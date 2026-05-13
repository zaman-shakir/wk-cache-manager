<?php

/**
 * Plugin Name: WK Cache Manager
 * Plugin URI: https://webkonsulenter.dk
 * Description: Unified cache management: warm cache on updates and monitor all cache purge events.
 * Version: 3.3
 * Author: Shakir, Webkonsulenterne
 * Author URI: https://www.webkonsulenter.dk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wk-cache-manager
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * GitHub Plugin URI: zaman-shakir/wk-cache-manager
 * Primary Branch: main
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WK_CACHE_MANAGER_VERSION', '4.1');
define('WK_CACHE_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WK_CACHE_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WK_CACHE_MANAGER_LOG_DIR', WK_CACHE_MANAGER_PLUGIN_DIR . 'logs/');

/**
 * Early-bail: skip plugin init on frontend requests that don't need any module.
 * Saves autoload + Plugin construction + 5 module-loader gates per request.
 *
 * Frontend page loads only need plugin if:
 *   - RequestMonitor is enabled (logs every public hit)
 *   - URL is checkout/order-received (Purge Prevention checks order completion)
 *   - URL contains /wp-json/ (REST_REQUEST flag may not be set this early)
 */
if (!is_admin() &&
    !defined('DOING_CRON') &&
    !defined('WP_CLI') &&
    !defined('REST_REQUEST') &&
    PHP_SAPI !== 'cli' &&
    !get_option('wk_cache_manager_request_monitor_enabled', false)) {
    $wkcm_uri = $_SERVER['REQUEST_URI'] ?? '';
    $wkcm_needs_load = (
        strpos($wkcm_uri, '/checkout') !== false ||
        strpos($wkcm_uri, 'order-received') !== false ||
        strpos($wkcm_uri, '/wp-json/wc/') !== false ||
        strpos($wkcm_uri, 'wp-cron.php') !== false ||
        strpos($wkcm_uri, 'admin-ajax.php') !== false
    );
    if (!$wkcm_needs_load) {
        return;
    }
}

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'WKCacheManager\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize plugin
WKCacheManager\Plugin::get_instance();

// GitHub-based updater. Polls the public repo for new releases and surfaces
// "Update available" in the WordPress Plugins page. No auto-update — user
// clicks Update like for any wordpress.org plugin. Runs only in admin/cron
// because it hooks into update transients.
if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
    $wkcm_puc_loader = __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
    if (file_exists($wkcm_puc_loader)) {
        require_once $wkcm_puc_loader;
        if (class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            $wkcm_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/zaman-shakir/wk-cache-manager/',
                __FILE__,
                'wk-cache-manager'
            );
            $wkcm_updater->setBranch('main');
            // Use the zip attached to each GitHub Release. Falls back to source
            // archive if no asset attached.
            $wkcm_updater->getVcsApi()->enableReleaseAssets();
        }
    }
}
