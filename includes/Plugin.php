<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Main Plugin Class
 * 
 * Handles plugin initialization, database optimization, and caching
 */
class Plugin {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Database manager instance
     */
    private $database_manager;
    
    /**
     * Cache manager instance
     */
    private $cache_manager;
    
    /**
     * Admin interface instance
     */
    private $admin_interface;
    
    /**
     * Upload preprocessor instance
     */
    private $upload_preprocessor;
    
    /**
     * Custom lookup table instance
     */
    private $custom_lookup_table;
    
    /**
     * JetEngine preloader instance
     */
    private $jetengine_preloader;
    
    /**
     * Transient manager instance
     */
    private $transient_manager;
    
    /**
     * Lazy load manager instance
     */
    private $lazy_load_manager;
    
    /**
     * JetEngine query optimizer instance
     */
    private $jetengine_query_optimizer;
    
    /**
     * Get plugin instance (singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Initialize core managers
        $this->database_manager = new DatabaseManager();
        $this->cache_manager = new CacheManager();
        $this->upload_preprocessor = new UploadPreprocessor();
        $this->custom_lookup_table = new CustomLookupTable();
        $this->jetengine_preloader = new JetEnginePreloader();
        $this->transient_manager = new TransientManager();
        
        // Initialize performance enhancement managers
        $this->lazy_load_manager = new LazyLoadManager();
        $this->jetengine_query_optimizer = new JetEngineQueryOptimizer();
        
        // Connect upload preprocessor to cache manager for fast lookups
        $this->cache_manager->set_upload_preprocessor($this->upload_preprocessor);
        
        // Connect custom lookup table to cache manager for ultra-fast lookups
        $this->cache_manager->set_custom_lookup_table($this->custom_lookup_table);
        
        // Connect custom lookup table to upload preprocessor for population
        $this->upload_preprocessor->set_custom_lookup_table($this->custom_lookup_table);
        
        // Connect JetEngine preloader to optimization components
        $this->jetengine_preloader->set_cache_manager($this->cache_manager);
        $this->jetengine_preloader->set_custom_lookup_table($this->custom_lookup_table);
        
        // Connect transient manager to cache manager for managed transient operations
        $this->cache_manager->set_transient_manager($this->transient_manager);
        
        // Initialize admin interface (only in admin)
        if (is_admin()) {
            $this->admin_interface = new AdminInterface($this->database_manager, $this->cache_manager);
            $this->admin_interface->set_upload_preprocessor($this->upload_preprocessor);
            $this->admin_interface->set_custom_lookup_table($this->custom_lookup_table);
            $this->admin_interface->set_jetengine_preloader($this->jetengine_preloader);
            $this->admin_interface->set_transient_manager($this->transient_manager);
            $this->admin_interface->set_lazy_load_manager($this->lazy_load_manager);
            $this->admin_interface->set_jetengine_query_optimizer($this->jetengine_query_optimizer);
        }
        
        // Hook into WordPress init
        add_action('init', [$this, 'on_init']);
        
        // Hook into admin init for database checks
        add_action('admin_init', [$this->database_manager, 'check_and_create_indexes']);
        
        // Hook into init with priority 20 for attached file index
        add_action('init', [$this->database_manager, 'check_and_create_attached_file_index'], 20);
        
        // Initialize cache hooks
        $this->cache_manager->init_hooks();
        
        // Schedule cleanup cron job
        $this->schedule_cleanup();
        
        // Register cleanup hook
        add_action('alo_daily_cleanup', [$this, 'run_daily_cleanup']);
    }
    
    /**
     * WordPress init hook
     */
    public function on_init() {
        // Load text domain for translations
        load_plugin_textdomain(
            'attachment-lookup-optimizer',
            false,
            dirname(plugin_basename(ALO_PLUGIN_FILE)) . '/languages'
        );
        
        // Additional initialization can go here
        do_action('alo_init');
    }
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Create database indexes on activation
        $database_manager = new DatabaseManager();
        $database_manager->create_postmeta_indexes();
        
        // Set activation flag
        update_option('alo_activated', true);
        update_option('alo_version', ALO_VERSION);
        
        // Schedule daily cleanup cron job
        if (!wp_next_scheduled('alo_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'alo_daily_cleanup');
        }
        
        // Clear any existing cache
        wp_cache_flush();
        
        do_action('alo_activated');
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled cleanup cron job
        wp_clear_scheduled_hook('alo_daily_cleanup');
        
        // Clear cache on deactivation
        wp_cache_flush();
        
        // Remove activation flag
        delete_option('alo_activated');
        
        do_action('alo_deactivated');
    }
    
    /**
     * Get plugin version
     */
    public function get_version() {
        return ALO_VERSION;
    }
    
    /**
     * Check if plugin is properly activated
     */
    public function is_activated() {
        return get_option('alo_activated', false);
    }
    
    /**
     * Get cache manager instance
     */
    public function get_cache_manager() {
        return $this->cache_manager;
    }
    
    /**
     * Get database manager instance
     */
    public function get_database_manager() {
        return $this->database_manager;
    }
    
    /**
     * Get upload preprocessor instance
     */
    public function get_upload_preprocessor() {
        return $this->upload_preprocessor;
    }
    
    /**
     * Get custom lookup table instance
     */
    public function get_custom_lookup_table() {
        return $this->custom_lookup_table;
    }
    
    /**
     * Get JetEngine preloader instance
     */
    public function get_jetengine_preloader() {
        return $this->jetengine_preloader;
    }
    
    /**
     * Get transient manager instance
     */
    public function get_transient_manager() {
        return $this->transient_manager;
    }
    
    /**
     * Get lazy load manager instance
     */
    public function get_lazy_load_manager() {
        return $this->lazy_load_manager;
    }
    
    /**
     * Get JetEngine query optimizer instance
     */
    public function get_jetengine_query_optimizer() {
        return $this->jetengine_query_optimizer;
    }
    
    /**
     * Schedule cleanup if not already scheduled
     */
    public function schedule_cleanup() {
        if (!wp_next_scheduled('alo_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'alo_daily_cleanup');
        }
    }
    
    /**
     * Run daily cleanup tasks
     */
    public function run_daily_cleanup() {
        $start_time = microtime(true);
        $cleanup_stats = [
            'transients_deleted' => 0,
            'logs_trimmed' => 0,
            'watchlist_entries_removed' => 0,
            'errors' => [],
            'start_time' => date('Y-m-d H:i:s'),
            'execution_time' => 0
        ];
        
        try {
            // 1. Delete transient cache older than 48h
            $cleanup_stats['transients_deleted'] = $this->cleanup_old_transients();
            
            // 2. Trim log files to last 100 entries
            $cleanup_stats['logs_trimmed'] = $this->trim_log_files();
            
            // 3. Remove fallback watchlist entries older than 7 days
            $cleanup_stats['watchlist_entries_removed'] = $this->cleanup_old_watchlist_entries();
            
            // 4. Clean up debug logs
            $this->cleanup_debug_logs();
            
            // 5. Clean up slow query samples
            $this->cleanup_slow_query_samples();
            
        } catch (Exception $e) {
            $cleanup_stats['errors'][] = $e->getMessage();
            error_log('ALO Daily Cleanup Error: ' . $e->getMessage());
        }
        
        $cleanup_stats['execution_time'] = round((microtime(true) - $start_time) * 1000, 2);
        
        // Store cleanup stats for admin review
        set_transient('alo_last_cleanup_stats', $cleanup_stats, WEEK_IN_SECONDS);
        
        // Log the cleanup completion
        error_log(sprintf(
            'ALO Daily Cleanup completed: %d transients, %d logs, %d watchlist entries cleaned in %sms',
            $cleanup_stats['transients_deleted'],
            $cleanup_stats['logs_trimmed'],
            $cleanup_stats['watchlist_entries_removed'],
            $cleanup_stats['execution_time']
        ));
        
        do_action('alo_daily_cleanup_completed', $cleanup_stats);
    }
    
    /**
     * Clean up transients older than 48 hours
     */
    private function cleanup_old_transients() {
        global $wpdb;
        
        $deleted_count = 0;
        $cutoff_time = time() - (48 * HOUR_IN_SECONDS);
        
        // Clean up our specific transients
        $transient_patterns = [
            '_transient_alo_url_%',
            '_transient_timeout_alo_url_%',
            '_transient_alo_file_failure_%',
            '_transient_alo_slow_query_samples',
            '_transient_alo_recent_fallback_errors'
        ];
        
        foreach ($transient_patterns as $pattern) {
            // For timeout transients, check if they're expired
            if (strpos($pattern, 'timeout') !== false) {
                $expired = $wpdb->get_results($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} 
                     WHERE option_name LIKE %s 
                     AND option_value < %d",
                    $pattern,
                    $cutoff_time
                ));
                
                foreach ($expired as $row) {
                    $transient_key = str_replace('_transient_timeout_', '', $row->option_name);
                    if (delete_transient($transient_key)) {
                        $deleted_count++;
                    }
                }
            } else {
                // For regular transients, check if corresponding timeout exists and is expired
                $transients = $wpdb->get_results($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} 
                     WHERE option_name LIKE %s",
                    $pattern
                ));
                
                foreach ($transients as $row) {
                    $transient_key = str_replace('_transient_', '', $row->option_name);
                    $timeout_value = get_option('_transient_timeout_' . $transient_key);
                    
                    if ($timeout_value && $timeout_value < $cutoff_time) {
                        if (delete_transient($transient_key)) {
                            $deleted_count++;
                        }
                    }
                }
            }
        }
        
        return $deleted_count;
    }
    
    /**
     * Trim log files to last 100 entries
     */
    private function trim_log_files() {
        $trimmed_count = 0;
        
        // Trim debug logger files
        if ($this->cache_manager) {
            $debug_logger = $this->cache_manager->get_debug_logger();
            if ($debug_logger) {
                $log_files = $debug_logger->get_log_files();
                
                foreach ($log_files as $log_file) {
                    $file_path = $debug_logger->get_log_directory() . '/' . $log_file;
                    
                    if (file_exists($file_path)) {
                        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        
                        if (count($lines) > 100) {
                            // Keep only the last 100 lines
                            $trimmed_lines = array_slice($lines, -100);
                            
                            // Add header to indicate trimming
                            $header = '# Trimmed by daily cleanup on ' . date('Y-m-d H:i:s') . ' - keeping last 100 entries';
                            array_unshift($trimmed_lines, $header);
                            
                            // Write back to file
                            file_put_contents($file_path, implode("\n", $trimmed_lines) . "\n", LOCK_EX);
                            $trimmed_count++;
                        }
                    }
                }
            }
        }
        
        // Trim flat log file
        $flat_log_file = WP_CONTENT_DIR . '/debug-attachment-fallback.log';
        if (file_exists($flat_log_file)) {
            $lines = file($flat_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            if (count($lines) > 100) {
                $trimmed_lines = array_slice($lines, -100);
                $header = sprintf('[%s] [CLEANUP] Log trimmed - keeping last 100 entries', date('Y-m-d H:i:s'));
                array_unshift($trimmed_lines, $header);
                
                file_put_contents($flat_log_file, implode("\n", $trimmed_lines) . "\n", LOCK_EX);
                $trimmed_count++;
            }
        }
        
        return $trimmed_count;
    }
    
    /**
     * Remove watchlist entries older than 7 days
     */
    private function cleanup_old_watchlist_entries() {
        $removed_count = 0;
        $cutoff_time = time() - (7 * DAY_IN_SECONDS);
        
        $watchlist = get_transient('alo_fallback_watchlist');
        if (is_array($watchlist)) {
            $cleaned_watchlist = [];
            
            foreach ($watchlist as $url => $data) {
                // Keep entries that are newer than 7 days
                if (isset($data['last_failure']) && $data['last_failure'] > $cutoff_time) {
                    $cleaned_watchlist[$url] = $data;
                } else {
                    $removed_count++;
                }
            }
            
            // Update the watchlist if we removed entries
            if ($removed_count > 0) {
                if (empty($cleaned_watchlist)) {
                    delete_transient('alo_fallback_watchlist');
                } else {
                    set_transient('alo_fallback_watchlist', $cleaned_watchlist, 7 * DAY_IN_SECONDS);
                }
            }
        }
        
        return $removed_count;
    }
    
    /**
     * Clean up debug logs older than 30 days
     */
    private function cleanup_debug_logs() {
        if (!$this->cache_manager) {
            return;
        }
        
        $debug_logger = $this->cache_manager->get_debug_logger();
        if (!$debug_logger) {
            return;
        }
        
        $log_directory = $debug_logger->get_log_directory();
        if (!is_dir($log_directory)) {
            return;
        }
        
        $cutoff_time = time() - (30 * DAY_IN_SECONDS);
        $files = glob($log_directory . '/*.log');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }
    
    /**
     * Clean up slow query samples older than 24 hours
     */
    private function cleanup_slow_query_samples() {
        $samples = get_transient('alo_slow_query_samples');
        if (!is_array($samples)) {
            return;
        }
        
        $cutoff_time = time() - DAY_IN_SECONDS;
        $cleaned_samples = [];
        
        foreach ($samples as $sample) {
            if (isset($sample['timestamp']) && $sample['timestamp'] > $cutoff_time) {
                $cleaned_samples[] = $sample;
            }
        }
        
        // Update samples or delete if empty
        if (empty($cleaned_samples)) {
            delete_transient('alo_slow_query_samples');
        } else {
            set_transient('alo_slow_query_samples', $cleaned_samples, DAY_IN_SECONDS);
        }
    }
    
    /**
     * Get last cleanup statistics
     */
    public function get_last_cleanup_stats() {
        return get_transient('alo_last_cleanup_stats');
    }
    
    /**
     * Force run cleanup (for manual testing)
     */
    public function force_cleanup() {
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        $this->run_daily_cleanup();
        return true;
    }
} 