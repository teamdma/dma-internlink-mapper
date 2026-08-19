<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared Elementor control discovery for safe automatic insertion.
 *
 * The service trusts Elementor's registered widget schema instead of guessing
 * theme/add-on field names. Only direct textarea/WYSIWYG settings containing
 * visible, non-dynamic body text are returned as write candidates.
 */
final class ILSM_Elementor_Controls {
    private static $registry_cache = array();

    /**
     * Return safe direct text controls for one saved Elementor widget.
     *
     * @param string $widget_type Widget type.
     * @param array  $settings    Saved widget settings.
     * @return array<int,array{path:array,value:string,label:string,type:string}>
     */
    public static function text_controls( $widget_type, $settings ) {
        $widget_type = sanitize_key( (string) $widget_type );
        if ( '' === $widget_type || ! is_array( $settings ) ) {
            return array();
        }

        // These widget families are not contextual body prose even when their
        // implementation uses a textarea internally.
        if ( in_array( $widget_type, array( 'heading', 'shortcode', 'html', 'button', 'menu-anchor', 'nav-menu', 'form', 'social-icons', 'call-to-action' ), true ) ) {
            return array();
        }

        $definitions = self::registered_text_controls( $widget_type );
        if ( empty( $definitions ) ) {
            return array();
        }

        $controls = array();
        foreach ( $definitions as $key => $definition ) {
            // A textarea control is not automatically body prose. Exclude field
            // names that clearly represent headings, actions, URLs, code or IDs.
            if ( preg_match( '/(?:^|_)(title|heading|headline|button|label|url|link|href|html|code|shortcode|css|id)(?:$|_)/i', (string) $key ) ) {
                continue;
            }
            if ( ! array_key_exists( $key, $settings ) || ! is_string( $settings[ $key ] ) ) {
                continue;
            }
            if ( self::is_dynamic( $settings, array( $key ) ) ) {
                continue;
            }
            if ( '' === trim( wp_strip_all_tags( $settings[ $key ] ) ) ) {
                continue;
            }
            $controls[] = array(
                'path'  => array( $key ),
                'value' => $settings[ $key ],
                'label' => (string) $definition['label'],
                'type'  => (string) $definition['type'],
            );
        }

        return $controls;
    }

    /**
     * Read textarea/WYSIWYG fields from Elementor's registered widget schema.
     *
     * @param string $widget_type Widget type.
     * @return array<string,array{type:string,label:string}>
     */
    private static function registered_text_controls( $widget_type ) {
        $widget_type = sanitize_key( (string) $widget_type );
        if ( isset( self::$registry_cache[ $widget_type ] ) ) {
            return self::$registry_cache[ $widget_type ];
        }

        self::$registry_cache[ $widget_type ] = array();
        if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
            return self::$registry_cache[ $widget_type ];
        }

        try {
            $manager = \Elementor\Plugin::$instance->widgets_manager;
            if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
                return self::$registry_cache[ $widget_type ];
            }
            $widget = $manager->get_widget_types( $widget_type );
            if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_controls' ) ) {
                return self::$registry_cache[ $widget_type ];
            }
            $registered = $widget->get_controls();
            if ( ! is_array( $registered ) ) {
                return self::$registry_cache[ $widget_type ];
            }

            foreach ( $registered as $key => $control ) {
                if ( ! is_array( $control ) ) {
                    continue;
                }
                $type = sanitize_key( (string) ( $control['type'] ?? '' ) );
                if ( ! in_array( $type, array( 'wysiwyg', 'textarea' ), true ) ) {
                    continue;
                }
                $key = sanitize_key( (string) $key );
                if ( '' === $key ) {
                    continue;
                }
                self::$registry_cache[ $widget_type ][ $key ] = array(
                    'type'  => $type,
                    'label' => sanitize_text_field( (string) ( $control['label'] ?? $key ) ),
                );
            }
        } catch ( \Throwable $e ) {
            self::$registry_cache[ $widget_type ] = array();
        }

        return self::$registry_cache[ $widget_type ];
    }

    /** Whether a saved setting uses an Elementor Dynamic Tag. */
    public static function is_dynamic( $settings, $path ) {
        $value = self::get_path( $settings, $path );
        if ( is_string( $value ) && ( false !== strpos( $value, '{{' ) || false !== strpos( $value, '}}' ) ) ) {
            return true;
        }
        $dynamic = isset( $settings['__dynamic__'] ) && is_array( $settings['__dynamic__'] ) ? $settings['__dynamic__'] : array();
        $top_key = (string) ( $path[0] ?? '' );
        return '' !== $top_key && isset( $dynamic[ $top_key ] );
    }

    /** Whether an Elementor node/subtree is non-body site chrome. */
    public static function node_is_non_body( $node, $depth, $index ) {
        if ( 0 === (int) $depth && 0 === (int) $index ) {
            return true; // Conservative first hero/intro region exclusion.
        }
        $widget_type = sanitize_key( (string) ( $node['widgetType'] ?? '' ) );
        if ( in_array( $widget_type, array( 'call-to-action', 'button', 'form', 'nav-menu', 'menu-anchor', 'social-icons' ), true ) ) {
            return true;
        }
        $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
        $haystack = strtolower( implode( ' ', array_filter( array(
            (string) ( $settings['_element_id'] ?? '' ),
            (string) ( $settings['css_id'] ?? '' ),
            (string) ( $settings['_css_classes'] ?? '' ),
            (string) ( $settings['css_classes'] ?? '' ),
            (string) ( $settings['html_tag'] ?? '' ),
        ) ) ) );
        return (bool) preg_match( '/(?:^|[\\s_-])(header|footer|hero|banner|cta|call-to-action|promo|promotion)(?:$|[\\s_-])/i', $haystack );
    }

    private static function get_path( $data, $path ) {
        foreach ( (array) $path as $key ) {
            if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
                return null;
            }
            $data = $data[ $key ];
        }
        return $data;
    }
}
