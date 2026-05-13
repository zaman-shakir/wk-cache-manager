<?php
namespace WKCacheManager\UrlCrawler;

class UrlCrawlerLogger {
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
     * Log a crawl attempt
     *
     * @param string $url The URL being crawled
     * @param array $result Crawl result
     * @param string $type Type of crawl (product, category, homepage)
     * @param int $object_id Object ID (product ID, term ID, etc.)
     */
    /**
     * Generic info/event line in the daily url-crawler log.
     * Used for queue lifecycle messages (batch scheduled, deferred, etc.)
     * that don't fit the per-URL log_crawl() row format.
     */
    public function log($message, $level = 'INFO') {
        $log_file = $this->get_current_log_file();
        $timestamp = current_time('mysql');
        $entry = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($level), $message);
        file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    }

    public function log_crawl($url, $result, $type = 'product', $object_id = 0) {
        $log_file = $this->get_current_log_file();
        $timestamp = current_time('mysql');

        // Extract final CF and LS status from result.
        $cf_status = 'UNKNOWN';
        $ls_status = 'UNKNOWN';
        $attempts = 0;

        if (is_array($result) && isset($result['results']) && is_array($result['results'])) {
            $attempts = count($result['results']);
            $final_result = end($result['results']);

            if (is_array($final_result)) {
                if (isset($final_result['cf_cache_status']) && is_string($final_result['cf_cache_status'])) {
                    $cf_status = $final_result['cf_cache_status'];
                }
                if (isset($final_result['ls_cache_status']) && is_string($final_result['ls_cache_status'])) {
                    $ls_status = $final_result['ls_cache_status'];
                }
            }
        }

        // Encode both statuses into the FINAL_STATUS column as "CF=<cf>,LS=<ls>"
        // so raw log lines are readable and the parser can split. Older log lines
        // without the LS half still parse — the comma split just falls through.
        $combined = sprintf('CF=%s,LS=%s', $cf_status, $ls_status);

        // Format: [TIMESTAMP]|URL|TYPE|OBJECT_ID|ATTEMPTS|FINAL_STATUS|DETAILS_JSON
        $log_entry = sprintf(
            "[%s]|%s|%s|%d|%d|%s|%s\n",
            $timestamp,
            $url,
            $type,
            $object_id,
            $attempts,
            $combined,
            json_encode($result)
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
        return $this->log_dir . 'url-crawler-' . $date . '.log';
    }

    /**
     * Get log file path for a specific date
     *
     * @param string $date Date in Y-m-d format
     * @return string Log file path
     */
    public function get_log_file_for_date($date) {
        return $this->log_dir . 'url-crawler-' . $date . '.log';
    }

    /**
     * Get all URL crawler log files
     *
     * @return array Array of log file paths
     */
    public function get_all_log_files() {
        return glob($this->log_dir . 'url-crawler-*.log');
    }

    /**
     * Get all parsed log entries for one YYYY-MM-DD date (full file, no limit).
     *
     * @param string $date YYYY-MM-DD
     * @return array
     */
    public function get_logs_for_date($date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }
        $file = $this->log_dir . 'url-crawler-' . $date . '.log';
        if (!file_exists($file)) {
            return [];
        }
        $logs = $this->parse_log_file($file);
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        return $logs;
    }

    /**
     * Get logs for a date range
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param int $limit Maximum number of logs to return
     * @return array Array of log entries
     */
    public function get_logs($start_date = null, $end_date = null, $limit = 100) {
        $all_logs = [];

        if ($start_date && $end_date) {
            // Get logs for date range
            $log_files = $this->get_log_files_for_range($start_date, $end_date);
        } else {
            // Get all log files
            $log_files = $this->get_all_log_files();
            // Sort by date (newest first)
            rsort($log_files);
            // Limit to last 7 days of files for performance
            $log_files = array_slice($log_files, 0, 7);
        }

        foreach ($log_files as $log_file) {
            $logs = $this->parse_log_file($log_file);
            $all_logs = array_merge($all_logs, $logs);

            // Stop if we've reached the limit
            if (count($all_logs) >= $limit) {
                break;
            }
        }

        // Sort by timestamp (newest first)
        usort($all_logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_slice($all_logs, 0, $limit);
    }

    /**
     * Parse a single log file
     *
     * @param string $log_file Path to log file
     * @return array Array of log entries
     */
    private function parse_log_file($log_file) {
        $logs = [];

        if (!file_exists($log_file)) {
            return $logs;
        }

        $handle = fopen($log_file, 'r');
        if (!$handle) {
            return $logs;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Parse line: [TIMESTAMP]|URL|TYPE|OBJECT_ID|ATTEMPTS|FINAL_STATUS|DETAILS_JSON
            $parts = explode('|', $line, 7);

            if (count($parts) === 7) {
                $timestamp = trim($parts[0], '[]');
                $details = json_decode($parts[6], true);

                $logs[] = [
                    'timestamp' => $timestamp,
                    'url' => $parts[1],
                    'type' => $parts[2],
                    'object_id' => (int)$parts[3],
                    'attempts' => (int)$parts[4],
                    'final_status' => $parts[5],
                    'attempts_detail' => isset($details['results']) ? $details['results'] : []
                ];
            }
        }

        fclose($handle);
        return $logs;
    }

    /**
     * Get log files for a date range
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Array of log file paths
     */
    private function get_log_files_for_range($start_date, $end_date) {
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

        // Sort newest first
        rsort($log_files);

        return $log_files;
    }

    /**
     * Clean up old log files based on retention settings
     */
    public function cleanup_old_logs() {
        $retention_days = get_option('wk_cache_manager_url_crawler_log_retention_days', 30);
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
     * Clear all URL crawler logs
     */
    public function clear_all_logs() {
        $log_files = $this->get_all_log_files();

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

    /**
     * Get total number of log entries (approximate, based on line count)
     *
     * @return int Total number of log entries
     */
    public function get_total_log_count() {
        $log_files = $this->get_all_log_files();
        $total_count = 0;

        foreach ($log_files as $log_file) {
            if (file_exists($log_file)) {
                $lines = count(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
                $total_count += $lines;
            }
        }

        return $total_count;
    }
}
