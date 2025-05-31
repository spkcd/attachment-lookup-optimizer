<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Transient Manager Class
 * 
 * Handles monitoring, cleanup, and purging of transients used for 
 * attachment URL lookups when object cache is not available
 */
class TransientManager {
    
    /**
     * Transient prefix for attachment lookups
     */
    const TRANSIENT_PREFIX = 'alo_url_';
    
    /**
     * Registry transient for tracking all ALO transients
     */
    const REGISTRY_TRANSIENT = 'alo_transient_registry';
    
    /**
     * Default transient expiration (1 day)
     */
    const DEFAULT_EXPIRATION = 86400;
    
    /**
     * Maximum registry size before cleanup
     */
    const MAX_REGISTRY_SIZE = 1000;
    
    /**
     * Cleanup statistics
     */
    private $cleanup_stats = [
        'transients_created' => 0,
        'transients_deleted' => 0,
        'attachments_purged' => 0,
        'registry_cleanups' => 0,
        'last_cleanup' => 0
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks for transient management
     */
    private function init_hooks() {
        // Hook into attachment lifecycle events
        add_action('delete_attachment', [$this, 'purge_attachment_transients'], 10, 1);
        add_action('add_attachment', [$this, 'register_attachment_transients'], 10, 1);
        add_action('edit_attachment', [$this, 'purge_attachment_transients'], 10, 1);
        
        // Hook into meta updates that affect attachments
        add_action('updated_post_meta', [$this, 'handle_meta_update'], 10, 4);
        add_action('added_post_meta', [$this, 'handle_meta_update'], 10, 4);
        add_action('deleted_post_meta', [$this, 'handle_meta_update'], 10, 4);
        
        // Daily cleanup of expired transients
        add_action('wp_scheduled_delete', [$this, 'cleanup_expired_transients']);
        
        // Periodic registry cleanup
        add_action('alo_transient_registry_cleanup', [$this, 'cleanup_transient_registry']);
        
        // Schedule cleanup if not scheduled
        if (!wp_next_scheduled('alo_transient_registry_cleanup')) {
            wp_schedule_event(time(), 'daily', 'alo_transient_registry_cleanup');
        }
        
        // Track transient operations
        add_filter('pre_set_transient_' . self::TRANSIENT_PREFIX, [$this, 'track_transient_creation'], 10, 3);
    }
    
    /**
     * Set attachment lookup transient with registry tracking
     */
    public function set_attachment_transient($cache_key, $value, $expiration = null) {
        if ($expiration === null) {
            $expiration = apply_filters('alo_transient_expiration', self::DEFAULT_EXPIRATION);
        }
        
        $transient_key = $this->get_transient_key($cache_key);
        
        // Set the transient
        $result = set_transient($transient_key, $value, $expiration);
        
        if ($result) {
            // Add to registry for tracking
            $this->add_to_registry($transient_key, $cache_key, time() + $expiration);
            $this->cleanup_stats['transients_created']++;
        }
        
        return $result;
    }
    
    /**
     * Get attachment lookup transient
     */
    public function get_attachment_transient($cache_key) {
        $transient_key = $this->get_transient_key($cache_key);
        return get_transient($transient_key);
    }
    
    /**
     * Delete attachment lookup transient
     */
    public function delete_attachment_transient($cache_key) {
        $transient_key = $this->get_transient_key($cache_key);
        $result = delete_transient($transient_key);
        
        if ($result) {
            $this->remove_from_registry($transient_key);
            $this->cleanup_stats['transients_deleted']++;
        }
        
        return $result;
    }
    
    /**
     * Get transient key from cache key
     */
    private function get_transient_key($cache_key) {
        $transient_key = self::TRANSIENT_PREFIX . $cache_key;
        
        // WordPress transients have a 172 character limit
        if (strlen($transient_key) > 172) {
            $transient_key = self::TRANSIENT_PREFIX . 'h_' . md5($cache_key);
        }
        
        return $transient_key;
    }
    
    /**
     * Add transient to registry for tracking
     */
    private function add_to_registry($transient_key, $cache_key, $expires) {
        $registry = get_transient(self::REGISTRY_TRANSIENT) ?: [];
        
        $registry[$transient_key] = [
            'cache_key' => $cache_key,
            'expires' => $expires,
            'created' => time()
        ];
        
        // Cleanup registry if it gets too large
        if (count($registry) > self::MAX_REGISTRY_SIZE) {
            $this->cleanup_transient_registry($registry);
        } else {
            set_transient(self::REGISTRY_TRANSIENT, $registry, self::DEFAULT_EXPIRATION * 2);
        }
    }
    
    /**
     * Remove transient from registry
     */
    private function remove_from_registry($transient_key) {
        $registry = get_transient(self::REGISTRY_TRANSIENT) ?: [];
        
        if (isset($registry[$transient_key])) {
            unset($registry[$transient_key]);
            set_transient(self::REGISTRY_TRANSIENT, $registry, self::DEFAULT_EXPIRATION * 2);
        }
    }
    
    /**
     * Purge all transients related to a specific attachment
     */
    public function purge_attachment_transients($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return;
        }
        
        $start_time = microtime(true);
        $purged_count = 0;
        
        // Get attachment URLs to purge
        $urls_to_purge = $this->get_attachment_urls($attachment_id);
        
        foreach ($urls_to_purge as $url) {
            $cache_key = 'url_' . md5($url);
            if ($this->delete_attachment_transient($cache_key)) {
                $purged_count++;
            }
        }
        
        // Also purge by attachment ID if stored that way
        $id_cache_key = 'attachment_' . $attachment_id;
        if ($this->delete_attachment_transient($id_cache_key)) {
            $purged_count++;
        }
        
        $end_time = microtime(true);
        $this->cleanup_stats['attachments_purged']++;
        
        if (defined('WP_DEBUG') && WP_DEBUG && $purged_count > 0) {
            error_log(sprintf(
                'ALO: Purged %d transients for attachment %d in %.2fms',
                $purged_count,
                $attachment_id,
                ($end_time - $start_time) * 1000
            ));
        }
        
        return $purged_count;
    }
    
    /**
     * Get all URLs for an attachment (main + sized versions)
     */
    private function get_attachment_urls($attachment_id) {
        $urls = [];
        
        // Main attachment URL
        $main_url = wp_get_attachment_url($attachment_id);
        if ($main_url) {
            $urls[] = $main_url;
        }
        
        // Sized versions
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata && !empty($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_url = dirname($main_url);
            
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $sized_url = $base_url . '/' . $size_data['file'];
                    $urls[] = $sized_url;
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Register transients when attachment is added
     */
    public function register_attachment_transients($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return;
        }
        
        // Add attachment to a registry for batch cleanup
        $attachment_registry = get_transient('alo_attachment_registry') ?: [];
        $attachment_registry[$attachment_id] = time();
        
        // Keep only recent attachments in registry
        $cutoff = time() - (self::DEFAULT_EXPIRATION * 7); // 7 days
        $attachment_registry = array_filter($attachment_registry, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });
        
        set_transient('alo_attachment_registry', $attachment_registry, self::DEFAULT_EXPIRATION * 7);
    }
    
    /**
     * Handle meta updates that affect attachment URLs
     */
    public function handle_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        // Only process attachment-related meta
        if (get_post_type($post_id) !== 'attachment') {
            return;
        }
        
        // Meta keys that affect attachment URLs
        $url_affecting_meta = [
            '_wp_attached_file',
            '_wp_attachment_metadata',
            '_wp_attachment_image_alt'
        ];
        
        if (in_array($meta_key, $url_affecting_meta)) {
            $this->purge_attachment_transients($post_id);
        }
    }
    
    /**
     * Cleanup expired transients
     */
    public function cleanup_expired_transients() {
        global $wpdb;
        
        $start_time = microtime(true);
        $deleted_count = 0;
        
        // Get all ALO transients
        $transients = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE %s",
            '_transient_' . self::TRANSIENT_PREFIX . '%',
            '_transient_timeout_%'
        ));
        
        foreach ($transients as $transient) {
            $transient_name = str_replace('_transient_', '', $transient->option_name);
            
            // Check if transient has expired
            $timeout_option = '_transient_timeout_' . $transient_name;
            $timeout = get_option($timeout_option);
            
            if ($timeout && $timeout < time()) {
                // Transient has expired, delete it
                delete_transient($transient_name);
                $deleted_count++;
            }
        }
        
        $end_time = microtime(true);
        $this->cleanup_stats['transients_deleted'] += $deleted_count;
        $this->cleanup_stats['last_cleanup'] = time();
        
        if (defined('WP_DEBUG') && WP_DEBUG && $deleted_count > 0) {
            error_log(sprintf(
                'ALO: Cleaned up %d expired transients in %.2fms',
                $deleted_count,
                ($end_time - $start_time) * 1000
            ));
        }
        
        return $deleted_count;
    }
    
    /**
     * Cleanup transient registry
     */
    public function cleanup_transient_registry($registry = null) {
        if ($registry === null) {
            $registry = get_transient(self::REGISTRY_TRANSIENT) ?: [];
        }
        
        $current_time = time();
        $cleaned_registry = [];
        $removed_count = 0;
        
        foreach ($registry as $transient_key => $data) {
            // Remove expired entries
            if (isset($data['expires']) && $data['expires'] < $current_time) {
                delete_transient(str_replace('_transient_', '', $transient_key));
                $removed_count++;
            } else {
                $cleaned_registry[$transient_key] = $data;
            }
        }
        
        // If registry is still too large, remove oldest entries
        if (count($cleaned_registry) > self::MAX_REGISTRY_SIZE) {
            uasort($cleaned_registry, function($a, $b) {
                return ($a['created'] ?? 0) - ($b['created'] ?? 0);
            });
            
            $cleaned_registry = array_slice($cleaned_registry, -self::MAX_REGISTRY_SIZE, null, true);
        }
        
        set_transient(self::REGISTRY_TRANSIENT, $cleaned_registry, self::DEFAULT_EXPIRATION * 2);
        
        $this->cleanup_stats['registry_cleanups']++;
        
        if (defined('WP_DEBUG') && WP_DEBUG && $removed_count > 0) {
            error_log(sprintf(
                'ALO: Registry cleanup removed %d expired entries',
                $removed_count
            ));
        }
        
        return $removed_count;
    }
    
    /**
     * Track transient creation for statistics
     */
    public function track_transient_creation($value, $transient, $expiration) {
        // Only track our transients
        if (strpos($transient, self::TRANSIENT_PREFIX) === 0) {
            $this->cleanup_stats['transients_created']++;
        }
        
        return $value; // Don't interfere with the actual transient setting
    }
    
    /**
     * Purge all ALO transients
     */
    public function purge_all_transients() {
        global $wpdb;
        
        $start_time = microtime(true);
        
        // Delete all ALO transients and their timeouts
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
            '_transient_' . self::TRANSIENT_PREFIX . '%',
            '_transient_timeout_' . self::TRANSIENT_PREFIX . '%'
        ));
        
        // Clear the registry
        delete_transient(self::REGISTRY_TRANSIENT);
        delete_transient('alo_attachment_registry');
        
        $end_time = microtime(true);
        $this->cleanup_stats['transients_deleted'] += $deleted;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'ALO: Purged all transients (%d rows) in %.2fms',
                $deleted,
                ($end_time - $start_time) * 1000
            ));
        }
        
        return $deleted;
    }
    
    /**
     * Get transient statistics
     */
    public function get_stats() {
        global $wpdb;
        
        // Count current transients
        $current_transients = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE %s",
            '_transient_' . self::TRANSIENT_PREFIX . '%',
            '_transient_timeout_%'
        ));
        
        // Get registry info
        $registry = get_transient(self::REGISTRY_TRANSIENT) ?: [];
        $registry_size = count($registry);
        
        // Calculate storage size
        $storage_size = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} 
             WHERE option_name LIKE %s",
            '_transient_' . self::TRANSIENT_PREFIX . '%'
        ));
        
        return array_merge($this->cleanup_stats, [
            'current_transients' => (int) $current_transients,
            'registry_size' => $registry_size,
            'storage_size_bytes' => (int) $storage_size,
            'storage_size_mb' => round((int) $storage_size / 1024 / 1024, 2),
            'cleanup_enabled' => true,
            'expiration_seconds' => apply_filters('alo_transient_expiration', self::DEFAULT_EXPIRATION)
        ]);
    }
    
    /**
     * Force cleanup of all expired transients immediately
     */
    public function force_cleanup() {
        $expired_cleaned = $this->cleanup_expired_transients();
        $registry_cleaned = $this->cleanup_transient_registry();
        
        return [
            'expired_cleaned' => $expired_cleaned,
            'registry_cleaned' => $registry_cleaned,
            'total_cleaned' => $expired_cleaned + $registry_cleaned
        ];
    }
    
    /**
     * Get transient expiration time
     */
    public function get_expiration() {
        return apply_filters('alo_transient_expiration', self::DEFAULT_EXPIRATION);
    }
    
    /**
     * Set transient expiration time
     */
    public function set_expiration($seconds) {
        $seconds = max(300, min(86400 * 7, $seconds)); // Between 5 minutes and 7 days
        add_filter('alo_transient_expiration', function() use ($seconds) {
            return $seconds;
        });
        
        return $seconds;
    }
} 