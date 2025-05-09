<?php
/**
 * GF Zoho Desk Integration
 * Adds support for Zoho Desk modules with separate authentication
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
    private $desk_token = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Zoho_API(); // Original CRM API for reference
        $this->logger = gf_zoho_logger();
        
        // Initialize Desk token and organization info
        $this->get_desk_token();
        $this->get_organization_info();
        
        // Add action to display Desk settings tab
        add_action('gform_zoho_settings_tabs', array($this, 'add_desk_settings_tab'));
        add_action('gform_zoho_settings_tab_desk', array($this, 'render_desk_settings_tab'));
        
        // Save Desk settings
        add_action('admin_init', array($this, 'save_desk_settings'));
    }
    
    /**
     * Get Desk Access Token - either from cache or refresh it
     *
     * @return string|null Access token or null if not available
     */
    public function get_desk_token() {
        // If we already have a cached token, use it
        if (!empty($this->desk_token)) {
            return $this->desk_token;
        }
        
        // Try to get from options
        $token = get_option('gf_zoho_desk_access_token', null);
        if (!empty($token)) {
            $this->desk_token = $token;
            return $token;
        }
        
        // If we have a refresh token, try to get a new access token
        $refresh_token = get_option('gf_zoho_desk_refresh_token', null);
        if (!empty($refresh_token)) {
            $result = $this->refresh_desk_token($refresh_token);
            if ($result['success']) {
                $this->desk_token = $result['access_token'];
                return $this->desk_token;
            }
        }
        
        return null;
    }
    
    /**
     * Refresh the Desk access token
     *
     * @param string $refresh_token The refresh token
     * @return array Result with success status and access token
     */
    private function refresh_desk_token($refresh_token) {
        $client_id = get_option('gf_zoho_desk_client_id', '');
        $client_secret = get_option('gf_zoho_desk_client_secret', '');
        $region = $this->get_region();
        
        $url = "https://accounts.zoho.{$region}/oauth/v2/token";
        $args = array(
            'method' => 'POST',
            'body' => array(
                'grant_type' => 'refresh_token',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token
            )
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->error("Error refreshing Desk token: " . $response->get_error_message());
            return array('success' => false);
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            update_option('gf_zoho_desk_access_token', $data['access_token']);
            $this->logger->info("Successfully refreshed Desk access token");
            return array(
                'success' => true,
                'access_token' => $data['access_token']
            );
        }
        
        $this->logger->error("Failed to refresh Desk token: " . (isset($data['error']) ? $data['error'] : 'Unknown error'));
        return array('success' => false);
    }
    
    /**
     * Get organization information from Zoho Desk
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
        
        // Check if we have a valid Desk token
        if (!$this->get_desk_token()) {
            $this->logger->error("No access token available for Zoho Desk organization info request");
            return null;
        }
        
        // Get region for Zoho API
        $region = $this->get_region();
        
        // Try to fetch organization info from Desk API
        $url = "https://desk.zoho.{$region}/api/v1/organizations";
        $this->logger->info("DEBUG - Fetching Desk organization info from: " . $url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching Desk organization info: {$error_message}");
            return null;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $this->logger->debug("Desk Organization response", array(
            'status' => $status,
            'body_sample' => substr($body, 0, 500) . (strlen($body) > 500 ? '...' : '')
        ));
        
        if ($status !== 200 || empty($data)) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching Desk organization info: {$error_msg}", array('status' => $status));
            return null;
        }
        
        // Extract the organization ID and portal name from the response
        if (isset($data['data']) && !empty($data['data'])) {
            // The first organization in the list
            $org_id = $data['data'][0]['id'];
            $portal_name = isset($data['data'][0]['portalName']) ? $data['data'][0]['portalName'] : '';
            
            $this->logger->info("Found Desk organization ID: {$org_id}, Portal Name: {$portal_name}");
            
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
            
            $this->logger->info("Found Desk organization ID: {$org_id}, Portal Name: {$portal_name}");
            
            // Cache the organization info
            update_option('gf_zoho_desk_organization_id', $org_id);
            update_option('gf_zoho_desk_portal_name', $portal_name);
            
            $this->organization_id = $org_id;
            $this->portal_name = $portal_name;
            
            return array('id' => $org_id, 'portal' => $portal_name);
        }
        
        $this->logger->error("Could not find Desk organization info in response");
        return null;
    }
    
    /**
     * Helper method to get the appropriate region for the API
     * 
     * @return string The Zoho region (com, eu, in, etc.)
     */
    private function get_region() {
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
     * @return array List of available modules
     */
    public function get_desk_modules() {
        $desk_modules = array(
            'tickets' => 'Tickets',
            'contacts' => 'Contacts',
            'accounts' => 'Accounts',
            'tasks' => 'Tasks'
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
        if (!$this->get_desk_token()) {
            $this->logger->error("No access token available for Desk departments request");
            return array(
                'support' => 'Support',
                'sales' => 'Sales',
                'billing' => 'Billing'
            );
        }
        
        // Get region for Zoho API
        $region = $this->get_region();
        
        // If we don't have organization ID, try to get it
        if (empty($this->organization_id)) {
            $this->get_organization_info();
        }
        
        if (empty($this->organization_id)) {
            $this->logger->error("No organization ID available for Desk departments request");
            return array(
                'support' => 'Support',
                'sales' => 'Sales',
                'billing' => 'Billing'
            );
        }
        
        // Try to fetch departments
        $url = "https://desk.zoho.{$region}/api/v1/departments?orgId=" . $this->organization_id;
        $this->logger->info("DEBUG - Fetching Desk departments from: " . $url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching Desk departments: {$error_message}");
            return array(
                'support' => 'Support',
                'sales' => 'Sales',
                'billing' => 'Billing'
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200 || empty($data)) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching Desk departments: {$error_msg}", array('status' => $status));
            
            // Fall back to default departments
            return array(
                'support' => 'Support',
                'sales' => 'Sales',
                'billing' => 'Billing'
            );
        }
        
        // Process departments
        $departments = array();
        if (isset($data['data'])) {
            foreach ($data['data'] as $dept) {
                if (isset($dept['id']) && isset($dept['name'])) {
                    $departments[$dept['id']] = $dept['name'];
                }
            }
        }
        
        if (empty($departments)) {
            // Fall back to default departments
            $departments = array(
                'support' => 'Support',
                'sales' => 'Sales',
                'billing' => 'Billing'
            );
        }
        
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
        if (!$this->get_desk_token()) {
            $this->logger->error("No access token available for Desk fields request");
            return $this->get_standard_fields($module);
        }
        
        // Get region for Zoho API
        $region = $this->get_region();
        
        // If we don't have organization ID, try to get it
        if (empty($this->organization_id)) {
            $this->get_organization_info();
        }
        
        if (empty($this->organization_id)) {
            $this->logger->error("No organization ID available for Desk fields request");
            return $this->get_standard_fields($module);
        }
        
        // Try specific Zoho Desk API endpoint based on module
        $fields_url = "https://desk.zoho.{$region}/api/v1/{$module}/fields?orgId={$this->organization_id}";
        $this->logger->info("DEBUG - Fetching Desk fields from: " . $fields_url);
        
        $response = wp_remote_get($fields_url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching Desk fields: {$error_message}");
            return $this->get_standard_fields($module);
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200 || empty($data)) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching Desk fields: {$error_msg}", array('status' => $status));
            
            // Try alternate endpoint for custom fields
            return $this->get_desk_custom_fields($module);
        }
        
        // Parse fields from response
        $fields = array();
        
        // Check for different response formats
        if (isset($data['fields'])) {
            foreach ($data['fields'] as $field) {
                if (isset($field['apiName']) && isset($field['label'])) {
                    $fields[] = array(
                        'api_name' => $field['apiName'],
                        'label' => $field['label'],
                        'type' => isset($field['dataType']) ? $field['dataType'] : 'text',
                        'required' => isset($field['isRequired']) ? (bool)$field['isRequired'] : false,
                        'custom' => isset($field['isCustomField']) ? (bool)$field['isCustomField'] : false
                    );
                }
            }
        } elseif (isset($data['data'])) {
            foreach ($data['data'] as $field) {
                if (isset($field['apiName']) && isset($field['label'])) {
                    $fields[] = array(
                        'api_name' => $field['apiName'],
                        'label' => $field['label'],
                        'type' => isset($field['dataType']) ? $field['dataType'] : 'text',
                        'required' => isset($field['isRequired']) ? (bool)$field['isRequired'] : false,
                        'custom' => isset($field['isCustomField']) ? (bool)$field['isCustomField'] : false
                    );
                }
            }
        }
        
        if (!empty($fields)) {
            return $fields;
        }
        
        // If still no fields, try to get custom fields
        return $this->get_desk_custom_fields($module);
    }
    
    /**
     * Get custom fields for a Zoho Desk module
     *
     * @param string $module The module name
     * @return array List of fields
     */
    private function get_desk_custom_fields($module) {
        $region = $this->get_region();
        
        // Try custom fields endpoint
        $custom_fields_url = "https://desk.zoho.{$region}/api/v1/customFields?module={$module}&orgId={$this->organization_id}";
        $this->logger->info("DEBUG - Fetching Desk custom fields from: " . $custom_fields_url);
        
        $response = wp_remote_get($custom_fields_url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching Desk custom fields: {$error_message}");
            return $this->get_standard_fields($module);
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200 || empty($data)) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching Desk custom fields: {$error_msg}", array('status' => $status));
            return $this->get_standard_fields($module);
        }
        
        // Parse custom fields
        $fields = array();
        
        // Add standard fields first
        $standard_fields = $this->get_standard_fields($module);
        $fields = array_merge($fields, $standard_fields);
        
        // Add custom fields
        if (isset($data['customFields'])) {
            foreach ($data['customFields'] as $field) {
                if (isset($field['apiName']) && isset($field['label'])) {
                    $fields[] = array(
                        'api_name' => $field['apiName'],
                        'label' => $field['label'],
                        'type' => isset($field['dataType']) ? $field['dataType'] : 'text',
                        'required' => isset($field['isRequired']) ? (bool)$field['isRequired'] : false,
                        'custom' => true
                    );
                }
            }
        } elseif (isset($data['data'])) {
            foreach ($data['data'] as $field) {
                if (isset($field['apiName']) && isset($field['label'])) {
                    $fields[] = array(
                        'api_name' => $field['apiName'],
                        'label' => $field['label'],
                        'type' => isset($field['dataType']) ? $field['dataType'] : 'text',
                        'required' => isset($field['isRequired']) ? (bool)$field['isRequired'] : false,
                        'custom' => true
                    );
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Get standard fields for modules
     *
     * @param string $module The module name
     * @return array Standard fields
     */
    private function get_standard_fields($module) {
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
                    array('api_name' => 'accountId', 'label' => 'Account', 'type' => 'lookup', 'required' => false),
                    array('api_name' => 'contactId', 'label' => 'Contact', 'type' => 'lookup', 'required' => false),
                    array('api_name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false),
                    array('api_name' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false),
                    array('api_name' => 'status', 'label' => 'Status', 'type' => 'picklist', 'required' => false),
                    array('api_name' => 'priority', 'label' => 'Priority', 'type' => 'picklist', 'required' => false),
                    array('api_name' => 'channel', 'label' => 'Channel', 'type' => 'picklist', 'required' => false),
                    array('api_name' => 'departmentId', 'label' => 'Department', 'type' => 'lookup', 'required' => false),
                    array('api_name' => 'category', 'label' => 'Category', 'type' => 'text', 'required' => false),
                    array('api_name' => 'dueDate', 'label' => 'Due Date', 'type' => 'date', 'required' => false),
                    array('api_name' => 'product', 'label' => 'Product', 'type' => 'text', 'required' => false),
                    array('api_name' => 'productId', 'label' => 'Product ID', 'type' => 'lookup', 'required' => false),
                    array('api_name' => 'classification', 'label' => 'Classification', 'type' => 'picklist', 'required' => false),
                    array('api_name' => 'assigneeId', 'label' => 'Assignee', 'type' => 'lookup', 'required' => false),
                    array('api_name' => 'teamId', 'label' => 'Team', 'type' => 'lookup', 'required' => false),
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
                    array('api_name' => 'accountId', 'label' => 'Account', 'type' => 'lookup', 'required' => false),
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
     * Submit data to Zoho Desk
     *
     * @param string $module The module name (e.g. 'tickets')
     * @param array $data The data to submit
     * @param string|null $record_id Record ID for update operations
     * @return array Result array (success, message, data)
     */
    public function submit_to_desk($module, $data, $record_id = null) {
        $this->logger->info("Submitting to Zoho Desk module: {$module}", array('data' => $data));
        
        // Check if we have a valid token
        if (!$this->get_desk_token()) {
            $this->logger->error("No access token available for Desk submission");
            return array(
                'success' => false,
                'message' => 'Not connected to Zoho Desk'
            );
        }
        
        // Get region for Zoho API
        $region = $this->get_region();
        
        // If we don't have organization ID, try to get it
        if (empty($this->organization_id)) {
            $this->get_organization_info();
        }
        
        if (empty($this->organization_id)) {
            $this->logger->error("No organization ID available for Desk submission");
            return array(
                'success' => false,
                'message' => 'No Zoho Desk organization found'
            );
        }
        
        // Determine if this is an update or create operation
        $is_update = !empty($record_id);
        
        // Build the API URL
        $url = "https://desk.zoho.{$region}/api/v1/{$module}";
        if ($is_update && $record_id) {
            $url .= "/{$record_id}";
        }
        
        // Add the organization ID to the URL
        $url .= "?orgId={$this->organization_id}";
        
        $method = $is_update ? 'PATCH' : 'POST'; // Desk uses PATCH for updates
        
        $response = wp_remote_request($url, array(
            'method' => $method,
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error submitting to Desk: {$error_message}");
            return array(
                'success' => false,
                'message' => "API Error: {$error_message}"
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if ($status < 200 || $status >= 300) {
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
        
        $this->logger->info("Successfully " . ($is_update ? "updated" : "created") . " Desk {$module} record");
        return array(
            'success' => true,
            'message' => ($is_update ? "Updated" : "Created") . " Desk {$module} successfully",
            'data' => $result
        );
    }
    
    /**
     * Add Desk tab to Zoho Settings
     *
     * @param array $tabs Current tabs
     * @return array Updated tabs
     */
    public function add_desk_settings_tab($tabs) {
        $tabs['desk'] = 'Zoho Desk';
        return $tabs;
    }
    
    /**
     * Render Desk settings tab content
     */
    public function render_desk_settings_tab() {
        $client_id = get_option('gf_zoho_desk_client_id', '');
        $client_secret = get_option('gf_zoho_desk_client_secret', '');
        $access_token = get_option('gf_zoho_desk_access_token', '');
        $refresh_token = get_option('gf_zoho_desk_refresh_token', '');
        $connected = !empty($access_token) || !empty($refresh_token);
        $region = $this->get_region();
        
        // Generate authorization URL
        $redirect_uri = urlencode(admin_url('admin.php?page=gf_settings&subview=Zoho&tab=desk&auth=1'));
        $scopes = urlencode('Desk.tickets.ALL,Desk.contacts.ALL,Desk.basic.READ,Desk.search.READ,Desk.settings.ALL,Desk.fields.ALL');
        $auth_url = "https://accounts.zoho.{$region}/oauth/v2/auth?scope={$scopes}&client_id={$client_id}&response_type=code&access_type=offline&redirect_uri={$redirect_uri}&state=zoho_desk_auth";
        
        // Handle authorization code
        if (isset($_GET['auth']) && isset($_GET['code']) && isset($_GET['state']) && $_GET['state'] === 'zoho_desk_auth') {
            $code = sanitize_text_field($_GET['code']);
            $result = $this->exchange_code_for_tokens($code, $client_id, $client_secret, $redirect_uri, $region);
            
            if (isset($result['success']) && $result['success']) {
                echo '<div class="notice notice-success"><p>Successfully connected to Zoho Desk!</p></div>';
                $connected = true;
            } else {
                $error = isset($result['message']) ? $result['message'] : 'Unknown error';
                echo '<div class="notice notice-error"><p>Error connecting to Zoho Desk: ' . esc_html($error) . '</p></div>';
            }
        }
        
        // Handle disconnect
        if (isset($_GET['disconnect']) && $_GET['disconnect'] === '1') {
            delete_option('gf_zoho_desk_access_token');
            delete_option('gf_zoho_desk_refresh_token');
            echo '<div class="notice notice-success"><p>Successfully disconnected from Zoho Desk.</p></div>';
            $connected = false;
        }
        
        ?>
        <h3>Zoho Desk API Configuration</h3>
        <p>Configure your Zoho Desk API credentials to enable Desk modules in Gravity Forms.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('gf_zoho_desk_settings', 'gf_zoho_desk_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="client_id" name="client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text">
                        <p class="description">Your Zoho Desk API Client ID</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" id="client_secret" name="client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text">
                        <p class="description">Your Zoho Desk API Client Secret</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="save_desk_settings" class="button-primary" value="Save Settings">
                
                <?php if (!empty($client_id) && !empty($client_secret)): ?>
                    <?php if (!$connected): ?>
                        <a href="<?php echo esc_url($auth_url); ?>" class="button-secondary">Connect to Zoho Desk</a>
                    <?php else: ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=gf_settings&subview=Zoho&tab=desk&disconnect=1')); ?>" class="button-secondary">Disconnect from Zoho Desk</a>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </form>
        
        <hr>
        
        <h3>Connection Status</h3>
        <?php if ($connected): ?>
            <div class="notice notice-success inline">
                <p><strong>Connected to Zoho Desk!</strong> Your Gravity Forms can now use Zoho Desk modules.</p>
            </div>
        <?php else: ?>
            <div class="notice notice-warning inline">
                <p><strong>Not connected to Zoho Desk.</strong> You need to connect to Zoho Desk to use Desk modules and custom fields.</p>
            </div>
        <?php endif; ?>
        
        <hr>
        
        <h3>How to Set Up Zoho Desk Integration</h3>
        <ol>
            <li>Go to the <a href="https://api-console.zoho.com/" target="_blank">Zoho API Console</a> and create a new client.</li>
            <li>Set the redirect URI to: <code><?php echo esc_url(admin_url('admin.php?page=gf_settings&subview=Zoho&tab=desk&auth=1')); ?></code></li>
            <li>Select at least these Zoho Desk scopes: 
                <ul>
                    <li>Desk.tickets.ALL</li>
                    <li>Desk.contacts.ALL</li>
                    <li>Desk.basic.READ</li>
                    <li>Desk.search.READ</li>
                    <li>Desk.settings.ALL</li>
                    <li>Desk.fields.ALL</li>
                </ul>
            </li>
            <li>Copy the Client ID and Client Secret to this page.</li>
            <li>Click "Save Settings" and then "Connect to Zoho Desk".</li>
            <li>After successful authentication, you can use Desk modules in your Gravity Forms Zoho feeds.</li>
        </ol>
        <?php
    }
    
    /**
     * Save Desk settings
     */
    public function save_desk_settings() {
        if (isset($_POST['save_desk_settings']) && isset($_POST['gf_zoho_desk_nonce']) && wp_verify_nonce($_POST['gf_zoho_desk_nonce'], 'gf_zoho_desk_settings')) {
            $client_id = isset($_POST['client_id']) ? sanitize_text_field($_POST['client_id']) : '';
            $client_secret = isset($_POST['client_secret']) ? sanitize_text_field($_POST['client_secret']) : '';
            
            update_option('gf_zoho_desk_client_id', $client_id);
            update_option('gf_zoho_desk_client_secret', $client_secret);
        }
    }
    
    /**
     * Exchange authorization code for access/refresh tokens
     *
     * @param string $code The authorization code
     * @param string $client_id The client ID
     * @param string $client_secret The client secret
     * @param string $redirect_uri The redirect URI
     * @param string $region The Zoho region
     * @return array Result with tokens or error
     */
    private function exchange_code_for_tokens($code, $client_id, $client_secret, $redirect_uri, $region) {
        $url = "https://accounts.zoho.{$region}/oauth/v2/token";
        $args = array(
            'method' => 'POST',
            'body' => array(
                'grant_type' => 'authorization_code',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
                'redirect_uri' => $redirect_uri
            )
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token']) && isset($data['refresh_token'])) {
            update_option('gf_zoho_desk_access_token', $data['access_token']);
            update_option('gf_zoho_desk_refresh_token', $data['refresh_token']);
            
            return array(
                'success' => true,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token']
            );
        } else {
            $error = isset($data['error']) ? $data['error'] : 'Unknown error';
            return array(
                'success' => false,
                'message' => $error
            );
        }
    }
    
    /**
     * Add UI for Desk settings & authentication in feed settings
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
            <h3>Zoho Desk Settings</h3>
            
            <?php if (empty($this->desk_token)): ?>
            <div class="notice notice-warning inline">
                <p><strong>Zoho Desk Connection Required</strong>: You need to connect Zoho Desk to access modules and custom fields.</p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=gf_settings&subview=Zoho&tab=desk')); ?>" class="button button-primary">
                        Configure Zoho Desk Connection
                    </a>
                </p>
            </div>
            <?php else: ?>
            <div class="notice notice-success inline">
                <p><strong>Connected to Zoho Desk</strong>: You can now access Desk modules and custom fields.</p>
            </div>
            <?php endif; ?>
            
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
                            <option value="Pending" <?php selected(isset($desk_settings['status']) ? $desk_settings['status'] : 'Open', 'Pending'); ?>>Pending</option>
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
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize the Desk integration
function gf_zoho_init_desk_integration() {
    global $gf_zoho_desk;
    $gf_zoho_desk = new GF_Zoho_Desk();
}
add_action('init', 'gf_zoho_init_desk_integration');
