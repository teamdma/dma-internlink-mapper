<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class ILSM_Activator {
    public static function activate() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $scans = $wpdb->prefix . 'ilsm_scans';
        $pages = $wpdb->prefix . 'ilsm_pages';
        $links = $wpdb->prefix . 'ilsm_links';
        $issues = $wpdb->prefix . 'ilsm_issues';
        $keywords = $wpdb->prefix . 'ilsm_keywords';
        $phrases = $wpdb->prefix . 'ilsm_phrases';
        $feedback = $wpdb->prefix . 'ilsm_feedback';
        $opportunities = $wpdb->prefix . 'ilsm_opportunities';
        $insertions = $wpdb->prefix . 'ilsm_insertions';
        $external_actions = $wpdb->prefix . 'ilsm_external_actions';
        $search_console_urls = $wpdb->prefix . 'ilsm_search_console_urls';
        $redirects = $wpdb->prefix . 'ilsm_redirects';
        $locks = $wpdb->prefix . 'ilsm_locks';
        dbDelta( "CREATE TABLE {$scans} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            post_types text NOT NULL,
            total_items bigint(20) unsigned NOT NULL DEFAULT 0,
            scanned_items bigint(20) unsigned NOT NULL DEFAULT 0,
            links_found bigint(20) unsigned NOT NULL DEFAULT 0,
            issues_found bigint(20) unsigned NOT NULL DEFAULT 0,
            batch_no bigint(20) unsigned NOT NULL DEFAULT 0,
            last_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            lock_token varchar(64) NOT NULL,
            lock_expires datetime NULL,
            heartbeat_at datetime NULL,
            started_at datetime NOT NULL,
            completed_at datetime NULL,
            error_message varchar(500) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY status (status),
            KEY user_id (user_id),
            KEY last_post_id (last_post_id),
            KEY heartbeat_at (heartbeat_at)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$pages} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            title varchar(255) NOT NULL,
            url text NOT NULL,
            url_hash char(64) NOT NULL,
            post_type varchar(40) NOT NULL,
            incoming_count int unsigned NOT NULL DEFAULT 0,
            outgoing_count int unsigned NOT NULL DEFAULT 0,
            weak_anchor_count int unsigned NOT NULL DEFAULT 0,
            broken_count int unsigned NOT NULL DEFAULT 0,
            is_orphan tinyint(1) NOT NULL DEFAULT 0,
            seo_score tinyint unsigned NOT NULL DEFAULT 0,
            seo_verified tinyint(1) unsigned NOT NULL DEFAULT 0,
            render_verified tinyint(1) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_post (scan_id,post_id),
            KEY scan_id (scan_id),
            KEY url_hash (url_hash),
            KEY is_orphan (is_orphan)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            occurrence_key char(64) NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            destination_type varchar(20) NOT NULL DEFAULT 'unresolved',
            destination_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            destination_taxonomy varchar(64) NOT NULL DEFAULT '',
            destination_post_type varchar(64) NOT NULL DEFAULT '',
            source_title varchar(255) NOT NULL,
            target_title varchar(255) NOT NULL DEFAULT '',
            source_url text NOT NULL,
            target_url text NOT NULL,
            source_url_hash char(64) NOT NULL,
            target_url_hash char(64) NOT NULL,
            anchor_text varchar(500) NOT NULL DEFAULT '',
            context_excerpt varchar(1000) NOT NULL DEFAULT '',
            link_location varchar(40) NOT NULL DEFAULT 'content',
            link_type varchar(20) NOT NULL DEFAULT 'text',
            follow_status varchar(20) NOT NULL DEFAULT 'follow',
            http_status smallint unsigned NOT NULL DEFAULT 0,
            redirect_url text NULL,
            issue_type varchar(60) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY occurrence (scan_id,occurrence_key),
            KEY scan_source (scan_id,source_post_id),
            KEY scan_target (scan_id,target_post_id),
            KEY scan_destination_object (scan_id,destination_type,destination_object_id),
            KEY destination_taxonomy (destination_taxonomy),
            KEY destination_post_type (destination_post_type),
            KEY target_hash (target_url_hash),
            KEY issue_type (issue_type),
            KEY link_location (link_location)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$issues} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            link_id bigint(20) unsigned NOT NULL DEFAULT 0,
            issue_type varchar(60) NOT NULL,
            severity varchar(20) NOT NULL DEFAULT 'medium',
            message varchar(500) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY scan_id (scan_id),
            KEY issue_type (issue_type),
            KEY post_id (post_id)
        ) ENGINE=InnoDB {$charset};" );

        dbDelta( "CREATE TABLE {$keywords} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            term varchar(190) NOT NULL,
            weight decimal(10,3) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_post_term (scan_id,post_id,term),
            KEY scan_term (scan_id,term),
            KEY scan_post (scan_id,post_id)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$phrases} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            phrase varchar(190) NOT NULL,
            normalized varchar(190) NOT NULL,
            source varchar(24) NOT NULL DEFAULT 'body',
            priority smallint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_post_phrase (scan_id,post_id,normalized),
            KEY scan_phrase (scan_id,normalized),
            KEY scan_post (scan_id,post_id),
            KEY source (source)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$feedback} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL,
            decision varchar(20) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_source_target (user_id,source_post_id,target_post_id),
            KEY source_post (source_post_id),
            KEY target_post (target_post_id)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$opportunities} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            opportunity_key char(64) NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL,
            anchor_text varchar(190) NOT NULL,
            context_excerpt varchar(700) NOT NULL DEFAULT '',
            score tinyint unsigned NOT NULL DEFAULT 0,
            reason varchar(500) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'new',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_opportunity (scan_id,opportunity_key),
            KEY scan_status_score (scan_id,status,score),
            KEY source_post (source_post_id),
            KEY target_post (target_post_id)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$insertions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            opportunity_id bigint(20) unsigned NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            anchor_text varchar(190) NOT NULL,
            destination_url text NOT NULL,
            editor_type varchar(30) NOT NULL DEFAULT '',
            content_location varchar(190) NOT NULL DEFAULT '',
            location_hash char(64) NOT NULL DEFAULT '',
            before_hash char(64) NOT NULL DEFAULT '',
            after_hash char(64) NOT NULL DEFAULT '',
            revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
            insertion_status varchar(20) NOT NULL DEFAULT 'inserted',
            error_code varchar(80) NOT NULL DEFAULT '',
            error_message varchar(500) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            undone_at datetime NULL,
            undone_by bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY opportunity_id (opportunity_id),
            KEY source_post_id (source_post_id),
            KEY target_post_id (target_post_id),
            KEY insertion_status (insertion_status),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset};" );

        dbDelta( "CREATE TABLE {$external_actions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            source_type varchar(20) NOT NULL,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action_type varchar(20) NOT NULL,
            target_url text NOT NULL,
            target_url_hash char(64) NOT NULL,
            replacement_text varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY source (source_type,source_id),
            KEY target_hash (target_url_hash),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$search_console_urls} (
            url_hash char(64) NOT NULL,
            url text NOT NULL,
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            impressions bigint(20) unsigned NOT NULL DEFAULT 0,
            position decimal(10,2) NOT NULL DEFAULT 0,
            http_status smallint unsigned NOT NULL DEFAULT 0,
            checked_at datetime NULL,
            imported_at datetime NOT NULL,
            PRIMARY KEY  (url_hash),
            KEY impressions (impressions),
            KEY http_status (http_status),
            KEY imported_at (imported_at)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$redirects} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_path varchar(700) NOT NULL,
            source_hash char(64) NOT NULL,
            source_url_hash char(64) NOT NULL DEFAULT '',
            destination_url text NOT NULL,
            status_code smallint unsigned NOT NULL DEFAULT 301,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_hash (source_hash),
            KEY source_url_hash (source_url_hash),
            KEY status_code (status_code)
        ) ENGINE=InnoDB {$charset};" );
        dbDelta( "CREATE TABLE {$locks} (
            lock_name varchar(100) NOT NULL,
            lock_token char(64) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (lock_name),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB {$charset};" );
        $role = get_role( 'administrator' );
        if ( $role ) {
            foreach ( array( 'ilsm_run_scans','ilsm_view_reports','ilsm_export_reports','ilsm_manage_settings','ilsm_insert_links','ilsm_delete_scan_data' ) as $cap ) {
                $role->add_cap( $cap );
            }
        }
        add_option( 'ilsm_settings', array(
            'batch_size'=>15,'batch_delay'=>350,'max_pages'=>5000,'post_types'=>self::default_post_types(),
            'check_http'=>0,'check_external_http'=>0,'broken_monitor_enabled'=>0,'broken_monitor_external'=>0,'broken_monitor_batch_size'=>5,'incoming_color'=>'#2563EB','outgoing_color'=>'#F97316','broken_color'=>'#EF4444',
            'redirect_color'=>'#8B5CF6','external_allowlist'=>'','external_removed_text'=>'[Removed Link]','admin_theme'=>'dark','delete_on_uninstall'=>0,'remove_inserted_links_on_uninstall'=>0,'local_assistant'=>1,'suggestion_limit'=>12,'exclude_media_links'=>1,'report_per_page'=>50,'insert_min_confidence'=>70,'insert_max_per_source'=>2,'insert_max_per_run'=>20,'insert_min_word_distance'=>120,'insert_min_source_words'=>300,'insert_density_per_1000'=>6,'insert_create_revision'=>1,'insert_audit_log'=>1,'insert_dry_run'=>1,'insert_auto_enabled'=>0,'insert_batch_size'=>5,'opportunity_exclude_noindex'=>1,'opportunity_exclude_privacy'=>1,'opportunity_exclude_legal'=>1,'opportunity_exclude_cookies'=>1
        ) );

        // Version 1.0.0 no longer owns provider credentials or remote reporting.
        // Remove legacy secrets/reports during the authenticated schema upgrade.
        wp_clear_scheduled_hook( 'ilsm_search_data_sync' );
        delete_option( 'ilsm_search_integrations' );
        delete_option( 'ilsm_search_data' );
        delete_option( 'ilsm_search_sync_lock' );

        // Migrate the legacy post/page-only default once. This makes public custom
        // post types such as tours, trips and products scannable without silently
        // overriding a later explicit administrator choice.
        $settings = get_option( 'ilsm_settings', array() );
        $stored_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['post_types'] ?? array() ) ) ) );
        sort( $stored_types );
        $safe_stored_types = array_values( array_filter( $stored_types, array( 'ILSM_SEO_Inspector', 'is_supported_post_type' ) ) );
        if ( $safe_stored_types !== $stored_types ) {
            $settings['post_types'] = $safe_stored_types ?: self::default_post_types();
            $stored_types = $settings['post_types'];
            sort( $stored_types );
            update_option( 'ilsm_settings', $settings, false );
        }
        if ( ! get_option( 'ilsm_post_types_migrated_142', false ) && array( 'page', 'post' ) === $stored_types ) {
            $settings['post_types'] = self::default_post_types();
            update_option( 'ilsm_settings', $settings, false );
            update_option( 'ilsm_post_types_migrated_142', 1, false );
        }
        if ( ! array_key_exists( 'insert_min_confidence', $settings ) || 90 === absint( $settings['insert_min_confidence'] ) ) {
            $settings['insert_min_confidence'] = 70;
            update_option( 'ilsm_settings', $settings, false );
            update_option( 'ilsm_opportunity_engine_version', '', false );
        }
        update_option( 'ilsm_db_version', defined( 'ILSM_DB_VERSION' ) ? ILSM_DB_VERSION : '1.5.0', false );
    }

    /**
     * Return public, front-end searchable post types suitable for scanning.
     * Attachments and internal-only objects are deliberately excluded.
     *
     * @return string[]
     */
    public static function default_post_types() {
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        $types   = array();
        foreach ( $objects as $name => $object ) {
            if ( ! ILSM_SEO_Inspector::is_default_post_type( $name ) ) {
                continue;
            }
            $types[] = sanitize_key( $name );
        }
        if ( ! in_array( 'post', $types, true ) && post_type_exists( 'post' ) ) {
            $types[] = 'post';
        }
        if ( ! in_array( 'page', $types, true ) && post_type_exists( 'page' ) ) {
            $types[] = 'page';
        }
        return array_values( array_unique( $types ) );
    }
    public static function deactivate() {
        wp_clear_scheduled_hook( 'ilsm_search_data_sync' );
        wp_clear_scheduled_hook( 'ilsm_broken_link_monitor' );
        global $wpdb;
        $table = $wpdb->prefix . 'ilsm_locks';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $wpdb->query( "DELETE FROM {$table} WHERE lock_name LIKE 'scan_%' OR lock_name LIKE 'insert_%' OR lock_name LIKE 'broken_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist and mutable operation state must not be cached.
    }
}
