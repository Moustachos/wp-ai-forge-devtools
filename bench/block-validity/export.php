<?php
/**
 * Dumps every completed Content Integrator generation, plus the distinct
 * templates they were generated from, as JSONL for the block validity spike.
 *
 * Run from wp-lab:
 *   npx wp-env run cli wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/block-validity/export.php
 *
 * Payloads are fetched one task at a time: joining the longtext payloads
 * against task_meta in a single query wedges the Docker MySQL.
 */

global $wpdb;

$out = __DIR__ . '/out';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

$tasks = $wpdb->prefix . 'aiforge_tasks';
$payloads = $wpdb->prefix . 'aiforge_task_payloads';
$meta = $wpdb->prefix . 'aiforge_task_meta';

$rows = $wpdb->get_results(
    "SELECT t.id, t.parent_id, t.created_at
       FROM {$tasks} t
      WHERE t.task_type = 'llm_generate' AND t.status = 'completed'
        AND EXISTS (SELECT 1 FROM {$payloads} p WHERE p.task_id = t.id AND p.payload_type = 'result_content')
      ORDER BY t.id",
    ARRAY_A
);

$metaOf = static function (int $taskId, array $keys) use ($wpdb, $meta): array {
    $in = implode(',', array_fill(0, count($keys), '%s'));
    $found = $wpdb->get_results(
        $wpdb->prepare("SELECT meta_key, meta_value FROM {$meta} WHERE task_id = %d AND meta_key IN ({$in})", $taskId, ...$keys),
        ARRAY_A
    );
    return array_column($found, 'meta_value', 'meta_key');
};

$payloadOf = static function (int $taskId, string $type) use ($wpdb, $payloads): ?string {
    return $wpdb->get_var(
        $wpdb->prepare("SELECT payload FROM {$payloads} WHERE task_id = %d AND payload_type = %s ORDER BY id DESC LIMIT 1", $taskId, $type)
    );
};

$gen = fopen($out . '/generations.jsonl', 'w');
$tpl = fopen($out . '/templates.jsonl', 'w');
$seenTemplates = [];

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $parentId = (int) $row['parent_id'];
    $own = $metaOf($id, ['provider', 'model_id', 'quality_gate_verdict', 'quality_score_global']);
    $parent = $metaOf($parentId, ['preset', 'template_id', 'template_name']);

    $template = $payloadOf($id, 'template_snapshot') ?? $payloadOf($parentId, 'template_snapshot');
    $templateHash = $template !== null ? md5($template) : null;

    if ($template !== null && !isset($seenTemplates[$templateHash])) {
        $seenTemplates[$templateHash] = true;
        fwrite($tpl, wp_json_encode([
            'hash' => $templateHash,
            'template_id' => $parent['template_id'] ?? null,
            'template_name' => $parent['template_name'] ?? null,
            'content' => $template,
        ]) . "\n");
    }

    fwrite($gen, wp_json_encode([
        'id' => $id,
        'parent_id' => $parentId,
        'created_at' => $row['created_at'],
        'provider' => $own['provider'] ?? null,
        'model_id' => $own['model_id'] ?? null,
        'preset' => $parent['preset'] ?? null,
        'template_id' => $parent['template_id'] ?? null,
        'template_name' => $parent['template_name'] ?? null,
        'template_hash' => $templateHash,
        'qg_verdict' => $own['quality_gate_verdict'] ?? null,
        'qg_global' => isset($own['quality_score_global']) ? (float) $own['quality_score_global'] : null,
        'content' => $payloadOf($id, 'result_content'),
    ]) . "\n");
}

fclose($gen);
fclose($tpl);

WP_CLI::success(sprintf('%d generations, %d distinct templates written to %s', count($rows), count($seenTemplates), $out));
