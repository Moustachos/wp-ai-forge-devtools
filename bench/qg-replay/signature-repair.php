<?php

/**
 * Signature score of every generation the final repair pass changes, before
 * and after the pass (spec S18 Phase B, criterion 4). No run may lose points.
 *
 *     wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/qg-replay/signature-repair.php
 *
 * Reads bench/block-validity/out/repaired/changed.json (run repair.php first).
 * Read-only. Meta is never joined with payloads: that wedges MySQL.
 */

use AIForge\Agent\ContentIntegrator\IntegrationPlanner;
use AIForge\Agent\ContentIntegrator\QualityGate;
use AIForge\Agent\ContentIntegrator\Sanitizer\BlockValidityRepairer;
use AIForge\Agent\ContentIntegrator\TemplateBlueprintAnalyzer;

global $wpdb;

$changed = json_decode((string) file_get_contents(dirname(__DIR__) . '/block-validity/out/repaired/changed.json'), true);
$ids = array_map('intval', $changed['generations'] ?? []);

$payload = static function (int $taskId, string $type) use ($wpdb): ?string {
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT payload FROM {$wpdb->prefix}aiforge_task_payloads WHERE task_id = %d AND payload_type = %s ORDER BY id DESC LIMIT 1",
        $taskId,
        $type
    ));

    return $value === null ? null : (string) $value;
};

$parentOf = static function (int $taskId) use ($wpdb): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT parent_id FROM {$wpdb->prefix}aiforge_tasks WHERE id = %d", $taskId));
};

$gate = new QualityGate();
$score = new ReflectionMethod(QualityGate::class, 'scoreSignature');
$planner = new IntegrationPlanner();
$structured = new ReflectionMethod(IntegrationPlanner::class, 'extractStructuredMapping');
$textual = new ReflectionMethod(IntegrationPlanner::class, 'parseTextMapping');
$analyzer = new TemplateBlueprintAnalyzer();

$replayed = 0;
$lost = 0;

foreach ($ids as $id) {
    $output = $payload($id, 'result_content');
    $parent = $parentOf($id);
    $template = $payload($id, 'template_snapshot') ?? $payload($parent, 'template_snapshot');

    if ($output === null || $template === null) {
        WP_CLI::log("{$id}  skipped (missing payload)");
        continue;
    }

    $blueprint = $analyzer->analyze($template);
    $plan = (string) ($payload($id, 'integration_plan') ?? '');
    $mapping = $structured->invoke($planner, $plan, $blueprint) ?? $textual->invoke($planner, $plan, $blueprint);

    $before = $score->invoke($gate, parse_blocks($output), $blueprint, $mapping)[0];
    $after = $score->invoke($gate, parse_blocks((new BlockValidityRepairer())->repair($output)), $blueprint, $mapping)[0];
    $replayed++;

    if ($before === null || $after === null) {
        WP_CLI::log("{$id}  n/a (template has no signature)");
        continue;
    }

    $drop = $after < $before;
    $lost += $drop ? 1 : 0;
    WP_CLI::log(sprintf('%d  %6.1f -> %6.1f%s', $id, $before, $after, $drop ? '  LOST' : ''));
}

WP_CLI::log(sprintf('%d runs replayed, %d lost signature points', $replayed, $lost));
