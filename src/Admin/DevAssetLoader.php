<?php

declare(strict_types=1);

namespace AIForge\Admin;

use AIForge\DemoMode;
use AIForge\DevMode;

/**
 * Asset loader for AI Forge Dev Tools.
 *
 * Enqueues admin scripts and styles for the devtools plugin.
 *
 * @package AIForgeDevTools
 */
class DevAssetLoader
{
    private const HANDLE = 'aiforge-devtools-admin';

    private string $pluginUrl;
    private string $pluginPath;

    public function __construct(string $pluginFile)
    {
        $this->pluginUrl = plugin_dir_url($pluginFile);
        $this->pluginPath = plugin_dir_path($pluginFile);
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', $this->enqueueAdminAssets(...));
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if (!$this->isPluginPage($hookSuffix)) {
            return;
        }

        $assetFile = $this->pluginPath . 'admin/build/index.asset.php';
        if (!file_exists($assetFile)) {
            return;
        }

        $asset = require $assetFile;

        wp_enqueue_script(
            self::HANDLE,
            $this->pluginUrl . 'admin/build/index.js',
            array_merge($asset['dependencies'], ['aiforge-admin']),
            $asset['version'],
            true
        );

        $cssFile = $this->pluginPath . 'admin/build/index.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                self::HANDLE,
                $this->pluginUrl . 'admin/build/index.css',
                ['aiforge-admin'],
                $asset['version']
            );
        }

        wp_localize_script(self::HANDLE, 'aiforgeDevData', $this->getLocalizedData());

        wp_set_script_translations(
            self::HANDLE,
            'ai-forge-devtools',
            $this->pluginPath . 'languages'
        );
    }

    private function getLocalizedData(): array
    {
        return [
            'apiUrl' => rest_url('aiforge-dev/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'isDemoMode' => DemoMode::isEnabled(),
            'isDevMode' => DevMode::isEnabled(),
        ];
    }

    private function isPluginPage(string $hookSuffix): bool
    {
        return $hookSuffix === 'toplevel_page_ai-forge';
    }
}
