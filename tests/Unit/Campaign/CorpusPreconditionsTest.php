<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusPreconditions;
use AIForge\Campaign\ManifestEntry;
use AIForge\DevTools\Tests\Unit\TestCase;

class CorpusPreconditionsTest extends TestCase
{
    /** 49 distinct-enough words; repeated to reach a length. */
    private const FILLER = 'Nous travaillons le chêne et le noyer dans notre atelier de quartier, avec des assemblages traditionnels, des finitions à la cire et une attention constante portée aux détails que vous remarquerez chaque jour en ouvrant un tiroir ou en posant une tasse sur le plateau de la table familiale. ';

    private function page(string $body, int $fillerRepeats = 13): string
    {
        return "# Atelier Brun\n\n" . $body . "\n\n## Notre métier\n\n" . str_repeat(self::FILLER, $fillerRepeats) . "\n";
    }

    private function entry(array $overrides = []): ManifestEntry
    {
        return ManifestEntry::fromArray($overrides + [
            'id' => 'hard-01', 'traps' => ['T1'], 'figures' => ['1998' => ['depuis 1998']],
        ]);
    }

    public function testACleanPagePasses(): void
    {
        $this->assertSame([], CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998.')));
    }

    public function testAnUnlistedNumberFails(): void
    {
        $errors = CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998, 40 chantiers par an.'));

        $this->assertContains('number 40 is not in figures', $errors);
    }

    public function testAFigurePhraseMustBeInTheText(): void
    {
        $errors = CorpusPreconditions::check($this->entry(['figures' => ['1998' => ['fondée en 1998']]]), $this->page('Menuiserie depuis 1998.'));

        $this->assertContains('figure phrase «fondée en 1998» is not in the text', $errors);
    }

    public function testAuthoringConstraints(): void
    {
        $markdown = $this->page("Menuiserie depuis 1998. Voir <https://brun.fr> et [ici](https://brun.fr/x). Lorem ipsum.\n\n![photo](a.jpg)\n\n# Second titre");
        $errors = CorpusPreconditions::check($this->entry(), $markdown);

        $this->assertContains('contains a < character', $errors);
        $this->assertContains('contains an image', $errors);
        $this->assertContains('contains the leak pattern «lorem ipsum»', $errors);
        $this->assertContains('needs exactly one # title, found 2', $errors);
        $this->assertContains('link https://brun.fr/x is not an example.com URL', $errors);
    }

    public function testLengthBounds(): void
    {
        $errors = CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998.', 2));

        $this->assertMatchesRegularExpression('/^\d+ words, outside 600\.\.2500$/', implode("\n", array_filter($errors, static fn ($e) => str_contains($e, 'words'))));
    }

    public function testT1RejectsPercentages(): void
    {
        $errors = CorpusPreconditions::check($this->entry(), $this->page('Menuiserie depuis 1998, clients satisfaits à cent pour cent.'));

        $this->assertContains('T1 file states a percentage', $errors);
    }

    public function testT2RejectsQuotesAndQuoteShapes(): void
    {
        $entry = $this->entry(['traps' => ['T2'], 'quotes' => [['text' => 'Parfait', 'attribution' => 'Marie']]]);
        $errors = CorpusPreconditions::check($entry, $this->page("Menuiserie depuis 1998.\n\n> « Un travail parfait. » — Marie Durand"));

        $this->assertContains('T2 file lists quotes', $errors);
        $this->assertContains('T2 file holds an attributed quote', $errors);
    }

    public function testT3NeedsExactlyTheTwoGridItems(): void
    {
        $entry = $this->entry(['traps' => ['T3'], 'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']]]);
        $good = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n### Sur mesure\n\nTexte.\n\n### Restauration\n\nTexte.");
        $bad = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n### Sur mesure\n\n### Restauration\n\n### Conseil");

        $this->assertSame([], CorpusPreconditions::check($entry, $good));
        $this->assertContains('T3 section «Nos deux ateliers» holds 3 parallel items, expected Sur mesure | Restauration', CorpusPreconditions::check($entry, $bad));
    }

    public function testT3AcceptsTwoListItems(): void
    {
        $entry = $this->entry(['traps' => ['T3'], 'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']]]);
        $page = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n- **Sur mesure** : cuisines.\n- **Restauration** : meubles anciens.");

        $this->assertSame([], CorpusPreconditions::check($entry, $page));
    }

    public function testT3RejectsWrongHeadingLabelsAtTheSameCount(): void
    {
        $entry = $this->entry(['traps' => ['T3'], 'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']]]);
        $page = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n### Sur mesure avancée\n\nTexte.\n\n### Restauration\n\nTexte.");

        $this->assertContains(
            'T3 section «Nos deux ateliers» items are Sur mesure avancée | Restauration, expected Sur mesure | Restauration',
            CorpusPreconditions::check($entry, $page)
        );
    }

    public function testT3AcceptsListLeadsStartingWithTheGridItems(): void
    {
        $entry = $this->entry(['traps' => ['T3'], 'grid' => ['section' => 'Nos deux ateliers', 'items' => ['Sur mesure', 'Restauration']]]);
        $page = $this->page("Menuiserie depuis 1998.\n\n## Nos deux ateliers\n\n- **Sur mesure avancée** : cuisines.\n- **Restauration** : meubles anciens.");

        $this->assertSame([], CorpusPreconditions::check($entry, $page));
    }

    public function testT4NeedsOneDenseSection(): void
    {
        $entry = $this->entry(['traps' => ['T4']]);
        $flat = "# A\n\nMenuiserie depuis 1998.\n\n## Un\n\n" . str_repeat(self::FILLER, 4) . "\n\n## Deux\n\n" . str_repeat(self::FILLER, 4) . "\n\n## Trois\n\n" . str_repeat(self::FILLER, 4);
        $dense = "# A\n\nMenuiserie depuis 1998.\n\n## Un\n\n" . str_repeat(self::FILLER, 10) . "\n\n## Deux\n\n" . str_repeat(self::FILLER, 2) . "\n\n## Trois\n\n" . str_repeat(self::FILLER, 2);

        $this->assertContains('T4 file has no section of 450+ words at twice the median', CorpusPreconditions::check($entry, $flat));
        $this->assertNotContains('T4 file has no section of 450+ words at twice the median', CorpusPreconditions::check($entry, $dense));
    }

    public function testT5RejectsOffers(): void
    {
        $entry = $this->entry(['traps' => ['T5'], 'offers' => ['meubles']]);

        $this->assertContains('T5 file lists offers', CorpusPreconditions::check($entry, $this->page('Menuiserie depuis 1998, meubles.')));
    }

    public function testLintWarnsOnBoldLinesAndEntityEnding(): void
    {
        $markdown = "# A\n\n## B\n\n**Un**\n\n**Deux**\n\n**Trois**\n\nFin & « suite »";
        $warnings = CorpusPreconditions::lint($markdown);

        $this->assertContains('3 bold line(s) over 2 heading(s): ratio 2.5 would pass the 1.8 heading-inflation gate if each became a heading', $warnings);
        $this->assertContains('last line holds entity-prone characters (&, « »)', $warnings);
    }
}
