<?php
/** Bounded broken-link monitoring and reviewed bulk repairs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Broken_Link_Maintenance {
	const CRON_HOOK = 'ilsm_broken_link_monitor';
	const STATE_KEY = 'ilsm_broken_link_monitor_state';

	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_monitor' ) );
		add_action( 'wp_ajax_ilsm_broken_monitor_run', array( __CLASS__, 'ajax_run_monitor' ) );
		add_action( 'wp_ajax_ilsm_broken_bulk_unlink', array( __CLASS__, 'ajax_bulk_unlink' ) );
		add_action( 'wp_ajax_ilsm_broken_bulk_resolve', array( __CLASS__, 'ajax_bulk_resolve' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ), 20 );
	}

	public static function sync_schedule() {
		$settings = get_option( 'ilsm_settings', array() );
		$enabled  = ! empty( $settings['broken_monitor_enabled'] );
		$next     = wp_next_scheduled( self::CRON_HOOK );
		if ( $enabled && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		} elseif ( ! $enabled && $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/** Check at most ten unique destinations and move forward by primary key, never OFFSET. */
	public static function run_monitor( $manual = false ) {
		global $wpdb;
		$settings = get_option( 'ilsm_settings', array() );
		if ( ! $manual && empty( $settings['broken_monitor_enabled'] ) ) { return array( 'checked' => 0 ); }
		$limit = max( 1, min( 10, absint( $settings['broken_monitor_batch_size'] ?? 5 ) ) );
		$token = ILSM_Locks::acquire( 'broken_monitor', 120 );
		if ( is_wp_error( $token ) ) { return $token; }

		try {
			$scan_id = absint( ILSM_Database::latest_completed_scan_id() );
			$state  = get_option( self::STATE_KEY, array() );
			$cursor = absint( ( $state['scan_id'] ?? 0 ) === $scan_id ? ( $state['cursor'] ?? 0 ) : 0 );
			$table  = ILSM_Database::table( 'links' );
			$search_table = ILSM_Database::table( 'search_console_urls' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Small count over an allowlisted plugin table, used only to split the hard-capped queue.
			$search_count = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$search_table}" ) );
			if ( ! $scan_id && ! $search_count ) { return new WP_Error( 'no_scan', __( 'Run a link scan or import a Search Console CSV before checking destinations.', 'dma-internlink-mapper' ) ); }
			$search_limit = $search_count ? ( $scan_id ? max( 1, (int) floor( $limit / 2 ) ) : $limit ) : 0;
			$link_limit = $scan_id ? max( 0, $limit - $search_limit ) : 0;
			$external_sql = ! empty( $settings['broken_monitor_external'] ) ? '' : " AND destination_type<>'external'";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Allowlisted plugin table and bounded fresh queue query.
			$rows = $link_limit ? $wpdb->get_results( $wpdb->prepare( "SELECT MAX(id) id,target_url,target_url_hash FROM {$table} WHERE scan_id=%d AND id>%d AND target_url<>'' {$external_sql} GROUP BY target_url_hash,target_url ORDER BY id ASC LIMIT %d", $scan_id, $cursor, $link_limit ), ARRAY_A ) : array();
			if ( ! $rows && $cursor ) {
				$cursor = 0;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Allowlisted plugin table and bounded fresh queue query.
				$rows = $link_limit ? $wpdb->get_results( $wpdb->prepare( "SELECT MAX(id) id,target_url,target_url_hash FROM {$table} WHERE scan_id=%d AND id>%d AND target_url<>'' {$external_sql} GROUP BY target_url_hash,target_url ORDER BY id ASC LIMIT %d", $scan_id, $cursor, $link_limit ), ARRAY_A ) : array();
			}

			$checked = 0;
			foreach ( $rows as $row ) {
				$cursor = absint( $row['id'] );
				$result = self::inspect_url( $row['target_url'] );
				if ( is_wp_error( $result ) ) { continue; }
				$issue = $result['status'] >= 400 ? 'broken' : ( $result['status'] >= 300 ? 'redirect' : '' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded prepared update of current scan evidence in an allowlisted plugin table.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET http_status=%d,redirect_url=%s,issue_type=%s WHERE scan_id=%d AND target_url_hash=%s", $result['status'], $result['redirect'], $issue, $scan_id, $row['target_url_hash'] ) );
				$checked++;
			}

			$search_cursor = isset( $state['search_cursor'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $state['search_cursor'] ) ? (string) $state['search_cursor'] : str_repeat( '0', 64 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded prepared cursor query over an allowlisted plugin table.
			$search_rows = $search_limit ? $wpdb->get_results( $wpdb->prepare( "SELECT url_hash,url FROM {$search_table} WHERE url_hash>%s ORDER BY url_hash ASC LIMIT %d", $search_cursor, $search_limit ), ARRAY_A ) : array();
			if ( ! $search_rows && $search_limit ) {
				$search_cursor = str_repeat( '0', 64 );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded prepared cursor query over an allowlisted plugin table.
				$search_rows = $wpdb->get_results( $wpdb->prepare( "SELECT url_hash,url FROM {$search_table} WHERE url_hash>%s ORDER BY url_hash ASC LIMIT %d", $search_cursor, $search_limit ), ARRAY_A );
			}
			foreach ( $search_rows as $row ) {
				$search_cursor = (string) $row['url_hash'];
				$result = self::inspect_url( $row['url'] );
				if ( is_wp_error( $result ) ) { continue; }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One bounded imported URL status update.
				$wpdb->update( $search_table, array( 'http_status' => $result['status'], 'checked_at' => current_time( 'mysql', true ) ), array( 'url_hash' => $row['url_hash'] ), array( '%d', '%s' ), array( '%s' ) );
				$checked++;
			}
			update_option( self::STATE_KEY, array( 'scan_id' => $scan_id, 'cursor' => $cursor, 'search_cursor' => $search_cursor, 'checked_at' => time() ), false );
			return array( 'checked' => $checked, 'scan_id' => $scan_id );
		} finally {
			ILSM_Locks::release( 'broken_monitor', $token );
		}
	}

	private static function inspect_url( $url, $redirection = 3 ) {
		$url = esc_url_raw( $url );
		if ( ! $url || ! wp_http_validate_url( $url ) ) { return new WP_Error( 'unsafe_url' ); }
		$response = wp_safe_remote_get( $url, array( 'timeout' => 3, 'redirection' => max( 0, min( 3, absint( $redirection ) ) ), 'reject_unsafe_urls' => true, 'sslverify' => true, 'limit_response_size' => 1024, 'user-agent' => 'DMA-InternLink-Mapper/' . ILSM_VERSION ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$status = absint( wp_remote_retrieve_response_code( $response ) );
		return array( 'status' => $status, 'redirect' => esc_url_raw( wp_remote_retrieve_header( $response, 'location' ) ) );
	}

	public static function ajax_run_monitor() {
		if ( ! current_user_can( 'ilsm_run_scans' ) ) { wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 ); }
		check_ajax_referer( 'ilsm_broken_links', 'nonce' );
		$result = self::run_monitor( true );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 ); }
		/* translators: %d: number of broken-link destinations checked. */
		wp_send_json_success( array( 'message' => sprintf( _n( '%d destination checked.', '%d destinations checked.', $result['checked'], 'dma-internlink-mapper' ), $result['checked'] ) ) );
	}

	public static function ajax_bulk_unlink() {
		self::process_bulk_resolve( 'unlink' );
	}

	public static function ajax_bulk_resolve() {
		self::process_bulk_resolve();
	}

	private static function process_bulk_resolve( $forced_resolution = '' ) {
		if ( ! current_user_can( 'ilsm_insert_links' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
		}

		check_ajax_referer( 'ilsm_broken_links', 'nonce' );

		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['link_ids'] ?? array() ) ) ) ) );
		$target_hashes = array_values( array_unique( array_filter( array_map( static function( $hash ) { $hash = strtolower( sanitize_text_field( $hash ) ); return preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : ''; }, (array) wp_unslash( $_POST['target_hashes'] ?? array() ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every array member is unslashed, sanitized, and restricted to a SHA-256 hash.
		if ( ( ! $ids && ! $target_hashes ) || count( $ids ) > 20 || count( $target_hashes ) > 20 ) { wp_send_json_error( array( 'message' => __( 'Select between 1 and 20 broken destinations.', 'dma-internlink-mapper' ) ), 400 ); }
		$resolution = $forced_resolution ? sanitize_key( $forced_resolution ) : sanitize_key( wp_unslash( $_POST['resolution'] ?? 'unlink' ) );
		if ( ! in_array( $resolution, array( 'replace', 'redirect', 'replace_redirect', 'unlink' ), true ) ) { wp_send_json_error( array( 'message' => __( 'Choose a valid SEO repair action.', 'dma-internlink-mapper' ) ), 400 ); }
		if ( 'unlink' !== $resolution && count( $target_hashes ) > 1 ) { wp_send_json_error( array( 'message' => __( 'Replace or redirect one distinct broken destination at a time so unrelated URLs cannot share the wrong target.', 'dma-internlink-mapper' ) ), 400 ); }
		if ( false !== strpos( $resolution, 'redirect' ) && ! current_user_can( 'ilsm_manage_settings' ) ) { wp_send_json_error( array( 'message' => __( 'Creating site-wide redirects requires settings-management permission.', 'dma-internlink-mapper' ) ), 403 ); }
		$replacement = esc_url_raw( wp_unslash( $_POST['replacement_url'] ?? '' ), array( 'http', 'https' ) );
		$redirect_code = absint( wp_unslash( $_POST['redirect_code'] ?? 301 ) );
		if ( 'unlink' !== $resolution ) {
			if ( ! $replacement || ! ILSM_Redirect_Manager::is_same_site_url( $replacement ) ) { wp_send_json_error( array( 'message' => __( 'Enter a valid destination URL belonging to this site.', 'dma-internlink-mapper' ) ), 400 ); }
			$check = self::inspect_url( $replacement, 0 );
			if ( is_wp_error( $check ) || absint( $check['status'] ?? 0 ) < 200 || absint( $check['status'] ?? 0 ) >= 300 ) { wp_send_json_error( array( 'message' => __( 'The new destination must respond directly with a successful status; redirect chains are not accepted.', 'dma-internlink-mapper' ) ), 400 ); }
		}
		if ( false !== strpos( $resolution, 'redirect' ) && ! in_array( $redirect_code, array( 301, 302 ), true ) ) { wp_send_json_error( array( 'message' => __( 'Choose 301 Permanent or 302 Temporary.', 'dma-internlink-mapper' ) ), 400 ); }
		$scan_id = absint( ILSM_Database::latest_completed_scan_id() );
		$table = ILSM_Database::table( 'links' );
		if ( ! $target_hashes && $ids ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$id_args = array_merge( array( $scan_id ), $ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Selected representative IDs are prepared and capped at 20; the table identifier is allowlisted.
			$target_hashes = array_values( array_unique( array_filter( $wpdb->get_col( $wpdb->prepare( "SELECT target_url_hash FROM {$table} WHERE scan_id=%d AND id IN ({$id_placeholders})", $id_args ) ) ) ) );
		}
		if ( 'unlink' !== $resolution && count( $target_hashes ) > 1 ) { wp_send_json_error( array( 'message' => __( 'Select one broken destination at a time for replacement or redirect.', 'dma-internlink-mapper' ) ), 400 ); }
		if ( $target_hashes ) {
			$placeholders = implode( ',', array_fill( 0, count( $target_hashes ), '%s' ) );
			$args = array_merge( array( $scan_id ), $target_hashes, array( 20 ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Runtime placeholders exactly match the validated hash list plus scan and hard LIMIT arguments.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_post_id,target_url,target_url_hash,http_status,issue_type,link_location FROM {$table} WHERE scan_id=%d AND target_url_hash IN ({$placeholders}) AND (issue_type='broken' OR http_status>=400) ORDER BY id ASC LIMIT %d", $args ), ARRAY_A );
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$args = array_merge( array( $scan_id ), $ids );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- IDs are prepared and query is capped at 20; the table identifier is allowlisted.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_post_id,target_url,target_url_hash,http_status,issue_type,link_location FROM {$table} WHERE scan_id=%d AND id IN ({$placeholders})", $args ), ARRAY_A );
		}
		$by_post = array(); $results = array(); $changed_ids = array(); $redirect_rows = array();
		foreach ( $rows as $row ) {
			$id = absint( $row['id'] ); $post_id = absint( $row['source_post_id'] );
			if ( 404 !== absint( $row['http_status'] ) ) { $results[ $id ] = __( 'Skipped: the latest scan does not verify this as a 404.', 'dma-internlink-mapper' ); continue; }
			if ( false !== strpos( $resolution, 'redirect' ) ) { $redirect_rows[ $id ] = $row; }
			if ( in_array( $resolution, array( 'replace', 'replace_redirect', 'unlink' ), true ) ) {
				if ( ! self::is_editable_location( $row['link_location'] ) || ! self::has_editable_anchor( $post_id, $row['target_url'] ) ) { $results[ $id ] = __( 'The exact link is generated outside editable post content; use Redirect only or edit its template owner.', 'dma-internlink-mapper' ); continue; }
				if ( ! current_user_can( 'edit_post', $post_id ) ) { $results[ $id ] = __( 'Skipped: you cannot edit the source.', 'dma-internlink-mapper' ); continue; }
				$by_post[ $post_id ][ $id ] = $row['target_url'];
			}
		}
		foreach ( $by_post as $post_id => $links ) { self::unlink_post_urls( $post_id, $links, $results, $changed_ids, 'unlink' === $resolution ? '' : $replacement, $scan_id ); }
		$redirected_targets = array();
		foreach ( $redirect_rows as $id => $row ) {
			$target_hash = hash( 'sha256', (string) $row['target_url'] );
			if ( ! array_key_exists( $target_hash, $redirected_targets ) ) {
				$fresh = self::inspect_url( $row['target_url'], 0 );
				$redirected_targets[ $target_hash ] = is_wp_error( $fresh ) || 404 !== absint( $fresh['status'] ?? 0 ) ? new WP_Error( 'not_current_404', __( 'Redirect not created: the old URL no longer returns a direct 404. Recheck it and review existing redirect rules.', 'dma-internlink-mapper' ) ) : ILSM_Redirect_Manager::save( $row['target_url'], $replacement, $redirect_code );
			}
			$saved = $redirected_targets[ $target_hash ];
			if ( is_wp_error( $saved ) ) { $results[ $id ] = $saved->get_error_message(); continue; }
			$content_replaced = in_array( absint( $id ), $changed_ids, true );
			// A verified redirect resolves every occurrence of this exact destination. One indexed update keeps the grouped report truthful without loading every row.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A verified redirect updates the indexed plugin-owned scan rows; stale object caching would make the report incorrect.
				$wpdb->update( $table, array( 'issue_type' => 'redirect', 'http_status' => $redirect_code, 'redirect_url' => $replacement ), array( 'scan_id' => $scan_id, 'target_url_hash' => $row['target_url_hash'] ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
			$changed_ids[] = absint( $id );
				/* translators: %d: HTTP redirect status code, either 301 or 302. */
				$redirect_message = __( '%d redirect created and verified. Any generated/template link still needs a direct-link update.', 'dma-internlink-mapper' );
				$results[ $id ] = 'replace_redirect' === $resolution && $content_replaced ? __( 'Internal link replaced and redirect created.', 'dma-internlink-mapper' ) : sprintf( $redirect_message, $redirect_code );
		}
		wp_send_json_success( array( 'message' => __( 'SEO resolution finished. Up to 20 verified occurrences were processed; reload shows any remaining occurrences.', 'dma-internlink-mapper' ), 'results' => $results, 'changed_ids' => array_values( array_unique( array_map( 'absint', $changed_ids ) ) ), 'reload' => true ) );
	}

	private static function unlink_post_urls( $post_id, $links, &$results, &$changed_ids = array(), $replacement = '', $scan_id = 0 ) {
		global $wpdb;
		$token = ILSM_Locks::acquire( 'broken_edit_' . $post_id, 120 );
		if ( is_wp_error( $token ) ) { foreach ( $links as $id => $url ) { $results[ $id ] = __( 'Skipped: this source is being edited.', 'dma-internlink-mapper' ); } return; }
		try {
			$post = get_post( $post_id );
			if ( ! $post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { foreach ( $links as $id => $url ) { $results[ $id ] = __( 'Skipped: invalid source.', 'dma-internlink-mapper' ); } return; }
			$base = get_permalink( $post );
			$original = (string) $post->post_content; $updated = $original; $changed = array();
			foreach ( $links as $id => $url ) { $next = self::transform_url( $updated, $url, $replacement, $base ); if ( $next !== $updated ) { $updated = $next; $changed[] = $id; } }
			$elementor_original = get_post_meta( $post_id, '_elementor_data', true ); $elementor_updated = $elementor_original;
			if ( is_string( $elementor_original ) && '' !== $elementor_original ) {
				$data = json_decode( $elementor_original, true );
				if ( is_array( $data ) ) { self::walk_elementor( $data, $links, $changed, 0, $base, $replacement ); $elementor_updated = wp_json_encode( $data ); }
			}
			$changed = array_values( array_unique( $changed ) );
			if ( ! $changed ) { foreach ( $links as $id => $url ) { $results[ $id ] = __( 'Not changed: exact anchor was not found in editable content.', 'dma-internlink-mapper' ); } return; }
			wp_save_post_revision( $post_id );
			if ( $updated !== $original ) { $saved = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $updated ) ), true ); if ( is_wp_error( $saved ) ) { foreach ( $links as $id => $url ) { $results[ $id ] = $saved->get_error_message(); } return; } }
			if ( $elementor_updated !== $elementor_original ) { update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_updated ) ); if ( class_exists( '\\Elementor\\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); } }
			self::reconcile_saved_source( absint( $scan_id ), $post_id, $links );
			foreach ( $links as $id => $url ) {
				if ( in_array( $id, $changed, true ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keep the latest scan report consistent after a verified edit.
					$wpdb->update( ILSM_Database::table( 'links' ), array( 'issue_type' => 'resolved', 'http_status' => 0 ), array( 'id' => absint( $id ) ), array( '%s', '%d' ), array( '%d' ) );
					$changed_ids[] = absint( $id );
					$results[ $id ] = $replacement ? __( 'Broken link replaced with the verified destination.', 'dma-internlink-mapper' ) : __( 'Unlinked; visible anchor text was preserved.', 'dma-internlink-mapper' );
				} else {
					$results[ $id ] = __( 'Not changed: exact anchor was not found in editable content.', 'dma-internlink-mapper' );
				}
			}
		} finally { ILSM_Locks::release( 'broken_edit_' . $post_id, $token ); }
	}


	/**
	 * Reconcile every editable occurrence for URLs touched in a saved source.
	 * This prevents representative bulk rows from leaving phantom broken links.
	 */
	private static function reconcile_saved_source( $scan_id, $post_id, $links ) {
		global $wpdb;
		$scan_id = absint( $scan_id );
		$post_id = absint( $post_id );
		if ( ! $scan_id || ! $post_id || empty( $links ) ) { return; }
		$table = ILSM_Database::table( 'links' );
		$seen = array();
		foreach ( $links as $url ) {
			$url = (string) $url;
			$hash = hash( 'sha256', $url );
			if ( isset( $seen[ $hash ] ) ) { continue; }
			$seen[ $hash ] = true;
			/* Re-read saved post/meta instead of trusting the pre-edit cache. */
			if ( self::has_editable_anchor( $post_id, $url, false ) ) { continue; }
			$elementor_location_like = $wpdb->esc_like( 'elementor-' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate reconciliation write to a plugin-owned custom table; caching a write would leave the report stale.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET issue_type='resolved',http_status=0,redirect_url='' WHERE scan_id=%d AND source_post_id=%d AND target_url_hash=%s AND (link_location='content' OR link_location='elementor' OR link_location LIKE %s)",
					$table,
					$scan_id,
					$post_id,
					$hash,
					$elementor_location_like
				)
			);
		}
	}

	private static function walk_elementor( &$nodes, $links, &$changed, $depth = 0, $base = '', $replacement = '' ) {
		if ( ! is_array( $nodes ) ) { return; }
		foreach ( $nodes as $index => &$node ) {
			if ( ! is_array( $node ) || ILSM_Elementor_Controls::node_is_non_body( $node, $depth, $index ) ) { continue; }
			$widget_type = sanitize_key( $node['widgetType'] ?? '' );
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			foreach ( ILSM_Elementor_Controls::text_controls( $widget_type, $settings ) as $control ) {
				$key = $control['path'][0] ?? '';
				if ( ! $key || ! isset( $node['settings'][ $key ] ) || ! is_string( $node['settings'][ $key ] ) ) { continue; }
				foreach ( $links as $id => $url ) {
					$next = self::transform_url( $node['settings'][ $key ], $url, $replacement, $base );
					if ( $next !== $node['settings'][ $key ] ) { $node['settings'][ $key ] = $next; $changed[] = $id; }
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) { self::walk_elementor( $node['elements'], $links, $changed, $depth + 1, $base, $replacement ); }
		}
		unset( $node );
	}

	private static function is_editable_location( $location ) {
		$location = sanitize_key( $location );
		return in_array( $location, array( 'content', 'elementor' ), true ) || ( 0 === strpos( $location, 'elementor-' ) && 'elementor-rendered' !== $location );
	}

	/** Prove that the exact anchor exists in saved content before enabling a destructive control. */
	private static function has_editable_anchor( $post_id, $url, $use_cache = true ) {
		static $cache = array();
		$key = absint( $post_id ) . '|' . hash( 'sha256', (string) $url );
		if ( $use_cache && array_key_exists( $key, $cache ) ) { return $cache[ $key ]; }
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) { return $cache[ $key ] = false; }
		$base = get_permalink( $post );
		$content = (string) $post->post_content;
		if ( self::unwrap_url( $content, $url, $base ) !== $content ) { return $cache[ $key ] = true; }
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return $cache[ $key ] = false; }
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) { $data = json_decode( wp_unslash( $raw ), true ); }
		if ( ! is_array( $data ) ) { return $cache[ $key ] = false; }
		$changed = array();
		self::walk_elementor( $data, array( 1 => $url ), $changed, 0, $base );
		return $cache[ $key ] = in_array( 1, $changed, true );
	}

	private static function unwrap_url( $html, $url, $base = '' ) {
		return self::transform_url( $html, $url, '', $base );
	}

	private static function transform_url( $html, $url, $replacement = '', $base = '' ) {
		$expected = ILSM_Link_Normalizer::normalize_any( $url, $base );
		if ( ! $expected ) { return $html; }
		return preg_replace_callback( '~<a\b([^>]*)>(.*?)</a\s*>~is', static function( $m ) use ( $expected, $base, $replacement ) {
			if ( ! preg_match( '~\bhref\s*=\s*(["\'])(.*?)\1~is', $m[1], $href ) ) { return $m[0]; }
			$actual = ILSM_Link_Normalizer::normalize_any( html_entity_decode( trim( $href[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
			if ( ! $actual || ! hash_equals( $expected, $actual ) ) { return $m[0]; }
			if ( ! $replacement ) { return $m[2]; }
			$attributes = preg_replace( '~\bhref\s*=\s*(["\']).*?\1~is', 'href="' . esc_url( $replacement ) . '"', $m[1], 1 );
			return '<a' . $attributes . '>' . $m[2] . '</a>';
		}, $html );
	}

	public static function render() {
		global $wpdb;
		$scan_id = absint( ILSM_Database::latest_completed_scan_id() ); $rows = array();
		$search_table = ILSM_Database::table( 'search_console_urls' );
		$redirect_table = ILSM_Database::table( 'redirects' );
		ILSM_Redirect_Manager::reconcile_source_hashes();
		$settings = get_option( 'ilsm_settings', array() );
		$per_page = in_array( absint( $settings['report_per_page'] ?? 50 ), array( 25, 50, 100, 200 ), true ) ? absint( $settings['report_per_page'] ?? 50 ) : 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report pagination.
		$current_page = max( 1, absint( wp_unslash( $_GET['broken_p'] ?? 1 ) ) );
		$total = 0; $total_pages = 1;
		if ( $scan_id ) {
			$table = ILSM_Database::table( 'links' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh prepared aggregate over allowlisted plugin tables for truthful server-side pagination.
			$total = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT l.target_url_hash) FROM {$table} l WHERE l.scan_id=%d AND (l.issue_type='broken' OR l.http_status>=400) AND NOT EXISTS (SELECT 1 FROM {$redirect_table} r WHERE r.source_url_hash=l.target_url_hash)", $scan_id ) ) );
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			$current_page = min( $current_page, $total_pages );
			$offset = ( $current_page - 1 ) * $per_page;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded prepared report over allowlisted plugin tables.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT MIN(l.id) id,l.target_url_hash,l.target_url,COUNT(*) occurrences,COUNT(DISTINCT l.source_post_id) source_count,MAX(l.http_status) http_status,COALESCE(MAX(s.clicks),0) clicks,COALESCE(MAX(s.impressions),0) impressions,COALESCE(MAX(s.position),0) position FROM {$table} l LEFT JOIN {$search_table} s ON s.url_hash=l.target_url_hash WHERE l.scan_id=%d AND (l.issue_type='broken' OR l.http_status>=400) AND NOT EXISTS (SELECT 1 FROM {$redirect_table} r WHERE r.source_url_hash=l.target_url_hash) GROUP BY l.target_url_hash,l.target_url ORDER BY COALESCE(MAX(s.impressions),0) DESC,MAX(l.http_status)=404 DESC,MAX(l.id) DESC LIMIT %d OFFSET %d", $scan_id, $per_page, $offset ), ARRAY_A );
		}
		echo '<section class="ilsm-panel ilsm-broken-seo-panel"><div class="ilsm-panel-head ilsm-broken-hero"><div><span class="ilsm-broken-kicker"><i class="fa fa-shield" aria-hidden="true"></i> ' . esc_html__( 'Reviewed SEO repair', 'dma-internlink-mapper' ) . '</span><h2><i class="fa fa-chain-broken" aria-hidden="true"></i> ' . esc_html__( 'Broken Link SEO Resolution', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Resolve up to 20 verified 404s. Replacing the internal link is preferred; redirects preserve visits to an old URL.', 'dma-internlink-mapper' ) . '</p></div><button type="button" class="ilsm-btn" id="ilsm-broken-check-now"><i class="fa fa-refresh" aria-hidden="true"></i> ' . esc_html__( 'Check next safe batch', 'dma-internlink-mapper' ) . '</button></div>';
		echo '<p><strong>' . esc_html__( 'Background monitor:', 'dma-internlink-mapper' ) . '</strong> ' . esc_html( ! empty( $settings['broken_monitor_enabled'] ) ? __( 'Enabled; checks a small batch hourly.', 'dma-internlink-mapper' ) : __( 'Disabled. Enable it in Settings.', 'dma-internlink-mapper' ) ) . ' <span id="ilsm-broken-status" aria-live="polite"></span></p>';
		if ( ! $rows ) { echo '<p>' . esc_html__( 'No broken links were found in the latest completed scan.', 'dma-internlink-mapper' ) . '</p>'; } else {
			echo '<div class="ilsm-broken-resolution-controls"><label class="ilsm-field"><span>' . esc_html__( 'SEO repair action', 'dma-internlink-mapper' ) . '</span><select id="ilsm-broken-resolution"><option value="replace_redirect">' . esc_html__( 'Replace internal link + create redirect', 'dma-internlink-mapper' ) . '</option><option value="replace">' . esc_html__( 'Replace internal link only', 'dma-internlink-mapper' ) . '</option><option value="redirect">' . esc_html__( 'Create redirect only', 'dma-internlink-mapper' ) . '</option><option value="unlink">' . esc_html__( 'Unlink and preserve visible text', 'dma-internlink-mapper' ) . '</option></select></label><label class="ilsm-field" id="ilsm-broken-new-url-wrap"><span>' . esc_html__( 'New destination URL', 'dma-internlink-mapper' ) . '</span><input id="ilsm-broken-new-url" type="url" inputmode="url" placeholder="https://example.com/new-destination/"><small>' . esc_html__( 'Type the complete working URL. Grey example text is not an entered value.', 'dma-internlink-mapper' ) . '</small></label><label class="ilsm-field" id="ilsm-broken-code-wrap"><span>' . esc_html__( 'Redirect type', 'dma-internlink-mapper' ) . '</span><select id="ilsm-broken-code"><option value="301">' . esc_html__( '301 — Permanent move (recommended)', 'dma-internlink-mapper' ) . '</option><option value="302">' . esc_html__( '302 — Temporary move', 'dma-internlink-mapper' ) . '</option></select></label></div>';
			echo '<div class="ilsm-table-scroll ilsm-broken-table-wrap"><table class="widefat striped ilsm-broken-table"><thead><tr><th><input type="checkbox" id="ilsm-broken-all" aria-label="' . esc_attr__( 'Select all eligible destinations', 'dma-internlink-mapper' ) . '"></th><th>' . esc_html__( 'Broken destination', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Occurrences', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Source pages', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Search priority', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'HTTP', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Review', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$eligible = 404 === absint( $row['http_status'] ) && ILSM_Redirect_Manager::is_same_site_url( $row['target_url'] );
				/* translators: 1: Search Console clicks, 2: Search Console impressions. */
				$priority = absint( $row['impressions'] ) ? sprintf( __( '%1$s clicks · %2$s impressions', 'dma-internlink-mapper' ), number_format_i18n( absint( $row['clicks'] ) ), number_format_i18n( absint( $row['impressions'] ) ) ) : __( 'No imported data', 'dma-internlink-mapper' );
				$details_url = add_query_arg( array( 'page' => 'ilsm-link-report', 'issue' => 'broken', 's' => $row['target_url'] ), admin_url( 'admin.php' ) );
				echo '<tr><td><input class="ilsm-broken-item" type="checkbox" value="' . absint( $row['id'] ) . '" data-target-url="' . esc_attr( $row['target_url'] ) . '" ' . disabled( ! $eligible, true, false ) . '></td><td><code>' . esc_html( $row['target_url'] ) . '</code></td><td><strong>' . esc_html( number_format_i18n( absint( $row['occurrences'] ) ) ) . '</strong><small class="ilsm-block-note">' . esc_html__( 'Up to 20 processed per batch', 'dma-internlink-mapper' ) . '</small></td><td>' . esc_html( number_format_i18n( absint( $row['source_count'] ) ) ) . '</td><td>' . esc_html( $priority ) . '</td><td>' . absint( $row['http_status'] ) . '</td><td data-broken-result="' . absint( $row['id'] ) . '"><a href="' . esc_url( $details_url ) . '">' . esc_html__( 'View occurrences', 'dma-internlink-mapper' ) . '</a></td></tr>';
			}
			echo '</tbody></table></div><div class="ilsm-broken-actionbar"><span><i class="fa fa-info-circle" aria-hidden="true"></i> ' . esc_html__( 'Only verified changes leave this report.', 'dma-internlink-mapper' ) . '</span><button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-broken-resolve"><i class="fa fa-magic" aria-hidden="true"></i> ' . esc_html__( 'Resolve selected broken links', 'dma-internlink-mapper' ) . '</button></div>';
			if ( $total_pages > 1 ) { echo '<nav class="tablenav-pages" aria-label="' . esc_attr__( 'Broken links pagination', 'dma-internlink-mapper' ) . '">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'broken_p', '%#%', admin_url( 'admin.php?page=ilsm-broken-links' ) ), 'format' => '', 'current' => $current_page, 'total' => $total_pages, 'prev_text' => __( 'Previous', 'dma-internlink-mapper' ), 'next_text' => __( 'Next', 'dma-internlink-mapper' ) ) ) ) . '</nav>'; }
		}
		echo '</section>';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded report from an allowlisted plugin table of URLs verified by DMA.
		$imported_broken = $wpdb->get_results( "SELECT url,clicks,impressions,position,http_status,checked_at FROM {$search_table} WHERE http_status>=400 ORDER BY impressions DESC LIMIT 50", ARRAY_A );
		if ( $imported_broken ) { echo '<section class="ilsm-panel"><div class="ilsm-panel-head"><div><h2>' . esc_html__( 'Broken Search Console URLs', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Historical or indexed URLs verified by DMA. These have no proven editable anchor and cannot be batch-unlinked.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'URL', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Clicks', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Impressions', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Position', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'HTTP', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>'; foreach ( $imported_broken as $item ) { echo '<tr><td><a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['url'] ) . '</a></td><td>' . esc_html( number_format_i18n( absint( $item['clicks'] ) ) ) . '</td><td>' . esc_html( number_format_i18n( absint( $item['impressions'] ) ) ) . '</td><td>' . esc_html( number_format_i18n( (float) $item['position'], 2 ) ) . '</td><td>' . absint( $item['http_status'] ) . '</td></tr>'; } echo '</tbody></table></div></section>'; }
		ILSM_Redirect_Manager::render_admin_table();
	}

}
