<?php
/**
 * Plugin Name: GF ↔ Zoho Sync
 * Description: Two-way sync between Gravity Forms and Zoho CRM/Desk modules.
 * Version:     1.0.0
 * Author:      Matt Duncan
 */
if (!defined('ABSPATH')) exit;

// Define constants
define('GF_ZOHO_SYNC_VERSION', '1.0.0');
define('GF_ZOHO_SYNC_PATH', plugin_dir_path(__FILE__));
define('GF_ZOHO_SYNC_URL', plugin_dir_url(__FILE__));

// Initialize logging if possible
function gf_zoho_init_logging() {
    if (class_exists('GFCommon')) {
        GFCommon::log_debug('GF Zoho Sync: Plugin initializing');
    }
}
add_action('init', 'gf_zoho_init_logging', 5);

// Core includes
require GF_ZOHO_SYNC_PATH . '/includes/class-gf-zoho-addon.php';
require GF_ZOHO_SYNC_PATH . '/includes/class-zoho-api.php';
require GF_ZOHO_SYNC_PATH . '/includes/settings-page.php';
require GF_ZOHO_SYNC_PATH . '/includes/class-gf-zoho-webhook.php';
require GF_ZOHO_SYNC_PATH . '/includes/class-gf-zoho-mapping.php';

// Integration modules
foreach (glob(GF_ZOHO_SYNC_PATH . '/includes/integration/class-sync-*.php') as $file) {
    require_once $file;
}

// Activation: create mapping table
register_activation_hook(__FILE__, 'gf_zoho_activate');
function gf_zoho_activate() {
    if (class_exists('GFCommon')) {
        GFCommon::log_debug('GF Zoho Sync: Plugin activating');
    }
    GFZohoMapping::install_table();
}

// Deactivation: remove Zoho webhook
register_deactivation_hook(__FILE__, 'gf_zoho_deactivate');
function gf_zoho_deactivate() {
    if (class_exists('GFCommon')) {
        GFCommon::log_debug('GF Zoho Sync: Plugin deactivating');
    }
    
    $webhook_id = get_option('gf_zoho_webhook_id');
    if ($webhook_id) {
        if (class_exists('GFCommon')) {
            GFCommon::log_debug('GF Zoho Sync: Removing webhook ID: ' . $webhook_id);
        }
        
        $api = new Zoho_API();
        $api->remove_webhook($webhook_id);
        delete_option('gf_zoho_webhook_id');
        delete_option('gf_zoho_webhook_token');
    }
}

// Launch the Add-On
add_action('gform_loaded', 'gf_zoho_bootstrap');
function gf_zoho_bootstrap() {
    if (!class_exists('GFAddOn')) {
        if (class_exists('GFCommon')) {
            GFCommon::log_debug('GF Zoho Sync: GFAddOn class not found, addon not initialized');
        }
        return;
    }
    
    if (class_exists('GFCommon')) {
        GFCommon::log_debug('GF Zoho Sync: Initializing addon');
    }
    
    GFZohoSyncAddOn::get_instance();
}
