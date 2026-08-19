<?php
/** Registry for optional SEO integrations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_SEO_Provider_Registry {
    /** @var ILSM_SEO_Provider_Interface[]|null */
    private static $providers = null;

    /** @return ILSM_SEO_Provider_Interface[] */
    public static function providers() {
        if ( null === self::$providers ) {
            self::$providers = array(
                new ILSM_SEO_Provider_Yoast(),
                new ILSM_SEO_Provider_Rank_Math(),
                new ILSM_SEO_Provider_AIOSEO(),
            );
            /**
             * Filter the SEO provider list.
             *
             * Custom integrations should implement ILSM_SEO_Provider_Interface.
             *
             * @param ILSM_SEO_Provider_Interface[] $providers Providers.
             */
            self::$providers = (array) apply_filters( 'ilsm_seo_providers', self::$providers );
            self::$providers = array_values(
                array_filter(
                    self::$providers,
                    static function( $provider ) {
                        return $provider instanceof ILSM_SEO_Provider_Interface;
                    }
                )
            );
        }
        return self::$providers;
    }

    /** @param int $post_id Post ID. @return string[] */
    public static function focus_keyphrases( $post_id ) {
        $values = array();
        foreach ( self::providers() as $provider ) {
            $values = array_merge( $values, (array) $provider->focus_keyphrases( absint( $post_id ) ) );
        }
        $values = array_map( 'sanitize_text_field', $values );
        return array_values( array_unique( array_filter( $values ) ) );
    }

    /** @param int $post_id Post ID. @return bool */
    public static function is_noindex( $post_id ) {
        foreach ( self::providers() as $provider ) {
            if ( $provider->is_noindex( absint( $post_id ) ) ) {
                return true;
            }
        }
        return false;
    }

    /** @param int $post_id Post ID. @return string */
    public static function canonical_url( $post_id ) {
        foreach ( self::providers() as $provider ) {
            $url = esc_url_raw( (string) $provider->canonical_url( absint( $post_id ) ) );
            if ( '' !== $url ) {
                return $url;
            }
        }
        return '';
    }
}
