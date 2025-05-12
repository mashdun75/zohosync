<?php
/**
 * GF Zoho Desk Lookup Methods
 * This file contains methods for resolving Zoho Desk lookup fields
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Process Desk lookup fields before submission
 * 
 * @param array $data The data to be submitted to Zoho Desk
 * @param string $module The Desk module (tickets, contacts, etc.)
 * @return array Processed data with lookup fields resolved
 */
function process_desk_lookup_fields($data, $module) {
    $this->logger->info("Processing Desk lookup fields for module: {$module}");
    
    // Tickets module lookup handling
    if ($module === 'tickets') {
        // Handle contactId lookup
        if (!empty($data['email']) && empty($data['contactId']) && empty($data['contact'])) {
            $contactId = $this->lookup_desk_contact_by_email($data['email']);
            if ($contactId) {
                $data['contactId'] = $contactId;
                $this->logger->info("Resolved contact email '{$data['email']}' to contactId: {$contactId}");
            } else {
                // If contact not found, create contact info for auto-creation
                $data['contact'] = array(
                    'email' => $data['email'],
                    'phone' => isset($data['phone']) ? $data['phone'] : ''
                );
                $this->logger->info("Contact not found for email '{$data['email']}', will auto-create");
            }
        }
        
        // Handle departmentId lookup (by name)
        if (!empty($data['department']) && empty($data['departmentId'])) {
            $departmentId = $this->lookup_desk_department_by_name($data['department']);
            if ($departmentId) {
                $data['departmentId'] = $departmentId;
                unset($data['department']); // Remove name since we have ID
                $this->logger->info("Resolved department name '{$data['department']}' to departmentId: {$departmentId}");
            }
        }
        
        // Handle productId lookup
        if (!empty($data['product']) && empty($data['productId'])) {
            $productId = $this->lookup_desk_product_by_name($data['product']);
            if ($productId) {
                $data['productId'] = $productId;
                unset($data['product']); // Remove name since we have ID
                $this->logger->info("Resolved product name '{$data['product']}' to productId: {$productId}");
            }
        }
        
        // Handle assigneeId lookup
        if (!empty($data['assignee']) && empty($data['assigneeId'])) {
            $assigneeId = $this->lookup_desk_agent_by_name($data['assignee']);
            if ($assigneeId) {
                $data['assigneeId'] = $assigneeId;
                unset($data['assignee']); // Remove name since we have ID
                $this->logger->info("Resolved assignee name '{$data['assignee']}' to assigneeId: {$assigneeId}");
            }
        }
    } 
    // Contacts module lookup handling
    else if ($module === 'contacts') {
        // Handle accountId lookup
        if (!empty($data['account']) && empty($data['accountId'])) {
            $accountId = $this->lookup_desk_account_by_name($data['account']);
            if ($accountId) {
                $data['accountId'] = $accountId;
                unset($data['account']); // Remove name since we have ID
                $this->logger->info("Resolved account name '{$data['account']}' to accountId: {$accountId}");
            }
        }
    }
    
    return $data;
}

/**
 * Look up a Desk contact by email
 * 
 * @param string $email Email address to search for
 * @return string|null Contact ID if found, null otherwise
 */
function lookup_desk_contact_by_email($email) {
    $this->logger->info("Looking up Desk contact by email: {$email}");
    
    if (empty($email)) {
        return null;
    }
    
    $region = $this->get_region();
    $url = "https://desk.zoho.{$region}/api/v1/contacts/search?email=" . urlencode($email) . "&orgId={$this->organization_id}";
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
            'Content-Type' => 'application/json'
        ),
        'timeout' => 15
    ));
    
    if (is_wp_error($response)) {
        $this->logger->error("Error looking up contact: " . $response->get_error_message());
        return null;
    }
    
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($status !== 200 || empty($data)) {
        $this->logger->error("Failed to lookup contact: " . ($status !== 200 ? "HTTP {$status}" : "Empty response"));
        return null;
    }
    
    // Check if we got a match
    if (!empty($data['data'])) {
        $contactId = $data['data'][0]['id'];
        $this->logger->info("Found contact ID: {$contactId}");
        return $contactId;
    }
    
    $this->logger->info("No contact found with email: {$email}");
    return null;
}

/**
 * Look up a Desk department by name
 * 
 * @param string $name Department name to search for
 * @return string|null Department ID if found, null otherwise
 */
function lookup_desk_department_by_name($name) {
    $this->logger->info("Looking up Desk department by name: {$name}");
    
    if (empty($name)) {
        return null;
    }
    
    // Get all departments
    $departments = $this->get_departments();
    
    // Look for a case-insensitive match
    foreach ($departments as $id => $dept_name) {
        if (strtolower($dept_name) === strtolower($name)) {
            $this->logger->info("Found department ID: {$id}");
            return $id;
        }
    }
    
    $this->logger->info("No department found with name: {$name}");
    return null;
}

/**
 * Look up a Desk product by name
 * 
 * @param string $name Product name to search for
 * @return string|null Product ID if found, null otherwise
 */
function lookup_desk_product_by_name($name) {
    $this->logger->info("Looking up Desk product by name: {$name}");
    
    if (empty($name)) {
        return null;
    }
    
    $region = $this->get_region();
    $url = "https://desk.zoho.{$region}/api/v1/products?orgId={$this->organization_id}";
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
            'Content-Type' => 'application/json'
        ),
        'timeout' => 15
    ));
    
    if (is_wp_error($response)) {
        $this->logger->error("Error looking up products: " . $response->get_error_message());
        return null;
    }
    
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($status !== 200 || empty($data)) {
        $this->logger->error("Failed to lookup products: " . ($status !== 200 ? "HTTP {$status}" : "Empty response"));
        return null;
    }
    
    // Look for a match
    if (!empty($data['data'])) {
        foreach ($data['data'] as $product) {
            if (isset($product['productName']) && strtolower($product['productName']) === strtolower($name)) {
                $productId = $product['id'];
                $this->logger->info("Found product ID: {$productId}");
                return $productId;
            }
        }
    }
    
    $this->logger->info("No product found with name: {$name}");
    return null;
}

/**
 * Look up a Desk agent by name
 * 
 * @param string $name Agent name to search for
 * @return string|null Agent ID if found, null otherwise
 */
function lookup_desk_agent_by_name($name) {
    $this->logger->info("Looking up Desk agent by name: {$name}");
    
    if (empty($name)) {
        return null;
    }
    
    $region = $this->get_region();
    $url = "https://desk.zoho.{$region}/api/v1/agents?orgId={$this->organization_id}";
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
            'Content-Type' => 'application/json'
        ),
        'timeout' => 15
    ));
    
    if (is_wp_error($response)) {
        $this->logger->error("Error looking up agents: " . $response->get_error_message());
        return null;
    }
    
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($status !== 200 || empty($data)) {
        $this->logger->error("Failed to lookup agents: " . ($status !== 200 ? "HTTP {$status}" : "Empty response"));
        return null;
    }
    
    // Look for a match
    if (!empty($data['data'])) {
        foreach ($data['data'] as $agent) {
            $fullName = '';
            if (isset($agent['firstName'])) {
                $fullName = $agent['firstName'];
                if (isset($agent['lastName'])) {
                    $fullName .= ' ' . $agent['lastName'];
                }
            }
            
            if ($fullName && strtolower($fullName) === strtolower($name)) {
                $agentId = $agent['id'];
                $this->logger->info("Found agent ID: {$agentId}");
                return $agentId;
            }
        }
    }
    
    $this->logger->info("No agent found with name: {$name}");
    return null;
}

/**
 * Look up a Desk account by name
 * 
 * @param string $name Account name to search for
 * @return string|null Account ID if found, null otherwise
 */
function lookup_desk_account_by_name($name) {
    $this->logger->info("Looking up Desk account by name: {$name}");
    
    if (empty($name)) {
        return null;
    }
    
    $region = $this->get_region();
    $url = "https://desk.zoho.{$region}/api/v1/accounts/search?accountName=" . urlencode($name) . "&orgId={$this->organization_id}";
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => "Zoho-oauthtoken " . $this->get_desk_token(),
            'Content-Type' => 'application/json'
        ),
        'timeout' => 15
    ));
    
    if (is_wp_error($response)) {
        $this->logger->error("Error looking up account: " . $response->get_error_message());
        return null;
    }
    
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($status !== 200 || empty($data)) {
        $this->logger->error("Failed to lookup account: " . ($status !== 200 ? "HTTP {$status}" : "Empty response"));
        return null;
    }
    
    // Check if we got a match
    if (!empty($data['data'])) {
        $accountId = $data['data'][0]['id'];
        $this->logger->info("Found account ID: {$accountId}");
        return $accountId;
    }
    
    $this->logger->info("No account found with name: {$name}");
    return null;
}