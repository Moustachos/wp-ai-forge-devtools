<?php

declare(strict_types=1);

namespace AIForge\Cli;

use AIForge\REST\VisionQueryController;
use AIForge\Vision\SuggestionReviewPage;
use AIForge\Vision\SuggestionWastePage;
use WP_CLI;

/**
 * Builds the blind comparison page for a suggestion-bench report.
 */
final class SuggestionReviewCommand
{
    /**
     * ## OPTIONS
     *
     * [--report=<file>]
     * : suggestion-bench report inside the aiforge-dev upload directory.
     *   Defaults to the most recent sugg-*.json.
     *
     * [--mode=<mode>]
     * : `preference` (default) asks which series is best; `waste` asks which
     *   individual images do not belong. The second answers "how much does this
     *   index throw in that should not be there", which preference cannot.
     *
     * [--only-divergent]
     * : Skip blocks where every index proposed the same three images — there is
     *   nothing to choose between them.
     *
     * @param string[]              $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $dir = wp_upload_dir()['basedir'] . '/aiforge-dev/';

        $file = null;
        if (isset($assoc['report'])) {
            $file = $dir . basename((string) $assoc['report']);
        } else {
            $found = glob($dir . 'sugg-*.json') ?: [];
            sort($found);
            $file = $found === [] ? null : $found[\count($found) - 1];
        }

        if ($file === null || !file_exists($file)) {
            WP_CLI::error('No suggestion-bench report found. Run suggestion-bench first.');
        }

        $report = json_decode((string) file_get_contents($file), true);
        if (!\is_array($report) || empty($report['models'])) {
            WP_CLI::error("Unusable report: $file");
        }

        $names = array_keys($report['models']);
        $cases = [];

        foreach ($report['blocks'] as $i => $block) {
            $sets = [];
            foreach ($names as $name) {
                $sets[$name] = $report['models'][$name][$i]['suggestions'] ?? [];
            }

            $signatures = array_unique(array_map(static fn ($s) => implode(',', $s), $sets));
            if (isset($assoc['only-divergent']) && \count($signatures) === 1) {
                continue;
            }

            // Deterministic shuffle: the same block always presents its series
            // in the same order, but that order tells nothing about the model.
            $order = $names;
            usort($order, static fn ($a, $b) => strcmp(md5($a . '|' . $i), md5($b . '|' . $i)));

            $series = [];
            foreach ($order as $slot => $name) {
                $series[] = [
                    'letter' => \chr(65 + $slot),
                    'model' => $name,
                    'images' => array_map(static fn ($id) => self::image((int) $id), $sets[$name]),
                ];
            }

            $cases[] = [
                'block' => $i,
                'context' => $block['context'],
                'series' => $series,
                'verdict' => null,
            ];
        }

        if ($cases === []) {
            WP_CLI::error('Nothing to review.');
        }

        $token = wp_generate_password(32, false);
        set_transient(VisionQueryController::TOKEN_TRANSIENT, $token, 12 * HOUR_IN_SECONDS);

        $waste = ($assoc['mode'] ?? 'preference') === 'waste';
        $file = $waste ? 'suggestion-waste.html' : 'suggestion-review.html';
        $verdictFile = $waste ? 'suggestion-waste.json' : 'suggestion-verdicts.json';

        $html = $waste
            ? SuggestionWastePage::render($cases, $verdictFile, rest_url('aiforge-dev/v1/vision-queries'), $token)
            : SuggestionReviewPage::render($cases, $verdictFile, rest_url('aiforge-dev/v1/vision-queries'), $token);

        file_put_contents($dir . $file, $html);

        WP_CLI::success(sprintf(
            '%d case(s) to review. Open %s',
            \count($cases),
            wp_upload_dir()['baseurl'] . '/aiforge-dev/' . $file
        ));
        WP_CLI::log("Verdicts are saved to $verdictFile; models stay hidden until you reveal them.");
    }

    /**
     * @return array{id: int, url: string, title: string}
     */
    private static function image(int $id): array
    {
        $src = wp_get_attachment_image_src($id, 'medium');

        return [
            'id' => $id,
            'url' => $src ? $src[0] : (string) wp_get_attachment_url($id),
            'title' => mb_substr((string) get_the_title($id), 0, 24),
        ];
    }
}
