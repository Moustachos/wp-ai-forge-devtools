<?php

/**
 * Smoke harness bridge, run through `wp eval-file`.
 *
 * Commands: environment, prepare, license probe|install|teardown.
 *
 * Every answer is one XRESULT: line, because wp-env echoes the command text
 * back around whatever the script prints. Nothing here may assume the main
 * plugin is loaded: the red-path proof deactivates it and still expects an
 * answer.
 *
 * No strict_types here: eval-file runs the body through eval(), where a
 * declare() is never the first statement.
 */

const SMOKE_LICENSE_KEY = 'AIFORGE-SMOK-ETST-0000-0001';

function smoke_reply(array $data): void
{
    echo 'XRESULT:' . json_encode($data) . "\n";
}

function smoke_plugin_loaded(): bool
{
    return class_exists('\AIForge\Core') && \AIForge\Core::getInstance() !== null;
}

/**
 * @return array{0: \AIForge\Agent\MediaIntelligence\MediaSearchService, 1: string}
 */
function smoke_media_search(\AIForge\Config\ConfigRepository $config): array
{
    global $wpdb;

    $agentConfig = new \AIForge\Agent\AgentConfigRepository();
    $active = $agentConfig->getActiveConfig(\AIForge\Agent\MediaIntelligence\MediaIntelligenceAgent::ID);

    $service = new \AIForge\Agent\MediaIntelligence\MediaSearchService(
        new \AIForge\Agent\MediaIntelligence\MediaIndexRepository($wpdb),
        $config,
        $agentConfig,
        new \AIForge\Agent\MediaIntelligence\MediaSearchCostLog()
    );

    return [$service, $active['provider'] ?? 'gemini'];
}

$command = $args[0] ?? 'environment';
$sub = $args[1] ?? '';

if ($command === 'prepare') {
    update_option('admin_email_lifespan', time() + YEAR_IN_SECONDS);

    smoke_reply(['admin_email_lifespan' => (int) get_option('admin_email_lifespan')]);

    return;
}

if ($command === 'license') {
    if (!smoke_plugin_loaded()) {
        smoke_reply(['status' => 'unknown', 'has_key' => false, 'is_smoke_key' => false, 'plugin_active' => false]);

        return;
    }

    $client = \AIForge\Core::getInstance()->getLicenseClient();

    if ($sub === 'install') {
        $client->setLicenseKey(SMOKE_LICENSE_KEY);
        $client->cacheStatus(\AIForge\License\LicenseStatus::fromApiResponse([
            'status' => \AIForge\License\LicenseStatus::VALID,
            'plan' => 'smoke',
            'customer_name' => 'Browser smoke harness',
            'active_sites' => 1,
            'max_sites' => 1,
        ]));
    }

    // Only ever removes the harness's own key, so a real license activated
    // while a run was in flight survives its teardown.
    if ($sub === 'teardown' && $client->getLicenseKey() === SMOKE_LICENSE_KEY) {
        $client->removeLicenseKey();
    }

    $key = $client->getLicenseKey();

    smoke_reply([
        'status' => $client->getStatus()->status,
        'has_key' => $key !== null,
        'is_smoke_key' => $key === SMOKE_LICENSE_KEY,
        'plugin_active' => true,
    ]);

    return;
}

$environment = [
    'plugin_active' => smoke_plugin_loaded(),
    'wp_version' => get_bloginfo('version'),
    'locale' => get_locale(),
    'license' => ['status' => 'unknown', 'has_key' => false],
    'providers' => [],
    'media_indexed' => 0,
    'search' => ['available' => false, 'reason' => 'AI Forge is not active'],
];

if ($environment['plugin_active']) {
    global $wpdb;

    $core = \AIForge\Core::getInstance();
    $config = $core->getConfig();
    $client = $core->getLicenseClient();
    $status = $client->getStatus();

    $indexed = (new \AIForge\Agent\MediaIntelligence\MediaIndexRepository($wpdb))->countIndexed();
    [$search, $searchProvider] = smoke_media_search($config);

    // isAvailable() answers yes or no; the four switches behind it are read
    // again here so a skip can say which one is off.
    $reason = null;
    if (!get_option(\AIForge\Agent\MediaIntelligence\MediaSearchService::OPTION_ENABLED, true)) {
        $reason = 'media search is switched off in settings';
    } elseif ($indexed < 1) {
        $reason = 'media index is empty';
    } elseif (!$config->isProviderConfigured($searchProvider)) {
        $reason = "the media intelligence service ({$searchProvider}) is not configured";
    } elseif (!$status->allowsFeatureAccess()) {
        $reason = "the license does not allow feature access ({$status->status})";
    } elseif (!$search->isAvailable()) {
        $reason = 'MediaSearchService reports the search as unavailable';
    }

    $environment['license'] = [
        'status' => $status->status,
        'has_key' => $client->getLicenseKey() !== null,
    ];
    $environment['providers'] = [
        'gemini' => $config->isProviderConfigured('gemini'),
        'openai' => $config->isProviderConfigured('openai'),
        'anthropic' => $config->isProviderConfigured('anthropic'),
    ];
    $environment['media_indexed'] = $indexed;
    $environment['search'] = [
        'available' => $search->isAvailable() && $reason === null,
        'reason' => $reason,
        'provider' => $searchProvider,
    ];
}

smoke_reply($environment);
