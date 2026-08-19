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
        if ( class_exists( 'ILSM_Opportunity_Eligibility' ) && ! ILSM_Opportunity_Eligibility::is_eligible( $target_id, 'destination' ) ) {
            return new WP_Error( 'excluded_target', __( 'This destination is excluded by the plugin eligibility rules.', 'dma-internlink-mapper' ) );
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

            do_action( 'ilsm_elementor_link_inserted', $source_id, $target_id, $anchor, $located );
            return array(
                'status'      => 'inserted',
                'message'     => __( 'The link was inserted, saved and verified in Elementor.', 'dma-internlink-mapper' ),
                'revision_id' => $revision_id,
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
            $history_id = $this->record_success( $opportunity, $located, $before_hash, $after_hash, $revision_id );
            $this->update_opportunity( $opportunity->id, 'inserted' );
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
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        $history = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::history_table() . ' WHERE id=%d', absint( $history_id ) ) );
        if ( ! $history || 'inserted' !== $history->insertion_status ) { return new WP_Error( 'invalid_history', __( 'This insertion cannot be undone.', 'dma-internlink-mapper' ) ); }
        if ( ! current_user_can( 'ilsm_insert_links' ) || ! current_user_can( 'edit_post', $history->source_post_id ) ) { return new WP_Error( 'forbidden', __( 'You are not allowed to undo this insertion.', 'dma-internlink-mapper' ) ); }
        $lock = $this->acquire_lock( (int) $history->source_post_id );
        if ( is_wp_error( $lock ) ) { return $lock; }
        try {
            $result = $this->remove_exact_link( $history );
            if ( is_wp_error( $result ) ) { return $result; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            $wpdb->update( self::history_table(), array( 'insertion_status' => 'undone', 'undone_at' => current_time( 'mysql', true ), 'undone_by' => get_current_user_id() ), array( 'id' => $history->id ), array( '%s','%s','%d' ), array( '%d' ) );
            $this->update_opportunity( $history->opportunity_id, 'undone' );
            return array( 'status' => 'undone', 'message' => __( 'The link markup was removed and the anchor text was preserved.', 'dma-internlink-mapper' ) );
        } finally { $this->release_lock( (int) $history->source_post_id, $lock ); }
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
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Link_Opportunities::table() . ' WHERE id=%d', absint( $id ) ) );
        if ( ! $row ) { return new WP_Error( 'not_found', __( 'Opportunity not found.', 'dma-internlink-mapper' ) ); }
        if ( (int) $row->scan_id !== ILSM_Database::latest_completed_scan_id() ) { return new WP_Error( 'stale_opportunity', __( 'This opportunity belongs to an older scan. Generate fresh opportunities.', 'dma-internlink-mapper' ) ); }
        return $row;
    }

    private function check_permission( $o ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_insert_links' ) || ! current_user_can( 'edit_post', $o->source_post_id ) || ! current_user_can( 'read_post', $o->target_post_id ) ) {
            return new WP_Error( 'forbidden', __( 'You are not allowed to modify this source post.', 'dma-internlink-mapper' ) );
        }
        $source = get_post( $o->source_post_id ); $target = get_post( $o->target_post_id );
        if ( ! $source || ! $target || $source->ID === $target->ID ) { return new WP_Error( 'invalid_posts', __( 'The source or destination is invalid.', 'dma-internlink-mapper' ) ); }
        if ( 'publish' !== $target->post_status || post_password_required( $target ) ) { return new WP_Error( 'target_not_public', __( 'The destination is not publicly available.', 'dma-internlink-mapper' ) ); }
        $settings = get_option( 'ilsm_settings', array() );
        if ( ! in_array( $source->post_type, (array) ( $settings['post_types'] ?? ILSM_Activator::default_post_types() ), true ) ) { return new WP_Error( 'post_type_disabled', __( 'The source post type is not enabled in plugin settings.', 'dma-internlink-mapper' ) ); }
        return true;
    }

    private function locate( $o, $_for_insert ) {
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
        return array(
            'element_id'    => (string) ( $located['element_id'] ?? '' ),
            'widget_type'   => (string) ( $located['widget_type'] ?? '' ),
            'setting_path'  => array_values( (array) ( $located['setting_path'] ?? array() ) ),
            'setting_key'   => (string) ( $located['setting_key'] ?? ( $located['setting_path'][0] ?? '' ) ),
            'control_type'  => (string) ( $located['control_type'] ?? '' ),
            'control_label' => (string) ( $located['control_label'] ?? '' ),
            'location'      => (string) ( $located['location'] ?? '' ),
            'anchor'        => $anchor,
            'original_html' => (string) ( $located['html'] ?? '' ),
            'new_html'      => (string) ( $located['new_html'] ?? '' ),
            'target_url'    => esc_url_raw( get_permalink( $target_id ) ),
        );
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
        $anchor = trim( wp_strip_all_tags( (string) $o->anchor_text ) );
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
        $pattern = '/<a\b(?=[^>]*\bdata-ilsm-insertion=["\']1["\'])(?=[^>]*\bhref=["\']' . preg_quote( $url, '/' ) . '["\'])[^>]*>\s*' . preg_quote( $anchor, '/' ) . '\s*<\/a>/iu';
        return 1 === preg_match( $pattern, (string) $html );
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

    private function remove_exact_link( $h ) {
        $post=get_post($h->source_post_id); if(!$post){return new WP_Error('source_missing',__('The source post no longer exists.','dma-internlink-mapper'));}
        $url=esc_url_raw($h->destination_url); $anchor=(string)$h->anchor_text;
        $pattern='/<a\b(?=[^>]*data-ilsm-insertion=["\']1["\'])(?=[^>]*href=["\']'.preg_quote($url,'/').'["\'])[^>]*>'.preg_quote($anchor,'/').'<\/a>/iu';
        $is_elementor='elementor'===$h->editor_type; $content=$is_elementor?(string)get_post_meta($post->ID,'_elementor_data',true):(string)$post->post_content;
        if(1!==preg_match_all($pattern,$content)){return new WP_Error('unsafe_undo',__('Safe undo is unavailable because the exact inserted link changed or occurs more than once. Use the WordPress revision instead.','dma-internlink-mapper'));}
        $new=preg_replace($pattern,$anchor,$content,1);
        if($is_elementor){update_post_meta($post->ID,'_elementor_data',wp_slash($new));if(class_exists('\\Elementor\\Plugin')){\Elementor\Plugin::$instance->files_manager->clear_cache();}}
        else{$saved=wp_update_post(array('ID'=>$post->ID,'post_content'=>$new),true);if(is_wp_error($saved)){return $saved;}}
        clean_post_cache($post->ID); return true;
    }

    private function acquire_lock($post_id){return ILSM_Locks::acquire('insert_'.absint($post_id),self::LOCK_TTL);}
    private function release_lock($post_id,$token){ILSM_Locks::release('insert_'.absint($post_id),$token);}
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
    private function update_opportunity($id,$status){global $wpdb;$wpdb->update(ILSM_Link_Opportunities::table(),array('status'=>$status,'updated_at'=>current_time('mysql',true)),array('id'=>(int)$id),array('%s','%s'),array('%d'));}
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom tables require direct database access.
    private function record_success($o,$l,$before,$after,$revision){global $wpdb;$wpdb->insert(self::history_table(),array('scan_id'=>$o->scan_id,'opportunity_id'=>$o->id,'source_post_id'=>$o->source_post_id,'target_post_id'=>$o->target_post_id,'user_id'=>get_current_user_id(),'anchor_text'=>$o->anchor_text,'destination_url'=>get_permalink($o->target_post_id),'editor_type'=>$l['editor_type'],'content_location'=>$l['location'],'location_hash'=>$l['location_hash'],'before_hash'=>$before,'after_hash'=>$after,'revision_id'=>$revision,'insertion_status'=>'inserted','error_code'=>'','error_message'=>'','created_at'=>current_time('mysql',true)),array('%d','%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s'));return (int)$wpdb->insert_id;}
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom tables require direct database access.
    private function record_failure($o,$error){global $wpdb;$wpdb->insert(self::history_table(),array('scan_id'=>$o->scan_id,'opportunity_id'=>$o->id,'source_post_id'=>$o->source_post_id,'target_post_id'=>$o->target_post_id,'user_id'=>get_current_user_id(),'anchor_text'=>$o->anchor_text,'destination_url'=>get_permalink($o->target_post_id),'editor_type'=>'unknown','content_location'=>'','location_hash'=>'','before_hash'=>'','after_hash'=>'','revision_id'=>0,'insertion_status'=>'failed','error_code'=>sanitize_key($error->get_error_code()),'error_message'=>sanitize_text_field($error->get_error_message()),'created_at'=>current_time('mysql',true)));$this->update_opportunity($o->id,'failed');}


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
