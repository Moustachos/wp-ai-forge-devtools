<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\REST\VisionQueryController;
use AIForge\Vision\IndexDivergence;
use AIForge\Vision\IndexReviewPage;
use WP_CLI;

/**
 * Compares the media indexes of a vision-bench report and builds the page that
 * settles the facets they disagree on.
 */
final class IndexReviewCommand
{
    private const OBJECTIVE = ['people_count', 'has_text', 'image_type'];

    /**
     * ## OPTIONS
     *
     * [--report=<file>]
     * : vision-bench report inside the aiforge-dev upload directory. Repeat with
     *   a comma to merge several, which is how a model re-run after an outage
     *   rejoins the others.
     *
     * [--facets=<list>]
     * : Facets to compare. Defaults to the ones with an objective answer, the
     *   only ones a reviewer can settle without stating a preference.
     *
     * [--report-only]
     * : Print the comparison and skip the review page.
     *
     * @param string[]              $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $dir = wp_upload_dir()['basedir'] . '/aiforge-dev/';

        $files = [];
        if (isset($assoc['report'])) {
            foreach (explode(',', (string) $assoc['report']) as $name) {
                $files[] = $dir . basename(trim($name));
            }
        } else {
            $found = glob($dir . 'vision-*.json') ?: [];
            sort($found);
            $files = $found === [] ? [] : [$found[\count($found) - 1]];
        }

        $models = [];
        foreach ($files as $file) {
            if (!file_exists($file)) {
                WP_CLI::error("Report not found: $file");
            }
            $report = json_decode((string) file_get_contents($file), true);
            foreach ($report['models'] ?? [] as $name => $data) {
                if (!empty($data['rows'])) {
                    $models[$name] = $data['rows'];
                }
            }
        }

        if (\count($models) < 2) {
            WP_CLI::error('Need at least two indexes to compare. Merge reports with --report=a.json,b.json');
        }

        $facets = isset($assoc['facets'])
            ? array_map('trim', explode(',', (string) $assoc['facets']))
            : self::OBJECTIVE;

        $result = IndexDivergence::compare($models, $facets);

        WP_CLI::log(sprintf(
            '%d image(s) indexed by all %d models, %d facet call(s) unanimous, %d split.',
            $result['compared'],
            \count($models),
            $result['unanimous'],
            $result['split']
        ));
        WP_CLI::log('');
        WP_CLI::log('Alone against every other model:');

        $minority = $result['minority'];
        asort($minority);
        $calls = max(1, $result['compared'] * \count($facets));
        foreach ($minority as $name => $count) {
            WP_CLI::log(sprintf('  %-34s %3d  (%.1f%% of its calls)', $name, $count, $count / $calls * 100));
        }

        $swaps = IndexDivergence::swaps($models);
        WP_CLI::log('');
        if ($swaps === []) {
            WP_CLI::log('No image pair was described the wrong way round.');
        } else {
            WP_CLI::warning(\count($swaps) . ' image pair(s) described the wrong way round:');
            foreach ($swaps as [$name, $a, $b]) {
                WP_CLI::log("  $name: #$a <-> #$b");
            }
        }

        if (isset($assoc['report-only'])) {
            return;
        }

        $cases = [];
        foreach (IndexDivergence::cases($models, $facets) as $case) {
            $id = (int) $case['attachment_id'];
            $src = wp_get_attachment_image_src($id, 'large');
            $meta = wp_get_attachment_metadata($id);

            $cases[] = $case + [
                'url' => $src ? $src[0] : (string) wp_get_attachment_url($id),
                'full' => (string) wp_get_attachment_url($id),
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
                'verdict' => null,
            ];
        }

        if ($cases === []) {
            WP_CLI::success('The indexes agree on every objective facet. Nothing to review.');

            return;
        }

        shuffle($cases);

        $token = wp_generate_password(32, false);
        set_transient(VisionQueryController::TOKEN_TRANSIENT, $token, 12 * HOUR_IN_SECONDS);

        file_put_contents($dir . 'index-review.html', IndexReviewPage::render(
            $cases,
            'index-verdicts.json',
            rest_url('aiforge-dev/v1/vision-queries'),
            $token
        ));

        WP_CLI::success(sprintf(
            '%d disagreement(s) to settle. Open %s',
            \count($cases),
            wp_upload_dir()['baseurl'] . '/aiforge-dev/index-review.html'
        ));
    }
}
