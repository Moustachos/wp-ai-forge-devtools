<?php

declare(strict_types=1);

namespace AIForge\REST;

use AIForge\Agent\ContentIntegrator\GutenbergSanitizer;
use AIForge\Agent\ContentIntegrator\MarkdownSanitizer;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class SanitizerController extends WP_REST_Controller
{
    protected $namespace = 'aiforge-dev/v1';
    protected $rest_base = 'sanitizer/test';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => $this->test(...),
                'permission_callback' => $this->adminPermissionsCheck(...),
                'args' => [
                    'markdown' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                    'content' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                    'template' => [
                        'type' => 'string',
                        'required' => false,
                        'default' => '',
                    ],
                ],
            ],
        ]);
    }

    public function adminPermissionsCheck(): bool
    {
        return current_user_can('manage_options');
    }

    public function test(WP_REST_Request $request): WP_REST_Response
    {
        $markdown = $request->get_param('markdown');
        $content = $request->get_param('content');
        $template = $request->get_param('template');

        $markdownSanitizer = new MarkdownSanitizer();
        $sanitizedMarkdown = $markdownSanitizer->sanitize($markdown);

        $templateHasH1 = $template
            ? (bool) preg_match('/<!-- wp:heading \{"level":1[,}]/', $template)
            : false;

        $gutenbergSanitizer = new GutenbergSanitizer();
        $sanitized = $gutenbergSanitizer->sanitize($content, $sanitizedMarkdown, $templateHasH1);

        $changes = $this->calculateChanges($content, $sanitized);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'sanitized' => $sanitized,
                'changes' => $changes,
                'has_changes' => $content !== $sanitized,
            ],
        ]);
    }

    private function calculateChanges(string $original, string $sanitized): array
    {
        if ($original === $sanitized) {
            return [];
        }

        $changes = [];

        $this->detectPreambleStrip($original, $changes);

        $strippedOriginal = $this->stripPreamble($original);
        $originalBlocks = parse_blocks($strippedOriginal);
        $sanitizedBlocks = parse_blocks($sanitized);

        $this->compareBlocks($originalBlocks, $sanitizedBlocks, $changes);

        $this->detectEmptyParagraphsRemoved($originalBlocks, $sanitizedBlocks, $changes);
        $this->detectLoremIpsumRemoved($originalBlocks, $sanitizedBlocks, $changes);

        return $changes;
    }

    private function detectLoremIpsumRemoved(array $original, array $sanitized, array &$changes): void
    {
        $countLorem = function (array $blocks) use (&$countLorem): int {
            $count = 0;
            $patterns = ['lorem ipsum', 'dolor sit amet', 'consectetur adipiscing'];

            foreach ($blocks as $block) {
                $text = strtolower(strip_tags($block['innerHTML'] ?? ''));
                foreach ($patterns as $pattern) {
                    if (str_contains($text, $pattern)) {
                        $count++;
                        break;
                    }
                }
                if (!empty($block['innerBlocks'])) {
                    $count += $countLorem($block['innerBlocks']);
                }
            }
            return $count;
        };

        $origLorem = $countLorem($original);
        $sanLorem = $countLorem($sanitized);
        $removed = $origLorem - $sanLorem;

        if ($removed > 0) {
            $changes[] = [
                'type' => 'lorem_ipsum_removed',
                'count' => $removed,
            ];
        }
    }

    private function stripPreamble(string $content): string
    {
        $firstBlockPos = strpos($content, '<!-- wp:');

        if ($firstBlockPos === false) {
            return $content;
        }

        if ($firstBlockPos > 0) {
            $content = substr($content, $firstBlockPos);
        }

        $lastClosePos = strrpos($content, '<!-- /wp:');

        if ($lastClosePos !== false) {
            $endPos = strpos($content, '-->', $lastClosePos);
            if ($endPos !== false) {
                $content = substr($content, 0, $endPos + 3);
            }
        }

        return $content;
    }

    private function detectEmptyParagraphsRemoved(array $original, array $sanitized, array &$changes): void
    {
        $countEmpty = function (array $blocks) use (&$countEmpty): int {
            $count = 0;
            foreach ($blocks as $block) {
                if (($block['blockName'] ?? '') === 'core/paragraph') {
                    $text = trim(strip_tags($block['innerHTML'] ?? ''));
                    if ($text === '' || $text === '&nbsp;') {
                        $count++;
                    }
                }
                if (!empty($block['innerBlocks'])) {
                    $count += $countEmpty($block['innerBlocks']);
                }
            }
            return $count;
        };

        $origEmpty = $countEmpty($original);
        $sanEmpty = $countEmpty($sanitized);
        $removed = $origEmpty - $sanEmpty;

        if ($removed > 0) {
            $changes[] = [
                'type' => 'empty_paragraphs_removed',
                'count' => $removed,
            ];
        }
    }

    private function detectPreambleStrip(string $content, array &$changes): void
    {
        $firstBlockPos = strpos($content, '<!-- wp:');

        if ($firstBlockPos !== false && $firstBlockPos > 0) {
            $preamble = trim(substr($content, 0, $firstBlockPos));
            if ($preamble !== '') {
                $changes[] = [
                    'type' => 'preamble_stripped',
                    'content' => mb_substr($preamble, 0, 100),
                ];
            }
        }

        $lastClosePos = strrpos($content, '<!-- /wp:');

        if ($lastClosePos !== false) {
            $endPos = strpos($content, '-->', $lastClosePos);
            if ($endPos !== false) {
                $postamble = trim(substr($content, $endPos + 3));
                if ($postamble !== '') {
                    $changes[] = [
                        'type' => 'postamble_stripped',
                        'content' => mb_substr($postamble, 0, 100),
                    ];
                }
            }
        }
    }

    private function compareBlocks(array $original, array $sanitized, array &$changes, string $path = ''): void
    {
        foreach ($original as $index => $origBlock) {
            if (!isset($sanitized[$index])) {
                continue;
            }

            $sanBlock = $sanitized[$index];

            if (empty($origBlock['blockName'])) {
                continue;
            }

            $origAttrs = $origBlock['attrs'] ?? [];
            $sanAttrs = $sanBlock['attrs'] ?? [];

            if ($origBlock['blockName'] === 'core/heading') {
                $origLevel = $origAttrs['level'] ?? 2;
                $sanLevel = $sanAttrs['level'] ?? 2;

                if ($origLevel !== $sanLevel) {
                    $text = strip_tags($origBlock['innerHTML'] ?? '');
                    $changes[] = [
                        'type' => 'heading_level',
                        'block' => $origBlock['blockName'],
                        'text' => mb_substr(trim($text), 0, 50),
                        'from' => $origLevel,
                        'to' => $sanLevel,
                    ];
                }
            }

            if ($origBlock['blockName'] === 'core/list') {
                $origOrdered = $origAttrs['ordered'] ?? false;
                $sanOrdered = $sanAttrs['ordered'] ?? false;

                if ($origOrdered !== $sanOrdered) {
                    $changes[] = [
                        'type' => 'list_type',
                        'block' => $origBlock['blockName'],
                        'from' => $origOrdered ? 'ordered' : 'unordered',
                        'to' => $sanOrdered ? 'ordered' : 'unordered',
                    ];
                }

                $origInnerCount = count($origBlock['innerBlocks'] ?? []);
                $sanInnerCount = count($sanBlock['innerBlocks'] ?? []);

                if ($origInnerCount === 0 && $sanInnerCount > 0) {
                    $changes[] = [
                        'type' => 'list_items_wrapped',
                        'block' => $origBlock['blockName'],
                        'count' => $sanInnerCount,
                    ];
                }
            }

            if ($origBlock['blockName'] === 'core/quote') {
                $origCitation = $origAttrs['citation'] ?? '';
                $sanCitation = $sanAttrs['citation'] ?? '';

                if ($origCitation !== $sanCitation && $sanCitation !== '') {
                    $changes[] = [
                        'type' => 'quote_citation_synced',
                        'block' => $origBlock['blockName'],
                        'citation' => mb_substr($sanCitation, 0, 50),
                    ];
                }
            }

            foreach ($sanAttrs as $key => $value) {
                if (!array_key_exists($key, $origAttrs) && !in_array($key, ['ordered', 'citation'], true)) {
                    $changes[] = [
                        'type' => 'attribute_added',
                        'block' => $origBlock['blockName'],
                        'attribute' => $key,
                        'value' => is_string($value) ? $value : json_encode($value),
                    ];
                }
            }

            if (!empty($origBlock['innerBlocks']) && !empty($sanBlock['innerBlocks'])) {
                $this->compareBlocks($origBlock['innerBlocks'], $sanBlock['innerBlocks'], $changes, $path);
            }
        }
    }
}
