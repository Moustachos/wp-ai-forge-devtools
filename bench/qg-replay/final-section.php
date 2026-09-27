<?php

/**
 * Replays OutputValidator::lostFinalSection() on every stored generation.
 *
 *     wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/qg-replay/final-section.php [since=2026-06-01]
 *
 * Read-only. Meta is read per task: joining task_meta with payload longtext wedges MySQL.
 */

use AIForge\Agent\ContentIntegrator\OutputValidator;

global $wpdb;

$since = '2026-06-01';
foreach ($args as $arg) {
    if (str_starts_with($arg, 'since=')) {
        $since = substr($arg, 6);
    }
}

$tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT id, parent_id FROM {$wpdb->prefix}aiforge_tasks
     WHERE task_type = 'llm_generate' AND status = 'completed' AND created_at >= %s
     ORDER BY id",
    $since
));

$payload = static function (int $taskId, string $type) use ($wpdb): ?string {
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT payload FROM {$wpdb->prefix}aiforge_task_payloads WHERE task_id = %d AND payload_type = %s ORDER BY id DESC LIMIT 1",
        $taskId,
        $type
    ));

    return $value === null ? null : (string) $value;
};

$meta = static function (int $taskId, string $key) use ($wpdb): string {
    return (string) $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}aiforge_task_meta WHERE task_id = %d AND meta_key = %s",
        $taskId,
        $key
    ));
};

$validator = new OutputValidator();
$counts = ['scanned' => 0, 'warning' => 0, 'lost' => 0];
$hits = [];

foreach ($tasks as $task) {
    $markdown = $payload((int) $task->parent_id, 'markdown_snapshot');
    $output = $payload((int) $task->id, 'result_content');

    if ($markdown === null || $output === null) {
        continue;
    }

    $counts['scanned']++;

    if ($validator->checkContentCompletion($markdown, $output) !== 'warning') {
        continue;
    }

    $counts['warning']++;
    $lost = $validator->lostFinalSection($markdown, $output, $validator->checkSectionCoverage($markdown, $output));

    if ($lost === null) {
        continue;
    }

    $counts['lost']++;
    $hits[] = \sprintf(
        '%d  %-24s %-9s %-20s «%s»',
        $task->id,
        $meta((int) $task->id, 'model_id'),
        $meta((int) $task->id, 'quality_gate_verdict'),
        $meta((int) $task->parent_id, 'template_name'),
        $lost
    );
}

WP_CLI::log(\sprintf('since %s: %d scanned, %d completion warnings, %d lost final sections', $since, $counts['scanned'], $counts['warning'], $counts['lost']));
foreach ($hits as $hit) {
    WP_CLI::log($hit);
}
