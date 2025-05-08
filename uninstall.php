<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) exit;
// drop mapping table
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}gf_zoho_map");
// delete tokens
delete_option('gf_zoho_tokens');