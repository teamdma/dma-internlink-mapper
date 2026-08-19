<?php
/** Editor detection contract used by insertion and future editor integrations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ILSM_Editor_Adapter_Interface {
    /** @return string */
    public function id();
    /** @param WP_Post $post Post object. @return bool */
    public function supports( WP_Post $post );
}
