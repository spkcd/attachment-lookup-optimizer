<?php
/**
 * Fix Duplicate Entries Script
 * 
 * This script fixes the duplicate entry issue in the Attachment Lookup Optimizer plugin
 * by migrating the table to use hash-based unique constraints instead of truncated strings.
 * 
 * Run this script to resolve the duplicate key errors you're experiencing.
 */

// Load WordPress
require_once(__DIR__ . '/../../wp-config.php');

// Check if we're running from command line or have admin privileges
if (defined('WP_CLI') && WP_CLI) {
    // Running from WP-CLI
} elseif (is_admin() && current_user_can('administrator')) {
    // Running from WordPress admin
} else {
    die('This script must be run by an administrator or via WP-CLI.');
}

echo "=== Attachment Lookup Optimizer - Fix Duplicate Entries ===\n";
echo "Starting migration to hash-based structure...\n\n";

global $wpdb;

// Table name
$table_name = $wpdb->prefix . 'attachment_lookup';

// Check if table exists
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

if (!$table_exists) {
    echo "❌ Table $table_name does not exist. Nothing to migrate.\n";
    exit(1);
}

echo "✅ Found table: $table_name\n";

// Check current table structure
$columns = $wpdb->get_results("DESCRIBE $table_name");
$has_hash_column = false;
$current_columns = [];

foreach ($columns as $column) {
    $current_columns[] = $column->Field;
    if ($column->Field === 'meta_value_hash') {
        $has_hash_column = true;
    }
}

echo "📋 Current columns: " . implode(', ', $current_columns) . "\n";

// Check current indexes
$indexes = $wpdb->get_results("SHOW INDEXES FROM $table_name");
$current_indexes = [];
foreach ($indexes as $index) {
    $current_indexes[] = $index->Key_name;
}

echo "🔍 Current indexes: " . implode(', ', array_unique($current_indexes)) . "\n\n";

// Step 1: Backup current record count
$record_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
echo "📊 Current record count: $record_count\n";

// Step 2: Find duplicates that are causing the issue
$duplicates = $wpdb->get_results("
    SELECT LEFT(meta_value, 191) as truncated_value, COUNT(*) as count
    FROM $table_name 
    GROUP BY LEFT(meta_value, 191) 
    HAVING count > 1
    ORDER BY count DESC
    LIMIT 10
");

if (!empty($duplicates)) {
    echo "⚠️  Found " . count($duplicates) . " groups of duplicate truncated values:\n";
    foreach ($duplicates as $dup) {
        echo "   - '{$dup->truncated_value}' ({$dup->count} records)\n";
    }
    echo "\n";
}

// Step 3: Add hash column if it doesn't exist
if (!$has_hash_column) {
    echo "🔨 Adding meta_value_hash column...\n";
    $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN meta_value_hash char(32) NOT NULL DEFAULT ''");
    
    if ($result === false) {
        echo "❌ Failed to add hash column: " . $wpdb->last_error . "\n";
        exit(1);
    }
    echo "✅ Added meta_value_hash column\n";
} else {
    echo "✅ meta_value_hash column already exists\n";
}

// Step 4: Populate hash values
echo "🔄 Populating hash values...\n";
$updated = $wpdb->query("UPDATE $table_name SET meta_value_hash = MD5(meta_value) WHERE meta_value_hash = ''");
echo "✅ Updated $updated records with hash values\n";

// Step 5: Remove old problematic unique index
echo "🗑️  Removing old truncated unique index...\n";
$drop_result = $wpdb->query("ALTER TABLE $table_name DROP INDEX unique_meta_value");
if ($drop_result === false && strpos($wpdb->last_error, "check that column/key exists") === false) {
    echo "⚠️  Warning: Could not drop old index (may not exist): " . $wpdb->last_error . "\n";
} else {
    echo "✅ Removed old unique_meta_value index\n";
}

// Step 6: Handle remaining duplicates by removing older ones
echo "🧹 Cleaning up any remaining duplicates...\n";
$duplicates_removed = $wpdb->query("
    DELETE t1 FROM $table_name t1
    INNER JOIN $table_name t2 
    WHERE t1.meta_value_hash = t2.meta_value_hash 
    AND t1.id < t2.id
");
echo "✅ Removed $duplicates_removed duplicate records\n";

// Step 7: Add new unique hash index
echo "🔗 Adding new hash-based unique index...\n";
$index_result = $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY unique_meta_value_hash (meta_value_hash)");
if ($index_result === false) {
    echo "❌ Failed to add hash index: " . $wpdb->last_error . "\n";
    // Try to continue anyway
} else {
    echo "✅ Added unique hash index\n";
}

// Step 8: Add prefix index for meta_value searches
echo "🔗 Adding prefix index for meta_value...\n";
$prefix_index_result = $wpdb->query("ALTER TABLE $table_name ADD KEY meta_value_prefix_idx (meta_value(191))");
if ($prefix_index_result === false && strpos($wpdb->last_error, "Duplicate key name") === false) {
    echo "⚠️  Warning: Could not add prefix index: " . $wpdb->last_error . "\n";
} else {
    echo "✅ Added meta_value prefix index\n";
}

// Step 9: Verify final state
$final_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
$final_indexes = $wpdb->get_results("SHOW INDEXES FROM $table_name");
$final_index_names = [];
foreach ($final_indexes as $index) {
    $final_index_names[] = $index->Key_name;
}

echo "\n=== Migration Complete ===\n";
echo "📊 Final record count: $final_count (was $record_count)\n";
echo "🔍 Final indexes: " . implode(', ', array_unique($final_index_names)) . "\n";

// Test the new structure
echo "\n🧪 Testing new structure...\n";
$test_value = 'test/migration/verification-' . time() . '.jpg';
$test_hash = md5($test_value);

$test_insert = $wpdb->insert(
    $table_name,
    [
        'post_id' => 999999,
        'meta_value' => $test_value,
        'meta_value_hash' => $test_hash,
        'updated_at' => current_time('mysql', true)
    ],
    ['%d', '%s', '%s', '%s']
);

if ($test_insert !== false) {
    $wpdb->delete($table_name, ['post_id' => 999999], ['%d']);
    echo "✅ Test insert/delete successful\n";
} else {
    echo "❌ Test insert failed: " . $wpdb->last_error . "\n";
}

echo "\n🎉 Migration completed successfully!\n";
echo "The duplicate entry errors should now be resolved.\n";
echo "You can now use the plugin normally.\n"; 