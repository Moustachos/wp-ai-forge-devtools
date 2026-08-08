<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Aggregates a campaign's runs into per-combo and grand-total statistics.
 *
 * "Publishable" means the Quality Gate returned pass or warnings; a warning
 * verdict is shippable, a fail is not.
 */
final class CampaignReport
{
    /**
     * @param RunResult[] $runs
     */
    public function __construct(
        public readonly string $label,
        public readonly int $sourceBatchId,
        public readonly int $filesPerCombo,
        public readonly array $runs,
    ) {
    }

    /**
     * @return string[] Combo keys in the order they first appear.
     */
    public function comboKeys(): array
    {
        $keys = [];

        foreach ($this->runs as $run) {
            $keys[$run->comboKey] = true;
        }

        return array_keys($keys);
    }

    /**
     * @return array{runs: int, publishable_rate: float, mean_global: float, mean_signature: float, total_cost: float}
     */
    public function comboStats(string $comboKey): array
    {
        return self::aggregate($this->runsFor($comboKey));
    }

    /**
     * @return array{runs: int, publishable_rate: float, mean_global: float, mean_signature: float, total_cost: float}
     */
    public function totals(): array
    {
        return self::aggregate($this->runs);
    }

    /**
     * @return int[] Task IDs whose verdict is not publishable.
     */
    public function flaggedTaskIds(): array
    {
        $ids = [];

        foreach ($this->runs as $run) {
            if (!$run->isPublishable()) {
                $ids[] = $run->taskId;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $combos = [];

        foreach ($this->comboKeys() as $key) {
            $stats = $this->comboStats($key);

            $detail = array_values(array_map(
                static fn (RunResult $run): array => $run->toArray(),
                $this->runsFor($key)
            ));

            // In the serialised form 'runs' is the detail list and the count
            // moves to 'run_count'; comboStats() keeps 'runs' as the count.
            $combos[$key] = [
                'run_count' => $stats['runs'],
                'publishable_rate' => $stats['publishable_rate'],
                'mean_global' => $stats['mean_global'],
                'mean_signature' => $stats['mean_signature'],
                'total_cost' => $stats['total_cost'],
                'runs' => $detail,
            ];
        }

        return [
            'label' => $this->label,
            'source_batch' => $this->sourceBatchId,
            'files_per_combo' => $this->filesPerCombo,
            'combos' => $combos,
            'totals' => $this->totals(),
        ];
    }

    public function toMarkdown(): string
    {
        $lines = [];
        $lines[] = '## QG campaign — ' . $this->label;
        $lines[] = '';
        $lines[] = \sprintf(
            'Source batch %d, %d file(s) per combo, %d run(s).',
            $this->sourceBatchId,
            $this->filesPerCombo,
            \count($this->runs)
        );
        $lines[] = '';
        $lines[] = '| task | combo | model | verdict | global | comp | sign | covr | vol | leak | dup | cost |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|---|';

        foreach ($this->runs as $run) {
            $lines[] = \sprintf(
                '| %d | %s | %s | %s | %d | %d | %d | %d | %d | %d | %d | $%.3f |',
                $run->taskId,
                $run->comboKey,
                $run->modelId,
                $run->verdict,
                $run->globalScore,
                $run->subscore('completion'),
                $run->subscore('signature'),
                $run->subscore('coverage'),
                $run->subscore('volume'),
                $run->subscore('leaks'),
                $run->subscore('dups'),
                $run->cost
            );
        }

        $lines[] = '';
        $lines[] = '| combo | runs | publishable | mean global | mean signature | cost |';
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($this->comboKeys() as $key) {
            $stats = $this->comboStats($key);
            $lines[] = \sprintf(
                '| %s | %d | %.1f%% | %.1f | %.1f | $%.3f |',
                $key,
                $stats['runs'],
                $stats['publishable_rate'],
                $stats['mean_global'],
                $stats['mean_signature'],
                $stats['total_cost']
            );
        }

        $totals = $this->totals();
        $lines[] = \sprintf(
            '| **TOTAL** | %d | %.1f%% | %.1f | %.1f | $%.3f |',
            $totals['runs'],
            $totals['publishable_rate'],
            $totals['mean_global'],
            $totals['mean_signature'],
            $totals['total_cost']
        );

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return RunResult[]
     */
    private function runsFor(string $comboKey): array
    {
        return array_values(array_filter(
            $this->runs,
            static fn (RunResult $run): bool => $run->comboKey === $comboKey
        ));
    }

    /**
     * @param RunResult[] $runs
     * @return array{runs: int, publishable_rate: float, mean_global: float, mean_signature: float, total_cost: float}
     */
    private static function aggregate(array $runs): array
    {
        $count = \count($runs);

        if ($count === 0) {
            return [
                'runs' => 0,
                'publishable_rate' => 0.0,
                'mean_global' => 0.0,
                'mean_signature' => 0.0,
                'total_cost' => 0.0,
            ];
        }

        $publishable = 0;
        $globalSum = 0;
        $signatureSum = 0;
        $costSum = 0.0;

        foreach ($runs as $run) {
            if ($run->isPublishable()) {
                $publishable++;
            }

            $globalSum += $run->globalScore;
            $signatureSum += $run->subscore('signature');
            $costSum += $run->cost;
        }

        return [
            'runs' => $count,
            'publishable_rate' => round(100 * $publishable / $count, 1),
            'mean_global' => round($globalSum / $count, 1),
            'mean_signature' => round($signatureSum / $count, 1),
            'total_cost' => round($costSum, 4),
        ];
    }
}
