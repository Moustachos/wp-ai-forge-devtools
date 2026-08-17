<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Agent\MediaIntelligence\MediaIndexRepository;
use AIForge\Agent\MediaIntelligence\MediaIntelligenceAgent;
use AIForge\AI\ProviderFactory;
use AIForge\Config\ConfigRepository;
use AIForge\Vision\SuggestionBlockSelector;
use AIForge\Vision\SuggestionContextBuilder;
use AIForge\Vision\VisionBenchRunner;
use Throwable;
use WP_CLI;

/**
 * Replays real image blocks through media suggestion, once per candidate index.
 *
 * Search only exercised the facets a query happens to mention; suggestion leans
 * on the descriptions and keywords, which nothing measured so far. The indexes
 * come from a vision-bench report, so no image is re-indexed here.
 */
final class SuggestionBenchCommand
{
    /**
     * ## OPTIONS
     *
     * [--report=<pattern>]
     * : vision-bench report(s) holding the indexes, relative to the aiforge-dev
     *   upload directory. Default vision-v2*.json (several files are merged).
     *
     * [--blocks=<n>]
     * : Image blocks to replay. Default 20.
     *
     * [--limit=<n>]
     * : Suggestions requested per block. Default 3.
     *
     * [--suggest-with=<provider>]
     * : Provider running the suggestion itself, held constant across indexes so
     *   only the index varies. Default gemini.
     *
     * [--label=<name>]
     * : Report name. Default suggestion-bench.
     *
     * [--dry-run]
     * : Select the blocks and print them, suggest nothing.
     *
     * @param string[]              $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        wp_set_current_user(1);

        $runner = new VisionBenchRunner($wpdb);
        $dir = wp_upload_dir()['basedir'] . '/aiforge-dev/';
        $pattern = (string) ($assoc['report'] ?? 'vision-v2*.json');
        $wanted = (int) ($assoc['blocks'] ?? 20);
        $limit = (int) ($assoc['limit'] ?? 3);
        $label = (string) ($assoc['label'] ?? 'suggestion-bench');

        $indexes = $this->loadIndexes($dir . $pattern);
        if ($indexes === []) {
            WP_CLI::error("No index rows found in {$dir}{$pattern}. Run vision-bench first.");
        }
        WP_CLI::log('Indexes: ' . implode(', ', array_keys($indexes)));

        $blocks = SuggestionBlockSelector::select($this->collectBlocks(), $wanted);
        if ($blocks === []) {
            WP_CLI::error('No image block found in any draft.');
        }

        WP_CLI::log(sprintf('Replaying %d block(s), %d suggestion(s) each.', \count($blocks), $limit));
        foreach ($blocks as $b) {
            WP_CLI::log(sprintf(
                '  post %d #%d — %s / %s / %s — %s',
                $b['post_id'],
                $b['block_index'],
                $b['context']['block_type'],
                $b['context']['image_position'],
                $b['context']['text_density'],
                mb_substr((string) $b['context']['section_heading'], 0, 40) ?: '(sans titre)'
            ));
        }

        if (isset($assoc['dry-run'])) {
            WP_CLI::success('Dry run: blocks selected, nothing suggested.');
            return;
        }

        $config = new ConfigRepository();
        $suggestProvider = (string) ($assoc['suggest-with'] ?? 'gemini');
        $siteContext = $config->get('site_context', '');

        // Every attachment any index knows about has to be restorable.
        $touched = [];
        foreach ($indexes as $rows) {
            foreach ($rows as $row) {
                $touched[(int) $row['attachment_id']] = true;
            }
        }
        $touched = array_keys($touched);
        $backup = $runner->snapshot($touched);
        WP_CLI::log(sprintf('Backed up %d index row(s).', \count($backup)));

        $report = ['label' => $label, 'limit' => $limit, 'blocks' => $blocks, 'models' => []];

        try {
            foreach ($indexes as $name => $rows) {
                WP_CLI::log("--- $name ---");
                $runner->restore($touched, array_values($rows));

                $agent = new MediaIntelligenceAgent();
                $agent->setIndexRepository(new MediaIndexRepository($wpdb));
                $provider = ProviderFactory::make($suggestProvider, $config);

                $perBlock = [];
                foreach ($blocks as $i => $block) {
                    try {
                        $result = $agent->execute($provider, [
                            'context' => $block['context'],
                            'limit' => $limit,
                            'site_context' => $siteContext,
                            'locale' => 'fr_FR',
                        ]);
                        $decoded = json_decode((string) $result->content, true);
                        $ids = array_map(
                            static fn ($s) => (int) ($s['attachment_id'] ?? $s['id'] ?? 0),
                            $decoded['suggestions'] ?? []
                        );
                        $perBlock[] = ['block' => $i, 'suggestions' => array_values(array_filter($ids))];
                    } catch (Throwable $e) {
                        WP_CLI::warning('  block ' . $i . ': ' . mb_substr($e->getMessage(), 0, 60));
                        $perBlock[] = ['block' => $i, 'suggestions' => [], 'error' => $e->getMessage()];
                    }
                }

                $filled = \count(array_filter($perBlock, static fn ($b) => $b['suggestions'] !== []));
                WP_CLI::log(sprintf('  %d/%d blocks answered', $filled, \count($blocks)));
                $report['models'][$name] = $perBlock;
            }
        } finally {
            $runner->restore($touched, $backup);
            WP_CLI::log(sprintf('Restored %d original index row(s).', \count($backup)));
        }

        $report['agreement'] = $this->agreement($report['models'], \count($blocks));
        WP_CLI::log(sprintf(
            'Agreement: %d block(s) where every index proposed the same set, %d where they all differ.',
            $report['agreement']['unanimous'],
            $report['agreement']['fully_split']
        ));

        $file = $dir . $label . '-' . gmdate('Ymd-His') . '.json';
        file_put_contents($file, (string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        WP_CLI::success("Report written to $file");
    }

    /**
     * Index rows per model, merged across reports.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function loadIndexes(string $pattern): array
    {
        $indexes = [];
        foreach (glob($pattern) ?: [] as $file) {
            $rep = json_decode((string) file_get_contents($file), true);
            foreach ($rep['models'] ?? [] as $name => $model) {
                if (!empty($model['rows'])) {
                    $indexes[$name] = $model['rows'];
                }
            }
        }

        return $indexes;
    }

    /**
     * Every image block of every draft, with the context the editor would send.
     *
     * @return list<array{post_id: int, block_index: int, context: array<string, mixed>}>
     */
    private function collectBlocks(): array
    {
        global $wpdb;

        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_type, post_content
             FROM {$wpdb->posts}
             WHERE post_status IN ('draft','publish','private')
               AND post_type IN ('post','page')
               AND post_content LIKE '%wp:image%'
             ORDER BY ID DESC LIMIT 40"
        );

        $out = [];
        foreach ($posts as $post) {
            $blocks = parse_blocks((string) $post->post_content);
            $flat = SuggestionContextBuilder::flatten($blocks);

            foreach ($flat as $i => $block) {
                if (($block['blockName'] ?? '') !== 'core/image') {
                    continue;
                }
                $out[] = [
                    'post_id' => (int) $post->ID,
                    'block_index' => $i,
                    'context' => SuggestionContextBuilder::build(
                        $blocks,
                        $i,
                        (int) $post->ID,
                        (string) $post->post_title,
                        (string) $post->post_type
                    ),
                ];
            }
        }

        return $out;
    }

    /**
     * How often the indexes lead to the same suggestions. Blocks where they
     * agree carry no information and are not worth a human looking at them.
     *
     * @param array<string, list<array{block: int, suggestions: list<int>}>> $models
     * @return array{unanimous: int, fully_split: int, per_block: list<array<string, mixed>>}
     */
    private function agreement(array $models, int $blockCount): array
    {
        $names = array_keys($models);
        $unanimous = 0;
        $split = 0;
        $perBlock = [];

        for ($i = 0; $i < $blockCount; $i++) {
            $sets = [];
            foreach ($names as $name) {
                $ids = $models[$name][$i]['suggestions'] ?? [];
                sort($ids);
                $sets[$name] = $ids;
            }

            $signatures = array_map(static fn ($s) => implode(',', $s), $sets);
            $distinct = \count(array_unique($signatures));

            // Overlap of the first suggestion, the one users actually see first
            $firsts = array_map(static fn ($s) => $s[0] ?? 0, $sets);
            $sameFirst = \count(array_unique($firsts)) === 1;

            if ($distinct === 1) {
                $unanimous++;
            }
            if ($distinct === \count($names)) {
                $split++;
            }

            $perBlock[] = [
                'block' => $i,
                'distinct_sets' => $distinct,
                'same_first_pick' => $sameFirst,
                'sets' => $sets,
            ];
        }

        return ['unanimous' => $unanimous, 'fully_split' => $split, 'per_block' => $perBlock];
    }
}
