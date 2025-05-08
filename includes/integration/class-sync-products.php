<?php
/**
 * Products module sync for Zoho CRM
 */

class Sync_Products {
    /**
     * Format the payload for Products module
     * 
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     * @return array Formatted payload for Zoho API
     */
    public function format_payload($entry, $feed) {
        GFCommon::log_debug('Sync_Products: Formatting payload for entry #' . $entry['id']);
        
        $payload = [];
        foreach ($feed['field_mappings'] as $gf_id => $zoho_field) {
            $value = rgar($entry, (string) $gf_id);
            $payload[$zoho_field] = $value;
            
            GFCommon::log_debug("Sync_Products: Mapped GF field #{$gf_id} to Zoho field '{$zoho_field}' with value: " . $value);
        }
        
        // Add any Products-specific field processing
        $this->process_products_specific_fields($payload, $entry, $feed);
        
        return $payload;
    }
    
    /**
     * Process any Products-specific field formatting
     * 
     * @param array $payload The payload being prepared
     * @param array $entry The Gravity Forms entry
     * @param array $feed The feed configuration
     */
    private function process_products_specific_fields(&$payload, $entry, $feed) {
        // Example: Format product name if needed
        if (isset($payload['Product_Name'])) {
            GFCommon::log_debug("Sync_Products: Processing Product_Name: " . $payload['Product_Name']);
        }
        
        // Example: Format pricing fields if they exist
        if (isset($payload['Unit_Price']) && !is_numeric($payload['Unit_Price'])) {
            $price = preg_replace('/[^0-9.]/', '', $payload['Unit_Price']);
            $payload['Unit_Price'] = floatval($price);
            GFCommon::log_debug("Sync_Products: Formatted Unit_Price from '{$payload['Unit_Price']}' to {$price}");
        }
        
        // Example: Set default product active status if not specified
        if (!isset($payload['Product_Active'])) {
            $payload['Product_Active'] = true;
            GFCommon::log_debug("Sync_Products: Set default Product_Active to true");
        }
        
        // Example: Format SKU if needed
        if (isset($payload['Product_Code'])) {
            $payload['Product_Code'] = strtoupper($payload['Product_Code']);
            GFCommon::log_debug("Sync_Products: Formatted Product_Code to uppercase: " . $payload['Product_Code']);
        }
    }
}
