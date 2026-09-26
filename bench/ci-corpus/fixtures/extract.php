<?php

/**
 * Extracts TrapChecker fixtures from stored runs, once. For each llm_generate
 * task id: its result_content and integration_plan, and its parent's
 * markdown_snapshot and template_snapshot.
 *
 *     cd wp-lab && npx wp-env run cli -- timeout 120 wp eval-file \
 *       wp-content/plugins/wp-ai-forge-devtools/bench/ci-corpus/fixtures/extract.php 6687 7014
 */

global $wpdb;

$out = \dirname(__DIR__, 3) . '/tests/fixtures/trap';

if (!wp_mkdir_p($out)) {
    WP_CLI::error("Cannot create {$out}.");
}

$payload = static function (int $taskId, string $type) use ($wpdb): ?string {
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT payload FROM {$wpdb->prefix}aiforge_task_payloads WHERE task_id = %d AND payload_type = %s",
        $taskId,
        $type
    ));

    return $value === null ? null : (string) $value;
};

foreach ($args as $arg) {
    $id = (int) $arg;
    $parent = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT parent_id FROM {$wpdb->prefix}aiforge_tasks WHERE id = %d AND task_type = 'llm_generate'",
        $id
    ));

    if ($parent === 0) {
        WP_CLI::warning("{$id}: not an llm_generate task with a parent, skipped.");
        continue;
    }

    $files = [
        'html' => $payload($id, 'result_content'),
        'md' => $payload($parent, 'markdown_snapshot'),
        'tpl.html' => $payload($parent, 'template_snapshot'),
        'plan.txt' => $payload($id, 'integration_plan'),
    ];

    foreach ($files as $extension => $content) {
        if ($content === null) {
            WP_CLI::warning("{$id}: no {$extension}.");
            continue;
        }

        file_put_contents("{$out}/{$id}.{$extension}", $content);
    }

    WP_CLI::log("{$id} (parent {$parent}) extracted.");
}
