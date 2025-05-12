<?php
/**
 * Simple GF-Zoho Integration Class
 * Handles basic integration between Gravity Forms and Zoho
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Integration {
    /**
     * Initialize the integration
     */
    public static function init() {
        // Check if Gravity Forms is active
        if (!class_exists('GFForms')) {
            return;
        }
        
        // Add Gravity Forms hooks
        add_filter('gform_form_settings_menu', array(__CLASS__, 'add_form_settings_menu'), 10, 2);
        add_action('gform_form_settings_page_zoho', array(__CLASS__, 'render_form_settings_page'));
        
        // Fix permissions for the form settings page
        add_filter('gform_form_settings_page_capability_zoho', array(__CLASS__, 'form_settings_capability'));
        
        // Add custom icon for admin
        add_action('admin_head', array(__CLASS__, 'add_zoho_icon_style'));
    }
    
    /**
     * Add custom CSS for Zoho icon
     */
    public static function add_zoho_icon_style() {
        // Check if we're on the form settings page
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'toplevel_page_gf_edit_forms') {
            return;
        }
        
        // Add custom CSS for Zoho icon
        ?>
        <style type="text/css">
            /* Zoho icon for form settings */
            .icon-zoho::before {
                content: "Z";
                font-weight: bold;
                font-family: sans-serif;
                background-color: #ea3b2c;
                color: white;
                border-radius: 3px;
                padding: 2px 4px;
                font-size: 12px;
                display: inline-block;
                line-height: 1;
                margin-right: 5px;
            }
        </style>
        <?php
    }
    
    /**
     * Set capability for the Zoho settings page
     */
    public static function form_settings_capability() {
        return 'gravityforms_edit_forms';
    }
    
    /**
     * Add Zoho tab to form settings
     */
    public static function add_form_settings_menu($menu_items, $form_id) {
        $menu_items[] = array(
            'name' => 'zoho',
            'label' => 'Zoho Sync',
            'icon' => 'zoho', // Custom class defined in add_zoho_icon_style
            'query' => array(
                'page' => 'zoho'
            )
        );
        
        return $menu_items;
    }
    
    /**
     * Render form settings page
     */
    public static function render_form_settings_page() {
        // Get current form
        $form_id = rgget('id');
        if (empty($form_id)) {
            die(__('No form ID provided.', 'gf-zoho-sync'));
        }
        
        $form = GFAPI::get_form($form_id);
        if (!$form) {
            die(__('Form not found.', 'gf-zoho-sync'));
        }
        
        // Display settings page
        ?>
        <h2>Zoho Sync Settings</h2>
        
        <p>This is a placeholder for the Zoho Sync settings page. In the fully functional plugin, you would configure mappings between form fields and Zoho fields here.</p>
        
        <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
            <h3>Form Fields</h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Label</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($form['fields'] as $field): ?>
                    <tr>
                        <td><?php echo esc_html($field->id); ?></td>
                        <td><?php echo esc_html($field->label); ?></td>
                        <td><?php echo esc_html($field->type); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
            <h3>Zoho Connection Status</h3>
            <?php
            if (class_exists('Zoho_API')) {
                $api = new Zoho_API();
                $connected = (bool) $api->get_access_token();
                
                if ($connected) {
                    echo '<p class="connection-status success">✅ Connected to Zoho</p>';
                } else {
                    echo '<p class="connection-status error">❌ Not connected to Zoho</p>';
                    echo '<p>Please configure Zoho API credentials in the <a href="' . esc_url(admin_url('options-general.php?page=gf-zoho-sync')) . '">plugin settings</a>.</p>';
                }
            } else {
                echo '<p class="connection-status error">❌ Zoho API class not found</p>';
            }
            ?>
        </div>
        
        <div style="background: #fff; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px;">
            <h3>Placeholder Feed Configuration</h3>
            <p>In the full version, you would configure:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li>Which Zoho module to sync with (Contacts, Leads, etc.)</li>
                <li>Field mappings between your form fields and Zoho fields</li>
                <li>Conditions for when to sync data</li>
                <li>Options for bidirectional sync</li>
            </ul>
            <p>This simplified version doesn't include these features yet.</p>
        </div>
        <?php
    }
}

// Initialize the integration
GF_Zoho_Integration::init();