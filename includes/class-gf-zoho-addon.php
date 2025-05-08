<?php

use GFAPI;

if ( ! class_exists('GFAddOn') ) {
    require_once GF_PLUGIN_DIR . '/includes/addon/class-gf-addon.php';
}

class GFZohoSyncAddOn extends GFAddOn {
    protected $_version = '1.0.0';
    protected $_min_gravityforms_version = '2.5';
    protected $_slug = 'gf-zoho-sync';
    protected $_path = 'gf-zoho-sync/gf-zoho-sync.php';
    protected $_full_path = __FILE__;

    public static function get_instance() {
        return parent::get_instance();
    }

    public function init() {
        parent::init();
        // AJAX for Zoho fields
        add_action('wp_ajax_gf_zoho_get_fields', [ $this, 'ajax_get_zoho_fields' ]);
        // Enqueue admin assets for feed settings
        add_action('gform_form_settings_page_' . $this->_slug, [ $this, 'enqueue_admin_assets' ]);
    }

    public function enqueue_admin_assets() {
        wp_enqueue_script('gf-zoho-admin', plugins_url('../assets/js/admin.js', __FILE__), ['jquery'], $this->_version, true);
        wp_localize_script('gf-zoho-admin', 'gfZohoSync', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'security' => wp_create_nonce('gf-zoho-nonce'),
            'gfFields' => $this->get_gf_fields(),
        ]);
        wp_enqueue_style('gf-zoho-admin', plugins_url('../assets/css/admin.css', __FILE__), [], $this->_version);
    }

    private function get_gf_fields() {
        $form_id = $this->get_current_form_id();
        $fields = [];
        if ( $form_id ) {
            $form = GFAPI::get_form_meta( $form_id );
            foreach ( $form['fields'] as $field ) {
                if ( isset( $field->id ) && ! empty( $field->label ) ) {
                    $fields[] = [ 'id' => $field->id, 'label' => $field->label ];
                }
            }
        }
        return $fields;
    }

    protected function feed_settings_fields() {
        return [
            [
                'title'       => 'Zoho Sync Feed Settings',
                'description' => 'Select which module to sync and map Gravity Forms fields to Zoho fields.',
                'fields'      => [
                    [
                        'name'     => 'module',
                        'label'    => 'Zoho Module',
                        'type'     => 'select',
                        'choices'  => [
                            ['label'=>'Cases','value'=>'Cases'],
                            ['label'=>'Contacts','value'=>'Contacts'],
                            ['label'=>'Products','value'=>'Products'],
                            ['label'=>'Accounts','value'=>'Accounts'],
                            ['label'=>'Leads','value'=>'Leads'],
                            ['label'=>'Deals','value'=>'Deals'],
                        ],
                        'required' => true,
                        'onchange' => 'gfZohoSync.loadZohoFields(this.value);',
                    ],
                    [
                        'name'     => 'lookup_field',
                        'label'    => 'Zoho Lookup Field API Name',
                        'type'     => 'text',
                        'tooltip'  => 'e.g. Email for Contacts, VIN for Products, or GF_Entry_ID__c',
                        'required' => true,
                    ],
                    [
                        'name'        => 'field_mappings',
                        'label'       => 'Field Mappings',
                        'type'        => 'textarea',
                        'description' => '<button type="button" class="button" id="add-mapping">Add Mapping</button><div id="zoho-field-mappings"></div>',
                        'tooltip'     => 'Click "Add Mapping" to map each GF field to a Zoho field.',
                        'required'    => true,
                    ],
                ],
            ],
        ];
    }

    public function ajax_get_zoho_fields() {
        check_ajax_referer('gf-zoho-nonce', 'security');
        $module = isset( $_POST['module'] ) ? sanitize_text_field( $_POST['module'] ) : '';
        if ( ! $module ) {
            wp_send_json_error( 'Module required' );
        }
        $api = new Zoho_API();
        $meta = $api->request( 'GET', "settings/fields?module={$module}" );
        $fields = [];
        if ( ! empty( $meta['fields'] ) ) {
            foreach ( $meta['fields'] as $f ) {
                $fields[] = [ 'api_name' => $f['api_name'], 'label' => $f['field_label'] ];
            }
        }
        wp_send_json_success( $fields );
    }
}