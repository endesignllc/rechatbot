<?php
/**
 * Plugin Name: Chicago Loft Search
 * Plugin URI: https://example.com/chicago-loft-search
 * Description: A secure WordPress plugin that allows users to search Chicago loft listings using ChatGPT-powered natural language queries.
 * Version: 1.0.3
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
define('CHICAGO_LOFT_SEARCH_VERSION', '1.0.3');
define('CHICAGO_LOFT_SEARCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHICAGO_LOFT_SEARCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHICAGO_LOFT_SEARCH_PLUGIN_BASENAME', plugin_basename(__FILE__));

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
    delete_transient('chicago_loft_search_api_check'); // Example specific transient

    // Remove plugin admin menu entries if they were added with add_menu_page
    // Note: Submenu pages are removed automatically when the parent is removed.
    // If settings pages were added under existing menus (e.g., 'options-general.php'),
    // they are typically handled by WordPress during deactivation/uninstallation.
    remove_menu_page('chicago-loft-search-dashboard');
    
    // Attempt to clean up admin menu hooks more broadly if needed, though generally not required for standard deactivation.
    // This is more aggressive and might be overkill or have unintended side effects if not carefully managed.
    // Consider if this is truly necessary or if standard hook removal during plugin load/unload is sufficient.
    // global $wp_filter;
    // if (is_array($wp_filter)) {
    //     foreach ($wp_filter as $tag => $hook_details) {
    //         if (is_string($tag) && strpos($tag, 'chicago_loft_search') !== false) {
    //             // This is a very broad removal. Be cautious.
    //             // It might be better to specifically unhook functions if known.
    //             // remove_all_filters($tag); 
    //         }
    //     }
    // }
    
    // Flush rewrite rules to remove any custom endpoints if the plugin registered any
    flush_rewrite_rules();
}

/**
 * The code that runs during plugin uninstallation.
 * This function is registered with register_uninstall_hook.
 * WordPress will prioritize executing an uninstall.php file if present in the plugin's root.
 * The detailed cleanup logic should be in uninstall.php.
 */
function uninstall_chicago_loft_search() {
    // This function is a fallback if uninstall.php is not present or
    // if there's specific logic that must run from the main plugin file context
    // during uninstallation (which is rare).
    // For thorough cleanup, ensure uninstall.php contains all necessary actions.

    // If uninstall not called from WordPress, exit
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        exit;
    }

    // Most cleanup logic (dropping tables, deleting options) should be in uninstall.php.
    // This function can remain simple or be a safeguard.
}

// Register activation, deactivation, and uninstall hooks
register_activation_hook(__FILE__, 'activate_chicago_loft_search');
register_deactivation_hook(__FILE__, 'deactivate_chicago_loft_search');
register_uninstall_hook(__FILE__, 'uninstall_chicago_loft_search'); // Points to the function in this file. WordPress will use uninstall.php if it exists.

/**
 * Create necessary database tables (defines the latest schema)
 */
function chicago_loft_search_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Table for storing listings (lofts, buildings, agents)
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        mls_id varchar(50) NOT NULL,
        address varchar(255) DEFAULT NULL, /* Made nullable for agents */
        neighborhood varchar(100) DEFAULT NULL,
        price decimal(12,2) DEFAULT NULL,
        bedrooms int(11) DEFAULT NULL,
        bathrooms decimal(3,1) DEFAULT NULL,
        square_feet int(11) DEFAULT NULL,
        year_built int(4) DEFAULT NULL,
        features text DEFAULT NULL,
        description longtext DEFAULT NULL,
        image_urls longtext DEFAULT NULL, /* Can store multiple URLs for lofts/buildings, or single for agent */
        status varchar(50) DEFAULT 'active',
        raw_data longtext DEFAULT NULL,
        date_added datetime NOT NULL,
        date_updated datetime NOT NULL,
        listing_type varchar(20) DEFAULT 'loft',
        
        /* Building-specific fields */
        building_name varchar(255) DEFAULT NULL,
        units int(11) DEFAULT NULL,
        floors int(11) DEFAULT NULL,
        hoa_fee varchar(50) DEFAULT NULL,
        pet_policy text DEFAULT NULL,
        amenities text DEFAULT NULL,
        
        /* Agent-specific fields */
        agent_name varchar(255) DEFAULT NULL,
        email varchar(100) DEFAULT NULL,
        phone varchar(50) DEFAULT NULL,
        bio text DEFAULT NULL, /* Can be stored in description or this specific field */
        areas_of_expertise text DEFAULT NULL,
        specialty varchar(100) DEFAULT NULL,
        license varchar(50) DEFAULT NULL,
        
        PRIMARY KEY  (id),
        UNIQUE KEY mls_id (mls_id), /* Ensure this is unique across all types */
        KEY neighborhood (neighborhood),
        KEY price (price),
        KEY bedrooms (bedrooms),
        KEY bathrooms (bathrooms),
        KEY square_feet (square_feet),
        KEY year_built (year_built),
        KEY status (status),
        KEY listing_type (listing_type)
    ) $charset_collate;";
    
    // Table for tracking usage
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
    
    // Table for logging queries
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
                // Check if column exists before trying to modify (dbDelta might handle this, but explicit check is safer for MODIFY)
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
        // No update_option here, will be handled by the next version or final update
        // update_option('chicago_loft_search_db_version', '1.0.2'); 
        // $current_db_version = '1.0.2'; // Update for further checks in same run if any
    }

    if (version_compare($current_db_version, '1.0.3', '<')) { // Upgrade to 1.0.3 schema for multiple listing types
        global $wpdb;
        $table_name = $wpdb->prefix . 'chicago_loft_listings';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) == $table_name) {
            // Check if listing_type column exists
            $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", 'listing_type'));
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN `listing_type` varchar(20) DEFAULT 'loft'");
            }
            
            // Add building-specific columns
            $building_columns = array(
                'building_name' => 'varchar(255) DEFAULT NULL',
                'units' => 'int(11) DEFAULT NULL',
                'floors' => 'int(11) DEFAULT NULL',
                'hoa_fee' => 'varchar(50) DEFAULT NULL',
                'pet_policy' => 'text DEFAULT NULL',
                'amenities' => 'text DEFAULT NULL'
            );
            
            // Add agent-specific columns
            $agent_columns = array(
                'agent_name' => 'varchar(255) DEFAULT NULL',
                'email' => 'varchar(100) DEFAULT NULL',
                'phone' => 'varchar(50) DEFAULT NULL',
                'bio' => 'text DEFAULT NULL',
                'areas_of_expertise' => 'text DEFAULT NULL',
                'specialty' => 'varchar(100) DEFAULT NULL',
                'license' => 'varchar(50) DEFAULT NULL'
            );
            
            // Add all new columns
            $all_new_columns = array_merge($building_columns, $agent_columns);
            foreach ($all_new_columns as $column_name => $column_definition) {
                $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", $column_name));
                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE $table_name ADD COLUMN `$column_name` $column_definition");
                }
            }
            
            // Add index for listing_type if it doesn't exist
            $index_exists = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM `$table_name` WHERE Key_name = %s", 'listing_type'));
            if (empty($index_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD INDEX listing_type (listing_type)");
            }

            // Make 'address' column nullable if it's not already (for agent type)
            $address_col_details = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", 'address'));
            if ($address_col_details && strtoupper($address_col_details->Null) === 'NO') {
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `address` varchar(255) DEFAULT NULL");
            }
        }
        // No update_option here, will be handled by the final update_option in activate_chicago_loft_search
    }
    // After all specific version upgrades, if the current plugin version is higher than stored DB version, update it.
    // This is usually handled by the activation hook which calls this then sets the option.
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
        'loft-search_page_chicago-loft-search-csv' // Added for CSV manager page
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
        'import_nonce' => wp_create_nonce('chicago_loft_search_import_settings'), // Used for settings import
        'csv_import_nonce' => wp_create_nonce('chicago_loft_search_import_nonce'), // Specific nonce for CSV data import
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
        __('Listings Data', 'chicago-loft-search'), // Changed from "Loft Listings"
        __('Listings Data', 'chicago-loft-search'), // Changed from "Loft Listings"
        'manage_options',
        'chicago-loft-search-listings',
        'chicago_loft_search_listings_page'
    );
    
    add_submenu_page(
        'chicago-loft-search-dashboard',
        __('Import Data', 'chicago-loft-search'), // Changed from "Import MLS Data"
        __('Import Data', 'chicago-loft-search'), // Changed from "Import MLS Data"
        'manage_options',
        'chicago-loft-search-import',
        'chicago_loft_search_import_page'
    );

    // CSV Manager Page (as per new instructions for direct CSV query)
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

// Ensure WP_List_Table class is available
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Listings Data Table Class. (Generalized from Loft Listings)
 */
class Chicago_Loft_Listings_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => __('Listing Data', 'chicago-loft-search'), // Generalized
            'plural'   => __('Listings Data', 'chicago-loft-search'), // Generalized
            'ajax'     => false
        ));
    }

    public function get_columns() {
        // Basic columns, can be extended or modified based on filters for type
        return array(
            'cb'           => '<input type="checkbox" />', 
            'mls_id'       => __('ID / MLS ID', 'chicago-loft-search'),
            'listing_type' => __('Type', 'chicago-loft-search'),
            'primary_identifier' => __('Name / Address', 'chicago-loft-search'), // Combined for different types
            'neighborhood' => __('Neighborhood', 'chicago-loft-search'),
            'status'       => __('Status', 'chicago-loft-search'),
            'date_updated' => __('Last Updated', 'chicago-loft-search'),
        );
    }

    public function get_sortable_columns() {
        return array(
            'mls_id'       => array('mls_id', false),
            'listing_type' => array('listing_type', false),
            'primary_identifier' => array('address', false), // Sort by address as a proxy
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
        switch ($item['listing_type']) {
            case 'building':
                $identifier = $item['building_name'] ?: $item['address'];
                break;
            case 'agent':
                $identifier = $item['agent_name'];
                break;
            case 'loft':
            default:
                $identifier = $item['address'];
                break;
        }
        return esc_html($identifier ?: __('N/A', 'chicago-loft-search'));
    }
    
    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'mls_id':
            case 'listing_type':
            case 'neighborhood':
            case 'status':
                return esc_html($item[$column_name] ?: __('N/A', 'chicago-loft-search'));
            case 'date_updated':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name]));
            default:
                // For other specific columns if added, or just print_r for debug
                return isset($item[$column_name]) ? esc_html($item[$column_name]) : print_r($item, true); 
        }
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'chicago_loft_listings';

        $per_page = 20;
        $current_page = $this->get_pagenum();
        
        $where_clauses = array();
        $query_params = array();

        // Filter by listing type
        $filter_listing_type = isset($_REQUEST['listing_type_filter']) ? sanitize_text_field($_REQUEST['listing_type_filter']) : '';
        if (!empty($filter_listing_type)) {
            $where_clauses[] = "listing_type = %s";
            $query_params[] = $filter_listing_type;
        }
        
        // Search
        if (!empty($_REQUEST['s'])) {
            $search_term = '%' . $wpdb->esc_like(sanitize_text_field($_REQUEST['s'])) . '%';
            $search_fields = "(mls_id LIKE %s OR address LIKE %s OR neighborhood LIKE %s OR building_name LIKE %s OR agent_name LIKE %s OR description LIKE %s)";
            $where_clauses[] = $wpdb->prepare($search_fields, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term);
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
        // Adjust orderby for combined column
        if ($orderby === 'primary_identifier') $orderby = 'address'; 
        $order = (!empty($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), array('ASC', 'DESC'))) ? $_REQUEST['order'] : 'DESC';
        
        $query = "SELECT id, mls_id, listing_type, address, building_name, agent_name, neighborhood, status, date_updated FROM $table_name" . $sql_where;
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
                    <option value="loft" <?php selected($current_filter, 'loft'); ?>><?php _e('Loft', 'chicago-loft-search'); ?></option>
                    <option value="building" <?php selected($current_filter, 'building'); ?>><?php _e('Building', 'chicago-loft-search'); ?></option>
                    <option value="agent" <?php selected($current_filter, 'agent'); ?>><?php _e('Agent', 'chicago-loft-search'); ?></option>
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
    echo '<h1>' . __('Manage Listings Data', 'chicago-loft-search') . '</h1>'; // Generalized
    
    settings_errors('chicago_loft_search_listings_messages');

    echo '<div id="loft-listings-summary" style="margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border: 1px solid #e3e3e3;">';
    echo '<h3>' . __('Summary', 'chicago-loft-search') . '</h3>';
    echo '<p><strong>' . __('Total Items Imported:', 'chicago-loft-search') . '</strong> ' . esc_html($total_listings) . '</p>'; // Generalized
    if (!empty($last_sync_date)) {
        echo '<p><strong>' . __('Last Data Sync/Import:', 'chicago-loft-search') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync_date)) . '</p>'; // Generalized
    } else {
        echo '<p><strong>' . __('Last Data Sync/Import:', 'chicago-loft-search') . '</strong> ' . __('Never synced or no data imported yet.', 'chicago-loft-search') . '</p>';
    }
     echo '<p><button type="button" id="delete-all-listings-button" class="button button-danger">' . __('Delete All Imported Data', 'chicago-loft-search') . '</button></p>'; // Generalized
    echo '</div>';

    $listings_table = new Chicago_Loft_Listings_Table();
    $listings_table->prepare_items();
    
    echo '<form method="post">'; 
    wp_nonce_field( 'bulk-' . $listings_table->_args['plural'] );
    $listings_table->search_box(__('Search Data', 'chicago-loft-search'), 'chicago-loft-search'); // Generalized
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

    // --- Start: Optimized Direct CSV Query Logic ---
    $max_chars_for_csv_context = apply_filters('chicago_loft_search_csv_context_max_chars', 15000);
    $csv_data_for_gpt_structured = chicago_loft_search_query_csv_documents($query, $max_chars_for_csv_context);
    
    $final_system_prompt = $system_prompt_template;
    $data_context_for_api_string = "";

    if (!empty($csv_data_for_gpt_structured)) {
        $json_encoded_csv_data = json_encode($csv_data_for_gpt_structured);
        $truncation_note = "";

        if (strlen($json_encoded_csv_data) > $max_chars_for_csv_context) {
            $data_context_for_api_string = substr($json_encoded_csv_data, 0, $max_chars_for_csv_context);
            // Attempt to make it valid JSON by finding last closing brace/bracket of a complete object/array
            $last_brace = strrpos($data_context_for_api_string, '}');
            $last_bracket = strrpos($data_context_for_api_string, ']');
            
            // Ensure we are cutting at a point that's likely to be the end of a JSON structure
            $cut_off_point = -1;
            if ($last_bracket !== false && ($last_brace === false || $last_bracket > $last_brace)) {
                 // Likely an array of objects, try to cut after the last full object in the array
                 $temp_str = substr($data_context_for_api_string, 0, $last_bracket + 1);
                 if (json_decode($temp_str) !== null || json_last_error() === JSON_ERROR_NONE) {
                     // If it's a valid JSON array up to this point
                     $data_context_for_api_string = $temp_str;
                 } else {
                     // Fallback: try to find the last '},' which might indicate end of an object in an array
                     $last_obj_in_array = strrpos($data_context_for_api_string, '},');
                     if ($last_obj_in_array !== false) {
                         $data_context_for_api_string = substr($data_context_for_api_string, 0, $last_obj_in_array + 1) . ']'; // Close the array
                     } else {
                         // If still not good, just take the substring and hope for the best or clear it
                         // $data_context_for_api_string = ""; // Or a generic error message
                     }
                 }
            } elseif ($last_brace !== false) { // If it's an object or ends with an object
                 $data_context_for_api_string = substr($data_context_for_api_string, 0, $last_brace + 1);
            }
            // Final check if the truncated string is valid JSON
            if (json_decode($data_context_for_api_string) === null && json_last_error() !== JSON_ERROR_NONE) {
                // If truncation resulted in invalid JSON, it's safer to send less or indicate error.
                // For now, we'll just use the truncated string and add a strong note.
                // A more robust solution might involve iterative truncation of elements from $csv_data_for_gpt_structured.
            }
            $truncation_note = "\n[Note: The provided CSV data context was truncated to fit within processing limits and may be incomplete.]";
        } else {
            $data_context_for_api_string = $json_encoded_csv_data;
        }
        
        if (!empty($data_context_for_api_string) && (json_decode($data_context_for_api_string) !== null || json_last_error() === JSON_ERROR_NONE)) {
            $final_system_prompt .= "\n\nUse the following data from CSV documents to answer the user's query. This data is specific to their search and is structured as an array of objects, where each object represents a CSV file and contains its 'source_file' (filename), 'columns' (an array of column headers), and 'rows' (an array of matching data rows, where each row is an associative array of header:value pairs):\n" . $data_context_for_api_string . $truncation_note;
        } else {
             $final_system_prompt .= "\n\nRelevant data was found in CSV documents but could not be fully processed to fit within context limits for the query: \"" . esc_html($query) . "\". Please use your general knowledge or ask for clarification if needed.";
        }

    } else {
        $final_system_prompt .= "\n\nNo specific data was found in local CSV documents matching the query: \"" . esc_html($query) . "\". Please use your general knowledge or ask for clarification if needed.";
    }
    // --- End: Optimized Direct CSV Query Logic ---
    
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
    
    // Prepare data for the formatter by extracting all rows from the structured CSV data
    $listings_for_formatter = [];
    if (!empty($csv_data_for_gpt_structured)) {
        foreach ($csv_data_for_gpt_structured as $file_data) {
            if (isset($file_data['rows']) && is_array($file_data['rows'])) {
                $listings_for_formatter = array_merge($listings_for_formatter, $file_data['rows']);
            }
        }
    }
    // If MLS data were also fetched and merged, $listings_for_formatter would include them too.
    // For now, it's just CSV data.
    
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
function chicago_loft_search_format_response($content, $listing_data) { // $listing_data might be CSV results now
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

    // Link generation might need adjustment if $listing_data is now raw CSV data
    // For now, this part is kept as is, assuming $listing_data still contains 'mls_id' and 'listing_type'
    // if it's from the database, or similar structure from CSV parsing.
    if (is_array($listing_data)) {
        foreach ($listing_data as $listing) {
            // Ensure $listing is an array and has 'mls_id'
            if (is_array($listing) && isset($listing['mls_id']) && !empty($listing['mls_id'])) {
                $mls_id = $listing['mls_id'];
                if (strpos($formatted, $mls_id) !== false) {
                    $pattern = '/(?<!href=\"[^\"]*|data-mls-id=\"[^\"]*\")' . preg_quote($mls_id, '/') . '(?![^\"]*\"\\s*>|[^<]*<\\/a>)/';
                    $listing_url = home_url('/loft/' . $mls_id); 
                    if (isset($listing['listing_type'])) { // Check if listing_type exists
                        if ($listing['listing_type'] === 'building') {
                            // $listing_url = home_url('/building/' . $mls_id); 
                        } elseif ($listing['listing_type'] === 'agent') {
                            // $listing_url = home_url('/agent/' . $mls_id); 
                        }
                    }
                    $replacement = '<a href="' . esc_url($listing_url) . '" class="chicago-loft-link" data-mls-id="' . esc_attr($mls_id) . '">' . esc_html($mls_id) . '</a>';
                    $formatted = preg_replace($pattern, $replacement, $formatted);
                }
            } elseif (is_object($listing) && isset($listing->mls_id) && !empty($listing->mls_id)) { // Handle object case if $listing_data comes from DB query
                 $mls_id = $listing->mls_id;
                if (strpos($formatted, $mls_id) !== false) {
                    $pattern = '/(?<!href=\"[^\"]*|data-mls-id=\"[^\"]*\")' . preg_quote($mls_id, '/') . '(?![^\"]*\"\\s*>|[^<]*<\\/a>)/';
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


    // Filter out mentions of competitor real estate sites
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
    
    // Assume 'loft' type if not specified in incoming REST data
    // For more advanced REST import, the $data could specify listing_type
    $default_listing_type = 'loft'; 

    foreach ($data['listings'] as $listing) {
        if (!isset($listing['mls_id']) || empty($listing['mls_id'])) {
            $errors++;
            continue;
        }
        
        $current_listing_type = isset($listing['listing_type']) ? sanitize_text_field($listing['listing_type']) : $default_listing_type;

        $base_data_for_db = array(
            'mls_id' => sanitize_text_field($listing['mls_id']),
            'status' => isset($listing['status']) ? sanitize_text_field($listing['status']) : 'active',
            'date_updated' => current_time('mysql'),
            'listing_type' => $current_listing_type,
        );

        $listing_data_for_db = array();
        // Here, we'd ideally have a similar structure to _prepare_*_data or pass $listing directly
        // For simplicity, assuming REST import is primarily for lofts or needs its own _prepare function
        if ($current_listing_type === 'loft') {
            $listing_data_for_db = _chicago_loft_search_prepare_loft_data($listing, $listing, $base_data_for_db);
        } elseif ($current_listing_type === 'building') {
            $listing_data_for_db = _chicago_loft_search_prepare_building_data($listing, $listing, $base_data_for_db);
        } elseif ($current_listing_type === 'agent') {
            $listing_data_for_db = _chicago_loft_search_prepare_agent_data($listing, $listing, $base_data_for_db);
        } else {
             $errors++; // Unknown type
            continue;
        }
        
        $listing_data_for_db['raw_data'] = json_encode($listing);

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE mls_id = %s", $listing_data_for_db['mls_id']));
        
        $current_format_array = array();
        $data_for_operation = $listing_data_for_db;

        if ($existing) {
            // For update, ensure all fields in $data_for_operation are covered by $current_format_array
            foreach (array_values($data_for_operation) as $value) {
                if (is_int($value)) $current_format_array[] = '%d';
                elseif (is_float($value)) $current_format_array[] = '%f';
                else $current_format_array[] = '%s';
            }
            $result = $wpdb->update( $table_name, $data_for_operation, array('mls_id' => $listing_data_for_db['mls_id']), $current_format_array, array('%s'));
            if ($result !== false) $updated++; else $errors++;
        } else {
            $data_for_operation['date_added'] = current_time('mysql');
            // For insert, ensure all fields in $data_for_operation are covered by $current_format_array
            foreach (array_values($data_for_operation) as $value) {
                if (is_int($value)) $current_format_array[] = '%d';
                elseif (is_float($value)) $current_format_array[] = '%f';
                else $current_format_array[] = '%s';
            }
            $result = $wpdb->insert( $table_name, $data_for_operation, $current_format_array);
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
    
    // This cost is an example, actual costs vary by model
    $options = get_option('chicago_loft_search_options');
    $model_for_cost = isset($options['model']) ? $options['model'] : 'gpt-3.5-turbo'; // Default if not set
    $cost_per_thousand_tokens_input = 0.0005; // Example for gpt-3.5-turbo-0125 input
    $cost_per_thousand_tokens_output = 0.0015; // Example for gpt-3.5-turbo-0125 output
    if (strpos($model_for_cost, 'gpt-4o') !== false) {
        $cost_per_thousand_tokens_input = 0.005; 
        $cost_per_thousand_tokens_output = 0.015;
    } elseif (strpos($model_for_cost, 'gpt-4-turbo') !== false) {
        $cost_per_thousand_tokens_input = 0.01;
        $cost_per_thousand_tokens_output = 0.03;
    } elseif (strpos($model_for_cost, 'gpt-4') !== false && strpos($model_for_cost, 'turbo') === false) { // Base GPT-4
        $cost_per_thousand_tokens_input = 0.03;
        $cost_per_thousand_tokens_output = 0.06;
    }
    // Assuming 1/3 input tokens, 2/3 output tokens for estimation
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
        // Intentionally do not export the API key for security
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

    // Preserve existing API key if not included in import file
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
    $mls_api_key = isset($options['mls_api_key']) ? $options['mls_api_key'] : ''; // This option might not exist yet
    
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
    
    // Use the same import logic as REST API import for consistency
    // Create a mock WP_REST_Request object
    $mock_request = new WP_REST_Request('POST', '/chicago-loft-search/v1/import');
    $mock_request->set_body(json_encode($data)); // $data already contains the 'listings' array
    
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
    
    // Generate HTML based on listing_type
    // This part would need to be significantly expanded to show different details for buildings/agents
    // For now, it's still loft-centric
    
    $image_urls = json_decode($loft->image_urls, true);
    $images_html = '';
    
    if (is_array($image_urls) && !empty($image_urls)) {
        $images_html .= '<div class="loft-images-slider">'; // Class name might need to be generic
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
        $features_html = '<ul class="loft-features-list">'; // Generic class
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

    if ($loft->listing_type === 'loft' || $loft->listing_type === 'building') {
        $html .= '<div class="listing-neighborhood">' . esc_html($loft->neighborhood ?: '') . '</div>';
    }
            
    if ($loft->listing_type === 'loft') {
        $html .= '<div class="listing-price-info">
                <div class="listing-price">' . ($loft->price ? '$' . number_format_i18n($loft->price) : __('Price not available', 'chicago-loft-search')) . '</div>
                <div class="listing-specs">
                    <span class="listing-beds">' . ($loft->bedrooms ? esc_html($loft->bedrooms) . ' ' . _n('Bed', 'Beds', $loft->bedrooms, 'chicago-loft-search') : '') . '</span>
                    <span class="listing-baths">' . ($loft->bathrooms ? esc_html($loft->bathrooms) . ' ' . _n('Bath', 'Baths', floatval($loft->bathrooms), 'chicago-loft-search') : '') . '</span>
                    <span class="listing-sqft">' . ($loft->square_feet ? number_format_i18n($loft->square_feet) . ' ' . __('sq ft', 'chicago-loft-search') : '') . '</span>
                </div>
            </div>';
    }
    
    $html .= $images_html; // Display images for all types if available
            
    $html .= '<div class="listing-description">
                <h3>' . __('Description', 'chicago-loft-search') . '</h3>
                <p>' . nl2br(esc_html($loft->description ?: ($loft->bio ?: __('Description not available.', 'chicago-loft-search')))) . '</p>
            </div>';
            
    // Type-specific details grid
    if ($loft->listing_type === 'loft') {
        $html .= '<div class="listing-details-grid">
                <div class="listing-details-column">
                    <h3>' . __('Property Details', 'chicago-loft-search') . '</h3>
                    <table class="listing-details-table">
                        <tr><th>' . __('MLS ID', 'chicago-loft-search') . ':</th><td>' . esc_html($loft->mls_id) . '</td></tr>
                        <tr><th>' . __('Year Built', 'chicago-loft-search') . ':</th><td>' . ($loft->year_built ? esc_html($loft->year_built) : __('N/A', 'chicago-loft-search')) . '</td></tr>
                        <tr><th>' . __('Square Feet', 'chicago-loft-search') . ':</th><td>' . ($loft->square_feet ? number_format_i18n($loft->square_feet) : __('N/A', 'chicago-loft-search')) . '</td></tr>';
        if ($loft->square_feet > 0 && $loft->price > 0) { 
            $html .= '<tr><th>' . __('Price per sq ft', 'chicago-loft-search') . ':</th><td>$' . number_format_i18n($loft->price / $loft->square_feet, 2) . '</td></tr>';
        }
        $html .= '</table></div><div class="listing-details-column"><h3>' . __('Features', 'chicago-loft-search') . '</h3>' . ($features_html ?: __('Features not available.', 'chicago-loft-search')) . '</div></div>';
    } elseif ($loft->listing_type === 'building') {
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

// --- CSV Import Helper Functions ---\

/**
 * Helper to detect listing type from CSV headers.
 */
function _chicago_loft_search_detect_listing_type($headers) {
    // Convert headers to lowercase for case-insensitive comparison
    $headers_lower = array_map('strtolower', $headers);
    
    // Define signature columns for each type
    $building_signatures = ['building name', 'neighborhood', 'hoa fee', 'pet policy', 'amenities', 'year built', 'floors', 'units'];
    $loft_signatures = ['loft name', 'exposed brick', 'loft features', 'elevator type', 'timber ceiling', 'concrete', 'hard loft', 'soft loft', 'price', 'bedrooms', 'bathrooms', 'square_feet']; // Added more loft specific fields
    $agent_signatures = ['agent name', 'bio', 'areas of expertise', 'phone', 'email', 'specialty', 'license'];
    
    // Count the matches for each type
    $building_match_count = count(array_intersect($headers_lower, $building_signatures));
    $loft_match_count = count(array_intersect($headers_lower, $loft_signatures));
    $agent_match_count = count(array_intersect($headers_lower, $agent_signatures));
    
    // Determine the most likely type based on match counts
    // Loft often has more specific fields, so check it first if its signature is strong
    if ($loft_match_count >= 3 && $loft_match_count > $building_match_count && $loft_match_count > $agent_match_count) {
        return 'loft';
    }
    if ($building_match_count >= 2 && $building_match_count >= $loft_match_count && $building_match_count >= $agent_match_count) {
        return 'building';
    }
    if ($agent_match_count >= 2) { // Agent fields are quite distinct
        return 'agent';
    }
    
    // Fallback logic if primary checks are not decisive
    if ($loft_match_count >= 2) return 'loft'; // General fallback to loft if some loft fields are present
    if ($building_match_count >= 1 && $loft_match_count < 2 && $agent_match_count < 2) return 'building';


    // Default to loft if type cannot be clearly determined or if it's a generic real estate CSV
    return 'loft';
}

/**
 * Helper to generate preview data based on listing type.
 */
function _chicago_loft_search_generate_preview_by_type($csv_row_assoc, $listing_type) {
    $preview_item = array(
        'listing_type' => $listing_type
        // 'original_csv_data' => $csv_row_assoc // For debugging or if needed by JS
    );
    
    switch ($listing_type) {
        case 'building':
            $building_name = isset($csv_row_assoc['Building Name']) ? trim($csv_row_assoc['Building Name']) : (isset($csv_row_assoc['BuildingName']) ? trim($csv_row_assoc['BuildingName']) : '');
            $address = isset($csv_row_assoc['Address']) ? trim($csv_row_assoc['Address']) : '';
            $neighborhood = isset($csv_row_assoc['Neighborhood']) ? trim($csv_row_assoc['Neighborhood']) : 
                (isset($csv_row_assoc['City and Neighborhood']) ? _chicago_loft_search_extract_neighborhood_from_string($csv_row_assoc['City and Neighborhood']) : '');
            
            $preview_item = array_merge($preview_item, array(
                'mls_id' => _chicago_loft_search_generate_mls_id_from_data('building-' . $building_name . '-' . $address),
                'building_name' => $building_name,
                'address' => $address,
                'neighborhood' => $neighborhood,
                'year_built' => isset($csv_row_assoc['Year Built']) && $csv_row_assoc['Year Built'] !== '' ? intval($csv_row_assoc['Year Built']) : (isset($csv_row_assoc['YearBuilt']) ? intval($csv_row_assoc['YearBuilt']) : null),
                'units' => isset($csv_row_assoc['Units']) && $csv_row_assoc['Units'] !== '' ? intval($csv_row_assoc['Units']) : null,
                'floors' => isset($csv_row_assoc['Floors']) && $csv_row_assoc['Floors'] !== '' ? intval($csv_row_assoc['Floors']) : null,
                'hoa_fee' => isset($csv_row_assoc['HOA Fee']) ? trim($csv_row_assoc['HOA Fee']) : (isset($csv_row_assoc['HOAFee']) ? trim($csv_row_assoc['HOAFee']) : ''),
                'pet_policy' => isset($csv_row_assoc['Pet Policy']) ? trim($csv_row_assoc['Pet Policy']) : (isset($csv_row_assoc['PetPolicy']) ? trim($csv_row_assoc['PetPolicy']) : ''),
                'amenities' => isset($csv_row_assoc['Amenities']) ? trim($csv_row_assoc['Amenities']) : '',
                'description' => _chicago_loft_search_construct_building_description_from_csv_row($csv_row_assoc),
                'image_urls' => '', // Placeholder, can be mapped if CSV has image URLs
            ));
            break;
            
        case 'agent':
            $agent_name = isset($csv_row_assoc['Agent Name']) ? trim($csv_row_assoc['Agent Name']) : (isset($csv_row_assoc['AgentName']) ? trim($csv_row_assoc['AgentName']) : '');
            
            $preview_item = array_merge($preview_item, array(
                'mls_id' => _chicago_loft_search_generate_mls_id_from_data('agent-' . $agent_name . '-' . (isset($csv_row_assoc['Email']) ? trim($csv_row_assoc['Email']) : uniqid())),
                'agent_name' => $agent_name,
                'email' => isset($csv_row_assoc['Email']) ? trim($csv_row_assoc['Email']) : '',
                'phone' => isset($csv_row_assoc['Phone']) ? trim($csv_row_assoc['Phone']) : '',
                'bio' => isset($csv_row_assoc['Bio']) ? trim($csv_row_assoc['Bio']) : '',
                'areas_of_expertise' => isset($csv_row_assoc['Areas of Expertise']) ? trim($csv_row_assoc['Areas of Expertise']) : (isset($csv_row_assoc['AreasOfExpertise']) ? trim($csv_row_assoc['AreasOfExpertise']) : ''),
                'specialty' => isset($csv_row_assoc['Specialty']) ? trim($csv_row_assoc['Specialty']) : '',
                'license' => isset($csv_row_assoc['License']) ? trim($csv_row_assoc['License']) : '',
                'image_url' => isset($csv_row_assoc['Image URL']) ? trim($csv_row_assoc['Image URL']) : (isset($csv_row_assoc['ImageURL']) ? trim($csv_row_assoc['ImageURL']) : ''),
            ));
            break;
            
        case 'loft':
        default:
            $address_full = isset($csv_row_assoc['Address']) ? trim($csv_row_assoc['Address']) : '';
            $year_built = isset($csv_row_assoc['YearBuilt']) && $csv_row_assoc['YearBuilt'] !== '' ? intval($csv_row_assoc['YearBuilt']) : (isset($csv_row_assoc['Year Built']) ? intval($csv_row_assoc['Year Built']) : null);
            $city_neighborhood = isset($csv_row_assoc['City and Neighborhood']) ? trim($csv_row_assoc['City and Neighborhood']) : (isset($csv_row_assoc['Neighborhood']) ? trim($csv_row_assoc['Neighborhood']) : '');
            
            $preview_item = array_merge($preview_item, array(
                'original_address' => $address_full, // Keep original if needed
                'mls_id' => isset($csv_row_assoc['MLS ID']) && !empty(trim($csv_row_assoc['MLS ID'])) ? trim($csv_row_assoc['MLS ID']) : (isset($csv_row_assoc['MLSID']) && !empty(trim($csv_row_assoc['MLSID'])) ? trim($csv_row_assoc['MLSID']) : _chicago_loft_search_generate_mls_id_from_data($address_full)),
                'address' => $address_full,
                'neighborhood' => _chicago_loft_search_extract_neighborhood_from_string($city_neighborhood),
                'price' => isset($csv_row_assoc['Price']) && $csv_row_assoc['Price'] !== '' ? floatval(str_replace(array('$', ','), '', $csv_row_assoc['Price'])) : null,
                'bedrooms' => isset($csv_row_assoc['Bedrooms']) && $csv_row_assoc['Bedrooms'] !== '' ? intval($csv_row_assoc['Bedrooms']) : (isset($csv_row_assoc['Beds']) ? intval($csv_row_assoc['Beds']) : null),
                'bathrooms' => isset($csv_row_assoc['Bathrooms']) && $csv_row_assoc['Bathrooms'] !== '' ? floatval($csv_row_assoc['Bathrooms']) : (isset($csv_row_assoc['Baths']) ? floatval($csv_row_assoc['Baths']) : null),
                'square_feet' => isset($csv_row_assoc['Square Feet']) && $csv_row_assoc['Square Feet'] !== '' ? intval(str_replace(',', '', $csv_row_assoc['Square Feet'])) : (isset($csv_row_assoc['SqFt']) ? intval(str_replace(',', '', $csv_row_assoc['SqFt'])) : null),
                'year_built' => $year_built,
                'description' => _chicago_loft_search_construct_description_from_csv_row($csv_row_assoc),
                'image_urls' => '', // Placeholder
                'features_preview' => substr(_chicago_loft_search_construct_features_from_csv_row($csv_row_assoc), 0, 100) . '...'
            ));
            break;
    }
    return $preview_item;
}

/**
 * Helper to construct building description from CSV row.
 */
function _chicago_loft_search_construct_building_description_from_csv_row($csv_row_assoc) {
    $description_parts = array();
    $building_name_key = isset($csv_row_assoc['Building Name']) ? 'Building Name' : (isset($csv_row_assoc['BuildingName']) ? 'BuildingName' : null);
    $year_built_key = isset($csv_row_assoc['Year Built']) ? 'Year Built' : (isset($csv_row_assoc['YearBuilt']) ? 'YearBuilt' : null);

    if ($building_name_key && !empty($csv_row_assoc[$building_name_key])) $description_parts[] = trim($csv_row_assoc[$building_name_key]) . ".";
    if ($year_built_key && !empty($csv_row_assoc[$year_built_key])) $description_parts[] = "Built in " . trim($csv_row_assoc[$year_built_key]) . ".";
    if (!empty($csv_row_assoc['Units'])) $description_parts[] = trim($csv_row_assoc['Units']) . " units in building.";
    if (!empty($csv_row_assoc['Floors'])) $description_parts[] = trim($csv_row_assoc['Floors']) . " floors.";
    if (!empty($csv_row_assoc['Amenities'])) $description_parts[] = "Amenities include " . trim($csv_row_assoc['Amenities']) . ".";
    
    if (empty($description_parts)) $description_parts[] = "Building in Chicago.";
    
    return implode(' ', $description_parts);
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
    // Use a more robust unique ID if the identifier is empty or very short
    if (empty($identifier_string) || strlen($identifier_string) < 5) {
        return 'uid-' . substr(md5(uniqid(rand(), true)), 0, 12);
    }
    return sanitize_title($identifier_string . '-' . substr(md5($identifier_string . uniqid(rand(), true)), 0, 8));
}

/**
 * Helper to extract neighborhood.
 */
function _chicago_loft_search_extract_neighborhood_from_string($city_neighborhood_string) {
    if (empty($city_neighborhood_string)) return '';
    $parts = explode(',', $city_neighborhood_string);
    // Assuming neighborhood might be the second part if "City, Neighborhood" or first if just "Neighborhood"
    return trim(isset($parts[1]) ? $parts[1] : $parts[0]);
}

/**
 * Helper to construct features string from CSV row (Loft specific).
 */
function _chicago_loft_search_construct_features_from_csv_row($csv_row_assoc) {
    $features_list = array();
    // More comprehensive map for loft features
    $feature_columns_map = array(
        'Parking' => 'Parking Available', 'ParkingType' => 'Parking Type: %s', 
        'FHA Approved' => 'FHA Approved', 'Pets Allowed' => 'Pets Allowed', 'PetsInfo' => 'Pet Info: %s', 
        'Amenities' => 'Amenities: %s', // Could be building amenities if loft is in a building
        'ExposedBrick' => 'Exposed Brick', 'ExposedDuctwork' => 'Exposed Ductwork',
        'ConcreteCeiling' => 'Concrete Ceiling', 'ConcretePosts' => 'Concrete Posts',
        'TimberCeiling' => 'Timber Ceiling', 'TimberPosts' => 'Timber Posts',
        'OutdoorSpace' => 'Outdoor Space', 'Deck' => 'Deck', 'Balcony' => 'Balcony', 
        'RoofDeckShared' => 'Shared Roof Deck', 'RoofDeckPrivate' => 'Private Roof Deck', 
        'HardwoodFloors' => 'Hardwood Floors', 'OversizedWindows' => 'Oversized Windows', 
        'SoftLoft' => 'Soft Loft', 'HardLoft' => 'Hard Loft',
        'Fireplace' => 'Fireplace', 'InUnitWasherDryer' => 'In-Unit Washer/Dryer',
        'CeilingHeight' => 'Ceiling Height: %s ft'
    );

    foreach ($feature_columns_map as $csv_col_name => $feature_text_format) {
        if (isset($csv_row_assoc[$csv_col_name]) && !empty(trim($csv_row_assoc[$csv_col_name]))) {
            $value = trim($csv_row_assoc[$csv_col_name]);
            if (strtoupper($value) === 'Y' || strtoupper($value) === 'YES' || $value === '1' || $value === true) {
                 // If format string doesn't have %s, it's a boolean feature
                if (strpos($feature_text_format, '%s') === false) {
                    $features_list[] = $feature_text_format;
                } else {
                    // If it expects a value but got Y/Yes, use a generic affirmative
                    $features_list[] = str_replace(': %s', '', $feature_text_format) . ' (Yes)';
                }
            } elseif (strpos($feature_text_format, '%s') !== false) {
                $features_list[] = sprintf($feature_text_format, $value);
            }
            // If value is N/No/0/false and format string doesn't have %s, we can skip it or add "Feature: No"
        }
    }
    return implode(', ', array_filter($features_list));
}

/**
 * Helper to construct description from CSV row (Loft specific).
 */
function _chicago_loft_search_construct_description_from_csv_row($csv_row_assoc) {
    $description_parts = array();
    // Try various common description / remarks / comments column names
    $desc_keys = ['Description', 'Remarks', 'PublicRemarks', 'AgentRemarks', 'Comments', 'PropertyDescription'];
    foreach($desc_keys as $key) {
        if (!empty($csv_row_assoc[$key])) {
            $description_parts[] = trim($csv_row_assoc[$key]);
            break; // Found a description, use it
        }
    }

    if (empty($description_parts)) { // Fallback if no direct description column
        if (!empty($csv_row_assoc['BuildingName'])) $description_parts[] = trim($csv_row_assoc['BuildingName']) . ".";
        if (!empty($csv_row_assoc['Type'])) $description_parts[] = "Type: " . trim($csv_row_assoc['Type']) . ".";
        if (!empty($csv_row_assoc['Stories'])) $description_parts[] = trim($csv_row_assoc['Stories']) . " stories.";
        if (!empty($csv_row_assoc['Units'])) $description_parts[] = trim($csv_row_assoc['Units']) . " units in building.";
    }
    if (empty($description_parts)) $description_parts[] = "Loft property.";
    
    return implode(' ', $description_parts);
}

/**
 * Helper to prepare loft data for database insertion.
 */
function _chicago_loft_search_prepare_loft_data($item, $original_csv_data, $base_data) {
    $address = !empty($item['address']) ? sanitize_text_field($item['address']) : (isset($original_csv_data['Address']) ? trim($original_csv_data['Address']) : 'N/A');
    $neighborhood_key = isset($original_csv_data['Neighborhood']) ? 'Neighborhood' : (isset($original_csv_data['City and Neighborhood']) ? 'City and Neighborhood' : null);
    $neighborhood_raw = $neighborhood_key ? $original_csv_data[$neighborhood_key] : '';
    $neighborhood = !empty($item['neighborhood']) ? sanitize_text_field($item['neighborhood']) : _chicago_loft_search_extract_neighborhood_from_string($neighborhood_raw);
    
    $data = array_merge($base_data, array(
        'address' => $address,
        'neighborhood' => $neighborhood,
        'price' => isset($item['price']) && $item['price'] !== '' ? floatval(str_replace(array('$', ','), '', $item['price'])) : null,
        'bedrooms' => isset($item['bedrooms']) && $item['bedrooms'] !== '' ? intval($item['bedrooms']) : null,
        'bathrooms' => isset($item['bathrooms']) && $item['bathrooms'] !== '' ? floatval($item['bathrooms']) : null,
        'square_feet' => isset($item['square_feet']) && $item['square_feet'] !== '' ? intval(str_replace(',', '', $item['square_feet'])) : null,
        'year_built' => isset($original_csv_data['YearBuilt']) && $original_csv_data['YearBuilt'] !== '' ? intval($original_csv_data['YearBuilt']) : (isset($item['year_built']) && $item['year_built'] !== '' ? intval($item['year_built']) : null),
        'features' => _chicago_loft_search_construct_features_from_csv_row($original_csv_data),
        'description' => !empty($item['description']) ? sanitize_textarea_field($item['description']) : _chicago_loft_search_construct_description_from_csv_row($original_csv_data),
        'image_urls' => isset($item['image_urls']) && is_array($item['image_urls']) ? json_encode(array_map('esc_url_raw', $item['image_urls'])) : (isset($item['image_urls']) && !is_array($item['image_urls']) && !empty($item['image_urls']) ? json_encode([esc_url_raw($item['image_urls'])]) : '[]'),
    ));
    
    return $data;
}

/**
 * Helper to prepare building data for database insertion.
 */
function _chicago_loft_search_prepare_building_data($item, $original_csv_data, $base_data) {
    $building_name_key = isset($original_csv_data['Building Name']) ? 'Building Name' : (isset($original_csv_data['BuildingName']) ? 'BuildingName' : null);
    $building_name = !empty($item['building_name']) ? sanitize_text_field($item['building_name']) : ($building_name_key ? trim($original_csv_data[$building_name_key]) : '');
    
    $address = !empty($item['address']) ? sanitize_text_field($item['address']) : (isset($original_csv_data['Address']) ? trim($original_csv_data['Address']) : 'N/A');
    $neighborhood_key = isset($original_csv_data['Neighborhood']) ? 'Neighborhood' : (isset($original_csv_data['City and Neighborhood']) ? 'City and Neighborhood' : null);
    $neighborhood_raw = $neighborhood_key ? $original_csv_data[$neighborhood_key] : '';
    $neighborhood = !empty($item['neighborhood']) ? sanitize_text_field($item['neighborhood']) : _chicago_loft_search_extract_neighborhood_from_string($neighborhood_raw);
    
    $data = array_merge($base_data, array(
        'address' => $address,
        'neighborhood' => $neighborhood,
        'year_built' => isset($item['year_built']) && $item['year_built'] !== '' ? intval($item['year_built']) : (isset($original_csv_data['Year Built']) ? intval($original_csv_data['Year Built']) : (isset($original_csv_data['YearBuilt']) ? intval($original_csv_data['YearBuilt']) : null)),
        'description' => !empty($item['description']) ? sanitize_textarea_field($item['description']) : _chicago_loft_search_construct_building_description_from_csv_row($original_csv_data),
        'image_urls' => isset($item['image_urls']) && is_array($item['image_urls']) ? json_encode(array_map('esc_url_raw', $item['image_urls'])) : (isset($item['image_urls']) && !is_array($item['image_urls']) && !empty($item['image_urls']) ? json_encode([esc_url_raw($item['image_urls'])]) : '[]'),
        'building_name' => $building_name,
        'units' => isset($item['units']) && $item['units'] !== '' ? intval($item['units']) : (isset($original_csv_data['Units']) ? intval($original_csv_data['Units']) : null),
        'floors' => isset($item['floors']) && $item['floors'] !== '' ? intval($item['floors']) : (isset($original_csv_data['Floors']) ? intval($original_csv_data['Floors']) : null),
        'hoa_fee' => isset($item['hoa_fee']) ? sanitize_text_field($item['hoa_fee']) : (isset($original_csv_data['HOA Fee']) ? trim($original_csv_data['HOA Fee']) : (isset($original_csv_data['HOAFee']) ? trim($original_csv_data['HOAFee']) : '')),
        'pet_policy' => isset($item['pet_policy']) ? sanitize_text_field($item['pet_policy']) : (isset($original_csv_data['Pet Policy']) ? trim($original_csv_data['Pet Policy']) : (isset($original_csv_data['PetPolicy']) ? trim($original_csv_data['PetPolicy']) : '')),
        'amenities' => isset($item['amenities']) ? sanitize_text_field($item['amenities']) : (isset($original_csv_data['Amenities']) ? trim($original_csv_data['Amenities']) : ''),
    ));
    
    return $data;
}

/**
 * Helper to prepare agent data for database insertion.
 */
function _chicago_loft_search_prepare_agent_data($item, $original_csv_data, $base_data) {
    $agent_name_key = isset($original_csv_data['Agent Name']) ? 'Agent Name' : (isset($original_csv_data['AgentName']) ? 'AgentName' : null);
    $agent_name = !empty($item['agent_name']) ? sanitize_text_field($item['agent_name']) : ($agent_name_key ? trim($original_csv_data[$agent_name_key]) : '');
    
    $data = array_merge($base_data, array(
        'agent_name' => $agent_name,
        'email' => isset($item['email']) ? sanitize_email($item['email']) : (isset($original_csv_data['Email']) ? sanitize_email(trim($original_csv_data['Email'])) : ''),
        'phone' => isset($item['phone']) ? sanitize_text_field($item['phone']) : (isset($original_csv_data['Phone']) ? sanitize_text_field(trim($original_csv_data['Phone'])) : ''),
        'bio' => isset($item['bio']) ? sanitize_textarea_field($item['bio']) : (isset($original_csv_data['Bio']) ? sanitize_textarea_field(trim($original_csv_data['Bio'])) : ''),
        'areas_of_expertise' => isset($item['areas_of_expertise']) ? sanitize_text_field($item['areas_of_expertise']) : (isset($original_csv_data['Areas of Expertise']) ? trim($original_csv_data['Areas of Expertise']) : (isset($original_csv_data['AreasOfExpertise']) ? trim($original_csv_data['AreasOfExpertise']) : '')),
        'specialty' => isset($item['specialty']) ? sanitize_text_field($item['specialty']) : (isset($original_csv_data['Specialty']) ? trim($original_csv_data['Specialty']) : ''),
        'license' => isset($item['license']) ? sanitize_text_field($item['license']) : (isset($original_csv_data['License']) ? trim($original_csv_data['License']) : ''),
        'image_urls' => isset($item['image_url']) && !empty($item['image_url']) ? json_encode([esc_url_raw($item['image_url'])]) : (isset($original_csv_data['Image URL']) && !empty($original_csv_data['Image URL']) ? json_encode([esc_url_raw($original_csv_data['Image URL'])]) : (isset($original_csv_data['ImageURL']) && !empty($original_csv_data['ImageURL']) ? json_encode([esc_url_raw($original_csv_data['ImageURL'])]) : '[]')),
        'description' => isset($item['bio']) ? sanitize_textarea_field($item['bio']) : (isset($original_csv_data['Bio']) ? sanitize_textarea_field(trim($original_csv_data['Bio'])) : ''), // Use bio as description for agents
    ));
    
    return $data;
}


// --- AJAX Handlers for CSV Import ---\

/**
 * AJAX handler for parsing CSV and returning preview data.
 */
function chicago_loft_search_parse_csv_preview() {
    check_ajax_referer('chicago_loft_search_import_nonce', 'nonce'); // Ensure this nonce matches the one in JS
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'chicago-loft-search')));
    }

    if (!isset($_FILES['mls_csv_file']) || $_FILES['mls_csv_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(array('message' => __('File upload error.', 'chicago-loft-search')));
    }

    $file_path = $_FILES['mls_csv_file']['tmp_name'];
    $preview_data = array();
    $raw_data_for_import = array(); // This will store the rows with original_csv_data
    $header = null;
    $listing_type = 'loft'; // Default type

    if (($handle = fopen($file_path, 'r')) !== false) {
        $row_count = 0;
        while (($row_values = fgetcsv($handle)) !== false) {
            if (!$header) {
                $header = array_map('trim', $row_values);
                // Detect listing type from headers
                $listing_type = _chicago_loft_search_detect_listing_type($header);
                continue;
            }
            if (count($header) !== count($row_values)) { 
                // Log or skip malformed row
                continue;
            }
            $csv_row_assoc = array_combine($header, $row_values);
            
            // Generate preview data based on listing type
            $preview_item = _chicago_loft_search_generate_preview_by_type($csv_row_assoc, $listing_type);
            $preview_data[] = $preview_item;

            // Store the original associative array for batch import
            $raw_data_for_import[] = array(
                'preview_item_for_js' => $preview_item, // This is what JS might use to display, includes mls_id etc.
                'original_csv_data' => $csv_row_assoc, // This is the raw data for _prepare functions
                'listing_type' => $listing_type // Ensure type is passed for each row
            );
            $row_count++;
            if ($row_count >= 200) break; // Limit preview size for performance
        }
        fclose($handle);
    } else {
        wp_send_json_error(array('message' => __('Could not open CSV file.', 'chicago-loft-search')));
    }

    if (empty($preview_data)) {
        wp_send_json_error(array('message' => __('No data found in CSV or CSV format error (ensure header row exists).', 'chicago-loft-search')));
    }

    wp_send_json_success(array(
        'preview_data' => $preview_data,  // For display in admin UI
        'raw_data_for_import' => $raw_data_for_import, // For actual import process
        'listing_type' => $listing_type // Overall detected type for the file (can be overridden per row if needed)
    ));
}
add_action('wp_ajax_chicago_loft_search_parse_csv_preview', 'chicago_loft_search_parse_csv_preview');

/**
 * AJAX handler for importing a batch of listings.
 */
function chicago_loft_search_import_listings_batch() {
    check_ajax_referer('chicago_loft_search_import_nonce', 'nonce'); // Ensure this nonce matches
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'chicago-loft-search')));
    }

    if (!isset($_POST['listings_to_import'])) { // Expecting 'listings_to_import' from JS
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
    
    // Use overall listing type from POST if available, otherwise it's per item
    $overall_listing_type = isset($_POST['listing_type']) ? sanitize_text_field($_POST['listing_type']) : 'loft';

    foreach ($listings_batch_from_js as $js_item) {
        // Each $js_item should contain 'preview_item_for_js' and 'original_csv_data' and 'listing_type'
        $item_preview = isset($js_item['preview_item_for_js']) ? $js_item['preview_item_for_js'] : array();
        $original_csv_data = isset($js_item['original_csv_data']) && is_array($js_item['original_csv_data']) ? $js_item['original_csv_data'] : array();
        $current_type = isset($js_item['listing_type']) ? sanitize_text_field($js_item['listing_type']) : $overall_listing_type;
        
        if (empty($original_csv_data)) {
            $batch_results[] = array('success' => false, 'message' => __('Skipped item due to missing original CSV data.', 'chicago-loft-search'));
            continue;
        }

        // Common fields. Use mls_id from preview if available, otherwise generate.
        $mls_id = !empty($item_preview['mls_id']) ? sanitize_text_field($item_preview['mls_id']) : _chicago_loft_search_generate_mls_id_from_data(uniqid('item-', true));
        
        $base_data = array(
            'mls_id' => $mls_id,
            'status' => sanitize_text_field(isset($item_preview['status']) ? $item_preview['status'] : 'active'),
            'date_updated' => current_time('mysql'),
            'listing_type' => $current_type,
        );
        
        $data_to_insert = array();
        switch ($current_type) {
            case 'building':
                $data_to_insert = _chicago_loft_search_prepare_building_data($item_preview, $original_csv_data, $base_data);
                break;
            case 'agent':
                $data_to_insert = _chicago_loft_search_prepare_agent_data($item_preview, $original_csv_data, $base_data);
                break;
            case 'loft':
            default:
                $data_to_insert = _chicago_loft_search_prepare_loft_data($item_preview, $original_csv_data, $base_data);
                break;
        }
        
        $data_to_insert['raw_data'] = json_encode($original_csv_data);
        
        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE mls_id = %s", $data_to_insert['mls_id']));

        $data_for_db = $data_to_insert;
        if (!$existing_id) {
            $data_for_db['date_added'] = current_time('mysql');
        }

        $current_format_array = array();
        // Build format array based on the actual keys in $data_for_db
        // $wpdb->insert/update work with associative arrays, format array should correspond to values in order
        // To be safe, we list formats for all possible columns that might be in $data_for_db
        // Or, more dynamically:
        foreach ($data_for_db as $value) {
            if (is_int($value)) $current_format_array[] = '%d';
            elseif (is_float($value)) $current_format_array[] = '%f';
            else $current_format_array[] = '%s'; // Handles strings and nulls
        }

        if ($existing_id) {
            $result = $wpdb->update($table_name, $data_for_db, array('id' => $existing_id), $current_format_array, array('%d'));
            if ($result !== false) {
                $batch_results[] = array('success' => true, 'message' => sprintf(__('Updated %s ID %s', 'chicago-loft-search'), $current_type, $data_to_insert['mls_id']));
            } else {
                $batch_results[] = array('success' => false, 'message' => sprintf(__('Error updating %s ID %s. DB Error: %s', 'chicago-loft-search'), $current_type, $data_to_insert['mls_id'], $wpdb->last_error));
            }
        } else {
            $result = $wpdb->insert($table_name, $data_for_db, $current_format_array);
            if ($result) {
                $batch_results[] = array('success' => true, 'message' => sprintf(__('Imported %s ID %s', 'chicago-loft-search'), $current_type, $data_to_insert['mls_id']));
            } else {
                $batch_results[] = array('success' => false, 'message' => sprintf(__('Error importing %s ID %s. DB Error: %s', 'chicago-loft-search'), $current_type, $data_to_insert['mls_id'], $wpdb->last_error));
            }
        }
    }
    
    // Update last sync date after successful batch operations
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
    $result = $wpdb->query("TRUNCATE TABLE $table_name"); // TRUNCATE is faster for deleting all rows

    if ($result !== false) {
        // Also clear last_sync_date
        $options = get_option('chicago_loft_search_options');
        $options['last_sync_date'] = '';
        update_option('chicago_loft_search_options', $options);
        wp_send_json_success(array('message' => __('All imported data has been deleted.', 'chicago-loft-search')));
    } else {
        wp_send_json_error(array('message' => __('Error deleting data. Please try again.', 'chicago-loft-search')));
    }
}
add_action('wp_ajax_chicago_loft_search_delete_all_listings', 'chicago_loft_search_delete_all_listings_ajax');

// --- Direct CSV Query Functions (New Feature) ---\

/**
 * Create a directory for CSV storage if it doesn't exist.
 */
function chicago_loft_search_create_csv_storage() {
    $upload_dir = wp_upload_dir();
    $csv_dir = $upload_dir['basedir'] . '/csv-documents';
    if (!file_exists($csv_dir)) {
        wp_mkdir_p($csv_dir);
        // Create an empty index.php for security
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
    
    settings_errors('csv_manager'); // Display any success/error messages
    
    $upload_dir = wp_upload_dir();
    $csv_dir = chicago_loft_search_create_csv_storage(); // Ensure directory exists
    $files = glob($csv_dir . '/*.csv');
    
    echo '<div class="wrap">';
    echo '<h1>' . __('Manage CSV Documents', 'chicago-loft-search') . '</h1>';
    echo '<p>' . __('Upload CSV files here to make their content available for direct querying by ChatGPT.', 'chicago-loft-search') . '</p>';
    
    // Upload form
    echo '<h2>' . __('Upload New CSV Document', 'chicago-loft-search') . '</h2>';
    echo '<form method="post" enctype="multipart/form-data" style="margin-bottom: 20px;">';
    echo '<input type="file" name="csv_document" accept=".csv" required>';
    echo '<input type="submit" name="upload_csv" value="' . __('Upload Document', 'chicago-loft-search') . '" class="button button-primary">';
    wp_nonce_field('upload_csv_nonce', 'upload_csv_nonce_field'); // Unique nonce name
    echo '</form>';
    
    // List existing files
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
            echo '<input type="hidden" name="delete_csv_file" value="' . esc_attr($filename) . '">'; // Unique name for delete input
            wp_nonce_field('delete_csv_nonce', 'delete_csv_nonce_field'); // Unique nonce name
            echo '<input type="submit" class="button button-link-delete" value="' . __('Delete', 'chicago-loft-search') . '" onclick="return confirm(\'' . esc_js(sprintf(__('Are you sure you want to delete %s?', 'chicago-loft-search'), $filename)) . '\');">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . __('No CSV documents found. Upload one using the form above.', 'chicago-loft-search') . '</p>';
    }
    
    echo '</div>'; // .wrap
}

/**
 * Process CSV uploads and deletions.
 */
function chicago_loft_search_process_csv_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Handle upload
    if (isset($_POST['upload_csv']) && isset($_FILES['csv_document']) && isset($_POST['upload_csv_nonce_field'])) {
        if (!wp_verify_nonce($_POST['upload_csv_nonce_field'], 'upload_csv_nonce')) {
            add_settings_error('csv_manager', 'csv_nonce_fail', __('Security check failed for upload.', 'chicago-loft-search'), 'error');
            return;
        }
        
        $csv_dir = chicago_loft_search_create_csv_storage();
        $file = $_FILES['csv_document'];
        
        // Detailed error reporting for debugging
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
        
        // Check file extension - more reliable than MIME type
        $filename = sanitize_file_name($file['name']);
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if ($file_extension !== 'csv') {
            // Try to check if it might be CSV despite the extension
            $fh = fopen($file['tmp_name'], 'r');
            if ($fh) {
                $first_line = fgets($fh);
                fclose($fh);
                // Check if it looks like CSV (has commas or tabs)
                if (strpos($first_line, ',') !== false || strpos($first_line, "\t") !== false) {
                    // Rename with .csv extension
                    $filename = pathinfo($filename, PATHINFO_FILENAME) . '.csv';
                    $file_extension = 'csv'; // Update extension for subsequent checks
                } else {
                    add_settings_error('csv_manager', 'csv_upload_failed', 'File does not appear to be a valid CSV. Please use a .csv file with comma-separated values.', 'error');
                    return;
                }
            } else {
                add_settings_error('csv_manager', 'csv_upload_failed', 'Could not read file to verify format. Please ensure it is a valid CSV file.', 'error');
                return;
            }
        }
        
        // Check for CSV file
        $mime_types = array(
            'text/csv', 
            'text/plain', 
            'application/csv', 
            'text/comma-separated-values', 
            'application/excel', 
            'application/vnd.ms-excel', 
            'application/vnd.msexcel',
            'text/anytext',
            'application/octet-stream', // Some systems use this for uploaded CSVs
        );
        
        // Get file mimetype - use finfo if available
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime_type = $finfo ? finfo_file($finfo, $file['tmp_name']) : $file['type'];
        if ($finfo) finfo_close($finfo);
        
        // For debugging
        // error_log('CSV Upload: File extension: ' . $file_extension . ', MIME type: ' . $mime_type);
        
        // First check the file extension (most reliable)
        if ($file_extension === 'csv') {
            // Then do a looser MIME type check since they're often wrong for CSVs
            $valid_mime = in_array(strtolower($mime_type), array_map('strtolower', $mime_types)) || 
                           strpos(strtolower($mime_type), 'text/') === 0 || 
                           strpos(strtolower($mime_type), 'application/csv') === 0 || // More specific application/csv
                           strpos(strtolower($mime_type), 'application/vnd.ms-excel') === 0; // Common for Excel-generated CSVs
                           
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
    
    // Handle deletion
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
 * Optimized function to query CSV files directly.
 * Reads all CSVs from the designated folder, extracts relevant rows based on keywords,
 * and returns a structured array of this data, optimized for token usage.
 *
 * @param string $query The user's search query.
 * @param int $max_chars_for_csv_data Approximate character limit for the CSV data payload.
 * @return array An array of structured data from CSVs, or an empty array if no relevant data found or fits.
 */
function chicago_loft_search_query_csv_documents($query, $max_chars_for_csv_data = 15000) {
    $upload_dir = wp_upload_dir();
    $csv_dir_path = $upload_dir['basedir'] . '/csv-documents';

    if (!file_exists($csv_dir_path) || !is_dir($csv_dir_path)) {
        // error_log('CSV_QUERY: CSV directory not found: ' . $csv_dir_path);
        return []; // No CSV directory, no results
    }

    $files = glob($csv_dir_path . '/*.csv');
    if (empty($files)) {
        // error_log('CSV_QUERY: No CSV files found in ' . $csv_dir_path);
        return []; // No CSV files found
    }

    $keywords = preg_split('/\\s+/', strtolower(trim($query)));
    $keywords = array_filter($keywords, function($kw) {
        return strlen(trim($kw)) > 2; // Filter out very short or empty keywords
    });

    if (empty($keywords)) {
        // error_log('CSV_QUERY: No meaningful keywords after filtering from query: ' . $query);
        return []; // No meaningful keywords to search
    }

    $relevant_data_payload = [];
    $current_total_chars_count = 0;

    // error_log('CSV_QUERY: Starting search with keywords: ' . implode(', ', $keywords) . ' and char limit: ' . $max_chars_for_csv_data);

    foreach ($files as $file_path) {
        if ($current_total_chars_count >= $max_chars_for_csv_data) {
            // error_log('CSV_QUERY: Character limit reached before processing file: ' . basename($file_path));
            break; // Stop if we've already hit the limit
        }

        if (($handle = fopen($file_path, 'r')) !== false) {
            $header = fgetcsv($handle);
            if (!$header || empty(array_filter($header, 'strlen'))) { // Ensure header is not empty or just empty strings
                fclose($handle);
                // error_log('CSV_QUERY: Skipped empty or invalid header in file: ' . basename($file_path));
                continue; // Skip empty or invalid CSV
            }
            $header = array_map('trim', $header);

            $current_file_matched_rows = [];
            $current_file_rows_chars_count = 0;

            // Estimate characters for this file's metadata (filename + column headers)
            // This is a rough estimate; actual JSON encoding might vary slightly.
            $file_metadata_for_estimation = ['source_file' => basename($file_path), 'columns' => $header, 'rows' => []];
            $file_metadata_chars_estimate = strlen(json_encode($file_metadata_for_estimation)) - strlen(json_encode([])); // Subtract empty rows part

            while (($row_values = fgetcsv($handle)) !== false) {
                if (count($header) !== count($row_values)) {
                    // error_log('CSV_QUERY: Malformed row in ' . basename($file_path) . '. Header count: ' . count($header) . ', Row count: ' . count($row_values));
                    continue; // Skip malformed rows
                }
                // Ensure row is not just empty strings
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
                        // error_log('CSV_QUERY: Added row from ' . basename($file_path) . '. Row chars: ' . $row_chars_estimate . '. File rows chars: ' . $current_file_rows_chars_count);
                    } else {
                        // error_log('CSV_QUERY: Row from ' . basename($file_path) . ' skipped, adding it would exceed char limit. Potential: ' . $potential_new_total_chars);
                        // Not enough space for this row. Stop processing this file.
                        // We could also decide to stop processing all files if we are very close to the limit.
                        goto next_file_in_loop; // Break from inner while loop, continue to next file or end.
                    }
                }
            }
            
            next_file_in_loop: // Label for goto
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
                    // error_log('CSV_QUERY: Added file block for ' . basename($file_path) . '. Block chars: ' . $this_file_block_chars . '. Total chars: ' . $current_total_chars_count);
                } else {
                    // error_log('CSV_QUERY: File block for ' . basename($file_path) . ' skipped, total data would exceed char limit. Current total: ' . $current_total_chars_count . ', this block: ' . $this_file_block_chars);
                    // If this file's content (even after row-level filtering) doesn't fit,
                    // and we've already accumulated some data, it's better to stop to avoid exceeding the limit.
                    if ($current_total_chars_count > 0) break; // Stop processing more files if we already have some data.
                }
            }
        } else {
            // error_log('CSV_QUERY: Could not open file: ' . $file_path);
        }
    }

    // error_log('CSV_QUERY: Finished processing. Total chars for payload: ' . $current_total_chars_count . '. Payload items: ' . count($relevant_data_payload));
    return $relevant_data_payload;
}
?>
