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

        $installed = (string) get_option( 'ilsm_db_version', '0.0.0' );
        if ( defined( 'ILSM_DB_VERSION' ) && version_compare( $installed, ILSM_DB_VERSION, '>=' ) ) {
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
            delete_option( self::LOCK_OPTION );
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
