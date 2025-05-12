/**
 * Gravity Forms Zoho Sync - Admin Script
 */

jQuery(function($) {
    // Safe debug function to avoid linting errors
    var debug = function(msg) {
        if (window.console && window.console.log && typeof window.console.log === 'function') {
            window.console.log(msg);
        }
    };
    
    // Track whether we're on the settings page
    var isSettingsPage = window.location.href.indexOf('page=gf-zoho-sync') > -1;
    
    if (isSettingsPage) {
        // Handle API domain select change
        $('#gf_zoho_api_domain').on('change', function() {
            debug('API Domain changed to: ' + $(this).val());
        });
        
        // Add confirmation to disconnect button
        $('input[name="gf_zoho_disconnect"]').on('click', function(e) {
            if (!confirm('Are you sure you want to disconnect from Zoho? This will remove the stored access tokens.')) {
                e.preventDefault();
            }
        });
        
        // Add spinner to test connection button
        $('input[name="gf_zoho_test_connection"]').on('click', function() {
            $(this).after('<span class="spinner is-active" style="float:none; margin-left:5px;"></span>');
        });
    }
    
    // Mobile responsive adjustments for mapping table
    function adjustMappingTableForMobile() {
        if (window.innerWidth < 782 && $('#mapping-table').length) {
            $('#mapping-table').addClass('responsive');
            
            if (!$('#mapping-table').hasClass('processed')) {
                $('#mapping-table').addClass('processed');
                
                // Add data-label attribute to cells
                $('#mapping-table tbody tr').each(function() {
                    var $row = $(this);
                    var $cells = $row.find('td');
                    
                    $cells.eq(0).attr('data-label', 'Form Field');
                    $cells.eq(1).attr('data-label', 'Zoho Field');
                    $cells.eq(2).attr('data-label', 'Actions');
                });
            }
        } else {
            $('#mapping-table').removeClass('responsive');
        }
    }
    
    // Call on load and resize
    adjustMappingTableForMobile();
    $(window).on('resize', adjustMappingTableForMobile);
    
    // Tooltip for required fields
    $('.zoho-field-select').on('change', function() {
        var $this = $(this);
        var selectedField = $this.val();
        
        // Check if this is a required field
        if (window.zohoFields && selectedField) {
            $.each(window.zohoFields, function(i, field) {
                if (field.api_name === selectedField && field.required) {
                    $this.after('<p class="description" style="color:#d63638;">This is a required field in Zoho.</p>');
                    return false;
                }
            });
        }
    });
    
    // Smooth scrolling to error messages
    if ($('.error').length) {
        $('html, body').animate({
            scrollTop: $('.error').offset().top - 50
        }, 500);
    }
    
    // Add confirm before leaving if form is dirty
    var formDirty = false;
    
    $('form input, form select, form textarea').on('change', function() {
        formDirty = true;
    });
    
    $(window).on('beforeunload', function() {
        if (formDirty) {
            return 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    // Reset the dirty flag when submitting
    $('form').on('submit', function() {
        formDirty = false;
    });
    
    // Add highlight effect to saved settings
    if (window.location.search.indexOf('settings-updated=true') > -1) {
        $('.zoho-sync-card').first().css({
            'border-color': '#46b450',
            'box-shadow': '0 0 5px rgba(70, 180, 80, 0.5)'
        });
        
        setTimeout(function() {
            $('.zoho-sync-card').first().css({
                'border-color': '#e5e5e5',
                'box-shadow': '0 1px 1px rgba(0,0,0,0.04)'
            });
        }, 3000);
    }
    
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
        if (window.zohoFields && window.zohoFields.length > 0) {
            $.each(window.zohoFields, function(i, field) {
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
        }
        
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
    
    // Run field enhancement when the page loads
    if ($('#zoho_module').length) {
        // If zohoFields array exists already, enhance right away
        if (window.zohoFields && window.zohoFields.length > 0) {
            enhanceZohoFieldDisplay();
        }
        
        // Otherwise, enhance when fields load via AJAX
        $(document).on('ajaxSuccess', function(event, xhr, settings) {
            if (settings.data && settings.data.indexOf('action=gf_zoho_get_fields') !== -1) {
                if (window.zohoFields && window.zohoFields.length > 0) {
                    setTimeout(enhanceZohoFieldDisplay, 100);
                }
            }
        });
        
        // Also enhance whenever a field select changes
        $(document).on('change', '.zoho-field-select', function() {
            if (window.zohoFields && window.zohoFields.length > 0) {
                setTimeout(enhanceZohoFieldDisplay, 50);
            }
        });
    }
});