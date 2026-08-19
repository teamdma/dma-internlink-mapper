<?php
/**
 * Safe same-site rendered-page reader used as the source of truth for
 * frontend SEO measurements and rendered internal-link discovery.
 *
 * @package Internal_Link_SEO_Mapper
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Rendered_Page {
    const CACHE_TTL = 300;
    const MAX_BYTES = 3145728;

    /**
     * Fetch and parse a public post permalink.
     *
     * @param WP_Post|int $post Post object or ID.
     * @param bool        $force Force a fresh HTTP request.
     * @return array
     */
    public static function snapshot( $post, $force = false ) {
        $post = get_post( $post );
        if ( ! $post instanceof WP_Post ) {
            return self::failure( '', __( 'The requested WordPress object does not exist.', 'dma-internlink-mapper' ) );
        }

        $url = get_permalink( $post );
        if ( ! is_string( $url ) || ! self::is_same_site_public_url( $url ) ) {
            return self::failure( '', __( 'A safe public permalink could not be resolved.', 'dma-internlink-mapper' ) );
        }

        $modified = (string) $post->post_modified_gmt;
        $cache_key = 'ilsm_rendered_' . substr( hash( 'sha256', $url . '|' . $modified ), 0, 28 );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && array_key_exists( 'ok', $cached ) ) {
                return $cached;
            }
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 8,
                'redirection'         => 3,
                'reject_unsafe_urls'  => true,
                'sslverify'           => true,
                'limit_response_size' => self::MAX_BYTES,
                'user-agent'          => 'DMA InternLink Mapper/' . ILSM_VERSION . '; ' . home_url( '/' ),
                'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $result = self::failure( $url, $response->get_error_message() );
            set_transient( $cache_key, $result, 60 );
            return $result;
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $type   = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        $html   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 300 ) {
            /* translators: %d: HTTP response status code returned by the public URL. */
            $status_message = sprintf( __( 'The public URL returned HTTP %d.', 'dma-internlink-mapper' ), $status );
            $result = self::failure( $url, $status_message, $status );
            set_transient( $cache_key, $result, 60 );
            return $result;
        }
        if ( '' !== $type && false === strpos( $type, 'text/html' ) && false === strpos( $type, 'application/xhtml+xml' ) ) {
            $result = self::failure( $url, __( 'The public URL did not return HTML.', 'dma-internlink-mapper' ), $status );
            set_transient( $cache_key, $result, 60 );
            return $result;
        }
        if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
            $result = self::failure( $url, __( 'Rendered HTML could not be parsed on this server.', 'dma-internlink-mapper' ), $status );
            set_transient( $cache_key, $result, 60 );
            return $result;
        }

        $result = self::parse( $html, $url, $status );
        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }

    /** Parse one complete rendered document. */
    private static function parse( $html, $url, $status ) {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $doc->loadHTML( $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return self::failure( $url, __( 'Rendered HTML could not be parsed.', 'dma-internlink-mapper' ), $status );
        }

        $xpath = new DOMXPath( $doc );
        $seo_title = self::first_text( $xpath, '//head/title' );
        $meta = self::first_attribute( $xpath, '//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]', 'content' );
        $robots = self::first_attribute( $xpath, '//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]', 'content' );
        $canonical = self::first_attribute( $xpath, '//head/link[contains(concat(" ", translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]', 'href' );
        if ( $canonical ) {
            $canonical = ILSM_Link_Normalizer::normalize( $canonical, $url );
        }

        $root = self::content_root( $doc, $xpath );
        if ( ! $root instanceof DOMNode ) {
            return self::failure( $url, __( 'The rendered page body could not be identified.', 'dma-internlink-mapper' ), $status );
        }

        $content = $root->cloneNode( true );
        self::remove_non_content_nodes( $content );
        $body_text = self::clean_text( $content->textContent );

        /*
         * Heading analysis is intentionally document-wide.
         *
         * SEO reporting must describe the HTML that the public URL actually
         * returns, not only post_content or a guessed "main content" wrapper.
         * Elementor, theme builders, WP Travel Engine and other plugins may
         * render legitimate H1-H6 elements outside #page-content / <main>.
         * Restricting this query to $root caused real headings to be missed.
         *
         * Query the rendered <body> directly. DOM tag matching means all
         * normal variants are detected automatically, for example:
         * <h1>, <h1 class="..."> and <h1 style="...">.
         * CSS and JavaScript resources are not followed or parsed here.
         */
        $headings         = array_fill_keys( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), array() );
        $heading_sequence = array();
        $body_nodes       = $xpath->query( '//body[1]' );
        $heading_root     = ( $body_nodes && $body_nodes->length ) ? $body_nodes->item( 0 ) : $doc;
        $ordered_headings = $xpath->query( './/*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $heading_root );
        if ( $ordered_headings ) {
            foreach ( $ordered_headings as $node ) {
                if ( ! $node instanceof DOMElement ) { continue; }
                $text = self::clean_text( $node->textContent );
                if ( '' === $text ) { continue; }
                $tag = strtolower( $node->tagName );
                if ( isset( $headings[ $tag ] ) ) {
                    $headings[ $tag ][] = $text;
                    $heading_sequence[] = array(
                        'level' => absint( substr( $tag, 1 ) ),
                        'text'  => ILSM_Text::substring( $text, 0, 500 ),
                    );
                }
            }
        }

        $images = array( 'total' => 0, 'with_alt' => 0, 'empty_alt' => 0, 'missing_alt' => 0 );
        foreach ( self::descendants_by_tag( $root, 'img' ) as $img ) {
            if ( self::is_hidden_or_chrome( $img, $root ) ) { continue; }
            $images['total']++;
            if ( ! $img->hasAttribute( 'alt' ) ) {
                $images['missing_alt']++;
            } elseif ( '' === trim( (string) $img->getAttribute( 'alt' ) ) ) {
                $images['empty_alt']++;
            } else {
                $images['with_alt']++;
            }
        }

		$schema_types = array();
		$schema_nodes = $xpath->query( '//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]' );
		if ( $schema_nodes ) {
			foreach ( $schema_nodes as $schema_node ) {
				$decoded = json_decode( (string) $schema_node->textContent, true );
				self::collect_schema_types( $decoded, $schema_types );
			}
		}

        $links = self::extract_links( $doc, $url );
        $noindex = (bool) preg_match( '/(?:^|[\s,])noindex(?:[\s,]|$)/i', $robots );

        return array(
            'ok'               => true,
            'verified'         => true,
            'source'           => 'rendered-public-html',
            'url'              => esc_url_raw( $url ),
            'http_status'      => absint( $status ),
            'seo_title'        => sanitize_text_field( $seo_title ),
            'meta_description' => sanitize_text_field( $meta ),
            'canonical'        => esc_url_raw( $canonical ),
            'robots'           => sanitize_text_field( $robots ),
            'indexable'        => ! $noindex,
            'word_count'       => self::count_words( $body_text ),
            'body_text'        => ILSM_Text::substring( $body_text, 0, 250000 ),
            'headings'         => $headings,
            'heading_sequence' => $heading_sequence,
            'heading_scope'    => 'rendered-body',
            'images'           => $images,
			'schema_types'     => array_values( array_unique( array_filter( $schema_types ) ) ),
            'links'            => $links,
            'error'            => '',
        );
    }

	/** Collect JSON-LD @type values recursively without retaining page data. */
	private static function collect_schema_types( $value, &$types ) {
		if ( ! is_array( $value ) ) { return; }
		if ( isset( $value['@type'] ) ) {
			foreach ( (array) $value['@type'] as $type ) {
				$type = sanitize_text_field( (string) $type );
				if ( '' !== $type && count( $types ) < 50 ) { $types[] = $type; }
			}
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) { self::collect_schema_types( $child, $types ); }
		}
	}

    /** Use semantic/main site content, with conservative fallbacks. */
    private static function content_root( DOMDocument $doc, DOMXPath $xpath ) {
        /*
         * Prefer the theme/plugin page-content wrapper before a generic <main>.
         * Elementor headers, mega menus and third-party widgets can contain their
         * own <main> elements. Selecting the first <main> in the document can
         * therefore analyze header chrome instead of the actual singular page.
         */
        $queries = array(
            '//*[@id="page-content"][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " site-main ") and not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)][1]',
            '//main[not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)][1]',
            '//article[not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)][1]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ") and not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)][1]',
            '//body[1]',
        );
        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( $nodes && $nodes->length ) { return $nodes->item( 0 ); }
        }
        return $doc->documentElement;
    }

    /** Remove site chrome and non-text nodes only from the word-count clone. */
    private static function remove_non_content_nodes( DOMNode $root ) {
        $remove = array();
        self::walk( $root, static function( $node ) use ( &$remove, $root ) {
            if ( ! $node instanceof DOMElement || $node === $root ) { return; }
            $tag = strtolower( $node->tagName );
            if ( in_array( $tag, array( 'script','style','noscript','svg','canvas','template','form','nav','header','footer' ), true ) ) {
                $remove[] = $node;
                return;
            }
            $class = strtolower( (string) $node->getAttribute( 'class' ) );
            $id    = strtolower( (string) $node->getAttribute( 'id' ) );
            if ( preg_match( '/(?:cookie|modal|popup|offcanvas|screen-reader|breadcrumb|sharing|social)/', $class . ' ' . $id ) ) {
                $remove[] = $node;
            }
        } );
        foreach ( array_reverse( $remove ) as $node ) {
            if ( $node->parentNode ) { $node->parentNode->removeChild( $node ); }
        }
    }

    /** Extract all rendered anchors, classifying where they appeared. */
    private static function extract_links( DOMDocument $doc, $base ) {
        $out = array();
        foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
            if ( ! $a instanceof DOMElement ) { continue; }
            $url = ILSM_Link_Normalizer::normalize_any( $a->getAttribute( 'href' ), $base );
            if ( ! $url ) { continue; }
            $scope = ILSM_Link_Normalizer::is_internal( $url ) ? 'internal' : 'external';
            $anchor = self::clean_text( $a->textContent );
            $imgs = $a->getElementsByTagName( 'img' );
            $type = $imgs->length ? 'image' : 'text';
            if ( 'image' === $type && '' === $anchor ) {
                $img = $imgs->item( 0 );
                $anchor = $img instanceof DOMElement ? trim( (string) $img->getAttribute( 'alt' ) ) : '';
            }
            $location = self::link_location( $a );
            $rel = strtolower( (string) $a->getAttribute( 'rel' ) );
            $context = $a->parentNode ? self::clean_text( $a->parentNode->textContent ) : '';
            $out[] = array(
                'url'      => $url,
                'anchor'   => ILSM_Text::substring( $anchor, 0, 500 ),
                'context'  => ILSM_Text::substring( $context, 0, 1000 ),
                'location' => $location,
                'type'     => $type,
                'follow'   => false !== strpos( $rel, 'nofollow' ) ? 'nofollow' : 'follow',
                'scope'    => $scope,
                'sponsored'=> false !== strpos( $rel, 'sponsored' ) ? 1 : 0,
                'ugc'      => false !== strpos( $rel, 'ugc' ) ? 1 : 0,
            );
        }
        return $out;
    }

    private static function link_location( DOMElement $node ) {
        $cursor = $node;
        while ( $cursor instanceof DOMElement ) {
            $tag = strtolower( $cursor->tagName );
            $class = strtolower( (string) $cursor->getAttribute( 'class' ) );
            $id = strtolower( (string) $cursor->getAttribute( 'id' ) );
            if ( 'header' === $tag || preg_match( '/(?:site-header|wp-site-header|masthead)/', $class . ' ' . $id ) ) { return 'header'; }
            if ( 'footer' === $tag || preg_match( '/(?:site-footer|footer)/', $class . ' ' . $id ) ) { return 'footer'; }
            if ( 'nav' === $tag || preg_match( '/(?:navigation|menu)/', $class . ' ' . $id ) ) { return 'navigation'; }
            if ( 'aside' === $tag || preg_match( '/(?:sidebar|widget-area)/', $class . ' ' . $id ) ) { return 'sidebar'; }
            if ( 'main' === $tag || 'article' === $tag || 'page-content' === $id || preg_match( '/(?:site-main|entry-content|post-content|trip-content|booking-single)/', $class ) ) { return 'content'; }
            $cursor = $cursor->parentNode;
        }
        return 'rendered';
    }

    private static function is_hidden_or_chrome( DOMNode $node, DOMNode $root ) {
        $cursor = $node;
        while ( $cursor instanceof DOMElement && $cursor !== $root ) {
            $tag = strtolower( $cursor->tagName );
            if ( in_array( $tag, array( 'header','footer','nav' ), true ) ) { return true; }
            $class = strtolower( (string) $cursor->getAttribute( 'class' ) );
            $id = strtolower( (string) $cursor->getAttribute( 'id' ) );
            if ( preg_match( '/(?:site-header|site-footer|offcanvas|screen-reader|cookie|modal|popup)/', $class . ' ' . $id ) ) { return true; }
            $cursor = $cursor->parentNode;
        }
        return false;
    }

    private static function descendants_by_tag( DOMNode $root, $tag ) {
        if ( $root instanceof DOMDocument || $root instanceof DOMElement ) {
            return $root->getElementsByTagName( $tag );
        }
        return array();
    }

    private static function walk( DOMNode $node, $callback ) {
        $callback( $node );
        foreach ( iterator_to_array( $node->childNodes ) as $child ) { self::walk( $child, $callback ); }
    }

    private static function first_text( DOMXPath $xpath, $query ) {
        $nodes = $xpath->query( $query );
        return ( $nodes && $nodes->length ) ? self::clean_text( $nodes->item( 0 )->textContent ) : '';
    }

    private static function first_attribute( DOMXPath $xpath, $query, $attribute ) {
        $nodes = $xpath->query( $query );
        if ( ! $nodes || ! $nodes->length || ! $nodes->item( 0 ) instanceof DOMElement ) { return ''; }
        return trim( (string) $nodes->item( 0 )->getAttribute( $attribute ) );
    }

    private static function clean_text( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text, true ) ) );
    }

    private static function count_words( $text ) {
        $text = trim( (string) $text );
        if ( '' === $text ) { return 0; }
        if ( preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text, $matches ) ) { return count( $matches[0] ); }
        return str_word_count( $text );
    }

    private static function lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
    }

    private static function is_same_site_public_url( $url ) {
        $parts = wp_parse_url( $url );
        $home  = wp_parse_url( home_url( '/' ) );
        if ( ! is_array( $parts ) || ! is_array( $home ) ) { return false; }
        if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) { return false; }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $home_host = strtolower( (string) ( $home['host'] ?? '' ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || $host !== $home_host ) { return false; }
        $path = (string) ( $parts['path'] ?? '/' );
        return 0 !== strpos( $path, '/wp-admin/' ) && '/wp-login.php' !== $path;
    }

    private static function failure( $url, $message, $status = 0 ) {
        return array(
            'ok'          => false,
            'verified'    => false,
            'source'      => 'rendered-public-html',
            'url'         => esc_url_raw( $url ),
            'http_status' => absint( $status ),
            'error'       => sanitize_text_field( $message ),
            'links'       => array(),
        );
    }
}
