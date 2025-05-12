<?php
/**
 * GF Zoho Multi-Module Handler
 * Handles syncing to multiple Zoho modules with conditional logic
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Multi_Module {
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
     * Process multi-module mappings from a form submission
     *
     * @param array $entry The Gravity Forms entry
     * @param array $form The form object
     * @return array Results of each module submission
     */
    public function process_multi_module_submission($entry, $form) {
        $this->logger->info("Processing multi-module submission for form {$form['id']}");
        
        // Get form mappings
        $multi_mappings = self::get_multi_module_mappings($form['id']);
        
        if (empty($multi_mappings)) {
            $this->logger->info("No multi-module mappings found for form {$form['id']}");
            return array();
        }
        
        // Check if entry was created by Zoho (to avoid infinite loops in two-way sync)
        if (isset($entry['source']) && ($entry['source'] === 'zoho' || $entry['source'] === 'zoho_desk')) {
            $this->logger->info("Entry was created by Zoho, skipping multi-module processing");
            return array();
        }
        
        // Process each module mapping
        $results = array();
        $record_ids = array(); // Store created/updated record IDs for reference in later mappings
        
        foreach ($multi_mappings as $mapping_id => $mapping) {
            // Check if conditions are met
            if (!$this->check_conditions($mapping, $entry, $form)) {
                $this->logger->info("Conditions not met for mapping ID: {$mapping_id}, skipping");
                continue;
            }
            
            $this->logger->info("Processing mapping ID: {$mapping_id} for module: {$mapping['module']}");
            
            // Process the mapping
            $result = $this->process_module_mapping($mapping, $entry, $form, $record_ids);
            
            // Store the result
            $results[$mapping_id] = $result;
            
            // If successful and we got a record ID, store it for reference
            if ($result['success'] && !empty($result['record_id'])) {
                $module_key = $mapping['module'];
                $record_ids[$module_key] = $result['record_id'];
                
                // Store the reference ID in the entry meta
                $meta_key = "zoho_record_id_{$module_key}";
                gform_update_meta($entry['id'], $meta_key, $result['record_id']);
                
                $this->logger->info("Stored record ID for module {$module_key}: {$result['record_id']}");
            }
        }
        
        return $results;
    }
    
    /**
     * Process a single module mapping
     *
     * @param array $mapping The module mapping configuration
     * @param array $entry The Gravity Forms entry
     * @param array $form The form object
     * @param array $record_ids Previously created/updated record IDs
     * @return array Result of the submission
     */
    private function process_module_mapping($mapping, $entry, $form, $record_ids) {
        $module = $mapping['module'];
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        // Check if connected
        if (!$this->api->get_access_token()) {
            $this->logger->error("Not connected to Zoho");
            return array(
                'success' => false,
                'message' => 'Not connected to Zoho',
                'module' => $module
            );
        }
        
        // Prepare data for Zoho
        $data = array();
        
        // Process field mappings
        foreach ($mapping['fields'] as $gf_field => $zoho_field) {
            if (empty($gf_field) || empty($zoho_field)) {
                continue;
            }
            
            // Special handling for entry_id
            if ($gf_field === 'entry_id') {
                $value = $entry['id'];
                $this->logger->info("Using entry ID {$value} for Zoho field {$zoho_field}");
            } 
            // Check if this is a reference to another module's record ID
            elseif (strpos($gf_field, 'module_id:') === 0) {
                $referenced_module = substr($gf_field, 10); // Remove 'module_id:' prefix
                if (isset($record_ids[$referenced_module])) {
                    $value = $record_ids[$referenced_module];
                    $this->logger->info("Using record ID {$value} from module {$referenced_module} for Zoho field {$zoho_field}");
                } else {
                    $this->logger->warning("No record ID found for referenced module {$referenced_module}");
                    continue;
                }
            } 
            // Normal form field
            else {
                $value = rgar($entry, $gf_field);
            }
            
            // Skip empty values
            if (empty($value)) {
                continue;
            }
            
            // Add to data
            $data[$zoho_field] = $value;
        }
        
        // Process custom values if specified
        if (!empty($mapping['custom_values']) && class_exists('GF_Zoho_Custom_Values')) {
            $custom_values = new GF_Zoho_Custom_Values();
            $data = $custom_values->process_custom_values_from_array($data, $mapping['custom_values'], $entry, $form);
        }
        
        if (empty($data)) {
            $this->logger->error("No data to send to Zoho for module {$module}");
            return array(
                'success' => false,
                'message' => 'No data to send',
                'module' => $module
            );
        }
        
        // Add form ID and entry ID for reference
        $data['GF_Form_ID'] = $form['id'];
        $data['GF_Entry_ID'] = $entry['id'];
        
        // Process lookup fields if this is a CRM module
        if (!$is_desk_module && class_exists('GF_Zoho_Lookup_Handler')) {
            $lookup_handler = new GF_Zoho_Lookup_Handler();
            $data = $lookup_handler->process_lookup_fields($data, array(), $module);
        }
        
        // Handle lookup for existing record
        $record_id = null;
        if (!empty($mapping['lookup_field']) && !empty($mapping['lookup_value'])) {
            $lookup_field = $mapping['lookup_field'];
            $lookup_value_field = $mapping['lookup_value'];
            
            // Determine the lookup value based on type
            if ($lookup_value_field === 'entry_id') {
                $lookup_value = $entry['id'];
            } elseif (strpos($lookup_value_field, 'module_id:') === 0) {
                $referenced_module = substr($lookup_value_field, 10);
                $lookup_value = isset($record_ids[$referenced_module]) ? $record_ids[$referenced_module] : null;
            } else {
                $lookup_value = rgar($entry, $lookup_value_field);
            }
            
            if (!empty($lookup_value)) {
                $record_id = $this->lookup_record($module, $lookup_field, $lookup_value);
                
                if ($record_id) {
                    $this->logger->info("Found existing record {$record_id} for module {$module} with {$lookup_field} = {$lookup_value}");
                } else {
                    $this->logger->info("No existing record found for module {$module} with {$lookup_field} = {$lookup_value}");
                }
            }
        }
        
        // Determine if we should create a new record or update existing
        $create_mode = empty($record_id) || !empty($mapping['force_create']);
        
        // Send data to Zoho
        if ($is_desk_module) {
            // Use Zoho Desk API
            return $this->send_to_desk_module($module, $data, $record_id, $mapping, $create_mode);
        } else {
            // Use Zoho CRM API
            return $this->send_to_crm_module($module, $data, $record_id, $create_mode);
        }
    }
    
    /**
     * Send data to a Zoho CRM module
     *
     * @param string $module The CRM module
     * @param array $data The data to send
     * @param string|null $record_id Record ID for update operations
     * @param bool $create_mode Whether to create a new record
     * @return array Result of the operation
     */
    private function send_to_crm_module($module, $data, $record_id, $create_mode) {
        if ($create_mode) {
            // Create new record
            $url = "https://{$this->api->api_domain}/crm/v2/{$module}";
            $method = 'POST';
            $this->logger->info("Creating new record in {$module}");
        } else {
            // Update existing record
            $url = "https://{$this->api->api_domain}/crm/v2/{$module}/{$record_id}";
            $method = 'PUT';
            $this->logger->info("Updating record {$record_id} in {$module}");
        }
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array('data' => array($data)))
        );
        
        // Make the request
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error sending data to Zoho CRM: {$error_message}");
            return array(
                'success' => false,
                'message' => "API Error: {$error_message}",
                'module' => $module
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if ($status >= 200 && $status < 300) {
            $this->logger->info("Successfully " . ($create_mode ? "created" : "updated") . " record in {$module}");
            
            // Get the record ID
            $zoho_id = null;
            if ($create_mode && !empty($result['data']) && !empty($result['data'][0]['details']['id'])) {
                $zoho_id = $result['data'][0]['details']['id'];
                $this->logger->info("Record created with ID: {$zoho_id}");
            } else {
                $zoho_id = $record_id; // Use the existing ID for updates
            }
            
            return array(
                'success' => true,
                'message' => ($create_mode ? "Created" : "Updated") . " record in {$module}",
                'module' => $module,
                'record_id' => $zoho_id
            );
        } else {
            $error = isset($result['message']) ? $result['message'] : 'Unknown error';
            $this->logger->error("Error " . ($create_mode ? "creating" : "updating") . " record in {$module}: {$error}");
            return array(
                'success' => false,
                'message' => "API Error: {$error}",
                'module' => $module
            );
        }
    }
    
    /**
     * Send data to a Zoho Desk module
     *
     * @param string $module The Desk module
     * @param array $data The data to send
     * @param string|null $record_id Record ID for update operations
     * @param array $mapping The module mapping configuration
     * @param bool $create_mode Whether to create a new record
     * @return array Result of the operation
     */
    private function send_to_desk_module($module, $data, $record_id, $mapping, $create_mode) {
        $desk_module = str_replace('desk_', '', $module);
        
        if (class_exists('GF_Zoho_Desk')) {
            $desk = new GF_Zoho_Desk();
            
            // Add Desk settings if available
            if (isset($mapping['desk_settings'])) {
                $desk_settings = $mapping['desk_settings'];
                
                // Add department ID
                if (!isset($data['departmentId']) && !empty($desk_settings['department_id'])) {
                    $data['departmentId'] = $desk_settings['department_id'];
                }
                
                // Add status if not already set
                if (!isset($data['status']) && !empty($desk_settings['status'])) {
                    $data['status'] = $desk_settings['status'];
                }
                
                // Add priority if not already set
                if (!isset($data['priority']) && !empty($desk_settings['priority'])) {
                    $data['priority'] = $desk_settings['priority'];
                }
            }
            
            $result = $desk->submit_to_desk($desk_module, $data, $create_mode ? null : $record_id);
            
            if ($result['success']) {
                // Get the record ID
                $zoho_id = null;
                if ($create_mode && !empty($result['data']['id'])) {
                    $zoho_id = $result['data']['id'];
                    $this->logger->info("Desk record created with ID: {$zoho_id}");
                } else {
                    $zoho_id = $record_id; // Use the existing ID for updates
                }
                
                return array(
                    'success' => true,
                    'message' => $result['message'],
                    'module' => $module,
                    'record_id' => $zoho_id
                );
            } else {
                return array(
                    'success' => false,
                    'message' => $result['message'],
                    'module' => $module
                );
            }
        } else {
            $this->logger->error("GF_Zoho_Desk class not found, cannot send to Desk");
            return array(
                'success' => false,
                'message' => "Zoho Desk integration not available",
                'module' => $module
            );
        }
    }
    
    /**
     * Look up a record in Zoho by field value
     *
     * @param string $module The Zoho module
     * @param string $field The field to search by
     * @param string $value The value to search for
     * @return string|null Record ID if found, null otherwise
     */
    private function lookup_record($module, $field, $value) {
        $this->logger->info("Looking up record in {$module} with {$field} = {$value}");
        
        // Check if we have a valid token
        if (!$this->api->get_access_token()) {
            $this->logger->error("Not connected to Zoho");
            return null;
        }
        
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        // Build the URL
        if ($is_desk_module) {
            $desk_module = str_replace('desk_', '', $module);
            $url = "https://desk.{$this->api->api_domain}/api/v1/{$desk_module}/search?{$field}=" . urlencode($value);
        } else {
            $url = "https://{$this->api->api_domain}/crm/v2/{$module}/search?criteria=({$field}:equals:{$value})";
        }
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken " . $this->api->get_access_token(),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error("Error looking up record: {$error_message}");
            return null;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if ($status !== 200 || empty($result)) {
            $error = isset($result['message']) ? $result['message'] : 'Unknown error';
            $this->logger->error("API error looking up record: {$error}");
            return null;
        }
        
        // Different response formats for Desk vs CRM
        if ($is_desk_module) {
            if (!empty($result['data'])) {
                $record_id = $result['data'][0]['id'];
                $this->logger->info("Found Desk record: {$record_id}");
                return $record_id;
            }
        } else {
            if (!empty($result['data'])) {
                $record_id = $result['data'][0]['id'];
                $this->logger->info("Found CRM record: {$record_id}");
                return $record_id;
            }
        }
        
        $this->logger->info("No record found");
        return null;
    }
    
    /**
     * Check if conditions are met for a mapping
     *
     * @param array $mapping The mapping configuration
     * @param array $entry The Gravity Forms entry
     * @param array $form The form object
     * @return bool True if conditions are met, false otherwise
     */
    private function check_conditions($mapping, $entry, $form) {
        // If no conditions, always process
        if (empty($mapping['conditions'])) {
            return true;
        }
        
        $this->logger->info("Checking conditions for mapping");
        
        $match_all = isset($mapping['condition_logic']) && $mapping['condition_logic'] === 'all';
        $conditions_met = false;
        
        foreach ($mapping['conditions'] as $condition) {
            if (empty($condition['field']) || empty($condition['operator']) || !isset($condition['value'])) {
                continue;
            }
            
            $field = $condition['field'];
            $operator = $condition['operator'];
            $expected_value = $condition['value'];
            
            // Get actual value from entry
            $actual_value = rgar($entry, $field);
            
            // Compare values
            $condition_met = $this->compare_values($actual_value, $expected_value, $operator);
            
            $this->logger->info("Condition: {$field} {$operator} {$expected_value} - Actual value: {$actual_value} - Result: " . ($condition_met ? 'true' : 'false'));
            
            if ($match_all && !$condition_met) {
                // If matching all conditions and one fails, the entire check fails
                return false;
            } elseif (!$match_all && $condition_met) {
                // If matching any condition and one passes, the entire check passes
                return true;
            }
        }
        
        // If matching all conditions and we got here, all passed
        // If matching any condition and we got here, none passed
        return $match_all;
    }
    
    /**
     * Compare values based on operator
     *
     * @param mixed $actual The actual value
     * @param mixed $expected The expected value
     * @param string $operator The comparison operator
     * @return bool Result of the comparison
     */
    private function compare_values($actual, $expected, $operator) {
        switch ($operator) {
            case 'is':
                return (string)$actual === (string)$expected;
                
            case 'isnot':
                return (string)$actual !== (string)$expected;
                
            case 'contains':
                return stripos((string)$actual, (string)$expected) !== false;
                
            case 'doesnotcontain':
                return stripos((string)$actual, (string)$expected) === false;
                
            case 'startswith':
                return stripos((string)$actual, (string)$expected) === 0;
                
            case 'endswith':
                $actual_len = strlen((string)$actual);
                $expected_len = strlen((string)$expected);
                return $expected_len <= $actual_len && substr_compare((string)$actual, (string)$expected, $actual_len - $expected_len, $expected_len, true) === 0;
                
            case 'greater_than':
                return floatval($actual) > floatval($expected);
                
            case 'less_than':
                return floatval($actual) < floatval($expected);
                
            case 'is_empty':
                return empty($actual);
                
            case 'is_not_empty':
                return !empty($actual);
                
            default:
                return false;
        }
    }
    
    /**
     * Get multi-module mappings for a form
     *
     * @param int $form_id The form ID
     * @return array Multi-module mappings
     */
    public static function get_multi_module_mappings($form_id) {
        $mappings = get_option("gf_zoho_multi_mappings_{$form_id}", array());
        return $mappings;
    }
    
    /**
     * Save multi-module mappings for a form
     *
     * @param int $form_id The form ID
     * @param array $mappings The mappings to save
     * @return bool Success
     */
    public static function save_multi_module_mappings($form_id, $mappings) {
        return update_option("gf_zoho_multi_mappings_{$form_id}", $mappings);
    }
    
    /**
     * Render multi-module mappings UI
     *
     * @param int $form_id The form ID
     * @param array $form The form object
     * @return string HTML
     */
    public function render_multi_module_ui($form_id, $form) {
        $mappings = self::get_multi_module_mappings($form_id);
        
        // Get Zoho modules
        $modules = array(
            'Leads' => 'Leads',
            'Contacts' => 'Contacts',
            'Accounts' => 'Accounts',
            'Deals' => 'Deals',
            'Campaigns' => 'Campaigns',
            'Tasks' => 'Tasks',
            'Cases' => 'Cases',
            'Events' => 'Events',
            'Calls' => 'Calls',
            'Solutions' => 'Solutions',
            'Products' => 'Products',
            'Vendors' => 'Vendors',
            'PriceBooks' => 'Price Books',
            'Quotes' => 'Quotes',
            'SalesOrders' => 'Sales Orders',
            'PurchaseOrders' => 'Purchase Orders',
            'Invoices' => 'Invoices',
            'Notes' => 'Notes'
        );
        
        // Add Desk modules if available
        if (class_exists('GF_Zoho_Desk')) {
            $desk = new GF_Zoho_Desk();
            $desk_modules = $desk->get_desk_modules();
            
            foreach ($desk_modules as $key => $label) {
                $modules['desk_' . $key] = 'Desk: ' . $label;
            }
        }
        
        // Get form fields for conditions
        $form_fields = array();
        foreach ($form['fields'] as $field) {
            $form_fields[$field->id] = $field->label;
        }
        
        ob_start();
        ?>
        <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
            <h3>Multi-Module Mappings</h3>
            <p>Configure mappings to multiple Zoho modules with conditional logic. This allows you to create related records in Zoho.</p>
            
            <div id="multi-module-mappings">
                <?php if (empty($mappings)): ?>
                    <p>No multi-module mappings configured yet.</p>
                <?php else: ?>
                    <?php foreach ($mappings as $mapping_id => $mapping): ?>
                        <div class="multi-module-mapping" id="mapping-<?php echo esc_attr($mapping_id); ?>" style="margin-bottom: 20px; border: 1px solid #ddd; padding: 15px;">
                            <h4>Mapping: <?php echo esc_html($mapping['module']); ?></h4>
                            
                            <div style="margin-bottom: 10px;">
                                <label for="module-<?php echo esc_attr($mapping_id); ?>"><strong>Zoho Module:</strong></label>
                                <select id="module-<?php echo esc_attr($mapping_id); ?>" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][module]" class="multi-module-select">
                                    <option value="">-- Select Module --</option>
                                    <?php foreach ($modules as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($mapping['module'], $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <label style="margin-left: 15px;">
                                    <input type="checkbox" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][force_create]" value="1" <?php checked(!empty($mapping['force_create'])); ?>>
                                    Always create new record (ignore lookup)
                                </label>
                            </div>
                            
                            <div style="margin-bottom: 10px;">
                                <h5>Conditional Logic</h5>
                                <label>
                                    <input type="radio" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][condition_logic]" value="all" <?php checked(empty($mapping['condition_logic']) || $mapping['condition_logic'] === 'all'); ?>>
                                    Process if ALL conditions match
                                </label>
                                <label style="margin-left: 15px;">
                                    <input type="radio" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][condition_logic]" value="any" <?php checked(isset($mapping['condition_logic']) && $mapping['condition_logic'] === 'any'); ?>>
                                    Process if ANY condition matches
                                </label>
                                
                                <div class="conditions-container" style="margin-top: 10px;">
                                    <?php 
                                    if (!empty($mapping['conditions'])) {
                                        foreach ($mapping['conditions'] as $condition_index => $condition):
                                    ?>
                                        <div class="condition-row" style="margin-bottom: 5px;">
                                            <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][conditions][<?php echo esc_attr($condition_index); ?>][field]" class="condition-field">
                                                <option value="">-- Select Field --</option>
                                                <?php foreach ($form_fields as $field_id => $field_label): ?>
                                                    <option value="<?php echo esc_attr($field_id); ?>" <?php selected($condition['field'], $field_id); ?>><?php echo esc_html($field_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            
                                            <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][conditions][<?php echo esc_attr($condition_index); ?>][operator]" class="condition-operator">
                                                <option value="is" <?php selected($condition['operator'], 'is'); ?>>is</option>
                                                <option value="isnot" <?php selected($condition['operator'], 'isnot'); ?>>is not</option>
                                                <option value="contains" <?php selected($condition['operator'], 'contains'); ?>>contains</option>
                                                <option value="doesnotcontain" <?php selected($condition['operator'], 'doesnotcontain'); ?>>does not contain</option>
                                                <option value="startswith" <?php selected($condition['operator'], 'startswith'); ?>>starts with</option>
                                                <option value="endswith" <?php selected($condition['operator'], 'endswith'); ?>>ends with</option>
                                                <option value="greater_than" <?php selected($condition['operator'], 'greater_than'); ?>>greater than</option>
                                                <option value="less_than" <?php selected($condition['operator'], 'less_than'); ?>>less than</option>
                                                <option value="is_empty" <?php selected($condition['operator'], 'is_empty'); ?>>is empty</option>
                                                <option value="is_not_empty" <?php selected($condition['operator'], 'is_not_empty'); ?>>is not empty</option>
                                            </select>
                                            
                                            <input type="text" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][conditions][<?php echo esc_attr($condition_index); ?>][value]" value="<?php echo esc_attr($condition['value']); ?>" class="condition-value">
                                            
                                            <button type="button" class="button remove-condition">Remove</button>
                                        </div>
                                    <?php 
                                        endforeach;
                                    } else {
                                    ?>
                                        <div class="condition-row" style="margin-bottom: 5px;">
                                            <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][conditions][0][field]" class="condition-field">
                                                <option value="">-- Select Field --</option>
                                                <?php foreach ($form_fields as $field_id => $field_label): ?>
                                                    <option value="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($field_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            
                                            <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][conditions][0][operator]" class="condition-operator">
                                                <option value="is">is</option>
                                                <option value="isnot">is not</option>
                                                <option value="contains">contains</option>
                                                <option value="doesnotcontain">does not contain</option>
                                                <option value="startswith">starts with</option>
                                                <option value="endswith">ends with</option>
                                                <option value="greater_than">greater than</option>
                                                <option value="less_than">less than</option>
                                                <option value="is_empty">is empty</option>
                                                <option value="is_not_empty">is not empty</option>
                                            </select>
                                            
                                            <input type="text" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][conditions][0][value]" value="" class="condition-value">
                                            
                                            <button type="button" class="button remove-condition">Remove</button>
                                        </div>
                                    <?php } ?>
                                </div>
                                
                                <button type="button" class="button add-condition" data-mapping-id="<?php echo esc_attr($mapping_id); ?>">Add Condition</button>
                            </div>
                            
                            <div style="margin-bottom: 10px;">
                                <h5>Record Lookup (Optional)</h5>
                                <table class="form-table" style="margin-top: 0;">
                                    <tr>
                                        <th style="width: 150px;"><label>Lookup Field:</label></th>
                                        <td>
                                            <input type="text" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][lookup_field]" value="<?php echo esc_attr(!empty($mapping['lookup_field']) ? $mapping['lookup_field'] : ''); ?>" placeholder="Zoho field name" class="regular-text">
                                            <p class="description">The Zoho field to use for finding existing records.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label>Value Field:</label></th>
                                        <td>
                                            <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][lookup_value]">
                                                <option value="">-- Select Form Field --</option>
                                                <?php foreach ($form['fields'] as $field): ?>
                                                    <option value="<?php echo $field->id; ?>" <?php selected(!empty($mapping['lookup_value']) && $mapping['lookup_value'] == $field->id); ?>><?php echo esc_html($field->label); ?></option>
                                                <?php endforeach; ?>
                                                <option value="entry_id" <?php selected(!empty($mapping['lookup_value']) && $mapping['lookup_value'] == 'entry_id'); ?>>Entry ID</option>
                                                
                                                <?php if (!empty($mappings)): ?>
                                                    <optgroup label="Module Record IDs">
                                                    <?php foreach ($mappings as $ref_mapping_id => $ref_mapping): 
                                                        if ($ref_mapping_id == $mapping_id) continue; // Skip self-reference
                                                    ?>
                                                        <option value="module_id:<?php echo esc_attr($ref_mapping['module']); ?>" <?php selected(!empty($mapping['lookup_value']) && $mapping['lookup_value'] == "module_id:{$ref_mapping['module']}"); ?>>
                                                            ID from <?php echo esc_html($modules[$ref_mapping['module']]); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endif; ?>
                                            </select>
                                            <p class="description">The form field or reference that contains the value to look up in Zoho.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div style="margin-bottom: 10px;">
                                <h5>Field Mappings</h5>
                                <table class="widefat field-mapping-table">
                                    <thead>
                                        <tr>
                                            <th>Form Field</th>
                                            <th>Zoho Field</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        if (!empty($mapping['fields'])) {
                                            foreach ($mapping['fields'] as $gf_field_id => $zoho_field):
                                                // Determine if this is a special field
                                                $is_entry_id = ($gf_field_id === 'entry_id');
                                                $is_module_reference = (strpos($gf_field_id, 'module_id:') === 0);
                                                
                                                // Get field label for display
                                                $field_label = 'Unknown Field';
                                                if ($is_entry_id) {
                                                    $field_label = 'Entry ID';
                                                } elseif ($is_module_reference) {
                                                    $ref_module = substr($gf_field_id, 10); // Remove 'module_id:' prefix
                                                    $field_label = "ID from " . (isset($modules[$ref_module]) ? $modules[$ref_module] : $ref_module);
                                                } else {
                                                    foreach ($form['fields'] as $field) {
                                                        if ($field->id == $gf_field_id) {
                                                            $field_label = $field->label;
                                                            break;
                                                        }
                                                    }
                                                }
                                        ?>
                                            <tr class="field-mapping-row">
                                                <td>
                                                    <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][fields][<?php echo esc_attr($gf_field_id); ?>]" class="gf-field-select">
                                                        <option value="">-- Select Form Field --</option>
                                                        <?php foreach ($form['fields'] as $field): ?>
                                                            <option value="<?php echo $field->id; ?>" <?php selected($gf_field_id, $field->id); ?>><?php echo esc_html($field->label); ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="entry_id" <?php selected($gf_field_id, 'entry_id'); ?>>Entry ID</option>
                                                        
                                                        <?php if (!empty($mappings)): ?>
                                                            <optgroup label="Module Record IDs">
                                                            <?php foreach ($mappings as $ref_mapping_id => $ref_mapping): 
                                                                if ($ref_mapping_id == $mapping_id) continue; // Skip self-reference
                                                            ?>
                                                                <option value="module_id:<?php echo esc_attr($ref_mapping['module']); ?>" <?php selected($gf_field_id, "module_id:{$ref_mapping['module']}"); ?>>
                                                                    ID from <?php echo esc_html($modules[$ref_mapping['module']]); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endif; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][fields][zoho_field][]" value="<?php echo esc_attr($zoho_field); ?>" placeholder="Zoho field name" class="regular-text">
                                                </td>
                                                <td>
                                                    <button type="button" class="button remove-field-mapping">Remove</button>
                                                </td>
                                            </tr>
                                        <?php 
                                            endforeach;
                                        } else {
                                        ?>
                                            <tr class="field-mapping-row">
                                                <td>
                                                    <select name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][fields][gf_field][]" class="gf-field-select">
                                                        <option value="">-- Select Form Field --</option>
                                                        <?php foreach ($form['fields'] as $field): ?>
                                                            <option value="<?php echo $field->id; ?>"><?php echo esc_html($field->label); ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="entry_id">Entry ID</option>
                                                        
                                                        <?php if (!empty($mappings)): ?>
                                                            <optgroup label="Module Record IDs">
                                                            <?php foreach ($mappings as $ref_mapping_id => $ref_mapping): 
                                                                if ($ref_mapping_id == $mapping_id) continue; // Skip self-reference
                                                            ?>
                                                                <option value="module_id:<?php echo esc_attr($ref_mapping['module']); ?>">
                                                                    ID from <?php echo esc_html($modules[$ref_mapping['module']]); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endif; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" name="multi_mapping[<?php echo esc_attr($mapping_id); ?>][fields][zoho_field][]" value="" placeholder="Zoho field name" class="regular-text">
                                                </td>
                                                <td>
                                                    <button type="button" class="button remove-field-mapping">Remove</button>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                                
                                <button type="button" class="button add-field-mapping" data-mapping-id="<?php echo esc_attr($mapping_id); ?>">Add Field Mapping</button>
                            </div>
                            
                            <?php
                            // Add custom values UI if available
                            if (class_exists('GF_Zoho_Custom_Values')) {
                                $custom_values = new GF_Zoho_Custom_Values();
                                echo $custom_values->render_custom_values_ui_for_multi_mapping($mapping, $form, $mapping_id);
                            }
                            
                            // Add Desk settings if needed
                            if (class_exists('GF_Zoho_Desk') && strpos($mapping['module'], 'desk_') === 0) {
                                $desk = new GF_Zoho_Desk();
                                echo $desk->render_desk_settings_ui_for_multi_mapping($mapping, $mapping_id);
                            }
                            ?>
                            
                            <div style="margin-top: 10px; text-align: right;">
                                <button type="button" class="button remove-mapping" data-mapping-id="<?php echo esc_attr($mapping_id); ?>">Remove Mapping</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <p style="margin-top: 15px;">
                <button type="button" class="button button-primary add-mapping">Add Module Mapping</button>
            </p>
            
            <div style="margin-top: 20px; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">
                <h4>Multi-Module Mapping Help</h4>
                <p><strong>Example scenario:</strong> When submitting a support case, create a Contact if it doesn't exist, then create a Case linked to that Contact.</p>
                <ol>
                    <li>Create one mapping for the Contact module with a lookup to check if the contact already exists</li>
                    <li>Create another mapping for the Case module that references the Contact's record ID</li>
                    <li>Use conditions to control when each mapping should be processed</li>
                </ol>
                <p><strong>Note:</strong> Mappings are processed in the order they appear. Make sure to create parent records before child records that reference them.</p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Generate unique IDs for new mappings
            function generateMappingId() {
                return 'mapping_' + new Date().getTime();
            }
            
            // Add new mapping
            $('.add-mapping').on('click', function() {
                var mappingId = generateMappingId();
                var template = `
                    <div class="multi-module-mapping" id="mapping-${mappingId}" style="margin-bottom: 20px; border: 1px solid #ddd; padding: 15px;">
                        <h4>New Mapping</h4>
                        
                        <div style="margin-bottom: 10px;">
                            <label for="module-${mappingId}"><strong>Zoho Module:</strong></label>
                            <select id="module-${mappingId}" name="multi_mapping[${mappingId}][module]" class="multi-module-select">
                                <option value="">-- Select Module --</option>
                                <?php foreach ($modules as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            
                            <label style="margin-left: 15px;">
                                <input type="checkbox" name="multi_mapping[${mappingId}][force_create]" value="1">
                                Always create new record (ignore lookup)
                            </label>
                        </div>
                        
                        <div style="margin-bottom: 10px;">
                            <h5>Conditional Logic</h5>
                            <label>
                                <input type="radio" name="multi_mapping[${mappingId}][condition_logic]" value="all" checked>
                                Process if ALL conditions match
                            </label>
                            <label style="margin-left: 15px;">
                                <input type="radio" name="multi_mapping[${mappingId}][condition_logic]" value="any">
                                Process if ANY condition matches
                            </label>
                            
                            <div class="conditions-container" style="margin-top: 10px;">
                                <div class="condition-row" style="margin-bottom: 5px;">
                                    <select name="multi_mapping[${mappingId}][conditions][0][field]" class="condition-field">
                                        <option value="">-- Select Field --</option>
                                        <?php foreach ($form_fields as $field_id => $field_label): ?>
                                            <option value="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($field_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <select name="multi_mapping[${mappingId}][conditions][0][operator]" class="condition-operator">
                                        <option value="is">is</option>
                                        <option value="isnot">is not</option>
                                        <option value="contains">contains</option>
                                        <option value="doesnotcontain">does not contain</option>
                                        <option value="startswith">starts with</option>
                                        <option value="endswith">ends with</option>
                                        <option value="greater_than">greater than</option>
                                        <option value="less_than">less than</option>
                                        <option value="is_empty">is empty</option>
                                        <option value="is_not_empty">is not empty</option>
                                    </select>
                                    
                                    <input type="text" name="multi_mapping[${mappingId}][conditions][0][value]" value="" class="condition-value">
                                    
                                    <button type="button" class="button remove-condition">Remove</button>
                                </div>
                            </div>
                            
                            <button type="button" class="button add-condition" data-mapping-id="${mappingId}">Add Condition</button>
                        </div>
                        
                        <div style="margin-bottom: 10px;">
                            <h5>Record Lookup (Optional)</h5>
                            <table class="form-table" style="margin-top: 0;">
                                <tr>
                                    <th style="width: 150px;"><label>Lookup Field:</label></th>
                                    <td>
                                        <input type="text" name="multi_mapping[${mappingId}][lookup_field]" value="" placeholder="Zoho field name" class="regular-text">
                                        <p class="description">The Zoho field to use for finding existing records.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Value Field:</label></th>
                                    <td>
                                        <select name="multi_mapping[${mappingId}][lookup_value]">
                                            <option value="">-- Select Form Field --</option>
                                            <?php foreach ($form['fields'] as $field): ?>
                                                <option value="<?php echo $field->id; ?>"><?php echo esc_html($field->label); ?></option>
                                            <?php endforeach; ?>
                                            <option value="entry_id">Entry ID</option>
                                            
                                            <?php if (!empty($mappings)): ?>
                                                <optgroup label="Module Record IDs">
                                                <?php foreach ($mappings as $ref_mapping_id => $ref_mapping): ?>
                                                    <option value="module_id:<?php echo esc_attr($ref_mapping['module']); ?>">
                                                        ID from <?php echo esc_html($modules[$ref_mapping['module']]); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                        </select>
                                        <p class="description">The form field or reference that contains the value to look up in Zoho.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style="margin-bottom: 10px;">
                            <h5>Field Mappings</h5>
                            <table class="widefat field-mapping-table">
                                <thead>
                                    <tr>
                                        <th>Form Field</th>
                                        <th>Zoho Field</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="field-mapping-row">
                                        <td>
                                            <select name="multi_mapping[${mappingId}][fields][gf_field][]" class="gf-field-select">
                                                <option value="">-- Select Form Field --</option>
                                                <?php foreach ($form['fields'] as $field): ?>
                                                    <option value="<?php echo $field->id; ?>"><?php echo esc_html($field->label); ?></option>
                                                <?php endforeach; ?>
                                                <option value="entry_id">Entry ID</option>
                                                
                                                <?php if (!empty($mappings)): ?>
                                                    <optgroup label="Module Record IDs">
                                                    <?php foreach ($mappings as $ref_mapping_id => $ref_mapping): ?>
                                                        <option value="module_id:<?php echo esc_attr($ref_mapping['module']); ?>">
                                                            ID from <?php echo esc_html($modules[$ref_mapping['module']]); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endif; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="multi_mapping[${mappingId}][fields][zoho_field][]" value="" placeholder="Zoho field name" class="regular-text">
                                        </td>
                                        <td>
                                            <button type="button" class="button remove-field-mapping">Remove</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <button type="button" class="button add-field-mapping" data-mapping-id="${mappingId}">Add Field Mapping</button>
                        </div>
                        
                        <div style="margin-top: 10px; text-align: right;">
                            <button type="button" class="button remove-mapping" data-mapping-id="${mappingId}">Remove Mapping</button>
                        </div>
                    </div>
                `;
                
                $('#multi-module-mappings').append(template);
                
                // Update module select to trigger any dependent UI
                $('#module-' + mappingId).trigger('change');
            });
            
            // Remove mapping
            $(document).on('click', '.remove-mapping', function() {
                var mappingId = $(this).data('mapping-id');
                if (confirm('Are you sure you want to remove this mapping?')) {
                    $('#mapping-' + mappingId).remove();
                }
            });
            
            // Add condition
            $(document).on('click', '.add-condition', function() {
                var mappingId = $(this).data('mapping-id');
                var container = $(this).siblings('.conditions-container');
                var conditionIndex = container.find('.condition-row').length;
                
                var template = `
                    <div class="condition-row" style="margin-bottom: 5px;">
                        <select name="multi_mapping[${mappingId}][conditions][${conditionIndex}][field]" class="condition-field">
                            <option value="">-- Select Field --</option>
                            <?php foreach ($form_fields as $field_id => $field_label): ?>
                                <option value="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($field_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="multi_mapping[${mappingId}][conditions][${conditionIndex}][operator]" class="condition-operator">
                            <option value="is">is</option>
                            <option value="isnot">is not</option>
                            <option value="contains">contains</option>
                            <option value="doesnotcontain">does not contain</option>
                            <option value="startswith">starts with</option>
                            <option value="endswith">ends with</option>
                            <option value="greater_than">greater than</option>
                            <option value="less_than">less than</option>
                            <option value="is_empty">is empty</option>
                            <option value="is_not_empty">is not empty</option>
                        </select>
                        
                        <input type="text" name="multi_mapping[${mappingId}][conditions][${conditionIndex}][value]" value="" class="condition-value">
                        
                        <button type="button" class="button remove-condition">Remove</button>
                    </div>
                `;
                
                container.append(template);
            });
            
            // Remove condition
            $(document).on('click', '.remove-condition', function() {
                var row = $(this).closest('.condition-row');
                row.remove();
            });
            
            // Add field mapping
            $(document).on('click', '.add-field-mapping', function() {
                var mappingId = $(this).data('mapping-id');
                var table = $(this).siblings('.field-mapping-table').find('tbody');
                
                var template = `
                    <tr class="field-mapping-row">
                        <td>
                            <select name="multi_mapping[${mappingId}][fields][gf_field][]" class="gf-field-select">
                                <option value="">-- Select Form Field --</option>
                                <?php foreach ($form['fields'] as $field): ?>
                                    <option value="<?php echo $field->id; ?>"><?php echo esc_html($field->label); ?></option>
                                <?php endforeach; ?>
                                <option value="entry_id">Entry ID</option>
                                
                                <?php if (!empty($mappings)): ?>
                                    <optgroup label="Module Record IDs">
                                    <?php foreach ($mappings as $ref_mapping_id => $ref_mapping): ?>
                                        <option value="module_id:<?php echo esc_attr($ref_mapping['module']); ?>">
                                            ID from <?php echo esc_html($modules[$ref_mapping['module']]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="multi_mapping[${mappingId}][fields][zoho_field][]" value="" placeholder="Zoho field name" class="regular-text">
                        </td>
                        <td>
                            <button type="button" class="button remove-field-mapping">Remove</button>
                        </td>
                    </tr>
                `;
                
                table.append(template);
            });
            
            // Remove field mapping
            $(document).on('click', '.remove-field-mapping', function() {
                var row = $(this).closest('.field-mapping-row');
                row.remove();
            });
            
            // Module selection change
            $(document).on('change', '.multi-module-select', function() {
                var mappingId = $(this).attr('id').replace('module-', '');
                var moduleValue = $(this).val();
                
                // Handle Desk module specific UI
                if (moduleValue.indexOf('desk_') === 0) {
                    // Show Desk settings if not already shown
                    if ($('#desk-settings-' + mappingId).length === 0) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'gf_zoho_get_desk_settings_ui',
                                mapping_id: mappingId,
                                security: '<?php echo wp_create_nonce('gf_zoho_admin'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#mapping-' + mappingId).find('.field-mapping-table').closest('div').after(response.data);
                                }
                            }
                        });
                    }
                } else {
                    // Remove Desk settings if shown
                    $('#desk-settings-' + mappingId).remove();
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Process multi-module mappings from admin form submission
     *
     * @param array $form_data POST data
     * @param int $form_id Form ID
     * @return array Processed mappings
     */
    public function process_multi_module_admin_submission($form_data, $form_id) {
        $mappings = array();
        
        if (empty($form_data['multi_mapping']) || !is_array($form_data['multi_mapping'])) {
            return $mappings;
        }
        
        foreach ($form_data['multi_mapping'] as $mapping_id => $mapping_data) {
            // Skip if no module selected
            if (empty($mapping_data['module'])) {
                continue;
            }
            
            $module = sanitize_text_field($mapping_data['module']);
            $force_create = isset($mapping_data['force_create']) ? true : false;
            
            // Process conditions
            $conditions = array();
            if (!empty($mapping_data['conditions']) && is_array($mapping_data['conditions'])) {
                foreach ($mapping_data['conditions'] as $condition) {
                    if (empty($condition['field'])) {
                        continue;
                    }
                    
                    $conditions[] = array(
                        'field' => sanitize_text_field($condition['field']),
                        'operator' => sanitize_text_field($condition['operator']),
                        'value' => sanitize_textarea_field($condition['value'])
                    );
                }
            }
            
            // Process field mappings
            $fields = array();
            if (!empty($mapping_data['fields']) && is_array($mapping_data['fields'])) {
                // Handle direct field mappings
                if (isset($mapping_data['fields']['gf_field']) && isset($mapping_data['fields']['zoho_field'])) {
                    foreach ($mapping_data['fields']['gf_field'] as $index => $gf_field) {
                        if (empty($gf_field) || empty($mapping_data['fields']['zoho_field'][$index])) {
                            continue;
                        }
                        
                        $fields[sanitize_text_field($gf_field)] = sanitize_text_field($mapping_data['fields']['zoho_field'][$index]);
                    }
                } 
                // Handle existing mappings being edited
                else {
                    foreach ($mapping_data['fields'] as $gf_field => $zoho_field) {
                        if (is_array($zoho_field)) {
                            // Skip if empty
                            if (empty($gf_field) || empty($zoho_field[0])) {
                                continue;
                            }
                            
                            $fields[sanitize_text_field($gf_field)] = sanitize_text_field($zoho_field[0]);
                        } else {
                            $fields[sanitize_text_field($gf_field)] = sanitize_text_field($zoho_field);
                        }
                    }
                }
            }
            
            // Process custom values
            $custom_values = array();
            if (!empty($mapping_data['custom_values']) && is_array($mapping_data['custom_values'])) {
                foreach ($mapping_data['custom_values'] as $zoho_field => $value) {
                    $custom_values[sanitize_text_field($zoho_field)] = sanitize_textarea_field($value);
                }
            }
            
            // Process desk settings
            $desk_settings = array();
            if (strpos($module, 'desk_') === 0 && !empty($mapping_data['desk_settings'])) {
                $desk_settings = array(
                    'department_id' => sanitize_text_field($mapping_data['desk_settings']['department_id']),
                    'status' => sanitize_text_field($mapping_data['desk_settings']['status']),
                    'priority' => sanitize_text_field($mapping_data['desk_settings']['priority'])
                );
            }
            
            // Build the mapping
            $condition_logic = isset($mapping_data['condition_logic']) ? sanitize_text_field($mapping_data['condition_logic']) : 'all';
            $lookup_field = isset($mapping_data['lookup_field']) ? sanitize_text_field($mapping_data['lookup_field']) : '';
            $lookup_value = isset($mapping_data['lookup_value']) ? sanitize_text_field($mapping_data['lookup_value']) : '';
            
            $mappings[$mapping_id] = array(
                'module' => $module,
                'force_create' => $force_create,
                'condition_logic' => $condition_logic,
                'conditions' => $conditions,
                'lookup_field' => $lookup_field,
                'lookup_value' => $lookup_value,
                'fields' => $fields,
                'custom_values' => $custom_values,
                'desk_settings' => $desk_settings
            );
        }
        
        return $mappings;
    }
}

// Helper function to get the multi-module handler instance
function gf_zoho_multi_module() {
    static $instance = null;
    
    if ($instance === null) {
        $instance = new GF_Zoho_Multi_Module();
    }
    
    return $instance;
}

/**
 * AJAX handler for getting Desk settings UI
 */
function gf_zoho_get_desk_settings_ui_callback() {
    // Check nonce
    check_ajax_referer('gf_zoho_admin', 'security');
    
    // Check permissions
    if (!current_user_can('gravityforms_edit_forms')) {
        wp_send_json_error('Permission denied');
    }
    
    // Get mapping ID
    $mapping_id = isset($_POST['mapping_id']) ? sanitize_text_field($_POST['mapping_id']) : '';
    
    if (empty($mapping_id)) {
        wp_send_json_error('Missing mapping ID');
    }
    
    // Check if Desk class exists
    if (!class_exists('GF_Zoho_Desk')) {
        wp_send_json_error('Zoho Desk integration not available');
    }
    
    // Get Desk settings UI
    $desk = new GF_Zoho_Desk();
    $html = $desk->render_desk_settings_ui_for_multi_mapping(array(), $mapping_id);
    
    wp_send_json_success($html);
}

// Add action hook outside of all functions and classes
add_action('wp_ajax_gf_zoho_get_desk_settings_ui', 'gf_zoho_get_desk_settings_ui_callback');