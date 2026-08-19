<?php
/**
 * Eligibility rules for link-opportunity source and destination posts.
 *
 * @package Internal_Link_SEO_Mapper
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Opportunity_Eligibility {
    /** Return true when a post may participate in link opportunities. */
    public static function is_eligible( $post, $role = 'source' ) {
        return ! is_wp_error( self::validate( $post, $role ) );
    }

    /** Validate a source or destination and return a descriptive WP_Error. */
    public static function validate( $post, $role = 'source' ) {
        $post = get_post( $post );
        $role = in_array( $role, array( 'source', 'destination' ), true ) ? $role : 'source';
        if ( ! $post || 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) ) {
            return new WP_Error( 'not_public', __( 'The page is not publicly viewable.', 'dma-internlink-mapper' ) );
        }
        if ( post_password_required( $post ) || '' !== (string) $post->post_password ) {
            return new WP_Error( 'password_protected', __( 'Password-protected pages are excluded from link opportunities.', 'dma-internlink-mapper' ) );
        }

        $settings = wp_parse_args(
            (array) get_option( 'ilsm_settings', array() ),
            array(
                'opportunity_exclude_noindex' => 1,
                'opportunity_exclude_privacy' => 1,
                'opportunity_exclude_legal'   => 1,
                'opportunity_exclude_cookies' => 1,
            )
        );

        if ( ! empty( $settings['opportunity_exclude_noindex'] ) && ! ILSM_SEO_Inspector::is_indexable( $post ) ) {
            return new WP_Error( 'noindex', __( 'Noindex pages are excluded from link opportunities.', 'dma-internlink-mapper' ) );
        }
        if ( ! empty( $settings['opportunity_exclude_privacy'] ) && self::is_privacy_page( $post ) ) {
            return new WP_Error( 'privacy_page', __( 'Privacy-policy pages are excluded from link opportunities.', 'dma-internlink-mapper' ) );
        }
        if ( ! empty( $settings['opportunity_exclude_cookies'] ) && self::matches_utility_page( $post, array( 'cookie-policy', 'cookies-policy', 'cookie-notice', 'cookies', 'cookiebeleid', 'politique-de-cookies' ) ) ) {
            return new WP_Error( 'cookie_page', __( 'Cookie-policy pages are excluded from link opportunities.', 'dma-internlink-mapper' ) );
        }
        if ( ! empty( $settings['opportunity_exclude_legal'] ) && self::matches_utility_page( $post, array( 'terms', 'terms-of-use', 'terms-and-conditions', 'terms-of-service', 'conditions-of-use', 'legal-notice', 'disclaimer', 'algemene-voorwaarden', 'gebruiksvoorwaarden' ) ) ) {
            return new WP_Error( 'legal_page', __( 'Terms and legal-policy pages are excluded from link opportunities.', 'dma-internlink-mapper' ) );
        }

        /**
         * Filter whether a post may be used by the opportunity engine.
         *
         * @param bool    $eligible Eligibility before custom filtering.
         * @param WP_Post $post     Candidate post.
         * @param string  $role     source or destination.
         */
        $eligible = (bool) apply_filters( 'ilsm_is_linkable_page', true, $post, $role );
        if ( ! $eligible ) {
            return new WP_Error( 'filtered', __( 'The page was excluded by a site-specific eligibility filter.', 'dma-internlink-mapper' ) );
        }
        return true;
    }

    private static function is_privacy_page( WP_Post $post ) {
        $privacy_id = absint( get_option( 'wp_page_for_privacy_policy' ) );
        if ( $privacy_id && $privacy_id === (int) $post->ID ) { return true; }
        return self::matches_utility_page( $post, array( 'privacy-policy', 'privacy', 'privacyverklaring', 'privacybeleid', 'politique-de-confidentialite' ) );
    }

    private static function matches_utility_page( WP_Post $post, array $slugs ) {
        $slug = sanitize_title( $post->post_name ?: $post->post_title );
        $path = trim( (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH ), '/' );
        $last = sanitize_title( basename( $path ) );
        foreach ( $slugs as $candidate ) {
            $candidate = sanitize_title( $candidate );
            if ( $candidate === $slug || $candidate === $last ) { return true; }
        }
        return false;
    }
}
