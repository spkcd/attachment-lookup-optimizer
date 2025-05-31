<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Custom Lookup Table Class
 * 
 * Manages a dedicated table for fast attachment file path lookups
 * Syncs with wp_postmeta _wp_attached_file entries automatically
 */
class CustomLookupTable {
    
    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'attachment_lookup';
    
    /**
     * Table version for schema updates
     */
    const TABLE_VERSION = '1.0';
    
    /**
     * Option name for table version
     */
    const VERSION_OPTION = 'alo_custom_table_version';
    
    /**
     * Option name for custom table feature
     */
    const ENABLED_OPTION = 'alo_custom_lookup_table';
    
    /**
     * Whether the custom table feature is enabled
     */
    private $enabled = false;
    
    /**
     * Whether the table exists and is ready
     */
    private $table_ready = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->enabled = get_option(self::ENABLED_OPTION, false);
        
        if ($this->enabled) {
            $this->init_hooks();
            $this->ensure_table_exists();
        }
    }
    
    /**
     * Initialize hooks for syncing with postmeta
     */
    private function init_hooks() {
        // Sync on attachment save
        add_action('add_attachment', [$this, 'sync_attachment'], 10, 1);
        add_action('edit_attachment', [$this, 'sync_attachment'], 10, 1);
        
        // Sync when _wp_attached_file meta is updated
        add_action('updated_post_meta', [$this, 'sync_meta_update'], 10, 4);
        add_action('added_post_meta', [$this, 'sync_meta_update'], 10, 4);
        add_action('deleted_post_meta', [$this, 'sync_meta_delete'], 10, 4);
        
        // Clean up when attachment is deleted
        add_action('delete_attachment', [$this, 'delete_attachment_lookup'], 10, 1);
        
        // Bulk sync on plugin activation
        add_action('alo_custom_table_enabled', [$this, 'bulk_sync_existing_attachments']);
    }
    
    /**
     * Get the full table name with WordPress prefix
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }
    
    /**
     * Check if the custom table exists
     */
    public function table_exists() {
        if (!$this->enabled) {
            return false;
        }
        
        if ($this->table_ready !== null) {
            return $this->table_ready;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        )) > 0;
        
        $this->table_ready = $table_exists;
        return $table_exists;
    }
    
    /**
     * Ensure the table exists, create if needed
     */
    private function ensure_table_exists() {
        if ($this->table_exists()) {
            return true;
        }
        
        return $this->create_table();
    }
    
    /**
     * Create the custom lookup table
     */
    public function create_table() {
        global $wpdb;
        
        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            meta_value text NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_meta_value (meta_value(191)),
            KEY post_id_idx (post_id),
            KEY updated_at_idx (updated_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Update table version
        update_option(self::VERSION_OPTION, self::TABLE_VERSION);
        
        // Set table as ready
        $this->table_ready = true;
        
        // Log table creation
        error_log('ALO: Custom lookup table created: ' . $table_name);
        
        return true;
    }
    
    /**
     * Drop the custom lookup table
     */
    public function drop_table() {
        global $wpdb;
        
        $table_name = $this->get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        // Remove table version
        delete_option(self::VERSION_OPTION);
        
        // Mark table as not ready
        $this->table_ready = false;
        
        error_log('ALO: Custom lookup table dropped: ' . $table_name);
    }
    
    /**
     * Get attachment post ID by file path
     */
    public function get_post_id_by_path($file_path) {
        if (!$this->table_exists()) {
            return false;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // Try exact match first
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $table_name WHERE meta_value = %s LIMIT 1",
            $file_path
        ));
        
        if ($post_id) {
            return (int) $post_id;
        }
        
        // Try with leading slash
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $table_name WHERE meta_value = %s LIMIT 1",
            '/' . ltrim($file_path, '/')
        ));
        
        if ($post_id) {
            return (int) $post_id;
        }
        
        // Try without leading slash
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $table_name WHERE meta_value = %s LIMIT 1",
            ltrim($file_path, '/')
        ));
        
        return $post_id ? (int) $post_id : false;
    }
    
    /**
     * Sync attachment data to custom table
     */
    public function sync_attachment($post_id) {
        if (!$this->table_exists() || get_post_type($post_id) !== 'attachment') {
            return;
        }
        
        $attached_file = get_post_meta($post_id, '_wp_attached_file', true);
        if (!$attached_file) {
            return;
        }
        
        $this->upsert_lookup($post_id, $attached_file);
    }
    
    /**
     * Sync when postmeta is updated
     */
    public function sync_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key !== '_wp_attached_file' || !$this->table_exists()) {
            return;
        }
        
        if (get_post_type($post_id) !== 'attachment') {
            return;
        }
        
        $this->upsert_lookup($post_id, $meta_value);
    }
    
    /**
     * Sync when postmeta is deleted
     */
    public function sync_meta_delete($meta_ids, $post_id, $meta_key, $meta_values) {
        if ($meta_key !== '_wp_attached_file' || !$this->table_exists()) {
            return;
        }
        
        $this->delete_attachment_lookup($post_id);
    }
    
    /**
     * Insert or update lookup record
     */
    private function upsert_lookup($post_id, $meta_value) {
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // First, remove any existing records for this post_id
        $wpdb->delete($table_name, ['post_id' => $post_id], ['%d']);
        
        // Insert new record
        $result = $wpdb->insert(
            $table_name,
            [
                'post_id' => $post_id,
                'meta_value' => $meta_value,
                'updated_at' => current_time('mysql', true)
            ],
            ['%d', '%s', '%s']
        );
        
        if ($result === false) {
            error_log('ALO: Failed to upsert lookup for post_id: ' . $post_id . ', meta_value: ' . $meta_value);
        }
    }
    
    /**
     * Delete lookup record for attachment
     */
    public function delete_attachment_lookup($post_id) {
        if (!$this->table_exists()) {
            return;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        $wpdb->delete($table_name, ['post_id' => $post_id], ['%d']);
    }
    
    /**
     * Bulk sync all existing attachments
     */
    public function bulk_sync_existing_attachments($limit = 1000) {
        if (!$this->table_exists()) {
            return 0;
        }
        
        global $wpdb;
        
        // Get all attachments with _wp_attached_file meta
        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.meta_key = '_wp_attached_file'
             AND pm.meta_value != ''
             LIMIT %d",
            $limit
        ));
        
        $synced = 0;
        foreach ($attachments as $attachment) {
            $this->upsert_lookup($attachment->ID, $attachment->meta_value);
            $synced++;
        }
        
        return $synced;
    }
    
    /**
     * Rebuild the entire table from scratch
     */
    public function rebuild_table() {
        // Drop and recreate table
        $this->drop_table();
        $this->create_table();
        
        // Sync all existing attachments
        return $this->bulk_sync_existing_attachments(10000);
    }
    
    /**
     * Get table statistics
     */
    public function get_stats() {
        if (!$this->table_exists()) {
            return [
                'table_exists' => false,
                'total_mappings' => 0,
                'table_size_mb' => 0,
                'created_at' => 0
            ];
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // Get record count
        $total_mappings = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        // Get table size
        $table_size = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) 
             FROM information_schema.TABLES 
             WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        
        // Get creation time from table version option
        $created_at = get_option(self::VERSION_OPTION . '_created', 0);
        if (!$created_at) {
            // Fallback: estimate from earliest record
            $earliest = $wpdb->get_var("SELECT MIN(updated_at) FROM $table_name");
            if ($earliest) {
                $created_at = strtotime($earliest);
                update_option(self::VERSION_OPTION . '_created', $created_at);
            }
        }
        
        return [
            'table_exists' => true,
            'total_mappings' => (int) $total_mappings,
            'table_size_mb' => (float) $table_size,
            'created_at' => $created_at,
            'table_name' => $table_name,
            'version' => get_option(self::VERSION_OPTION, '0')
        ];
    }
    
    /**
     * Enable the custom table feature
     */
    public function enable() {
        update_option(self::ENABLED_OPTION, true);
        $this->enabled = true;
        
        // Initialize hooks and create table
        $this->init_hooks();
        $this->ensure_table_exists();
        
        // Trigger bulk sync
        do_action('alo_custom_table_enabled');
        
        // Log creation timestamp
        update_option(self::VERSION_OPTION . '_created', current_time('timestamp'));
    }
    
    /**
     * Disable the custom table feature
     */
    public function disable() {
        update_option(self::ENABLED_OPTION, false);
        $this->enabled = false;
        
        // Note: We don't drop the table automatically to preserve data
        // Admin can manually rebuild if they want to clean up
    }
    
    /**
     * Check if the feature is enabled
     */
    public function is_enabled() {
        return $this->enabled;
    }
    
    /**
     * Get lookup performance for a specific file path
     */
    public function test_lookup($file_path) {
        if (!$this->table_exists()) {
            return [
                'error' => 'Custom table not available',
                'table_exists' => false
            ];
        }
        
        $start_time = microtime(true);
        $post_id = $this->get_post_id_by_path($file_path);
        $end_time = microtime(true);
        
        return [
            'file_path' => $file_path,
            'post_id' => $post_id,
            'found' => $post_id !== false,
            'execution_time_ms' => ($end_time - $start_time) * 1000,
            'table_exists' => true,
            'method' => 'custom_table_lookup'
        ];
    }
    
    /**
     * Optimize table for better performance
     */
    public function optimize_table() {
        if (!$this->table_exists()) {
            return false;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // Optimize table
        $wpdb->query("OPTIMIZE TABLE $table_name");
        
        // Analyze table
        $wpdb->query("ANALYZE TABLE $table_name");
        
        return true;
    }
    
    /**
     * Clean up orphaned records (where post no longer exists)
     */
    public function cleanup_orphaned_records() {
        if (!$this->table_exists()) {
            return 0;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // Delete records where post_id doesn't exist in wp_posts
        $deleted = $wpdb->query(
            "DELETE al FROM $table_name al
             LEFT JOIN {$wpdb->posts} p ON al.post_id = p.ID
             WHERE p.ID IS NULL"
        );
        
        return $deleted;
    }
} 