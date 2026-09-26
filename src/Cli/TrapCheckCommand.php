<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Campaign\CampaignRunner;
use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\TrapEvaluation;
use Throwable;
use WP_CLI;

/**
 * Re-runs the trap checks over a stored campaign, at zero API cost.
 */
final class TrapCheckCommand
{
    /**
     * Re-check a corpus campaign's stored runs with the current manifest.
     *
     * Edit the manifest's thresholds, re-run this, compare: calibration never
     * needs a new generation.
     *
     * ## OPTIONS
     *
     * --report=<path>
     * : A qg-campaign report JSON produced with --corpus.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev trap-check --report=wp-content/uploads/aiforge-dev/hard-balanced-20260927-101500.json
     *
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        $path = (string) ($assoc['report'] ?? '');
        $report = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!\is_array($report)) {
            WP_CLI::error("'{$path}' is not a readable report JSON.");
        }

        if (empty($report['corpus'])) {
            WP_CLI::error('This report was not run on a corpus (no "corpus" key); trap checks need one.');
        }

        try {
            $manifest = CorpusManifest::fromName(AIFORGE_DEV_PATH . 'bench/ci-corpus', (string) $report['corpus']);
            $runs = TrapEvaluation::runsFromReport($report);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        if ($manifest->errors() !== []) {
            WP_CLI::error("Corpus {$manifest->name} is invalid:\n  " . implode("\n  ", $manifest->errors()));
        }

        $then = (array) ($report['provenance']['files'] ?? []);
        $now = $manifest->hashes();
        $changed = array_keys(array_diff_assoc($now, $then) + array_diff_assoc($then, $now));

        if ($changed !== []) {
            WP_CLI::log('Changed since the campaign: ' . implode(', ', $changed) . '. Checks read the stored markdown; only the manifest\'s ground truth and thresholds apply anew.');
        }

        $runner = new CampaignRunner($wpdb);
        $traps = TrapEvaluation::evaluate($manifest, $runs, static fn (int $id): ?array => $runner->loadRunArtifacts($id));

        WP_CLI::log($traps->toMarkdown());

        $out = preg_replace('/\.json$/', '', $path) . '-trapcheck-' . gmdate('Ymd-His') . '.json';
        $written = file_put_contents($out, wp_json_encode([
            'source_report' => $path,
            'corpus' => $manifest->name,
            'manifest_hashes' => $now,
            'thresholds' => $manifest->data['thresholds'] ?? [],
            'traps' => $traps->toArray(),
        ], JSON_PRETTY_PRINT));

        $written === false ? WP_CLI::warning("Could not write {$out}.") : WP_CLI::success("Re-check written to {$out}");
    }
}
