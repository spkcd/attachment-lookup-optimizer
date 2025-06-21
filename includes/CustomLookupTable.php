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
    const TABLE_VERSION = '2.0';
    
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
    private $table_ready = null;
    
    /**
     * Static cache for table existence to avoid multiple DB queries per request
     */
    private static $table_exists_cache = null;
    
    /**
     * Static flag to prevent multiple table creation attempts in same request
     */
    private static $table_creation_attempted = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->enabled = get_option(self::ENABLED_OPTION, false);
        
        if ($this->enabled) {
            $this->init_hooks();
            
            // Only attempt table operations once per minute to prevent spam
            $last_attempt = get_transient('alo_table_creation_attempt');
            if (!$last_attempt) {
                set_transient('alo_table_creation_attempt', time(), 60); // 60 seconds
                
                // Ensure table exists
                $this->ensure_table_exists();
                
                // Run migration if needed (for existing installations)
                if ($this->table_exists()) {
                    $this->migrate_to_hash_structure();
                }
            }
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
        
        // Use static cache to avoid multiple DB queries in same request
        if (self::$table_exists_cache !== null) {
            return self::$table_exists_cache;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // Use multiple verification methods for reliability
        $table_exists_info_schema = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        )) > 0;
        
        $table_exists_show_tables = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        // Table exists if either method confirms it
        $table_exists = $table_exists_info_schema || $table_exists_show_tables;
        
        // Additional verification: check if we can query the table structure
        if ($table_exists) {
            $table_structure = $wpdb->get_results("DESCRIBE $table_name");
            $table_exists = !empty($table_structure);
        }
        
        // Cache the result for this request
        self::$table_exists_cache = $table_exists;
        $this->table_ready = $table_exists;
        
        return $table_exists;
    }
    
    /**
     * Ensure the table exists, create if needed
     */
    private function ensure_table_exists() {
        // Use cached result if available
        if ($this->table_ready === true) {
            return true;
        }
        
        // Check if table exists first
        if ($this->table_exists()) {
            return true;
        }
        
        // Only create if it doesn't exist
        return $this->create_table();
    }
    
    /**
     * Create the custom lookup table
     */
    public function create_table() {
        global $wpdb;

        // Enable the feature first if not already enabled
        if (!$this->enabled) {
            $this->enabled = true;
            update_option('alo_custom_lookup_table', true);
        }

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Log the table creation attempt
        error_log('ALO: Attempting to create custom lookup table: ' . $table_name);

        // First, let's try a direct approach without checking existing columns
        // Drop table if it exists but is problematic
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        error_log('ALO: Dropped existing table (if any): ' . $table_name);

        // Fixed SQL - use hash for unique constraint to avoid truncation issues
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            meta_value text NOT NULL,
            meta_value_hash char(32) NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_meta_value_hash (meta_value_hash),
            KEY post_id_idx (post_id),
            KEY updated_at_idx (updated_at),
            KEY meta_value_prefix_idx (meta_value(191))
        ) $charset_collate;";

        // Try direct creation first
        $direct_result = $wpdb->query($sql);
        error_log('ALO: Direct CREATE TABLE result: ' . ($direct_result !== false ? 'SUCCESS' : 'FAILED'));
        
        if ($wpdb->last_error) {
            error_log('ALO: Direct creation MySQL error: ' . $wpdb->last_error);
        }

        // Also try with dbDelta for WordPress compatibility
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Log the dbDelta result
        if (is_array($result)) {
            error_log('ALO: dbDelta result: ' . print_r($result, true));
        }
        
        // Check for MySQL errors
        if (!empty($wpdb->last_error)) {
            error_log('ALO: MySQL error during table creation: ' . $wpdb->last_error);
        }

        // Reset table_ready cache to force fresh check
        $this->table_ready = null;
        self::$table_exists_cache = null; // Clear static cache
        
        // Verify table was created successfully with multiple methods
        $table_exists_info_schema = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        )) > 0;
        
        $table_exists_show_tables = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        error_log('ALO: Table existence check - info_schema: ' . ($table_exists_info_schema ? 'YES' : 'NO') . ', show_tables: ' . ($table_exists_show_tables ? 'YES' : 'NO'));
        
        if (!$table_exists_info_schema && !$table_exists_show_tables) {
            error_log('ALO: Failed to create custom lookup table: ' . $table_name);
            error_log('ALO: Last MySQL error: ' . $wpdb->last_error);
            error_log('ALO: SQL used: ' . $sql);
            return false;
        }

        // Test basic operations
        $test_value = 'test/creation/verification.jpg';
        $test_insert = $wpdb->insert(
            $table_name,
            [
                'post_id' => 999999,
                'meta_value' => $test_value,
                'meta_value_hash' => md5($test_value),
                'updated_at' => current_time('mysql', true)
            ],
            ['%d', '%s', '%s', '%s']
        );
        
        if ($test_insert !== false) {
            $wpdb->delete($table_name, ['post_id' => 999999], ['%d']);
            error_log('ALO: Table operations test: SUCCESS');
        } else {
            error_log('ALO: Table operations test: FAILED - ' . $wpdb->last_error);
        }

        // Update table version
        update_option(self::VERSION_OPTION, self::TABLE_VERSION);

        // Set table as ready
        $this->table_ready = true;

        // Log successful table creation
        error_log('ALO: Custom lookup table created successfully: ' . $table_name);

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
        self::$table_exists_cache = null; // Clear static cache
        
        error_log('ALO: Custom lookup table dropped: ' . $table_name);
    }
    
    /**
     * Migrate existing table to new hash-based structure
     */
    public function migrate_to_hash_structure() {
        if (!$this->table_exists()) {
            return false;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // Check if meta_value_hash column already exists
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $has_hash_column = false;
        
        foreach ($columns as $column) {
            if ($column->Field === 'meta_value_hash') {
                $has_hash_column = true;
                break;
            }
        }
        
        if (!$has_hash_column) {
            // Add the hash column
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN meta_value_hash char(32) NOT NULL DEFAULT ''");
            
            // Remove the old truncated unique key if it exists
            $wpdb->query("ALTER TABLE $table_name DROP INDEX unique_meta_value");
            
            // Add the hash index
            $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY unique_meta_value_hash (meta_value_hash)");
            
            // Add prefix index for meta_value searches
            $wpdb->query("ALTER TABLE $table_name ADD KEY meta_value_prefix_idx (meta_value(191))");
            
            error_log('ALO: Added meta_value_hash column and updated indexes');
        }
        
        // Populate hash values for existing records
        $records_updated = $wpdb->query(
            "UPDATE $table_name SET meta_value_hash = MD5(meta_value) WHERE meta_value_hash = ''"
        );
        
        error_log("ALO: Updated $records_updated records with hash values");
        
        return true;
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
        
        // Try exact match first using hash for faster lookup
        $meta_value_hash = md5($file_path);
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $table_name WHERE meta_value_hash = %s LIMIT 1",
            $meta_value_hash
        ));
        
        if ($post_id) {
            return (int) $post_id;
        }
        
        // Try with leading slash
        $with_slash = '/' . ltrim($file_path, '/');
        if ($with_slash !== $file_path) {
            $meta_value_hash = md5($with_slash);
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $table_name WHERE meta_value_hash = %s LIMIT 1",
                $meta_value_hash
            ));
            
            if ($post_id) {
                return (int) $post_id;
            }
        }
        
        // Try without leading slash
        $without_slash = ltrim($file_path, '/');
        if ($without_slash !== $file_path) {
            $meta_value_hash = md5($without_slash);
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $table_name WHERE meta_value_hash = %s LIMIT 1",
                $meta_value_hash
            ));
            
            if ($post_id) {
                return (int) $post_id;
            }
        }
        
        return false;
    }
    
    /**
     * Get multiple attachment post IDs by file paths (batch operation)
     * 
     * @param array $file_paths Array of file paths to look up
     * @return array Associative array mapping file paths to post IDs (false if not found)
     */
    public function batch_get_post_ids($file_paths) {
        if (!$this->table_exists() || empty($file_paths)) {
            return [];
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        $results = [];
        
        // Initialize results array with false values
        foreach ($file_paths as $file_path) {
            $results[$file_path] = false;
        }
        
        // Prepare all variations of file paths for bulk lookup using hashes
        $lookup_hashes = [];
        $hash_mapping = [];
        
        foreach ($file_paths as $file_path) {
            // Original path
            $hash = md5($file_path);
            $lookup_hashes[] = $hash;
            $hash_mapping[$hash] = $file_path;
            
            // With leading slash
            $with_slash = '/' . ltrim($file_path, '/');
            if ($with_slash !== $file_path) {
                $hash = md5($with_slash);
                $lookup_hashes[] = $hash;
                $hash_mapping[$hash] = $file_path;
            }
            
            // Without leading slash
            $without_slash = ltrim($file_path, '/');
            if ($without_slash !== $file_path) {
                $hash = md5($without_slash);
                $lookup_hashes[] = $hash;
                $hash_mapping[$hash] = $file_path;
            }
        }
        
        // Remove duplicates
        $lookup_hashes = array_unique($lookup_hashes);
        
        if (empty($lookup_hashes)) {
            return $results;
        }
        
        // Create placeholders for IN query
        $placeholders = array_fill(0, count($lookup_hashes), '%s');
        $in_clause = implode(',', $placeholders);
        
        // Perform bulk lookup using hashes
        $query = "SELECT meta_value, meta_value_hash, post_id FROM $table_name WHERE meta_value_hash IN ($in_clause)";
        $lookup_results = $wpdb->get_results($wpdb->prepare($query, $lookup_hashes));
        
        // Map results back to original file paths
        foreach ($lookup_results as $row) {
            $found_hash = $row->meta_value_hash;
            $post_id = (int) $row->post_id;
            
            // Find the original file path this result corresponds to
            if (isset($hash_mapping[$found_hash])) {
                $original_path = $hash_mapping[$found_hash];
                $results[$original_path] = $post_id;
            }
        }
        
        return $results;
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
        
        // Generate hash for the meta_value to avoid truncation issues
        $meta_value_hash = md5($meta_value);
        
        // Check if this meta_value already exists using hash (regardless of post_id)
        $existing_record = $wpdb->get_row($wpdb->prepare(
            "SELECT id, post_id FROM $table_name WHERE meta_value_hash = %s LIMIT 1",
            $meta_value_hash
        ));
        
        if ($existing_record) {
            // Update existing record with new post_id
            $result = $wpdb->update(
                $table_name,
                [
                    'post_id' => $post_id,
                    'meta_value' => $meta_value,
                    'updated_at' => current_time('mysql', true)
                ],
                ['id' => $existing_record->id],
                ['%d', '%s', '%s'],
                ['%d']
            );
            
            if ($result === false) {
                error_log('ALO: Failed to update lookup for post_id: ' . $post_id . ', meta_value: ' . $meta_value);
            }
        } else {
            // Remove any existing records for this post_id (in case post changed file)
            $wpdb->delete($table_name, ['post_id' => $post_id], ['%d']);
            
            // Insert new record with hash
            $result = $wpdb->insert(
                $table_name,
                [
                    'post_id' => $post_id,
                    'meta_value' => $meta_value,
                    'meta_value_hash' => $meta_value_hash,
                    'updated_at' => current_time('mysql', true)
                ],
                ['%d', '%s', '%s', '%s']
            );
            
            if ($result === false) {
                error_log('ALO: Failed to insert lookup for post_id: ' . $post_id . ', meta_value: ' . $meta_value);
            }
        }
    }
    
    /**
     * Delete lookup record for attachment
     */
    public function delete_attachment_lookup($post_id) {
        if (!$this->table_exists()) {
            return false;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        return $wpdb->delete($table_name, ['post_id' => $post_id], ['%d']);
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
        
        if (!$this->create_table()) {
            return 0;
        }
        
        // Sync all existing attachments with duplicate handling
        return $this->bulk_sync_existing_attachments_safe(10000);
    }
    
    /**
     * Safe bulk sync that handles duplicates
     */
    private function bulk_sync_existing_attachments_safe($limit = 1000) {
        if (!$this->table_exists()) {
            return 0;
        }
        
        global $wpdb;
        
        // Get all attachments with _wp_attached_file meta
        // Group by meta_value to handle duplicates
        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value, MAX(p.post_date) as latest_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.meta_key = '_wp_attached_file'
             AND pm.meta_value != ''
             GROUP BY pm.meta_value
             ORDER BY latest_date DESC
             LIMIT %d",
            $limit
        ));
        
        $synced = 0;
        foreach ($attachments as $attachment) {
            // For each unique meta_value, use the most recent post
            $this->upsert_lookup($attachment->ID, $attachment->meta_value);
            $synced++;
        }
        
        return $synced;
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
    
    /**
     * Alias for upsert_lookup for compatibility with UploadPreprocessor
     * 
     * @param string $file_path The file path to map
     * @param int $post_id The post ID to map to
     * @return bool Success status
     */
    public function upsert_mapping($file_path, $post_id) {
        return $this->upsert_lookup($post_id, $file_path);
    }
    
    /**
     * Clean up duplicate file entries in the database
     */
    public function cleanup_duplicate_entries() {
        if (!$this->table_exists()) {
            return 0;
        }
        
        global $wpdb;
        $table_name = $this->get_table_name();
        
        // Find and remove duplicates using hash, keeping the most recent one
        $duplicates_removed = $wpdb->query(
            "DELETE t1 FROM $table_name t1
             INNER JOIN $table_name t2 
             WHERE t1.meta_value_hash = t2.meta_value_hash 
             AND t1.id < t2.id"
        );
        
        return $duplicates_removed;
    }
} 