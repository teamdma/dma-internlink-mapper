<?php
/** Rank Math metadata adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_SEO_Provider_Rank_Math implements ILSM_SEO_Provider_Interface {
    public function id() { return 'rank_math'; }

    public function focus_keyphrases( $post_id ) {
        $raw = get_post_meta( absint( $post_id ), 'rank_math_focus_keyword', true );
        if ( ! is_scalar( $raw ) ) { return array(); }
        $values = array();
        foreach ( preg_split( '/[,\n]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $value ) {
            $value = sanitize_text_field( html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( '' !== $value && ILSM_Text::length( $value ) <= 190 ) { $values[] = $value; }
        }
        return array_values( array_unique( $values ) );
    }

    public function is_noindex( $post_id ) {
        $robots = get_post_meta( absint( $post_id ), 'rank_math_robots', true );
        $robots = is_array( $robots ) ? $robots : preg_split( '/[,\s]+/', strtolower( (string) $robots ) );
        return in_array( 'noindex', array_filter( (array) $robots ), true );
    }

    public function canonical_url( $post_id ) {
        return esc_url_raw( (string) get_post_meta( absint( $post_id ), 'rank_math_canonical_url', true ) );
    }
}
