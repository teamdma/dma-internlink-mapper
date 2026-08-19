<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class ILSM_Link_Normalizer {
    public static function normalize( $url, $base_url = '' ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
        if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '~^(mailto|tel|javascript|data):~i', $url ) ) { return ''; }
        if ( 0 === strpos( $url, '//' ) ) { $url = wp_parse_url( home_url(), PHP_URL_SCHEME ) . ':' . $url; }
        if ( 0 === strpos( $url, '/' ) ) { $url = home_url( $url ); }
        if ( ! preg_match( '~^https?://~i', $url ) ) {
            $base_url = $base_url ?: home_url( '/' );
            $url = trailingslashit( dirname( $base_url ) ) . ltrim( $url, '/' );
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) { return ''; }
        $home = wp_parse_url( home_url() );
        if ( ! self::is_internal( $url ) ) { return ''; }
        unset( $parts['fragment'] );
        $scheme = strtolower( $parts['scheme'] ?? $home['scheme'] );
        // Store one canonical host so www/non-www aliases do not create
        // duplicate internal nodes or leak into External Link Health.
        $host = strtolower( (string) $home['host'] );
        $path = $parts['path'] ?? '/';
        $query = '';
        if ( ! empty( $parts['query'] ) ) { parse_str( $parts['query'], $q ); ksort( $q ); $query = http_build_query( $q ); }
        $normalized = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '' ) . $path;
        return $query ? $normalized . '?' . $query : $normalized;
    }
    /**
     * Normalize any public HTTP(S) link, including external destinations.
     * Relative URLs are resolved against the supplied base URL.
     * Non-web schemes and fragment-only links are rejected.
     */
    public static function normalize_any( $url, $base_url = '' ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
        if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '~^(mailto|tel|javascript|data):~i', $url ) ) { return ''; }
        $base_url = $base_url ?: home_url( '/' );
        $base = wp_parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['host'] ) ) { return ''; }
        $base_scheme = strtolower( (string) ( $base['scheme'] ?? 'https' ) );

        if ( 0 === strpos( $url, '//' ) ) {
            $url = $base_scheme . ':' . $url;
        } elseif ( 0 === strpos( $url, '/' ) ) {
            $origin = $base_scheme . '://' . strtolower( (string) $base['host'] ) . ( isset( $base['port'] ) ? ':' . absint( $base['port'] ) : '' );
            $url = $origin . $url;
        } elseif ( ! preg_match( '~^https?://~i', $url ) ) {
            $base_path = (string) ( $base['path'] ?? '/' );
            $dir = trailingslashit( dirname( $base_path ) );
            $origin = $base_scheme . '://' . strtolower( (string) $base['host'] ) . ( isset( $base['port'] ) ? ':' . absint( $base['port'] ) : '' );
            $url = $origin . $dir . ltrim( $url, '/' );
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) { return ''; }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return ''; }
        unset( $parts['fragment'] );
        $host = strtolower( (string) $parts['host'] );
        $path = (string) ( $parts['path'] ?? '/' );
        $query = '';
        if ( ! empty( $parts['query'] ) ) { parse_str( $parts['query'], $q ); ksort( $q ); $query = http_build_query( $q ); }
        $normalized = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '' ) . $path;
        return $query ? $normalized . '?' . $query : $normalized;
    }

    /**
     * Return whether a normalized HTTP(S) URL belongs to this WordPress site.
     *
     * WordPress installations are commonly served through both the bare and
     * www hostname while one variant redirects to the configured canonical
     * URL. Treat only that exact www alias as equivalent; unrelated subdomains
     * remain external. Both home_url() and site_url() are recognized because
     * WordPress core can live in a subdirectory or use a distinct configured
     * URL while serving the same public site.
     */
    public static function is_internal( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return false; }
        $candidate = self::canonical_site_host( $parts['host'] );
        if ( '' === $candidate ) { return false; }
        foreach ( array( home_url( '/' ), site_url( '/' ) ) as $site_url ) {
            $site = wp_parse_url( $site_url );
            if ( is_array( $site ) && ! empty( $site['host'] ) && hash_equals( self::canonical_site_host( $site['host'] ), $candidate ) ) {
                return true;
            }
        }
        return false;
    }

    /** Canonicalize only case, a terminal dot, and one conventional www alias. */
    private static function canonical_site_host( $host ) {
        $host = strtolower( rtrim( trim( (string) $host ), '.' ) );
        return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
    }

}
