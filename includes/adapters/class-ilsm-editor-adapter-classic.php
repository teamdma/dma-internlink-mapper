<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ILSM_Editor_Adapter_Classic implements ILSM_Editor_Adapter_Interface {
    public function id() { return 'classic'; }
    public function supports( WP_Post $post ) { return true; }
}
