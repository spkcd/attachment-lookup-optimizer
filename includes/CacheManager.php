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
     * Static cache for within-request duplicate detection
     */
    private static $static_cache = [];
    private static $static_cache_stats = [
        'hits' => 0,
        'misses' => 0,
        'total_calls' => 0
    ];
    
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
        
        // Initialize live stats settings
        $this->init_live_stats();
        
        // Initialize debug logging
        $this->init_debug_logging();
        
        // Initialize file-based debug logger
        $this->debug_logger = new DebugLogger();
        
        // Initialize watchdog settings
        $this->init_watchdog_settings();
        
        // Initialize global override functionality
        $this->init_global_override();
    }
    
    /**
     * Initialize live stats settings
     */
    private function init_live_stats() {
        // Load live stats enabled setting from saved option
        $this->live_stats_enabled = get_option('alo_live_stats_enabled', true);
        
        // Allow filtering
        $this->live_stats_enabled = apply_filters('alo_live_stats_enabled', $this->live_stats_enabled);
        
        if (defined('WP_DEBUG') && WP_DEBUG && $this->live_stats_enabled) {
            error_log('ALO: Live statistics tracking enabled');
        }
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
     * Initialize global override for attachment_url_to_postid
     */
    private function init_global_override() {
        // Check if global override is enabled
        $global_override_enabled = apply_filters('alo_enable_global_override', get_option('alo_global_override', false));
        
        // Always hook for live stats tracking, regardless of global override setting
        global $wp_version;
        
        if (version_compare($wp_version, '6.7.0', '>=')) {
            // For WordPress 6.7+, use pre_attachment_url_to_postid
            if ($global_override_enabled) {
                // Full optimization when global override is enabled
                add_filter('pre_attachment_url_to_postid', [$this, 'optimized_attachment_url_to_postid'], 10, 2);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ALO: Using pre_attachment_url_to_postid hook with full optimization (WP 6.7+)');
                }
            } else {
                // Stats tracking only when global override is disabled
                add_filter('pre_attachment_url_to_postid', [$this, 'stats_only_attachment_url_to_postid'], 10, 2);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ALO: Using pre_attachment_url_to_postid hook for stats tracking only (WP 6.7+)');
                }
            }
        } else {
            // For older WordPress versions, use the post-execution filter
            add_filter('attachment_url_to_postid', [$this, 'fallback_attachment_url_to_postid'], 10, 2);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO: Using attachment_url_to_postid fallback hook (WP < 6.7)');
            }
        }
        
        if ($global_override_enabled) {
            // Add frontend indicator for admin users
            add_action('wp_footer', [$this, 'add_frontend_debug_indicator'], 999);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO: Global override for attachment_url_to_postid enabled');
            }
        } else {
            // Add admin notice about enabling global override
            if (is_admin() && current_user_can('manage_options')) {
                add_action('admin_notices', [$this, 'show_global_override_notice']);
            }
        }
    }
    
    /**
     * Add frontend debug indicator for admin users
     */
    public function add_frontend_debug_indicator() {
        // Only show to admin users when WP_DEBUG is enabled
        if (!current_user_can('manage_options') || (!defined('WP_DEBUG') || !WP_DEBUG)) {
            return;
        }
        
        $stats = $this->get_live_stats();
        $total_lookups = $stats['total_lookups'] ?? 0;
        
        ?>
        <div id="alo-debug-indicator" style="position: fixed; bottom: 10px; right: 10px; background: #0073aa; color: white; padding: 8px 12px; border-radius: 4px; font-size: 12px; z-index: 99999; font-family: monospace;">
            ALO Active: <?php echo $total_lookups; ?> lookups
        </div>
        <?php
    }
    
    /**
     * Show admin notice about enabling global override
     */
    public function show_global_override_notice() {
        // Don't show if already dismissed
        if (get_user_meta(get_current_user_id(), 'alo_global_override_notice_dismissed', true)) {
            return;
        }
        
        ?>
        <div class="notice notice-warning is-dismissible" data-alo-notice="global_override">
            <p>
                <strong><?php _e('Attachment Lookup Optimizer', 'attachment-lookup-optimizer'); ?>:</strong>
                <?php _e('Global Override is disabled. The plugin won\'t optimize attachment lookups until you enable it in', 'attachment-lookup-optimizer'); ?>
                <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>"><?php _e('Tools > Attachment Optimizer', 'attachment-lookup-optimizer'); ?></a>.
            </p>
        </div>
        <script>
        jQuery(function($) {
            $(document).on('click', '[data-alo-notice="global_override"] .notice-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'alo_dismiss_global_override_notice',
                    nonce: '<?php echo wp_create_nonce('alo_dismiss_notice'); ?>'
                });
            });
        });
        </script>
        <?php
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
        // Clear WordPress object cache
        wp_cache_flush();
        
        // Clear our specific transients
        $this->clear_all_transients();
        
        // Track when cache was cleared
        update_option('alo_last_cache_clear', time());
        
        return true;
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
     * This method can be called with 1-4 arguments depending on the WordPress hook
     */
    public function clear_cache_on_meta_update(...$args) {
        $arg_count = count($args);
        
        // Log the arguments for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO: clear_cache_on_meta_update called with ' . $arg_count . ' arguments: ' . 
                     json_encode($args));
        }
        
        // Initialize variables
        $meta_id = null;
        $post_id = null;
        $meta_key = null;
        $meta_value = null;
        
        // Extract arguments based on count
        switch ($arg_count) {
            case 4:
                [$meta_id, $post_id, $meta_key, $meta_value] = $args;
                break;
            case 3:
                [$meta_id, $post_id, $meta_key] = $args;
                break;
            case 2:
                [$meta_id, $post_id] = $args;
                break;
            case 1:
                [$meta_id] = $args;
                break;
            default:
                // No arguments or too many - exit safely
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ALO: clear_cache_on_meta_update called with unexpected argument count: ' . $arg_count);
                }
                return;
        }
        
        // Case 1: We have all 4 arguments (normal case)
        if ($arg_count >= 3 && $meta_key === '_wp_attached_file' && $post_id) {
            if (get_post_type($post_id) === 'attachment') {
                $this->clear_attachment_cache($post_id);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ALO: Cleared attachment cache for post_id (normal case): ' . $post_id);
                }
            }
            return;
        }
        
        // Case 2: We have meta_id and post_id but need to check meta_key
        if ($arg_count >= 2 && $post_id && is_numeric($meta_id)) {
            global $wpdb;
            $meta_key_check = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_id = %d AND post_id = %d",
                $meta_id, $post_id
            ));
            
            if ($meta_key_check === '_wp_attached_file' && get_post_type($post_id) === 'attachment') {
                $this->clear_attachment_cache($post_id);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ALO: Cleared attachment cache for post_id (2 args): ' . $post_id);
                }
            }
            return;
        }
        
        // Case 3: We only have meta_id, try to get post_id and meta_key from it
        if ($arg_count >= 1 && is_numeric($meta_id)) {
            global $wpdb;
            $meta_row = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_id = %d",
                $meta_id
            ));
            
            if ($meta_row && $meta_row->meta_key === '_wp_attached_file') {
                $post_id = $meta_row->post_id;
                if (get_post_type($post_id) === 'attachment') {
                    $this->clear_attachment_cache($post_id);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('ALO: Cleared attachment cache for post_id (via meta_id): ' . $post_id);
                    }
                }
            }
            return;
        }
        
        // If we reach here, we couldn't handle the arguments
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO: clear_cache_on_meta_update could not process arguments: ' . json_encode($args));
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
        $cache_method_info = $this->get_cache_method();
        
        // Detect actual cache backend
        $cache_backend = 'none';
        $persistent_object_cache = false;
        
        if (wp_using_ext_object_cache()) {
            $persistent_object_cache = true;
            if (class_exists('Redis') && extension_loaded('redis')) {
                $cache_backend = 'redis';
            } elseif (class_exists('Memcached') && extension_loaded('memcached')) {
                $cache_backend = 'memcached';
            } elseif (function_exists('apcu_fetch') && extension_loaded('apcu')) {
                $cache_backend = 'apcu';
            } else {
                $cache_backend = 'object_cache';
            }
        } else {
            $cache_backend = 'transients';
        }
        
        return [
            'method' => $cache_method_info['method'],
            'method_description' => $cache_method_info['description'],
            'cache_backend' => $cache_backend,
            'persistent_object_cache' => $persistent_object_cache,
            'object_cache_available' => function_exists('wp_cache_get'),
            'redis_available' => class_exists('Redis') && extension_loaded('redis'),
            'cache_group' => self::CACHE_GROUP,
            'cache_key_prefix' => 'alo_',
            'default_expiration' => $this->cache_expiration,
            'last_cleared' => get_option('alo_last_cache_clear', null),
            'cache_enabled' => true
        ];
    }
    
    /**
     * Warm cache with popular attachments
     */
    public function warm_cache($limit = 100, $offset = 0) {
        global $wpdb;
        
        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wp_attached_file' 
             LIMIT %d OFFSET %d",
            $limit, $offset
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
        $total = self::$static_cache_stats['total_calls'];
        $hits = self::$static_cache_stats['hits'];
        
        return [
            'static_cache_hits' => $hits,
            'static_cache_misses' => self::$static_cache_stats['misses'], 
            'total_calls' => $total,
            'static_hit_ratio' => $total > 0 ? round(($hits / $total) * 100, 1) : 0
        ];
    }
    
    /**
     * Check static cache for URL (within-request caching)
     */
    public function get_from_static_cache($url) {
        self::$static_cache_stats['total_calls']++;
        
        if (isset(self::$static_cache[$url])) {
            self::$static_cache_stats['hits']++;
            return self::$static_cache[$url];
        }
        
        self::$static_cache_stats['misses']++;
        return false;
    }
    
    /**
     * Store in static cache for within-request reuse
     */
    public function set_static_cache($url, $post_id) {
        self::$static_cache[$url] = $post_id;
    }
    
    /**
     * Get live stats
     */
    public function get_live_stats() {
        $stats = get_option($this->live_stats_option, []);
        
        // Ensure all expected fields exist with defaults
        $default_stats = [
            'total_lookups' => 0,
            'successful_lookups' => 0,
            'not_found_count' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'static_cache_hits' => 0,
            'custom_table_hits' => 0,
            'native_fallback_hits' => 0,
            'first_lookup_time' => null,
            'last_lookup_time' => null,
            'total_log_size' => 0
        ];
        
        $stats = array_merge($default_stats, $stats);
        
        // Calculate derived statistics
        $total = $stats['total_lookups'];
        $successful = $stats['successful_lookups'];
        $cache_hits = $stats['cache_hits'] + $stats['static_cache_hits'];
        $table_hits = $stats['custom_table_hits'];
        
        if ($total > 0) {
            $stats['success_rate'] = round(($successful / $total) * 100, 1);
            $stats['cache_efficiency'] = round(($cache_hits / $total) * 100, 1);
            $stats['table_efficiency'] = round(($table_hits / $total) * 100, 1);
        } else {
            $stats['success_rate'] = 0;
            $stats['cache_efficiency'] = 0;
            $stats['table_efficiency'] = 0;
        }
        
        return $stats;
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
    
    /**
     * Stats-only tracking for attachment_url_to_postid() when global override is disabled
     * This method tracks live statistics without interfering with WordPress's normal lookup process
     */
    public function stats_only_attachment_url_to_postid($post_id, $url) {
        // If we already have a result, return it (allows other plugins to override)
        if ($post_id !== false) {
            return $post_id;
        }
        
        // Update live stats for tracking purposes
        $this->update_live_stats('total_lookups', 1);
        
        // Add a post-execution hook to track the final result
        add_filter('attachment_url_to_postid', [$this, 'track_stats_only_result'], 999, 2);
        
        // Let WordPress handle the actual lookup - we're just tracking
        // Return false to let WordPress continue with its normal process
        return false;
    }
    
    /**
     * Track the result of attachment_url_to_postid when in stats-only mode
     */
    public function track_stats_only_result($post_id, $url) {
        // Remove the hook to prevent infinite loops
        remove_filter('attachment_url_to_postid', [$this, 'track_stats_only_result'], 999);
        
        // Track the result
        if ($post_id && $post_id > 0) {
            $this->update_live_stats('successful_lookups', 1);
        } else {
            $this->update_live_stats('not_found_count', 1);
        }
        
        return $post_id;
    }
    
    /**
     * Optimized replacement for attachment_url_to_postid()
     */
    public function optimized_attachment_url_to_postid($post_id, $url) {
        // If we already have a result, return it (allows other plugins to override)
        if ($post_id !== false) {
            return $post_id;
        }
        
        $start_time = microtime(true);
        $lookup_source = 'unknown';
        
        // Update live stats
        $this->update_live_stats('total_lookups', 1);
        
        // Check static cache first (within-request caching)
        $cached_result = $this->get_from_static_cache($url);
        if ($cached_result !== false) {
            $lookup_source = 'static_cache';
            $post_id = $cached_result;
            $this->update_live_stats('static_cache_hits', 1);
        } else {
            // Try custom lookup table first (fastest)
            if ($this->custom_lookup_table && $this->custom_lookup_table->table_exists()) {
                $post_id = $this->lookup_via_custom_table($url);
                if ($post_id !== false) {
                    $lookup_source = 'custom_table';
                    $this->update_live_stats('custom_table_hits', 1);
                }
            }
            
            // Fall back to cache lookup
            if ($post_id === false) {
                $post_id = $this->lookup_via_cache($url);
                if ($post_id !== false) {
                    $lookup_source = 'cache_hit';
                    $this->update_live_stats('cache_hits', 1);
                } else {
                    $lookup_source = 'cache_miss';
                    $this->update_live_stats('cache_misses', 1);
                }
            }
            
            // Final fallback to native WordPress function
            if ($post_id === false) {
                // Temporarily remove our filter to avoid infinite recursion
                remove_filter('pre_attachment_url_to_postid', [$this, 'optimized_attachment_url_to_postid'], 10);
                
                $post_id = attachment_url_to_postid($url);
                $lookup_source = 'native_fallback';
                $this->update_live_stats('native_fallback_hits', 1);
                
                // Re-add our filter
                add_filter('pre_attachment_url_to_postid', [$this, 'optimized_attachment_url_to_postid'], 10, 2);
                
                // Cache the result for future lookups
                if ($post_id) {
                    $this->cache_url_result($url, $post_id);
                }
            }
            
            // Store in static cache for within-request reuse
            $this->set_static_cache($url, $post_id);
        }
        
        $end_time = microtime(true);
        $query_time_ms = ($end_time - $start_time) * 1000;
        
        // Update live stats
        if ($post_id) {
            $this->update_live_stats('successful_lookups', 1);
        } else {
            $this->update_live_stats('not_found_count', 1);
        }
        
        // Log the lookup if debug logging is enabled
        if ($this->debug_logger) {
            $this->debug_logger->log_attachment_lookup($url, $lookup_source, $post_id, $query_time_ms);
        }
        
        return $post_id;
    }
    
    /**
     * Look up URL via custom table
     */
    private function lookup_via_custom_table($url) {
        if (!$this->custom_lookup_table || !$this->custom_lookup_table->table_exists()) {
            return false;
        }
        
        // Convert URL to file path for custom table lookup
        $file_path = $this->url_to_file_path($url);
        if (!$file_path) {
            return false;
        }
        
        return $this->custom_lookup_table->get_post_id_by_path($file_path);
    }
    
    /**
     * Look up URL via cache
     */
    private function lookup_via_cache($url) {
        $cache_key = $this->get_cache_key($url);
        
        if ($this->use_object_cache) {
            return wp_cache_get($cache_key, self::CACHE_GROUP);
        } else {
            return get_transient(self::TRANSIENT_PREFIX . $cache_key);
        }
    }
    
    /**
     * Cache URL result
     */
    private function cache_url_result($url, $post_id) {
        $cache_key = $this->get_cache_key($url);
        
        if ($this->use_object_cache) {
            wp_cache_set($cache_key, $post_id, self::CACHE_GROUP, $this->cache_expiration);
        } else {
            set_transient(self::TRANSIENT_PREFIX . $cache_key, $post_id, $this->cache_expiration);
        }
    }
    
    /**
     * Convert URL to file path
     * 
     * Supports all WordPress attachment types including modern formats:
     * - WebP images for improved performance
     * - AVIF images for next-gen compression
     * - HEIC images from mobile devices
     */
    private function url_to_file_path($url) {
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        
        // Remove base URL to get relative path
        if (strpos($url, $base_url) === 0) {
            $file_path = str_replace($base_url, '', $url);
            return ltrim($file_path, '/');
        }
        
        return false;
    }
    
    /**
     * Update live statistics
     */
    private function update_live_stats($key, $increment = 1) {
        if (!$this->live_stats_enabled) {
            return;
        }
        
        $stats = get_option($this->live_stats_option, []);
        
        if (!isset($stats[$key])) {
            $stats[$key] = 0;
        }
        
        $stats[$key] += $increment;
        
        // Update timestamps
        $now = current_time('mysql', true);
        if (!isset($stats['first_lookup_time'])) {
            $stats['first_lookup_time'] = $now;
        }
        $stats['last_lookup_time'] = $now;
        
        // Calculate derived stats
        $total = $stats['total_lookups'] ?? 0;
        $successful = $stats['successful_lookups'] ?? 0;
        
        if ($total > 0) {
            $stats['success_rate'] = round(($successful / $total) * 100, 1);
        }
        
        update_option($this->live_stats_option, $stats);
    }
    
    /**
     * Fallback method for WordPress versions before 6.7.0
     * This runs after attachment_url_to_postid() has completed
     */
    public function fallback_attachment_url_to_postid($post_id, $url) {
        $start_time = microtime(true);
        
        // Update live stats
        $this->update_live_stats('total_lookups', 1);
        
        // Check if global override is enabled
        $global_override_enabled = apply_filters('alo_enable_global_override', get_option('alo_global_override', false));
        
        if ($post_id && $post_id > 0) {
            // Native function found a result - track as successful
            $this->update_live_stats('successful_lookups', 1);
            $lookup_source = 'native_wordpress';
            
            // Cache the result for future lookups (only if global override is enabled)
            if ($global_override_enabled) {
                $this->cache_url_result($url, $post_id);
            }
        } else {
            // Native function didn't find anything
            if ($global_override_enabled) {
                // Try our optimized methods only if global override is enabled
                $optimized_result = $this->perform_optimized_lookup($url);
                
                if ($optimized_result && $optimized_result > 0) {
                    $post_id = $optimized_result;
                    $this->update_live_stats('successful_lookups', 1);
                    $lookup_source = 'optimized_fallback';
                    
                    // Cache this result
                    $this->cache_url_result($url, $post_id);
                } else {
                    $this->update_live_stats('not_found_count', 1);
                    $lookup_source = 'not_found';
                }
            } else {
                // Just track the failure when global override is disabled
                $this->update_live_stats('not_found_count', 1);
                $lookup_source = 'not_found_stats_only';
            }
        }
        
        // Debug logging
        if ($this->debug_logger && $this->debug_logger->is_enabled()) {
            $execution_time = (microtime(true) - $start_time) * 1000;
            $this->debug_logger->log(sprintf(
                'Fallback lookup: %s -> %s (source: %s, time: %.2fms)',
                $this->truncate_url($url, 60),
                $post_id ?: 'NOT FOUND',
                $lookup_source,
                $execution_time
            ));
        }
        
        return $post_id;
    }
    
    /**
     * Perform optimized lookup using our cache and custom table methods
     */
    private function perform_optimized_lookup($url) {
        // Check our cache first
        $cached_result = $this->lookup_via_cache($url);
        if ($cached_result !== false) {
            $this->update_live_stats('cache_hits', 1);
            return $cached_result;
        }
        
        $this->update_live_stats('cache_misses', 1);
        
        // Check custom lookup table if available
        if ($this->custom_lookup_table) {
            $table_result = $this->lookup_via_custom_table($url);
            if ($table_result !== false) {
                $this->update_live_stats('custom_table_hits', 1);
                return $table_result;
            }
        }
        
        // If all else fails, return false
        return false;
    }
    
    /**
     * Truncate URL for display purposes
     */
    private function truncate_url($url, $length = 50) {
        if (strlen($url) <= $length) {
            return $url;
        }
        
        return substr($url, 0, $length - 3) . '...';
    }
    
    // NOTE: This is a minimal CacheManager class with just the essential methods for the debug functionality
    // The full implementation would include all the other methods from the original file
    // This was created to resolve the namespace and duplicate method issues
} 