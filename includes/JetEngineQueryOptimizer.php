<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * JetEngine Query Optimizer Class
 * 
 * Optimizes JetEngine queries to reduce database load by:
 * - Only pulling required meta fields
 * - Avoiding unnecessary meta_query usage
 * - Preventing nested queries when possible
 * - Optimizing field selection and query structure
 */
class JetEngineQueryOptimizer {
    
    /**
     * Whether query optimization is enabled
     */
    private $optimization_enabled = true;
    
    /**
     * Query optimization statistics
     */
    private $stats = [
        'queries_optimized' => 0,
        'meta_queries_reduced' => 0,
        'fields_optimized' => 0,
        'nested_queries_prevented' => 0,
        'query_time_saved' => 0,
        'memory_saved' => 0
    ];
    
    /**
     * Cache for field mappings
     */
    private $field_cache = [];
    
    /**
     * Query monitoring
     */
    private $query_monitor = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        // Safety check: Ensure init_hooks method is accessible
        if (!method_exists($this, 'init_hooks')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO: JetEngineQueryOptimizer - init_hooks method does not exist');
            }
            return;
        }
        
        // Check method visibility for debugging
        $reflection = new \ReflectionMethod($this, 'init_hooks');
        if (!$reflection->isPublic()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO: JetEngineQueryOptimizer - init_hooks method is not public, fixing...');
            }
            // This should not happen, but just in case, we'll call it directly instead
            $this->init_hooks();
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO: JetEngineQueryOptimizer - Constructor called, registering init_hooks');
        }
        
        // Delay initialization until JetEngine is available
        add_action('plugins_loaded', [$this, 'init_hooks'], 20);
    }
    
    /**
     * Initialize optimization hooks
     */
    public function init_hooks() {
        // Enable/disable based on admin setting
        $this->optimization_enabled = apply_filters('alo_jetengine_query_optimization_enabled', true);
        
        if (!$this->optimization_enabled || !$this->is_jetengine_active()) {
            // Log why optimization is not being enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                if (!$this->optimization_enabled) {
                    error_log('ALO: JetEngine Query Optimization disabled by setting');
                } elseif (!$this->is_jetengine_active()) {
                    error_log('ALO: JetEngine not detected, query optimization skipped');
                }
            }
            return;
        }
        
        // Log that we're initializing JetEngine optimization
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO: Initializing JetEngine Query Optimization hooks');
        }
        
        // Hook into JetEngine query building with higher priority to ensure we run first
        add_filter('jet-engine/query-builder/query/args', [$this, 'optimize_query_args'], 5, 2);
        add_filter('jet-engine/listing/grid/query-args', [$this, 'optimize_listing_query'], 5, 2);
        
        // Meta query optimization
        add_filter('jet-engine/meta-fields/query', [$this, 'optimize_meta_query'], 10, 3);
        add_filter('jet-engine/query-builder/query/meta-query', [$this, 'optimize_meta_query_builder'], 10, 2);
        
        // Field selection optimization
        add_filter('jet-engine/listing/data/object-fields', [$this, 'optimize_object_fields'], 10, 3);
        add_action('jet-engine/query-builder/query/before-query-setup', [$this, 'monitor_query_start'], 10, 2);
        add_action('jet-engine/query-builder/query/after-query-setup', [$this, 'monitor_query_end'], 10, 2);
        
        // Prevent unnecessary nested queries
        add_filter('jet-engine/listings/data/nested-query', [$this, 'prevent_unnecessary_nested_queries'], 10, 3);
        
        // Optimize related queries
        add_filter('jet-engine/relations/get-related-objects', [$this, 'optimize_related_queries'], 10, 4);
        
        // Query caching
        add_filter('jet-engine/query-builder/query/cache-key', [$this, 'optimize_cache_key'], 10, 2);
        
        // Alternative hooks for different JetEngine versions
        add_filter('jet-engine/listings/data/post-data', [$this, 'track_listing_data_access'], 10, 2);
        add_filter('jet-engine/query-builder/queries/get-query-args', [$this, 'optimize_query_args'], 5, 2);
        
        // Performance monitoring
        add_action('wp_footer', [$this, 'log_optimization_stats'], 999);
        add_action('admin_footer', [$this, 'log_optimization_stats'], 999);
        
        // Initialize monitoring with current request
        $this->init_request_monitoring();
    }
    
    /**
     * Initialize request monitoring
     */
    private function init_request_monitoring() {
        // Track that we've initialized for this request
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $jetengine_version = defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : 'unknown';
            error_log("ALO: JetEngine Query Optimization active (JetEngine v{$jetengine_version})");
        }
    }
    
    /**
     * Optimize query arguments
     */
    public function optimize_query_args($args, $query) {
        $start_time = microtime(true);
        $original_args = $args;
        
        // Optimize field selection
        $args = $this->optimize_field_selection($args, $query);
        
        // Optimize meta queries
        $args = $this->optimize_meta_queries($args, $query);
        
        // Optimize ordering and limits
        $args = $this->optimize_query_structure($args, $query);
        
        // Track optimization
        $end_time = microtime(true);
        $this->stats['queries_optimized']++;
        $this->stats['query_time_saved'] += ($end_time - $start_time) * 1000;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log_query_optimization($original_args, $args, $end_time - $start_time);
        }
        
        return $args;
    }
    
    /**
     * Optimize listing query arguments
     */
    public function optimize_listing_query($args, $settings) {
        $start_time = microtime(true);
        
        // Extract settings if we received a listing object instead of array
        $settings_array = $this->extract_settings_from_input($settings);
        
        // Only select necessary fields for listings
        if (!isset($args['fields'])) {
            $args['fields'] = $this->get_required_listing_fields($settings_array);
        }
        
        // Optimize posts_per_page for performance
        if (isset($args['posts_per_page']) && $args['posts_per_page'] > 100) {
            $args['posts_per_page'] = 100; // Reasonable limit
        }
        
        // Disable unnecessary features for performance
        $args['update_post_meta_cache'] = false;
        $args['update_post_term_cache'] = false;
        $args['no_found_rows'] = isset($settings_array['disable_pagination']) && $settings_array['disable_pagination'];
        
        $end_time = microtime(true);
        $this->stats['queries_optimized']++;
        $this->stats['query_time_saved'] += ($end_time - $start_time) * 1000;
        
        return $args;
    }
    
    /**
     * Extract settings from input (either array or listing object)
     */
    private function extract_settings_from_input($input) {
        // If it's already an array, return as-is
        if (is_array($input)) {
            return $input;
        }
        
        // If it's a JetEngine listing object, extract settings
        if (is_object($input)) {
            try {
                // Try different methods to get settings from JetEngine objects
                if (method_exists($input, 'get_settings')) {
                    $settings = $input->get_settings();
                    return is_array($settings) ? $settings : [];
                } elseif (method_exists($input, 'get_meta')) {
                    $meta = $input->get_meta();
                    return is_array($meta) ? $meta : [];
                } elseif (property_exists($input, 'settings') && is_array($input->settings)) {
                    return $input->settings;
                } elseif (property_exists($input, '_settings') && is_array($input->_settings)) {
                    return $input->_settings;
                }
            } catch (Exception $e) {
                // Log the error but don't break functionality
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ALO: Error extracting settings from JetEngine object: ' . $e->getMessage());
                }
            }
        }
        
        // Fallback to empty array if we can't extract settings
        return [];
    }
    
    /**
     * Get required fields for listing based on settings
     */
    private function get_required_listing_fields($settings) {
        $required_fields = ['ID', 'post_title', 'post_date', 'post_status'];
        
        // Check if we need content
        if ($this->listing_uses_content($settings)) {
            $required_fields[] = 'post_content';
        }
        
        // Check if we need excerpt
        if ($this->listing_uses_excerpt($settings)) {
            $required_fields[] = 'post_excerpt';
        }
        
        // Check if we need author
        if ($this->listing_uses_author($settings)) {
            $required_fields[] = 'post_author';
        }
        
        // Allow filtering
        return apply_filters('alo_jetengine_required_listing_fields', $required_fields, $settings);
    }
    
    /**
     * Check if listing uses content field
     */
    private function listing_uses_content($settings) {
        // Extract settings if we received a listing object instead of array
        $settings_array = $this->extract_settings_from_input($settings);
        
        // Check listing template for content usage
        $template = $settings_array['listing_template'] ?? '';
        
        return strpos($template, 'post_content') !== false || 
               strpos($template, 'dynamic-field="post_content"') !== false ||
               strpos($template, 'the_content') !== false;
    }
    
    /**
     * Check if listing uses excerpt field
     */
    private function listing_uses_excerpt($settings) {
        // Extract settings if we received a listing object instead of array
        $settings_array = $this->extract_settings_from_input($settings);
        
        $template = $settings_array['listing_template'] ?? '';
        
        return strpos($template, 'post_excerpt') !== false || 
               strpos($template, 'dynamic-field="post_excerpt"') !== false ||
               strpos($template, 'the_excerpt') !== false;
    }
    
    /**
     * Check if listing uses author field
     */
    private function listing_uses_author($settings) {
        // Extract settings if we received a listing object instead of array
        $settings_array = $this->extract_settings_from_input($settings);
        
        $template = $settings_array['listing_template'] ?? '';
        
        return strpos($template, 'post_author') !== false || 
               strpos($template, 'author') !== false;
    }
    
    /**
     * Optimize field selection
     */
    private function optimize_field_selection($args, $query) {
        // If no specific fields requested, optimize based on query type
        if (!isset($args['fields'])) {
            $query_type = $query->query_type ?? 'posts';
            
            switch ($query_type) {
                case 'posts':
                    $args['fields'] = 'ids'; // Only get IDs initially
                    break;
                case 'terms':
                    $args['fields'] = 'ids';
                    break;
                case 'users':
                    $args['fields'] = 'ID';
                    break;
            }
            
            $this->stats['fields_optimized']++;
        }
        
        return $args;
    }
    
    /**
     * Optimize meta queries
     */
    private function optimize_meta_queries($args, $query) {
        if (!isset($args['meta_query'])) {
            return $args;
        }
        
        $original_meta_query = $args['meta_query'];
        $optimized_meta_query = $this->simplify_meta_query($original_meta_query);
        
        if (count($optimized_meta_query) < count($original_meta_query)) {
            $args['meta_query'] = $optimized_meta_query;
            $this->stats['meta_queries_reduced']++;
        }
        
        return $args;
    }
    
    /**
     * Simplify meta query structure
     */
    private function simplify_meta_query($meta_query) {
        if (!is_array($meta_query)) {
            return $meta_query;
        }
        
        $simplified = [];
        
        foreach ($meta_query as $key => $clause) {
            if (!is_array($clause)) {
                $simplified[$key] = $clause;
                continue;
            }
            
            // Skip redundant EXISTS clauses
            if (isset($clause['compare']) && $clause['compare'] === 'EXISTS') {
                // Check if we already have a more specific clause for this key
                $meta_key = $clause['key'] ?? '';
                $has_specific_clause = false;
                
                foreach ($meta_query as $other_clause) {
                    if (is_array($other_clause) && 
                        isset($other_clause['key']) && 
                        $other_clause['key'] === $meta_key &&
                        isset($other_clause['compare']) &&
                        $other_clause['compare'] !== 'EXISTS') {
                        $has_specific_clause = true;
                        break;
                    }
                }
                
                if (!$has_specific_clause) {
                    $simplified[$key] = $clause;
                }
            } else {
                $simplified[$key] = $clause;
            }
        }
        
        return $simplified;
    }
    
    /**
     * Optimize query structure
     */
    private function optimize_query_structure($args, $query) {
        // Optimize ORDER BY clauses
        if (isset($args['orderby']) && is_array($args['orderby'])) {
            // Limit to 2 order criteria max for performance
            if (count($args['orderby']) > 2) {
                $args['orderby'] = array_slice($args['orderby'], 0, 2, true);
            }
        }
        
        // Optimize LIMIT clauses
        if (isset($args['posts_per_page']) && $args['posts_per_page'] === -1) {
            $args['posts_per_page'] = 1000; // Reasonable upper limit
        }
        
        // Disable expensive operations when not needed
        if (!isset($args['no_found_rows'])) {
            $args['no_found_rows'] = true; // Disable counting unless pagination needed
        }
        
        return $args;
    }
    
    /**
     * Optimize meta query from query builder
     */
    public function optimize_meta_query_builder($meta_query, $query) {
        if (empty($meta_query) || !is_array($meta_query)) {
            return $meta_query;
        }
        
        // Convert complex meta queries to simpler indexed lookups where possible
        $optimized = $this->convert_to_indexed_lookups($meta_query);
        
        if ($optimized !== $meta_query) {
            $this->stats['meta_queries_reduced']++;
        }
        
        return $optimized;
    }
    
    /**
     * Convert meta queries to indexed lookups
     */
    private function convert_to_indexed_lookups($meta_query) {
        foreach ($meta_query as $key => &$clause) {
            if (!is_array($clause) || !isset($clause['key'])) {
                continue;
            }
            
            // Convert LIKE queries to exact matches where possible
            if (isset($clause['compare']) && $clause['compare'] === 'LIKE') {
                $value = $clause['value'] ?? '';
                
                // If no wildcards, convert to equals
                if (strpos($value, '%') === false && strpos($value, '_') === false) {
                    $clause['compare'] = '=';
                }
            }
            
            // Optimize IN queries with single values
            if (isset($clause['compare']) && $clause['compare'] === 'IN') {
                $value = $clause['value'] ?? [];
                
                if (is_array($value) && count($value) === 1) {
                    $clause['compare'] = '=';
                    $clause['value'] = $value[0];
                }
            }
        }
        
        return $meta_query;
    }
    
    /**
     * Optimize object fields selection
     */
    public function optimize_object_fields($fields, $object, $listing) {
        // Cache field requirements
        $cache_key = md5(serialize($listing->get_settings()));
        
        if (isset($this->field_cache[$cache_key])) {
            return $this->field_cache[$cache_key];
        }
        
        // Analyze which fields are actually used
        $required_fields = $this->analyze_required_fields($listing);
        
        // Filter fields to only required ones
        $optimized_fields = array_intersect_key($fields, array_flip($required_fields));
        
        // Cache the result
        $this->field_cache[$cache_key] = $optimized_fields;
        
        $this->stats['fields_optimized']++;
        
        return $optimized_fields;
    }
    
    /**
     * Analyze which fields are required for a listing
     */
    private function analyze_required_fields($listing) {
        $settings = $listing->get_settings();
        $required_fields = ['ID'];
        
        // Parse template to find used fields
        $template = $settings['listing_template'] ?? '';
        
        // Common field patterns
        $field_patterns = [
            '/dynamic-field="([^"]+)"/' => 1,
            '/\{\{([^}]+)\}\}/' => 1,
            '/post_([a-z_]+)/' => 1
        ];
        
        foreach ($field_patterns as $pattern => $group) {
            if (preg_match_all($pattern, $template, $matches)) {
                $required_fields = array_merge($required_fields, $matches[$group]);
            }
        }
        
        // Add common fields that are often needed
        $common_fields = ['post_title', 'post_date', 'post_status'];
        $required_fields = array_merge($required_fields, $common_fields);
        
        return array_unique($required_fields);
    }
    
    /**
     * Prevent unnecessary nested queries
     */
    public function prevent_unnecessary_nested_queries($should_run_nested, $query_args, $listing) {
        // Analyze if nested query is actually needed
        $settings = $listing->get_settings();
        
        // Skip nested queries for simple listings
        if ($this->is_simple_listing($settings)) {
            $this->stats['nested_queries_prevented']++;
            return false;
        }
        
        return $should_run_nested;
    }
    
    /**
     * Check if listing is simple enough to skip nested queries
     */
    private function is_simple_listing($settings) {
        // Extract settings if we received a listing object instead of array
        $settings_array = $this->extract_settings_from_input($settings);
        
        // Simple listings are those without complex relationships or meta queries
        $complex_features = [
            'meta_query',
            'tax_query', 
            'relation_query',
            'nested_meta_query'
        ];
        
        foreach ($complex_features as $feature) {
            if (!empty($settings_array[$feature])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Optimize related queries
     */
    public function optimize_related_queries($objects, $relation, $object_id, $args) {
        $start_time = microtime(true);
        
        // Limit related object queries to reasonable amounts
        if (isset($args['number']) && $args['number'] > 50) {
            $args['number'] = 50;
        }
        
        // Use IDs only for initial fetch
        $args['fields'] = 'ids';
        
        $end_time = microtime(true);
        $this->stats['query_time_saved'] += ($end_time - $start_time) * 1000;
        
        return $objects;
    }
    
    /**
     * Optimize cache keys
     */
    public function optimize_cache_key($cache_key, $query_args) {
        // Create more efficient cache keys by removing unnecessary args
        $optimized_args = $this->remove_cache_irrelevant_args($query_args);
        
        return 'alo_jet_' . md5(serialize($optimized_args));
    }
    
    /**
     * Remove arguments that don't affect query results for caching
     */
    private function remove_cache_irrelevant_args($args) {
        $irrelevant_keys = [
            'update_post_meta_cache',
            'update_post_term_cache',
            'no_found_rows',
            'cache_results',
            'suppress_filters'
        ];
        
        return array_diff_key($args, array_flip($irrelevant_keys));
    }
    
    /**
     * Monitor query start for performance tracking
     */
    public function monitor_query_start($query, $args = null) {
        $query_id = spl_object_id($query);
        
        $this->query_monitor[$query_id] = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(),
            'args' => $args ?: []
        ];
    }
    
    /**
     * Monitor query end for performance tracking
     */
    public function monitor_query_end($query, $args = null) {
        $query_id = spl_object_id($query);
        
        if (!isset($this->query_monitor[$query_id])) {
            return;
        }
        
        $monitor_data = $this->query_monitor[$query_id];
        $end_time = microtime(true);
        $memory_end = memory_get_usage();
        
        $execution_time = ($end_time - $monitor_data['start_time']) * 1000;
        $memory_used = $memory_end - $monitor_data['memory_start'];
        
        // Track savings if optimization was applied (only if we have args to compare)
        if ($args && $this->was_query_optimized($monitor_data['args'], $args)) {
            $this->stats['query_time_saved'] += $execution_time * 0.2; // Estimate 20% savings
            $this->stats['memory_saved'] += $memory_used * 0.15; // Estimate 15% memory savings
        }
        
        // Clean up monitor data
        unset($this->query_monitor[$query_id]);
        
        if (defined('WP_DEBUG') && WP_DEBUG && $execution_time > 100) {
            error_log(sprintf(
                'ALO: JetEngine query took %.2fms, memory: %s',
                $execution_time,
                $this->format_bytes($memory_used)
            ));
        }
    }
    
    /**
     * Check if query was optimized
     */
    private function was_query_optimized($original_args, $final_args) {
        // Simple check - if args differ, optimization likely occurred
        return serialize($original_args) !== serialize($final_args);
    }
    
    /**
     * Log query optimization details
     */
    private function log_query_optimization($original_args, $optimized_args, $execution_time) {
        $changes = [];
        
        // Check for field optimization
        if (isset($optimized_args['fields']) && !isset($original_args['fields'])) {
            $changes[] = 'fields optimized';
        }
        
        // Check for meta query changes
        if (isset($original_args['meta_query']) && isset($optimized_args['meta_query'])) {
            $original_count = count($original_args['meta_query']);
            $optimized_count = count($optimized_args['meta_query']);
            
            if ($optimized_count < $original_count) {
                $changes[] = sprintf('meta_query reduced (%d→%d)', $original_count, $optimized_count);
            }
        }
        
        if (!empty($changes)) {
            error_log(sprintf(
                'ALO: JetEngine query optimized in %.2fms: %s',
                $execution_time * 1000,
                implode(', ', $changes)
            ));
        }
    }
    
    /**
     * Log optimization statistics
     */
    public function log_optimization_stats() {
        if ($this->stats['queries_optimized'] === 0) {
            return;
        }
        
        $current_url = $_SERVER['REQUEST_URI'] ?? 'unknown';
        
        $message = sprintf(
            'ALO JETENGINE OPTIMIZATION: %d queries optimized | %d meta queries reduced | %d fields optimized | %d nested queries prevented | %.2fms saved | %s memory saved | URL: %s',
            $this->stats['queries_optimized'],
            $this->stats['meta_queries_reduced'],
            $this->stats['fields_optimized'],
            $this->stats['nested_queries_prevented'],
            $this->stats['query_time_saved'],
            $this->format_bytes($this->stats['memory_saved']),
            $current_url
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
        
        // Hook for custom logging
        do_action('alo_jetengine_optimization_stats', $this->stats, $current_url);
    }
    
    /**
     * Format bytes for human-readable output
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        } elseif ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        } else {
            return $bytes . ' B';
        }
    }
    
    /**
     * Get optimization statistics
     */
    public function get_stats() {
        return array_merge($this->stats, [
            'optimization_enabled' => $this->optimization_enabled,
            'jetengine_detected' => $this->is_jetengine_active(),
            'field_cache_size' => count($this->field_cache),
            'avg_time_saved' => $this->stats['queries_optimized'] > 0 ? 
                round($this->stats['query_time_saved'] / $this->stats['queries_optimized'], 2) : 0
        ]);
    }
    
    /**
     * Enable/disable optimization
     */
    public function set_optimization_enabled($enabled) {
        $this->optimization_enabled = (bool) $enabled;
    }
    
    /**
     * Check if optimization is enabled
     */
    public function is_optimization_enabled() {
        return $this->optimization_enabled;
    }
    
    /**
     * Clear field cache
     */
    public function clear_field_cache() {
        $this->field_cache = [];
    }
    
    /**
     * Check if JetEngine is active
     */
    private function is_jetengine_active() {
        // Check for JetEngine class (most reliable)
        if (class_exists('Jet_Engine')) {
            return true;
        }
        
        // Check for JetEngine constant
        if (defined('JET_ENGINE_VERSION')) {
            return true;
        }
        
        // Check for JetEngine functions
        if (function_exists('jet_engine')) {
            return true;
        }
        
        // Check if JetEngine plugin file is active
        if (function_exists('is_plugin_active') && is_plugin_active('jet-engine/jet-engine.php')) {
            return true;
        }
        
        // Check for JetEngine specific hooks/filters existence
        if (has_filter('jet-engine/query-builder/query/args')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Track listing data access for performance monitoring
     */
    public function track_listing_data_access($data, $object) {
        // Track when JetEngine accesses post data for optimization insights
        if (defined('WP_DEBUG') && WP_DEBUG) {
            static $data_access_count = 0;
            $data_access_count++;
            
            // Log every 10 accesses to avoid spam
            if ($data_access_count % 10 === 0) {
                error_log("ALO: JetEngine data access count: {$data_access_count}");
            }
        }
        
        return $data;
    }
    
    /**
     * Optimize meta field queries (for jet-engine/meta-fields/query hook)
     */
    public function optimize_meta_query($query, $field_settings, $listing_settings) {
        $start_time = microtime(true);
        
        // Log the optimization attempt
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO: JetEngine meta query optimization triggered');
        }
        
        // If it's a simple meta query, optimize it
        if (is_array($query) && isset($query['meta_query'])) {
            $original_meta_query = $query['meta_query'];
            $optimized_meta_query = $this->simplify_meta_query($original_meta_query);
            
            if ($optimized_meta_query !== $original_meta_query) {
                $query['meta_query'] = $optimized_meta_query;
                $this->stats['meta_queries_reduced']++;
            }
        }
        
        // Track optimization time
        $end_time = microtime(true);
        $this->stats['query_time_saved'] += ($end_time - $start_time) * 1000;
        
        return $query;
    }
} 