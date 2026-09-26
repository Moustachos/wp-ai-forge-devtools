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

        $source = new MarkdownDocument($markdown);

        if ($templateDoc->hasTestimonialSlot()) {
            [$results['testimonial_grounding'], $results['testimonial_repurposed']] =
                $this->testimonials($entry, $source, $outputDoc);
        }

        if ($templateDoc->hasCardGrid(3) && $entry->grid !== null) {
            $results['card_count'] = $this->cardCount($entry->grid['items'], $outputDoc);
        }

        if ($templateDoc->hasButton()) {
            $results['cta_grounding'] = $this->ctaGrounding($entry, $source, $outputDoc);
        }

        $results['orphan_headings'] = $this->orphanHeadings($source, $outputDoc);
        $results['content_retention'] = $this->contentRetention($source, $outputDoc);
        $results['link_preservation'] = $this->linkPreservation($source, $outputDoc);

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

        $sourceWords = array_flip(TextTools::contentWords($source->title() . ' ' . $source->plainText(), 3));
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
            } elseif (
                $testimonial['attribution'] !== null
                && !$this->isKnownAttribution($entry, $testimonial['attribution'])
                && !self::namedInSource($testimonial['attribution'], $sourceWords)
            ) {
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

    /**
     * A page crediting its own prose to itself ("L'atelier") repurposes, it does not invent.
     *
     * @param array<string, int> $sourceWords
     */
    private static function namedInSource(string $attribution, array $sourceWords): bool
    {
        $words = TextTools::contentWords($attribution, 3);

        return $words !== [] && array_diff_key(array_flip($words), $sourceWords) === [];
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
     * @param string[] $items
     * @return array{status: string, findings: string[]}
     */
    private function cardCount(array $items, GenerationDocument $output): array
    {
        $itemWords = array_map(static fn (string $item): array => TextTools::contentWords($item), $items);
        $grids = array_values($output->cardGrids());
        $best = null;
        $bestHits = 0;

        foreach ($grids as $cards) {
            $hits = 0;

            foreach ($cards as $card) {
                if ($this->cardMatchesAnItem(TextTools::contentWords($card), $itemWords)) {
                    $hits++;
                }
            }

            if ($hits > $bestHits) {
                $best = $cards;
                $bestHits = $hits;
            }
        }

        if ($best === null && \count($grids) === 1 && \count($grids[0]) > \count($items)) {
            return self::outcome([\sprintf(
                '%d cards for %d items, none matching an item: %s',
                \count($grids[0]),
                \count($items),
                implode(' | ', $grids[0])
            )]);
        }

        if ($best === null || \count($best) <= \count($items)) {
            return self::outcome([]);
        }

        return self::outcome([\sprintf('%d cards for %d items: %s', \count($best), \count($items), implode(' | ', $best))]);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function ctaGrounding(ManifestEntry $entry, MarkdownDocument $source, GenerationDocument $output): array
    {
        $generic = array_map(static fn (string $cta): string => implode(' ', TextTools::words($cta)), $this->settings->genericCta);
        $allowed = [];

        foreach (array_merge($entry->offers, $this->settings->ctaVocabulary, [(string) $source->title()]) as $phrase) {
            foreach (TextTools::contentWords($phrase) as $word) {
                $allowed[TextTools::stem($word)] = true;
            }
        }

        $findings = [];

        foreach ($output->buttons() as $label) {
            $words = TextTools::words($label);

            if ($words === [] || \in_array(implode(' ', $words), $generic, true)) {
                continue;
            }

            $stray = array_values(array_filter(
                TextTools::contentWords($label),
                static fn (string $word): bool => !isset($allowed[TextTools::stem($word)])
            ));

            if ($stray !== []) {
                $findings[] = "«{$label}»: " . implode(', ', $stray) . ' named in no offer';
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function orphanHeadings(MarkdownDocument $source, GenerationDocument $output): array
    {
        $references = [];

        foreach ($source->headings() as $heading) {
            $references[] = TextTools::contentWords($heading['text']);
        }

        foreach ($source->boldLeads() as $lead) {
            $references[] = TextTools::contentWords($lead);
        }

        $findings = [];

        foreach ($output->headings() as $heading) {
            $words = TextTools::contentWords($heading);

            if ($words !== [] && !$this->matchesAny($words, $references)) {
                $findings[] = "«{$heading}»";
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function contentRetention(MarkdownDocument $source, GenerationDocument $output): array
    {
        $kept = array_flip(TextTools::shingles($output->plainText()));
        $findings = [];

        foreach ($source->sections() as $section) {
            $shingles = TextTools::shingles(MarkdownDocument::toPlain($section['body']));

            if ($shingles === []) {
                continue;
            }

            $recall = \count(array_filter($shingles, static fn (string $s): bool => isset($kept[$s]))) / \count($shingles);

            if ($recall < $this->settings->retention) {
                $findings[] = \sprintf('%s: %.2f', $section['heading'], $recall);
            }
        }

        return self::outcome($findings);
    }

    /**
     * @return array{status: string, findings: string[]}
     */
    private function linkPreservation(MarkdownDocument $source, GenerationDocument $output): array
    {
        return self::outcome(array_values(array_diff(array_unique($source->links()), $output->links())));
    }

    /**
     * @param string[] $words
     * @param array<int, string[]> $references
     */
    private function matchesAny(array $words, array $references): bool
    {
        foreach ($references as $reference) {
            if (TextTools::overlap($words, $reference) >= $this->settings->orphanOverlap) {
                return true;
            }
        }

        return false;
    }

    /**
     * Either direction: a card may add words to its item ("Gestion de votre parc"
     * for "Infogérance du parc") as well as drop some.
     *
     * @param string[] $card
     * @param array<int, string[]> $items
     */
    private function cardMatchesAnItem(array $card, array $items): bool
    {
        foreach ($items as $item) {
            if (max(TextTools::overlap($card, $item), TextTools::overlap($item, $card)) >= $this->settings->orphanOverlap) {
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
