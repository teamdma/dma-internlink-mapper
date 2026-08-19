<?php
/** Resolves the editor implementation without coupling callers to plugin checks. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Editor_Adapter_Registry {
    /** @var ILSM_Editor_Adapter_Interface[]|null */
    private static $adapters = null;

    /** @return ILSM_Editor_Adapter_Interface[] */
    public static function adapters() {
        if ( null === self::$adapters ) {
            self::$adapters = array(
                new ILSM_Editor_Adapter_Elementor(),
                new ILSM_Editor_Adapter_Gutenberg(),
                new ILSM_Editor_Adapter_Classic(),
            );
            self::$adapters = array_values(
                array_filter(
                    (array) apply_filters( 'ilsm_editor_adapters', self::$adapters ),
                    static function( $adapter ) { return $adapter instanceof ILSM_Editor_Adapter_Interface; }
                )
            );
        }
        return self::$adapters;
    }

    /** @param WP_Post $post Post object. @return string */
    public static function detect( WP_Post $post ) {
        foreach ( self::adapters() as $adapter ) {
            if ( $adapter->supports( $post ) ) { return $adapter->id(); }
        }
        return 'classic';
    }
}
