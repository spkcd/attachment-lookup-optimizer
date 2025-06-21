<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * SharedStatsCache Class
 * 
 * Prevents duplicate database queries by caching common statistics
 * that are used by multiple components (DatabaseManager, UploadPreprocessor, etc.)
 */
class SharedStatsCache {
    
    /**
     * In-memory cache for current request
     */
    private static $request_cache = [];
    
    /**
     * Cache keys
     */
    const ATTACHMENT_COUNT_KEY = 'alo_shared_attachment_count';
    const POSTMETA_COUNT_KEY = 'alo_shared_postmeta_count';
    const SHARED_STATS_KEY = 'alo_shared_stats_cache_v1';
    
    /**
     * Get attachment count (shared between components)
     */
    public function get_attachment_count($use_cache = true) {
        if (!$use_cache) {
            return $this->query_attachment_count();
        }
        
        // Check request-level cache first
        if (isset(self::$request_cache['attachment_count'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO SharedCache: Using request-level attachment count cache');
            }
            return self::$request_cache['attachment_count'];
        }
        
        // Check transient cache
        $cached_count = get_transient(self::ATTACHMENT_COUNT_KEY);
        if ($cached_count !== false) {
            self::$request_cache['attachment_count'] = $cached_count;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO SharedCache: Using transient attachment count cache');
            }
            return $cached_count;
        }
        
        // Query and cache
        $count = $this->query_attachment_count();
        self::$request_cache['attachment_count'] = $count;
        set_transient(self::ATTACHMENT_COUNT_KEY, $count, 900); // 15 minutes
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO SharedCache: Generated fresh attachment count: ' . $count);
        }
        
        return $count;
    }
    
    /**
     * Get postmeta count (shared between components)
     */
    public function get_postmeta_count($use_cache = true) {
        if (!$use_cache) {
            return $this->query_postmeta_count();
        }
        
        // Check request-level cache first
        if (isset(self::$request_cache['postmeta_count'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO SharedCache: Using request-level postmeta count cache');
            }
            return self::$request_cache['postmeta_count'];
        }
        
        // Check transient cache
        $cached_count = get_transient(self::POSTMETA_COUNT_KEY);
        if ($cached_count !== false) {
            self::$request_cache['postmeta_count'] = $cached_count;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO SharedCache: Using transient postmeta count cache');
            }
            return $cached_count;
        }
        
        // Query and cache
        $count = $this->query_postmeta_count();
        self::$request_cache['postmeta_count'] = $count;
        set_transient(self::POSTMETA_COUNT_KEY, $count, 900); // 15 minutes
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO SharedCache: Generated fresh postmeta count: ' . $count);
        }
        
        return $count;
    }
    
    /**
     * Get comprehensive stats (used by both components)
     */
    public function get_shared_stats($use_cache = true) {
        if (!$use_cache) {
            return $this->generate_shared_stats();
        }
        
        // Check request-level cache first
        if (isset(self::$request_cache['shared_stats'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO SharedCache: Using request-level shared stats cache');
            }
            return self::$request_cache['shared_stats'];
        }
        
        // Check transient cache
        $cached_stats = get_transient(self::SHARED_STATS_KEY);
        if ($cached_stats !== false) {
            self::$request_cache['shared_stats'] = $cached_stats;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO SharedCache: Using transient shared stats cache');
            }
            return $cached_stats;
        }
        
        // Generate and cache
        $stats = $this->generate_shared_stats();
        self::$request_cache['shared_stats'] = $stats;
        set_transient(self::SHARED_STATS_KEY, $stats, 900); // 15 minutes
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO SharedCache: Generated fresh shared stats in ' . $stats['query_time_ms'] . 'ms');
        }
        
        return $stats;
    }
    
    /**
     * Actually query attachment count from database
     */
    private function query_attachment_count() {
        global $wpdb;
        
        $start_time = microtime(true);
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'inherit'",
            'attachment'
        ));
        $query_time = microtime(true) - $start_time;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('ALO SharedCache: Attachment count query took %.2fms', $query_time * 1000));
        }
        
        return (int) $count;
    }
    
    /**
     * Actually query postmeta count from database
     */
    private function query_postmeta_count() {
        global $wpdb;
        
        $start_time = microtime(true);
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
        $query_time = microtime(true) - $start_time;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('ALO SharedCache: Postmeta count query took %.2fms', $query_time * 1000));
        }
        
        return (int) $count;
    }
    
    /**
     * Generate comprehensive shared statistics
     */
    private function generate_shared_stats() {
        global $wpdb;
        
        $start_time = microtime(true);
        
        // Get basic counts that are shared
        $attachment_count = $this->get_attachment_count(false); // Don't use cache to avoid recursion
        $postmeta_count = $this->get_postmeta_count(false);
        
        // Additional optimized queries with better performance
        $cached_attachments = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_alo_cached_file_path'
        ));
        
        // Estimate attachment meta count using optimized approach
        if ($attachment_count > 1000) {
            // Use sampling for large datasets
            $sample_size = min(100, $attachment_count);
            $sample_meta_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(pm.meta_id) 
                 FROM {$wpdb->postmeta} pm 
                 INNER JOIN (
                     SELECT ID FROM {$wpdb->posts} 
                     WHERE post_type = %s AND post_status = 'inherit' 
                     ORDER BY RAND() LIMIT %d
                 ) p ON pm.post_id = p.ID",
                'attachment',
                $sample_size
            ));
            
            $avg_meta_per_attachment = $sample_size > 0 ? ($sample_meta_count / $sample_size) : 0;
            $estimated_attachment_meta_count = round($attachment_count * $avg_meta_per_attachment);
            
            $attachment_meta_data = [
                'attachment_meta_count' => $estimated_attachment_meta_count,
                'estimated' => true,
                'sample_size' => $sample_size,
                'avg_meta_per_attachment' => round($avg_meta_per_attachment, 1)
            ];
        } else {
            // Exact count for smaller datasets
            $attachment_meta_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} 
                 WHERE post_id IN (
                     SELECT ID FROM {$wpdb->posts} 
                     WHERE post_type = %s AND post_status = 'inherit'
                 )",
                'attachment'
            ));
            
            $attachment_meta_data = [
                'attachment_meta_count' => (int) $attachment_meta_count,
                'estimated' => false
            ];
        }
        
        // JetFormBuilder specific count (optimized)
        $jetformbuilder_uploads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND post_id IN (
                 SELECT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = %s 
                 AND meta_value LIKE %s
             )",
            '_alo_cached_file_path',
            '_alo_upload_source',
            '%jetformbuilder%'
        ));
        
        $query_time = microtime(true) - $start_time;
        
        $shared_stats = array_merge([
            'attachment_count' => $attachment_count,
            'postmeta_count' => $postmeta_count,
            'cached_attachments' => (int) $cached_attachments,
            'jetformbuilder_uploads' => (int) $jetformbuilder_uploads,
            'coverage_percentage' => $attachment_count > 0 ? 
                round(($cached_attachments / $attachment_count) * 100, 2) : 0,
            'cache_generated_at' => current_time('mysql'),
            'query_time_ms' => round($query_time * 1000, 2)
        ], $attachment_meta_data);
        
        return $shared_stats;
    }
    
    /**
     * Clear all shared caches
     */
    public function clear_all_caches() {
        // Clear transients
        delete_transient(self::ATTACHMENT_COUNT_KEY);
        delete_transient(self::POSTMETA_COUNT_KEY);
        delete_transient(self::SHARED_STATS_KEY);
        
        // Clear request-level cache
        self::$request_cache = [];
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO SharedCache: All caches cleared');
        }
    }
    
    /**
     * Clear attachment-related caches (when attachments are added/removed)
     */
    public function clear_attachment_caches() {
        delete_transient(self::ATTACHMENT_COUNT_KEY);
        delete_transient(self::SHARED_STATS_KEY);
        
        // Clear relevant request cache
        unset(self::$request_cache['attachment_count']);
        unset(self::$request_cache['shared_stats']);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO SharedCache: Attachment-related caches cleared');
        }
    }
    
    /**
     * Get cache status for debugging
     */
    public function get_cache_status() {
        return [
            'attachment_count_cached' => get_transient(self::ATTACHMENT_COUNT_KEY) !== false,
            'postmeta_count_cached' => get_transient(self::POSTMETA_COUNT_KEY) !== false,
            'shared_stats_cached' => get_transient(self::SHARED_STATS_KEY) !== false,
            'request_cache_items' => array_keys(self::$request_cache),
            'request_cache_size' => count(self::$request_cache)
        ];
    }
} 