<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * BunnyCDN Migrator Class
 * 
 * Handles migration of existing WordPress attachments to BunnyCDN storage
 */
class BunnyCDNMigrator {
    
    /**
     * BunnyCDN Manager instance
     */
    private $bunny_cdn_manager;
    
    /**
     * Batch size for processing attachments
     */
    const BATCH_SIZE = 10;
    
    /**
     * Constructor
     * 
     * @param BunnyCDNManager $bunny_cdn_manager BunnyCDN manager instance
     */
    public function __construct($bunny_cdn_manager) {
        $this->bunny_cdn_manager = $bunny_cdn_manager;
    }
    
    /**
     * Register admin menu and hooks
     */
    public function register_admin_menu() {
        // Only register if user has proper permissions
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Add submenu under Tools
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Register AJAX handlers
        add_action('wp_ajax_alo_bunnycdn_migrate_batch', [$this, 'ajax_migrate_batch']);
        add_action('wp_ajax_alo_bunnycdn_migration_status', [$this, 'ajax_migration_status']);
        add_action('wp_ajax_alo_bunnycdn_stop_migration', [$this, 'ajax_stop_migration']);
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }
    
    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            __('Migrate to BunnyCDN', 'attachment-lookup-optimizer'),
            __('Migrate to BunnyCDN', 'attachment-lookup-optimizer'),
            'manage_options',
            'alo-bunnycdn-migrator',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our migration page
        if ($hook !== 'tools_page_alo-bunnycdn-migrator') {
            return;
        }
        
        // Check permissions before loading scripts
        if (!current_user_can('manage_options')) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Inline script for migration functionality
        wp_add_inline_script('jquery', $this->get_migration_javascript());
        
        // Add some basic styling
        wp_add_inline_style('wp-admin', $this->get_migration_css());
    }
    
    /**
     * Display the migration admin page
     */
    public function admin_page() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Get migration statistics
        $stats = $this->get_migration_stats();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="alo-migration-container">
                
                <!-- Migration Status Card -->
                <div class="alo-migration-card">
                    <h2><?php _e('Migration Status', 'attachment-lookup-optimizer'); ?></h2>
                    
                    <?php if (!$this->bunny_cdn_manager->is_enabled()): ?>
                        <div class="notice notice-error">
                            <p><strong><?php _e('BunnyCDN Integration Disabled', 'attachment-lookup-optimizer'); ?></strong></p>
                            <p><?php _e('Please enable and configure BunnyCDN integration in the plugin settings before running migration.', 'attachment-lookup-optimizer'); ?></p>
                        </div>
                    <?php else: ?>
                        
                        <table class="widefat striped">
                            <tbody>
                                <tr>
                                    <td><strong><?php _e('Total Attachments', 'attachment-lookup-optimizer'); ?></strong></td>
                                    <td><?php echo number_format_i18n($stats['total_attachments']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Already Migrated', 'attachment-lookup-optimizer'); ?></strong></td>
                                    <td><?php echo number_format_i18n($stats['migrated_attachments']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Pending Migration', 'attachment-lookup-optimizer'); ?></strong></td>
                                    <td><?php echo number_format_i18n($stats['pending_attachments']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Migration Progress', 'attachment-lookup-optimizer'); ?></strong></td>
                                    <td><?php echo $stats['progress_percentage']; ?>%</td>
                                </tr>
                            </tbody>
                        </table>
                        
                    <?php endif; ?>
                </div>
                
                <?php if ($this->bunny_cdn_manager->is_enabled() && $stats['pending_attachments'] > 0): ?>
                
                <!-- Migration Controls -->
                <div class="alo-migration-card">
                    <h2><?php _e('Migration Controls', 'attachment-lookup-optimizer'); ?></h2>
                    
                    <div class="alo-migration-controls">
                        <button id="alo-start-migration" class="button button-primary button-large">
                            <?php _e('Start Migration', 'attachment-lookup-optimizer'); ?>
                        </button>
                        
                        <button id="alo-stop-migration" class="button button-secondary" style="display: none;">
                            <?php _e('Stop Migration', 'attachment-lookup-optimizer'); ?>
                        </button>
                        
                        <button id="alo-refresh-stats" class="button">
                            <?php _e('Refresh Statistics', 'attachment-lookup-optimizer'); ?>
                        </button>
                    </div>
                    
                    <p class="description">
                        <?php _e('Migration will process attachments in batches to prevent timeouts. You can safely stop and resume migration at any time.', 'attachment-lookup-optimizer'); ?>
                    </p>
                </div>
                
                <!-- Progress Display -->
                <div class="alo-migration-card" id="alo-migration-progress" style="display: none;">
                    <h2><?php _e('Migration Progress', 'attachment-lookup-optimizer'); ?></h2>
                    
                    <div class="alo-progress-bar">
                        <div class="alo-progress-fill" style="width: 0%;"></div>
                    </div>
                    
                    <div class="alo-progress-stats">
                        <p id="alo-progress-text"><?php _e('Preparing migration...', 'attachment-lookup-optimizer'); ?></p>
                        <p id="alo-progress-details"></p>
                    </div>
                    
                    <div id="alo-migration-log" class="alo-migration-log">
                        <h3><?php _e('Migration Log', 'attachment-lookup-optimizer'); ?></h3>
                        <div id="alo-log-content"></div>
                    </div>
                </div>
                
                <?php elseif ($this->bunny_cdn_manager->is_enabled() && $stats['pending_attachments'] === 0): ?>
                
                <!-- Migration Complete -->
                <div class="alo-migration-card">
                    <div class="notice notice-success">
                        <p><strong><?php _e('Migration Complete!', 'attachment-lookup-optimizer'); ?></strong></p>
                        <p><?php _e('All attachments have been successfully migrated to BunnyCDN.', 'attachment-lookup-optimizer'); ?></p>
                    </div>
                </div>
                
                <?php endif; ?>
                
            </div>
        </div>
        
        <?php wp_nonce_field('alo_bunnycdn_migration', 'alo_migration_nonce'); ?>
        <?php
    }
    
    /**
     * Get migration statistics
     */
    public function get_migration_stats() {
        // Check permissions before accessing migration data
        if (!current_user_can('manage_options')) {
            return [
                'total_attachments' => 0,
                'migrated_attachments' => 0,
                'pending_attachments' => 0,
                'progress_percentage' => 0
            ];
        }
        
        global $wpdb;
        
        // Get total attachments
        $total_attachments = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
        );
        
        // Get migrated attachments (those with _bunnycdn_url meta)
        $migrated_attachments = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'attachment' 
             AND p.post_status = 'inherit' 
             AND pm.meta_key = '_bunnycdn_url' 
             AND pm.meta_value != ''"
        );
        
        $pending_attachments = max(0, $total_attachments - $migrated_attachments);
        $progress_percentage = $total_attachments > 0 ? round(($migrated_attachments / $total_attachments) * 100, 1) : 0;
        
        return [
            'total_attachments' => (int) $total_attachments,
            'migrated_attachments' => (int) $migrated_attachments,
            'pending_attachments' => (int) $pending_attachments,
            'progress_percentage' => $progress_percentage
        ];
    }
    
    /**
     * Get attachments that need migration
     */
    public function get_pending_attachments($limit = null, $offset = 0) {
        // Check permissions before accessing attachment data
        if (!current_user_can('manage_options')) {
            return [];
        }
        
        global $wpdb;
        
        $limit_clause = $limit ? "LIMIT " . intval($limit) : "";
        $offset_clause = $offset > 0 ? "OFFSET " . intval($offset) : "";
        
        $query = "
            SELECT p.ID, p.post_title, pm.meta_value as attached_file
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bunnycdn_url'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND pm.meta_key = '_wp_attached_file'
            AND pm.meta_value != ''
            AND (pm2.meta_value IS NULL OR pm2.meta_value = '')
            ORDER BY p.ID ASC
            {$limit_clause} {$offset_clause}
        ";
        
        return $wpdb->get_results($query);
    }
    
    /**
     * AJAX handler for batch migration
     */
    public function ajax_migrate_batch() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'alo_bunnycdn_migration')) {
            wp_send_json_error(['message' => __('Permission denied', 'attachment-lookup-optimizer')]);
        }
        
        // Check if migration should stop
        if (get_transient('alo_stop_bunnycdn_migration')) {
            delete_transient('alo_stop_bunnycdn_migration');
            wp_send_json_success([
                'completed' => true,
                'message' => __('Migration stopped by user', 'attachment-lookup-optimizer')
            ]);
        }
        
        $offset = intval($_POST['offset'] ?? 0);
        $batch_size = self::BATCH_SIZE;
        
        // Get pending attachments for this batch
        $attachments = $this->get_pending_attachments($batch_size, $offset);
        
        if (empty($attachments)) {
            wp_send_json_success([
                'completed' => true,
                'message' => __('Migration completed successfully!', 'attachment-lookup-optimizer')
            ]);
        }
        
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'completed' => false,
            'next_offset' => $offset + count($attachments)
        ];
        
        foreach ($attachments as $attachment) {
            $results['processed']++;
            
            try {
                $success = $this->migrate_single_attachment($attachment->ID);
                
                if ($success) {
                    $results['successful']++;
                    error_log("ALO: BunnyCDN migration successful for attachment {$attachment->ID}");
                } else {
                    $results['failed']++;
                    $results['errors'][] = sprintf(__('Failed to migrate attachment %d: %s', 'attachment-lookup-optimizer'), $attachment->ID, $attachment->post_title);
                    error_log("ALO: BunnyCDN migration failed for attachment {$attachment->ID}");
                }
                
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(__('Exception migrating attachment %d: %s', 'attachment-lookup-optimizer'), $attachment->ID, $e->getMessage());
                error_log("ALO: BunnyCDN migration exception for attachment {$attachment->ID}: " . $e->getMessage());
            }
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Migrate a single attachment to BunnyCDN
     */
    private function migrate_single_attachment($attachment_id) {
        if (!$this->bunny_cdn_manager->is_enabled()) {
            return false;
        }
        
        // Get attachment file path
        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (!$attached_file) {
            return false;
        }
        
        // Build full file path
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $attached_file;
        
        if (!file_exists($file_path)) {
            error_log("ALO: BunnyCDN migration - file not found: {$file_path} for attachment {$attachment_id}");
            return false;
        }
        
        // Generate unique filename
        $file_extension = pathinfo($attached_file, PATHINFO_EXTENSION);
        $file_basename = pathinfo($attached_file, PATHINFO_FILENAME);
        $unique_filename = $file_basename . '_' . $attachment_id . '.' . $file_extension;
        
        // Upload to BunnyCDN with attachment ID for tracking
        $cdn_url = $this->bunny_cdn_manager->upload_file($file_path, $unique_filename, $attachment_id);
        
        if ($cdn_url) {
            // Store CDN URL and migration info
            update_post_meta($attachment_id, '_bunnycdn_url', $cdn_url);
            update_post_meta($attachment_id, '_bunnycdn_filename', $unique_filename);
            update_post_meta($attachment_id, '_bunnycdn_migrated_at', current_time('mysql', true));
            
            // Rewrite post content URLs if enabled
            $this->rewrite_post_content_urls($attachment_id, $cdn_url);
            
            // Schedule delayed local file deletion if offload is enabled
            // This prevents WordPress core from encountering missing files during processing
            if (get_option('alo_bunnycdn_offload_enabled', false)) {
                $this->schedule_delayed_file_deletion($attachment_id);
            }
            
            return true;
        }
        
        return false;
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
                        error_log("ALO: BunnyCDN migrator - content URL rewriting is disabled for attachment {$attachment_id}");
                    } elseif (isset($results['updated_posts']) && $results['updated_posts'] > 0) {
                        error_log("ALO: BunnyCDN migrator - content rewriter updated {$results['updated_posts']} posts with {$results['total_replacements']} URL replacements for attachment {$attachment_id}");
                    } elseif (isset($results['message'])) {
                        error_log("ALO: BunnyCDN migrator - content rewriter for attachment {$attachment_id}: {$results['message']}");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN migrator - content rewriter error for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Schedule delayed file deletion after WordPress processing is complete
     * 
     * @param int $attachment_id Attachment post ID
     */
    private function schedule_delayed_file_deletion($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return;
        }
        
        // Mark this attachment for delayed deletion
        update_post_meta($attachment_id, '_alo_pending_offload', true);
        
        error_log("ALO: BunnyCDN migrator - scheduled delayed offload for attachment {$attachment_id}");
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
                error_log("ALO: BunnyCDN migrator offload - main file not found for attachment {$attachment_id}: {$local_path}");
                return;
            }
            
            // Delete the main file
            if (@unlink($local_path)) {
                error_log("ALO: BunnyCDN migrator offload - deleted main file for attachment {$attachment_id}: {$local_path}");
            } else {
                error_log("ALO: BunnyCDN migrator offload - failed to delete main file for attachment {$attachment_id}: {$local_path}");
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
                                error_log("ALO: BunnyCDN migrator offload - deleted {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            } else {
                                error_log("ALO: BunnyCDN migrator offload - failed to delete {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            }
                        }
                    }
                }
            }
            
            // Mark attachment as offloaded
            update_post_meta($attachment_id, '_bunnycdn_offloaded', true);
            update_post_meta($attachment_id, '_bunnycdn_offloaded_at', current_time('mysql', true));
            
            error_log("ALO: BunnyCDN migrator offload - completed for attachment {$attachment_id}");
            
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN migrator offload - exception for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for migration status
     */
    public function ajax_migration_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'attachment-lookup-optimizer')]);
        }
        
        $stats = $this->get_migration_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX handler to stop migration
     */
    public function ajax_stop_migration() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'alo_bunnycdn_migration')) {
            wp_send_json_error(['message' => __('Permission denied', 'attachment-lookup-optimizer')]);
        }
        
        set_transient('alo_stop_bunnycdn_migration', true, 300); // 5 minutes
        wp_send_json_success(['message' => __('Migration will stop after current batch', 'attachment-lookup-optimizer')]);
    }
    
    /**
     * Get JavaScript for migration functionality
     */
    private function get_migration_javascript() {
        return "
        jQuery(document).ready(function($) {
            let migrationRunning = false;
            let currentOffset = 0;
            
            // Start migration
            $('#alo-start-migration').on('click', function() {
                if (migrationRunning) return;
                
                migrationRunning = true;
                currentOffset = 0;
                
                $('#alo-start-migration').hide();
                $('#alo-stop-migration').show();
                $('#alo-migration-progress').show();
                
                updateProgress(0, 'Starting migration...');
                processBatch();
            });
            
            // Stop migration
            $('#alo-stop-migration').on('click', function() {
                $.post(ajaxurl, {
                    action: 'alo_bunnycdn_stop_migration',
                    nonce: $('#alo_migration_nonce').val()
                });
                
                $(this).prop('disabled', true).text('Stopping...');
            });
            
            // Refresh statistics
            $('#alo-refresh-stats').on('click', function() {
                location.reload();
            });
            
            function processBatch() {
                if (!migrationRunning) return;
                
                $.post(ajaxurl, {
                    action: 'alo_bunnycdn_migrate_batch',
                    nonce: $('#alo_migration_nonce').val(),
                    offset: currentOffset
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        if (data.completed) {
                            migrationCompleted(data.message);
                            return;
                        }
                        
                        // Update progress
                        currentOffset = data.next_offset;
                        const message = 'Processed: ' + data.processed + ', Successful: ' + data.successful + ', Failed: ' + data.failed;
                        updateProgress(null, message);
                        
                        // Log results
                        logMessage('Batch completed: ' + message);
                        
                        // Log errors
                        if (data.errors.length > 0) {
                            data.errors.forEach(function(error) {
                                logMessage('Error: ' + error, 'error');
                            });
                        }
                        
                        // Continue with next batch
                        setTimeout(processBatch, 1000);
                        
                    } else {
                        migrationCompleted('Migration failed: ' + response.data.message);
                    }
                }).fail(function() {
                    migrationCompleted('Migration failed: Network error');
                });
            }
            
            function migrationCompleted(message) {
                migrationRunning = false;
                
                $('#alo-start-migration').show();
                $('#alo-stop-migration').hide();
                
                updateProgress(100, message);
                logMessage('Migration completed: ' + message);
                
                // Refresh stats after a delay
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
            
            function updateProgress(percentage, message) {
                if (percentage !== null) {
                    $('.alo-progress-fill').css('width', percentage + '%');
                }
                $('#alo-progress-text').text(message);
            }
            
            function logMessage(message, type = 'info') {
                const timestamp = new Date().toLocaleTimeString();
                const className = type === 'error' ? 'alo-log-error' : 'alo-log-info';
                $('#alo-log-content').append('<div class=\"' + className + '\">[' + timestamp + '] ' + message + '</div>');
                
                // Auto-scroll to bottom
                const logContent = document.getElementById('alo-log-content');
                logContent.scrollTop = logContent.scrollHeight;
            }
        });
        ";
    }
    
    /**
     * Get CSS for migration interface
     */
    private function get_migration_css() {
        return "
        .alo-migration-container {
            max-width: 800px;
        }
        
        .alo-migration-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .alo-migration-controls {
            margin: 15px 0;
        }
        
        .alo-migration-controls .button {
            margin-right: 10px;
        }
        
        .alo-progress-bar {
            width: 100%;
            height: 20px;
            background-color: #f0f0f1;
            border-radius: 10px;
            overflow: hidden;
            margin: 15px 0;
        }
        
        .alo-progress-fill {
            height: 100%;
            background-color: #00a32a;
            transition: width 0.3s ease;
        }
        
        .alo-progress-stats {
            margin: 15px 0;
        }
        
        .alo-migration-log {
            margin-top: 20px;
        }
        
        #alo-log-content {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            height: 200px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 12px;
        }
        
        .alo-log-error {
            color: #d63638;
        }
        
        .alo-log-info {
            color: #2271b1;
        }
        ";
    }
} 