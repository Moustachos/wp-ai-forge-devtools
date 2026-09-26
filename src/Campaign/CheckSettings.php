<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Thresholds and closed word lists the trap checks read from the manifest.
 */
final class CheckSettings
{
    public const DEFAULT_GENERIC_CTA = [
        'nous contacter', 'prendre contact', 'contactez-nous', 'en savoir plus', 'découvrir',
        'contact us', 'get in touch', 'learn more',
    ];

    /**
     * @param string[] $genericCta Whole labels that pass whatever the source offers.
     * @param string[] $ctaVocabulary Words a label may use besides the offers': verbs
     *                                and function words, never an offer noun.
     * @param string[] $ctaNavigation Words that make a label a way around the page
     *                                ("voir", "méthode"): such a label may also name
     *                                what the source's headings name.
     */
    public function __construct(
        public readonly float $orphanOverlap = 0.5,
        public readonly float $retention = 0.8,
        public readonly float $quoteMatch = 0.6,
        public readonly int $cramChars = 40,
        public readonly array $genericCta = self::DEFAULT_GENERIC_CTA,
        public readonly array $ctaVocabulary = [],
        public readonly array $ctaNavigation = [],
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function fromManifest(array $manifest): self
    {
        $thresholds = \is_array($manifest['thresholds'] ?? null) ? $manifest['thresholds'] : [];

        return new self(
            (float) ($thresholds['orphan_overlap'] ?? 0.5),
            (float) ($thresholds['retention'] ?? 0.8),
            (float) ($thresholds['quote_match'] ?? 0.6),
            (int) ($thresholds['cram_chars'] ?? 40),
            self::strings($manifest['generic_cta'] ?? self::DEFAULT_GENERIC_CTA),
            self::strings($manifest['cta_vocabulary'] ?? []),
            self::strings($manifest['cta_navigation'] ?? []),
        );
    }

    /**
     * @return string[]
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
