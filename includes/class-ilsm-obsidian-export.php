<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local-only Obsidian vault exporter.
 *
 * Exports scan data as Markdown + YAML + [[wikilinks]] without contacting
 * Obsidian or any third-party service.
 */
class ILSM_Obsidian_Export {
    const MAX_SITE_PAGES = 5000;
    const MAX_LINKS      = 50000;

    public static function register() {
        add_action( 'admin_post_ilsm_export_obsidian', array( __CLASS__, 'download' ) );
    }

    public static function export_url( $scope = 'site', $post_id = 0, $view = 'site-architecture' ) {
        $scope = in_array( $scope, array( 'site', 'page' ), true ) ? $scope : 'site';
        $view  = in_array( $view, array( 'knowledge-graph', 'page-architecture', 'site-architecture' ), true ) ? $view : 'site-architecture';
        $url   = add_query_arg(
            array(
                'action'  => 'ilsm_export_obsidian',
                'scope'   => $scope,
                'post_id' => absint( $post_id ),
                'view'    => $view,
            ),
            admin_url( 'admin-post.php' )
        );
        return wp_nonce_url( $url, 'ilsm_export_obsidian' );
    }

    public static function download() {
        if ( ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to export this report.', 'dma-internlink-mapper' ),
                '',
                array( 'response' => 403 )
            );
        }
        check_admin_referer( 'ilsm_export_obsidian' );

        $scan_id = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan_id ) {
            wp_die( esc_html__( 'Run and complete a scan before exporting to Obsidian.', 'dma-internlink-mapper' ) );
        }

        $scope   = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'site';
        $scope   = in_array( $scope, array( 'site', 'page' ), true ) ? $scope : 'site';
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        $view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'site-architecture';
        $view    = in_array( $view, array( 'knowledge-graph', 'page-architecture', 'site-architecture' ), true ) ? $view : 'site-architecture';

        if ( 'page' === $scope && ! $post_id ) {
            wp_die( esc_html__( 'Select a scanned page before exporting this Obsidian neighborhood.', 'dma-internlink-mapper' ) );
        }

        $data = self::collect( $scan_id, $scope, $post_id );
        if ( empty( $data['pages'] ) ) {
            wp_die( esc_html__( 'No scanned pages were available for this export.', 'dma-internlink-mapper' ) );
        }

        $tmp = wp_tempnam( 'dma-internlink-mapper-obsidian.zip' );
        if ( ! $tmp ) {
            wp_die( esc_html__( 'WordPress could not create a temporary export file.', 'dma-internlink-mapper' ) );
        }

        $archive_created = false;
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( true === $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
                self::build_vault(
                    static function( $path, $content ) use ( $zip ) {
                        $zip->addFromString( $path, $content );
                    },
                    $data,
                    $scan_id,
                    $scope,
                    $post_id,
                    $view
                );
                $zip->close();
                $archive_created = true;
            }
        } else {
            $archive_created = self::build_with_pclzip( $tmp, $data, $scan_id, $scope, $post_id, $view );
        }

        if ( ! $archive_created ) {
            wp_delete_file( $tmp );
            wp_die( esc_html__( 'The Obsidian export archive could not be created.', 'dma-internlink-mapper' ) );
        }

        $site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $name      = sanitize_file_name( 'dma-internlink-mapper-obsidian-' . ( $site_host ? $site_host : 'site' ) . '-scan-' . $scan_id . '.zip' );
        $size      = filesize( $tmp );

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        if ( false !== $size ) {
            header( 'Content-Length: ' . (string) $size );
        }
        header( 'X-Content-Type-Options: nosniff' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Temporary server-generated ZIP is streamed directly to the authenticated administrator.
        readfile( $tmp );
        wp_delete_file( $tmp );
        exit;
    }

    private static function collect( $scan_id, $scope, $post_id ) {
        global $wpdb;
        $pages_table = ILSM_Database::checked_table( ILSM_Database::table( 'pages' ) );
        $links_table = ILSM_Database::checked_table( ILSM_Database::table( 'links' ) );

        if ( 'site' === $scope ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables, identifiers come from strict allowlist; export requires current scan data.
            $pages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pages_table} WHERE scan_id=%d ORDER BY id ASC LIMIT %d", $scan_id, self::MAX_SITE_PAGES ), ARRAY_A );
            $ids   = array_map( 'absint', wp_list_pluck( $pages, 'post_id' ) );
        } else {
            $ids = array( absint( $post_id ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables, identifiers come from strict allowlist; export requires current scan data.
            $neighbors = $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id,target_post_id FROM {$links_table} WHERE scan_id=%d AND destination_type<>'external' AND (source_post_id=%d OR target_post_id=%d) LIMIT %d", $scan_id, $post_id, $post_id, self::MAX_LINKS ), ARRAY_A );
            foreach ( $neighbors as $neighbor ) {
                $ids[] = absint( $neighbor['source_post_id'] );
                $ids[] = absint( $neighbor['target_post_id'] );
            }
            $ids = array_values( array_filter( array_unique( $ids ) ) );
            if ( empty( $ids ) ) {
                return array( 'pages' => array(), 'links' => array() );
            }
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $args         = array_merge( array( $scan_id ), $ids );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder list is generated internally from validated integer IDs; table identifier is allowlisted.
            $pages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pages_table} WHERE scan_id=%d AND post_id IN ({$placeholders}) ORDER BY id ASC", $args ), ARRAY_A );
        }

        if ( empty( $pages ) ) {
            return array( 'pages' => array(), 'links' => array() );
        }

        $ids          = array_map( 'absint', wp_list_pluck( $pages, 'post_id' ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $args         = array_merge( array( $scan_id ), $ids, $ids, array( self::MAX_LINKS ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder lists are generated internally from validated integer IDs; arguments contain scan ID, both ID lists, and LIMIT in matching order; table identifier is allowlisted.
        $links = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$links_table} WHERE scan_id=%d AND (source_post_id IN ({$placeholders}) OR target_post_id IN ({$placeholders})) ORDER BY id ASC LIMIT %d", $args ), ARRAY_A );

        return array(
            'pages' => $pages,
            'links' => $links,
        );
    }

    private static function build_vault( $add_file, $data, $scan_id, $scope, $post_id, $view ) {
        $pages = $data['pages'];
        $links = $data['links'];
        $files = array();
        foreach ( $pages as $page ) {
            $files[ absint( $page['post_id'] ) ] = self::page_file( $page );
        }

        $incoming = array();
        $outgoing = array();
        $external = array();
        $domains  = array();
        foreach ( $links as $link ) {
            $source_id = absint( $link['source_post_id'] );
            $target_id = absint( $link['target_post_id'] );
            if ( 'external' === $link['destination_type'] ) {
                $external[ $source_id ][] = $link;
                $host = strtolower( (string) wp_parse_url( $link['target_url'], PHP_URL_HOST ) );
                if ( $host ) {
                    if ( ! isset( $domains[ $host ] ) ) { $domains[ $host ] = array(); }
                    $domains[ $host ][] = $link;
                }
                continue;
            }
            if ( $source_id && $target_id ) {
                $outgoing[ $source_id ][] = $link;
                $incoming[ $target_id ][] = $link;
            }
        }

        foreach ( $pages as $page ) {
            $id      = absint( $page['post_id'] );
            $content = self::page_note(
                $page,
                isset( $incoming[ $id ] ) ? $incoming[ $id ] : array(),
                isset( $outgoing[ $id ] ) ? $outgoing[ $id ] : array(),
                isset( $external[ $id ] ) ? $external[ $id ] : array(),
                $files
            );
            call_user_func( $add_file, $files[ $id ], $content );
        }

        call_user_func( $add_file, 'DMA InternLink Mapper.md', self::index_note( $pages, $links, $scan_id, $scope, $post_id, $view, $files, $domains ) );
        call_user_func( $add_file, 'Reports/Orphan Pages.md', self::orphan_note( $pages, $files ) );
        call_user_func( $add_file, 'Reports/Broken Links.md', self::broken_note( $links, $files ) );
        call_user_func( $add_file, 'Reports/External Domains.md', self::external_domains_note( $domains, $files ) );
        call_user_func( $add_file, 'README.md', self::readme_note( $scan_id, $scope, $view ) );
    }

    private static function build_with_pclzip( $tmp, $data, $scan_id, $scope, $post_id, $view ) {
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        if ( ! class_exists( 'PclZip' ) ) {
            return false;
        }

        $base = dirname( $tmp ) . '/ilsm-obsidian-' . wp_generate_password( 12, false, false );
        if ( ! wp_mkdir_p( $base ) ) {
            return false;
        }

        $ok = true;
        self::build_vault(
            static function( $path, $content ) use ( $base, &$ok ) {
                if ( ! $ok ) { return; }
                $path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
                if ( false !== strpos( $path, '../' ) ) { $ok = false; return; }
                $file = trailingslashit( $base ) . $path;
                $dir  = dirname( $file );
                if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { $ok = false; return; }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary export files are generated locally and removed immediately after archiving.
                if ( false === file_put_contents( $file, $content ) ) { $ok = false; }
            },
            $data,
            $scan_id,
            $scope,
            $post_id,
            $view
        );

        if ( ! $ok ) {
            self::remove_temp_tree( $base );
            return false;
        }

        $files = self::temp_files( $base );
        if ( empty( $files ) ) {
            self::remove_temp_tree( $base );
            return false;
        }
        $archive = new PclZip( $tmp );
        $result  = $archive->create( $files, PCLZIP_OPT_REMOVE_PATH, $base );
        self::remove_temp_tree( $base );
        return 0 !== $result;
    }

    private static function temp_files( $base ) {
        $files = array();
        if ( ! is_dir( $base ) ) { return $files; }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) { $files[] = $file->getPathname(); }
        }
        return $files;
    }

    private static function remove_temp_tree( $base ) {
        if ( ! is_dir( $base ) ) { return; }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup of plugin-created temporary export directory.
                rmdir( $item->getPathname() );
            } else {
                wp_delete_file( $item->getPathname() );
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup of plugin-created temporary export directory.
        rmdir( $base );
    }

    private static function page_file( $page ) {
        $slug = sanitize_file_name( sanitize_title( $page['title'] ) );
        if ( '' === $slug ) { $slug = 'page'; }
        return 'Pages/' . substr( $slug, 0, 100 ) . '-' . absint( $page['post_id'] ) . '.md';
    }

    private static function yaml_string( $value ) {
        $value = str_replace( array( "\r", "\n" ), ' ', wp_strip_all_tags( (string) $value ) );
        return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
    }

    private static function md_text( $value ) {
        $value = wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $value = preg_replace( '/[\r\n\t]+/u', ' ', $value );
        return trim( str_replace( array( '[', ']' ), array( '\\[', '\\]' ), $value ) );
    }

    private static function wikilink( $id, $files, $title = '' ) {
        $id = absint( $id );
        if ( ! $id || empty( $files[ $id ] ) ) { return ''; }
        $target = preg_replace( '/\.md$/', '', $files[ $id ] );
        $label  = self::md_text( $title ? $title : get_the_title( $id ) );
        return '[[' . $target . ( $label ? '|' . $label : '' ) . ']]';
    }

    /**
     * Group internal link occurrences by connected page while retaining counts.
     */
    private static function group_internal_links( $rows, $direction ) {
        $groups = array();
        foreach ( $rows as $row ) {
            $connected_id = 'incoming' === $direction ? absint( $row['source_post_id'] ) : absint( $row['target_post_id'] );
            if ( ! $connected_id ) { continue; }
            if ( ! isset( $groups[ $connected_id ] ) ) {
                $groups[ $connected_id ] = array(
                    'id'          => $connected_id,
                    'title'       => 'incoming' === $direction ? $row['source_title'] : $row['target_title'],
                    'occurrences' => 0,
                    'anchors'     => array(),
                );
            }
            $groups[ $connected_id ]['occurrences']++;
            $anchor = trim( wp_strip_all_tags( (string) $row['anchor_text'] ) );
            if ( '' !== $anchor ) {
                if ( ! isset( $groups[ $connected_id ]['anchors'][ $anchor ] ) ) {
                    $groups[ $connected_id ]['anchors'][ $anchor ] = 0;
                }
                $groups[ $connected_id ]['anchors'][ $anchor ]++;
            }
        }
        uasort(
            $groups,
            static function( $a, $b ) {
                return $b['occurrences'] <=> $a['occurrences'];
            }
        );
        return $groups;
    }

    /**
     * Group external link occurrences by host for safer, quieter review.
     */
    private static function group_external_links( $rows ) {
        $domains = array();
        foreach ( $rows as $row ) {
            $host = strtolower( (string) wp_parse_url( $row['target_url'], PHP_URL_HOST ) );
            if ( ! $host ) { $host = 'unknown-domain'; }
            if ( ! isset( $domains[ $host ] ) ) {
                $domains[ $host ] = array(
                    'occurrences' => 0,
                    'urls'        => array(),
                    'statuses'    => array(),
                );
            }
            $domains[ $host ]['occurrences']++;
            $url = esc_url_raw( $row['target_url'] );
            if ( $url ) { $domains[ $host ]['urls'][ $url ] = true; }
            $status = absint( $row['http_status'] );
            if ( $status ) { $domains[ $host ]['statuses'][ $status ] = true; }
        }
        uasort(
            $domains,
            static function( $a, $b ) {
                return $b['occurrences'] <=> $a['occurrences'];
            }
        );
        return $domains;
    }

    private static function yaml_tags( $page ) {
        $tags  = array( 'dma', 'wordpress/' . sanitize_key( $page['post_type'] ) );
        $score = absint( $page['seo_score'] );
        if ( $score >= 90 ) {
            $tags[] = 'seo/excellent';
        } elseif ( $score >= 75 ) {
            $tags[] = 'seo/healthy';
        } else {
            $tags[] = 'seo/review';
        }
        if ( ! empty( $page['is_orphan'] ) ) { $tags[] = 'links/orphan'; }
        if ( absint( $page['weak_anchor_count'] ) > 0 ) { $tags[] = 'links/weak-anchor'; }
        if ( absint( $page['broken_count'] ) > 0 ) { $tags[] = 'links/broken'; }
        return $tags;
    }

    private static function mermaid_label( $value ) {
        $value = wp_strip_all_tags( (string) $value );
        $value = preg_replace( '/[\\r\\n\\t]+/', ' ', $value );
        $value = str_replace( array( '"', '\\\\', '[', ']', '{', '}', '(', ')' ), '', $value );
        return trim( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 52 ) : substr( $value, 0, 52 ) );
    }

    private static function page_note( $page, $incoming, $outgoing, $external, $files ) {
        $id              = absint( $page['post_id'] );
        $title           = self::md_text( $page['title'] );
        $incoming_groups = self::group_internal_links( $incoming, 'incoming' );
        $outgoing_groups = self::group_internal_links( $outgoing, 'outgoing' );
        $external_groups = self::group_external_links( $external );
        $tags            = self::yaml_tags( $page );

        $lines = array(
            '---',
            'type: wordpress-page',
            'post_id: ' . $id,
            'post_type: ' . self::yaml_string( $page['post_type'] ),
            'url: ' . self::yaml_string( $page['url'] ),
            'seo_score: ' . absint( $page['seo_score'] ),
            'incoming_unique_pages: ' . count( $incoming_groups ),
            'incoming_occurrences: ' . count( $incoming ),
            'outgoing_unique_pages: ' . count( $outgoing_groups ),
            'outgoing_occurrences: ' . count( $outgoing ),
            'external_domains: ' . count( $external_groups ),
            'external_occurrences: ' . count( $external ),
            'weak_anchors: ' . absint( $page['weak_anchor_count'] ),
            'broken_links: ' . absint( $page['broken_count'] ),
            'orphan: ' . ( ! empty( $page['is_orphan'] ) ? 'true' : 'false' ),
            'tags:',
        );
        foreach ( $tags as $tag ) { $lines[] = '  - ' . self::yaml_string( $tag ); }

        $lines = array_merge( $lines, array(
            '---', '',
            '# ' . ( $title ? $title : 'Untitled page' ), '',
            '> [!info] Page intelligence',
            '> **SEO:** ' . absint( $page['seo_score'] ) . '/100 · **Incoming:** ' . count( $incoming_groups ) . ' unique / ' . count( $incoming ) . ' occurrences · **Outgoing:** ' . count( $outgoing_groups ) . ' unique / ' . count( $outgoing ) . ' occurrences', '',
            '| Metric | Value |', '| --- | ---: |',
            '| SEO score | **' . absint( $page['seo_score'] ) . '/100** |',
            '| Incoming pages | **' . count( $incoming_groups ) . '** |',
            '| Incoming occurrences | **' . count( $incoming ) . '** |',
            '| Outgoing pages | **' . count( $outgoing_groups ) . '** |',
            '| Outgoing occurrences | **' . count( $outgoing ) . '** |',
            '| External domains | **' . count( $external_groups ) . '** |',
            '| Weak anchors | **' . absint( $page['weak_anchor_count'] ) . '** |',
            '| Broken links | **' . absint( $page['broken_count'] ) . '** |',
            '| Orphan | **' . ( ! empty( $page['is_orphan'] ) ? 'Yes' : 'No' ) . '** |', '',
            '**WordPress URL:** `' . esc_url_raw( $page['url'] ) . '`  ',
            '**Post ID:** ' . $id . ' · **Post type:** ' . self::md_text( $page['post_type'] ), '',
            '## Relationship chart', '', '```mermaid', 'flowchart LR',
        ) );

        $center = 'P' . $id;
        $lines[] = '    ' . $center . '["' . self::mermaid_label( $title ? $title : 'Page ' . $id ) . '"]';
        $count = 0;
        foreach ( $incoming_groups as $group ) {
            if ( $count >= 8 ) { break; }
            $node = 'I' . absint( $group['id'] );
            $lines[] = '    ' . $node . '["' . self::mermaid_label( $group['title'] ) . '"] -->|' . absint( $group['occurrences'] ) . '| ' . $center;
            $count++;
        }
        $count = 0;
        foreach ( $outgoing_groups as $group ) {
            if ( $count >= 8 ) { break; }
            $node = 'O' . absint( $group['id'] );
            $lines[] = '    ' . $center . ' -->|' . absint( $group['occurrences'] ) . '| ' . $node . '["' . self::mermaid_label( $group['title'] ) . '"]';
            $count++;
        }
        $lines[] = '```'; $lines[] = '';
        $lines[] = '> [!note] Chart scope';
        $lines[] = '> The chart shows up to the eight strongest incoming and outgoing page relationships. Complete deduplicated lists are below.';
        $lines[] = ''; $lines[] = '## Incoming internal links'; $lines[] = '';

        if ( empty( $incoming_groups ) ) {
            $lines[] = '_No scanned incoming internal links._';
        } else {
            foreach ( $incoming_groups as $group ) {
                $wiki = self::wikilink( $group['id'], $files, $group['title'] );
                if ( ! $wiki ) { continue; }
                $lines[] = '### ' . $wiki; $lines[] = '';
                $lines[] = '- Occurrences: **' . absint( $group['occurrences'] ) . '**';
                if ( ! empty( $group['anchors'] ) ) {
                    arsort( $group['anchors'] );
                    $lines[] = '- Anchor variants:';
                    foreach ( array_slice( $group['anchors'], 0, 12, true ) as $anchor => $occurrences ) {
                        $lines[] = '  - **' . self::md_text( $anchor ) . '** ×' . absint( $occurrences );
                    }
                }
                $lines[] = '';
            }
        }

        $lines[] = '## Outgoing internal links'; $lines[] = '';
        if ( empty( $outgoing_groups ) ) {
            $lines[] = '_No scanned outgoing internal links._';
        } else {
            foreach ( $outgoing_groups as $group ) {
                $wiki = self::wikilink( $group['id'], $files, $group['title'] );
                if ( ! $wiki ) { continue; }
                $lines[] = '### ' . $wiki; $lines[] = '';
                $lines[] = '- Occurrences: **' . absint( $group['occurrences'] ) . '**';
                if ( ! empty( $group['anchors'] ) ) {
                    arsort( $group['anchors'] );
                    $lines[] = '- Anchor variants:';
                    foreach ( array_slice( $group['anchors'], 0, 12, true ) as $anchor => $occurrences ) {
                        $lines[] = '  - **' . self::md_text( $anchor ) . '** ×' . absint( $occurrences );
                    }
                }
                $lines[] = '';
            }
        }

        $lines[] = '## External destinations'; $lines[] = '';
        $lines[] = '> [!warning] Safe review';
        $lines[] = '> External destinations are deliberately rendered as code, not clickable Markdown links.';
        $lines[] = '';
        if ( empty( $external_groups ) ) {
            $lines[] = '_No scanned external destinations._';
        } else {
            foreach ( $external_groups as $host => $group ) {
                $lines[] = '### ' . self::md_text( $host ); $lines[] = '';
                $lines[] = '- Occurrences: **' . absint( $group['occurrences'] ) . '**';
                $lines[] = '- Unique URLs: **' . count( $group['urls'] ) . '**';
                if ( ! empty( $group['statuses'] ) ) {
                    $lines[] = '- HTTP statuses: **' . implode( ', ', array_map( 'absint', array_keys( $group['statuses'] ) ) ) . '**';
                }
                foreach ( array_slice( array_keys( $group['urls'] ), 0, 20 ) as $url ) { $lines[] = '  - `' . $url . '`'; }
                $lines[] = '';
            }
        }
        return implode( "\n", $lines ) . "\n";
    }

    private static function index_note( $pages, $links, $scan_id, $scope, $post_id, $view, $files, $domains ) {
        $orphans = 0; $broken = 0; $scores = array(); $internal_occurrences = 0; $external_occurrences = 0;
        foreach ( $pages as $page ) {
            if ( ! empty( $page['is_orphan'] ) ) { $orphans++; }
            $scores[] = absint( $page['seo_score'] );
        }
        foreach ( $links as $link ) {
            if ( absint( $link['http_status'] ) >= 400 || 'broken' === $link['issue_type'] ) { $broken++; }
            if ( 'external' === $link['destination_type'] ) { $external_occurrences++; } else { $internal_occurrences++; }
        }
        $avg_score = $scores ? (int) round( array_sum( $scores ) / count( $scores ) ) : 0;
        $site_name = get_bloginfo( 'name' );
        $site_name = $site_name ? self::md_text( $site_name ) : self::md_text( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

        $attention = $pages;
        usort( $attention, static function( $a, $b ) {
            $ar = ( ! empty( $a['is_orphan'] ) ? 1000 : 0 ) + absint( $a['broken_count'] ) * 100 + absint( $a['weak_anchor_count'] ) * 10 + ( 100 - absint( $a['seo_score'] ) );
            $br = ( ! empty( $b['is_orphan'] ) ? 1000 : 0 ) + absint( $b['broken_count'] ) * 100 + absint( $b['weak_anchor_count'] ) * 10 + ( 100 - absint( $b['seo_score'] ) );
            return $br <=> $ar;
        } );

        $lines = array(
            '---', 'type: dma-site-link-intelligence',
            'scan_id: ' . absint( $scan_id ),
            'scope: ' . self::yaml_string( $scope ),
            'view: ' . self::yaml_string( $view ),
            'site: ' . self::yaml_string( home_url( '/' ) ),
            'site_name: ' . self::yaml_string( $site_name ),
            'pages: ' . count( $pages ),
            'internal_link_occurrences: ' . $internal_occurrences,
            'external_link_occurrences: ' . $external_occurrences,
            'external_domains: ' . count( $domains ),
            'orphan_pages: ' . $orphans,
            'broken_link_records: ' . $broken,
            'average_seo_score: ' . $avg_score,
            'tags:', '  - dma', '  - report/site-health', '---', '',
            '# ' . $site_name, '', '## Site Link Intelligence', '',
            '> [!success] Local scan snapshot',
            '> **Scan #' . absint( $scan_id ) . '** · ' . count( $pages ) . ' pages · ' . $internal_occurrences . ' internal link occurrences · ' . count( $domains ) . ' external domains  ',
            '> Generated locally by **DMA InternLink Mapper**. No external report service was contacted.', '',
            '## Health dashboard', '', '| Metric | Value |', '| --- | ---: |',
            '| Average SEO score | **' . $avg_score . '/100** |',
            '| Exported pages | **' . count( $pages ) . '** |',
            '| Internal link occurrences | **' . $internal_occurrences . '** |',
            '| External link occurrences | **' . $external_occurrences . '** |',
            '| External domains | **' . count( $domains ) . '** |',
            '| Orphan pages | **' . $orphans . '** |',
            '| Broken link records | **' . $broken . '** |', '',
            '### Link composition', '', '```mermaid', 'pie showData', '    title Link occurrence composition',
            '    "Internal" : ' . $internal_occurrences,
            '    "External" : ' . $external_occurrences, '```', '',
            '### Page health', '', '```mermaid', 'pie showData', '    title Exported page health',
            '    "Connected" : ' . max( 0, count( $pages ) - $orphans ),
            '    "Orphan" : ' . $orphans, '```', '',
            '## Quick navigation', '',
            '- [[Reports/Orphan Pages|Orphan Pages]]',
            '- [[Reports/Broken Links|Broken Links]]',
            '- [[Reports/External Domains|External Domains]]',
            '- [[README|How to use this vault]]',
        );
        if ( $post_id && isset( $files[ $post_id ] ) ) { $lines[] = '- Focus page: ' . self::wikilink( $post_id, $files, get_the_title( $post_id ) ); }

        $lines[]=''; $lines[]='## Pages needing attention'; $lines[]=''; $shown=0;
        foreach ( $attention as $page ) {
            if ( $shown >= 12 ) { break; }
            if ( empty( $page['is_orphan'] ) && 0 === absint( $page['broken_count'] ) && 0 === absint( $page['weak_anchor_count'] ) && absint( $page['seo_score'] ) >= 90 ) { continue; }
            $flags=array();
            if ( ! empty( $page['is_orphan'] ) ) { $flags[]='orphan'; }
            if ( absint( $page['broken_count'] ) ) { $flags[]=absint( $page['broken_count'] ) . ' broken'; }
            if ( absint( $page['weak_anchor_count'] ) ) { $flags[]=absint( $page['weak_anchor_count'] ) . ' weak anchors'; }
            $lines[]='- ' . self::wikilink( $page['post_id'], $files, $page['title'] ) . ' · SEO **' . absint( $page['seo_score'] ) . '/100**' . ( $flags ? ' · ' . implode( ', ', $flags ) : '' );
            $shown++;
        }
        if ( ! $shown ) { $lines[]='_No obvious attention items are present in this export scope._'; }
        $lines[]=''; $lines[]='## All exported pages'; $lines[]='';
        foreach ( $pages as $page ) { $lines[]='- ' . self::wikilink( $page['post_id'], $files, $page['title'] ) . ' · SEO ' . absint( $page['seo_score'] ) . '/100'; }
        return implode( "\n", $lines ) . "\n";
    }

    private static function orphan_note( $pages, $files ) {
        $lines = array( '# Orphan Pages', '', 'Pages marked as orphaned in the exported scan snapshot.', '' );
        $count = 0;
        foreach ( $pages as $page ) {
            if ( empty( $page['is_orphan'] ) ) { continue; }
            $count++;
            $lines[] = '- ' . self::wikilink( $page['post_id'], $files, $page['title'] ) . ' · SEO ' . absint( $page['seo_score'] ) . '/100';
        }
        if ( ! $count ) { $lines[] = '_No orphan pages are present in this export scope._'; }
        return implode( "\n", $lines ) . "\n";
    }

    private static function broken_note( $links, $files ) {
        $lines = array( '# Broken Links', '', 'Broken-link records from the exported scan scope.', '' );
        $count = 0;
        foreach ( $links as $link ) {
            if ( absint( $link['http_status'] ) < 400 && 'broken' !== $link['issue_type'] ) { continue; }
            $count++;
            $source = self::wikilink( $link['source_post_id'], $files, $link['source_title'] );
            $lines[] = '- ' . ( $source ? $source . ' → ' : '' ) . '`' . esc_url_raw( $link['target_url'] ) . '` · HTTP ' . absint( $link['http_status'] );
        }
        if ( ! $count ) { $lines[] = '_No broken links are present in this export scope._'; }
        return implode( "\n", $lines ) . "\n";
    }

    private static function external_domains_note( $domains, $files ) {
        $lines = array( '# External Domains', '', 'External destinations are shown as code, not Markdown hyperlinks, so reviewing an unknown destination does not require opening it.', '' );
        if ( empty( $domains ) ) {
            $lines[] = '_No external domains are present in this export scope._';
            return implode( "\n", $lines ) . "\n";
        }
        ksort( $domains );
        foreach ( $domains as $host => $rows ) {
            $lines[] = '## ' . self::md_text( $host );
            $lines[] = '';
            $lines[] = 'Occurrences: **' . count( $rows ) . '**';
            $lines[] = '';
            $seen = array();
            foreach ( $rows as $row ) {
                $key = absint( $row['source_post_id'] ) . '|' . $row['target_url'];
                if ( isset( $seen[ $key ] ) ) { continue; }
                $seen[ $key ] = true;
                $source = self::wikilink( $row['source_post_id'], $files, $row['source_title'] );
                $lines[] = '- ' . ( $source ? $source . ' → ' : '' ) . '`' . esc_url_raw( $row['target_url'] ) . '`';
            }
            $lines[] = '';
        }
        return implode( "\n", $lines ) . "\n";
    }

    private static function readme_note( $scan_id, $scope, $view ) {
        $site_name = get_bloginfo( 'name' );
        $site_name = $site_name ? self::md_text( $site_name ) : self::md_text( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        return implode( "\n", array(
            '---', 'type: dma-vault-guide', 'tags:', '  - dma', '  - guide', '---', '',
            '# ' . $site_name . ' · Obsidian Link Intelligence', '',
            '> [!tip] Start here',
            '> Open [[DMA InternLink Mapper]] for the site dashboard, then use **Graph view** for the whole site or **Local graph** from an individual page note.', '',
            '## What this vault contains', '',
            '- **Pages/** — one note per exported WordPress page.',
            '- **Reports/Orphan Pages** — pages without scanned incoming internal links.',
            '- **Reports/Broken Links** — broken-link records from this scan scope.',
            '- **Reports/External Domains** — external destinations grouped for safer review.',
            '- Native `[[wikilinks]]` create the Obsidian graph. Duplicate link occurrences are summarized inside page notes instead of creating noisy duplicate relationships.', '',
            '## Built-in visualizations', '',
            'The dashboard and page notes use **Mermaid**, which Obsidian supports natively. No community plugin is required. If Mermaid rendering is unavailable, the underlying Markdown data and wikilinks still remain fully usable.', '',
            '## Safety', '',
            '> [!warning] External destinations',
            '> External URLs are intentionally written inside backticks instead of clickable Markdown links. This prevents an unknown or spam-like destination from becoming an accidental click target.', '',
            '## Export source', '',
            '- Scan: **#' . absint( $scan_id ) . '**',
            '- Scope: **' . self::md_text( $scope ) . '**',
            '- Source view: **' . self::md_text( $view ) . '**',
            '- Website: `' . esc_url_raw( home_url( '/' ) ) . '`', '',
            'Safety limits for site-wide export: up to ' . number_format_i18n( self::MAX_SITE_PAGES ) . ' scanned pages and ' . number_format_i18n( self::MAX_LINKS ) . ' link records per archive.', '',
            'Generated locally by **DMA InternLink Mapper**.', '',
        ) ) . "\n";
    }
}
