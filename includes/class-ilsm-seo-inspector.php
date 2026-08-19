<?php
/** SEO indexability helpers. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ILSM_SEO_Inspector {
	/**
	 * Post types that represent editor templates, reusable layout records, or
	 * other internal objects rather than useful public SEO destinations.
	 *
	 * @return string[]
	 */
	public static function excluded_post_types() {
		$types = array(
			'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
			'oembed_cache', 'user_request', 'wp_navigation', 'wp_global_styles',
		);

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'ilsm_excluded_post_types', $types ) ) ) ) );
	}

	/**
	 * Public content types that are useful only on some installations.
	 * They remain selectable in Settings, but are disabled in fresh defaults.
	 *
	 * @return string[]
	 */
	public static function advanced_post_types() {
		$types = array(
			'attachment', 'wp_block', 'wp_template', 'wp_template_part',
			'elementor_library', 'e-landing-page', 'e-floating-buttons',
			'gva__template', 'elementor-hf', 'ae_global_templates',
			'jet-theme-core', 'theme_template',
		);

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'ilsm_advanced_post_types', $types ) ) ) ) );
	}

	/** Whether a post type may be selected for scan/report surfaces. */
	public static function is_supported_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type || in_array( $post_type, self::excluded_post_types(), true ) ) {
			return false;
		}
		$object = get_post_type_object( $post_type );
		return $object && ! empty( $object->public );
	}

	/** Whether a selectable post type should be enabled on a fresh install. */
	public static function is_default_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		return self::is_supported_post_type( $post_type ) && ! in_array( $post_type, self::advanced_post_types(), true );
	}

	public static function is_indexable( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) ) { return false; }
		$robots = array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook is intentionally the WordPress core hook name.
		if ( function_exists( 'wp_robots' ) ) { $robots = apply_filters( 'wp_robots', array() ); }
		if ( isset( $robots['noindex'] ) && $robots['noindex'] ) { return false; }
		if ( ILSM_SEO_Provider_Registry::is_noindex( $post->ID ) ) { return false; }
		return true;
	}


	/**
	 * Whether a published post belongs in link reports.
	 *
	 * Noindex content remains reportable because it can still participate in the
	 * internal-link graph. Transactional and account utility pages are excluded.
	 */
	public static function is_reportable( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) || ! self::is_supported_post_type( $post->post_type ) ) { return false; }

		$excluded_ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount', 'terms' ) as $page_key ) {
				$page_id = (int) wc_get_page_id( $page_key );
				if ( $page_id > 0 ) { $excluded_ids[] = $page_id; }
			}
		}
		$privacy_page_id = absint( get_option( 'wp_page_for_privacy_policy' ) );
		if ( $privacy_page_id > 0 ) { $excluded_ids[] = $privacy_page_id; }
		$excluded_ids = array_map( 'absint', (array) apply_filters( 'ilsm_excluded_report_post_ids', $excluded_ids, $post ) );
		if ( in_array( (int) $post->ID, $excluded_ids, true ) ) { return false; }
		if ( self::is_utility_post( $post ) ) { return false; }

		return ! self::is_utility_url( get_permalink( $post ) );
	}


	/**
	 * Exclude named utility, transactional and legal pages even when their URLs
	 * are customized by WooCommerce, WP Travel Engine or another plugin.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private static function is_utility_post( WP_Post $post ) {
		$values = array(
			sanitize_title( (string) $post->post_name ),
			sanitize_title( (string) $post->post_title ),
		);
		$exact = array(
			'cart', 'basket', 'winkelmand', 'winkelwagen', 'wp-travel-engine-cart',
			'travel-cart', 'trip-cart', 'checkout', 'travel-checkout', 'trip-checkout',
			'thank-you', 'thankyou', 'thank-you-page', 'booking-confirmation',
			'confirmation', 'order-confirmation', 'reservation-confirmation',
			'wishlist', 'my-wishlist', 'dashboard', 'user-dashboard', 'customer-dashboard',
			'privacy-policy', 'privacy', 'privacyverklaring', 'privacybeleid',
			'terms', 'terms-of-use', 'terms-and-conditions', 'terms-of-service',
			'conditions-of-use', 'legal-notice', 'disclaimer', 'algemene-voorwaarden',
			'refund-and-cancellation-policy', 'refund-policy', 'cancellation-policy',
			'refund-cancellation-policy', 'annuleringsvoorwaarden',
		);
		$exact = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) apply_filters( 'ilsm_excluded_report_page_slugs', $exact, $post ) ) ) ) );
		foreach ( $values as $value ) {
			if ( '' !== $value && in_array( $value, $exact, true ) ) { return true; }
		}
		return (bool) apply_filters( 'ilsm_is_utility_report_post', false, $post );
	}

	/** Exclude transactional URLs that have no useful place in an SEO link graph. */
	public static function is_utility_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) { return false; }

		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$path = '/' . trim( rawurldecode( $path ), '/' ) . '/';
		$segments = array(
			'/cart/', '/basket/', '/winkelmand/', '/winkelwagen/', '/wp-travel-engine-cart/',
			'/travel-cart/', '/trip-cart/', '/checkout/', '/travel-checkout/', '/trip-checkout/',
			'/order-received/', '/order-pay/', '/my-account/', '/customer-logout/',
			'/lost-password/', '/edit-account/', '/edit-address/', '/view-order/',
			'/payment-methods/', '/add-payment-method/', '/downloads/', '/thank-you/',
			'/thankyou/', '/thank-you-page/', '/booking-confirmation/', '/confirmation/',
			'/order-confirmation/', '/reservation-confirmation/', '/wishlist/', '/my-wishlist/',
			'/dashboard/', '/user-dashboard/', '/customer-dashboard/', '/privacy-policy/',
			'/privacy/', '/terms/', '/terms-of-use/', '/terms-and-conditions/',
			'/terms-of-service/', '/refund-and-cancellation-policy/', '/refund-policy/',
			'/cancellation-policy/', '/refund-cancellation-policy/', '/login/', '/log-in/',
			'/signin/', '/sign-in/', '/register/', '/password-reset/', '/reset-password/', '/wc-api/'
		);
		$segments = (array) apply_filters( 'ilsm_excluded_report_url_segments', $segments, $url );
		foreach ( $segments as $segment ) {
			$segment = '/' . trim( strtolower( (string) $segment ), '/' ) . '/';
			if ( '//' !== $segment && false !== strpos( $path, $segment ) ) { return true; }
		}

		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		foreach ( array( 'order-received', 'order-pay', 'pay_for_order', 'key', 'wc-ajax' ) as $key ) {
			if ( array_key_exists( $key, $query ) ) { return true; }
		}
		return (bool) apply_filters( 'ilsm_is_utility_report_url', false, $url );
	}

	public static function canonical_url( $post_id ) {
		$url = ILSM_SEO_Provider_Registry::canonical_url( absint( $post_id ) );
		return $url ? $url : get_permalink( $post_id );
	}
}
