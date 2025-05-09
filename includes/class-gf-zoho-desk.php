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
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Zoho_API();
        $this->logger = gf_zoho_logger();
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
        
        // Fix for desk API domain
        $api_domain = $this->api->api_domain;
        // Remove "www." if present
        $api_domain = str_replace('www.', '', $api_domain);
        
        // Debug the URL construction
        $debug_url = "https://desk." . $api_domain . "/api/v1/departments";
        $this->logger->info("DEBUG - Constructed departments URL: " . $debug_url);
        
        $url = "https://desk." . $api_domain . "/api/v1/departments";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching Desk departments: {$error_message}");
            return array();
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status !== 200 || empty($data)) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->error("API error fetching Desk departments: {$error_msg}", array('status' => $status));
            return array();
        }
        
        $departments = array();
        foreach ($data as $dept) {
            $departments[$dept['id']] = $dept['name'];
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
        
        // Fix for desk API domain
        $api_domain = $this->api->api_domain;
        // Remove "www." if present
        $api_domain = str_replace('www.', '', $api_domain);
        
        $module_singular = rtrim($module, 's'); // Convert 'tickets' to 'ticket'
        
        // Debug the URL construction
        $debug_url = "https://desk." . $api_domain . "/api/v1/customFields/{$module_singular}";
        $this->logger->info("DEBUG - Constructed fields URL: " . $debug_url);
        
        $url = "https://desk." . $api_domain . "/api/v1/customFields/{$module_singular}";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error fetching Desk fields: {$error_message}");
            return array();
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status !== 200) {
            $error_msg = json_decode($body, true);
            $error_msg = isset($error_msg['message']) ? $error_msg['message'] : 'Unknown error';
            $this->logger->error("API error fetching Desk fields: {$error_msg}", array('status' => $status));
            return array();
        }
        
        $custom_fields = json_decode($body, true);
        
        // Add standard fields based on module
        $standard_fields = $this->get_standard_desk_fields($module);
        
        // Combine standard and custom fields
        $all_fields = array_merge($standard_fields, $this->format_custom_fields($custom_fields));
        
        $this->logger->info("Found " . count($all_fields) . " fields for Zoho Desk module {$module}");
        return $all_fields;
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
        
        foreach ($custom_fields as $field) {
            $formatted[] = array(
                'api_name' => $field['fieldName'],
                'label' => !empty($field['displayLabel']) ? $field['displayLabel'] : $field['fieldName'],
                'type' => isset($field['dataType']) ? strtolower($field['dataType']) : 'text',
                'required' => !empty($field['required']),
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
        
        // Determine if this is a create or update
        $is_update = !empty($record_id);
        
        // Fix for desk API domain
        $api_domain = $this->api->api_domain;
        // Remove "www." if present
        $api_domain = str_replace('www.', '', $api_domain);
        
        // Build the URL
        if ($is_update) {
            $url = "https://desk." . $api_domain . "/api/v1/{$module}/{$record_id}";
            $method = 'PATCH';
        } else {
            $url = "https://desk." . $api_domain . "/api/v1/{$module}";
            $method = 'POST';
        }
        
        // Debug the URL construction
        $this->logger->debug("Desk API Request", array(
            'url' => $url,
            'method' => $method,
            'data' => $data
        ));
        
        // Make the request
        $response = wp_remote_request($url, array(
            'method' => $method,
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
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
        
        $this->logger->debug("Desk API Response", array(
            'status' => $status,
            'body' => $body
        ));
        
        // Check for success
        $success = ($status >= 200 && $status < 300);
        
        if ($success) {
            $this->logger->info("Successfully " . ($is_update ? "updated" : "created") . " {$module} record");
            return array(
                'success' => true,
                'message' => ($is_update ? "Updated" : "Created") . " {$module} successfully",
                'data' => $result
            );
        } else {
            $error = isset($result['message']) ? $result['message'] : 'Unknown error';
            $this->logger->error("Error " . ($is_update ? "updating" : "creating") . " {$module} record: {$error}");
            return array(
                'success' => false,
                'message' => "Error: {$error}",
                'data' => $result
            );
        }
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
}