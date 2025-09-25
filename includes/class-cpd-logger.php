<?php
/**
 * Logger Class for Custom Permalink Domain Plugin
 *
 * Structured logging system for debugging cache operations, URL transformations,
 * and performance monitoring. Provides multiple output formats and log levels
 * with WordPress integration.
 *
 * Features:
 * - Multiple log levels (error, warning, info, debug)
 * - Context-aware logging with structured data
 * - WordPress error log integration
 * - Development vs production mode handling
 * - Cache operation tracking
 * - Performance metrics collection
 *
 * @package CustomPermalinkDomain
 * @since   1.3.11
 * @version 1.3.11
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-cpd-constants.php';

class CPD_Logger {

    /**
     * Log entries storage
     * @var array
     */
    private static $log_entries = [];

    /**
     * Maximum log entries to store in memory
     * @var int
     */
    private static $max_entries = 1000;

    /**
     * Current log level threshold
     * @var string
     */
    private static $log_level = CPD_Constants::LOG_INFO;

    /**
     * Initialize the logger
     */
    public static function init() {
        self::$log_level = CPD_Constants::is_development()
            ? CPD_Constants::LOG_DEBUG
            : CPD_Constants::LOG_INFO;
    }

    /**
     * Log an error message
     *
     * @param string $message Error message
     * @param array  $context Additional context data
     */
    public static function error($message, $context = []) {
        self::log(CPD_Constants::LOG_ERROR, $message, $context);
    }

    /**
     * Log a warning message
     *
     * @param string $message Warning message
     * @param array  $context Additional context data
     */
    public static function warning($message, $context = []) {
        self::log(CPD_Constants::LOG_WARNING, $message, $context);
    }

    /**
     * Log an info message
     *
     * @param string $message Info message
     * @param array  $context Additional context data
     */
    public static function info($message, $context = []) {
        self::log(CPD_Constants::LOG_INFO, $message, $context);
    }

    /**
     * Log a debug message
     *
     * @param string $message Debug message
     * @param array  $context Additional context data
     */
    public static function debug($message, $context = []) {
        self::log(CPD_Constants::LOG_DEBUG, $message, $context);
    }

    /**
     * Log cache operation
     *
     * @param string $operation Cache operation (hit, miss, set, delete, purge)
     * @param string $key Cache key
     * @param array  $context Additional context
     */
    public static function cache_operation($operation, $key, $context = []) {
        $context = array_merge($context, [
            'operation' => $operation,
            'cache_key' => $key,
            'timestamp' => microtime(true)
        ]);

        self::debug("Cache {$operation}: {$key}", $context);
    }

    /**
     * Log URL transformation
     *
     * @param string $original_url Original URL
     * @param string $transformed_url Transformed URL
     * @param array  $context Additional context
     */
    public static function url_transformation($original_url, $transformed_url, $context = []) {
        $context = array_merge($context, [
            'original_url' => $original_url,
            'transformed_url' => $transformed_url,
            'transformation_time' => microtime(true)
        ]);

        self::debug("URL transformed: {$original_url} -> {$transformed_url}", $context);
    }

    /**
     * Log performance metric
     *
     * @param string $metric Metric name
     * @param mixed  $value Metric value
     * @param array  $context Additional context
     */
    public static function performance_metric($metric, $value, $context = []) {
        $context = array_merge($context, [
            'metric' => $metric,
            'value' => $value,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true)
        ]);

        self::debug("Performance metric - {$metric}: {$value}", $context);
    }

    /**
     * Main logging method
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array  $context Context data
     */
    private static function log($level, $message, $context = []) {
        if (!self::should_log($level)) {
            return;
        }

        $entry = [
            'timestamp' => current_time('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'cli',
            'user_id' => get_current_user_id()
        ];

        // Store in memory
        self::store_entry($entry);

        // Write to WordPress error log in development
        if (CPD_Constants::is_development()) {
            self::write_to_error_log($entry);
        }

        // Trigger WordPress action for external logging systems
        do_action('cpd_log_entry', $entry);
    }

    /**
     * Check if we should log at this level
     *
     * @param string $level Log level to check
     * @return bool
     */
    private static function should_log($level) {
        $levels = [
            CPD_Constants::LOG_ERROR => 0,
            CPD_Constants::LOG_WARNING => 1,
            CPD_Constants::LOG_INFO => 2,
            CPD_Constants::LOG_DEBUG => 3
        ];

        return isset($levels[$level]) &&
               isset($levels[self::$log_level]) &&
               $levels[$level] <= $levels[self::$log_level];
    }

    /**
     * Store log entry in memory
     *
     * @param array $entry Log entry
     */
    private static function store_entry($entry) {
        self::$log_entries[] = $entry;

        // Prevent memory overflow
        if (count(self::$log_entries) > self::$max_entries) {
            array_shift(self::$log_entries);
        }
    }

    /**
     * Write to WordPress error log
     *
     * @param array $entry Log entry
     */
    private static function write_to_error_log($entry) {
        $formatted = sprintf(
            '[%s] CPD %s: %s %s',
            $entry['timestamp'],
            strtoupper($entry['level']),
            $entry['message'],
            !empty($entry['context']) ? '- Context: ' . wp_json_encode($entry['context']) : ''
        );

        error_log($formatted);
    }

    /**
     * Get recent log entries
     *
     * @param int    $limit Number of entries to return
     * @param string $level Minimum log level
     * @return array Log entries
     */
    public static function get_recent_logs($limit = 50, $level = null) {
        $entries = self::$log_entries;

        if ($level) {
            $entries = array_filter($entries, function($entry) use ($level) {
                return $entry['level'] === $level;
            });
        }

        return array_slice(array_reverse($entries), 0, $limit);
    }

    /**
     * Clear all stored log entries
     */
    public static function clear_logs() {
        self::$log_entries = [];
    }

    /**
     * Get log statistics
     *
     * @return array Log statistics
     */
    public static function get_stats() {
        $stats = [
            'total_entries' => count(self::$log_entries),
            'by_level' => [],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];

        foreach (self::$log_entries as $entry) {
            $level = $entry['level'];
            $stats['by_level'][$level] = isset($stats['by_level'][$level])
                ? $stats['by_level'][$level] + 1
                : 1;
        }

        return $stats;
    }

    /**
     * Export logs for debugging
     *
     * @param string $format Export format (json, csv, txt)
     * @return string Exported log data
     */
    public static function export_logs($format = 'json') {
        switch ($format) {
            case 'csv':
                return self::export_csv();
            case 'txt':
                return self::export_txt();
            default:
                return wp_json_encode(self::$log_entries, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Export logs as CSV
     *
     * @return string CSV data
     */
    private static function export_csv() {
        $csv = "Timestamp,Level,Message,Context,Memory Usage,Request URI,User ID\n";

        foreach (self::$log_entries as $entry) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s","%s"' . "\n",
                $entry['timestamp'],
                $entry['level'],
                str_replace('"', '""', $entry['message']),
                wp_json_encode($entry['context']),
                $entry['memory_usage'],
                $entry['request_uri'],
                $entry['user_id']
            );
        }

        return $csv;
    }

    /**
     * Export logs as plain text
     *
     * @return string Text data
     */
    private static function export_txt() {
        $txt = "Custom Permalink Domain - Debug Log Export\n";
        $txt .= str_repeat('=', 50) . "\n\n";

        foreach (self::$log_entries as $entry) {
            $txt .= sprintf(
                "[%s] %s: %s\n",
                $entry['timestamp'],
                strtoupper($entry['level']),
                $entry['message']
            );

            if (!empty($entry['context'])) {
                $txt .= "  Context: " . wp_json_encode($entry['context']) . "\n";
            }

            $txt .= "\n";
        }

        return $txt;
    }

    /**
     * Enable debug mode
     */
    public static function enable_debug() {
        self::$log_level = CPD_Constants::LOG_DEBUG;
    }

    /**
     * Disable debug mode
     */
    public static function disable_debug() {
        self::$log_level = CPD_Constants::LOG_INFO;
    }
}

// Initialize logger
CPD_Logger::init();