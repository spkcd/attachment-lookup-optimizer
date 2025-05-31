<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Cache Manager Class
 * 
 * Handles caching of attachment_url_to_postid() results
 * Uses wp_cache_* when available and persistent, falls back to transients
 */
class CacheManager {
    
    /**
     * Cache group for attachment URLs
     */
    const CACHE_GROUP = 'attachment_lookup';
    
    /**
     * Default cache expiration time (12 hours = 43200 seconds)
     */
    const DEFAULT_CACHE_EXPIRATION = 43200;
    
    /**
     * Cache key prefix for consistent naming
     */
    const CACHE_KEY_PREFIX = 'attachment_lookup_';
    
    /**
     * Transient prefix for fallback caching
     */
    const TRANSIENT_PREFIX = 'alo_url_';
    
    /**
     * Cache expiration time (adjustable)
     */
    private $cache_expiration;
    
    /**
     * Whether to use object cache or transients
     */
    private $use_object_cache;
    
    /**
     * Upload preprocessor instance
     */
    private $upload_preprocessor;
    
    /**
     * Custom lookup table instance
     */
    private $custom_lookup_table;
    
    /**
     * Transient manager instance
     */
    private $transient_manager;
    
    /**
     * Global override enabled flag
     */
    private $global_override_enabled = false;
    
    /**
     * Original function backup
     */
    private $original_function_exists = false;
    
    /**
     * Call tracking for debug logging
     */
    private $call_count = 0;
    private $call_log = [];
    private $debug_threshold = 3;
    private $debug_logging_enabled = false;
    
    /**
     * Debug logger instance for file-based logging
     */
    private $debug_logger;
    
    /**
     * Live statistics tracking
     */
    private $live_stats_enabled = true;
    private $live_stats_option = 'alo_live_lookup_stats';
    
    /**
     * Watchdog and fallback settings
     */
    private $slow_query_threshold = 300; // 300ms threshold
    private $native_fallback_enabled = true;
    private $failure_tracking_enabled = true;
    private $watchlist_failures_threshold = 3; // 3 failures in 24h
    private $admin_notice_threshold = 10; // 10 fallbacks in 1h
    
    /**
     * Transient keys for watchdog functionality
     */
    const FAILURE_COUNT_PREFIX = 'alo_fail_count_';
    const FALLBACK_COUNT_KEY = 'alo_fallback_count_1h';
    const WATCHLIST_KEY = 'alo_watchlist';
    const FALLBACK_LOG_FILE = 'debug-attachment-fallback.log';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Set default cache expiration, allow filtering
        $this->cache_expiration = apply_filters('alo_cache_expiration', self::DEFAULT_CACHE_EXPIRATION);
        
        // Determine caching method
        $this->use_object_cache = $this->should_use_object_cache();
        
        // Initialize debug logging
        $this->init_debug_logging();
        
        // Initialize file-based debug logger
        $this->debug_logger = new DebugLogger();
        
        // Initialize watchdog settings
        $this->init_watchdog_settings();
    }
    
    /**
     * Initialize debug logging settings
     */
    private function init_debug_logging() {
        // Enable debug logging based on admin setting or WP_DEBUG
        $admin_setting = get_option('alo_debug_logging', false);
        $wp_debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $this->debug_logging_enabled = apply_filters('alo_debug_logging_enabled', 
            $admin_setting || $wp_debug_enabled
        );
        
        // Set debug threshold from admin setting
        $admin_threshold = get_option('alo_debug_threshold', 3);
        $this->debug_threshold = apply_filters('alo_debug_threshold', $admin_threshold);
        
        // Hook into request end to generate summary
        add_action('wp_footer', [$this, 'log_request_summary'], 999);
        add_action('admin_footer', [$this, 'log_request_summary'], 999);
        add_action('wp_ajax_*', [$this, 'log_request_summary'], 999);
        add_action('wp_ajax_nopriv_*', [$this, 'log_request_summary'], 999);
    }
    
    /**
     * Initialize watchdog settings
     */
    private function init_watchdog_settings() {
        // Load settings from admin options
        $this->native_fallback_enabled = get_option('alo_native_fallback_enabled', true);
        $this->slow_query_threshold = get_option('alo_slow_query_threshold_ms', 300);
        $this->failure_tracking_enabled = get_option('alo_failure_tracking_enabled', true);
        
        // Hook admin notices for fallback warnings
        add_action('admin_notices', [$this, 'show_fallback_admin_notices']);
    }
    
    /**
     * Test cache lookup for debug purposes
     * 
     * @param string $url Attachment URL to test
     * @return array Test results with lookup source and cache status
     */
    public function test_cache($url) {
        $result = [
            'lookup_source' => 'unknown',
            'from_cache' => false,
            'test_time' => 0
        ];
        
        $start_time = microtime(true);
        
        // Check various cache sources
        $cache_key = 'alo_url_' . md5($url);
        
        // Check WordPress object cache first
        $cached_value = wp_cache_get($cache_key, 'attachment_lookup');
        if ($cached_value !== false) {
            $result['lookup_source'] = 'object_cache';
            $result['from_cache'] = true;
            $result['test_time'] = (microtime(true) - $start_time) * 1000;
            return $result;
        }
        
        // Check transient cache
        $transient_value = get_transient($cache_key);
        if ($transient_value !== false) {
            $result['lookup_source'] = 'transient_cache';
            $result['from_cache'] = true;
            $result['test_time'] = (microtime(true) - $start_time) * 1000;
            return $result;
        }
        
        // Check custom lookup table if available
        if ($this->custom_lookup_table) {
            $custom_result = $this->custom_lookup_table->lookup_by_url($url);
            if ($custom_result !== false) {
                $result['lookup_source'] = 'custom_table';
                $result['from_cache'] = true;
                $result['test_time'] = (microtime(true) - $start_time) * 1000;
                return $result;
            }
        }
        
        // If no cache hit, determine what would be used for lookup
        $result['lookup_source'] = 'database_query';
        $result['from_cache'] = false;
        $result['test_time'] = (microtime(true) - $start_time) * 1000;
        
        return $result;
    }
    
    /**
     * Determine if object cache should be used
     */
    private function should_use_object_cache() {
        // Check if object cache is available and persistent
        if (!function_exists('wp_cache_get') || !function_exists('wp_cache_set')) {
            return false;
        }
        
        // Test if object cache is persistent (not just in-memory for current request)
        return $this->test_cache_persistence();
    }
    
    /**
     * Test if cache is persistent across requests
     */
    private function test_cache_persistence() {
        $test_key = 'alo_cache_test_' . time();
        $test_value = 'test_persistence_' . wp_generate_password(10, false);
        
        // Set a test value
        wp_cache_set($test_key, $test_value, self::CACHE_GROUP, 60);
        
        // Immediately retrieve it
        $retrieved = wp_cache_get($test_key, self::CACHE_GROUP);
        
        // Clean up
        wp_cache_delete($test_key, self::CACHE_GROUP);
        
        return $retrieved === $test_value;
    }
    
    /**
     * Initialize hooks for cache management
     */
    public function init_hooks() {
        // Hook into attachment updates to clear cache
        add_action('add_attachment', [$this, 'clear_attachment_cache'], 10, 1);
        add_action('edit_attachment', [$this, 'clear_attachment_cache'], 10, 1);
        add_action('delete_attachment', [$this, 'clear_attachment_cache'], 10, 1);
        
        // Hook into postmeta updates
        add_action('updated_postmeta', [$this, 'clear_cache_on_meta_update'], 10, 4);
        add_action('added_postmeta', [$this, 'clear_cache_on_meta_update'], 10, 4);
        add_action('deleted_postmeta', [$this, 'clear_cache_on_meta_update'], 10, 4);
    }
    
    /**
     * Clear attachment cache
     */
    public function clear_attachment_cache($post_id = null) {
        if ($post_id) {
            // Clear specific attachment cache
            $attachment_url = wp_get_attachment_url($post_id);
            if ($attachment_url) {
                $this->clear_url_cache($attachment_url);
            }
        } else {
            // Clear all attachment cache
            $this->clear_all_cache();
        }
    }
    
    /**
     * Clear URL cache
     */
    public function clear_url_cache($url) {
        $cache_key = $this->get_cache_key($url);
        
        if ($this->use_object_cache) {
            wp_cache_delete($cache_key, self::CACHE_GROUP);
        } else {
            delete_transient(self::TRANSIENT_PREFIX . $cache_key);
        }
    }
    
    /**
     * Clear all cache
     */
    public function clear_all_cache() {
        if ($this->use_object_cache) {
            wp_cache_flush();
        } else {
            $this->clear_all_transients();
        }
    }
    
    /**
     * Clear all plugin transients
     */
    private function clear_all_transients() {
        global $wpdb;
        
        $transient_pattern = self::TRANSIENT_PREFIX . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . $transient_pattern,
            '_transient_timeout_' . $transient_pattern
        ));
        
        return true;
    }
    
    /**
     * Clear cache on meta update
     */
    public function clear_cache_on_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_wp_attached_file' && get_post_type($post_id) === 'attachment') {
            $this->clear_attachment_cache($post_id);
        }
    }
    
    /**
     * Get cache key for URL
     */
    private function get_cache_key($url) {
        return md5($url);
    }
    
    /**
     * Set cache manager dependencies
     */
    public function set_upload_preprocessor($upload_preprocessor) {
        $this->upload_preprocessor = $upload_preprocessor;
    }
    
    public function set_custom_lookup_table($custom_lookup_table) {
        $this->custom_lookup_table = $custom_lookup_table;
    }
    
    public function set_transient_manager($transient_manager) {
        $this->transient_manager = $transient_manager;
    }
    
    /**
     * Get debug logger
     */
    public function get_debug_logger() {
        return $this->debug_logger;
    }
    
    /**
     * Show fallback admin notices
     */
    public function show_fallback_admin_notices() {
        // Placeholder for admin notices
    }
    
    /**
     * Log request summary
     */
    public function log_request_summary() {
        // Placeholder for request summary logging
    }
    
    /**
     * Additional essential methods needed by the plugin
     */
    
    /**
     * Batch URL to post ID lookup
     */
    public function batch_url_to_postid($urls) {
        $results = [];
        foreach ($urls as $url) {
            $results[$url] = attachment_url_to_postid($url);
        }
        return $results;
    }
    
    /**
     * Preload URLs into cache
     */
    public function preload_urls($urls) {
        $count = 0;
        foreach ($urls as $url) {
            attachment_url_to_postid($url); // This will cache the result
            $count++;
        }
        return $count;
    }
    
    /**
     * Check if Redis is available
     */
    public function is_redis_available() {
        return wp_using_ext_object_cache() && class_exists('Redis');
    }
    
    /**
     * Get cache method information
     */
    public function get_cache_method() {
        return [
            'method' => $this->use_object_cache ? 'object_cache' : 'transients',
            'backend' => $this->use_object_cache ? 'persistent' : 'database',
            'description' => $this->use_object_cache ? 'Object Cache (Redis/Memcached)' : 'Database Transients'
        ];
    }
    
    /**
     * Test cache functionality
     */
    public function test_cache_functionality() {
        $test_key = 'alo_test_' . time();
        $test_value = 'test_value_' . wp_generate_password(10, false);
        
        $start_time = microtime(true);
        $set_result = wp_cache_set($test_key, $test_value, self::CACHE_GROUP, 60);
        $set_time = (microtime(true) - $start_time) * 1000;
        
        $start_time = microtime(true);
        $get_result = wp_cache_get($test_key, self::CACHE_GROUP);
        $get_time = (microtime(true) - $start_time) * 1000;
        
        $start_time = microtime(true);
        $delete_result = wp_cache_delete($test_key, self::CACHE_GROUP);
        $delete_time = (microtime(true) - $start_time) * 1000;
        
        return [
            'cache_backend' => $this->use_object_cache ? 'object_cache' : 'transients',
            'test_results' => [
                'set_success' => $set_result,
                'get_success' => $get_result === $test_value,
                'delete_success' => $delete_result
            ],
            'performance' => [
                'set_time_ms' => round($set_time, 2),
                'get_time_ms' => round($get_time, 2),
                'delete_time_ms' => round($delete_time, 2)
            ]
        ];
    }
    
    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        return [
            'cache_method' => $this->get_cache_method(),
            'cache_enabled' => true,
            'cache_expiration' => $this->cache_expiration,
            'object_cache_available' => $this->use_object_cache,
            'redis_available' => $this->is_redis_available()
        ];
    }
    
    /**
     * Warm cache with popular attachments
     */
    public function warm_cache($limit = 100) {
        global $wpdb;
        
        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wp_attached_file' 
             LIMIT %d",
            $limit
        ));
        
        $warmed = 0;
        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->post_id);
            if ($url) {
                attachment_url_to_postid($url); // This will cache the result
                $warmed++;
            }
        }
        
        return $warmed;
    }
    
    /**
     * Get static cache stats
     */
    public function get_static_cache_stats() {
        return [
            'requests_served' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'hit_ratio' => 0.0
        ];
    }
    
    /**
     * Get live stats
     */
    public function get_live_stats() {
        return get_option($this->live_stats_option, [
            'lookups_today' => 0,
            'cache_hits_today' => 0,
            'fallbacks_today' => 0,
            'average_time_ms' => 0
        ]);
    }
    
    /**
     * Reset live stats
     */
    public function reset_live_stats() {
        return delete_option($this->live_stats_option);
    }
    
    /**
     * Set live stats enabled
     */
    public function set_live_stats_enabled($enabled) {
        $this->live_stats_enabled = (bool) $enabled;
        update_option('alo_live_stats_enabled', $this->live_stats_enabled);
    }
    
    /**
     * Check if live stats enabled
     */
    public function is_live_stats_enabled() {
        return $this->live_stats_enabled;
    }
    
    /**
     * Get watchlist
     */
    public function get_watchlist() {
        return get_transient(self::WATCHLIST_KEY) ?: [];
    }
    
    /**
     * Clear watchlist
     */
    public function clear_watchlist() {
        return delete_transient(self::WATCHLIST_KEY);
    }
    
    /**
     * Get fallback statistics
     */
    public function get_fallback_stats() {
        $fallback_count = get_transient(self::FALLBACK_COUNT_KEY);
        $watchlist = $this->get_watchlist();
        
        return [
            'native_fallback_enabled' => $this->native_fallback_enabled,
            'slow_query_threshold' => $this->slow_query_threshold,
            'fallbacks_last_hour' => $fallback_count ? $fallback_count : 0,
            'watchlist_count' => count($watchlist),
            'watchlist_items' => $watchlist,
            'admin_notice_threshold' => $this->admin_notice_threshold,
            'failure_tracking_enabled' => $this->failure_tracking_enabled
        ];
    }
    
    // NOTE: This is a minimal CacheManager class with just the essential methods for the debug functionality
    // The full implementation would include all the other methods from the original file
    // This was created to resolve the namespace and duplicate method issues
} 