<?php

declare(strict_types=1);

namespace AIForge\Demo;

class DemoLicenseProvider
{
    private const OPTION_KEY = 'aiforge_dev_license_scenario';
    private const DEFAULT_SCENARIO = 'valid';

    private const SCENARIOS = [
        'passthrough',
        'valid',
        'expired',
        'invalid',
        'update_available',
    ];

    public static function register(): void
    {
        add_filter('aiforge_license_api_request', [self::class, 'interceptRequest'], 10, 3);
    }

    public static function getScenario(): string
    {
        $scenario = get_option(self::OPTION_KEY, self::DEFAULT_SCENARIO);

        return in_array($scenario, self::SCENARIOS, true) ? $scenario : self::DEFAULT_SCENARIO;
    }

    public static function setScenario(string $scenario): bool
    {
        if (!in_array($scenario, self::SCENARIOS, true)) {
            return false;
        }

        return update_option(self::OPTION_KEY, $scenario);
    }

    public static function getAvailableScenarios(): array
    {
        return self::SCENARIOS;
    }

    /**
     * @return array|null
     */
    public static function interceptRequest(?array $response, string $endpoint, array $body): ?array
    {
        $scenario = self::getScenario();

        if ($scenario === 'passthrough') {
            return null;
        }

        return match ($endpoint) {
            '/licenses/activate' => self::mockActivate($scenario, $body),
            '/licenses/deactivate' => self::mockDeactivate($scenario),
            '/licenses/check' => self::mockCheck($scenario),
            '/updates/check' => self::mockUpdateCheck($scenario),
            '/updates/info' => self::mockUpdateInfo($scenario),
            default => null,
        };
    }

    private static function mockActivate(string $scenario, array $body): array
    {
        return match ($scenario) {
            'invalid' => [
                'success' => false,
                'error_code' => 'invalid',
                'error_message' => 'Invalid license key.',
            ],
            'expired' => [
                'success' => true,
                'data' => self::expiredLicenseData(),
            ],
            default => [
                'success' => true,
                'data' => self::validLicenseData(),
            ],
        };
    }

    private static function mockDeactivate(string $scenario): array
    {
        return [
            'success' => true,
            'data' => [
                'status' => 'not_activated',
            ],
        ];
    }

    private static function mockCheck(string $scenario): array
    {
        return match ($scenario) {
            'expired' => [
                'success' => true,
                'data' => self::expiredLicenseData(),
            ],
            'invalid' => [
                'success' => false,
                'error_code' => 'invalid',
                'error_message' => 'License key not found.',
            ],
            default => [
                'success' => true,
                'data' => self::validLicenseData(),
            ],
        };
    }

    private static function mockUpdateCheck(string $scenario): array
    {
        if ($scenario === 'update_available') {
            return [
                'success' => true,
                'data' => [
                    'version' => '1.0.0',
                    'download_url' => 'https://wp-aiforge.com/releases/wp-ai-forge-1.0.0.zip',
                    'requires_php' => '8.2',
                    'tested' => '6.9.1',
                ],
            ];
        }

        return [
            'success' => true,
            'data' => [
                'version' => defined('AIFORGE_VERSION') ? AIFORGE_VERSION : '0.1.0',
            ],
        ];
    }

    private static function mockUpdateInfo(string $scenario): array
    {
        if ($scenario === 'update_available') {
            return [
                'success' => true,
                'data' => [
                    'name' => 'AI Forge',
                    'slug' => 'wp-ai-forge',
                    'version' => '1.0.0',
                    'author' => 'AI Forge',
                    'homepage' => 'https://wp-aiforge.com',
                    'requires_php' => '8.2',
                    'tested' => '6.9.1',
                    'download_url' => 'https://wp-aiforge.com/releases/wp-ai-forge-1.0.0.zip',
                    'sections' => [
                        'description' => 'AI-powered content generation suite for WordPress.',
                        'changelog' => '<h4>1.0.0</h4><ul><li>Initial stable release</li><li>License system</li></ul>',
                    ],
                ],
            ];
        }

        return [
            'success' => true,
            'data' => [],
        ];
    }

    private static function validLicenseData(): array
    {
        return [
            'status' => 'valid',
            'plan' => 'professional',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 year')),
            'customer_name' => 'Demo User',
        ];
    }

    private static function expiredLicenseData(): array
    {
        return [
            'status' => 'expired',
            'plan' => 'professional',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime('-30 days')),
            'customer_name' => 'Demo User',
        ];
    }
}
