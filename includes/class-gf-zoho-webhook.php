<?php
add_action('rest_api_init', function() {
    register_rest_route('gf-zoho-sync/v1', '/zoho-update', [
        'methods'             => 'POST',
        'callback'            => 'gf_zoho_handle_update',
        'permission_callback' => '__return_true',
    ]);
});

function gf_zoho_handle_update(WP_REST_Request $request) {
    global $wpdb;

    $body    = $request->get_json_params();
    $module  = rgar($body, 'module');
    $zohoId  = rgar($body, 'recordId');

    // lookup mapping
    $table = $wpdb->prefix . 'gf_zoho_map';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT form_id, entry_id FROM $table WHERE module=%s AND zoho_id=%s",
            $module, $zohoId
        )
    );
    if ( ! $row ) {
        return rest_ensure_response([ 'success' => false, 'message' => 'No mapping found.' ]);
    }

    $mappings = GFZohoSyncAddOn::get_instance()->get_feed_field_map( $row->form_id );
    foreach ( $mappings as $gf_id => $zoho_field ) {
        if ( isset( $body[ $zoho_field ] ) ) {
            GFAPI::update_entry_field(
                $row->entry_id,
                $gf_id,
                sanitize_text_field( $body[ $zoho_field ] )
            );
        }
    }

    return rest_ensure_response([ 'success' => true ]);
}