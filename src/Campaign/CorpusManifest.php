<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use RuntimeException;

/**
 * A frozen corpus: its manifest.json plus the markdown files it lists.
 */
final class CorpusManifest
{
    private const MIN_FILES_PER_TRAP = 3;

    /**
     * @param array<string, mixed> $data
     * @param ManifestEntry[] $entries
     */
    private function __construct(
        public readonly string $name,
        public readonly string $directory,
        public readonly array $data,
        public readonly array $entries,
        public readonly CheckSettings $settings,
    ) {
    }

    public static function fromName(string $benchRoot, string $name): self
    {
        $directory = rtrim($benchRoot, '/') . '/' . $name;

        if (!is_dir($directory)) {
            throw new RuntimeException(
                "Corpus '{$name}' not found at {$directory}. bench/ is not in the release zip: run from a git checkout of wp-ai-forge-devtools."
            );
        }

        return self::load($directory);
    }

    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/') . '/manifest.json';

        if (!is_readable($path)) {
            throw new RuntimeException("No readable manifest.json in {$directory}.");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!\is_array($data)) {
            throw new RuntimeException("{$path} is not valid JSON: " . json_last_error_msg());
        }

        $entries = array_map(
            static fn (mixed $file): ManifestEntry => ManifestEntry::fromArray(\is_array($file) ? $file : []),
            \is_array($data['files'] ?? null) ? array_values($data['files']) : []
        );

        return new self(basename(rtrim($directory, '/')), rtrim($directory, '/'), $data, $entries, CheckSettings::fromManifest($data));
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return self::validate($this->data, $this->directory);
    }

    public function entry(string $id): ?ManifestEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function markdown(ManifestEntry $entry): ?string
    {
        $path = "{$this->directory}/{$entry->file}";

        return is_readable($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * @return array<string, string>
     */
    public function hashes(): array
    {
        $hashes = ['manifest.json' => (string) sha1_file("{$this->directory}/manifest.json")];

        foreach ($this->entries as $entry) {
            $path = "{$this->directory}/{$entry->file}";

            if (is_readable($path)) {
                $hashes[$entry->file] = (string) sha1_file($path);
            }
        }

        return $hashes;
    }

    /**
     * Schema and invariants only. No hard-coded counts, so adding files never breaks it.
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    public static function validate(array $data, string $directory): array
    {
        $errors = [];
        $files = $data['files'] ?? null;

        if (!\is_array($files) || $files === []) {
            return ['files must be a non-empty list'];
        }

        if (!\is_array($data['generic_cta'] ?? null) || $data['generic_cta'] === []) {
            $errors[] = 'generic_cta must be a non-empty list';
        }

        foreach (['orphan_overlap', 'retention', 'quote_match'] as $ratio) {
            $value = $data['thresholds'][$ratio] ?? null;

            if ($value !== null && (!is_numeric($value) || $value <= 0 || $value > 1)) {
                $errors[] = "threshold {$ratio} must be in (0, 1]";
            }
        }

        $cram = $data['thresholds']['cram_chars'] ?? null;

        if ($cram !== null && (!\is_int($cram) || $cram <= 0)) {
            $errors[] = 'threshold cram_chars must be a positive integer';
        }

        $seen = [];
        $trapCounts = array_fill_keys(ManifestEntry::TRAPS, 0);

        foreach ($files as $raw) {
            $raw = \is_array($raw) ? $raw : [];
            $id = (string) ($raw['id'] ?? '');

            if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1) {
                $errors[] = "invalid id '{$id}'";
                continue;
            }

            if (isset($seen[$id])) {
                $errors[] = "duplicate id {$id}";
                continue;
            }

            $seen[$id] = true;
            $entry = ManifestEntry::fromArray($raw);

            if ($entry->file !== "{$id}.md") {
                $errors[] = "{$id}: file must be {$id}.md";
            } elseif (!is_readable("{$directory}/{$entry->file}")) {
                $errors[] = "{$id}: file {$entry->file} is missing";
            }

            if (!\in_array($entry->author, ManifestEntry::AUTHORS, true)) {
                $errors[] = "{$id}: unknown author {$entry->author}";
            }

            if (!\in_array($entry->locale, ManifestEntry::LOCALES, true)) {
                $errors[] = "{$id}: unknown locale {$entry->locale}";
            }

            if ($entry->sector === '') {
                $errors[] = "{$id}: sector is empty";
            }

            if ($entry->traps === [] || array_diff($entry->traps, ManifestEntry::TRAPS) !== []) {
                $errors[] = "{$id}: traps must be a non-empty subset of " . implode(', ', ManifestEntry::TRAPS);
            }

            foreach (array_intersect($entry->traps, ManifestEntry::TRAPS) as $trap) {
                $trapCounts[$trap]++;
            }

            foreach ((array) ($raw['figures'] ?? []) as $key => $phrases) {
                if (TextTools::numbers((string) $key) === []) {
                    $errors[] = "{$id}: figure key {$key} is not a number";
                } elseif (!\is_array($phrases) || $phrases === []) {
                    $errors[] = "{$id}: figure {$key} needs at least one phrase";
                }
            }

            foreach ($entry->quotes as $quote) {
                if ($quote['text'] === '' || $quote['attribution'] === '') {
                    $errors[] = "{$id}: every quote needs a text and an attribution";
                    break;
                }
            }

            if ($entry->hasTrap('T3') && ($entry->grid === null || $entry->grid['section'] === '' || \count($entry->grid['items']) !== 2)) {
                $errors[] = "{$id}: T3 needs a grid with a section and exactly 2 items";
            }

            if ($entry->hasTrap('T6') && $entry->claims === []) {
                $errors[] = "{$id}: T6 needs at least one claim";
            }

            if ($entry->author === 'adapted' && ($entry->source === null || $entry->source === '')) {
                $errors[] = "{$id}: an adapted file needs its source";
            }
        }

        foreach ($trapCounts as $trap => $count) {
            if ($count < self::MIN_FILES_PER_TRAP) {
                $errors[] = \sprintf('trap %s is carried by %d file(s), needs at least %d', $trap, $count, self::MIN_FILES_PER_TRAP);
            }
        }

        return $errors;
    }
}
