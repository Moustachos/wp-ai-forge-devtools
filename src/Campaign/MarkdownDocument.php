<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Read-only view of a corpus markdown file, or of a stored markdown_snapshot.
 */
final class MarkdownDocument
{
    /** @var string[] */
    private array $lines;

    public function __construct(string $markdown)
    {
        $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
    }

    public function title(): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^#\s+(.+?)\s*$/u', $line, $m) === 1) {
                return self::inline($m[1]);
            }
        }

        return null;
    }

    /**
     * @return array<int, array{level: int, text: string}>
     */
    public function headings(): array
    {
        $headings = [];

        foreach ($this->lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $m) === 1) {
                $headings[] = ['level' => \strlen($m[1]), 'text' => self::inline($m[2])];
            }
        }

        return $headings;
    }

    /**
     * The page cut at its `##` headings. Everything above the first one,
     * title included, is the "[intro]" section.
     *
     * @return array<int, array{heading: string, body: string}>
     */
    public function sections(): array
    {
        $sections = [['heading' => '[intro]', 'lines' => []]];

        foreach ($this->lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/u', $line, $m) === 1) {
                $sections[] = ['heading' => self::inline($m[1]), 'lines' => []];
                continue;
            }

            $sections[array_key_last($sections)]['lines'][] = $line;
        }

        $out = [];

        foreach ($sections as $section) {
            $body = trim(implode("\n", $section['lines']));

            if ($section['heading'] === '[intro]' && $body === '') {
                continue;
            }

            $out[] = ['heading' => $section['heading'], 'body' => $body];
        }

        return $out;
    }

    /**
     * Lines that hold nothing but bold text: the shape a model turns into a heading.
     *
     * @return string[]
     */
    public function boldLines(): array
    {
        $bold = [];

        foreach ($this->lines as $line) {
            if (preg_match('/^\s*\*\*([^*]+?)\s*:?\s*\*\*\s*:?\s*$/u', $line, $m) === 1) {
                $bold[] = trim($m[1]);
            }
        }

        return $bold;
    }

    /**
     * Standalone bold lines, then the bold lead of list items.
     *
     * @return string[]
     */
    public function boldLeads(): array
    {
        $leads = $this->boldLines();

        foreach ($this->lines as $line) {
            if (preg_match('/^\s*(?:[-*+]|\d+\.)\s+\*\*([^*]+?)\s*:?\s*\*\*/u', $line, $m) === 1) {
                $leads[] = trim($m[1]);
            }
        }

        return $leads;
    }

    /**
     * @return string[]
     */
    public function links(): array
    {
        preg_match_all('/(?<!!)\[[^\]]*\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/u', implode("\n", $this->lines), $m);

        return $m[1];
    }

    /**
     * @return string[]
     */
    public function linkTexts(): array
    {
        preg_match_all('/(?<!!)\[([^\]]*)\]\(\s*[^)\s]+(?:\s+"[^"]*")?\s*\)/u', implode("\n", $this->lines), $m);

        return $m[1];
    }

    public function plainText(): string
    {
        return self::toPlain(implode("\n", $this->lines));
    }

    /**
     * @return string[]
     */
    public function sentences(): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+|\n+/u', $this->plainText(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $sentence): bool => $sentence !== ''
        ));
    }

    public static function toPlain(string $markdown): string
    {
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $markdown);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*\|?\s*:?-{3,}/u', $line) === 1) {
                continue;
            }

            $line = (string) preg_replace('/^\s{0,3}(?:#{1,6}\s+|>\s?|[-*+]\s+|\d+\.\s+)/u', '', $line);
            $line = str_replace(['**', '__', '`', '|'], ['', '', '', ' '], $line);
            $line = (string) preg_replace('/(?<![\p{L}\d])[*_]|[*_](?![\p{L}\d])/u', '', $line);
            $lines[] = trim((string) preg_replace('/\s+/u', ' ', $line));
        }

        return trim(implode("\n", $lines));
    }

    private static function inline(string $text): string
    {
        return trim(str_replace(['**', '__', '`'], '', (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text)));
    }
}
