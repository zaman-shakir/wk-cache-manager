<?php

namespace WKCacheManager\UrlCrawler;

class UrlCrawler
{
    private static $instance = null;
    private $logger;
    private const TRANSIENT_PREFIX = 'wk_cache_manager_url_crawler_';
    private const CRAWL_LOCK_EXPIRY = 30;
    private const QUEUE_KEY = 'wk_cache_manager_crawl_queue';
    private const PROCESS_LOCK_KEY = 'wk_cache_manager_crawl_processing';
    private const PROCESS_LOCK_EXPIRY = 300; // 5 minutes max processing time
    private const DEDUP_PREFIX = 'wk_crawl_dedup_';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        // Initialize logger
        $this->logger = UrlCrawlerLogger::get_instance();

        // Initialize plugin
        add_action('plugins_loaded', [$this, 'init']);

        // Add settings menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Add post actions
        add_action('publish_post', [$this, 'crawl_post_url']);

        // Add WooCommerce product update hooks (using async trigger instead of cron)
        add_action('woocommerce_update_product', [$this, 'trigger_async_crawl'], 999, 1);

        // Add WooCommerce product sale hooks (crawl when products are sold)
        add_action('woocommerce_payment_complete', [$this, 'handle_product_sale'], 999, 1);
        add_action('woocommerce_order_status_completed', [$this, 'handle_product_sale'], 999, 1);
        add_action('woocommerce_reduce_order_stock', [$this, 'handle_product_sale'], 999, 1);

        // Add category update hooks
        add_action('edit_term', [$this, 'handle_category_update'], 10, 3);

        // Add AJAX handler
        add_action('wp_ajax_wk_cache_manager_clear_url_logs', [$this, 'handle_clear_logs']);

        // Schedule daily cleanup
        if (!wp_next_scheduled('wk_cache_manager_url_crawler_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wk_cache_manager_url_crawler_cleanup');
        }
        add_action('wk_cache_manager_url_crawler_cleanup', [$this->logger, 'cleanup_old_logs']);

        // Add batch queue processor
        add_action('wk_cache_manager_process_crawl_queue', [$this, 'process_queue_batch']);

        // Manual "process now" admin trigger (replaces old loopback)
        add_action('wp_ajax_wk_cache_manager_process_queue_now', [$this, 'handle_process_queue_now']);

        // Run manual form actions at admin_init — BEFORE any headers are sent so
        // wp_safe_redirect() can fire (PRG pattern). Prevents F5-resubmit duplicate crawls.
        add_action('admin_init', [$this, 'maybe_handle_manual_action_early']);
    }

    /**
     * admin_init wrapper: only fires on our URL Crawler page so the global
     * admin_init scope stays clean.
     */
    public function maybe_handle_manual_action_early()
    {
        if (($_GET['page'] ?? '') !== 'wk-cache-manager-url-crawler') {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $this->maybe_handle_manual_action();
    }

    /**
     * Admin-only AJAX handler to run process_queue_batch synchronously.
     * Used by the "Process Queue Now" button in settings.
     */
    public function handle_process_queue_now()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('wk_cache_manager_process_queue_now', 'nonce');

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);
        $this->process_queue_batch();
        wp_send_json_success(['message' => 'processed']);
    }

    public function init()
    {
        // Load plugin text domain
        load_plugin_textdomain('wk-cache-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'wk-cache-manager',
            __('URL Crawler', 'wk-cache-manager'),
            __('URL Crawler', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-url-crawler',
            [$this, 'render_combined_page']
        );

        // Hidden alias kept for backward-compat: redirects to combined page w/ logs view
        add_submenu_page(
            null,
            __('URL Crawler Logs', 'wk-cache-manager'),
            __('URL Crawler Logs', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-url-crawler-logs',
            [$this, 'redirect_to_logs_view']
        );
    }

    public function redirect_to_logs_view()
    {
        wp_safe_redirect(admin_url('admin.php?page=wk-cache-manager-url-crawler&view=logs'));
        exit;
    }

    /**
     * Combined Settings + Logs page w/ inner tabs.
     */
    public function render_combined_page()
    {
        $view = isset($_GET['view']) && $_GET['view'] === 'logs' ? 'logs' : 'settings';
        if ($view === 'logs') {
            $this->render_logs_page();
        } else {
            $this->render_settings_page();
        }
    }

    public function register_settings()
    {
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_enabled', 'boolean');
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_auto_crawl', 'boolean');
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_crawl_categories', 'boolean');
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_crawl_delay', [
            'type' => 'integer',
            'default' => 60,
            'sanitize_callback' => function ($v) {
                $v = (int) $v;
                if ($v < 30) return 30;
                if ($v > 300) return 300;
                return $v;
            }
        ]);
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_dedup_minutes', [
            'type' => 'integer',
            'default' => 5,
            'sanitize_callback' => function ($v) {
                $v = (int) $v;
                if ($v < 0) return 0;
                if ($v > 1440) return 1440;
                return $v;
            }
        ]);
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_batch_cap', [
            'type' => 'integer',
            'default' => 20,
            'sanitize_callback' => function ($v) {
                $v = (int) $v;
                if ($v < 1) return 1;
                if ($v > 200) return 200;
                return $v;
            }
        ]);
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_log_retention_days', [
            'type' => 'integer',
            'default' => 30,
            'sanitize_callback' => 'absint'
        ]);
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_basic_auth_user', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('wk_cache_manager_url_crawler_settings', 'wk_cache_manager_url_crawler_basic_auth_pass', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        add_settings_section(
            'wk_cache_manager_url_crawler_main',
            __('Main Settings', 'wk-cache-manager'),
            null,
            'wk-cache-manager-url-crawler'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_enabled',
            __('Enable URL Crawler', 'wk-cache-manager'),
            [$this, 'render_enabled_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_auto_crawl',
            __('Auto Crawl on Publish/Update', 'wk-cache-manager'),
            [$this, 'render_auto_crawl_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_crawl_categories',
            __('Crawl Product Categories', 'wk-cache-manager'),
            [$this, 'render_crawl_categories_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_crawl_delay',
            __('Crawl Delay (seconds)', 'wk-cache-manager'),
            [$this, 'render_crawl_delay_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_dedup_minutes',
            __('Dedup Window (minutes)', 'wk-cache-manager'),
            [$this, 'render_dedup_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_batch_cap',
            __('Max URLs per Batch', 'wk-cache-manager'),
            [$this, 'render_batch_cap_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_log_retention_days',
            __('Log Retention (Days)', 'wk-cache-manager'),
            [$this, 'render_log_retention_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );

        add_settings_field(
            'wk_cache_manager_url_crawler_basic_auth',
            __('HTTP Basic Auth (optional)', 'wk-cache-manager'),
            [$this, 'render_basic_auth_field'],
            'wk-cache-manager-url-crawler',
            'wk_cache_manager_url_crawler_main'
        );
    }

    public function render_settings_page()
    {
        $queue = get_option(self::QUEUE_KEY, []);
        $next_cron = wp_next_scheduled('wk_cache_manager_process_crawl_queue');
        $log_file = WK_CACHE_MANAGER_LOG_DIR . 'url-crawler-' . current_time('Y-m-d') . '.log';
        $last_crawl_age = file_exists($log_file) ? human_time_diff(filemtime($log_file), time()) . ' ago' : 'never';
        $recent_crawls = $this->get_recent_crawls(5);
        \WKCacheManager\Admin::render_shell_open('URL Crawler', 'Auto cache warming on product/post updates.', 'url-crawler');
        \WKCacheManager\Admin::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-url-crawler'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-url-crawler&view=logs'), 'label' => 'Logs'],
        ], 'settings');
        ?>
            <?php $this->render_manual_notice(); ?>

            <?php
            // Help block lives OUTSIDE the grid so it spans full width and
            // doesn't leave empty sibling cells in the auto-fit grid.
            \WKCacheManager\Admin::render_help(
                __('How does URL Crawler work?', 'wk-cache-manager'),
                    '<p><strong>' . esc_html__('Problem it solves:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('When a product is updated or sold, LiteSpeed/CloudFlare invalidate its cached HTML. The next visitor pays the full uncached render cost (slow database queries, theme rendering, image processing). Crawler pre-warms the cache so that visitor gets an instant HIT instead.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('Trigger events:', 'wk-cache-manager') . '</strong></p>'
                    . '<ul style="margin:0 0 8px 18px;line-height:1.6;">'
                    . '<li>' . esc_html__('Product updated (woocommerce_update_product)', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Product sold — payment_complete, order_status_completed, reduce_order_stock', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Post published (publish_post)', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Product category edited (edit_term)', 'wk-cache-manager') . '</li>'
                    . '</ul>'

                    . '<p><strong>' . esc_html__('What happens after a trigger:', 'wk-cache-manager') . '</strong></p>'
                    . '<ol style="margin:0 0 8px 18px;line-height:1.6;">'
                    . '<li>' . esc_html__('Affected product ID is added to a queue (deduped — same product saved 10× in row = 1 queue entry).', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('A single wp_schedule_single_event is set for "now + Crawl Delay seconds" (default 60s). The delay gives LiteSpeed/CloudFlare time to finish purging the OLD cache first — otherwise we\'d warm the stale version about to be invalidated.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('cPanel cron tick fires wp-cron.php → batch processor runs.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Plugin builds the URL list: product page + (optionally) its categories + homepage. Deduped across all queued products in the batch.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Dedup window check (default 5min): skip any URL already crawled within that window. Prevents burst load when 100 products are updated in seconds.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Batch cap (default 20 URLs): if more than cap, remaining URLs are deferred to next cron tick.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Each URL is fetched via wp_remote_get with full browser-like headers (User-Agent, Accept, Sec-Fetch-*) so LiteSpeed/CloudFlare treat it as a real visitor and CACHE the response.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Two attempts per URL: attempt 1 populates the cache (MISS expected), attempt 2 verifies HIT.', 'wk-cache-manager') . '</li>'
                    . '<li>' . esc_html__('Result is written to the daily log with status, HTTP code, CF cache status, LiteSpeed cache status, elapsed time.', 'wk-cache-manager') . '</li>'
                    . '</ol>'

                    . '<p><strong>' . esc_html__('Settings you can tune:', 'wk-cache-manager') . '</strong></p>'
                    . '<ul style="margin:0 0 8px 18px;line-height:1.6;">'
                    . '<li><em>' . esc_html__('Auto Crawl on Publish/Update:', 'wk-cache-manager') . '</em> ' . esc_html__('Master switch for automatic warming.', 'wk-cache-manager') . '</li>'
                    . '<li><em>' . esc_html__('Crawl Product Categories:', 'wk-cache-manager') . '</em> ' . esc_html__('Disable to skip category warming and reduce server load on stores with many categories per product.', 'wk-cache-manager') . '</li>'
                    . '<li><em>' . esc_html__('Crawl Delay (seconds):', 'wk-cache-manager') . '</em> ' . esc_html__('30-300s. Default 60s. Longer = more time for purges to settle before we re-warm.', 'wk-cache-manager') . '</li>'
                    . '<li><em>' . esc_html__('Dedup Window (minutes):', 'wk-cache-manager') . '</em> ' . esc_html__('0-1440. Default 5min. Skip re-crawling same URL within this window.', 'wk-cache-manager') . '</li>'
                    . '<li><em>' . esc_html__('Max URLs per Batch:', 'wk-cache-manager') . '</em> ' . esc_html__('1-200. Default 20. Cap per cron tick. Lower = gentler on server.', 'wk-cache-manager') . '</li>'
                    . '<li><em>' . esc_html__('HTTP Basic Auth:', 'wk-cache-manager') . '</em> ' . esc_html__('Optional. Set username/password if your site is behind .htpasswd (staging environments).', 'wk-cache-manager') . '</li>'
                    . '</ul>'

                    . '<p><strong>' . esc_html__('Manual Trigger card:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('"Test Crawl URL" warms a specific URL synchronously and shows the result inline. "Process Queue Now" runs the queued batch immediately, skipping the Crawl Delay.', 'wk-cache-manager') . '</p>'

                    . '<p><strong>' . esc_html__('Logs tab:', 'wk-cache-manager') . '</strong> '
                    . esc_html__('Every crawl attempt is logged with the cache status reported by CloudFlare and LiteSpeed (HIT/MISS/DYNAMIC/UNKNOWN). Use this to verify warming actually populated the cache.', 'wk-cache-manager') . '</p>'
            );
            ?>

            <div class="wkcm-main">
                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">01</span>
                        <h2><?php esc_html_e('Live Status', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Queue depth, processor state, last activity.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <tbody>
                                <tr><td>Queue depth</td><td><?php echo count($queue); ?> products waiting</td></tr>
                                <tr><td>Next scheduled run</td><td><?php echo $next_cron ? esc_html(date('H:i:s', $next_cron + (get_option('gmt_offset',0)*HOUR_IN_SECONDS)) . ' (in ' . max(0, $next_cron - time()) . 's)') : '<span class="wkcm-badge is-muted">not scheduled</span>'; ?></td></tr>
                                <tr><td>Last log activity</td><td><?php echo esc_html($last_crawl_age); ?></td></tr>
                                <tr><td>Processor lock</td><td><?php echo get_transient(self::PROCESS_LOCK_KEY) ? '<span class="wkcm-badge is-warning">Running</span>' : '<span class="wkcm-badge is-success">Idle</span>'; ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (!empty($queue)): ?>
                <div class="wkcm-card is-wide">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">1b</span>
                        <h2><?php esc_html_e('Queued URLs (next batch)', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Products waiting to have their cache warmed. URLs shown are what will actually be fetched.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Product', 'wk-cache-manager'); ?></th>
                                    <th><?php esc_html_e('URLs to crawl', 'wk-cache-manager'); ?></th>
                                    <th><?php esc_html_e('Source', 'wk-cache-manager'); ?></th>
                                    <th><?php esc_html_e('Queued', 'wk-cache-manager'); ?></th>
                                    <th><?php esc_html_e('ETA', 'wk-cache-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $crawl_categories = (bool) get_option('wk_cache_manager_url_crawler_crawl_categories', true);
                                $crawl_homepage = (bool) get_option('wk_cache_manager_url_crawler_crawl_homepage', false);
                                foreach ($queue as $item):
                                    $pid = (int) ($item['product_id'] ?? 0);
                                    if (!$pid) continue;
                                    $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
                                    $name = $product ? $product->get_name() : ('#' . $pid);
                                    $perma = get_permalink($pid) ?: '';
                                    $urls = [];
                                    if ($perma) $urls[] = $perma;
                                    if ($crawl_categories && $product) {
                                        $cats = wp_get_post_terms($pid, 'product_cat');
                                        if (!is_wp_error($cats)) {
                                            foreach ($cats as $c) {
                                                $u = get_term_link($c);
                                                if (!is_wp_error($u)) $urls[] = $u;
                                            }
                                        }
                                    }
                                    if ($crawl_homepage) $urls[] = home_url('/');
                                    $added = (int) ($item['added_at'] ?? 0);
                                    $eta = $next_cron ? max(0, $next_cron - time()) : null;
                                    $source = (string) ($item['source'] ?? '');
                                    $last_source = (string) ($item['last_source'] ?? '');
                                    $requeue = (int) ($item['requeue_count'] ?? 0);
                                    // Friendly label for known WP/WC hook names
                                    $source_label = $source;
                                    $source_map = [
                                        'woocommerce_update_product' => __('Product updated', 'wk-cache-manager'),
                                        'woocommerce_payment_complete' => __('Order paid', 'wk-cache-manager'),
                                        'woocommerce_order_status_completed' => __('Order completed', 'wk-cache-manager'),
                                        'woocommerce_reduce_order_stock' => __('Stock reduced', 'wk-cache-manager'),
                                        'publish_post' => __('Post published', 'wk-cache-manager'),
                                        'manual' => __('Manual trigger', 'wk-cache-manager'),
                                        'product_update' => __('Product updated', 'wk-cache-manager'),
                                        'unknown' => __('Unknown', 'wk-cache-manager'),
                                    ];
                                    // Source string may be "hook:order#123" — split for friendly label
                                    $order_part = '';
                                    if (strpos($source, ':') !== false) {
                                        list($hook_part, $order_part) = explode(':', $source, 2);
                                        $source_label = $source_map[$hook_part] ?? $hook_part;
                                    } else {
                                        $source_label = $source_map[$source] ?? ($source !== '' ? $source : __('Unknown', 'wk-cache-manager'));
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($perma): ?><a href="<?php echo esc_url($perma); ?>" target="_blank"><?php echo esc_html($name); ?></a><?php else: echo esc_html($name); endif; ?>
                                        <br><span style="color:var(--wk-text-muted);font-size:11px;">ID: <?php echo (int) $pid; ?></span>
                                    </td>
                                    <td>
                                        <?php if (empty($urls)): ?>
                                            <em style="color:var(--wk-text-muted);"><?php esc_html_e('(no URLs — product unpublished?)', 'wk-cache-manager'); ?></em>
                                        <?php else: ?>
                                            <ul style="margin:0;padding:0;list-style:none;font-size:12px;line-height:1.5;">
                                                <?php foreach ($urls as $u): ?>
                                                    <li><code style="background:transparent;padding:0;"><?php echo esc_html($u); ?></code></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="wkcm-badge is-info" title="<?php echo esc_attr($source); ?>"><?php echo esc_html($source_label); ?></span>
                                        <?php if ($order_part !== ''): ?>
                                            <br><a href="<?php echo esc_url(admin_url('post.php?action=edit&post=' . preg_replace('/[^0-9]/', '', $order_part))); ?>" style="font-size:11px;color:var(--wk-text-muted);"><?php echo esc_html($order_part); ?></a>
                                        <?php endif; ?>
                                        <?php if ($requeue > 0): ?>
                                            <br><span style="font-size:11px;color:var(--wk-text-muted);" title="<?php echo esc_attr($last_source); ?>"><?php echo esc_html(sprintf(__('re-queued %d×', 'wk-cache-manager'), $requeue)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $added ? esc_html(human_time_diff($added, time()) . ' ago') : '—'; ?></td>
                                    <td><?php
                                        if ($eta === null) {
                                            echo '<span class="wkcm-badge is-muted">not scheduled</span>';
                                        } elseif ($eta === 0) {
                                            echo '<span class="wkcm-badge is-warning">due now</span>';
                                        } else {
                                            echo 'in ' . (int) $eta . 's';
                                        }
                                    ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">02</span>
                        <h2><?php esc_html_e('Manual Trigger', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Crawl a single URL now, or run the queued batch immediately.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body">
                        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <?php wp_nonce_field('wk_cache_manager_crawl_now', '_wkcm_crawl_nonce'); ?>
                            <input type="hidden" name="wk_cache_manager_action" value="crawl_url">
                            <input type="url" name="crawl_url" placeholder="https://example.com/page/" class="regular-text" required style="flex:1;min-width:240px;">
                            <button type="submit" class="button"><?php esc_html_e('Test Crawl URL', 'wk-cache-manager'); ?></button>
                        </form>
                        <hr style="margin:12px 0;border:0;border-top:1px solid var(--wk-border-soft);">
                        <form method="post">
                            <?php wp_nonce_field('wk_cache_manager_crawl_now', '_wkcm_crawl_nonce'); ?>
                            <input type="hidden" name="wk_cache_manager_action" value="process_queue">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Process Queue Now', 'wk-cache-manager'); ?></button>
                            <span style="color:var(--wk-text-muted);margin-left:8px;font-size:12px;"><?php esc_html_e('Skips the configured Crawl Delay and runs the batch synchronously.', 'wk-cache-manager'); ?></span>
                        </form>
                    </div>
                </div>

                <?php if (!empty($recent_crawls)): ?>
                <div class="wkcm-card is-wide">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">03</span>
                        <h2><?php esc_html_e('Last 5 Crawls', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('From today\'s log. Includes products, categories, homepage.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <thead><tr><th>Time</th><th>Type</th><th>URL</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent_crawls as $c): ?>
                                <tr>
                                    <td><code><?php echo esc_html($c['timestamp']); ?></code></td>
                                    <td><?php echo esc_html($c['type']); ?></td>
                                    <td style="word-break:break-all;"><?php echo esc_html($c['url']); ?></td>
                                    <td><?php
                                        $status = $c['status'];
                                        $class = strpos($status, 'HIT') !== false ? 'is-success' :
                                            (strpos($status, 'MISS') !== false ? 'is-warning' : 'is-muted');
                                        ?><span class="wkcm-badge <?php echo $class; ?>"><?php echo esc_html($status); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div class="wkcm-card is-wide">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">04</span>
                        <h2><?php esc_html_e('Settings', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Auto-crawl behavior, endpoint, batch delay, log retention.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body">
                        <form action="options.php" method="post">
                            <?php
                            settings_fields('wk_cache_manager_url_crawler_settings');
                            do_settings_sections('wk-cache-manager-url-crawler');
                            submit_button();
                            ?>
                        </form>
                    </div>
                </div>
            </div>
        <?php
        \WKCacheManager\Admin::render_shell_close();
    }

    /**
     * Parse last N crawl entries from today's log
     */
    private function get_recent_crawls($limit = 5)
    {
        $file = WK_CACHE_MANAGER_LOG_DIR . 'url-crawler-' . current_time('Y-m-d') . '.log';
        if (!file_exists($file)) {
            return [];
        }
        $lines = array_slice(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -100);
        $out = [];
        foreach (array_reverse($lines) as $line) {
            // Format: [TS]|URL|TYPE|OBJECT_ID|ATTEMPTS|FINAL_STATUS|JSON
            if (!preg_match('/^\[([^\]]+)\]\|([^|]+)\|([^|]+)\|\d+\|\d+\|([^|]+)\|/', $line, $m)) {
                continue;
            }
            $out[] = ['timestamp' => $m[1], 'url' => $m[2], 'type' => $m[3], 'status' => $m[4]];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * Handle "Crawl Now" / "Process Queue" buttons on settings page
     */
    private function maybe_handle_manual_action()
    {
        if (empty($_POST['wk_cache_manager_action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST['_wkcm_crawl_nonce']) || !wp_verify_nonce($_POST['_wkcm_crawl_nonce'], 'wk_cache_manager_crawl_now')) {
            return;
        }

        $action = sanitize_text_field($_POST['wk_cache_manager_action']);

        if ($action === 'crawl_url' && !empty($_POST['crawl_url'])) {
            $url = esc_url_raw($_POST['crawl_url']);
            $start = microtime(true);
            $result = $this->crawl_url($url);
            $this->log_crawl_attempt($url, $result, 'manual', 0);
            $elapsed = round(microtime(true) - $start, 2);
            $last = $result['results'][count($result['results']) - 1] ?? [];
            $status = $last['cf_cache_status'] ?? 'UNKNOWN';
            $code = $last['status_code'] ?? 0;
            set_transient('wkcm_manual_notice_' . get_current_user_id(), [
                'type' => 'success',
                'msg' => sprintf('Crawled %s in %ss — HTTP %s, Cache: %s', $url, $elapsed, $code, $status),
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=wk-cache-manager-url-crawler&done=1'));
            exit;
        }

        if ($action === 'process_queue') {
            $queue = get_option(self::QUEUE_KEY, []);
            $msg = empty($queue) ? 'Queue is empty.' : null;
            if ($msg === null) {
                $count = count($queue);
                $start = microtime(true);
                $this->process_queue_batch();
                $elapsed = round(microtime(true) - $start, 2);
                $msg = sprintf('Processed %d queued product(s) in %ss. Check log below for per-URL results.', $count, $elapsed);
            }
            set_transient('wkcm_manual_notice_' . get_current_user_id(), [
                'type' => empty($queue) ? 'info' : 'success',
                'msg' => $msg,
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=wk-cache-manager-url-crawler&done=1'));
            exit;
        }
    }

    /**
     * Render any one-shot notice stashed by maybe_handle_manual_action() before redirect.
     */
    private function render_manual_notice()
    {
        $key = 'wkcm_manual_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!$notice || !is_array($notice)) {
            return;
        }
        delete_transient($key);
        $type = in_array($notice['type'] ?? 'info', ['success', 'info', 'warning', 'error'], true) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['msg'] ?? '') . '</p></div>';
    }

    public function render_enabled_field()
    {
        $value = get_option('wk_cache_manager_url_crawler_enabled', true);
        echo '<label><input type="checkbox" name="wk_cache_manager_url_crawler_enabled" value="1" ' . checked($value, true, false) . '> ';
        echo esc_html__('Enable URL Crawler feature', 'wk-cache-manager') . '</label>';
        echo '<p class="description">' . esc_html__('Turn this OFF to completely disable URL crawling. When disabled, no URLs will be crawled automatically.', 'wk-cache-manager') . '</p>';
    }

    public function render_auto_crawl_field()
    {
        $value = get_option('wk_cache_manager_url_crawler_auto_crawl', true);
        echo '<input type="checkbox" name="wk_cache_manager_url_crawler_auto_crawl" value="1" ' . checked($value, true, false) . '>';
        echo '<p class="description">' . esc_html__('Automatically crawl URLs when posts are published or updated', 'wk-cache-manager') . '</p>';
    }

    public function render_crawl_categories_field()
    {
        $value = get_option('wk_cache_manager_url_crawler_crawl_categories', true);
        echo '<input type="checkbox" name="wk_cache_manager_url_crawler_crawl_categories" value="1" ' . checked($value, true, false) . '>';
        echo '<p class="description">' . esc_html__('Also crawl category pages when a product is updated. Disable to reduce server load on stores with many categories per product.', 'wk-cache-manager') . '</p>';
    }

    public function render_crawl_delay_field()
    {
        $value = get_option('wk_cache_manager_url_crawler_crawl_delay', 60);
        echo '<input type="number" name="wk_cache_manager_url_crawler_crawl_delay" value="' . esc_attr($value) . '" min="30" max="300" class="small-text"> ';
        echo esc_html__('seconds (min 30, max 300)', 'wk-cache-manager');
        echo '<p class="description">' . esc_html__('Delay between product update/sale and cache warm. Gives LiteSpeed/CloudFlare purges time to propagate first, so the crawler warms the fresh content (not the stale version about to be purged).', 'wk-cache-manager') . '</p>';
    }

    public function render_dedup_field()
    {
        $value = get_option('wk_cache_manager_url_crawler_dedup_minutes', 5);
        echo '<input type="number" name="wk_cache_manager_url_crawler_dedup_minutes" value="' . esc_attr($value) . '" min="0" max="1440" class="small-text"> ';
        echo esc_html__('minutes', 'wk-cache-manager');
        echo '<p class="description">' . esc_html__('Skip re-crawling the same URL within this window. Prevents burst load when the same product is updated repeatedly. Set to 0 to disable dedup.', 'wk-cache-manager') . '</p>';
    }

    public function render_batch_cap_field()
    {
        $value = get_option('wk_cache_manager_url_crawler_batch_cap', 20);
        echo '<input type="number" name="wk_cache_manager_url_crawler_batch_cap" value="' . esc_attr($value) . '" min="1" max="200" class="small-text"> ';
        echo esc_html__('URLs per batch (1-200)', 'wk-cache-manager');
        echo '<p class="description">' . esc_html__('Caps how many URLs are crawled per cron tick. Remaining URLs stay queued for the next tick. Lower = gentler on server, higher = faster warming after bulk imports.', 'wk-cache-manager') . '</p>';
    }

    public function render_log_retention_field()
    {
        $value = get_option('wk_cache_manager_url_crawler_log_retention_days', 30);
        echo '<input type="number" name="wk_cache_manager_url_crawler_log_retention_days" value="' . esc_attr($value) . '" min="1" max="365" class="small-text"> ';
        echo esc_html__('days', 'wk-cache-manager');
        echo '<p class="description">' . esc_html__('Automatically delete logs older than this many days', 'wk-cache-manager') . '</p>';
    }

    public function render_basic_auth_field()
    {
        $user = get_option('wk_cache_manager_url_crawler_basic_auth_user', '');
        $pass = get_option('wk_cache_manager_url_crawler_basic_auth_pass', '');
        echo '<input type="text" name="wk_cache_manager_url_crawler_basic_auth_user" value="' . esc_attr($user) . '" placeholder="username" class="regular-text" autocomplete="off" style="margin-right:8px;">';
        echo '<input type="password" name="wk_cache_manager_url_crawler_basic_auth_pass" value="' . esc_attr($pass) . '" placeholder="password" class="regular-text" autocomplete="off">';
        echo '<p class="description">';
        echo esc_html__('Only needed if your site is behind HTTP Basic Auth (e.g. staging "Access to..." prompt). Crawler will use these credentials so requests get past the auth wall and reach the cache layer. Leave blank in production.', 'wk-cache-manager');
        echo '</p>';
    }

    private function get_crawl_result_key($post_id)
    {
        return self::TRANSIENT_PREFIX . 'result_' . $post_id;
    }

    private function get_category_crawl_key($term_id)
    {
        return self::TRANSIENT_PREFIX . 'cat_result_' . $term_id;
    }

    private function get_crawl_lock_key($url)
    {
        return self::TRANSIENT_PREFIX . 'lock_' . md5($url);
    }

    private function is_url_locked($url)
    {
        return (bool) get_transient($this->get_crawl_lock_key($url));
    }

    private function lock_url($url)
    {
        return set_transient($this->get_crawl_lock_key($url), true, self::CRAWL_LOCK_EXPIRY);
    }

    private function unlock_url($url)
    {
        return delete_transient($this->get_crawl_lock_key($url));
    }

    /**
     * Add product to crawl queue.
     *
     * @param int    $product_id
     * @param string $source Where this entry came from. Examples:
     *               "product_update", "sale:#1234", "manual", "publish".
     *               Used in the admin queue UI so operators can see WHY a URL
     *               is queued, not just that it is.
     */
    private function add_to_queue($product_id, $source = 'unknown')
    {
        $queue = get_option(self::QUEUE_KEY, []);

        if (!is_array($queue)) {
            $queue = [];
        }

        // If the product is already queued, preserve its FIRST source + added_at
        // so we don't lose the original trigger when a later event re-queues it.
        if (isset($queue[$product_id]) && is_array($queue[$product_id])) {
            $queue[$product_id]['last_source'] = $source;
            $queue[$product_id]['requeue_count'] = (int) ($queue[$product_id]['requeue_count'] ?? 0) + 1;
        } else {
            $queue[$product_id] = [
                'product_id' => $product_id,
                'added_at'   => time(),
                'source'     => $source,
            ];
        }

        update_option(self::QUEUE_KEY, $queue, false);

        return true;
    }

    /**
     * Get all items from queue
     */
    private function get_queue()
    {
        $queue = get_option(self::QUEUE_KEY, []);
        return is_array($queue) ? $queue : [];
    }

    /**
     * Clear the queue
     */
    private function clear_queue()
    {
        delete_option(self::QUEUE_KEY);
    }

    /**
     * Check if batch processor is currently running
     */
    private function is_processing()
    {
        return (bool) get_transient(self::PROCESS_LOCK_KEY);
    }

    /**
     * Lock the batch processor
     */
    private function lock_processor()
    {
        return set_transient(self::PROCESS_LOCK_KEY, true, self::PROCESS_LOCK_EXPIRY);
    }

    /**
     * Unlock the batch processor
     */
    private function unlock_processor()
    {
        return delete_transient(self::PROCESS_LOCK_KEY);
    }

    /**
     * Schedule batch processing
     *
     * @param bool $immediate If true, schedule for immediate execution (1 second delay)
     */
    /**
     * Schedule the batch processor. Delay reads from the Crawl Delay setting
     * (default 60s, min 30, max 300) so purges from LiteSpeed / CloudFlare can
     * propagate before we warm the cache.
     */
    private function schedule_batch_processing()
    {
        if (wp_next_scheduled('wk_cache_manager_process_crawl_queue')) {
            return;
        }
        $delay = (int) get_option('wk_cache_manager_url_crawler_crawl_delay', 60);
        if ($delay < 30) $delay = 30;
        if ($delay > 300) $delay = 300;
        wp_schedule_single_event(time() + $delay, 'wk_cache_manager_process_crawl_queue');
        $this->logger->log(sprintf('Batch processor scheduled in %ds', $delay));
    }

    private function log_crawl_attempt($url, $result, $type = 'product', $object_id = 0)
    {
        // Use file-based logger
        $this->logger->log_crawl($url, $result, $type, $object_id);
    }

    public function crawl_url($url)
    {
        // Skip if URL is empty or already being crawled
        if (empty($url) || $this->is_url_locked($url)) {
            return [
                'status' => 'skipped',
                'results' => [[
                    'attempt' => 1,
                    'status_code' => 0,
                    'cf_cache_status' => 'SKIPPED',
                    'timestamp' => current_time('mysql'),
                    'error' => __('URL is empty or already being crawled', 'wk-cache-manager')
                ]]
            ];
        }

        // Lock the URL
        $this->lock_url($url);

        try {
            // Validate and sanitize the URL before crawling
            $validated_url = esc_url_raw($url);

            if (empty($validated_url) || !filter_var($validated_url, FILTER_VALIDATE_URL)) {
                return [
                    'status' => 'error',
                    'results' => [[
                        'attempt' => 1,
                        'status_code' => 0,
                        'cf_cache_status' => 'ERROR',
                        'timestamp' => current_time('mysql'),
                        'error' => __('Invalid URL format', 'wk-cache-manager')
                    ]]
                ];
            }

            // Direct crawl: GET requests from this server, up to 2 attempts:
            //   attempt 1 = warm cache (first hit populates CF/LS)
            //   attempt 2 = verify HIT
            // Headers mimic a real browser so CF / LiteSpeed treat the request as
            // a cacheable visitor (not a bot or admin request).
            $results = [];
            $max_attempts = 2;
            $cached = false;

            $browser_headers = [
                'User-Agent'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,da;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
                'Cache-Control'   => 'no-cache',
                'Pragma'          => 'no-cache',
                'Connection'      => 'keep-alive',
                'DNT'             => '1',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Site'  => 'none',
                'Sec-Fetch-Mode'  => 'navigate',
                'Sec-Fetch-User'  => '?1',
                'Sec-Fetch-Dest'  => 'document',
                'Sec-Ch-Ua'       => '"Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
                'Sec-Ch-Ua-Mobile' => '?0',
                'Sec-Ch-Ua-Platform' => '"macOS"',
            ];

            // Optional HTTP Basic Auth (for staging sites behind .htpasswd / Wordfence basic auth)
            $ba_user = get_option('wk_cache_manager_url_crawler_basic_auth_user', '');
            $ba_pass = get_option('wk_cache_manager_url_crawler_basic_auth_pass', '');
            if ($ba_user !== '' && $ba_pass !== '') {
                $browser_headers['Authorization'] = 'Basic ' . base64_encode($ba_user . ':' . $ba_pass);
            }

            for ($attempt = 1; $attempt <= $max_attempts && !$cached; $attempt++) {
                $response = wp_remote_get($validated_url, [
                    'timeout' => 30,
                    'sslverify' => true,
                    'redirection' => 5,
                    'decompress' => true,
                    'headers' => $browser_headers,
                ]);

                if (is_wp_error($response)) {
                    $results[] = [
                        'attempt' => $attempt,
                        'status_code' => 500,
                        'cf_cache_status' => 'ERROR',
                        'timestamp' => current_time('mysql'),
                        'error' => $response->get_error_message()
                    ];
                    continue;
                }

                $status_code = wp_remote_retrieve_response_code($response);
                $headers = wp_remote_retrieve_headers($response);
                $cf_status = $headers['cf-cache-status'] ?? 'UNKNOWN';
                $ls_status = $headers['x-litespeed-cache'] ?? 'UNKNOWN';

                $results[] = [
                    'attempt' => $attempt,
                    'status_code' => $status_code,
                    'cf_cache_status' => $cf_status,
                    'ls_cache_status' => $ls_status,
                    'timestamp' => current_time('mysql')
                ];

                if ($cf_status === 'HIT' || stripos($ls_status, 'hit') !== false) {
                    $cached = true;
                    break;
                }

                if ($attempt < $max_attempts) {
                    usleep(500000); // 0.5s between attempts
                }
            }

            return [
                'status' => $cached ? 'success' : 'completed',
                'results' => $results
            ];
        } finally {
            // Always unlock the URL
            $this->unlock_url($url);
        }
    }

    public function crawl_post_url($post_id)
    {
        // Check if URL Crawler is enabled
        if (!get_option('wk_cache_manager_url_crawler_enabled', true)) {
            return;
        }

        // Check if auto-crawl is enabled
        if (!get_option('wk_cache_manager_url_crawler_auto_crawl', true)) {
            return;
        }

        // Only proceed if this is a published post
        if (get_post_status($post_id) !== 'publish') {
            return;
        }

        $url = get_permalink($post_id);
        $result = $this->crawl_url($url);

        if (!is_wp_error($result)) {
            update_post_meta($post_id, '_wk_cache_manager_url_crawler_result', $result);
            update_post_meta($post_id, '_wk_cache_manager_url_crawler_last_crawl', current_time('mysql'));
        }
    }

    /**
     * Trigger batch crawl via queue system
     * Multiple products are batched together and processed after a delay
     * This prevents duplicate homepage/category crawls and reduces server load
     *
     * @param int    $product_id
     * @param string $source Trigger source label for the queue view. Defaults
     *               to the WP action that fired this method.
     */
    public function trigger_async_crawl($product_id, $source = '')
    {
        if (!get_option('wk_cache_manager_url_crawler_enabled', true)) {
            return;
        }
        if (!get_option('wk_cache_manager_url_crawler_auto_crawl', true)) {
            return;
        }

        if ($source === '') {
            $source = current_action() ?: 'product_update';
        }

        $this->add_to_queue($product_id, $source);
        $this->schedule_batch_processing();

        $this->logger->log(sprintf('Product #%d queued (source: %s)', $product_id, $source));
    }

    /**
     * Handle product sale - triggered when products are sold
     * Extracts product IDs from order and queues them for crawling
     *
     * @param int $order_id The WooCommerce order ID
     */
    public function handle_product_sale($order_id)
    {
        // Check if URL Crawler is enabled
        if (!get_option('wk_cache_manager_url_crawler_enabled', true)) {
            return;
        }

        // Skip if auto-crawl is disabled
        if (!get_option('wk_cache_manager_url_crawler_auto_crawl', true)) {
            return;
        }

        // Get the order object
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

        if (!$order) {
            $this->logger->log('Could not get order #' . $order_id, 'WARNING');
            return;
        }

        // Get all products from the order
        $items = $order->get_items();

        if (empty($items)) {
            $this->logger->log('Order #' . $order_id . ' has no items', 'WARNING');
            return;
        }

        $product_count = 0;

        // Queue each product for crawling
        foreach ($items as $item) {
            $product_id = $item->get_product_id();

            if (!$product_id) {
                continue;
            }

            // Add product to queue, tagged with the order ID + WC hook so the
            // admin queue view can show WHY this URL is being crawled.
            $hook = current_action() ?: 'product_sale';
            $this->add_to_queue($product_id, sprintf('%s:order#%d', $hook, $order_id));
            $product_count++;
        }

        if ($product_count > 0) {
            // Check if immediate crawl is enabled
            $immediate_crawl = get_option('wk_cache_manager_url_crawler_immediate', false);

            // Schedule batch processing
            $this->schedule_batch_processing($immediate_crawl);

            // Log the sale
            $this->logger->log(sprintf(
                'Order #%d completed: %d products added to crawl queue. Immediate: %s',
                $order_id,
                $product_count,
                $immediate_crawl ? 'YES' : 'NO'
            ));
        }
    }

    /**
     * Process queue batch - crawl all queued products with deduplication
     */
    public function process_queue_batch()
    {
        $this->logger->log('process_queue_batch entered');

        if ($this->is_processing()) {
            $this->logger->log('Batch skipped — already processing', 'INFO');
            return;
        }

        $queue = $this->get_queue();
        $this->logger->log(sprintf('Queue read: %d items', count($queue)));
        if (empty($queue)) {
            return;
        }

        $this->lock_processor();

        try {
            $crawl_categories = get_option('wk_cache_manager_url_crawler_crawl_categories', true);
            $batch_cap = (int) get_option('wk_cache_manager_url_crawler_batch_cap', 20);
            if ($batch_cap < 1) $batch_cap = 1;

            // Collect candidate URLs with dedup by URL string
            $candidates = []; // url => ['url','type','object_id']
            foreach ($queue as $item) {
                $product_id = $item['product_id'];
                $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
                if (!$product) continue;

                $product_url = get_permalink($product_id);
                if ($product_url && !isset($candidates[$product_url])) {
                    $candidates[$product_url] = [
                        'url' => $product_url,
                        'type' => 'product',
                        'object_id' => $product_id,
                    ];
                }

                if ($crawl_categories) {
                    $terms = get_the_terms($product_id, 'product_cat');
                    if ($terms && !is_wp_error($terms)) {
                        foreach ($terms as $term) {
                            $cat_url = get_term_link($term);
                            if (!is_wp_error($cat_url) && !isset($candidates[$cat_url])) {
                                $candidates[$cat_url] = [
                                    'url' => $cat_url,
                                    'type' => 'category',
                                    'object_id' => $term->term_id,
                                ];
                            }
                        }
                    }
                }
            }

            // Homepage warmed once per batch
            $homepage = home_url('/');
            if (!isset($candidates[$homepage])) {
                $candidates[$homepage] = ['url' => $homepage, 'type' => 'homepage', 'object_id' => 0];
            }

            // Filter out URLs recently crawled (dedup window)
            $crawl_now = [];
            $skipped_dedup = 0;
            foreach ($candidates as $url => $entry) {
                if ($this->is_url_recently_crawled($url)) {
                    $skipped_dedup++;
                    continue;
                }
                $crawl_now[] = $entry;
            }

            $total = count($crawl_now);
            $deferred = 0;
            if ($total > $batch_cap) {
                $deferred = $total - $batch_cap;
                $crawl_now = array_slice($crawl_now, 0, $batch_cap);
            }

            $this->logger->log(sprintf(
                'Batch start: %d candidates, %d skipped (dedup), %d to crawl, %d deferred',
                count($candidates), $skipped_dedup, count($crawl_now), $deferred
            ));

            foreach ($crawl_now as $entry) {
                $result = $this->crawl_url($entry['url']);
                $this->log_crawl_attempt($entry['url'], $result, $entry['type'], $entry['object_id']);
                $this->mark_url_crawled($entry['url']);
                usleep(500000); // 0.5s spacing between URLs
            }

            // If we deferred URLs, leave them queued (caller re-schedules) — otherwise clear
            if ($deferred > 0) {
                // Re-schedule next batch for remaining URLs on next cron tick
                if (!wp_next_scheduled('wk_cache_manager_process_crawl_queue')) {
                    wp_schedule_single_event(time() + 60, 'wk_cache_manager_process_crawl_queue');
                }
                $this->logger->log(sprintf('%d URLs deferred to next batch', $deferred));
            } else {
                $this->clear_queue();
            }
        } catch (\Throwable $e) {
            $this->logger->log(sprintf(
                'Batch ERROR: %s in %s:%d',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine()
            ), 'ERROR');
        } finally {
            $this->unlock_processor();
            $this->logger->log('process_queue_batch exited');
        }
    }

    /**
     * Has this URL been crawled within the dedup window?
     */
    private function is_url_recently_crawled($url)
    {
        $minutes = (int) get_option('wk_cache_manager_url_crawler_dedup_minutes', 5);
        if ($minutes <= 0) return false;
        return (bool) get_transient(self::DEDUP_PREFIX . md5($url));
    }

    /**
     * Mark URL as crawled for the dedup window.
     */
    private function mark_url_crawled($url)
    {
        $minutes = (int) get_option('wk_cache_manager_url_crawler_dedup_minutes', 5);
        if ($minutes <= 0) return;
        set_transient(self::DEDUP_PREFIX . md5($url), time(), $minutes * MINUTE_IN_SECONDS);
    }

    public function crawl_product_urls($product_id)
    {
        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Crawl product URL
        $product_url = get_permalink($product_id);
        if ($product_url) {
            $result = $this->crawl_url($product_url);
            $this->log_crawl_attempt($product_url, $result, 'product', $product_id);

            // Add small delay before crawling category
            usleep(500000);
        }

        // Crawl category URLs if enabled
        if (get_option('wk_cache_manager_url_crawler_crawl_categories', true)) {
            $terms = get_the_terms($product_id, 'product_cat');
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $category_url = get_term_link($term);
                    if (!is_wp_error($category_url)) {
                        $result = $this->crawl_url($category_url);
                        $this->log_crawl_attempt($category_url, $result, 'category', $term->term_id);

                        // Add small delay between category crawls
                        usleep(500000);
                    }
                }
            }
        }

        // Crawl homepage/site URL
        $home_url = home_url('/');
        if ($home_url) {
            // Add small delay before crawling homepage
            usleep(500000);

            $result = $this->crawl_url($home_url);
            $this->log_crawl_attempt($home_url, $result, 'homepage', 0);
        }
    }

    public function render_logs_page()
    {
        // Date picker
        $all_log_files = $this->logger->get_all_log_files();
        $available_dates = [];
        foreach ($all_log_files as $f) {
            if (preg_match('/url-crawler-(\d{4}-\d{2}-\d{2})\.log$/', basename($f), $m)) {
                $available_dates[] = $m[1];
            }
        }
        rsort($available_dates);
        $today = current_time('Y-m-d');
        $selected_date = isset($_GET['log_date']) ? sanitize_text_field($_GET['log_date']) : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
            $selected_date = $today;
        }

        // Get logs for selected date (no cap)
        $logs = $this->logger->get_logs_for_date($selected_date);

        // Get log file stats
        $total_files = count($all_log_files);
        $total_size = $this->logger->get_total_log_size();
        $total_count = $this->logger->get_total_log_count();
        $retention_days = get_option('wk_cache_manager_url_crawler_log_retention_days', 30);

        $show_raw = isset($_GET['raw']) && $_GET['raw'] == '1';
        \WKCacheManager\Admin::render_shell_open('URL Crawler', 'Crawl history with HIT/MISS status.', 'url-crawler');
        \WKCacheManager\Admin::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-url-crawler'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-url-crawler&view=logs'), 'label' => 'Logs'],
        ], 'logs');
        ?>
        <div class="wkcm-main wkcm-main--stack">

            <div class="wkcm-card">
                <div class="wkcm-card-head">
                    <span class="wkcm-card-num">01</span>
                    <h2><?php esc_html_e('Log Statistics', 'wk-cache-manager'); ?></h2>
                    <p><?php printf(esc_html__('Daily-rotated logs. Retention %d days.', 'wk-cache-manager'), (int) $retention_days); ?></p>
                </div>
                <div class="wkcm-card-body" style="padding:0;">
                    <table class="wkcm-table"><tbody>
                        <tr><td><?php esc_html_e('Total log files', 'wk-cache-manager'); ?></td><td><?php echo esc_html(number_format($total_files)); ?></td></tr>
                        <tr><td><?php esc_html_e('Total entries', 'wk-cache-manager'); ?></td><td><?php echo esc_html(number_format($total_count)); ?></td></tr>
                        <tr><td><?php esc_html_e('Total size', 'wk-cache-manager'); ?></td><td><?php echo esc_html(size_format($total_size)); ?></td></tr>
                        <tr><td><?php esc_html_e('Retention', 'wk-cache-manager'); ?></td><td><?php echo (int) $retention_days; ?> days</td></tr>
                    </tbody></table>
                </div>
                <div class="wkcm-card-body" style="border-top:1px solid var(--wk-border-soft);">
                    <form method="get" style="display:inline-block;margin-right:8px;">
                        <input type="hidden" name="page" value="wk-cache-manager-url-crawler" />
                        <input type="hidden" name="view" value="logs" />
                        <?php if ($show_raw): ?><input type="hidden" name="raw" value="1" /><?php endif; ?>
                        <label for="log_date" style="margin-right:4px;"><?php esc_html_e('Date:', 'wk-cache-manager'); ?></label>
                        <select name="log_date" id="log_date" onchange="this.form.submit()">
                            <?php if (empty($available_dates)): ?>
                                <option value="<?php echo esc_attr($today); ?>"><?php echo esc_html($today); ?></option>
                            <?php else: ?>
                                <?php foreach ($available_dates as $d): ?>
                                    <option value="<?php echo esc_attr($d); ?>" <?php selected($selected_date, $d); ?>>
                                        <?php echo esc_html($d); ?><?php if ($d === $today) echo ' ' . esc_html__('(today)', 'wk-cache-manager'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </form>
                    <?php if ($show_raw): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-url-crawler-logs&log_date=' . $selected_date)); ?>" class="button"><?php esc_html_e('View Chart & Table', 'wk-cache-manager'); ?></a>
                    <?php else: ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-url-crawler-logs&raw=1&log_date=' . $selected_date)); ?>" class="button"><?php esc_html_e('View Raw Logs', 'wk-cache-manager'); ?></a>
                    <?php endif; ?>
                    <button type="button" class="button wkcm-btn-danger" id="clear-logs" data-nonce="<?php echo esc_attr(wp_create_nonce('wk_cache_manager_clear_url_logs')); ?>"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Clear All Logs', 'wk-cache-manager'); ?></button>
                </div>
            </div>

            <?php if ($show_raw): ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head"><span class="wkcm-card-num">02</span><h2><?php esc_html_e('Raw Log Data', 'wk-cache-manager'); ?></h2><p><?php printf(esc_html__('%s — full day. Format: %s', 'wk-cache-manager'), esc_html($selected_date), '<code>[TS]|URL|TYPE|OBJECT_ID|ATTEMPTS|STATUS|JSON</code>'); ?></p></div>
                    <div class="wkcm-card-body">
                        <?php
                        $raw_file = WK_CACHE_MANAGER_LOG_DIR . 'url-crawler-' . $selected_date . '.log';
                        $raw_content = file_exists($raw_file) ? file_get_contents($raw_file) : '';
                        ?>
                        <?php if ($raw_content === '' || $raw_content === false): ?>
                            <div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span><?php esc_html_e('No log entries for this date.', 'wk-cache-manager'); ?></div>
                        <?php else: ?>
                            <pre class="wkcm-log-tail" style="max-height:600px;"><?php echo esc_html($raw_content); ?></pre>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($logs) && !$show_raw): ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head"><span class="wkcm-card-num">02</span><h2><?php esc_html_e('Crawl Activity', 'wk-cache-manager'); ?></h2><p>Hourly buckets, last 7 days.</p></div>
                    <div class="wkcm-card-body"><div class="wk-chart-canvas"><canvas id="urlCrawlerChart"></canvas></div></div>
                </div>
            <?php endif; ?>

            <div class="wkcm-card">
                <div class="wkcm-card-head"><span class="wkcm-card-num">03</span><h2><?php esc_html_e('Recent Crawls', 'wk-cache-manager'); ?></h2><p><?php printf(esc_html__('%s — %d entries.', 'wk-cache-manager'), esc_html($selected_date), count($logs)); ?></p></div>
                <div class="wkcm-card-body" style="padding:0;">
            <table class="wkcm-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'wk-cache-manager'); ?></th>
                        <th><?php esc_html_e('URL', 'wk-cache-manager'); ?></th>
                        <th><?php esc_html_e('Type', 'wk-cache-manager'); ?></th>
                        <th><?php esc_html_e('Attempts', 'wk-cache-manager'); ?></th>
                        <th title="<?php esc_attr_e('CloudFlare (CF) and LiteSpeed (LS) cache status from response headers', 'wk-cache-manager'); ?>"><?php esc_html_e('Cache Status', 'wk-cache-manager'); ?></th>
                        <th><?php esc_html_e('Details', 'wk-cache-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('No logs available.', 'wk-cache-manager'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $badge_for = function ($val) {
                            $val = is_string($val) ? trim($val) : 'UNKNOWN';
                            if ($val === '') $val = 'UNKNOWN';
                            $up = strtoupper($val);
                            $cls = '';
                            if (strpos($up, 'HIT') !== false) {
                                $cls = 'hit';
                            } elseif (strpos($up, 'MISS') !== false) {
                                $cls = 'miss';
                            } elseif (strpos($up, 'ERROR') !== false) {
                                $cls = 'error';
                            }
                            return '<span class="status-badge status-' . esc_attr($cls) . '">' . esc_html($val) . '</span>';
                        };
                        ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $last_attempt = !empty($log['attempts_detail']) ? end($log['attempts_detail']) : null;
                            $cf_val = is_array($last_attempt) && isset($last_attempt['cf_cache_status']) ? $last_attempt['cf_cache_status'] : ($log['final_status'] ?? 'UNKNOWN');
                            $ls_val = is_array($last_attempt) && isset($last_attempt['ls_cache_status']) ? $last_attempt['ls_cache_status'] : 'UNKNOWN';
                            ?>
                            <tr>
                                <td><?php echo esc_html(get_date_from_gmt($log['timestamp'])); ?></td>
                                <td><code><?php echo esc_html($log['url']); ?></code></td>
                                <td>
                                    <?php
                                if ($log['type'] === 'product' && function_exists('wc_get_product')) {
                                    $product = wc_get_product($log['object_id']);
                                    echo $product ? esc_html($product->get_name()) : 'Product #' . $log['object_id'];
                                } elseif ($log['type'] === 'homepage') {
                                    echo 'Homepage';
                                } elseif ($log['type'] === 'shop') {
                                    echo 'Shop Page';
                                } elseif ($log['type'] === 'category') {
                                    $term = get_term($log['object_id'], 'product_cat');
                                    echo $term && !is_wp_error($term) ? esc_html($term->name) : 'Category #' . $log['object_id'];
                                } else {
                                    echo esc_html(ucfirst($log['type']));
                                }
                            ?>
                                </td>
                                <td><?php echo esc_html($log['attempts']); ?></td>
                                <td>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
                                        <span style="display:inline-flex;gap:6px;align-items:center;font-size:11px;"><strong style="color:var(--wk-text-muted);width:22px;">CF</strong><?php echo $badge_for($cf_val); ?></span>
                                        <span style="display:inline-flex;gap:6px;align-items:center;font-size:11px;"><strong style="color:var(--wk-text-muted);width:22px;">LS</strong><?php echo $badge_for($ls_val); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($log['attempts_detail'])): ?>
                                        <button type="button" class="button button-small view-attempts"
                                                data-attempts='<?php echo esc_attr(json_encode($log['attempts_detail'])); ?>'>
                                            <?php esc_html_e('View Progress', 'wk-cache-manager'); ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="description"><?php esc_html_e('No details', 'wk-cache-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p class="description" style="padding: 12px 16px; margin: 0; color: var(--wk-text-muted); font-size: 12px;">
                <?php printf(esc_html__('Showing last %d entries from the last 7 days. Logs are automatically deleted after %d days.', 'wk-cache-manager'),
                    count($logs),
                    (int) $retention_days
                ); ?>
            </p>

        <!-- Modal for viewing attempt details -->
        <div id="attempts-modal" style="display: none;">
            <div class="attempts-content">
                <h3><?php esc_html_e('Cache Status Progress', 'wk-cache-manager'); ?></h3>
                <div class="attempts-list"></div>
            </div>
        </div>

        <style>
            .status-badge {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .status-badge.status-hit {
                background: #d4edda;
                color: #155724;
            }
            .status-badge.status-miss {
                background: #fff3cd;
                color: #856404;
            }
            .status-badge.status-error {
                background: #f8d7da;
                color: #721c24;
            }
        </style>

        <?php if (!empty($logs)): ?>
            <!-- Chart Data Script -->
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var chartData = <?php echo json_encode($this->get_url_crawler_chart_data()); ?>;

                    if (chartData && chartData.labels && chartData.labels.length > 0) {
                        var ctx = document.getElementById('urlCrawlerChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: chartData.labels,
                                datasets: [
                                    {
                                        label: 'Successful (HIT)',
                                        data: chartData.success,
                                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                                        borderColor: '#10b981',
                                        borderWidth: 2
                                    },
                                    {
                                        label: 'Partial (MISS)',
                                        data: chartData.miss,
                                        backgroundColor: 'rgba(245, 158, 11, 0.8)',
                                        borderColor: '#f59e0b',
                                        borderWidth: 2
                                    },
                                    {
                                        label: 'Failed (ERROR)',
                                        data: chartData.error,
                                        backgroundColor: 'rgba(239, 68, 68, 0.8)',
                                        borderColor: '#ef4444',
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
                                        stacked: false,
                                        beginAtZero: true,
                                        ticks: {
                                            precision: 0
                                        },
                                        title: {
                                            display: true,
                                            text: 'Number of URLs',
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
                </div><!-- /.wkcm-card-body recent-crawls -->
            </div><!-- /.wkcm-card recent-crawls -->
        </div><!-- /.wkcm-main -->
        <?php
        \WKCacheManager\Admin::render_shell_close();
    }

    /**
     * Get chart data for URL Crawler
     */
    private function get_url_crawler_chart_data()
    {
        $log_files = $this->logger->get_all_log_files();
        $hourly_data = [];

        // Read from last 7 days
        rsort($log_files);
        $files_to_read = array_slice($log_files, 0, 7);

        foreach ($files_to_read as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $parts = explode('|', $line);
                    if (count($parts) >= 6) {
                        $timestamp = trim($parts[0]);
                        $final_status = trim($parts[5]);

                        $hour_key = date('Y-m-d H:00', strtotime($timestamp));

                        if (!isset($hourly_data[$hour_key])) {
                            $hourly_data[$hour_key] = [
                                'success' => 0,
                                'miss' => 0,
                                'error' => 0
                            ];
                        }

                        if (stripos($final_status, 'HIT') !== false) {
                            $hourly_data[$hour_key]['success']++;
                        } elseif (stripos($final_status, 'MISS') !== false) {
                            $hourly_data[$hour_key]['miss']++;
                        } else {
                            $hourly_data[$hour_key]['error']++;
                        }
                    }
                }
            }
        }

        // Sort by time
        ksort($hourly_data);

        // Prepare chart data
        $labels = [];
        $success = [];
        $miss = [];
        $error = [];

        foreach ($hourly_data as $hour => $data) {
            $labels[] = date('M d, H:i', strtotime($hour));
            $success[] = $data['success'];
            $miss[] = $data['miss'];
            $error[] = $data['error'];
        }

        return [
            'labels' => $labels,
            'success' => $success,
            'miss' => $miss,
            'error' => $error
        ];
    }

    public function handle_clear_logs()
    {
        check_ajax_referer('wk_cache_manager_clear_url_logs', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Clear all file-based logs
        $this->logger->clear_all_logs();
        wp_send_json_success();
    }

    public function handle_category_update($term_id, $tt_id, $taxonomy)
    {
        // Only handle WooCommerce product categories
        if ($taxonomy !== 'product_cat') {
            return;
        }

        // Check if auto-crawl is enabled
        if (!get_option('wk_cache_manager_url_crawler_auto_crawl', true)) {
            return;
        }

        // Check if category crawling is enabled
        if (!get_option('wk_cache_manager_url_crawler_crawl_categories', true)) {
            return;
        }

        // Crawl the category URL
        $category_url = get_term_link($term_id);
        if (!is_wp_error($category_url)) {
            $result = $this->crawl_url($category_url);
            $this->log_crawl_attempt($category_url, $result, 'category', $term_id);
        }
    }
}
