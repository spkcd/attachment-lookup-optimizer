<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * BunnyCDN Manager Class
 * 
 * Handles uploading media attachments to BunnyCDN storage and provides CDN URLs
 */
class BunnyCDNManager {
    
    /**
     * Option names for BunnyCDN settings
     */
    const ENABLED_OPTION = 'alo_bunnycdn_enabled';
    const API_KEY_OPTION = 'alo_bunnycdn_api_key';
    const STORAGE_ZONE_OPTION = 'alo_bunnycdn_storage_zone';
    const REGION_OPTION = 'alo_bunnycdn_region';
    const CUSTOM_HOSTNAME_OPTION = 'alo_bunnycdn_custom_hostname';
    const AUTO_UPLOAD_OPTION = 'alo_bunnycdn_auto_upload';
    
    /**
     * BunnyCDN API settings
     */
    private $api_key;
    private $storage_zone;
    private $region;
    private $custom_hostname;
    private $enabled;
    private $auto_upload;
    
    /**
     * BunnyCDN Storage API base URL
     */
    const STORAGE_API_BASE = 'https://storage.bunnycdn.com';
    
    /**
     * Default CDN hostname pattern
     */
    const DEFAULT_CDN_PATTERN = '{zone}.b-cdn.net';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_settings();
        $this->init_hooks();
    }
    
    /**
     * Load BunnyCDN settings from WordPress options
     */
    private function load_settings() {
        $this->enabled = get_option(self::ENABLED_OPTION, false);
        $this->api_key = get_option(self::API_KEY_OPTION, '');
        $this->storage_zone = $this->sanitize_storage_zone(get_option(self::STORAGE_ZONE_OPTION, ''));
        $this->region = get_option(self::REGION_OPTION, '');
        $this->custom_hostname = get_option(self::CUSTOM_HOSTNAME_OPTION, '');
        $this->auto_upload = get_option(self::AUTO_UPLOAD_OPTION, false);
    }
    
    /**
     * Initialize hooks for integration
     */
    private function init_hooks() {
        // Always register the URL filter, but it will check if enabled internally
        add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
        
        // Hook into image size functions to ensure full-resolution CDN images are used
        add_filter('wp_get_attachment_image_src', [$this, 'filter_attachment_image_src'], 10, 4);
        add_filter('image_downsize', [$this, 'filter_image_downsize'], 10, 3);
        
        // Hook into attachment deletion to clean up CDN files
        add_action('delete_attachment', [$this, 'handle_attachment_deletion'], 10, 1);
        
        if ($this->is_enabled()) {
            // Log initialization
            error_log('ALO: BunnyCDN Manager initialized with storage zone: ' . $this->storage_zone);
        }
    }
    
    /**
     * Set BunnyCDN credentials
     * 
     * @param string $api_key BunnyCDN API key
     * @param string $storage_zone Storage zone name
     * @param string $region Region (e.g., de, ny, sg)
     * @param string $custom_hostname Optional custom CDN hostname
     */
    public function set_credentials($api_key, $storage_zone, $region = '', $custom_hostname = '') {
        // Check permissions for credential changes
        if (!current_user_can('manage_options')) {
            error_log('ALO: BunnyCDN credential change failed - insufficient permissions');
            return;
        }
        
        $this->api_key = sanitize_text_field($api_key);
        $this->storage_zone = $this->sanitize_storage_zone(sanitize_text_field($storage_zone));
        $this->region = sanitize_text_field($region);
        $this->custom_hostname = sanitize_text_field($custom_hostname);
        
        // Update WordPress options
        update_option(self::API_KEY_OPTION, $this->api_key);
        update_option(self::STORAGE_ZONE_OPTION, $this->storage_zone);
        update_option(self::REGION_OPTION, $this->region);
        update_option(self::CUSTOM_HOSTNAME_OPTION, $this->custom_hostname);
        
        error_log('ALO: BunnyCDN credentials updated for storage zone: ' . $this->storage_zone);
    }
    
    /**
     * Check if BunnyCDN integration is enabled and properly configured
     * 
     * @return bool True if enabled and configured
     */
    public function is_enabled() {
        if (!$this->enabled) {
            return false;
        }
        
        if (empty($this->api_key)) {
            error_log('ALO: BunnyCDN integration disabled - API key is empty');
            return false;
        }
        
        if (empty($this->storage_zone)) {
            error_log('ALO: BunnyCDN integration disabled - storage zone is empty');
            return false;
        }
        
        // Validate storage zone format
        if (strpos($this->storage_zone, '.') !== false || 
            strpos($this->storage_zone, '/') !== false ||
            strpos($this->storage_zone, 'http') !== false) {
            error_log('ALO: BunnyCDN integration disabled - invalid storage zone format: ' . $this->storage_zone . ' (should be just the zone name, e.g., "myzone")');
            return false;
        }
        
        return true;
    }
    
    /**
     * Enable or disable BunnyCDN integration
     * 
     * @param bool $enabled Enable or disable
     */
    public function set_enabled($enabled) {
        // Check permissions for settings changes
        if (!current_user_can('manage_options')) {
            error_log('ALO: BunnyCDN settings change failed - insufficient permissions');
            return;
        }
        
        $this->enabled = (bool) $enabled;
        update_option(self::ENABLED_OPTION, $this->enabled);
        
        if ($this->enabled) {
            error_log('ALO: BunnyCDN integration enabled');
        } else {
            error_log('ALO: BunnyCDN integration disabled');
        }
    }
    
    /**
     * Enable or disable automatic upload on attachment creation
     * 
     * @param bool $auto_upload Enable or disable auto upload
     */
    public function set_auto_upload($auto_upload) {
        // Check permissions for settings changes
        if (!current_user_can('manage_options')) {
            error_log('ALO: BunnyCDN settings change failed - insufficient permissions');
            return;
        }
        
        $this->auto_upload = (bool) $auto_upload;
        update_option(self::AUTO_UPLOAD_OPTION, $this->auto_upload);
    }
    
    /**
     * Upload a file to BunnyCDN storage
     * 
     * @param string $local_path Full local file path
     * @param string $filename Remote filename/path (e.g., 'uploads/2024/01/image.jpg')
     * @param int|null $attachment_id Optional attachment ID for tracking upload attempts
     * @return string|false CDN URL on success, false on failure
     */
    public function upload_file($local_path, $filename, $attachment_id = null) {
        // Track upload attempt if attachment ID provided
        if ($attachment_id) {
            $this->track_upload_attempt($attachment_id);
        }
        
        // Check permissions for admin-initiated uploads
        if (is_admin() && !current_user_can('manage_options')) {
            error_log('ALO: BunnyCDN upload failed - insufficient permissions');
            if ($attachment_id) {
                $this->track_upload_status($attachment_id, 'error: insufficient permissions');
            }
            return false;
        }
        
        if (!$this->is_enabled()) {
            error_log('ALO: BunnyCDN upload failed - integration not enabled or configured');
            if ($attachment_id) {
                $this->track_upload_status($attachment_id, 'error: not enabled or configured');
            }
            return false;
        }
        
        // Validate local file exists
        if (!file_exists($local_path) || !is_readable($local_path)) {
            error_log('ALO: BunnyCDN upload failed - local file not found or not readable: ' . $local_path);
            if ($attachment_id) {
                $this->track_upload_status($attachment_id, 'error: file not found or not readable');
            }
            return false;
        }
        
        // Sanitize filename (remove leading slash, normalize path)
        $filename = ltrim($filename, '/');
        $filename = str_replace('\\', '/', $filename);
        
        // Build API endpoint URL
        $api_url = $this->build_storage_api_url($filename);
        
        // Read file content
        $file_content = file_get_contents($local_path);
        if ($file_content === false) {
            error_log('ALO: BunnyCDN upload failed - could not read file content: ' . $local_path);
            if ($attachment_id) {
                $this->track_upload_status($attachment_id, 'error: could not read file content');
            }
            return false;
        }
        
        // Prepare headers
        $headers = [
            'AccessKey' => $this->api_key,
            'Content-Type' => $this->get_mime_type($local_path),
            'Content-Length' => strlen($file_content),
        ];
        
        // Prepare request arguments with optimized settings
        $args = [
            'method' => 'PUT',
            'headers' => $headers,
            'body' => $file_content,
            'timeout' => 60, // Increased timeout for large files
            'user-agent' => 'AttachmentLookupOptimizer/' . ALO_VERSION,
            'compress' => false, // Disable compression for faster uploads
            'decompress' => false, // Disable decompression
            'stream' => false, // Keep false for PUT requests
            'sslverify' => true,
        ];
        
        error_log('ALO: BunnyCDN uploading file to: ' . $api_url . ' (size: ' . strlen($file_content) . ' bytes)');
        
        // Make the API request
        $response = wp_remote_request($api_url, $args);
        
        // Check for request errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('ALO: BunnyCDN upload failed - request error: ' . $error_message);
            if ($attachment_id) {
                // Determine specific error type
                $status = 'error: request failed';
                if (strpos($error_message, 'timeout') !== false) {
                    $status = 'error: timeout';
                } elseif (strpos($error_message, 'connection') !== false) {
                    $status = 'error: connection failed';
                }
                $this->track_upload_status($attachment_id, $status);
            }
            return false;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            // Success - build and return CDN URL
            $cdn_url = $this->build_cdn_url($filename);
            error_log('ALO: BunnyCDN upload successful - CDN URL: ' . $cdn_url);
            if ($attachment_id) {
                $this->track_upload_status($attachment_id, 'success');
                
                // Delete local files if offload is enabled
                if (get_option('alo_bunnycdn_offload_enabled', false)) {
                    $this->delete_local_files_after_upload($attachment_id);
                }
            }
            return $cdn_url;
        } else {
            // Error - log details
            error_log('ALO: BunnyCDN upload failed - HTTP ' . $response_code . ': ' . $response_body);
            if ($attachment_id) {
                // Determine specific HTTP error type
                $status = 'error: HTTP ' . $response_code;
                if ($response_code === 401) {
                    $status = 'error: unauthorized';
                } elseif ($response_code === 403) {
                    $status = 'error: forbidden';
                } elseif ($response_code === 404) {
                    $status = 'error: not found';
                } elseif ($response_code >= 500) {
                    $status = 'error: server error';
                }
                $this->track_upload_status($attachment_id, $status);
            }
            return false;
        }
    }
    
    /**
     * Track upload attempt by incrementing attempt counter
     * 
     * @param int $attachment_id Attachment post ID
     */
    private function track_upload_attempt($attachment_id) {
        if (!$attachment_id) {
            return;
        }
        
        $current_attempts = (int) get_post_meta($attachment_id, '_bunnycdn_upload_attempts', true);
        update_post_meta($attachment_id, '_bunnycdn_upload_attempts', $current_attempts + 1);
        
        error_log('ALO: BunnyCDN tracking upload attempt #' . ($current_attempts + 1) . ' for attachment ID: ' . $attachment_id);
    }
    
    /**
     * Track upload status and timestamp
     * 
     * @param int $attachment_id Attachment post ID
     * @param string $status Upload status (e.g., 'success', 'error: timeout')
     */
    private function track_upload_status($attachment_id, $status) {
        if (!$attachment_id) {
            return;
        }
        
        update_post_meta($attachment_id, '_bunnycdn_last_upload_status', $status);
        update_post_meta($attachment_id, '_bunnycdn_last_upload_time', current_time('mysql'));
        
        error_log('ALO: BunnyCDN tracking upload status for attachment ID ' . $attachment_id . ': ' . $status);
    }
    
    /**
     * Delete a file from BunnyCDN storage
     * 
     * @param string $filename Remote filename/path
     * @return bool True on success, false on failure
     */
    public function delete_file($filename) {
        // Check permissions for admin-initiated deletions
        if (is_admin() && !current_user_can('manage_options')) {
            error_log('ALO: BunnyCDN delete failed - insufficient permissions');
            return false;
        }
        
        if (!$this->is_enabled()) {
            error_log('ALO: BunnyCDN delete failed - integration not enabled or configured');
            return false;
        }
        
        // Validate filename
        if (empty($filename)) {
            error_log('ALO: BunnyCDN delete failed - empty filename provided');
            return false;
        }
        
        // Sanitize filename
        $filename = ltrim($filename, '/');
        $filename = str_replace('\\', '/', $filename);
        
        // Build API endpoint URL
        $api_url = $this->build_storage_api_url($filename);
        
        // Prepare request arguments
        $args = [
            'method' => 'DELETE',
            'headers' => [
                'AccessKey' => $this->api_key,
            ],
            'timeout' => 30,
            'user-agent' => 'AttachmentLookupOptimizer/' . ALO_VERSION,
        ];
        
        error_log('ALO: BunnyCDN deleting file: ' . $api_url);
        
        // Make the API request
        $response = wp_remote_request($api_url, $args);
        
        // Check for request errors
        if (is_wp_error($response)) {
            error_log('ALO: BunnyCDN delete failed - request error: ' . $response->get_error_message());
            return false;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            error_log('ALO: BunnyCDN delete successful for: ' . $filename);
            return true;
        } elseif ($response_code === 404) {
            // File not found - consider this a success since the goal is achieved
            error_log('ALO: BunnyCDN delete - file not found (already deleted): ' . $filename);
            return true;
        } else {
            error_log('ALO: BunnyCDN delete failed - HTTP ' . $response_code . ': ' . $response_body);
            return false;
        }
    }
    
    /**
     * Build the storage API URL for a file
     * 
     * @param string $filename Remote filename/path
     * @return string API URL
     */
    private function build_storage_api_url($filename) {
        return self::STORAGE_API_BASE . '/' . $this->storage_zone . '/' . $filename;
    }
    
    /**
     * Build the public CDN URL for a file
     * 
     * @param string $filename Remote filename/path
     * @return string CDN URL
     */
    private function build_cdn_url($filename) {
        if (!empty($this->custom_hostname)) {
            // Use custom hostname
            $hostname = $this->custom_hostname;
        } else {
            // Use default BunnyCDN hostname
            $hostname = str_replace('{zone}', $this->storage_zone, self::DEFAULT_CDN_PATTERN);
        }
        
        // Ensure hostname has protocol
        if (!preg_match('/^https?:\/\//', $hostname)) {
            $hostname = 'https://' . $hostname;
        }
        
        return rtrim($hostname, '/') . '/' . $filename;
    }
    
    /**
     * Extract filename from CDN URL
     * 
     * @param string $cdn_url Full CDN URL
     * @return string|false Filename/path or false if invalid
     */
    public function extract_filename_from_url($cdn_url) {
        if (empty($cdn_url)) {
            return false;
        }
        
        // Parse the URL
        $parsed_url = parse_url($cdn_url);
        if (!$parsed_url || !isset($parsed_url['path'])) {
            error_log('ALO: BunnyCDN - Invalid CDN URL format: ' . $cdn_url);
            return false;
        }
        
        // Remove leading slash from path
        $filename = ltrim($parsed_url['path'], '/');
        
        if (empty($filename)) {
            error_log('ALO: BunnyCDN - No filename found in CDN URL: ' . $cdn_url);
            return false;
        }
        
        return $filename;
    }
    
    /**
     * Get MIME type for a file
     * 
     * @param string $file_path File path
     * @return string MIME type
     */
    private function get_mime_type($file_path) {
        // Try WordPress function first
        if (function_exists('wp_get_mimetype_from_file_extension')) {
            $filename = basename($file_path);
            $mime_type = wp_get_mimetype_from_file_extension($filename);
            if ($mime_type) {
                return $mime_type;
            }
        }
        
        // Fallback to PHP's mime_content_type if available
        if (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($file_path);
            if ($mime_type) {
                return $mime_type;
            }
        }
        
        // Final fallback - guess from extension
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
        ];
        
        return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    }
    
    /**
     * Sanitize storage zone name to prevent common configuration mistakes
     * 
     * @param string $storage_zone Raw storage zone input
     * @return string Sanitized storage zone name
     */
    private function sanitize_storage_zone($storage_zone) {
        if (empty($storage_zone)) {
            return '';
        }
        
        // Remove common mistakes - full URLs or hostnames
        $storage_zone = str_replace([
            'https://',
            'http://',
            'storage.bunnycdn.com/',
            'storage.bunnycdn.com',
            '.b-cdn.net',
            'www.'
        ], '', $storage_zone);
        
        // Remove leading/trailing slashes and whitespace
        $storage_zone = trim($storage_zone, '/ ');
        
        // Log warning if we had to clean up the storage zone
        $original = get_option(self::STORAGE_ZONE_OPTION, '');
        if (!empty($original) && $original !== $storage_zone) {
            error_log('ALO: BunnyCDN storage zone sanitized from "' . $original . '" to "' . $storage_zone . '"');
        }
        
        return $storage_zone;
    }
    
    /**
     * Test BunnyCDN connection and credentials
     * 
     * @return array Test results with success status and message
     */
    public function test_connection() {
        // Check permissions for connection testing
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => 'Insufficient permissions to test BunnyCDN connection'
            ];
        }
        
        if (!$this->is_enabled()) {
            return [
                'success' => false,
                'message' => 'BunnyCDN integration is not enabled or configured'
            ];
        }
        
        // Create a small test file
        $test_filename = 'alo-test-' . time() . '.txt';
        $test_content = 'ALO BunnyCDN test - ' . current_time('mysql');
        
        // Use WordPress temp directory
        $temp_file = wp_tempnam($test_filename);
        if (!$temp_file) {
            return [
                'success' => false,
                'message' => 'Could not create temporary test file'
            ];
        }
        
        // Write test content
        file_put_contents($temp_file, $test_content);
        
        // Attempt upload
        $cdn_url = $this->upload_file($temp_file, 'test/' . $test_filename);
        
        // Clean up temp file
        unlink($temp_file);
        
        if ($cdn_url) {
            // Test successful - clean up test file from CDN
            $this->delete_file('test/' . $test_filename);
            
            return [
                'success' => true,
                'message' => 'BunnyCDN connection successful',
                'test_url' => $cdn_url
            ];
        } else {
            return [
                'success' => false,
                'message' => 'BunnyCDN upload test failed - check credentials and settings'
            ];
        }
    }
    
    /**
     * Get BunnyCDN settings for admin interface
     * 
     * @return array Settings array
     */
    public function get_settings() {
        return [
            'enabled' => $this->enabled,
            'api_key' => $this->api_key,
            'storage_zone' => $this->storage_zone,
            'region' => $this->region,
            'custom_hostname' => $this->custom_hostname,
            'auto_upload' => $this->auto_upload,
        ];
    }
    
    /**
     * Get storage zone statistics (if supported by BunnyCDN API)
     * 
     * @return array|false Storage statistics or false on failure
     */
    public function get_storage_stats() {
        if (!$this->is_enabled()) {
            return false;
        }
        
        // Note: This would require the BunnyCDN Statistics API
        // For now, return basic info
        return [
            'storage_zone' => $this->storage_zone,
            'region' => $this->region,
            'configured' => true,
        ];
    }
    
    /**
     * Check if auto upload is enabled
     * 
     * @return bool True if auto upload is enabled
     */
    public function is_auto_upload_enabled() {
        return $this->enabled && $this->auto_upload;
    }
    
    /**
     * Filter attachment URLs to use BunnyCDN URLs when available
     * 
     * @param string $url The attachment URL
     * @param int $post_id The attachment post ID
     * @return string The filtered URL (CDN URL if available, original URL otherwise)
     */
    public function filter_attachment_url($url, $post_id) {
        if (is_admin() || !$this->is_enabled()) return $url;
        if (!get_option('alo_bunnycdn_override_urls')) return $url;
        $cdn_url = get_post_meta($post_id, '_bunnycdn_url', true);
        return $cdn_url ?: $url;
    }

    /**
     * Filter wp_get_attachment_image_src to use full-resolution BunnyCDN images
     * This ensures that when themes/plugins request specific sizes, they get the full CDN image
     * 
     * @param array|false $image Array of image data or false
     * @param int $attachment_id Attachment post ID
     * @param string|array $size Requested image size
     * @param bool $icon Whether the image should be treated as an icon
     * @return array|false Modified image data or original value
     */
    public function filter_attachment_image_src($image, $attachment_id, $size, $icon) {
        // Skip if not enabled or in admin
        if (is_admin() || !$this->is_enabled()) return $image;
        if (!get_option('alo_bunnycdn_override_urls')) return $image;
        
        // Get BunnyCDN URL for this attachment
        $cdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
        if (!$cdn_url) return $image;
        
        // If we have image data and a CDN URL, replace the URL with CDN URL
        if (is_array($image) && !empty($image[0])) {
            // Get original image dimensions if available
            $metadata = wp_get_attachment_metadata($attachment_id);
            if ($metadata && isset($metadata['width']) && isset($metadata['height'])) {
                // Return full-resolution CDN image with original dimensions
                $image[0] = $cdn_url; // URL
                $image[1] = $metadata['width']; // Width
                $image[2] = $metadata['height']; // Height
                $image[3] = false; // Not resized (it's the original)
            } else {
                // Fallback: just replace the URL
                $image[0] = $cdn_url;
            }
        }
        
        return $image;
    }

    /**
     * Filter image_downsize to use full-resolution BunnyCDN images
     * This is the core WordPress function that handles image resizing
     * 
     * @param bool|array $downsize Whether to short-circuit the image downsize
     * @param int $attachment_id Attachment post ID
     * @param string|array $size Requested image size
     * @return bool|array False to use default behavior, or array with image data
     */
    public function filter_image_downsize($downsize, $attachment_id, $size) {
        // Skip if not enabled or in admin
        if (is_admin() || !$this->is_enabled()) return $downsize;
        if (!get_option('alo_bunnycdn_override_urls')) return $downsize;
        
        // Get BunnyCDN URL for this attachment
        $cdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
        if (!$cdn_url) return $downsize;
        
        // Get original image metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!$metadata || !isset($metadata['width']) || !isset($metadata['height'])) {
            return $downsize;
        }
        
        // Return full-resolution CDN image data
        // Format: [url, width, height, is_intermediate]
        return [
            $cdn_url,                    // URL - full-resolution CDN image
            $metadata['width'],          // Width - original dimensions
            $metadata['height'],         // Height - original dimensions  
            false                        // Not an intermediate size - it's the original
        ];
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
                error_log("ALO: BunnyCDN offload - main file not found for attachment {$attachment_id}: {$local_path}");
                return;
            }
            
            // Delete the main file
            if (@unlink($local_path)) {
                error_log("ALO: BunnyCDN offload - deleted main file for attachment {$attachment_id}: {$local_path}");
            } else {
                error_log("ALO: BunnyCDN offload - failed to delete main file for attachment {$attachment_id}: {$local_path}");
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
                                error_log("ALO: BunnyCDN offload - deleted {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            } else {
                                error_log("ALO: BunnyCDN offload - failed to delete {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            }
                        }
                    }
                }
            }
            
            // Mark attachment as offloaded
            update_post_meta($attachment_id, '_bunnycdn_offloaded', true);
            update_post_meta($attachment_id, '_bunnycdn_offloaded_at', current_time('mysql', true));
            
            error_log("ALO: BunnyCDN offload - completed for attachment {$attachment_id}");
            
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN offload - exception for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Handle WordPress attachment deletion by removing file from BunnyCDN
     * 
     * @param int $post_id The attachment post ID being deleted
     */
    public function handle_attachment_deletion($post_id) {
        // Only process if BunnyCDN is enabled
        if (!$this->is_enabled()) {
            return;
        }
        
        // Verify this is an attachment
        if (get_post_type($post_id) !== 'attachment') {
            return;
        }
        
        // Get the CDN URL for this attachment
        $cdn_url = get_post_meta($post_id, '_bunnycdn_url', true);
        
        if (empty($cdn_url)) {
            // No CDN URL found, nothing to delete
            error_log("ALO: BunnyCDN - No CDN URL found for attachment {$post_id}, skipping deletion");
            return;
        }
        
        // Extract filename from CDN URL
        $filename = $this->extract_filename_from_url($cdn_url);
        
        if ($filename === false) {
            error_log("ALO: BunnyCDN - Could not extract filename from CDN URL for attachment {$post_id}: {$cdn_url}");
            return;
        }
        
        // Attempt to delete the file from BunnyCDN
        $success = $this->delete_file($filename);
        
        if ($success) {
            error_log("ALO: BunnyCDN - Successfully deleted file from CDN for attachment {$post_id}: {$filename}");
            
            // Clean up the CDN metadata since the file is deleted
            delete_post_meta($post_id, '_bunnycdn_url');
            delete_post_meta($post_id, '_bunnycdn_filename');
            delete_post_meta($post_id, '_bunnycdn_uploaded_at');
            delete_post_meta($post_id, '_bunnycdn_migrated_at');
        } else {
            error_log("ALO: BunnyCDN - Failed to delete file from CDN for attachment {$post_id}: {$filename}");
            // Note: We don't prevent WordPress deletion if CDN deletion fails
            // The attachment will still be deleted from WordPress, but the CDN file may remain
        }
    }
} 