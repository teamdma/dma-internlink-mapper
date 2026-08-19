<?php
/** Yoast SEO metadata adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_SEO_Provider_Yoast implements ILSM_SEO_Provider_Interface {
    public function id() { return 'yoast'; }

    public function focus_keyphrases( $post_id ) {
        return $this->parse_phrases( get_post_meta( absint( $post_id ), '_yoast_wpseo_focuskw', true ) );
    }

    public function is_noindex( $post_id ) {
        $value = strtolower( trim( (string) get_post_meta( absint( $post_id ), '_yoast_wpseo_meta-robots-noindex', true ) ) );
        return in_array( $value, array( '1', 'noindex' ), true );
    }

    public function canonical_url( $post_id ) {
        return esc_url_raw( (string) get_post_meta( absint( $post_id ), '_yoast_wpseo_canonical', true ) );
    }

    private function parse_phrases( $raw ) {
        if ( ! is_scalar( $raw ) ) { return array(); }
        $values = array();
        foreach ( preg_split( '/[,\n]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $value ) {
            $value = sanitize_text_field( html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( '' !== $value && ILSM_Text::length( $value ) <= 190 ) { $values[] = $value; }
        }
        return array_values( array_unique( $values ) );
    }
}
