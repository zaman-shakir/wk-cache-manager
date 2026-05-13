<?php
namespace WKCacheManager\RequestMonitor;

class RequestLogger {
    private static $instance = null;
    private $log_dir;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->log_dir = WK_CACHE_MANAGER_LOG_DIR;
        $this->ensure_log_directory();
    }

    /**
     * Ensure log directory exists and is protected
     */
    private function ensure_log_directory() {
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);

            // Create .htaccess to protect logs
            $htaccess = $this->log_dir . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }
    }

    /**
     * Log a request
     *
     * @param string $url The URL being accessed
     * @param string $cf_status CloudFlare cache status
     * @param string $ls_status LiteSpeed cache status
     */
    public function log_request($url, $cf_status, $ls_status) {
        $log_file = $this->get_current_log_file();
        $timestamp = current_time('mysql');

        // Format: [TIMESTAMP]|URL|CF_STATUS|LS_STATUS
        $log_entry = sprintf(
            "[%s]|%s|%s|%s\n",
            $timestamp,
            $url,
            $cf_status,
            $ls_status
        );

        // Append to log file
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get the current log file path (daily rotation)
     *
     * @return string Log file path
     */
    private function get_current_log_file() {
        $date = current_time('Y-m-d');
        return $this->log_dir . 'request-monitor-' . $date . '.log';
    }

    /**
     * Get log file path for a specific date
     *
     * @param string $date Date in Y-m-d format
     * @return string Log file path
     */
    public function get_log_file_for_date($date) {
        return $this->log_dir . 'request-monitor-' . $date . '.log';
    }

    /**
     * Clean up old log files based on retention settings
     */
    public function cleanup_old_logs() {
        $retention_days = get_option('wk_cache_manager_request_monitor_retention_days', 30);
        $cutoff_time = strtotime("-{$retention_days} days");

        $log_files = glob($this->log_dir . 'request-monitor-*.log');

        foreach ($log_files as $log_file) {
            $file_time = filemtime($log_file);
            if ($file_time < $cutoff_time) {
                unlink($log_file);
            }
        }
    }

    /**
     * Get all log files
     *
     * @return array Array of log file paths
     */
    public function get_all_log_files() {
        return glob($this->log_dir . 'request-monitor-*.log');
    }

    /**
     * Get log files for a date range
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Array of log file paths
     */
    public function get_log_files_for_range($start_date, $end_date) {
        $log_files = [];
        $current = strtotime($start_date);
        $end = strtotime($end_date);

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $log_file = $this->get_log_file_for_date($date);

            if (file_exists($log_file)) {
                $log_files[] = $log_file;
            }

            $current = strtotime('+1 day', $current);
        }

        return $log_files;
    }

    /**
     * Clear all request monitor logs
     */
    public function clear_all_logs() {
        $log_files = glob($this->log_dir . 'request-monitor-*.log');

        foreach ($log_files as $log_file) {
            unlink($log_file);
        }
    }

    /**
     * Get total size of all log files
     *
     * @return int Total size in bytes
     */
    public function get_total_log_size() {
        $log_files = $this->get_all_log_files();
        $total_size = 0;

        foreach ($log_files as $log_file) {
            $total_size += filesize($log_file);
        }

        return $total_size;
    }
}
