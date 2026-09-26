<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\CorpusManifest;
use AIForge\Campaign\CorpusPreconditions;
use AIForge\DevTools\Tests\Unit\TestCase;

/**
 * The frozen corpus itself: schema, invariants, and every file's preconditions.
 */
class HardCorpusTest extends TestCase
{
    private static function manifest(): CorpusManifest
    {
        return CorpusManifest::fromName(\dirname(__DIR__, 3) . '/bench/ci-corpus', 'hard');
    }

    public function testManifestHasNoErrors(): void
    {
        $this->assertSame([], self::manifest()->errors());
    }

    public function testEveryFilePassesItsPreconditions(): void
    {
        $manifest = self::manifest();
        $failures = [];

        foreach ($manifest->entries as $entry) {
            foreach (CorpusPreconditions::check($entry, (string) $manifest->markdown($entry)) as $error) {
                $failures[] = "{$entry->id}: {$error}";
            }
        }

        $this->assertSame([], $failures);
    }
}
