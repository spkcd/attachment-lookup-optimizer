<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Upload Preprocessor Class
 * 
 * Handles preprocessing of file uploads to store reverse mappings
 * for faster attachment lookups
 */
class UploadPreprocessor {
    
    /**
     * Meta key for storing cached file path
     */
    const CACHED_FILE_PATH_META = '_alo_cached_file_path';
    
    /**
     * Meta key for storing upload source
     */
    const UPLOAD_SOURCE_META = '_alo_upload_source';
    
    /**
     * Upload sources
     */
    const SOURCE_WORDPRESS = 'wordpress';
    const SOURCE_JETFORMBUILDER = 'jetformbuilder';
    const SOURCE_UNKNOWN = 'unknown';
    
    /**
     * Custom lookup table instance
     */
    private $custom_lookup_table;
    
    /**
     * BunnyCDN manager instance
     */
    private $bunny_cdn_manager;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Set custom lookup table instance
     */
    public function set_custom_lookup_table($custom_lookup_table) {
        $this->custom_lookup_table = $custom_lookup_table;
    }
    
    /**
     * Set BunnyCDN manager instance
     */
    public function set_bunny_cdn_manager($bunny_cdn_manager) {
        $this->bunny_cdn_manager = $bunny_cdn_manager;
    }
    
    /**
     * Initialize upload hooks
     */
    private function init_hooks() {
        // Hook into WordPress upload processing
        add_filter('wp_handle_upload', [$this, 'handle_wp_upload'], 10, 2);
        
        // Hook into attachment metadata updates
        add_filter('wp_update_attachment_metadata', [$this, 'update_attachment_metadata'], 10, 2);
        
        // Hook into JetFormBuilder uploads if available
        if (class_exists('JFB_Modules\\Form_Record\\Action_Handler')) {
            add_action('jet-form-builder/request/success', [$this, 'handle_jetformbuilder_upload'], 10, 3);
        }
        
        // Hook into attachment creation to process new attachments
        add_action('add_attachment', [$this, 'process_new_attachment'], 20);
        
        // Add AJAX handler for bulk processing
        add_action('wp_ajax_alo_bulk_process_upload_preprocessor', [$this, 'ajax_bulk_process_attachments']);
        
        // Hook into background processing
        add_action('alo_background_upload_processing', [$this, 'background_process_attachments']);
        
        // Only clear stats cache on significant changes that affect stats
        add_action('add_attachment', [$this, 'maybe_clear_stats_cache_on_attachment_add']);
        add_action('delete_attachment', [$this, 'clear_stats_cache']);
    }
    
    /**
     * Handle WordPress core file uploads
     */
    public function handle_wp_upload($upload, $context = null) {
        if (!isset($upload['file']) || !isset($upload['url'])) {
            return $upload;
        }
        
        // Store upload info for later processing in add_attachment hook
        $this->store_upload_info($upload['file'], $upload['url'], self::SOURCE_WORDPRESS);
        
        return $upload;
    }
    
    /**
     * Process new attachment after it's added to database
     */
    public function process_new_attachment($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return;
        }
        
        // Get stored upload info
        $upload_info = $this->get_stored_upload_info();
        
        if (!$upload_info) {
            // Fallback: generate from attachment metadata
            $this->generate_reverse_mapping_from_metadata($attachment_id);
            // Try to upload to BunnyCDN even for fallback cases
            $this->upload_to_bunnycdn($attachment_id);
            return;
        }
        
        // Store the reverse mapping
        $this->store_reverse_mapping($attachment_id, $upload_info);
        
        // Upload to BunnyCDN if enabled
        $this->upload_to_bunnycdn($attachment_id, $upload_info);
        
        // Clean up stored info
        $this->clear_stored_upload_info();
    }
    
    /**
     * Handle JetFormBuilder file uploads
     */
    public function handle_jetformbuilder_upload($file_data, $field_settings, $form_id) {
        if (!isset($file_data['attachment_id']) || !isset($file_data['file_path'])) {
            return;
        }
        
        $attachment_id = $file_data['attachment_id'];
        $file_path = $file_data['file_path'];
        
        // Create upload info structure
        $upload_info = [
            'file_path' => $file_path,
            'url' => wp_get_attachment_url($attachment_id),
            'source' => self::SOURCE_JETFORMBUILDER,
            'form_id' => $form_id,
            'field_name' => $field_settings['name'] ?? 'unknown'
        ];
        
        // Store the reverse mapping immediately
        $this->store_reverse_mapping($attachment_id, $upload_info);
        
        // Upload to BunnyCDN if enabled
        $this->upload_to_bunnycdn($attachment_id, $upload_info);
    }
    
    /**
     * Store upload info temporarily for processing
     */
    private function store_upload_info($file_path, $url, $source) {
        $upload_info = [
            'file_path' => $file_path,
            'url' => $url,
            'source' => $source,
            'timestamp' => time()
        ];
        
        // Store in transient for short-term use
        set_transient('alo_upload_info_' . md5($file_path), $upload_info, 300); // 5 minutes
    }
    
    /**
     * Get stored upload info
     */
    private function get_stored_upload_info() {
        // Try to find matching upload info from recent uploads
        global $wpdb;
        
        $recent_transients = $wpdb->get_results(
            "SELECT option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_alo_upload_info_%' 
             AND option_value IS NOT NULL"
        );
        
        foreach ($recent_transients as $transient) {
            $upload_info = maybe_unserialize($transient->option_value);
            if ($upload_info && isset($upload_info['timestamp'])) {
                // Use the most recent upload (within last 5 minutes)
                if (time() - $upload_info['timestamp'] < 300) {
                    return $upload_info;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Clear stored upload info
     */
    private function clear_stored_upload_info() {
        global $wpdb;
        
        // Clean up old upload info transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_alo_upload_info_%' 
             OR option_name LIKE '_transient_timeout_alo_upload_info_%'"
        );
    }
    
    /**
     * Store reverse mapping in postmeta
     */
    private function store_reverse_mapping($attachment_id, $upload_info) {
        // Get relative file path
        $relative_path = $this->get_relative_file_path($upload_info['file_path']);
        
        if (!$relative_path) {
            return false;
        }
        
        // Store cached file path
        update_post_meta($attachment_id, self::CACHED_FILE_PATH_META, $relative_path);
        
        // Store upload source info
        $source_info = [
            'source' => $upload_info['source'],
            'timestamp' => time(),
            'url' => $upload_info['url'] ?? '',
            'form_id' => $upload_info['form_id'] ?? null,
            'field_name' => $upload_info['field_name'] ?? null
        ];
        
        update_post_meta($attachment_id, self::UPLOAD_SOURCE_META, $source_info);
        
        // Also store in custom lookup table for ultra-fast lookups
        if ($this->custom_lookup_table) {
            $this->custom_lookup_table->upsert_mapping($relative_path, $attachment_id);
        }
        
        // Log successful mapping creation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'ALO: Stored reverse mapping for attachment %d: %s (source: %s)',
                $attachment_id,
                $relative_path,
                $upload_info['source']
            ));
        }
        
        return true;
    }
    
    /**
     * Get relative file path from absolute path
     */
    private function get_relative_file_path($file_path) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        // Convert to relative path
        if (strpos($file_path, $base_dir) === 0) {
            return ltrim(str_replace($base_dir, '', $file_path), '/\\');
        }
        
        // If it's already a relative path, use as-is
        if (!path_is_absolute($file_path)) {
            return ltrim($file_path, '/\\');
        }
        
        return false;
    }
    
    /**
     * Generate reverse mapping from existing attachment metadata
     */
    private function generate_reverse_mapping_from_metadata($attachment_id) {
        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        
        if (!$attached_file) {
            return false;
        }
        
        // Store the reverse mapping
        update_post_meta($attachment_id, self::CACHED_FILE_PATH_META, $attached_file);
        
        // Store source as WordPress core
        $source_info = [
            'source' => self::SOURCE_WORDPRESS,
            'timestamp' => time(),
            'url' => wp_get_attachment_url($attachment_id),
            'generated' => true
        ];
        
        update_post_meta($attachment_id, self::UPLOAD_SOURCE_META, $source_info);
        
        // Also store in custom lookup table for ultra-fast lookups
        if ($this->custom_lookup_table) {
            $this->custom_lookup_table->upsert_mapping($attached_file, $attachment_id);
        }
        
        return true;
    }
    
    /**
     * Update attachment metadata hook
     */
    public function update_attachment_metadata($metadata, $attachment_id) {
        // Ensure reverse mapping exists when metadata is updated
        $cached_path = get_post_meta($attachment_id, self::CACHED_FILE_PATH_META, true);
        
        if (!$cached_path) {
            $this->generate_reverse_mapping_from_metadata($attachment_id);
        }
        
        return $metadata;
    }
    
    /**
     * Get reverse mapping for URL lookup optimization
     */
    public function get_attachment_by_file_path($file_path) {
        global $wpdb;
        
        // Normalize the file path
        $relative_path = $this->get_relative_file_path($file_path);
        
        if (!$relative_path) {
            return false;
        }
        
        // Query using our cached file path meta
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value = %s 
             LIMIT 1",
            self::CACHED_FILE_PATH_META,
            $relative_path
        ));
        
        return $attachment_id ? (int) $attachment_id : false;
    }
    
    /**
     * Bulk process existing attachments to add reverse mappings
     */
    public function bulk_process_existing_attachments($limit = 100, $offset = 0, $auto_process = false) {
        global $wpdb;
        
        // Get total count for progress calculation
        $total_count = $wpdb->get_var(
            "SELECT COUNT(*) 
             FROM {$wpdb->posts} 
             WHERE post_type = 'attachment' 
             AND post_status = 'inherit'"
        );
        
        // Get attachments that don't have cached file path meta
        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID 
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                 AND pm.meta_key = %s
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.meta_id IS NULL
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            self::CACHED_FILE_PATH_META,
            $limit,
            $offset
        ));
        
        $processed = 0;
        $errors = 0;
        
        foreach ($attachments as $attachment) {
            if (get_transient('alo_stop_bulk_process')) {
                break;
            }
            
            try {
                if ($this->generate_reverse_mapping_from_metadata($attachment->ID)) {
                    $processed++;
                } else {
                    $errors++;
                    if (!$auto_process) {
                        error_log('ALO: UploadPreprocessor failed to process attachment ' . $attachment->ID);
                    }
                }
            } catch (Exception $e) {
                if (!$auto_process) {
                    error_log('ALO: UploadPreprocessor error processing attachment ' . $attachment->ID . ': ' . $e->getMessage());
                }
                $errors++;
            }
        }
        
        // For background processing (auto_process = true), just return simple stats
        if ($auto_process) {
            // Clear stats cache if any processing was done
            if ($processed > 0) {
                $this->clear_stats_cache();
            }
            
            return [
                'processed' => $processed,
                'total_found' => count($attachments),
                'has_more' => count($attachments) === $limit
            ];
        }
        
        // For manual/AJAX calls, return detailed progress info
        $new_offset = $offset + count($attachments);
        $progress = $total_count > 0 ? min(100, round(($new_offset / $total_count) * 100, 1)) : 100;
        $is_complete = $new_offset >= $total_count || empty($attachments);
        
        $message = sprintf(__('Processed %d of %d attachments (%s%%) - %d successful, %d errors', 'attachment-lookup-optimizer'), 
                         $new_offset, $total_count, $progress, $processed, $errors);
        
        // Clear stats cache when processing is complete or when progress is made
        if ($is_complete || $processed > 0) {
            $this->clear_stats_cache();
        }
        
        return [
            'processed' => $processed,
            'errors' => $errors,
            'offset' => $new_offset,
            'progress' => $progress,
            'is_complete' => $is_complete,
            'message' => $message
        ];
    }
    
    /**
     * AJAX handler for bulk processing
     */
    public function ajax_bulk_process_attachments() {
        error_log('ALO: UploadPreprocessor AJAX bulk process called with data: ' . print_r($_POST, true));
        
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'alo_bulk_process')) {
            error_log('ALO: UploadPreprocessor AJAX - security check failed');
            wp_send_json_error(['message' => __('Permission denied', 'attachment-lookup-optimizer')]);
        }
        
        $action = sanitize_text_field($_POST['bulk_action'] ?? 'start');
        $offset = absint($_POST['offset'] ?? 0);
        $limit = 20; // Process 20 at a time to match AdminInterface
        
        error_log('ALO: UploadPreprocessor AJAX - action: ' . $action . ', offset: ' . $offset);
        
        if ($action === 'stop') {
            set_transient('alo_stop_bulk_process', true, 300);
            error_log('ALO: UploadPreprocessor AJAX - stop requested');
            wp_send_json_success(['message' => __('Bulk processing stopped', 'attachment-lookup-optimizer')]);
        }
        
        if (get_transient('alo_stop_bulk_process')) {
            delete_transient('alo_stop_bulk_process');
            error_log('ALO: UploadPreprocessor AJAX - stopping due to transient');
            wp_send_json_success([
                'is_complete' => true,
                'message' => __('Processing stopped by user', 'attachment-lookup-optimizer')
            ]);
        }
        
        // Call the main bulk processing method directly
        $result = $this->bulk_process_existing_attachments($limit, $offset, false);
        
        error_log('ALO: UploadPreprocessor AJAX response: ' . json_encode($result));
        
        wp_send_json_success($result);
    }
    
    /**
     * Get statistics about reverse mappings
     */
    public function get_stats() {
        global $wpdb;
        
        // Use SharedStatsCache to prevent duplicate queries
        $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
        $shared_cache = $plugin->get_shared_stats_cache();
        
        // Use longer cache duration and add debug logging
        $cache_key = 'alo_upload_preprocessor_stats_cache_v3'; // Increment version for shared cache
        $cached_stats = get_transient($cache_key);
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO UploadPreprocessor: get_stats() called, cache result: ' . ($cached_stats ? 'HIT' : 'MISS'));
        }
        
        if ($cached_stats === false) {
            $start_time = microtime(true);
            
            // Get shared stats (this consolidates duplicate queries with DatabaseManager)
            $shared_stats = $shared_cache->get_shared_stats();
            
            // Extract what we need and add our specific metrics
            $expensive_stats = [
                'total_attachments' => $shared_stats['attachment_count'],
                'cached_attachments' => $shared_stats['cached_attachments'],
                'jetformbuilder_uploads' => $shared_stats['jetformbuilder_uploads'],
                'coverage_percentage' => $shared_stats['coverage_percentage'],
                'cache_generated_at' => current_time('mysql'),
                'query_time_ms' => round((microtime(true) - $start_time) * 1000, 2),
                'using_shared_cache' => true
            ];
            
            // Cache for 15 minutes (900 seconds) - longer duration
            $cache_result = set_transient($cache_key, $expensive_stats, 900);
            
            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'ALO UploadPreprocessor: Generated stats using shared cache in %.2fms, cache set: %s', 
                    $expensive_stats['query_time_ms'], 
                    $cache_result ? 'SUCCESS' : 'FAILED'
                ));
            }
        } else {
            $expensive_stats = $cached_stats;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALO UploadPreprocessor: Using cached stats from ' . ($expensive_stats['cache_generated_at'] ?? 'unknown'));
            }
        }
        
        return $expensive_stats;
    }
    
    /**
     * Clear the upload preprocessor stats cache
     * Call this when attachments are added/removed or when bulk processing completes
     */
    public function clear_stats_cache() {
        delete_transient('alo_upload_preprocessor_stats_cache');
        delete_transient('alo_upload_preprocessor_stats_cache_v2');
        delete_transient('alo_upload_preprocessor_stats_cache_v3');
        
        // Also clear shared cache
        $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
        $shared_cache = $plugin->get_shared_stats_cache();
        if ($shared_cache) {
            $shared_cache->clear_attachment_caches();
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALO UploadPreprocessor: Stats cache cleared');
        }
    }
    
    /**
     * Check if attachment has reverse mapping
     */
    public function has_reverse_mapping($attachment_id) {
        return !empty(get_post_meta($attachment_id, self::CACHED_FILE_PATH_META, true));
    }
    
    /**
     * Get upload source info for attachment
     */
    public function get_upload_source($attachment_id) {
        return get_post_meta($attachment_id, self::UPLOAD_SOURCE_META, true);
    }
    
    /**
     * Process a single attachment by ID
     * Used for progress-based bulk processing
     */
    public function process_attachment($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }
        
        // Check if already processed
        if ($this->has_reverse_mapping($attachment_id)) {
            return true; // Already processed
        }
        
        // Generate reverse mapping from metadata
        $result = $this->generate_reverse_mapping_from_metadata($attachment_id);
        
        return $result !== false;
    }
    
    /**
     * Schedule background processing to catch up on unprocessed attachments
     */
    public function schedule_background_processing() {
        // Only schedule if not already scheduled
        if (!wp_next_scheduled('alo_background_upload_processing')) {
            // Run every 5 minutes to gradually process unprocessed attachments
            wp_schedule_event(time(), 'alo_5min', 'alo_background_upload_processing');
        }
    }
    
    /**
     * Background process unprocessed attachments
     */
    public function background_process_attachments() {
        // Process 50 attachments per run to avoid timeouts
        $this->bulk_process_existing_attachments(50, 0, true);
    }
    
    /**
     * Clear stats cache on significant changes that affect stats
     */
    public function maybe_clear_stats_cache_on_attachment_add($attachment_id) {
        // Implement logic to determine if the change is significant
        $this->clear_stats_cache();
    }
    
    /**
     * Upload attachment to BunnyCDN
     */
    private function upload_to_bunnycdn($attachment_id, $upload_info = null) {
        // Check if BunnyCDN manager is available and enabled
        if (!$this->bunny_cdn_manager || !$this->bunny_cdn_manager->is_enabled()) {
            return false;
        }
        
        try {
            // Get file path - try from upload_info first, then from attachment metadata
            $file_path = null;
            $filename = null;
            
            if ($upload_info && isset($upload_info['file_path'])) {
                $file_path = $upload_info['file_path'];
                $filename = basename($file_path);
            } else {
                // Fallback: get from attachment metadata
                $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
                if ($attached_file) {
                    $upload_dir = wp_upload_dir();
                    $file_path = $upload_dir['basedir'] . '/' . $attached_file;
                    $filename = basename($attached_file);
                }
            }
            
            if (!$file_path || !file_exists($file_path)) {
                error_log("ALO: BunnyCDN upload failed - file not found: {$file_path} for attachment {$attachment_id}");
                return false;
            }
            
            // Generate a unique filename to avoid conflicts
            $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
            $file_basename = pathinfo($filename, PATHINFO_FILENAME);
            $unique_filename = $file_basename . '_' . $attachment_id . '.' . $file_extension;
            
            // Upload to BunnyCDN with attachment ID for tracking
            $cdn_url = $this->bunny_cdn_manager->upload_file($file_path, $unique_filename, $attachment_id);
            
            if ($cdn_url) {
                // Store CDN URL in attachment meta
                update_post_meta($attachment_id, '_bunnycdn_url', $cdn_url);
                update_post_meta($attachment_id, '_bunnycdn_filename', $unique_filename);
                update_post_meta($attachment_id, '_bunnycdn_uploaded_at', current_time('mysql', true));
                
                // Rewrite post content URLs if enabled
                $this->rewrite_post_content_urls($attachment_id, $cdn_url);
                
                // Delete local files if offload is enabled
                if (get_option('alo_bunnycdn_offload_enabled', false)) {
                    $this->delete_local_files_after_upload($attachment_id);
                }
                
                // Log successful upload
                error_log("ALO: Successfully uploaded attachment {$attachment_id} to BunnyCDN: {$cdn_url}");
                
                return $cdn_url;
            } else {
                error_log("ALO: BunnyCDN upload failed for attachment {$attachment_id} - no CDN URL returned");
                return false;
            }
            
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN upload exception for attachment {$attachment_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Rewrite post content URLs after successful upload
     * 
     * @param int $attachment_id
     * @param string $cdn_url
     */
    private function rewrite_post_content_urls($attachment_id, $cdn_url) {
        try {
            $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
            if ($plugin) {
                $content_rewriter = $plugin->get_bunnycdn_content_rewriter();
                if ($content_rewriter) {
                    $results = $content_rewriter->rewrite_content_urls($attachment_id, $cdn_url);
                    
                    // Log the rewrite results
                    if (isset($results['enabled']) && !$results['enabled']) {
                        error_log("ALO: Upload preprocessor - content URL rewriting is disabled for attachment {$attachment_id}");
                    } elseif (isset($results['updated_posts']) && $results['updated_posts'] > 0) {
                        error_log("ALO: Upload preprocessor - content rewriter updated {$results['updated_posts']} posts with {$results['total_replacements']} URL replacements for attachment {$attachment_id}");
                    } elseif (isset($results['message'])) {
                        error_log("ALO: Upload preprocessor - content rewriter for attachment {$attachment_id}: {$results['message']}");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("ALO: Upload preprocessor - content rewriter error for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Delete local files after successful upload to BunnyCDN
     * 
     * @param int $attachment_id Attachment post ID
     */
    private function delete_local_files_after_upload($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return;
        }
        
        try {
            // Get the main attachment file
            $local_path = get_attached_file($attachment_id);
            if (!$local_path || !file_exists($local_path)) {
                error_log("ALO: Upload preprocessor offload - main file not found for attachment {$attachment_id}: {$local_path}");
                return;
            }
            
            // Delete the main file
            if (@unlink($local_path)) {
                error_log("ALO: Upload preprocessor offload - deleted main file for attachment {$attachment_id}: {$local_path}");
            } else {
                error_log("ALO: Upload preprocessor offload - failed to delete main file for attachment {$attachment_id}: {$local_path}");
                return; // Don't proceed if main file deletion failed
            }
            
            // Get attachment metadata to find image sizes
            $meta = wp_get_attachment_metadata($attachment_id);
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                $upload_dir = wp_upload_dir();
                $base_dir = $upload_dir['basedir'];
                
                // Get the directory of the main file
                if (!empty($meta['file'])) {
                    $file_dir = dirname($meta['file']);
                    $full_base_dir = $base_dir . '/' . $file_dir;
                } else {
                    $full_base_dir = dirname($local_path);
                }
                
                // Delete each image size variant
                foreach ($meta['sizes'] as $size_name => $size_data) {
                    if (!empty($size_data['file'])) {
                        $size_file_path = $full_base_dir . '/' . $size_data['file'];
                        
                        if (file_exists($size_file_path)) {
                            if (@unlink($size_file_path)) {
                                error_log("ALO: Upload preprocessor offload - deleted {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            } else {
                                error_log("ALO: Upload preprocessor offload - failed to delete {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            }
                        }
                    }
                }
            }
            
            // Mark attachment as offloaded
            update_post_meta($attachment_id, '_bunnycdn_offloaded', true);
            update_post_meta($attachment_id, '_bunnycdn_offloaded_at', current_time('mysql', true));
            
            error_log("ALO: Upload preprocessor offload - completed for attachment {$attachment_id}");
            
        } catch (Exception $e) {
            error_log("ALO: Upload preprocessor offload - exception for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Get BunnyCDN URL for an attachment
     */
    public function get_bunnycdn_url($attachment_id) {
        if (!$attachment_id) {
            return false;
        }
        
        $cdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
        
        // Verify the URL is still valid (basic check)
        if ($cdn_url && filter_var($cdn_url, FILTER_VALIDATE_URL)) {
            return $cdn_url;
        }
        
        return false;
    }
    
    /**
     * Check if attachment has been uploaded to BunnyCDN
     */
    public function has_bunnycdn_upload($attachment_id) {
        if (!$attachment_id) {
            return false;
        }
        
        $cdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
        $uploaded_at = get_post_meta($attachment_id, '_bunnycdn_uploaded_at', true);
        
        return !empty($cdn_url) && !empty($uploaded_at);
    }
    
    /**
     * Get BunnyCDN upload info for an attachment
     */
    public function get_bunnycdn_info($attachment_id) {
        if (!$attachment_id) {
            return false;
        }
        
        $cdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
        $filename = get_post_meta($attachment_id, '_bunnycdn_filename', true);
        $uploaded_at = get_post_meta($attachment_id, '_bunnycdn_uploaded_at', true);
        
        if (!$cdn_url) {
            return false;
        }
        
        return [
            'cdn_url' => $cdn_url,
            'filename' => $filename,
            'uploaded_at' => $uploaded_at,
            'has_upload' => true
        ];
    }
} 