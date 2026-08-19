<?php
/**
 * Atomic database-backed locks.
 *
 * @package Internal_Link_SEO_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ILSM_Locks {
	/** Acquire a named lock. */
	public static function acquire( $name, $ttl = 1800 ) {
		global $wpdb;
		$table = ILSM_Database::table( 'locks' );
		$name  = sanitize_key( $name );
		$token = wp_generate_password( 48, false, false );
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE lock_name=%s AND expires_at<UTC_TIMESTAMP()", $name ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
				"INSERT IGNORE INTO {$table} (lock_name,lock_token,expires_at,created_at) VALUES (%s,%s,%s,%s)",
				$name,
				hash( 'sha256', $token ),
				$until,
				$now
			)
		);
		return 1 === $inserted ? $token : new WP_Error( 'locked', __( 'This operation is already running.', 'dma-internlink-mapper' ) );
	}

	/** Return lock metadata, or null when no lock exists. */
	public static function inspect( $name ) {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
				'SELECT lock_name,expires_at,created_at FROM ' . ILSM_Database::table( 'locks' ) . ' WHERE lock_name=%s',
				sanitize_key( $name )
			),
			ARRAY_A
		);
	}

	/** Delete locks that are expired or have had no heartbeat for the supplied age. */
	public static function recover_stale( $name, $stale_after = 600 ) {
		global $wpdb;
		$name        = sanitize_key( $name );
		$stale_after = max( 60, absint( $stale_after ) );
		$lock        = self::inspect( $name );
		if ( ! $lock ) {
			return false;
		}

		$expires = strtotime( (string) $lock['expires_at'] . ' UTC' );
		$created = strtotime( (string) $lock['created_at'] . ' UTC' );
		$expired = $expires && $expires < time();
		// Scan locks use a 30-minute TTL. The inferred heartbeat is expires_at minus 30 minutes.
		$heartbeat = $expires ? $expires - 1800 : $created;
		$stale     = $heartbeat && $heartbeat < ( time() - $stale_after );
		if ( ! $expired && ! $stale ) {
			return false;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		return false !== $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
				'DELETE FROM ' . ILSM_Database::table( 'locks' ) . ' WHERE lock_name=%s',
				$name
			)
		);
	}

	/**
	 * Reclaim a missing or expired lock with a token already authenticated by
	 * the owning operation. This never replaces a live lock.
	 */
	public static function reclaim( $name, $token, $ttl = 1800 ) {
		global $wpdb;
		$table = ILSM_Database::table( 'locks' );
		$name  = sanitize_key( $name );
		$hash  = hash( 'sha256', (string) $token );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE lock_name=%s AND expires_at<UTC_TIMESTAMP()", $name ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
				"INSERT IGNORE INTO {$table} (lock_name,lock_token,expires_at,created_at) VALUES (%s,%s,%s,%s)",
				$name,
				$hash,
				$until,
				$now
			)
		);
		if ( 1 === $inserted ) {
			return true;
		}

		// A matching live row may already exist after a concurrent retry. MySQL
		// returns 0 affected rows when expires_at already has the same value, which
		// is still a successful ownership check rather than a lost lock.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
				"UPDATE {$table} SET expires_at=%s WHERE lock_name=%s AND lock_token=%s",
				$until,
				$name,
				$hash
			)
		);
		if ( false === $updated ) {
			return false;
		}
		if ( 1 === $updated ) {
			return true;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
				"SELECT 1 FROM {$table} WHERE lock_name=%s AND lock_token=%s AND expires_at>=UTC_TIMESTAMP() LIMIT 1",
				$name,
				$hash
			)
		);
	}

	/** Refresh a lock owned by the supplied token. */
	public static function refresh( $name, $token, $ttl = 1800 ) {
		global $wpdb;
		$table = ILSM_Database::table( 'locks' );
		$name  = sanitize_key( $name );
		$hash  = hash( 'sha256', (string) $token );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
				"UPDATE {$table} SET expires_at=%s WHERE lock_name=%s AND lock_token=%s",
				$until,
				$name,
				$hash
			)
		);
		if ( false === $result ) {
			return false;
		}
		if ( 1 === $result ) {
			return true;
		}

		// Zero affected rows can mean the timestamp was already identical. Verify
		// ownership and validity explicitly instead of treating that as expiration.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
				"SELECT 1 FROM {$table} WHERE lock_name=%s AND lock_token=%s AND expires_at>=UTC_TIMESTAMP() LIMIT 1",
				$name,
				$hash
			)
		);
	}

	/** Release a lock only when the token matches. */
	public static function release( $name, $token ) {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		return false !== $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
				'DELETE FROM ' . ILSM_Database::table( 'locks' ) . ' WHERE lock_name=%s AND lock_token=%s',
				sanitize_key( $name ),
				hash( 'sha256', (string) $token )
			)
		);
	}

	/**
	 * Release a named lock using a trusted, already-hashed ownership token.
	 *
	 * This is intended for server-side control paths that have authenticated an
	 * operation from its persisted scan row but no longer possess the raw browser
	 * lease token. Never pass request data directly to this method.
	 *
	 * @param string $name       Lock name.
	 * @param string $token_hash Persisted SHA-256 token hash.
	 * @return bool True when the delete query succeeded, false on database error.
	 */
	public static function release_by_hash( $name, $token_hash ) {
		global $wpdb;
		$name       = sanitize_key( $name );
		$token_hash = strtolower( trim( (string) $token_hash ) );
		if ( 64 !== strlen( $token_hash ) || ! ctype_xdigit( $token_hash ) ) {
			return false;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. This revokes exactly one persisted lease and must read mutable lock state directly.
		return false !== $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and an allowlisted identifier before prepare().
				'DELETE FROM ' . ILSM_Database::table( 'locks' ) . ' WHERE lock_name=%s AND lock_token=%s',
				$name,
				$token_hash
			)
		);
	}

	/** Force-release a named lock. Callers must enforce capability and nonce checks. */
	public static function force_release( $name ) {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
		return false !== $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
				'DELETE FROM ' . ILSM_Database::table( 'locks' ) . ' WHERE lock_name=%s',
				sanitize_key( $name )
			)
		);
	}
}
