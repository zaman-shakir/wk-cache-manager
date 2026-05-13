<?php

namespace WKCacheManager;

class Plugin
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init();
    }

    private function init()
    {
        // Initialize modules
        add_action('plugins_loaded', [$this, 'load_modules']);

        // Register activation/deactivation hooks
        register_activation_hook(WK_CACHE_MANAGER_PLUGIN_DIR . 'wk-cache-manager.php', [$this, 'activate']);
        register_deactivation_hook(WK_CACHE_MANAGER_PLUGIN_DIR . 'wk-cache-manager.php', [$this, 'deactivate']);

        // Add settings link on plugin page
        add_filter('plugin_action_links_' . plugin_basename(WK_CACHE_MANAGER_PLUGIN_DIR . 'wk-cache-manager.php'), [$this, 'add_settings_link']);

        // Create log directory
        $this->ensure_log_directory();
    }

    public function load_modules()
    {
        // Skip module loading on WordPress heartbeat to reduce overhead
        if (defined('DOING_AJAX') && isset($_REQUEST['action']) && $_REQUEST['action'] === 'heartbeat') {
            return;
        }

        // Record every WP-Cron tick (whether or not any of our modules load).
        // Used by the Health Check card to verify cPanel cron is actually firing
        // wp-cron.php. Without this, sites with DISABLE_WP_CRON=true would show
        // "no tick recorded" because Admin class is skipped on cron requests.
        if (defined('DOING_CRON') && DOING_CRON) {
            $now = time();
            update_option('wk_cache_manager_last_cron_tick', $now, false);
            // Flush options cache so admin page reads the fresh value on next request
            // (some object cache backends don't auto-invalidate on update_option).
            wp_cache_delete('wk_cache_manager_last_cron_tick', 'options');
            wp_cache_delete('notoptions', 'options');
            // Belt-and-suspenders: write a marker file too. If the option write
            // is being swallowed (object cache, autoload weirdness), the file
            // still proves cron fired.
            if (defined('WK_CACHE_MANAGER_LOG_DIR')) {
                @file_put_contents(WK_CACHE_MANAGER_LOG_DIR . 'cron-tick.txt', (string) $now, LOCK_EX);
            }
        }

        // Read all module-toggle options once. Keys are autoloaded so this is one filter chain per option,
        // but we want to avoid re-reading the same key from multiple should_load_* gates.
        $opts = [
            'url_crawler' => (bool) get_option('wk_cache_manager_url_crawler_enabled', true),
            'purge_prevention' => (bool) get_option('wk_cache_manager_purge_prevention_enabled', true),
            'request_monitor' => (bool) get_option('wk_cache_manager_request_monitor_enabled', false),
        ];

        if ($this->should_load_admin()) {
            Admin::get_instance();
        }

        if ($opts['url_crawler'] && $this->should_load_url_crawler()) {
            UrlCrawler\UrlCrawler::get_instance();
        }

        // Always load Cache Monitor class so admin menu/settings page register.
        // The class internally gates hook registration on wk_cache_manager_cache_monitor_enabled,
        // so disabled = no hooks fire but settings UI remains reachable.
        if ($this->should_load_cache_monitor()) {
            CacheMonitor\CacheMonitor::get_instance();
        }

        if ($opts['purge_prevention'] && $this->should_load_litespeed_manager()) {
            LiteSpeedManager\LiteSpeedManager::get_instance();
        }

        if ($opts['request_monitor']) {
            RequestMonitor\RequestMonitor::get_instance();
        }
    }

    /**
     * Check if we should load LiteSpeed Manager based on current context
     * Only load when hooks we're intercepting might actually fire
     *
     * OPTIMIZATION: Only load for product updates and order completions
     */
    private function should_load_litespeed_manager()
    {
        // REST API: detect by URI (REST_REQUEST constant is defined later than plugins_loaded).
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/wp-json/wc/') !== false) {
            if (strpos($request_uri, '/products') !== false || strpos($request_uri, '/orders') !== false) {
                return true;
            }
            return false;
        }

        // Load in admin only for specific pages
        if (is_admin()) {
            // Always load for plugin settings page access
            if (isset($_GET['page']) && strpos($_GET['page'], 'wk-cache-manager') !== false) {
                return true;
            }

            // Load on WooCommerce HPOS order pages (admin.php?page=wc-orders[&action=edit])
            $page_param = $_REQUEST['page'] ?? '';
            if ($page_param === 'wc-orders' || $page_param === 'wc-orders--shop_order_refund') {
                return true;
            }

            // Load on AJAX only if WooCommerce/product/order related
            if (defined('DOING_AJAX') && DOING_AJAX) {
                $action = $_REQUEST['action'] ?? '';

                // Only load for WooCommerce/product/order related AJAX
                if (strpos($action, 'woocommerce') !== false ||
                    strpos($action, 'product') !== false ||
                    strpos($action, 'order') !== false ||
                    strpos($action, 'wk_cache_manager') !== false) {
                    return true;
                }

                // Skip all other admin AJAX (heartbeat, auto-save, etc.)
                return false;
            }

            // Load on product/order edit pages.
            // get_current_screen() is unavailable at plugins_loaded so we inspect the request
            // directly: $pagenow + post_type query/body parameter.
            global $pagenow;
            $script = $pagenow ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
            if (in_array($script, ['post.php', 'post-new.php', 'edit.php'], true)) {
                $post_type = $_REQUEST['post_type'] ?? '';
                if ($post_type === '' && !empty($_REQUEST['post'])) {
                    $post_type = get_post_type((int) $_REQUEST['post']);
                }
                if (in_array($post_type, ['product', 'shop_order'], true)) {
                    return true;
                }
            }

            // Skip all other admin pages (posts, media, etc.)
            return false;
        }

        // CRON: only load if a wk_cache_manager_* event is due NOW.
        // With cPanel cron firing every minute, most ticks have nothing for us.
        if (defined('DOING_CRON') && DOING_CRON) {
            return $this->is_our_cron_due();
        }

        // On frontend, only load for WooCommerce checkout/order completion
        // This is where order status changes happen (triggering cache purge check)
        if (class_exists('WooCommerce')) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';

            // Only load for checkout and order-received pages
            // These are the only frontend pages where orders complete
            if (strpos($request_uri, 'checkout') !== false ||
                strpos($request_uri, 'order-received') !== false) {
                return true;
            }
        }

        // Load for REST API requests (product/order updates via API trigger purge checks)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            // Only load for WooCommerce product/order API endpoints
            if (strpos($request_uri, '/wp-json/wc/') !== false &&
                (strpos($request_uri, '/products') !== false || strpos($request_uri, '/orders') !== false)) {
                return true;
            }
        }

        // Don't load for anything else (cart, products, blog, etc.)
        return false;
    }

    /**
     * Check if we should load URL Crawler based on current context
     * Only load when hooks we're intercepting might actually fire
     */
    private function should_load_url_crawler()
    {
        // REST API: detect by URI (REST_REQUEST constant is defined later than plugins_loaded).
        // Required so woocommerce_update_product hook fires on WC REST PUT.
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/wp-json/wc/') !== false &&
            (strpos($request_uri, '/products') !== false || strpos($request_uri, '/orders') !== false)) {
            return true;
        }

        // Always load in admin (for settings page + post/product editing)
        if (is_admin()) {
            // But skip on admin AJAX that won't trigger crawls
            if (defined('DOING_AJAX') && DOING_AJAX) {
                $action = $_REQUEST['action'] ?? '';

                // Load for URL Crawler's own AJAX (clear logs, async loopback)
                if (strpos($action, 'wk_cache_manager_clear_url_logs') !== false ||
                    strpos($action, 'wk_cache_manager_process_queue_now') !== false) {
                    return true;
                }

                // Load for post/product related AJAX
                if (strpos($action, 'save_post') !== false ||
                    strpos($action, 'inline-save') !== false ||
                    strpos($action, 'woocommerce') !== false ||
                    strpos($action, 'product') !== false) {
                    return true;
                }

                // Skip auto-save, updates, health checks, etc.
                return false;
            }

            // Load only for plugin settings pages and product/post edit screens
            if (isset($_GET['page']) && strpos($_GET['page'], 'wk-cache-manager') !== false) {
                return true;
            }

            // Load on post/product edit pages (where crawl might be triggered)
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && in_array($screen->post_type, ['post', 'product'])) {
                return true;
            }

            // FALLBACK: Load on all admin pages if we can't determine screen
            // This ensures URL Crawler works even if screen detection fails
            return true;
        }

        // CRON: only load if our event is due now
        if (defined('DOING_CRON') && DOING_CRON) {
            return $this->is_our_cron_due();
        }

        // Load for REST API requests (product updates via API)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            // Only load for WooCommerce product/order API endpoints
            if (strpos($request_uri, '/wp-json/wc/') !== false &&
                (strpos($request_uri, '/products') !== false || strpos($request_uri, '/orders') !== false)) {
                return true;
            }
        }

        // Don't load on frontend (no hooks will fire)
        return false;
    }

    /**
     * Check if we should load Cache Monitor based on current context
     * Only load when monitoring is needed
     *
     * OPTIMIZATION: Cache Monitor hooks into many events, so load it when those events might fire
     */
    private function should_load_cache_monitor()
    {
        // Always load in admin (monitors post saves, product updates, etc.)
        if (is_admin()) {
            // Skip heartbeat AJAX (no cache events)
            if (defined('DOING_AJAX') && DOING_AJAX) {
                $action = $_REQUEST['action'] ?? '';
                if ($action === 'heartbeat') {
                    return false;
                }
            }
            // Load for all other admin requests (cache events can happen)
            return true;
        }

        // Don't load on CRON (cache monitor doesn't use cron)
        if (defined('DOING_CRON') && DOING_CRON) {
            return false;
        }

        // On frontend, load for checkout/order completion (order status changes fire cache events)
        if (class_exists('WooCommerce')) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($request_uri, 'checkout') !== false ||
                strpos($request_uri, 'order-received') !== false) {
                return true;
            }
        }

        // Load for REST API (product/order updates via API trigger cache events)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($request_uri, '/wp-json/wc/') !== false ||
                strpos($request_uri, '/wp-json/wp/v2/') !== false) {
                return true;
            }
        }

        // Don't load for other frontend requests (blog, product views, etc.)
        return false;
    }

    /**
     * Add settings link on plugin page
     *
     * @param array $links Existing plugin action links
     * @return array Modified plugin action links
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=wk-cache-manager') . '">' . __('Settings', 'wk-cache-manager') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function activate()
    {
        // Create log directory on activation
        $this->ensure_log_directory();

        // Create cache monitor log file
        $cache_log = WK_CACHE_MANAGER_LOG_DIR . 'cache-monitor.log';
        if (!file_exists($cache_log)) {
            touch($cache_log);
        }
    }

    public function deactivate()
    {
        // Cleanup on deactivation (optional)
        // Currently does nothing - logs are preserved
    }

    private function ensure_log_directory()
    {
        if (!file_exists(WK_CACHE_MANAGER_LOG_DIR)) {
            wp_mkdir_p(WK_CACHE_MANAGER_LOG_DIR);
        }

        $htaccess = WK_CACHE_MANAGER_LOG_DIR . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $index = WK_CACHE_MANAGER_LOG_DIR . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // Silence is golden.\n");
        }
    }

    /**
     * Should we load the Admin class (menus, dashboard, admin bar widget, AJAX endpoints)?
     */
    private function should_load_admin()
    {
        // Always load in admin context (menus + screens)
        if (is_admin()) {
            // Skip non-our admin AJAX (heartbeat, autosave, etc.)
            if (defined('DOING_AJAX') && DOING_AJAX) {
                $action = $_REQUEST['action'] ?? '';
                $is_ours = $action && strpos($action, 'wk_cache_manager') !== false;
                return $is_ours; // skip for everything else
            }
            return true;
        }

        // Frontend / REST: only load if admin bar widget enabled AND a logged-in user
        // is likely to view this response. Admin bar never renders for guests/REST.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (PHP_SAPI === 'cli' || (defined('DOING_CRON') && DOING_CRON)) {
            return false;
        }
        if (get_option('wk_cache_manager_admin_bar_enabled', false) && is_user_logged_in()) {
            return true;
        }
        return false;
    }

    /**
     * Are any wk_cache_manager_* cron events due in this tick?
     * cPanel cron fires every minute; most ticks have nothing for us.
     * Skipping unrelated ticks avoids loading plugin code for no reason.
     */
    private function is_our_cron_due()
    {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return false;
        }
        $now = time();
        foreach ($crons as $ts => $hooks) {
            if ($ts > $now) {
                break; // sorted ascending; rest are future
            }
            foreach (array_keys($hooks) as $hook_name) {
                if (strpos($hook_name, 'wk_cache_manager') !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
