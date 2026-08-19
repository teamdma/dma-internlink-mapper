<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Small UTF-8 helpers with safe fallbacks for hosts without ext-mbstring.
 */
final class ILSM_Text {
    public static function length( $text ) {
        $text = (string) $text;
        return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
    }

    public static function lower( $text ) {
        $text = (string) $text;
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    }

    public static function position( $haystack, $needle ) {
        $haystack = (string) $haystack;
        $needle   = (string) $needle;
        return function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $needle, 0, 'UTF-8' ) : strpos( $haystack, $needle );
    }

    public static function substring( $text, $start, $length = null ) {
        $text  = (string) $text;
        $start = (int) $start;
        if ( function_exists( 'mb_substr' ) ) {
            return null === $length
                ? mb_substr( $text, $start, null, 'UTF-8' )
                : mb_substr( $text, $start, (int) $length, 'UTF-8' );
        }
        return null === $length ? substr( $text, $start ) : substr( $text, $start, (int) $length );
    }


    /**
     * Normalize visible anchor text for quality classification.
     *
     * This deliberately ignores case, repeated whitespace, punctuation, arrows,
     * icons and other symbols so strings such as "Read more →" and
     * "READ MORE..." are evaluated consistently.
     *
     * @param string $anchor Raw visible anchor text.
     * @return string
     */
    public static function normalize_anchor( $anchor ) {
        $anchor = html_entity_decode( wp_strip_all_tags( (string) $anchor ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $anchor = self::lower( $anchor );
        $anchor = str_replace( array( "Â ", "â" ), ' ', $anchor );
        $anchor = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $anchor );
        $anchor = preg_replace( '/\s+/u', ' ', (string) $anchor );
        return trim( (string) $anchor );
    }

    /**
     * Whether an anchor is generic/non-descriptive enough to flag as weak.
     *
     * Keep this list intentionally conservative. Short but descriptive anchors
     * such as "Pricing", "FAQ", "Contact" or "Hotels" are not weak
     * merely because they contain only one word.
     *
     * @param string $anchor Raw visible anchor text.
     * @return bool
     */
    public static function is_weak_anchor( $anchor ) {
        $normalized = self::normalize_anchor( $anchor );
        if ( '' === $normalized ) {
            return false;
        }

        $generic = array(
            'click here',
            'read more',
            'read article',
            'read the article',
            'read full article',
            'view article',
            'view the article',
            'view page',
            'visit page',
            'learn more',
            'find out more',
            'discover more',
            'see more',
            'view more',
            'more info',
            'more information',
            'view details',
            'see details',
            'continue reading',
            'full article',
            'details',
            'continue',
            'here',
            'more',
            'open',
            'go',
        );

        return in_array( $normalized, $generic, true );
    }
}
