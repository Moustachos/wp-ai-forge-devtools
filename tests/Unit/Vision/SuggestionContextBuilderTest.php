<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Vision;

use AIForge\DevTools\Tests\Unit\TestCase;
use AIForge\Vision\SuggestionContextBuilder;

/**
 * Mirrors admin/src/editor/contextExtractor.js. Any drift between the two
 * means the bench measures a context the editor never sends.
 */
class SuggestionContextBuilderTest extends TestCase
{
    /** @return array<string, mixed> */
    private function block(string $name, string $html = '', array $inner = []): array
    {
        return ['blockName' => $name, 'attrs' => [], 'innerHTML' => $html, 'innerBlocks' => $inner];
    }

    public function testFlattenDropsContainersButKeepsCoverAndRecursesIntoIt(): void
    {
        $blocks = [
            $this->block('core/group', '', [
                $this->block('core/columns', '', [
                    $this->block('core/column', '', [
                        $this->block('core/paragraph', '<p>dans la colonne</p>'),
                    ]),
                ]),
            ]),
            $this->block('core/cover', '<div>fond</div>', [
                $this->block('core/heading', '<h2>titre du cover</h2>'),
            ]),
        ];

        $flat = SuggestionContextBuilder::flatten($blocks);
        $names = array_column($flat, 'blockName');

        $this->assertSame(
            ['core/paragraph', 'core/cover', 'core/heading'],
            $names,
            'containers vanish, cover stays and its children follow'
        );
    }

    public function testTakesTheNearestHeadingAboveTheTarget(): void
    {
        $blocks = [
            $this->block('core/heading', '<h2>Loin</h2>'),
            $this->block('core/paragraph', '<p>bla</p>'),
            $this->block('core/heading', '<h2>Proche</h2>'),
            $this->block('core/image', '<figure><img/></figure>'),
        ];

        $ctx = SuggestionContextBuilder::build($blocks, 3, 42, 'Mon titre', 'post');

        $this->assertSame('Proche', $ctx['section_heading']);
    }

    public function testCollectsThreeTextBlocksBeforeAndTwoAfterInReadingOrder(): void
    {
        $blocks = [
            $this->block('core/paragraph', '<p>un</p>'),
            $this->block('core/paragraph', '<p>deux</p>'),
            $this->block('core/paragraph', '<p>trois</p>'),
            $this->block('core/paragraph', '<p>quatre</p>'),
            $this->block('core/image', '<figure><img/></figure>'),
            $this->block('core/paragraph', '<p>cinq</p>'),
            $this->block('core/paragraph', '<p>six</p>'),
            $this->block('core/paragraph', '<p>sept</p>'),
        ];

        $ctx = SuggestionContextBuilder::build($blocks, 4, 1, 'T', 'post');

        $this->assertSame('deux trois quatre', $ctx['preceding_text'], 'the three nearest, oldest first');
        $this->assertSame('cinq six', $ctx['following_text']);
    }

    public function testImagePositionIsRelativeToTheOtherImages(): void
    {
        $blocks = [
            $this->block('core/image', '<figure><img/></figure>'),
            $this->block('core/paragraph', '<p>x</p>'),
            $this->block('core/cover', '<div></div>'),
            $this->block('core/paragraph', '<p>y</p>'),
            $this->block('core/image', '<figure><img/></figure>'),
        ];

        $this->assertSame('first', SuggestionContextBuilder::build($blocks, 0, 1, 'T', 'post')['image_position']);
        $this->assertSame('middle', SuggestionContextBuilder::build($blocks, 2, 1, 'T', 'post')['image_position']);
        $this->assertSame('last', SuggestionContextBuilder::build($blocks, 4, 1, 'T', 'post')['image_position']);
    }

    public function testTruncatesAtTwoHundredWords(): void
    {
        $long = implode(' ', array_fill(0, 300, 'mot'));
        $blocks = [
            $this->block('core/paragraph', '<p>' . $long . '</p>'),
            $this->block('core/image', '<figure><img/></figure>'),
        ];

        $ctx = SuggestionContextBuilder::build($blocks, 1, 1, 'T', 'post');

        $this->assertStringEndsWith('...', $ctx['preceding_text']);
        $this->assertSame(200, \count(explode(' ', str_replace('...', '', trim($ctx['preceding_text'])))));
    }

    public function testReadsTextFromInnerHtmlSincePhpHasNoContentAttribute(): void
    {
        $blocks = [
            $this->block('core/paragraph', "<p>Du <strong>gras</strong> et un &amp; entit&eacute;</p>"),
            $this->block('core/image', '<figure><img/></figure>'),
        ];

        $ctx = SuggestionContextBuilder::build($blocks, 1, 1, 'T', 'post');

        $this->assertSame('Du gras et un & entité', $ctx['preceding_text']);
    }
}
