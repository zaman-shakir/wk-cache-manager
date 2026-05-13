<?php

namespace WKCacheManager\CacheMonitor;

class CacheMonitor
{
    private static $instance = null;
    private $log_dir;
    private static $recent_wp_actions = [];
    private $monitoring_enabled = null; // Cache for performance
    private static $log_buffer = [];
    private static $flush_registered = false;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->log_dir = WK_CACHE_MANAGER_LOG_DIR;

        // Cache monitoring enabled status (prevents repeated DB calls)
        $this->monitoring_enabled = get_option('wk_cache_manager_cache_monitor_enabled', false);

        // Add admin menu (must be in constructor, not init)
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Register settings
        add_action('admin_init', [$this, 'register_settings']);

        // Initialize hooks immediately (not on plugins_loaded which may be too late)
        $this->init();
    }

    public function init()
    {
        // Don't register any hooks if monitoring is disabled
        if (!$this->monitoring_enabled) {
            return;
        }

        // Check if lightweight mode is enabled (REST API & Webhooks only)
        $lightweight_mode = get_option('wk_cache_manager_cache_monitor_lightweight', false);

        if (!$lightweight_mode) {
            // FULL MONITORING MODE: Hook into WordPress core actions to track what triggers cache purges
            add_action('save_post', [$this, 'log_wp_action_save_post'], 1, 3);
            add_action('post_updated', [$this, 'log_wp_action_post_updated'], 1, 3);
            add_action('deleted_post', [$this, 'log_wp_action_deleted_post'], 1, 2);
            add_action('transition_post_status', [$this, 'log_wp_action_transition_post_status'], 1, 3);
            add_action('edited_term', [$this, 'log_wp_action_edited_term'], 1, 3);
            add_action('comment_post', [$this, 'log_wp_action_comment_post'], 1, 3);
            add_action('wp_update_nav_menu', [$this, 'log_wp_action_nav_menu'], 1, 1);

            // Hook into WooCommerce actions
            add_action('woocommerce_update_product', [$this, 'log_wp_action_wc_update_product'], 1, 1);
            add_action('woocommerce_new_product', [$this, 'log_wp_action_wc_new_product'], 1, 1);
            add_action('woocommerce_delete_product', [$this, 'log_wp_action_wc_delete_product'], 1, 1);
            add_action('woocommerce_product_set_stock', [$this, 'log_wp_action_wc_stock_change'], 1, 1);
            add_action('woocommerce_product_set_stock_status', [$this, 'log_wp_action_wc_stock_status_change'], 1, 1);
            add_action('woocommerce_update_order', [$this, 'log_wp_action_wc_update_order'], 1, 1);
            add_action('woocommerce_order_status_changed', [$this, 'log_wp_action_wc_order_status_changed'], 1, 4);
            add_action('woocommerce_new_order', [$this, 'log_wp_action_wc_new_order'], 1, 1);
            add_action('woocommerce_delete_order', [$this, 'log_wp_action_wc_delete_order'], 1, 1);
            add_action('woocommerce_payment_complete', [$this, 'log_wp_action_wc_payment_complete'], 1, 1);
            add_action('woocommerce_save_product_variation', [$this, 'log_wp_action_wc_save_variation'], 1, 2);
        }

        // ALWAYS monitor REST API & Webhooks (even in lightweight mode)
        // This is what catches external integrations like Klaviyo, Peakws, etc.
        add_action('rest_after_insert_post', [$this, 'log_wp_action_rest_insert_post'], 1, 3);
        add_action('rest_after_insert_product', [$this, 'log_wp_action_rest_insert_product'], 1, 3);
        add_action('rest_after_insert_shop_order', [$this, 'log_wp_action_rest_insert_order'], 1, 3);
        add_action('rest_delete_post', [$this, 'log_wp_action_rest_delete_post'], 1, 3);
        add_action('rest_after_insert_term', [$this, 'log_wp_action_rest_insert_term'], 1, 3);

        // Monitor WooCommerce webhooks (external integrations)
        add_action('woocommerce_webhook_process_delivery', [$this, 'log_webhook_delivery'], 1, 5);

        // Track REST API authentication (if enabled)
        // This shows which API key/user is making REST API requests
        if (get_option('wk_cache_manager_cache_monitor_track_api_keys', false)) {
            add_filter('rest_authentication_errors', [$this, 'track_rest_api_auth'], 999);
        }

        // === LiteSpeed Cache Hooks (verified from actual plugin code) ===
        // Main purge completion hooks (these are what actually fire!)
        add_action('litespeed_purged_all', [$this, 'log_purge_complete'], 10, 1);                    // Main "Purge All" button
        add_action('litespeed_purged_all_lscache', [$this, 'log_purge_complete'], 10, 1);           // LSCache purge
        add_action('litespeed_purged_all_ccss', [$this, 'log_purge_complete'], 10, 1);              // Critical CSS purge
        add_action('litespeed_purged_all_ucss', [$this, 'log_purge_complete'], 10, 1);              // Unique CSS purge
        add_action('litespeed_purged_all_lqip', [$this, 'log_purge_complete'], 10, 1);              // LQIP purge
        add_action('litespeed_purged_all_avatar', [$this, 'log_purge_complete'], 10, 1);            // Avatar cache purge
        add_action('litespeed_purged_all_localres', [$this, 'log_purge_complete'], 10, 1);          // Local resources purge
        add_action('litespeed_purged_all_cssjs', [$this, 'log_purge_complete'], 10, 1);             // CSS/JS purge
        add_action('litespeed_purged_all_opcache', [$this, 'log_purge_complete'], 10, 1);           // OPcache purge
        add_action('litespeed_purged_all_object', [$this, 'log_purge_complete'], 10, 1);            // Object cache purge

        // Specific purge completion hooks
        add_action('litespeed_purged_single', [$this, 'log_purge_complete'], 10, 1);                // Single tag purge
        add_action('litespeed_purged_front', [$this, 'log_purge_complete'], 10, 1);                 // Front page purge (with referer)
        add_action('litespeed_purged_frontpage', [$this, 'log_purge_complete'], 10, 1);             // Front page purge
        add_action('litespeed_purged_pages', [$this, 'log_purge_complete'], 10, 1);                 // All pages purge
        add_action('litespeed_purged_cat', [$this, 'log_purge_complete'], 10, 1);                   // Category purge
        add_action('litespeed_purged_tag', [$this, 'log_purge_complete'], 10, 1);                   // Tag purge
        add_action('litespeed_purged_link', [$this, 'log_purge_complete'], 10, 1);                  // URL/link purge
        add_action('litespeed_purged_esi', [$this, 'log_purge_complete'], 10, 1);                   // ESI block purge
        add_action('litespeed_purged_posttype', [$this, 'log_purge_complete'], 10, 1);              // Post type purge
        add_action('litespeed_purged_post', [$this, 'log_purge_complete'], 10, 1);                  // Single post purge
        add_action('litespeed_purged_widget', [$this, 'log_purge_complete'], 10, 1);                // Widget purge
        add_action('litespeed_purged_comment_widget', [$this, 'log_purge_complete'], 10, 1);        // Comment widget purge
        add_action('litespeed_purged_feeds', [$this, 'log_purge_complete'], 10, 1);                 // RSS feeds purge
        add_action('litespeed_purged_on_logout', [$this, 'log_purge_complete'], 10, 1);             // Logout purge

        // API/Programmatic hooks
        add_action('litespeed_api_purge_post', [$this, 'log_purge_complete'], 10, 1);               // API post purge
        add_action('litespeed_purge_finalize', [$this, 'log_purge'], 10, 2);                        // Final purge step

        // Third-party integration hooks (from actual plugin usage)
        add_action('litespeed_purge', [$this, 'log_purge'], 10, 1);                                 // Generic purge (tags)
        add_action('litespeed_purge_all', [$this, 'log_purge'], 10, 1);                             // Purge all trigger
        add_action('litespeed_purge_post', [$this, 'log_purge'], 10, 1);                            // Purge post trigger
        add_action('litespeed_purge_posttype', [$this, 'log_purge'], 10, 1);                        // Purge posttype trigger
        add_action('litespeed_purge_url', [$this, 'log_purge'], 10, 1);                             // Purge URL trigger (if exists)
        add_action('litespeed_purge_widget', [$this, 'log_purge'], 10, 1);                          // Purge widget trigger
        add_action('litespeed_purge_private_esi', [$this, 'log_purge'], 10, 1);                     // WooCommerce ESI purge
        add_action('litespeed_purge_private_all', [$this, 'log_purge'], 10, 1);                     // WooCommerce private cache
        add_action('litespeed_purge_ucss', [$this, 'log_purge'], 10, 1);                            // Unique CSS purge trigger
        add_action('litespeed_purge_esi', [$this, 'log_purge'], 10, 1);                             // ESI purge trigger (if exists)

        // === WP Cloudflare Page Cache Hooks (verified from actual plugin code) ===
        // Main purge hooks
        add_action('swcfpc_purge_all', [$this, 'log_purge'], 10, 1);                                // Purge all trigger
        add_action('swcfpc_purge_urls', [$this, 'log_purge'], 10, 1);                               // Purge specific URLs

        // Cloudflare API hooks (before/after)
        add_action('swcfpc_cf_purge_whole_cache_before', [$this, 'log_purge'], 10, 0);              // Before purge all
        add_action('swcfpc_cf_purge_whole_cache_after', [$this, 'log_purge_complete'], 10, 0);      // After purge all
        add_action('swcfpc_cf_purge_cache_by_urls_before', [$this, 'log_purge'], 10, 1);            // Before purge URLs
        add_action('swcfpc_cf_purge_cache_by_urls_after', [$this, 'log_purge_complete'], 10, 1);    // After purge URLs

        // Fallback cache hooks
        add_action('swcfpc_fallback_cache_purged_all', [$this, 'log_purge_complete'], 10, 0);       // Fallback cache purge all
        add_action('swcfpc_fallback_cache_purged_url', [$this, 'log_purge_complete'], 10, 1);       // Fallback cache purge URL

        // Schedule daily log cleanup
        if (!wp_next_scheduled('wk_cache_manager_monitor_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wk_cache_manager_monitor_cleanup');
        }
        add_action('wk_cache_manager_monitor_cleanup', [$this, 'cleanup_old_logs']);
    }

    /**
     * Register plugin settings
     */
    public function register_settings()
    {
        register_setting(
            'wk_cache_manager_cache_monitor_settings',
            'wk_cache_manager_cache_monitor_enabled',
            [
                'type' => 'boolean',
                'default' => true
            ]
        );

        register_setting(
            'wk_cache_manager_cache_monitor_settings',
            'wk_cache_manager_cache_monitor_lightweight',
            [
                'type' => 'boolean',
                'default' => false
            ]
        );

        register_setting(
            'wk_cache_manager_cache_monitor_settings',
            'wk_cache_manager_cache_monitor_track_api_keys',
            [
                'type' => 'boolean',
                'default' => false
            ]
        );

        register_setting(
            'wk_cache_manager_cache_monitor_settings',
            'wk_cache_manager_cache_monitor_log_retention_days',
            [
                'type' => 'integer',
                'default' => 30,
                'sanitize_callback' => 'absint',
            ]
        );
    }

    /**
     * Get the current log file path based on today's date
     *
     * @return string Path to today's log file
     */
    private function get_current_log_file()
    {
        $date = current_time('Y-m-d');
        return $this->log_dir . 'cache-monitor-' . $date . '.log';
    }

    /**
     * Buffer a log entry. Single flushed write on shutdown reduces I/O contention
     * when many purge hooks fire in one request.
     */
    private function buffer_log($entry)
    {
        self::$log_buffer[] = $entry;

        if (!self::$flush_registered) {
            self::$flush_registered = true;
            add_action('shutdown', [self::class, 'flush_log_buffer'], 9999);
        }
    }

    public static function flush_log_buffer()
    {
        if (empty(self::$log_buffer)) {
            return;
        }
        $instance = self::$instance;
        if (!$instance) {
            return;
        }
        $payload = implode('', self::$log_buffer);
        self::$log_buffer = [];
        @file_put_contents($instance->get_current_log_file(), $payload, FILE_APPEND | LOCK_EX);
    }

    /**
     * Store a WordPress action that might trigger a cache purge
     *
     * @param string $action The action name
     * @param string $details Details about the action
     */
    private function store_wp_action($action, $details)
    {
        // Skip if monitoring is disabled (performance optimization)
        if (!$this->monitoring_enabled) {
            return;
        }

        $timestamp = microtime(true);
        self::$recent_wp_actions[] = [
            'action' => $action,
            'details' => $details,
            'timestamp' => $timestamp
        ];

        // Keep only actions from last 5 seconds
        self::$recent_wp_actions = array_filter(self::$recent_wp_actions, function ($item) use ($timestamp) {
            return ($timestamp - $item['timestamp']) < 5;
        });
    }

    /**
     * Get the request context (admin page, frontend, AJAX, etc.)
     *
     * @return string Context description
     */
    private function get_request_context()
    {
        $context = 'Unknown';

        if (defined('DOING_CRON') && DOING_CRON) {
            $context = 'CRON';
        } elseif (defined('DOING_AJAX') && DOING_AJAX) {
            $action = $_REQUEST['action'] ?? 'unknown';
            $context = 'AJAX:' . $action;
        } elseif (defined('REST_REQUEST') && REST_REQUEST) {
            $context = 'REST_API';
        } elseif (is_admin()) {
            // Try to get current admin page
            $page = $_GET['page'] ?? '';
            if ($page) {
                $context = 'Admin:' . $page;
            } else {
                $screen = get_current_screen();
                if ($screen) {
                    $context = 'Admin:' . $screen->id;
                } else {
                    $context = 'Admin';
                }
            }
        } else {
            // Frontend - try to get current URL or page type
            $url = $_SERVER['REQUEST_URI'] ?? '';
            if (is_front_page()) {
                $context = 'Frontend:Homepage';
            } elseif (is_single()) {
                $context = 'Frontend:Post#' . get_the_ID();
            } elseif (is_product()) {
                $context = 'Frontend:Product#' . get_the_ID();
            } elseif ($url) {
                $context = 'Frontend:' . parse_url($url, PHP_URL_PATH);
            } else {
                $context = 'Frontend';
            }
        }

        return $context;
    }

    /**
     * Get the most recent WordPress action that might have triggered the purge
     *
     * @return string WordPress action description or empty string
     */
    private function get_recent_wp_action()
    {
        if (empty(self::$recent_wp_actions)) {
            return '';
        }

        // Get the most recent action
        $recent = end(self::$recent_wp_actions);
        return sprintf('%s (%s)', $recent['action'], $recent['details']);
    }

    /**
     * Convert hook-specific scalar args (post ID, term object, URL) into
     * an array of resolved URLs for logging in the Arg field. Empty array
     * means the hook does not carry URL info we can resolve.
     */
    private function resolve_purge_urls_from_hook($hook, $arg)
    {
        $urls = [];

        switch ($hook) {
            case 'litespeed_purged_post':
            case 'litespeed_api_purge_post':
                $pid = is_scalar($arg) ? (int) $arg : 0;
                if ($pid > 0) {
                    $url = get_permalink($pid);
                    if ($url) {
                        $urls[] = $url;
                    }
                }
                break;

            case 'litespeed_purged_cat':
                $cat = is_scalar($arg) ? $arg : null;
                if ($cat) {
                    $term = is_numeric($cat) ? get_term((int) $cat, 'category') : get_term_by('slug', (string) $cat, 'category');
                    if ($term && !is_wp_error($term)) {
                        $url = get_term_link($term);
                        if (!is_wp_error($url)) {
                            $urls[] = $url;
                        }
                    }
                }
                break;

            case 'litespeed_purged_tag':
                $tag = is_scalar($arg) ? $arg : null;
                if ($tag) {
                    $term = is_numeric($tag) ? get_term((int) $tag, 'post_tag') : get_term_by('slug', (string) $tag, 'post_tag');
                    if ($term && !is_wp_error($term)) {
                        $url = get_term_link($term);
                        if (!is_wp_error($url)) {
                            $urls[] = $url;
                        }
                    }
                }
                break;

            case 'litespeed_purged_link':
            case 'litespeed_purged_front':
                if (is_string($arg) && $arg !== '') {
                    $u = strpos($arg, 'http') === 0 ? $arg : home_url($arg);
                    $urls[] = $u;
                }
                break;

            case 'litespeed_purged_frontpage':
            case 'litespeed_purged_pages':
                $urls[] = home_url('/');
                break;
        }

        return $urls;
    }

    /**
     * WordPress Action: save_post
     */
    public function log_wp_action_save_post($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $action_type = $update ? 'Updated' : 'Created';
        $this->store_wp_action('save_post', sprintf('%s %s #%d: %s', $action_type, $post->post_type, $post_id, $post->post_title));
    }

    /**
     * WordPress Action: post_updated
     */
    public function log_wp_action_post_updated($post_id, $post_after, $post_before)
    {
        $this->store_wp_action('post_updated', sprintf('%s #%d: %s', $post_after->post_type, $post_id, $post_after->post_title));
    }

    /**
     * WordPress Action: deleted_post
     */
    public function log_wp_action_deleted_post($post_id, $post)
    {
        $this->store_wp_action('deleted_post', sprintf('%s #%d: %s', $post->post_type, $post_id, $post->post_title));
    }

    /**
     * WordPress Action: transition_post_status
     */
    public function log_wp_action_transition_post_status($new_status, $old_status, $post)
    {
        if ($new_status !== $old_status) {
            $this->store_wp_action('transition_post_status', sprintf('%s #%d: %s → %s', $post->post_type, $post->ID, $old_status, $new_status));
        }
    }

    /**
     * WordPress Action: edited_term
     */
    public function log_wp_action_edited_term($term_id, $tt_id, $taxonomy)
    {
        $term = get_term($term_id, $taxonomy);
        $term_name = $term ? $term->name : "ID $term_id";
        $this->store_wp_action('edited_term', sprintf('%s: %s', $taxonomy, $term_name));
    }

    /**
     * WordPress Action: comment_post
     */
    public function log_wp_action_comment_post($comment_id, $approved, $commentdata)
    {
        $this->store_wp_action('comment_post', sprintf('Comment #%d on Post #%d', $comment_id, $commentdata['comment_post_ID']));
    }

    /**
     * WordPress Action: wp_update_nav_menu
     */
    public function log_wp_action_nav_menu($menu_id)
    {
        $menu = wp_get_nav_menu_object($menu_id);
        $menu_name = $menu ? $menu->name : "ID $menu_id";
        $this->store_wp_action('wp_update_nav_menu', sprintf('Menu: %s', $menu_name));
    }

    /**
     * WooCommerce Action: woocommerce_update_product
     */
    public function log_wp_action_wc_update_product($product_id)
    {
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "ID $product_id";
        $this->store_wp_action('woocommerce_update_product', sprintf('Product #%d: %s', $product_id, $product_name));
    }

    /**
     * WooCommerce Action: woocommerce_new_product
     */
    public function log_wp_action_wc_new_product($product_id)
    {
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "ID $product_id";
        $this->store_wp_action('woocommerce_new_product', sprintf('Product #%d: %s', $product_id, $product_name));
    }

    /**
     * WooCommerce Action: woocommerce_delete_product
     */
    public function log_wp_action_wc_delete_product($product_id)
    {
        $this->store_wp_action('woocommerce_delete_product', sprintf('Product #%d deleted', $product_id));
    }

    /**
     * WooCommerce Action: woocommerce_product_set_stock
     */
    public function log_wp_action_wc_stock_change($product)
    {
        $product_id = $product->get_id();
        $stock = $product->get_stock_quantity();
        $this->store_wp_action('woocommerce_product_set_stock', sprintf('Product #%d stock: %s', $product_id, $stock));
    }

    /**
     * WooCommerce Action: woocommerce_update_order
     */
    public function log_wp_action_wc_update_order($order_id)
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $status = $order ? $order->get_status() : 'unknown';
        $this->store_wp_action('woocommerce_update_order', sprintf('Order #%d (%s)', $order_id, $status));
    }

    /**
     * WooCommerce Action: woocommerce_order_status_changed
     */
    public function log_wp_action_wc_order_status_changed($order_id, $old_status, $new_status, $order)
    {
        $this->store_wp_action('woocommerce_order_status_changed', sprintf('Order #%d: %s → %s', $order_id, $old_status, $new_status));
    }

    /**
     * WooCommerce Action: woocommerce_new_order
     */
    public function log_wp_action_wc_new_order($order_id)
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $status = $order ? $order->get_status() : 'unknown';
        $this->store_wp_action('woocommerce_new_order', sprintf('New Order #%d (%s)', $order_id, $status));
    }

    /**
     * WooCommerce Action: woocommerce_delete_order
     */
    public function log_wp_action_wc_delete_order($order_id)
    {
        $this->store_wp_action('woocommerce_delete_order', sprintf('Order #%d deleted', $order_id));
    }

    /**
     * WooCommerce Action: woocommerce_payment_complete
     */
    public function log_wp_action_wc_payment_complete($order_id)
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $total = $order ? $order->get_total() : '0';
        $this->store_wp_action('woocommerce_payment_complete', sprintf('Payment completed for Order #%d (Total: %s)', $order_id, $total));
    }

    /**
     * WooCommerce Action: woocommerce_product_set_stock_status
     */
    public function log_wp_action_wc_stock_status_change($product)
    {
        $product_id = $product->get_id();
        $stock_status = $product->get_stock_status();
        $this->store_wp_action('woocommerce_product_set_stock_status', sprintf('Product #%d stock status: %s', $product_id, $stock_status));
    }

    /**
     * WooCommerce Action: woocommerce_save_product_variation
     */
    public function log_wp_action_wc_save_variation($variation_id, $i)
    {
        $variation = function_exists('wc_get_product') ? wc_get_product($variation_id) : null;
        $parent_id = $variation ? $variation->get_parent_id() : 0;
        $this->store_wp_action('woocommerce_save_product_variation', sprintf('Variation #%d (Product #%d)', $variation_id, $parent_id));
    }

    /**
     * REST API Action: rest_after_insert_post
     */
    public function log_wp_action_rest_insert_post($post, $request, $creating)
    {
        $action_type = $creating ? 'Created via REST API' : 'Updated via REST API';
        $this->store_wp_action('rest_after_insert_post', sprintf('%s: %s #%d (%s)', $action_type, $post->post_type, $post->ID, $post->post_title));
    }

    /**
     * REST API Action: rest_after_insert_product (WooCommerce)
     */
    public function log_wp_action_rest_insert_product($post, $request, $creating)
    {
        $action_type = $creating ? 'Created via REST API' : 'Updated via REST API';
        $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
        $product_name = $product ? $product->get_name() : $post->post_title;
        $this->store_wp_action('rest_after_insert_product', sprintf('%s: Product #%d (%s)', $action_type, $post->ID, $product_name));
    }

    /**
     * REST API Action: rest_after_insert_shop_order (WooCommerce)
     */
    public function log_wp_action_rest_insert_order($post, $request, $creating)
    {
        $action_type = $creating ? 'Created via REST API' : 'Updated via REST API';
        $order = function_exists('wc_get_order') ? wc_get_order($post->ID) : null;
        $status = $order ? $order->get_status() : 'unknown';
        $this->store_wp_action('rest_after_insert_shop_order', sprintf('%s: Order #%d (%s)', $action_type, $post->ID, $status));
    }

    /**
     * REST API Action: rest_delete_post
     */
    public function log_wp_action_rest_delete_post($post, $response, $request)
    {
        $this->store_wp_action('rest_delete_post', sprintf('Deleted via REST API: %s #%d (%s)', $post->post_type, $post->ID, $post->post_title));
    }

    /**
     * REST API Action: rest_after_insert_term
     */
    public function log_wp_action_rest_insert_term($term, $request, $creating)
    {
        $action_type = $creating ? 'Created via REST API' : 'Updated via REST API';
        $this->store_wp_action('rest_after_insert_term', sprintf('%s: %s (%s)', $action_type, $term->taxonomy, $term->name));
    }

    public function log_purge($arg1 = '', $arg2 = '')
    {
        if (!$this->monitoring_enabled) {
            return;
        }

        $hook = current_action();
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $source = '';
        $plugin_name = 'Unknown';
        $trigger_plugin = 'Unknown';
        $trigger_source = '';

        // First pass: Find cache plugin that fired the hook
        foreach ($backtrace as $trace) {
            if (isset($trace['file']) && strpos($trace['file'], 'wp-content/plugins') !== false && $trace['file'] !== __FILE__) {
                $source = basename(dirname($trace['file'])) . '/' . basename($trace['file']) . ':' . $trace['line'];

                // Extract cache plugin name
                if (preg_match('#wp-content/plugins/([^/]+)/#', $trace['file'], $matches)) {
                    $plugin_name = $matches[1];
                }
                break;
            }
        }

        // Second pass: Find the plugin/theme that triggered the action
        foreach ($backtrace as $trace) {
            if (!isset($trace['file'])) {
                continue;
            }

            // Skip cache plugins themselves
            if (strpos($trace['file'], 'litespeed-cache') !== false ||
                strpos($trace['file'], 'wp-cloudflare-page-cache') !== false ||
                strpos($trace['file'], 'wk-cache-manager') !== false) {
                continue;
            }

            // Check for plugin trigger
            if (strpos($trace['file'], 'wp-content/plugins') !== false) {
                if (preg_match('#wp-content/plugins/([^/]+)/#', $trace['file'], $matches)) {
                    $trigger_plugin = $matches[1];
                    $trigger_source = basename($trace['file']) . ':' . $trace['line'];
                    break;
                }
            }

            // Check for theme trigger
            if (strpos($trace['file'], 'wp-content/themes') !== false) {
                if (preg_match('#wp-content/themes/([^/]+)/#', $trace['file'], $matches)) {
                    $trigger_plugin = 'theme:' . $matches[1];
                    $trigger_source = basename($trace['file']) . ':' . $trace['line'];
                    break;
                }
            }
        }

        // If no plugin/theme found, might be WordPress Core or direct admin action
        if ($trigger_plugin === 'Unknown') {
            $wp_action = $this->get_recent_wp_action();
            if ($wp_action) {
                // It's triggered by a WordPress action
                $trigger_plugin = 'WordPress Core';
                $trigger_source = 'via ' . explode('(', $wp_action)[0];
            } elseif (is_admin()) {
                $trigger_plugin = 'Manual (Admin)';
                $trigger_source = 'Direct user action';
            }
        }

        // Get context (admin page, frontend, AJAX, etc.)
        $context = $this->get_request_context();

        $arg1_str = '';
        if (is_array($arg1)) {
            $count = count($arg1);
            $arg1_str = sprintf('Array (count: %d, URLs: [%s])', $count, implode(' | ', array_map('strval', $arg1)));
        } else {
            $arg1_str = is_scalar($arg1) ? $arg1 : gettype($arg1);
        }

        $arg2_str = '';
        if (is_array($arg2)) {
            $count = count($arg2);
            $arg2_str = sprintf('Array (count: %d, URLs: [%s])', $count, implode(' | ', array_map('strval', $arg2)));
        } else {
            $arg2_str = is_scalar($arg2) ? $arg2 : gettype($arg2);
        }

        // Get the WordPress action that might have triggered this purge
        $wp_action = $this->get_recent_wp_action();
        $wp_action_str = $wp_action ? ', WP_Action=' . $wp_action : '';

        $log_entry = sprintf(
            "[%s] PURGE TRIGGERED: CachePlugin=%s, Hook=%s, TriggerPlugin=%s, TriggerSource=%s, Context=%s, Source=%s, Arg1=%s, Arg2=%s%s\n",
            current_time('mysql'),
            $plugin_name,
            $hook,
            $trigger_plugin,
            $trigger_source ?: '-',
            $context,
            $source ?: 'Unknown',
            $arg1_str,
            $arg2_str,
            $wp_action_str
        );
        $this->buffer_log($log_entry);
    }

    public function log_purge_complete($arg = '')
    {
        // Check if Cache Monitor is enabled (using cached value for performance)
        if (!$this->monitoring_enabled) {
            return;
        }

        $hook = current_action();
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $source = '';
        $plugin_name = 'Unknown';
        $trigger_plugin = 'Unknown';
        $trigger_source = '';

        // First pass: Find cache plugin that fired the hook
        foreach ($backtrace as $trace) {
            if (isset($trace['file']) && strpos($trace['file'], 'wp-content/plugins') !== false && $trace['file'] !== __FILE__) {
                $source = basename(dirname($trace['file'])) . '/' . basename($trace['file']) . ':' . $trace['line'];

                // Extract cache plugin name
                if (preg_match('#wp-content/plugins/([^/]+)/#', $trace['file'], $matches)) {
                    $plugin_name = $matches[1];
                }
                break;
            }
        }

        // Second pass: Find the plugin/theme that triggered the action
        foreach ($backtrace as $trace) {
            if (!isset($trace['file'])) {
                continue;
            }

            // Skip cache plugins themselves
            if (strpos($trace['file'], 'litespeed-cache') !== false ||
                strpos($trace['file'], 'wp-cloudflare-page-cache') !== false ||
                strpos($trace['file'], 'wk-cache-manager') !== false) {
                continue;
            }

            // Check for plugin trigger
            if (strpos($trace['file'], 'wp-content/plugins') !== false) {
                if (preg_match('#wp-content/plugins/([^/]+)/#', $trace['file'], $matches)) {
                    $trigger_plugin = $matches[1];
                    $trigger_source = basename($trace['file']) . ':' . $trace['line'];
                    break;
                }
            }

            // Check for theme trigger
            if (strpos($trace['file'], 'wp-content/themes') !== false) {
                if (preg_match('#wp-content/themes/([^/]+)/#', $trace['file'], $matches)) {
                    $trigger_plugin = 'theme:' . $matches[1];
                    $trigger_source = basename($trace['file']) . ':' . $trace['line'];
                    break;
                }
            }
        }

        // If no plugin/theme found, might be WordPress Core or direct admin action
        if ($trigger_plugin === 'Unknown') {
            $wp_action = $this->get_recent_wp_action();
            if ($wp_action) {
                $trigger_plugin = 'WordPress Core';
                $trigger_source = 'via ' . explode('(', $wp_action)[0];
            } elseif (is_admin()) {
                $trigger_plugin = 'Manual (Admin)';
                $trigger_source = 'Direct user action';
            }
        }

        $context = $this->get_request_context();

        // Resolve hook-specific scalar args (post/cat/tag IDs) into actual URLs
        // so cards 5a (Most Frequently Purged URLs) and 5b (Recently Purged URLs)
        // can populate from per-item completion hooks, not just URL-array hooks.
        $resolved_urls = $this->resolve_purge_urls_from_hook($hook, $arg);

        if (!empty($resolved_urls)) {
            $arg_str = sprintf('Array (count: %d, URLs: [%s])', count($resolved_urls), implode(' | ', $resolved_urls));
        } elseif (is_array($arg)) {
            $arg_str = sprintf('Array (count: %d, URLs: [%s])', count($arg), implode(' | ', array_map('strval', $arg)));
        } else {
            $arg_str = is_scalar($arg) ? $arg : gettype($arg);
        }

        // Get the WordPress action that might have triggered this purge
        $wp_action = $this->get_recent_wp_action();
        $wp_action_str = $wp_action ? ', WP_Action=' . $wp_action : '';

        $log_entry = sprintf(
            "[%s] PURGE COMPLETED: CachePlugin=%s, Hook=%s, TriggerPlugin=%s, TriggerSource=%s, Context=%s, Source=%s, Arg=%s%s\n",
            current_time('mysql'),
            $plugin_name,
            $hook,
            $trigger_plugin,
            $trigger_source ?: '-',
            $context,
            $source ?: 'Unknown',
            $arg_str,
            $wp_action_str
        );
        $this->buffer_log($log_entry);
    }

    /**
     * Log WooCommerce webhook deliveries
     * This catches external integrations (Klaviyo, Peakws, etc.) making changes via webhooks
     *
     * @param array $http_args HTTP request arguments
     * @param string $arg2 Webhook resource (product, order, etc.)
     * @param string $arg3 Webhook event (created, updated, deleted)
     * @param int $arg4 Webhook ID
     * @param int|object $arg5 Resource ID or object
     */
    public function log_webhook_delivery($http_args, $arg2, $arg3, $arg4, $arg5)
    {
        // Check if Cache Monitor is enabled
        if (!$this->monitoring_enabled) {
            return;
        }

        $hook = current_action();
        $context = $this->get_request_context();

        // Extract webhook details
        $webhook_id = is_numeric($arg4) ? $arg4 : 'Unknown';
        $resource = is_string($arg2) ? $arg2 : 'Unknown';
        $event = is_string($arg3) ? $arg3 : 'Unknown';

        // Get resource ID
        $resource_id = 'Unknown';
        if (is_numeric($arg5)) {
            $resource_id = $arg5;
        } elseif (is_object($arg5) && method_exists($arg5, 'get_id')) {
            $resource_id = $arg5->get_id();
        }

        // Try to identify the external service from webhook URL
        $webhook_url = isset($http_args['url']) ? $http_args['url'] : 'Unknown';
        $external_service = 'Unknown';

        if (stripos($webhook_url, 'klaviyo') !== false) {
            $external_service = 'Klaviyo';
        } elseif (stripos($webhook_url, 'peakws') !== false || stripos($webhook_url, 'peak') !== false) {
            $external_service = 'Peakws';
        } elseif (stripos($webhook_url, 'zapier') !== false) {
            $external_service = 'Zapier';
        } elseif (stripos($webhook_url, 'integromat') !== false || stripos($webhook_url, 'make.com') !== false) {
            $external_service = 'Make (Integromat)';
        } else {
            // Try to extract domain from URL
            $parsed_url = parse_url($webhook_url);
            if (isset($parsed_url['host'])) {
                $external_service = $parsed_url['host'];
            }
        }

        $log_entry = sprintf(
            "[%s] WEBHOOK DELIVERY: Hook=%s, ExternalService=%s, Resource=%s, Event=%s, ResourceID=%s, WebhookID=%s, Context=%s, URL=%s\n",
            current_time('mysql'),
            $hook,
            $external_service,
            $resource,
            $event,
            $resource_id,
            $webhook_id,
            $context,
            $webhook_url
        );

        $this->buffer_log($log_entry);
    }

    /**
     * Track REST API authentication to identify which API key is being used
     *
     * This hook runs during REST API authentication and logs:
     * - API key description (e.g., "Klaviyo", "Peakwms")
     * - User associated with the key
     * - Endpoint being accessed
     * - Request method (GET, POST, PUT, DELETE)
     *
     * @param WP_Error|null|bool $result Authentication result
     * @return WP_Error|null|bool Pass through the authentication result
     */
    public function track_rest_api_auth($result)
    {
        // Only log if this is an actual REST API request (not admin or frontend)
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return $result;
        }

        // Get current user (if authenticated via API key)
        $user = wp_get_current_user();

        // Try to identify the API key being used
        $api_key_info = $this->identify_api_key();

        if (!$api_key_info) {
            // No API key detected, might be cookie auth or not authenticated
            return $result;
        }

        // Get request details
        $request_uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        $request_method = $_SERVER['REQUEST_METHOD'] ?? 'Unknown';
        $context = $this->get_request_context();

        // Parse endpoint from URI
        $endpoint = 'Unknown';
        if (preg_match('#/wp-json/(.+?)(\?|$)#', $request_uri, $matches)) {
            $endpoint = $matches[1];
        }

        // Log the API request with key information
        $log_entry = sprintf(
            "[%s] REST API REQUEST: APIKey=%s (ID:%s), User=%s, Endpoint=%s, Method=%s, Context=%s\n",
            current_time('mysql'),
            $api_key_info['description'],
            $api_key_info['key_id'],
            $user->user_login ?? 'Unknown',
            $endpoint,
            $request_method,
            $context
        );

        $this->buffer_log($log_entry);

        // Pass through the authentication result unchanged
        return $result;
    }

    /**
     * Identify which WooCommerce REST API key is being used
     *
     * Checks HTTP Basic Auth headers for WooCommerce API credentials
     * and looks up the key in the database to get description and user info
     *
     * @return array|null Array with key info or null if not found
     */
    private function identify_api_key()
    {
        global $wpdb;

        // Check for HTTP Basic Auth (WooCommerce REST API uses this)
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            return null;
        }

        $consumer_key = $_SERVER['PHP_AUTH_USER'];

        // Look up the API key in WooCommerce keys table
        $table_name = $wpdb->prefix . 'woocommerce_api_keys';

        // Check if table exists (WooCommerce might not be active)
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            return null;
        }

        // Query for the API key (match truncated consumer key)
        $key_data = $wpdb->get_row($wpdb->prepare(
            "SELECT key_id, description, user_id, permissions, last_access
             FROM {$table_name}
             WHERE consumer_key LIKE %s
             LIMIT 1",
            $consumer_key . '%'
        ), ARRAY_A);

        if (!$key_data) {
            return null;
        }

        return [
            'key_id' => $key_data['key_id'],
            'description' => $key_data['description'] ?: 'Unnamed API Key',
            'user_id' => $key_data['user_id'],
            'permissions' => $key_data['permissions'],
            'last_access' => $key_data['last_access']
        ];
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'wk-cache-manager',
            __('Cache Purging Monitor', 'wk-cache-manager'),
            __('Cache Purging Monitor', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-cache-monitor-settings',
            [$this, 'render_combined_page']
        );

        // Hidden alias for old logs URL
        add_submenu_page(
            null,
            __('Cache Purge Logs', 'wk-cache-manager'),
            __('Cache Purge Logs', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-monitor',
            [$this, 'redirect_to_logs']
        );
    }

    public function redirect_to_logs()
    {
        wp_safe_redirect(admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings&view=logs'));
        exit;
    }

    public function render_combined_page()
    {
        $view = isset($_GET['view']) && $_GET['view'] === 'logs' ? 'logs' : 'settings';
        if ($view === 'logs') {
            $this->admin_page();
        } else {
            $this->render_settings_page();
        }
    }

    /**
     * Render Cache Monitor settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle settings save
        $settings_saved = false;
        if (isset($_POST['wk_cache_monitor_save_settings']) && check_admin_referer('wk_cache_monitor_settings')) {
            $enabled = isset($_POST['cache_monitor_enabled']) ? 1 : 0;
            $lightweight = isset($_POST['cache_monitor_lightweight']) ? 1 : 0;
            $track_api_keys = isset($_POST['cache_monitor_track_api_keys']) ? 1 : 0;
            $retention_days = absint($_POST['log_retention_days']);
            // Store as int (0/1). LiteSpeed/Redis object cache can cache option
            // values; flushing the cache after update_option forces the next
            // get_option() in this same request to read the fresh value.
            update_option('wk_cache_manager_cache_monitor_enabled', $enabled, false);
            update_option('wk_cache_manager_cache_monitor_lightweight', $lightweight, false);
            update_option('wk_cache_manager_cache_monitor_track_api_keys', $track_api_keys, false);
            update_option('wk_cache_manager_cache_monitor_log_retention_days', $retention_days, false);
            wp_cache_delete('wk_cache_manager_cache_monitor_enabled', 'options');
            wp_cache_delete('wk_cache_manager_cache_monitor_lightweight', 'options');
            wp_cache_delete('wk_cache_manager_cache_monitor_track_api_keys', 'options');
            wp_cache_delete('wk_cache_manager_cache_monitor_log_retention_days', 'options');
            wp_cache_delete('alloptions', 'options');
            $settings_saved = true;
        }

        // Get current settings
        $enabled = get_option('wk_cache_manager_cache_monitor_enabled', false);
        $lightweight = get_option('wk_cache_manager_cache_monitor_lightweight', false);
        $track_api_keys = get_option('wk_cache_manager_cache_monitor_track_api_keys', false);
        $retention_days = get_option('wk_cache_manager_cache_monitor_log_retention_days', 30);

        \WKCacheManager\Admin::render_shell_open('Cache Purging Monitor', 'Track cache purge events from LiteSpeed + CloudFlare.', 'cache-monitor');
        \WKCacheManager\Admin::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings&view=logs'), 'label' => 'Logs'],
        ], 'settings');
        ?>
            <?php if ($settings_saved): ?>
                <div class="wkcm-inline-notice is-success"><?php echo esc_html__('Settings saved.', 'wk-cache-manager'); ?></div>
            <?php endif; ?>
            <div class="wkcm-main wkcm-main--stack">
                <?php
                \WKCacheManager\Admin::render_help(
                    __('How does Cache Purging Monitor work?', 'wk-cache-manager'),
                    '<p><strong>' . esc_html__('Problem it solves:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('On a typical WordPress + WooCommerce stack, dozens of plugins (WC core, third-party integrations, theme code) trigger cache purges. When the cache mysteriously goes cold and your hit rate drops, finding the culprit means reading code or watching error logs. This module records every single purge call.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('What gets captured per purge:', 'wk-cache-manager') . '</strong></p>'
                    . '<ul style="margin:0 0 8px 18px;line-height:1.6;">'
                    . '<li><strong>' . esc_html__('Cache plugin:', 'wk-cache-manager') . '</strong> ' . esc_html__('LiteSpeed Cache or WP CloudFlare Page Cache (whichever fired the hook).', 'wk-cache-manager') . '</li>'
                    . '<li><strong>' . esc_html__('Hook name:', 'wk-cache-manager') . '</strong> ' . esc_html__('The exact action that fired (e.g. litespeed_purge_finalize, swcfpc_purge_urls).', 'wk-cache-manager') . '</li>'
                    . '<li><strong>' . esc_html__('Trigger plugin/theme:', 'wk-cache-manager') . '</strong> ' . esc_html__('Walks the backtrace to find the originating plugin or theme that started the chain.', 'wk-cache-manager') . '</li>'
                    . '<li><strong>' . esc_html__('Context:', 'wk-cache-manager') . '</strong> ' . esc_html__('Admin page slug, REST endpoint, or AJAX action name.', 'wk-cache-manager') . '</li>'
                    . '<li><strong>' . esc_html__('WP action chain:', 'wk-cache-manager') . '</strong> ' . esc_html__('Recent save_post, woocommerce_update_product, rest_after_insert_*, etc. so you can see WHY a purge fired.', 'wk-cache-manager') . '</li>'
                    . '<li><strong>' . esc_html__('Affected URLs:', 'wk-cache-manager') . '</strong> ' . esc_html__('When the hook passes a URL array (e.g. swcfpc_purge_urls), all URLs are logged and grouped per batch.', 'wk-cache-manager') . '</li>'
                    . '</ul>'

                    . '<p><strong>' . esc_html__('Monitoring scope:', 'wk-cache-manager') . '</strong></p>'
                    . '<ul style="margin:0 0 8px 18px;line-height:1.6;">'
                    . '<li>' . esc_html__('34 LiteSpeed hooks (10 trigger + 24 completion phases of every purge type).', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('8 CloudFlare hooks (purge all, purge URLs, before/after pairs).', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('REST API insert/delete hooks for posts, products, orders, terms — so external integrations like Klaviyo are visible.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Optional WooCommerce webhook delivery tracking.', 'wk-cache-manager') . '</li>'
                    . '</ul>'

                    . '<p><strong>' . esc_html__('Analytics on the Logs tab:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('Top plugins by purge count, top hooks, top contexts, top triggering plugins, most-purged URLs, plus a grouped expandable view of recent purge batches.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('Read-only:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('This module does NOT block any purge. It only observes and logs. Use Purge Prevention if you want to actually stop unnecessary purges.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('Performance:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('Keep this OFF in normal production. Enable only during debugging sessions — buffered writes still cost ~1-2ms per hook fire.', 'wk-cache-manager') . '</p>'
                );
                ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">01</span>
                        <h2><?php esc_html_e('Settings', 'wk-cache-manager'); ?></h2>
                    </div>
                    <div class="wkcm-card-body">
                        <form method="post" action="">
                            <?php wp_nonce_field('wk_cache_monitor_settings'); ?>
                            <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cache_monitor_enabled"><?php echo __('Enable Cache Purge Monitoring', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="cache_monitor_enabled" name="cache_monitor_enabled" value="1" <?php checked($enabled, true); ?>>
                                <?php echo __('Enable monitoring of cache purge events', 'wk-cache-manager'); ?>
                            </label>
                            <p class="description">
                                <?php echo __('Turn this OFF to completely disable cache purge monitoring. When disabled, no purge events will be logged.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cache_monitor_track_api_keys"><?php echo __('Track API Keys', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="cache_monitor_track_api_keys" name="cache_monitor_track_api_keys" value="1" <?php checked($track_api_keys, true); ?>>
                                <?php echo __('Log which REST API key is used for each request', 'wk-cache-manager'); ?>
                            </label>
                            <p class="description">
                                <strong><?php echo __('Identify which external integration is making API calls', 'wk-cache-manager'); ?></strong><br>
                                <?php echo __('Shows: API key description (e.g., "Klaviyo", "Peakwms"), endpoint, and request method', 'wk-cache-manager'); ?><br>
                                <?php echo __('Example log: "REST API REQUEST: APIKey=Klaviyo (ID:27), Endpoint=wc/v3/products/123, Method=PUT"', 'wk-cache-manager'); ?><br>
                                <?php echo __('Note: Adds 1 database query per REST API request (minimal overhead)', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="log_retention_days"><?php echo __('Log Retention (Days)', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="log_retention_days" name="log_retention_days" value="<?php echo esc_attr($retention_days); ?>" min="1" max="365" class="small-text">
                            <?php echo __('days', 'wk-cache-manager'); ?>
                            <p class="description">
                                <?php echo __('Number of days to keep cache monitor logs. Older logs will be automatically deleted.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="wk_cache_monitor_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'wk-cache-manager'); ?>">
                </p>
            </form>
                    </div>
                </div>

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">03</span>
                        <h2><?php esc_html_e('Current Status', 'wk-cache-manager'); ?></h2>
                    </div>
                    <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <tbody>
                                <tr><td>Monitoring Status</td><td><?php echo $enabled ? '<span class="wkcm-badge is-success">Enabled</span>' : '<span class="wkcm-badge is-muted">Disabled</span>'; ?></td></tr>
                                <tr><td>Total Log Files</td><td><?php echo count($this->get_all_log_files()); ?></td></tr>
                                <tr><td>Log Retention</td><td><?php echo esc_html($retention_days); ?> days</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">04</span>
                        <h2><?php esc_html_e('Quick Links', 'wk-cache-manager'); ?></h2>
                    </div>
                    <div class="wkcm-card-body">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-monitor')); ?>" class="button button-primary"><?php esc_html_e('View Cache Purge Logs', 'wk-cache-manager'); ?></a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager')); ?>" class="button"><?php esc_html_e('Back to Dashboard', 'wk-cache-manager'); ?></a>
                    </div>
                </div>
            </div>
        <?php
        \WKCacheManager\Admin::render_shell_close();
    }

    /**
     * Parse log for cache purge entries that include URL array.
     *
     * @param int $days  Days to scan.
     * @param int $limit Max URLs to return.
     * @return array list of ['ts','plugin','hook','url']
     */
    public function get_recent_purged_urls($days = 7, $limit = 50)
    {
        $files = $this->get_recent_log_files($days);
        $out = [];
        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_reverse($lines) as $line) {
                // [TS] PURGE TRIGGERED|COMPLETED: CachePlugin=X, Hook=Y, ..., Arg1=Array (count: N, URLs: [u1 | u2 | u3])
                if (!preg_match('/^\[(?P<ts>[^\]]+)\]\s+PURGE\s+(?:TRIGGERED|COMPLETED):.*?CachePlugin=(?P<plugin>[^,]+).*?Hook=(?P<hook>[^,]+).*?(Arg1?|Arg)=Array \(count: \d+, URLs: \[(?P<urls>[^\]]+)\]\)/', $line, $m)) {
                    continue;
                }
                $urls = array_map('trim', explode('|', $m['urls']));
                foreach ($urls as $url) {
                    if ($url === '') continue;
                    $out[] = [
                        'ts' => $m['ts'],
                        'plugin' => trim($m['plugin']),
                        'hook' => trim($m['hook']),
                        'url' => $url,
                    ];
                    if (count($out) >= $limit) break 3;
                }
            }
        }
        return $out;
    }

    /**
     * Group recent purge events into batches (one batch = one purge call that
     * invalidated N URLs together). Groups by (timestamp truncated to second +
     * plugin + hook) so a single purge action collapses into one row with all
     * its URLs nested inside.
     *
     * @return array list of ['ts','plugin','hook','urls'=>[...]]
     */
    public function get_recent_purge_batches($days = 7, $limit = 50)
    {
        $files = $this->get_recent_log_files($days);
        $batches = []; // key => batch
        $order = [];   // keys in newest-first order

        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_reverse($lines) as $line) {
                if (!preg_match('/^\[(?P<ts>[^\]]+)\]\s+PURGE\s+(?:TRIGGERED|COMPLETED):.*?CachePlugin=(?P<plugin>[^,]+).*?Hook=(?P<hook>[^,]+).*?(Arg1?|Arg)=Array \(count: \d+, URLs: \[(?P<urls>[^\]]+)\]\)/', $line, $m)) {
                    continue;
                }
                $ts = trim($m['ts']);
                $plugin = trim($m['plugin']);
                $hook = trim($m['hook']);
                $key = $ts . '|' . $plugin . '|' . $hook;
                if (!isset($batches[$key])) {
                    $batches[$key] = [
                        'ts' => $ts,
                        'plugin' => $plugin,
                        'hook' => $hook,
                        'urls' => [],
                    ];
                    $order[] = $key;
                    if (count($order) > $limit) break 2;
                }
                foreach (explode('|', $m['urls']) as $url) {
                    $url = trim($url);
                    if ($url !== '' && !in_array($url, $batches[$key]['urls'], true)) {
                        $batches[$key]['urls'][] = $url;
                    }
                }
            }
        }

        $out = [];
        foreach ($order as $key) {
            $out[] = $batches[$key];
        }
        return $out;
    }

    /**
     * Group purged URLs by URL, count occurrences.
     *
     * @param int $days
     * @param int $limit
     * @return array list of ['url','count','last_ts','hooks'=>[hook=>count]]
     */
    public function get_purged_url_frequency($days = 7, $limit = 20)
    {
        $files = $this->get_recent_log_files($days);
        $agg = []; // url => stats
        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (!preg_match('/^\[(?P<ts>[^\]]+)\]\s+PURGE\s+(?:TRIGGERED|COMPLETED):.*?Hook=(?P<hook>[^,]+).*?(Arg1?|Arg)=Array \(count: \d+, URLs: \[(?P<urls>[^\]]+)\]\)/', $line, $m)) {
                    continue;
                }
                $hook = trim($m['hook']);
                foreach (explode('|', $m['urls']) as $url) {
                    $url = trim($url);
                    if ($url === '') continue;
                    if (!isset($agg[$url])) {
                        $agg[$url] = ['url' => $url, 'count' => 0, 'last_ts' => '', 'hooks' => []];
                    }
                    $agg[$url]['count']++;
                    $agg[$url]['last_ts'] = $m['ts'];
                    $agg[$url]['hooks'][$hook] = ($agg[$url]['hooks'][$hook] ?? 0) + 1;
                }
            }
        }
        uasort($agg, fn($a, $b) => $b['count'] <=> $a['count']);
        return array_slice(array_values($agg), 0, $limit);
    }

    public function admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle settings save
        $settings_saved = false;
        if (isset($_POST['wk_cache_monitor_save_settings']) && check_admin_referer('wk_cache_monitor_settings')) {
            $enabled = isset($_POST['cache_monitor_enabled']) ? 1 : 0;
            $lightweight = isset($_POST['cache_monitor_lightweight']) ? 1 : 0;
            $track_api_keys = isset($_POST['cache_monitor_track_api_keys']) ? 1 : 0;
            $retention_days = absint($_POST['log_retention_days']);
            // Store as int (0/1). LiteSpeed/Redis object cache can cache option
            // values; flushing the cache after update_option forces the next
            // get_option() in this same request to read the fresh value.
            update_option('wk_cache_manager_cache_monitor_enabled', $enabled, false);
            update_option('wk_cache_manager_cache_monitor_lightweight', $lightweight, false);
            update_option('wk_cache_manager_cache_monitor_track_api_keys', $track_api_keys, false);
            update_option('wk_cache_manager_cache_monitor_log_retention_days', $retention_days, false);
            wp_cache_delete('wk_cache_manager_cache_monitor_enabled', 'options');
            wp_cache_delete('wk_cache_manager_cache_monitor_lightweight', 'options');
            wp_cache_delete('wk_cache_manager_cache_monitor_track_api_keys', 'options');
            wp_cache_delete('wk_cache_manager_cache_monitor_log_retention_days', 'options');
            wp_cache_delete('alloptions', 'options');
            $settings_saved = true;
        }

        $clear_log = false;
        $clean_old = false;
        if (isset($_GET['clear_log']) && $_GET['clear_log'] == 1) {
            if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'clear_wk_cache_log')) {
                $this->clear_all_logs();
                $clear_log = true;
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }
        if (isset($_GET['clean_old']) && $_GET['clean_old'] == 1) {
            if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'clean_wk_cache_log')) {
                $this->cleanup_old_logs();
                $clean_old = true;
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }

        $show_raw = isset($_GET['raw']) && $_GET['raw'] == 1;

        // Date picker — default today, allow any past day with a log file
        $available_dates = $this->get_available_log_dates();
        $today = current_time('Y-m-d');
        $selected_date = isset($_GET['log_date']) ? sanitize_text_field($_GET['log_date']) : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
            $selected_date = $today;
        }

        $log_content = '';
        $events = array();
        $scope_counts = array('single' => 0, 'multi' => 0, 'full' => 0, 'completion' => 0);
        $grouped = array();

        // Read full content of selected day's log
        $log_file = $this->log_dir . 'cache-monitor-' . $selected_date . '.log';
        if (file_exists($log_file)) {
            $content = file_get_contents($log_file);
            $all_lines = explode("\n", $content);
            $log_content = $content;
            $lines = array_filter($all_lines);
            $lines = array_reverse($lines);
            // Full day — no slice

            foreach ($lines as $line) {
                // Parse DEBUG entries differently
                if (preg_match('/^\[(.+?)\] DEBUG: Hook fired: (.+?)$/', $line, $debug_matches)) {
                    $time = $debug_matches[1];
                    $hook = $debug_matches[2];

                    $key = 'debug|' . $hook;
                    if (!isset($grouped[$key])) {
                        $grouped[$key] = array(
                            'count' => 0,
                            'time' => $time,
                            'type' => 'DEBUG',
                            'hook' => $hook,
                            'source' => 'WordPress Core',
                            'scope' => 'Hook Detection',
                            'details' => 'Detected hook: ' . $hook,
                            'wp_action' => ''
                        );
                    }
                    $grouped[$key]['count']++;
                    continue;
                }

                // Parse regular purge entries (NEWEST FORMAT with Trigger)
                if (preg_match('/^\[(.+?)\] (.+?): CachePlugin=(.+?), Hook=(.+?), TriggerPlugin=(.+?), TriggerSource=(.+?), Context=(.+?), Source=(.+?), (.+?)=(.*?)((?:, .+?=.+?)*)$/', $line, $matches)) {
                    $time = $matches[1];
                    $type = $matches[2];
                    $plugin = $matches[3];
                    $hook = $matches[4];
                    $trigger_plugin = $matches[5];
                    $trigger_source = $matches[6];
                    $context = $matches[7];
                    $source = $matches[8];

                    // Extract WP_Action if present
                    $wp_action = '';
                    if (preg_match('/WP_Action=(.+?)(?:,|$)/', $line, $wp_matches)) {
                        $wp_action = trim($wp_matches[1]);
                    }
                }
                // Parse OLD FORMAT with Plugin and Context (backwards compatibility)
                elseif (preg_match('/^\[(.+?)\] (.+?): Plugin=(.+?), Hook=(.+?), Context=(.+?), Source=(.+?), (.+?)=(.*?)((?:, .+?=.+?)*)$/', $line, $matches)) {
                    $time = $matches[1];
                    $type = $matches[2];
                    $plugin = $matches[3];
                    $hook = $matches[4];
                    $trigger_plugin = 'Unknown';
                    $trigger_source = '-';
                    $context = $matches[5];
                    $source = $matches[6];

                    // Extract WP_Action if present
                    $wp_action = '';
                    if (preg_match('/WP_Action=(.+?)(?:,|$)/', $line, $wp_matches)) {
                        $wp_action = trim($wp_matches[1]);
                    }
                }
                // Parse OLDEST FORMAT (backwards compatibility)
                elseif (preg_match('/^\[(.+?)\] (.+?): Hook=(.+?), Source=(.+?), (.+?)=(.*?)((?:, .+?=.+?)*)$/', $line, $matches)) {
                    $time = $matches[1];
                    $type = $matches[2];
                    $plugin = 'Unknown';
                    $hook = $matches[3];
                    $trigger_plugin = 'Unknown';
                    $trigger_source = '-';
                    $context = 'Unknown';
                    $source = $matches[4];

                    // Extract WP_Action if present
                    $wp_action = '';
                    if (preg_match('/WP_Action=(.+?)(?:,|$)/', $line, $wp_matches)) {
                        $wp_action = trim($wp_matches[1]);
                    }
                } else {
                    continue; // Skip lines that don't match
                }

                // Continue parsing arguments (same for both formats)
                {

                    $arg1 = '';
                    if (strpos($line, 'Arg1=') !== false) {
                        preg_match('/Arg1=(.+?)(?:, Arg2=|, WP_Action=|$)/', $line, $arg_matches);
                        $arg1 = trim($arg_matches[1] ?? '');
                    } elseif (strpos($line, 'Arg=') !== false) {
                        preg_match('/Arg=(.+?)(?:, WP_Action=|$)/', $line, $arg_matches);
                        $arg1 = trim($arg_matches[1] ?? '');
                    }

                    if (strpos($arg1, 'Array (count:') !== false) {
                        $arg1 = $arg1;
                    } elseif (strpos($arg1, 'array') !== false) {
                        $arg1 = 'Array of URLs';
                    } elseif (strpos($arg1, 'http') !== false || is_numeric($arg1)) {
                        $arg1 = $arg1;
                    } else {
                        $arg1 = $arg1;
                    }

                    $scope = 'Unknown';
                    $details = $arg1 ?: '';
                    if (strpos($hook, '_after') !== false || strpos($hook, 'purged') !== false || strpos($type, 'COMPLETED') !== false) {
                        $scope = 'Completion';
                        $scope_counts['completion']++;
                        $details = 'Purge completed (' . $arg1 . ')';
                    } elseif (strpos($hook, 'purge_all') !== false) {
                        $scope = 'Full Site';
                        $scope_counts['full']++;
                        $details = 'Full site cache cleared';
                    } elseif (strpos($hook, 'purge_url') !== false || strpos($hook, 'purge_post') !== false) {
                        $scope = 'Single Page';
                        $scope_counts['single']++;
                        $details = $arg1;
                    } elseif (strpos($hook, 'purge_urls') !== false) {
                        $scope = 'Multiple URLs';
                        $scope_counts['multi']++;
                        $details = $arg1 ?: 'Array of URLs';
                    }

                    $key = $hook . '|' . $plugin . '|' . $trigger_plugin . '|' . $context . '|' . $source . '|' . $type . '|' . substr($details, 0, 50) . '|' . $wp_action;
                    if (!isset($grouped[$key])) {
                        $grouped[$key] = array(
                            'count' => 0,
                            'time' => $time,
                            'type' => $type,
                            'hook' => $hook,
                            'plugin' => $plugin,
                            'trigger_plugin' => $trigger_plugin,
                            'trigger_source' => $trigger_source,
                            'context' => $context,
                            'source' => $source,
                            'scope' => $scope,
                            'details' => $details,
                            'wp_action' => $wp_action
                        );
                    }
                    $grouped[$key]['count']++;
                }
            }

            $events = array_values($grouped);
            usort($events, function ($a, $b) { return strtotime($b['time']) - strtotime($a['time']); });
        }

        // Get filter parameters
        $filter_plugin = isset($_GET['filter_plugin']) ? sanitize_text_field($_GET['filter_plugin']) : '';
        $filter_hook = isset($_GET['filter_hook']) ? sanitize_text_field($_GET['filter_hook']) : '';
        $filter_context = isset($_GET['filter_context']) ? sanitize_text_field($_GET['filter_context']) : '';
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';

        // Build analytics before filtering
        $hook_stats = array();
        $plugin_stats = array();
        $context_stats = array();
        $trigger_stats = array();
        foreach ($events as $event) {
            $hook = $event['hook'];
            $plugin = $event['plugin'] ?? 'Unknown';
            $context = $event['context'] ?? 'Unknown';
            $trigger = $event['trigger_plugin'] ?? 'Unknown';

            if (!isset($hook_stats[$hook])) {
                $hook_stats[$hook] = array('count' => 0, 'plugin' => $plugin);
            }
            $hook_stats[$hook]['count'] += $event['count'];

            if (!isset($plugin_stats[$plugin])) {
                $plugin_stats[$plugin] = 0;
            }
            $plugin_stats[$plugin] += $event['count'];

            if (!isset($context_stats[$context])) {
                $context_stats[$context] = 0;
            }
            $context_stats[$context] += $event['count'];

            if (!isset($trigger_stats[$trigger])) {
                $trigger_stats[$trigger] = 0;
            }
            $trigger_stats[$trigger] += $event['count'];
        }

        // Sort stats by count
        arsort($hook_stats);
        arsort($plugin_stats);
        arsort($context_stats);
        arsort($trigger_stats);

        // Apply filters
        if ($filter_plugin || $filter_hook || $filter_context || $filter_type) {
            $events = array_filter($events, function ($event) use ($filter_plugin, $filter_hook, $filter_context, $filter_type) {
                if ($filter_plugin && ($event['plugin'] ?? '') !== $filter_plugin) {
                    return false;
                }
                if ($filter_hook && $event['hook'] !== $filter_hook) {
                    return false;
                }
                if ($filter_context && ($event['context'] ?? '') !== $filter_context) {
                    return false;
                }
                if ($filter_type && $event['type'] !== $filter_type) {
                    return false;
                }
                return true;
            });
        }

        \WKCacheManager\Admin::render_shell_open('Cache Purging Monitor', 'Recent cache purge events grouped by scope.', 'cache-monitor');
        \WKCacheManager\Admin::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings&view=logs'), 'label' => 'Logs'],
        ], 'logs');
        echo '<div class="wkcm-main wkcm-main--stack">';

        if ($settings_saved) {
            echo '<div class="wkcm-inline-notice is-success">' . esc_html__('Settings saved.', 'wk-cache-manager') . '</div>';
        }

        \WKCacheManager\Admin::render_help(
            __('How to read these logs', 'wk-cache-manager'),
            '<p><strong>' . esc_html__('Status card (top):', 'wk-cache-manager') . '</strong> '
            . esc_html__('Monitoring on/off, today\'s log file size, total log files + size on disk, count of hooks being watched.', 'wk-cache-manager') . '</p>'

            . '<p><strong>' . esc_html__('Analytics cards (02-05):', 'wk-cache-manager') . '</strong></p>'
            . '<ul style="margin:0 0 8px 18px;line-height:1.6;">'
            . '<li><em>' . esc_html__('By plugin:', 'wk-cache-manager') . '</em> ' . esc_html__('Which cache plugin (LiteSpeed vs CloudFlare) ran each purge.', 'wk-cache-manager') . '</li>'
            . '<li><em>' . esc_html__('By context:', 'wk-cache-manager') . '</em> ' . esc_html__('Where the purge originated — admin page slug, REST endpoint, AJAX action, cron, or frontend.', 'wk-cache-manager') . '</li>'
            . '<li><em>' . esc_html__('Top hooks:', 'wk-cache-manager') . '</em> ' . esc_html__('Most-fired purge hooks. Useful for spotting noisy callbacks.', 'wk-cache-manager') . '</li>'
            . '<li><em>' . esc_html__('Triggered by:', 'wk-cache-manager') . '</em> ' . esc_html__('Plugin or theme that started the chain. "WordPress Core" means no plugin in backtrace = a built-in WP/WC action.', 'wk-cache-manager') . '</li>'
            . '</ul>'

            . '<p><strong>' . esc_html__('Most Frequently Purged URLs (5a):', 'wk-cache-manager') . '</strong> '
            . esc_html__('Same URL appearing many times = over-purging. Look at the hooks column to find the offending event.', 'wk-cache-manager') . '</p>'

            . '<p><strong>' . esc_html__('Recently Purged URLs (5b):', 'wk-cache-manager') . '</strong> '
            . esc_html__('One row per purge call. Click the + icon to expand the row and see every URL invalidated by that single call. Groups by timestamp + plugin + hook so a single product save that purges 50 URLs shows up as one row, not 50.', 'wk-cache-manager') . '</p>'

            . '<p><strong>' . esc_html__('Filter logs (06):', 'wk-cache-manager') . '</strong> '
            . esc_html__('Narrow down by plugin, hook, context, or event type. Useful when chasing one specific purge.', 'wk-cache-manager') . '</p>'

            . '<p><strong>' . esc_html__('Raw Log Entries (bottom):', 'wk-cache-manager') . '</strong> '
            . esc_html__('Full text dump of the selected day\'s log file. Toggle "View Raw Logs" / "View Chart & Summary" via the button.', 'wk-cache-manager') . '</p>'
        );

        // Date picker card
        echo '<div class="wkcm-card"><div class="wkcm-card-head"><span class="wkcm-card-num">00</span><h2>' . esc_html__('Date', 'wk-cache-manager') . '</h2><p>' . esc_html__('Pick a day to view its full log.', 'wk-cache-manager') . '</p></div><div class="wkcm-card-body">';
        echo '<form method="get" style="display:inline-block;">';
        echo '<input type="hidden" name="page" value="wk-cache-manager-cache-monitor-settings" />';
        echo '<input type="hidden" name="view" value="logs" />';
        if ($show_raw) { echo '<input type="hidden" name="raw" value="1" />'; }
        echo '<label for="log_date" style="margin-right:4px;">' . esc_html__('Date:', 'wk-cache-manager') . '</label>';
        echo '<select name="log_date" id="log_date" onchange="this.form.submit()">';
        if (empty($available_dates)) {
            echo '<option value="' . esc_attr($today) . '">' . esc_html($today) . '</option>';
        } else {
            foreach ($available_dates as $d) {
                $label = $d . ($d === $today ? ' ' . __('(today)', 'wk-cache-manager') : '');
                echo '<option value="' . esc_attr($d) . '"' . selected($selected_date, $d, false) . '>' . esc_html($label) . '</option>';
            }
        }
        echo '</select>';
        echo '</form>';
        echo '</div></div>';

        // Debug status notice
        $current_log_file = $this->get_current_log_file();
        $monitoring_status = get_option('wk_cache_manager_cache_monitor_enabled', false);
        $file_exists = file_exists($current_log_file);
        $file_size = $file_exists ? filesize($current_log_file) : 0;

        $all_files = $this->get_all_log_files();
        $total_size = 0;
        foreach ($all_files as $f) { if (file_exists($f)) $total_size += filesize($f); }

        echo '<div class="wkcm-card">';
        echo '<div class="wkcm-card-head"><span class="wkcm-card-num">01</span><h2>Status</h2><p>Live state of the monitor + log storage.</p></div>';
        echo '<div class="wkcm-card-body" style="padding:0;">';
        echo '<table class="wkcm-table"><tbody>';
        echo '<tr><td>Monitoring</td><td>' . ($monitoring_status ? '<span class="wkcm-badge is-success">Enabled</span>' : '<span class="wkcm-badge is-error">Disabled</span>') . '</td></tr>';
        echo '<tr><td>Today\'s log file</td><td><code>' . esc_html(basename($current_log_file)) . '</code></td></tr>';
        echo '<tr><td>Today file exists</td><td>' . ($file_exists ? '<span class="wkcm-badge is-success">Yes</span>' : '<span class="wkcm-badge is-error">No</span>') . '</td></tr>';
        echo '<tr><td>Today file size</td><td>' . esc_html(size_format($file_size)) . '</td></tr>';
        echo '<tr><td>Total log files</td><td>' . esc_html(number_format(count($all_files))) . '</td></tr>';
        echo '<tr><td>Total log size</td><td>' . esc_html(size_format($total_size)) . '</td></tr>';
        echo '<tr><td>LiteSpeed hooks watched</td><td>34 (10 trigger + 24 completion)</td></tr>';
        echo '<tr><td>CloudFlare hooks watched</td><td>8 (4 trigger + 4 completion)</td></tr>';
        echo '</tbody></table>';
        echo '</div></div>';

        // === ANALYTICS DASHBOARD ===
        if (!empty($hook_stats)) {
            $base = admin_url('admin.php?page=wk-cache-manager-monitor');
            $render_stat_card = function ($num, $title, $sub, $rows, $base, $filter_key, $monospaced = false) {
                echo '<div class="wkcm-card">';
                echo '<div class="wkcm-card-head"><span class="wkcm-card-num">' . esc_html($num) . '</span><h2>' . esc_html($title) . '</h2><p>' . esc_html($sub) . '</p></div>';
                echo '<div class="wkcm-card-body" style="padding:0;">';
                echo '<table class="wkcm-table"><tbody>';
                $i = 0;
                foreach ($rows as $key => $count) {
                    if ($i++ >= 8) break;
                    $url = $filter_key ? esc_url(add_query_arg($filter_key, urlencode($key), $base)) : null;
                    echo '<tr><td>' . ($monospaced ? '<code>' . esc_html($key) . '</code>' : esc_html($key)) . '</td>';
                    echo '<td style="text-align:right;"><span class="wkcm-badge is-muted">' . esc_html($count) . '</span></td>';
                    echo '<td style="text-align:right;width:70px;">' . ($url ? '<a href="' . $url . '" class="button button-small">Filter</a>' : '') . '</td></tr>';
                }
                echo '</tbody></table></div></div>';
            };
            echo '<div class="wkcm-grid-2" style="margin-bottom:16px;">';
            $render_stat_card('02', 'By plugin', 'Cache plugin firing the purge', $plugin_stats, $base, 'filter_plugin');
            $render_stat_card('03', 'By context', 'Where the purge happened', $context_stats, $base, 'filter_context');
            $hook_counts = array_map(fn($d) => is_array($d) ? $d['count'] : $d, $hook_stats);
            $render_stat_card('04', 'Top hooks', 'Most frequent purge hooks', $hook_counts, $base, 'filter_hook', true);
            $render_stat_card('05', 'Triggered by', 'Plugin/theme that initiated', $trigger_stats, $base, '');
            echo '</div>';

            // Most-purged URLs card
            $url_freq = $this->get_purged_url_frequency(7, 20);
            echo '<div class="wkcm-card">';
            echo '<div class="wkcm-card-head"><span class="wkcm-card-num">5a</span><h2>Most Frequently Purged URLs <span class="wkcm-badge is-warning" style="margin-left:8px;font-size:10px;">top ' . count($url_freq) . '</span></h2><p>Group by URL across all purge hooks (last 7 days). High counts may indicate over-purging.</p></div>';
            echo '<div class="wkcm-card-body" style="padding:0;">';
            if (empty($url_freq)) {
                echo '<div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span>No URL-level entries yet.</div>';
            } else {
                $max = $url_freq[0]['count'];
                echo '<table class="wkcm-table"><thead><tr><th>URL</th><th style="width:70px;">Count</th><th>Hooks</th><th>Last Seen</th></tr></thead><tbody>';
                foreach ($url_freq as $row) {
                    $bar_pct = $max > 0 ? round(($row['count'] / $max) * 100) : 0;
                    $hooks_str = implode(', ', array_keys($row['hooks']));
                    echo '<tr>';
                    echo '<td><a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener" class="wkcm-url-cell" title="' . esc_attr($row['url']) . '">' . esc_html($row['url']) . '</a></td>';
                    echo '<td><div style="display:flex;align-items:center;gap:8px;"><strong style="font-feature-settings:\'tnum\' 1;">' . esc_html($row['count']) . '</strong>';
                    echo '<div style="flex:1;height:4px;background:var(--wk-border-soft);border-radius:2px;overflow:hidden;min-width:60px;"><div style="width:' . $bar_pct . '%;height:100%;background:var(--wk-warning);"></div></div></div></td>';
                    echo '<td><code style="font-size:11px;">' . esc_html($hooks_str) . '</code></td>';
                    echo '<td><code style="font-size:11px;color:var(--wk-text-muted);">' . esc_html($row['last_ts']) . '</code></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div></div>';

            // Purged URLs — grouped into batches (one row per purge call, all URLs nested)
            $purge_batches = $this->get_recent_purge_batches(7, 50);
            $total_urls = array_sum(array_map(fn($b) => count($b['urls']), $purge_batches));
            echo '<div class="wkcm-card">';
            echo '<div class="wkcm-card-head"><span class="wkcm-card-num">5b</span><h2>Recently Purged URLs <span class="wkcm-badge is-warning" style="margin-left:8px;font-size:10px;">' . count($purge_batches) . ' batches / ' . $total_urls . ' URLs</span></h2><p>Each row = one purge action. Click to expand and see all URLs invalidated by that call.</p></div>';
            echo '<div class="wkcm-card-body" style="padding:0;">';
            if (empty($purge_batches)) {
                echo '<div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span>No URL-level purge entries in the log range.</div>';
            } else {
                echo '<table class="wkcm-table"><thead><tr><th style="width:40px;"></th><th>Time</th><th>Plugin</th><th>Hook</th><th style="width:80px;">URLs</th><th>First URL</th></tr></thead><tbody>';
                foreach ($purge_batches as $i => $batch) {
                    $count = count($batch['urls']);
                    $first = $batch['urls'][0] ?? '';
                    $row_id = 'wkcm-batch-' . $i;
                    echo '<tr style="cursor:pointer;" onclick="var r=document.getElementById(\'' . esc_attr($row_id) . '\');var open=r.style.display!==\'table-row\';r.style.display=open?\'table-row\':\'none\';this.querySelector(\'.wkcm-toggle\').textContent=open?\'−\':\'+\';">';
                    echo '<td style="text-align:center;width:40px;"><span class="wkcm-toggle" style="display:inline-block;width:22px;height:22px;line-height:20px;font-size:18px;font-weight:bold;border:1px solid #1877D3;border-radius:3px;color:#1877D3;background:#fff;font-family:system-ui,-apple-system,sans-serif;">+</span></td>';
                    echo '<td><code style="font-size:11px;">' . esc_html($batch['ts']) . '</code></td>';
                    echo '<td>' . esc_html($batch['plugin']) . '</td>';
                    echo '<td><code style="font-size:11px;">' . esc_html($batch['hook']) . '</code></td>';
                    echo '<td><span class="wkcm-badge is-warning">' . $count . '</span></td>';
                    echo '<td><span class="wkcm-url-cell" title="' . esc_attr($first) . '">' . esc_html($first) . ($count > 1 ? ' <em style="color:var(--wk-text-muted);">+' . ($count - 1) . ' more</em>' : '') . '</span></td>';
                    echo '</tr>';
                    // Hidden details row
                    echo '<tr id="' . esc_attr($row_id) . '" style="display:none;background:var(--wk-bg-soft,#f9f9f9);">';
                    echo '<td colspan="6" style="padding:8px 16px;">';
                    echo '<ol style="margin:0;padding-left:24px;">';
                    foreach ($batch['urls'] as $u) {
                        echo '<li style="margin:2px 0;"><a href="' . esc_url($u) . '" target="_blank" rel="noopener" style="word-break:break-all;">' . esc_html($u) . '</a></li>';
                    }
                    echo '</ol>';
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div></div>';
        }

        // === FILTER UI ===
        echo '<div class="wkcm-card">';
        echo '<div class="wkcm-card-head"><span class="wkcm-card-num">06</span><h2>Filter logs</h2><p>Narrow down by plugin, hook, context, or event type.</p></div>';
        echo '<div class="wkcm-card-body">';

        $current_url = admin_url('admin.php?page=wk-cache-manager-monitor');
        $has_filters = $filter_plugin || $filter_hook || $filter_context || $filter_type;

        echo '<form method="get" action="' . admin_url('admin.php') . '" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: end;">';
        echo '<input type="hidden" name="page" value="wk-cache-manager-monitor">';

        // Plugin filter
        echo '<div style="flex: 1; min-width: 200px;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: 600;">Plugin</label>';
        echo '<select name="filter_plugin" class="regular-text">';
        echo '<option value="">All Plugins</option>';
        foreach ($plugin_stats as $plugin => $count) {
            $selected = ($filter_plugin === $plugin) ? 'selected' : '';
            echo '<option value="' . esc_attr($plugin) . '" ' . $selected . '>' . esc_html($plugin) . ' (' . $count . ')</option>';
        }
        echo '</select>';
        echo '</div>';

        // Hook filter
        echo '<div style="flex: 1; min-width: 200px;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: 600;">Hook</label>';
        echo '<select name="filter_hook" class="regular-text">';
        echo '<option value="">All Hooks</option>';
        foreach ($hook_stats as $hook => $data) {
            $selected = ($filter_hook === $hook) ? 'selected' : '';
            echo '<option value="' . esc_attr($hook) . '" ' . $selected . '>' . esc_html($hook) . ' (' . $data['count'] . ')</option>';
        }
        echo '</select>';
        echo '</div>';

        // Context filter
        echo '<div style="flex: 1; min-width: 200px;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: 600;">Context</label>';
        echo '<select name="filter_context" class="regular-text">';
        echo '<option value="">All Contexts</option>';
        foreach ($context_stats as $context => $count) {
            $selected = ($filter_context === $context) ? 'selected' : '';
            echo '<option value="' . esc_attr($context) . '" ' . $selected . '>' . esc_html($context) . ' (' . $count . ')</option>';
        }
        echo '</select>';
        echo '</div>';

        // Type filter
        echo '<div style="flex: 0 0 auto;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: 600;">Type</label>';
        echo '<select name="filter_type" class="regular-text">';
        echo '<option value="">All Types</option>';
        $types = array('PURGE TRIGGERED', 'PURGE COMPLETED', 'DEBUG');
        foreach ($types as $type) {
            $selected = ($filter_type === $type) ? 'selected' : '';
            echo '<option value="' . esc_attr($type) . '" ' . $selected . '>' . esc_html($type) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div style="flex: 0 0 auto;">';
        echo '<button type="submit" class="button button-primary" style="margin-top: 0;">Apply Filters</button>';
        if ($has_filters) {
            echo ' <a href="' . $current_url . '" class="button">Clear Filters</a>';
        }
        echo '</div>';

        echo '</form>';

        if ($has_filters) {
            $filter_count = count(array_filter([$filter_plugin, $filter_hook, $filter_context, $filter_type]));
            echo '<div class="wkcm-inline-notice"><strong>' . $filter_count . ' filter(s) active</strong> — showing ' . count($events) . ' events</div>';
        }

        echo '</div></div>'; // Close card body + card

        // Settings form
        $enabled = get_option('wk_cache_manager_cache_monitor_enabled', false);
        $lightweight = get_option('wk_cache_manager_cache_monitor_lightweight', false);
        $track_api_keys = get_option('wk_cache_manager_cache_monitor_track_api_keys', false);
        $retention_days = get_option('wk_cache_manager_cache_monitor_log_retention_days', 30);
        echo '<div class="wkcm-card">';
        echo '<div class="wkcm-card-head"><span class="wkcm-card-num">07</span><h2>' . esc_html__('Settings', 'wk-cache-manager') . '</h2><p>Enable, lightweight mode, retention.</p></div>';
        echo '<div class="wkcm-card-body">';
        echo '<form method="post">';
        wp_nonce_field('wk_cache_monitor_settings');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="cache_monitor_enabled">' . __('Enable Cache Monitor', 'wk-cache-manager') . '</label></th>';
        echo '<td><label><input type="checkbox" id="cache_monitor_enabled" name="cache_monitor_enabled" value="1" ' . checked($enabled, true, false) . '> ';
        echo __('Enable Cache Monitor feature', 'wk-cache-manager') . '</label>';
        echo '<p class="description">' . __('Turn this OFF to completely disable cache purge monitoring. When disabled, no purge events will be logged.', 'wk-cache-manager') . '</p></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="cache_monitor_lightweight">' . __('Lightweight Mode', 'wk-cache-manager') . '</label></th>';
        echo '<td><label><input type="checkbox" id="cache_monitor_lightweight" name="cache_monitor_lightweight" value="1" ' . checked($lightweight, true, false) . '> ';
        echo __('Monitor REST API & Webhooks only', 'wk-cache-manager') . '</label>';
        echo '<p class="description"><strong>' . __('Use this to catch external integrations (Klaviyo, Peakws, etc.) purging your cache', 'wk-cache-manager') . '</strong><br>';
        echo __('Monitors: REST API requests, WooCommerce webhooks, LiteSpeed purges, CloudFlare purges', 'wk-cache-manager') . '<br>';
        echo __('Skips: Manual product updates, admin actions, post saves (50+ hooks disabled)', 'wk-cache-manager') . '<br>';
        echo __('Performance: ~70% less overhead compared to full monitoring', 'wk-cache-manager') . '</p></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="cache_monitor_track_api_keys">' . __('Track API Keys', 'wk-cache-manager') . '</label></th>';
        echo '<td><label><input type="checkbox" id="cache_monitor_track_api_keys" name="cache_monitor_track_api_keys" value="1" ' . checked($track_api_keys, true, false) . '> ';
        echo __('Log which REST API key is used for each request', 'wk-cache-manager') . '</label>';
        echo '<p class="description"><strong>' . __('Identify which external integration is making API calls', 'wk-cache-manager') . '</strong><br>';
        echo __('Shows: API key description (e.g., "Klaviyo", "Peakwms"), endpoint, and request method', 'wk-cache-manager') . '<br>';
        echo __('Example: "REST API REQUEST: APIKey=Klaviyo (ID:27), Endpoint=wc/v3/products/123, Method=PUT"', 'wk-cache-manager') . '<br>';
        echo __('Note: Adds 1 database query per REST API request (minimal overhead)', 'wk-cache-manager') . '</p></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="log_retention_days">' . __('Log Retention (Days)', 'wk-cache-manager') . '</label></th>';
        echo '<td><input type="number" id="log_retention_days" name="log_retention_days" value="' . esc_attr($retention_days) . '" min="1" max="365" class="small-text"> ';
        echo '<p class="description">' . __('Number of days to keep cache monitor logs. Older logs will be automatically deleted.', 'wk-cache-manager') . '</p></td>';
        echo '</tr>';
        echo '</table>';
        echo '<p class="submit"><input type="submit" name="wk_cache_monitor_save_settings" class="button button-primary" value="' . __('Save Settings', 'wk-cache-manager') . '"></p>';
        echo '</form>';
        echo '</div></div>'; // close card body + card

        $total = array_sum($scope_counts);
        echo '<div class="wkcm-card"><div class="wkcm-card-head"><span class="wkcm-card-num">08</span><h2>Summary</h2><p>Last 200 events grouped by scope.</p></div>';
        echo '<div class="wkcm-card-body" style="padding:0;"><table class="wkcm-table"><tbody>';
        echo '<tr><td>Total events</td><td>' . esc_html($total) . '</td></tr>';
        echo '<tr><td>Single pages</td><td>' . esc_html($scope_counts['single']) . '</td></tr>';
        echo '<tr><td>Multiple URLs</td><td>' . esc_html($scope_counts['multi']) . '</td></tr>';
        echo '<tr><td>Full site</td><td>' . esc_html($scope_counts['full']) . '</td></tr>';
        echo '<tr><td>Completions</td><td>' . esc_html($scope_counts['completion']) . '</td></tr>';
        echo '</tbody></table></div></div>';

        if ($total > 0) {
            echo '<div class="wkcm-card"><div class="wkcm-card-head"><span class="wkcm-card-num">09</span><h2>Purge events over time</h2><p>Hourly buckets, last 7 days.</p></div>';
            echo '<div class="wkcm-card-body"><div class="wk-chart-canvas"><canvas id="cacheMonitorChart"></canvas></div></div></div>';
        }

        if ($show_raw) {
            echo '<p><a href="' . admin_url('admin.php?page=wk-cache-manager-monitor') . '" class="button">View Table</a></p>';
            echo '<textarea style="width:100%; height:400px;" readonly>' . esc_textarea($log_content) . '</textarea>';
        } else {
            echo '<div class="wkcm-card"><div class="wkcm-card-head"><span class="wkcm-card-num">10</span><h2>Events</h2><p>Recent purge entries. Click "View Raw Logs" for full file.</p></div><div class="wkcm-card-body">';
            echo '<p><a href="' . admin_url('admin.php?page=wk-cache-manager-monitor&raw=1') . '" class="button">View Raw Logs</a></p>';
            if (!empty($events)) {
                echo '<table class="wkcm-table">';
                echo '<thead><tr><th>Time</th><th>Type</th><th>Cache Plugin</th><th>Triggered By</th><th>Context</th><th>Hook</th><th>Scope</th><th>WP Action</th><th>Count</th></tr></thead>';
                echo '<tbody>';
                foreach ($events as $event) {
                    $icon = '';
                    $scope_class = strtolower(str_replace(' ', '-', $event['scope']));
                    $wp_action_display = !empty($event['wp_action']) ? esc_html($event['wp_action']) : '<em style="color: #999;">-</em>';

                    $plugin = $event['plugin'] ?? 'Unknown';
                    $plugin_display = '<strong>' . esc_html($plugin) . '</strong>';

                    $trigger = $event['trigger_plugin'] ?? 'Unknown';
                    $trigger_source = $event['trigger_source'] ?? '';
                    $trigger_display = '<strong>' . esc_html($trigger) . '</strong>';
                    if ($trigger_source && $trigger_source !== '-') {
                        $trigger_display .= '<br><small style="color:var(--wk-text-muted);">' . esc_html($trigger_source) . '</small>';
                    }

                    $context = $event['context'] ?? 'Unknown';
                    $context_display = esc_html($context);

                    echo '<tr>';
                    echo '<td>' . esc_html($event['time']) . '</td>';
                    echo '<td>' . esc_html($event['type']) . '</td>';
                    echo '<td>' . $plugin_display . '</td>';
                    echo '<td>' . $trigger_display . '</td>';
                    echo '<td>' . $context_display . '</td>';
                    echo '<td><code>' . esc_html($event['hook']) . '</code></td>';
                    echo '<td><span class="scope-badge ' . $scope_class . '">' . $icon . ' ' . esc_html($event['scope']) . '</span></td>';
                    echo '<td>' . $wp_action_display . '</td>';
                    echo '<td>' . ($event['count'] > 1 ? '<strong>' . $event['count'] . 'x</strong>' : '') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span>No recent events.</div>';
            }
            echo '</div></div>'; // close events card body + card
        }

        echo '<div class="wkcm-card"><div class="wkcm-card-head"><span class="wkcm-card-num">11</span><h2>Maintenance</h2><p>Clear or rotate logs.</p></div><div class="wkcm-card-body">';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=wk-cache-manager-monitor&clear_log=1'), 'clear_wk_cache_log')) . '" class="button wkcm-btn-danger"><span class="dashicons dashicons-trash"></span> Clear All Logs</a> ';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=wk-cache-manager-monitor&clean_old=1'), 'clean_wk_cache_log')) . '" class="button wkcm-btn-warning"><span class="dashicons dashicons-clock"></span> Clean Old Logs</a>';
        echo '</div></div>';

        if ($clear_log) {
            echo '<div class="wkcm-inline-notice is-success">All logs cleared.</div>';
        }
        if ($clean_old) {
            echo '<div class="wkcm-inline-notice is-success">Old logs cleaned (kept last 1000 entries).</div>';
        }

        // Chart JavaScript
        if ($total > 0) {
            $chart_data = $this->get_cache_monitor_chart_data();
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var chartData = <?php echo json_encode($chart_data); ?>;

                    if (chartData && chartData.labels && chartData.labels.length > 0) {
                        var ctx = document.getElementById('cacheMonitorChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: chartData.labels,
                                datasets: [
                                    {
                                        label: 'Full Site',
                                        data: chartData.full_site,
                                        backgroundColor: 'rgba(239, 68, 68, 0.8)',
                                        borderColor: '#ef4444',
                                        borderWidth: 2
                                    },
                                    {
                                        label: 'Multiple URLs',
                                        data: chartData.multi_urls,
                                        backgroundColor: 'rgba(245, 158, 11, 0.8)',
                                        borderColor: '#f59e0b',
                                        borderWidth: 2
                                    },
                                    {
                                        label: 'Single Page',
                                        data: chartData.single_page,
                                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                                        borderColor: '#3b82f6',
                                        borderWidth: 2
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: false,
                                plugins: {
                                    legend: {
                                        display: true,
                                        position: 'bottom',
                                        labels: {
                                            padding: 15,
                                            font: {
                                                size: 12,
                                                weight: '500'
                                            }
                                        }
                                    },
                                    tooltip: {
                                        mode: 'index',
                                        intersect: false,
                                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                        padding: 12,
                                        titleFont: {
                                            size: 14,
                                            weight: 'bold'
                                        },
                                        bodyFont: {
                                            size: 13
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        stacked: true,
                                        beginAtZero: true,
                                        ticks: {
                                            precision: 0
                                        },
                                        title: {
                                            display: true,
                                            text: 'Number of Purge Events',
                                            font: {
                                                size: 12,
                                                weight: '600'
                                            }
                                        }
                                    },
                                    x: {
                                        stacked: true,
                                        title: {
                                            display: true,
                                            text: 'Time',
                                            font: {
                                                size: 12,
                                                weight: '600'
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }
                });
            </script>
            <?php
        }

        echo '</div>';
        \WKCacheManager\Admin::render_shell_close();
    }

    /**
     * Get chart data for Cache Monitor
     */
    private function get_cache_monitor_chart_data()
    {
        $log_files = $this->get_recent_log_files(7);
        $hourly_data = [];

        foreach ($log_files as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (preg_match('/^\[(.+?)\]/', $line, $matches)) {
                        $timestamp = $matches[1];
                        $hour_key = date('Y-m-d H:00', strtotime($timestamp));

                        if (!isset($hourly_data[$hour_key])) {
                            $hourly_data[$hour_key] = [
                                'full_site' => 0,
                                'multi_urls' => 0,
                                'single_page' => 0
                            ];
                        }

                        if (strpos($line, 'purge_all') !== false) {
                            $hourly_data[$hour_key]['full_site']++;
                        } elseif (strpos($line, 'purge_urls') !== false) {
                            $hourly_data[$hour_key]['multi_urls']++;
                        } elseif (strpos($line, 'purge_url') !== false || strpos($line, 'purge_post') !== false) {
                            $hourly_data[$hour_key]['single_page']++;
                        }
                    }
                }
            }
        }

        // Sort by time
        ksort($hourly_data);

        // Prepare chart data
        $labels = [];
        $full_site = [];
        $multi_urls = [];
        $single_page = [];

        foreach ($hourly_data as $hour => $data) {
            $labels[] = date('M d, H:i', strtotime($hour));
            $full_site[] = $data['full_site'];
            $multi_urls[] = $data['multi_urls'];
            $single_page[] = $data['single_page'];
        }

        return [
            'labels' => $labels,
            'full_site' => $full_site,
            'multi_urls' => $multi_urls,
            'single_page' => $single_page
        ];
    }

    /**
     * Get all log files
     *
     * @return array List of log file paths
     */
    private function get_all_log_files()
    {
        $pattern = $this->log_dir . 'cache-monitor-*.log';
        $files = glob($pattern);
        return $files ? $files : array();
    }

    /**
     * List YYYY-MM-DD dates for which a cache-monitor log file exists, newest first.
     */
    private function get_available_log_dates()
    {
        $dates = array();
        foreach ($this->get_all_log_files() as $f) {
            if (preg_match('/cache-monitor-(\d{4}-\d{2}-\d{2})\.log$/', basename($f), $m)) {
                $dates[] = $m[1];
            }
        }
        rsort($dates);
        return $dates;
    }

    /**
     * Get recent log files
     *
     * @param int $days Number of days to retrieve
     * @return array List of log file paths
     */
    private function get_recent_log_files($days = 7)
    {
        $files = array();
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $file = $this->log_dir . 'cache-monitor-' . $date . '.log';
            if (file_exists($file)) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Clear all log files
     */
    private function clear_all_logs()
    {
        $files = $this->get_all_log_files();
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Cleanup old log files based on retention setting
     */
    public function cleanup_old_logs()
    {
        $retention_days = get_option('wk_cache_manager_cache_monitor_log_retention_days', 30);
        $cutoff_date = date('Y-m-d', strtotime("-$retention_days days"));

        $files = $this->get_all_log_files();
        foreach ($files as $file) {
            // Extract date from filename: cache-monitor-YYYY-MM-DD.log
            if (preg_match('/cache-monitor-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                $file_date = $matches[1];
                if ($file_date < $cutoff_date) {
                    unlink($file);
                }
            }
        }
    }
}
