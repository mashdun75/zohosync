<?php
/**
 * GF Zoho Custom Values Handler
 * Allows custom values and calculations for Zoho field mappings
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Custom_Values {
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = gf_zoho_logger();
    }
    
    /**
     * Process custom values in mapping
     *
     * @param array $data The data to be submitted to Zoho
     * @param array $mappings The field mappings configuration
     * @param array $entry The Gravity Forms entry
     * @param array $form The form object
     * @return array Processed data with custom values
     */
    public function process_custom_values($data, $mappings, $entry, $form) {
        $this->logger->info("Processing custom values for form ID: {$form['id']}");
        
        // Check if we have custom values in the mappings
        if (empty($mappings['custom_values'])) {
            return $data;
        }
        
        foreach ($mappings['custom_values'] as $zoho_field => $custom_value) {
            $processed_value = $this->parse_custom_value($custom_value, $entry, $form);
            
            if ($processed_value !== null) {
                $data[$zoho_field] = $processed_value;
                $this->logger->info("Added custom value for {$zoho_field}: {$processed_value}");
            }
        }
        
        return $data;
    }
    
    /**
     * Parse and evaluate a custom value string
     * 
     * @param string $custom_value The custom value with merge tags
     * @param array $entry The Gravity Forms entry
     * @param array $form The form object
     * @return string|null The processed value
     */
    private function parse_custom_value($custom_value, $entry, $form) {
        // Parse merge tags
        $parsed_value = GFCommon::replace_variables($custom_value, $form, $entry, false, false, false, 'text');
        
        // Check if the value is a calculation enclosed in [calculate] tags
        if (preg_match('/\[calculate\](.*?)\[\/calculate\]/is', $parsed_value, $matches)) {
            $calculation = $matches[1];
            
            // Log the calculation for debugging
            $this->logger->debug("Processing calculation: {$calculation}");
            
            // Attempt to evaluate the calculation
            try {
                // Replace entry fields with their values
                preg_match_all('/\{([^\}]+)\}/', $calculation, $field_matches);
                
                if (!empty($field_matches[1])) {
                    foreach ($field_matches[1] as $field_key) {
                        // Handle special entry properties
                        if ($field_key === 'entry_id') {
                            $calculation = str_replace('{' . $field_key . '}', $entry['id'], $calculation);
                        } elseif ($field_key === 'form_id') {
                            $calculation = str_replace('{' . $field_key . '}', $form['id'], $calculation);
                        } elseif ($field_key === 'date_created') {
                            $calculation = str_replace('{' . $field_key . '}', "'" . $entry['date_created'] . "'", $calculation);
                        } elseif (isset($entry[$field_key])) {
                            $field_value = is_numeric($entry[$field_key]) ? $entry[$field_key] : "'" . $entry[$field_key] . "'";
                            $calculation = str_replace('{' . $field_key . '}', $field_value, $calculation);
                        }
                    }
                }
                
                // Make sure the calculation is clean and safe
                $calculation = preg_replace('/[^0-9\+\-\*\/\(\)\.\s\'\"]/', '', $calculation);
                
                // Log the calculation after processing
                $this->logger->debug("Processed calculation: {$calculation}");
                
                // Evaluate the calculation
                $result = @eval("return {$calculation};");
                
                if ($result !== false) {
                    return $result;
                } else {
                    $this->logger->error("Failed to evaluate calculation: {$calculation}");
                    return null;
                }
            } catch (Exception $e) {
                $this->logger->error("Error in calculation: " . $e->getMessage());
                return null;
            }
        }
        
        return $parsed_value;
    }
    
    /**
     * Add UI for custom values in the admin
     *
     * @param array $mappings Current mappings
     * @param array $form The form object
     * @return string HTML for custom values UI
     */
    public function render_custom_values_ui($mappings, $form) {
        $custom_values = isset($mappings['custom_values']) ? $mappings['custom_values'] : array();
        
        ob_start();
        ?>
        <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
            <h3>Custom Values <span class="description">(Optional)</span></h3>
            <p>Set custom values for Zoho fields. You can use form merge tags and calculations.</p>
            
            <table class="widefat" id="custom-values-table">
                <thead>
                    <tr>
                        <th>Zoho Field</th>
                        <th>Custom Value</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($custom_values)): ?>
                        <?php foreach ($custom_values as $zoho_field => $value): ?>
                            <tr class="custom-value-row">
                                <td>
                                    <input type="text" name="custom_zoho_field[]" value="<?php echo esc_attr($zoho_field); ?>" class="regular-text" placeholder="Zoho Field API Name">
                                </td>
                                <td>
                                    <input type="text" name="custom_value[]" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="Value or {merge_tag}">
                                    <p class="description">Use form merge tags like {1}, {2}, etc.</p>
                                    <p class="description">For calculations, use [calculate]{1}+{2}[/calculate]</p>
                                </td>
                                <td>
                                    <button type="button" class="button remove-custom-value">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="custom-value-row">
                            <td>
                                <input type="text" name="custom_zoho_field[]" value="" class="regular-text" placeholder="Zoho Field API Name">
                            </td>
                            <td>
                                <input type="text" name="custom_value[]" value="" class="regular-text" placeholder="Value or {merge_tag}">
                                <p class="description">Use form merge tags like {1}, {2}, etc.</p>
                                <p class="description">For calculations, use [calculate]{1}+{2}[/calculate]</p>
                            </td>
                            <td>
                                <button type="button" class="button remove-custom-value">Remove</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <button type="button" class="button add-custom-value" style="margin-top:10px;">Add Custom Value</button>
            
            <p class="description" style="margin-top:10px;">
                <strong>Examples:</strong><br>
                - Static value: <code>New Lead from Website</code><br>
                - Merge tag: <code>{1} {2}</code> (combines fields 1 and 2)<br>
                - Date: <code>{date_created}</code><br>
                - Calculation: <code>[calculate]{3}*0.01[/calculate]</code> (calculates 1% of field 3)<br>
                - Current date: <code>[calculate]date('Y-m-d')[/calculate]</code>
            </p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Add custom value row
            $('.add-custom-value').on('click', function() {
                var row = $(
                    '<tr class="custom-value-row">' +
                    '<td>' +
                    '<input type="text" name="custom_zoho_field[]" value="" class="regular-text" placeholder="Zoho Field API Name">' +
                    '</td>' +
                    '<td>' +
                    '<input type="text" name="custom_value[]" value="" class="regular-text" placeholder="Value or {merge_tag}">' +
                    '<p class="description">Use form merge tags like {1}, {2}, etc.</p>' +
                    '<p class="description">For calculations, use [calculate]{1}+{2}[/calculate]</p>' +
                    '</td>' +
                    '<td>' +
                    '<button type="button" class="button remove-custom-value">Remove</button>' +
                    '</td>' +
                    '</tr>'
                );
                
                $('#custom-values-table tbody').append(row);
            });
            
            // Remove custom value row
            $(document).on('click', '.remove-custom-value', function() {
                var $row = $(this).closest('tr');
                
                // Don't remove if it's the only row
                if ($('.custom-value-row').length > 1) {
                    $row.remove();
                } else {
                    // Just clear the values
                    $row.find('input').val('');
                }
            });
            
            // Add merge tag button
            if (typeof gform !== 'undefined' && typeof gform.addFilter !== 'undefined') {
                gform.addFilter('gform_merge_tags', 'append_custom_merge_tags');
                
                function append_custom_merge_tags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {
                    mergeTags.push({
                        tag: '{entry_id}',
                        label: 'Entry ID'
                    });
                    
                    mergeTags.push({
                        tag: '{form_id}',
                        label: 'Form ID'
                    });
                    
                    return mergeTags;
                }
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Process custom value form submission
     *
     * @param array $mappings Current mappings
     * @param array $form_data Form POST data
     * @return array Updated mappings
     */
    public function process_custom_values_submission($mappings, $form_data) {
        $custom_values = array();
        
        if (isset($form_data['custom_zoho_field']) && isset($form_data['custom_value'])) {
            foreach ($form_data['custom_zoho_field'] as $i => $zoho_field) {
                if (empty($zoho_field) || !isset($form_data['custom_value'][$i])) {
                    continue;
                }
                
                $custom_values[sanitize_text_field($zoho_field)] = sanitize_textarea_field($form_data['custom_value'][$i]);
            }
        }
        
        $mappings['custom_values'] = $custom_values;
        return $mappings;
    }
}