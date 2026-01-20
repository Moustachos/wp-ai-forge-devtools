<?php

/**
* Plugin Name: AI Forge Dev Tools
* Requires Plugins: wp-ai-forge
* Description: Developper only add-on for AI Forge
* Version: 0.1.0
* Author: Christophe Barrilliez
* Text Domain: wp-ai-forge-devtools
* Domain Path: /languages
* Fake: psr-cs-fixer.
*/

define('AIFORGE_DEV_TEXT_DOMAIN', 'wp-ai-forge-devtools');
define('AIFORGE_DEV_PATH', plugin_dir_path(__FILE__));

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

use AIForge\DevTools;

add_action('plugins_loaded', function () {
    DevTools::boot(__FILE__);
});
