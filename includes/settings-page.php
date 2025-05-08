<?php
/**
 * Settings page for Gravity Forms Zoho Sync
 */

/**
 * Add the settings page to the WordPress admin menu
 */
function gf_zoho_add_settings_menu() {
    GFCommon::log_debug('GF Zoho Sync: Adding settings page to admin menu');
    
    add_options_page(
        'Gravity Forms Zoho Sync',
        'Zoho Sync',
        'manage_options',
        'gf-zoho-sync',
        'gf_zoho_sync_settings_page'
    );
}
add_action('admin_menu', 'gf_zoho_add_settings_menu');

/**
 * Render the settings page
 */
function gf_zoho_sync_settings_page() {
    GFCommon::log_debug('GF Zoho Sync: Rendering settings page');
    
    // Handle saving client ID and secret
    if (isset($_POST['gf_zoho_save_settings']) && check_admin_referer('gf_zoho_settings')) {
        $client_id = sanitize_text_field($_POST['gf_zoho_client_id']);
        $client_secret = sanitize_text_field($_POST['gf_zoho_client_secret']);
        $api_domain = isset($_POST['gf_zoho_api_domain']) ? sanitize_text_field($_POST['gf_zoho_api_domain']) : 'www.zohoapis.com';
        
        update_option('gf_zoho_client_id', $client_id);
        update_option('gf_zoho_client_secret', $client_secret);
        update_option('gf_zoho_api_domain', $api_domain);
        
        GFCommon::log_debug('GF Zoho Sync: Saved API credentials');
        echo '<div class="updated"><p>Settings saved successfully.</p></div>';
    }
    
    // Handle disconnect action
    if (isset($_GET['action']) && $_GET['action'] == 'disconnect' && check_admin_referer('gf_zoho_disconnect')) {
        delete_option('gf_zoho_tokens');
        
        // Also remove webhook
        $webhook_id = get_option('gf_zoho_webhook_id');
        if ($webhook_id) {
            $api = new Zoho_API();
            $api->remove_webhook($webhook_id);
            delete_option('gf_zoho_webhook_id');
            delete_option('gf_zoho_webhook_token');
        }
        
        GFCommon::log_debug('GF Zoho Sync: Disconnected from Zoho');
        echo '<div class="updated"><p>Disconnected from Zoho successfully.</p></div>';
    }
    
    // Handle OAuth callback
    if (isset($_GET['code']) && !empty($_GET['code'])) {
        gf_zoho_handle_oauth_callback(sanitize_text_field($_GET['code']));
    }
    
    // Get current settings
    $client_id = get_option('gf_zoho_client_id', '');
    $client_secret = get_option('gf_zoho_client_secret', '');
    $api_domain = get_option('gf_zoho_api_domain', 'www.zohoapis.com');
    $connected = (bool) get_option('gf_zoho_tokens');
    
    GFCommon::log_debug('GF Zoho Sync: Displaying settings page. Connected: ' . ($connected ? 'Yes' : 'No'));
    
    // Display settings page
    ?>
    <div class="wrap">
        <h1>Gravity Forms Zoho Sync Settings</h1>
        
        <div class="card">
            <h2>Zoho API Credentials</h2>
            <p>To connect with Zoho, you need to create an API client in the <a href="https://api-console.zoho.com/" target="_blank">Zoho API Console</a>.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('gf_zoho_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="gf_zoho_client_id">Client ID</label></th>
                        <td>
                            <input type="text" id="gf_zoho_client_id" name="gf_zoho_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gf_zoho_client_secret">Client Secret</label></th>
                        <td>
                            <input type="password" id="gf_zoho_client_secret" name="gf_zoho_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gf_zoho_api_domain">API Domain</label></th>
                        <td>
                            <select id="gf_zoho_api_domain" name="gf_zoho_api_domain">
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
                    <input type="submit" name="gf_zoho_save_settings" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        
        <div class="card">
            <h2>Zoho Connection</h2>
            <?php if (!$client_id || !$client_secret): ?>
                <p>Please save your API credentials before connecting to Zoho.</p>
            <?php elseif (!$connected): ?>
                <p>You need to authorize this plugin to access your Zoho account.</p>
                <a href="<?php echo esc_url(gf_zoho_get_auth_url()); ?>" id="gf-zoho-connect" class="button button-primary">Connect to Zoho</a>
            <?php else: ?>
                <p>✅ Connected to Zoho</p>
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'disconnect'), 'gf_zoho_disconnect')); ?>" class="button">Disconnect from Zoho</a>
            <?php endif; ?>
        </div>
        
        <?php if ($connected): ?>
        <div class="card">
            <h2>Webhook Status</h2>
            <?php
            $webhook_id = get_option('gf_zoho_webhook_id');
            if ($webhook_id): ?>
                <p>✅ Zoho webhook is active (ID: <?php echo esc_html($webhook_id); ?>)</p>
            <?php else: ?>
                <p>❌ No active webhook found. A webhook will be created automatically when you set up a two-way sync feed.</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>How to Use</h2>
            <ol>
                <li>Edit a Gravity Form and go to Settings > Zoho Sync</li>
                <li>Add a new feed and select the Zoho module you want to sync with</li>
                <li>Configure field mapping between your form fields and Zoho fields</li>
                <li>Enable two-way sync if you want updates in Zoho to be reflected in your form entries</li>
            </ol>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Logging</h2>
            <p>Logging is enabled for this plugin. You can view detailed logs at:</p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=gf_settings&subview=Zoho+Sync')); ?>" class="button">View Logs</a></p>
            <p class="description">Go to Forms > Settings > Logging, select "Zoho Sync" from the dropdown, and enable logging.</p>
        </div>
    </div>
    <?php
}

/**
 * Get the Zoho OAuth authorization URL
 * 
 * @return string The authorization URL
 */
function gf_zoho_get_auth_url() {
    $client_id = get_option('gf_zoho_client_id');
    $redirect_uri = admin_url('options-general.php?page=gf-zoho-sync');
    $scope = 'ZohoCRM.modules.ALL,ZohoCRM.settings.ALL,ZohoSearch.securesearch.READ';
    
    // Add Desk scope if needed
    $scope .= ',desk.tickets.ALL,desk.settings.ALL';
    
    GFCommon::log_debug("GF Zoho Sync: Generated auth URL with scope: {$scope}");
    
    return 'https://accounts.zoho.com/oauth/v2/auth?' . http_build_query([
        'scope' => $scope,
        'client_id' => $client_id,
        'response_type' => 'code',
        'access_type' => 'offline',
        'redirect_uri' => $redirect_uri
    ]);
}

/**
 * Handle OAuth callback from Zoho
 * 
 * @param string $code The authorization code
 */
function gf_zoho_handle_oauth_callback($code) {
    GFCommon::log_debug('GF Zoho Sync: Processing OAuth callback');
    
    $client_id = get_option('gf_zoho_client_id');
    $client_secret = get_option('gf_zoho_client_secret');
    $redirect_uri = admin_url('options-general.php?page=gf-zoho-sync');
    
    $response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', [
        'body' => [
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'code' => $code
        ]
    ]);
    
    if (is_wp_error($response)) {
        GFCommon::log_error('GF Zoho Sync: OAuth error: ' . $response->get_error_message());
        echo '<div class="error"><p>Error: ' . esc_html($response->get_error_message()) . '</p></div>';
        return;
    }
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!empty($data['access_token'])) {
        // Add created timestamp for token expiration tracking
        $data['created_at'] = time();
        
        update_option('gf_zoho_tokens', $data);
        GFCommon::log_debug('GF Zoho Sync: OAuth successful, tokens stored');
        echo '<div class="updated"><p>Connected to Zoho successfully!</p></div>';
    } else {
        GFCommon::log_error('GF Zoho Sync: OAuth failed: ' . wp_remote_retrieve_body($response));
        echo '<div class="error"><p>Error: ' . esc_html($data['error'] ?? 'Unknown error') . '</p></div>';
    }
}
