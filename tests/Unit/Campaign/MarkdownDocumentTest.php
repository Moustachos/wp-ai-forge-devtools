<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\MarkdownDocument;
use AIForge\DevTools\Tests\Unit\TestCase;

class MarkdownDocumentTest extends TestCase
{
    private const PAGE = <<<MD
# Atelier Brun

Menuiserie depuis 1998. [Nous écrire](https://example.com/contact).

## Nos deux ateliers

### Sur mesure

Des **meubles** pensés pour vos murs.

### Restauration

**Ce que nous restaurons :**

- **Tables anciennes** : décapage et cire.
- Chaises paillées.

## Contact

Passez nous voir. Ou appelez-nous !
MD;

    public function testTitle(): void
    {
        $this->assertSame('Atelier Brun', (new MarkdownDocument(self::PAGE))->title());
    }

    public function testSectionsCutAtLevelTwoAndKeepTheIntro(): void
    {
        $sections = (new MarkdownDocument(self::PAGE))->sections();

        $this->assertSame(['[intro]', 'Nos deux ateliers', 'Contact'], array_column($sections, 'heading'));
        $this->assertStringContainsString('# Atelier Brun', $sections[0]['body']);
        $this->assertStringContainsString('### Restauration', $sections[1]['body']);
    }

    public function testHeadingsCarryTheirLevel(): void
    {
        $headings = (new MarkdownDocument(self::PAGE))->headings();

        $this->assertSame(['level' => 1, 'text' => 'Atelier Brun'], $headings[0]);
        $this->assertSame(['level' => 3, 'text' => 'Sur mesure'], $headings[2]);
        $this->assertCount(5, $headings);
    }

    public function testBoldLinesAreStandaloneOnly(): void
    {
        $this->assertSame(['Ce que nous restaurons'], (new MarkdownDocument(self::PAGE))->boldLines());
    }

    public function testBoldLeadsAddListItemLeads(): void
    {
        $this->assertSame(
            ['Ce que nous restaurons', 'Tables anciennes'],
            (new MarkdownDocument(self::PAGE))->boldLeads()
        );
    }

    public function testLinks(): void
    {
        $this->assertSame(['https://example.com/contact'], (new MarkdownDocument(self::PAGE))->links());
    }

    public function testPlainTextDropsSyntaxAndUrls(): void
    {
        $plain = (new MarkdownDocument(self::PAGE))->plainText();

        $this->assertStringContainsString('Nous écrire.', $plain);
        $this->assertStringNotContainsString('example.com', $plain);
        $this->assertStringNotContainsString('**', $plain);
        $this->assertStringNotContainsString('#', $plain);
        $this->assertStringContainsString('Tables anciennes : décapage et cire.', $plain);
    }

    public function testSentencesSplitOnPunctuationAndLines(): void
    {
        $sentences = (new MarkdownDocument(self::PAGE))->sentences();

        $this->assertContains('Passez nous voir.', $sentences);
        $this->assertContains('Ou appelez-nous !', $sentences);
    }
}
