<?php

/**
 * Drafts one corpus file through the plugin's own provider, from its brief.
 *
 *     cd wp-lab && npx wp-env run cli -- timeout 300 wp eval-file \
 *       wp-content/plugins/wp-ai-forge-devtools/bench/ci-corpus/hard/generate.php hard-02-cabinet-kine [overwrite]
 *
 * Refuses to replace an existing file unless "overwrite" is passed: edited
 * drafts are worth more than a fresh one.
 *
 * No declare(strict_types=1): `wp eval-file` runs this through eval(), and
 * PHP rejects that declaration in eval()'d code regardless of position.
 */

use AIForge\AI\ProviderFactory;
use AIForge\Config\ConfigRepository;

const MODELS = ['gemini' => ['gemini', 'gemini-3.7-flash'], 'gpt' => ['openai', 'gpt-5.4']];

$id = (string) ($args[0] ?? '');
$overwrite = in_array('overwrite', $args, true);
$briefs = json_decode((string) file_get_contents(__DIR__ . '/briefs.json'), true);
$brief = null;

foreach ((array) $briefs as $candidate) {
    if (($candidate['id'] ?? '') === $id) {
        $brief = $candidate;
    }
}

if ($brief === null) {
    WP_CLI::error("No brief for '{$id}'.");
}

if (!isset(MODELS[$brief['author']])) {
    WP_CLI::error("{$id} is authored by {$brief['author']}; only gemini and gpt drafts are generated.");
}

$target = __DIR__ . "/{$id}.md";

if (file_exists($target) && !$overwrite) {
    WP_CLI::error("{$target} exists. Pass 'overwrite' to replace it.");
}

$prompt = sprintf(
    'Write the full text of a web page for %s, a %s business, in %s, as Markdown: one `#` title, then `##` sections, `###` where natural. About %d words. Tone: a real small business describing itself to prospects, no marketing clichés. It must state exactly these facts and no other figure: %s. %s Links only as `[text](url)` with example.com URLs. No images, no HTML, no `<` character.',
    $brief['business'],
    $brief['sector'],
    $brief['language'],
    (int) $brief['words'],
    $brief['facts'],
    $brief['trap_instructions']
);

if (!empty($brief['links'])) {
    $prompt .= ' Use these links where natural: ' . implode(', ', $brief['links']) . '.';
}

[$providerId, $model] = MODELS[$brief['author']];
$provider = ProviderFactory::make($providerId, new ConfigRepository());
$provider->setModel($model);
$result = $provider->complete($prompt, ['max_tokens' => 8000]);

if (!$result->success || $result->content === null) {
    WP_CLI::error("{$model} failed: " . ($result->error ?? 'empty response'));
}

$markdown = trim((string) preg_replace('/^```(?:markdown|md)?\s*\n|\n```\s*$/', '', trim($result->content))) . "\n";
file_put_contents($target, $markdown);

WP_CLI::success(sprintf('%s written by %s (%d words).', basename($target), $model, str_word_count(strip_tags($markdown))));
