<?php
/**
 * Leads module sync for Zoho CRM
 */

class Sync_Leads {
    /**
     * Format the payload for Leads module
     * 
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     * @return array Formatted payload for Zoho API
     */
    public function format_payload($entry, $feed) {
        GFCommon::log_debug('Sync_Leads: Formatting payload for entry #' . $entry['id']);
        
        $payload = [];
        foreach ($feed['field_mappings'] as $gf_id => $zoho_field) {
            $value = rgar($entry, (string) $gf_id);
            $payload[$zoho_field] = $value;
            
            GFCommon::log_debug("Sync_Leads: Mapped GF field #{$gf_id} to Zoho field '{$zoho_field}' with value: " . $value);
        }
        
        // Add any Leads-specific field processing
        $this->process_leads_specific_fields($payload, $entry, $feed);
        
        return $payload;
    }
    
    /**
     * Process any Leads-specific field formatting
     * 
     * @param array $payload The payload being prepared
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     */
    private function process_leads_specific_fields(&$payload, $entry, $feed) {
        // Example: Format lead name if first/last are mapped separately
        if (isset($payload['First_Name']) && isset($payload['Last_Name']) && !isset($payload['Full_Name'])) {
            $payload['Full_Name'] = $payload['First_Name'] . ' ' . $payload['Last_Name'];
            GFCommon::log_debug("Sync_Leads: Created Full_Name field: " . $payload['Full_Name']);
        }
        
        // Example: Set default lead source if not specified
        if (!isset($payload['Lead_Source'])) {
            $payload['Lead_Source'] = 'Web Form';
            GFCommon::log_debug("Sync_Leads: Set default Lead_Source to Web Form");
        }
        
        // Example: Set default lead status if not specified
        if (!isset($payload['Lead_Status'])) {
            $payload['Lead_Status'] = 'New';
            GFCommon::log_debug("Sync_Leads: Set default Lead_Status to New");
        }
    }
}
