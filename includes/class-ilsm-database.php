<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class ILSM_Database {
    private static $transaction_active = false;

    public static function table( $name ) {
        global $wpdb;
        $allowed = array( 'scans','pages','links','issues','keywords','phrases','feedback','opportunities','insertions','external_actions','search_console_urls','redirects','locks' );
        if ( ! in_array( $name, $allowed, true ) ) { throw new InvalidArgumentException( 'Invalid table.' ); }
        return $wpdb->prefix . 'ilsm_' . $name;
    }

    /**
     * Confirm that a table identifier belongs to this plugin.
     *
     * SQL placeholders cannot represent identifiers, so every dynamic table
     * name must originate from table() and pass this allowlist check.
     *
     * @param string $table Full database table name.
     * @return string
     */
    public static function checked_table( $table ) {
        foreach ( array( 'scans','pages','links','issues','keywords','phrases','feedback','opportunities','insertions','external_actions','search_console_urls','redirects','locks' ) as $name ) {
            $allowed = self::table( $name );
            if ( hash_equals( $allowed, (string) $table ) ) {
                return $allowed;
            }
        }
        throw new InvalidArgumentException( 'Invalid plugin table identifier.' );
    }

    public static function table_exists( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    public static function latest_completed_scan_id() {
        global $wpdb;
        $table = self::table( 'scans' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE status='completed' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
    }

    /** Ensure every data table supports rollback before destructive operations. */
    public static function transactions_supported() {
        global $wpdb;
        foreach ( array( 'scans','pages','links','issues','keywords','phrases','feedback','opportunities','insertions','external_actions','search_console_urls','redirects','locks' ) as $name ) {
            $table = self::table( $name );
            if ( ! self::table_exists( $table ) ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
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
