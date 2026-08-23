<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Runs schema upgrades behind an atomic, expiring option lock.
 */
final class ILSM_Migrator {
    const LOCK_OPTION = 'ilsm_migration_lock';
    const LOCK_TTL    = 300;

    public static function maybe_upgrade() {
        // Defense in depth: schema changes must never be initiated by lower-privileged wp-admin users.
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $installed           = (string) get_option( 'ilsm_db_version', '0.0.0' );
        $installed_signature = (string) get_option( 'ilsm_schema_signature', '' );
        $version_current     = defined( 'ILSM_DB_VERSION' ) && version_compare( $installed, ILSM_DB_VERSION, '>=' );
        $signature_current   = defined( 'ILSM_SCHEMA_SIGNATURE' ) && hash_equals( ILSM_SCHEMA_SIGNATURE, $installed_signature );
        if ( $version_current && $signature_current ) {
            return true;
        }

        $token = wp_generate_uuid4();
        $lock  = array(
            'token'      => $token,
            'expires_at' => time() + self::LOCK_TTL,
        );

        if ( ! add_option( self::LOCK_OPTION, $lock, '', false ) ) {
            $existing = get_option( self::LOCK_OPTION, array() );
            if ( ! is_array( $existing ) || empty( $existing['expires_at'] ) || (int) $existing['expires_at'] >= time() ) {
                return false;
            }

            // Atomically remove only the exact expired value we inspected. A
            // concurrent request may already have replaced it with a fresh lock;
            // an unconditional delete_option() could erase that new owner.
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete is required for atomic stale migration-lock takeover.
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE option_name=%s AND option_value=%s',
                    $wpdb->options,
                    self::LOCK_OPTION,
                    maybe_serialize( $existing )
                )
            );
            if ( 1 !== (int) $deleted ) {
                return false;
            }
            wp_cache_delete( self::LOCK_OPTION, 'options' );
            if ( ! add_option( self::LOCK_OPTION, $lock, '', false ) ) {
                return false;
            }
        }

        try {
            ILSM_Activator::activate();
            delete_option( 'ilsm_migration_error' );
            return true;
        } catch ( Throwable $error ) {
            update_option(
                'ilsm_migration_error',
                array(
                    'message' => sanitize_text_field( $error->getMessage() ),
                    'time'    => time(),
                ),
                false
            );
            return false;
        } finally {
            $current = get_option( self::LOCK_OPTION, array() );
            if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) {
                delete_option( self::LOCK_OPTION );
            }
        }
    }
}
