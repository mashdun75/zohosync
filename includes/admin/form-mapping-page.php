<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('gravityforms_edit_forms')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Check if Zoho API is connected
$connected = false;
if (class_exists('Zoho_API')) {
    $api = new Zoho_API();
    $connected = $api->get_access_token() !== false;
}

if (!$connected) {
    echo '<div class="error"><p>Not connected to Zoho. Please configure API credentials in <a href="' . admin_url('admin.php?page=gf_zoho_sync_settings') . '">Settings</a>.</p></div>';
}

// Get all forms
$forms = GFAPI::get_forms();

// Handle form selection
$selected_form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$selected_form = null;

if ($selected_form_id) {
    foreach ($forms as $form) {
        if ($form['id'] == $selected_form_id) {
            $selected_form = $form;
            break;
        }
    }
}

// Get current mappings if a form is selected
$mappings = array();
if ($selected_form) {
    $mappings = GF_Zoho_Direct::get_form_mappings($selected_form_id);
}

// Handle form submission
if (isset($_POST['gf_zoho_save_mapping']) && check_admin_referer('gf_zoho_save_mapping')) {
    // Get form data
    $module = isset($_POST['zoho_module']) ? sanitize_text_field($_POST['zoho_module']) : '';
    $lookup_field = isset($_POST['zoho_lookup_field']) ? sanitize_text_field($_POST['zoho_lookup_field']) : '';
    $lookup_value = isset($_POST['zoho_lookup_value']) ? sanitize_text_field($_POST['zoho_lookup_value']) : '';
    
    // Debug: Log the form submission data for lookup fields
    $logger = gf_zoho_logger();
    $logger->info("Form mapping submission - lookup fields", array(
        'lookup_field' => $lookup_field,
        'lookup_value' => $lookup_value
    ));
    
    error_log('Zoho API Debug: Lookup field values from form POST - Field: ' . $lookup_field . ', Value Field: ' . $lookup_value);
    
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
    
    // Debug: Log the mappings array before processing
    error_log('Zoho API Debug: Saving initial mappings with lookup_field: "' . $mappings['lookup_field'] . 
              '", lookup_value: "' . $mappings['lookup_value'] . '"');
    
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
    
    // CRITICAL: Make sure lookup fields are still set after all processing
    // Sometimes these values might be lost in one of the above processes
    if (!isset($mappings['lookup_field']) || $mappings['lookup_field'] !== $lookup_field) {
        $mappings['lookup_field'] = $lookup_field;
        error_log('Zoho API Debug: Restoring lookup_field value that was lost during processing');
    }
    
    if (!isset($mappings['lookup_value']) || $mappings['lookup_value'] !== $lookup_value) {
        $mappings['lookup_value'] = $lookup_value;
        error_log('Zoho API Debug: Restoring lookup_value that was lost during processing');
    }
    
    // Debug: Log the mappings array right before saving
    error_log('Zoho API Debug: Final mappings before save - lookup_field: "' . $mappings['lookup_field'] . 
              '", lookup_value: "' . $mappings['lookup_value'] . '"');
    
    // Save the mappings to the database
    $save_result = GF_Zoho_Direct::save_form_mappings($selected_form_id, $mappings);
    
    // Debug: Log the save result
    error_log('Zoho API Debug: Save form mappings result: ' . ($save_result ? 'SUCCESS' : 'FAILED'));
    
    echo '<div class="updated"><p>Mappings saved successfully.</p></div>';
    
    // Update the mappings variable with the new values
    $mappings = GF_Zoho_Direct::get_form_mappings($selected_form_id);
    
    // Debug: Log the mappings after retrieval to verify they were saved correctly
    error_log('Zoho API Debug: Retrieved mappings after save - lookup_field: "' . 
              (isset($mappings['lookup_field']) ? $mappings['lookup_field'] : 'NOT SET') . 
              '", lookup_value: "' . 
              (isset($mappings['lookup_value']) ? $mappings['lookup_value'] : 'NOT SET') . '"');
}

// Get current mappings (either freshly saved or existing)
$current_module = !empty($mappings['module']) ? $mappings['module'] : '';
$current_lookup_field = !empty($mappings['lookup_field']) ? $mappings['lookup_field'] : '';
$current_lookup_value = !empty($mappings['lookup_value']) ? $mappings['lookup_value'] : '';
$current_field_mappings = !empty($mappings['fields']) ? $mappings['fields'] : array();

// Debug: Verify that the lookup fields are correctly populated for use in the form
error_log('Zoho API Debug: Current values for form display - lookup_field: "' . $current_lookup_field . 
          '", lookup_value: "' . $current_lookup_value . '"');

// Get Zoho modules for dropdown
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
?>

<div class="wrap zoho-sync-container">
    <h1 class="zoho-sync-title">Zoho Sync Form Mapping</h1>
    
    <?php if (!$selected_form): ?>
        <!-- Form selection view -->
        <div class="zoho-sync-card">
            <h2>Select a Form to Configure</h2>
            <p>Choose a form to set up field mappings between Gravity Forms and Zoho:</p>
            
            <table class="zoho-sync-table">
                <thead>
                    <tr>
                        <th>Form Name</th>
                        <th>Zoho Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forms)): ?>
                        <tr>
                            <td colspan="3">No forms found. Please create a form in Gravity Forms first.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($forms as $form): 
                            $form_mappings = GF_Zoho_Direct::get_form_mappings($form['id']);
                            $has_mappings = !empty($form_mappings) && !empty($form_mappings['module']) && !empty($form_mappings['fields']);
                            
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
                                        echo '✅ Mapped to ' . esc_html($form_mappings['module']);
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
                                    <a href="?page=gf_zoho_sync_form_mapping&form_id=<?php echo $form['id']; ?>" class="zoho-sync-button"><?php echo ($has_mappings || $has_multi_mappings) ? 'Edit' : 'Configure'; ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="zoho-sync-card">
            <h2>Form Mapping Instructions</h2>
            <p>Form mapping allows you to specify how form fields should be sent to Zoho CRM or Zoho Desk. Follow these steps:</p>
            <ol>
                <li>Select a form from the list above</li>
                <li>Choose the Zoho module you want to map to (Leads, Contacts, etc.)</li>
                <li>Map each form field to the appropriate Zoho field</li>
                <li>Save your mappings</li>
            </ol>
        </div>
    <?php else: ?>
        <!-- Form mapping view -->
        <div class="zoho-sync-breadcrumb">
            <a href="?page=gf_zoho_sync_form_mapping" class="zoho-sync-button zoho-sync-button-secondary">← Back to Forms List</a>
        </div>
        
        <div class="zoho-sync-card">
            <h2>Form Mapping: <?php echo esc_html($selected_form['title']); ?></h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('gf_zoho_save_mapping'); ?>
                
                <div class="zoho-sync-mapping-section">
                    <h3>Zoho Module</h3>
                    <p>Select which Zoho module to sync with:</p>
                    
                    <select id="zoho_module" name="zoho_module" class="zoho-sync-input">
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
                
                <div class="zoho-sync-mapping-section">
                    <h3>Record Lookup (Optional)</h3>
                    <p>Configure how to find existing records in Zoho:</p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="zoho_lookup_field">Lookup Field</label></th>
                            <td>
                                <select id="zoho_lookup_field" name="zoho_lookup_field" class="zoho-sync-input">
                                    <option value="">-- Select Zoho Field --</option>
                                    <!-- Will be populated via JavaScript -->
                                    <?php if (isset($current_lookup_field) && !empty($current_lookup_field)): ?>
                                        <option value="<?php echo esc_attr($current_lookup_field); ?>" selected><?php echo esc_html($current_lookup_field); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description">The Zoho field to use for finding existing records.</p>
                                <!-- Debug: Display current value -->
                                <?php if (WP_DEBUG): ?>
                                <p class="description" style="color:#999;">Current value: <?php echo esc_html($current_lookup_field); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="zoho_lookup_value">Value Field</label></th>
                            <td>
                                <select id="zoho_lookup_value" name="zoho_lookup_value" class="zoho-sync-input">
                                    <option value="">-- Select Form Field --</option>
                                    <?php foreach ($selected_form['fields'] as $field): ?>
                                        <option value="<?php echo $field->id; ?>" <?php selected($current_lookup_value, $field->id); ?>><?php echo esc_html($field->label); ?></option>
                                    <?php endforeach; ?>
                                    <!-- Add Entry ID as a special option -->
                                    <option value="entry_id" <?php selected($current_lookup_value, 'entry_id'); ?>>Entry ID (available after submission)</option>
                                </select>
                                <p class="description">The form field that contains the value to look up in Zoho.</p>
                                <!-- Debug: Display current value -->
                                <?php if (WP_DEBUG): ?>
                                <p class="description" style="color:#999;">Current value: <?php echo esc_html($current_lookup_value); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    // Capture original values for debugging
                    var originalLookupField = $('#zoho_lookup_field').val();
                    var originalLookupValue = $('#zoho_lookup_value').val();
                    
                    console.log('Initial lookup field values:', {
                        lookupField: originalLookupField,
                        lookupValue: originalLookupValue
                    });
                    
                    // Track changes to lookup field selections
                    $('#zoho_lookup_field, #zoho_lookup_value').on('change', function() {
                        var lookupField = $('#zoho_lookup_field').val();
                        var lookupValue = $('#zoho_lookup_value').val();
                        
                        console.log('Lookup field values changed:', {
                            lookupField: lookupField,
                            lookupValue: lookupValue
                        });
                    });
                    
                    // Add form submission handler to validate lookup fields
                    $('form').on('submit', function() {
                        var lookupField = $('#zoho_lookup_field').val();
                        var lookupValue = $('#zoho_lookup_value').val();
                        
                        console.log('Form submitted with lookup field values:', {
                            lookupField: lookupField,
                            lookupValue: lookupValue
                        });
                        
                        // If one is set but not the other, show a warning
                        if ((lookupField && !lookupValue) || (!lookupField && lookupValue)) {
                            if (!confirm('You have only set one part of the lookup configuration. To use record lookup, both fields must be set. Continue anyway?')) {
                                return false;
                            }
                        }
                    });
                });
                </script>
                
                <!-- Add Lookup Field Tester UI -->
                <div id="lookup-field-tester" style="margin-top: 15px; display: none; background: #f8f8f8; padding: 10px; border: 1px solid #ddd;">
                    <h4>Test Lookup Field</h4>
                    <p>Enter a test value to check if the lookup field configuration works correctly:</p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="lookup_test_value">Test Value</label></th>
                            <td>
                                <input type="text" id="lookup_test_value" name="lookup_test_value" class="regular-text" placeholder="Enter test value...">
                                <button type="button" id="test_lookup_button" class="button">Test Lookup</button>
                                <span id="lookup_test_spinner" class="spinner" style="float: none; margin-left: 5px;"></span>
                            </td>
                        </tr>
                    </table>
                    
                    <div id="lookup_test_result" style="margin-top: 10px;"></div>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    // Toggle lookup field tester visibility based on selections
                    function toggleLookupTester() {
                        var moduleSelected = $('#zoho_module').val();
                        var lookupFieldSelected = $('#zoho_lookup_field').val();
                        
                        if (moduleSelected && lookupFieldSelected) {
                            $('#lookup-field-tester').slideDown();
                        } else {
                            $('#lookup-field-tester').slideUp();
                        }
                    }
                    
                    // Attach change handlers
                    $('#zoho_module, #zoho_lookup_field').on('change', toggleLookupTester);
                    
                    // Check initial state
                    toggleLookupTester();
                    
                    // Handle test lookup button click
                    $('#test_lookup_button').on('click', function() {
                        var module = $('#zoho_module').val();
                        var lookupField = $('#zoho_lookup_field').val();
                        var testValue = $('#lookup_test_value').val();
                        
                        // Validate inputs
                        if (!module || !lookupField || !testValue) {
                            $('#lookup_test_result').html('<div class="error"><p>Please select a module, lookup field, and enter a test value.</p></div>');
                            return;
                        }
                        
                        // Show spinner
                        $('#lookup_test_spinner').addClass('is-active');
                        $('#lookup_test_result').empty();
                        
                        // Make AJAX request
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'gf_zoho_test_lookup_field',
                                module: module,
                                lookup_field: lookupField,
                                test_value: testValue,
                                security: '<?php echo wp_create_nonce('gf_zoho_admin'); ?>'
                            },
                            success: function(response) {
                                // Hide spinner
                                $('#lookup_test_spinner').removeClass('is-active');
                                
                                if (response.success) {
                                    var data = response.data;
                                    var resultHtml = '<div class="updated"><p><strong>' + data.message + '</strong></p>';
                                    
                                    // Add record details if available
                                    if (data.data) {
                                        resultHtml += '<table class="widefat" style="margin-top: 10px;">';
                                        resultHtml += '<thead><tr><th>Field</th><th>Value</th></tr></thead>';
                                        resultHtml += '<tbody>';
                                        
                                        $.each(data.data, function(key, value) {
                                            resultHtml += '<tr><td><strong>' + key + '</strong></td><td>' + (value || '(empty)') + '</td></tr>';
                                        });
                                        
                                        resultHtml += '</tbody></table>';
                                    }
                                    
                                    resultHtml += '</div>';
                                    $('#lookup_test_result').html(resultHtml);
                                } else {
                                    var data = response.data || {};
                                    var message = data.message || 'Error testing lookup field';
                                    $('#lookup_test_result').html('<div class="error"><p>' + message + '</p></div>');
                                }
                            },
                            error: function() {
                                // Hide spinner
                                $('#lookup_test_spinner').removeClass('is-active');
                                $('#lookup_test_result').html('<div class="error"><p>Error communicating with server. Please try again.</p></div>');
                            }
                        });
                    });
                });
                </script>
                
                <div class="zoho-sync-mapping-section">
                    <h3>Field Mappings</h3>
                    <p>Map form fields to Zoho fields:</p>
                    
                    <div id="field-mappings">
                        <table class="zoho-sync-table" id="mapping-table">
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
                                            foreach ($selected_form['fields'] as $field) {
                                                if ($field->id == $gf_field_id) {
                                                    $field_label = $field->label;
                                                    break;
                                                }
                                            }
                                        }
                                        ?>
                                        <tr class="mapping-row">
                                            <td>
                                                <select name="gf_field[]" class="gf-field-select zoho-sync-input">
                                                    <option value="">-- Select Form Field --</option>
                                                    <?php foreach ($selected_form['fields'] as $field): ?>
                                                        <option value="<?php echo $field->id; ?>" <?php selected($gf_field_id, $field->id); ?>><?php echo esc_html($field->label); ?></option>
                                                    <?php endforeach; ?>
                                                    <!-- Add Entry ID as a special option -->
                                                    <option value="entry_id" <?php selected($gf_field_id, 'entry_id'); ?>>Entry ID (available after submission)</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="zoho_field[]" class="zoho-field-select zoho-sync-input">
                                                    <option value="">-- Select Zoho Field --</option>
                                                    <!-- Will be populated via JavaScript -->
                                                    <option value="<?php echo esc_attr($zoho_field); ?>" selected><?php echo esc_html($zoho_field); ?></option>
                                                </select>
                                            </td>
                                            <td>
                                                <button type="button" class="zoho-sync-button zoho-sync-button-secondary remove-mapping">Remove</button>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    // Display empty row
                                    ?>
                                    <tr class="mapping-row">
                                        <td>
                                            <select name="gf_field[]" class="gf-field-select zoho-sync-input">
                                                <option value="">-- Select Form Field --</option>
                                                <?php foreach ($selected_form['fields'] as $field): ?>
                                                    <option value="<?php echo $field->id; ?>"><?php echo esc_html($field->label); ?></option>
                                                <?php endforeach; ?>
                                                <option value="entry_id">Entry ID (available after submission)</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="zoho_field[]" class="zoho-field-select zoho-sync-input">
                                                <option value="">-- Select Zoho Field --</option>
                                                <!-- Will be populated via JavaScript -->
                                            </select>
                                        </td>
                                        <td>
                                            <button type="button" class="zoho-sync-button zoho-sync-button-secondary remove-mapping">Remove</button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                        
                        <button type="button" class="zoho-sync-button zoho-sync-button-secondary add-mapping" style="margin-top:10px;">Add Field Mapping</button>
                    </div>
                </div>
                
                <?php
                // Add custom values UI if available
                if (class_exists('GF_Zoho_Custom_Values')) {
                    $custom_values = new GF_Zoho_Custom_Values();
                    echo $custom_values->render_custom_values_ui($mappings, $selected_form);
                }
                
                // Add two-way sync UI if available
                if (class_exists('GF_Zoho_Two_Way_Sync')) {
                    $two_way_sync = gf_zoho_two_way_sync();
                    echo $two_way_sync->render_two_way_sync_ui($mappings);
                }
                ?>
                
                <div style="margin-top:20px;">
                    <input type="submit" name="gf_zoho_save_mapping" class="zoho-sync-button" value="Save Mappings">
                    <span id="test-mapping-button" class="zoho-sync-button zoho-sync-button-secondary" style="margin-left:10px;">Test Mapping</span>
                    <span id="test-result" style="margin-left:10px;"></span>
                </div>
            </form>
        </div>
        
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
                        security: gfZohoSync.nonce
                    },
                    success: function(response) {
                        $('#module-loading').hide();
                        
                        if (response.success) {
                            zohoFields = response.data;
                            window.zohoFields = zohoFields; // Make globally available
                            
                            // Update lookup field dropdown
                            var $lookupField = $('#zoho_lookup_field');
                            var currentLookupField = $lookupField.val();
                            
                            $lookupField.empty().append('<option value="">-- Select Zoho Field --</option>');
                            
                            $.each(zohoFields, function(i, field) {
                                $lookupField.append('<option value="' + field.api_name + '">' + field.label + '</option>');
                            });
                            
                            if (currentLookupField) {
                                $lookupField.val(currentLookupField);
                                // If value not found, add it back
                                if ($lookupField.val() !== currentLookupField) {
                                    $lookupField.append('<option value="' + currentLookupField + '" selected>' + currentLookupField + '</option>');
                                }
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
                                    // If value not found, add it back
                                    if ($this.val() !== currentValue) {
                                        $this.append('<option value="' + currentValue + '" selected>' + currentValue + '</option>');
                                    }
                                }
                            });
                            
                            // Call the enhanced field display function if it exists
                            if (typeof enhanceZohoFieldDisplay === 'function') {
                                enhanceZohoFieldDisplay();
                            }
                        } else {
                            $('#module-error').html('<div class="error"><p>Error loading Zoho fields: ' + (response.data || 'Unknown error') + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#module-loading').hide();
                        $('#module-error').html('<div class="error"><p>Error loading Zoho fields. Please check your network connection.</p></div>');
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
                    '<select name="gf_field[]" class="gf-field-select zoho-sync-input">' +
                    '<option value="">-- Select Form Field --</option>' +
                    '</select>' +
                    '</td>' +
                    '<td>' +
                    '<select name="zoho_field[]" class="zoho-field-select zoho-sync-input">' +
                    '<option value="">-- Select Zoho Field --</option>' +
                    '</select>' +
                    '</td>' +
                    '<td>' +
                    '<button type="button" class="zoho-sync-button zoho-sync-button-secondary remove-mapping">Remove</button>' +
                    '</td>' +
                    '</tr>'
                );
                
                // Populate form fields
                var $gfField = row.find('.gf-field-select');
                <?php foreach ($selected_form['fields'] as $field): ?>
                    $gfField.append('<option value="<?php echo $field->id; ?>"><?php echo esc_js($field->label); ?></option>');
                <?php endforeach; ?>
                
                // Add Entry ID option
                $gfField.append('<option value="entry_id">Entry ID (available after submission)</option>');
                
                // Populate Zoho fields
                var $zohoField = row.find('.zoho-field-select');
                $.each(zohoFields, function(i, field) {
                    $zohoField.append('<option value="' + field.api_name + '">' + field.label + '</option>');
                });
                
                $('#mapping-table tbody').append(row);
                
                // Trigger enhanced display if available
                if (typeof enhanceZohoFieldDisplay === 'function') {
                    enhanceZohoFieldDisplay();
                }
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
                        security: gfZohoSync.nonce
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
            
            // Enhanced Zoho field display with field type information
            function enhanceZohoFieldDisplay() {
                // Style for the field type badges
                $('<style>\
                    .zoho-field-type {\
                        display: inline-block;\
                        font-size: 10px;\
                        font-weight: normal;\
                        color: #fff;\
                        background-color: #888;\
                        border-radius: 3px;\
                        padding: 1px 5px;\
                        margin-left: 5px;\
                        vertical-align: middle;\
                    }\
                    .zoho-field-type.required {\
                        background-color: #d63638;\
                    }\
                    .zoho-field-type.lookup {\
                        background-color: #2271b1;\
                    }\
                    .zoho-field-type.text {\
                        background-color: #666;\
                    }\
                    .zoho-field-type.boolean {\
                        background-color: #46b450;\
                    }\
                    .zoho-field-select option {\
                        padding: 4px;\
                    }\
                    .zoho-field-info {\
                        display: none;\
                        position: absolute;\
                        background: #fff;\
                        border: 1px solid #ddd;\
                        padding: 10px;\
                        border-radius: 3px;\
                        box-shadow: 0 2px 4px rgba(0,0,0,0.1);\
                        z-index: 100;\
                        max-width: 300px;\
                    }\
                    .zoho-field-container {\
                        position: relative;\
                    }\
                    .zoho-field-tooltip {\
                        cursor: pointer;\
                        color: #2271b1;\
                        margin-left: 5px;\
                    }\
                </style>').appendTo('head');
                
                // Create field info modal
                $('body').append('<div id="zoho-field-info-modal" class="zoho-field-info"></div>');
                var $modal = $('#zoho-field-info-modal');
                
                // Add tooltip icons to field options and handle clicking on them
                $(document).on('mouseenter', '.zoho-field-tooltip', function(e) {
                    var $this = $(this);
                    var fieldDetails = $this.data('field-details');
                    
                    if (fieldDetails) {
                        var content = '<h4>' + fieldDetails.label + '</h4>';
                        content += '<p><strong>API Name:</strong> ' + fieldDetails.api_name + '</p>';
                        content += '<p><strong>Type:</strong> ' + fieldDetails.type + '</p>';
                        content += '<p><strong>Required:</strong> ' + (fieldDetails.required ? 'Yes' : 'No') + '</p>';
                        
                        if (fieldDetails.lookup_module) {
                            content += '<p><strong>Lookup Module:</strong> ' + fieldDetails.lookup_module + '</p>';
                        }
                        
                        $modal.html(content)
                            .css({
                                left: e.pageX + 15,
                                top: e.pageY - 25
                            })
                            .show();
                    }
                }).on('mouseleave', '.zoho-field-tooltip', function() {
                    $modal.hide();
                });
                
                // Store field info for each field
                var fieldInfo = {};
                $.each(zohoFields, function(i, field) {
                    fieldInfo[field.api_name] = field;
                });
                
                // Attach the field info to the window for later use
                window.zohoFieldInfo = fieldInfo;
                
                // Format field options with type indicators
                $('.zoho-field-select option').each(function() {
                    var $option = $(this);
                    var fieldName = $option.val();
                    
                    if (fieldName && fieldInfo[fieldName]) {
                        var field = fieldInfo[fieldName];
                        var typeClass = 'text';
                        
                        if (field.type && field.type.toLowerCase().includes('lookup')) {
                            typeClass = 'lookup';
                        } else if (field.type === 'boolean') {
                            typeClass = 'boolean';
                        }
                        
                        // Format: Field Label (TYPE)
                        $option.html(field.label + ' <span class="zoho-field-type ' + typeClass + '">' + 
                            field.type.toUpperCase() + '</span>' + 
                            (field.required ? ' <span class="zoho-field-type required">REQUIRED</span>' : ''));
                    }
                });
                
                // Add info icon and tooltip to select elements after they're populated
                $('.zoho-field-select').each(function() {
                    var $select = $(this);
                    var fieldName = $select.val();
                    
                    // Remove any existing tooltips
                    $select.next('.zoho-field-tooltip').remove();
                    
                    if (fieldName && window.zohoFieldInfo && window.zohoFieldInfo[fieldName]) {
                        var fieldDetails = window.zohoFieldInfo[fieldName];
                        var $tooltip = $('<span class="zoho-field-tooltip dashicons dashicons-info"></span>');
                        $tooltip.data('field-details', fieldDetails);
                        
                        $select.after($tooltip);
                        
                        // If this is a lookup field, add a note below the select
                        if (fieldDetails.type && fieldDetails.type.toLowerCase().includes('lookup') && !$select.next('.lookup-note').length) {
                            var lookupNote = '<p class="description lookup-note">This is a lookup field. If you provide a name instead of ID, the plugin will attempt to look up the correct ID.</p>';
                            $tooltip.after(lookupNote);
                        }
                    }
                });
            }
            
            // Run field enhancement when the page loads if zohoFields are already populated
            if (zohoFields && zohoFields.length > 0) {
                enhanceZohoFieldDisplay();
            }
            
            // Trigger enhancement when a Zoho field select changes
            $(document).on('change', '.zoho-field-select', function() {
                if (typeof enhanceZohoFieldDisplay === 'function' && zohoFields && zohoFields.length > 0) {
                    enhanceZohoFieldDisplay();
                }
            });
        });
        </script>
    <?php endif; ?>
</div>