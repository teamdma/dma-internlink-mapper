<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Privacy-first, on-server internal-link suggestion engine.
 * No remote APIs, telemetry, or cloud calls are used.
 */
final class ILSM_Local_Assistant {
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
        add_action( 'wp_ajax_ilsm_local_suggestions', array( $this, 'ajax_suggestions' ) );
        add_action( 'wp_ajax_ilsm_record_suggestion', array( $this, 'ajax_record_feedback' ) );
    }

    public function register_meta_box() {
        if ( ! current_user_can( 'edit_posts' ) ) { return; }
        $settings = get_option( 'ilsm_settings', array() );
        $types = array_values( array_filter( (array) ( $settings['post_types'] ?? ILSM_Activator::default_post_types() ), 'post_type_exists' ) );
        foreach ( $types as $type ) {
            add_meta_box(
                'ilsm-local-assistant',
                '<span class="dashicons dashicons-admin-links"></span> ' . esc_html__( 'DMA InternLink Mapper', 'dma-internlink-mapper' ),
                array( $this, 'render_meta_box' ),
                $type,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) { return; }
        wp_nonce_field( 'ilsm_editor_' . $post->ID, 'ilsm_editor_nonce' );
        ?>
        <div id="ilsm-assistant" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
            <p class="ilsm-assistant-intro"><?php esc_html_e( 'Local analysis only. Suggestions are calculated from your latest completed scan and this website’s content.', 'dma-internlink-mapper' ); ?></p>
            <button type="button" class="button button-primary button-large ilsm-assistant-analyse">
                <span class="fa fa-search" aria-hidden="true"></span>
                <?php esc_html_e( 'Analyse link opportunities', 'dma-internlink-mapper' ); ?>
            </button>
            <div class="ilsm-assistant-status" aria-live="polite"></div>
            <div class="ilsm-assistant-results"></div>
        </div>
        <?php
    }

    public function enqueue_editor_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! post_type_exists( $screen->post_type ) ) { return; }
        $settings = get_option( 'ilsm_settings', array() );
        $types = array_values( array_filter( (array) ( $settings['post_types'] ?? ILSM_Activator::default_post_types() ), 'post_type_exists' ) );
        if ( ! in_array( $screen->post_type, $types, true ) ) { return; }
        wp_enqueue_style( 'ilsm-font-awesome', ILSM_URL . 'admin/vendor/font-awesome/css/font-awesome.min.css', array(), ILSM_VERSION );
        $editor_css = ILSM_PATH . 'admin/css/editor-assistant.css';
        $editor_js  = ILSM_PATH . 'admin/js/editor-assistant.js';
        wp_enqueue_style( 'ilsm-editor', ILSM_URL . 'admin/css/editor-assistant.css', array(), is_readable( $editor_css ) ? (string) filemtime( $editor_css ) : ILSM_VERSION );
        wp_enqueue_script( 'ilsm-editor', ILSM_URL . 'admin/js/editor-assistant.js', array( 'jquery', 'wp-data', 'wp-blocks', 'wp-dom-ready' ), is_readable( $editor_js ) ? (string) filemtime( $editor_js ) : ILSM_VERSION, true );
        wp_localize_script( 'ilsm-editor', 'ILSM_EDITOR', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ilsm_editor_ajax' ),
            'strings' => array(
                'loading' => __( 'Analysing local content…', 'dma-internlink-mapper' ),
                'none'    => __( 'No strong, unused internal-link opportunities were found.', 'dma-internlink-mapper' ),
                'error'   => __( 'The analysis could not be completed.', 'dma-internlink-mapper' ),
                'copied'       => __( 'Link HTML copied.', 'dma-internlink-mapper' ),
                'inserted'     => __( 'Link inserted. The editor moved to its location.', 'dma-internlink-mapper' ),
                'viewInserted' => __( 'View inserted link', 'dma-internlink-mapper' ),
                'noSafeLocation' => __( 'No safe body-text location was found. Links are never inserted into headings, images, buttons, captions, code, or existing links.', 'dma-internlink-mapper' ),
                'noEditorText'   => __( 'No eligible body text was found in the current editor.', 'dma-internlink-mapper' ),
                'insertFailed'   => __( 'Gutenberg rejected the block update. The original block content was restored.', 'dma-internlink-mapper' ),
                'noBodyOpportunities' => __( 'No safe body-text link opportunities were found in the current editor content.', 'dma-internlink-mapper' ),
                'noSafeAnchor' => __( 'No safe body-text anchor found', 'dma-internlink-mapper' ),
                'invalidNaturalAnchor' => __( 'That anchor is not eligible. Use one to three meaningful words with spaces only; location-only and punctuation-separated phrases are excluded.', 'dma-internlink-mapper' ),
                /* translators: 1: Search Console impressions, 2: average search position. */
                'searchConsoleEvidence' => __( 'Search Console page evidence: %1$s impressions · average position %2$s', 'dma-internlink-mapper' ),
                'intentJourney' => __( 'Reader journey', 'dma-internlink-mapper' ),
                'intentCurrentPost' => __( 'Current post', 'dma-internlink-mapper' ),
                'intentSuggestedPage' => __( 'Suggested page', 'dma-internlink-mapper' ),
                /* translators: %d: intent-classification confidence percentage. */
                'intentConfidence' => __( '%d%% confidence', 'dma-internlink-mapper' ),
                'intentJourneyInformationalCommercial' => __( 'Helps readers move from learning to comparing suitable options.', 'dma-internlink-mapper' ),
                'intentJourneyInformationalTransactional' => __( 'Helps informed readers continue to a relevant booking or enquiry page.', 'dma-internlink-mapper' ),
                'intentJourneyCommercialTransactional' => __( 'Provides a clear next step from comparing options to taking action.', 'dma-internlink-mapper' ),
                'intentJourneyInformationalInformational' => __( 'Adds useful supporting information for readers exploring this topic.', 'dma-internlink-mapper' ),
                'intentJourneyTransactionalInformational' => __( 'Offers helpful supporting information before a reader takes action.', 'dma-internlink-mapper' ),
                'intentJourneyNeutral' => __( 'Intent is supporting evidence only; confirm that this link genuinely helps the reader.', 'dma-internlink-mapper' ),
                'linkBudgetTitle' => __( 'Contextual link balance', 'dma-internlink-mapper' ),
                /* translators: 1: contextual internal links, 2: contextual external links, 3: shortcode/card links, 4: all visible links, 5: eligible words, 6: recommended maximum. */
                'linkBudgetSummary' => __( '%1$d contextual internal + %2$d contextual external + %3$d shortcode/card links = %4$d visible links across %5$d eligible body words. Recommended working maximum: %6$d.', 'dma-internlink-mapper' ),
                'linkBudgetHealthy' => __( 'The page remains within its contextual-link working range.', 'dma-internlink-mapper' ),
                'linkBudgetNear' => __( 'This page is close to its contextual-link working maximum. Add only a highly useful link.', 'dma-internlink-mapper' ),
                'linkBudgetExceeded' => __( 'This page already meets or exceeds its contextual-link working maximum. Too many links can distract readers. Only add this suggestion when it clearly improves navigation.', 'dma-internlink-mapper' ),
                'linkBudgetCrowded' => __( 'This page already presents more visible links than its reader-focused working range. Internal, external and shortcode/card links all contribute to navigational load. Add only an essential, highly relevant link.', 'dma-internlink-mapper' ),
                /* translators: 1: current contextual internal links, 2: eligible body words, 3: recommended maximum. */
                'linkBudgetConfirm' => __( 'This page already has %1$d contextual internal links for %2$d eligible body words (working maximum: %3$d). Add this link anyway?', 'dma-internlink-mapper' ),
                /* translators: 1: all visible links, 2: shortcode/card links. */
                'linkCrowdedConfirm' => __( 'This page already displays %1$d links in total, including %2$d shortcode or card links. Add another contextual link anyway?', 'dma-internlink-mapper' ),
            ),
        ) );
    }

    public function ajax_suggestions() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_editor_ajax', 'nonce' );
        $post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post.', 'dma-internlink-mapper' ) ), 400 );
        }
        $scan_id = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan_id ) {
            wp_send_json_error( array( 'message' => __( 'Run and complete a website scan before requesting suggestions.', 'dma-internlink-mapper' ) ), 409 );
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => __( 'Post not found.', 'dma-internlink-mapper' ) ), 404 );
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input is validated and normalized immediately before use.
        $live_body_text = isset( $_POST['live_body_text'] ) ? wp_unslash( (string) $_POST['live_body_text'] ) : '';
        $live_body_text = self::sanitize_live_body_text( $live_body_text );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized immediately below and used only for a bounded word count.
        $live_body_word_text = isset( $_POST['live_body_word_text'] ) ? self::sanitize_live_body_text( wp_unslash( (string) $_POST['live_body_word_text'] ) ) : $live_body_text;
        $live_contextual_links = min( 5000, absint( wp_unslash( $_POST['live_contextual_links'] ?? 0 ) ) );
        $live_contextual_external_links = min( 5000, absint( wp_unslash( $_POST['live_contextual_external_links'] ?? 0 ) ) );
        $live_visible_internal_links = min( 5000, absint( wp_unslash( $_POST['live_visible_internal_links'] ?? $live_contextual_links ) ) );
        $live_visible_external_links = min( 5000, absint( wp_unslash( $_POST['live_visible_external_links'] ?? $live_contextual_external_links ) ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input is validated and normalized immediately before use.
        $live_urls_raw = isset( $_POST['live_existing_urls'] ) ? json_decode( wp_unslash( (string) $_POST['live_existing_urls'] ), true ) : array();
        $live_existing_urls = array();
        foreach ( array_slice( is_array( $live_urls_raw ) ? $live_urls_raw : array(), 0, 1000 ) as $url ) {
            $url = esc_url_raw( (string) $url );
            if ( $url ) { $live_existing_urls[] = untrailingslashit( strtolower( $url ) ); }
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input is validated and normalized immediately before use.
        $live_segments_raw = isset( $_POST['live_segments'] ) ? json_decode( wp_unslash( (string) $_POST['live_segments'] ), true ) : array();
        $live_segments = self::sanitize_live_segments( $live_segments_raw );

        /*
         * Elementor runs in a separate editor application and intentionally
         * analyses the last saved document. Its assistant therefore does not
         * submit Gutenberg-style live text. Fall back to the same saved,
         * insertion-safe Elementor controls used by the link inserter.
         */
        if ( '' === $live_body_text && ILSM_Content_Extractor::has_elementor_document( $post_id ) ) {
            $live_body_text = self::sanitize_live_body_text( ILSM_Content_Extractor::extract_insertable_text( $post ) );

            if ( empty( $live_existing_urls ) ) {
                foreach ( ILSM_Content_Extractor::extract( $post ) as $existing_link ) {
                    $existing_url = esc_url_raw( (string) ( $existing_link['url'] ?? '' ) );
                    if ( $existing_url ) {
                        $live_existing_urls[] = untrailingslashit( strtolower( $existing_url ) );
                    }
                }
                $live_existing_urls = array_values( array_unique( $live_existing_urls ) );
            }
        }

        if ( '' === $live_body_text ) {
            wp_send_json_error( array( 'message' => __( 'No eligible body text was found in the current editor.', 'dma-internlink-mapper' ) ), 400 );
        }
        $suggestions = $this->get_suggestions( $scan_id, $post, 8, $live_body_text, $live_existing_urls, $live_segments );
        $link_metrics = self::live_link_metrics( $live_body_word_text, $live_contextual_links, $live_visible_internal_links, $live_contextual_external_links, $live_visible_external_links );
        wp_send_json_success( array(
            'scan_id'     => $scan_id,
            'suggestions' => $suggestions,
            'link_metrics' => $link_metrics,
            'privacy'     => __( 'Processed locally on this WordPress installation.', 'dma-internlink-mapper' ),
        ) );
    }

    /** Calculate a conservative contextual-link working range for unsaved editor content. */
    public static function live_link_metrics( $body_text, $contextual_links, $visible_internal_links = null, $contextual_external_links = 0, $visible_external_links = null ) {
        preg_match_all( '/[\p{L}\p{N}]+/u', html_entity_decode( (string) $body_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $words );
        $word_count = count( $words[0] ?? array() );
        $settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_density_per_1000' => 6 ) );
        $density = max( 1, min( 20, absint( $settings['insert_density_per_1000'] ) ) );
        $recommended_max = max( 2, (int) ceil( max( 1, $word_count ) / 1000 * $density ) );
        $contextual_links = min( 5000, absint( $contextual_links ) );
        $contextual_external_links = min( 5000, absint( $contextual_external_links ) );
        $visible_internal_links = null === $visible_internal_links ? $contextual_links : min( 5000, absint( $visible_internal_links ) );
        $visible_internal_links = max( $contextual_links, $visible_internal_links );
        $visible_external_links = null === $visible_external_links ? $contextual_external_links : min( 5000, absint( $visible_external_links ) );
        $visible_external_links = max( $contextual_external_links, $visible_external_links );
        $contextual_total = $contextual_links + $contextual_external_links;
        $visible_total = $visible_internal_links + $visible_external_links;
        $embedded_links = max( 0, $visible_total - $contextual_total );
        $remaining = max( 0, $recommended_max - $visible_total );
        $state = $visible_total > $recommended_max ? 'crowded' : ( $visible_total === $recommended_max ? 'exceeded' : ( $remaining <= 1 ? 'near' : 'healthy' ) );
        return array(
            'word_count'       => $word_count,
            'contextual_links' => $contextual_links,
            'contextual_external_links' => $contextual_external_links,
            'contextual_total' => $contextual_total,
            'embedded_links'   => $embedded_links,
            'visible_internal_links' => $visible_internal_links,
            'visible_external_links' => $visible_external_links,
            'visible_total' => $visible_total,
            'recommended_max'  => $recommended_max,
            'remaining_budget' => $remaining,
            'state'            => $state,
        );
    }

    public function ajax_record_feedback() {
        if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( array(), 403 ); }
        check_ajax_referer( 'ilsm_editor_ajax', 'nonce' );
        global $wpdb;
        $source_post_id = absint( wp_unslash( $_POST['source_post_id'] ?? 0 ) );
        $target_post_id = absint( wp_unslash( $_POST['target_post_id'] ?? 0 ) );
        $decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        if ( ! $source_post_id || ! $target_post_id || ! in_array( $decision, array( 'accepted', 'ignored' ), true ) || ! current_user_can( 'edit_post', $source_post_id ) || ! current_user_can( 'read_post', $target_post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid feedback.', 'dma-internlink-mapper' ) ), 400 );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $wpdb->replace(
            ILSM_Database::table( 'feedback' ),
            array(
                'user_id'        => get_current_user_id(),
                'source_post_id' => $source_post_id,
                'target_post_id' => $target_post_id,
                'decision'       => $decision,
                'updated_at'     => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%d', '%s', '%s' )
        );
        wp_send_json_success();
    }

    public function get_suggestions( $scan_id, WP_Post $post, $limit = 8, $live_body_text = '', $live_existing_urls = array(), $live_segments = array() ) {
        global $wpdb;

        $source_anchor_text = '' !== trim( (string) $live_body_text )
            ? (string) $live_body_text
            : self::body_text_without_headings( $post->post_content );
        if ( '' === trim( $source_anchor_text ) ) { return array(); }

        $terms = '' !== $live_body_text
            ? self::extract_live_terms( $post, $live_body_text )
            : self::extract_terms( $post );
        if ( empty( $terms ) ) { return array(); }

        /*
         * Candidate discovery uses two independent signals:
         * 1. weighted keyword overlap from the latest local scan;
         * 2. an exact natural body-text match for a destination focus keyphrase,
         *    title phrase, or slug phrase.
         *
         * This prevents useful links such as "Erg Chegaga" from disappearing
         * merely because generic TF-IDF terms did not rank inside the first set.
         */
        $candidate_ids   = array();
        $keyword_scores = array();
        $crawler_matches_by_post = array();
        $term_names      = array_slice( array_keys( $terms ), 0, 45 );

        // The dedicated crawler phrase index is the primary exact-intent signal.
        // It matches phrases already present in current body text against crawled
        // focus keyphrases, titles, slugs, taxonomies, headings and body copy.
        foreach ( ILSM_Crawler::match_editor_text( $scan_id, $post->ID, $source_anchor_text, 240 ) as $crawler_row ) {
            $crawler_post_id = absint( $crawler_row['post_id'] ?? 0 );
            if ( ! $crawler_post_id ) { continue; }
            $candidate_ids[ $crawler_post_id ] = true;
            $crawler_matches_by_post[ $crawler_post_id ][] = $crawler_row;
        }

        if ( $term_names ) {
            $placeholders = implode( ',', array_fill( 0, count( $term_names ), '%s' ) );
            $sql = "SELECT k.post_id, SUM(k.weight) AS term_score, COUNT(DISTINCT k.term) AS shared_terms
                    FROM " . ILSM_Database::table( 'keywords' ) . " k
                    INNER JOIN " . ILSM_Database::table( 'pages' ) . " p ON p.scan_id=k.scan_id AND p.post_id=k.post_id
                    WHERE k.scan_id=%d AND k.term IN ({$placeholders}) AND k.post_id<>%d
                    GROUP BY k.post_id
                    ORDER BY term_score DESC, shared_terms DESC
                    LIMIT 120";
            $args = array_merge( array( $scan_id ), $term_names, array( $post->ID ) );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $candidate_id = absint( $row['post_id'] ?? 0 );
                if ( ! $candidate_id ) { continue; }
                $candidate_ids[ $candidate_id ] = true;
                $keyword_scores[ $candidate_id ] = (float) ( $row['term_score'] ?? 0 );
            }
        }

        // Examine scanned titles locally as a second candidate source. The hard
        // limit prevents one editor request from loading an unbounded site index.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $page_rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
                'SELECT post_id,title FROM ' . ILSM_Database::table( 'pages' ) . ' WHERE scan_id=%d AND post_id<>%d ORDER BY id ASC LIMIT 1500',
                $scan_id,
                $post->ID
            ),
            ARRAY_A
        );
        $page_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( (array) $page_rows, 'post_id' ) ) ) );
        if ( $page_ids ) {
            _prime_post_caches( $page_ids, false, false );
            update_meta_cache( 'post', $page_ids );
        }

        foreach ( (array) $page_rows as $page_row ) {
            $candidate_id = absint( $page_row['post_id'] ?? 0 );
            if ( ! $candidate_id ) { continue; }
            $direct_candidates = self::phrase_candidates_from_text( (string) ( $page_row['title'] ?? '' ), 90, 'title' );
            foreach ( self::get_focus_keyphrases( $candidate_id ) as $focus_phrase ) {
                $direct_candidates = array_merge( $direct_candidates, self::phrase_candidates_from_text( $focus_phrase, 110, 'focus' ) );
            }
            $candidate_post = get_post( $candidate_id );
            if ( $candidate_post && '' !== (string) $candidate_post->post_name ) {
                $direct_candidates = array_merge(
                    $direct_candidates,
                    self::phrase_candidates_from_text( str_replace( array( '-', '_' ), ' ', urldecode( (string) $candidate_post->post_name ) ), 76, 'slug' )
                );
            }
            if ( self::find_candidate_anchor_matches( $source_anchor_text, $direct_candidates ) ) {
                $candidate_ids[ $candidate_id ] = true;
            }
        }

        if ( ! $candidate_ids ) { return array(); }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $existing_targets = $wpdb->get_col( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
            'SELECT DISTINCT target_post_id FROM ' . ILSM_Database::table( 'links' ) . ' WHERE scan_id=%d AND source_post_id=%d AND target_post_id>0',
            $scan_id,
            $post->ID
        ) );
        $existing_targets = array_map( 'intval', (array) $existing_targets );

        $source_focus = self::get_focus_keyphrases( $post->ID );
        $source_intent = ILSM_Search_Intent::classify_post( $post, $source_anchor_text );
        $suggestions  = array();
        $candidate_urls = array();
        foreach ( array_keys( $candidate_ids ) as $candidate_id ) {
            $candidate_url = get_permalink( absint( $candidate_id ) );
            if ( $candidate_url ) { $candidate_urls[] = $candidate_url; }
        }
        $search_console_metrics = class_exists( 'ILSM_Search_Console_Import' ) ? ILSM_Search_Console_Import::metrics_for_urls( $candidate_urls ) : array();

        foreach ( array_keys( $candidate_ids ) as $target_id ) {
            $target_id = absint( $target_id );
            if ( ! $target_id || in_array( $target_id, $existing_targets, true ) ) { continue; }

            $target = get_post( $target_id );
            if ( ! $target || 'publish' !== $target->post_status || ! is_post_publicly_viewable( $target ) ) { continue; }

            $target_url = get_permalink( $target );
            if ( ! $target_url ) { continue; }
            $target_url_normalized = untrailingslashit( strtolower( (string) $target_url ) );
            if ( in_array( $target_url_normalized, (array) $live_existing_urls, true ) ) { continue; }
            $search_url = esc_url_raw( ILSM_Link_Normalizer::normalize( $target_url, home_url( '/' ) ) );
            $search_metrics = $search_url && isset( $search_console_metrics[ $search_url ] ) ? (array) $search_console_metrics[ $search_url ] : array();

            $target_terms      = self::get_indexed_terms( $scan_id, $target_id );
            $shared            = array_intersect_key( $terms, $target_terms );
            $meaningful_shared = array_values( array_filter( array_keys( $shared ), array( __CLASS__, 'is_meaningful_term' ) ) );

            $anchor_matches = self::destination_anchor_matches( $target, $source_anchor_text, array_keys( $shared ) );

            // Merge exact phrases returned by the crawler. These are guaranteed to
            // occur in current editor body text and point to this destination.
            foreach ( (array) ( $crawler_matches_by_post[ $target_id ] ?? array() ) as $crawler_match ) {
                $phrase = trim( (string) ( $crawler_match['phrase'] ?? '' ) );
                if ( '' === $phrase ) { continue; }
                $found = self::find_candidate_anchor_matches(
                    $source_anchor_text,
                    array( array(
                        'phrase'   => $phrase,
                        'priority' => min( 140, 70 + absint( $crawler_match['priority'] ?? 0 ) / 2 ),
                        'source'   => sanitize_key( (string) ( $crawler_match['source'] ?? 'crawler' ) ),
                    ) )
                );
                if ( $found ) { $anchor_matches = array_merge( $anchor_matches, $found ); }
            }
            if ( ! $anchor_matches ) { continue; }
            usort( $anchor_matches, static function( $a, $b ) {
                $word_order = (int) ( $b['word_count'] ?? 0 ) <=> (int) ( $a['word_count'] ?? 0 );
                return 0 !== $word_order ? $word_order : (int) $b['priority'] <=> (int) $a['priority'];
            } );

            $anchors = array_values( wp_list_pluck( $anchor_matches, 'text' ) );
            $anchors = array_slice( array_values( array_unique( $anchors ) ), 0, 8 );
            if ( ! $anchors ) { continue; }
            $anchor_locations = array();
            foreach ( $anchors as $anchor_text ) {
                $location = self::find_live_segment_location( $live_segments, $anchor_text );
                if ( $location ) { $anchor_locations[ ILSM_Text::lower( $anchor_text ) ] = $location; }
            }

            $score = self::similarity_score( $terms, $target_terms );
            if ( ! empty( $crawler_matches_by_post[ $target_id ] ) ) {
                $score += min( 30, 8 + count( $crawler_matches_by_post[ $target_id ] ) * 4 );
            }
            $best_anchor_priority = (int) ( $anchor_matches[0]['priority'] ?? 0 );
            $score += min( 38, max( 10, ( $best_anchor_priority - 50 ) * 0.75 ) );

            $target_focus = self::get_focus_keyphrases( $target_id );
            $focus_matches = array();
            foreach ( $anchor_matches as $match ) {
                if ( 'focus' === ( $match['source'] ?? '' ) ) {
                    $focus_matches[] = $match['candidate'];
                }
            }
            $focus_matches = array_values( array_unique( $focus_matches ) );

            if ( $source_focus && $target_focus ) {
                $source_focus_tokens = self::focus_tokens( $source_focus );
                $target_focus_tokens = self::focus_tokens( $target_focus );
                $focus_overlap = array_intersect( $source_focus_tokens, $target_focus_tokens );
                $score += min( 12, count( $focus_overlap ) * 3 );
            }

            if ( count( $meaningful_shared ) >= 2 ) {
                $score += min( 12, count( $meaningful_shared ) * 2 );
            }
            if ( isset( $keyword_scores[ $target_id ] ) ) {
                $score += min( 8, log( 1 + max( 0, $keyword_scores[ $target_id ] ) ) );
            }
            $search_boost = self::search_console_page_boost( $search_metrics );
            $score += $search_boost;
            $target_intent = ILSM_Search_Intent::classify_post( $target );
            $intent_boost = ILSM_Search_Intent::compatibility_boost( $source_intent, $target_intent );
            $score += $intent_boost;

            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            $feedback = $wpdb->get_var( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
                'SELECT decision FROM ' . ILSM_Database::table( 'feedback' ) . ' WHERE user_id=%d AND source_post_id=%d AND target_post_id=%d',
                get_current_user_id(),
                $post->ID,
                $target_id
            ) );
            if ( 'ignored' === $feedback ) { $score -= 12; }
            if ( 'accepted' === $feedback ) { $score += 5; }
            if ( $score < 35 ) { continue; }

            $why = array_slice( $meaningful_shared, 0, 5 );
            $signals = array();
            if ( $focus_matches ) {
                $signals[] = __( 'A destination focus keyphrase is present naturally in the current body text.', 'dma-internlink-mapper' );
            } elseif ( 'title' === ( $anchor_matches[0]['source'] ?? '' ) ) {
                $signals[] = __( 'A meaningful phrase from the destination title is present naturally in the current body text.', 'dma-internlink-mapper' );
            } elseif ( 'slug' === ( $anchor_matches[0]['source'] ?? '' ) ) {
                $signals[] = __( 'A meaningful destination URL phrase is present naturally in the current body text.', 'dma-internlink-mapper' );
            }
            if ( count( $meaningful_shared ) >= 2 ) {
                $signals[] = sprintf(
                    /* translators: %d: number of shared meaningful terms. */
                    _n( '%d meaningful topic overlap.', '%d meaningful topic overlaps.', count( $meaningful_shared ), 'dma-internlink-mapper' ),
                    count( $meaningful_shared )
                );
            }
            if ( ! empty( $search_metrics['impressions'] ) ) {
                $signals[] = sprintf(
                    /* translators: 1: impressions, 2: average position. */
                    __( 'Imported Search Console page evidence: %1$s impressions at average position %2$s.', 'dma-internlink-mapper' ),
                    number_format_i18n( absint( $search_metrics['impressions'] ) ),
                    number_format_i18n( (float) ( $search_metrics['position'] ?? 0 ), 1 )
                );
            }
            if ( $intent_boost > 0 ) { $signals[] = __( 'Source and destination intent form a useful reader journey.', 'dma-internlink-mapper' ); }
            $signals[] = __( 'This destination is not already linked from the current post.', 'dma-internlink-mapper' );

            $suggestions[] = array(
                'post_id'       => $target_id,
                'title'         => html_entity_decode( get_the_title( $target ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                'url'           => esc_url_raw( $target_url ),
                'edit_url'      => esc_url_raw( get_edit_post_link( $target_id, 'raw' ) ),
                'score'         => max( 0, min( 100, (int) round( $score ) ) ),
                'anchors'       => $anchors,
                'anchor_locations' => $anchor_locations,
                'shared_terms'  => array_values( $why ),
                'reason'        => implode( ' ', $signals ),
                'focus_matches' => $focus_matches,
                'search_console' => ! empty( $search_metrics['impressions'] ) ? array(
                    'clicks'      => absint( $search_metrics['clicks'] ?? 0 ),
                    'impressions' => absint( $search_metrics['impressions'] ),
                    'position'    => round( (float) ( $search_metrics['position'] ?? 0 ), 1 ),
                    'boost'       => $search_boost,
                ) : array(),
                'source_intent' => $source_intent,
                'target_intent' => $target_intent,
                'intent_boost'  => $intent_boost,
            );
        }

        usort( $suggestions, static function( $a, $b ) {
            $score_order = (int) $b['score'] <=> (int) $a['score'];
            if ( 0 !== $score_order ) { return $score_order; }
            return strcasecmp( (string) $a['title'], (string) $b['title'] );
        } );

        return array_slice( $suggestions, 0, max( 1, min( 8, absint( $limit ) ) ) );
    }

    /** Keep imported page-performance evidence useful but subordinate to relevance. */
    public static function search_console_page_boost( $metrics ) {
        $impressions = absint( $metrics['impressions'] ?? 0 );
        if ( ! $impressions ) { return 0; }
        $position = max( 0, (float) ( $metrics['position'] ?? 0 ) );
        $boost = min( 5, (int) floor( log10( $impressions + 1 ) * 1.7 ) );
        if ( $position >= 4 && $position <= 20 ) { $boost += 4; }
        elseif ( $position > 20 && $position <= 50 ) { $boost += 2; }
        elseif ( $position > 0 && $position < 4 ) { $boost += 1; }
        return min( 9, max( 1, $boost ) );
    }

    /**
     * Return focus keyphrases from supported SEO plugins without requiring them.
     * Values are used only as relevance signals; they never force a suggestion.
     */
    /** Public read-only bridge used by the local crawler. */
    public static function get_focus_keyphrases_for_crawler( $post_id ) {
        return self::get_focus_keyphrases( absint( $post_id ) );
    }

    private static function get_focus_keyphrases( $post_id ) {
        return ILSM_SEO_Provider_Registry::focus_keyphrases( absint( $post_id ) );
    }

    private static function normalise_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = ILSM_Text::lower( $text );
        $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function contains_phrase( $haystack, $needle ) {
        if ( '' === $haystack || '' === $needle ) { return false; }
        return false !== ILSM_Text::position( ' ' . $haystack . ' ', ' ' . $needle . ' ' );
    }

    private static function focus_tokens( $phrases ) {
        $tokens = array();
        foreach ( (array) $phrases as $phrase ) {
            foreach ( self::tokenize( $phrase ) as $token ) {
                if ( self::is_meaningful_term( $token ) ) { $tokens[] = $token; }
            }
        }
        return array_values( array_unique( $tokens ) );
    }

    private static function is_meaningful_term( $term ) {
        $term = trim( (string) $term );
        if ( '' === $term ) { return false; }
        $generic = array( 'tour', 'tours', 'trip', 'trips', 'travel', 'page', 'post', 'blog', 'guide', 'discover', 'explore', 'details', 'view details', 'read more', 'click here', 'learn more' );
        return ! in_array( $term, $generic, true );
    }

    private static function sanitize_live_body_text( $text ) {
        $text = wp_strip_all_tags( (string) $text, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text );
        $text = trim( preg_replace( '/[ \t]+/u', ' ', preg_replace( '/\R+/u', "\n", $text ) ) );
        return ILSM_Text::substring( $text, 0, 200000 );
    }

    private static function extract_live_terms( WP_Post $post, $live_body_text ) {
        $title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $taxonomy_text = '';
        foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
            $names = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) ) { $taxonomy_text .= ' ' . implode( ' ', $names ); }
        }
        $focus = implode( ' ', self::get_focus_keyphrases( $post->ID ) );
        $parts = array(
            array( $title, 5.0 ), array( $taxonomy_text, 4.0 ), array( $focus, 6.0 ), array( $live_body_text, 1.0 ),
        );
        $scores = array();
        foreach ( $parts as $part ) {
            $tokens = self::tokenize( $part[0] );
            foreach ( $tokens as $token ) { $scores[ $token ] = ( $scores[ $token ] ?? 0 ) + $part[1]; }
            $count = count( $tokens );
            for ( $i = 0; $i < $count - 1; $i++ ) {
                $phrase = $tokens[ $i ] . ' ' . $tokens[ $i + 1 ];
                if ( strlen( $phrase ) <= 190 ) { $scores[ $phrase ] = ( $scores[ $phrase ] ?? 0 ) + ( $part[1] * 1.45 ); }
            }
            for ( $i = 0; $i < $count - 2; $i++ ) {
                $phrase = $tokens[ $i ] . ' ' . $tokens[ $i + 1 ] . ' ' . $tokens[ $i + 2 ];
                if ( strlen( $phrase ) <= 190 ) { $scores[ $phrase ] = ( $scores[ $phrase ] ?? 0 ) + ( $part[1] * 1.75 ); }
            }
        }
        arsort( $scores, SORT_NUMERIC );
        return array_filter( $scores, static function( $score, $term ) { return $score >= 2 && strlen( $term ) >= 3; }, ARRAY_FILTER_USE_BOTH );
    }

    public static function index_post( $scan_id, WP_Post $post ) {
        global $wpdb;
        $table = ILSM_Database::table( 'keywords' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $wpdb->delete( $table, array( 'scan_id' => $scan_id, 'post_id' => $post->ID ), array( '%d', '%d' ) );
        $terms = self::extract_terms( $post );
        foreach ( array_slice( $terms, 0, 80, true ) as $term => $weight ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom tables require direct database access.
            $wpdb->insert( $table, array(
                'scan_id' => $scan_id,
                'post_id' => $post->ID,
                'term'    => ILSM_Text::substring( $term, 0, 190 ),
                'weight'  => round( $weight, 3 ),
            ), array( '%d', '%d', '%s', '%f' ) );
        }
    }

    public static function extract_terms( WP_Post $post ) {
        $title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $content = ILSM_Content_Extractor::extract_searchable_text( $post );
        $excerpt = wp_strip_all_tags( $post->post_excerpt, true );
        $headings = ILSM_Content_Extractor::extract_headings( $post );
        $taxonomy_text = '';
        foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
            $names = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) ) { $taxonomy_text .= ' ' . implode( ' ', $names ); }
        }
        $focus = implode( ' ', self::get_focus_keyphrases( $post->ID ) );
        $parts = array(
            array( $title, 6.0 ), array( $headings, 4.0 ), array( $taxonomy_text, 4.5 ),
            array( $focus, 6.0 ), array( $excerpt, 2.5 ), array( $content, 1.0 ),
        );
        $scores = array();
        foreach ( $parts as $part ) {
            $tokens = self::tokenize( $part[0] );
            foreach ( $tokens as $token ) { $scores[ $token ] = ( $scores[ $token ] ?? 0 ) + $part[1]; }
            $count = count( $tokens );
            for ( $i = 0; $i < $count - 1; $i++ ) {
                $phrase = $tokens[$i] . ' ' . $tokens[$i+1];
                if ( ILSM_Text::length( $phrase ) <= 190 ) { $scores[$phrase] = ( $scores[$phrase] ?? 0 ) + ( $part[1] * 1.35 ); }
            }
            for ( $i = 0; $i < $count - 2; $i++ ) {
                $phrase = $tokens[$i] . ' ' . $tokens[$i+1] . ' ' . $tokens[$i+2];
                if ( ILSM_Text::length( $phrase ) <= 190 ) { $scores[$phrase] = ( $scores[$phrase] ?? 0 ) + ( $part[1] * 1.6 ); }
            }
        }
        arsort( $scores, SORT_NUMERIC );
        return array_filter( $scores, static function( $score, $term ) { return $score >= 2 && ILSM_Text::length( $term ) >= 3; }, ARRAY_FILTER_USE_BOTH );
    }

    private static function tokenize( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = ILSM_Text::lower( $text );
        $text = preg_replace( '/[^\p{L}\p{N}\s-]+/u', ' ', $text );
        $raw = preg_split( '/[\s-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $stop = array_flip( array(
            'the','and','for','with','from','this','that','your','you','are','was','were','have','has','had','but','not','our','their','into','about','more','will','can','all','any','its','also','than','then','when','where','what','which','who','how','why','a','an','of','to','in','on','at','by','or','as','is','be','it','we','they','he','she','de','het','een','en','van','voor','met','op','naar','te','is','zijn','les','des','une','un','et','du','la','le','dans','sur','pour','من','في','على','إلى','عن','هذا','هذه','مع','كان','و','أو'
        ) );
        $out = array();
        foreach ( $raw as $token ) {
            if ( isset( $stop[$token] ) || ILSM_Text::length( $token ) < 3 || ILSM_Text::length( $token ) > 45 || ctype_digit( $token ) ) { continue; }
            $out[] = $token;
            if ( count( $out ) >= 1200 ) { break; }
        }
        return $out;
    }

    private static function get_indexed_terms( $scan_id, $post_id ) {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $rows = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
            'SELECT term,weight FROM ' . ILSM_Database::table( 'keywords' ) . ' WHERE scan_id=%d AND post_id=%d ORDER BY weight DESC LIMIT 100',
            $scan_id, $post_id
        ), ARRAY_A );
        $out = array();
        foreach ( $rows as $row ) { $out[ $row['term'] ] = (float) $row['weight']; }
        return $out;
    }

    private static function similarity_score( $a, $b ) {
        $shared = array_intersect_key( $a, $b );
        if ( ! $shared ) { return 0; }
        $dot = 0.0; $norm_a = 0.0; $norm_b = 0.0;
        foreach ( $a as $term => $weight ) { $norm_a += $weight * $weight; if ( isset( $b[$term] ) ) { $dot += $weight * $b[$term]; } }
        foreach ( $b as $weight ) { $norm_b += $weight * $weight; }
        if ( $norm_a <= 0 || $norm_b <= 0 ) { return 0; }
        $cosine = $dot / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
        $coverage = min( 1, count( $shared ) / 12 );
        return ( $cosine * 75 ) + ( $coverage * 25 );
    }

    /**
     * Collect eligible body copy from Gutenberg blocks. Headings, media,
     * captions, buttons, navigation, code and other non-contextual blocks
     * are intentionally excluded from anchor opportunities.
     */
    private static function body_text_without_headings( $content ) {
        $content = (string) $content;
        if ( '' === trim( $content ) ) { return ''; }

        if ( function_exists( 'has_blocks' ) && has_blocks( $content ) && function_exists( 'parse_blocks' ) ) {
            $parts = self::eligible_block_text( parse_blocks( $content ) );
            return trim( implode( "\n", array_filter( $parts ) ) );
        }

        // Classic Editor fallback. Remove unsafe containers before extracting text.
        $content = preg_replace( '#<(h[1-6]|figure|figcaption|picture|button|nav|code|pre|script|style|svg|canvas)\b[^>]*>.*?</\1>#isu', ' ', $content );
        $content = preg_replace( '#<img\b[^>]*>#isu', ' ', $content );
        return html_entity_decode( wp_strip_all_tags( (string) $content, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /**
     * Recursively extract only body-text block content that may safely receive
     * a contextual link.
     */
    private static function eligible_block_text( $blocks ) {
        $out = array();
        $blocked_exact = array(
            'core/heading', 'core/image', 'core/gallery', 'core/media-text',
            'core/cover', 'core/video', 'core/audio', 'core/file',
            'core/button', 'core/buttons', 'core/navigation', 'core/code',
            'core/preformatted', 'core/html', 'core/shortcode', 'core/embed',
            'core/post-featured-image', 'core/post-title', 'core/site-title',
        );

        foreach ( (array) $blocks as $block ) {
            $name = isset( $block['blockName'] ) ? strtolower( (string) $block['blockName'] ) : '';
            $is_blocked = in_array( $name, $blocked_exact, true )
                || preg_match( '#(^|/|-|_)(heading|image|gallery|media|photo|slider|carousel|caption|button|navigation|menu|code|form|map)(/|-|_|$)#', $name );
            if ( $is_blocked ) { continue; }

            $html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
            if ( '' !== trim( $html ) ) {
                $html = preg_replace( '#<(h[1-6]|figure|figcaption|picture|button|nav|code|pre|script|style|svg|canvas)\b[^>]*>.*?</\1>#isu', ' ', $html );
                $html = preg_replace( '#<img\b[^>]*>#isu', ' ', $html );
                $text = trim( html_entity_decode( wp_strip_all_tags( (string) $html, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                if ( '' !== $text ) { $out[] = $text; }
            }

            if ( ! empty( $block['innerBlocks'] ) ) {
                $out = array_merge( $out, self::eligible_block_text( $block['innerBlocks'] ) );
            }
        }
        return $out;
    }

    /**
     * Build natural anchor candidates from a destination's SEO focus phrases,
     * title, slug, and shared indexed terms, then return only phrases that are
     * truly present in eligible current-editor body text.
     */
    private static function destination_anchor_matches( WP_Post $target, $source_text, $shared_terms = array() ) {
        $candidates = array();

        foreach ( self::get_focus_keyphrases( $target->ID ) as $focus_phrase ) {
            $candidates = array_merge( $candidates, self::phrase_candidates_from_text( $focus_phrase, 120, 'focus' ) );
        }

        $title = html_entity_decode( get_the_title( $target ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $candidates = array_merge( $candidates, self::phrase_candidates_from_text( $title, 105, 'title' ) );

        $slug = (string) $target->post_name;
        if ( '' !== $slug ) {
            $slug = str_replace( array( '-', '_' ), ' ', urldecode( $slug ) );
            $candidates = array_merge( $candidates, self::phrase_candidates_from_text( $slug, 82, 'slug' ) );
        }

        foreach ( (array) $shared_terms as $term ) {
            $term = trim( html_entity_decode( (string) $term, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( ! self::is_useful_anchor_phrase( $term ) ) { continue; }
            $candidates[] = array(
                'phrase'   => $term,
                'priority' => false !== strpos( $term, ' ' ) ? 68 : 54,
                'source'   => 'topic',
            );
        }

        return self::find_candidate_anchor_matches( $source_text, $candidates );
    }

    /**
     * Generate clean contiguous anchors in editorial order: three words first,
     * then two, then a distinctive single word only as a final fallback.
     */
    private static function phrase_candidates_from_text( $text, $base_priority, $source ) {
        $text = trim( html_entity_decode( wp_strip_all_tags( (string) $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' === $text ) { return array(); }

        $tokens = preg_split( '/\s+/u', preg_replace( '/[^\p{L}\p{N}\s\'’-]+/u', ' ', $text ), -1, PREG_SPLIT_NO_EMPTY );
        $tokens = array_values( array_filter( array_map( 'trim', (array) $tokens ) ) );
        if ( ! $tokens ) { return array(); }

        $count = count( $tokens );
        $max_n = min( 3, $count );
        $out   = array();

        // A complete keyphrase is useful only when it already fits the maximum.
        if ( $count <= 3 && self::is_useful_anchor_phrase( $text ) ) {
            $out[] = array( 'phrase' => $text, 'priority' => (int) $base_priority + 8, 'source' => $source );
        }

        for ( $length = $max_n; $length >= 1; $length-- ) {
            for ( $start = 0; $start <= $count - $length; $start++ ) {
                $phrase_tokens = array_slice( $tokens, $start, $length );
                $phrase = implode( ' ', $phrase_tokens );
                $route_connectors = array( 'to','from','via','through','into' );
                $is_middle_phrase = $start > 0 && ( $start + $length ) < $count;
                $contains_route_connector = (bool) array_intersect( array_map( array( 'ILSM_Text', 'lower' ), $phrase_tokens ), $route_connectors );
                if ( $is_middle_phrase && $contains_route_connector ) { continue; }
                if ( ! self::is_useful_anchor_phrase( $phrase ) ) { continue; }
                $out[] = array(
                    'phrase'   => $phrase,
                    'priority' => ( $length * 1000 ) + (int) $base_priority - $start,
                    'source'   => $source,
                );
            }
        }

        // Dedupe by normalized phrase while preserving the strongest signal.
        $deduped = array();
        foreach ( $out as $candidate ) {
            $key = self::normalise_text( $candidate['phrase'] );
            if ( '' === $key ) { continue; }
            if ( ! isset( $deduped[ $key ] ) || $candidate['priority'] > $deduped[ $key ]['priority'] ) {
                $deduped[ $key ] = $candidate;
            }
        }
        return array_values( $deduped );
    }

    /**
     * Reject vague anchors. Connecting words may remain inside a phrase, but a
     * candidate needs at least two meaningful words unless it is a distinctive
     * long single word.
     */
    private static function is_useful_anchor_phrase( $phrase ) {
        $phrase = trim( (string) $phrase );
        if ( '' === $phrase || ILSM_Text::length( $phrase ) < 4 || ILSM_Text::length( $phrase ) > 120 ) { return false; }

        $all_tokens = preg_split( '/[^\p{L}\p{N}]+/u', self::normalise_text( $phrase ), -1, PREG_SPLIT_NO_EMPTY );
        $all_tokens = array_values( array_filter( (array) $all_tokens ) );
        if ( ! $all_tokens ) { return false; }

        $connectors = array( 'to','from','in','on','at','of','and','or','the','a','an','for','with','through','via','by','into','over','under' );
        $first = (string) reset( $all_tokens );
        $last  = (string) end( $all_tokens );
        if ( in_array( $first, $connectors, true ) || in_array( $last, $connectors, true ) ) { return false; }

        $meaningful = self::tokenize( $phrase );
        $meaningful = array_values( array_filter( $meaningful, array( __CLASS__, 'is_meaningful_term' ) ) );
        $location_only = array( 'marrakech','morocco','moroccan','city','town','village','country','region','destination','area' );
        $meaningful_lower = array_map( array( 'ILSM_Text', 'lower' ), $meaningful );
        if ( $meaningful_lower && ! array_diff( $meaningful_lower, $location_only ) ) { return false; }
        $single_generic = array( 'morocco','moroccan','desert','adventure','travel','tour','tours','trip','trips','guide','blog','page','post' );
        if ( count( $meaningful ) >= 2 ) {
            $distinctive = array_diff( array_map( array( 'ILSM_Text', 'lower' ), $meaningful ), $single_generic );
            if ( $distinctive ) { return true; }
        }

        // A single distinctive place/name word may be useful, but common sitewide
        // SEO terms are never offered as one-word anchors.
        return 1 === count( $meaningful )
            && 1 === count( $all_tokens )
            && ILSM_Text::length( $meaningful[0] ) >= 7
            && ! in_array( ILSM_Text::lower( $meaningful[0] ), $single_generic, true );
    }

    /**
     * Find candidate phrases in plain eligible body text. Multi-word anchors
     * must be joined by whitespace only; punctuation never becomes part of or
     * bridges a suggested anchor.
     */
    private static function find_candidate_anchor_matches( $source_text, $candidates ) {
        $source_text = html_entity_decode( (string) $source_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( '' === trim( $source_text ) ) { return array(); }

        $matches = array();
        foreach ( (array) $candidates as $candidate ) {
            $phrase = trim( (string) ( $candidate['phrase'] ?? '' ) );
            if ( '' === $phrase ) { continue; }

            $tokens = preg_split( '/[^\p{L}\p{N}]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY );
            if ( ! $tokens || count( $tokens ) > 3 || ! self::is_useful_anchor_phrase( implode( ' ', $tokens ) ) ) { continue; }
            $escaped = array_map( static function( $token ) { return preg_quote( $token, '/' ); }, $tokens );
            $pattern = '/(?<![\p{L}\p{N}])' . implode( '[\x{20}\x{09}\x{00A0}]+', $escaped ) . '(?![\p{L}\p{N}])/iu';
            if ( ! preg_match( $pattern, $source_text, $found ) ) { continue; }

            $exact = trim( (string) $found[0] );
            $key   = self::normalise_text( $exact );
            if ( '' === $key ) { continue; }

            $row = array(
                'text'      => $exact,
                'candidate' => $phrase,
                'priority'  => (int) ( $candidate['priority'] ?? 0 ),
                'word_count' => count( $tokens ),
                'source'    => sanitize_key( (string) ( $candidate['source'] ?? 'topic' ) ),
            );
            if ( ! isset( $matches[ $key ] ) || $row['priority'] > $matches[ $key ]['priority'] ) {
                $matches[ $key ] = $row;
            }
        }

        uasort( $matches, static function( $a, $b ) {
            $word_order = (int) ( $b['word_count'] ?? 0 ) <=> (int) ( $a['word_count'] ?? 0 );
            if ( 0 !== $word_order ) { return $word_order; }
            $priority_order = (int) $b['priority'] <=> (int) $a['priority'];
            if ( 0 !== $priority_order ) { return $priority_order; }
            return ILSM_Text::length( (string) $b['text'] ) <=> ILSM_Text::length( (string) $a['text'] );
        } );

        return array_values( $matches );
    }

    private static function sanitize_live_segments( $segments ) {
        $clean = array();
        foreach ( array_slice( is_array( $segments ) ? $segments : array(), 0, 5000 ) as $segment ) {
            if ( ! is_array( $segment ) ) { continue; }
            $client_id = sanitize_text_field( (string) ( $segment['clientId'] ?? '' ) );
            $attribute = sanitize_key( (string) ( $segment['attribute'] ?? '' ) );
            $text      = self::sanitize_live_body_text( (string) ( $segment['text'] ?? '' ) );
            if ( '' === $text ) { continue; }
            $clean[] = array(
                'clientId' => ILSM_Text::substring( $client_id, 0, 100 ),
                'attribute' => ILSM_Text::substring( $attribute, 0, 80 ),
                'text' => ILSM_Text::substring( $text, 0, 20000 ),
            );
        }
        return $clean;
    }

    private static function find_live_segment_location( $segments, $anchor ) {
        $anchor = trim( (string) $anchor );
        if ( '' === $anchor ) { return array(); }
        foreach ( (array) $segments as $segment ) {
            $text = (string) ( $segment['text'] ?? '' );
            if ( '' === $text ) { continue; }
            $position = ILSM_Text::position( ILSM_Text::lower( $text ), ILSM_Text::lower( $anchor ) );
            if ( false === $position ) { continue; }
            return array(
                'clientId' => (string) ( $segment['clientId'] ?? '' ),
                'attribute' => (string) ( $segment['attribute'] ?? '' ),
                'offset' => (int) $position,
                'length' => ILSM_Text::length( $anchor ),
            );
        }
        return array();
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
