<?php
/**
 * Uninstall routine for GF Zoho Sync
 * 
 * This file is called when the plugin is uninstalled via the WordPress admin.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Initialize logging if available
if (class_exists('GFCommon')) {
    GFCommon::log_debug('GF Zoho Sync: Plugin uninstalling');
}

// Drop mapping table
global $wpdb;
$table_name = $wpdb->prefix . 'gf_zoho_map';

if (class_exists('GFCommon')) {
    GFCommon::log_debug('GF Zoho Sync: Dropping table ' . $table_name);
}

$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete options
$options = [
    'gf_zoho_tokens',
    'gf_zoho_client_id',
    'gf_zoho_client_secret',
    'gf_zoho_api_domain',
    'gf_zoho_webhook_id',
    'gf_zoho_webhook_token',
    'gf_zoho_default_department_id'
];

foreach ($options as $option) {
    if (class_exists('GFCommon')) {
        GFCommon::log_debug('GF Zoho Sync: Deleting option ' . $option);
    }
    delete_option($option);
}

if (class_exists('GFCommon')) {
    GFCommon::log_debug('GF Zoho Sync: Uninstallation complete');
}
