<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Campaign\BaselineComparator;
use AIForge\Campaign\CampaignReport;
use AIForge\Campaign\CampaignRunner;
use AIForge\Campaign\ComboSpec;
use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\ManifestEntry;
use AIForge\Campaign\PluginCommit;
use AIForge\Campaign\RunResult;
use AIForge\Campaign\TrapEvaluation;
use InvalidArgumentException;
use RuntimeException;
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
     * [--corpus=<name>]
     * : Run the frozen corpus bench/ci-corpus/<name>/ in manifest order instead
     *   of a source batch, and score every run with the trap checks. Mutually
     *   exclusive with --source-batch. --files still caps the count.
     *
     * [--collect=<roots-file>]
     * : Launch nothing: wait for the roots listed in a .roots.json file written
     *   by an interrupted campaign, then build its report.
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
     * [--model=<id>]
     * : Pin every combo to this model, keeping the preset's own settings.
     *   Use it to benchmark a model no preset ships.
     *
     * [--timeout=<seconds>]
     * : Give up waiting after this long. Default 3600.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev qg-campaign --combos=gemini:balanced:showcase,openai:balanced:showcase --files=2
     *     wp aiforge-dev qg-campaign --combos=gemini:balanced:showcase --baseline=wp-content/uploads/aiforge-dev/campaign-1.json
     *     wp aiforge-dev qg-campaign --corpus=hard --combos=gemini:balanced:page-datterrissage
     *     wp aiforge-dev qg-campaign --collect=wp-content/uploads/aiforge-dev/hard-gemini-20260927-101500.roots.json
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

        if (isset($assoc['collect'])) {
            $this->runCollect($runner, $assoc);
            return;
        }

        $this->runCampaign($runner, $assoc);
    }

    /**
     * @param array<string, string> $assoc
     */
    private function runCampaign(CampaignRunner $runner, array $assoc): void
    {
        try {
            $combos = ComboSpec::parseList((string) ($assoc['combos'] ?? ''));
            $label = (string) ($assoc['label'] ?? 'campaign');
            $timeout = (int) ($assoc['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);
            $modelId = isset($assoc['model']) ? (string) $assoc['model'] : null;

            if (isset($assoc['corpus'], $assoc['source-batch'])) {
                WP_CLI::error('--corpus and --source-batch are mutually exclusive.');
            }

            $setup = isset($assoc['corpus'])
                ? $this->setupCorpus($runner, $assoc, $combos, $modelId)
                : $this->setupSourceBatch($runner, $assoc, $modelId);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        ['manifest' => $manifest, 'source_batch' => $sourceBatch, 'files' => $files, 'create' => $create, 'provenance' => $provenance] = $setup;

        WP_CLI::log(\sprintf(
            'Campaign "%s": %d combo(s) × %d file(s), %s, window %d.',
            $label,
            \count($combos),
            $files,
            $manifest !== null ? "corpus {$manifest->name}" : "source batch {$sourceBatch}",
            CampaignRunner::MAX_IN_FLIGHT
        ));

        if ($modelId !== null) {
            WP_CLI::log("All combos pinned to model {$modelId}.");
        }

        $stamp = gmdate('Ymd-His');
        $rootsPath = $this->reportPath($label, $stamp, '.roots.json');

        if ($rootsPath !== null) {
            WP_CLI::log("Root ids are saved to {$rootsPath} as they launch.");
        }

        $onLaunch = $this->makeOnLaunch($rootsPath, $label, $provenance);
        $rootIdsByCombo = $this->launchAndWait($runner, $combos, $create, $timeout, $onLaunch);

        $this->finish($runner, $assoc, $stamp, $label, $sourceBatch, $files, $manifest, $provenance, $rootIdsByCombo);
    }

    /**
     * @param array<string, string> $assoc
     */
    private function runCollect(CampaignRunner $runner, array $assoc): void
    {
        $path = (string) $assoc['collect'];
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!\is_array($data)) {
            WP_CLI::error("'{$path}' is not a readable roots file.");
            return;
        }

        $label = (string) ($data['label'] ?? 'campaign');
        $provenance = \is_array($data['provenance'] ?? null) ? $data['provenance'] : [];
        $corpusName = $data['corpus'] ?? ($provenance['corpus'] ?? null);
        $manifest = null;

        try {
            if ($corpusName !== null) {
                $manifest = CorpusManifest::fromName(AIFORGE_DEV_PATH . 'bench/ci-corpus', (string) $corpusName);
            }
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        $rootIdsByCombo = [];

        foreach ((array) ($data['root_ids_by_combo'] ?? []) as $comboKey => $roots) {
            $rootIdsByCombo[(string) $comboKey] = array_map('intval', (array) $roots);
        }

        $timeout = (int) ($assoc['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        $create = static function (ComboSpec $combo): int {
            throw new RuntimeException('--collect launches nothing; the queue is always empty.');
        };

        WP_CLI::log(\sprintf(
            'Collecting "%s": waiting on %d already-launched root(s).',
            $label,
            array_sum(array_map('count', $rootIdsByCombo))
        ));

        $rootIdsByCombo = $this->launchAndWait($runner, [], $create, $timeout, static function (): void {
        }, $rootIdsByCombo);

        $stamp = gmdate('Ymd-His');
        $this->finish($runner, $assoc, $stamp, $label, null, null, $manifest, $provenance, $rootIdsByCombo);
    }

    /**
     * @param ComboSpec[] $combos
     * @param array<string, string> $assoc
     * @return array{manifest: CorpusManifest, source_batch: null, files: int, create: callable(ComboSpec): int, provenance: array<string, mixed>}
     */
    private function setupCorpus(CampaignRunner $runner, array $assoc, array $combos, ?string $modelId): array
    {
        $manifest = CorpusManifest::fromName(AIFORGE_DEV_PATH . 'bench/ci-corpus', (string) $assoc['corpus']);

        if ($manifest->errors() !== []) {
            WP_CLI::error("Corpus {$manifest->name} is invalid:\n  " . implode("\n  ", $manifest->errors()));
        }

        $requested = isset($assoc['files']) ? (int) $assoc['files'] : null;
        $available = \count($manifest->entries);
        $files = CampaignRunner::fileCount($requested, $available);

        if ($requested !== null && $files < $requested) {
            WP_CLI::warning(\sprintf(
                'Asked for %d file(s) but corpus %s only holds %d. Running %d.',
                $requested,
                $manifest->name,
                $available,
                $files
            ));
        }

        $entries = \array_slice($manifest->entries, 0, $files);
        $sources = array_map(
            static fn (ManifestEntry $e): array => ['id' => $e->id, 'markdown' => (string) $manifest->markdown($e)],
            $entries
        );

        $create = static fn (ComboSpec $c): int => $runner->createComboFromSources($c, $sources, $modelId);

        $templates = [];

        foreach ($combos as $combo) {
            $templates[$combo->templateSlug] = $runner->templateHash($combo->templateSlug);
        }

        $provenance = [
            'corpus' => $manifest->name,
            'files' => $manifest->hashes(),
            'templates' => $templates,
            'plugin_commit' => PluginCommit::read(WP_PLUGIN_DIR . '/wp-ai-forge'),
        ];

        return ['manifest' => $manifest, 'source_batch' => null, 'files' => $files, 'create' => $create, 'provenance' => $provenance];
    }

    /**
     * @param array<string, string> $assoc
     * @return array{manifest: null, source_batch: int, files: int, create: callable(ComboSpec): int, provenance: array<string, mixed>}
     */
    private function setupSourceBatch(CampaignRunner $runner, array $assoc, ?string $modelId): array
    {
        $sourceBatch = isset($assoc['source-batch'])
            ? (int) $assoc['source-batch']
            : $runner->resolveSourceBatch();

        $available = $runner->countMarkdownSnapshots($sourceBatch);

        if ($available === 0) {
            WP_CLI::error("Batch {$sourceBatch} has no markdown snapshots.");
        }

        $requested = isset($assoc['files']) ? (int) $assoc['files'] : null;
        $files = CampaignRunner::fileCount($requested, $available);

        if ($requested !== null && $files < $requested) {
            WP_CLI::warning(\sprintf(
                'Asked for %d file(s) but batch %d only holds %d. Running %d. Pass --source-batch to pick a richer corpus.',
                $requested,
                $sourceBatch,
                $available,
                $files
            ));
        }

        $create = static fn (ComboSpec $c): int => $runner->createCombo($c, $sourceBatch, $files, $modelId);

        return ['manifest' => null, 'source_batch' => $sourceBatch, 'files' => $files, 'create' => $create, 'provenance' => []];
    }

    /**
     * @param array<string, mixed> $provenance
     * @return callable(array<string, int[]>): void
     */
    private function makeOnLaunch(?string $rootsPath, string $label, array $provenance): callable
    {
        return static function (array $roots) use ($rootsPath, $label, $provenance): void {
            if ($rootsPath !== null) {
                file_put_contents($rootsPath, wp_json_encode([
                    'label' => $label,
                    'corpus' => $provenance['corpus'] ?? null,
                    'provenance' => $provenance,
                    'root_ids_by_combo' => $roots,
                ], JSON_PRETTY_PRINT));
            }
        };
    }

    /**
     * @param ComboSpec[] $combos
     * @param callable(ComboSpec): int $create
     * @param callable(array<string, int[]>): void $onLaunch
     * @param array<string, int[]> $alreadyLaunched
     * @return array<string, int[]>
     */
    private function launchAndWait(
        CampaignRunner $runner,
        array $combos,
        callable $create,
        int $timeout,
        callable $onLaunch,
        array $alreadyLaunched = []
    ): array {
        $queue = $combos;
        $rootIdsByCombo = $alreadyLaunched;
        $inFlight = [];

        foreach ($alreadyLaunched as $comboKey => $rootIds) {
            foreach ($rootIds as $rootId) {
                if (!$runner->isRootFinished((int) $rootId)) {
                    $inFlight[(int) $rootId] = $comboKey;
                }
            }
        }

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
                    $rootId = $create($combo);
                } catch (Throwable $e) {
                    WP_CLI::warning($e->getMessage());
                    continue;
                }

                $rootIdsByCombo[$combo->key()][] = $rootId;
                $inFlight[$rootId] = $combo->key();
                WP_CLI::log("  launched {$combo->key()} as task {$rootId}");
                $onLaunch($rootIdsByCombo);
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
     * @param array<string, int[]> $rootIdsByCombo
     */
    private function finish(
        CampaignRunner $runner,
        array $assoc,
        string $stamp,
        string $label,
        ?int $sourceBatch,
        ?int $files,
        ?CorpusManifest $manifest,
        array $provenance,
        array $rootIdsByCombo
    ): void {
        $runs = $runner->collect($rootIdsByCombo);
        $traps = $manifest === null ? null : TrapEvaluation::evaluate(
            $manifest,
            TrapEvaluation::runsFromResults($runs),
            static fn (int $taskId): ?array => $runner->loadRunArtifacts($taskId)
        );

        $report = new CampaignReport($label, $sourceBatch, $files ?? $this->filesPerCombo($runs), $runs, $manifest?->name, $provenance, $traps);

        WP_CLI::log('');
        WP_CLI::log($report->toMarkdown());

        $this->maybeCompare($assoc, $report);
        $this->writeReport($label, $stamp, $report);
        $this->printAuditHint($report);
    }

    /**
     * Best-effort file count when it was not known up front, as with
     * --collect: the number of llm_generate runs sharing one root.
     *
     * @param RunResult[] $runs
     */
    private function filesPerCombo(array $runs): int
    {
        if ($runs === []) {
            return 0;
        }

        $firstRoot = $runs[0]->rootId;

        return \count(array_filter($runs, static fn (RunResult $r): bool => $r->rootId === $firstRoot));
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

        try {
            $rows = BaselineComparator::compare($baseline, $report->toArray());
        } catch (InvalidArgumentException $e) {
            WP_CLI::warning($e->getMessage() . ' Skipping comparison.');
            return;
        }

        WP_CLI::log('');
        WP_CLI::log('### Comparison against baseline');
        WP_CLI::log('');
        WP_CLI::log(BaselineComparator::toMarkdown($rows));
    }

    private function reportPath(string $label, string $stamp, string $suffix): ?string
    {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'aiforge-dev';

        if (!wp_mkdir_p($dir)) {
            WP_CLI::warning("Could not create {$dir}, nothing written.");
            return null;
        }

        return "{$dir}/" . sanitize_file_name($label) . "-{$stamp}{$suffix}";
    }

    private function writeReport(string $label, string $stamp, CampaignReport $report): void
    {
        $path = $this->reportPath($label, $stamp, '.json');

        if ($path === null) {
            return;
        }

        if (file_put_contents($path, wp_json_encode($report->toArray(), JSON_PRETTY_PRINT)) === false) {
            WP_CLI::warning("Could not write {$path}.");
            return;
        }

        WP_CLI::success("Report written to {$path}");
    }

    private function printAuditHint(CampaignReport $report): void
    {
        $dead = $report->failedTaskIds();

        if ($dead !== []) {
            WP_CLI::warning(\sprintf(
                '%d run(s) never generated (provider outage or timeout): %s. They are excluded from the averages.',
                \count($dead),
                implode(', ', $dead)
            ));
        }

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
