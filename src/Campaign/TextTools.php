<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Text normalisation shared by the corpus checks.
 *
 * No WordPress calls: the checks run in unit tests and from a plain PHP script.
 */
final class TextTools
{
    private const FOLD = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        "\u{2018}" => "'", "\u{2019}" => "'",
    ];

    private const SPACES = ["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}"];

    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::FOLD);
    }

    /**
     * @return string[]
     */
    public static function words(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return string[]
     */
    public static function contentWords(string $text, int $minLength = 4): array
    {
        return array_values(array_unique(array_filter(
            self::words($text),
            static fn (string $word): bool => \strlen($word) >= $minLength
        )));
    }

    /**
     * A crude stem: the folded word cut to five characters, so that a verb
     * meets its noun and a singular its plural ("estimer", "estimation").
     */
    public static function stem(string $word): string
    {
        return mb_substr(self::fold($word), 0, 5, 'UTF-8');
    }

    /**
     * @param string[] $words
     * @param string[] $reference
     */
    public static function overlap(array $words, array $reference): float
    {
        if ($words === []) {
            return 0.0;
        }

        return \count(array_intersect($words, $reference)) / \count($words);
    }

    /**
     * @return string[]
     */
    public static function shingles(string $text, int $size = 5): array
    {
        $words = self::words($text);
        $shingles = [];

        for ($i = 0; $i + $size <= \count($words); $i++) {
            $shingles[implode(' ', \array_slice($words, $i, $size))] = true;
        }

        return array_map('strval', array_keys($shingles));
    }

    /**
     * Numeric tokens, normalised so that the same figure written two ways
     * compares equal: "15 000" and "15000", "4,8" and "4.8".
     *
     * @return string[]
     */
    public static function numbers(string $text): array
    {
        $text = str_replace(self::SPACES, ' ', $text);
        $numbers = [];

        // A French phone number is one token, not five two-digit figures.
        $text = (string) preg_replace_callback(
            '/(?<![\d.,])0\d(?:[ .]\d{2}){4}(?![\d])/',
            static function (array $match) use (&$numbers): string {
                $numbers[] = (string) preg_replace('/\D/', '', $match[0]);
                return ' ';
            },
            $text
        );

        preg_match_all(
            '/(?<![\d.,])\d{1,3}(?: \d{3})+(?:[.,]\d+)?(?!\d)|(?<![\d.,])\d+(?:[.,]\d+)?(?!\d)/',
            $text,
            $matches
        );

        foreach ($matches[0] as $raw) {
            $numbers[] = str_replace([' ', ','], ['', '.'], $raw);
        }

        return $numbers;
    }

    public static function wordCount(string $text): int
    {
        return \count(self::words($text));
    }
}
