<?php

/**
 * Static precondition checks and gate lint over a corpus. Plain PHP, no WordPress:
 *
 *     cd wp-lab && npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-forge-devtools php bench/ci-corpus/check.php hard
 */

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\CorpusPreconditions;
use AIForge\Campaign\MarkdownDocument;
use AIForge\Campaign\TextTools;

$name = $argv[1] ?? 'hard';

try {
    $manifest = CorpusManifest::fromName(__DIR__, $name);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$errors = 0;

foreach ($manifest->errors() as $error) {
    echo "ERROR manifest: {$error}\n";
    $errors++;
}

foreach ($manifest->entries as $entry) {
    $markdown = $manifest->markdown($entry);

    if ($markdown === null) {
        continue;
    }

    printf(
        "%-36s %-8s %5d words  %s\n",
        $entry->id,
        $entry->author,
        TextTools::wordCount((new MarkdownDocument($markdown))->plainText()),
        implode(',', $entry->traps)
    );

    foreach (CorpusPreconditions::check($entry, $markdown) as $error) {
        echo "  ERROR {$error}\n";
        $errors++;
    }

    foreach (CorpusPreconditions::lint($markdown) as $warning) {
        echo "  warn  {$warning}\n";
    }
}

echo $errors === 0 ? "clean\n" : "{$errors} error(s)\n";
exit($errors === 0 ? 0 : 1);
