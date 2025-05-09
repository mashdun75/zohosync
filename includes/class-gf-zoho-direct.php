<?php
/**
 * Direct GF-Zoho Integration Class
 * Handles integration between Gravity Forms and Zoho
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Direct {
    /**
     * Initialize the integration
     */
    public static function init() {
        // Check if Gravity Forms is active
        if (!class_exists('GFForms')) {
            return;
        }
        
        // Add a submenu under forms
        add_action('admin_menu', array(__CLASS__, 'add_submenu'), 20);
        
        // Add a link to the form actions
        add_filter('gform_form_actions', array(__CLASS__, 'add_form_action'), 10, 2);
        
        // Process form submission
        add_action('gform_after_submission', array(__CLASS__, 'process_submission'), 10, 2);
        
        // AJAX handler for getting Zoho fields
        add_action('wp_ajax_gf_zoho_get_fields', array(__CLASS__, 'ajax_get_zoho_fields'));
        
        // AJAX handler for testing mapping
        add_action('wp_ajax_gf_zoho_test_mapping', array(__CLASS__, 'ajax_test_mapping'));
    }
    
    /**
     * Process form submission to send data to Zoho - Enhanced Version with Multi-Module Support
     */
    public static function process_submission($entry, $form) {
        gf_zoho_logger()->info('Processing submission for form ' . $form['id']);
        
        // Check if the entry was created by Zoho (two-way sync)
        // Skip processing to avoid infinite loops
        if (isset($entry['source']) && ($entry['source'] === 'zoho' || $entry['source'] === 'zoho_desk')) {
            gf_zoho_logger()->info('Entry was created by Zoho, skipping submission processing');
            return;
        }
        
        // First check if we have multi-module mappings
        if (class_exists('GF_Zoho_Multi_Module')) {
            $multi_mappings = GF_Zoho_Multi_Module::get_multi_module_mappings($form['id']);
            
            if (!empty($multi_mappings)) {
                gf_zoho_logger()->info('Processing multi-module mappings for form ' . $form['id']);
                
                // Process multi-module mappings
                $multi_module = gf_zoho_multi_module();
                $results = $multi_module->process_multi_module_submission($entry, $form);
                
                // Log results
                if (!empty($results)) {
                    foreach ($results as $mapping_id => $result) {
                        $status = $result['success'] ? 'successful' : 'failed';
                        gf_zoho_logger()->info("Multi-module mapping {$mapping_id} processing {$status}: {$result['message']}");
                    }
                }
                
                // Return since we're using the multi-module approach
                return;
            }
        }
        
        // Fall back to single module mapping if no multi-module mappings
        $mappings = self::get_form_mappings($form['id']);
        
        if (empty($mappings) || empty($mappings['module']) || empty($mappings['fields'])) {
            gf_zoho_logger()->info('No mappings found for form ' . $form['id']);
            return;
        }
        
        gf_zoho_logger()->info('Processing submission for form ' . $form['id'] . ' with module ' . $mappings['module']);
        
        // Check if Zoho_API class exists
        if (!class_exists('Zoho_API')) {
            gf_zoho_logger()->error('Zoho_API class not found');
            return;
        }
        
        // Initialize API
        $api = new Zoho_API();
        
        // Check if connected
        if (!$api->get_access_token()) {
            gf_zoho_logger()->error('Not connected to Zoho');
            return;
        }
        
        // Determine if this is a Zoho Desk module
        $is_desk_module = strpos($mappings['module'], 'desk_') === 0;
        
        // Prepare data for Zoho
        $data = array();
        foreach ($mappings['fields'] as $gf_field => $zoho_field) {
            if (empty($gf_field) || empty($zoho_field)) {
                continue;
            }
            
            // Special handling for entry_id
            if ($gf_field === 'entry_id') {
                $value = $entry['id'];
                gf_zoho_logger()->info("Using entry ID {$value} for Zoho field {$zoho_field}");
            } else {
                // Normal form field
                $value = rgar($entry, $gf_field);
            }
            
            // Skip empty values
            if (empty($value)) {
                continue;
            }
            
            // Add to data
            $data[$zoho_field] = $value;
            
            gf_zoho_logger()->info("Mapping " . ($gf_field === 'entry_id' ? 'entry ID' : "form field $gf_field") . 
                    " to Zoho field $zoho_field with value: $value");
        }
        
        // Process custom values if enabled
        if (class_exists('GF_Zoho_Custom_Values')) {
            $custom_values = new GF_Zoho_Custom_Values();
            $data = $custom_values->process_custom_values($data, $mappings, $entry, $form);
        }
        
        if (empty($data)) {
            gf_zoho_logger()->error('No data to send to Zoho');
            return;
        }
        
        // Add form ID to the data for reference
        $data['GF_Form_ID'] = $form['id'];
        
        // Add entry ID to the data for reference (always include this regardless of mappings)
        $data['GF_Entry_ID'] = $entry['id'];
        
        // Process lookup fields if this is a CRM module
        if (!$is_desk_module && class_exists('GF_Zoho_Lookup_Handler')) {
            $lookup_handler = new GF_Zoho_Lookup_Handler();
            $data = $lookup_handler->process_lookup_fields($data, array(), $mappings['module']);
        }
        
        // Lookup existing record if specified
        $record_id = null;
        if (!empty($mappings['lookup_field']) && !empty($mappings['lookup_value'])) {
            $lookup_field = $mappings['lookup_field'];
            $lookup_value_field = $mappings['lookup_value'];
            
            // Special handling for entry_id lookup
            if ($lookup_value_field === 'entry_id') {
                $lookup_value = $entry['id'];
                gf_zoho_logger()->info("Looking up record with $lookup_field = $lookup_value (Entry ID)");
            } else {
                $lookup_value = rgar($entry, $lookup_value_field);
                gf_zoho_logger()->info("Looking up record with $lookup_field = $lookup_value (Form field)");
            }
            
            if (!empty($lookup_value)) {
                // Search for existing record
                if ($is_desk_module) {
                    // Desk module lookup
                    $desk_module = str_replace('desk_', '', $mappings['module']);
                    $url = "https://desk.{$api->api_domain}/api/v1/{$desk_module}/search?{$lookup_field}=" . urlencode($lookup_value);
                } else {
                    // CRM module lookup
                    $url = "https://{$api->api_domain}/crm/v2/{$mappings['module']}/search?criteria=({$lookup_field}:equals:{$lookup_value})";
                }
                
                $response = wp_remote_get($url, array(
                    'headers' => array(
                        'Authorization' => "Zoho-oauthtoken " . $api->get_access_token(),
                        'Content-Type' => 'application/json'
                    )
                ));
                
                if (!is_wp_error($response)) {
                    $status = wp_remote_retrieve_response_code($response);
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    
                    if ($status === 200) {
                        if ($is_desk_module) {
                            // Desk API response format
                            if (!empty($body['data'])) {
                                $record_id = $body['data'][0]['id'];
                                gf_zoho_logger()->info("Found existing Desk record with ID: $record_id");
                            }
                        } else {
                            // CRM API response format
                            if (!empty($body['data'])) {
                                $record_id = $body['data'][0]['id'];
                                gf_zoho_logger()->info("Found existing CRM record with ID: $record_id");
                            }
                        }
                        
                        if (empty($record_id)) {
                            gf_zoho_logger()->info("No existing record found with $lookup_field = $lookup_value");
                        }
                    } else {
                        gf_zoho_logger()->error("Lookup error: " . wp_remote_retrieve_body($response));
                    }
                } else {
                    gf_zoho_logger()->error("Error looking up record: " . $response->get_error_message());
                }
            }
        }
        
        // Send data to Zoho
        if ($is_desk_module) {
            // Use Zoho Desk API
            if (class_exists('GF_Zoho_Desk')) {
                $desk = new GF_Zoho_Desk();
                $desk_module = str_replace('desk_', '', $mappings['module']);
                
                // Add Desk settings if available
                if (isset($mappings['desk_settings'])) {
                    $desk_settings = $mappings['desk_settings'];
                    
                    // Add department ID
                    if (!empty($desk_settings['department_id'])) {
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
                
                $result = $desk->submit_to_desk($desk_module, $data, $record_id);
                
                if ($result['success']) {
                    gf_zoho_logger()->info("Data sent successfully to Zoho Desk");
                    
                    // Store the Zoho record ID with the entry
                    if (!$record_id && !empty($result['data']['id'])) {
                        $zoho_id = $result['data']['id'];
                        gf_zoho_logger()->info("Desk record created with ID: $zoho_id");
                        
                        gform_update_meta($entry['id'], 'zoho_desk_record_id', $zoho_id);
                        gform_update_meta($entry['id'], 'zoho_desk_module', $desk_module);
                    }
                } else {
                    gf_zoho_logger()->error("Error sending data to Zoho Desk: " . $result['message']);
                }
            } else {
                gf_zoho_logger()->error("GF_Zoho_Desk class not found, cannot send to Desk");
            }
        } else {
            // Use Zoho CRM API
            if ($record_id) {
                // Update existing record
                $url = "https://{$api->api_domain}/crm/v2/{$mappings['module']}/$record_id";
                $args = array(
                    'method' => 'PUT',
                    'headers' => array(
                        'Authorization' => "Zoho-oauthtoken " . $api->get_access_token(),
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode(array('data' => array($data)))
                );
                
                gf_zoho_logger()->info("Updating record $record_id in {$mappings['module']}");
            } else {
                // Create new record
                $url = "https://{$api->api_domain}/crm/v2/{$mappings['module']}";
                $args = array(
                    'method' => 'POST',
                    'headers' => array(
                        'Authorization' => "Zoho-oauthtoken " . $api->get_access_token(),
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode(array('data' => array($data)))
                );
                
                gf_zoho_logger()->info("Creating new record in {$mappings['module']}");
            }
            
            // Make the request
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                gf_zoho_logger()->error('Error sending data to Zoho: ' . $response->get_error_message());
                return;
            }
            
            $status = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($status === 200 || $status === 201 || $status === 202) {
                gf_zoho_logger()->info('Data sent successfully to Zoho');
                
                // Store the Zoho record ID with the entry if created
                if (!$record_id && !empty($body['data']) && !empty($body['data'][0]['details']['id'])) {
                    $zoho_id = $body['data'][0]['details']['id'];
                    gf_zoho_logger()->info("Record created with ID: $zoho_id");
                    
                    // Store mapping in entry meta
                    gform_update_meta($entry['id'], 'zoho_record_id', $zoho_id);
                    gform_update_meta($entry['id'], 'zoho_module', $mappings['module']);
                }
            } else {
                gf_zoho_logger()->error('Error response from Zoho: ' . wp_remote_retrieve_body($response));
            }
        }
    }
    
    /**
     * Get form mappings
     */
    public static function get_form_mappings($form_id) {
        $mappings = get_option("gf_zoho_mappings_$form_id", array());
        return $mappings;
    }
    
    /**
     * Save form mappings
     */
    public static function save_form_mappings($form_id, $mappings) {
        return update_option("gf_zoho_mappings_$form_id", $mappings);
    }
    
    /**
     * AJAX handler for getting Zoho fields
     */
    public static function ajax_get_zoho_fields() {
        // Check nonce
        check_ajax_referer('gf_zoho_admin', 'security');
        
        // Check permissions
        if (!current_user_can('gravityforms_edit_forms')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get module
        $module = isset($_POST['module']) ? sanitize_text_field($_POST['module']) : '';
        
        if (empty($module)) {
            wp_send_json_error('Module is required');
        }
        
        // Debug info
        gf_zoho_logger()->info('Getting fields for module: ' . $module);
        
        // Check if Zoho_API class exists
        if (!class_exists('Zoho_API')) {
            gf_zoho_logger()->error('Zoho_API class not found');
            wp_send_json_error('Zoho API class not found');
        }
        
        // Initialize API
        $api = new Zoho_API();
        
        // Check if connected
        $access_token = $api->get_access_token();
        if (!$access_token) {
            gf_zoho_logger()->error('Not connected to Zoho - no access token');
            wp_send_json_error('Not connected to Zoho');
        }
        
        // Determine if this is a Desk module
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        if ($is_desk_module && class_exists('GF_Zoho_Desk')) {
            // Use Desk API
            $desk = new GF_Zoho_Desk();
            $desk_module = str_replace('desk_', '', $module);
            $fields = $desk->get_desk_fields($desk_module);
            
            if (empty($fields)) {
                wp_send_json_error('No fields found for Desk module: ' . $desk_module);
            }
            
            wp_send_json_success($fields);
        } else {
            // Use CRM API
            // Log the token and API domain
            gf_zoho_logger()->debug('Using access token: ' . substr($access_token, 0, 10) . '...');
            gf_zoho_logger()->debug('Using API domain: ' . $api->api_domain);
            
            // Get fields for module
            $url = "https://{$api->api_domain}/crm/v2/settings/fields?module=" . urlencode($module);
            gf_zoho_logger()->debug('Requesting URL: ' . $url);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => "Zoho-oauthtoken " . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30 // Increase timeout
            ));
            
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                gf_zoho_logger()->error('WP Error fetching fields: ' . $error_message);
                wp_send_json_error('Error fetching fields: ' . $error_message);
            }
            
            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            gf_zoho_logger()->debug('Response status: ' . $status);
            gf_zoho_logger()->debug('Response body: ' . substr($body, 0, 200) . '...');
            
            $body_data = json_decode($body, true);
            
            if ($status !== 200 || empty($body_data['fields'])) {
                $error_msg = isset($body_data['message']) ? $body_data['message'] : 'Unknown error';
                gf_zoho_logger()->error('Error response from Zoho: ' . $error_msg);
                wp_send_json_error('Error fetching fields: ' . $error_msg);
            }
            
            $fields = array();
            foreach ($body_data['fields'] as $field) {
                // Skip fields that aren't needed (adjust these conditions as needed)
                if (isset($field['visible']) && !$field['visible']) {
                    continue;
                }
                
                // Include system fields that we might want to map to
                $is_system_field = !empty($field['system_mandatory']) || !empty($field['system_generated']);
                
                // Only exclude system fields if they're not visible or useful
                if ($is_system_field && empty($field['visible']) && !empty($field['read_only'])) {
                    continue;
                }
                
                $fields[] = array(
                    'api_name' => $field['api_name'],
                    'label' => $field['field_label'],
                    'type' => isset($field['data_type']) ? $field['data_type'] : 'text',
                    'required' => !empty($field['required']) || !empty($field['system_mandatory'])
                );
            }
            
            gf_zoho_logger()->info('Found ' . count($fields) . ' fields for module: ' . $module);
            
            wp_send_json_success($fields);
        }
    }
    
    /**
     * AJAX handler for testing mapping
     */
    public static function ajax_test_mapping() {
        // Check nonce
        check_ajax_referer('gf_zoho_admin', 'security');
        
        // Check permissions
        if (!current_user_can('gravityforms_edit_forms')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get data
        $module = isset($_POST['module']) ? sanitize_text_field($_POST['module']) : '';
        
        if (empty($module)) {
            wp_send_json_error('Module is required');
        }
        
        // Check if Zoho_API class exists
        if (!class_exists('Zoho_API')) {
            wp_send_json_error('Zoho API class not found');
        }
        
        // Initialize API
        $api = new Zoho_API();
        
        // Check if connected
        if (!$api->get_access_token()) {
            wp_send_json_error('Not connected to Zoho');
        }
        
        // Determine if this is a Desk module
        $is_desk_module = strpos($module, 'desk_') === 0;
        
        if ($is_desk_module && class_exists('GF_Zoho_Desk')) {
            // Test Desk module connection
            $desk = new GF_Zoho_Desk();
            $desk_module = str_replace('desk_', '', $module);
            
            // Get departments to test connection
            $departments = $desk->get_departments();
            
            if (empty($departments)) {
                wp_send_json_error('Could not connect to Zoho Desk API');
            }
            
            wp_send_json_success('Zoho Desk connection test successful');
        } else {
            // Test CRM module connection
            $url = "https://{$api->api_domain}/crm/v2/{$module}?fields=id&per_page=1";
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => "Zoho-oauthtoken " . $api->get_access_token(),
                    'Content-Type' => 'application/json'
                )
            ));
            
            if (is_wp_error($response)) {
                wp_send_json_error('Error testing mapping: ' . $response->get_error_message());
            }
            
            $status = wp_remote_retrieve_response_code($response);
            
            if ($status !== 200) {
                wp_send_json_error('Error testing mapping: ' . wp_remote_retrieve_body($response));
            }
            
            wp_send_json_success('Mapping test successful');
        }
    }
    
    /**
     * Add a submenu item under the Forms menu
     */
    public static function add_submenu() {
        add_submenu_page(
            'gf_edit_forms',
            'Zoho Sync',
            'Zoho Sync',
            'gravityforms_edit_forms',
            'gf_zoho_sync',
            array(__CLASS__, 'render_page')
        );
    }
    
    /**
     * Add a link to the form actions dropdown
     */
    public static function add_form_action($actions, $form_id) {
        $actions['zoho'] = array(
            'label'      => 'Zoho Sync',
            'url'        => admin_url("admin.php?page=gf_zoho_sync&id={$form_id}"),
            'capability' => 'gravityforms_edit_forms'
        );
        
        return $actions;
    }
    
    /**
     * Render the Zoho Sync page
     */
    public static function render_page() {
        // Get the form ID
        $form_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        
        // Check if we have a form ID
        if ($form_id) {
            self::render_form_page($form_id);
        } else {
            self::render_forms_list();
        }
    }
    
    /**
     * Render the forms list
     */
    private static function render_forms_list() {
        // Get all forms
        $forms = GFAPI::get_forms();
        
        // Output the page
        ?>
        <div class="wrap">
            <h1>Zoho Sync - Forms</h1>
            
            <p>Select a form to configure Zoho Sync settings:</p>
            
            <table class="widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th>Form Name</th>
                        <th>Zoho Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $form): 
                        $mappings = self::get_form_mappings($form['id']);
                        $has_mappings = !empty($mappings) && !empty($mappings['module']) && !empty($mappings['fields']);
                        
                        // Check for multi-module mappings
                        $multi_mappings = array();
                        if (class_exists('GF_Zoho_Multi_Module')) {
                            $multi_mappings = GF_Zoho_Multi_Module::get_multi_module_mappings($form['id']);
                        }
                        $has_multi_mappings = !empty($multi_mappings);
                    ?>
                    <tr>
                        <td><?php echo esc_html($form['title']); ?></td>
                        <td>
                            <?php 
                            if ($has_mappings) {
                                echo '✅ Mapped to ' . esc_html($mappings['module']);
                            }
                            
                            if ($has_multi_mappings) {
                                echo $has_mappings ? '<br>' : '';
                                echo '✅ Multi-module mappings (' . count($multi_mappings) . ')';
                            }
                            
                            if (!$has_mappings && !$has_multi_mappings) {
                                echo 'Not configured';
                            }
                            ?>
                        </td>
                        <td>
                            <a href="?page=gf_zoho_sync&id=<?php echo $form['id']; ?>" class="button-secondary"><?php echo ($has_mappings || $has_multi_mappings) ? 'Edit' : 'Configure'; ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
                <h3>Plugin Information</h3>
                <p>This plugin allows you to sync form submissions with Zoho CRM and Zoho Desk.</p>
                <p>To get started:</p>
                <ol>
                    <li>Configure your Zoho API credentials in <a href="<?php echo admin_url('options-general.php?page=gf-zoho-sync'); ?>">Settings > Zoho Sync</a></li>
                    <li>Select a form from the list above to configure field mappings</li>
                    <li>Map form fields to Zoho CRM or Desk fields</li>
                </ol>
                
                <h4>Advanced Features:</h4>
                <ul>
                    <li><strong>Multi-Module Mappings:</strong> Create or update records in multiple Zoho modules from a single form submission</li>
                    <li><strong>Conditional Logic:</strong> Control when mappings are processed based on form field values</li>
                    <li><strong>Lookup Fields:</strong> Automatically resolve lookup fields by finding the appropriate record ID</li>
                    <li><strong>Two-Way Sync:</strong> Changes in Zoho can be synced back to Gravity Forms entries</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the form settings page
     */
    private static function render_form_page($form_id) {
        // Get the form
        $form = GFAPI::get_form($form_id);
        if (!$form) {
            ?>
            <div class="wrap">
                <h1>Zoho Sync</h1>
                <div class="error"><p>Form not found.</p></div>
            </div>
            <?php
            return;
        }
        
        // Check if Zoho_API class exists
        if (!class_exists('Zoho_API')) {
            ?>
            <div class="wrap">
                <h1>Zoho Sync - <?php echo esc_html($form['title']); ?></h1>
                <div class="error"><p>Zoho API class not found. Please check the plugin installation.</p></div>
            </div>
            <?php
            return;
        }
        
        // Initialize API
        $api = new Zoho_API();
        
        // Check if connected
        $connected = $api->get_access_token() !== false;
        
        if (!$connected) {
            ?>
            <div class="wrap">
                <h1>Zoho Sync - <?php echo esc_html($form['title']); ?></h1>
                
                <div class="error"><p>Not connected to Zoho. Please configure API credentials in <a href="<?php echo admin_url('options-general.php?page=gf-zoho-sync'); ?>">Settings > Zoho Sync</a>.</p></div>
                
                <a href="?page=gf_zoho_sync" class="button-secondary" style="margin-top:20px;">← Back to Forms List</a>
            </div>
            <?php
            return;
        }
        
        // Handle form submission
        if (isset($_POST['gf_zoho_save_mapping']) && check_admin_referer('gf_zoho_save_mapping')) {
            // Get form data
            $module = isset($_POST['zoho_module']) ? sanitize_text_field($_POST['zoho_module']) : '';
            $lookup_field = isset($_POST['zoho_lookup_field']) ? sanitize_text_field($_POST['zoho_lookup_field']) : '';
            $lookup_value = isset($_POST['zoho_lookup_value']) ? sanitize_text_field($_POST['zoho_lookup_value']) : '';
            
            // Prepare field mappings
            $field_mappings = array();
            
            // Process field mappings from the form
            if (isset($_POST['gf_field']) && is_array($_POST['gf_field']) && 
                isset($_POST['zoho_field']) && is_array($_POST['zoho_field'])) {
                
                foreach ($_POST['gf_field'] as $index => $gf_field) {
                    if (empty($gf_field) || empty($_POST['zoho_field'][$index])) {
                        continue;
                    }
                    
                    $zoho_field = sanitize_text_field($_POST['zoho_field'][$index]);
                    $field_mappings[$gf_field] = $zoho_field;
                }
            }
            
            // Save mappings
            $mappings = array(
                'module' => $module,
                'lookup_field' => $lookup_field,
                'lookup_value' => $lookup_value,
                'fields' => $field_mappings
            );
            
            // Process custom values
            if (class_exists('GF_Zoho_Custom_Values')) {
                $custom_values = new GF_Zoho_Custom_Values();
                $mappings = $custom_values->process_custom_values_submission($mappings, $_POST);
            }
            
            // Process Desk settings
            if (strpos($module, 'desk_') === 0 && class_exists('GF_Zoho_Desk')) {
                $desk = new GF_Zoho_Desk();
                $mappings = $desk->process_desk_settings_submission($mappings, $_POST);
            }
            
            // Process two-way sync settings
            if (class_exists('GF_Zoho_Two_Way_Sync')) {
                $two_way_sync = gf_zoho_two_way_sync();
                $mappings = $two_way_sync->process_two_way_sync_submission($mappings, $_POST);
            }
            
            self::save_form_mappings($form_id, $mappings);
            
            echo '<div class="updated"><p>Mappings saved successfully.</p></div>';
        }
        
        // Get current mappings
        $mappings = self::get_form_mappings($form_id);
        $current_module = !empty($mappings['module']) ? $mappings['module'] : '';
        $current_lookup_field = !empty($mappings['lookup_field']) ? $mappings['lookup_field'] : '';
        $current_lookup_value = !empty($mappings['lookup_value']) ? $mappings['lookup_value'] : '';
        $current_field_mappings = !empty($mappings['fields']) ? $mappings['fields'] : array();
        
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
        
        // Output the page
        ?>
        <div class="wrap">
            <h1>Zoho Sync - <?php echo esc_html($form['title']); ?></h1>
            
            <a href="?page=gf_zoho_sync" class="button-secondary" style="margin-bottom:20px;">← Back to Forms List</a>
            
            <form method="post" action="">
                <?php wp_nonce_field('gf_zoho_save_mapping'); ?>
                
                <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
                    <h3>Zoho Module</h3>
                    <p>Select which Zoho module to sync with:</p>
                    
                    <select id="zoho_module" name="zoho_module">
                        <option value="">-- Select Module --</option>
                        <?php foreach ($modules as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($current_module, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div id="module-loading" style="display:none; margin-top:10px;">
                        <span class="spinner is-active" style="float:none; margin:0;"></span>
                        Loading Zoho fields...
                    </div>
                    <div id="module-error"></div>
                </div>
                
                <?php
                // Add Desk settings if needed
                if (class_exists('GF_Zoho_Desk') && strpos($current_module, 'desk_') === 0) {
                    $desk = new GF_Zoho_Desk();
                    echo $desk->render_desk_settings_ui($mappings);
                }
                ?>
                
                <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
                    <h3>Record Lookup (Optional)</h3>
                    <p>Configure how to find existing records in Zoho:</p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="zoho_lookup_field">Lookup Field</label></th>
                            <td>
                                <select id="zoho_lookup_field" name="zoho_lookup_field">
                                    <option value="">-- Select Zoho Field --</option>
                                    <!-- Will be populated via JavaScript -->
                                </select>
                                <p class="description">The Zoho field to use for finding existing records.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="zoho_lookup_value">Value Field</label></th>
                            <td>
                                <select id="zoho_lookup_value" name="zoho_lookup_value">
                                    <option value="">-- Select Form Field --</option>
                                    <?php foreach ($form['fields'] as $field): ?>
                                        <option value="<?php echo $field->id; ?>" <?php selected($current_lookup_value, $field->id); ?>><?php echo esc_html($field->label); ?></option>
                                    <?php endforeach; ?>
                                    <!-- Add Entry ID as a special option -->
                                    <option value="entry_id" <?php selected($current_lookup_value, 'entry_id'); ?>>Entry ID (available after submission)</option>
                                </select>
                                <p class="description">The form field that contains the value to look up in Zoho.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
                    <h3>Field Mappings</h3>
                    <p>Map form fields to Zoho fields:</p>
                    
                    <div id="field-mappings">
                        <table class="widefat" id="mapping-table">
                            <thead>
                                <tr>
                                    <th>Form Field</th>
                                    <th>Zoho Field</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Display existing mappings
                                if (!empty($current_field_mappings)) {
                                    foreach ($current_field_mappings as $gf_field_id => $zoho_field) {
                                        // Get field label
                                        $field_label = 'Unknown Field';
                                        if ($gf_field_id === 'entry_id') {
                                            $field_label = 'Entry ID';
                                        } else {
                                            foreach ($form['fields'] as $field) {
                                                if ($field->id == $gf_field_id) {
                                                    $field_label = $field->label;
                                                    break;
                                                }
                                            }
                                        }
                                        ?>
                                        <tr class="mapping-row">
                                            <td>
                                                <select name="gf_field[]" class="gf-field-select">
                                                    <option value="">-- Select Form Field --</option>
                                                    <?php foreach ($form['fields'] as $field): ?>
                                                        <option value="<?php echo $field->id; ?>" <?php selected($gf_field_id, $field->id); ?>><?php echo esc_html($field->label); ?></option>
                                                    <?php endforeach; ?>
                                                    <!-- Add Entry ID as a special option -->
                                                    <option value="entry_id" <?php selected($gf_field_id, 'entry_id'); ?>>Entry ID (available after submission)</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="zoho_field[]" class="zoho-field-select">
                                                    <option value="">-- Select Zoho Field --</option>
                                                    <!-- Will be populated via JavaScript -->
                                                    <option value="<?php echo esc_attr($zoho_field); ?>" selected><?php echo esc_html($zoho_field); ?></option>
                                                </select>
                                            </td>
                                            <td>
                                                <button type="button" class="button remove-mapping">Remove</button>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    // Display empty row
                                    ?>
                                    <tr class="mapping-row">
                                        <td>
                                            <select name="gf_field[]" class="gf-field-select">
                                                <option value="">-- Select Form Field --</option>
                                                <?php foreach ($form['fields'] as $field): ?>
                                                    <option value="<?php echo $field->id; ?>"><?php echo esc_html($field->label); ?></option>
                                                <?php endforeach; ?>
                                                <option value="entry_id">Entry ID (available after submission)</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="zoho_field[]" class="zoho-field-select">
                                                <option value="">-- Select Zoho Field --</option>
                                                <!-- Will be populated via JavaScript -->
                                            </select>
                                        </td>
                                        <td>
                                            <button type="button" class="button remove-mapping">Remove</button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                        
                        <button type="button" class="button add-mapping" style="margin-top:10px;">Add Mapping</button>
                    </div>
                </div>
                
                <?php
                // Add custom values UI if available
                if (class_exists('GF_Zoho_Custom_Values')) {
                    $custom_values = new GF_Zoho_Custom_Values();
                    echo $custom_values->render_custom_values_ui($mappings, $form);
                }
                
                // Add two-way sync UI if available
                if (class_exists('GF_Zoho_Two_Way_Sync')) {
                    $two_way_sync = gf_zoho_two_way_sync();
                    echo $two_way_sync->render_two_way_sync_ui($mappings);
                }
                ?>
                
                <div style="margin-top:20px;">
                    <input type="submit" name="gf_zoho_save_mapping" class="button-primary" value="Save Mappings">
                    <span id="test-mapping-button" class="button" style="margin-left:10px;">Test Mapping</span>
                    <span id="test-result" style="margin-left:10px;"></span>
                </div>
            </form>
            
            <!-- Add multi-module mappings UI -->
            <?php if (class_exists('GF_Zoho_Multi_Module')): ?>
            <form method="post" action="">
                <?php wp_nonce_field('gf_zoho_save_multi_mapping'); ?>
                
                <h2 style="margin-top: 30px;">Advanced Multi-Module Mappings</h2>
                
                <?php
                // Handle multi-module form submission
if (isset($_POST['gf_zoho_save_multi_mapping']) && check_admin_referer('gf_zoho_save_multi_mapping')) {
    $multi_module = gf_zoho_multi_module();
    $multi_mappings = $multi_module->process_multi_module_admin_submission($_POST, $form_id);
    
    // Save the mappings
    GF_Zoho_Multi_Module::save_multi_module_mappings($form_id, $multi_mappings);
    
    echo '<div class="updated"><p>Multi-module mappings saved successfully.</p></div>';
}
                
                // Display multi-module UI
                $multi_module = gf_zoho_multi_module();
                echo $multi_module->render_multi_module_ui($form_id, $form);
                ?>
                
                <div style="margin-top:20px;">
                    <input type="submit" name="gf_zoho_save_multi_mapping" class="button-primary" value="Save Multi-Module Mappings">
                </div>
            </form>
            <?php endif; ?>
            
            <script>
            jQuery(document).ready(function($) {
                var zohoFields = [];
                
                // Load Zoho fields on module change
                $('#zoho_module').on('change', function() {
                    var module = $(this).val();
                    if (!module) {
                        return;
                    }
                    
                    $('#module-loading').show();
                    
                    // Clear previous error messages
                    $('#module-error').empty();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'gf_zoho_get_fields',
                            module: module,
                            security: '<?php echo wp_create_nonce('gf_zoho_admin'); ?>'
                        },
                        success: function(response) {
                            $('#module-loading').hide();
                            
                            if (response.success) {
                                zohoFields = response.data;
                                
                                // Update lookup field dropdown
                                var $lookupField = $('#zoho_lookup_field');
                                var currentLookupField = $lookupField.val();
                                
                                $lookupField.empty().append('<option value="">-- Select Zoho Field --</option>');
                                
                                $.each(zohoFields, function(i, field) {
                                    $lookupField.append('<option value="' + field.api_name + '">' + field.label + '</option>');
                                });
                                
                                if (currentLookupField) {
                                    $lookupField.val(currentLookupField);
                                }
                                
                                // Update Zoho field dropdowns
                                $('.zoho-field-select').each(function() {
                                    var $this = $(this);
                                    var currentValue = $this.val();
                                    
                                    $this.empty().append('<option value="">-- Select Zoho Field --</option>');
                                    
                                    $.each(zohoFields, function(i, field) {
                                        $this.append('<option value="' + field.api_name + '">' + field.label + '</option>');
                                    });
                                    
                                    if (currentValue) {
                                        $this.val(currentValue);
                                    }
                                });
                            } else {
                                // Show error message in a more visible way
                                $('<div class="error" style="margin: 10px 0;"><p>Error loading Zoho fields: ' + (response.data || 'Unknown error') + '</p><p>Please check the settings page to verify your Zoho connection.</p></div>').appendTo('#module-error');
                                console.error('Error loading Zoho fields:', response);
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#module-loading').hide();
                            // Show detailed error message
                            $('<div class="error" style="margin: 10px 0;"><p>Error loading Zoho fields: ' + status + ' - ' + error + '</p><p>Please check your network connection and verify your Zoho API credentials.</p></div>').appendTo('#module-error');
                            console.error('AJAX error:', xhr, status, error);
                        }
                    });
                });
                
                // Trigger module change if already selected
                if ($('#zoho_module').val()) {
                    $('#zoho_module').trigger('change');
                }
                
                // Add mapping row
                $('.add-mapping').on('click', function() {
                    var row = $(
                        '<tr class="mapping-row">' +
                        '<td>' +
                        '<select name="gf_field[]" class="gf-field-select">' +
                        '<option value="">-- Select Form Field --</option>' +
                        '</select>' +
                        '</td>' +
                        '<td>' +
                        '<select name="zoho_field[]" class="zoho-field-select">' +
                        '<option value="">-- Select Zoho Field --</option>' +
                        '</select>' +
                        '</td>' +
                        '<td>' +
                        '<button type="button" class="button remove-mapping">Remove</button>' +
                        '</td>' +
                        '</tr>'
                    );
                    
                    // Populate form fields
                    var $gfField = row.find('.gf-field-select');
                    <?php foreach ($form['fields'] as $field): ?>
                        $gfField.append('<option value="<?php echo $field->id; ?>"><?php echo esc_html($field->label); ?></option>');
                    <?php endforeach; ?>
                    
                    // Add Entry ID option
                    $gfField.append('<option value="entry_id">Entry ID (available after submission)</option>');
                    
                    // Populate Zoho fields
                    var $zohoField = row.find('.zoho-field-select');
                    $.each(zohoFields, function(i, field) {
                        $zohoField.append('<option value="' + field.api_name + '">' + field.label + '</option>');
                    });
                    
                    $('#mapping-table tbody').append(row);
                });
                
                // Remove mapping row
                $(document).on('click', '.remove-mapping', function() {
                    var $row = $(this).closest('tr');
                    
                    // Don't remove if it's the only row
                    if ($('.mapping-row').length > 1) {
                        $row.remove();
                    } else {
                        // Just clear the values
                        $row.find('select').val('');
                    }
                });
                
                // Test mapping
                $('#test-mapping-button').on('click', function() {
                    var module = $('#zoho_module').val();
                    if (!module) {
                        alert('Please select a Zoho module first.');
                        return;
                    }
                    
                    var $testResult = $('#test-result');
                    $testResult.html('<span class="spinner is-active" style="float:none; margin:0;"></span> Testing...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'gf_zoho_test_mapping',
                            module: module,
                            security: '<?php echo wp_create_nonce('gf_zoho_admin'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $testResult.html('<span style="color:green;">✓ ' + response.data + '</span>');
                            } else {
                                $testResult.html('<span style="color:red;">✗ ' + (response.data || 'Unknown error') + '</span>');
                            }
                        },
                        error: function() {
                            $testResult.html('<span style="color:red;">✗ Error testing mapping. Please try again.</span>');
                        }
                    });
                });
            });
            </script>
            
            <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
                <h3>Form Fields</h3>
                <p>The following fields are available in this form:</p>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Label</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($form['fields'] as $field): ?>
                        <tr>
                            <td><?php echo esc_html($field->id); ?></td>
                            <td><?php echo esc_html($field->label); ?></td>
                            <td><?php echo esc_html($field->type); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
                <h3>Zoho Connection Status</h3>
                <?php
                if (class_exists('Zoho_API')) {
                    $api = new Zoho_API();
                    $tokens = $api->get_tokens();
                    $access_token = $api->get_access_token();
                    $client_id = get_option('gf_zoho_client_id', '');
                    $client_secret = get_option('gf_zoho_client_secret', '');
                    
                    // Debug information
                    echo '<div style="background:#f8f8f8; padding:10px; margin-bottom:10px; border:1px solid #ddd;">';
                    echo '<strong>Debug Information:</strong><br>';
                    echo 'Client ID set: ' . (!empty($client_id) ? 'Yes' : 'No') . '<br>';
                    echo 'Client Secret set: ' . (!empty($client_secret) ? 'Yes' : 'No') . '<br>';
                    echo 'Tokens in database: ' . (!empty($tokens) ? 'Yes' : 'No') . '<br>';
                    echo 'Access token available: ' . (!empty($access_token) ? 'Yes' : 'No') . '<br>';
                    echo 'API Domain: ' . $api->api_domain . '<br>';
                    echo '</div>';
                    
                    $connected = !empty($client_id) && !empty($client_secret) && !empty($access_token);
                    
                    if ($connected) {
                        echo '<p class="connection-status success">✅ Connected to Zoho</p>';
                        
                        // Add a test connection button
                        ?>
                        <form method="post" action="<?php echo admin_url('options-general.php?page=gf-zoho-sync'); ?>">
                            <?php wp_nonce_field('gf_zoho_settings'); ?>
                            <input type="submit" name="gf_zoho_test_connection" class="button" value="Test Connection">
                        </form>
                        <?php
                    } else {
                        echo '<p class="connection-status error">❌ Not connected to Zoho</p>';
                        echo '<p>Please configure Zoho API credentials in the <a href="' . esc_url(admin_url('options-general.php?page=gf-zoho-sync')) . '">plugin settings</a>.</p>';
                    }
                } else {
                    echo '<p class="connection-status error">❌ Zoho API class not found</p>';
                }
                ?>
            </div>
        </div>
        <?php
    }
}

// Initialize the integration
GF_Zoho_Direct::init();