<?php
class GFZohoMapping {
    public static function install_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'gf_zoho_map';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            form_id MEDIUMINT(9) NOT NULL,
            entry_id BIGINT(20) NOT NULL,
            module VARCHAR(64) NOT NULL,
            zoho_id VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY record (module, zoho_id, form_id, entry_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function save_mapping( $form_id, $entry_id, $module, $zoho_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'gf_zoho_map';
        $wpdb->replace(
            $table,
            [
                'form_id'  => $form_id,
                'entry_id' => $entry_id,
                'module'   => $module,
                'zoho_id'  => $zoho_id,
            ],
            [ '%d','%d','%s','%s' ]
        );
    }
}
register_activation_hook( __FILE__, ['GFZohoMapping','install_table'] );