# Scheduled Cleanup Implementation

## Overview
A comprehensive scheduled cleanup system has been implemented to prevent database bloat from logs, cache, and watchlist data. The system uses WordPress Cron to run daily maintenance tasks automatically.

## Features Implemented

### 1. WP Cron Integration
- **Schedule**: Daily cleanup at 24-hour intervals
- **Hook**: `alo_daily_cleanup` 
- **Management**: Automatically scheduled on plugin activation, cleared on deactivation
- **Fallback**: Manual cleanup available through admin interface

### 2. Cleanup Tasks

#### Transient Cache Cleanup (48h retention)
- **Target**: All plugin-specific transients older than 48 hours
- **Patterns Cleaned**:
  - `_transient_alo_url_%` - Cached URL lookup results
  - `_transient_timeout_alo_url_%` - Transient timeout values
  - `_transient_alo_file_failure_%` - File failure tracking
  - `_transient_alo_slow_query_samples` - Slow query samples
  - `_transient_alo_recent_fallback_errors` - Recent fallback errors
- **Logic**: Checks timeout values to identify expired transients
- **Safety**: Only removes plugin-specific transients, not global ones

#### Log File Trimming (100 entries maximum)
- **Target**: Debug log files and flat log files
- **Retention**: Last 100 entries per file
- **Files Processed**:
  - Debug logger files in `/wp-content/uploads/attachment-lookup-logs/`
  - Flat fallback log at `/wp-content/debug-attachment-fallback.log`
- **Headers**: Adds cleanup timestamp headers to trimmed files
- **Safety**: File locking prevents corruption during trimming

#### Watchlist Cleanup (7-day retention)
- **Target**: Fallback watchlist entries older than 7 days
- **Logic**: Checks `last_failure` timestamp for each entry
- **Cleanup**: Removes expired entries, deletes transient if empty
- **Preservation**: Keeps active problematic files for ongoing monitoring

#### Additional Cleanup Tasks
- **Debug Logs**: Removes debug log files older than 30 days
- **Slow Query Samples**: Cleans samples older than 24 hours
- **Error Tracking**: Maintains recent error history with automatic expiration

### 3. Activation/Deactivation Hooks

#### Plugin Activation
```php
// Schedule daily cleanup cron job
if (!wp_next_scheduled('alo_daily_cleanup')) {
    wp_schedule_event(time(), 'daily', 'alo_daily_cleanup');
}
```

#### Plugin Deactivation
```php
// Clear scheduled cleanup cron job
wp_clear_scheduled_hook('alo_daily_cleanup');
```

### 4. Statistics and Monitoring

#### Cleanup Statistics Tracking
- **Storage**: `alo_last_cleanup_stats` transient (1 week retention)
- **Metrics Tracked**:
  - Transients deleted count
  - Log files trimmed count
  - Watchlist entries removed count
  - Execution time in milliseconds
  - Error messages and timestamps
  - Start time for audit trail

#### Admin Interface Integration
- **Stats Display**: Cleanup status widget in statistics page
- **Manual Trigger**: "Run Cleanup Now" button for immediate execution
- **AJAX Support**: Non-blocking manual cleanup with progress feedback
- **Security**: Nonce verification and capability checks

### 5. Error Handling and Safety

#### Robust Error Management
- **Exception Handling**: Try-catch blocks around all cleanup operations
- **Error Logging**: PHP error_log integration for debugging
- **Graceful Degradation**: Continues with other tasks if one fails
- **Statistics**: Error tracking in cleanup statistics

#### Data Safety Measures
- **File Locking**: Prevents corruption during file operations
- **Atomic Operations**: Transient operations are atomic
- **Validation**: Path validation and existence checks
- **Backup Headers**: Adds informational headers to trimmed files

### 6. Performance Considerations

#### Efficient Database Operations
- **Prepared Statements**: All database queries use `$wpdb->prepare()`
- **Batch Processing**: Processes multiple transients efficiently
- **Index Usage**: Leverages database indexes for option lookups
- **Memory Management**: Processes files line-by-line for large logs

#### Minimal Server Impact
- **Background Execution**: Runs during low-traffic periods via WP Cron
- **Execution Time Tracking**: Monitors performance impact
- **Resource Limits**: Respects PHP memory and execution time limits
- **Non-blocking**: Manual cleanup uses AJAX for better UX

## Technical Implementation

### File Structure
```
includes/
├── Plugin.php              # Main cleanup orchestration
├── AdminInterface.php      # Manual cleanup interface
├── DebugLogger.php         # Log directory access
└── CacheManager.php        # Transient management
```

### Key Methods

#### Plugin.php
- `schedule_cleanup()` - Ensures cron job is scheduled
- `run_daily_cleanup()` - Main cleanup orchestrator
- `cleanup_old_transients()` - Transient cleanup logic
- `trim_log_files()` - Log file trimming
- `cleanup_old_watchlist_entries()` - Watchlist maintenance
- `force_cleanup()` - Manual cleanup trigger

#### AdminInterface.php
- `ajax_force_cleanup()` - AJAX handler for manual cleanup
- `display_cleanup_status_widget()` - Status display widget

### Cron Hook Registration
```php
// In Plugin::init()
add_action('alo_daily_cleanup', [$this, 'run_daily_cleanup']);

// Scheduling
wp_schedule_event(time(), 'daily', 'alo_daily_cleanup');
```

### AJAX Integration
```javascript
// Manual cleanup trigger
function runManualCleanup() {
    fetch(ajaxurl, {
        method: 'POST',
        body: new URLSearchParams({
            action: 'alo_force_cleanup',
            nonce: nonce_value
        })
    });
}
```

## Benefits

### Database Health
- **Prevents Bloat**: Automatic removal of expired data
- **Optimized Performance**: Keeps option tables lean
- **Storage Efficiency**: Manages log file sizes automatically
- **Query Performance**: Maintains fast transient lookups

### Maintenance Automation
- **Zero Configuration**: Works out-of-the-box after activation
- **Hands-off Operation**: No manual intervention required
- **Audit Trail**: Complete statistics for monitoring
- **Failure Recovery**: Continues operation despite individual task failures

### Administrative Control
- **Manual Override**: Force cleanup when needed
- **Status Visibility**: Real-time cleanup status monitoring
- **Error Reporting**: Clear error messages and resolution paths
- **Performance Monitoring**: Execution time tracking and optimization

### Production Readiness
- **WordPress Standards**: Full WP Cron API compliance
- **Security Hardened**: Proper nonce verification and capability checks
- **Error Resistant**: Robust error handling and logging
- **Scalable**: Efficient processing for large datasets

## Usage

### Automatic Operation
The cleanup system runs automatically every 24 hours without any configuration required. It will:
1. Clean expired transients older than 48 hours
2. Trim log files to last 100 entries
3. Remove watchlist entries older than 7 days
4. Clean old debug logs and samples

### Manual Operation
Administrators can trigger immediate cleanup via:
- **Admin Interface**: "Run Cleanup Now" button in settings
- **Statistics Page**: Cleanup widget with status display
- **Programmatic**: `$plugin->force_cleanup()` method call

### Monitoring
Check cleanup status via:
- **Statistics Page**: Real-time cleanup status widget
- **Transient Data**: `get_transient('alo_last_cleanup_stats')`
- **WordPress Logs**: Standard error_log entries for major events

This implementation provides a robust, automated maintenance solution that prevents database bloat while maintaining optimal plugin performance and providing full administrative visibility and control. 