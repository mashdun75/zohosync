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
    
    GF_Zoho_Direct::save_form_mappings($selected_form_id, $mappings);
    
    echo '<div class="updated"><p>Mappings saved successfully.</p></div>';
    
    // Update the mappings variable with the new values
    $mappings = GF_Zoho_Direct::get_form_mappings($selected_form_id);
}

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
                            <option value="<?php echo esc_attr($value); ?>" <?php selected(isset($mappings['module']) ? $mappings['module'] : '', $value); ?>><?php echo esc_html($label); ?></option>
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
                if (class_exists('GF_Zoho_Desk') && isset($mappings['module']) && strpos($mappings['module'], 'desk_') === 0) {
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
                                    <?php if (isset($mappings['lookup_field']) && !empty($mappings['lookup_field'])): ?>
                                        <option value="<?php echo esc_attr($mappings['lookup_field']); ?>" selected><?php echo esc_html($mappings['lookup_field']); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description">The Zoho field to use for finding existing records.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="zoho_lookup_value">Value Field</label></th>
                            <td>
                                <select id="zoho_lookup_value" name="zoho_lookup_value" class="zoho-sync-input">
                                    <option value="">-- Select Form Field --</option>
                                    <?php foreach ($selected_form['fields'] as $field): ?>
                                        <option value="<?php echo $field->id; ?>" <?php selected(isset($mappings['lookup_value']) ? $mappings['lookup_value'] : '', $field->id); ?>><?php echo esc_html($field->label); ?></option>
                                    <?php endforeach; ?>
                                    <!-- Add Entry ID as a special option -->
                                    <option value="entry_id" <?php selected(isset($mappings['lookup_value']) ? $mappings['lookup_value'] : '', 'entry_id'); ?>>Entry ID (available after submission)</option>
                                </select>
                                <p class="description">The form field that contains the value to look up in Zoho.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
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
                                if (!empty($mappings['fields'])) {
                                    foreach ($mappings['fields'] as $gf_field_id => $zoho_field) {
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
                    url: gfZohoSync.ajaxurl,
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
                    url: gfZohoSync.ajaxurl,
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
        });
        </script>
    <?php endif; ?>
</div>