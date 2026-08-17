<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Agent\AgentConfigRepository;
use AIForge\Agent\MediaIntelligence\MediaIndexRepository;
use AIForge\Agent\MediaIntelligence\MediaIndexService;
use AIForge\Agent\MediaIntelligence\MediaSearchCostLog;
use AIForge\Agent\MediaIntelligence\MediaSearchService;
use AIForge\Agent\MediaIntelligence\MediaTaxonomy;
use AIForge\AI\ProviderFactory;
use AIForge\Config\ConfigRepository;
use AIForge\Vision\VisionBenchRunner;
use AIForge\Vision\VisionBenchScorer;
use Throwable;
use WP_CLI;

/**
 * Benchmarks vision models on the media index, judged by whether the resulting
 * index answers a human-written query set.
 */
final class VisionBenchCommand
{
    /**
     * Index one image sample with several vision models and compare them.
     *
     * ## OPTIONS
     *
     * --models=<list>
     * : Comma-separated provider:model pairs, e.g.
     *   gemini:gemini-3-flash-preview,openai:gpt-5.6-luna,anthropic:claude-sonnet-5
     *
     * [--images=<n>]
     * : How many image attachments to index. Oldest first, so the sample is
     *   stable across runs. Default 30.
     *
     * [--queries=<path>]
     * : JSON file of {"query": "...", "expect": [attachment ids]} entries.
     *   An empty `expect` marks a trap query nothing should answer. Without
     *   this file only the index-health metrics are produced.
     *
     * [--label=<name>]
     * : Report name. Default "vision-bench".
     *
     * [--dry-run]
     * : Resolve the sample and validate the query file, index nothing.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev vision-bench --models=gemini:gemini-3.7-flash --images=10 --dry-run
     *     wp aiforge-dev vision-bench --models=gemini:gemini-3-flash-preview,openai:gpt-5.6-luna --images=100 --queries=wp-content/uploads/aiforge-dev/queries.json
     *
     * @param string[]              $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        wp_set_current_user(1);

        $runner = new VisionBenchRunner($wpdb);
        $models = $this->parseModels((string) ($assoc['models'] ?? ''));
        $count = (int) ($assoc['images'] ?? 30);
        $label = (string) ($assoc['label'] ?? 'vision-bench');
        $dryRun = isset($assoc['dry-run']);

        $sample = $runner->resolveSample($count);
        if ($sample === []) {
            WP_CLI::error('No image attachments found.');
        }

        $queries = [];
        if (isset($assoc['queries'])) {
            $path = $this->resolvePath((string) $assoc['queries']);
            $queries = VisionBenchRunner::loadQueries($path);

            $unreachable = VisionBenchRunner::unreachableExpectations($queries, $sample);
            if ($unreachable !== []) {
                WP_CLI::warning(sprintf(
                    '%d expected attachment(s) are outside the sample and can never be found: %s. Raise --images or fix the query file.',
                    \count($unreachable),
                    implode(', ', $unreachable)
                ));
            }
        }

        WP_CLI::log(sprintf(
            'Sample: %d images (%d..%d). Models: %d. Queries: %d.',
            \count($sample),
            $sample[0],
            $sample[\count($sample) - 1],
            \count($models),
            \count($queries)
        ));

        if ($dryRun) {
            WP_CLI::success('Dry run: sample resolved and query file valid. Nothing indexed.');
            return;
        }

        $backup = $runner->snapshot($sample);
        WP_CLI::log(sprintf('Backed up %d existing index row(s) for the sample.', \count($backup)));

        $config = new ConfigRepository();
        $service = new MediaIndexService(new MediaIndexRepository($wpdb));
        $allowed = $this->allowedTaxonomy();
        $report = ['label' => $label, 'sample' => $sample, 'models' => []];

        try {
            foreach ($models as [$providerId, $modelId]) {
                $key = "$providerId:$modelId";
                WP_CLI::log("--- $key ---");

                $filter = static fn (): string => $modelId;
                add_filter('aiforge_media_vision_model', $filter, 99);

                try {
                    $runner->wipe($sample);

                    // indexBatch fills existing rows; the wipe removed them, so
                    // put the empty ones back the way the normal flow does.
                    $service->syncMissingEntries();

                    $provider = ProviderFactory::make($providerId, $config);
                    $started = microtime(true);
                    $outcome = $service->indexBatch($sample, $provider);
                    $elapsed = round(microtime(true) - $started, 1);

                    if (!empty($outcome['errors'])) {
                        WP_CLI::warning('  ' . implode(' | ', array_filter($outcome['errors'])));
                    }

                    $rows = $runner->harvest($sample);
                    $health = VisionBenchRunner::indexHealth($rows, $allowed);
                    $health['seconds'] = $elapsed;

                    WP_CLI::log(sprintf(
                        '  indexed %d, failed %d, off-taxonomy %d, %.1f keywords/img, %ds',
                        $health['indexed'],
                        $health['failed'],
                        $health['off_taxonomy'],
                        $health['avg_keywords'],
                        $elapsed
                    ));

                    $entry = ['health' => $health];

                    if ($queries !== []) {
                        $entry['search'] = VisionBenchScorer::score($queries, $this->runQueries($queries, $config));
                        WP_CLI::log(sprintf(
                            '  top1 %.1f%%  top3 %.1f%%  mrr %.3f  misses %d  false positives %d',
                            $entry['search']['top1_rate'],
                            $entry['search']['top3_rate'],
                            $entry['search']['mrr'],
                            $entry['search']['misses'],
                            $entry['search']['false_positives']
                        ));
                    }

                    $report['models'][$key] = $entry;
                } catch (Throwable $e) {
                    WP_CLI::warning("$key failed: " . $e->getMessage());
                    $report['models'][$key] = ['error' => $e->getMessage()];
                } finally {
                    remove_filter('aiforge_media_vision_model', $filter, 99);
                }
            }
        } finally {
            $runner->restore($sample, $backup);
            WP_CLI::log(sprintf('Restored %d original index row(s).', \count($backup)));
        }

        $file = $this->write($label, $report);
        WP_CLI::success("Report written to $file");
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function parseModels(string $raw): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            $parts = explode(':', $pair, 2);
            if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                WP_CLI::error("Bad --models entry '$pair', expected provider:model.");
            }
            $out[] = [$parts[0], $parts[1]];
        }

        if ($out === []) {
            WP_CLI::error('--models is required, e.g. --models=gemini:gemini-3.7-flash');
        }

        return $out;
    }

    /**
     * @param list<array{query: string, expect: list<int>}> $queries
     * @return array<string, list<int>>
     */
    private function runQueries(array $queries, ConfigRepository $config): array
    {
        global $wpdb;

        $search = new MediaSearchService(
            new MediaIndexRepository($wpdb),
            $config,
            new AgentConfigRepository(),
            new MediaSearchCostLog()
        );

        $results = [];
        foreach ($queries as $q) {
            try {
                $r = $search->search($q['query']);
                $results[$q['query']] = array_map('intval', $r['attachment_ids'] ?? []);
            } catch (Throwable $e) {
                WP_CLI::warning('  query failed: ' . $q['query'] . ' — ' . $e->getMessage());
                $results[$q['query']] = [];
            }
        }

        return $results;
    }

    /**
     * @return array<string, list<string>>
     */
    private function allowedTaxonomy(): array
    {
        $allowed = [];
        foreach (['tone', 'usage_type', 'category', 'image_type', 'color_mood', 'people_count', 'setting', 'subject_position', 'negative_space'] as $facet) {
            $values = MediaTaxonomy::getValues($facet);
            if (!\is_array($values) || $values === []) {
                continue;
            }
            // Accept either a flat list of values or a value => label map.
            $isList = array_keys($values) === range(0, \count($values) - 1);
            $allowed[$facet] = array_map('strval', $isList ? $values : array_keys($values));
        }

        return $allowed;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : ABSPATH . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $report
     */
    private function write(string $label, array $report): string
    {
        $dir = wp_upload_dir()['basedir'] . '/aiforge-dev';
        wp_mkdir_p($dir);
        $file = $dir . '/' . $label . '-' . gmdate('Ymd-His') . '.json';
        file_put_contents($file, (string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $file;
    }
}
