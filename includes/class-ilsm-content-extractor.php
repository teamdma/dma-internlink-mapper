<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Content_Extractor {
    public static function extract( $post ) {
        $links = array();
        $base = get_permalink( $post );
        $content = (string) $post->post_content;
        $links = array_merge( $links, self::extract_html( $content, $base, 'content' ) );

        $elementor = self::get_elementor_data( $post->ID );
        if ( $elementor ) {
            $rendered = self::get_rendered_elementor_html( $post->ID );
            if ( '' !== $rendered ) {
                // Rendered output captures links produced by third-party cards,
                // listing widgets, repeaters and dynamic Elementor controls.
                $links = array_merge( $links, self::extract_html( $rendered, $base, 'elementor-rendered' ) );
            } else {
                // Safe fallback when Elementor is inactive or cannot render.
                self::walk_elementor( $elementor, $links, $base, 'document' );
            }
        }

        return self::deduplicate_links( $links );
    }

    /**
     * Whether a post contains a saved Elementor document.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function has_elementor_document( $post_id ) {
        return ! empty( self::get_elementor_data( absint( $post_id ) ) );
    }

    public static function extract_searchable_text( WP_Post $post ) {
        $parts = array(
            (string) get_the_title( $post ),
            (string) $post->post_excerpt,
            self::body_text_without_excluded_elements( (string) $post->post_content ),
        );
        $elementor = self::get_elementor_data( $post->ID );
        if ( $elementor ) {
            self::collect_elementor_text( $elementor, $parts );
        }
        $text = implode( "\n", $parts );
        $text = strip_shortcodes( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text, true ) ) );
    }

    private static function body_text_without_excluded_elements( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) { return ''; }
        // Exclude headings and media-derived text from local link suggestions.
        $html = preg_replace( '#<(h[1-6]|figure|figcaption|picture|svg|canvas|button)[^>]*>.*?</\1>#isu', ' ', $html );
        $html = preg_replace( '#<(img|source)[^>]*>#isu', ' ', $html );
        return is_string( $html ) ? $html : '';
    }


    /**
     * Return only text that the automatic inserter can safely modify.
     *
     * Titles, excerpts, headings, existing links, media, controls, code and
     * unsupported Elementor widgets are deliberately excluded so generated
     * opportunities correspond to real insertion locations.
     */
    public static function extract_insertable_text( WP_Post $post ) {
        $parts = array( self::insertable_body_text( (string) $post->post_content ) );
        $elementor = self::get_elementor_data( $post->ID );
        if ( $elementor ) {
            self::collect_insertable_elementor_text( $elementor, $parts );
        }
        $text = strip_shortcodes( implode( "\n", $parts ) );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text, true ) ) );
    }

    private static function insertable_body_text( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) { return ''; }
        $html = preg_replace( '#<(h[1-6]|a|figure|figcaption|picture|svg|canvas|button|nav|form|code|pre|script|style|table|label|select|textarea)[^>]*>.*?</\1>#isu', ' ', $html );
        $html = preg_replace( '#<(img|source|input|option)[^>]*>#isu', ' ', $html );
        return is_string( $html ) ? $html : '';
    }

    /**
     * Collect only body textarea/WYSIWYG widget content that the Elementor inserter
     * can modify reliably in version 1.0.0.
     *
     * The first top-level Elementor section/container is treated as hero and
     * skipped. Subtrees explicitly labelled as header, footer, hero, banner,
     * CTA or promotional content are also skipped.
     */
    private static function collect_insertable_elementor_text( $nodes, &$parts, $depth = 0 ) {
        foreach ( array_values( (array) $nodes ) as $index => $node ) {
            if ( ! is_array( $node ) ) { continue; }
            if ( self::elementor_node_is_non_body( $node, $depth, $index ) ) { continue; }

            $widget_type = isset( $node['widgetType'] ) ? sanitize_key( $node['widgetType'] ) : '';
            $settings    = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();

            foreach ( ILSM_Elementor_Controls::text_controls( $widget_type, $settings ) as $control ) {
                $parts[] = self::insertable_body_text( $control['value'] );
                if ( count( $parts ) >= 2000 ) { return; }
            }

            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                self::collect_insertable_elementor_text( $node['elements'], $parts, $depth + 1 );
            }
        }
    }

    /**
     * Automatic insert uses Elementor-registered textarea/WYSIWYG controls.
     */
    private static function elementor_insertable_text_controls( $widget_type, $settings ) {
        return ILSM_Elementor_Controls::text_controls( $widget_type, $settings );
    }

    /** Whether this Elementor node belongs to non-body/site-chrome content. */
    private static function elementor_node_is_non_body( $node, $depth, $index ) {
        return ILSM_Elementor_Controls::node_is_non_body( $node, $depth, $index );
    }

    private static function elementor_control_is_dynamic( $settings, $path ) {
        return ILSM_Elementor_Controls::is_dynamic( $settings, $path );
    }

    /**
     * Render only the current Elementor document, never a remote URL.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private static function get_rendered_elementor_html( $post_id ) {
        /*
         * Do not run Elementor's full frontend renderer inside the browser-driven
         * scan AJAX request. Third-party/theme widgets can exhaust memory or throw
         * runtime errors there, producing an HTTP 500 for the whole scan batch.
         * During scanning we still inspect the complete saved Elementor document
         * through the structured-data fallback in extract().
         */
        if ( wp_doing_ajax() ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; the scan handler verifies its nonce.
            $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
            if ( 'ilsm_scan_batch' === $action ) {
                return '';
            }
        }
        if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
            return '';
        }
        try {
            $frontend = \Elementor\Plugin::$instance->frontend ?? null;
            if ( ! $frontend || ! is_callable( array( $frontend, 'get_builder_content_for_display' ) ) ) {
                return '';
            }
            $html = $frontend->get_builder_content_for_display( absint( $post_id ), false );
            return is_string( $html ) ? $html : '';
        } catch ( Throwable $error ) {
            return '';
        }
    }

    private static function get_elementor_data( $post_id ) {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( is_array( $raw ) ) { return $raw; }
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return array(); }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            $data = json_decode( wp_unslash( $raw ), true );
        }
        return is_array( $data ) ? $data : array();
    }

    private static function extract_html( $html, $base, $location ) {
        if ( '' === trim( (string) $html ) || ( false === stripos( $html, '<a' ) && false === stripos( $html, 'href=' ) ) ) { return array(); }
        $out = array();
        if ( ! class_exists( 'DOMDocument' ) ) { return $out; }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        if ( $loaded ) {
            foreach ( $doc->getElementsByTagName( 'a' ) as $i => $a ) {
                $url = ILSM_Link_Normalizer::normalize( $a->getAttribute( 'href' ), $base );
                if ( ! $url ) { continue; }
                $anchor = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $a->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
                $type = $a->getElementsByTagName( 'img' )->length ? 'image' : 'text';
                if ( 'image' === $type && '' === $anchor ) { $anchor = trim( $a->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'alt' ) ); }
                $rel = strtolower( $a->getAttribute( 'rel' ) );
                $parent = $a->parentNode ? trim( preg_replace( '/\s+/u', ' ', $a->parentNode->textContent ) ) : '';
                $out[] = array(
                    'url'      => $url,
                    'anchor'   => ILSM_Text::substring( $anchor, 0, 500 ),
                    'context'  => ILSM_Text::substring( $parent, 0, 1000 ),
                    'location' => sanitize_key( $location ),
                    'type'     => $type,
                    'follow'   => false !== strpos( $rel, 'nofollow' ) ? 'nofollow' : 'follow',
                    'index'    => $i,
                );
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $out;
    }

    private static function walk_elementor( $nodes, &$links, $base, $path ) {
        foreach ( (array) $nodes as $index => $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $widget_type = isset( $node['widgetType'] ) ? sanitize_key( $node['widgetType'] ) : '';
            $location = 'elementor' . ( $widget_type ? '-' . $widget_type : '' );
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            self::extract_elementor_settings( $settings, $links, $base, $location, $path . '.' . $index );
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                self::walk_elementor( $node['elements'], $links, $base, $path . '.' . $index . '.elements' );
            }
        }
    }

    private static function extract_elementor_settings( $settings, &$links, $base, $location, $path ) {
        foreach ( $settings as $key => $value ) {
            if ( is_array( $value ) ) {
                if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
                    $url = ILSM_Link_Normalizer::normalize( $value['url'], $base );
                    if ( $url ) {
                        $anchor = self::nearest_anchor_text( $settings, $key );
                        $links[] = array(
                            'url'      => $url,
                            'anchor'   => ILSM_Text::substring( $anchor, 0, 500 ),
                            'context'  => ILSM_Text::substring( self::settings_context( $settings ), 0, 1000 ),
                            'location' => $location,
                            'type'     => self::elementor_link_type( $location ),
                            'follow'   => ! empty( $value['nofollow'] ) ? 'nofollow' : 'follow',
                            'index'    => crc32( $path . '.' . $key ),
                        );
                    }
                }
                self::extract_elementor_settings( $value, $links, $base, $location, $path . '.' . $key );
                continue;
            }
            if ( ! is_string( $value ) ) { continue; }
            if ( false !== stripos( $value, '<a' ) ) {
                $links = array_merge( $links, self::extract_html( $value, $base, $location ) );
            }
            if ( in_array( sanitize_key( $key ), array( 'url', 'href', 'link' ), true ) ) {
                $url = ILSM_Link_Normalizer::normalize( $value, $base );
                if ( $url ) {
                    $links[] = array(
                        'url'      => $url,
                        'anchor'   => ILSM_Text::substring( self::nearest_anchor_text( $settings, $key ), 0, 500 ),
                        'context'  => ILSM_Text::substring( self::settings_context( $settings ), 0, 1000 ),
                        'location' => $location,
                        'type'     => self::elementor_link_type( $location ),
                        'follow'   => 'follow',
                        'index'    => crc32( $path . '.' . $key ),
                    );
                }
            }
        }
    }

    private static function nearest_anchor_text( $settings, $link_key ) {
        $preferred = array( 'text', 'title', 'button_text', 'link_text', 'description', 'editor', 'heading', 'label' );
        foreach ( $preferred as $key ) {
            if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( wp_strip_all_tags( $settings[ $key ] ) ) ) {
                return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $settings[ $key ] ) ) );
            }
        }
        return '';
    }

    private static function settings_context( $settings ) {
        $parts = array();
        foreach ( $settings as $value ) {
            if ( is_string( $value ) && strlen( $value ) < 5000 && ! preg_match( '#^(https?:|mailto:|tel:)#i', trim( $value ) ) ) {
                $plain = trim( wp_strip_all_tags( $value ) );
                if ( '' !== $plain ) { $parts[] = $plain; }
            }
            if ( count( $parts ) >= 8 ) { break; }
        }
        return trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
    }

    private static function elementor_link_type( $location ) {
        if ( false !== strpos( $location, 'image' ) ) { return 'image'; }
        if ( false !== strpos( $location, 'button' ) || false !== strpos( $location, 'call-to-action' ) ) { return 'button'; }
        return 'text';
    }

    private static function collect_elementor_text( $nodes, &$parts ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $widget_type = isset( $node['widgetType'] ) ? sanitize_key( $node['widgetType'] ) : '';
            if ( preg_match( '/(?:heading|image|gallery|carousel|slider|media)/', $widget_type ) ) {
                if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) { self::collect_elementor_text( $node['elements'], $parts ); }
                continue;
            }
            if ( ! empty( $node['settings'] ) && is_array( $node['settings'] ) ) {
                self::collect_strings( $node['settings'], $parts );
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) { self::collect_elementor_text( $node['elements'], $parts ); }
        }
    }

    private static function collect_strings( $value, &$parts ) {
        foreach ( (array) $value as $key => $item ) {
            if ( is_array( $item ) ) { self::collect_strings( $item, $parts ); continue; }
            if ( ! is_string( $item ) || '' === trim( $item ) ) { continue; }
            $key = sanitize_key( $key );
            if ( preg_match( '/(?:url|href|link|image|icon|caption|alt|gallery|css|class|id|color|size|width|height|margin|padding|typography|animation|background)/', $key ) ) { continue; }
            $plain = trim( wp_strip_all_tags( $item ) );
            if ( '' !== $plain && ILSM_Text::length( $plain ) >= 2 ) { $parts[] = $plain; }
            if ( count( $parts ) >= 2000 ) { return; }
        }
    }

    private static function collect_elementor_headings( $nodes, &$headings ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $widget_type = isset( $node['widgetType'] ) ? sanitize_key( $node['widgetType'] ) : '';
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            if ( 'heading' === $widget_type && ! empty( $settings['title'] ) ) { $headings[] = wp_strip_all_tags( $settings['title'] ); }
            foreach ( array( 'title', 'heading', 'widget_title' ) as $key ) {
                if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && false !== strpos( $widget_type, 'heading' ) ) { $headings[] = wp_strip_all_tags( $settings[ $key ] ); }
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) { self::collect_elementor_headings( $node['elements'], $headings ); }
        }
    }

    private static function deduplicate_links( $links ) {
        $seen = array();
        $out = array();
        foreach ( $links as $link ) {
            $key = hash( 'sha256', (string) ( $link['url'] ?? '' ) . '|' . (string) ( $link['anchor'] ?? '' ) . '|' . (string) ( $link['location'] ?? '' ) . '|' . (string) ( $link['index'] ?? '' ) );
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $out[] = $link;
        }
        return $out;
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
    /**
     * Return a structured, read-only SEO snapshot for admin diagnostics.
     *
     * The snapshot reads saved WordPress and Elementor data only. It never
     * renders arbitrary frontend widgets and never changes post content.
     *
     * @param WP_Post|int $post Post object or ID.
     * @return array
     */
    public static function seo_snapshot( $post ) {
        $post = get_post( $post );
        if ( ! $post instanceof WP_Post ) {
            return array();
        }

        $body_parts = array( self::body_text_without_excluded_elements( (string) $post->post_content ) );
        $elementor_for_text = self::get_elementor_data( $post->ID );
        if ( $elementor_for_text ) { self::collect_elementor_text( $elementor_for_text, $body_parts ); }
        $body_text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( implode( "\n", $body_parts ) ), true ) ) );
        $title     = trim( wp_strip_all_tags( get_the_title( $post ) ) );
        $headings  = array_fill_keys( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), array() );
        $images    = array( 'total' => 0, 'with_alt' => 0, 'missing_alt' => 0 );

        self::collect_html_seo_elements( (string) $post->post_content, $headings, $images );

        $elementor = self::get_elementor_data( $post->ID );
        if ( $elementor ) {
            self::collect_elementor_seo_elements( $elementor, $headings, $images );
        }

        foreach ( $headings as $level => $values ) {
            $headings[ $level ] = array_values( array_unique( array_filter( array_map( 'trim', $values ) ) ) );
        }

        return array(
            'title'       => $title,
            'title_length'=> ILSM_Text::length( $title ),
            'word_count'  => self::count_words( $body_text ),
            'headings'    => $headings,
            'images'      => $images,
            'elementor'   => ! empty( $elementor ),
        );
    }

    /** Backward-compatible heading text used by the local crawler. */
    public static function extract_headings( WP_Post $post ) {
        $snapshot = self::seo_snapshot( $post );
        if ( empty( $snapshot['headings'] ) ) {
            return '';
        }
        $parts = array();
        foreach ( $snapshot['headings'] as $values ) {
            $parts = array_merge( $parts, (array) $values );
        }
        return trim( implode( ' ', array_unique( array_filter( $parts ) ) ) );
    }

    private static function count_words( $text ) {
        $text = trim( wp_strip_all_tags( (string) $text ) );
        if ( '' === $text ) { return 0; }
        if ( preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text, $matches ) ) {
            return count( $matches[0] );
        }
        return str_word_count( $text );
    }

    private static function collect_html_seo_elements( $html, &$headings, &$images ) {
        $html = (string) $html;
        if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) { return; }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        if ( $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
            foreach ( array_keys( $headings ) as $tag ) {
                foreach ( $doc->getElementsByTagName( $tag ) as $node ) {
                    $value = trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
                    if ( '' !== $value ) { $headings[ $tag ][] = $value; }
                }
            }
            foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
                $images['total']++;
                if ( '' !== trim( (string) $img->getAttribute( 'alt' ) ) ) { $images['with_alt']++; }
                else { $images['missing_alt']++; }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
    }

    private static function collect_elementor_seo_elements( $nodes, &$headings, &$images ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $widget_type = isset( $node['widgetType'] ) ? sanitize_key( $node['widgetType'] ) : '';
            $settings    = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();

            if ( 'heading' === $widget_type || false !== strpos( $widget_type, 'heading' ) ) {
                $value = '';
                foreach ( array( 'title', 'heading', 'widget_title' ) as $key ) {
                    if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
                        $value = trim( wp_strip_all_tags( (string) $settings[ $key ] ) );
                        if ( '' !== $value ) { break; }
                    }
                }
                $level = isset( $settings['header_size'] ) ? strtolower( sanitize_key( $settings['header_size'] ) ) : 'h2';
                if ( ! isset( $headings[ $level ] ) ) { $level = 'h2'; }
                if ( '' !== $value ) { $headings[ $level ][] = $value; }
            }

            if ( 'image' === $widget_type || false !== strpos( $widget_type, 'image' ) ) {
                $attachment_id = 0;
                if ( isset( $settings['image']['id'] ) ) { $attachment_id = absint( $settings['image']['id'] ); }
                if ( $attachment_id ) {
                    $images['total']++;
                    $alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
                    if ( '' !== $alt ) { $images['with_alt']++; } else { $images['missing_alt']++; }
                }
            }

            // Text editor controls may contain inline HTML images/headings.
            foreach ( array( 'editor', 'text', 'description' ) as $key ) {
                if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && false !== strpos( $settings[ $key ], '<' ) ) {
                    self::collect_html_seo_elements( $settings[ $key ], $headings, $images );
                }
            }

            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                self::collect_elementor_seo_elements( $node['elements'], $headings, $images );
            }
        }
    }

}
