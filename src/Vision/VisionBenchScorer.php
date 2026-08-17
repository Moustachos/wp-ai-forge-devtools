<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Turns search results into comparable numbers for a vision benchmark.
 *
 * The benchmark asks one question of every indexing model: with this index in
 * place, does the image a human expected actually come back for the query they
 * wrote? Ranking is the measure, because it needs no judgement about what a
 * "good" description reads like.
 *
 * Queries with an empty `expect` list are traps: nothing in the sample answers
 * them, so anything returned is a false positive. They are scored apart from
 * the recall rates, otherwise a model that answers everything would look good
 * simply for never staying silent.
 */
final class VisionBenchScorer
{
    /**
     * Rank standing in for a miss in the rank series. Larger than any sample
     * this bench runs on, so a miss always sorts last without pretending to be
     * a measured position.
     */
    public const MISS_RANK = 9999;

    /**
     * Best (lowest) 1-based position among the accepted answers, null if none
     * of them came back at all.
     *
     * @param list<int> $expected
     * @param list<int> $results
     */
    public static function rank(array $expected, array $results): ?int
    {
        $best = null;

        foreach ($expected as $id) {
            $pos = array_search($id, $results, true);
            if ($pos === false) {
                continue;
            }
            $rank = (int) $pos + 1;
            if ($best === null || $rank < $best) {
                $best = $rank;
            }
        }

        return $best;
    }

    /**
     * @param list<array{query: string, expect: list<int>}> $queries
     * @param array<string, list<int>>                      $resultsByQuery
     * @return array<string, int|float|list<array<string, mixed>>>
     */
    public static function score(array $queries, array $resultsByQuery): array
    {
        $answerable = 0;
        $top1 = 0;
        $top3 = 0;
        $top5 = 0;
        $top10 = 0;
        $misses = 0;
        $traps = 0;
        $rankSeries = [];
        $falsePositives = 0;
        $reciprocal = 0.0;
        $detail = [];

        foreach ($queries as $q) {
            $query = (string) ($q['query'] ?? '');
            $expect = array_values(array_map('intval', $q['expect'] ?? []));
            $results = array_values(array_map('intval', $resultsByQuery[$query] ?? []));

            if ($expect === []) {
                $traps++;
                $returned = \count($results);
                if ($returned > 0) {
                    $falsePositives++;
                }
                $detail[] = ['query' => $query, 'trap' => true, 'returned' => $returned];
                continue;
            }

            $answerable++;
            $rank = self::rank($expect, $results);

            // A miss enters the rank series as worse than anything observed,
            // so the median cannot be flattered by dropping the failures.
            $rankSeries[] = $rank ?? self::MISS_RANK;

            if ($rank === null) {
                $misses++;
            } else {
                $reciprocal += 1 / $rank;
                if ($rank === 1) {
                    $top1++;
                }
                if ($rank <= 3) {
                    $top3++;
                }
                if ($rank <= 5) {
                    $top5++;
                }
                if ($rank <= 10) {
                    $top10++;
                }
            }

            $detail[] = ['query' => $query, 'trap' => false, 'rank' => $rank, 'returned' => \count($results)];
        }

        sort($rankSeries);
        $n = \count($rankSeries);
        $median = 0.0;
        if ($n > 0) {
            $median = $n % 2 === 1
                ? (float) $rankSeries[intdiv($n, 2)]
                : (float) (($rankSeries[$n / 2 - 1] + $rankSeries[$n / 2]) / 2);
        }

        return [
            'answerable' => $answerable,
            'top1' => $top1,
            'top3' => $top3,
            'top5' => $top5,
            'top10' => $top10,
            'median_rank' => $median,
            'misses' => $misses,
            'traps' => $traps,
            'false_positives' => $falsePositives,
            'top1_rate' => self::pct($top1, $answerable),
            'top3_rate' => self::pct($top3, $answerable),
            'mrr' => $answerable > 0 ? round($reciprocal / $answerable, 4) : 0.0,
            'detail' => $detail,
        ];
    }

    private static function pct(int $n, int $total): float
    {
        return $total > 0 ? round(100 * $n / $total, 1) : 0.0;
    }
}
