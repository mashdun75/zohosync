<?php
/**
 * Plugin Name: GF ↔ Zoho Sync
 * Description: Two-way sync between Gravity Forms and Zoho CRM/Desk modules.
 * Version:     1.0.0
 * Author:      Your Name
 */
if ( ! defined('ABSPATH') ) exit;

// core includes
require __DIR__ . '/includes/class-gf-zoho-addon.php';
require __DIR__ . '/includes/class-zoho-api.php';
require __DIR__ . '/includes/settings-page.php';
require __DIR__ . '/includes/class-gf-zoho-webhook.php';
require __DIR__ . '/includes/class-gf-zoho-sync.php';
require __DIR__ . '/includes/class-gf-zoho-mapping.php';

// integration modules
foreach ( glob( __DIR__ . '/includes/integration/class-sync-*.php' ) as $file ) {
    require_once $file;
}

// Activation: create mapping table
register_activation_hook( __FILE__, ['GFZohoMapping', 'install_table'] );

// Deactivation: remove Zoho webhook
register_deactivation_hook( __FILE__, 'gf_zoho_deactivate' );
function gf_zoho_deactivate() {
    $webhook_id = get_option('gf_zoho_webhook_id');
    if ( $webhook_id ) {
        $api = new Zoho_API();
        $api->remove_webhook( $webhook_id );
        delete_option('gf_zoho_webhook_id');
    }
}

// Launch the Add-On
if ( class_exists('GFAddOn') ) {
    GFZohoSyncAddOn::get_instance();
}