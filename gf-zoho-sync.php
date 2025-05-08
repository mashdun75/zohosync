<?php
/**
 * Plugin Name: GF ↔ Zoho Sync
 * Description: Two-way sync between Gravity Forms and Zoho CRM/Desk modules.
 * Version:     1.0.0
 * Author:      Matt Duncan
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

// Launch the Add-On
if ( class_exists('GFAddOn') ) {
    GFZohoSyncAddOn::get_instance();
}