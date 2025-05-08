<?php
/**
 * Tickets module sync for Zoho Desk
 */

class Sync_Tickets {
    /**
     * Format the payload for Tickets module
     * 
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     * @return array Formatted payload for Zoho API
     */
    public function format_payload($entry, $feed) {
        GFCommon::log_debug('Sync_Tickets: Formatting payload for entry #' . $entry['id']);
        
        $payload = [];
        foreach ($feed['field_mappings'] as $gf_id => $zoho_field) {
            $value = rgar($entry, (string) $gf_id);
            $payload[$zoho_field] = $value;
            
            GFCommon::log_debug("Sync_Tickets: Mapped GF field #{$gf_id} to Zoho field '{$zoho_field}' with value: " . $value);
        }
        
        // Add any Tickets-specific field processing
        $this->process_tickets_specific_fields($payload, $entry, $feed);
        
        return $payload;
    }
    
    /**
     * Process any Tickets-specific field formatting
     * 
     * @param array $payload The payload being prepared
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     */
    private function process_tickets_specific_fields(&$payload, $entry, $feed) {
        // Example: Set default ticket status if not specified
        if (!isset($payload['status'])) {
            $payload['status'] = 'Open';
            GFCommon::log_debug("Sync_Tickets: Set default status to Open");
        }
        
        // Example: Set default priority if not specified
        if (!isset($payload['priority'])) {
            $payload['priority'] = 'Medium';
            GFCommon::log_debug("Sync_Tickets: Set default priority to Medium");
        }
        
        // Example: Set default classification if not specified
        if (!isset($payload['classification'])) {
            $payload['classification'] = 'Problem';
            GFCommon::log_debug("Sync_Tickets: Set default classification to Problem");
        }
        
        // Example: Format subject if needed
        if (isset($payload['subject']) && strlen($payload['subject']) > 255) {
            $payload['subject'] = substr($payload['subject'], 0, 252) . '...';
            GFCommon::log_debug("Sync_Tickets: Trimmed subject to fit 255 character limit");
        }
        
        // Example: Ensure departmentId is set (required by Zoho Desk)
        if (!isset($payload['departmentId'])) {
            // This should ideally come from settings
            $departmentId = get_option('gf_zoho_default_department_id');
            if ($departmentId) {
                $payload['departmentId'] = $departmentId;
                GFCommon::log_debug("Sync_Tickets: Set departmentId from settings: " . $departmentId);
            } else {
                GFCommon::log_error("Sync_Tickets: No departmentId set in payload or settings");
            }
        }
    }
}
