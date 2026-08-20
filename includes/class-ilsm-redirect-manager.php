<?php
/** Exact, reviewed, same-site redirects created by the broken-link resolver. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Redirect_Manager {
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		add_action( 'admin_post_ilsm_delete_redirect', array( __CLASS__, 'delete_redirect' ) );
	}

	public static function canonical_path( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) { return ''; }
		$path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
		$path = preg_replace( '~/+~', '/', $path );
		return is_string( $path ) && strlen( $path ) <= 700 ? $path : '';
	}

	public static function is_same_site_url( $url ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		$url_port = absint( $parts['port'] ?? ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80 ) );
		$home_port = absint( $home['port'] ?? ( 'https' === strtolower( (string) ( $home['scheme'] ?? '' ) ) ? 443 : 80 ) );
		return $url && is_array( $parts ) && is_array( $home ) && empty( $parts['user'] ) && empty( $parts['pass'] )
			&& strtolower( (string) ( $parts['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) ) && $url_port === $home_port;
	}

	private static function protected_path( $path ) {
		return '/' === $path || (bool) preg_match( '~(?:^|/)(?:wp-admin(?:/|$)|wp-login\.php$|wp-json(?:/|$)|xmlrpc\.php$)~i', $path );
	}

	public static function save( $source_url, $destination_url, $status_code ) {
		global $wpdb;
		$status_code = absint( $status_code );
		if ( ! in_array( $status_code, array( 301, 302 ), true ) ) { return new WP_Error( 'invalid_code', __( 'Choose a 301 or 302 redirect.', 'dma-internlink-mapper' ) ); }
		if ( ! self::is_same_site_url( $source_url ) || ! self::is_same_site_url( $destination_url ) ) { return new WP_Error( 'same_site_only', __( 'Redirect source and destination must belong to this site.', 'dma-internlink-mapper' ) ); }
		$source_path = self::canonical_path( $source_url );
		$destination_path = self::canonical_path( $destination_url );
		if ( ! $source_path || ! $destination_path || self::protected_path( $source_path ) || self::protected_path( $destination_path ) ) { return new WP_Error( 'protected_path', __( 'That route is protected or invalid.', 'dma-internlink-mapper' ) ); }
		if ( hash_equals( $source_path, $destination_path ) ) { return new WP_Error( 'same_target', __( 'A URL cannot redirect to itself.', 'dma-internlink-mapper' ) ); }
		$table = ILSM_Database::table( 'redirects' );
		$cursor = $destination_path;
		for ( $depth = 0; $depth < 10; $depth++ ) {
			if ( hash_equals( $source_path, $cursor ) ) { return new WP_Error( 'redirect_loop', __( 'This redirect would create a loop.', 'dma-internlink-mapper' ) ); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned redirect records require direct reads/writes and must reflect current state.
			$next = $wpdb->get_var( $wpdb->prepare( 'SELECT destination_url FROM %i WHERE source_hash=%s LIMIT 1', $table, hash( 'sha256', $cursor ) ) );
			if ( ! $next ) { break; }
			$cursor = self::canonical_path( $next );
		}
		if ( 10 === $depth ) { return new WP_Error( 'redirect_chain', __( 'The destination already has an excessive redirect chain.', 'dma-internlink-mapper' ) ); }
		$now = current_time( 'mysql', true );
		$source_url_hash = hash( 'sha256', ILSM_Link_Normalizer::normalize_any( home_url( $source_path ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned redirect records require direct reads/writes and must reflect current state.
		$ok = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (source_path,source_hash,source_url_hash,destination_url,status_code,created_by,created_at,updated_at) VALUES (%s,%s,%s,%s,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE source_url_hash=VALUES(source_url_hash),destination_url=VALUES(destination_url),status_code=VALUES(status_code),updated_at=VALUES(updated_at)', $table, $source_path, hash( 'sha256', $source_path ), $source_url_hash, esc_url_raw( $destination_url ), $status_code, get_current_user_id(), $now, $now ) );
		return false === $ok ? new WP_Error( 'database_error', __( 'The redirect could not be saved.', 'dma-internlink-mapper' ) ) : true;
	}

	/** Backfill URL hashes for legacy redirect rows that do not yet have them. */
	public static function reconcile_source_hashes() {
		global $wpdb;
		$table = ILSM_Database::table( 'redirects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned redirect records require direct reads/writes and must reflect current state.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_path FROM %i WHERE source_url_hash='' ORDER BY id ASC LIMIT 200", $table ), ARRAY_A );
		foreach ( $rows as $row ) {
			$normalized = ILSM_Link_Normalizer::normalize_any( home_url( $row['source_path'] ) );
			if ( $normalized ) { /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded migration write to one plugin-owned row. */ $wpdb->update( $table, array( 'source_url_hash' => hash( 'sha256', $normalized ) ), array( 'id' => absint( $row['id'] ) ), array( '%s' ), array( '%d' ) ); }
		}
	}

	public static function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( version_compare( (string) get_option( 'ilsm_db_version', '0' ), ILSM_DB_VERSION, '<' ) ) { return; }
		$signature = (string) get_option( 'ilsm_schema_signature', '' );
		if ( ! defined( 'ILSM_SCHEMA_SIGNATURE' ) || ! hash_equals( ILSM_SCHEMA_SIGNATURE, $signature ) ) { return; }
		global $wpdb;
		$path = self::canonical_path( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- canonical_path strips the query, normalizes slashes, and rejects unsafe paths before use.
		if ( ! $path || self::protected_path( $path ) ) { return; }
		$table = ILSM_Database::table( 'redirects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned redirect records require direct reads/writes and must reflect current state.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT destination_url,status_code FROM %i WHERE source_hash=%s AND source_path=%s LIMIT 1', $table, hash( 'sha256', $path ), $path ), ARRAY_A );
		if ( ! $row || ! self::is_same_site_url( $row['destination_url'] ) ) { return; }
		wp_safe_redirect( $row['destination_url'], in_array( absint( $row['status_code'] ), array( 301, 302 ), true ) ? absint( $row['status_code'] ) : 302, 'DMA InternLink Mapper' );
		exit;
	}

	public static function delete_redirect() {
		if ( ! current_user_can( 'ilsm_manage_settings' ) ) { wp_die( esc_html__( 'Permission denied.', 'dma-internlink-mapper' ), '', array( 'response' => 403 ) ); }
		$id = absint( wp_unslash( $_POST['redirect_id'] ?? 0 ) );
		check_admin_referer( 'ilsm_delete_redirect_' . $id );
		if ( $id ) { global $wpdb; /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Nonce-protected deletion of one plugin-owned redirect. */ $wpdb->delete( ILSM_Database::table( 'redirects' ), array( 'id' => $id ), array( '%d' ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=ilsm-broken-links#ilsm-managed-redirects' ) );
		exit;
	}

	public static function render_admin_table() {
		global $wpdb;
		$table = ILSM_Database::table( 'redirects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned redirect records require direct reads/writes and must reflect current state.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,source_path,destination_url,status_code FROM %i ORDER BY id DESC LIMIT 100', $table ), ARRAY_A );
		echo '<section class="ilsm-panel" id="ilsm-managed-redirects"><div class="ilsm-panel-head"><div><h2>' . esc_html__( 'Managed SEO Redirects', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Exact-path redirects created by DMA. Delete restores the old URL behavior immediately.', 'dma-internlink-mapper' ) . '</p></div></div>';
		if ( ! $rows ) { echo '<p>' . esc_html__( 'No DMA redirects have been created.', 'dma-internlink-mapper' ) . '</p></section>'; return; }
			echo '<div class="ilsm-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Old path', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Destination', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Type', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Action', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td><code>' . esc_html( $row['source_path'] ) . '</code></td><td><a href="' . esc_url( $row['destination_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $row['destination_url'] ) . '</a></td><td>' . absint( $row['status_code'] ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ilsm_delete_redirect"><input type="hidden" name="redirect_id" value="' . absint( $row['id'] ) . '">';
				wp_nonce_field( 'ilsm_delete_redirect_' . absint( $row['id'] ) );
				echo '<button type="submit" class="ilsm-delete-redirect" data-confirm="' . esc_attr__( 'Delete this redirect and restore the old URL behavior?', 'dma-internlink-mapper' ) . '"><i class="fa fa-trash-o" aria-hidden="true"></i><span>' . esc_html__( 'Delete redirect', 'dma-internlink-mapper' ) . '</span></button></form></td></tr>';
			}
		echo '</tbody></table></div></section>';
	}
}
