<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\TrapChecker;
use AIForge\Campaign\TrapReport;
use AIForge\DevTools\Tests\Unit\TestCase;

class TrapReportTest extends TestCase
{
    private static function checks(array $statuses): array
    {
        $checks = [];

        foreach (TrapChecker::CHECKS as $check) {
            $status = $statuses[$check] ?? TrapChecker::PASS;
            $checks[$check] = ['status' => $status, 'findings' => $status === TrapChecker::FAIL ? ["{$check} finding"] : []];
        }

        return $checks;
    }

    private static function row(int $task, int $root, string $combo, string $file, array $traps, ?array $checks, string $author = 'claude'): array
    {
        return ['task_id' => $task, 'root_id' => $root, 'combo' => $combo, 'file' => $file, 'author' => $author, 'traps' => $traps, 'checks' => $checks, 'mapping' => "map {$task}", 'notes' => "notes {$task}"];
    }

    private function report(): TrapReport
    {
        return new TrapReport([
            self::row(1, 10, 'g:b:l', 'hard-01', ['T1'], self::checks(['stat_grounding' => TrapChecker::FAIL])),
            self::row(2, 10, 'g:b:l', 'hard-02', ['T2'], self::checks(['card_count' => TrapChecker::NA])),
            self::row(3, 11, 'g:b:l', 'hard-01', ['T1'], self::checks([]), 'gemini'),
            self::row(4, 11, 'g:b:l', 'hard-02', ['T2'], null),
        ]);
    }

    public function testTalliesByCombo(): void
    {
        $tally = $this->report()->byCombo()['g:b:l'];

        $this->assertSame(['pass' => 2, 'fail' => 1, 'na' => 0], $tally['stat_grounding']);
        $this->assertSame(['pass' => 2, 'fail' => 0, 'na' => 1], $tally['card_count']);
    }

    public function testTalliesByRepeat(): void
    {
        $byRepeat = $this->report()->byRepeat();

        $this->assertSame(['g:b:l #10', 'g:b:l #11'], array_keys($byRepeat));
        $this->assertSame(['pass' => 1, 'fail' => 0, 'na' => 0], $byRepeat['g:b:l #11']['stat_grounding']);
    }

    public function testOnTrapFilesCountsOnlyTheTrapsOwnFiles(): void
    {
        $tally = $this->report()->onTrapFiles()['g:b:l'];

        // stat_grounding belongs to T1: only rows 1 and 3.
        $this->assertSame(['pass' => 1, 'fail' => 1, 'na' => 0], $tally['stat_grounding']);
        // testimonial_grounding belongs to T2: only row 2 (row 4 is not evaluated).
        $this->assertSame(['pass' => 1, 'fail' => 0, 'na' => 0], $tally['testimonial_grounding']);
        $this->assertArrayNotHasKey('orphan_headings', $tally);
    }

    public function testByAuthor(): void
    {
        $this->assertSame(['claude', 'gemini'], array_keys($this->report()->byAuthor()));
    }

    public function testNotEvaluatedAndFailures(): void
    {
        $report = $this->report();

        $this->assertSame([4], $report->notEvaluated());
        $this->assertSame(
            [['task_id' => 1, 'file' => 'hard-01', 'check' => 'stat_grounding', 'findings' => ['stat_grounding finding'], 'mapping' => 'map 1', 'notes' => 'notes 1']],
            $report->failures()
        );
    }

    public function testCell(): void
    {
        $this->assertSame('2/3', TrapReport::cell(['pass' => 2, 'fail' => 1, 'na' => 4]));
        $this->assertSame('n/a', TrapReport::cell(['pass' => 0, 'fail' => 0, 'na' => 4]));
    }

    public function testMarkdownShowsKOverNAndTaskIdsOnly(): void
    {
        $markdown = $this->report()->toMarkdown();

        $this->assertStringContainsString('| g:b:l | 2/3 |', $markdown);
        $this->assertStringContainsString('stat_grounding: 1', $markdown);
        $this->assertStringContainsString('Not evaluated: 4', $markdown);
        $this->assertStringNotContainsString('map 1', $markdown);
        $this->assertStringNotContainsString('%', $markdown);
    }

    public function testArrayCarriesThePlanNextToFailures(): void
    {
        $array = $this->report()->toArray();

        $this->assertSame('map 1', $array['failures'][0]['mapping']);
        $this->assertSame([4], $array['not_evaluated']);
        $this->assertSame('1/2', $array['on_trap_files']['g:b:l']['stat_grounding']);
        $this->assertSame('2/3', $array['by_combo']['g:b:l']['stat_grounding']);
    }
}
