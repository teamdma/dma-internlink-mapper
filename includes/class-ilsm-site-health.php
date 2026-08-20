<?php
/**
 * Site Health integration.
 *
 * @package Internal_Link_SEO_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers local Site Health checks for required runtime features. */
final class ILSM_Site_Health {
	/** Register checks and dependency notices. */
	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
	}

	/** Add direct Site Health tests. */
	public static function tests( $tests ) {
		$tests['direct']['ilsm_php_runtime'] = array(
			'label' => __( 'DMA InternLink Mapper PHP compatibility', 'dma-internlink-mapper' ),
			'test'  => array( __CLASS__, 'test_php_runtime' ),
		);
		$tests['direct']['ilsm_dom_extension'] = array(
			'label' => __( 'DMA InternLink Mapper PHP DOM support', 'dma-internlink-mapper' ),
			'test'  => array( __CLASS__, 'test_dom_extension' ),
		);
		$tests['direct']['ilsm_database_schema'] = array(
			'label' => __( 'DMA InternLink Mapper database schema', 'dma-internlink-mapper' ),
			'test'  => array( __CLASS__, 'test_database_schema' ),
		);
		$tests['direct']['ilsm_database_tables'] = array(
			'label' => __( 'DMA InternLink Mapper database tables', 'dma-internlink-mapper' ),
			'test'  => array( __CLASS__, 'test_database_tables' ),
		);
		$tests['direct']['ilsm_operation_locks'] = array(
			'label' => __( 'DMA InternLink Mapper operation locks', 'dma-internlink-mapper' ),
			'test'  => array( __CLASS__, 'test_operation_locks' ),
		);
		return $tests;
	}

	/** Standard result badge. */
	private static function badge() {
		return array(
			'label' => __( 'DMA InternLink Mapper', 'dma-internlink-mapper' ),
			'color' => 'blue',
		);
	}

	/** Check the declared minimum PHP version. */
	public static function test_php_runtime() {
		$healthy = version_compare( PHP_VERSION, '7.4', '>=' );

		if ( $healthy ) {
			/* translators: %s: Installed PHP version. */
			$message = sprintf( __( 'PHP %s satisfies the plugin minimum of PHP 7.4.', 'dma-internlink-mapper' ), PHP_VERSION );
		} else {
			/* translators: %s: Installed PHP version. */
			$message = sprintf( __( 'PHP %s is installed. Upgrade to PHP 7.4 or newer before using the plugin.', 'dma-internlink-mapper' ), PHP_VERSION );
		}

		return array(
			'label'       => $healthy ? __( 'The PHP runtime is compatible', 'dma-internlink-mapper' ) : __( 'The PHP runtime is unsupported', 'dma-internlink-mapper' ),
			'status'      => $healthy ? 'good' : 'critical',
			'badge'       => self::badge(),
			'description' => sprintf( '<p>%s</p>', esc_html( $message ) ),
			'test'        => 'ilsm_php_runtime',
		);
	}

	/** Check required DOM support. */
	public static function test_dom_extension() {
		$available = class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' );
		return array(
			'label'       => $available ? __( 'PHP DOM support is available', 'dma-internlink-mapper' ) : __( 'PHP DOM support is missing', 'dma-internlink-mapper' ),
			'status'      => $available ? 'good' : 'critical',
			'badge'       => self::badge(),
			'description' => sprintf( '<p>%s</p>', esc_html( $available ? __( 'Safe HTML analysis and verified link insertion can run on this server.', 'dma-internlink-mapper' ) : __( 'Ask your hosting provider to enable the PHP DOM/XML extension. Link insertion remains blocked until it is available.', 'dma-internlink-mapper' ) ) ),
			'test'        => 'ilsm_dom_extension',
		);
	}

	/** Check the installed schema version. */
	public static function test_database_schema() {
		$installed           = (string) get_option( 'ilsm_db_version', '0.0.0' );
		$installed_signature = (string) get_option( 'ilsm_schema_signature', '' );
		$healthy = defined( 'ILSM_DB_VERSION' ) && defined( 'ILSM_SCHEMA_SIGNATURE' )
			&& version_compare( $installed, ILSM_DB_VERSION, '>=' )
			&& hash_equals( ILSM_SCHEMA_SIGNATURE, $installed_signature );
		return array(
			'label'       => $healthy ? __( 'The plugin database schema is current', 'dma-internlink-mapper' ) : __( 'The plugin database schema needs repair', 'dma-internlink-mapper' ),
			'status'      => $healthy ? 'good' : 'recommended',
			'badge'       => self::badge(),
			'description' => sprintf( '<p>%s</p>', esc_html( $healthy ? __( 'The installed schema matches this plugin release.', 'dma-internlink-mapper' ) : __( 'Open DMA InternLink Mapper Health Audit and run the schema repair action.', 'dma-internlink-mapper' ) ) ),
			'test'        => 'ilsm_database_schema',
		);
	}

	/** Verify all plugin-owned tables and runtime-critical columns exist. */
	public static function test_database_tables() {
		$status  = ILSM_Database::schema_status();
		$healthy = ! empty( $status['healthy'] );

		if ( $healthy ) {
			$message = __( 'The plugin can read and write its indexed scan data using the current table structure.', 'dma-internlink-mapper' );
		} else {
			$parts = array();
			if ( ! empty( $status['missing_tables'] ) ) {
				/* translators: %s: Comma-separated list of missing database table suffixes. */
				$parts[] = sprintf( __( 'Missing tables: %s.', 'dma-internlink-mapper' ), implode( ', ', array_map( 'sanitize_key', $status['missing_tables'] ) ) );
			}
			if ( ! empty( $status['missing_columns'] ) ) {
				$column_parts = array();
				foreach ( $status['missing_columns'] as $table_name => $columns ) {
					$column_parts[] = sanitize_key( $table_name ) . ': ' . implode( ', ', array_map( 'sanitize_key', $columns ) );
				}
				/* translators: %s: Semicolon-separated table and missing-column names. */
				$parts[] = sprintf( __( 'Missing columns: %s.', 'dma-internlink-mapper' ), implode( '; ', $column_parts ) );
			}
			$message = implode( ' ', $parts ) . ' ' . __( 'Run the schema repair action before starting another scan.', 'dma-internlink-mapper' );
		}

		return array(
			'label'       => $healthy ? __( 'All plugin database tables are available', 'dma-internlink-mapper' ) : __( 'The plugin database structure is incomplete', 'dma-internlink-mapper' ),
			'status'      => $healthy ? 'good' : 'critical',
			'badge'       => self::badge(),
			'description' => sprintf( '<p>%s</p>', esc_html( trim( $message ) ) ),
			'test'        => 'ilsm_database_tables',
		);
	}

	/** Detect expired operation locks without modifying data. */
	public static function test_operation_locks() {
		global $wpdb;
		$table = ILSM_Database::table( 'locks' );
		if ( ! ILSM_Database::table_exists( $table ) ) {
			return array(
				'label'       => __( 'Operation locks cannot be inspected', 'dma-internlink-mapper' ),
				'status'      => 'recommended',
				'badge'       => self::badge(),
				'description' => '<p>' . esc_html__( 'Repair the plugin database schema first.', 'dma-internlink-mapper' ) . '</p>',
				'test'        => 'ilsm_operation_locks',
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock state must be read directly and must not be cached.
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE expires_at < UTC_TIMESTAMP()',
				$table
			)
		);
		$healthy = 0 === $expired;

		if ( $healthy ) {
			$message = __( 'Scans and insertions are not blocked by expired locks.', 'dma-internlink-mapper' );
		} else {
			$message = sprintf(
				/* translators: %d: Number of expired operation locks. */
				_n(
					'%d expired lock can be safely recovered from the plugin dashboard.',
					'%d expired locks can be safely recovered from the plugin dashboard.',
					$expired,
					'dma-internlink-mapper'
				),
				$expired
			);
		}

		return array(
			'label'       => $healthy ? __( 'No expired operation locks were found', 'dma-internlink-mapper' ) : __( 'Expired operation locks were found', 'dma-internlink-mapper' ),
			'status'      => $healthy ? 'good' : 'recommended',
			'badge'       => self::badge(),
			'description' => sprintf( '<p>%s</p>', esc_html( $message ) ),
			'test'        => 'ilsm_operation_locks',
		);
	}

	/** Show a clear dependency warning in wp-admin. */
	public static function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ( class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' ) ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'DMA InternLink Mapper:', 'dma-internlink-mapper' ) . '</strong> ' . esc_html__( 'The PHP DOM/XML extension is missing. Safe link analysis and insertion are disabled until your hosting provider enables it.', 'dma-internlink-mapper' ) . '</p></div>';
	}
}
