<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CampaignReport;
use AIForge\Campaign\RunResult;
use AIForge\DevTools\Tests\Unit\TestCase;

class CampaignReportTest extends TestCase
{
    private function makeRun(
        int $taskId,
        string $comboKey,
        string $verdict,
        int $global,
        int $signature = 80,
        float $cost = 0.10,
        string $modelId = 'gemini-3.6-flash'
    ): RunResult {
        return new RunResult(
            taskId: $taskId,
            comboKey: $comboKey,
            modelId: $modelId,
            verdict: $verdict,
            globalScore: $global,
            subscores: [
                'completion' => 90,
                'signature' => $signature,
                'coverage' => 85,
                'volume' => 88,
                'leaks' => 100,
                'dups' => 95,
            ],
            cost: $cost,
        );
    }

    // --- RunResult ---

    public function testPassAndWarningsArePublishable(): void
    {
        $this->assertTrue($this->makeRun(1, 'a:b:c', 'pass', 95)->isPublishable());
        $this->assertTrue($this->makeRun(2, 'a:b:c', 'warnings', 85)->isPublishable());
    }

    public function testFailAndUnknownAreNotPublishable(): void
    {
        $this->assertFalse($this->makeRun(3, 'a:b:c', 'fail', 40)->isPublishable());
        $this->assertFalse($this->makeRun(4, 'a:b:c', 'unknown', 0)->isPublishable());
    }

    public function testSubscoreReturnsZeroForUnknownAxis(): void
    {
        $this->assertSame(0, $this->makeRun(5, 'a:b:c', 'pass', 95)->subscore('nonexistent'));
        $this->assertSame(80, $this->makeRun(6, 'a:b:c', 'pass', 95)->subscore('signature'));
    }

    // --- Per-combo aggregation ---

    public function testComboStatsComputePublishableRateAndMeans(): void
    {
        $report = new CampaignReport('test', 100, 2, [
            $this->makeRun(1, 'gemini:balanced:showcase', 'pass', 96, 92, 0.08),
            $this->makeRun(2, 'gemini:balanced:showcase', 'warnings', 88, 60, 0.08),
            $this->makeRun(3, 'gemini:balanced:showcase', 'fail', 40, 30, 0.08),
            $this->makeRun(4, 'gemini:balanced:showcase', 'pass', 92, 90, 0.08),
        ]);

        $stats = $report->comboStats('gemini:balanced:showcase');

        $this->assertSame(4, $stats['runs']);
        // 3 of 4 publishable
        $this->assertSame(75.0, $stats['publishable_rate']);
        // (96 + 88 + 40 + 92) / 4 = 79
        $this->assertSame(79.0, $stats['mean_global']);
        // (92 + 60 + 30 + 90) / 4 = 68
        $this->assertSame(68.0, $stats['mean_signature']);
        $this->assertSame(0.32, $stats['total_cost']);
    }

    public function testMeansAreRoundedToOneDecimal(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'a:b:c', 'pass', 95),
            $this->makeRun(2, 'a:b:c', 'pass', 96),
            $this->makeRun(3, 'a:b:c', 'pass', 98),
        ]);

        // (95 + 96 + 98) / 3 = 96.333...
        $this->assertSame(96.3, $report->comboStats('a:b:c')['mean_global']);
    }

    public function testUnknownComboReturnsZeroedStats(): void
    {
        $report = new CampaignReport('test', 100, 1, []);

        $stats = $report->comboStats('nope:nope:nope');

        $this->assertSame(0, $stats['runs']);
        $this->assertSame(0.0, $stats['publishable_rate']);
        $this->assertSame(0.0, $stats['mean_global']);
        $this->assertSame(0.0, $stats['mean_signature']);
        $this->assertSame(0.0, $stats['total_cost']);
    }

    public function testComboKeysPreserveInsertionOrder(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'z:balanced:one', 'pass', 90),
            $this->makeRun(2, 'a:balanced:two', 'pass', 90),
            $this->makeRun(3, 'z:balanced:one', 'pass', 90),
        ]);

        $this->assertSame(['z:balanced:one', 'a:balanced:two'], $report->comboKeys());
    }

    // --- Totals ---

    public function testTotalsAggregateAcrossCombos(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'gemini:balanced:showcase', 'pass', 100, 90, 0.05),
            $this->makeRun(2, 'openai:economic:manifesto', 'fail', 50, 40, 0.15),
        ]);

        $totals = $report->totals();

        $this->assertSame(2, $totals['runs']);
        $this->assertSame(50.0, $totals['publishable_rate']);
        $this->assertSame(75.0, $totals['mean_global']);
        $this->assertSame(65.0, $totals['mean_signature']);
        $this->assertSame(0.2, $totals['total_cost']);
    }

    public function testTotalsOnAnEmptyCampaignDoNotDivideByZero(): void
    {
        $totals = (new CampaignReport('test', 100, 1, []))->totals();

        $this->assertSame(0, $totals['runs']);
        $this->assertSame(0.0, $totals['publishable_rate']);
        $this->assertSame(0.0, $totals['mean_global']);
    }

    // --- Flagged runs ---

    public function testFlaggedTaskIdsListNonPublishableRuns(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(11, 'a:b:c', 'pass', 95),
            $this->makeRun(12, 'a:b:c', 'fail', 40),
            $this->makeRun(13, 'a:b:c', 'unknown', 0),
            $this->makeRun(14, 'a:b:c', 'warnings', 85),
        ]);

        // 13 has no verdict: that is a dead run, not a quality problem.
        $this->assertSame([12], $report->flaggedTaskIds());
        $this->assertSame([13], $report->failedTaskIds());
    }

    // --- Serialisation ---

    public function testToArrayCarriesHeaderCombosAndTotals(): void
    {
        $report = new CampaignReport('v038-revalidation', 6168, 2, [
            $this->makeRun(1, 'gemini:balanced:showcase', 'pass', 96, 92, 0.08),
        ]);

        $array = $report->toArray();

        $this->assertSame('v038-revalidation', $array['label']);
        $this->assertSame(6168, $array['source_batch']);
        $this->assertSame(2, $array['files_per_combo']);
        $this->assertArrayHasKey('gemini:balanced:showcase', $array['combos']);
        $this->assertSame(96, $array['combos']['gemini:balanced:showcase']['runs'][0]['global']);
        $this->assertSame(1, $array['combos']['gemini:balanced:showcase']['run_count']);
        $this->assertSame(1, $array['totals']['runs']);
    }

    public function testToArrayIsJsonSerialisable(): void
    {
        $report = new CampaignReport('test', 1, 1, [$this->makeRun(1, 'a:b:c', 'pass', 90)]);

        $json = json_encode($report->toArray());

        $this->assertIsString($json);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    // --- Technical failures must not be read as bad scores ---
    //
    // A run that never executed (provider 503, timeout) has no verdict and no
    // model. Averaging it as zero made three dead runs look like a model
    // collapsing to 0.0 signature, which is the opposite of what happened.

    private function failedRun(int $taskId, string $comboKey): RunResult
    {
        return new RunResult(
            taskId: $taskId,
            comboKey: $comboKey,
            modelId: 'unknown',
            verdict: 'unknown',
            globalScore: 0,
            subscores: [],
            cost: 0.0,
            failed: true,
        );
    }

    public function testFailedRunsAreCountedApartFromScoredOnes(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'a:b:c', 'pass', 96, 92),
            $this->failedRun(2, 'a:b:c'),
        ]);

        $stats = $report->comboStats('a:b:c');

        $this->assertSame(1, $stats['runs'], 'Only scored runs count as runs');
        $this->assertSame(1, $stats['failed']);
    }

    public function testFailedRunsDoNotDragTheAveragesDown(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'a:b:c', 'pass', 96, 92),
            $this->failedRun(2, 'a:b:c'),
        ]);

        $stats = $report->comboStats('a:b:c');

        // Averaging the dead run in would give 48.0 and 46.0.
        $this->assertSame(96.0, $stats['mean_global']);
        $this->assertSame(92.0, $stats['mean_signature']);
        $this->assertSame(100.0, $stats['publishable_rate']);
    }

    public function testAllRunsFailedReportsZeroScoredNotZeroQuality(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->failedRun(1, 'a:b:c'),
            $this->failedRun(2, 'a:b:c'),
        ]);

        $stats = $report->comboStats('a:b:c');

        $this->assertSame(0, $stats['runs']);
        $this->assertSame(2, $stats['failed']);
        $this->assertSame(0.0, $stats['publishable_rate']);
    }

    public function testFailedRunsAreListedForFollowUp(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'a:b:c', 'pass', 96),
            $this->failedRun(2, 'a:b:c'),
        ]);

        $this->assertSame([2], $report->failedTaskIds());
        $this->assertSame([], $report->flaggedTaskIds(), 'A dead run is not a quality flag');
    }

    public function testMarkdownSurfacesFailuresExplicitly(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(1, 'a:b:c', 'pass', 96),
            $this->failedRun(2, 'a:b:c'),
        ]);

        $markdown = $report->toMarkdown();

        $this->assertStringContainsString('failed', $markdown);
    }

    public function testMarkdownContainsComboRowsAndTotals(): void
    {
        $report = new CampaignReport('test', 100, 1, [
            $this->makeRun(4251, 'openai:balanced:case-study', 'fail', 69, 26, 0.27, 'gpt-5.6-sol'),
        ]);

        $markdown = $report->toMarkdown();

        $this->assertStringContainsString('openai:balanced:case-study', $markdown);
        $this->assertStringContainsString('4251', $markdown);
        $this->assertStringContainsString('gpt-5.6-sol', $markdown);
        $this->assertStringContainsString('fail', $markdown);
        $this->assertStringContainsString('TOTAL', $markdown);
    }
}
