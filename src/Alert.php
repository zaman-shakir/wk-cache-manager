<?php

namespace WKCacheManager;

/**
 * Telegram Alert Integration
 *
 * This class provides integration with Telegram Error Notifier plugin.
 * It's imported in all classes so you can add custom notifications when needed.
 *
 * Usage in any class:
 *   $alert = \WKCacheManager\Alert::get_instance();
 *   $alert->notify('Your message here');
 */
class Alert
{
    private static $instance = null;
    private $telegram_alert = null;
    private static $initialized = false;

    private function __construct()
    {
        $this->init();
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init()
    {
        if (self::$initialized) {
            return;
        }

        // Check if Telegram Error Notifier plugin is available
        if (class_exists('Webkonsulenterne\TelegramErrorNotifier\Alert')) {
            $this->telegram_alert = new \Webkonsulenterne\TelegramErrorNotifier\Alert();
        }

        self::$initialized = true;
    }

    /**
     * Send a notification to Telegram
     *
     * @param string $message The message to send
     * @param bool $background Whether to send in background (default: false)
     */
    public function notify($message, $background = false)
    {
        if ($this->telegram_alert) {
            $this->telegram_alert->send_telegram_message($message, $background);
        }
        // If Telegram notifier is not available, silently fail (no logging to avoid spam)
    }

    /**
     * Check if Telegram alerts are enabled
     *
     * @return bool
     */
    public function is_enabled()
    {
        return $this->telegram_alert !== null;
    }

    // ========================================
    // Predefined notification methods
    // Add your custom notifications below
    // ========================================

    /**
     * Notify when URL crawler starts
     */
    public function notify_url_crawler_start($url)
    {
        $this->notify('URL Crawler: Starting crawl for ' . $url, true);
    }

    /**
     * Notify when URL crawler completes
     */
    public function notify_url_crawler_complete($url, $status)
    {
        $this->notify('URL Crawler: Completed ' . $url . ' - Status: ' . $status, true);
    }

    /**
     * Notify when cache is purged
     */
    public function notify_cache_purge($type, $target)
    {
        $this->notify('Cache Purge: ' . $type . ' - ' . $target, true);
    }

    /**
     * Notify when LiteSpeed cache decision is made
     */
    public function notify_litespeed_decision($action, $product_id, $stock)
    {
        $this->notify('LiteSpeed: ' . $action . ' for Product #' . $product_id . ' (Stock: ' . $stock . ')', true);
    }

    /**
     * Notify when request monitoring is toggled
     */
    public function notify_request_monitor_toggled($enabled)
    {
        $status = $enabled ? 'ENABLED' : 'DISABLED';
        $this->notify('Request Monitor: ' . $status, true);
    }

    /**
     * Notify about errors
     */
    public function notify_error($message, $context = '')
    {
        $full_message = 'ERROR: ' . $message;
        if ($context) {
            $full_message .= ' | Context: ' . $context;
        }
        $this->notify($full_message, true);
    }

    /**
     * Notify about warnings
     */
    public function notify_warning($message, $context = '')
    {
        $full_message = 'WARNING: ' . $message;
        if ($context) {
            $full_message .= ' | Context: ' . $context;
        }
        $this->notify($full_message, true);
    }
}
