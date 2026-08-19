<?php
/** Secure, local-only Search Console export import and shared metrics service. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Search_Console_Import {
	const MAX_CSV_BYTES = 5242880;
	const MAX_ZIP_BYTES = 10485760;
	const MAX_ROWS      = 10000;
	const MAX_ZIP_FILES = 25;
	const MAX_EXPANDED  = 26214400;

	public static function register() {
		add_action( 'admin_post_ilsm_import_search_console_csv', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ilsm_delete_search_console_csv', array( __CLASS__, 'handle_delete' ) );
	}

	private static function authorize( $action ) {
		if ( ! current_user_can( 'ilsm_manage_settings' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage imported Search Console data.', 'dma-internlink-mapper' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}

	private static function redirect( $notice, $count = 0 ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'ilsm-settings', 'ilsm_csv_notice' => sanitize_key( $notice ), 'ilsm_csv_count' => absint( $count ) ), admin_url( 'admin.php' ) ) . '#ilsm-search-console-import' );
		exit;
	}

	public static function handle_import() {
		self::authorize( 'ilsm_import_search_console_csv' );
		$file = isset( $_FILES['search_console_csv'] ) && is_array( $_FILES['search_console_csv'] ) ? $_FILES['search_console_csv'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verifies this action's nonce first; the PHP-managed upload is validated and never retained.
		if ( UPLOAD_ERR_OK !== absint( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { self::redirect( 'upload_failed' ); }
		$name = sanitize_file_name( wp_unslash( $file['name'] ?? '' ) );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$actual_size = filesize( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Read-only temporary-upload validation.
		$size = false === $actual_size ? 0 : absint( $actual_size );
		if ( $size < 1 || ! in_array( $extension, array( 'csv', 'zip' ), true ) || ( 'csv' === $extension && $size > self::MAX_CSV_BYTES ) || ( 'zip' === $extension && $size > self::MAX_ZIP_BYTES ) ) { self::redirect( 'invalid_file' ); }

		$result = 'zip' === $extension ? self::parse_zip( $file['tmp_name'] ) : self::parse_csv_file( $file['tmp_name'], $size );
		if ( is_wp_error( $result ) ) { self::redirect( $result->get_error_code() ); }
		if ( ! self::replace_dataset( $result ) ) { self::redirect( 'database_failed' ); }
		self::redirect( 'imported', count( $result ) );
	}

	private static function parse_csv_file( $path, $size ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed parsing of PHP-managed temporary upload.
		if ( false === $handle ) { return new WP_Error( 'upload_failed' ); }
		$sample = fread( $handle, min( 4096, $size ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded temporary-upload validation.
		if ( false === $sample || false !== strpos( $sample, "\0" ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a read-only PHP-managed temporary upload stream.
			fclose( $handle );
			return new WP_Error( 'invalid_file' );
		}
		rewind( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewind temporary stream after validation.
		$result = self::parse_stream( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a read-only PHP-managed temporary upload stream.
		fclose( $handle );
		return $result;
	}

	private static function parse_zip( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'zip_unavailable' ); }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) || $zip->numFiles < 1 || $zip->numFiles > self::MAX_ZIP_FILES ) { if ( $zip->status === ZipArchive::ER_OK ) { $zip->close(); } return new WP_Error( 'invalid_zip' ); }
		$selected = ''; $expanded = 0;
		$accepted_names = array( 'pages.csv', 'page.csv', 'paginas.csv', 'paginas-principales.csv', 'paginas-superiores.csv', 'seiten.csv', 'pagina-s.csv' );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) ) { $zip->close(); return new WP_Error( 'invalid_zip' ); }
			$entry = (string) ( $stat['name'] ?? '' );
			$entry_size = absint( $stat['size'] ?? 0 ); $compressed = absint( $stat['comp_size'] ?? 0 );
			$expanded += $entry_size;
			if ( $expanded > self::MAX_EXPANDED || $entry_size > self::MAX_CSV_BYTES || ( $compressed > 0 && $entry_size / $compressed > 100 ) ) { $zip->close(); return new WP_Error( 'unsafe_zip' ); }
			$base = strtolower( sanitize_file_name( basename( str_replace( '\\', '/', $entry ) ) ) );
			if ( in_array( $base, $accepted_names, true ) || 'pages.csv' === $base ) { $selected = $entry; }
		}
		if ( ! $selected ) { $zip->close(); return new WP_Error( 'missing_pages_report' ); }
		$handle = $zip->getStream( $selected );
		if ( false === $handle ) { $zip->close(); return new WP_Error( 'invalid_zip' ); }
		$temp = fopen( 'php://temp/maxmemory:' . self::MAX_CSV_BYTES, 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded in-memory stream; no persistent write.
		if ( false === $temp ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded ZIP entry stream.
			fclose( $handle );
			$zip->close();
			return new WP_Error( 'invalid_zip' );
		}
		$copied = stream_copy_to_stream( $handle, $temp, self::MAX_CSV_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_copy_to_stream -- Bounded transfer between temporary streams.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded ZIP entry stream.
		fclose( $handle );
		$zip->close();
		if ( false === $copied || $copied > self::MAX_CSV_BYTES ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded in-memory stream.
			fclose( $temp );
			return new WP_Error( 'unsafe_zip' );
		}
		rewind( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewind bounded in-memory ZIP entry.
		$result = self::parse_stream( $temp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded in-memory stream.
		fclose( $temp );
		return $result;
	}

	private static function parse_stream( $handle ) {
		$first_line = fgets( $handle, 65536 );
		if ( false === $first_line || false !== strpos( $first_line, "\0" ) ) { return new WP_Error( 'invalid_file' ); }
		$delimiter = ','; $best = 0;
		foreach ( array( ',', ';', "\t" ) as $candidate ) { $count = count( str_getcsv( $first_line, $candidate ) ); if ( $count > $best ) { $best = $count; $delimiter = $candidate; } }
		rewind( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewind temporary/import stream after delimiter detection.
		$columns = null; $url_column = null; $clicks_column = null; $impressions_column = null; $position_column = null;
		for ( $line = 0; $line < 10 && false !== ( $candidate = fgetcsv( $handle, 65536, $delimiter ) ); $line++ ) {
			$map = array(); foreach ( (array) $candidate as $index => $label ) { $map[ self::normalize_header( $label ) ] = $index; }
			$clicks = self::first_column( $map, array( 'clicks', 'klikken', 'clics', 'klicks', 'click' ) );
			$impressions = self::first_column( $map, array( 'impressions', 'vertoningen', 'impressionen', 'impresiones', 'impression' ) );
			if ( null === $clicks || null === $impressions ) { continue; }
			$url = self::first_column( $map, array( 'top pages', 'pages', 'page', 'url', 'paginas principales', 'paginas', 'pagina', 'seiten', 'seite' ) );
			if ( null === $url ) { return new WP_Error( self::first_column( $map, array( 'top queries', 'queries', 'query', 'zoekopdrachten', 'consultas', 'suchanfragen' ) ) !== null ? 'queries_report' : 'invalid_columns' ); }
			$columns = $map; $url_column = $url; $clicks_column = $clicks; $impressions_column = $impressions;
			$position_column = self::first_column( $map, array( 'position', 'average position', 'gemiddelde positie', 'posicion media', 'durchschnittliche position' ) );
			break;
		}
		if ( null === $columns ) { return new WP_Error( 'invalid_columns' ); }

		$rows = array(); $read = 0;
		while ( false !== ( $record = fgetcsv( $handle, 65536, $delimiter ) ) ) {
			if ( ++$read > self::MAX_ROWS ) { return new WP_Error( 'too_many_rows' ); }
			$url = self::same_site_url( $record[ $url_column ] ?? '' );
			if ( ! $url ) { continue; }
			$hash = hash( 'sha256', $url );
			$rows[ $hash ] = array( 'url' => $url, 'clicks' => self::unsigned_number( $record[ $clicks_column ] ?? 0 ), 'impressions' => self::unsigned_number( $record[ $impressions_column ] ?? 0 ), 'position' => null === $position_column ? 0 : self::decimal_number( $record[ $position_column ] ?? 0 ) );
		}
		return $rows ? $rows : new WP_Error( 'no_same_site_rows' );
	}

	private static function normalize_header( $label ) {
		$label = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $label );
		$label = strtolower( remove_accents( trim( $label ) ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $label ) );
	}

	private static function first_column( $columns, $names ) { foreach ( $names as $name ) { $name = self::normalize_header( $name ); if ( array_key_exists( $name, $columns ) ) { return $columns[ $name ]; } } return null; }

	private static function same_site_url( $raw ) {
		$url = esc_url_raw( trim( (string) $raw ), array( 'http', 'https' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) || ! ILSM_Link_Normalizer::is_internal( $url ) ) { return ''; }
		return esc_url_raw( ILSM_Link_Normalizer::normalize( $url, home_url( '/' ) ) );
	}

	private static function unsigned_number( $value ) { return min( PHP_INT_MAX, absint( preg_replace( '/[^0-9]/', '', (string) $value ) ) ); }
	private static function decimal_number( $value ) { $value = str_replace( ',', '.', preg_replace( '/[^0-9,.-]/', '', (string) $value ) ); return max( 0, min( 99999999.99, round( (float) $value, 2 ) ) ); }

	private static function replace_dataset( $rows ) {
		global $wpdb; $table = ILSM_Database::table( 'search_console_urls' );
		if ( ! ILSM_Database::begin_transaction() ) { return false; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic plugin-owned dataset replacement; the table identifier comes from ILSM_Database's strict allowlist.
		if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) { ILSM_Database::rollback(); return false; }
		$now = current_time( 'mysql', true );
		foreach ( array_chunk( $rows, 100, true ) as $chunk ) {
			$values = array(); $args = array();
			foreach ( $chunk as $hash => $row ) { $values[] = '(%s,%s,%d,%d,%f,%d,NULL,%s)'; array_push( $args, $hash, $row['url'], $row['clicks'], $row['impressions'], $row['position'], 0, $now ); }
			$sql = "INSERT INTO {$table} (url_hash,url,clicks,impressions,position,http_status,checked_at,imported_at) VALUES " . implode( ',', $values );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin identifier is allowlisted; every row value uses a placeholder in a bounded chunk.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) { ILSM_Database::rollback(); return false; }
		}
		return ILSM_Database::commit();
	}

	/** Shared, read-only metrics source for plugin reports and ranking. */
	public static function metrics_for_url( $url ) {
		global $wpdb; $url = self::same_site_url( $url ); if ( ! $url ) { return array(); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table identifier comes from the plugin's strict allowlist and the URL hash is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT clicks,impressions,position,http_status,checked_at FROM ' . ILSM_Database::table( 'search_console_urls' ) . ' WHERE url_hash=%s', hash( 'sha256', $url ) ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** Bounded bulk metrics lookup used by editor-side opportunity ranking. */
	public static function metrics_for_urls( $urls ) {
		global $wpdb;
		$normalized = array();
		foreach ( array_slice( array_values( array_unique( (array) $urls ) ), 0, 1500 ) as $url ) {
			$url = self::same_site_url( $url );
			if ( $url ) { $normalized[ hash( 'sha256', $url ) ] = $url; }
		}
		if ( ! $normalized ) { return array(); }
		$out = array();
		$table = ILSM_Database::table( 'search_console_urls' );
		foreach ( array_chunk( array_keys( $normalized ), 200 ) as $hashes ) {
			$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded indexed lookup; runtime placeholders are paired with validated hashes and the table identifier is allowlisted.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT url_hash,clicks,impressions,position,http_status,checked_at FROM {$table} WHERE url_hash IN ({$placeholders})", $hashes ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$hash = (string) ( $row['url_hash'] ?? '' );
				if ( isset( $normalized[ $hash ] ) ) { $out[ $normalized[ $hash ] ] = $row; }
			}
		}
		return $out;
	}

	public static function handle_delete() { self::authorize( 'ilsm_delete_search_console_csv' ); global $wpdb; $table = ILSM_Database::table( 'search_console_urls' ); /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nonce-authorized deletion from an allowlisted plugin table. */ $wpdb->query( "DELETE FROM {$table}" ); self::redirect( 'deleted' ); }

	public static function render() {
		global $wpdb; $table = ILSM_Database::table( 'search_console_urls' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Small aggregate over an allowlisted plugin-owned table.
		$summary = $wpdb->get_row( "SELECT COUNT(*) total,SUM(clicks) clicks,SUM(impressions) impressions,MAX(imported_at) imported_at FROM {$table}", ARRAY_A );
		self::notice();
		echo '<section id="ilsm-search-console-import" class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-upload" aria-hidden="true"></i></span><div><h2>' . esc_html__( 'Search Console data source', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Optional local evidence for broken-link priority, SEO reports, and internal-link opportunity ordering. DMA never contacts Google and never treats imported data as live HTTP proof.', 'dma-internlink-mapper' ) . '</p></div></div>';
		if ( ! current_user_can( 'ilsm_manage_settings' ) && ! current_user_can( 'manage_options' ) ) { echo '<p>' . esc_html__( 'An administrator can manage this optional dataset.', 'dma-internlink-mapper' ) . '</p></section>'; return; }
		echo '<div class="ilsm-search-kpis"><div class="ilsm-panel"><span>' . esc_html__( 'Stored pages', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( number_format_i18n( absint( $summary['total'] ?? 0 ) ) ) . '</strong></div><div class="ilsm-panel"><span>' . esc_html__( 'Clicks', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( number_format_i18n( absint( $summary['clicks'] ?? 0 ) ) ) . '</strong></div><div class="ilsm-panel"><span>' . esc_html__( 'Impressions', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( number_format_i18n( absint( $summary['impressions'] ?? 0 ) ) ) . '</strong></div></div>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ilsm_import_search_console_csv">'; wp_nonce_field( 'ilsm_import_search_console_csv' ); echo '<label class="ilsm-field"><span>' . esc_html__( 'Google Search Console export', 'dma-internlink-mapper' ) . '</span><input type="file" name="search_console_csv" accept=".csv,.zip,text/csv,application/zip" required><small>' . esc_html__( 'Upload the complete Search Console ZIP export or its Pages.csv file. Comma, semicolon, tab, English, Dutch, French, Spanish, and German headers are supported. Maximum 10,000 page rows.', 'dma-internlink-mapper' ) . '</small></label><button class="ilsm-btn ilsm-btn-primary" type="submit">' . esc_html__( 'Import and replace data source', 'dma-internlink-mapper' ) . '</button></form>';
		if ( ! empty( $summary['total'] ) ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Delete all imported Search Console rows?', 'dma-internlink-mapper' ) ) ) . ');"><input type="hidden" name="action" value="ilsm_delete_search_console_csv">'; wp_nonce_field( 'ilsm_delete_search_console_csv' ); echo '<button class="ilsm-btn" type="submit">' . esc_html__( 'Delete imported data', 'dma-internlink-mapper' ) . '</button></form>'; }
		echo '</section>';
	}

	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized read-only redirect notice.
		$key = isset( $_GET['ilsm_csv_notice'] ) ? sanitize_key( wp_unslash( $_GET['ilsm_csv_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized read-only count.
		$count = isset( $_GET['ilsm_csv_count'] ) ? absint( $_GET['ilsm_csv_count'] ) : 0;
		$messages = array( 'upload_failed' => __( 'The export upload did not complete.', 'dma-internlink-mapper' ), 'invalid_file' => __( 'Choose a Search Console CSV up to 5 MB or ZIP up to 10 MB.', 'dma-internlink-mapper' ), 'invalid_columns' => __( 'A Pages report with Page, Clicks, and Impressions columns was not found.', 'dma-internlink-mapper' ), 'queries_report' => __( 'This is the Queries report. Upload Pages.csv or the complete Search Console ZIP export.', 'dma-internlink-mapper' ), 'missing_pages_report' => __( 'The ZIP does not contain Pages.csv. Export Performance results with the Pages tab included.', 'dma-internlink-mapper' ), 'zip_unavailable' => __( 'This server cannot read ZIP files. Extract and upload Pages.csv instead.', 'dma-internlink-mapper' ), 'invalid_zip' => __( 'The ZIP export is invalid or contains too many files.', 'dma-internlink-mapper' ), 'unsafe_zip' => __( 'The ZIP was rejected by the compressed-size safety limits.', 'dma-internlink-mapper' ), 'too_many_rows' => __( 'The Pages report exceeds the secure 10,000-row limit.', 'dma-internlink-mapper' ), 'no_same_site_rows' => __( 'No valid URLs for this WordPress site were found.', 'dma-internlink-mapper' ), 'database_failed' => __( 'The previous data source was preserved because the import could not be committed.', 'dma-internlink-mapper' ), 'deleted' => __( 'Imported Search Console data was deleted.', 'dma-internlink-mapper' ) );
		if ( 'imported' === $key ) {
			/* translators: %d: number of imported Search Console page rows. */
			$messages['imported'] = sprintf( _n( '%d Search Console page imported.', '%d Search Console pages imported.', $count, 'dma-internlink-mapper' ), $count );
		}
		if ( isset( $messages[ $key ] ) ) { echo '<div class="notice ' . ( in_array( $key, array( 'imported', 'deleted' ), true ) ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>'; }
	}
}
