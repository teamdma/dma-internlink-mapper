<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ILSM_Editor_Adapter_Gutenberg implements ILSM_Editor_Adapter_Interface {
    public function id() { return 'gutenberg'; }
    public function supports( WP_Post $post ) { return has_blocks( $post->post_content ); }
}
