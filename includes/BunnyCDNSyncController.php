<?php
/**
 * BunnyCDN Sync Controller
 * 
 * Handles AJAX requests for manual full sync operations
 */

namespace AttachmentLookupOptimizer;

class BunnyCDNSyncController {
    
    /**
     * @var BunnyCDNManager
     */
    private $bunnycdn_manager;
    
    /**
     * @var int Number of attachments to process per batch
     */
    private $batch_size;
    
    /**
     * Constructor
     */
    public function __construct($bunnycdn_manager = null) {
        $this->bunnycdn_manager = $bunnycdn_manager;
        $this->batch_size = get_option('alo_bunnycdn_batch_size', 25); // Optimized default: 25 files per batch
        
        // Force update the option if it's still the old default
        if ($this->batch_size == 10) {
            update_option('alo_bunnycdn_batch_size', 25); // Reduced from 50 to 25 for stability
            $this->batch_size = 25;
        }
        
        // Debug logging
        error_log('BunnyCDN Sync Controller: Batch size set to ' . $this->batch_size);
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('wp_ajax_bunnycdn_sync_next_batch', [$this, 'sync_next_batch']);
        add_action('wp_ajax_bunnycdn_stop_sync', function () {
            if (!current_user_can('manage_options')) wp_die();
            check_ajax_referer('bunnycdn_sync_nonce', 'nonce');
            update_option('bunnycdn_stop_sync', true);
            wp_send_json_success();
        });
        add_action('wp_ajax_bunnycdn_check_status', [$this, 'check_status']);
        add_action('wp_ajax_bunnycdn_reset_failed', [$this, 'reset_failed_attachments']);
        add_action('wp_ajax_bunnycdn_record_timeout', [$this, 'record_timeout_ajax']);
        add_action('bunnycdn_clear_timeout_counter', [$this, 'clear_timeout_counter']);
    }
    
    /**
     * Set BunnyCDN Manager instance
     */
    public function set_bunnycdn_manager($bunnycdn_manager) {
        $this->bunnycdn_manager = $bunnycdn_manager;
    }
    
    /**
     * Handle AJAX request for syncing next batch of attachments
     */
    public function sync_next_batch() {
        // Debug logging
        error_log('BunnyCDN Sync: sync_next_batch called');
        error_log('BunnyCDN Sync: BunnyCDN Manager available: ' . ($this->bunnycdn_manager ? 'Yes' : 'No'));
        
        // Get nonce from either POST or GET request
        $nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
        
        // Validate nonce
        if (!wp_verify_nonce($nonce, 'bunnycdn_sync_nonce')) {
            error_log('BunnyCDN Sync: Nonce validation failed');
            wp_send_json_error([
                'message' => __('Security check failed. Please refresh the page and try again.', 'attachment-lookup-optimizer')
            ]);
            return;
        }
        
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'attachment-lookup-optimizer')
            ]);
            return;
        }
        
        // Check if BunnyCDN is properly configured
        if (!$this->bunnycdn_manager) {
            error_log('BunnyCDN Sync: BunnyCDN Manager is null');
            wp_send_json_error([
                'message' => __('BunnyCDN manager is not initialized. Please check your plugin configuration.', 'attachment-lookup-optimizer')
            ]);
            return;
        }
        
        if (!$this->bunnycdn_manager->is_enabled()) {
            error_log('BunnyCDN Sync: BunnyCDN is not enabled');
            wp_send_json_error([
                'message' => __('BunnyCDN is not enabled. Please check your settings.', 'attachment-lookup-optimizer')
            ]);
            return;
        }
        
        try {
            // Increase execution time for large batches
            @set_time_limit(120); // 2 minutes (reduced from 5 minutes)
            @ini_set('memory_limit', '256M'); // Increase memory limit (reduced from 512M)
            @ignore_user_abort(true); // Continue processing even if user closes browser
            
            // Check if sync should be stopped
            if (get_option('bunnycdn_stop_sync')) {
                delete_option('bunnycdn_stop_sync');
                $total_count = $this->get_total_attachments_count();
                $synced_count = $this->get_synced_count();
                $failed_count = $this->get_failed_count();
                $done_count = $synced_count + $failed_count;
                wp_send_json_success([
                    'completed' => true, 
                    'done' => $done_count, 
                    'total' => $total_count, 
                    'stopped' => true,
                    'message' => sprintf(
                        __('Sync stopped manually. %d successful, %d failed out of %d total attachments.', 'attachment-lookup-optimizer'),
                        $synced_count,
                        $failed_count,
                        $total_count
                    )
                ]);
            }
            
            // Get total count of all attachments (for progress calculation)
            $total_count = $this->get_total_attachments_count();
            
            // Get next batch of unsynced attachments
            // Adaptive batch sizing based on recent performance
            $adaptive_batch_size = $this->get_adaptive_batch_size();
            $effective_batch_size = max($adaptive_batch_size, 10); // Ensure minimum of 10
            error_log('BunnyCDN Sync: Requesting batch of ' . $effective_batch_size . ' attachments (configured: ' . $this->batch_size . ', adaptive: ' . $adaptive_batch_size . ')');
            $attachments = $this->get_unsynced_attachments($effective_batch_size);
            error_log('BunnyCDN Sync: Retrieved ' . count($attachments) . ' attachments for processing');
        
        // Log if many files were pre-filtered for missing files
        $pre_filter_stats = get_transient('bunnycdn_pre_filter_stats');
        if ($pre_filter_stats) {
            error_log('BunnyCDN Sync: Pre-filter stats - ' . $pre_filter_stats);
            delete_transient('bunnycdn_pre_filter_stats');
        }
            
            if (empty($attachments)) {
                // Check if there are really no more files, or if they were all pre-filtered
                $raw_unsynced_count = $this->get_raw_unsynced_count();
                error_log('BunnyCDN Sync: Empty batch - Raw unsynced count: ' . $raw_unsynced_count);
                
                if ($raw_unsynced_count === 0) {
                    // No more attachments to process
                    wp_send_json_success([
                        'completed' => true,
                        'done' => $total_count,
                        'total' => $total_count,
                        'processed' => 0,
                        'successful' => 0,
                        'failed' => 0,
                        'message' => __('All attachments have been processed.', 'attachment-lookup-optimizer')
                    ]);
                    return;
                } else {
                    // There are files but they were all pre-filtered, continue with next batch
                    error_log('BunnyCDN Sync: All files in batch were pre-filtered, continuing...');
                    wp_send_json_success([
                        'completed' => false,
                        'done' => $this->get_synced_count() + $this->get_failed_count(),
                        'total' => $total_count,
                        'processed' => 0,
                        'successful' => 0,
                        'failed' => 0,
                        'message' => __('Batch contained only missing files, continuing with next batch...', 'attachment-lookup-optimizer')
                    ]);
                    return;
                }
            }
            
            // Process the batch with timeout monitoring
            $batch_start_time = microtime(true);
            $max_execution_time = ini_get('max_execution_time');
            
            // Check if we're approaching timeout
            if ($max_execution_time > 0 && (time() - $_SERVER['REQUEST_TIME']) > ($max_execution_time * 0.8)) {
                // We're at 80% of max execution time, reduce batch size
                $attachments = array_slice($attachments, 0, min(10, count($attachments)));
                error_log('BunnyCDN Sync: Approaching timeout, reducing batch to ' . count($attachments) . ' files');
            }
            
            $results = $this->process_attachment_batch($attachments);
            $batch_duration = microtime(true) - $batch_start_time;
            error_log('BunnyCDN Sync: Batch processing took ' . round($batch_duration, 2) . ' seconds for ' . count($attachments) . ' files');
            
            // Calculate progress
            $remaining_count = $this->get_total_unsynced_count();
            $raw_remaining_count = $this->get_raw_unsynced_count();
            $synced_count = $this->get_synced_count();
            $failed_count = $this->get_failed_count();
            $done_count = $synced_count + $failed_count;
            
            // More robust completion logic - check both counts
            $completed = ($remaining_count === 0 && $raw_remaining_count === 0);
            
            // Debug logging for completion logic
            error_log('BunnyCDN Sync: Progress calculation - Remaining: ' . $remaining_count . ', Raw remaining: ' . $raw_remaining_count . ', Synced: ' . $synced_count . ', Failed: ' . $failed_count . ', Done: ' . $done_count . ', Total: ' . $total_count);
            error_log('BunnyCDN Sync: Completed status: ' . ($completed ? 'TRUE' : 'FALSE'));
            
            // Prepare response
            $response = [
                'completed' => $completed,
                'done' => $done_count,
                'total' => $total_count,
                'processed' => $results['processed'],
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'batch_details' => $results['details']
            ];
            
            if ($completed) {
                // Save the sync completion time
                update_option('bunnycdn_last_full_sync', current_time('mysql'));
                
                $response['message'] = sprintf(
                    __('Sync completed! %d successful, %d failed out of %d total attachments.', 'attachment-lookup-optimizer'),
                    $synced_count,
                    $failed_count,
                    $total_count
                );
            } else {
                $response['message'] = sprintf(
                    __('Batch processed: %d successful, %d failed. %d remaining.', 'attachment-lookup-optimizer'),
                    $results['successful'],
                    $results['failed'],
                    $remaining_count
                );
            }
            
            // Record successful batch completion
            $this->record_successful_batch($effective_batch_size);
            
            wp_send_json_success($response);
            
        } catch (Exception $e) {
            error_log('BunnyCDN Sync Error: ' . $e->getMessage());
            error_log('BunnyCDN Sync Error Stack Trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => sprintf(
                    __('Sync failed: %s', 'attachment-lookup-optimizer'),
                    $e->getMessage()
                )
            ]);
        } catch (Error $e) {
            error_log('BunnyCDN Sync Fatal Error: ' . $e->getMessage());
            error_log('BunnyCDN Sync Fatal Error Stack Trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => sprintf(
                    __('Sync failed: %s', 'attachment-lookup-optimizer'),
                    $e->getMessage()
                )
            ]);
        }
    }
    
    /**
     * Get total count of all attachments
     */
    private function get_total_attachments_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status = 'inherit'
        ");
        
        return (int) $count;
    }
    
    /**
     * Get total count of unsynced attachments
     */
    private function get_total_unsynced_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND pm.meta_key = '_wp_attached_file'
            AND pm.meta_value != ''
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunnycdn_url' 
                AND meta_value != ''
            )
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunnycdn_failed'
            )
        ");
        
        return (int) $count;
    }
    
    /**
     * Get raw count of unsynced attachments (including those marked as failed)
     * Used to determine if there are actually more files to process
     */
    private function get_raw_unsynced_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND pm.meta_key = '_wp_attached_file'
            AND pm.meta_value != ''
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunnycdn_url' 
                AND meta_value != ''
            )
        ");
        
        return (int) $count;
    }
    
    /**
     * Get count of synced attachments
     */
    private function get_synced_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_url'
            AND meta_value != ''
        ");
        
        return (int) $count;
    }
    
    /**
     * Get count of failed attachments
     */
    private function get_failed_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_failed'
        ");
        
        return (int) $count;
    }
    
    /**
     * Get unsynced attachments
     */
    private function get_unsynced_attachments($limit = 25) {
        global $wpdb;
        
        // Get a larger batch to account for pre-filtering
        $fetch_limit = $limit * 3; // Fetch 3x more to account for missing files
        
        $attachments = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, pm.meta_value as file_path
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND pm.meta_key = '_wp_attached_file'
            AND pm.meta_value != ''
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunnycdn_url' 
                AND meta_value != ''
            )
            AND p.ID NOT IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_bunnycdn_failed'
            )
            ORDER BY p.ID ASC
            LIMIT %d
        ", $fetch_limit));
        
        // Pre-filter to check file existence and reduce wasted processing
        $upload_dir = wp_upload_dir();
        $valid_attachments = [];
        $batch_failed_count = 0;
        
        foreach ($attachments as $attachment) {
            // Stop if we have enough valid attachments
            if (count($valid_attachments) >= $limit) {
                break;
            }
            
            $file_path = trailingslashit($upload_dir['basedir']) . $attachment->file_path;
            
            if (file_exists($file_path)) {
                $valid_attachments[] = $attachment;
            } else {
                // Mark as failed immediately to avoid processing again
                update_post_meta($attachment->ID, '_bunnycdn_failed', current_time('mysql'));
                update_post_meta($attachment->ID, '_bunnycdn_error', 'File not found on server');
                $this->log_sync_result($attachment->ID, false, 'Pre-check: File not found: ' . $file_path);
                $batch_failed_count++;
            }
        }
        
        // Log pre-filtering statistics
        if ($batch_failed_count > 0) {
            $stats_message = "Pre-filtered {$batch_failed_count} missing files from " . count($attachments) . " fetched, returning " . count($valid_attachments) . " valid files";
            error_log("BunnyCDN Sync: " . $stats_message);
            set_transient('bunnycdn_pre_filter_stats', $stats_message, 60);
        }
        
         return $valid_attachments;
    }
    
    /**
     * Process a batch of attachments with parallel uploads
     */
    private function process_attachment_batch($attachments) {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        // Use parallel processing for better performance
        $parallel_limit = min(5, count($attachments)); // Process up to 5 files in parallel for stability
        $chunks = array_chunk($attachments, $parallel_limit);
        
        foreach ($chunks as $chunk) {
            $chunk_results = $this->process_attachment_chunk_parallel($chunk);
            
            // Merge results
            $results['processed'] += $chunk_results['processed'];
            $results['successful'] += $chunk_results['successful'];
            $results['failed'] += $chunk_results['failed'];
            $results['details'] = array_merge($results['details'], $chunk_results['details']);
        }
        
        return $results;
    }
    
    /**
     * Process a chunk of attachments in parallel using cURL multi-handle
     */
    private function process_attachment_chunk_parallel($attachments) {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        $upload_dir = wp_upload_dir();
        $curl_handles = [];
        $attachment_map = [];
        
        // Initialize multi-handle
        $multi_handle = curl_multi_init();
        
        // Set multi-handle options for better performance
        curl_multi_setopt($multi_handle, CURLMOPT_MAXCONNECTS, 5);
        curl_multi_setopt($multi_handle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        
        foreach ($attachments as $attachment) {
            $results['processed']++;
            
            $file_path = trailingslashit($upload_dir['basedir']) . $attachment->file_path;
            
            // Check if file exists
            if (!file_exists($file_path)) {
                $results['failed']++;
                $results['details'][] = [
                    'id' => $attachment->ID,
                    'status' => 'failed',
                    'error' => __('File not found on server', 'attachment-lookup-optimizer')
                ];
                
                // Mark as failed
                update_post_meta($attachment->ID, '_bunnycdn_failed', current_time('mysql'));
                update_post_meta($attachment->ID, '_bunnycdn_error', 'File not found on server');
                $this->log_sync_result($attachment->ID, false, 'File not found: ' . $file_path);
                continue;
            }
            
            // Prepare cURL handle for this file
            $curl_handle = $this->create_curl_handle_for_upload($file_path, $attachment->file_path);
            if ($curl_handle) {
                $curl_handles[] = $curl_handle;
                $attachment_map[(int)$curl_handle] = $attachment;
                curl_multi_add_handle($multi_handle, $curl_handle);
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'id' => $attachment->ID,
                    'status' => 'failed',
                    'error' => __('Failed to prepare upload', 'attachment-lookup-optimizer')
                ];
                update_post_meta($attachment->ID, '_bunnycdn_failed', current_time('mysql'));
                update_post_meta($attachment->ID, '_bunnycdn_error', 'Failed to prepare upload');
                $this->log_sync_result($attachment->ID, false, 'Failed to prepare upload');
            }
        }
        
        // Execute all handles in parallel
        if (!empty($curl_handles)) {
            $running = null;
            do {
                $status = curl_multi_exec($multi_handle, $running);
                if ($running > 0) {
                    curl_multi_select($multi_handle, 0.1); // Short wait to avoid busy waiting
                }
            } while ($running > 0 && $status === CURLM_OK);
            
            // Process results
            foreach ($curl_handles as $curl_handle) {
                $attachment = $attachment_map[(int)$curl_handle];
                $http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
                $error = curl_error($curl_handle);
                
                if ($error) {
                    $results['failed']++;
                    $results['details'][] = [
                        'id' => $attachment->ID,
                        'status' => 'failed',
                        'error' => $error
                    ];
                    update_post_meta($attachment->ID, '_bunnycdn_failed', current_time('mysql'));
                    update_post_meta($attachment->ID, '_bunnycdn_error', $error);
                    $this->log_sync_result($attachment->ID, false, 'cURL error: ' . $error);
                } elseif ($http_code >= 200 && $http_code < 300) {
                                         // Success - build CDN URL manually since we can't access private method
                     $settings = $this->bunnycdn_manager->get_settings();
                     $storage_zone = $settings['storage_zone'];
                     $custom_hostname = $settings['custom_hostname'];
                     
                     if (!empty($custom_hostname)) {
                         $cdn_url = 'https://' . $custom_hostname . '/' . ltrim($attachment->file_path, '/');
                     } else {
                         $cdn_url = 'https://' . $storage_zone . '.b-cdn.net/' . ltrim($attachment->file_path, '/');
                     }
                    update_post_meta($attachment->ID, '_bunnycdn_url', $cdn_url);
                    update_post_meta($attachment->ID, '_bunnycdn_uploaded', current_time('mysql'));
                    
                    // Rewrite post content URLs if enabled
                    $this->rewrite_post_content_urls($attachment->ID, $cdn_url);
                    
                    // Delete local files if offload is enabled
                    if (get_option('alo_bunnycdn_offload_enabled', false)) {
                        $this->delete_local_files_after_upload($attachment->ID);
                    }
                    
                    $results['successful']++;
                    $results['details'][] = [
                        'id' => $attachment->ID,
                        'status' => 'success',
                        'url' => $cdn_url
                    ];
                    $this->log_sync_result($attachment->ID, true, 'Uploaded successfully: ' . $cdn_url);
                } else {
                    $results['failed']++;
                    $error_msg = 'HTTP ' . $http_code;
                    $results['details'][] = [
                        'id' => $attachment->ID,
                        'status' => 'failed',
                        'error' => $error_msg
                    ];
                    update_post_meta($attachment->ID, '_bunnycdn_failed', current_time('mysql'));
                    update_post_meta($attachment->ID, '_bunnycdn_error', $error_msg);
                    $this->log_sync_result($attachment->ID, false, $error_msg);
                }
                
                curl_multi_remove_handle($multi_handle, $curl_handle);
                curl_close($curl_handle);
            }
        }
        
        curl_multi_close($multi_handle);
        
        return $results;
    }
    
    /**
     * Create a cURL handle for uploading a file to BunnyCDN
     */
    private function create_curl_handle_for_upload($local_path, $filename) {
        if (!$this->bunnycdn_manager->is_enabled()) {
            return false;
        }
        
        $settings = $this->bunnycdn_manager->get_settings();
        $api_key = $settings['api_key'];
        $storage_zone = $settings['storage_zone'];
        
        if (empty($api_key) || empty($storage_zone)) {
            return false;
        }
        
        // Build API URL
        $filename = ltrim($filename, '/');
        $api_url = 'https://storage.bunnycdn.com/' . $storage_zone . '/' . $filename;
        
        // Read file content
        $file_content = file_get_contents($local_path);
        if ($file_content === false) {
            return false;
        }
        
        // Create cURL handle
        $curl_handle = curl_init();
        
        curl_setopt_array($curl_handle, [
            CURLOPT_URL => $api_url,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $file_content,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $api_key,
                'Content-Type: ' . $this->get_mime_type($local_path),
                'Content-Length: ' . strlen($file_content),
            ],
            CURLOPT_RETURNTRANSFER => true,
                         CURLOPT_TIMEOUT => 30, // Reduced from 60 to 30 seconds
             CURLOPT_CONNECTTIMEOUT => 5, // Reduced from 10 to 5 seconds
            CURLOPT_USERAGENT => 'AttachmentLookupOptimizer/' . ALO_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0, // Use HTTP/2 for better performance
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        
        return $curl_handle;
    }
    
    /**
     * Get MIME type for a file (helper method)
     */
    private function get_mime_type($file_path) {
        if (function_exists('mime_content_type')) {
            return mime_content_type($file_path);
        }
        
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
        ];
        
        return $mime_types[$extension] ?? 'application/octet-stream';
    }
    
    /**
     * Get adaptive batch size based on recent performance
     */
    private function get_adaptive_batch_size() {
        $recent_timeouts = get_option('bunnycdn_recent_timeouts', 0);
        $last_successful_batch_size = get_option('bunnycdn_last_successful_batch_size', $this->batch_size);
        
        // If we've had recent timeouts, reduce batch size
        if ($recent_timeouts > 0) {
            $adaptive_size = max(10, $last_successful_batch_size - ($recent_timeouts * 5));
            error_log('BunnyCDN Sync: Reducing batch size to ' . $adaptive_size . ' due to ' . $recent_timeouts . ' recent timeouts');
            return $adaptive_size;
        }
        
        // If no recent timeouts, use configured batch size
        return $this->batch_size;
    }
    
    /**
     * Record successful batch completion
     */
    private function record_successful_batch($batch_size) {
        update_option('bunnycdn_last_successful_batch_size', $batch_size);
        // Reset timeout counter on successful batch
        delete_option('bunnycdn_recent_timeouts');
    }
    
    /**
     * Record timeout occurrence
     */
    private function record_timeout() {
        $recent_timeouts = get_option('bunnycdn_recent_timeouts', 0);
        update_option('bunnycdn_recent_timeouts', $recent_timeouts + 1);
        
        // Clear timeout counter after 1 hour
        wp_schedule_single_event(time() + 3600, 'bunnycdn_clear_timeout_counter');
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
                $this->log_sync_result($attachment_id, false, "Offload failed - main file not found: {$local_path}");
                return;
            }
            
            // Delete the main file
            if (@unlink($local_path)) {
                $this->log_sync_result($attachment_id, true, "Offload - deleted main file: {$local_path}");
            } else {
                $this->log_sync_result($attachment_id, false, "Offload failed - could not delete main file: {$local_path}");
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
                                $this->log_sync_result($attachment_id, true, "Offload - deleted {$size_name} size: {$size_file_path}");
                            } else {
                                $this->log_sync_result($attachment_id, false, "Offload failed - could not delete {$size_name} size: {$size_file_path}");
                            }
                        }
                    }
                }
            }
            
            // Mark attachment as offloaded
            update_post_meta($attachment_id, '_bunnycdn_offloaded', true);
            update_post_meta($attachment_id, '_bunnycdn_offloaded_at', current_time('mysql', true));
            
            $this->log_sync_result($attachment_id, true, "Offload completed - all local files deleted");
            
        } catch (Exception $e) {
            $this->log_sync_result($attachment_id, false, "Offload exception: " . $e->getMessage());
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
                        $this->log_sync_result($attachment_id, true, 'Content URL rewriting is disabled');
                    } elseif (isset($results['updated_posts']) && $results['updated_posts'] > 0) {
                        $this->log_sync_result($attachment_id, true, "Content rewriter updated {$results['updated_posts']} posts with {$results['total_replacements']} URL replacements");
                    } elseif (isset($results['message'])) {
                        $this->log_sync_result($attachment_id, true, "Content rewriter: {$results['message']}");
                    }
                }
            }
        } catch (Exception $e) {
            $this->log_sync_result($attachment_id, false, "Content rewriter error: " . $e->getMessage());
        }
    }
    
    /**
     * Log sync result
     */
    private function log_sync_result($attachment_id, $success, $message) {
        $log_message = sprintf(
            '[BunnyCDN Manual Sync] Attachment ID %d: %s - %s',
            $attachment_id,
            $success ? 'SUCCESS' : 'FAILED',
            $message
        );
        
        error_log($log_message);
        
        // Also log to WordPress debug log if WP_DEBUG_LOG is enabled
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($log_message);
        }
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_stats() {
        global $wpdb;
        
        // Total attachments
        $total_attachments = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status = 'inherit'
        ");
        
        // Synced attachments
        $synced_attachments = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_url'
            AND meta_value != ''
        ");
        
        // Failed attachments
        $failed_attachments = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_failed'
        ");
        
        // Unsynced attachments
        $unsynced_attachments = $this->get_total_unsynced_count();
        
        return [
            'total' => (int) $total_attachments,
            'synced' => (int) $synced_attachments,
            'failed' => (int) $failed_attachments,
            'unsynced' => (int) $unsynced_attachments,
            'sync_percentage' => $total_attachments > 0 ? round(($synced_attachments / $total_attachments) * 100, 1) : 0
        ];
    }
    
    /**
     * Reset sync status for all attachments (for testing purposes)
     */
    public function reset_sync_status() {
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        global $wpdb;
        
        // Remove BunnyCDN metadata
        $deleted = $wpdb->delete(
            $wpdb->postmeta,
            [
                'meta_key' => '_bunnycdn_url'
            ]
        );
        
        $wpdb->delete(
            $wpdb->postmeta,
            [
                'meta_key' => '_bunnycdn_uploaded'
            ]
        );
        
        return $deleted;
    }
    
    /**
     * Reset failed attachments to allow retry
     */
    public function reset_failed_attachments() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'attachment-lookup-optimizer')
            ]);
            return;
        }
        
        try {
            global $wpdb;
            
            // Get count of failed attachments before deletion
            $failed_count = $wpdb->get_var("
                SELECT COUNT(DISTINCT post_id)
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_bunnycdn_failed'
            ");
            
            // Remove failed status from all attachments
            $deleted = $wpdb->query("
                DELETE FROM {$wpdb->postmeta} 
                WHERE meta_key IN ('_bunnycdn_failed', '_bunnycdn_error')
            ");
            
            wp_send_json_success([
                'message' => sprintf(
                    __('Reset %d failed attachments for retry.', 'attachment-lookup-optimizer'),
                    (int) $failed_count
                ),
                'reset_count' => (int) $failed_count
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Failed to reset failed attachments: %s', 'attachment-lookup-optimizer'),
                    $e->getMessage()
                )
            ]);
        }
    }
    
    /**
     * Check BunnyCDN configuration status
     */
    public function check_status() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'attachment-lookup-optimizer')
            ]);
            return;
        }
        
        $status = [
            'manager_available' => $this->bunnycdn_manager ? true : false,
            'enabled' => false,
            'api_key_set' => false,
            'storage_zone_set' => false,
            'total_attachments' => 0,
            'unsynced_attachments' => 0,
            'configuration_errors' => []
        ];
        
        if ($this->bunnycdn_manager) {
            $status['enabled'] = $this->bunnycdn_manager->is_enabled();
            
            // Check settings
            $settings = $this->bunnycdn_manager->get_settings();
            $status['api_key_set'] = !empty($settings['api_key']);
            $status['storage_zone_set'] = !empty($settings['storage_zone']);
            
            if (!$status['api_key_set']) {
                $status['configuration_errors'][] = 'API key is not set';
            }
            if (!$status['storage_zone_set']) {
                $status['configuration_errors'][] = 'Storage zone is not set';
            }
            
            // Get attachment counts
            try {
                $status['total_attachments'] = $this->get_total_attachments_count();
                $status['unsynced_attachments'] = $this->get_total_unsynced_count();
            } catch (Exception $e) {
                $status['configuration_errors'][] = 'Database error: ' . $e->getMessage();
            }
        } else {
            $status['configuration_errors'][] = 'BunnyCDN manager is not initialized';
        }
        
        wp_send_json_success($status);
    }
    
    /**
     * AJAX handler to record timeout occurrence
     */
    public function record_timeout_ajax() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'attachment-lookup-optimizer')
            ]);
            return;
        }
        
        // Record the timeout
        $this->record_timeout();
        
        wp_send_json_success([
            'message' => 'Timeout recorded',
            'recent_timeouts' => get_option('bunnycdn_recent_timeouts', 0)
        ]);
    }
    
    /**
     * Clear timeout counter (scheduled task)
     */
    public function clear_timeout_counter() {
        delete_option('bunnycdn_recent_timeouts');
        error_log('BunnyCDN Sync: Timeout counter cleared');
    }
} 