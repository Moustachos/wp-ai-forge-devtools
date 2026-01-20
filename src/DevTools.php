<?php

declare(strict_types=1);

namespace AIForge;

use AIForge\Admin\DevAssetLoader;
use AIForge\REST\DemoModeController;
use AIForge\REST\DevModeController;

/**
 * AI Forge Dev Tools main class.
 *
 * Provides development features for AI Forge:
 * - Demo mode: simulate AI responses without API keys
 * - Dev mode: debug tools (prompt inspection, etc.)
 */
class DevTools
{
    private static ?self $instance = null;

    private function __construct(
        private readonly string $pluginFile
    ) {
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

    private function init(): void
    {
        if (!$this->isMainPluginActive()) {
            add_action('admin_notices', [$this, 'showMissingPluginNotice']);
            return;
        }

        DemoMode::register();
        DevMode::register();
        $this->registerRest();
        $this->registerAdminAssets();
    }

    /**
     * Check if the main AI Forge plugin is active.
     */
    private function isMainPluginActive(): bool
    {
        return defined('AIFORGE_PATH');
    }

    /**
     * Show admin notice when main plugin is not active.
     */
    public function showMissingPluginNotice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('AI Forge Dev Tools requires the AI Forge plugin to be active.', 'wp-ai-forge-devtools');
        echo '</p></div>';
    }

    /**
     * Register REST API controllers.
     */
    private function registerRest(): void
    {
        add_action('rest_api_init', function () {
            (new DemoModeController())->register_routes();
            (new DevModeController())->register_routes();
        });
    }

    /**
     * Register admin assets.
     */
    private function registerAdminAssets(): void
    {
        $assets = new DevAssetLoader($this->pluginFile);
        $assets->register();
    }
}
