<?php
/**
 * External link and URL integrity reporting/remediation.
 *
 * @package Internal_Link_SEO_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_External_Link_Health {
    /** Register secure write actions. */
    public static function register() {
        add_action( 'wp_ajax_ilsm_external_link_action', array( __CLASS__, 'ajax_action' ) );
        add_action( 'wp_ajax_ilsm_external_approve_domains', array( __CLASS__, 'ajax_approve_domains' ) );
        add_action( 'wp_ajax_ilsm_external_ignore_action', array( __CLASS__, 'ajax_ignore_action' ) );
        add_action( 'wp_ajax_ilsm_external_domain_action', array( __CLASS__, 'ajax_domain_action' ) );
        add_action( 'wp_ajax_ilsm_external_domain_action_cancel', array( __CLASS__, 'ajax_domain_action_cancel' ) );
    }

    /** Normalize a hostname for comparison. */
    public static function normalize_domain( $domain ) {
        $domain = strtolower( trim( (string) $domain ) );
        $domain = preg_replace( '#^https?://#i', '', $domain );
        $domain = preg_replace( '#/.*$#', '', $domain );
        $domain = preg_replace( '#:\d+$#', '', $domain );
        return sanitize_text_field( trim( $domain, ". \t\n\r\0\x0B" ) );
    }

    /** Return configured approved domains. */
    public static function approved_domains() {
        $settings = get_option( 'ilsm_settings', array() );
        $raw = isset( $settings['external_allowlist'] ) ? (string) $settings['external_allowlist'] : '';
        $domains = preg_split( '/[\r\n,]+/', $raw );
        $out = array();
        foreach ( (array) $domains as $domain ) {
            $domain = self::normalize_domain( $domain );
            if ( '' !== $domain ) { $out[] = $domain; }
        }
        return array_values( array_unique( $out ) );
    }

    /** Whether an external destination is approved. Supports *.example.com. */
    public static function is_approved( $url, $approved = null ) {
        $host = self::normalize_domain( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host ) { return false; }
        if ( null === $approved ) { $approved = self::approved_domains(); }
        foreach ( (array) $approved as $rule ) {
            $rule = self::normalize_domain( $rule );
            if ( '' === $rule ) { continue; }
            if ( 0 === strpos( $rule, '*.' ) ) {
                $base = substr( $rule, 2 );
                if ( $host === $base || ( strlen( $host ) > strlen( $base ) && substr( $host, -strlen( '.' . $base ) ) === '.' . $base ) ) { return true; }
            } elseif ( $host === $rule ) {
                return true;
            }
        }
        return false;
    }

    /** Query latest scan external links. */
    public static function external_rows( $scan_id, $limit = 200 ) {
        global $wpdb;
        $table = ILSM_Database::table( 'links' );
        $limit = max( 1, min( 1000, absint( $limit ) ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifier is allowlisted; mutable scan data must be fresh.
        return $wpdb->get_results( $wpdb->prepare( "SELECT id,source_post_id,source_title,source_url,target_url,target_title,anchor_text,context_excerpt,link_location,link_type,follow_status,http_status,redirect_url,issue_type,created_at FROM {$table} WHERE scan_id=%d AND destination_type='external' ORDER BY id DESC LIMIT %d", absint( $scan_id ), $limit ), ARRAY_A );
    }

    /** Count all external link occurrences for a completed scan. */
    public static function external_total( $scan_id, $ignored = false ) {
        global $wpdb;
        $table = ILSM_Database::table( 'links' );
        $actions = ILSM_Database::table( 'external_actions' );
        $operator = $ignored ? 'EXISTS' : 'NOT EXISTS';
        $predicate = self::ignored_predicate( $actions );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifier is allowlisted; mutable scan data must be fresh.
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} l WHERE l.scan_id=%d AND l.destination_type='external' AND {$operator} ({$predicate})", absint( $scan_id ) ) );
    }

    /** Query one bounded page of external link occurrences. */
    public static function external_rows_page( $scan_id, $page, $per_page, $ignored = false ) {
        global $wpdb;
        $table = ILSM_Database::table( 'links' );
        $actions = ILSM_Database::table( 'external_actions' );
        $page = max( 1, absint( $page ) );
        $per_page = max( 20, min( 100, absint( $per_page ) ) );
        $offset = ( $page - 1 ) * $per_page;
        $operator = $ignored ? 'EXISTS' : 'NOT EXISTS';
        $occurrence_predicate = self::occurrence_ignored_predicate( $actions );
        $domain_predicate = self::domain_ignored_predicate( $actions );
        $predicate = "{$occurrence_predicate} OR {$domain_predicate}";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifier is allowlisted; mutable scan data must be fresh.
        return $wpdb->get_results( $wpdb->prepare( "SELECT l.id,l.occurrence_key,l.source_post_id,l.source_title,l.source_url,l.target_url,l.target_title,l.anchor_text,l.context_excerpt,l.link_location,l.link_type,l.follow_status,l.http_status,l.redirect_url,l.issue_type,l.created_at,EXISTS(SELECT 1 FROM {$actions} a WHERE {$occurrence_predicate}) AS ignored_occurrence,EXISTS(SELECT 1 FROM {$actions} a WHERE {$domain_predicate}) AS ignored_domain FROM {$table} l WHERE l.scan_id=%d AND l.destination_type='external' AND {$operator} (SELECT 1 FROM {$actions} a WHERE {$predicate}) ORDER BY l.id DESC LIMIT %d OFFSET %d", absint( $scan_id ), $per_page, $offset ), ARRAY_A );
    }

    /** Match persistent report-only ignores without modifying scan rows. */
    private static function ignored_predicate( $actions ) {
        ILSM_Database::checked_table( $actions );
        return 'SELECT 1 FROM ' . $actions . ' a WHERE ' . self::occurrence_ignored_predicate( $actions ) . ' OR ' . self::domain_ignored_predicate( $actions );
    }

    /** Correlated predicate for one exact scanned occurrence. */
    private static function occurrence_ignored_predicate( $actions ) {
        ILSM_Database::checked_table( $actions );
        return "a.action_type='ignore_occurrence' AND a.source_type='post' AND a.source_id=l.source_post_id AND a.target_url_hash=l.target_url_hash AND a.replacement_text=l.occurrence_key";
    }

    /** Correlated predicate for all occurrences on one external hostname. */
    private static function domain_ignored_predicate( $actions ) {
        ILSM_Database::checked_table( $actions );
        return "a.action_type='ignore_domain' AND a.source_type='domain' AND a.replacement_text=LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(l.target_url,'/',3),'/',-1),':',1))";
    }

    /** Aggregate external destination hosts without loading every occurrence into PHP. */
    public static function external_domain_counts( $scan_id, $with_status = false ) {
        global $wpdb;
        $table = ILSM_Database::table( 'links' );
        $actions = ILSM_Database::table( 'external_actions' );
        $predicate = self::ignored_predicate( $actions );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifier is allowlisted; read-only aggregation over current scan data.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(l.target_url,'/',3),'/',-1)) AS host,COUNT(*) AS occurrences,SUM(CASE WHEN l.follow_status='nofollow' THEN 1 ELSE 0 END) AS nofollow_occurrences,SUM(CASE WHEN l.follow_status='follow' THEN 1 ELSE 0 END) AS follow_occurrences FROM {$table} l WHERE l.scan_id=%d AND l.destination_type='external' AND NOT EXISTS ({$predicate}) GROUP BY host ORDER BY occurrences DESC", absint( $scan_id ) ), ARRAY_A );
        $domains = array();
        foreach ( (array) $rows as $row ) {
            $host = self::normalize_domain( $row['host'] ?? '' );
            if ( '' !== $host ) {
                $domains[ $host ] = $with_status
                    ? array(
                        'total'    => absint( $row['occurrences'] ?? 0 ),
                        'nofollow' => absint( $row['nofollow_occurrences'] ?? 0 ),
                        'follow'   => absint( $row['follow_occurrences'] ?? 0 ),
                    )
                    : absint( $row['occurrences'] ?? 0 );
            }
        }
        return $domains;
    }

    /** Exact scan-wide broken and redirect occurrence counts. */
    public static function external_issue_counts( $scan_id ) {
        global $wpdb;
        $table = ILSM_Database::table( 'links' );
        $actions = ILSM_Database::table( 'external_actions' );
        $predicate = self::ignored_predicate( $actions );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifier is allowlisted; read-only aggregation over current scan data.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CASE WHEN l.http_status>=400 OR l.issue_type='broken' THEN 1 ELSE 0 END) AS broken_count, SUM(CASE WHEN l.redirect_url IS NOT NULL AND l.redirect_url<>'' THEN 1 ELSE 0 END) AS redirect_count FROM {$table} l WHERE l.scan_id=%d AND l.destination_type='external' AND NOT EXISTS ({$predicate})", absint( $scan_id ) ), ARRAY_A );
        return array( 'broken' => absint( $row['broken_count'] ?? 0 ), 'redirects' => absint( $row['redirect_count'] ?? 0 ) );
    }

    /** Query internal URLs which could not be mapped back to a WordPress object. */
    public static function unexpected_rows( $scan_id, $limit = 100 ) {
        global $wpdb;
        $table = ILSM_Database::table( 'links' );
        $limit = max( 1, min( 500, absint( $limit ) ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifier is allowlisted; mutable scan data must be fresh.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_post_id,source_title,source_url,target_url,anchor_text,link_location,http_status,redirect_url,issue_type,created_at FROM {$table} WHERE scan_id=%d AND destination_type='unresolved' AND target_post_id=0 ORDER BY id DESC LIMIT %d", absint( $scan_id ), $limit ), ARRAY_A );
        $out = array();
        foreach ( (array) $rows as $row ) {
            if ( ILSM_Link_Normalizer::is_internal( $row['target_url'] ) ) { $out[] = $row; }
        }
        return $out;
    }

    /** Query approved comment-content and author URLs without mutating them. */
    public static function comment_rows( $limit = 100 ) {
        global $wpdb;
        $limit = max( 1, min( 500, absint( $limit ) ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only comment health report; mutable comment data must be fresh.
        $comments = $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID,comment_post_ID,comment_author,comment_author_url,comment_content,comment_date_gmt FROM {$wpdb->comments} WHERE comment_approved='1' AND (comment_author_url<>'' OR comment_content LIKE %s OR comment_content LIKE %s) ORDER BY comment_ID DESC LIMIT %d", '%http://%', '%https://%', $limit ), ARRAY_A );
        $rows = array();
        foreach ( (array) $comments as $comment ) {
            $author_url = esc_url_raw( $comment['comment_author_url'] );
            if ( $author_url && ! ILSM_Link_Normalizer::is_internal( $author_url ) ) {
                $rows[] = array(
                    'comment_id' => absint( $comment['comment_ID'] ), 'post_id' => absint( $comment['comment_post_ID'] ),
                    'author' => (string) $comment['comment_author'], 'url' => $author_url, 'location' => 'comment_author_url',
                    'context' => wp_trim_words( wp_strip_all_tags( $comment['comment_content'] ), 18, '…' ), 'created_at' => $comment['comment_date_gmt'],
                );
            }
            if ( preg_match_all( '#https?://[^\s<>"]+#i', (string) $comment['comment_content'], $matches ) ) {
                foreach ( array_unique( $matches[0] ) as $url ) {
                    $url = esc_url_raw( rtrim( $url, '.,);\']' ) );
                    if ( $url && ! ILSM_Link_Normalizer::is_internal( $url ) ) {
                        $rows[] = array(
                            'comment_id' => absint( $comment['comment_ID'] ), 'post_id' => absint( $comment['comment_post_ID'] ),
                            'author' => (string) $comment['comment_author'], 'url' => $url, 'location' => 'comment_content',
                            'context' => wp_trim_words( wp_strip_all_tags( $comment['comment_content'] ), 18, '…' ), 'created_at' => $comment['comment_date_gmt'],
                        );
                    }
                }
            }
        }
        return array_slice( $rows, 0, $limit );
    }


    /**
     * Read a bounded same-site XML sitemap inventory. Cached to avoid turning an
     * admin report into a crawler on every page load.
     *
     * @return array{urls:array,error:string,source:string}
     */
    public static function sitemap_inventory() {
        $cache_key = 'ilsm_url_integrity_sitemap_' . get_current_blog_id();
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }

        $candidates = array( home_url( '/wp-sitemap.xml' ), home_url( '/sitemap_index.xml' ) );
        $queue = array(); $source = ''; $last_error = '';
        foreach ( $candidates as $candidate ) {
            $response = wp_safe_remote_get( $candidate, array( 'timeout' => 8, 'redirection' => 2, 'reject_unsafe_urls' => true, 'sslverify' => true, 'limit_response_size' => 2097152, 'user-agent' => 'DMA-InternLink-Mapper/' . ILSM_VERSION ) );
            if ( is_wp_error( $response ) ) { $last_error = $response->get_error_message(); continue; }
            $status = absint( wp_remote_retrieve_response_code( $response ) );
            $body = (string) wp_remote_retrieve_body( $response );
            if ( $status >= 200 && $status < 300 && false !== stripos( $body, '<loc' ) ) {
                $source = $candidate;
                $queue[] = array( 'url' => $candidate, 'body' => $body );
                break;
            }
        }
        if ( empty( $queue ) ) {
            $result = array( 'urls' => array(), 'error' => sanitize_text_field( $last_error ?: __( 'No readable XML sitemap was found.', 'dma-internlink-mapper' ) ), 'source' => '' );
            set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
            return $result;
        }

        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $seen_maps = array(); $urls = array(); $processed = 0;
        while ( $queue && $processed < 20 && count( $urls ) < 1500 ) {
            $item = array_shift( $queue );
            $map_url = esc_url_raw( $item['url'] );
            if ( isset( $seen_maps[ $map_url ] ) ) { continue; }
            $seen_maps[ $map_url ] = true; $processed++;
            $body = (string) $item['body'];
            if ( ! class_exists( 'DOMDocument' ) ) { break; }
            $doc = new DOMDocument();
            $previous = libxml_use_internal_errors( true );
            $loaded = $doc->loadXML( $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
            libxml_clear_errors(); libxml_use_internal_errors( $previous );
            if ( ! $loaded ) { continue; }
            $locs = $doc->getElementsByTagName( 'loc' );
            $is_index = $doc->getElementsByTagName( 'sitemapindex' )->length > 0;
            foreach ( $locs as $loc ) {
                $loc_url = esc_url_raw( trim( (string) $loc->textContent ) );
                if ( ! $loc_url ) { continue; }
                $host = strtolower( (string) wp_parse_url( $loc_url, PHP_URL_HOST ) );
                if ( $host !== $home_host ) { continue; }
                if ( $is_index ) {
                    if ( count( $seen_maps ) + count( $queue ) >= 20 ) { continue; }
                    $child = wp_safe_remote_get( $loc_url, array( 'timeout' => 8, 'redirection' => 2, 'reject_unsafe_urls' => true, 'sslverify' => true, 'limit_response_size' => 2097152, 'user-agent' => 'DMA-InternLink-Mapper/' . ILSM_VERSION ) );
                    if ( ! is_wp_error( $child ) && absint( wp_remote_retrieve_response_code( $child ) ) < 300 ) {
                        $queue[] = array( 'url' => $loc_url, 'body' => (string) wp_remote_retrieve_body( $child ) );
                    }
                } else {
                    $urls[] = $loc_url;
                    if ( count( $urls ) >= 1500 ) { break; }
                }
            }
        }
        $result = array( 'urls' => array_values( array_unique( $urls ) ), 'error' => '', 'source' => $source );
        set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    /** Return sitemap URLs that do not resolve to a known WordPress object. */
    private static function sitemap_unexpected() {
        $inventory = self::sitemap_inventory();
        $unexpected = array();
        foreach ( $inventory['urls'] as $url ) {
            $destination = ILSM_Destination_Resolver::resolve( $url );
            $type = sanitize_key( $destination['type'] ?? 'unresolved' );
            if ( in_array( $type, array( 'post', 'term', 'home' ), true ) || absint( $destination['object_id'] ?? 0 ) > 0 || absint( $destination['post_id'] ?? 0 ) > 0 ) { continue; }
            $unexpected[] = $url;
            if ( count( $unexpected ) >= 250 ) { break; }
        }
        return array( 'items' => $unexpected, 'inventory' => $inventory );
    }

    /** Compare domains with the previous completed scan. */
    private static function previous_domains( $scan_id ) {
        global $wpdb;
        $scans = ILSM_Database::table( 'scans' );
        $links = ILSM_Database::table( 'links' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifiers are allowlisted; mutable scan data must be fresh.
        $previous = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$scans} WHERE status='completed' AND id<%d ORDER BY id DESC LIMIT 1", absint( $scan_id ) ) );
        if ( ! $previous ) { return array(); }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifiers are allowlisted; mutable scan data must be fresh.
        $urls = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT target_url FROM {$links} WHERE scan_id=%d AND destination_type='external'", $previous ) );
        $domains = array();
        foreach ( (array) $urls as $url ) {
            $host = self::normalize_domain( wp_parse_url( $url, PHP_URL_HOST ) );
            if ( $host ) { $domains[] = $host; }
        }
        return array_values( array_unique( $domains ) );
    }

    /** Render the admin report inside ILSM_Admin's normal wrapper. */
    public static function render() {
        $scan_id = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan_id ) {
            echo '<section class="ilsm-panel ilsm-first-scan-empty"><h2>' . esc_html__( 'Run a scan to build External Link Health', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'External destinations are collected by the normal local site scan. Scanning does not modify content.', 'dma-internlink-mapper' ) . '</p></section>';
            return;
        }
        $settings = get_option( 'ilsm_settings', array() );
        $per_page = max( 20, min( 100, absint( $settings['report_per_page'] ?? 50 ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination state; no data is changed.
        $current_page = max( 1, absint( wp_unslash( $_GET['ilsm_ext_page'] ?? 1 ) ) );
        $total = self::external_total( $scan_id );
        $ignored_total = self::external_total( $scan_id, true );
        $all_total = $total + $ignored_total;
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $current_page > $total_pages ) { $current_page = $total_pages; }

        $approved = self::approved_domains();
        $rows = self::external_rows_page( $scan_id, $current_page, $per_page );
        $ignored_rows = self::external_rows_page( $scan_id, 1, $per_page, true );
        $comments = self::comment_rows( 200 );
        $domain_statuses = self::external_domain_counts( $scan_id, true );
        $domains = array();
        foreach ( $domain_statuses as $domain => $status_counts ) {
            $domains[ $domain ] = absint( $status_counts['total'] ?? 0 );
        }
        $issue_counts = self::external_issue_counts( $scan_id );
        $unapproved = 0;
        foreach ( $domains as $domain => $occurrences ) {
            if ( ! self::is_approved( 'https://' . $domain . '/', $approved ) ) { $unapproved += absint( $occurrences ); }
        }
        $broken = $issue_counts['broken'];
        $redirects = $issue_counts['redirects'];
        $previous_domains = self::previous_domains( $scan_id );
        $new_domains = array_diff( array_keys( $domains ), $previous_domains );
        echo '<div class="ilsm-external-health">';
        echo '<div class="ilsm-external-summary">';
        foreach ( array(
            array( __( 'External links', 'dma-internlink-mapper' ), $all_total, 'fa-external-link' ),
            array( __( 'Domains', 'dma-internlink-mapper' ), count( $domains ), 'fa-globe' ),
            array( __( 'To review', 'dma-internlink-mapper' ), $unapproved, 'fa-eye' ),
            array( __( 'New domains', 'dma-internlink-mapper' ), count( $new_domains ), 'fa-plus-circle' ),
            array( __( 'Broken', 'dma-internlink-mapper' ), $broken, 'fa-chain-broken' ),
            array( __( 'Redirects', 'dma-internlink-mapper' ), $redirects, 'fa-random' ),
            array( __( 'Comment links', 'dma-internlink-mapper' ), count( $comments ), 'fa-comments-o' ),
        ) as $metric ) {
            echo '<div class="ilsm-external-kpi"><i class="fa ' . esc_attr( $metric[2] ) . '" aria-hidden="true"></i><div><strong>' . esc_html( number_format_i18n( $metric[1] ) ) . '</strong><span>' . esc_html( $metric[0] ) . '</span></div></div>';
        }
        echo '</div>';
        echo '<div class="ilsm-external-tabs" role="tablist"><button type="button" class="is-active" data-external-tab="links"><span>' . esc_html__( 'External Links', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong></button><button type="button" data-external-tab="domains"><span>' . esc_html__( 'Domains', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( number_format_i18n( count( $domains ) ) ) . '</strong></button><button type="button" data-external-tab="comments"><span>' . esc_html__( 'Comments', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( number_format_i18n( count( $comments ) ) ) . '</strong></button><button type="button" data-external-tab="ignored"><span>' . esc_html__( 'Ignored', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( number_format_i18n( $ignored_total ) ) . '</strong></button></div>';
        self::render_links_tab( $rows, $approved, $new_domains, $current_page, $total_pages, $total );
        self::render_domains_tab( $domains, $approved, $new_domains, $domain_statuses );
        self::render_comments_tab( $comments, $approved );
        self::render_ignored_tab( $ignored_rows, $ignored_total );
        echo '</div>';
    }

    private static function render_links_tab( $rows, $approved, $new_domains, $current_page, $total_pages, $total ) {
        $can_approve = current_user_can( 'manage_options' );
        echo '<section class="ilsm-external-tabpanel is-active" data-external-panel="links"><div class="ilsm-panel ilsm-external-links-panel"><div class="ilsm-panel-head ilsm-external-panel-head"><div><h2><i class="fa fa-external-link" aria-hidden="true"></i>' . esc_html__( 'External destinations', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Review where your site links externally, manage rel attributes, and approve trusted domains without leaving this workspace.', 'dma-internlink-mapper' ) . '</p></div><label class="ilsm-external-search-wrap"><i class="fa fa-search" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( 'Search external links', 'dma-internlink-mapper' ) . '</span><input class="ilsm-external-search" type="search" placeholder="' . esc_attr__( 'Search source, domain or anchor…', 'dma-internlink-mapper' ) . '"></label></div>';

        echo '<p class="ilsm-external-ignore-note"><i class="fa fa-info-circle" aria-hidden="true"></i> ' . esc_html__( 'Ignoring changes this report only. It never edits page content or rel attributes, and every ignored item can be restored.', 'dma-internlink-mapper' ) . '</p>';

        echo '<div class="ilsm-external-toolbar"><label class="ilsm-external-select"><input type="checkbox" id="ilsm-external-select-all"> <span>' . esc_html__( 'Select current page', 'dma-internlink-mapper' ) . '</span></label><div class="ilsm-external-bulk-control"><label for="ilsm-external-bulk-action" class="screen-reader-text">' . esc_html__( 'Bulk action', 'dma-internlink-mapper' ) . '</label><select id="ilsm-external-bulk-action" disabled><option value="">' . esc_html__( 'Bulk actions', 'dma-internlink-mapper' ) . '</option><option value="follow">' . esc_html__( 'Set dofollow', 'dma-internlink-mapper' ) . '</option><option value="nofollow">' . esc_html__( 'Set nofollow', 'dma-internlink-mapper' ) . '</option>';
        if ( $can_approve ) {
            echo '<option value="approve">' . esc_html__( 'Approve domains', 'dma-internlink-mapper' ) . '</option>';
        }
        echo '<option value="ignore_occurrence">' . esc_html__( 'Ignore occurrences', 'dma-internlink-mapper' ) . '</option>';
        if ( current_user_can( 'ilsm_manage_settings' ) || current_user_can( 'manage_options' ) ) { echo '<option value="ignore_domain">' . esc_html__( 'Ignore domains', 'dma-internlink-mapper' ) . '</option>'; }
        echo '<option value="unlink">' . esc_html__( 'Unlink', 'dma-internlink-mapper' ) . '</option><option value="replace">' . esc_html__( 'Replace with [Removed Link]', 'dma-internlink-mapper' ) . '</option></select><button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-external-bulk-apply" disabled>' . esc_html__( 'Apply', 'dma-internlink-mapper' ) . '</button></div><span id="ilsm-external-bulk-status" aria-live="polite"></span></div>';

        echo '<div class="ilsm-table-scroll"><table class="widefat ilsm-external-table ilsm-external-table-premium"><thead><tr><th class="check-column"><span class="screen-reader-text">' . esc_html__( 'Select', 'dma-internlink-mapper' ) . '</span></th><th>' . esc_html__( 'Source', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Destination', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Location', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Health / Rel', 'dma-internlink-mapper' ) . '</th><th class="ilsm-actions-column">' . esc_html__( 'Actions', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="6"><div class="ilsm-external-empty"><i class="fa fa-check-circle" aria-hidden="true"></i><strong>' . esc_html__( 'No external links were found on this page of results.', 'dma-internlink-mapper' ) . '</strong></div></td></tr>';
        }
        foreach ( $rows as $row ) {
            $host = self::normalize_domain( wp_parse_url( $row['target_url'], PHP_URL_HOST ) );
            $ok = self::is_approved( $row['target_url'], $approved );
            $is_new = in_array( $host, $new_domains, true );
            $source_view_url = esc_url_raw( $row['source_url'] );
            if ( ! $source_view_url ) {
                $source_view_url = esc_url_raw( get_permalink( absint( $row['source_post_id'] ) ) );
            }
            $source_path = wp_parse_url( $source_view_url, PHP_URL_PATH );
            $source_path = $source_path ? $source_path : '/';
            $target_path = wp_parse_url( $row['target_url'], PHP_URL_PATH );
            $target_path = $target_path ? $target_path : '/';
            $follow_status = 'nofollow' === sanitize_key( $row['follow_status'] ) ? 'nofollow' : 'follow';
            $follow_label = 'nofollow' === $follow_status ? __( 'Nofollow', 'dma-internlink-mapper' ) : __( 'Dofollow', 'dma-internlink-mapper' );
            $follow_class = 'nofollow' === $follow_status ? 'is-nofollow' : 'is-follow';
            $review_label = $ok ? __( 'Approved', 'dma-internlink-mapper' ) : __( 'Needs review', 'dma-internlink-mapper' );
            $health_class = $ok ? 'is-approved' : 'is-review';
            $toggle_mode = 'nofollow' === $follow_status ? 'follow' : 'nofollow';
            $toggle_label = 'nofollow' === $follow_status ? __( 'Set dofollow', 'dma-internlink-mapper' ) : __( 'Set nofollow', 'dma-internlink-mapper' );

            echo '<tr data-search="' . esc_attr( strtolower( $row['source_title'] . ' ' . $row['source_url'] . ' ' . $row['target_url'] . ' ' . $row['anchor_text'] . ' ' . $host ) ) . '">';
            echo '<th scope="row" class="check-column"><input type="checkbox" class="ilsm-external-row-check" aria-label="' . esc_attr__( 'Select external link', 'dma-internlink-mapper' ) . '" data-source="post" data-id="' . absint( $row['source_post_id'] ) . '" data-link-id="' . absint( $row['id'] ) . '" data-url="' . esc_attr( $row['target_url'] ) . '" data-location="' . esc_attr( $row['link_location'] ) . '"></th>';
            echo '<td class="ilsm-source-cell"><a class="ilsm-source-title" href="' . esc_url( $source_view_url ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html( $row['source_title'] ) . '</strong><i class="fa fa-external-link" aria-hidden="true"></i><span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'dma-internlink-mapper' ) . '</span></a><small title="' . esc_attr( $source_view_url ) . '">' . esc_html( $source_path ) . '</small></td>';
            echo '<td class="ilsm-destination-cell"><div class="ilsm-destination-host"><span class="ilsm-domain-favicon" aria-hidden="true">' . esc_html( strtoupper( substr( $host, 0, 1 ) ) ) . '</span><div><strong>' . esc_html( $host ) . '</strong><code title="' . esc_attr( $row['target_url'] ) . '">' . esc_html( $target_path ) . '</code></div></div><div class="ilsm-anchor-preview"><span>' . esc_html__( 'Anchor', 'dma-internlink-mapper' ) . '</span><small>' . esc_html( $row['anchor_text'] ) . '</small></div></td>';
            echo '<td><span class="ilsm-location-badge"><i class="fa fa-map-marker" aria-hidden="true"></i>' . esc_html( ucfirst( $row['link_location'] ) ) . '</span></td>';
            echo '<td><div class="ilsm-health-rel"><button type="button" class="ilsm-review-domain ilsm-health-badge ' . esc_attr( $health_class ) . '" data-domain="' . esc_attr( $host ) . '" data-new="' . ( $is_new ? '1' : '0' ) . '"' . ( $ok ? ' disabled aria-disabled="true"' : '' ) . '><span class="ilsm-status-dot" aria-hidden="true"></span>' . esc_html( $review_label ) . '</button><span class="ilsm-follow-status ' . esc_attr( $follow_class ) . '" data-follow-status="' . esc_attr( $follow_status ) . '">' . esc_html( $follow_label ) . '</span>' . ( $row['http_status'] ? '<span class="ilsm-http-status">HTTP ' . esc_html( (string) absint( $row['http_status'] ) ) . '</span>' : '' ) . ( $is_new ? '<span class="ilsm-new-domain-badge">' . esc_html__( 'New domain', 'dma-internlink-mapper' ) . '</span>' : '' ) . ( $row['redirect_url'] ? '<span class="ilsm-redirect-badge">' . esc_html__( 'Redirects', 'dma-internlink-mapper' ) . '</span>' : '' ) . '</div></td>';
            echo '<td class="ilsm-actions-cell"><div class="ilsm-row-actions"><a class="ilsm-btn ilsm-row-open" href="' . esc_url( $source_view_url ) . '" target="_blank" rel="noopener noreferrer"><i class="fa fa-eye" aria-hidden="true"></i>' . esc_html__( 'Open page', 'dma-internlink-mapper' ) . '</a><details class="ilsm-row-action-menu"><summary class="ilsm-action-more" aria-label="' . esc_attr__( 'More actions', 'dma-internlink-mapper' ) . '"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></summary><div class="ilsm-action-menu-popover"><button class="ilsm-action-menu-item ilsm-follow-link" type="button" data-source="post" data-id="' . absint( $row['source_post_id'] ) . '" data-link-id="' . absint( $row['id'] ) . '" data-url="' . esc_attr( $row['target_url'] ) . '" data-mode="' . esc_attr( $toggle_mode ) . '"><i class="fa ' . ( 'follow' === $toggle_mode ? 'fa-link' : 'fa-shield' ) . '" aria-hidden="true"></i><span><strong>' . esc_html( $toggle_label ) . '</strong><small>' . ( 'follow' === $toggle_mode ? esc_html__( 'Remove only the nofollow token.', 'dma-internlink-mapper' ) : esc_html__( 'Preserve other rel values.', 'dma-internlink-mapper' ) ) . '</small></span></button><div class="ilsm-menu-separator" aria-hidden="true"></div><button class="ilsm-action-menu-item ilsm-remove-link" type="button" data-source="post" data-id="' . absint( $row['source_post_id'] ) . '" data-link-id="' . absint( $row['id'] ) . '" data-url="' . esc_attr( $row['target_url'] ) . '" data-location="' . esc_attr( $row['link_location'] ) . '" data-mode="unlink"><i class="fa fa-unlink" aria-hidden="true"></i><span><strong>' . esc_html__( 'Unlink', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Keep the anchor text as plain text.', 'dma-internlink-mapper' ) . '</small></span></button><button class="ilsm-action-menu-item is-danger ilsm-remove-link" type="button" data-source="post" data-id="' . absint( $row['source_post_id'] ) . '" data-link-id="' . absint( $row['id'] ) . '" data-url="' . esc_attr( $row['target_url'] ) . '" data-location="' . esc_attr( $row['link_location'] ) . '" data-mode="replace"><i class="fa fa-ban" aria-hidden="true"></i><span><strong>' . esc_html__( 'Replace with [Removed Link]', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Use the configured safe replacement text.', 'dma-internlink-mapper' ) . '</small></span></button></div></details></div></td></tr>';
        }
        echo '</tbody></table></div>';
        if ( $total_pages > 1 ) {
            $base = add_query_arg( array( 'page' => 'ilsm-external-links', 'ilsm_ext_page' => '%#%' ), admin_url( 'admin.php' ) );
            $pagination = paginate_links( array( 'base' => $base, 'format' => '', 'current' => $current_page, 'total' => $total_pages, 'type' => 'array', 'mid_size' => 2, 'end_size' => 1, 'prev_text' => '&lsaquo;', 'next_text' => '&rsaquo;' ) );
            if ( $pagination ) {
                $pagination_html = implode( '', array_map( static function( $link ) { return '<span class="ilsm-page-item">' . wp_kses_post( $link ) . '</span>'; }, $pagination ) );
                echo '<nav class="ilsm-pagination" aria-label="' . esc_attr__( 'External links pagination', 'dma-internlink-mapper' ) . '">' . wp_kses_post( $pagination_html ) . '</nav>';
            }
        }
        /* translators: 1: number of links shown on the current page, 2: total number of external links. */
        /* translators: 1: number of links shown on the current page, 2: total external links in the completed scan. */
        echo '<p class="description ilsm-external-result-note">' . sprintf( esc_html__( 'Showing %1$d links on this page from %2$d total. Every bulk change is validated link by link; anything that cannot be matched safely is skipped.', 'dma-internlink-mapper' ), count( $rows ), absint( $total ) ) . '</p></div></section>';
    }

    /** Render reversible report preferences separately from content mutations. */
    private static function render_ignored_tab( $rows, $total ) {
        echo '<section class="ilsm-external-tabpanel" data-external-panel="ignored" hidden><div class="ilsm-panel ilsm-external-ignored-panel"><div class="ilsm-panel-head"><div><h2><i class="fa fa-eye-slash" aria-hidden="true"></i>' . esc_html__( 'Ignored report items', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Ignored links remain on the site and remain unchanged. Restore an occurrence or its domain to return it to External Link Health.', 'dma-internlink-mapper' ) . '</p></div></div>';
        echo '<div class="ilsm-table-scroll"><table class="widefat ilsm-external-table"><thead><tr><th>' . esc_html__( 'Source', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Destination', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Location', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Restore', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        if ( empty( $rows ) ) { echo '<tr><td colspan="4">' . esc_html__( 'No external-link report items are ignored.', 'dma-internlink-mapper' ) . '</td></tr>'; }
        foreach ( (array) $rows as $row ) {
            $host = self::normalize_domain( wp_parse_url( $row['target_url'], PHP_URL_HOST ) );
            echo '<tr><td><strong>' . esc_html( $row['source_title'] ) . '</strong><small>' . esc_html( wp_parse_url( $row['source_url'], PHP_URL_PATH ) ?: '/' ) . '</small></td><td><strong>' . esc_html( $host ) . '</strong><small>' . esc_html( $row['target_url'] ) . '</small></td><td>' . esc_html( ucfirst( $row['link_location'] ) ) . '</td><td><div class="ilsm-ignored-actions">';
            if ( ! empty( $row['ignored_occurrence'] ) ) { echo '<button type="button" class="ilsm-btn ilsm-ignore-link" data-link-id="' . absint( $row['id'] ) . '" data-mode="restore_occurrence">' . esc_html__( 'Restore occurrence', 'dma-internlink-mapper' ) . '</button>'; }
            if ( ! empty( $row['ignored_domain'] ) && ( current_user_can( 'ilsm_manage_settings' ) || current_user_can( 'manage_options' ) ) ) { echo '<button type="button" class="ilsm-btn ilsm-ignore-link" data-link-id="' . absint( $row['id'] ) . '" data-mode="restore_domain">' . esc_html__( 'Restore domain', 'dma-internlink-mapper' ) . '</button>'; }
            echo '</div></td></tr>';
        }
        /* translators: %d: number of ignored external-link occurrences. */
        $summary = sprintf( __( '%d ignored occurrences in the latest completed scan.', 'dma-internlink-mapper' ), absint( $total ) );
        echo '</tbody></table></div><p class="description">' . esc_html( $summary ) . '</p></div></section>';
    }

    private static function render_domains_tab( $domains, $approved, $new_domains, $domain_statuses = array() ) {
        arsort( $domains );
        echo '<section class="ilsm-external-tabpanel" data-external-panel="domains" hidden><div class="ilsm-panel"><div class="ilsm-panel-head"><h2><i class="fa fa-globe" aria-hidden="true"></i>' . esc_html__( 'External domains', 'dma-internlink-mapper' ) . '</h2></div>';
        echo '<div id="ilsm-domain-action-feedback" class="ilsm-domain-action-feedback" role="status" aria-live="polite" tabindex="-1" hidden><i class="fa fa-info-circle" aria-hidden="true"></i><span></span></div>';
        echo '<section id="ilsm-domain-operation" class="ilsm-domain-operation" aria-live="polite" hidden>';
        echo '<header class="ilsm-domain-operation-banner"><div class="ilsm-domain-operation-icon"><i class="fa fa-shield" aria-hidden="true"></i></div><div><span class="ilsm-domain-operation-eyebrow">' . esc_html__( 'REL ATTRIBUTE AUDIT · CONTENT SAFE', 'dma-internlink-mapper' ) . '</span><h3>' . esc_html__( 'Inspect and update external links', 'dma-internlink-mapper' ) . '</h3><p>' . esc_html__( 'Checks the latest rendered scan first, then changes only links that need the selected rel value.', 'dma-internlink-mapper' ) . '</p></div></header>';
        echo '<div class="ilsm-domain-operation-workspace"><div class="ilsm-domain-operation-main"><div class="ilsm-domain-operation-overview"><div class="ilsm-domain-operation-ring" style="--ilsm-progress:0deg"><strong id="ilsm-domain-operation-percent">0%</strong></div><div class="ilsm-domain-operation-copy"><div class="ilsm-domain-operation-head"><div><h3 id="ilsm-domain-operation-title">' . esc_html__( 'Checking current rel status', 'dma-internlink-mapper' ) . '</h3><p id="ilsm-domain-operation-subtitle"></p></div><span id="ilsm-domain-operation-state" class="ilsm-domain-operation-state"></span></div><p id="ilsm-domain-operation-detail" class="ilsm-domain-operation-detail"></p></div></div>';
        echo '<div class="ilsm-domain-operation-progress"><div class="ilsm-domain-operation-progress-track" role="progressbar" aria-label="' . esc_attr__( 'Domain operation progress', 'dma-internlink-mapper' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="ilsm-domain-operation-progress-bar"></span></div></div>';
        echo '<ol class="ilsm-domain-operation-steps"><li data-ilsm-domain-step="inspect"><i class="fa fa-search" aria-hidden="true"></i><span><strong>' . esc_html__( 'Inspect rel status', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Read-only check; no content changes', 'dma-internlink-mapper' ) . '</small></span></li><li data-ilsm-domain-step="change"><i class="fa fa-pencil" aria-hidden="true"></i><span><strong>' . esc_html__( 'Change required links', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'One stored source per request', 'dma-internlink-mapper' ) . '</small></span></li><li data-ilsm-domain-step="verify"><i class="fa fa-check" aria-hidden="true"></i><span><strong>' . esc_html__( 'Verify results', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Classify changed and manual items', 'dma-internlink-mapper' ) . '</small></span></li></ol></div>';
        echo '<div id="ilsm-domain-live-activity" class="ilsm-domain-live-activity" hidden><strong><i class="fa fa-list-ul" aria-hidden="true"></i> ' . esc_html__( 'Live source activity', 'dma-internlink-mapper' ) . '</strong><ul></ul></div>';
        echo '<aside class="ilsm-domain-operation-stats"><span><strong id="ilsm-domain-operation-processed">0</strong><small>' . esc_html__( 'Checked', 'dma-internlink-mapper' ) . '</small></span><span><strong id="ilsm-domain-operation-updated">0</strong><small>' . esc_html__( 'Changed', 'dma-internlink-mapper' ) . '</small></span><span><strong id="ilsm-domain-operation-already">0</strong><small>' . esc_html__( 'Already correct', 'dma-internlink-mapper' ) . '</small></span><span><strong id="ilsm-domain-operation-skipped">0</strong><small>' . esc_html__( 'Needs manual edit', 'dma-internlink-mapper' ) . '</small></span></aside></div>';
        echo '<footer class="ilsm-domain-operation-footer"><p><i class="fa fa-lock" aria-hidden="true"></i><span><strong>' . esc_html__( 'Safe changes only', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Menus, dynamic fields, ambiguous templates, and unsupported editor storage are never modified blindly.', 'dma-internlink-mapper' ) . '</small></span></p><div class="ilsm-domain-operation-actions"><button type="button" id="ilsm-domain-operation-stop" class="ilsm-btn" hidden><i class="fa fa-stop-circle" aria-hidden="true"></i> ' . esc_html__( 'Stop safely', 'dma-internlink-mapper' ) . '</button><button type="button" id="ilsm-domain-operation-retry" class="ilsm-btn" hidden><i class="fa fa-repeat" aria-hidden="true"></i> ' . esc_html__( 'Retry', 'dma-internlink-mapper' ) . '</button><a id="ilsm-domain-operation-scan" class="ilsm-btn ilsm-btn-primary" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '" hidden><i class="fa fa-search" aria-hidden="true"></i> ' . esc_html__( 'Start a new scan', 'dma-internlink-mapper' ) . '</a><button type="button" id="ilsm-domain-operation-refresh" class="ilsm-btn" hidden><i class="fa fa-refresh" aria-hidden="true"></i> ' . esc_html__( 'Refresh report', 'dma-internlink-mapper' ) . '</button><button type="button" id="ilsm-domain-operation-dismiss" class="ilsm-btn" hidden>' . esc_html__( 'Dismiss', 'dma-internlink-mapper' ) . '</button></div></footer>';
        echo '</section><div id="ilsm-domain-operation-history" class="ilsm-domain-operation-history" hidden><header><span><i class="fa fa-history" aria-hidden="true"></i></span><div><strong>' . esc_html__( 'Recent domain operations', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Results from this browser session. Page content is changed only after safe validation.', 'dma-internlink-mapper' ) . '</small></div></header><ul></ul></div>';
        echo '<div class="ilsm-domain-grid">';
        if ( empty( $domains ) ) { echo '<p>' . esc_html__( 'No external domains found.', 'dma-internlink-mapper' ) . '</p>'; }
        foreach ( $domains as $domain => $count ) {
            $status_counts = is_array( $domain_statuses[ $domain ] ?? null ) ? $domain_statuses[ $domain ] : array();
            $nofollow_count = absint( $status_counts['nofollow'] ?? 0 );
            $follow_count = absint( $status_counts['follow'] ?? 0 );
            $ok = self::is_approved( 'https://' . $domain . '/', $approved );
            $is_new = in_array( $domain, $new_domains, true );
            /* translators: %d: number of external-link occurrences for this domain. */
            /* translators: %d: number of occurrences linking to this external domain. */
            echo '<article class="ilsm-domain-card"><div><strong>' . esc_html( $domain ) . '</strong><small>' . sprintf( esc_html__( '%d link occurrences', 'dma-internlink-mapper' ), absint( $count ) ) . '</small></div><div class="ilsm-domain-card-actions"><div class="ilsm-link-flags">' . ( $ok ? '<span class="is-approved">' . esc_html__( 'Approved', 'dma-internlink-mapper' ) . '</span>' : '<button type="button" class="ilsm-review-domain is-review" data-domain="' . esc_attr( $domain ) . '" data-new="' . ( $is_new ? '1' : '0' ) . '">' . esc_html__( 'Review', 'dma-internlink-mapper' ) . '</button>' ) . ( $is_new ? '<span class="is-new">' . esc_html__( 'New', 'dma-internlink-mapper' ) . '</span>' : '' ) . '</div>';
            if ( current_user_can( 'ilsm_insert_links' ) || current_user_can( 'ilsm_manage_settings' ) || current_user_can( 'manage_options' ) ) {
                echo '<details class="ilsm-row-action-menu ilsm-domain-action-menu"><summary class="ilsm-action-more" aria-label="' . esc_attr__( 'Domain actions', 'dma-internlink-mapper' ) . '"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></summary><div class="ilsm-action-menu-popover">';
                if ( current_user_can( 'ilsm_insert_links' ) ) {
                    echo '<button type="button" class="ilsm-action-menu-item ilsm-domain-link-action" data-domain="' . esc_attr( $domain ) . '" data-count="' . esc_attr( (string) absint( $count ) ) . '" data-follow="' . esc_attr( (string) $follow_count ) . '" data-nofollow="' . esc_attr( (string) $nofollow_count ) . '" data-mode="nofollow"><i class="fa fa-shield" aria-hidden="true"></i><span><strong>' . esc_html__( 'Set all to nofollow', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Add nofollow to every editable occurrence on this domain while preserving other rel values.', 'dma-internlink-mapper' ) . '</small></span></button>';
                    echo '<button type="button" class="ilsm-action-menu-item ilsm-domain-link-action" data-domain="' . esc_attr( $domain ) . '" data-count="' . esc_attr( (string) absint( $count ) ) . '" data-follow="' . esc_attr( (string) $follow_count ) . '" data-nofollow="' . esc_attr( (string) $nofollow_count ) . '" data-mode="follow"><i class="fa fa-link" aria-hidden="true"></i><span><strong>' . esc_html__( 'Set all to dofollow', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Remove only the nofollow token from every editable occurrence.', 'dma-internlink-mapper' ) . '</small></span></button>';
                    echo '<div class="ilsm-menu-separator" aria-hidden="true"></div>';
                    echo '<button type="button" class="ilsm-action-menu-item ilsm-domain-link-action" data-domain="' . esc_attr( $domain ) . '" data-count="' . absint( $count ) . '" data-mode="unlink"><i class="fa fa-unlink" aria-hidden="true"></i><span><strong>' . esc_html__( 'Unlink all occurrences', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Keep each anchor text as plain text where a safe stored occurrence can be verified.', 'dma-internlink-mapper' ) . '</small></span></button>';
                    echo '<button type="button" class="ilsm-action-menu-item is-danger ilsm-domain-link-action" data-domain="' . esc_attr( $domain ) . '" data-count="' . absint( $count ) . '" data-mode="replace"><i class="fa fa-ban" aria-hidden="true"></i><span><strong>' . esc_html__( 'Replace all with [Removed Link]', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Use the configured safe replacement text for each verified stored occurrence.', 'dma-internlink-mapper' ) . '</small></span></button>';
                }
                echo '<div class="ilsm-menu-separator" aria-hidden="true"></div>';
                echo '<button type="button" class="ilsm-action-menu-item ilsm-domain-link-action" data-domain="' . esc_attr( $domain ) . '" data-count="' . absint( $count ) . '" data-mode="ignore_occurrences"><i class="fa fa-eye-slash" aria-hidden="true"></i><span><strong>' . esc_html__( 'Ignore current occurrences', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Hide only the occurrences in the latest completed scan. Page content is not changed.', 'dma-internlink-mapper' ) . '</small></span></button>';
                if ( current_user_can( 'ilsm_manage_settings' ) || current_user_can( 'manage_options' ) ) {
                    echo '<button type="button" class="ilsm-action-menu-item ilsm-domain-link-action" data-domain="' . esc_attr( $domain ) . '" data-count="' . absint( $count ) . '" data-mode="ignore_domain"><i class="fa fa-globe" aria-hidden="true"></i><span><strong>' . esc_html__( 'Ignore this domain', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Hide this hostname across External Link Health reports. Page content is not changed.', 'dma-internlink-mapper' ) . '</small></span></button>';
                }
                echo '<span class="ilsm-domain-action-status" aria-live="polite"></span></div></details>';
            }
            echo '</div></article>';
        }
        $unknown_count = 0;
        foreach ( $domains as $domain => $count ) {
            if ( ! self::is_approved( 'https://' . $domain . '/', $approved ) ) { $unknown_count++; }
        }
        echo '</div><div class="ilsm-domain-bulk-actions">';
        if ( $unknown_count > 0 && current_user_can( 'manage_options' ) ) {
            /* translators: %d: number of external domains that are not yet approved. */
            /* translators: %d: number of external domains awaiting review. */
            echo '<button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-approve-all-external-domains" data-count="' . absint( $unknown_count ) . '"><i class="fa fa-check-circle" aria-hidden="true"></i> ' . sprintf( esc_html__( 'Approve all domains needing review (%d)', 'dma-internlink-mapper' ), absint( $unknown_count ) ) . '</button>';
        }
        echo '<a class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings#ilsm-external-link-health' ) ) . '"><i class="fa fa-cog" aria-hidden="true"></i> ' . esc_html__( 'Manage approved domains', 'dma-internlink-mapper' ) . '</a><span id="ilsm-domain-bulk-status" aria-live="polite"></span></div>';
        echo '<div id="ilsm-domain-action-modal" class="ilsm-domain-action-modal" hidden><div class="ilsm-domain-action-modal-backdrop" data-ilsm-domain-modal-close></div><div class="ilsm-domain-action-dialog" role="dialog" aria-modal="true" aria-labelledby="ilsm-domain-action-modal-title" aria-describedby="ilsm-domain-action-modal-description"><button type="button" class="ilsm-domain-action-modal-close" data-ilsm-domain-modal-close aria-label="' . esc_attr__( 'Close', 'dma-internlink-mapper' ) . '"><i class="fa fa-times" aria-hidden="true"></i></button><div class="ilsm-domain-action-modal-icon"><i class="fa fa-shield" aria-hidden="true"></i></div><h3 id="ilsm-domain-action-modal-title">' . esc_html__( 'Confirm domain operation', 'dma-internlink-mapper' ) . '</h3><p id="ilsm-domain-action-modal-description"></p><dl><div><dt>' . esc_html__( 'Domain', 'dma-internlink-mapper' ) . '</dt><dd id="ilsm-domain-action-modal-domain"></dd></div><div><dt>' . esc_html__( 'Current occurrences', 'dma-internlink-mapper' ) . '</dt><dd id="ilsm-domain-action-modal-count"></dd></div><div><dt>' . esc_html__( 'Action', 'dma-internlink-mapper' ) . '</dt><dd id="ilsm-domain-action-modal-action"></dd></div></dl><p class="ilsm-domain-action-modal-safety"><i class="fa fa-lock" aria-hidden="true"></i><span>' . esc_html__( 'Classic content, block HTML, and static Elementor link or HTML controls are revalidated before editing. Links generated by menus, dynamic tags, theme options, ambiguous shared templates, or unsupported editor fields are reported as unsupported and left unchanged.', 'dma-internlink-mapper' ) . '</span></p><div class="ilsm-domain-action-modal-actions"><button type="button" class="ilsm-btn" data-ilsm-domain-modal-close>' . esc_html__( 'Cancel', 'dma-internlink-mapper' ) . '</button><button type="button" id="ilsm-domain-action-modal-confirm" class="ilsm-btn ilsm-btn-primary">' . esc_html__( 'Confirm operation', 'dma-internlink-mapper' ) . '</button></div></div></div>';
        echo '</div></section>';
        self::render_domain_review_modal();
    }

    /** Explain domain review status without implying that an unknown domain is unsafe. */
    private static function render_domain_review_modal() {
        $settings_url = admin_url( 'admin.php?page=ilsm-settings#ilsm-external-link-health' );
        $can_approve = current_user_can( 'manage_options' );
        echo '<div id="ilsm-domain-review-modal" class="ilsm-modal ilsm-domain-review-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ilsm-domain-review-title" aria-describedby="ilsm-domain-review-description"><div class="ilsm-modal-card" tabindex="-1"><button type="button" class="ilsm-modal-close" data-ilsm-domain-review-close aria-label="' . esc_attr__( 'Close', 'dma-internlink-mapper' ) . '">&times;</button><div class="ilsm-domain-review-hero"><span class="ilsm-domain-review-icon"><i class="fa fa-shield" aria-hidden="true"></i></span><div><span class="ilsm-modal-eyebrow">' . esc_html__( 'External domain review', 'dma-internlink-mapper' ) . '</span><h2 id="ilsm-domain-review-title">' . esc_html__( 'Decide whether you trust this domain', 'dma-internlink-mapper' ) . '</h2><p id="ilsm-domain-review-description">' . esc_html__( 'A review flag means the hostname is not yet on your Approved domains list. It is a trust workflow, not a malware or safety diagnosis.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-domain-review-body"><div class="ilsm-domain-review-domain"><span>' . esc_html__( 'Domain under review', 'dma-internlink-mapper' ) . '</span><strong id="ilsm-domain-review-name"></strong><span id="ilsm-domain-review-new-note" class="ilsm-new-domain-callout" hidden><i class="fa fa-star" aria-hidden="true"></i>' . esc_html__( 'New since the previous completed scan', 'dma-internlink-mapper' ) . '</span></div><div class="ilsm-domain-review-grid"><section><span class="ilsm-review-step">1</span><div><h3>' . esc_html__( 'Why is it here?', 'dma-internlink-mapper' ) . '</h3><p>' . esc_html__( 'The scanner found one or more links to this hostname, but the hostname is not yet approved by an administrator.', 'dma-internlink-mapper' ) . '</p></div></section><section><span class="ilsm-review-step">2</span><div><h3>' . esc_html__( 'What does approval do?', 'dma-internlink-mapper' ) . '</h3><p>' . esc_html__( 'Approval adds only the hostname to your Approved domains list so future scans treat it as trusted.', 'dma-internlink-mapper' ) . '</p></div></section><section><span class="ilsm-review-step">3</span><div><h3>' . esc_html__( 'What does it not do?', 'dma-internlink-mapper' ) . '</h3><p>' . esc_html__( 'It does not edit, remove, redirect, follow, or nofollow any link. Link changes remain separate reviewed actions.', 'dma-internlink-mapper' ) . '</p></div></section></div><div class="ilsm-domain-review-note"><i class="fa fa-info-circle" aria-hidden="true"></i><p><strong>' . esc_html__( 'Before approving:', 'dma-internlink-mapper' ) . '</strong> ' . esc_html__( 'Open the destination or verify the organization if you do not recognize the hostname.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-modal-footer ilsm-domain-review-footer"><div class="ilsm-domain-review-footer-left"><a class="ilsm-text-link" href="' . esc_url( $settings_url ) . '"><i class="fa fa-cog" aria-hidden="true"></i>' . esc_html__( 'Manage Approved domains', 'dma-internlink-mapper' ) . '</a><span id="ilsm-domain-review-status" aria-live="polite"></span></div><div class="ilsm-modal-actions"><button type="button" class="ilsm-btn" data-ilsm-domain-review-close>' . esc_html__( 'Cancel', 'dma-internlink-mapper' ) . '</button>';
        if ( $can_approve ) {
            echo '<button type="button" class="ilsm-btn ilsm-btn-primary" id="ilsm-domain-review-approve"><i class="fa fa-check-circle" aria-hidden="true"></i>' . esc_html__( 'Approve this domain', 'dma-internlink-mapper' ) . '</button>';
        }
        echo '</div></div></div></div>';
    }

    private static function render_comments_tab( $comments, $approved ) {
        echo '<section class="ilsm-external-tabpanel" data-external-panel="comments" hidden><div class="ilsm-panel"><div class="ilsm-panel-head"><h2><i class="fa fa-comments-o" aria-hidden="true"></i>' . esc_html__( 'Comment external links', 'dma-internlink-mapper' ) . '</h2></div><div class="ilsm-table-scroll"><table class="widefat striped ilsm-external-table"><thead><tr><th>' . esc_html__( 'Comment', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'External URL', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Location', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Actions', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        if ( empty( $comments ) ) { echo '<tr><td colspan="4">' . esc_html__( 'No approved comments with external URLs were found.', 'dma-internlink-mapper' ) . '</td></tr>'; }
        foreach ( $comments as $row ) {
            echo '<tr><td><strong>#' . absint( $row['comment_id'] ) . ' · ' . esc_html( $row['author'] ) . '</strong><small>' . esc_html( $row['context'] ) . '</small></td><td><code>' . esc_html( $row['url'] ) . '</code>' . ( self::is_approved( $row['url'], $approved ) ? '<div class="ilsm-link-flags"><span class="is-approved">' . esc_html__( 'Approved', 'dma-internlink-mapper' ) . '</span></div>' : '<div class="ilsm-link-flags"><span class="is-review">' . esc_html__( 'Review', 'dma-internlink-mapper' ) . '</span></div>' ) . '</td><td>' . esc_html( $row['location'] ) . '</td><td><div class="ilsm-health-actions"><a class="ilsm-btn" href="' . esc_url( admin_url( 'comment.php?action=editcomment&c=' . absint( $row['comment_id'] ) ) ) . '">' . esc_html__( 'Review', 'dma-internlink-mapper' ) . '</a><button class="ilsm-btn ilsm-btn-danger-soft ilsm-remove-link" type="button" data-source="comment" data-id="' . absint( $row['comment_id'] ) . '" data-location="' . esc_attr( $row['location'] ) . '" data-url="' . esc_attr( $row['url'] ) . '" data-mode="replace">' . esc_html__( 'Replace with [Removed Link]', 'dma-internlink-mapper' ) . '</button></div></td></tr>';
        }
        echo '</tbody></table></div></div></section>';
    }

    private static function render_integrity_tab( $unexpected, $sitemap_integrity ) {
        echo '<section class="ilsm-external-tabpanel" data-external-panel="integrity" hidden><div class="ilsm-panel"><div class="ilsm-panel-head"><h2><i class="fa fa-shield" aria-hidden="true"></i>' . esc_html__( 'URL Integrity', 'dma-internlink-mapper' ) . '</h2></div><p>' . esc_html__( 'These are internal URLs discovered from scanned pages or the site XML sitemap that could not be mapped to a known WordPress content object. They are review signals, not malware diagnoses.', 'dma-internlink-mapper' ) . '</p>';
        $inventory = $sitemap_integrity['inventory'];
        /* translators: %d: number of sitemap URLs checked. */
        $checked_label = sprintf( __( '%d sitemap URLs checked', 'dma-internlink-mapper' ), count( $inventory['urls'] ) );
        /* translators: %d: number of sitemap URLs that could not be mapped to a known WordPress object. */
        $review_label = sprintf( __( '%d sitemap URLs need review because they did not map to a known WordPress object.', 'dma-internlink-mapper' ), count( $sitemap_integrity['items'] ) );
        echo '<div class="ilsm-integrity-note"><i class="fa fa-sitemap" aria-hidden="true"></i><div><strong>' . esc_html( $checked_label ) . '</strong><small>' . esc_html( $inventory['error'] ? $inventory['error'] : $review_label ) . '</small></div></div>';
        if ( ! empty( $sitemap_integrity['items'] ) ) {
            echo '<div class="ilsm-integrity-url-list"><h3>' . esc_html__( 'Sitemap URLs to review', 'dma-internlink-mapper' ) . '</h3>';
            foreach ( $sitemap_integrity['items'] as $url ) { echo '<code>' . esc_html( $url ) . '</code>'; }
            echo '</div>';
        }
        echo '<div class="ilsm-table-scroll"><table class="widefat striped ilsm-external-table"><thead><tr><th>' . esc_html__( 'Source', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Unexpected URL', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Location', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'HTTP', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        if ( empty( $unexpected ) ) { echo '<tr><td colspan="4">' . esc_html__( 'No unresolved internal URLs were found in the latest scan.', 'dma-internlink-mapper' ) . '</td></tr>'; }
        foreach ( $unexpected as $row ) {
            echo '<tr><td><strong>' . esc_html( $row['source_title'] ) . '</strong><small>' . esc_html( $row['source_url'] ) . '</small></td><td><code>' . esc_html( $row['target_url'] ) . '</code><div class="ilsm-link-flags"><span class="is-review">' . esc_html__( 'Unexpected URL', 'dma-internlink-mapper' ) . '</span></div></td><td>' . esc_html( $row['link_location'] ) . '</td><td>' . ( $row['http_status'] ? absint( $row['http_status'] ) : '—' ) . '</td></tr>';
        }
        echo '</tbody></table></div></div></section>';
    }

    /** Securely add reviewed scan domains to the Approved domains setting. */
    public static function ajax_approve_domains() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to change approved domains.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_external_links', 'nonce' );

        $scan_id = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan_id ) {
            wp_send_json_error( array( 'message' => __( 'No completed scan is available.', 'dma-internlink-mapper' ) ), 409 );
        }

        $available = self::external_domain_counts( $scan_id );
        $available = array_fill_keys( array_keys( $available ), true );
        $approve_all = ! empty( $_POST['approve_all'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified above.
        $requested = array();

        if ( $approve_all ) {
            $requested = array_keys( $available );
        } else {
            $posted = isset( $_POST['domains'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['domains'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified above.
            foreach ( array_slice( $posted, 0, 100 ) as $domain ) {
                $domain = self::normalize_domain( $domain );
                if ( '' !== $domain && isset( $available[ $domain ] ) ) { $requested[] = $domain; }
            }
        }

        $requested = array_values( array_unique( $requested ) );
        if ( empty( $requested ) ) {
            wp_send_json_error( array( 'message' => __( 'No valid scanned domains were selected.', 'dma-internlink-mapper' ) ), 400 );
        }

        $settings = get_option( 'ilsm_settings', array() );
        if ( ! is_array( $settings ) ) { $settings = array(); }
        $existing = self::approved_domains();
        $added = array();
        foreach ( $requested as $domain ) {
            if ( ! self::is_approved( 'https://' . $domain . '/', $existing ) ) {
                $existing[] = $domain;
                $added[] = $domain;
            }
        }
        $existing = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_domain' ), $existing ) ) ) );
        sort( $existing, SORT_NATURAL | SORT_FLAG_CASE );
        $settings['external_allowlist'] = implode( "\n", $existing );

        if ( ! update_option( 'ilsm_settings', $settings, false ) && ! empty( $added ) ) {
            wp_send_json_error( array( 'message' => __( 'WordPress could not save the approved domains.', 'dma-internlink-mapper' ) ), 500 );
        }

        /* translators: %d: number of newly approved external domains. */
        $message = sprintf( __( '%d external domains were approved.', 'dma-internlink-mapper' ), count( $added ) );
        if ( 1 === count( $added ) ) {
            $message = __( '1 external domain was approved.', 'dma-internlink-mapper' );
        } elseif ( 0 === count( $added ) ) {
            $message = __( 'The selected domains were already approved.', 'dma-internlink-mapper' );
        }
        wp_send_json_success( array( 'message' => $message, 'added' => count( $added ) ) );
    }

    /** Build a short, non-autoloaded lock key for one scan/domain mutation. */
    private static function domain_operation_lock_key( $scan_id, $domain ) {
        return 'ilsm_domain_op_' . substr( hash( 'sha256', absint( $scan_id ) . '|' . self::normalize_domain( $domain ) ), 0, 32 );
    }

    /** Acquire an atomic option-backed lock so two domain mutations cannot overlap. */
    private static function acquire_domain_operation_lock( $scan_id, $domain, $mode ) {
        $key      = self::domain_operation_lock_key( $scan_id, $domain );
        $existing = get_option( $key, array() );
        $now      = time();
        if ( is_array( $existing ) && ! empty( $existing['expires'] ) && absint( $existing['expires'] ) <= $now ) {
            delete_option( $key );
            $existing = array();
        }
        if ( ! empty( $existing ) ) {
            return new WP_Error( 'ilsm_domain_operation_busy', __( 'Another operation is already changing this domain. Wait for it to finish or stop it safely before starting another action.', 'dma-internlink-mapper' ) );
        }
        $token = wp_generate_password( 32, false, false );
        $lock  = array(
            'token'   => $token,
            'user_id' => get_current_user_id(),
            'mode'    => sanitize_key( $mode ),
            'expires' => $now + 300,
        );
        if ( ! add_option( $key, $lock, '', false ) ) {
            return new WP_Error( 'ilsm_domain_operation_busy', __( 'Another operation started changing this domain at the same time. Please try again after it finishes.', 'dma-internlink-mapper' ) );
        }
        return $token;
    }

    /** Validate and refresh an existing domain-operation lock. */
    private static function refresh_domain_operation_lock( $scan_id, $domain, $mode, $token ) {
        $key  = self::domain_operation_lock_key( $scan_id, $domain );
        $lock = get_option( $key, array() );
        if ( ! is_array( $lock ) || empty( $lock['token'] ) || empty( $token ) || ! hash_equals( (string) $lock['token'], (string) $token ) || absint( $lock['user_id'] ?? 0 ) !== get_current_user_id() || sanitize_key( $lock['mode'] ?? '' ) !== sanitize_key( $mode ) ) {
            return new WP_Error( 'ilsm_domain_operation_lock_invalid', __( 'This domain operation is no longer active. Start it again so the remaining links can be revalidated safely.', 'dma-internlink-mapper' ) );
        }
        $lock['expires'] = time() + 300;
        update_option( $key, $lock, false );
        return true;
    }

    /** Release a domain-operation lock owned by the current user/token. */
    private static function release_domain_operation_lock( $scan_id, $domain, $token ) {
        $key  = self::domain_operation_lock_key( $scan_id, $domain );
        $lock = get_option( $key, array() );
        if ( is_array( $lock ) && ! empty( $lock['token'] ) && ! empty( $token ) && hash_equals( (string) $lock['token'], (string) $token ) && absint( $lock['user_id'] ?? 0 ) === get_current_user_id() ) {
            delete_option( $key );
            return true;
        }
        return false;
    }

    /** Stop an in-progress client batch sequence after its current request finishes. */
    public static function ajax_domain_action_cancel() {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to manage External Link Health.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_external_links', 'nonce' );
        $domain  = self::normalize_domain( isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '' );
        $token   = isset( $_POST['operation_token'] ) ? sanitize_text_field( wp_unslash( $_POST['operation_token'] ) ) : '';
        $scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;
        if ( '' === $domain || ! $scan_id || '' === $token ) {
            wp_send_json_error( array( 'message' => __( 'The domain operation could not be stopped because its security state is incomplete.', 'dma-internlink-mapper' ) ), 400 );
        }
        if ( ! self::release_domain_operation_lock( $scan_id, $domain, $token ) ) {
            wp_send_json_error( array( 'message' => __( 'The domain operation had already finished or its lock expired.', 'dma-internlink-mapper' ) ), 409 );
        }
        wp_send_json_success( array( 'message' => __( 'The operation was stopped safely after the last completed batch.', 'dma-internlink-mapper' ) ) );
    }

    /**
     * Apply one reviewed action to all current external-link records for a domain.
     *
     * Mutating actions are processed in bounded batches so large sites do not turn
     * one click into a heroic PHP timeout. Each candidate is revalidated against
     * the latest completed scan and the current user's edit capability.
     */
    public static function ajax_domain_action() {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to manage External Link Health.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_external_links', 'nonce' );

        $domain_input    = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $mode_input      = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';
        $cursor_input    = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
        $phase_input     = isset( $_POST['phase'] ) ? sanitize_key( wp_unslash( $_POST['phase'] ) ) : 'change';
        $token_input     = isset( $_POST['operation_token'] ) ? sanitize_text_field( wp_unslash( $_POST['operation_token'] ) ) : '';
        $scan_id_input   = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;

        $domain          = self::normalize_domain( $domain_input );
        $mode            = sanitize_key( $mode_input );
        $cursor          = absint( $cursor_input );
        $operation_token = $token_input;
        $modes  = array( 'follow', 'nofollow', 'unlink', 'replace', 'ignore_occurrences', 'ignore_domain' );
        if ( '' === $domain || ! in_array( $mode, $modes, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid domain action.', 'dma-internlink-mapper' ) ), 400 );
        }
        if ( in_array( $mode, array( 'follow', 'nofollow', 'unlink', 'replace' ), true ) && ! current_user_can( 'ilsm_insert_links' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to modify links.', 'dma-internlink-mapper' ) ), 403 );
        }
        if ( 'ignore_domain' === $mode && ! current_user_can( 'ilsm_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to change domain-wide report preferences.', 'dma-internlink-mapper' ) ), 403 );
        }

        global $wpdb;
        $latest_scan_id = ILSM_Database::latest_completed_scan_id();
        if ( ! $latest_scan_id ) {
            wp_send_json_error( array( 'message' => __( 'No completed scan is available.', 'dma-internlink-mapper' ) ), 409 );
        }
        $scan_id = $scan_id_input ? absint( $scan_id_input ) : absint( $latest_scan_id );
        if ( $scan_id_input && absint( $latest_scan_id ) !== $scan_id ) {
            if ( '' !== $operation_token ) {
                self::release_domain_operation_lock( $scan_id, $domain, $operation_token );
            }
            wp_send_json_error( array( 'message' => __( 'A newer completed scan became available while this operation was running. Start the action again from the current report so no stale scan data is used.', 'dma-internlink-mapper' ) ), 409 );
        }
        $links   = ILSM_Database::table( 'links' );
        $actions = ILSM_Database::table( 'external_actions' );

        // Inspect the latest report first. This phase is read-only and lets the
        // UI exclude occurrences that already have the requested rel state
        // before any post, Elementor document, or template is opened.
        if ( 'inspect' === $phase_input && in_array( $mode, array( 'follow', 'nofollow' ), true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh aggregate from the latest completed plugin scan is required before a reviewed mutation.
            $states = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT follow_status,COUNT(*) occurrences FROM %i WHERE scan_id=%d AND destination_type='external' AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(target_url,'/',3),'/',-1),':',1))=%s GROUP BY follow_status",
                    $links,
                    absint( $scan_id ),
                    $domain
                ),
                ARRAY_A
            );
            $total = 0;
            $already = 0;
            foreach ( (array) $states as $state ) {
                $occurrences = absint( $state['occurrences'] ?? 0 );
                $total += $occurrences;
                if ( sanitize_key( $state['follow_status'] ?? '' ) === $mode ) { $already += $occurrences; }
            }
            if ( ! $total ) {
                wp_send_json_error( array( 'message' => __( 'This domain is no longer present in the latest completed scan.', 'dma-internlink-mapper' ) ), 409 );
            }
            wp_send_json_success(
                array(
                    'scan_id'      => absint( $scan_id ),
                    'total'        => $total,
                    'already'      => $already,
                    'needs_change' => max( 0, $total - $already ),
                )
            );
        }
        $exists = 1;
        if ( 0 === $cursor ) {
            // The hostname expression cannot use a normal URL index. Run it only
            // when the operation starts; later batches advance by primary key.
            if ( 'ignore_domain' === $mode ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current-scan count for the confirmation result.
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM %i WHERE scan_id=%d AND destination_type='external' AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(target_url,'/',3),'/',-1),':',1))=%s",
                        $links,
                        absint( $scan_id ),
                        $domain
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast current-scan presence check before acquiring the mutation lock.
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM %i WHERE scan_id=%d AND destination_type='external' AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(target_url,'/',3),'/',-1),':',1))=%s LIMIT 1",
                        $links,
                        absint( $scan_id ),
                        $domain
                    )
                );
            }
            if ( ! $exists ) {
                wp_send_json_error( array( 'message' => __( 'This domain is no longer present in the latest completed scan.', 'dma-internlink-mapper' ) ), 409 );
            }
        }

        if ( 0 === $cursor && '' === $operation_token ) {
            $operation_token = self::acquire_domain_operation_lock( $scan_id, $domain, $mode );
            if ( is_wp_error( $operation_token ) ) {
                wp_send_json_error( array( 'message' => $operation_token->get_error_message() ), 409 );
            }
        } else {
            $lock_ok = self::refresh_domain_operation_lock( $scan_id, $domain, $mode, $operation_token );
            if ( is_wp_error( $lock_ok ) ) {
                wp_send_json_error( array( 'message' => $lock_ok->get_error_message() ), 409 );
            }
        }

        if ( 'ignore_domain' === $mode ) {
            $where = array( 'source_type' => 'domain', 'source_id' => 0, 'action_type' => 'ignore_domain', 'replacement_text' => $domain );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical plugin-owned preference deduplication.
            $wpdb->delete( $actions, $where, array( '%s', '%d', '%s', '%s' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table write; no WordPress API provides this storage operation.
            $saved = $wpdb->insert(
                $actions,
                array(
                    'user_id'           => get_current_user_id(),
                    'source_type'       => 'domain',
                    'source_id'         => 0,
                    'action_type'       => 'ignore_domain',
                    'target_url'        => $domain,
                    'target_url_hash'   => hash( 'sha256', $domain ),
                    'replacement_text'  => $domain,
                    'created_at'        => current_time( 'mysql', true ),
                ),
                array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            if ( false === $saved ) {
                self::release_domain_operation_lock( $scan_id, $domain, $operation_token );
                wp_send_json_error( array( 'message' => __( 'The domain ignore preference could not be saved.', 'dma-internlink-mapper' ) ), 500 );
            }
            self::release_domain_operation_lock( $scan_id, $domain, $operation_token );
            wp_send_json_success( array( 'done' => true, 'scan_id' => $scan_id, 'operation_token' => $operation_token, 'processed' => $exists, 'updated' => $exists, 'skipped' => 0, 'message' => __( 'The domain is now hidden from External Link Health reports. Page content was not changed.', 'dma-internlink-mapper' ) ) );
        }

        // Elementor documents and Theme Builder templates can be expensive to
        // validate. Small batches keep AJAX requests within shared-host limits.
        $batch_size = 1;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded read against a plugin-owned custom table; current scan data must be fresh.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,occurrence_key,source_post_id,target_url,target_url_hash,link_location,follow_status FROM %i WHERE scan_id=%d AND destination_type='external' AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(target_url,'/',3),'/',-1),':',1))=%s AND id>%d AND (%s NOT IN ('follow','nofollow') OR follow_status<>%s) ORDER BY id ASC LIMIT %d",
                $links,
                absint( $scan_id ),
                $domain,
                $cursor,
                $mode,
                $mode,
                $batch_size
            ),
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            self::release_domain_operation_lock( $scan_id, $domain, $operation_token );
            wp_send_json_success( array( 'done' => true, 'scan_id' => $scan_id, 'operation_token' => $operation_token, 'cursor' => $cursor, 'processed' => 0, 'updated' => 0, 'already' => 0, 'skipped' => 0, 'message' => __( 'Domain action completed.', 'dma-internlink-mapper' ) ) );
        }

        $updated = 0;
        $already = 0;
        $skipped = 0;
        $processed = 0;
        $last_id = $cursor;
        $seen = array();
        $errors = array();
        $item = array();
        foreach ( $rows as $row ) {
            $last_id = max( $last_id, absint( $row['id'] ?? 0 ) );
            $processed++;
            $source_post_id = absint( $row['source_post_id'] ?? 0 );
            $item = array(
                'source_id'    => $source_post_id,
                'source_title' => sanitize_text_field( get_the_title( $source_post_id ) ?: __( 'Untitled source', 'dma-internlink-mapper' ) ),
                'source_url'   => esc_url_raw( get_permalink( $source_post_id ) ?: '' ),
                'target_url'   => esc_url_raw( $row['target_url'] ?? '' ),
                'location'     => sanitize_key( $row['link_location'] ?? '' ),
                'outcome'      => 'checked',
                'message'      => __( 'The stored source was checked.', 'dma-internlink-mapper' ),
            );

            if ( 'ignore_occurrences' === $mode ) {
                $occurrence_key = sanitize_text_field( $row['occurrence_key'] ?? '' );
                $post_id = absint( $row['source_post_id'] ?? 0 );
                if ( '' === $occurrence_key || ! $post_id || ! current_user_can( 'read_post', $post_id ) ) { $skipped++; continue; }
                $where = array( 'source_type' => 'post', 'source_id' => $post_id, 'action_type' => 'ignore_occurrence', 'target_url_hash' => (string) $row['target_url_hash'], 'replacement_text' => $occurrence_key );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reversible plugin preference deduplication.
                $wpdb->delete( $actions, $where, array( '%s', '%d', '%s', '%s', '%s' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table write; no WordPress API provides this storage operation.
                $saved = $wpdb->insert(
                    $actions,
                    array(
                        'user_id'          => get_current_user_id(),
                        'source_type'      => 'post',
                        'source_id'        => $post_id,
                        'action_type'      => 'ignore_occurrence',
                        'target_url'       => esc_url_raw( $row['target_url'] ),
                        'target_url_hash'  => (string) $row['target_url_hash'],
                        'replacement_text' => $occurrence_key,
                        'created_at'       => current_time( 'mysql', true ),
                    ),
                    array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
                );
                false === $saved ? $skipped++ : $updated++;
                continue;
            }

            // Avoid revisions, meta writes, template searches, and cache clears
            // when the latest rendered scan already has the requested rel state.
            if ( in_array( $mode, array( 'follow', 'nofollow' ), true ) && sanitize_key( $row['follow_status'] ?? '' ) === $mode ) {
                $already++;
                continue;
            }

            // One stored post/URL pair may represent several rendered occurrences.
            // Mutate it once; the HTML helpers already update all exact matches.
            $pair_key = absint( $row['source_post_id'] ) . '|' . (string) $row['target_url_hash'];
            if ( isset( $seen[ $pair_key ] ) ) { $already++; continue; }
            $seen[ $pair_key ] = true;
            $result = self::apply_domain_post_action(
                absint( $row['source_post_id'] ),
                esc_url_raw( $row['target_url'] ),
                sanitize_key( $row['link_location'] ?? '' ),
                $mode,
                absint( $scan_id )
            );
            if ( is_wp_error( $result ) ) {
                $skipped++;
                $item['outcome'] = 'manual';
                $item['message'] = sanitize_text_field( $result->get_error_message() );
                if ( count( $errors ) < 5 ) { $errors[] = $result->get_error_message(); }
            } elseif ( ! empty( $result['changed'] ) ) {
                // One safe stored-source mutation can update several rendered
                // occurrences of the same URL. Report affected occurrences,
                // rather than pretending the single write changed only one.
                $updated += max( 1, absint( $result['affected'] ?? 1 ) );
                $item['outcome'] = 'changed';
                $item['message'] = sprintf(
                    /* translators: %s: storage location changed, such as Elementor or post content. */
                    __( 'Changed successfully in %s.', 'dma-internlink-mapper' ),
                    sanitize_text_field( $result['storage'] ?? 'stored content' )
                );
            } elseif ( ! empty( $result['already'] ) ) {
                $already++;
                $item['outcome'] = 'already';
                $item['message'] = __( 'The stored source was already in the requested state.', 'dma-internlink-mapper' );
            } else {
                $skipped++;
                $item['outcome'] = 'manual';
                $item['message'] = __( 'No safe editable occurrence was found.', 'dma-internlink-mapper' );
            }
        }

        $done = count( $rows ) < $batch_size;
        if ( $done ) {
            self::release_domain_operation_lock( $scan_id, $domain, $operation_token );
        }
        wp_send_json_success(
            array(
                'done'            => $done,
                'scan_id'         => $scan_id,
                'operation_token' => $operation_token,
                'cursor'          => $last_id,
                'processed'       => $processed,
                'updated'         => $updated,
                'already'         => $already,
                'skipped'         => $skipped,
                'errors'          => $errors,
                'item'            => $item,
                'message'         => $done ? __( 'Domain action completed.', 'dma-internlink-mapper' ) : __( 'Processing the next batch…', 'dma-internlink-mapper' ),
            )
        );
    }

    /** Apply a current-scan domain action without terminating the batch request. */
    private static function apply_domain_post_action( $post_id, $url, $location, $mode, $scan_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'ilsm_domain_edit_forbidden', __( 'One source page could not be edited by the current user.', 'dma-internlink-mapper' ) );
        }
        $post = get_post( $post_id );
        if ( ! $post ) { return new WP_Error( 'ilsm_domain_post_missing', __( 'One source page no longer exists.', 'dma-internlink-mapper' ) ); }
        if ( ! $url || ILSM_Link_Normalizer::is_internal( $url ) ) {
            return new WP_Error( 'ilsm_domain_url_invalid', __( 'One scanned URL is no longer a valid external link.', 'dma-internlink-mapper' ) );
        }

        global $wpdb;
        $links_table = ILSM_Database::table( 'links' );
        $url_hash = hash( 'sha256', $url );
        $content = (string) $post->post_content;
        $changed = false;
        $storage = 'post_content';

        if ( in_array( $mode, array( 'follow', 'nofollow' ), true ) ) {
            $new = self::set_follow_status_in_html( $content, $url, $mode );
            $content_changed = $new !== $content;
            $elementor_changed = false;
            if ( $content_changed ) {
                if ( wp_revisions_enabled( $post ) ) { wp_save_post_revision( $post_id ); }
                $result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $new ) ), true );
                if ( is_wp_error( $result ) ) { return $result; }
            }
            $elementor_changed = self::modify_elementor_follow_status( $post_id, $url, $mode );
            $changed = $content_changed || $elementor_changed;
            if ( $content_changed && $elementor_changed ) { $storage = 'post_content+elementor'; }
            elseif ( $elementor_changed ) { $storage = 'elementor'; }

            if ( ! $changed && in_array( $location, array( 'header', 'footer' ), true ) ) {
                $template = self::find_elementor_template_owner( $url, $location, $mode );
                if ( is_wp_error( $template ) ) { return $template; }
                if ( $template ) {
                    $template_post = get_post( $template );
                    if ( $template_post && wp_revisions_enabled( $template_post ) ) { wp_save_post_revision( $template ); }
                    $changed = self::modify_elementor_follow_status( $template, $url, $mode );
                    if ( $changed ) { $storage = 'elementor_template'; }
                }
            }

            // Already in the requested state is a successful no-op, not a failure.
            if ( ! $changed ) {
                $reported_status = sanitize_key( (string) $wpdb->get_var( $wpdb->prepare( "SELECT follow_status FROM {$links_table} WHERE scan_id=%d AND source_post_id=%d AND target_url_hash=%s AND destination_type='external' LIMIT 1", absint( $scan_id ), $post_id, $url_hash ) ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact current-scan state check.
                if ( $reported_status === $mode ) { return array( 'changed' => false, 'already' => true ); }
                return new WP_Error( 'ilsm_domain_occurrence_uneditable', __( 'One rendered link could not be mapped to an editable stored occurrence.', 'dma-internlink-mapper' ) );
            }

            $where = array( 'scan_id' => absint( $scan_id ), 'target_url_hash' => $url_hash, 'destination_type' => 'external' );
            $where_format = array( '%d', '%s', '%s' );
            if ( 'elementor_template' === $storage ) {
                $where['link_location'] = $location;
                $where_format[] = '%s';
            } else {
                $where['source_post_id'] = $post_id;
                $where_format[] = '%d';
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synchronize plugin-owned latest-scan report after verified mutation.
            $affected = $wpdb->update( $links_table, array( 'follow_status' => $mode ), $where, array( '%s' ), $where_format );
            $affected = false === $affected ? 0 : absint( $affected );
        } else {
            if ( false === strpos( $content, $url ) ) {
                return new WP_Error( 'ilsm_domain_occurrence_uneditable', __( 'One rendered link exists only in dynamic output or an unsupported stored field, so it was skipped.', 'dma-internlink-mapper' ) );
            }
            $replacement = 'replace' === $mode ? self::replacement_text() : '';
            $new = self::replace_url_in_html( $content, $url, $replacement );
            if ( $new === $content ) { return new WP_Error( 'ilsm_domain_occurrence_missing', __( 'One exact stored link occurrence could not be found and was skipped.', 'dma-internlink-mapper' ) ); }
            if ( wp_revisions_enabled( $post ) ) { wp_save_post_revision( $post_id ); }
            $result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $new ) ), true );
            if ( is_wp_error( $result ) ) { return $result; }
            $changed = true;
        }

        if ( $changed ) {
            clean_post_cache( $post_id );
            self::log_action( 'post', $post_id, $mode, $url );
        }
        return array( 'changed' => $changed, 'storage' => $storage, 'affected' => isset( $affected ) ? $affected : 1 );
    }

    /** Secure explicit remediation of a single reviewed URL. */
    public static function ajax_ignore_action() {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to manage ignored report items.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_external_links', 'nonce' );
        $mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
        $link_id = absint( wp_unslash( $_POST['link_id'] ?? 0 ) );
        if ( ! $link_id || ! in_array( $mode, array( 'ignore_occurrence', 'ignore_domain', 'restore_occurrence', 'restore_domain' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ignore action.', 'dma-internlink-mapper' ) ), 400 );
        }
        if ( in_array( $mode, array( 'ignore_domain', 'restore_domain' ), true ) && ! current_user_can( 'ilsm_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to change domain-wide report preferences.', 'dma-internlink-mapper' ) ), 403 );
        }
        global $wpdb;
        $scan_id = ILSM_Database::latest_completed_scan_id();
        $links = ILSM_Database::table( 'links' );
        $actions = ILSM_Database::table( 'external_actions' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strictly allowlisted plugin table; exact current-scan row is required before changing report preferences.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id,occurrence_key,source_post_id,target_url,target_url_hash FROM {$links} WHERE id=%d AND scan_id=%d AND destination_type='external' LIMIT 1", $link_id, $scan_id ), ARRAY_A );
        if ( ! $row || ! current_user_can( 'read_post', absint( $row['source_post_id'] ) ) ) {
            wp_send_json_error( array( 'message' => __( 'The selected external-link occurrence is no longer available in the latest scan.', 'dma-internlink-mapper' ) ), 409 );
        }
        $host = self::normalize_domain( wp_parse_url( $row['target_url'], PHP_URL_HOST ) );
        if ( '' === $host ) { wp_send_json_error( array( 'message' => __( 'The destination domain could not be validated.', 'dma-internlink-mapper' ) ), 400 ); }
        $is_domain = false !== strpos( $mode, 'domain' );
        if ( ! $is_domain && empty( $row['occurrence_key'] ) ) {
            wp_send_json_error( array( 'message' => __( 'This older scan does not contain an exact occurrence identifier. Run a fresh scan before ignoring this occurrence.', 'dma-internlink-mapper' ) ), 409 );
        }
        $action_type = $is_domain ? 'ignore_domain' : 'ignore_occurrence';
        $where = $is_domain
            ? array( 'source_type' => 'domain', 'source_id' => 0, 'action_type' => $action_type, 'replacement_text' => $host )
            : array( 'source_type' => 'post', 'source_id' => absint( $row['source_post_id'] ), 'action_type' => $action_type, 'target_url_hash' => $row['target_url_hash'], 'replacement_text' => sanitize_text_field( $row['occurrence_key'] ) );
        if ( 0 === strpos( $mode, 'restore_' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit deletion of a plugin-owned reversible preference.
            $wpdb->delete( $actions, $where, $is_domain ? array( '%s', '%d', '%s', '%s' ) : array( '%s', '%d', '%s', '%s', '%s' ) );
            wp_send_json_success( array( 'message' => $is_domain ? __( 'The domain was restored to External Link Health.', 'dma-internlink-mapper' ) : __( 'The link occurrence was restored to External Link Health.', 'dma-internlink-mapper' ) ) );
        }
        // Keep one canonical preference row if the same action is repeated.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned preference deduplication before insert.
        $wpdb->delete( $actions, $where, $is_domain ? array( '%s', '%d', '%s', '%s' ) : array( '%s', '%d', '%s', '%s', '%s' ) );
        $insert = array(
            'user_id' => get_current_user_id(),
            'source_type' => $is_domain ? 'domain' : 'post',
            'source_id' => $is_domain ? 0 : absint( $row['source_post_id'] ),
            'action_type' => $action_type,
            'target_url' => $is_domain ? $host : esc_url_raw( $row['target_url'] ),
            'target_url_hash' => $is_domain ? hash( 'sha256', $host ) : $row['target_url_hash'],
            'replacement_text' => $is_domain ? $host : sanitize_text_field( $row['occurrence_key'] ),
            'created_at' => current_time( 'mysql', true ),
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert one plugin-owned report preference after nonce, capability and current-scan validation.
        $saved = $wpdb->insert( $actions, $insert, array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
        if ( false === $saved ) { wp_send_json_error( array( 'message' => __( 'The ignore preference could not be saved.', 'dma-internlink-mapper' ) ), 500 ); }
        wp_send_json_success( array( 'message' => $is_domain ? __( 'The domain is now hidden from External Link Health reports. Page content was not changed.', 'dma-internlink-mapper' ) : __( 'The link occurrence is now hidden from External Link Health reports. Page content was not changed.', 'dma-internlink-mapper' ) ) );
    }

    /** Secure explicit remediation of a single reviewed URL. */
    public static function ajax_action() {
        if ( ! is_user_logged_in() || ! current_user_can( 'ilsm_insert_links' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to modify links.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_external_links', 'nonce' );
        $source = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
        $mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
        $id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
        $location = sanitize_key( wp_unslash( $_POST['location'] ?? '' ) );
        if ( ! $id || ! $url || ! in_array( $mode, array( 'unlink', 'replace', 'follow', 'nofollow' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid link action.', 'dma-internlink-mapper' ) ), 400 );
        }
        if ( ILSM_Link_Normalizer::is_internal( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'This is a same-site internal link, including the equivalent www or non-www hostname. Run a fresh scan to move it out of External Links; no rel attribute was changed.', 'dma-internlink-mapper' ) ), 409 );
        }
        if ( 'comment' === $source ) {
            if ( in_array( $mode, array( 'follow', 'nofollow' ), true ) ) {
                wp_send_json_error( array( 'message' => __( 'Follow-status changes are available for scanned post/page links, not comment URLs.', 'dma-internlink-mapper' ) ), 400 );
            }
            self::modify_comment( $id, $url, $location, $mode );
        } elseif ( 'post' === $source ) {
            self::modify_post( $id, $url, $location, $mode );
        } else {
            wp_send_json_error( array( 'message' => __( 'Unsupported source type.', 'dma-internlink-mapper' ) ), 400 );
        }
    }

    private static function modify_comment( $comment_id, $url, $location, $mode ) {
        if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You cannot edit this comment.', 'dma-internlink-mapper' ) ), 403 );
        }
        $comment = get_comment( $comment_id );
        if ( ! $comment ) { wp_send_json_error( array( 'message' => __( 'Comment not found.', 'dma-internlink-mapper' ) ), 404 ); }
        $replacement = self::replacement_text();
        if ( 'comment_author_url' === $location ) {
            if ( ! hash_equals( esc_url_raw( $comment->comment_author_url ), $url ) ) { wp_send_json_error( array( 'message' => __( 'The comment URL changed since the report was generated.', 'dma-internlink-mapper' ) ), 409 ); }
            $result = wp_update_comment( array( 'comment_ID' => $comment_id, 'comment_author_url' => '' ), true );
        } else {
            $content = (string) $comment->comment_content;
            if ( false === strpos( $content, $url ) ) { wp_send_json_error( array( 'message' => __( 'The URL is no longer present in this comment.', 'dma-internlink-mapper' ) ), 409 ); }
            $new = self::replace_url_in_html( $content, $url, 'replace' === $mode ? $replacement : '' );
            $result = wp_update_comment( array( 'comment_ID' => $comment_id, 'comment_content' => $new ), true );
        }
        if ( is_wp_error( $result ) || false === $result ) { wp_send_json_error( array( 'message' => __( 'WordPress could not update the comment.', 'dma-internlink-mapper' ) ), 500 ); }
        self::log_action( 'comment', $comment_id, $mode, $url );
        wp_send_json_success( array( 'message' => __( 'The reviewed comment link was safely removed.', 'dma-internlink-mapper' ) ) );
    }

    private static function modify_post( $post_id, $url, $location, $mode ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) { wp_send_json_error( array( 'message' => __( 'You cannot edit this content.', 'dma-internlink-mapper' ) ), 403 ); }
        $post = get_post( $post_id );
        if ( ! $post ) { wp_send_json_error( array( 'message' => __( 'Content not found.', 'dma-internlink-mapper' ) ), 404 ); }
        global $wpdb;
        $scan_id = ILSM_Database::latest_completed_scan_id();
        $links_table = ILSM_Database::table( 'links' );
        $url_hash = hash( 'sha256', $url );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table identifier is allowlisted; this verifies current report evidence before mutation.
        $evidence = $scan_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$links_table} WHERE scan_id=%d AND source_post_id=%d AND target_url_hash=%s AND destination_type='external'", $scan_id, $post_id, $url_hash ) ) : 0;
        if ( ! $evidence ) { wp_send_json_error( array( 'message' => __( 'This URL is not present in the latest completed External Link Health scan, so it was not modified.', 'dma-internlink-mapper' ) ), 409 ); }

        $content = (string) $post->post_content;
        $changed = false;
        $storage = 'post_content';
        if ( in_array( $mode, array( 'follow', 'nofollow' ), true ) ) {
            if ( wp_revisions_enabled( $post ) ) { wp_save_post_revision( $post_id ); }
            $new = self::set_follow_status_in_html( $content, $url, $mode );
            $content_changed = $new !== $content;
            if ( $content_changed ) {
                $result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $new ) ), true );
                if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
            }
            $elementor_changed = self::modify_elementor_follow_status( $post_id, $url, $mode );
            $changed = $content_changed || $elementor_changed;
            if ( $content_changed && $elementor_changed ) { $storage = 'post_content+elementor'; }
            elseif ( $elementor_changed ) { $storage = 'elementor'; }

			// Elementor Theme Builder headers/footers are rendered on the scanned
			// page but stored in a separate elementor_library document. Resolve an
			// owner only when the exact URL has one unambiguous editable template.
			if ( ! $changed && in_array( $location, array( 'header', 'footer' ), true ) ) {
				$template = self::find_elementor_template_owner( $url, $location, $mode );
				if ( is_wp_error( $template ) ) {
					wp_send_json_error( array( 'message' => $template->get_error_message() ), 409 );
				}
				if ( $template ) {
					$template_post = get_post( $template );
					if ( $template_post && wp_revisions_enabled( $template_post ) ) { wp_save_post_revision( $template ); }
					$changed = self::modify_elementor_follow_status( $template, $url, $mode );
					if ( $changed ) { $storage = 'elementor_template'; }
				}
			}
        } else {
            if ( false === strpos( $content, $url ) ) {
                wp_send_json_error( array( 'message' => __( 'This URL was found in rendered output but not in stored post content, so it was not modified automatically.', 'dma-internlink-mapper' ) ), 409 );
            }
            if ( wp_revisions_enabled( $post ) ) { wp_save_post_revision( $post_id ); }
            $replacement = 'replace' === $mode ? self::replacement_text() : '';
            $new = self::replace_url_in_html( $content, $url, $replacement );
            if ( $new === $content ) { wp_send_json_error( array( 'message' => __( 'No exact safe occurrence was found to modify.', 'dma-internlink-mapper' ) ), 409 ); }
            $result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $new ) ), true );
            if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
            $changed = true;
        }

        if ( ! $changed ) {
            wp_send_json_error( array( 'message' => __( 'No exact stored occurrence was found that can be changed safely. The link may come from a template, dynamic field, menu, or unsupported widget.', 'dma-internlink-mapper' ) ), 409 );
        }
        clean_post_cache( $post_id );
        if ( in_array( $mode, array( 'follow', 'nofollow' ), true ) ) {
			$where = array( 'scan_id' => absint( $scan_id ), 'target_url_hash' => $url_hash, 'destination_type' => 'external' );
			$where_format = array( '%d', '%s', '%s' );
			if ( 'elementor_template' === $storage ) {
				$where['link_location'] = $location;
				$where_format[] = '%s';
			} else {
				$where['source_post_id'] = absint( $post_id );
				$where_format[] = '%d';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keep the latest completed local report consistent with the explicit verified mutation.
			$wpdb->update(
                $links_table,
                array( 'follow_status' => $mode ),
				$where,
                array( '%s' ),
				$where_format
            );
        }
        self::log_action( 'post', $post_id, $mode, $url );
        if ( 'nofollow' === $mode ) {
			$message = 'elementor_template' === $storage ? __( 'Nofollow was added to the shared Elementor template. This safely updates every page that uses that template; other rel values were preserved.', 'dma-internlink-mapper' ) : __( 'Nofollow was added safely. Other rel values were preserved.', 'dma-internlink-mapper' );
			wp_send_json_success( array( 'message' => $message, 'follow_status' => 'nofollow', 'storage' => $storage ) );
        }
        if ( 'follow' === $mode ) {
			$message = 'elementor_template' === $storage ? __( 'Nofollow was removed from the shared Elementor template. This safely updates every page that uses that template; other rel values were preserved.', 'dma-internlink-mapper' ) : __( 'Nofollow was removed safely. Other rel values were preserved.', 'dma-internlink-mapper' );
			wp_send_json_success( array( 'message' => $message, 'follow_status' => 'follow', 'storage' => $storage ) );
        }
        wp_send_json_success( array( 'message' => __( 'The reviewed external link was safely updated. A WordPress revision was created when revisions are enabled.', 'dma-internlink-mapper' ) ) );
    }

    /** Add or remove only the nofollow token on exact href matches. */
    private static function set_follow_status_in_html( $html, $url, $mode ) {
        if ( '' === (string) $html || '' === (string) $url ) { return $html; }
        $changed = preg_replace_callback(
            '#<a\b[^>]*?href=(["\'])(.*?)\1[^>]*?>#isu',
            static function( $m ) use ( $mode, $url ) {
                $tag = $m[0];
                if ( ! self::urls_match( $m[2], $url ) ) { return $tag; }
                $rel_pattern = '#\srel=(["\'])(.*?)\1#isu';
                if ( preg_match( $rel_pattern, $tag, $rel_match ) ) {
                    $tokens = preg_split( '/\s+/', strtolower( trim( html_entity_decode( $rel_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
                    $tokens = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $tokens ) ) ) );
                    $tokens = array_values( array_diff( $tokens, array( 'nofollow' ) ) );
                    if ( 'nofollow' === $mode ) { $tokens[] = 'nofollow'; }
                    $tokens = array_values( array_unique( $tokens ) );
                    $replacement = $tokens ? ' rel="' . esc_attr( implode( ' ', $tokens ) ) . '"' : '';
                    return preg_replace( $rel_pattern, $replacement, $tag, 1 );
                }
                if ( 'nofollow' !== $mode ) { return $tag; }
                return preg_replace( '#\s*/?>$#', ' rel="nofollow">', $tag, 1 );
            },
            $html
        );
        return null === $changed ? $html : $changed;
    }

    /** Safely update Elementor link controls or HTML strings when post_content does not contain the rendered link. */
    private static function modify_elementor_follow_status( $post_id, $url, $mode ) {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return false; }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) { $data = json_decode( wp_unslash( $raw ), true ); }
        if ( ! is_array( $data ) ) { return false; }
        $changed = false;
        self::walk_elementor_follow_status( $data, $url, $mode, $changed );
        if ( ! $changed ) { return false; }
        $encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $encoded ) || '' === $encoded ) { return false; }
        $result = update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
        if ( false === $result && $encoded !== (string) get_post_meta( $post_id, '_elementor_data', true ) ) { return false; }
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
            try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch ( Throwable $error ) { /* Cache clearing is best-effort; persisted data is already saved. */ }
        }
        return true;
    }

	/**
	 * Find one editable Elementor document owner for an exact rendered URL.
	 *
	 * Broad or ambiguous matches are deliberately rejected because changing a
	 * shared template affects every page on which Elementor renders it.
	 *
	 * @return int|false|WP_Error
	 */
	private static function find_elementor_template_owner( $url, $location, $mode ) {
		global $wpdb;
		$host = sanitize_text_field( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) { return false; }
		$like = '%' . $wpdb->esc_like( $host ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only bounded lookup in core postmeta is required to resolve a rendered Theme Builder owner; every candidate is capability checked and exact-decoded before mutation.
		if ( in_array( $location, array( 'header', 'footer' ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only bounded lookup in core postmeta is required to resolve an exact rendered Elementor Theme Builder owner; candidates are capability checked before mutation.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID,p.post_type,p.post_status,p.post_title FROM %i p INNER JOIN %i pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_type IN ('elementor_library','elementor-hf') AND p.post_status NOT IN ('trash','auto-draft','inherit') AND pm.meta_value LIKE %s LIMIT 50",
					$wpdb->posts,
					$wpdb->postmeta,
					'_elementor_data',
					$like
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only bounded lookup in core postmeta is required to resolve an exact rendered Elementor owner; candidates are capability checked before mutation.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID,p.post_type,p.post_status,p.post_title FROM %i p INNER JOIN %i pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_type NOT IN ('revision','attachment','nav_menu_item') AND p.post_status NOT IN ('trash','auto-draft','inherit') AND pm.meta_value LIKE %s LIMIT 50",
					$wpdb->posts,
					$wpdb->postmeta,
					'_elementor_data',
					$like
				),
				ARRAY_A
			);
		}
		$matches = array();
		$preferred_matches = array();
		$published_matches = array();
		$published_preferred_matches = array();
		foreach ( (array) $rows as $candidate ) {
			$template_id = absint( $candidate['ID'] ?? 0 );
			if ( ! $template_id || ! current_user_can( 'edit_post', $template_id ) ) { continue; }
			$owner_signals = array(
				(string) get_post_meta( $template_id, '_elementor_template_type', true ),
				(string) get_post_meta( $template_id, 'ehf_template_type', true ),
				(string) get_post_meta( $template_id, '_ehf_template_type', true ),
				(string) get_post_meta( $template_id, 'elementor_hf_display', true ),
				(string) ( $candidate['post_type'] ?? '' ),
				(string) ( $candidate['post_title'] ?? '' ),
			);
			$owner_signal = strtolower( implode( ' ', $owner_signals ) );
			$location_match = '' !== $location && false !== strpos( $owner_signal, $location );
			$raw = (string) get_post_meta( $template_id, '_elementor_data', true );
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) { $data = json_decode( wp_unslash( $raw ), true ); }
			if ( ! is_array( $data ) ) { continue; }
			$would_change = false;
			self::walk_elementor_follow_status( $data, $url, $mode, $would_change );
			if ( $would_change ) {
				$matches[] = $template_id;
				if ( $location_match ) { $preferred_matches[] = $template_id; }
				if ( 'publish' === (string) ( $candidate['post_status'] ?? '' ) ) {
					$published_matches[] = $template_id;
					if ( $location_match ) { $published_preferred_matches[] = $template_id; }
				}
			}
		}
		$matches = array_values( array_unique( $matches ) );
		$preferred_matches = array_values( array_unique( $preferred_matches ) );
		$published_matches = array_values( array_unique( $published_matches ) );
		$published_preferred_matches = array_values( array_unique( $published_preferred_matches ) );
		if ( 1 === count( $published_preferred_matches ) ) {
			return absint( $published_preferred_matches[0] );
		}
		if ( count( $published_preferred_matches ) > 1 ) {
			return new WP_Error( 'ambiguous_elementor_template', __( 'This rendered link exists in more than one published Elementor header/footer document. Their display conditions may target different pages or languages, so the plugin will not guess which site-wide document to edit.', 'dma-internlink-mapper' ) );
		}
		if ( 1 === count( $published_matches ) ) {
			return absint( $published_matches[0] );
		}
		if ( count( $published_matches ) > 1 ) {
			return new WP_Error( 'ambiguous_elementor_template', __( 'This rendered link exists in more than one published Elementor document. Their display conditions may differ, so the plugin will not guess which site-wide document to edit.', 'dma-internlink-mapper' ) );
		}
		if ( 1 === count( $preferred_matches ) ) {
			return absint( $preferred_matches[0] );
		}
		if ( count( $preferred_matches ) > 1 ) {
			return new WP_Error( 'ambiguous_elementor_template', __( 'This rendered link exists in more than one matching Elementor header/footer document. Open the intended template in Elementor and change it manually to avoid a site-wide edit to the wrong document.', 'dma-internlink-mapper' ) );
		}
		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'ambiguous_elementor_template', __( 'This rendered link exists in more than one editable Elementor document. Open the intended template or global widget in Elementor and change it manually to avoid a site-wide edit to the wrong document.', 'dma-internlink-mapper' ) );
		}
		return $matches ? absint( $matches[0] ) : false;
	}

    /** Recursive Elementor mutation restricted to exact URL link controls and HTML anchor strings. */
    private static function walk_elementor_follow_status( &$value, $url, $mode, &$changed ) {
        if ( is_array( $value ) ) {
            if ( isset( $value['url'] ) && is_string( $value['url'] ) && self::urls_match( $value['url'], $url ) ) {
                if ( 'nofollow' === $mode ) {
                    if ( empty( $value['nofollow'] ) ) { $value['nofollow'] = 'on'; $changed = true; }
                } elseif ( ! empty( $value['nofollow'] ) ) {
                    $value['nofollow'] = '';
                    $changed = true;
                }
            }
            // Some Elementor and third-party Elementor widgets use a scalar
            // `link`, `href`, or `url` control with a sibling nofollow control
            // instead of Elementor's normal link-control array.
            foreach ( array( 'link', 'href' ) as $url_key ) {
                if ( ! isset( $value[ $url_key ] ) || ! is_string( $value[ $url_key ] ) || ! self::urls_match( $value[ $url_key ], $url ) ) {
                    continue;
                }
                if ( 'nofollow' === $mode ) {
                    if ( empty( $value['nofollow'] ) ) { $value['nofollow'] = 'on'; $changed = true; }
                } elseif ( ! empty( $value['nofollow'] ) ) {
                    $value['nofollow'] = '';
                    $changed = true;
                }
            }
            foreach ( $value as &$child ) { self::walk_elementor_follow_status( $child, $url, $mode, $changed ); }
            unset( $child );
            return;
        }
        if ( is_string( $value ) && false !== stripos( $value, '<a' ) && false !== strpos( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
            $new = self::set_follow_status_in_html( $value, $url, $mode );
            if ( $new !== $value ) { $value = $new; $changed = true; }
        }
    }

    private static function urls_match( $left, $right ) {
        $left = html_entity_decode( trim( (string) $left ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $right = html_entity_decode( trim( (string) $right ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $left = self::canonical_url_for_match( $left );
        $right = self::canonical_url_for_match( $right );
        return '' !== $left && '' !== $right && hash_equals( $left, $right );
    }

    /**
     * Canonicalize harmless URL formatting differences without changing the
     * actual destination components used for the safety comparison.
     */
    private static function canonical_url_for_match( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( '' === $url ) { return ''; }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return $url; }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        $port = absint( $parts['port'] ?? 0 );
        if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) { $port = 0; }
        $path = (string) ( $parts['path'] ?? '/' );
        if ( '' === $path ) { $path = '/'; }
        if ( '/' !== $path ) { $path = untrailingslashit( $path ); }
        $encoded = wp_json_encode(
            array(
                'scheme'   => $scheme,
                'host'     => $host,
                'port'     => $port,
                'path'     => $path,
                'query'    => (string) ( $parts['query'] ?? '' ),
                'fragment' => (string) ( $parts['fragment'] ?? '' ),
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        return is_string( $encoded ) ? $encoded : '';
    }

    /** Preserve anchor text and remove only exact matching href; raw URL can become replacement text. */
    private static function replace_url_in_html( $html, $url, $raw_replacement ) {
        $quoted = preg_quote( $url, '#' );
        $changed = preg_replace_callback(
            '#<a\b([^>]*?)href=([' . "\"'" . '])' . $quoted . '\2([^>]*)>(.*?)</a>#isu',
            static function( $m ) use ( $raw_replacement ) {
                $text = $m[4];
                return '' !== $raw_replacement ? $text . ' ' . esc_html( $raw_replacement ) : $text;
            },
            $html
        );
        if ( null === $changed ) { return $html; }
        if ( $changed !== $html ) { return $changed; }
        return str_replace( $url, '' !== $raw_replacement ? esc_html( $raw_replacement ) : '', $html );
    }


    /** Record reviewed destructive actions without storing page/comment bodies. */
    private static function log_action( $source_type, $source_id, $mode, $url ) {
        global $wpdb;
        $table = ILSM_Database::table( 'external_actions' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned audit table; writes must be immediately durable and fresh.
        $wpdb->insert(
            $table,
            array(
                'user_id'          => get_current_user_id(),
                'source_type'      => sanitize_key( $source_type ),
                'source_id'        => absint( $source_id ),
                'action_type'      => sanitize_key( $mode ),
                'target_url'       => esc_url_raw( $url ),
                'target_url_hash'  => hash( 'sha256', $url ),
                'replacement_text' => 'replace' === $mode ? self::replacement_text() : '',
                'created_at'       => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    private static function replacement_text() {
        $settings = get_option( 'ilsm_settings', array() );
        $text = sanitize_text_field( $settings['external_removed_text'] ?? '[Removed Link]' );
        return '' !== $text ? $text : '[Removed Link]';
    }
}
