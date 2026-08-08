<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * One llm_generate run's Quality Gate outcome, as stored in task meta.
 */
final class RunResult
{
    public const AXES = ['completion', 'signature', 'coverage', 'volume', 'leaks', 'dups'];

    private const PUBLISHABLE_VERDICTS = ['pass', 'warnings'];

    /**
     * @param array<string, int> $subscores
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $comboKey,
        public readonly string $modelId,
        public readonly string $verdict,
        public readonly int $globalScore,
        public readonly array $subscores,
        public readonly float $cost,
    ) {
    }

    public function isPublishable(): bool
    {
        return \in_array($this->verdict, self::PUBLISHABLE_VERDICTS, true);
    }

    public function subscore(string $axis): int
    {
        return (int) ($this->subscores[$axis] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'task_id' => $this->taskId,
            'model_id' => $this->modelId,
            'verdict' => $this->verdict,
            'global' => $this->globalScore,
            'cost' => round($this->cost, 6),
        ];

        foreach (self::AXES as $axis) {
            $array[$axis] = $this->subscore($axis);
        }

        return $array;
    }
}
