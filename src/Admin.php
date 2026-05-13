<?php

namespace WKCacheManager;

class Admin
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
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 999);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_admin_bar_styles']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_bar_styles']);
        add_action('wp_ajax_wk_cache_manager_tail_log', [$this, 'ajax_tail_log']);

        // Lightweight no-op handler for loopback health probe (Dashboard).
        // Without this the probe POST hits a 400, and although the transient
        // caches the result it still blocks TTFB on first dashboard load
        // after every cache flush.
        add_action('wp_ajax_nopriv_wkcm_loopback_health', [$this, 'ajax_loopback_health_noop']);
        add_action('wp_ajax_wkcm_loopback_health', [$this, 'ajax_loopback_health_noop']);

        // Suppress noisy 3rd-party admin notices on our own pages so customers
        // don't see "your license expired" boxes from unrelated plugins inside
        // the WK Cache Manager dashboards.
        add_action('in_admin_header', [$this, 'suppress_foreign_admin_notices'], 1000);

        // Note: cron tick recording lives in Plugin.php::load_modules() so it
        // runs even on cron requests where Admin class is intentionally skipped.
    }

    /**
     * AJAX: no-op handler for the dashboard loopback health probe.
     * Just returns 200 with empty body so wp_remote_post returns a real status code.
     */
    public function ajax_loopback_health_noop()
    {
        wp_die('', '', ['response' => 200]);
    }

    /**
     * AJAX: return last N lines of a log file.
     */
    public function ajax_tail_log()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        check_ajax_referer('wk_cache_manager_tail', 'nonce');

        $type = sanitize_key($_GET['type'] ?? 'cache-monitor');
        $allowed = ['cache-monitor', 'url-crawler', 'litespeed-stock', 'request-monitor'];
        if (!in_array($type, $allowed, true)) {
            wp_send_json_error('bad type', 400);
        }
        $lines = max(1, min(500, (int) ($_GET['lines'] ?? 100)));
        $file = WK_CACHE_MANAGER_LOG_DIR . $type . '-' . current_time('Y-m-d') . '.log';
        if (!file_exists($file)) {
            wp_send_json_success(['lines' => [], 'mtime' => 0, 'file' => basename($file)]);
        }
        $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        wp_send_json_success([
            'lines' => array_slice($all, -$lines),
            'mtime' => filemtime($file),
            'file' => basename($file),
            'total' => count($all),
        ]);
    }

    /**
     * Strip every other plugin/theme admin notice when the user is viewing one
     * of our own pages. Keeps our own notices intact (we use inline notices
     * rendered after this filter fires, not via the admin_notices hook stack).
     */
    public function suppress_foreign_admin_notices()
    {
        $page = $_GET['page'] ?? '';
        if (strpos($page, 'wk-cache-manager') !== 0) {
            return;
        }
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('user_admin_notices');
        remove_all_actions('network_admin_notices');
    }

    public function add_admin_menu()
    {
        // Main menu page
        add_menu_page(
            __('WK Cache Manager', 'wk-cache-manager'),
            __('WK Cache Manager', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager',
            [$this, 'render_dashboard'],
            'dashicons-performance',
            80
        );

        // Dashboard submenu (same as parent)
        add_submenu_page(
            'wk-cache-manager',
            __('Dashboard', 'wk-cache-manager'),
            __('Dashboard', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager',
            [$this, 'render_dashboard']
        );

        // URL Crawler settings and logs will be added by UrlCrawler module
        // Cache Monitor logs will be added by CacheMonitor module

        // Purge Prevention — combined Settings + Logs
        add_submenu_page(
            'wk-cache-manager',
            __('Purge Prevention', 'wk-cache-manager'),
            __('Purge Prevention', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-purge-prevention',
            [\WKCacheManager\LiteSpeedManager\LiteSpeedManager::get_instance(), 'render_combined_page']
        );
        // Hidden alias for old logs URL
        add_submenu_page(
            null,
            __('Prevention Log', 'wk-cache-manager'),
            __('Prevention Log', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-purge-prevention-log',
            [\WKCacheManager\LiteSpeedManager\LiteSpeedManager::get_instance(), 'redirect_to_log']
        );

        // Request Monitor — combined Settings + Logs
        add_submenu_page(
            'wk-cache-manager',
            __('Request Monitor', 'wk-cache-manager'),
            __('Request Monitor', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-request-monitor-settings',
            [$this, 'render_request_monitor_combined']
        );
        add_submenu_page(
            null,
            __('Request Monitor Logs', 'wk-cache-manager'),
            __('Request Monitor Logs', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-request-monitor-logs',
            [$this, 'redirect_to_request_logs']
        );

        // General Settings
        add_submenu_page(
            'wk-cache-manager',
            __('General Settings', 'wk-cache-manager'),
            __('Settings', 'wk-cache-manager'),
            'manage_options',
            'wk-cache-manager-settings',
            [$this, 'render_general_settings']
        );
    }

    public function render_dashboard()
    {
        $stats = $this->get_dashboard_stats();
        ?>
        <div class="wrap wkcm-shell">
            <div class="wkcm-masthead">
                <div class="wkcm-mast-left">
                    <span class="wkcm-eyebrow">WK / Cache Manager</span>
                    <div class="wkcm-mast-title-row">
                        <h1 class="wkcm-display"><?php esc_html_e('Cache Manager', 'wk-cache-manager'); ?></h1>
                        <p class="wkcm-mast-subtitle"><?php esc_html_e('Cache warming + purge prevention.', 'wk-cache-manager'); ?></p>
                    </div>
                </div>
                <div class="wkcm-mast-right">
                    <dl class="wkcm-statgrid">
                        <div><dt><?php esc_html_e('Requests', 'wk-cache-manager'); ?></dt><dd><?php echo number_format($stats['total_requests']); ?></dd></div>
                        <div><dt><?php esc_html_e('Hit Rate', 'wk-cache-manager'); ?></dt><dd><?php echo (int) $stats['hit_rate']; ?>%</dd></div>
                        <div><dt><?php esc_html_e('Prevented', 'wk-cache-manager'); ?></dt><dd><?php echo number_format($stats['purges_prevented']); ?></dd></div>
                        <div><dt><?php esc_html_e('Crawled', 'wk-cache-manager'); ?></dt><dd><?php echo number_format($stats['urls_crawled']); ?></dd></div>
                        <div><dt><?php esc_html_e('Purges', 'wk-cache-manager'); ?></dt><dd><?php echo number_format($stats['purge_events']); ?></dd></div>
                        <div><dt><?php esc_html_e('Monitor', 'wk-cache-manager'); ?></dt><dd><?php echo $stats['request_monitor_enabled'] ? 'ON' : 'OFF'; ?></dd></div>
                    </dl>
                </div>
            </div>

            <nav class="wkcm-tabs">
                <a class="wkcm-tab is-active" href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager')); ?>"><span class="wkcm-tab-num">01</span> <?php esc_html_e('Overview', 'wk-cache-manager'); ?></a>
                <a class="wkcm-tab" href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-url-crawler')); ?>"><span class="wkcm-tab-num">02</span> <?php esc_html_e('URL Crawler', 'wk-cache-manager'); ?></a>
                <a class="wkcm-tab" href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-purge-prevention')); ?>"><span class="wkcm-tab-num">03</span> <?php esc_html_e('Purge Prevention', 'wk-cache-manager'); ?></a>
                <a class="wkcm-tab" href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings')); ?>"><span class="wkcm-tab-num">04</span> <?php esc_html_e('Cache Purging Monitor', 'wk-cache-manager'); ?></a>
                <a class="wkcm-tab" href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-request-monitor-settings')); ?>"><span class="wkcm-tab-num">05</span> <?php esc_html_e('Request Monitor', 'wk-cache-manager'); ?></a>
                <a class="wkcm-tab" href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-settings')); ?>"><span class="wkcm-tab-num">06</span> <?php esc_html_e('Settings', 'wk-cache-manager'); ?></a>
            </nav>

            <div class="wkcm-main">

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">01</span>
                        <h2><?php esc_html_e('Health Checks', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Live diagnostics for log dir, cron, cache plugins, loopback HTTP.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <tbody>
                            <?php foreach ($this->run_health_checks() as $check): ?>
                                <tr>
                                    <td><?php echo esc_html($check['label']); ?></td>
                                    <td><span class="wkcm-badge <?php echo $check['ok'] ? 'is-success' : 'is-error'; ?>"><?php echo esc_html($check['msg']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">02</span>
                        <h2><?php esc_html_e('System Status', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Module toggles + thresholds.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <tbody>
                                <tr><td>URL Crawler</td><td><?php echo $stats['url_crawler_enabled'] ? '<span class="wkcm-badge is-success">Active</span>' : '<span class="wkcm-badge is-error">Disabled</span>'; ?></td></tr>
                                <tr><td>Category Crawling</td><td><?php echo $stats['crawl_categories'] ? '<span class="wkcm-badge is-success">Enabled</span>' : '<span class="wkcm-badge is-muted">Disabled</span>'; ?></td></tr>
                                <tr><td>Purge Prevention</td><td><span class="wkcm-badge is-success">Active</span></td></tr>
                                <tr><td>Stock Threshold</td><td><?php echo esc_html($stats['stock_threshold']); ?> units</td></tr>
                                <tr><td>Total Log Files</td><td><?php echo count($stats['log_files']); ?> files <span style="color:var(--wk-text-muted)">(<?php echo size_format($stats['total_log_size']); ?>)</span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">03</span>
                        <h2><?php esc_html_e('Quick Links', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Settings + log pages.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body">
                        <ul class="quick-links-list">
                            <li><a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-url-crawler')); ?>" class="button button-small"><span class="dashicons dashicons-share-alt2"></span> URL Crawler</a></li>
                            <li><a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-purge-prevention')); ?>" class="button button-small"><span class="dashicons dashicons-shield"></span> Purge Prevention</a></li>
                            <li><a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings')); ?>" class="button button-small"><span class="dashicons dashicons-admin-tools"></span> Cache Monitor</a></li>
                            <li><a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-request-monitor-settings')); ?>" class="button button-small"><span class="dashicons dashicons-admin-network"></span> Request Monitor</a></li>
                            <li><a href="<?php echo esc_url(admin_url('admin.php?page=wk-cache-manager-settings')); ?>" class="button button-small"><span class="dashicons dashicons-admin-settings"></span> Settings</a></li>
                        </ul>
                    </div>
                </div>

                <div class="wkcm-card is-wide">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">04</span>
                        <h2><?php esc_html_e('Live Log Tail', 'wk-cache-manager'); ?></h2>
                        <p><?php esc_html_e('Stream the last 200 lines of any plugin log. Auto-refresh every 5 seconds.', 'wk-cache-manager'); ?></p>
                    </div>
                    <div class="wkcm-card-body">
                        <div class="wkcm-toolbar">
                            <div class="wkcm-toolbar-field">
                                <label for="wkcm-tail-source">Source</label>
                                <select id="wkcm-tail-source">
                                    <option value="cache-monitor">Cache Monitor (purges)</option>
                                    <option value="url-crawler">URL Crawler</option>
                                    <option value="litespeed-stock">LiteSpeed Stock (purge prevention)</option>
                                    <option value="request-monitor">Request Monitor</option>
                                </select>
                            </div>
                            <label class="wkcm-toolbar-check"><input type="checkbox" id="wkcm-tail-auto" checked> Auto-refresh <span class="wkcm-muted">(5s)</span></label>
                            <button type="button" class="button" id="wkcm-tail-refresh"><span class="dashicons dashicons-update"></span> Refresh</button>
                            <span id="wkcm-tail-status" class="wkcm-toolbar-status"></span>
                        </div>
                        <pre id="wkcm-tail-output">Loading…</pre>
                    </div>
                </div>
                <script>
                (function(){
                    const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    const nonce = <?php echo wp_json_encode(wp_create_nonce('wk_cache_manager_tail')); ?>;
                    const out = document.getElementById('wkcm-tail-output');
                    const src = document.getElementById('wkcm-tail-source');
                    const auto = document.getElementById('wkcm-tail-auto');
                    const status = document.getElementById('wkcm-tail-status');
                    let timer = null;
                    function fetchTail(){
                        const url = ajaxUrl + '?action=wk_cache_manager_tail_log&nonce=' + nonce + '&type=' + src.value + '&lines=200';
                        fetch(url, {credentials:'same-origin'}).then(r=>r.json()).then(j=>{
                            if(!j.success){ out.textContent = 'Error: ' + JSON.stringify(j); return; }
                            const lines = j.data.lines || [];
                            out.textContent = lines.length ? lines.join('\n') : '(empty)';
                            out.scrollTop = out.scrollHeight;
                            status.textContent = '· ' + j.data.file + ' · ' + (j.data.total||0) + ' lines · refreshed ' + new Date().toLocaleTimeString();
                        }).catch(e=>{ status.textContent = 'fetch error'; });
                    }
                    function start(){ if(timer) clearInterval(timer); if(auto.checked) timer = setInterval(fetchTail, 5000); }
                    src.addEventListener('change', fetchTail);
                    auto.addEventListener('change', start);
                    document.getElementById('wkcm-tail-refresh').addEventListener('click', fetchTail);
                    fetchTail(); start();
                })();
                </script>
                </div>

                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">05</span>
                        <h2><?php esc_html_e('Log Directory', 'wk-cache-manager'); ?></h2>
                        <p><code><?php echo esc_html(WK_CACHE_MANAGER_LOG_DIR); ?></code></p>
                    </div>
                    <div class="wkcm-card-body">
                        <?php if ($stats['log_files']): ?>
                            <table class="wkcm-table">
                                <thead><tr><th><?php esc_html_e('File', 'wk-cache-manager'); ?></th><th><?php esc_html_e('Size', 'wk-cache-manager'); ?></th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($stats['log_files'], 0, 10) as $log_file): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($log_file['name']); ?></code></td>
                                        <td style="color: var(--wk-text-muted);"><?php echo esc_html($log_file['size']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span><?php esc_html_e('No log files yet', 'wk-cache-manager'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /.wkcm-main -->
        </div>
        <?php
    }

    /**
     * Get dashboard statistics
     *
     * @return array Dashboard stats
     */
    private function get_dashboard_stats()
    {
        $stats = [
            'total_requests' => 0,
            'hit_rate' => 0,
            'purges_prevented' => 0,
            'urls_crawled' => 0,
            'purge_events' => 0,
            'request_monitor_enabled' => get_option('wk_cache_manager_request_monitor_enabled', false),
            'url_crawler_enabled' => get_option('wk_cache_manager_url_crawler_enabled', true),
            'crawl_categories' => get_option('wk_cache_manager_url_crawler_crawl_categories', true),
            'stock_threshold' => get_option('wk_cache_manager_litespeed_stock_threshold', 20),
            'log_files' => [],
            'total_log_size' => 0,
        ];

        // Get Request Monitor stats
        if (class_exists('\\WKCacheManager\\RequestMonitor\\RequestLogger')) {
            $rm_logger = \WKCacheManager\RequestMonitor\RequestLogger::get_instance();
            $rm_stats = $this->get_request_monitor_stats($rm_logger);
            $stats['total_requests'] = $rm_stats['total'];
            $stats['hit_rate'] = $rm_stats['hit_rate'];
        }

        // Get URL Crawler stats
        if (class_exists('\\WKCacheManager\\UrlCrawler\\UrlCrawlerLogger')) {
            $uc_logger = \WKCacheManager\UrlCrawler\UrlCrawlerLogger::get_instance();
            $stats['urls_crawled'] = $this->get_url_crawler_stats($uc_logger);
        }

        // Get LiteSpeed Manager stats
        if (class_exists('\\WKCacheManager\\LiteSpeedManager\\LiteSpeedManager')) {
            $stats['purges_prevented'] = $this->get_litespeed_stats();
        }

        // Get Cache Monitor stats
        $stats['purge_events'] = $this->get_cache_monitor_stats();

        // Get log files
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . '*.log');
        if ($log_files) {
            foreach ($log_files as $log_file) {
                $size = filesize($log_file);
                $stats['log_files'][] = [
                    'name' => basename($log_file),
                    'size' => size_format($size),
                ];
                $stats['total_log_size'] += $size;
            }
        }

        return $stats;
    }

    /**
     * Get Request Monitor statistics
     */
    private function get_request_monitor_stats($logger)
    {
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'request-monitor-*.log');
        $total = 0;
        $hits = 0;

        foreach ($log_files as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $parts = explode('|', $line);
                    if (count($parts) >= 4) {
                        $total++;
                        $cf_status = trim($parts[2]);
                        $ls_status = trim($parts[3]);
                        if (stripos($cf_status, 'HIT') !== false || stripos($ls_status, 'hit') !== false) {
                            $hits++;
                        }
                    }
                }
            }
        }

        $hit_rate = $total > 0 ? round(($hits / $total) * 100, 1) : 0;

        return [
            'total' => $total,
            'hits' => $hits,
            'hit_rate' => $hit_rate
        ];
    }

    /**
     * Get URL Crawler statistics
     */
    private function get_url_crawler_stats($logger)
    {
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'url-crawler-*.log');
        $total = 0;

        foreach ($log_files as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $total += count($lines);
            }
        }

        return $total;
    }

    /**
     * Get LiteSpeed Manager statistics
     */
    private function get_litespeed_stats()
    {
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'litespeed-stock-*.log');
        $prevented = 0;

        foreach ($log_files as $file) {
            if (!file_exists($file)) continue;
            // Stream the file line-by-line — large logs will OOM file_get_contents
            $fh = @fopen($file, 'r');
            if (!$fh) continue;
            while (($line = fgets($fh)) !== false) {
                if (strpos($line, 'BLOCKED stock purge') !== false) {
                    $prevented++;
                }
            }
            fclose($fh);
        }

        return $prevented;
    }

    /**
     * Get Cache Monitor statistics
     */
    private function get_cache_monitor_stats()
    {
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'cache-monitor-*.log');
        $total = 0;

        foreach ($log_files as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $total += count($lines);
            }
        }

        return $total;
    }

    public function enqueue_assets($hook)
    {
        // Only load on our plugin pages
        if (strpos($hook, 'wk-cache-manager') === false) {
            return;
        }

        // Enqueue Chart.js from CDN
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_style(
            'wk-cache-manager-admin',
            WK_CACHE_MANAGER_PLUGIN_URL . 'assets/css/admin-styles.css',
            [],
            WK_CACHE_MANAGER_VERSION
        );

        wp_enqueue_script(
            'wk-cache-manager-admin',
            WK_CACHE_MANAGER_PLUGIN_URL . 'assets/js/admin-scripts.js',
            ['jquery', 'chartjs'],
            WK_CACHE_MANAGER_VERSION,
            true
        );
    }

    /**
     * Enqueue admin bar styles
     */
    public function enqueue_admin_bar_styles()
    {
        // Only load if admin bar widget is enabled
        if (!get_option('wk_cache_manager_admin_bar_enabled', false)) {
            return;
        }

        $custom_css = "
            #wpadminbar .wk-cache-manager-admin-bar .ab-icon.wk-icon {
                margin-right: 6px;
                top: 2px;
            }
            #wpadminbar .wk-cache-manager-admin-bar .ab-icon.wk-icon:before {
                font-family: dashicons !important;
                font-size: 18px !important;
                content: '\\f495' !important;
            }
            #wpadminbar .wk-admin-bar-stats {
                padding: 8px 14px;
                min-width: 260px;
            }
            #wpadminbar .wk-admin-bar-stats .wk-stat-item {
                display: flex;
                justify-content: space-between;
                padding: 6px 0;
                border-bottom: 1px solid #3c434a;
            }
            #wpadminbar .wk-admin-bar-stats .wk-stat-item:last-child {
                border-bottom: none;
            }
            #wpadminbar .wk-admin-bar-stats .wk-stat-label {
                color: #a7aaad;
            }
            #wpadminbar .wk-admin-bar-stats .wk-stat-value {
                color: #ffffff;
                margin-left: 10px;
            }
            #wpadminbar .wk-admin-bar-stats .wk-stat-updated {
                margin-top: 4px;
                padding-top: 8px;
                border-top: 1px solid #3c434a;
                opacity: 0.7;
            }
            #wpadminbar #wp-admin-bar-wk-cache-manager-stats .ab-item {
                cursor: default;
                height: auto;
            }
        ";

        wp_add_inline_style('admin-bar', $custom_css);
    }

    /**
     * Render Request Monitor Settings Page
     */
    public function render_request_monitor_combined()
    {
        $view = isset($_GET['view']) && $_GET['view'] === 'logs' ? 'logs' : 'settings';
        if ($view === 'logs') {
            $this->render_request_monitor_logs();
        } else {
            $this->render_request_monitor_settings();
        }
    }

    public function redirect_to_request_logs()
    {
        wp_safe_redirect(admin_url('admin.php?page=wk-cache-manager-request-monitor-settings&view=logs'));
        exit;
    }

    public function render_request_monitor_settings()
    {
        // Handle form submission
        if (isset($_POST['wk_request_monitor_settings_submit'])) {
            if (!isset($_POST['wk_request_monitor_nonce']) || !wp_verify_nonce($_POST['wk_request_monitor_nonce'], 'wk_request_monitor_settings')) {
                wp_die('Security check failed');
            }

            // Save settings
            update_option('wk_cache_manager_request_monitor_enabled', isset($_POST['enabled']) ? 1 : 0);
            update_option('wk_cache_manager_request_monitor_skip_logged_in', isset($_POST['skip_logged_in']) ? 1 : 0);
            update_option('wk_cache_manager_request_monitor_skip_admin', isset($_POST['skip_admin']) ? 1 : 0);
            update_option('wk_cache_manager_request_monitor_skip_ajax', isset($_POST['skip_ajax']) ? 1 : 0);
            update_option('wk_cache_manager_request_monitor_retention_days', intval($_POST['retention_days']));

            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'wk-cache-manager') . '</p></div>';
        }

        // Get current settings
        $enabled = get_option('wk_cache_manager_request_monitor_enabled', false);
        $skip_logged_in = get_option('wk_cache_manager_request_monitor_skip_logged_in', true);
        $skip_admin = get_option('wk_cache_manager_request_monitor_skip_admin', true);
        $skip_ajax = get_option('wk_cache_manager_request_monitor_skip_ajax', true);
        $retention_days = get_option('wk_cache_manager_request_monitor_retention_days', 30);

        $status = \WKCacheManager\RequestMonitor\RequestMonitor::get_status();

        self::render_shell_open('Request Monitor', 'Per-request cache HIT/MISS analytics.', 'request-monitor');
        self::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-request-monitor-settings'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-request-monitor-settings&view=logs'), 'label' => 'Logs'],
        ], 'settings');
        ?>
            <div class="wkcm-main wkcm-main--stack">
                <?php
                self::render_help(
                    __('What does Request Monitor do?', 'wk-cache-manager'),
                    '<p>' . esc_html__('Logs every page request with CloudFlare CF-Cache-Status + LiteSpeed X-LiteSpeed-Cache headers, so you can see HIT/MISS rates per URL.', 'wk-cache-manager') . '</p>'
                    . '<p><strong>' . esc_html__('Performance note:', 'wk-cache-manager') . '</strong> ' . esc_html__('runs on every public request. Enable for debugging sessions only — disable during normal operation.', 'wk-cache-manager') . '</p>'
                );
                ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head">
                        <span class="wkcm-card-num">01</span>
                        <h2><?php esc_html_e('Settings', 'wk-cache-manager'); ?></h2>
                    </div>
                    <div class="wkcm-card-body">

            <form method="post" action="">
                <?php wp_nonce_field('wk_request_monitor_settings', 'wk_request_monitor_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Request Monitoring</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($enabled, 1); ?>>
                                Enable monitoring (Use for debugging/analytics sessions)
                            </label>
                            <?php if ($enabled): ?>
                                <p class="description" style="color: #d63638;">
                                    <strong>Monitoring is currently ACTIVE</strong>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Monitoring Options</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="skip_logged_in" value="1" <?php checked($skip_logged_in, 1); ?>>
                                    Skip logged-in users (recommended)
                                </label><br>

                                <label>
                                    <input type="checkbox" name="skip_admin" value="1" <?php checked($skip_admin, 1); ?>>
                                    Skip admin pages (recommended)
                                </label><br>

                                <label>
                                    <input type="checkbox" name="skip_ajax" value="1" <?php checked($skip_ajax, 1); ?>>
                                    Skip AJAX requests (recommended)
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Log Retention</th>
                        <td>
                            <input type="number" name="retention_days" value="<?php echo esc_attr($retention_days); ?>" min="1" max="365" class="small-text">
                            days
                            <p class="description">Automatically delete logs older than this many days</p>
                        </td>
                    </tr>
                </table>

                <h2>Current Status</h2>
                <table class="wkcm-table">
                    <tbody>
                        <tr>
                            <td><strong>Monitoring Status:</strong></td>
                            <td>
                                <?php echo $status['enabled']
                                    ? '<span style="color: #d63638;">ACTIVE (Debug Mode)</span>'
                                    : '<span style="color: #999;">○ Disabled</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Total Log Files:</strong></td>
                            <td><?php echo esc_html($status['total_log_files']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Total Log Size:</strong></td>
                            <td><?php echo esc_html(size_format($status['total_log_size'])); ?></td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="submit" name="wk_request_monitor_settings_submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'wk-cache-manager'); ?>">
                </p>
            </form>
                    </div>
                </div>
            </div>
        <?php
        self::render_shell_close();
    }

    /**
     * Render Request Monitor Logs Page
     */
    public function render_request_monitor_logs()
    {
        // Handle clear logs action
        if (isset($_POST['clear_logs'])) {
            if (!isset($_POST['wk_request_monitor_logs_nonce']) || !wp_verify_nonce($_POST['wk_request_monitor_logs_nonce'], 'wk_request_monitor_clear_logs')) {
                wp_die('Security check failed');
            }

            $logger = \WKCacheManager\RequestMonitor\RequestLogger::get_instance();
            $logger->clear_all_logs();

            echo '<div class="notice notice-success"><p>' . __('All request monitor logs have been cleared.', 'wk-cache-manager') . '</p></div>';
        }

        // Get date range from query params
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : current_time('Y-m-d');
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : $end_date;

        // Get analytics
        $analytics = \WKCacheManager\RequestMonitor\RequestAnalytics::get_instance();
        $stats = $analytics->get_stats($start_date, $end_date);

        self::render_shell_open('Request Monitor', 'HIT/MISS analytics by date range.', 'request-monitor');
        self::render_subtabs([
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-request-monitor-settings'), 'label' => 'Settings'],
            'logs' => ['url' => admin_url('admin.php?page=wk-cache-manager-request-monitor-settings&view=logs'), 'label' => 'Logs'],
        ], 'logs');
        ?>
        <div class="wkcm-main wkcm-main--stack">
            <?php if (!get_option('wk_cache_manager_request_monitor_enabled', false)): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>Request Monitoring is currently disabled.</strong><br>
                        Enable it in <a href="<?php echo admin_url('admin.php?page=wk-cache-manager-request-monitor-settings'); ?>">Request Monitor Settings</a> to start collecting data.
                    </p>
                </div>
            <?php endif; ?>

            <?php
            // Log storage stats
            $rm_log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'request-monitor-*.log') ?: [];
            $rm_total_size = 0;
            foreach ($rm_log_files as $f) { if (file_exists($f)) $rm_total_size += filesize($f); }
            $rm_retention = (int) get_option('wk_cache_manager_request_monitor_log_retention_days', 30);
            ?>
            <div class="wkcm-card">
                <div class="wkcm-card-head"><span class="wkcm-card-num">00</span><h2><?php esc_html_e('Log Storage', 'wk-cache-manager'); ?></h2><p><?php printf(esc_html__('Daily-rotated files. Retention %d days.', 'wk-cache-manager'), $rm_retention); ?></p></div>
                <div class="wkcm-card-body" style="padding:0;">
                    <table class="wkcm-table"><tbody>
                        <tr><td><?php esc_html_e('Total log files', 'wk-cache-manager'); ?></td><td><?php echo esc_html(number_format(count($rm_log_files))); ?></td></tr>
                        <tr><td><?php esc_html_e('Total log size', 'wk-cache-manager'); ?></td><td><?php echo esc_html(size_format($rm_total_size)); ?></td></tr>
                        <tr><td><?php esc_html_e('Retention', 'wk-cache-manager'); ?></td><td><?php echo (int) $rm_retention; ?> days</td></tr>
                    </tbody></table>
                </div>
            </div>

            <div class="wkcm-card">
                <div class="wkcm-card-head"><span class="wkcm-card-num">01</span><h2><?php esc_html_e('Date Range', 'wk-cache-manager'); ?></h2><p><?php esc_html_e('Pick a window to analyze.', 'wk-cache-manager'); ?></p></div>
                <div class="wkcm-card-body">
                    <form method="get" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                        <input type="hidden" name="page" value="wk-cache-manager-request-monitor-logs">
                        <label>From: <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>"></label>
                        <label>To: <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>"></label>
                        <input type="submit" class="button button-primary" value="<?php esc_attr_e('View', 'wk-cache-manager'); ?>">
                        <?php
                        $show_raw = isset($_GET['raw']) && $_GET['raw'] == '1';
                        if ($show_raw) {
                            echo '<a href="' . esc_url(admin_url('admin.php?page=wk-cache-manager-request-monitor-logs&start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date))) . '" class="button">' . esc_html__('View Charts & Stats', 'wk-cache-manager') . '</a>';
                        } else {
                            echo '<a href="' . esc_url(admin_url('admin.php?page=wk-cache-manager-request-monitor-logs&start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date) . '&raw=1')) . '" class="button">' . esc_html__('View Raw Logs', 'wk-cache-manager') . '</a>';
                        }
                        ?>
                    </form>
                </div>
            </div>

            <?php if ($stats['total_requests'] > 0 && !$show_raw): ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head"><span class="wkcm-card-num">02</span><h2><?php esc_html_e('Cache Performance Over Time', 'wk-cache-manager'); ?></h2><p><?php esc_html_e('Hourly buckets for the selected range.', 'wk-cache-manager'); ?></p></div>
                    <div class="wkcm-card-body"><div class="wk-chart-canvas"><canvas id="requestMonitorChart"></canvas></div></div>
                </div>

                <div class="wkcm-card">
                    <div class="wkcm-card-head"><span class="wkcm-card-num">03</span><h2><?php esc_html_e('Statistics', 'wk-cache-manager'); ?></h2></div>
                    <div class="wkcm-card-body">
                    <table class="wkcm-table">
                        <tbody>
                            <tr>
                                <td colspan="2"><strong>Total Requests: <?php echo number_format($stats['total_requests']); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>CloudFlare Cache</h3>
                    <table class="wkcm-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['cf_summary'] as $status => $data): ?>
                                <tr>
                                    <td>
                                        <?php
                            $klass = strtoupper($status) === 'HIT' ? 'is-success' : (strtoupper($status) === 'MISS' ? 'is-warning' : 'is-muted');
                                echo '<span class="wkcm-badge ' . esc_attr($klass) . '">' . esc_html($status) . '</span>';
                                ?>
                                    </td>
                                    <td><?php echo number_format($data['count']); ?></td>
                                    <td><?php echo esc_html($data['percentage']); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3>LiteSpeed Cache</h3>
                    <table class="wkcm-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['ls_summary'] as $status => $data): ?>
                                <tr>
                                    <td>
                                        <?php
                                $klass = strtolower($status) === 'hit' ? 'is-success' : (strtolower($status) === 'miss' ? 'is-warning' : 'is-muted');
                                echo '<span class="wkcm-badge ' . esc_attr($klass) . '">' . esc_html($status) . '</span>';
                                ?>
                                    </td>
                                    <td><?php echo number_format($data['count']); ?></td>
                                    <td><?php echo esc_html($data['percentage']); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                </div><!-- close stats card -->

                <?php if (!empty($stats['top_cached_urls'])): ?>
                    <div class="wkcm-card">
                        <div class="wkcm-card-head"><span class="wkcm-card-num">04</span><h2><?php esc_html_e('Top Cached Pages', 'wk-cache-manager'); ?></h2><p>HIT rate &gt; 50%.</p></div>
                        <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th>HIT Rate</th>
                                    <th>Requests</th>
                                    <th>CF HIT/MISS</th>
                                    <th>LS HIT/MISS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['top_cached_urls'] as $url_data): ?>
                                    <?php if ($url_data['hit_rate'] >= 50): ?>
                                        <tr>
                                            <td><code><?php echo esc_html($url_data['url']); ?></code></td>
                                            <td><strong><?php echo esc_html($url_data['hit_rate']); ?>%</strong></td>
                                            <td><?php echo number_format($url_data['total_requests']); ?></td>
                                            <td><?php echo esc_html($url_data['cf_hit'] . '/' . $url_data['cf_miss']); ?></td>
                                            <td><?php echo esc_html($url_data['ls_hit'] . '/' . $url_data['ls_miss']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($stats['top_miss_urls'])): ?>
                    <div class="wkcm-card">
                        <div class="wkcm-card-head"><span class="wkcm-card-num">05</span><h2><?php esc_html_e('Pages Needing Attention', 'wk-cache-manager'); ?></h2><p>HIT rate &lt; 50%.</p></div>
                        <div class="wkcm-card-body" style="padding:0;">
                        <table class="wkcm-table">
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th>HIT Rate</th>
                                    <th>Requests</th>
                                    <th>CF HIT/MISS</th>
                                    <th>LS HIT/MISS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['top_miss_urls'] as $url_data): ?>
                                    <?php if ($url_data['hit_rate'] < 50): ?>
                                        <tr>
                                            <td><code><?php echo esc_html($url_data['url']); ?></code></td>
                                            <td style="color: #d63638;"><strong><?php echo esc_html($url_data['hit_rate']); ?>%</strong></td>
                                            <td><?php echo number_format($url_data['total_requests']); ?></td>
                                            <td><?php echo esc_html($url_data['cf_hit'] . '/' . $url_data['cf_miss']); ?></td>
                                            <td><?php echo esc_html($url_data['ls_hit'] . '/' . $url_data['ls_miss']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($show_raw): ?>
                <div class="wkcm-card">
                    <div class="wkcm-card-head"><span class="wkcm-card-num">02</span><h2><?php esc_html_e('Raw Log Data', 'wk-cache-manager'); ?></h2><p><?php printf(esc_html__('%s → %s — full content. Format: %s', 'wk-cache-manager'), esc_html($start_date), esc_html($end_date), '<code>[TS]|URL|CF_STATUS|LS_STATUS</code>'); ?></p></div>
                    <div class="wkcm-card-body">
                        <?php
                        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'request-monitor-*.log');
                        sort($log_files);
                        $raw_content = '';
                        $files_shown = 0;
                        foreach ($log_files as $file) {
                            if (preg_match('/request-monitor-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                                $file_date = $matches[1];
                                if ($file_date >= $start_date && $file_date <= $end_date) {
                                    // Single-day range: no separator. Multi-day: show file header.
                                    if ($start_date !== $end_date) {
                                        $raw_content .= "=== " . basename($file) . " ===\n";
                                    }
                                    $raw_content .= file_get_contents($file) . "\n";
                                    $files_shown++;
                                }
                            }
                        }
                        if ($raw_content === '') {
                            echo '<div class="wkcm-empty"><span class="dashicons dashicons-media-text"></span>' . esc_html__('No log entries for this range.', 'wk-cache-manager') . '</div>';
                        } else {
                            echo '<pre class="wkcm-log-tail" style="max-height:600px;">' . esc_html($raw_content) . '</pre>';
                        }
                        ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="wkcm-inline-notice"><strong><?php esc_html_e('No data for the selected range.', 'wk-cache-manager'); ?></strong> <?php esc_html_e('Verify Request Monitor is enabled and try a different range.', 'wk-cache-manager'); ?></div>
            <?php endif; ?>

            <!-- Clear Logs -->
            <div class="wk-dashboard-card">
                <h2>Manage Logs</h2>
                <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear all request monitor logs? This action cannot be undone.');">
                    <?php wp_nonce_field('wk_request_monitor_clear_logs', 'wk_request_monitor_logs_nonce'); ?>
                    <input type="submit" name="clear_logs" class="button button-secondary" value="<?php esc_attr_e('Clear All Logs', 'wk-cache-manager'); ?>">
                </form>
            </div>

            <?php if ($stats['total_requests'] > 0): ?>
                <!-- Chart Data Script -->
                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        var chartData = <?php echo json_encode($this->get_request_monitor_chart_data($start_date, $end_date)); ?>;

                        if (chartData && chartData.labels && chartData.labels.length > 0) {
                            var ctx = document.getElementById('requestMonitorChart').getContext('2d');
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: chartData.labels,
                                    datasets: [
                                        {
                                            label: 'CloudFlare HIT',
                                            data: chartData.cf_hit,
                                            borderColor: '#10b981',
                                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                            borderWidth: 3,
                                            tension: 0.4,
                                            fill: true
                                        },
                                        {
                                            label: 'CloudFlare MISS',
                                            data: chartData.cf_miss,
                                            borderColor: '#ef4444',
                                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                            borderWidth: 3,
                                            tension: 0.4,
                                            fill: true
                                        },
                                        {
                                            label: 'LiteSpeed HIT',
                                            data: chartData.ls_hit,
                                            borderColor: '#3b82f6',
                                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                            borderWidth: 2,
                                            tension: 0.4,
                                            borderDash: [5, 5]
                                        },
                                        {
                                            label: 'LiteSpeed MISS',
                                            data: chartData.ls_miss,
                                            borderColor: '#f59e0b',
                                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
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
                                                text: 'Number of Requests',
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
        </div>
        <?php
        self::render_shell_close();
    }

    /**
     * Get chart data for Request Monitor
     */
    private function get_request_monitor_chart_data($start_date, $end_date)
    {
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'request-monitor-*.log');
        $hourly_data = [];

        foreach ($log_files as $file) {
            if (file_exists($file)) {
                // Extract date from filename
                if (preg_match('/request-monitor-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                    $file_date = $matches[1];
                    if ($file_date >= $start_date && $file_date <= $end_date) {
                        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        foreach ($lines as $line) {
                            $parts = explode('|', $line);
                            if (count($parts) >= 4) {
                                $timestamp = trim($parts[0]);
                                $cf_status = trim($parts[2]);
                                $ls_status = trim($parts[3]);

                                // Group by hour
                                $hour_key = date('Y-m-d H:00', strtotime($timestamp));

                                if (!isset($hourly_data[$hour_key])) {
                                    $hourly_data[$hour_key] = [
                                        'cf_hit' => 0,
                                        'cf_miss' => 0,
                                        'ls_hit' => 0,
                                        'ls_miss' => 0
                                    ];
                                }

                                if (stripos($cf_status, 'HIT') !== false) {
                                    $hourly_data[$hour_key]['cf_hit']++;
                                } elseif (stripos($cf_status, 'MISS') !== false) {
                                    $hourly_data[$hour_key]['cf_miss']++;
                                }

                                if (stripos($ls_status, 'hit') !== false) {
                                    $hourly_data[$hour_key]['ls_hit']++;
                                } elseif (stripos($ls_status, 'miss') !== false) {
                                    $hourly_data[$hour_key]['ls_miss']++;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Sort by time
        ksort($hourly_data);

        // Prepare chart data
        $labels = [];
        $cf_hit = [];
        $cf_miss = [];
        $ls_hit = [];
        $ls_miss = [];

        foreach ($hourly_data as $hour => $data) {
            $labels[] = date('M d, H:i', strtotime($hour));
            $cf_hit[] = $data['cf_hit'];
            $cf_miss[] = $data['cf_miss'];
            $ls_hit[] = $data['ls_hit'];
            $ls_miss[] = $data['ls_miss'];
        }

        return [
            'labels' => $labels,
            'cf_hit' => $cf_hit,
            'cf_miss' => $cf_miss,
            'ls_hit' => $ls_hit,
            'ls_miss' => $ls_miss
        ];
    }

    /**
     * Register general settings
     */
    public function register_settings()
    {
        register_setting(
            'wk_cache_manager_general_settings',
            'wk_cache_manager_admin_bar_enabled',
            [
                'type' => 'boolean',
                'default' => false
            ]
        );

        register_setting(
            'wk_cache_manager_general_settings',
            'wk_cache_manager_admin_bar_refresh_interval',
            [
                'type' => 'string',
                'default' => '1_hour',
                'sanitize_callback' => [$this, 'sanitize_refresh_interval']
            ]
        );
    }

    /**
     * Sanitize refresh interval
     */
    public function sanitize_refresh_interval($value)
    {
        $allowed = ['1_hour', '2_hours', '4_hours', '8_hours', '24_hours', 'weekly'];
        return in_array($value, $allowed) ? $value : '1_hour';
    }

    /**
     * Render general settings page
     */
    public function render_general_settings()
    {
        if (isset($_POST['wk_cache_manager_save_general_settings']) && check_admin_referer('wk_cache_manager_general_settings')) {
            $enabled = isset($_POST['admin_bar_enabled']) ? true : false;
            $refresh_interval = sanitize_text_field($_POST['admin_bar_refresh_interval']);

            update_option('wk_cache_manager_admin_bar_enabled', $enabled);
            update_option('wk_cache_manager_admin_bar_refresh_interval', $refresh_interval);

            // Clear transient when settings change
            delete_transient('wk_cache_manager_admin_bar_stats');

            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wk-cache-manager') . '</p></div>';
        }

        $enabled = get_option('wk_cache_manager_admin_bar_enabled', false);
        $refresh_interval = get_option('wk_cache_manager_admin_bar_refresh_interval', '1_hour');

        self::render_shell_open('Settings', 'Admin bar widget + cron configuration.', 'settings');
        ?>
        <div class="wkcm-main wkcm-main--stack">
            <?php $this->render_cpanel_cron_card(); ?>

            <div class="wkcm-card">
                <div class="wkcm-card-head">
                    <span class="wkcm-card-num">02</span>
                    <h2><?php _e('Admin Bar Widget', 'wk-cache-manager'); ?></h2>
                </div>
                <div class="wkcm-card-body">

            <form method="post" action="">
                <?php wp_nonce_field('wk_cache_manager_general_settings'); ?>

                <h2><?php _e('Admin Bar Widget', 'wk-cache-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="admin_bar_enabled"><?php _e('Enable Admin Bar Widget', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="admin_bar_enabled"
                                       name="admin_bar_enabled"
                                       value="1"
                                       <?php checked($enabled, true); ?> />
                                <?php _e('Show WK Cache Manager stats in admin bar', 'wk-cache-manager'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Displays cache statistics in the WordPress admin bar with hover dropdown.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="admin_bar_refresh_interval"><?php _e('Stats Refresh Interval', 'wk-cache-manager'); ?></label>
                        </th>
                        <td>
                            <select id="admin_bar_refresh_interval" name="admin_bar_refresh_interval">
                                <option value="1_hour" <?php selected($refresh_interval, '1_hour'); ?>><?php _e('1 Hour', 'wk-cache-manager'); ?></option>
                                <option value="2_hours" <?php selected($refresh_interval, '2_hours'); ?>><?php _e('2 Hours', 'wk-cache-manager'); ?></option>
                                <option value="4_hours" <?php selected($refresh_interval, '4_hours'); ?>><?php _e('4 Hours', 'wk-cache-manager'); ?></option>
                                <option value="8_hours" <?php selected($refresh_interval, '8_hours'); ?>><?php _e('8 Hours', 'wk-cache-manager'); ?></option>
                                <option value="24_hours" <?php selected($refresh_interval, '24_hours'); ?>><?php _e('24 Hours', 'wk-cache-manager'); ?></option>
                                <option value="weekly" <?php selected($refresh_interval, 'weekly'); ?>><?php _e('Weekly', 'wk-cache-manager'); ?></option>
                            </select>
                            <p class="description">
                                <?php _e('How often to update the cached statistics. Longer intervals reduce server load.', 'wk-cache-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit"
                           name="wk_cache_manager_save_general_settings"
                           class="button button-primary"
                           value="<?php esc_attr_e('Save Settings', 'wk-cache-manager'); ?>" />
                </p>
            </form>
                </div>
            </div>
        </div>
        <?php
        self::render_shell_close();
    }

    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu($wp_admin_bar)
    {
        // Check if admin bar widget is enabled
        if (!get_option('wk_cache_manager_admin_bar_enabled', false)) {
            return;
        }

        // Get cached stats
        $stats = $this->get_admin_bar_stats();

        // Add parent menu
        $wp_admin_bar->add_node([
            'id' => 'wk-cache-manager',
            'title' => '<span class="ab-icon wk-icon dashicons-before dashicons-performance" aria-hidden="true"></span><span class="ab-label">Cache Manager</span>',
            'href' => admin_url('admin.php?page=wk-cache-manager'),
            'meta' => [
                'class' => 'wk-cache-manager-admin-bar',
            ]
        ]);

        // Add stats submenu
        $wp_admin_bar->add_node([
            'id' => 'wk-cache-manager-stats',
            'parent' => 'wk-cache-manager',
            'title' => sprintf(
                '<div class="wk-admin-bar-stats">
                    <div class="wk-stat-item">
                        <span class="wk-stat-label">Cache Created</span>
                        <span class="wk-stat-value">%s</span>
                    </div>
                    <div class="wk-stat-item">
                        <span class="wk-stat-label">Purges Prevented</span>
                        <span class="wk-stat-value">%s</span>
                    </div>
                    <div class="wk-stat-item">
                        <span class="wk-stat-label">Purges Today</span>
                        <span class="wk-stat-value">%s</span>
                    </div>
                    <div class="wk-stat-item wk-stat-updated">
                        <span class="wk-stat-label">Updated</span>
                        <span class="wk-stat-value">%s ago</span>
                    </div>
                </div>',
                number_format($stats['urls_crawled']),
                number_format($stats['purges_prevented']),
                number_format($stats['purge_events_today']),
                human_time_diff($stats['updated_time'], current_time('timestamp'))
            ),
            'href' => false,
        ]);

        // Add quick links
        $wp_admin_bar->add_node([
            'id' => 'wk-cache-manager-dashboard',
            'parent' => 'wk-cache-manager',
            'title' => 'Dashboard',
            'href' => admin_url('admin.php?page=wk-cache-manager'),
        ]);

        $wp_admin_bar->add_node([
            'id' => 'wk-cache-manager-settings',
            'parent' => 'wk-cache-manager',
            'title' => 'Settings',
            'href' => admin_url('admin.php?page=wk-cache-manager-settings'),
        ]);
    }

    /**
     * Get admin bar stats (cached with transient)
     */
    private function get_admin_bar_stats()
    {
        // Try to get cached stats
        $stats = get_transient('wk_cache_manager_admin_bar_stats');

        if ($stats !== false) {
            return $stats;
        }

        // Calculate fresh stats
        $stats = [
            'urls_crawled' => 0,
            'purges_prevented' => 0,
            'purge_events_today' => 0,
            'updated_time' => current_time('timestamp')
        ];

        // Get URL Crawler stats (total). Stream files line-by-line so big logs
        // don't OOM PHP memory_limit.
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'url-crawler-*.log');
        foreach ($log_files as $file) {
            if (!file_exists($file)) continue;
            $fh = @fopen($file, 'r');
            if (!$fh) continue;
            while (fgets($fh) !== false) {
                $stats['urls_crawled']++;
            }
            fclose($fh);
        }

        // Get Purges Prevented stats (total). Match the exact string the
        // LiteSpeedManager logger writes: "BLOCKED stock purge".
        $log_files = glob(WK_CACHE_MANAGER_LOG_DIR . 'litespeed-stock-*.log');
        foreach ($log_files as $file) {
            if (!file_exists($file)) continue;
            $fh = @fopen($file, 'r');
            if (!$fh) continue;
            while (($line = fgets($fh)) !== false) {
                if (strpos($line, 'BLOCKED stock purge') !== false) {
                    $stats['purges_prevented']++;
                }
            }
            fclose($fh);
        }

        // Get Cache Purge Events Today (streamed)
        $today_date = current_time('Y-m-d');
        $today_log = WK_CACHE_MANAGER_LOG_DIR . 'cache-monitor-' . $today_date . '.log';
        if (file_exists($today_log)) {
            $fh = @fopen($today_log, 'r');
            if ($fh) {
                while (fgets($fh) !== false) {
                    $stats['purge_events_today']++;
                }
                fclose($fh);
            }
        }

        // Cache the stats based on refresh interval
        $refresh_interval = get_option('wk_cache_manager_admin_bar_refresh_interval', '1_hour');
        $expiration = $this->get_transient_expiration($refresh_interval);

        set_transient('wk_cache_manager_admin_bar_stats', $stats, $expiration);

        return $stats;
    }

    /**
     * Get transient expiration time in seconds
     */
    private function get_transient_expiration($interval)
    {
        $expirations = [
            '1_hour' => HOUR_IN_SECONDS,
            '2_hours' => 2 * HOUR_IN_SECONDS,
            '4_hours' => 4 * HOUR_IN_SECONDS,
            '8_hours' => 8 * HOUR_IN_SECONDS,
            '24_hours' => DAY_IN_SECONDS,
            'weekly' => WEEK_IN_SECONDS,
        ];

        return isset($expirations[$interval]) ? $expirations[$interval] : HOUR_IN_SECONDS;
    }

    /**
     * Open the standard plugin shell w/ masthead + tabs.
     * Sub-pages use this to share layout w/ Dashboard.
     *
     * @param string $title    Big display title.
     * @param string $subtitle Optional one-liner under masthead title.
     * @param string $tab      Active tab slug (overview|url-crawler|purge-prevention|cache-monitor|request-monitor|settings).
     */
    public static function render_shell_open($title = '', $subtitle = '', $tab = 'overview')
    {
        $tabs = [
            'overview' => ['url' => admin_url('admin.php?page=wk-cache-manager'), 'label' => 'Overview', 'num' => '01'],
            'url-crawler' => ['url' => admin_url('admin.php?page=wk-cache-manager-url-crawler'), 'label' => 'URL Crawler', 'num' => '02'],
            'purge-prevention' => ['url' => admin_url('admin.php?page=wk-cache-manager-purge-prevention'), 'label' => 'Purge Prevention', 'num' => '03'],
            'cache-monitor' => ['url' => admin_url('admin.php?page=wk-cache-manager-cache-monitor-settings'), 'label' => 'Cache Purging Monitor', 'num' => '04'],
            'request-monitor' => ['url' => admin_url('admin.php?page=wk-cache-manager-request-monitor-settings'), 'label' => 'Request Monitor', 'num' => '05'],
            'settings' => ['url' => admin_url('admin.php?page=wk-cache-manager-settings'), 'label' => 'Settings', 'num' => '06'],
        ];
        ?>
        <div class="wrap wkcm-shell">
            <div class="wkcm-masthead">
                <div class="wkcm-mast-left">
                    <span class="wkcm-eyebrow">WK / Cache Manager</span>
                    <div class="wkcm-mast-title-row">
                        <h1 class="wkcm-display"><?php echo esc_html($title); ?></h1>
                        <?php if ($subtitle): ?><p class="wkcm-mast-subtitle"><?php echo esc_html($subtitle); ?></p><?php endif; ?>
                    </div>
                </div>
            </div>
            <nav class="wkcm-tabs">
                <?php foreach ($tabs as $slug => $t): ?>
                    <a class="wkcm-tab<?php echo $slug === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url($t['url']); ?>"><span class="wkcm-tab-num"><?php echo esc_html($t['num']); ?></span> <?php echo esc_html($t['label']); ?></a>
                <?php endforeach; ?>
            </nav>
        <?php
    }

    public static function render_shell_close()
    {
        echo '</div>';
    }

    /**
     * Render inner sub-tabs (e.g. Settings | Logs within URL Crawler page).
     *
     * @param array  $tabs   [slug => ['url' => '', 'label' => '']]
     * @param string $active Active slug.
     */
    /**
     * Render collapsible help block.
     *   <details class="wkcm-help"><summary>...</summary><body>...</body></details>
     */
    public static function render_help($summary, $body, $open = false)
    {
        $attr = $open ? ' open' : '';
        echo '<details class="wkcm-help"' . $attr . '>';
        echo '<summary><span class="dashicons dashicons-info-outline"></span>' . esc_html($summary) . '</summary>';
        echo '<div class="wkcm-help-body">' . $body . '</div>';
        echo '</details>';
    }

    public static function render_subtabs($tabs, $active)
    {
        echo '<nav class="wkcm-subtabs">';
        foreach ($tabs as $slug => $t) {
            $cls = $slug === $active ? ' is-active' : '';
            echo '<a class="wkcm-subtab' . $cls . '" href="' . esc_url($t['url']) . '">' . esc_html($t['label']) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * Render cPanel cron setup card on General Settings.
     */
    private function render_cpanel_cron_card()
    {
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        wp_cache_delete('wk_cache_manager_last_cron_tick', 'options');
        $last_tick = (int) get_option('wk_cache_manager_last_cron_tick', 0);
        // Fallback to marker file if the option lookup returns 0 (object cache
        // miss or option not yet autoloaded into this request).
        $marker = WK_CACHE_MANAGER_LOG_DIR . 'cron-tick.txt';
        if (!$last_tick && file_exists($marker)) {
            $last_tick = (int) @file_get_contents($marker);
        }
        $tick_age = $last_tick ? human_time_diff($last_tick, time()) . ' ago' : 'no tick recorded';
        $abspath = defined('ABSPATH') ? ABSPATH : '/path/to/wordpress/';
        $cron_path = $abspath . 'wp-cron.php';
        $site_url = home_url('/wp-cron.php');
        ?>
        <div class="wkcm-card">
            <div class="wkcm-card-head">
                <span class="wkcm-card-num">01</span>
                <h2><?php esc_html_e('Cron Configuration', 'wk-cache-manager'); ?></h2>
                <p><?php esc_html_e('cPanel cron is recommended for reliable scheduled jobs.', 'wk-cache-manager'); ?></p>
            </div>
            <div class="wkcm-card-body">
            <p>
                <strong>Mode:</strong>
                <?php if ($disabled): ?>
                    <span class="status-badge status-success">cPanel cron (DISABLE_WP_CRON = true)</span>
                <?php else: ?>
                    <span class="status-badge status-warning">WP-Cron (visitor-triggered, unreliable on low-traffic sites)</span>
                <?php endif; ?>
            </p>
            <p><strong>Last cron tick:</strong> <?php echo esc_html($tick_age); ?></p>

            <?php if (!$disabled): ?>
                <h3>Recommended: Switch to cPanel cron</h3>
                <p>For reliable scheduled tasks (log cleanup, queued crawls, fallback for failed loopback), use server cron instead of WP-Cron.</p>
                <ol>
                    <li><strong>Disable WP-Cron</strong> — add to <code>wp-config.php</code> above the "stop editing" line:
                        <pre style="background:#f0f0f0;padding:0.5em;">define('DISABLE_WP_CRON', true);</pre>
                    </li>
                    <li><strong>Add cPanel cron job</strong> — in cPanel → Cron Jobs, set interval to <code>* * * * *</code> (every minute) and command:
                        <pre style="background:#f0f0f0;padding:0.5em;">/usr/local/bin/php <?php echo esc_html($cron_path); ?> &gt;/dev/null 2&gt;&amp;1</pre>
                        <em>Or HTTP variant:</em>
                        <pre style="background:#f0f0f0;padding:0.5em;">wget -q -O - <?php echo esc_html($site_url); ?>?doing_wp_cron &gt;/dev/null 2&gt;&amp;1</pre>
                    </li>
                </ol>
            <?php else: ?>
                <p>cPanel cron mode active. Plugin uses immediate loopback HTTP for "instant" crawls; cron handles batched/scheduled jobs.</p>
                <p><strong>Verify cron is firing:</strong> last tick should be &lt; 2 minutes ago. If not, cPanel cron job is misconfigured.</p>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Run health checks for Dashboard widget
     */
    private function run_health_checks()
    {
        $checks = [];

        // 1. Log dir writable
        $log_dir = WK_CACHE_MANAGER_LOG_DIR;
        $writable = is_dir($log_dir) && wp_is_writable($log_dir);
        $checks[] = [
            'label' => 'Log Directory Writable',
            'ok' => $writable,
            'msg' => $writable ? 'Writable' : 'NOT writable — check perms',
        ];

        // 2. .htaccess present
        $htaccess_ok = file_exists($log_dir . '.htaccess');
        $checks[] = [
            'label' => 'Logs Protected (.htaccess)',
            'ok' => $htaccess_ok,
            'msg' => $htaccess_ok ? 'Present' : 'Missing — logs publicly readable on Apache',
        ];

        // 3. Cron status — DISABLE_WP_CRON=true means cPanel cron is authoritative
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        wp_cache_delete('wk_cache_manager_last_cron_tick', 'options');
        $last_cron_run = (int) get_option('wk_cache_manager_last_cron_tick', 0);
        $marker = WK_CACHE_MANAGER_LOG_DIR . 'cron-tick.txt';
        if (!$last_cron_run && file_exists($marker)) {
            $last_cron_run = (int) @file_get_contents($marker);
        }
        $cron_age = $last_cron_run ? (time() - $last_cron_run) : null;
        if ($cron_disabled) {
            $ok = $cron_age !== null && $cron_age < 5 * MINUTE_IN_SECONDS;
            $msg = $last_cron_run
                ? 'cPanel cron mode · last tick ' . human_time_diff($last_cron_run, time()) . ' ago'
                : 'cPanel cron mode · no tick recorded yet (waiting up to 1 min)';
        } else {
            $ok = true;
            $msg = 'WP-Cron mode (visitor-triggered)';
        }
        $checks[] = ['label' => 'Cron', 'ok' => $ok, 'msg' => $msg];

        // 4. LiteSpeed plugin
        $ls_active = defined('LSCWP_V') || class_exists('\LiteSpeed\Core');
        $checks[] = [
            'label' => 'LiteSpeed Cache',
            'ok' => $ls_active,
            'msg' => $ls_active ? 'Active' : 'Not detected',
        ];

        // 5. CloudFlare plugin
        $cf_active = class_exists('SW_CLOUDFLARE_PAGECACHE');
        $checks[] = [
            'label' => 'CloudFlare Page Cache',
            'ok' => $cf_active,
            'msg' => $cf_active ? 'Active' : 'Not detected',
        ];

        // 6. WooCommerce
        $wc_active = class_exists('WooCommerce');
        $checks[] = [
            'label' => 'WooCommerce',
            'ok' => $wc_active,
            'msg' => $wc_active ? 'Active' : 'Not detected (Purge Prevention requires WC)',
        ];

        // 7. Last URL crawl recency
        $latest = 0;
        $logfile = $log_dir . 'url-crawler-' . current_time('Y-m-d') . '.log';
        if (file_exists($logfile)) {
            $latest = filemtime($logfile);
        }
        $age = $latest ? (time() - $latest) : null;
        $checks[] = [
            'label' => 'Last URL Crawl',
            'ok' => $age !== null && $age < DAY_IN_SECONDS,
            'msg' => $age === null ? 'No crawl today' : human_time_diff($latest, time()) . ' ago',
        ];

        // 8. Loopback HTTP reachable (admin-ajax). Cached 1h to avoid TTFB hit
        // on every dashboard render after cache flush.
        $loopback_ok = get_transient('wk_cache_manager_loopback_health');
        if ($loopback_ok === false) {
            $resp = wp_remote_post(admin_url('admin-ajax.php'), [
                'timeout' => 3,
                'blocking' => true,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'body' => ['action' => 'wkcm_loopback_health'],
            ]);
            $loopback_ok = !is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200 ? '1' : '0';
            set_transient('wk_cache_manager_loopback_health', $loopback_ok, HOUR_IN_SECONDS);
        }
        $checks[] = [
            'label' => 'Loopback HTTP (async crawl)',
            'ok' => $loopback_ok === '1',
            'msg' => $loopback_ok === '1' ? 'Reachable' : 'admin-ajax.php not reachable from server',
        ];

        return $checks;
    }
}
