<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Per-combo deltas between a stored baseline report and a fresh one.
 *
 * A drop in publishable rate always outranks a rise in mean score: a higher
 * mean with fewer publishable runs means the failures got worse, not better.
 */
final class BaselineComparator
{
    public const STATUS_REGRESSION = 'regression';
    public const STATUS_IMPROVEMENT = 'improvement';
    public const STATUS_STABLE = 'stable';
    public const STATUS_NEW = 'new';
    public const STATUS_MISSING = 'missing';

    /** Mean-score movement below this is noise. */
    private const GLOBAL_THRESHOLD = 2.0;

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return array<int, array{combo: string, status: string, publishable_delta: float, global_delta: float, signature_delta: float}>
     */
    public static function compare(array $baseline, array $current): array
    {
        $baseCombos = \is_array($baseline['combos'] ?? null) ? $baseline['combos'] : [];
        $currCombos = \is_array($current['combos'] ?? null) ? $current['combos'] : [];

        $keys = array_keys($baseCombos + $currCombos);
        $rows = [];

        foreach ($keys as $key) {
            $inBase = \array_key_exists($key, $baseCombos);
            $inCurr = \array_key_exists($key, $currCombos);

            if (!$inBase) {
                $rows[] = self::row((string) $key, self::STATUS_NEW, 0.0, 0.0, 0.0);
                continue;
            }

            if (!$inCurr) {
                $rows[] = self::row((string) $key, self::STATUS_MISSING, 0.0, 0.0, 0.0);
                continue;
            }

            $publishableDelta = self::delta($currCombos[$key], $baseCombos[$key], 'publishable_rate');
            $globalDelta = self::delta($currCombos[$key], $baseCombos[$key], 'mean_global');
            $signatureDelta = self::delta($currCombos[$key], $baseCombos[$key], 'mean_signature');

            $rows[] = self::row(
                (string) $key,
                self::classify($publishableDelta, $globalDelta),
                $publishableDelta,
                $globalDelta,
                $signatureDelta
            );
        }

        return $rows;
    }

    /**
     * @param array<int, array{combo: string, status: string, publishable_delta: float, global_delta: float, signature_delta: float}> $rows
     */
    public static function toMarkdown(array $rows): string
    {
        $lines = [];
        $lines[] = '| combo | status | Δ publishable | Δ global | Δ signature |';
        $lines[] = '|---|---|---|---|---|';

        foreach ($rows as $row) {
            $lines[] = \sprintf(
                '| %s | %s | %+.1f%% | %+.1f | %+.1f |',
                $row['combo'],
                $row['status'],
                $row['publishable_delta'],
                $row['global_delta'],
                $row['signature_delta']
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private static function classify(float $publishableDelta, float $globalDelta): string
    {
        if ($publishableDelta < 0.0 || $globalDelta <= -self::GLOBAL_THRESHOLD) {
            return self::STATUS_REGRESSION;
        }

        if ($globalDelta >= self::GLOBAL_THRESHOLD) {
            return self::STATUS_IMPROVEMENT;
        }

        return self::STATUS_STABLE;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $baseline
     */
    private static function delta(array $current, array $baseline, string $field): float
    {
        return round((float) ($current[$field] ?? 0.0) - (float) ($baseline[$field] ?? 0.0), 1);
    }

    /**
     * @return array{combo: string, status: string, publishable_delta: float, global_delta: float, signature_delta: float}
     */
    private static function row(
        string $combo,
        string $status,
        float $publishableDelta,
        float $globalDelta,
        float $signatureDelta
    ): array {
        return [
            'combo' => $combo,
            'status' => $status,
            'publishable_delta' => $publishableDelta,
            'global_delta' => $globalDelta,
            'signature_delta' => $signatureDelta,
        ];
    }
}
