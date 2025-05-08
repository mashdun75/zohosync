/**
 * Gravity Forms Zoho Sync - Admin Script
 */

jQuery(function($) {
    console.log('GF Zoho Sync: Admin script initialized');
    
    var zohoFields = [];
    var mappingWrap = $('#zoho-field-mappings');

    // Refresh all Zoho field dropdowns with current field data
    function refreshZohoFieldsDropdown() {
        console.log('GF Zoho Sync: Refreshing Zoho field dropdowns');
        
        mappingWrap.find('.zoho-field-select').each(function() {
            var $sel = $(this);
            var current = $sel.val();
            
            console.log('GF Zoho Sync: Rebuilding dropdown with ' + zohoFields.length + ' fields, current value: ' + current);
            
            $sel.empty();
            $sel.append($('<option>').val('').text('-- Select Zoho Field --'));
            
            zohoFields.forEach(function(f) {
                $sel.append($('<option>').val(f.api_name).text(f.label));
            });
            
            if (current) {
                $sel.val(current);
                console.log('GF Zoho Sync: Restored selection: ' + current);
            }
        });
    }

    // Initialize API object
    window.gfZohoSync = {
        ajaxUrl: window.gfZohoSync ? window.gfZohoSync.ajaxUrl : '',
        security: window.gfZohoSync ? window.gfZohoSync.security : '',
        
        loadZohoFields: function(module, apiType) {
            console.log('GF Zoho Sync: Loading Zoho fields for module: ' + module + ', API type: ' + apiType);
            
            $('#zoho-loading-fields').remove();
            $('#gaddon-setting-row-field_mappings').prepend(
                '<div id="zoho-loading-fields" style="color:#2271b1;margin-bottom:10px;"><span class="spinner is-active" style="float:none;margin-left:0;margin-right:5px;"></span> Loading Zoho fields...</div>'
            );
            
            $.post(this.ajaxUrl, {
                action: 'gf_zoho_get_fields',
                module: module,
                api_type: apiType || 'CRM',
                security: this.security
            }, function(res) {
                $('#zoho-loading-fields').remove();
                
                if (res.success) {
                    console.log('GF Zoho Sync: Successfully loaded ' + res.data.length + ' Zoho fields');
                    zohoFields = res.data;
                    
                    // Update lookup field dropdown as well
                    var lookupSelect = $('#gaddon-setting-row-lookup_field select');
                    var currentValue = lookupSelect.val();
                    
                    lookupSelect.empty();
                    lookupSelect.append($('<option>').val('').text('-- Select Lookup Field --'));
                    
                    zohoFields.forEach(function(field) {
                        lookupSelect.append(
                            $('<option>')
                                .val(field.api_name)
                                .text(field.label)
                        );
                    });
                    
                    if (currentValue) {
                        lookupSelect.val(currentValue);
                    }
                    
                    refreshZohoFieldsDropdown();
                } else {
                    console.error('GF Zoho Sync: Error fetching Zoho fields: ', res.data);
                    alert('Error fetching Zoho fields: ' + res.data);
                }
            }).fail(function(xhr, status, error) {
                console.error('GF Zoho Sync: AJAX error fetching Zoho fields: ' + error);
                $('#zoho-loading-fields').remove();
                alert('Error fetching Zoho fields: ' + error);
            });
        },
        
        testConnection: function() {
            console.log('GF Zoho Sync: Testing Zoho connection');
            
            $('#test-connection-result').remove();
            $('#test-connection').after('<span id="test-connection-result"> <span class="spinner is-active" style="float:none;margin:0;"></span> Testing...</span>');
            
            $.post(this.ajaxUrl, {
                action: 'gf_zoho_test_connection',
                security: this.security
            }, function(res) {
                if (res.success) {
                    console.log('GF Zoho Sync: Connection test successful');
                    $('#test-connection-result')
                        .html(' ✅ ' + res.data)
                        .css('color', 'green');
                } else {
                    console.error('GF Zoho Sync: Connection test failed: ' + res.data);
                    $('#test-connection-result')
                        .html(' ❌ ' + res.data)
                        .css('color', 'red');
                }
            }).fail(function(xhr, status, error) {
                console.error('GF Zoho Sync: AJAX error testing connection: ' + error);
                $('#test-connection-result')
                    .html(' ❌ Error: ' + error)
                    .css('color', 'red');
            });
        }
    };

    // Add field mapping row
    if ($('#add-mapping').length) {
        console.log('GF Zoho Sync: Setting up Add Mapping button');
        
        $('#add-mapping').on('click', function() {
            console.log('GF Zoho Sync: Adding new mapping row');
            
            var row = $(
                '<div class="mapping-row">' +
                '<select class="gf-field-select"></select> → ' +
                '<select class="zoho-field-select"></select> ' +
                '<button type="button" class="remove-mapping button-link">Remove</button>' +
                '</div>'
            );
            
            // Populate GF fields
            console.log('GF Zoho Sync: Populating form fields dropdown');
            if (window.gfFields && window.gfFields.length) {
                row.find('.gf-field-select').append($('<option>').val('').text('-- Select Form Field --'));
                
                window.gfFields.forEach(function(f) {
                    row.find('.gf-field-select').append($('<option>').val(f.id).text(f.label));
                });
                
                console.log('GF Zoho Sync: Added ' + window.gfFields.length + ' form fields to dropdown');
            } else {
                console.warn('GF Zoho Sync: No form fields available');
            }
            
            // Populate Zoho fields if available
            refreshZohoFieldsDropdown();
            
            mappingWrap.append(row);
        });
    }

    // Remove mapping row
    if (mappingWrap.length) {
        console.log('GF Zoho Sync: Setting up mapping row removal');
        
        mappingWrap.on('click', '.remove-mapping', function() {
            console.log('GF Zoho Sync: Removing mapping row');
            $(this).closest('.mapping-row').remove();
        });
    }

    // Before saving feed, serialize mappings to textarea
    if ($('#gform_submit_button').length) {
        console.log('GF Zoho Sync: Setting up feed form submission handler');
        
        $('#gform_submit_button').closest('form').on('submit', function() {
            console.log('GF Zoho Sync: Processing feed form submission');
            
            var mappings = [];
            mappingWrap.find('.mapping-row').each(function() {
                var gf = $(this).find('.gf-field-select').val();
                var zoho = $(this).find('.zoho-field-select').val();
                
                if (gf && zoho) {
                    mappings.push({gf_id: gf, zoho: zoho});
                    console.log('GF Zoho Sync: Adding mapping - GF field ' + gf + ' to Zoho field ' + zoho);
                } else {
                    console.warn('GF Zoho Sync: Skipping incomplete mapping row');
                }
            });
            
            console.log('GF Zoho Sync: Saving ' + mappings.length + ' field mappings');
            $('textarea[name="field_mappings"]').val(JSON.stringify(mappings));
        });
    }
    
    // Initialize API type and module handling
    if ($('#gaddon-setting-row-api_type select').length && $('#gaddon-setting-row-module select').length) {
        console.log('GF Zoho Sync: Setting up API type and module change handlers');
        
        // When API type changes, may need to adjust module list
        $('#gaddon-setting-row-api_type select').on('change', function() {
            var apiType = $(this).val();
            console.log('GF Zoho Sync: API type changed to ' + apiType);
            
            // Load fields if module already selected
            var module = $('#gaddon-setting-row-module select').val();
            if (module) {
                gfZohoSync.loadZohoFields(module, apiType);
            }
        });
        
        // When module changes, load its fields
        $('#gaddon-setting-row-module select').on('change', function() {
            var module = $(this).val();
            var apiType = $('#gaddon-setting-row-api_type select').val();
            
            console.log('GF Zoho Sync: Module changed to ' + module + ' (' + apiType + ')');
            
            if (module) {
                gfZohoSync.loadZohoFields(module, apiType);
            }
        });
        
        // Add test connection button
        $('#gaddon-setting-row-api_type').after(
            '<tr><th></th><td><button type="button" id="test-connection" ' +
            'class="button-secondary">Test Zoho Connection</button></td></tr>'
        );
        
        $('#test-connection').on('click', function(e) {
            e.preventDefault();
            gfZohoSync.testConnection();
        });
        
        // Initialize fields if module is already selected
        var initialModule = $('#gaddon-setting-row-module select').val();
        var initialApiType = $('#gaddon-setting-row-api_type select').val();
        
        if (initialModule) {
            console.log('GF Zoho Sync: Initializing fields for module ' + initialModule + ' (' + initialApiType + ')');
            gfZohoSync.loadZohoFields(initialModule, initialApiType);
        }
    }
});

// Open Zoho OAuth in a popup
jQuery(document).on('click', '#gf-zoho-connect', function(e) {
    console.log('GF Zoho Sync: Opening OAuth popup');
    e.preventDefault();
    window.open(this.href, 'ZohoOAuth', 'width=600,height=700');
});
