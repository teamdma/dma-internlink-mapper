<?php
/** Structural content eligibility helpers. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reject site-wide Elementor header/footer template records as contextual sources/destinations.
 *
 * @param bool $eligible Current eligibility.
 * @param int  $post_id  Post ID.
 * @return bool
 */
function ilsm_exclude_structural_elementor_templates( $eligible, $post_id ) {
    $post_id = absint( $post_id );
    if ( ! $eligible || ! $post_id ) { return $eligible; }
    if ( 'elementor_library' !== get_post_type( $post_id ) ) { return $eligible; }
    $type = strtolower( (string) get_post_meta( $post_id, '_elementor_template_type', true ) );
    if ( in_array( $type, array( 'header', 'footer' ), true ) ) { return false; }
    return $eligible;
}
foreach ( array( 'ilsm_post_is_eligible', 'ilsm_opportunity_post_is_eligible', 'ilsm_elementor_source_is_eligible' ) as $ilsm_hook ) {
    add_filter( $ilsm_hook, 'ilsm_exclude_structural_elementor_templates', 20, 2 );
}
unset( $ilsm_hook );
