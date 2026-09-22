<?php

/**
 * Plugin Name:       AI Settings
 * Plugin URI:        https://github.com/bestony/AI-Settings
 * Description:       Edit every configuration option of the WordPress AI plugin from a single screen.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Requires Plugins:  ai
 * Version:           0.1.0
 * Author:            Bestony
 * Author URI:        https://github.com/bestony
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-settings
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin version, reported on the settings screen.
 *
 * @var string
 */
define('AISETTINGS_VERSION', '0.1.0');

/**
 * Absolute path to the main plugin file.
 *
 * @var string
 */
define('AISETTINGS_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/src/autoload.php';

Plugin::get_instance()->setup();
