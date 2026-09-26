<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Trap check outcomes of a campaign, tallied as k/n. Never percentages:
 * twelve files do not make a rate.
 */
final class TrapReport
{
    /**
     * @param array<int, array{task_id: int, root_id: int, combo: string, file: string, author: string, traps: string[], checks: ?array<string, array{status: string, findings: string[]}>, mapping: string, notes: string}> $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {
    }

    /**
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function byCombo(): array
    {
        return $this->tally(static fn (array $row): string => $row['combo']);
    }

    /**
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function byRepeat(): array
    {
        return $this->tally(static fn (array $row): string => $row['combo'] . ' #' . $row['root_id']);
    }

    /**
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function byAuthor(): array
    {
        return $this->tally(static fn (array $row): string => $row['author']);
    }

    /**
     * Each trap check counted on the files written to carry its trap only.
     *
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    public function onTrapFiles(): array
    {
        return $this->tally(
            static fn (array $row): string => $row['combo'],
            static fn (array $row, string $check): bool => isset(TrapChecker::TRAP_OF_CHECK[$check])
                && \in_array(TrapChecker::TRAP_OF_CHECK[$check], $row['traps'], true)
        );
    }

    /**
     * @return int[]
     */
    public function notEvaluated(): array
    {
        return array_values(array_map(
            static fn (array $row): int => $row['task_id'],
            array_filter($this->rows, static fn (array $row): bool => $row['checks'] === null)
        ));
    }

    /**
     * @return array<int, array{task_id: int, file: string, check: string, findings: string[], mapping: string, notes: string}>
     */
    public function failures(): array
    {
        $failures = [];

        foreach ($this->rows as $row) {
            foreach ($row['checks'] ?? [] as $check => $outcome) {
                if ($outcome['status'] === TrapChecker::FAIL) {
                    $failures[] = [
                        'task_id' => $row['task_id'],
                        'file' => $row['file'],
                        'check' => $check,
                        'findings' => $outcome['findings'],
                        'mapping' => $row['mapping'],
                        'notes' => $row['notes'],
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array{pass: int, fail: int, na: int} $tally
     */
    public static function cell(array $tally): string
    {
        $n = $tally['pass'] + $tally['fail'];

        return $n === 0 ? 'n/a' : "{$tally['pass']}/{$n}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'by_combo' => self::cells($this->byCombo()),
            'on_trap_files' => self::cells($this->onTrapFiles()),
            'by_repeat' => self::cells($this->byRepeat()),
            'by_author' => self::cells($this->byAuthor()),
            'not_evaluated' => $this->notEvaluated(),
            'failures' => $this->failures(),
            'rows' => $this->rows,
        ];
    }

    public function toMarkdown(): string
    {
        $lines = ['### Trap checks (k/n; stat_has_figure measures model + addendum)', ''];

        foreach (['All files' => $this->byCombo(), 'On each trap\'s own files' => $this->onTrapFiles(), 'By repeat' => $this->byRepeat(), 'By author' => $this->byAuthor()] as $title => $groups) {
            $lines[] = "#### {$title}";
            $lines[] = '';
            $lines[] = '| group | ' . implode(' | ', TrapChecker::CHECKS) . ' |';
            $lines[] = '|---' . str_repeat('|---', \count(TrapChecker::CHECKS)) . '|';

            foreach ($groups as $group => $checks) {
                $cells = array_map(
                    static fn (string $check): string => isset($checks[$check]) ? self::cell($checks[$check]) : '-',
                    TrapChecker::CHECKS
                );
                $lines[] = "| {$group} | " . implode(' | ', $cells) . ' |';
            }

            $lines[] = '';
        }

        $byCheck = [];

        foreach ($this->failures() as $failure) {
            $byCheck[$failure['check']][] = $failure['task_id'];
        }

        $lines[] = 'Failed checks (task ids; findings, MAPPING and NOTES are in the JSON):';

        foreach ($byCheck as $check => $ids) {
            $lines[] = "- {$check}: " . implode(', ', array_unique($ids));
        }

        if ($this->notEvaluated() !== []) {
            $lines[] = 'Not evaluated: ' . implode(', ', $this->notEvaluated());
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param callable(array): string $groupOf
     * @param (callable(array, string): bool)|null $counts
     * @return array<string, array<string, array{pass: int, fail: int, na: int}>>
     */
    private function tally(callable $groupOf, ?callable $counts = null): array
    {
        $tallies = [];

        foreach ($this->rows as $row) {
            if ($row['checks'] === null) {
                continue;
            }

            $group = $groupOf($row);

            foreach (TrapChecker::CHECKS as $check) {
                if ($counts !== null && !$counts($row, $check)) {
                    continue;
                }

                $tallies[$group][$check] ??= ['pass' => 0, 'fail' => 0, 'na' => 0];
                $status = $row['checks'][$check]['status'] ?? TrapChecker::NA;
                $key = match ($status) {
                    TrapChecker::PASS => 'pass',
                    TrapChecker::FAIL => 'fail',
                    default => 'na',
                };
                $tallies[$group][$check][$key]++;
            }
        }

        return $tallies;
    }

    /**
     * @param array<string, array<string, array{pass: int, fail: int, na: int}>> $groups
     * @return array<string, array<string, string>>
     */
    private static function cells(array $groups): array
    {
        return array_map(
            static fn (array $checks): array => array_map(self::cell(...), $checks),
            $groups
        );
    }
}
