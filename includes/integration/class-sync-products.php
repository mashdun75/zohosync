<?php
class Sync_Products {
    public function format_payload( $entry, $feed ) {
        $payload = [];
        foreach ( $feed['field_mappings'] as $gf_id => $zoho_field ) {
            $payload[ $zoho_field ] = rgar( $entry, (string) $gf_id );
        }
        return $payload;
    }
}