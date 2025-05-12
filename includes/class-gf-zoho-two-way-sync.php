<?php
/**
 * GF Zoho Two-Way Sync Handler
 * Enables bidirectional sync between Zoho and Gravity Forms
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Two_Way_Sync {
    private $api;
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Zoho_API();
        $this->logger = gf_zoho_logger();
        
        // Register webhook receiver
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        
        // Register cron event for periodic sync
        add_action('gf_zoho_sync_records', array($this, 'process_periodic_sync'));
        
        // Add admin AJAX handler for manual sync
        add_action('wp_ajax_gf_zoho_manual_sync', array($this, 'ajax_manual_sync'));
    }
    
    /**
     * Register REST API endpoint for webhook
     */
    public function register_webhook_endpoint() {
        register_rest_route('gf-zoho/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true' // We'll validate the webhook signature
        ));
    }
    
    /**
     * Handle incoming webhook from Zoho
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response Response object
     */
    public function handle_webhook($request) {
        $this->logger->info("Received webhook from Zoho");
        
        // Get the request body
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Validate webhook signature
        $webhook_key = get_option('gf_zoho_webhook_key', '');
        $signature = $request->get_header('x-zoho-signature');
        
        if (!empty($webhook_key) && !empty($signature)) {
            $expected_signature = hash_hmac('sha256', $body, $webhook_key);
            
            if ($signature !== $expected_signature) {
                $this->logger->error("Webhook signature validation failed");
                return new WP_REST_Response(array('success' => false, 'message' => 'Invalid signature'), 403);
            }
        }
        
        // Process webhook payload
        if (empty($data)) {
            $this->logger->error("Webhook payload is empty or invalid");
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid payload'), 400);
        }
        
        $this->logger->info("Webhook data received", array('data' => $data));
        
        // Determine operation type and module
        $operation = isset($data['operation']) ? $data['operation'] : '';
        $module = isset($data['module']) ? $data['module'] : '';
        $record_id = isset($data['id']) ? $data['id'] : null;
        
        if (empty($operation) || empty($module) || empty($record_id)) {
            $this->logger->error("Missing required webhook data");
            return new WP_REST_Response(array('success' => false, 'message' => 'Missing required data'), 400);
        }
        
        // Process based on operation
        switch ($operation) {
            case 'create':
            case 'update':
                $this->sync_record_from_zoho_to_gf($module, $record_id);
                break;
                
            case 'delete':
                $this->handle_zoho_record_deletion($module, $record_id);
                break;
                
            default:
                $this->logger->warning("Unknown webhook operation: {$operation}");
        }
        
        return new WP_REST_Response(array('success' => true));
    }
    
    /**
     * Process periodic sync from Zoho
     */
    public function process_periodic_sync() {
        $this->logger->info("Starting periodic sync from Zoho");
        
        // Get all form mappings with two-way sync enabled
        $forms = GFAPI::get_forms();
        
        foreach ($forms as $form) {
            $mappings = GF_Zoho_Direct::get_form_mappings($form['id']);
            
            if (empty($mappings) || empty($mappings['module']) || empty($mappings['enable_two_way_sync'])) {
                continue;
            }
            
            $this->logger->info("Processing two-way sync for form: {$form['id']}, module: {$mappings['module']}");
            
            // Get last sync time for this form
            $last_sync = get_option("gf_zoho_last_sync_{$form['id']}", 0);
            $current_time = time();
            
            // Sync records modified since last sync
            try {
                $this->sync_records_from_zoho($form['id'], $mappings, $last_sync);
            } catch (Exception $e) {
                $this->logger->error("Error during periodic sync: " . $e->getMessage());
            }
            
            // Update last sync time
            update_option("gf_zoho_last_sync_{$form['id']}", $current_time);
        }
        
        $this->logger->info("Periodic sync completed");
    }
    
    /**
     * Sync records from Zoho to Gravity Forms
     *
     * @param int $form_id The form ID
     * @param array $mappings The form mappings
     * @param int $last_sync Last sync timestamp
     */
    public function sync_records_from_zoho($form_id, $mappings, $last_sync) {
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for Zoho sync");
            return;
        }
        
        $module = $mappings['module'];
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        if ($is_desk_module) {
            // Zoho Desk sync
            $desk_module = str_replace('desk_', '', $module);
            $this->sync_desk_records($form_id, $desk_module, $mappings, $last_sync);
        } else {
            // Zoho CRM sync
            $this->sync_crm_records($form_id, $module, $mappings, $last_sync);
        }
    }
    
    /**
     * Sync CRM records from Zoho to Gravity Forms
     *
     * @param int $form_id The form ID
     * @param string $module The CRM module name
     * @param array $mappings The form mappings
     * @param int $last_sync Last sync timestamp
     */
    private function sync_crm_records($form_id, $module, $mappings, $last_sync) {
        // Convert timestamp to Zoho format (ISO 8601)
        $last_sync_date = gmdate('c', $last_sync);
        
        // Build the search criteria
        $criteria = "Modified_Time:greater_than:{$last_sync_date}";
        
        // Build the URL
        $url = "https://{$this->api->api_domain}/crm/v2/{$module}/search?criteria=" . urlencode($criteria);
        
        $this->logger->info("Syncing CRM records from Zoho for module: {$module}", array('criteria' => $criteria));
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching CRM records: {$error_message}");
            return;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching CRM records: {$error_msg}", array('status' => $status));
            return;
        }
        
        if (empty($data['data'])) {
            $this->logger->info("No updated CRM records found since last sync");
            return;
        }
        
        $this->logger->info("Found " . count($data['data']) . " updated CRM records");
        
        // Process each record
        foreach ($data['data'] as $record) {
            $this->import_zoho_record_to_gf($form_id, $module, $record, $mappings);
        }
    }
    
    /**
     * Sync Desk records from Zoho to Gravity Forms
     *
     * @param int $form_id The form ID
     * @param string $module The Desk module name
     * @param array $mappings The form mappings
     * @param int $last_sync Last sync timestamp
     */
    private function sync_desk_records($form_id, $module, $mappings, $last_sync) {
        // Convert timestamp to Zoho format (ISO 8601)
        $last_sync_date = gmdate('c', $last_sync);
        
        // Build the URL
        $url = "https://desk.{$this->api->api_domain}/api/v1/{$module}?modifiedTime>" . urlencode($last_sync_date);
        
        $this->logger->info("Syncing Desk records from Zoho for module: {$module}", array('last_sync' => $last_sync_date));
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching Desk records: {$error_message}");
            return;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching Desk records: {$error_msg}", array('status' => $status));
            return;
        }
        
        if (empty($data['data'])) {
            $this->logger->info("No updated Desk records found since last sync");
            return;
        }
        
        $this->logger->info("Found " . count($data['data']) . " updated Desk records");
        
        // Process each record
        foreach ($data['data'] as $record) {
            $this->import_zoho_desk_record_to_gf($form_id, $module, $record, $mappings);
        }
    }
    
    /**
     * Import a Zoho CRM record to Gravity Forms
     *
     * @param int $form_id The form ID
     * @param string $module The CRM module name
     * @param array $record The Zoho record data
     * @param array $mappings The form mappings
     */
    private function import_zoho_record_to_gf($form_id, $module, $record, $mappings) {
        // Check if we already have an entry for this record
        $existing_entry_id = $this->find_entry_by_zoho_id($form_id, $record['id']);
        
        // Log the import process
        $this->logger->info(
            "Importing " . ($existing_entry_id ? "existing" : "new") . " record from Zoho to form {$form_id}",
            array('record_id' => $record['id'], 'entry_id' => $existing_entry_id)
        );
        
        // Prepare entry data
        $entry = array();
        $entry['form_id'] = $form_id;
        
        // If updating, get the existing entry
        if ($existing_entry_id) {
            $existing_entry = GFAPI::get_entry($existing_entry_id);
            if ($existing_entry) {
                $entry = $existing_entry;
            }
        }
        
        // Map Zoho fields to form fields
        foreach ($mappings['fields'] as $gf_field_id => $zoho_field) {
            // Skip entry_id field
            if ($gf_field_id === 'entry_id') {
                continue;
            }
            
            // Check if the zoho field exists in the record
            if (isset($record[$zoho_field])) {
                $entry[$gf_field_id] = $record[$zoho_field];
            }
        }
        
        // Set the source so we know this came from Zoho
        $entry['source'] = 'zoho';
        
        // Add or update the entry
        if ($existing_entry_id) {
            // Update existing entry
            $result = GFAPI::update_entry($entry);
            if (is_wp_error($result)) {
                $this->logger->error("Error updating entry: " . $result->get_error_message(), 
                    array('entry' => $entry, 'record_id' => $record['id'])
                );
            } else {
                $this->logger->info("Successfully updated entry {$existing_entry_id} from Zoho record");
            }
        } else {
            // Create new entry
            $result = GFAPI::add_entry($entry);
            if (is_wp_error($result)) {
                $this->logger->error("Error adding entry: " . $result->get_error_message(), 
                    array('entry' => $entry, 'record_id' => $record['id'])
                );
            } else {
                $this->logger->info("Successfully created new entry {$result} from Zoho record");
                
                // Store the Zoho ID with the entry
                gform_update_meta($result, 'zoho_record_id', $record['id']);
                gform_update_meta($result, 'zoho_module', $module);
            }
        }
    }
    
    /**
     * Import a Zoho Desk record to Gravity Forms
     *
     * @param int $form_id The form ID
     * @param string $module The Desk module name
     * @param array $record The Zoho record data
     * @param array $mappings The form mappings
     */
    private function import_zoho_desk_record_to_gf($form_id, $module, $record, $mappings) {
        // Check if we already have an entry for this record
        $existing_entry_id = $this->find_entry_by_zoho_desk_id($form_id, $record['id']);
        
        // Log the import process
        $this->logger->info(
            "Importing " . ($existing_entry_id ? "existing" : "new") . " Desk record from Zoho to form {$form_id}",
            array('record_id' => $record['id'], 'entry_id' => $existing_entry_id)
        );
        
        // Prepare entry data
        $entry = array();
        $entry['form_id'] = $form_id;
        
        // If updating, get the existing entry
        if ($existing_entry_id) {
            $existing_entry = GFAPI::get_entry($existing_entry_id);
            if ($existing_entry) {
                $entry = $existing_entry;
            }
        }
        
        // Map Zoho fields to form fields
        foreach ($mappings['fields'] as $gf_field_id => $zoho_field) {
            // Skip entry_id field
            if ($gf_field_id === 'entry_id') {
                continue;
            }
            
            // Desk API returns camelCase field names
            $zoho_field_camel = lcfirst($zoho_field);
            
            // Check if the zoho field exists in the record
            if (isset($record[$zoho_field_camel])) {
                $entry[$gf_field_id] = $record[$zoho_field_camel];
            }
        }
        
        // Set the source so we know this came from Zoho
        $entry['source'] = 'zoho_desk';
        
        // Add or update the entry
        if ($existing_entry_id) {
            // Update existing entry
            $result = GFAPI::update_entry($entry);
            if (is_wp_error($result)) {
                $this->logger->error("Error updating entry from Desk: " . $result->get_error_message(), 
                    array('entry' => $entry, 'record_id' => $record['id'])
                );
            } else {
                $this->logger->info("Successfully updated entry {$existing_entry_id} from Zoho Desk record");
            }
        } else {
            // Create new entry
            $result = GFAPI::add_entry($entry);
            if (is_wp_error($result)) {
                $this->logger->error("Error adding entry from Desk: " . $result->get_error_message(), 
                    array('entry' => $entry, 'record_id' => $record['id'])
                );
            } else {
                $this->logger->info("Successfully created new entry {$result} from Zoho Desk record");
                
                // Store the Zoho ID with the entry
                gform_update_meta($result, 'zoho_desk_record_id', $record['id']);
                gform_update_meta($result, 'zoho_desk_module', $module);
            }
        }
    }
    
    /**
     * Sync a single record from Zoho to GF
     *
     * @param string $module The Zoho module
     * @param string $record_id The record ID
     */
    public function sync_record_from_zoho_to_gf($module, $record_id) {
        $this->logger->info("Syncing single record from Zoho to GF", array('module' => $module, 'record_id' => $record_id));
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for single record sync");
            return;
        }
        
        // Determine if this is a Desk module
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        if ($is_desk_module) {
            // Handle Desk module
            $desk_module = str_replace('desk_', '', $module);
            $url = "https://desk.{$this->api->api_domain}/api/v1/{$desk_module}/{$record_id}";
        } else {
            // Handle CRM module
            $url = "https://{$this->api->api_domain}/crm/v2/{$module}/{$record_id}";
        }
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching record: {$error_message}");
            return;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching record: {$error_msg}", array('status' => $status));
            return;
        }
        
        // Process the record
        if ($is_desk_module) {
            $record = $data;
            
            // Find forms with mappings to this module
            $forms = GFAPI::get_forms();
            
            foreach ($forms as $form) {
                $mappings = GF_Zoho_Direct::get_form_mappings($form['id']);
                
                if (!empty($mappings) && $mappings['module'] === "desk_{$desk_module}" && !empty($mappings['enable_two_way_sync'])) {
                    $this->import_zoho_desk_record_to_gf($form['id'], $desk_module, $record, $mappings);
                }
            }
        } else {
            $record = isset($data['data'][0]) ? $data['data'][0] : null;
            
            if (!$record) {
                $this->logger->error("No record data found in API response");
                return;
            }
            
            // Find forms with mappings to this module
            $forms = GFAPI::get_forms();
            
            foreach ($forms as $form) {
                $mappings = GF_Zoho_Direct::get_form_mappings($form['id']);
                
                if (!empty($mappings) && $mappings['module'] === $module && !empty($mappings['enable_two_way_sync'])) {
                    $this->import_zoho_record_to_gf($form['id'], $module, $record, $mappings);
                }
            }
        }
    }
    
    /**
     * Handle Zoho record deletion
     *
     * @param string $module The Zoho module
     * @param string $record_id The record ID
     */
    public function handle_zoho_record_deletion($module, $record_id) {
        $this->logger->info("Handling deletion of Zoho record", array('module' => $module, 'record_id' => $record_id));
        
        // Determine if this is a Desk module
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        // Find the corresponding entry
        if ($is_desk_module) {
            $desk_module = str_replace('desk_', '', $module);
            $entry_id = $this->find_entry_by_zoho_desk_id(null, $record_id);
        } else {
            $entry_id = $this->find_entry_by_zoho_id(null, $record_id);
        }
        
        if (!$entry_id) {
            $this->logger->info("No matching entry found for deleted Zoho record");
            return;
        }
        
        // Get mappings for the form
        $entry = GFAPI::get_entry($entry_id);
        if (!$entry) {
            $this->logger->error("Entry {$entry_id} not found");
            return;
        }
        
        $form_id = $entry['form_id'];
        $mappings = GF_Zoho_Direct::get_form_mappings($form_id);
        
        if (empty($mappings) || empty($mappings['enable_two_way_sync'])) {
            $this->logger->info("Two-way sync not enabled for form {$form_id}");
            return;
        }
        
        // Check deletion action setting
        $deletion_action = isset($mappings['deletion_action']) ? $mappings['deletion_action'] : 'mark_as_deleted';
        
        switch ($deletion_action) {
            case 'delete':
                // Delete the entry
                $result = GFAPI::delete_entry($entry_id);
                if (is_wp_error($result)) {
                    $this->logger->error("Error deleting entry: " . $result->get_error_message());
                } else {
                    $this->logger->info("Entry {$entry_id} deleted due to Zoho record deletion");
                }
                break;
                
            case 'mark_as_trashed':
                // Mark the entry as trashed
                $result = GFAPI::update_entry_property($entry_id, 'status', 'trash');
                if (is_wp_error($result)) {
                    $this->logger->error("Error trashing entry: " . $result->get_error_message());
                } else {
                    $this->logger->info("Entry {$entry_id} trashed due to Zoho record deletion");
                }
                break;
                
            case 'mark_as_deleted':
            default:
                // Add a meta flag indicating the record was deleted in Zoho
                gform_update_meta($entry_id, 'zoho_record_deleted', 'yes');
                gform_update_meta($entry_id, 'zoho_record_deleted_date', current_time('mysql'));
                $this->logger->info("Entry {$entry_id} marked as deleted in Zoho");
                break;
        }
    }
    
    /**
     * Find a Gravity Forms entry by Zoho CRM record ID
     *
     * @param int|null $form_id The form ID (optional)
     * @param string $zoho_id The Zoho record ID
     * @return int|null Entry ID or null if not found
     */
    public function find_entry_by_zoho_id($form_id = null, $zoho_id) {
        global $wpdb;
        
        $form_clause = $form_id ? $wpdb->prepare("AND entry.form_id = %d", $form_id) : "";
        
        $entry_id = $wpdb->get_var($wpdb->prepare(
            "SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta
            JOIN {$wpdb->prefix}gf_entry entry ON entry.id = entry_id
            WHERE meta_key = 'zoho_record_id'
            AND meta_value = %s
            {$form_clause}
            LIMIT 1",
            $zoho_id
        ));
        
        return $entry_id ? intval($entry_id) : null;
    }
    
    /**
     * Find a Gravity Forms entry by Zoho Desk record ID
     *
     * @param int|null $form_id The form ID (optional)
     * @param string $zoho_desk_id The Zoho Desk record ID
     * @return int|null Entry ID or null if not found
     */
    public function find_entry_by_zoho_desk_id($form_id = null, $zoho_desk_id) {
        global $wpdb;
        
        $form_clause = $form_id ? $wpdb->prepare("AND entry.form_id = %d", $form_id) : "";
        
        $entry_id = $wpdb->get_var($wpdb->prepare(
            "SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta
            JOIN {$wpdb->prefix}gf_entry entry ON entry.id = entry_id
            WHERE meta_key = 'zoho_desk_record_id'
            AND meta_value = %s
            {$form_clause}
            LIMIT 1",
            $zoho_desk_id
        ));
        
        return $entry_id ? intval($entry_id) : null;
    }
    
    /**
     * AJAX handler for manual sync
     */
    public function ajax_manual_sync() {
        // Check nonce
        check_ajax_referer('gf_zoho_admin', 'security');
        
        // Check permissions
        if (!current_user_can('gravityforms_edit_forms')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get form ID
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        
        if (!$form_id) {
            wp_send_json_error('Missing form ID');
        }
        
        // Get mappings
        $mappings = GF_Zoho_Direct::get_form_mappings($form_id);
        
        if (empty($mappings) || empty($mappings['module'])) {
            wp_send_json_error('No mappings found for this form');
        }
        
        // Set last sync to 30 days ago to sync recent records
        $last_sync = time() - (30 * DAY_IN_SECONDS);
        
        try {
            // Sync records
            $this->sync_records_from_zoho($form_id, $mappings, $last_sync);
            
            // Update last sync time
            update_option("gf_zoho_last_sync_{$form_id}", time());
            
            wp_send_json_success('Manual sync completed successfully');
        } catch (Exception $e) {
            wp_send_json_error('Error during manual sync: ' . $e->getMessage());
        }
    }
    
    /**
     * Add UI for two-way sync options
     *
     * @param array $mappings Current mappings
     * @return string HTML
     */
    public function render_two_way_sync_ui($mappings) {
        $enable_two_way_sync = isset($mappings['enable_two_way_sync']) ? $mappings['enable_two_way_sync'] : false;
        $deletion_action = isset($mappings['deletion_action']) ? $mappings['deletion_action'] : 'mark_as_deleted';
        $webhook_url = rest_url('gf-zoho/v1/webhook');
        
        ob_start();
        ?>
        <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
            <h3>Two-Way Sync Options</h3>
            
            <table class="form-table">
                <tr>
                    <th><label for="enable_two_way_sync">Enable Two-Way Sync</label></th>
                    <td>
                        <input type="checkbox" id="enable_two_way_sync" name="enable_two_way_sync" value="1" <?php checked($enable_two_way_sync); ?>>
                        <span class="description">Allow changes in Zoho to be synced back to Gravity Forms</span>
                    </td>
                </tr>
                <tr>
                    <th><label for="deletion_action">When Record Deleted in Zoho</label></th>
                    <td>
                        <select id="deletion_action" name="deletion_action">
                            <option value="mark_as_deleted" <?php selected($deletion_action, 'mark_as_deleted'); ?>>Mark Entry as Deleted (flag only)</option>
                            <option value="mark_as_trashed" <?php selected($deletion_action, 'mark_as_trashed'); ?>>Move Entry to Trash</option>
                            <option value="delete" <?php selected($deletion_action, 'delete'); ?>>Delete Entry Permanently</option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <div style="margin-top: 15px; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">
                <h4>Zoho Webhook Setup</h4>
                <p>To enable two-way sync, you need to set up a webhook in Zoho CRM:</p>
                <ol>
                    <li>Go to Zoho CRM Setup &gt; Developer Space &gt; Webhooks</li>
                    <li>Create a new webhook with the following URL:<br>
                        <code style="background: #eee; padding: 5px; display: block; margin: 5px 0;"><?php echo esc_html($webhook_url); ?></code>
                    </li>
                    <li>Set the events to trigger the webhook (create, update, delete)</li>
                    <li>Save the webhook and copy the generated authentication key</li>
                    <li>Enter the authentication key in the field below:</li>
                </ol>
                
                <div style="margin-top: 10px;">
                    <label for="webhook_key"><strong>Webhook Authentication Key:</strong></label><br>
                    <input type="text" id="webhook_key" name="webhook_key" class="regular-text" value="<?php echo esc_attr(get_option('gf_zoho_webhook_key', '')); ?>" placeholder="Enter webhook key...">
                </div>
                
                <div style="margin-top: 15px;">
                    <button type="button" id="manual_sync_button" class="button" data-form-id="<?php echo isset($_GET['id']) ? absint($_GET['id']) : 0; ?>">Run Manual Sync</button>
                    <span id="manual_sync_result" style="margin-left: 10px;"></span>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Manual sync button
            $('#manual_sync_button').on('click', function() {
                var $button = $(this);
                var formId = $button.data('form-id');
                var $result = $('#manual_sync_result');
                
                $button.attr('disabled', 'disabled').text('Syncing...');
                $result.html('<span class="spinner is-active" style="float:none; margin:0;"></span> Syncing records from Zoho...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gf_zoho_manual_sync',
                        form_id: formId,
                        security: '<?php echo wp_create_nonce('gf_zoho_admin'); ?>'
                    },
                    success: function(response) {
                        $button.removeAttr('disabled').text('Run Manual Sync');
                        
                        if (response.success) {
                            $result.html('<span style="color:green;">✓ ' + response.data + '</span>');
                        } else {
                            $result.html('<span style="color:red;">✗ ' + (response.data || 'Error during sync') + '</span>');
                        }
                    },
                    error: function() {
                        $button.removeAttr('disabled').text('Run Manual Sync');
                        $result.html('<span style="color:red;">✗ Error during sync. Please check the logs.</span>');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Process two-way sync settings from form submission
     *
     * @param array $mappings Current mappings
     * @param array $form_data Form POST data
     * @return array Updated mappings
     */
    public function process_two_way_sync_submission($mappings, $form_data) {
        $mappings['enable_two_way_sync'] = isset($form_data['enable_two_way_sync']) ? true : false;
        $mappings['deletion_action'] = isset($form_data['deletion_action']) ? sanitize_text_field($form_data['deletion_action']) : 'mark_as_deleted';
        
        // Save webhook key as a global option
        if (isset($form_data['webhook_key'])) {
            update_option('gf_zoho_webhook_key', sanitize_text_field($form_data['webhook_key']));
        }
        
        return $mappings;
    }
}

// Helper function to get the two-way sync instance
function gf_zoho_two_way_sync() {
    static $instance = null;
    
    if ($instance === null) {
        $instance = new GF_Zoho_Two_Way_Sync();
    }
    
    return $instance;
}