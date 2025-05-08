jQuery(function($){
    var zohoFields = [];
    var mappingWrap = $('#zoho-field-mappings');

    function refreshZohoFieldsDropdown(){
        mappingWrap.find('.zoho-field-select').each(function(){
            var $sel = $(this);
            var current = $sel.val();
            $sel.empty();
            zohoFields.forEach(function(f){
                $sel.append($('<option>').val(f.api_name).text(f.label));
            });
            if(current) $sel.val(current);
        });
    }

    window.gfZohoSync = {
        ajaxUrl: window.gfZohoSync ? window.gfZohoSync.ajaxUrl : '',
        security: window.gfZohoSync ? window.gfZohoSync.security : '',
        loadZohoFields: function(module){
            $.post(this.ajaxUrl, {
                action: 'gf_zoho_get_fields',
                module: module,
                security: this.security
            }, function(res){
                if(res.success){
                    zohoFields = res.data;
                    refreshZohoFieldsDropdown();
                } else {
                    alert('Error fetching Zoho fields: ' + res.data);
                }
            });
        }
    };

    // Add mapping row
    $('#add-mapping').on('click', function(){
        var row = $('<div class="mapping-row">'
            + '<select class="gf-field-select"></select> → '
            + '<select class="zoho-field-select"></select> '
            + '<button type="button" class="remove-mapping button-link">Remove</button>'
            + '</div>');
        // Populate GF fields
        (window.gfFields||[]).forEach(function(f){
            row.find('.gf-field-select').append($('<option>').val(f.id).text(f.label));
        });
        // Populate Zoho fields if available
        refreshZohoFieldsDropdown();
        mappingWrap.append(row);
    });

    // Remove mapping row
    mappingWrap.on('click', '.remove-mapping', function(){
        $(this).closest('.mapping-row').remove();
    });

    // Before saving feed, serialize mappings to textarea
    $('#gform_submit_button').closest('form').on('submit', function(){
        var mappings = [];
        mappingWrap.find('.mapping-row').each(function(){
            var gf = $(this).find('.gf-field-select').val();
            var zoho = $(this).find('.zoho-field-select').val();
            if(gf && zoho) mappings.push({gf_id: gf, zoho: zoho});
        });
        $('textarea[name="field_mappings"]').val(JSON.stringify(mappings));
    });
});
```js
// Open Zoho OAuth in a popup
jQuery(document).on('click', '#gf-zoho-connect', function(e) {
    e.preventDefault();
    window.open(this.href, 'ZohoOAuth', 'width=600,height=700');
});