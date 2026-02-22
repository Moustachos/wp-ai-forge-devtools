<?php

declare(strict_types=1);

namespace AIForge\Demo;

use AIForge\License\LicenseClient;

/**
 * Redirects AI Forge license API requests to a local Laravel server.
 *
 * Hooks into WordPress pre_http_request filter to transparently rewrite
 * requests targeting the production license API to the local dev server.
 * No hooks needed in the core plugin — this operates at the WP HTTP layer.
 */
class DemoLicenseProvider
{
    private const OPTION_KEY = 'aiforge_dev_license_scenario';
    private const DEFAULT_SCENARIO = 'passthrough';

    private const LOCAL_SERVER_URL = 'http://host.docker.internal/api/v1';

    private const SCENARIOS = [
        'passthrough',
        'local_server',
    ];

    public static function register(): void
    {
        add_filter('pre_http_request', [self::class, 'interceptHttpRequest'], 10, 3);
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
     * Intercept WP HTTP requests targeting the AI Forge license API.
     *
     * When the local_server scenario is active, rewrites the request URL
     * to point to the local Laravel server (HTTP, no SSL verification).
     *
     * @param  false|array|\WP_Error $preempt  Short-circuit value (false = don't short-circuit)
     * @param  array                 $parsedArgs  Request arguments from wp_remote_*
     * @param  string                $url         Target URL
     * @return false|array|\WP_Error
     */
    public static function interceptHttpRequest(false|array|\WP_Error $preempt, array $parsedArgs, string $url): false|array|\WP_Error
    {
        // Only intercept requests to the AI Forge license API
        if (!str_contains($url, LicenseClient::API_BASE_URL)) {
            return $preempt;
        }

        if (self::getScenario() !== 'local_server') {
            return $preempt;
        }

        // Rewrite URL: replace production base with local server
        $localUrl = str_replace(LicenseClient::API_BASE_URL, self::LOCAL_SERVER_URL, $url);

        // Forward to local server with SSL disabled
        $parsedArgs['sslverify'] = false;

        return wp_remote_post($localUrl, $parsedArgs);
    }
}
