<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\PluginCommit;
use AIForge\DevTools\Tests\Unit\TestCase;

class PluginCommitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/commit-' . bin2hex(random_bytes(4));
        mkdir("{$this->dir}/.git/refs/heads", 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->dir);
        parent::tearDown();
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$dir}/{$entry}";

            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function testReadsTheBranchRef(): void
    {
        file_put_contents("{$this->dir}/.git/HEAD", "ref: refs/heads/feat/x\n");
        mkdir("{$this->dir}/.git/refs/heads/feat");
        file_put_contents("{$this->dir}/.git/refs/heads/feat/x", str_repeat('a', 40) . "\n");

        $this->assertSame(str_repeat('a', 40), PluginCommit::read($this->dir));
    }

    public function testFallsBackToPackedRefs(): void
    {
        file_put_contents("{$this->dir}/.git/HEAD", "ref: refs/heads/main\n");
        file_put_contents("{$this->dir}/.git/packed-refs", "# pack-refs\n" . str_repeat('b', 40) . " refs/heads/main\n");

        $this->assertSame(str_repeat('b', 40), PluginCommit::read($this->dir));
    }

    public function testDetachedHead(): void
    {
        file_put_contents("{$this->dir}/.git/HEAD", str_repeat('c', 40) . "\n");

        $this->assertSame(str_repeat('c', 40), PluginCommit::read($this->dir));
    }

    public function testNoRepositoryIsNull(): void
    {
        $this->assertNull(PluginCommit::read($this->dir . '/none'));
    }
}
