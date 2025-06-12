<?php
/**
 * Plugin Name: Chicago Loft Search
 * Plugin URI: https://example.com/chicago-loft-search
 * Description: A secure WordPress plugin that allows users to search Chicago loft listings using ChatGPT-powered natural language queries.
 * Version: 1.0.8
 * Author: Factory AI
 * Author URI: https://factory.ai
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: chicago-loft-search
 * Domain Path: /languages
 *
 * @package Chicago_Loft_Search
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('CHICAGO_LOFT_SEARCH_VERSION', '1.0.8');
define('CHICAGO_LOFT_SEARCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHICAGO_LOFT_SEARCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHICAGO_LOFT_SEARCH_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Define the optimized CSV query function directly in the plugin instead of including an external file

/**
 * Optimized function to query CSV files directly.
 * Reads all CSVs from the designated folder, extracts relevant rows based on keywords,
 * and returns a structured array of this data, optimized for token usage.
 *
 * @param string $query The user's search query.
 * @param int $max_chars_for_csv_data Approximate character limit for the CSV data payload.
 * @return array An array of structured data from CSVs, or an empty array if no relevant data found.
 */
function chicago_loft_search_query_csv_documents_optimized($query, $max_chars_for_csv_data = 15000) {
    $upload_dir = wp_upload_dir();
    $csv_dir_path = $upload_dir['basedir'] . '/csv-documents';

    if (!file_exists($csv_dir_path) || !is_dir($csv_dir_path)) {
        return []; // No CSV directory, no results
    }

    $files = glob($csv_dir_path . '/*.csv');
    if (empty($files)) {
        return []; // No CSV files found
    }

    $keywords = preg_split('/\\s+/', strtolower(trim($query)));
    $keywords = array_filter($keywords, function($kw) {
        return strlen(trim($kw)) > 2; // Filter out very short or empty keywords
    });

    if (empty($keywords)) {
        return []; // No meaningful keywords to search
    }

    $relevant_data_payload = [];
    $current_total_chars_count = 0;

    foreach ($files as $file_path) {
        if ($current_total_chars_count >= $max_chars_for_csv_data) {
            break; // Stop if we've already hit the limit
        }

        if (($handle = fopen($file_path, 'r')) !== false) {
            $header = fgetcsv($handle);
            if (!$header || empty(array_filter($header, 'strlen'))) { 
                fclose($handle);
                continue; // Skip empty or invalid CSV
            }
            $header = array_map('trim', $header);

            $current_file_matched_rows = [];
            $current_file_rows_chars_count = 0;

            // Estimate characters for this file's metadata 
            $file_metadata_for_estimation = ['source_file' => basename($file_path), 'columns' => $header, 'rows' => []];
            $file_metadata_chars_estimate = strlen(json_encode($file_metadata_for_estimation)) - strlen(json_encode([])); 

            while (($row_values = fgetcsv($handle)) !== false) {
                if (count($header) !== count($row_values)) {
                    continue; // Skip malformed rows
                }
                if (empty(array_filter($row_values, 'strlen'))) {
                    continue;
                }

                $row_assoc = array_combine($header, $row_values);
                $row_text_lower = strtolower(implode(' ', $row_assoc));

                $matches_this_row = false;
                foreach ($keywords as $keyword) {
                    if (stripos($row_text_lower, $keyword) !== false) {
                        $matches_this_row = true;
                        break;
                    }
                }

                if ($matches_this_row) {
                    $row_json_for_estimation = json_encode($row_assoc);
                    $row_chars_estimate = strlen($row_json_for_estimation);

                    // Calculate potential new total character count if this row is added
                    $potential_new_total_chars = $current_total_chars_count + $row_chars_estimate;
                    if (empty($current_file_matched_rows)) { // If this is the first matched row for this file
                        $potential_new_total_chars += $file_metadata_chars_estimate;
                    }

                    if ($potential_new_total_chars <= $max_chars_for_csv_data) {
                        $current_file_matched_rows[] = $row_assoc;
                        $current_file_rows_chars_count += $row_chars_estimate;
                    } else {
                        break; // Not enough space for this row
                    }
                }
            }
            
            fclose($handle);

            if (!empty($current_file_matched_rows)) {
                // We have matched rows for this file. Check again if the file block fits.
                $this_file_block_chars = $file_metadata_chars_estimate + $current_file_rows_chars_count;
                if (($current_total_chars_count + $this_file_block_chars) <= $max_chars_for_csv_data) {
                    $relevant_data_payload[] = [
                        'source_file' => basename($file_path),
                        'columns' => $header,
                        'rows' => $current_file_matched_rows
                    ];
                    $current_total_chars_count += $this_file_block_chars;
                } else if ($current_total_chars_count > 0) {
                    break; // Stop if we already have some data
                }
            }
        }
    }

    return $relevant_data_payload;
}


/**
 * The code that runs during plugin activation.
 */
function activate_chicago_loft_search() {
    // Create/update necessary database tables
    chicago_loft_search_create_tables();
    
    // Perform database upgrades if needed
    chicago_loft_search_check_and_upgrade_db();
    
    // Set default options if not already set
    $default_options = array(
        'openai_api_key' => '',
        'daily_query_limit' => 50,
        'monthly_query_limit' => 1000,
        'allowed_user_roles' => array('administrator', 'editor', 'subscriber'),
        'model' => 'gpt-4o',
        'system_prompt' => 'You are a helpful assistant specializing in Chicago loft and high rise properties. Provide accurate information based on the data available. Keep responses concise and relevant to real estate inquiries only. Please keep users on this site and never mention competitor real estate sites in the result messaging.',
        'last_sync_date' => '',
        'example_questions' => "Show me lofts in West Loop under $500,000\nWhat are the largest lofts in River North?\nFind 2 bedroom lofts in South Loop with exposed brick",
    );
    
    if (false === get_option('chicago_loft_search_options')) {
        add_option('chicago_loft_search_options', $default_options);
    }
    
    // Store the current plugin version as the database version
    update_option('chicago_loft_search_db_version', CHICAGO_LOFT_SEARCH_VERSION);
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_chicago_loft_search() {
    // Clear any scheduled events
    wp_clear_scheduled_hook('chicago_loft_search_daily_reset');
    wp_clear_scheduled_hook('chicago_loft_search_monthly_reset');
    
    // Clear any transients related to this plugin
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_chicago_loft_search_%'");
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_chicago_loft_search_%'");
    delete_transient('chicago_loft_search_api_check'); 
    remove_menu_page('chicago-loft-search-dashboard');
    
    flush_rewrite_rules();
}

/**
 * The code that runs during plugin uninstallation.
 */
function uninstall_chicago_loft_search() {
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        exit;
    }
}

register_activation_hook(__FILE__, 'activate_chicago_loft_search');
register_deactivation_hook(__FILE__, 'deactivate_chicago_loft_search');
register_uninstall_hook(__FILE__, 'uninstall_chicago_loft_search'); 

/**
 * Create necessary database tables (defines the latest schema)
 */
function chicago_loft_search_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        mls_id varchar(255) NOT NULL, /* Increased length for potentially longer generated IDs */
        address varchar(255) DEFAULT NULL, 
        neighborhood varchar(100) DEFAULT NULL,
        price decimal(12,2) DEFAULT NULL,
        bedrooms int(11) DEFAULT NULL,
        bathrooms decimal(3,1) DEFAULT NULL,
        square_feet int(11) DEFAULT NULL,
        year_built int(4) DEFAULT NULL,
        features text DEFAULT NULL,
        description longtext DEFAULT NULL,
        image_urls longtext DEFAULT NULL, 
        status varchar(50) DEFAULT 'active',
        raw_data longtext DEFAULT NULL, /* To store the full JSON of the original CSV row */
        date_added datetime NOT NULL,
        date_updated datetime NOT NULL,
        listing_type varchar(50) DEFAULT 'imported_csv_item', /* Changed default and increased length */
        
        building_name varchar(255) DEFAULT NULL,
        units int(11) DEFAULT NULL,
        floors int(11) DEFAULT NULL,
        hoa_fee varchar(50) DEFAULT NULL,
        pet_policy text DEFAULT NULL,
        amenities text DEFAULT NULL,
        
        agent_name varchar(255) DEFAULT NULL,
        email varchar(100) DEFAULT NULL,
        phone varchar(50) DEFAULT NULL,
        bio text DEFAULT NULL, 
        areas_of_expertise text DEFAULT NULL,
        specialty varchar(100) DEFAULT NULL,
        license varchar(50) DEFAULT NULL,
        
        PRIMARY KEY  (id),
        UNIQUE KEY mls_id (mls_id), 
        KEY neighborhood (neighborhood),
        KEY price (price),
        KEY bedrooms (bedrooms),
        KEY bathrooms (bathrooms),
        KEY square_feet (square_feet),
        KEY year_built (year_built),
        KEY status (status),
        KEY listing_type (listing_type)
    ) $charset_collate;";
    
    $table_usage = $wpdb->prefix . 'chicago_loft_search_usage';
    $sql_usage = "CREATE TABLE $table_usage (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        ip_address varchar(45) NOT NULL,
        daily_count int(11) NOT NULL DEFAULT 0,
        monthly_count int(11) NOT NULL DEFAULT 0,
        last_reset_daily datetime NOT NULL,
        last_reset_monthly datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_ip (user_id, ip_address)
    ) $charset_collate;";
    
    $table_logs = $wpdb->prefix . 'chicago_loft_search_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        ip_address varchar(45) NOT NULL,
        query text NOT NULL,
        response longtext NOT NULL,
        tokens_used int(11) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    dbDelta($sql_usage);
    dbDelta($sql_logs);
}

/**
 * Check and upgrade database schema if necessary.
 */
function chicago_loft_search_check_and_upgrade_db() {
    $current_db_version = get_option('chicago_loft_search_db_version', '0.0.0');

    if (version_compare($current_db_version, '1.0.2', '<')) { 
        global $wpdb;
        $table_name = $wpdb->prefix . 'chicago_loft_listings';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) == $table_name) {
            $columns_to_modify = array(
                'neighborhood' => 'varchar(100) DEFAULT NULL',
                'price'        => 'decimal(12,2) DEFAULT NULL',
                'bedrooms'     => 'int(11) DEFAULT NULL',
                'bathrooms'    => 'decimal(3,1) DEFAULT NULL',
                'square_feet'  => 'int(11) DEFAULT NULL',
                'year_built'   => 'int(4) DEFAULT NULL',
                'features'     => 'text DEFAULT NULL',
                'description'  => 'longtext DEFAULT NULL',
                'image_urls'   => 'longtext DEFAULT NULL',
                'status'       => "varchar(50) DEFAULT 'active'"
            );
            foreach ($columns_to_modify as $column_name => $column_definition) {
                $col_exists_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", $column_name));
                if (!empty($col_exists_check)) {
                    $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `$column_name` $column_definition");
                }
            }
            $raw_data_column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", 'raw_data'));
            if (empty($raw_data_column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN `raw_data` longtext DEFAULT NULL");
            }
        }
    }

    if (version_compare($current_db_version, '1.0.3', '<')) { 
        global $wpdb;
        $table_name = $wpdb->prefix . 'chicago_loft_listings';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) == $table_name) {
            $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", 'listing_type'));
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN `listing_type` varchar(20) DEFAULT 'loft'");
            }
            
            $building_columns = array(
                'building_name' => 'varchar(255) DEFAULT NULL',
                'units' => 'int(11) DEFAULT NULL',
                'floors' => 'int(11) DEFAULT NULL',
                'hoa_fee' => 'varchar(50) DEFAULT NULL',
                'pet_policy' => 'text DEFAULT NULL',
                'amenities' => 'text DEFAULT NULL'
            );
            
            $agent_columns = array(
                'agent_name' => 'varchar(255) DEFAULT NULL',
                'email' => 'varchar(100) DEFAULT NULL',
                'phone' => 'varchar(50) DEFAULT NULL',
                'bio' => 'text DEFAULT NULL',
                'areas_of_expertise' => 'text DEFAULT NULL',
                'specialty' => 'varchar(100) DEFAULT NULL',
                'license' => 'varchar(50) DEFAULT NULL'
            );
            
            $all_new_columns = array_merge($building_columns, $agent_columns);
            foreach ($all_new_columns as $column_name => $column_definition) {
                $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", $column_name));
                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE $table_name ADD COLUMN `$column_name` $column_definition");
                }
            }
            
            $index_exists = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM `$table_name` WHERE Key_name = %s", 'listing_type'));
            if (empty($index_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD INDEX listing_type (listing_type)");
            }

            $address_col_details = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", 'address'));
            if ($address_col_details && strtoupper($address_col_details->Null) === 'NO') {
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `address` varchar(255) DEFAULT NULL");
            }
        }
    }
    if (version_compare($current_db_version, '1.0.5', '<')) { // This covers changes up to 1.0.5
        global $wpdb;
        $table_name = $wpdb->prefix . 'chicago_loft_listings';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) == $table_name) {
            // Increase mls_id length
            $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `mls_id` varchar(255) NOT NULL");
            // Change listing_type default and length
            $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `listing_type` varchar(50) DEFAULT 'imported_csv_item'");
        }
    }

     if (version_compare($current_db_version, CHICAGO_LOFT_SEARCH_VERSION, '<')) {
        update_option('chicago_loft_search_db_version', CHICAGO_LOFT_SEARCH_VERSION);
    }
}
add_action('plugins_loaded', 'chicago_loft_search_check_and_upgrade_db');


/**
 * Enqueue scripts and styles for admin
 */
function chicago_loft_search_admin_enqueue_scripts($hook) {
    $plugin_pages = array(
        'toplevel_page_chicago-loft-search-dashboard',
        'loft-search_page_chicago-loft-search-listings',
        'loft-search_page_chicago-loft-search-import',
        'loft-search_page_chicago-loft-search-logs',
        'settings_page_chicago-loft-search',
        'loft-search_page_chicago-loft-search-settings',
        'loft-search_page_chicago-loft-search-csv' 
    );

    if (!in_array($hook, $plugin_pages)) {
        return;
    }
    
    wp_enqueue_style('chicago-loft-search-admin', CHICAGO_LOFT_SEARCH_PLUGIN_URL . 'admin/css/chicago-loft-search-admin.css', array(), CHICAGO_LOFT_SEARCH_VERSION);
    wp_enqueue_script('chicago-loft-search-admin', CHICAGO_LOFT_SEARCH_PLUGIN_URL . 'admin/js/chicago-loft-search-admin.js', array('jquery'), CHICAGO_LOFT_SEARCH_VERSION, true);
    
    wp_localize_script('chicago-loft-search-admin', 'chicago_loft_search_admin', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'verify_nonce' => wp_create_nonce('chicago_loft_search_verify_api_key'),
        'stats_nonce' => wp_create_nonce('chicago_loft_search_get_usage_stats'),
        'export_nonce' => wp_create_nonce('chicago_loft_search_export_settings'),
        'import_nonce' => wp_create_nonce('chicago_loft_search_import_settings'), 
        'csv_import_nonce' => wp_create_nonce('chicago_loft_search_import_nonce'), 
        'sync_nonce' => wp_create_nonce('chicago_loft_search_manual_sync'),
        'delete_listings_nonce' => wp_create_nonce('chicago_loft_search_delete_listings'), 
        'delete_all_listings_nonce' => wp_create_nonce('chicago_loft_search_delete_all_listings'),
        'show_text' => __('Show', 'chicago-loft-search'),
        'hide_text' => __('Hide', 'chicago-loft-search'),
        'verifying' => __('Verifying...', 'chicago-loft-search'),
        'api_key_valid' => __('API key is valid!', 'chicago-loft-search'),
        'enter_api_key' => __('Please enter an API key first', 'chicago-loft-search'),
        'error_verifying' => __('Error verifying API key', 'chicago-loft-search'),
        'select_file' => __('Please select a file to import', 'chicago-loft-search'),
        'import_success' => __('Settings imported successfully! Reloading page...', 'chicago-loft-search'),
        'import_error' => __('Error importing settings', 'chicago-loft-search'),
        'syncing' => __('Synchronizing MLS data...', 'chicago-loft-search'),
        'sync_error' => __('Error synchronizing MLS data', 'chicago-loft-search'),
        'stats_error' => __('Error loading usage data', 'chicago-loft-search'),
        'reset_prompt_confirm' => __('Are you sure you want to reset the system prompt to default?', 'chicago-loft-search'),
        'reset_all_confirm' => __('Are you sure you want to reset ALL settings to default? This cannot be undone!', 'chicago-loft-search'),
        'reset_url' => admin_url('options-general.php?page=chicago-loft-search&reset=true'),
        'default_system_prompt' => __('You are a helpful assistant specializing in Chicago loft and high rise properties. Provide accurate information based on the data available. Keep responses concise and relevant to real estate inquiries only. Please keep users on this site and never mention competitor real estate sites in the result messaging.', 'chicago-loft-search'),
        'confirm_delete_selected' => __('Are you sure you want to delete the selected listings? This action cannot be undone.', 'chicago-loft-search'),
        'confirm_delete_all' => __('Are you sure you want to delete ALL loft listings? This action cannot be undone and will remove all imported data.', 'chicago-loft-search'),
        'deleting_text' => __('Deleting...', 'chicago-loft-search'),
        'delete_all_text' => __('Delete All Listings', 'chicago-loft-search'),
    ));
}
add_action('admin_enqueue_scripts', 'chicago_loft_search_admin_enqueue_scripts');

/**
 * Enqueue scripts and styles for frontend
 */
function chicago_loft_search_enqueue_scripts() {
    wp_enqueue_style('chicago-loft-search', CHICAGO_LOFT_SEARCH_PLUGIN_URL . 'public/css/chicago-loft-search-public.css', array(), CHICAGO_LOFT_SEARCH_VERSION);
    wp_enqueue_script('chicago-loft-search', CHICAGO_LOFT_SEARCH_PLUGIN_URL . 'public/js/chicago-loft-search-public.js', array('jquery'), CHICAGO_LOFT_SEARCH_VERSION, true);
    
    wp_localize_script('chicago-loft-search', 'chicago_loft_search', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('chicago_loft_search_public_nonce'),
        'loading_text' => __('Searching for lofts...', 'chicago-loft-search'),
        'error_text' => __('Error processing your request. Please try again.', 'chicago-loft-search'),
        'limit_reached_text' => __('You have reached your search limit for today. Please try again tomorrow.', 'chicago-loft-search')
    ));
}
add_action('wp_enqueue_scripts', 'chicago_loft_search_enqueue_scripts');

/**
 * Add admin menu
 */
function chicago_loft_search_admin_menu() {
    add_options_page(
        __('Chicago Loft Search Settings', 'chicago-loft-search'),
        __('Chicago Loft Search', 'chicago-loft-search'),
        'manage_options',
        'chicago-loft-search', 
        'chicago_loft_search_admin_page'
    );
    
    add_menu_page(
        __('Chicago Loft Search', 'chicago-loft-search'),
        __('Loft Search', 'chicago-loft-search'),
        'manage_options',
        'chicago-loft-search-dashboard', 
        'chicago_loft_search_dashboard_page',
        'dashicons-search',
        30
    );
    
    add_submenu_page(
        'chicago-loft-search-dashboard',
        __('Dashboard', 'chicago-loft-search'),
        __('Dashboard', 'chicago-loft-search'),
        'manage_options',
        'chicago-loft-search-dashboard', 
        'chicago_loft_search_dashboard_page'
    );
    
    add_submenu_page(
        'chicago-loft-search-dashboard',
        __('Listings Data', 'chicago-loft-search'), 
        __('Listings Data', 'chicago-loft-search'), 
        'manage_options',
        'chicago-loft-search-listings',
        'chicago_loft_search_listings_page'
    );
    
    add_submenu_page(
        'chicago-loft-search-dashboard',
        __('Import Data', 'chicago-loft-search'), 
        __('Import Data', 'chicago-loft-search'), 
        'manage_options',
        'chicago-loft-search-import',
        'chicago_loft_search_import_page'
    );

    add_submenu_page(
        'chicago-loft-search-dashboard',
        __('CSV Documents', 'chicago-loft-search'),
        __('CSV Documents', 'chicago-loft-search'),
        'manage_options',
        'chicago-loft-search-csv',
        'chicago_loft_search_csv_manager_page'
    );
    
    add_submenu_page(
        'chicago-loft-search-dashboard',
        __('Search Logs', 'chicago-loft-search'),
        __('Search Logs', 'chicago-loft-search'),
        'manage_options',
        'chicago-loft-search-logs',
        'chicago_loft_search_logs_page'
    );
    
    add_submenu_page(
        'chicago-loft-search-dashboard', 
        __('Settings', 'chicago-loft-search'),    
        __('Settings', 'chicago-loft-search'),    
        'manage_options',                         
        'chicago-loft-search-settings',           
        'chicago_loft_search_settings_page_redirect' 
    );
}
add_action('admin_menu', 'chicago_loft_search_admin_menu');

/**
 * Admin settings page (main one, under "Settings" menu)
 */
function chicago_loft_search_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_POST['chicago_loft_search_save_settings'])) {
        check_admin_referer('chicago_loft_search_settings', 'chicago_loft_search_nonce');
        
        $options = get_option('chicago_loft_search_options');
        
        if (isset($_POST['openai_api_key'])) {
            $api_key = sanitize_text_field($_POST['openai_api_key']);
            if (!empty($api_key) || ($api_key === '' && isset($options['openai_api_key']))) {
                 $options['openai_api_key'] = $api_key;
            }
        }
        
        if (isset($_POST['daily_query_limit'])) {
            $options['daily_query_limit'] = absint($_POST['daily_query_limit']);
        }
        
        if (isset($_POST['monthly_query_limit'])) {
            $options['monthly_query_limit'] = absint($_POST['monthly_query_limit']);
        }
        
        if (isset($_POST['allowed_user_roles']) && is_array($_POST['allowed_user_roles'])) {
            $options['allowed_user_roles'] = array_map('sanitize_text_field', $_POST['allowed_user_roles']);
        } else {
            $options['allowed_user_roles'] = array(); 
        }
        
        if (isset($_POST['model'])) {
            $options['model'] = sanitize_text_field($_POST['model']);
        }
        
        if (isset($_POST['system_prompt'])) {
            $options['system_prompt'] = sanitize_textarea_field($_POST['system_prompt']);
        }

        if (isset($_POST['example_questions'])) {
            $example_questions_raw = sanitize_textarea_field($_POST['example_questions']);
            $options['example_questions'] = $example_questions_raw; 
        } else {
            $options['example_questions'] = ''; 
        }
        
        update_option('chicago_loft_search_options', $options);
        
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully!', 'chicago-loft-search') . '</p></div>';
    }
    
    $options = get_option('chicago_loft_search_options');
    
    include(CHICAGO_LOFT_SEARCH_PLUGIN_DIR . 'admin/partials/settings-page.php');
}

/**
 * Redirect for settings page under Loft Search menu
 */
function chicago_loft_search_settings_page_redirect() {
    wp_redirect(admin_url('options-general.php?page=chicago-loft-search'));
    exit;
}


/**
 * Dashboard page
 */
function chicago_loft_search_dashboard_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    echo '<div class="wrap"><h1>' . __('Chicago Loft Search Dashboard', 'chicago-loft-search') . '</h1><p>' . __('Welcome to the dashboard. Analytics and quick stats will be shown here.', 'chicago-loft-search') . '</p></div>';
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Listings Data Table Class.
 */
class Chicago_Loft_Listings_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => __('Listing Data', 'chicago-loft-search'), 
            'plural'   => __('Listings Data', 'chicago-loft-search'), 
            'ajax'     => false
        ));
    }

    public function get_columns() {
        return array(
            'cb'           => '<input type="checkbox" />', 
            'mls_id'       => __('ID / MLS ID', 'chicago-loft-search'),
            'listing_type' => __('Type', 'chicago-loft-search'),
            'primary_identifier' => __('Name / Address', 'chicago-loft-search'), 
            'neighborhood' => __('Neighborhood', 'chicago-loft-search'),
            'status'       => __('Status', 'chicago-loft-search'),
            'date_updated' => __('Last Updated', 'chicago-loft-search'),
        );
    }

    public function get_sortable_columns() {
        return array(
            'mls_id'       => array('mls_id', false),
            'listing_type' => array('listing_type', false),
            'primary_identifier' => array('address', false), 
            'neighborhood' => array('neighborhood', false),
            'status'       => array('status', false),
            'date_updated' => array('date_updated', true) 
        );
    }

    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="listing_id[]" value="%s" />', $item['id']
        );
    }
    
    protected function column_primary_identifier($item) {
        $identifier = '';
        // Since all data is in raw_data, try to get a common identifier like Address or Name
        $raw_data = json_decode($item['raw_data'], true);
        if (is_array($raw_data)) {
            if (!empty($raw_data['Address'])) {
                $identifier = $raw_data['Address'];
            } elseif (!empty($raw_data['Building Name'])) {
                $identifier = $raw_data['Building Name'];
            } elseif (!empty($raw_data['Agent Name'])) {
                 $identifier = $raw_data['Agent Name'];
            } elseif (!empty($item['address'])) { // Fallback to old column if raw_data is missing it
                $identifier = $item['address'];
            }
        }
        if (empty($identifier) && !empty($item['address'])) $identifier = $item['address']; // Final fallback
        
        return esc_html($identifier ?: __('N/A', 'chicago-loft-search'));
    }
    
    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'mls_id':
            case 'listing_type':
            case 'status':
                return esc_html($item[$column_name] ?: __('N/A', 'chicago-loft-search'));
            case 'neighborhood': // Try to get from raw_data if specific column is empty
                 $raw_data = json_decode($item['raw_data'], true);
                 $neighborhood = $item['neighborhood'];
                 if (is_array($raw_data) && !empty($raw_data['Neighborhood'])) {
                     $neighborhood = $raw_data['Neighborhood'];
                 } elseif (is_array($raw_data) && !empty($raw_data['City and Neighborhood'])) {
                     $neighborhood = _chicago_loft_search_extract_neighborhood_from_string($raw_data['City and Neighborhood']);
                 }
                 return esc_html($neighborhood ?: __('N/A', 'chicago-loft-search'));
            case 'date_updated':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name]));
            default:
                // For other columns, try to pull from raw_data if the direct column is empty
                $value = $item[$column_name];
                if (empty($value)) {
                    $raw_data = json_decode($item['raw_data'], true);
                    // Convert column_name (e.g. building_name) to CSV-like header (e.g. Building Name)
                    $header_key = ucwords(str_replace('_', ' ', $column_name));
                    if (is_array($raw_data) && isset($raw_data[$header_key])) {
                        $value = $raw_data[$header_key];
                    }
                }
                return isset($value) ? esc_html($value) : print_r($item, true); 
        }
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'chicago_loft_listings';

        $per_page = 20;
        $current_page = $this->get_pagenum();
        
        $where_clauses = array();
        $query_params = array();

        $filter_listing_type = isset($_REQUEST['listing_type_filter']) ? sanitize_text_field($_REQUEST['listing_type_filter']) : '';
        if (!empty($filter_listing_type)) {
            $where_clauses[] = "listing_type = %s";
            $query_params[] = $filter_listing_type;
        }
        
        if (!empty($_REQUEST['s'])) {
            $search_term = '%' . $wpdb->esc_like(sanitize_text_field($_REQUEST['s'])) . '%';
            // Search in mls_id, address, neighborhood, and raw_data JSON
            $search_fields = "(mls_id LIKE %s OR address LIKE %s OR neighborhood LIKE %s OR building_name LIKE %s OR agent_name LIKE %s OR description LIKE %s OR raw_data LIKE %s)";
            $where_clauses[] = $wpdb->prepare($search_fields, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term);
        }
        
        $sql_where = "";
        if (!empty($where_clauses)) {
            $sql_where = " WHERE " . implode(" AND ", $where_clauses);
        }
        
        $total_items_query = "SELECT COUNT(id) FROM $table_name" . $sql_where;
        $total_items = $wpdb->get_var(empty($query_params) ? $total_items_query : $wpdb->prepare($total_items_query, $query_params));


        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page
        ));

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        $this->process_bulk_action();

        $orderby = (!empty($_REQUEST['orderby']) && array_key_exists($_REQUEST['orderby'], $this->get_sortable_columns())) ? $_REQUEST['orderby'] : 'date_updated';
        if ($orderby === 'primary_identifier') $orderby = 'address'; 
        $order = (!empty($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), array('ASC', 'DESC'))) ? $_REQUEST['order'] : 'DESC';
        
        // Select all columns including raw_data
        $query = "SELECT * FROM $table_name" . $sql_where;
        $query .= " ORDER BY $orderby $order";
        $query .= " LIMIT $per_page";
        $query .= " OFFSET " . (($current_page - 1) * $per_page);
        
        $this->items = $wpdb->get_results(empty($query_params) ? $query : $wpdb->prepare($query, $query_params), ARRAY_A);
    }
    
    protected function extra_tablenav($which) {
        if ($which == "top") {
            $current_filter = isset($_REQUEST['listing_type_filter']) ? $_REQUEST['listing_type_filter'] : '';
            ?>
            <div class="alignleft actions">
                <select name="listing_type_filter" id="listing_type_filter">
                    <option value=""><?php _e('All Types', 'chicago-loft-search'); ?></option>
                    <option value="imported_csv_item" <?php selected($current_filter, 'imported_csv_item'); ?>><?php _e('Imported CSV Item', 'chicago-loft-search'); ?></option>
                    <option value="loft" <?php selected($current_filter, 'loft'); ?>><?php _e('Loft (Legacy)', 'chicago-loft-search'); ?></option>
                    <option value="building" <?php selected($current_filter, 'building'); ?>><?php _e('Building (Legacy)', 'chicago-loft-search'); ?></option>
                    <option value="agent" <?php selected($current_filter, 'agent'); ?>><?php _e('Agent (Legacy)', 'chicago-loft-search'); ?></option>
                </select>
                <?php submit_button(__('Filter'), 'button', 'filter_action', false, array('id' => 'post-query-submit')); ?>
            </div>
            <?php
        }
    }
    
    protected function get_bulk_actions() {
        return array(
            'bulk-delete' => __('Delete Selected', 'chicago-loft-search')
        );
    }

    public function process_bulk_action() {
        if ('bulk-delete' === $this->current_action()) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                wp_die(__('Security check failed!', 'chicago-loft-search'));
            }
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to delete listings.', 'chicago-loft-search'));
            }

            $listing_ids = isset($_POST['listing_id']) ? array_map('absint', $_POST['listing_id']) : array();

            if (!empty($listing_ids)) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'chicago_loft_listings';
                $ids_placeholder = implode(',', array_fill(0, count($listing_ids), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $deleted_count = $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE id IN ($ids_placeholder)", $listing_ids));
                
                if ($deleted_count > 0) {
                    add_settings_error(
                        'chicago_loft_search_listings_messages',
                        'listings_deleted',
                        sprintf(_n('%d item deleted.', '%d items deleted.', $deleted_count, 'chicago-loft-search'), $deleted_count),
                        'updated'
                    );
                } else {
                     add_settings_error(
                        'chicago_loft_search_listings_messages',
                        'listings_delete_error',
                        __('Error deleting items. Please try again.', 'chicago-loft-search'),
                        'error'
                    );
                }
            }
        }
    }
}


/**
 * Listings page
 */
function chicago_loft_search_listings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $total_listings = $wpdb->get_var("SELECT COUNT(id) FROM $table_name");
    $options = get_option('chicago_loft_search_options');
    $last_sync_date = isset($options['last_sync_date']) ? $options['last_sync_date'] : '';

    echo '<div class="wrap">';
    echo '<h1>' . __('Manage Listings Data', 'chicago-loft-search') . '</h1>'; 
    
    settings_errors('chicago_loft_search_listings_messages');

    echo '<div id="loft-listings-summary" style="margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border: 1px solid #e3e3e3;">';
    echo '<h3>' . __('Summary', 'chicago-loft-search') . '</h3>';
    echo '<p><strong>' . __('Total Items Imported:', 'chicago-loft-search') . '</strong> ' . esc_html($total_listings) . '</p>'; 
    if (!empty($last_sync_date)) {
        echo '<p><strong>' . __('Last Data Sync/Import:', 'chicago-loft-search') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync_date)) . '</p>'; 
    } else {
        echo '<p><strong>' . __('Last Data Sync/Import:', 'chicago-loft-search') . '</strong> ' . __('Never synced or no data imported yet.', 'chicago-loft-search') . '</p>';
    }
     echo '<p><button type="button" id="delete-all-listings-button" class="button button-danger">' . __('Delete All Imported Data', 'chicago-loft-search') . '</button></p>'; 
    echo '</div>';

    $listings_table = new Chicago_Loft_Listings_Table();
    $listings_table->prepare_items();
    
    echo '<form method="post">'; 
    wp_nonce_field( 'bulk-' . $listings_table->_args['plural'] );
    $listings_table->search_box(__('Search Data', 'chicago-loft-search'), 'chicago-loft-search'); 
    $listings_table->display();
    echo '</form>';
    
    echo '</div>';
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#delete-all-listings-button').on('click', function() {
                if (confirm(chicago_loft_search_admin.confirm_delete_all)) {
                    const button = $(this);
                    const originalText = button.text();
                    button.text(chicago_loft_search_admin.deleting_text).prop('disabled', true);

                    $.ajax({
                        url: chicago_loft_search_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'chicago_loft_search_delete_all_listings',
                            nonce: chicago_loft_search_admin.delete_all_listings_nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                window.location.reload();
                            } else {
                                alert('<?php _e('Error:', 'chicago-loft-search'); ?> ' + response.data.message);
                                button.text(originalText).prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('<?php _e('An error occurred. Please try again.', 'chicago-loft-search'); ?>');
                            button.text(originalText).prop('disabled', false);
                        }
                    });
                }
            });
        });
    </script>
    <?php
}

/**
 * Import page
 */
function chicago_loft_search_import_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    include(CHICAGO_LOFT_SEARCH_PLUGIN_DIR . 'admin/partials/import-page.php');
}

/**
 * Logs page
 */
function chicago_loft_search_logs_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    echo '<div class="wrap"><h1>' . __('Search Logs', 'chicago-loft-search') . '</h1><p>' . __('View logs of user search queries and API responses here.', 'chicago-loft-search') . '</p></div>';
}


/**
 * Register shortcode for the search interface
 */
function chicago_loft_search_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'title' => __('Chicago Loft Search', 'chicago-loft-search'),
            'placeholder' => __('Search for lofts in Chicago...', 'chicago-loft-search'),
            'button_text' => __('Search', 'chicago-loft-search'),
            'show_examples' => 'yes',
        ),
        $atts,
        'chicago_loft_search'
    );
    
    if (!chicago_loft_search_user_can_search()) {
        return '<div class="chicago-loft-search-error">' . __('You do not have permission to use this search feature.', 'chicago-loft-search') . '</div>';
    }
    
    if (chicago_loft_search_user_reached_limit()) {
        return '<div class="chicago-loft-search-error">' . __('You have reached your search limit for today. Please try again tomorrow.', 'chicago-loft-search') . '</div>';
    }
    
    $options = get_option('chicago_loft_search_options');
    $example_questions_setting = isset($options['example_questions']) ? $options['example_questions'] : "Show me lofts in West Loop under $500,000\nWhat are the largest lofts in River North?\nFind 2 bedroom lofts in South Loop with exposed brick";
    $example_questions = array_map('trim', preg_split('/\\r\\n|\\r|\\n/', $example_questions_setting));
    $example_questions = array_filter($example_questions); 
    
    ob_start();
    
    include(CHICAGO_LOFT_SEARCH_PLUGIN_DIR . 'public/partials/search-interface.php');
    
    return ob_get_clean();
}
add_shortcode('chicago_loft_search', 'chicago_loft_search_shortcode');

/**
 * Check if user can use the search feature
 */
function chicago_loft_search_user_can_search() {
    $options = get_option('chicago_loft_search_options');
    $allowed_roles = isset($options['allowed_user_roles']) ? $options['allowed_user_roles'] : array('administrator');
    
    if (!is_user_logged_in() && in_array('visitor', $allowed_roles)) {
        return true;
    }
    
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        foreach ($user->roles as $role) {
            if (in_array($role, $allowed_roles)) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Check if user has reached their search limit
 */
function chicago_loft_search_user_reached_limit() {
    global $wpdb;
    $options = get_option('chicago_loft_search_options');
    $daily_limit = isset($options['daily_query_limit']) ? $options['daily_query_limit'] : 50;
    
    $user_id = get_current_user_id();
    $ip_address = chicago_loft_search_get_client_ip();
    
    $table_name = $wpdb->prefix . 'chicago_loft_search_usage';
    
    $usage = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND ip_address = %s",
            $user_id,
            $ip_address
        )
    );
    
    if (null === $usage) {
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'ip_address' => $ip_address,
                'daily_count' => 0,
                'monthly_count' => 0,
                'last_reset_daily' => current_time('mysql'),
                'last_reset_monthly' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%d', '%s', '%s')
        );
        return false;
    }
    
    $last_reset_daily = new DateTime($usage->last_reset_daily);
    $now = new DateTime(current_time('mysql'));
    $interval = $last_reset_daily->diff($now);
    
    if ($interval->days >= 1) {
        $wpdb->update(
            $table_name,
            array(
                'daily_count' => 0,
                'last_reset_daily' => current_time('mysql'),
            ),
            array('id' => $usage->id),
            array('%d', '%s'),
            array('%d')
        );
        $usage->daily_count = 0; 
    }
    
    $last_reset_monthly = new DateTime($usage->last_reset_monthly);
    $interval_monthly = $last_reset_monthly->diff($now); 
    
    if ($interval_monthly->m >= 1 || $interval_monthly->y >= 1) {
        $wpdb->update(
            $table_name,
            array(
                'monthly_count' => 0,
                'last_reset_monthly' => current_time('mysql'),
            ),
            array('id' => $usage->id),
            array('%d', '%s'),
            array('%d')
        );
        $usage->monthly_count = 0;
    }
    
    if ($usage->daily_count >= $daily_limit) {
        return true;
    }
    
    $monthly_limit = isset($options['monthly_query_limit']) ? $options['monthly_query_limit'] : 1000;
    if ($usage->monthly_count >= $monthly_limit) {
        return true;
    }
    
    return false;
}

/**
 * Get client IP address
 */
function chicago_loft_search_get_client_ip() {
    $ip_address = '';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
        $ip_address = $_SERVER['HTTP_CLIENT_IP'];
    }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($ip_list as $ip) {
            if (filter_var(trim($ip), FILTER_VALIDATE_IP)) {
                $ip_address = trim($ip);
                break;
            }
        }
    }
    elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip_address = $_SERVER['REMOTE_ADDR'];
    }
    
    return $ip_address ?: '127.0.0.1';
}

/**
 * Update usage count
 */
function chicago_loft_search_update_usage_count() {
    global $wpdb;
    
    $user_id = get_current_user_id();
    $ip_address = chicago_loft_search_get_client_ip();
    
    $table_name = $wpdb->prefix . 'chicago_loft_search_usage';
    
    $usage = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND ip_address = %s",
            $user_id,
            $ip_address
        )
    );
    
    if (null === $usage) {
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'ip_address' => $ip_address,
                'daily_count' => 1,
                'monthly_count' => 1,
                'last_reset_daily' => current_time('mysql'),
                'last_reset_monthly' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%d', '%s', '%s')
        );
    } else {
        $wpdb->update(
            $table_name,
            array(
                'daily_count' => $usage->daily_count + 1,
                'monthly_count' => $usage->monthly_count + 1,
            ),
            array('id' => $usage->id),
            array('%d', '%d'),
            array('%d')
        );
    }
}

/**
 * Log search query
 */
function chicago_loft_search_log_query($query, $response, $tokens_used) {
    global $wpdb;
    
    $user_id = get_current_user_id();
    $ip_address = chicago_loft_search_get_client_ip();
    
    $table_name = $wpdb->prefix . 'chicago_loft_search_logs';
    
    $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'ip_address' => $ip_address,
            'query' => $query,
            'response' => $response,
            'tokens_used' => $tokens_used,
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%s', '%s', '%s', '%d', '%s')
    );
}

/**
 * AJAX handler for search requests
 */
function chicago_loft_search_ajax_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chicago_loft_search_public_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'chicago-loft-search')));
        return;
    }
    
    if (!chicago_loft_search_user_can_search()) {
        wp_send_json_error(array('message' => __('You do not have permission to use this search feature.', 'chicago-loft-search')));
        return;
    }
    
    if (chicago_loft_search_user_reached_limit()) {
        wp_send_json_error(array('message' => __('You have reached your search limit for today. Please try again tomorrow.', 'chicago-loft-search')));
        return;
    }
    
    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    
    if (empty($query)) {
        wp_send_json_error(array('message' => __('Please enter a search query.', 'chicago-loft-search')));
        return;
    }
    
    $options = get_option('chicago_loft_search_options');
    $api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
    $model = isset($options['model']) ? $options['model'] : 'gpt-4o';
    $system_prompt_template = isset($options['system_prompt']) ? $options['system_prompt'] : 'You are a helpful assistant specializing in Chicago loft and high rise properties. Provide accurate information based on the data available. Keep responses concise and relevant to real estate inquiries only. Please keep users on this site and never mention competitor real estate sites in the result messaging.';
    
    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('API key not configured. Please contact the administrator.', 'chicago-loft-search')));
        return;
    }

    // --- Start: Database Query for Context ---
    global $wpdb;
    $listings_table = $wpdb->prefix . 'chicago_loft_listings';
    $db_listings_for_context = array();
    $listings_for_formatter = array(); // For linking MLS IDs in response
    
    // Simple keyword extraction for DB query (can be enhanced)
    $query_keywords = preg_split('/\\s+/', strtolower($query));
    $query_keywords = array_filter($query_keywords, function($kw) { return strlen($kw) > 2; });

    if (!empty($query_keywords)) {
        $search_conditions = array();
        foreach ($query_keywords as $keyword) {
            $search_conditions[] = $wpdb->prepare("raw_data LIKE %s", '%' . $wpdb->esc_like($keyword) . '%');
        }
        $search_sql_where = implode(' OR ', $search_conditions);
        
        // Fetch a limited number of relevant listings from DB
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $matched_db_listings = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT mls_id, raw_data FROM $listings_table WHERE listing_type = 'imported_csv_item' AND status = 'active' AND ($search_sql_where) LIMIT 10"
        );

        if (!empty($matched_db_listings)) {
            foreach ($matched_db_listings as $db_listing) {
                $decoded_raw_data = json_decode($db_listing->raw_data, true);
                if (is_array($decoded_raw_data)) {
                    // Add mls_id to the decoded raw data if not already prominently there, for context and formatter
                    if (!isset($decoded_raw_data['mls_id']) && !isset($decoded_raw_data['MLS ID'])) {
                         $decoded_raw_data['Retrieved_MLS_ID'] = $db_listing->mls_id;
                    }
                    $db_listings_for_context[] = $decoded_raw_data;
                    $listings_for_formatter[] = array_merge(['mls_id' => $db_listing->mls_id], $decoded_raw_data); // Ensure mls_id is top-level for formatter
                }
            }
        }
    }
    // --- End: Database Query for Context ---
    
    $final_system_prompt = $system_prompt_template;
    $data_context_for_api_string = "";
    $max_chars_for_context = apply_filters('chicago_loft_search_db_context_max_chars', 15000);

    if (!empty($db_listings_for_context)) {
        $json_encoded_db_data = json_encode($db_listings_for_context);
        $truncation_note = "";

        if (strlen($json_encoded_db_data) > $max_chars_for_context) {
            $data_context_for_api_string = substr($json_encoded_db_data, 0, $max_chars_for_context);
            // Basic truncation, try to keep valid JSON structure
            $last_brace = strrpos($data_context_for_api_string, '}');
            $last_bracket = strrpos($data_context_for_api_string, ']');
             if ($last_bracket > $last_brace && $last_bracket !== false) { // Ends with array of objects
                $data_context_for_api_string = substr($data_context_for_api_string, 0, $last_bracket + 1);
            } elseif ($last_brace !== false) { // Ends with an object
                $data_context_for_api_string = substr($data_context_for_api_string, 0, $last_brace + 1);
            }
            // Ensure it's valid JSON after truncation, or append note
             if (json_decode($data_context_for_api_string) === null && json_last_error() !== JSON_ERROR_NONE) {
                // If still not valid, might need more sophisticated truncation or send less data
             }
            $truncation_note = "\n[Note: The provided database context was truncated to fit limits and may be incomplete.]";
        } else {
            $data_context_for_api_string = $json_encoded_db_data;
        }
        
        if (!empty($data_context_for_api_string) && (json_decode($data_context_for_api_string) !== null || json_last_error() === JSON_ERROR_NONE)) {
            $final_system_prompt .= "\n\nUse the following data from imported listings to answer the user's query. This data is specific to their search and is an array of listing objects, where each object contains various property details:\n" . $data_context_for_api_string . $truncation_note;
        } else {
             $final_system_prompt .= "\n\nRelevant data was found in imported listings but could not be fully processed for your query: \"" . esc_html($query) . "\". Please use your general knowledge or ask for clarification.";
        }

    } else {
        // Fallback or augment with CSV file querying if no DB results or if desired
        $csv_file_data_for_gpt = chicago_loft_search_query_csv_documents_optimized($query, $max_chars_for_context); // Use the directly defined optimized function

        if(!empty($csv_file_data_for_gpt)) {
            $json_encoded_csv_file_data = json_encode($csv_file_data_for_gpt);
            // Truncation logic for csv_file_data similar to above if needed
            $final_system_prompt .= "\n\nAdditionally, consider the following data extracted from uploaded CSV files that might be relevant. This data is structured as an array of objects, where each object represents a CSV file and contains its 'source_file', 'columns', and 'rows' of matching data:\n" . $json_encoded_csv_file_data;
             // Populate listings_for_formatter from CSV file data if it's the primary source here
            if (empty($db_listings_for_context)) { // Only if DB didn't yield results
                // If $csv_file_data_for_gpt is a string (from original function), this loop won't work as expected.
                // If it's structured (from the optimized function, if that were still in use), it would.
                // For now, with original function, this part will be ineffective for listings_for_formatter.
                if (is_array($csv_file_data_for_gpt)) { // Check if it's an array (optimized version would return this)
                    foreach ($csv_file_data_for_gpt as $file_data) {
                        if (isset($file_data['rows']) && is_array($file_data['rows'])) {
                            foreach($file_data['rows'] as $row_item) {
                                $mls_key_found = '';
                                foreach (['MLS ID', 'mls_id', 'MLSID', 'Listing ID'] as $key_check) {
                                    if(isset($row_item[$key_check])) { $mls_key_found = $row_item[$key_check]; break; }
                                }
                                if($mls_key_found) $listings_for_formatter[] = array_merge(['mls_id' => $mls_key_found], $row_item);
                                else  $listings_for_formatter[] = $row_item; 
                            }
                        }
                    }
                }
            }
        } else {
            $final_system_prompt .= "\n\nNo specific data was found in imported listings or uploaded CSV files matching the query: \"" . esc_html($query) . "\". Please use your general knowledge or ask for clarification.";
        }
    }
    
    $request_data = array(
        'model' => $model,
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $final_system_prompt
            ),
            array(
                'role' => 'user',
                'content' => $query
            )
        ),
        'temperature' => isset($options['temperature']) ? floatval($options['temperature']) : 0.7,
        'max_tokens' => isset($options['max_tokens']) ? intval($options['max_tokens']) : 1000
    );
    
    $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($request_data),
            'timeout' => 30,
        )
    );
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
        return;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (!isset($data['choices'][0]['message']['content'])) {
        wp_send_json_error(array('message' => __('Invalid response from API.', 'chicago-loft-search')));
        return;
    }
    
    $content = $data['choices'][0]['message']['content'];
    $tokens_used = isset($data['usage']['total_tokens']) ? $data['usage']['total_tokens'] : 0;
    
    chicago_loft_search_update_usage_count();
    chicago_loft_search_log_query($query, $content, $tokens_used);
    
    $formatted_response = chicago_loft_search_format_response($content, $listings_for_formatter); 
    
    wp_send_json_success(array(
        'response' => $formatted_response,
        'tokens_used' => $tokens_used
    ));
}
add_action('wp_ajax_chicago_loft_search', 'chicago_loft_search_ajax_handler');
add_action('wp_ajax_nopriv_chicago_loft_search', 'chicago_loft_search_ajax_handler');

/**
 * Extract keywords from query
 */
function chicago_loft_search_extract_keywords($query) {
    $neighborhoods = array(
        'West Loop', 'River North', 'South Loop', 'Lincoln Park', 'Wicker Park',
        'Bucktown', 'Logan Square', 'Pilsen', 'Printer\'s Row', 'Gold Coast',
        'Streeterville', 'Old Town', 'Lakeview', 'Uptown', 'Andersonville',
        'Ravenswood', 'Rogers Park', 'Edgewater', 'Hyde Park', 'Bronzeville'
    );
    
    $features = array(
        'exposed brick', 'high ceiling', 'timber', 'concrete', 'hardwood floor',
        'industrial', 'open floor plan', 'large window', 'skylight', 'rooftop',
        'balcony', 'terrace', 'garage', 'parking', 'elevator', 'doorman',
        'gym', 'fitness', 'pool', 'washer', 'dryer', 'stainless', 'granite',
        'marble', 'fireplace', 'pet friendly', 'dog friendly', 'cat friendly'
    );
    
    $keywords = array();
    
    foreach ($neighborhoods as $neighborhood) {
        if (stripos($query, $neighborhood) !== false) {
            $keywords[] = $neighborhood;
        }
    }
    
    foreach ($features as $feature) {
        if (stripos($query, $feature) !== false) {
            $keywords[] = $feature;
        }
    }
    
    preg_match_all('/\\$?(\\d+[,\\.]?\\d*)\\s*(k|thousand|million|m|sq\\s*ft|square\\s*feet|bedroom|bed|bath|bathroom)?/i', $query, $matches);
    
    if (!empty($matches[0])) {
        foreach ($matches[0] as $match) {
            $keywords[] = trim($match);
        }
    }
    
    return $keywords;
}

/**
 * Format the response for display
 */
function chicago_loft_search_format_response($content, $listing_data) { 
    $formatted = $content;
    
    $formatted = preg_replace('/^# (.*?)$/m', '<h2>$1</h2>', $formatted);
    $formatted = preg_replace('/^## (.*?)$/m', '<h3>$1</h3>', $formatted);
    $formatted = preg_replace('/^### (.*?)$/m', '<h4>$1</h4>', $formatted);
    
    $formatted = preg_replace('/\\*\\*(.*?)\\*\\*/m', '<strong>$1</strong>', $formatted);
    
    $formatted = preg_replace('/\\*(.*?)\\*/m', '<em>$1</em>', $formatted);
    
    $formatted = preg_replace('/\\[(.*?)\\]\\((.*?)\\)/m', '<a href="$2" target="_blank">$1</a>', $formatted);
    
    $formatted = preg_replace_callback('/(^\\s*[\\-\\*\\+]\\s+.*(?:\\n^\\s*[\\-\\*\\+]\\s+.*)*)/m', function($matches) {
        $list_items = preg_replace('/^\\s*[\\-\\*\\+]\\s+(.*)/m', '<li>$1</li>', $matches[0]);
        return '<ul>' . $list_items . '</ul>';
    }, $formatted);
    
    $formatted = preg_replace_callback('/(^\\s*\\d+\\.\\s+.*(?:\\n^\\s*\\d+\\.\\s+.*)*)/m', function($matches) {
        $list_items = preg_replace('/^\\s*\\d+\\.\\s+(.*)/m', '<li class="loft-listing-item">$1</li>', $matches[0]);
        return '<ul class="loft-listings">' . $list_items . '</ul>';
    }, $formatted);
    
    $formatted = nl2br($formatted);
    $formatted = preg_replace_callback('/<(ul|ol)>(.*?)<\\/\\1>/is', function($matches) {
        return '<' . $matches[1] . '>' . str_replace('<br />', '', $matches[2]) . '</' . $matches[1] . '>';
    }, $formatted);

    if (is_array($listing_data)) {
        foreach ($listing_data as $listing) {
            if (is_array($listing) && isset($listing['mls_id']) && !empty($listing['mls_id'])) {
                $mls_id = $listing['mls_id'];
                if (strpos($formatted, $mls_id) !== false) {
                    $pattern = '/(?<!href=\\"[^\\"]*|data-mls-id=\\"[^\\"]*\\")' . preg_quote($mls_id, '/') . '(?![^\\"]*\\"\\s*>|[^<]*<\\/a>)/';
                    $listing_url = home_url('/loft/' . $mls_id); 
                    if (isset($listing['listing_type'])) { 
                        if ($listing['listing_type'] === 'building') {
                            // $listing_url = home_url('/building/' . $mls_id); 
                        } elseif ($listing['listing_type'] === 'agent') {
                            // $listing_url = home_url('/agent/' . $mls_id); 
                        }
                    }
                    $replacement = '<a href="' . esc_url($listing_url) . '" class="chicago-loft-link" data-mls-id="' . esc_attr($mls_id) . '">' . esc_html($mls_id) . '</a>';
                    $formatted = preg_replace($pattern, $replacement, $formatted);
                }
            } elseif (is_object($listing) && isset($listing->mls_id) && !empty($listing->mls_id)) { 
                 $mls_id = $listing->mls_id;
                if (strpos($formatted, $mls_id) !== false) {
                    $pattern = '/(?<!href=\\"[^\\"]*|data-mls-id=\\"[^\\"]*\\")' . preg_quote($mls_id, '/') . '(?![^\\"]*\\"\\s*>|[^<]*<\\/a>)/';
                    $listing_url = home_url('/loft/' . $mls_id); 
                    if (isset($listing->listing_type)) { 
                        if ($listing->listing_type === 'building') {
                            // $listing_url = home_url('/building/' . $mls_id); 
                        } elseif ($listing->listing_type === 'agent') {
                            // $listing_url = home_url('/agent/' . $mls_id); 
                        }
                    }
                    $replacement = '<a href="' . esc_url($listing_url) . '" class="chicago-loft-link" data-mls-id="' . esc_attr($mls_id) . '">' . esc_html($mls_id) . '</a>';
                    $formatted = preg_replace($pattern, $replacement, $formatted);
                }
            }
        }
    }


    $competitor_sites = array(
        'zillow', 'redfin', 'realtor.com', 'trulia', 'homes.com', 'homesnap', 
        'movoto', 'homefinder', 'apartments.com', 'century 21', 'coldwell banker', 
        'compass', 're/max', 'keller williams', 'sotheby\'s', 'berkshire hathaway'
    );

    $patterns = array();
    foreach ($competitor_sites as $site) {
        $patterns[] = '/\\b(https?:\\/\\/)?(www\\.)?' . preg_quote($site, '/') . '\\b/i';
        $patterns[] = '/\\b(check|visit|go to|look at|search on|use|try|browse|explore|see|view|refer to|find on|listings? on|properties? on|homes? on)\\s+.*\\b' . preg_quote($site, '/') . '\\b/i';
    }

    $patterns[] = '/(check|visit|go to|look at|search on|use|try|browse|explore|see|view|refer to|find on|listings? on|properties? on|homes? on)\\s+(other|popular|leading|major|top|additional|alternative|different|various)\\s+(real estate|property|listing|home)\\s+(sites|platforms|websites|services|portals)/i';
    $replacement = 'contact a local real estate agent';
    $formatted = preg_replace($patterns, $replacement, $formatted);
    $formatted = preg_replace('/(popular|leading|major|top|various|other)\\s+(real estate|property|listing|home)\\s+(sites|platforms|websites|services|portals)\\s+like\\s+.+?(or|and)\\s+.+?(\\.|,|for)/i', 'contacting a local real estate agent$5', $formatted);
    $formatted = preg_replace('/(I\\s+recommend|I\\s+suggest|try|consider)\\s+(checking|visiting|using|exploring)\\s+(popular|leading|major|top|various|other)\\s+(real estate|property|listing)\\s+(sites|platforms|websites|portals|services)/i', 'I recommend contacting a local real estate agent', $formatted);
    $formatted = preg_replace('/(other|external|additional|alternative|different|various)\\s+(real estate|property|listing|home)\\s+(sites|platforms|websites|services|portals)/i', 'local real estate professionals', $formatted);
    
    return $formatted;
}

/**
 * Schedule daily and monthly usage resets
 */
function chicago_loft_search_schedule_events() {
    if (!wp_next_scheduled('chicago_loft_search_daily_reset')) {
        wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'chicago_loft_search_daily_reset');
    }
    
    if (!wp_next_scheduled('chicago_loft_search_monthly_reset')) {
        wp_schedule_event(strtotime('first day of next month midnight'), 'monthly', 'chicago_loft_search_monthly_reset');
    }
}
add_action('wp', 'chicago_loft_search_schedule_events');

/**
 * Reset daily usage counts
 */
function chicago_loft_search_reset_daily_counts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_search_usage';
    
    $wpdb->query("UPDATE $table_name SET daily_count = 0, last_reset_daily = '" . current_time('mysql') . "'");
}
add_action('chicago_loft_search_daily_reset', 'chicago_loft_search_reset_daily_counts');

/**
 * Reset monthly usage counts
 */
function chicago_loft_search_reset_monthly_counts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_search_usage';
    
    $wpdb->query("UPDATE $table_name SET monthly_count = 0, last_reset_monthly = '" . current_time('mysql') . "'");
}
add_action('chicago_loft_search_monthly_reset', 'chicago_loft_search_reset_monthly_counts');

/**
 * Add custom schedule for monthly events
 */
function chicago_loft_search_add_cron_schedules($schedules) {
    $schedules['monthly'] = array(
        'interval' => 30 * 24 * 60 * 60, 
        'display' => __('Once a month', 'chicago-loft-search')
    );
    return $schedules;
}
add_filter('cron_schedules', 'chicago_loft_search_add_cron_schedules');

/**
 * Register REST API endpoint for MLS data import
 */
function chicago_loft_search_register_rest_routes() {
    register_rest_route('chicago-loft-search/v1', '/import', array(
        'methods' => 'POST',
        'callback' => 'chicago_loft_search_import_data_rest', 
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ));
}
add_action('rest_api_init', 'chicago_loft_search_register_rest_routes');

/**
 * Import MLS data via REST API
 */
function chicago_loft_search_import_data_rest($request) { 
    $data = $request->get_json_params();
    
    if (!isset($data['listings']) || !is_array($data['listings'])) {
        return new WP_Error('invalid_data', __('Invalid data format.', 'chicago-loft-search'), array('status' => 400));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $imported = 0;
    $updated = 0;
    $errors = 0;
    
    // For REST API, we assume it's structured somewhat, but still prioritize raw_data
    // The $listing here is an associative array of a single listing
    foreach ($data['listings'] as $listing_from_rest) {
        $mls_id = isset($listing_from_rest['mls_id']) ? sanitize_text_field($listing_from_rest['mls_id']) : null;
        if (!$mls_id) {
            // Attempt to generate an MLS ID if not provided, using some fields from the listing
            $address_for_id = isset($listing_from_rest['address']) ? $listing_from_rest['address'] : (isset($listing_from_rest['street_address']) ? $listing_from_rest['street_address'] : '');
            $mls_id = _chicago_loft_search_generate_mls_id_from_data($address_for_id . '-' . uniqid());
        }

        $data_for_db = array(
            'mls_id'       => $mls_id,
            'raw_data'     => json_encode($listing_from_rest), // Store the entire REST listing item
            'status'       => isset($listing_from_rest['status']) ? sanitize_text_field($listing_from_rest['status']) : 'active',
            'listing_type' => isset($listing_from_rest['listing_type']) ? sanitize_text_field($listing_from_rest['listing_type']) : 'imported_csv_item', // Or a specific type if provided
            'date_updated' => current_time('mysql'),
            // Set other specific columns to NULL or default as they are now in raw_data
            'address' => null, 'neighborhood' => null, 'price' => null, 'bedrooms' => null, 'bathrooms' => null,
            'square_feet' => null, 'year_built' => null, 'features' => null, 'description' => null, 'image_urls' => null,
            'building_name' => null, 'units' => null, 'floors' => null, 'hoa_fee' => null, 'pet_policy' => null, 'amenities' => null,
            'agent_name' => null, 'email' => null, 'phone' => null, 'bio' => null, 'areas_of_expertise' => null, 'specialty' => null, 'license' => null,
        );
        
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE mls_id = %s", $data_for_db['mls_id']));
        
        $current_format_array = array();
        foreach (array_values($data_for_db) as $value) {
            if (is_int($value)) $current_format_array[] = '%d';
            elseif (is_float($value)) $current_format_array[] = '%f';
            else $current_format_array[] = '%s';
        }

        if ($existing) {
            $result = $wpdb->update( $table_name, $data_for_db, array('mls_id' => $data_for_db['mls_id']), $current_format_array, array('%s'));
            if ($result !== false) $updated++; else $errors++;
        } else {
            $data_for_db['date_added'] = current_time('mysql');
            // Rebuild format array as date_added is new
            $current_format_array = array();
            foreach (array_values($data_for_db) as $value) {
                if (is_int($value)) $current_format_array[] = '%d';
                elseif (is_float($value)) $current_format_array[] = '%f';
                else $current_format_array[] = '%s';
            }
            $result = $wpdb->insert( $table_name, $data_for_db, $current_format_array);
            if ($result) $imported++; else $errors++;
        }
    }
    
    $options = get_option('chicago_loft_search_options');
    $options['last_sync_date'] = current_time('mysql');
    update_option('chicago_loft_search_options', $options);
    
    return array(
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'errors' => $errors,
        'timestamp' => current_time('mysql'),
    );
}

/**
 * Add plugin action links
 */
function chicago_loft_search_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=chicago-loft-search') . '">' . __('Settings', 'chicago-loft-search') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . CHICAGO_LOFT_SEARCH_PLUGIN_BASENAME, 'chicago_loft_search_plugin_action_links');

/**
 * Load plugin text domain
 */
function chicago_loft_search_load_textdomain() {
    load_plugin_textdomain('chicago-loft-search', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'chicago_loft_search_load_textdomain');

/**
 * AJAX handler for API key verification
 */
function chicago_loft_search_verify_api_key() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chicago_loft_search_verify_api_key')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'chicago-loft-search')));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'chicago-loft-search')));
    }
    
    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
    
    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('API key is empty.', 'chicago-loft-search')));
    }
    
    $response = wp_remote_get(
        'https://api.openai.com/v1/models',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        )
    );
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $error_message = isset($data['error']['message']) ? $data['error']['message'] : __('Invalid API key or API error.', 'chicago-loft-search');
        wp_send_json_error(array('message' => $error_message));
    }
    
    wp_send_json_success(array('message' => __('API key is valid!', 'chicago-loft-search')));
}
add_action('wp_ajax_chicago_loft_search_verify_api_key', 'chicago_loft_search_verify_api_key');

/**
 * AJAX handler for getting usage statistics
 */
function chicago_loft_search_get_usage_stats() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chicago_loft_search_get_usage_stats')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'chicago-loft-search')));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'chicago-loft-search')));
    }
    
    global $wpdb;
    $logs_table = $wpdb->prefix . 'chicago_loft_search_logs';
    
    $today = date('Y-m-d');
    $first_day_of_month = date('Y-m-01');
    
    $today_stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) as queries, SUM(tokens_used) as tokens FROM $logs_table WHERE DATE(created_at) = %s",
            $today
        )
    );
    
    $month_stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) as queries, SUM(tokens_used) as tokens FROM $logs_table WHERE DATE(created_at) >= %s",
            $first_day_of_month
        )
    );
    
    $today_tokens = intval($today_stats->tokens) ?: 0;
    $month_tokens = intval($month_stats->tokens) ?: 0;
    
    $options = get_option('chicago_loft_search_options');
    $model_for_cost = isset($options['model']) ? $options['model'] : 'gpt-3.5-turbo'; 
    $cost_per_thousand_tokens_input = 0.0005; 
    $cost_per_thousand_tokens_output = 0.0015; 
    if (strpos($model_for_cost, 'gpt-4o') !== false) {
        $cost_per_thousand_tokens_input = 0.005; 
        $cost_per_thousand_tokens_output = 0.015;
    } elseif (strpos($model_for_cost, 'gpt-4-turbo') !== false) {
        $cost_per_thousand_tokens_input = 0.01;
        $cost_per_thousand_tokens_output = 0.03;
    } elseif (strpos($model_for_cost, 'gpt-4') !== false && strpos($model_for_cost, 'turbo') === false) { 
        $cost_per_thousand_tokens_input = 0.03;
        $cost_per_thousand_tokens_output = 0.06;
    }
    $estimated_avg_cost_per_thousand = ($cost_per_thousand_tokens_input / 3) + ($cost_per_thousand_tokens_output * 2 / 3);


    $today_cost = number_format(($today_tokens / 1000) * $estimated_avg_cost_per_thousand, 2);
    $month_cost = number_format(($month_tokens / 1000) * $estimated_avg_cost_per_thousand, 2);
    
    wp_send_json_success(array(
        'today_queries' => intval($today_stats->queries) ?: 0,
        'today_tokens' => $today_tokens,
        'today_cost' => $today_cost,
        'month_queries' => intval($month_stats->queries) ?: 0,
        'month_tokens' => $month_tokens,
        'month_cost' => $month_cost
    ));
}
add_action('wp_ajax_chicago_loft_search_get_usage_stats', 'chicago_loft_search_get_usage_stats');

/**
 * AJAX handler for exporting settings
 */
function chicago_loft_search_export_settings() {
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'chicago_loft_search_export_settings')) {
        wp_die(__('Security check failed.', 'chicago-loft-search'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to perform this action.', 'chicago-loft-search'));
    }
    
    $options = get_option('chicago_loft_search_options');
    
    if (isset($options['openai_api_key'])) {
        $options_to_export = $options;
        unset($options_to_export['openai_api_key']);
    } else {
        $options_to_export = $options;
    }
    
    $export_data = array(
        'plugin_name' => 'Chicago Loft Search',
        'plugin_version' => CHICAGO_LOFT_SEARCH_VERSION,
        'export_date' => current_time('mysql'),
        'settings' => $options_to_export
    );
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="chicago-loft-search-settings-' . date('Y-m-d') . '.json"');
    header('Pragma: no-cache');
    
    echo json_encode($export_data, JSON_PRETTY_PRINT);
    exit;
}
add_action('wp_ajax_chicago_loft_search_export_settings', 'chicago_loft_search_export_settings');

/**
 * AJAX handler for importing settings
 */
function chicago_loft_search_import_settings() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chicago_loft_search_import_settings')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'chicago-loft-search')));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'chicago-loft-search')));
    }
    
    if (!isset($_FILES['settings_file']) || $_FILES['settings_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(array('message' => __('No file uploaded or upload error.', 'chicago-loft-search')));
    }
    
    $file_contents = file_get_contents($_FILES['settings_file']['tmp_name']);
    if (!$file_contents) {
        wp_send_json_error(array('message' => __('Could not read the uploaded file.', 'chicago-loft-search')));
    }
    
    $import_data = json_decode($file_contents, true);
    if (!$import_data || !isset($import_data['settings']) || !is_array($import_data['settings'])) {
        wp_send_json_error(array('message' => __('Invalid settings file format.', 'chicago-loft-search')));
    }
    
    $current_options = get_option('chicago_loft_search_options');
    $new_options = $import_data['settings'];

    if (!isset($new_options['openai_api_key']) || empty($new_options['openai_api_key'])) {
        if (isset($current_options['openai_api_key'])) {
            $new_options['openai_api_key'] = $current_options['openai_api_key'];
        }
    }
    
    update_option('chicago_loft_search_options', $new_options);
    
    wp_send_json_success(array('message' => __('Settings imported successfully!', 'chicago-loft-search')));
}
add_action('wp_ajax_chicago_loft_search_import_settings', 'chicago_loft_search_import_settings');

/**
 * AJAX handler for manual sync
 */
function chicago_loft_search_manual_sync() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chicago_loft_search_manual_sync')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'chicago-loft-search')));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'chicago-loft-search')));
    }
    
    $options = get_option('chicago_loft_search_options');
    $mls_api_endpoint = isset($options['mls_api_endpoint']) ? $options['mls_api_endpoint'] : '';
    $mls_api_key = isset($options['mls_api_key']) ? $options['mls_api_key'] : ''; 
    
    if (empty($mls_api_endpoint)) {
        wp_send_json_error(array('message' => __('MLS API endpoint not configured. Please set it in the Import/Export tab.', 'chicago-loft-search')));
    }
    
    $request_args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'timeout' => 60,
    );

    if (!empty($mls_api_key)) {
        $request_args['headers']['Authorization'] = 'Bearer ' . $mls_api_key;
    }

    $response = wp_remote_get($mls_api_endpoint, $request_args);
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $error_message = sprintf(__('API returned error code: %d', 'chicago-loft-search'), $response_code);
        wp_send_json_error(array('message' => $error_message));
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (!$data || !isset($data['listings']) || !is_array($data['listings'])) {
        wp_send_json_error(array('message' => __('Invalid response from MLS API. Expected "listings" array.', 'chicago-loft-search')));
    }
    
    $mock_request = new WP_REST_Request('POST', '/chicago-loft-search/v1/import');
    $mock_request->set_body(json_encode($data)); 
    
    $import_result = chicago_loft_search_import_data_rest($mock_request);

    if (is_wp_error($import_result)) {
        wp_send_json_error(array('message' => $import_result->get_error_message()));
    } else {
        $result_data = $import_result->get_data();
        $message = sprintf(
            __('Sync completed: %d imported, %d updated, %d errors.', 'chicago-loft-search'),
            $result_data['imported'],
            $result_data['updated'],
            $result_data['errors']
        );
        wp_send_json_success(array('message' => $message));
    }
}
add_action('wp_ajax_chicago_loft_search_manual_sync', 'chicago_loft_search_manual_sync');

/**
 * AJAX handler for getting loft details
 */
function chicago_loft_search_get_loft_details() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chicago_loft_search_public_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'chicago-loft-search')));
    }
    
    $mls_id = isset($_POST['mls_id']) ? sanitize_text_field($_POST['mls_id']) : '';
    
    if (empty($mls_id)) {
        wp_send_json_error(array('message' => __('No MLS ID provided.', 'chicago-loft-search')));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    
    $loft = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE mls_id = %s AND status = 'active'",
            $mls_id
        )
    );
    
    if (!$loft) {
        wp_send_json_error(array('message' => __('Item not found or not active.', 'chicago-loft-search')));
    }
    
    $image_urls = json_decode($loft->image_urls, true);
    $images_html = '';
    
    if (is_array($image_urls) && !empty($image_urls)) {
        $images_html .= '<div class="loft-images-slider">'; 
        foreach ($image_urls as $url) {
            $images_html .= '<div class="loft-image-slide">';
            $images_html .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($loft->address ?: $loft->building_name ?: $loft->agent_name) . '" class="loft-image">';
            $images_html .= '</div>';
        }
        $images_html .= '</div>';
    }
    
    $features_array = !empty($loft->features) ? explode(',', $loft->features) : array();
    $features_html = '';
    if (!empty($features_array)) {
        $features_html = '<ul class="loft-features-list">'; 
        foreach ($features_array as $feature) {
            $feature = trim($feature);
            if (!empty($feature)) {
                $features_html .= '<li>' . esc_html($feature) . '</li>';
            }
        }
        $features_html .= '</ul>';
    }

    $title_text = $loft->address ?: $loft->building_name ?: $loft->agent_name ?: __('Details', 'chicago-loft-search');
    
    $html = '
        <div class="listing-details"> 
            <h2 id="modal-title" class="listing-title">' . esc_html($title_text) . '</h2>';

    if ($loft->listing_type === 'loft' || $loft->listing_type === 'building' || $loft->listing_type === 'imported_csv_item') {
        $neighborhood_display = $loft->neighborhood;
        if (empty($neighborhood_display) && !empty($loft->raw_data)) {
            $raw = json_decode($loft->raw_data, true);
            if (is_array($raw) && !empty($raw['Neighborhood'])) {
                $neighborhood_display = $raw['Neighborhood'];
            } elseif (is_array($raw) && !empty($raw['City and Neighborhood'])) {
                 $neighborhood_display = _chicago_loft_search_extract_neighborhood_from_string($raw['City and Neighborhood']);
            }
        }
        $html .= '<div class="listing-neighborhood">' . esc_html($neighborhood_display ?: '') . '</div>';
    }
            
    if ($loft->listing_type === 'loft' || $loft->listing_type === 'imported_csv_item') {
        $price_display = $loft->price;
        $bedrooms_display = $loft->bedrooms;
        $bathrooms_display = $loft->bathrooms;
        $sqft_display = $loft->square_feet;

        if (!empty($loft->raw_data)) {
            $raw = json_decode($loft->raw_data, true);
            if (is_array($raw)) {
                $price_display = !empty($raw['Price']) ? $raw['Price'] : $price_display;
                $bedrooms_display = !empty($raw['Bedrooms']) ? $raw['Bedrooms'] : (!empty($raw['Beds']) ? $raw['Beds'] : $bedrooms_display);
                $bathrooms_display = !empty($raw['Bathrooms']) ? $raw['Bathrooms'] : (!empty($raw['Baths']) ? $raw['Baths'] : $bathrooms_display);
                $sqft_display = !empty($raw['Square Feet']) ? $raw['Square Feet'] : (!empty($raw['SqFt']) ? $raw['SqFt'] : $sqft_display);
            }
        }

        $html .= '<div class="listing-price-info">
                <div class="listing-price">' . ($price_display ? '$' . number_format_i18n(floatval(str_replace(array('$', ','), '', $price_display)))) : __('Price not available', 'chicago-loft-search')) . '</div>
                <div class="listing-specs">
                    <span class="listing-beds">' . ($bedrooms_display ? esc_html($bedrooms_display) . ' ' . _n('Bed', 'Beds', intval($bedrooms_display), 'chicago-loft-search') : '') . '</span>
                    <span class="listing-baths">' . ($bathrooms_display ? esc_html($bathrooms_display) . ' ' . _n('Bath', 'Baths', floatval($bathrooms_display), 'chicago-loft-search') : '') . '</span>
                    <span class="listing-sqft">' . ($sqft_display ? number_format_i18n(intval(str_replace(',', '', $sqft_display))) . ' ' . __('sq ft', 'chicago-loft-search') : '') . '</span>
                </div>
            </div>';
    }
    
    $html .= $images_html; 
            
    $description_display = $loft->description ?: ($loft->bio ?: '');
     if (empty($description_display) && !empty($loft->raw_data)) {
        $raw = json_decode($loft->raw_data, true);
        if (is_array($raw) && !empty($raw['Description'])) {
            $description_display = $raw['Description'];
        } elseif (is_array($raw) && !empty($raw['Remarks'])) {
            $description_display = $raw['Remarks'];
        }
    }
    $html .= '<div class="listing-description">
                <h3>' . __('Description', 'chicago-loft-search') . '</h3>
                <p>' . nl2br(esc_html($description_display ?: __('Description not available.', 'chicago-loft-search'))) . '</p>
            </div>';
            
    if ($loft->listing_type === 'loft' || $loft->listing_type === 'imported_csv_item') {
         $year_built_display = $loft->year_built;
         $sqft_display_detail = $loft->square_feet;
         $price_display_detail = $loft->price;

         if (!empty($loft->raw_data)) {
            $raw = json_decode($loft->raw_data, true);
            if (is_array($raw)) {
                $year_built_display = !empty($raw['Year Built']) ? $raw['Year Built'] : (!empty($raw['YearBuilt']) ? $raw['YearBuilt'] : $year_built_display);
                $sqft_display_detail = !empty($raw['Square Feet']) ? $raw['Square Feet'] : (!empty($raw['SqFt']) ? $raw['SqFt'] : $sqft_display_detail);
                $price_display_detail = !empty($raw['Price']) ? $raw['Price'] : $price_display_detail;
            }
        }
        $sqft_val = $sqft_display_detail ? intval(str_replace(',', '', $sqft_display_detail)) : 0;
        $price_val = $price_display_detail ? floatval(str_replace(array('$', ','), '', $price_display_detail)) : 0;

        $html .= '<div class="listing-details-grid">
                <div class="listing-details-column">
                    <h3>' . __('Property Details', 'chicago-loft-search') . '</h3>
                    <table class="listing-details-table">
                        <tr><th>' . __('MLS ID', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->mls_id) . '</td></tr>
                        <tr><th>' . __('Year Built', 'chicago-loft-search') . ':</th><td>' . ($year_built_display ? esc_html($year_built_display) : __('N/A', 'chicago-loft-search')) . '</td></tr>
                        <tr><th>' . __('Square Feet', 'chicago-loft-search') . ':</th><td>' . ($sqft_val ? number_format_i18n($sqft_val) : __('N/A', 'chicago-loft-search')) . '</td></tr>';
        if ($sqft_val > 0 && $price_val > 0) { 
            $html .= '<tr><th>' . __('Price per sq ft', 'chicago-loft-search') . ':</th><td>$' . number_format_i18n($price_val / $sqft_val, 2) . '</td></tr>';
        }
        $html .= '</table></div><div class="listing-details-column"><h3>' . __('Features', 'chicago-loft-search') . '</h3>' . ($features_html ?: __('Features not available.', 'chicago-loft-search')) . '</div></div>';
    
    } elseif ($loft->listing_type === 'building') {
        // Similar logic for building, pulling from raw_data if specific fields are empty
        $html .= '<div class="listing-details-grid">
                <div class="listing-details-column"><h3>' . __('Building Details', 'chicago-loft-search') . '</h3>
                <table class="listing-details-table">
                    <tr><th>' . __('Building Name', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->building_name ?: 'N/A') . '</td></tr>
                    <tr><th>' . __('Address', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->address ?: 'N/A') . '</td></tr>
                    <tr><th>' . __('Year Built', 'chicago-loft-search') . ':</th><td>' . ($loft->year_built ? esc_html($loft->year_built) : __('N/A', 'chicago-loft-search')) . '</td></tr>
                    <tr><th>' . __('Units', 'chicago-loft-search') . ':</th><td>' . ($loft->units ? esc_html($loft->units) : __('N/A', 'chicago-loft-search')) . '</td></tr>
                    <tr><th>' . __('Floors', 'chicago-loft-search') . ':</th><td>' . ($loft->floors ? esc_html($loft->floors) : __('N/A', 'chicago-loft-search')) . '</td></tr>
                    <tr><th>' . __('HOA Fee', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->hoa_fee ?: 'N/A') . '</td></tr>
                </table></div><div class="listing-details-column"><h3>' . __('Amenities & Policies', 'chicago-loft-search') . '</h3>
                <p><strong>' . __('Amenities:', 'chicago-loft-search') . '</strong> ' . esc_html($loft->amenities ?: 'N/A') . '</p>
                <p><strong>' . __('Pet Policy:', 'chicago-loft-search') . '</strong> ' . esc_html($loft->pet_policy ?: 'N/A') . '</p>
                </div></div>';
    } elseif ($loft->listing_type === 'agent') {
         // Similar logic for agent
         $html .= '<div class="listing-details-grid">
                <div class="listing-details-column"><h3>' . __('Agent Information', 'chicago-loft-search') . '</h3>
                <table class="listing-details-table">
                    <tr><th>' . __('Agent Name', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->agent_name ?: 'N/A') . '</td></tr>
                    <tr><th>' . __('Email', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->email ?: 'N/A') . '</td></tr>
                    <tr><th>' . __('Phone', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->phone ?: 'N/A') . '</td></tr>
                    <tr><th>' . __('License', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->license ?: 'N/A') . '</td></tr>
                    <tr><th>' . __('Specialty', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->specialty ?: 'N/A') . '</td></tr>
                </table></div><div class="listing-details-column"><h3>' . __('Areas of Expertise', 'chicago-loft-search') . '</h3>
                <p>' . esc_html($loft->areas_of_expertise ?: 'N/A') . '</p>
                </div></div>';
    }
            
    $html .= '<div class="listing-actions">
                <button class="listing-contact-button">' . __('Contact Agent', 'chicago-loft-search') . '</button>
                <button class="listing-schedule-button">' . __('Schedule Viewing', 'chicago-loft-search') . '</button>
            </div>
        </div>';
    
    wp_send_json_success(array('html' => $html));
}
add_action('wp_ajax_chicago_loft_search_get_loft_details', 'chicago_loft_search_get_loft_details');
add_action('wp_ajax_nopriv_chicago_loft_search_get_loft_details', 'chicago_loft_search_get_loft_details');

// --- CSV Import Helper Functions ---

/**
 * Helper to detect listing type from CSV headers.
 * Simplified to return a generic type as per new requirements.
 */
function _chicago_loft_search_detect_listing_type($headers) {
    // All CSV imports will now be treated as 'imported_csv_item'
    // The specific type detection logic is no longer needed for this simplified import.
    return 'imported_csv_item';
}

/**
 * Helper to generate preview data.
 * Now passes through original CSV data with an added MLS ID.
 */
function _chicago_loft_search_generate_preview_by_type($csv_row_assoc, $listing_type) {
    // $listing_type is now generic, e.g., 'imported_csv_item'
    
    // Try to find MLS ID from common column names
    $mls_id_keys = ['MLS ID', 'MLSID', 'mls_id', 'MLS Number', 'Listing ID', 'listing_id'];
    $mls_id = null;
    $identifier_for_mls_generation = '';

    foreach ($mls_id_keys as $key) {
        if (isset($csv_row_assoc[$key]) && !empty(trim($csv_row_assoc[$key]))) {
            $mls_id = trim($csv_row_assoc[$key]);
            break;
        }
    }
    
    // If MLS ID not found, generate one. Try to use Address or a unique part of the row.
    if (empty($mls_id)) {
        $address_keys = ['Address', 'Street Address', 'Property Address'];
        foreach($address_keys as $key) {
            if(isset($csv_row_assoc[$key]) && !empty(trim($csv_row_assoc[$key]))) {
                $identifier_for_mls_generation = trim($csv_row_assoc[$key]);
                break;
            }
        }
        // If address is also not found, use a hash of the row or a truly unique id
        if(empty($identifier_for_mls_generation)) {
            $identifier_for_mls_generation = md5(implode('|', $csv_row_assoc));
        }
        $mls_id = _chicago_loft_search_generate_mls_id_from_data($identifier_for_mls_generation);
    }

    // The preview item now includes the determined/generated MLS ID and the original CSV data.
    // The frontend JS will need to dynamically display all columns from original_csv_data.
    $preview_item = array(
        'mls_id' => $mls_id, // This is the key field for editing/confirming
        'listing_type' => $listing_type, // Generic type
        'original_data_preview' => $csv_row_assoc // Pass the full original row for JS to display
    );
    
    return $preview_item;
}

/**
 * Helper to get column index from header.
 */
function _chicago_loft_search_get_csv_column_index($column_name, $header_array) {
    $index = array_search($column_name, $header_array);
    return ($index !== false) ? $index : -1;
}

/**
 * Helper to generate a unique MLS ID.
 */
function _chicago_loft_search_generate_mls_id_from_data($identifier_string) {
    if (empty($identifier_string) || strlen($identifier_string) < 5) {
        return 'uid-' . substr(md5(uniqid(rand(), true)), 0, 12);
    }
    // Sanitize and create a somewhat readable prefix, then add a hash for uniqueness.
    // Using sanitize_title ensures it's URL-friendly.
    return sanitize_title(substr($identifier_string, 0, 50)) . '-' . substr(md5($identifier_string . uniqid(rand(), true)), 0, 8);
}

/**
 * Helper to extract neighborhood. (Retained for potential use with raw_data display, but not for direct DB column population)
 */
function _chicago_loft_search_extract_neighborhood_from_string($city_neighborhood_string, $city_val_from_csv = '') {
    if (empty($city_neighborhood_string)) return '';
    
    if (!empty($city_val_from_csv)) {
        $city_neighborhood_string = str_ireplace($city_val_from_csv, '', $city_neighborhood_string);
    }
    $common_cities = ['Chicago', 'Chicago IL'];
    foreach ($common_cities as $city) {
        $city_neighborhood_string = str_ireplace($city, '', $city_neighborhood_string);
    }
    
    $parts = explode(',', $city_neighborhood_string);
    $neighborhood = trim(end($parts)); 
    
    if (empty($neighborhood) && count($parts) > 0) { 
        $neighborhood = trim($parts[0]);
    }
    
    return $neighborhood;
}


/**
 * Helper to construct features string from CSV row. (Retained for raw_data display/ChatGPT context, not direct DB column)
 */
function _chicago_loft_search_construct_features_from_csv_row($csv_row_assoc) {
    $features_list = array();
    $feature_columns_map = array(
        'Exposed Brick' => 'Exposed Brick', 
        'Exposed Ductwork' => 'Exposed Ductwork',
        'Concrete Ceiling' => 'Concrete Ceiling', 
        'Concrete Posts' => 'Concrete Posts',
        'Timber Ceiling' => 'Timber Ceiling', 
        'Timber Posts' => 'Timber Posts',
        'Outdoor Space' => 'Outdoor Space', 
        'Deck' => 'Deck', 
        'Balcony' => 'Balcony', 
        'Roof Deck Shared' => 'Shared Roof Deck', 
        'Roof Deck Private' => 'Private Roof Deck', 
        'Walled Master BR' => 'Walled Master Bedroom', 
        'Walled 2nd Bedroom' => 'Walled 2nd Bedroom', 
        'Hardwood Floors' => 'Hardwood Floors', 
        'Oversized Windows' => 'Oversized Windows', 
        'Soft Loft' => 'Soft Loft Style', 
        'Hard Loft' => 'Hard Loft Style',
        'Fireplace' => 'Fireplace', 
        'InUnitWasherDryer' => 'In-Unit Washer/Dryer',
        'FHA Approved' => 'FHA Approved',
        'Pets Allowed' => 'Pets Allowed',
        'Parking' => 'Parking: %s',
        'ParkingType' => 'Parking Type: %s', 
        'PetsInfo' => 'Pet Info: %s', 
        'Amenities' => 'Amenities: %s', 
        'CeilingHeight' => 'Ceiling Height: %s ft',
        'Type' => 'Property Type: %s'
    );

    foreach ($feature_columns_map as $csv_col_name => $feature_text_format) {
        $csv_col_name_spaceless = str_replace(' ', '', $csv_col_name);
        $value = null;

        if (isset($csv_row_assoc[$csv_col_name]) && trim($csv_row_assoc[$csv_col_name]) !== '') {
            $value = trim($csv_row_assoc[$csv_col_name]);
        } elseif (isset($csv_row_assoc[$csv_col_name_spaceless]) && trim($csv_row_assoc[$csv_col_name_spaceless]) !== '') { 
            $value = trim($csv_row_assoc[$csv_col_name_spaceless]);
        }
        
        if ($value !== null) {
            $is_boolean_like_true = in_array(strtoupper($value), ['Y', 'YES', '1', 'TRUE', 'ON']);
            $is_boolean_like_false = in_array(strtoupper($value), ['N', 'NO', '0', 'FALSE', 'OFF']);

            if (strpos($feature_text_format, '%s') === false) { 
                if ($is_boolean_like_true) {
                    $features_list[] = $feature_text_format;
                }
            } else { 
                if ($is_boolean_like_true) { 
                     $features_list[] = str_replace(': %s', ' (Yes)', $feature_text_format);
                } elseif (!$is_boolean_like_false) { 
                    $features_list[] = sprintf($feature_text_format, $value);
                }
            }
        }
    }
    return implode(', ', array_filter(array_unique($features_list)));
}

/**
 * Helper to construct description from CSV row. (Retained for raw_data display/ChatGPT context)
 */
function _chicago_loft_search_construct_description_from_csv_row($csv_row_assoc, $constructed_address = '') {
    $description_parts = array();
    $desc_keys = ['Description', 'Remarks', 'PublicRemarks', 'AgentRemarks', 'Comments', 'PropertyDescription', 'Property Description'];
    foreach($desc_keys as $key) {
        if (!empty($csv_row_assoc[$key])) {
            $description_parts[] = trim($csv_row_assoc[$key]);
            $year_built_val = isset($csv_row_assoc['Year Built']) ? trim($csv_row_assoc['Year Built']) : (isset($csv_row_assoc['YearBuilt']) ? trim($csv_row_assoc['YearBuilt']) : '');
            if ($year_built_val) $description_parts[] = "Built in {$year_built_val}.";
            
            $features_summary = _chicago_loft_search_construct_features_from_csv_row($csv_row_assoc);
            if ($features_summary) $description_parts[] = "Features include: " . $features_summary . ".";
            return implode(' ', $description_parts);
        }
    }

    if (!empty($constructed_address)) {
         $description_parts[] = rtrim($constructed_address, ', ') . ".";
    }

    $building_name = isset($csv_row_assoc['Building Name']) ? trim($csv_row_assoc['Building Name']) : (isset($csv_row_assoc['BuildingName']) ? trim($csv_row_assoc['BuildingName']) : '');
    if ($building_name) $description_parts[] = "Located in " . $building_name . ".";

    $property_type = isset($csv_row_assoc['Type']) ? trim($csv_row_assoc['Type']) : '';
    if ($property_type) $description_parts[] = "This " . strtolower($property_type) . " property";
    else  $description_parts[] = "This property";

    $year_built_val = isset($csv_row_assoc['Year Built']) ? trim($csv_row_assoc['Year Built']) : (isset($csv_row_assoc['YearBuilt']) ? trim($csv_row_assoc['YearBuilt']) : '');
    if ($year_built_val) $description_parts[] = "was built in " . $year_built_val . ".";
    else $description_parts[] = "offers unique characteristics.";


    $stories_val = isset($csv_row_assoc['Stories']) ? trim($csv_row_assoc['Stories']) : '';
    if ($stories_val) $description_parts[] = "The building has " . $stories_val . " stories.";
    
    $units_val = isset($csv_row_assoc['Units']) ? trim($csv_row_assoc['Units']) : '';
     if ($units_val && $property_type !== 'Single Family') { 
        $unit_no_val = isset($csv_row_assoc['Unit Number']) ? trim($csv_row_assoc['Unit Number']) : (isset($csv_row_assoc['Unit No']) ? trim($csv_row_assoc['Unit No']) : '');
        if($unit_no_val) {
            $description_parts[] = "Unit #".$unit_no_val.".";
        }
    }
    
    $features_summary = _chicago_loft_search_construct_features_from_csv_row($csv_row_assoc);
    if ($features_summary) $description_parts[] = "Key features include: " . $features_summary . ".";
    
    if (empty($description_parts)) $description_parts[] = "Charming loft property in Chicago."; 
    
    return implode(' ', array_filter($description_parts));
}


/**
 * Helper to prepare data for database insertion, focusing on raw_data.
 */
function _chicago_loft_search_prepare_data_for_db($item_preview_from_js, $original_csv_row_assoc, $base_data) {
    // $item_preview_from_js contains user-editable fields like MLS ID from JS preview
    // $original_csv_row_assoc is the full original CSV row
    // $base_data contains status, dates, generic listing_type
    
    $data_for_db = array_merge($base_data, array(
        'mls_id'       => !empty($item_preview_from_js['mls_id']) ? sanitize_text_field($item_preview_from_js['mls_id']) : $base_data['mls_id'],
        'raw_data'     => json_encode($original_csv_row_assoc),
        'listing_type' => 'imported_csv_item', // Standardized type for these imports
        // Set all other specific columns to NULL, as their data is now in raw_data
        'address' => null, 'neighborhood' => null, 'price' => null, 'bedrooms' => null, 'bathrooms' => null,
        'square_feet' => null, 'year_built' => null, 'features' => null, 'description' => null, 'image_urls' => null,
        'building_name' => null, 'units' => null, 'floors' => null, 'hoa_fee' => null, 'pet_policy' => null, 'amenities' => null,
        'agent_name' => null, 'email' => null, 'phone' => null, 'bio' => null, 'areas_of_expertise' => null, 'specialty' => null, 'license' => null,
    ));
    
    return $data_for_db;
}


// --- AJAX Handlers for CSV Import ---

/**
 * AJAX handler for parsing CSV and returning preview data.
 * Sends all headers and raw row data to the frontend.
 */
function chicago_loft_search_parse_csv_preview() {
    check_ajax_referer('chicago_loft_search_import_nonce', 'nonce'); 
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'chicago-loft-search')));
    }

    if (!isset($_FILES['mls_csv_file']) || $_FILES['mls_csv_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(array('message' => __('File upload error.', 'chicago-loft-search')));
    }

    $file_path = $_FILES['mls_csv_file']['tmp_name'];
    $preview_data_for_js = array(); // Data structured for JS preview table (includes generated MLS ID)
    $raw_data_for_batch_import = array(); // Data structured for batch import (original CSV row + MLS ID)
    $header = null;
    $listing_type = _chicago_loft_search_detect_listing_type(array()); // Get the generic type

    if (($handle = fopen($file_path, 'r')) !== false) {
        $row_count = 0;
        while (($row_values = fgetcsv($handle)) !== false) {
            if (!$header) {
                $header = array_map('trim', $row_values);
                // No specific listing type detection from headers anymore, type is generic.
                continue;
            }
            if (count($header) !== count($row_values)) { 
                continue; // Skip malformed rows
            }
            
            $csv_row_assoc = array(); // Original CSV row as associative array
            foreach ($header as $i => $col_name) {
                $csv_row_assoc[$col_name] = isset($row_values[$i]) ? $row_values[$i] : null;
            }
            
            // Generate preview item (this now mainly adds an MLS ID to the original data)
            $preview_item_struct = _chicago_loft_search_generate_preview_by_type($csv_row_assoc, $listing_type);
            $preview_data_for_js[] = $preview_item_struct; // For JS to build the dynamic table

            // Data for batch import needs the original CSV row and the (potentially edited) MLS ID
            $raw_data_for_batch_import[] = array(
                'preview_item_for_js' => $preview_item_struct, // Contains mls_id that JS can edit
                'original_csv_data'   => $csv_row_assoc,       // The full original row
                'listing_type'        => $listing_type         // Generic type
            );

            $row_count++;
            if ($row_count >= 200) break; // Limit preview size for performance
        }
        fclose($handle);
    } else {
        wp_send_json_error(array('message' => __('Could not open CSV file.', 'chicago-loft-search')));
    }

    if (empty($preview_data_for_js)) {
        wp_send_json_error(array('message' => __('No data found in CSV or CSV format error (ensure header row exists).', 'chicago-loft-search')));
    }

    wp_send_json_success(array(
        'headers' => $header, // Send original headers to JS
        'preview_data' => $preview_data_for_js,  // Data for JS preview table (original_data_preview + mls_id)
        'raw_data_for_import' => $raw_data_for_batch_import, // Data for the actual import process
        'listing_type' => $listing_type // Generic type
    ));
}
add_action('wp_ajax_chicago_loft_search_parse_csv_preview', 'chicago_loft_search_parse_csv_preview');

/**
 * AJAX handler for importing a batch of listings.
 * Stores all CSV data into raw_data field.
 */
function chicago_loft_search_import_listings_batch() {
    check_ajax_referer('chicago_loft_search_import_nonce', 'nonce'); 
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'chicago-loft-search')));
    }

    if (!isset($_POST['listings_to_import'])) { 
        wp_send_json_error(array('message' => __('No listings data received.', 'chicago-loft-search')));
    }

    $listings_json = stripslashes($_POST['listings_to_import']);
    $listings_batch_from_js = json_decode($listings_json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($listings_batch_from_js)) {
        wp_send_json_error(array('message' => __('Invalid listings data format: ' . json_last_error_msg(), 'chicago-loft-search')));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $batch_results = array();
    
    $overall_listing_type = isset($_POST['listing_type']) ? sanitize_text_field($_POST['listing_type']) : 'imported_csv_item'; // Use generic type

    foreach ($listings_batch_from_js as $js_item) {
        // $js_item contains 'preview_item_for_js' (with potentially edited mls_id) 
        // and 'original_csv_data' (the full original CSV row)
        $item_preview_from_js = isset($js_item['preview_item_for_js']) ? $js_item['preview_item_for_js'] : array();
        $original_csv_row_assoc = isset($js_item['original_csv_data']) && is_array($js_item['original_csv_data']) ? $js_item['original_csv_data'] : array();
        
        if (empty($original_csv_row_assoc)) {
            $batch_results[] = array('success' => false, 'message' => __('Skipped item due to missing original CSV data.', 'chicago-loft-search'));
            continue;
        }

        // Determine MLS ID: use from preview if edited, else from original preview generation, else generate new
        $mls_id_from_preview = isset($item_preview_from_js['mls_id']) ? sanitize_text_field($item_preview_from_js['mls_id']) : null;
        if(empty($mls_id_from_preview)) { // Fallback if JS didn't send it or it was empty
            $temp_preview_for_id = _chicago_loft_search_generate_preview_by_type($original_csv_row_assoc, $overall_listing_type);
            $mls_id_from_preview = $temp_preview_for_id['mls_id'];
        }
        
        $base_data = array(
            'mls_id'       => $mls_id_from_preview,
            'status'       => sanitize_text_field(isset($item_preview_from_js['status']) ? $item_preview_from_js['status'] : 'active'), // Allow status override from JS if any
            'date_updated' => current_time('mysql'),
            'listing_type' => $overall_listing_type, // Use the generic type
        );
        
        // Prepare data for DB, focusing on raw_data
        $data_for_db = _chicago_loft_search_prepare_data_for_db($item_preview_from_js, $original_csv_row_assoc, $base_data);
                
        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE mls_id = %s", $data_for_db['mls_id']));

        if (!$existing_id) {
            $data_for_db['date_added'] = current_time('mysql');
        }

        // Dynamically build format array based on the actual keys in $data_for_db
        $current_format_array = array();
        foreach ($data_for_db as $key => $value) { 
             if (is_int($value)) $current_format_array[$key] = '%d';
             elseif (is_float($value)) $current_format_array[$key] = '%f';
             else $current_format_array[$key] = '%s'; // Handles strings, nulls, and JSON strings
        }
        
        $ordered_format_array = array_values(array_intersect_key($current_format_array, $data_for_db));


        if ($existing_id) {
            $result = $wpdb->update($table_name, $data_for_db, array('id' => $existing_id), $ordered_format_array, array('%d'));
            if ($result !== false) {
                $batch_results[] = array('success' => true, 'message' => sprintf(__('Updated item with MLS ID %s', 'chicago-loft-search'), $data_for_db['mls_id']));
            } else {
                $batch_results[] = array('success' => false, 'message' => sprintf(__('Error updating item with MLS ID %s. DB Error: %s', 'chicago-loft-search'), $data_for_db['mls_id'], $wpdb->last_error));
            }
        } else {
            $result = $wpdb->insert($table_name, $data_for_db, $ordered_format_array);
            if ($result) {
                $batch_results[] = array('success' => true, 'message' => sprintf(__('Imported item with MLS ID %s', 'chicago-loft-search'), $data_for_db['mls_id']));
            } else {
                $batch_results[] = array('success' => false, 'message' => sprintf(__('Error importing item with MLS ID %s. DB Error: %s', 'chicago-loft-search'), $data_for_db['mls_id'], $wpdb->last_error));
            }
        }
    }
    
    if (!empty($batch_results)) {
        $options = get_option('chicago_loft_search_options');
        $options['last_sync_date'] = current_time('mysql');
        update_option('chicago_loft_search_options', $options);
    }

    wp_send_json_success(array('results' => $batch_results, 'processed_listing_type' => $overall_listing_type));
}
add_action('wp_ajax_chicago_loft_search_import_listings_batch', 'chicago_loft_search_import_listings_batch');

/**
 * AJAX handler for deleting all listings.
 */
function chicago_loft_search_delete_all_listings_ajax() {
    check_ajax_referer('chicago_loft_search_delete_all_listings', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'chicago-loft-search')));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->query("TRUNCATE TABLE $table_name"); 

    if ($result !== false) {
        $options = get_option('chicago_loft_search_options');
        $options['last_sync_date'] = '';
        update_option('chicago_loft_search_options', $options);
        wp_send_json_success(array('message' => __('All imported data has been deleted.', 'chicago-loft-search')));
    } else {
        wp_send_json_error(array('message' => __('Error deleting data. Please try again.', 'chicago-loft-search')));
    }
}
add_action('wp_ajax_chicago_loft_search_delete_all_listings', 'chicago_loft_search_delete_all_listings_ajax');

// --- Direct CSV Query Functions (New Feature) ---

/**
 * Create a directory for CSV storage if it doesn't exist.
 */
function chicago_loft_search_create_csv_storage() {
    $upload_dir = wp_upload_dir();
    $csv_dir = $upload_dir['basedir'] . '/csv-documents';
    if (!file_exists($csv_dir)) {
        wp_mkdir_p($csv_dir);
        if (!file_exists($csv_dir . '/index.php')) {
            @file_put_contents($csv_dir . '/index.php', '<?php // Silence is golden');
        }
    }
    return $csv_dir;
}

/**
 * Admin page to manage CSV files.
 */
function chicago_loft_search_csv_manager_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    settings_errors('csv_manager'); 
    
    $upload_dir = wp_upload_dir();
    $csv_dir = chicago_loft_search_create_csv_storage(); 
    $files = glob($csv_dir . '/*.csv');
    
    echo '<div class="wrap">';
    echo '<h1>' . __('Manage CSV Documents', 'chicago-loft-search') . '</h1>';
    echo '<p>' . __('Upload CSV files here to make their content available for direct querying by ChatGPT.', 'chicago-loft-search') . '</p>';
    
    echo '<h2>' . __('Upload New CSV Document', 'chicago-loft-search') . '</h2>';
    echo '<form method="post" enctype="multipart/form-data" style="margin-bottom: 20px;">';
    echo '<input type="file" name="csv_document" accept=".csv" required>';
    echo '<input type="submit" name="upload_csv" value="' . __('Upload Document', 'chicago-loft-search') . '" class="button button-primary">';
    wp_nonce_field('upload_csv_nonce', 'upload_csv_nonce_field'); 
    echo '</form>';
    
    echo '<h2>' . __('Available CSV Documents', 'chicago-loft-search') . '</h2>';
    if (!empty($files)) {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . __('File Name', 'chicago-loft-search') . '</th><th>' . __('Size', 'chicago-loft-search') . '</th><th>' . __('Uploaded', 'chicago-loft-search') . '</th><th>' . __('Actions', 'chicago-loft-search') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($files as $file) {
            $filename = basename($file);
            $file_url = $upload_dir['baseurl'] . '/csv-documents/' . $filename;
            $file_size = size_format(filesize($file));
            $file_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($file));
            
            echo '<tr>';
            echo '<td>' . esc_html($filename) . '</td>';
            echo '<td>' . esc_html($file_size) . '</td>';
            echo '<td>' . esc_html($file_time) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($file_url) . '" class="button" download>' . __('Download', 'chicago-loft-search') . '</a> ';
            echo '<form method="post" style="display:inline;">';
            echo '<input type="hidden" name="delete_csv_file" value="' . esc_attr($filename) . '">'; 
            wp_nonce_field('delete_csv_nonce', 'delete_csv_nonce_field'); 
            echo '<input type="submit" class="button button-link-delete" value="' . __('Delete', 'chicago-loft-search') . '" onclick="return confirm(\'' . esc_js(sprintf(__('Are you sure you want to delete %s?', 'chicago-loft-search'), $filename)) . '\');">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . __('No CSV documents found. Upload one using the form above.', 'chicago-loft-search') . '</p>';
    }
    
    echo '</div>'; 
}

/**
 * Process CSV uploads and deletions.
 */
function chicago_loft_search_process_csv_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_POST['upload_csv']) && isset($_FILES['csv_document']) && isset($_POST['upload_csv_nonce_field'])) {
        if (!wp_verify_nonce($_POST['upload_csv_nonce_field'], 'upload_csv_nonce')) {
            add_settings_error('csv_manager', 'csv_nonce_fail', __('Security check failed for upload.', 'chicago-loft-search'), 'error');
            return;
        }
        
        $csv_dir = chicago_loft_search_create_csv_storage();
        $file = $_FILES['csv_document'];
        
        $upload_error = '';
        if ($file['error'] !== UPLOAD_ERR_OK) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $upload_error = 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $upload_error = 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $upload_error = 'The uploaded file was only partially uploaded.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $upload_error = 'No file was uploaded.';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $upload_error = 'Missing a temporary folder.';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $upload_error = 'Failed to write file to disk.';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $upload_error = 'A PHP extension stopped the file upload.';
                    break;
                default:
                    $upload_error = 'Unknown upload error.';
            }
            add_settings_error('csv_manager', 'csv_upload_failed', 'Upload failed: ' . $upload_error, 'error');
            return;
        }
        
        $filename = sanitize_file_name($file['name']);
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if ($file_extension !== 'csv') {
            $fh = fopen($file['tmp_name'], 'r');
            if ($fh) {
                $first_line = fgets($fh);
                fclose($fh);
                if (strpos($first_line, ',') !== false || strpos($first_line, "\t") !== false) {
                    $filename = pathinfo($filename, PATHINFO_FILENAME) . '.csv';
                    $file_extension = 'csv'; 
                } else {
                    add_settings_error('csv_manager', 'csv_upload_failed', 'File does not appear to be a valid CSV. Please use a .csv file with comma-separated values.', 'error');
                    return;
                }
            } else {
                add_settings_error('csv_manager', 'csv_upload_failed', 'Could not read file to verify format. Please ensure it is a valid CSV file.', 'error');
                return;
            }
        }
        
        $mime_types = array(
            'text/csv', 
            'text/plain', 
            'application/csv', 
            'text/comma-separated-values', 
            'application/excel', 
            'application/vnd.ms-excel', 
            'application/vnd.msexcel',
            'text/anytext',
            'application/octet-stream', 
        );
        
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime_type = $finfo ? finfo_file($finfo, $file['tmp_name']) : $file['type'];
        if ($finfo) finfo_close($finfo);
        
        if ($file_extension === 'csv') {
            $valid_mime = in_array(strtolower($mime_type), array_map('strtolower', $mime_types)) || 
                           strpos(strtolower($mime_type), 'text/') === 0 || 
                           strpos(strtolower($mime_type), 'application/csv') === 0 || 
                           strpos(strtolower($mime_type), 'application/vnd.ms-excel') === 0; 
                           
            if ($valid_mime) {
                $target_path = $csv_dir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    add_settings_error('csv_manager', 'csv_uploaded', __('CSV document uploaded successfully.', 'chicago-loft-search'), 'updated');
                } else {
                    add_settings_error('csv_manager', 'csv_upload_failed', __('Failed to move uploaded file to target directory.', 'chicago-loft-search'), 'error');
                }
            } else {
                add_settings_error('csv_manager', 'csv_upload_failed', __('Invalid file type. Only CSV files are allowed. (Detected MIME type: ', 'chicago-loft-search') . esc_html($mime_type) . ')', 'error');
            }
        } else {
            add_settings_error('csv_manager', 'csv_upload_failed', __('Invalid file extension. Only .csv files are allowed.', 'chicago-loft-search'), 'error');
        }
    }
    
    if (isset($_POST['delete_csv_file']) && isset($_POST['delete_csv_nonce_field'])) {
         if (!wp_verify_nonce($_POST['delete_csv_nonce_field'], 'delete_csv_nonce')) {
            add_settings_error('csv_manager', 'csv_nonce_fail', __('Security check failed for deletion.', 'chicago-loft-search'), 'error');
            return;
        }
        
        $csv_dir = chicago_loft_search_create_csv_storage();
        $filename = sanitize_file_name($_POST['delete_csv_file']);
        $file_path = $csv_dir . '/' . $filename;
        
        if (file_exists($file_path)) {
            if (unlink($file_path)) {
                add_settings_error('csv_manager', 'csv_deleted', __('CSV document deleted successfully.', 'chicago-loft-search'), 'updated');
            } else {
                add_settings_error('csv_manager', 'csv_delete_failed', __('Failed to delete CSV document. Check file permissions.', 'chicago-loft-search'), 'error');
            }
        } else {
            add_settings_error('csv_manager', 'csv_file_not_found', __('File not found for deletion.', 'chicago-loft-search'), 'error');
        }
    }
}
add_action('admin_init', 'chicago_loft_search_process_csv_actions');


/**
 * Original function to query CSV files directly.
 * Reads all CSVs from the designated folder and returns rows that contain query keywords
 * as a single concatenated string. This is the version that will be used.
 *
 * @param string $query The user's search query.
 * @param int $max_chars_for_csv_data Approximate character limit for the CSV data payload.
 * @return string|array A single string of concatenated relevant CSV data, or an empty array if dir not found.
 */
function chicago_loft_search_query_csv_documents($query, $max_chars_for_csv_data = 15000) { 
    $upload_dir = wp_upload_dir();
    $csv_dir_path = $upload_dir['basedir'] . '/csv-documents';

    if (!file_exists($csv_dir_path) || !is_dir($csv_dir_path)) {
        return []; 
    }

    $files = glob($csv_dir_path . '/*.csv');
    if (empty($files)) {
        return ""; 
    }

    $keywords = preg_split('/\\s+/', strtolower(trim($query)));
    $keywords = array_filter($keywords, function($kw) {
        return strlen(trim($kw)) > 2; 
    });

    if (empty($keywords)) {
        return ""; 
    }
    
    $all_csv_content_for_gpt = "";

    foreach ($files as $file_path) {
        if (strlen($all_csv_content_for_gpt) >= $max_chars_for_csv_data) break;

        if (($handle = fopen($file_path, 'r')) !== false) {
            $file_content_for_this_file = "Content from file: " . basename($file_path) . "\n";
            $header = fgetcsv($handle);
            if (!$header || empty(array_filter($header, 'strlen'))) { 
                fclose($handle);
                continue; 
            }
            $file_content_for_this_file .= implode(", ", $header) . "\n";
            $found_in_this_file = false;

            while (($row_values = fgetcsv($handle)) !== false) {
                if (strlen($all_csv_content_for_gpt . $file_content_for_this_file) >= $max_chars_for_csv_data) break;

                if (count($header) !== count($row_values)) {
                    continue; 
                }
                if (empty(array_filter($row_values, 'strlen'))) {
                    continue;
                }

                $row_text_lower = strtolower(implode(' ', $row_values));
                $matches_this_row = false;
                foreach ($keywords as $keyword) {
                    if (stripos($row_text_lower, $keyword) !== false) {
                        $matches_this_row = true;
                        break;
                    }
                }

                if ($matches_this_row) {
                    $row_string = implode(", ", $row_values) . "\n";
                    if (strlen($all_csv_content_for_gpt . $file_content_for_this_file . $row_string) <= $max_chars_for_csv_data) {
                        $file_content_for_this_file .= $row_string;
                        $found_in_this_file = true;
                    } else {
                        // Not enough space for this row, stop processing this file
                        break; 
                    }
                }
            }
            fclose($handle);

            if ($found_in_this_file) {
                 if (strlen($all_csv_content_for_gpt . $file_content_for_this_file . "\n---\n") <= $max_chars_for_csv_data) {
                    $all_csv_content_for_gpt .= $file_content_for_this_file . "\n---\n";
                 } else {
                     $all_csv_content_for_gpt .= substr($file_content_for_this_file, 0, $max_chars_for_csv_data - strlen($all_csv_content_for_gpt) - strlen("\n[DATA TRUNCATED]")) . "\n[DATA TRUNCATED]";
                     break;
                 }
            }
        } 
    }
    
    if (strlen($all_csv_content_for_gpt) > $max_chars_for_csv_data) {
        $all_csv_content_for_gpt = substr($all_csv_content_for_gpt, 0, $max_chars_for_csv_data - strlen("\n[DATA TRUNCATED]")) . "\n[DATA TRUNCATED]";
    }
    
    return $all_csv_content_for_gpt;
}

// The optimized function previously here has been renamed to chicago_loft_search_query_csv_documents
// and integrated above, replacing the older version of that function.
// No fallback is needed as the primary function itself is now the optimized one.

?>
