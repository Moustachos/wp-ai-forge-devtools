<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\ComboSpec;
use AIForge\DevTools\Tests\Unit\TestCase;
use InvalidArgumentException;

class ComboSpecTest extends TestCase
{
    public function testParsesASingleCombo(): void
    {
        $combo = ComboSpec::parse('gemini:balanced:showcase');

        $this->assertSame('gemini', $combo->provider);
        $this->assertSame('balanced', $combo->preset);
        $this->assertSame('showcase', $combo->templateSlug);
    }

    public function testKeyRoundTripsTheInput(): void
    {
        $this->assertSame('openai:economic:landing-page', ComboSpec::parse('openai:economic:landing-page')->key());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $combo = ComboSpec::parse('  anthropic : performance : case-study  ');

        $this->assertSame('anthropic', $combo->provider);
        $this->assertSame('performance', $combo->preset);
        $this->assertSame('case-study', $combo->templateSlug);
    }

    public function testParsesAList(): void
    {
        $combos = ComboSpec::parseList('gemini:balanced:showcase,openai:economic:manifesto');

        $this->assertCount(2, $combos);
        $this->assertSame('gemini:balanced:showcase', $combos[0]->key());
        $this->assertSame('openai:economic:manifesto', $combos[1]->key());
    }

    public function testIgnoresEmptySegmentsInAList(): void
    {
        $combos = ComboSpec::parseList('gemini:balanced:showcase, ,openai:economic:manifesto,');

        $this->assertCount(2, $combos);
    }

    public function testRejectsWrongFieldCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('gemini:balanced');
    }

    public function testRejectsEmptyField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('gemini::showcase');
    }

    public function testRejectsUnknownProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('mistral:balanced:showcase');
    }

    public function testRejectsUnknownPreset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parse('gemini:turbo:showcase');
    }

    public function testRejectsAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComboSpec::parseList('  ');
    }
}
