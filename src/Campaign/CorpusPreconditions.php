<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Checks a corpus file really holds the traps its manifest entry claims, and
 * warns when it would trip a Quality Gate artifact instead of a model.
 */
final class CorpusPreconditions
{
    public const MIN_WORDS = 600;
    public const MAX_WORDS = 2500;
    public const DENSE_MIN_WORDS = 450;
    public const HEADING_INFLATION = 1.8;

    private const LEAK_PATTERNS = ['lorem ipsum', 'consectetur', 'dolor sit', 'adipiscing'];
    private const LINK_HOST = '#^https?://([a-z0-9-]+\.)*example\.com(/|$)#i';

    /**
     * @return string[]
     */
    public static function check(ManifestEntry $entry, string $markdown): array
    {
        $document = new MarkdownDocument($markdown);
        $plain = $document->plainText();
        $folded = TextTools::fold($plain);
        $errors = [];

        if (str_contains($markdown, '<')) {
            $errors[] = 'contains a < character';
        }

        if (str_contains($markdown, '![')) {
            $errors[] = 'contains an image';
        }

        foreach (self::LEAK_PATTERNS as $pattern) {
            if (str_contains(TextTools::fold($markdown), $pattern)) {
                $errors[] = "contains the leak pattern «{$pattern}»";
            }
        }

        $titles = \count(array_filter($document->headings(), static fn (array $h): bool => $h['level'] === 1));

        if ($titles !== 1) {
            $errors[] = "needs exactly one # title, found {$titles}";
        }

        foreach ($document->links() as $href) {
            if (preg_match(self::LINK_HOST, $href) !== 1) {
                $errors[] = "link {$href} is not an example.com URL";
            }
        }

        $words = TextTools::wordCount($plain);

        if ($words < self::MIN_WORDS || $words > self::MAX_WORDS) {
            $errors[] = \sprintf('%d words, outside %d..%d', $words, self::MIN_WORDS, self::MAX_WORDS);
        }

        foreach (array_unique(TextTools::numbers($plain)) as $number) {
            if (!$entry->isFigure($number)) {
                $errors[] = "number {$number} is not in figures";
            }
        }

        foreach ($entry->figures as $phrases) {
            foreach ($phrases as $phrase) {
                if (!str_contains($folded, TextTools::fold($phrase))) {
                    $errors[] = "figure phrase «{$phrase}» is not in the text";
                }
            }
        }

        foreach ($entry->quotes as $quote) {
            foreach (['text', 'attribution'] as $field) {
                if (!str_contains($folded, TextTools::fold($quote[$field]))) {
                    $errors[] = "quote {$field} «{$quote[$field]}» is not in the text";
                }
            }
        }

        foreach (['offers' => $entry->offers, 'claims' => $entry->claims] as $field => $phrases) {
            foreach ($phrases as $phrase) {
                if (!str_contains($folded, TextTools::fold($phrase))) {
                    $errors[] = "{$field} phrase «{$phrase}» is not in the text";
                }
            }
        }

        if ($entry->hasTrap('T1') && (str_contains($plain, '%') || str_contains($folded, 'pour cent') || str_contains($folded, 'percent'))) {
            $errors[] = 'T1 file states a percentage';
        }

        if ($entry->hasTrap('T2')) {
            if ($entry->quotes !== []) {
                $errors[] = 'T2 file lists quotes';
            }

            if (preg_match('/^>\s*[«"“]/mu', $markdown) === 1 || preg_match('/[»"”]\s*[—–-]\s*\p{Lu}/u', $plain) === 1) {
                $errors[] = 'T2 file holds an attributed quote';
            }
        }

        if ($entry->hasTrap('T3') && $entry->grid !== null) {
            $error = self::gridError($document, $entry->grid);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if ($entry->hasTrap('T4') && !self::hasDenseSection($document)) {
            $errors[] = 'T4 file has no section of 450+ words at twice the median';
        }

        if ($entry->hasTrap('T5') && $entry->offers !== []) {
            $errors[] = 'T5 file lists offers';
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    public static function lint(string $markdown): array
    {
        $document = new MarkdownDocument($markdown);
        $warnings = [];

        preg_match_all('/^#{1,3}\s+/m', $markdown, $headings);
        $headingCount = \count($headings[0]);
        $boldCount = \count($document->boldLines());

        if ($headingCount > 0 && $boldCount > 0) {
            $ratio = ($headingCount + $boldCount) / $headingCount;

            if ($ratio > self::HEADING_INFLATION) {
                $warnings[] = \sprintf(
                    '%d bold line(s) over %d heading(s): ratio %.1f would pass the %.1f heading-inflation gate if each became a heading',
                    $boldCount,
                    $headingCount,
                    $ratio,
                    self::HEADING_INFLATION
                );
            }
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $markdown)), static fn (string $l): bool => $l !== ''));
        $last = $lines === [] ? '' : $lines[\count($lines) - 1];

        if (preg_match('/[&«»]/u', $last) === 1) {
            $warnings[] = 'last line holds entity-prone characters (&, « »)';
        }

        return $warnings;
    }

    /**
     * @param array{section: string, items: string[]} $grid
     */
    private static function gridError(MarkdownDocument $document, array $grid): ?string
    {
        $expected = implode(' | ', $grid['items']);

        foreach ($document->sections() as $section) {
            if (TextTools::fold($section['heading']) !== TextTools::fold($grid['section'])) {
                continue;
            }

            preg_match_all('/^###\s+(.+?)\s*$/mu', $section['body'], $h3);
            $items = $h3[1];

            if ($items === []) {
                preg_match_all('/^[-*+]\s+(?:\*\*)?([^*\n:]+)/mu', $section['body'], $list);
                $items = $list[1];
            }

            $items = array_map('trim', $items);

            if (\count($items) !== \count($grid['items'])) {
                return \sprintf('T3 section «%s» holds %d parallel items, expected %s', $grid['section'], \count($items), $expected);
            }

            foreach ($grid['items'] as $i => $item) {
                if (!str_starts_with(TextTools::fold($items[$i]), TextTools::fold($item))) {
                    return \sprintf('T3 section «%s» items are %s, expected %s', $grid['section'], implode(' | ', $items), $expected);
                }
            }

            return null;
        }

        return "T3 section «{$grid['section']}» not found";
    }

    private static function hasDenseSection(MarkdownDocument $document): bool
    {
        $counts = [];

        foreach ($document->sections() as $section) {
            if ($section['heading'] !== '[intro]') {
                $counts[] = TextTools::wordCount(MarkdownDocument::toPlain($section['body']));
            }
        }

        if ($counts === []) {
            return false;
        }

        $sorted = $counts;
        sort($sorted);
        $middle = intdiv(\count($sorted), 2);
        $median = \count($sorted) % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

        foreach ($counts as $count) {
            if ($count >= self::DENSE_MIN_WORDS && $count >= 2 * $median) {
                return true;
            }
        }

        return false;
    }
}
