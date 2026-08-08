<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use InvalidArgumentException;

/**
 * One cell of a validation matrix: which provider, at which preset, against
 * which Content Integrator template.
 */
final class ComboSpec
{
    private const PROVIDERS = ['gemini', 'openai', 'anthropic'];
    private const PRESETS = ['economic', 'balanced', 'performance'];

    public function __construct(
        public readonly string $provider,
        public readonly string $preset,
        public readonly string $templateSlug,
    ) {
    }

    public function key(): string
    {
        return $this->provider . ':' . $this->preset . ':' . $this->templateSlug;
    }

    public static function parse(string $combo): self
    {
        $parts = array_map('trim', explode(':', $combo));

        if (\count($parts) !== 3) {
            throw new InvalidArgumentException(
                "Combo '{$combo}' must have the form provider:preset:template-slug"
            );
        }

        [$provider, $preset, $templateSlug] = $parts;

        if ($provider === '' || $preset === '' || $templateSlug === '') {
            throw new InvalidArgumentException("Combo '{$combo}' has an empty field");
        }

        if (!\in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException(
                "Unknown provider '{$provider}' (expected one of: " . implode(', ', self::PROVIDERS) . ')'
            );
        }

        if (!\in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException(
                "Unknown preset '{$preset}' (expected one of: " . implode(', ', self::PRESETS) . ')'
            );
        }

        return new self($provider, $preset, $templateSlug);
    }

    /**
     * @return self[]
     */
    public static function parseList(string $csv): array
    {
        $combos = [];

        foreach (explode(',', $csv) as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            $combos[] = self::parse($segment);
        }

        if ($combos === []) {
            throw new InvalidArgumentException('No combos given. Use --combos=provider:preset:template-slug,...');
        }

        return $combos;
    }
}
