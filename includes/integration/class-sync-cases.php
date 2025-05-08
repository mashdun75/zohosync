<?php
/**
 * Cases module sync for Zoho CRM
 */

class Sync_Cases {
    /**
     * Format the payload for Cases module
     * 
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     * @return array Formatted payload for Zoho API
     */
    public function format_payload($entry, $feed) {
        GFCommon::log_debug('Sync_Cases: Formatting payload for entry #' . $entry['id']);
        
        $payload = [];
        foreach ($feed['field_mappings'] as $gf_id => $zoho_field) {
            $value = rgar($entry, (string) $gf_id);
            $payload[$zoho_field] = $value;
            
            GFCommon::log_debug("Sync_Cases: Mapped GF field #{$gf_id} to Zoho field '{$zoho_field}' with value: " . $value);
        }
        
        // Add any Cases-specific field processing
        $this->process_cases_specific_fields($payload, $entry, $feed);
        
        return $payload;
    }
    
    /**
     * Process any Cases-specific field formatting
     * 
     * @param array $payload The payload being prepared
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     */
    private function process_cases_specific_fields(&$payload, $entry, $feed) {
        // Example: Set default case status if not specified
        if (!isset($payload['Status'])) {
            $payload['Status'] = 'New';
            GFCommon::log_debug("Sync_Cases: Set default Status to New");
        }
        
        // Example: Set default case priority if not specified
        if (!isset($payload['Priority'])) {
            $payload['Priority'] = 'Medium';
            GFCommon::log_debug("Sync_Cases: Set default Priority to Medium");
        }
        
        // Example: Format case description if needed
        if (isset($payload['Description'])) {
            GFCommon::log_debug("Sync_Cases: Processing Description with length: " . strlen($payload['Description']));
        }
    }
}
