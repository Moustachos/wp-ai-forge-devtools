<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * One corpus file's ground truth: every number it states, the quotes and
 * offers it really holds, and the traps it was written to carry.
 */
final class ManifestEntry
{
    public const AUTHORS = ['claude', 'gemini', 'gpt', 'adapted'];
    public const TRAPS = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6'];
    public const LOCALES = ['fr_FR', 'en_US'];

    /**
     * @param string[] $traps
     * @param array<string, string[]> $figures Normalised number => phrases stating it.
     * @param array<int, array{text: string, attribution: string}> $quotes
     * @param string[] $offers
     * @param string[] $claims
     * @param array{section: string, items: string[]}|null $grid
     */
    public function __construct(
        public readonly string $id,
        public readonly string $file,
        public readonly string $author,
        public readonly string $locale,
        public readonly string $sector,
        public readonly array $traps,
        public readonly array $figures,
        public readonly array $quotes,
        public readonly array $offers,
        public readonly array $claims,
        public readonly ?array $grid,
        public readonly ?string $source,
    ) {
    }

    /**
     * Lenient: validation lives in CorpusManifest::validate(), so tests can
     * build an entry from the fields they care about.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? '');
        $figures = [];

        foreach ((\is_array($data['figures'] ?? null) ? $data['figures'] : []) as $key => $phrases) {
            $number = TextTools::numbers((string) $key)[0] ?? (string) $key;
            $figures[$number] = \is_array($phrases) ? array_values(array_map('strval', $phrases)) : [];
        }

        $quotes = [];

        foreach ((\is_array($data['quotes'] ?? null) ? $data['quotes'] : []) as $quote) {
            $quotes[] = [
                'text' => (string) ($quote['text'] ?? ''),
                'attribution' => (string) ($quote['attribution'] ?? ''),
            ];
        }

        $grid = null;

        if (\is_array($data['grid'] ?? null)) {
            $grid = [
                'section' => (string) ($data['grid']['section'] ?? ''),
                'items' => array_values(array_map('strval', (array) ($data['grid']['items'] ?? []))),
            ];
        }

        return new self(
            $id,
            (string) ($data['file'] ?? "{$id}.md"),
            (string) ($data['author'] ?? ''),
            (string) ($data['locale'] ?? ''),
            (string) ($data['sector'] ?? ''),
            array_values(array_map('strval', (array) ($data['traps'] ?? []))),
            $figures,
            $quotes,
            array_values(array_map('strval', (array) ($data['offers'] ?? []))),
            array_values(array_map('strval', (array) ($data['claims'] ?? []))),
            $grid,
            isset($data['source']) ? (string) $data['source'] : null,
        );
    }

    public function hasTrap(string $trap): bool
    {
        return \in_array($trap, $this->traps, true);
    }

    public function isFigure(string $number): bool
    {
        return \array_key_exists($number, $this->figures);
    }

    /**
     * @return string[]
     */
    public function phrasesFor(string $number): array
    {
        return $this->figures[$number] ?? [];
    }
}
