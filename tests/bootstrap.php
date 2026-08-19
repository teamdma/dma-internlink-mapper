<?php
/** Minimal WordPress stubs for pure unit tests. */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['ilsm_test_home_url'] = 'https://example.com';
$GLOBALS['ilsm_test_site_url'] = 'https://example.com';

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		$base = rtrim( $GLOBALS['ilsm_test_home_url'], '/' );
		return '' === $path ? $base : $base . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		$base = rtrim( $GLOBALS['ilsm_test_site_url'], '/' );
		return '' === $path ? $base : $base . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, "/\\" ) . '/';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ilsm-link-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/class-ilsm-text.php';
