<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('gravityforms_edit_forms')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}
?>

<div class="wrap zoho-sync-container">
    <h1 class="zoho-sync-title">Zoho Sync for Gravity Forms</h1>
    
    <div class="zoho-sync-card">
        <h2>Welcome to Zoho Sync</h2>
        <p>This plugin allows you to seamlessly integrate Gravity Forms with Zoho CRM and Zoho Desk.</p>
        
        <div class="zoho-sync-menu-cards">
            <a href="<?php echo admin_url('admin.php?page=gf_zoho_sync_settings'); ?>" class="zoho-sync-menu-card">
                <h3>Settings</h3>
                <p>Configure your Zoho API credentials and connection settings</p>
            </a>
            
            <a href="<?php echo admin_url('admin.php?page=gf_zoho_sync_form_mapping'); ?>" class="zoho-sync-menu-card">
                <h3>Form Mapping</h3>
                <p>Set up mappings between form fields and Zoho fields</p>
            </a>
            
            <a href="<?php echo admin_url('admin.php?page=gf_zoho_sync_history'); ?>" class="zoho-sync-menu-card">
                <h3>Sync History</h3>
                <p>View logs of sync operations between Gravity Forms and Zoho</p>
            </a>
        </div>
        
        <?php
        // Check connection status
        $connected = false;
        $client_id = get_option('gf_zoho_client_id', '');
        $client_secret = get_option('gf_zoho_client_secret', '');
        
        if (class_exists('Zoho_API')) {
            $api = new Zoho_API();
            $tokens = $api->get_tokens();
            $access_token = $api->get_access_token();
            $connected = !empty($client_id) && !empty($client_secret) && !empty($access_token);
        }
        ?>
        
        <div class="zoho-sync-status-panel <?php echo $connected ? 'connected' : 'disconnected'; ?>">
            <h3>Connection Status</h3>
            <?php if ($connected): ?>
                <p class="connection-status success">✅ Connected to Zoho</p>
                <p>Your Gravity Forms are ready to sync with Zoho CRM and Zoho Desk.</p>
            <?php else: ?>
                <p class="connection-status error">❌ Not connected to Zoho</p>
                <p>Please configure your API credentials in the <a href="<?php echo admin_url('admin.php?page=gf_zoho_sync_settings'); ?>">Settings</a> page.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="zoho-sync-card">
        <h2>Quick Start Guide</h2>
        <ol>
            <li>Go to the <strong>Settings</strong> page to configure your Zoho API credentials and connect to Zoho</li>
            <li>Visit the <strong>Form Mapping</strong> page to set up field mappings for your forms</li>
            <li>Check the <strong>Sync History</strong> page to monitor sync operations and troubleshoot any issues</li>
        </ol>
        
        <p>For more detailed instructions, please refer to the plugin documentation.</p>
    </div>
</div>