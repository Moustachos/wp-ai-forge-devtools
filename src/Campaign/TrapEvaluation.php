<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use InvalidArgumentException;

/**
 * Runs TrapChecker over a campaign's runs. The loader is injected so the
 * campaign (right after collection) and trap-check (offline, from a report)
 * share one path, and so this stays testable without a database.
 */
final class TrapEvaluation
{
    /**
     * @param array<int, array{task_id: int, root_id: int, combo: string, file: ?string, scored: bool}> $runs
     * @param callable(int): ?array{markdown: string, template: string, output: string, plan: string} $load
     */
    public static function evaluate(CorpusManifest $manifest, array $runs, callable $load): TrapReport
    {
        $checker = new TrapChecker($manifest->settings);
        $rows = [];

        foreach ($runs as $run) {
            $entry = $run['file'] !== null ? $manifest->entry($run['file']) : null;
            $artifacts = ($run['scored'] && $entry !== null) ? $load($run['task_id']) : null;
            $plan = PlanExcerpt::extract($artifacts['plan'] ?? '');

            $rows[] = [
                'task_id' => $run['task_id'],
                'root_id' => $run['root_id'],
                'combo' => $run['combo'],
                'file' => $run['file'] ?? '(unknown)',
                'author' => $entry?->author ?? '(unknown)',
                'traps' => $entry?->traps ?? [],
                'checks' => $artifacts === null
                    ? null
                    : $checker->check($entry, $artifacts['markdown'], $artifacts['template'], $artifacts['output']),
                'mapping' => $plan['mapping'],
                'notes' => $plan['notes'],
            ];
        }

        return new TrapReport($rows);
    }

    /**
     * @param RunResult[] $results
     * @return array<int, array{task_id: int, root_id: int, combo: string, file: ?string, scored: bool}>
     */
    public static function runsFromResults(array $results): array
    {
        return array_map(static fn (RunResult $run): array => [
            'task_id' => $run->taskId,
            'root_id' => (int) $run->rootId,
            'combo' => $run->comboKey,
            'file' => $run->sourceFile,
            'scored' => $run->isScored(),
        ], array_values($results));
    }

    /**
     * A run missing its root_id or file is kept but never evaluated; only a
     * report where no run carries both is refused.
     *
     * @param array<string, mixed> $report
     * @return array<int, array{task_id: int, root_id: int, combo: string, file: ?string, scored: bool}>
     */
    public static function runsFromReport(array $report, string $name = 'this report'): array
    {
        $runs = [];
        $complete = false;

        foreach ((array) ($report['combos'] ?? []) as $combo => $data) {
            foreach ((array) ($data['runs'] ?? []) as $run) {
                $identified = isset($run['root_id'], $run['file']);
                $complete = $complete || $identified;

                $runs[] = [
                    'task_id' => (int) ($run['task_id'] ?? 0),
                    'root_id' => $identified ? (int) $run['root_id'] : 0,
                    'combo' => (string) $combo,
                    'file' => $identified ? (string) $run['file'] : null,
                    'scored' => empty($run['failed']) && ($run['verdict'] ?? 'unknown') !== 'unknown',
                ];
            }
        }

        if ($runs !== [] && !$complete) {
            throw new InvalidArgumentException(\sprintf(
                'No run in %s carries a root_id and a file (%s): it predates corpus campaigns.',
                $name,
                implode(', ', array_map(static fn (int $id): string => "task {$id}", array_column($runs, 'task_id')))
            ));
        }

        return $runs;
    }
}
