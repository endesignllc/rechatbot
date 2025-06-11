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
        'system_prompt' => 'You are a helpful assistant specializing in Chicago loft properties. Your primary goal is to answer user questions based on the provided Chicago Loft Data. If essential information like price, bedrooms, or square footage is missing for a specific listing, acknowledge this limitation in your response (e.g., "Price information is not available for this listing"). Use the "raw_data" field for additional context if primary fields are incomplete. Provide accurate information and keep responses concise and relevant to real estate inquiries only. Do not make up information if it is not present in the data.',
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
}

/**
 * The code that runs during plugin uninstallation.
 */
function uninstall_chicago_loft_search() {
    // If uninstall not called from WordPress, exit
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        exit;
    }
    
    // Delete options
    delete_option('chicago_loft_search_options');
    delete_option('chicago_loft_search_db_version');
    
    // Drop custom tables
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}chicago_loft_listings");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}chicago_loft_search_usage");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}chicago_loft_search_logs");
}

// Register activation, deactivation, and uninstall hooks
register_activation_hook(__FILE__, 'activate_chicago_loft_search');
register_deactivation_hook(__FILE__, 'deactivate_chicago_loft_search');
register_uninstall_hook(__FILE__, 'uninstall_chicago_loft_search');

/**
 * Create necessary database tables (defines the latest schema)
 */
function chicago_loft_search_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Table for storing loft listings
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        mls_id varchar(50) NOT NULL,
        address varchar(255) NOT NULL,
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
        raw_data longtext DEFAULT NULL,
        date_added datetime NOT NULL,
        date_updated datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY mls_id (mls_id),
        KEY neighborhood (neighborhood),
        KEY price (price),
        KEY bedrooms (bedrooms),
        KEY bathrooms (bathrooms),
        KEY square_feet (square_feet),
        KEY year_built (year_built),
        KEY status (status)
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

    if (version_compare($current_db_version, '1.0.2', '<')) { // Upgrade to 1.0.2 schema
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
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `$column_name` $column_definition");
            }
            $raw_data_column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$table_name` LIKE %s", 'raw_data'));
            if (empty($raw_data_column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN `raw_data` longtext DEFAULT NULL");
            }
        }
        update_option('chicago_loft_search_db_version', '1.0.2'); 
        $current_db_version = '1.0.2'; 
    }

    // Example for future upgrades:
    // if (version_compare($current_db_version, '1.0.3', '<')) {
    //     // Perform 1.0.3 specific DB changes if any
    //     update_option('chicago_loft_search_db_version', '1.0.3');
    // }
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
        'loft-search_page_chicago-loft-search-settings'
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
        'sync_nonce' => wp_create_nonce('chicago_loft_search_manual_sync'),
        'delete_listings_nonce' => wp_create_nonce('chicago_loft_search_delete_listings'), // For potential future individual delete
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
        'default_system_prompt' => __('You are a helpful assistant specializing in Chicago loft properties. Your primary goal is to answer user questions based on the provided Chicago Loft Data. If essential information like price, bedrooms, or square footage is missing for a specific listing, acknowledge this limitation in your response (e.g., "Price information is not available for this listing"). Use the "raw_data" field for additional context if primary fields are incomplete. Provide accurate information and keep responses concise and relevant to real estate inquiries only. Do not make up information if it is not present in the data.', 'chicago-loft-search'),
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
        __('Loft Listings', 'chicago-loft-search'),
        __('Loft Listings', 'chicago-loft-search'),
        'manage_options',
        'chicago-loft-search-listings',
        'chicago_loft_search_listings_page'
    );
    
    add_submenu_page(
        'chicago-loft-search-dashboard',
        __('Import MLS Data', 'chicago-loft-search'),
        __('Import MLS Data', 'chicago-loft-search'),
        'manage_options',
        'chicago-loft-search-import',
        'chicago_loft_search_import_page'
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

        // Save Example Questions
        if (isset($_POST['example_questions'])) {
            $example_questions_raw = sanitize_textarea_field($_POST['example_questions']);
            $options['example_questions'] = $example_questions_raw; 
        } else {
            // If the field is not submitted (e.g., if it were a checkbox that's unchecked),
            // this handles clearing it. For a textarea, it's usually submitted even if empty.
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
 * Loft Listings Table Class.
 */
class Chicago_Loft_Listings_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => __('Loft Listing', 'chicago-loft-search'),
            'plural'   => __('Loft Listings', 'chicago-loft-search'),
            'ajax'     => false
        ));
    }

    public function get_columns() {
        return array(
            'cb'           => '<input type="checkbox" />', 
            'mls_id'       => __('MLS ID', 'chicago-loft-search'),
            'address'      => __('Address', 'chicago-loft-search'),
            'neighborhood' => __('Neighborhood', 'chicago-loft-search'),
            'price'        => __('Price', 'chicago-loft-search'),
            'bedrooms'     => __('Beds', 'chicago-loft-search'),
            'bathrooms'    => __('Baths', 'chicago-loft-search'),
            'square_feet'  => __('SqFt', 'chicago-loft-search'),
            'status'       => __('Status', 'chicago-loft-search'),
            'date_updated' => __('Last Updated', 'chicago-loft-search'),
        );
    }

    public function get_sortable_columns() {
        return array(
            'mls_id'       => array('mls_id', false),
            'address'      => array('address', false),
            'neighborhood' => array('neighborhood', false),
            'price'        => array('price', false),
            'bedrooms'     => array('bedrooms', false),
            'bathrooms'    => array('bathrooms', false),
            'square_feet'  => array('square_feet', false),
            'status'       => array('status', false),
            'date_updated' => array('date_updated', true) 
        );
    }

    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="listing_id[]" value="%s" />', $item['id']
        );
    }
    
    protected function column_price($item) {
        if ($item['price'] === null) {
            return __('N/A', 'chicago-loft-search');
        }
        return '$' . number_format_i18n($item['price']);
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'mls_id':
            case 'address':
            case 'neighborhood':
            case 'bedrooms':
            case 'bathrooms':
            case 'square_feet':
            case 'status':
                return esc_html($item[$column_name] ?: __('N/A', 'chicago-loft-search'));
            case 'date_updated':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name]));
            default:
                return print_r($item, true); 
        }
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'chicago_loft_listings';

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name");

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
        $order = (!empty($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), array('ASC', 'DESC'))) ? $_REQUEST['order'] : 'DESC';
        
        $query = "SELECT * FROM $table_name";

        if (!empty($_REQUEST['s'])) {
            $search_term = '%' . $wpdb->esc_like(sanitize_text_field($_REQUEST['s'])) . '%';
            $query .= $wpdb->prepare(" WHERE (mls_id LIKE %s OR address LIKE %s OR neighborhood LIKE %s)", $search_term, $search_term, $search_term);
        }

        $query .= " ORDER BY $orderby $order";
        $query .= " LIMIT $per_page";
        $query .= " OFFSET " . (($current_page - 1) * $per_page);
        
        $this->items = $wpdb->get_results($query, ARRAY_A);
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
                        sprintf(_n('%d listing deleted.', '%d listings deleted.', $deleted_count, 'chicago-loft-search'), $deleted_count),
                        'updated'
                    );
                } else {
                     add_settings_error(
                        'chicago_loft_search_listings_messages',
                        'listings_delete_error',
                        __('Error deleting listings. Please try again.', 'chicago-loft-search'),
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
    echo '<h1>' . __('Manage Loft Listings', 'chicago-loft-search') . '</h1>';
    
    settings_errors('chicago_loft_search_listings_messages');

    echo '<div id="loft-listings-summary" style="margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border: 1px solid #e3e3e3;">';
    echo '<h3>' . __('Summary', 'chicago-loft-search') . '</h3>';
    echo '<p><strong>' . __('Total Listings Imported:', 'chicago-loft-search') . '</strong> ' . esc_html($total_listings) . '</p>';
    if (!empty($last_sync_date)) {
        echo '<p><strong>' . __('Last MLS Data Sync:', 'chicago-loft-search') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync_date)) . '</p>';
    } else {
        echo '<p><strong>' . __('Last MLS Data Sync:', 'chicago-loft-search') . '</strong> ' . __('Never synced or no data imported yet.', 'chicago-loft-search') . '</p>';
    }
     echo '<p><button type="button" id="delete-all-listings-button" class="button button-danger">' . __('Delete All Listings', 'chicago-loft-search') . '</button></p>';
    echo '</div>';

    $listings_table = new Chicago_Loft_Listings_Table();
    $listings_table->prepare_items();
    
    echo '<form method="post">'; 
    wp_nonce_field( 'bulk-' . $listings_table->_args['plural'] );
    $listings_table->search_box(__('Search Listings', 'chicago-loft-search'), 'chicago-loft-search');
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
    $example_questions = array_map('trim', explode("\n", $example_questions_setting));
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
    }
    
    if (!chicago_loft_search_user_can_search()) {
        wp_send_json_error(array('message' => __('You do not have permission to use this search feature.', 'chicago-loft-search')));
    }
    
    if (chicago_loft_search_user_reached_limit()) {
        wp_send_json_error(array('message' => __('You have reached your search limit for today. Please try again tomorrow.', 'chicago-loft-search')));
    }
    
    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    
    if (empty($query)) {
        wp_send_json_error(array('message' => __('Please enter a search query.', 'chicago-loft-search')));
    }
    
    $options = get_option('chicago_loft_search_options');
    $api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
    $model = isset($options['model']) ? $options['model'] : 'gpt-4o';
    $system_prompt = isset($options['system_prompt']) ? $options['system_prompt'] : '';
    
    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('API key not configured. Please contact the administrator.', 'chicago-loft-search')));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    
    $keywords = chicago_loft_search_extract_keywords($query);
    
    $sql = "SELECT * FROM $table_name WHERE 1=1";
    
    if (!empty($keywords)) {
        $sql .= " AND (";
        $conditions = array();
        
        foreach ($keywords as $keyword) {
            $keyword_like = '%' . $wpdb->esc_like($keyword) . '%'; 
            $conditions[] = $wpdb->prepare("neighborhood LIKE %s", $keyword_like);
            $conditions[] = $wpdb->prepare("address LIKE %s", $keyword_like);
            $conditions[] = $wpdb->prepare("features LIKE %s", $keyword_like);
            $conditions[] = $wpdb->prepare("description LIKE %s", $keyword_like);
        }
        
        $sql .= implode(" OR ", $conditions);
        $sql .= ")";
    }
    
    $sql .= " AND status = 'active'";
    
    $sql .= " LIMIT 50";
    
    $listings = $wpdb->get_results($sql);
    
    $listing_data = array();
    foreach ($listings as $listing) {
        $listing_data[] = array(
            'mls_id' => $listing->mls_id,
            'address' => $listing->address,
            'neighborhood' => $listing->neighborhood,
            'price' => $listing->price,
            'bedrooms' => $listing->bedrooms,
            'bathrooms' => $listing->bathrooms,
            'square_feet' => $listing->square_feet,
            'year_built' => $listing->year_built,
            'features' => $listing->features,
            'description' => $listing->description,
            'raw_data' => json_decode($listing->raw_data, true) 
        );
    }
    
    $request_data = array(
        'model' => $model,
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $system_prompt . "\n\nAvailable Chicago Loft Data: " . json_encode($listing_data)
            ),
            array(
                'role' => 'user',
                'content' => $query
            )
        ),
        'temperature' => 0.7,
        'max_tokens' => 1000
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
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (!isset($data['choices'][0]['message']['content'])) {
        wp_send_json_error(array('message' => __('Invalid response from API.', 'chicago-loft-search')));
    }
    
    $content = $data['choices'][0]['message']['content'];
    
    $tokens_used = isset($data['usage']['total_tokens']) ? $data['usage']['total_tokens'] : 0;
    
    chicago_loft_search_update_usage_count();
    
    chicago_loft_search_log_query($query, $content, $tokens_used);
    
    $formatted_response = chicago_loft_search_format_response($content, $listing_data);
    
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
    
    preg_match_all('/\$?(\d+[,\\.]?\d*)\s*(k|thousand|million|m|sq\s*ft|square\s*feet|bedroom|bed|bath|bathroom)?/i', $query, $matches);
    
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
    
    $formatted = preg_replace_callback('/(^\s*[\-\*\+]\s+.*(?:\n^\s*[\-\*\+]\s+.*)*)/m', function($matches) {
        $list_items = preg_replace('/^\s*[\-\*\+]\s+(.*)/m', '<li>$1</li>', $matches[0]);
        return '<ul>' . $list_items . '</ul>';
    }, $formatted);
    
    $formatted = preg_replace_callback('/(^\s*\d+\.\s+.*(?:\n^\s*\d+\.\s+.*)*)/m', function($matches) {
        $list_items = preg_replace('/^\s*\d+\.\s+(.*)/m', '<li>$1</li>', $matches[0]);
        return '<ol>' . $list_items . '</ol>';
    }, $formatted);
    
    $formatted = nl2br($formatted);
    $formatted = preg_replace_callback('/<(ul|ol)>(.*?)<\/\1>/is', function($matches) {
        return '<' . $matches[1] . '>' . str_replace('<br />', '', $matches[2]) . '</' . $matches[1] . '>';
    }, $formatted);


    foreach ($listing_data as $listing) {
        $mls_id = $listing['mls_id'];
        if (strpos($formatted, $mls_id) !== false) {
            $pattern = '/(?<!href="[^"]*|data-mls-id="[^"]*)' . preg_quote($mls_id, '/') . '(?![^"]*"\s*>|[^<]*<\/a>)/';
            $listing_url = home_url('/loft/' . $mls_id); 
            $replacement = '<a href="' . esc_url($listing_url) . '" class="chicago-loft-link" data-mls-id="' . esc_attr($mls_id) . '">' . esc_html($mls_id) . '</a>';
            $formatted = preg_replace($pattern, $replacement, $formatted);
        }
    }
    
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
    
    foreach ($data['listings'] as $listing) {
        if (!isset($listing['mls_id']) || empty($listing['mls_id'])) {
            $errors++;
            continue;
        }
        
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table_name WHERE mls_id = %s",
                $listing['mls_id']
            )
        );
        
        $listing_data = array(
            'mls_id' => sanitize_text_field($listing['mls_id']),
            'address' => isset($listing['address']) ? sanitize_text_field($listing['address']) : '',
            'neighborhood' => isset($listing['neighborhood']) ? sanitize_text_field($listing['neighborhood']) : null,
            'price' => isset($listing['price']) && $listing['price'] !== '' ? floatval($listing['price']) : null,
            'bedrooms' => isset($listing['bedrooms']) && $listing['bedrooms'] !== '' ? intval($listing['bedrooms']) : null,
            'bathrooms' => isset($listing['bathrooms']) && $listing['bathrooms'] !== '' ? floatval($listing['bathrooms']) : null,
            'square_feet' => isset($listing['square_feet']) && $listing['square_feet'] !== '' ? intval($listing['square_feet']) : null,
            'year_built' => isset($listing['year_built']) && $listing['year_built'] !== '' ? intval($listing['year_built']) : null,
            'features' => isset($listing['features']) ? sanitize_text_field($listing['features']) : null,
            'description' => isset($listing['description']) ? sanitize_textarea_field($listing['description']) : null,
            'image_urls' => isset($listing['image_urls']) && is_array($listing['image_urls']) ? json_encode(array_map('esc_url_raw', $listing['image_urls'])) : '[]',
            'status' => isset($listing['status']) ? sanitize_text_field($listing['status']) : 'active',
            'raw_data' => json_encode($listing), 
            'date_updated' => current_time('mysql'),
        );
        
        $format = array(
            '%s', '%s', '%s', '%f', '%d', '%f', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' 
        );
        
        if ($existing) {
            $result = $wpdb->update(
                $table_name,
                $listing_data,
                array('mls_id' => $listing['mls_id']),
                $format,
                array('%s')
            );
            
            if ($result !== false) {
                $updated++;
            } else {
                $errors++;
            }
        } else {
            $listing_data['date_added'] = current_time('mysql');
            $format[] = '%s'; 
            
            $result = $wpdb->insert(
                $table_name,
                $listing_data,
                $format
            );
            
            if ($result) {
                $imported++;
            } else {
                $errors++;
            }
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
    
    $token_cost_per_thousand = 0.03;
    $today_cost = number_format(($today_tokens / 1000) * $token_cost_per_thousand, 2);
    $month_cost = number_format(($month_tokens / 1000) * $token_cost_per_thousand, 2);
    
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
        $options['openai_api_key'] = '';
    }
    
    $export_data = array(
        'plugin_name' => 'Chicago Loft Search',
        'plugin_version' => CHICAGO_LOFT_SEARCH_VERSION,
        'export_date' => current_time('mysql'),
        'settings' => $options
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
    
    if (!isset($import_data['settings']['openai_api_key']) || empty($import_data['settings']['openai_api_key'])) {
        $import_data['settings']['openai_api_key'] = $current_options['openai_api_key'];
    }
    
    update_option('chicago_loft_search_options', $import_data['settings']);
    
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
    
    $response = wp_remote_get(
        $mls_api_endpoint,
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $mls_api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 60,
        )
    );
    
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
        wp_send_json_error(array('message' => __('Invalid response from MLS API.', 'chicago-loft-search')));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $imported = 0;
    $updated = 0;
    $errors = 0;
    
    foreach ($data['listings'] as $listing) {
        if (!isset($listing['mls_id']) || empty($listing['mls_id'])) {
            $errors++;
            continue;
        }
        
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table_name WHERE mls_id = %s",
                $listing['mls_id']
            )
        );
        
        $listing_data = array(
            'mls_id' => sanitize_text_field($listing['mls_id']),
            'address' => isset($listing['address']) ? sanitize_text_field($listing['address']) : '',
            'neighborhood' => isset($listing['neighborhood']) ? sanitize_text_field($listing['neighborhood']) : null,
            'price' => isset($listing['price']) && $listing['price'] !== '' ? floatval($listing['price']) : null,
            'bedrooms' => isset($listing['bedrooms']) && $listing['bedrooms'] !== '' ? intval($listing['bedrooms']) : null,
            'bathrooms' => isset($listing['bathrooms']) && $listing['bathrooms'] !== '' ? floatval($listing['bathrooms']) : null,
            'square_feet' => isset($listing['square_feet']) && $listing['square_feet'] !== '' ? intval($listing['square_feet']) : null,
            'year_built' => isset($listing['year_built']) && $listing['year_built'] !== '' ? intval($listing['year_built']) : null,
            'features' => isset($listing['features']) ? sanitize_text_field($listing['features']) : null,
            'description' => isset($listing['description']) ? sanitize_textarea_field($listing['description']) : null,
            'image_urls' => isset($listing['image_urls']) && is_array($listing['image_urls']) ? json_encode(array_map('esc_url_raw', $listing['image_urls'])) : '[]',
            'status' => isset($listing['status']) ? sanitize_text_field($listing['status']) : 'active',
            'raw_data' => json_encode($listing), 
            'date_updated' => current_time('mysql'),
        );
        
        $format = array(
            '%s', '%s', '%s', '%f', '%d', '%f', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' 
        );
        
        if ($existing) {
            $result = $wpdb->update(
                $table_name,
                $listing_data,
                array('mls_id' => $listing['mls_id']),
                $format,
                array('%s')
            );
            
            if ($result !== false) {
                $updated++;
            } else {
                $errors++;
            }
        } else {
            $listing_data['date_added'] = current_time('mysql');
            $format[] = '%s'; 
            
            $result = $wpdb->insert(
                $table_name,
                $listing_data,
                $format
            );
            
            if ($result) {
                $imported++;
            } else {
                $errors++;
            }
        }
    }
    
    $options['last_sync_date'] = current_time('mysql');
    update_option('chicago_loft_search_options', $options);
    
    $message = sprintf(
        __('Sync completed: %d imported, %d updated, %d errors.', 'chicago-loft-search'),
        $imported,
        $updated,
        $errors
    );
    
    wp_send_json_success(array('message' => $message));
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
        wp_send_json_error(array('message' => __('Loft not found or not active.', 'chicago-loft-search')));
    }
    
    $image_urls = json_decode($loft->image_urls, true);
    $images_html = '';
    
    if (is_array($image_urls) && !empty($image_urls)) {
        $images_html .= '<div class="loft-images-slider">';
        foreach ($image_urls as $url) {
            $images_html .= '<div class="loft-image-slide">';
            $images_html .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($loft->address) . '" class="loft-image">';
            $images_html .= '</div>';
        }
        $images_html .= '</div>';
    }
    
    $features_array = explode(',', $loft->features);
    $features_html = '<ul class="loft-features-list">';
    foreach ($features_array as $feature) {
        $feature = trim($feature);
        if (!empty($feature)) {
            $features_html .= '<li>' . esc_html($feature) . '</li>';
        }
    }
    $features_html .= '</ul>';
    
    $html = '
        <div class="loft-details">
            <h2 id="modal-title" class="loft-title">' . esc_html($loft->address) . '</h2>
            <div class="loft-neighborhood">' . esc_html($loft->neighborhood) . '</div>
            
            <div class="loft-price-info">
                <div class="loft-price">' . ($loft->price ? '$' . number_format_i18n($loft->price) : __('Price not available', 'chicago-loft-search')) . '</div>
                <div class="loft-specs">
                    <span class="loft-beds">' . ($loft->bedrooms ? esc_html($loft->bedrooms) . ' ' . _n('Bed', 'Beds', $loft->bedrooms, 'chicago-loft-search') : __('Beds not available', 'chicago-loft-search')) . '</span>
                    <span class="loft-baths">' . ($loft->bathrooms ? esc_html($loft->bathrooms) . ' ' . _n('Bath', 'Baths', $loft->bathrooms, 'chicago-loft-search') : __('Baths not available', 'chicago-loft-search')) . '</span>
                    <span class="loft-sqft">' . ($loft->square_feet ? number_format_i18n($loft->square_feet) . ' ' . __('sq ft', 'chicago-loft-search') : __('SqFt not available', 'chicago-loft-search')) . '</span>
                </div>
            </div>
            
            ' . $images_html . '
            
            <div class="loft-description">
                <h3>' . __('Description', 'chicago-loft-search') . '</h3>
                <p>' . nl2br(esc_html($loft->description ?: __('Description not available.', 'chicago-loft-search'))) . '</p>
            </div>
            
            <div class="loft-details-grid">
                <div class="loft-details-column">
                    <h3>' . __('Property Details', 'chicago-loft-search') . '</h3>
                    <table class="loft-details-table">
                        <tr>
                            <th>' . __('MLS ID', 'chicago-loft-search') . ':</th>
                            <td>' . esc_html($loft->mls_id) . '</td>
                        </tr>
                        <tr>
                            <th>' . __('Year Built', 'chicago-loft-search') . ':</th>
                            <td>' . ($loft->year_built ? esc_html($loft->year_built) : __('N/A', 'chicago-loft-search')) . '</td>
                        </tr>
                        <tr>
                            <th>' . __('Square Feet', 'chicago-loft-search') . ':</th>
                            <td>' . ($loft->square_feet ? number_format_i18n($loft->square_feet) : __('N/A', 'chicago-loft-search')) . '</td>
                        </tr>';
    if ($loft->square_feet > 0 && $loft->price > 0) { 
        $html .= '<tr>
                            <th>' . __('Price per sq ft', 'chicago-loft-search') . ':</th>
                            <td>$' . number_format_i18n($loft->price / $loft->square_feet, 2) . '</td>
                        </tr>';
    }
    $html .= '</table>
                </div>
                
                <div class="loft-details-column">
                    <h3>' . __('Features', 'chicago-loft-search') . '</h3>
                    ' . ($loft->features ? $features_html : __('Features not available.', 'chicago-loft-search')) . '
                </div>
            </div>
            
            <div class="loft-actions">
                <button class="loft-contact-button">' . __('Contact Agent', 'chicago-loft-search') . '</button>
                <button class="loft-schedule-button">' . __('Schedule Viewing', 'chicago-loft-search') . '</button>
            </div>
        </div>
    ';
    
    wp_send_json_success(array('html' => $html));
}
add_action('wp_ajax_chicago_loft_search_get_loft_details', 'chicago_loft_search_get_loft_details');
add_action('wp_ajax_nopriv_chicago_loft_search_get_loft_details', 'chicago_loft_search_get_loft_details');

// --- CSV Import Helper Functions ---

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
function _chicago_loft_search_generate_mls_id_from_data($address_string) {
    return sanitize_title($address_string . '-' . substr(md5(uniqid(rand(), true)), 0, 8));
}

/**
 * Helper to extract neighborhood.
 */
function _chicago_loft_search_extract_neighborhood_from_string($city_neighborhood_string) {
    if (empty($city_neighborhood_string)) return '';
    $parts = explode(',', $city_neighborhood_string);
    return trim(isset($parts[1]) ? $parts[1] : $parts[0]);
}

/**
 * Helper to construct features string from CSV row.
 */
function _chicago_loft_search_construct_features_from_csv_row($csv_row_assoc) {
    $features_list = array();
    $feature_columns_map = array(
        'Parking' => 'Parking Available', 
        'ParkingType' => 'Parking Type: %s', 
        'FHA Approved' => 'FHA Approved', 
        'Pets Allowed' => 'Pets Allowed', 
        'PetsInfo' => 'Pet Info: %s', 
        'Amenities' => 'Amenities: %s', 
        'ExposedBrick' => 'Exposed Brick',
        'ExposedDuctwork' => 'Exposed Ductwork',
        'ConcreteCeiling' => 'Concrete Ceiling',
        'ConcretePosts' => 'Concrete Posts',
        'TimberCeiling' => 'Timber Ceiling',
        'TimberPosts' => 'Timber Posts',
        'OutdoorSpace' => 'Outdoor Space', 
        'Deck' => 'Deck', 
        'Balcony' => 'Balcony', 
        'RoofDeckShared' => 'Shared Roof Deck', 
        'RoofDeckPrivate' => 'Private Roof Deck', 
        'HardwoodFloors' => 'Hardwood Floors', 
        'OversizedWindows' => 'Oversized Windows', 
        'SoftLoft' => 'Soft Loft', 
        'HardLoft' => 'Hard Loft' 
    );

    foreach ($feature_columns_map as $csv_col_name => $feature_text_format) {
        if (isset($csv_row_assoc[$csv_col_name]) && !empty(trim($csv_row_assoc[$csv_col_name]))) {
            $value = trim($csv_row_assoc[$csv_col_name]);
            if (strtoupper($value) === 'Y') {
                $features_list[] = $feature_text_format;
            } elseif (strpos($feature_text_format, '%s') !== false) {
                $features_list[] = sprintf($feature_text_format, $value);
            }
        }
    }
    return implode(', ', array_filter($features_list));
}

/**
 * Helper to construct description from CSV row.
 */
function _chicago_loft_search_construct_description_from_csv_row($csv_row_assoc) {
    $description_parts = array();
    if (!empty($csv_row_assoc['BuildingName'])) $description_parts[] = trim($csv_row_assoc['BuildingName']) . ".";
    if (!empty($csv_row_assoc['Type'])) $description_parts[] = "Type: " . trim($csv_row_assoc['Type']) . ".";
    if (!empty($csv_row_assoc['Stories'])) $description_parts[] = trim($csv_row_assoc['Stories']) . " stories.";
    if (!empty($csv_row_assoc['Units'])) $description_parts[] = trim($csv_row_assoc['Units']) . " units in building.";
    if (empty($description_parts)) $description_parts[] = "Loft property.";
    
    return implode(' ', $description_parts);
}


// --- AJAX Handlers for CSV Import ---

/**
 * AJAX handler for parsing CSV and returning preview data.
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
    $preview_data = array();
    $raw_data = array();
    $header = null;

    if (($handle = fopen($file_path, 'r')) !== false) {
        while (($row = fgetcsv($handle)) !== false) {
            if (!$header) {
                $header = array_map('trim', $row); 
                continue;
            }
            if (count($header) !== count($row)) { 
                continue;
            }
            $csv_row_assoc = array_combine($header, $row);
            $raw_data[] = $csv_row_assoc; 

            $address_full = isset($csv_row_assoc['Address']) ? trim($csv_row_assoc['Address']) : '';
            $year_built = isset($csv_row_assoc['YearBuilt']) && $csv_row_assoc['YearBuilt'] !== '' ? intval($csv_row_assoc['YearBuilt']) : null;
            $city_neighborhood = isset($csv_row_assoc['City and Neighborhood']) ? trim($csv_row_assoc['City and Neighborhood']) : '';
            
            $preview_item = array(
                'original_address' => $address_full,
                'mls_id' => _chicago_loft_search_generate_mls_id_from_data($address_full),
                'address' => $address_full,
                'neighborhood' => _chicago_loft_search_extract_neighborhood_from_string($city_neighborhood),
                'price' => null, 
                'bedrooms' => null, 
                'bathrooms' => null, 
                'square_feet' => null, 
                'year_built' => $year_built,
                'description' => _chicago_loft_search_construct_description_from_csv_row($csv_row_assoc),
                'image_urls' => '', 
                'features_preview' => substr(_chicago_loft_search_construct_features_from_csv_row($csv_row_assoc), 0, 100) . '...' 
            );
            $preview_data[] = $preview_item;
        }
        fclose($handle);
    } else {
        wp_send_json_error(array('message' => __('Could not open CSV file.', 'chicago-loft-search')));
    }

    if (empty($preview_data)) {
        wp_send_json_error(array('message' => __('No data found in CSV or CSV format error (ensure header row exists).', 'chicago-loft-search')));
    }

    wp_send_json_success(array('preview_data' => $preview_data, 'raw_data' => $raw_data));
}
add_action('wp_ajax_chicago_loft_search_parse_csv_preview', 'chicago_loft_search_parse_csv_preview');

/**
 * AJAX handler for importing a batch of listings.
 */
function chicago_loft_search_import_listings_batch() {
    check_ajax_referer('chicago_loft_search_import_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'chicago-loft-search')));
    }

    if (!isset($_POST['listings'])) {
        wp_send_json_error(array('message' => __('No listings data received.', 'chicago-loft-search')));
    }

    $listings_json = stripslashes($_POST['listings']);
    $listings_batch = json_decode($listings_json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($listings_batch)) {
        wp_send_json_error(array('message' => __('Invalid listings data format: ' . json_last_error_msg(), 'chicago-loft-search')));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chicago_loft_listings';
    $batch_results = array();

    foreach ($listings_batch as $item) {
        $original_csv_data = isset($item['original_csv_data']) && is_array($item['original_csv_data']) ? $item['original_csv_data'] : array();
        
        $mls_id = !empty($item['mls_id']) ? sanitize_text_field($item['mls_id']) : _chicago_loft_search_generate_mls_id_from_data(isset($original_csv_data['Address']) ? $original_csv_data['Address'] : uniqid());
        $address = !empty($item['address']) ? sanitize_text_field($item['address']) : (isset($original_csv_data['Address']) ? trim($original_csv_data['Address']) : 'N/A');
        $neighborhood = !empty($item['neighborhood']) ? sanitize_text_field($item['neighborhood']) : _chicago_loft_search_extract_neighborhood_from_string(isset($original_csv_data['City and Neighborhood']) ? $original_csv_data['City and Neighborhood'] : '');
        
        $data_to_insert = array(
            'mls_id' => $mls_id,
            'address' => $address,
            'neighborhood' => $neighborhood,
            'price' => isset($item['price']) && $item['price'] !== '' ? floatval($item['price']) : null,
            'bedrooms' => isset($item['bedrooms']) && $item['bedrooms'] !== '' ? intval($item['bedrooms']) : null,
            'bathrooms' => isset($item['bathrooms']) && $item['bathrooms'] !== '' ? floatval($item['bathrooms']) : null,
            'square_feet' => isset($item['square_feet']) && $item['square_feet'] !== '' ? intval($item['square_feet']) : null,
            'year_built' => isset($original_csv_data['YearBuilt']) && $original_csv_data['YearBuilt'] !== '' ? intval($original_csv_data['YearBuilt']) : (isset($item['year_built']) && $item['year_built'] !== '' ? intval($item['year_built']) : null),
            'features' => _chicago_loft_search_construct_features_from_csv_row($original_csv_data),
            'description' => !empty($item['description']) ? sanitize_textarea_field($item['description']) : _chicago_loft_search_construct_description_from_csv_row($original_csv_data),
            'image_urls' => isset($item['image_urls']) && is_array($item['image_urls']) ? json_encode(array_map('esc_url_raw', $item['image_urls'])) : '[]',
            'status' => sanitize_text_field(isset($item['status']) ? $item['status'] : 'active'),
            'raw_data' => json_encode($original_csv_data),
            'date_updated' => current_time('mysql'),
        );
        
        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE mls_id = %s", $data_to_insert['mls_id']));

        $format = array(
            '%s', 
            '%s', 
            '%s', 
            $data_to_insert['price'] === null ? null : '%f',
            $data_to_insert['bedrooms'] === null ? null : '%d',
            $data_to_insert['bathrooms'] === null ? null : '%f',
            $data_to_insert['square_feet'] === null ? null : '%d',
            $data_to_insert['year_built'] === null ? null : '%d',
            '%s', 
            '%s', 
            '%s', 
            '%s', 
            '%s', 
            '%s'  
        );
        $filtered_format = array_values(array_filter($format, function($f) { return $f !== null; }));


        if ($existing_id) {
            $result = $wpdb->update($table_name, $data_to_insert, array('id' => $existing_id), $filtered_format, array('%d'));
            if ($result !== false) {
                $batch_results[] = array('success' => true, 'message' => sprintf(__('Updated MLS ID %s: %s', 'chicago-loft-search'), $data_to_insert['mls_id'], $data_to_insert['address']));
            } else {
                $batch_results[] = array('success' => false, 'message' => sprintf(__('Error updating MLS ID %s: %s. DB Error: %s', 'chicago-loft-search'), $data_to_insert['mls_id'], $data_to_insert['address'], $wpdb->last_error));
            }
        } else {
            $data_to_insert['date_added'] = current_time('mysql');
            $current_format = $filtered_format; 
            $current_format[] = '%s'; 
            
            $result = $wpdb->insert($table_name, $data_to_insert, $current_format);
            if ($result) {
                $batch_results[] = array('success' => true, 'message' => sprintf(__('Imported MLS ID %s: %s', 'chicago-loft-search'), $data_to_insert['mls_id'], $data_to_insert['address']));
            } else {
                $batch_results[] = array('success' => false, 'message' => sprintf(__('Error importing MLS ID %s: %s. DB Error: %s', 'chicago-loft-search'), $data_to_insert['mls_id'], $data_to_insert['address'], $wpdb->last_error));
            }
        }
    }

    wp_send_json_success(array('results' => $batch_results));
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
        wp_send_json_success(array('message' => __('All loft listings have been deleted.', 'chicago-loft-search')));
    } else {
        wp_send_json_error(array('message' => __('Error deleting listings. Please try again.', 'chicago-loft-search')));
    }
}
add_action('wp_ajax_chicago_loft_search_delete_all_listings', 'chicago_loft_search_delete_all_listings_ajax');
