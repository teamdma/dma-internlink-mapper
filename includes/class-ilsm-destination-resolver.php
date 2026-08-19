<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolve internal URLs to generic WordPress objects without depending on a
 * particular third-party plugin, post type, or taxonomy.
 */
final class ILSM_Destination_Resolver {
    /** @var array<string,array<string,mixed>> */
    private static $cache = array();

    /**
     * Resolve an internal URL.
     *
     * @param string $url Internal URL.
     * @return array<string,mixed>
     */
    public static function resolve( $url ) {
        $normalized = ILSM_Link_Normalizer::normalize( $url );
        if ( '' === $normalized ) {
            return self::result( 'invalid', 0, '', '', '', __( 'Unknown page', 'dma-internlink-mapper' ), '' );
        }
        if ( isset( self::$cache[ $normalized ] ) ) {
            return self::$cache[ $normalized ];
        }

        $home = untrailingslashit( ILSM_Link_Normalizer::normalize( home_url( '/' ) ) );
        if ( $home && untrailingslashit( $normalized ) === $home ) {
            return self::$cache[ $normalized ] = self::result( 'home', 0, '', '', $normalized, get_bloginfo( 'name' ), '' );
        }

        $post_id = absint( url_to_postid( $normalized ) );
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( $post instanceof WP_Post && ILSM_SEO_Inspector::is_reportable( $post ) ) {
                $title = trim( wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) );
                if ( '' === $title ) {
                    $title = __( 'Untitled content', 'dma-internlink-mapper' );
                }
                return self::$cache[ $normalized ] = self::result( 'post', $post_id, '', $post->post_type, $normalized, $title, $post->post_status );
            }
        }

        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type => $object ) {
            if ( ! ILSM_SEO_Inspector::is_supported_post_type( $post_type ) || empty( $object->has_archive ) ) { continue; }
            $archive_url = get_post_type_archive_link( $post_type );
            if ( $archive_url && untrailingslashit( ILSM_Link_Normalizer::normalize( $archive_url ) ) === untrailingslashit( $normalized ) ) {
                return self::$cache[ $normalized ] = self::result( 'archive', 0, '', $post_type, $normalized, (string) $object->labels->name, 'publish' );
            }
        }

        $term = self::resolve_term( $normalized );
        if ( $term instanceof WP_Term ) {
            return self::$cache[ $normalized ] = self::result( 'term', (int) $term->term_id, $term->taxonomy, '', $normalized, $term->name, 'publish' );
        }

        $path  = trim( (string) wp_parse_url( $normalized, PHP_URL_PATH ), '/' );
        $label = $path ? ucwords( str_replace( array( '-', '_' ), ' ', basename( $path ) ) ) : __( 'Unknown page', 'dma-internlink-mapper' );
        return self::$cache[ $normalized ] = self::result( 'unresolved', 0, '', '', $normalized, $label, '' );
    }

    /** @return WP_Term|null */
    private static function resolve_term( $url ) {
        $home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
        $path      = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        if ( $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }
        if ( '' === $path ) { return null; }

        foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy => $object ) {
            $bases = array();
            if ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
                $bases[] = trim( $object->rewrite['slug'], '/' );
            }
            if ( 'category' === $taxonomy ) {
                $bases[] = trim( (string) get_option( 'category_base', 'category' ), '/' ) ?: 'category';
            } elseif ( 'post_tag' === $taxonomy ) {
                $bases[] = trim( (string) get_option( 'tag_base', 'tag' ), '/' ) ?: 'tag';
            }
            foreach ( array_unique( array_filter( $bases ) ) as $base ) {
                if ( $path !== $base && 0 !== strpos( $path, $base . '/' ) ) { continue; }
                $relative = trim( substr( $path, strlen( $base ) ), '/' );
                if ( '' === $relative ) { continue; }
                $segments = array_values( array_filter( explode( '/', $relative ) ) );
                $slug     = sanitize_title( end( $segments ) );
                if ( '' === $slug ) { continue; }
                $term = get_term_by( 'slug', $slug, $taxonomy );
                if ( $term instanceof WP_Term ) {
                    $term_url = get_term_link( $term );
                    if ( ! is_wp_error( $term_url ) && untrailingslashit( ILSM_Link_Normalizer::normalize( $term_url ) ) === untrailingslashit( $url ) ) {
                        return $term;
                    }
                }
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function result( $type, $id, $taxonomy, $post_type, $url, $label, $status ) {
        return array(
            'type'       => sanitize_key( $type ),
            'object_id'  => absint( $id ),
            'taxonomy'   => sanitize_key( $taxonomy ),
            'post_type'  => sanitize_key( $post_type ),
            'url'        => esc_url_raw( $url ),
            'label'      => sanitize_text_field( $label ),
            'status'     => sanitize_key( $status ),
            'post_id'    => 'post' === $type ? absint( $id ) : 0,
        );
    }
}
