<?php
/**
 * GF Zoho Desk Integration
 * Adds support for Zoho Desk modules alongside Zoho CRM
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Desk {
    private $api;
    private $logger;
    private $organization_id = null;
    private $portal_name = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Zoho_API();
        $this->logger = gf_zoho_logger();
        
        // Initialize the organization ID and portal name
        $this->get_organization_info();
    }
    
    /**
     * Get organization information for Zoho Desk API
     *
     * @return array|null Organization info or null if not found
     */
    private function get_organization_info() {
        // Try to get cached organization info first
        $org_id = get_option('gf_zoho_desk_organization_id', null);
        $portal_name = get_option('gf_zoho_desk_portal_name', null);
        
        if (!empty($org_id) && !empty($portal_name)) {
            $this->organization_id = $org_id;
            $this->portal_name = $portal_name;
            return array('id' => $org_id, 'portal' => $portal_name);
        }
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for Desk organization info request");
            return null;
        }
        
        // Get region for Desk API
        $region = $this->get_desk_region();
        
        // Try to fetch organization info
        $url = "https://desk.zoho.{$region}/api/v1/organizations";
        $this->logger->info("DEBUG - Fetching organization info from: " . $url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching organization info: {$error_message}");
            return null;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $this->logger->debug("Organization response", array(
            'status' => $status,
            'body_sample' => substr($body, 0, 500) . (strlen($body) > 500 ? '...' : '')
        ));
        
        if ($status !== 200 || empty($data)) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching organization info: {$error_msg}", array('status' => $status));
            return null;
        }
        
        // Extract the organization ID and portal name from the response
        if (isset($data['data']) && !empty($data['data'])) {
            // The first organization in the list
            $org_id = $data['data'][0]['id'];
            $portal_name = isset($data['data'][0]['portalName']) ? $data['data'][0]['portalName'] : '';
            
            $this->logger->info("Found organization ID: {$org_id}, Portal Name: {$portal_name}");
            
            // Cache the organization info
            update_option('gf_zoho_desk_organization_id', $org_id);
            update_option('gf_zoho_desk_portal_name', $portal_name);
            
            $this->organization_id = $org_id;
            $this->portal_name = $portal_name;
            
            return array('id' => $org_id, 'portal' => $portal_name);
        } elseif (isset($data[0]) && isset($data[0]['id'])) {
            // Alternative format - direct array
            $org_id = $data[0]['id'];
            $portal_name = isset($data[0]['portalName']) ? $data[0]['portalName'] : '';
            
            $this->logger->info("Found organization ID: {$org_id}, Portal Name: {$portal_name}");
            
            // Cache the organization info
            update_option('gf_zoho_desk_organization_id', $org_id);
            update_option('gf_zoho_desk_portal_name', $portal_name);
            
            $this->organization_id = $org_id;
            $this->portal_name = $portal_name;
            
            return array('id' => $org_id, 'portal' => $portal_name);
        }
        
        $this->logger->error("Could not find organization info in response");
        return null;
    }
    
    /**
     * Helper method to get the appropriate region for Desk API
     * 
     * @return string The Zoho region (com, eu, in, etc.)
     */
    private function get_desk_region() {
        $api_domain = $this->api->api_domain;
        $region = "com"; // Default region
        
        if (strpos($api_domain, 'eu') !== false) {
            $region = "eu";
        } elseif (strpos($api_domain, 'in') !== false) {
            $region = "in";
        } elseif (strpos($api_domain, 'com.au') !== false || strpos($api_domain, 'au') !== false) {
            $region = "com.au";
        } elseif (strpos($api_domain, 'jp') !== false) {
            $region = "jp";
        }
        
        return $region;
    }
    
    /**
     * Get Zoho Desk modules
     *
     * @return array List of available Desk modules
     */
    public function get_desk_modules() {
        $desk_modules = array(
            'tickets' => 'Tickets',
            'contacts' => 'Contacts',
            'accounts' => 'Accounts',
            'tasks' => 'Tasks',
            'calls' => 'Calls',
            'events' => 'Events',
            'departments' => 'Departments',
            'articles' => 'Knowledge Base Articles'
        );
        
        return apply_filters('gf_zoho_desk_modules', $desk_modules);
    }
    
    /**
     * Get a list of Zoho Desk departments
     * 
     * @return array Departments (id => name)
     */
    public function get_departments() {
        $this->logger->info("Getting Zoho Desk departments");
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for Desk departments request");
            return array();
        }
        
        // Check if we have the portal name
        if (empty($this->portal_name)) {
            $this->logger->error("No portal name available for Desk departments request");
            return array();
        }
        
        // Get region for Desk API
        $region = $this->get_desk_region();
        
        // Try multiple API endpoints with portal name
        $endpoints = [
            // Portal-based endpoints
            "https://desk.zoho.{$region}/api/v1/{$this->portal_name}/departments",
            // Organization ID-based endpoints
            "https://desk.zoho.{$region}/api/v1/organizations/{$this->organization_id}/departments",
            // Fallbacks
            "https://desk.zoho.{$region}/api/v1/departments"
        ];
        
        $response = null;
        $successful_endpoint = null;
        
        foreach ($endpoints as $url) {
            $this->logger->info("DEBUG - Trying departments URL: " . $url);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                    'Content-Type' => 'application/json',
                    'orgId' => $this->organization_id // Include orgId header
                ),
                'timeout' => 30
            ));
            
            if (!is_wp_error($response)) {
                $status = wp_remote_retrieve_response_code($response);
                if ($status === 200) {
                    $successful_endpoint = $url;
                    $this->logger->info("Found working departments endpoint: " . $url);
                    break;
                }
            }
        }
        
        if (!$successful_endpoint) {
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->logger->error("Error fetching Desk departments: {$error_message}");
            } else {
                $status = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $error_data = json_decode($body, true);
                $error_msg = isset($error_data['message']) ? $error_data['message'] : 'Unknown error';
                $this->logger->error("API error fetching Desk departments: {$error_msg}", array('status' => $status));
            }
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Log the response structure for debugging
        $this->logger->debug("Departments response structure", array(
            'keys' => is_array($data) ? array_keys($data) : 'Not an array',
            'sample' => is_array($data) && !empty($data) ? json_encode(reset($data)) : 'No data'
        ));
        
        $departments = array();
        
        // Handle different response formats
        if (isset($data['data'])) {
            // Handle v2 API format with 'data' key
            foreach ($data['data'] as $dept) {
                $departments[$dept['id']] = $dept['name'];
            }
        } else {
            // Handle v1 API format with direct array
            foreach ($data as $dept) {
                $departments[$dept['id']] = $dept['name'];
            }
        }
        
        $this->logger->info("Found " . count($departments) . " Zoho Desk departments");
        return $departments;
    }
    
    /**
     * Get fields for a Zoho Desk module
     *
     * @param string $module The module name (e.g. 'tickets')
     * @return array List of fields
     */
    public function get_desk_fields($module) {
        $this->logger->info("Getting fields for Zoho Desk module: {$module}");
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for Desk fields request");
            return array();
        }
        
        // Check if we have the portal name
        if (empty($this->portal_name)) {
            $this->logger->error("No portal name available for Desk fields request");
            return array();
        }
        
        // Get region for Desk API
        $region = $this->get_desk_region();
        $module_singular = rtrim($module, 's'); // Convert 'tickets' to 'ticket'
        
        // Try multiple API endpoints with portal name
        $endpoints = [
            // API Explorer endpoints (most likely to work)
            "https://desk.zoho.{$region}/api/v1/{$this->portal_name}/{$module}/fields",
            "https://desk.zoho.{$region}/api/v1/{$this->portal_name}/fields?module={$module_singular}",
            
            // Portal-based custom fields endpoints
            "https://desk.zoho.{$region}/api/v1/{$this->portal_name}/customFields?module={$module_singular}",
            "https://desk.zoho.{$region}/api/v1/{$this->portal_name}/customFields/{$module_singular}",
            
            // Organization-based endpoints
            "https://desk.zoho.{$region}/api/v1/organizations/{$this->organization_id}/{$module}/fields",
            "https://desk.zoho.{$region}/api/v1/organizations/{$this->organization_id}/customFields?module={$module_singular}",
            
            // Fallbacks without portal or org ID
            "https://desk.zoho.{$region}/api/v1/{$module}/fields",
            "https://desk.zoho.{$region}/api/v1/customFields?module={$module_singular}"
        ];
        
        $response = null;
        $successful_endpoint = null;
        
        foreach ($endpoints as $url) {
            $this->logger->info("DEBUG - Trying fields URL: " . $url);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                    'Content-Type' => 'application/json',
                    'orgId' => $this->organization_id // Include orgId header
                ),
                'timeout' => 30
            ));
            
            if (!is_wp_error($response)) {
                $status = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                
                // Log full response for debugging
                $this->logger->debug("Desk API response from " . $url, array(
                    'status' => $status,
                    'body_sample' => substr($body, 0, 500) . (strlen($body) > 500 ? '...' : '')
                ));
                
                if ($status === 200) {
                    $successful_endpoint = $url;
                    $this->logger->info("Found working fields endpoint: " . $url);
                    break;
                }
            }
        }
        
        if (!$successful_endpoint) {
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->logger->error("Error fetching Desk fields: {$error_message}");
            } else {
                $status = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $error_data = json_decode($body, true);
                $error_msg = isset($error_data['message']) ? $error_data['message'] : 'Unknown error';
                $this->logger->error("API error fetching Desk fields: {$error_msg}", array('status' => $status));
            }
            
            // Return standard fields at minimum
            $this->logger->info("Using standard fields only for module: {$module}");
            return $this->get_standard_desk_fields($module);
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Add standard fields based on module
        $standard_fields = $this->get_standard_desk_fields($module);
        $formatted_fields = array();
        
        // Log the response structure for debugging
        $this->logger->debug("Fields response structure", array(
            'keys' => is_array($data) ? array_keys($data) : 'Not an array',
            'url' => $successful_endpoint
        ));
        
        // Process based on response format and endpoint
        if (strpos($successful_endpoint, 'customFields?') !== false || strpos($successful_endpoint, 'customFields/') !== false) {
            // Custom fields endpoint
            $formatted_fields = $this->format_custom_fields($data);
        } else {
            // Regular fields endpoint
            if (isset($data['fields'])) {
                $formatted_fields = $this->format_api_fields($data['fields']);
            } elseif (isset($data['data'])) {
                $formatted_fields = $this->format_api_fields($data['data']);
            } else {
                $formatted_fields = $this->format_api_fields($data);
            }
        }
        
        // Combine standard and custom fields
        $all_fields = array_merge($standard_fields, $formatted_fields);
        
        $this->logger->info("Found " . count($all_fields) . " fields for Zoho Desk module {$module}");
        return $all_fields;
    }
    
    /**
     * Format fields from Zoho Desk API v1/v2
     *
     * @param array $api_fields Fields from API
     * @return array Formatted fields
     */
    private function format_api_fields($api_fields) {
        $formatted = array();
        
        if (!is_array($api_fields)) {
            return $formatted;
        }
        
        foreach ($api_fields as $field) {
            $formatted[] = array(
                'api_name' => isset($field['apiName']) ? $field['apiName'] : (isset($field['name']) ? $field['name'] : ''),
                'label' => isset($field['displayLabel']) ? $field['displayLabel'] : (isset($field['label']) ? $field['label'] : (isset($field['apiName']) ? $field['apiName'] : '')),
                'type' => isset($field['dataType']) ? strtolower($field['dataType']) : 'text',
                'required' => !empty($field['required']) || !empty($field['mandatory']),
                'custom' => !empty($field['isCustomField'])
            );
        }
        
        return $formatted;
    }
    
    /**
     * Get standard fields for Zoho Desk modules
     *
     * @param string $module The module name
     * @return array Standard fields
     */
    private function get_standard_desk_fields($module) {
        $fields = array();
        
        // Common fields for all modules
        $common_fields = array(
            array('api_name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true),
            array('api_name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false),
        );
        
        // Module-specific fields
        switch ($module) {
            case 'tickets':
                $fields = array_merge($common_fields, array(
                    array('api_name' => 'departmentId', 'label' => 'Department ID', 'type' => 'lookup', 'required' => true),
                    array('api_name' => 'contactId', 'label' => 'Contact ID', 'type' => 'lookup', 'required' => true),
                    array('api_name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false),
                    array('api_name' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false),
                    array('api_name' => 'status', 'label' => 'Status', 'type' => 'picklist', 'required' => false),
                    array('api_name' => 'priority', 'label' => 'Priority', 'type' => 'picklist', 'required' => false),
                    array('api_name' => 'assigneeId', 'label' => 'Assignee ID', 'type' => 'lookup', 'required' => false),
                    array('api_name' => 'category', 'label' => 'Category', 'type' => 'text', 'required' => false),
                    array('api_name' => 'dueDate', 'label' => 'Due Date', 'type' => 'date', 'required' => false),
                ));
                break;
                
            case 'contacts':
                $fields = array(
                    array('api_name' => 'firstName', 'label' => 'First Name', 'type' => 'text', 'required' => true),
                    array('api_name' => 'lastName', 'label' => 'Last Name', 'type' => 'text', 'required' => true),
                    array('api_name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false),
                    array('api_name' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false),
                    array('api_name' => 'mobile', 'label' => 'Mobile', 'type' => 'phone', 'required' => false),
                    array('api_name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => false),
                    array('api_name' => 'accountId', 'label' => 'Account ID', 'type' => 'lookup', 'required' => false),
                );
                break;
                
            case 'accounts':
                $fields = array(
                    array('api_name' => 'accountName', 'label' => 'Account Name', 'type' => 'text', 'required' => true),
                    array('api_name' => 'website', 'label' => 'Website', 'type' => 'url', 'required' => false),
                    array('api_name' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false),
                    array('api_name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false),
                );
                break;
                
            default:
                $fields = $common_fields;
        }
        
        return $fields;
    }
    
    /**
     * Format custom fields from Zoho Desk API
     *
     * @param array $custom_fields Custom fields from API
     * @return array Formatted fields
     */
    private function format_custom_fields($custom_fields) {
        $formatted = array();
        
        if (!is_array($custom_fields)) {
            return $formatted;
        }
        
        // Handle different response formats
        if (isset($custom_fields['data'])) {
            $custom_fields = $custom_fields['data'];
        }
        
        foreach ($custom_fields as $field) {
            $formatted[] = array(
                'api_name' => isset($field['fieldName']) ? $field['fieldName'] : (isset($field['apiName']) ? $field['apiName'] : ''),
                'label' => !empty($field['displayLabel']) ? $field['displayLabel'] : (isset($field['fieldName']) ? $field['fieldName'] : (isset($field['apiName']) ? $field['apiName'] : '')),
                'type' => isset($field['dataType']) ? strtolower($field['dataType']) : 'text',
                'required' => !empty($field['required']) || !empty($field['mandatory']),
                'custom' => true
            );
        }
        
        return $formatted;
    }
    
    /**
     * Submit data to Zoho Desk
     *
     * @param string $module The Desk module (e.g. 'tickets')
     * @param array $data The data to submit
     * @param string|null $record_id Record ID for update operations
     * @return array Result array (success, message, data)
     */
    public function submit_to_desk($module, $data, $record_id = null) {
        $this->logger->info("Submitting to Zoho Desk module: {$module}", array('data' => $data));
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("No access token available for Desk submission");
            return array(
                'success' => false,
                'message' => 'Not connected to Zoho'
            );
        }
        
        // Check if we have the portal name
        if (empty($this->portal_name)) {
            $this->logger->error("No portal name available for Desk submission");
            return array(
                'success' => false,
                'message' => 'No Zoho portal name found'
            );
        }
        
        // Determine if this is a create or update
        $is_update = !empty($record_id);
        
        // Get region for Desk API
        $region = $this->get_desk_region();
        
        // Try different API endpoints
        $endpoints = [
            // Portal-based endpoints (most likely to work)
            "https://desk.zoho.{$region}/api/v1/{$this->portal_name}/{$module}" . ($is_update ? "/{$record_id}" : ""),
            
            // Organization-based endpoints
            "https://desk.zoho.{$region}/api/v1/organizations/{$this->organization_id}/{$module}" . ($is_update ? "/{$record_id}" : ""),
            
            // Fallback without portal or org ID
            "https://desk.zoho.{$region}/api/v1/{$module}" . ($is_update ? "/{$record_id}" : "")
        ];
        
        $response = null;
        $successful_url = null;
        
        foreach ($endpoints as $url) {
            // Determine method based on operation
            $method = $is_update ? 'PATCH' : 'POST';
            
            // Debug the URL construction
            $this->logger->debug("Trying Desk API Request", array(
                'url' => $url,
                'method' => $method
            ));
            
            // Make the request
            $response = wp_remote_request($url, array(
                'method' => $method,
                'headers' => array(
                    'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                    'Content-Type' => 'application/json',
                    'orgId' => $this->organization_id // Include orgId header
                ),
                'body' => json_encode($data),
                'timeout' => 30
            ));
            
            if (!is_wp_error($response)) {
                $status = wp_remote_retrieve_response_code($response);
                if ($status >= 200 && $status < 300) {
                    $successful_url = $url;
                    $this->logger->info("Successfully used API endpoint: " . $url);
                    break;
                }
            }
        }
        
        if (!$successful_url) {
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->logger->error("Error submitting to Desk: {$error_message}");
                return array(
                    'success' => false,
                    'message' => "API Error: {$error_message}"
                );
            } else {
                $status = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $result = json_decode($body, true);
                $error = isset($result['message']) ? $result['message'] : 'Unknown error';
                
                $this->logger->error("Error submitting to Desk API: {$error}", array(
                    'status' => $status,
                    'body' => $body
                ));
                
                return array(
                    'success' => false,
                    'message' => "Error: {$error}",
                    'data' => $result
                );
            }
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        $this->logger->debug("Successful Desk API Response", array(
            'status' => wp_remote_retrieve_response_code($response),
            'body' => $body
        ));
        
        $this->logger->info("Successfully " . ($is_update ? "updated" : "created") . " {$module} record");
        return array(
            'success' => true,
            'message' => ($is_update ? "Updated" : "Created") . " {$module} successfully",
            'data' => $result
        );
    }
    
    /**
     * Add UI to select product for Desk support
     *
     * @param array $mappings Current mappings
     * @return string HTML
     */
    public function render_desk_settings_ui($mappings) {
        $desk_settings = isset($mappings['desk_settings']) ? $mappings['desk_settings'] : array(
            'department_id' => '',
            'status' => 'Open',
            'priority' => 'Medium'
        );
        
        // Get departments
        $departments = $this->get_departments();
        
        ob_start();
        ?>
        <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
            <h3>Zoho Desk Settings <span class="description">(Required for Desk modules)</span></h3>
            
            <table class="form-table">
                <tr>
                    <th><label for="desk_department_id">Department</label></th>
                    <td>
                        <select id="desk_department_id" name="desk_department_id">
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected(isset($desk_settings['department_id']) ? $desk_settings['department_id'] : '', $id); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">The department to assign tickets to.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="desk_status">Default Status</label></th>
                    <td>
                        <select id="desk_status" name="desk_status">
                            <option value="Open" <?php selected(isset($desk_settings['status']) ? $desk_settings['status'] : 'Open', 'Open'); ?>>Open</option>
                            <option value="On Hold" <?php selected(isset($desk_settings['status']) ? $desk_settings['status'] : 'Open', 'On Hold'); ?>>On Hold</option>
                            <option value="Closed" <?php selected(isset($desk_settings['status']) ? $desk_settings['status'] : 'Open', 'Closed'); ?>>Closed</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="desk_priority">Default Priority</label></th>
                    <td>
                        <select id="desk_priority" name="desk_priority">
                            <option value="Low" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'Low'); ?>>Low</option>
                            <option value="Medium" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'Medium'); ?>>Medium</option>
                            <option value="High" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'High'); ?>>High</option>
                            <option value="Urgent" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'Urgent'); ?>>Urgent</option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <?php if (empty($this->portal_name)): ?>
            <div class="notice notice-warning inline">
                <p><strong>Warning:</strong> Zoho Desk portal name not found. Reconnect to Zoho to resolve this issue.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Desk settings UI for multi-module mappings
     *
     * @param array $mapping Current mapping
     * @param string $mapping_id Mapping ID
     * @return string HTML
     */
    public function render_desk_settings_ui_for_multi_mapping($mapping, $mapping_id) {
        $desk_settings = isset($mapping['desk_settings']) ? $mapping['desk_settings'] : array(
            'department_id' => '',
            'status' => 'Open',
            'priority' => 'Medium'
        );
        
        // Get departments
        $departments = $this->get_departments();
        
        ob_start();
        ?>
        <div id="desk-settings-<?php echo esc_attr($mapping_id); ?>" style="margin-bottom: 10px;">
            <h5>Zoho Desk Settings</h5>
            <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th style="width: 150px;"><label>Department:</label></th>
                    <td>
                        <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][desk_settings][department_id]">
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected(isset($desk_settings['department_id']) ? $desk_settings['department_id'] : '', $id); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">The department to assign tickets to.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Default Status:</label></th>
                    <td>
                        <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][desk_settings][status]">
                            <option value="Open" <?php selected(isset($desk_settings['status']) ? $desk_settings['status'] : 'Open', 'Open'); ?>>Open</option>
                            <option value="On Hold" <?php selected(isset($desk_settings['status']) ? $desk_settings['status'] : 'Open', 'On Hold'); ?>>On Hold</option>
                            <option value="Closed" <?php selected(isset($desk_settings['status']) ? $desk_settings['status'] : 'Open', 'Closed'); ?>>Closed</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Default Priority:</label></th>
                    <td>
                        <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][desk_settings][priority]">
                            <option value="Low" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'Low'); ?>>Low</option>
                            <option value="Medium" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'Medium'); ?>>Medium</option>
                            <option value="High" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'High'); ?>>High</option>
                            <option value="Urgent" <?php selected(isset($desk_settings['priority']) ? $desk_settings['priority'] : 'Medium', 'Urgent'); ?>>Urgent</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Process Desk settings from form submission
     *
     * @param array $mappings Current mappings
     * @param array $form_data Form POST data
     * @return array Updated mappings
     */
    public function process_desk_settings_submission($mappings, $form_data) {
        $mappings['desk_settings'] = array(
            'department_id' => isset($form_data['desk_department_id']) ? sanitize_text_field($form_data['desk_department_id']) : '',
            'status' => isset($form_data['desk_status']) ? sanitize_text_field($form_data['desk_status']) : 'Open',
            'priority' => isset($form_data['desk_priority']) ? sanitize_text_field($form_data['desk_priority']) : 'Medium'
        );
        
        return $mappings;
    }
    
    /**
     * Manually clear the organization info cache
     * 
     * @return bool Success
     */
    public function clear_organization_cache() {
        $this->organization_id = null;
        $this->portal_name = null;
        delete_option('gf_zoho_desk_portal_name');
        return delete_option('gf_zoho_desk_organization_id');
    }
}
