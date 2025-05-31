<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * JetEngine Preloader Class
 * 
 * Hooks into JetEngine listing rendering to preload all attachment URLs
 * in advance, eliminating N+1 query problems during listing generation
 */
class JetEnginePreloader {
    
    /**
     * Cache manager instance
     */
    private $cache_manager;
    
    /**
     * Custom lookup table instance
     */
    private $custom_lookup_table;
    
    /**
     * Preloading statistics
     */
    private $stats = [
        'listings_processed' => 0,
        'urls_preloaded' => 0,
        'urls_found' => 0,
        'preload_time' => 0,
        'requests_processed' => 0
    ];
    
    /**
     * Whether preloading is enabled
     */
    private $preloading_enabled = true;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check if JetEngine is active
        if (!$this->is_jetengine_active()) {
            return;
        }
        
        // Hook into JetEngine listing rendering
        add_action('jet-engine/listing/grid/before-render', [$this, 'preload_listing_attachments'], 5, 2);
        add_action('jet-engine/listing/before-render', [$this, 'preload_listing_attachments'], 5, 2);
        
        // Hook into JetEngine gallery rendering
        add_action('jet-engine/listings/frontend/before-listing-item', [$this, 'preload_item_attachments'], 5, 2);
        
        // Hook into general JetEngine queries
        add_action('jet-engine/query-builder/query/after-query-setup', [$this, 'preload_query_attachments'], 10, 2);
        
        // Enable/disable based on admin setting
        $this->preloading_enabled = apply_filters('alo_jetengine_preloading_enabled', true);
        
        // Check admin setting
        add_action('admin_init', [$this, 'update_preloading_setting']);
        
        // Reset stats on new request
        add_action('init', [$this, 'reset_request_stats']);
        
        // Log stats at end of request
        add_action('wp_footer', [$this, 'log_preload_stats'], 999);
        add_action('admin_footer', [$this, 'log_preload_stats'], 999);
    }
    
    /**
     * Check if JetEngine is active
     */
    private function is_jetengine_active() {
        return class_exists('Jet_Engine') || function_exists('jet_engine');
    }
    
    /**
     * Set cache manager instance
     */
    public function set_cache_manager($cache_manager) {
        $this->cache_manager = $cache_manager;
    }
    
    /**
     * Set custom lookup table instance
     */
    public function set_custom_lookup_table($custom_lookup_table) {
        $this->custom_lookup_table = $custom_lookup_table;
    }
    
    /**
     * Preload attachments for listing grid rendering
     */
    public function preload_listing_attachments($listing_id, $settings = []) {
        if (!$this->preloading_enabled || !$this->cache_manager) {
            return;
        }
        
        $start_time = microtime(true);
        
        // Get listing configuration
        $listing_config = $this->get_listing_config($listing_id);
        
        if (!$listing_config) {
            return;
        }
        
        // Extract attachment URLs from listing
        $urls = $this->extract_listing_urls($listing_config, $settings);
        
        if (!empty($urls)) {
            // Preload URLs using batch lookup
            $this->preload_urls($urls);
            
            $end_time = microtime(true);
            $this->stats['preload_time'] += ($end_time - $start_time) * 1000;
            $this->stats['listings_processed']++;
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'ALO: JetEngine preloaded %d URLs for listing %d in %.2fms',
                    count($urls),
                    $listing_id,
                    ($end_time - $start_time) * 1000
                ));
            }
        }
    }
    
    /**
     * Preload attachments for individual listing items
     */
    public function preload_item_attachments($item, $listing) {
        if (!$this->preloading_enabled || !$this->cache_manager) {
            return;
        }
        
        // Extract URLs from item data
        $urls = $this->extract_item_urls($item, $listing);
        
        if (!empty($urls)) {
            $this->preload_urls($urls);
        }
    }
    
    /**
     * Preload attachments for JetEngine queries
     */
    public function preload_query_attachments($query, $query_args) {
        if (!$this->preloading_enabled || !$this->cache_manager) {
            return;
        }
        
        // Get query results if available
        $items = $query->get_items();
        
        if (!empty($items)) {
            $urls = $this->extract_query_urls($items, $query_args);
            
            if (!empty($urls)) {
                $this->preload_urls($urls);
            }
        }
    }
    
    /**
     * Get listing configuration
     */
    private function get_listing_config($listing_id) {
        if (!function_exists('get_post_meta')) {
            return null;
        }
        
        // Get JetEngine listing settings
        $listing_settings = get_post_meta($listing_id, '_elementor_data', true);
        
        if (!$listing_settings) {
            // Try JetEngine meta
            $listing_settings = get_post_meta($listing_id, '_jet_engine_listing_data', true);
        }
        
        return $listing_settings;
    }
    
    /**
     * Extract attachment URLs from listing configuration
     */
    private function extract_listing_urls($listing_config, $settings = []) {
        $urls = [];
        
        if (!is_string($listing_config)) {
            return $urls;
        }
        
        // Parse JSON if needed
        if (is_string($listing_config) && strpos($listing_config, '[') === 0) {
            $listing_config = json_decode($listing_config, true);
        }
        
        if (!is_array($listing_config)) {
            return $urls;
        }
        
        // Recursively search for image and gallery fields
        $urls = array_merge($urls, $this->search_config_for_urls($listing_config));
        
        // Get URLs from current query if available
        if (isset($settings['query']) || isset($settings['meta_query'])) {
            $query_urls = $this->extract_query_based_urls($settings);
            $urls = array_merge($urls, $query_urls);
        }
        
        return array_unique(array_filter($urls));
    }
    
    /**
     * Recursively search configuration for attachment URLs
     */
    private function search_config_for_urls($config, $depth = 0) {
        $urls = [];
        
        // Prevent infinite recursion
        if ($depth > 10) {
            return $urls;
        }
        
        if (!is_array($config)) {
            return $urls;
        }
        
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                // Recurse into nested arrays
                $nested_urls = $this->search_config_for_urls($value, $depth + 1);
                $urls = array_merge($urls, $nested_urls);
            } elseif (is_string($value)) {
                // Check if this looks like an attachment URL
                if ($this->looks_like_attachment_url($value)) {
                    $urls[] = $value;
                }
                
                // Check for dynamic fields that might contain images
                if ($this->is_image_field($key, $value)) {
                    $field_urls = $this->extract_field_urls($value);
                    $urls = array_merge($urls, $field_urls);
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Extract URLs from individual item data
     */
    private function extract_item_urls($item, $listing) {
        $urls = [];
        
        if (!is_array($item) && !is_object($item)) {
            return $urls;
        }
        
        // Convert object to array for easier processing
        if (is_object($item)) {
            $item = (array) $item;
        }
        
        // Look for image fields in item data
        foreach ($item as $key => $value) {
            if (is_string($value) && $this->looks_like_attachment_url($value)) {
                $urls[] = $value;
            } elseif (is_array($value)) {
                // Handle gallery fields
                foreach ($value as $sub_value) {
                    if (is_string($sub_value) && $this->looks_like_attachment_url($sub_value)) {
                        $urls[] = $sub_value;
                    }
                }
            }
        }
        
        // Extract URLs from common meta fields
        if (isset($item['ID'])) {
            $meta_urls = $this->extract_post_meta_urls($item['ID']);
            $urls = array_merge($urls, $meta_urls);
        }
        
        return array_unique(array_filter($urls));
    }
    
    /**
     * Extract URLs from query results
     */
    private function extract_query_urls($items, $query_args) {
        $urls = [];
        
        foreach ($items as $item) {
            $item_urls = $this->extract_item_urls($item, null);
            $urls = array_merge($urls, $item_urls);
        }
        
        return array_unique(array_filter($urls));
    }
    
    /**
     * Extract URLs based on query settings
     */
    private function extract_query_based_urls($settings) {
        $urls = [];
        
        // Get current query results if we're in a query context
        global $wp_query;
        
        if ($wp_query && $wp_query->posts) {
            foreach ($wp_query->posts as $post) {
                $post_urls = $this->extract_post_meta_urls($post->ID);
                $urls = array_merge($urls, $post_urls);
            }
        }
        
        return $urls;
    }
    
    /**
     * Extract attachment URLs from post meta
     */
    private function extract_post_meta_urls($post_id) {
        $urls = [];
        
        if (!$post_id) {
            return $urls;
        }
        
        // Common meta fields that might contain attachment URLs
        $image_meta_keys = [
            '_thumbnail_id',
            'featured_image',
            'gallery',
            'image',
            'images',
            'attachment',
            'file',
            'media'
        ];
        
        // Apply filter to allow customization
        $image_meta_keys = apply_filters('alo_jetengine_image_meta_keys', $image_meta_keys);
        
        foreach ($image_meta_keys as $meta_key) {
            $meta_value = get_post_meta($post_id, $meta_key, true);
            
            if (is_string($meta_value)) {
                if ($this->looks_like_attachment_url($meta_value)) {
                    $urls[] = $meta_value;
                } elseif (is_numeric($meta_value)) {
                    // Attachment ID - convert to URL
                    $url = wp_get_attachment_url($meta_value);
                    if ($url) {
                        $urls[] = $url;
                    }
                }
            } elseif (is_array($meta_value)) {
                foreach ($meta_value as $value) {
                    if (is_string($value) && $this->looks_like_attachment_url($value)) {
                        $urls[] = $value;
                    } elseif (is_numeric($value)) {
                        $url = wp_get_attachment_url($value);
                        if ($url) {
                            $urls[] = $url;
                        }
                    }
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Check if a string looks like an attachment URL
     */
    private function looks_like_attachment_url($string) {
        if (!is_string($string) || empty($string)) {
            return false;
        }
        
        // Check if it contains upload directory
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        
        if (strpos($string, $base_url) !== false) {
            return true;
        }
        
        // Check for common image extensions
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
        $extension = strtolower(pathinfo(parse_url($string, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        return in_array($extension, $image_extensions);
    }
    
    /**
     * Check if a field is likely an image field
     */
    private function is_image_field($key, $value) {
        $image_indicators = [
            'image', 'img', 'photo', 'picture', 'gallery', 'media',
            'thumbnail', 'avatar', 'featured', 'attachment', 'file'
        ];
        
        $key_lower = strtolower($key);
        
        foreach ($image_indicators as $indicator) {
            if (strpos($key_lower, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract URLs from field value
     */
    private function extract_field_urls($field_value) {
        $urls = [];
        
        if (is_string($field_value)) {
            // Try to parse as JSON (gallery fields)
            $decoded = json_decode($field_value, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item) && $this->looks_like_attachment_url($item)) {
                        $urls[] = $item;
                    } elseif (is_array($item) && isset($item['url'])) {
                        $urls[] = $item['url'];
                    }
                }
            } elseif ($this->looks_like_attachment_url($field_value)) {
                $urls[] = $field_value;
            }
        }
        
        return $urls;
    }
    
    /**
     * Preload URLs using batch lookup
     */
    public function preload_urls($urls) {
        if (empty($urls) || !$this->cache_manager) {
            return;
        }
        
        $start_time = microtime(true);
        
        // Use custom lookup table if available for even faster preloading
        if ($this->custom_lookup_table && $this->custom_lookup_table->table_exists()) {
            $results = $this->preload_via_custom_table($urls);
        } else {
            // Fall back to cache manager batch lookup
            $results = $this->cache_manager->batch_url_to_postid($urls);
        }
        
        $end_time = microtime(true);
        
        // Update statistics
        $this->stats['urls_preloaded'] += count($urls);
        $this->stats['urls_found'] += count(array_filter($results));
        $this->stats['preload_time'] += ($end_time - $start_time) * 1000;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $found_count = count(array_filter($results));
            error_log(sprintf(
                'ALO: JetEngine batch preloaded %d URLs, found %d attachments in %.2fms',
                count($urls),
                $found_count,
                ($end_time - $start_time) * 1000
            ));
        }
        
        return $results;
    }
    
    /**
     * Preload URLs via custom lookup table
     */
    private function preload_via_custom_table($urls) {
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        
        // Convert URLs to file paths
        $file_paths = [];
        $url_to_path_map = [];
        
        foreach ($urls as $url) {
            if (strpos($url, $base_url) === 0) {
                $file_path = str_replace($base_url . '/', '', $url);
                $file_path = ltrim($file_path, '/');
                $file_paths[] = $file_path;
                $url_to_path_map[$file_path] = $url;
            }
        }
        
        if (empty($file_paths)) {
            return [];
        }
        
        // Batch lookup via custom table
        $path_results = $this->custom_lookup_table->batch_get_post_ids($file_paths);
        
        // Convert back to URL => post_id format
        $results = [];
        foreach ($url_to_path_map as $path => $url) {
            $results[$url] = isset($path_results[$path]) ? $path_results[$path] : 0;
        }
        
        return $results;
    }
    
    /**
     * Reset statistics for new request
     */
    public function reset_request_stats() {
        if (!isset($this->stats['requests_processed'])) {
            $this->stats['requests_processed'] = 0;
        }
        
        $this->stats['requests_processed']++;
        
        // Reset per-request stats
        $this->stats['listings_processed'] = 0;
        $this->stats['urls_preloaded'] = 0;
        $this->stats['urls_found'] = 0;
        $this->stats['preload_time'] = 0;
    }
    
    /**
     * Log preloading statistics
     */
    public function log_preload_stats() {
        if (!$this->preloading_enabled || 
            ($this->stats['listings_processed'] === 0 && $this->stats['urls_preloaded'] === 0)) {
            return;
        }
        
        $current_url = $_SERVER['REQUEST_URI'] ?? 'unknown';
        
        $message = sprintf(
            'ALO JETENGINE PRELOAD: %d listings | %d URLs preloaded | %d found | %.2fms total | URL: %s',
            $this->stats['listings_processed'],
            $this->stats['urls_preloaded'],
            $this->stats['urls_found'],
            $this->stats['preload_time'],
            $current_url
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
        
        // Hook for custom logging
        do_action('alo_jetengine_preload_stats', $this->stats, $current_url);
    }
    
    /**
     * Get preloading statistics
     */
    public function get_stats() {
        return $this->stats;
    }
    
    /**
     * Enable/disable preloading
     */
    public function set_preloading_enabled($enabled) {
        $this->preloading_enabled = (bool) $enabled;
    }
    
    /**
     * Check if preloading is enabled
     */
    public function is_preloading_enabled() {
        return $this->preloading_enabled;
    }
    
    /**
     * Update preloading setting
     */
    public function update_preloading_setting() {
        $this->preloading_enabled = apply_filters('alo_jetengine_preloading_enabled', true);
    }
} 