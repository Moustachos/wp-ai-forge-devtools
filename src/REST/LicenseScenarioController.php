<?php

declare(strict_types=1);

namespace AIForge\REST;

use AIForge\Demo\DemoLicenseProvider;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class LicenseScenarioController extends WP_REST_Controller
{
    protected $namespace = 'aiforge-dev/v1';
    protected $rest_base = 'license-scenario';

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
                    'scenario' => [
                        'type' => 'string',
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
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

    public function get(): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'scenario' => DemoLicenseProvider::getScenario(),
                'available' => DemoLicenseProvider::getAvailableScenarios(),
            ],
        ]);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $scenario = $request->get_param('scenario');

        if (!DemoLicenseProvider::setScenario($scenario)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'invalid_scenario',
                    'message' => 'Invalid scenario.',
                ],
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'scenario' => DemoLicenseProvider::getScenario(),
                'available' => DemoLicenseProvider::getAvailableScenarios(),
            ],
        ]);
    }
}
