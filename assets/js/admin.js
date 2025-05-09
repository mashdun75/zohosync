/**
 * Gravity Forms Zoho Sync - Admin Script
 */

jQuery(function($) {
    console.log('GF Zoho Sync: Admin script initialized');
    
    // Track whether we're on the settings page
    var isSettingsPage = window.location.href.indexOf('page=gf-zoho-sync') > -1;
    
    if (isSettingsPage) {
        // Handle API domain select change
        $('#gf_zoho_api_domain').on('change', function() {
            console.log('API Domain changed to: ' + $(this).val());
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
});