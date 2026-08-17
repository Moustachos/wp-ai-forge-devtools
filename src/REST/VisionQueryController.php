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
 * The picker is a static HTML file, so it cannot carry a REST nonce: a nonce
 * minted by WP-CLI is computed against an empty session token and will never
 * verify against a browser session. The page therefore presents a one-off
 * token, stored as a transient when the page is generated and expiring with
 * it. Writes stay confined to .json files in the aiforge-dev upload directory.
 */
class VisionQueryController extends WP_REST_Controller
{
    /** Transient holding the token the current picker page was built with. */
    public const TOKEN_TRANSIENT = 'aiforge_dev_picker_token';

    /** Header the picker page sends its token in. */
    public const TOKEN_HEADER = 'X-AIForge-Picker-Token';

    protected $namespace = 'aiforge-dev/v1';
    protected $rest_base = 'vision-queries';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => $this->save(...),
                'permission_callback' => $this->permissionCheck(...),
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

    /**
     * An administrator session, or the token the current picker page holds.
     */
    public function permissionCheck(WP_REST_Request $request): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $stored = get_transient(self::TOKEN_TRANSIENT);
        $sent = (string) $request->get_header(self::TOKEN_HEADER);

        return \is_string($stored) && $stored !== '' && $sent !== '' && hash_equals($stored, $sent);
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
