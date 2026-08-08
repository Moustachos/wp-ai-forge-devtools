<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use RuntimeException;
use WP_REST_Request;
use wpdb;

/**
 * Runs a validation matrix against the main plugin's task pipeline.
 *
 * Tasks are created through the real REST endpoint so the campaign exercises
 * the same path a user hits. That endpoint rejects creation once the user
 * already has 5 active root tasks, so combos are launched through a sliding
 * window rather than all at once.
 */
final class CampaignRunner
{
    /** One slot below the main plugin's per-user active-root cap. */
    public const MAX_IN_FLIGHT = 4;

    /**
     * Marks a batch as produced by a campaign. Those batches complete with
     * their own markdown snapshots, so without this marker every campaign
     * would pick its predecessor as source and inherit its file count.
     */
    public const CAMPAIGN_META_KEY = 'qg_campaign';

    private const SNAPSHOT_PROBE_LIMIT = 50;

    /**
     * How many files to run, given what the user asked for and what the
     * source batch actually holds.
     */
    public static function fileCount(?int $requested, int $available): int
    {
        if ($requested === null || $requested <= 0) {
            return $available;
        }

        return min($requested, $available);
    }

    public function __construct(
        private readonly wpdb $wpdb
    ) {
    }

    /**
     * How many combos may be launched right now.
     */
    public static function launchableCount(int $activeRoots, int $maxInFlight, int $remainingCombos): int
    {
        return max(0, min($maxInFlight - $activeRoots, $remainingCombos));
    }

    /**
     * Most recent completed batch that still carries markdown snapshots.
     */
    public function resolveSourceBatch(): int
    {
        // Campaign-produced batches are excluded: they complete with their own
        // snapshots and would otherwise become the next campaign's source,
        // silently shrinking the file count run after run.
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT t.id FROM {$this->wpdb->prefix}aiforge_tasks t
             WHERE t.task_type = 'batch_markdown_to_gutenberg' AND t.status = 'completed'
             AND NOT EXISTS (
                 SELECT 1 FROM {$this->wpdb->prefix}aiforge_task_meta m
                 WHERE m.task_id = t.id AND m.meta_key = %s
             )
             ORDER BY t.id DESC LIMIT 20",
            self::CAMPAIGN_META_KEY
        ));

        foreach ($ids as $id) {
            if ($this->countMarkdownSnapshots((int) $id) > 0) {
                return (int) $id;
            }
        }

        throw new RuntimeException(
            'No completed batch_markdown_to_gutenberg task with markdown snapshots found. Pass --source-batch=<id>.'
        );
    }

    public function countMarkdownSnapshots(int $batchId): int
    {
        $count = 0;

        for ($i = 0; $i < self::SNAPSHOT_PROBE_LIMIT; $i++) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}aiforge_task_payloads
                 WHERE task_id = %d AND payload_type = %s",
                $batchId,
                "markdown_snapshot_{$i}"
            ));

            if ((int) $exists === 0) {
                break;
            }

            $count++;
        }

        return $count;
    }

    public function resolveTemplateId(string $slug): int
    {
        $posts = get_posts([
            'post_type' => 'aiforge_ci_tpl',
            'name' => $slug,
            'numberposts' => 1,
            'post_status' => 'any',
        ]);

        if ($posts === []) {
            throw new RuntimeException("Template '{$slug}' not found in aiforge_ci_tpl.");
        }

        return (int) $posts[0]->ID;
    }

    public function activeRootCount(): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}aiforge_tasks
             WHERE created_by = %d AND status IN ('pending', 'running') AND parent_id IS NULL",
            get_current_user_id()
        ));
    }

    /**
     * Meta that pins a specific model while keeping the preset's own settings.
     *
     * Lets a campaign benchmark a model no preset ships (an older generation,
     * a candidate replacement) without editing preset classes. The custom
     * preset carries the named preset's config so only the model varies.
     *
     * @return array<string, mixed>
     */
    public function modelOverrideMeta(ComboSpec $combo, string $modelId): array
    {
        $config = [];

        try {
            $preset = \AIForge\Agent\Preset\PresetRegistry::getPreset('content-integrator', $combo->preset);
            $config = $preset->getConfigForProvider($combo->provider);
        } catch (\Throwable $e) {
            $config = [];
        }

        return [
            'preset' => 'custom',
            'custom' => ['model' => $modelId, 'config' => $config],
        ];
    }

    /**
     * Create one batch task for a combo. Returns the root task ID.
     */
    public function createCombo(ComboSpec $combo, int $sourceBatchId, int $files, ?string $modelId = null): int
    {
        $templateId = $this->resolveTemplateId($combo->templateSlug);
        $template = get_post($templateId);
        $templateContent = $template->post_content;

        $payloads = [];
        $filesMeta = [];

        for ($i = 0; $i < $files; $i++) {
            $markdown = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT payload FROM {$this->wpdb->prefix}aiforge_task_payloads
                 WHERE task_id = %d AND payload_type = %s",
                $sourceBatchId,
                "markdown_snapshot_{$i}"
            ));

            if ($markdown === null) {
                throw new RuntimeException(
                    "Batch {$sourceBatchId} has no markdown_snapshot_{$i}; lower --files."
                );
            }

            $title = 'Untitled';
            if (preg_match('/^#\s+(.+)$/m', $markdown, $matches) === 1) {
                $title = trim($matches[1]);
            }

            $filesMeta[] = [
                'index' => $i,
                'template_id' => $templateId,
                'template_name' => $template->post_title,
                'content_title' => $title,
                'draft_title' => null,
                'original_format' => 'markdown',
            ];

            $payloads[] = ['type' => "markdown_snapshot_{$i}", 'payload' => $markdown];
            $payloads[] = ['type' => "template_snapshot_{$i}", 'payload' => $templateContent];
        }

        $meta = [
            'agent_id' => 'content-integrator',
            'provider' => $combo->provider,
            'preset' => $combo->preset,
            'batch_name' => 'QG campaign ' . $combo->key() . ($modelId !== null ? ' @' . $modelId : ''),
            'file_count' => $files,
            'create_draft' => false,
            'files_meta' => wp_json_encode($filesMeta),
            self::CAMPAIGN_META_KEY => '1',
        ];

        if ($modelId !== null && $modelId !== '') {
            $meta = array_merge($meta, $this->modelOverrideMeta($combo, $modelId));
        }

        $request = new WP_REST_Request('POST', '/aiforge/v1/tasks');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'taskType' => 'batch_markdown_to_gutenberg',
            'meta' => $meta,
            'payloads' => $payloads,
        ]));

        $response = rest_do_request($request);

        if ($response->get_status() >= 400) {
            throw new RuntimeException(\sprintf(
                'Task creation failed for %s (HTTP %d): %s',
                $combo->key(),
                $response->get_status(),
                wp_json_encode($response->get_data())
            ));
        }

        $data = $response->get_data();
        $taskId = (int) ($data['data']['id'] ?? $data['id'] ?? 0);

        if ($taskId === 0) {
            throw new RuntimeException("Task creation for {$combo->key()} returned no ID.");
        }

        return $taskId;
    }

    public function isRootFinished(int $rootId): bool
    {
        $status = (string) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT status FROM {$this->wpdb->prefix}aiforge_tasks WHERE id = %d",
            $rootId
        ));

        return \in_array($status, ['completed', 'failed', 'cancelled'], true);
    }

    /**
     * Advance the pipeline without waiting for cron.
     */
    public function drain(int $budgetSeconds): void
    {
        $core = \AIForge\Core::getInstance();

        if ($core === null) {
            throw new RuntimeException('AI Forge core is not booted.');
        }

        $core->getTaskExecutor()->executePending($budgetSeconds);
    }

    /**
     * Pull the Quality Gate meta off every llm_generate descendant.
     *
     * @param array<string, int[]> $rootIdsByComboKey
     * @return RunResult[]
     */
    public function collect(array $rootIdsByComboKey): array
    {
        $results = [];

        foreach ($rootIdsByComboKey as $comboKey => $rootIds) {
            foreach ($rootIds as $rootId) {
                $llmIds = $this->wpdb->get_col($this->wpdb->prepare(
                    "SELECT id FROM {$this->wpdb->prefix}aiforge_tasks
                     WHERE root_id = %d AND task_type = 'llm_generate' ORDER BY id",
                    $rootId
                ));

                foreach ($llmIds as $llmId) {
                    $results[] = $this->buildRunResult((int) $llmId, (string) $comboKey);
                }
            }
        }

        return $results;
    }

    private function buildRunResult(int $llmId, string $comboKey): RunResult
    {
        $meta = [];

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->wpdb->prefix}aiforge_task_meta WHERE task_id = %d",
            $llmId
        ));

        foreach ($rows as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }

        $subscores = [];
        foreach (RunResult::AXES as $axis) {
            $subscores[$axis] = (int) ($meta["quality_score_{$axis}"] ?? 0);
        }

        return new RunResult(
            taskId: $llmId,
            comboKey: $comboKey,
            modelId: (string) ($meta['model_id'] ?? 'unknown'),
            verdict: (string) ($meta['quality_gate_verdict'] ?? 'unknown'),
            globalScore: (int) ($meta['quality_score_global'] ?? 0),
            subscores: $subscores,
            cost: (float) ($meta['cost'] ?? 0.0),
        );
    }
}
