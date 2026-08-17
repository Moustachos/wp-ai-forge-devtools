<?php

// Lists the Gemini models the configured key can actually reach.
// No declare(strict_types=1): wp eval-file runs this through eval().

$config = AIForge\Core::getInstance()->getConfig();
$credentials = $config->getProviderCredentials('gemini');
$key = $credentials['api_key'] ?? '';

if ($key === '') {
    echo "XRESULT:NO_KEY\n";
    return;
}

$response = wp_remote_get(
    'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . rawurlencode($key),
    ['timeout' => 30]
);

if (is_wp_error($response)) {
    echo 'XRESULT:ERROR ' . $response->get_error_message() . "\n";
    return;
}

$body = json_decode(wp_remote_retrieve_body($response), true);
$names = [];

foreach (($body['models'] ?? []) as $model) {
    $name = str_replace('models/', '', $model['name'] ?? '');
    if (strpos($name, 'gemini-3') === 0) {
        $names[] = $name;
    }
}

sort($names);

echo 'XRESULT:' . wp_remote_retrieve_response_code($response) . ' ' . implode(',', $names) . "\n";
