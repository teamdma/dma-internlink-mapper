<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ILSM_Editor_Adapter_Elementor implements ILSM_Editor_Adapter_Interface {
    public function id() { return 'elementor'; }
    public function supports( WP_Post $post ) {
        $raw = get_post_meta( $post->ID, '_elementor_data', true );
        return 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) && is_string( $raw ) && '' !== trim( $raw );
    }
}
