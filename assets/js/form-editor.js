/**
 * Gravity Forms Zoho Sync - Form Editor Script
 */

(function($) {
    $(document).ready(function() {
        console.log('GF Zoho Sync: Form editor script loaded');
        
        // Add any form editor enhancements here
        
        // Example: Add custom field settings
        if (typeof gform !== 'undefined' && typeof gform.addFilter !== 'undefined') {
            console.log('GF Zoho Sync: Adding field settings filter');
            
            gform.addFilter('gform_field_settings', function(settings, field) {
                console.log('GF Zoho Sync: Processing field settings for field #' + field.id);
                
                // Example: Add "Map to Zoho" checkbox for relevant field types
                if ($.inArray(field.type, ['text', 'email', 'phone', 'select', 'checkbox']) !== -1) {
                    settings.push({
                        title: 'Zoho Field Mapping',
                        description: 'Enable special processing for Zoho integration',
                        dependency: function() {
                            return true;
                        },
                        fields: [
                            {
                                type: 'checkbox',
                                choices: [
                                    {
                                        label: 'Enable Zoho-specific formatting',
                                        name: 'zohoFormatting',
                                        value: '1'
                                    }
                                ]
                            }
                        ]
                    });
                    console.log('GF Zoho Sync: Added Zoho mapping options to field #' + field.id);
                }
                
                return settings;
            });
        }
        
        // Example: Listen for field property changes
        $(document).on('change', '.zohoFormatting input[type="checkbox"]', function() {
            var checked = $(this).prop('checked');
            var fieldId = $('.field_selected').data('fieldId');
            
            console.log('GF Zoho Sync: Zoho formatting ' + (checked ? 'enabled' : 'disabled') + ' for field #' + fieldId);
            
            // Additional logic here as needed
        });
    });
})(jQuery);
