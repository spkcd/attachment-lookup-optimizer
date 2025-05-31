<?php
/**
 * Uninstall script for Attachment Lookup Optimizer
 * 
 * This file is executed when the plugin is uninstalled (deleted) from WordPress admin.
 * It removes all plugin data including database indexes and options.
 */

// Prevent direct access
defined('ABSPATH') || exit;

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define plugin constants if not already defined
if (!defined('ALO_PLUGIN_DIR')) {
    define('ALO_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

// Validate plugin directory path
if (!is_dir(ALO_PLUGIN_DIR . 'includes/')) {
    exit;
}

// Include the database manager for cleanup
require_once ALO_PLUGIN_DIR . 'includes/DatabaseManager.php';

/**
 * Remove database indexes
 */
function alo_remove_database_indexes() {
    $database_manager = new \AttachmentLookupOptimizer\DatabaseManager();
    $database_manager->drop_postmeta_indexes();
}

/**
 * Remove plugin options
 */
function alo_remove_options() {
    $options_to_remove = [
        'alo_activated',
        'alo_version',
        'alo_db_checked_version',
        'alo_index_created',
        'alo_attached_file_index_created',
        'alo_attached_file_index_checked',
        'alo_cache_ttl',
        'alo_debug_logging',
        'alo_debug_threshold',
        'alo_global_override',
        'alo_cache_last_cleared'
    ];
    
    foreach ($options_to_remove as $option) {
        delete_option($option);
    }
}

/**
 * Remove upload preprocessing meta fields
 */
function alo_remove_upload_meta() {
    global $wpdb;
    
    // Remove our custom meta fields
    $meta_keys_to_remove = [
        '_alo_cached_file_path',
        '_alo_upload_source'
    ];
    
    foreach ($meta_keys_to_remove as $meta_key) {
        $wpdb->delete(
            $wpdb->postmeta,
            ['meta_key' => $meta_key],
            ['%s']
        );
    }
}

/**
 * Clear all plugin cache
 */
function alo_clear_cache() {
    // Clear the specific cache group
    wp_cache_flush_group('alo_attachment_urls');
    
    // Also do a general cache flush to be safe
    wp_cache_flush();
}

/**
 * Clean up transients and scheduled events
 */
function alo_cleanup_transients_and_events() {
    global $wpdb;
    
    // Remove scheduled events
    wp_clear_scheduled_hook('alo_transient_registry_cleanup');
    
    // Clean up all ALO transients
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE %s 
         OR option_name LIKE %s
         OR option_name LIKE %s
         OR option_name LIKE %s",
        '_transient_alo_url_%',
        '_transient_timeout_alo_url_%',
        '_transient_alo_transient_registry',
        '_transient_alo_attachment_registry'
    ));
}

/**
 * Main uninstall process
 */
function alo_uninstall() {
    // Clear cache first
    alo_clear_cache();
    
    // Remove database indexes
    alo_remove_database_indexes();
    
    // Remove plugin options
    alo_remove_options();
    
    // Remove upload preprocessing meta fields
    alo_remove_upload_meta();
    
    // Clean up database indexes
    $database_manager = new AttachmentLookupOptimizer\DatabaseManager();
    $database_manager->drop_indexes();

    // Clean up custom lookup table
    $custom_lookup_table = new AttachmentLookupOptimizer\CustomLookupTable();
    $custom_lookup_table->drop_table();
    
    // Clean up transients and scheduled events
    alo_cleanup_transients_and_events();
    
    // Log the uninstall
    error_log('ALO: Plugin successfully uninstalled and cleaned up');
}

// Run the uninstall process
alo_uninstall(); 