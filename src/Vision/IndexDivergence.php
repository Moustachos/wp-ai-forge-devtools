<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Compares media indexes produced by different vision models over one sample.
 *
 * Facets like `people_count` or `has_text` have an objective answer, so a model
 * standing alone against the others is very likely the one that is wrong. That
 * ranks models without any human pass, and points the human pass at the handful
 * of images where the answer is actually in doubt.
 */
final class IndexDivergence
{
    private const STOP = 'a an the of in on at to for with and or is are this that from by as its his her their it over into out up down near above under between showing featuring conveying creating captured shot view image photo photograph close scene';

    /**
     * @param array<string, array<string, array<string, mixed>>> $models Rows per model, keyed by attachment id
     * @param list<string>                                       $facets
     * @return array{compared: int, unanimous: int, split: int, undecided: int, minority: array<string, int>, disagreements: list<array{attachment_id: string, facet: string, answers: array<string, string>}>}
     */
    public static function compare(array $models, array $facets): array
    {
        $names = array_keys($models);
        $ids = self::comparableIds($models);

        $minority = array_fill_keys($names, 0);
        $unanimous = $split = $undecided = 0;
        $disagreements = [];

        foreach ($facets as $facet) {
            foreach ($ids as $id) {
                $answers = [];
                foreach ($names as $name) {
                    $answers[$name] = (string) ($models[$name][$id][$facet] ?? '');
                }

                $distinct = array_values(array_unique($answers));
                if (\count($distinct) === 1) {
                    $unanimous++;
                    continue;
                }

                $split++;
                $disagreements[] = ['attachment_id' => $id, 'facet' => $facet, 'answers' => $answers];

                // Counted by value rather than by array key: "0" and "1" become
                // integers as keys and never match their own string again.
                $majority = null;
                $best = 0;
                foreach ($distinct as $value) {
                    $held = \count(array_keys($answers, $value, true));
                    if ($held > $best) {
                        $best = $held;
                        $majority = $value;
                    }
                }

                if ($best === \count($names) - 1) {
                    $agreeing = array_keys($answers, $majority, true);
                    $minority[array_values(array_diff($names, $agreeing))[0]]++;
                } else {
                    $undecided++;
                }
            }
        }

        return [
            'compared' => \count($ids),
            'unanimous' => $unanimous,
            'split' => $split,
            'undecided' => $undecided,
            'minority' => $minority,
            'disagreements' => $disagreements,
        ];
    }

    /**
     * Pairs of images a model described the wrong way round.
     *
     * Two similar images make either description fit either one, so a swap is
     * only called when the match is mutual and each description fits its own
     * image far worse than its partner.
     *
     * @param array<string, array<string, array<string, mixed>>> $models
     * @return list<array{0: string, 1: string, 2: string}> [model, id, id]
     */
    public static function swaps(array $models, float $selfFitCeiling = 0.15): array
    {
        $names = array_keys($models);
        $ids = self::comparableIds($models);
        $swaps = [];

        foreach ($names as $name) {
            $mine = [];
            $reference = [];
            foreach ($ids as $id) {
                $mine[$id] = self::words((string) ($models[$name][$id]['description'] ?? ''));
                $ref = [];
                foreach ($names as $other) {
                    if ($other !== $name) {
                        $ref = array_merge($ref, self::words((string) ($models[$other][$id]['description'] ?? '')));
                    }
                }
                $reference[$id] = array_unique($ref);
            }

            $best = [];
            foreach ($ids as $id) {
                $bestId = $id;
                $bestScore = -1.0;
                foreach ($ids as $candidate) {
                    $score = self::jaccard($mine[$id], $reference[$candidate]);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestId = $candidate;
                    }
                }
                $best[$id] = $bestId;
            }

            foreach ($ids as $id) {
                $partner = $best[$id];
                if ($partner === $id || ($best[$partner] ?? null) !== $id || strcmp($id, $partner) > 0) {
                    continue;
                }
                if (self::jaccard($mine[$id], $reference[$id]) < $selfFitCeiling
                    && self::jaccard($mine[$partner], $reference[$partner]) < $selfFitCeiling) {
                    $swaps[] = [$name, $id, $partner];
                }
            }
        }

        return $swaps;
    }

    /**
     * One review case per disagreement, with the answers kept aside so the page
     * can ask the question without showing who said what.
     *
     * @param array<string, array<string, array<string, mixed>>> $models
     * @param list<string>                                       $facets
     * @return list<array{attachment_id: string, facet: string, options: list<string>, answers: array<string, string>}>
     */
    public static function cases(array $models, array $facets): array
    {
        $cases = [];

        foreach (self::compare($models, $facets)['disagreements'] as $d) {
            $options = array_values(array_unique(array_values($d['answers'])));
            sort($options);

            $cases[] = [
                'attachment_id' => $d['attachment_id'],
                'facet' => $d['facet'],
                'options' => $options,
                'answers' => $d['answers'],
            ];
        }

        return $cases;
    }

    /**
     * Images every model actually indexed. A failed row has no facets, so left
     * in it would read as a disagreement with everyone on everything.
     *
     * @param array<string, array<string, array<string, mixed>>> $models
     * @return list<string>
     */
    public static function comparableIds(array $models): array
    {
        $names = array_keys($models);
        $ids = array_keys($models[$names[0]] ?? []);

        $ids = array_values(array_filter($ids, static function ($id) use ($models, $names) {
            foreach ($names as $name) {
                $row = $models[$name][$id] ?? null;
                if ($row === null || ($row['status'] ?? '') !== 'indexed' || ($row['description'] ?? '') === '') {
                    return false;
                }
            }

            return true;
        }));

        usort($ids, static fn ($a, $b) => (int) $a <=> (int) $b);

        return array_map('strval', $ids);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $union = \count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : \count(array_intersect($a, $b)) / $union;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        static $stop = null;
        $stop ??= array_flip(explode(' ', self::STOP));

        preg_match_all('/[a-z]+/', strtolower($text), $m);

        return array_values(array_unique(array_filter(
            $m[0],
            static fn ($w) => \strlen($w) > 2 && !isset($stop[$w])
        )));
    }
}
