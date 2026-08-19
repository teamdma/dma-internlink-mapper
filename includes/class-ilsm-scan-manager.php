<?php
/**
 * Batched scan orchestration.
 *
 * @package Internal_Link_SEO_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ILSM_Scan_Manager {
	private static $instance;

	public static function instance() {
		return self::$instance ?: ( self::$instance = new self() );
	}

	private function __construct() {
		add_action( 'wp_ajax_ilsm_start_scan', array( $this, 'start' ) );
		add_action( 'wp_ajax_ilsm_scan_batch', array( $this, 'batch' ) );
		add_action( 'wp_ajax_ilsm_scan_action', array( $this, 'action' ) );
		add_action( 'wp_ajax_ilsm_scan_status', array( $this, 'status' ) );
		add_action( 'wp_ajax_ilsm_map_data', array( $this, 'map_data' ) );
	}

	private function guard( $capability = 'ilsm_run_scans' ) {
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
		}
		check_ajax_referer( 'ilsm_admin', 'nonce' );
	}

	/**
	 * Recover a browser-driven scan whose lease no longer has a heartbeat.
	 *
	 * A scan batch refreshes heartbeat_at on every successful request. Because
	 * there is no cron/background worker, a missing heartbeat means no PHP scan
	 * process is still doing work. We still allow a generous grace period for a
	 * slow request before reclaiming the global lease.
	 */
	public static function recover_abandoned( $stale_after = 180 ) {
		global $wpdb;
		$stale_after = max( 60, absint( $stale_after ) );
		$table       = ILSM_Database::table( 'scans' );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$scan        = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
			"SELECT id,status,heartbeat_at,started_at,lock_expires FROM {$table} WHERE status='running' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$lock = ILSM_Locks::inspect( 'scan_global' );

		if ( ! $scan ) {
			if ( $lock ) {
				$expires = strtotime( (string) $lock['expires_at'] . ' UTC' );
				if ( $expires && $expires < time() ) {
					ILSM_Locks::force_release( 'scan_global' );
					return true;
				}
			}
			return false;
		}

		$heartbeat = ! empty( $scan['heartbeat_at'] ) ? strtotime( $scan['heartbeat_at'] . ' UTC' ) : strtotime( $scan['started_at'] . ' UTC' );
		$expired   = ! empty( $scan['lock_expires'] ) && strtotime( $scan['lock_expires'] . ' UTC' ) < time();
		$stale     = $heartbeat && $heartbeat < ( time() - $stale_after );
		$orphaned  = ! $lock;

		if ( ! $expired && ! $stale && ! $orphaned ) {
			return false;
		}

		ILSM_Locks::force_release( 'scan_global' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$wpdb->update(
			$table,
			array(
				'status'        => 'interrupted',
				'lock_token'    => '',
				'lock_expires'  => null,
				'error_message' => __( 'The scan was automatically interrupted after its browser heartbeat stopped.', 'dma-internlink-mapper' ),
			),
			array( 'id' => absint( $scan['id'] ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	public function start() {
		$this->guard();
		global $wpdb;

		// Scan execution never performs schema changes. Repair requires the
		// stronger Settings/activation privilege boundary.
		$required_tables = array(
			ILSM_Database::table( 'scans' ),
			ILSM_Database::table( 'locks' ),
		);
		foreach ( $required_tables as $required_table ) {
			if ( ! ILSM_Database::table_exists( $required_table ) ) {
				wp_send_json_error(
					array( 'message' => __( 'The scan database tables are missing. Open Settings and run Repair Database Schema.', 'dma-internlink-mapper' ) ),
					500
				);
			}
		}

		// Clear abandoned leases before deciding whether a scan is active.
		self::recover_abandoned( 180 );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
		$fresh = ! empty( $_POST['fresh'] );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$running = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
			"SELECT id,user_id,status,scanned_items,total_items,lock_expires,heartbeat_at FROM " . ILSM_Database::table( 'scans' ) . " WHERE status='running' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and literals.

		/*
		 * A deliberate fresh rescan safely retires an existing scan owned by the
		 * current user. It never overwrites a live scan owned by another user.
		 * Previous scan rows and report data remain available for history.
		 */
		if ( $fresh && $running ) {
			wp_send_json_error(
				array(
					'message' => (int) $running['user_id'] === get_current_user_id()
						? __( 'A scan is already running in another browser session. Cancel it from the owning session, or use Force unlock only after it is abandoned.', 'dma-internlink-mapper' )
						: __( 'Another administrator currently owns the active scan. Wait for it to finish or ask that administrator to cancel it.', 'dma-internlink-mapper' ),
					'scan' => $running,
				),
				409
			);
		}

		/*
		 * Start is intentionally idempotent for the owner of a browser-driven scan.
		 * The raw token only exists in JavaScript, so a reload otherwise strands a
		 * perfectly resumable scan behind its own hashed token. Rotate the lease and
		 * return the existing scan instead of creating a duplicate scan row.
		 */
		if ( $running && (int) $running['user_id'] === get_current_user_id() ) {
			/*
			 * Never rotate a fresh lease merely because the dashboard reloaded.
			 * The previous PHP request may still be writing. Recovery is allowed
			 * only after recover_abandoned() has proved the heartbeat stale.
			 */
			wp_send_json_error(
				array(
					'message' => __( 'This scan still has a live browser lease. Wait for the current batch to finish, or use Force unlock only if the browser session was abandoned.', 'dma-internlink-mapper' ),
					'scan'    => $running,
				),
				409
			);
		}

		$lock = ILSM_Locks::acquire( 'scan_global', 300 );
		if ( is_wp_error( $lock ) ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
			$running = $wpdb->get_row( "SELECT id,user_id,status,scanned_items,total_items,lock_expires,heartbeat_at FROM " . ILSM_Database::table( 'scans' ) . " WHERE status='running' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
			wp_send_json_error(
				array(
					'message' => __( 'Another administrator currently owns the active scan. Wait for it to finish or use Force unlock if it was abandoned.', 'dma-internlink-mapper' ),
					'scan'    => $running,
				),
				409
			);
		}

		$settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'post_types' => ILSM_Activator::default_post_types(), 'max_pages' => 5000 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification is handled by the called authenticated action or surrounding request guard. Input is validated and normalized immediately before use.
		$requested = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : (array) $settings['post_types'];
		$public    = array_values( array_filter( get_post_types( array( 'public' => true ), 'names' ), array( 'ILSM_SEO_Inspector', 'is_supported_post_type' ) ) );
		$types     = array_values( array_intersect( array_map( 'sanitize_key', $requested ), $public ) );
		if ( empty( $types ) ) {
			$types = ILSM_Activator::default_post_types();
		}

		$max   = max( 1, min( 100000, absint( $settings['max_pages'] ) ) );
		$total = 0;
		foreach ( $types as $type ) {
			$count  = wp_count_posts( $type );
			$total += isset( $count->publish ) ? (int) $count->publish : 0;
		}
		$total = min( $total, $max );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom tables require direct database access.
		$inserted = $wpdb->insert(
			ILSM_Database::table( 'scans' ),
			array(
				'user_id'       => get_current_user_id(),
				'status'        => 'running',
				'post_types'    => wp_json_encode( $types ),
				'total_items'   => $total,
				'last_post_id'  => 0,
				'lock_token'    => hash( 'sha256', $lock ),
				'lock_expires'  => gmdate( 'Y-m-d H:i:s', time() + 300 ),
				'heartbeat_at'  => $now,
				'started_at'    => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			ILSM_Locks::release( 'scan_global', $lock );
			wp_send_json_error( array( 'message' => __( 'The scan could not be created.', 'dma-internlink-mapper' ) ), 500 );
		}

		wp_send_json_success( array( 'scan_id' => (int) $wpdb->insert_id, 'token' => $lock, 'total' => $total ) );
	}

	public function batch() {
		$this->guard();
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
		$scan    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Database::table( 'scans' ) . ' WHERE id=%d', $scan_id ) );
		if ( ! $scan || ! hash_equals( (string) $scan->lock_token, hash( 'sha256', $token ) ) || (int) $scan->user_id !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'This scan session is no longer active. Use Rescan to start a fresh scan, or Resume when an interrupted scan is available.', 'dma-internlink-mapper' ) ), 403 );
		}
		if ( 'running' !== $scan->status ) {
			wp_send_json_success( array( 'status' => $scan->status, 'done' => true ) );
		}

		/*
		 * Serialize batches within the same scan. A browser timeout does not prove
		 * that PHP stopped executing, so a retry must never start a second worker on
		 * the same cursor while the first request is still alive. The short-lived
		 * per-scan lock is released at request shutdown, including wp_die() paths.
		 */
		$batch_lock_name  = 'scan_batch_' . $scan_id;
		$batch_lock_token = ILSM_Locks::acquire( $batch_lock_name, 330 );
		if ( is_wp_error( $batch_lock_token ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The previous scan batch is still finishing. Its result will be checked before another batch starts.', 'dma-internlink-mapper' ),
					'code'    => 'scan_batch_in_progress',
				),
				409
			);
		}
		register_shutdown_function(
			static function () use ( $batch_lock_name, $batch_lock_token ) {
				ILSM_Locks::release( $batch_lock_name, $batch_lock_token );
			}
		);

		if ( ! ILSM_Locks::refresh( 'scan_global', $token, 300 ) ) {
			// The database lease can expire between browser-driven batches. The
			// token and owner were authenticated above, so safely reclaim only a
			// missing/expired lease. A live lock with another token is never replaced.
			if ( ! ILSM_Locks::reclaim( 'scan_global', $token, 300 ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Another scan is currently active. Wait for it to finish or cancel it before starting a fresh rescan.', 'dma-internlink-mapper' ) ),
					409
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
			$wpdb->update(
				ILSM_Database::table( 'scans' ),
				array(
					'lock_expires' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
					'heartbeat_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $scan_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		/* Keep the persisted heartbeat aligned with the worker lease at batch start. */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable scan state must be updated immediately.
		$heartbeat_updated = $wpdb->update(
			ILSM_Database::table( 'scans' ),
			array(
				'lock_expires' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
				'heartbeat_at' => current_time( 'mysql', true ),
			),
			array(
				'id'         => $scan_id,
				'status'     => 'running',
				'lock_token' => hash( 'sha256', $token ),
			),
			array( '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
		if ( false === $heartbeat_updated ) {
			$this->abort_batch_for_database_error( $scan_id, $token, __( 'The scan heartbeat could not be refreshed before processing the next batch.', 'dma-internlink-mapper' ) );
		}

		$settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'batch_size' => 15, 'check_http' => 0, 'exclude_media_links' => 1 ) );
		$size     = max( 1, min( 100, absint( $settings['batch_size'] ) ) );

		/*
		 * Elementor rendering can be substantially heavier than normal post-content
		 * parsing. Keep browser-driven AJAX batches deliberately small whenever
		 * Elementor is loaded so ordinary shared-host PHP time limits are respected.
		 * This does not reduce scan coverage; the browser simply performs more batches.
		 */
		if ( did_action( 'elementor/loaded' ) || class_exists( '\\Elementor\\Plugin' ) ) {
			$size = min( $size, 1 );
		}
		/* Rendered-page verification performs one same-site HTTP request per page. */
		$size = min( $size, 2 );

		$types    = array_values( array_filter( array_map( 'sanitize_key', (array) json_decode( $scan->post_types, true ) ) ) );
		$ids      = $this->next_post_ids( $types, (int) $scan->last_post_id, $size );
		if ( is_wp_error( $ids ) ) {
			$this->abort_batch_for_database_error( $scan_id, $token, $ids->get_error_message() );
		}
		$added       = 0;
		$processed   = 0;
		$last_id     = (int) $scan->last_post_id;
		$batch_start = microtime( true );
		$time_budget = 12.0;

		foreach ( $ids as $post_id ) {
			/* Leave headroom for DB writes and the JSON response. */
			if ( $processed > 0 && ( microtime( true ) - $batch_start ) >= $time_budget ) {
				break;
			}

			/*
			 * Crash/stall circuit breaker. A pathological frontend template, third-party
			 * shortcode, or exhausted loopback worker can terminate an AJAX request before
			 * last_post_id is committed. Without a guard the browser then retries the same
			 * object forever. Persist the attempt before any rendering work; after two
			 * abandoned attempts, record the object as explicitly unverified and advance.
			 * No SEO/orphan data is invented for that object.
			 */
			$attempt_key = 'ilsm_scan_try_' . absint( $scan_id ) . '_' . absint( $post_id );
			$attempts    = absint( get_transient( $attempt_key ) ) + 1;
			set_transient( $attempt_key, $attempts, 15 * MINUTE_IN_SECONDS );

			$last_id = max( $last_id, (int) $post_id );
			$post    = get_post( $post_id );
			$processed++;

			if ( $attempts >= 3 && $post instanceof WP_Post ) {
				$source_url = get_permalink( $post_id );
				if ( ! $source_url ) { $source_url = ILSM_SEO_Inspector::canonical_url( $post_id ); }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table. Mutable scan state must be written immediately and is not cacheable.
				$page_written = $wpdb->replace(
					ILSM_Database::table( 'pages' ),
					array(
						'scan_id'         => $scan_id,
						'post_id'         => $post_id,
						'title'           => ILSM_Text::substring( get_the_title( $post ), 0, 255 ),
						'url'             => $source_url,
						'url_hash'        => hash( 'sha256', $source_url ),
						'post_type'       => $post->post_type,
						'seo_score'       => 0,
						'seo_verified'    => 0,
						'render_verified' => 0,
					),
					array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
				);
				if ( false === $page_written ) {
					$this->abort_batch_for_database_error( $scan_id, $token, __( 'The scan could not store unverified page evidence. No progress was advanced.', 'dma-internlink-mapper' ) );
				}
				delete_transient( $attempt_key );
				continue;
			}

			if ( ! ILSM_SEO_Inspector::is_reportable( $post ) ) {
				delete_transient( $attempt_key );
				continue;
			}
			$source_url = get_permalink( $post_id );
			if ( ! $source_url ) { $source_url = ILSM_SEO_Inspector::canonical_url( $post_id ); }

			/*
			 * Rendered public HTML is the crawler source of truth. This captures
			 * theme templates, Elementor Theme Builder, plugin-generated CPT output,
			 * taxonomy/card widgets and other frontend HTML that is not stored in
			 * post_content. Saved-content extraction remains a conservative fallback
			 * only when the public URL cannot be verified.
			 */
			try {
				$rendered_snapshot = ILSM_Rendered_Page::snapshot( $post, true );
			} catch ( \Throwable $error ) {
				unset( $error );
				$rendered_snapshot = array( 'ok' => false, 'verified' => false, 'links' => array() );
			}
			$render_verified = ! empty( $rendered_snapshot['ok'] ) && ! empty( $rendered_snapshot['verified'] );

			try {
				ILSM_Local_Assistant::index_post( $scan_id, $post );
				ILSM_Crawler::index_post( $scan_id, $post );
			} catch ( \Throwable $error ) {
				/* Advisory indexing must never abort the core scan. */
				unset( $error );
			}

			$seo_score = 0;
			$seo_verified = 0;
			if ( $render_verified ) {
				$scan_analysis = ILSM_Page_SEO_Analyzer::analyze( $post_id, array(), $rendered_snapshot );
				if ( ! empty( $scan_analysis['verified'] ) && null !== ( $scan_analysis['score'] ?? null ) ) {
					$seo_score = max( 0, min( 100, absint( $scan_analysis['score'] ) ) );
					$seo_verified = 1;
					// Keep the breakdown available for the lifetime of a normal
					// report cycle. The scan ID in the key prevents stale data
					// from being used by a later completed scan.
					set_transient( 'ilsm_seo_breakdown_' . absint( $scan_id ) . '_' . absint( $post_id ), $scan_analysis, 30 * DAY_IN_SECONDS );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
			$page_written = $wpdb->replace(
				ILSM_Database::table( 'pages' ),
				array(
					'scan_id'         => $scan_id,
					'post_id'         => $post_id,
					'title'           => ILSM_Text::substring( get_the_title( $post ), 0, 255 ),
					'url'             => $source_url,
					'url_hash'        => hash( 'sha256', $source_url ),
					'post_type'       => $post->post_type,
					'seo_score'       => $seo_score,
					'seo_verified'    => $seo_verified,
					'render_verified' => $render_verified ? 1 : 0,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
			);
			if ( false === $page_written ) {
				$this->abort_batch_for_database_error( $scan_id, $token, __( 'The scan could not store page evidence. No progress was advanced.', 'dma-internlink-mapper' ) );
			}

			if ( $render_verified ) {
				$extracted_links = isset( $rendered_snapshot['links'] ) && is_array( $rendered_snapshot['links'] ) ? $rendered_snapshot['links'] : array();
			} else {
				try {
					$extracted_links = ILSM_Content_Extractor::extract( $post );
				} catch ( \Throwable $error ) {
					unset( $error );
					$extracted_links = array();
				}
			}

			foreach ( $extracted_links as $index => $link ) {
				$scope = sanitize_key( $link['scope'] ?? ( ILSM_Link_Normalizer::is_internal( $link['url'] ) ? 'internal' : 'external' ) );
				if ( 'internal' === $scope && ILSM_SEO_Inspector::is_utility_url( $link['url'] ) ) {
					continue;
				}
				if ( ! empty( $settings['exclude_media_links'] ) && $this->is_media_url( $link['url'] ) ) {
					continue;
				}
				if ( 'external' === $scope ) {
					$host = (string) wp_parse_url( $link['url'], PHP_URL_HOST );
					$destination = array(
						'type' => 'external', 'object_id' => 0, 'taxonomy' => '', 'post_type' => '', 'post_id' => 0,
						'label' => $host ? $host : __( 'External URL', 'dma-internlink-mapper' ),
					);
				} else {
					$destination = ILSM_Destination_Resolver::resolve( $link['url'] );
				}
				$target_id    = absint( $destination['post_id'] ?? 0 );
				if ( $target_id && ! ILSM_SEO_Inspector::is_reportable( $target_id ) ) {
					continue;
				}
				$target_title = (string) ( $destination['label'] ?? __( 'Unknown page', 'dma-internlink-mapper' ) );
				$anchor       = trim( (string) $link['anchor'] );
				$weak         = ILSM_Text::is_weak_anchor( $anchor ) ? 'weak_anchor' : '';
				if ( '' === $anchor ) { $weak = 'empty_anchor'; }
				if ( 'external' === $scope ) {
					$http = ! empty( $settings['check_external_http'] ) ? $this->inspect_external_url( $scan_id, $link['url'] ) : array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' );
				} else {
					$http = ! empty( $settings['check_http'] ) ? $this->inspect_internal_url( $scan_id, $link['url'] ) : array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' );
				}
				$link_issue = $http['issue_type'] ? $http['issue_type'] : $weak;
				$occurrence = hash( 'sha256', $post_id . '|' . $link['url'] . '|' . $anchor . '|' . $link['location'] . '|' . $index );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
				$ok = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
						'INSERT IGNORE INTO ' . ILSM_Database::table( 'links' ) . ' (scan_id,occurrence_key,source_post_id,target_post_id,destination_type,destination_object_id,destination_taxonomy,destination_post_type,source_title,target_title,source_url,target_url,source_url_hash,target_url_hash,anchor_text,context_excerpt,link_location,link_type,follow_status,http_status,redirect_url,issue_type,created_at) VALUES (%d,%s,%d,%d,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)',
						$scan_id, $occurrence, $post_id, $target_id, sanitize_key( $destination['type'] ?? 'unresolved' ), absint( $destination['object_id'] ?? 0 ), sanitize_key( $destination['taxonomy'] ?? '' ), sanitize_key( $destination['post_type'] ?? '' ), ILSM_Text::substring( get_the_title( $post ), 0, 255 ), ILSM_Text::substring( $target_title, 0, 255 ), $source_url, $link['url'], hash( 'sha256', $source_url ), hash( 'sha256', $link['url'] ), ILSM_Text::substring( $anchor, 0, 500 ), ILSM_Text::substring( $link['context'], 0, 1000 ), sanitize_key( $link['location'] ), sanitize_key( $link['type'] ), sanitize_key( $link['follow'] ), absint( $http['status'] ), esc_url_raw( $http['redirect_url'] ), sanitize_key( $link_issue ), current_time( 'mysql', true )
					)
				);
				if ( false === $ok ) {
					$this->abort_batch_for_database_error( $scan_id, $token, __( 'The scan could not store link evidence. No progress was advanced.', 'dma-internlink-mapper' ) );
				}
				if ( 0 !== $ok ) {
					$added++;
					if ( ! $this->record_issue( $scan_id, $post_id, $weak, $http['issue_type'], get_the_title( $post ), $scope ) ) {
						/* Roll back the newly inserted occurrence so a retry can recreate link + issue evidence together. */
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table. Immediate cleanup preserves retry consistency.
						$rolled_back = $wpdb->delete( ILSM_Database::table( 'links' ), array( 'scan_id' => absint( $scan_id ), 'occurrence_key' => $occurrence ), array( '%d', '%s' ) );
						if ( false === $rolled_back ) {
							$this->abort_batch_for_database_error( $scan_id, $token, __( "The scan could not store complete SEO issue evidence and could not roll back the new link occurrence. The scan was interrupted; run a fresh scan before relying on this scan's issue report.", 'dma-internlink-mapper' ) );
						}
						$this->abort_batch_for_database_error( $scan_id, $token, __( 'The scan could not store complete SEO issue evidence. The new link occurrence was rolled back and the scan was interrupted for a safe retry.', 'dma-internlink-mapper' ) );
					}
				}
			}
			delete_transient( $attempt_key );
		}

		$new_scanned = min( (int) $scan->total_items, (int) $scan->scanned_items + $processed );
		$exhausted   = count( $ids ) < $size && $processed >= count( $ids );
		$done        = $exhausted || $new_scanned >= (int) $scan->total_items;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$progress_updated = $wpdb->update(
			ILSM_Database::table( 'scans' ),
			array( 'scanned_items' => $new_scanned, 'links_found' => (int) $scan->links_found + $added, 'batch_no' => (int) $scan->batch_no + 1, 'last_post_id' => $last_id, 'lock_expires' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'heartbeat_at' => current_time( 'mysql', true ) ),
			array( 'id' => $scan_id, 'status' => 'running', 'lock_token' => hash( 'sha256', $token ) ),
			array( '%d', '%d', '%d', '%d', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
		if ( false === $progress_updated || 0 === $progress_updated ) {
			ILSM_Locks::release( 'scan_global', $token );
			wp_send_json_error( array( 'message' => __( 'Scan progress could not be committed because this browser no longer owns the scan or the database write failed.', 'dma-internlink-mapper' ) ), 409 );
		}
		if ( $done ) {
			$finalized = $this->finalize( $scan_id, $token );
			if ( is_wp_error( $finalized ) ) {
				ILSM_Locks::release( 'scan_global', $token );
				wp_send_json_error( array( 'message' => $finalized->get_error_message(), 'code' => $finalized->get_error_code() ), 500 );
			}
			ILSM_Locks::release( 'scan_global', $token );
		}
		wp_send_json_success( array( 'scan_id' => $scan_id, 'scanned' => $new_scanned, 'total' => (int) $scan->total_items, 'percent' => $scan->total_items ? min( 100, round( $new_scanned / $scan->total_items * 100 ) ) : 100, 'done' => $done, 'status' => $done ? 'completed' : 'running' ) );
	}

	private function next_post_ids( $types, $last_post_id, $limit ) {
		global $wpdb;
		if ( empty( $types ) ) { return array(); }
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args = array_merge( array( $last_post_id ), $types, array( $limit ) );
		$sql  = "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_status='publish' AND post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d";
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Core posts are scanned with cursor pagination and must be read fresh. SQL is assembled from fixed clauses and validated post-type values before prepare().
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'scan_cursor_query_failed', __( 'The scan could not load the next page cursor from the database. No progress was advanced.', 'dma-internlink-mapper' ) );
		}
		return array_map( 'absint', (array) $rows );
	}

	private function abort_batch_for_database_error( $scan_id, $token, $message ) {
		global $wpdb;
		$detail = sanitize_text_field( $message );
		if ( ! empty( $wpdb->last_error ) ) {
			$detail .= ' ' . sanitize_text_field( $wpdb->last_error );
		}
		$detail = ILSM_Text::substring( $detail, 0, 500 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table. Failure state must be persisted immediately and is not cacheable.
		$wpdb->update(
			ILSM_Database::table( 'scans' ),
			array(
				'status'        => 'interrupted',
				'lock_token'    => '',
				'lock_expires'  => null,
				'error_message' => $detail,
			),
			array( 'id' => absint( $scan_id ), 'status' => 'running', 'lock_token' => hash( 'sha256', (string) $token ) ),
			array( '%s', '%s', null, '%s' ),
			array( '%d', '%s', '%s' )
		);
		ILSM_Locks::release( 'scan_global', $token );
		wp_send_json_error( array( 'message' => $detail, 'code' => 'scan_database_write_failed' ), 500 );
	}

	private function record_issue( $scan_id, $post_id, $weak, $http_issue, $title, $scope = 'internal' ) {
		global $wpdb;
		$issues = array();
		$scope_label = 'external' === sanitize_key( $scope ) ? __( 'External link', 'dma-internlink-mapper' ) : __( 'Internal link', 'dma-internlink-mapper' );
		if ( $weak ) {
			/* translators: %s: link scope label, either Internal link or External link. */
			$issues[] = array( $weak, 'medium', sprintf( 'empty_anchor' === $weak ? __( '%s has empty anchor text.', 'dma-internlink-mapper' ) : __( '%s uses a weak anchor.', 'dma-internlink-mapper' ), $scope_label ) );
		}
		if ( $http_issue ) {
			/* translators: %s: link scope label, either Internal link or External link. */
			$issues[] = array( $http_issue, 'broken' === $http_issue ? 'high' : 'medium', sprintf( 'broken' === $http_issue ? __( '%s returned an error status.', 'dma-internlink-mapper' ) : __( '%s redirects to another URL.', 'dma-internlink-mapper' ), $scope_label ) );
		}
		$inserted_ids = array();
		foreach ( $issues as $issue ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom tables require direct database access.
			$inserted = $wpdb->insert( ILSM_Database::table( 'issues' ), array( 'scan_id' => $scan_id, 'post_id' => $post_id, 'issue_type' => $issue[0], 'severity' => $issue[1], 'message' => ILSM_Text::substring( $issue[2] . ' ' . $title, 0, 500 ), 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s', '%s', '%s' ) );
			if ( false === $inserted ) {
				foreach ( $inserted_ids as $inserted_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table. Immediate cleanup keeps a failed multi-issue write retryable.
					$wpdb->delete( ILSM_Database::table( 'issues' ), array( 'id' => absint( $inserted_id ) ), array( '%d' ) );
				}
				return false;
			}
			$inserted_ids[] = (int) $wpdb->insert_id;
		}
		return true;
	}

	private function finalize( $scan_id, $token ) {
		global $wpdb;
		$pages  = ILSM_Database::table( 'pages' );
		$links  = ILSM_Database::table( 'links' );
		$issues = ILSM_Database::table( 'issues' );
		$scans  = ILSM_Database::table( 'scans' );
		$transactional = ILSM_Database::begin_transaction();
		$step = __( 'final scan aggregation', 'dma-internlink-mapper' );

		try {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access and finalization must use fresh data.
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$pages} p LEFT JOIN (SELECT source_post_id,COUNT(*) outgoing_count,SUM(issue_type IN ('weak_anchor','empty_anchor')) weak_count,SUM(issue_type='broken') broken_count FROM {$links} WHERE scan_id=%d AND destination_type<>'external' GROUP BY source_post_id) o ON o.source_post_id=p.post_id LEFT JOIN (SELECT target_post_id,COUNT(*) incoming_count FROM {$links} WHERE scan_id=%d AND target_post_id>0 GROUP BY target_post_id) i ON i.target_post_id=p.post_id SET p.outgoing_count=COALESCE(o.outgoing_count,0),p.incoming_count=COALESCE(i.incoming_count,0),p.weak_anchor_count=COALESCE(o.weak_count,0),p.broken_count=COALESCE(o.broken_count,0) WHERE p.scan_id=%d", $scan_id, $scan_id, $scan_id ) );
			if ( false === $result ) {
				throw new RuntimeException( $step );
			}

			$step = __( 'render-verification count', 'dma-internlink-mapper' );
			$wpdb->last_error = '';
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifier is produced by the strict ILSM_Database allowlist. Finalization must read fresh mutable scan data.
			$unverified_raw = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pages} WHERE scan_id=%d AND render_verified=0", $scan_id ) );
			if ( '' !== (string) $wpdb->last_error || null === $unverified_raw ) {
				throw new RuntimeException( $step );
			}
			$unverified = (int) $unverified_raw;

			$step = __( 'orphan-state reconciliation', 'dma-internlink-mapper' );
			if ( 0 === $unverified ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is produced by the strict ILSM_Database allowlist. Mutable scan data must be updated immediately.
				$result = $wpdb->query( $wpdb->prepare( "UPDATE {$pages} SET is_orphan=IF(incoming_count=0,1,0) WHERE scan_id=%d", $scan_id ) );
			} else {
				/* Never assert orphan status from a crawl that could not verify every rendered source page. */
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is produced by the strict ILSM_Database allowlist. Mutable scan data must be updated immediately.
				$result = $wpdb->query( $wpdb->prepare( "UPDATE {$pages} SET is_orphan=0 WHERE scan_id=%d", $scan_id ) );
			}
			if ( false === $result ) {
				throw new RuntimeException( $step );
			}

			/* Finalize is retryable. Remove only derived orphan issues before recreating them. */
			$step = __( 'orphan-issue reconciliation', 'dma-internlink-mapper' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table. Derived issue state must be reconciled immediately.
			$deleted = $wpdb->delete( $issues, array( 'scan_id' => absint( $scan_id ), 'issue_type' => 'orphan_page' ), array( '%d', '%s' ) );
			if ( false === $deleted ) {
				throw new RuntimeException( $step );
			}
			if ( 0 === $unverified ) {
				$prefix = __( 'Page has no incoming internal links: ', 'dma-internlink-mapper' );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Values are passed through prepare(); derived issue rows must be written immediately.
				$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$issues} (scan_id,post_id,issue_type,severity,message,created_at) SELECT scan_id,post_id,'orphan_page','high',CONCAT(%s,title),UTC_TIMESTAMP() FROM {$pages} WHERE scan_id=%d AND is_orphan=1", $prefix, $scan_id ) );
				if ( false === $result ) {
					throw new RuntimeException( $step );
				}
			}

			$step = __( 'issue count', 'dma-internlink-mapper' );
			$wpdb->last_error = '';
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifier is produced by the strict ILSM_Database allowlist. Finalization must read fresh mutable scan data.
			$count_raw = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$issues} WHERE scan_id=%d", $scan_id ) );
			if ( '' !== (string) $wpdb->last_error || null === $count_raw ) {
				throw new RuntimeException( $step );
			}
			$count = (int) $count_raw;

			$scan_note = '';
			if ( $unverified > 0 ) {
				/* translators: %d: number of pages that could not be verified from rendered public HTML. */
				$scan_note = sprintf( __( '%d page(s) could not be verified from rendered public HTML. Orphan assertions were disabled for this scan.', 'dma-internlink-mapper' ), $unverified );
			}

			$step = __( 'scan completion state', 'dma-internlink-mapper' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table. Mutable scan state must be committed immediately.
			$completed = $wpdb->update(
				$scans,
				array( 'status' => 'completed', 'completed_at' => current_time( 'mysql', true ), 'issues_found' => $count, 'lock_expires' => null, 'error_message' => $scan_note ),
				array( 'id' => absint( $scan_id ), 'status' => 'running', 'lock_token' => hash( 'sha256', (string) $token ) ),
				array( '%s', '%s', '%d', null, '%s' ),
				array( '%d', '%s', '%s' )
			);
			if ( false === $completed || 0 === $completed ) {
				throw new RuntimeException( $step );
			}

			if ( $transactional && ! ILSM_Database::commit() ) {
				throw new RuntimeException( __( 'database transaction commit', 'dma-internlink-mapper' ) );
			}
			return true;
		} catch ( Throwable $error ) {
			if ( $transactional ) {
				ILSM_Database::rollback();
			}
			$db_error = sanitize_text_field( (string) $wpdb->last_error );
			/* translators: %s: name of the finalization step that failed. */
			$message = sprintf( __( 'The scan was interrupted because %s could not be completed safely.', 'dma-internlink-mapper' ), sanitize_text_field( $error->getMessage() ) );
			if ( '' !== $db_error ) {
				$message .= ' ' . $db_error;
			}
			$message = ILSM_Text::substring( $message, 0, 500 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table. Failure state must be persisted immediately and is not cacheable.
			$wpdb->update(
				$scans,
				array( 'status' => 'interrupted', 'lock_token' => '', 'lock_expires' => null, 'error_message' => $message ),
				array( 'id' => absint( $scan_id ), 'status' => 'running', 'lock_token' => hash( 'sha256', (string) $token ) ),
				array( '%s', '%s', null, '%s' ),
				array( '%d', '%s', '%s' )
			);
			return new WP_Error( 'scan_finalize_failed', $message );
		}
	}

	private function inspect_internal_url( $scan_id, $url ) {
		global $wpdb;
		static $request_cache = array();
		$url = ILSM_Link_Normalizer::normalize( $url, home_url( '/' ) );
		if ( ! $url ) { return array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' ); }
		$parts = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$home_host = strtolower( (string) ( $home['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || $host !== $home_host || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) { return array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' ); }
		$port = isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === $scheme ? 443 : 80 );
		$home_scheme = strtolower( (string) ( $home['scheme'] ?? 'https' ) );
		$home_port = isset( $home['port'] ) ? absint( $home['port'] ) : ( 'https' === $home_scheme ? 443 : 80 );
		if ( $port !== $home_port ) { return array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' ); }
		$path = (string) ( $parts['path'] ?? '/' );
		if ( 0 === strpos( $path, '/wp-admin/' ) || '/wp-login.php' === $path ) { return array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' ); }
		$hash = hash( 'sha256', $url );
		if ( isset( $request_cache[ $hash ] ) ) { return $request_cache[ $hash ]; }
		$local_post_id = (int) url_to_postid( $url );
		if ( $local_post_id ) {
			$local_post = get_post( $local_post_id );
			if ( ILSM_SEO_Inspector::is_indexable( $local_post ) ) { return $request_cache[ $hash ] = array( 'status' => 200, 'redirect_url' => '', 'issue_type' => '' ); }
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT http_status,redirect_url,issue_type FROM ' . ILSM_Database::table( 'links' ) . ' WHERE scan_id=%d AND target_url_hash=%s AND http_status>0 ORDER BY id DESC LIMIT 1', absint( $scan_id ), $hash ), ARRAY_A );
		if ( $existing ) { return $request_cache[ $hash ] = array( 'status' => absint( $existing['http_status'] ), 'redirect_url' => (string) $existing['redirect_url'], 'issue_type' => in_array( $existing['issue_type'], array( 'broken', 'redirect' ), true ) ? $existing['issue_type'] : '' ); }
		$transient_key = 'ilsm_http_' . substr( $hash, 0, 32 );
		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) && isset( $cached['status'], $cached['redirect_url'], $cached['issue_type'] ) ) { return $request_cache[ $hash ] = $cached; }
		$response = wp_safe_remote_get( $url, array( 'timeout' => 3, 'redirection' => 0, 'reject_unsafe_urls' => true, 'sslverify' => true, 'limit_response_size' => 1024, 'user-agent' => 'DMA InternLink Mapper/' . ILSM_VERSION . '; ' . home_url( '/' ) ) );
		$code = is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) );
		$result = array( 'status' => $code, 'redirect_url' => '', 'issue_type' => '' );
		if ( $code >= 300 && $code < 400 ) { $result['redirect_url'] = esc_url_raw( ILSM_Link_Normalizer::normalize( wp_remote_retrieve_header( $response, 'location' ), $url ) ); $result['issue_type'] = 'redirect'; }
		elseif ( $code >= 400 ) { $result['issue_type'] = 'broken'; }
		set_transient( $transient_key, $result, $result['issue_type'] ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $request_cache[ $hash ] = $result;
	}

	/** Optional external HTTP verification. Discovery itself never requires this. */
	private function inspect_external_url( $scan_id, $url ) {
		global $wpdb;
		static $request_cache = array();
		$url = ILSM_Link_Normalizer::normalize_any( $url );
		if ( ! $url || ILSM_Link_Normalizer::is_internal( $url ) ) { return array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' ); }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) { return array( 'status' => 0, 'redirect_url' => '', 'issue_type' => '' ); }
		$hash = hash( 'sha256', $url );
		if ( isset( $request_cache[ $hash ] ) ) { return $request_cache[ $hash ]; }
		$links_table = ILSM_Database::checked_table( ILSM_Database::table( 'links' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned scan data must be read fresh; persistent object caching could return stale HTTP status data.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT http_status,redirect_url,issue_type FROM %i WHERE scan_id=%d AND target_url_hash=%s AND destination_type=%s AND http_status>0 ORDER BY id DESC LIMIT 1',
				$links_table,
				absint( $scan_id ),
				$hash,
				'external'
			),
			ARRAY_A
		);
		if ( $existing ) { return $request_cache[ $hash ] = array( 'status' => absint( $existing['http_status'] ), 'redirect_url' => (string) $existing['redirect_url'], 'issue_type' => in_array( $existing['issue_type'], array( 'broken', 'redirect' ), true ) ? $existing['issue_type'] : '' ); }
		$transient_key = 'ilsm_ext_http_' . substr( $hash, 0, 32 );
		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) && isset( $cached['status'] ) ) { return $request_cache[ $hash ] = $cached; }
		$response = wp_safe_remote_get( $url, array( 'timeout' => 3, 'redirection' => 0, 'reject_unsafe_urls' => true, 'sslverify' => true, 'limit_response_size' => 1024, 'user-agent' => 'DMA InternLink Mapper/' . ILSM_VERSION ) );
		$code = is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) );
		$result = array( 'status' => $code, 'redirect_url' => '', 'issue_type' => '' );
		if ( $code >= 300 && $code < 400 ) {
			$result['redirect_url'] = esc_url_raw( ILSM_Link_Normalizer::normalize_any( wp_remote_retrieve_header( $response, 'location' ), $url ) );
			$result['issue_type'] = 'redirect';
		} elseif ( $code >= 400 ) {
			$result['issue_type'] = 'broken';
		}
		set_transient( $transient_key, $result, $result['issue_type'] ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $request_cache[ $hash ] = $result;
	}

	private function is_media_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg','jpeg','png','gif','webp','avif','svg','ico','pdf','zip','rar','7z','mp3','m4a','wav','ogg','mp4','m4v','mov','avi','webm','doc','docx','xls','xlsx','ppt','pptx' ), true );
	}

	/**
	 * Return authoritative scan state without changing the worker lease.
	 *
	 * This endpoint is used after a lost AJAX response. It deliberately avoids
	 * guessing that a timeout means interruption. The browser may continue only
	 * after the per-scan batch lock proves the previous PHP request has ended.
	 */
	public function status() {
		$this->guard();
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by guard().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => __( 'A valid scan is required.', 'dma-internlink-mapper' ) ), 400 );
		}

		$scans_table = ILSM_Database::table( 'scans' );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- %i safely quotes the strict allowlisted plugin table identifier; mutable scan state must be read fresh.
		$scan = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id,user_id,status,scanned_items,total_items,heartbeat_at,lock_expires,error_message FROM %i WHERE id=%d',
				$scans_table,
				$scan_id
			),
			ARRAY_A
		);
		if ( ! $scan ) {
			wp_send_json_error( array( 'message' => __( 'The selected scan no longer exists.', 'dma-internlink-mapper' ) ), 404 );
		}
		if ( (int) $scan['user_id'] !== get_current_user_id() && ! current_user_can( 'ilsm_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot inspect another user’s scan.', 'dma-internlink-mapper' ) ), 403 );
		}

		$batch_lock      = ILSM_Locks::inspect( 'scan_batch_' . $scan_id );
		$batch_in_flight = false;
		if ( $batch_lock && ! empty( $batch_lock['expires_at'] ) ) {
			$batch_expires   = strtotime( (string) $batch_lock['expires_at'] . ' UTC' );
			$batch_in_flight = (bool) ( $batch_expires && $batch_expires >= time() );
		}

		$total   = max( 0, (int) $scan['total_items'] );
		$scanned = max( 0, min( $total, (int) $scan['scanned_items'] ) );
		$percent = $total > 0 ? min( 100, (int) round( ( $scanned / $total ) * 100 ) ) : 100;

		wp_send_json_success(
			array(
				'scan_id'         => (int) $scan['id'],
				'status'          => sanitize_key( $scan['status'] ),
				'scanned'         => $scanned,
				'total'           => $total,
				'percent'         => $percent,
				'batch_in_flight' => $batch_in_flight,
				'heartbeat_at'    => (string) $scan['heartbeat_at'],
				'lock_expires'    => (string) $scan['lock_expires'],
				'error_message'   => sanitize_text_field( (string) $scan['error_message'] ),
			)
		);
	}

	public function action() {
		$this->guard();
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by guard().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by guard().
		$action  = isset( $_POST['scan_action'] ) ? sanitize_key( wp_unslash( $_POST['scan_action'] ) ) : '';

		if ( 'force_unlock' === $action ) {
			if ( ! current_user_can( 'ilsm_manage_settings' ) ) {
				wp_send_json_error( array( 'message' => __( 'Only an administrator can force-unlock a scan.', 'dma-internlink-mapper' ) ), 403 );
			}
			ILSM_Locks::force_release( 'scan_global' );
			$cleared_message = __( 'Lock was manually cleared.', 'dma-internlink-mapper' );
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable scan table; identifier comes from the strict ILSM_Database allowlist and the translated message is passed as a placeholder.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL uses an internally allowlisted identifier and prepared value placeholder.
					"UPDATE " . ILSM_Database::table( 'scans' ) . " SET status='interrupted',lock_token='',lock_expires=NULL,error_message=%s WHERE status='running'",
					$cleared_message
				)
			);
			wp_send_json_success( array( 'status' => 'interrupted', 'message' => __( 'The abandoned scan lock was cleared. You can start a new scan.', 'dma-internlink-mapper' ) ) );
		}

		$allowed = array( 'pause' => 'paused', 'resume' => 'running', 'cancel' => 'cancelled' );
		if ( ! isset( $allowed[ $action ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid action.', 'dma-internlink-mapper' ) ), 400 );
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from the strict internal allowlist and mutable scan state must be read fresh.
		$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Database::table( 'scans' ) . ' WHERE id=%d', $scan_id ), ARRAY_A );
		if ( ! $scan ) {
			wp_send_json_error( array( 'message' => __( 'The selected scan no longer exists.', 'dma-internlink-mapper' ) ), 404 );
		}
		if ( (int) $scan['user_id'] !== get_current_user_id() && ! current_user_can( 'ilsm_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot control another user’s scan.', 'dma-internlink-mapper' ) ), 403 );
		}

		if ( 'resume' === $action ) {
			if ( ! in_array( $scan['status'], array( 'paused', 'interrupted' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Only a paused or interrupted scan can be resumed.', 'dma-internlink-mapper' ) ), 409 );
			}

			/* Acquire a fresh worker lease without replacing another live owner. */
			self::recover_abandoned( 180 );
			$token = ILSM_Locks::acquire( 'scan_global', 300 );
			if ( is_wp_error( $token ) ) {
				wp_send_json_error( array( 'message' => $token->get_error_message() ), 409 );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable scan state requires a conditional direct write.
			$updated = $wpdb->update(
				ILSM_Database::table( 'scans' ),
				array(
					'status'        => 'running',
					'lock_token'    => hash( 'sha256', $token ),
					'lock_expires'  => gmdate( 'Y-m-d H:i:s', time() + 300 ),
					'heartbeat_at'  => current_time( 'mysql', true ),
					'error_message' => '',
				),
				array( 'id' => $scan_id, 'status' => $scan['status'] ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $updated ) {
				ILSM_Locks::release( 'scan_global', $token );
				wp_send_json_error( array( 'message' => __( 'The scan could not be resumed because the database write failed.', 'dma-internlink-mapper' ) ), 500 );
			}
			if ( 0 === $updated ) {
				ILSM_Locks::release( 'scan_global', $token );
				wp_send_json_error( array( 'message' => __( 'The scan state changed before it could be resumed. Refresh the page and try again.', 'dma-internlink-mapper' ) ), 409 );
			}
			wp_send_json_success( array( 'status' => 'running', 'scan_id' => $scan_id, 'token' => $token ) );
		}

		if ( 'pause' === $action && 'running' !== $scan['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Only a running scan can be paused.', 'dma-internlink-mapper' ) ), 409 );
		}
		if ( 'cancel' === $action && ! in_array( $scan['status'], array( 'running', 'paused', 'interrupted' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only a running, paused, or interrupted scan can be cancelled.', 'dma-internlink-mapper' ) ), 409 );
		}

		/*
		 * Pause/cancel are authenticated control-plane operations, not worker-plane
		 * operations. The scan owner (or a settings administrator) may stop a scan
		 * after reloading the dashboard even though the raw browser lease token is no
		 * longer available. The conditional write binds the transition to the exact
		 * persisted state and, for a running scan, to its server-stored lease hash.
		 * Batch work still requires the raw token and cannot commit after this state
		 * transition clears it.
		 */
		$where = array( 'id' => $scan_id, 'status' => $scan['status'] );
		$where_formats = array( '%d', '%s' );
		$lease_hash = '';
		if ( 'running' === $scan['status'] ) {
			$lease_hash = (string) $scan['lock_token'];
			if ( '' === $lease_hash ) {
				wp_send_json_error( array( 'message' => __( 'The running scan has no valid lease. Use Force unlock to recover it safely.', 'dma-internlink-mapper' ) ), 409 );
			}
			$where['lock_token'] = $lease_hash;
			$where_formats[] = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned mutable scan state requires an immediate conditional write.
		$updated = $wpdb->update(
			ILSM_Database::table( 'scans' ),
			array(
				'status'       => $allowed[ $action ],
				'lock_token'   => '',
				'lock_expires' => null,
				'heartbeat_at' => current_time( 'mysql', true ),
			),
			$where,
			array( '%s', '%s', null, '%s' ),
			$where_formats
		);
		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'The scan could not be updated because the database write failed.', 'dma-internlink-mapper' ) ), 500 );
		}
		if ( 0 === $updated ) {
			wp_send_json_error( array( 'message' => __( 'The scan state changed before this action could be applied. Refresh the page and try again.', 'dma-internlink-mapper' ) ), 409 );
		}

		$warning = '';
		if ( '' !== $lease_hash && ! ILSM_Locks::release_by_hash( 'scan_global', $lease_hash ) ) {
			$warning = __( 'The scan was stopped, but its database lock could not be removed immediately. It will expire automatically; an administrator can also use Force unlock.', 'dma-internlink-mapper' );
		}

		$response = array( 'status' => $allowed[ $action ] );
		if ( '' !== $warning ) {
			$response['warning'] = $warning;
		}
		wp_send_json_success( $response );
	}


	/**
	 * Build evidence-based diagnostics for the selected map page.
	 *
	 * Every value is derived from the latest completed crawl. No traffic,
	 * ranking, PageRank, or third-party metrics are estimated here.
	 *
	 * @param int   $scan_id Scan identifier.
	 * @param int   $post_id Post identifier.
	 * @param array $page    Stored page row.
	 * @return array
	 */
	private function map_diagnostics( $scan_id, $post_id, array $page ) {
		global $wpdb;

		$links_table         = ILSM_Database::table( 'links' );
		$pages_table         = ILSM_Database::table( 'pages' );
		$opportunities_table = ILSM_Database::table( 'opportunities' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh crawl diagnostics from plugin-owned custom tables.
		$incoming = $wpdb->get_row(
			$wpdb->prepare(
				' SELECT
					COUNT(*) AS total,
					COUNT(DISTINCT source_post_id) AS unique_sources,
					COUNT(DISTINCT NULLIF(LOWER(TRIM(anchor_text)), %s)) AS unique_anchors,
					SUM(CASE WHEN link_location = %s THEN 1 ELSE 0 END) AS contextual,
					SUM(CASE WHEN issue_type IN (%s, %s) THEN 1 ELSE 0 END) AS weak,
					SUM(CASE WHEN issue_type = %s THEN 1 ELSE 0 END) AS broken
				FROM %i
				WHERE scan_id = %d AND target_post_id = %d',
				'',
				'content',
				'weak_anchor',
				'empty_anchor',
				'broken',
				$links_table,
				$scan_id,
				$post_id
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh crawl diagnostics from plugin-owned custom tables.
		$outgoing = $wpdb->get_row(
			$wpdb->prepare(
				' SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN link_location = %s THEN 1 ELSE 0 END) AS contextual,
					SUM(CASE WHEN issue_type = %s THEN 1 ELSE 0 END) AS broken,
					SUM(CASE WHEN issue_type = %s OR (http_status BETWEEN 300 AND 399) THEN 1 ELSE 0 END) AS redirects
				FROM %i
				WHERE scan_id = %d AND source_post_id = %d',
				'content',
				'broken',
				'redirect',
				$links_table,
				$scan_id,
				$post_id
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh crawl comparison from plugin-owned custom tables.
		$peer_average = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT AVG(incoming_count) FROM %i WHERE scan_id = %d AND post_type = %s',
				$pages_table,
				$scan_id,
				(string) ( $page['post_type'] ?? '' )
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh local opportunity evidence from plugin-owned custom tables.
		$opportunity = $wpdb->get_row(
			$wpdb->prepare(
				' SELECT o.id, o.source_post_id, o.anchor_text, o.score, o.reason, p.title AS source_title
				FROM %i o
				LEFT JOIN %i p ON p.scan_id = o.scan_id AND p.post_id = o.source_post_id
				WHERE o.scan_id = %d AND o.target_post_id = %d AND o.status IN (%s, %s)
				ORDER BY o.score DESC, o.id DESC LIMIT 1',
				$opportunities_table,
				$pages_table,
				$scan_id,
				$post_id,
				'new',
				'ready'
			),
			ARRAY_A
		);

		$incoming = wp_parse_args( (array) $incoming, array( 'total' => 0, 'unique_sources' => 0, 'unique_anchors' => 0, 'contextual' => 0, 'weak' => 0, 'broken' => 0 ) );
		$outgoing = wp_parse_args( (array) $outgoing, array( 'total' => 0, 'contextual' => 0, 'broken' => 0, 'redirects' => 0 ) );

		$metrics = array(
			'contextual_incoming' => absint( $incoming['contextual'] ),
			'unique_sources'      => absint( $incoming['unique_sources'] ),
			'unique_anchors'      => absint( $incoming['unique_anchors'] ),
			'weak_anchors'        => absint( $incoming['weak'] ),
			'broken_links'        => absint( $incoming['broken'] ) + absint( $outgoing['broken'] ),
			'redirects'           => absint( $outgoing['redirects'] ),
			'peer_average'        => round( $peer_average, 1 ),
		);

		$title       = __( 'No major internal-link issue detected', 'dma-internlink-mapper' );
		$explanation = __( 'The latest crawl did not find a higher-priority structural issue for this page.', 'dma-internlink-mapper' );
		$action      = __( 'Keep the page under review after future scans.', 'dma-internlink-mapper' );
		$severity    = 'good';

		if ( ! empty( $page['is_orphan'] ) || 0 === $metrics['contextual_incoming'] ) {
			$title       = __( 'No contextual incoming links found', 'dma-internlink-mapper' );
			$explanation = __( 'The crawl found no links to this page inside scanned page content.', 'dma-internlink-mapper' );
			$action      = __( 'Review relevant source pages and add one natural contextual link where it helps the reader.', 'dma-internlink-mapper' );
			$severity    = 'high';
		} elseif ( $metrics['broken_links'] > 0 ) {
			$title       = __( 'Broken internal links affect this page', 'dma-internlink-mapper' );
			$explanation = sprintf(
				/* translators: %d: number of broken incoming and outgoing internal links. */
				_n( '%d broken internal link was found.', '%d broken internal links were found.', $metrics['broken_links'], 'dma-internlink-mapper' ),
				$metrics['broken_links']
			);
			$action      = __( 'Open the link report and repair the verified broken destinations first.', 'dma-internlink-mapper' );
			$severity    = 'high';
		} elseif ( $metrics['weak_anchors'] > 0 ) {
			$title       = __( 'Weak incoming anchor text was found', 'dma-internlink-mapper' );
			$explanation = sprintf(
				/* translators: %d: number of weak or empty incoming anchors. */
				_n( '%d incoming anchor is weak or empty.', '%d incoming anchors are weak or empty.', $metrics['weak_anchors'], 'dma-internlink-mapper' ),
				$metrics['weak_anchors']
			);
			$action      = __( 'Replace only generic or empty anchors with accurate wording that fits the surrounding sentence.', 'dma-internlink-mapper' );
			$severity    = 'medium';
		} elseif ( $metrics['redirects'] > 0 ) {
			$title       = __( 'Outgoing internal links use redirects', 'dma-internlink-mapper' );
			$explanation = sprintf(
				/* translators: %d: number of redirected outgoing links. */
				_n( '%d outgoing link redirects before reaching its destination.', '%d outgoing links redirect before reaching their destinations.', $metrics['redirects'], 'dma-internlink-mapper' ),
				$metrics['redirects']
			);
			$action      = __( 'Update verified redirected links to their final internal URLs where appropriate.', 'dma-internlink-mapper' );
			$severity    = 'medium';
		} elseif ( $metrics['unique_sources'] <= 1 && absint( $incoming['total'] ) > 1 ) {
			$title       = __( 'Incoming support is concentrated', 'dma-internlink-mapper' );
			$explanation = __( 'Multiple incoming links originate from only one unique source page.', 'dma-internlink-mapper' );
			$action      = __( 'Review other genuinely related pages instead of adding more links from the same source.', 'dma-internlink-mapper' );
			$severity    = 'medium';
		} elseif ( $peer_average >= 1 && absint( $page['incoming_count'] ?? 0 ) < $peer_average ) {
			$title       = __( 'Incoming links are below comparable scanned pages', 'dma-internlink-mapper' );
			$explanation = sprintf(
				/* translators: 1: selected page incoming link count, 2: average for scanned pages of the same post type. */
				__( 'This page has %1$d incoming links; scanned pages of the same post type average %2$s.', 'dma-internlink-mapper' ),
				absint( $page['incoming_count'] ?? 0 ),
				number_format_i18n( $peer_average, 1 )
			);
			$action      = __( 'Review high-relevance opportunities; do not add links merely to match an average.', 'dma-internlink-mapper' );
			$severity    = 'medium';
		} elseif ( $opportunity ) {
			$title       = __( 'A verified local link opportunity is available', 'dma-internlink-mapper' );
			$explanation = sprintf(
				/* translators: 1: source page title, 2: opportunity confidence score. */
				__( 'The strongest current opportunity is from “%1$s” with %2$d%% confidence.', 'dma-internlink-mapper' ),
				(string) ( $opportunity['source_title'] ?: __( 'an eligible source page', 'dma-internlink-mapper' ) ),
				absint( $opportunity['score'] )
			);
			$action      = __( 'Preview the exact sentence and confirm that the link genuinely helps the reader before inserting it.', 'dma-internlink-mapper' );
			$severity    = 'opportunity';
		}

		return array(
			'title'       => $title,
			'explanation' => $explanation,
			'action'      => $action,
			'severity'    => $severity,
			'metrics'     => $metrics,
			'opportunity' => $opportunity ? array(
				'source_title' => (string) $opportunity['source_title'],
				'anchor_text'  => (string) $opportunity['anchor_text'],
				'score'        => absint( $opportunity['score'] ),
				'reason'       => (string) $opportunity['reason'],
			) : null,
		);
	}

	public function map_data() {
		$this->guard( 'ilsm_view_reports' );
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : ILSM_Database::latest_completed_scan_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by the called authenticated action or surrounding request guard.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
		if ( ! $post_id ) { $post_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . ILSM_Database::table( 'pages' ) . ' WHERE scan_id=%d ORDER BY seo_score DESC LIMIT 1', $scan_id ) ); }
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
		$page = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Database::table( 'pages' ) . ' WHERE scan_id=%d AND post_id=%d', $scan_id, $post_id ), ARRAY_A );
		if ( ! $page ) {
			$post = get_post( $post_id );
			$type = $post ? get_post_type_object( $post->post_type ) : null;
			$label = $type && ! empty( $type->labels->singular_name ) ? $type->labels->singular_name : __( 'content item', 'dma-internlink-mapper' );
			$settings = wp_parse_args( get_option( 'ilsm_settings', array() ), array( 'post_types' => ILSM_Activator::default_post_types() ) );
			$enabled = $post && in_array( $post->post_type, (array) $settings['post_types'], true );
			if ( $enabled ) {
				/* translators: %s: Singular post type label, for example "Tour" or "Page". */
				$message = sprintf( __( 'This %s was not included in the latest completed scan. Run a fresh full scan to create its Link Map.', 'dma-internlink-mapper' ), $label );
			} else {
				/* translators: %s: Singular post type label, for example "Tour" or "Page". */
				$message = sprintf( __( 'This %s post type is not enabled for scanning. Enable it in Settings, save, and run a fresh full scan.', 'dma-internlink-mapper' ), $label );
			}
			wp_send_json_error( array( 'code' => 'page_not_in_scan', 'message' => $message, 'post_id' => $post_id ), 404 );
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
		$incoming = $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id AS id,source_title AS title,source_url AS url,'post' AS object_type,source_post_id AS object_id,'' AS taxonomy,'' AS post_type,anchor_text,issue_type FROM " . ILSM_Database::table( 'links' ) . ' WHERE scan_id=%d AND target_post_id=%d ORDER BY id DESC LIMIT 500', $scan_id, $post_id ), ARRAY_A );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
		$outgoing = $wpdb->get_results( $wpdb->prepare( 'SELECT id AS link_id,target_post_id AS id,target_title AS title,target_url AS url,destination_type AS object_type,destination_object_id AS object_id,destination_taxonomy AS taxonomy,destination_post_type AS post_type,anchor_text,issue_type FROM ' . ILSM_Database::table( 'links' ) . ' WHERE scan_id=%d AND source_post_id=%d ORDER BY id DESC LIMIT 500', $scan_id, $post_id ), ARRAY_A );
		foreach ( $outgoing as &$outgoing_node ) {
			if ( 'external' === (string) ( $outgoing_node['object_type'] ?? '' ) ) {
				if ( '' === trim( (string) ( $outgoing_node['title'] ?? '' ) ) || '0' === trim( (string) $outgoing_node['title'] ) ) {
					$external_host = sanitize_text_field( (string) wp_parse_url( (string) ( $outgoing_node['url'] ?? '' ), PHP_URL_HOST ) );
					$outgoing_node['title'] = $external_host ?: __( 'External destination', 'dma-internlink-mapper' );
				}
				continue;
			}
			if ( '' !== trim( (string) ( $outgoing_node['title'] ?? '' ) ) && '0' !== trim( (string) $outgoing_node['title'] ) && 'unresolved' !== (string) ( $outgoing_node['object_type'] ?? '' ) ) {
				continue;
			}
			$resolved = ILSM_Destination_Resolver::resolve( (string) ( $outgoing_node['url'] ?? '' ) );
			$outgoing_node['title']       = (string) $resolved['label'];
			$outgoing_node['object_type'] = (string) $resolved['type'];
			$outgoing_node['object_id']   = absint( $resolved['object_id'] );
			$outgoing_node['taxonomy']    = (string) $resolved['taxonomy'];
			$outgoing_node['post_type']   = (string) $resolved['post_type'];
			$outgoing_node['id']          = absint( $resolved['post_id'] );
			// Resolve for this response only. Report-view requests must not mutate scan rows.

		}
		unset( $outgoing_node );
		// Reports retain every individual link occurrence. The visual map instead
		// represents relationships between unique objects/URLs so repeated links do
		// not create overlapping nodes or compete for click targets.
		$incoming = $this->aggregate_map_nodes( $incoming );
		$outgoing = $this->aggregate_map_nodes( $outgoing );
		if ( $page ) {
			$can_read = current_user_can( 'read_post', $post_id );
			$can_edit = current_user_can( 'edit_post', $post_id );
			$page['permalink']         = $can_read ? get_permalink( $post_id ) : '';
			$page['edit_url']          = $can_edit ? get_edit_post_link( $post_id, 'raw' ) : '';
			$page['opportunities_url'] = add_query_arg(
				array(
					'page'           => 'ilsm-link-opportunities',
					'source_post_id' => $post_id,
				),
				admin_url( 'admin.php' )
			);
			$page['report_url']        = add_query_arg( array( 'page' => 'ilsm-link-report', 's' => (string) ( $page['title'] ?? '' ) ), admin_url( 'admin.php' ) );
			$page['diagnostics']       = $this->map_diagnostics( $scan_id, $post_id, $page );
		}

		wp_send_json_success( array( 'page' => $page, 'incoming' => $incoming, 'outgoing' => $outgoing ) );
	}

	/**
	 * Collapse raw link occurrences into stable visual-map nodes.
	 *
	 * @param array $nodes Raw relationship rows.
	 * @return array
	 */
	private function aggregate_map_nodes( $nodes ) {
		$grouped = array();
		foreach ( (array) $nodes as $node ) {
			$id   = absint( $node['id'] ?? 0 );
			$type = sanitize_key( $node['object_type'] ?? 'unresolved' );
			$url  = ILSM_Link_Normalizer::normalize( (string) ( $node['url'] ?? '' ) );
			$key  = 'post' === $type && $id ? 'post:' . $id : 'url:' . hash( 'sha256', $url ?: (string) ( $node['url'] ?? '' ) );

			if ( ! isset( $grouped[ $key ] ) ) {
				$node['occurrence_count'] = 0;
				$node['anchors'] = array();
				$grouped[ $key ] = $node;
			}

			$grouped[ $key ]['occurrence_count']++;
			$anchor = trim( (string) ( $node['anchor_text'] ?? '' ) );
			if ( '' !== $anchor && ! in_array( $anchor, $grouped[ $key ]['anchors'], true ) && count( $grouped[ $key ]['anchors'] ) < 5 ) {
				$grouped[ $key ]['anchors'][] = $anchor;
			}
			$severity = array( 'broken' => 3, 'redirect' => 2, 'weak_anchor' => 1, 'empty_anchor' => 1, '' => 0 );
			$current  = sanitize_key( $grouped[ $key ]['issue_type'] ?? '' );
			$candidate = sanitize_key( $node['issue_type'] ?? '' );
			if ( ( $severity[ $candidate ] ?? 1 ) > ( $severity[ $current ] ?? 0 ) ) {
				$grouped[ $key ]['issue_type'] = $candidate;
			}
		}

		foreach ( $grouped as &$node ) {
			$node['anchor_text'] = implode( ' · ', $node['anchors'] );
		}
		unset( $node );

		return array_slice( array_values( $grouped ), 0, 100 );
	}
}
