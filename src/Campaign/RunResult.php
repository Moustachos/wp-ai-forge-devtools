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
     * @param bool $failed The generation never ran (provider outage, timeout).
     *                     Such a run carries no verdict and must stay out of
     *                     every average: scoring it as zero makes an outage
     *                     look like a model collapsing.
     * @param ?int $rootId The batch root: one per repeat.
     * @param ?string $sourceFile Corpus file id, from the parent task's source_filename meta.
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $comboKey,
        public readonly string $modelId,
        public readonly string $verdict,
        public readonly int $globalScore,
        public readonly array $subscores,
        public readonly float $cost,
        public readonly bool $failed = false,
        public readonly ?int $rootId = null,
        public readonly ?string $sourceFile = null,
    ) {
    }

    /**
     * True when the run produced a Quality Gate verdict worth aggregating.
     */
    public function isScored(): bool
    {
        return !$this->failed && $this->verdict !== 'unknown';
    }

    public function isPublishable(): bool
    {
        return $this->isScored() && \in_array($this->verdict, self::PUBLISHABLE_VERDICTS, true);
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

        $array['failed'] = $this->failed;

        if ($this->rootId !== null) {
            $array['root_id'] = $this->rootId;
        }

        if ($this->sourceFile !== null) {
            $array['file'] = $this->sourceFile;
        }

        return $array;
    }
}
