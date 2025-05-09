<?php
/**
 * Plugin Name: GF ↔ Zoho Sync
 * Description: Two-way sync between Gravity Forms and Zoho CRM/Desk modules.
 * Version:     1.0.0
 * Author:      Matt Duncan
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define basic constants
define('GF_ZOHO_SYNC_VERSION', '1.0.0');
define('GF_ZOHO_SYNC_PATH', plugin_dir_path(__FILE__));
define('GF_ZOHO_SYNC_URL', plugin_dir_url(__FILE__));

// Add admin notice
function gf_zoho_admin_notice() {
    // Only show this on plugin pages
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, array('settings_page_gf-zoho-sync', 'forms_page_gf_zoho_sync'))) {
        return;
    }
    
    ?>
    <div class="notice notice-info is-dismissible">
        <p><strong>GF ↔ Zoho Sync:</strong> Running in simplified mode. Connect to Zoho in Settings > Zoho Sync first, then configure field mappings.</p>
    </div>
    <?php
}
add_action('admin_notices', 'gf_zoho_admin_notice');

// Load dependencies
function gf_zoho_load_dependencies() {
    // Include the API class
    require_once GF_ZOHO_SYNC_PATH . 'includes/class-zoho-api.php';
    
    // Include GF integration class if Gravity Forms is active
    if (class_exists('GFForms')) {
        require_once GF_ZOHO_SYNC_PATH . 'includes/class-gf-zoho-direct.php';
    }
}
add_action('plugins_loaded', 'gf_zoho_load_dependencies', 5);

// Add settings page
function gf_zoho_add_settings_menu() {
    add_options_page(
        'Gravity Forms Zoho Sync',
        'Zoho Sync',
        'manage_options',
        'gf-zoho-sync',
        'gf_zoho_sync_settings_page'
    );
}
add_action('admin_menu', 'gf_zoho_add_settings_menu');

// Enqueue admin assets
function gf_zoho_admin_assets($hook) {
    // Only load on our settings page
    if ($hook != 'settings_page_gf-zoho-sync' && $hook != 'forms_page_gf_zoho_sync') {
        return;
    }
    
    // Enqueue CSS
    wp_enqueue_style(
        'gf-zoho-admin',
        GF_ZOHO_SYNC_URL . 'assets/css/admin.css',
        array(),
        GF_ZOHO_SYNC_VERSION
    );
    
    // Enqueue JS
    wp_enqueue_script(
        'gf-zoho-admin',
        GF_ZOHO_SYNC_URL . 'assets/js/admin.js',
        array('jquery'),
        GF_ZOHO_SYNC_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'gf_zoho_admin_assets');

// Render settings page
function gf_zoho_sync_settings_page() {
    // Check user permissions
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Initialize variables
    $oauth_result = null;
    $test_result = null;
    
    // Handle OAuth callback
    if (isset($_GET['code']) && !empty($_GET['code'])) {
        if (class_exists('Zoho_API')) {
            $api = new Zoho_API();
            $oauth_result = $api->handle_oauth_callback(sanitize_text_field($_GET['code']));
        }
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
    
    // Display OAuth result if available
    if ($oauth_result) {
        $class = $oauth_result['success'] ? 'updated' : 'error';
        echo '<div class="' . $class . '"><p>' . esc_html($oauth_result['message']) . '</p></div>';
    }
    
    // Display test result if available
    if ($test_result) {
        $class = $test_result['success'] ? 'updated' : 'error';
        echo '<div class="' . $class . '"><p>' . esc_html($test_result['message']) . '</p></div>';
    }
    
    // Output settings page HTML
    ?>
    <div class="wrap">
        <h1>Gravity Forms Zoho Sync Settings</h1>
        
        <!-- Debug Information Card -->
        <div class="zoho-sync-card">
            <h2>Connection Status</h2>
            <div style="background:#f8f8f8; padding:10px; border:1px solid #ddd;">
                <strong>Debug Information:</strong><br>
                Client ID set: <?php echo !empty($client_id) ? '✅ Yes' : '❌ No'; ?><br>
                Client Secret set: <?php echo !empty($client_secret) ? '✅ Yes' : '❌ No'; ?><br>
                Tokens in database: <?php echo !empty($tokens) ? '✅ Yes' : '❌ No'; ?><br>
                Access token available: <?php echo !empty($access_token) ? '✅ Yes' : '❌ No'; ?><br>
                Overall status: <?php echo $connected ? '✅ Connected' : '❌ Not connected'; ?>
            </div>
            
            <?php
            // Add direct API test
            if ($connected && !empty($access_token)) {
                echo '<div style="background:#f8f8f8; padding:10px; margin-top:10px; border:1px solid #ddd;">';
                echo '<strong>Direct API Test:</strong><br>';
                
                $api_url = "https://{$api_domain}/crm/v2/settings/modules";
                $args = [
                    'headers' => [
                        'Authorization' => "Zoho-oauthtoken " . $access_token,
                        'Content-Type' => 'application/json'
                    ],
                    'timeout' => 15
                ];
                
                echo "Testing connection to: " . esc_html($api_url) . "<br>";
                
                $test_response = wp_remote_get($api_url, $args);
                if (is_wp_error($test_response)) {
                    $error_message = $test_response->get_error_message();
                    echo "Error: " . esc_html($error_message) . "<br>";
                    error_log("Zoho Direct Test - WP Error: {$error_message}");
                } else {
                    $status = wp_remote_retrieve_response_code($test_response);
                    $body = wp_remote_retrieve_body($test_response);
                    $body_short = substr($body, 0, 100) . (strlen($body) > 100 ? '...' : '');
                    
                    echo "Status Code: " . esc_html($status) . "<br>";
                    echo "Response Preview: " . esc_html($body_short) . "<br>";
                    
                    error_log("Zoho Direct Test - Status: {$status}, Response: {$body}");
                }
                
                echo '</div>';
            }
            ?>
            
            <?php if ($connected && !empty($test_result) && $test_result['success']): ?>
                <div style="margin-top:10px;">
                    <p class="connection-status success">✅ Connection verified. Zoho API is working correctly.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="zoho-sync-card">
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
        
        <div class="zoho-sync-card">
            <h2>Zoho Connection</h2>
            <?php if (!$client_id || !$client_secret): ?>
                <p class="connection-status error">❌ Missing API credentials</p>
                <p>Please save your API credentials before connecting to Zoho.</p>
            <?php elseif (!$connected): ?>
                <p class="connection-status error">❌ Not connected to Zoho</p>
                <p>You need to authorize this plugin to access your Zoho account.</p>
                <?php if (class_exists('Zoho_API')): ?>
                    <a href="<?php echo esc_url($api->get_auth_url()); ?>" class="button button-primary">Connect to Zoho</a>
                <?php else: ?>
                    <p class="error">Error: Zoho API class not loaded.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="connection-status success">✅ Connected to Zoho</p>
                <form method="post" action="">
                    <?php wp_nonce_field('gf_zoho_settings'); ?>
                    <input type="submit" name="gf_zoho_test_connection" class="button" value="Test Connection">
                    <input type="submit" name="gf_zoho_disconnect" class="button" value="Disconnect from Zoho">
                </form>
            <?php endif; ?>
        </div>
        
        <div class="zoho-sync-card">
            <h2>Gravity Forms Integration</h2>
            <?php if (class_exists('GFForms')): ?>
                <p class="connection-status success">✅ Gravity Forms is active<?php echo class_exists('GFCommon') ? ' (' . GFCommon::$version . ')' : ''; ?></p>
                <p>To configure Zoho sync for a form, go to <a href="<?php echo admin_url('admin.php?page=gf_zoho_sync'); ?>">Forms > Zoho Sync</a>.</p>
            <?php else: ?>
                <p class="connection-status error">❌ Gravity Forms is not active</p>
                <p>Gravity Forms is required for this plugin to work. Please install and activate Gravity Forms.</p>
            <?php endif; ?>
        </div>
        
        <div class="zoho-sync-card">
            <h2>System Information</h2>
            <table class="widefat" style="margin-top: 15px;">
                <tbody>
                    <tr>
                        <th>PHP Version</th>
                        <td><?php echo PHP_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>WordPress Version</th>
                        <td><?php echo get_bloginfo('version'); ?></td>
                    </tr>
                    <tr>
                        <th>Gravity Forms Installed</th>
                        <td><?php echo class_exists('GFForms') ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <?php if (class_exists('GFCommon')): ?>
                    <tr>
                        <th>Gravity Forms Version</th>
                        <td><?php echo GFCommon::$version; ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Memory Limit</th>
                        <td><?php echo WP_MEMORY_LIMIT; ?></td>
                    </tr>
                    <tr>
                        <th>Debug Mode</th>
                        <td><?php echo defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled'; ?></td>
                    </tr>
                    <tr>
                        <th>Database Table</th>
                        <td>
                            <?php 
                            global $wpdb;
                            $table_name = $wpdb->prefix . 'gf_zoho_map';
                            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
                            echo $table_exists ? '✅ Exists' : '❌ Not found';
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="zoho-sync-card">
            <h2>Getting Started</h2>
            <ol>
                <li>Create a Zoho API client in the <a href="https://api-console.zoho.com/" target="_blank">Zoho API Console</a></li>
                <li>Enter your Client ID and Client Secret above</li>
                <li>Click "Connect to Zoho" to authorize the plugin</li>
                <li>Go to <a href="<?php echo admin_url('admin.php?page=gf_zoho_sync'); ?>">Forms > Zoho Sync</a> to configure form mappings</li>
            </ol>
        </div>
        
        <div class="zoho-sync-card">
            <h2>Debug Log</h2>
            <?php
            // Check if the user has the capability to view logs
            if (current_user_can('manage_options')) {
                $log_file = WP_CONTENT_DIR . '/debug.log';
                $zoho_log_entries = [];
                
                if (file_exists($log_file) && is_readable($log_file)) {
                    // Get the last 100 lines from the log file
                    $log_content = shell_exec('tail -n 100 ' . escapeshellarg($log_file));
                    
                    // If shell_exec fails, try reading the file directly
                    if (empty($log_content)) {
                        $log_content = file_get_contents($log_file);
                        // Only get the last part of a potentially large file
                        $log_content = substr($log_content, -50000);
                    }
                    
                    // Break into lines and look for Zoho-related entries
                    $lines = explode("\n", $log_content);
                    foreach ($lines as $line) {
                        if (strpos($line, 'Zoho API Debug') !== false || 
                            strpos($line, 'Zoho OAuth') !== false ||
                            strpos($line, 'Zoho Direct Test') !== false) {
                            $zoho_log_entries[] = $line;
                        }
                    }
                    
                    // Display the entries
                    if (!empty($zoho_log_entries)) {
                        echo '<div style="background:#f8f8f8; padding:10px; border:1px solid #ddd; max-height:300px; overflow:auto;">';
                        echo '<pre style="margin:0; white-space:pre-wrap;">';
                        foreach (array_reverse($zoho_log_entries) as $entry) {
                            echo htmlspecialchars($entry) . "\n";
                        }
                        echo '</pre></div>';
						} else {
                        echo '<p>No Zoho-related log entries found. Enable WP_DEBUG_LOG in wp-config.php to collect debug information.</p>';
                    }
                } else {
                    echo '<p>Debug log file not found or not readable. Make sure WP_DEBUG_LOG is enabled in wp-config.php.</p>';
                    
                    // Show instructions for enabling debug logging
                    echo '<div style="background:#f8f8f8; padding:10px; border:1px solid #ddd; margin-top:10px;">';
                    echo '<strong>How to enable debug logging:</strong><br>';
                    echo 'Add these lines to your wp-config.php file:<br>';
                    echo '<code>define(\'WP_DEBUG\', true);<br>define(\'WP_DEBUG_LOG\', true);<br>define(\'WP_DEBUG_DISPLAY\', false);</code>';
                    echo '</div>';
                }
            } else {
                echo '<p>You do not have permission to view debug logs.</p>';
            }
            ?>
        </div>
        
        <div class="zoho-sync-card">
            <h2>Troubleshooting Tips</h2>
            <ul>
                <li><strong>Invalid OAuth Token Error</strong>: If you're seeing "invalid oauth token" errors, try disconnecting and reconnecting to Zoho.</li>
                <li><strong>Data Center Issues</strong>: Make sure your API Domain setting matches the region where your Zoho account is hosted.</li>
                <li><strong>Redirect URI</strong>: Ensure the authorized redirect URI in your Zoho API client matches exactly: <code><?php echo esc_html(admin_url('options-general.php?page=gf-zoho-sync')); ?></code></li>
                <li><strong>Scope Problems</strong>: Your Zoho API client should have these scopes: <code>ZohoCRM.modules.ALL,ZohoCRM.settings.ALL</code></li>
                <li><strong>Token Errors</strong>: If connection issues persist, check the debug log for more detailed error messages from the Zoho API.</li>
            </ul>
        </div>
    </div>
    <?php
}

// Add activation hook
register_activation_hook(__FILE__, 'gf_zoho_activate');
function gf_zoho_activate() {
    // Just add a simple option to indicate activation
    add_option('gf_zoho_sync_activated', time());
    
    // Log the activation
    error_log('Zoho API Debug: Plugin activated');
}

// Add deactivation hook
register_deactivation_hook(__FILE__, 'gf_zoho_deactivate');
function gf_zoho_deactivate() {
    // Just remove our activation marker
    delete_option('gf_zoho_sync_activated');
    
    // Log the deactivation
    error_log('Zoho API Debug: Plugin deactivated');
}