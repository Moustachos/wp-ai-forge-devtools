<?php

declare(strict_types=1);

namespace AIForge\REST;

use AIForge\DevMode;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class DevModeController extends WP_REST_Controller
{
    protected $namespace = 'aiforge-dev/v1';
    protected $rest_base = 'dev-mode';

    // =========================================================================
    // WP_REST_Controller overrides
    // =========================================================================

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => $this->get(...),
                'permission_callback' => $this->adminPermissionsCheck(...),
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => $this->update(...),
                'permission_callback' => $this->adminPermissionsCheck(...),
                'args' => [
                    'enabled' => [
                        'type' => 'boolean',
                        'required' => true,
                    ],
                ],
            ],
        ]);
    }

    // =========================================================================
    // Permission callbacks
    // =========================================================================

    public function adminPermissionsCheck(): bool
    {
        return current_user_can('manage_options');
    }

    // =========================================================================
    // Route handlers
    // =========================================================================

    /**
     * GET /dev-mode - Get dev mode status.
     */
    public function get(): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'enabled' => DevMode::isEnabled(),
            ],
        ]);
    }

    /**
     * POST /dev-mode - Set dev mode enabled state.
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $enabled = $request->get_param('enabled');
        DevMode::setEnabled($enabled);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'enabled' => DevMode::isEnabled(),
            ],
        ]);
    }
}
