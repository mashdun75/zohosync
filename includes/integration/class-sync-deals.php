<?php
/**
 * Deals module sync for Zoho CRM
 */

class Sync_Deals {
    /**
     * Format the payload for Deals module
     * 
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     * @return array Formatted payload for Zoho API
     */
    public function format_payload($entry, $feed) {
        GFCommon::log_debug('Sync_Deals: Formatting payload for entry #' . $entry['id']);
        
        $payload = [];
        foreach ($feed['field_mappings'] as $gf_id => $zoho_field) {
            $value = rgar($entry, (string) $gf_id);
            $payload[$zoho_field] = $value;
            
            GFCommon::log_debug("Sync_Deals: Mapped GF field #{$gf_id} to Zoho field '{$zoho_field}' with value: " . $value);
        }
        
        // Add any Deals-specific field processing
        $this->process_deals_specific_fields($payload, $entry, $feed);
        
        return $payload;
    }
    
    /**
     * Process any Deals-specific field formatting
     * 
     * @param array $payload The payload being prepared
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     */
    private function process_deals_specific_fields(&$payload, $entry, $feed) {
        // Example: Format deal name if needed
        if (isset($payload['Deal_Name'])) {
            GFCommon::log_debug("Sync_Deals: Processing Deal_Name: " . $payload['Deal_Name']);
        }
        
        // Example: Set default deal stage if not specified
        if (!isset($payload['Stage'])) {
            $payload['Stage'] = 'Qualification';
            GFCommon::log_debug("Sync_Deals: Set default Stage to Qualification");
        }
        
        // Example: Format amount as number if it exists
        if (isset($payload['Amount']) && !is_numeric($payload['Amount'])) {
            $amount = preg_replace('/[^0-9.]/', '', $payload['Amount']);
            $payload['Amount'] = floatval($amount);
            GFCommon::log_debug("Sync_Deals: Formatted Amount from '{$payload['Amount']}' to {$amount}");
        }
        
        // Example: Set closing date if not specified
        if (!isset($payload['Closing_Date'])) {
            $payload['Closing_Date'] = date('Y-m-d', strtotime('+30 days'));
            GFCommon::log_debug("Sync_Deals: Set default Closing_Date to " . $payload['Closing_Date']);
        }
    }
}
