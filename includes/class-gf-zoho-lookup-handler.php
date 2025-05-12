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
}