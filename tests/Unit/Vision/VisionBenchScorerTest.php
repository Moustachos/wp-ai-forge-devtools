<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Vision;

use AIForge\DevTools\Tests\Unit\TestCase;
use AIForge\Vision\VisionBenchScorer;

class VisionBenchScorerTest extends TestCase
{
    public function testRanksTheFirstExpectedAttachmentInTheResultList(): void
    {
        $this->assertSame(1, VisionBenchScorer::rank([10], [10, 20, 30]));
        $this->assertSame(3, VisionBenchScorer::rank([30], [10, 20, 30]));
        $this->assertSame(2, VisionBenchScorer::rank([99, 20], [10, 20, 30]), 'best rank among several accepted answers');
        $this->assertNull(VisionBenchScorer::rank([77], [10, 20, 30]), 'absent from results');
    }

    public function testScoresTopOneAndTopThreeRates(): void
    {
        $queries = [
            ['query' => 'a', 'expect' => [10]],
            ['query' => 'b', 'expect' => [20]],
            ['query' => 'c', 'expect' => [30]],
            ['query' => 'd', 'expect' => [40]],
        ];
        $results = [
            'a' => [10, 11, 12],   // rank 1
            'b' => [11, 20, 12],   // rank 2
            'c' => [11, 12, 13, 30], // rank 4
            'd' => [11, 12, 13],   // miss
        ];

        $score = VisionBenchScorer::score($queries, $results);

        $this->assertSame(4, $score['answerable']);
        $this->assertSame(1, $score['top1']);
        $this->assertSame(2, $score['top3']);
        $this->assertSame(1, $score['misses']);
        $this->assertSame(25.0, $score['top1_rate']);
        $this->assertSame(50.0, $score['top3_rate']);
    }

    public function testPrecisionSeparatesTwoEnginesThatRankTheSame(): void
    {
        // The case ranking cannot see: both put the wanted image first, one
        // hands back two results and the other ten.
        $queries = [['query' => 'a', 'expect' => [10, 11]]];

        $tight = VisionBenchScorer::score($queries, ['a' => [10, 11]]);
        $loose = VisionBenchScorer::score($queries, ['a' => [10, 11, 20, 21, 22, 23, 24, 25, 26, 27]]);

        $this->assertSame(1, $tight['top1']);
        $this->assertSame(1, $loose['top1']);
        $this->assertSame($tight['mrr'], $loose['mrr'], 'ranking cannot tell them apart');

        $this->assertSame(1.0, $tight['precision']);
        $this->assertSame(0.2, $loose['precision']);
        $this->assertSame(0, $tight['noise_total']);
        $this->assertSame(8, $loose['noise_total'], 'rows the user had to look past');
    }

    public function testPrecisionIsAveragedPerQueryAndSkipsEmptyResults(): void
    {
        // A query that returned nothing has no precision to speak of; counting
        // it as zero would punish silence twice, once here and once in misses.
        $queries = [
            ['query' => 'a', 'expect' => [10]],
            ['query' => 'b', 'expect' => [20]],
        ];

        $score = VisionBenchScorer::score($queries, ['a' => [10, 30], 'b' => []]);

        $this->assertSame(0.5, $score['precision'], 'only the query that returned anything counts');
        $this->assertSame(1, $score['misses']);
    }

    public function testShownSetIsMeasuredOnAnswerableQueriesOnly(): void
    {
        $queries = [
            ['query' => 'a', 'expect' => [10]],
            ['query' => 'b', 'expect' => [20]],
            ['query' => 'trap', 'type' => 'piege', 'expect' => []],
        ];

        $score = VisionBenchScorer::score($queries, [
            'a' => [10, 30, 31],
            'b' => [20],
            'trap' => [40, 41, 42, 43, 44, 45, 46, 47, 48],
        ]);

        $this->assertSame(4, $score['shown_total'], 'the trap is scored as a false positive, not as volume');
        $this->assertSame(2.0, $score['shown_median']);
        $this->assertSame(3, $score['shown_max']);
        $this->assertSame(1, $score['false_positives']);
    }

    public function testMeanReciprocalRankRewardsHigherPositions(): void
    {
        $queries = [
            ['query' => 'a', 'expect' => [10]],
            ['query' => 'b', 'expect' => [20]],
        ];

        // ranks 1 and 2 -> (1 + 0.5) / 2
        $score = VisionBenchScorer::score($queries, ['a' => [10], 'b' => [11, 20]]);
        $this->assertSame(0.75, $score['mrr']);

        // a miss contributes zero
        $score = VisionBenchScorer::score($queries, ['a' => [10], 'b' => [11, 12]]);
        $this->assertSame(0.5, $score['mrr']);
    }

    public function testTreatsQueriesWithNoExpectedImageAsPrecisionTraps(): void
    {
        $queries = [
            ['query' => 'nothing matches this', 'expect' => []],
            ['query' => 'nor this', 'expect' => []],
            ['query' => 'real one', 'expect' => [10]],
        ];
        $results = [
            'nothing matches this' => [],       // correctly empty
            'nor this' => [11, 12],             // false positive
            'real one' => [10],
        ];

        $score = VisionBenchScorer::score($queries, $results);

        $this->assertSame(2, $score['traps']);
        $this->assertSame(1, $score['false_positives']);
        $this->assertSame(1, $score['answerable'], 'traps are not counted as answerable queries');
        $this->assertSame(100.0, $score['top1_rate'], 'traps must not dilute the recall rate');
    }

    public function testMissingResultEntryCountsAsAMiss(): void
    {
        $queries = [['query' => 'a', 'expect' => [10]]];

        $score = VisionBenchScorer::score($queries, []);

        $this->assertSame(1, $score['misses']);
        $this->assertSame(0.0, $score['top1_rate']);
    }

    public function testReportsMedianRankAndTopFive(): void
    {
        $queries = [
            ['query' => 'a', 'expect' => [10]],
            ['query' => 'b', 'expect' => [20]],
            ['query' => 'c', 'expect' => [30]],
            ['query' => 'd', 'expect' => [40]],
        ];
        $results = [
            'a' => [10],                          // rank 1
            'b' => [1, 2, 20],                    // rank 3
            'c' => [1, 2, 3, 4, 5, 6, 30],        // rank 7
            'd' => [1, 2],                        // miss
        ];

        $score = VisionBenchScorer::score($queries, $results);

        // ranks 1, 3, 7 and a miss: the miss must not be dropped, it is the
        // worst possible outcome and has to weigh as such. Median of the four
        // sorted values is therefore (3 + 7) / 2.
        $this->assertSame(5.0, $score['median_rank'], 'median over 1, 3, 7, miss');
        $this->assertSame(2, $score['top5'], 'ranks 1 and 3');
        $this->assertSame(3, $score['top10'], 'ranks 1, 3 and 7');
    }

    public function testMedianRankIgnoresTrapQueries(): void
    {
        $queries = [
            ['query' => 'a', 'expect' => [10]],
            ['query' => 't', 'expect' => []],
        ];

        $score = VisionBenchScorer::score($queries, ['a' => [10], 't' => [1, 2, 3]]);

        $this->assertSame(1.0, $score['median_rank']);
    }
}
