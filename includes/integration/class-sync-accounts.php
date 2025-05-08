<?php
/**
 * Accounts module sync for Zoho CRM
 */

class Sync_Accounts {
    /**
     * Format the payload for Accounts module
     * 
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     * @return array Formatted payload for Zoho API
     */
    public function format_payload($entry, $feed) {
        GFCommon::log_debug('Sync_Accounts: Formatting payload for entry #' . $entry['id']);
        
        $payload = [];
        foreach ($feed['field_mappings'] as $gf_id => $zoho_field) {
            $value = rgar($entry, (string) $gf_id);
            $payload[$zoho_field] = $value;
            
            GFCommon::log_debug("Sync_Accounts: Mapped GF field #{$gf_id} to Zoho field '{$zoho_field}' with value: " . $value);
        }
        
        // Add any Accounts-specific field processing
        $this->process_accounts_specific_fields($payload, $entry, $feed);
        
        return $payload;
    }
    
    /**
     * Process any Accounts-specific field formatting
     * 
     * @param array $payload The payload being prepared
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     */
    private function process_accounts_specific_fields(&$payload, $entry, $feed) {
        // Example: Format account name if needed
        if (isset($payload['Account_Name'])) {
            GFCommon::log_debug("Sync_Accounts: Processing Account_Name: " . $payload['Account_Name']);
        }
        
        // Example: Set default account type if not specified
        if (!isset($payload['Account_Type'])) {
            $payload['Account_Type'] = 'Customer';
            GFCommon::log_debug("Sync_Accounts: Set default Account_Type to Customer");
        }
    }
}
