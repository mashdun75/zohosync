<?php
/**
 * Webhook handler for Zoho to Gravity Forms synchronization
 */

// Register REST API route for webhook endpoint
add_action('rest_api_init', function() {
    GFCommon::log_debug('GF Zoho Sync: Registering webhook endpoint');
    
    register_rest_route('gf-zoho-sync/v1', '/zoho-update', [
        'methods'             => 'POST',
        'callback'            => 'gf_zoho_handle_update',
        'permission_callback' => 'gf_zoho_verify_webhook',
    ]);
});

/**
 * Verify the webhook request is from Zoho
 * 
 * @param WP_REST_Request $request The request object
 * @return bool Whether the request is valid
 */
function gf_zoho_verify_webhook(WP_REST_Request $request) {
    GFCommon::log_debug('GF Zoho Sync: Verifying webhook request');
    
    // For CRM, verify token
    $token = get_option('gf_zoho_webhook_token');
    if (empty($token)) {
        GFCommon::log_error('GF Zoho Sync: No webhook token found in options');
        return false;
    }
    
    $request_token = $request->get_header('x-zoho-webhook-token');
    
    if ($request_token === $token) {
        GFCommon::log_debug('GF Zoho Sync: Webhook token verification successful');
        return true;
    }
    
    // For Desk, different verification may be needed
    // Add Desk verification logic here
    
    GFCommon::log_error('GF Zoho Sync: Webhook token verification failed');
    return false;
}

/**
 * Handle webhook updates from Zoho
 * 
 * @param WP_REST_Request $request The request object
 * @return WP_REST_Response The response object
 */
function gf_zoho_handle_update(WP_REST_Request $request) {
    global $wpdb;
    
    $body = $request->get_json_params();
    GFCommon::log_debug('GF Zoho Sync: Received webhook data: ' . json_encode($body));
    
    // Extract module and record ID based on API type
    $module = rgar($body, 'module');
    $zoho_id = rgar($body, 'recordId');
    $api_type = 'CRM'; // Default to CRM
    
    // Handle different formats for CRM vs Desk
    if (isset($body['module']) && isset($body['record'])) {
        // CRM format
        $module = sanitize_text_field($body['module']);
        $record = $body['record'];
        $zoho_id = sanitize_text_field($record['id']);
        $api_type = 'CRM';
        GFCommon::log_debug("GF Zoho Sync: Processing CRM webhook for {$module} record {$zoho_id}");
    } elseif (isset($body['ticketId'])) {
        // Desk format
        $module = 'tickets';
        $zoho_id = sanitize_text_field($body['ticketId']);
        $record = $body;
        $api_type = 'Desk';
        GFCommon::log_debug("GF Zoho Sync: Processing Desk webhook for ticket {$zoho_id}");
    } else {
        GFCommon::log_error("GF Zoho Sync: Invalid webhook data format");
        return rest_ensure_response([
            'success' => false,
            'message' => 'Invalid webhook data format'
        ]);
    }

    // Look up mapping to find corresponding Gravity Forms entry
    $table = $wpdb->prefix . 'gf_zoho_map';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT form_id, entry_id FROM $table WHERE module=%s AND zoho_id=%s",
            $module, $zoho_id
        )
    );
    
    if (!$row) {
        GFCommon::log_error("GF Zoho Sync: No mapping found for {$module} record {$zoho_id}");
        return rest_ensure_response([
            'success' => false, 
            'message' => 'No mapping found.'
        ]);
    }
    
    GFCommon::log_debug("GF Zoho Sync: Found mapping to Form #{$row->form_id}, Entry #{$row->entry_id}");
    
    // Get feed and field mappings
    $addon = GFZohoSyncAddOn::get_instance();
    $feeds = $addon->get_feeds($row->form_id);
    $feed = null;
    
    foreach ($feeds as $f) {
        if (rgar($f, 'is_active') && rgar($f['meta'], 'module') === $module) {
            $feed = $f;
            break;
        }
    }
    
    if (!$feed) {
        GFCommon::log_error("GF Zoho Sync: No active feed found for Form #{$row->form_id} and module {$module}");
        return rest_ensure_response([
            'success' => false, 
            'message' => 'No active feed found for this form and module.'
        ]);
    }
    
    // Check if bidirectional sync is enabled
    if (empty($feed['meta']['bi_directional'])) {
        GFCommon::log_debug("GF Zoho Sync: Bidirectional sync not enabled for feed #{$feed['id']}");
        return rest_ensure_response([
            'success' => false, 
            'message' => 'Bidirectional sync not enabled for this feed.'
        ]);
    }
    
    // Get field mappings
    $field_mappings = rgar($feed['meta'], 'field_mappings', []);
    $updated_fields = [];
    
    GFCommon::log_debug("GF Zoho Sync: Processing " . count($field_mappings) . " field mappings");
    
    // Update entry fields from Zoho data
    foreach ($field_mappings as $gf_id => $zoho_field) {
        if (isset($body[$zoho_field])) {
            $value = sanitize_text_field($body[$zoho_field]);
            
            GFCommon::log_debug("GF Zoho Sync: Updating field #{$gf_id} with value from Zoho field '{$zoho_field}'");
            
            $result = GFAPI::update_entry_field(
                $row->entry_id,
                $gf_id,
                $value
            );
            
            if (!is_wp_error($result)) {
                $updated_fields[] = [
                    'gf_id' => $gf_id,
                    'zoho_field' => $zoho_field,
                    'value' => $value
                ];
                GFCommon::log_debug("GF Zoho Sync: Successfully updated field #{$gf_id}");
            } else {
                GFCommon::log_error("GF Zoho Sync: Failed to update field #{$gf_id}: " . $result->get_error_message());
            }
        }
    }
    
    // Update mapping last sync time
    if (!empty($updated_fields)) {
        GFCommon::log_debug("GF Zoho Sync: Updated " . count($updated_fields) . " fields in entry #{$row->entry_id}");
        
        // Update mapping with new timestamp
        GFZohoMapping::save_mapping(
            $row->form_id,
            $row->entry_id,
            $module,
            $zoho_id,
            $api_type
        );
    } else {
        GFCommon::log_debug("GF Zoho Sync: No fields were updated in entry #{$row->entry_id}");
    }

    return rest_ensure_response([
        'success' => true,
        'updated_fields' => $updated_fields
    ]);
}
