<?php
namespace AttachmentLookupOptimizer;

require_once __DIR__ . '/BunnyCDNRewrittenPostsTable.php';

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
     * Additional option constants for all settings
     */
    const JETENGINE_PRELOADING_OPTION = 'alo_jetengine_preloading';
    const LAZY_LOADING_OPTION = 'alo_lazy_loading';
    const LAZY_LOADING_ABOVE_FOLD_OPTION = 'alo_lazy_loading_above_fold';
    const JETENGINE_QUERY_OPTIMIZATION_OPTION = 'alo_jetengine_query_optimization';
    const EXPENSIVE_QUERY_LOGGING_OPTION = 'alo_expensive_query_logging';
    const SLOW_QUERY_THRESHOLD_OPTION = 'alo_slow_query_threshold';
    const SLOW_QUERY_THRESHOLD_MS_OPTION = 'alo_slow_query_threshold_ms';
    const CUSTOM_LOOKUP_TABLE_OPTION = 'alo_custom_lookup_table';
    const ATTACHMENT_DEBUG_LOGS_OPTION = 'alo_attachment_debug_logs';
    const DEBUG_LOG_FORMAT_OPTION = 'alo_debug_log_format';
    const NATIVE_FALLBACK_ENABLED_OPTION = 'alo_native_fallback_enabled';
    const FAILURE_TRACKING_ENABLED_OPTION = 'alo_failure_tracking_enabled';
    
    /**
     * BunnyCDN Integration option constants
     */
    const BUNNYCDN_ENABLED_OPTION = 'alo_bunnycdn_enabled';
    const BUNNYCDN_API_KEY_OPTION = 'alo_bunnycdn_api_key';
    const BUNNYCDN_STORAGE_ZONE_OPTION = 'alo_bunnycdn_storage_zone';
    const BUNNYCDN_REGION_OPTION = 'alo_bunnycdn_region';
    const BUNNYCDN_HOSTNAME_OPTION = 'alo_bunnycdn_hostname';
    const BUNNYCDN_OVERRIDE_URLS_OPTION = 'alo_bunnycdn_override_urls';
    const BUNNYCDN_AUTO_SYNC_OPTION = 'alo_bunnycdn_auto_sync';
    const BUNNYCDN_OFFLOAD_ENABLED_OPTION = 'alo_bunnycdn_offload_enabled';
    const BUNNYCDN_BATCH_SIZE_OPTION = 'alo_bunnycdn_batch_size';
    const BUNNYCDN_REWRITE_URLS_OPTION = 'alo_bunnycdn_rewrite_urls';
    const BUNNYCDN_REWRITE_META_ENABLED_OPTION = 'alo_bunnycdn_rewrite_meta_enabled';
    
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
     * BunnyCDN manager instance
     */
    private $bunny_cdn_manager;
    
    /**
     * Constructor
     */
    public function __construct($database_manager, $cache_manager) {
        $this->database_manager = $database_manager;
        $this->cache_manager = $cache_manager;
        
        // Initialize upload preprocessor if not set
        if (!$this->upload_preprocessor) {
            $this->upload_preprocessor = new UploadPreprocessor();
            error_log('ALO: Upload preprocessor initialized in AdminInterface constructor');
        }
        
        $this->init_hooks();
    }
    
    /**
     * Set upload preprocessor instance
     */
    public function set_upload_preprocessor($upload_preprocessor) {
        $this->upload_preprocessor = $upload_preprocessor;
        
        // Debug logging to help diagnose initialization issues
        if ($upload_preprocessor) {
            error_log('ALO: Upload preprocessor set successfully in AdminInterface: ' . get_class($upload_preprocessor));
        } else {
            error_log('ALO: Upload preprocessor is null in AdminInterface');
        }
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
     * Set BunnyCDN manager instance
     */
    public function set_bunny_cdn_manager($bunny_cdn_manager) {
        $this->bunny_cdn_manager = $bunny_cdn_manager;
    }
    
    /**
     * BunnyCDN cron sync instance
     */
    private $bunnycdn_cron_sync;
    
    /**
     * Set BunnyCDN cron sync instance
     */
    public function set_bunnycdn_cron_sync($bunnycdn_cron_sync) {
        $this->bunnycdn_cron_sync = $bunnycdn_cron_sync;
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
        
        // Global override notice dismissal
        add_action('wp_ajax_alo_dismiss_global_override_notice', [$this, 'dismiss_global_override_notice']);
        
        // AJAX handler for clearing fallback errors
        add_action('wp_ajax_alo_clear_fallback_errors', [$this, 'ajax_clear_fallback_errors']);
        
        // AJAX handler for manual cleanup
        add_action('wp_ajax_alo_force_cleanup', [$this, 'ajax_force_cleanup']);
        
        // AJAX handlers for debug operations
        add_action('wp_ajax_alo_test_lookup', [$this, 'ajax_test_lookup']);
        add_action('wp_ajax_alo_flush_cache', [$this, 'ajax_flush_cache']);
        add_action('wp_ajax_alo_test_static_cache', [$this, 'ajax_test_static_cache']);
        add_action('wp_ajax_alo_clear_shared_cache', [$this, 'ajax_clear_shared_cache']);
        
        // AJAX handlers for progress operations
        add_action('wp_ajax_alo_bulk_process_attachments', [$this, 'ajax_bulk_process_attachments']);
        add_action('wp_ajax_alo_warm_cache_progress', [$this, 'ajax_warm_cache_progress']);
        
        // AJAX handler for force create table
        add_action('wp_ajax_alo_force_create_table', [$this, 'ajax_force_create_table']);
        
        // AJAX handler for testing live stats
        add_action('wp_ajax_alo_test_live_stats', [$this, 'ajax_test_live_stats']);
        
        // AJAX handler for testing WebP support
        add_action('wp_ajax_alo_test_webp_support', [$this, 'ajax_test_webp_support']);
        
        // AJAX handler for reclaim disk space
        add_action('wp_ajax_alo_reclaim_disk_space', [$this, 'ajax_reclaim_disk_space']);
        
        // AJAX handler for reset offload status
        add_action('wp_ajax_alo_reset_offload_status', [$this, 'ajax_reset_offload_status']);
        
        // AJAX handler for content URL rewriting tool
        add_action('wp_ajax_alo_rewrite_content_urls', [$this, 'ajax_rewrite_content_urls']);
        
        // Hook into the cache TTL filter to use our setting
        add_filter('alo_cache_expiration', [$this, 'get_cache_ttl_setting']);
        
        // Hook into the global override filter to use our setting
        add_filter('alo_enable_global_override', [$this, 'get_global_override_setting']);
        
        // Hook into the JetEngine preloading filter to use our setting
        add_filter('alo_jetengine_preloading_enabled', [$this, 'get_jetengine_preloading_setting']);
        
        // Hook into all additional settings filters
        add_filter('alo_debug_logging_enabled', function($default) {
            return get_option(self::DEBUG_LOGGING_OPTION, $default);
        });
        
        add_filter('alo_debug_threshold', function($default) {
            return get_option(self::DEBUG_THRESHOLD_OPTION, $default);
        });
        
        add_filter('alo_lazy_loading_enabled', function($default) {
            return get_option(self::LAZY_LOADING_OPTION, $default);
        });
        
        add_filter('alo_lazy_loading_above_fold_limit', function($default) {
            return get_option(self::LAZY_LOADING_ABOVE_FOLD_OPTION, $default);
        });
        
        add_filter('alo_jetengine_query_optimization_enabled', function($default) {
            return get_option(self::JETENGINE_QUERY_OPTIMIZATION_OPTION, $default);
        });
        
        add_filter('alo_expensive_query_logging_enabled', function($default) {
            return get_option(self::EXPENSIVE_QUERY_LOGGING_OPTION, $default);
        });
        
        add_filter('alo_slow_query_threshold', function($default) {
            return get_option(self::SLOW_QUERY_THRESHOLD_OPTION, $default);
        });
        
        add_filter('alo_slow_query_threshold_ms', function($default) {
            return get_option(self::SLOW_QUERY_THRESHOLD_MS_OPTION, $default);
        });
        
        add_filter('alo_custom_lookup_table_enabled', function($default) {
            return get_option(self::CUSTOM_LOOKUP_TABLE_OPTION, $default);
        });
        
        add_filter('alo_attachment_debug_logs_enabled', function($default) {
            return get_option(self::ATTACHMENT_DEBUG_LOGS_OPTION, $default);
        });
        
        add_filter('alo_debug_log_format', function($default) {
            return get_option(self::DEBUG_LOG_FORMAT_OPTION, $default);
        });
        
        add_filter('alo_native_fallback_enabled', function($default) {
            return get_option(self::NATIVE_FALLBACK_ENABLED_OPTION, $default);
        });
        
        add_filter('alo_failure_tracking_enabled', function($default) {
            return get_option(self::FAILURE_TRACKING_ENABLED_OPTION, $default);
        });
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
        
        // Add BunnyCDN Rewritten Posts page under Tools menu
        add_management_page(
            __('BunnyCDN Rewritten Posts', 'attachment-lookup-optimizer'),
            __('BunnyCDN Rewritten Posts', 'attachment-lookup-optimizer'),
            'manage_options',
            'bunnycdn-rewritten-posts',
            [$this, 'bunnycdn_rewritten_posts_page']
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
            self::JETENGINE_PRELOADING_OPTION,
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_jetengine_preloading']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::LAZY_LOADING_OPTION,
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_lazy_loading']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::LAZY_LOADING_ABOVE_FOLD_OPTION,
            [
                'type' => 'integer',
                'default' => 3,
                'sanitize_callback' => [$this, 'sanitize_lazy_loading_above_fold']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::JETENGINE_QUERY_OPTIMIZATION_OPTION,
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_jetengine_query_optimization']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::EXPENSIVE_QUERY_LOGGING_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_expensive_query_logging']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::SLOW_QUERY_THRESHOLD_OPTION,
            [
                'type' => 'number',
                'default' => 0.3,
                'sanitize_callback' => [$this, 'sanitize_slow_query_threshold']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::CUSTOM_LOOKUP_TABLE_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_custom_lookup_table']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::ATTACHMENT_DEBUG_LOGS_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_attachment_debug_logs']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::DEBUG_LOG_FORMAT_OPTION,
            [
                'type' => 'string',
                'default' => 'json',
                'sanitize_callback' => [$this, 'sanitize_debug_log_format']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::NATIVE_FALLBACK_ENABLED_OPTION,
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_native_fallback_enabled']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::SLOW_QUERY_THRESHOLD_MS_OPTION,
            [
                'type' => 'integer',
                'default' => 300,
                'sanitize_callback' => [$this, 'sanitize_slow_query_threshold_ms']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::FAILURE_TRACKING_ENABLED_OPTION,
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_failure_tracking_enabled']
            ]
        );
        
        // BunnyCDN Integration Settings
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_ENABLED_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_enabled']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_API_KEY_OPTION,
            [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_api_key']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_STORAGE_ZONE_OPTION,
            [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_storage_zone']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_REGION_OPTION,
            [
                'type' => 'string',
                'default' => 'de',
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_region']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_HOSTNAME_OPTION,
            [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_hostname']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_OVERRIDE_URLS_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_override_urls']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_AUTO_SYNC_OPTION,
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_auto_sync']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_OFFLOAD_ENABLED_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_offload_enabled']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_BATCH_SIZE_OPTION,
            [
                'type' => 'integer',
                'default' => 10,
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_batch_size']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_REWRITE_URLS_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_rewrite_urls']
            ]
        );
        
        register_setting(
            'alo_settings_group',
            self::BUNNYCDN_REWRITE_META_ENABLED_OPTION,
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => [$this, 'sanitize_bunnycdn_rewrite_meta_enabled']
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
        
        // BunnyCDN Integration Section
        add_settings_section(
            'alo_bunnycdn_section',
            __('BunnyCDN Integration', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_section_callback'],
            'alo_settings'
        );
        
        add_settings_field(
            'bunnycdn_enabled',
            __('Enable BunnyCDN Integration', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_enabled_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_api_key',
            __('BunnyCDN API Key', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_api_key_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_storage_zone',
            __('Storage Zone Name', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_storage_zone_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_region',
            __('Storage Region', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_region_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_hostname',
            __('Custom CDN Hostname (optional)', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_hostname_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_override_urls',
            __('Serve attachments from BunnyCDN', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_override_urls_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_auto_sync',
            __('Enable automatic background sync', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_auto_sync_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_offload_enabled',
            __('Delete local files after upload', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_offload_enabled_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_batch_size',
            __('Batch size for sync operations', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_batch_size_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_rewrite_urls',
            __('Rewrite post content URLs', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_rewrite_urls_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
        );
        
        add_settings_field(
            'bunnycdn_rewrite_meta_enabled',
            __('Rewrite BunnyCDN URLs in JetEngine/JetFormBuilder fields', 'attachment-lookup-optimizer'),
            [$this, 'bunnycdn_rewrite_meta_enabled_field_callback'],
            'alo_settings',
            'alo_bunnycdn_section'
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
     * Sanitize debug logging setting
     */
    public function sanitize_debug_logging($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize debug threshold setting
     */
    public function sanitize_debug_threshold($value) {
        $value = absint($value);
        return max(1, min(100, $value)); // Between 1 and 100
    }
    
    /**
     * Sanitize global override setting
     */
    public function sanitize_global_override($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize JetEngine preloading setting
     */
    public function sanitize_jetengine_preloading($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize lazy loading setting
     */
    public function sanitize_lazy_loading($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize lazy loading above fold setting
     */
    public function sanitize_lazy_loading_above_fold($value) {
        $value = absint($value);
        return max(0, min(20, $value)); // Between 0 and 20 images
    }
    
    /**
     * Sanitize JetEngine query optimization setting
     */
    public function sanitize_jetengine_query_optimization($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize expensive query logging setting
     */
    public function sanitize_expensive_query_logging($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize slow query threshold setting
     */
    public function sanitize_slow_query_threshold($value) {
        $value = floatval($value);
        return max(0.1, min(10.0, $value)); // Between 0.1 and 10 seconds
    }
    
    /**
     * Sanitize slow query threshold in milliseconds setting
     */
    public function sanitize_slow_query_threshold_ms($value) {
        $value = intval($value);
        return max(50, min(5000, $value)); // Between 50ms and 5000ms
    }
    
    /**
     * Sanitize custom lookup table setting
     */
    public function sanitize_custom_lookup_table($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize attachment debug logs setting
     */
    public function sanitize_attachment_debug_logs($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize debug log format setting
     */
    public function sanitize_debug_log_format($value) {
        $valid_formats = ['json', 'text', 'csv'];
        return in_array($value, $valid_formats) ? $value : 'json';
    }
    
    /**
     * Sanitize native fallback enabled setting
     */
    public function sanitize_native_fallback_enabled($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize failure tracking enabled setting
     */
    public function sanitize_failure_tracking_enabled($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize BunnyCDN enabled setting
     */
    public function sanitize_bunnycdn_enabled($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_ENABLED_OPTION, false);
        }
        return (bool) $value;
    }
    
    /**
     * Sanitize BunnyCDN API key
     */
    public function sanitize_bunnycdn_api_key($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_API_KEY_OPTION, '');
        }
        return sanitize_text_field(trim($value));
    }
    
    /**
     * Sanitize BunnyCDN storage zone
     */
    public function sanitize_bunnycdn_storage_zone($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_STORAGE_ZONE_OPTION, '');
        }
        
        $value = sanitize_text_field(trim($value));
        
        // Apply the same sanitization as BunnyCDNManager
        if (!empty($value)) {
            // Remove common mistakes - full URLs or hostnames
            $value = str_replace([
                'https://',
                'http://',
                'storage.bunnycdn.com/',
                'storage.bunnycdn.com',
                '.b-cdn.net',
                'www.'
            ], '', $value);
            
            // Remove leading/trailing slashes and whitespace
            $value = trim($value, '/ ');
        }
        
        return $value;
    }
    
    /**
     * Sanitize BunnyCDN region
     */
    public function sanitize_bunnycdn_region($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_REGION_OPTION, 'de');
        }
        $allowed_regions = ['de', 'ny', 'la', 'sg', 'syd', 'uk'];
        return in_array($value, $allowed_regions) ? $value : 'de';
    }
    
    /**
     * Sanitize BunnyCDN hostname
     */
    public function sanitize_bunnycdn_hostname($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_HOSTNAME_OPTION, '');
        }
        $value = sanitize_text_field(trim($value));
        // Basic hostname validation
        if (!empty($value) && !filter_var('http://' . $value, FILTER_VALIDATE_URL)) {
            return '';
        }
        return $value;
    }
    
    /**
     * Sanitize BunnyCDN override URLs setting
     */
    public function sanitize_bunnycdn_override_urls($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_OVERRIDE_URLS_OPTION, false);
        }
        return (bool) $value;
    }
    
    /**
     * Sanitize BunnyCDN auto sync setting
     */
    public function sanitize_bunnycdn_auto_sync($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_AUTO_SYNC_OPTION, true);
        }
        return (bool) $value;
    }
    
    /**
     * Sanitize BunnyCDN offload enabled setting
     */
    public function sanitize_bunnycdn_offload_enabled($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_OFFLOAD_ENABLED_OPTION, false);
        }
        return (bool) $value;
    }
    
    /**
     * Sanitize BunnyCDN batch size setting
     */
    public function sanitize_bunnycdn_batch_size($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_BATCH_SIZE_OPTION, 25);
        }
        $value = absint($value);
        return max(5, min(200, $value)); // Between 5 and 200 files per batch
    }
    
    /**
     * Sanitize BunnyCDN rewrite URLs setting
     */
    public function sanitize_bunnycdn_rewrite_urls($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_REWRITE_URLS_OPTION, false);
        }
        return (bool) $value;
    }
    
    /**
     * Sanitize BunnyCDN rewrite meta enabled setting
     */
    public function sanitize_bunnycdn_rewrite_meta_enabled($value) {
        if (!current_user_can('manage_options')) {
            return get_option(self::BUNNYCDN_REWRITE_META_ENABLED_OPTION, false);
        }
        return (bool) $value;
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
        return get_option(self::JETENGINE_PRELOADING_OPTION, $default);
    }
    
    /**
     * Get option value by key
     */
    public function get_option($option_key, $default = false) {
        // Map option keys to their constants
        $option_map = [
            'bunnycdn_enabled' => self::BUNNYCDN_ENABLED_OPTION,
            'bunnycdn_api_key' => self::BUNNYCDN_API_KEY_OPTION,
            'bunnycdn_storage_zone' => self::BUNNYCDN_STORAGE_ZONE_OPTION,
            'bunnycdn_region' => self::BUNNYCDN_REGION_OPTION,
            'bunnycdn_hostname' => self::BUNNYCDN_HOSTNAME_OPTION,
            'bunnycdn_override_urls' => self::BUNNYCDN_OVERRIDE_URLS_OPTION,
        ];
        
        $option_name = $option_map[$option_key] ?? $option_key;
        return get_option($option_name, $default);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_attachment-lookup-optimizer' && 
            $hook !== 'tools_page_attachment-lookup-stats' && 
            $hook !== 'tools_page_bunnycdn-rewritten-posts') {
            return;
        }
        
        wp_enqueue_style('alo-admin-style', ALO_PLUGIN_URL . 'assets/admin.css', [], ALO_VERSION);
        
        // Add inline CSS for post type badges and tabs
        if ($hook === 'tools_page_bunnycdn-rewritten-posts') {
            $custom_css = "
                .post-type-badge {
                    display: inline-block;
                    background: #6b7280;
                    color: white;
                    padding: 2px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                .subsubsub {
                    margin: 10px 0 15px 0;
                    padding: 10px 0;
                    border-bottom: 1px solid #ddd;
                }
                
                .subsubsub a {
                    text-decoration: none;
                    padding: 5px 10px;
                    border-radius: 3px;
                    transition: background-color 0.2s;
                }
                
                .subsubsub a:hover {
                    background-color: #f0f0f1;
                }
                
                .subsubsub a.current {
                    font-weight: bold;
                    background-color: #2271b1;
                    color: white;
                }
                
                .subsubsub a.current:hover {
                    background-color: #135e96;
                }
                
                .subsubsub .count {
                    font-weight: normal;
                    opacity: 0.8;
                }
                
                #post-type-filter {
                    margin-right: 10px;
                }
                
                .wp-list-table .column-post_type {
                    width: 120px;
                }
                
                .wp-list-table .column-bunnycdn_urls {
                    width: 100px;
                    text-align: center;
                }
                
                .wp-list-table .column-rewrite_date {
                    width: 150px;
                }
                
                .wp-list-table .column-actions {
                    width: 150px;
                }
            ";
            wp_add_inline_style('alo-admin-style', $custom_css);
        }
        
        // Enqueue jQuery for AJAX functionality on both pages
        wp_enqueue_script('jquery');
        
        // Enqueue BunnyCDN sync script for the main settings page
        if ($hook === 'tools_page_attachment-lookup-optimizer') {
            wp_enqueue_script(
                'bunnycdn-sync',
                ALO_PLUGIN_URL . 'assets/bunnycdn-sync.js',
                ['jquery'],
                ALO_VERSION,
                true
            );
            
            // Localize script with AJAX data
            wp_localize_script('bunnycdn-sync', 'BUNNYCDN_SYNC', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('bunnycdn_sync_nonce'),
                'stop_flag' => get_option('bunnycdn_stop_sync', false)
            ]);
        }
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        // Handle form submissions
        // Quick setup
        if (isset($_POST['alo_quick_setup']) && wp_verify_nonce($_POST['alo_nonce_quick_setup'], 'alo_quick_setup')) {
            // Enable all key features
            update_option('alo_custom_lookup_table', true);
            update_option('alo_jetengine_preloading', true);
            update_option('alo_lazy_loading', true);
            update_option('alo_jetengine_query_optimization', true);
            update_option('alo_attachment_debug_logs', true);
            
            // Create and populate the custom table
            if ($this->custom_lookup_table) {
                $create_result = $this->custom_lookup_table->create_table();
                $rebuild_result = $this->custom_lookup_table->rebuild_table();
                
                echo '<div class="notice notice-success"><p>' . 
                     __('🚀 Quick setup completed! All features enabled and custom table created.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>' . 
                     __('⚠ Features enabled, but custom table service is not available. Please check plugin configuration.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_clear_cache']) && wp_verify_nonce($_POST['alo_nonce_clear_cache'], 'alo_actions')) {
            $this->cache_manager->clear_all_cache();
            echo '<div class="notice notice-success"><p>' . __('Cache cleared successfully!', 'attachment-lookup-optimizer') . '</p></div>';
        }
        
        if (isset($_POST['alo_warm_cache']) && wp_verify_nonce($_POST['alo_nonce_warm_cache'], 'alo_actions')) {
            $warmed = $this->cache_manager->warm_cache(100);
            echo '<div class="notice notice-success"><p>' . 
                 sprintf(__('Cache warmed for %d attachments!', 'attachment-lookup-optimizer'), $warmed) . 
                 '</p></div>';
        }
        
        if (isset($_POST['alo_test_batch']) && wp_verify_nonce($_POST['alo_nonce_test_batch'], 'alo_actions')) {
            $test_results = $this->test_batch_lookup();
            echo '<div class="notice notice-info"><p>' . 
                 sprintf(__('Batch test completed: %d URLs tested, %d found, %d cache hits (%.1f%% hit ratio)', 'attachment-lookup-optimizer'), 
                     $test_results['total_urls'], 
                     $test_results['found_count'],
                     $test_results['cache_hits'],
                     $test_results['hit_ratio']) . 
                 '</p></div>';
        }
        
        if (isset($_POST['alo_bulk_process']) && wp_verify_nonce($_POST['alo_nonce_bulk_process'], 'alo_actions')) {
            if ($this->upload_preprocessor) {
                $process_results = $this->upload_preprocessor->bulk_process_existing_attachments(200);
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Bulk processing completed: %d attachments processed', 'attachment-lookup-optimizer'), 
                         $process_results['processed']) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_rebuild_table']) && wp_verify_nonce($_POST['alo_nonce_rebuild_table'], 'alo_actions')) {
            if ($this->custom_lookup_table) {
                $rebuild_results = $this->custom_lookup_table->rebuild_table();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Custom lookup table rebuilt: %d mappings created', 'attachment-lookup-optimizer'), 
                         $rebuild_results) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_cleanup_transients']) && wp_verify_nonce($_POST['alo_nonce_cleanup_transients'], 'alo_actions')) {
            if ($this->transient_manager) {
                $cleanup_results = $this->transient_manager->force_cleanup();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Transient cleanup completed: %d expired transients removed', 'attachment-lookup-optimizer'), 
                         $cleanup_results['total_cleaned']) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_purge_transients']) && wp_verify_nonce($_POST['alo_nonce_purge_transients'], 'alo_actions')) {
            if ($this->transient_manager) {
                $purged_count = $this->transient_manager->purge_all_transients();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('All transients purged: %d transients removed', 'attachment-lookup-optimizer'), 
                         $purged_count) . 
                     '</p></div>';
            }
        }
        
        if (isset($_POST['alo_clear_query_stats']) && wp_verify_nonce($_POST['alo_nonce_clear_query_stats'], 'alo_actions')) {
            $this->database_manager->clear_query_stats();
            echo '<div class="notice notice-success"><p>' . 
                 __('Query statistics cleared successfully!', 'attachment-lookup-optimizer') . 
                 '</p></div>';
        }
        
        if (isset($_POST['alo_test_cache']) && wp_verify_nonce($_POST['alo_nonce_test_cache'], 'alo_actions')) {
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
        
        if (isset($_POST['alo_test_custom_table']) && wp_verify_nonce($_POST['alo_nonce_test_custom_table'], 'alo_actions')) {
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
        
        if (isset($_POST['alo_clear_debug_logs']) && wp_verify_nonce($_POST['alo_nonce_clear_debug_logs'], 'alo_actions')) {
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
        
        if (isset($_POST['alo_force_cleanup']) && wp_verify_nonce($_POST['alo_nonce_force_cleanup'], 'alo_actions')) {
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
        
        if (isset($_POST['alo_clear_archived_logs']) && wp_verify_nonce($_POST['alo_nonce_clear_archived_logs'], 'alo_actions')) {
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
        
        if (isset($_POST['alo_clear_watchlist']) && wp_verify_nonce($_POST['alo_nonce_clear_watchlist'], 'alo_actions')) {
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
        
        if (isset($_POST['alo_cleanup_duplicates']) && wp_verify_nonce($_POST['alo_nonce_cleanup_duplicates'], 'alo_actions')) {
            if ($this->custom_lookup_table) {
                $cleanup_results = $this->custom_lookup_table->cleanup_duplicate_entries();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Duplicate entries cleaned up: %d duplicates removed', 'attachment-lookup-optimizer'), 
                         $cleanup_results) . 
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
        
        // Get live statistics for the Live Lookup Summary section  
        $stats = $this->cache_manager ? $this->cache_manager->get_live_stats() : $this->get_empty_live_stats();
        
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
                                <strong><?php echo esc_html($cache_stats['method_description'] ?? 'Unknown'); ?></strong>
                                <?php if (($cache_stats['method'] ?? '') === 'object_cache'): ?>
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
                                <?php if ($cache_stats['redis_available'] ?? false): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('No', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Object Cache Available', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cache_stats['object_cache_available'] ?? false): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-bad">✗ <?php _e('No', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Persistent Object Cache', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($cache_stats['persistent_object_cache'] ?? false): ?>
                                    <span class="alo-status-good">✓ <?php _e('Yes (External Cache)', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('No (using transients)', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Group', 'attachment-lookup-optimizer'); ?></td>
                            <td><code><?php echo esc_html($cache_stats['cache_group'] ?? 'alo_attachment_cache'); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Key Prefix', 'attachment-lookup-optimizer'); ?></td>
                            <td><code><?php echo esc_html($cache_stats['cache_key_prefix'] ?? 'alo_'); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php _e('Default Expiration', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php 
                            $default_expiration = $cache_stats['default_expiration'] ?? 3600;
                            echo sprintf(__('%d seconds (%s)', 'attachment-lookup-optimizer'), 
                                $default_expiration, human_time_diff(0, $default_expiration)); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Last Cache Clear', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo ($cache_stats['last_cleared'] ?? null) ? 
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
                                echo number_format_i18n($static_stats['static_cache_hits'] ?? 0);
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Static Hit Ratio (This Request)', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <strong><?php echo sprintf(__('%s%%', 'attachment-lookup-optimizer'), $static_stats['static_hit_ratio'] ?? 0); ?></strong>
                                <?php if (($static_stats['static_hit_ratio'] ?? 0) >= 50): ?>
                                    <span class="alo-status-good">✓ <?php _e('Excellent', 'attachment-lookup-optimizer'); ?></span>
                                <?php elseif (($static_stats['static_hit_ratio'] ?? 0) >= 20): ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Good', 'attachment-lookup-optimizer'); ?></span>
                                <?php elseif (($static_stats['static_hit_ratio'] ?? 0) > 0): ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Some Benefit', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-neutral">— <?php _e('No Duplicates', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Total Calls (This Request)', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo number_format_i18n($static_stats['total_calls'] ?? 0); ?></td>
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
                        <tr>
                            <td><?php _e('Test Static Cache', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <button type="button" id="test-static-cache-btn" class="button">
                                    <?php _e('Test Static Cache Functionality', 'attachment-lookup-optimizer'); ?>
                                </button>
                                <div id="static-cache-test-results" style="margin-top: 10px; display: none;"></div>
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
                                <?php $jetengine_enabled = get_option(self::JETENGINE_PRELOADING_OPTION, true); ?>
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
                                <?php if (($cache_stats['cache_method'] ?? 'none') === 'transients'): ?>
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
                            <td>
                                <?php if ($transient_stats): ?>
                                    <?php echo sprintf(__('%s MB', 'attachment-lookup-optimizer'), $transient_stats['storage_size_mb']); ?>
                                    <?php if ($transient_stats['storage_size_mb'] == 0 && $transient_stats['current_transients'] == 0): ?>
                                        <br><small style="color: #666;">
                                            <?php _e('(Lookup data stored in custom table, not transients)', 'attachment-lookup-optimizer'); ?>
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
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
                                <?php $lazy_enabled = get_option(self::LAZY_LOADING_OPTION, true); ?>
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
                        <?php 
                        $live_stats_enabled = $this->cache_manager ? $this->cache_manager->is_live_stats_enabled() : false;
                        $global_override_enabled = get_option(self::GLOBAL_OVERRIDE_OPTION, false);
                        ?>
                        <p style="margin-bottom: 15px;">
                            <strong><?php _e('Status:', 'attachment-lookup-optimizer'); ?></strong>
                            <?php if ($live_stats_enabled): ?>
                                <span class="alo-status-good">✓ <?php _e('Active', 'attachment-lookup-optimizer'); ?></span>
                                <?php if (!$global_override_enabled): ?>
                                    <span style="color: #856404;">(<?php _e('Stats Only Mode', 'attachment-lookup-optimizer'); ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="alo-status-bad">✗ <?php _e('Disabled', 'attachment-lookup-optimizer'); ?></span>
                            <?php endif; ?>
                        </p>
                        <table class="widefat">
                            <tr>
                                <td><?php _e('Total Lookups', 'attachment-lookup-optimizer'); ?></td>
                                <td><strong><?php echo number_format_i18n($stats['total_lookups'] ?? 0); ?></strong></td>
                            </tr>
                            <tr>
                                <td><?php _e('Successful Lookups', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['successful_lookups'] ?? 0); ?></strong>
                                    (<?php echo ($stats['success_rate'] ?? 0); ?>%)
                                    <?php if (($stats['success_rate'] ?? 0) >= 80): ?>
                                        <span class="alo-status-good">✓</span>
                                    <?php elseif (($stats['success_rate'] ?? 0) >= 60): ?>
                                        <span class="alo-status-warning">⚠</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">✗</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Not Found Count', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo number_format_i18n($stats['not_found_count'] ?? 0); ?></strong>
                                    <?php if (($stats['total_lookups'] ?? 0) > 0): ?>
                                        (<?php echo round((($stats['not_found_count'] ?? 0) / ($stats['total_lookups'] ?? 1)) * 100, 1); ?>%)
                                    <?php else: ?>
                                        (0%)
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Cache Efficiency', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo ($stats['cache_efficiency'] ?? 0); ?>%</strong>
                                    <?php if (($stats['cache_efficiency'] ?? 0) >= 70): ?>
                                        <span class="alo-status-good">🚀 Excellent</span>
                                    <?php elseif (($stats['cache_efficiency'] ?? 0) >= 40): ?>
                                        <span class="alo-status-warning">⚡ Good</span>
                                    <?php else: ?>
                                        <span class="alo-status-bad">⚠ Needs Improvement</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Table Efficiency', 'attachment-lookup-optimizer'); ?></td>
                                <td>
                                    <strong><?php echo ($stats['table_efficiency'] ?? 0); ?>%</strong>
                                    <?php if (($stats['table_efficiency'] ?? 0) > 0): ?>
                                        <span class="alo-status-good">✓ Active</span>
                                    <?php else: ?>
                                        <span class="alo-status-neutral">— Not Used</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php _e('Tracking Started', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo ($stats['first_lookup_time'] ?? null) ? 
                                    human_time_diff(strtotime($stats['first_lookup_time'])) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                    __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Last Activity', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo ($stats['last_lookup_time'] ?? null) ? 
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
                                <td><?php echo ($stats['first_entry_time'] ?? null) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stats['first_entry_time'])) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Latest Log Entry', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo ($stats['last_entry_time'] ?? null) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stats['last_entry_time'])) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Total Log Size', 'attachment-lookup-optimizer'); ?></td>
                                <td><?php echo $this->format_bytes($stats['total_log_size'] ?? 0); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Clear Stats Action -->
                <div class="alo-actions-section">
                    <h3><?php _e('Log Management', 'attachment-lookup-optimizer'); ?></h3>
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('alo_clear_stats', 'alo_nonce_clear_stats', true, true); ?>
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
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_clear_watchlist'); ?>
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
                
                <div class="alo-stats-card">
                    <h3><?php _e('Shared Stats Cache (Performance)', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <?php 
                        $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
                        $shared_cache = $plugin->get_shared_stats_cache();
                        $shared_cache_status = $shared_cache ? $shared_cache->get_cache_status() : null;
                        ?>
                        <tr>
                            <td><?php _e('Purpose', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php _e('Prevents duplicate queries between DatabaseManager and UploadPreprocessor', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Attachment Count Cache', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($shared_cache_status && $shared_cache_status['attachment_count_cached']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Cached', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Not Cached', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Postmeta Count Cache', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($shared_cache_status && $shared_cache_status['postmeta_count_cached']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Cached', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Not Cached', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Shared Stats Cache', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($shared_cache_status && $shared_cache_status['shared_stats_cached']): ?>
                                    <span class="alo-status-good">✓ <?php _e('Cached', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span class="alo-status-warning">⚠ <?php _e('Not Cached', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Request Cache Items', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if ($shared_cache_status): ?>
                                    <?php echo number_format_i18n($shared_cache_status['request_cache_size']); ?>
                                    <?php if ($shared_cache_status['request_cache_size'] > 0): ?>
                                        <span class="alo-status-good">✓</span>
                                        <br><small style="color: #666;">
                                            <?php echo esc_html(implode(', ', $shared_cache_status['request_cache_items'])); ?>
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('Not available', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Performance Impact', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <span class="alo-status-good">⚡ <?php _e('Eliminates duplicate slow queries (70-130ms saved per duplicate)', 'attachment-lookup-optimizer'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Duration', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php _e('15 minutes (900 seconds)', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Clear Shared Cache', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <button type="button" id="clear-shared-cache-btn" class="button">
                                    <?php _e('Clear Shared Cache', 'attachment-lookup-optimizer'); ?>
                                </button>
                                <div id="shared-cache-clear-results" style="margin-top: 10px; display: none;"></div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Actions Section -->
            <div class="alo-actions-section" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3><?php _e('Cache & Performance Actions', 'attachment-lookup-optimizer'); ?></h3>
                
                <div class="alo-actions-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                    
                    <!-- Quick Setup -->
                    <div class="alo-action-group">
                        <h4><?php _e('Quick Setup', 'attachment-lookup-optimizer'); ?></h4>
                        <form method="post" style="margin-bottom: 10px;">
                            <?php wp_nonce_field('alo_quick_setup', 'alo_nonce_quick_setup'); ?>
                            <input type="hidden" name="alo_quick_setup" value="1">
                            <button type="submit" class="button button-primary button-large" style="width: 100%; padding: 10px;">
                                🚀 <?php _e('Enable All Features & Create Table', 'attachment-lookup-optimizer'); ?>
                            </button>
                            <p class="description">
                                <?php _e('One-click setup: enables custom lookup table, caching, JetEngine optimization, and debug logging.', 'attachment-lookup-optimizer'); ?>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Cache Management -->
                    <div class="alo-action-group">
                        <h4><?php _e('Cache Management', 'attachment-lookup-optimizer'); ?></h4>
                        
                        <form method="post" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_clear_cache'); ?>
                            <input type="submit" name="alo_clear_cache" class="button button-secondary" 
                                   value="<?php _e('Clear All Cache', 'attachment-lookup-optimizer'); ?>">
                        </form>
                        
                        <div class="alo-progress-container" id="warm-cache-container" style="display: inline-block; margin-right: 10px;">
                            <button type="button" class="button button-secondary" id="alo-warm-cache-btn">
                                <?php _e('🔥 Warm Cache (Latest First)', 'attachment-lookup-optimizer'); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="alo-warm-cache-stop" style="display: none;">
                                <?php _e('🛑 Stop Warming', 'attachment-lookup-optimizer'); ?>
                            </button>
                            
                            <div class="alo-progress-wrapper" id="warm-cache-progress" style="display: none; margin-top: 10px;">
                                <div class="alo-progress-bar">
                                    <div class="alo-progress-fill" id="warm-cache-fill"></div>
                                    <div class="alo-progress-text" id="warm-cache-text">0%</div>
                                </div>
                                <div class="alo-progress-status" id="warm-cache-status">Preparing...</div>
                            </div>
                        </div>
                        
                        <form method="post" style="display: inline-block;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_test_cache'); ?>
                            <input type="submit" name="alo_test_cache" class="button button-secondary" 
                                   value="<?php _e('Test Cache Performance', 'attachment-lookup-optimizer'); ?>">
                        </form>
                        
                        <p class="description">
                            <?php _e('Clear cached attachment lookups, warm the cache with the latest items, or test cache performance.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    </div>
                    
                    <!-- Database Management -->
                    <div class="alo-action-group">
                        <h4><?php _e('Database & Table Management', 'attachment-lookup-optimizer'); ?></h4>
                        
                        <?php if ($this->custom_lookup_table && get_option('alo_custom_lookup_table', false)): ?>
                        <form method="post" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_rebuild_table'); ?>
                            <input type="submit" name="alo_rebuild_table" class="button button-primary" 
                                   value="<?php _e('Rebuild Custom Table', 'attachment-lookup-optimizer'); ?>"
                                   onclick="return confirm('<?php _e('This will rebuild the custom lookup table. Continue?', 'attachment-lookup-optimizer'); ?>');">
                        </form>
                        
                        <form method="post" style="display: inline-block; margin-bottom: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_cleanup_duplicates'); ?>
                            <input type="submit" name="alo_cleanup_duplicates" class="button button-secondary" 
                                   value="<?php _e('Cleanup Duplicate Entries', 'attachment-lookup-optimizer'); ?>"
                                   onclick="return confirm('<?php _e('This will remove duplicate file path entries. Continue?', 'attachment-lookup-optimizer'); ?>');">
                        </form>
                        <?php else: ?>
                        <p style="color: #666; font-style: italic;">
                            <?php _e('Custom lookup table is disabled. Enable it in the settings above to see table management options.', 'attachment-lookup-optimizer'); ?>
                        </p>
                        <?php endif; ?>
                        
                        <form method="post" style="display: inline-block; margin-top: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_clear_query_stats'); ?>
                            <input type="submit" name="alo_clear_query_stats" class="button button-secondary" 
                                   value="<?php _e('Clear Query Statistics', 'attachment-lookup-optimizer'); ?>">
                        </form>
                        
                        <p class="description">
                            <?php _e('Manage the custom lookup table and database query statistics.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    </div>
                    
                    <!-- Upload Processing -->
                    <div class="alo-action-group">
                        <h4><?php _e('Upload Processing', 'attachment-lookup-optimizer'); ?></h4>
                        
                        <?php if ($this->upload_preprocessor): ?>
                        <div class="alo-progress-container" id="bulk-process-container">
                            <button type="button" class="button button-secondary" id="alo-bulk-process-btn">
                                <?php _e('🚀 Bulk Process Attachments (Latest First)', 'attachment-lookup-optimizer'); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="alo-bulk-process-stop" style="display: none;">
                                <?php _e('🛑 Stop Processing', 'attachment-lookup-optimizer'); ?>
                            </button>
                            
                            <div class="alo-progress-wrapper" id="bulk-process-progress" style="display: none; margin-top: 10px;">
                                <div class="alo-progress-bar">
                                    <div class="alo-progress-fill" id="bulk-process-fill"></div>
                                    <div class="alo-progress-text" id="bulk-process-text">0%</div>
                                </div>
                                <div class="alo-progress-status" id="bulk-process-status">Preparing...</div>
                            </div>
                        </div>
                        <?php else: ?>
                        <p style="color: #666; font-style: italic;">
                            <?php _e('Upload preprocessor not available.', 'attachment-lookup-optimizer'); ?>
                        </p>
                        <?php endif; ?>
                        
                        <p class="description">
                            <?php _e('Process existing attachments for faster lookups, starting with the most recent uploads.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    </div>
                    
                    <!-- Transient Management -->
                    <div class="alo-action-group">
                        <h4><?php _e('Transient Management', 'attachment-lookup-optimizer'); ?></h4>
                        
                        <?php if ($this->transient_manager): ?>
                        <form method="post" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_cleanup_transients'); ?>
                            <input type="submit" name="alo_cleanup_transients" class="button button-secondary" 
                                   value="<?php _e('Cleanup Expired Transients', 'attachment-lookup-optimizer'); ?>">
                        </form>
                        
                        <form method="post" style="display: inline-block;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_purge_transients'); ?>
                            <input type="submit" name="alo_purge_transients" class="button button-secondary" 
                                   value="<?php _e('Purge All Plugin Transients', 'attachment-lookup-optimizer'); ?>"
                                   onclick="return confirm('<?php _e('This will remove ALL plugin transients. Continue?', 'attachment-lookup-optimizer'); ?>');">
                        </form>
                        <?php else: ?>
                        <p style="color: #666; font-style: italic;">
                            <?php _e('Transient manager not available.', 'attachment-lookup-optimizer'); ?>
                        </p>
                        <?php endif; ?>
                        
                        <p class="description">
                            <?php _e('Manage plugin transients and cleanup expired entries.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    </div>
                    
                    <!-- Debug & Logging -->
                    <div class="alo-action-group">
                        <h4><?php _e('Debug & Logging', 'attachment-lookup-optimizer'); ?></h4>
                        
                        <form method="post" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_clear_debug_logs'); ?>
                            <input type="submit" name="alo_clear_debug_logs" class="button button-secondary" 
                                   value="<?php _e('Clear Debug Logs', 'attachment-lookup-optimizer'); ?>"
                                   onclick="return confirm('<?php _e('This will delete all debug log files. Continue?', 'attachment-lookup-optimizer'); ?>');">
                        </form>
                        
                        <form method="post" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_force_cleanup'); ?>
                            <input type="submit" name="alo_force_cleanup" class="button button-secondary" 
                                   value="<?php _e('Force Log Cleanup', 'attachment-lookup-optimizer'); ?>">
                        </form>
                        
                        <form method="post" style="display: inline-block;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_clear_archived_logs'); ?>
                            <input type="submit" name="alo_clear_archived_logs" class="button button-secondary" 
                                   value="<?php _e('Clear Archived Logs', 'attachment-lookup-optimizer'); ?>"
                                   onclick="return confirm('<?php _e('This will delete all archived log files. Continue?', 'attachment-lookup-optimizer'); ?>');">
                        </form>
                        
                        <p class="description">
                            <?php _e('Manage debug logs and cleanup old log files.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    </div>
                    
                    <!-- Testing Tools -->
                    <div class="alo-action-group">
                        <h4><?php _e('Testing Tools', 'attachment-lookup-optimizer'); ?></h4>
                        
                        <form method="post" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('alo_actions', 'alo_nonce_test_batch'); ?>
                            <input type="submit" name="alo_test_batch" class="button button-secondary" 
                                   value="<?php _e('Run Batch Test', 'attachment-lookup-optimizer'); ?>">
                        </form>
                        
                        <p class="description">
                            <?php _e('Test batch lookup performance with random attachment URLs.', 'attachment-lookup-optimizer'); ?>
                        </p>
                        
                        <p style="margin-top: 15px;">
                            <a href="<?php echo admin_url('tools.php?page=attachment-lookup-debug'); ?>" class="button button-primary">
                                <?php _e('🔧 Advanced Debug Tools', 'attachment-lookup-optimizer'); ?>
                            </a>
                        </p>
                        <p class="description">
                            <?php _e('Access advanced debugging and URL testing tools.', 'attachment-lookup-optimizer'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .alo-actions-section h3 {
            margin-top: 0;
            color: #23282d;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .alo-action-group {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .alo-action-group h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #135e96;
        }
        .alo-action-group .button {
            margin-bottom: 5px;
        }
        .alo-action-group .description {
            margin-top: 10px;
            font-style: italic;
            color: #666;
        }
        </style>
        
        <style>
            /* Progress bars */
            .alo-progress-container {
                margin: 10px 0;
            }
            
            .alo-progress-wrapper {
                margin-top: 10px;
                padding: 10px;
                background: #f8f9fa;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .alo-progress-bar {
                position: relative;
                width: 100%;
                height: 25px;
                background: #e9ecef;
                border-radius: 12px;
                overflow: hidden;
                margin-bottom: 8px;
            }
            
            .alo-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #28a745, #20c997);
                width: 0%;
                transition: width 0.3s ease;
                border-radius: 12px;
            }
            
            .alo-progress-text {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                color: #000;
                font-weight: 600;
                font-size: 12px;
                text-shadow: 0 0 3px rgba(255,255,255,0.8);
            }
            
            .alo-progress-status {
                font-size: 13px;
                color: #495057;
                text-align: center;
            }
            
            #alo-bulk-process-stop, #alo-warm-cache-stop {
                background: #dc3545;
                border-color: #dc3545;
                color: white;
                margin-left: 10px;
            }
            
            #alo-bulk-process-stop:hover, #alo-warm-cache-stop:hover {
                background: #c82333;
                border-color: #bd2130;
            }
        </style>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                let bulkProcessing = false;
                let cacheWarming = false;
                
                // Bulk Process Attachments with Progress
                $('#alo-bulk-process-btn').on('click', function() {
                    if (bulkProcessing) return;
                    
                    if (!confirm('This will process all attachments starting with the most recent. Continue?')) {
                        return;
                    }
                    
                    bulkProcessing = true;
                    $('#alo-bulk-process-btn').prop('disabled', true);
                    $('#alo-bulk-process-stop').show();
                    $('#bulk-process-progress').show();
                    
                    processBulkAttachments(0);
                });
                
                // Stop Bulk Processing
                $('#alo-bulk-process-stop').on('click', function() {
                    bulkProcessing = false;
                    $.post(ajaxurl, {
                        action: 'alo_bulk_process_attachments',
                        bulk_action: 'stop',
                        nonce: '<?php echo wp_create_nonce('alo_bulk_process'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $('#bulk-process-status').text(response.data.message);
                            resetBulkProcessUI();
                        }
                    });
                });
                
                function processBulkAttachments(offset) {
                    if (!bulkProcessing) return;
                    
                    console.log('ALO: Starting bulk process with offset:', offset);
                    
                    $.post(ajaxurl, {
                        action: 'alo_bulk_process_attachments',
                        bulk_action: offset === 0 ? 'start' : 'continue',
                        offset: offset,
                        nonce: '<?php echo wp_create_nonce('alo_bulk_process'); ?>'
                    }, function(response) {
                        console.log('ALO: Received response:', response);
                        
                        if (response.success) {
                            const data = response.data;
                            console.log('ALO: Processing response data:', data);
                            
                            // Validate data before using it
                            const progress = typeof data.progress !== 'undefined' ? data.progress : 0;
                            const message = typeof data.message !== 'undefined' ? data.message : 'Processing...';
                            
                            // Update progress
                            $('#bulk-process-fill').css('width', progress + '%');
                            $('#bulk-process-text').text(progress + '%');
                            $('#bulk-process-status').text(message);
                            
                            console.log('ALO: Updated progress to:', progress + '%');
                            
                            if (data.is_complete || !bulkProcessing) {
                                resetBulkProcessUI();
                                if (data.is_complete) {
                                    $('#bulk-process-status').text('✅ Processing complete! ' + message);
                                }
                            } else {
                                // Continue processing
                                setTimeout(() => processBulkAttachments(data.offset), 500);
                            }
                        } else {
                            console.error('ALO: Bulk processing error:', response);
                            resetBulkProcessUI();
                            const errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error';
                            $('#bulk-process-status').text('❌ Error: ' + errorMessage);
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('ALO: AJAX request failed:', {xhr: xhr, status: status, error: error});
                        resetBulkProcessUI();
                        $('#bulk-process-status').text('❌ Network error: ' + error);
                    });
                }
                
                function resetBulkProcessUI() {
                    bulkProcessing = false;
                    $('#alo-bulk-process-btn').prop('disabled', false);
                    $('#alo-bulk-process-stop').hide();
                }
                
                // Warm Cache with Progress
                $('#alo-warm-cache-btn').on('click', function() {
                    if (cacheWarming) return;
                    
                    if (!confirm('This will warm the cache for all attachments starting with the most recent. Continue?')) {
                        return;
                    }
                    
                    cacheWarming = true;
                    $('#alo-warm-cache-btn').prop('disabled', true);
                    $('#alo-warm-cache-stop').show();
                    $('#warm-cache-progress').show();
                    
                    warmCacheProgress(0);
                });
                
                // Stop Cache Warming
                $('#alo-warm-cache-stop').on('click', function() {
                    cacheWarming = false;
                    $.post(ajaxurl, {
                        action: 'alo_warm_cache_progress',
                        cache_action: 'stop',
                        nonce: '<?php echo wp_create_nonce('alo_warm_cache'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $('#warm-cache-status').text(response.data.message);
                            resetCacheWarmUI();
                        }
                    });
                });
                
                function warmCacheProgress(offset) {
                    if (!cacheWarming) return;
                    
                    $.post(ajaxurl, {
                        action: 'alo_warm_cache_progress',
                        cache_action: offset === 0 ? 'start' : 'continue',
                        offset: offset,
                        nonce: '<?php echo wp_create_nonce('alo_warm_cache'); ?>'
                    }, function(response) {
                        if (response.success) {
                            const data = response.data;
                            
                            // Update progress
                            $('#warm-cache-fill').css('width', data.progress + '%');
                            $('#warm-cache-text').text(data.progress + '%');
                            $('#warm-cache-status').text(data.message);
                            
                            if (data.is_complete || !cacheWarming) {
                                resetCacheWarmUI();
                                if (data.is_complete) {
                                    $('#warm-cache-status').text('✅ Cache warming complete! ' + data.message);
                                }
                            } else {
                                // Continue warming
                                setTimeout(() => warmCacheProgress(data.offset), 500);
                            }
                        } else {
                            console.error('Cache warming error:', response.data);
                            resetCacheWarmUI();
                            $('#warm-cache-status').text('❌ Error: ' + (response.data.message || 'Unknown error'));
                        }
                    }).fail(function() {
                        resetCacheWarmUI();
                        $('#warm-cache-status').text('❌ Network error occurred');
                    });
                }
                
                function resetCacheWarmUI() {
                    cacheWarming = false;
                    $('#alo-warm-cache-btn').prop('disabled', false);
                    $('#alo-warm-cache-stop').hide();
                }
                
                // Force Create Custom Table
                $('#alo-force-create-table-btn').on('click', function() {
                    if (!confirm('This will force recreation of the custom lookup table. Continue?')) {
                        return;
                    }
                    
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('Creating...');
                    
                    $.post(ajaxurl, {
                        action: 'alo_force_create_table',
                        nonce: '<?php echo wp_create_nonce('alo_debug'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('✅ Custom table creation completed!\n\n' + response.data.message);
                            location.reload(); // Refresh to see updated stats
                        } else {
                            alert('❌ Error: ' + (response.data.message || 'Unknown error'));
                        }
                    }).fail(function() {
                        alert('❌ Network error occurred');
                    }).always(function() {
                        $btn.prop('disabled', false).text('Force Create Custom Table');
                    });
                });
                
                // Reclaim Disk Space functionality
                let reclaimProcessing = false;
                let totalSpaceReclaimed = 0;
                let totalProcessed = 0;
                let totalSuccessful = 0;
                let totalFailed = 0;
                
                $('#reclaim-disk-space-btn').on('click', function() {
                    if (reclaimProcessing) return;
                    
                    if (!confirm('⚠️ WARNING: This will permanently delete local files from your server.\\n\\nMake sure your BunnyCDN integration is working correctly before proceeding.\\n\\nThis action cannot be undone. Continue?')) {
                        return;
                    }
                    
                    reclaimProcessing = true;
                    totalSpaceReclaimed = 0;
                    totalProcessed = 0;
                    totalSuccessful = 0;
                    totalFailed = 0;
                    
                    $('#reclaim-disk-space-btn').prop('disabled', true);
                    $('#reclaim-stop-btn').show();
                    $('#reclaim-progress-container').show();
                    $('#reclaim-results').hide();
                    $('#reclaim-status').text('Starting disk space reclaim process...');
                    
                    processReclaimDiskSpace(0);
                });
                
                $('#reclaim-stop-btn').on('click', function() {
                    reclaimProcessing = false;
                    $('#reclaim-status').text('Stopping reclaim process...');
                    resetReclaimUI();
                });
                
                function processReclaimDiskSpace(offset) {
                    if (!reclaimProcessing) return;
                    
                    $.post(ajaxurl, {
                        action: 'alo_reclaim_disk_space',
                        action_type: offset === 0 ? 'start' : 'continue',
                        offset: offset,
                        batch_size: <?php echo min(get_option(self::BUNNYCDN_BATCH_SIZE_OPTION, 25), 25); ?>,
                        nonce: '<?php echo wp_create_nonce('alo_actions'); ?>'
                    }, function(response) {
                        if (response.success) {
                            const data = response.data;
                            
                            // Update totals
                            totalProcessed += data.processed;
                            totalSuccessful += data.successful;
                            totalFailed += data.failed;
                            totalSpaceReclaimed += data.space_reclaimed;
                            
                            // Update progress
                            $('#reclaim-progress-bar').css('width', data.progress + '%');
                            $('#reclaim-progress-label').text(data.progress + '% - ' + data.message);
                            $('#reclaim-status').html(
                                'Processing... <strong>Total:</strong> ' + totalProcessed + 
                                ' | <strong>Success:</strong> ' + totalSuccessful + 
                                ' | <strong>Failed:</strong> ' + totalFailed + 
                                ' | <strong>Space Reclaimed:</strong> ' + formatBytes(totalSpaceReclaimed)
                            );
                            
                            if (data.is_complete || !reclaimProcessing) {
                                resetReclaimUI();
                                showReclaimResults();
                                if (data.is_complete) {
                                    $('#reclaim-status').html('✅ <strong>Reclaim process completed!</strong>');
                                }
                            } else {
                                // Continue processing
                                setTimeout(() => processReclaimDiskSpace(data.offset), 1000);
                            }
                        } else {
                            console.error('Reclaim error:', response);
                            resetReclaimUI();
                            const errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error';
                            $('#reclaim-status').html('❌ <strong>Error:</strong> ' + errorMessage);
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('Reclaim AJAX failed:', {xhr: xhr, status: status, error: error});
                        resetReclaimUI();
                        $('#reclaim-status').html('❌ <strong>Network error:</strong> ' + error);
                    });
                }
                
                function resetReclaimUI() {
                    reclaimProcessing = false;
                    $('#reclaim-disk-space-btn').prop('disabled', false);
                    $('#reclaim-stop-btn').hide();
                }
                
                function showReclaimResults() {
                    let resultsHtml = '<p><strong>Final Results:</strong></p>';
                    resultsHtml += '<ul>';
                    resultsHtml += '<li><strong>Total Files Processed:</strong> ' + totalProcessed + '</li>';
                    resultsHtml += '<li><strong>Successfully Reclaimed:</strong> ' + totalSuccessful + '</li>';
                    resultsHtml += '<li><strong>Failed:</strong> ' + totalFailed + '</li>';
                    resultsHtml += '<li><strong>Total Disk Space Reclaimed:</strong> ' + formatBytes(totalSpaceReclaimed) + '</li>';
                    resultsHtml += '</ul>';
                    
                    if (totalFailed > 0) {
                        resultsHtml += '<p style="color: #d63638;"><strong>Note:</strong> Some files could not be reclaimed. Check the error logs for details.</p>';
                    }
                    
                    $('#reclaim-results-content').html(resultsHtml);
                    $('#reclaim-results').show();
                }
                
                function formatBytes(bytes) {
                    if (bytes === 0) return '0 Bytes';
                    const k = 1024;
                    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                }
                
                // Reset Offload Status functionality
                let resetProcessing = false;
                let totalResetCounts = {
                    offload_status: 0,
                    cdn_urls: 0,
                    timestamps: 0
                };
                let totalResetProcessed = 0;
                let totalResetSuccessful = 0;
                let totalResetFailed = 0;
                
                $('#reset-offload-status-btn').on('click', function() {
                    if (resetProcessing) return;
                    
                    // Get selected options
                    const resetOffloadStatus = $('#reset-offload-status').is(':checked');
                    const resetCdnUrls = $('#reset-cdn-urls').is(':checked');
                    const resetTimestamps = $('#reset-offload-timestamps').is(':checked');
                    const dateFrom = $('#reset-date-from').val();
                    const dateTo = $('#reset-date-to').val();
                    
                    if (!resetOffloadStatus && !resetCdnUrls && !resetTimestamps) {
                        alert('Please select at least one reset option.');
                        return;
                    }
                    
                    let confirmMessage = '⚠️ WARNING: This will reset the offload status for media attachments.\\n\\n';
                    confirmMessage += 'Selected options:\\n';
                    if (resetOffloadStatus) confirmMessage += '- Reset offload status\\n';
                    if (resetCdnUrls) confirmMessage += '- Reset CDN URLs\\n';
                    if (resetTimestamps) confirmMessage += '- Reset timestamps\\n';
                    if (dateFrom || dateTo) {
                        confirmMessage += '\\nDate range: ' + (dateFrom || 'any') + ' to ' + (dateTo || 'any') + '\\n';
                    }
                    confirmMessage += '\\nThis may cause files to be re-processed by sync operations.\\n\\nContinue?';
                    
                    if (!confirm(confirmMessage)) {
                        return;
                    }
                    
                    resetProcessing = true;
                    totalResetCounts = { offload_status: 0, cdn_urls: 0, timestamps: 0 };
                    totalResetProcessed = 0;
                    totalResetSuccessful = 0;
                    totalResetFailed = 0;
                    
                    $('#reset-offload-status-btn').prop('disabled', true);
                    $('#reset-offload-stop-btn').show();
                    $('#reset-progress-container').show();
                    $('#reset-results').hide();
                    $('#reset-status').text('Starting offload status reset process...');
                    
                    processResetOffloadStatus(0, {
                        resetOffloadStatus,
                        resetCdnUrls,
                        resetTimestamps,
                        dateFrom,
                        dateTo
                    });
                });
                
                $('#reset-offload-stop-btn').on('click', function() {
                    resetProcessing = false;
                    $('#reset-status').text('Stopping reset process...');
                    resetResetUI();
                });
                
                function processResetOffloadStatus(offset, options) {
                    if (!resetProcessing) return;
                    
                    $.post(ajaxurl, {
                        action: 'alo_reset_offload_status',
                        action_type: offset === 0 ? 'start' : 'continue',
                        offset: offset,
                        batch_size: <?php echo max(get_option(self::BUNNYCDN_BATCH_SIZE_OPTION, 25), 25); ?>,
                        reset_offload_status: options.resetOffloadStatus,
                        reset_cdn_urls: options.resetCdnUrls,
                        reset_timestamps: options.resetTimestamps,
                        date_from: options.dateFrom,
                        date_to: options.dateTo,
                        nonce: '<?php echo wp_create_nonce('alo_actions'); ?>'
                    }, function(response) {
                        if (response.success) {
                            const data = response.data;
                            
                            // Update totals
                            totalResetProcessed += data.processed;
                            totalResetSuccessful += data.successful;
                            totalResetFailed += data.failed;
                            totalResetCounts.offload_status += data.reset_counts.offload_status;
                            totalResetCounts.cdn_urls += data.reset_counts.cdn_urls;
                            totalResetCounts.timestamps += data.reset_counts.timestamps;
                            
                            // Update progress
                            $('#reset-progress-bar').css('width', data.progress + '%');
                            $('#reset-progress-label').text(data.progress + '% - ' + data.message);
                            $('#reset-status').html(
                                'Processing... <strong>Total:</strong> ' + totalResetProcessed + 
                                ' | <strong>Success:</strong> ' + totalResetSuccessful + 
                                ' | <strong>Failed:</strong> ' + totalResetFailed + 
                                ' | <strong>Reset:</strong> ' + totalResetCounts.offload_status + ' offload, ' + 
                                totalResetCounts.cdn_urls + ' URLs, ' + totalResetCounts.timestamps + ' timestamps'
                            );
                            
                            if (data.is_complete || !resetProcessing) {
                                resetResetUI();
                                showResetResults();
                                if (data.is_complete) {
                                    $('#reset-status').html('✅ <strong>Reset process completed!</strong>');
                                }
                            } else {
                                // Continue processing
                                setTimeout(() => processResetOffloadStatus(data.offset, options), 500);
                            }
                        } else {
                            console.error('Reset error:', response);
                            resetResetUI();
                            const errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error';
                            $('#reset-status').html('❌ <strong>Error:</strong> ' + errorMessage);
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('Reset AJAX failed:', {xhr: xhr, status: status, error: error});
                        resetResetUI();
                        $('#reset-status').html('❌ <strong>Network error:</strong> ' + error);
                    });
                }
                
                function resetResetUI() {
                    resetProcessing = false;
                    $('#reset-offload-status-btn').prop('disabled', false);
                    $('#reset-offload-stop-btn').hide();
                }
                
                function showResetResults() {
                    let resultsHtml = '<p><strong>Final Results:</strong></p>';
                    resultsHtml += '<ul>';
                    resultsHtml += '<li><strong>Total Attachments Processed:</strong> ' + totalResetProcessed + '</li>';
                    resultsHtml += '<li><strong>Successfully Reset:</strong> ' + totalResetSuccessful + '</li>';
                    resultsHtml += '<li><strong>Failed:</strong> ' + totalResetFailed + '</li>';
                    resultsHtml += '<li><strong>Offload Status Reset:</strong> ' + totalResetCounts.offload_status + '</li>';
                    resultsHtml += '<li><strong>CDN URLs Reset:</strong> ' + totalResetCounts.cdn_urls + '</li>';
                    resultsHtml += '<li><strong>Timestamps Reset:</strong> ' + totalResetCounts.timestamps + '</li>';
                    resultsHtml += '</ul>';
                    
                    if (totalResetFailed > 0) {
                        resultsHtml += '<p style="color: #d63638;"><strong>Note:</strong> Some attachments could not be reset. Check the error logs for details.</p>';
                    }
                    
                    resultsHtml += '<p style="color: #0073aa;"><strong>Next Steps:</strong> You can now re-run bulk upload/sync operations to re-process these attachments.</p>';
                    
                    $('#reset-results-content').html(resultsHtml);
                    $('#reset-results').show();
                }
                
                // Content URL Rewriting functionality
                let contentRewriteProcessing = false;
                let totalContentProcessed = 0;
                let totalContentUpdated = 0;
                let totalContentSkipped = 0;
                let totalContentReplacements = 0;
                
                $('#rewrite-content-urls-btn').on('click', function() {
                    if (contentRewriteProcessing) return;
                    
                    if (!confirm('This will scan all posts and pages to replace local image URLs with BunnyCDN URLs.\\n\\nThis process may take some time for sites with many posts.\\n\\nContinue?')) {
                        return;
                    }
                    
                    contentRewriteProcessing = true;
                    totalContentProcessed = 0;
                    totalContentUpdated = 0;
                    totalContentSkipped = 0;
                    totalContentReplacements = 0;
                    
                    // Show top progress bar
                    $('#bunnycdn-rewrite-progress-container').show();
                    $('#bunnycdn-rewrite-progress-bar').css('width', '0%');
                    $('#bunnycdn-rewrite-progress-percent').text('0%');
                    $('#bunnycdn-rewrite-progress-label').text('Starting content URL rewriting process...');
                    $('#bunnycdn-rewrite-status-text').text('Initializing');
                    $('#bunnycdn-rewrite-current-post').text('-');
                    $('#bunnycdn-rewrite-progress-stats').text('0 / 0 posts processed');
                    
                    // Update tool section
                    $('#rewrite-content-urls-btn').prop('disabled', true);
                    $('#rewrite-content-stop-btn').show();
                    $('#rewrite-content-progress-container').show();
                    $('#rewrite-content-results').hide();
                    $('#rewrite-content-status').text('Starting content URL rewriting process...');
                    
                    // Scroll to top progress bar
                    $('html, body').animate({
                        scrollTop: $('#bunnycdn-rewrite-progress-container').offset().top - 20
                    }, 500);
                    
                    processContentRewrite(0);
                });
                
                $('#rewrite-content-stop-btn, #bunnycdn-rewrite-cancel-btn').on('click', function() {
                    contentRewriteProcessing = false;
                    $('#rewrite-content-status').text('Stopping content rewrite process...');
                    $('#bunnycdn-rewrite-status-text').text('Cancelling...');
                    $('#bunnycdn-rewrite-progress-label').text('Process cancelled by user');
                    resetContentRewriteUI();
                });
                
                function processContentRewrite(offset) {
                    if (!contentRewriteProcessing) return;
                    
                    // Get selected post type
                    const selectedPostType = $('#rewrite-post-type-filter').val() || 'all';
                    
                    $.post(ajaxurl, {
                        action: 'alo_rewrite_content_urls',
                        action_type: offset === 0 ? 'start' : 'continue',
                        offset: offset,
                        batch_size: 15, // Process 15 posts at a time for better performance
                        post_type: selectedPostType,
                        nonce: '<?php echo wp_create_nonce('alo_actions'); ?>'
                    }, function(response) {
                        if (response.success) {
                            const data = response.data;
                            
                            // Update totals
                            totalContentProcessed += data.processed;
                            totalContentUpdated += data.updated;
                            totalContentSkipped += data.skipped;
                            totalContentReplacements += data.total_replacements;
                            
                            // Update top progress bar
                            $('#bunnycdn-rewrite-progress-bar').css('width', data.progress + '%');
                            $('#bunnycdn-rewrite-progress-percent').text(data.progress + '%');
                            $('#bunnycdn-rewrite-progress-label').text(data.message);
                            
                            // Update detailed progress info
                            $('#bunnycdn-rewrite-status-text').text('Processing batch ' + Math.ceil(data.offset / data.batch_size));
                            if (data.current_post) {
                                $('#bunnycdn-rewrite-current-post').text('#' + data.current_post.id + ' - ' + data.current_post.title + ' (' + data.current_post.type + ')');
                            }
                            $('#bunnycdn-rewrite-progress-stats').text(data.offset + ' / ' + data.total_posts + ' posts processed');
                            
                            // Update tool section progress
                            $('#rewrite-content-progress-bar').css('width', data.progress + '%');
                            $('#rewrite-content-progress-label').text(data.progress + '% - ' + data.message);
                            $('#rewrite-content-status').html(
                                'Processing... <strong>Total:</strong> ' + totalContentProcessed + 
                                ' | <strong>Updated:</strong> ' + totalContentUpdated + 
                                ' | <strong>Skipped:</strong> ' + totalContentSkipped + 
                                ' | <strong>URL Replacements:</strong> ' + totalContentReplacements
                            );
                            
                            if (data.is_complete || !contentRewriteProcessing) {
                                resetContentRewriteUI();
                                showContentRewriteResults();
                                if (data.is_complete) {
                                    // Update top progress bar for completion
                                    $('#bunnycdn-rewrite-progress-bar').css('width', '100%');
                                    $('#bunnycdn-rewrite-progress-percent').text('100%');
                                    $('#bunnycdn-rewrite-progress-label').text('✅ Content URL rewriting completed successfully!');
                                    $('#bunnycdn-rewrite-status-text').text('Completed');
                                    $('#bunnycdn-rewrite-current-post').text('All posts processed');
                                    
                                    $('#rewrite-content-status').html('✅ <strong>Content URL rewriting completed!</strong>');
                                    
                                    // Hide top progress bar after 5 seconds
                                    setTimeout(() => {
                                        $('#bunnycdn-rewrite-progress-container').fadeOut(1000);
                                    }, 5000);
                                }
                            } else {
                                // Continue processing with a shorter delay for better UX
                                setTimeout(() => processContentRewrite(data.offset), 500);
                            }
                        } else {
                            console.error('Content rewrite error:', response);
                            resetContentRewriteUI();
                            const errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error';
                            
                            // Update top progress bar for error
                            $('#bunnycdn-rewrite-progress-label').text('❌ Error: ' + errorMessage);
                            $('#bunnycdn-rewrite-status-text').text('Error occurred');
                            
                            $('#rewrite-content-status').html('❌ <strong>Error:</strong> ' + errorMessage);
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('Content rewrite AJAX failed:', {xhr: xhr, status: status, error: error});
                        resetContentRewriteUI();
                        
                        // Update top progress bar for network error
                        $('#bunnycdn-rewrite-progress-label').text('❌ Network error: ' + error);
                        $('#bunnycdn-rewrite-status-text').text('Network error');
                        
                        $('#rewrite-content-status').html('❌ <strong>Network error:</strong> ' + error);
                    });
                }
                
                function resetContentRewriteUI() {
                    contentRewriteProcessing = false;
                    $('#rewrite-content-urls-btn').prop('disabled', false);
                    $('#rewrite-content-stop-btn').hide();
                    
                    // Hide top progress bar after a delay if not completed successfully
                    setTimeout(() => {
                        if (!contentRewriteProcessing) {
                            $('#bunnycdn-rewrite-progress-container').fadeOut(1000);
                        }
                    }, 3000);
                }
                
                function showContentRewriteResults() {
                    let resultsHtml = '<p><strong>Final Results:</strong></p>';
                    resultsHtml += '<ul>';
                    resultsHtml += '<li><strong>Total Posts/Pages Processed:</strong> ' + totalContentProcessed + '</li>';
                    resultsHtml += '<li><strong>Posts/Pages Updated:</strong> ' + totalContentUpdated + '</li>';
                    resultsHtml += '<li><strong>Posts/Pages Skipped:</strong> ' + totalContentSkipped + '</li>';
                    resultsHtml += '<li><strong>Total URL Replacements:</strong> ' + totalContentReplacements + '</li>';
                    resultsHtml += '</ul>';
                    
                    if (totalContentUpdated > 0) {
                        resultsHtml += '<p style="color: #00a32a;"><strong>Success!</strong> ' + totalContentUpdated + ' posts/pages have been updated with BunnyCDN URLs.</p>';
                    }
                    
                    if (totalContentSkipped > 0) {
                        resultsHtml += '<p style="color: #f56e28;"><strong>Note:</strong> ' + totalContentSkipped + ' posts/pages were skipped (no matching CDN URLs found or already processed).</p>';
                    }
                    
                    resultsHtml += '<p style="color: #0073aa;"><strong>Next Steps:</strong> Your post content now uses BunnyCDN URLs for faster loading. You can run this tool again if you upload more media to BunnyCDN.</p>';
                    
                    $('#rewrite-content-results-content').html(resultsHtml);
                    $('#rewrite-content-results').show();
                }
            });
        </script>
        
        <?php
    }
    
    /**
     * Test batch lookup performance
     */
    private function test_batch_lookup() {
        // Sample some random attachments for testing
        global $wpdb;
        
        $sample_attachments = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value as file_path
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.meta_key = '_wp_attached_file'
             AND pm.meta_value != ''
             ORDER BY RAND()
             LIMIT 20"
        );
        
        if (empty($sample_attachments)) {
            return [
                'total_urls' => 0,
                'found_count' => 0,
                'cache_hits' => 0,
                'hit_ratio' => 0
            ];
        }
        
        $total_urls = count($sample_attachments);
        $found_count = 0;
        $cache_hits = 0;
        
        // Clear cache to get accurate results
        $this->cache_manager->clear_all_cache();
        
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        
        foreach ($sample_attachments as $attachment) {
            $file_url = $base_url . '/' . $attachment->file_path;
            
            // First lookup (should be cache miss)
            $result1 = attachment_url_to_postid($file_url);
            
            // Second lookup (should be cache hit)
            $start_time = microtime(true);
            $result2 = attachment_url_to_postid($file_url);
            $lookup_time = (microtime(true) - $start_time) * 1000;
            
            if ($result1 == $attachment->ID || $result2 == $attachment->ID) {
                $found_count++;
                
                // If second lookup was very fast, it was likely a cache hit
                if ($lookup_time < 1.0) { // Less than 1ms = cache hit
                    $cache_hits++;
                }
            }
        }
        
        $hit_ratio = $total_urls > 0 ? ($cache_hits / $total_urls) * 100 : 0;
        
        return [
            'total_urls' => $total_urls,
            'found_count' => $found_count,
            'cache_hits' => $cache_hits,
            'hit_ratio' => $hit_ratio
        ];
    }
    
    /**
     * Show JetEngine compatibility notice
     */
    public function jetengine_compatibility_notice() {
        // Check if JetEngine is active and if notice was dismissed
        if (!class_exists('Jet_Engine') || get_user_meta(get_current_user_id(), 'alo_dismiss_jetengine_notice', true)) {
            return;
        }
        
        // Only show on our plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'attachment-lookup') === false) {
            return;
        }
        
        ?>
        <div class="notice notice-info is-dismissible" id="alo-jetengine-notice">
            <p>
                <strong><?php _e('JetEngine Detected!', 'attachment-lookup-optimizer'); ?></strong>
                <?php _e('The Attachment Lookup Optimizer has detected JetEngine on your site. For optimal performance with JetEngine listings, consider enabling:', 'attachment-lookup-optimizer'); ?>
            </p>
            <ul style="margin-left: 20px;">
                <li>✅ <?php _e('JetEngine Preloading (automatically enabled)', 'attachment-lookup-optimizer'); ?></li>
                <li>✅ <?php _e('JetEngine Query Optimization', 'attachment-lookup-optimizer'); ?></li>
                <li>✅ <?php _e('Custom Lookup Table', 'attachment-lookup-optimizer'); ?></li>
            </ul>
            <p>
                <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>" class="button button-primary">
                    <?php _e('Configure Settings', 'attachment-lookup-optimizer'); ?>
                </a>
                <button type="button" class="button button-secondary" id="alo-dismiss-jetengine-notice">
                    <?php _e('Dismiss This Notice', 'attachment-lookup-optimizer'); ?>
                </button>
            </p>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#alo-dismiss-jetengine-notice').on('click', function() {
                $.post(ajaxurl, {
                    action: 'alo_dismiss_jetengine_notice',
                    nonce: '<?php echo wp_create_nonce('alo_dismiss_jetengine_notice'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#alo-jetengine-notice').fadeOut();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler to dismiss JetEngine notice
     */
    public function dismiss_jetengine_notice() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'alo_dismiss_jetengine_notice')) {
            wp_die(__('Permission denied', 'attachment-lookup-optimizer'));
        }
        
        update_user_meta(get_current_user_id(), 'alo_jetengine_notice_dismissed', true);
        wp_die();
    }
    
    /**
     * Attachment Lookup Settings Page
     */
    public function attachment_lookup_settings_page() {
        // Handle form submissions
        if (isset($_POST['submit']) && check_admin_referer('attachment_lookup_opts_group-options')) {
            // WordPress will handle the settings save automatically
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'attachment-lookup-optimizer') . '</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('attachment_lookup_opts_group');
                do_settings_sections('attachment_lookup_settings');
                submit_button();
                ?>
            </form>
            
            <!-- Quick Reference -->
            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 4px;">
                <h3><?php _e('Quick Reference', 'attachment-lookup-optimizer'); ?></h3>
                <p><strong><?php _e('Optimized Lookup:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Uses our enhanced attachment lookup system instead of WordPress core.', 'attachment-lookup-optimizer'); ?></p>
                <p><strong><?php _e('Native Fallback:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Falls back to WordPress core lookup if optimized lookup fails.', 'attachment-lookup-optimizer'); ?></p>
                <p><strong><?php _e('Log Levels:', 'attachment-lookup-optimizer'); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <li><strong><?php _e('None:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('No logging', 'attachment-lookup-optimizer'); ?></li>
                    <li><strong><?php _e('Errors Only:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Log only errors and fallbacks', 'attachment-lookup-optimizer'); ?></li>
                    <li><strong><?php _e('All Lookups:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Log all attachment lookups (high volume)', 'attachment-lookup-optimizer'); ?></li>
                </ul>
                
                <h4><?php _e('Related Settings', 'attachment-lookup-optimizer'); ?></h4>
                <p><?php _e('Additional optimization settings can be found in:', 'attachment-lookup-optimizer'); ?></p>
                <ul style="margin-left: 20px;">
                    <li><a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>"><?php _e('Main Settings Page', 'attachment-lookup-optimizer'); ?></a> - <?php _e('Cache, database, and performance settings', 'attachment-lookup-optimizer'); ?></li>
                    <li><a href="<?php echo admin_url('tools.php?page=attachment-lookup-stats'); ?>"><?php _e('Statistics Page', 'attachment-lookup-optimizer'); ?></a> - <?php _e('Performance metrics and analytics', 'attachment-lookup-optimizer'); ?></li>
                    <li><a href="<?php echo admin_url('tools.php?page=attachment-lookup-logs'); ?>"><?php _e('Logs Page', 'attachment-lookup-optimizer'); ?></a> - <?php _e('Debug logs and error tracking', 'attachment-lookup-optimizer'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Core settings section callback
     */
    public function core_settings_section_callback() {
        echo '<p>' . __('Configure the core attachment lookup functionality.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Fallback settings section callback
     */
    public function fallback_settings_section_callback() {
        echo '<p>' . __('Configure fallback behavior and error handling.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Logging settings section callback
     */
    public function logging_settings_section_callback() {
        echo '<p>' . __('Configure logging and debugging options.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Error history section callback
     */
    public function error_history_section_callback() {
        echo '<p>' . __('Recent fallback errors and recovery attempts.', 'attachment-lookup-optimizer') . '</p>';
        $this->display_recent_fallback_errors();
    }
    
    /**
     * Optimized lookup enabled field
     */
    public function optimized_lookup_enabled_field() {
        $options = get_option('attachment_lookup_opts', []);
        $value = $options['optimized_lookup_enabled'] ?? true;
        ?>
        <label>
            <input type="checkbox" name="attachment_lookup_opts[optimized_lookup_enabled]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable optimized attachment lookup system', 'attachment-lookup-optimizer'); ?>
        </label>
        <p class="description">
            <?php _e('Use our enhanced lookup system instead of WordPress core. Recommended for better performance.', 'attachment-lookup-optimizer'); ?>
        </p>
        <?php
    }
    
    /**
     * Native fallback enabled field
     */
    public function native_fallback_enabled_field() {
        $options = get_option('attachment_lookup_opts', []);
        $value = $options['native_fallback_enabled'] ?? true;
        ?>
        <label>
            <input type="checkbox" name="attachment_lookup_opts[native_fallback_enabled]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable native WordPress fallback', 'attachment-lookup-optimizer'); ?>
        </label>
        <p class="description">
            <?php _e('Fall back to WordPress core lookup if optimized lookup fails. Recommended for reliability.', 'attachment-lookup-optimizer'); ?>
        </p>
        <?php
    }
    
    /**
     * Log level field
     */
    public function log_level_field() {
        $options = get_option('attachment_lookup_opts', []);
        $value = $options['log_level'] ?? 'errors_only';
        ?>
        <select name="attachment_lookup_opts[log_level]">
            <option value="none" <?php selected($value, 'none'); ?>><?php _e('None', 'attachment-lookup-optimizer'); ?></option>
            <option value="errors_only" <?php selected($value, 'errors_only'); ?>><?php _e('Errors Only', 'attachment-lookup-optimizer'); ?></option>
            <option value="all" <?php selected($value, 'all'); ?>><?php _e('All Lookups', 'attachment-lookup-optimizer'); ?></option>
        </select>
        <p class="description">
            <?php _e('Choose what to log. "Errors Only" is recommended for most sites.', 'attachment-lookup-optimizer'); ?>
        </p>
        <?php
    }
    
    /**
     * Display recent fallback errors
     */
    private function display_recent_fallback_errors() {
        // This would typically pull from a log or database table
        // For now, we'll show a placeholder
        ?>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-top: 10px;">
            <p><em><?php _e('No recent fallback errors recorded.', 'attachment-lookup-optimizer'); ?></em></p>
            <p class="description">
                <?php _e('When fallback errors occur, they will be displayed here for troubleshooting.', 'attachment-lookup-optimizer'); ?>
            </p>
            <button type="button" class="button button-secondary" onclick="clearFallbackErrors()">
                <?php _e('Clear Error History', 'attachment-lookup-optimizer'); ?>
            </button>
        </div>
        
        <script type="text/javascript">
        function clearFallbackErrors() {
            if (confirm('<?php _e('Are you sure you want to clear the error history?', 'attachment-lookup-optimizer'); ?>')) {
                jQuery.post(ajaxurl, {
                    action: 'alo_clear_fallback_errors',
                    nonce: '<?php echo wp_create_nonce('alo_clear_fallback_errors'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('<?php _e('Failed to clear error history.', 'attachment-lookup-optimizer'); ?>');
                    }
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
        
        $sanitized['optimized_lookup_enabled'] = !empty($options['optimized_lookup_enabled']);
        $sanitized['native_fallback_enabled'] = !empty($options['native_fallback_enabled']);
        
        $valid_log_levels = ['none', 'errors_only', 'all'];
        $sanitized['log_level'] = in_array($options['log_level'] ?? '', $valid_log_levels) ? $options['log_level'] : 'errors_only';
        
        return $sanitized;
    }
    
    /**
     * AJAX handler to clear fallback errors
     */
    public function ajax_clear_fallback_errors() {
        check_ajax_referer('alo_clear_fallback_errors', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'attachment-lookup-optimizer'));
        }
        
        // Clear fallback error history (implementation would depend on how errors are stored)
        delete_option('alo_fallback_errors');
        delete_transient('alo_recent_fallback_errors');
        
        wp_send_json_success(['message' => __('Fallback error history cleared', 'attachment-lookup-optimizer')]);
    }
    
    /**
     * Debug Page - Advanced debugging tools and information
     */
    public function debug_page() {
        if (!current_user_can('administrator')) {
            wp_die(__('Access denied. Administrator privileges required.', 'attachment-lookup-optimizer'));
        }
        
        // Handle AJAX test action
        if (isset($_POST['alo_test_lookup_ajax']) && wp_verify_nonce($_POST['alo_nonce_test_lookup'], 'alo_debug')) {
            $test_url = sanitize_url($_POST['test_url'] ?? '');
            if ($test_url) {
                $this->perform_debug_lookup_test($test_url);
            }
        }
        
        // Handle force cleanup action
        if (isset($_POST['alo_force_cleanup_debug']) && wp_verify_nonce($_POST['alo_nonce_force_cleanup_debug'], 'alo_debug')) {
            $this->run_debug_cleanup();
        }
        
        // Get debug information
        $debug_info = $this->get_debug_information();
        $slow_queries = $this->display_slow_queries();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h3 style="margin-top: 0; color: #856404;">⚠️ <?php _e('Debug Tools - Administrator Only', 'attachment-lookup-optimizer'); ?></h3>
                <p><?php _e('This page contains advanced debugging tools and sensitive system information. Use with caution.', 'attachment-lookup-optimizer'); ?></p>
            </div>
            
            <!-- Live Lookup Testing -->
            <div class="postbox">
                <h2 class="hndle"><?php _e('Live Lookup Testing', 'attachment-lookup-optimizer'); ?></h2>
                <div class="inside">
                    <form method="post" style="margin-bottom: 20px;">
                        <?php wp_nonce_field('alo_debug', 'alo_nonce_test_lookup'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Test URL', 'attachment-lookup-optimizer'); ?></th>
                                <td>
                                    <input type="url" name="test_url" class="regular-text" 
                                           placeholder="https://example.com/wp-content/uploads/2023/07/image.jpg"
                                           value="<?php echo esc_attr($_POST['test_url'] ?? ''); ?>" />
                                    <p class="description"><?php _e('Enter a full attachment URL to test the lookup process.', 'attachment-lookup-optimizer'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" name="alo_test_lookup_ajax" class="button button-primary" 
                                   value="<?php _e('Test Lookup', 'attachment-lookup-optimizer'); ?>" />
                        </p>
                    </form>
                    
                    <!-- AJAX Testing Section -->
                    <div id="ajax-test-section">
                        <h4><?php _e('AJAX Lookup Test', 'attachment-lookup-optimizer'); ?></h4>
                        <button type="button" class="button button-secondary" id="alo-ajax-test-btn">
                            <?php _e('Run AJAX Test', 'attachment-lookup-optimizer'); ?>
                        </button>
                        <div id="ajax-test-result" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px; display: none;">
                            <pre id="ajax-test-output"></pre>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- System Information -->
            <div class="postbox">
                <h2 class="hndle"><?php _e('System Information', 'attachment-lookup-optimizer'); ?></h2>
                <div class="inside">
                    <table class="widefat striped">
                        <tbody>
                            <?php foreach ($debug_info as $key => $value): ?>
                            <tr>
                                <td style="width: 30%; font-weight: bold;"><?php echo esc_html($key); ?></td>
                                <td><?php echo wp_kses_post($value); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Slow Queries -->
            <?php if (!empty($slow_queries)): ?>
            <div class="postbox">
                <h2 class="hndle"><?php _e('Recent Slow Queries', 'attachment-lookup-optimizer'); ?></h2>
                <div class="inside">
                    <?php echo $slow_queries; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Debug Actions -->
            <div class="postbox">
                <h2 class="hndle"><?php _e('Debug Actions', 'attachment-lookup-optimizer'); ?></h2>
                <div class="inside">
                    <form method="post" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field('alo_debug', 'alo_nonce_force_cleanup_debug'); ?>
                        <input type="submit" name="alo_force_cleanup_debug" class="button button-secondary" 
                               value="<?php _e('Force Cleanup All Systems', 'attachment-lookup-optimizer'); ?>"
                               onclick="return confirm('<?php _e('This will clean up caches, logs, and temporary data. Continue?', 'attachment-lookup-optimizer'); ?>');" />
                    </form>
                    
                    <button type="button" class="button button-secondary" id="alo-flush-cache-btn">
                        <?php _e('Flush All Caches', 'attachment-lookup-optimizer'); ?>
                    </button>
                    
                    <button type="button" class="button button-secondary" id="alo-export-debug-btn">
                        <?php _e('Export Debug Info', 'attachment-lookup-optimizer'); ?>
                    </button>
                    
                    <a href="<?php echo add_query_arg('alo_clear_cache', '1'); ?>" class="button button-secondary"
                       onclick="return confirm('<?php _e('Clear all stats caches? This will help test if caching is working properly.', 'attachment-lookup-optimizer'); ?>');">
                        <?php _e('Clear Stats Caches', 'attachment-lookup-optimizer'); ?>
                    </a>
                    
                    <button type="button" class="button button-primary" id="alo-force-create-table-btn"
                            onclick="aloForceCreateTable()" 
                            style="margin-left: 10px;">
                        <?php _e('Force Create Custom Table', 'attachment-lookup-optimizer'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <?php $this->output_debug_javascript(); ?>
        <?php
    }
    
    /**
     * Get comprehensive debug information
     */
    private function get_debug_information() {
        global $wpdb;
        
        $info = [];
        
        // Plugin Information
        $info['Plugin Version'] = ALO_VERSION ?? 'Unknown';
        $info['WordPress Version'] = get_bloginfo('version');
        $info['PHP Version'] = PHP_VERSION;
        $info['MySQL Version'] = $wpdb->db_version();
        
        // Plugin Status
        $info['Custom Table Enabled'] = get_option('alo_custom_lookup_table', false) ? '✅ Yes' : '❌ No';
        
        // JetEngine Preloading - show more detailed status
        $jetengine_setting = get_option('alo_jetengine_preloading', true);
        $jetengine_available = class_exists('Jet_Engine') || function_exists('jet_engine') || defined('JET_ENGINE_VERSION');
        if ($jetengine_setting && $jetengine_available) {
            $info['JetEngine Preloading'] = '✅ Active';
        } elseif ($jetengine_setting && !$jetengine_available) {
            $info['JetEngine Preloading'] = '⚠ Enabled (JetEngine not detected)';
        } elseif (!$jetengine_setting && $jetengine_available) {
            $info['JetEngine Preloading'] = '❌ Disabled (JetEngine available)';
        } else {
            $info['JetEngine Preloading'] = '❌ Disabled';
        }
        
        $info['Global Override'] = get_option('alo_global_override', false) ? '✅ Yes' : '❌ No';
        $info['Debug Logging'] = get_option('alo_debug_logging', false) ? '✅ Yes' : '❌ No';
        
        // JetEngine Detection Information (moved here for better organization)
        $info['JetEngine Detected'] = $jetengine_available ? '✅ Yes' : '❌ No';
        if ($jetengine_available && defined('JET_ENGINE_VERSION')) {
            $info['JetEngine Version'] = JET_ENGINE_VERSION;
        }
        
        // Modern Image Format Support
        $info['WebP Support'] = function_exists('imagewebp') ? '✅ Yes' : '❌ No';
        $info['AVIF Support'] = function_exists('imageavif') ? '✅ Yes' : '❌ No';
        $supported_formats = ['JPEG', 'PNG', 'GIF'];
        if (function_exists('imagewebp')) $supported_formats[] = 'WebP';
        if (function_exists('imageavif')) $supported_formats[] = 'AVIF';
        $info['Supported Formats'] = implode(', ', $supported_formats);
        
        // Cache Information
        if ($this->cache_manager) {
            $cache_stats = $this->cache_manager->get_cache_stats();
            $info['Cache Backend'] = ucfirst($cache_stats['cache_backend'] ?? 'none');
            $info['Cache Method'] = $cache_stats['method_description'] ?? 'Unknown';
            $info['Redis Available'] = ($cache_stats['redis_available'] ?? false) ? '✅ Yes' : '❌ No';
            $info['Object Cache'] = ($cache_stats['object_cache_available'] ?? false) ? '✅ Yes' : '❌ No';
        }
        
        // Database Information
        if ($this->database_manager) {
            $db_stats = $this->database_manager->get_stats();
            $info['Main Index Exists'] = ($db_stats['index_exists'] ?? false) ? '✅ Yes' : '❌ No';
            $info['Attachment Index'] = ($db_stats['attached_file_index_exists'] ?? false) ? '✅ Yes' : '❌ No';
            $info['Total Postmeta'] = number_format_i18n($db_stats['postmeta_count'] ?? 0);
            $info['Attachment Meta'] = number_format_i18n($db_stats['attachment_meta_count'] ?? 0);
        }
        
        // Custom Table Information
        if ($this->custom_lookup_table) {
            $table_stats = $this->custom_lookup_table->get_stats();
            $info['Custom Table Exists'] = ($table_stats['table_exists'] ?? false) ? '✅ Yes' : '❌ No';
            $info['Custom Table Mappings'] = number_format_i18n($table_stats['total_mappings'] ?? 0);
            $info['Custom Table Size'] = ($table_stats['table_size_mb'] ?? 0) . ' MB';
        }
        
        // System Resources
        $info['Memory Limit'] = ini_get('memory_limit');
        $info['Memory Usage'] = size_format(memory_get_usage(true));
        $info['Max Execution Time'] = ini_get('max_execution_time') . 's';
        
        // WordPress Configuration
        $info['WP Debug'] = defined('WP_DEBUG') && WP_DEBUG ? '✅ Enabled' : '❌ Disabled';
        $info['WP Debug Log'] = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '✅ Enabled' : '❌ Disabled';
        $info['WP Cache'] = defined('WP_CACHE') && WP_CACHE ? '✅ Enabled' : '❌ Disabled';
        
        // Cache Debug Information
        $info['--- CACHE DEBUG ---'] = '---';
        
        // Database manager cache
        $db_cache = get_transient('alo_db_stats_cache_v2');
        $info['DB Stats Cache'] = $db_cache ? '✅ CACHED' : '❌ EMPTY';
        if ($db_cache) {
            $info['DB Cache Generated'] = $db_cache['cache_generated_at'] ?? 'Unknown';
            $info['DB Cache Query Time'] = ($db_cache['query_time_ms'] ?? 0) . 'ms';
        }
        
        // Upload preprocessor cache
        $upload_cache = get_transient('alo_upload_preprocessor_stats_cache_v2');
        $info['Upload Stats Cache'] = $upload_cache ? '✅ CACHED' : '❌ EMPTY';
        if ($upload_cache) {
            $info['Upload Cache Generated'] = $upload_cache['cache_generated_at'] ?? 'Unknown';
            $info['Upload Cache Query Time'] = ($upload_cache['query_time_ms'] ?? 0) . 'ms';
        }
        
        // Manual cache clear action
        if (isset($_GET['alo_clear_cache']) && $_GET['alo_clear_cache'] == '1' && current_user_can('administrator')) {
            if (function_exists('alo_clear_all_stats_caches')) {
                $result = alo_clear_all_stats_caches();
                $info['Cache Clear Result'] = $result ? '✅ SUCCESS' : '❌ FAILED';
            }
        }
        
        return $info;
    }
    
    /**
     * Perform a debug lookup test
     */
    private function perform_debug_lookup_test($test_url) {
        echo '<div class="notice notice-info">';
        echo '<h4>' . __('Lookup Test Results', 'attachment-lookup-optimizer') . '</h4>';
        
        // Manually trigger our optimized lookup function to test stats
        if ($this->cache_manager && method_exists($this->cache_manager, 'optimized_attachment_url_to_postid')) {
            $start_time = microtime(true);
            $result = $this->cache_manager->optimized_attachment_url_to_postid(false, $test_url);
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time) * 1000;
            
            echo '<p><strong>Manual Optimized Test:</strong></p>';
            echo '<p><strong>' . __('URL:', 'attachment-lookup-optimizer') . '</strong> ' . esc_html($test_url) . '</p>';
            echo '<p><strong>' . __('Result:', 'attachment-lookup-optimizer') . '</strong> ';
            
            if ($result) {
                echo '<span style="color: #46b450;">✅ Found - Post ID: ' . $result . '</span>';
            } else {
                echo '<span style="color: #d63638;">❌ Not Found</span>';
            }
            
            echo '</p>';
            echo '<p><strong>' . __('Execution Time:', 'attachment-lookup-optimizer') . '</strong> ' . 
                 sprintf('%.2f ms', $execution_time) . '</p>';
        }
        
        // Also test the standard function
        $start_time = microtime(true);
        $result = attachment_url_to_postid($test_url);
        $end_time = microtime(true);
        
        $execution_time = ($end_time - $start_time) * 1000;
        
        echo '<p><strong>Standard WordPress Test:</strong></p>';
        wp_send_json_success([
            'url' => $test_url,
            'result' => $result,
            'execution_time' => round($execution_time, 2),
            'found' => (bool) $result,
            'message' => $result ? 
                sprintf(__('Found Post ID: %d (%.2f ms)', 'attachment-lookup-optimizer'), $result, $execution_time) :
                sprintf(__('Not found (%.2f ms)', 'attachment-lookup-optimizer'), $execution_time)
        ]);
        
        // Show live stats after the test
        if ($this->cache_manager) {
            $stats = $this->cache_manager->get_live_stats();
            echo '<p><strong>' . __('Live Stats After Test:', 'attachment-lookup-optimizer') . '</strong></p>';
            echo '<pre>' . print_r($stats, true) . '</pre>';
        }
        
        echo '</div>';
        
        // Add manual test for live stats tracking
        echo '<div class="notice notice-info">';
        echo '<h4>Manual Live Stats Test</h4>';
        echo '<p>Test if live statistics tracking is working properly:</p>';
        echo '<button type="button" id="test-live-stats-btn" class="button button-secondary">Test Live Stats Tracking</button>';
        echo '<div id="live-stats-test-result" style="margin-top: 10px; padding: 10px; border: 1px solid #ddd; display: none; background: #f9f9f9;"></div>';
        echo '</div>';
        
        // Add WebP support test
        echo '<div class="notice notice-info">';
        echo '<h4>WebP & Modern Format Support Test</h4>';
        echo '<p>Test WebP and modern image format support across all plugin components:</p>';
        echo '<button type="button" id="test-webp-support-btn" class="button button-secondary">Test WebP Support</button>';
        echo '<div id="webp-test-result" style="margin-top: 10px; padding: 10px; border: 1px solid #ddd; display: none; background: #f9f9f9;"></div>';
        echo '</div>';
        
        // Add JavaScript for the test
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#test-live-stats-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#live-stats-test-result');
                
                $btn.prop('disabled', true).text('Testing...');
                $result.show().html('Running live stats test...');
                
                $.post(ajaxurl, {
                    action: 'alo_test_live_stats',
                    nonce: '<?php echo wp_create_nonce('alo_test_live_stats'); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        var statsIncreased = data.stats_changed ? '✅ YES' : '❌ NO';
                        
                        var html = '<h4>Test Results:</h4>' + 
                                  '<p><strong>Test URL:</strong> ' + data.test_url + '</p>' +
                                  '<p><strong>Lookup Result:</strong> ' + data.message + '</p>' +
                                  '<p><strong>Stats Tracking Working:</strong> ' + statsIncreased + '</p>' +
                                  '<div style="display: flex; gap: 20px;">' +
                                  '<div style="flex: 1;"><strong>Before Test:</strong><pre style="font-size: 11px;">' + JSON.stringify(data.before_stats, null, 2) + '</pre></div>' +
                                  '<div style="flex: 1;"><strong>After Test:</strong><pre style="font-size: 11px;">' + JSON.stringify(data.after_stats, null, 2) + '</pre></div>' +
                                  '</div>';
                        
                        $result.html(html);
                        
                        if (data.stats_changed) {
                            $result.css('border-color', '#46b450');
                        } else {
                            $result.css('border-color', '#d63638');
                        }
                    } else {
                        $result.html('<strong>Error:</strong> ' + (response.data ? response.data.message : 'Unknown error'));
                        $result.css('border-color', '#d63638');
                    }
                }).fail(function() {
                    $result.html('<strong>Network Error:</strong> Failed to run test');
                    $result.css('border-color', '#d63638');
                }).always(function() {
                    $btn.prop('disabled', false).text('Test Live Stats Tracking');
                });
            });
            
            $('#test-webp-support-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#webp-test-result');
                
                $btn.prop('disabled', true).text('Testing...');
                $result.show().html('Running WebP support test...');
                
                $.post(ajaxurl, {
                    action: 'alo_test_webp_support',
                    nonce: '<?php echo wp_create_nonce('alo_test_webp_support'); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        var comprehensiveSupport = data.comprehensive_support ? '✅ Full Support' : '⚠ Partial Support';
                        
                        var html = '<h4>WebP & Modern Format Support Test Results:</h4>' + 
                                  '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">' +
                                  '<div>' +
                                  '<h5>Format Detection:</h5>' +
                                  '<p><strong>WebP URL Detection:</strong> ' + (data.jetengine_webp_detected ? '✅ Yes' : '❌ No') + '</p>' +
                                  '<p><strong>AVIF URL Detection:</strong> ' + (data.jetengine_avif_detected ? '✅ Yes' : '❌ No') + '</p>' +
                                  '<p><strong>WebP in Extensions:</strong> ' + (data.webp_in_extensions ? '✅ Yes' : '❌ No') + '</p>' +
                                  '<p><strong>AVIF in Extensions:</strong> ' + (data.avif_in_extensions ? '✅ Yes' : '❌ No') + '</p>' +
                                  '</div>' +
                                  '<div>' +
                                  '<h5>Server Support:</h5>' +
                                  '<p><strong>PHP WebP Support:</strong> ' + (data.server_webp_support ? '✅ Yes' : '❌ No') + '</p>' +
                                  '<p><strong>PHP AVIF Support:</strong> ' + (data.server_avif_support ? '✅ Yes' : '❌ No') + '</p>' +
                                  '<p><strong>Overall Status:</strong> ' + comprehensiveSupport + '</p>' +
                                  '</div>' +
                                  '</div>' +
                                  '<div style="margin-top: 15px;">' +
                                  '<h5>Supported Extensions:</h5>' +
                                  '<p>' + data.supported_extensions.join(', ') + '</p>' +
                                  '</div>';
                        
                        $result.html(html);
                        
                        if (data.comprehensive_support) {
                            $result.css('border-color', '#46b450');
                        } else {
                            $result.css('border-color', '#ffb900');
                        }
                    } else {
                        $result.html('<strong>Error:</strong> ' + (response.data ? response.data.message : 'Unknown error'));
                        $result.css('border-color', '#d63638');
                    }
                }).fail(function() {
                    $result.html('<strong>Error:</strong> ' + (response.data ? response.data.message : 'Unknown error'));
                    $result.css('border-color', '#d63638');
                }).always(function() {
                    $btn.prop('disabled', false).text('Test WebP Support');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Run debug cleanup
     */
    private function run_debug_cleanup() {
        $cleaned = [];
        
        // Clear all caches
        if ($this->cache_manager) {
            $this->cache_manager->clear_all_cache();
            $cleaned[] = 'All caches cleared';
        }
        
        // Clear transients
        if ($this->transient_manager) {
            $result = $this->transient_manager->force_cleanup();
            $cleaned[] = sprintf(__('Cleaned %d transients', 'attachment-lookup-optimizer'), $result['total_cleaned'] ?? 0);
        }
        
        // Clear debug logs
        $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
        if ($debug_logger) {
            $deleted = $debug_logger->clear_logs();
            $cleaned[] = sprintf(__('Deleted %d log files', 'attachment-lookup-optimizer'), $deleted);
        }
        
        // Clear query stats
        if ($this->database_manager) {
            $this->database_manager->clear_query_stats();
            $cleaned[] = __('Query statistics cleared', 'attachment-lookup-optimizer');
        }
        
        // Clear watchlist
        if ($this->cache_manager) {
            $this->cache_manager->clear_watchlist();
            $cleaned[] = __('Watchlist cleared', 'attachment-lookup-optimizer');
        }
        
        wp_send_json_success([
            'message' => __('Force cleanup completed successfully', 'attachment-lookup-optimizer'),
            'details' => $cleaned
        ]);
    }
    
    /**
     * Statistics page content
     */
    public function stats_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Access denied.', 'attachment-lookup-optimizer'));
        }
        
        // Get statistics from various components
        $db_stats = $this->database_manager ? $this->database_manager->get_stats() : [];
        $cache_stats = $this->cache_manager ? $this->cache_manager->get_cache_stats() : [];
        $live_stats = $this->cache_manager ? $this->cache_manager->get_live_stats() : $this->get_empty_live_stats();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="alo-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin: 20px 0;">
                
                <!-- Performance Overview -->
                <div class="alo-stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3><?php _e('Performance Overview', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Total Lookups', 'attachment-lookup-optimizer'); ?></td>
                            <td><strong><?php echo number_format_i18n($live_stats['total_lookups'] ?? 0); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('Success Rate', 'attachment-lookup-optimizer'); ?></td>
                            <td><strong><?php echo ($live_stats['success_rate'] ?? 0); ?>%</strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Efficiency', 'attachment-lookup-optimizer'); ?></td>
                            <td><strong><?php echo ($live_stats['cache_efficiency'] ?? 0); ?>%</strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('Average Query Time', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo isset($db_stats['avg_query_time_ms']) ? sprintf('%.2f ms', $db_stats['avg_query_time_ms']) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Database Performance -->
                <div class="alo-stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3><?php _e('Database Performance', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Total Queries', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo isset($db_stats['total_queries']) ? number_format_i18n($db_stats['total_queries']) : __('N/A', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Slow Queries', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if (isset($db_stats['slow_queries'])): ?>
                                    <strong><?php echo number_format_i18n($db_stats['slow_queries']); ?></strong>
                                    <?php if ($db_stats['slow_queries'] > 0): ?>
                                        <span style="color: #d63638;">(<?php echo $db_stats['slow_query_percentage'] ?? 0; ?>%)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php _e('N/A', 'attachment-lookup-optimizer'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Index Status', 'attachment-lookup-optimizer'); ?></td>
                            <td>
                                <?php if (isset($db_stats['index_exists']) && $db_stats['index_exists']): ?>
                                    <span style="color: #46b450;">✓ <?php _e('Optimized', 'attachment-lookup-optimizer'); ?></span>
                                <?php else: ?>
                                    <span style="color: #d63638;">✗ <?php _e('Needs Optimization', 'attachment-lookup-optimizer'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Cache Performance -->
                <div class="alo-stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3><?php _e('Cache Performance', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Cache Backend', 'attachment-lookup-optimizer'); ?></td>
                            <td><strong><?php echo esc_html(ucfirst($cache_stats['cache_backend'] ?? 'none')); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php _e('Cache Method', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo esc_html($cache_stats['method_description'] ?? 'Unknown'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Hit Ratio', 'attachment-lookup-optimizer'); ?></td>
                            <td><strong><?php echo ($live_stats['cache_efficiency'] ?? 0); ?>%</strong></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Recent Activity -->
                <div class="alo-stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3><?php _e('Recent Activity', 'attachment-lookup-optimizer'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><?php _e('Last Activity', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo ($live_stats['last_lookup_time'] ?? null) ? 
                                human_time_diff(strtotime($live_stats['last_lookup_time'])) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                __('N/A', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Tracking Started', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo ($live_stats['first_lookup_time'] ?? null) ? 
                                human_time_diff(strtotime($live_stats['first_lookup_time'])) . ' ' . __('ago', 'attachment-lookup-optimizer') : 
                                __('N/A', 'attachment-lookup-optimizer'); ?></td>
                        </tr>
                        <tr>
                            <td><?php _e('Log Size', 'attachment-lookup-optimizer'); ?></td>
                            <td><?php echo $this->format_bytes($live_stats['total_log_size'] ?? 0); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p style="margin-top: 30px;">
                <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>" class="button button-primary">
                    <?php _e('← Back to Main Settings', 'attachment-lookup-optimizer'); ?>
                </a>
                <a href="<?php echo admin_url('tools.php?page=attachment-lookup-logs'); ?>" class="button button-secondary">
                    <?php _e('View Logs', 'attachment-lookup-optimizer'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Logs page content
     */
    public function logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Access denied.', 'attachment-lookup-optimizer'));
        }
        
        // Handle log clearing
        if (isset($_POST['alo_clear_logs']) && wp_verify_nonce($_POST['alo_nonce_clear_logs'], 'alo_clear_logs')) {
            $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
            if ($debug_logger) {
                $deleted_count = $debug_logger->clear_logs();
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Debug logs cleared: %d files deleted', 'attachment-lookup-optimizer'), $deleted_count) . 
                     '</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3><?php _e('Debug Logging Status', 'attachment-lookup-optimizer'); ?></h3>
                
                <?php $debug_enabled = get_option('alo_attachment_debug_logs', false); ?>
                <?php if ($debug_enabled): ?>
                    <p style="color: #46b450;">
                        <strong>✓ <?php _e('Debug logging is enabled', 'attachment-lookup-optimizer'); ?></strong>
                    </p>
                    <p><?php _e('All attachment lookups are being logged for analysis.', 'attachment-lookup-optimizer'); ?></p>
                <?php else: ?>
                    <p style="color: #d63638;">
                        <strong>✗ <?php _e('Debug logging is disabled', 'attachment-lookup-optimizer'); ?></strong>
                    </p>
                    <p>
                        <?php _e('Enable debug logging in the', 'attachment-lookup-optimizer'); ?>
                        <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>">
                            <?php _e('main settings', 'attachment-lookup-optimizer'); ?>
                        </a>
                        <?php _e('to start collecting log data.', 'attachment-lookup-optimizer'); ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <?php if ($debug_enabled): ?>
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3><?php _e('Recent Log Entries', 'attachment-lookup-optimizer'); ?></h3>
                
                <?php
                // Try to get recent log entries
                $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
                if ($debug_logger && method_exists($debug_logger, 'get_recent_entries')) {
                    $recent_entries = $debug_logger->get_recent_entries(50);
                    
                    if (!empty($recent_entries)) {
                        echo '<table class="widefat striped">';
                        echo '<thead>';
                        echo '<tr>';
                        echo '<th>' . __('Time', 'attachment-lookup-optimizer') . '</th>';
                        echo '<th>' . __('URL', 'attachment-lookup-optimizer') . '</th>';
                        echo '<th>' . __('Result', 'attachment-lookup-optimizer') . '</th>';
                        echo '<th>' . __('Source', 'attachment-lookup-optimizer') . '</th>';
                        echo '<th>' . __('Time (ms)', 'attachment-lookup-optimizer') . '</th>';
                        echo '</tr>';
                        echo '</thead>';
                        echo '<tbody>';
                        
                        foreach ($recent_entries as $entry) {
                            echo '<tr>';
                            echo '<td>' . esc_html($entry['timestamp'] ?? 'Unknown') . '</td>';
                            echo '<td>' . $this->truncate_url($entry['url'] ?? '', 50) . '</td>';
                            echo '<td>';
                            if (isset($entry['result']) && $entry['result']) {
                                echo '<span style="color: #46b450;">✓ Found</span>';
                            } else {
                                echo '<span style="color: #d63638;">✗ Not Found</span>';
                            }
                            echo '</td>';
                            echo '<td>' . esc_html($this->format_lookup_source_short($entry['source'] ?? 'unknown')) . '</td>';
                            echo '<td>' . sprintf('%.2f', ($entry['execution_time'] ?? 0) * 1000) . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</tbody>';
                        echo '</table>';
                    } else {
                        echo '<p><em>' . __('No recent log entries found.', 'attachment-lookup-optimizer') . '</em></p>';
                    }
                } else {
                    echo '<p><em>' . __('Log data not available. Debug logger may not be properly initialized.', 'attachment-lookup-optimizer') . '</em></p>';
                }
                ?>
                
                <div style="margin-top: 20px;">
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('alo_clear_logs', 'alo_nonce_clear_logs'); ?>
                        <input type="submit" name="alo_clear_logs" class="button button-secondary" 
                               value="<?php _e('Clear All Logs', 'attachment-lookup-optimizer'); ?>"
                               onclick="return confirm('<?php _e('Are you sure? This will delete all debug log files and cannot be undone.', 'attachment-lookup-optimizer'); ?>');">
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <p style="margin-top: 30px;">
                <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>" class="button button-primary">
                    <?php _e('← Back to Main Settings', 'attachment-lookup-optimizer'); ?>
                </a>
                <a href="<?php echo admin_url('tools.php?page=attachment-lookup-stats'); ?>" class="button button-secondary">
                    <?php _e('View Statistics', 'attachment-lookup-optimizer'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for force creating custom table
     */
    public function ajax_force_create_table() {
        check_ajax_referer('alo_debug', 'nonce');
        
        if (!current_user_can('administrator')) {
            wp_send_json_error(['message' => __('Administrator privileges required', 'attachment-lookup-optimizer')]);
        }
        
        $messages = [];
        $success = false;
        
        // Force create custom table
        if ($this->custom_lookup_table) {
            try {
                $result = $this->custom_lookup_table->create_table();
                if ($result) {
                    $messages[] = "✅ Custom lookup table created successfully";
                    $success = true;
                    
                    // Get table stats
                    $stats = $this->custom_lookup_table->get_stats();
                    $messages[] = "📊 Table ready with {$stats['total_mappings']} mappings";
                } else {
                    $messages[] = "❌ Custom table creation failed";
                }
            } catch (Exception $e) {
                $messages[] = "❌ Error: " . $e->getMessage();
            }
        } else {
            $messages[] = "❌ Custom lookup table instance not available";
        }
        
        // Also enable JetEngine preloading while we're at it
        update_option('alo_jetengine_preloading', true);
        $messages[] = "✅ JetEngine preloading enabled";
        
        $response = [
            'success' => $success,
            'message' => implode("\n", $messages)
        ];
        
        if ($success) {
            wp_send_json_success($response);
        } else {
            wp_send_json_error($response);
        }
    }
    
    /**
     * AJAX handler for testing live stats
     */
    public function ajax_test_live_stats() {
        check_ajax_referer('alo_test_live_stats', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'attachment-lookup-optimizer')]);
        }
        
        if (!$this->cache_manager) {
            wp_send_json_error(['message' => __('Cache manager not available', 'attachment-lookup-optimizer')]);
        }
        
        // Get stats before test
        $before_stats = $this->cache_manager->get_live_stats();
        
        // Test with a fake URL to trigger the optimized function
        $test_url = wp_upload_dir()['baseurl'] . '/test-stats-tracking.jpg';
        
        try {
            // Manually call the optimized function to test stats tracking
            $result = $this->cache_manager->optimized_attachment_url_to_postid(false, $test_url);
            
            // Get stats after test
            $after_stats = $this->cache_manager->get_live_stats();
            
            $message = $result ? 'Found attachment (unlikely with test URL)' : 'Test URL not found (expected)';
            
            wp_send_json_success([
                'before_stats' => $before_stats,
                'after_stats' => $after_stats,
                'test_url' => $test_url,
                'lookup_result' => $result,
                'message' => $message,
                'stats_changed' => ($after_stats['total_lookups'] ?? 0) > ($before_stats['total_lookups'] ?? 0)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error during test: ' . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX handler for testing WebP support
     */
    public function ajax_test_webp_support() {
        check_ajax_referer('alo_test_webp_support', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'attachment-lookup-optimizer')]);
        }
        
        // Test WebP format detection
        $webp_url = wp_upload_dir()['baseurl'] . '/test-webp-support.webp';
        $avif_url = wp_upload_dir()['baseurl'] . '/test-avif-support.avif';
        
        // Test JetEngine format detection
        $webp_detected = false;
        $avif_detected = false;
        
        if ($this->jetengine_preloader && method_exists($this->jetengine_preloader, 'looks_like_attachment_url')) {
            $webp_detected = $this->jetengine_preloader->looks_like_attachment_url($webp_url);
            $avif_detected = $this->jetengine_preloader->looks_like_attachment_url($avif_url);
        }
        
        // Check server WebP support
        $server_webp_support = function_exists('imagewebp');
        $server_avif_support = function_exists('imageavif');
        
        // Test formats detection
        $supported_formats = apply_filters('alo_supported_image_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif', 'heic']);
        
        wp_send_json_success([
            'webp_url' => $webp_url,
            'avif_url' => $avif_url,
            'jetengine_webp_detected' => $webp_detected,
            'jetengine_avif_detected' => $avif_detected,
            'server_webp_support' => $server_webp_support,
            'server_avif_support' => $server_avif_support,
            'supported_extensions' => $supported_formats,
            'webp_in_extensions' => in_array('webp', $supported_formats),
            'avif_in_extensions' => in_array('avif', $supported_formats),
            'comprehensive_support' => $webp_detected && $server_webp_support && in_array('webp', $supported_formats),
            'message' => 'WebP support test completed successfully'
        ]);
    }
    
    /**
     * Dismiss global override notice
     */
    public function dismiss_global_override_notice() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'alo_dismiss_notice')) {
            wp_die(__('Permission denied', 'attachment-lookup-optimizer'));
        }
        
        update_user_meta(get_current_user_id(), 'alo_global_override_notice_dismissed', true);
        wp_send_json_success(['message' => __('Global override notice dismissed', 'attachment-lookup-optimizer')]);
    }
    
    /**
     * Cache settings section callback
     */
    public function cache_section_callback() {
        echo '<p>' . __('Configure caching, performance optimizations, and advanced features.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Database optimization section callback
     */
    public function database_section_callback() {
        echo '<p>' . __('Database optimization tools and custom lookup table management.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Actions section callback
     */
    public function actions_section_callback() {
        echo '<p>' . __('Perform maintenance tasks and bulk operations.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Cache TTL field callback
     */
    public function cache_ttl_field_callback() {
        $value = get_option(self::CACHE_TTL_OPTION, self::DEFAULT_CACHE_TTL);
        echo '<input type="number" name="' . self::CACHE_TTL_OPTION . '" value="' . esc_attr($value) . '" min="60" max="86400" />';
        echo '<p class="description">' . __('Cache expiration time in seconds (60-86400). Default: 43200 (12 hours)', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Debug logging field callback
     */
    public function debug_logging_field_callback() {
        $value = get_option(self::DEBUG_LOGGING_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::DEBUG_LOGGING_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable debug logging', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Log detailed information about attachment lookups for troubleshooting.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Debug threshold field callback
     */
    public function debug_threshold_field_callback() {
        $value = get_option(self::DEBUG_THRESHOLD_OPTION, 3);
        echo '<input type="number" name="' . self::DEBUG_THRESHOLD_OPTION . '" value="' . esc_attr($value) . '" min="1" max="100" />';
        echo '<p class="description">' . __('Number of calls to trigger detailed logging (1-100). Default: 3', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Global override field callback
     */
    public function global_override_field_callback() {
        $value = get_option(self::GLOBAL_OVERRIDE_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::GLOBAL_OVERRIDE_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable global override of attachment_url_to_postid()', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Replace WordPress core function with optimized version. Recommended for best performance.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * JetEngine preloading field callback
     */
    public function jetengine_preloading_field_callback() {
        $value = get_option(self::JETENGINE_PRELOADING_OPTION, true);
        echo '<label><input type="checkbox" name="' . self::JETENGINE_PRELOADING_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable JetEngine attachment preloading', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Preload attachment URLs when JetEngine listings are rendered.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Lazy loading field callback
     */
    public function lazy_loading_field_callback() {
        $value = get_option(self::LAZY_LOADING_OPTION, true);
        echo '<label><input type="checkbox" name="' . self::LAZY_LOADING_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable lazy loading for images', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Delay loading of images until they are needed.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Lazy loading above fold field callback
     */
    public function lazy_loading_above_fold_field_callback() {
        $value = get_option(self::LAZY_LOADING_ABOVE_FOLD_OPTION, 3);
        echo '<input type="number" name="' . self::LAZY_LOADING_ABOVE_FOLD_OPTION . '" value="' . esc_attr($value) . '" min="0" max="20" />';
        echo '<p class="description">' . __('Number of above-the-fold images to skip lazy loading (0-20). Default: 3', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * JetEngine query optimization field callback
     */
    public function jetengine_query_optimization_field_callback() {
        $value = get_option(self::JETENGINE_QUERY_OPTIMIZATION_OPTION, true);
        echo '<label><input type="checkbox" name="' . self::JETENGINE_QUERY_OPTIMIZATION_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable JetEngine query optimization', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Optimize database queries for JetEngine listings.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Expensive query logging field callback
     */
    public function expensive_query_logging_field_callback() {
        $value = get_option(self::EXPENSIVE_QUERY_LOGGING_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::EXPENSIVE_QUERY_LOGGING_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Log expensive queries', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Log slow database queries for performance analysis.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Slow query threshold field callback
     */
    public function slow_query_threshold_field_callback() {
        $value = get_option(self::SLOW_QUERY_THRESHOLD_OPTION, 0.3);
        echo '<input type="number" name="' . self::SLOW_QUERY_THRESHOLD_OPTION . '" value="' . esc_attr($value) . '" min="0.1" max="5" step="0.1" />';
        echo '<p class="description">' . __('Slow query threshold in seconds (0.1-5). Default: 0.3', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Custom lookup table field callback
     */
    public function custom_lookup_table_field_callback() {
        $value = get_option(self::CUSTOM_LOOKUP_TABLE_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::CUSTOM_LOOKUP_TABLE_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable custom lookup table (Experimental)', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Use a dedicated table for ultra-fast attachment lookups. Experimental feature.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Attachment debug logs field callback
     */
    public function attachment_debug_logs_field_callback() {
        $value = get_option(self::ATTACHMENT_DEBUG_LOGS_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::ATTACHMENT_DEBUG_LOGS_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable attachment lookup debug logs', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Create detailed logs for each attachment lookup operation.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Debug log format field callback
     */
    public function debug_log_format_field_callback() {
        $value = get_option(self::DEBUG_LOG_FORMAT_OPTION, 'standard');
        echo '<select name="' . self::DEBUG_LOG_FORMAT_OPTION . '">';
        echo '<option value="standard"' . selected($value, 'standard', false) . '>' . __('Standard', 'attachment-lookup-optimizer') . '</option>';
        echo '<option value="json"' . selected($value, 'json', false) . '>' . __('JSON', 'attachment-lookup-optimizer') . '</option>';
        echo '<option value="detailed"' . selected($value, 'detailed', false) . '>' . __('Detailed', 'attachment-lookup-optimizer') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Format for debug log entries.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Native fallback enabled field callback
     */
    public function native_fallback_enabled_field_callback() {
        $value = get_option(self::NATIVE_FALLBACK_ENABLED_OPTION, true);
        echo '<label><input type="checkbox" name="' . self::NATIVE_FALLBACK_ENABLED_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable native WordPress fallback', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Fall back to WordPress core if optimized lookup fails.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Slow query threshold ms field callback
     */
    public function slow_query_threshold_ms_field_callback() {
        $value = get_option(self::SLOW_QUERY_THRESHOLD_MS_OPTION, 300);
        echo '<input type="number" name="' . self::SLOW_QUERY_THRESHOLD_MS_OPTION . '" value="' . esc_attr($value) . '" min="50" max="5000" />';
        echo '<p class="description">' . __('Slow query threshold in milliseconds (50-5000). Default: 300', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Failure tracking enabled field callback
     */
    public function failure_tracking_enabled_field_callback() {
        $value = get_option(self::FAILURE_TRACKING_ENABLED_OPTION, true);
        echo '<label><input type="checkbox" name="' . self::FAILURE_TRACKING_ENABLED_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable failure tracking & watchlist', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('Track failed lookups and maintain a watchlist for monitoring.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN section callback
     */
    public function bunnycdn_section_callback() {
        // Add top progress bar for bulk rewrite process
        echo '<div id="bunnycdn-rewrite-progress-container" style="display: none; background: #fff; border: 2px solid #0073aa; border-radius: 6px; padding: 15px; margin: 0 0 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        echo '<h4 style="margin: 0 0 10px 0; color: #0073aa; font-size: 16px;">' . __('🔄 Content URL Rewriting in Progress', 'attachment-lookup-optimizer') . '</h4>';
        echo '<div id="bunnycdn-rewrite-progress-bar-bg" style="width: 100%; background: #e0e0e0; height: 24px; border-radius: 12px; overflow: hidden; margin: 10px 0;">';
        echo '<div id="bunnycdn-rewrite-progress-bar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #0073aa 0%, #005a87 100%); transition: width 0.4s ease; border-radius: 12px; position: relative;">';
        echo '<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: bold; font-size: 12px; text-shadow: 1px 1px 2px rgba(0,0,0,0.5);" id="bunnycdn-rewrite-progress-percent">0%</div>';
        echo '</div>';
        echo '</div>';
        echo '<div id="bunnycdn-rewrite-progress-label" style="font-size: 14px; color: #555; margin: 5px 0; min-height: 20px;">' . __('Initializing...', 'attachment-lookup-optimizer') . '</div>';
        echo '<div id="bunnycdn-rewrite-progress-details" style="font-size: 12px; color: #666; background: #f8f9fa; padding: 8px; border-radius: 4px; margin: 10px 0; min-height: 40px;">';
        echo '<div><strong>' . __('Status:', 'attachment-lookup-optimizer') . '</strong> <span id="bunnycdn-rewrite-status-text">' . __('Starting...', 'attachment-lookup-optimizer') . '</span></div>';
        echo '<div><strong>' . __('Current Post:', 'attachment-lookup-optimizer') . '</strong> <span id="bunnycdn-rewrite-current-post">-</span></div>';
        echo '<div><strong>' . __('Progress:', 'attachment-lookup-optimizer') . '</strong> <span id="bunnycdn-rewrite-progress-stats">0 / 0 posts processed</span></div>';
        echo '</div>';
        echo '<button id="bunnycdn-rewrite-cancel-btn" class="button button-secondary" style="background: #d63638; border-color: #d63638; color: #fff;">' . __('Cancel Process', 'attachment-lookup-optimizer') . '</button>';
        echo '</div>';
        
        echo '<p>' . __('Configure BunnyCDN integration to automatically upload media files to your CDN storage zone.', 'attachment-lookup-optimizer') . '</p>';
        echo '<p><strong>' . __('Note:', 'attachment-lookup-optimizer') . '</strong> ' . __('This feature requires a BunnyCDN account and storage zone. Files will be uploaded to BunnyCDN upon WordPress upload.', 'attachment-lookup-optimizer') . '</p>';
        
        // Display cron sync status if available
        if ($this->bunnycdn_cron_sync) {
            $this->display_bunnycdn_cron_status();
        }
        
        // Display manual sync section
        $this->display_bunnycdn_manual_sync();
    }
    
    /**
     * Display BunnyCDN cron sync status
     */
    private function display_bunnycdn_cron_status() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $stats = $this->bunnycdn_cron_sync->get_sync_stats();
        $is_active = $this->bunnycdn_cron_sync->is_cron_active();
        $is_auto_sync_enabled = $this->bunnycdn_cron_sync->is_auto_sync_enabled();
        
        echo '<div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0;">' . __('Automatic Background Sync', 'attachment-lookup-optimizer') . '</h4>';
        
        if ($is_auto_sync_enabled && $is_active) {
            echo '<p><span style="color: #00a32a;">●</span> ' . __('Cron sync is active', 'attachment-lookup-optimizer') . '</p>';
            
            if (!empty($stats['next_run'])) {
                echo '<p><strong>' . __('Next run:', 'attachment-lookup-optimizer') . '</strong> ' . esc_html($stats['next_run']) . '</p>';
            }
            
            if (!empty($stats['pending_count'])) {
                echo '<p><strong>' . __('Pending uploads:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($stats['pending_count']) . ' ' . __('attachments', 'attachment-lookup-optimizer') . '</p>';
            }
            
            if (!empty($stats['last_run'])) {
                echo '<p><strong>' . __('Last run:', 'attachment-lookup-optimizer') . '</strong> ' . esc_html($stats['last_run']) . '</p>';
                
                if (isset($stats['processed']) && isset($stats['successful']) && isset($stats['failed'])) {
                    echo '<p><strong>' . __('Last run results:', 'attachment-lookup-optimizer') . '</strong> ';
                    echo sprintf(
                        __('Processed: %d, Successful: %d, Failed: %d', 'attachment-lookup-optimizer'),
                        $stats['processed'],
                        $stats['successful'],
                        $stats['failed']
                    );
                    echo '</p>';
                }
            }
            
            if (!empty($stats['total_runs'])) {
                echo '<p><strong>' . __('Total runs:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($stats['total_runs']) . '</p>';
            }
        } elseif (!$is_auto_sync_enabled) {
            echo '<p><span style="color: #f56e28;">●</span> ' . __('Auto sync is disabled', 'attachment-lookup-optimizer') . '</p>';
            echo '<p class="description">' . __('Enable the "Enable automatic background sync" setting below to activate background uploads.', 'attachment-lookup-optimizer') . '</p>';
        } else {
            echo '<p><span style="color: #d63638;">●</span> ' . __('Cron sync is not active', 'attachment-lookup-optimizer') . '</p>';
            echo '<p class="description">' . __('Background sync will be activated automatically when BunnyCDN integration is enabled.', 'attachment-lookup-optimizer') . '</p>';
        }
        
        echo '<p class="description">' . __('The background sync automatically uploads attachments to BunnyCDN every minute (up to 10 attachments per run).', 'attachment-lookup-optimizer') . '</p>';
        echo '</div>';
    }
    
    /**
     * Display BunnyCDN manual sync section
     */
    private function display_bunnycdn_manual_sync() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo '<div style="background: #f0f8ff; border: 1px solid #0073aa; padding: 15px; margin: 15px 0; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0;">' . __('Manual Full Sync', 'attachment-lookup-optimizer') . '</h4>';
        echo '<p>' . __('Upload all media files in your library to BunnyCDN. This will process all attachments, including those that have not been uploaded yet.', 'attachment-lookup-optimizer') . '</p>';
        echo '<p><strong>' . __('Warning:', 'attachment-lookup-optimizer') . '</strong> ' . __('This operation may take a long time for large media libraries. The process will run in the background and you can monitor progress below.', 'attachment-lookup-optimizer') . '</p>';
        
        // Display last sync time
        $last_sync = get_option('bunnycdn_last_full_sync');
        echo '<p><strong>' . __('Last Sync:', 'attachment-lookup-optimizer') . '</strong> ' . ($last_sync ? date('Y-m-d H:i:s', strtotime($last_sync)) : __('Never', 'attachment-lookup-optimizer')) . '</p>';
        
        // Sync control buttons
        echo '<button id="bunnycdn-run-sync" class="button button-primary">' . __('Upload All Media to BunnyCDN', 'attachment-lookup-optimizer') . '</button>';
        echo '<button id="bunnycdn-stop-sync" class="button button-secondary" style="margin-left: 10px;">' . __('Stop Sync', 'attachment-lookup-optimizer') . '</button>';
        echo '<button id="bunnycdn-check-status" class="button button-secondary" style="margin-left: 10px;">' . __('Check Status', 'attachment-lookup-optimizer') . '</button>';
        echo '<div id="bunnycdn-sync-status" style="margin-top: 10px;"></div>';
        
        // Progress bar container
        echo '<div id="bunnycdn-progress-container" style="display:none; margin-top:10px; max-width: 500px;">';
        echo '<div id="bunnycdn-progress-bar-bg" style="width:100%; background:#e0e0e0; height:20px; border-radius:10px; overflow:hidden;">';
        echo '<div id="bunnycdn-progress-bar" style="height:100%; width:0%; background:#0073aa; transition:width 0.4s ease;"></div>';
        echo '</div>';
        echo '<div id="bunnycdn-progress-label" style="margin-top:4px; font-size:12px; color:#555;"></div>';
        echo '</div>';
        
        echo '</div>';
        
        // Add Reclaim Disk Space section
        $this->display_reclaim_disk_space_section();
        
        // Add Reset Offload Status section (admin only)
        $this->display_reset_offload_status_section();
        
        // Add Content Rewriting Statistics section
        $this->display_content_rewriting_stats();
        
        // Add Content URL Rewriting Tool section
        $this->display_content_url_rewriting_tool();
    }
    
    /**
     * Display Reclaim Disk Space section
     */
    private function display_reclaim_disk_space_section() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get statistics for files that can be reclaimed
        global $wpdb;
        
        $reclaimable_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p1.post_id)
            FROM {$wpdb->postmeta} p1
            LEFT JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id AND p2.meta_key = '_bunnycdn_offloaded'
            WHERE p1.meta_key = '_bunnycdn_url'
            AND p1.meta_value != ''
            AND (p2.meta_value IS NULL OR p2.meta_value != '1')
        ");
        
        echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0; color: #856404;">' . __('Reclaim Disk Space', 'attachment-lookup-optimizer') . '</h4>';
        echo '<p>' . __('Delete local copies of files that have already been uploaded to BunnyCDN to free up disk space on your server.', 'attachment-lookup-optimizer') . '</p>';
        
        if ($reclaimable_count > 0) {
            echo '<p><strong>' . sprintf(
                _n(
                    '%d file can be reclaimed to free up disk space.',
                    '%d files can be reclaimed to free up disk space.',
                    $reclaimable_count,
                    'attachment-lookup-optimizer'
                ),
                $reclaimable_count
            ) . '</strong></p>';
            
            echo '<p><strong>' . __('Warning:', 'attachment-lookup-optimizer') . '</strong> ' . __('This action will permanently delete local files from your server. Make sure your BunnyCDN integration is working correctly before proceeding. This action cannot be undone.', 'attachment-lookup-optimizer') . '</p>';
            
            echo '<button id="reclaim-disk-space-btn" class="button button-secondary" style="background: #f0ad4e; border-color: #eea236; color: #fff;">' . __('Reclaim Disk Space', 'attachment-lookup-optimizer') . '</button>';
            echo '<button id="reclaim-stop-btn" class="button button-secondary" style="margin-left: 10px; display: none;">' . __('Stop Process', 'attachment-lookup-optimizer') . '</button>';
        } else {
            echo '<p><strong>' . __('No files available for reclaim.', 'attachment-lookup-optimizer') . '</strong> ' . __('All files with BunnyCDN URLs have already been offloaded, or no files have been uploaded to BunnyCDN yet.', 'attachment-lookup-optimizer') . '</p>';
        }
        
        // Status and progress display
        echo '<div id="reclaim-status" style="margin-top: 10px;"></div>';
        echo '<div id="reclaim-progress-container" style="display:none; margin-top:10px; max-width: 500px;">';
        echo '<div id="reclaim-progress-bar-bg" style="width:100%; background:#e0e0e0; height:20px; border-radius:10px; overflow:hidden;">';
        echo '<div id="reclaim-progress-bar" style="height:100%; width:0%; background:#f0ad4e; transition:width 0.4s ease;"></div>';
        echo '</div>';
        echo '<div id="reclaim-progress-label" style="margin-top:4px; font-size:12px; color:#555;"></div>';
        echo '</div>';
        
        // Results summary
        echo '<div id="reclaim-results" style="display:none; margin-top:15px; padding:10px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">';
        echo '<h5 style="margin-top:0;">' . __('Reclaim Results', 'attachment-lookup-optimizer') . '</h5>';
        echo '<div id="reclaim-results-content"></div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Display Reset Offload Status section (admin only)
     */
    private function display_reset_offload_status_section() {
        // Only show to administrators
        if (!current_user_can('administrator')) {
            return;
        }
        
        // Get statistics for offloaded files
        global $wpdb;
        
        $offloaded_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_offloaded'
            AND meta_value = '1'
        ");
        
        $cdn_url_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_url'
            AND meta_value != ''
        ");
        
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 15px 0; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0; color: #721c24;">' . __('Reset Offload Status', 'attachment-lookup-optimizer') . ' <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: normal;">ADMIN ONLY</span></h4>';
        echo '<p>' . __('Reset the offload status for all media attachments. This is useful for testing, re-syncing, or re-running bulk upload/offload operations from scratch.', 'attachment-lookup-optimizer') . '</p>';
        
        // Display current statistics
        echo '<div style="background: #fff; padding: 10px; border-radius: 4px; margin: 10px 0;">';
        echo '<h5 style="margin-top: 0;">' . __('Current Status', 'attachment-lookup-optimizer') . '</h5>';
        echo '<ul style="margin: 0;">';
        echo '<li><strong>' . __('Files marked as offloaded:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($offloaded_count) . '</li>';
        echo '<li><strong>' . __('Files with CDN URLs:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($cdn_url_count) . '</li>';
        echo '</ul>';
        echo '</div>';
        
        // Reset options
        echo '<div style="margin: 15px 0;">';
        echo '<h5>' . __('Reset Options', 'attachment-lookup-optimizer') . '</h5>';
        echo '<label style="display: block; margin: 5px 0;"><input type="checkbox" id="reset-offload-status" checked> ' . __('Reset _bunnycdn_offloaded status', 'attachment-lookup-optimizer') . '</label>';
        echo '<label style="display: block; margin: 5px 0;"><input type="checkbox" id="reset-cdn-urls"> ' . __('Also reset _bunnycdn_url (removes CDN URLs)', 'attachment-lookup-optimizer') . '</label>';
        echo '<label style="display: block; margin: 5px 0;"><input type="checkbox" id="reset-offload-timestamps"> ' . __('Reset _bunnycdn_offloaded_at timestamps', 'attachment-lookup-optimizer') . '</label>';
        echo '</div>';
        
        // Filter options
        echo '<div style="margin: 15px 0;">';
        echo '<h5>' . __('Filter Options (Optional)', 'attachment-lookup-optimizer') . '</h5>';
        echo '<label style="display: block; margin: 5px 0;">' . __('Date Range:', 'attachment-lookup-optimizer') . '</label>';
        echo '<input type="date" id="reset-date-from" style="margin-right: 10px;"> ' . __('to', 'attachment-lookup-optimizer') . ' <input type="date" id="reset-date-to" style="margin-left: 10px;">';
        echo '<p class="description">' . __('Leave empty to reset all files, or specify a date range to reset only files uploaded within that period.', 'attachment-lookup-optimizer') . '</p>';
        echo '</div>';
        
        echo '<p><strong>' . __('Warning:', 'attachment-lookup-optimizer') . '</strong> ' . __('This action will reset the offload status for attachments, which may cause them to be re-processed by sync operations. Use with caution on production sites.', 'attachment-lookup-optimizer') . '</p>';
        
        // Action buttons
        if ($offloaded_count > 0 || $cdn_url_count > 0) {
            echo '<button id="reset-offload-status-btn" class="button button-secondary" style="background: #dc3545; border-color: #dc3545; color: #fff;">' . __('Reset Offload Status', 'attachment-lookup-optimizer') . '</button>';
            echo '<button id="reset-offload-stop-btn" class="button button-secondary" style="margin-left: 10px; display: none;">' . __('Stop Reset', 'attachment-lookup-optimizer') . '</button>';
        } else {
            echo '<p><strong>' . __('No offload data to reset.', 'attachment-lookup-optimizer') . '</strong> ' . __('No files have been marked as offloaded or have CDN URLs.', 'attachment-lookup-optimizer') . '</p>';
        }
        
        // Status and progress display
        echo '<div id="reset-status" style="margin-top: 10px;"></div>';
        echo '<div id="reset-progress-container" style="display:none; margin-top:10px; max-width: 500px;">';
        echo '<div id="reset-progress-bar-bg" style="width:100%; background:#e0e0e0; height:20px; border-radius:10px; overflow:hidden;">';
        echo '<div id="reset-progress-bar" style="height:100%; width:0%; background:#dc3545; transition:width 0.4s ease;"></div>';
        echo '</div>';
        echo '<div id="reset-progress-label" style="margin-top:4px; font-size:12px; color:#555;"></div>';
        echo '</div>';
        
        // Results summary
        echo '<div id="reset-results" style="display:none; margin-top:15px; padding:10px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">';
        echo '<h5 style="margin-top:0;">' . __('Reset Results', 'attachment-lookup-optimizer') . '</h5>';
        echo '<div id="reset-results-content"></div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Display Content Rewriting Statistics section
     */
    private function display_content_rewriting_stats() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if content rewriting is enabled
        $rewrite_enabled = get_option(self::BUNNYCDN_REWRITE_URLS_OPTION, false);
        
        // Get statistics for content rewriting
        global $wpdb;
        
        // Count posts that have been rewritten
        $rewritten_posts_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_rewritten'
        ");
        
        // Count total attachments with CDN URLs
        $cdn_attachments_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_url'
            AND meta_value != ''
        ");
        
        // Get sample of rewritten posts
        $sample_posts = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_type, pm.meta_value as rewritten_attachments
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_bunnycdn_rewritten'
            AND p.post_status = 'publish'
            ORDER BY p.post_modified DESC
            LIMIT 5
        ");
        
        echo '<div style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; margin: 15px 0; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0; color: #0066cc;">' . __('Content URL Rewriting Statistics', 'attachment-lookup-optimizer') . '</h4>';
        
        if ($rewrite_enabled) {
            echo '<p style="color: #0066cc;"><strong>✅ ' . __('Content URL rewriting is enabled', 'attachment-lookup-optimizer') . '</strong></p>';
            echo '<p>' . __('When attachments are uploaded to BunnyCDN, the system automatically finds and replaces local image URLs in post and page content with CDN URLs.', 'attachment-lookup-optimizer') . '</p>';
        } else {
            echo '<p style="color: #666;"><strong>⚪ ' . __('Content URL rewriting is disabled', 'attachment-lookup-optimizer') . '</strong></p>';
            echo '<p>' . __('Enable this feature in the settings above to automatically rewrite image URLs in post content after BunnyCDN uploads.', 'attachment-lookup-optimizer') . '</p>';
        }
        
        // Display statistics
        echo '<div style="background: #fff; padding: 10px; border-radius: 4px; margin: 10px 0;">';
        echo '<h5 style="margin-top: 0;">' . __('Rewriting Statistics', 'attachment-lookup-optimizer') . '</h5>';
        echo '<ul style="margin: 0;">';
        echo '<li><strong>' . __('Posts/pages with rewritten URLs:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($rewritten_posts_count) . '</li>';
        echo '<li><strong>' . __('Total attachments with CDN URLs:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($cdn_attachments_count) . '</li>';
        
        if ($rewritten_posts_count > 0 && $cdn_attachments_count > 0) {
            $rewrite_coverage = round(($rewritten_posts_count / $cdn_attachments_count) * 100, 1);
            echo '<li><strong>' . __('Content coverage:', 'attachment-lookup-optimizer') . '</strong> ' . $rewrite_coverage . '%</li>';
        }
        echo '</ul>';
        echo '</div>';
        
        // Display sample of recently rewritten posts
        if (!empty($sample_posts)) {
            echo '<div style="background: #fff; padding: 10px; border-radius: 4px; margin: 10px 0;">';
            echo '<h5 style="margin-top: 0;">' . __('Recently Rewritten Content', 'attachment-lookup-optimizer') . '</h5>';
            echo '<ul style="margin: 0; font-size: 12px;">';
            
            foreach ($sample_posts as $post) {
                $rewritten_attachments = maybe_unserialize($post->rewritten_attachments);
                $attachment_count = is_array($rewritten_attachments) ? count($rewritten_attachments) : 1;
                
                echo '<li>';
                echo '<strong>' . esc_html($post->post_title) . '</strong> ';
                echo '<span style="color: #666;">(' . esc_html($post->post_type) . ')</span> - ';
                echo sprintf(
                    _n(
                        '%d attachment rewritten',
                        '%d attachments rewritten',
                        $attachment_count,
                        'attachment-lookup-optimizer'
                    ),
                    $attachment_count
                );
                echo '</li>';
            }
            
            echo '</ul>';
            echo '</div>';
        }
        
        // How it works explanation
        echo '<div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #0066cc;">';
        echo '<h5 style="margin-top: 0;">' . __('How Content Rewriting Works', 'attachment-lookup-optimizer') . '</h5>';
        echo '<ul style="margin: 0; font-size: 12px;">';
        echo '<li>' . __('After successful BunnyCDN upload, the system searches for posts/pages containing the local image URLs', 'attachment-lookup-optimizer') . '</li>';
        echo '<li>' . __('All size variants (thumbnail, medium, large) are replaced with the single CDN URL', 'attachment-lookup-optimizer') . '</li>';
        echo '<li>' . __('Posts are marked as rewritten to prevent duplicate processing', 'attachment-lookup-optimizer') . '</li>';
        echo '<li>' . __('Only published posts and pages are processed for content rewriting', 'attachment-lookup-optimizer') . '</li>';
        echo '<li>' . __('All changes are logged for traceability and debugging', 'attachment-lookup-optimizer') . '</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Display Content URL Rewriting Tool section
     */
    private function display_content_url_rewriting_tool() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get statistics for posts that might need rewriting
        global $wpdb;
        
        // Get supported post types
        $supported_post_types = $this->get_supported_post_types();
        $post_types_placeholders = implode(',', array_fill(0, count($supported_post_types), '%s'));
        
        // Count total posts across all supported post types
        $total_posts = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type IN ($post_types_placeholders)
            AND post_status = 'publish'
        ", $supported_post_types));
        
        // Count posts that have already been rewritten
        $rewritten_posts = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_rewritten'
        ");
        
        // Count attachments with CDN URLs
        $cdn_attachments = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bunnycdn_url'
            AND meta_value != ''
        ");
        
        echo '<div style="background: #e8f4fd; border: 1px solid #0073aa; padding: 15px; margin: 15px 0; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0; color: #0073aa;">' . __('Rewrite Post Content URLs to BunnyCDN', 'attachment-lookup-optimizer') . '</h4>';
        echo '<p>' . __('Scan all existing posts and pages to find local image URLs and replace them with BunnyCDN URLs. This is useful for bulk processing existing content that wasn\'t automatically rewritten.', 'attachment-lookup-optimizer') . '</p>';
        
        // Display current statistics
        echo '<div style="background: #fff; padding: 10px; border-radius: 4px; margin: 10px 0;">';
        echo '<h5 style="margin-top: 0;">' . __('Content Analysis', 'attachment-lookup-optimizer') . '</h5>';
        echo '<ul style="margin: 0;">';
        echo '<li><strong>' . __('Total posts/pages:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($total_posts) . '</li>';
        echo '<li><strong>' . __('Posts with rewritten URLs:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($rewritten_posts) . '</li>';
        echo '<li><strong>' . __('Attachments with CDN URLs:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($cdn_attachments) . '</li>';
        
        $potential_posts = max(0, $total_posts - $rewritten_posts);
        echo '<li><strong>' . __('Posts potentially needing rewriting:', 'attachment-lookup-optimizer') . '</strong> ' . number_format($potential_posts) . '</li>';
        echo '</ul>';
        echo '</div>';
        
        if ($cdn_attachments > 0) {
            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 10px 0;">';
            echo '<h5 style="margin-top: 0; color: #856404;">' . __('How This Tool Works', 'attachment-lookup-optimizer') . '</h5>';
            echo '<ul style="margin: 0; font-size: 12px;">';
            echo '<li>' . __('Scans all published posts and pages for local image URLs (/wp-content/uploads/)', 'attachment-lookup-optimizer') . '</li>';
            echo '<li>' . __('Matches each local URL to an attachment using WordPress functions', 'attachment-lookup-optimizer') . '</li>';
            echo '<li>' . __('Replaces local URLs with BunnyCDN URLs if the attachment has been uploaded to CDN', 'attachment-lookup-optimizer') . '</li>';
            echo '<li>' . __('Marks processed posts with _bunnycdn_rewritten metadata to avoid duplicate processing', 'attachment-lookup-optimizer') . '</li>';
            echo '<li>' . __('Provides detailed summary of scanned, updated, and skipped posts', 'attachment-lookup-optimizer') . '</li>';
            echo '</ul>';
            echo '</div>';
            
            echo '<p><strong>' . __('Note:', 'attachment-lookup-optimizer') . '</strong> ' . __('This process may take some time for sites with many posts. The operation runs in batches to avoid server timeouts.', 'attachment-lookup-optimizer') . '</p>';
            
            // Post type selection
            echo '<div style="background: #fff; padding: 15px; border-radius: 4px; margin: 15px 0; border: 1px solid #ddd;">';
            echo '<h5 style="margin-top: 0;">' . __('Post Type Selection', 'attachment-lookup-optimizer') . '</h5>';
            echo '<p style="margin-bottom: 10px;">' . __('Choose which post types to process:', 'attachment-lookup-optimizer') . '</p>';
            
            echo '<select id="rewrite-post-type-filter" style="margin-right: 10px;">';
            echo '<option value="all">' . __('All Supported Post Types', 'attachment-lookup-optimizer') . '</option>';
            
            // Get post type objects for better labels
            foreach ($supported_post_types as $post_type) {
                $post_type_obj = get_post_type_object($post_type);
                $label = $post_type_obj ? $post_type_obj->labels->name : ucfirst($post_type);
                
                // Count posts for this specific post type
                $type_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*)
                    FROM {$wpdb->posts}
                    WHERE post_type = %s
                    AND post_status = 'publish'
                ", $post_type));
                
                echo '<option value="' . esc_attr($post_type) . '">' . esc_html($label) . ' (' . number_format($type_count) . ')</option>';
            }
            echo '</select>';
            
            echo '<p class="description" style="margin-top: 5px;">' . __('Select a specific post type to process only that content type, or choose "All" to process all supported post types.', 'attachment-lookup-optimizer') . '</p>';
            echo '</div>';
            
            // Action buttons
            echo '<button id="rewrite-content-urls-btn" class="button button-primary">' . __('Start Content URL Rewriting', 'attachment-lookup-optimizer') . '</button>';
            echo '<button id="rewrite-content-stop-btn" class="button button-secondary" style="margin-left: 10px; display: none;">' . __('Stop Process', 'attachment-lookup-optimizer') . '</button>';
        } else {
            echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px; margin: 10px 0;">';
            echo '<p style="margin: 0; color: #721c24;"><strong>' . __('No CDN URLs Available', 'attachment-lookup-optimizer') . '</strong></p>';
            echo '<p style="margin: 5px 0 0 0; color: #721c24;">' . __('No attachments have been uploaded to BunnyCDN yet. Upload some media files to BunnyCDN first, then use this tool to rewrite existing content.', 'attachment-lookup-optimizer') . '</p>';
            echo '</div>';
        }
        
        // Status and progress display
        echo '<div id="rewrite-content-status" style="margin-top: 10px;"></div>';
        echo '<div id="rewrite-content-progress-container" style="display:none; margin-top:10px; max-width: 500px;">';
        echo '<div id="rewrite-content-progress-bar-bg" style="width:100%; background:#e0e0e0; height:20px; border-radius:10px; overflow:hidden;">';
        echo '<div id="rewrite-content-progress-bar" style="height:100%; width:0%; background:#0073aa; transition:width 0.4s ease;"></div>';
        echo '</div>';
        echo '<div id="rewrite-content-progress-label" style="margin-top:4px; font-size:12px; color:#555;"></div>';
        echo '</div>';
        
        // Results summary
        echo '<div id="rewrite-content-results" style="display:none; margin-top:15px; padding:10px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">';
        echo '<h5 style="margin-top:0;">' . __('Content Rewriting Results', 'attachment-lookup-optimizer') . '</h5>';
        echo '<div id="rewrite-content-results-content"></div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * BunnyCDN enabled field callback
     */
    public function bunnycdn_enabled_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_ENABLED_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::BUNNYCDN_ENABLED_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable automatic upload to BunnyCDN', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('When enabled, media files will be automatically uploaded to your BunnyCDN storage zone.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN API key field callback
     */
    public function bunnycdn_api_key_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_API_KEY_OPTION, '');
        echo '<input type="password" id="bunnycdn_api_key" name="' . self::BUNNYCDN_API_KEY_OPTION . '" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Your BunnyCDN API key. You can find this in your BunnyCDN account settings.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN storage zone field callback
     */
    public function bunnycdn_storage_zone_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_STORAGE_ZONE_OPTION, '');
        echo '<input type="text" id="bunnycdn_storage_zone" name="' . self::BUNNYCDN_STORAGE_ZONE_OPTION . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="mystoragezone" />';
        echo '<p class="description">' . __('<strong>Important:</strong> Enter only the storage zone name (e.g., "mystoragezone"), not the full URL. Do not include "storage.bunnycdn.com" or ".b-cdn.net".', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN region field callback
     */
    public function bunnycdn_region_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_REGION_OPTION, 'de');
        $regions = [
            'de' => __('Germany (Frankfurt)', 'attachment-lookup-optimizer'),
            'ny' => __('New York', 'attachment-lookup-optimizer'),
            'la' => __('Los Angeles', 'attachment-lookup-optimizer'),
            'sg' => __('Singapore', 'attachment-lookup-optimizer'),
            'syd' => __('Sydney', 'attachment-lookup-optimizer'),
            'uk' => __('United Kingdom (London)', 'attachment-lookup-optimizer')
        ];
        
        echo '<select id="bunnycdn_region" name="' . self::BUNNYCDN_REGION_OPTION . '">';
        foreach ($regions as $region_code => $region_name) {
            echo '<option value="' . esc_attr($region_code) . '" ' . selected($value, $region_code, false) . '>' . esc_html($region_name) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Select the storage region closest to your users for optimal performance.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN hostname field callback
     */
    public function bunnycdn_hostname_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_HOSTNAME_OPTION, '');
        echo '<input type="text" id="bunnycdn_hostname" name="' . self::BUNNYCDN_HOSTNAME_OPTION . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="cdn.example.com" />';
        echo '<p class="description">' . __('Optional: Custom CDN hostname (e.g., cdn.example.com). Leave empty to use the default BunnyCDN hostname.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN override URLs field callback
     */
    public function bunnycdn_override_urls_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_OVERRIDE_URLS_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::BUNNYCDN_OVERRIDE_URLS_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable CDN URL serving', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('When enabled, attachments with a BunnyCDN URL will be served from the CDN instead of your server. This improves performance by delivering files from BunnyCDN\'s global network.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN auto sync field callback
     */
    public function bunnycdn_auto_sync_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_AUTO_SYNC_OPTION, true);
        echo '<label><input type="checkbox" name="' . self::BUNNYCDN_AUTO_SYNC_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable automatic background sync for missing uploads', 'attachment-lookup-optimizer') . '</label>';
        $batch_size = get_option(self::BUNNYCDN_BATCH_SIZE_OPTION, 25);
        echo '<p class="description">' . sprintf(__('When enabled, the system will automatically upload attachments to BunnyCDN in the background every minute (up to %d attachments per run). This ensures that attachments that failed to upload initially will be processed automatically.', 'attachment-lookup-optimizer'), $batch_size) . '</p>';
    }
    
    /**
     * BunnyCDN offload enabled field callback
     */
    public function bunnycdn_offload_enabled_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_OFFLOAD_ENABLED_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::BUNNYCDN_OFFLOAD_ENABLED_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Delete local files after successful upload to BunnyCDN', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('<strong>Warning:</strong> This will permanently delete the original files and all image sizes from your server after successful upload to BunnyCDN. The attachment will remain functional in WordPress, but files will only exist on BunnyCDN. Make sure your BunnyCDN integration is working properly before enabling this option.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN batch size field callback
     */
    public function bunnycdn_batch_size_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_BATCH_SIZE_OPTION, 25);
        echo '<input type="number" id="bunnycdn_batch_size" name="' . self::BUNNYCDN_BATCH_SIZE_OPTION . '" value="' . esc_attr($value) . '" min="5" max="200" class="small-text" />';
        echo '<p class="description">' . __('Number of files to process per batch during sync operations. Higher values may improve performance but use more server resources.', 'attachment-lookup-optimizer') . '</p>';
        echo '<p class="description"><strong>' . __('Recommended values:', 'attachment-lookup-optimizer') . '</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li><strong>5-20:</strong> ' . __('Shared hosting or low-resource servers', 'attachment-lookup-optimizer') . '</li>';
        echo '<li><strong>20-50:</strong> ' . __('Medium performance servers (default: 10)', 'attachment-lookup-optimizer') . '</li>';
        echo '<li><strong>50-100:</strong> ' . __('High-performance servers', 'attachment-lookup-optimizer') . '</li>';
        echo '<li><strong>100-200:</strong> ' . __('Dedicated servers with high resources', 'attachment-lookup-optimizer') . '</li>';
        echo '</ul>';
    }
    
    /**
     * BunnyCDN rewrite URLs field callback
     */
    public function bunnycdn_rewrite_urls_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_REWRITE_URLS_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::BUNNYCDN_REWRITE_URLS_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Automatically rewrite image URLs in post content after BunnyCDN upload', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('When enabled, the system will automatically find and replace local image URLs in post and page content with BunnyCDN URLs after successful uploads. This ensures your content uses the CDN URLs instead of local URLs.', 'attachment-lookup-optimizer') . '</p>';
        echo '<p class="description"><strong>' . __('Note:', 'attachment-lookup-optimizer') . '</strong> ' . __('This only affects posts and pages, and only processes content that contains the uploaded image URLs. Posts are marked as rewritten to avoid duplicate processing.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * BunnyCDN rewrite meta enabled field callback
     */
    public function bunnycdn_rewrite_meta_enabled_field_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $value = get_option(self::BUNNYCDN_REWRITE_META_ENABLED_OPTION, false);
        echo '<label><input type="checkbox" name="' . self::BUNNYCDN_REWRITE_META_ENABLED_OPTION . '" value="1"' . checked($value, true, false) . ' /> ' . __('Enable URL rewriting in JetEngine and JetFormBuilder custom fields', 'attachment-lookup-optimizer') . '</label>';
        echo '<p class="description">' . __('When enabled, the system will automatically find and replace local image URLs in JetEngine and JetFormBuilder custom fields with BunnyCDN URLs after successful uploads. This includes file fields, image fields, gallery fields, and any custom fields containing /wp-content/uploads/ URLs.', 'attachment-lookup-optimizer') . '</p>';
        echo '<p class="description"><strong>' . __('Supported field types:', 'attachment-lookup-optimizer') . '</strong> ' . __('JetEngine meta fields, JetFormBuilder form fields (field_123456), file uploads, image galleries, and repeater fields.', 'attachment-lookup-optimizer') . '</p>';
        echo '<p class="description"><strong>' . __('Default:', 'attachment-lookup-optimizer') . '</strong> ' . __('Disabled to prevent unexpected changes to custom field data. Enable only if you want custom fields to use CDN URLs.', 'attachment-lookup-optimizer') . '</p>';
    }
    
    /**
     * Get empty live stats structure
     */
    private function get_empty_live_stats() {
        return [
            'total_lookups' => 0,
            'successful_lookups' => 0,
            'not_found_count' => 0,
            'success_rate' => 0,
            'cache_efficiency' => 0,
            'table_efficiency' => 0,
            'first_lookup_time' => null,
            'last_lookup_time' => null,
            'first_entry_time' => null,
            'last_entry_time' => null,
            'total_log_size' => 0
        ];
    }
    
    /**
     * Format bytes to human readable format
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Truncate URL for display
     */
    private function truncate_url($url, $length = 50) {
        if (strlen($url) <= $length) {
            return esc_html($url);
        }
        
        $start = substr($url, 0, $length / 2);
        $end = substr($url, -($length / 2));
        
        return esc_html($start . '...' . $end);
    }
    
    /**
     * Format lookup source for short display
     */
    private function format_lookup_source_short($source) {
        $sources = [
            'optimized' => 'Optimized',
            'cache' => 'Cache',
            'database' => 'Database',
            'custom_table' => 'Custom Table',
            'fallback' => 'Fallback',
            'native' => 'Native'
        ];
        
        return $sources[$source] ?? ucfirst($source);
    }
    
    /**
     * Display slow queries if available
     */
    private function display_slow_queries() {
        if (!$this->database_manager) {
            return '';
        }
        
        $db_stats = $this->database_manager->get_stats();
        
        if (empty($db_stats['slow_query_samples'])) {
            return '';
        }
        
        $output = '<table class="widefat striped">';
        $output .= '<thead>';
        $output .= '<tr>';
        $output .= '<th>' . __('Time', 'attachment-lookup-optimizer') . '</th>';
        $output .= '<th>' . __('Query Type', 'attachment-lookup-optimizer') . '</th>';
        $output .= '<th>' . __('Execution Time', 'attachment-lookup-optimizer') . '</th>';
        $output .= '<th>' . __('Index Used', 'attachment-lookup-optimizer') . '</th>';
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';
        
        foreach (array_slice($db_stats['slow_query_samples'], -10) as $sample) {
            $output .= '<tr>';
            $output .= '<td>' . date('H:i:s', $sample['timestamp']) . '</td>';
            $output .= '<td>' . esc_html($sample['query_type']) . '</td>';
            $output .= '<td>' . sprintf('%.2f ms', $sample['execution_time'] * 1000) . '</td>';
            $output .= '<td>' . ($sample['index_used'] ? '✓ Yes' : '✗ No') . '</td>';
            $output .= '</tr>';
        }
        
        $output .= '</tbody>';
        $output .= '</table>';
        
        return $output;
    }
    
    /**
     * Output debug JavaScript for the debug page
     */
    private function output_debug_javascript() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // AJAX Test Lookup
            $('#alo-ajax-test-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#ajax-test-result');
                var $output = $('#ajax-test-output');
                
                $btn.prop('disabled', true).text('Testing...');
                $result.show();
                $output.text('Running AJAX test...');
                
                $.post(ajaxurl, {
                    action: 'alo_test_lookup',
                    nonce: '<?php echo wp_create_nonce('alo_debug'); ?>'
                }, function(response) {
                    if (response.success) {
                        $output.text(JSON.stringify(response.data, null, 2));
                    } else {
                        $output.text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                }).fail(function() {
                    $output.text('Network error occurred');
                }).always(function() {
                    $btn.prop('disabled', false).text('Run AJAX Test');
                });
            });
            
            // Flush Cache
            $('#alo-flush-cache-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Flushing...');
                
                $.post(ajaxurl, {
                    action: 'alo_flush_cache',
                    nonce: '<?php echo wp_create_nonce('alo_debug'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('✅ Cache flushed successfully!');
                    } else {
                        alert('❌ Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                }).fail(function() {
                    alert('❌ Network error occurred');
                }).always(function() {
                    $btn.prop('disabled', false).text('Flush All Caches');
                });
            });
            
            // Export Debug Info
            $('#alo-export-debug-btn').on('click', function() {
                // Create debug info object
                var debugInfo = {
                    timestamp: new Date().toISOString(),
                    url: window.location.href,
                    userAgent: navigator.userAgent,
                    // Add more debug info as needed
                };
                
                // Create and download file
                var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(debugInfo, null, 2));
                var downloadAnchorNode = document.createElement('a');
                downloadAnchorNode.setAttribute("href", dataStr);
                downloadAnchorNode.setAttribute("download", "alo-debug-info-" + Date.now() + ".json");
                document.body.appendChild(downloadAnchorNode);
                downloadAnchorNode.click();
                downloadAnchorNode.remove();
            });
            
            // Test Static Cache
            $('#test-static-cache-btn').on('click', function() {
                console.log('ALO: Test Static Cache button clicked');
                var $btn = $(this);
                var $results = $('#static-cache-test-results');
                
                console.log('ALO: Button found:', $btn.length);
                console.log('ALO: Results container found:', $results.length);
                
                $btn.prop('disabled', true).text('Testing...');
                $results.show().html('<p style="color: #666;">Running static cache test...</p>');
                
                console.log('ALO: Making AJAX request...');
                
                $.post(ajaxurl, {
                    action: 'alo_test_static_cache',
                    nonce: '<?php echo wp_create_nonce('alo_debug'); ?>'
                }, function(response) {
                    console.log('ALO: AJAX response received:', response);
                    if (response.success) {
                        var data = response.data;
                        var html = '<div style="border: 1px solid #ddd; background: #f9f9f9; padding: 10px; border-radius: 4px;">';
                        html += '<h4 style="margin-top: 0; color: #0073aa;">Static Cache Test Results</h4>';
                        
                        // Show test summary
                        html += '<p><strong>Status:</strong> ' + 
                            (data.static_cache_working ? 
                                '<span style="color: #46b450;">✅ Working</span>' : 
                                '<span style="color: #dc3232;">❌ Not Working</span>') + '</p>';
                        
                        // Show performance data
                        html += '<p><strong>Performance Improvement:</strong> ' + data.performance_improvement_percent + '%</p>';
                        html += '<p><strong>Total Test Time:</strong> ' + data.total_time_ms + 'ms</p>';
                        html += '<p><strong>Average Time per Call:</strong> ' + data.average_time_ms + 'ms</p>';
                        
                        // Show cache statistics
                        html += '<h5>Cache Statistics:</h5>';
                        html += '<ul>';
                        html += '<li>Cache Hits: ' + data.final_stats.static_cache_hits + '</li>';
                        html += '<li>Total Calls: ' + data.final_stats.total_calls + '</li>';
                        html += '<li>Hit Ratio: ' + data.final_stats.static_hit_ratio + '%</li>';
                        html += '</ul>';
                        
                        // Show lookup details
                        html += '<h5>Lookup Details:</h5>';
                        html += '<table style="width: 100%; border-collapse: collapse;">';
                        html += '<tr style="background: #f0f0f0;"><th style="padding: 5px; border: 1px solid #ddd;">Call</th><th style="padding: 5px; border: 1px solid #ddd;">Time (ms)</th><th style="padding: 5px; border: 1px solid #ddd;">Result</th></tr>';
                        $.each(data.lookup_results, function(i, result) {
                            html += '<tr>';
                            html += '<td style="padding: 5px; border: 1px solid #ddd;">' + result.call_number + '</td>';
                            html += '<td style="padding: 5px; border: 1px solid #ddd;">' + result.execution_time_ms + '</td>';
                            html += '<td style="padding: 5px; border: 1px solid #ddd;">' + 
                                (result.success ? '✅ Found ID: ' + result.result_id : '❌ Not found') + '</td>';
                            html += '</tr>';
                        });
                        html += '</table>';
                        
                        html += '<p style="margin-bottom: 0;"><small>Test URL: <code>' + data.test_url + '</code></small></p>';
                        html += '</div>';
                        
                        $results.html(html);
                    } else {
                        console.log('ALO: AJAX error response:', response);
                        $results.html('<div style="color: #dc3232; border: 1px solid #dc3232; background: #ffeaea; padding: 10px; border-radius: 4px;">' +
                            '❌ Error: ' + (response.data ? response.data.message : 'Unknown error') + '</div>');
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    console.log('ALO: AJAX request failed:', textStatus, errorThrown);
                    console.log('ALO: Response:', jqXHR.responseText);
                    $results.html('<div style="color: #dc3232; border: 1px solid #dc3232; background: #ffeaea; padding: 10px; border-radius: 4px;">' +
                        '❌ Network error occurred: ' + textStatus + '</div>');
                }).always(function() {
                    console.log('ALO: AJAX request completed');
                    $btn.prop('disabled', false).text('Test Static Cache Functionality');
                });
            });
            
            // Clear Shared Cache
            $('#clear-shared-cache-btn').on('click', function() {
                const btn = $(this);
                const resultsDiv = $('#shared-cache-clear-results');
                
                btn.prop('disabled', true).text('Clearing...');
                resultsDiv.hide();
                
                $.post(ajaxurl, {
                    action: 'alo_clear_shared_cache',
                    nonce: '<?php echo wp_create_nonce('alo_debug'); ?>'
                }, function(response) {
                    if (response.success) {
                        resultsDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>').show();
                        // Update cache status indicators
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        resultsDiv.html('<div class="notice notice-error inline"><p>' + (response.data.message || 'Unknown error') + '</p></div>').show();
                    }
                }).fail(function() {
                    resultsDiv.html('<div class="notice notice-error inline"><p>Network error occurred</p></div>').show();
                }).always(function() {
                    btn.prop('disabled', false).text('<?php _e('Clear Shared Cache', 'attachment-lookup-optimizer'); ?>');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for testing lookups  
     */
    public function ajax_test_lookup() {
        check_ajax_referer('alo_debug', 'nonce');
        
        if (!current_user_can('administrator')) {
            wp_send_json_error(['message' => __('Administrator privileges required', 'attachment-lookup-optimizer')]);
        }
        
        // Get a random attachment URL for testing
        global $wpdb;
        $sample_attachment = $wpdb->get_row(
            "SELECT p.ID, pm.meta_value 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.meta_key = '_wp_attached_file'
             AND pm.meta_value != ''
             ORDER BY RAND()
             LIMIT 1"
        );
        
        if (!$sample_attachment) {
            wp_send_json_error(['message' => __('No attachments found for testing', 'attachment-lookup-optimizer')]);
        }
        
        $upload_dir = wp_upload_dir();
        $test_url = $upload_dir['baseurl'] . '/' . $sample_attachment->meta_value;
        
        // Test the lookup
        $start_time = microtime(true);
        $result = attachment_url_to_postid($test_url);
        $end_time = microtime(true);
        
        wp_send_json_success([
            'test_url' => $test_url,
            'expected_id' => $sample_attachment->ID,
            'found_id' => $result,
            'success' => ($result == $sample_attachment->ID),
            'execution_time_ms' => round(($end_time - $start_time) * 1000, 2),
            'message' => $result ? 
                sprintf(__('Found Post ID: %d (%.2f ms)', 'attachment-lookup-optimizer'), $result, ($end_time - $start_time) * 1000) :
                sprintf(__('Not found (%.2f ms)', 'attachment-lookup-optimizer'), ($end_time - $start_time) * 1000)
        ]);
    }
    
    /**
     * AJAX handler for flushing cache
     */
    public function ajax_flush_cache() {
        check_ajax_referer('alo_debug', 'nonce');
        
        if (!current_user_can('administrator')) {
            wp_send_json_error(['message' => __('Administrator privileges required', 'attachment-lookup-optimizer')]);
        }
        
        if (!$this->cache_manager) {
            wp_send_json_error(['message' => __('Cache manager not available', 'attachment-lookup-optimizer')]);
        }
        
        try {
            $this->cache_manager->clear_all_cache();
            wp_send_json_success(['message' => __('All caches flushed successfully', 'attachment-lookup-optimizer')]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error flushing cache: ', 'attachment-lookup-optimizer') . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX handler for bulk processing attachments
     */
    public function ajax_bulk_process_attachments() {
        check_ajax_referer('alo_bulk_process', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'attachment-lookup-optimizer')]);
        }
        
        if (!$this->upload_preprocessor) {
            wp_send_json_error(['message' => __('Upload preprocessor not available', 'attachment-lookup-optimizer')]);
        }
        
        $action = sanitize_text_field($_POST['bulk_action'] ?? 'start');
        $offset = intval($_POST['offset'] ?? 0);
        
        if ($action === 'stop') {
            wp_send_json_success(['message' => __('Bulk processing stopped', 'attachment-lookup-optimizer'), 'is_complete' => true]);
        }
        
        try {
            $batch_size = 25; // Process in smaller batches for AJAX
            $result = $this->upload_preprocessor->bulk_process_existing_attachments($batch_size, $offset);
            
            $total_attachments = $this->upload_preprocessor->get_total_attachment_count();
            $progress = $total_attachments > 0 ? min(100, round(($offset + $result['processed']) / $total_attachments * 100)) : 100;
            
            wp_send_json_success([
                'processed' => $result['processed'],
                'total' => $total_attachments,
                'offset' => $offset + $result['processed'],
                'progress' => $progress,
                'is_complete' => ($offset + $result['processed']) >= $total_attachments,
                'message' => sprintf(__('Processed %d attachments (%d/%d)', 'attachment-lookup-optimizer'), 
                    $result['processed'], $offset + $result['processed'], $total_attachments)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error processing attachments: ', 'attachment-lookup-optimizer') . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX handler for warming cache with progress
     */
    public function ajax_warm_cache_progress() {
        check_ajax_referer('alo_warm_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'attachment-lookup-optimizer')]);
        }
        
        if (!$this->cache_manager) {
            wp_send_json_error(['message' => __('Cache manager not available', 'attachment-lookup-optimizer')]);
        }
        
        $action = sanitize_text_field($_POST['cache_action'] ?? 'start');
        $offset = intval($_POST['offset'] ?? 0);
        
        if ($action === 'stop') {
            wp_send_json_success(['message' => __('Cache warming stopped', 'attachment-lookup-optimizer'), 'is_complete' => true]);
        }
        
        try {
            $batch_size = 50; // Warm cache in batches
            $result = $this->cache_manager->warm_cache($batch_size, $offset);
            
            // Get total attachment count for progress calculation
            global $wpdb;
            $total_attachments = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
            );
            
            $progress = $total_attachments > 0 ? min(100, round(($offset + $result) / $total_attachments * 100)) : 100;
            
            wp_send_json_success([
                'warmed' => $result,
                'total' => $total_attachments,
                'offset' => $offset + $result,
                'progress' => $progress,
                'is_complete' => ($offset + $result) >= $total_attachments,
                'message' => sprintf(__('Warmed cache for %d attachments (%d/%d)', 'attachment-lookup-optimizer'), 
                    $result, $offset + $result, $total_attachments)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error warming cache: ', 'attachment-lookup-optimizer') . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX handler for force cleanup
     */
    public function ajax_force_cleanup() {
        check_ajax_referer('alo_actions', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'attachment-lookup-optimizer')]);
        }
        
        $cleaned = [];
        
        try {
            // Clear all caches
            if ($this->cache_manager) {
                $this->cache_manager->clear_all_cache();
                $cleaned[] = __('All caches cleared', 'attachment-lookup-optimizer');
            }
            
            // Clear transients
            if ($this->transient_manager) {
                $result = $this->transient_manager->force_cleanup();
                $cleaned[] = sprintf(__('Cleaned %d transients', 'attachment-lookup-optimizer'), $result['total_cleaned'] ?? 0);
            }
            
            // Clear debug logs
            $debug_logger = $this->cache_manager ? $this->cache_manager->get_debug_logger() : null;
            if ($debug_logger) {
                $deleted = $debug_logger->clear_logs();
                $cleaned[] = sprintf(__('Deleted %d log files', 'attachment-lookup-optimizer'), $deleted);
            }
            
            wp_send_json_success([
                'message' => __('Force cleanup completed successfully', 'attachment-lookup-optimizer'),
                'details' => $cleaned
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error during cleanup: ', 'attachment-lookup-optimizer') . $e->getMessage()]);
        }
    }

    /**
     * AJAX handler for testing static cache functionality
     */
    public function ajax_test_static_cache() {
        error_log('ALO: ajax_test_static_cache method called');
        
        // Log request data
        error_log('ALO: POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('alo_debug', 'nonce');
        
        if (!current_user_can('administrator')) {
            error_log('ALO: User does not have administrator privileges');
            wp_send_json_error(['message' => __('Administrator privileges required', 'attachment-lookup-optimizer')]);
        }
        
        if (!$this->cache_manager) {
            error_log('ALO: Cache manager not available');
            wp_send_json_error(['message' => __('Cache manager not available', 'attachment-lookup-optimizer')]);
        }
        
        error_log('ALO: Starting static cache test');
        
        // Get a random attachment URL for testing
        global $wpdb;
        $sample_attachment = $wpdb->get_row(
            "SELECT p.ID, pm.meta_value 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.meta_key = '_wp_attached_file'
             AND pm.meta_value != ''
             ORDER BY RAND()
             LIMIT 1"
        );
        
        if (!$sample_attachment) {
            error_log('ALO: No attachments found for testing');
            wp_send_json_error(['message' => __('No attachments found for testing', 'attachment-lookup-optimizer')]);
        }
        
        error_log('ALO: Found test attachment: ID=' . $sample_attachment->ID . ', file=' . $sample_attachment->meta_value);
        
        $upload_dir = wp_upload_dir();
        $test_url = $upload_dir['baseurl'] . '/' . $sample_attachment->meta_value;
        
        error_log('ALO: Test URL: ' . $test_url);
        
        // Clear static cache stats to start fresh
        $initial_stats = $this->cache_manager->get_static_cache_stats();
        error_log('ALO: Initial stats: ' . print_r($initial_stats, true));
        
        $lookup_results = [];
        $total_time = 0;
        
        // Perform multiple lookups with the same URL to test static cache
        for ($i = 1; $i <= 3; $i++) {
            error_log('ALO: Starting lookup #' . $i);
            $start_time = microtime(true);
            $result = attachment_url_to_postid($test_url);
            $end_time = microtime(true);
            
            $execution_time = ($end_time - $start_time) * 1000;
            $total_time += $execution_time;
            
            $lookup_results[] = [
                'call_number' => $i,
                'result_id' => $result,
                'execution_time_ms' => round($execution_time, 4),
                'expected_id' => $sample_attachment->ID,
                'success' => ($result == $sample_attachment->ID)
            ];
            
            error_log('ALO: Lookup #' . $i . ' result: ' . $result . ' in ' . round($execution_time, 4) . 'ms');
        }
        
        // Get final static cache stats
        $final_stats = $this->cache_manager->get_static_cache_stats();
        error_log('ALO: Final stats: ' . print_r($final_stats, true));
        
        // Calculate performance improvement
        $first_call_time = $lookup_results[0]['execution_time_ms'];
        $last_call_time = end($lookup_results)['execution_time_ms'];
        $performance_improvement = $first_call_time > 0 ? 
            round((($first_call_time - $last_call_time) / $first_call_time) * 100, 1) : 0;
        
        $response_data = [
            'test_url' => $test_url,
            'expected_id' => $sample_attachment->ID,
            'lookup_results' => $lookup_results,
            'initial_stats' => $initial_stats,
            'final_stats' => $final_stats,
            'total_time_ms' => round($total_time, 2),
            'average_time_ms' => round($total_time / count($lookup_results), 2),
            'performance_improvement_percent' => $performance_improvement,
            'static_cache_working' => $final_stats['static_cache_hits'] > 0,
            'message' => sprintf(
                __('Static cache test completed. Hits: %d, Total calls: %d, Hit ratio: %s%%', 'attachment-lookup-optimizer'),
                $final_stats['static_cache_hits'],
                $final_stats['total_calls'],
                $final_stats['static_hit_ratio']
            )
        ];
        
        error_log('ALO: Sending success response with data: ' . print_r($response_data, true));
        
        wp_send_json_success($response_data);
    }
    
    /**
     * AJAX handler for clearing shared stats cache
     */
    public function ajax_clear_shared_cache() {
        check_ajax_referer('alo_debug', 'nonce');
        
        if (!current_user_can('administrator')) {
            wp_send_json_error(['message' => __('Administrator privileges required', 'attachment-lookup-optimizer')]);
        }
        
        $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
        $shared_cache = $plugin->get_shared_stats_cache();
        
        if (!$shared_cache) {
            wp_send_json_error(['message' => __('Shared cache not available', 'attachment-lookup-optimizer')]);
        }
        
        // Clear all shared caches
        $shared_cache->clear_all_caches();
        
        wp_send_json_success([
            'message' => __('Shared cache cleared successfully', 'attachment-lookup-optimizer'),
            'timestamp' => current_time('mysql'),
            'cache_status' => $shared_cache->get_cache_status()
        ]);
    }
    
    /**
     * AJAX handler for reclaim disk space
     */
    public function ajax_reclaim_disk_space() {
        check_ajax_referer('alo_actions', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'attachment-lookup-optimizer')]);
        }
        
        $action = sanitize_text_field($_POST['action_type'] ?? 'start');
        // Use configurable batch size, but smaller for disk operations to be safer
        $configured_batch_size = get_option(self::BUNNYCDN_BATCH_SIZE_OPTION, 25);
        $batch_size = intval($_POST['batch_size'] ?? min($configured_batch_size, 20));
        $offset = intval($_POST['offset'] ?? 0);
        
        global $wpdb;
        
        try {
            if ($action === 'start' || $action === 'continue') {
                // Get attachments that have CDN URLs but are not offloaded
                $attachments = $wpdb->get_results($wpdb->prepare("
                    SELECT DISTINCT p1.post_id
                    FROM {$wpdb->postmeta} p1
                    LEFT JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id AND p2.meta_key = '_bunnycdn_offloaded'
                    WHERE p1.meta_key = '_bunnycdn_url'
                    AND p1.meta_value != ''
                    AND (p2.meta_value IS NULL OR p2.meta_value != '1')
                    LIMIT %d OFFSET %d
                ", $batch_size, $offset));
                
                $processed = 0;
                $successful = 0;
                $failed = 0;
                $errors = [];
                $space_reclaimed = 0;
                
                foreach ($attachments as $attachment) {
                    $attachment_id = $attachment->post_id;
                    $processed++;
                    
                    try {
                        // Get file size before deletion for space calculation
                        $local_path = get_attached_file($attachment_id);
                        $file_size = 0;
                        
                        if ($local_path && file_exists($local_path)) {
                            $file_size += filesize($local_path);
                        }
                        
                        // Get attachment metadata to find image sizes
                        $meta = wp_get_attachment_metadata($attachment_id);
                        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                            $upload_dir = wp_upload_dir();
                            $base_dir = $upload_dir['basedir'];
                            
                            if (!empty($meta['file'])) {
                                $file_dir = dirname($meta['file']);
                                $full_base_dir = $base_dir . '/' . $file_dir;
                            } else {
                                $full_base_dir = dirname($local_path);
                            }
                            
                            foreach ($meta['sizes'] as $size_data) {
                                if (!empty($size_data['file'])) {
                                    $size_file_path = $full_base_dir . '/' . $size_data['file'];
                                    if (file_exists($size_file_path)) {
                                        $file_size += filesize($size_file_path);
                                    }
                                }
                            }
                        }
                        
                        // Delete the main file
                        if ($local_path && file_exists($local_path)) {
                            if (@unlink($local_path)) {
                                error_log("ALO: Reclaim - deleted main file for attachment {$attachment_id}: {$local_path}");
                            } else {
                                throw new Exception("Failed to delete main file: {$local_path}");
                            }
                        }
                        
                        // Delete image size variants
                        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                            foreach ($meta['sizes'] as $size_name => $size_data) {
                                if (!empty($size_data['file'])) {
                                    $size_file_path = $full_base_dir . '/' . $size_data['file'];
                                    
                                    if (file_exists($size_file_path)) {
                                        if (@unlink($size_file_path)) {
                                            error_log("ALO: Reclaim - deleted {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                                        } else {
                                            error_log("ALO: Reclaim - failed to delete {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Mark attachment as offloaded
                        update_post_meta($attachment_id, '_bunnycdn_offloaded', true);
                        update_post_meta($attachment_id, '_bunnycdn_offloaded_at', current_time('mysql', true));
                        
                        $space_reclaimed += $file_size;
                        $successful++;
                        
                        error_log("ALO: Reclaim - completed for attachment {$attachment_id}, reclaimed " . $this->format_bytes($file_size));
                        
                    } catch (Exception $e) {
                        $failed++;
                        $error_msg = "Attachment {$attachment_id}: " . $e->getMessage();
                        $errors[] = $error_msg;
                        error_log("ALO: Reclaim - error for attachment {$attachment_id}: " . $e->getMessage());
                    }
                }
                
                // Get total count for progress calculation
                $total_reclaimable = $wpdb->get_var("
                    SELECT COUNT(DISTINCT p1.post_id)
                    FROM {$wpdb->postmeta} p1
                    LEFT JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id AND p2.meta_key = '_bunnycdn_offloaded'
                    WHERE p1.meta_key = '_bunnycdn_url'
                    AND p1.meta_value != ''
                    AND (p2.meta_value IS NULL OR p2.meta_value != '1')
                ");
                
                $new_offset = $offset + $processed;
                $progress = $total_reclaimable > 0 ? min(100, round($new_offset / ($total_reclaimable + $new_offset) * 100)) : 100;
                $is_complete = empty($attachments) || count($attachments) < $batch_size;
                
                // Update last reclaim time
                if ($is_complete) {
                    update_option('alo_last_disk_reclaim', current_time('mysql'));
                }
                
                wp_send_json_success([
                    'processed' => $processed,
                    'successful' => $successful,
                    'failed' => $failed,
                    'errors' => $errors,
                    'space_reclaimed' => $space_reclaimed,
                    'space_reclaimed_formatted' => $this->format_bytes($space_reclaimed),
                    'offset' => $new_offset,
                    'total_reclaimable' => $total_reclaimable,
                    'progress' => $progress,
                    'is_complete' => $is_complete,
                    'message' => sprintf(
                        __('Processed %d files. Success: %d, Failed: %d. Space reclaimed: %s', 'attachment-lookup-optimizer'),
                        $processed,
                        $successful,
                        $failed,
                        $this->format_bytes($space_reclaimed)
                    )
                ]);
                
            } elseif ($action === 'status') {
                // Get current status
                $total_reclaimable = $wpdb->get_var("
                    SELECT COUNT(DISTINCT p1.post_id)
                    FROM {$wpdb->postmeta} p1
                    LEFT JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id AND p2.meta_key = '_bunnycdn_offloaded'
                    WHERE p1.meta_key = '_bunnycdn_url'
                    AND p1.meta_value != ''
                    AND (p2.meta_value IS NULL OR p2.meta_value != '1')
                ");
                
                $last_reclaim = get_option('alo_last_disk_reclaim');
                
                wp_send_json_success([
                    'total_reclaimable' => $total_reclaimable,
                    'last_reclaim' => $last_reclaim,
                    'last_reclaim_formatted' => $last_reclaim ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_reclaim)) : __('Never', 'attachment-lookup-optimizer')
                ]);
            }
            
        } catch (Exception $e) {
            error_log("ALO: Reclaim disk space error: " . $e->getMessage());
            wp_send_json_error(['message' => __('Error during disk space reclaim: ', 'attachment-lookup-optimizer') . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX handler for reset offload status
     */
    public function ajax_reset_offload_status() {
        check_ajax_referer('alo_actions', 'nonce');
        
        if (!current_user_can('administrator')) {
            wp_send_json_error(['message' => __('Administrator privileges required', 'attachment-lookup-optimizer')]);
        }
        
        $action = sanitize_text_field($_POST['action_type'] ?? 'start');
        // Use configurable batch size for reset operations
        $configured_batch_size = get_option(self::BUNNYCDN_BATCH_SIZE_OPTION, 25);
        $batch_size = intval($_POST['batch_size'] ?? max($configured_batch_size, 25));
        $offset = intval($_POST['offset'] ?? 0);
        
        // Get reset options
        $reset_offload_status = isset($_POST['reset_offload_status']) && $_POST['reset_offload_status'] === 'true';
        $reset_cdn_urls = isset($_POST['reset_cdn_urls']) && $_POST['reset_cdn_urls'] === 'true';
        $reset_timestamps = isset($_POST['reset_timestamps']) && $_POST['reset_timestamps'] === 'true';
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        
        global $wpdb;
        
        try {
            if ($action === 'start' || $action === 'continue') {
                // Build the query to find attachments to reset
                $where_conditions = ["p.post_type = 'attachment'"];
                $join_conditions = [];
                
                // Add date range filter if specified
                if (!empty($date_from) || !empty($date_to)) {
                    if (!empty($date_from)) {
                        $where_conditions[] = $wpdb->prepare("p.post_date >= %s", $date_from . ' 00:00:00');
                    }
                    if (!empty($date_to)) {
                        $where_conditions[] = $wpdb->prepare("p.post_date <= %s", $date_to . ' 23:59:59');
                    }
                }
                
                // Only get attachments that have offload-related metadata
                if ($reset_offload_status || $reset_cdn_urls || $reset_timestamps) {
                    $meta_conditions = [];
                    if ($reset_offload_status) {
                        $meta_conditions[] = "pm.meta_key = '_bunnycdn_offloaded'";
                    }
                    if ($reset_cdn_urls) {
                        $meta_conditions[] = "pm.meta_key = '_bunnycdn_url'";
                    }
                    if ($reset_timestamps) {
                        $meta_conditions[] = "pm.meta_key = '_bunnycdn_offloaded_at'";
                    }
                    
                    $join_conditions[] = "INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id";
                    $where_conditions[] = "(" . implode(' OR ', $meta_conditions) . ")";
                }
                
                $query = "
                    SELECT DISTINCT p.ID
                    FROM {$wpdb->posts} p
                    " . implode(' ', $join_conditions) . "
                    WHERE " . implode(' AND ', $where_conditions) . "
                    ORDER BY p.ID
                    LIMIT %d OFFSET %d
                ";
                
                $attachments = $wpdb->get_results($wpdb->prepare($query, $batch_size, $offset));
                
                $processed = 0;
                $successful = 0;
                $failed = 0;
                $errors = [];
                $reset_counts = [
                    'offload_status' => 0,
                    'cdn_urls' => 0,
                    'timestamps' => 0
                ];
                
                foreach ($attachments as $attachment) {
                    $attachment_id = $attachment->ID;
                    $processed++;
                    
                    try {
                        $reset_actions = [];
                        
                        // Reset offload status
                        if ($reset_offload_status) {
                            $deleted = delete_post_meta($attachment_id, '_bunnycdn_offloaded');
                            if ($deleted) {
                                $reset_counts['offload_status']++;
                                $reset_actions[] = 'offload status';
                            }
                        }
                        
                        // Reset CDN URLs
                        if ($reset_cdn_urls) {
                            $deleted = delete_post_meta($attachment_id, '_bunnycdn_url');
                            if ($deleted) {
                                $reset_counts['cdn_urls']++;
                                $reset_actions[] = 'CDN URL';
                            }
                        }
                        
                        // Reset timestamps
                        if ($reset_timestamps) {
                            $deleted = delete_post_meta($attachment_id, '_bunnycdn_offloaded_at');
                            if ($deleted) {
                                $reset_counts['timestamps']++;
                                $reset_actions[] = 'timestamp';
                            }
                        }
                        
                        if (!empty($reset_actions)) {
                            $successful++;
                            error_log("ALO: Reset offload status for attachment {$attachment_id}: " . implode(', ', $reset_actions));
                        }
                        
                    } catch (Exception $e) {
                        $failed++;
                        $error_msg = "Attachment {$attachment_id}: " . $e->getMessage();
                        $errors[] = $error_msg;
                        error_log("ALO: Reset offload status error for attachment {$attachment_id}: " . $e->getMessage());
                    }
                }
                
                // Get total count for progress calculation
                $total_query = "
                    SELECT COUNT(DISTINCT p.ID)
                    FROM {$wpdb->posts} p
                    " . implode(' ', $join_conditions) . "
                    WHERE " . implode(' AND ', $where_conditions);
                
                $total_attachments = $wpdb->get_var($total_query);
                
                $new_offset = $offset + $processed;
                $progress = $total_attachments > 0 ? min(100, round($new_offset / $total_attachments * 100)) : 100;
                $is_complete = empty($attachments) || count($attachments) < $batch_size;
                
                // Update last reset time
                if ($is_complete) {
                    update_option('alo_last_offload_reset', current_time('mysql'));
                }
                
                wp_send_json_success([
                    'processed' => $processed,
                    'successful' => $successful,
                    'failed' => $failed,
                    'errors' => $errors,
                    'reset_counts' => $reset_counts,
                    'offset' => $new_offset,
                    'total_attachments' => $total_attachments,
                    'progress' => $progress,
                    'is_complete' => $is_complete,
                    'message' => sprintf(
                        __('Processed %d attachments. Success: %d, Failed: %d. Reset: %d offload status, %d CDN URLs, %d timestamps', 'attachment-lookup-optimizer'),
                        $processed,
                        $successful,
                        $failed,
                        $reset_counts['offload_status'],
                        $reset_counts['cdn_urls'],
                        $reset_counts['timestamps']
                    )
                ]);
                
            } elseif ($action === 'status') {
                // Get current status
                $offloaded_count = $wpdb->get_var("
                    SELECT COUNT(*)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_bunnycdn_offloaded'
                    AND meta_value = '1'
                ");
                
                $cdn_url_count = $wpdb->get_var("
                    SELECT COUNT(*)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_bunnycdn_url'
                    AND meta_value != ''
                ");
                
                $last_reset = get_option('alo_last_offload_reset');
                
                wp_send_json_success([
                    'offloaded_count' => $offloaded_count,
                    'cdn_url_count' => $cdn_url_count,
                    'last_reset' => $last_reset,
                    'last_reset_formatted' => $last_reset ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_reset)) : __('Never', 'attachment-lookup-optimizer')
                ]);
            }
            
        } catch (Exception $e) {
            error_log("ALO: Reset offload status error: " . $e->getMessage());
            wp_send_json_error(['message' => __('Error during offload status reset: ', 'attachment-lookup-optimizer') . $e->getMessage()]);
        }
    }
    
    /**
     * Get supported post types for content URL rewriting
     * 
     * @return array List of supported post types
     */
    private function get_supported_post_types() {
        // Define default supported post types
        $default_post_types = ['post', 'page'];
        
        // Get all public post types
        $all_post_types = get_post_types(['public' => true], 'names');
        
        // Add common custom post types that typically have content
        $common_custom_types = ['tickets', 'products', 'events', 'portfolio', 'testimonials', 'services'];
        
        $supported_post_types = $default_post_types;
        
        // Add custom post types that exist on this site
        foreach ($common_custom_types as $post_type) {
            if (in_array($post_type, $all_post_types)) {
                $supported_post_types[] = $post_type;
            }
        }
        
        // Allow filtering of supported post types
        $supported_post_types = apply_filters('alo_content_rewrite_post_types', $supported_post_types);
        
        // Ensure we have valid post types
        $supported_post_types = array_intersect($supported_post_types, $all_post_types);
        
        return array_unique($supported_post_types);
    }
    
    /**
     * Process custom fields for a post during bulk rewriting
     * 
     * @param int $post_id The post ID to process
     * @param array $matched_attachments Array of attachment IDs that were already processed
     * @return int Number of custom field replacements made
     */
    private function process_custom_fields_for_post($post_id, $matched_attachments = []) {
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
            
            // Get JetEngine fields if available
            $jetengine_fields = $this->get_jetengine_fields_for_post($post_id);
            
            // Process JetFormBuilder fields first (they have specific handling)
            $jetformbuilder_replacements = $this->process_jetformbuilder_fields_for_post($post_id);
            $total_replacements += $jetformbuilder_replacements;
            
            foreach ($all_meta as $meta_key => $meta_values) {
                // Skip WordPress internal meta fields unless they're JetEngine/JetFormBuilder fields
                if (strpos($meta_key, '_') === 0 && !$this->is_jetengine_field($meta_key, $jetengine_fields)) {
                    continue;
                }
                
                // Process each meta value
                foreach ($meta_values as $meta_value) {
                    $original_value = $meta_value;
                    $updated_value = $this->process_custom_field_value($meta_value);
                    
                    if ($updated_value !== $original_value) {
                        // Update the meta field
                        update_post_meta($post_id, $meta_key, $updated_value, $original_value);
                        $total_replacements++;
                        
                        error_log(sprintf(
                            '[BunnyCDN Bulk Rewriter] Post %d, Custom Field %s: Updated with CDN URLs',
                            $post_id,
                            $meta_key
                        ));
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('[BunnyCDN Bulk Rewriter] Error processing custom fields for post ' . $post_id . ': ' . $e->getMessage());
        }
        
        return $total_replacements;
    }
    
    /**
     * Get JetEngine fields for a post
     * 
     * @param int $post_id
     * @return array JetEngine field definitions
     */
    private function get_jetengine_fields_for_post($post_id) {
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
            error_log('[BunnyCDN Bulk Rewriter] Error getting JetEngine fields: ' . $e->getMessage());
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
     * Process a custom field value for URL rewriting
     * 
     * @param mixed $value The field value to process
     * @return mixed The processed value
     */
    private function process_custom_field_value($value) {
        if (is_string($value)) {
            return $this->process_string_custom_field($value);
        } elseif (is_array($value)) {
            return $this->process_array_custom_field($value);
        } elseif (is_numeric($value)) {
            return $this->process_numeric_custom_field($value);
        }
        
        return $value;
    }
    
    /**
     * Process string custom field values
     * 
     * @param string $value
     * @return string
     */
    private function process_string_custom_field($value) {
        // Check if the string contains /wp-content/uploads/ URLs
        if (strpos($value, '/wp-content/uploads/') === false) {
            return $value;
        }
        
        // Pattern to match /wp-content/uploads/ URLs
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $pattern = '#' . preg_quote($upload_url, '#') . '/[^\s\'"<>]+#i';
        
        return preg_replace_callback($pattern, function($matches) {
            $url = $matches[0];
            
            // Try to resolve the URL to an attachment ID
            $attachment_id = attachment_url_to_postid($url);
            
                         if (!$attachment_id) {
                // Try alternative method using get_posts
                $relative_path = str_replace(wp_upload_dir()['baseurl'] . '/', '', $url);
                $attachment_posts = get_posts([
                    'post_type' => 'attachment',
                    'meta_query' => [
                        [
                            'key' => '_wp_attached_file',
                            'value' => $relative_path,
                            'compare' => 'LIKE'
                        ]
                    ],
                    'posts_per_page' => 1
                ]);
                
                if (!empty($attachment_posts)) {
                    $attachment_id = $attachment_posts[0]->ID;
                }
            }
            
            if ($attachment_id) {
                // Check if this attachment has a BunnyCDN URL
                $cdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                
                if (!empty($cdn_url)) {
                    return $cdn_url;
                }
            }
            
            // Return original URL if no CDN URL found
            return $url;
        }, $value);
    }
    
    /**
     * Process array custom field values (repeater fields, etc.)
     * 
     * @param array $value
     * @return array
     */
    private function process_array_custom_field($value) {
        $processed_array = [];
        
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $processed_array[$key] = $this->process_string_custom_field($item);
            } elseif (is_array($item)) {
                // Recursively process nested arrays
                $processed_array[$key] = $this->process_array_custom_field($item);
            } elseif (is_numeric($item)) {
                $processed_array[$key] = $this->process_numeric_custom_field($item);
            } else {
                $processed_array[$key] = $item;
            }
        }
        
        return $processed_array;
    }
    
    /**
     * Process numeric custom field values (attachment IDs)
     * 
     * @param mixed $value
     * @return mixed
     */
    private function process_numeric_custom_field($value) {
        // Check if it's a potential attachment ID
        if (is_numeric($value) && intval($value) > 0) {
            $attachment_id = intval($value);
            
            // Verify it's actually an attachment
            if (get_post_type($attachment_id) === 'attachment') {
                $bunnycdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                
                if (!empty($bunnycdn_url)) {
                    // For now, keep as ID since most fields expect IDs
                    // This could be made configurable in the future
                    return $value;
                }
            }
        }
        
        return $value;
    }
    
    /**
     * Process JetFormBuilder fields for a post during bulk rewriting
     * 
     * @param int $post_id The post ID to process
     * @return int Number of JetFormBuilder field replacements made
     */
    private function process_jetformbuilder_fields_for_post($post_id) {
        $total_replacements = 0;
        
        // Check if JetFormBuilder is active
        if (!class_exists('Jet_Form_Builder\Plugin') && !function_exists('jet_form_builder')) {
            return 0;
        }
        
        try {
            // Get all meta for the post to identify JetFormBuilder fields
            $all_meta = get_post_meta($post_id);
            
            foreach ($all_meta as $meta_key => $meta_values) {
                if (!$this->is_jetformbuilder_field($meta_key)) {
                    continue;
                }
                
                // Detect if this is a file field
                $field_value = $meta_values[0] ?? '';
                $is_file_field = $this->is_jetformbuilder_file_field($meta_key, $field_value);
                
                if (!$is_file_field) {
                    continue;
                }
                
                $original_value = $field_value;
                $updated_value = $this->process_jetformbuilder_field_value_bulk($field_value);
                
                if ($updated_value !== $original_value) {
                    update_post_meta($post_id, $meta_key, $updated_value);
                    $total_replacements++;
                    
                    error_log(sprintf(
                        '[BunnyCDN Bulk JetFormBuilder] Post %d, Field %s: Updated with CDN URLs',
                        $post_id,
                        $meta_key
                    ));
                }
            }
            
        } catch (Exception $e) {
            error_log('[BunnyCDN Bulk JetFormBuilder] Error processing post ' . $post_id . ': ' . $e->getMessage());
        }
        
        return $total_replacements;
    }
    
    /**
     * Check if a JetFormBuilder field is a file field
     * 
     * @param string $field_name
     * @param mixed $field_value
     * @return bool
     */
    private function is_jetformbuilder_file_field($field_name, $field_value) {
        // If value is numeric and corresponds to an attachment, it's a file field
        if (is_numeric($field_value) && intval($field_value) > 0) {
            $attachment_id = intval($field_value);
            if (get_post_type($attachment_id) === 'attachment') {
                return true;
            }
        }
        
        // If value is a URL pointing to uploads directory, it's a file field
        if (is_string($field_value) && strpos($field_value, '/wp-content/uploads/') !== false) {
            return true;
        }
        
        // If value is an array, check if it contains file references
        if (is_array($field_value)) {
            foreach ($field_value as $item) {
                if ($this->is_jetformbuilder_file_field($field_name, $item)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Process JetFormBuilder field value during bulk rewriting
     * 
     * @param mixed $value
     * @return mixed
     */
    private function process_jetformbuilder_field_value_bulk($value) {
        // Handle attachment ID
        if (is_numeric($value) && intval($value) > 0) {
            $attachment_id = intval($value);
            
            if (get_post_type($attachment_id) === 'attachment') {
                $bunnycdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                if (!empty($bunnycdn_url)) {
                    // For JetFormBuilder, we might want to keep IDs or convert to URLs
                    // This could be made configurable - for now keep as ID
                    return $value;
                }
            }
        }
        
        // Handle URL string
        if (is_string($value) && strpos($value, '/wp-content/uploads/') !== false) {
            // Try to resolve URL to attachment and get CDN URL
            $attachment_id = attachment_url_to_postid($value);
            
            if (!$attachment_id) {
                // Try alternative method
                $upload_dir = wp_upload_dir();
                $relative_path = str_replace($upload_dir['baseurl'] . '/', '', $value);
                $attachment_posts = get_posts([
                    'post_type' => 'attachment',
                    'meta_query' => [
                        [
                            'key' => '_wp_attached_file',
                            'value' => $relative_path,
                            'compare' => 'LIKE'
                        ]
                    ],
                    'posts_per_page' => 1
                ]);
                
                if (!empty($attachment_posts)) {
                    $attachment_id = $attachment_posts[0]->ID;
                }
            }
            
            if ($attachment_id) {
                $bunnycdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                if (!empty($bunnycdn_url)) {
                    return $bunnycdn_url;
                }
            }
        }
        
        // Handle array (gallery fields)
        if (is_array($value)) {
            $processed_array = [];
            foreach ($value as $key => $item) {
                $processed_array[$key] = $this->process_jetformbuilder_field_value_bulk($item);
            }
            return $processed_array;
        }
        
        return $value;
    }
    
    /**
     * AJAX handler for content URL rewriting tool
     */
    public function ajax_rewrite_content_urls() {
        check_ajax_referer('alo_actions', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'attachment-lookup-optimizer')]);
        }
        
        $action = sanitize_text_field($_POST['action_type'] ?? 'start');
        $batch_size = intval($_POST['batch_size'] ?? 10);
        $offset = intval($_POST['offset'] ?? 0);
        $selected_post_type = sanitize_text_field($_POST['post_type'] ?? 'all');
        
        global $wpdb;
        
        try {
            if ($action === 'start' || $action === 'continue') {
                // Get supported post types
                $supported_post_types = $this->get_supported_post_types();
                
                // Filter post types based on selection
                if ($selected_post_type !== 'all' && in_array($selected_post_type, $supported_post_types)) {
                    $post_types_to_query = [$selected_post_type];
                } else {
                    $post_types_to_query = $supported_post_types;
                }
                
                // Build the IN clause for post types
                $post_types_placeholders = implode(',', array_fill(0, count($post_types_to_query), '%s'));
                
                // Get posts that haven't been processed yet
                $query = $wpdb->prepare("
                    SELECT p.ID, p.post_title, p.post_type, p.post_content
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bunnycdn_rewritten'
                    WHERE p.post_type IN ($post_types_placeholders)
                    AND p.post_status = 'publish'
                    AND pm.meta_value IS NULL
                    ORDER BY p.post_modified DESC
                    LIMIT %d OFFSET %d
                ", array_merge($post_types_to_query, [$batch_size, $offset]));
                
                $posts = $wpdb->get_results($query);
                
                $processed = 0;
                $updated = 0;
                $skipped = 0;
                $errors = [];
                $total_replacements = 0;
                $updated_posts = [];
                $current_post_info = null;
                
                foreach ($posts as $post) {
                    $processed++;
                    $current_post_info = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'type' => $post->post_type
                    ];
                    
                    try {
                        $original_content = $post->post_content;
                        $updated_content = $original_content;
                        $post_replacements = 0;
                        $matched_attachments = [];
                        
                        // Find all image URLs in the content that point to wp-content/uploads/
                        $upload_dir = wp_upload_dir();
                        $upload_url = $upload_dir['baseurl'];
                        
                        // Pattern to match image URLs in various contexts
                        $pattern = '#(?:src|href)=["\']([^"\']*' . preg_quote($upload_url, '#') . '/[^"\']*)["\']#i';
                        
                        if (preg_match_all($pattern, $original_content, $matches, PREG_SET_ORDER)) {
                            foreach ($matches as $match) {
                                $local_url = $match[1];
                                
                                // Try to find the attachment ID for this URL
                                $attachment_id = attachment_url_to_postid($local_url);
                                
                                if (!$attachment_id) {
                                    // Try alternative method using get_posts
                                    $relative_path = str_replace($upload_url . '/', '', $local_url);
                                    $attachment_posts = get_posts([
                                        'post_type' => 'attachment',
                                        'meta_query' => [
                                            [
                                                'key' => '_wp_attached_file',
                                                'value' => $relative_path,
                                                'compare' => 'LIKE'
                                            ]
                                        ],
                                        'posts_per_page' => 1
                                    ]);
                                    
                                    if (!empty($attachment_posts)) {
                                        $attachment_id = $attachment_posts[0]->ID;
                                    }
                                }
                                
                                if ($attachment_id) {
                                    // Check if this attachment has a BunnyCDN URL
                                    $cdn_url = get_post_meta($attachment_id, '_bunnycdn_url', true);
                                    
                                    if ($cdn_url) {
                                        // Replace the local URL with the CDN URL
                                        $updated_content = str_replace($local_url, $cdn_url, $updated_content);
                                        $post_replacements++;
                                        $matched_attachments[] = $attachment_id;
                                    }
                                }
                            }
                        }
                        
                        // Process JetEngine custom fields
                        $custom_field_replacements = $this->process_custom_fields_for_post($post->ID, $matched_attachments);
                        $post_replacements += $custom_field_replacements;
                        
                        // Update the post if we made replacements
                        if ($post_replacements > 0 && ($updated_content !== $original_content || $custom_field_replacements > 0)) {
                            // Only update post content if it was actually modified
                            $update_success = true;
                            if ($updated_content !== $original_content) {
                                $update_result = wp_update_post([
                                    'ID' => $post->ID,
                                    'post_content' => $updated_content
                                ]);
                                $update_success = !is_wp_error($update_result) && $update_result !== 0;
                            }
                            
                            if ($update_success) {
                                // Mark post as rewritten
                                update_post_meta($post->ID, '_bunnycdn_rewritten', $matched_attachments);
                                
                                // Store rewrite date and count for the new table
                                update_post_meta($post->ID, '_bunnycdn_rewrite_date', current_time('mysql'));
                                update_post_meta($post->ID, '_bunnycdn_rewrite_count', $post_replacements);
                                
                                $updated++;
                                $total_replacements += $post_replacements;
                                $updated_posts[] = [
                                    'id' => $post->ID,
                                    'title' => $post->post_title,
                                    'type' => $post->post_type,
                                    'replacements' => $post_replacements,
                                    'attachments' => count($matched_attachments)
                                ];
                                
                                error_log("ALO: Content URL rewriter updated post {$post->ID} ({$post->post_type}): {$post_replacements} URL replacements");
                            } else {
                                $skipped++;
                                $error_msg = "Post {$post->ID}: Failed to update post content";
                                $errors[] = $error_msg;
                                error_log("ALO: Content URL rewriter error for post {$post->ID}: Failed to update post content");
                            }
                        } else {
                            // No URLs to replace, mark as processed anyway
                            update_post_meta($post->ID, '_bunnycdn_rewritten', []);
                            update_post_meta($post->ID, '_bunnycdn_rewrite_date', current_time('mysql'));
                            update_post_meta($post->ID, '_bunnycdn_rewrite_count', 0);
                            $skipped++;
                        }
                        
                    } catch (Exception $e) {
                        $skipped++;
                        $error_msg = "Post {$post->ID}: " . $e->getMessage();
                        $errors[] = $error_msg;
                        error_log("ALO: Content URL rewriter error for post {$post->ID}: " . $e->getMessage());
                    }
                }
                
                // Get total count for progress calculation
                $total_posts = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*)
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bunnycdn_rewritten'
                    WHERE p.post_type IN ($post_types_placeholders)
                    AND p.post_status = 'publish'
                    AND pm.meta_value IS NULL
                ", $post_types_to_query));
                
                $new_offset = $offset + $processed;
                $progress = $total_posts > 0 ? min(100, round($new_offset / $total_posts * 100)) : 100;
                $is_complete = empty($posts) || count($posts) < $batch_size;
                
                // Update last rewrite time
                if ($is_complete) {
                    update_option('alo_last_content_rewrite', current_time('mysql'));
                }
                
                wp_send_json_success([
                    'processed' => $processed,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'total_replacements' => $total_replacements,
                    'updated_posts' => $updated_posts,
                    'offset' => $new_offset,
                    'total_posts' => $total_posts,
                    'progress' => $progress,
                    'is_complete' => $is_complete,
                    'current_post' => $current_post_info,
                    'batch_size' => $batch_size,
                    'message' => sprintf(
                        __('Processed %d posts. Updated: %d, Skipped: %d. Total URL replacements: %d', 'attachment-lookup-optimizer'),
                        $processed,
                        $updated,
                        $skipped,
                        $total_replacements
                    )
                ]);
                
            } elseif ($action === 'status') {
                // Get supported post types for status
                $supported_post_types = $this->get_supported_post_types();
                
                // Filter post types based on selection
                if ($selected_post_type !== 'all' && in_array($selected_post_type, $supported_post_types)) {
                    $post_types_to_query = [$selected_post_type];
                } else {
                    $post_types_to_query = $supported_post_types;
                }
                
                $post_types_placeholders = implode(',', array_fill(0, count($post_types_to_query), '%s'));
                
                // Get current status
                $total_posts = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*)
                    FROM {$wpdb->posts}
                    WHERE post_type IN ($post_types_placeholders)
                    AND post_status = 'publish'
                ", $post_types_to_query));
                
                $rewritten_posts = $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_bunnycdn_rewritten'
                ");
                
                $cdn_attachments = $wpdb->get_var("
                    SELECT COUNT(*)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_bunnycdn_url'
                    AND meta_value != ''
                ");
                
                $last_rewrite = get_option('alo_last_content_rewrite');
                
                wp_send_json_success([
                    'total_posts' => $total_posts,
                    'rewritten_posts' => $rewritten_posts,
                    'cdn_attachments' => $cdn_attachments,
                    'last_rewrite' => $last_rewrite,
                    'last_rewrite_formatted' => $last_rewrite ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_rewrite)) : __('Never', 'attachment-lookup-optimizer')
                ]);
            }
            
        } catch (Exception $e) {
            error_log("ALO: Content URL rewriter error: " . $e->getMessage());
            wp_send_json_error(['message' => __('Error during content URL rewriting: ', 'attachment-lookup-optimizer') . $e->getMessage()]);
        }
    }
    
    /**
     * BunnyCDN Rewritten Posts page
     */
    public function bunnycdn_rewritten_posts_page() {
        // Handle individual post reprocessing
        if (isset($_POST['reprocess_post_id']) && isset($_POST['reprocess_nonce'])) {
            $post_id = intval($_POST['reprocess_post_id']);
            $nonce = sanitize_text_field($_POST['reprocess_nonce']);
            
            if (wp_verify_nonce($nonce, 'bunnycdn_reprocess_post_' . $post_id)) {
                // Reset the post for reprocessing
                delete_post_meta($post_id, '_bunnycdn_rewritten');
                delete_post_meta($post_id, '_bunnycdn_rewrite_date');
                delete_post_meta($post_id, '_bunnycdn_rewrite_count');
                
                add_action('admin_notices', function() use ($post_id) {
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('Post #%d has been marked for reprocessing. Run the Content URL Rewriting tool to reprocess this post.', 'attachment-lookup-optimizer'), $post_id) . 
                         '</p></div>';
                });
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . __('Security check failed.', 'attachment-lookup-optimizer') . '</p></div>';
                });
            }
        }
        
        // Create and prepare the list table
        $list_table = new BunnyCDNRewrittenPostsTable();
        $list_table->process_bulk_action();
        $list_table->prepare_items();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <hr class="wp-header-end">
            
            <!-- Page Header -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0;"><?php _e('📝 Content URL Rewriting Overview', 'attachment-lookup-optimizer'); ?></h2>
                <p><?php _e('This page displays all posts and pages that have been processed by the BunnyCDN Content URL Rewriting tool. You can view details about each rewritten post, see how many BunnyCDN URLs are being used, and reprocess posts if needed.', 'attachment-lookup-optimizer'); ?></p>
                
                <?php
                // Display summary statistics
                global $wpdb;
                $total_rewritten = $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_bunnycdn_rewritten'
                ");
                
                $total_urls = $wpdb->get_var("
                    SELECT SUM(CAST(meta_value AS UNSIGNED))
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_bunnycdn_rewrite_count'
                    AND meta_value != ''
                ");
                
                $recent_rewrites = $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_bunnycdn_rewrite_date'
                    AND meta_value > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ");
                ?>
                
                <div style="display: flex; gap: 20px; margin: 15px 0;">
                    <div style="background: #e8f4fd; padding: 15px; border-radius: 4px; flex: 1; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_rewritten); ?></div>
                        <div style="color: #555;"><?php _e('Total Rewritten Posts', 'attachment-lookup-optimizer'); ?></div>
                    </div>
                    <div style="background: #f0f8ff; padding: 15px; border-radius: 4px; flex: 1; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_urls ?: 0); ?></div>
                        <div style="color: #555;"><?php _e('Total BunnyCDN URLs', 'attachment-lookup-optimizer'); ?></div>
                    </div>
                    <div style="background: #f6f7f7; padding: 15px; border-radius: 4px; flex: 1; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo number_format($recent_rewrites); ?></div>
                        <div style="color: #555;"><?php _e('Rewritten This Week', 'attachment-lookup-optimizer'); ?></div>
                    </div>
                </div>
                
                <div style="margin-top: 15px;">
                    <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>" class="button button-primary">
                        <?php _e('🔄 Run Content URL Rewriting Tool', 'attachment-lookup-optimizer'); ?>
                    </a>
                    <a href="<?php echo admin_url('tools.php?page=attachment-lookup-optimizer'); ?>" class="button button-secondary">
                        <?php _e('⚙️ BunnyCDN Settings', 'attachment-lookup-optimizer'); ?>
                    </a>
                </div>
            </div>
            
            <!-- Search Form -->
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                <?php $list_table->search_box(__('Search posts', 'attachment-lookup-optimizer'), 'search_posts'); ?>
            </form>
            
            <!-- List Table -->
            <form method="post">
                <?php $list_table->display(); ?>
            </form>
            
            <!-- Help Section -->
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php _e('💡 How to Use This Page', 'attachment-lookup-optimizer'); ?></h3>
                <ul style="margin: 0;">
                    <li><strong><?php _e('View Details:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Click on post titles to edit posts or use the "Details" button to see rewrite information.', 'attachment-lookup-optimizer'); ?></li>
                    <li><strong><?php _e('Reprocess Posts:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Use the "Reprocess" button to mark posts for re-processing by the Content URL Rewriting tool.', 'attachment-lookup-optimizer'); ?></li>
                    <li><strong><?php _e('Bulk Actions:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Select multiple posts and use bulk actions to reprocess or reset multiple posts at once.', 'attachment-lookup-optimizer'); ?></li>
                    <li><strong><?php _e('Search & Filter:', 'attachment-lookup-optimizer'); ?></strong> <?php _e('Use the search box to find specific posts by title or ID, and filter by post type.', 'attachment-lookup-optimizer'); ?></li>
                </ul>
            </div>
        </div>
        
        <!-- JavaScript for individual post actions -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Handle individual post reprocessing
            $('.bunnycdn-reprocess-post').on('click', function() {
                const postId = $(this).data('post-id');
                const nonce = $(this).data('nonce');
                const button = $(this);
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to reprocess this post? This will mark it for re-processing by the Content URL Rewriting tool.', 'attachment-lookup-optimizer')); ?>')) {
                    return;
                }
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'attachment-lookup-optimizer')); ?>');
                
                // Create a hidden form and submit it
                const form = $('<form method="post" style="display: none;">')
                    .append('<input type="hidden" name="reprocess_post_id" value="' + postId + '">')
                    .append('<input type="hidden" name="reprocess_nonce" value="' + nonce + '">');
                
                $('body').append(form);
                form.submit();
            });
            
            // Handle view details
            $('.bunnycdn-view-details').on('click', function() {
                const postId = $(this).data('post-id');
                
                // Create a simple modal or redirect to edit post
                const editUrl = '<?php echo admin_url('post.php?action=edit&post='); ?>' + postId;
                window.open(editUrl, '_blank');
            });
        });
        </script>
        
        <!-- Custom CSS for the table -->
        <style type="text/css">
        .bunnycdn-url-count {
            background: #e8f4fd;
            color: #0073aa;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .bunnycdn-url-count-zero {
            background: #f6f7f7;
            color: #666;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .bunnycdn-reprocess-post {
            background: #0073aa !important;
            border-color: #0073aa !important;
            color: #fff !important;
        }
        
        .bunnycdn-reprocess-post:hover {
            background: #005a87 !important;
            border-color: #005a87 !important;
        }
        
        .bunnycdn-view-details {
            background: #f0f0f1 !important;
            border-color: #c3c4c7 !important;
            color: #2c3338 !important;
        }
        
        .bunnycdn-view-details:hover {
            background: #e0e0e0 !important;
        }
        
        .wp-list-table .column-post_id {
            width: 80px;
        }
        
        .wp-list-table .column-post_type {
            width: 100px;
        }
        
        .wp-list-table .column-bunnycdn_urls {
            width: 120px;
        }
        
        .wp-list-table .column-rewrite_date {
            width: 150px;
        }
        
        .wp-list-table .column-actions {
            width: 150px;
        }
        </style>
        <?php
    }
}