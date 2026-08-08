<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Campaign\BaselineComparator;
use AIForge\Campaign\CampaignReport;
use AIForge\Campaign\CampaignRunner;
use AIForge\Campaign\ComboSpec;
use Throwable;
use WP_CLI;

/**
 * Runs a Quality Gate validation matrix and reports the outcome.
 */
final class QgCampaignCommand
{
    private const POLL_BUDGET_SECONDS = 25;
    private const DEFAULT_TIMEOUT_SECONDS = 3600;

    /**
     * Run a provider × preset × template validation campaign.
     *
     * ## OPTIONS
     *
     * --combos=<combos>
     * : Comma-separated list of provider:preset:template-slug.
     *
     * [--source-batch=<id>]
     * : Batch task to pull markdown snapshots from. Defaults to the most recent
     *   completed batch that still has snapshots.
     *
     * [--files=<n>]
     * : Markdown files per combo. Defaults to every snapshot on the source batch.
     *
     * [--label=<name>]
     * : Campaign name, used in the report. Defaults to "campaign".
     *
     * [--baseline=<path>]
     * : Path to a previous report JSON to diff against.
     *
     * [--timeout=<seconds>]
     * : Give up waiting after this long. Default 3600.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev qg-campaign --combos=gemini:balanced:showcase,openai:balanced:showcase --files=2
     *     wp aiforge-dev qg-campaign --combos=gemini:balanced:showcase --baseline=wp-content/uploads/aiforge-dev/campaign-1.json
     *
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        wp_set_current_user(1);

        // Creating a task makes the main plugin call spawn_cron(). Under
        // ALTERNATE_WP_CRON that loads wp-cron.php in-process, which ends in
        // die() and would kill the campaign right after the first launch.
        // spawn_cron() bails out when the request already looks like a cron
        // run, and this campaign drives execution itself through drain().
        $_GET['doing_wp_cron'] = '1';

        $runner = new CampaignRunner($wpdb);

        try {
            $combos = ComboSpec::parseList((string) ($assoc['combos'] ?? ''));
            $label = (string) ($assoc['label'] ?? 'campaign');
            $timeout = (int) ($assoc['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);

            $sourceBatch = isset($assoc['source-batch'])
                ? (int) $assoc['source-batch']
                : $runner->resolveSourceBatch();

            $available = $runner->countMarkdownSnapshots($sourceBatch);

            if ($available === 0) {
                WP_CLI::error("Batch {$sourceBatch} has no markdown snapshots.");
            }

            $requested = isset($assoc['files']) ? (int) $assoc['files'] : null;
            $files = CampaignRunner::fileCount($requested, $available);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        if ($requested !== null && $files < $requested) {
            WP_CLI::warning(\sprintf(
                'Asked for %d file(s) but batch %d only holds %d. Running %d. Pass --source-batch to pick a richer corpus.',
                $requested,
                $sourceBatch,
                $available,
                $files
            ));
        }

        WP_CLI::log(\sprintf(
            'Campaign "%s": %d combo(s) × %d file(s), source batch %d, window %d.',
            $label,
            \count($combos),
            $files,
            $sourceBatch,
            CampaignRunner::MAX_IN_FLIGHT
        ));

        $rootIdsByCombo = $this->launchAndWait($runner, $combos, $sourceBatch, $files, $timeout);

        $report = new CampaignReport($label, $sourceBatch, $files, $runner->collect($rootIdsByCombo));

        WP_CLI::log('');
        WP_CLI::log($report->toMarkdown());

        $this->maybeCompare($assoc, $report);
        $this->writeReport($label, $report);
        $this->printAuditHint($report);
    }

    /**
     * @param ComboSpec[] $combos
     * @return array<string, int[]>
     */
    private function launchAndWait(
        CampaignRunner $runner,
        array $combos,
        int $sourceBatch,
        int $files,
        int $timeout
    ): array {
        $queue = $combos;
        $rootIdsByCombo = [];
        $inFlight = [];
        $deadline = time() + $timeout;

        while ($queue !== [] || $inFlight !== []) {
            if (time() > $deadline) {
                WP_CLI::warning(\sprintf(
                    'Timed out with %d combo(s) queued and %d still running.',
                    \count($queue),
                    \count($inFlight)
                ));
                break;
            }

            $launchable = CampaignRunner::launchableCount(
                $runner->activeRootCount(),
                CampaignRunner::MAX_IN_FLIGHT,
                \count($queue)
            );

            for ($i = 0; $i < $launchable; $i++) {
                $combo = array_shift($queue);

                try {
                    $rootId = $runner->createCombo($combo, $sourceBatch, $files);
                } catch (Throwable $e) {
                    WP_CLI::warning($e->getMessage());
                    continue;
                }

                $rootIdsByCombo[$combo->key()][] = $rootId;
                $inFlight[$rootId] = $combo->key();
                WP_CLI::log("  launched {$combo->key()} as task {$rootId}");
            }

            $runner->drain(self::POLL_BUDGET_SECONDS);

            foreach ($inFlight as $rootId => $comboKey) {
                if ($runner->isRootFinished((int) $rootId)) {
                    unset($inFlight[$rootId]);
                    WP_CLI::log("  finished {$comboKey} (task {$rootId})");
                }
            }
        }

        return $rootIdsByCombo;
    }

    /**
     * @param array<string, string> $assoc
     */
    private function maybeCompare(array $assoc, CampaignReport $report): void
    {
        if (!isset($assoc['baseline'])) {
            return;
        }

        $path = (string) $assoc['baseline'];

        if (!is_readable($path)) {
            WP_CLI::warning("Baseline '{$path}' is not readable, skipping comparison.");
            return;
        }

        $baseline = json_decode((string) file_get_contents($path), true);

        if (!\is_array($baseline)) {
            WP_CLI::warning("Baseline '{$path}' is not valid JSON, skipping comparison.");
            return;
        }

        WP_CLI::log('');
        WP_CLI::log('### Comparison against baseline');
        WP_CLI::log('');
        WP_CLI::log(BaselineComparator::toMarkdown(BaselineComparator::compare($baseline, $report->toArray())));
    }

    private function writeReport(string $label, CampaignReport $report): void
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'aiforge-dev';

        if (!wp_mkdir_p($dir)) {
            WP_CLI::warning("Could not create {$dir}, report not written.");
            return;
        }

        $slug = sanitize_file_name($label);
        $path = "{$dir}/{$slug}-" . gmdate('Ymd-His') . '.json';

        if (file_put_contents($path, wp_json_encode($report->toArray(), JSON_PRETTY_PRINT)) === false) {
            WP_CLI::warning("Could not write {$path}.");
            return;
        }

        WP_CLI::success("Report written to {$path}");
    }

    private function printAuditHint(CampaignReport $report): void
    {
        $flagged = $report->flaggedTaskIds();

        if ($flagged === []) {
            WP_CLI::log('No flagged runs.');
            return;
        }

        WP_CLI::log('');
        WP_CLI::log('Flagged runs, audit them with:');
        WP_CLI::log('  /qg-audit ' . implode(' ', $flagged));
    }
}
