<?php

namespace AttachmentLookupOptimizer;

/**
 * BunnyCDN Cron Sync Class
 * 
 * Handles automatic background synchronization of attachments to BunnyCDN
 * using WordPress cron system.
 */
class BunnyCDNCronSync {
    
    /**
     * Cron hook name
     */
    const CRON_HOOK = 'alo_bunnycdn_cron_sync';
    
    /**
     * Number of attachments to process per cron run
     */
    // Batch size is now configurable via settings
    
    /**
     * BunnyCDNManager instance
     * 
     * @var BunnyCDNManager
     */
    private $bunny_cdn_manager;
    
    /**
     * Constructor
     * 
     * @param BunnyCDNManager $bunny_cdn_manager BunnyCDN manager instance
     */
    public function __construct($bunny_cdn_manager) {
        $this->bunny_cdn_manager = $bunny_cdn_manager;
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register cron event
        add_action('init', [$this, 'schedule_cron_event']);
        
        // Register cron callback
        add_action(self::CRON_HOOK, [$this, 'process']);
        
        // Clean up cron on deactivation
        register_deactivation_hook(__FILE__, [$this, 'clear_scheduled_event']);
    }
    
    /**
     * Schedule the cron event if not already scheduled
     */
    public function schedule_cron_event() {
        // Only schedule if BunnyCDN is enabled and auto sync is enabled
        if (!$this->bunny_cdn_manager || !$this->bunny_cdn_manager->is_enabled()) {
            return;
        }
        
        // Check if auto sync is enabled
        if (!$this->is_auto_sync_enabled()) {
            // If auto sync is disabled, clear any existing schedule
            $this->clear_scheduled_event();
            return;
        }
        
        // Schedule every minute cron if not already scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $scheduled = wp_schedule_event(time(), 'alo_1min', self::CRON_HOOK);
            
            if ($scheduled !== false) {
                error_log('ALO: BunnyCDN cron sync scheduled successfully');
            } else {
                error_log('ALO: Failed to schedule BunnyCDN cron sync');
            }
        }
    }
    
    /**
     * Clear scheduled cron event
     */
    public function clear_scheduled_event() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            error_log('ALO: BunnyCDN cron sync unscheduled');
        }
    }
    
    /**
     * Process attachments for BunnyCDN upload
     * This is the main cron callback function
     */
    public function process() {
        // Check if BunnyCDN is enabled
        if (!$this->bunny_cdn_manager || !$this->bunny_cdn_manager->is_enabled()) {
            error_log('ALO: BunnyCDN cron sync skipped - integration not enabled');
            return;
        }
        
        // Check if auto sync is enabled
        if (!$this->is_auto_sync_enabled()) {
            error_log('ALO: BunnyCDN cron sync skipped - auto sync disabled');
            return;
        }
        
        // Get attachments that need uploading
        $attachments = $this->get_pending_attachments();
        
        if (empty($attachments)) {
            error_log('ALO: BunnyCDN cron sync - no pending attachments found');
            return;
        }
        
        $processed = 0;
        $successful = 0;
        $failed = 0;
        
        error_log('ALO: BunnyCDN cron sync starting - processing ' . count($attachments) . ' attachments');
        
        foreach ($attachments as $attachment) {
            try {
                $result = $this->process_single_attachment($attachment->ID);
                
                if ($result) {
                    $successful++;
                    error_log("ALO: BunnyCDN cron sync - successfully uploaded attachment {$attachment->ID}");
                } else {
                    $failed++;
                    error_log("ALO: BunnyCDN cron sync - failed to upload attachment {$attachment->ID}");
                }
                
                $processed++;
                
                // Add small delay to prevent overwhelming the server
                usleep(100000); // 0.1 second delay
                
            } catch (Exception $e) {
                $failed++;
                error_log("ALO: BunnyCDN cron sync - exception for attachment {$attachment->ID}: " . $e->getMessage());
            }
        }
        
        // Log summary
        error_log("ALO: BunnyCDN cron sync completed - processed: {$processed}, successful: {$successful}, failed: {$failed}");
        
        // Store sync statistics
        $this->update_sync_stats($processed, $successful, $failed);
    }
    
    /**
     * Get attachments that need to be uploaded to BunnyCDN
     * 
     * @return array Array of attachment post objects
     */
    private function get_pending_attachments() {
        global $wpdb;
        
        // Get configurable batch size
        $batch_size = get_option('alo_bunnycdn_batch_size', 25);
        
        // Query attachments without BunnyCDN URL
        $query = $wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                AND pm.meta_key = '_bunnycdn_url'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ORDER BY p.post_date DESC
            LIMIT %d
        ", $batch_size);
        
        $results = $wpdb->get_results($query);
        
        if ($wpdb->last_error) {
            error_log('ALO: BunnyCDN cron sync - database error: ' . $wpdb->last_error);
            return [];
        }
        
        return $results ?: [];
    }
    
    /**
     * Process a single attachment for BunnyCDN upload
     * 
     * @param int $attachment_id Attachment post ID
     * @return bool True on success, false on failure
     */
    private function process_single_attachment($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }
        
        // Check if already uploaded (race condition protection)
        $existing_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
        if (!empty($existing_url)) {
            error_log("ALO: BunnyCDN cron sync - attachment {$attachment_id} already has CDN URL, skipping");
            return true; // Consider this a success
        }
        
        try {
            // Get attachment file path
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (!$attached_file) {
                error_log("ALO: BunnyCDN cron sync - no attached file found for attachment {$attachment_id}");
                return false;
            }
            
            // Build full file path
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/' . $attached_file;
            
            if (!file_exists($file_path)) {
                error_log("ALO: BunnyCDN cron sync - file not found: {$file_path} for attachment {$attachment_id}");
                return false;
            }
            
            // Generate unique filename
            $file_extension = pathinfo($attached_file, PATHINFO_EXTENSION);
            $file_basename = pathinfo($attached_file, PATHINFO_FILENAME);
            $unique_filename = $file_basename . '_' . $attachment_id . '.' . $file_extension;
            
            // Upload to BunnyCDN with tracking
            $cdn_url = $this->bunny_cdn_manager->upload_file($file_path, $unique_filename, $attachment_id);
            
            if ($cdn_url) {
                // Store CDN URL and metadata
                update_post_meta($attachment_id, '_bunnycdn_url', $cdn_url);
                update_post_meta($attachment_id, '_bunnycdn_filename', $unique_filename);
                update_post_meta($attachment_id, '_bunnycdn_uploaded_at', current_time('mysql', true));
                update_post_meta($attachment_id, '_bunnycdn_cron_synced', current_time('mysql', true));
                
                // Rewrite post content URLs if enabled
                $this->rewrite_post_content_urls($attachment_id, $cdn_url);
                
                // Delete local files if offload is enabled
                if (get_option('alo_bunnycdn_offload_enabled', false)) {
                    $this->delete_local_files_after_upload($attachment_id);
                }
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN cron sync - exception processing attachment {$attachment_id}: " . $e->getMessage());
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
                        error_log("ALO: BunnyCDN cron sync - content URL rewriting is disabled for attachment {$attachment_id}");
                    } elseif (isset($results['updated_posts']) && $results['updated_posts'] > 0) {
                        error_log("ALO: BunnyCDN cron sync - content rewriter updated {$results['updated_posts']} posts with {$results['total_replacements']} URL replacements for attachment {$attachment_id}");
                    } elseif (isset($results['message'])) {
                        error_log("ALO: BunnyCDN cron sync - content rewriter for attachment {$attachment_id}: {$results['message']}");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN cron sync - content rewriter error for attachment {$attachment_id}: " . $e->getMessage());
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
                error_log("ALO: BunnyCDN cron offload - main file not found for attachment {$attachment_id}: {$local_path}");
                return;
            }
            
            // Delete the main file
            if (@unlink($local_path)) {
                error_log("ALO: BunnyCDN cron offload - deleted main file for attachment {$attachment_id}: {$local_path}");
            } else {
                error_log("ALO: BunnyCDN cron offload - failed to delete main file for attachment {$attachment_id}: {$local_path}");
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
                                error_log("ALO: BunnyCDN cron offload - deleted {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            } else {
                                error_log("ALO: BunnyCDN cron offload - failed to delete {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            }
                        }
                    }
                }
            }
            
            // Mark attachment as offloaded
            update_post_meta($attachment_id, '_bunnycdn_offloaded', true);
            update_post_meta($attachment_id, '_bunnycdn_offloaded_at', current_time('mysql', true));
            
            error_log("ALO: BunnyCDN cron offload - completed for attachment {$attachment_id}");
            
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN cron offload - exception for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Update sync statistics
     * 
     * @param int $processed Number of attachments processed
     * @param int $successful Number of successful uploads
     * @param int $failed Number of failed uploads
     */
    private function update_sync_stats($processed, $successful, $failed) {
        $stats = [
            'last_run' => current_time('mysql', true),
            'processed' => $processed,
            'successful' => $successful,
            'failed' => $failed,
            'total_runs' => (int) get_option('alo_bunnycdn_cron_total_runs', 0) + 1
        ];
        
        // Store individual stats
        update_option('alo_bunnycdn_cron_last_run', $stats['last_run']);
        update_option('alo_bunnycdn_cron_last_processed', $processed);
        update_option('alo_bunnycdn_cron_last_successful', $successful);
        update_option('alo_bunnycdn_cron_last_failed', $failed);
        update_option('alo_bunnycdn_cron_total_runs', $stats['total_runs']);
        
        // Store complete stats array
        update_option('alo_bunnycdn_cron_stats', $stats);
    }
    
    /**
     * Get sync statistics
     * 
     * @return array Sync statistics
     */
    public function get_sync_stats() {
        $stats = get_option('alo_bunnycdn_cron_stats', []);
        
        // Add next scheduled run time
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        $stats['next_run'] = $next_run ? date('Y-m-d H:i:s', $next_run) : null;
        $stats['is_scheduled'] = (bool) $next_run;
        
        // Add pending count
        $stats['pending_count'] = $this->get_pending_count();
        
        return $stats;
    }
    
    /**
     * Get count of pending attachments
     * 
     * @return int Number of attachments without CDN URL
     */
    public function get_pending_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                AND pm.meta_key = '_bunnycdn_url'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        return (int) $count;
    }
    
    /**
     * Manually trigger sync process (for testing/admin use)
     * 
     * @return array Results of the sync process
     */
    public function manual_sync() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('Permission denied', 'attachment-lookup-optimizer')
            ];
        }
        
        // Check if BunnyCDN is enabled
        if (!$this->bunny_cdn_manager || !$this->bunny_cdn_manager->is_enabled()) {
            return [
                'success' => false,
                'message' => __('BunnyCDN integration is not enabled', 'attachment-lookup-optimizer')
            ];
        }
        
        // Run the sync process
        ob_start();
        $this->process();
        $output = ob_get_clean();
        
        $stats = $this->get_sync_stats();
        
        return [
            'success' => true,
            'message' => __('Manual sync completed', 'attachment-lookup-optimizer'),
            'stats' => $stats,
            'output' => $output
        ];
    }
    
    /**
     * Check if cron sync is enabled and working
     * 
     * @return bool True if cron is properly scheduled
     */
    public function is_cron_active() {
        return (bool) wp_next_scheduled(self::CRON_HOOK);
    }
    
    /**
     * Check if auto sync is enabled in settings
     * 
     * @return bool True if auto sync is enabled
     */
    public function is_auto_sync_enabled() {
        return (bool) get_option('alo_bunnycdn_auto_sync', true);
    }
    
    /**
     * Reschedule cron event (useful for changing frequency)
     * 
     * @param string $recurrence Cron recurrence (alo_1min, hourly, daily, etc.)
     * @return bool True on success
     */
    public function reschedule_cron($recurrence = 'alo_1min') {
        // Clear existing schedule
        $this->clear_scheduled_event();
        
        // Schedule with new recurrence
        $scheduled = wp_schedule_event(time(), $recurrence, self::CRON_HOOK);
        
        if ($scheduled !== false) {
            error_log("ALO: BunnyCDN cron sync rescheduled to {$recurrence}");
            return true;
        }
        
        error_log("ALO: Failed to reschedule BunnyCDN cron sync to {$recurrence}");
        return false;
    }
} 