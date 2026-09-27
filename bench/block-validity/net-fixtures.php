<?php

/**
 * Posts for net-check.mjs, built from stored generations.
 *
 *     wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/block-validity/net-fixtures.php create
 *     wp eval-file … state <post-id> [<post-id> …]
 *     wp eval-file … delete <post-id> [<post-id> …]
 *
 * Prints one line "XRESULT:<json>" that the caller looks for.
 */

global $wpdb;

const NET_CHECK_TASKS = [6363, 6379, 7059];

// Invalid (the saved markup lacks alignfull, as in stored generation 4202) but
// safely rebuildable: the rebuild only adds that class.
const NET_CHECK_LEAF = "<!-- wp:group {\"align\":\"full\"} -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>net-check %s leaf</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";

// Without a user, kses strips every inline style on insert.
wp_set_current_user(1);

$payload = static function (int $taskId, string $type) use ($wpdb): ?string {
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT payload FROM {$wpdb->prefix}aiforge_task_payloads WHERE task_id = %d AND payload_type = %s ORDER BY id DESC LIMIT 1",
        $taskId,
        $type
    ));

    return $value === null ? null : (string) $value;
};

$rootOf = static function (int $taskId) use ($wpdb): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT root_id FROM {$wpdb->prefix}aiforge_tasks WHERE id = %d",
        $taskId
    ));
};

$insert = static function (string $title, string $content): int {
    $postId = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'draft',
        'post_title' => $title,
        'post_content' => wp_slash($content),
    ], true);

    if (is_wp_error($postId)) {
        WP_CLI::error($postId->get_error_message());
    }

    return (int) $postId;
};

$ids = array_map('intval', array_slice($args, 1));
$result = [];

switch ($args[0] ?? '') {
    case 'create':
        $result = ['ai' => [], 'plain' => null];

        foreach (NET_CHECK_TASKS as $taskId) {
            $content = $payload($taskId, 'result_content');

            if ($content === null) {
                WP_CLI::error("Task {$taskId} has no result_content.");
            }

            $postId = $insert("net-check {$taskId}", $content);
            update_post_meta($postId, '_aiforge_task_id', $rootOf($taskId));
            $result['ai'][] = ['post' => $postId, 'task' => $taskId];
        }

        $result['plain'] = $insert('net-check plain', (string) $payload(NET_CHECK_TASKS[0], 'result_content'));

        // The dark group the net must refuse, with a rebuildable block inside it
        // and another after it.
        $content = (string) $payload(NET_CHECK_TASKS[0], 'result_content');
        $dark = strpos($content, 'background-color:#18181b');
        $open = $dark === false ? false : strpos($content, '>', $dark);

        if ($open === false) {
            WP_CLI::error('Task ' . NET_CHECK_TASKS[0] . ' has no dark group.');
        }

        $content = substr($content, 0, $open + 1) . "\n" . sprintf(NET_CHECK_LEAF, 'child') . "\n\n"
            . substr($content, $open + 1) . "\n\n" . sprintf(NET_CHECK_LEAF, 'sibling');
        $postId = $insert('net-check mixed', $content);
        update_post_meta($postId, '_aiforge_task_id', $rootOf(NET_CHECK_TASKS[0]));
        $result['mixed'] = $postId;
        break;

    case 'state':
        foreach ($ids as $postId) {
            $post = get_post($postId);
            $result[$postId] = $post ? $post->post_modified_gmt : null;
        }
        break;

    case 'delete':
        foreach ($ids as $postId) {
            $result[$postId] = (bool) wp_delete_post($postId, true);
        }
        break;

    default:
        WP_CLI::error('Usage: create | state <ids> | delete <ids>');
}

echo 'XRESULT:' . wp_json_encode($result) . "\n";
