<?php
/** SEO provider contract for optional third-party SEO plugins. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ILSM_SEO_Provider_Interface {
    /** @return string Provider identifier. */
    public function id();

    /** @param int $post_id Post ID. @return string[] */
    public function focus_keyphrases( $post_id );

    /** @param int $post_id Post ID. @return bool */
    public function is_noindex( $post_id );

    /** @param int $post_id Post ID. @return string */
    public function canonical_url( $post_id );
}
