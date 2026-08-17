<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Rebuilds, from a post's stored blocks, the editorial context the block editor
 * sends when a user asks for an image suggestion.
 *
 * This is a PHP mirror of `admin/src/editor/contextExtractor.js`, so the bench
 * measures what the product actually sends rather than an approximation of it.
 * The constants below are that file's, and drift between the two makes the
 * benchmark meaningless — change them together.
 *
 * One difference is unavoidable: the editor reads a block's text from its
 * parsed `content` attribute, which only exists once Gutenberg has applied the
 * block's attribute sources. Server-side, `parse_blocks()` leaves that empty
 * and the text lives in `innerHTML`, so the text is stripped from there.
 */
final class SuggestionContextBuilder
{
    private const MAX_PRECEDING_BLOCKS = 3;
    private const MAX_FOLLOWING_BLOCKS = 2;
    private const MAX_WORDS = 200;

    /** Containers hold no text of their own and are dropped from the sequence. */
    private const CONTAINER_BLOCKS = ['core/group', 'core/columns', 'core/column', 'core/row', 'core/stack'];

    private const TEXT_BLOCKS = ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote'];

    private const IMAGE_BLOCKS = ['core/image', 'core/cover', 'core/media-text'];

    /**
     * Depth-first flatten. Containers disappear and their children take their
     * place; `core/cover` is hybrid — it carries a background image and holds
     * text, so it is kept *and* recursed into.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $blocks): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            if ($name === '') {
                continue;
            }

            $inner = $block['innerBlocks'] ?? [];
            $hasInner = $inner !== [];

            if ($hasInner && \in_array($name, self::CONTAINER_BLOCKS, true)) {
                $out = array_merge($out, self::flatten($inner));
                continue;
            }

            $out[] = $block;

            if ($name === 'core/cover' && $hasInner) {
                $out = array_merge($out, self::flatten($inner));
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $blocks       Top-level blocks of the post
     * @param int                        $targetIndex  Index in the *flattened* list
     * @return array<string, mixed>
     */
    public static function build(array $blocks, int $targetIndex, int $postId, string $title, string $type): array
    {
        $flat = self::flatten($blocks);
        $name = $flat[$targetIndex]['blockName'] ?? '';

        return [
            'post_id' => $postId,
            'post_title' => $title,
            'post_type' => $type,
            'block_type' => $name,
            'section_heading' => self::nearestHeading($flat, $targetIndex),
            'preceding_text' => self::truncateWords(self::collect($flat, $targetIndex, -1, self::MAX_PRECEDING_BLOCKS), self::MAX_WORDS),
            'following_text' => self::truncateWords(self::collect($flat, $targetIndex, 1, self::MAX_FOLLOWING_BLOCKS), self::MAX_WORDS),
            'image_position' => self::imagePosition($flat, $targetIndex, $name),
            'is_in_gallery' => false,
            'parent_layout' => ['type' => 'default'],
            'text_density' => self::textDensity($flat, $targetIndex),
        ];
    }

    /** @param list<array<string, mixed>> $flat */
    private static function nearestHeading(array $flat, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (($flat[$i]['blockName'] ?? '') === 'core/heading') {
                return self::text($flat[$i]);
            }
        }

        return '';
    }

    /** @param list<array<string, mixed>> $flat */
    private static function collect(array $flat, int $start, int $direction, int $maxBlocks): string
    {
        $texts = [];
        $collected = 0;

        for ($i = $start + $direction; $i >= 0 && $i < \count($flat) && $collected < $maxBlocks; $i += $direction) {
            $text = self::text($flat[$i]);
            if ($text !== '') {
                $texts[] = $text;
                $collected++;
            }
        }

        if ($direction === -1) {
            $texts = array_reverse($texts);
        }

        return implode(' ', $texts);
    }

    /** @param array<string, mixed> $block */
    private static function text(array $block): string
    {
        if (!\in_array($block['blockName'] ?? '', self::TEXT_BLOCKS, true)) {
            return '';
        }

        $html = (string) ($block['innerHTML'] ?? '');
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param list<array<string, mixed>> $flat */
    private static function imagePosition(array $flat, int $index, string $name): string
    {
        if (!\in_array($name, self::IMAGE_BLOCKS, true)) {
            return 'middle';
        }

        $imageIndices = [];
        foreach ($flat as $i => $block) {
            if (\in_array($block['blockName'] ?? '', self::IMAGE_BLOCKS, true)) {
                $imageIndices[] = $i;
            }
        }

        if (\count($imageIndices) <= 1) {
            return 'first';
        }

        $pos = array_search($index, $imageIndices, true);
        if ($pos === 0) {
            return 'first';
        }
        if ($pos === \count($imageIndices) - 1) {
            return 'last';
        }

        return 'middle';
    }

    /** @param list<array<string, mixed>> $flat */
    private static function textDensity(array $flat, int $index): string
    {
        $words = 0;
        for ($i = max(0, $index - 3); $i < min(\count($flat), $index + 3); $i++) {
            $t = self::text($flat[$i]);
            if ($t !== '') {
                $words += \count(preg_split('/\s+/u', $t) ?: []);
            }
        }

        return match (true) {
            $words < 40 => 'low',
            $words > 150 => 'high',
            default => 'medium',
        };
    }

    private static function truncateWords(string $text, int $maxWords): string
    {
        $words = array_values(array_filter(preg_split('/\s+/u', $text) ?: []));
        if (\count($words) <= $maxWords) {
            return $text;
        }

        return implode(' ', \array_slice($words, 0, $maxWords)) . '...';
    }
}
