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

    /** Folded words that name the business's own people as a whole, beside its name. */
    private const TEAM_WORDS = ['equipe', 'team'];

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
        $results['stat_has_figure'] = $hasStats ? $this->statHasFigure($entry, $outputDoc) : self::na();
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
        $sentences = array_map(static fn (string $s): array => TextTools::contentWords($s), $source->sentences());
        $titleWords = array_flip(TextTools::contentWords((string) $source->title(), 3));
        $invented = [];
        $repurposed = [];

        foreach ($output->testimonials() as $testimonial) {
            $words = TextTools::contentWords($testimonial['quote']);

            if ($words === [] || $this->isRealQuote($entry, $words, $testimonial['attribution'])) {
                continue;
            }

            $label = '«' . mb_strimwidth($testimonial['quote'], 0, 60, '…') . '»';

            $unmatched = $this->unmatchedSentences($testimonial['quote'], $sentences);

            if ($unmatched !== []) {
                $invented[] = "{$label}: " . implode(', ', array_map(
                    static fn (string $s): string => '«' . mb_strimwidth($s, 0, 60, '…') . '»',
                    $unmatched
                )) . ' matches no source sentence';
            } elseif (
                $testimonial['attribution'] !== null
                && !$this->isKnownAttribution($entry, $testimonial['attribution'])
                && !self::isTheBusinessName($testimonial['attribution'], $titleWords)
            ) {
                $invented[] = "{$label}: source words credited to «{$testimonial['attribution']}», who is quoted nowhere";
            } else {
                $repurposed[] = "{$label}: source prose styled as a testimonial";
            }
        }

        return [self::outcome($invented), self::outcome($repurposed)];
    }

    /**
     * Sentences of the quote that match no source sentence or consecutive pair.
     * Each sentence is held to the source on its own, so a paragraph lifted whole
     * passes while one invented sentence appended to it does not.
     *
     * @param array<int, string[]> $sentences content words of each source sentence
     * @return string[]
     */
    private function unmatchedSentences(string $quote, array $sentences): array
    {
        $unmatched = [];

        foreach (preg_split('/(?<=[.!?…])\s+/u', trim($quote), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
            $words = TextTools::contentWords($sentence);

            if ($words === []) {
                continue;
            }

            $best = 0.0;

            foreach ($sentences as $i => $candidate) {
                $best = max($best, TextTools::overlap($words, $candidate));

                if (isset($sentences[$i + 1])) {
                    $best = max($best, TextTools::overlap($words, array_merge($candidate, $sentences[$i + 1])));
                }
            }

            if ($best < $this->settings->quoteMatch) {
                $unmatched[] = $sentence;
            }
        }

        return $unmatched;
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
     * A page crediting its own prose to itself ("L'atelier" under "# Atelier Brun",
     * "Shiftloom Team") repurposes, it does not invent. Only the title names the
     * business: a patient or a person the body mentions is still someone who said
     * nothing.
     *
     * @param array<string, int> $titleWords
     */
    private static function isTheBusinessName(string $attribution, array $titleWords): bool
    {
        $words = array_diff(TextTools::contentWords($attribution, 3), self::TEAM_WORDS);

        return $words !== [] && array_diff_key(array_flip($words), $titleWords) === [];
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
    private function statHasFigure(ManifestEntry $entry, GenerationDocument $output): array
    {
        $findings = [];

        foreach ($output->statValues() as $value) {
            if (
                preg_match(self::STEP_NUMBER, trim($value)) !== 1
                && TextTools::numbers($value) === []
                && !self::statesAFigurePhrase($entry, $value)
            ) {
                $findings[] = "«{$value}» holds no figure";
            }
        }

        return self::outcome($findings);
    }

    /**
     * "Deux plombiers" holds a figure when the manifest records "deux plombiers"
     * as the phrase that states it: a lookup, not a number-word parser. The whole
     * value must be the phrase, so "Deux plombiers, trois apprentis" is not excused.
     */
    private static function statesAFigurePhrase(ManifestEntry $entry, string $value): bool
    {
        $words = implode(' ', TextTools::words($value));

        foreach ($entry->figures as $phrases) {
            foreach ($phrases as $phrase) {
                if ($words !== '' && $words === implode(' ', TextTools::words($phrase))) {
                    return true;
                }
            }
        }

        return false;
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
        $vocabulary = [];
        $groundedStems = [];

        foreach ($this->settings->ctaVocabulary as $phrase) {
            foreach (TextTools::words($phrase) as $word) {
                $vocabulary[$word] = true;
            }
        }

        // Only offers and the business's own title are stemmed: a stemmed verb
        // would admit its noun ("réserver" → "réservation", "consulter" → "consultation").
        foreach (array_merge($entry->offers, [(string) $source->title()]) as $phrase) {
            foreach (TextTools::contentWords($phrase) as $word) {
                $groundedStems[TextTools::stem($word)] = true;
            }
        }

        $navigation = [];
        $landmarkStems = [];

        foreach ($this->settings->ctaNavigation as $phrase) {
            foreach (TextTools::words($phrase) as $word) {
                $navigation[$word] = true;
            }
        }

        $landmarks = array_merge(array_column($source->headings(), 'text'), $source->linkTexts());

        foreach ($landmarks as $landmark) {
            foreach (TextTools::contentWords($landmark) as $word) {
                $landmarkStems[TextTools::stem($word)] = true;
            }
        }

        $findings = [];

        foreach ($output->buttons() as $label) {
            $words = TextTools::words($label);

            if ($words === [] || \in_array(implode(' ', $words), $generic, true)) {
                continue;
            }

            $content = TextTools::contentWords($label);
            $stray = array_values(array_filter(
                $content,
                static fn (string $word): bool => !isset($vocabulary[$word])
                    && !isset($groundedStems[TextTools::stem($word)])
                    && !(ctype_digit($word) && $entry->isFigure($word))
            ));

            // A way around the page ("Voir la boutique", "Nos revendeurs") names
            // what the source's headings and links name; a request verb never
            // takes this path.
            $navigates = array_filter(
                $content,
                static fn (string $word): bool => !isset($navigation[$word]) && !isset($landmarkStems[TextTools::stem($word)])
            ) === [];

            if ($stray !== [] && !$navigates) {
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
