<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local content crawler and phrase index.
 *
 * Builds a deterministic on-site phrase index from titles, SEO focus phrases,
 * slugs, taxonomies, headings and visible body copy. No remote requests are made.
 */
final class ILSM_Crawler {
    const MAX_INDEXED_PHRASES = 120;

    public static function index_post( $scan_id, WP_Post $post ) {
        global $wpdb;
        $table = ILSM_Database::table( 'phrases' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $wpdb->delete( $table, array( 'scan_id' => absint( $scan_id ), 'post_id' => $post->ID ), array( '%d', '%d' ) );

        $sources = array();
        foreach ( ILSM_Local_Assistant::get_focus_keyphrases_for_crawler( $post->ID ) as $phrase ) {
            $sources[] = array( $phrase, 'focus', 120 );
        }
        $sources[] = array( html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'title', 105 );
        $sources[] = array( str_replace( array( '-', '_' ), ' ', urldecode( (string) $post->post_name ) ), 'slug', 82 );

        $taxonomy_text = array();
        foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
            $names = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) ) { $taxonomy_text = array_merge( $taxonomy_text, $names ); }
        }
        foreach ( array_unique( array_filter( array_map( 'trim', $taxonomy_text ) ) ) as $term ) {
            $sources[] = array( $term, 'taxonomy', 74 );
        }

        $headings = ILSM_Content_Extractor::extract_headings( $post );
        if ( $headings ) { $sources[] = array( $headings, 'heading', 68 ); }
        $body = ILSM_Content_Extractor::extract_searchable_text( $post );
        if ( $body ) { $sources[] = array( $body, 'body', 42 ); }

        $phrases = array();
        foreach ( $sources as $source ) {
            foreach ( self::phrases_from_text( $source[0], $source[1], $source[2] ) as $row ) {
                $key = $row['normalized'];
                if ( ! isset( $phrases[ $key ] ) || $row['priority'] > $phrases[ $key ]['priority'] ) {
                    $phrases[ $key ] = $row;
                }
            }
        }
        uasort( $phrases, static function( $a, $b ) { return $b['priority'] <=> $a['priority']; } );

        foreach ( array_slice( $phrases, 0, self::MAX_INDEXED_PHRASES, true ) as $row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom tables require direct database access.
            $wpdb->insert(
                $table,
                array(
                    'scan_id'    => absint( $scan_id ),
                    'post_id'    => $post->ID,
                    'phrase'     => ILSM_Text::substring( $row['phrase'], 0, 190 ),
                    'normalized' => ILSM_Text::substring( $row['normalized'], 0, 190 ),
                    'source'     => sanitize_key( $row['source'] ),
                    'priority'   => absint( $row['priority'] ),
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%d' )
            );
        }
    }

    /** Return crawled destination matches for phrases present in current body text. */
    public static function match_editor_text( $scan_id, $post_id, $body_text, $limit = 160 ) {
        global $wpdb;
        $normalized_phrases = self::editor_phrase_keys( $body_text );
        if ( ! $normalized_phrases ) { return array(); }

        $table = ILSM_Database::table( 'phrases' );
        $matches = array();
        foreach ( array_chunk( $normalized_phrases, 80 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $sql = "SELECT post_id,phrase,normalized,source,priority FROM {$table} WHERE scan_id=%d AND post_id<>%d AND normalized IN ({$placeholders}) ORDER BY priority DESC LIMIT 600";
            $args = array_merge( array( absint( $scan_id ), absint( $post_id ) ), $chunk );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $target_id = absint( $row['post_id'] ?? 0 );
                if ( ! $target_id ) { continue; }
                $key = $target_id . '|' . (string) $row['normalized'];
                if ( ! isset( $matches[ $key ] ) || (int) $row['priority'] > (int) $matches[ $key ]['priority'] ) {
                    $matches[ $key ] = $row;
                }
            }
        }
        uasort( $matches, static function( $a, $b ) { return (int) $b['priority'] <=> (int) $a['priority']; } );
        return array_slice( array_values( $matches ), 0, max( 1, min( 500, absint( $limit ) ) ) );
    }

    private static function editor_phrase_keys( $text ) {
        $tokens = self::tokens( $text );
        $count = count( $tokens );
        $keys = array();
        for ( $length = 5; $length >= 2; $length-- ) {
            for ( $i = 0; $i <= $count - $length; $i++ ) {
                $phrase = implode( ' ', array_slice( $tokens, $i, $length ) );
                if ( self::useful( $phrase ) ) { $keys[ $phrase ] = true; }
                if ( count( $keys ) >= 900 ) { break 2; }
            }
        }
        foreach ( $tokens as $token ) {
            if ( ILSM_Text::length( $token ) >= 7 && self::useful( $token ) ) { $keys[ $token ] = true; }
        }
        return array_slice( array_keys( $keys ), 0, 900 );
    }

    private static function phrases_from_text( $text, $source, $base_priority ) {
        $tokens = self::tokens( $text );
        $count = count( $tokens );
        if ( ! $count ) { return array(); }
        $out = array();
        $max_length = in_array( $source, array( 'focus', 'title', 'slug', 'taxonomy' ), true ) ? min( 8, $count ) : min( 4, $count );
        for ( $length = $max_length; $length >= 2; $length-- ) {
            for ( $i = 0; $i <= $count - $length; $i++ ) {
                $phrase = implode( ' ', array_slice( $tokens, $i, $length ) );
                if ( ! self::useful( $phrase ) ) { continue; }
                $out[] = array(
                    'phrase'     => $phrase,
                    'normalized' => $phrase,
                    'source'     => $source,
                    'priority'   => max( 1, (int) $base_priority + min( 12, $length * 2 ) - min( 10, $i ) ),
                );
            }
        }
        if ( in_array( $source, array( 'focus', 'title', 'slug', 'taxonomy', 'heading' ), true ) ) {
            foreach ( $tokens as $token ) {
                if ( ILSM_Text::length( $token ) >= 7 && self::useful( $token ) ) {
                    $out[] = array( 'phrase' => $token, 'normalized' => $token, 'source' => $source, 'priority' => max( 1, (int) $base_priority - 20 ) );
                }
            }
        }
        return $out;
    }

    private static function tokens( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = ILSM_Text::lower( $text );
        $text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
        $raw = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
        $stop = array_flip( array( 'the','and','for','with','from','this','that','your','you','are','was','were','have','has','had','but','not','our','their','into','about','more','will','can','all','any','its','also','than','then','when','where','what','which','who','how','why','a','an','of','to','in','on','at','by','or','as','is','be','it','we','they','he','she' ) );
        $tokens = array();
        foreach ( (array) $raw as $token ) {
            if ( isset( $stop[ $token ] ) || ILSM_Text::length( $token ) < 3 || ILSM_Text::length( $token ) > 45 || ctype_digit( $token ) ) { continue; }
            $tokens[] = $token;
            if ( count( $tokens ) >= 1600 ) { break; }
        }
        return $tokens;
    }

    private static function useful( $phrase ) {
        $phrase = trim( (string) $phrase );
        if ( '' === $phrase || ILSM_Text::length( $phrase ) < 4 || ILSM_Text::length( $phrase ) > 190 ) { return false; }
        $generic = array( 'tour','tours','trip','trips','travel','guide','blog','page','post','morocco','moroccan','desert','adventure' );
        $parts = preg_split( '/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY );
        if ( 1 === count( $parts ) && in_array( $phrase, $generic, true ) ) { return false; }
        if ( count( $parts ) >= 2 && ! array_diff( $parts, $generic ) ) { return false; }
        return true;
    }
}
