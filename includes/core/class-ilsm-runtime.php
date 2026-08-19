<?php
/** Runtime bootstrap. Keeps composition out of the plugin entry file. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Runtime {
    private static $registered = false;

    public static function register() {
        if ( self::$registered ) { return; }
        self::$registered = true;
        add_action( 'init', array( __CLASS__, 'boot' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ), 1 );
    }

    /** Run schema upgrades only for an authenticated site administrator. */
    public static function maybe_upgrade() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ILSM_Migrator::maybe_upgrade();
    }

    public static function boot() {
        if ( ! is_admin() ) { return; }

        ILSM_Site_Health::register();
        ILSM_Admin_Assets::register();
        ILSM_Admin::instance();
        ILSM_Local_Assistant::instance();
        ILSM_Link_Opportunities::instance();
        ILSM_Elementor::instance();
        ILSM_Scan_Manager::instance();
        ILSM_Architecture_Service::instance();
        ILSM_External_Link_Health::register();
    }
}
