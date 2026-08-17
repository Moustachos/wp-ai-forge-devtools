<?php

declare(strict_types=1);

namespace AIForge\REST;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Saves the vision benchmark query file from the picker page.
 *
 * The picker is a static HTML file served from uploads, so it authenticates
 * with the cookie already in the browser plus a nonce baked in at generation
 * time. Writes are confined to the aiforge-dev upload directory.
 */
class VisionQueryController extends WP_REST_Controller
{
    protected $namespace = 'aiforge-dev/v1';
    protected $rest_base = 'vision-queries';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => $this->save(...),
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'args' => [
                    'file' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'File name inside the aiforge-dev upload directory.',
                    ],
                    'queries' => [
                        'type' => 'array',
                        'required' => true,
                    ],
                ],
            ],
        ]);
    }

    public function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $name = basename((string) $request->get_param('file'));

        if (!str_ends_with($name, '.json')) {
            return new WP_Error('aiforge_dev_bad_file', 'Only .json files can be written.', ['status' => 400]);
        }

        $dir = wp_upload_dir()['basedir'] . '/aiforge-dev';
        wp_mkdir_p($dir);
        $path = $dir . '/' . $name;

        $queries = $request->get_param('queries');
        if (!\is_array($queries)) {
            return new WP_Error('aiforge_dev_bad_payload', 'queries must be an array.', ['status' => 400]);
        }

        // Keep a copy of what was there before overwriting: the file carries
        // manual work and a bad save would lose it.
        if (file_exists($path)) {
            copy($path, $path . '.bak');
        }

        $json = wp_json_encode($queries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return new WP_Error('aiforge_dev_encode', 'Could not encode the payload.', ['status' => 500]);
        }

        $written = file_put_contents($path, $json);
        if ($written === false) {
            return new WP_Error('aiforge_dev_write', "Could not write $path.", ['status' => 500]);
        }

        $filled = 0;
        $traps = 0;
        foreach ($queries as $q) {
            if (($q['type'] ?? '') === 'piege') {
                $traps++;
            } elseif (!empty($q['expect'])) {
                $filled++;
            }
        }

        return new WP_REST_Response([
            'saved' => true,
            'path' => $path,
            'bytes' => $written,
            'filled' => $filled,
            'traps' => $traps,
            'total' => \count($queries),
        ]);
    }
}
