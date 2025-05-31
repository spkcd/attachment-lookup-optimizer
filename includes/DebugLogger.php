<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Debug Logger Class
 * 
 * Handles file-based debug logging for attachment lookup operations
 * Supports rotating logs, multiple formats, and detailed call tracking
 */
class DebugLogger {
    
    /**
     * Log directory name
     */
    const LOG_DIR_NAME = 'attachment-lookup-logs';
    
    /**
     * Maximum log file size (5MB)
     */
    const MAX_FILE_SIZE = 5242880; // 5MB
    
    /**
     * Maximum number of log files to keep
     */
    const MAX_FILES = 10;
    
    /**
     * Log file base name
     */
    const LOG_FILE_PREFIX = 'attachment-lookup';
    
    /**
     * Cleanup settings
     */
    const CLEANUP_THROTTLE_HOURS = 6;
    const DELETE_AFTER_DAYS = 7;
    const COMPRESS_AFTER_DAYS = 3;
    const CLEANUP_TRANSIENT_KEY = 'alo_log_cleanup_last_run';
    
    /**
     * Whether debug logging is enabled
     */
    private $enabled = false;
    
    /**
     * Log format (json or plain)
     */
    private $format = 'json';
    
    /**
     * Current log file path
     */
    private $current_log_file = null;
    
    /**
     * Log directory path
     */
    private $log_directory = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->enabled = get_option('alo_attachment_debug_logs', false);
        $this->format = get_option('alo_debug_log_format', 'json');
        
        if ($this->enabled) {
            $this->init_logging();
        }
    }
    
    /**
     * Initialize logging system
     */
    private function init_logging() {
        $upload_dir = wp_upload_dir();
        $this->log_directory = $upload_dir['basedir'] . '/' . self::LOG_DIR_NAME;
        
        // Ensure log directory exists
        $this->ensure_log_directory();
        
        // Set current log file
        $this->current_log_file = $this->get_current_log_file();
    }
    
    /**
     * Ensure log directory exists and is protected
     */
    private function ensure_log_directory() {
        if (!is_dir($this->log_directory)) {
            wp_mkdir_p($this->log_directory);
            
            // Create .htaccess to protect log files
            $htaccess_file = $this->log_directory . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, "Deny from all\n");
            }
            
            // Create index.php to prevent directory listing
            $index_file = $this->log_directory . '/index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }
        }
    }
    
    /**
     * Get current log file path
     */
    private function get_current_log_file() {
        $date = date('Y-m-d');
        $base_file = $this->log_directory . '/' . self::LOG_FILE_PREFIX . '-' . $date . '.log';
        
        // Check if we need rotation due to file size
        if (file_exists($base_file) && filesize($base_file) >= self::MAX_FILE_SIZE) {
            $counter = 1;
            do {
                $rotated_file = $this->log_directory . '/' . self::LOG_FILE_PREFIX . '-' . $date . '-' . $counter . '.log';
                $counter++;
            } while (file_exists($rotated_file) && filesize($rotated_file) >= self::MAX_FILE_SIZE);
            
            return $rotated_file;
        }
        
        return $base_file;
    }
    
    /**
     * Log an attachment lookup call
     * 
     * @param string $url The attachment URL being looked up
     * @param string $lookup_source Source of the lookup (static_cache, cache_hit, table_lookup, etc.)
     * @param int|false $result_post_id The resulting post ID or false if not found
     * @param float $query_time_ms Query execution time in milliseconds
     * @param array $additional_data Additional context data
     */
    public function log_attachment_lookup($url, $lookup_source, $result_post_id, $query_time_ms, $additional_data = []) {
        if (!$this->enabled || !$this->current_log_file) {
            return;
        }
        
        // Prepare log entry data
        $log_data = [
            'timestamp' => current_time('mysql', true),
            'timestamp_unix' => time(),
            'url' => $this->sanitize_url_for_logging($url),
            'lookup_source' => $lookup_source,
            'result_post_id' => $result_post_id !== false ? (int) $result_post_id : null,
            'found' => $result_post_id !== false,
            'query_time_ms' => round($query_time_ms, 4),
            'memory_usage_mb' => round(memory_get_usage() / 1048576, 2),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $this->get_sanitized_user_agent(),
            'caller_info' => $this->extract_caller_info($additional_data)
        ];
        
        // Add any additional data
        if (!empty($additional_data)) {
            $log_data['additional'] = $additional_data;
        }
        
        // Format and write log entry
        $log_entry = $this->format_log_entry($log_data);
        $this->write_log_entry($log_entry);
        
        // Check if rotation is needed after writing
        $this->check_rotation();
    }
    
    /**
     * Sanitize URL for logging (remove sensitive parameters)
     */
    private function sanitize_url_for_logging($url) {
        // Parse URL and remove sensitive query parameters
        $parsed = parse_url($url);
        
        if (!$parsed) {
            return $url;
        }
        
        // Rebuild URL without query parameters (they might contain sensitive data)
        $clean_url = '';
        
        if (isset($parsed['scheme'])) {
            $clean_url .= $parsed['scheme'] . '://';
        }
        
        if (isset($parsed['host'])) {
            $clean_url .= $parsed['host'];
        }
        
        if (isset($parsed['port'])) {
            $clean_url .= ':' . $parsed['port'];
        }
        
        if (isset($parsed['path'])) {
            $clean_url .= $parsed['path'];
        }
        
        return $clean_url;
    }
    
    /**
     * Get sanitized user agent
     */
    private function get_sanitized_user_agent() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Truncate very long user agents
        if (strlen($user_agent) > 200) {
            $user_agent = substr($user_agent, 0, 200) . '...';
        }
        
        return $user_agent;
    }
    
    /**
     * Extract caller information from additional data
     */
    private function extract_caller_info($additional_data) {
        $caller_info = [
            'function' => 'unknown',
            'is_jetengine' => false,
            'is_admin' => is_admin(),
            'is_ajax' => defined('DOING_AJAX') && DOING_AJAX
        ];
        
        // Extract from additional data if available
        if (isset($additional_data['caller_info'])) {
            $caller_data = $additional_data['caller_info'];
            
            $caller_info['function'] = $caller_data['function'] ?? 'unknown';
            $caller_info['is_jetengine'] = $caller_data['is_jetengine'] ?? false;
            $caller_info['is_theme'] = $caller_data['is_theme'] ?? false;
            $caller_info['is_plugin'] = $caller_data['is_plugin'] ?? false;
            $caller_info['plugin_name'] = $caller_data['plugin_name'] ?? null;
        }
        
        return $caller_info;
    }
    
    /**
     * Format log entry based on configured format
     */
    private function format_log_entry($log_data) {
        switch ($this->format) {
            case 'plain':
                return $this->format_plain_text($log_data);
            
            case 'json':
            default:
                return json_encode($log_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    
    /**
     * Format log entry as plain text
     */
    private function format_plain_text($log_data) {
        $parts = [
            '[' . $log_data['timestamp'] . ']',
            'URL: ' . $log_data['url'],
            'Source: ' . $log_data['lookup_source'],
            'Result: ' . ($log_data['found'] ? 'ID ' . $log_data['result_post_id'] : 'NOT FOUND'),
            'Time: ' . $log_data['query_time_ms'] . 'ms',
            'Memory: ' . $log_data['memory_usage_mb'] . 'MB'
        ];
        
        if (!empty($log_data['caller_info']['function'])) {
            $parts[] = 'Caller: ' . $log_data['caller_info']['function'];
        }
        
        if ($log_data['caller_info']['is_jetengine']) {
            $parts[] = 'JetEngine: YES';
        }
        
        return implode(' | ', $parts);
    }
    
    /**
     * Write log entry to file
     */
    private function write_log_entry($log_entry) {
        if (!$this->current_log_file) {
            return false;
        }
        
        $log_line = $log_entry . "\n";
        
        // Use file locking to prevent corruption
        $result = file_put_contents($this->current_log_file, $log_line, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            error_log('ALO Debug Logger: Failed to write to log file: ' . $this->current_log_file);
        }
        
        return $result !== false;
    }
    
    /**
     * Check if log rotation is needed
     */
    private function check_rotation() {
        if (!$this->current_log_file || !file_exists($this->current_log_file)) {
            return;
        }
        
        // Check file size
        if (filesize($this->current_log_file) >= self::MAX_FILE_SIZE) {
            $this->current_log_file = $this->get_current_log_file();
        }
        
        // Clean up old files
        $this->cleanup_old_files();
    }
    
    /**
     * Clean up old log files
     */
    private function cleanup_old_files() {
        if (!is_dir($this->log_directory)) {
            return;
        }
        
        $pattern = $this->log_directory . '/' . self::LOG_FILE_PREFIX . '-*.log';
        $files = glob($pattern);
        
        if (count($files) <= self::MAX_FILES) {
            return;
        }
        
        // Sort by modification time (oldest first)
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Remove oldest files
        $files_to_remove = count($files) - self::MAX_FILES;
        for ($i = 0; $i < $files_to_remove; $i++) {
            if (isset($files[$i])) {
                unlink($files[$i]);
            }
        }
    }
    
    /**
     * Get log statistics
     */
    public function get_stats() {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'log_directory' => null,
                'total_files' => 0,
                'total_size_mb' => 0,
                'current_file' => null
            ];
        }
        
        $files = [];
        $total_size = 0;
        
        if (is_dir($this->log_directory)) {
            $pattern = $this->log_directory . '/' . self::LOG_FILE_PREFIX . '-*.log';
            $files = glob($pattern);
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $total_size += filesize($file);
                }
            }
        }
        
        return [
            'enabled' => true,
            'log_directory' => $this->log_directory,
            'total_files' => count($files),
            'total_size_mb' => round($total_size / 1048576, 2),
            'current_file' => $this->current_log_file ? basename($this->current_log_file) : null,
            'format' => $this->format,
            'max_file_size_mb' => round(self::MAX_FILE_SIZE / 1048576, 1),
            'max_files' => self::MAX_FILES
        ];
    }
    
    /**
     * Enable debug logging
     */
    public function enable() {
        $this->enabled = true;
        update_option('alo_attachment_debug_logs', true);
        $this->init_logging();
    }
    
    /**
     * Disable debug logging
     */
    public function disable() {
        $this->enabled = false;
        update_option('alo_attachment_debug_logs', false);
    }
    
    /**
     * Set log format
     */
    public function set_format($format) {
        if (in_array($format, ['json', 'plain'])) {
            $this->format = $format;
            update_option('alo_debug_log_format', $format);
        }
    }
    
    /**
     * Check if logging is enabled
     */
    public function is_enabled() {
        return $this->enabled;
    }
    
    /**
     * Get available log files for reading
     */
    public function get_log_files() {
        if (!is_dir($this->log_directory)) {
            return [];
        }
        
        $pattern = $this->log_directory . '/' . self::LOG_FILE_PREFIX . '-*.log';
        $files = glob($pattern);
        
        if (!$files) {
            return [];
        }
        
        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        return array_map('basename', $files);
    }
    
    /**
     * Read log entries from a specific file
     */
    public function read_log_file($filename, $limit = 100) {
        $filepath = $this->log_directory . '/' . $filename;
        
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return [];
        }
        
        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if (!$lines) {
            return [];
        }
        
        // Get the last N lines
        $lines = array_slice($lines, -$limit);
        
        $entries = [];
        foreach ($lines as $line) {
            if ($this->format === 'json') {
                $decoded = json_decode($line, true);
                if ($decoded !== null) {
                    $entries[] = $decoded;
                }
            } else {
                $entries[] = ['raw' => $line];
            }
        }
        
        return array_reverse($entries); // Show newest first
    }
    
    /**
     * Clear all log files
     */
    public function clear_logs() {
        if (!is_dir($this->log_directory)) {
            return 0;
        }
        
        $pattern = $this->log_directory . '/' . self::LOG_FILE_PREFIX . '-*.log';
        $files = glob($pattern);
        
        $deleted = 0;
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        // Reset current log file
        if ($this->enabled) {
            $this->current_log_file = $this->get_current_log_file();
        }
        
        return $deleted;
    }
    
    /**
     * Initialize automated cleanup hook
     */
    public static function init_automated_cleanup() {
        add_action('admin_init', [__CLASS__, 'maybe_run_automated_cleanup']);
    }
    
    /**
     * Maybe run automated cleanup (throttled to every 6 hours)
     */
    public static function maybe_run_automated_cleanup() {
        // Check if cleanup has run recently
        $last_cleanup = get_transient(self::CLEANUP_TRANSIENT_KEY);
        if ($last_cleanup !== false) {
            return; // Cleanup ran recently, skip
        }
        
        // Set transient to throttle cleanup
        $throttle_duration = self::CLEANUP_THROTTLE_HOURS * HOUR_IN_SECONDS;
        set_transient(self::CLEANUP_TRANSIENT_KEY, time(), $throttle_duration);
        
        // Run the cleanup
        self::run_automated_cleanup();
    }
    
    /**
     * Run automated log cleanup
     */
    public static function run_automated_cleanup() {
        $upload_dir = wp_upload_dir();
        $log_directory = $upload_dir['basedir'] . '/' . self::LOG_DIR_NAME;
        
        if (!is_dir($log_directory)) {
            return;
        }
        
        // Initialize WordPress filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        $filesystem_credentials = request_filesystem_credentials('', '', false, false, array());
        if (!WP_Filesystem($filesystem_credentials)) {
            error_log('ALO Debug Logger: Failed to initialize WP_Filesystem for log cleanup');
            return;
        }
        
        global $wp_filesystem;
        
        try {
            $cleanup_results = self::cleanup_logs_with_filesystem($wp_filesystem, $log_directory);
            
            // Log the cleanup results
            if ($cleanup_results['total_processed'] > 0) {
                error_log(sprintf(
                    'ALO Debug Logger: Automated cleanup completed - %d files processed, %d deleted, %d compressed, %d errors',
                    $cleanup_results['total_processed'],
                    $cleanup_results['deleted_count'],
                    $cleanup_results['compressed_count'],
                    $cleanup_results['error_count']
                ));
            }
        } catch (Exception $e) {
            error_log('ALO Debug Logger: Automated cleanup failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Cleanup logs using WP_Filesystem
     */
    private static function cleanup_logs_with_filesystem($wp_filesystem, $log_directory) {
        $results = [
            'total_processed' => 0,
            'deleted_count' => 0,
            'compressed_count' => 0,
            'error_count' => 0,
            'errors' => []
        ];
        
        // Get all log files
        $log_files = self::get_log_files_with_filesystem($wp_filesystem, $log_directory);
        
        if (empty($log_files)) {
            return $results;
        }
        
        $current_time = time();
        $delete_threshold = $current_time - (self::DELETE_AFTER_DAYS * DAY_IN_SECONDS);
        $compress_threshold = $current_time - (self::COMPRESS_AFTER_DAYS * DAY_IN_SECONDS);
        
        // Ensure archived logs directory exists
        $archive_dir = $log_directory . '/archived-logs';
        if (!$wp_filesystem->exists($archive_dir)) {
            if (!$wp_filesystem->mkdir($archive_dir, 0755)) {
                $results['errors'][] = 'Failed to create archive directory';
                $results['error_count']++;
            }
        }
        
        foreach ($log_files as $file_info) {
            $results['total_processed']++;
            $file_path = $file_info['path'];
            $file_time = $file_info['modified'];
            
            try {
                if ($file_time < $delete_threshold) {
                    // Delete old files (older than 7 days)
                    if (self::delete_log_file($wp_filesystem, $file_path, $archive_dir)) {
                        $results['deleted_count']++;
                    } else {
                        $results['error_count']++;
                        $results['errors'][] = 'Failed to delete ' . basename($file_path);
                    }
                } elseif ($file_time < $compress_threshold) {
                    // Compress files older than 3 days but newer than 7 days
                    if (self::compress_log_file($wp_filesystem, $file_path, $archive_dir)) {
                        $results['compressed_count']++;
                    } else {
                        $results['error_count']++;
                        $results['errors'][] = 'Failed to compress ' . basename($file_path);
                    }
                }
            } catch (Exception $e) {
                $results['error_count']++;
                $results['errors'][] = 'Error processing ' . basename($file_path) . ': ' . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Get log files using WP_Filesystem
     */
    private static function get_log_files_with_filesystem($wp_filesystem, $log_directory) {
        $files = [];
        
        $dir_list = $wp_filesystem->dirlist($log_directory);
        if (!$dir_list) {
            return $files;
        }
        
        foreach ($dir_list as $filename => $file_info) {
            // Only process log files, skip directories and other files
            if ($file_info['type'] === 'f' && 
                strpos($filename, self::LOG_FILE_PREFIX) === 0 && 
                substr($filename, -4) === '.log') {
                
                $files[] = [
                    'path' => $log_directory . '/' . $filename,
                    'filename' => $filename,
                    'modified' => $file_info['lastmodunix'],
                    'size' => $file_info['size']
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Delete a log file (and its compressed version if exists)
     */
    private static function delete_log_file($wp_filesystem, $file_path, $archive_dir) {
        $filename = basename($file_path);
        $archived_file = $archive_dir . '/' . $filename . '.gz';
        
        $success = true;
        
        // Delete original file
        if ($wp_filesystem->exists($file_path)) {
            if (!$wp_filesystem->delete($file_path)) {
                $success = false;
            }
        }
        
        // Delete compressed version if it exists
        if ($wp_filesystem->exists($archived_file)) {
            if (!$wp_filesystem->delete($archived_file)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Compress a log file and move to archive directory
     */
    private static function compress_log_file($wp_filesystem, $file_path, $archive_dir) {
        $filename = basename($file_path);
        $compressed_filename = $filename . '.gz';
        $archived_file = $archive_dir . '/' . $compressed_filename;
        
        // Skip if already compressed
        if ($wp_filesystem->exists($archived_file)) {
            return true;
        }
        
        // Read the original file
        $file_content = $wp_filesystem->get_contents($file_path);
        if ($file_content === false) {
            return false;
        }
        
        // Compress the content
        $compressed_content = gzencode($file_content, 6); // Compression level 6 for good balance
        if ($compressed_content === false) {
            return false;
        }
        
        // Write compressed file
        if (!$wp_filesystem->put_contents($archived_file, $compressed_content, 0644)) {
            return false;
        }
        
        // Delete original file after successful compression
        if (!$wp_filesystem->delete($file_path)) {
            // Compression succeeded but deletion failed
            // This is not ideal but not a complete failure
            error_log('ALO Debug Logger: Compressed ' . $filename . ' but failed to delete original');
        }
        
        return true;
    }
    
    /**
     * Get cleanup statistics
     */
    public function get_cleanup_stats() {
        $stats = [
            'cleanup_enabled' => true,
            'last_cleanup_time' => null,
            'next_cleanup_time' => null,
            'cleanup_interval_hours' => self::CLEANUP_THROTTLE_HOURS,
            'delete_after_days' => self::DELETE_AFTER_DAYS,
            'compress_after_days' => self::COMPRESS_AFTER_DAYS,
            'archive_directory' => null,
            'total_archived_files' => 0,
            'total_archived_size' => 0
        ];
        
        // Check last cleanup time
        $last_cleanup = get_transient(self::CLEANUP_TRANSIENT_KEY);
        if ($last_cleanup !== false) {
            $stats['last_cleanup_time'] = $last_cleanup;
            $stats['next_cleanup_time'] = $last_cleanup + (self::CLEANUP_THROTTLE_HOURS * HOUR_IN_SECONDS);
        }
        
        // Check archive directory
        if ($this->log_directory) {
            $archive_dir = $this->log_directory . '/archived-logs';
            $stats['archive_directory'] = $archive_dir;
            
            if (is_dir($archive_dir)) {
                $archived_files = glob($archive_dir . '/*.gz');
                $stats['total_archived_files'] = count($archived_files);
                
                $total_size = 0;
                foreach ($archived_files as $file) {
                    if (is_file($file)) {
                        $total_size += filesize($file);
                    }
                }
                $stats['total_archived_size'] = $total_size;
            }
        }
        
        return $stats;
    }
    
    /**
     * Force run cleanup (bypasses throttling)
     */
    public function force_cleanup() {
        // Clear throttling transient
        delete_transient(self::CLEANUP_TRANSIENT_KEY);
        
        // Run cleanup
        self::run_automated_cleanup();
        
        return true;
    }
    
    /**
     * Clear archived logs
     */
    public function clear_archived_logs() {
        if (!$this->log_directory) {
            return 0;
        }
        
        $archive_dir = $this->log_directory . '/archived-logs';
        if (!is_dir($archive_dir)) {
            return 0;
        }
        
        $archived_files = glob($archive_dir . '/*.gz');
        $deleted = 0;
        
        foreach ($archived_files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Get time until next automated cleanup
     */
    public function seconds_until_next_cleanup() {
        $last_cleanup = get_transient('alo_log_cleanup_last_run');
        if ($last_cleanup === false) {
            return 0;
        }
        
        $cleanup_interval = 6 * HOUR_IN_SECONDS; // 6 hours
        $next_cleanup = $last_cleanup + $cleanup_interval;
        $current_time = time();
        
        return max(0, $next_cleanup - $current_time);
    }
    
    /**
     * Get log directory path for external access
     */
    public function get_log_directory() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/attachment-lookup-logs';
    }
} 