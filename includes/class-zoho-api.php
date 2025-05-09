<?php
/**
 * Simple Zoho API Class
 * Handles basic Zoho API functionality with minimal dependencies
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Zoho_API {
    private $client_id;
    private $client_secret;
    private $token_option = 'gf_zoho_tokens';
    public $api_domain;

    /**
     * Constructor
     */
    public function __construct() {
        $this->client_id = get_option('gf_zoho_client_id', '');
        $this->client_secret = get_option('gf_zoho_client_secret', '');
        $this->api_domain = get_option('gf_zoho_api_domain', 'www.zohoapis.com');
    }

    /**
     * Get stored tokens
     */
    public function get_tokens() {
        $tokens = get_option($this->token_option);
        if (!is_array($tokens) || empty($tokens['access_token'])) {
            return false;
        }
        return $tokens;
    }

    /**
     * Get current access token
     */
    public function get_access_token() {
        $tokens = $this->get_tokens();
        // Double-check we have client credentials too
        if (!$tokens || empty($tokens['access_token']) || empty($this->client_id) || empty($this->client_secret)) {
            return false;
        }
        
        return $tokens['access_token'];
    }

    /**
     * Simple test connection function
     */
    public function test_connection() {
        $token = $this->get_access_token();
        
        // Debug token information
        $debug_info = [
            'token_length' => strlen($token),
            'token_prefix' => $token ? substr($token, 0, 10) . '...' : 'NULL',
            'has_tokens' => !empty($this->get_tokens()),
            'client_id_length' => strlen($this->client_id),
            'api_domain' => $this->api_domain,
        ];
        error_log('Zoho API Debug: ' . print_r($debug_info, true));
        
        if (!$token) {
            return array(
                'success' => false,
                'message' => 'No access token available. Please connect to Zoho first.'
            );
        }

        // Determine API URL
        $url = "https://{$this->api_domain}/crm/v2/settings/modules";
        error_log('Zoho API Debug: Testing connection to URL: ' . $url);
        
        $args = array(
            'headers' => array(
                'Authorization' => "Zoho-oauthtoken {$token}",
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        );

        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Zoho API Debug: WP Error: ' . $error_message);
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $error_message
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('Zoho API Debug: Response Status: ' . $status_code);
        error_log('Zoho API Debug: Response Body: ' . $body);
        
        $body_parsed = json_decode($body, true);
        
        if ($status_code === 200 && !empty($body_parsed['modules'])) {
            error_log('Zoho API Debug: Connection test successful');
            return array(
                'success' => true,
                'message' => 'Connection successful!',
                'data' => $body_parsed
            );
        } else {
            error_log('Zoho API Debug: Connection test failed with status ' . $status_code);
            return array(
                'success' => false,
                'message' => 'API Error: ' . (isset($body_parsed['message']) ? $body_parsed['message'] : 'Unknown error'),
                'status' => $status_code
            );
        }
    }

    /**
     * Get authorization URL for OAuth
     */
    public function get_auth_url() {
        // Check that we have client ID
        if (empty($this->client_id)) {
            return '#missing-client-id';
        }
        
        $redirect_uri = admin_url('options-general.php?page=gf-zoho-sync');
        $scope = 'ZohoCRM.modules.ALL,ZohoCRM.settings.ALL';
        
        // Build the auth URL
        $auth_url = 'https://accounts.zoho.com/oauth/v2/auth?' . http_build_query(array(
            'scope' => $scope,
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'access_type' => 'offline',
            'redirect_uri' => $redirect_uri
        ));
        
        error_log('Zoho API Debug: Generated Auth URL: ' . $auth_url);
        
        return $auth_url;
    }

    /**
     * Handle OAuth callback and save tokens
     */
    public function handle_oauth_callback($code) {
        // Add debug logging
        error_log('Zoho OAuth Callback Called with code length: ' . strlen($code));
        
        // Check that we have client credentials
        if (empty($this->client_id) || empty($this->client_secret)) {
            return array(
                'success' => false,
                'message' => 'Missing Zoho client credentials. Please configure them in the settings.'
            );
        }
        
        $redirect_uri = admin_url('options-general.php?page=gf-zoho-sync');
        
        error_log('Zoho OAuth Debug: Attempting to exchange code for token with redirect URI: ' . $redirect_uri);
        
        $post_data = array(
            'grant_type' => 'authorization_code',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $redirect_uri,
            'code' => $code
        );
        
        error_log('Zoho OAuth Debug: Token request data: ' . print_r($post_data, true));
        
        $response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', array(
            'body' => $post_data
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Zoho OAuth Debug: WP Error during token request: ' . $error_message);
            return array(
                'success' => false,
                'message' => 'OAuth error: ' . $error_message
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('Zoho OAuth Debug: Token response status: ' . $status_code);
        error_log('Zoho OAuth Debug: Token response body: ' . $body);
        
        $data = json_decode($body, true);
        
        if (!empty($data['access_token'])) {
            // Add created timestamp for token expiration tracking
            $data['created_at'] = time();
            
            update_option($this->token_option, $data);
            
            error_log('Zoho OAuth Debug: Successfully obtained and stored token');
            
            return array(
                'success' => true,
                'message' => 'Connected to Zoho successfully!'
            );
        } else {
            error_log('Zoho OAuth Debug: Failed to obtain token. Error: ' . (isset($data['error']) ? $data['error'] : 'Unknown error'));
            
            return array(
                'success' => false,
                'message' => 'OAuth failed: ' . (isset($data['error']) ? $data['error'] : 'Unknown error')
            );
        }
    }
    
    /**
     * Refresh the access token using the refresh token
     */
    public function refresh_token() {
        $tokens = $this->get_tokens();
        
        if (!$tokens || empty($tokens['refresh_token'])) {
            error_log('Zoho API Debug: No refresh token available');
            return false;
        }
        
        error_log('Zoho API Debug: Attempting to refresh token');
        
        $response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', [
            'body' => [
                'refresh_token' => $tokens['refresh_token'],
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token',
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('Zoho API Debug: Refresh token error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        
        error_log('Zoho API Debug: Refresh token response status: ' . $status);
        error_log('Zoho API Debug: Refresh token response: ' . $body);
        
        $new_tokens = json_decode($body, true);
        
        if (!empty($new_tokens['access_token'])) {
            // Preserve the refresh token if not returned
            if (empty($new_tokens['refresh_token'])) {
                $new_tokens['refresh_token'] = $tokens['refresh_token'];
            }
            
            // Update the timestamp
            $new_tokens['created_at'] = time();
            
            update_option($this->token_option, $new_tokens);
            
            error_log('Zoho API Debug: Token refreshed successfully');
            return $new_tokens['access_token'];
        }
        
        error_log('Zoho API Debug: Token refresh failed');
        return false;
    }
    
    /**
     * Clear stored tokens
     */
    public function clear_tokens() {
        error_log('Zoho API Debug: Clearing stored tokens');
        return delete_option($this->token_option);
    }
}