<?php
/**
 * Simplified Gravity Forms Zoho Sync Add-On
 * 
 * Note: This is a minimal implementation to avoid potential issues
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Only load if GFAddOn class exists
if (!class_exists('GFAddOn')) {
    return;
}

class GFZohoSyncAddOn extends GFAddOn {
    // Basic settings - prevent fatal errors with undefined properties
    protected $_version = GF_ZOHO_SYNC_VERSION;
    protected $_min_gravityforms_version = '2.5';
    protected $_slug = 'gf-zoho-sync';
    protected $_path = 'gf-zoho-sync/gf-zoho-sync.php';
    protected $_full_path = __FILE__;
    protected $_title = 'Gravity Forms Zoho Sync';
    protected $_short_title = 'Zoho Sync';

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
     * Initialize the add-on with minimal functionality
     */
    public function init() {
        // Call parent init
        parent::init();
        
        // Add minimal AJAX handler for testing
        add_action('wp_ajax_gf_zoho_test', array($this, 'ajax_test'));
    }
    
    /**
     * Minimal AJAX test handler
     */
    public function ajax_test() {
        // Check permissions
        if (!current_user_can('gravityforms_edit_forms')) {
            wp_send_json_error('Permission denied');
        }
        
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'gf_zoho_test')) {
            wp_send_json_error('Invalid nonce');
        }
        
        // Return success
        wp_send_json_success('Test successful!');
    }
    
    /**
     * Register scripts for the add-on
     */
    public function scripts() {
        $scripts = array(
            array(
                'handle'    => 'gf_zoho_admin',
                'src'       => GF_ZOHO_SYNC_URL . 'assets/js/admin.js',
                'version'   => $this->_version,
                'deps'      => array('jquery'),
                'in_footer' => true,
                'enqueue'   => array(
                    array('admin_page' => array('form_settings', 'plugin_settings'))
                )
            )
        );

        return array_merge(parent::scripts(), $scripts);
    }
    
    /**
     * Register styles for the add-on
     */
    public function styles() {
        $styles = array(
            array(
                'handle'  => 'gf_zoho_admin',
                'src'     => GF_ZOHO_SYNC_URL . 'assets/css/admin.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array('admin_page' => array('form_settings', 'plugin_settings'))
                )
            )
        );

        return array_merge(parent::styles(), $styles);
    }
    
    /**
     * Setup plugin settings fields - minimal implementation
     */
    public function plugin_settings_fields() {
        return array(
            array(
                'title'  => 'Zoho Sync Settings',
                'fields' => array(
                    array(
                        'name'    => 'info',
                        'type'    => 'html',
                        'content' => '<p>Please use the <a href="' . admin_url('options-general.php?page=gf-zoho-sync') . '">Zoho Sync Settings</a> page to configure this add-on.</p>'
                    )
                )
            )
        );
    }
}