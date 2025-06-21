<?php
/**
 * BunnyCDN Content Rewriter
 * 
 * Handles rewriting post content URLs from local URLs to BunnyCDN URLs
 * after successful uploads to BunnyCDN.
 */

namespace AttachmentLookupOptimizer;

class BunnyCDNContentRewriter {
    
    /**
     * Rewrite post content URLs after successful BunnyCDN upload
     * 
     * @param int $attachment_id The attachment ID that was uploaded
     * @param string $cdn_url The BunnyCDN URL for the uploaded file
     * @return array Results of the rewriting process
     */
    public function rewrite_content_urls($attachment_id, $cdn_url) {
        // Check if URL rewriting is enabled
        if (!get_option('alo_bunnycdn_rewrite_urls', false)) {
            return [
                'enabled' => false,
                'message' => 'URL rewriting is disabled in settings'
            ];
        }
        
        if (!$attachment_id || !$cdn_url) {
            return [
                'success' => false,
                'message' => 'Invalid attachment ID or CDN URL'
            ];
        }
        
        try {
            // Get attachment file information
            $attachment_info = $this->get_attachment_info($attachment_id);
            if (!$attachment_info) {
                return [
                    'success' => false,
                    'message' => 'Could not get attachment information'
                ];
            }
            
            // Find posts containing the local URLs
            $posts_to_update = $this->find_posts_with_attachment_urls($attachment_info);
            
            if (empty($posts_to_update)) {
                return [
                    'success' => true,
                    'updated_posts' => 0,
                    'message' => 'No posts found containing the attachment URLs'
                ];
            }
            
            // Rewrite URLs in found posts
            $results = $this->update_posts_content($posts_to_update, $attachment_info, $cdn_url);
            
            // Log the results
            $this->log_rewrite_results($attachment_id, $results);
            
            return $results;
            
        } catch (Exception $e) {
            error_log("BunnyCDN Content Rewriter Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get attachment file information
     * 
     * @param int $attachment_id
     * @return array|false Attachment information or false on failure
     */
    private function get_attachment_info($attachment_id) {
        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (!$attached_file) {
            return false;
        }
        
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        
        // Get attachment metadata for image sizes
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        $urls_to_replace = [];
        
        // Add main file URL
        $main_url = $base_url . '/' . $attached_file;
        $urls_to_replace[] = $main_url;
        
        // Add image size URLs if available
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $file_dir = dirname($attached_file);
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (!empty($size_data['file'])) {
                    $size_url = $base_url . '/' . $file_dir . '/' . $size_data['file'];
                    $urls_to_replace[] = $size_url;
                }
            }
        }
        
        return [
            'attachment_id' => $attachment_id,
            'attached_file' => $attached_file,
            'main_url' => $main_url,
            'urls_to_replace' => array_unique($urls_to_replace),
            'basename' => basename($attached_file),
            'filename_without_ext' => pathinfo($attached_file, PATHINFO_FILENAME)
        ];
    }
    
    /**
     * Find posts containing attachment URLs
     * 
     * @param array $attachment_info
     * @return array Posts that contain the attachment URLs
     */
    private function find_posts_with_attachment_urls($attachment_info) {
        global $wpdb;
        
        $posts_found = [];
        
        // Search for each URL variant
        foreach ($attachment_info['urls_to_replace'] as $url) {
            // Escape the URL for SQL LIKE query
            $escaped_url = $wpdb->esc_like($url);
            
            $query = $wpdb->prepare("
                SELECT DISTINCT p.ID, p.post_title, p.post_type
                FROM {$wpdb->posts} p
                WHERE p.post_type IN ('post', 'page')
                AND p.post_status = 'publish'
                AND p.post_content LIKE %s
                AND p.ID NOT IN (
                    SELECT post_id 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_bunnycdn_rewritten' 
                    AND meta_value = %s
                )
            ", '%' . $escaped_url . '%', $attachment_info['attachment_id']);
            
            $results = $wpdb->get_results($query);
            
            if ($results) {
                foreach ($results as $post) {
                    $posts_found[$post->ID] = [
                        'ID' => $post->ID,
                        'post_title' => $post->post_title,
                        'post_type' => $post->post_type,
                        'matching_url' => $url
                    ];
                }
            }
        }
        
        return array_values($posts_found);
    }
    
    /**
     * Update posts content with CDN URLs
     * 
     * @param array $posts_to_update
     * @param array $attachment_info
     * @param string $cdn_url
     * @return array Results of the update process
     */
    private function update_posts_content($posts_to_update, $attachment_info, $cdn_url) {
        $results = [
            'success' => true,
            'updated_posts' => 0,
            'failed_posts' => 0,
            'total_replacements' => 0,
            'updated_post_ids' => [],
            'failed_post_ids' => [],
            'details' => []
        ];
        
        foreach ($posts_to_update as $post_info) {
            try {
                $post = get_post($post_info['ID']);
                if (!$post) {
                    $results['failed_posts']++;
                    $results['failed_post_ids'][] = $post_info['ID'];
                    continue;
                }
                
                $original_content = $post->post_content;
                $updated_content = $original_content;
                $replacements_made = 0;
                
                // Replace each URL variant with the CDN URL
                foreach ($attachment_info['urls_to_replace'] as $local_url) {
                    $replacement_count = 0;
                    $updated_content = str_replace($local_url, $cdn_url, $updated_content, $replacement_count);
                    $replacements_made += $replacement_count;
                }
                
                // Process JetEngine custom fields for this post
                $custom_field_replacements = $this->process_jetengine_custom_fields($post->ID, $attachment_info, $cdn_url);
                $replacements_made += $custom_field_replacements;
                
                // Only update if replacements were made
                if ($replacements_made > 0 && ($updated_content !== $original_content || $custom_field_replacements > 0)) {
                    // Update post content if it was modified
                    if ($updated_content !== $original_content) {
                        $update_result = wp_update_post([
                            'ID' => $post->ID,
                            'post_content' => $updated_content
                        ]);
                        
                        if (is_wp_error($update_result) || $update_result === 0) {
                            $results['failed_posts']++;
                            $results['failed_post_ids'][] = $post->ID;
                            
                            $results['details'][] = [
                                'post_id' => $post->ID,
                                'post_title' => $post->post_title,
                                'post_type' => $post->post_type,
                                'status' => 'failed',
                                'error' => is_wp_error($update_result) ? $update_result->get_error_message() : 'Content update failed'
                            ];
                            continue;
                        }
                    }
                    
                    // Mark post as rewritten for this attachment
                    $rewritten_attachments = get_post_meta($post->ID, '_bunnycdn_rewritten', true);
                    if (!is_array($rewritten_attachments)) {
                        $rewritten_attachments = [];
                    }
                    $rewritten_attachments[] = $attachment_info['attachment_id'];
                    update_post_meta($post->ID, '_bunnycdn_rewritten', array_unique($rewritten_attachments));
                    
                    // Store rewrite date and count for the new table
                    update_post_meta($post->ID, '_bunnycdn_rewrite_date', current_time('mysql'));
                    
                    // Update or increment the rewrite count
                    $current_count = get_post_meta($post->ID, '_bunnycdn_rewrite_count', true);
                    $new_count = $current_count ? intval($current_count) + $replacements_made : $replacements_made;
                    update_post_meta($post->ID, '_bunnycdn_rewrite_count', $new_count);
                    
                    $results['updated_posts']++;
                    $results['total_replacements'] += $replacements_made;
                    $results['updated_post_ids'][] = $post->ID;
                    
                    $results['details'][] = [
                        'post_id' => $post->ID,
                        'post_title' => $post->post_title,
                        'post_type' => $post->post_type,
                        'replacements' => $replacements_made,
                        'status' => 'success'
                    ];
                }
                
            } catch (Exception $e) {
                $results['failed_posts']++;
                $results['failed_post_ids'][] = $post_info['ID'];
                
                $results['details'][] = [
                    'post_id' => $post_info['ID'],
                    'post_title' => $post_info['post_title'] ?? 'Unknown',
                    'post_type' => $post_info['post_type'] ?? 'Unknown',
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Set overall success status
        $results['success'] = $results['failed_posts'] === 0;
        $results['message'] = sprintf(
            'Updated %d posts with %d total URL replacements. %d posts failed.',
            $results['updated_posts'],
            $results['total_replacements'],
            $results['failed_posts']
        );
        
        return $results;
    }
    
    /**
     * Log rewrite results
     * 
     * @param int $attachment_id
     * @param array $results
     */
    private function log_rewrite_results($attachment_id, $results) {
        $log_message = sprintf(
            '[BunnyCDN Content Rewriter] Attachment ID %d: %s - %s',
            $attachment_id,
            $results['success'] ? 'SUCCESS' : 'PARTIAL/FAILED',
            $results['message']
        );
        
        error_log($log_message);
        
        // Log detailed results if there were updates or failures
        if ($results['updated_posts'] > 0 || $results['failed_posts'] > 0) {
            foreach ($results['details'] as $detail) {
                $detail_message = sprintf(
                    '[BunnyCDN Content Rewriter] Post ID %d (%s): %s - %s',
                    $detail['post_id'],
                    $detail['post_type'],
                    strtoupper($detail['status']),
                    isset($detail['replacements']) ? "{$detail['replacements']} replacements" : $detail['error']
                );
                error_log($detail_message);
            }
        }
        
        // Also log to WordPress debug log if WP_DEBUG_LOG is enabled
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($log_message);
        }
    }
    
    /**
     * Get rewrite statistics for an attachment
     * 
     * @param int $attachment_id
     * @return array Statistics about posts rewritten for this attachment
     */
    public function get_rewrite_stats($attachment_id) {
        global $wpdb;
        
        // Count posts that have been rewritten for this attachment
        $rewritten_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_rewritten'
            AND meta_value LIKE %s
        ", '%' . $attachment_id . '%'));
        
        return [
            'attachment_id' => $attachment_id,
            'rewritten_posts' => (int) $rewritten_count
        ];
    }
    
    /**
     * Reset rewrite status for all posts (for testing purposes)
     * 
     * @return bool Success status
     */
    public function reset_rewrite_status() {
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        global $wpdb;
        
        $deleted = $wpdb->delete(
            $wpdb->postmeta,
            [
                'meta_key' => '_bunnycdn_rewritten'
            ]
        );
        
        return $deleted !== false;
    }
    
    /**
     * Process JetEngine custom fields for URL rewriting
     * 
     * @param int $post_id The post ID to process
     * @param array $attachment_info Attachment information
     * @param string $cdn_url The CDN URL to replace with
     * @return int Number of replacements made
     */
    private function process_jetengine_custom_fields($post_id, $attachment_info, $cdn_url) {
        // Check if custom field rewriting is enabled
        if (!get_option('alo_bunnycdn_rewrite_meta_enabled', false)) {
            return 0;
        }
        
        $total_replacements = 0;
        
        try {
            // Get all custom meta fields for the post
            $all_meta = get_post_meta($post_id);
            
            if (empty($all_meta)) {
                return 0;
            }
            
            // Get JetEngine meta boxes if available
            $jetengine_fields = $this->get_jetengine_fields($post_id);
            
            // Process JetFormBuilder fields first (they have specific handling)
            $jetformbuilder_replacements = $this->process_jetformbuilder_fields($post_id, $attachment_info, $cdn_url);
            $total_replacements += $jetformbuilder_replacements;
            
            foreach ($all_meta as $meta_key => $meta_values) {
                // Skip WordPress internal meta fields
                if (strpos($meta_key, '_') === 0 && !$this->is_jetengine_field($meta_key, $jetengine_fields)) {
                    continue;
                }
                
                // Process each meta value (meta can have multiple values)
                foreach ($meta_values as $meta_value) {
                    $original_value = $meta_value;
                    $updated_value = $this->process_meta_value($meta_value, $attachment_info, $cdn_url);
                    
                    if ($updated_value !== $original_value) {
                        // Update the meta field
                        update_post_meta($post_id, $meta_key, $updated_value, $original_value);
                        $total_replacements++;
                        
                        // Log the custom field update
                        error_log(sprintf(
                            '[BunnyCDN Custom Fields] Post %d, Field %s: Updated with CDN URL',
                            $post_id,
                            $meta_key
                        ));
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('[BunnyCDN Custom Fields] Error processing post ' . $post_id . ': ' . $e->getMessage());
        }
        
        return $total_replacements;
    }
    
    /**
     * Get JetEngine fields for a post
     * 
     * @param int $post_id
     * @return array JetEngine field definitions
     */
    private function get_jetengine_fields($post_id) {
        $jetengine_fields = [];
        
        // Check if JetEngine is active
        if (!class_exists('Jet_Engine') || !function_exists('jet_engine')) {
            return $jetengine_fields;
        }
        
        try {
            // Get post type
            $post_type = get_post_type($post_id);
            
            // Get JetEngine meta boxes for this post type
            if (method_exists(jet_engine()->meta_boxes, 'get_meta_boxes')) {
                $meta_boxes = jet_engine()->meta_boxes->get_meta_boxes();
                
                if (!empty($meta_boxes)) {
                    foreach ($meta_boxes as $meta_box) {
                        // Check if this meta box applies to the current post type
                        if (!empty($meta_box['args']['allowed_posts']) && 
                            in_array($post_type, $meta_box['args']['allowed_posts'])) {
                            
                            if (!empty($meta_box['meta_fields'])) {
                                foreach ($meta_box['meta_fields'] as $field) {
                                    $jetengine_fields[$field['name']] = $field;
                                }
                            }
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('[BunnyCDN Custom Fields] Error getting JetEngine fields: ' . $e->getMessage());
        }
        
        return $jetengine_fields;
    }
    
    /**
     * Check if a meta key is a JetEngine or JetFormBuilder field
     * 
     * @param string $meta_key
     * @param array $jetengine_fields
     * @return bool
     */
    private function is_jetengine_field($meta_key, $jetengine_fields) {
        // Check if it's in the JetEngine fields list
        if (isset($jetengine_fields[$meta_key])) {
            return true;
        }
        
        // Check for common JetEngine field patterns
        $jetengine_patterns = [
            'jet_',
            '_jet_',
            'jetengine_',
            '_jetengine_'
        ];
        
        foreach ($jetengine_patterns as $pattern) {
            if (strpos($meta_key, $pattern) === 0) {
                return true;
            }
        }
        
        // Check for JetFormBuilder field patterns
        if ($this->is_jetformbuilder_field($meta_key)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if a meta key is a JetFormBuilder field
     * 
     * @param string $meta_key
     * @return bool
     */
    private function is_jetformbuilder_field($meta_key) {
        // JetFormBuilder field patterns
        $jetformbuilder_patterns = [
            'field_',           // Standard JetFormBuilder field pattern
            'jetform_',         // Alternative pattern
            '_jetform_',        // Internal JetFormBuilder fields
            'jfb_',            // JetFormBuilder abbreviation
            '_jfb_'            // Internal JetFormBuilder abbreviation
        ];
        
        foreach ($jetformbuilder_patterns as $pattern) {
            if (strpos($meta_key, $pattern) === 0) {
                return true;
            }
        }
        
        // Check for numeric field pattern (field_123456)
        if (preg_match('/^field_\d+$/', $meta_key)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get JetFormBuilder fields for a post
     * 
     * @param int $post_id
     * @return array JetFormBuilder field information
     */
    private function get_jetformbuilder_fields($post_id) {
        $jetformbuilder_fields = [];
        
        // Check if JetFormBuilder is active
        if (!class_exists('Jet_Form_Builder\Plugin') && !function_exists('jet_form_builder')) {
            return $jetformbuilder_fields;
        }
        
        try {
            // Get all meta for the post to identify JetFormBuilder fields
            $all_meta = get_post_meta($post_id);
            
            foreach ($all_meta as $meta_key => $meta_values) {
                if ($this->is_jetformbuilder_field($meta_key)) {
                    // Try to determine field type based on value
                    $field_type = $this->detect_jetformbuilder_field_type($meta_key, $meta_values[0] ?? '');
                    
                    $jetformbuilder_fields[$meta_key] = [
                        'name' => $meta_key,
                        'type' => $field_type,
                        'is_file_field' => in_array($field_type, ['file', 'image', 'gallery'])
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log('[BunnyCDN Custom Fields] Error getting JetFormBuilder fields: ' . $e->getMessage());
        }
        
        return $jetformbuilder_fields;
    }
    
    /**
     * Detect JetFormBuilder field type based on value
     * 
     * @param string $field_name
     * @param mixed $field_value
     * @return string Field type
     */
    private function detect_jetformbuilder_field_type($field_name, $field_value) {
        // If value is numeric and corresponds to an attachment, it's likely a file field
        if (is_numeric($field_value) && intval($field_value) > 0) {
            $attachment_id = intval($field_value);
            if (get_post_type($attachment_id) === 'attachment') {
                $mime_type = get_post_mime_type($attachment_id);
                if (strpos($mime_type, 'image/') === 0) {
                    return 'image';
                } else {
                    return 'file';
                }
            }
        }
        
        // If value is a URL pointing to uploads directory, it's a file field
        if (is_string($field_value) && strpos($field_value, '/wp-content/uploads/') !== false) {
            // Check if it's an image based on extension
            $extension = strtolower(pathinfo($field_value, PATHINFO_EXTENSION));
            $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            
            if (in_array($extension, $image_extensions)) {
                return 'image';
            } else {
                return 'file';
            }
        }
        
        // If value is an array, it might be a gallery or multiple file field
        if (is_array($field_value)) {
            return 'gallery';
        }
        
        // Default to text field
        return 'text';
    }
    
    /**
     * Process JetFormBuilder file fields specifically
     * 
     * @param int $post_id
     * @param array $attachment_info
     * @param string $cdn_url
     * @return int Number of replacements made
     */
    private function process_jetformbuilder_fields($post_id, $attachment_info, $cdn_url) {
        $total_replacements = 0;
        
        try {
            // Get JetFormBuilder fields
            $jetformbuilder_fields = $this->get_jetformbuilder_fields($post_id);
            
            if (empty($jetformbuilder_fields)) {
                return 0;
            }
            
            foreach ($jetformbuilder_fields as $field_name => $field_info) {
                // Only process file-related fields
                if (!$field_info['is_file_field']) {
                    continue;
                }
                
                $field_value = get_post_meta($post_id, $field_name, true);
                
                if (empty($field_value)) {
                    continue;
                }
                
                $original_value = $field_value;
                $updated_value = $this->process_jetformbuilder_field_value(
                    $field_value, 
                    $field_info['type'], 
                    $attachment_info, 
                    $cdn_url
                );
                
                if ($updated_value !== $original_value) {
                    update_post_meta($post_id, $field_name, $updated_value);
                    $total_replacements++;
                    
                    error_log(sprintf(
                        '[BunnyCDN JetFormBuilder] Post %d, Field %s (%s): Updated with CDN URL',
                        $post_id,
                        $field_name,
                        $field_info['type']
                    ));
                }
            }
            
        } catch (Exception $e) {
            error_log('[BunnyCDN JetFormBuilder] Error processing post ' . $post_id . ': ' . $e->getMessage());
        }
        
        return $total_replacements;
    }
    
    /**
     * Process JetFormBuilder field value based on field type
     * 
     * @param mixed $value
     * @param string $field_type
     * @param array $attachment_info
     * @param string $cdn_url
     * @return mixed
     */
    private function process_jetformbuilder_field_value($value, $field_type, $attachment_info, $cdn_url) {
        switch ($field_type) {
            case 'image':
            case 'file':
                return $this->process_jetformbuilder_single_file($value, $attachment_info, $cdn_url);
                
            case 'gallery':
                return $this->process_jetformbuilder_gallery($value, $attachment_info, $cdn_url);
                
            default:
                // For other field types, use standard processing
                return $this->process_meta_value($value, $attachment_info, $cdn_url);
        }
    }
    
    /**
     * Process single file field (image or file)
     * 
     * @param mixed $value
     * @param array $attachment_info
     * @param string $cdn_url
     * @return mixed
     */
    private function process_jetformbuilder_single_file($value, $attachment_info, $cdn_url) {
        // Handle attachment ID
        if (is_numeric($value) && intval($value) > 0) {
            $attachment_id = intval($value);
            
            // Check if this is the attachment we're processing
            if ($attachment_id == $attachment_info['attachment_id']) {
                // Keep as ID for now, but could be configured to return URL
                return $value;
            }
            
            // Check if this attachment has a CDN URL
            if (get_post_type($attachment_id) === 'attachment') {
                $bunnycdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                if (!empty($bunnycdn_url)) {
                    // For JetFormBuilder, we might want to keep IDs or convert to URLs
                    // This could be made configurable
                    return $value; // Keep as ID for now
                }
            }
        }
        
        // Handle URL string
        if (is_string($value) && strpos($value, '/wp-content/uploads/') !== false) {
            // Replace with CDN URL if this matches our attachment
            foreach ($attachment_info['urls_to_replace'] as $local_url) {
                if ($value === $local_url) {
                    return $cdn_url;
                }
            }
            
            // Try to resolve other URLs to CDN URLs
            $attachment_id = attachment_url_to_postid($value);
            if ($attachment_id) {
                $bunnycdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                if (!empty($bunnycdn_url)) {
                    return $bunnycdn_url;
                }
            }
        }
        
        return $value;
    }
    
    /**
     * Process gallery field (array of files)
     * 
     * @param mixed $value
     * @param array $attachment_info
     * @param string $cdn_url
     * @return mixed
     */
    private function process_jetformbuilder_gallery($value, $attachment_info, $cdn_url) {
        if (!is_array($value)) {
            return $value;
        }
        
        $processed_gallery = [];
        
        foreach ($value as $key => $item) {
            $processed_gallery[$key] = $this->process_jetformbuilder_single_file($item, $attachment_info, $cdn_url);
        }
        
        return $processed_gallery;
    }
    
    /**
     * Process a meta value for URL rewriting
     * 
     * @param mixed $meta_value The meta value to process
     * @param array $attachment_info Attachment information
     * @param string $cdn_url The CDN URL to replace with
     * @return mixed The processed meta value
     */
    private function process_meta_value($meta_value, $attachment_info, $cdn_url) {
        // Handle different types of meta values
        if (is_string($meta_value)) {
            return $this->process_string_meta_value($meta_value, $attachment_info, $cdn_url);
        } elseif (is_array($meta_value)) {
            return $this->process_array_meta_value($meta_value, $attachment_info, $cdn_url);
        } elseif (is_numeric($meta_value)) {
            return $this->process_numeric_meta_value($meta_value, $attachment_info, $cdn_url);
        }
        
        return $meta_value;
    }
    
    /**
     * Process string meta values (URLs, WYSIWYG content, etc.)
     * 
     * @param string $value
     * @param array $attachment_info
     * @param string $cdn_url
     * @return string
     */
    private function process_string_meta_value($value, $attachment_info, $cdn_url) {
        $original_value = $value;
        
        // Check if the string contains /wp-content/uploads/ URLs
        if (strpos($value, '/wp-content/uploads/') !== false) {
            // Replace each URL variant with the CDN URL
            foreach ($attachment_info['urls_to_replace'] as $local_url) {
                $value = str_replace($local_url, $cdn_url, $value);
            }
            
            // Also try to match by attachment URL resolution
            $value = $this->resolve_and_replace_attachment_urls($value, $cdn_url);
        }
        
        return $value;
    }
    
    /**
     * Process array meta values (repeater fields, groups, etc.)
     * 
     * @param array $value
     * @param array $attachment_info
     * @param string $cdn_url
     * @return array
     */
    private function process_array_meta_value($value, $attachment_info, $cdn_url) {
        $processed_array = [];
        
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $processed_array[$key] = $this->process_string_meta_value($item, $attachment_info, $cdn_url);
            } elseif (is_array($item)) {
                // Recursively process nested arrays (for complex repeater fields)
                $processed_array[$key] = $this->process_array_meta_value($item, $attachment_info, $cdn_url);
            } elseif (is_numeric($item)) {
                $processed_array[$key] = $this->process_numeric_meta_value($item, $attachment_info, $cdn_url);
            } else {
                $processed_array[$key] = $item;
            }
        }
        
        return $processed_array;
    }
    
    /**
     * Process numeric meta values (attachment IDs)
     * 
     * @param mixed $value
     * @param array $attachment_info
     * @param string $cdn_url
     * @return mixed
     */
    private function process_numeric_meta_value($value, $attachment_info, $cdn_url) {
        // Check if this numeric value is an attachment ID
        if (is_numeric($value) && intval($value) == $attachment_info['attachment_id']) {
            // This is the attachment ID we're processing
            // For now, we keep the ID as is since some fields expect IDs
            // But we could potentially store the CDN URL instead depending on field type
            return $value;
        }
        
        // Check if it's another attachment ID that might have a CDN URL
        if (is_numeric($value) && intval($value) > 0) {
            $attachment_id = intval($value);
            $bunnycdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
            
            if (!empty($bunnycdn_url)) {
                // Some JetEngine fields might expect URLs instead of IDs
                // This is configurable behavior that could be enhanced
                return $value; // Keep as ID for now
            }
        }
        
        return $value;
    }
    
    /**
     * Resolve attachment URLs and replace with CDN URLs
     * 
     * @param string $content
     * @param string $cdn_url
     * @return string
     */
    private function resolve_and_replace_attachment_urls($content, $cdn_url) {
        // Pattern to match /wp-content/uploads/ URLs
        $pattern = '/https?:\/\/[^\/\s]+\/wp-content\/uploads\/[^\s\'"<>]+/i';
        
        return preg_replace_callback($pattern, function($matches) use ($cdn_url) {
            $url = $matches[0];
            
            // Try to resolve the URL to an attachment ID
            $attachment_id = attachment_url_to_postid($url);
            
            if ($attachment_id) {
                // Check if this attachment has a BunnyCDN URL
                $bunnycdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                
                if (!empty($bunnycdn_url)) {
                    return $bunnycdn_url;
                }
            }
            
            // If no specific CDN URL found, return original
            return $url;
        }, $content);
    }
    
    /**
     * Get statistics about custom field processing
     * 
     * @param int $post_id
     * @return array Statistics about custom fields processed
     */
    public function get_custom_field_stats($post_id) {
        $all_meta = get_post_meta($post_id);
        $jetengine_fields = $this->get_jetengine_fields($post_id);
        
        $stats = [
            'total_meta_fields' => count($all_meta),
            'jetengine_fields' => count($jetengine_fields),
            'processable_fields' => 0,
            'fields_with_urls' => 0
        ];
        
        foreach ($all_meta as $meta_key => $meta_values) {
            // Skip WordPress internal meta fields
            if (strpos($meta_key, '_') === 0 && !$this->is_jetengine_field($meta_key, $jetengine_fields)) {
                continue;
            }
            
            $stats['processable_fields']++;
            
            // Check if any values contain URLs
            foreach ($meta_values as $meta_value) {
                if (is_string($meta_value) && strpos($meta_value, '/wp-content/uploads/') !== false) {
                    $stats['fields_with_urls']++;
                    break;
                }
            }
        }
        
        return $stats;
    }
} 