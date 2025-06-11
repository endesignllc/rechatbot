<?php
/**
 * Uninstall script for Chicago Loft Search plugin.
 *
 * This script is executed when the plugin is deleted from the WordPress admin.
 * It ensures all plugin data, options, tables, and files are removed.
 *
 * @package Chicago_Loft_Search
 */

// Exit if accessed directly or not during uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// --- Single Site Uninstall ---

// 1. Delete Plugin Options
delete_option('chicago_loft_search_options');
delete_option('chicago_loft_search_db_version');

// 2. Delete Custom Database Tables
$table_listings = $wpdb->prefix . 'chicago_loft_listings';
$table_usage = $wpdb->prefix . 'chicago_loft_search_usage';
$table_logs = $wpdb->prefix . 'chicago_loft_search_logs';

$wpdb->query("DROP TABLE IF EXISTS {$table_listings}");
$wpdb->query("DROP TABLE IF EXISTS {$table_usage}");
$wpdb->query("DROP TABLE IF EXISTS {$table_logs}");

// 3. Delete Transients
// General pattern for plugin-specific transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_chicago_loft_search\_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_chicago_loft_search\_%'");
// Specific known transients (if any)
delete_transient('chicago_loft_search_api_check'); // Example

// 4. Clear Scheduled Cron Events
wp_clear_scheduled_hook('chicago_loft_search_daily_reset');
wp_clear_scheduled_hook('chicago_loft_search_monthly_reset');
// Additionally, look for any other plugin-specific cron events if their hook names are known.
// WordPress also cleans up its 'cron' option array, but direct clearance ensures.

// 5. Delete User Meta (if any was stored by the plugin)
// Example: $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'chicago_loft_search_%'");
// Based on current plugin, no specific user meta seems to be stored with this prefix.
// If user-specific settings were stored under a meta_key like 'chicago_loft_search_user_prefs',
// that would be deleted here.

// 6. Remove Uploaded Files/Directories
// This should be done carefully. Only remove directories known to be created by this plugin.
$upload_dir_info = wp_upload_dir();
$csv_documents_dir = $upload_dir_info['basedir'] . '/csv-documents';

if (is_dir($csv_documents_dir)) {
    // Ensure the directory is empty before attempting to remove it, or remove recursively.
    // WordPress doesn't have a built-in recursive directory delete function that's safe for uninstall scripts.
    // Manual recursive delete:
    function chicago_loft_search_recursive_delete($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . DIRECTORY_SEPARATOR . $object)) {
                        chicago_loft_search_recursive_delete($dir . DIRECTORY_SEPARATOR . $object);
                    } else {
                        unlink($dir . DIRECTORY_SEPARATOR . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
    // Check if the directory name is specific enough to avoid accidental deletion of other data.
    // For '/csv-documents/', it's reasonably specific if created by this plugin.
    if (strpos(realpath($csv_documents_dir), realpath($upload_dir_info['basedir'])) === 0) { // Basic safety check
         chicago_loft_search_recursive_delete($csv_documents_dir);
    }
}


// --- Multisite Uninstall ---
if (is_multisite()) {
    // Delete Network-wide options (if any)
    delete_site_option('chicago_loft_search_options'); // If it was ever a network option
    delete_site_option('chicago_loft_search_db_version'); // If it was ever a network option

    // Get all blogs in the network
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);

        // 1. Delete Plugin Options for each site
        delete_option('chicago_loft_search_options');
        delete_option('chicago_loft_search_db_version');

        // 2. Delete Transients for each site
        $site_options_table = $wpdb->prefix . "options"; // Options table for the current site
        $wpdb->query("DELETE FROM {$site_options_table} WHERE option_name LIKE '\_transient\_chicago_loft_search\_%'");
        $wpdb->query("DELETE FROM {$site_options_table} WHERE option_name LIKE '\_transient\_timeout\_chicago_loft_search\_%'");
        delete_transient('chicago_loft_search_api_check');

        // 3. Clear Scheduled Cron Events for each site (if they were site-specific)
        // Note: Cron events are usually global, but plugin might have scheduled site-specific ones.
        // Global ones are cleared above.

        // 4. Delete User Meta (if stored per-site, though usually global)
        // $site_usermeta_table = $wpdb->prefix . "usermeta";
        // $wpdb->query("DELETE FROM {$site_usermeta_table} WHERE meta_key LIKE 'chicago_loft_search_%'");

        // Note: Custom tables ($table_listings, $table_usage, $table_logs) are assumed to be global
        // (using $wpdb->prefix) and are dropped once above. If they were per-site (e.g., $wpdb->prefix . $blog_id . '_tablename'),
        // they would need to be dropped inside this loop. The current plugin structure uses global tables.

        restore_current_blog();
    }

    // Clean up sitemeta table for any network-wide settings if the plugin used them.
    // Example: $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'chicago_loft_search_%'");
}

// Flush rewrite rules one last time (though deactivation usually handles this)
flush_rewrite_rules();

?>
