<?php
/**
 * Contacts module sync for Zoho CRM
 */

class Sync_Contacts {
    /**
     * Format the payload for Contacts module
     * 
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     * @return array Formatted payload for Zoho API
     */
    public function format_payload($entry, $feed) {
        GFCommon::log_debug('Sync_Contacts: Formatting payload for entry #' . $entry['id']);
        
        $payload = [];
        foreach ($feed['field_mappings'] as $gf_id => $zoho_field) {
            $value = rgar($entry, (string) $gf_id);
            $payload[$zoho_field] = $value;
            
            GFCommon::log_debug("Sync_Contacts: Mapped GF field #{$gf_id} to Zoho field '{$zoho_field}' with value: " . $value);
        }
        
        // Add any Contacts-specific field processing
        $this->process_contacts_specific_fields($payload, $entry, $feed);
        
        return $payload;
    }
    
    /**
     * Process any Contacts-specific field formatting
     * 
     * @param array $payload The payload being prepared
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     */
    private function process_contacts_specific_fields(&$payload, $entry, $feed) {
        // Example: Format full name if first/last are mapped separately
        if (isset($payload['First_Name']) && isset($payload['Last_Name']) && !isset($payload['Full_Name'])) {
            $payload['Full_Name'] = $payload['First_Name'] . ' ' . $payload['Last_Name'];
            GFCommon::log_debug("Sync_Contacts: Created Full_Name field: " . $payload['Full_Name']);
        }
        
        // Example: Format email type if available
        if (isset($payload['Email']) && !isset($payload['Email_Type'])) {
            $payload['Email_Type'] = 'Work'; // Default to work email
            GFCommon::log_debug("Sync_Contacts: Set default Email_Type to Work");
        }
    }
}
