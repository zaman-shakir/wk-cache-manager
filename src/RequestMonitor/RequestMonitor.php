<?php

namespace WKCacheManager\RequestMonitor;

class RequestMonitor
{
    private static $instance = null;
    private $logger;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->logger = RequestLogger::get_instance();

        $this->init();
    }

    /**
     * Initialize hooks
     */
    private function init()
    {
        // Hook to shutdown to capture response headers after cache layers set them.
        // Conditional tags (is_single, is_archive, etc.) are not ready until 'wp' action,
        // so init/parse_request are too early. shutdown is the only safe spot.
        add_action('shutdown', [$this, 'monitor_request'], 999);


        // Schedule daily cleanup
        if (!wp_next_scheduled('wk_cache_manager_request_monitor_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wk_cache_manager_request_monitor_cleanup');
        }
        add_action('wk_cache_manager_request_monitor_cleanup', [$this->logger, 'cleanup_old_logs']);
    }

    /**
     * Monitor the current request and log cache status
     *
     * IMPORTANT: This reads headers from the CURRENT incoming request.
     * - Captures CloudFlare and LiteSpeed cache headers from the actual request
     * - Shows cache status (HIT/MISS/BYPASS/DYNAMIC) as seen by the visitor
     * - Works with both CloudFlare CDN and LiteSpeed cache
     */
    public function monitor_request()
    {
        static $settings = null;
        if ($settings === null) {
            $settings = [
                'enabled' => (bool) get_option('wk_cache_manager_request_monitor_enabled', false),
                'skip_admin' => (bool) get_option('wk_cache_manager_request_monitor_skip_admin', true),
                'skip_ajax' => (bool) get_option('wk_cache_manager_request_monitor_skip_ajax', true),
                'skip_logged_in' => (bool) get_option('wk_cache_manager_request_monitor_skip_logged_in', true),
            ];
        }

        if (!$settings['enabled']) {
            return;
        }
        if ($settings['skip_admin'] && is_admin()) {
            return;
        }
        if ($settings['skip_ajax'] && wp_doing_ajax()) {
            return;
        }
        if ($settings['skip_logged_in'] && is_user_logged_in()) {
            return;
        }

        // Get current URL
        $url = $this->get_current_url();

        // Only monitor if this is a page URL (not static files)
        if (!$this->is_page_url()) {
            return;
        }

        // Get cache status from CURRENT REQUEST headers (not external request)
        $cf_status = $this->get_cloudflare_status_from_request();
        $ls_status = $this->get_litespeed_status_from_request();

        // Log the request
        $this->logger->log_request($url, $cf_status, $ls_status);
    }

    /**
     * Get the current request URL
     *
     * @return string Current URL
     */
    private function get_current_url()
    {
        global $wp;

        if (isset($wp->request)) {
            return home_url($wp->request);
        }

        // Fallback
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return $protocol . '://' . $host . $uri;
    }

    /**
     * Check if the current request is for a WordPress page (not a static file)
     * Uses WordPress conditional tags to determine if this is actual page content
     *
     * @return bool True if this is a page URL, false if it's a static file
     */
    private function is_page_url()
    {
        // Check if this is any type of WordPress page/content
        // These functions return true for actual pages, posts, archives, etc.
        if (
            is_front_page() ||           // Homepage
            is_home() ||                 // Blog page
            is_single() ||               // Single post/product
            is_page() ||                 // Static page
            is_archive() ||              // Archive pages (category, tag, date, etc.)
            is_category() ||             // Category archive
            is_tag() ||                  // Tag archive
            is_tax() ||                  // Custom taxonomy archive
            is_author() ||               // Author archive
            is_date() ||                 // Date archive
            is_search() ||               // Search results
            is_404() ||                  // 404 page
            is_attachment() ||           // Attachment page
            is_singular() ||             // Any single content (post, page, custom post type)
            is_post_type_archive()       // Custom post type archive
        ) {
            return true;
        }

        // If none of the above, it's likely a static file or invalid URL
        return false;
    }

    /**
     * Get final response headers using PHP's native get_headers()
     * This includes all headers added by CloudFlare, LiteSpeed, and web server
     *
     * IMPORTANT: This makes an EXTERNAL HTTP request to your site.
     * - Uses PHP's native get_headers() which is more reliable than wp_remote_head()
     * - Will capture REAL cache headers as seen by external visitors
     * - Works better with CloudFlare and LiteSpeed
     *
     * @param string $url The URL to check
     * @return array|false Array of headers (normalized to lowercase keys) or false on error
     */
    private function get_remote_headers($url)
    {
        static $cached_headers = null;
        static $cached_url = null;

        // Return cached headers if same URL
        if ($cached_url === $url && $cached_headers !== null) {
            return $cached_headers;
        }

        // Create stream context to control request behavior
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD', // Use HEAD request (faster, no body)
                'timeout' => 10,
                'user_agent' => 'WK-Cache-Manager/1.0 (WordPress Monitor)',
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true, // Don't fail on 4xx/5xx errors
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        // Suppress warnings and get headers
        $raw_headers = @get_headers($url, 1, $context);

        if ($raw_headers === false) {
            error_log(sprintf(
                '[WK Cache Manager] Failed to fetch headers for %s using get_headers()',
                $url
            ));
            return false;
        }

        // Normalize header keys to lowercase for consistent access
        $headers = [];
        foreach ($raw_headers as $key => $value) {
            // Skip numeric keys (HTTP status line)
            if (is_numeric($key)) {
                continue;
            }

            $normalized_key = strtolower($key);

            // Handle multiple values (arrays)
            if (is_array($value)) {
                // Take the last value (most recent in case of redirects)
                $headers[$normalized_key] = end($value);
            } else {
                $headers[$normalized_key] = $value;
            }
        }

        // Cache the result
        $cached_headers = $headers;
        $cached_url = $url;

        return $headers;
    }

    /**
     * Get CloudFlare cache status from server environment
     * CloudFlare adds the CF-Cache-Status header to responses
     *
     * NOTE: CF-Cache-Status is a RESPONSE header added by CloudFlare AFTER PHP execution.
     * It cannot be read from within PHP during the request.
     *
     * This method uses multiple approaches to try to capture it:
     * 1. Check if LiteSpeed/Apache passed it as environment variable
     * 2. Check response headers that have been queued
     * 3. Make external request to get actual cache status
     *
     * @return string Cache status (HIT, MISS, BYPASS, EXPIRED, etc.)
     */
    private function get_cloudflare_status_from_request()
    {
        // Method 1: Check if header is in response headers list (headers_list() shows queued headers)
        $headers_list = headers_list();
        foreach ($headers_list as $header) {
            if (stripos($header, 'CF-Cache-Status:') === 0) {
                $parts = explode(':', $header, 2);
                return strtoupper(trim($parts[1]));
            }
        }

        // Method 2: Check if LiteSpeed set it as environment variable (LSCache does this for its own header)
        if (isset($_ENV['CF_CACHE_STATUS'])) {
            return strtoupper(trim($_ENV['CF_CACHE_STATUS']));
        }

        // CF is active but cache status header not visible to PHP for HIT responses
        // (those bypass PHP entirely). For MISS the header is in headers_list above.
        // Do NOT make external HTTP request from frontend — adds blocking TTFB on
        // every public hit. Mark unknown instead.
        if (isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return 'CF_ACTIVE_HEADER_HIDDEN';
        }

        return 'N/A';
    }

    /**
     * Get LiteSpeed cache status from server environment
     * LiteSpeed adds the X-LiteSpeed-Cache header and also sets it as an environment variable
     *
     * @return string Cache status (hit, miss, etc.)
     */
    private function get_litespeed_status_from_request()
    {
        // Method 1: Check LiteSpeed environment variable (LSCache sets this)
        if (isset($_ENV['X-LiteSpeed-Cache'])) {
            return strtolower(trim($_ENV['X-LiteSpeed-Cache']));
        }

        // Method 2: Check if header is in response headers list
        $headers_list = headers_list();
        foreach ($headers_list as $header) {
            if (stripos($header, 'X-LiteSpeed-Cache:') === 0) {
                $parts = explode(':', $header, 2);
                return strtolower(trim($parts[1]));
            }
        }

        // Method 3: Check $_SERVER (some setups pass it here)
        if (isset($_SERVER['HTTP_X_LITESPEED_CACHE'])) {
            return strtolower(trim($_SERVER['HTTP_X_LITESPEED_CACHE']));
        }

        // LS active but cache status header not visible to PHP for HIT responses.
        // Skip the external HTTP request — too expensive for every public request.
        if (isset($_ENV['LSCACHE_VARY_COOKIE']) || isset($_ENV['X-LiteSpeed-Tag'])) {
            return 'ls_active_header_hidden';
        }

        return 'N/A';
    }

    /**
     * DEPRECATED: Get CloudFlare cache status from external request
     * Kept for backward compatibility but not used anymore
     *
     * @param string $url The URL to check
     * @return string Cache status (HIT, MISS, BYPASS, EXPIRED, etc.)
     */
    private function get_cloudflare_status($url)
    {
        // CloudFlare sends CF-Cache-Status header as response header
        // Possible values: HIT, MISS, EXPIRED, STALE, BYPASS, REVALIDATED, UPDATING, DYNAMIC

        $headers = $this->get_remote_headers($url);

        if (is_array($headers) && isset($headers['cf-cache-status'])) {
            return strtoupper(trim($headers['cf-cache-status']));
        }

        return 'N/A';
    }

    /**
     * DEPRECATED: Get LiteSpeed cache status from external request
     * Kept for backward compatibility but not used anymore
     *
     * @param string $url The URL to check
     * @return string Cache status (hit, miss, etc.)
     */
    private function get_litespeed_status($url)
    {
        // LiteSpeed sends X-LiteSpeed-Cache header as response header
        // Possible values: hit, miss, no-cache

        $headers = $this->get_remote_headers($url);

        if (is_array($headers) && isset($headers['x-litespeed-cache'])) {
            return strtolower(trim($headers['x-litespeed-cache']));
        }

        return 'N/A';
    }

    /**
     * Get current monitoring status
     *
     * @return array Status information
     */
    public static function get_status()
    {
        $logger = RequestLogger::get_instance();

        return [
            'enabled' => get_option('wk_cache_manager_request_monitor_enabled', false),
            'skip_logged_in' => get_option('wk_cache_manager_request_monitor_skip_logged_in', true),
            'skip_admin' => get_option('wk_cache_manager_request_monitor_skip_admin', true),
            'skip_ajax' => get_option('wk_cache_manager_request_monitor_skip_ajax', true),
            'retention_days' => get_option('wk_cache_manager_request_monitor_retention_days', 30),
            'total_log_files' => count($logger->get_all_log_files()),
            'total_log_size' => $logger->get_total_log_size(),
        ];
    }
}
