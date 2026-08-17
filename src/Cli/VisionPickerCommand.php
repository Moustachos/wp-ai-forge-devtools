<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\Vision\VisionBenchRunner;
use AIForge\Vision\VisionPickerPage;
use WP_CLI;

/**
 * Generates the visual picker used to fill in a benchmark query file.
 */
final class VisionPickerCommand
{
    /**
     * Build an HTML page that shows each query with its candidate thumbnails.
     *
     * ## OPTIONS
     *
     * [--queries=<file>]
     * : Query file name inside the aiforge-dev upload directory.
     *   Default queries.json.
     *
     * [--images=<n>]
     * : Sample size the benchmark will use, so the picker offers the same
     *   images. Default 100.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev vision-picker
     *     wp aiforge-dev vision-picker --queries=queries.json --images=100
     *
     * @param string[]              $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        wp_set_current_user(1);

        $fileName = basename((string) ($assoc['queries'] ?? 'queries.json'));
        $dir = wp_upload_dir()['basedir'] . '/aiforge-dev';
        $path = $dir . '/' . $fileName;

        if (!file_exists($path)) {
            WP_CLI::error("Query file not found: $path");
        }

        $queries = json_decode((string) file_get_contents($path), true);
        if (!\is_array($queries)) {
            WP_CLI::error("Query file is not valid JSON: $path");
        }

        $runner = new VisionBenchRunner($wpdb);
        $sample = $runner->resolveSample((int) ($assoc['images'] ?? 100));

        $images = [];
        foreach ($sample as $id) {
            $url = wp_get_attachment_image_url($id, 'medium');
            if (!$url) {
                $url = wp_get_attachment_url($id);
            }
            if (!$url) {
                continue;
            }
            $images[$id] = [
                'url' => $url,
                'title' => mb_substr((string) get_the_title($id), 0, 22),
            ];
        }

        // Candidates pointing outside the sample would render as broken tiles.
        $outside = [];
        foreach ($queries as $q) {
            foreach (array_merge($q['candidates'] ?? [], $q['expect'] ?? []) as $id) {
                if (!isset($images[(int) $id]) && !\in_array((int) $id, $outside, true)) {
                    $outside[] = (int) $id;
                }
            }
        }
        if ($outside !== []) {
            WP_CLI::warning('Referenced outside the sample, tiles will be blank: ' . implode(', ', $outside));
        }

        $html = VisionPickerPage::render(
            $queries,
            $images,
            $fileName,
            rest_url('aiforge-dev/v1/vision-queries'),
            wp_create_nonce('wp_rest')
        );

        $out = $dir . '/picker.html';
        file_put_contents($out, $html);

        $url = wp_upload_dir()['baseurl'] . '/aiforge-dev/picker.html';

        WP_CLI::success(sprintf('%d queries, %d images. Open %s', \count($queries), \count($images), $url));
        WP_CLI::log('Log into wp-admin first — the page saves through the REST API with your cookie.');
        WP_CLI::log('The nonce expires after about 12 hours; re-run this command to refresh it.');
    }
}
