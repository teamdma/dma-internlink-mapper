<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class ILSM_Database {
    private static $transaction_active = false;

    /** Return plugin-owned table suffixes from one canonical allowlist. */
    public static function table_names() {
        return array( 'scans','pages','links','issues','keywords','phrases','feedback','opportunities','insertions','external_actions','search_console_urls','redirects','locks' );
    }

    /** Minimum columns required by the current runtime. */
    private static function schema_columns() {
        return array(
            'scans'              => array( 'id','user_id','status','total_items','scanned_items','last_post_id','lock_token','lock_expires','heartbeat_at','error_message' ),
            'pages'              => array( 'id','scan_id','post_id','url_hash','incoming_count','outgoing_count','is_orphan','seo_score','seo_verified','render_verified' ),
            'links'              => array( 'id','scan_id','occurrence_key','source_post_id','target_post_id','destination_type','target_url_hash','anchor_text','issue_type' ),
            'issues'             => array( 'id','scan_id','post_id','issue_type','severity','message' ),
            'keywords'           => array( 'id','scan_id','post_id','term','weight' ),
            'phrases'            => array( 'id','scan_id','post_id','phrase','normalized','source','priority' ),
            'feedback'           => array( 'id','user_id','source_post_id','target_post_id','decision' ),
            'opportunities'      => array( 'id','scan_id','opportunity_key','source_post_id','target_post_id','anchor_text','score','context_score','strategy_score','score_details','status' ),
            'insertions'         => array( 'id','scan_id','opportunity_id','source_post_id','target_post_id','user_id','before_hash','after_hash','insertion_status' ),
            'external_actions'   => array( 'id','user_id','source_type','source_id','action_type','target_url_hash' ),
            'search_console_urls'=> array( 'url_hash','url','clicks','impressions','position','http_status','imported_at' ),
            'redirects'          => array( 'id','source_path','source_hash','source_url_hash','destination_url','status_code' ),
            'locks'              => array( 'lock_name','lock_token','expires_at','created_at' ),
        );
    }

    /** Inspect required tables/columns after activation or from Site Health. */
    public static function schema_status() {
        global $wpdb;
        $missing_tables  = array();
        $missing_columns = array();
        foreach ( self::schema_columns() as $name => $required_columns ) {
            $table = self::table( $name );
            if ( ! self::table_exists( $table ) ) {
                $missing_tables[] = $name;
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit schema verification must inspect the current database definition.
            $columns = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
            $missing = array_values( array_diff( $required_columns, array_map( 'strval', $columns ) ) );
            if ( $missing ) {
                $missing_columns[ $name ] = $missing;
            }
        }
        return array(
            'healthy'         => empty( $missing_tables ) && empty( $missing_columns ),
            'missing_tables'  => $missing_tables,
            'missing_columns' => $missing_columns,
        );
    }

    public static function table( $name ) {
        global $wpdb;
        $allowed = self::table_names();
        if ( ! in_array( $name, $allowed, true ) ) { throw new InvalidArgumentException( 'Invalid table.' ); }
        return $wpdb->prefix . 'ilsm_' . $name;
    }

    /**
     * Confirm that a table identifier belongs to this plugin.
     *
     * Every dynamic table name must originate from table() and pass this
     * allowlist check before it is used with WordPress's %i identifier placeholder.
     *
     * @param string $table Full database table name.
     * @return string
     */
    public static function checked_table( $table ) {
        foreach ( self::table_names() as $name ) {
            $allowed = self::table( $name );
            if ( hash_equals( $allowed, (string) $table ) ) {
                return $allowed;
            }
        }
        throw new InvalidArgumentException( 'Invalid plugin table identifier.' );
    }

    public static function table_exists( $table ) {
        global $wpdb;
        $like = $wpdb->esc_like( (string) $table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table;
    }

    public static function latest_completed_scan_id() {
        global $wpdb;
        $table = self::table( 'scans' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned scan state must be read fresh.
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status='completed' ORDER BY id DESC LIMIT 1", $table ) );
    }

    /** Ensure every data table supports rollback before destructive operations. */
    public static function transactions_supported() {
        global $wpdb;
        foreach ( self::table_names() as $name ) {
            $table = self::table( $name );
            if ( ! self::table_exists( $table ) ) {
                continue;
            }
            $like = $wpdb->esc_like( $table );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );
            if ( ! $status || empty( $status['Engine'] ) || 'InnoDB' !== $status['Engine'] ) {
                return false;
            }
        }
        return true;
    }

    public static function begin_transaction() {
        global $wpdb;
        if ( self::$transaction_active || ! self::transactions_supported() ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return false;
        }
        self::$transaction_active = true;
        return true;
    }

    public static function commit() {
        global $wpdb;
        if ( ! self::$transaction_active ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $result = false !== $wpdb->query( 'COMMIT' );
        if ( $result ) {
            self::$transaction_active = false;
        }
        return $result;
    }

    public static function rollback() {
        global $wpdb;
        if ( ! self::$transaction_active ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $result = false !== $wpdb->query( 'ROLLBACK' );
        self::$transaction_active = false;
        return $result;
    }
}
