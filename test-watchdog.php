<?php
/**
 * Test file for Attachment Lookup Optimizer Watchdog functionality
 * 
 * This file tests the enhanced lookup system with watchdog and fallback features.
 * Place this in your WordPress root directory and access via browser.
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    // Find the wp-config.php file
    $wp_config_path = dirname(__FILE__);
    while ($wp_config_path && !file_exists($wp_config_path . '/wp-config.php')) {
        $parent = dirname($wp_config_path);
        if ($parent === $wp_config_path) {
            break;
        }
        $wp_config_path = $parent;
    }
    
    if (file_exists($wp_config_path . '/wp-config.php')) {
        require_once $wp_config_path . '/wp-config.php';
    } else {
        die('WordPress not found');
    }
}

require_once(ABSPATH . 'wp-settings.php');

// Test URLs - mix of valid and invalid
$test_urls = [
    // Valid URLs (should be found)
    home_url('/wp-content/uploads/2023/01/sample-image.jpg'),
    home_url('/wp-content/uploads/2023/02/another-image.png'),
    
    // Invalid URLs (should trigger fallback)
    home_url('/wp-content/uploads/2023/01/nonexistent-file.jpg'),
    home_url('/wp-content/uploads/2023/02/missing-image.png'),
    home_url('/wp-content/uploads/2023/03/fake-file.gif'),
    
    // Malformed URLs (should be handled gracefully)
    'not-a-valid-url',
    '',
    null
];

echo "<h1>Attachment Lookup Optimizer - Watchdog Test</h1>\n";
echo "<p>Testing enhanced lookup system with watchdog and fallback functionality.</p>\n";

// Get the cache manager instance
$alo_plugin = $GLOBALS['attachment_lookup_optimizer'] ?? null;
$cache_manager = $alo_plugin ? $alo_plugin->get_cache_manager() : null;

if (!$cache_manager) {
    echo "<p style='color: red;'>Error: Cache manager not available. Make sure the plugin is active.</p>\n";
    exit;
}

echo "<h2>Test Results</h2>\n";
echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
echo "<tr><th>URL</th><th>Result</th><th>Time (ms)</th><th>Status</th></tr>\n";

foreach ($test_urls as $url) {
    if ($url === null) {
        $url = '(null)';
    }
    
    $start_time = microtime(true);
    
    // Test the lookup
    $result = attachment_url_to_postid($url);
    
    $end_time = microtime(true);
    $query_time = ($end_time - $start_time) * 1000;
    
    $status_color = $result > 0 ? 'green' : 'red';
    $status_text = $result > 0 ? 'Found' : 'Not Found';
    
    // Highlight slow queries
    $time_color = $query_time > 300 ? 'red' : ($query_time > 100 ? 'orange' : 'black');
    
    echo "<tr>\n";
    echo "<td>" . esc_html(substr($url, 0, 60)) . (strlen($url) > 60 ? '...' : '') . "</td>\n";
    echo "<td>" . ($result > 0 ? $result : '0') . "</td>\n";
    echo "<td style='color: $time_color;'>" . number_format($query_time, 2) . "</td>\n";
    echo "<td style='color: $status_color;'>$status_text</td>\n";
    echo "</tr>\n";
    
    // Add a small delay to avoid overwhelming the system
    usleep(100000); // 100ms delay
}

echo "</table>\n";

// Display watchdog statistics
echo "<h2>Watchdog Statistics</h2>\n";
$fallback_stats = $cache_manager->get_fallback_stats();

echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
echo "<tr><th>Metric</th><th>Value</th></tr>\n";
echo "<tr><td>Native Fallback Enabled</td><td>" . ($fallback_stats['native_fallback_enabled'] ? 'Yes' : 'No') . "</td></tr>\n";
echo "<tr><td>Slow Query Threshold</td><td>" . $fallback_stats['slow_query_threshold'] . " ms</td></tr>\n";
echo "<tr><td>Fallbacks (Last Hour)</td><td>" . $fallback_stats['fallbacks_last_hour'] . "</td></tr>\n";
echo "<tr><td>Watchlist Count</td><td>" . $fallback_stats['watchlist_count'] . "</td></tr>\n";
echo "<tr><td>Failure Tracking</td><td>" . ($fallback_stats['failure_tracking_enabled'] ? 'Enabled' : 'Disabled') . "</td></tr>\n";
echo "</table>\n";

// Display watchlist items if any
if (!empty($fallback_stats['watchlist_items'])) {
    echo "<h3>Current Watchlist</h3>\n";
    echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
    echo "<tr><th>URL</th><th>Failures</th><th>Last Failure</th></tr>\n";
    
    foreach ($fallback_stats['watchlist_items'] as $url => $data) {
        echo "<tr>\n";
        echo "<td>" . esc_html(basename($url)) . "</td>\n";
        echo "<td>" . $data['failures'] . "</td>\n";
        echo "<td>" . human_time_diff($data['last_failure']) . " ago</td>\n";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
}

// Display live statistics
echo "<h2>Live Statistics</h2>\n";
$live_stats = $cache_manager->get_live_stats();

echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
echo "<tr><th>Metric</th><th>Value</th></tr>\n";
echo "<tr><td>Total Lookups</td><td>" . number_format($live_stats['total_lookups']) . "</td></tr>\n";
echo "<tr><td>Successful Lookups</td><td>" . number_format($live_stats['successful_lookups']) . "</td></tr>\n";
echo "<tr><td>Success Rate</td><td>" . $live_stats['success_rate'] . "%</td></tr>\n";
echo "<tr><td>Cache Efficiency</td><td>" . $live_stats['cache_efficiency'] . "%</td></tr>\n";
echo "<tr><td>Static Cache Hits</td><td>" . number_format($live_stats['static_cache_hits']) . "</td></tr>\n";
echo "<tr><td>Cache Hits</td><td>" . number_format($live_stats['cache_hits']) . "</td></tr>\n";
echo "<tr><td>Table Lookup Hits</td><td>" . number_format($live_stats['table_lookup_hits']) . "</td></tr>\n";
echo "<tr><td>Fast Lookup Hits</td><td>" . number_format($live_stats['fast_lookup_hits']) . "</td></tr>\n";
echo "<tr><td>SQL Lookup Hits</td><td>" . number_format($live_stats['sql_lookup_hits']) . "</td></tr>\n";
echo "<tr><td>Legacy Fallback Hits</td><td>" . number_format($live_stats['legacy_fallback_hits']) . "</td></tr>\n";
echo "<tr><td>Native Fallback Success</td><td>" . number_format($live_stats['native_fallback_success_hits'] ?? 0) . "</td></tr>\n";
echo "<tr><td>Native Fallback Failed</td><td>" . number_format($live_stats['native_fallback_failed_hits'] ?? 0) . "</td></tr>\n";
echo "</table>\n";

// Check if flat log file exists
$log_file = WP_CONTENT_DIR . '/debug-attachment-fallback.log';
if (file_exists($log_file)) {
    echo "<h2>Flat Log File</h2>\n";
    echo "<p>Log file exists: <code>$log_file</code></p>\n";
    echo "<p>Size: " . size_format(filesize($log_file)) . "</p>\n";
    
    // Show last few lines
    $lines = file($log_file);
    if ($lines && count($lines) > 0) {
        echo "<h3>Recent Log Entries (Last 5)</h3>\n";
        echo "<pre style='background: #f0f0f0; padding: 10px; overflow-x: auto;'>\n";
        $recent_lines = array_slice($lines, -5);
        foreach ($recent_lines as $line) {
            echo esc_html($line);
        }
        echo "</pre>\n";
    }
} else {
    echo "<h2>Flat Log File</h2>\n";
    echo "<p>No flat log file found yet. Trigger some fallbacks to create it.</p>\n";
}

echo "<p><strong>Test completed!</strong> Check your WordPress admin for watchdog alerts and statistics.</p>\n";
echo "<p><a href='" . admin_url('tools.php?page=attachment-lookup-optimizer-stats') . "'>View Plugin Statistics</a></p>\n";
?> 