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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock state is plugin-owned and must be read/written fresh.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE lock_name=%s AND expires_at<UTC_TIMESTAMP()', $table, $name ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock acquisition must bypass persistent caching.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (lock_name,lock_token,expires_at,created_at) VALUES (%s,%s,%s,%s)',
				$table,
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock metadata is mutable operation state.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT lock_name,expires_at,created_at FROM %i WHERE lock_name=%s',
				ILSM_Database::table( 'locks' ),
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

		/* Delete only the same stale row inspected above. */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete prevents stale recovery from removing a replacement lock.
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE lock_name=%s AND expires_at=%s AND created_at=%s',
				ILSM_Database::table( 'locks' ),
				$name,
				(string) $lock['expires_at'],
				(string) $lock['created_at']
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock state is plugin-owned and must be read/written fresh.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE lock_name=%s AND expires_at<UTC_TIMESTAMP()', $table, $name ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock acquisition must bypass persistent caching.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (lock_name,lock_token,expires_at,created_at) VALUES (%s,%s,%s,%s)',
				$table,
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock refresh must bypass persistent caching.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET expires_at=%s WHERE lock_name=%s AND lock_token=%s',
				$table,
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock ownership must be verified against current database state.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE lock_name=%s AND lock_token=%s AND expires_at>=UTC_TIMESTAMP() LIMIT 1',
				$table,
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock refresh must bypass persistent caching.
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET expires_at=%s WHERE lock_name=%s AND lock_token=%s',
				$table,
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock ownership must be verified against current database state.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE lock_name=%s AND lock_token=%s AND expires_at>=UTC_TIMESTAMP() LIMIT 1',
				$table,
				$name,
				$hash
			)
		);
	}

	/** Release a lock only when the token matches. */
	public static function release( $name, $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock release must bypass persistent caching.
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE lock_name=%s AND lock_token=%s',
				ILSM_Database::table( 'locks' ),
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
	 * @return bool True only when the owned lock row was removed.
	 */
	public static function release_by_hash( $name, $token_hash ) {
		global $wpdb;
		$name       = sanitize_key( $name );
		$token_hash = strtolower( trim( (string) $token_hash ) );
		if ( 64 !== strlen( $token_hash ) || ! ctype_xdigit( $token_hash ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock release must bypass persistent caching.
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE lock_name=%s AND lock_token=%s',
				ILSM_Database::table( 'locks' ),
				$name,
				$token_hash
			)
		);
	}

	/** Force-release a named lock. Callers must enforce capability and nonce checks. */
	public static function force_release( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock release must bypass persistent caching.
		return false !== $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE lock_name=%s',
				ILSM_Database::table( 'locks' ),
				sanitize_key( $name )
			)
		);
	}

	/** Release a lock only when it is still expired at delete time. */
	public static function release_expired( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic stale-lock recovery must evaluate expiry in the delete statement.
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE lock_name=%s AND expires_at<UTC_TIMESTAMP()',
				ILSM_Database::table( 'locks' ),
				sanitize_key( $name )
			)
		);
	}
}
