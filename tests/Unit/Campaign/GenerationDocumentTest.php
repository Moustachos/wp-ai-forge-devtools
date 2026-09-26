<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\GenerationDocument;
use AIForge\DevTools\Tests\Unit\TestCase;

class GenerationDocumentTest extends TestCase
{
    use TrapFixtures;

    public function testStatValuesAreDecoded(): void
    {
        $doc = new GenerationDocument('<!-- wp:paragraph --><p class="has-text-align-center is-style-stat-value">15&nbsp;000</p><!-- /wp:paragraph --><p class="is-style-stat-value">l&#8217;an</p>');

        $this->assertSame(['15 000', 'l’an'], $doc->statValues());
        $this->assertTrue($doc->hasStatSlot());
    }

    public function testTestimonialAttributionIsTheNextStrongBeforeTheNextTestimonial(): void
    {
        $html = '<div><p class="is-style-testimonial">« Un travail soigné. »</p>'
            . '<div class="wp-block-group"><figure><img src="x" alt=""/></figure><div><p><strong>Marie Durand</strong></p><p>Lyon</p></div></div>'
            . '<p class="is-style-testimonial">"Rapide."</p><p>Sans nom</p></div>';

        $this->assertSame(
            [['quote' => 'Un travail soigné.', 'attribution' => 'Marie Durand'], ['quote' => 'Rapide.', 'attribution' => null]],
            (new GenerationDocument($html))->testimonials()
        );
    }

    public function testCiteInsideTheTestimonialIsTheAttribution(): void
    {
        $html = '<blockquote class="wp-block-quote is-style-testimonial"><p>Très bien.</p><cite>Paul</cite></blockquote>';

        $this->assertSame([['quote' => 'Très bien.', 'attribution' => 'Paul']], (new GenerationDocument($html))->testimonials());
    }

    public function testCardGridsSkipStatRowsAndWeightedColumns(): void
    {
        $cards = '<div class="wp-block-columns"><div class="wp-block-column"><h3>Sur mesure</h3><p>a</p></div><div class="wp-block-column"><h3>Restauration</h3></div></div>';
        $stats = '<div class="wp-block-columns"><div class="wp-block-column"><p class="is-style-stat-value">1998</p><h3>x</h3></div><div class="wp-block-column"><p class="is-style-stat-value">3</p><h3>y</h3></div></div>';
        $media = '<div class="wp-block-columns"><div class="wp-block-column" style="flex-basis:55%"><h2>Texte</h2></div><div class="wp-block-column" style="flex-basis:45%"><h3>z</h3></div></div>';

        $doc = new GenerationDocument($cards . $stats . $media);

        $this->assertSame([['Sur mesure', 'Restauration']], $doc->cardGrids());
        $this->assertFalse($doc->hasCardGrid(3));
        $this->assertTrue($doc->hasCardGrid(2));
    }

    public function testTheLandingTemplateHasEverySlot(): void
    {
        $template = new GenerationDocument($this->fixture('7014.tpl.html'));

        $this->assertTrue($template->hasStatSlot());
        $this->assertTrue($template->hasTestimonialSlot());
        $this->assertTrue($template->hasCardGrid(3));
        $this->assertTrue($template->hasButton());
    }

    public function testButtonsHeadingsAndLinks(): void
    {
        $html = '<h1>Titre</h1><div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/a?x=1&amp;y=2">Nous <strong>contacter</strong></a></div></div><h2>Deux</h2>';
        $doc = new GenerationDocument($html);

        $this->assertSame(['Nous contacter'], $doc->buttons());
        $this->assertSame(['Titre', 'Deux'], $doc->headings());
        $this->assertSame(['https://example.com/a?x=1&y=2'], $doc->links());
    }

    public function testPlainTextSeparatesBlocksButNotInlineTags(): void
    {
        $doc = new GenerationDocument('<p>Un <strong>mot</strong>s</p><p>Deux</p><!-- wp:paragraph -->');

        $this->assertSame('Un mots Deux', $doc->plainText());
    }
}
