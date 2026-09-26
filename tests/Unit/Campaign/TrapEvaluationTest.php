<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\RunResult;
use AIForge\Campaign\TrapChecker;
use AIForge\Campaign\TrapEvaluation;
use AIForge\DevTools\Tests\Unit\TestCase;
use InvalidArgumentException;

class TrapEvaluationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/trap-eval-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/hard-01.md", "# T\n");
        file_put_contents("{$this->dir}/manifest.json", json_encode(['files' => [[
            'id' => 'hard-01', 'author' => 'gemini', 'traps' => ['T1'], 'figures' => [],
        ]]]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private static function artifacts(): array
    {
        return [
            'markdown' => "# T\n",
            'template' => '<p class="is-style-stat-value">1</p>',
            'output' => '<p class="is-style-stat-value">100%</p>',
            'plan' => "MAPPING\n[intro] → [0] hero\n\nNOTES\nkeep it short\n",
        ];
    }

    public function testEvaluatesScoredRunsAgainstTheirFile(): void
    {
        $runs = [['task_id' => 5, 'root_id' => 1, 'combo' => 'g:b:l', 'file' => 'hard-01', 'scored' => true]];

        $report = TrapEvaluation::evaluate(CorpusManifest::load($this->dir), $runs, static fn (int $id): array => self::artifacts());
        $row = $report->rows[0];

        $this->assertSame('gemini', $row['author']);
        $this->assertSame(['T1'], $row['traps']);
        $this->assertSame(TrapChecker::FAIL, $row['checks']['stat_grounding']['status']);
        $this->assertSame('[intro] → [0] hero', $row['mapping']);
        $this->assertSame('keep it short', $row['notes']);
    }

    public function testUnscoredUnknownOrUnloadableRunsAreNotEvaluated(): void
    {
        $runs = [
            ['task_id' => 5, 'root_id' => 1, 'combo' => 'c', 'file' => 'hard-01', 'scored' => false],
            ['task_id' => 6, 'root_id' => 1, 'combo' => 'c', 'file' => 'nope', 'scored' => true],
            ['task_id' => 7, 'root_id' => 1, 'combo' => 'c', 'file' => null, 'scored' => true],
            ['task_id' => 8, 'root_id' => 1, 'combo' => 'c', 'file' => 'hard-01', 'scored' => true],
        ];
        $loaded = [];

        $report = TrapEvaluation::evaluate(CorpusManifest::load($this->dir), $runs, static function (int $id) use (&$loaded): ?array {
            $loaded[] = $id;
            return null;
        });

        $this->assertSame([5, 6, 7, 8], $report->notEvaluated());
        $this->assertSame([8], $loaded);
    }

    public function testRunsFromResults(): void
    {
        $results = [
            new RunResult(5, 'g:b:l', 'm', 'pass', 90, [], 0.1, false, 1, 'hard-01'),
            new RunResult(6, 'g:b:l', 'm', 'unknown', 0, [], 0.0, true, 1, 'hard-02'),
        ];

        $this->assertSame([
            ['task_id' => 5, 'root_id' => 1, 'combo' => 'g:b:l', 'file' => 'hard-01', 'scored' => true],
            ['task_id' => 6, 'root_id' => 1, 'combo' => 'g:b:l', 'file' => 'hard-02', 'scored' => false],
        ], TrapEvaluation::runsFromResults($results));
    }

    public function testRunsFromReport(): void
    {
        $report = ['combos' => ['g:b:l' => ['runs' => [
            ['task_id' => 5, 'root_id' => 1, 'file' => 'hard-01', 'verdict' => 'pass', 'failed' => false],
            ['task_id' => 6, 'root_id' => 1, 'file' => 'hard-02', 'verdict' => 'unknown', 'failed' => true],
        ]]]];

        $runs = TrapEvaluation::runsFromReport($report);

        $this->assertTrue($runs[0]['scored']);
        $this->assertFalse($runs[1]['scored']);
        $this->assertSame('hard-02', $runs[1]['file']);
    }

    public function testRunsFromAnOldReportAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('task 5');

        TrapEvaluation::runsFromReport(['combos' => ['g:b:l' => ['runs' => [['task_id' => 5, 'verdict' => 'pass']]]]]);
    }

    public function testTheRefusalNamesTheReport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('old.json');

        TrapEvaluation::runsFromReport(['combos' => ['c' => ['runs' => [['task_id' => 5, 'verdict' => 'pass']]]]], 'old.json');
    }

    public function testAMixedReportKeepsItsIncompleteRunsAsNotEvaluated(): void
    {
        $report = ['combos' => ['g:b:l' => ['runs' => [
            ['task_id' => 5, 'root_id' => 1, 'file' => 'hard-01', 'verdict' => 'pass', 'failed' => false],
            ['task_id' => 6, 'root_id' => 1, 'verdict' => 'pass', 'failed' => false],
            ['task_id' => 7, 'file' => 'hard-01', 'verdict' => 'pass', 'failed' => false],
        ]]]];

        $runs = TrapEvaluation::runsFromReport($report);

        $this->assertSame([5, 6, 7], array_column($runs, 'task_id'));
        $this->assertSame([null, 0], [$runs[1]['file'], $runs[1]['root_id']]);
        $this->assertSame([null, 0], [$runs[2]['file'], $runs[2]['root_id']]);

        $loaded = [];
        $traps = TrapEvaluation::evaluate(CorpusManifest::load($this->dir), $runs, static function (int $id) use (&$loaded): array {
            $loaded[] = $id;
            return self::artifacts();
        });

        $this->assertSame([5], $loaded);
        $this->assertSame([6, 7], $traps->notEvaluated());
    }

    public function testAnEmptyReportHasNoRuns(): void
    {
        $this->assertSame([], TrapEvaluation::runsFromReport(['combos' => []]));
    }
}
