<?php
/**
 * Deterministic strategic ranking for internal-link opportunities.
 *
 * Contextual relevance is produced by ILSM_Local_Assistant and remains the
 * gatekeeper. This class adds bounded site-graph, destination-need, imported
 * Search Console, keyword-ownership and anchor-diversity evidence. No remote
 * services, AI models, embeddings or external APIs are used.
 *
 * @package Internal_Link_SEO_Mapper
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Opportunity_Strategy {
    const CONTEXTUAL_GATE = 60;

    /** @var array<int,array<int,array<string,mixed>>> */
    private static $pages = array();

    /** @var array<int,array<string,array<string,float>>> */
    private static $peer_metrics = array();

    /** @var array<int,array<int,array<string,mixed>>> */
    private static $anchor_profiles = array();

    /** @var array<int,array<string,int[]>> */
    private static $focus_competitors = array();

    /** @var array<int,array<int,array<string,mixed>>> */
    private static $search_metrics = array();

    /**
     * Prime bounded ranking evidence for one suggestion request.
     *
     * @param int   $scan_id   Completed scan ID.
     * @param int[] $post_ids  Source and candidate destination post IDs.
     * @param array $focus_map Map of post ID to focus keyphrases.
     * @return void
     */
    public static function prepare( $scan_id, $post_ids, $focus_map = array() ) {
        $scan_id  = absint( $scan_id );
        $post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
        if ( ! $scan_id || ! $post_ids ) { return; }

        self::load_peer_metrics( $scan_id );
        self::load_pages( $scan_id, $post_ids );
        self::load_anchor_profiles( $scan_id, $post_ids );

        $normalized = array();
        foreach ( (array) $focus_map as $post_id => $phrases ) {
            $post_id = absint( $post_id );
            if ( ! $post_id ) { continue; }
            foreach ( (array) $phrases as $phrase ) {
                $key = self::normalize_focus_phrase( $phrase );
                if ( '' !== $key ) { $normalized[ $key ] = true; }
            }
        }

        if ( $normalized ) {
            self::load_focus_competitors( $scan_id, array_keys( $normalized ) );
            $competitor_ids = array();
            foreach ( array_keys( $normalized ) as $key ) {
                foreach ( (array) ( self::$focus_competitors[ $scan_id ][ $key ] ?? array() ) as $post_id ) {
                    $competitor_ids[] = absint( $post_id );
                }
            }
            $competitor_ids = array_values( array_unique( array_filter( $competitor_ids ) ) );
            if ( $competitor_ids ) {
                self::load_pages( $scan_id, $competitor_ids );
                self::load_anchor_profiles( $scan_id, $competitor_ids );
                $post_ids = array_values( array_unique( array_merge( $post_ids, $competitor_ids ) ) );
            }
        }

        self::load_search_metrics( $scan_id, $post_ids );
    }

    /**
     * Add strategic evidence to an already-contextual suggestion.
     *
     * @param int      $scan_id          Completed scan ID.
     * @param WP_Post  $source           Source post.
     * @param WP_Post  $target           Destination post.
     * @param string   $anchor           Proposed natural anchor.
     * @param int      $contextual_score Contextual score from the local matcher.
     * @param string[] $source_focus     Source focus keyphrases.
     * @param string[] $target_focus     Destination focus keyphrases.
     * @return array<string,mixed>
     */
    public static function score( $scan_id, WP_Post $source, WP_Post $target, $anchor, $contextual_score, $source_focus = array(), $target_focus = array() ) {
        $scan_id          = absint( $scan_id );
        $contextual_score = max( 0, min( 100, absint( $contextual_score ) ) );
        $anchor           = sanitize_text_field( (string) $anchor );

        self::prepare(
            $scan_id,
            array( $source->ID, $target->ID ),
            array( $source->ID => $source_focus, $target->ID => $target_focus )
        );

        $source_strength = self::source_strength( $scan_id, $source->ID );
        $destination_need = self::destination_need( $scan_id, $target->ID );
        $search_opportunity = self::search_opportunity( $scan_id, $target->ID );
        $ownership = self::keyword_ownership( $scan_id, $source->ID, $target->ID, $target_focus );
        $anchor_diversity = self::anchor_diversity( $scan_id, $target->ID, $anchor );

        $strategy_score = (int) round(
            ( $source_strength['score'] * 0.25 ) +
            ( $destination_need['score'] * 0.30 ) +
            ( $search_opportunity['score'] * 0.20 ) +
            ( $ownership['score'] * 0.15 ) +
            ( $anchor_diversity['score'] * 0.10 )
        );
        $strategy_score = max( 0, min( 100, $strategy_score ) );

        $adjustments = array();
        $strategy_adjustment = (int) round( ( $strategy_score - 50 ) * 0.20 );
        if ( $contextual_score < self::CONTEXTUAL_GATE && $strategy_adjustment > 0 ) {
            $strategy_adjustment = 0;
        }
        if ( 0 !== $strategy_adjustment ) {
            $adjustments['strategy'] = $strategy_adjustment;
        }

        $ownership_adjustment = (int) ( $ownership['adjustment'] ?? 0 );
        if ( 0 !== $ownership_adjustment ) {
            $adjustments['keyword_ownership'] = $ownership_adjustment;
        }

        $focus_anchor_adjustment = self::source_focus_anchor_adjustment( $anchor, $source_focus, $target_focus );
        if ( 0 !== $focus_anchor_adjustment ) {
            $adjustments['source_focus_anchor'] = $focus_anchor_adjustment;
        }

        $anchor_adjustment = (int) ( $anchor_diversity['adjustment'] ?? 0 );
        if ( 0 !== $anchor_adjustment ) {
            $adjustments['anchor_diversity'] = $anchor_adjustment;
        }

        // Context is the gatekeeper. Strategic evidence may reduce a weak
        // contextual match, but it may never rescue one with positive bonuses.
        if ( $contextual_score < self::CONTEXTUAL_GATE ) {
            foreach ( $adjustments as $key => $value ) {
                if ( $value > 0 ) { $adjustments[ $key ] = 0; }
            }
        }

        $final_score = $contextual_score + array_sum( $adjustments );
        $final_score = max( 0, min( 100, (int) round( $final_score ) ) );

        $signals = array();
        if ( ! empty( $destination_need['signal'] ) ) { $signals[] = $destination_need['signal']; }
        if ( ! empty( $source_strength['signal'] ) ) { $signals[] = $source_strength['signal']; }
        if ( ! empty( $search_opportunity['signal'] ) ) { $signals[] = $search_opportunity['signal']; }
        if ( ! empty( $ownership['signal'] ) ) { $signals[] = $ownership['signal']; }
        if ( ! empty( $anchor_diversity['signal'] ) ) { $signals[] = $anchor_diversity['signal']; }
        if ( $focus_anchor_adjustment < 0 ) {
            $signals[] = __( 'The proposed anchor substantially overlaps the source page’s own focus keyphrase, so the strategic score was reduced.', 'dma-internlink-mapper' );
        }
        if ( $contextual_score < self::CONTEXTUAL_GATE ) {
            $signals[] = sprintf(
                /* translators: %d: contextual score required before strategic bonuses can apply. */
                __( 'Contextual relevance is below the %d-point strategic gate, so graph and Search Console signals cannot promote this suggestion.', 'dma-internlink-mapper' ),
                self::CONTEXTUAL_GATE
            );
        }

        return array(
            'score'            => $final_score,
            'contextual_score' => $contextual_score,
            'strategy_score'   => $strategy_score,
            'adjustment'       => array_sum( $adjustments ),
            'signals'          => array_values( array_unique( array_filter( $signals ) ) ),
            'details'          => array(
                'version'             => 1,
                'contextual_gate'     => self::CONTEXTUAL_GATE,
                'contextual_score'    => $contextual_score,
                'strategy_score'      => $strategy_score,
                'final_score'         => $final_score,
                'adjustments'         => $adjustments,
                'source_strength'     => $source_strength,
                'destination_need'    => $destination_need,
                'search_opportunity'  => $search_opportunity,
                'keyword_ownership'   => $ownership,
                'anchor_diversity'    => $anchor_diversity,
            ),
        );
    }

    /** Normalize focus phrases exactly enough to match the crawler phrase index. */
    public static function normalize_focus_phrase( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = ILSM_Text::lower( $text );
        $text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
        $raw  = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $raw ) { return ''; }
        $stop = array_flip( array(
            'the','and','for','with','from','this','that','your','you','are','was','were','have','has','had','but','not','our','their','into','about','more','will','can','all','any','its','also','than','then','when','where','what','which','who','how','why','a','an','of','to','in','on','at','by','or','as','is','be','it','we','they','he','she'
        ) );
        $tokens = array();
        foreach ( $raw as $token ) {
            if ( isset( $stop[ $token ] ) || ILSM_Text::length( $token ) < 3 || ILSM_Text::length( $token ) > 45 || ctype_digit( $token ) ) { continue; }
            $tokens[] = $token;
            if ( count( $tokens ) >= 8 ) { break; }
        }
        return implode( ' ', $tokens );
    }

    private static function load_peer_metrics( $scan_id ) {
        global $wpdb;
        if ( isset( self::$peer_metrics[ $scan_id ] ) ) { return; }
        self::$peer_metrics[ $scan_id ] = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh local graph evidence from plugin-owned scan tables.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT post_type,AVG(incoming_count) avg_incoming,MAX(incoming_count) max_incoming FROM %i WHERE scan_id=%d GROUP BY post_type',
                ILSM_Database::table( 'pages' ),
                absint( $scan_id )
            ),
            ARRAY_A
        );
        foreach ( (array) $rows as $row ) {
            $type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
            if ( '' === $type ) { continue; }
            self::$peer_metrics[ $scan_id ][ $type ] = array(
                'avg_incoming' => max( 0.0, (float) ( $row['avg_incoming'] ?? 0 ) ),
                'max_incoming' => max( 0.0, (float) ( $row['max_incoming'] ?? 0 ) ),
            );
        }
    }

    private static function load_pages( $scan_id, $post_ids ) {
        global $wpdb;
        if ( ! isset( self::$pages[ $scan_id ] ) ) { self::$pages[ $scan_id ] = array(); }
        $missing = array();
        foreach ( (array) $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id && ! array_key_exists( $post_id, self::$pages[ $scan_id ] ) ) { $missing[] = $post_id; }
        }
        if ( ! $missing ) { return; }

        foreach ( array_chunk( array_values( array_unique( $missing ) ), 100 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $args = array_merge( array( ILSM_Database::table( 'pages' ), absint( $scan_id ) ), $chunk );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned scan index; the bounded ID list is prepared below.
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacement list is intentionally variable-length and every value is supplied in $args.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders contains only plugin-generated %d tokens; no data is interpolated.
                    "SELECT post_id,post_type,url,incoming_count,outgoing_count,is_orphan,seo_score,seo_verified FROM %i WHERE scan_id=%d AND post_id IN ({$placeholders})",
                    $args
                ),
                ARRAY_A
            );
            foreach ( $chunk as $post_id ) { self::$pages[ $scan_id ][ absint( $post_id ) ] = array(); }
            foreach ( (array) $rows as $row ) {
                $post_id = absint( $row['post_id'] ?? 0 );
                if ( $post_id ) { self::$pages[ $scan_id ][ $post_id ] = $row; }
            }
        }
    }

    private static function load_anchor_profiles( $scan_id, $post_ids ) {
        global $wpdb;
        if ( ! isset( self::$anchor_profiles[ $scan_id ] ) ) { self::$anchor_profiles[ $scan_id ] = array(); }
        $missing = array();
        foreach ( (array) $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id && ! array_key_exists( $post_id, self::$anchor_profiles[ $scan_id ] ) ) { $missing[] = $post_id; }
        }
        if ( ! $missing ) { return; }

        foreach ( array_chunk( array_values( array_unique( $missing ) ), 80 ) as $chunk ) {
            foreach ( $chunk as $post_id ) {
                self::$anchor_profiles[ $scan_id ][ absint( $post_id ) ] = array( 'total' => 0, 'anchors' => array() );
            }
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $elementor_like = $wpdb->esc_like( 'elementor-' ) . '%';
            $args = array_merge(
                array( ILSM_Database::table( 'links' ), absint( $scan_id ) ),
                $chunk,
                array( 'text', 'content', 'elementor', $elementor_like, 'elementor-rendered' )
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned link graph; the bounded ID list and all values are prepared below.
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacement list is intentionally variable-length and every value is supplied in $args.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders contains only plugin-generated %d tokens; no data is interpolated.
                    "SELECT target_post_id,anchor_text,COUNT(*) occurrences FROM %i WHERE scan_id=%d AND target_post_id IN ({$placeholders}) AND link_type=%s AND (link_location=%s OR link_location=%s OR (link_location LIKE %s AND link_location<>%s)) AND anchor_text<>'' GROUP BY target_post_id,anchor_text",
                    $args
                ),
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $target_id = absint( $row['target_post_id'] ?? 0 );
                if ( ! $target_id || ! isset( self::$anchor_profiles[ $scan_id ][ $target_id ] ) ) { continue; }
                $count = max( 0, absint( $row['occurrences'] ?? 0 ) );
                $key   = self::normalize_anchor( (string) ( $row['anchor_text'] ?? '' ) );
                self::$anchor_profiles[ $scan_id ][ $target_id ]['total'] += $count;
                if ( '' !== $key ) {
                    self::$anchor_profiles[ $scan_id ][ $target_id ]['anchors'][ $key ] = absint( self::$anchor_profiles[ $scan_id ][ $target_id ]['anchors'][ $key ] ?? 0 ) + $count;
                }
            }
        }
    }

    private static function load_focus_competitors( $scan_id, $normalized_phrases ) {
        global $wpdb;
        if ( ! isset( self::$focus_competitors[ $scan_id ] ) ) { self::$focus_competitors[ $scan_id ] = array(); }
        $missing = array();
        foreach ( (array) $normalized_phrases as $phrase ) {
            $phrase = sanitize_text_field( (string) $phrase );
            if ( '' !== $phrase && ! array_key_exists( $phrase, self::$focus_competitors[ $scan_id ] ) ) {
                self::$focus_competitors[ $scan_id ][ $phrase ] = array();
                $missing[] = $phrase;
            }
        }
        if ( ! $missing ) { return; }

        foreach ( array_chunk( array_values( array_unique( $missing ) ), 80 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $args = array_merge( array( ILSM_Database::table( 'phrases' ), absint( $scan_id ), 'focus' ), $chunk );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned phrase index; the bounded phrase list and all values are prepared below.
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacement list is intentionally variable-length and every value is supplied in $args.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders contains only plugin-generated %s tokens; no data is interpolated.
                    "SELECT normalized,post_id FROM %i WHERE scan_id=%d AND source=%s AND normalized IN ({$placeholders}) GROUP BY normalized,post_id",
                    $args
                ),
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $key     = sanitize_text_field( (string) ( $row['normalized'] ?? '' ) );
                $post_id = absint( $row['post_id'] ?? 0 );
                if ( '' !== $key && $post_id && isset( self::$focus_competitors[ $scan_id ][ $key ] ) ) {
                    self::$focus_competitors[ $scan_id ][ $key ][] = $post_id;
                }
            }
            foreach ( $chunk as $key ) {
                self::$focus_competitors[ $scan_id ][ $key ] = array_values( array_unique( array_map( 'absint', self::$focus_competitors[ $scan_id ][ $key ] ) ) );
            }
        }
    }

    private static function load_search_metrics( $scan_id, $post_ids ) {
        if ( ! isset( self::$search_metrics[ $scan_id ] ) ) { self::$search_metrics[ $scan_id ] = array(); }
        $urls = array();
        $url_to_post = array();
        foreach ( (array) $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $post_id || array_key_exists( $post_id, self::$search_metrics[ $scan_id ] ) ) { continue; }
            $row = (array) ( self::$pages[ $scan_id ][ $post_id ] ?? array() );
            $url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
            self::$search_metrics[ $scan_id ][ $post_id ] = array();
            if ( $url ) {
                $normalized = esc_url_raw( ILSM_Link_Normalizer::normalize( $url, home_url( '/' ) ) );
                if ( $normalized ) {
                    $urls[] = $normalized;
                    $url_to_post[ $normalized ] = $post_id;
                }
            }
        }
        if ( ! $urls || ! class_exists( 'ILSM_Search_Console_Import' ) ) { return; }
        $metrics = ILSM_Search_Console_Import::metrics_for_urls( array_values( array_unique( $urls ) ) );
        foreach ( (array) $metrics as $url => $row ) {
            if ( isset( $url_to_post[ $url ] ) ) {
                self::$search_metrics[ $scan_id ][ $url_to_post[ $url ] ] = is_array( $row ) ? $row : array();
            }
        }
    }

    private static function source_strength( $scan_id, $post_id ) {
        $row = (array) ( self::$pages[ $scan_id ][ absint( $post_id ) ] ?? array() );
        if ( ! $row ) { return array( 'score' => 50, 'signal' => '' ); }
        $incoming = absint( $row['incoming_count'] ?? 0 );
        $type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
        $peer = (array) ( self::$peer_metrics[ $scan_id ][ $type ] ?? array() );
        $average = max( 0.5, (float) ( $peer['avg_incoming'] ?? 0.5 ) );
        $ratio = $incoming / $average;

        if ( ! empty( $row['is_orphan'] ) || 0 === $incoming ) { $score = 20; }
        elseif ( $ratio < 0.5 ) { $score = 40; }
        elseif ( $ratio < 1.0 ) { $score = 58; }
        elseif ( $ratio < 1.5 ) { $score = 72; }
        elseif ( $ratio < 2.0 ) { $score = 84; }
        else { $score = 95; }

        $signal = '';
        if ( $score >= 72 ) {
            $signal = sprintf(
                /* translators: 1: incoming links to source, 2: average incoming links for same post type. */
                __( 'Source graph strength: %1$d incoming links versus a %2$s peer average.', 'dma-internlink-mapper' ),
                $incoming,
                number_format_i18n( $average, 1 )
            );
        }
        return array( 'score' => $score, 'incoming' => $incoming, 'peer_average' => round( $average, 1 ), 'signal' => $signal );
    }

    private static function destination_need( $scan_id, $post_id ) {
        $row = (array) ( self::$pages[ $scan_id ][ absint( $post_id ) ] ?? array() );
        if ( ! $row ) { return array( 'score' => 50, 'signal' => '' ); }
        $incoming = absint( $row['incoming_count'] ?? 0 );
        $type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
        $peer = (array) ( self::$peer_metrics[ $scan_id ][ $type ] ?? array() );
        $average = max( 0.5, (float) ( $peer['avg_incoming'] ?? 0.5 ) );
        $ratio = $incoming / $average;

        if ( ! empty( $row['is_orphan'] ) || 0 === $incoming ) { $score = 100; }
        elseif ( $ratio <= 0.25 ) { $score = 92; }
        elseif ( $ratio <= 0.50 ) { $score = 82; }
        elseif ( $ratio <= 1.00 ) { $score = 66; }
        elseif ( $ratio <= 1.50 ) { $score = 48; }
        elseif ( $ratio <= 2.00 ) { $score = 32; }
        else { $score = 18; }

        $signal = '';
        if ( $score >= 66 ) {
            $signal = sprintf(
                /* translators: 1: incoming links to destination, 2: average incoming links for same post type. */
                __( 'Destination priority: %1$d incoming links versus a %2$s peer average, so this URL has room for stronger internal support.', 'dma-internlink-mapper' ),
                $incoming,
                number_format_i18n( $average, 1 )
            );
        } elseif ( $score <= 32 ) {
            $signal = __( 'The destination already receives strong internal-link coverage, so structural need contributes little additional priority.', 'dma-internlink-mapper' );
        }
        return array( 'score' => $score, 'incoming' => $incoming, 'peer_average' => round( $average, 1 ), 'orphan' => ! empty( $row['is_orphan'] ), 'signal' => $signal );
    }

    private static function search_opportunity( $scan_id, $post_id ) {
        $metrics = (array) ( self::$search_metrics[ $scan_id ][ absint( $post_id ) ] ?? array() );
        $impressions = absint( $metrics['impressions'] ?? 0 );
        $position = max( 0.0, (float) ( $metrics['position'] ?? 0 ) );
        if ( ! $impressions ) {
            return array( 'score' => 50, 'impressions' => 0, 'position' => 0, 'signal' => '' );
        }

        $demand = min( 100, (int) round( log10( $impressions + 1 ) * 25 ) );
        if ( $position >= 8 && $position <= 20 ) { $rank = 100; }
        elseif ( $position >= 4 && $position < 8 ) { $rank = 76; }
        elseif ( $position > 20 && $position <= 50 ) { $rank = 72; }
        elseif ( $position > 0 && $position < 4 ) { $rank = 42; }
        elseif ( $position > 50 ) { $rank = 45; }
        else { $rank = 50; }
        $score = max( 0, min( 100, (int) round( ( $demand * 0.60 ) + ( $rank * 0.40 ) ) ) );

        return array(
            'score'       => $score,
            'impressions' => $impressions,
            'clicks'      => absint( $metrics['clicks'] ?? 0 ),
            'position'    => round( $position, 1 ),
            'signal'      => sprintf(
                /* translators: 1: Search Console impressions, 2: average position. */
                __( 'Search Console opportunity: %1$s impressions at average position %2$s.', 'dma-internlink-mapper' ),
                number_format_i18n( $impressions ),
                number_format_i18n( $position, 1 )
            ),
        );
    }

    private static function keyword_ownership( $scan_id, $source_id, $target_id, $target_focus ) {
        $best_result = array( 'score' => 50, 'status' => 'none', 'adjustment' => 0, 'signal' => '', 'phrase' => '', 'owner_post_id' => 0, 'competitors' => 0 );
        foreach ( (array) $target_focus as $phrase ) {
            $key = self::normalize_focus_phrase( $phrase );
            if ( '' === $key ) { continue; }
            $competitors = array_values( array_unique( array_filter( array_map( 'absint', (array) ( self::$focus_competitors[ $scan_id ][ $key ] ?? array() ) ) ) ) );
            if ( count( $competitors ) < 2 ) { continue; }

            $scores = array();
            foreach ( $competitors as $post_id ) {
                $scores[ $post_id ] = self::ownership_evidence_score( $scan_id, $post_id, $key );
            }
            arsort( $scores, SORT_NUMERIC );
            $ids = array_keys( $scores );
            $owner_id = absint( $ids[0] ?? 0 );
            $owner_score = (float) ( $scores[ $owner_id ] ?? 0 );
            $runner_score = isset( $ids[1] ) ? (float) ( $scores[ $ids[1] ] ?? 0 ) : 0.0;
            $margin = $owner_score - $runner_score;
            $target_score = (float) ( $scores[ absint( $target_id ) ] ?? 0 );
            $status = 'ambiguous';
            $adjustment = -3;
            $factor = 40;

            if ( $owner_id === absint( $target_id ) && $margin >= 5 ) {
                $status = 'primary';
                $adjustment = 3;
                $factor = 82;
            } elseif ( $owner_id !== absint( $target_id ) && ( $owner_score - $target_score ) >= 5 ) {
                $status = 'competing';
                $adjustment = -12;
                $factor = 22;
            }

            if ( $owner_id === absint( $source_id ) && $owner_id !== absint( $target_id ) ) {
                $status = 'source_primary';
                $adjustment = -15;
                $factor = 12;
            } elseif ( $owner_id === absint( $target_id ) && in_array( absint( $source_id ), $competitors, true ) ) {
                $status = 'consolidating';
                $adjustment = 5;
                $factor = 90;
            }

            $signal = '';
            if ( 'primary' === $status || 'consolidating' === $status ) {
                $signal = sprintf(
                    /* translators: %s: focus keyphrase. */
                    __( 'Keyword ownership: this destination is the strongest deterministic target among URLs sharing “%s”.', 'dma-internlink-mapper' ),
                    sanitize_text_field( (string) $phrase )
                );
            } elseif ( 'source_primary' === $status ) {
                $signal = sprintf(
                    /* translators: %s: focus keyphrase. */
                    __( 'Cannibalization guard: the source appears to be the stronger owner of “%s”, so linking that topic to the competing destination is heavily reduced.', 'dma-internlink-mapper' ),
                    sanitize_text_field( (string) $phrase )
                );
            } elseif ( 'competing' === $status ) {
                $signal = sprintf(
                    /* translators: %s: focus keyphrase. */
                    __( 'Keyword conflict: another indexed URL has stronger ownership evidence for “%s”, so this destination is deprioritized.', 'dma-internlink-mapper' ),
                    sanitize_text_field( (string) $phrase )
                );
            } else {
                $signal = sprintf(
                    /* translators: %s: focus keyphrase. */
                    __( 'Keyword ownership is ambiguous because multiple URLs target “%s”; the recommendation receives a small caution penalty.', 'dma-internlink-mapper' ),
                    sanitize_text_field( (string) $phrase )
                );
            }

            $owner_anchor_support  = self::focus_anchor_support( $scan_id, $owner_id, $key );
            $target_anchor_support = self::focus_anchor_support( $scan_id, $target_id, $key );
            $owner_matching_anchors  = absint( $owner_anchor_support['exact'] ?? 0 ) + absint( $owner_anchor_support['close'] ?? 0 );
            $target_matching_anchors = absint( $target_anchor_support['exact'] ?? 0 ) + absint( $target_anchor_support['close'] ?? 0 );
            if ( ( $owner_matching_anchors || $target_matching_anchors ) && '' !== $signal ) {
                $signal .= ' ' . sprintf(
                    /* translators: 1: matching anchors to strongest owner, 2: matching anchors to current destination. */
                    __( 'Matching internal-anchor evidence: strongest owner %1$d, current destination %2$d.', 'dma-internlink-mapper' ),
                    $owner_matching_anchors,
                    $target_matching_anchors
                );
            }

            $candidate = array(
                'score'         => $factor,
                'status'        => $status,
                'adjustment'    => $adjustment,
                'signal'        => $signal,
                'phrase'        => sanitize_text_field( (string) $phrase ),
                'normalized'    => $key,
                'owner_post_id' => $owner_id,
                'competitors'   => count( $competitors ),
                'owner_evidence'        => round( $owner_score, 1 ),
                'target_evidence'       => round( $target_score, 1 ),
                'owner_keyword_anchors' => $owner_matching_anchors,
                'target_keyword_anchors'=> $target_matching_anchors,
            );

            if ( abs( $adjustment ) > abs( (int) $best_result['adjustment'] ) || 'none' === $best_result['status'] ) {
                $best_result = $candidate;
            }
        }
        return $best_result;
    }

    private static function ownership_evidence_score( $scan_id, $post_id, $focus_key ) {
        $post_id = absint( $post_id );
        $post = get_post( $post_id );
        $row  = (array) ( self::$pages[ $scan_id ][ $post_id ] ?? array() );
        if ( ! $post instanceof WP_Post || ! $row ) { return 0.0; }

        $score = 25.0; // Exact focus-keyphrase ownership is already established by the phrase index.
        $title = self::normalize_focus_phrase( get_the_title( $post ) );
        $slug  = self::normalize_focus_phrase( str_replace( array( '-', '_' ), ' ', urldecode( (string) $post->post_name ) ) );
        if ( $title === $focus_key ) { $score += 25; }
        elseif ( self::contains_normalized_phrase( $title, $focus_key ) ) { $score += 13; }
        if ( $slug === $focus_key ) { $score += 18; }
        elseif ( self::contains_normalized_phrase( $slug, $focus_key ) ) { $score += 9; }

        $incoming = absint( $row['incoming_count'] ?? 0 );
        $type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
        $average = max( 0.5, (float) ( self::$peer_metrics[ $scan_id ][ $type ]['avg_incoming'] ?? 0.5 ) );
        $score += min( 16, ( $incoming / $average ) * 8 );

        $metrics = (array) ( self::$search_metrics[ $scan_id ][ $post_id ] ?? array() );
        $impressions = absint( $metrics['impressions'] ?? 0 );
        $position = max( 0.0, (float) ( $metrics['position'] ?? 0 ) );
        if ( $impressions ) { $score += min( 10, log10( $impressions + 1 ) * 2.5 ); }
        if ( $position >= 4 && $position <= 20 ) { $score += 10; }
        elseif ( $position > 20 && $position <= 50 ) { $score += 6; }
        elseif ( $position > 0 && $position < 4 ) { $score += 4; }

        // Matching internal anchor evidence is deliberately only one ownership
        // signal. It helps identify the URL the site already reinforces without
        // turning exact-match anchor counts into a simplistic ranking rule.
        $anchor_support = self::focus_anchor_support( $scan_id, $post_id, $focus_key );
        $score += min( 18, ( $anchor_support['exact'] * 4 ) + ( $anchor_support['close'] * 1.5 ) );

        if ( ! empty( $row['is_orphan'] ) ) { $score -= 8; }
        return max( 0.0, min( 100.0, $score ) );
    }

    /** Count exact and close focus-keyphrase anchors already pointing at a URL. */
    private static function focus_anchor_support( $scan_id, $post_id, $focus_key ) {
        $profile = (array) ( self::$anchor_profiles[ $scan_id ][ absint( $post_id ) ] ?? array( 'total' => 0, 'anchors' => array() ) );
        $focus_key = self::normalize_focus_phrase( $focus_key );
        if ( '' === $focus_key ) { return array( 'exact' => 0, 'close' => 0, 'total' => absint( $profile['total'] ?? 0 ) ); }
        $focus_tokens = self::phrase_tokens( $focus_key );
        $exact = 0;
        $close = 0;
        foreach ( (array) ( $profile['anchors'] ?? array() ) as $anchor => $count ) {
            $count = absint( $count );
            if ( ! $count ) { continue; }
            $anchor_key = self::normalize_focus_phrase( $anchor );
            if ( '' === $anchor_key ) { continue; }
            if ( $anchor_key === $focus_key ) {
                $exact += $count;
                continue;
            }
            $anchor_tokens = self::phrase_tokens( $anchor_key );
            if ( count( $focus_tokens ) < 2 || count( $anchor_tokens ) < 2 ) { continue; }
            $shared = count( array_intersect( $focus_tokens, $anchor_tokens ) );
            $focus_coverage  = $shared / max( 1, count( $focus_tokens ) );
            $anchor_coverage = $shared / max( 1, count( $anchor_tokens ) );
            if ( $focus_coverage >= 0.75 && $anchor_coverage >= 0.60 ) { $close += $count; }
        }
        return array( 'exact' => $exact, 'close' => $close, 'total' => absint( $profile['total'] ?? 0 ) );
    }

    private static function anchor_diversity( $scan_id, $target_id, $anchor ) {
        $profile = (array) ( self::$anchor_profiles[ $scan_id ][ absint( $target_id ) ] ?? array( 'total' => 0, 'anchors' => array() ) );
        $total = absint( $profile['total'] ?? 0 );
        $key   = self::normalize_anchor( $anchor );
        $exact = '' !== $key ? absint( $profile['anchors'][ $key ] ?? 0 ) : 0;
        $similar = $exact;
        $proposed_tokens = self::phrase_tokens( $key );

        if ( count( $proposed_tokens ) >= 2 ) {
            foreach ( (array) ( $profile['anchors'] ?? array() ) as $existing => $count ) {
                if ( $existing === $key ) { continue; }
                $existing_tokens = self::phrase_tokens( $existing );
                if ( count( $existing_tokens ) < 2 ) { continue; }
                $shared = count( array_intersect( $proposed_tokens, $existing_tokens ) );
                $left   = $shared / max( 1, count( $proposed_tokens ) );
                $right  = $shared / max( 1, count( $existing_tokens ) );
                if ( $left >= 0.80 && $right >= 0.70 ) { $similar += absint( $count ); }
            }
        }

        if ( $total < 3 ) {
            return array( 'score' => 95, 'total' => $total, 'matching' => $exact, 'similar' => $similar, 'ratio' => 0, 'adjustment' => 0, 'signal' => '' );
        }

        // Judge the projected distribution after this recommendation is added.
        $projected_total   = $total + 1;
        $projected_similar = $similar + 1;
        $ratio = $projected_similar / max( 1, $projected_total );
        if ( $ratio < 0.25 ) { $score = 100; $adjustment = 0; }
        elseif ( $ratio < 0.40 ) { $score = 88; $adjustment = 0; }
        elseif ( $ratio < 0.60 ) { $score = 68; $adjustment = -3; }
        elseif ( $ratio < 0.75 ) { $score = 48; $adjustment = -6; }
        else { $score = 28; $adjustment = -10; }

        $signal = '';
        if ( $adjustment < 0 ) {
            $signal = sprintf(
                /* translators: 1: projected matching/close incoming anchors, 2: projected all contextual incoming anchors. */
                __( 'Anchor diversity guard: this wording or a close variation would account for %1$d of %2$d contextual incoming anchors after insertion.', 'dma-internlink-mapper' ),
                $projected_similar,
                $projected_total
            );
        }
        return array(
            'score'      => $score,
            'total'      => $total,
            'matching'   => $exact,
            'similar'    => $similar,
            'ratio'      => round( $ratio, 3 ),
            'adjustment' => $adjustment,
            'signal'     => $signal,
        );
    }

    private static function source_focus_anchor_adjustment( $anchor, $source_focus, $target_focus ) {
        $anchor_tokens = self::phrase_tokens( $anchor );
        if ( count( $anchor_tokens ) < 2 ) { return 0; }
        $target_keys = array();
        foreach ( (array) $target_focus as $phrase ) {
            $key = self::normalize_focus_phrase( $phrase );
            if ( '' !== $key ) { $target_keys[ $key ] = true; }
        }
        foreach ( (array) $source_focus as $phrase ) {
            $source_key = self::normalize_focus_phrase( $phrase );
            if ( '' === $source_key || isset( $target_keys[ $source_key ] ) ) { continue; }
            $source_tokens = self::phrase_tokens( $source_key );
            if ( count( $source_tokens ) < 2 ) { continue; }
            $shared = array_intersect( $anchor_tokens, $source_tokens );
            $coverage = count( $shared ) / max( 1, count( $source_tokens ) );
            if ( $coverage >= 0.80 ) { return -8; }
        }
        return 0;
    }

    private static function normalize_anchor( $anchor ) {
        $anchor = html_entity_decode( wp_strip_all_tags( (string) $anchor, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $anchor = ILSM_Text::lower( $anchor );
        $anchor = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $anchor );
        return trim( preg_replace( '/\s+/u', ' ', (string) $anchor ) );
    }

    private static function phrase_tokens( $text ) {
        $normalized = self::normalize_focus_phrase( $text );
        return '' === $normalized ? array() : preg_split( '/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY );
    }

    private static function contains_normalized_phrase( $haystack, $needle ) {
        if ( '' === $haystack || '' === $needle ) { return false; }
        return false !== ILSM_Text::position( ' ' . $haystack . ' ', ' ' . $needle . ' ' );
    }
}
