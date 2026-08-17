<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Measures how far indexes lead to the same media suggestions.
 *
 * The point is to spend human judgement only where the indexes disagree. That
 * makes the silent case dangerous: blocks nobody answered are trivially
 * "identical" and, counted as agreement, turn a broken run into a verdict. The
 * first version of this bench reported unanimity on twelve blocks while every
 * set was empty, because the result parser was reading the wrong key.
 *
 * So blocks are sorted into three buckets — answered by everyone (comparable),
 * answered by some (partial), answered by nobody (no_answer) — and unanimity is
 * only ever counted within the first.
 */
final class SuggestionAgreement
{
    /**
     * @param array<string, list<array{block: int, suggestions: list<int>}>> $models
     * @return array<string, mixed>
     */
    public static function compute(array $models, int $blockCount): array
    {
        $names = array_keys($models);
        $unanimous = 0;
        $fullySplit = 0;
        $sameFirst = 0;
        $comparable = 0;
        $partial = 0;
        $noAnswer = 0;
        $perBlock = [];

        for ($i = 0; $i < $blockCount; $i++) {
            // Ranked order is kept apart from the sorted set: the first
            // suggestion is the one a user sees, and sorting to compare sets
            // would silently redefine "first" as "lowest attachment id".
            $ranked = [];
            $sets = [];
            foreach ($names as $name) {
                $ids = array_values(array_map('intval', $models[$name][$i]['suggestions'] ?? []));
                $ranked[$name] = $ids;
                sort($ids);
                $sets[$name] = $ids;
            }

            $answered = array_filter($sets, static fn ($s) => $s !== []);
            $answeredCount = \count($answered);

            $entry = ['block' => $i, 'answered_by' => $answeredCount, 'sets' => $ranked];

            if ($answeredCount === 0) {
                $noAnswer++;
                $entry['status'] = 'no_answer';
                $perBlock[] = $entry;
                continue;
            }

            if ($answeredCount < \count($names)) {
                $partial++;
                $entry['status'] = 'partial';
                $perBlock[] = $entry;
                continue;
            }

            $comparable++;
            $entry['status'] = 'comparable';

            $signatures = array_map(static fn ($s) => implode(',', $s), $sets);
            $distinct = \count(array_unique($signatures));
            $entry['distinct_sets'] = $distinct;

            $firsts = array_map(static fn ($s) => $s[0], $ranked);
            $entry['same_first_pick'] = \count(array_unique($firsts)) === 1;

            if ($distinct === 1) {
                $unanimous++;
            }
            if ($distinct === \count($names)) {
                $fullySplit++;
            }
            if ($entry['same_first_pick']) {
                $sameFirst++;
            }

            $perBlock[] = $entry;
        }

        return [
            'comparable' => $comparable,
            'partial' => $partial,
            'no_answer' => $noAnswer,
            'unanimous' => $unanimous,
            'fully_split' => $fullySplit,
            'same_first' => $sameFirst,
            'per_block' => $perBlock,
        ];
    }
}
