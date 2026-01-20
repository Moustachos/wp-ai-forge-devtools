<?php

declare(strict_types=1);

namespace AIForge;

class DevTools
{
    private static ?self $instance = null;

    private function __construct(
        private readonly string $pluginFile
    ) {
      // TODO: init dev mode etc
    }

    public static function boot(string $pluginFile): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self($pluginFile);
        self::$instance->init();
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }
}
