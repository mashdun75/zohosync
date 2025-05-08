<?php
/**
 * Gravity Forms Zoho Sync Add-On
 */

if ( ! class_exists('GFAddOn') ) {
    require_once GF_PLUGIN_DIR . '/includes/addon/class-gf-addon.php';
}

class GFZohoSyncAddOn extends GFAddOn {
    protected $_version = GF_ZOHO_SYNC_VERSION;
    protected $_min_gravityforms_version = '2.5';
    protected $_slug = 'gf-zoho-sync';
    protected $_path = 'gf-zoho-sync/gf-zoho-sync.php';
    protected $_full_path = __FILE__;
    protected $_title = 'Gravity Forms Zoho Sync';
    protected $_short_title = 'Zoho Sync';
    protected $_enable_rg_autoupgrade = true;
    protected $_supports_logging = true; // Enable logging support

    private static $_instance = null;

    /**
     * Get instance of this class
     */
    public static function get_instance() {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
 /**
     * Define form settings fields
     */
    public function form_settings_fields($form) {
        $this->log_debug(__METHOD__ . "(): Building form settings fields for form #{$form['id']}");
        return [
            [
                'title'  => esc_html__('Zoho Sync Settings', 'gf-zoho-sync'),
                'fields' => [
                    [
                        'type'    => 'feed_list',
                        'tooltip' => esc_html__('Configure feeds to sync form entries with Zoho.', 'gf-zoho-sync'),
                        'label'   => esc_html__('Zoho Feeds', 'gf-zoho-sync')
                    ],
                ]
            ]
        ];
    }

    /**
     * Get menu icon for add-on
     */
    public function get_menu_icon() {
        return 'dashicons-admin-generic';
    }

    /**
     * Define feed list columns
     */
    public function feed_list_columns() {
        return [
            'feed_name' => esc_html__('Name', 'gf-zoho-sync'),
            'module'    => esc_html__('Zoho Module', 'gf-zoho-sync'),
        ];
    }

    /**
     * Get module column value for feed list
     */
    public function get_column_value_module($feed) {
        return rgar($feed['meta'], 'module');
    }
    // END NEW METHODS ↑

    // Don't add them after all existing methods - make sure they're inside the class but not inside any other method
}

    /**
     * Initialize the add-on
     */
    public function init() {
        parent::init();
        
        $this->log_debug(__METHOD__ . '(): Initializing Zoho Sync Add-On');
        
        // AJAX handlers
        add_action('wp_ajax_gf_zoho_get_fields', [ $this, 'ajax_get_zoho_fields' ]);
        add_action('wp_ajax_gf_zoho_test_connection', [ $this, 'ajax_test_connection' ]);
        
        // Process form submission
        add_action('gform_after_submission', [ $this, 'process_feed' ], 10, 2);
        
        // Enqueue admin assets
        add_action('gform_editor_js', [ $this, 'enqueue_form_editor_scripts' ]);
    }

    /**
     * Register admin scripts
     */
    public function scripts() {
        $this->log_debug(__METHOD__ . '(): Registering admin scripts');
        
        $scripts = [
            [
                'handle'  => 'gf-zoho-admin',
                'src'     => $this->get_base_url() . '/assets/js/admin.js',
                'version' => $this->_version,
                'deps'    => ['jquery'],
                'enqueue' => [
                    [
                        'admin_page' => ['form_settings', 'plugin_settings']
                    ]
                ]
            ]
        ];

        $styles = [
            [
                'handle'  => 'gf-zoho-admin',
                'src'     => $this->get_base_url() . '/assets/css/admin.css',
                'version' => $this->_version,
                'enqueue' => [
                    [
                        'admin_page' => ['form_settings', 'plugin_settings']
                    ]
                ]
            ]
        ];

        return array_merge(parent::scripts(), $scripts);
    }

    /**
     * Enqueue admin assets for feed settings
     */
    public function enqueue_admin_assets() {
        $this->log_debug(__METHOD__ . '(): Enqueuing admin assets');
        
        wp_enqueue_script('gf-zoho-admin', $this->get_base_url() . '/assets/js/admin.js', ['jquery'], $this->_version, true);
        wp_localize_script('gf-zoho-admin', 'gfZohoSync', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'security' => wp_create_nonce('gf-zoho-nonce'),
            'gfFields' => $this->get_form_fields()
        ]);
        wp_enqueue_style('gf-zoho-admin', $this->get_base_url() . '/assets/css/admin.css', [], $this->_version);
    }

    /**
     * Enqueue form editor scripts
     */
    public function enqueue_form_editor_scripts() {
        $this->log_debug(__METHOD__ . '(): Enqueuing form editor scripts');
        
        wp_enqueue_script('gf-zoho-form-editor', $this->get_base_url() . '/assets/js/form-editor.js', ['jquery', 'gform_form_editor'], $this->_version, true);
    }

    /**
     * Get form fields for mapping
     */
    public function get_form_fields() {
        $form_id = $this->get_current_form_id();
        $fields = [];
        
        $this->log_debug(__METHOD__ . "(): Getting fields for form #{$form_id}");
        
        if ($form_id) {
            $form = GFAPI::get_form($form_id);
            if ($form) {
                foreach ($form['fields'] as $field) {
                    if (isset($field->id) && !empty($field->label)) {
                        $fields[] = [
                            'id' => $field->id,
                            'label' => $field->label,
                            'type' => $field->type
                        ];
                    }
                }
                $this->log_debug(__METHOD__ . "(): Found " . count($fields) . " fields");
            } else {
                $this->log_error(__METHOD__ . "(): Form #{$form_id} not found");
            }
        }
        
        return $fields;
    }

    /**
     * Feed settings fields
     */
    public function feed_settings_fields() {
        $this->log_debug(__METHOD__ . '(): Building feed settings fields');
        
        return [
            [
                'title'       => 'Zoho Sync Feed Settings',
                'description' => 'Configure synchronization between this form and Zoho.',
                'fields'      => [
                    [
                        'name'     => 'feed_name',
                        'label'    => 'Feed Name',
                        'type'     => 'text',
                        'class'    => 'medium',
                        'required' => true,
                        'tooltip'  => 'Enter a name to identify this sync feed.'
                    ],
                    [
                        'name'     => 'api_type',
                        'label'    => 'Zoho API',
                        'type'     => 'select',
                        'choices'  => [
                            ['label' => 'CRM', 'value' => 'CRM'],
                            ['label' => 'Desk', 'value' => 'Desk']
                        ],
                        'default_value' => 'CRM',
                        'required' => true,
                        'onchange' => 'jQuery("#gaddon-setting-row-module").toggle();'
                    ],
                    [
                        'name'     => 'module',
                        'label'    => 'Zoho Module',
                        'type'     => 'select',
                        'choices'  => [
                            ['label' => 'Cases', 'value' => 'Cases'],
                            ['label' => 'Contacts', 'value' => 'Contacts'],
                            ['label' => 'Products', 'value' => 'Products'],
                            ['label' => 'Accounts', 'value' => 'Accounts'],
                            ['label' => 'Leads', 'value' => 'Leads'],
                            ['label' => 'Deals', 'value' => 'Deals'],
                        ],
                        'required' => true,
                        'onchange' => 'gfZohoSync.loadZohoFields(this.value, jQuery("#api_type").val());',
                    ],
                    [
                        'name'     => 'lookup_field',
                        'label'    => 'Zoho Lookup Field',
                        'type'     => 'select',
                        'choices'  => [
                            ['label' => 'Select a field', 'value' => '']
                        ],
                        'tooltip'  => 'Select the Zoho field used to look up existing records.',
                        'required' => true
                    ],
                    [
                        'name'     => 'lookup_value_field',
                        'label'    => 'Form Field for Lookup Value',
                        'type'     => 'select',
                        'choices'  => $this->get_form_field_choices(),
                        'tooltip'  => 'Select the form field that contains the value to look up records in Zoho.',
                        'required' => true
                    ],
                    [
                        'name'     => 'create_if_not_exists',
                        'label'    => 'Create New Record If Not Found',
                        'type'     => 'checkbox',
                        'choices'  => [
                            ['label' => 'Create new record if lookup fails', 'value' => 1]
                        ],
                        'default_value' => 1
                    ],
                    [
                        'name'     => 'conditional_logic',
                        'label'    => 'Conditional Logic',
                        'type'     => 'feed_condition',
                        'tooltip'  => 'When conditions are enabled, form submissions will only be sent to Zoho when the conditions are met.'
                    ],
                    [
                        'name'        => 'field_mappings',
                        'label'       => 'Field Mappings',
                        'type'        => 'dynamic_field_map',
                        'limit'       => 100,
                        'key_field'   => [
                            'choices' => $this->get_form_field_choices(),
                            'label'   => 'Gravity Form Field',
                            'name'    => 'gf_field'
                        ],
                        'value_field' => [
                            'choices' => $this->get_zoho_field_choices(),
                            'label'   => 'Zoho Field',
                            'name'    => 'zoho_field'
                        ]
                    ],
                    [
                        'name'        => 'bi_directional',
                        'label'       => 'Sync Direction',
                        'type'        => 'radio',
                        'choices'     => [
                            ['label' => 'One-way (Form to Zoho only)', 'value' => '0'],
                            ['label' => 'Two-way (Sync changes from Zoho back to form)', 'value' => '1']
                        ],
                        'default_value' => '1'
                    ]
                ],
            ],
        ];
    }

    /**
     * Get form field choices for dropdowns
     */
    private function get_form_field_choices() {
        $form_id = $this->get_current_form_id();
        $choices = [];
        
        $this->log_debug(__METHOD__ . "(): Getting field choices for form #{$form_id}");
        
        if ($form_id) {
            $form = GFAPI::get_form($form_id);
            if ($form) {
                foreach ($form['fields'] as $field) {
                    if (isset($field->id) && !empty($field->label)) {
                        $choices[] = [
                            'value' => $field->id,
                            'label' => $field->label
                        ];
                    }
                }
                $this->log_debug(__METHOD__ . "(): Found " . count($choices) . " field choices");
            }
        }
        
        return $choices;
    }

    /**
     * Get Zoho field choices - this will be populated via AJAX
     */
    private function get_zoho_field_choices() {
        return [
            ['value' => '', 'label' => 'Select a Zoho field']
        ];
    }

    /**
     * AJAX handler for getting Zoho fields
     */
    public function ajax_get_zoho_fields() {
        check_ajax_referer('gf-zoho-nonce', 'security');
        
        $this->log_debug(__METHOD__ . '(): AJAX request for Zoho fields');
        
        $module = isset($_POST['module']) ? sanitize_text_field($_POST['module']) : '';
        $api_type = isset($_POST['api_type']) ? sanitize_text_field($_POST['api_type']) : 'CRM';
        
        if (!$module) {
            $this->log_error(__METHOD__ . '(): No module specified in request');
            wp_send_json_error('Module required');
        }
        
        $this->log_debug(__METHOD__ . "(): Getting fields for {$api_type} module {$module}");
        
        $api = new Zoho_API();
        $endpoint = $api_type === 'Desk' 
            ? "settings/fields?module={$module}" 
            : "settings/fields?module={$module}";
            
        $response = $api->request('GET', $endpoint, null, $api_type);
        $fields = [];
        
        if ($api_type === 'CRM' && !empty($response['fields'])) {
            foreach ($response['fields'] as $field) {
                $fields[] = [
                    'api_name' => $field['api_name'],
                    'label' => $field['field_label'],
                    'type' => $field['data_type']
                ];
            }
            $this->log_debug(__METHOD__ . "(): Retrieved " . count($fields) . " fields for CRM module {$module}");
        } elseif ($api_type === 'Desk' && !empty($response['data'])) {
            foreach ($response['data'] as $field) {
                $fields[] = [
                    'api_name' => $field['apiName'],
                    'label' => $field['label'],
                    'type' => $field['type']
                ];
            }
            $this->log_debug(__METHOD__ . "(): Retrieved " . count($fields) . " fields for Desk module {$module}");
        } else {
            $this->log_error(__METHOD__ . "(): Failed to retrieve fields: " . json_encode($response));
        }
        
        wp_send_json_success($fields);
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('gf-zoho-nonce', 'security');
        
        $this->log_debug(__METHOD__ . '(): Testing Zoho API connection');
        
        $api = new Zoho_API();
        $token = $api->get_access_token();
        
        if (!$token) {
            $this->log_error(__METHOD__ . '(): No valid access token found');
            wp_send_json_error('No valid access token found. Please reconnect to Zoho.');
            return;
        }
        
        // Try a simple API request to verify connection
        $response = $api->request('GET', 'settings/modules');
        
        if (empty($response['modules'])) {
            $this->log_error(__METHOD__ . '(): Connection test failed: ' . json_encode($response));
            wp_send_json_error('Connection failed. Please check your Zoho credentials.');
            return;
        }
        
        $this->log_debug(__METHOD__ . '(): Connection test successful');
        wp_send_json_success('Connection successful!');
    }

    /**
     * Process feed when a form is submitted
     */
    public function process_feed($entry, $form) {
        $this->log_debug(__METHOD__ . "(): Processing form #{$form['id']}, entry #{$entry['id']}");
        
        $feeds = $this->get_active_feeds($form['id']);
        $this->log_debug(__METHOD__ . '(): Found ' . count($feeds) . ' active feeds');
        
        foreach ($feeds as $feed) {
            // Check conditional logic
            if (!$this->is_feed_condition_met($feed, $form, $entry)) {
                $this->log_debug(__METHOD__ . "(): Feed #{$feed['id']} condition not met, skipping");
                continue;
            }
            
            $this->log_debug(__METHOD__ . "(): Processing feed #{$feed['id']}");
            $this->process_zoho_sync($feed, $entry, $form);
        }
    }

    /**
     * Process Zoho synchronization for a single feed
     */
    private function process_zoho_sync($feed, $entry, $form) {
        $this->log_debug(__METHOD__ . "(): Starting Zoho sync for feed #{$feed['id']}");
        
        $settings = $feed['meta'];
        $api_type = rgar($settings, 'api_type', 'CRM');
        $module = rgar($settings, 'module');
        $lookup_field = rgar($settings, 'lookup_field');
        $lookup_value_field = rgar($settings, 'lookup_value_field');
        $create_if_not_exists = (bool) rgar($settings, 'create_if_not_exists', true);
        $field_mappings = rgar($settings, 'field_mappings', []);
        $bi_directional = (bool) rgar($settings, 'bi_directional', true);
        
        if (empty($module) || empty($lookup_field) || empty($lookup_value_field)) {
            $this->log_error(__METHOD__ . "(): Feed #{$feed['id']} missing required settings");
            return;
        }
        
        $lookup_value = rgar($entry, $lookup_value_field);
        if (empty($lookup_value)) {
            $this->log_error(__METHOD__ . "(): No lookup value found for field #{$lookup_value_field}");
            return;
        }
        
        $this->log_debug(__METHOD__ . "(): Using lookup field '{$lookup_field}' with value '{$lookup_value}'");
        
        $api = new Zoho_API();
        
        // First attempt to find the record in Zoho
        $this->log_debug(__METHOD__ . "(): Searching for {$module} record with {$lookup_field} = {$lookup_value}");
        $records = $api->search_records($module, $lookup_field, $lookup_value, $api_type);
        $zoho_id = null;
        $action = 'create';
        
        if (!empty($records) && is_array($records) && count($records) > 0) {
            $zoho_id = $api_type === 'CRM' ? $records[0]['id'] : $records[0]['ticketId'];
            $action = 'update';
            $this->log_debug(__METHOD__ . "(): Found existing {$module} record with ID: {$zoho_id}");
        } elseif (!$create_if_not_exists) {
            $this->log_error(__METHOD__ . "(): No matching Zoho record found and create_if_not_exists is false");
            return;
        } else {
            $this->log_debug(__METHOD__ . "(): No existing record found, will create new {$module} record");
        }
        
        // Prepare data payload
        $payload = $this->prepare_feed_data($entry, $feed, $form);
        $this->log_debug(__METHOD__ . "(): Prepared payload: " . json_encode($payload));
        
        // Add form and entry ID references for two-way sync
        $payload['GF_Form_ID'] = $form['id'];
        $payload['GF_Entry_ID'] = $entry['id'];
        
        // Add or update record in Zoho
        if ($action === 'create') {
            $this->log_debug(__METHOD__ . "(): Creating new {$module} record in Zoho");
            $response = $api->request('POST', $module, $payload, $api_type);
            
            if (!empty($response['data']) && is_array($response['data'])) {
                $zoho_id = $api_type === 'CRM' ? $response['data'][0]['details']['id'] : $response['data'][0]['ticketId'];
                
                // Save the mapping
                GFZohoMapping::save_mapping($form['id'], $entry['id'], $module, $zoho_id, $api_type);
                
                $this->log_debug(__METHOD__ . "(): Created new {$module} record in Zoho with ID: {$zoho_id}");
            } else {
                $this->log_error(__METHOD__ . "(): Failed to create Zoho record: " . json_encode($response));
            }
        } else {
            // For update, endpoint format differs between CRM and Desk
            $endpoint = $api_type === 'CRM' 
                ? "{$module}/{$zoho_id}" 
                : "tickets/{$zoho_id}";
                
            $this->log_debug(__METHOD__ . "(): Updating existing {$module} record in Zoho with ID: {$zoho_id}");
            $response = $api->request('PUT', $endpoint, $payload, $api_type);
            
            if (!empty($response['data']) && is_array($response['data'])) {
                // Update the mapping in case the module changed
                GFZohoMapping::save_mapping($form['id'], $entry['id'], $module, $zoho_id, $api_type);
                
                $this->log_debug(__METHOD__ . "(): Updated existing {$module} record in Zoho with ID: {$zoho_id}");
            } else {
                $this->log_error(__METHOD__ . "(): Failed to update Zoho record: " . json_encode($response));
            }
        }
        
        // Process file uploads if present
        $this->process_file_uploads($entry, $feed, $form, $zoho_id, $api_type, $module);
        
        // Set up webhook for two-way sync if needed
        if ($bi_directional && $zoho_id) {
            $this->log_debug(__METHOD__ . "(): Setting up webhook for two-way sync");
            $this->setup_zoho_webhook($module, $api_type);
        }
    }

    /**
     * Prepare data payload for Zoho API
     */
    private function prepare_feed_data($entry, $feed, $form) {
        $this->log_debug(__METHOD__ . "(): Preparing data payload for entry #{$entry['id']}");
        
        $data = [];
        $settings = $feed['meta'];
        $field_mappings = rgar($settings, 'field_mappings', []);
        
        // Format data according to field mappings
        foreach ($field_mappings as $mapping) {
            $gf_field = rgar($mapping, 'gf_field');
            $zoho_field = rgar($mapping, 'zoho_field');
            
            if (empty($gf_field) || empty($zoho_field)) {
                continue;
            }
            
            $value = rgar($entry, $gf_field);
            
            // Handle different field types if needed
            $field = GFFormsModel::get_field($form, $gf_field);
            if ($field) {
                // Special handling for file uploads
                if ($field->type === 'fileupload') {
                    // For file uploads, we'll handle them separately
                    $this->log_debug(__METHOD__ . "(): Skipping file upload field #{$gf_field} for separate processing");
                    continue;
                }
                
                // Format dates correctly for Zoho
                if ($field->type === 'date' && !empty($value)) {
                    $old_value = $value;
                    $value = date('Y-m-d', strtotime($value));
                    $this->log_debug(__METHOD__ . "(): Converted date field #{$gf_field} from '{$old_value}' to '{$value}'");
                }
            }
            
            $this->log_debug(__METHOD__ . "(): Mapping GF field #{$gf_field} to Zoho field '{$zoho_field}' with value: " . $value);
            $data[$zoho_field] = $value;
        }
        
        return $data;
    }
    
    /**
     * Process file uploads from the form
     */
    private function process_file_uploads($entry, $feed, $form, $zoho_id, $api_type, $module) {
        $this->log_debug(__METHOD__ . "(): Processing file uploads for entry #{$entry['id']}");
        
        $settings = $feed['meta'];
        $field_mappings = rgar($settings, 'field_mappings', []);
        
        foreach ($field_mappings as $mapping) {
            $gf_field = rgar($mapping, 'gf_field');
            
            // Check if this is a file upload field
            $field = GFFormsModel::get_field($form, $gf_field);
            if (!$field || $field->type !== 'fileupload') {
                continue;
            }
            
            $file_url = rgar($entry, $gf_field);
            if (empty($file_url)) {
                continue;
            }
            
            $this->log_debug(__METHOD__ . "(): Processing file upload from field #{$gf_field}: {$file_url}");
            
            // Download file to temp directory
            $tmp_dir = get_temp_dir();
            $tmp_file = $tmp_dir . basename($file_url);
            
            $downloaded = copy($file_url, $tmp_file);
            if (!$downloaded) {
                $this->log_error(__METHOD__ . "(): Failed to download file from {$file_url}");
                continue;
            }
            
            // Upload to Zoho
            $api = new Zoho_API();
            $result = $api->upload_attachment($module, $zoho_id, $tmp_file, $api_type);
            
            // Clean up temp file
            @unlink($tmp_file);
            
            if (!empty($result['data'])) {
                $this->log_debug(__METHOD__ . "(): Successfully uploaded file attachment to Zoho");
            } else {
                $this->log_error(__METHOD__ . "(): Failed to upload file attachment to Zoho: " . json_encode($result));
            }
        }
    }

    /**
     * Setup Zoho webhook for two-way sync if not already set up
     */
    private function setup_zoho_webhook($module, $api_type = 'CRM') {
        $webhook_id = get_option('gf_zoho_webhook_id');
        if ($webhook_id) {
            $this->log_debug(__METHOD__ . "(): Webhook already exists with ID: {$webhook_id}");
            return;
        }
        
        $api = new Zoho_API();
        
        // Get the webhook endpoint URL
        $webhook_url = rest_url('gf-zoho-sync/v1/zoho-update');
        $this->log_debug(__METHOD__ . "(): Setting up webhook with endpoint: {$webhook_url}");
        
        // Different webhook setup for CRM vs Desk
        if ($api_type === 'CRM') {
            $webhook_token = wp_generate_password(32, false);
            
            $config = [
                'channelId' => 'gf_zoho_sync_' . wp_generate_password(6, false),
                'channelExpiry' => date('Y-m-d\TH:i:s', strtotime('+1 year')),
                'notifyUrl' => $webhook_url,
                'events' => ['$create', '$update'],
                'channelStatus' => 'ENABLED',
                'token' => $webhook_token
            ];
            
            $this->log_debug(__METHOD__ . "(): Registering CRM webhook with config: " . json_encode($config));
            $response = $api->register_webhook($config);
            
            if (!empty($response['data']) && is_array($response['data'])) {
                $webhook_id = $response['data'][0]['details']['id'];
                update_option('gf_zoho_webhook_id', $webhook_id);
                update_option('gf_zoho_webhook_token', $config['token']);
                $this->log_debug(__METHOD__ . "(): Created Zoho webhook with ID: {$webhook_id}");
            } else {
                $this->log_error(__METHOD__ . "(): Failed to create Zoho webhook: " . json_encode($response));
            }
        } else {
            // Desk webhook setup is different
            $this->log_debug(__METHOD__ . "(): Desk webhook setup not implemented yet");
        }
    }
}
