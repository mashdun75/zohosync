<?php
/**
 * Mapping class for storing relationships between Gravity Forms entries and Zoho records
 */

class GFZohoMapping {
    /**
     * Install mapping table on plugin activation
     */
    public static function install_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'gf_zoho_map';
        $charset = $wpdb->get_charset_collate();

        GFCommon::log_debug('GFZohoMapping: Installing mapping table');

        $sql = "CREATE TABLE $table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            form_id MEDIUMINT(9) NOT NULL,
            entry_id BIGINT(20) NOT NULL,
            module VARCHAR(64) NOT NULL,
            zoho_id VARCHAR(64) NOT NULL,
            api_type VARCHAR(10) NOT NULL DEFAULT 'CRM',
            last_sync DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sync_status VARCHAR(20) NOT NULL DEFAULT 'success',
            PRIMARY KEY (id),
            UNIQUE KEY record (module, zoho_id, form_id, entry_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        GFCommon::log_debug('GFZohoMapping: Mapping table installation complete');
    }

    /**
     * Save mapping between GF entry and Zoho record
     * 
     * @param int $form_id Gravity Form ID
     * @param int $entry_id Entry ID
     * @param string $module Zoho module name
     * @param string $zoho_id Zoho record ID
     * @param string $api_type CRM or Desk
     * @param string $status Sync status
     * @return bool|int False on error, number of rows affected on success
     */
    public static function save_mapping($form_id, $entry_id, $module, $zoho_id, $api_type = 'CRM', $status = 'success') {
        global $wpdb;
        $table = $wpdb->prefix . 'gf_zoho_map';
        
        GFCommon::log_debug("GFZohoMapping: Saving mapping for Form #{$form_id}, Entry #{$entry_id}, {$module} record {$zoho_id}");
        
        $result = $wpdb->replace(
            $table,
            [
                'form_id'     => $form_id,
                'entry_id'    => $entry_id,
                'module'      => $module,
                'zoho_id'     => $zoho_id,
                'api_type'    => $api_type,
                'last_sync'   => current_time('mysql'),
                'sync_status' => $status
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            GFCommon::log_error("GFZohoMapping: Failed to save mapping - DB error: {$wpdb->last_error}");
            return false;
        }
        
        GFCommon::log_debug("GFZohoMapping: Mapping saved successfully with ID {$wpdb->insert_id}");
        return $result;
    }
    
    /**
     * Get mapping for a specific entry
     * 
     * @param int $form_id Gravity Form ID
     * @param int $entry_id Entry ID
     * @param string $module Optional module name to filter by
     * @return object|null Database row object or null if not found
     */
    public static function get_mapping($form_id, $entry_id, $module = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'gf_zoho_map';
        
        GFCommon::log_debug("GFZohoMapping: Looking up mapping for Form #{$form_id}, Entry #{$entry_id}" . ($module ? ", Module {$module}" : ""));
        
        $sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE form_id = %d AND entry_id = %d",
            $form_id, $entry_id
        );
        
        if ($module) {
            $sql .= $wpdb->prepare(" AND module = %s", $module);
        }
        
        $mapping = $wpdb->get_row($sql);
        
        if ($mapping) {
            GFCommon::log_debug("GFZohoMapping: Found mapping with Zoho ID {$mapping->zoho_id}");
        } else {
            GFCommon::log_debug("GFZohoMapping: No mapping found");
        }
        
        return $mapping;
    }
    
    /**
     * Get mapping by Zoho ID
     * 
     * @param string $module Zoho module name
     * @param string $zoho_id Zoho record ID
     * @param string $api_type CRM or Desk
     * @return object|null Database row object or null if not found
     */
    public static function get_mapping_by_zoho_id($module, $zoho_id, $api_type = 'CRM') {
        global $wpdb;
        $table = $wpdb->prefix . 'gf_zoho_map';
        
        GFCommon::log_debug("GFZohoMapping: Looking up mapping for {$module} record {$zoho_id}, API type {$api_type}");
        
        $sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE module = %s AND zoho_id = %s",
            $module, $zoho_id
        );
        
        if ($api_type) {
            $sql .= $wpdb->prepare(" AND api_type = %s", $api_type);
        }
        
        $mapping = $wpdb->get_row($sql);
        
        if ($mapping) {
            GFCommon::log_debug("GFZohoMapping: Found mapping to Form #{$mapping->form_id}, Entry #{$mapping->entry_id}");
        } else {
            GFCommon::log_debug("GFZohoMapping: No mapping found");
        }
        
        return $mapping;
    }
    
    /**
     * Delete mapping for an entry
     * 
     * @param int $form_id Gravity Form ID
     * @param int $entry_id Entry ID
     * @param string $module Optional module name to filter by
     * @return int|false Number of rows affected or false on error
     */
    public static function delete_mapping($form_id, $entry_id, $module = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'gf_zoho_map';
        
        GFCommon::log_debug("GFZohoMapping: Deleting mapping for Form #{$form_id}, Entry #{$entry_id}" . ($module ? ", Module {$module}" : ""));
        
        $where = [
            'form_id'  => $form_id,
            'entry_id' => $entry_id
        ];
        
        $where_format = ['%d', '%d'];
        
        if ($module) {
            $where['module'] = $module;
            $where_format[] = '%s';
        }
        
        $result = $wpdb->delete($table, $where, $where_format);
        
        if ($result === false) {
            GFCommon::log_error("GFZohoMapping: Failed to delete mapping - DB error: {$wpdb->last_error}");
        } else {
            GFCommon::log_debug("GFZohoMapping: Deleted {$result} mapping records");
        }
        
        return $result;
    }
}

// Fixed activation hook - this won't work as is because __FILE__ refers to this file
// The hook should be registered in the main plugin file
// Leaving comment here for reference
// register_activation_hook( __FILE__, ['GFZohoMapping','install_table'] );
