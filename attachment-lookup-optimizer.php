<?php
/**
 * Plugin Name: Attachment Lookup Optimizer
 * Plugin URI: https://sparkwebstudio.com/attachment-lookup-optimizer
 * Description: Optimizes attachment lookups by adding database indexes and caching attachment_url_to_postid() results.
 * Version: 1.1.0
 * Author: SPARKWEB Studio
 * Author URI: https://sparkwebstudio.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: attachment-lookup-optimizer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Define plugin constants
define('ALO_VERSION', '1.1.0');
define('ALO_PLUGIN_FILE', __FILE__);
define('ALO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader for classes
spl_autoload_register(function ($class) {
    $prefix = 'AttachmentLookupOptimizer\\';
    $base_dir = ALO_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
add_action('plugins_loaded', function() {
    \AttachmentLookupOptimizer\Plugin::getInstance();
});

// Initialize automated log cleanup
add_action('plugins_loaded', function() {
    \AttachmentLookupOptimizer\DebugLogger::init_automated_cleanup();
});

// Activation hook
register_activation_hook(__FILE__, function() {
    \AttachmentLookupOptimizer\Plugin::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    \AttachmentLookupOptimizer\Plugin::deactivate();
});

// Add custom cron schedule for background processing
add_filter('cron_schedules', function($schedules) {
    $schedules['alo_5min'] = array(
        'interval' => 300, // 5 minutes in seconds
        'display' => __('Every 5 Minutes (ALO Background Processing)', 'attachment-lookup-optimizer')
    );
    $schedules['alo_1min'] = array(
        'interval' => 60, // 1 minute in seconds
        'display' => __('Every Minute (ALO BunnyCDN Sync)', 'attachment-lookup-optimizer')
    );
    return $schedules;
});

/**
 * Global helper function for batch URL to post ID lookup
 * 
 * @param array $urls Array of attachment URLs to lookup
 * @return array Associative array of URL => post_id (0 if not found)
 */
function alo_batch_url_to_postid($urls) {
    // Validate and sanitize input
    if (!is_array($urls)) {
        return [];
    }
    
    $sanitized_urls = [];
    foreach ($urls as $url) {
        $sanitized_url = esc_url_raw($url);
        if (!empty($sanitized_url) && filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
            $sanitized_urls[] = $sanitized_url;
        }
    }
    
    if (empty($sanitized_urls)) {
        return [];
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    
    if (!$plugin || !method_exists($plugin, 'get_cache_manager')) {
        // Fallback to individual lookups if plugin not available
        $results = [];
        foreach ($sanitized_urls as $url) {
            $results[$url] = attachment_url_to_postid($url);
        }
        return $results;
    }
    
    $cache_manager = $plugin->get_cache_manager();
    return $cache_manager->batch_url_to_postid($sanitized_urls);
}

/**
 * Global helper function to preload URLs into cache
 * 
 * @param array $urls Array of attachment URLs to preload
 * @return int Number of URLs successfully preloaded
 */
function alo_preload_urls($urls) {
    // Validate and sanitize input
    if (!is_array($urls)) {
        return 0;
    }
    
    $sanitized_urls = [];
    foreach ($urls as $url) {
        $sanitized_url = esc_url_raw($url);
        if (!empty($sanitized_url) && filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
            $sanitized_urls[] = $sanitized_url;
        }
    }
    
    if (empty($sanitized_urls)) {
        return 0;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    
    if (!$plugin || !method_exists($plugin, 'get_cache_manager')) {
        return 0;
    }
    
    $cache_manager = $plugin->get_cache_manager();
    return $cache_manager->preload_urls($sanitized_urls);
}

/**
 * Global helper function to get attachment by file path using reverse mapping
 * 
 * @param string $file_path Relative file path (e.g., 'jet-form-builder/xyz.jpg')
 * @return int|false Attachment ID or false if not found
 */
function alo_get_attachment_by_path($file_path) {
    // Validate and sanitize file path
    if (empty($file_path) || !is_string($file_path)) {
        return false;
    }
    
    $sanitized_path = sanitize_text_field($file_path);
    $sanitized_path = ltrim($sanitized_path, '/');
    
    // Validate file path format - should not contain ../ or other dangerous patterns
    if (strpos($sanitized_path, '../') !== false || 
        strpos($sanitized_path, '..\\') !== false ||
        preg_match('/[<>"|*?]/', $sanitized_path)) {
        return false;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    
    if (!$plugin || !method_exists($plugin, 'get_upload_preprocessor')) {
        return false;
    }
    
    $upload_preprocessor = $plugin->get_upload_preprocessor();
    return $upload_preprocessor ? $upload_preprocessor->get_attachment_by_file_path($sanitized_path) : false;
}

/**
 * Global helper function to check if attachment has reverse mapping
 * 
 * @param int $attachment_id Attachment post ID
 * @return bool True if reverse mapping exists
 */
function alo_has_reverse_mapping($attachment_id) {
    // Validate attachment ID
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0) {
        return false;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    
    if (!$plugin || !method_exists($plugin, 'get_upload_preprocessor')) {
        return false;
    }
    
    $upload_preprocessor = $plugin->get_upload_preprocessor();
    return $upload_preprocessor ? $upload_preprocessor->has_reverse_mapping($attachment_id) : false;
}

/**
 * Global helper function to get upload source information
 * 
 * @param int $attachment_id Attachment post ID
 * @return array|false Upload source info or false if not available
 */
function alo_get_upload_source($attachment_id) {
    // Validate attachment ID
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0) {
        return false;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    
    if (!$plugin || !method_exists($plugin, 'get_upload_preprocessor')) {
        return false;
    }
    
    $upload_preprocessor = $plugin->get_upload_preprocessor();
    return $upload_preprocessor ? $upload_preprocessor->get_upload_source($attachment_id) : false;
}

/**
 * Global helper function to test attachment URL lookup with detailed statistics
 * 
 * @param string $url Attachment URL to test
 * @return array Detailed lookup information including method used
 */
function alo_test_lookup($url) {
    // Validate and sanitize URL
    if (empty($url) || !is_string($url)) {
        return ['error' => 'Invalid URL provided'];
    }
    
    $sanitized_url = esc_url_raw($url);
    if (!filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid URL format'];
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return ['error' => 'Cache manager not available'];
    }
    
    return $cache_manager->test_cache($sanitized_url);
}

/**
 * Test custom lookup table performance
 * 
 * @param string $url Attachment URL to test
 * @return array Custom table test results
 */
function alo_test_custom_table($url) {
    // Validate and sanitize URL
    if (empty($url) || !is_string($url)) {
        return ['error' => 'Invalid URL provided'];
    }
    
    $sanitized_url = esc_url_raw($url);
    if (!filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid URL format'];
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $custom_table = $plugin->get_custom_lookup_table();
    
    if (!$custom_table || !$custom_table->table_exists()) {
        return ['error' => 'Custom lookup table not available'];
    }
    
    $upload_dir = wp_upload_dir();
    $base_url = $upload_dir['baseurl'];
    
    // Convert URL to file path
    if (strpos($sanitized_url, $base_url) !== 0) {
        return ['error' => 'URL not from upload directory'];
    }
    
    $file_path = str_replace($base_url . '/', '', $sanitized_url);
    $file_path = ltrim($file_path, '/');
    $file_path = sanitize_text_field($file_path);
    
    // Additional validation for file path
    if (strpos($file_path, '../') !== false || 
        strpos($file_path, '..\\') !== false ||
        preg_match('/[<>"|*?]/', $file_path)) {
        return ['error' => 'Invalid file path'];
    }
    
    $start_time = microtime(true);
    $post_id = $custom_table->get_post_id_by_path($file_path);
    $end_time = microtime(true);
    
    return [
        'url' => esc_url($sanitized_url),
        'file_path' => esc_html($file_path),
        'post_id' => absint($post_id),
        'execution_time_ms' => ($end_time - $start_time) * 1000,
        'table_exists' => true,
        'found' => $post_id > 0
    ];
}

/**
 * Preload attachment URLs for JetEngine listings
 * 
 * @param array $urls Array of attachment URLs to preload
 * @return array Preload results
 */
function alo_jetengine_preload($urls) {
    // Validate and sanitize input
    if (!is_array($urls)) {
        return ['error' => 'Invalid input - URLs must be an array'];
    }
    
    $sanitized_urls = [];
    foreach ($urls as $url) {
        $sanitized_url = esc_url_raw($url);
        if (!empty($sanitized_url) && filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
            $sanitized_urls[] = $sanitized_url;
        }
    }
    
    if (empty($sanitized_urls)) {
        return ['error' => 'No valid URLs provided'];
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $jetengine_preloader = $plugin->get_jetengine_preloader();
    
    if (!$jetengine_preloader || !$jetengine_preloader->is_preloading_enabled()) {
        return ['error' => 'JetEngine preloading not available or disabled'];
    }
    
    // Use the preloader's URL processing
    return $jetengine_preloader->preload_urls($sanitized_urls);
}

/**
 * Get JetEngine preloading statistics
 * 
 * @return array Preloading statistics
 */
function alo_get_jetengine_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $jetengine_preloader = $plugin->get_jetengine_preloader();
    
    if (!$jetengine_preloader) {
        return ['error' => 'JetEngine preloader not available'];
    }
    
    return $jetengine_preloader->get_stats();
}

/**
 * Enable/disable JetEngine preloading
 * 
 * @param bool $enabled Whether to enable preloading
 */
function alo_set_jetengine_preloading($enabled) {
    // Validate boolean input
    $enabled = (bool) $enabled;
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $jetengine_preloader = $plugin->get_jetengine_preloader();
    
    if ($jetengine_preloader) {
        $jetengine_preloader->set_preloading_enabled($enabled);
        update_option('alo_jetengine_preloading', $enabled);
    }
}

/**
 * Get transient management statistics
 * 
 * @return array Transient statistics
 */
function alo_get_transient_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $transient_manager = $plugin->get_transient_manager();
    
    if (!$transient_manager) {
        return ['error' => 'Transient manager not available'];
    }
    
    return $transient_manager->get_stats();
}

/**
 * Force cleanup of expired transients
 * 
 * @return array Cleanup results
 */
function alo_cleanup_transients() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $transient_manager = $plugin->get_transient_manager();
    
    if (!$transient_manager) {
        return ['error' => 'Transient manager not available'];
    }
    
    return $transient_manager->force_cleanup();
}

/**
 * Purge all attachment lookup transients
 * 
 * @return int Number of transients purged
 */
function alo_purge_transients() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $transient_manager = $plugin->get_transient_manager();
    
    if (!$transient_manager) {
        return 0;
    }
    
    return $transient_manager->purge_all_transients();
}

/**
 * Purge transients for a specific attachment
 * 
 * @param int $attachment_id Attachment post ID
 * @return int Number of transients purged
 */
function alo_purge_attachment_transients($attachment_id) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $transient_manager = $plugin->get_transient_manager();
    
    if (!$transient_manager) {
        return 0;
    }
    
    return $transient_manager->purge_attachment_transients($attachment_id);
}

/**
 * Set transient expiration time
 * 
 * @param int $seconds Expiration time in seconds (300-604800)
 * @return int Actual expiration time set
 */
function alo_set_transient_expiration($seconds) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $transient_manager = $plugin->get_transient_manager();
    
    if (!$transient_manager) {
        return 0;
    }
    
    return $transient_manager->set_expiration($seconds);
}

/**
 * Get lazy loading statistics
 * 
 * @return array Lazy loading statistics
 */
function alo_get_lazy_loading_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $lazy_load_manager = $plugin->get_lazy_load_manager();
    
    if (!$lazy_load_manager) {
        return ['error' => 'Lazy load manager not available'];
    }
    
    return $lazy_load_manager->get_stats();
}

/**
 * Enable/disable lazy loading
 * 
 * @param bool $enabled Whether to enable lazy loading
 */
function alo_set_lazy_loading($enabled) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $lazy_load_manager = $plugin->get_lazy_load_manager();
    
    if ($lazy_load_manager) {
        $lazy_load_manager->set_lazy_loading_enabled($enabled);
        update_option('alo_lazy_loading', (bool) $enabled);
    }
}

/**
 * Set lazy loading above-the-fold limit
 * 
 * @param int $limit Number of images to keep eager loading (1-10)
 */
function alo_set_above_fold_limit($limit) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $lazy_load_manager = $plugin->get_lazy_load_manager();
    
    if ($lazy_load_manager) {
        $lazy_load_manager->set_above_fold_limit($limit);
        update_option('alo_lazy_loading_above_fold', max(1, min(10, intval($limit))));
    }
}

/**
 * Set lazy loading strategy
 * 
 * @param string $strategy Loading strategy ('lazy', 'eager', 'auto')
 */
function alo_set_loading_strategy($strategy) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $lazy_load_manager = $plugin->get_lazy_load_manager();
    
    if ($lazy_load_manager) {
        $lazy_load_manager->set_loading_strategy($strategy);
    }
}

/**
 * Get JetEngine query optimization statistics
 * 
 * @return array Query optimization statistics
 */
function alo_get_jetengine_query_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $query_optimizer = $plugin->get_jetengine_query_optimizer();
    
    if (!$query_optimizer) {
        return ['error' => 'JetEngine query optimizer not available'];
    }
    
    return $query_optimizer->get_stats();
}

/**
 * Enable/disable JetEngine query optimization
 * 
 * @param bool $enabled Whether to enable query optimization
 */
function alo_set_jetengine_query_optimization($enabled) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $query_optimizer = $plugin->get_jetengine_query_optimizer();
    
    if ($query_optimizer) {
        $query_optimizer->set_optimization_enabled($enabled);
        update_option('alo_jetengine_query_optimization', (bool) $enabled);
    }
}

/**
 * Clear JetEngine query optimizer field cache
 */
function alo_clear_jetengine_field_cache() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $query_optimizer = $plugin->get_jetengine_query_optimizer();
    
    if ($query_optimizer) {
        $query_optimizer->clear_field_cache();
    }
}

/**
 * Get database query monitoring statistics
 * 
 * @return array Query monitoring statistics
 */
function alo_get_query_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $database_manager = $plugin->get_database_manager();
    
    if (!$database_manager) {
        return ['error' => 'Database manager not available'];
    }
    
    return $database_manager->get_query_stats();
}

/**
 * Enable/disable expensive query logging
 * 
 * @param bool $enabled Whether to enable expensive query logging
 */
function alo_set_expensive_query_logging($enabled) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $database_manager = $plugin->get_database_manager();
    
    if ($database_manager) {
        $database_manager->set_expensive_query_logging($enabled);
    }
}

/**
 * Set slow query threshold
 * 
 * @param float $seconds Threshold in seconds (0.1-5.0)
 */
function alo_set_slow_query_threshold($seconds) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $database_manager = $plugin->get_database_manager();
    
    if ($database_manager) {
        $database_manager->set_slow_query_threshold($seconds);
    }
}

/**
 * Clear query monitoring statistics
 */
function alo_clear_query_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $database_manager = $plugin->get_database_manager();
    
    if ($database_manager) {
        $database_manager->clear_query_stats();
    }
}

/**
 * Get slow query samples for debugging
 * 
 * @return array Recent slow query samples
 */
function alo_get_slow_query_samples() {
    $samples = get_transient('alo_slow_query_samples') ?: [];
    
    return array_map(function($sample) {
        $sample['formatted_time'] = sprintf('%.2fms', $sample['execution_time'] * 1000);
        $sample['formatted_date'] = date('Y-m-d H:i:s', $sample['timestamp']);
        return $sample;
    }, $samples);
}

/**
 * Check if Redis or persistent object cache is available
 * 
 * @return bool True if Redis/persistent cache is detected
 */
function alo_is_redis_available() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return false;
    }
    
    return $cache_manager->is_redis_available();
}

/**
 * Get detailed cache backend information
 * 
 * @return array Cache method details including backend type
 */
function alo_get_cache_method() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return ['error' => 'Cache manager not available'];
    }
    
    return $cache_manager->get_cache_method();
}

/**
 * Test cache functionality including Redis performance
 * 
 * @return array Test results with performance metrics
 */
function alo_test_cache() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return ['error' => 'Cache manager not available'];
    }
    
    return $cache_manager->test_cache_functionality();
}

/**
 * Get request-scoped static cache statistics
 * 
 * @return array Static cache statistics for current request
 */
function alo_get_static_cache_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return ['error' => 'Cache manager not available'];
    }
    
    return $cache_manager->get_static_cache_stats();
}

/**
 * Enable/disable custom lookup table
 * 
 * @param bool $enabled Whether to enable the custom lookup table
 */
function alo_set_custom_lookup_table($enabled) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $custom_table = $plugin->get_custom_lookup_table();
    
    if ($custom_table) {
        if ($enabled) {
            $custom_table->enable();
        } else {
            $custom_table->disable();
        }
        update_option('alo_custom_lookup_table', (bool) $enabled);
    }
}

/**
 * Check if custom lookup table is enabled and ready
 * 
 * @return bool True if custom table is available
 */
function alo_is_custom_table_available() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $custom_table = $plugin->get_custom_lookup_table();
    
    if (!$custom_table) {
        return false;
    }
    
    return $custom_table->is_enabled() && $custom_table->table_exists();
}

/**
 * Get custom lookup table statistics
 * 
 * @return array Custom table statistics
 */
function alo_get_custom_table_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $custom_table = $plugin->get_custom_lookup_table();
    
    if (!$custom_table) {
        return ['error' => 'Custom lookup table not available'];
    }
    
    return $custom_table->get_stats();
}

/**
 * Test custom lookup table performance
 * 
 * @param string $file_path File path to test lookup
 * @return array Test results
 */
function alo_test_custom_table_lookup($file_path) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $custom_table = $plugin->get_custom_lookup_table();
    
    if (!$custom_table) {
        return ['error' => 'Custom lookup table not available'];
    }
    
    return $custom_table->test_lookup($file_path);
}

/**
 * Rebuild custom lookup table from scratch
 * 
 * @return int Number of mappings created
 */
function alo_rebuild_custom_table() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $custom_table = $plugin->get_custom_lookup_table();
    
    if (!$custom_table) {
        return 0;
    }
    
    return $custom_table->rebuild_table();
}

/**
 * Enable/disable attachment debug logging
 * 
 * @param bool $enabled Whether to enable debug logging
 */
function alo_set_attachment_debug_logging($enabled) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if ($cache_manager) {
        $debug_logger = $cache_manager->get_debug_logger();
        if ($debug_logger) {
            if ($enabled) {
                $debug_logger->enable();
            } else {
                $debug_logger->disable();
            }
        }
    }
    
    update_option('alo_attachment_debug_logs', (bool) $enabled);
}

/**
 * Set debug log format
 * 
 * @param string $format Log format ('json' or 'plain')
 */
function alo_set_debug_log_format($format) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if ($cache_manager) {
        $debug_logger = $cache_manager->get_debug_logger();
        if ($debug_logger) {
            $debug_logger->set_format($format);
        }
    }
    
    update_option('alo_debug_log_format', $format);
}

/**
 * Get debug logging statistics
 * 
 * @return array Debug logging statistics
 */
function alo_get_debug_logging_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return ['error' => 'Cache manager not available'];
    }
    
    $debug_logger = $cache_manager->get_debug_logger();
    if (!$debug_logger) {
        return ['error' => 'Debug logger not available'];
    }
    
    return $debug_logger->get_stats();
}

/**
 * Clear all debug log files
 * 
 * @return int Number of files deleted
 */
function alo_clear_debug_logs() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return 0;
    }
    
    $debug_logger = $cache_manager->get_debug_logger();
    if (!$debug_logger) {
        return 0;
    }
    
    return $debug_logger->clear_logs();
}

/**
 * Check if JetEngine is active and installed
 * 
 * @return bool True if JetEngine is active
 */
function alo_is_jetengine_active() {
    // Check for JetEngine class existence
    if (class_exists('Jet_Engine')) {
        return true;
    }
    
    // Check for JetEngine constant
    if (defined('JET_ENGINE_VERSION')) {
        return true;
    }
    
    // Check if JetEngine plugin is active
    if (function_exists('is_plugin_active')) {
        return is_plugin_active('jet-engine/jet-engine.php');
    }
    
    // Fallback: check for JetEngine functions
    if (function_exists('jet_engine')) {
        return true;
    }
    
    return false;
}

/**
 * Check if attachment lookup optimizations are active
 * 
 * @return bool True if any optimization features are enabled
 */
function alo_are_optimizations_active() {
    // Check if global override is enabled (main optimization)
    $global_override = get_option('alo_global_override', false);
    if ($global_override) {
        return true;
    }
    
    // Check if any major optimization features are enabled
    $jetengine_preloading = get_option('alo_jetengine_preloading', true);
    $jetengine_query_optimization = get_option('alo_jetengine_query_optimization', true);
    $lazy_loading = get_option('alo_lazy_loading', true);
    $custom_lookup_table = get_option('alo_custom_lookup_table', false);
    
    // Consider plugin "active" if any optimization is enabled
    return $jetengine_preloading || $jetengine_query_optimization || $lazy_loading || $custom_lookup_table;
}

/**
 * Get JetEngine compatibility status
 * 
 * @return array Status information about JetEngine compatibility
 */
function alo_get_jetengine_compatibility_status() {
    $jetengine_active = alo_is_jetengine_active();
    $optimizations_active = alo_are_optimizations_active();
    
    return [
        'jetengine_active' => $jetengine_active,
        'optimizations_active' => $optimizations_active,
        'compatible' => !$jetengine_active || $optimizations_active,
        'should_show_notice' => $jetengine_active && !$optimizations_active,
        'jetengine_version' => defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : null,
        'plugin_version' => ALO_VERSION
    ];
}

/**
 * Dismiss JetEngine compatibility notice for current user
 * 
 * @return bool True if successfully dismissed
 */
function alo_dismiss_jetengine_notice() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    return update_user_meta(get_current_user_id(), 'alo_jetengine_notice_dismissed', true);
}

/**
 * Reset JetEngine compatibility notice (show it again)
 * 
 * @return bool True if successfully reset
 */
function alo_reset_jetengine_notice() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    return delete_user_meta(get_current_user_id(), 'alo_jetengine_notice_dismissed');
}

/**
 * Get live attachment lookup statistics
 * 
 * @return array Live statistics with real-time counters
 */
function alo_get_live_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return ['error' => 'Cache manager not available'];
    }
    
    return $cache_manager->get_live_stats();
}

/**
 * Reset live attachment lookup statistics
 * 
 * @return bool True if successfully reset
 */
function alo_reset_live_stats() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return false;
    }
    
    return $cache_manager->reset_live_stats();
}

/**
 * Enable/disable live statistics tracking
 * 
 * @param bool $enabled Whether to enable live statistics tracking
 * @return bool True if successfully updated
 */
function alo_set_live_stats_enabled($enabled) {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return false;
    }
    
    $cache_manager->set_live_stats_enabled($enabled);
    return true;
}

/**
 * Check if live statistics tracking is enabled
 * 
 * @return bool True if live statistics are enabled
 */
function alo_is_live_stats_enabled() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return false;
    }
    
    return $cache_manager->is_live_stats_enabled();
}

/**
 * Get automated log cleanup statistics
 * 
 * @return array Cleanup statistics
 */
function alo_get_cleanup_stats() {
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return ['error' => 'Cache manager not available'];
    }
    
    $debug_logger = $cache_manager->get_debug_logger();
    if (!$debug_logger) {
        return ['error' => 'Debug logger not available'];
    }
    
    return $debug_logger->get_cleanup_stats();
}

/**
 * Force run automated log cleanup (bypasses throttling)
 * 
 * @return bool True if cleanup was forced successfully
 */
function alo_force_log_cleanup() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return false;
    }
    
    $debug_logger = $cache_manager->get_debug_logger();
    if (!$debug_logger) {
        return false;
    }
    
    return $debug_logger->force_cleanup();
}

/**
 * Clear all archived compressed log files
 * 
 * @return int Number of archived files deleted
 */
function alo_clear_archived_logs() {
    if (!current_user_can('manage_options')) {
        return 0;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    $cache_manager = $plugin->get_cache_manager();
    
    if (!$cache_manager) {
        return 0;
    }
    
    $debug_logger = $cache_manager->get_debug_logger();
    if (!$debug_logger) {
        return 0;
    }
    
    return $debug_logger->clear_archived_logs();
}

/**
 * Check if automated log cleanup is ready to run
 * 
 * @return bool True if cleanup can run now
 */
function alo_is_cleanup_ready() {
    $last_cleanup = get_transient('alo_log_cleanup_last_run');
    return $last_cleanup === false;
}

/**
 * Get time until next automated cleanup
 * 
 * @return int Seconds until next cleanup (0 if ready now)
 */
function alo_seconds_until_next_cleanup() {
    $last_cleanup = get_transient('alo_log_cleanup_last_run');
    if ($last_cleanup === false) {
        return 0;
    }
    
    $cleanup_interval = 6 * HOUR_IN_SECONDS; // 6 hours
    $next_cleanup = $last_cleanup + $cleanup_interval;
    $current_time = time();
    
    return max(0, $next_cleanup - $current_time);
}

/**
 * Clear all stats caches for debugging
 * 
 * @return bool True if caches were cleared successfully
 */
function alo_clear_all_stats_caches() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
    
    if (!$plugin) {
        return false;
    }
    
    // Clear shared cache first (this clears common duplicate queries)
    $shared_cache = $plugin->get_shared_stats_cache();
    if ($shared_cache) {
        $shared_cache->clear_all_caches();
    }
    
    // Clear cache manager caches
    $cache_manager = $plugin->get_cache_manager();
    if ($cache_manager) {
        $cache_manager->clear_all_cache();
    }
    
    // Clear database manager stats cache
    $database_manager = $plugin->get_database_manager();
    if ($database_manager) {
        $database_manager->clear_stats_cache();
    }
    
    // Clear upload preprocessor stats cache
    $upload_preprocessor = $plugin->get_upload_preprocessor();
    if ($upload_preprocessor) {
        $upload_preprocessor->clear_stats_cache();
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('ALO: All stats caches cleared manually (including shared cache)');
    }
    
    return true;
} 