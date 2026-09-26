<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

trait TrapFixtures
{
    private function fixture(string $name): string
    {
        $path = \dirname(__DIR__, 2) . '/fixtures/trap/' . $name;

        if (!is_readable($path)) {
            $this->fail("Missing fixture {$name}; extract it with bench/ci-corpus/fixtures/extract.php.");
        }

        return (string) file_get_contents($path);
    }
}
