<?php

declare(strict_types=1);

namespace AIForge\Demo;

use AIForge\AI\ProviderFactory;
use AIForge\Config\ConfigRepository;

class DemoConfigRepository extends ConfigRepository
{
    public function isProviderConfigured(string $providerId): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    public function getConfiguredProviderIds(): array
    {
        return array_keys(ProviderFactory::getAvailable());
    }
}
