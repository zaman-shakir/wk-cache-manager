<?php
namespace WKCacheManager\RequestMonitor;

class RequestAnalytics {
    private static $instance = null;
    private $logger;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger = RequestLogger::get_instance();
    }

    /**
     * Get statistics for a date range
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Statistics data
     */
    public function get_stats($start_date, $end_date) {
        $log_files = $this->logger->get_log_files_for_range($start_date, $end_date);

        if (empty($log_files)) {
            return $this->get_empty_stats();
        }

        $stats = [
            'total_requests' => 0,
            'cf_stats' => [],
            'ls_stats' => [],
            'url_data' => [], // Store per-URL cache data
        ];

        // Parse all log files
        foreach ($log_files as $log_file) {
            $this->parse_log_file($log_file, $stats);
        }

        // Calculate percentages and summaries
        $stats['cf_summary'] = $this->calculate_summary($stats['cf_stats'], $stats['total_requests']);
        $stats['ls_summary'] = $this->calculate_summary($stats['ls_stats'], $stats['total_requests']);

        // Get top URLs
        $stats['top_cached_urls'] = $this->get_top_urls($stats['url_data'], 'hit', 10);
        $stats['top_miss_urls'] = $this->get_top_urls($stats['url_data'], 'miss', 10);

        return $stats;
    }

    /**
     * Parse a single log file
     *
     * @param string $log_file Path to log file
     * @param array &$stats Statistics array (passed by reference)
     */
    private function parse_log_file($log_file, &$stats) {
        $handle = fopen($log_file, 'r');
        if (!$handle) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Parse line: [TIMESTAMP]|URL|CF_STATUS|LS_STATUS
            $parts = explode('|', $line);
            if (count($parts) !== 4) {
                continue;
            }

            $timestamp = trim($parts[0], '[]');
            $url = $parts[1];
            $cf_status = $parts[2];
            $ls_status = $parts[3];

            $stats['total_requests']++;

            // Count CF statuses
            if (!isset($stats['cf_stats'][$cf_status])) {
                $stats['cf_stats'][$cf_status] = 0;
            }
            $stats['cf_stats'][$cf_status]++;

            // Count LS statuses
            if (!isset($stats['ls_stats'][$ls_status])) {
                $stats['ls_stats'][$ls_status] = 0;
            }
            $stats['ls_stats'][$ls_status]++;

            // Track per-URL data
            if (!isset($stats['url_data'][$url])) {
                $stats['url_data'][$url] = [
                    'total' => 0,
                    'cf_hit' => 0,
                    'cf_miss' => 0,
                    'ls_hit' => 0,
                    'ls_miss' => 0,
                ];
            }

            $stats['url_data'][$url]['total']++;

            // Track CF hits/misses
            if (strtoupper($cf_status) === 'HIT') {
                $stats['url_data'][$url]['cf_hit']++;
            } elseif (strtoupper($cf_status) === 'MISS') {
                $stats['url_data'][$url]['cf_miss']++;
            }

            // Track LS hits/misses
            if (strtolower($ls_status) === 'hit') {
                $stats['url_data'][$url]['ls_hit']++;
            } elseif (strtolower($ls_status) === 'miss') {
                $stats['url_data'][$url]['ls_miss']++;
            }
        }

        fclose($handle);
    }

    /**
     * Calculate summary statistics with percentages
     *
     * @param array $status_counts Status counts
     * @param int $total Total requests
     * @return array Summary with percentages
     */
    private function calculate_summary($status_counts, $total) {
        if ($total === 0) {
            return [];
        }

        $summary = [];
        foreach ($status_counts as $status => $count) {
            $percentage = ($count / $total) * 100;
            $summary[$status] = [
                'count' => $count,
                'percentage' => round($percentage, 1),
            ];
        }

        // Sort by count (descending)
        uasort($summary, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        return $summary;
    }

    /**
     * Get top URLs by hit or miss rate
     *
     * @param array $url_data URL data
     * @param string $type 'hit' or 'miss'
     * @param int $limit Number of URLs to return
     * @return array Top URLs
     */
    private function get_top_urls($url_data, $type = 'hit', $limit = 10) {
        $urls_with_rate = [];

        foreach ($url_data as $url => $data) {
            if ($data['total'] === 0) {
                continue;
            }

            // Calculate combined hit/miss rate (CF + LS)
            $hit_count = $data['cf_hit'] + $data['ls_hit'];
            $miss_count = $data['cf_miss'] + $data['ls_miss'];
            $total = $hit_count + $miss_count;

            if ($total === 0) {
                continue;
            }

            $hit_rate = ($hit_count / $total) * 100;

            $urls_with_rate[] = [
                'url' => $url,
                'total_requests' => $data['total'],
                'hit_rate' => round($hit_rate, 1),
                'miss_rate' => round(100 - $hit_rate, 1),
                'cf_hit' => $data['cf_hit'],
                'cf_miss' => $data['cf_miss'],
                'ls_hit' => $data['ls_hit'],
                'ls_miss' => $data['ls_miss'],
            ];
        }

        // Sort based on type
        if ($type === 'hit') {
            // Sort by hit rate descending (best cached pages)
            usort($urls_with_rate, function($a, $b) {
                return $b['hit_rate'] - $a['hit_rate'];
            });
        } else {
            // Sort by miss rate descending (worst cached pages)
            usort($urls_with_rate, function($a, $b) {
                return $b['miss_rate'] - $a['miss_rate'];
            });
        }

        return array_slice($urls_with_rate, 0, $limit);
    }

    /**
     * Get empty stats structure
     *
     * @return array Empty stats
     */
    private function get_empty_stats() {
        return [
            'total_requests' => 0,
            'cf_stats' => [],
            'ls_stats' => [],
            'cf_summary' => [],
            'ls_summary' => [],
            'url_data' => [],
            'top_cached_urls' => [],
            'top_miss_urls' => [],
        ];
    }

    /**
     * Get overall hit rates (summary)
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @return array Hit rate summary
     */
    public function get_hit_rates($start_date, $end_date) {
        $stats = $this->get_stats($start_date, $end_date);

        $cf_hit_rate = 0;
        $ls_hit_rate = 0;

        if (isset($stats['cf_summary']['HIT'])) {
            $cf_hit_rate = $stats['cf_summary']['HIT']['percentage'];
        }

        if (isset($stats['ls_summary']['hit'])) {
            $ls_hit_rate = $stats['ls_summary']['hit']['percentage'];
        }

        return [
            'cf_hit_rate' => $cf_hit_rate,
            'ls_hit_rate' => $ls_hit_rate,
            'total_requests' => $stats['total_requests'],
        ];
    }
}
