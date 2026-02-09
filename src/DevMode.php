<?php

declare(strict_types=1);

namespace AIForge;

use AIForge\Agent\MediaSuggestion\MediaIndexRepository;

/**
 * Dev mode management.
 *
 * When enabled, exposes debug information like prompts sent to LLMs.
 * Independent from demo mode - both can be enabled simultaneously.
 */
class DevMode
{
    private const CAPABILITY = 'manage_options';
    private const USER_META_KEY = 'aiforge_dev_mode';

    /**
     * Register all dev mode hooks.
     */
    public static function register(): void
    {
        // Agent Result Meta - add debug info (prompt) when dev mode is enabled
        add_filter('aiforge_agent_result_meta', function (array $meta, array $context) {
            if (self::isEnabled() && isset($context['prompt'])) {
                $meta['debug'] = [
                    'prompt' => $context['prompt'],
                ];
            }
            return $meta;
        }, 10, 2);

        // Include dev data (logs, children payloads) in task API responses
        add_filter('aiforge_task_include_extra_data', function (bool $include) {
            return $include || self::isEnabled();
        });

        // Add debug column in media library
        add_filter('manage_media_columns', function (array $columns) {
            if (self::isEnabled()) {
                $columns['aiforge_media_info'] = __('Infos média index', AIFORGE_DEV_TEXT_DOMAIN);
            }

            return $columns;
        }, 11);

        // Fill debug column info
        add_action('manage_media_custom_column', function (string $columnName, int $postId) {
            if (!self::isEnabled()) {
                return;
            }

            if ($columnName !== 'aiforge_media_info') {
                return;
            }

            if (!wp_attachment_is_image($postId)) {
                echo '—';
                return;
            }

            global $wpdb;
            $repository = new MediaIndexRepository($wpdb);
            $entry = $repository->findByAttachmentId($postId);

            if ($entry === null) {
                echo '—';
                return;
            }

            if (!empty($entry['keywords'])) {
                echo '<br><small>' . esc_html($entry['keywords']) . '</small>';
            }

            if (!empty($entry['description'])) {
                echo '<br><small>' . esc_html($entry['description']) . '</small>';
            }
        }, 11, 2);
    }

    /**
     * Check if dev mode is currently enabled.
     *
     * Enabled when:
     * - User is logged in
     * - User has manage_options capability
     * - User has enabled it (default: disabled)
     */
    public static function isEnabled(): bool
    {
        $userId = get_current_user_id();
        if ($userId === 0 || !current_user_can(self::CAPABILITY)) {
            return false;
        }

        $userPref = get_user_meta($userId, self::USER_META_KEY, true);

        // Default to disabled if no preference set
        return $userPref === '1';
    }

    /**
     * Set the dev mode enabled state.
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
