<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\GenerationDocument;
use AIForge\Campaign\ManifestEntry;
use AIForge\Campaign\TrapChecker;
use AIForge\DevTools\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TrapCheckerTestimonialTest extends TestCase
{
    use TrapFixtures;

    private const REAL_QUOTE = 'Les AirFlow Pro sont une référence dans leur catégorie. La qualité audio rivalise avec des casques à 600€.';

    private function airflowEntry(): ManifestEntry
    {
        return ManifestEntry::fromArray(['id' => '6041', 'quotes' => [
            ['text' => self::REAL_QUOTE, 'attribution' => 'Les Numériques'],
            ['text' => 'Confort exceptionnel et autonomie démente. Mon nouveau compagnon de voyage.', 'attribution' => 'CNET'],
            ['text' => 'Probablement le meilleur casque Bluetooth de 2025.', 'attribution' => 'What Hi-Fi?'],
        ]]);
    }

    private function check(ManifestEntry $entry, string $id, ?string $output = null): array
    {
        return (new TrapChecker())->check($entry, $this->fixture("{$id}.md"), $this->fixture("{$id}.tpl.html"), $output ?? $this->fixture("{$id}.html"));
    }

    public function testARealQuoteWithItsRealAttributionPasses(): void
    {
        $result = $this->check($this->airflowEntry(), '6041');

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status'], implode("\n", $result['testimonial_grounding']['findings']));
        $this->assertSame(TrapChecker::PASS, $result['testimonial_repurposed']['status']);
    }

    public function testAnInventedQuoteFails(): void
    {
        $html = $this->fixture('6041.html');
        $this->assertStringContainsString(self::REAL_QUOTE, (new GenerationDocument($html))->testimonials()[0]['quote']);

        $invented = str_replace(self::REAL_QUOTE, 'Un casque qui a changé ma façon de travailler au quotidien, je le recommande sans hésiter.', $html);
        $result = $this->check($this->airflowEntry(), '6041', $invented);

        $this->assertSame(TrapChecker::FAIL, $result['testimonial_grounding']['status']);
        $this->assertStringContainsString('matches no source sentence', $result['testimonial_grounding']['findings'][0]);
    }

    public function testSourceProseStyledAsTestimonialIsRepurposedOnARealRun(): void
    {
        $result = $this->check(ManifestEntry::fromArray(['id' => '7335']), '7335');

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status'], implode("\n", $result['testimonial_grounding']['findings']));
        $this->assertSame(TrapChecker::FAIL, $result['testimonial_repurposed']['status']);
        $this->assertStringContainsString('source prose styled as a testimonial', implode("\n", $result['testimonial_repurposed']['findings']));
    }

    public function testSourceWordsCreditedToSomeoneQuotedNowhereFail(): void
    {
        $html = $this->fixture('6041.html');
        $this->assertStringContainsString('<strong>Les Numériques</strong>', $html);

        $tampered = str_replace('<strong>Les Numériques</strong>', '<strong>Marie Dupont</strong>', $html);
        $this->assertStringContainsString('<strong>Marie Dupont</strong>', $tampered);

        $result = $this->check($this->airflowEntry(), '6041', $tampered);

        $this->assertSame(TrapChecker::FAIL, $result['testimonial_grounding']['status']);
        $this->assertStringContainsString('who is quoted nowhere', implode("\n", $result['testimonial_grounding']['findings']));
    }

    public function testSourceProseCreditedToTheBusinessItselfIsRepurposed(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# Atelier Brun\n\nNous fabriquons chaque meuble à la main, dans le chêne massif de la région.\n";
        $output = '<p class="is-style-testimonial">« Nous fabriquons chaque meuble à la main, dans le chêne massif. »</p><p><strong>L\'atelier</strong></p>';

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), $markdown, $template, $output);

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status'], implode("\n", $result['testimonial_grounding']['findings']));
        $this->assertSame(TrapChecker::FAIL, $result['testimonial_repurposed']['status']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inventedAttributions(): array
    {
        return [
            'a generic patient the source talks about' => ['Un patient'],
            'a generic family' => ['Une famille'],
            'a role' => ['Manager'],
            'a person named only in the body' => ['Julie Roche'],
            'a team that is not the business' => ["L'équipe de Julie Roche"],
        ];
    }

    #[DataProvider('inventedAttributions')]
    public function testSourceProseCreditedToAnyoneButTheBusinessNameIsInvented(string $attribution): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# Cabinet Lefèvre\n\nChaque patient est reçu sans attente, avec une famille si besoin. Julie Roche, manager du cabinet, organise le planning.\n";
        $output = "<p class=\"is-style-testimonial\">« Chaque patient est reçu sans attente, avec une famille si besoin. »</p><p><strong>{$attribution}</strong></p>";

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), $markdown, $template, $output);

        $this->assertSame(TrapChecker::FAIL, $result['testimonial_grounding']['status']);
        $this->assertStringContainsString('who is quoted nowhere', implode("\n", $result['testimonial_grounding']['findings']));
    }

    public function testSourceProseWithoutAttributionIsRepurposed(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# T\n\nNous réparons les vélos de tout le quartier depuis la rue principale.\n";
        $output = '<p class="is-style-testimonial">« Nous réparons les vélos de tout le quartier. »</p>';

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), $markdown, $template, $output);

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status']);
        $this->assertSame(TrapChecker::FAIL, $result['testimonial_repurposed']['status']);
    }

    public function testAQuoteSpanningTwoSentencesIsMatched(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# T\n\nLe chantier a duré trois semaines. Tout était propre chaque soir, sans exception.\n";
        $output = '<p class="is-style-testimonial">« Le chantier a duré trois semaines. Tout était propre chaque soir. »</p>';

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), $markdown, $template, $output);

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status']);
    }

    public function testAFourSentenceQuoteOfSourceProseIsRepurposedOnARealRun(): void
    {
        $result = $this->check(ManifestEntry::fromArray(['id' => '7472']), '7472');

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status'], implode("\n", $result['testimonial_grounding']['findings']));
        $this->assertStringContainsString('«Le métier de fromager', implode("\n", $result['testimonial_repurposed']['findings']));
    }

    public function testTheAttributionIsTheNameLineNotABoldPhraseInTheContinuationOnARealRun(): void
    {
        $result = $this->check(ManifestEntry::fromArray(['id' => '7374']), '7374');

        $this->assertSame(TrapChecker::FAIL, $result['testimonial_grounding']['status']);
        $this->assertStringContainsString('credited to «Laurent Ferrand»', implode("\n", $result['testimonial_grounding']['findings']));
    }

    public function testSourceProseCreditedToTheBusinessTeamIsRepurposedOnARealRun(): void
    {
        $result = $this->check(ManifestEntry::fromArray(['id' => '7394']), '7394');

        $this->assertSame(TrapChecker::PASS, $result['testimonial_grounding']['status'], implode("\n", $result['testimonial_grounding']['findings']));
        $this->assertStringContainsString('«Shiftloom was founded', implode("\n", $result['testimonial_repurposed']['findings']));
    }

    public function testAnInventedQuoteSpanningSeveralSentencesStillFails(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# T\n\nLe chantier a duré trois semaines. Tout était propre chaque soir. Les menuisiers sont venus à l'heure. La facture correspondait au devis.\n";
        $output = '<p class="is-style-testimonial">« Une équipe formidable. Je recommande vivement ce professionnel à mes voisins. Merci encore pour votre gentillesse. »</p>';

        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), $markdown, $template, $output);

        $this->assertSame(TrapChecker::FAIL, $result['testimonial_grounding']['status']);
    }

    public function testAnAttributionWithARoleStillMatches(): void
    {
        $template = '<p class="is-style-testimonial">"x"</p>';
        $markdown = "# T\n\n> « Un atelier sérieux et ponctuel. » — Marie Durand\n";
        $entry = ManifestEntry::fromArray(['id' => 'x', 'quotes' => [['text' => 'Un atelier sérieux et ponctuel.', 'attribution' => 'Marie Durand']]]);
        $output = '<p class="is-style-testimonial">« Un atelier sérieux et ponctuel. »</p><p><strong>Marie Durand, gérante</strong></p>';

        $this->assertSame(TrapChecker::PASS, (new TrapChecker())->check($entry, $markdown, $template, $output)['testimonial_grounding']['status']);
    }

    public function testNoTestimonialSlotIsNotApplicable(): void
    {
        $result = (new TrapChecker())->check(ManifestEntry::fromArray(['id' => 'x']), "# T\n", '<p>x</p>', '<p class="is-style-testimonial">« Inventé de toutes pièces. »</p>');

        $this->assertSame(TrapChecker::NA, $result['testimonial_grounding']['status']);
        $this->assertSame(TrapChecker::NA, $result['testimonial_repurposed']['status']);
    }
}
