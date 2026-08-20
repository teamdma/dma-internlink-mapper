<?php
/** Complete optional uninstall cleanup. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/**
 * Unwrap links inserted by this plugin while preserving their visible contents.
 *
 * @param string $html HTML fragment.
 * @return string
 */
function ilsm_uninstall_strip_inserted_links( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, 'data-ilsm-insertion' ) ) {
		return $html;
	}

	$pattern = '/<a\b(?=[^>]*\bdata-ilsm-insertion\s*=\s*(["\'])1\1)[^>]*>(.*?)<\/a>/isu';
	$previous = null;
	while ( $previous !== $html ) {
		$previous = $html;
		$html = preg_replace( $pattern, '$2', $html );
		if ( null === $html ) {
			return $previous;
		}
	}
	return $html;
}

/**
 * Recursively unwrap marked links inside decoded Elementor data.
 *
 * @param mixed $value Elementor value.
 * @return mixed
 */
function ilsm_uninstall_strip_elementor_value( $value ) {
	if ( is_string( $value ) ) {
		return ilsm_uninstall_strip_inserted_links( $value );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = ilsm_uninstall_strip_elementor_value( $item );
		}
	}
	return $value;
}

/**
 * Remove plugin-marked inserted links from known source posts.
 */
function ilsm_uninstall_remove_inserted_links() {
	global $wpdb;
	$table = $wpdb->prefix . 'ilsm_insertions';
	$like  = $wpdb->esc_like( $table );

	// Only operate when the plugin-owned audit table still exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must inspect a plugin-owned table directly.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $table ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must read current plugin-owned insertion records directly.
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT source_post_id FROM %i WHERE insertion_status = 'inserted'",
			$table
		)
	);
	foreach ( array_map( 'absint', (array) $post_ids ) as $post_id ) {
		if ( ! $post_id ) { continue; }
		$post = get_post( $post_id );
		if ( ! $post ) { continue; }

		$new_content = ilsm_uninstall_strip_inserted_links( (string) $post->post_content );
		if ( $new_content !== $post->post_content ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $new_content,
					)
				)
			);
		}

		$raw_elementor = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw_elementor ) && false !== stripos( $raw_elementor, 'data-ilsm-insertion' ) ) {
			$decoded = json_decode( $raw_elementor, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$cleaned = ilsm_uninstall_strip_elementor_value( $decoded );
				$encoded = wp_json_encode( $cleaned );
				if ( is_string( $encoded ) && $encoded !== $raw_elementor ) {
					update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
				}
			}
		}
		clean_post_cache( $post_id );
	}
}

function ilsm_uninstall_site() {
	$settings = get_option( 'ilsm_settings', array() );
	global $wpdb;
	wp_clear_scheduled_hook( 'ilsm_search_data_sync' );
	wp_clear_scheduled_hook( 'ilsm_broken_link_monitor' );

	$role = get_role( 'administrator' );
	if ( $role ) {
		foreach ( array( 'ilsm_run_scans','ilsm_view_reports','ilsm_export_reports','ilsm_manage_settings','ilsm_insert_links','ilsm_delete_scan_data' ) as $capability ) {
			$role->remove_cap( $capability );
		}
	}

	if ( ! empty( $settings['remove_inserted_links_on_uninstall'] ) ) {
		ilsm_uninstall_remove_inserted_links();
	}

	// Legacy authorization secrets are never retained after uninstall. Imported
	// Search Console rows follow the explicit full-cleanup preference below so
	// the data-retention control behaves exactly as described in Settings.
	delete_option( 'ilsm_search_integrations' );
	delete_option( 'ilsm_search_data' );
	delete_option( 'ilsm_search_sync_lock' );

	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	$suffixes = array( 'ilsm_locks','ilsm_redirects','ilsm_search_console_urls','ilsm_external_actions','ilsm_insertions','ilsm_opportunities','ilsm_feedback','ilsm_phrases','ilsm_keywords','ilsm_issues','ilsm_links','ilsm_pages','ilsm_scans' );
	foreach ( $suffixes as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing plugin-owned custom tables is the explicit purpose of uninstall.php.
		$wpdb->query(
			$wpdb->prepare(
				'DROP TABLE IF EXISTS %i',
				$table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
	delete_option( 'ilsm_settings' );
	delete_option( 'ilsm_db_version' );
	delete_option( 'ilsm_schema_signature' );
	delete_option( 'ilsm_last_scan_id' );
	delete_option( 'ilsm_opportunity_engine_version' );
	delete_option( 'ilsm_ignored_orphan_post_ids' );
	delete_option( 'ilsm_post_types_migrated_142' );
	delete_option( 'ilsm_broken_link_monitor_state' );
	delete_option( 'ilsm_migration_lock' );
	delete_option( 'ilsm_migration_error' );
	$like           = $wpdb->esc_like( '_transient_ilsm_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_ilsm_' ) . '%';
	$domain_op_like = $wpdb->esc_like( 'ilsm_domain_op_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full uninstall removes plugin-owned transient and expiring operation-lock options.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $like, $timeout_like, $domain_op_like ) );
}

if ( is_multisite() ) {
	$ilsm_offset = 0;
	do {
		$ilsm_site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 100,
				'offset'  => $ilsm_offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		foreach ( $ilsm_site_ids as $ilsm_site_id ) {
			switch_to_blog( $ilsm_site_id );
			try {
				ilsm_uninstall_site();
			} finally {
				restore_current_blog();
			}
		}
		$ilsm_offset += count( $ilsm_site_ids );
	} while ( 100 === count( $ilsm_site_ids ) );
} else {
	ilsm_uninstall_site();
}
