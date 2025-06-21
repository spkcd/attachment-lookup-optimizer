<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Database Manager Class
 * 
 * Handles database optimization including index creation
 */
class DatabaseManager {
    
    /**
     * Index name for postmeta optimization
     */
    const POSTMETA_INDEX_NAME = 'alo_meta_key_value_idx';
    
    /**
     * Index name for attached file optimization
     */
    const ATTACHED_FILE_INDEX_NAME = 'idx_attached_file';
    
    /**
     * Track database query statistics
     */
    private $query_stats = [
        'total_queries' => 0,
        'postmeta_queries' => 0,
        'attached_file_queries' => 0,
        'slow_queries' => 0,
        'total_query_time' => 0,
        'slow_query_time' => 0
    ];
    
    /**
     * Slow query threshold in seconds (0.3s = 300ms)
     */
    private $slow_query_threshold = 0.3;
    
    /**
     * Whether expensive query logging is enabled
     */
    private $expensive_query_logging = false;
    
    /**
     * Query start times for monitoring
     */
    private $query_start_times = [];
    
    /**
     * Current query being monitored
     */
    private $current_query = '';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_query_monitoring();
        
        // Only clear stats cache on significant changes that affect counts
        // Note: add_attachment is handled by UploadPreprocessor
        add_action('delete_attachment', [$this, 'clear_stats_cache']);
        
        // Clear cache when plugin is updated (attachment counts may change due to processing)
        add_action('upgrader_process_complete', [$this, 'maybe_clear_cache_on_plugin_update'], 10, 2);
    }
    
    /**
     * Initialize query monitoring
     */
    private function init_query_monitoring() {
        // Enable expensive query logging based on admin setting
        $this->expensive_query_logging = apply_filters('alo_expensive_query_logging_enabled', 
            get_option('alo_expensive_query_logging', false)
        );
        
        // Set custom threshold - use milliseconds setting and convert to seconds
        $threshold_ms = get_option('alo_slow_query_threshold_ms', 300);
        $this->slow_query_threshold = $threshold_ms / 1000.0; // Convert ms to seconds
        
        // Apply filters
        $this->slow_query_threshold = apply_filters('alo_slow_query_threshold', 
            $this->slow_query_threshold
        );
        
        if ($this->expensive_query_logging) {
            $this->init_query_hooks();
        }
    }
    
    /**
     * Initialize query monitoring hooks
     */
    private function init_query_hooks() {
        // Hook into WordPress database queries
        add_filter('query', [$this, 'monitor_query_start'], 10, 1);
        add_filter('posts_results', [$this, 'monitor_query_end'], 10, 2);
        add_action('shutdown', [$this, 'log_query_summary']);
        
        // Hook into wpdb queries for more detailed monitoring
        add_action('wp_loaded', [$this, 'setup_wpdb_monitoring']);
    }
    
    /**
     * Setup WordPress database monitoring
     */
    public function setup_wpdb_monitoring() {
        global $wpdb;
        
        if (!$this->expensive_query_logging) {
            return;
        }
        
        // Add query logging to wpdb if not already enabled
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        
        // Hook into wpdb query execution
        add_filter('wpdb_profiling_sql', [$this, 'profile_sql_query'], 10, 2);
    }
    
    /**
     * Monitor query start
     */
    public function monitor_query_start($query) {
        if (!$this->expensive_query_logging) {
            return $query;
        }
        
        $this->current_query = $query;
        $this->query_start_times[md5($query)] = microtime(true);
        
        return $query;
    }
    
    /**
     * Monitor query end
     */
    public function monitor_query_end($posts, $query) {
        if (!$this->expensive_query_logging || empty($this->current_query)) {
            return $posts;
        }
        
        $query_hash = md5($this->current_query);
        if (isset($this->query_start_times[$query_hash])) {
            $execution_time = microtime(true) - $this->query_start_times[$query_hash];
            $this->analyze_query_performance($this->current_query, $execution_time);
            unset($this->query_start_times[$query_hash]);
        }
        
        return $posts;
    }
    
    /**
     * Profile SQL queries using wpdb profiling
     */
    public function profile_sql_query($query, $execution_time) {
        if (!$this->expensive_query_logging) {
            return $query;
        }
        
        $this->analyze_query_performance($query, $execution_time);
        
        return $query;
    }
    
    /**
     * Analyze query performance and log slow queries
     */
    private function analyze_query_performance($query, $execution_time) {
        $this->query_stats['total_queries']++;
        $this->query_stats['total_query_time'] += $execution_time;
        
        // Check if this is a postmeta query
        if (strpos($query, 'postmeta') !== false) {
            $this->query_stats['postmeta_queries']++;
            
            // Check if it's specifically querying _wp_attached_file
            if (strpos($query, '_wp_attached_file') !== false) {
                $this->query_stats['attached_file_queries']++;
                
                // Log if it's a slow query
                if ($execution_time > $this->slow_query_threshold) {
                    $this->log_slow_attached_file_query($query, $execution_time);
                }
            }
        }
        
        // Track all slow queries regardless of type
        if ($execution_time > $this->slow_query_threshold) {
            $this->query_stats['slow_queries']++;
            $this->query_stats['slow_query_time'] += $execution_time;
        }
    }
    
    /**
     * Log slow _wp_attached_file queries
     */
    private function log_slow_attached_file_query($query, $execution_time) {
        $execution_ms = round($execution_time * 1000, 2);
        
        // Parse query to extract useful information
        $query_info = $this->parse_attached_file_query($query);
        
        // Build detailed log message
        $log_message = sprintf(
            'ALO SLOW QUERY [%.2fms]: %s | Type: %s | Rows: %s | Index: %s',
            $execution_ms,
            $query_info['type'],
            $query_info['operation'],
            $query_info['estimated_rows'],
            $query_info['index_used'] ? 'YES' : 'NO'
        );
        
        // Add query details if in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message .= ' | Query: ' . $this->sanitize_query_for_log($query);
        }
        
        error_log($log_message);
        
        // Hook for custom handling
        do_action('alo_slow_attached_file_query', $query, $execution_time, $query_info);
        
        // Store for admin dashboard
        $this->store_slow_query_sample($query, $execution_time, $query_info);
    }
    
    /**
     * Parse _wp_attached_file query to extract information
     */
    private function parse_attached_file_query($query) {
        $info = [
            'type' => 'UNKNOWN',
            'operation' => 'SELECT',
            'estimated_rows' => 'unknown',
            'index_used' => false,
            'has_where_clause' => false,
            'has_like_clause' => false
        ];
        
        // Determine operation type
        if (stripos($query, 'SELECT') === 0) {
            $info['operation'] = 'SELECT';
        } elseif (stripos($query, 'UPDATE') === 0) {
            $info['operation'] = 'UPDATE';
        } elseif (stripos($query, 'DELETE') === 0) {
            $info['operation'] = 'DELETE';
        }
        
        // Check for WHERE clauses
        if (stripos($query, 'WHERE') !== false) {
            $info['has_where_clause'] = true;
        }
        
        // Check for LIKE clauses (usually slower)
        if (stripos($query, 'LIKE') !== false) {
            $info['has_like_clause'] = true;
            $info['type'] = 'LIKE_SEARCH';
        }
        
        // Check for exact meta_key and meta_value matches (should use index)
        if (preg_match('/meta_key\s*=\s*["\']_wp_attached_file["\']/', $query) &&
            preg_match('/meta_value\s*=\s*/', $query)) {
            $info['index_used'] = true;
            $info['type'] = 'EXACT_MATCH';
        }
        
        // Check for meta_value LIKE patterns
        if (preg_match('/meta_value\s+LIKE\s+/', $query)) {
            $info['type'] = 'PATTERN_MATCH';
            $info['index_used'] = false; // LIKE usually can't use index efficiently
        }
        
        // Estimate query complexity
        if (stripos($query, 'JOIN') !== false) {
            $info['type'] .= '_WITH_JOIN';
        }
        
        if (stripos($query, 'ORDER BY') !== false) {
            $info['type'] .= '_WITH_ORDER';
        }
        
        return $info;
    }
    
    /**
     * Sanitize query for logging (remove sensitive data)
     */
    private function sanitize_query_for_log($query) {
        // Truncate very long queries
        if (strlen($query) > 500) {
            $query = substr($query, 0, 500) . '... [TRUNCATED]';
        }
        
        // Remove potential sensitive file paths but keep structure
        $query = preg_replace('/uploads\/[0-9]+\/[0-9]+\/[^\'"`\s]+/', 'uploads/YYYY/MM/filename', $query);
        
        return $query;
    }
    
    /**
     * Store slow query sample for admin dashboard
     */
    private function store_slow_query_sample($query, $execution_time, $query_info) {
        $samples = get_transient('alo_slow_query_samples') ?: [];
        
        // Keep only last 10 samples
        if (count($samples) >= 10) {
            array_shift($samples);
        }
        
        $samples[] = [
            'timestamp' => time(),
            'execution_time' => $execution_time,
            'query_type' => $query_info['type'],
            'operation' => $query_info['operation'],
            'index_used' => $query_info['index_used'],
            'query_hash' => md5($query),
            'sanitized_query' => $this->sanitize_query_for_log($query)
        ];
        
        set_transient('alo_slow_query_samples', $samples, HOUR_IN_SECONDS);
    }
    
    /**
     * Log query performance summary
     */
    public function log_query_summary() {
        if (!$this->expensive_query_logging || $this->query_stats['total_queries'] === 0) {
            return;
        }
        
        $current_url = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $avg_query_time = $this->query_stats['total_query_time'] / $this->query_stats['total_queries'];
        
        $summary = sprintf(
            'ALO QUERY SUMMARY: %d total | %d postmeta | %d attached_file | %d slow (%.1f%%) | %.2fms avg | %.2fms slow avg | URL: %s',
            $this->query_stats['total_queries'],
            $this->query_stats['postmeta_queries'],
            $this->query_stats['attached_file_queries'],
            $this->query_stats['slow_queries'],
            $this->query_stats['total_queries'] > 0 ? ($this->query_stats['slow_queries'] / $this->query_stats['total_queries']) * 100 : 0,
            $avg_query_time * 1000,
            $this->query_stats['slow_queries'] > 0 ? ($this->query_stats['slow_query_time'] / $this->query_stats['slow_queries']) * 1000 : 0,
            $current_url
        );
        
        // Only log if we have slow queries or debug is enabled
        if ($this->query_stats['slow_queries'] > 0 || (defined('WP_DEBUG') && WP_DEBUG)) {
            error_log($summary);
        }
        
        // Hook for custom handling
        do_action('alo_query_performance_summary', $this->query_stats, $current_url);
    }
    
    /**
     * Get query monitoring statistics
     */
    public function get_query_stats() {
        $stats = $this->query_stats;
        
        // Add calculated metrics
        $stats['expensive_query_logging'] = $this->expensive_query_logging;
        $stats['slow_query_threshold'] = $this->slow_query_threshold;
        $stats['slow_query_threshold_ms'] = round($this->slow_query_threshold * 1000);
        
        if ($stats['total_queries'] > 0) {
            $stats['avg_query_time'] = $stats['total_query_time'] / $stats['total_queries'];
            $stats['avg_query_time_ms'] = round($stats['avg_query_time'] * 1000, 2);
            $stats['slow_query_percentage'] = round(($stats['slow_queries'] / $stats['total_queries']) * 100, 2);
        } else {
            $stats['avg_query_time'] = 0;
            $stats['avg_query_time_ms'] = 0;
            $stats['slow_query_percentage'] = 0;
        }
        
        if ($stats['slow_queries'] > 0) {
            $stats['avg_slow_query_time'] = $stats['slow_query_time'] / $stats['slow_queries'];
            $stats['avg_slow_query_time_ms'] = round($stats['avg_slow_query_time'] * 1000, 2);
        } else {
            $stats['avg_slow_query_time'] = 0;
            $stats['avg_slow_query_time_ms'] = 0;
        }
        
        // Add slow query samples
        $stats['slow_query_samples'] = get_transient('alo_slow_query_samples') ?: [];
        
        return $stats;
    }
    
    /**
     * Enable/disable expensive query logging
     */
    public function set_expensive_query_logging($enabled) {
        $this->expensive_query_logging = (bool) $enabled;
        update_option('alo_expensive_query_logging', $this->expensive_query_logging);
        
        if ($this->expensive_query_logging) {
            $this->init_query_hooks();
        }
    }
    
    /**
     * Set slow query threshold
     */
    public function set_slow_query_threshold($seconds) {
        $this->slow_query_threshold = max(0.1, min(5.0, floatval($seconds)));
        update_option('alo_slow_query_threshold', $this->slow_query_threshold);
    }
    
    /**
     * Clear query statistics
     */
    public function clear_query_stats() {
        $this->query_stats = [
            'total_queries' => 0,
            'postmeta_queries' => 0,
            'attached_file_queries' => 0,
            'slow_queries' => 0,
            'total_query_time' => 0,
            'slow_query_time' => 0
        ];
        
        delete_transient('alo_slow_query_samples');
    }
    
    /**
     * Check and create indexes if needed
     */
    public function check_and_create_indexes() {
        // Only run this once per version
        $last_checked_version = get_option('alo_db_checked_version', '0.0.0');
        
        if (version_compare($last_checked_version, ALO_VERSION, '<')) {
            $this->create_postmeta_indexes();
            update_option('alo_db_checked_version', ALO_VERSION);
        }
    }
    
    /**
     * Check and create the attached file index
     * This method is hooked to 'init' with priority 20
     */
    public function check_and_create_attached_file_index() {
        // Only run this once per version for the attached file index
        $last_checked_version = get_option('alo_attached_file_index_checked', '0.0.0');
        
        if (version_compare($last_checked_version, ALO_VERSION, '<')) {
            $this->create_attached_file_index();
            update_option('alo_attached_file_index_checked', ALO_VERSION);
        }
    }
    
    /**
     * Create the attached file index using ALTER TABLE
     */
    public function create_attached_file_index() {
        global $wpdb;
        
        // Check if index already exists
        if ($this->index_exists(self::ATTACHED_FILE_INDEX_NAME)) {
            return true;
        }
        
        // Try dbDelta-safe approach first
        $result = $this->create_attached_file_index_dbdelta_safe();
        
        if ($result) {
            return true;
        }
        
        // Fallback to direct ALTER TABLE query
        return $this->create_attached_file_index_direct();
    }
    
    /**
     * Create attached file index using dbDelta-safe logic
     */
    private function create_attached_file_index_dbdelta_safe() {
        global $wpdb;
        
        // dbDelta approach - create a temporary SQL schema
        $sql = "CREATE TABLE {$wpdb->postmeta} (
            meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL DEFAULT '0',
            meta_key varchar(255) DEFAULT NULL,
            meta_value longtext,
            PRIMARY KEY (meta_id),
            KEY post_id (post_id),
            KEY meta_key (meta_key(191)),
            KEY " . self::ATTACHED_FILE_INDEX_NAME . " (meta_key(191), meta_value(191))
        ) {$wpdb->get_charset_collate()};";
        
        // Include WordPress upgrade functions
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        
        // Try dbDelta (this might not work for adding indexes to existing tables)
        $result = dbDelta($sql);
        
        // Check if the index was actually created
        if ($this->index_exists(self::ATTACHED_FILE_INDEX_NAME)) {
            error_log('ALO: Successfully created attached file index via dbDelta: ' . self::ATTACHED_FILE_INDEX_NAME);
            update_option('alo_attached_file_index_created', current_time('timestamp'));
            return true;
        }
        
        return false;
    }
    
    /**
     * Create attached file index using direct ALTER TABLE query
     */
    private function create_attached_file_index_direct() {
        global $wpdb;
        
        // Use ALTER TABLE as requested
        $sql = $wpdb->prepare(
            "ALTER TABLE {$wpdb->postmeta} ADD INDEX %i (meta_key(191), meta_value(191))",
            self::ATTACHED_FILE_INDEX_NAME
        );
        
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            error_log('ALO: Failed to create attached file index: ' . $wpdb->last_error);
            return false;
        }
        
        // Log successful index creation
        error_log('ALO: Successfully created attached file index: ' . self::ATTACHED_FILE_INDEX_NAME);
        
        // Store creation timestamp
        update_option('alo_attached_file_index_created', current_time('timestamp'));
        
        return true;
    }
    
    /**
     * Create postmeta table indexes
     */
    public function create_postmeta_indexes() {
        global $wpdb;
        
        // Check if index already exists
        if ($this->index_exists(self::POSTMETA_INDEX_NAME)) {
            return true;
        }
        
        // Create composite index on meta_key and meta_value
        $sql = $wpdb->prepare(
            "CREATE INDEX %i ON {$wpdb->postmeta} (meta_key(191), meta_value(191))",
            self::POSTMETA_INDEX_NAME
        );
        
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            error_log('ALO: Failed to create postmeta index: ' . $wpdb->last_error);
            return false;
        }
        
        // Log successful index creation
        error_log('ALO: Successfully created postmeta index: ' . self::POSTMETA_INDEX_NAME);
        
        // Store creation timestamp
        update_option('alo_index_created', current_time('timestamp'));
        
        return true;
    }
    
    /**
     * Check if an index exists
     */
    private function index_exists($index_name) {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = %s",
            $index_name
        ));
        
        return !empty($result);
    }
    
    /**
     * Drop the custom indexes (useful for debugging or uninstall)
     */
    public function drop_postmeta_indexes() {
        global $wpdb;
        
        $indexes_dropped = 0;
        
        // Drop the original postmeta index
        if ($this->index_exists(self::POSTMETA_INDEX_NAME)) {
            $sql = $wpdb->prepare(
                "DROP INDEX %i ON {$wpdb->postmeta}",
                self::POSTMETA_INDEX_NAME
            );
            
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                error_log('ALO: Successfully dropped postmeta index: ' . self::POSTMETA_INDEX_NAME);
                delete_option('alo_index_created');
                $indexes_dropped++;
            } else {
                error_log('ALO: Failed to drop postmeta index: ' . $wpdb->last_error);
            }
        }
        
        // Drop the attached file index
        if ($this->index_exists(self::ATTACHED_FILE_INDEX_NAME)) {
            $sql = $wpdb->prepare(
                "DROP INDEX %i ON {$wpdb->postmeta}",
                self::ATTACHED_FILE_INDEX_NAME
            );
            
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                error_log('ALO: Successfully dropped attached file index: ' . self::ATTACHED_FILE_INDEX_NAME);
                delete_option('alo_attached_file_index_created');
                delete_option('alo_attached_file_index_checked');
                $indexes_dropped++;
            } else {
                error_log('ALO: Failed to drop attached file index: ' . $wpdb->last_error);
            }
        }
        
        return $indexes_dropped > 0;
    }
    
    /**
     * Get database optimization statistics
     */
    public function get_stats() {
        global $wpdb;
        
        $index_exists = $this->index_exists(self::POSTMETA_INDEX_NAME);
        $attached_file_index_exists = $this->index_exists(self::ATTACHED_FILE_INDEX_NAME);
        
        // Auto-set timestamps for existing indexes that don't have them
        if ($index_exists && !get_option('alo_index_created', 0)) {
            update_option('alo_index_created', current_time('timestamp'));
        }
        
        if ($attached_file_index_exists && !get_option('alo_attached_file_index_created', 0)) {
            update_option('alo_attached_file_index_created', current_time('timestamp'));
        }
        
        // Use SharedStatsCache to prevent duplicate queries
        $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
        $shared_cache = $plugin->get_shared_stats_cache();
        
        // Check for existing cache first
        $cache_key = 'alo_db_stats_cache_v3'; // Increment version for shared cache
        $cached_stats = get_transient($cache_key);
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO DatabaseManager: get_stats() called, cache result: ' . ($cached_stats ? 'HIT' : 'MISS'));
        }
        
        if ($cached_stats === false) {
            $start_time = microtime(true);
            
            // Get shared stats (this consolidates the duplicate queries)
            $shared_stats = $shared_cache->get_shared_stats();
            
            // Extract what we need from shared stats
            $expensive_stats = [
                'postmeta_count' => $shared_stats['postmeta_count'],
                'attachment_meta_count' => $shared_stats['attachment_meta_count'],
                'attachment_count' => $shared_stats['attachment_count'],
                'estimated' => $shared_stats['estimated'] ?? false,
                'sample_size' => $shared_stats['sample_size'] ?? null,
                'avg_meta_per_attachment' => $shared_stats['avg_meta_per_attachment'] ?? null,
                'cache_generated_at' => current_time('mysql'),
                'query_time_ms' => round((microtime(true) - $start_time) * 1000, 2),
                'using_shared_cache' => true
            ];
            
            // Cache for 15 minutes (900 seconds) - longer duration
            $cache_result = set_transient($cache_key, $expensive_stats, 900);
            
            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'ALO DatabaseManager: Generated stats using shared cache in %.2fms, cache set: %s', 
                    $expensive_stats['query_time_ms'], 
                    $cache_result ? 'SUCCESS' : 'FAILED'
                ));
            }
        } else {
            $expensive_stats = $cached_stats;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO DatabaseManager: Using cached stats from ' . ($expensive_stats['cache_generated_at'] ?? 'unknown'));
            }
        }
        
        $stats = [
            'index_exists' => $index_exists,
            'index_created' => get_option('alo_index_created', 0),
            'attached_file_index_exists' => $attached_file_index_exists,
            'attached_file_index_created' => get_option('alo_attached_file_index_created', 0),
        ];
        
        // Merge cached expensive stats
        $stats = array_merge($stats, $expensive_stats);
        
        // Merge in query monitoring statistics (these are lightweight)
        $query_stats = $this->get_query_stats();
        $stats = array_merge($stats, $query_stats);
        
        return $stats;
    }
    
    /**
     * Clear the database stats cache (useful when attachments are added/removed)
     */
    public function clear_stats_cache() {
        delete_transient('alo_db_stats_cache');
        delete_transient('alo_db_stats_cache_v2');
        delete_transient('alo_db_stats_cache_v3');
        
        // Also clear shared cache
        $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
        $shared_cache = $plugin->get_shared_stats_cache();
        if ($shared_cache) {
            $shared_cache->clear_attachment_caches();
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO DatabaseManager: Stats cache cleared');
        }
    }
    
    /**
     * Clear the database stats cache when plugin is updated (attachment counts may change due to processing)
     */
    public function maybe_clear_cache_on_plugin_update($upgrader_object, $options) {
        if (isset($options['action']) && $options['action'] === 'update' && isset($options['type']) && $options['type'] === 'plugin') {
            $this->clear_stats_cache();
        }
    }
} 