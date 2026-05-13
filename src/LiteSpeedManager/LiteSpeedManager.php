<?php

namespace WKCacheManager\LiteSpeedManager;

class LiteSpeedManager
{
    private static $instance = null;
    private $wc_class = 'LiteSpeed\Thirdparty\WooCommerce';
    protected $stock_threshold;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Get stock threshold from options
        $this->stock_threshold = (int) get_option('wk_cache_manager_litespeed_stock_threshold', 20);

        // Hook into WooCommerce init
        add_action('woocommerce_init', [$this, 'init'], 20);

        // Register settings and AJAX handlers
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_wk_cache_manager_clear_stock_log', [$this, 'handle_clear_log']);

        // Schedule daily cleanup
        if (!wp_next_scheduled('wk_cache_manager_litespeed_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wk_cache_manager_litespeed_log_cleanup');
        }
        add_action('wk_cache_manager_litespeed_log_cleanup', [$this, 'cleanup_old_logs']);

        // Invalidate cached product category list when taxonomy changes
        $cat_cache_buster = function () {
            delete_transient('wkcm_purge_prevention_cats');
        };
        add_action('created_product_cat', $cat_cache_buster);
        add_action('edited_product_cat', $cat_cache_buster);
        add_action('delete_product_cat', $cat_cache_buster);
    }

    /**
     * Get effective stock threshold for a given product, honoring per-category
     * overrides. If product has multiple categories with overrides, lowest wins
     * (safest: purge sooner = more cache invalidation, never miss low-stock).
     */
    public function get_threshold_for_product($product)
    {
        if (!$product) {
            return $this->stock_threshold;
        }

        $overrides = get_option('wk_cache_manager_litespeed_category_thresholds', []);
        if (empty($overrides) || !is_array($overrides)) {
            return $this->stock_threshold;
        }

        $pid = $product->get_id();
        $terms = get_the_terms($pid, 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return $this->stock_threshold;
        }

        $chosen = null;
        foreach ($terms as $term) {
            if (isset($overrides[$term->term_id]) && $overrides[$term->term_id] !== '') {
                $val = (int) $overrides[$term->term_id];
                if ($chosen === null || $val < $chosen) {
                    $chosen = $val;
                }
            }
        }

        return $chosen !== null ? $chosen : $this->stock_threshold;
    }

    /**
     * Safely remove an action if it exists
     */
    private function safe_remove_action($hook, $method)
    {
        global $wp_filter;

        if (!isset($wp_filter[$hook])) {
            return false;
        }

        // Get all callbacks for this hook
        $callbacks = $wp_filter[$hook]->callbacks;

        foreach ($callbacks as $priority => $priority_callbacks) {
            foreach ($priority_callbacks as $callback) {
                // Check if this is our target callback
                if (is_array($callback['function'])
                    && is_object($callback['function'][0])
                    && get_class($callback['function'][0]) === 'LiteSpeed\Thirdparty\WooCommerce'
                    && $callback['function'][1] === $method
                ) {
                    // Found it - remove it
                    remove_action($hook, array($callback['function'][0], $method), $priority);
                    return true;
                }
            }
        }

        return false;
    }

    public function init()
    {
        // Wait for LiteSpeed to initialize
        if (!did_action('litespeed_init')) {
            add_action('litespeed_init', [$this, 'setup'], 20);
            return;
        }

        $this->setup();
    }

    public function setup()
    {
        // Check if Purge Prevention is enabled
        if (!get_option('wk_cache_manager_purge_prevention_enabled', true)) {
            return;
        }

        // Let WooCommerce + LiteSpeed register their hooks first, then we conditionally intercept.
        add_action('wp_loaded', function () {
            // CONDITIONAL stock-purge blocker.
            // On every stock change, check the new stock. If above threshold, remove LiteSpeed's
            // default purge_product callback for THIS request only. If at/below threshold, leave
            // it in place so cache reflects low-stock state quickly.
            add_action('woocommerce_product_set_stock', [$this, 'maybe_block_stock_purge'], 1, 1);
            add_action('woocommerce_variation_set_stock', [$this, 'maybe_block_stock_purge'], 1, 1);

            // CloudFlare monitoring (read-only filters, just observe purge events)
            add_filter('swcfpc_purge_all', [$this, 'monitor_cloudflare_purge_all'], 10, 1);
            add_filter('swcfpc_cf_purge_whole_cache_before', [$this, 'monitor_cloudflare_whole_cache'], 10, 0);
            add_filter('swcfpc_cf_purge_cache_by_urls_before', [$this, 'monitor_cloudflare_urls_cache'], 10, 1);

            // Order completion handler: explicit purge when sold-item stock drops to/below threshold
            add_action('woocommerce_order_status_changed', [$this, 'check_order_products'], 10, 3);

            // Init noise removed: every request was writing a "registered" line.
            // Real events (BLOCKED, ALLOWED, PURGED, KEPT) carry the threshold value
            // when they actually matter.
        }, 999);
    }

    /**
     * Conditionally remove LiteSpeed's default purge_product hook for this request.
     * - Stock above threshold: remove the hook so cache is kept
     * - Stock at/below threshold: leave hook intact so cache reflects low stock
     *
     * Runs at priority 1, before LiteSpeed's purge_product (priority 10).
     */
    public function maybe_block_stock_purge($product)
    {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }
        if (!$product || !$product->managing_stock()) {
            return;
        }

        $stock = (int) $product->get_stock_quantity();
        $threshold = $this->get_threshold_for_product($product);
        $log_enabled = get_option('wk_cache_manager_log_order_completion', true);

        if ($stock > $threshold) {
            // Block the default purge — high stock, no need to refresh cache
            $this->safe_remove_action('woocommerce_product_set_stock', 'purge_product');
            $this->safe_remove_action('woocommerce_variation_set_stock', 'purge_product');

            if ($log_enabled) {
                $this->log_to_file(sprintf(
                    'BLOCKED stock purge for "%s" | Stock: %d units (threshold: %d) | URL: %s',
                    $product->get_name(),
                    $stock,
                    $threshold,
                    get_permalink($product->get_id()) ?: 'N/A'
                ), 'info');
            }
        } else {
            // Stock low — allow LiteSpeed's default purge to run, just record it
            if ($log_enabled) {
                $this->log_to_file(sprintf(
                    'ALLOWED stock purge for "%s" | Stock: %d units (at/below threshold: %d) | URL: %s',
                    $product->get_name(),
                    $stock,
                    $threshold,
                    get_permalink($product->get_id()) ?: 'N/A'
                ), 'warning');
            }
        }
    }

    /**
     * Monitor CloudFlare "Purge All" cache purges (logging only, no prevention)
     */
    public function monitor_cloudflare_purge_all($args)
    {
        $this->log_to_file('CloudFlare is purging ALL cache (monitoring only)', 'info');
        return $args;
    }

    /**
     * Monitor CloudFlare whole cache purge (before hook)
     */
    public function monitor_cloudflare_whole_cache()
    {
        $this->log_to_file('CloudFlare is purging entire cache (monitoring only)', 'info');
    }

    /**
     * Monitor CloudFlare cache by URLs (before hook - logging only, no prevention)
     */
    public function monitor_cloudflare_urls_cache($urls)
    {
        if (!is_array($urls) || empty($urls)) {
            return;
        }

        $product_count = 0;
        $valid_non_product_urls = [];
        $invalid_urls = [];
        $total_count = count($urls);

        foreach ($urls as $url) {
            // Validate URL format
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $invalid_urls[] = $url;
                continue;
            }

            // Try to get post ID from URL
            $post_id = url_to_postid($url);

            // Also check if URL contains product slug pattern
            $is_product_url = strpos($url, '/vare/') !== false || strpos($url, '/product/') !== false;

            if ($post_id && function_exists('wc_get_product')) {
                $product = wc_get_product($post_id);

                if ($product) {
                    $product_count++;

                    $stock = $product->managing_stock() ? $product->get_stock_quantity() : 'not managed';
                    $stock_display = $product->managing_stock() ? $stock : 'Stock not managed';

                    $this->log_to_file(sprintf(
                        'CloudFlare will purge product: %s | Stock: %s | URL: %s',
                        $product->get_name(),
                        $stock_display,
                        $url
                    ), 'debug');
                } else {
                    // Post ID found but not a product
                    $post_type = get_post_type($post_id);
                    if ($is_product_url) {
                        $this->log_to_file(sprintf(
                            'URL looks like product but post_id %d is type: %s | URL: %s',
                            $post_id,
                            $post_type ?: 'unknown',
                            $url
                        ), 'debug');
                    }
                    $valid_non_product_urls[] = $url;
                }
            } else {
                // No post ID found
                if ($is_product_url) {
                    $this->log_to_file(sprintf(
                        'URL looks like product but no post_id found | URL: %s',
                        $url
                    ), 'debug');
                }
                $valid_non_product_urls[] = $url;
            }
        }

        $valid_non_product_count = count($valid_non_product_urls);
        $invalid_count = count($invalid_urls);

        // ---- Structured multi-line log entry ----
        // Header (single grep-able line summarising totals)
        $this->log_to_file(sprintf(
            'CloudFlare purge batch: total=%d, products=%d, other=%d, invalid=%d',
            $total_count,
            $product_count,
            $valid_non_product_count,
            $invalid_count
        ), 'info');

        // Full list of non-product URLs (one per line, indented for readability)
        if (!empty($valid_non_product_urls)) {
            $this->log_to_file(sprintf('  Other URLs (%d):', $valid_non_product_count), 'debug');
            foreach ($valid_non_product_urls as $url) {
                $this->log_to_file('    - ' . $url, 'debug');
            }
        }

        // Full list of invalid URLs (one per line)
        if (!empty($invalid_urls)) {
            $this->log_to_file(sprintf('  Invalid URLs (%d):', $invalid_count), 'warning');
            foreach ($invalid_urls as $url) {
                $this->log_to_file('    - ' . $url, 'warning');
            }
        }
    }

    /**
     * Check products in completed order and purge cache if stock is below threshold
     */
    public function check_order_products($order_id, $old_status, $new_status)
    {
        // Only check when order is completed
        if ($new_status !== 'completed') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $log_orders = get_option('wk_cache_manager_log_order_completion', true);

        if ($log_orders) {
            $this->log_to_file(sprintf(
                'Order #%d completed - checking product stock levels (threshold: %d units)',
                $order_id,
                $this->stock_threshold
            ), 'info');
        }

        do_action('litespeed_debug', sprintf(
            '[Order Complete] Checking products in order #%d for stock levels below %d',
            $order_id,
            $this->stock_threshold
        ));

        // Check each product in the order
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            // Skip if product doesn't exist or doesn't manage stock
            if (!$product || !$product->managing_stock()) {
                continue;
            }

            $stock = $product->get_stock_quantity();
            $product_url = get_permalink($product->get_id());
            $threshold = $this->get_threshold_for_product($product);

            // If stock is below threshold, purge this product's cache
            if ($stock <= $threshold) {
                do_action('litespeed_purge_post', $product->get_id());

                if ($log_orders) {
                    $this->log_to_file(sprintf(
                        'PURGED LiteSpeed cache for "%s" after order #%d | Stock now: %d units (at or below threshold of %d) | URL: %s',
                        $product->get_name(),
                        $order_id,
                        $stock,
                        $threshold,
                        $product_url ?: 'N/A'
                    ), 'warning');
                }

                do_action('litespeed_debug', sprintf(
                    '[Order Complete] Purged cache for product #%d - Stock %d below threshold %d',
                    $product->get_id(),
                    $stock,
                    $threshold
                ));
            } else {
                if ($log_orders) {
                    $this->log_to_file(sprintf(
                        'KEPT LiteSpeed cache for "%s" after order #%d | Stock: %d units (above threshold of %d) | URL: %s',
                        $product->get_name(),
                        $order_id,
                        $stock,
                        $threshold,
                        $product_url ?: 'N/A'
                    ), 'info');
                }

                do_action('litespeed_debug', sprintf(
                    '[Order Complete] Kept cache for product #%d - Stock %d above threshold %d',
                    $product->get_id(),
                    $stock,
                    $threshold
                ));
            }
        }

        if ($log_orders) {
            $this->log_to_file(sprintf('Finished processing order #%d', $order_id), 'info');
        }
    }

    /**
     * Register plugin settings
     */
    public function register_settings()
    {
        register_setting(
            'wk_cache_manager_litespeed_settings',
            'wk_cache_manager_purge_prevention_enabled',
            [
                'type' => 'boolean',
                'default' => true
            ]
        );

        register_setting(
            'wk_cache_manager_litespeed_settings',
            'wk_cache_manager_litespeed_stock_threshold',
            [
                'type' => 'integer',
                'default' => 20,
                'sanitize_callback' => 'absint'
            ]
        );

        register_setting(
            'wk_cache_manager_litespeed_settings',
            'wk_cache_manager_litespeed_log_retention_days',
            [
                'type' => 'integer',
                'default' => 30,
                'sanitize_callback' => 'absint'
            ]
        );

        register_setting(
            'wk_cache_manager_litespeed_settings',
            'wk_cache_manager_log_order_completion',
            [
                'type' => 'boolean',
                'default' => true
            ]
        );

        register_setting(
            'wk_cache_manager_litespeed_settings',
            'wk_cache_manager_litespeed_category_thresholds',
            [
                'type' => 'array',
                'default' => [],
                'sanitize_callback' => function ($v) {
                    $out = [];
                    if (!is_array($v)) return $out;
                    foreach ($v as $term_id => $threshold) {
                        $term_id = absint($term_id);
                        if ($term_id <= 0) continue;
                        $threshold = trim((string) $threshold);
                        if ($threshold === '') continue;
                        $out[$term_id] = absint($threshold);
                    }
                    return $out;
                }
            ]
        );
    }

    /**
     * Render settings page
     */
    /**
     * Parse log files for PURGED + KEPT entries.
     *
     * @param int $days  How many recent days to scan.
     * @param int $limit Max entries per category.
     * @return array{purged: array, prevented: array}
     */
    public function get_purge_decisions($days = 7, $limit = 50)
    {
        $files = $this->get_all_log_files();
        rsort($files);
        $files = array_slice($files, 0, $days);

        $purged = [];
        $prevented = [];

        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            // walk newest first
            foreach (array_reverse($lines) as $line) {
                $entry = null;
                $is_purged = null;

                // Format A — order completion:
                // [TS] [LEVEL] PURGED LiteSpeed cache for "PRODUCT" after order #123 | Stock now: N units ... | URL: ...
                // [TS] [LEVEL] KEPT   LiteSpeed cache for "PRODUCT" after order #123 | Stock: N units ... | URL: ...
                if (preg_match('/^\[(?P<ts>[^\]]+)\]\s+\[[^\]]+\]\s+(?P<verb>PURGED|KEPT)\s+LiteSpeed cache for "(?P<name>[^"]+)" after order #(?P<order>\d+)\s+\|\s+Stock(?:\s+now)?:\s+(?P<stock>\d+) units[^|]+\|\s+URL:\s+(?P<url>\S+)/', $line, $m)) {
                    $entry = [
                        'ts' => $m['ts'],
                        'product' => $m['name'],
                        'order' => (int) $m['order'],
                        'stock' => (int) $m['stock'],
                        'url' => $m['url'],
                    ];
                    $is_purged = ($m['verb'] === 'PURGED');
                }
                // Format B — stock change (no order):
                // [TS] [LEVEL] ALLOWED stock purge for "PRODUCT" | Stock: N units (at/below threshold: T) | URL: ...
                // [TS] [LEVEL] BLOCKED stock purge for "PRODUCT" | Stock: N units (threshold: T) | URL: ...
                elseif (preg_match('/^\[(?P<ts>[^\]]+)\]\s+\[[^\]]+\]\s+(?P<verb>ALLOWED|BLOCKED)\s+stock purge for "(?P<name>[^"]+)"\s+\|\s+Stock:\s+(?P<stock>\d+) units[^|]+\|\s+URL:\s+(?P<url>\S+)/', $line, $m)) {
                    $entry = [
                        'ts' => $m['ts'],
                        'product' => $m['name'],
                        'order' => 0,
                        'stock' => (int) $m['stock'],
                        'url' => $m['url'],
                    ];
                    $is_purged = ($m['verb'] === 'ALLOWED');
                }

                if ($entry === null) {
                    continue;
                }

                if ($is_purged) {
                    if (count($purged) < $limit) $purged[] = $entry;
                } else {
                    if (count($prevented) < $limit) $prevented[] = $entry;
                }
                if (count($purged) >= $limit && count($prevented) >= $limit) break 2;
            }
        }
        return ['purged' => $purged, 'prevented' => $prevented];
    }

    public function render_combined_page()
    {
        $view = isset($_GET['view']) && $_GET['view'] === 'logs' ? 'logs' : 'settings';
        if ($view === 'logs') {
            $this->render_log_page();
        } else {
            $this->render_settings_page();
        }
    }

    public function redirect_to_log()
    {
        wp_safe_redirect(admin_url('admin.php?page=wk-cache-manager-purge-prevention&view=logs'));
        exit;
    }

    public function render_settings_page()
    {
        if (isset($_POST['wk_litespeed_save_settings']) && check_admin_referer('wk_litespeed_settings')) {
            $enabled = isset($_POST['purge_prevention_enabled']) ? true : false;
            $threshold = absint($_POST['stock_threshold']);
            $retention_days = absint($_POST['log_retention_days']);
            $log_orders = isset($_POST['log_order_completion']) ? true : false;

            // Per-category thresholds: only persist rows with non-empty integer value
            $cat_overrides = [];
            if (!empty($_POST['category_thresholds']) && is_array($_POST['category_thresholds'])) {
                foreach ($_POST['category_thresholds'] as $term_id => $val) {
                    $term_id = absint($term_id);
                    $val = trim((string) wp_unslash($val));
                    if ($term_id > 0 && $val !== '') {
                        $cat_overrides[$term_id] = absint($val);
                    }
                }
            }

            update_option('wk_cache_manager_purge_prevention_enabled', $enabled);
            update_option('wk_cache_manager_litespeed_stock_threshold', $threshold);
            update_option('wk_cache_manager_litespeed_log_retention_days', $retention_days);
            update_option('wk_cache_manager_log_order_completion', $log_orders);
            update_option('wk_cache_manager_litespeed_category_thresholds', $cat_overrides);

            $this->log_to_file(sprintf(
                'Settings updated - Enabled: %s, Stock threshold: %d units, Log retention: %d days, Log orders: %s',
                $enabled ? 'Yes' : 'No',
                $threshold,
                $retention_days,
                $log_orders ? 'Yes' : 'No'
            ), 'info');

            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wk-cache-manager') . '</p></div>';
        }

        $enabled = get_option('wk_cache_manager_purge_prevention_enabled', true);
        $threshold = get_option('wk_cache_manager_litespeed_stock_threshold', 20);
        $retention_days = get_option('wk_cache_manager_litespeed_log_retention_days', 30);
        $log_orders = get_option('wk_cache_manager_log_order_completion', true);

        \WKCacheManager\Admin::render_shell_open('Purge Prevention', 'Block automatic cache purges for high-stock products.', 'purge-prevention');
        \WKCacheManager\Admin::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-purge-prevention'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-purge-prevention&view=logs'), 'label' => 'Logs'],
        ], 'settings');
        ?>
            <div class="wkcm-main wkcm-main--stack">
                <?php
                \WKCacheManager\Admin::render_help(
                    __('How does Purge Prevention work?', 'wk-cache-manager'),
                    '<p><strong>' . esc_html__('Problem it solves:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('LiteSpeed Cache and CloudFlare automatically purge product pages on every stock change. On busy stores this means cache is constantly being invalidated — even for products with plenty of stock, where a 1-unit change is irrelevant to visitors. Result: hot pages serve cold MISS responses repeatedly, hammering the database.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('What this module does:', 'wk-cache-manager') . '</strong></p>'
                    . '<ol style="margin:0 0 8px 18px;line-height:1.6;">'
                    . '<li>' . esc_html__('Intercepts the LiteSpeed "woocommerce_product_set_stock" hook on every stock change.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Reads the new stock level for the product.', 'wk-cache-manager') . '</li>'
                    . '<li><strong>' . esc_html__('Stock above threshold (default 20):', 'wk-cache-manager') . '</strong> '
                    . esc_html__('removes LiteSpeed\'s default purge_product hook for this request → cache stays warm.', 'wk-cache-manager') . '</li>'
                    . '<li><strong>' . esc_html__('Stock at or below threshold:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('lets LiteSpeed purge the product page so visitors see the low-stock state.', 'wk-cache-manager') . '</li>'
                    . '</ol>'

                    . '<p><strong>' . esc_html__('Order completion safety net:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('When a WooCommerce order completes, the plugin re-checks each sold product and explicitly purges its cache if stock dropped to/below the threshold. This guarantees visitors see "low stock" badges right after a sale, not stale "in stock" text.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('What gets purged when stock is low:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('Only the product page itself + its direct categories. Not subcategories, not the shop archive, not the homepage. Keeps purge scope tight.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('Configurable:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('Threshold (default 20 units), log retention (days), feature toggle. Works with LiteSpeed Cache + WP CloudFlare Page Cache. CloudFlare-only purges are also monitored (read-only, logged).', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('Logs:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('Every block / allow / purge decision is written to the daily Stock Decision log (Logs tab). You can see exactly which products were kept hot vs. refreshed.', 'wk-cache-manager') . '</p>'
                );
                ?>

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">01</span>
                        <h2><?php _e('Settings', 'wk-cache-manager'); ?></h2>
                        <p><?php _e('Toggle, threshold, log retention.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body">
                        <form method="post" action="">
                            <?php wp_nonce_field('wk_litespeed_settings'); ?>
                            <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="purge_prevention_enabled"><?php _e('Enable Purge Prevention', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="purge_prevention_enabled"
                                       name="purge_prevention_enabled"
                                       value="1"
                                       <?php checked($enabled, true); ?> />
                                <?php _e('Enable CF + LS Purge Prevention feature', 'wk-cache-manager'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Turn this OFF to completely disable purge prevention for CloudFlare and LiteSpeed. When disabled, all cache purge events will proceed normally.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="stock_threshold"><?php _e('Stock Threshold (Global)', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="stock_threshold"
                                   name="stock_threshold"
                                   value="<?php echo esc_attr($threshold); ?>"
                                   min="0"
                                   class="regular-text" />
                            <p class="description">
                                <?php _e('Purge cache only when product stock falls to or below this level. Default: 20 units. Used unless a category-specific override is set below.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e('Per-Category Thresholds', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <?php
                            $cat_overrides = get_option('wk_cache_manager_litespeed_category_thresholds', []);
                            if (!is_array($cat_overrides)) $cat_overrides = [];

                            // Cache the category list for 15min — settings page loads
                            // are infrequent but get_terms on 500 rows is non-trivial
                            // and there's no reason to hit DB every render.
                            $categories = get_transient('wkcm_purge_prevention_cats');
                            if ($categories === false) {
                                $categories = taxonomy_exists('product_cat')
                                    ? get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500])
                                    : [];
                                if (is_wp_error($categories)) $categories = [];
                                set_transient('wkcm_purge_prevention_cats', $categories, 15 * MINUTE_IN_SECONDS);
                            }
                            ?>
                            <p class="description" style="margin:0 0 8px;">
                                <?php _e('Override the global threshold for specific product categories. Leave blank to use global. If product has multiple categories with overrides, the lowest wins.', 'wk-cache-manager'); ?>
                            </p>
                            <?php if (empty($categories)): ?>
                                <p><em><?php _e('No product categories found.', 'wk-cache-manager'); ?></em></p>
                            <?php else: ?>
                                <table class="widefat striped" style="max-width:600px;">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Category', 'wk-cache-manager'); ?></th>
                                            <th style="width:140px;"><?php _e('Threshold (blank = use global)', 'wk-cache-manager'); ?></th>
                                            <th style="width:80px;"><?php _e('Products', 'wk-cache-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr>
                                            <td><?php echo esc_html($cat->name); ?></td>
                                            <td>
                                                <input type="number"
                                                       name="category_thresholds[<?php echo (int) $cat->term_id; ?>]"
                                                       value="<?php echo isset($cat_overrides[$cat->term_id]) ? esc_attr($cat_overrides[$cat->term_id]) : ''; ?>"
                                                       min="0"
                                                       placeholder="<?php echo esc_attr($threshold); ?>"
                                                       class="small-text" />
                                            </td>
                                            <td><?php echo (int) $cat->count; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="log_order_completion"><?php _e('Log Order Completions', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="log_order_completion"
                                       name="log_order_completion"
                                       value="1"
                                       <?php checked($log_orders, true); ?> />
                                <?php _e('Log when orders are completed and cache decisions are made', 'wk-cache-manager'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Enable this to track all order completions and see which products had their cache purged or kept. Useful for monitoring and debugging.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="log_retention_days"><?php _e('Log Retention (Days)', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="log_retention_days"
                                   name="log_retention_days"
                                   value="<?php echo esc_attr($retention_days); ?>"
                                   min="1"
                                   max="365"
                                   class="small-text" />
                            <?php _e('days', 'wk-cache-manager'); ?>
                            <p class="description">
                                <?php _e('Automatically delete logs older than this many days. Default: 30 days.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                            <p class="submit">
                                <input type="submit"
                                       name="wk_litespeed_save_settings"
                                       class="button button-primary"
                                       value="<?php esc_attr_e('Save Settings', 'wk-cache-manager'); ?>" />
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        <?php
        \WKCacheManager\Admin::render_shell_close();
    }

    /**
     * Render log viewer page
     */
    public function render_log_page()
    {
        \WKCacheManager\Admin::render_shell_open('Purge Prevention', 'Stock decisions, purged vs kept entries.', 'purge-prevention');
        \WKCacheManager\Admin::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-purge-prevention'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-purge-prevention&view=logs'), 'label' => 'Logs'],
        ], 'logs');

        // Date picker: default to today, allow viewing any prior day that has a log file
        $available_dates = $this->get_available_log_dates();
        $today = current_time('Y-m-d');
        $selected_date = isset($_GET['log_date']) ? sanitize_text_field($_GET['log_date']) : $today;
        if (!in_array($selected_date, $available_dates, true)) {
            $selected_date = $today;
        }

        $logs = $this->get_logs_for_date($selected_date);
        $show_raw = isset($_GET['raw']) && $_GET['raw'] == '1';
        ?>
        <div class="wkcm-main wkcm-main--stack">

            <div class="wkcm-card">
                <div class="wkcm-card-head">
                    <span class="wkcm-card-num">01</span>
                    <h2><?php esc_html_e('Actions', 'wk-cache-manager'); ?></h2>
                    <p><?php esc_html_e('Switch view, clear log file.', 'wk-cache-manager'); ?></p>
                </div>
                <div class="wkcm-card-body">
                    <form method="get" style="display:inline-block;margin-right:8px;">
                        <input type="hidden" name="page" value="wk-cache-manager-purge-prevention" />
                        <input type="hidden" name="view" value="logs" />
                        <?php if ($show_raw): ?><input type="hidden" name="raw" value="1" /><?php endif; ?>
                        <label for="log_date" style="margin-right:4px;"><?php esc_html_e('Date:', 'wk-cache-manager'); ?></label>
                        <select name="log_date" id="log_date" onchange="this.form.submit()">
                            <?php foreach ($available_dates as $d): ?>
                                <option value="<?php echo esc_attr($d); ?>" <?php selected($selected_date, $d); ?>>
                                    <?php echo esc_html($d); ?><?php if ($d === $today) echo ' ' . esc_html__('(today)', 'wk-cache-manager'); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($available_dates)): ?>
                                <option value="<?php echo esc_attr($today); ?>"><?php echo esc_html($today); ?></option>
                            <?php endif; ?>
                        </select>
                    </form>
                    <?php if ($show_raw): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-purge-prevention&view=logs&log_date=' . $selected_date)); ?>" class="button"><?php esc_html_e('View Chart & Summary', 'wk-cache-manager'); ?></a>
                    <?php else: ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-purge-prevention&view=logs&raw=1&log_date=' . $selected_date)); ?>" class="button"><?php esc_html_e('View Raw Logs', 'wk-cache-manager'); ?></a>
                    <?php endif; ?>
                    <button id="clear-stock-log" class="button wkcm-btn-danger" data-nonce="<?php echo esc_attr(wp_create_nonce('wk_clear_stock_log')); ?>"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Clear Log', 'wk-cache-manager'); ?></button>
                </div>
            </div>

            <?php
            // Log storage stats
            $all_log_files = $this->get_all_log_files();
            $total_log_size = 0;
            foreach ($all_log_files as $f) { if (file_exists($f)) $total_log_size += filesize($f); }
            $retention = (int) get_option('wk_cache_manager_litespeed_log_retention_days', 30);
            ?>
            <div class="wkcm-card">
                <div class="wkcm-card-head">
                    <span class="wkcm-card-num">01b</span>
                    <h2><?php esc_html_e('Log Storage', 'wk-cache-manager'); ?></h2>
                    <p><?php printf(esc_html__('Daily-rotated files. Retention %d days.', 'wk-cache-manager'), $retention); ?></p>
                </div>
                <div class="wkcm-card-body" style="padding:0;">
                    <table class="wkcm-table"><tbody>
                        <tr><td><?php esc_html_e('Total log files', 'wk-cache-manager'); ?></td><td><?php echo esc_html(number_format(count($all_log_files))); ?></td></tr>
                        <tr><td><?php esc_html_e('Total log size', 'wk-cache-manager'); ?></td><td><?php echo esc_html(size_format($total_log_size)); ?></td></tr>
                        <tr><td><?php esc_html_e('Retention', 'wk-cache-manager'); ?></td><td><?php echo (int) $retention; ?> days</td></tr>
                    </tbody></table>
                </div>
            </div>

            <?php
            $decisions = $this->get_purge_decisions(7, 50);
            $render_decisions_card = function($num, $title, $sub, $rows, $verb_class, $verb_label) {
                ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num"><?php echo esc_html($num); ?></span>
                        <h2><?php echo esc_html($title); ?> <span class="wkcm-badge <?php echo esc_attr($verb_class); ?>" style="margin-left:8px;font-size:10px;"><?php echo esc_html(count($rows)); ?> <?php echo esc_html($verb_label); ?></span></h2>
                        <p><?php echo esc_html($sub); ?></p>
                    </div>
                    <div class="wkcm-card-body" style="padding:0;">
                        <?php if (empty($rows)): ?>
                            <div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span><?php esc_html_e('No entries yet.', 'wk-cache-manager'); ?></div>
                        <?php else: ?>
                            <table class="wkcm-table">
                                <thead><tr>
                                    <th>Time</th><th>Product</th><th>Stock</th><th>Order</th><th>URL</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><code style="font-size:11px;"><?php echo esc_html($r['ts']); ?></code></td>
                                        <td><?php echo esc_html($r['product']); ?></td>
                                        <td><?php echo (int) $r['stock']; ?></td>
                                        <td>#<?php echo (int) $r['order']; ?></td>
                                        <td><a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener" class="wkcm-url-cell" title="<?php echo esc_attr($r['url']); ?>"><?php echo esc_html($r['url']); ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            };
            $render_decisions_card('02', __('Purged URLs', 'wk-cache-manager'), 'Cache invalidated — stock at or below threshold.', $decisions['purged'], 'is-warning', 'purged');
            $render_decisions_card('03', __('Prevented URLs', 'wk-cache-manager'), 'Cache kept — stock above threshold.', $decisions['prevented'], 'is-success', 'kept');
            ?>

            <?php if (!empty($logs) && !$show_raw): ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">04</span>
                        <h2><?php esc_html_e('Purge Prevention Over Time', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Hourly buckets, last 7 days.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body"><div class="wk-chart-canvas"><canvas id="litespeedChart"></canvas></div></div>
                </div>
            <?php endif; ?>

            <div class="wkcm-card">
                <div class="wkcm-card-head">
                    <span class="wkcm-card-num">05</span>
                    <h2><?php esc_html_e('Raw Log Entries', 'wk-cache-manager'); ?></h2>
                    <p><?php printf(esc_html__('%s — %d entries.', 'wk-cache-manager'), esc_html($selected_date), count($logs)); ?></p>
                </div>
                <div class="wkcm-card-body">
                <?php
                if (empty($logs)) {
                    echo '<div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span>' . esc_html__('No log entries for this date.', 'wk-cache-manager') . '</div>';
                } else {
                    echo '<pre class="wkcm-log-tail" style="max-height:600px;">';
                    foreach ($logs as $log) {
                        echo esc_html($log) . "\n";
                    }
                    echo '</pre>';
                }
                ?>
                </div>
            </div>

            <?php if (!empty($logs)): ?>
                <!-- Chart Data Script -->
                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        var chartData = <?php echo json_encode($this->get_litespeed_chart_data()); ?>;

                        if (chartData && chartData.labels && chartData.labels.length > 0) {
                            var ctx = document.getElementById('litespeedChart').getContext('2d');
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: chartData.labels,
                                    datasets: [
                                        {
                                            label: 'Purges Prevented',
                                            data: chartData.prevented,
                                            borderColor: '#10b981',
                                            backgroundColor: 'rgba(16, 185, 129, 0.2)',
                                            borderWidth: 3,
                                            tension: 0.4,
                                            fill: true
                                        },
                                        {
                                            label: 'Stock Changes Detected',
                                            data: chartData.total,
                                            borderColor: '#3b82f6',
                                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                            borderWidth: 2,
                                            tension: 0.4,
                                            borderDash: [5, 5]
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
                                            beginAtZero: true,
                                            ticks: {
                                                precision: 0
                                            },
                                            title: {
                                                display: true,
                                                text: 'Number of Events',
                                                font: {
                                                    size: 12,
                                                    weight: '600'
                                                }
                                            }
                                        },
                                        x: {
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
            <?php endif; ?>
        </div><!-- /.wkcm-main -->
        <?php
        \WKCacheManager\Admin::render_shell_close();
    }

    /**
     * Get chart data for LiteSpeed Manager
     */
    private function get_litespeed_chart_data()
    {
        $log_files = $this->get_all_log_files();
        $hourly_data = [];

        // Read from last 7 days
        rsort($log_files);
        $files_to_read = array_slice($log_files, 0, 7);

        foreach ($files_to_read as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (preg_match('/^\[(.+?)\]/', $line, $matches)) {
                        $timestamp = $matches[1];
                        $hour_key = date('Y-m-d H:00', strtotime($timestamp));

                        if (!isset($hourly_data[$hour_key])) {
                            $hourly_data[$hour_key] = [
                                'prevented' => 0,
                                'total' => 0
                            ];
                        }

                        $hourly_data[$hour_key]['total']++;

                        if (strpos($line, 'PURGE PREVENTED') !== false) {
                            $hourly_data[$hour_key]['prevented']++;
                        }
                    }
                }
            }
        }

        // Sort by time
        ksort($hourly_data);

        // Prepare chart data
        $labels = [];
        $prevented = [];
        $total = [];

        foreach ($hourly_data as $hour => $data) {
            $labels[] = date('M d, H:i', strtotime($hour));
            $prevented[] = $data['prevented'];
            $total[] = $data['total'];
        }

        return [
            'labels' => $labels,
            'prevented' => $prevented,
            'total' => $total
        ];
    }

    /**
     * AJAX handler to clear log file
     */
    public function handle_clear_log()
    {
        check_ajax_referer('wk_clear_stock_log', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $this->clear_all_logs();

        wp_send_json_success('Log cleared');
    }

    /**
     * Get current log file path (daily rotation)
     */
    private function get_current_log_file()
    {
        $date = current_time('Y-m-d');
        return WK_CACHE_MANAGER_LOG_DIR . 'litespeed-stock-' . $date . '.log';
    }

    /**
     * Log message to stock management log file
     */
    private function log_to_file($message, $type = 'info')
    {
        $timestamp = current_time('Y-m-d H:i:s');
        $type_upper = strtoupper($type);
        $log_entry = sprintf("[%s] [%s] %s\n", $timestamp, $type_upper, $message);

        // Ensure log directory exists
        if (!file_exists(WK_CACHE_MANAGER_LOG_DIR)) {
            wp_mkdir_p(WK_CACHE_MANAGER_LOG_DIR);
        }

        // Write to daily log file
        $log_file = $this->get_current_log_file();
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get all log files
     */
    private function get_all_log_files()
    {
        return glob(WK_CACHE_MANAGER_LOG_DIR . 'litespeed-stock-*.log');
    }

    /**
     * Get recent log entries from last 7 days
     */
    private function get_recent_logs($limit = 100)
    {
        $all_logs = [];
        $log_files = $this->get_all_log_files();

        // Sort by date (newest first)
        rsort($log_files);

        // Read from last 7 days of files
        $files_to_read = array_slice($log_files, 0, 7);

        foreach ($files_to_read as $log_file) {
            if (file_exists($log_file)) {
                $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $all_logs = array_merge($all_logs, $lines);

                if (count($all_logs) >= $limit) {
                    break;
                }
            }
        }

        // Return last N lines
        return array_slice($all_logs, -$limit);
    }

    /**
     * Return every log line for the given YYYY-MM-DD date (full file, no truncation).
     */
    private function get_logs_for_date($date)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }
        $file = WK_CACHE_MANAGER_LOG_DIR . 'litespeed-stock-' . $date . '.log';
        if (!file_exists($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines ?: [];
    }

    /**
     * List YYYY-MM-DD dates for which a log file exists, newest first.
     */
    private function get_available_log_dates()
    {
        $files = $this->get_all_log_files();
        $dates = [];
        foreach ($files as $f) {
            if (preg_match('/litespeed-stock-(\d{4}-\d{2}-\d{2})\.log$/', basename($f), $m)) {
                $dates[] = $m[1];
            }
        }
        rsort($dates);
        return $dates;
    }

    /**
     * Clean up old log files based on retention settings
     */
    public function cleanup_old_logs()
    {
        $retention_days = get_option('wk_cache_manager_litespeed_log_retention_days', 30);
        $cutoff_time = strtotime("-{$retention_days} days");

        $log_files = $this->get_all_log_files();

        foreach ($log_files as $log_file) {
            $file_time = filemtime($log_file);
            if ($file_time < $cutoff_time) {
                unlink($log_file);
            }
        }
    }

    /**
     * Clear all logs
     */
    private function clear_all_logs()
    {
        $log_files = $this->get_all_log_files();

        foreach ($log_files as $log_file) {
            unlink($log_file);
        }
    }
}
