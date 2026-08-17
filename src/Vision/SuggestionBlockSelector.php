<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Picks which image blocks the suggestion bench runs on.
 *
 * Two things matter and pull against each other: covering distinct editorial
 * situations (an opening image reads nothing like one buried mid-section), and
 * not drawing the whole sample from a single post, which would compare models
 * on one subject. Selection walks posts round-robin and, within a post, takes
 * context profiles it has not seen yet before repeating one.
 *
 * Deterministic by construction — a bench that reshuffles its own sample cannot
 * be compared against its previous run.
 */
final class SuggestionBlockSelector
{
    /**
     * @param list<array{post_id: int, block_index: int, context: array<string, mixed>}> $candidates
     * @return list<array{post_id: int, block_index: int, context: array<string, mixed>}>
     */
    public static function select(array $candidates, int $wanted): array
    {
        if (\count($candidates) <= $wanted) {
            return $candidates;
        }

        $byPost = [];
        foreach ($candidates as $c) {
            $byPost[$c['post_id']][] = $c;
        }
        ksort($byPost);

        $picked = [];
        $seenProfiles = [];
        $exhausted = false;

        while (\count($picked) < $wanted && !$exhausted) {
            $exhausted = true;

            foreach ($byPost as $postId => $blocks) {
                if (\count($picked) >= $wanted) {
                    break;
                }
                if ($blocks === []) {
                    continue;
                }

                $index = self::pickIndex($blocks, $seenProfiles);
                $chosen = $blocks[$index];

                $picked[] = $chosen;
                $seenProfiles[self::profile($chosen)] = true;

                array_splice($byPost[$postId], $index, 1);
                $exhausted = false;
            }
        }

        return $picked;
    }

    /**
     * Index of the first block whose profile has not been taken yet, falling
     * back to the first one when they are all already represented.
     *
     * @param list<array{post_id: int, block_index: int, context: array<string, mixed>}> $blocks
     * @param array<string, bool>                                                        $seen
     */
    private static function pickIndex(array $blocks, array $seen): int
    {
        foreach ($blocks as $i => $block) {
            if (!isset($seen[self::profile($block)])) {
                return $i;
            }
        }

        return 0;
    }

    /** @param array{context: array<string, mixed>} $block */
    private static function profile(array $block): string
    {
        return ($block['context']['image_position'] ?? '?')
            . '/' . ($block['context']['text_density'] ?? '?')
            . '/' . (($block['context']['section_heading'] ?? '') === '' ? 'no-heading' : 'heading');
    }
}
