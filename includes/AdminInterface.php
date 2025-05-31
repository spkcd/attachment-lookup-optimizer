<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Admin Interface Class
 * 
 * Handles the WordPress admin interface for the plugin
 */
class AdminInterface {
    
    /**
     * Option name for cache TTL setting
     */
    const CACHE_TTL_OPTION = 'alo_cache_ttl';
    
    /**
     * Default cache TTL (5 minutes)
     */
    const DEFAULT_CACHE_TTL = 300;
    
    /**
     * Option name for debug logging setting
     */
    const DEBUG_LOGGING_OPTION = 'alo_debug_logging';
    
    /**
     * Option name for debug threshold setting  
     */
    const DEBUG_THRESHOLD_OPTION = 'alo_debug_threshold';
    
    /**
     * Option name for global override setting
     */
    const GLOBAL_OVERRIDE_OPTION = 'alo_global_override';
    
    /**
     * Database manager instance
     */
    private $database_manager;
    
    /**
     * Cache manager instance
     */
    private $cache_manager;
    
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
     * JetEngine query optimizer instance
     */
    private $jetengine_query_optimizer;
    
    /**
     * Lazy load manager instance
     */
    private $lazy_load_manager;
    
    /**
     * Constructor
     */
    public function __construct($database_manager, $cache_manager) {
        $this->database_manager = $database_manager;
        $this->cache_manager = $cache_manager;
        
        $this->init_hooks();
    }
    
    /**
     * Set upload preprocessor instance
     */
    public function set_upload_preprocessor($upload_preprocessor) {
        $this->upload_preprocessor = $upload_preprocessor;
    }
    
    /**
     * Set custom lookup table instance
     */
    public function set_custom_lookup_table($custom_lookup_table) {
        $this->custom_lookup_table = $custom_lookup_table;
    }
    
    /**
     * Set JetEngine preloader instance
     */
    public function set_jetengine_preloader($jetengine_preloader) {
        $this->jetengine_preloader = $jetengine_preloader;
    }
    
    /**
     * Set transient manager instance
     */
    public function set_transient_manager($transient_manager) {
        $this->transient_manager = $transient_manager;
    }
    
    /**
     * Set lazy load manager instance
     */
    public function set_lazy_load_manager($lazy_load_manager) {
        $this->lazy_load_manager = $lazy_load_manager;
    }
    
    /**
     * Set JetEngine query optimizer instance
     */
    public function set_jetengine_query_optimizer($jetengine_query_optimizer) {
        $this->jetengine_query_optimizer = $jetengine_query_optimizer;
    }
    
    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // JetEngine compatibility warning
        add_action('admin_notices', [$this, 'jetengine_compatibility_notice']);
        add_action('wp_ajax_alo_dismiss_jetengine_notice', [$this, 'dismiss_jetengine_notice']);
        
        // AJAX handler for clearing fallback errors
        add_action('wp_ajax_alo_clear_fallback_errors', [$this, 'ajax_clear_fallback_errors']);
        
        // AJAX handler for manual cleanup
        add_action('wp_ajax_alo_force_cleanup', [$this, 'ajax_force_cleanup']);
        
        // AJAX handlers for debug operations
        add_action('wp_ajax_alo_test_lookup', [$this, 'ajax_test_lookup']);
        add_action('wp_ajax_alo_flush_cache', [$this, 'ajax_flush_cache']);
        
        // Hook into the cache TTL filter to use our setting
        add_filter('alo_cache_expiration', [$this, 'get_cache_ttl_setting']);
        
        // Hook into the global override filter to use our setting
        add_filter('alo_enable_global_override', [$this, 'get_global_override_setting']);
        
        // Hook into the JetEngine preloading filter to use our setting
        add_filter('alo_jetengine_preloading_enabled', [$this, 'get_jetengine_preloading_setting']);
    }
    
    /**
     * Add admin menu under Tools
     */
    public function add_admin_menu() {
        add_management_page(
            __('Attachment Optimizer', 'attachment-lookup-optimizer'),
            __('Attachment Optimizer', 'attachment-lookup-optimizer'),
            'manage_options',
            'attachment-lookup-optimizer',
            [$this, 'admin_page']
        );
        
        add_management_page(
            __('Attachment Lookup Stats', 'attachment-lookup-optimizer'),
            __('Attachment Lookup Stats', 'attachment-lookup-optimizer'),
            'manage_options',
            'attachment-lookup-stats',
            [$this, 'stats_page']
        );
        
        add_management_page(
            __('Attachment Lookup Logs', 'attachment-lookup-optimizer'),
            __('Attachment Lookup Logs', 'attachment-lookup-optimizer'),
            'manage_options',
            'attachment-lookup-logs',
            [$this, 'logs_page']
        );
        
        // Add debug page under Tools menu (admin only)
        add_management_page(
            __('Attachment Lookup Debug', 'attachment-lookup-optimizer'),
            __('Attachment Lookup Debug', 'attachment-lookup-optimizer'),
            'administrator',
            'attachment-lookup-debug',
            [$this, 'debug_page']
        );
        
        // Add settings page under Settings menu
        add_options_page(
            __('Attachment Lookup Settings', 'attachment-lookup-optimizer'),
            __('Attachment Lookup', 'attachment-lookup-optimizer'),
            'manage_options',
            'attachment-lookup-settings',
            [$this, 'attachment_lookup_settings_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register the main attachment lookup settings option
        register_setting(
            'attachment_lookup_opts_group',
            'attachment_lookup_opts',
            [
                'type' => 'array',
                'default' => [
                    'optimized_lookup_enabled' => true,
                    'native_fallback_enabled' => true,
                    'log_level' => 'errors_only'
                ],
                'sanitize_callback' => [$this, 'sanitize_attachment_lookup_opts']
            ]
        );
        
        // Add settings sections for the new settings page
        add_settings_section(
            'alo_core_settings',
            __('Core Lookup Settings', 'attachment-lookup-optimizer'),
            [$this, 'core_settings_section_callback'],
            'attachment_lookup_settings'
        );
        
        add_settings_section(
            'alo_fallback_settings',
            __('Fallback & Error Handling', 'attachment-lookup-optimizer'),
            [$this, 'fallback_settings_section_callback'],
            'attachment_lookup_settings'
        );
        
        add_settings_section(
            'alo_logging_settings',
            __('Logging & Debugging', 'attachment-lookup-optimizer'),
            [$this, 'logging_settings_section_callback'],
            'attachment_lookup_settings'
        );
        
        add_settings_section(
            'alo_error_history',
            __('Recent Fallback Errors', 'attachment-lookup-optimizer'),
            [$this, 'error_history_section_callback'],
            'attachment_lookup_settings'
        );
        
        // Add individual settings fields
        add_settings_field(
            'optimized_lookup_enabled',
            __('Enable Optimized Lookup', 'attachment-lookup-optimizer'),
            [$this, 'optimized_lookup_enabled_field'],
            'attachment_lookup_settings',
            'alo_core_settings'
        );
        
        add_settings_field(
            'native_fallback_enabled',
            __('Enable Native Fallback', 'attachment-lookup-optimizer'),
            [$this, 'native_fallback_enabled_field'],
            'attachment_lookup_settings',
            'alo_fallback_settings'
        );
        
        add_settings_field(
            'log_level',
            __('Log Level', 'attachment-lookup-optimizer'),
            [$this, 'log_level_field'],
            'attachment_lookup_settings',
            'alo_logging_settings'
        );
        
        register_setting(
            'alo_settings_group',
            self::CACHE_TTL_OPTION,
            [
                'type' => 'integer',
                'default' => self::DEFAULT_CACHE_TTL,
                'sanitize_callback' => [$this, 'sanitize_cache_ttl']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::DEBUG_LOGGING_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_debug_logging']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::DEBUG_THRESHOLD_OPTION,
            [
                'type' => 'integer',
                'default' => 3,
                'sanitize_callback' => [$this, 'sanitize_debug_threshold']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::GLOBAL_OVERRIDE_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_global_override']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_jetengine_preloading',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_jetengine_preloading']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_lazy_loading',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_lazy_loading']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_lazy_loading_above_fold',
            [
                'type' => 'integer',
                'default' => 3,
                'sanitize_callback' => [$this, 'sanitize_lazy_loading_above_fold']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_jetengine_query_optimization',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_jetengine_query_optimization']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_expensive_query_logging',
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_expensive_query_logging']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_slow_query_threshold',
            [
                'type' => 'number',
                'default' => 0.3,
                'sanitize_callback' => [$this, 'sanitize_slow_query_threshold']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_custom_lookup_table',
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_custom_lookup_table']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_attachment_debug_logs',
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_attachment_debug_logs']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_debug_log_format',
            [
                'type' => 'string',
                'default' => 'json',
                'sanitize_callback' => [$this, 'sanitize_debug_log_format']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_native_fallback_enabled',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_native_fallback_enabled']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_slow_query_threshold_ms',
            [
                'type' => 'integer',
                'default' => 300,
                'sanitize_callback' => [$this, 'sanitize_slow_query_threshold']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            'alo_failure_tracking_enabled',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_failure_tracking_enabled']
            ]
        );
        
        add_settings_section(
            'alo_cache_section',
            __('Cache Settings', 'attachment-lookup-optimizer'),
            [$this, 'cache_section_callback'],
            'alo_settings'
        );
        
        add_settings_field(
            'cache_ttl',
            __('Cache TTL (seconds)', 'attachment-lookup-optimizer'),
            [$this, 'cache_ttl_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'debug_logging',
            __('Debug Logging', 'attachment-lookup-optimizer'),
            [$this, 'debug_logging_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'debug_threshold',
            __('Debug Threshold', 'attachment-lookup-optimizer'),
            [$this, 'debug_threshold_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'global_override',
            __('Global Override', 'attachment-lookup-optimizer'),
            [$this, 'global_override_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'jetengine_preloading',
            __('JetEngine Preloading', 'attachment-lookup-optimizer'),
            [$this, 'jetengine_preloading_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'lazy_loading',
            __('Lazy Loading Images', 'attachment-lookup-optimizer'),
            [$this, 'lazy_loading_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'lazy_loading_above_fold',
            __('Above-the-fold Limit', 'attachment-lookup-optimizer'),
            [$this, 'lazy_loading_above_fold_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'jetengine_query_optimization',
            __('JetEngine Query Optimization', 'attachment-lookup-optimizer'),
            [$this, 'jetengine_query_optimization_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'expensive_query_logging',
            __('Expensive Query Logging', 'attachment-lookup-optimizer'),
            [$this, 'expensive_query_logging_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'slow_query_threshold',
            __('Slow Query Threshold', 'attachment-lookup-optimizer'),
            [$this, 'slow_query_threshold_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'custom_lookup_table',
            __('Custom Lookup Table (Experimental)', 'attachment-lookup-optimizer'),
            [$this, 'custom_lookup_table_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'attachment_debug_logs',
            __('Enable Attachment Lookup Debug Logs', 'attachment-lookup-optimizer'),
            [$this, 'attachment_debug_logs_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'debug_log_format',
            __('Debug Log Format', 'attachment-lookup-optimizer'),
            [$this, 'debug_log_format_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'native_fallback_enabled',
            __('Native WordPress Fallback', 'attachment-lookup-optimizer'),
            [$this, 'native_fallback_enabled_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'slow_query_threshold_ms',
            __('Slow Query Threshold (ms)', 'attachment-lookup-optimizer'),
            [$this, 'slow_query_threshold_ms_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_field(
            'failure_tracking_enabled',
            __('Failure Tracking & Watchlist', 'attachment-lookup-optimizer'),
            [$this, 'failure_tracking_enabled_field_callback'],
            'alo_settings',
            'alo_cache_section'
        );
        
        add_settings_section(
            'alo_database_section',
            __('Database Optimization', 'attachment-lookup-optimizer'),
            [$this, 'database_section_callback'],
            'alo_settings'
        );
        
        add_settings_section(
            'alo_actions_section',
            __('Actions', 'attachment-lookup-optimizer'),
            [$this, 'actions_section_callback'],
            'alo_settings'
        );
    }
    
    /**
     * Sanitize cache TTL input
     */
    public function sanitize_cache_ttl($value) {
        $value = absint($value);
        return max(60, min(86400, $value)); // Between 1 minute and 24 hours
    }
    
    /**
     * Get cache TTL setting for filter
     */
    public function get_cache_ttl_setting($default) {
        return get_option(self::CACHE_TTL_OPTION, $default);
    }
    
    /**
     * Get global override setting for filter
     */
    public function get_global_override_setting($default) {
        return get_option(self::GLOBAL_OVERRIDE_OPTION, $default);
    }
    
    /**
     * Get JetEngine preloading setting for filter
     */
    public function get_jetengine_preloading_setting($default) {
        return get_option('alo_jetengine_preloading', $default);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_attachment-lookup-optimizer' && $hook !== 'tools_page_attachment-lookup-stats') {
            return;
        }
        
        wp_enqueue_style('alo-admin-style', ALO_PLUGIN_URL . 'assets/admin.css', [], ALO_VERSION);
        
        // Enqueue jQuery for AJAX functionality on both pages
        wp_enqueue_script('jquery');
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        // Handle form submissions
        if (isset($_POST['alo_clear_cache']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $this->cache_manager->clear_all_cache();
            echo '<div class="notice notice-success"><p>' . __('Cache cleared successfully!', 'attachment-lookup-optimizer') . '</p></div>';
        }
        
        if (isset($_POST['alo_warm_cache']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $warmed = $this->cache_manager->warm_cache(100);
            echo '<div class="notice notice-success"><p>' . 
                 sprintf(__('Cache warmed for %d attachments!', 'attachment-lookup-optimizer'), $warmed) . 
                 '</p></div>';
        }
        
        if (isset($_POST['alo_test_batch']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $test_results = $this->test_batch_lookup();
            echo '<div class="notice notice-info"><p>' . 
                 sprintf(__('Batch test completed: %d URLs tested, %d found, %d cache hits (%.1f%% hit ratio)', 'attachment-lookup-optimizer'), 
                     $test_results['total_urls'], 
                     $test_results['found_count'],
                     $test_results['cache_hits'],
                     $test_results['hit_ratio']) . 
                 '</p></div>';
        }
        
        if (isset($_POST['alo_bulk_process']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            if ($this->upload_preprocessor) {
                $process_results = $this->upload_preprocessor->bulk_process_existing_attachments(200);
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Bulk processing completed: %d attachments processed', 'attachment-lookup-optimizer'), 
                         $process_results['processed']) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_rebuild_table']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            if ($this->custom_lookup_table) {
                $rebuild_results = $this->custom_lookup_table->rebuild_table();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Custom lookup table rebuilt: %d mappings created', 'attachment-lookup-optimizer'), 
                         $rebuild_results) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_cleanup_transients']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            if ($this->transient_manager) {
                $cleanup_results = $this->transient_manager->force_cleanup();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Transient cleanup completed: %d expired transients removed', 'attachment-lookup-optimizer'), 
                         $cleanup_results['total_cleaned']) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_purge_transients']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            if ($this->transient_manager) {
                $purged_count = $this->transient_manager->purge_all_transients();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('All transients purged: %d transients removed', 'attachment-lookup-optimizer'), 
                         $purged_count) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_clear_query_stats']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $this->database_manager->clear_query_stats();
            echo '<div class="notice notice-success"><p>' . 
                 __('Query statistics cleared successfully!', 'attachment-lookup-optimizer') . 
                 '</p></div>';
        }
        
        if (isset($_POST['alo_test_cache']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $test_results = $this->cache_manager->test_cache_functionality();
            $success = $test_results['test_results']['set_success'] && 
                      $test_results['test_results']['get_success'] && 
                      $test_results['test_results']['delete_success'];
            
            if ($success) {
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Cache test successful! Backend: %s, Set: %.2fms, Get: %.2fms, Delete: %.2fms', 'attachment-lookup-optimizer'), 
                         ucfirst($test_results['cache_backend']),
                         $test_results['performance']['set_time_ms'],
                         $test_results['performance']['get_time_ms'],
                         $test_results['performance']['delete_time_ms']) . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . 
                     __('Cache test failed! Check your cache configuration.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_test_custom_table']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            if ($this->custom_lookup_table && $this->custom_lookup_table->table_exists()) {
                // Get a sample attachment for testing
                global $wpdb;
                $sample_attachment = $wpdb->get_row(
                    "SELECT p.ID, pm.meta_value 
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_type = 'attachment'
                     AND p.post_status = 'inherit'
                     AND pm.meta_key = '_wp_attached_file'
                     AND pm.meta_value != ''
                     LIMIT 1"
                );
                
                if ($sample_attachment) {
                    $test_results = $this->custom_lookup_table->test_lookup($sample_attachment->meta_value);
                    if ($test_results['found']) {
                        echo '<div class="notice notice-success"><p>' . 
                             sprintf(__('Custom table test successful! File: %s, Found Post ID: %d, Lookup time: %.4fms', 'attachment-lookup-optimizer'), 
                                 basename($test_results['file_path']),
                                 $test_results['post_id'],
                                 $test_results['execution_time_ms']) . 
                             '</p></div>';
                    } else {
                        echo '<div class="notice notice-warning"><p>' . 
                             sprintf(__('Custom table test completed but file not found. Lookup time: %.4fms', 'attachment-lookup-optimizer'), 
                                 $test_results['execution_time_ms']) . 
                             '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>' . 
                         __('No attachments found for testing.', 'attachment-lookup-optimizer') . 
                         '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>' . 
                     __('Custom lookup table is not available or enabled.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_clear_debug_logs']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
            if ($debug_logger) {
                $deleted_count = $debug_logger->clear_logs();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Debug logs cleared: %d files deleted', 'attachment-lookup-optimizer'), $deleted_count) . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . 
                     __('Debug logger not available.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_force_cleanup']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
            if ($debug_logger) {
                $debug_logger->force_cleanup();
                echo '<div class="notice notice-success"><p>' . 
                     __('Automated cleanup forced successfully!', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . 
                     __('Debug logger not available.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_clear_archived_logs']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
            if ($debug_logger) {
                $deleted_count = $debug_logger->clear_archived_logs();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Archived logs cleared: %d files deleted', 'attachment-lookup-optimizer'), $deleted_count) . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . 
                     __('Debug logger not available.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_clear_watchlist']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_actions')) {
            if ($this->cache_manager) {
                $this->cache_manager->clear_watchlist();
                echo '<div class="notice notice-success"><p>' . 
                     __('Watchlist cleared successfully!', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . 
                     __('Cache manager not available.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        // Get current statistics
        $db_stats = $this->database_manager->get_stats();
        $cache_stats = $this->cache_manager->get_cache_stats();
        $upload_stats = $this->upload_preprocessor ? $this->upload_preprocessor->get_stats() : null;
        $table_stats = $this->custom_lookup_table ? $this->custom_lookup_table->get_stats() : null;
        $jetengine_stats = $this->jetengine_preloader ? $this->jetengine_preloader->get_stats() : null;
        $transient_stats = $this->transient_manager ? $this->transient_manager->get_stats() : null;
        $lazy_load_stats = $this->lazy_load_manager ? $this->lazy_load_manager->get_stats() : null;
        $query_optimizer_stats = $this->jetengine_query_optimizer ? $this->jetengine_query_optimizer->get_stats() : null;
        $current_ttl = get_option(self::CACHE_TTL_OPTION, self::DEFAULT_CACHE_TTL);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('alo_settings_group');
                do_settings_sections('alo_settings');
                submit_button();
                ?>
            </form>
            
            <div class="alo-stats-grid">
                <div class="alo-stats-card">
                    <h3><?php _e('Database Status', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Main Index (alo_meta_key_value_idx)', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($db_stats['index_exists']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Exists', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('Missing', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Attached File Index (idx_attached_file)', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($db_stats['attached_file_index_exists']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Exists', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('Missing', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Main Index Created', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $db_stats['index_created'] ? 
                                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $db_stats['index_created']) : 
                                __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Attached File Index Created', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $db_stats['attached_file_index_created'] ? 
                                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $db_stats['attached_file_index_created']) : 
                                __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Total Postmeta Records', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo number_format_i18n($db_stats['postmeta_count']); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Attachment Meta Records', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo number_format_i18n($db_stats['attachment_meta_count']); ?></td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Cache Status', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Current TTL', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo sprintf(__('%d seconds (%s)', 'attachment-lookup-optimizer'), 
                                $current_ttl, human_time_diff(0, $current_ttl)); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Method', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <strong><?php echo esc_html($cache_stats['method_description']); ?></strong>
                                <?php if ($cache_stats['method'] === 'object_cache'): ?>
                                    <span class="alo-status-good">✓</span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Backend', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php $backend = $cache_stats['cache_backend'] ?? 'none'; ?>
                                <strong><?php echo esc_html(ucfirst($backend)); ?></strong>
                                <?php if (in_array($backend, ['redis', 'predis', 'memcached'])): ?>
                                    <span class="alo-status-good">🚀</span>
                                <?php elseif ($backend === 'apcu'): ?>
                                    <span class="alo-status-warning">⚡</span>
                                <?php else: ?>
                                    <span class="alo-status-bad">⚠</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Redis Available', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cache_stats['redis_available']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('No', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Object Cache Available', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cache_stats['object_cache_available']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('No', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Persistent Object Cache', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cache_stats['persistent_object_cache']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes (External Cache)', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('No (using transients)', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Group', 'attachment-lookup-optimizer'); ?></td>
                            <td><code><?php echo esc_html($cache_stats['cache_group']); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Key Prefix', 'attachment-lookup-optimizer'); ?></td>
                            <td><code><?php echo esc_html($cache_stats['cache_key_prefix']); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php _e('Default Expiration', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo sprintf(__('%d seconds (%s)', 'attachment-lookup-optimizer'), 
                                $cache_stats['default_expiration'], human_time_diff(0, $cache_stats['default_expiration'])); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Last Cache Clear', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $cache_stats['last_cleared'] ? 
                                human_time_diff($cache_stats['last_cleared']) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                __('Never', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Plugin Version', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo ALO_VERSION; ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Global Override', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php $global_override = get_option(self::GLOBAL_OVERRIDE_OPTION, false); ?>
                                <?php if ($global_override): ?>
                                    <span class="alo-status-good">✓ <?php _e('Enabled (Full Replacement)', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Disabled (Filter Mode)', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Request-Scoped Static Cache', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Static Cache Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <span class="alo-status-good">✓ <?php _e('Active (In-Memory)', 'attachment-lookup-optimizer'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Static Cache Hits (This Request)', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php 
                                $static_stats = $this->cache_manager->get_static_cache_stats();
                                echo number_format_i18n($static_stats['static_cache_hits']);
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Static Hit Ratio (This Request)', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <strong><?php echo sprintf(__('%s%%', 'attachment-lookup-optimizer'), $static_stats['static_hit_ratio']); ?></strong>
                                <?php if ($static_stats['static_hit_ratio'] >= 50): ?>
                                    <span class="alo-status-good">✓ <?php _e('Excellent', 'attachment-lookup-optimizer'); ?></span>
                                <?php elseif ($static_stats['static_hit_ratio'] >= 20): ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Good', 'attachment-lookup-optimizer'); ?></span>
                                <?php elseif ($static_stats['static_hit_ratio'] > 0): ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Some Benefit', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-neutral">— <?php _e('No Duplicates', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Total Calls (This Request)', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo number_format_i18n($static_stats['total_calls']); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Purpose', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php _e('Prevents redundant lookups for same URL within request', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Best For', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php _e('JetEngine listings with repeated images, galleries', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Performance', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <span class="alo-status-good">⚡ <?php _e('Instant (sub-microsecond)', 'attachment-lookup-optimizer'); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Upload Preprocessing', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Total Attachments', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $upload_stats ? number_format_i18n($upload_stats['total_attachments']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Cached Attachments', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $upload_stats ? number_format_i18n($upload_stats['cached_attachments']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('JetFormBuilder Uploads', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $upload_stats ? number_format_i18n($upload_stats['jetformbuilder_uploads']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Coverage Percentage', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($upload_stats): ?>
                                    <strong><?php echo sprintf(__('%s%%', 'attachment-lookup-optimizer'), $upload_stats['coverage_percentage']); ?></strong>
                                    <?php if ($upload_stats['coverage_percentage'] >= 80): ?>
                                        <span class="alo-status-good">✓</span>
                                    <?php elseif ($upload_stats['coverage_percentage'] >= 50): ?>
                                        <span class="alo-status-warning">⚠</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">✗</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Custom Lookup Table', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Table Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($table_stats && $table_stats['table_exists']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Active', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('Not Created', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Total Mappings', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $table_stats ? number_format_i18n($table_stats['total_mappings']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Table Size', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $table_stats ? sprintf(__('%s MB', 'attachment-lookup-optimizer'), $table_stats['table_size_mb']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Performance', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($table_stats && $table_stats['table_exists']): ?>
                                    <span class="alo-status-good">🚀 <?php _e('Ultra-Fast Primary Key Lookup', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Table not available', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Created', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo ($table_stats && $table_stats['created_at']) ? 
                                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $table_stats['created_at']) : 
                                __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('JetEngine Preloading', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Preloading Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php $jetengine_enabled = get_option('alo_jetengine_preloading', true); ?>
                                <?php if ($jetengine_enabled && class_exists('Jet_Engine')): ?>
                                    <span class="alo-status-good">✓ <?php _e('Active', 'attachment-lookup-optimizer'); ?></span>
                                <?php elseif ($jetengine_enabled): ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Enabled (JetEngine not detected)', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('JetEngine Detected', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if (class_exists('Jet_Engine')): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('No', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Requests Processed', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $jetengine_stats ? number_format_i18n($jetengine_stats['requests_processed']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('URLs Preloaded', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $jetengine_stats ? number_format_i18n($jetengine_stats['urls_preloaded']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Attachments Found', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $jetengine_stats ? number_format_i18n($jetengine_stats['urls_found']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Preload Time', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $jetengine_stats ? sprintf(__('%.2f ms', 'attachment-lookup-optimizer'), $jetengine_stats['preload_time']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Transient Management', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Cache Method', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cache_stats['cache_method'] === 'transients'): ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Using Transients', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-good">✓ <?php _e('Using Object Cache', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Current Transients', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $transient_stats ? number_format_i18n($transient_stats['current_transients']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Registry Size', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $transient_stats ? number_format_i18n($transient_stats['registry_size']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Storage Size', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $transient_stats ? sprintf(__('%s MB', 'attachment-lookup-optimizer'), $transient_stats['storage_size_mb']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Transients Created', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $transient_stats ? number_format_i18n($transient_stats['transients_created']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Transients Deleted', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $transient_stats ? number_format_i18n($transient_stats['transients_deleted']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Last Cleanup', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo ($transient_stats && $transient_stats['last_cleanup']) ? 
                                human_time_diff($transient_stats['last_cleanup']) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                __('Never', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Expiration Time', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $transient_stats ? 
                                sprintf(__('%s (%s)', 'attachment-lookup-optimizer'), 
                                    $transient_stats['expiration_seconds'] . ' ' . __('seconds', 'attachment-lookup-optimizer'),
                                    human_time_diff(0, $transient_stats['expiration_seconds'])) : 
                                __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Lazy Loading Images', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Lazy Loading Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php $lazy_enabled = get_option('alo_lazy_loading', true); ?>
                                <?php if ($lazy_enabled): ?>
                                    <span class="alo-status-good">✓ <?php _e('Enabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Images Processed', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $lazy_load_stats ? number_format_i18n($lazy_load_stats['images_processed']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Lazy Applied', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $lazy_load_stats ? number_format_i18n($lazy_load_stats['lazy_applied']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Eager Applied', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $lazy_load_stats ? number_format_i18n($lazy_load_stats['eager_applied']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('JetEngine Images', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $lazy_load_stats ? number_format_i18n($lazy_load_stats['jetengine_images']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Above-the-fold Limit', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $lazy_load_stats ? $lazy_load_stats['above_fold_limit'] . ' ' . __('images', 'attachment-lookup-optimizer') : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Lazy Percentage', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($lazy_load_stats): ?>
                                    <strong><?php echo sprintf(__('%s%%', 'attachment-lookup-optimizer'), $lazy_load_stats['lazy_percentage']); ?></strong>
                                    <?php if ($lazy_load_stats['lazy_percentage'] >= 70): ?>
                                        <span class="alo-status-good">✓</span>
                                    <?php elseif ($lazy_load_stats['lazy_percentage'] >= 40): ?>
                                        <span class="alo-status-warning">⚠</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">✗</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('JetEngine Query Optimization', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Optimization Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php $optimization_enabled = get_option('alo_jetengine_query_optimization', true); ?>
                                <?php if ($optimization_enabled && class_exists('Jet_Engine')): ?>
                                    <span class="alo-status-good">✓ <?php _e('Active', 'attachment-lookup-optimizer'); ?></span>
                                <?php elseif ($optimization_enabled): ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Enabled (JetEngine not detected)', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('JetEngine Detected', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if (class_exists('Jet_Engine')): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('No', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Queries Optimized', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $query_optimizer_stats ? number_format_i18n($query_optimizer_stats['queries_optimized']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Meta Queries Reduced', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $query_optimizer_stats ? number_format_i18n($query_optimizer_stats['meta_queries_reduced']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Fields Optimized', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $query_optimizer_stats ? number_format_i18n($query_optimizer_stats['fields_optimized']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Nested Queries Prevented', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $query_optimizer_stats ? number_format_i18n($query_optimizer_stats['nested_queries_prevented']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Time Saved', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $query_optimizer_stats ? sprintf(__('%.2f ms', 'attachment-lookup-optimizer'), $query_optimizer_stats['query_time_saved']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Average Time Saved', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $query_optimizer_stats ? sprintf(__('%.2f ms/query', 'attachment-lookup-optimizer'), $query_optimizer_stats['avg_time_saved']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Query Performance Monitoring', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Query Monitoring Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php $expensive_logging = get_option('alo_expensive_query_logging', false); ?>
                                <?php if ($expensive_logging): ?>
                                    <span class="alo-status-good">✓ <?php _e('Active', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Slow Query Threshold', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $db_stats ? $db_stats['slow_query_threshold_ms'] . ' ms' : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Total Queries Monitored', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $db_stats ? number_format_i18n($db_stats['total_queries']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Postmeta Queries', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $db_stats ? number_format_i18n($db_stats['postmeta_queries']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Attached File Queries', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $db_stats ? number_format_i18n($db_stats['attached_file_queries']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Slow Queries Detected', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($db_stats): ?>
                                    <strong><?php echo number_format_i18n($db_stats['slow_queries']); ?></strong>
                                    <?php if ($db_stats['slow_queries'] > 0): ?>
                                        <span class="alo-status-warning">⚠ (<?php echo $db_stats['slow_query_percentage']; ?>%)</span>
                                    <?php else: ?>
                                        <span class="alo-status-good">✓</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Average Query Time', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $db_stats ? sprintf(__('%.2f ms', 'attachment-lookup-optimizer'), $db_stats['avg_query_time_ms']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <?php if ($db_stats && !empty($db_stats['slow_query_samples'])): ?>
                        <tr>
                            <td colspan="2">
                                <strong><?php _e('Recent Slow Queries:', 'attachment-lookup-optimizer'); ?></strong>
                                <div style="max-height: 150px; overflow-y: auto; margin-top: 5px;">
                                    <?php foreach (array_slice($db_stats['slow_query_samples'], -5) as $sample): ?>
                                        <div style="font-size: 11px; padding: 2px 0; border-bottom: 1px solid #f0f0f0;">
                                            <strong><?php echo sprintf('%.1fms', $sample['execution_time'] * 1000); ?></strong> - 
                                            <?php echo esc_html($sample['query_type']); ?> 
                                            (<?php echo $sample['index_used'] ? '✓ Index' : '✗ No Index'; ?>) -
                                            <span style="color: #666;"><?php echo date('H:i:s', $sample['timestamp']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Attachment Debug Logging', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Debug Logging Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php $debug_enabled = get_option('alo_attachment_debug_logs', false); ?>
                                <?php if ($debug_enabled): ?>
                                    <span class="alo-status-good">✓ <?php _e('Enabled (File Logging)', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('What Gets Logged', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php _e('Every attachment_url_to_postid() call with timestamp, URL, source, result, query time', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                        <h3><?php _e('Live Lookup Summary', 'attachment-lookup-optimizer'); ?></h3>
                        <table class="widefat">
                            <tr>
                                <td><?php _e('Total Lookups', 'attachment-lookup-optimizer'); ?></td>
                                <td><strong><?php echo number_format_i18n($stats['total_lookups']); ?></strong></td>
                            </tr>
                            <tr>
                                <td><?php _e('Successful Lookups', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['successful_lookups']); ?></strong>
                                    (<?php echo $stats['success_rate']; ?>%)
                                    <?php if ($stats['success_rate'] >= 80): ?>
                                        <span class="alo-status-good">✓</span>
                                    <?php elseif ($stats['success_rate'] >= 60): ?>
                                        <span class="alo-status-warning">⚠</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">✗</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Not Found Count', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['not_found_count']); ?></strong>
                                    (<?php echo round(($stats['not_found_count'] / $stats['total_lookups']) * 100, 1); ?>%)
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Cache Efficiency', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo $stats['cache_efficiency']; ?>%</strong>
                                    <?php if ($stats['cache_efficiency'] >= 70): ?>
                                        <span class="alo-status-good">🚀 Excellent</span>
                                    <?php elseif ($stats['cache_efficiency'] >= 40): ?>
                                        <span class="alo-status-warning">⚡ Good</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">⚠ Needs Improvement</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Table Efficiency', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo $stats['table_efficiency']; ?>%</strong>
                                    <?php if ($stats['table_efficiency'] > 0): ?>
                                        <span class="alo-status-good">✓ Active</span>
                                    <?php else: ?>
                                        <span class="alo-status-neutral">— Not Used</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Tracking Started', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $stats['first_lookup_time'] ? 
                                    human_time_diff(strtotime($stats['first_lookup_time'])) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                    __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Last Activity', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $stats['last_lookup_time'] ? 
                                    human_time_diff(strtotime($stats['last_lookup_time'])) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                    __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="alo-stats-card">
                        <h3><?php _e('Recent Activity', 'attachment-lookup-optimizer'); ?></h3>
                        <table class="widefat">
                            <tr>
                                <td><?php _e('First Log Entry', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $stats['first_entry_time'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stats['first_entry_time'])) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Latest Log Entry', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $stats['last_entry_time'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stats['last_entry_time'])) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Total Log Size', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $this->format_bytes($stats['total_log_size']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Clear Stats Action -->
                <div class="alo-actions-section">
                    <h3><?php _e('Log Management', 'attachment-lookup-optimizer'); ?></h3>
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('alo_clear_stats', 'alo_nonce'); ?>
                        <input type="submit" name="alo_clear_stats" class="button button-secondary" 
                               value="<?php _e('Clear Stats Logs', 'attachment-lookup-optimizer'); ?>"
                               onclick="return confirm('<?php _e('Are you sure? This will delete all debug log files and cannot be undone.', 'attachment-lookup-optimizer'); ?>');">
                        <p class="description">
                            <?php _e('This will delete all debug log files and reset statistics. Debug logging must be enabled to collect new data.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    </form>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Automated Log Cleanup', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Cleanup Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <span class="alo-status-good">✓ <?php _e('Active (Every 6 hours)', 'attachment-lookup-optimizer'); ?></span>
                            </td>
                        </tr>
                        <?php 
                        $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
                        $cleanup_stats = $debug_logger ? $debug_logger->get_cleanup_stats() : null;
                        ?>
                        <tr>
                            <td><?php _e('Compression After', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $cleanup_stats ? sprintf(__('%d days', 'attachment-lookup-optimizer'), $cleanup_stats['compress_after_days']) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Deletion After', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $cleanup_stats ? sprintf(__('%d days', 'attachment-lookup-optimizer'), $cleanup_stats['delete_after_days']) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Last Cleanup', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cleanup_stats && $cleanup_stats['last_cleanup_time']): ?>
                                    <?php echo human_time_diff($cleanup_stats['last_cleanup_time']) . ' ' . __('ago', 'attachment-lookup-optimizer'); ?>
                                <?php else: ?>
                                    <?php _e('Never run', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Next Cleanup', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cleanup_stats && $cleanup_stats['next_cleanup_time']): ?>
                                    <?php if ($cleanup_stats['next_cleanup_time'] > time()): ?>
                                        <?php echo human_time_diff($cleanup_stats['next_cleanup_time']) . ' ' . __('from now', 'attachment-lookup-optimizer'); ?>
                                    <?php else: ?>
                                        <span class="alo-status-good"><?php _e('Ready to run', 'attachment-lookup-optimizer'); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="alo-status-good"><?php _e('Ready to run', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Archived Files', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cleanup_stats): ?>
                                    <strong><?php echo number_format_i18n($cleanup_stats['total_archived_files']); ?></strong>
                                    <?php if ($cleanup_stats['total_archived_size'] > 0): ?>
                                        (<?php echo $this->format_bytes($cleanup_stats['total_archived_size']); ?>)
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Archive Directory', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cleanup_stats && $cleanup_stats['archive_directory']): ?>
                                    <code><?php echo esc_html(basename(dirname($cleanup_stats['archive_directory'])) . '/archived-logs'); ?></code>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="alo-stats-card">
                    <h3><?php _e('Watchdog & Fallback System', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <?php 
                        $fallback_stats = $this->cache_manager ? $this->cache_manager->get_fallback_stats() : null;
                        ?>
                        <tr>
                            <td><?php _e('Native Fallback Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($fallback_stats && $fallback_stats['native_fallback_enabled']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Enabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Slow Query Threshold', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $fallback_stats ? $fallback_stats['slow_query_threshold'] . ' ms' : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Fallbacks (Last Hour)', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($fallback_stats): ?>
                                    <strong><?php echo number_format_i18n($fallback_stats['fallbacks_last_hour']); ?></strong>
                                    <?php if ($fallback_stats['fallbacks_last_hour'] >= $fallback_stats['admin_notice_threshold']): ?>
                                        <span class="alo-status-bad">⚠ <?php _e('High', 'attachment-lookup-optimizer'); ?></span>
                                    <?php elseif ($fallback_stats['fallbacks_last_hour'] > 0): ?>
                                        <span class="alo-status-warning">⚠ <?php _e('Some', 'attachment-lookup-optimizer'); ?></span>
                                    <?php else: ?>
                                        <span class="alo-status-good">✓ <?php _e('None', 'attachment-lookup-optimizer'); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Watchlist Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($fallback_stats && $fallback_stats['failure_tracking_enabled']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Active', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Files on Watchlist', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($fallback_stats): ?>
                                    <strong><?php echo number_format_i18n($fallback_stats['watchlist_count']); ?></strong>
                                    <?php if ($fallback_stats['watchlist_count'] > 0): ?>
                                        <span class="alo-status-warning">⚠ <?php _e('Needs attention', 'attachment-lookup-optimizer'); ?></span>
                                    <?php else: ?>
                                        <span class="alo-status-good">✓ <?php _e('Clean', 'attachment-lookup-optimizer'); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Alert Threshold', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $fallback_stats ? sprintf(__('%d fallbacks/hour', 'attachment-lookup-optimizer'), $fallback_stats['admin_notice_threshold']) : __('Not available', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Flat Log File', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <code>/wp-content/debug-attachment-fallback.log</code>
                                <?php
                                $log_file = WP_CONTENT_DIR . '/debug-attachment-fallback.log';
                                if (file_exists($log_file)): ?>
                                    <br><small><?php echo sprintf(__('Size: %s', 'attachment-lookup-optimizer'), size_format(filesize($log_file))); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($fallback_stats && !empty($fallback_stats['watchlist_items'])): ?>
                        <tr>
                            <td colspan="2">
                                <strong><?php _e('Current Watchlist:', 'attachment-lookup-optimizer'); ?></strong>
                                <div style="max-height: 150px; overflow-y: auto; margin-top: 5px;">
                                    <?php foreach (array_slice($fallback_stats['watchlist_items'], 0, 10) as $url => $data): ?>
                                        <div style="font-size: 11px; padding: 2px 0; border-bottom: 1px solid #f0f0f0;">
                                            <strong><?php echo $data['failures']; ?> failures</strong> - 
                                            <?php echo esc_html(basename($url)); ?>
                                            <span style="color: #666;">(<?php echo human_time_diff($data['last_failure']); ?> ago)</span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($fallback_stats['watchlist_items']) > 10): ?>
                                        <div style="font-size: 11px; padding: 2px 0; color: #666;">
                                            <?php printf(__('... and %d more items', 'attachment-lookup-optimizer'), count($fallback_stats['watchlist_items']) - 10); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <!-- Clear Watchlist Action -->
                    <?php if ($fallback_stats && $fallback_stats['watchlist_count'] > 0): ?>
                    <div style="margin-top: 15px;">
                        <form method="post" style="display: inline-block;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce'); ?>
                            <input type="submit" name="alo_clear_watchlist" class="button button-secondary" 
                                   value="<?php _e('Clear Watchlist', 'attachment-lookup-optimizer'); ?>"
                                   onclick="return confirm('<?php _e('Are you sure? This will clear all files from the watchlist.', 'attachment-lookup-optimizer'); ?>');">
                            <p class="description">
                                <?php _e('Remove all files from the watchlist. Failed lookups will start tracking again from zero.', 'attachment-lookup-optimizer'); ?>
                            </p>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Parse debug logs and generate statistics
     */
    private function parse_debug_logs($log_dir) {
        $stats = [
            'total_entries' => 0,
            'successful_lookups' => 0,
            'average_time_ms' => 0,
            'fastest_time_ms' => PHP_FLOAT_MAX,
            'slowest_time_ms' => 0,
            'files_analyzed' => 0,
            'lookup_sources' => [],
            'top_urls' => [],
            'performance_distribution' => [
                'ultra_fast' => 0, // < 1ms
                'fast' => 0,       // 1-10ms
                'moderate' => 0,   // 10-50ms
                'slow' => 0        // > 50ms
            ],
            'first_entry_time' => null,
            'last_entry_time' => null,
            'total_log_size' => 0
        ];
        
        if (!is_dir($log_dir)) {
            return $stats;
        }
        
        // Get all log files
        $log_files = glob($log_dir . '/attachment-lookup-*.log');
        if (empty($log_files)) {
            return $stats;
        }
        
        $stats['files_analyzed'] = count($log_files);
        $total_time = 0;
        $url_counts = [];
        $url_times = [];
        
        foreach ($log_files as $log_file) {
            $stats['total_log_size'] += filesize($log_file);
            
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                continue;
            }
            
            foreach ($lines as $line) {
                $entry = $this->parse_log_entry($line);
                if (!$entry) {
                    continue;
                }
                
                $stats['total_entries']++;
                
                // Track successful lookups
                if ($entry['found']) {
                    $stats['successful_lookups']++;
                }
                
                // Track lookup sources
                $source = $entry['lookup_source'];
                if (!isset($stats['lookup_sources'][$source])) {
                    $stats['lookup_sources'][$source] = 0;
                }
                $stats['lookup_sources'][$source]++;
                
                // Track timing
                $time_ms = $entry['query_time_ms'];
                $total_time += $time_ms;
                
                if ($time_ms < $stats['fastest_time_ms']) {
                    $stats['fastest_time_ms'] = $time_ms;
                }
                if ($time_ms > $stats['slowest_time_ms']) {
                    $stats['slowest_time_ms'] = $time_ms;
                }
                
                // Performance distribution
                if ($time_ms < 1) {
                    $stats['performance_distribution']['ultra_fast']++;
                } elseif ($time_ms < 10) {
                    $stats['performance_distribution']['fast']++;
                } elseif ($time_ms < 50) {
                    $stats['performance_distribution']['moderate']++;
                } else {
                    $stats['performance_distribution']['slow']++;
                }
                
                // Track URLs
                $url = $entry['url'];
                if (!isset($url_counts[$url])) {
                    $url_counts[$url] = 0;
                    $url_times[$url] = [];
                }
                $url_counts[$url]++;
                $url_times[$url][] = $time_ms;
                
                // Track first/last entry times
                $timestamp = $entry['timestamp'];
                if (!$stats['first_entry_time'] || $timestamp < $stats['first_entry_time']) {
                    $stats['first_entry_time'] = $timestamp;
                }
                if (!$stats['last_entry_time'] || $timestamp > $stats['last_entry_time']) {
                    $stats['last_entry_time'] = $timestamp;
                }
            }
        }
        
        // Calculate averages
        if ($stats['total_entries'] > 0) {
            $stats['average_time_ms'] = $total_time / $stats['total_entries'];
        }
        
        if ($stats['fastest_time_ms'] === PHP_FLOAT_MAX) {
            $stats['fastest_time_ms'] = 0;
        }
        
        // Sort lookup sources by count
        arsort($stats['lookup_sources']);
        
        // Prepare top URLs with average times
        foreach ($url_counts as $url => $count) {
            $avg_time = array_sum($url_times[$url]) / count($url_times[$url]);
            $stats['top_urls'][] = [
                'url' => $url,
                'count' => $count,
                'avg_time_ms' => $avg_time
            ];
        }
        
        // Sort top URLs by count
        usort($stats['top_urls'], function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return $stats;
    }
    
    /**
     * Parse a single log entry
     */
    private function parse_log_entry($line) {
        // Try JSON format first
        $json_data = json_decode($line, true);
        if ($json_data && isset($json_data['url'], $json_data['lookup_source'], $json_data['query_time_ms'])) {
            return [
                'timestamp' => $json_data['timestamp'] ?? '',
                'url' => $json_data['url'],
                'lookup_source' => $json_data['lookup_source'],
                'found' => $json_data['found'] ?? false,
                'query_time_ms' => (float) $json_data['query_time_ms']
            ];
        }
        
        // Try plain text format
        if (preg_match('/\[(.*?)\].*?URL: (.*?) \| Source: (.*?) \| Result: (.*?) \| Time: ([\d.]+)ms/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'url' => $matches[2],
                'lookup_source' => $matches[3],
                'found' => strpos($matches[4], 'NOT FOUND') === false,
                'query_time_ms' => (float) $matches[5]
            ];
        }
        
        return null;
    }
    
    /**
     * Format lookup source name for display
     */
    private function format_lookup_source_name($source) {
        $names = [
            'static_cache_hit' => __('Static Cache Hit', 'attachment-lookup-optimizer'),
            'cache_hit' => __('Cache Hit (Redis/Transients)', 'attachment-lookup-optimizer'),
            'table_lookup' => __('Custom Table Lookup', 'attachment-lookup-optimizer'),
            'fast_lookup' => __('Fast Lookup (Preprocessor)', 'attachment-lookup-optimizer'),
            'sql_lookup' => __('SQL Lookup (Database)', 'attachment-lookup-optimizer'),
            'legacy_fallback' => __('Legacy Fallback', 'attachment-lookup-optimizer'),
            'pre_existing' => __('Pre-existing Result', 'attachment-lookup-optimizer'),
            'invalid_url' => __('Invalid URL', 'attachment-lookup-optimizer')
        ];
        
        return $names[$source] ?? ucwords(str_replace('_', ' ', $source));
    }
    
    /**
     * Get indicator emoji for lookup source
     */
    private function get_source_indicator($source) {
        $indicators = [
            'static_cache_hit' => '⚡',
            'cache_hit' => '💾',
            'table_lookup' => '🚀',
            'fast_lookup' => '⚡',
            'sql_lookup' => '🔍',
            'legacy_fallback' => '🔄',
            'pre_existing' => '✅',
            'invalid_url' => '❌'
        ];
        
        return $indicators[$source] ?? '📊';
    }
    
    /**
     * Truncate URL for display
     */
    private function truncate_url($url, $max_length = 80) {
        if (strlen($url) <= $max_length) {
            return $url;
        }
        
        // Try to keep the filename visible
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $filename = basename($path);
        
        if (strlen($filename) < $max_length - 10) {
            $start_length = $max_length - strlen($filename) - 6;
            return substr($url, 0, $start_length) . '...' . $filename;
        }
        
        return substr($url, 0, $max_length - 3) . '...';
    }
    
    /**
     * Format bytes for human-readable file sizes
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        } elseif ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        } else {
            return $bytes . ' B';
        }
    }
    
    /**
     * Clear debug logs (helper method for stats page)
     */
    private function clear_debug_logs() {
        $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
        if ($debug_logger) {
            return $debug_logger->clear_logs();
        }
        
        // Fallback: manual deletion
        $log_dir = $this->get_debug_log_directory();
        if (!is_dir($log_dir)) {
            return 0;
        }
        
        $pattern = $log_dir . '/attachment-lookup-*.log';
        $files = glob($pattern);
        
        $deleted = 0;
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Get empty live statistics structure
     */
    private function get_empty_live_stats() {
        return [
            'total_lookups' => 0,
            'successful_lookups' => 0,
            'static_cache_hits' => 0,
            'cache_hits' => 0,
            'table_lookup_hits' => 0,
            'fast_lookup_hits' => 0,
            'sql_lookup_hits' => 0,
            'legacy_fallback_hits' => 0,
            'pre_existing_hits' => 0,
            'invalid_url_hits' => 0,
            'not_found_count' => 0,
            'first_lookup_time' => null,
            'last_lookup_time' => null,
            'last_reset_time' => null,
            'success_rate' => 0,
            'cache_efficiency' => 0,
            'table_efficiency' => 0
        ];
    }
    
    /**
     * Stats page content
     */
    public function stats_page() {
        // Handle clear stats action
        if (isset($_POST['alo_clear_stats']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_clear_stats')) {
            $deleted_count = $this->clear_debug_logs();
            echo '<div class="notice notice-success"><p>' . 
                 sprintf(__('%d log files cleared successfully!', 'attachment-lookup-optimizer'), $deleted_count) . 
                 '</p></div>';
        }
        
        // Handle reset live stats action
        if (isset($_POST['alo_reset_live_stats']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_reset_live_stats')) {
            if ($this->cache_manager) {
                $this->cache_manager->reset_live_stats();
                echo '<div class="notice notice-success"><p>' . 
                     __('Live statistics reset successfully!', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        // Handle manual cleanup action
        if (isset($_POST['alo_manual_cleanup']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_manual_cleanup')) {
            if (!current_user_can('manage_options')) {
                echo '<div class="notice notice-error"><p>' . 
                     __('Insufficient permissions to run cleanup.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            } else {
                $cleanup_results = $this->run_manual_log_cleanup();
                if ($cleanup_results) {
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('Manual cleanup completed: %d files processed, %d deleted, %d compressed', 'attachment-lookup-optimizer'), 
                             $cleanup_results['total_processed'], 
                             $cleanup_results['deleted_count'], 
                             $cleanup_results['compressed_count']) . 
                         '</p></div>';
                    
                    if ($cleanup_results['error_count'] > 0) {
                        echo '<div class="notice notice-warning"><p>' . 
                             sprintf(__('Warning: %d errors occurred during cleanup', 'attachment-lookup-optimizer'), 
                                 $cleanup_results['error_count']) . 
                             '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>' . 
                         __('Manual cleanup failed or no files to process.', 'attachment-lookup-optimizer') . 
                         '</p></div>';
                }
            }
        }
        
        // Get live statistics instead of parsing logs
        $stats = $this->cache_manager ? $this->cache_manager->get_live_stats() : $this->get_empty_live_stats();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Attachment Lookup Statistics', 'attachment-lookup-optimizer'); ?></h1>
            
            <?php if ($stats['total_lookups'] === 0): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('No statistics available', 'attachment-lookup-optimizer'); ?></strong>
                    </p>
                    <p>
                        <?php _e('No attachment lookups have been performed yet. Statistics will appear here once attachment_url_to_postid() is called.', 'attachment-lookup-optimizer'); ?>
                    </p>
                    <p>
                        <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>" class="button button-primary">
                            <?php _e('Go to Settings', 'attachment-lookup-optimizer'); ?>
                        </a>
                    </p>
                </div>
            <?php else: ?>
                
                <div class="alo-stats-grid">
                    <!-- Live Lookup Summary -->
                    <div class="alo-stats-card">
                        <h3><?php _e('Live Lookup Summary', 'attachment-lookup-optimizer'); ?></h3>
                        <table class="widefat">
                            <tr>
                                <td><?php _e('Total Lookups', 'attachment-lookup-optimizer'); ?></td>
                                <td><strong><?php echo number_format_i18n($stats['total_lookups']); ?></strong></td>
                            </tr>
                            <tr>
                                <td><?php _e('Successful Lookups', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['successful_lookups']); ?></strong>
                                    (<?php echo $stats['success_rate']; ?>%)
                                    <?php if ($stats['success_rate'] >= 80): ?>
                                        <span class="alo-status-good">✓</span>
                                    <?php elseif ($stats['success_rate'] >= 60): ?>
                                        <span class="alo-status-warning">⚠</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">✗</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Not Found Count', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['not_found_count']); ?></strong>
                                    (<?php echo round(($stats['not_found_count'] / $stats['total_lookups']) * 100, 1); ?>%)
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Cache Efficiency', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo $stats['cache_efficiency']; ?>%</strong>
                                    <?php if ($stats['cache_efficiency'] >= 70): ?>
                                        <span class="alo-status-good">🚀 Excellent</span>
                                    <?php elseif ($stats['cache_efficiency'] >= 40): ?>
                                        <span class="alo-status-warning">⚡ Good</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">⚠ Needs Improvement</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Tracking Started', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $stats['first_lookup_time'] ? 
                                    human_time_diff(strtotime($stats['first_lookup_time'])) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                    __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Last Activity', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $stats['last_lookup_time'] ? 
                                    human_time_diff(strtotime($stats['last_lookup_time'])) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                    __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Live Lookup Sources -->
                    <div class="alo-stats-card">
                        <h3><?php _e('Live Lookup Sources', 'attachment-lookup-optimizer'); ?></h3>
                        <table class="widefat">
                            <tr>
                                <td><?php _e('Static Cache Hits', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['static_cache_hits']); ?></strong>
                                    <span class="alo-status-good">⚡</span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Cache Hits (Redis/Transients)', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['cache_hits']); ?></strong>
                                    <span class="alo-status-good">💾</span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Custom Table Lookups', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['table_lookup_hits']); ?></strong>
                                    <span class="alo-status-good">🚀</span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Fast Lookups (Preprocessor)', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['fast_lookup_hits']); ?></strong>
                                    <span class="alo-status-warning">⚡</span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('SQL Lookups (Database)', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['sql_lookup_hits']); ?></strong>
                                    <span class="alo-status-warning">🔍</span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Legacy Fallback', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['legacy_fallback_hits']); ?></strong>
                                    <span class="alo-status-bad">🔄</span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Pre-existing Results', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['pre_existing_hits']); ?></strong>
                                    <span class="alo-status-good">✅</span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Statistics Management -->
                <div class="alo-actions-section">
                    <h3><?php _e('Statistics Management', 'attachment-lookup-optimizer'); ?></h3>
                    
                    <form method="post" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field('alo_reset_live_stats', 'alo_nonce'); ?>
                        <input type="submit" name="alo_reset_live_stats" class="button button-secondary" 
                               value="<?php _e('Reset Live Statistics', 'attachment-lookup-optimizer'); ?>"
                               onclick="return confirm('<?php _e('Are you sure you want to reset all live statistics? This cannot be undone.', 'attachment-lookup-optimizer'); ?>');">
                    </form>
                    
                    <form method="post" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field('alo_clear_stats', 'alo_nonce'); ?>
                        <input type="submit" name="alo_clear_stats" class="button button-secondary" 
                               value="<?php _e('Clear Debug Log Files', 'attachment-lookup-optimizer'); ?>"
                               onclick="return confirm('<?php _e('Are you sure? This will delete all debug log files and cannot be undone.', 'attachment-lookup-optimizer'); ?>');">
                    </form>
                    
                    <?php if (current_user_can('manage_options')): ?>
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('alo_manual_cleanup', 'alo_nonce'); ?>
                        <input type="submit" name="alo_manual_cleanup" class="button button-primary" 
                               value="<?php _e('Run Cleanup Now', 'attachment-lookup-optimizer'); ?>"
                               onclick="return confirm('<?php _e('This will delete all log files older than 7 days and compress files older than 3 days. Continue?', 'attachment-lookup-optimizer'); ?>');">
                    </form>
                    <?php endif; ?>
                    
                    <p class="description" style="margin-top: 10px;">
                        <?php _e('Live statistics are tracked in real-time. Debug logs must be enabled to collect file-based data.', 'attachment-lookup-optimizer'); ?>
                        <br>
                        <?php _e('Manual cleanup removes logs older than 7 days and compresses logs older than 3 days.', 'attachment-lookup-optimizer'); ?>
                    </p>
                </div>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Run manual log cleanup
     */
    private function run_manual_log_cleanup() {
        $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
        if (!$debug_logger) {
            return false;
        }
        
        // Use the DebugLogger's static cleanup method directly
        // This will process files according to the 7-day deletion and 3-day compression rules
        $upload_dir = wp_upload_dir();
        $log_directory = $upload_dir['basedir'] . '/attachment-lookup-logs';
        
        if (!is_dir($log_directory)) {
            return false;
        }
        
        // Initialize WordPress filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        $filesystem_credentials = request_filesystem_credentials('', '', false, false, array());
        if (!WP_Filesystem($filesystem_credentials)) {
            return false;
        }
        
        global $wp_filesystem;
        
        try {
            // Use reflection to call the private cleanup method
            $reflection = new ReflectionClass('AttachmentLookupOptimizer\DebugLogger');
            $cleanup_method = $reflection->getMethod('cleanup_logs_with_filesystem');
            $cleanup_method->setAccessible(true);
            
            $results = $cleanup_method->invoke(null, $wp_filesystem, $log_directory);
            
            return $results;
        } catch (Exception $e) {
            error_log('ALO Manual Cleanup: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Logs page content
     */
    public function logs_page() {
        // Handle CSV export
        if (isset($_POST['alo_export_csv']) && wp_verify_nonce($_POST['alo_nonce'], 'alo_export_csv')) {
            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions to export logs.', 'attachment-lookup-optimizer'));
            }
            
            $start_date = sanitize_text_field($_POST['start_date'] ?? '');
            $end_date = sanitize_text_field($_POST['end_date'] ?? '');
            
            $this->export_logs_csv($start_date, $end_date);
            return; // Exit after CSV download
        }
        
        // Get filter parameters
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        
        // Default to last 30 days if no dates specified
        if (empty($start_date)) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (empty($end_date)) {
            $end_date = date('Y-m-d');
        }
        
        // Get log entries
        $log_entries = $this->get_log_entries($start_date, $end_date, 100);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Attachment Lookup Logs', 'attachment-lookup-optimizer'); ?></h1>
            
            <!-- Date Range Filter -->
            <div class="alo-filters-section" style="background: #fff; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4;">
                <h3><?php _e('Filter & Export', 'attachment-lookup-optimizer'); ?></h3>
                
                <form method="get" style="margin-bottom: 15px;">
                    <input type="hidden" name="page" value="attachment-lookup-logs">
                    
                    <label for="start_date"><?php _e('Start Date:', 'attachment-lookup-optimizer'); ?></label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>" style="margin-right: 10px;">
                    
                    <label for="end_date"><?php _e('End Date:', 'attachment-lookup-optimizer'); ?></label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>" style="margin-right: 15px;">
                    
                    <input type="submit" class="button button-secondary" value="<?php _e('Filter Logs', 'attachment-lookup-optimizer'); ?>">
                </form>
                
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('alo_export_csv', 'alo_nonce'); ?>
                    <input type="hidden" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                    <input type="hidden" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                    <input type="submit" name="alo_export_csv" class="button button-primary" 
                           value="<?php _e('Export CSV', 'attachment-lookup-optimizer'); ?>">
                    <span class="description" style="margin-left: 10px;">
                        <?php printf(__('Export logs from %s to %s', 'attachment-lookup-optimizer'), 
                            date_i18n(get_option('date_format'), strtotime($start_date)), 
                            date_i18n(get_option('date_format'), strtotime($end_date))); ?>
                    </span>
                </form>
            </div>
            
            <?php if (empty($log_entries)): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('No log entries found', 'attachment-lookup-optimizer'); ?></strong>
                    </p>
                    <p>
                        <?php _e('No attachment lookup logs found for the selected date range. Make sure debug logging is enabled in the plugin settings.', 'attachment-lookup-optimizer'); ?>
                    </p>
                    <p>
                        <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>" class="button button-primary">
                            <?php _e('Go to Plugin Settings', 'attachment-lookup-optimizer'); ?>
                        </a>
                    </p>
                </div>
            <?php else: ?>
                
                <!-- Logs Summary -->
                <div class="alo-logs-summary" style="background: #fff; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4;">
                    <h3><?php _e('Logs Summary', 'attachment-lookup-optimizer'); ?></h3>
                    <p>
                        <?php printf(__('<strong>%d</strong> log entries found between <strong>%s</strong> and <strong>%s</strong>', 'attachment-lookup-optimizer'), 
                            count($log_entries),
                            date_i18n(get_option('date_format'), strtotime($start_date)), 
                            date_i18n(get_option('date_format'), strtotime($end_date))); ?>
                    </p>
                    <?php if (count($log_entries) >= 100): ?>
                        <p class="description">
                            <?php _e('Showing most recent 100 entries. Use CSV export to get all entries for the date range.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <!-- Logs Table -->
                <div class="alo-logs-table">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 15%;"><?php _e('Date', 'attachment-lookup-optimizer'); ?></th>
                                <th scope="col" style="width: 40%;"><?php _e('Lookup Input', 'attachment-lookup-optimizer'); ?></th>
                                <th scope="col" style="width: 10%;"><?php _e('Found ID', 'attachment-lookup-optimizer'); ?></th>
                                <th scope="col" style="width: 10%;"><?php _e('Query Time', 'attachment-lookup-optimizer'); ?></th>
                                <th scope="col" style="width: 15%;"><?php _e('Lookup Source', 'attachment-lookup-optimizer'); ?></th>
                                <th scope="col" style="width: 10%;"><?php _e('Status', 'attachment-lookup-optimizer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log_entries as $entry): ?>
                                <tr>
                                    <td>
                                        <div title="<?php echo esc_attr($entry['timestamp']); ?>">
                                            <?php echo esc_html(date_i18n('M j, Y H:i', strtotime($entry['timestamp']))); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div title="<?php echo esc_attr($entry['url']); ?>">
                                            <code><?php echo esc_html($this->truncate_url($entry['url'], 60)); ?></code>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($entry['result_post_id']): ?>
                                            <strong><?php echo esc_html($entry['result_post_id']); ?></strong>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $this->get_time_css_class($entry['query_time_ms']); ?>">
                                            <?php echo esc_html(sprintf('%.2f ms', $entry['query_time_ms'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="alo-source-badge">
                                            <?php echo $this->get_source_indicator($entry['lookup_source']); ?>
                                            <?php echo esc_html($this->format_lookup_source_short($entry['lookup_source'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($entry['found']): ?>
                                            <span class="alo-status-good">✓ Found</span>
                                        <?php else: ?>
                                            <span class="alo-status-bad">✗ Not Found</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php endif; ?>
        </div>
        
        <style>
        .alo-logs-table {
            background: #fff;
            border: 1px solid #ccd0d4;
        }
        .alo-source-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 3px;
            background: #f0f0f1;
            white-space: nowrap;
        }
        .alo-time-fast { color: #46b450; font-weight: bold; }
        .alo-time-moderate { color: #ffb900; font-weight: bold; }
        .alo-time-slow { color: #dc3232; font-weight: bold; }
        .alo-filters-section h3 { margin-top: 0; }
        .alo-logs-summary h3 { margin-top: 0; }
        </style>
        <?php
    }
    
    /**
     * Export logs as CSV
     */
    private function export_logs_csv($start_date = '', $end_date = '') {
        // Default to last 30 days if no dates specified
        if (empty($start_date)) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (empty($end_date)) {
            $end_date = date('Y-m-d');
        }
        
        // Get all log entries for the date range (no limit for CSV export)
        $log_entries = $this->get_log_entries($start_date, $end_date, 0);
        
        // Generate filename
        $filename = sprintf('attachment-lookup-logs_%s_to_%s.csv', 
            str_replace('-', '', $start_date),
            str_replace('-', '', $end_date)
        );
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        
        // Create file handle for output
        $output = fopen('php://output', 'w');
        
        // Add BOM for proper UTF-8 encoding in Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        // Write CSV headers
        fputcsv($output, [
            'Date',
            'Attachment Path', 
            'Post ID',
            'Query Time (ms)',
            'Lookup Source',
            'Found',
            'Memory Usage (MB)',
            'Request URI',
            'Timestamp'
        ]);
        
        // Write log entries
        foreach ($log_entries as $entry) {
            fputcsv($output, [
                date_i18n('Y-m-d H:i:s', strtotime($entry['timestamp'])),
                $entry['url'],
                $entry['result_post_id'] ? $entry['result_post_id'] : '',
                number_format($entry['query_time_ms'], 4),
                $this->format_lookup_source_name($entry['lookup_source']),
                $entry['found'] ? 'Yes' : 'No',
                isset($entry['memory_usage_mb']) ? $entry['memory_usage_mb'] : '',
                isset($entry['request_uri']) ? $entry['request_uri'] : '',
                $entry['timestamp']
            ]);
        }
        
        fclose($output);
        exit; // Important: exit after CSV output
    }
    
    /**
     * Get log entries from debug logs
     */
    private function get_log_entries($start_date, $end_date, $limit = 100) {
        $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
        if (!$debug_logger || !$debug_logger->is_enabled()) {
            return [];
        }
        
        $log_dir = $this->get_debug_log_directory();
        if (!is_dir($log_dir)) {
            return [];
        }
        
        // Get all log files
        $log_files = glob($log_dir . '/attachment-lookup-*.log');
        if (empty($log_files)) {
            return [];
        }
        
        // Sort by modification time (newest first)
        usort($log_files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $entries = [];
        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');
        
        foreach ($log_files as $log_file) {
            // Skip files that are clearly outside our date range
            $file_date = filemtime($log_file);
            if ($file_date < $start_timestamp - 86400 || $file_date > $end_timestamp + 86400) {
                continue;
            }
            
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                continue;
            }
            
            foreach ($lines as $line) {
                $entry = $this->parse_log_entry($line);
                if (!$entry) {
                    continue;
                }
                
                // Check if entry is within date range
                $entry_timestamp = strtotime($entry['timestamp']);
                if ($entry_timestamp >= $start_timestamp && $entry_timestamp <= $end_timestamp) {
                    $entries[] = $entry;
                    
                    // Stop if we've reached the limit (for table display)
                    if ($limit > 0 && count($entries) >= $limit) {
                        break 2; // Break out of both loops
                    }
                }
            }
        }
        
        // Sort by timestamp (newest first)
        usort($entries, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return $entries;
    }
    
    /**
     * Get CSS class for query time display
     */
    private function get_time_css_class($time_ms) {
        if ($time_ms < 5) {
            return 'alo-time-fast';
        } elseif ($time_ms < 20) {
            return 'alo-time-moderate';
        } else {
            return 'alo-time-slow';
        }
    }
    
    /**
     * Format lookup source name for short display
     */
    private function format_lookup_source_short($source) {
        $short_names = [
            'static_cache_hit' => 'Static',
            'cache_hit' => 'Cache',
            'table_lookup' => 'Table',
            'fast_lookup' => 'Fast',
            'sql_lookup' => 'SQL',
            'legacy_fallback' => 'Legacy',
            'pre_existing' => 'Pre-exist',
            'invalid_url' => 'Invalid'
        ];
        
        return $short_names[$source] ?? ucfirst(str_replace('_', ' ', $source));
    }
    
    /**
     * Cache section description
     */
    public function cache_section_callback() {
        echo '<p>' . __('Configure caching and optimization settings for attachment lookups.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Database section description
     */
    public function database_section_callback() {
        echo '<p>' . __('Database optimization and maintenance tools.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Actions section description
     */
    public function actions_section_callback() {
        echo '<p>' . __('Maintenance and testing actions for the plugin.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Cache TTL field callback
     */
    public function cache_ttl_field_callback() {
        $value = get_option(self::CACHE_TTL_OPTION, self::DEFAULT_CACHE_TTL);
        echo '<input type="number" name="' . self::CACHE_TTL_OPTION . '" value="' . esc_attr($value) . '" min="60" max="86400" />';
        echo '<p class="description">' . __('Cache expiration time in seconds (60-86400).', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Debug logging field callback
     */
    public function debug_logging_field_callback() {
        $value = get_option(self::DEBUG_LOGGING_OPTION, false);
        echo '<input type="checkbox" name="' . self::DEBUG_LOGGING_OPTION . '" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="' . self::DEBUG_LOGGING_OPTION . '">' . __('Enable debug logging', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Log attachment lookup calls for debugging purposes.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Debug threshold field callback
     */
    public function debug_threshold_field_callback() {
        $value = get_option(self::DEBUG_THRESHOLD_OPTION, 3);
        echo '<input type="number" name="' . self::DEBUG_THRESHOLD_OPTION . '" value="' . esc_attr($value) . '" min="1" max="100" />';
        echo '<p class="description">' . __('Minimum number of calls before logging summary.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Global override field callback
     */
    public function global_override_field_callback() {
        $value = get_option(self::GLOBAL_OVERRIDE_OPTION, false);
        echo '<input type="checkbox" name="' . self::GLOBAL_OVERRIDE_OPTION . '" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="' . self::GLOBAL_OVERRIDE_OPTION . '">' . __('Enable global override', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Replace all attachment_url_to_postid() calls globally.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * JetEngine preloading field callback
     */
    public function jetengine_preloading_field_callback() {
        $value = get_option('alo_jetengine_preloading', true);
        echo '<input type="checkbox" name="alo_jetengine_preloading" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_jetengine_preloading">' . __('Enable JetEngine preloading', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Preload attachment URLs found in JetEngine listings.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Lazy loading field callback
     */
    public function lazy_loading_field_callback() {
        $value = get_option('alo_lazy_loading', true);
        echo '<input type="checkbox" name="alo_lazy_loading" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_lazy_loading">' . __('Enable lazy loading for images', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Add loading="lazy" to images below the fold.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Lazy loading above fold field callback
     */
    public function lazy_loading_above_fold_field_callback() {
        $value = get_option('alo_lazy_loading_above_fold', 3);
        echo '<input type="number" name="alo_lazy_loading_above_fold" value="' . esc_attr($value) . '" min="1" max="10" />';
        echo '<p class="description">' . __('Number of images to keep eager (above-the-fold).', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * JetEngine query optimization field callback
     */
    public function jetengine_query_optimization_field_callback() {
        $value = get_option('alo_jetengine_query_optimization', true);
        echo '<input type="checkbox" name="alo_jetengine_query_optimization" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_jetengine_query_optimization">' . __('Enable JetEngine query optimization', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Optimize JetEngine queries for better performance.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Expensive query logging field callback
     */
    public function expensive_query_logging_field_callback() {
        $value = get_option('alo_expensive_query_logging', false);
        echo '<input type="checkbox" name="alo_expensive_query_logging" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_expensive_query_logging">' . __('Enable expensive query logging', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Log slow database queries for performance monitoring.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Slow query threshold field callback
     */
    public function slow_query_threshold_field_callback() {
        $value = get_option('alo_slow_query_threshold', 0.3);
        echo '<input type="number" name="alo_slow_query_threshold" value="' . esc_attr($value) . '" min="0.1" max="5.0" step="0.1" />';
        echo '<p class="description">' . __('Threshold in seconds for slow query detection.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Custom lookup table field callback
     */
    public function custom_lookup_table_field_callback() {
        $value = get_option('alo_custom_lookup_table', false);
        echo '<input type="checkbox" name="alo_custom_lookup_table" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_custom_lookup_table">' . __('Enable custom lookup table', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Use dedicated table for ultra-fast lookups (experimental).', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Attachment debug logs field callback
     */
    public function attachment_debug_logs_field_callback() {
        $value = get_option('alo_attachment_debug_logs', false);
        echo '<input type="checkbox" name="alo_attachment_debug_logs" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_attachment_debug_logs">' . __('Enable attachment lookup debug logs', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Log all attachment_url_to_postid() calls to files.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Debug log format field callback
     */
    public function debug_log_format_field_callback() {
        $value = get_option('alo_debug_log_format', 'json');
        echo '<select name="alo_debug_log_format">';
        echo '<option value="json" ' . selected('json', $value, false) . '>JSON</option>';
        echo '<option value="text" ' . selected('text', $value, false) . '>Plain Text</option>';
        echo '</select>';
        echo '<p class="description">' . __('Format for debug log entries.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Native fallback enabled field callback
     */
    public function native_fallback_enabled_field_callback() {
        $value = get_option('alo_native_fallback_enabled', true);
        echo '<input type="checkbox" name="alo_native_fallback_enabled" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_native_fallback_enabled">' . __('Enable native WordPress fallback', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Use native attachment_url_to_postid() as fallback when optimized lookup fails.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Slow query threshold (ms) field callback
     */
    public function slow_query_threshold_ms_field_callback() {
        $value = get_option('alo_slow_query_threshold_ms', 300);
        echo '<input type="number" name="alo_slow_query_threshold_ms" value="' . esc_attr($value) . '" min="100" max="5000" step="50" />';
        echo '<p class="description">' . __('Threshold in milliseconds for slow query warnings.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Failure tracking enabled field callback
     */
    public function failure_tracking_enabled_field_callback() {
        $value = get_option('alo_failure_tracking_enabled', true);
        echo '<input type="checkbox" name="alo_failure_tracking_enabled" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="alo_failure_tracking_enabled">' . __('Enable failure tracking and watchlist', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Track failed lookups and maintain a watchlist of problematic files.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Sanitize methods
     */
    public function sanitize_debug_logging($value) {
        return (bool) $value;
    }
    
    public function sanitize_debug_threshold($value) {
        return max(1, min(100, intval($value)));
    }
    
    public function sanitize_global_override($value) {
        return (bool) $value;
    }
    
    public function sanitize_jetengine_preloading($value) {
        return (bool) $value;
    }
    
    public function sanitize_lazy_loading($value) {
        return (bool) $value;
    }
    
    public function sanitize_lazy_loading_above_fold($value) {
        return max(1, min(10, intval($value)));
    }
    
    public function sanitize_jetengine_query_optimization($value) {
        return (bool) $value;
    }
    
    public function sanitize_expensive_query_logging($value) {
        return (bool) $value;
    }
    
    public function sanitize_slow_query_threshold($value) {
        return max(0.1, min(5.0, floatval($value)));
    }
    
    public function sanitize_custom_lookup_table($value) {
        return (bool) $value;
    }
    
    public function sanitize_attachment_debug_logs($value) {
        return (bool) $value;
    }
    
    public function sanitize_debug_log_format($value) {
        return in_array($value, ['json', 'text']) ? $value : 'json';
    }
    
    public function sanitize_native_fallback_enabled($value) {
        return (bool) $value;
    }
    
    public function sanitize_slow_query_threshold_ms($value) {
        return max(100, min(5000, intval($value)));
    }
    
    public function sanitize_failure_tracking_enabled($value) {
        return (bool) $value;
    }
    
    /**
     * Get debug log directory
     */
    private function get_debug_log_directory() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/attachment-lookup-logs';
    }
    
    /**
     * Attachment Lookup Settings Page
     */
    public function attachment_lookup_settings_page() {
        // Handle form submission
        if (isset($_POST['submit'])) {
            // WordPress automatically handles settings saving via the Settings API
            add_settings_error(
                'attachment_lookup_opts',
                'settings_updated',
                __('Settings saved successfully!', 'attachment-lookup-optimizer'),
                'updated'
            );
        }

        $options = get_option('attachment_lookup_opts', [
            'optimized_lookup_enabled' => true,
            'native_fallback_enabled' => true,
            'log_level' => 'errors_only'
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('attachment_lookup_opts'); ?>
            
            <div class="alo-settings-header" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php _e('Attachment Lookup Optimizer Settings', 'attachment-lookup-optimizer'); ?></h2>
                <p><?php _e('Configure the core settings for attachment URL lookups and fallback behavior.', 'attachment-lookup-optimizer'); ?></p>
                
                <!-- Status Overview -->
                <div class="alo-status-overview" style="margin-top: 20px;">
                    <h3><?php _e('Current Status', 'attachment-lookup-optimizer'); ?></h3>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div class="status-item">
                            <span class="status-label"><?php _e('Optimized Lookup:', 'attachment-lookup-optimizer'); ?></span>
                            <span class="status-value <?php echo $options['optimized_lookup_enabled'] ? 'status-enabled' : 'status-disabled'; ?>">
                                <?php echo $options['optimized_lookup_enabled'] ? 
                                    __('✓ Enabled', 'attachment-lookup-optimizer') : 
                                    __('✗ Disabled', 'attachment-lookup-optimizer'); ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-label"><?php _e('Native Fallback:', 'attachment-lookup-optimizer'); ?></span>
                            <span class="status-value <?php echo $options['native_fallback_enabled'] ? 'status-enabled' : 'status-disabled'; ?>">
                                <?php echo $options['native_fallback_enabled'] ? 
                                    __('✓ Enabled', 'attachment-lookup-optimizer') : 
                                    __('✗ Disabled', 'attachment-lookup-optimizer'); ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-label"><?php _e('Log Level:', 'attachment-lookup-optimizer'); ?></span>
                            <span class="status-value">
                                <?php 
                                $log_labels = [
                                    'none' => __('None', 'attachment-lookup-optimizer'),
                                    'errors_only' => __('Errors Only', 'attachment-lookup-optimizer'),
                                    'verbose' => __('Verbose', 'attachment-lookup-optimizer')
                                ];
                                echo esc_html($log_labels[$options['log_level']] ?? $log_labels['errors_only']);
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('attachment_lookup_opts_group');
                do_settings_sections('attachment_lookup_settings');
                submit_button();
                ?>
            </form>
            
            <style>
            .alo-settings-header .status-item {
                background: #f9f9f9;
                padding: 10px 15px;
                border-radius: 4px;
                border-left: 4px solid #ddd;
            }
            .status-label {
                font-weight: 600;
                margin-right: 10px;
            }
            .status-enabled {
                color: #46b450;
                font-weight: 600;
            }
            .status-disabled {
                color: #dc3232;
                font-weight: 600;
            }
            .form-table th {
                width: 200px;
            }
            .alo-error-entry {
                background: #fff2f2;
                border-left: 4px solid #dc3232;
                padding: 10px 15px;
                margin: 10px 0;
                border-radius: 4px;
            }
            .alo-error-time {
                color: #666;
                font-size: 12px;
                font-style: italic;
            }
            .alo-error-url {
                font-family: monospace;
                background: #f0f0f0;
                padding: 2px 4px;
                border-radius: 2px;
                word-break: break-all;
            }
            </style>
        </div>
        <?php
    }
    
    /**
     * Core settings section callback
     */
    public function core_settings_section_callback() {
        echo '<p>' . __('Control the main attachment lookup optimization features.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Fallback settings section callback
     */
    public function fallback_settings_section_callback() {
        echo '<p>' . __('Configure fallback behavior when optimized lookups fail or are slow.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Logging settings section callback
     */
    public function logging_settings_section_callback() {
        echo '<p>' . __('Control what gets logged for debugging and monitoring purposes.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Error history section callback
     */
    public function error_history_section_callback() {
        echo '<p>' . __('Recent fallback errors and failed lookups for troubleshooting.', 'attachment-lookup-optimizer') . '</p>';
        
        // Display last 10 fallback errors
        $this->display_recent_fallback_errors();
    }
    
    /**
     * Optimized lookup enabled field
     */
    public function optimized_lookup_enabled_field() {
        $options = get_option('attachment_lookup_opts', []);
        $value = isset($options['optimized_lookup_enabled']) ? $options['optimized_lookup_enabled'] : true;
        ?>
        <input type="checkbox" 
               name="attachment_lookup_opts[optimized_lookup_enabled]" 
               value="1" 
               <?php checked(1, $value); ?> />
        <label for="attachment_lookup_opts[optimized_lookup_enabled]">
            <?php _e('Enable optimized attachment URL lookups', 'attachment-lookup-optimizer'); ?>
        </label>
        <p class="description">
            <?php _e('Use caching, custom tables, and other optimizations to speed up attachment_url_to_postid() calls. Disable to use WordPress core functionality only.', 'attachment-lookup-optimizer'); ?>
        </p>
        <?php
    }
    
    /**
     * Native fallback enabled field
     */
    public function native_fallback_enabled_field() {
        $options = get_option('attachment_lookup_opts', []);
        $value = isset($options['native_fallback_enabled']) ? $options['native_fallback_enabled'] : true;
        ?>
        <input type="checkbox" 
               name="attachment_lookup_opts[native_fallback_enabled]" 
               value="1" 
               <?php checked(1, $value); ?> />
        <label for="attachment_lookup_opts[native_fallback_enabled]">
            <?php _e('Enable native WordPress fallback', 'attachment-lookup-optimizer'); ?>
        </label>
        <p class="description">
            <?php _e('Fall back to native WordPress attachment_url_to_postid() when optimized lookups fail or are too slow. Recommended to keep enabled.', 'attachment-lookup-optimizer'); ?>
        </p>
        <?php
    }
    
    /**
     * Log level field
     */
    public function log_level_field() {
        $options = get_option('attachment_lookup_opts', []);
        $value = isset($options['log_level']) ? $options['log_level'] : 'errors_only';
        ?>
        <select name="attachment_lookup_opts[log_level]">
            <option value="none" <?php selected('none', $value); ?>>
                <?php _e('None - No logging', 'attachment-lookup-optimizer'); ?>
            </option>
            <option value="errors_only" <?php selected('errors_only', $value); ?>>
                <?php _e('Errors Only - Log only failed lookups and fallbacks', 'attachment-lookup-optimizer'); ?>
            </option>
            <option value="verbose" <?php selected('verbose', $value); ?>>
                <?php _e('Verbose - Log all lookup attempts and performance data', 'attachment-lookup-optimizer'); ?>
            </option>
        </select>
        <p class="description">
            <?php _e('Choose how much information to log. Higher levels provide more debugging information but use more storage.', 'attachment-lookup-optimizer'); ?>
        </p>
        <?php
    }
    
    /**
     * Display recent fallback errors
     */
    private function display_recent_fallback_errors() {
        // Get recent fallback errors from transients/options
        $recent_errors = get_transient('alo_recent_fallback_errors');
        
        if (empty($recent_errors) || !is_array($recent_errors)) {
            echo '<div class="notice notice-info inline">';
            echo '<p>' . __('No recent fallback errors found. This is good - your optimized lookups are working well!', 'attachment-lookup-optimizer') . '</p>';
            echo '</div>';
            return;
        }
        
        // Sort by timestamp (most recent first)
        usort($recent_errors, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        // Show last 10 errors
        $recent_errors = array_slice($recent_errors, 0, 10);
        
        echo '<div class="alo-recent-errors">';
        echo '<h4>' . sprintf(__('Last %d fallback errors:', 'attachment-lookup-optimizer'), count($recent_errors)) . '</h4>';
        
        foreach ($recent_errors as $error) {
            echo '<div class="alo-error-entry">';
            echo '<div class="alo-error-time">' . 
                 esc_html(human_time_diff($error['timestamp'])) . ' ' . 
                 __('ago', 'attachment-lookup-optimizer') . 
                 '</div>';
            echo '<div><strong>' . __('URL:', 'attachment-lookup-optimizer') . '</strong> ';
            echo '<span class="alo-error-url">' . esc_html($this->truncate_url($error['url'] ?? '', 80)) . '</span></div>';
            
            if (!empty($error['error_message'])) {
                echo '<div><strong>' . __('Error:', 'attachment-lookup-optimizer') . '</strong> ' . 
                     esc_html($error['error_message']) . '</div>';
            }
            
            if (!empty($error['query_time_ms'])) {
                echo '<div><strong>' . __('Query Time:', 'attachment-lookup-optimizer') . '</strong> ' . 
                     esc_html(sprintf('%.2f ms', $error['query_time_ms'])) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        
        // Add clear errors button
        echo '<p style="margin-top: 15px;">';
        echo '<button type="button" class="button button-secondary" onclick="clearFallbackErrors()">';
        echo __('Clear Error History', 'attachment-lookup-optimizer');
        echo '</button>';
        echo '</p>';
        
        // Add JavaScript for clear button
        ?>
        <script>
        function clearFallbackErrors() {
            if (confirm('<?php _e('Are you sure you want to clear the fallback error history?', 'attachment-lookup-optimizer'); ?>')) {
                var data = {
                    action: 'alo_clear_fallback_errors',
                    nonce: '<?php echo wp_create_nonce('alo_clear_errors'); ?>'
                };
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(data)
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert('<?php _e('Failed to clear error history. Please try again.', 'attachment-lookup-optimizer'); ?>');
                    }
                })
                .catch(error => {
                    alert('<?php _e('An error occurred. Please try again.', 'attachment-lookup-optimizer'); ?>');
                });
            }
        }
        </script>
        <?php
    }
    
    /**
     * Sanitize attachment lookup options
     */
    public function sanitize_attachment_lookup_opts($options) {
        $sanitized = [];
        
        // Validate optimized lookup enabled
        $sanitized['optimized_lookup_enabled'] = isset($options['optimized_lookup_enabled']) ? 
            (bool) $options['optimized_lookup_enabled'] : true;
        
        // Validate native fallback enabled
        $sanitized['native_fallback_enabled'] = isset($options['native_fallback_enabled']) ? 
            (bool) $options['native_fallback_enabled'] : true;
        
        // Validate log level
        $valid_log_levels = ['none', 'errors_only', 'verbose'];
        $sanitized['log_level'] = isset($options['log_level']) && in_array($options['log_level'], $valid_log_levels) ? 
            $options['log_level'] : 'errors_only';
        
        return $sanitized;
    }
    
    /**
     * AJAX handler for clearing fallback errors
     */
    public function ajax_clear_fallback_errors() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'alo_clear_errors')) {
            wp_die(__('Security check failed.', 'attachment-lookup-optimizer'));
        }
        
        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'attachment-lookup-optimizer'));
        }
        
        // Clear the fallback errors transient
        delete_transient('alo_recent_fallback_errors');
        
        // Send success response
        wp_send_json_success([
            'message' => __('Fallback error history cleared successfully.', 'attachment-lookup-optimizer')
        ]);
    }
    
    /**
     * AJAX handler for manual cleanup
     */
    public function ajax_force_cleanup() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'alo_force_cleanup')) {
            wp_die(__('Security check failed.', 'attachment-lookup-optimizer'));
        }
        
        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'attachment-lookup-optimizer'));
        }
        
        // Get plugin instance and run cleanup
        $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
        if ($plugin && $plugin->force_cleanup()) {
            wp_send_json_success([
                'message' => __('Cleanup completed successfully.', 'attachment-lookup-optimizer')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Cleanup failed or you do not have sufficient permissions.', 'attachment-lookup-optimizer')
            ]);
        }
    }
    
    /**
     * Debug page for manual testing and comparison
     */
    public function debug_page() {
        // Restrict to administrators only
        if (!current_user_can('administrator')) {
            wp_die(__('Access denied. Administrator privileges required.', 'attachment-lookup-optimizer'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Attachment Lookup Debug & Testing', 'attachment-lookup-optimizer'); ?></h1>
            
            <div class="alo-debug-header" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0; color: #d63638;"><?php _e('⚠️ Debug Mode', 'attachment-lookup-optimizer'); ?></h2>
                <p style="color: #666;"><?php _e('This tool allows you to test and compare attachment lookup performance. Use URLs from your site\'s upload directory for accurate testing.', 'attachment-lookup-optimizer'); ?></p>
                
                <div class="alo-quick-actions" style="margin-top: 15px;">
                    <button type="button" class="button button-secondary" onclick="flushAllCache()">
                        <?php _e('🗑️ Flush All Cache', 'attachment-lookup-optimizer'); ?>
                    </button>
                    <button type="button" class="button button-secondary" onclick="loadSampleUrl()" style="margin-left: 10px;">
                        <?php _e('📄 Load Sample URL', 'attachment-lookup-optimizer'); ?>
                    </button>
                </div>
            </div>
            
            <!-- URL Testing Form -->
            <div class="alo-test-form" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3><?php _e('URL Lookup Comparison', 'attachment-lookup-optimizer'); ?></h3>
                
                <div style="margin-bottom: 20px;">
                    <label for="test_url" style="display: block; font-weight: 600; margin-bottom: 5px;">
                        <?php _e('Attachment URL to Test:', 'attachment-lookup-optimizer'); ?>
                    </label>
                    <input type="url" 
                           id="test_url" 
                           placeholder="<?php esc_attr_e('https://yoursite.com/wp-content/uploads/2023/01/image.jpg', 'attachment-lookup-optimizer'); ?>" 
                           style="width: 100%; max-width: 600px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                    <p class="description" style="margin: 5px 0 0 0;">
                        <?php _e('Paste any attachment URL from your uploads directory. The tool will test both optimized and native lookup methods.', 'attachment-lookup-optimizer'); ?>
                    </p>
                </div>
                
                <button type="button" class="button button-primary" onclick="runLookupTest()" id="test-button">
                    <?php _e('🔍 Run Lookup Test', 'attachment-lookup-optimizer'); ?>
                </button>
            </div>
            
            <!-- Results Display -->
            <div id="test-results" style="display: none;">
                <div class="alo-results-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
                    
                    <!-- Optimized Results -->
                    <div class="alo-result-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; color: #135e96;">
                            🚀 <?php _e('Optimized Lookup', 'attachment-lookup-optimizer'); ?>
                        </h3>
                        <div id="optimized-results">
                            <!-- Results populated via JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Native Results -->
                    <div class="alo-result-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; color: #d63638;">
                            🐌 <?php _e('Native WordPress', 'attachment-lookup-optimizer'); ?>
                        </h3>
                        <div id="native-results">
                            <!-- Results populated via JavaScript -->
                        </div>
                    </div>
                </div>
                
                <!-- Performance Comparison -->
                <div class="alo-comparison" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3><?php _e('📊 Performance Comparison', 'attachment-lookup-optimizer'); ?></h3>
                    <div id="comparison-results">
                        <!-- Comparison populated via JavaScript -->
                    </div>
                </div>
            </div>
            
            <!-- Slow Queries Display -->
            <div class="alo-slow-queries" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3><?php _e('🐌 Recent Slow Queries (>300ms)', 'attachment-lookup-optimizer'); ?></h3>
                <div id="slow-queries-list">
                    <?php $this->display_slow_queries(); ?>
                </div>
                <button type="button" class="button button-secondary" onclick="refreshSlowQueries()" style="margin-top: 10px;">
                    <?php _e('🔄 Refresh', 'attachment-lookup-optimizer'); ?>
                </button>
            </div>
            
            <?php $this->output_debug_javascript(); ?>
        </div>
        <?php
    }
    
    /**
     * Display recent slow queries (>300ms)
     */
    private function display_slow_queries() {
        $slow_queries = get_transient('alo_slow_query_samples');
        
        if (empty($slow_queries) || !is_array($slow_queries)) {
            echo '<p style="font-style: italic; color: #666;">';
            echo __('No slow queries recorded recently. This is good - your lookups are performing well!', 'attachment-lookup-optimizer');
            echo '</p>';
            return;
        }
        
        // Filter for queries >300ms and sort by time (slowest first)
        $filtered_queries = array_filter($slow_queries, function($query) {
            return isset($query['execution_time']) && $query['execution_time'] > 0.3;
        });
        
        if (empty($filtered_queries)) {
            echo '<p style="font-style: italic; color: #666;">';
            echo __('No queries slower than 300ms found recently.', 'attachment-lookup-optimizer');
            echo '</p>';
            return;
        }
        
        usort($filtered_queries, function($a, $b) {
            return $b['execution_time'] <=> $a['execution_time'];
        });
        
        // Show only last 10 slow queries
        $filtered_queries = array_slice($filtered_queries, 0, 10);
        
        echo '<table class="widefat" style="margin-top: 10px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('Time', 'attachment-lookup-optimizer') . '</th>';
        echo '<th>' . __('Query Time', 'attachment-lookup-optimizer') . '</th>';
        echo '<th>' . __('Query Type', 'attachment-lookup-optimizer') . '</th>';
        echo '<th>' . __('Details', 'attachment-lookup-optimizer') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($filtered_queries as $query) {
            $time_class = $query['execution_time'] > 1.0 ? 'alo-status-slow' : 'alo-status-medium';
            echo '<tr>';
            echo '<td>' . esc_html(human_time_diff($query['timestamp'] ?? time())) . ' ' . __('ago', 'attachment-lookup-optimizer') . '</td>';
            echo '<td class="' . $time_class . '" style="font-weight: 600;">' . esc_html(sprintf('%.0f ms', $query['execution_time'] * 1000)) . '</td>';
            echo '<td>' . esc_html($query['query_type'] ?? 'Unknown') . '</td>';
            echo '<td style="font-family: monospace; font-size: 12px;">' . esc_html($this->truncate_url($query['query_details'] ?? '', 60)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    /**
     * AJAX handler for testing lookup comparison
     */
    public function ajax_test_lookup() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'alo_test_lookup')) {
            wp_die(__('Security check failed.', 'attachment-lookup-optimizer'));
        }
        
        // Check capabilities
        if (!current_user_can('administrator')) {
            wp_die(__('Access denied. Administrator privileges required.', 'attachment-lookup-optimizer'));
        }
        
        $url = sanitize_url($_POST['url'] ?? '');
        if (empty($url)) {
            wp_send_json_error(['message' => __('Invalid URL provided.', 'attachment-lookup-optimizer')]);
        }
        
        // Test optimized lookup
        $start_time = microtime(true);
        $optimized_result = attachment_url_to_postid($url);
        $optimized_time = (microtime(true) - $start_time) * 1000;
        
        // Get lookup source from cache manager
        $lookup_source = 'unknown';
        $from_cache = false;
        
        if ($this->cache_manager) {
            $test_result = $this->cache_manager->test_cache($url);
            if (isset($test_result['lookup_source'])) {
                $lookup_source = $test_result['lookup_source'];
                $from_cache = $test_result['from_cache'] ?? false;
            }
        }
        
        // Test native WordPress lookup (disable our hooks temporarily)
        remove_all_filters('attachment_url_to_postid');
        $start_time = microtime(true);
        $native_result = attachment_url_to_postid($url);
        $native_time = (microtime(true) - $start_time) * 1000;
        
        // Re-enable our hooks
        if ($this->cache_manager) {
            $this->cache_manager->init_hooks();
        }
        
        wp_send_json_success([
            'optimized' => [
                'post_id' => $optimized_result,
                'time_ms' => $optimized_time,
                'lookup_source' => $lookup_source,
                'from_cache' => $from_cache
            ],
            'native' => [
                'post_id' => $native_result,
                'time_ms' => $native_time
            ]
        ]);
    }
    
    /**
     * AJAX handler for flushing cache
     */
    public function ajax_flush_cache() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'alo_flush_cache')) {
            wp_die(__('Security check failed.', 'attachment-lookup-optimizer'));
        }
        
        // Check capabilities
        if (!current_user_can('administrator')) {
            wp_die(__('Access denied. Administrator privileges required.', 'attachment-lookup-optimizer'));
        }
        
        // Flush all cache types
        $flushed = 0;
        
        // WordPress cache flush
        wp_cache_flush();
        $flushed++;
        
        // Plugin-specific cache clearing
        if ($this->cache_manager) {
            $this->cache_manager->clear_all_cache();
            $flushed++;
        }
        
        // Clear transients
        if ($this->transient_manager) {
            $flushed += $this->transient_manager->purge_all_transients();
        }
        
        wp_send_json_success([
            'message' => sprintf(__('Cache flushed successfully. %d cache stores cleared.', 'attachment-lookup-optimizer'), $flushed)
        ]);
    }
    
    /**
     * Output JavaScript for debug page functionality
     */
    private function output_debug_javascript() {
        ?>
        <style>
        .alo-result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .alo-result-item:last-child {
            border-bottom: none;
        }
        .alo-result-label {
            font-weight: 600;
            color: #555;
        }
        .alo-result-value {
            font-family: monospace;
            font-weight: 600;
        }
        .alo-status-fast {
            color: #46b450;
        }
        .alo-status-medium {
            color: #ffb900;
        }
        .alo-status-slow {
            color: #d63638;
        }
        .alo-speedup {
            background: #d7fcdf;
            color: #135e96;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
        }
        .alo-slowdown {
            background: #fcf0f1;
            color: #d63638;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
        }
        </style>
        
        <script>
        let testInProgress = false;
        
        function runLookupTest() {
            if (testInProgress) return;
            
            const url = document.getElementById('test_url').value.trim();
            if (!url) {
                alert('<?php _e('Please enter a URL to test.', 'attachment-lookup-optimizer'); ?>');
                return;
            }
            
            if (!url.includes('/wp-content/uploads/')) {
                const proceed = confirm('<?php _e('The URL doesn\'t appear to be from the uploads directory. Continue anyway?', 'attachment-lookup-optimizer'); ?>');
                if (!proceed) return;
            }
            
            testInProgress = true;
            const button = document.getElementById('test-button');
            const originalText = button.textContent;
            button.textContent = '<?php _e('🔄 Testing...', 'attachment-lookup-optimizer'); ?>';
            button.disabled = true;
            
            const data = {
                action: 'alo_test_lookup',
                url: url,
                nonce: '<?php echo wp_create_nonce('alo_test_lookup'); ?>'
            };
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(data)
            })
            .then(response => response.json())
            .then(result => {
                testInProgress = false;
                button.textContent = originalText;
                button.disabled = false;
                
                if (result.success) {
                    displayResults(result.data);
                    document.getElementById('test-results').style.display = 'block';
                } else {
                    alert('<?php _e('Test failed: ', 'attachment-lookup-optimizer'); ?>' + (result.data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                testInProgress = false;
                button.textContent = originalText;
                button.disabled = false;
                alert('<?php _e('An error occurred during testing.', 'attachment-lookup-optimizer'); ?>');
            });
        }
        
        function displayResults(data) {
            // Display optimized results
            const optimizedHtml = `
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Result:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value">${data.optimized.post_id || '<?php _e('Not Found', 'attachment-lookup-optimizer'); ?>'}</span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Time:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value ${getSpeedClass(data.optimized.time_ms)}">${data.optimized.time_ms.toFixed(2)} ms</span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Method:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value">${data.optimized.lookup_source || '<?php _e('Unknown', 'attachment-lookup-optimizer'); ?>'}</span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Cache Status:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value">${data.optimized.from_cache ? '<?php _e('Hit', 'attachment-lookup-optimizer'); ?>' : '<?php _e('Miss', 'attachment-lookup-optimizer'); ?>'}</span>
                </div>
            `;
            document.getElementById('optimized-results').innerHTML = optimizedHtml;
            
            // Display native results
            const nativeHtml = `
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Result:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value">${data.native.post_id || '<?php _e('Not Found', 'attachment-lookup-optimizer'); ?>'}</span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Time:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value ${getSpeedClass(data.native.time_ms)}">${data.native.time_ms.toFixed(2)} ms</span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Method:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value"><?php _e('WordPress Core', 'attachment-lookup-optimizer'); ?></span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Cache Status:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value"><?php _e('No Cache', 'attachment-lookup-optimizer'); ?></span>
                </div>
            `;
            document.getElementById('native-results').innerHTML = nativeHtml;
            
            // Display comparison
            const speedup = data.native.time_ms / data.optimized.time_ms;
            const resultMatch = data.optimized.post_id === data.native.post_id;
            
            const comparisonHtml = `
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Results Match:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value" style="color: ${resultMatch ? '#46b450' : '#d63638'}">
                        ${resultMatch ? '✅ <?php _e('Yes', 'attachment-lookup-optimizer'); ?>' : '❌ <?php _e('No', 'attachment-lookup-optimizer'); ?>'}
                    </span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Speed Improvement:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value">
                        ${speedup >= 1 ? 
                            `<span class="alo-speedup">${speedup.toFixed(1)}x <?php _e('faster', 'attachment-lookup-optimizer'); ?></span>` : 
                            `<span class="alo-slowdown">${(1/speedup).toFixed(1)}x <?php _e('slower', 'attachment-lookup-optimizer'); ?></span>`
                        }
                    </span>
                </div>
                <div class="alo-result-item">
                    <span class="alo-result-label"><?php _e('Time Saved:', 'attachment-lookup-optimizer'); ?></span>
                    <span class="alo-result-value">${(data.native.time_ms - data.optimized.time_ms).toFixed(2)} ms</span>
                </div>
            `;
            document.getElementById('comparison-results').innerHTML = comparisonHtml;
        }
        
        function getSpeedClass(timeMs) {
            if (timeMs < 10) return 'alo-status-fast';
            if (timeMs < 100) return 'alo-status-medium';
            return 'alo-status-slow';
        }
        
        function flushAllCache() {
            if (!confirm('<?php _e('Are you sure you want to flush all attachment lookup cache?', 'attachment-lookup-optimizer'); ?>')) {
                return;
            }
            
            const data = {
                action: 'alo_flush_cache',
                nonce: '<?php echo wp_create_nonce('alo_flush_cache'); ?>'
            };
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('<?php _e('Cache flushed successfully!', 'attachment-lookup-optimizer'); ?>');
                } else {
                    alert('<?php _e('Failed to flush cache.', 'attachment-lookup-optimizer'); ?>');
                }
            });
        }
        
        function loadSampleUrl() {
            // Try to load a sample URL from recent uploads
            const sampleUrls = [
                '<?php echo home_url('/wp-content/uploads/2023/01/sample.jpg'); ?>',
                '<?php echo home_url('/wp-content/uploads/2023/02/test.png'); ?>',
                '<?php echo home_url('/wp-content/uploads/2023/03/image.jpeg'); ?>'
            ];
            
            const randomUrl = sampleUrls[Math.floor(Math.random() * sampleUrls.length)];
            document.getElementById('test_url').value = randomUrl;
            document.getElementById('test_url').focus();
        }
        
        function refreshSlowQueries() {
            location.reload();
        }
        
        // Auto-focus URL input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('test_url').focus();
        });
        </script>
        <?php
    }
} 