<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Link_Opportunities {
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_action( 'wp_ajax_ilsm_generate_opportunities', array( $this, 'generate' ) );
        add_action( 'wp_ajax_ilsm_opportunity_status', array( $this, 'status' ) );
        add_action( 'wp_ajax_ilsm_find_target_links', array( $this, 'find_target_links' ) );
        add_action( 'wp_ajax_ilsm_opportunity_preview', array( $this, 'preview' ) );
        add_action( 'wp_ajax_ilsm_opportunity_bulk_preview', array( $this, 'bulk_preview' ) );
        add_action( 'wp_ajax_ilsm_opportunity_insert', array( $this, 'insert' ) );
        add_action( 'wp_ajax_ilsm_opportunity_insert_fresh', array( $this, 'insert_fresh' ) );
        add_action( 'wp_ajax_ilsm_opportunity_undo', array( $this, 'undo' ) );
        add_action( 'wp_ajax_ilsm_enable_live_insertion', array( $this, 'enable_live_insertion' ) );
        add_action( 'wp_ajax_ilsm_insertion_history', array( $this, 'ajax_insertion_history' ) );
    }

    public static function table() {
        return ILSM_Database::table( 'opportunities' );
    }

    /**
     * Return the single configured confidence threshold used by generation,
     * search, preview and insertion.
     */
    private static function minimum_confidence() {
        $settings = wp_parse_args(
            get_option( 'ilsm_settings', array() ),
            array( 'insert_min_confidence' => 70 )
        );

        return max( 60, min( 100, absint( $settings['insert_min_confidence'] ) ) );
    }

    /** Remove non-actionable Ready rows that are below the current threshold. */
    private static function purge_below_confidence_opportunities( $scan_id, $minimum = null ) {
        global $wpdb;

        $scan_id = absint( $scan_id );
        $minimum = null === $minimum ? self::minimum_confidence() : max( 60, min( 100, absint( $minimum ) ) );
        if ( ! $scan_id ) {
            return;
        }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
                'DELETE FROM ' . self::table() . " WHERE scan_id=%d AND status='new' AND score<%d",
                $scan_id,
                $minimum
            )
        );
    }

    private function guard( $capability = 'ilsm_view_reports' ) {
        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_admin', 'nonce' );
    }


    public function preview() {
        $this->insert_guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $result = ( new ILSM_Link_Inserter() )->preview( absint( wp_unslash( $_POST['id'] ?? 0 ) ) );
        $this->send_result( $result );
    }

    public function bulk_preview() {
        $this->insert_guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification is handled by the called authenticated action or surrounding request guard. Input is validated and normalized immediately before use.
        $raw_ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
        $ids = array_slice( $ids, 0, 20 );
        if ( ! $ids ) {
            wp_send_json_error( array( 'message' => __( 'No valid opportunities were selected.', 'dma-internlink-mapper' ) ), 400 );
        }
        $inserter = new ILSM_Link_Inserter();
        $items = array();
        $failures = array();
        foreach ( $ids as $id ) {
            $result = $inserter->preview( $id );
            if ( is_wp_error( $result ) ) {
                $code = $result->get_error_code();
                if ( 'anchor_missing' === $code ) {
                    $this->record_attempt_failure( $id, 'opportunity_outdated', $result->get_error_message() );
                    $this->mark_opportunity_outdated( $id );
                    $failures[] = array(
                        'id'         => $id,
                        'message'    => __( 'This opportunity is outdated because no safe occurrence of the suggested anchor exists in the current source content. It was removed from the Ready list. Refresh opportunities for this source page to find a new valid anchor.', 'dma-internlink-mapper' ),
                        'code'       => 'opportunity_outdated',
                        'status'     => 'skipped',
                        'remove_row' => true,
                    );
                } else {
                    $this->record_attempt_failure( $id, $code, $result->get_error_message() );
                    $failures[] = array( 'id' => $id, 'message' => $result->get_error_message(), 'code' => $code, 'status' => 'failed' );
                }
            } else {
                $items[] = $result;
            }
        }
        wp_send_json_success( array( 'items' => $items, 'failures' => $failures, 'checked' => count( $ids ) ) );
    }

    public function insert() {
        $this->insert_guard();
        $settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_dry_run' => 1 ) );
        $dry_run = ! empty( $settings['insert_dry_run'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $snapshot_token = sanitize_text_field( wp_unslash( $_POST['snapshot_token'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $result = ( new ILSM_Link_Inserter() )->insert( absint( wp_unslash( $_POST['id'] ?? 0 ) ), $dry_run, $snapshot_token );
        $this->send_result( $result );
    }

    /**
     * Re-preview one opportunity immediately before inserting it.
     *
     * Bulk runs can contain several opportunities for the same source post. A
     * successful earlier insertion intentionally changes that source, making
     * snapshots created at the beginning of the run stale. Refreshing the
     * snapshot here preserves the content-integrity check without rejecting
     * changes made by this plugin during the same bulk run.
     */
    public function insert_fresh() {
        $this->insert_guard();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by insert_guard().
        $opportunity_id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        if ( ! $opportunity_id ) {
            wp_send_json_error( array( 'message' => __( 'No valid opportunity was supplied.', 'dma-internlink-mapper' ), 'code' => 'invalid_opportunity' ), 400 );
        }

        $settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_dry_run' => 1 ) );
        $dry_run  = ! empty( $settings['insert_dry_run'] );
        $inserter = new ILSM_Link_Inserter();
        $preview  = $inserter->preview( $opportunity_id );

        if ( is_wp_error( $preview ) ) {
            if ( 'anchor_missing' === $preview->get_error_code() ) {
                $this->record_attempt_failure( $opportunity_id, 'opportunity_outdated', $preview->get_error_message() );
                $this->mark_opportunity_outdated( $opportunity_id );
                wp_send_json_success( array(
                    'status'     => 'skipped',
                    'code'       => 'opportunity_outdated',
                    'remove_row' => true,
                    'message'    => __( 'This opportunity became outdated because the suggested anchor no longer has a safe occurrence in the current source content. It was removed from the Ready list. Refresh opportunities for this source page to find a new valid anchor.', 'dma-internlink-mapper' ),
                ) );
            }
            $this->record_attempt_failure( $opportunity_id, $preview->get_error_code(), $preview->get_error_message() );
            $this->send_result( $preview );
        }

        if ( empty( $preview['insertable'] ) || empty( $preview['snapshot_token'] ) ) {
            $message = ! empty( $preview['reason_message'] )
                ? (string) $preview['reason_message']
                : __( 'This opportunity is no longer safe to insert.', 'dma-internlink-mapper' );
            $code = ! empty( $preview['reason_code'] ) ? sanitize_key( $preview['reason_code'] ) : 'not_insertable';
            $this->record_attempt_failure( $opportunity_id, $code, $message );
            $this->send_result( new WP_Error( $code, $message ) );
        }

        $result = $inserter->insert( $opportunity_id, $dry_run, (string) $preview['snapshot_token'] );
        $this->send_result( $result );
    }

    /**
     * Remove a stale opportunity from the actionable Ready queue.
     *
     * The record is retained for auditability, but it cannot be selected again
     * until opportunity generation discovers a fresh, technically valid anchor.
     */
    private function mark_opportunity_outdated( $opportunity_id ) {
        global $wpdb;

        $opportunity_id = absint( $opportunity_id );
        if ( ! $opportunity_id ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable table must be updated immediately after live validation.
        $wpdb->update(
            self::table(),
            array(
                'status'     => 'outdated',
                'updated_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $opportunity_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /** Store a real failed processing attempt and remove it from the Ready queue. */
    private function record_attempt_failure( $opportunity_id, $code, $message ) {
        global $wpdb;
        $opportunity_id = absint( $opportunity_id );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable opportunity state must be read fresh.
        $opportunity = $opportunity_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table(), $opportunity_id ) ) : null;
        if ( ! $opportunity ) { return; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Store the audit result in the plugin-owned insertion table.
        $wpdb->insert(
            ILSM_Database::table( 'insertions' ),
            array(
                'scan_id'          => absint( $opportunity->scan_id ),
                'opportunity_id'   => $opportunity_id,
                'source_post_id'   => absint( $opportunity->source_post_id ),
                'target_post_id'   => absint( $opportunity->target_post_id ),
                'user_id'          => get_current_user_id(),
                'anchor_text'      => sanitize_text_field( $opportunity->anchor_text ),
                'destination_url'  => esc_url_raw( get_permalink( $opportunity->target_post_id ) ),
                'editor_type'      => 'unknown',
                'content_location' => '',
                'location_hash'    => '',
                'before_hash'      => '',
                'after_hash'       => '',
                'revision_id'      => 0,
                'insertion_status' => 'failed',
                'error_code'       => sanitize_key( $code ),
                'error_message'    => sanitize_text_field( $message ),
                'created_at'       => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keep the mutable plugin-owned Ready queue synchronized with the persisted audit result.
        $wpdb->update( self::table(), array( 'status' => 'failed', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $opportunity_id ), array( '%s', '%s' ), array( '%d' ) );
    }

    public function undo() {
        $this->insert_guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $result = ( new ILSM_Link_Inserter() )->undo( absint( wp_unslash( $_POST['history_id'] ?? 0 ) ) );
        $this->send_result( $result );
    }

    /**
     * Explicitly switch from Preview Mode to Live Mode for administrators.
     * This is only called after a browser confirmation from the opportunities screen.
     */
    public function enable_live_insertion() {
        $this->insert_guard();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Only an administrator can enable live content insertion.', 'dma-internlink-mapper' ) ), 403 );
        }

        $settings = (array) get_option( 'ilsm_settings', array() );
        $settings['insert_dry_run'] = 0;
        $settings['insert_auto_enabled'] = 1;
        update_option( 'ilsm_settings', $settings, false );

        wp_send_json_success( array(
            'message' => __( 'Live insertion is enabled. Selected links will now be written to content.', 'dma-internlink-mapper' ),
            'dry_run' => false,
        ) );
    }

    private function insert_guard() {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_insert_links' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_insert_links', 'insert_nonce' );
    }

    private function send_result( $result ) {
        if ( is_wp_error( $result ) ) {
            $data = array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() );
            $extra = $result->get_error_data();
            if ( is_array( $extra ) ) { $data = array_merge( $data, $extra ); }
            wp_send_json_error( $data, 400 );
        }
        wp_send_json_success( $result );
    }

    /**
     * Determine whether a source already links to a destination.
     * Uses both the latest crawl graph and the current editable content so
     * stale scan data cannot create a false opportunity.
     */
    private static function source_already_links_to_target( $scan_id, $source_id, $target_id, $post = null ) {
        global $wpdb;

        $scan_id   = absint( $scan_id );
        $source_id = absint( $source_id );
        $target_id = absint( $target_id );
        if ( ! $scan_id || ! $source_id || ! $target_id || $source_id === $target_id ) {
            return true;
        }

        $links = ILSM_Database::table( 'links' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $found = $wpdb->get_var( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
            "SELECT 1 FROM {$links} WHERE scan_id=%d AND source_post_id=%d AND target_post_id=%d LIMIT 1",
            $scan_id,
            $source_id,
            $target_id
        ) );
        if ( $found ) {
            return true;
        }

        $post = $post instanceof WP_Post ? $post : get_post( $source_id );
        if ( ! $post ) {
            return false;
        }

        $target_url = ILSM_Link_Normalizer::normalize( get_permalink( $target_id ) );
        if ( '' === $target_url ) {
            return false;
        }

        $html = (string) $post->post_content;
        $elementor = get_post_meta( $source_id, '_elementor_data', true );
        if ( is_string( $elementor ) && '' !== $elementor ) {
            $html .= ' ' . $elementor;
        }

        if ( preg_match_all( '~<a\\b[^>]*\\bhref\\s*=\\s*(["\\\'])(.*?)\\1~is', $html, $matches ) ) {
            $base = get_permalink( $source_id );
            foreach ( $matches[2] as $href ) {
                if ( $target_url === ILSM_Link_Normalizer::normalize( $href, $base ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Remove stale ready opportunities which are already linked now. */
    private static function purge_already_linked_opportunities( $scan_id ) {
        global $wpdb;
        $scan_id = absint( $scan_id );
        if ( ! $scan_id ) {
            return;
        }
        $opportunities = self::table();
        $links = ILSM_Database::table( 'links' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $wpdb->query( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
            "DELETE o FROM {$opportunities} o INNER JOIN {$links} l ON l.scan_id=o.scan_id AND l.source_post_id=o.source_post_id AND l.target_post_id=o.target_post_id WHERE o.scan_id=%d AND o.status IN ('new','failed')",
            $scan_id
        ) );
    }

    /** Return the current user's most recent generation summary. */
    private static function generation_summary_key() {
        return 'ilsm_opportunity_summary_' . get_current_user_id();
    }

    public function generate() {
        $this->guard( 'ilsm_insert_links' );
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan ) {
            wp_send_json_error( array( 'message' => __( 'Run a completed scan first.', 'dma-internlink-mapper' ) ), 409 );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $offset = max( 0, absint( wp_unslash( $_POST['offset'] ?? 0 ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $batch  = max( 1, min( 20, absint( wp_unslash( $_POST['batch'] ?? 6 ) ) ) );
        $pages  = ILSM_Database::table( 'pages' );
        $table  = self::table();
        $seen_pairs = array();
        $minimum_confidence = self::minimum_confidence();
        $rejected = array( 'already_linked' => 0, 'below_confidence' => 0, 'unsupported' => 0, 'source_too_short' => 0, 'link_budget_exhausted' => 0, 'link_too_close' => 0, 'excluded_page' => 0, 'no_safe_anchor' => 0, 'uninsertable' => 0 );
        $checked = 0;
        $inserter = new ILSM_Link_Inserter();
        if ( 0 === $offset ) {
            // Keep successful audit-state rows; rebuild every non-inserted candidate.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id=%d AND status<>'inserted'", $scan ) );
            update_option( 'ilsm_opportunity_engine_version', '8', false );
            delete_transient( self::generation_summary_key() );
        }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pages} WHERE scan_id=%d", $scan ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $rows  = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$pages} WHERE scan_id=%d ORDER BY post_id LIMIT %d OFFSET %d", $scan, $batch, $offset ) );
        $created = 0;
        foreach ( (array) $rows as $source_id ) {
            $post = get_post( absint( $source_id ) );
            if ( ! $post || is_wp_error( ILSM_Opportunity_Eligibility::validate( $post, 'source' ) ) ) { $rejected['excluded_page']++; continue; }
            $body = ILSM_Content_Extractor::extract_insertable_text( $post );
            if ( '' === trim( (string) $body ) ) { $rejected['no_safe_anchor']++; continue; }
            $items = ILSM_Local_Assistant::instance()->get_suggestions( $scan, $post, 6, $body, array(), array() );
            foreach ( (array) $items as $suggestion ) {
                $checked++;
                $target_id = absint( $suggestion['post_id'] ?? 0 );
                $anchor    = sanitize_text_field( (string) ( $suggestion['anchors'][0] ?? '' ) );
                if ( ! $target_id || '' === $anchor || $target_id === absint( $source_id ) ) { continue; }
                if ( is_wp_error( ILSM_Opportunity_Eligibility::validate( $target_id, 'destination' ) ) ) { $rejected['excluded_page']++; continue; }
                $pair_key = absint( $source_id ) . '|' . $target_id;
                if ( isset( $seen_pairs[ $pair_key ] ) ) { continue; }
                if ( self::source_already_links_to_target( $scan, $source_id, $target_id, $post ) ) {
                    $rejected['already_linked']++;
                    continue;
                }

                $score = max( 0, min( 100, absint( $suggestion['score'] ?? 0 ) ) );
                if ( $score < $minimum_confidence ) {
                    $rejected['below_confidence']++;
                    continue;
                }

                // Store only opportunities that meet the current confidence threshold
                // and that the exact insertion engine can locate in a supported Classic,
                // Gutenberg, or Elementor text location.
                $preflight = $inserter->validate_candidate( $source_id, $target_id, $anchor );
                if ( is_wp_error( $preflight ) ) {
                    $code = $preflight->get_error_code();
                    if ( 'already_linked' === $code ) {
                        $rejected['already_linked']++;
                    } elseif ( 'anchor_missing' === $code ) {
                        $rejected['no_safe_anchor']++;
                    } elseif ( in_array( $code, array( 'unsupported_elementor', 'dom_extension_missing', 'unsafe_location' ), true ) ) {
                        $rejected['unsupported']++;
                    } elseif ( isset( $rejected[ $code ] ) ) {
                        $rejected[ $code ]++;
                    } else {
                        $rejected['uninsertable']++;
                    }
                    continue;
                }

                $seen_pairs[ $pair_key ] = true;
                $reason  = sanitize_textarea_field( (string) ( $suggestion['reason'] ?? '' ) );
                $context = self::excerpt( $body, $anchor );
                $key     = hash( 'sha256', $scan . '|' . $source_id . '|' . $target_id . '|' . ILSM_Text::lower( $anchor ) );
                $now     = current_time( 'mysql', true );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
                $ok = $wpdb->query( $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
                    "INSERT INTO {$table}(scan_id,opportunity_key,source_post_id,target_post_id,anchor_text,context_excerpt,score,reason,status,created_at,updated_at) VALUES(%d,%s,%d,%d,%s,%s,%d,%s,'new',%s,%s) ON DUPLICATE KEY UPDATE score=VALUES(score),reason=VALUES(reason),context_excerpt=VALUES(context_excerpt),updated_at=VALUES(updated_at)",
                    $scan, $key, $source_id, $target_id, ILSM_Text::substring( $anchor, 0, 190 ), ILSM_Text::substring( $context, 0, 700 ), $score, ILSM_Text::substring( $reason, 0, 500 ), $now, $now
                ) );
                if ( 1 === (int) $ok ) { $created++; }
            }
        }
        $next = $offset + count( $rows );
        $done = count( $rows ) < $batch || $next >= $total;

        $summary = get_transient( self::generation_summary_key() );
        if ( ! is_array( $summary ) || 0 === $offset ) {
            $summary = array(
                'checked' => 0,
                'created' => 0,
                'rejected' => array_fill_keys( array_keys( $rejected ), 0 ),
                'minimum_confidence' => $minimum_confidence,
                'completed_at' => '',
            );
        }
        $summary['checked'] += $checked;
        $summary['created'] += $created;
        foreach ( $rejected as $reason => $count ) {
            $summary['rejected'][ $reason ] = absint( $summary['rejected'][ $reason ] ?? 0 ) + absint( $count );
        }
        $summary['minimum_confidence'] = $minimum_confidence;
        if ( $done ) {
            $summary['completed_at'] = current_time( 'mysql', true );
        }
        set_transient( self::generation_summary_key(), $summary, DAY_IN_SECONDS );

        wp_send_json_success( array(
            'offset' => $next,
            'total' => $total,
            'created' => $created,
            'checked' => $checked,
            'summary' => $summary,
            'done' => $done,
            'percent' => $total ? min( 100, (int) round( $next / $total * 100 ) ) : 100,
            'rejected' => $rejected,
        ) );
    }

    public function status() {
        $this->guard( 'ilsm_insert_links' );
        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
        if ( ! $id || ! in_array( $status, array( 'new', 'reviewed', 'ignored', 'inserted', 'failed', 'undone' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid opportunity status.', 'dma-internlink-mapper' ) ), 400 );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $result = $wpdb->update( self::table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
        false === $result ? wp_send_json_error( array(), 500 ) : wp_send_json_success();
    }

    public function find_target_links() {
        $this->guard();
        global $wpdb;

        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan ) {
            wp_send_json_error( array( 'message' => __( 'Run a completed scan first.', 'dma-internlink-mapper' ) ), 409 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $target_ref = sanitize_text_field( wp_unslash( $_POST['target_ref'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $target_id  = absint( wp_unslash( $_POST['target_id'] ?? 0 ) );
        if ( '' === $target_ref && $target_id ) {
            $target_ref = 'post:' . $target_id;
        }

        $target_kind  = 'post';
        $target_title = '';
        $target_url   = '';
        $target_slug  = '';
        $target_post  = null;
        $target_term  = null;

        if ( 0 === strpos( $target_ref, 'term:' ) ) {
            $parts = explode( ':', $target_ref, 3 );
            $taxonomy = sanitize_key( $parts[1] ?? '' );
            $term_id  = absint( $parts[2] ?? 0 );
            $taxonomy_object = taxonomy_exists( $taxonomy ) ? get_taxonomy( $taxonomy ) : null;
            $target_term = $taxonomy_object && $taxonomy_object->public ? get_term( $term_id, $taxonomy ) : null;
            if ( ! $target_term || is_wp_error( $target_term ) ) {
                wp_send_json_error( array( 'message' => __( 'Choose a valid public taxonomy destination.', 'dma-internlink-mapper' ) ), 400 );
            }
            $term_link = get_term_link( $target_term );
            if ( is_wp_error( $term_link ) ) {
                wp_send_json_error( array( 'message' => __( 'The selected taxonomy destination has no valid public URL.', 'dma-internlink-mapper' ) ), 400 );
            }
            $target_kind  = 'term';
            $target_title = $target_term->name;
            $target_url   = $term_link;
            $target_slug  = $target_term->slug;
        } else {
            if ( 0 === strpos( $target_ref, 'post:' ) ) {
                $target_id = absint( substr( $target_ref, 5 ) );
            }
            $target_post = get_post( $target_id );
            if ( ! $target_post || is_wp_error( ILSM_Opportunity_Eligibility::validate( $target_post, 'destination' ) ) ) {
                wp_send_json_error( array( 'message' => __( 'Choose a valid published destination.', 'dma-internlink-mapper' ) ), 400 );
            }
            if ( self::is_utility_destination( $target_post ) ) {
                wp_send_json_error( array( 'message' => __( 'Cart, checkout, account, login and thank-you pages cannot be used as link destinations.', 'dma-internlink-mapper' ) ), 400 );
            }
            $target_title = get_the_title( $target_post );
            $target_url   = get_permalink( $target_post );
            $target_slug  = $target_post->post_name;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
        if ( '' === $keyword && $target_post ) {
            $keyword = self::focus_keyword( $target_post->ID );
        }
        if ( '' === $keyword ) {
            $keyword = $target_title;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $offset         = max( 0, absint( wp_unslash( $_POST['offset'] ?? 0 ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $batch          = max( 5, min( 50, absint( wp_unslash( $_POST['batch'] ?? 20 ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $include_linked = ! empty( $_POST['include_linked'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
        $source_scope   = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? '' ) );
        $enabled_types  = self::enabled_post_types();
        $include_terms  = in_array( $source_scope, array( 'taxonomy', 'all_content' ), true );
        $post_type      = in_array( $source_scope, array( 'taxonomy', 'all_content' ), true ) ? '' : sanitize_key( $source_scope );

        if ( $target_post && ! in_array( $target_post->post_type, $enabled_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'The selected destination post type is disabled in Settings.', 'dma-internlink-mapper' ) ), 400 );
        }
        if ( $post_type && ! in_array( $post_type, $enabled_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'The selected source post type is disabled in Settings.', 'dma-internlink-mapper' ) ), 400 );
        }

        $pages = ILSM_Database::table( 'pages' );
        $links = ILSM_Database::table( 'links' );
        $where = 'scan_id=%d';
        $args  = array( $scan );
        if ( $target_post ) {
            $where .= ' AND post_id<>%d';
            $args[] = $target_post->ID;
        }
        if ( $post_type ) {
            $where .= ' AND post_type=%s';
            $args[] = $post_type;
        } elseif ( 'taxonomy' === $source_scope ) {
            $where .= ' AND 1=0';
        } else {
            $where .= ' AND post_type IN (' . implode( ',', array_fill( 0, count( $enabled_types ), '%s' ) ) . ')';
            $args = array_merge( $args, $enabled_types );
        }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated. The placeholder list is completed by the validated argument array.
        $post_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pages} WHERE {$where}", $args ) );
        $query_args = array_merge( $args, array( $batch, $offset ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated. The dynamic placeholder list matches the validated argument array.
        $source_ids = $post_total ? $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$pages} WHERE {$where} ORDER BY post_id LIMIT %d OFFSET %d", $query_args ) ) : array();
        $results = array();
        $inserter = new ILSM_Link_Inserter();
        $minimum_confidence = self::minimum_confidence();

        foreach ( (array) $source_ids as $source_id ) {
            $source_id = absint( $source_id );
            if ( ! $source_id ) { continue; }
            $already_linked = false;
            if ( $target_post ) {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
                $already_linked = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$links} WHERE scan_id=%d AND source_post_id=%d AND target_post_id=%d LIMIT 1", $scan, $source_id, $target_post->ID ) );
            } else {
                $already_linked = self::source_contains_url( get_post_field( 'post_content', $source_id ), $target_url );
            }
            if ( $already_linked && ! $include_linked ) { continue; }
            $source = get_post( $source_id );
            if ( ! $source || is_wp_error( ILSM_Opportunity_Eligibility::validate( $source, 'source' ) ) || self::is_utility_destination( $source ) ) { continue; }
            $body = ILSM_Content_Extractor::extract_insertable_text( $source );
            if ( '' === trim( (string) $body ) ) { continue; }

            if ( $target_post ) {
                $matches = self::target_matches( $scan, $source_id, $target_post->ID, $body, $keyword, $target_post );
            } else {
                $matches = self::generic_target_matches( $body, $keyword, $target_title, $target_slug );
            }
            if ( ! $matches ) {
                $manual_candidate = self::contextual_source_candidate( $source, $body, $keyword, $target_title );
                if ( $manual_candidate ) { $matches[] = $manual_candidate; }
            }
            foreach ( $matches as $match ) {
                $manual_only = ! empty( $match['manual_only'] );
                if ( $manual_only ) {
                    $score = absint( $match['score'] ?? 0 );
                } elseif ( $target_post ) {
                    $preflight = $inserter->validate_candidate( $source_id, $target_post->ID, $match['anchor'] );
                    if ( is_wp_error( $preflight ) ) { continue; }
                    $score = self::focused_score( $match, $source, $target_post->ID, $already_linked );
                } else {
                    $score = self::generic_focused_score( $match, $source, $keyword, $already_linked );
                }
                if ( $score < $minimum_confidence ) { continue; }
                $results[] = array(
                    'source_id'       => $source_id,
                    'source_title'    => html_entity_decode( get_the_title( $source ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                    'source_type'     => $source->post_type,
                    'source_edit_url' => esc_url_raw( get_edit_post_link( $source_id, 'raw' ) ),
                    'anchor'          => $match['anchor'],
                    'context'         => $manual_only ? $match['context'] : self::excerpt( $body, $match['anchor'] ),
                    'score'           => $score,
                    'signal'          => $match['signal'],
                    'already_linked'  => $already_linked,
                    'manual_only'     => $manual_only || ( 'term' === $target_kind ),
                    'anchor_found'    => ! $manual_only,
                );
            }
        }

        if ( $include_terms && 0 === $offset ) {
            foreach ( self::searchable_taxonomies() as $taxonomy => $taxonomy_object ) {
                $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 500 ) );
                if ( is_wp_error( $terms ) ) { continue; }
                foreach ( $terms as $term ) {
                    if ( $target_term && $target_term->term_id === $term->term_id && $target_term->taxonomy === $taxonomy ) { continue; }
                    $body = trim( wp_strip_all_tags( term_description( $term->term_id ), true ) );
                    if ( '' === $body ) { continue; }
                    $already_linked = self::source_contains_url( $body, $target_url );
                    if ( $already_linked && ! $include_linked ) { continue; }
                    foreach ( self::generic_target_matches( $body, $keyword, $target_title, $target_slug ) as $match ) {
                        $score = self::generic_focused_score( $match, null, $keyword, $already_linked );
                        if ( $score < $minimum_confidence ) { continue; }
                        $results[] = array(
                            'source_id'       => 0,
                            'source_title'    => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                            'source_type'     => $taxonomy_object->labels->singular_name . ' taxonomy',
                            'source_edit_url' => esc_url_raw( get_edit_term_link( $term->term_id, $taxonomy ) ),
                            'anchor'          => $match['anchor'],
                            'context'         => self::excerpt( $body, $match['anchor'] ),
                            'score'           => $score,
                            'signal'          => $match['signal'],
                            'already_linked'  => $already_linked,
                            'manual_only'     => true,
                        );
                    }
                }
            }
        }

        usort( $results, static function( $a, $b ) { return (int) $b['score'] <=> (int) $a['score']; } );
        $deduped = array();
        foreach ( $results as $row ) {
            $key = absint( $row['source_id'] ) . '|' . $row['source_type'] . '|' . $row['source_title'];
            if ( ! isset( $deduped[ $key ] ) || $row['score'] > $deduped[ $key ]['score'] ) { $deduped[ $key ] = $row; }
        }
        $next = $offset + count( $source_ids );
        wp_send_json_success( array(
            'results'      => array_slice( array_values( $deduped ), 0, 75 ),
            'offset'       => $next,
            'total'        => $post_total,
            'done'         => count( $source_ids ) < $batch || $next >= $post_total,
            'percent'      => $post_total ? min( 100, (int) round( $next / $post_total * 100 ) ) : 100,
            'keyword'      => $keyword,
            'target_title' => html_entity_decode( $target_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            'target_url'   => esc_url_raw( $target_url ),
            'target_kind'  => $target_kind,
        ) );
    }

    private static function generic_target_matches( $body, $keyword, $title, $slug ) {
        $matches = array();
        $candidates = array_merge( array( $keyword, $title, str_replace( '-', ' ', $slug ) ), self::keyword_variants( $keyword ) );
        foreach ( array_unique( array_filter( array_map( 'trim', $candidates ) ) ) as $candidate ) {
            $exact = self::exact_in_text( $body, $candidate );
            if ( '' !== $exact && self::safe_anchor( $exact ) ) {
                $matches[] = array( 'anchor' => $exact, 'signal' => self::normalize( $candidate ) === self::normalize( $keyword ) ? 'keyword' : 'variant', 'priority' => 120 );
            }
        }
        $signature = self::semantic_signature( $keyword );
        if ( $signature ) {
            foreach ( self::body_phrases( $body ) as $phrase ) {
                if ( self::safe_anchor( $phrase ) && self::semantic_signature( $phrase ) === $signature ) {
                    $matches[] = array( 'anchor' => $phrase, 'signal' => 'semantic', 'priority' => 95 );
                }
            }
        }
        return $matches;
    }

    private static function generic_focused_score( $match, $source, $keyword, $already_linked ) {
        $base = array( 'keyword' => 96, 'variant' => 82, 'semantic' => 76 );
        $score = $base[ $match['signal'] ] ?? 70;
        if ( $source instanceof WP_Post ) {
            $source_focus = self::focus_keyword( $source->ID );
            if ( $source_focus && self::token_overlap( $source_focus, $keyword ) >= 2 ) { $score += 3; }
        }
        if ( $already_linked ) { $score -= 28; }
        return max( 0, min( 100, (int) round( $score ) ) );
    }

    /**
     * Rank a topically relevant source which does not yet contain a safe anchor.
     * The result is manual-only and never reaches automatic insertion.
     */
    private static function contextual_source_candidate( WP_Post $source, $body, $keyword, $target_title ) {
        $target_terms = self::meaningful_tokens( $keyword . ' ' . $target_title );
        if ( count( $target_terms ) < 2 ) { return null; }

        $body_terms    = self::meaningful_tokens( $body );
        $title_terms   = self::meaningful_tokens( get_the_title( $source ) );
        $focus_terms   = self::meaningful_tokens( self::focus_keyword( $source->ID ) );
        $body_overlap  = count( array_intersect( $target_terms, $body_terms ) );
        $title_overlap = count( array_intersect( $target_terms, $title_terms ) );
        $focus_overlap = count( array_intersect( $target_terms, $focus_terms ) );
        if ( $body_overlap < 2 && ( $body_overlap + $title_overlap + $focus_overlap ) < 3 ) { return null; }

        $score = 52 + min( 24, $body_overlap * 6 ) + min( 12, $title_overlap * 4 ) + min( 9, $focus_overlap * 3 );
        $context_term = '';
        foreach ( $target_terms as $term ) {
            if ( in_array( $term, $body_terms, true ) ) { $context_term = $term; break; }
        }

        return array(
            'anchor'       => $keyword,
            'signal'       => 'contextual_manual',
            'priority'     => 0,
            'score'        => max( 0, min( 89, (int) $score ) ),
            'context'      => self::excerpt( $body, $context_term ?: $keyword ),
            'manual_only'  => true,
            'anchor_found' => false,
        );
    }

    /** Return distinct normalized terms used by the contextual ranker. */
    private static function meaningful_tokens( $text ) {
        $stopwords = array( 'the','and','from','with','for','this','that','your','you','our','are','was','were','have','has','into','onto','about','page','post','read','more','click','here','best' );
        $tokens = preg_split( '/\s+/u', self::normalize( $text ), -1, PREG_SPLIT_NO_EMPTY );
        $out = array();
        foreach ( (array) $tokens as $token ) {
            if ( ILSM_Text::length( $token ) < 3 || in_array( $token, $stopwords, true ) ) { continue; }
            $out[] = $token;
        }
        return array_values( array_unique( $out ) );
    }

    private static function source_contains_url( $content, $url ) {
        if ( '' === trim( (string) $url ) ) { return false; }
        return false !== strpos( html_entity_decode( (string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    private static function searchable_taxonomies() {
        $objects = get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' );
        foreach ( array( 'post_format', 'product_shipping_class' ) as $excluded ) { unset( $objects[ $excluded ] ); }
        return $objects;
    }

    private static function is_utility_destination( WP_Post $post ) {
        $url  = (string) get_permalink( $post );
        $path = '/' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) . '/';
        $patterns = array( '/cart/', '/basket/', '/checkout/', '/order-received/', '/order-pay/', '/my-account/', '/customer-logout/', '/lost-password/', '/edit-account/', '/edit-address/', '/payment-methods/', '/downloads/', '/login/', '/log-in/', '/thank-you/', '/thankyou/' );
        foreach ( $patterns as $pattern ) { if ( false !== strpos( $path, $pattern ) ) { return true; } }
        if ( function_exists( 'wc_get_page_id' ) ) {
            foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page_key ) {
                if ( absint( wc_get_page_id( $page_key ) ) === (int) $post->ID ) { return true; }
            }
        }
        return false;
    }

    private static function target_matches( $scan, $source_id, $target_id, $body, $keyword, WP_Post $target ) {
        $matches = array();
        foreach ( ILSM_Crawler::match_editor_text( $scan, $source_id, $body, 500 ) as $row ) {
            if ( absint( $row['post_id'] ?? 0 ) !== $target_id ) { continue; }
            $anchor = trim( (string) ( $row['editor_phrase'] ?? $row['phrase'] ?? '' ) );
            $exact  = self::exact_in_text( $body, $anchor );
            if ( '' === $exact || ! self::safe_anchor( $exact ) ) { continue; }
            $matches[] = array( 'anchor' => $exact, 'signal' => sanitize_key( (string) ( $row['source'] ?? 'semantic' ) ), 'priority' => absint( $row['priority'] ?? 0 ) );
        }
        $candidates = array_merge( array( $keyword, get_the_title( $target ), str_replace( '-', ' ', $target->post_name ) ), self::keyword_variants( $keyword ) );
        foreach ( array_unique( array_filter( array_map( 'trim', $candidates ) ) ) as $candidate ) {
            $exact = self::exact_in_text( $body, $candidate );
            if ( '' !== $exact && self::safe_anchor( $exact ) ) {
                $matches[] = array( 'anchor' => $exact, 'signal' => self::normalize( $candidate ) === self::normalize( $keyword ) ? 'keyword' : 'variant', 'priority' => 120 );
            }
        }
        $keyword_signature = self::semantic_signature( $keyword );
        if ( $keyword_signature ) {
            foreach ( self::body_phrases( $body ) as $phrase ) {
                if ( self::safe_anchor( $phrase ) && self::semantic_signature( $phrase ) === $keyword_signature ) {
                    $matches[] = array( 'anchor' => $phrase, 'signal' => 'semantic', 'priority' => 95 );
                }
            }
        }
        return $matches;
    }

    private static function focused_score( $match, WP_Post $source, $target_id, $already_linked ) {
        $base = array( 'keyword' => 96, 'focus' => 94, 'title' => 90, 'slug' => 86, 'taxonomy' => 82, 'variant' => 80, 'semantic' => 76, 'heading' => 72, 'body' => 68 );
        $score = $base[ $match['signal'] ] ?? 70;
        $score += min( 4, max( 0, absint( $match['priority'] ?? 0 ) - 80 ) / 20 );
        $source_focus = self::focus_keyword( $source->ID );
        if ( $source_focus && self::token_overlap( $source_focus, get_the_title( $target_id ) ) >= 2 ) { $score += 3; }
        if ( $already_linked ) { $score -= 28; }
        return max( 0, min( 100, (int) round( $score ) ) );
    }

    public static function render() {
        global $wpdb;
        $scan  = ILSM_Database::latest_completed_scan_id();
        self::purge_already_linked_opportunities( $scan );
        self::purge_below_confidence_opportunities( $scan );
        $table = self::table();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $status = sanitize_key( wp_unslash( $_GET['status'] ?? 'new' ) );
        $status = in_array( $status, array( 'all', 'new', 'reviewed', 'ignored', 'inserted', 'failed', 'undone' ), true ) ? $status : 'new';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $query = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $source_post_id = absint( wp_unslash( $_GET['source_post_id'] ?? 0 ) );
        $configured_min = self::minimum_confidence();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $requested_min  = max( 0, min( 100, absint( wp_unslash( $_GET['min_score'] ?? $configured_min ) ) ) );
        $min            = max( $configured_min, $requested_min );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $paged = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
        $per_page = 20;
        $where = array( 'o.scan_id=%d', 'o.score>=%d' );
        $args  = array( $scan, $min );
        if ( 'all' !== $status ) { $where[] = 'o.status=%s'; $args[] = $status; }
        if ( $source_post_id ) { $where[] = 'o.source_post_id=%d'; $args[] = $source_post_id; }
        if ( '' !== $query ) {
            $like = '%' . $wpdb->esc_like( $query ) . '%';
            $where[] = '(sp.post_title LIKE %s OR tp.post_title LIKE %s OR o.anchor_text LIKE %s)';
            array_push( $args, $like, $like, $like );
        }
        $where_sql = implode( ' AND ', $where );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated. The placeholder list is completed by the validated argument array.
        $total = $scan ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} o JOIN {$wpdb->posts} sp ON sp.ID=o.source_post_id JOIN {$wpdb->posts} tp ON tp.ID=o.target_post_id WHERE {$where_sql}", $args ) ) : 0;
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $paged > $total_pages ) { $paged = $total_pages; }
        $offset = ( $paged - 1 ) * $per_page;
        $pages_table = ILSM_Database::table( 'pages' );
        $search_table = ILSM_Database::table( 'search_console_urls' );
        $sql = "SELECT o.*,sp.post_title source_title,sp.post_type source_type,tp.post_title target_title,COALESCE(srcp.outgoing_count,0) source_outgoing_count,COALESCE(tgtp.incoming_count,0) target_incoming_count,COALESCE(sct.clicks,0) search_clicks,COALESCE(sct.impressions,0) search_impressions,COALESCE(sct.position,0) search_position FROM {$table} o JOIN {$wpdb->posts} sp ON sp.ID=o.source_post_id JOIN {$wpdb->posts} tp ON tp.ID=o.target_post_id LEFT JOIN {$pages_table} srcp ON srcp.scan_id=o.scan_id AND srcp.post_id=o.source_post_id LEFT JOIN {$pages_table} tgtp ON tgtp.scan_id=o.scan_id AND tgtp.post_id=o.target_post_id LEFT JOIN {$search_table} sct ON sct.url_hash=tgtp.url_hash WHERE {$where_sql} ORDER BY o.score DESC,COALESCE(sct.impressions,0) DESC,o.id DESC LIMIT %d OFFSET %d";
        $query_args = array_merge( $args, array( $per_page, $offset ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        $rows = $scan ? $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) ) : array();
        $counts = array( 'new' => 0, 'reviewed' => 0, 'ignored' => 0, 'inserted' => 0, 'failed' => 0, 'undone' => 0, 'all' => 0 );
        if ( $scan ) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
            foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) n FROM {$table} WHERE scan_id=%d GROUP BY status", $scan ), ARRAY_A ) as $row ) {
                if ( isset( $counts[ $row['status'] ] ) ) { $counts[ $row['status'] ] = absint( $row['n'] ); }
            }
            $counts['all'] = array_sum( array_intersect_key( $counts, array_flip( array( 'new','reviewed','ignored','inserted','failed','undone' ) ) ) );
        }
        echo '<div class="ilsm-opportunities-modern">';
	        echo '<div class="ilsm-opportunity-topbar"><div class="ilsm-opportunity-tabs" role="tablist"><button type="button" class="ilsm-opportunity-tab is-active" data-opportunity-view="all"><i class="fa fa-link" aria-hidden="true"></i> ' . esc_html__( 'All Opportunities', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-opportunity-tab" data-opportunity-view="target"' . disabled( ! $scan, true, false ) . '><i class="fa fa-search" aria-hidden="true"></i> ' . esc_html__( 'Find Links to a Page', 'dma-internlink-mapper' ) . '</button></div><div class="ilsm-opportunity-top-actions"><a class="ilsm-help-link" href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings' ) ) . '"><i class="fa fa-question-circle-o" aria-hidden="true"></i> ' . esc_html__( 'How it works', 'dma-internlink-mapper' ) . '</a>' . ( $scan ? '<button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-build-opportunities"><i class="fa fa-magic" aria-hidden="true"></i> ' . esc_html__( 'Generate opportunities', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-btn ilsm-refresh-opportunities"><i class="fa fa-refresh" aria-hidden="true"></i> ' . esc_html__( 'Refresh scan data', 'dma-internlink-mapper' ) . '</button>' : '' ) . '</div></div>';
        echo '<div id="ilsm-opportunity-view-all" class="ilsm-opportunity-view is-active">';

        if ( ! $scan ) {
            echo '<section class="ilsm-panel ilsm-first-scan-empty" aria-labelledby="ilsm-first-scan-title">';
            echo '<div class="ilsm-first-scan-visual" aria-hidden="true"><span class="ilsm-first-scan-ring"><i class="fa fa-search"></i></span><span class="ilsm-first-scan-node ilsm-node-one"></span><span class="ilsm-first-scan-node ilsm-node-two"></span><span class="ilsm-first-scan-node ilsm-node-three"></span></div>';
            echo '<span class="ilsm-first-scan-eyebrow">' . esc_html__( 'Opportunity index not built yet', 'dma-internlink-mapper' ) . '</span>';
            echo '<h2 id="ilsm-first-scan-title">' . esc_html__( 'Run your first scan to discover link opportunities', 'dma-internlink-mapper' ) . '</h2>';
            echo '<p class="ilsm-first-scan-intro">' . esc_html__( 'DMA InternLink Mapper needs one completed local scan before it can compare pages, evaluate anchors and build safe internal-link suggestions. No content is changed during scanning.', 'dma-internlink-mapper' ) . '</p>';
            echo '<div class="ilsm-first-scan-steps">';
            echo '<div><span>1</span><i class="fa fa-database" aria-hidden="true"></i><strong>' . esc_html__( 'Scan public content', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Index posts, pages and enabled custom post types.', 'dma-internlink-mapper' ) . '</small></div>';
            echo '<div><span>2</span><i class="fa fa-shield" aria-hidden="true"></i><strong>' . esc_html__( 'Apply safety rules', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Check editor support, link budgets, spacing and indexability.', 'dma-internlink-mapper' ) . '</small></div>';
            echo '<div><span>3</span><i class="fa fa-lightbulb-o" aria-hidden="true"></i><strong>' . esc_html__( 'Review suggestions', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Preview every source, anchor and destination before insertion.', 'dma-internlink-mapper' ) . '</small></div>';
            echo '</div>';
            echo '<div class="ilsm-first-scan-actions"><a class="ilsm-btn ilsm-btn-primary ilsm-btn-large" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '"><i class="fa fa-search" aria-hidden="true"></i> ' . esc_html__( 'Start First Scan', 'dma-internlink-mapper' ) . '</a><a class="ilsm-btn ilsm-btn-large" href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings' ) ) . '"><i class="fa fa-sliders" aria-hidden="true"></i> ' . esc_html__( 'Review Scan Settings', 'dma-internlink-mapper' ) . '</a></div>';
            echo '<p class="ilsm-first-scan-note"><i class="fa fa-lock" aria-hidden="true"></i> ' . esc_html__( 'Scanning runs locally in WordPress and does not send site content to an external service.', 'dma-internlink-mapper' ) . '</p>';
            echo '</section></div></div>';
            return;
        }
        if ( '8' !== (string) get_option( 'ilsm_opportunity_engine_version', '' ) ) {
            echo '<div class="notice notice-warning inline ilsm-opportunity-engine-notice"><p><strong>' . esc_html__( 'Regenerate opportunities before inserting links.', 'dma-internlink-mapper' ) . '</strong> ' . esc_html__( 'Existing opportunities may reference titles, excerpts, existing links or unsupported widgets. The updated engine stores only technically insertable suggestions that meet the current minimum confidence and source-page link budget, respect minimum word distance, remove already-linked pairs, and exclude privacy, cookie, terms, legal, password-protected, and noindex pages according to Settings.', 'dma-internlink-mapper' ) . '</p></div>';
        }
        echo '<div class="ilsm-opportunity-summary">';
	        $kpi_meta = array(
	            'new'      => array( 'fa-check-circle-o', __( 'Ready opportunities', 'dma-internlink-mapper' ), __( 'Ready to insert', 'dma-internlink-mapper' ) ),
	            'inserted' => array( 'fa-link', __( 'Inserted links', 'dma-internlink-mapper' ), __( 'Already inserted', 'dma-internlink-mapper' ) ),
	            'failed'   => array( 'fa-exclamation-triangle', __( 'Needs review', 'dma-internlink-mapper' ), __( 'Check manually', 'dma-internlink-mapper' ) ),
	            'ignored'  => array( 'fa-ban', __( 'Ignored', 'dma-internlink-mapper' ), __( 'Not relevant', 'dma-internlink-mapper' ) ),
	        );
        foreach ( $kpi_meta as $key => $meta ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
            echo '<a class="ilsm-opportunity-kpi ilsm-kpi-' . esc_attr( $key ) . ' ' . ( $status === $key ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'ilsm-link-opportunities', 'status' => $key ), admin_url( 'admin.php' ) ) ) . '"><span class="ilsm-kpi-icon"><i class="fa ' . esc_attr( $meta[0] ) . '" aria-hidden="true"></i></span><span class="ilsm-kpi-copy"><span class="ilsm-kpi-label">' . esc_html( $meta[1] ) . '</span><strong>' . number_format_i18n( $counts[ $key ] ) . '</strong><small>' . esc_html( $meta[2] ) . '</small></span></a>';
        }
        $pages_table = ILSM_Database::checked_table( ILSM_Database::table( 'pages' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable scan data must be read fresh; the table identifier and scan value are both passed through prepare().
        $analysis_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id=%d', $pages_table, absint( $scan ) ) );
	        echo '</div><section class="ilsm-panel ilsm-opportunity-generator"><div class="ilsm-opportunity-generator-head"><div class="ilsm-opportunity-generator-title"><span class="ilsm-opportunity-generator-icon"><i class="fa fa-magic" aria-hidden="true"></i></span><div><span class="ilsm-opportunity-generator-kicker">' . esc_html__( 'Local analysis · Content safe', 'dma-internlink-mapper' ) . '</span><h2>' . esc_html__( 'Opportunity discovery', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Builds suggestions from the latest completed scan without modifying content.', 'dma-internlink-mapper' ) . '</p></div></div></div>';
	        /* translators: %s: localized number of pages eligible for opportunity analysis. */
	        echo '<div id="ilsm-opportunity-discovery" class="ilsm-opportunity-discovery" data-total="' . esc_attr( $analysis_total ) . '" data-stage="0" hidden><div class="ilsm-opportunity-discovery-status"><div class="ilsm-opportunity-progress-ring" aria-hidden="true"><span id="ilsm-opportunity-percent">0%</span></div><div class="ilsm-opportunity-status-copy"><span class="ilsm-opportunity-live"><i class="fa fa-circle" aria-hidden="true"></i> ' . esc_html__( 'Live analysis', 'dma-internlink-mapper' ) . '</span><strong id="ilsm-opportunity-headline">' . sprintf( esc_html__( 'Ready to analyze %s pages', 'dma-internlink-mapper' ), esc_html( number_format_i18n( $analysis_total ) ) ) . '</strong><p id="ilsm-opportunity-stage" class="ilsm-opportunity-stage">' . esc_html__( 'Preparing the first analysis batch…', 'dma-internlink-mapper' ) . '</p></div><span class="ilsm-opportunity-elapsed"><i class="fa fa-clock-o" aria-hidden="true"></i><span id="ilsm-opportunity-elapsed">' . esc_html__( 'Elapsed: 0s', 'dma-internlink-mapper' ) . '</span></span></div><div class="ilsm-opportunity-progress-track" role="progressbar" aria-label="' . esc_attr__( 'Opportunity discovery progress', 'dma-internlink-mapper' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="ilsm-opportunity-progress-fill"></span></div><ol class="ilsm-opportunity-pipeline" aria-label="' . esc_attr__( 'Analysis stages', 'dma-internlink-mapper' ) . '"><li data-opportunity-step="1"><span><i class="fa fa-file-text-o" aria-hidden="true"></i></span><div><strong>' . esc_html__( 'Read eligible pages', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Body text and editor support', 'dma-internlink-mapper' ) . '</small></div></li><li data-opportunity-step="2"><span><i class="fa fa-crosshairs" aria-hidden="true"></i></span><div><strong>' . esc_html__( 'Score relevance', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Anchors, intent and confidence', 'dma-internlink-mapper' ) . '</small></div></li><li data-opportunity-step="3"><span><i class="fa fa-shield" aria-hidden="true"></i></span><div><strong>' . esc_html__( 'Verify safe insertion', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Existing links, spacing and budgets', 'dma-internlink-mapper' ) . '</small></div></li></ol><div class="ilsm-opportunity-discovery-body"><div class="ilsm-opportunity-discovery-meta"><div class="ilsm-opportunity-metric"><span class="ilsm-opportunity-metric-icon is-candidates"><i class="fa fa-filter" aria-hidden="true"></i></span><span><strong id="ilsm-opportunity-candidates">0</strong><small>' . esc_html__( 'Candidates checked', 'dma-internlink-mapper' ) . '</small></span></div><div class="ilsm-opportunity-metric"><span class="ilsm-opportunity-metric-icon is-found"><i class="fa fa-link" aria-hidden="true"></i></span><span><strong id="ilsm-opportunity-found">0</strong><small>' . esc_html__( 'Opportunities found', 'dma-internlink-mapper' ) . '</small></span></div></div><blockquote class="ilsm-opportunity-quote"><i class="fa fa-quote-left" aria-hidden="true"></i><div><p id="ilsm-opportunity-quote-text"></p><cite id="ilsm-opportunity-quote-author"></cite></div></blockquote></div><p class="ilsm-opportunity-discovery-note"><span><i class="fa fa-lock" aria-hidden="true"></i></span><span><strong>' . esc_html__( 'Analysis only—your content stays unchanged', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Keep this page open while WordPress checks editor support, existing links, confidence, spacing and link budgets.', 'dma-internlink-mapper' ) . '</small></span></p></div><span id="ilsm-opportunity-progress" class="screen-reader-text" aria-live="polite"></span></section>';
        echo '<form method="get" class="ilsm-opportunity-filters"><input type="hidden" name="page" value="ilsm-link-opportunities"><label><span>' . esc_html__( 'Search', 'dma-internlink-mapper' ) . '</span><input type="search" name="s" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Source, anchor or destination', 'dma-internlink-mapper' ) . '"></label><label><span>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '</span><select name="status">';
        foreach ( array( 'all' => __( 'All', 'dma-internlink-mapper' ), 'new' => __( 'Ready to insert', 'dma-internlink-mapper' ), 'inserted' => __( 'Inserted', 'dma-internlink-mapper' ), 'failed' => __( 'Failed', 'dma-internlink-mapper' ), 'reviewed' => __( 'Reviewed', 'dma-internlink-mapper' ), 'ignored' => __( 'Ignored', 'dma-internlink-mapper' ), 'undone' => __( 'Undone', 'dma-internlink-mapper' ) ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        /* translators: %d: configured minimum confidence percentage required for insertion. */
	        echo '</select></label><label class="ilsm-confidence-filter"><span>' . esc_html__( 'Minimum confidence', 'dma-internlink-mapper' ) . '</span><span class="ilsm-confidence-control"><input type="range" name="min_score" min="' . esc_attr( $configured_min ) . '" max="100" value="' . esc_attr( $min ) . '"><output>' . esc_html( $min ) . '%</output></span><small>' . sprintf( esc_html__( 'Configured insertion minimum: %d%%', 'dma-internlink-mapper' ), absint( $configured_min ) ) . '</small></label><button class="ilsm-btn ilsm-btn-primary">' . esc_html__( 'Apply filters', 'dma-internlink-mapper' ) . '</button></form>';
        $insert_settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_dry_run' => 1 ) );
        $mode_label = ! empty( $insert_settings['insert_dry_run'] ) ? __( 'Preview Mode: no content changes', 'dma-internlink-mapper' ) : __( 'Live Mode: links are written to content', 'dma-internlink-mapper' );
        echo '<div class="ilsm-bulk-bar"><label><input type="checkbox" id="ilsm-select-all-opportunities"> ' . esc_html__( 'Select current page', 'dma-internlink-mapper' ) . '</label><button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-insert-selected">' . esc_html__( 'Insert selected links', 'dma-internlink-mapper' ) . '</button><span class="ilsm-mode-badge ' . ( ! empty( $insert_settings['insert_dry_run'] ) ? 'is-dry-run' : 'is-live' ) . '">' . esc_html( $mode_label ) . '</span><span id="ilsm-bulk-progress" aria-live="polite"></span></div><section class="ilsm-panel ilsm-table-panel"><div class="ilsm-table-scroll"><table class="ilsm-table"><thead><tr><th><span class="screen-reader-text">' . esc_html__( 'Select', 'dma-internlink-mapper' ) . '</span></th><th>' . esc_html__( 'Source page', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Anchor found', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Destination', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Confidence', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Internal links', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Review', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        foreach ( (array) $rows as $row ) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
            $history_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ILSM_Database::table( 'insertions' ) . ' WHERE opportunity_id=%d AND insertion_status=\'inserted\' ORDER BY id DESC LIMIT 1', $row->id ) );
            $eligible = 'new' === $row->status && (int) $row->score >= $min;
	            /* translators: 1: Search Console clicks, 2: impressions, 3: average position. */
	            $search_evidence = absint( $row->search_impressions ) ? '<small class="ilsm-row-url">' . sprintf( esc_html__( 'Search Console: %1$s clicks · %2$s impressions · position %3$s', 'dma-internlink-mapper' ), esc_html( number_format_i18n( absint( $row->search_clicks ) ) ), esc_html( number_format_i18n( absint( $row->search_impressions ) ) ), esc_html( number_format_i18n( (float) $row->search_position, 1 ) ) ) . '</small>' : '';
	            echo '<tr data-opportunity-id="' . absint( $row->id ) . '" data-history-id="' . absint( $history_id ) . '"><td><input class="ilsm-opportunity-check" type="checkbox" ' . disabled( ! $eligible, true, false ) . ' aria-label="' . esc_attr__( 'Select opportunity', 'dma-internlink-mapper' ) . '"></td><td><strong>' . esc_html( html_entity_decode( $row->source_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '</strong><small class="ilsm-row-url">' . esc_html( $row->source_type ) . '</small><p class="ilsm-opportunity-context">' . esc_html( $row->context_excerpt ) . '</p></td><td><span class="ilsm-anchor-chip">' . esc_html( $row->anchor_text ) . '</span></td><td><strong>' . esc_html( html_entity_decode( $row->target_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '</strong>' . wp_kses_post( $search_evidence ) . '</td><td><span class="ilsm-confidence">' . absint( $row->score ) . '%</span></td><td><div class="ilsm-link-counts" aria-label="' . esc_attr__( 'Internal link counts', 'dma-internlink-mapper' ) . '"><span class="ilsm-link-count ilsm-link-count-out" title="' . esc_attr__( 'Outgoing links from source', 'dma-internlink-mapper' ) . '"><i class="fa fa-arrow-up" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( 'Outgoing from source:', 'dma-internlink-mapper' ) . ' </span><b class="ilsm-outgoing-count">' . absint( $row->source_outgoing_count ) . '</b></span><span class="ilsm-link-count ilsm-link-count-in" title="' . esc_attr__( 'Incoming links to destination', 'dma-internlink-mapper' ) . '"><i class="fa fa-arrow-down" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( 'Incoming to destination:', 'dma-internlink-mapper' ) . ' </span><b class="ilsm-incoming-count">' . absint( $row->target_incoming_count ) . '</b></span><span class="screen-reader-text ilsm-insertion-state">' . esc_html( ucfirst( $row->status ) ) . '</span></div></td><td><div class="ilsm-row-actions"><button type="button" class="ilsm-btn ilsm-btn-small ilsm-preview-opportunity" data-opportunity-id="' . absint( $row->id ) . '" aria-label="' . esc_attr__( 'Preview insertion location', 'dma-internlink-mapper' ) . '" title="' . esc_attr__( 'Preview insertion location', 'dma-internlink-mapper' ) . '"><span class="screen-reader-text">' . esc_html__( 'Preview insertion location', 'dma-internlink-mapper' ) . '</span></button>' . ( $eligible ? '<button type="button" class="ilsm-btn ilsm-btn-small ilsm-btn-primary ilsm-insert-opportunity">' . esc_html__( 'Insert link', 'dma-internlink-mapper' ) . '</button>' : '' ) . ( $history_id ? '<button type="button" class="ilsm-btn ilsm-btn-small ilsm-undo-opportunity">' . esc_html__( 'Undo', 'dma-internlink-mapper' ) . '</button>' : '' ) . '<a class="ilsm-btn ilsm-btn-small" href="' . esc_url( get_edit_post_link( $row->source_post_id ) ) . '">' . esc_html__( 'Open source', 'dma-internlink-mapper' ) . '</a><button type="button" class="ilsm-btn ilsm-btn-small ilsm-opportunity-status" data-status="reviewed">' . esc_html__( 'Reviewed', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-btn ilsm-btn-small ilsm-btn-ignore ilsm-opportunity-status" data-status="ignored">' . esc_html__( 'Ignore', 'dma-internlink-mapper' ) . '</button></div></td></tr>';
        }
        if ( ! $rows ) {
            $summary = get_transient( self::generation_summary_key() );
            if ( is_array( $summary ) && ! empty( $summary['completed_at'] ) ) {
                $reasons = array(
                    'below_confidence'     => __( 'Below confidence threshold', 'dma-internlink-mapper' ),
                    'already_linked'       => __( 'Already linked', 'dma-internlink-mapper' ),
                    'link_budget_exhausted'=> __( 'Source link budget full', 'dma-internlink-mapper' ),
                    'source_too_short'     => __( 'Source content too short', 'dma-internlink-mapper' ),
                    'link_too_close'       => __( 'Anchor too close to another link', 'dma-internlink-mapper' ),
                    'unsupported'          => __( 'Unsupported editor location', 'dma-internlink-mapper' ),
                    'excluded_page'        => __( 'Excluded or noindex page', 'dma-internlink-mapper' ),
                    'no_safe_anchor'       => __( 'No safe body-text anchor', 'dma-internlink-mapper' ),
                    'uninsertable'         => __( 'Other safety checks', 'dma-internlink-mapper' ),
                );
                echo '<tr><td colspan="7"><div class="ilsm-opportunity-empty">';
                echo '<span class="ilsm-empty-icon"><i class="fa fa-search" aria-hidden="true"></i></span>';
	                echo '<h3>' . esc_html__( 'No opportunities passed the current rules', 'dma-internlink-mapper' ) . '</h3>';
                echo '<p>' . esc_html__( 'The analysis completed successfully, but no candidates met all current confidence, editor-safety, indexability, word-count, link-budget and spacing rules.', 'dma-internlink-mapper' ) . '</p>';
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
                echo '<div class="ilsm-empty-summary"><div><strong>' . number_format_i18n( absint( $summary['checked'] ?? 0 ) ) . '</strong><span>' . esc_html__( 'Candidates checked', 'dma-internlink-mapper' ) . '</span></div><div><strong>0</strong><span>' . esc_html__( 'Ready opportunities', 'dma-internlink-mapper' ) . '</span></div><div><strong>' . absint( $summary['minimum_confidence'] ?? 0 ) . '%</strong><span>' . esc_html__( 'Minimum confidence', 'dma-internlink-mapper' ) . '</span></div></div>';
	                $rejected_counts  = array_map( 'absint', (array) ( $summary['rejected'] ?? array() ) );
	                $maximum_rejected = max( array_merge( array( 1 ), $rejected_counts ) );
	                echo '<div class="ilsm-reject-grid" aria-label="' . esc_attr__( 'Reasons candidates were rejected', 'dma-internlink-mapper' ) . '">';
	                foreach ( $reasons as $key => $label ) {
	                    $count = absint( $summary['rejected'][ $key ] ?? 0 );
	                    $percentage = $maximum_rejected ? min( 100, max( 4, (int) round( $count / $maximum_rejected * 100 ) ) ) : 0;
	                    if ( $count ) { echo '<div><span>' . esc_html( $label ) . '</span><span class="ilsm-reject-bar" aria-hidden="true"><i style="width:' . esc_attr( $percentage ) . '%"></i></span><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong></div>'; }
	                }
                echo '</div>';
                echo '<details class="ilsm-confidence-help"><summary>' . esc_html__( 'How confidence is calculated', 'dma-internlink-mapper' ) . '</summary><p>' . esc_html__( 'Confidence is calculated locally from semantic term overlap, exact crawler phrase matches, anchor quality and priority, destination focus-keyphrase overlap, meaningful shared terms, indexed keyword relevance, and prior accepted or ignored feedback. The final score is capped at 100. Technical eligibility checks such as noindex status, duplicate links, editor support, word count, link budget and link spacing are hard requirements and are not bypassed by a high confidence score.', 'dma-internlink-mapper' ) . '</p></details>';
                echo '<div class="ilsm-empty-actions"><a class="ilsm-btn ilsm-btn-primary" href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings#ilsm-safe-link-insertion' ) ) . '">' . esc_html__( 'Review insertion settings', 'dma-internlink-mapper' ) . '</a><button type="button" class="ilsm-btn" id="ilsm-empty-regenerate">' . esc_html__( 'Generate again', 'dma-internlink-mapper' ) . '</button><a class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '">' . esc_html__( 'Run a fresh scan', 'dma-internlink-mapper' ) . '</a></div>';
                echo '</div></td></tr>';
            } else {
                echo '<tr><td colspan="7" class="ilsm-empty-cell">' . esc_html__( 'Generate opportunities after a completed scan.', 'dma-internlink-mapper' ) . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
        if ( $total_pages > 1 ) {
            $pagination = paginate_links( array(
                'base' => add_query_arg( array( 'page' => 'ilsm-link-opportunities', 'status' => $status, 's' => $query, 'min_score' => $min, 'source_post_id' => $source_post_id, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
                'format' => '',
                'current' => $paged,
                'total' => $total_pages,
                'mid_size' => 2,
                'end_size' => 1,
                'prev_text' => '<i class="fa fa-angle-left" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( 'Previous', 'dma-internlink-mapper' ) . '</span>',
                'next_text' => '<span class="screen-reader-text">' . esc_html__( 'Next', 'dma-internlink-mapper' ) . '</span><i class="fa fa-angle-right" aria-hidden="true"></i>',
                'type' => 'array',
            ) );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
            if ( $pagination ) { echo '<nav class="ilsm-pagination" aria-label="Link opportunities pagination">' . implode( '', array_map( static function( $link ) { return '<span class="ilsm-page-item">' . wp_kses_post( $link ) . '</span>'; }, $pagination ) ) . '</nav>'; }
        }
        echo '</section>';
        $history = self::get_history_page( $scan, 'all', 1, 25 );
        echo '<section class="ilsm-panel ilsm-table-panel ilsm-insertion-history-panel"><div class="ilsm-panel-head"><div><h2>' . esc_html__( 'Insertion history', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Live links and failed attempts are separated so the Ready list stays actionable.', 'dma-internlink-mapper' ) . '</p></div><div class="ilsm-history-tabs" role="tablist" aria-label="' . esc_attr__( 'Insertion history filters', 'dma-internlink-mapper' ) . '"><button type="button" class="ilsm-history-tab is-active" data-history-status="all" role="tab" aria-selected="true">' . esc_html__( 'All', 'dma-internlink-mapper' ) . ' <span>' . absint( $history['counts']['all'] ) . '</span></button><button type="button" class="ilsm-history-tab" data-history-status="live" role="tab" aria-selected="false">' . esc_html__( 'Live', 'dma-internlink-mapper' ) . ' <span>' . absint( $history['counts']['live'] ) . '</span></button><button type="button" class="ilsm-history-tab" data-history-status="errors" role="tab" aria-selected="false">' . esc_html__( 'Errors', 'dma-internlink-mapper' ) . ' <span>' . absint( $history['counts']['errors'] ) . '</span></button></div></div><div class="ilsm-table-scroll"><table class="ilsm-table"><thead><tr><th>' . esc_html__( 'When', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'User', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Source', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Anchor', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Destination', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Result', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Actions', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody id="ilsm-insertion-history-body">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by history_rows_html() with context-specific escaping.
        echo $history['html'];
        echo '</tbody></table></div><div class="ilsm-history-load-wrap"><button type="button" class="ilsm-btn" id="ilsm-history-load-more" data-history-page="1"' . disabled( ! $history['has_more'], true, false ) . '>' . esc_html__( 'Load more history', 'dma-internlink-mapper' ) . '</button><span id="ilsm-history-status" aria-live="polite"></span></div></section></div><div id="ilsm-insert-modal" class="ilsm-modal ilsm-insert-modal-modern" hidden role="dialog" aria-modal="true" aria-labelledby="ilsm-insert-modal-title" aria-describedby="ilsm-insert-modal-description"><div class="ilsm-modal-card" tabindex="-1"><button type="button" class="ilsm-modal-close" aria-label="' . esc_attr__( 'Close', 'dma-internlink-mapper' ) . '">&times;</button><div class="ilsm-modal-titlebar"><span class="ilsm-modal-status-icon"><i class="fa fa-check-circle" aria-hidden="true"></i></span><div><h2 id="ilsm-insert-modal-title">' . esc_html__( 'Confirm internal-link insertion', 'dma-internlink-mapper' ) . '</h2><p id="ilsm-insert-modal-description">' . esc_html__( 'Review verified results for the selected source, anchor and destination.', 'dma-internlink-mapper' ) . '</p></div></div><div id="ilsm-insert-preview" aria-live="polite" aria-atomic="false"></div><div class="ilsm-modal-footer"><label class="ilsm-confirm-check"><input type="checkbox" id="ilsm-confirm-insert"> <span><i class="fa fa-info-circle" aria-hidden="true"></i> ' . esc_html__( 'I reviewed the source, anchor and destination.', 'dma-internlink-mapper' ) . '</span></label><div class="ilsm-modal-actions"><button type="button" class="ilsm-btn" data-ilsm-close>' . esc_html__( 'Cancel', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-confirm-insert-button" disabled>' . esc_html__( 'Insert verified link', 'dma-internlink-mapper' ) . '</button></div></div></div></div>';
        self::render_target_search( $scan );
        echo '</div>';
    }

    /** Return one bounded insertion-history page and truthful status counts. */
    private static function get_history_page( $scan, $status = 'all', $page = 1, $per_page = 25 ) {
        global $wpdb;
        $scan     = absint( $scan );
        $status   = in_array( $status, array( 'all', 'live', 'errors' ), true ) ? $status : 'all';
        $page     = max( 1, absint( $page ) );
        $per_page = max( 10, min( 50, absint( $per_page ) ) );
        $offset   = ( $page - 1 ) * $per_page;
        $table    = ILSM_Database::table( 'insertions' );
        if ( ! $scan ) { return array( 'html' => self::history_rows_html( array() ), 'has_more' => false, 'counts' => array( 'all' => 0, 'live' => 0, 'errors' => 0 ) ); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- History is mutable audit data and must reflect the insertion that just completed.
        $count_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS all_count, SUM(insertion_status=%s) AS live_count, SUM(insertion_status=%s) AS error_count FROM %i WHERE scan_id=%d',
                'inserted',
                'failed',
                $table,
                $scan
            ),
            ARRAY_A
        );
        $counts = array(
            'all'    => (int) ( $count_row['all_count'] ?? 0 ),
            'live'   => (int) ( $count_row['live_count'] ?? 0 ),
            'errors' => (int) ( $count_row['error_count'] ?? 0 ),
        );
        $status_value = 'live' === $status ? 'inserted' : ( 'errors' === $status ? 'failed' : '' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- History is mutable audit data and must reflect the insertion that just completed.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT h.*,u.display_name,sp.post_title source_title,tp.post_title target_title FROM %i h LEFT JOIN %i u ON u.ID=h.user_id LEFT JOIN %i sp ON sp.ID=h.source_post_id LEFT JOIN %i tp ON tp.ID=h.target_post_id WHERE h.scan_id=%d AND (%s=%s OR h.insertion_status=%s) ORDER BY h.id DESC LIMIT %d OFFSET %d',
                $table,
                $wpdb->users,
                $wpdb->posts,
                $wpdb->posts,
                $scan,
                $status,
                'all',
                $status_value,
                $per_page + 1,
                $offset
            )
        );
        $has_more = count( $rows ) > $per_page;
        if ( $has_more ) { array_pop( $rows ); }
        return array( 'html' => self::history_rows_html( $rows ), 'has_more' => $has_more, 'counts' => $counts );
    }

    /** Build escaped history rows for initial rendering and authenticated AJAX refreshes. */
    private static function history_rows_html( $rows ) {
        if ( ! $rows ) { return '<tr class="ilsm-history-empty"><td colspan="7" class="ilsm-empty-cell">' . esc_html__( 'No insertion history in this view.', 'dma-internlink-mapper' ) . '</td></tr>'; }
        $html = '';
        foreach ( $rows as $history ) {
            $source_view_url = get_permalink( (int) $history->source_post_id );
            $source_edit_url = get_edit_post_link( (int) $history->source_post_id, 'raw' );
            $destination_url = $history->destination_url ?: get_permalink( (int) $history->target_post_id );
            $source_title    = $history->source_title ?: '#' . $history->source_post_id;
            $target_title    = $history->target_title ?: $destination_url;
            $is_live         = 'inserted' === $history->insertion_status && 'publish' === get_post_status( (int) $history->source_post_id );
            $is_error        = 'failed' === $history->insertion_status;
            $status_label    = $is_live ? __( 'Live', 'dma-internlink-mapper' ) : ( $is_error ? __( 'Error', 'dma-internlink-mapper' ) : ucfirst( (string) $history->insertion_status ) );
            $status_class    = $is_live ? ' ilsm-badge-live' : ( $is_error ? ' ilsm-badge-error' : '' );
            $result_detail   = $is_error ? ( $history->error_message ?: $history->error_code ) : $status_label;
            $actions         = '<div class="ilsm-row-actions ilsm-history-actions-row">';
            if ( $source_view_url ) { $actions .= '<a class="ilsm-btn ilsm-btn-small" href="' . esc_url( $source_view_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View source', 'dma-internlink-mapper' ) . '</a>'; }
            if ( $source_edit_url ) { $actions .= '<a class="ilsm-btn ilsm-btn-small" href="' . esc_url( $source_edit_url ) . '">' . esc_html__( 'Edit source', 'dma-internlink-mapper' ) . '</a>'; }
            if ( $destination_url ) { $actions .= '<a class="ilsm-btn ilsm-btn-small" href="' . esc_url( $destination_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Destination', 'dma-internlink-mapper' ) . '</a>'; }
            if ( 'inserted' === $history->insertion_status ) { $actions .= '<button type="button" class="ilsm-btn ilsm-btn-small ilsm-undo-opportunity" data-history-id="' . absint( $history->id ) . '">' . esc_html__( 'Undo', 'dma-internlink-mapper' ) . '</button>'; }
            $actions .= '</div>';
            $html .= '<tr data-history-status="' . esc_attr( $is_error ? 'errors' : 'live' ) . '"><td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $history->created_at ) ) . '</td><td>' . esc_html( $history->display_name ?: '—' ) . '</td><td><a href="' . esc_url( $source_view_url ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html( $source_title ) . '</strong></a></td><td><span class="ilsm-anchor-chip">' . esc_html( $history->anchor_text ) . '</span></td><td><a href="' . esc_url( $destination_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $target_title ) . '</a></td><td><span class="ilsm-badge' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span><small class="ilsm-history-result-detail">' . esc_html( $result_detail ) . '</small></td><td>' . wp_kses_post( $actions ) . '</td></tr>';
        }
        return $html;
    }

    /** Authenticated, paginated history loader used by tabs and Load more. */
    public function ajax_insertion_history() {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_insert_links' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_insert_links', 'insert_nonce' );
        $scan   = ILSM_Database::latest_completed_scan_id();
        $status = sanitize_key( wp_unslash( $_POST['history_status'] ?? 'all' ) );
        $page   = max( 1, absint( wp_unslash( $_POST['history_page'] ?? 1 ) ) );
        wp_send_json_success( self::get_history_page( $scan, $status, $page, 25 ) );
    }

    private static function render_target_search( $scan ) {
        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill values; no state is changed.
        $prefill_target_id = absint( wp_unslash( $_GET['target_post_id'] ?? 0 ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill values; no state is changed.
        $prefill_keyword = sanitize_text_field( wp_unslash( $_GET['keyword'] ?? '' ) );
        $pages = ILSM_Database::table( 'pages' );
        $enabled_types = self::enabled_post_types();
        $targets = array();
        if ( $scan && $enabled_types ) {
            $placeholders = implode( ',', array_fill( 0, count( $enabled_types ), '%s' ) );
            $target_args = array_merge( array( $scan ), $enabled_types );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
            $targets = $wpdb->get_results( $wpdb->prepare( "SELECT post_id,title,post_type,url FROM {$pages} WHERE scan_id=%d AND post_type IN ({$placeholders}) ORDER BY title ASC LIMIT 3000", $target_args ) );
        }
        $types = array();
        foreach ( $enabled_types as $type_slug ) {
            $object = get_post_type_object( $type_slug );
            $types[ $type_slug ] = $object ? $object->labels->singular_name : $type_slug;
        }
        $taxonomies = self::searchable_taxonomies();
        $indexed_type_slugs = array_values( array_unique( array_map( static function( $target ) { return sanitize_key( $target->post_type ); }, (array) $targets ) ) );
        $missing_types = array_values( array_diff( $enabled_types, $indexed_type_slugs ) );

        echo '<div id="ilsm-opportunity-view-target" class="ilsm-opportunity-view"><section class="ilsm-panel ilsm-target-search-card"><div class="ilsm-settings-head"><i class="fa fa-bullseye"></i><div><h2>' . esc_html__( 'Find Links to a Page', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Select a public destination URL, enter its main keyword or keyphrase, and search posts, pages, public custom post types and taxonomy descriptions for safe contextual link opportunities.', 'dma-internlink-mapper' ) . '</p></div></div>';
        if ( $missing_types ) {
            $labels = array();
            foreach ( $missing_types as $missing_type ) { $labels[] = $types[ $missing_type ] ?? $missing_type; }
            echo '<div class="ilsm-index-notice"><i class="fa fa-refresh" aria-hidden="true"></i><div><strong>' . esc_html__( 'Fresh scan required', 'dma-internlink-mapper' ) . '</strong><span>' . esc_html( implode( ', ', $labels ) ) . ' are enabled but are not present in the latest completed scan.</span></div><a class="ilsm-btn ilsm-btn-small" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '">' . esc_html__( 'Open scanner', 'dma-internlink-mapper' ) . '</a></div>';
        }

        echo '<div class="ilsm-target-search-grid">';
        echo '<label class="ilsm-field"><span>' . esc_html__( 'Destination page or taxonomy URL', 'dma-internlink-mapper' ) . '</span><select id="ilsm-target-page"><option value="">' . esc_html__( 'Select a public destination', 'dma-internlink-mapper' ) . '</option><optgroup label="' . esc_attr__( 'Posts, pages and custom post types', 'dma-internlink-mapper' ) . '">';
        foreach ( $targets as $target ) {
            $post = get_post( $target->post_id );
            if ( ! $post || self::is_utility_destination( $post ) || is_wp_error( ILSM_Opportunity_Eligibility::validate( $post, 'destination' ) ) ) { continue; }
            $focus = self::focus_keyword( $target->post_id );
            $url = $target->url ?: get_permalink( $target->post_id );
            echo '<option value="post:' . absint( $target->post_id ) . '" data-id="' . absint( $target->post_id ) . '" data-keyword="' . esc_attr( $focus ) . '" data-url="' . esc_url( $url ) . '"' . selected( $prefill_target_id, absint( $target->post_id ), false ) . '>' . esc_html( html_entity_decode( $target->title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ' [' . esc_html( $target->post_type ) . '] — ' . esc_html( $url ) . '</option>';
        }
        echo '</optgroup>';
        foreach ( $taxonomies as $taxonomy => $object ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1000, 'orderby' => 'name', 'order' => 'ASC' ) );
            if ( is_wp_error( $terms ) || ! $terms ) { continue; }
            echo '<optgroup label="' . esc_attr( $object->labels->name ) . '">';
            foreach ( $terms as $term ) {
                $url = get_term_link( $term );
                if ( is_wp_error( $url ) ) { continue; }
                echo '<option value="term:' . esc_attr( $taxonomy ) . ':' . absint( $term->term_id ) . '" data-id="0" data-keyword="' . esc_attr( $term->name ) . '" data-url="' . esc_url( $url ) . '">' . esc_html( html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ' [' . esc_html( $object->labels->singular_name ) . '] — ' . esc_html( $url ) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select><small id="ilsm-target-selected-url" class="ilsm-field-help">' . esc_html__( 'WooCommerce cart, checkout, account, login and thank-you URLs are excluded automatically.', 'dma-internlink-mapper' ) . '</small></label>';
        echo '<label class="ilsm-field"><span>' . esc_html__( 'Keyword or keyphrase', 'dma-internlink-mapper' ) . '</span><input type="text" id="ilsm-target-keyword" maxlength="190" value="' . esc_attr( $prefill_keyword ) . '" placeholder="' . esc_attr__( 'Example: Marrakech desert tours', 'dma-internlink-mapper' ) . '"><small class="ilsm-field-help">' . esc_html__( 'The plugin first finds safely insertable phrases already present in source content, then ranks relevant manual source candidates when no anchor exists yet.', 'dma-internlink-mapper' ) . '</small></label>';
        echo '<label class="ilsm-field"><span>' . esc_html__( 'Source content type', 'dma-internlink-mapper' ) . '</span><select id="ilsm-target-source-type"><option value="all_content">' . esc_html__( 'All public content and taxonomies', 'dma-internlink-mapper' ) . '</option><option value="">' . esc_html__( 'All scanned post types', 'dma-internlink-mapper' ) . '</option>';
        foreach ( $types as $slug => $label ) { echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>'; }
        echo '<option value="taxonomy">' . esc_html__( 'Taxonomy descriptions only', 'dma-internlink-mapper' ) . '</option></select></label>';
        echo '<label class="ilsm-target-check"><input type="checkbox" id="ilsm-target-include-linked" value="1"> ' . esc_html__( 'Include sources already linking to this destination', 'dma-internlink-mapper' ) . '</label></div>';
        echo '<div class="ilsm-target-search-actions"><button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-find-target-links"><i class="fa fa-search"></i> ' . esc_html__( 'Find Link Opportunities', 'dma-internlink-mapper' ) . '</button><span id="ilsm-target-search-progress" aria-live="polite"></span></div></section>';
        echo '<section class="ilsm-panel ilsm-table-panel"><div class="ilsm-target-result-summary" id="ilsm-target-result-summary"></div><div class="ilsm-table-scroll"><table class="ilsm-table"><thead><tr><th>' . esc_html__( 'Best source', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Anchor or suggested phrase', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Context', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Confidence', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Action', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody id="ilsm-target-results"><tr><td colspan="6" class="ilsm-empty-cell">' . esc_html__( 'Choose a destination and keyword to search all eligible public content.', 'dma-internlink-mapper' ) . '</td></tr></tbody></table></div></section></div>';
    }

    private static function enabled_post_types() {
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        $settings = wp_parse_args(
            get_option( 'ilsm_settings', array() ),
            array( 'post_types' => ILSM_Activator::default_post_types() )
        );
        $selected = array_values( array_filter( array_map( 'sanitize_key', (array) $settings['post_types'] ) ) );
        $enabled  = array();

        foreach ( $selected as $slug ) {
            if ( ! isset( $objects[ $slug ] ) || ! ILSM_SEO_Inspector::is_supported_post_type( $slug ) ) {
                continue;
            }
            $enabled[] = $slug;
        }

        /**
         * Filters post types available to link opportunities and destination search.
         * The administrator's saved Settings selection is used as the baseline.
         */
        $enabled = apply_filters( 'ilsm_supported_post_types', array_values( array_unique( $enabled ) ) );

        return $enabled ?: ILSM_Activator::default_post_types();
    }

    private static function safe_anchor( $anchor ) {
        $normalized = self::normalize( $anchor );
        $parts = preg_split( '/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $parts ) { return false; }
        $generic = array( 'private', 'tour', 'tours', 'trip', 'trips', 'travel', 'desert', 'morocco', 'moroccan', 'guide', 'page', 'post', 'read', 'more', 'click', 'here', 'best', 'luxury' );
        if ( count( $parts ) >= 2 ) {
            return (bool) array_diff( $parts, $generic );
        }
        return ILSM_Text::length( $parts[0] ) >= 7 && ! in_array( $parts[0], $generic, true );
    }

    private static function focus_keyword( $post_id ) {
        $phrases = ILSM_SEO_Provider_Registry::focus_keyphrases( absint( $post_id ) );
        return empty( $phrases ) ? '' : sanitize_text_field( (string) reset( $phrases ) );
    }

    private static function exact_in_text( $text, $phrase ) {
        $phrase = trim( (string) $phrase );
        if ( '' === $phrase ) { return ''; }
        $tokens = preg_split( '/[^\p{L}\p{N}]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $tokens ) { return ''; }
        $pattern = '/(?<![\p{L}\p{N}])' . implode( '[^\p{L}\p{N}]+', array_map( static function( $token ) { return preg_quote( $token, '/' ); }, $tokens ) ) . '(?![\p{L}\p{N}])/iu';
        return preg_match( $pattern, (string) $text, $match ) ? trim( (string) $match[0] ) : '';
    }

    private static function keyword_variants( $keyword ) {
        $variants = array();
        $map = array( 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10', 'trip' => 'tour', 'journey' => 'tour', 'itinerary' => 'route', 'sahara' => 'desert' );
        $tokens = preg_split( '/\s+/u', self::normalize( $keyword ), -1, PREG_SPLIT_NO_EMPTY );
        if ( $tokens ) {
            $converted = array_map( static function( $token ) use ( $map ) { return $map[ $token ] ?? $token; }, $tokens );
            $variants[] = implode( ' ', $converted );
        }
        return $variants;
    }

    private static function semantic_signature( $phrase ) {
        $map = array( 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10', 'trips' => 'tour', 'trip' => 'tour', 'tours' => 'tour', 'journey' => 'tour', 'itinerary' => 'route', 'routes' => 'route', 'sahara' => 'desert', 'days' => 'day' );
        $tokens = preg_split( '/\s+/u', self::normalize( $phrase ), -1, PREG_SPLIT_NO_EMPTY );
        $out = array();
        foreach ( (array) $tokens as $token ) {
            $token = $map[ $token ] ?? $token;
            if ( ILSM_Text::length( $token ) < 2 || in_array( $token, array( 'the', 'and', 'from', 'with', 'for', 'to', 'in', 'of', 'a', 'an' ), true ) ) { continue; }
            $out[] = $token;
        }
        $out = array_values( array_unique( $out ) );
        sort( $out, SORT_STRING );
        return count( $out ) >= 2 ? implode( ' ', $out ) : '';
    }

    private static function body_phrases( $body ) {
        $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $body, true ) ) );
        $words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $phrases = array();
        $count = count( $words );
        for ( $length = 5; $length >= 2; $length-- ) {
            for ( $i = 0; $i <= $count - $length; $i++ ) {
                $phrase = trim( implode( ' ', array_slice( $words, $i, $length ) ), " \t\n\r\0\x0B,.;:!?()[]{}\"'" );
                if ( $phrase ) { $phrases[] = $phrase; }
                if ( count( $phrases ) >= 2500 ) { break 2; }
            }
        }
        return $phrases;
    }

    private static function normalize( $text ) {
        $text = remove_accents( html_entity_decode( wp_strip_all_tags( (string) $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $text = ILSM_Text::lower( $text );
        return trim( preg_replace( '/\s+/u', ' ', preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) ) );
    }

    private static function token_overlap( $a, $b ) {
        $one = array_unique( preg_split( '/\s+/u', self::normalize( $a ), -1, PREG_SPLIT_NO_EMPTY ) );
        $two = array_unique( preg_split( '/\s+/u', self::normalize( $b ), -1, PREG_SPLIT_NO_EMPTY ) );
        return count( array_intersect( $one, $two ) );
    }

    private static function excerpt( $body, $anchor ) {
        $body = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $body, true ) ) );
        $position = ILSM_Text::position( ILSM_Text::lower( $body ), ILSM_Text::lower( $anchor ) );
        if ( false === $position ) { return ILSM_Text::substring( $body, 0, 240 ); }
        $start = max( 0, $position - 100 );
        return ( $start ? '…' : '' ) . trim( ILSM_Text::substring( $body, $start, ILSM_Text::length( $anchor ) + 220 ) ) . '…';
    }


    /**
     * Whether an Elementor document is structural site chrome rather than body content.
     * Header/footer/theme-builder documents are never eligible as contextual-link sources.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private static function ilsm_is_structural_elementor_document( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return false;
        }

        if ( 'elementor_library' === get_post_type( $post_id ) ) {
            $template_type = strtolower( (string) get_post_meta( $post_id, '_elementor_template_type', true ) );
            if ( in_array( $template_type, array( 'header', 'footer' ), true ) ) {
                return true;
            }
        }

        $title = strtolower( (string) get_the_title( $post_id ) );
        $slug  = strtolower( (string) get_post_field( 'post_name', $post_id ) );
        foreach ( array( 'header', 'footer' ) as $structural_name ) {
            if ( $structural_name === $slug || preg_match( '/(^|[-_ ])' . preg_quote( $structural_name, '/' ) . '($|[-_ ])/i', $slug . ' ' . $title ) ) {
                if ( 'elementor_library' === get_post_type( $post_id ) ) {
                    return true;
                }
            }
        }
        return false;
    }
}
