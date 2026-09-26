<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\ManifestEntry;
use AIForge\DevTools\Tests\Unit\TestCase;

class ManifestEntryTest extends TestCase
{
    public function testIntegerJsonKeysStillMatchStringFigures(): void
    {
        // json_decode turns {"1998": [...]} into an int key.
        $entry = ManifestEntry::fromArray(json_decode('{"id":"x","figures":{"1998":["depuis 1998"]}}', true));

        $this->assertTrue($entry->isFigure('1998'));
    }

    public function testFigureKeysAreNormalised(): void
    {
        $entry = ManifestEntry::fromArray(['id' => 'x', 'figures' => ['15 000' => ['15 000 exemplaires'], '4,8' => ['4,8 sur 5']]]);

        $this->assertTrue($entry->isFigure('15000'));
        $this->assertTrue($entry->isFigure('4.8'));
        $this->assertSame(['15 000 exemplaires'], $entry->phrasesFor('15000'));
        $this->assertFalse($entry->isFigure('10'));
        $this->assertSame([], $entry->phrasesFor('10'));
    }

    public function testMissingOptionalFieldsDefaultToEmpty(): void
    {
        $entry = ManifestEntry::fromArray(['id' => 'x']);

        $this->assertSame([], $entry->traps);
        $this->assertSame([], $entry->quotes);
        $this->assertSame([], $entry->offers);
        $this->assertNull($entry->grid);
        $this->assertSame('x.md', $entry->file);
    }

    public function testHasTrap(): void
    {
        $entry = ManifestEntry::fromArray(['id' => 'x', 'traps' => ['T1', 'T3']]);

        $this->assertTrue($entry->hasTrap('T3'));
        $this->assertFalse($entry->hasTrap('T2'));
    }
}
