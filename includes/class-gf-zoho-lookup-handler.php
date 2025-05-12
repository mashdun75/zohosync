<?php
/**
 * GF Zoho Lookup Fields Handler
 * Handles Zoho lookup fields and ID resolution
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Lookup_Handler {
    private $api;
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Zoho_API();
        $this->logger = gf_zoho_logger();
    }
    
    /**
     * Get lookup field information for a module
     *
     * @param string $module The Zoho module name
     * @return array Lookup fields data
     */
    public function get_lookup_fields($module) {
        $this->logger->info("Getting lookup fields for module: {$module}");
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for lookup field retrieval");
            return array();
        }
        
        // Get all fields for the module
        $url = "https://{$this->api->api_domain}/crm/v2/settings/fields?module=" . urlencode($module);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching fields: {$error_message}");
            return array();
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200 || empty($data['fields'])) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error: {$error_msg}", array('status' => $status, 'response' => $body));
            return array();
        }
        
        // Filter for lookup fields
        $lookup_fields = array();
        foreach ($data['fields'] as $field) {
            if (isset($field['data_type']) && in_array($field['data_type'], array('lookup', 'ownerlookup', 'userlookup', 'multiuserlookup'))) {
                $lookup_fields[] = array(
                    'api_name' => $field['api_name'],
                    'label' => $field['field_label'],
                    'type' => $field['data_type'],
                    'required' => !empty($field['required']) || !empty($field['system_mandatory']),
                    'lookup_module' => isset($field['lookup']) ? $field['lookup']['module'] : null
                );
            }
        }
        
        $this->logger->info("Found " . count($lookup_fields) . " lookup fields for module {$module}");
        return $lookup_fields;
    }
    
    /**
     * Resolve a lookup field value to its ID
     *
     * @param string $module The module containing the lookup field
     * @param string $lookup_module The module being referenced in the lookup
     * @param string $search_field The field to search by (usually Name)
     * @param string $search_value The value to search for
     * @return string|null The record ID if found, null otherwise
     */
    public function resolve_lookup_id($module, $lookup_module, $search_field, $search_value) {
        if (empty($search_value)) {
            $this->logger->warning("Empty search value for lookup resolution", array(
                'module' => $module,
                'lookup_module' => $lookup_module,
                'search_field' => $search_field
            ));
            return null;
        }
        
        $this->logger->info("Resolving lookup ID", array(
            'module' => $module,
            'lookup_module' => $lookup_module,
            'search_field' => $search_field,
            'search_value' => $search_value
        ));
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for lookup resolution");
            return null;
        }
        
        // Search for the record
        $url = "https://{$this->api->api_domain}/crm/v2/{$lookup_module}/search?criteria=({$search_field}:equals:{$search_value})";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error during lookup resolution: {$error_message}");
            return null;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error during lookup resolution: {$error_msg}", array('status' => $status));
            return null;
        }
        
        if (empty($data['data'])) {
            $this->logger->warning("No matching record found for lookup", array(
                'module' => $lookup_module,
                'search_field' => $search_field,
                'search_value' => $search_value
            ));
            return null;
        }
        
        $record_id = $data['data'][0]['id'];
        $this->logger->info("Resolved lookup value '{$search_value}' to ID: {$record_id}");
        return $record_id;
    }
    
    /**
     * Process data for submission, handling lookup fields
     *
     * @param array $data The data to submit to Zoho
     * @param array $field_types Map of field types by API name
     * @param string $module The Zoho module
     * @return array The processed data
     */
    public function process_lookup_fields($data, $field_types, $module) {
        $this->logger->info("Processing lookup fields for module: {$module}");
        
        // Get lookup fields for the module
        $lookup_fields = $this->get_lookup_fields($module);
        $lookup_field_map = array();
        
        // Create a lookup map for easy reference
        foreach ($lookup_fields as $field) {
            $lookup_field_map[$field['api_name']] = $field;
        }
        
        // Process each field in the data
        foreach ($data as $field_name => $field_value) {
            // Skip if not a lookup field
            if (!isset($lookup_field_map[$field_name])) {
                continue;
            }
            
            $lookup_info = $lookup_field_map[$field_name];
            $lookup_module = $lookup_info['lookup_module'];
            
            if (empty($lookup_module)) {
                $this->logger->warning("Lookup module not found for field: {$field_name}");
                continue;
            }
            
            // If the value looks like an ID already, keep it
            if (preg_match('/^[0-9]+$/', $field_value)) {
                $this->logger->info("Field {$field_name} value {$field_value} appears to be an ID already");
                continue;
            }
            
            // Resolve the lookup value to an ID
            $record_id = $this->resolve_lookup_id($module, $lookup_module, 'name', $field_value);
            
            if ($record_id) {
                // Replace the value with the ID
                $data[$field_name] = $record_id;
                $this->logger->info("Replaced lookup value '{$field_value}' with ID: {$record_id} for field {$field_name}");
            } else {
                // Remove the field if we couldn't resolve it
                unset($data[$field_name]);
                $this->logger->warning("Removed unresolved lookup field {$field_name} with value '{$field_value}'");
            }
        }
        
        return $data;
    }

    /**
     * Test a lookup field configuration to see if it resolves correctly
     * 
     * @param string $module The Zoho module (CRM or Desk)
     * @param string $lookup_field The Zoho lookup field to test
     * @param string $test_value The test value to look up
     * @return array Result with success status and found data
     */
    public function test_lookup_field($module, $lookup_field, $test_value) {
        $this->logger->info("Testing lookup field", array(
            'module' => $module,
            'lookup_field' => $lookup_field,
            'test_value' => $test_value
        ));
        
        // Skip if empty test value
        if (empty($test_value)) {
            return array(
                'success' => false,
                'message' => 'Test value cannot be empty',
                'data' => null
            );
        }
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for lookup field test");
            return array(
                'success' => false,
                'message' => 'Not connected to Zoho',
                'data' => null
            );
        }
        
        // Determine if this is a Desk module
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        // Handle each module type differently
        if ($is_desk_module) {
            return $this->test_desk_lookup_field($module, $lookup_field, $test_value);
        } else {
            return $this->test_crm_lookup_field($module, $lookup_field, $test_value);
        }
    }

    /**
     * Test a CRM lookup field
     * 
     * @param string $module The CRM module
     * @param string $lookup_field The lookup field to test
     * @param string $test_value The value to test
     * @return array Result with success status and found data
     */
    private function test_crm_lookup_field($module, $lookup_field, $test_value) {
        $this->logger->info("Testing CRM lookup field", array(
            'module' => $module,
            'lookup_field' => $lookup_field,
            'test_value' => $test_value
        ));
        
        // Build the search URL
        $url = "https://{$this->api->api_domain}/crm/v2/{$module}/search?criteria=({$lookup_field}:equals:{$test_value})";
        
        $this->logger->debug("Making CRM lookup test request to: {$url}");
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error testing CRM lookup field: {$error_message}");
            return array(
                'success' => false,
                'message' => "API Error: {$error_message}",
                'data' => null
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $this->logger->debug("CRM lookup test response", array(
            'status' => $status,
            'body' => substr($body, 0, 500) . (strlen($body) > 500 ? '...' : '')
        ));
        
        if ($status !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error testing CRM lookup field: {$error_msg}", array('status' => $status));
            return array(
                'success' => false,
                'message' => "API Error: {$error_msg}",
                'data' => null
            );
        }
        
        // Check if we found any matching records
        if (empty($data['data'])) {
            $this->logger->info("No matching records found for CRM lookup test");
            return array(
                'success' => false,
                'message' => "No records found matching '{$test_value}' for field '{$lookup_field}'",
                'data' => null
            );
        }
        
        // Get the first matching record
        $record = $data['data'][0];
        $record_id = $record['id'];
        
        // Extract key details for display
        $record_details = array(
            'id' => $record_id,
            'name' => isset($record['name']) ? $record['name'] : (
                        isset($record['Name']) ? $record['Name'] : (
                        isset($record['Account_Name']) ? $record['Account_Name'] : null))
        );
        
        // Add other useful fields if available
        if (isset($record['Email']) || isset($record['email'])) {
            $record_details['email'] = isset($record['Email']) ? $record['Email'] : $record['email'];
        }
        
        $this->logger->info("Successfully found CRM record for lookup test", array('record_id' => $record_id));
        
        return array(
            'success' => true,
            'message' => "Found matching record with ID: {$record_id}",
            'data' => $record_details
        );
    }

    /**
     * Test a Desk lookup field
     * 
     * @param string $module The Desk module (with desk_ prefix)
     * @param string $lookup_field The lookup field to test
     * @param string $test_value The value to test
     * @return array Result with success status and found data
     */
    private function test_desk_lookup_field($module, $lookup_field, $test_value) {
        $desk_module = str_replace('desk_', '', $module);
        
        $this->logger->info("Testing Desk lookup field", array(
            'module' => $desk_module,
            'lookup_field' => $lookup_field,
            'test_value' => $test_value
        ));
        
        // Check for Desk API class
        if (!class_exists('GF_Zoho_Desk')) {
            $this->logger->error("Zoho Desk class not available for lookup test");
            return array(
                'success' => false,
                'message' => 'Zoho Desk integration not available',
                'data' => null
            );
        }
        
        // Get Desk API instance
        $desk = new GF_Zoho_Desk();
        
        // Get organization ID and region
        $org_id = $desk->organization_id;
        $region = $desk->get_region();
        
        if (empty($org_id)) {
            $this->logger->error("No Desk organization ID available for lookup test");
            return array(
                'success' => false,
                'message' => 'Zoho Desk organization information not available',
                'data' => null
            );
        }
        
        // Build the search URL
        $url = "https://desk.zoho.{$region}/api/v1/{$desk_module}/search?{$lookup_field}=" . urlencode($test_value) . "&orgId={$org_id}";
        
        $this->logger->debug("Making Desk lookup test request to: {$url}");
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $desk->get_desk_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error testing Desk lookup field: {$error_message}");
            return array(
                'success' => false,
                'message' => "API Error: {$error_message}",
                'data' => null
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $this->logger->debug("Desk lookup test response", array(
            'status' => $status,
            'body' => substr($body, 0, 500) . (strlen($body) > 500 ? '...' : '')
        ));
        
        if ($status !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error testing Desk lookup field: {$error_msg}", array('status' => $status));
            return array(
                'success' => false,
                'message' => "API Error: {$error_msg}",
                'data' => null
            );
        }
        
        // Check if we found any matching records
        if (empty($data['data'])) {
            $this->logger->info("No matching records found for Desk lookup test");
            return array(
                'success' => false,
                'message' => "No records found matching '{$test_value}' for field '{$lookup_field}'",
                'data' => null
            );
        }
        
        // Get the first matching record
        $record = $data['data'][0];
        $record_id = $record['id'];
        
        // Extract key details for display
        $record_details = array(
            'id' => $record_id
        );
        
        // Add module-specific details
        switch ($desk_module) {
            case 'tickets':
                $record_details['subject'] = isset($record['subject']) ? $record['subject'] : null;
                $record_details['status'] = isset($record['status']) ? $record['status'] : null;
                break;
                
            case 'contacts':
                $record_details['name'] = (isset($record['firstName']) ? $record['firstName'] : '') . 
                                         (isset($record['lastName']) ? ' ' . $record['lastName'] : '');
                $record_details['email'] = isset($record['email']) ? $record['email'] : null;
                break;
                
            case 'accounts':
                $record_details['name'] = isset($record['accountName']) ? $record['accountName'] : null;
                break;
        }
        
        $this->logger->info("Successfully found Desk record for lookup test", array('record_id' => $record_id));
        
        return array(
            'success' => true,
            'message' => "Found matching record with ID: {$record_id}",
            'data' => $record_details
        );
    }
}