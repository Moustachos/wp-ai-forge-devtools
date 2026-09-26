<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\BaselineComparator;
use AIForge\DevTools\Tests\Unit\TestCase;

class BaselineComparatorTest extends TestCase
{
    private function report(array $combos): array
    {
        return ['combos' => $combos, 'totals' => []];
    }

    private function combo(float $publishable, float $global, float $signature): array
    {
        return [
            'run_count' => 2,
            'publishable_rate' => $publishable,
            'mean_global' => $global,
            'mean_signature' => $signature,
            'total_cost' => 0.2,
            'runs' => [],
        ];
    }

    public function testIdenticalReportsAreStable(): void
    {
        $report = $this->report(['gemini:balanced:showcase' => $this->combo(100.0, 95.0, 90.0)]);

        $rows = BaselineComparator::compare($report, $report);

        $this->assertCount(1, $rows);
        $this->assertSame(BaselineComparator::STATUS_STABLE, $rows[0]['status']);
        $this->assertSame(0.0, $rows[0]['global_delta']);
        $this->assertSame(0.0, $rows[0]['publishable_delta']);
    }

    public function testDroppedPublishableRateIsARegression(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(50.0, 94.0, 89.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_REGRESSION, $rows[0]['status']);
        $this->assertSame(-50.0, $rows[0]['publishable_delta']);
    }

    public function testGlobalDropOfTwoOrMoreIsARegression(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 93.0, 90.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_REGRESSION, $rows[0]['status']);
        $this->assertSame(-2.0, $rows[0]['global_delta']);
    }

    public function testSmallGlobalDropIsStillStable(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 93.9, 90.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_STABLE, $rows[0]['status']);
    }

    public function testGlobalGainOfTwoOrMoreIsAnImprovement(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 90.0, 80.0)]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 85.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_IMPROVEMENT, $rows[0]['status']);
        $this->assertSame(5.0, $rows[0]['global_delta']);
        $this->assertSame(5.0, $rows[0]['signature_delta']);
    }

    public function testGlobalGainWithPublishableDropIsStillARegression(): void
    {
        // A higher mean with fewer publishable runs means the failures got worse, not better.
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 90.0, 80.0)]),
            $this->report(['a:balanced:x' => $this->combo(75.0, 96.0, 85.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_REGRESSION, $rows[0]['status']);
    }

    public function testComboOnlyInCurrentIsNew(): void
    {
        $rows = BaselineComparator::compare(
            $this->report([]),
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)])
        );

        $this->assertSame(BaselineComparator::STATUS_NEW, $rows[0]['status']);
        $this->assertSame(0.0, $rows[0]['global_delta']);
    }

    public function testComboOnlyInBaselineIsMissing(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report([])
        );

        $this->assertSame(BaselineComparator::STATUS_MISSING, $rows[0]['status']);
    }

    public function testRowsCoverBothReportsWithoutDuplicates(): void
    {
        $rows = BaselineComparator::compare(
            $this->report([
                'a:balanced:x' => $this->combo(100.0, 95.0, 90.0),
                'b:balanced:y' => $this->combo(100.0, 95.0, 90.0),
            ]),
            $this->report([
                'b:balanced:y' => $this->combo(100.0, 95.0, 90.0),
                'c:balanced:z' => $this->combo(100.0, 95.0, 90.0),
            ])
        );

        $combos = array_column($rows, 'combo');

        $this->assertSame(['a:balanced:x', 'b:balanced:y', 'c:balanced:z'], $combos);
    }

    public function testMissingCombosKeyIsTreatedAsEmpty(): void
    {
        $rows = BaselineComparator::compare([], ['combos' => ['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]]);

        $this->assertCount(1, $rows);
        $this->assertSame(BaselineComparator::STATUS_NEW, $rows[0]['status']);
    }

    public function testMarkdownRendersEveryRow(): void
    {
        $rows = BaselineComparator::compare(
            $this->report(['a:balanced:x' => $this->combo(100.0, 95.0, 90.0)]),
            $this->report(['a:balanced:x' => $this->combo(50.0, 80.0, 70.0)])
        );

        $markdown = BaselineComparator::toMarkdown($rows);

        $this->assertStringContainsString('a:balanced:x', $markdown);
        $this->assertStringContainsString('regression', $markdown);
        $this->assertStringContainsString('-15.0', $markdown);
    }

    public function testRefusesToCompareDifferentCorpora(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('corpus hard with one on source batch 6223');

        BaselineComparator::compare(['corpus' => 'hard', 'combos' => []], ['corpus' => null, 'source_batch' => 6223, 'combos' => []]);
    }

    public function testComparesTwoReportsOnTheSameCorpus(): void
    {
        $this->assertSame([], BaselineComparator::compare(['corpus' => 'hard', 'combos' => []], ['corpus' => 'hard', 'combos' => []]));
    }
}
