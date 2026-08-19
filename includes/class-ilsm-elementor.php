<?php
/**
 * DMA InternLink Mapper Elementor write-safety boundary.
 * Editor opportunities and automatic insertion are intentionally restricted to
 * registered textarea/WYSIWYG controls in body widgets. Do not reuse this restriction in read-only global
 * crawling/SEO analysis: SEO Issues, Link Report, Anchor Analysis and maps must
 * continue to analyse the complete rendered/indexable page output.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Elementor compatibility bridge.
 *
 * The bridge does not send content to any third party. It exposes the same
 * local suggestion engine inside Elementor and improves indexing of saved
 * Elementor documents.
 */
final class ILSM_Elementor {
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
        add_action( 'elementor/editor/after_enqueue_styles', array( $this, 'enqueue_editor_styles' ) );
        add_action( 'wp_ajax_ilsm_elementor_insert_link', array( $this, 'ajax_insert_link' ) );
        add_action( 'wp_ajax_ilsm_elementor_preview_link', array( $this, 'ajax_preview_link' ) );
        add_action( 'wp_ajax_ilsm_elementor_verify_published_link', array( $this, 'ajax_verify_published_link' ) );
    }

    public function enqueue_editor_styles() {
        if ( ! current_user_can( 'edit_posts' ) ) { return; }
        $font_css = ILSM_PATH . 'admin/vendor/font-awesome/css/font-awesome.min.css';
        $panel_css = ILSM_PATH . 'admin/css/elementor-assistant.css';
        wp_enqueue_style( 'ilsm-font-awesome', ILSM_URL . 'admin/vendor/font-awesome/css/font-awesome.min.css', array(), is_readable( $font_css ) ? (string) filemtime( $font_css ) : ILSM_VERSION );
        wp_enqueue_style( 'ilsm-elementor', ILSM_URL . 'admin/css/elementor-assistant.css', array(), is_readable( $panel_css ) ? (string) filemtime( $panel_css ) : ILSM_VERSION );
    }

    public function enqueue_editor_assets() {
        if ( ! current_user_can( 'edit_posts' ) ) { return; }
        $panel_js = ILSM_PATH . 'admin/js/elementor-assistant.js';
        wp_enqueue_script( 'ilsm-elementor', ILSM_URL . 'admin/js/elementor-assistant.js', array( 'jquery' ), is_readable( $panel_js ) ? (string) filemtime( $panel_js ) : ILSM_VERSION, true );
        $ilsm_settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_min_confidence' => 70 ) );
        wp_localize_script( 'ilsm-elementor', 'ILSM_ELEMENTOR', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ilsm_editor_ajax' ),
            'canInsert' => current_user_can( 'ilsm_insert_links' ),
            'minConfidence' => max( 60, min( 100, absint( $ilsm_settings['insert_min_confidence'] ) ) ),
            'sourceUrl' => ( isset( $_GET['post'] ) && absint( $_GET['post'] ) ) ? esc_url_raw( get_permalink( absint( $_GET['post'] ) ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor context used only to build a frontend link.
            'strings' => array(
                'title'          => __( 'DMA InternLink Mapper', 'dma-internlink-mapper' ),
                'subtitle'       => __( 'Local Elementor link analysis', 'dma-internlink-mapper' ),
                'analyse'        => __( 'Analyse link opportunities', 'dma-internlink-mapper' ),
                'loading'        => __( 'Analysing saved Elementor content locally…', 'dma-internlink-mapper' ),
                'noneTitle'      => __( 'No eligible opportunities found', 'dma-internlink-mapper' ),
                'none'           => __( 'No safely insertable opportunities were found in saved Elementor textarea/WYSIWYG body controls. Other page content is still analysed in the WordPress SEO reports.', 'dma-internlink-mapper' ),
                'error'          => __( 'The analysis could not be completed.', 'dma-internlink-mapper' ),
                'ignore'         => __( 'Ignore', 'dma-internlink-mapper' ),
                'insert'         => __( 'Insert link', 'dma-internlink-mapper' ),
                'inserting'      => __( 'Updating the Elementor text control and saving with Elementor…', 'dma-internlink-mapper' ),
                'inserted'       => __( 'Link inserted and saved by Elementor.', 'dma-internlink-mapper' ),
                'insertedVerified' => __( 'Link inserted and verified in saved Elementor data.', 'dma-internlink-mapper' ),
                'publishedVerified' => __( 'Published and verified live.', 'dma-internlink-mapper' ),
                'savedNotLive' => __( 'Saved in Elementor, but the public page could not yet be verified. Update/publish the page and verify again.', 'dma-internlink-mapper' ),
                'renderVerifyFailed' => __( 'Insertion failed: the saved control did not render a live link. The original value was restored.', 'dma-internlink-mapper' ),
                'viewLive'       => __( 'View live page', 'dma-internlink-mapper' ),
                'editInserted'   => __( 'Edit', 'dma-internlink-mapper' ),
                'undo'           => __( 'Undo', 'dma-internlink-mapper' ),
                'undone'         => __( 'Insertion undone. The original Elementor text was restored.', 'dma-internlink-mapper' ),
                'retry'          => __( 'Retry', 'dma-internlink-mapper' ),
                'unsaved'        => __( 'Update the page before inserting a link so unsaved Elementor changes are not overwritten.', 'dma-internlink-mapper' ),
                'confirmInsert'  => __( 'Insert this link into the selected Elementor textarea/WYSIWYG control?', 'dma-internlink-mapper' ),
                'previewFound'   => __( 'The Elementor widget is open at the exact insertion location.', 'dma-internlink-mapper' ),
                'previewMissing' => __( 'The anchor is in saved Elementor data but is not visible in the current preview. Update the page and analyse again.', 'dma-internlink-mapper' ),
                'saved'          => __( 'Analysis uses the last saved Elementor revision. Update the page first if you have unsaved changes.', 'dma-internlink-mapper' ),
                'supportedTitle' => __( 'What DMA InternLink Mapper analyses', 'dma-internlink-mapper' ),
                'supported'      => __( 'For automatic insertion, DMA InternLink Mapper reads Elementor’s registered controls and supports body widget fields declared as textarea or WYSIWYG, including compatible theme/custom widgets. Header, hero, CTA, footer, templates, dynamic controls and other control types are skipped.', 'dma-internlink-mapper' ),
                'anchorLabel'    => __( 'Anchor text', 'dma-internlink-mapper' ),
                'bestSuggestionsShown' => __( 'best suggestions shown', 'dma-internlink-mapper' ),
                /* translators: %s: Number of additional suggestions. */
                'showMoreSuggestions' => __( 'Show %s more suggestions', 'dma-internlink-mapper' ),
                'readySafeInsertion' => __( 'Ready for safe Elementor text insertion', 'dma-internlink-mapper' ),
                'checkingSafe' => __( 'Checking which suggestions are safely insertable in Elementor textarea/WYSIWYG controls…', 'dma-internlink-mapper' ),
                /* translators: %s: Number of verified Elementor text-control opportunities. */
                'verifiedReadyOne' => __( '%s verified Elementor text-control opportunity is ready.', 'dma-internlink-mapper' ),
                /* translators: %s: Number of verified Elementor text-control opportunities. */
                'verifiedReadyMany' => __( '%s verified Elementor text-control opportunities are ready.', 'dma-internlink-mapper' ),
                /* translators: %s: Minimum confidence percentage. */
                'noneAtConfidence' => __( 'No unused Elementor text-control opportunities met the configured minimum confidence (%s%%). Existing links are not suggested again.', 'dma-internlink-mapper' ),
                'editorApiUnavailable' => __( 'Elementor editor API is unavailable. Reload Elementor and try again.', 'dma-internlink-mapper' ),
                'widgetOpenFailed' => __( 'The exact Elementor text widget could not be opened. Update the page and analyse again.', 'dma-internlink-mapper' ),
                'controlUnsupported' => __( 'The saved control is no longer a supported Elementor textarea/WYSIWYG field. Analyse again.', 'dma-internlink-mapper' ),
                'prepareUpdateFailed' => __( 'DMA InternLink Mapper could not prepare a safe Elementor text-control update.', 'dma-internlink-mapper' ),
                'controlChanged' => __( 'The Elementor text control changed after analysis. Update the page and analyse again before inserting.', 'dma-internlink-mapper' ),
                'updateRejected' => __( 'Elementor rejected the text-control update.', 'dma-internlink-mapper' ),
                'saveStartFailed' => __( 'Elementor could not start the save operation. The text-control change was reverted.', 'dma-internlink-mapper' ),
                'liveWidgetVerifyFailed' => __( 'Elementor saved, but DMA InternLink Mapper could not verify the inserted link in the live widget.', 'dma-internlink-mapper' ),
                'saveVerifyFailed' => __( 'Save verification failed. Update the page and analyse again.', 'dma-internlink-mapper' ),
                'publishWhenReady' => __( 'Saved in Elementor. Publish/update the page when ready.', 'dma-internlink-mapper' ),
                'saveLivePending' => __( 'Elementor save completed; live verification is pending.', 'dma-internlink-mapper' ),
                'saveFailedReverted' => __( 'Elementor could not save the page. The text-control change was reverted.', 'dma-internlink-mapper' ),
                'controlReopenFailed' => __( 'The inserted Elementor control could not be reopened.', 'dma-internlink-mapper' ),
                'verifiedSuggestions' => __( 'verified suggestions', 'dma-internlink-mapper' ),
                'textControlLocated' => __( 'Text control located. Elementor opened the exact widget.', 'dma-internlink-mapper' ),
                'insertionBlockedSave' => __( 'Insertion blocked: save/update the Elementor page first.', 'dma-internlink-mapper' ),
                'insertingSaving' => __( 'Inserting and saving with Elementor…', 'dma-internlink-mapper' ),
                'undoMissingContext' => __( 'Undo is unavailable because the verified insertion context is missing.', 'dma-internlink-mapper' ),
                'undoSaveFirst' => __( 'Undo blocked: save/update your current Elementor changes first.', 'dma-internlink-mapper' ),
                'undoControlUnavailable' => __( 'Undo failed: the Elementor control is no longer available.', 'dma-internlink-mapper' ),
                'undoControlChanged' => __( 'Undo stopped: this Elementor text control changed after insertion.', 'dma-internlink-mapper' ),
                'undoing' => __( 'Undoing the verified insertion…', 'dma-internlink-mapper' ),
                'undoVerifyFailed' => __( 'Undo failed verification.', 'dma-internlink-mapper' ),
                'undoSaveFailed' => __( 'Undo failed: Elementor could not save the restored text.', 'dma-internlink-mapper' ),
                'undoUpdateRejected' => __( 'Undo failed: Elementor rejected the text-control update.', 'dma-internlink-mapper' ),
            ),
        ) );
    }
    /** Resolve the exact saved Elementor textarea/WYSIWYG control used by a suggestion. */
    public function ajax_preview_link() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_editor_ajax', 'nonce' );
        $source_id = absint( wp_unslash( $_POST['source_post_id'] ?? 0 ) );
        $target_id = absint( wp_unslash( $_POST['target_post_id'] ?? 0 ) );
        $anchor    = isset( $_POST['anchor'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor'] ) ) : '';
        if ( ! $source_id || ! $target_id || '' === trim( $anchor ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'read_post', $target_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid preview request.', 'dma-internlink-mapper' ) ), 400 );
        }
        $inserter = new ILSM_Link_Inserter();
        $result = $inserter->preview_elementor_location( $source_id, $target_id, $anchor );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 409 );
        }
        wp_send_json_success( $result );
    }

    /**
     * Verify that an Elementor insertion exists in persisted data and, for a
     * published source, in the public frontend response. This endpoint never
     * writes content. It is deliberately separate from the editor preview so
     * a green status cannot be produced from an in-memory Elementor model.
     */
    public function ajax_verify_published_link() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_editor_ajax', 'nonce' );

        $source_id = absint( wp_unslash( $_POST['source_post_id'] ?? 0 ) );
        $target_id = absint( wp_unslash( $_POST['target_post_id'] ?? 0 ) );
        $anchor    = isset( $_POST['anchor'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor'] ) ) : '';
        if ( ! $source_id || ! $target_id || '' === trim( $anchor ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'read_post', $target_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid verification request.', 'dma-internlink-mapper' ) ), 400 );
        }

        $target_url = esc_url_raw( get_permalink( $target_id ) );
        $source_url = esc_url_raw( get_permalink( $source_id ) );
        $raw        = (string) get_post_meta( $source_id, '_elementor_data', true );
        $persisted  = false;
        if ( $target_url && '' !== $raw ) {
            $needle_url = str_replace( array( '\\/', '&amp;' ), array( '/', '&' ), $target_url );
            $haystack   = str_replace( array( '\\/', '&amp;' ), array( '/', '&' ), $raw );
            $persisted  = false !== strpos( $haystack, $needle_url );
        }

        $post = get_post( $source_id );
        $published = $post instanceof WP_Post && 'publish' === $post->post_status && is_post_publicly_viewable( $post );
        $live = false;
        $http_code = 0;
        if ( $persisted && $published && $source_url && $target_url ) {
            $verify_url = add_query_arg( 'ilsm_verify', (string) time(), $source_url );
            $response = wp_safe_remote_get( $verify_url, array(
                'timeout'             => 8,
                'redirection'         => 3,
                'reject_unsafe_urls'  => true,
                'sslverify'           => true,
                'limit_response_size' => 3145728,
                'headers'             => array( 'Cache-Control' => 'no-cache' ),
                'user-agent'          => 'WordPress/DMA-InternLink-Mapper live-link verification',
            ) );
            if ( ! is_wp_error( $response ) ) {
                $http_code = (int) wp_remote_retrieve_response_code( $response );
                $body = (string) wp_remote_retrieve_body( $response );
                if ( $http_code >= 200 && $http_code < 400 && '' !== $body ) {
                    $normalized_body = html_entity_decode( $body, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
                    $normalized_url  = html_entity_decode( $target_url, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
                    $live = false !== strpos( $normalized_body, $normalized_url );
                }
            }
        }

        wp_send_json_success( array(
            'persisted'   => (bool) $persisted,
            'published'   => (bool) $published,
            'live'        => (bool) $live,
            'http_code'   => $http_code,
            'source_url'  => $source_url,
            'target_url'  => $target_url,
            'post_status' => $post instanceof WP_Post ? (string) $post->post_status : '',
        ) );
    }

    /** Insert a verified local suggestion into supported saved Elementor content. */
    public function ajax_insert_link() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_editor_ajax', 'nonce' );

        $source_id = absint( wp_unslash( $_POST['source_post_id'] ?? 0 ) );
        $target_id = absint( wp_unslash( $_POST['target_post_id'] ?? 0 ) );
        $anchor    = isset( $_POST['anchor'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor'] ) ) : '';
        if ( ! $source_id || ! $target_id || '' === trim( $anchor ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'read_post', $target_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid link insertion request.', 'dma-internlink-mapper' ) ), 400 );
        }

        $inserter = new ILSM_Link_Inserter();
        $result   = $inserter->insert_elementor_direct( $source_id, $target_id, $anchor );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ),
                409
            );
        }

        // Record acceptance so the local assistant learns from the user's action.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned feedback table stores an explicit write; object-cache reads are not applicable to this write operation.
        $wpdb->replace(
            ILSM_Database::table( 'feedback' ),
            array(
                'user_id'        => get_current_user_id(),
                'source_post_id' => $source_id,
                'target_post_id' => $target_id,
                'decision'       => 'accepted',
                'updated_at'     => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%d', '%s', '%s' )
        );

        wp_send_json_success( $result );
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
