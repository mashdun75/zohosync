<?php
/**
 * Plugin Name: GF ↔ Zoho Sync
 * Description: Two-way sync between Gravity Forms and Zoho CRM/Desk modules.
 * Version:     1.0.0
 * Author:      Matt Duncan
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define basic constants
define('GF_ZOHO_SYNC_VERSION', '1.0.0');
define('GF_ZOHO_SYNC_PATH', plugin_dir_path(__FILE__));
define('GF_ZOHO_SYNC_URL', plugin_dir_url(__FILE__));

// Define log directory
define('GF_ZOHO_SYNC_LOGS_DIR', trailingslashit(wp_upload_dir()['basedir']) . 'gf-zoho-logs');

// Load dependencies
function gf_zoho_load_dependencies() {
    // Include the logger class first
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-logger.php';
    
    // Include the API class
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-zoho-api.php';
    
    // Include lookup handler
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-lookup-handler.php';
    
    // Include custom values handler
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-custom-values.php';
    
    // Include Zoho Desk integration
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-desk.php';
    
    // Include two-way sync
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-two-way-sync.php';
    
    // Include multi-module handler
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-multi-module.php';
    
    // Include GF integration class if Gravity Forms is active
    if (class_exists('GFForms')) {
        require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-direct.php';
    }
}
add_action('plugins_loaded', 'gf_zoho_load_dependencies', 5);

// Register the new menu structure
function gf_zoho_register_admin_menu() {
    // Only register if Gravity Forms is active
    if (!class_exists('GFForms')) {
        return;
    }
    
    // Main menu item under Forms
    add_submenu_page(
        'gf_edit_forms',         // Parent slug (Gravity Forms)
        'Zoho Sync',             // Page title
        'Zoho Sync',             // Menu title
        'gravityforms_edit_forms', // Capability
        'gf_zoho_sync_main',     // Menu slug
        'gf_zoho_render_main_page' // Function to display the page
    );
    
    // Settings submenu
    add_submenu_page(
        'gf_zoho_sync_main',      // Parent slug
        'Zoho Sync Settings',     // Page title
        'Settings',               // Menu title
        'gravityforms_edit_forms', // Capability
        'gf_zoho_sync_settings',  // Menu slug
        'gf_zoho_render_settings_page' // Function to display the page
    );
    
    // Form Mapping submenu
    add_submenu_page(
        'gf_zoho_sync_main',        // Parent slug
        'Zoho Sync Form Mapping',   // Page title
        'Form Mapping',             // Menu title
        'gravityforms_edit_forms',  // Capability
        'gf_zoho_sync_form_mapping', // Menu slug
        'gf_zoho_render_form_mapping_page' // Function to display the page
    );
    
    // History/Log submenu
    add_submenu_page(
        'gf_zoho_sync_main',      // Parent slug
        'Zoho Sync History',      // Page title
        'Sync History',           // Menu title
        'gravityforms_edit_forms', // Capability
        'gf_zoho_sync_history',   // Menu slug
        'gf_zoho_render_history_page' // Function to display the page
    );
}
add_action('admin_menu', 'gf_zoho_register_admin_menu', 15); // Higher priority to appear after GF menu

// Remove the old settings menu
function gf_zoho_remove_old_settings_menu() {
    remove_submenu_page('options-general.php', 'gf-zoho-sync');
}
add_action('admin_menu', 'gf_zoho_remove_old_settings_menu', 100);

// Enqueue admin assets
function gf_zoho_admin_assets($hook) {
    // Only load on our settings pages
    if (strpos($hook, 'gf_zoho_sync') === false) {
        return;
    }
    
    // Enqueue CSS
    wp_enqueue_style(
        'gf-zoho-admin',
        GF_ZOHO_SYNC_URL . 'assets/css/admin.css',
        array(),
        GF_ZOHO_SYNC_VERSION
    );
    
    // Enqueue JS
    wp_enqueue_script(
        'gf-zoho-admin',
        GF_ZOHO_SYNC_URL . 'assets/js/admin.js',
        array('jquery'),
        GF_ZOHO_SYNC_VERSION,
        true
    );
    
    // Add localization for AJAX
    wp_localize_script(
        'gf-zoho-admin',
        'gfZohoSync',
        array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gf_zoho_nonce')
        )
    );
}
add_action('admin_enqueue_scripts', 'gf_zoho_admin_assets');

// Page rendering functions
function gf_zoho_render_main_page() {
    include GF_ZOHO_SYNC_PATH . 'includes/admin/main-page.php';
}

function gf_zoho_render_settings_page() {
    include GF_ZOHO_SYNC_PATH . 'includes/admin/settings-page.php';
}

function gf_zoho_render_form_mapping_page() {
    include GF_ZOHO_SYNC_PATH . 'includes/admin/form-mapping-page.php';
}

function gf_zoho_render_history_page() {
    include GF_ZOHO_SYNC_PATH . 'includes/admin/history-page.php';
}

// Process OAuth Callback Before Headers Are Sent
add_action('init', 'gf_zoho_process_oauth_callback');
function gf_zoho_process_oauth_callback() {
    // Only run on our settings page
    if (!isset($_GET['page']) || $_GET['page'] !== 'gf_zoho_sync_settings') {
        return;
    }
    
    // Only process if we have a code and are an admin
    if (!isset($_GET['code']) || empty($_GET['code']) || !current_user_can('manage_options')) {
        return;
    }
    
    $code = sanitize_text_field($_GET['code']);
    
    // Create a unique key for this code to prevent duplicate processing
    $code_key = 'zoho_oauth_code_' . md5($code);
    
    // Check if we've already processed this code
    if (get_transient($code_key) !== false) {
        // Already processed - prevent duplicate handling
        error_log('Zoho OAuth Debug: Prevented duplicate processing of OAuth code');
        // Store message for display
        set_transient('zoho_oauth_message', [
            'type' => 'warning',
            'message' => 'OAuth callback already processed. Please try connecting again if needed.'
        ], 60);
    } else {
        // Set a transient to mark this code as being processed
        set_transient($code_key, 'processing', 60); // 60 second expiration
        
        // Process the code
        if (class_exists('Zoho_API')) {
            $api = new Zoho_API();
            $result = $api->handle_oauth_callback($code);
            
            if (isset($result['success']) && $result['success']) {
                // Success, mark the code as used
                set_transient($code_key, 'completed', HOUR_IN_SECONDS);
                // Store success message
                set_transient('zoho_oauth_message', [
                    'type' => 'success',
                    'message' => $result['message']
                ], 60);
                
                error_log('Zoho OAuth Debug: Successfully processed OAuth code');
            } else {
                // Error handling
                $error_message = isset($result['message']) ? $result['message'] : 'Unknown error';
                // Store error message
                set_transient('zoho_oauth_message', [
                    'type' => 'error',
                    'message' => $error_message
                ], 60);
                
                // If it's an invalid_code error, clear the transient so they can try again
                if (isset($result['error_code']) && $result['error_code'] === 'invalid_code') {
                    delete_transient($code_key);
                    error_log('Zoho OAuth Debug: Invalid code error - cleared transient for retry');
                }
            }
        }
    }
    
    // Redirect to remove the code from the URL to prevent accidental reuse
    wp_redirect(admin_url('admin.php?page=gf_zoho_sync_settings'));
    exit;
}

// Add restart auth trigger
add_action('init', 'gf_zoho_restart_auth');
function gf_zoho_restart_auth() {
    // Only run on our settings page
    if (!isset($_GET['page']) || $_GET['page'] !== 'gf_zoho_sync_settings') {
        return;
    }
    
    // Check if we should trigger the Zoho auth process
    if (isset($_GET['zoho_auth']) && $_GET['zoho_auth'] == '1' && current_user_can('manage_options')) {
        if (class_exists('Zoho_API')) {
            $api = new Zoho_API();
            $auth_url = $api->get_auth_url();
            
            if ($auth_url !== '#missing-client-id') {
                wp_redirect($auth_url);
                exit;
            } else {
                // Store error message
                set_transient('zoho_oauth_message', [
                    'type' => 'error',
                    'message' => 'Zoho Client ID is missing. Please enter your Client ID and Secret first.'
                ], 60);
                
                // Redirect back to settings page
                wp_redirect(admin_url('admin.php?page=gf_zoho_sync_settings'));
                exit;
            }
        }
    }
}

// Register activation hook
register_activation_hook(__FILE__, 'gf_zoho_activate');
function gf_zoho_activate() {
    // Add a simple option to indicate activation
    add_option('gf_zoho_sync_activated', time());
    
    // Schedule two-way sync cron
    if (!wp_next_scheduled('gf_zoho_sync_records')) {
        wp_schedule_event(time(), 'hourly', 'gf_zoho_sync_records');
    }
    
    // Create the log directory
    wp_mkdir_p(GF_ZOHO_SYNC_LOGS_DIR);
    
    // Add .htaccess to protect logs
    $htaccess = "# Disable directory browsing\nOptions -Indexes\n\n# Deny access to all files\n<FilesMatch \".*\">\nOrder Allow,Deny\nDeny from all\n</FilesMatch>";
    @file_put_contents(GF_ZOHO_SYNC_LOGS_DIR . '/.htaccess', $htaccess);
    
    // Log the activation
    error_log('Zoho API Debug: Plugin activated');
}

// Add deactivation hook
register_deactivation_hook(__FILE__, 'gf_zoho_deactivate');
function gf_zoho_deactivate() {
    // Just remove our activation marker
    delete_option('gf_zoho_sync_activated');
    
    // Clear scheduled cron
    wp_clear_scheduled_hook('gf_zoho_sync_records');
    
    // Log the deactivation
    error_log('Zoho API Debug: Plugin deactivated');
}

// Initialize logger
function gf_zoho_logger() {
    static $logger = null;
    
    if ($logger === null && class_exists('GF_Zoho_Logger')) {
        $logger = new GF_Zoho_Logger();
    }
    
    return $logger;
}