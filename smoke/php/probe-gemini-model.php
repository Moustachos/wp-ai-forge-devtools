<?php

// Probes a Gemini model's request-shaping behaviour: does it accept sampling
// parameters, and does it emit thinking tokens without being asked?
// No declare(strict_types=1): wp eval-file runs this through eval().

$model = getenv('AIFORGE_PROBE_MODEL') ?: 'gemini-3.7-flash';

$config = AIForge\Core::getInstance()->getConfig();
$key = $config->getProviderCredentials('gemini')['api_key'] ?? '';

if ($key === '') {
    echo "XRESULT:NO_KEY\n";
    return;
}

$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model
    . ':generateContent?key=' . rawurlencode($key);

$call = function (array $body) use ($endpoint) {
    $response = wp_remote_post($endpoint, [
        'timeout' => 60,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        return ['code' => 0, 'error' => $response->get_error_message(), 'json' => null];
    }

    return [
        'code'  => wp_remote_retrieve_response_code($response),
        'error' => '',
        'json'  => json_decode(wp_remote_retrieve_body($response), true),
    ];
};

$prompt = ['contents' => [['parts' => [['text' => 'Reply with the single word OK.']]]]];

// 1. Baseline, no generationConfig at all.
$plain = $call($prompt);

// 2. With sampling parameters the older Flash models refuse.
$sampled = $call($prompt + ['generationConfig' => ['temperature' => 0.7, 'topP' => 0.9]]);

$usage = $plain['json']['usageMetadata'] ?? [];
$thoughts = $usage['thoughtsTokenCount'] ?? null;

$samplingError = '';
if ($sampled['code'] >= 400) {
    $samplingError = $sampled['json']['error']['message'] ?? 'unknown';
    $samplingError = substr(str_replace(["\n", "\r"], ' ', $samplingError), 0, 160);
}

echo 'XRESULT:'
    . 'model=' . $model
    . ' plain=' . $plain['code']
    . ' sampling=' . $sampled['code']
    . ' thoughts=' . ($thoughts === null ? 'absent' : $thoughts)
    . ' promptTok=' . ($usage['promptTokenCount'] ?? '?')
    . ' outTok=' . ($usage['candidatesTokenCount'] ?? '?')
    . ($samplingError !== '' ? ' samplingError="' . $samplingError . '"' : '')
    . ($plain['error'] !== '' ? ' plainError="' . $plain['error'] . '"' : '')
    . "\n";
