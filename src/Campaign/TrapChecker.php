<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * Mechanical checks of one generation against its corpus file's ground truth.
 *
 * A check whose slot the template lacks is n/a, never pass: a template swap
 * must not make a model look careful.
 */
final class TrapChecker
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const NA = 'n/a';

    public const CHECKS = [
        'stat_grounding',
        'stat_has_figure',
        'stat_cram',
        'testimonial_grounding',
        'testimonial_repurposed',
        'card_count',
        'cta_grounding',
        'orphan_headings',
        'content_retention',
        'link_preservation',
    ];

    /** The trap each check was built for; the report also scores it on those files alone. */
    public const TRAP_OF_CHECK = [
        'stat_grounding' => 'T1',
        'testimonial_grounding' => 'T2',
        'card_count' => 'T3',
        'content_retention' => 'T4',
        'cta_grounding' => 'T5',
        'stat_has_figure' => 'T6',
    ];

    /** The generation prompt asks for zero-padded step numbers as decorative filler. */
    private const STEP_NUMBER = '/^0[1-9]$/';

    private const PERCENT_MARKERS = ['%', 'pour cent', 'pourcent', 'percent'];

    public function __construct(
        private readonly CheckSettings $settings = new CheckSettings(),
    ) {
    }

    /**
     * @return array<string, array{status: string, findings: string[]}>
     */
    public function check(ManifestEntry $entry, string $markdown, string $template, string $output): array
    {
        $templateDoc = new GenerationDocument($template);
        $outputDoc = new GenerationDocument($output);
        $hasStats = $templateDoc->hasStatSlot();

        $results = array_fill_keys(self::CHECKS, self::na());
        $results['stat_grounding'] = $hasStats ? $this->statGrounding($entry, $outputDoc) : self::na();
        $results['stat_has_figure'] = $hasStats ? $this->statHasFigure($outputDoc) : self::na();
        $results['stat_cram'] = $hasStats ? $this->statCram($outputDoc) : self::na();

        if ($templateDoc->hasTestimonialSlot()) {
            [$results['testimonial_grounding'], $results['testimonial_repurposed']] =
                $this->testimonials($entry, new MarkdownDocument($markdown), $outputDoc);
        }

        return $results;
    }

    /**
     * @return array{0: array{status: string, findings: string[]}, 1: array{status: string, findings: string[]}}
     */
    private function testimonials(ManifestEntry $entry, MarkdownDocument $source, GenerationDocument $output): array
    {
        $sentences = $source->sentences();
        $candidates = [];

        foreach ($sentences as $i => $sentence) {
            $candidates[] = TextTools::contentWords($sentence);

            if (isset($sentences[$i + 1])) {
                $candidates[] = TextTools::contentWords($sentence . ' ' . $sentences[$i + 1]);
            }
        }

        $invented = [];
        $repurposed = [];

        foreach ($output->testimonials() as $testimonial) {
            $words = TextTools::contentWords($testimonial['quote']);

            if ($words === [] || $this->isRealQuote($entry, $words, $testimonial['attribution'])) {
                continue;
            }

            $label = '«' . mb_strimwidth($testimonial['quote'], 0, 60, '…') . '»';
            $best = 0.0;

            foreach ($candidates as $candidate) {
                $best = max($best, TextTools::overlap($words, $candidate));
            }

            if ($best < $this->settings->quoteMatch) {
                $invented[] = "{$label}: matches no source sentence";
            } elseif ($testimonial['attribution'] !== null && !$this->isKnownAttribution($entry, $testimonial['attribution'])) {
                $invented[] = "{$label}: source words credited to «{$testimonial['attribution']}», who is quoted nowhere";
            } else {
                $repurposed[] = "{$label}: source prose styled as a testimonial";
            }
        }

        return [self::outcome($invented), self::outcome($repurposed)];
    }

    /**
     * @param string[] $words
     */
    private function isRealQuote(ManifestEntry $entry, array $words, ?string $attribution): bool
    {
        foreach ($entry->quotes as $quote) {
            if (TextTools::overlap($words, TextTools::contentWords($quote['text'])) < $this->settings->quoteMatch) {
                continue;
            }

            if ($attribution === null || self::attributionMatches($attribution, $quote['attribution'])) {
                return true;
            }
        }

        return false;
    }

    private function isKnownAttribution(ManifestEntry $entry, string $attribution): bool
    {
        foreach ($entry->quotes as $quote) {
            if (self::attributionMatches($attribution, $quote['attribution'])) {
                return true;
            }
        }

        return false;
    }

    private static function attributionMatches(string $given, string $expected): bool
    {
        $expectedWords = TextTools::contentWords($expected, 3);

        if ($expectedWords === []) {
            return trim(TextTools::fold($given)) === trim(TextTools::fold($expected));
        }

        return array_diff($expectedWords, TextTools::contentWords($given, 3)) === [];
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function statGrounding(ManifestEntry $entry, GenerationDocument $output): array
    {
        $findings = [];

        foreach ($output->statValues() as $value) {
            if (preg_match(self::STEP_NUMBER, trim($value)) === 1) {
                continue;
            }

            foreach (TextTools::numbers($value) as $number) {
                if (!$entry->isFigure($number)) {
                    $findings[] = "«{$value}»: {$number} is stated nowhere in the source";
                } elseif (self::isPercent($value) && !self::anyPercent($entry->phrasesFor($number))) {
                    $findings[] = "«{$value}»: the source never gives {$number} as a percentage";
                }
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function statHasFigure(GenerationDocument $output): array
    {
        $findings = [];

        foreach ($output->statValues() as $value) {
            if (preg_match(self::STEP_NUMBER, trim($value)) !== 1 && TextTools::numbers($value) === []) {
                $findings[] = "«{$value}» holds no figure";
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function statCram(GenerationDocument $output): array
    {
        $findings = [];

        foreach ($output->statValues() as $value) {
            if (mb_strlen(trim($value)) > $this->settings->cramChars) {
                $findings[] = \sprintf('«%s»: %d characters', $value, mb_strlen(trim($value)));
            }
        }

        return self::outcome($findings);
    }

    private static function isPercent(string $text): bool
    {
        $folded = TextTools::fold($text);

        foreach (self::PERCENT_MARKERS as $marker) {
            if (str_contains($folded, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $phrases
     */
    private static function anyPercent(array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (self::isPercent($phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $findings
     * @return array{status: string, findings: string[]}
     */
    private static function outcome(array $findings): array
    {
        return ['status' => $findings === [] ? self::PASS : self::FAIL, 'findings' => array_values($findings)];
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private static function na(): array
    {
        return ['status' => self::NA, 'findings' => []];
    }
}
