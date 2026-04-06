<?php
/**
 * Plugin Name: Content Signing for WordPress
 * Plugin URI: https://example.com/content-signing
 * Description: Integrates WordPress with content signing services to verify content origin and authenticity.
 * Version: 1.0.0
 * Author: Jason Grey
 * Author URI: https://jason-grey.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: content-signing
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('CONTENT_SIGNING_VERSION', '1.0.0');
define('CONTENT_SIGNING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONTENT_SIGNING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CONTENT_SIGNING_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * The code that runs during plugin activation.
 */
function activate_content_signing() {
    require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-activator.php';
    ContentSigning_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_content_signing() {
    require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-deactivator.php';
    ContentSigning_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_content_signing');
register_deactivation_hook(__FILE__, 'deactivate_content_signing');

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-plugin.php';

/**
 * Begins execution of the plugin.
 */
function run_content_signing() {
    $plugin = ContentSigning_Plugin::get_instance();
    $plugin->run();
}

run_content_signing();