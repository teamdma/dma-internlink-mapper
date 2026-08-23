<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Performs conservative, verified internal-link insertions and safe undo operations.
 */
class ILSM_Link_Inserter {
    const LOCK_TTL = 120;
    const SNAPSHOT_TTL = 900;

    public static function history_table() { return ILSM_Database::table( 'insertions' ); }

    /**
     * Insert one verified link directly into a supported saved Elementor control.
     *
     * This bridge is used by the Elementor DMA InternLink Mapper panel. It deliberately refuses
     * dynamic controls, headings, buttons and unsupported widgets, and reloads
     * the editor after success so Elementor cannot overwrite the saved change
     * with an older in-memory document.
     *
     * @param int    $source_id Source post ID.
     * @param int    $target_id Destination post ID.
     * @param string $anchor    Visible anchor text already present in the source.
     * @return array|WP_Error
     */
    public function insert_elementor_direct( $source_id, $target_id, $anchor ) {
        $source_id = absint( $source_id );
        $target_id = absint( $target_id );
        $anchor    = trim( sanitize_text_field( (string) $anchor ) );

        if ( ! $source_id || ! $target_id || '' === $anchor ) {
            return new WP_Error( 'invalid_request', __( 'The source, destination or anchor is invalid.', 'dma-internlink-mapper' ) );
        }
        if ( ! current_user_can( 'ilsm_insert_links' ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'read_post', $target_id ) ) {
            return new WP_Error( 'forbidden', __( 'You are not allowed to insert this link.', 'dma-internlink-mapper' ) );
        }
        $source = get_post( $source_id );
        $target = get_post( $target_id );
        if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post || 'publish' !== $target->post_status || ! is_post_publicly_viewable( $target ) ) {
            return new WP_Error( 'unavailable', __( 'The source or destination is no longer available.', 'dma-internlink-mapper' ) );
        }
        if ( 'builder' !== get_post_meta( $source_id, '_elementor_edit_mode', true ) ) {
            return new WP_Error( 'not_elementor', __( 'This source is not a saved Elementor document.', 'dma-internlink-mapper' ) );
        }
        if ( class_exists( 'ILSM_Opportunity_Eligibility' ) ) {
            $source_eligibility = ILSM_Opportunity_Eligibility::validate( $source_id, 'source' );
            $target_eligibility = ILSM_Opportunity_Eligibility::validate( $target_id, 'destination' );
            if ( is_wp_error( $source_eligibility ) ) { return $source_eligibility; }
            if ( is_wp_error( $target_eligibility ) ) { return $target_eligibility; }
        }

        $url = esc_url_raw( get_permalink( $target_id ) );
        if ( ! $url ) {
            return new WP_Error( 'invalid_target', __( 'The destination URL is unavailable.', 'dma-internlink-mapper' ) );
        }
        $raw = get_post_meta( $source_id, '_elementor_data', true );
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return new WP_Error( 'missing_elementor_data', __( 'No saved Elementor data is available for this page.', 'dma-internlink-mapper' ) );
        }

        $budget = $this->validate_source_budget( $source_id, $anchor );
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }

        $lock = $this->acquire_lock( $source_id );
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        try {
            $request = (object) array(
                'source_post_id' => $source_id,
                'target_post_id' => $target_id,
                'anchor_text'    => $anchor,
            );
            $located = $this->locate_elementor( $raw, $request, $url );
            if ( is_wp_error( $located ) ) {
                return $located;
            }
            if ( ! empty( $located['already_linked'] ) ) {
                return new WP_Error( 'already_linked', __( 'The source already links to this destination.', 'dma-internlink-mapper' ) );
            }

            $revision_id = 0;
            $settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_create_revision' => 1 ) );
            if ( ! empty( $settings['insert_create_revision'] ) && wp_revisions_enabled( $source ) ) {
                $revision_id = (int) wp_save_post_revision( $source_id );
            }

            $before_hash = hash( 'sha256', (string) $source->post_content . '|' . (string) $raw );
            $saved = $this->save_located( $request, $located );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
            if ( ! $this->verify_inserted( $request, $located ) ) {
                $rollback = $this->restore_source_content( $request, $located );
                return is_wp_error( $rollback )
                    ? new WP_Error( 'verification_failed_rollback_failed', __( 'The link could not be verified and the original Elementor data could not be restored automatically.', 'dma-internlink-mapper' ) )
                    : new WP_Error( 'verification_failed_rolled_back', __( 'The link could not be verified, so the original Elementor data was restored.', 'dma-internlink-mapper' ) );
            }

            $after_raw  = (string) get_post_meta( $source_id, '_elementor_data', true );
            $after_hash = hash( 'sha256', (string) get_post_field( 'post_content', $source_id ) . '|' . $after_raw );
            $opportunity = (object) array(
                'scan_id'        => ILSM_Database::latest_completed_scan_id(),
                'id'             => 0,
                'source_post_id' => $source_id,
                'target_post_id' => $target_id,
                'anchor_text'    => $anchor,
            );
            $history_id = $this->finalize_success( $opportunity, $located, $before_hash, $after_hash, $revision_id );
            if ( is_wp_error( $history_id ) ) {
                $rollback = $this->restore_source_content( $request, $located );
                return is_wp_error( $rollback )
                    ? new WP_Error( 'finalization_failed_rollback_failed', __( 'The Elementor content was saved, but durable insertion history could not be finalized and the original Elementor data could not be restored automatically.', 'dma-internlink-mapper' ) )
                    : new WP_Error( 'finalization_failed_rolled_back', __( 'The Elementor content was saved, but durable insertion history could not be finalized. The original Elementor data was restored automatically.', 'dma-internlink-mapper' ) );
            }

            do_action( 'ilsm_elementor_link_inserted', $source_id, $target_id, $anchor, $located );
            return array(
                'status'      => 'inserted',
                'message'     => __( 'The link was inserted, saved and verified in Elementor.', 'dma-internlink-mapper' ),
                'revision_id' => $revision_id,
                'history_id'  => absint( $history_id ),
                'location'    => (string) ( $located['location'] ?? '' ),
                'element_id'  => (string) ( $located['element_id'] ?? '' ),
                'anchor'      => $anchor,
            );
        } finally {
            $this->release_lock( $source_id, $lock );
        }
    }


    /**
     * Calculate the real contextual internal-link budget for a source page.
     *
     * Only editable body text is counted. Headings, navigation, controls,
     * media captions, code and unsupported Elementor widgets are excluded.
     *
     * @param int    $source_id Source post ID.
     * @param string $anchor    Optional proposed anchor for distance checking.
     * @return array|WP_Error
     */
    public function source_metrics( $source_id, $anchor = '' ) {
        $source = get_post( absint( $source_id ) );
        if ( ! $source instanceof WP_Post ) {
            return new WP_Error( 'source_unavailable', __( 'The source post is no longer available.', 'dma-internlink-mapper' ) );
        }
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'dom_extension_missing', __( 'The PHP DOM extension is required for safe link-budget analysis.', 'dma-internlink-mapper' ) );
        }

        $fragments = array( (string) $source->post_content );
        $elementor = get_post_meta( $source->ID, '_elementor_data', true );
        if ( is_string( $elementor ) && '' !== trim( $elementor ) ) {
            $data = json_decode( $elementor, true );
            if ( ! is_array( $data ) ) {
                $data = json_decode( wp_unslash( $elementor ), true );
            }
            if ( is_array( $data ) ) {
                $this->collect_elementor_text_fragments( $data, $fragments );
            }
        }

        $word_count = 0;
        $link_positions = array();
        $candidate_positions = array();
        $internal_links = 0;
        foreach ( $fragments as $fragment ) {
            $analysis = $this->analyze_html_fragment( $fragment, $anchor, $word_count );
            $word_count += (int) $analysis['words'];
            $internal_links += (int) $analysis['internal_links'];
            $link_positions = array_merge( $link_positions, $analysis['link_positions'] );
            $candidate_positions = array_merge( $candidate_positions, $analysis['candidate_positions'] );
        }

        $settings = wp_parse_args(
            get_option( 'ilsm_settings', array() ),
            array(
                'insert_min_source_words'  => 300,
                'insert_min_word_distance' => 120,
                'insert_density_per_1000'  => 6,
            )
        );
        $density = max( 1, (int) $settings['insert_density_per_1000'] );
        $recommended = max( 2, (int) ceil( max( 1, $word_count ) / 1000 * $density ) );
        $remaining = max( 0, $recommended - $internal_links );
        $nearest = null;
        if ( $candidate_positions && $link_positions ) {
            foreach ( $candidate_positions as $candidate_position ) {
                foreach ( $link_positions as $link_position ) {
                    $distance = abs( (int) $candidate_position - (int) $link_position );
                    if ( null === $nearest || $distance < $nearest ) {
                        $nearest = $distance;
                    }
                }
            }
        }

        return array(
            'word_count'            => $word_count,
            'contextual_links'      => $internal_links,
            'recommended_max'       => $recommended,
            'remaining_budget'      => $remaining,
            'minimum_source_words'  => max( 50, (int) $settings['insert_min_source_words'] ),
            'minimum_word_distance' => max( 20, (int) $settings['insert_min_word_distance'] ),
            'nearest_link_distance' => $nearest,
        );
    }

    private function collect_elementor_text_fragments( $nodes, &$fragments ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            if ( isset( $node['widgetType'], $node['settings'] ) && is_array( $node['settings'] ) ) {
                foreach ( $this->elementor_text_controls( sanitize_key( (string) $node['widgetType'] ), $node['settings'] ) as $control ) {
                    if ( ! $this->elementor_value_is_dynamic( $node['settings'], $control['path'] ) && is_string( $control['value'] ) ) {
                        $fragments[] = $control['value'];
                    }
                }
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->collect_elementor_text_fragments( $node['elements'], $fragments );
            }
        }
    }

    private function unicode_word_count( $text ) {
        preg_match_all( '/[\\p{L}\\p{N}]+/u', html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $matches );
        return count( $matches[0] ?? array() );
    }

    private function analyze_html_fragment( $html, $anchor, $base_word_offset ) {
        $result = array( 'words' => 0, 'internal_links' => 0, 'link_positions' => array(), 'candidate_positions' => array() );
        if ( '' === trim( (string) $html ) ) { return $result; }
        $previous = libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="ilsm-budget-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) { return $result; }
        $root = $dom->getElementById( 'ilsm-budget-root' );
        if ( ! $root ) { return $result; }
        $excluded = array( 'h1','h2','h3','h4','h5','h6','button','nav','form','code','pre','script','style','figcaption','table','label','select','option','textarea' );
        $anchor_pattern = '';
        if ( '' !== trim( (string) $anchor ) ) {
            preg_match_all( '/[\\p{L}\\p{N}]+/u', wp_strip_all_tags( (string) $anchor ), $tokens );
            if ( ! empty( $tokens[0] ) ) {
                $anchor_pattern = '/(?<![\\p{L}\\p{N}])' . implode( '(?:[\\s\\x{00A0}\\p{P}\\p{S}]+)', array_map( static function( $token ) { return preg_quote( $token, '/' ); }, $tokens[0] ) ) . '(?![\\p{L}\\p{N}])/iu';
            }
        }
        $position = (int) $base_word_offset;
        $walker = function( $node ) use ( &$walker, &$result, &$position, $excluded, $anchor_pattern ) {
            if ( XML_ELEMENT_NODE === $node->nodeType ) {
                $name = strtolower( $node->nodeName );
                if ( in_array( $name, $excluded, true ) ) { return; }
                if ( 'a' === $name ) {
                    $href = $node->getAttribute( 'href' );
                    $url = ILSM_Link_Normalizer::normalize( $href, home_url( '/' ) );
                    $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
                    $link_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
                    $words = $this->unicode_word_count( $node->textContent );
                    if ( $url && $home_host && $home_host === $link_host ) {
                        $result['internal_links']++;
                        $result['link_positions'][] = $position;
                    }
                    $position += $words;
                    $result['words'] += $words;
                    return;
                }
            }
            if ( XML_TEXT_NODE === $node->nodeType ) {
                $text = (string) $node->nodeValue;
                if ( $anchor_pattern && preg_match_all( $anchor_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
                    foreach ( $matches[0] as $match ) {
                        $before = substr( $text, 0, (int) $match[1] );
                        $result['candidate_positions'][] = $position + $this->unicode_word_count( $before );
                    }
                }
                $words = $this->unicode_word_count( $text );
                $position += $words;
                $result['words'] += $words;
                return;
            }
            foreach ( $node->childNodes as $child ) { $walker( $child ); }
        };
        $walker( $root );
        return $result;
    }

    private function validate_source_budget( $source_id, $anchor ) {
        $metrics = $this->source_metrics( $source_id, $anchor );
        if ( is_wp_error( $metrics ) ) { return $metrics; }
        if ( (int) $metrics['word_count'] < (int) $metrics['minimum_source_words'] ) {
            return new WP_Error(
                'source_too_short',
                sprintf(
                    /* translators: 1: source words, 2: required words. */
                    __( 'The source has %1$d eligible body words. At least %2$d are required for automatic linking.', 'dma-internlink-mapper' ),
                    (int) $metrics['word_count'],
                    (int) $metrics['minimum_source_words']
                ),
                $metrics
            );
        }
        if ( (int) $metrics['remaining_budget'] < 1 ) {
            return new WP_Error(
                'link_budget_exhausted',
                sprintf(
                    /* translators: 1: current contextual links, 2: recommended maximum. */
                    __( 'The source already has %1$d contextual internal links, which meets its recommended maximum of %2$d.', 'dma-internlink-mapper' ),
                    (int) $metrics['contextual_links'],
                    (int) $metrics['recommended_max']
                ),
                $metrics
            );
        }
        if ( null !== $metrics['nearest_link_distance'] && (int) $metrics['nearest_link_distance'] < (int) $metrics['minimum_word_distance'] ) {
            return new WP_Error(
                'link_too_close',
                sprintf(
                    /* translators: 1: actual word distance, 2: minimum word distance. */
                    __( 'The proposed anchor is only %1$d words from an existing contextual internal link. The configured minimum is %2$d words.', 'dma-internlink-mapper' ),
                    (int) $metrics['nearest_link_distance'],
                    (int) $metrics['minimum_word_distance']
                ),
                $metrics
            );
        }
        return $metrics;
    }

    /**
     * Prove that a generated opportunity is insertable before it is stored.
     *
     * This read-only preflight uses the exact same editor adapters and DOM
     * eligibility rules as Preview and Live insertion. It never writes content,
     * creates history, or acquires an insertion lock.
     *
     * @param int    $source_id Source post ID.
     * @param int    $target_id Destination post ID.
     * @param string $anchor    Proposed visible anchor text.
     * @return array|WP_Error Located insertion data or a precise rejection.
     */
    public function validate_candidate( $source_id, $target_id, $anchor ) {
        $source_id = absint( $source_id );
        $target_id = absint( $target_id );
        $anchor    = sanitize_text_field( (string) $anchor );

        if ( ! $source_id || ! $target_id || $source_id === $target_id || '' === $anchor ) {
            return new WP_Error( 'invalid_candidate', __( 'The proposed source, destination, or anchor is invalid.', 'dma-internlink-mapper' ) );
        }

        $source = get_post( $source_id );
        $target = get_post( $target_id );
        if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post ) {
            return new WP_Error( 'invalid_posts', __( 'The source or destination is unavailable.', 'dma-internlink-mapper' ) );
        }
        if ( 'publish' !== $source->post_status || 'publish' !== $target->post_status || ! is_post_publicly_viewable( $target ) ) {
            return new WP_Error( 'target_not_public', __( 'The source and destination must be publicly available.', 'dma-internlink-mapper' ) );
        }

        $candidate = (object) array(
            'source_post_id' => $source_id,
            'target_post_id' => $target_id,
            'anchor_text'    => $anchor,
        );
        $located = $this->locate( $candidate, false );
        if ( is_wp_error( $located ) ) {
            return $located;
        }
        if ( ! empty( $located['already_linked'] ) ) {
            return new WP_Error( 'already_linked', __( 'The source already links to this destination.', 'dma-internlink-mapper' ) );
        }

        $metrics = $this->validate_source_budget( $source_id, $anchor );
        if ( is_wp_error( $metrics ) ) { return $metrics; }
        $located['source_metrics'] = $metrics;
        return $located;
    }

    public function preview( $opportunity_id ) {
        $opportunity = $this->load_opportunity( $opportunity_id );
        if ( is_wp_error( $opportunity ) ) { return $opportunity; }
        $permission = $this->check_permission( $opportunity );
        if ( is_wp_error( $permission ) ) { return $permission; }
        $located = $this->locate( $opportunity, false );
        if ( is_wp_error( $located ) ) { return $located; }
        $metrics = $this->validate_source_budget( (int) $opportunity->source_post_id, (string) $opportunity->anchor_text );
        if ( is_wp_error( $metrics ) ) { return $metrics; }
        $located['source_metrics'] = $metrics;

        $settings   = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_min_confidence' => 70 ) );
        $score      = (int) $opportunity->score;
        $minimum    = max( 0, min( 100, (int) $settings['insert_min_confidence'] ) );
        $insertable = $score >= $minimum && empty( $located['already_linked'] );
        $reason     = '';
        $reason_message = '';
        if ( ! empty( $located['already_linked'] ) ) {
            $reason = 'already_linked';
            $reason_message = __( 'The source already links to this destination.', 'dma-internlink-mapper' );
        } elseif ( $score < $minimum ) {
            $reason = 'confidence_too_low';
            $reason_message = sprintf(
                /* translators: 1: current confidence, 2: required confidence. */
                __( 'Current confidence is %1$d%%. The required minimum is %2$d%%.', 'dma-internlink-mapper' ),
                $score,
                $minimum
            );
        }

        $snapshot_token = '';
        if ( $insertable ) {
            $snapshot_token = wp_generate_password( 32, false, false );
            set_transient(
                $this->snapshot_key( $snapshot_token ),
                array(
                    'user_id'          => get_current_user_id(),
                    'opportunity_id'   => (int) $opportunity->id,
                    'source_post_id'   => (int) $opportunity->source_post_id,
                    'target_post_id'   => (int) $opportunity->target_post_id,
                    'score'            => $score,
                    'minimum'          => $minimum,
                    'content_hash'     => $this->source_content_hash( (int) $opportunity->source_post_id ),
                    'location_hash'    => (string) ( $located['location_hash'] ?? '' ),
                    'content_location' => $located['location'] ?? '',
                    'created_at'       => time(),
                ),
                self::SNAPSHOT_TTL
            );
        }

        return array(
            'opportunity_id'   => (int) $opportunity->id,
            'source_id'        => (int) $opportunity->source_post_id,
            'source_title'     => wp_specialchars_decode( get_the_title( $opportunity->source_post_id ), ENT_QUOTES ),
            'target_id'        => (int) $opportunity->target_post_id,
            'target_title'     => wp_specialchars_decode( get_the_title( $opportunity->target_post_id ), ENT_QUOTES ),
            'destination_url'  => get_permalink( $opportunity->target_post_id ),
            'source_edit_url'  => get_edit_post_link( $opportunity->source_post_id, 'raw' ),
            'source_view_url'  => get_permalink( $opportunity->source_post_id ),
            'anchor'           => $opportunity->anchor_text,
            'paragraph_html'   => $located['preview_html'],
            'editor_type'      => $located['editor_type'],
            'content_location' => $located['location'],
            'location_hash'    => $located['location_hash'],
            'score'            => $score,
            'contextual_score' => absint( $opportunity->context_score ?? $score ),
            'strategy_score'   => absint( $opportunity->strategy_score ?? 50 ),
            'score_reason'     => sanitize_text_field( (string) ( $opportunity->reason ?? '' ) ),
            'minimum_confidence' => $minimum,
            'insertable'       => $insertable,
            'reason_code'      => $reason,
            'reason_message'   => $reason_message,
            'snapshot_token'   => $snapshot_token,
            'snapshot_expires' => time() + self::SNAPSHOT_TTL,
            'already_linked'   => $located['already_linked'],
            'warnings'         => $located['warnings'],
            'state'            => $located['state'],
            'source_metrics'   => $located['source_metrics'],
        );
    }

    public function insert( $opportunity_id, $dry_run = false, $snapshot_token = '' ) {
        $opportunity = $this->load_opportunity( $opportunity_id );
        if ( is_wp_error( $opportunity ) ) { return $opportunity; }
        $permission = $this->check_permission( $opportunity );
        if ( is_wp_error( $permission ) ) { return $permission; }
        $settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_min_confidence' => 70, 'insert_create_revision' => 1 ) );
        $snapshot = $this->validate_snapshot( $snapshot_token, $opportunity );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        $lock = $this->acquire_lock( (int) $opportunity->source_post_id );
        if ( is_wp_error( $lock ) ) { return $lock; }
        try {
            $located = $this->locate( $opportunity, true );
            if ( is_wp_error( $located ) ) { if ( ! $dry_run ) { $this->record_failure( $opportunity, $located ); } return $located; }
            if ( ! hash_equals( (string) $snapshot['location_hash'], (string) ( $located['location_hash'] ?? '' ) ) ) {
                return new WP_Error( 'content_changed', __( 'The source content changed after the preview. Review the opportunity again before inserting.', 'dma-internlink-mapper' ) );
            }
            if ( ! empty( $located['already_linked'] ) ) { return new WP_Error( 'already_linked', __( 'The source already links to this destination.', 'dma-internlink-mapper' ) ); }
            $post = get_post( $opportunity->source_post_id );
            if ( ! $post instanceof WP_Post ) {
                return new WP_Error( 'source_unavailable', __( 'The source post is no longer available.', 'dma-internlink-mapper' ) );
            }
            $metrics = $this->validate_source_budget( (int) $post->ID, (string) $opportunity->anchor_text );
            if ( is_wp_error( $metrics ) ) { return $metrics; }
            if ( $dry_run ) {
                return array( 'status' => 'dry_run_passed', 'dry_run' => true, 'message' => __( 'Preview passed. The link can be inserted safely, but no content was modified.', 'dma-internlink-mapper' ), 'preview' => $located );
            }
            $before_hash = hash( 'sha256', (string) $post->post_content . '|' . (string) get_post_meta( $post->ID, '_elementor_data', true ) );
            $revision_id = 0;
            if ( ! empty( $settings['insert_create_revision'] ) && wp_revisions_enabled( $post ) ) {
                $revision_id = (int) wp_save_post_revision( $post->ID );
            }
            $save = $this->save_located( $opportunity, $located );
            if ( is_wp_error( $save ) ) { $this->record_failure( $opportunity, $save ); return $save; }
            clean_post_cache( $post->ID );
            $verified = $this->verify_inserted( $opportunity, $located );
            if ( ! $verified ) {
                $rollback = $this->restore_source_content( $opportunity, $located );
                $error = is_wp_error( $rollback )
                    ? new WP_Error( 'verification_failed_rollback_failed', __( 'WordPress saved the content, but the inserted link could not be verified and the automatic rollback also failed. Review the source revision immediately.', 'dma-internlink-mapper' ) )
                    : new WP_Error( 'verification_failed_rolled_back', __( 'The inserted link could not be verified, so the source content was restored automatically.', 'dma-internlink-mapper' ) );
                $this->record_failure( $opportunity, $error );
                return $error;
            }
            $after_post = get_post( $post->ID );
            $after_hash = hash( 'sha256', (string) $after_post->post_content . '|' . (string) get_post_meta( $post->ID, '_elementor_data', true ) );
            $finalized = $this->finalize_success( $opportunity, $located, $before_hash, $after_hash, $revision_id );
            if ( is_wp_error( $finalized ) ) {
                $rollback = $this->restore_source_content( $opportunity, $located );
                $error = is_wp_error( $rollback )
                    ? new WP_Error(
                        'finalization_failed_rollback_failed',
                        __( 'The link was saved, but its durable insertion history could not be finalized and the original source content could not be restored automatically. Review the source revision before retrying.', 'dma-internlink-mapper' )
                    )
                    : new WP_Error(
                        'finalization_failed_rolled_back',
                        __( 'The link was saved, but its durable insertion history could not be finalized. The original source content was restored automatically and no success was recorded.', 'dma-internlink-mapper' )
                    );
                $this->record_failure( $opportunity, $error );
                return $error;
            }
            $history_id = (int) $finalized;
            delete_transient( $this->snapshot_key( $snapshot_token ) );
            return array(
                'status'       => 'inserted',
                'history_id'   => $history_id,
                'message'      => __( 'The link is now live. It was inserted, saved, and verified successfully.', 'dma-internlink-mapper' ),
                'source_edit_url' => get_edit_post_link( $opportunity->source_post_id, 'raw' ),
                'source_view_url' => get_permalink( $opportunity->source_post_id ),
                'destination_url' => get_permalink( $opportunity->target_post_id ),
                'source_title'    => wp_specialchars_decode( get_the_title( $opportunity->source_post_id ), ENT_QUOTES ),
                'target_title'    => wp_specialchars_decode( get_the_title( $opportunity->target_post_id ), ENT_QUOTES ),
                'anchor'          => $opportunity->anchor_text,
                'editor_type'     => $located['editor_type'],
                'content_location'=> $located['location'],
                'history'      => array(
                    'id'          => $history_id,
                    'when'        => current_time( 'mysql' ),
                    'user'        => wp_get_current_user()->display_name,
                    'source'      => wp_specialchars_decode( get_the_title( $opportunity->source_post_id ), ENT_QUOTES ),
                    'anchor'      => $opportunity->anchor_text,
                    'destination' => wp_specialchars_decode( get_the_title( $opportunity->target_post_id ), ENT_QUOTES ),
                    'editor'      => $located['editor_type'],
                    'result'      => 'inserted',
                    'source_edit_url' => get_edit_post_link( $opportunity->source_post_id, 'raw' ),
                    'source_view_url' => get_permalink( $opportunity->source_post_id ),
                    'destination_url' => get_permalink( $opportunity->target_post_id ),
                    'is_live'         => 'publish' === get_post_status( $opportunity->source_post_id ),
                ),
            );
        } finally {
            $this->release_lock( (int) $opportunity->source_post_id, $lock );
        }
    }

    public function undo( $history_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable history must be read fresh.
        $history = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::history_table(), absint( $history_id ) ) );
        if ( ! $history || 'inserted' !== $history->insertion_status ) {
            return new WP_Error( 'invalid_history', __( 'This insertion cannot be undone.', 'dma-internlink-mapper' ) );
        }
        if ( ! current_user_can( 'ilsm_insert_links' ) || ! current_user_can( 'edit_post', $history->source_post_id ) ) {
            return new WP_Error( 'forbidden', __( 'You are not allowed to undo this insertion.', 'dma-internlink-mapper' ) );
        }

        $lock = $this->acquire_lock( (int) $history->source_post_id );
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        try {
            $post = get_post( $history->source_post_id );
            if ( ! $post instanceof WP_Post ) {
                return new WP_Error( 'source_missing', __( 'The source post no longer exists.', 'dma-internlink-mapper' ) );
            }
            $is_elementor = 'elementor' === (string) $history->editor_type;
            $pre_undo_content = $is_elementor
                ? (string) get_post_meta( $post->ID, '_elementor_data', true )
                : (string) $post->post_content;

            $result = $this->remove_exact_link( $history );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $finalized = $this->finalize_undo_state( $history );
            if ( is_wp_error( $finalized ) ) {
                $restored = $this->restore_storage_value( $post->ID, $is_elementor, $pre_undo_content );
                if ( is_wp_error( $restored ) ) {
                    return new WP_Error(
                        'undo_state_failed_restore_failed',
                        __( 'The link markup was removed, but WordPress could not finalize the undo state or restore the previous content. Review the source revision immediately.', 'dma-internlink-mapper' )
                    );
                }
                return new WP_Error(
                    'undo_state_failed_restored',
                    __( 'WordPress could not finalize the undo history, so the original inserted content was restored and the undo was not reported as successful.', 'dma-internlink-mapper' )
                );
            }

            return array(
                'status'  => 'undone',
                'message' => __( 'The link markup was removed, the anchor text was preserved, and the undo was recorded.', 'dma-internlink-mapper' ),
            );
        } finally {
            $this->release_lock( (int) $history->source_post_id, $lock );
        }
    }

    private function snapshot_key( $token ) {
        return 'ilsm_insert_snapshot_' . hash( 'sha256', (string) $token );
    }

    private function source_content_hash( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) { return ''; }
        return hash( 'sha256', (string) $post->post_content . '|' . (string) get_post_meta( $post_id, '_elementor_data', true ) );
    }

    private function validate_snapshot( $token, $opportunity ) {
        $token = sanitize_text_field( (string) $token );
        if ( '' === $token ) {
            return new WP_Error( 'preview_required', __( 'Review this opportunity again before inserting it.', 'dma-internlink-mapper' ) );
        }
        $snapshot = get_transient( $this->snapshot_key( $token ) );
        if ( ! is_array( $snapshot ) ) {
            return new WP_Error( 'preview_expired', __( 'The insertion preview expired. Review the opportunity again.', 'dma-internlink-mapper' ) );
        }
        if ( (int) ( $snapshot['user_id'] ?? 0 ) !== get_current_user_id() || (int) ( $snapshot['opportunity_id'] ?? 0 ) !== (int) $opportunity->id ) {
            return new WP_Error( 'invalid_preview', __( 'The insertion preview does not belong to this opportunity or user.', 'dma-internlink-mapper' ) );
        }
        if ( (int) ( $snapshot['source_post_id'] ?? 0 ) !== (int) $opportunity->source_post_id || (int) ( $snapshot['target_post_id'] ?? 0 ) !== (int) $opportunity->target_post_id ) {
            return new WP_Error( 'invalid_preview', __( 'The source or destination changed after preview.', 'dma-internlink-mapper' ) );
        }
        if ( (int) ( $snapshot['score'] ?? -1 ) < (int) ( $snapshot['minimum'] ?? 101 ) ) {
            return new WP_Error( 'confidence_too_low', __( 'This opportunity did not meet the minimum confidence threshold when it was reviewed.', 'dma-internlink-mapper' ) );
        }
        $current_hash = $this->source_content_hash( (int) $opportunity->source_post_id );
        if ( '' === $current_hash || ! hash_equals( (string) ( $snapshot['content_hash'] ?? '' ), $current_hash ) ) {
            return new WP_Error( 'content_changed', __( 'The source content changed after the preview. Review the opportunity again before inserting.', 'dma-internlink-mapper' ) );
        }
        return $snapshot;
    }

    private function load_opportunity( $id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable opportunities must be read fresh.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', ILSM_Link_Opportunities::table(), absint( $id ) ) );
        if ( ! $row ) { return new WP_Error( 'not_found', __( 'Opportunity not found.', 'dma-internlink-mapper' ) ); }
        if ( (int) $row->scan_id !== ILSM_Database::latest_completed_scan_id() ) { return new WP_Error( 'stale_opportunity', __( 'This opportunity belongs to an older scan. Generate fresh opportunities.', 'dma-internlink-mapper' ) ); }
        return $row;
    }

    private function check_permission( $o ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_insert_links' ) || ! current_user_can( 'edit_post', $o->source_post_id ) || ! current_user_can( 'read_post', $o->target_post_id ) ) {
            return new WP_Error( 'forbidden', __( 'You are not allowed to modify this source post.', 'dma-internlink-mapper' ) );
        }

        $source = get_post( $o->source_post_id );
        $target = get_post( $o->target_post_id );
        if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post || $source->ID === $target->ID ) {
            return new WP_Error( 'invalid_posts', __( 'The source or destination is invalid.', 'dma-internlink-mapper' ) );
        }

        $settings = get_option( 'ilsm_settings', array() );
        if ( ! in_array( $source->post_type, (array) ( $settings['post_types'] ?? ILSM_Activator::default_post_types() ), true ) ) {
            return new WP_Error( 'post_type_disabled', __( 'The source post type is not enabled in plugin settings.', 'dma-internlink-mapper' ) );
        }

        // Revalidate both ends immediately before mutation. Opportunities can
        // become stale after generation when SEO metadata, visibility, privacy
        // assignments, or plugin eligibility settings change.
        if ( class_exists( 'ILSM_Opportunity_Eligibility' ) ) {
            $source_eligibility = ILSM_Opportunity_Eligibility::validate( $source, 'source' );
            if ( is_wp_error( $source_eligibility ) ) {
                return new WP_Error( 'source_no_longer_eligible', $source_eligibility->get_error_message() );
            }
            $target_eligibility = ILSM_Opportunity_Eligibility::validate( $target, 'destination' );
            if ( is_wp_error( $target_eligibility ) ) {
                return new WP_Error( 'target_no_longer_eligible', $target_eligibility->get_error_message() );
            }
        } elseif ( 'publish' !== $target->post_status || post_password_required( $target ) ) {
            return new WP_Error( 'target_not_public', __( 'The destination is not publicly available.', 'dma-internlink-mapper' ) );
        }

        return true;
    }

    private function locate( $o, $for_insert ) {
        $post = get_post( $o->source_post_id );
        $url = get_permalink( $o->target_post_id );
        if ( ! $post || ! $url ) { return new WP_Error( 'invalid_content', __( 'Source content or destination URL is unavailable.', 'dma-internlink-mapper' ) ); }
        $editor = ILSM_Editor_Adapter_Registry::detect( $post );
        if ( 'elementor' === $editor ) {
            $elementor = get_post_meta( $post->ID, '_elementor_data', true );
            return $this->locate_elementor( $elementor, $o, $url );
        }
        if ( 'gutenberg' === $editor ) { return $this->locate_blocks( $post->post_content, $o, $url ); }
        $located = $this->locate_html( $post->post_content, $o, $url, 'classic', 'post_content' );
        if ( ! is_wp_error( $located ) ) {
            $located['source_content'] = (string) $post->post_content;
        }
        return $located;
    }

    private function locate_blocks( $content, $o, $url ) {
        $blocks = parse_blocks( $content );
        $matches = array();
        $this->walk_blocks( $blocks, $o->anchor_text, $url, $matches, array() );
        if ( empty( $matches ) ) {
            return new WP_Error( 'anchor_missing', __( 'The anchor is no longer present in eligible body text.', 'dma-internlink-mapper' ) );
        }
        foreach ( $matches as $match ) {
            if ( ! empty( $match['already_linked'] ) ) {
                return array_merge( array( 'editor_type' => 'gutenberg', 'source_content' => $content, 'blocks' => $blocks ), $match );
            }
        }
        usort( $matches, static function( $a, $b ) { return (int) ( $b['match_score'] ?? 0 ) <=> (int) ( $a['match_score'] ?? 0 ); } );
        $match = $matches[0];
        $warnings = array();
        if ( count( $matches ) > 1 ) {
            $warnings[] = __( 'The anchor appears in multiple blocks. The safest contextual occurrence was selected.', 'dma-internlink-mapper' );
        }
        return array_merge( array( 'editor_type' => 'gutenberg', 'source_content' => $content, 'blocks' => $blocks, 'state' => 'ready', 'warnings' => $warnings ), $match );
    }

    private function walk_blocks( &$blocks, $anchor, $url, &$matches, $path ) {
        $allowed = array( 'core/paragraph', 'core/list-item', 'core/verse', 'core/pullquote' );
        foreach ( $blocks as $i => &$block ) {
            $here = array_merge( $path, array( $i ) );
            if ( in_array( $block['blockName'], $allowed, true ) && ! empty( $block['innerHTML'] ) ) {
                $found = $this->locate_html( $block['innerHTML'], (object) array( 'anchor_text' => $anchor ), $url, 'gutenberg', 'block:' . implode( '.', $here ) );
                if ( ! is_wp_error( $found ) ) {
                    $found['block_path'] = $here;
                    $matches[] = $found;
                }
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                $this->walk_blocks( $block['innerBlocks'], $anchor, $url, $matches, $here );
            }
        }
    }

    private function locate_elementor( $json, $o, $url ) {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'unsupported_elementor', __( 'Elementor data could not be decoded safely.', 'dma-internlink-mapper' ) );
        }
        $matches = array();
        $this->walk_elementor( $data, $o->anchor_text, $url, $matches );
        if ( empty( $matches ) ) {
            return new WP_Error( 'unsupported_elementor', __( 'No supported Elementor text widget contains the anchor.', 'dma-internlink-mapper' ) );
        }
        foreach ( $matches as $match ) {
            if ( ! empty( $match['already_linked'] ) ) {
                $match['elementor_data'] = $data;
                $match['source_content'] = $json;
                return $match;
            }
        }
        usort( $matches, static function( $a, $b ) { return (int) ( $b['match_score'] ?? 0 ) <=> (int) ( $a['match_score'] ?? 0 ); } );
        $match = $matches[0];
        $match['elementor_data'] = $data;
        $match['source_content'] = $json;
        $match['state'] = 'ready';
        $match['warnings'] = $match['warnings'] ?? array();
        if ( count( $matches ) > 1 ) {
            $match['warnings'][] = __( 'The anchor appears in multiple Elementor widgets. The safest contextual occurrence was selected.', 'dma-internlink-mapper' );
        }
        return $match;
    }

    /**
     * Return safe, visible Elementor text controls for a widget.
     *
     * Headings, buttons, URLs, CSS, IDs and dynamic values are deliberately
     * excluded. Repeater content is represented by a precise setting path so
     * only the reviewed control is changed during save.
     *
     * @param string $widget_type Elementor widget type.
     * @param array  $settings    Widget settings.
     * @return array<int,array{path:array,value:string,label:string}>
     */
    /**
     * Return Elementor text controls that are safe for automatic insertion.
     *
     * DMA InternLink Mapper does not guess custom-widget field names. It asks Elementor for the
     * registered controls of the current widget and only accepts controls whose
     * declared type is `wysiwyg` or `textarea`. This lets theme/add-on widgets
     * participate safely without hard-coding their widget names.
     *
     * @param string $widget_type Elementor widget type.
     * @param array  $settings    Saved widget settings.
     * @return array<int,array{path:array,value:string,label:string,type:string}>
     */
    private function elementor_text_controls( $widget_type, $settings ) {
        return ILSM_Elementor_Controls::text_controls( $widget_type, $settings );
    }

    /** Whether this Elementor node belongs to non-body/site-chrome content. */
    private function elementor_node_is_non_body( $node, $depth, $index ) {
        return ILSM_Elementor_Controls::node_is_non_body( $node, $depth, $index );
    }

    private function elementor_get_path( $data, $path ) {
        foreach ( (array) $path as $key ) {
            if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) { return null; }
            $data = $data[ $key ];
        }
        return $data;
    }

    private function elementor_set_path( &$data, $path, $value ) {
        $ref =& $data;
        foreach ( (array) $path as $index => $key ) {
            if ( $index === count( $path ) - 1 ) {
                if ( ! is_array( $ref ) || ! array_key_exists( $key, $ref ) ) { return false; }
                $ref[ $key ] = $value;
                return true;
            }
            if ( ! is_array( $ref ) || ! array_key_exists( $key, $ref ) || ! is_array( $ref[ $key ] ) ) { return false; }
            $ref =& $ref[ $key ];
        }
        return false;
    }

    /**
     * Encode an Elementor element/control path into a compact history location.
     *
     * The token is plugin-owned metadata only. If an unusually deep repeater path
     * would exceed the existing VARCHAR(190), fall back to the legacy location and
     * let safe undo discover the unique marker without truncating structured data.
     */
    private function elementor_location_token( $element_id, $setting_path ) {
        $element_id = sanitize_key( (string) $element_id );
        $json = wp_json_encode( array_values( (array) $setting_path ) );
        if ( ! is_string( $json ) || '' === $json ) {
            return 'widget:' . $element_id;
        }
        $encoded = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
        $token = 'widget:' . $element_id . ':path:' . $encoded;
        if ( strlen( $token ) > 180 ) {
            return 'widget:' . $element_id;
        }
        return $token;
    }

    /** Decode a precise Elementor history location when one is available. */
    private function elementor_location_parts( $location ) {
        $location = (string) $location;
        if ( ! preg_match( '/^widget:([a-z0-9_-]+):path:([A-Za-z0-9_-]+)$/', $location, $matches ) ) {
            return null;
        }
        $encoded = (string) $matches[2];
        $padding = strlen( $encoded ) % 4;
        if ( $padding ) {
            $encoded .= str_repeat( '=', 4 - $padding );
        }
        $decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );
        $path = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
        if ( ! is_array( $path ) || empty( $path ) ) {
            return null;
        }
        foreach ( $path as $key ) {
            if ( ! is_string( $key ) && ! is_int( $key ) ) {
                return null;
            }
        }
        return array(
            'element_id'   => sanitize_key( (string) $matches[1] ),
            'setting_path' => array_values( $path ),
        );
    }

    private function elementor_value_is_dynamic( $settings, $path ) {
        return ILSM_Elementor_Controls::is_dynamic( $settings, $path );
    }

    private function walk_elementor( &$elements, $anchor, $url, &$matches, $depth = 0 ) {
        $position = 0;
        foreach ( $elements as &$el ) {
            if ( ! is_array( $el ) ) { $position++; continue; }
            if ( $this->elementor_node_is_non_body( $el, $depth, $position ) ) { $position++; continue; }
            if ( isset( $el['widgetType'], $el['settings'] ) && is_array( $el['settings'] ) ) {
                $widget_type = sanitize_key( (string) $el['widgetType'] );
                foreach ( $this->elementor_text_controls( $widget_type, $el['settings'] ) as $control ) {
                    if ( $this->elementor_value_is_dynamic( $el['settings'], $control['path'] ) ) { continue; }
                    $found = $this->locate_html(
                        $control['value'],
                        (object) array( 'anchor_text' => $anchor ),
                        $url,
                        'elementor',
                        'widget:' . sanitize_key( (string) ( $el['id'] ?? '' ) ) . ':' . sanitize_key( (string) ( $control['path'][0] ?? '' ) )
                    );
                    if ( ! is_wp_error( $found ) ) {
                        $found['element_id'] = (string) ( $el['id'] ?? '' );
                        $found['setting_path'] = $control['path'];
                        $found['setting_key'] = (string) ( $control['path'][0] ?? '' );
                        $found['location'] = $this->elementor_location_token( (string) ( $el['id'] ?? '' ), (array) $control['path'] );
                        $found['control_type'] = (string) ( $control['type'] ?? '' );
                        $found['control_label'] = (string) ( $control['label'] ?? ( $control['path'][0] ?? '' ) );
                        $found['widget_type'] = $widget_type;
                        $matches[] = $found;
                    }
                }
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $this->walk_elementor( $el['elements'], $anchor, $url, $matches, $depth + 1 );
            }
            $position++;
        }
    }

    /** Resolve a safe Elementor textarea/WYSIWYG location without changing content. */
    public function preview_elementor_location( $source_id, $target_id, $anchor ) {
        $source_id = absint( $source_id );
        $target_id = absint( $target_id );
        $anchor = trim( sanitize_text_field( (string) $anchor ) );
        if ( ! $source_id || ! $target_id || '' === $anchor || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'read_post', $target_id ) ) {
            return new WP_Error( 'invalid_request', __( 'The preview request is invalid.', 'dma-internlink-mapper' ) );
        }
        $url = esc_url_raw( get_permalink( $target_id ) );
        $raw = get_post_meta( $source_id, '_elementor_data', true );
        if ( ! $url || ! is_string( $raw ) || '' === trim( $raw ) ) {
            return new WP_Error( 'missing_elementor_data', __( 'No saved Elementor content is available for preview.', 'dma-internlink-mapper' ) );
        }
        $request = (object) array( 'source_post_id' => $source_id, 'target_post_id' => $target_id, 'anchor_text' => $anchor );
        $located = $this->locate_elementor( $raw, $request, $url );
        if ( is_wp_error( $located ) ) { return $located; }
        if ( ! empty( $located['already_linked'] ) ) {
            return new WP_Error( 'already_linked', __( 'The source already links to this destination.', 'dma-internlink-mapper' ) );
        }
        $setting_path  = array_values( (array) ( $located['setting_path'] ?? array() ) );
        $original_html = (string) ( $located['html'] ?? '' );
        $finalize_token = wp_generate_password( 40, false, false );
        set_transient(
            'ilsm_elementor_finalize_' . hash( 'sha256', $finalize_token ),
            array(
                'user_id'              => get_current_user_id(),
                'source_post_id'       => $source_id,
                'target_post_id'       => $target_id,
                'anchor'               => sanitize_text_field( (string) ( $located['matched_anchor'] ?? $anchor ) ),
                'target_url'           => esc_url_raw( get_permalink( $target_id ) ),
                'element_id'           => (string) ( $located['element_id'] ?? '' ),
                'setting_path'         => $setting_path,
                'location'             => (string) ( $located['location'] ?? '' ),
                'original_html_hash'   => hash( 'sha256', $original_html ),
                'source_before_hash'   => hash( 'sha256', (string) $raw ),
                'created_at'           => time(),
            ),
            self::SNAPSHOT_TTL
        );

        return array(
            'element_id'     => (string) ( $located['element_id'] ?? '' ),
            'widget_type'    => (string) ( $located['widget_type'] ?? '' ),
            'setting_path'   => $setting_path,
            'setting_key'    => (string) ( $located['setting_key'] ?? ( $located['setting_path'][0] ?? '' ) ),
            'control_type'   => (string) ( $located['control_type'] ?? '' ),
            'control_label'  => (string) ( $located['control_label'] ?? '' ),
            'location'       => (string) ( $located['location'] ?? '' ),
            'anchor'         => $anchor,
            'matched_anchor' => sanitize_text_field( (string) ( $located['matched_anchor'] ?? $anchor ) ),
            'original_html'  => $original_html,
            'new_html'       => (string) ( $located['new_html'] ?? '' ),
            'target_url'     => esc_url_raw( get_permalink( $target_id ) ),
            'finalize_token' => $finalize_token,
        );
    }

    /**
     * Finalize an Elementor editor insertion only after the exact saved control
     * contains the plugin marker link. This turns the editor-side save into a
     * durable auditable insertion without rewriting Elementor data from PHP.
     *
     * @param string $token Opaque preview/finalization token.
     * @return array|WP_Error
     */
    public function finalize_elementor_editor_insertion( $token ) {
        $token = sanitize_text_field( (string) $token );
        if ( '' === $token ) {
            return new WP_Error( 'finalize_token_missing', __( 'The Elementor insertion verification token is missing.', 'dma-internlink-mapper' ) );
        }

        $snapshot = get_transient( 'ilsm_elementor_finalize_' . hash( 'sha256', $token ) );
        if ( ! is_array( $snapshot ) || absint( $snapshot['user_id'] ?? 0 ) !== get_current_user_id() ) {
            return new WP_Error( 'finalize_token_invalid', __( 'The Elementor insertion verification expired or does not belong to this user.', 'dma-internlink-mapper' ) );
        }

        $source_id = absint( $snapshot['source_post_id'] ?? 0 );
        $target_id = absint( $snapshot['target_post_id'] ?? 0 );
        $anchor    = sanitize_text_field( (string) ( $snapshot['anchor'] ?? '' ) );
        $url       = esc_url_raw( (string) ( $snapshot['target_url'] ?? '' ) );
        $element_id = sanitize_text_field( (string) ( $snapshot['element_id'] ?? '' ) );
        $setting_path = array_values( (array) ( $snapshot['setting_path'] ?? array() ) );

        if ( ! $source_id || ! $target_id || '' === $anchor || '' === $url || '' === $element_id || empty( $setting_path ) ) {
            return new WP_Error( 'finalize_snapshot_invalid', __( 'The Elementor insertion verification data is incomplete.', 'dma-internlink-mapper' ) );
        }
        if ( ! current_user_can( 'ilsm_insert_links' ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'read_post', $target_id ) ) {
            return new WP_Error( 'forbidden', __( 'You are not allowed to finalize this insertion.', 'dma-internlink-mapper' ) );
        }

        $source_eligibility = ILSM_Opportunity_Eligibility::validate( $source_id, 'source' );
        $target_eligibility = ILSM_Opportunity_Eligibility::validate( $target_id, 'destination' );
        if ( is_wp_error( $source_eligibility ) ) { return $source_eligibility; }
        if ( is_wp_error( $target_eligibility ) ) { return $target_eligibility; }

        $raw = (string) get_post_meta( $source_id, '_elementor_data', true );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            $data = json_decode( wp_unslash( $raw ), true );
        }
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'elementor_data_invalid', __( 'Saved Elementor data could not be decoded for verification.', 'dma-internlink-mapper' ) );
        }

        $saved_html = $this->elementor_control_value_by_id( $data, $element_id, $setting_path );
        if ( ! is_string( $saved_html ) || ! $this->html_has_verified_link( $saved_html, $url, $anchor ) ) {
            return new WP_Error( 'elementor_exact_verification_failed', __( 'The exact Elementor control does not contain the verified inserted link.', 'dma-internlink-mapper' ) );
        }
        if ( hash_equals( (string) ( $snapshot['original_html_hash'] ?? '' ), hash( 'sha256', $saved_html ) ) ) {
            return new WP_Error( 'elementor_no_change', __( 'The Elementor control did not change after insertion.', 'dma-internlink-mapper' ) );
        }

        $opportunity = (object) array(
            'scan_id'        => ILSM_Database::latest_completed_scan_id(),
            'id'             => 0,
            'source_post_id' => $source_id,
            'target_post_id' => $target_id,
            'anchor_text'    => $anchor,
        );
        $located = array(
            'editor_type'   => 'elementor',
            'location'      => sanitize_text_field( (string) ( $snapshot['location'] ?? ( 'elementor:' . $element_id ) ) ),
            'location_hash' => sanitize_text_field( (string) ( $snapshot['original_html_hash'] ?? '' ) ),
        );
        $history_id = $this->finalize_success(
            $opportunity,
            $located,
            sanitize_text_field( (string) ( $snapshot['source_before_hash'] ?? '' ) ),
            hash( 'sha256', $raw ),
            0
        );
        if ( is_wp_error( $history_id ) ) {
            return $history_id;
        }

        delete_transient( 'ilsm_elementor_finalize_' . hash( 'sha256', $token ) );
        return array(
            'status'       => 'inserted',
            'history_id'   => absint( $history_id ),
            'source_id'    => $source_id,
            'target_id'    => $target_id,
            'anchor'       => $anchor,
            'target_url'   => $url,
            'element_id'   => $element_id,
            'setting_path' => $setting_path,
            'persisted'    => true,
        );
    }

    /** Find one exact saved Elementor control value by element ID and setting path. */
    private function elementor_control_value_by_id( $nodes, $element_id, $setting_path ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            if ( (string) ( $node['id'] ?? '' ) === (string) $element_id && isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
                $value = $this->elementor_get_path( $node['settings'], $setting_path );
                return is_string( $value ) ? $value : null;
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $value = $this->elementor_control_value_by_id( $node['elements'], $element_id, $setting_path );
                if ( null !== $value ) { return $value; }
            }
        }
        return null;
    }

    private function locate_html( $html, $o, $url, $editor_type, $location ) {
        if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
            return new WP_Error( 'anchor_missing', __( 'No eligible visible text was found.', 'dma-internlink-mapper' ) );
        }
        if ( $this->html_has_target( $html, $url ) ) {
            return array(
                'already_linked' => true,
                'state' => 'already_linked',
                'warnings' => array( __( 'The source already links to this destination.', 'dma-internlink-mapper' ) ),
                'preview_html' => esc_html( wp_strip_all_tags( $html ) ),
                'location' => $location,
                'location_hash' => hash( 'sha256', $html ),
                'editor_type' => $editor_type,
                'html' => $html,
                'match_score' => PHP_INT_MAX,
            );
        }
        $result = $this->replace_text_node( $html, $o->anchor_text, $url, false );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return array(
            'html' => $html,
            'new_html' => $result['html'],
            'preview_html' => $result['preview_html'],
            'location' => $location . ':text-' . (int) $result['node_index'],
            'location_hash' => hash( 'sha256', $html ),
            'editor_type' => $editor_type,
            'already_linked' => false,
            'state' => 'ready',
            'warnings' => $result['warnings'],
            'match_score' => $result['score'],
            'matched_anchor' => (string) ( $result['matched_anchor'] ?? $o->anchor_text ),
        );
    }

    /**
     * Insert a link into the best eligible DOM text node.
     *
     * Matching is case-insensitive and tolerates whitespace, dashes and punctuation
     * between anchor words. Headings, existing links, controls and code remain excluded.
     */
    private function replace_text_node( $html, $anchor, $url, $undo ) {
        unset( $undo );
        $pattern = $this->anchor_pattern( $anchor );
        if ( is_wp_error( $pattern ) ) {
            return $pattern;
        }

        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
            return new WP_Error( 'dom_extension_missing', __( 'The PHP DOM extension is required for safe link insertion.', 'dma-internlink-mapper' ) );
        }

        $previous = libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="ilsm-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return new WP_Error( 'unsafe_location', __( 'The HTML could not be parsed safely.', 'dma-internlink-mapper' ) );
        }

        $xpath = new DOMXPath( $dom );
        $nodes = $xpath->query( '//*[@id="ilsm-root"]//text()[normalize-space(.) != ""]' );
        $excluded = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'nav', 'form', 'code', 'pre', 'script', 'style', 'figcaption', 'table', 'label', 'select', 'option', 'textarea' );
        $candidates = array();
        $node_index = 0;

        foreach ( $nodes as $node ) {
            $node_index++;
            $parent = $node->parentNode;
            $skip = false;
            $container = null;
            while ( $parent && XML_ELEMENT_NODE === $parent->nodeType ) {
                $name = strtolower( $parent->nodeName );
                if ( in_array( $name, $excluded, true ) ) {
                    $skip = true;
                    break;
                }
                if ( null === $container && in_array( $name, array( 'p', 'li', 'div', 'blockquote' ), true ) ) {
                    $container = $parent;
                }
                $parent = $parent->parentNode;
            }
            if ( $skip ) {
                continue;
            }

            $text = (string) $node->nodeValue;
            if ( ! preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE ) ) {
                continue;
            }

            $found = $match[0][0];
            $offset = (int) $match[0][1];
            $score = 100;
            $length = function_exists( 'mb_strlen' ) ? mb_strlen( trim( $text ), 'UTF-8' ) : strlen( trim( $text ) );
            if ( $length >= 60 && $length <= 500 ) {
                $score += 25;
            } elseif ( $length >= 25 ) {
                $score += 10;
            }
            if ( $container ) {
                $name = strtolower( $container->nodeName );
                if ( 'p' === $name || 'li' === $name ) {
                    $score += 25;
                }
                $existing_links = $xpath->query( './/a', $container );
                if ( $existing_links && 0 === $existing_links->length ) {
                    $score += 30;
                } else {
                    $score -= 20;
                }
            }
            if ( 1 === preg_match_all( $pattern, $text, $all ) ) {
                $score += 10;
            }
            $candidates[] = array(
                'node' => $node,
                'text' => $text,
                'found' => $found,
                'offset' => $offset,
                'score' => $score,
                'node_index' => $node_index,
            );
        }

        if ( empty( $candidates ) ) {
            return new WP_Error( 'anchor_missing', __( 'No safe text occurrence matching the anchor was found. The content may have changed since the scan.', 'dma-internlink-mapper' ) );
        }

        usort( $candidates, static function( $a, $b ) { return (int) $b['score'] <=> (int) $a['score']; } );
        $candidate = $candidates[0];
        $node = $candidate['node'];
        $text = $candidate['text'];
        $found = $candidate['found'];
        $offset = $candidate['offset'];

        $fragment = $dom->createDocumentFragment();
        $fragment->appendChild( $dom->createTextNode( substr( $text, 0, $offset ) ) );
        $link = $dom->createElement( 'a' );
        $link->setAttribute( 'href', esc_url_raw( $url ) );
        $link->setAttribute( 'data-ilsm-insertion', '1' );
        $link->appendChild( $dom->createTextNode( $found ) );
        $fragment->appendChild( $link );
        $fragment->appendChild( $dom->createTextNode( substr( $text, $offset + strlen( $found ) ) ) );
        $node->parentNode->replaceChild( $fragment, $node );

        $root = $dom->getElementById( 'ilsm-root' );
        $out = '';
        foreach ( $root->childNodes as $child ) {
            $out .= $dom->saveHTML( $child );
        }

        $before = substr( $text, max( 0, $offset - 100 ), min( 100, $offset ) );
        $after = substr( $text, $offset + strlen( $found ), 140 );
        $preview = esc_html( ltrim( $before ) ) . '<mark>' . esc_html( $found ) . '</mark>' . esc_html( $after );
        $warnings = array();
        if ( count( $candidates ) > 1 ) {
            $warnings[] = __( 'Multiple safe occurrences were found. The most contextual occurrence was selected automatically.', 'dma-internlink-mapper' );
        }
        if ( 0 !== strcasecmp( $found, $anchor ) ) {
            $warnings[] = __( 'The visible text differs only by capitalization or punctuation from the suggested anchor.', 'dma-internlink-mapper' );
        }

        return array(
            'html' => $out,
            'preview_html' => $preview,
            'score' => (int) $candidate['score'],
            'node_index' => (int) $candidate['node_index'],
            'matched_anchor' => (string) $found,
            'warnings' => $warnings,
        );
    }

    private function anchor_pattern( $anchor ) {
        $anchor = trim( wp_strip_all_tags( (string) $anchor ) );
        if ( '' === $anchor ) {
            return new WP_Error( 'invalid_anchor', __( 'The suggested anchor is empty.', 'dma-internlink-mapper' ) );
        }
        preg_match_all( '/[\p{L}\p{N}]+/u', $anchor, $matches );
        $tokens = array_values( array_filter( $matches[0] ?? array() ) );
        if ( empty( $tokens ) ) {
            return new WP_Error( 'invalid_anchor', __( 'The suggested anchor contains no searchable words.', 'dma-internlink-mapper' ) );
        }
        $quoted = array_map( static function( $token ) { return preg_quote( $token, '/' ); }, $tokens );
        $separator = '(?:[\s\x{00A0}\p{P}\p{S}]+)';
        return '/(?<![\p{L}\p{N}])' . implode( $separator, $quoted ) . '(?![\p{L}\p{N}])/iu';
    }

    private function save_located( $o, $located ) {
        if ( 'classic' === $located['editor_type'] ) {
            $saved = wp_update_post( array( 'ID' => (int) $o->source_post_id, 'post_content' => $located['new_html'] ), true );
            return is_wp_error( $saved ) ? new WP_Error( 'save_failed', __( 'WordPress could not save the source post.', 'dma-internlink-mapper' ) ) : true;
        }
        if ( 'gutenberg' === $located['editor_type'] ) {
            $blocks = $located['blocks'];
            $this->set_block_html( $blocks, $located['block_path'], $located['new_html'] );
            $saved = wp_update_post( array( 'ID' => (int) $o->source_post_id, 'post_content' => serialize_blocks( $blocks ) ), true );
            return is_wp_error( $saved ) ? $saved : true;
        }
        if ( 'elementor' === $located['editor_type'] ) {
            $data = $located['elementor_data'];
            if ( ! $this->set_elementor_html( $data, $located['element_id'], (array) ( $located['setting_path'] ?? array() ), $located['new_html'] ) ) {
                return new WP_Error( 'save_failed', __( 'The Elementor text control changed before saving.', 'dma-internlink-mapper' ) );
            }

            // Mirror Elementor\Core\Base\Document::save_elements() directly.
            $encoded = wp_slash( wp_json_encode( $data ) );
            $updated = update_metadata( 'post', (int) $o->source_post_id, '_elementor_data', $encoded );
            if ( false === $updated ) {
                $stored = (string) get_post_meta( (int) $o->source_post_id, '_elementor_data', true );
                if ( wp_unslash( $stored ) !== wp_unslash( $encoded ) ) {
                    return new WP_Error( 'save_failed', __( 'WordPress could not save the Elementor text-control content.', 'dma-internlink-mapper' ) );
                }
            }

            if ( class_exists( '\\Elementor\\Plugin' ) ) {
                try {
                    if ( isset( \Elementor\Plugin::$instance->db ) && method_exists( \Elementor\Plugin::$instance->db, 'save_plain_text' ) ) {
                        \Elementor\Plugin::$instance->db->save_plain_text( (int) $o->source_post_id );
                    }
                    if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
                        $post_css = \Elementor\Core\Files\CSS\Post::create( (int) $o->source_post_id );
                        if ( $post_css && method_exists( $post_css, 'delete' ) ) { $post_css->delete(); }
                    }
                    if ( isset( \Elementor\Plugin::$instance->documents ) ) {
                        $document = \Elementor\Plugin::$instance->documents->get( (int) $o->source_post_id );
                        if ( $document && method_exists( $document, 'delete_cache' ) ) { $document->delete_cache(); }
                    }
                    if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
                        \Elementor\Plugin::$instance->files_manager->clear_cache();
                    }
                } catch ( \Throwable $e ) {
                    // The content is already saved; cache refresh failures are non-fatal.
                }
            }
            clean_post_cache( (int) $o->source_post_id );
            return true;
        }
        return new WP_Error( 'unsupported_editor', __( 'This editor location is unsupported.', 'dma-internlink-mapper' ) );
    }

    private function restore_source_content( $o, $located ) {
        $original = isset( $located['source_content'] ) ? (string) $located['source_content'] : '';
        if ( 'elementor' === $located['editor_type'] ) {
            $restored = update_post_meta( (int) $o->source_post_id, '_elementor_data', wp_slash( $original ) );
            if ( false === $restored && (string) get_post_meta( (int) $o->source_post_id, '_elementor_data', true ) !== $original ) {
                return new WP_Error( 'rollback_failed', __( 'The original Elementor content could not be restored automatically.', 'dma-internlink-mapper' ) );
            }
            if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }
            clean_post_cache( (int) $o->source_post_id );
            return true;
        }
        $restored = wp_update_post( array( 'ID' => (int) $o->source_post_id, 'post_content' => $original ), true );
        return is_wp_error( $restored ) ? $restored : true;
    }

    private function set_block_html( &$blocks, $path, $html ) { $ref=&$blocks; foreach($path as $depth=>$index){ if($depth===count($path)-1){$ref[$index]['innerHTML']=$html;$ref[$index]['innerContent']=array($html);return;} $ref=&$ref[$index]['innerBlocks']; } }
    private function set_elementor_html( &$elements, $id, $setting_path, $html ) {
        foreach ( $elements as &$el ) {
            if ( (string) ( $el['id'] ?? '' ) === (string) $id && isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
                return $this->elementor_set_path( $el['settings'], $setting_path, $html );
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) && $this->set_elementor_html( $el['elements'], $id, $setting_path, $html ) ) { return true; }
        }
        return false;
    }

    private function verify_inserted( $o, $located ) {
        $url    = esc_url_raw( get_permalink( $o->target_post_id ) );
        $anchor = trim( wp_strip_all_tags( (string) ( $located['matched_anchor'] ?? $o->anchor_text ) ) );
        if ( '' === $url || '' === $anchor ) { return false; }

        if ( 'elementor' === $located['editor_type'] ) {
            $raw  = (string) get_post_meta( $o->source_post_id, '_elementor_data', true );
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                $data = json_decode( wp_unslash( $raw ), true );
            }
            if ( ! is_array( $data ) ) { return false; }
            return $this->elementor_data_has_verified_link( $data, $url, $anchor );
        }

        $content = (string) get_post_field( 'post_content', $o->source_post_id );
        if ( '' === $content ) { return false; }
        return $this->html_has_verified_link( $content, $url, $anchor );
    }

    /** Verify the marker link inside decoded Elementor control values. */
    private function elementor_data_has_verified_link( $nodes, $url, $anchor ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            if ( isset( $node['widgetType'], $node['settings'] ) && is_array( $node['settings'] ) ) {
                $widget_type = sanitize_key( (string) $node['widgetType'] );
                foreach ( $this->elementor_text_controls( $widget_type, $node['settings'] ) as $control ) {
                    if ( is_string( $control['value'] ) && $this->html_has_verified_link( $control['value'], $url, $anchor ) ) {
                        return true;
                    }
                }
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) && $this->elementor_data_has_verified_link( $node['elements'], $url, $anchor ) ) {
                return true;
            }
        }
        return false;
    }

    /** Verify one exact plugin marker link in normal HTML. */
    private function html_has_verified_link( $html, $url, $anchor ) {
        return $this->count_verified_marker_links( $html, $url, $anchor ) > 0;
    }

    /**
     * Count exact plugin-owned marker links without relying on raw substring matches.
     *
     * The href is normalized through the same URL normalizer used by the crawler,
     * and visible text is compared after entity decoding/whitespace normalization.
     * Nested markup is intentionally rejected because the inserter creates a plain
     * text anchor; changed markup makes automatic undo unsafe.
     */
    private function count_verified_marker_links( $html, $url, $anchor ) {
        $html   = (string) $html;
        $target = ILSM_Link_Normalizer::normalize( (string) $url, home_url( '/' ) );
        $anchor = $this->normalize_marker_text( $anchor );
        if ( '' === $html || ! $target || '' === $anchor ) {
            return 0;
        }

        $pattern = '/<a\b(?=[^>]*\bdata-ilsm-insertion\s*=\s*(["\'])1\1)[^>]*\bhref\s*=\s*(["\'])(.*?)\2[^>]*>(.*?)<\/a>/isu';
        if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $matches as $match ) {
            $href = html_entity_decode( (string) ( $match[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $normalized = ILSM_Link_Normalizer::normalize( $href, home_url( '/' ) );
            $inner = (string) ( $match[4] ?? '' );
            if ( ! $normalized || untrailingslashit( $normalized ) !== untrailingslashit( $target ) ) {
                continue;
            }
            if ( preg_match( '/<[^>]+>/', $inner ) ) {
                continue;
            }
            if ( $this->normalize_marker_text( $inner ) === $anchor ) {
                $count++;
            }
        }
        return $count;
    }

    /** Normalize plain marker text while preserving its real punctuation/case. */
    private function normalize_marker_text( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( is_string( $text ) ? $text : '' );
    }

    /**
     * Remove one exact plugin marker link from one HTML value and keep its original
     * encoded visible text. The surrounding HTML is never reparsed or reformatted.
     *
     * @param string $html   HTML value.
     * @param string $url    Destination URL.
     * @param string $anchor Exact visible anchor stored in history.
     * @param int    $count  Number of exact marker links encountered.
     * @return string
     */
    private function remove_verified_marker_link_from_html( $html, $url, $anchor, &$count ) {
        $html   = (string) $html;
        $target = ILSM_Link_Normalizer::normalize( (string) $url, home_url( '/' ) );
        $anchor = $this->normalize_marker_text( $anchor );
        $count  = 0;
        if ( '' === $html || ! $target || '' === $anchor ) {
            return $html;
        }

        $pattern = '/<a\b(?=[^>]*\bdata-ilsm-insertion\s*=\s*(["\'])1\1)[^>]*\bhref\s*=\s*(["\'])(.*?)\2[^>]*>(.*?)<\/a>/isu';
        $result = preg_replace_callback(
            $pattern,
            function( $match ) use ( $target, $anchor, &$count ) {
                $href = html_entity_decode( (string) ( $match[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $normalized = ILSM_Link_Normalizer::normalize( $href, home_url( '/' ) );
                $inner = (string) ( $match[4] ?? '' );
                if ( ! $normalized || untrailingslashit( $normalized ) !== untrailingslashit( $target ) ) {
                    return $match[0];
                }
                if ( preg_match( '/<[^>]+>/', $inner ) || $this->normalize_marker_text( $inner ) !== $anchor ) {
                    return $match[0];
                }
                $count++;
                return $inner;
            },
            $html
        );
        return is_string( $result ) ? $result : $html;
    }

    private function html_has_target( $html, $url ) {
        $target = untrailingslashit( esc_url_raw( (string) $url ) );
        if ( '' === $target || false === stripos( (string) $html, 'href' ) ) {
            return false;
        }

        if ( class_exists( 'DOMDocument' ) ) {
            $previous = libxml_use_internal_errors( true );
            $doc = new DOMDocument( '1.0', 'UTF-8' );
            $loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . (string) $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
            if ( $loaded ) {
                foreach ( $doc->getElementsByTagName( 'a' ) as $link ) {
                    $href = trim( html_entity_decode( (string) $link->getAttribute( 'href' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                    if ( '' === $href ) { continue; }
                    $normal = ILSM_Link_Normalizer::normalize( $href, home_url( '/' ) );
                    if ( $normal && untrailingslashit( esc_url_raw( $normal ) ) === $target ) {
                        return true;
                    }
                }
            }
        }

        // Fallback for hosts without DOM. Accept an optional trailing slash.
        $quoted = preg_quote( $target, '/' );
        return (bool) preg_match( '/<a\b[^>]*href=["\']' . $quoted . '\/?["\']/i', (string) $html );
    }

    private function remove_exact_link( $history ) {
        $post = get_post( $history->source_post_id );
        if ( ! $post instanceof WP_Post ) {
            return new WP_Error( 'source_missing', __( 'The source post no longer exists.', 'dma-internlink-mapper' ) );
        }

        $url    = esc_url_raw( $history->destination_url );
        $anchor = (string) $history->anchor_text;
        if ( '' === $url || '' === $this->normalize_marker_text( $anchor ) ) {
            return new WP_Error( 'unsafe_undo', __( 'Safe undo is unavailable because the stored insertion identity is incomplete.', 'dma-internlink-mapper' ) );
        }

        $is_elementor = 'elementor' === (string) $history->editor_type;
        if ( $is_elementor ) {
            $raw = (string) get_post_meta( $post->ID, '_elementor_data', true );
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                $data = json_decode( wp_unslash( $raw ), true );
            }
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'elementor_data_invalid', __( 'Safe undo is unavailable because the saved Elementor data could not be decoded.', 'dma-internlink-mapper' ) );
            }

            $parts = $this->elementor_location_parts( $history->content_location ?? '' );
            if ( is_array( $parts ) ) {
                $current = $this->elementor_control_value_by_id( $data, $parts['element_id'], $parts['setting_path'] );
                if ( ! is_string( $current ) ) {
                    return new WP_Error( 'unsafe_undo', __( 'Safe undo is unavailable because the original Elementor control no longer exists.', 'dma-internlink-mapper' ) );
                }
                $count = 0;
                $updated = $this->remove_verified_marker_link_from_html( $current, $url, $anchor, $count );
                if ( 1 !== $count || $updated === $current ) {
                    return new WP_Error( 'unsafe_undo', __( 'Safe undo is unavailable because the exact inserted Elementor link changed or is no longer unique in its control.', 'dma-internlink-mapper' ) );
                }
                if ( ! $this->set_elementor_html( $data, $parts['element_id'], $parts['setting_path'], $updated ) ) {
                    return new WP_Error( 'unsafe_undo', __( 'Safe undo could not update the original Elementor control.', 'dma-internlink-mapper' ) );
                }
            } else {
                /*
                 * Backward-compatible path for history created before precise
                 * Elementor locations were stored. Discover the exact plugin
                 * marker in supported non-dynamic text controls and refuse to
                 * guess unless it is unique across the saved document.
                 */
                $candidates = array();
                $this->find_elementor_marker_candidates( $data, $url, $anchor, $candidates );
                if ( 1 !== count( $candidates ) ) {
                    return new WP_Error( 'unsafe_undo', __( 'Safe undo is unavailable because the exact inserted Elementor link changed or occurs more than once. Use the WordPress revision instead.', 'dma-internlink-mapper' ) );
                }
                $candidate = $candidates[0];
                if ( ! $this->set_elementor_html( $data, $candidate['element_id'], $candidate['setting_path'], $candidate['new_html'] ) ) {
                    return new WP_Error( 'unsafe_undo', __( 'Safe undo could not update the original Elementor control.', 'dma-internlink-mapper' ) );
                }
            }

            $encoded = wp_json_encode( $data );
            if ( ! is_string( $encoded ) || '' === $encoded ) {
                return new WP_Error( 'elementor_encode_failed', __( 'Safe undo could not serialize the restored Elementor document.', 'dma-internlink-mapper' ) );
            }
            return $this->restore_storage_value( $post->ID, true, $encoded );
        }

        $content = (string) $post->post_content;
        $count   = 0;
        $new     = $this->remove_verified_marker_link_from_html( $content, $url, $anchor, $count );
        if ( 1 !== $count ) {
            return new WP_Error( 'unsafe_undo', __( 'Safe undo is unavailable because the exact inserted link changed or occurs more than once. Use the WordPress revision instead.', 'dma-internlink-mapper' ) );
        }
        if ( $new === $content ) {
            return new WP_Error( 'undo_no_change', __( 'The exact inserted link could not be removed safely.', 'dma-internlink-mapper' ) );
        }
        return $this->restore_storage_value( $post->ID, false, $new );
    }

    /** Find exact marker candidates in supported saved Elementor controls. */
    private function find_elementor_marker_candidates( $nodes, $url, $anchor, &$candidates ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            if ( isset( $node['widgetType'], $node['settings'] ) && is_array( $node['settings'] ) ) {
                $widget_type = sanitize_key( (string) $node['widgetType'] );
                foreach ( $this->elementor_text_controls( $widget_type, $node['settings'] ) as $control ) {
                    if ( $this->elementor_value_is_dynamic( $node['settings'], $control['path'] ) ) {
                        continue;
                    }
                    $count = 0;
                    $new_html = $this->remove_verified_marker_link_from_html( (string) $control['value'], $url, $anchor, $count );
                    if ( 1 === $count ) {
                        $candidates[] = array(
                            'element_id'   => (string) ( $node['id'] ?? '' ),
                            'setting_path' => array_values( (array) $control['path'] ),
                            'new_html'     => $new_html,
                        );
                    } elseif ( $count > 1 ) {
                        /*
                         * Represent every extra occurrence so the caller refuses
                         * to guess. Automatic undo must never choose one of several.
                         */
                        for ( $i = 0; $i < $count; $i++ ) {
                            $candidates[] = array( 'ambiguous' => true );
                        }
                    }
                }
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->find_elementor_marker_candidates( $node['elements'], $url, $anchor, $candidates );
            }
        }
    }

    /** Write an exact storage value and verify the persisted value afterwards. */
    private function restore_storage_value( $post_id, $is_elementor, $content ) {
        $post_id = absint( $post_id );
        $content = (string) $content;
        if ( ! $post_id ) {
            return new WP_Error( 'invalid_source', __( 'The source post is invalid.', 'dma-internlink-mapper' ) );
        }

        if ( $is_elementor ) {
            $updated = update_post_meta( $post_id, '_elementor_data', wp_slash( $content ) );
            $stored  = (string) get_post_meta( $post_id, '_elementor_data', true );
            if ( false === $updated && $stored !== $content ) {
                return new WP_Error( 'elementor_save_failed', __( 'WordPress could not save the Elementor content.', 'dma-internlink-mapper' ) );
            }
            if ( $stored !== $content ) {
                return new WP_Error( 'elementor_save_mismatch', __( 'The saved Elementor content did not match the verified update.', 'dma-internlink-mapper' ) );
            }
            if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }
            clean_post_cache( $post_id );
            return true;
        }

        $saved = wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ), true );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        clean_post_cache( $post_id );
        if ( (string) get_post_field( 'post_content', $post_id ) !== $content ) {
            return new WP_Error( 'post_save_mismatch', __( 'The saved post content did not match the verified update.', 'dma-internlink-mapper' ) );
        }
        return true;
    }

    private function acquire_lock( $post_id ) {
        return ILSM_Locks::acquire( 'insert_' . absint( $post_id ), self::LOCK_TTL );
    }

    private function release_lock( $post_id, $token ) {
        ILSM_Locks::release( 'insert_' . absint( $post_id ), $token );
    }

    /** Persist one opportunity status and verify the durable state. */
    private function update_opportunity( $id, $status ) {
        global $wpdb;
        $id     = absint( $id );
        $status = sanitize_key( $status );
        if ( ! $id ) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable opportunity state must be written and verified immediately.
        $updated = $wpdb->update(
            ILSM_Link_Opportunities::table(),
            array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return new WP_Error( 'opportunity_state_write_failed', __( 'WordPress could not update the opportunity state.', 'dma-internlink-mapper' ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-back verifies durable state after the write.
        $stored = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id=%d', ILSM_Link_Opportunities::table(), $id ) );
        if ( $stored !== $status ) {
            return new WP_Error( 'opportunity_state_verify_failed', __( 'The opportunity state could not be verified after saving.', 'dma-internlink-mapper' ) );
        }
        return true;
    }

    /** Insert durable history and return its primary key only after verification. */
    private function record_success( $opportunity, $located, $before, $after, $revision ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned insertion history is a required durable write.
        $inserted = $wpdb->insert(
            self::history_table(),
            array(
                'scan_id'          => absint( $opportunity->scan_id ),
                'opportunity_id'   => absint( $opportunity->id ),
                'source_post_id'   => absint( $opportunity->source_post_id ),
                'target_post_id'   => absint( $opportunity->target_post_id ),
                'user_id'          => get_current_user_id(),
                'anchor_text'      => sanitize_text_field( (string) ( $located['matched_anchor'] ?? $opportunity->anchor_text ) ),
                'destination_url'  => esc_url_raw( get_permalink( $opportunity->target_post_id ) ),
                'editor_type'      => sanitize_key( (string) $located['editor_type'] ),
                'content_location' => sanitize_text_field( (string) $located['location'] ),
                'location_hash'    => sanitize_text_field( (string) $located['location_hash'] ),
                'before_hash'      => sanitize_text_field( (string) $before ),
                'after_hash'       => sanitize_text_field( (string) $after ),
                'revision_id'      => absint( $revision ),
                'insertion_status' => 'inserted',
                'error_code'       => '',
                'error_message'    => '',
                'created_at'       => current_time( 'mysql', true ),
            ),
            array( '%d','%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s' )
        );
        $history_id = (int) $wpdb->insert_id;
        if ( false === $inserted || $history_id <= 0 ) {
            return new WP_Error( 'history_write_failed', __( 'WordPress could not write the durable insertion history.', 'dma-internlink-mapper' ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required read-back verifies the inserted audit row.
        $stored = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d AND insertion_status=%s', self::history_table(), $history_id, 'inserted' ) );
        if ( $stored !== $history_id ) {
            return new WP_Error( 'history_verify_failed', __( 'The insertion history could not be verified after saving.', 'dma-internlink-mapper' ) );
        }
        return $history_id;
    }

    /** Commit history plus opportunity state as one plugin-owned finalization step. */
    private function finalize_success( $opportunity, $located, $before, $after, $revision ) {
        global $wpdb;
        $transaction = ILSM_Database::begin_transaction();
        $history_id  = 0;

        $recorded = $this->record_success( $opportunity, $located, $before, $after, $revision );
        if ( is_wp_error( $recorded ) ) {
            if ( $transaction ) { ILSM_Database::rollback(); }
            return $recorded;
        }
        $history_id = (int) $recorded;

        $state = $this->update_opportunity( $opportunity->id, 'inserted' );
        if ( is_wp_error( $state ) ) {
            if ( $transaction ) {
                ILSM_Database::rollback();
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensating cleanup prevents orphaned success history when transactions are unavailable.
                $wpdb->delete( self::history_table(), array( 'id' => $history_id ), array( '%d' ) );
            }
            return $state;
        }

        if ( $transaction && ! ILSM_Database::commit() ) {
            ILSM_Database::rollback();
            return new WP_Error( 'finalization_commit_failed', __( 'WordPress could not commit the insertion history safely.', 'dma-internlink-mapper' ) );
        }
        return $history_id;
    }

    /** Finalize a durable undo state without claiming success on partial writes. */
    private function finalize_undo_state( $history ) {
        global $wpdb;
        $transaction = ILSM_Database::begin_transaction();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional state transition prevents duplicate concurrent undo success.
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET insertion_status=%s, undone_at=%s, undone_by=%d WHERE id=%d AND insertion_status=%s',
                self::history_table(),
                'undone',
                current_time( 'mysql', true ),
                get_current_user_id(),
                absint( $history->id ),
                'inserted'
            )
        );
        if ( 1 !== (int) $updated ) {
            if ( $transaction ) { ILSM_Database::rollback(); }
            return new WP_Error( 'undo_history_write_failed', __( 'WordPress could not record the undo state.', 'dma-internlink-mapper' ) );
        }

        if ( absint( $history->opportunity_id ) ) {
            $state = $this->update_opportunity( $history->opportunity_id, 'undone' );
            if ( is_wp_error( $state ) ) {
                if ( $transaction ) {
                    ILSM_Database::rollback();
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensate the history state when a transaction is unavailable.
                    $wpdb->update(
                        self::history_table(),
                        array( 'insertion_status' => 'inserted', 'undone_at' => null, 'undone_by' => 0 ),
                        array( 'id' => absint( $history->id ) ),
                        array( '%s', '%s', '%d' ),
                        array( '%d' )
                    );
                }
                return $state;
            }
        }

        if ( $transaction && ! ILSM_Database::commit() ) {
            ILSM_Database::rollback();
            return new WP_Error( 'undo_commit_failed', __( 'WordPress could not commit the undo history safely.', 'dma-internlink-mapper' ) );
        }
        return true;
    }

    /** Best-effort failure audit. The original error is still returned even if logging fails. */
    private function record_failure( $opportunity, $error ) {
        global $wpdb;
        $settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'insert_audit_log' => 1 ) );
        if ( empty( $settings['insert_audit_log'] ) ) {
            $this->update_opportunity( $opportunity->id, 'failed' );
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Optional failed-attempt diagnostics must never hide the original operation error.
        $wpdb->insert(
            self::history_table(),
            array(
                'scan_id'          => absint( $opportunity->scan_id ),
                'opportunity_id'   => absint( $opportunity->id ),
                'source_post_id'   => absint( $opportunity->source_post_id ),
                'target_post_id'   => absint( $opportunity->target_post_id ),
                'user_id'          => get_current_user_id(),
                'anchor_text'      => sanitize_text_field( (string) $opportunity->anchor_text ),
                'destination_url'  => esc_url_raw( get_permalink( $opportunity->target_post_id ) ),
                'editor_type'      => 'unknown',
                'content_location' => '',
                'location_hash'    => '',
                'before_hash'      => '',
                'after_hash'       => '',
                'revision_id'      => 0,
                'insertion_status' => 'failed',
                'error_code'       => sanitize_key( $error->get_error_code() ),
                'error_message'    => sanitize_text_field( $error->get_error_message() ),
                'created_at'       => current_time( 'mysql', true ),
            )
        );
        $this->update_opportunity( $opportunity->id, 'failed' );
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
