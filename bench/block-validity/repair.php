<?php

/**
 * Applies the plugin's final block-validity pass, alone, to the exported
 * corpus, as production runs it after every other step. Checks idempotence.
 *
 *     wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/block-validity/repair.php
 *
 * Run export.php first. Writes out/repaired/{templates,generations}.jsonl and
 * out/repaired/changed.json.
 */

use AIForge\Agent\ContentIntegrator\Sanitizer\BlockValidityRepairer;

$in = __DIR__ . '/out';
$out = $in . '/repaired';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

$totals = array_fill_keys(BlockValidityRepairer::FAMILIES, 0);
$changed = [];
$unstable = 0;

foreach (['templates', 'generations'] as $kind) {
    $src = fopen("{$in}/{$kind}.jsonl", 'r');
    $dst = fopen("{$out}/{$kind}.jsonl", 'w');
    $changed[$kind] = [];
    $read = 0;
    $notIdempotent = [];

    while (($line = fgets($src)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row) || !is_string($row['content'] ?? null)) {
            continue;
        }
        $read++;
        $key = $row['id'] ?? $row['hash'];

        $repairer = new BlockValidityRepairer();
        $once = $repairer->repair($row['content']);
        foreach ($repairer->getRepairCounts() as $family => $count) {
            $totals[$family] += $count;
        }

        if ((new BlockValidityRepairer())->repair($once) !== $once) {
            $notIdempotent[] = $key;
        }
        if ($once !== $row['content']) {
            $changed[$kind][] = $key;
        }

        $row['content'] = $once;
        fwrite($dst, wp_json_encode($row) . "\n");
    }

    fclose($src);
    fclose($dst);
    $unstable += count($notIdempotent);

    WP_CLI::log(sprintf(
        '%s: %d read, %d changed, %d not idempotent%s',
        $kind,
        $read,
        count($changed[$kind]),
        count($notIdempotent),
        $notIdempotent !== [] ? ' (' . implode(', ', array_slice($notIdempotent, 0, 20)) . ')' : ''
    ));
}

file_put_contents("{$out}/changed.json", wp_json_encode($changed));
WP_CLI::log('repairs per family: ' . wp_json_encode($totals));

if ($unstable > 0) {
    WP_CLI::error("{$unstable} documents are not idempotent.");
}
WP_CLI::success('Repaired corpus written to ' . $out);
