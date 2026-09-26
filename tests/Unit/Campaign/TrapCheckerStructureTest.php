<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CheckSettings;
use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\ManifestEntry;
use AIForge\Campaign\TrapChecker;
use AIForge\DevTools\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TrapCheckerStructureTest extends TestCase
{
    use TrapFixtures;

    private const GRID_TEMPLATE = '<div class="wp-block-columns"><div class="wp-block-column"><h3>A</h3></div><div class="wp-block-column"><h3>B</h3></div><div class="wp-block-column"><h3>C</h3></div></div>'
        . '<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link">Go</a></div></div>';

    private const SOURCE = "# Atelier Brun\n\nMenuiserie de quartier, meubles en chêne massif pour toute la maison.\n\n## Nos deux ateliers\n\n### Sur mesure\n\nNous dessinons et fabriquons chaque meuble pour la pièce qui l'attend, du placard sous l'escalier à la bibliothèque du salon.\n\n### Restauration\n\n**Tables anciennes**\n\nNous reprenons les tables de famille, les chaises paillées et les commodes abîmées par le temps.\n\n[Nous écrire](https://example.com/contact)\n";

    private function entry(array $extra = []): ManifestEntry
    {
        return ManifestEntry::fromArray($extra + [
            'id' => 'x',
            'traps' => ['T3', 'T5'],
            'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']],
            'offers' => [],
        ]);
    }

    private function checker(): TrapChecker
    {
        return new TrapChecker(new CheckSettings(ctaVocabulary: ['réserver', 'votre', 'nous']));
    }

    private function grid(string ...$headings): string
    {
        $cols = array_map(static fn (string $h): string => "<div class=\"wp-block-column\"><h3>{$h}</h3><p>texte</p></div>", $headings);

        return '<div class="wp-block-columns">' . implode('', $cols) . '</div>';
    }

    public function testTwoCardsForTwoItemsPass(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, $this->grid('Sur mesure', 'Restauration'));

        $this->assertSame(TrapChecker::PASS, $result['card_count']['status']);
    }

    public function testAThirdCardFails(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, $this->grid('Sur mesure', 'Restauration', 'Conseil déco'));

        $this->assertSame(TrapChecker::FAIL, $result['card_count']['status']);
        $this->assertSame(['3 cards for 2 items: Sur mesure | Restauration | Conseil déco'], $result['card_count']['findings']);
    }

    public function testRenamedItemsWithAnInventedThirdFail(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, $this->grid('Meubles uniques', 'Remise à neuf', 'Conseil déco'));

        $this->assertSame(TrapChecker::FAIL, $result['card_count']['status']);
        $this->assertSame(['3 cards for 2 items, none matching an item: Meubles uniques | Remise à neuf | Conseil déco'], $result['card_count']['findings']);
    }

    public function testTwoRenamedCardsPass(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, $this->grid('Meubles uniques', 'Remise à neuf'));

        $this->assertSame(TrapChecker::PASS, $result['card_count']['status']);
    }

    public function testACardMatchesAnItemWhoseWordsItHolds(): void
    {
        $entry = $this->entry(['grid' => ['section' => 'Nos services', 'items' => ['Infogérance du parc', 'Sauvegarde']]]);
        $output = $this->grid('Sauvegarde', 'Support') . $this->grid('Gestion de votre parc', 'Sauvegarde', 'Formation');

        $result = $this->checker()->check($entry, self::SOURCE, self::GRID_TEMPLATE, $output);

        $this->assertSame(TrapChecker::FAIL, $result['card_count']['status']);
        $this->assertSame(['3 cards for 2 items: Gestion de votre parc | Sauvegarde | Formation'], $result['card_count']['findings']);
    }

    public function testItemsRenderedOutsideAGridPass(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, self::GRID_TEMPLATE, '<h2>Nos deux ateliers</h2><p>Sur mesure, restauration.</p>');

        $this->assertSame(TrapChecker::PASS, $result['card_count']['status']);
    }

    public function testCardCountNeedsAGridInTheTemplateAndTheEntry(): void
    {
        $noGrid = $this->checker()->check($this->entry(), self::SOURCE, '<p>x</p>', $this->grid('A', 'B', 'C'));
        $noItems = $this->checker()->check(ManifestEntry::fromArray(['id' => 'x']), self::SOURCE, self::GRID_TEMPLATE, $this->grid('A', 'B', 'C'));

        $this->assertSame(TrapChecker::NA, $noGrid['card_count']['status']);
        $this->assertSame(TrapChecker::NA, $noItems['card_count']['status']);
    }

    /**
     * @return array<string, array{string, string[], string}>
     */
    public static function ctaCases(): array
    {
        return [
            'generic invitation' => ['Contactez-nous', [], TrapChecker::PASS],
            'generic with different case and spacing' => ['  EN SAVOIR PLUS ', [], TrapChecker::PASS],
            'offer word with a tolerated verb' => ['Réserver votre bilan', ['bilan initial'], TrapChecker::PASS],
            'offer word absent from offers' => ['Réserver un bilan', [], TrapChecker::FAIL],
            'invented trial' => ['Démarrer l\'essai gratuit', [], TrapChecker::FAIL],
        ];
    }

    /**
     * @param string[] $offers
     */
    #[DataProvider('ctaCases')]
    public function testCtaGrounding(string $label, array $offers, string $expected): void
    {
        $output = "<div class=\"wp-block-button\"><a class=\"wp-block-button__link\">{$label}</a></div>";

        $result = $this->checker()->check($this->entry(['offers' => $offers]), self::SOURCE, self::GRID_TEMPLATE, $output);

        $this->assertSame($expected, $result['cta_grounding']['status'], implode("\n", $result['cta_grounding']['findings']));
    }

    /**
     * @return array<string, array{string, string[], string, string}>
     */
    public static function frozenVocabularyCtaCases(): array
    {
        return [
            'navigation verb and the business name' => ["Découvrir l'atelier →", [], 'Atelier Brun', TrapChecker::PASS],
            'portfolio' => ['Nos réalisations', [], 'Atelier Brun', TrapChecker::PASS],
            'singular of a plural offer' => ['Réserver une dégustation', ['dégustations commentées'], 'Domaine des Coteaux', TrapChecker::PASS],
            'verb of a noun offer' => ['Estimer ma reprise', ['estimation sans engagement', 'reprise de votre ancien véhicule'], 'Garage Martin', TrapChecker::PASS],
            'the business names itself' => ['Contacter le cabinet', [], 'Cabinet Lefèvre, kinésithérapie', TrapChecker::PASS],
            'calling the workshop' => ["Appeler l'atelier", [], 'Atelier Brun', TrapChecker::PASS],
            'conjugated verb and an offered quote' => ['Demandez votre devis', ['devis'], 'Atelier Brun', TrapChecker::PASS],
            'joining' => ['Nous rejoindre', [], 'Association du quartier', TrapChecker::PASS],
            'invented trial' => ["Démarrer l'essai gratuit", [], 'Atelier Brun', TrapChecker::FAIL],
            'invented assessment' => ['Réserver un bilan', [], 'Atelier Brun', TrapChecker::FAIL],
            'invented quote' => ['Obtenir un devis gratuit', [], 'Atelier Brun', TrapChecker::FAIL],
            'invented English trial' => ['Book a free trial', [], 'Atelier Brun', TrapChecker::FAIL],
            'invented consultation beside an offered trial' => ['Free consultation', ['free trial'], 'Shiftloom: staff scheduling for teams that work in shifts', TrapChecker::FAIL],
            "the title's own business word" => ['Schedule a call', ['free trial', 'book a demo'], 'Shiftloom: staff scheduling for teams that work in shifts', TrapChecker::PASS],
            'starting an offered trial' => ['Start a free trial →', ['free trial', 'book a demo'], 'Shiftloom: staff scheduling for teams that work in shifts', TrapChecker::PASS],
            'navigating to a section the page names' => ['Voir la restauration', [], 'Atelier Brun', TrapChecker::PASS],
            'a section name alone' => ['Nos ateliers →', [], 'Atelier Brun', TrapChecker::PASS],
            'navigating to how the business works' => ['Voir notre méthode', [], 'Atelier Brun', TrapChecker::PASS],
            'coming to the shop' => ['Nous rendre visite', [], 'Atelier Brun', TrapChecker::PASS],
            'finding the shop' => ['Nous trouver', [], 'Atelier Brun', TrapChecker::PASS],
            'requesting what a heading names' => ['Demander une restauration', [], 'Atelier Brun', TrapChecker::FAIL],
            'navigating to a resource the page lacks' => ['Découvrir le guide', [], 'Atelier Brun', TrapChecker::FAIL],
            'navigating to an invented visit' => ['Voir les visites', [], 'Atelier Brun', TrapChecker::FAIL],
        ];
    }

    private const INVENTED_OFFER_LABELS = [
        'Réserver une visite',
        'Demandez votre visite',
        'Demander une consultation',
        'Réserver une consultation',
        'Consultation',
        'Book a consultation',
        'Request a consultation',
        'Réservation',
        'Appel découverte',
        'Réserver un appel',
        'Book a discovery call',
        'Réserver votre rencontre',
        'Demander un échange',
        'Demander un échantillon',
        "Découvrir l'essai gratuit",
        'Voir les tarifs',
        'Découvrir la consultation',
        'Voir nos rendez-vous',
        'See a free demo',
        'Discover our booking',
        'Voir le devis',
    ];

    /**
     * Every T5 file of the frozen corpus that offers nothing, crossed with the labels that invent an offer.
     *
     * @return array<string, array{string, string}>
     */
    public static function inventedOfferOnT5Cases(): array
    {
        $manifest = CorpusManifest::fromName(\dirname(__DIR__, 3) . '/bench/ci-corpus', 'hard');
        $cases = [];

        foreach ($manifest->entries as $entry) {
            if (!\in_array('T5', $entry->traps, true) || $entry->offers !== []) {
                continue;
            }

            foreach (self::INVENTED_OFFER_LABELS as $label) {
                $cases["{$label} @ {$entry->id}"] = [$label, $entry->id];
            }
        }

        return $cases;
    }

    #[DataProvider('inventedOfferOnT5Cases')]
    public function testAnInventedOfferFailsOnAT5FileWithoutOffers(string $label, string $entryId): void
    {
        $manifest = CorpusManifest::fromName(\dirname(__DIR__, 3) . '/bench/ci-corpus', 'hard');
        $entry = $manifest->entry($entryId);
        $output = "<div class=\"wp-block-button\"><a class=\"wp-block-button__link\">{$label}</a></div>";

        $result = (new TrapChecker($manifest->settings))->check($entry, (string) $manifest->markdown($entry), self::GRID_TEMPLATE, $output);

        $this->assertSame(TrapChecker::FAIL, $result['cta_grounding']['status']);
    }

    public function testTheFrozenCorpusHasFourT5FilesWithoutOffers(): void
    {
        $this->assertCount(4 * \count(self::INVENTED_OFFER_LABELS), self::inventedOfferOnT5Cases());
    }

    /**
     * @param string[] $offers
     */
    #[DataProvider('frozenVocabularyCtaCases')]
    public function testCtaGroundingWithTheFrozenVocabulary(string $label, array $offers, string $title, string $expected): void
    {
        $settings = CorpusManifest::fromName(\dirname(__DIR__, 3) . '/bench/ci-corpus', 'hard')->settings;
        $source = str_replace('# Atelier Brun', "# {$title}", self::SOURCE);
        $output = "<div class=\"wp-block-button\"><a class=\"wp-block-button__link\">{$label}</a></div>";

        $result = (new TrapChecker($settings))->check($this->entry(['offers' => $offers]), $source, self::GRID_TEMPLATE, $output);

        $this->assertSame($expected, $result['cta_grounding']['status'], implode("\n", $result['cta_grounding']['findings']));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function navigationLabelRuns(): array
    {
        return [
            '«Voir la méthode» on 7477' => ['7477', 'hard-11-infogerance'],
            '«Call 555-0163» on 7400' => ['7400', 'hard-10-vet-clinic'],
        ];
    }

    #[DataProvider('navigationLabelRuns')]
    public function testNavigationLabelsPassOnARealRun(string $id, string $entryId): void
    {
        $manifest = CorpusManifest::fromName(\dirname(__DIR__, 3) . '/bench/ci-corpus', 'hard');

        $result = (new TrapChecker($manifest->settings))->check(
            $manifest->entry($entryId),
            $this->fixture("{$id}.md"),
            $this->fixture("{$id}.tpl.html"),
            $this->fixture("{$id}.html")
        );

        $this->assertSame(TrapChecker::PASS, $result['cta_grounding']['status'], implode("\n", $result['cta_grounding']['findings']));
    }

    public function testCtaNeedsAButtonInTheTemplate(): void
    {
        $result = $this->checker()->check($this->entry(), self::SOURCE, '<p>x</p>', '<a class="wp-block-button__link">Essai gratuit</a>');

        $this->assertSame(TrapChecker::NA, $result['cta_grounding']['status']);
    }

    public function testOrphanHeadings(): void
    {
        $output = '<h1>Atelier Brun</h1><h2>Nos ateliers</h2><h3>Tables anciennes</h3><h2>Pourquoi nous choisir</h2>';

        $result = $this->checker()->check($this->entry(), self::SOURCE, '', $output);

        $this->assertSame(['«Pourquoi nous choisir»'], $result['orphan_headings']['findings']);
    }

    public function testContentRetentionFlagsTheDroppedSection(): void
    {
        $output = '<h1>Atelier Brun</h1><p>Menuiserie de quartier, meubles en chêne massif pour toute la maison.</p>'
            . '<p>Nous dessinons et fabriquons chaque meuble pour la pièce qui l\'attend, du placard sous l\'escalier à la bibliothèque du salon.</p>';

        $result = $this->checker()->check($this->entry(), self::SOURCE, '', $output);

        $this->assertSame(TrapChecker::FAIL, $result['content_retention']['status']);
        $this->assertCount(1, $result['content_retention']['findings']);
        $this->assertStringStartsWith('Nos deux ateliers: 0.', $result['content_retention']['findings'][0]);
    }

    public function testLinkPreservation(): void
    {
        $kept = $this->checker()->check($this->entry(), self::SOURCE, '', '<a href="https://example.com/contact">x</a>');
        $lost = $this->checker()->check($this->entry(), self::SOURCE, '', '<a href="https://example.com/autre">x</a>');

        $this->assertSame(TrapChecker::PASS, $kept['link_preservation']['status']);
        $this->assertSame(['https://example.com/contact'], $lost['link_preservation']['findings']);
    }
}
