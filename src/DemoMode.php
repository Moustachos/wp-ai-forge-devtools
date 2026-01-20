<?php

declare(strict_types=1);

namespace AIForge;

use AIForge\Demo\DemoConfigRepository;
use AIForge\Demo\DemoProviderDecorator;
use AIForge\Demo\DemoTemplateLoader;
use AIForge\Demo\DemoTemplateService;
use AIForge\Demo\MockResponseGenerator;

/**
 * Demo mode management.
 *
 * When the devtools plugin is active, demo mode is available.
 * Users with manage_options capability can toggle it on/off.
 */
class DemoMode
{
    private const CAPABILITY = 'manage_options';
    private const USER_META_KEY = 'aiforge_demo_mode';

    /**
     * Register all demo mode hooks.
     */
    public static function register(): void
    {
        // Config Repository - substitute with demo version
        add_filter('aiforge_config_repository', function ($config) {
            if (self::isEnabled()) {
                return new DemoConfigRepository();
            }
            return $config;
        });

        // Template Service - decorate with demo support
        add_filter('aiforge_template_service', function ($service) {
            if (self::isEnabled()) {
                return new DemoTemplateService($service, new DemoTemplateLoader());
            }
            return $service;
        });

        // Provider Instance - decorate with mock responses
        add_filter('aiforge_provider_instance', function ($provider, $id) {
            if (self::isEnabled()) {
                return new DemoProviderDecorator($provider, new MockResponseGenerator());
            }
            return $provider;
        }, 10, 2);
    }

    /**
     * Check if demo mode is currently enabled.
     *
     * Enabled when:
     * - User is logged in
     * - User has manage_options capability
     * - User has enabled it (or hasn't explicitly disabled it)
     */
    public static function isEnabled(): bool
    {
        $userId = get_current_user_id();
        if ($userId === 0 || !current_user_can(self::CAPABILITY)) {
            return false;
        }

        $userPref = get_user_meta($userId, self::USER_META_KEY, true);

        // Default to enabled if no preference set
        if ($userPref === '') {
            return true;
        }

        return $userPref === '1';
    }

    /**
     * Set the demo mode enabled state.
     */
    public static function setEnabled(bool $enabled): bool
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return false;
        }

        return update_user_meta($userId, self::USER_META_KEY, $enabled ? '1' : '0') !== false;
    }
}
