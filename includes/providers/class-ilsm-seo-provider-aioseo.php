<?php
/** AIOSEO metadata adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_SEO_Provider_AIOSEO implements ILSM_SEO_Provider_Interface {
    public function id() { return 'aioseo'; }

    public function focus_keyphrases( $post_id ) {
        $row = $this->row( $post_id );
        if ( ! $row ) { return array(); }

        $values = array();
        if ( isset( $row['focus_keyword'] ) && is_scalar( $row['focus_keyword'] ) ) {
            $values[] = (string) $row['focus_keyword'];
        }

        $additional = $this->decode_array( $row['additional_keywords'] ?? null );
        foreach ( $additional as $entry ) {
            if ( is_array( $entry ) && isset( $entry['word'] ) && is_scalar( $entry['word'] ) ) {
                $values[] = (string) $entry['word'];
            }
        }

        // AIOSEO 4.x and unmigrated 5.x rows retain the nested keyphrases JSON.
        $legacy = $this->decode_array( $row['keyphrases'] ?? null );
        if ( isset( $legacy['focus']['keyphrase'] ) && is_scalar( $legacy['focus']['keyphrase'] ) ) {
            $values[] = (string) $legacy['focus']['keyphrase'];
        }
        foreach ( (array) ( $legacy['additional'] ?? array() ) as $entry ) {
            if ( is_array( $entry ) && isset( $entry['keyphrase'] ) && is_scalar( $entry['keyphrase'] ) ) {
                $values[] = (string) $entry['keyphrase'];
            }
        }

        $values = array_map( 'sanitize_text_field', array_map( 'trim', $values ) );
        $values = array_filter(
            $values,
            static function( $value ) {
                return '' !== $value && ILSM_Text::length( $value ) <= 190;
            }
        );
        return array_values( array_unique( $values ) );
    }

    public function is_noindex( $post_id ) {
        $row = $this->row( $post_id );
        if ( ! $row ) { return false; }
        return empty( $row['robots_default'] ) && ! empty( $row['robots_noindex'] );
    }

    public function canonical_url( $post_id ) {
        $row = $this->row( $post_id );
        return $row ? esc_url_raw( (string) ( $row['canonical_url'] ?? '' ) ) : '';
    }

    /** Read one provider row only when the optional AIOSEO table exists. */
    private function row( $post_id ) {
        global $wpdb;
        static $rows = array();
        static $table_available = null;

        $post_id = absint( $post_id );
        if ( ! $post_id ) { return array(); }
        if ( isset( $rows[ $post_id ] ) ) { return $rows[ $post_id ]; }

        $table = $wpdb->prefix . 'aioseo_posts';
        if ( null === $table_available ) {
            $like = $wpdb->esc_like( $table );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only optional-provider schema discovery is cached per request.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
            $table_available = $table === $exists;
        }
        if ( ! $table_available ) { return $rows[ $post_id ] = array(); }

        // Selecting one provider row avoids constructing a dynamic identifier list.
        // Only the fixed keys consumed by this adapter are read from the result.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optional-provider metadata is read-only and cached per request.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id=%d LIMIT 1', $table, $post_id ), ARRAY_A );
        return $rows[ $post_id ] = is_array( $row ) ? $row : array();
    }

    private function decode_array( $value ) {
        if ( is_array( $value ) ) { return $value; }
        if ( is_object( $value ) ) { return json_decode( wp_json_encode( $value ), true ); }
        if ( ! is_string( $value ) || '' === trim( $value ) ) { return array(); }
        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
