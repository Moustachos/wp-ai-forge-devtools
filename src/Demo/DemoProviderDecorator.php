<?php

declare(strict_types=1);

namespace AIForge\Demo;

use AIForge\AI\CompletionResult;
use AIForge\Contracts\ProviderInterface;

class DemoProviderDecorator implements ProviderInterface
{
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly MockResponseGenerator $mockGenerator
    ) {
    }

    public function getId(): string
    {
        return $this->provider->getId();
    }

    public function getName(): string
    {
        return $this->provider->getName();
    }

    public function configure(array $credentials): void
    {
        // No-op in demo mode - credentials not needed
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function validateCredentials(): bool
    {
        // Simulate network latency
        usleep(random_int(500000, 1000000));
        return true;
    }

    public function complete(string $prompt, array $options = []): CompletionResult
    {
        // Simulate network latency (1-2 seconds)
        usleep(random_int(1000000, 2000000));

        return $this->mockGenerator->generate($prompt, $options);
    }
}
