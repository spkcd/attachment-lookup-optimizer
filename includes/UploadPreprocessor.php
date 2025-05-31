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
     * Initialize upload hooks
     */
    private function init_hooks() {
        // WordPress core upload handling
        add_filter('wp_handle_upload', [$this, 'handle_wp_upload'], 10, 2);
        add_action('add_attachment', [$this, 'process_new_attachment'], 10, 1);
        
        // JetFormBuilder hooks
        add_action('jet-form-builder/file-upload/after-upload', [$this, 'handle_jetformbuilder_upload'], 10, 3);
        
        // Hook into attachment metadata update
        add_filter('wp_update_attachment_metadata', [$this, 'update_attachment_metadata'], 10, 2);
        
        // Bulk processing for existing attachments
        add_action('wp_ajax_alo_bulk_process_attachments', [$this, 'ajax_bulk_process_attachments']);
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
            return;
        }
        
        // Store the reverse mapping
        $this->store_reverse_mapping($attachment_id, $upload_info);
        
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
    public function bulk_process_existing_attachments($limit = 100, $offset = 0) {
        global $wpdb;
        
        // Get attachments that don't have cached file path meta
        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID 
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                 AND pm.meta_key = %s
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.meta_id IS NULL
             LIMIT %d OFFSET %d",
            self::CACHED_FILE_PATH_META,
            $limit,
            $offset
        ));
        
        $processed = 0;
        foreach ($attachments as $attachment) {
            if ($this->generate_reverse_mapping_from_metadata($attachment->ID)) {
                $processed++;
            }
        }
        
        return [
            'processed' => $processed,
            'total_found' => count($attachments),
            'has_more' => count($attachments) === $limit
        ];
    }
    
    /**
     * AJAX handler for bulk processing
     */
    public function ajax_bulk_process_attachments() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'alo_bulk_process')) {
            wp_die('Permission denied');
        }
        
        $offset = absint($_POST['offset'] ?? 0);
        $limit = 50; // Process 50 at a time
        
        $result = $this->bulk_process_existing_attachments($limit, $offset);
        
        wp_send_json_success($result);
    }
    
    /**
     * Get statistics about reverse mappings
     */
    public function get_stats() {
        global $wpdb;
        
        $total_attachments = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'attachment' 
             AND post_status = 'inherit'"
        );
        
        $cached_attachments = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = %s",
            self::CACHED_FILE_PATH_META
        ));
        
        $jetformbuilder_uploads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id
             WHERE pm.meta_key = %s
             AND pm2.meta_key = %s
             AND pm2.meta_value LIKE %s",
            self::CACHED_FILE_PATH_META,
            self::UPLOAD_SOURCE_META,
            '%jetformbuilder%'
        ));
        
        return [
            'total_attachments' => (int) $total_attachments,
            'cached_attachments' => (int) $cached_attachments,
            'jetformbuilder_uploads' => (int) $jetformbuilder_uploads,
            'coverage_percentage' => $total_attachments > 0 ? 
                round(($cached_attachments / $total_attachments) * 100, 2) : 0
        ];
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
} 