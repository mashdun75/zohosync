<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('gravityforms_edit_forms')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Initialize variables
$oauth_result = null;
$test_result = null;

// Check for stored messages from the OAuth process
$stored_message = get_transient('zoho_oauth_message');
if ($stored_message) {
    delete_transient('zoho_oauth_message'); // Clear it so it's only shown once
    echo '<div class="' . esc_attr($stored_message['type']) . '"><p>' . esc_html($stored_message['message']) . '</p></div>';
}

// Handle test connection
if (isset($_POST['gf_zoho_test_connection']) && check_admin_referer('gf_zoho_settings')) {
    if (class_exists('Zoho_API')) {
        $api = new Zoho_API();
        $test_result = $api->test_connection();
    }
}

// Handle form submission
if (isset($_POST['gf_zoho_save_settings']) && check_admin_referer('gf_zoho_settings')) {
    $client_id = isset($_POST['gf_zoho_client_id']) ? sanitize_text_field($_POST['gf_zoho_client_id']) : '';
    $client_secret = isset($_POST['gf_zoho_client_secret']) ? sanitize_text_field($_POST['gf_zoho_client_secret']) : '';
    $api_domain = isset($_POST['gf_zoho_api_domain']) ? sanitize_text_field($_POST['gf_zoho_api_domain']) : 'www.zohoapis.com';
    
    update_option('gf_zoho_client_id', $client_id);
    update_option('gf_zoho_client_secret', $client_secret);
    update_option('gf_zoho_api_domain', $api_domain);
    
    echo '<div class="updated"><p>Settings saved successfully.</p></div>';
}

// Handle disconnect
if (isset($_POST['gf_zoho_disconnect']) && check_admin_referer('gf_zoho_settings')) {
    if (class_exists('Zoho_API')) {
        $api = new Zoho_API();
        $api->clear_tokens();
        echo '<div class="updated"><p>Disconnected from Zoho successfully.</p></div>';
    }
}

// Process log settings
if (isset($_POST['gf_zoho_save_log_settings']) && check_admin_referer('gf_zoho_log_settings')) {
    $enable_logging = isset($_POST['gf_zoho_enable_logging']) ? true : false;
    update_option('gf_zoho_enable_logging', $enable_logging);
    echo '<div class="updated"><p>Log settings saved.</p></div>';
}

// Process log clear
if (isset($_POST['gf_zoho_clear_logs']) && check_admin_referer('gf_zoho_log_settings')) {
    $logger = gf_zoho_logger();
    $result = $logger->clear_log();
    if ($result) {
        echo '<div class="updated"><p>Logs cleared successfully.</p></div>';
    } else {
        echo '<div class="error"><p>Failed to clear logs.</p></div>';
    }
}

// Get current settings and connection status
$client_id = get_option('gf_zoho_client_id', '');
$client_secret = get_option('gf_zoho_client_secret', '');
$api_domain = get_option('gf_zoho_api_domain', 'www.zohoapis.com');
$connected = false;
$tokens = null;
$access_token = null;

if (class_exists('Zoho_API')) {
    $api = new Zoho_API();
    $tokens = $api->get_tokens();
    $access_token = $api->get_access_token();
    $connected = !empty($client_id) && !empty($client_secret) && !empty($access_token);
}

// Display test result if available
if ($test_result) {
    $class = $test_result['success'] ? 'updated' : 'error';
    echo '<div class="' . $class . '"><p>' . esc_html($test_result['message']) . '</p></div>';
}
?>

<div class="wrap zoho-sync-container">
    <h1 class="zoho-sync-title">Zoho Sync Settings</h1>
    
    <div class="zoho-sync-card">
        <h2>Zoho API Credentials</h2>
        <p>To connect with Zoho, you need to create an API client in the <a href="https://api-console.zoho.com/" target="_blank">Zoho API Console</a>.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('gf_zoho_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="gf_zoho_client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="gf_zoho_client_id" name="gf_zoho_client_id" value="<?php echo esc_attr($client_id); ?>" class="zoho-sync-input">
                    </td>
                </tr>
                <tr>
                    <th><label for="gf_zoho_client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" id="gf_zoho_client_secret" name="gf_zoho_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="zoho-sync-input">
                    </td>
                </tr>
                <tr>
                    <th><label for="gf_zoho_api_domain">API Domain</label></th>
                    <td>
                        <select id="gf_zoho_api_domain" name="gf_zoho_api_domain" class="zoho-sync-input">
                            <option value="www.zohoapis.com" <?php selected($api_domain, 'www.zohoapis.com'); ?>>US (www.zohoapis.com)</option>
                            <option value="www.zohoapis.eu" <?php selected($api_domain, 'www.zohoapis.eu'); ?>>EU (www.zohoapis.eu)</option>
                            <option value="www.zohoapis.in" <?php selected($api_domain, 'www.zohoapis.in'); ?>>IN (www.zohoapis.in)</option>
                            <option value="www.zohoapis.com.au" <?php selected($api_domain, 'www.zohoapis.com.au'); ?>>AU (www.zohoapis.com.au)</option>
                            <option value="www.zohoapis.jp" <?php selected($api_domain, 'www.zohoapis.jp'); ?>>JP (www.zohoapis.jp)</option>
                        </select>
                        <p class="description">Select the Zoho data center where your account is hosted.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="gf_zoho_save_settings" class="zoho-sync-button" value="Save Settings">
            </p>
        </form>
    </div>
    
    <div class="zoho-sync-card">
        <h2>Zoho Connection</h2>
        <?php if (!$client_id || !$client_secret): ?>
            <p class="connection-status error">❌ Missing API credentials</p>
            <p>Please save your API credentials before connecting to Zoho.</p>
        <?php elseif (!$connected): ?>
            <p class="connection-status error">❌ Not connected to Zoho</p>
            <p>You need to authorize this plugin to access your Zoho account.</p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=gf_zoho_sync_settings&zoho_auth=1')); ?>" class="zoho-sync-button">Connect to Zoho</a>
        <?php else: ?>
            <p class="connection-status success">✅ Connected to Zoho</p>
            <form method="post" action="">
                <?php wp_nonce_field('gf_zoho_settings'); ?>
                <input type="submit" name="gf_zoho_test_connection" class="zoho-sync-button zoho-sync-button-secondary" value="Test Connection">
                <input type="submit" name="gf_zoho_disconnect" class="zoho-sync-button zoho-sync-button-secondary" value="Disconnect from Zoho" onclick="return confirm('Are you sure you want to disconnect from Zoho? This will remove the stored access tokens.');">
            </form>
        <?php endif; ?>
    </div>
    
    <div class="zoho-sync-card">
        <h2>Gravity Forms Integration</h2>
        <?php if (class_exists('GFForms')): ?>
            <p class="connection-status success">✅ Gravity Forms is active<?php echo class_exists('GFCommon') ? ' (' . GFCommon::$version . ')' : ''; ?></p>
            <p>To configure Zoho sync for a form, go to <a href="<?php echo admin_url('admin.php?page=gf_zoho_sync_form_mapping'); ?>">Form Mapping</a>.</p>
        <?php else: ?>
            <p class="connection-status error">❌ Gravity Forms is not active</p>
            <p>Gravity Forms is required for this plugin to work. Please install and activate Gravity Forms.</p>
        <?php endif; ?>
    </div>
    
    <!-- Logging Settings -->
    <div class="zoho-sync-card">
        <h2>Logging Settings</h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('gf_zoho_log_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="gf_zoho_enable_logging">Enable Detailed Logging</label></th>
                    <td>
                        <input type="checkbox" id="gf_zoho_enable_logging" name="gf_zoho_enable_logging" value="1" <?php checked(get_option('gf_zoho_enable_logging', true)); ?>>
                        <p class="description">Enable detailed logging of Zoho sync operations.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="gf_zoho_save_log_settings" class="zoho-sync-button" value="Save Log Settings">
                <input type="submit" name="gf_zoho_clear_logs" class="zoho-sync-button zoho-sync-button-secondary" value="Clear Logs" onclick="return confirm('Are you sure you want to clear all logs?');">
            </p>
        </form>
    </div>
</div>