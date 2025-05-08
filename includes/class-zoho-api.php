<?php
/**
 * Zoho API Class
 * Handles all interactions with the Zoho API
 */

class Zoho_API {
    private $client_id;
    private $client_secret;
    private $token_option = 'gf_zoho_tokens';
    private $api_domain;

    /**
     * Constructor
     */
    public function __construct() {
        $this->client_id = defined('ZOHO_CLIENT_ID') ? ZOHO_CLIENT_ID : get_option('gf_zoho_client_id', 'YOUR_CLIENT_ID');
        $this->client_secret = defined('ZOHO_CLIENT_SECRET') ? ZOHO_CLIENT_SECRET : get_option('gf_zoho_client_secret', 'YOUR_CLIENT_SECRET');
        $this->api_domain = get_option('gf_zoho_api_domain', 'www.zohoapis.com');
        
        GFCommon::log_debug('Zoho_API: Initializing with domain: ' . $this->api_domain);
    }

    /**
     * Get stored tokens
     */
    private function get_tokens() {
        $tokens = get_option($this->token_option);
        if (!$tokens) {
            GFCommon::log_debug('Zoho_API: No tokens found in options');
            return false;
        }
        return $tokens;
    }

    /**
     * Get current access token
     */
    public function get_access_token() {
        $tokens = $this->get_tokens();
        if (!$tokens) {
            GFCommon::log_error('Zoho_API: No access token available');
            return false;
        }
        
        GFCommon::log_debug('Zoho_API: Retrieved access token');
        return $tokens['access_token'];
    }

    /**
     * Refresh the access token
     */
    public function refresh_token() {
        GFCommon::log_debug('Zoho_API: Refreshing access token');
        
        $tokens = $this->get_tokens();
        if (!$tokens || empty($tokens['refresh_token'])) {
            GFCommon::log_error('Zoho_API: No refresh token available');
            return false;
        }

        $response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', [
            'body' => [
                'refresh_token' => $tokens['refresh_token'],
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token',
            ]
        ]);

        if (is_wp_error($response)) {
            GFCommon::log_error('Zoho_API: Refresh token error: ' . $response->get_error_message());
            return false;
        }

        $new_tokens = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($new_tokens['access_token'])) {
            // Preserve the refresh token if not returned
            if (empty($new_tokens['refresh_token'])) {
                $new_tokens['refresh_token'] = $tokens['refresh_token'];
            }
            
            update_option($this->token_option, $new_tokens);
            GFCommon::log_debug('Zoho_API: Token refreshed successfully');
            return $new_tokens['access_token'];
        }
        
        GFCommon::log_error('Zoho_API: Refresh failed: ' . wp_remote_retrieve_body($response));
        return false;
    }

    /**
     * Make an API request to Zoho
     * 
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint
     * @param array|null $body Request body
     * @param string $api_type CRM or Desk
     * @return array|false Response or false on failure
     */
    public function request($method, $endpoint, $body = null, $api_type = 'CRM') {
        $token = $this->get_access_token();
        if (!$token) {
            GFCommon::log_error('Zoho_API: No access token available for request');
            return false;
        }

        // Determine API URL based on type
        $base_url = $api_type === 'Desk' 
            ? "https://desk.{$this->api_domain}/api/v1/"
            : "https://{$this->api_domain}/crm/v2/";
            
        $url = $base_url . $endpoint;
        
        GFCommon::log_debug("Zoho_API: Making {$method} request to {$url}");
        
        $args = [
            'headers' => [
                'Authorization' => "Zoho-oauthtoken {$token}",
                'Content-Type' => 'application/json'
            ],
            'method' => $method
        ];

        if ($body) {
            // Format body properly for Zoho API
            if (strpos($endpoint, 'search') !== false) {
                $args['body'] = wp_json_encode($body);
            } else {
                $args['body'] = wp_json_encode(['data' => [$body]]);
            }
            GFCommon::log_debug('Zoho_API: Request body: ' . $args['body']);
        }
        
        $response = wp_remote_request($url, $args);
        
        // Handle authorization error by refreshing token
        if (wp_remote_retrieve_response_code($response) === 401) {
            GFCommon::log_debug('Zoho_API: Received 401 response, refreshing token and retrying');
            $token = $this->refresh_token();
            
            if ($token) {
                $args['headers']['Authorization'] = "Zoho-oauthtoken {$token}";
                $response = wp_remote_request($url, $args);
            } else {
                GFCommon::log_error('Zoho_API: Token refresh failed, cannot retry request');
                return false;
            }
        }

        if (is_wp_error($response)) {
            GFCommon::log_error('Zoho_API: Request error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);
        
        GFCommon::log_debug("Zoho_API: Response status: {$status_code}");
        
        // Log errors for debugging
        if (!empty($body['error'])) {
            GFCommon::log_error('Zoho_API: API error: ' . wp_json_encode($body['error']));
        }
        
        return $body;
    }

    /**
     * Search for records in a module
     * 
     * @param string $module Module name
     * @param string $field Field to search
     * @param string $value Value to search for
     * @param string $api_type CRM or Desk
     * @return array|false Found records or false
     */
    public function search_records($module, $field, $value, $api_type = 'CRM') {
        GFCommon::log_debug("Zoho_API: Searching for {$module} records with {$field} = {$value}");
        
        // Different search format for CRM vs Desk
        if ($api_type === 'CRM') {
            $criteria = "(${field}:equals:${value})";
            GFCommon::log_debug("Zoho_API: Using CRM search criteria: {$criteria}");
            
            $response = $this->request('GET', "${module}/search?criteria=" . urlencode($criteria), null, $api_type);
            
            if (!empty($response['data'])) {
                GFCommon::log_debug("Zoho_API: Found " . count($response['data']) . " records");
                return $response['data'];
            }
        } else {
            $endpoint = "search?module=${module}&limit=1&${field}=${value}";
            GFCommon::log_debug("Zoho_API: Using Desk search endpoint: {$endpoint}");
            
            $response = $this->request('GET', $endpoint, null, $api_type);
            
            if (!empty($response['data'])) {
                GFCommon::log_debug("Zoho_API: Found " . count($response['data']) . " records");
                return $response['data'];
            }
        }
        
        GFCommon::log_debug("Zoho_API: No records found");
        return false;
    }

    /**
     * Upload an attachment to a Zoho record
     * 
     * @param string $module Module name
     * @param string $id Record ID
     * @param string $path File path
     * @param string $api_type CRM or Desk
     * @return array|false Response or false on failure
     */
    public function upload_attachment($module, $id, $path, $api_type = 'CRM') {
        GFCommon::log_debug("Zoho_API: Uploading attachment for {$module} record {$id} from {$path}");
        
        $token = $this->get_access_token();
        if (!$token) {
            GFCommon::log_error('Zoho_API: No access token available for file upload');
            return false;
        }

        // Different endpoints for CRM vs Desk
        $url = $api_type === 'Desk'
            ? "https://desk.{$this->api_domain}/api/v1/${module}/${id}/attachments"
            : "https://{$this->api_domain}/crm/v2/${module}/${id}/Attachments";

        $h = ['Authorization' => "Zoho-oauthtoken {$token}"];
        $b = ['file' => curl_file_create($path)];
        
        GFCommon::log_debug("Zoho_API: Uploading file to {$url}");
        
        $r = wp_remote_post($url, ['headers' => $h, 'body' => $b, 'timeout' => 60]);
        
        if (wp_remote_retrieve_response_code($r) === 401) {
            GFCommon::log_debug('Zoho_API: Received 401 response, refreshing token and retrying');
            $token = $this->refresh_token();
            $h['Authorization'] = "Zoho-oauthtoken {$token}";
            $r = wp_remote_post($url, ['headers' => $h, 'body' => $b, 'timeout' => 60]);
        }
        
        if (is_wp_error($r)) {
            GFCommon::log_error('Zoho_API: File upload error: ' . $r->get_error_message());
            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($r), true);
        
        if (!empty($result['data'])) {
            GFCommon::log_debug("Zoho_API: File uploaded successfully");
        } else {
            GFCommon::log_error("Zoho_API: File upload failed: " . wp_remote_retrieve_body($r));
        }
        
        return $result;
    }

    /**
     * Register a webhook in Zoho
     * 
     * @param array $config Webhook configuration
     * @param string $api_type CRM or Desk
     * @return array|false Response or false on failure
     */
    public function register_webhook($config, $api_type = 'CRM') {
        GFCommon::log_debug("Zoho_API: Registering webhook with config: " . wp_json_encode($config));
        return $this->request('POST', 'settings/webhooks', $config, $api_type);
    }

    /**
     * Remove a webhook from Zoho
     * 
     * @param string $id Webhook ID
     * @param string $api_type CRM or Desk
     * @return array|false Response or false on failure
     */
    public function remove_webhook($id, $api_type = 'CRM') {
        GFCommon::log_debug("Zoho_API: Removing webhook with ID: {$id}");
        return $this->request('DELETE', "settings/webhooks/$id", null, $api_type);
    }
}
