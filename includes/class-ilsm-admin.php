<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ILSM_Admin {
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_ilsm_export_csv', array( $this, 'export' ) );
        add_action( 'admin_post_ilsm_export_pdf', array( $this, 'export_pdf' ) );
        add_action( 'admin_post_ilsm_export_knowledge_pdf', array( $this, 'export_knowledge_pdf' ) );
        add_action( 'admin_post_ilsm_export_visual_pdf', array( $this, 'export_visual_pdf' ) );
        add_action( 'admin_post_ilsm_repair_schema', array( $this, 'repair_schema' ) );
        add_action( 'admin_post_ilsm_orphan_ignore', array( $this, 'orphan_ignore' ) );
        add_action( 'admin_post_ilsm_delete_scan_history', array( $this, 'delete_scan_history' ) );
        add_action( 'admin_post_ilsm_delete_all_scan_data', array( $this, 'delete_all_scan_data' ) );
        add_action( 'admin_init', array( $this, 'save_settings' ) );
        add_action( 'wp_ajax_ilsm_page_seo_analysis', array( $this, 'ajax_page_seo_analysis' ) );
    }

    public function menu() {
        add_menu_page(
            __( 'DMA InternLink Mapper', 'dma-internlink-mapper' ),
            __( 'DMA InternLink Mapper', 'dma-internlink-mapper' ),
            'ilsm_view_reports',
            'ilsm-dashboard',
            array( $this, 'dashboard' ),
            ILSM_URL . 'admin/images/icon-20.png',
            58
        );

        $items = array(
            'ilsm-dashboard'          => __( 'Dashboard', 'dma-internlink-mapper' ),
            'ilsm-link-opportunities' => __( 'Link Opportunities', 'dma-internlink-mapper' ),
            'ilsm-visual-map'         => __( 'Visual Map', 'dma-internlink-mapper' ),
            'ilsm-link-report'        => __( 'Link Report', 'dma-internlink-mapper' ),
            'ilsm-on-page-seo'        => __( 'On-Page SEO', 'dma-internlink-mapper' ),
            'ilsm-external-links'     => __( 'External Links', 'dma-internlink-mapper' ),
            'ilsm-broken-links'       => __( 'Broken Links', 'dma-internlink-mapper' ),
            'ilsm-seo-issues'         => __( 'SEO Issues', 'dma-internlink-mapper' ),
            'ilsm-anchor-analysis'    => __( 'Anchor Analysis', 'dma-internlink-mapper' ),
            'ilsm-orphans'            => __( 'Orphan Pages', 'dma-internlink-mapper' ),
            'ilsm-history'            => __( 'Scan History', 'dma-internlink-mapper' ),
            'ilsm-health-audit'       => __( 'Health Audit', 'dma-internlink-mapper' ),
            'ilsm-settings'           => __( 'Settings', 'dma-internlink-mapper' ),
        );

        foreach ( $items as $slug => $label ) {
            $capability = 'ilsm_view_reports';
            add_submenu_page(
                'ilsm-dashboard',
                $label,
                $label,
                $capability,
                $slug,
                array( $this, str_replace( '-', '_', str_replace( 'ilsm-', '', $slug ) ) )
            );
        }
    }

    private function display_text( $value ) {
        return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    private function header( $title, $subtitle = '' ) {
        $nav_items = array(
            'ilsm-dashboard'           => __( 'Dashboard', 'dma-internlink-mapper' ),
            'ilsm-link-opportunities'  => __( 'Opportunities', 'dma-internlink-mapper' ),
            'ilsm-visual-map'          => __( 'Visual Map', 'dma-internlink-mapper' ),
            'ilsm-link-report'         => __( 'Link Report', 'dma-internlink-mapper' ),
            'ilsm-on-page-seo'         => __( 'On-Page SEO', 'dma-internlink-mapper' ),
            'ilsm-external-links'      => __( 'External Links', 'dma-internlink-mapper' ),
            'ilsm-broken-links'        => __( 'Broken Links', 'dma-internlink-mapper' ),
            'ilsm-anchor-analysis'     => __( 'Anchor Analysis', 'dma-internlink-mapper' ),
            'ilsm-seo-issues'          => __( 'SEO Issues', 'dma-internlink-mapper' ),
            'ilsm-health-audit'        => __( 'Health Audit', 'dma-internlink-mapper' ),
            'ilsm-settings'            => __( 'Settings', 'dma-internlink-mapper' ),
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'ilsm-dashboard';
        echo '<div class="wrap ilsm-wrap">';
        echo '<div class="ilsm-product-bar">';
        echo '<a class="ilsm-product-brand" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '"><img src="' . esc_url( ILSM_URL . 'admin/images/icon-40.png' ) . '" alt=""><span>' . esc_html__( 'DMA InternLink Mapper', 'dma-internlink-mapper' ) . '</span></a>';
        echo '<nav class="ilsm-product-nav" aria-label="' . esc_attr__( 'Plugin navigation', 'dma-internlink-mapper' ) . '">';
        foreach ( $nav_items as $slug => $label ) {
            echo '<a class="' . ( $current === $slug ? 'is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        echo '<div class="ilsm-product-actions"><button type="button" class="ilsm-global-theme-toggle" aria-pressed="true" aria-label="' . esc_attr__( 'Switch plugin color theme', 'dma-internlink-mapper' ) . '"><i class="fa fa-sun-o" aria-hidden="true"></i><span>' . esc_html__( 'Light', 'dma-internlink-mapper' ) . '</span></button><a class="ilsm-btn ilsm-product-help" href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings' ) ) . '"><i class="fa fa-question-circle-o" aria-hidden="true"></i> ' . esc_html__( 'Help', 'dma-internlink-mapper' ) . '</a></div>';
        echo '</div>';
        echo '<div class="ilsm-page-head">';
        echo '<div class="ilsm-brand-icon"><img src="' . esc_url( ILSM_URL . 'admin/images/icon-40.png' ) . '" alt=""></div>';
        echo '<div><h1>' . esc_html( $this->display_text( $title ) ) . '</h1><p>' . esc_html( $this->display_text( $subtitle ) ) . '</p></div>';
        echo '</div>';
        // WordPress and third-party admin notices use this marker as the safe
        // insertion boundary. Without it, notices can be moved inside the
        // flex title block and split the page header layout.
        echo '<hr class="wp-header-end">';
    }

    private function footer() { echo '</div>'; }

    /**
     * Render a consistent first-scan state for reports that depend on indexed data.
     *
     * @param array $args Screen-specific copy and icons.
     */
    private function render_first_scan_state( $args ) {
        $defaults = array(
            'eyebrow'    => __( 'Scan data not built yet', 'dma-internlink-mapper' ),
            'title'      => __( 'Run your first scan to build this report', 'dma-internlink-mapper' ),
            'intro'      => __( 'DMA InternLink Mapper needs one completed local scan before this screen can show reliable results. No content is changed during scanning.', 'dma-internlink-mapper' ),
            'visual_icon'=> 'fa-search',
            'steps'      => array(),
        );
        $args = wp_parse_args( $args, $defaults );

        echo '<section class="ilsm-panel ilsm-first-scan-empty" aria-labelledby="ilsm-first-scan-title">';
        echo '<div class="ilsm-first-scan-visual" aria-hidden="true"><span class="ilsm-first-scan-ring"><i class="fa ' . esc_attr( $args['visual_icon'] ) . '"></i></span><span class="ilsm-first-scan-node ilsm-node-one"></span><span class="ilsm-first-scan-node ilsm-node-two"></span><span class="ilsm-first-scan-node ilsm-node-three"></span></div>';
        echo '<span class="ilsm-first-scan-eyebrow">' . esc_html( $args['eyebrow'] ) . '</span>';
        echo '<h2 id="ilsm-first-scan-title">' . esc_html( $args['title'] ) . '</h2>';
        echo '<p class="ilsm-first-scan-intro">' . esc_html( $args['intro'] ) . '</p>';

        if ( ! empty( $args['steps'] ) ) {
            echo '<div class="ilsm-first-scan-steps">';
            foreach ( array_values( $args['steps'] ) as $index => $step ) {
                $step = wp_parse_args(
                    $step,
                    array(
                        'icon'        => 'fa-check-circle-o',
                        'title'       => '',
                        'description' => '',
                    )
                );
                echo '<div><span>' . esc_html( (string) ( $index + 1 ) ) . '</span><i class="fa ' . esc_attr( $step['icon'] ) . '" aria-hidden="true"></i><strong>' . esc_html( $step['title'] ) . '</strong><small>' . esc_html( $step['description'] ) . '</small></div>';
            }
            echo '</div>';
        }

        echo '<div class="ilsm-first-scan-actions"><a class="ilsm-btn ilsm-btn-primary ilsm-btn-large" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '"><i class="fa fa-search" aria-hidden="true"></i> ' . esc_html__( 'Start First Scan', 'dma-internlink-mapper' ) . '</a><a class="ilsm-btn ilsm-btn-large" href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings' ) ) . '"><i class="fa fa-sliders" aria-hidden="true"></i> ' . esc_html__( 'Review Scan Settings', 'dma-internlink-mapper' ) . '</a></div>';
        echo '<p class="ilsm-first-scan-note"><i class="fa fa-lock" aria-hidden="true"></i> ' . esc_html__( 'Scanning runs locally in WordPress and does not send site content to an external service.', 'dma-internlink-mapper' ) . '</p>';
        echo '</section>';
    }

    private function panel_title( $icon, $title, $tools = '' ) {
        return '<div class="ilsm-panel-head"><h2><i class="fa ' . esc_attr( $icon ) . '" aria-hidden="true"></i>' . esc_html( $this->display_text( $title ) ) . '</h2>' . $tools . '</div>';
    }

    public function dashboard() {
        $this->header( __( 'DMA InternLink Mapper', 'dma-internlink-mapper' ), __( 'Private, manual internal-link scanning with local reports and visual maps.', 'dma-internlink-mapper' ) );
        $scan               = $this->latest();
        $stats              = $this->stats();
        $best               = $this->best_page();
        $has_completed_scan = (bool) ILSM_Database::latest_completed_scan_id();
        $scan_button_label  = $has_completed_scan ? __( 'Rescan', 'dma-internlink-mapper' ) : __( 'Start First Scan', 'dma-internlink-mapper' );
        $scan_button_icon   = $has_completed_scan ? 'fa-refresh' : 'fa-search';

        echo '<div class="ilsm-toolbar">';
        echo '<button class="ilsm-btn ilsm-btn-primary" id="ilsm-start" data-first-scan="' . ( $has_completed_scan ? '0' : '1' ) . '"><i class="fa ' . esc_attr( $scan_button_icon ) . '" aria-hidden="true"></i> ' . esc_html( $scan_button_label ) . '</button>';
        echo '<button class="ilsm-btn" id="ilsm-pause"><i class="fa fa-pause" aria-hidden="true"></i> ' . esc_html__( 'Pause', 'dma-internlink-mapper' ) . '</button>';
        echo '<button class="ilsm-btn" id="ilsm-resume"><i class="fa fa-forward" aria-hidden="true"></i> ' . esc_html__( 'Resume', 'dma-internlink-mapper' ) . '</button>';
        echo '<button class="ilsm-btn ilsm-btn-danger-soft" id="ilsm-cancel"><i class="fa fa-times" aria-hidden="true"></i> ' . esc_html__( 'Cancel', 'dma-internlink-mapper' ) . '</button>';
        if ( current_user_can( 'ilsm_manage_settings' ) ) {
            echo '<button class="ilsm-btn ilsm-btn-danger-soft" id="ilsm-force-unlock"><i class="fa fa-unlock" aria-hidden="true"></i> ' . esc_html__( 'Force unlock', 'dma-internlink-mapper' ) . '</button>';
        }
        if ( $has_completed_scan ) {
            echo '<div class="ilsm-toolbar-note"><i class="fa fa-info-circle" aria-hidden="true"></i> ' . esc_html__( 'Rescan starts a fresh manual scan. Previous completed reports remain available until the new scan finishes.', 'dma-internlink-mapper' ) . '</div>';
        } else {
            echo '<div class="ilsm-toolbar-note"><i class="fa fa-info-circle" aria-hidden="true"></i> ' . esc_html__( 'Start your first scan to discover internal links, orphan pages, technical SEO issues, and link opportunities.', 'dma-internlink-mapper' ) . '</div>';
        }
        echo '</div>';

        $cards = array(
            array( __( 'Pages Scanned', 'dma-internlink-mapper' ), $stats['pages'], 'fa-file-text', 'blue', __( 'Indexed content', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-visual-map&view=pages' ) ),
            array( __( 'Internal Links', 'dma-internlink-mapper' ), $stats['links'], 'fa-link', 'green', __( 'All discovered links', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-link-report' ) ),
            array( __( 'Incoming Links', 'dma-internlink-mapper' ), $stats['incoming'], 'fa-arrow-down', 'indigo', __( 'Links pointing inward', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-visual-map&view=incoming' ) ),
            array( __( 'Outgoing Links', 'dma-internlink-mapper' ), $stats['outgoing'], 'fa-arrow-up', 'orange', __( 'Links pointing outward', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-visual-map&view=outgoing' ) ),
            array( __( 'Broken Links', 'dma-internlink-mapper' ), $stats['broken'], 'fa-chain-broken', 'red', __( 'Needs attention', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-link-report&issue=broken' ) ),
            array( __( 'Redirects', 'dma-internlink-mapper' ), $stats['redirects'], 'fa-random', 'purple', __( 'Redirected targets', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-link-report&issue=redirect' ) ),
            array( __( 'Orphan Pages', 'dma-internlink-mapper' ), $stats['orphans'], 'fa-user-o', 'slate', __( 'No incoming links', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-orphans' ) ),
            array( __( 'Weak Anchors', 'dma-internlink-mapper' ), $stats['weak'], 'fa-font', 'amber', __( 'Generic or empty text', 'dma-internlink-mapper' ), admin_url( 'admin.php?page=ilsm-anchor-analysis' ) ),
        );

        echo '<div class="ilsm-kpis">';
        foreach ( $cards as $card ) {
            // translators: %s is the dashboard metric label.
            echo '<a class="ilsm-kpi" href="' . esc_url( $card[5] ) . '" aria-label="' . esc_attr( sprintf( __( 'View %s', 'dma-internlink-mapper' ), $card[0] ) ) . '">';
            echo '<span class="ilsm-kpi-icon is-' . esc_attr( $card[3] ) . '"><i class="fa ' . esc_attr( $card[2] ) . '"></i></span>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
            echo '<div><span class="ilsm-kpi-label">' . esc_html( $card[0] ) . '</span><strong data-count="' . absint( $card[1] ) . '">' . number_format_i18n( $card[1] ) . '</strong><small>' . esc_html( $card[4] ) . '</small></div>';
            echo '<span class="ilsm-kpi-arrow"><i class="fa fa-arrow-right"></i></span>';
            echo '</a>';
        }
        echo '</div>';

        echo '<div class="ilsm-dashboard-grid">';
        echo '<div class="ilsm-left-column">';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<section class="ilsm-panel">' . $this->panel_title( 'fa-line-chart', __( 'Scan Progress', 'dma-internlink-mapper' ) );
        echo '<div class="ilsm-progress-row"><span>' . esc_html__( 'Overall progress', 'dma-internlink-mapper' ) . '</span><b id="ilsm-percent">' . esc_html( $scan['percent'] ) . '%</b></div>';
        echo '<div class="ilsm-progress"><span style="width:' . esc_attr( $scan['percent'] ) . '%"></span></div>';
        echo '<div class="ilsm-scan-meta"><div><small>' . esc_html__( 'Current batch', 'dma-internlink-mapper' ) . '</small><strong>' . absint( $scan['batch_no'] ?? 0 ) . '</strong></div><div><small>' . esc_html__( 'Scanned', 'dma-internlink-mapper' ) . '</small><strong id="ilsm-scanned">' . absint( $scan['scanned_items'] ?? 0 ) . ' / ' . absint( $scan['total_items'] ?? 0 ) . '</strong></div><div><small>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '</small><strong id="ilsm-status">' . esc_html( ucfirst( $scan['status'] ) ) . '</strong></div></div>';
        $show_scan_quotes = in_array( $scan['status'], array( 'pending', 'running', 'paused', 'interrupted' ), true );
        echo '<aside id="ilsm-scan-quotes" class="ilsm-scan-quotes"' . ( $show_scan_quotes ? '' : ' hidden' ) . ' aria-live="polite" aria-atomic="true">';
        echo '<div class="ilsm-scan-quotes-head"><span><i class="fa fa-lightbulb-o" aria-hidden="true"></i> ' . esc_html__( 'While the scan works', 'dma-internlink-mapper' ) . '</span><small id="ilsm-scan-quote-count">1 / 10</small></div>';
        echo '<p id="ilsm-scan-quote-text">' . esc_html__( 'Orphan pages can be excellent content that the rest of the site simply forgot to introduce.', 'dma-internlink-mapper' ) . '</p>';
        echo '</aside>';
        echo '<p class="ilsm-muted"><i class="fa fa-shield" aria-hidden="true"></i> ' . esc_html__( 'Batches are intentionally limited to reduce database and CPU load.', 'dma-internlink-mapper' ) . '</p>';
        echo '</section>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<section class="ilsm-panel ilsm-color-panel">' . $this->panel_title( 'fa-paint-brush', __( 'Link Colors', 'dma-internlink-mapper' ) );
        $settings = get_option( 'ilsm_settings', array() );
        $colors = array( 'incoming_color' => __( 'Incoming', 'dma-internlink-mapper' ), 'outgoing_color' => __( 'Outgoing', 'dma-internlink-mapper' ), 'broken_color' => __( 'Broken', 'dma-internlink-mapper' ), 'redirect_color' => __( 'Redirect', 'dma-internlink-mapper' ) );
        echo '<div class="ilsm-color-grid">';
        foreach ( $colors as $key => $label ) {
            $value = sanitize_hex_color( $settings[ $key ] ?? '#2563EB' ) ?: '#2563EB';
            echo '<div><span class="ilsm-color-dot" style="background:' . esc_attr( $value ) . '"></span><small>' . esc_html( $label ) . '</small><code>' . esc_html( strtoupper( $value ) ) . '</code></div>';
        }
        echo '</div><a class="ilsm-text-link" href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings' ) ) . '">' . esc_html__( 'Edit map colors', 'dma-internlink-mapper' ) . ' <i class="fa fa-arrow-right"></i></a></section>';
        echo '</div>';

        echo '<div class="ilsm-main-column">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<section class="ilsm-panel ilsm-map-card" id="ilsm-map-panel">' . $this->panel_title( 'fa-bullseye', __( 'Wheel View', 'dma-internlink-mapper' ), '<div class="ilsm-map-actions"><button type="button" class="ilsm-icon-btn" id="ilsm-fit-map" title="' . esc_attr__( 'Fit map to view', 'dma-internlink-mapper' ) . '" aria-label="' . esc_attr__( 'Fit map to view', 'dma-internlink-mapper' ) . '"><i class="fa fa-arrows-alt" aria-hidden="true"></i></button><button type="button" class="ilsm-icon-btn" id="ilsm-big-map" title="' . esc_attr__( 'Open large map', 'dma-internlink-mapper' ) . '" aria-label="' . esc_attr__( 'Open large map', 'dma-internlink-mapper' ) . '"><i class="fa fa-expand" aria-hidden="true"></i></button></div>' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div class="ilsm-map-toolbar"><select id="ilsm-page-search" aria-label="' . esc_attr__( 'Select page', 'dma-internlink-mapper' ) . '">' . $this->page_options( $best['post_id'] ?? 0 ) . '</select><select id="ilsm-map-style" aria-label="' . esc_attr__( 'Map style', 'dma-internlink-mapper' ) . '"><option value="wheel">' . esc_html__( 'Wheel', 'dma-internlink-mapper' ) . '</option><option value="radial">' . esc_html__( 'Radial', 'dma-internlink-mapper' ) . '</option><option value="flow">' . esc_html__( 'Flow', 'dma-internlink-mapper' ) . '</option><option value="compact">' . esc_html__( 'Compact', 'dma-internlink-mapper' ) . '</option><option value="organic">' . esc_html__( 'Organic', 'dma-internlink-mapper' ) . '</option></select><button class="ilsm-btn ilsm-btn-small" id="ilsm-load-map"><i class="fa fa-refresh" aria-hidden="true"></i> ' . esc_html__( 'Load', 'dma-internlink-mapper' ) . '</button></div>';
        echo '<div class="ilsm-svg-wrap"><svg id="ilsm-wheel" viewBox="0 0 900 520" role="img" aria-label="' . esc_attr__( 'Internal link wheel map', 'dma-internlink-mapper' ) . '"></svg></div>';
        echo '<div class="ilsm-legend"><span class="in">' . esc_html__( 'Incoming', 'dma-internlink-mapper' ) . '</span><span class="out">' . esc_html__( 'Internal outgoing', 'dma-internlink-mapper' ) . '</span><span class="external">' . esc_html__( 'External', 'dma-internlink-mapper' ) . '</span><span class="bad">' . esc_html__( 'Broken', 'dma-internlink-mapper' ) . '</span><span class="redirect">' . esc_html__( 'Redirect', 'dma-internlink-mapper' ) . '</span></div>';
        echo '</section>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<section class="ilsm-panel ilsm-report-preview">' . $this->panel_title( 'fa-table', __( 'Link Report', 'dma-internlink-mapper' ), '<a class="ilsm-text-link" href="' . esc_url( admin_url( 'admin.php?page=ilsm-link-report' ) ) . '">' . esc_html__( 'Full report', 'dma-internlink-mapper' ) . ' <i class="fa fa-arrow-right" aria-hidden="true"></i></a>' ) . $this->link_preview_table() . '</section>';
        echo '</div>';

        echo '<div class="ilsm-right-column">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<section class="ilsm-panel ilsm-tree-panel">' . $this->panel_title( 'fa-folder-open-o', __( 'Tree View', 'dma-internlink-mapper' ) ) . '<div id="ilsm-tree" class="ilsm-tree"><div class="ilsm-empty"><i class="fa fa-sitemap"></i><p>' . esc_html__( 'Run a scan to build the link tree.', 'dma-internlink-mapper' ) . '</p></div></div></section>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<section class="ilsm-panel ilsm-insights">' . $this->panel_title( 'fa-lightbulb-o', __( 'Page Insights', 'dma-internlink-mapper' ) );
        $score = min( 100, absint( $best['seo_score'] ?? 0 ) );
        $score_label = $score >= 80 ? __( 'Excellent', 'dma-internlink-mapper' ) : ( $score >= 60 ? __( 'Good', 'dma-internlink-mapper' ) : __( 'Needs work', 'dma-internlink-mapper' ) );
        echo '<div class="ilsm-score-wrap">';
        // translators: %d is the SEO score on a scale from 0 to 100.
        echo '<div id="ilsm-map-score-ring" class="ilsm-score-ring" role="img" aria-label="' . esc_attr( sprintf( __( 'SEO score: %d out of 100', 'dma-internlink-mapper' ), $score ) ) . '">';
        echo '<svg viewBox="0 0 120 120" aria-hidden="true" focusable="false"><defs><linearGradient id="ilsm-score-gradient" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#34d399"/><stop offset="55%" stop-color="#16a34a"/><stop offset="100%" stop-color="#047857"/></linearGradient></defs><circle class="ilsm-score-track" cx="60" cy="60" r="48"/><circle id="ilsm-map-score-progress" class="ilsm-score-progress" cx="60" cy="60" r="48" pathLength="100" stroke-dasharray="' . esc_attr( $score ) . ' 100"/></svg>';
        echo '<span class="ilsm-score-number"><strong id="ilsm-map-score">' . esc_html( $score ) . '</strong><small>/100</small></span></div>';
        echo '<div><strong id="ilsm-map-insight-title">' . esc_html( $this->display_text( $best['title'] ?? __( 'No page selected', 'dma-internlink-mapper' ) ) ) . '</strong><small id="ilsm-map-insight-label">' . esc_html( $score_label ) . '</small></div></div>';
        echo '<div id="ilsm-insight-metrics" class="ilsm-insight-metrics"><div><i class="fa fa-arrow-down"></i><span>' . esc_html__( 'Incoming', 'dma-internlink-mapper' ) . '</span><b>' . absint( $best['incoming_count'] ?? 0 ) . '</b></div><div><i class="fa fa-arrow-up"></i><span>' . esc_html__( 'Outgoing', 'dma-internlink-mapper' ) . '</span><b>' . absint( $best['outgoing_count'] ?? 0 ) . '</b></div><div><i class="fa fa-font"></i><span>' . esc_html__( 'Weak anchors', 'dma-internlink-mapper' ) . '</span><b>' . absint( $best['weak_anchor_count'] ?? 0 ) . '</b></div></div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div id="ilsm-map-recommendations" class="ilsm-recommendations"><h3>' . esc_html__( 'Recommendations', 'dma-internlink-mapper' ) . '</h3>' . $this->recommendations( $best ) . '</div>';
        echo '</section></div></div>';

        $this->footer();
    }

    public function link_opportunities() {
        $this->header( 'Link Opportunities', 'Discover relevant internal-link opportunities from the latest local scan.' );
        ILSM_Link_Opportunities::render();
        $this->footer();
    }

    public function visual_map() {
        $this->header( 'Visual Map', 'Explore your site’s internal linking structure and architecture.' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $selected_post = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $requested_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'link-map';
        $view = in_array( $requested_view, array( 'link-map', 'page-architecture', 'site-architecture', 'knowledge-graph' ), true ) ? $requested_view : 'link-map';
        $best = $selected_post ? $this->page_row( $selected_post ) : $this->best_page();
        $base = admin_url( 'admin.php?page=ilsm-visual-map' );

        echo '<div class="ilsm-page-help"><a href="' . esc_url( admin_url( 'admin.php?page=ilsm-settings#visual-map' ) ) . '"><i class="fa fa-question-circle-o" aria-hidden="true"></i> ' . esc_html__( 'How it works', 'dma-internlink-mapper' ) . '</a></div>';
        echo '<nav class="ilsm-map-tabs" role="tablist" aria-label="' . esc_attr__( 'Visual Map views', 'dma-internlink-mapper' ) . '">';
        $tabs = array(
            'link-map' => __( 'Link Map', 'dma-internlink-mapper' ),
            'page-architecture' => __( 'Page Architecture', 'dma-internlink-mapper' ),
            'site-architecture' => __( 'Site Architecture', 'dma-internlink-mapper' ),
            'knowledge-graph' => __( 'Knowledge Graph', 'dma-internlink-mapper' ),
        );
        foreach ( $tabs as $slug => $label ) {
            echo '<a class="ilsm-map-tab ' . ( $view === $slug ? 'is-active' : '' ) . '" role="tab" aria-selected="' . ( $view === $slug ? 'true' : 'false' ) . '" aria-controls="ilsm-panel-' . esc_attr( $slug ) . '" id="ilsm-tab-' . esc_attr( $slug ) . '" href="' . esc_url( add_query_arg( 'view', $slug, $base ) ) . '" data-map-tab="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';

        if ( ! ILSM_Database::latest_completed_scan_id() ) {
            $this->render_first_scan_state(
                array(
                    'eyebrow'     => __( 'Visual map not built yet', 'dma-internlink-mapper' ),
                    'title'       => __( 'Build your first internal link map', 'dma-internlink-mapper' ),
                    'intro'       => __( 'Run a full local scan to index supported public content and reveal how pages connect across your WordPress site.', 'dma-internlink-mapper' ),
                    'visual_icon' => 'fa-sitemap',
                    'steps'       => array(
                        array( 'icon' => 'fa-files-o', 'title' => __( 'Scan public content', 'dma-internlink-mapper' ), 'description' => __( 'Index posts, pages and enabled public custom post types.', 'dma-internlink-mapper' ) ),
                        array( 'icon' => 'fa-link', 'title' => __( 'Map link relationships', 'dma-internlink-mapper' ), 'description' => __( 'Connect incoming and outgoing links to their scanned pages.', 'dma-internlink-mapper' ) ),
                        array( 'icon' => 'fa-sitemap', 'title' => __( 'Explore architecture', 'dma-internlink-mapper' ), 'description' => __( 'Review page maps, site structure and page-level insights.', 'dma-internlink-mapper' ) ),
                    ),
                )
            );
            $this->footer();
            return;
        }

        echo '<div id="ilsm-panel-link-map" class="ilsm-map-tabpanel ' . ( 'link-map' === $view ? 'is-active' : '' ) . '" role="tabpanel" aria-labelledby="ilsm-tab-link-map" ' . ( 'link-map' === $view ? '' : 'hidden' ) . '>';
        echo '<section class="ilsm-panel ilsm-map-card ilsm-map-full ilsm-map-modern" id="ilsm-map-panel">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div class="ilsm-map-toolbar"><select id="ilsm-page-search" aria-label="' . esc_attr__( 'Select a scanned page', 'dma-internlink-mapper' ) . '">' . $this->page_options( $selected_post ) . '</select><select id="ilsm-map-style" aria-label="' . esc_attr__( 'Map style', 'dma-internlink-mapper' ) . '"><option value="wheel">' . esc_html__( 'Wheel', 'dma-internlink-mapper' ) . '</option><option value="radial">' . esc_html__( 'Radial', 'dma-internlink-mapper' ) . '</option><option value="flow">' . esc_html__( 'Flow', 'dma-internlink-mapper' ) . '</option><option value="compact">' . esc_html__( 'Compact', 'dma-internlink-mapper' ) . '</option><option value="organic">' . esc_html__( 'Organic', 'dma-internlink-mapper' ) . '</option></select><input type="search" id="ilsm-map-filter" class="ilsm-map-filter" placeholder="' . esc_attr__( 'Find a node…', 'dma-internlink-mapper' ) . '" aria-label="' . esc_attr__( 'Find a node', 'dma-internlink-mapper' ) . '"><button class="ilsm-btn ilsm-btn-primary" id="ilsm-load-map"><i class="fa fa-refresh"></i> ' . esc_html__( 'Load Map', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-icon-btn" id="ilsm-zoom-out" aria-label="' . esc_attr__( 'Zoom out', 'dma-internlink-mapper' ) . '"><i class="fa fa-search-minus"></i></button><button type="button" class="ilsm-icon-btn" id="ilsm-zoom-in" aria-label="' . esc_attr__( 'Zoom in', 'dma-internlink-mapper' ) . '"><i class="fa fa-search-plus"></i></button><button type="button" class="ilsm-icon-btn" id="ilsm-fit-map" aria-label="' . esc_attr__( 'Fit map', 'dma-internlink-mapper' ) . '"><i class="fa fa-arrows-alt"></i></button><button type="button" class="ilsm-icon-btn" id="ilsm-big-map" aria-label="' . esc_attr__( 'Open large map', 'dma-internlink-mapper' ) . '"><i class="fa fa-expand"></i></button></div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div class="ilsm-map-page-grid"><div class="ilsm-map-stage"><div class="ilsm-svg-wrap"><svg id="ilsm-wheel" viewBox="0 0 900 520" role="img" aria-label="' . esc_attr__( 'Link relationship visual map', 'dma-internlink-mapper' ) . '"></svg></div><div class="ilsm-legend"><span class="in">' . esc_html__( 'Incoming', 'dma-internlink-mapper' ) . '</span><span class="out">' . esc_html__( 'Internal outgoing', 'dma-internlink-mapper' ) . '</span><span class="external">' . esc_html__( 'External', 'dma-internlink-mapper' ) . '</span><span class="bad">' . esc_html__( 'Broken', 'dma-internlink-mapper' ) . '</span><span class="redirect">' . esc_html__( 'Redirect', 'dma-internlink-mapper' ) . '</span></div></div><aside class="ilsm-map-sidebar"><section class="ilsm-panel ilsm-tree-panel">' . $this->panel_title( 'fa-folder-open-o', __( 'Tree View', 'dma-internlink-mapper' ) ) . '<div id="ilsm-tree" class="ilsm-tree"></div></section><section class="ilsm-panel ilsm-insights ilsm-map-insights">' . $this->panel_title( 'fa-lightbulb-o', __( 'Page Insights', 'dma-internlink-mapper' ) );
        $score=min(100,absint($best['seo_score']??0));$label=$score>=80?'Excellent':($score>=60?'Good':'Needs work');
        echo '<div class="ilsm-score-wrap"><div class="ilsm-score-ring"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="ilsm-score-track" cx="60" cy="60" r="48"/><circle id="ilsm-map-score-progress" class="ilsm-score-progress" cx="60" cy="60" r="48" pathLength="100" stroke-dasharray="' . esc_attr($score) . ' 100"/></svg><span class="ilsm-score-number"><strong id="ilsm-map-score">' . esc_html($score) . '</strong><small>/100</small></span></div><div><strong id="ilsm-map-insight-title">' . esc_html($this->display_text($best['title'] ?? __( 'No page selected', 'dma-internlink-mapper' ))) . '</strong><small id="ilsm-map-insight-label">' . esc_html($label) . '</small></div></div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div id="ilsm-insight-metrics" class="ilsm-insight-metrics"><div><i class="fa fa-arrow-down"></i><span>' . esc_html__( 'Incoming', 'dma-internlink-mapper' ) . '</span><b>' . absint($best['incoming_count']??0) . '</b></div><div><i class="fa fa-arrow-up"></i><span>' . esc_html__( 'Outgoing', 'dma-internlink-mapper' ) . '</span><b>' . absint($best['outgoing_count']??0) . '</b></div><div><i class="fa fa-font"></i><span>' . esc_html__( 'Weak anchors', 'dma-internlink-mapper' ) . '</span><b>' . absint($best['weak_anchor_count']??0) . '</b></div></div><div id="ilsm-map-recommendations" class="ilsm-recommendations"><h3>' . esc_html__( 'Recommendations', 'dma-internlink-mapper' ) . '</h3>' . $this->recommendations($best) . '</div></section></aside></div>';
        $action_post_id = absint( $best['post_id'] ?? 0 );
        $action_title   = $action_post_id ? get_the_title( $action_post_id ) : '';
        $action_url     = $action_post_id && current_user_can( 'read_post', $action_post_id ) ? get_permalink( $action_post_id ) : '';
        $action_edit    = $action_post_id && current_user_can( 'edit_post', $action_post_id ) ? get_edit_post_link( $action_post_id, 'raw' ) : '';
        $action_opps    = $action_post_id ? add_query_arg( array( 'page' => 'ilsm-link-opportunities', 'source_post_id' => $action_post_id ), admin_url( 'admin.php' ) ) : '';
        echo '<section id="ilsm-map-page-actions" class="ilsm-map-page-actions" aria-live="polite"' . ( $action_url ? '' : ' hidden' ) . '>';
        echo '<div class="ilsm-map-page-actions__column ilsm-map-page-actions__identity"><span class="ilsm-map-page-actions__eyebrow">' . esc_html__( 'Current page', 'dma-internlink-mapper' ) . '</span><strong id="ilsm-map-action-title">' . esc_html( $action_title ) . '</strong><a id="ilsm-map-action-url" href="' . esc_url( $action_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $action_url ) . '</a><div class="ilsm-map-page-actions__buttons"><a id="ilsm-map-open-url" class="ilsm-btn ilsm-btn-primary" href="' . esc_url( $action_url ) . '" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link" aria-hidden="true"></i> ' . esc_html__( 'Open URL', 'dma-internlink-mapper' ) . '</a><a id="ilsm-map-edit-page" class="ilsm-btn" href="' . esc_url( $action_edit ) . '"' . ( $action_edit ? '' : ' hidden' ) . '><i class="fa fa-pencil" aria-hidden="true"></i> ' . esc_html__( 'Edit Page', 'dma-internlink-mapper' ) . '</a><button id="ilsm-map-copy-url" type="button" class="ilsm-btn" data-url="' . esc_attr( $action_url ) . '"><i class="fa fa-copy" aria-hidden="true"></i> <span>' . esc_html__( 'Copy URL', 'dma-internlink-mapper' ) . '</span></button><a id="ilsm-map-view-opportunities" class="ilsm-btn" href="' . esc_url( $action_opps ) . '"><i class="fa fa-link" aria-hidden="true"></i> ' . esc_html__( 'View Opportunities', 'dma-internlink-mapper' ) . '</a><a id="ilsm-map-view-report" class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=ilsm-link-report' ) ) . '"><i class="fa fa-table" aria-hidden="true"></i> ' . esc_html__( 'View Report', 'dma-internlink-mapper' ) . '</a></div></div>';
        echo '<div class="ilsm-map-page-actions__column ilsm-map-diagnosis"><span class="ilsm-map-page-actions__eyebrow">' . esc_html__( 'Top crawl diagnosis', 'dma-internlink-mapper' ) . '</span><div id="ilsm-map-diagnosis-status" class="ilsm-map-diagnosis__status is-neutral"><i class="fa fa-info-circle" aria-hidden="true"></i><strong id="ilsm-map-diagnosis-title">' . esc_html__( 'Loading crawl evidence…', 'dma-internlink-mapper' ) . '</strong></div><p id="ilsm-map-diagnosis-explanation" class="ilsm-map-diagnosis__text"></p><p id="ilsm-map-diagnosis-action" class="ilsm-map-diagnosis__action"></p></div>';
        echo '<div class="ilsm-map-page-actions__column ilsm-map-evidence"><span class="ilsm-map-page-actions__eyebrow">' . esc_html__( 'Measured crawl evidence', 'dma-internlink-mapper' ) . '</span><dl id="ilsm-map-diagnostic-metrics" class="ilsm-map-diagnostic-metrics"><div><dt>' . esc_html__( 'Contextual incoming', 'dma-internlink-mapper' ) . '</dt><dd>—</dd></div><div><dt>' . esc_html__( 'Unique source pages', 'dma-internlink-mapper' ) . '</dt><dd>—</dd></div><div><dt>' . esc_html__( 'Unique anchors', 'dma-internlink-mapper' ) . '</dt><dd>—</dd></div><div><dt>' . esc_html__( 'Broken links', 'dma-internlink-mapper' ) . '</dt><dd>—</dd></div></dl><p class="ilsm-map-evidence__note">' . esc_html__( 'Derived only from the latest completed local scan. No ranking or traffic impact is estimated.', 'dma-internlink-mapper' ) . '</p></div></section>';
        echo '<aside id="ilsm-node-drawer" class="ilsm-node-drawer" aria-hidden="true"><button type="button" class="ilsm-drawer-close" aria-label="' . esc_attr__( 'Close details', 'dma-internlink-mapper' ) . '"><i class="fa fa-times"></i></button><div id="ilsm-node-details"></div></aside></section>';
        echo '</div>';

        $this->render_architecture_tab( 'page', $view, $selected_post );
        $this->render_architecture_tab( 'site', $view, $selected_post );
        $this->render_knowledge_graph_tab( $view );
        $this->footer();
    }


    /**
     * Render the exploratory, read-only knowledge graph from the latest scan.
     * This view never writes content and uses only plugin-owned scan data.
     */
    private function render_knowledge_graph_tab( $active_view ) {
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        unset( $objects['attachment'] );
        $is_active = 'knowledge-graph' === $active_view;
        echo '<div id="ilsm-panel-knowledge-graph" class="ilsm-map-tabpanel ilsm-knowledge-graph-panel ' . ( $is_active ? 'is-active' : '' ) . '" role="tabpanel" aria-labelledby="ilsm-tab-knowledge-graph" ' . ( $is_active ? '' : 'hidden' ) . ' data-knowledge-graph="1">';
        echo '<section class="ilsm-second-brain-hero"><div class="ilsm-kg-hero-copy"><span class="ilsm-second-brain-kicker"><i class="fa fa-braille" aria-hidden="true"></i> ' . esc_html__( 'Second Brain of the Web', 'dma-internlink-mapper' ) . '</span><h2>' . esc_html__( 'Knowledge Graph', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Explore how your real internal-link relationships connect across the latest completed local scan.', 'dma-internlink-mapper' ) . '</p></div><div class="ilsm-kg-hero-actions"><div class="ilsm-second-brain-badge"><i class="fa fa-lock" aria-hidden="true"></i><strong>' . esc_html__( 'Read-only map', 'dma-internlink-mapper' ) . '</strong><span>' . esc_html__( 'No posts, links or settings are changed.', 'dma-internlink-mapper' ) . '</span></div></div></section>';
        echo '<section class="ilsm-panel ilsm-knowledge-controls"><label><span>' . esc_html__( 'Search the brain', 'dma-internlink-mapper' ) . '</span><input type="search" class="ilsm-knowledge-search" placeholder="' . esc_attr__( 'Find a page…', 'dma-internlink-mapper' ) . '"></label><label><span>' . esc_html__( 'Graph depth', 'dma-internlink-mapper' ) . '</span><select class="ilsm-knowledge-depth"><option value="0">' . esc_html__( 'All connections', 'dma-internlink-mapper' ) . '</option><option value="1" selected>' . esc_html__( '1 hop', 'dma-internlink-mapper' ) . '</option><option value="2">' . esc_html__( '2 hops', 'dma-internlink-mapper' ) . '</option><option value="3">' . esc_html__( '3 hops', 'dma-internlink-mapper' ) . '</option></select></label><label><span>' . esc_html__( 'Display density', 'dma-internlink-mapper' ) . '</span><select class="ilsm-knowledge-limit"><option value="160">' . esc_html__( 'Focused · 160 nodes', 'dma-internlink-mapper' ) . '</option><option value="320" selected>' . esc_html__( 'Balanced · 320 nodes', 'dma-internlink-mapper' ) . '</option><option value="600">' . esc_html__( 'Dense · 600 nodes', 'dma-internlink-mapper' ) . '</option></select></label><details class="ilsm-knowledge-types-menu"><summary><span>' . esc_html__( 'Content types', 'dma-internlink-mapper' ) . '</span><strong class="ilsm-knowledge-types-count">0</strong><i class="fa fa-angle-down" aria-hidden="true"></i></summary><fieldset class="ilsm-type-filter ilsm-knowledge-types"><legend class="screen-reader-text">' . esc_html__( 'Content types', 'dma-internlink-mapper' ) . '</legend>';
        foreach ( $objects as $type => $object ) {
            if ( ! ILSM_SEO_Inspector::is_supported_post_type( $type ) ) { continue; }
            echo '<label><input type="checkbox" value="' . esc_attr( $type ) . '" checked> ' . esc_html( $object->labels->name ) . '</label>';
        }
        $site_export = wp_nonce_url( admin_url( 'admin-post.php?action=ilsm_export_knowledge_pdf&scope=site' ), 'ilsm_export_knowledge_pdf' );
        $page_export_base = wp_nonce_url( admin_url( 'admin-post.php?action=ilsm_export_knowledge_pdf&scope=page' ), 'ilsm_export_knowledge_pdf' );
        $obsidian_knowledge = ILSM_Obsidian_Export::export_url( 'page', 0, 'knowledge-graph' );
        echo '</fieldset></details><div class="ilsm-kg-control-actions"><button type="button" class="ilsm-btn ilsm-btn-primary ilsm-load-knowledge"><i class="fa fa-refresh" aria-hidden="true"></i> ' . esc_html__( 'Refresh Graph', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-btn ilsm-kg-export-open"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> ' . esc_html__( 'Export Report', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-btn ilsm-visual-export-pdf" data-ilsm-export="pdf" data-ilsm-export-target="knowledge"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> ' . esc_html__( 'Export PDF Snapshot', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-btn ilsm-visual-export-png" data-ilsm-export="png" data-ilsm-export-target="knowledge"><i class="fa fa-file-image-o" aria-hidden="true"></i> ' . esc_html__( 'Export PNG', 'dma-internlink-mapper' ) . '</button><a class="ilsm-btn ilsm-visual-export-obsidian" data-ilsm-obsidian-base="' . esc_url( $obsidian_knowledge ) . '" href="' . esc_url( $obsidian_knowledge ) . '"><i class="fa fa-diamond" aria-hidden="true"></i> ' . esc_html__( 'Export Obsidian', 'dma-internlink-mapper' ) . '</a></div></section>';
        echo '<div class="ilsm-kg-export-modal" hidden aria-hidden="true"><div class="ilsm-kg-export-backdrop" data-export-close></div><section class="ilsm-kg-export-dialog" role="dialog" aria-modal="true" aria-labelledby="ilsm-kg-export-title"><button type="button" class="ilsm-kg-export-close" data-export-close aria-label="' . esc_attr__( 'Close export dialog', 'dma-internlink-mapper' ) . '"><i class="fa fa-times" aria-hidden="true"></i></button><span class="ilsm-second-brain-kicker"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> ' . esc_html__( 'Premium PDF export', 'dma-internlink-mapper' ) . '</span><h2 id="ilsm-kg-export-title">' . esc_html__( 'Export Knowledge Graph report', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Export a polished, printable report using only data from the latest completed local scan.', 'dma-internlink-mapper' ) . '</p><div class="ilsm-kg-export-grid"><a class="ilsm-kg-export-card is-site" href="' . esc_url( $site_export ) . '"><span class="ilsm-kg-export-icon"><i class="fa fa-sitemap" aria-hidden="true"></i></span><strong>' . esc_html__( 'Site-wide Audit Report', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Full pages, internal links, anchors, orphan pages, broken links, redirects, issues and architecture summary. Long tables paginate automatically and print completely.', 'dma-internlink-mapper' ) . '</small><span class="ilsm-kg-export-cta">' . esc_html__( 'Export full site PDF', 'dma-internlink-mapper' ) . ' <i class="fa fa-arrow-right" aria-hidden="true"></i></span></a><a class="ilsm-kg-export-card is-page is-disabled" aria-disabled="true" data-page-export-base="' . esc_url( $page_export_base ) . '" href="#"><span class="ilsm-kg-export-icon"><i class="fa fa-file-text-o" aria-hidden="true"></i></span><strong>' . esc_html__( 'Selected Page Report', 'dma-internlink-mapper' ) . '</strong><small class="ilsm-kg-export-page-copy">' . esc_html__( 'Select a page node in the graph first. The report will include every scanned incoming and outgoing relationship for that page.', 'dma-internlink-mapper' ) . '</small><span class="ilsm-kg-export-cta">' . esc_html__( 'Export selected page PDF', 'dma-internlink-mapper' ) . ' <i class="fa fa-arrow-right" aria-hidden="true"></i></span></a></div><div class="ilsm-kg-export-note"><i class="fa fa-lock" aria-hidden="true"></i><span>' . esc_html__( 'No external service is used. Reports are generated locally from one completed scan snapshot; no placeholder metrics or invented recommendations are added.', 'dma-internlink-mapper' ) . '</span></div></section></div>';
        echo '<div class="ilsm-knowledge-layout"><section class="ilsm-panel ilsm-knowledge-stage"><div class="ilsm-knowledge-toolbar" role="toolbar" aria-label="' . esc_attr__( 'Knowledge graph navigation', 'dma-internlink-mapper' ) . '"><div class="ilsm-knowledge-mode" role="group" aria-label="' . esc_attr__( 'Graph dimension', 'dma-internlink-mapper' ) . '"><button type="button" class="ilsm-knowledge-mode-btn is-active" data-mode="2d" aria-pressed="true">' . esc_html__( '2D', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-knowledge-mode-btn" data-mode="3d" aria-pressed="false">' . esc_html__( '3D', 'dma-internlink-mapper' ) . '</button></div><button type="button" class="ilsm-icon-btn ilsm-knowledge-zoom-in" aria-label="' . esc_attr__( 'Zoom in', 'dma-internlink-mapper' ) . '"><i class="fa fa-plus" aria-hidden="true"></i></button><button type="button" class="ilsm-icon-btn ilsm-knowledge-zoom-out" aria-label="' . esc_attr__( 'Zoom out', 'dma-internlink-mapper' ) . '"><i class="fa fa-minus" aria-hidden="true"></i></button><button type="button" class="ilsm-icon-btn ilsm-knowledge-fit" aria-label="' . esc_attr__( 'Fit graph', 'dma-internlink-mapper' ) . '"><i class="fa fa-arrows-alt" aria-hidden="true"></i></button><button type="button" class="ilsm-icon-btn ilsm-knowledge-reset" aria-label="' . esc_attr__( 'Reset graph', 'dma-internlink-mapper' ) . '"><i class="fa fa-crosshairs" aria-hidden="true"></i></button><button type="button" class="ilsm-knowledge-signals is-active" aria-pressed="true" aria-label="' . esc_attr__( 'Toggle live link signals', 'dma-internlink-mapper' ) . '" title="' . esc_attr__( 'Turn live link transfer animation on or off', 'dma-internlink-mapper' ) . '"><i class="fa fa-bolt" aria-hidden="true"></i><span class="ilsm-knowledge-signals-label">' . esc_html__( 'Live signals: ON', 'dma-internlink-mapper' ) . '</span></button><span class="ilsm-kg-flow-legend" aria-hidden="true"><span class="is-incoming"><i></i>' . esc_html__( 'Incoming', 'dma-internlink-mapper' ) . '</span><span class="is-outgoing"><i></i>' . esc_html__( 'Outgoing', 'dma-internlink-mapper' ) . '</span><span class="is-orphan"><i></i>' . esc_html__( 'Orphan', 'dma-internlink-mapper' ) . '</span></span><span class="ilsm-knowledge-live" aria-live="polite"></span></div><div class="ilsm-knowledge-loading" hidden><span class="spinner is-active"></span> ' . esc_html__( 'Connecting the second brain from local scan data…', 'dma-internlink-mapper' ) . '</div><canvas class="ilsm-knowledge-canvas" width="1400" height="820" aria-label="' . esc_attr__( 'Interactive internal-link knowledge graph', 'dma-internlink-mapper' ) . '"></canvas><canvas class="ilsm-knowledge-canvas-3d" width="1400" height="820" aria-label="' . esc_attr__( 'Interactive rotatable 3D internal-link knowledge graph', 'dma-internlink-mapper' ) . '" hidden></canvas><div class="ilsm-knowledge-empty" hidden></div><p class="ilsm-architecture-tip"><i class="fa fa-info-circle" aria-hidden="true"></i> ' . esc_html__( 'Drag nodes to reorganize the graph. Scroll to zoom, drag empty space to pan, click a node to inspect it, and double-click to open Page Insights. Live flares follow the real source → destination direction of links connected to the selected page.', 'dma-internlink-mapper' ) . '</p></section><aside class="ilsm-knowledge-sidebar"><section class="ilsm-panel"><div class="ilsm-side-card-head"><h2>' . esc_html__( 'Brain Signals', 'dma-internlink-mapper' ) . '</h2><i class="fa fa-signal" aria-hidden="true"></i></div><div class="ilsm-knowledge-metrics"></div></section><section class="ilsm-panel"><div class="ilsm-side-card-head"><h2>' . esc_html__( 'Selected Page', 'dma-internlink-mapper' ) . '</h2><i class="fa fa-lightbulb-o" aria-hidden="true"></i></div><div class="ilsm-knowledge-keyboard-nav"><label for="ilsm-knowledge-node-selector">' . esc_html__( 'Keyboard page selector', 'dma-internlink-mapper' ) . '</label><select id="ilsm-knowledge-node-selector" class="ilsm-knowledge-node-selector"><option value="">' . esc_html__( 'Choose a page…', 'dma-internlink-mapper' ) . '</option></select><p class="description">' . esc_html__( 'Use this control to inspect any rendered graph page without a mouse.', 'dma-internlink-mapper' ) . '</p></div><div class="ilsm-knowledge-details"><p class="ilsm-muted">' . esc_html__( 'Select a node to see its relationships and SEO signals.', 'dma-internlink-mapper' ) . '</p></div></section><section class="ilsm-panel"><div class="ilsm-side-card-head"><h2>' . esc_html__( 'Visual language', 'dma-internlink-mapper' ) . '</h2><i class="fa fa-eye" aria-hidden="true"></i></div><ul class="ilsm-knowledge-legend"><li><span class="is-authority"></span>' . esc_html__( 'Large = stronger internal authority', 'dma-internlink-mapper' ) . '</li><li><span class="is-healthy"></span>' . esc_html__( 'Healthy connected page', 'dma-internlink-mapper' ) . '</li><li><span class="is-weak"></span>' . esc_html__( 'Weak SEO / low support', 'dma-internlink-mapper' ) . '</li><li><span class="is-orphan"></span>' . esc_html__( 'Orphan / isolated page', 'dma-internlink-mapper' ) . '</li><li><span class="is-selected"></span>' . esc_html__( 'Selected page and direct neighborhood', 'dma-internlink-mapper' ) . '</li></ul></section></aside></div>';
        echo '<section class="ilsm-panel ilsm-selected-links-panel" aria-labelledby="ilsm-selected-links-title"><div class="ilsm-selected-links-head"><div><span class="ilsm-selected-links-kicker">' . esc_html__( 'Selected node links', 'dma-internlink-mapper' ) . '</span><h3 id="ilsm-selected-links-title">' . esc_html__( 'Incoming & outgoing links', 'dma-internlink-mapper' ) . '</h3><p class="ilsm-selected-links-subtitle">' . esc_html__( 'Showing real internal links and anchor text for the selected page.', 'dma-internlink-mapper' ) . '</p></div><div class="ilsm-selected-links-tools"><input type="search" class="ilsm-selected-links-search" placeholder="' . esc_attr__( 'Search connections…', 'dma-internlink-mapper' ) . '" aria-label="' . esc_attr__( 'Search selected node connections', 'dma-internlink-mapper' ) . '"><label><span class="screen-reader-text">' . esc_html__( 'Sort connections', 'dma-internlink-mapper' ) . '</span><select class="ilsm-selected-links-sort"><option value="authority">' . esc_html__( 'Authority (High → Low)', 'dma-internlink-mapper' ) . '</option><option value="title">' . esc_html__( 'Title (A → Z)', 'dma-internlink-mapper' ) . '</option><option value="seo">' . esc_html__( 'SEO score (High → Low)', 'dma-internlink-mapper' ) . '</option></select></label></div></div><div class="ilsm-selected-links-pagebar"><span class="ilsm-selected-links-name">' . esc_html__( 'No page selected', 'dma-internlink-mapper' ) . '</span></div><div class="ilsm-selected-links-columns"><section><h4>' . esc_html__( 'Incoming links to this page', 'dma-internlink-mapper' ) . ' <span class="ilsm-selected-links-count ilsm-selected-links-count-in">0</span></h4><div class="ilsm-selected-links-incoming"><p class="ilsm-selected-links-empty">' . esc_html__( 'Select a node in the graph to see incoming links.', 'dma-internlink-mapper' ) . '</p></div></section><section><h4>' . esc_html__( 'Outgoing links from this page', 'dma-internlink-mapper' ) . ' <span class="ilsm-selected-links-count ilsm-selected-links-count-out">0</span></h4><div class="ilsm-selected-links-outgoing"><p class="ilsm-selected-links-empty">' . esc_html__( 'Select a node in the graph to see outgoing links.', 'dma-internlink-mapper' ) . '</p></div></section></div></section>';
        echo '</div>';
    }

    private function render_architecture_tab( $mode, $active_view, $selected_post ) {
        $slug = 'page' === $mode ? 'page-architecture' : 'site-architecture';
        $title = 'page' === $mode ? __( 'Page Architecture', 'dma-internlink-mapper' ) : __( 'Site Architecture', 'dma-internlink-mapper' );
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        unset( $objects['attachment'] );
        echo '<div id="ilsm-panel-' . esc_attr( $slug ) . '" class="ilsm-map-tabpanel ' . ( $active_view === $slug ? 'is-active' : '' ) . '" role="tabpanel" aria-labelledby="ilsm-tab-' . esc_attr( $slug ) . '" ' . ( $active_view === $slug ? '' : 'hidden' ) . ' data-architecture-mode="' . esc_attr( $mode ) . '">';
        if ( 'page' === $mode && $selected_post ) {
            $context_title = get_the_title( $selected_post );
            $context_url   = get_permalink( $selected_post );
            if ( $context_title || $context_url ) {
                if ( ! $context_title ) {
                    /* translators: %d: WordPress post ID. */
                    $context_title = sprintf( __( 'Post #%d', 'dma-internlink-mapper' ), $selected_post );
                }
                echo '<section class="ilsm-panel ilsm-page-architecture-context"><div class="ilsm-page-architecture-context-copy"><span>' . esc_html__( 'Page', 'dma-internlink-mapper' ) . '</span><strong>' . esc_html( $context_title ) . '</strong>' . ( $context_url ? '<a href="' . esc_url( $context_url ) . '" target="_blank" rel="noopener">' . esc_html( $context_url ) . '</a>' : '' ) . '</div>' . ( $context_url ? '<a class="ilsm-btn ilsm-page-context-open" href="' . esc_url( $context_url ) . '" target="_blank" rel="noopener"><i class="fa fa-external-link" aria-hidden="true"></i> ' . esc_html__( 'Open in new tab', 'dma-internlink-mapper' ) . '</a>' : '' ) . '</section>';
            }
        }
        echo '<section class="ilsm-panel ilsm-architecture-controls ' . ( 'site' === $mode ? 'is-site-architecture' : 'is-page-architecture' ) . '">';
        if ( 'page' === $mode ) {
            echo '<div class="ilsm-architecture-control"><strong>' . esc_html__( 'Architecture view (depth / tiers)', 'dma-internlink-mapper' ) . '</strong><div class="ilsm-segmented" role="group" aria-label="' . esc_attr__( 'Architecture depth', 'dma-internlink-mapper' ) . '"><button type="button" data-depth="1" aria-pressed="false">' . esc_html__( '1 Tier', 'dma-internlink-mapper' ) . '</button><button type="button" data-depth="2" aria-pressed="false">' . esc_html__( '2 Tiers', 'dma-internlink-mapper' ) . '</button><button type="button" data-depth="3" aria-pressed="false">' . esc_html__( '3 Tiers', 'dma-internlink-mapper' ) . '</button><button type="button" class="is-active" data-depth="0" aria-pressed="true">' . esc_html__( 'All Levels', 'dma-internlink-mapper' ) . '</button></div></div>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
            echo '<div class="ilsm-architecture-control"><label for="ilsm-page-root"><strong>' . esc_html__( 'Start from', 'dma-internlink-mapper' ) . '</strong></label><select id="ilsm-page-root" class="ilsm-architecture-root">' . $this->page_options( $selected_post ) . '</select></div>';
        } else {
            echo '<div class="ilsm-architecture-control ilsm-site-root-summary"><strong>' . esc_html__( 'Site root', 'dma-internlink-mapper' ) . '</strong><span><i class="fa fa-home" aria-hidden="true"></i> ' . esc_html__( 'Homepage — complete site architecture', 'dma-internlink-mapper' ) . '</span></div>';
        }
        echo '<fieldset class="ilsm-architecture-control ilsm-type-filter"><legend>' . esc_html__( 'Include', 'dma-internlink-mapper' ) . '</legend>';
        foreach ( $objects as $type => $object ) {
            if ( ! ILSM_SEO_Inspector::is_supported_post_type( $type ) ) { continue; }
            echo '<label><input type="checkbox" value="' . esc_attr( $type ) . '" checked> ' . esc_html( $object->labels->name ) . '</label>';
        }
        echo '</fieldset>';
        echo '<div class="ilsm-architecture-control"><strong>' . esc_html__( 'Layout / Style', 'dma-internlink-mapper' ) . '</strong><div class="ilsm-layout-switcher" role="group">';
        $layouts = 'page' === $mode ? array( 'tree' => 'fa-sitemap', 'horizontal' => 'fa-random', 'radial' => 'fa-sun-o', 'pack' => 'fa-circle-o', 'list' => 'fa-list', 'grid' => 'fa-th-large' ) : array( 'radial' => 'fa-sun-o', 'tree' => 'fa-sitemap', 'horizontal' => 'fa-random', 'force' => 'fa-braille', 'list' => 'fa-list', 'grid' => 'fa-th-large' );
        foreach ( $layouts as $layout => $icon ) {
            echo '<button type="button" class="' . ( array_key_first( $layouts ) === $layout ? 'is-active' : '' ) . '" data-layout="' . esc_attr( $layout ) . '" aria-label="' . esc_attr( ucfirst( $layout ) ) . '"><i class="fa ' . esc_attr( $icon ) . '"></i></button>';
        }
        $obsidian_scope = 'site' === $mode ? 'site' : 'page';
        $obsidian_url = ILSM_Obsidian_Export::export_url( $obsidian_scope, $selected_post, $slug );
        echo '</div><div class="ilsm-architecture-export-actions"><button type="button" class="ilsm-btn ilsm-visual-export-pdf" data-ilsm-export="pdf" data-ilsm-export-target="architecture"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> ' . esc_html__( 'Export PDF', 'dma-internlink-mapper' ) . '</button><button type="button" class="ilsm-btn ilsm-visual-export-png" data-ilsm-export="png" data-ilsm-export-target="architecture"><i class="fa fa-file-image-o" aria-hidden="true"></i> ' . esc_html__( 'Export PNG', 'dma-internlink-mapper' ) . '</button><a class="ilsm-btn ilsm-visual-export-obsidian" data-ilsm-obsidian-base="' . esc_url( $obsidian_url ) . '" data-ilsm-obsidian-scope="' . esc_attr( $obsidian_scope ) . '" href="' . esc_url( $obsidian_url ) . '"><i class="fa fa-diamond" aria-hidden="true"></i> ' . esc_html__( 'Export Obsidian', 'dma-internlink-mapper' ) . '</a></div></div><button type="button" class="ilsm-btn ilsm-btn-primary ilsm-load-architecture"><i class="fa fa-refresh"></i> ' . esc_html( 'site' === $mode ? __( 'Refresh Architecture', 'dma-internlink-mapper' ) : __( 'Apply', 'dma-internlink-mapper' ) ) . '</button></section>';
        if ( 'site' === $mode ) {
            echo '<p class="description ilsm-architecture-limit-note">' . esc_html__( 'Site Architecture displays up to 2,500 scanned pages and 25,000 internal relationships from the latest completed scan.', 'dma-internlink-mapper' ) . '</p>';
            echo '<section class="ilsm-panel ilsm-site-filters"><label>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '<select class="ilsm-architecture-status"><option value="all">' . esc_html__( 'All', 'dma-internlink-mapper' ) . '</option><option value="healthy">' . esc_html__( 'Healthy', 'dma-internlink-mapper' ) . '</option><option value="weak">' . esc_html__( 'Weak', 'dma-internlink-mapper' ) . '</option><option value="broken">' . esc_html__( 'Broken', 'dma-internlink-mapper' ) . '</option><option value="orphan">' . esc_html__( 'Orphan', 'dma-internlink-mapper' ) . '</option></select></label><label>' . esc_html__( 'Minimum incoming', 'dma-internlink-mapper' ) . '<input class="ilsm-min-in" type="number" min="0" value="0"></label><label>' . esc_html__( 'Minimum outgoing', 'dma-internlink-mapper' ) . '<input class="ilsm-min-out" type="number" min="0" value="0"></label><label>' . esc_html__( 'Search nodes', 'dma-internlink-mapper' ) . '<input class="ilsm-architecture-search" type="search" placeholder="' . esc_attr__( 'Search nodes…', 'dma-internlink-mapper' ) . '"></label></section>';
        }
        if ( 'page' === $mode ) {
            echo '<section class="ilsm-panel ilsm-page-architecture-filters" aria-label="' . esc_attr__( 'Page architecture filters', 'dma-internlink-mapper' ) . '"><div class="ilsm-architecture-filter-chips" role="group" aria-label="' . esc_attr__( 'Filter displayed pages', 'dma-internlink-mapper' ) . '"><button type="button" class="is-active" data-arch-filter="all">' . esc_html__( 'All', 'dma-internlink-mapper' ) . '</button><button type="button" data-arch-filter="orphan"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> ' . esc_html__( 'Orphan', 'dma-internlink-mapper' ) . '</button><button type="button" data-arch-filter="no-outgoing"><i class="fa fa-arrow-circle-o-right" aria-hidden="true"></i> ' . esc_html__( 'No outgoing', 'dma-internlink-mapper' ) . '</button><button type="button" data-arch-filter="deep"><i class="fa fa-level-down" aria-hidden="true"></i> ' . esc_html__( 'Deep pages', 'dma-internlink-mapper' ) . '</button><button type="button" data-arch-filter="redirect"><i class="fa fa-random" aria-hidden="true"></i> ' . esc_html__( 'Redirects', 'dma-internlink-mapper' ) . '</button><button type="button" data-arch-filter="broken"><i class="fa fa-chain-broken" aria-hidden="true"></i> ' . esc_html__( 'Broken links', 'dma-internlink-mapper' ) . '</button></div><label class="ilsm-architecture-search-wrap"><span class="screen-reader-text">' . esc_html__( 'Search pages', 'dma-internlink-mapper' ) . '</span><input class="ilsm-architecture-search" type="search" placeholder="' . esc_attr__( 'Search pages…', 'dma-internlink-mapper' ) . '"><i class="fa fa-search" aria-hidden="true"></i></label></section>';
        }
        $overview_title = 'page' === $mode ? __( 'Page Overview', 'dma-internlink-mapper' ) : __( 'Architecture Overview', 'dma-internlink-mapper' );
        echo '<div class="ilsm-architecture-summary ilsm-architecture-kpis" aria-live="polite"></div><div class="ilsm-architecture-grid"><section class="ilsm-panel ilsm-architecture-stage ilsm-premium-map-stage"><div class="ilsm-architecture-viewport-tools" role="toolbar" aria-label="' . esc_attr__( 'Architecture map navigation', 'dma-internlink-mapper' ) . '"><button type="button" class="ilsm-icon-btn ilsm-arch-zoom-in" aria-label="' . esc_attr__( 'Zoom in', 'dma-internlink-mapper' ) . '" title="' . esc_attr__( 'Zoom in', 'dma-internlink-mapper' ) . '"><i class="fa fa-plus" aria-hidden="true"></i></button><button type="button" class="ilsm-icon-btn ilsm-arch-zoom-out" aria-label="' . esc_attr__( 'Zoom out', 'dma-internlink-mapper' ) . '" title="' . esc_attr__( 'Zoom out', 'dma-internlink-mapper' ) . '"><i class="fa fa-minus" aria-hidden="true"></i></button><button type="button" class="ilsm-icon-btn ilsm-arch-fit" aria-label="' . esc_attr__( 'Fit architecture to view', 'dma-internlink-mapper' ) . '" title="' . esc_attr__( 'Fit architecture to view', 'dma-internlink-mapper' ) . '"><i class="fa fa-arrows-alt" aria-hidden="true"></i></button><button type="button" class="ilsm-icon-btn ilsm-arch-reset" aria-label="' . esc_attr__( 'Reset zoom and position', 'dma-internlink-mapper' ) . '" title="' . esc_attr__( 'Reset zoom and position', 'dma-internlink-mapper' ) . '"><i class="fa fa-crosshairs" aria-hidden="true"></i></button><output class="ilsm-arch-zoom-value" aria-live="polite">100%</output></div><div class="ilsm-architecture-loading" hidden><span class="spinner is-active"></span> ' . esc_html__( 'Building architecture from real scan data…', 'dma-internlink-mapper' ) . '</div><div class="ilsm-architecture-canvas" tabindex="0" aria-label="' . esc_attr( $title ) . '" aria-describedby="ilsm-' . esc_attr( $mode ) . '-architecture-help"></div><p id="ilsm-' . esc_attr( $mode ) . '-architecture-help" class="ilsm-architecture-tip"><i class="fa fa-info-circle"></i> ' . esc_html__( 'Use the mouse wheel or the toolbar to zoom. Drag the background to pan. Click a node to view page details.', 'dma-internlink-mapper' ) . '</p></section><aside class="ilsm-architecture-sidebar"><section class="ilsm-panel ilsm-overview-card"><div class="ilsm-side-card-head"><h2>' . esc_html( $overview_title ) . '</h2><span class="ilsm-side-card-accent" aria-hidden="true"></span></div><div class="ilsm-architecture-metrics"></div></section><section class="ilsm-panel ilsm-legend-card"><div class="ilsm-side-card-head"><h2>' . esc_html__( 'Legend', 'dma-internlink-mapper' ) . '</h2><i class="fa fa-map-o" aria-hidden="true"></i></div><ul class="ilsm-architecture-legend"><li><span class="level-0"></span>' . esc_html__( 'Root / Level 0', 'dma-internlink-mapper' ) . '</li><li><span class="level-1"></span>' . esc_html__( 'Level 1', 'dma-internlink-mapper' ) . '</li><li><span class="level-2"></span>' . esc_html__( 'Level 2', 'dma-internlink-mapper' ) . '</li><li><span class="level-3"></span>' . esc_html__( 'Level 3+', 'dma-internlink-mapper' ) . '</li><li><span class="orphan"></span>' . esc_html__( 'Orphan', 'dma-internlink-mapper' ) . '</li></ul></section><section class="ilsm-panel ilsm-selected-page-card"><div class="ilsm-side-card-head"><h2>' . esc_html__( 'Selected Page & Actions', 'dma-internlink-mapper' ) . '</h2><i class="fa fa-lightbulb-o" aria-hidden="true"></i></div><div class="ilsm-architecture-details"><p class="ilsm-muted">' . esc_html__( 'Select a node to inspect it.', 'dma-internlink-mapper' ) . '</p></div></section></aside></div>';
        echo '</div>';
    }

    public function link_report() {
        $this->header( 'Link Report', 'Review the latest completed scan and export the data safely.' );
        if ( ! ILSM_Database::latest_completed_scan_id() ) {
            $this->render_first_scan_state(
                array(
                    'eyebrow'     => __( 'Link report not built yet', 'dma-internlink-mapper' ),
                    'title'       => __( 'Build your first internal link report', 'dma-internlink-mapper' ),
                    'intro'       => __( 'Run a full local scan to discover internal links, classify anchors and destinations, and prepare reliable exportable reports.', 'dma-internlink-mapper' ),
                    'visual_icon' => 'fa-file-text-o',
                    'steps'       => array(
                        array( 'icon' => 'fa-link', 'title' => __( 'Discover internal links', 'dma-internlink-mapper' ), 'description' => __( 'Collect supported links from indexed public content.', 'dma-internlink-mapper' ) ),
                        array( 'icon' => 'fa-tags', 'title' => __( 'Classify link details', 'dma-internlink-mapper' ), 'description' => __( 'Record anchors, targets, locations, follow status and issues.', 'dma-internlink-mapper' ) ),
                        array( 'icon' => 'fa-file-text-o', 'title' => __( 'Review and export', 'dma-internlink-mapper' ), 'description' => __( 'Filter the latest scan and export CSV or PDF audit data.', 'dma-internlink-mapper' ) ),
                    ),
                )
            );
            $this->footer();
            return;
        }
        global $wpdb;
        $scan     = ILSM_Database::latest_completed_scan_id();
        $rows     = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $issue    = isset( $_GET['issue'] ) ? sanitize_key( wp_unslash( $_GET['issue'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $follow   = isset( $_GET['follow'] ) ? sanitize_key( wp_unslash( $_GET['follow'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $location = isset( $_GET['location'] ) ? sanitize_key( wp_unslash( $_GET['location'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $link_scope = isset( $_GET['link_scope'] ) ? sanitize_key( wp_unslash( $_GET['link_scope'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $paged    = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
        $settings = get_option( 'ilsm_settings', array() );
        $per_page = in_array( absint( $settings['report_per_page'] ?? 50 ), array( 25, 50, 100, 200 ), true ) ? absint( $settings['report_per_page'] ) : 50;
        $total    = 0;

        if ( $scan ) {
            $where = ' WHERE scan_id=%d';
            $args  = array( $scan );
            if ( in_array( $issue, array( 'healthy', 'broken', 'redirect', 'weak_anchor', 'empty_anchor' ), true ) ) {
                if ( 'healthy' === $issue ) { $where .= " AND issue_type=''"; }
                else { $where .= ' AND issue_type=%s'; $args[] = $issue; }
            }
            if ( in_array( $follow, array( 'follow', 'nofollow' ), true ) ) { $where .= ' AND follow_status=%s'; $args[] = $follow; }
            if ( '' !== $location ) { $where .= ' AND link_location=%s'; $args[] = $location; }
            if ( 'internal' === $link_scope ) { $where .= " AND destination_type<>'external'"; }
            elseif ( 'external' === $link_scope ) { $where .= " AND destination_type='external'"; }
            if ( '' !== $search ) { $like='%' . $wpdb->esc_like( $search ) . '%'; $where .= ' AND (source_title LIKE %s OR anchor_text LIKE %s OR target_title LIKE %s OR target_url LIKE %s)'; array_push($args,$like,$like,$like,$like); }
            $table = ILSM_Database::table( 'links' );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated. The placeholder list is completed by the validated argument array.
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where}", $args ) );
            $query_args = array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated. The dynamic placeholder list matches the validated argument array.
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $query_args ) );
        }

        // Load the latest completed rendered SEO snapshot for all source pages on this
        // report page in one query. This deliberately avoids one query (or one live
        // frontend request) per link row.
        $source_seo = array();
        if ( $scan && $rows ) {
            $source_ids = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'source_post_id' ) ) ) ) );
            if ( $source_ids ) {
                $pages_table = ILSM_Database::table( 'pages' );
                $placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
                $seo_args = array_merge( array( absint( $scan ) ), $source_ids );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is generated by the strict internal allowlist. Placeholder count is generated from validated integer post IDs. Mutable scan data must be read fresh.
                $seo_rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id,seo_score,seo_verified FROM {$pages_table} WHERE scan_id=%d AND post_id IN ({$placeholders})", $seo_args ) );
                foreach ( (array) $seo_rows as $seo_row ) {
                    $source_seo[ absint( $seo_row->post_id ) ] = array(
                        'score'    => min( 100, absint( $seo_row->seo_score ) ),
                        'verified' => 1 === absint( $seo_row->seo_verified ),
                    );
                }
            }
        }

        echo '<form method="get" class="ilsm-report-filters"><input type="hidden" name="page" value="ilsm-link-report"><label><span>' . esc_html__( 'Search', 'dma-internlink-mapper' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Source, anchor or target', 'dma-internlink-mapper' ) . '"></label><label><span>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '</span><select name="issue"><option value="">' . esc_html__( 'All statuses', 'dma-internlink-mapper' ) . '</option><option value="healthy" ' . selected( $issue, 'healthy', false ) . '>' . esc_html__( 'Healthy', 'dma-internlink-mapper' ) . '</option><option value="broken" ' . selected( $issue, 'broken', false ) . '>' . esc_html__( 'Broken', 'dma-internlink-mapper' ) . '</option><option value="redirect" ' . selected( $issue, 'redirect', false ) . '>' . esc_html__( 'Redirect', 'dma-internlink-mapper' ) . '</option><option value="weak_anchor" ' . selected( $issue, 'weak_anchor', false ) . '>' . esc_html__( 'Weak anchor', 'dma-internlink-mapper' ) . '</option><option value="empty_anchor" ' . selected( $issue, 'empty_anchor', false ) . '>' . esc_html__( 'Empty anchor', 'dma-internlink-mapper' ) . '</option></select></label><label><span>' . esc_html__( 'Link type', 'dma-internlink-mapper' ) . '</span><select name="link_scope"><option value="">' . esc_html__( 'All links', 'dma-internlink-mapper' ) . '</option><option value="internal" ' . selected( $link_scope, 'internal', false ) . '>' . esc_html__( 'Internal', 'dma-internlink-mapper' ) . '</option><option value="external" ' . selected( $link_scope, 'external', false ) . '>' . esc_html__( 'External', 'dma-internlink-mapper' ) . '</option></select></label><label><span>' . esc_html__( 'Follow', 'dma-internlink-mapper' ) . '</span><select name="follow"><option value="">' . esc_html__( 'All', 'dma-internlink-mapper' ) . '</option><option value="follow" ' . selected( $follow, 'follow', false ) . '>' . esc_html__( 'Follow', 'dma-internlink-mapper' ) . '</option><option value="nofollow" ' . selected( $follow, 'nofollow', false ) . '>' . esc_html__( 'Nofollow', 'dma-internlink-mapper' ) . '</option></select></label><label><span>' . esc_html__( 'Location', 'dma-internlink-mapper' ) . '</span><input type="text" name="location" value="' . esc_attr( $location ) . '" placeholder="' . esc_attr__( 'content', 'dma-internlink-mapper' ) . '"></label><button class="ilsm-btn ilsm-btn-primary"><i class="fa fa-filter"></i> ' . esc_html__( 'Apply Filters', 'dma-internlink-mapper' ) . '</button><a class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=ilsm-link-report' ) ) . '">' . esc_html__( 'Reset', 'dma-internlink-mapper' ) . '</a></form>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div class="ilsm-list-toolbar"><div class="ilsm-result-count"><strong>' . number_format_i18n( $total ) . '</strong> ' . esc_html__( 'links', 'dma-internlink-mapper' ) . '</div><input type="search" class="ilsm-table-search" placeholder="' . esc_attr__( 'Filter this page…', 'dma-internlink-mapper' ) . '"><div class="ilsm-export-actions"><a class="ilsm-btn" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ilsm_export_csv' ), 'ilsm_export' ) ) . '"><i class="fa fa-file-text-o"></i> ' . esc_html__( 'Export CSV', 'dma-internlink-mapper' ) . '</a><a class="ilsm-btn ilsm-btn-pdf" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ilsm_export_pdf' ), 'ilsm_export_pdf' ) ) . '"><i class="fa fa-file-pdf-o"></i> ' . esc_html__( 'Export PDF Audit', 'dma-internlink-mapper' ) . '</a></div></div>';
        echo '<section class="ilsm-panel ilsm-table-panel ilsm-orphan-table-panel"><div class="ilsm-table-scroll ilsm-orphan-table-scroll"><table class="ilsm-table"><thead><tr><th>' . esc_html__( 'Source', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Source Page SEO', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Anchor text', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Target', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Type', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Location', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Follow', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $source_post_id = absint( $r->source_post_id );
            $seo_snapshot = $source_post_id && isset( $source_seo[ $source_post_id ] ) ? $source_seo[ $source_post_id ] : null;
            if ( $source_post_id && $seo_snapshot && ! empty( $seo_snapshot['verified'] ) ) {
                $score = min( 100, absint( $seo_snapshot['score'] ) );
                $score_tone = $score >= 90 ? 'excellent' : ( $score >= 70 ? 'good' : ( $score >= 50 ? 'warning' : 'poor' ) );
                /* translators: 1: SEO score from 0 to 100, 2: source page title. */
                $seo_aria_label = sprintf( __( 'Source page SEO score: %1$d out of 100 for %2$s. View breakdown.', 'dma-internlink-mapper' ), $score, $this->display_text( $r->source_title ) );
                $seo_markup = '<button type="button" class="ilsm-score-gauge ilsm-score-gauge--' . esc_attr( $score_tone ) . ' ilsm-seo-score-trigger" style="--ilsm-score:' . esc_attr( $score ) . '%" data-post-id="' . esc_attr( $source_post_id ) . '" aria-label="' . esc_attr( $seo_aria_label ) . '"><span class="ilsm-score-gauge__inner"><strong>' . esc_html( $score ) . '</strong><small>/100</small></span></button>';
            } elseif ( $source_post_id ) {
                /* translators: %s: source page title. */
                $seo_aria_label = sprintf( __( 'Source page SEO has not been verified for %s. Analyze and view breakdown.', 'dma-internlink-mapper' ), $this->display_text( $r->source_title ) );
                $seo_markup = '<button type="button" class="ilsm-score-gauge ilsm-score-gauge--unverified ilsm-seo-score-trigger" style="--ilsm-score:0%" data-post-id="' . esc_attr( $source_post_id ) . '" aria-label="' . esc_attr( $seo_aria_label ) . '"><span class="ilsm-score-gauge__inner"><strong>—</strong><small>' . esc_html__( 'SEO', 'dma-internlink-mapper' ) . '</small></span></button>';
            } else {
                $seo_markup = '<span class="ilsm-muted">' . esc_html__( 'N/A', 'dma-internlink-mapper' ) . '</span>';
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SEO button markup is composed from escaped plugin-owned values; status_badge returns escaped plugin-owned markup.
            echo '<tr><td><a href="' . esc_url( get_edit_post_link( $source_post_id ) ?: '#' ) . '">' . esc_html( $this->display_text( $r->source_title ) ) . '</a></td><td>' . $seo_markup . '</td><td>' . esc_html( $this->display_text( $r->anchor_text ?: '—' ) ) . '</td><td>' . esc_html( $this->display_text( $r->target_title ?: $r->target_url ) ) . '</td><td><span class="ilsm-badge ' . ( 'external' === (string) $r->destination_type ? 'is-warning' : 'is-success' ) . '">' . esc_html( 'external' === (string) $r->destination_type ? __( 'External', 'dma-internlink-mapper' ) : __( 'Internal', 'dma-internlink-mapper' ) ) . '</span></td><td><span class="ilsm-badge is-neutral">' . esc_html( $this->display_text( $r->link_location ) ) . '</span></td><td>' . esc_html( $this->display_text( $r->follow_status ) ) . '</td><td>' . $this->status_badge( $r->issue_type ) . '</td></tr>';
        }
        if ( ! $rows ) { echo '<tr><td colspan="8" class="ilsm-empty-cell">' . esc_html__( 'No completed scan data yet.', 'dma-internlink-mapper' ) . '</td></tr>'; }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '</tbody></table></div>' . $this->pagination( $paged, $total, $per_page ) . '</section>';
        // Every report row with a WordPress source renders an SEO trigger, so the
        // matching dialog must always be present on this screen. Previously this
        // was guarded by an undefined orphan-report variable, leaving apparently
        // clickable gauges with no dialog for the delegated JavaScript handler.
        echo '<div id="ilsm-seo-analysis-modal" class="ilsm-seo-modal" hidden aria-hidden="true"><div class="ilsm-seo-modal-backdrop" data-ilsm-seo-close></div><section class="ilsm-seo-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ilsm-seo-modal-title"><button type="button" class="ilsm-seo-modal-close" data-ilsm-seo-close aria-label="' . esc_attr__( 'Close SEO analysis', 'dma-internlink-mapper' ) . '"><i class="fa fa-times" aria-hidden="true"></i></button><div id="ilsm-seo-modal-content"><div class="ilsm-seo-loading"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> ' . esc_html__( 'Analyzing rendered public HTML…', 'dma-internlink-mapper' ) . '</div></div></section></div>';
        $this->footer();
    }

    public function seo_issues() { $this->issues_page( 'SEO Issues', array() ); }
    public function anchor_analysis() { $this->issues_page( 'Anchor Analysis', array( 'weak_anchor', 'empty_anchor' ) ); }

    public function on_page_seo() {
        $this->header( __( 'On-Page SEO', 'dma-internlink-mapper' ), __( 'Audit rendered page signals for Google Search, focus-keyphrase relevance, and crawler access.', 'dma-internlink-mapper' ) );
        ILSM_On_Page_Audit::render();
        $this->footer();
    }

    /**
     * Read an object property defensively. Old scan rows can survive plugin upgrades,
     * so report rendering must never assume that every column is present.
     */
    private function row_value( $row, $property, $default = '' ) {
        return is_object( $row ) && isset( $row->{$property} ) ? $row->{$property} : $default;
    }

    /**
     * Return a clean database error without exposing SQL or server paths.
     */
    private function database_error_message( $fallback ) {
        global $wpdb;
        if ( ! empty( $wpdb->last_error ) ) {
            return $fallback . ' Run Health Audit, repair the schema, and retry.';
        }
        return $fallback;
    }

    /**
     * Render orphan pages directly from the indexed pages table.
     * This avoids loading or rebuilding the complete issue list in memory.
     */
    /**
     * Return a fresh, read-only SEO explanation for one reportable page.
     */
    public function ajax_page_seo_analysis() {
        if ( ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_admin', 'nonce' );

        $post_id = absint( isset( $_POST['post_id'] ) ? wp_unslash( $_POST['post_id'] ) : 0 );
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || ! ILSM_SEO_Inspector::is_reportable( $post ) ) {
            wp_send_json_error( array( 'message' => __( 'This page is not available for SEO analysis.', 'dma-internlink-mapper' ) ), 404 );
        }

        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        $metrics = array( 'incoming' => 0, 'outgoing' => 0, 'broken' => 0, 'weak' => 0 );
        if ( $scan ) {
            $table = ILSM_Database::table( 'pages' );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated by the strict internal allowlist; mutable scan data must be read fresh.
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT incoming_count,outgoing_count,broken_count,weak_anchor_count FROM {$table} WHERE scan_id=%d AND post_id=%d LIMIT 1", absint( $scan ), $post_id ), ARRAY_A );
            if ( is_array( $row ) ) {
                $metrics = array(
                    'incoming' => absint( $row['incoming_count'] ?? 0 ),
                    'outgoing' => absint( $row['outgoing_count'] ?? 0 ),
                    'broken' => absint( $row['broken_count'] ?? 0 ),
                    'weak' => absint( $row['weak_anchor_count'] ?? 0 ),
                );
            }
            $links_table = ILSM_Database::checked_table( ILSM_Database::table( 'links' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned scan metrics must be read fresh; persistent object caching could return stale report counts.
            $external = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) total,SUM(follow_status='nofollow') nofollow,SUM(issue_type='broken') broken,SUM(issue_type='redirect') redirects FROM %i WHERE scan_id=%d AND source_post_id=%d AND destination_type=%s",
                    $links_table,
                    absint( $scan ),
                    $post_id,
                    'external'
                ),
                ARRAY_A
            );
            $metrics['external_total'] = absint( $external['total'] ?? 0 );
            $metrics['external_nofollow'] = absint( $external['nofollow'] ?? 0 );
            $metrics['external_broken'] = absint( $external['broken'] ?? 0 );
            $metrics['external_redirects'] = absint( $external['redirects'] ?? 0 );
        }

        $analysis = $scan ? get_transient( 'ilsm_seo_breakdown_' . absint( $scan ) . '_' . $post_id ) : false;
        if ( is_array( $analysis ) && ! empty( $analysis['verified'] ) ) {
            $analysis['internal_links'] = array(
                'incoming' => absint( $metrics['incoming'] ),
                'outgoing' => absint( $metrics['outgoing'] ),
                'broken'   => absint( $metrics['broken'] ),
                'weak'     => absint( $metrics['weak'] ),
                'orphan'   => 0 === absint( $metrics['incoming'] ),
            );
            $analysis['external_links'] = array(
                'total'     => absint( $metrics['external_total'] ?? 0 ),
                'nofollow'  => absint( $metrics['external_nofollow'] ?? 0 ),
                'broken'    => absint( $metrics['external_broken'] ?? 0 ),
                'redirects' => absint( $metrics['external_redirects'] ?? 0 ),
            );
        } else {
            /*
             * Never start a same-site frontend HTTP request from this admin
             * AJAX worker. On shared hosting, the request can wait for the
             * current PHP worker and leave the modal loading indefinitely.
             * The completed scan is the source of truth for this report.
             */
            $analysis = ILSM_Page_SEO_Analyzer::analyze(
                $post_id,
                $metrics,
                array(
                    'ok'       => false,
                    'verified' => false,
                    'error'    => __( 'The saved rendered-HTML breakdown is unavailable. Run a fresh scan to rebuild it.', 'dma-internlink-mapper' ),
                )
            );
        }
        if ( empty( $analysis ) ) {
            wp_send_json_error( array( 'message' => __( 'The SEO analysis could not be generated.', 'dma-internlink-mapper' ) ), 500 );
        }
        wp_send_json_success( $analysis );
    }


    /**
     * Return post IDs intentionally hidden from the Orphan Pages report.
     *
     * This is a report preference only. It does not alter post content, scan
     * metrics, indexability, or the underlying orphan assertion.
     *
     * @return int[]
     */
    private function ignored_orphan_ids() {
        $ids = get_option( 'ilsm_ignored_orphan_post_ids', array() );
        if ( ! is_array( $ids ) ) {
            return array();
        }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        return $ids;
    }

    /**
     * Persist an Ignore/Restore decision for the Orphan Pages report.
     */
    public function orphan_ignore() {
        if ( ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'dma-internlink-mapper' ) );
        }

        $post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
        $mode    = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'ignore';
        if ( ! $post_id || ! in_array( $mode, array( 'ignore', 'restore' ), true ) ) {
            wp_die( esc_html__( 'Invalid orphan-page action.', 'dma-internlink-mapper' ) );
        }

        check_admin_referer( 'ilsm_orphan_' . $mode . '_' . $post_id );

        $ids = $this->ignored_orphan_ids();
        if ( 'ignore' === $mode ) {
            $page_row = $this->page_row( $post_id );
            if ( empty( $page_row ) || 1 !== absint( $page_row['is_orphan'] ?? 0 ) ) {
                wp_die( esc_html__( 'This page is not an orphan in the latest completed scan.', 'dma-internlink-mapper' ) );
            }
            $ids[] = $post_id;
            $ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
            $notice = 'ignored';
        } else {
            $ids    = array_values( array_diff( $ids, array( $post_id ) ) );
            $notice = 'restored';
        }

        if ( false === get_option( 'ilsm_ignored_orphan_post_ids', false ) ) {
            add_option( 'ilsm_ignored_orphan_post_ids', $ids, '', false );
        } else {
            update_option( 'ilsm_ignored_orphan_post_ids', $ids, false );
        }

        $fallback = admin_url( 'admin.php?page=ilsm-orphans' );
        $referer  = wp_get_referer();
        $redirect = $referer ? wp_validate_redirect( $referer, $fallback ) : $fallback;
        $redirect = remove_query_arg( array( 'ilsm_orphan_notice' ), $redirect );
        wp_safe_redirect( add_query_arg( 'ilsm_orphan_notice', $notice, $redirect ) );
        exit;
    }

    public function orphans() {
        $this->header( 'Orphan Pages', 'Indexed pages with no discovered incoming internal links.' );
        if ( ! ILSM_Database::latest_completed_scan_id() ) {
            $this->render_first_scan_state(
                array(
                    'eyebrow'     => __( 'Orphan index not built yet', 'dma-internlink-mapper' ),
                    'title'       => __( 'Find pages with no incoming internal links', 'dma-internlink-mapper' ),
                    'intro'       => __( 'Run a full local scan to compare indexed pages with discovered internal links and identify possible orphan content.', 'dma-internlink-mapper' ),
                    'visual_icon' => 'fa-unlink',
                    'steps'       => array(
                        array( 'icon' => 'fa-files-o', 'title' => __( 'Index public pages', 'dma-internlink-mapper' ), 'description' => __( 'Collect supported posts, pages and enabled public content types.', 'dma-internlink-mapper' ) ),
                        array( 'icon' => 'fa-arrow-down', 'title' => __( 'Count incoming links', 'dma-internlink-mapper' ), 'description' => __( 'Match discovered internal links to each indexed destination.', 'dma-internlink-mapper' ) ),
                        array( 'icon' => 'fa-user-o', 'title' => __( 'Review possible orphans', 'dma-internlink-mapper' ), 'description' => __( 'Inspect pages with no discovered incoming links before taking action.', 'dma-internlink-mapper' ) ),
                    ),
                )
            );
            $this->footer();
            return;
        }
        global $wpdb;
        $latest_scan_id = ILSM_Database::latest_completed_scan_id();
        $unverified_render_count = 0;
        if ( $latest_scan_id ) {
            $pages_table_for_notice = ILSM_Database::table( 'pages' );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table from strict allowlist; fresh crawl verification state.
            $unverified_render_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pages_table_for_notice} WHERE scan_id=%d AND render_verified=0", $latest_scan_id ) );
        }
        if ( $unverified_render_count > 0 ) {
            /* translators: %d: number of pages that could not be verified from public rendered HTML. */
            $render_warning = sprintf( __( '%d page(s) could not be verified from public rendered HTML. Orphan assertions are intentionally withheld for this scan rather than treating missing crawl data as zero links.', 'dma-internlink-mapper' ), $unverified_render_count );
            echo '<div class="notice notice-warning inline ilsm-render-warning"><p><strong>' . esc_html__( 'Rendered crawl incomplete.', 'dma-internlink-mapper' ) . '</strong> ' . esc_html( $render_warning ) . '</p></div>';
        }


        $rows = array();
        $total = 0;
        $error = '';
        $scan = ILSM_Database::latest_completed_scan_id();
        $ignored_ids = $this->ignored_orphan_ids();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $orphan_view = isset( $_GET['orphan_view'] ) && 'ignored' === sanitize_key( wp_unslash( $_GET['orphan_view'] ) ) ? 'ignored' : 'active';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $paged = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
        $settings = get_option( 'ilsm_settings', array() );
        $allowed = array( 25, 50, 100, 200 );
        $per_page = in_array( absint( $settings['report_per_page'] ?? 50 ), $allowed, true ) ? absint( $settings['report_per_page'] ) : 50;

        try {
            if ( $scan ) {
                $table = ILSM_Database::table( 'pages' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                    throw new RuntimeException( 'The indexed-pages table is missing. Run Health Audit and repair the database.' );
                }
                $where = ' WHERE scan_id=%d AND is_orphan=1';
                $args = array( $scan );
                if ( 'ignored' === $orphan_view ) {
                    if ( empty( $ignored_ids ) ) {
                        $where .= ' AND 1=0';
                    } else {
                        $placeholders = implode( ',', array_fill( 0, count( $ignored_ids ), '%d' ) );
                        $where .= " AND post_id IN ({$placeholders})";
                        $args = array_merge( $args, $ignored_ids );
                    }
                } elseif ( ! empty( $ignored_ids ) ) {
                    $placeholders = implode( ',', array_fill( 0, count( $ignored_ids ), '%d' ) );
                    $where .= " AND post_id NOT IN ({$placeholders})";
                    $args = array_merge( $args, $ignored_ids );
                }
                if ( '' !== $post_type ) { $where .= ' AND post_type=%s'; $args[] = $post_type; }
                if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where .= ' AND (title LIKE %s OR url LIKE %s)'; $args[] = $like; $args[] = $like; }
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated. The placeholder list is completed by the validated argument array.
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where}", $args ) );
                $query_args = array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated. The dynamic placeholder list matches the validated argument array.
                $result = $wpdb->get_results( $wpdb->prepare( "SELECT post_id,title,url,post_type,incoming_count,outgoing_count,seo_score,seo_verified FROM {$table}{$where} ORDER BY title ASC LIMIT %d OFFSET %d", $query_args ) );
                if ( ! empty( $wpdb->last_error ) ) {
                    throw new RuntimeException( $this->database_error_message( 'The orphan-page query could not be completed.' ) );
                }
                $rows = is_array( $result ) ? $result : array();
            }
        } catch ( Throwable $e ) {
            $error = $e->getMessage();
            $this->log_error( 'orphans', $e );
            $rows = array();
            $total = 0;
        }

        if ( $error ) {
            echo '<div class="ilsm-error-card"><i class="fa fa-exclamation-triangle"></i><div><h2>' . esc_html__( 'Orphan Pages could not be loaded', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html( $this->display_text( $error ) ) . '</p><a class="ilsm-btn ilsm-btn-primary" href="' . esc_url( admin_url( 'admin.php?page=ilsm-health-audit' ) ) . '"><i class="fa fa-heartbeat" aria-hidden="true"></i> ' . esc_html__( 'Open Health Audit', 'dma-internlink-mapper' ) . '</a></div></div>';
            $this->footer();
            return;
        }

        $ignored_count = 0;
        if ( $scan && ! empty( $ignored_ids ) ) {
            $table = ILSM_Database::table( 'pages' );
            $placeholders = implode( ',', array_fill( 0, count( $ignored_ids ), '%d' ) );
            $ignored_args = array_merge( array( $scan ), $ignored_ids );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Plugin-owned allowlisted table and a placeholder list generated only from absint post IDs.
            $ignored_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id=%d AND is_orphan=1 AND post_id IN ({$placeholders})", $ignored_args ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameter set only after a nonce-protected admin action.
        $orphan_notice = isset( $_GET['ilsm_orphan_notice'] ) ? sanitize_key( wp_unslash( $_GET['ilsm_orphan_notice'] ) ) : '';
        if ( 'ignored' === $orphan_notice ) {
            echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__( 'Page ignored. It is hidden from the active Orphan Pages report, while the underlying scan data remains unchanged.', 'dma-internlink-mapper' ) . '</p></div>';
        } elseif ( 'restored' === $orphan_notice ) {
            echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__( 'Page restored to the active Orphan Pages report.', 'dma-internlink-mapper' ) . '</p></div>';
        }

        $active_url  = admin_url( 'admin.php?page=ilsm-orphans' );
        $ignored_url = add_query_arg( 'orphan_view', 'ignored', $active_url );
        echo '<nav class="ilsm-orphan-views" aria-label="' . esc_attr__( 'Orphan report views', 'dma-internlink-mapper' ) . '">';
        echo '<a class="' . ( 'active' === $orphan_view ? 'is-active' : '' ) . '" href="' . esc_url( $active_url ) . '">' . esc_html__( 'Orphan pages', 'dma-internlink-mapper' ) . '</a>';
        echo '<a class="' . ( 'ignored' === $orphan_view ? 'is-active' : '' ) . '" href="' . esc_url( $ignored_url ) . '">' . esc_html__( 'Ignored', 'dma-internlink-mapper' ) . ' <span>' . esc_html( number_format_i18n( $ignored_count ) ) . '</span></a>';
        echo '</nav>';

        $orphan_types = get_post_types( array( 'public' => true ), 'objects' );
        echo '<form method="get" class="ilsm-report-filters"><input type="hidden" name="page" value="ilsm-orphans"><input type="hidden" name="orphan_view" value="' . esc_attr( $orphan_view ) . '"><label><span>' . esc_html__( 'Search', 'dma-internlink-mapper' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Page title or URL', 'dma-internlink-mapper' ) . '"></label><label><span>' . esc_html__( 'Post type', 'dma-internlink-mapper' ) . '</span><select name="post_type"><option value="">' . esc_html__( 'All post types', 'dma-internlink-mapper' ) . '</option>';
        foreach ( $orphan_types as $orphan_type ) {
            if ( ! ILSM_SEO_Inspector::is_supported_post_type( $orphan_type->name ) ) { continue; }
            echo '<option value="' . esc_attr( $orphan_type->name ) . '" ' . selected( $post_type, $orphan_type->name, false ) . '>' . esc_html( $orphan_type->labels->singular_name ) . '</option>';
        }
        $reset_url = 'ignored' === $orphan_view ? add_query_arg( 'orphan_view', 'ignored', admin_url( 'admin.php?page=ilsm-orphans' ) ) : admin_url( 'admin.php?page=ilsm-orphans' );
        echo '</select></label><button class="ilsm-btn ilsm-btn-primary"><i class="fa fa-filter"></i> ' . esc_html__( 'Apply Filters', 'dma-internlink-mapper' ) . '</button><a class="ilsm-btn" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset', 'dma-internlink-mapper' ) . '</a></form>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div class="ilsm-list-toolbar"><div class="ilsm-result-count"><strong>' . number_format_i18n( $total ) . '</strong> ' . esc_html( 'ignored' === $orphan_view ? __( 'ignored orphan pages', 'dma-internlink-mapper' ) : __( 'orphan pages', 'dma-internlink-mapper' ) ) . '</div><input type="search" class="ilsm-table-search" placeholder="' . esc_attr__( 'Filter this page…', 'dma-internlink-mapper' ) . '"></div>';
        $show_seo_score = 'ignored' !== $orphan_view;
        echo '<section class="ilsm-panel ilsm-table-panel ilsm-orphan-table-panel"><div class="ilsm-table-scroll ilsm-orphan-table-scroll"><table class="ilsm-table"><thead><tr><th>' . esc_html__( 'Page', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Post type', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Incoming', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Outgoing', 'dma-internlink-mapper' ) . '</th>' . ( $show_seo_score ? '<th>' . esc_html__( 'SEO score', 'dma-internlink-mapper' ) . '</th>' : '' ) . '<th>' . esc_html__( 'Actions', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        $rendered = 0;
        foreach ( $rows as $r ) {
            try {
                $post_id  = absint( $this->row_value( $r, 'post_id', 0 ) );
                $title    = $this->display_text( $this->row_value( $r, 'title', $post_id ? '#' . $post_id : 'Untitled page' ) );
                $url      = esc_url( $this->row_value( $r, 'url', '' ) );
                $type     = sanitize_key( $this->row_value( $r, 'post_type', 'unknown' ) );
                $incoming = absint( $this->row_value( $r, 'incoming_count', 0 ) );
                $outgoing = absint( $this->row_value( $r, 'outgoing_count', 0 ) );
                $score    = min( 100, absint( $this->row_value( $r, 'seo_score', 0 ) ) );
                $seo_verified = 1 === absint( $this->row_value( $r, 'seo_verified', 0 ) );
                $edit     = $post_id ? get_edit_post_link( $post_id ) : false;
                $view     = $post_id ? get_permalink( $post_id ) : false;

                $score_markup = $seo_verified ? esc_html( $score ) . '<span>/100</span>' : '<span class="ilsm-score-unverified">' . esc_html__( 'Analyze', 'dma-internlink-mapper' ) . '</span>';
                /* translators: %s: page title. */
                $seo_aria_label = sprintf( __( 'Analyze rendered SEO for %s', 'dma-internlink-mapper' ), $title );
                $orphan_action_url = '';
                if ( $post_id ) {
                    $orphan_mode = 'ignored' === $orphan_view ? 'restore' : 'ignore';
                    $orphan_action_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action'  => 'ilsm_orphan_ignore',
                                'post_id' => $post_id,
                                'mode'    => $orphan_mode,
                            ),
                            admin_url( 'admin-post.php' )
                        ),
                        'ilsm_orphan_' . $orphan_mode . '_' . $post_id
                    );
                }
                $orphan_action_label = 'ignored' === $orphan_view ? __( 'Restore', 'dma-internlink-mapper' ) : __( 'Ignore', 'dma-internlink-mapper' );
                $orphan_action_icon  = 'ignored' === $orphan_view ? 'fa-undo' : 'fa-eye-slash';
                echo '<tr><td><strong>' . esc_html( $title ) . '</strong>' . ( $url ? '<small class="ilsm-row-url">' . esc_html( $url ) . '</small>' : '' ) . '</td><td><span class="ilsm-badge is-neutral">' . esc_html( $type ) . '</span></td><td>' . esc_html( $incoming ) . '</td><td>' . esc_html( $outgoing ) . '</td>' . ( $show_seo_score ? '<td><button type="button" class="ilsm-score-pill ilsm-seo-score-trigger" data-post-id="' . esc_attr( $post_id ) . '" aria-label="' . esc_attr( $seo_aria_label ) . '">' . wp_kses_post( $score_markup ) . '<i class="fa fa-search-plus" aria-hidden="true"></i></button></td>' : '' ) . '<td><div class="ilsm-row-actions">' . ( $edit ? '<a href="' . esc_url( $edit ) . '"><i class="fa fa-pencil" aria-hidden="true"></i> ' . esc_html__( 'Edit', 'dma-internlink-mapper' ) . '</a>' : '' ) . ( $view ? '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener"><i class="fa fa-external-link" aria-hidden="true"></i> ' . esc_html__( 'View', 'dma-internlink-mapper' ) . '</a>' : '' ) . ( $post_id ? '<a href="' . esc_url( admin_url( 'admin.php?page=ilsm-visual-map&post_id=' . $post_id ) ) . '"><i class="fa fa-sitemap" aria-hidden="true"></i> ' . esc_html__( 'Map', 'dma-internlink-mapper' ) . '</a>' : '' ) . ( $orphan_action_url ? '<a class="ilsm-orphan-ignore-action" href="' . esc_url( $orphan_action_url ) . '"><i class="fa ' . esc_attr( $orphan_action_icon ) . '" aria-hidden="true"></i> ' . esc_html( $orphan_action_label ) . '</a>' : '' ) . '</div></td></tr>';
                $rendered++;
            } catch ( Throwable $row_error ) {
                $this->log_error( 'orphan-row', $row_error );
                continue;
            }
        }
        if ( 0 === $rendered ) {
            $empty_text = $total
                ? __( 'The orphan index contains records, but none could be rendered safely. Open Health Audit and repair the schema.', 'dma-internlink-mapper' )
                : ( 'ignored' === $orphan_view
                    ? __( 'No ignored orphan pages are currently present in the latest completed scan.', 'dma-internlink-mapper' )
                    : __( 'No orphan pages were found in the latest completed scan.', 'dma-internlink-mapper' ) );
            echo '<tr><td colspan="' . esc_attr( $show_seo_score ? 6 : 5 ) . '" class="ilsm-empty-cell">' . esc_html( $empty_text ) . '</td></tr>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '</tbody></table></div>' . $this->pagination( $paged, $total, $per_page ) . '</section>';
        if ( $show_seo_score ) {
            echo '<div id="ilsm-seo-analysis-modal" class="ilsm-seo-modal" hidden aria-hidden="true"><div class="ilsm-seo-modal-backdrop" data-ilsm-seo-close></div><section class="ilsm-seo-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ilsm-seo-modal-title"><button type="button" class="ilsm-seo-modal-close" data-ilsm-seo-close aria-label="' . esc_attr__( 'Close SEO analysis', 'dma-internlink-mapper' ) . '"><i class="fa fa-times" aria-hidden="true"></i></button><div id="ilsm-seo-modal-content"><div class="ilsm-seo-loading"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> ' . esc_html__( 'Analyzing rendered public HTML…', 'dma-internlink-mapper' ) . '</div></div></section></div>';
        }
        $this->footer();
    }

    public function repair_schema() {
        if ( ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'ilsm_manage_settings' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'dma-internlink-mapper' ) );
        }
        check_admin_referer( 'ilsm_repair_schema' );
        ILSM_Activator::activate();
        delete_transient( 'ilsm_health_snapshot' );
        wp_safe_redirect( add_query_arg( 'repaired', '1', admin_url( 'admin.php?page=ilsm-health-audit' ) ) );
        exit;
    }

    public function health_audit() {
        $this->header( 'Health Audit', 'Check database integrity, scan data, permissions, and hosting limits without crashing the admin.' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        if ( ! empty( $_GET['repaired'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Database schema repair completed. Run a fresh scan if report counts still use older data.', 'dma-internlink-mapper' ) . '</p></div>'; }
        global $wpdb;
        $checks = array();
        $tables = array( 'scans', 'pages', 'links', 'issues', 'keywords', 'phrases', 'feedback', 'opportunities', 'external_actions' );
        foreach ( $tables as $name ) {
            $table = ILSM_Database::table( $name );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
            $checks[] = array( $exists, 'Database table: ' . $table, $exists ? 'Available' : 'Missing. Deactivate and reactivate the plugin to repair tables.' );
        }
        $scan = ILSM_Database::latest_completed_scan_id();
        $checks[] = array( (bool) $scan, 'Completed scan', $scan ? 'Latest completed scan #' . absint( $scan ) : 'No completed scan is available.' );
        $checks[] = array( current_user_can( 'ilsm_view_reports' ), 'Report capability', current_user_can( 'ilsm_view_reports' ) ? 'Current user can view reports.' : 'Capability is missing.' );
        $checks[] = array( function_exists( 'mb_strtolower' ), 'Multibyte text support', function_exists( 'mb_strtolower' ) ? 'mbstring is available.' : 'mbstring is unavailable; basic UTF-8 fallbacks will be used.' );
        $memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $checks[] = array( -1 === $memory || $memory >= 134217728, 'PHP memory limit', -1 === $memory ? 'Unlimited' : size_format( $memory ) );
        $checks[] = array( version_compare( PHP_VERSION, '7.4', '>=' ), 'PHP version', PHP_VERSION );
        $checks[] = array( empty( $wpdb->last_error ), 'Last database error', empty( $wpdb->last_error ) ? 'None detected during this audit.' : $wpdb->last_error );

        echo '<div class="ilsm-health-grid">';
        foreach ( $checks as $check ) {
            echo '<article class="ilsm-health-card ' . ( $check[0] ? 'is-ok' : 'is-bad' ) . '"><span class="ilsm-health-icon"><i class="fa ' . ( $check[0] ? 'fa-check' : 'fa-exclamation-triangle' ) . '"></i></span><div><h2>' . esc_html( $check[1] ) . '</h2><p>' . esc_html( $this->display_text( $check[2] ) ) . '</p></div></article>';
        }
        echo '</div>';
        echo '<section class="ilsm-panel"><div class="ilsm-settings-head"><i class="fa fa-medkit"></i><div><h2>' . esc_html__( 'Safe recovery', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Database schema upgrades run automatically for administrators. To force a repair, deactivate and reactivate the plugin, then run a new scan.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-health-actions"><a class="ilsm-btn ilsm-btn-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ilsm_repair_schema' ), 'ilsm_repair_schema' ) ) . '"><i class="fa fa-wrench" aria-hidden="true"></i> ' . esc_html__( 'Repair Database Schema', 'dma-internlink-mapper' ) . '</a><a class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '"><i class="fa fa-refresh" aria-hidden="true"></i> ' . esc_html__( 'Return to Scanner', 'dma-internlink-mapper' ) . '</a></div></section>';
        $this->footer();
    }

    private function log_error( $context, Throwable $error ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            do_action( 'ilsm_debug_error', sanitize_key( $context ), $error );
        }
    }

    private function issues_page( $title, $types ) {
        $is_anchor = 'Anchor Analysis' === $title;
        $subtitle  = $is_anchor
            ? __( 'Review weak, empty and generic anchor text from the latest completed scan.', 'dma-internlink-mapper' )
            : __( 'Review supported technical SEO issues from the latest completed scan.', 'dma-internlink-mapper' );
        $this->header( $title, $subtitle );

        if ( ! ILSM_Database::latest_completed_scan_id() ) {
            $this->render_first_scan_state(
                $is_anchor
                    ? array(
                        'eyebrow'     => __( 'Anchor index not built yet', 'dma-internlink-mapper' ),
                        'title'       => __( 'Analyze your internal link anchors', 'dma-internlink-mapper' ),
                        'intro'       => __( 'Run a full local scan to collect anchor text and identify empty, generic, repeated or potentially weak internal-link anchors.', 'dma-internlink-mapper' ),
                        'visual_icon' => 'fa-font',
                        'steps'       => array(
                            array( 'icon' => 'fa-link', 'title' => __( 'Collect anchor text', 'dma-internlink-mapper' ), 'description' => __( 'Index anchors from supported internal links across scanned content.', 'dma-internlink-mapper' ) ),
                            array( 'icon' => 'fa-search', 'title' => __( 'Detect weak patterns', 'dma-internlink-mapper' ), 'description' => __( 'Identify empty, generic and potentially repetitive anchor usage.', 'dma-internlink-mapper' ) ),
                            array( 'icon' => 'fa-pencil', 'title' => __( 'Review affected links', 'dma-internlink-mapper' ), 'description' => __( 'Inspect the source page and improve anchors only where useful.', 'dma-internlink-mapper' ) ),
                        ),
                    )
                    : array(
                        'eyebrow'     => __( 'SEO issue index not built yet', 'dma-internlink-mapper' ),
                        'title'       => __( 'Scan your site for SEO issues', 'dma-internlink-mapper' ),
                        'intro'       => __( 'Run a full local scan to analyze supported public content for technical SEO issues and build an actionable report.', 'dma-internlink-mapper' ),
                        'visual_icon' => 'fa-search-plus',
                        'steps'       => array(
                            array( 'icon' => 'fa-files-o', 'title' => __( 'Analyze indexed content', 'dma-internlink-mapper' ), 'description' => __( 'Inspect supported posts, pages and enabled custom post types.', 'dma-internlink-mapper' ) ),
                            array( 'icon' => 'fa-exclamation-triangle', 'title' => __( 'Detect supported issues', 'dma-internlink-mapper' ), 'description' => __( 'Check metadata, headings, image ALT text and link-related signals.', 'dma-internlink-mapper' ) ),
                            array( 'icon' => 'fa-list-alt', 'title' => __( 'Review affected pages', 'dma-internlink-mapper' ), 'description' => __( 'Filter findings by severity and issue type after the scan.', 'dma-internlink-mapper' ) ),
                        ),
                    )
            );
            $this->footer();
            return;
        }

        global $wpdb;

        $scan       = ILSM_Database::latest_completed_scan_id();
        $rows       = array();
        $error      = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $severity   = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $issue_type = isset( $_GET['issue_type'] ) ? sanitize_key( wp_unslash( $_GET['issue_type'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $paged      = max( 1, absint( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
        $settings = get_option( 'ilsm_settings', array() );
        $allowed  = array( 25, 50, 100, 200 );
        $requested_per_page = absint( isset( $settings['report_per_page'] ) ? $settings['report_per_page'] : 50 );
        $per_page = in_array( $requested_per_page, $allowed, true ) ? $requested_per_page : 50;
        $total    = 0;

        try {
            if ( $scan ) {
                $table = ILSM_Database::table( 'issues' );
                if ( ! ILSM_Database::table_exists( $table ) ) {
                    throw new RuntimeException( 'The SEO issues table is missing.' );
                }

                $where = ' WHERE i.scan_id=%d';
                $args  = array( $scan );
                if ( $types ) {
                    $where .= ' AND i.issue_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
                    $args = array_merge( $args, array_map( 'sanitize_key', $types ) );
                }
                if ( in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
                    $where .= ' AND i.severity=%s';
                    $args[] = $severity;
                }
                if ( '' !== $issue_type && ( empty( $types ) || in_array( $issue_type, $types, true ) ) ) {
                    $where .= ' AND i.issue_type=%s';
                    $args[] = $issue_type;
                }
                if ( '' !== $search ) {
                    $like = '%' . $wpdb->esc_like( $search ) . '%';
                    $where .= ' AND (i.message LIKE %s OR i.issue_type LIKE %s OR p.post_title LIKE %s)';
                    $args[] = $like;
                    $args[] = $like;
                    $args[] = $like;
                }

                $count_sql = "SELECT COUNT(*) FROM {$table} i LEFT JOIN {$wpdb->posts} p ON p.ID=i.post_id {$where}";
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
                $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
                if ( ! empty( $wpdb->last_error ) ) {
                    throw new RuntimeException( $this->database_error_message( 'The issue count could not be loaded.' ) );
                }

                $offset = ( $paged - 1 ) * $per_page;
                if ( $total > 0 && $offset >= $total ) {
                    $paged  = max( 1, (int) ceil( $total / $per_page ) );
                    $offset = ( $paged - 1 ) * $per_page;
                }

                $posts_table = $wpdb->posts;
                $select_sql = "SELECT i.id,i.post_id,i.link_id,i.issue_type,i.severity,i.message,i.created_at,COALESCE(p.post_title,'') AS post_title
                    FROM {$table} i
                    LEFT JOIN {$posts_table} p ON p.ID=i.post_id
                    {$where}
                    ORDER BY i.id DESC
                    LIMIT %d OFFSET %d";
                $query_args = array_merge( $args, array( $per_page, $offset ) );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
                $result = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_args ) );
                if ( ! empty( $wpdb->last_error ) ) {
                    throw new RuntimeException( $this->database_error_message( 'The issue rows could not be loaded.' ) );
                }
                $rows = is_array( $result ) ? $result : array();
            }
        } catch ( Throwable $e ) {
            $error = $e->getMessage();
            $this->log_error( 'issues', $e );
            $rows = array();
            $total = 0;
        }

        if ( $error ) {
            echo '<div class="ilsm-error-card"><i class="fa fa-exclamation-triangle"></i><div><h2>' . esc_html( $this->display_text( $title ) ) . ' could not be loaded</h2><p>' . esc_html( $this->display_text( $error ) ) . '</p><div class="ilsm-health-actions"><a class="ilsm-btn ilsm-btn-primary" href="' . esc_url( admin_url( 'admin.php?page=ilsm-health-audit' ) ) . '"><i class="fa fa-heartbeat" aria-hidden="true"></i> ' . esc_html__( 'Open Health Audit', 'dma-internlink-mapper' ) . '</a><a class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ) . '"><i class="fa fa-refresh"></i> Run a new scan</a></div></div></div>';
            $this->footer();
            return;
        }

        $available_issue_types = $types ? array_values( array_unique( array_map( 'sanitize_key', $types ) ) ) : array();
        if ( ! $types && $scan ) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
            $available_issue_types = array_values( array_filter( array_map( 'sanitize_key', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT issue_type FROM ' . ILSM_Database::table( 'issues' ) . ' WHERE scan_id=%d ORDER BY issue_type ASC', $scan ) ) ) ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        $page_slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'ilsm-seo-issues';
        echo '<form method="get" class="ilsm-report-filters ilsm-issue-filters"><input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '"><label><span>' . esc_html__( 'Search', 'dma-internlink-mapper' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Post, issue or message', 'dma-internlink-mapper' ) . '"></label><label><span>' . esc_html__( 'Severity', 'dma-internlink-mapper' ) . '</span><select name="severity"><option value="">' . esc_html__( 'All severities', 'dma-internlink-mapper' ) . '</option>';
        foreach ( array( 'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $severity, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label><label><span>' . esc_html__( 'Issue type', 'dma-internlink-mapper' ) . '</span><select name="issue_type"><option value="">' . esc_html__( 'All issue types', 'dma-internlink-mapper' ) . '</option>';
        foreach ( $available_issue_types as $available_type ) { echo '<option value="' . esc_attr( $available_type ) . '" ' . selected( $issue_type, $available_type, false ) . '>' . esc_html( ucwords( str_replace( '_', ' ', $available_type ) ) ) . '</option>'; }
        echo '</select></label><button class="ilsm-btn ilsm-btn-primary"><i class="fa fa-filter"></i> ' . esc_html__( 'Apply Filters', 'dma-internlink-mapper' ) . '</button><a class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=' . $page_slug ) ) . '">' . esc_html__( 'Reset', 'dma-internlink-mapper' ) . '</a></form>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<div class="ilsm-list-toolbar"><div class="ilsm-result-count"><strong>' . number_format_i18n( $total ) . '</strong> ' . esc_html__( 'results', 'dma-internlink-mapper' ) . '</div><input type="search" class="ilsm-table-search" placeholder="' . esc_attr__( 'Filter this page…', 'dma-internlink-mapper' ) . '"></div>';
        echo '<section class="ilsm-panel ilsm-table-panel ilsm-orphan-table-panel"><div class="ilsm-table-scroll ilsm-orphan-table-scroll"><table class="ilsm-table"><thead><tr><th>' . esc_html__( 'Severity', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Issue', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Post', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Message', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Actions', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';

        $rendered = 0;
        foreach ( $rows as $r ) {
            try {
                $post_id    = absint( $this->row_value( $r, 'post_id', 0 ) );
                $severity   = sanitize_key( $this->row_value( $r, 'severity', 'medium' ) );
                $issue_type = sanitize_key( $this->row_value( $r, 'issue_type', 'unknown_issue' ) );
                $message    = $this->display_text( $this->row_value( $r, 'message', '' ) );
                $post_title = $this->display_text( $this->row_value( $r, 'post_title', '' ) );
                $post_label = '' !== trim( $post_title ) ? $post_title : ( $post_id ? '#' . $post_id : 'Site-wide issue' );
                $source_url = $post_id ? get_permalink( $post_id ) : false;
                $can_edit   = $post_id && current_user_can( 'edit_post', $post_id );
                $edit_url   = $can_edit ? get_edit_post_link( $post_id, 'raw' ) : false;
                $badge      = in_array( $severity, array( 'high', 'critical' ), true ) ? 'danger' : ( 'low' === $severity ? 'neutral' : 'warning' );
                $actions    = '';

                if ( $source_url ) {
                    /* translators: %s: source post, page, or custom post type title. */
                    $open_source_label = sprintf( __( 'Open source: %s', 'dma-internlink-mapper' ), $post_label );
                    $actions          .= '<a class="ilsm-btn ilsm-btn-small" href="' . esc_url( $source_url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $open_source_label ) . '"><i class="fa fa-external-link" aria-hidden="true"></i> ' . esc_html__( 'Open source', 'dma-internlink-mapper' ) . '</a>';
                }
                if ( $edit_url ) {
                    /* translators: %s: source post, page, or custom post type title. */
                    $edit_source_label = sprintf( __( 'Edit source: %s', 'dma-internlink-mapper' ), $post_label );
                    $actions          .= '<a class="ilsm-btn ilsm-btn-small" href="' . esc_url( $edit_url ) . '" aria-label="' . esc_attr( $edit_source_label ) . '"><i class="fa fa-pencil" aria-hidden="true"></i> ' . esc_html__( 'Edit', 'dma-internlink-mapper' ) . '</a>';
                }
                if ( '' === $actions ) {
                    $actions = '<span class="ilsm-muted">' . esc_html__( 'Unavailable', 'dma-internlink-mapper' ) . '</span>';
                }

                echo '<tr><td><span class="ilsm-badge is-' . esc_attr( $badge ) . '">' . esc_html( ucfirst( $severity ?: 'medium' ) ) . '</span></td><td>' . esc_html( ucwords( str_replace( '_', ' ', $issue_type ) ) ) . '</td><td>' . esc_html( $post_label ) . '</td><td>' . esc_html( $message ) . '</td><td><div class="ilsm-row-actions">' . wp_kses_post( $actions ) . '</div></td></tr>';
                $rendered++;
            } catch ( Throwable $row_error ) {
                $this->log_error( 'issue-row', $row_error );
                continue;
            }
        }
        if ( 0 === $rendered ) {
            echo '<tr><td colspan="5" class="ilsm-empty-cell">' . ( $total ? 'The report contains records, but none could be rendered safely. Open Health Audit and repair the schema.' : 'No issues found for this view.' ) . '</td></tr>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '</tbody></table></div>' . $this->pagination( $paged, $total, $per_page ) . '</section>';
        $this->footer();
    }

    private function pagination( $current, $total, $per_page ) {
        $pages = (int) ceil( $total / $per_page );
        if ( $pages <= 1 ) { return ''; }
        $links = paginate_links( array(
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'current'   => max( 1, $current ),
            'total'     => $pages,
            'mid_size'  => 2,
            'end_size'  => 1,
            'prev_text' => '<i class="fa fa-angle-left" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( 'Previous', 'dma-internlink-mapper' ) . '</span>',
            'next_text' => '<span class="screen-reader-text">' . esc_html__( 'Next', 'dma-internlink-mapper' ) . '</span><i class="fa fa-angle-right" aria-hidden="true"></i>',
            'type'      => 'array',
        ) );
        if ( ! $links ) { return ''; }
        return '<nav class="ilsm-pagination" aria-label="' . esc_attr__( 'Results pagination', 'dma-internlink-mapper' ) . '">' . implode( '', array_map( static function( $link ) { return '<span class="ilsm-page-item">' . wp_kses_post( $link ) . '</span>'; }, $links ) ) . '</nav>';
    }

    public function history() {
        $this->header( 'Scan History', 'Review previous scans, safely prune old snapshots, or reset all indexed plugin data.' );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        if ( isset( $_GET['ilsm_notice'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
            $notice = sanitize_key( wp_unslash( $_GET['ilsm_notice'] ) );
            $messages = array(
                'history_deleted' => array( 'success', 'Old scan history and its obsolete indexed snapshots were deleted. The latest completed scan and any active scan were preserved.' ),
                'all_deleted'     => array( 'success', 'All scan history and indexed data were permanently deleted. Links already inserted into post content were preserved.' ),
                'nothing_deleted' => array( 'info', 'There was no old scan history to delete.' ),
                'delete_failed'   => array( 'error', 'The cleanup could not be completed safely. No partial deletion was kept.' ),
                'transaction_unavailable' => array( 'error', 'Deletion was blocked because one or more plugin tables do not support reliable transactions. Repair the database schema before retrying.' ),
                'active_scan'     => array( 'warning', 'A scan is currently active. Pause or cancel it before deleting all indexed data.' ),
            );
            if ( isset( $messages[ $notice ] ) ) {
                echo '<div class="ilsm-inline-notice is-' . esc_attr( $messages[ $notice ][0] ) . '"><i class="fa fa-info-circle" aria-hidden="true"></i><span>' . esc_html( $messages[ $notice ][1] ) . '</span></div>';
            }
        }

        echo '<div class="ilsm-history-actions">';
        echo '<section class="ilsm-panel ilsm-history-action-card">';
        echo '<div class="ilsm-history-action-icon is-amber"><i class="fa fa-history" aria-hidden="true"></i></div><div><h2>' . esc_html__( 'Delete Scan History', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Removes older scan snapshots and their related obsolete rows while preserving the latest completed scan and any active scan used by reports.', 'dma-internlink-mapper' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ilsm-confirm-form" data-confirm="Delete old scan history? The latest completed scan and active scan will be kept.">';
        echo '<input type="hidden" name="action" value="ilsm_delete_scan_history">';
        wp_nonce_field( 'ilsm_delete_scan_history', 'ilsm_history_nonce' );
        echo '<button type="submit" class="ilsm-btn ilsm-btn-danger-soft"><i class="fa fa-trash-o" aria-hidden="true"></i> Delete Scan History</button></form></div></section>';

        echo '<section class="ilsm-panel ilsm-history-action-card is-danger">';
        echo '<div class="ilsm-history-action-icon is-red"><i class="fa fa-database" aria-hidden="true"></i></div><div><h2>' . esc_html__( 'Delete History and Indexed Data', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Permanently deletes scans, indexed pages, link records, issues, keywords, graph data, opportunities, and local assistant feedback. Links already inserted into WordPress post content are preserved. A fresh full scan will be required.', 'dma-internlink-mapper' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ilsm-confirm-form" data-confirm="Permanently delete ALL DMA InternLink Mapper history and indexed data? Links already inserted into posts will be kept.">';
        echo '<input type="hidden" name="action" value="ilsm_delete_all_scan_data">';
        echo '<input type="hidden" name="keep_inserted_links" value="1">';
        wp_nonce_field( 'ilsm_delete_all_scan_data', 'ilsm_all_data_nonce' );
        echo '<label class="ilsm-keep-inserted-links"><input type="checkbox" checked disabled><span><strong>' . esc_html__( 'Keep inserted links in posts', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Scan cleanup never edits post content. Links inserted through Gutenberg, Classic Editor, or Elementor remain in place.', 'dma-internlink-mapper' ) . '</small></span><i class="fa fa-shield" aria-hidden="true"></i></label>';
        echo '<label class="ilsm-destructive-confirm"><input type="checkbox" name="confirm_delete" value="1" required> I understand that all indexed plugin data will be permanently deleted.</label>';
        echo '<button type="submit" class="ilsm-btn ilsm-btn-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Delete History and Indexed Data</button></form></div></section>';
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        echo '<section class="ilsm-panel ilsm-table-panel">' . $this->history_table( 100 ) . '</section>';
        $this->footer();
    }

    private function history_table( $limit ) {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Database::table( 'scans' ) . ' ORDER BY id DESC LIMIT %d', $limit ) );
        $html = '<div class="ilsm-table-scroll"><table class="ilsm-table"><thead><tr><th>' . esc_html__( 'ID', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Items', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Links', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Issues', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Started', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $html .= '<tr><td>#' . absint( $r->id ) . '</td><td><span class="ilsm-badge is-neutral">' . esc_html( ucfirst( $r->status ) ) . '</span></td><td>' . absint( $r->scanned_items ) . ' / ' . absint( $r->total_items ) . '</td><td>' . absint( $r->links_found ) . '</td><td>' . absint( $r->issues_found ) . '</td><td>' . esc_html( $r->started_at ) . '</td></tr>';
        }
        if ( ! $rows ) { $html .= '<tr><td colspan="6" class="ilsm-empty-cell">' . esc_html__( 'No scans have been run yet.', 'dma-internlink-mapper' ) . '</td></tr>'; }
        return $html . '</tbody></table></div>';
    }

    public function delete_scan_history() {
        if ( ! current_user_can( 'ilsm_delete_scan_data' ) ) {
            wp_die( esc_html__( 'You are not allowed to delete scan data.', 'dma-internlink-mapper' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'ilsm_delete_scan_history', 'ilsm_history_nonce' );

        global $wpdb;
        $scans = ILSM_Database::table( 'scans' );
        $protected = array_filter( array(
            ILSM_Database::latest_completed_scan_id(),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
            (int) $wpdb->get_var( "SELECT id FROM {$scans} WHERE status IN ('pending','running','paused') ORDER BY id DESC LIMIT 1" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
        ) );
        $protected = array_values( array_unique( array_map( 'absint', $protected ) ) );

        $where = '1=1';
        $args  = array();
        if ( $protected ) {
            $where = 'id NOT IN (' . implode( ',', array_fill( 0, count( $protected ), '%d' ) ) . ')';
            $args  = $protected;
        }
        $ids_sql = "SELECT id FROM {$scans} WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $args ) {
            $ids_sql = $wpdb->prepare( $ids_sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $delete_ids = array_map( 'absint', (array) $wpdb->get_col( $ids_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
        if ( ! $delete_ids ) {
            $this->history_redirect( 'nothing_deleted' );
        }

        $ok = $this->delete_scan_ids_transactionally( $delete_ids, true );
        $this->history_redirect( $ok ? 'history_deleted' : 'delete_failed' );
    }

    public function delete_all_scan_data() {
        if ( ! current_user_can( 'ilsm_delete_scan_data' ) ) {
            wp_die( esc_html__( 'You are not allowed to delete scan data.', 'dma-internlink-mapper' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'ilsm_delete_all_scan_data', 'ilsm_all_data_nonce' );
        if ( empty( $_POST['confirm_delete'] ) ) {
            wp_die( esc_html__( 'Explicit confirmation is required.', 'dma-internlink-mapper' ), '', array( 'response' => 400 ) );
        }
        if ( empty( $_POST['keep_inserted_links'] ) ) {
            wp_die( esc_html__( 'Inserted links must remain protected when deleting scan data.', 'dma-internlink-mapper' ), '', array( 'response' => 400 ) );
        }

        global $wpdb;
        $scans = ILSM_Database::table( 'scans' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scans} WHERE status IN ('pending','running','paused')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
        if ( $active > 0 ) {
            $this->history_redirect( 'active_scan' );
        }

        $tables = array( 'external_actions', 'opportunities', 'feedback', 'phrases', 'keywords', 'issues', 'links', 'pages', 'scans' );
        if ( ! ILSM_Database::begin_transaction() ) {
            $this->history_redirect( 'transaction_unavailable' );
        }
        $ok = true;
        foreach ( $tables as $name ) {
            $table = ILSM_Database::table( $name );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
                $ok = false;
                break;
            }
        }
        if ( $ok ) {
            $ok = ILSM_Database::commit();
            delete_transient( 'ilsm_dashboard_stats' );
            delete_option( 'ilsm_last_scan_id' );
        } else {
            ILSM_Database::rollback();
        }
        $this->history_redirect( $ok ? 'all_deleted' : 'delete_failed' );
    }

    private function delete_scan_ids_transactionally( $scan_ids, $delete_scan_rows = true ) {
        global $wpdb;
        $scan_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $scan_ids ) ) ) );
        if ( ! $scan_ids ) { return true; }
        $placeholders = implode( ',', array_fill( 0, count( $scan_ids ), '%d' ) );
        if ( ! ILSM_Database::begin_transaction() ) {
            return false;
        }
        $ok = true;
        foreach ( array( 'opportunities', 'phrases', 'keywords', 'issues', 'links', 'pages' ) as $name ) {
            $table = ILSM_Database::table( $name );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder list is completed by the validated argument array.
            $sql = $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id IN ({$placeholders})", $scan_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            if ( false === $wpdb->query( $sql ) ) { $ok = false; break; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
        }
        if ( $ok && $delete_scan_rows ) {
            $table = ILSM_Database::table( 'scans' );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder list is completed by the validated argument array.
            $sql = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $scan_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            if ( false === $wpdb->query( $sql ) ) { $ok = false; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
        }
        if ( $ok ) {
            $ok = ILSM_Database::commit();
        } else {
            ILSM_Database::rollback();
        }
        if ( $ok ) { delete_transient( 'ilsm_dashboard_stats' ); }
        return $ok;
    }

    private function history_redirect( $notice ) {
        wp_safe_redirect( add_query_arg( 'ilsm_notice', sanitize_key( $notice ), admin_url( 'admin.php?page=ilsm-history' ) ) );
        exit;
    }


    public function external_links() {
        $this->header( __( 'External Link Health', 'dma-internlink-mapper' ), __( 'See where your site links, review new or unapproved domains, inspect comment URLs, and investigate unexpected internal URLs.', 'dma-internlink-mapper' ) );
        ILSM_External_Link_Health::render();
        $this->footer();
    }

    public function broken_links() {
        $this->header( __( 'Broken Links', 'dma-internlink-mapper' ), __( 'Recheck destinations in bounded batches and safely unlink multiple verified 404 anchors.', 'dma-internlink-mapper' ) );
        ILSM_Broken_Link_Maintenance::render();
        $this->footer();
    }

    public function settings() {
        $this->header( __( 'Settings', 'dma-internlink-mapper' ), __( 'Configure how DMA InternLink Mapper scans, analyzes, and stores your data.', 'dma-internlink-mapper' ) );
        $s = get_option( 'ilsm_settings', array() );
        $types = get_post_types( array( 'public' => true ), 'objects' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering; no state is changed.
        if ( ! empty( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'dma-internlink-mapper' ) . '</p></div>'; }
        echo '<form method="post" id="ilsm-settings-form" class="ilsm-settings-form ilsm-settings-premium">';
        wp_nonce_field( 'ilsm_save_settings' );
        echo '<input type="hidden" name="ilsm_save_settings" value="1">';
        echo '<div class="ilsm-settings-top-actions"><button type="button" class="ilsm-btn" id="ilsm-reset-settings"><i class="fa fa-refresh" aria-hidden="true"></i> ' . esc_html__( 'Reset to defaults', 'dma-internlink-mapper' ) . '</button><button class="ilsm-btn ilsm-btn-primary"><i class="fa fa-floppy-o" aria-hidden="true"></i> ' . esc_html__( 'Save settings', 'dma-internlink-mapper' ) . '</button></div>';
        echo '<div class="ilsm-settings-shell"><main class="ilsm-settings-main">';
        echo '<section class="ilsm-panel ilsm-settings-card ilsm-settings-performance"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-tachometer"></i></span><div><h2>' . esc_html__( 'Scan Performance', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Control scanner speed, accuracy, and server resource usage.', 'dma-internlink-mapper' ) . '</p></div><span class="ilsm-tip-chip"><i class="fa fa-lightbulb-o" aria-hidden="true"></i> ' . esc_html__( 'Tips', 'dma-internlink-mapper' ) . '</span></div><div class="ilsm-form-grid ilsm-form-grid-three">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'batch_size', __( 'Batch size', 'dma-internlink-mapper' ), $s['batch_size'] ?? 15, 1, 100, __( 'Pages processed per AJAX request.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'batch_delay', __( 'Delay between batches (ms)', 'dma-internlink-mapper' ), $s['batch_delay'] ?? 350, 0, 10000, __( 'Milliseconds between requests.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'max_pages', __( 'Maximum pages', 'dma-internlink-mapper' ), $s['max_pages'] ?? 5000, 1, 50000, __( 'Hard limit per scan.', 'dma-internlink-mapper' ) );
        echo '</div><div class="ilsm-field ilsm-post-type-field"><span class="ilsm-field-title">Post Types to Include <i class="fa fa-info-circle" title="Only selected public post types are scanned."></i></span><div class="ilsm-check-grid">';
        foreach ( $types as $type ) {
            if ( ! ILSM_SEO_Inspector::is_supported_post_type( $type->name ) ) { continue; }
            $checked = in_array( $type->name, (array) ( $s['post_types'] ?? ILSM_Activator::default_post_types() ), true );
            $default = ILSM_SEO_Inspector::is_default_post_type( $type->name ) ? '1' : '0';
            echo '<label class="ilsm-check-pill"><input type="checkbox" name="post_types[]" value="' . esc_attr( $type->name ) . '" data-ilsm-default="' . esc_attr( $default ) . '" ' . checked( $checked, true, false ) . '><span><i class="fa fa-check-square-o"></i>' . esc_html( $type->labels->singular_name ) . '</span></label>';
        }
        echo '</div></div></section>';
        echo '<div class="ilsm-settings-two-col">';
        echo '<section class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-paint-brush"></i></span><div><h2>' . esc_html__( 'Visual Map Colors', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Customize link colors used in maps and legends.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-color-settings-grid">';
        foreach ( array( 'incoming_color' => 'Incoming Links', 'outgoing_color' => 'Outgoing Links', 'broken_color' => 'Broken Links', 'redirect_color' => 'Redirect Links' ) as $key => $label ) {
            $fallbacks = array( 'incoming_color'=>'#2563EB','outgoing_color'=>'#F97316','broken_color'=>'#EF4444','redirect_color'=>'#8B5CF6' );
            $value = sanitize_hex_color( $s[ $key ] ?? $fallbacks[$key] ) ?: $fallbacks[$key];
            echo '<label class="ilsm-field ilsm-color-field"><span>' . esc_html( $label ) . '</span><div class="ilsm-color-input"><input type="color" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '"><code>' . esc_html( strtoupper( $value ) ) . '</code></div></label>';
        }
        echo '</div></section>';
        echo '<section class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-shield"></i></span><div><h2>' . esc_html__( 'Report Quality', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Control how reports are generated and displayed.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-report-quality-grid"><label class="ilsm-quality-option"><input type="checkbox" name="exclude_media_links" value="1" ' . checked( ! empty( $s['exclude_media_links'] ), true, false ) . '><span><i class="fa fa-check-square"></i><strong>' . esc_html__( 'Exclude media and document targets', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Keep image and document URLs out of map metrics.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-quality-option"><input type="checkbox" name="check_http" value="1" ' . checked( ! empty( $s['check_http'] ), true, false ) . '><span><i class="fa fa-globe"></i><strong>' . esc_html__( 'Check internal HTTP status', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Detect broken links and redirects during manual scans. This adds requests to your own site and is disabled by default.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-quality-option"><input type="checkbox" name="check_external_http" value="1" ' . checked( ! empty( $s['check_external_http'] ), true, false ) . '><span><i class="fa fa-external-link"></i><strong>' . esc_html__( 'Check external HTTP status', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Optionally verify external links for broken responses and redirects. Disabled by default because it makes requests to third-party sites.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-field"><span>' . esc_html__( 'Rows Per Report Page', 'dma-internlink-mapper' ) . ' <i class="fa fa-info-circle" aria-hidden="true"></i></span><select name="report_per_page">';
        foreach ( array( 25, 50, 100, 200 ) as $amount ) { echo '<option value="' . absint( $amount ) . '" ' . selected( (int) ( $s['report_per_page'] ?? 50 ), $amount, false ) . '>' . absint( $amount ) . '</option>'; }
        echo '</select><small>' . esc_html__( 'Server-side pagination keeps large reports responsive.', 'dma-internlink-mapper' ) . '</small></label></div></section></div>';
        echo '<section id="ilsm-broken-monitor" class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-chain-broken"></i></span><div><h2>' . esc_html__( 'Bounded Broken-Link Monitor', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Rechecks a very small queue hourly instead of crawling the whole site in one database-heavy job.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-report-quality-grid"><label class="ilsm-quality-option"><input type="checkbox" name="broken_monitor_enabled" value="1" ' . checked( ! empty( $s['broken_monitor_enabled'] ), true, false ) . '><span><strong>' . esc_html__( 'Enable background monitoring', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Disabled by default. Uses a single lock, a primary-key cursor and hard request limits.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-quality-option"><input type="checkbox" name="broken_monitor_external" value="1" ' . checked( ! empty( $s['broken_monitor_external'] ), true, false ) . '><span><strong>' . esc_html__( 'Include external destinations', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Makes outbound requests to third-party sites. Leave disabled for local-only checks.', 'dma-internlink-mapper' ) . '</small></span></label>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup.
        $this->number_field( 'broken_monitor_batch_size', __( 'Destinations per hourly run', 'dma-internlink-mapper' ), $s['broken_monitor_batch_size'] ?? 5, 1, 10, __( 'Hard capped at 10 to protect shared hosting and the database.', 'dma-internlink-mapper' ) );
        echo '</div><p><a class="ilsm-btn" href="' . esc_url( admin_url( 'admin.php?page=ilsm-broken-links' ) ) . '">' . esc_html__( 'Open Broken Links', 'dma-internlink-mapper' ) . '</a></p></section>';
        echo '<section id="ilsm-safe-link-insertion" class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-link"></i></span><div><h2>' . esc_html__( 'Safe Link Insertion', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Choose Preview Mode for testing or Live Mode to write approved links into supported content.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-form-grid ilsm-form-grid-three">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'insert_min_confidence', __( 'Minimum confidence', 'dma-internlink-mapper' ), $s['insert_min_confidence'] ?? 70, 60, 100, __( 'Default 70. Higher values produce fewer, stricter suggestions.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'insert_max_per_source', __( 'Maximum per source per run', 'dma-internlink-mapper' ), $s['insert_max_per_source'] ?? 2, 1, 10, __( 'Hard per-post limit.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'insert_max_per_run', __( 'Maximum site-wide per run', 'dma-internlink-mapper' ), $s['insert_max_per_run'] ?? 20, 1, 100, __( 'Hard batch limit.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'insert_min_word_distance', __( 'Minimum word distance', 'dma-internlink-mapper' ), $s['insert_min_word_distance'] ?? 120, 20, 1000, __( 'Minimum word distance between a new anchor and an existing contextual internal link.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'insert_min_source_words', __( 'Minimum source words', 'dma-internlink-mapper' ), $s['insert_min_source_words'] ?? 300, 50, 5000, __( 'Pages below this eligible body-word count are excluded as insertion sources.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'insert_density_per_1000', __( 'Maximum links per 1,000 words', 'dma-internlink-mapper' ), $s['insert_density_per_1000'] ?? 6, 1, 20, __( 'Contextual internal-link density ceiling.', 'dma-internlink-mapper' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper returns escaped, plugin-owned admin markup or a safe numeric value.
        $this->number_field( 'insert_batch_size', __( 'Processing batch size', 'dma-internlink-mapper' ), $s['insert_batch_size'] ?? 5, 1, 20, __( 'Small bounded AJAX batches.', 'dma-internlink-mapper' ) );
        echo '</div><div class="ilsm-report-quality-grid"><label class="ilsm-quality-option"><input type="checkbox" name="insert_create_revision" value="1" ' . checked( ! empty( $s['insert_create_revision'] ), true, false ) . '><span><strong>' . esc_html__( 'Create a WordPress revision', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Recommended before every content modification.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-quality-option"><input type="checkbox" name="insert_audit_log" value="1" ' . checked( ! empty( $s['insert_audit_log'] ), true, false ) . '><span><strong>' . esc_html__( 'Enable insertion audit log', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Records verified insertions, failures and undo actions.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-quality-option"><input type="checkbox" name="insert_dry_run" value="1" ' . checked( ! empty( $s['insert_dry_run'] ), true, false ) . '><span><strong>' . esc_html__( 'Preview Mode', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Safely test every insertion without changing posts, pages, Gutenberg blocks, or Elementor data. Enabled by default.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-quality-option"><input type="checkbox" name="insert_auto_enabled" value="1" ' . checked( ! empty( $s['insert_auto_enabled'] ), true, false ) . '><span><strong>' . esc_html__( 'Enable reviewed bulk processing', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Processes only opportunities you explicitly select. It never runs on page load or WordPress Heartbeat.', 'dma-internlink-mapper' ) . '</small></span></label></div></section>';
        echo '<section class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-filter"></i></span><div><h2>' . esc_html__( 'Opportunity Eligibility', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Exclude utility and non-indexable pages before suggestions are generated.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-report-quality-grid">';
        foreach ( array(
            'opportunity_exclude_noindex' => array( __( 'Exclude noindex pages', 'dma-internlink-mapper' ), __( 'Applies to both source and destination pages, including supported SEO plugin metadata.', 'dma-internlink-mapper' ) ),
            'opportunity_exclude_privacy' => array( __( 'Exclude privacy-policy pages', 'dma-internlink-mapper' ), __( 'Uses the WordPress privacy-policy assignment and recognized privacy slugs.', 'dma-internlink-mapper' ) ),
            'opportunity_exclude_cookies' => array( __( 'Exclude cookie-policy pages', 'dma-internlink-mapper' ), __( 'Removes cookie policies and notices from opportunity generation.', 'dma-internlink-mapper' ) ),
            'opportunity_exclude_legal' => array( __( 'Exclude terms and legal pages', 'dma-internlink-mapper' ), __( 'Removes terms of use, terms and conditions, legal notices and disclaimers.', 'dma-internlink-mapper' ) ),
        ) as $key => $copy ) {
            $enabled = ! array_key_exists( $key, $s ) || ! empty( $s[ $key ] );
            echo '<label class="ilsm-quality-option"><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $enabled, true, false ) . '><span><strong>' . esc_html( $copy[0] ) . '</strong><small>' . esc_html( $copy[1] ) . '</small></span></label>';
        }
        echo '</div><p class="description">' . wp_kses_post( __( 'Developers may customize eligibility with the <code>ilsm_is_linkable_page</code> filter.', 'dma-internlink-mapper' ) ) . '</p></section>';
        echo '<section id="ilsm-external-link-health" class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-external-link"></i></span><div><h2>' . esc_html__( 'External Link Health', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Configure approved external domains and safe replacement text. Unknown domains are reported, never removed automatically.', 'dma-internlink-mapper' ) . '</p></div></div><label class="ilsm-field"><span>' . esc_html__( 'Approved domains', 'dma-internlink-mapper' ) . '</span><textarea name="external_allowlist" rows="7" placeholder="tripadvisor.com
*.google.com">' . esc_textarea( $s['external_allowlist'] ?? '' ) . '</textarea><small>' . esc_html__( 'One domain per line. Use *.example.com to approve subdomains. Your own domain does not need to be listed.', 'dma-internlink-mapper' ) . '</small></label><label class="ilsm-field"><span>' . esc_html__( 'Removed link text', 'dma-internlink-mapper' ) . '</span><input type="text" name="external_removed_text" value="' . esc_attr( $s['external_removed_text'] ?? '[Removed Link]' ) . '" maxlength="80"><small>' . esc_html__( 'Used only when an administrator explicitly chooses the Replace action.', 'dma-internlink-mapper' ) . '</small></label><label class="ilsm-field"><span>' . esc_html__( 'Default admin appearance', 'dma-internlink-mapper' ) . '</span><select name="admin_theme"><option value="dark" ' . selected( $s['admin_theme'] ?? 'dark', 'dark', false ) . '>' . esc_html__( 'Dark', 'dma-internlink-mapper' ) . '</option><option value="light" ' . selected( $s['admin_theme'] ?? 'dark', 'light', false ) . '>' . esc_html__( 'Light', 'dma-internlink-mapper' ) . '</option><option value="system" ' . selected( $s['admin_theme'] ?? 'dark', 'system', false ) . '>' . esc_html__( 'Follow browser/system', 'dma-internlink-mapper' ) . '</option></select><small>' . esc_html__( 'Users can still switch instantly from the toolbar; their browser preference is remembered locally.', 'dma-internlink-mapper' ) . '</small></label></section>';
        echo '<section class="ilsm-panel ilsm-settings-card"><div class="ilsm-settings-head"><span class="ilsm-settings-icon"><i class="fa fa-lock"></i></span><div><h2>' . esc_html__( 'Uninstall & Data Retention', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Choose what remains when the plugin is deleted from WordPress.', 'dma-internlink-mapper' ) . '</p></div></div><div class="ilsm-report-quality-grid"><label class="ilsm-quality-option"><input type="checkbox" name="remove_inserted_links_on_uninstall" value="1" ' . checked( ! empty( $s['remove_inserted_links_on_uninstall'] ), true, false ) . '><span><strong>' . esc_html__( 'Remove plugin-inserted links during uninstall', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Advanced and disabled by default. Only links carrying the plugin marker are unwrapped; their visible anchor text is preserved. A backup is strongly recommended.', 'dma-internlink-mapper' ) . '</small></span></label><label class="ilsm-danger-check ilsm-retention-warning"><input type="checkbox" name="delete_on_uninstall" value="1" ' . checked( ! empty( $s['delete_on_uninstall'] ), true, false ) . '><span><strong>' . esc_html__( 'Permanently delete plugin tables and settings', 'dma-internlink-mapper' ) . '</strong><small>' . esc_html__( 'Scan history, reports, opportunities, insertion history, locks, feedback, and settings will be deleted. This cannot be undone.', 'dma-internlink-mapper' ) . '</small></span><i class="fa fa-exclamation-triangle"></i></label></div><p class="description"><strong>' . esc_html__( 'Recommended:', 'dma-internlink-mapper' ) . '</strong> ' . esc_html__( 'keep inserted links and delete only plugin data when a clean uninstall is required.', 'dma-internlink-mapper' ) . '</p></section>';
        echo '</main><aside class="ilsm-settings-sidebar"><section class="ilsm-panel ilsm-help-card"><h2><i class="fa fa-info-circle" aria-hidden="true"></i> ' . esc_html__( 'About Settings', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Fine-tune the scanner for your site size and hosting capabilities.', 'dma-internlink-mapper' ) . '</p></section><section class="ilsm-panel ilsm-help-card"><h2><i class="fa fa-rocket" aria-hidden="true"></i> ' . esc_html__( 'Performance Tips', 'dma-internlink-mapper' ) . '</h2><ul><li>' . esc_html__( 'Use smaller batch sizes on shared hosting.', 'dma-internlink-mapper' ) . '</li><li>' . esc_html__( 'Increase delay if requests time out.', 'dma-internlink-mapper' ) . '</li><li>' . esc_html__( 'Run large scans during off-peak hours.', 'dma-internlink-mapper' ) . '</li><li>' . esc_html__( 'Large rendered scans make local frontend requests and can be resource-intensive.', 'dma-internlink-mapper' ) . '</li></ul></section><section class="ilsm-panel ilsm-help-card"><h2><i class="fa fa-shield" aria-hidden="true"></i> ' . esc_html__( 'Data Safety', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Scan data and optional Search Console CSV rows stay in WordPress. DMA has no Google or Bing credentials. External status checks contact linked destinations only when explicitly enabled.', 'dma-internlink-mapper' ) . '</p></section></aside></div>';
        echo '</form>';
        ILSM_Search_Console_Import::render();
        echo '<div class="ilsm-savebar ilsm-savebar-final"><span><i class="fa fa-shield" aria-hidden="true"></i> ' . esc_html__( 'Settings are stored locally in WordPress.', 'dma-internlink-mapper' ) . '</span><button class="ilsm-btn ilsm-btn-primary" type="submit" form="ilsm-settings-form"><i class="fa fa-floppy-o" aria-hidden="true"></i> ' . esc_html__( 'Save settings', 'dma-internlink-mapper' ) . '</button></div>';
        $this->footer();
    }

    private function number_field( $name, $label, $value, $min, $max, $help ) {
        echo '<label class="ilsm-field"><span>' . esc_html( $label ) . '</span><input type="number" name="' . esc_attr( $name ) . '" min="' . absint( $min ) . '" max="' . absint( $max ) . '" value="' . esc_attr( $value ) . '"><small>' . esc_html( $help ) . '</small></label>';
    }

    public function save_settings() {
        if ( empty( $_POST['ilsm_save_settings'] ) ) { return; }
        if ( ! current_user_can( 'ilsm_manage_settings' ) ) { wp_die( esc_html__( 'Permission denied.', 'dma-internlink-mapper' ) ); }
        check_admin_referer( 'ilsm_save_settings' );

        $old = get_option( 'ilsm_settings', array() );
        $public_types = array_values( array_filter( get_post_types( array( 'public' => true ), 'names' ), array( 'ILSM_SEO_Inspector', 'is_supported_post_type' ) ) );
        $submitted_types = array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ?? ILSM_Activator::default_post_types() ) );
        $post_types = array_values( array_intersect( $submitted_types, $public_types ) );
        if ( ! $post_types ) { $post_types = ILSM_Activator::default_post_types(); }

        $new = array(
            'batch_size'         => max( 1, min( 100, absint( wp_unslash( $_POST['batch_size'] ?? 15 ) ) ) ),
            'batch_delay'        => max( 0, min( 10000, absint( wp_unslash( $_POST['batch_delay'] ?? 350 ) ) ) ),
            'max_pages'          => max( 1, min( 50000, absint( wp_unslash( $_POST['max_pages'] ?? 5000 ) ) ) ),
            'post_types'         => $post_types,
            'delete_on_uninstall'=> ! empty( $_POST['delete_on_uninstall'] ) ? 1 : 0,
            'remove_inserted_links_on_uninstall' => ! empty( $_POST['remove_inserted_links_on_uninstall'] ) ? 1 : 0,
            'exclude_media_links' => ! empty( $_POST['exclude_media_links'] ) ? 1 : 0,
            'check_http'          => ! empty( $_POST['check_http'] ) ? 1 : 0,
            'check_external_http' => ! empty( $_POST['check_external_http'] ) ? 1 : 0,
            'broken_monitor_enabled' => ! empty( $_POST['broken_monitor_enabled'] ) ? 1 : 0,
            'broken_monitor_external' => ! empty( $_POST['broken_monitor_external'] ) ? 1 : 0,
            'broken_monitor_batch_size' => max( 1, min( 10, absint( wp_unslash( $_POST['broken_monitor_batch_size'] ?? 5 ) ) ) ),
            'report_per_page'     => in_array( absint( wp_unslash( $_POST['report_per_page'] ?? 50 ) ), array( 25, 50, 100, 200 ), true ) ? absint( $_POST['report_per_page'] ) : 50,
            'insert_min_confidence' => max( 60, min( 100, absint( wp_unslash( $_POST['insert_min_confidence'] ?? 70 ) ) ) ),
            'insert_max_per_source' => max( 1, min( 10, absint( wp_unslash( $_POST['insert_max_per_source'] ?? 2 ) ) ) ),
            'insert_max_per_run' => max( 1, min( 100, absint( wp_unslash( $_POST['insert_max_per_run'] ?? 20 ) ) ) ),
            'insert_min_word_distance' => max( 20, min( 1000, absint( wp_unslash( $_POST['insert_min_word_distance'] ?? 120 ) ) ) ),
            'insert_min_source_words' => max( 50, min( 5000, absint( wp_unslash( $_POST['insert_min_source_words'] ?? 300 ) ) ) ),
            'insert_density_per_1000' => max( 1, min( 20, absint( wp_unslash( $_POST['insert_density_per_1000'] ?? 6 ) ) ) ),
            'insert_batch_size' => max( 1, min( 20, absint( wp_unslash( $_POST['insert_batch_size'] ?? 5 ) ) ) ),
            'insert_create_revision' => ! empty( $_POST['insert_create_revision'] ) ? 1 : 0,
            'insert_audit_log' => ! empty( $_POST['insert_audit_log'] ) ? 1 : 0,
            'insert_dry_run' => ! empty( $_POST['insert_dry_run'] ) ? 1 : 0,
            'insert_auto_enabled' => ! empty( $_POST['insert_auto_enabled'] ) ? 1 : 0,
            'opportunity_exclude_noindex' => ! empty( $_POST['opportunity_exclude_noindex'] ) ? 1 : 0,
            'opportunity_exclude_privacy' => ! empty( $_POST['opportunity_exclude_privacy'] ) ? 1 : 0,
            'opportunity_exclude_legal' => ! empty( $_POST['opportunity_exclude_legal'] ) ? 1 : 0,
            'opportunity_exclude_cookies' => ! empty( $_POST['opportunity_exclude_cookies'] ) ? 1 : 0,
            'external_allowlist' => implode( "\n", array_filter( array_unique( array_map( array( 'ILSM_External_Link_Health', 'normalize_domain' ), preg_split( '/[\r\n,]+/', sanitize_textarea_field( wp_unslash( $_POST['external_allowlist'] ?? '' ) ) ) ) ) ) ),
            'external_removed_text' => sanitize_text_field( wp_unslash( $_POST['external_removed_text'] ?? '[Removed Link]' ) ),
            'admin_theme' => in_array( sanitize_key( wp_unslash( $_POST['admin_theme'] ?? 'dark' ) ), array( 'dark', 'light', 'system' ), true ) ? sanitize_key( wp_unslash( $_POST['admin_theme'] ?? 'dark' ) ) : 'dark',
        );
        foreach ( array( 'incoming_color', 'outgoing_color', 'broken_color', 'redirect_color' ) as $key ) {
            $new[ $key ] = sanitize_hex_color( wp_unslash( $_POST[ $key ] ?? $old[ $key ] ?? '' ) ) ?: '#2563EB';
        }
        $new['local_assistant'] = isset( $old['local_assistant'] ) ? (int) $old['local_assistant'] : 1;
        $new['suggestion_limit'] = isset( $old['suggestion_limit'] ) ? absint( $old['suggestion_limit'] ) : 12;
        update_option( 'ilsm_settings', $new, false );
        ILSM_Broken_Link_Maintenance::sync_schedule();

        $old_min = max( 60, min( 100, absint( $old['insert_min_confidence'] ?? 70 ) ) );
        $eligibility_changed = $old_min !== $new['insert_min_confidence']
            || absint( $old['insert_min_source_words'] ?? 300 ) !== $new['insert_min_source_words']
            || absint( $old['insert_min_word_distance'] ?? 120 ) !== $new['insert_min_word_distance']
            || absint( $old['insert_density_per_1000'] ?? 6 ) !== $new['insert_density_per_1000'];
        if ( $eligibility_changed ) {
            global $wpdb;
            // Existing Ready rows were generated under different SEO eligibility rules.
            // Preserve inserted/history states and require a clean regeneration.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
            $wpdb->query( "DELETE FROM " . ILSM_Database::table( 'opportunities' ) . " WHERE status='new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
            update_option( 'ilsm_opportunity_engine_version', '', false );
        }

        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=ilsm-settings' ) ) );
        exit;
    }

    private function latest() {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $row = $wpdb->get_row( 'SELECT * FROM ' . ILSM_Database::table( 'scans' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables require direct SQL; identifiers are generated from a strict internal allowlist, mutable scan state must not be cached, and generated placeholders are completed with validated integer arguments.
        if ( ! $row ) { return array( 'status' => 'not started', 'percent' => 0, 'batch_no' => 0, 'scanned_items' => 0, 'total_items' => 0 ); }
        $row['percent'] = $row['total_items'] ? min( 100, round( $row['scanned_items'] / $row['total_items'] * 100 ) ) : 0;
        return $row;
    }

    private function stats() {
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan ) { return array_fill_keys( array( 'pages', 'links', 'incoming', 'outgoing', 'broken', 'redirects', 'orphans', 'weak' ), 0 ); }
        $p = ILSM_Database::table( 'pages' );
        $l = ILSM_Database::table( 'links' );
        return array(
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
            'pages'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE scan_id=%d", $scan ) ),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Only fixed or allowlisted SQL identifiers are interpolated.
            'links'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external'", $scan ) ),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Only fixed or allowlisted SQL identifiers are interpolated.
            'incoming'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(incoming_count),0) FROM {$p} WHERE scan_id=%d", $scan ) ),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Only fixed or allowlisted SQL identifiers are interpolated.
            'outgoing'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(outgoing_count),0) FROM {$p} WHERE scan_id=%d", $scan ) ),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Only fixed or allowlisted SQL identifiers are interpolated.
            'broken'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type='broken'", $scan ) ),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Only fixed or allowlisted SQL identifiers are interpolated.
            'redirects' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type='redirect'", $scan ) ),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Only fixed or allowlisted SQL identifiers are interpolated.
            'orphans'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE scan_id=%d AND is_orphan=1", $scan ) ),
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Only fixed or allowlisted SQL identifiers are interpolated.
            'weak'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type IN ('weak_anchor','empty_anchor')", $scan ) ),
        );
    }

    private function page_row( $post_id ) {
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan || ! $post_id ) { return array(); }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Database::table( 'pages' ) . ' WHERE scan_id=%d AND post_id=%d', $scan, $post_id ), ARRAY_A );
    }
    private function best_page() {
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan ) { return array(); }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Database::table( 'pages' ) . ' WHERE scan_id=%d ORDER BY seo_score DESC, incoming_count DESC LIMIT 1', $scan ), ARRAY_A );
    }

    private function page_options( $selected = 0 ) {
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan ) { return '<option value="0">' . esc_html__( 'No completed scan', 'dma-internlink-mapper' ) . '</option>'; }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id,title FROM ' . ILSM_Database::table( 'pages' ) . ' WHERE scan_id=%d ORDER BY title ASC LIMIT 1000', $scan ) );
        $html = '';
        foreach ( $rows as $row ) {
            $html .= '<option value="' . absint( $row->post_id ) . '" ' . selected( $selected, $row->post_id, false ) . '>' . esc_html( $this->display_text( $row->title ) ) . '</option>';
        }
        return $html ?: '<option value="0">' . esc_html__( 'No pages found', 'dma-internlink-mapper' ) . '</option>';
    }

    private function link_preview_table() {
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
        $rows = $scan ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ILSM_Database::table( 'links' ) . ' WHERE scan_id=%d ORDER BY id DESC LIMIT 5', $scan ) ) : array();
        $html = '<div class="ilsm-table-scroll"><table class="ilsm-table ilsm-table-compact"><thead><tr><th>' . esc_html__( 'Source', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Anchor', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Target', 'dma-internlink-mapper' ) . '</th><th>' . esc_html__( 'Status', 'dma-internlink-mapper' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $html .= '<tr><td>' . esc_html( $this->display_text( $r->source_title ) ) . '</td><td>' . esc_html( $this->display_text( $r->anchor_text ?: '—' ) ) . '</td><td>' . esc_html( $this->display_text( $r->target_title ?: $r->target_url ) ) . '</td><td>' . $this->status_badge( $r->issue_type ) . '</td></tr>';
        }
        if ( ! $rows ) { $html .= '<tr><td colspan="4" class="ilsm-empty-cell">' . esc_html__( 'Run your first scan to populate the report.', 'dma-internlink-mapper' ) . '</td></tr>'; }
        return $html . '</tbody></table></div>';
    }

    private function status_badge( $issue ) {
        if ( 'broken' === $issue ) { return '<span class="ilsm-badge is-danger">' . esc_html__( 'Broken', 'dma-internlink-mapper' ) . '</span>'; }
        if ( 'redirect' === $issue ) { return '<span class="ilsm-badge is-purple">' . esc_html__( 'Redirect', 'dma-internlink-mapper' ) . '</span>'; }
        if ( in_array( $issue, array( 'weak_anchor', 'empty_anchor' ), true ) ) { return '<span class="ilsm-badge is-warning">' . esc_html__( 'Weak anchor', 'dma-internlink-mapper' ) . '</span>'; }
        return '<span class="ilsm-badge is-success">' . esc_html__( 'Healthy', 'dma-internlink-mapper' ) . '</span>';
    }

    private function recommendations( $page ) {
        if ( ! $page ) { return '<p class="ilsm-muted">' . esc_html__( 'Run a scan to receive recommendations.', 'dma-internlink-mapper' ) . '</p>'; }
        $items = array();
        $items[] = (int) $page['incoming_count'] > 0 ? array( 'ok', 'Page has incoming internal links.' ) : array( 'warn', 'Add at least one relevant incoming link.' );
        $items[] = (int) $page['outgoing_count'] >= 2 ? array( 'ok', 'Outgoing link coverage is healthy.' ) : array( 'warn', 'Add useful contextual outgoing links.' );
        $items[] = (int) $page['weak_anchor_count'] === 0 ? array( 'ok', 'Anchor text is descriptive.' ) : array( 'warn', 'Replace generic or empty anchor text.' );
        $html = '<ul>';
        foreach ( $items as $item ) { $html .= '<li class="is-' . esc_attr( $item[0] ) . '"><i class="fa ' . ( 'ok' === $item[0] ? 'fa-check-circle' : 'fa-exclamation-triangle' ) . '"></i>' . esc_html( $item[1] ) . '</li>'; }
        return $html . '</ul>';
    }

    public function export() {
        if ( ! current_user_can( 'ilsm_export_reports' ) ) { wp_die( esc_html__( 'Permission denied.', 'dma-internlink-mapper' ) ); }
        check_admin_referer( 'ilsm_export' );
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=internal-links-' . absint( $scan ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( __( 'Source', 'dma-internlink-mapper' ), __( 'Anchor', 'dma-internlink-mapper' ), __( 'Target', 'dma-internlink-mapper' ), __( 'Location', 'dma-internlink-mapper' ), __( 'Follow', 'dma-internlink-mapper' ), __( 'Issue', 'dma-internlink-mapper' ) ) );
        $offset = 0;
        do {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. SQL is assembled from fixed clauses and allowlisted identifiers before prepare().
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_title,anchor_text,target_url,link_location,follow_status,issue_type FROM ' . ILSM_Database::table( 'links' ) . ' WHERE scan_id=%d ORDER BY id LIMIT 500 OFFSET %d', $scan, $offset ), ARRAY_A );
            foreach ( $rows as $row ) {
                foreach ( $row as &$value ) {
                    if ( preg_match( '/^[=+\-@\t\r]/', (string) $value ) ) { $value = "'" . $value; }
                }
                unset( $value );
                fputcsv( $out, $row );
            }
            $offset += 500;
        } while ( count( $rows ) === 500 );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the streamed php://output CSV handle is required.
        exit;
    }

    /**
     * Generate a branded, dependency-free PDF audit from the latest completed scan.
     */
    /**
     * Generate a real PDF file for the currently rendered Visual Map.
     *
     * The browser captures only the graph canvas. WordPress validates the
     * request, embeds that local snapshot into the dependency-free PDF
     * report renderer and returns an actual application/pdf download.
     */
    public function export_visual_pdf() {
        if ( ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_die( esc_html__( 'You do not have permission to export this report.', 'dma-internlink-mapper' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'ilsm_export_visual_pdf' );

        $view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'page-architecture';
        if ( ! in_array( $view, array( 'link-map', 'knowledge-graph', 'site-architecture', 'page-architecture' ), true ) ) {
            wp_die( esc_html__( 'Invalid visual map export type.', 'dma-internlink-mapper' ), '', array( 'response' => 400 ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Binary base64 payload is strictly prefix-checked, size-limited, decoded and validated as JPEG below.
        $image_data = isset( $_POST['image'] ) ? wp_unslash( $_POST['image'] ) : '';
        if ( ! is_string( $image_data ) || 0 !== strpos( $image_data, 'data:image/jpeg;base64,' ) ) {
            wp_die( esc_html__( 'The visual snapshot was not supplied in a supported format.', 'dma-internlink-mapper' ), '', array( 'response' => 400 ) );
        }
        $encoded = substr( $image_data, strlen( 'data:image/jpeg;base64,' ) );
        if ( strlen( $encoded ) > 8 * MB_IN_BYTES ) {
            wp_die( esc_html__( 'The visual snapshot is too large to export safely.', 'dma-internlink-mapper' ), '', array( 'response' => 413 ) );
        }
        $jpeg = base64_decode( $encoded, true );
        if ( false === $jpeg || strlen( $jpeg ) < 100 ) {
            wp_die( esc_html__( 'The visual snapshot could not be decoded.', 'dma-internlink-mapper' ), '', array( 'response' => 400 ) );
        }
        $image_info = @getimagesizefromstring( $jpeg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid user-provided image data is handled explicitly below.
        if ( ! is_array( $image_info ) || IMAGETYPE_JPEG !== (int) $image_info[2] || $image_info[0] < 1 || $image_info[1] < 1 ) {
            wp_die( esc_html__( 'The visual snapshot is not a valid JPEG image.', 'dma-internlink-mapper' ), '', array( 'response' => 400 ) );
        }

        $labels = array(
            'link-map'          => __( 'Link Map', 'dma-internlink-mapper' ),
            'knowledge-graph'   => __( 'Knowledge Graph', 'dma-internlink-mapper' ),
            'site-architecture' => __( 'Site Architecture', 'dma-internlink-mapper' ),
            'page-architecture' => __( 'Page Architecture', 'dma-internlink-mapper' ),
        );
        $title = $labels[ $view ];
        $style = isset( $_POST['style'] ) ? sanitize_text_field( wp_unslash( $_POST['style'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        $metrics = array();
        if ( isset( $_POST['metrics'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON container is decoded and every displayed field is sanitized individually below.
            $raw_metrics = json_decode( wp_unslash( $_POST['metrics'] ), true );
            if ( is_array( $raw_metrics ) ) {
                foreach ( array_slice( $raw_metrics, 0, 6 ) as $metric ) {
                    if ( ! is_array( $metric ) ) { continue; }
                    $label = isset( $metric['label'] ) ? sanitize_text_field( $metric['label'] ) : '';
                    $value = isset( $metric['value'] ) ? sanitize_text_field( $metric['value'] ) : '';
                    if ( '' !== $label || '' !== $value ) { $metrics[] = array( $label, $value ); }
                }
            }
        }

        $scan_id = ILSM_Database::latest_completed_scan_id();
        $pdf = new ILSM_PDF_Report();
        /* translators: %d: completed scan ID. */
        $scan_label = $scan_id ? sprintf( __( 'Completed scan #%d', 'dma-internlink-mapper' ), $scan_id ) : __( 'Completed scan', 'dma-internlink-mapper' );
        $pdf->report_header(
            /* translators: %s: report title. */
            sprintf( __( '%s Report', 'dma-internlink-mapper' ), $title ),
            home_url( '/' ),
            $scan_label,
            /* translators: %s: localized report generation date and time. */
            sprintf( __( 'Generated: %s', 'dma-internlink-mapper' ), wp_date( 'j F Y, H:i' ) )
        );

        $pdf->rounded_rect( 34, 652, 527, 48, 10, array( 248, 250, 252 ), array( 218, 226, 238 ) );
        $pdf->set_color( 79, 70, 229 );
        $pdf->text( 48, 683, __( 'VISUAL REPORT', 'dma-internlink-mapper' ), 7.2, true );
        $context_line = $style ? $style : $title;
        $pdf->set_color( 51, 65, 85 );
        $pdf->text( 48, 666, $this->display_text( $context_line ), 8.4, true );
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( $post instanceof WP_Post ) {
                $pdf->set_color( 15, 23, 42 );
                $pdf->text( 318, 676, $this->display_text( $pdf->shorten( get_the_title( $post ), 40 ) ), 8.2, true );
                $pdf->set_color( 100, 116, 139 );
                $pdf->text( 318, 660, $this->display_text( $pdf->shorten( get_permalink( $post ), 54 ) ), 6.8 );
            }
        }

        $accents = array(
            array( 37, 99, 235 ), array( 16, 185, 129 ), array( 124, 58, 237 ),
            array( 249, 115, 22 ), array( 239, 68, 68 ), array( 14, 165, 233 ),
        );
        $metric_y = 570;
        $card_w = 164;
        foreach ( $metrics as $index => $metric ) {
            $row = (int) floor( $index / 3 );
            $col = $index % 3;
            $x = 34 + ( $col * 176 );
            $y = $metric_y - ( $row * 78 );
            $pdf->metric_card( $x, $y, $card_w, $metric[0], $metric[1], '', $accents[ $index % count( $accents ) ] );
        }

        $graph_top = count( $metrics ) > 3 ? 474 : 552;
        $graph_bottom = 88;
        $box_x = 34;
        $box_y = $graph_bottom;
        $box_w = 527;
        $box_h = max( 250, $graph_top - $graph_bottom );
        $pdf->rounded_rect( $box_x, $box_y, $box_w, $box_h, 9, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 15, 23, 42 );
        $pdf->text( $box_x + 14, $box_y + $box_h - 21, __( 'Relationship map', 'dma-internlink-mapper' ), 10.2, true );
        $pdf->set_color( 100, 116, 139 );
        $pdf->text( $box_x + 14, $box_y + $box_h - 36, __( 'Snapshot of the map currently rendered in WordPress.', 'dma-internlink-mapper' ), 6.8 );

        $inner_x = $box_x + 14;
        $inner_y = $box_y + 14;
        $inner_w = $box_w - 28;
        $inner_h = $box_h - 60;
        $ratio = min( $inner_w / (float) $image_info[0], $inner_h / (float) $image_info[1] );
        $draw_w = max( 1, $image_info[0] * $ratio );
        $draw_h = max( 1, $image_info[1] * $ratio );
        $draw_x = $inner_x + ( $inner_w - $draw_w ) / 2;
        $draw_y = $inner_y + ( $inner_h - $draw_h ) / 2;
        $pdf->jpeg_image( $jpeg, (int) $image_info[0], (int) $image_info[1], $draw_x, $draw_y, $draw_w, $draw_h );

        if ( 'link-map' === $view ) {
            $legend = array(
                array( __( 'Incoming', 'dma-internlink-mapper' ), array( 37, 99, 235 ) ),
                array( __( 'Outgoing', 'dma-internlink-mapper' ), array( 249, 115, 22 ) ),
                array( __( 'External', 'dma-internlink-mapper' ), array( 172, 33, 116 ) ),
                array( __( 'Broken', 'dma-internlink-mapper' ), array( 239, 68, 68 ) ),
                array( __( 'Redirect', 'dma-internlink-mapper' ), array( 139, 92, 246 ) ),
            );
            $legend_x = 44;
            foreach ( $legend as $item ) {
                $pdf->filled_circle( $legend_x, 62, 3, $item[1][0], $item[1][1], $item[1][2] );
                $pdf->set_color( 71, 85, 105 );
                $pdf->text( $legend_x + 7, 59.5, $item[0], 6.7, true );
                $legend_x += 94;
            }
        }

        $filename = 'dma-internlink-mapper-' . sanitize_title( $title );
        if ( $post_id ) { $filename .= '-post-' . $post_id; }
        $filename .= '.pdf';
        $pdf->output( $filename );
    }

    public function export_pdf() {
        if ( ! current_user_can( 'ilsm_export_reports' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'dma-internlink-mapper' ) );
        }
        check_admin_referer( 'ilsm_export_pdf' );

        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan ) {
            wp_die( esc_html__( 'Run a complete scan before exporting a PDF report.', 'dma-internlink-mapper' ) );
        }

        $stats = $this->stats();
        $pages_table = ILSM_Database::table( 'pages' );
        $links_table = ILSM_Database::table( 'links' );
        $scan_table  = ILSM_Database::table( 'scans' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $scan_row = $wpdb->get_row( $wpdb->prepare( "SELECT started_at,completed_at FROM {$scan_table} WHERE id=%d", $scan ), ARRAY_A );

        $safe_ratio = static function( $part, $whole ) {
            return $whole > 0 ? min( 1, max( 0, $part / $whole ) ) : 0;
        };
        $pages = max( 1, (int) $stats['pages'] );
        $links = max( 1, (int) $stats['links'] );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $pages_with_incoming = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pages_table} WHERE scan_id=%d AND incoming_count>0", $scan ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $avg_outgoing = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(AVG(outgoing_count),0) FROM {$pages_table} WHERE scan_id=%d", $scan ) );

        $components = array(
            array( __( 'Broken links', 'dma-internlink-mapper' ), 25, 25 * max( 0, 1 - ( $safe_ratio( $stats['broken'], $links ) * 20 ) ), __( 'Broken links divided by all discovered links.', 'dma-internlink-mapper' ) ),
            array( __( 'Orphan pages', 'dma-internlink-mapper' ), 20, 20 * max( 0, 1 - $safe_ratio( $stats['orphans'], $pages ) ), __( 'Pages with no incoming internal links.', 'dma-internlink-mapper' ) ),
            array( __( 'Anchor quality', 'dma-internlink-mapper' ), 15, 15 * max( 0, 1 - ( $safe_ratio( $stats['weak'], $links ) * 5 ) ), __( 'Weak or empty anchors divided by all links.', 'dma-internlink-mapper' ) ),
            array( __( 'Redirect health', 'dma-internlink-mapper' ), 10, 10 * max( 0, 1 - ( $safe_ratio( $stats['redirects'], $links ) * 10 ) ), __( 'Redirected links divided by all links.', 'dma-internlink-mapper' ) ),
            array( __( 'Incoming coverage', 'dma-internlink-mapper' ), 20, 20 * $safe_ratio( $pages_with_incoming, $pages ), __( 'Share of indexed pages receiving at least one link.', 'dma-internlink-mapper' ) ),
            array( __( 'Outgoing coverage', 'dma-internlink-mapper' ), 10, 10 * min( 1, $avg_outgoing / 3 ), __( 'Average outgoing links, capped at three useful links per page.', 'dma-internlink-mapper' ) ),
        );
        $score = 0;
        foreach ( $components as $component ) { $score += $component[2]; }
        $score = (int) round( min( 100, max( 0, $score ) ) );
        $grade = $score >= 90 ? __( 'Excellent', 'dma-internlink-mapper' ) : ( $score >= 75 ? __( 'Good', 'dma-internlink-mapper' ) : ( $score >= 60 ? __( 'Needs improvement', 'dma-internlink-mapper' ) : __( 'Critical', 'dma-internlink-mapper' ) ) );

        $pdf = new ILSM_PDF_Report();
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url( '/' );
        $completed = ! empty( $scan_row['completed_at'] ) ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan_row['completed_at'] ) : current_time( 'mysql' );

        // Cover and executive summary.
        $pdf->rect( 0, 0, 595.28, 841.89, 248, 250, 252 );
        $pdf->rect( 0, 680, 595.28, 162, 15, 23, 42 );
        $pdf->set_color( 255, 255, 255 );
        $pdf->text( 42, 782, __( 'DMA INTERNLINK MAPPER', 'dma-internlink-mapper' ), 10, true );
        $pdf->text( 42, 742, __( 'SEO Site Audit Report', 'dma-internlink-mapper' ), 28, true );
        $pdf->set_color( 203, 213, 225 );
        $pdf->text( 42, 716, $site_name, 13 );
        $pdf->text( 42, 698, $site_url, 9 );

        $pdf->circle( 485, 747, 53, 226, 232, 240, 10 );
        $ring = $score >= 75 ? array( 16, 185, 129 ) : ( $score >= 60 ? array( 245, 158, 11 ) : array( 239, 68, 68 ) );
        $pdf->circle( 485, 747, 53, $ring[0], $ring[1], $ring[2], 7 );
        $pdf->set_color( 255, 255, 255 );
        $pdf->text( 466, 743, (string) $score, 24, true );
        $pdf->text( 465, 725, $grade, 7, true );

        $pdf->set_color( 100, 116, 139 );
        $pdf->text( 42, 648, __( 'Latest completed scan', 'dma-internlink-mapper' ), 8, true );
        $pdf->set_color( 15, 23, 42 );
        $pdf->text( 42, 632, $completed, 10 );
        $pdf->set_y( 592 );

        $card_w = 120;
        $gap = 10;
        $x = 42;
        $metrics = array(
            array( __( 'Pages', 'dma-internlink-mapper' ), number_format_i18n( $stats['pages'] ), __( 'Indexed content', 'dma-internlink-mapper' ), array( 37, 99, 235 ) ),
            array( __( 'Internal links', 'dma-internlink-mapper' ), number_format_i18n( $stats['links'] ), __( 'Discovered links', 'dma-internlink-mapper' ), array( 16, 185, 129 ) ),
            array( __( 'Broken links', 'dma-internlink-mapper' ), number_format_i18n( $stats['broken'] ), __( 'Require attention', 'dma-internlink-mapper' ), array( 239, 68, 68 ) ),
            array( __( 'Orphan pages', 'dma-internlink-mapper' ), number_format_i18n( $stats['orphans'] ), __( 'No incoming links', 'dma-internlink-mapper' ), array( 100, 116, 139 ) ),
        );
        foreach ( $metrics as $metric ) {
            $pdf->metric_card( $x, 500, $card_w, $metric[0], $metric[1], $metric[2], $metric[3] );
            $x += $card_w + $gap;
        }
        $x = 42;
        $metrics2 = array(
            array( __( 'Weak anchors', 'dma-internlink-mapper' ), number_format_i18n( $stats['weak'] ), __( 'Generic or empty', 'dma-internlink-mapper' ), array( 245, 158, 11 ) ),
            array( __( 'Redirects', 'dma-internlink-mapper' ), number_format_i18n( $stats['redirects'] ), __( 'Redirected targets', 'dma-internlink-mapper' ), array( 139, 92, 246 ) ),
            array( __( 'Incoming coverage', 'dma-internlink-mapper' ), round( 100 * $safe_ratio( $pages_with_incoming, $pages ) ) . '%', __( 'Pages receiving links', 'dma-internlink-mapper' ), array( 79, 70, 229 ) ),
            array( __( 'Average outgoing', 'dma-internlink-mapper' ), number_format_i18n( $avg_outgoing, 1 ), __( 'Links per page', 'dma-internlink-mapper' ), array( 249, 115, 22 ) ),
        );
        foreach ( $metrics2 as $metric ) {
            $pdf->metric_card( $x, 412, $card_w, $metric[0], $metric[1], $metric[2], $metric[3] );
            $x += $card_w + $gap;
        }

        $pdf->set_color( 15, 23, 42 );
        $pdf->text( 42, 370, __( 'How the SEO health score is calculated', 'dma-internlink-mapper' ), 15, true );
        $pdf->set_color( 100, 116, 139 );
        $pdf->text( 42, 352, __( 'The score is based only on the latest completed local scan. It is not a Google ranking score.', 'dma-internlink-mapper' ), 8 );
        $rows = array();
        foreach ( $components as $component ) {
            $rows[] = array( $component[0], $component[1] . '%', round( $component[2], 1 ) . ' / ' . $component[1], $component[3] );
        }
        $pdf->set_y( 330 );
        $pdf->table( array( __( 'Category', 'dma-internlink-mapper' ), __( 'Weight', 'dma-internlink-mapper' ), __( 'Awarded', 'dma-internlink-mapper' ), __( 'Calculation', 'dma-internlink-mapper' ) ), $rows, array( 115, 55, 70, 265 ), 7 );

        // Priorities and strongest pages.
        $pdf->new_page();
        $pdf->heading( 'Priority recommendations', 'Actions are ordered by likely SEO impact. The report avoids recommending more links merely to increase a number.' );
        $priorities = array();
        if ( $stats['broken'] > 0 ) { $priorities[] = array( 'High', 'Fix ' . number_format_i18n( $stats['broken'] ) . ' broken internal links.', 'Broken links interrupt navigation and waste internal authority.' ); }
        if ( $stats['orphans'] > 0 ) { $priorities[] = array( 'High', 'Review ' . number_format_i18n( $stats['orphans'] ) . ' orphan pages.', 'Add only relevant contextual links from established pages.' ); }
        if ( $stats['weak'] > 0 ) { $priorities[] = array( 'Medium', 'Improve ' . number_format_i18n( $stats['weak'] ) . ' weak or empty anchors.', 'Use descriptive language that explains the destination naturally.' ); }
        if ( $stats['redirects'] > 0 ) { $priorities[] = array( 'Medium', 'Replace ' . number_format_i18n( $stats['redirects'] ) . ' redirected internal targets.', 'Link directly to final destinations where practical.' ); }
        if ( $pages_with_incoming < $pages ) { $priorities[] = array( 'Medium', 'Increase incoming-link coverage.', 'Prioritize valuable pages rather than forcing links to every URL.' ); }
        if ( ! $priorities ) { $priorities[] = array( 'Low', 'Maintain the current link structure.', 'No major internal-link problems were found in this scan.' ); }
        $pdf->table( array( 'Priority', 'Recommended action', 'Why it matters' ), $priorities, array( 65, 210, 230 ), 7.5 );

        $pdf->heading( 'Pages needing attention', 'Pages are ordered by orphan status, broken links, weak anchors, and low SEO score.' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $attention = $wpdb->get_results( $wpdb->prepare( "SELECT title,post_type,incoming_count,outgoing_count,broken_count,weak_anchor_count,seo_score FROM {$pages_table} WHERE scan_id=%d ORDER BY is_orphan DESC,broken_count DESC,weak_anchor_count DESC,seo_score ASC LIMIT 20", $scan ), ARRAY_A );
        $rows = array();
        foreach ( $attention as $row ) {
            $rows[] = array( $this->display_text( $row['title'] ), $row['post_type'], $row['incoming_count'], $row['outgoing_count'], $row['broken_count'], $row['weak_anchor_count'], $row['seo_score'] );
        }
        $pdf->table( array( 'Page', 'Type', 'In', 'Out', 'Broken', 'Weak', 'Score' ), $rows, array( 225, 60, 35, 35, 45, 40, 45 ), 7 );

        // Detailed issue appendix.
        $pdf->new_page();
        $pdf->heading( 'Issue appendix', 'A concise sample of actionable issues from the latest scan. CSV export remains available for the complete raw dataset.' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale. Only fixed or allowlisted SQL identifiers are interpolated.
        $issue_rows = $wpdb->get_results( $wpdb->prepare( "SELECT source_title,anchor_text,target_url,issue_type,http_status FROM {$links_table} WHERE scan_id=%d AND issue_type<>'' ORDER BY FIELD(issue_type,'broken','redirect','empty_anchor','weak_anchor'),id DESC LIMIT 60", $scan ), ARRAY_A );
        $rows = array();
        foreach ( $issue_rows as $row ) {
            $status = $row['http_status'] ? (string) $row['http_status'] : '-';
            $rows[] = array(
                $this->display_text( $row['issue_type'] ),
                $this->display_text( $row['source_title'] ),
                $this->display_text( $row['anchor_text'] ?: '(empty)' ),
                $this->display_text( $row['target_url'] ),
                $status,
            );
        }
        if ( ! $rows ) { $rows[] = array( 'Healthy', 'No sampled issues', '-', '-', '-' ); }
        $pdf->table( array( 'Issue', 'Source', 'Anchor', 'Target', 'HTTP' ), $rows, array( 65, 130, 105, 170, 35 ), 6.8 );

        $pdf->heading( 'Report notes', 'This report analyses internal links stored in the latest completed scan. It does not claim to predict rankings. Recommendations should be reviewed by an editor and applied only when they improve context and user experience.' );
        $filename = 'internal-link-seo-audit-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.pdf';
        $pdf->output( $filename );
    }


    /**
     * Export the Knowledge Graph as either a selected-page report or a full site-wide audit.
     * All values come from one completed scan snapshot.
     */
    public function export_knowledge_pdf() {
        if ( ! current_user_can( 'ilsm_export_reports' ) ) { wp_die( esc_html__( 'Permission denied.', 'dma-internlink-mapper' ) ); }
        check_admin_referer( 'ilsm_export_knowledge_pdf' );
        $scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'site'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
        if ( 'page' === $scope ) {
            $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
            $this->export_selected_page_pdf( $post_id );
        }
        $this->export_sitewide_pdf();
    }

    private function pdf_scan_context() {
        global $wpdb;
        $scan = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan ) { wp_die( esc_html__( 'Run a complete scan before exporting a report.', 'dma-internlink-mapper' ) ); }
        $scan_table = ILSM_Database::table( 'scans' );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned allowlisted table; fresh snapshot metadata is required.
        $scan_row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT id,started_at,completed_at,scanned_items,total_items FROM {$scan_table} WHERE id=%d", $scan ), ARRAY_A );
        return array( 'scan_id'=>$scan, 'scan'=>$scan_row, 'site_name'=>get_bloginfo('name'), 'site_url'=>home_url('/'), 'generated'=>current_time('mysql') );
    }

    private function pdf_status_label( $issue ) {
        if ( 'broken' === $issue ) return 'Broken'; if ( 'redirect' === $issue ) return 'Redirect';
        if ( 'weak_anchor' === $issue ) return 'Weak anchor'; if ( 'empty_anchor' === $issue ) return 'Empty anchor'; return 'Healthy';
    }

    private function pdf_architecture_score( $pages, $internal_links, $orphans, $broken, $pages_without_outgoing ) {
        $page_count=max(1,(int)$pages);$link_count=max(1,(int)$internal_links);
        $penalty=(($orphans/$page_count)*30)+(($broken/$link_count)*30)+(($pages_without_outgoing/$page_count)*20);
        return max(0,min(100,(int)round(100-$penalty)));
    }

    private function export_sitewide_pdf() {
        global $wpdb;
        $ctx  = $this->pdf_scan_context();
        $scan = (int) $ctx['scan_id'];
        $p    = ILSM_Database::checked_table( ILSM_Database::table( 'pages' ) );
        $l    = ILSM_Database::checked_table( ILSM_Database::table( 'links' ) );
        $i    = ILSM_Database::checked_table( ILSM_Database::table( 'issues' ) );

        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only export from plugin-owned allowlisted tables. One completed scan ID is used consistently throughout the report.
        $pages     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE scan_id=%d", $scan ) );
        $internal  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external'", $scan ) );
        $external  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type='external'", $scan ) );
        $orphans   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE scan_id=%d AND is_orphan=1", $scan ) );
        $broken    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type='broken'", $scan ) );
        $redirects = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type='redirect'", $scan ) );
        $external_broken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type='external' AND issue_type='broken'", $scan ) );
        $external_redirects = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type='external' AND issue_type='redirect'", $scan ) );
        $weak      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type IN ('weak_anchor','empty_anchor')", $scan ) );
        $no_out    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE scan_id=%d AND outgoing_count=0", $scan ) );
        $avg_seo   = (int) round( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(AVG(seo_score),0) FROM {$p} WHERE scan_id=%d AND seo_verified=1", $scan ) ) );
        $score     = $this->pdf_architecture_score( $pages, $internal, $orphans, $broken, $no_out );
        $scan_types = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_type FROM {$p} WHERE scan_id=%d AND post_type<>''", $scan ) );
        if ( $scan_types ) {
            $architecture = ILSM_Architecture_Service::instance()->build( array( 'mode'=>'knowledge', 'root_id'=>0, 'max_depth'=>0, 'post_types'=>array_values( array_map( 'sanitize_key', $scan_types ) ), 'status'=>'all', 'min_in'=>0, 'min_out'=>0 ) );
            if ( ! is_wp_error( $architecture ) && isset( $architecture['meta']['totals']['architecture_score'] ) ) { $score = (int) $architecture['meta']['totals']['architecture_score']; }
        }
        $top_pages = $wpdb->get_results( $wpdb->prepare( "SELECT post_id,title,url,incoming_count,outgoing_count,seo_score,is_orphan FROM {$p} WHERE scan_id=%d ORDER BY incoming_count DESC,outgoing_count DESC LIMIT 80", $scan ), ARRAY_A );
        $top_ids = array_map( 'absint', array_column( $top_pages, 'post_id' ) );
        $graph_edges = array();
        if ( $top_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $top_ids ), '%d' ) );
            $args = array_merge( array( $scan ), $top_ids, $top_ids );
            $graph_edges = $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id,target_post_id FROM {$l} WHERE scan_id=%d AND source_post_id IN ({$placeholders}) AND target_post_id IN ({$placeholders}) AND destination_type<>'external'", ...$args ), ARRAY_A );
        }
        $broken_rows = $wpdb->get_results( $wpdb->prepare( "SELECT target_url,COUNT(*) AS instances FROM {$l} WHERE scan_id=%d AND issue_type='broken' GROUP BY target_url ORDER BY instances DESC LIMIT 5", $scan ), ARRAY_A );
        $redirect_rows = $wpdb->get_results( $wpdb->prepare( "SELECT target_url,redirect_url,http_status,COUNT(*) AS instances FROM {$l} WHERE scan_id=%d AND issue_type='redirect' GROUP BY target_url,redirect_url,http_status ORDER BY instances DESC LIMIT 5", $scan ), ARRAY_A );
        $orphan_rows = $wpdb->get_results( $wpdb->prepare( "SELECT title,url FROM {$p} WHERE scan_id=%d AND is_orphan=1 ORDER BY title LIMIT 5", $scan ), ARRAY_A );
        $heading_rows = $wpdb->get_results( $wpdb->prepare( "SELECT issue_type,COUNT(*) AS total FROM {$i} WHERE scan_id=%d AND issue_type IN ('missing_h1','multiple_h1','empty_heading','skipped_heading_level') GROUP BY issue_type", $scan ), ARRAY_A );
        // phpcs:enable

        $heading_counts = array( 'missing_h1'=>0, 'multiple_h1'=>0, 'empty_heading'=>0, 'skipped_heading_level'=>0 );
        foreach ( $heading_rows as $row ) { $heading_counts[ $row['issue_type'] ] = (int) $row['total']; }
        $crawl_score = $broken > 0 ? max( 65, 100 - min( 35, $broken * 2 ) ) : 100;
        $coverage_score = $pages > 0 ? max( 0, 100 - (int) round( ( $orphans / max( 1, $pages ) ) * 100 ) ) : 0;
        $content_score = max( 0, min( 100, $avg_seo ? $avg_seo : $score ) );
        $link_score = $score;
        $https_score = is_ssl() ? 100 : 0;

        $pdf = new ILSM_PDF_Report( 'DMA InternLink Mapper' );
        $pdf->report_header( 'Site-wide Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Generated: ' . $ctx['generated'] );

        // Executive summary band.
        $pdf->rounded_rect( 24, 650, 547, 64, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 690, 'EXECUTIVE SUMMARY', 8.8, true );
        $pdf->set_color( 30, 41, 59 ); $pdf->wrapped_text( 34, 674, 'This site-wide report provides a comprehensive overview of internal linking structure, external-link health, URL coverage and technical link issues based on the latest completed local scan.', 62, 6.8, 9, false, 4 );
        $pdf->icon_metric( 243, 666, 74, 'Pages Scanned', number_format_i18n( $pages ), array( 37, 99, 235 ), '□' );
        $pdf->icon_metric( 326, 666, 74, 'Internal Links', number_format_i18n( $internal ), array( 22, 163, 74 ), '↗' );
        $pdf->icon_metric( 409, 666, 74, 'Orphan Pages', number_format_i18n( $orphans ), array( 124, 58, 237 ), '◎' );
        $pdf->icon_metric( 492, 666, 74, 'SEO Health', $score . '/100', array( 239, 68, 68 ), '♡' );

        // Top visual cards.
        $pdf->rounded_rect( 24, 509, 126, 128, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 620, 'OVERALL SEO HEALTH', 8.2, true );
        $pdf->progress_ring( 87, 573, 38, $score, 16 );
        $pdf->pill( 72, 521, $score >= 90 ? 'Good' : ( $score >= 70 ? 'Review' : 'Needs work' ), array( 220, 252, 231 ), array( 22, 101, 52 ), 36 );

        $pdf->rounded_rect( 158, 509, 158, 128, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 168, 620, 'LINK DISTRIBUTION', 8.2, true );
        $total_links = max( 1, $internal + $external );
        $pdf->donut_chart( 213, 570, 31, array( array( $internal, array( 34, 197, 94 ), 'Internal' ), array( $external, array( 249, 115, 22 ), 'External' ) ), number_format_i18n( $total_links ), 'Total Links' );
        $pdf->legend_item( 258, 591, 'Internal Links', number_format_i18n( $internal ), array( 34, 197, 94 ) );
        $pdf->legend_item( 258, 571, 'External Links', number_format_i18n( $external ), array( 249, 115, 22 ) );
        $pdf->legend_item( 258, 551, 'Redirects', number_format_i18n( $redirects ), array( 37, 99, 235 ) );
        $pdf->legend_item( 258, 531, 'Broken Links', number_format_i18n( $broken ), array( 239, 68, 68 ) );

        $pdf->rounded_rect( 324, 509, 126, 128, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 334, 620, 'PAGES BY STATUS', 8.2, true );
        $pdf->donut_chart( 381, 570, 31, array( array( $indexable, array( 34, 197, 94 ), 'Indexable' ), array( $orphans, array( 249, 115, 22 ), 'Review' ) ), number_format_i18n( $pages ), 'Total Pages' );
        $pdf->legend_item( 420, 584, 'Indexable', number_format_i18n( $indexable ), array( 34, 197, 94 ) );
        $pdf->legend_item( 420, 564, 'Review', number_format_i18n( $orphans ), array( 249, 115, 22 ) );

        $pdf->rounded_rect( 458, 509, 113, 128, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 468, 620, 'LINK HEALTH', 8.2, true );
        $pdf->score_list( 468, 591, array( array( 'Crawlability', $crawl_score ), array( 'Linked Coverage', $coverage_score ), array( 'Content Quality', $content_score ), array( 'Link Health', $link_score ) ) );

        // Architecture and top incoming.
        $pdf->rounded_rect( 24, 305, 360, 190, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 478, 'SITE ARCHITECTURE OVERVIEW', 8.2, true );
        $pdf->stat_box( 34, 431, 78, 'Scanned Pages', number_format_i18n( $pages ), array( 22, 163, 74 ) );
        $pdf->stat_box( 34, 375, 78, 'Orphan Pages', number_format_i18n( $orphans ), array( 239, 68, 68 ) );
        $pdf->stat_box( 34, 319, 78, 'Architecture Health', $score . '/100', array( 124, 58, 237 ) );
        $pdf->graph_snapshot( $top_pages, $graph_edges, 122, 319, 252, 156, 0 );
        $pdf->set_color( 100, 116, 139 ); $pdf->text( 34, 308, 'Each node represents a scanned page. Node size reflects incoming-link support.', 5.8 );

        $pdf->rounded_rect( 392, 305, 179, 190, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 402, 478, 'TOP PAGES BY INCOMING LINKS', 8.2, true );
        $rows = array(); foreach ( array_slice( $top_pages, 0, 8 ) as $r ) { $rows[] = array( $this->display_text( $r['title'] ), $r['incoming_count'] ); }
        if ( ! $rows ) { $rows[] = array( 'No pages found', 0 ); }
        $yy = 455; foreach ( $rows as $row ) { $pdf->set_color( 15, 23, 42 ); $pdf->text( 402, $yy, $this->display_text( $row[0] ), 6.2, true ); $pdf->set_color( 15, 23, 42 ); $pdf->text( 547, $yy, (string) $row[1], 6.2, true ); $pdf->line( 402, $yy - 8, 558, $yy - 8, 226, 232, 240, .35 ); $yy -= 18; }

        // Lower metric and issue cards.
        $pdf->rounded_rect( 24, 226, 190, 66, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 274, 'INTERNAL LINKING OVERVIEW', 8.2, true );
        $pdf->stat_box( 34, 237, 40, 'Incoming', $top_pages ? (int) $top_pages[0]['incoming_count'] : 0, array( 22, 163, 74 ) );
        $pdf->stat_box( 80, 237, 40, 'Outgoing', $top_pages ? (int) $top_pages[0]['outgoing_count'] : 0, array( 37, 99, 235 ) );
        $pdf->stat_box( 126, 237, 40, 'Broken', $broken, array( 239, 68, 68 ) );
        $pdf->stat_box( 172, 237, 32, 'Weak', $weak, array( 249, 115, 22 ) );

        $pdf->rounded_rect( 222, 226, 160, 66, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 232, 274, 'EXTERNAL LINKS OVERVIEW', 8.2, true );
        $pdf->stat_box( 232, 237, 42, 'External', $external, array( 249, 115, 22 ) );
        $pdf->stat_box( 282, 237, 42, 'Broken ext.', $external_broken, array( 239, 68, 68 ) );
        $pdf->stat_box( 332, 237, 40, 'Redirects', $external_redirects, array( 124, 58, 237 ) );

        $pdf->rounded_rect( 390, 226, 181, 66, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 400, 274, 'TECHNICAL OVERVIEW', 8.2, true );
        $pdf->score_list( 400, 258, array( array( 'HTTPS Active', $https_score ), array( 'Linked Coverage', $coverage_score ), array( 'Link Health', $link_score ) ) );

        $pdf->rounded_rect( 24, 91, 164, 122, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 196, 'TOP ORPHAN PAGES', 8.2, true );
        $yy = 176; if ( ! $orphan_rows ) { $pdf->set_color( 71, 85, 105 ); $pdf->text( 34, $yy, 'No orphan pages found.', 6.4 ); } else { foreach ( $orphan_rows as $r ) { $pdf->set_color( 15, 23, 42 ); $pdf->text( 34, $yy, $this->display_text( $r['title'] ?: $r['url'] ), 6.2, true ); $pdf->pill( 124, $yy - 4, 'No incoming', array( 254, 226, 226 ), array( 185, 28, 28 ), 54 ); $yy -= 19; } }

        $pdf->rounded_rect( 196, 91, 164, 122, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 206, 196, 'TOP BROKEN LINKS', 8.2, true );
        $yy = 176; if ( ! $broken_rows ) { $pdf->set_color( 71, 85, 105 ); $pdf->text( 206, $yy, 'No broken links found.', 6.4 ); } else { foreach ( $broken_rows as $r ) { $pdf->set_color( 15, 23, 42 ); $pdf->text( 206, $yy, $this->display_text( $r['target_url'] ), 5.9 ); $pdf->set_color( 15, 23, 42 ); $pdf->text( 340, $yy, (string) $r['instances'], 6.1, true ); $yy -= 19; } }

        $pdf->rounded_rect( 368, 91, 203, 122, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 378, 196, 'TOP REDIRECTS', 8.2, true );
        $yy = 176; if ( ! $redirect_rows ) { $pdf->set_color( 71, 85, 105 ); $pdf->text( 378, $yy, 'No redirects found.', 6.4 ); } else { foreach ( $redirect_rows as $r ) { $pdf->set_color( 15, 23, 42 ); $pdf->text( 378, $yy, $this->display_text( $r['target_url'] ), 5.8 ); $pdf->set_color( 71, 85, 105 ); $pdf->text( 528, $yy, (string) ( $r['http_status'] ?: '301' ), 5.8 ); $yy -= 19; } }

        // Page 2: detailed link analysis like mockup.
        $pdf->new_page();
        $pdf->report_header( 'Knowledge Graph Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Generated: ' . $ctx['generated'] );
        $pdf->heading( 'Detailed link analysis', 'A sample of the most important link occurrences from the completed scan. Complete appendices continue after the visual summary pages.' );
        $sample = $wpdb->get_results( $wpdb->prepare( "SELECT source_title,anchor_text,target_title,target_url,destination_type,link_location,follow_status,issue_type,http_status FROM {$l} WHERE scan_id=%d ORDER BY destination_type='external',id LIMIT 10", $scan ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only sample from allowlisted table.
        $rows = array(); $n = 1; foreach ( $sample as $r ) { $rows[] = array( $n++, $this->display_text( $r['source_title'] ), $this->display_text( $r['target_title'] ?: $r['target_url'] ), 'external' === $r['destination_type'] ? 'External' : 'Internal', $r['link_location'], $r['follow_status'], $this->pdf_status_label( $r['issue_type'] ), $avg_seo . '/100' ); }
        if ( ! $rows ) { $rows[] = array( '1', 'No links found', '-', '-', '-', '-', '-', '-' ); }
        $pdf->table( array( '#', 'Source Page', 'Target Page', 'Type', 'Location', 'Follow', 'Status', 'SEO' ), $rows, array( 22, 125, 130, 48, 50, 42, 52, 45 ), 5.8 );

        $pdf->rounded_rect( 24, 435, 166, 150, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 566, 'ANCHOR TEXT DISTRIBUTION', 8.2, true );
        $branded = max( 0, $internal - $weak - $redirects - $broken );
        $pdf->donut_chart( 91, 501, 35, array( array( $branded, array( 37, 99, 235 ), 'Healthy' ), array( $weak, array( 249, 115, 22 ), 'Weak' ), array( $redirects, array( 124, 58, 237 ), 'Redirects' ), array( $broken, array( 239, 68, 68 ), 'Broken' ) ), number_format_i18n( $internal ), 'Internal' );
        $pdf->legend_item( 130, 529, 'Healthy anchors', number_format_i18n( $branded ), array( 37, 99, 235 ) ); $pdf->legend_item( 130, 508, 'Weak or empty', number_format_i18n( $weak ), array( 249, 115, 22 ) ); $pdf->legend_item( 130, 487, 'Redirects', number_format_i18n( $redirects ), array( 124, 58, 237 ) );

        $pdf->rounded_rect( 198, 435, 166, 150, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 208, 566, 'LINK TYPE DISTRIBUTION', 8.2, true );
        $pdf->donut_chart( 265, 501, 35, array( array( $internal, array( 34, 197, 94 ), 'Internal' ), array( $external, array( 249, 115, 22 ), 'External' ) ), number_format_i18n( $total_links ), 'Total' );
        $pdf->legend_item( 304, 529, 'Internal Links', number_format_i18n( $internal ), array( 34, 197, 94 ) ); $pdf->legend_item( 304, 508, 'External Links', number_format_i18n( $external ), array( 249, 115, 22 ) ); $pdf->legend_item( 304, 487, 'Broken Links', number_format_i18n( $broken ), array( 239, 68, 68 ) );

        $pdf->rounded_rect( 372, 435, 199, 150, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 382, 566, 'TECHNICAL SEO HEALTH', 8.2, true );
        $pdf->progress_ring( 432, 516, 31, $score, 15 );
        $pdf->score_list( 489, 547, array( array( 'Crawlability', $crawl_score ), array( 'Linked Coverage', $coverage_score ), array( 'Content Quality', $content_score ), array( 'Link Health', $link_score ), array( 'Security (HTTPS)', $https_score ) ) );

        $pdf->rounded_rect( 24, 277, 340, 145, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 405, 'HEADING STRUCTURE OVERVIEW', 8.2, true );
        $pdf->horizontal_bars( 45, 366, 200, array( array( 'Missing H1', $heading_counts['missing_h1'], array( 239, 68, 68 ) ), array( 'Multiple H1', $heading_counts['multiple_h1'], array( 249, 115, 22 ) ), array( 'Empty heading', $heading_counts['empty_heading'], array( 124, 58, 237 ) ), array( 'Skipped level', $heading_counts['skipped_heading_level'], array( 37, 99, 235 ) ) ) );
        $pdf->rounded_rect( 260, 305, 92, 70, 6, array( 248, 250, 252 ), array( 226, 232, 240 ) );
        $pdf->set_color( 15, 23, 42 ); $pdf->text( 270, 357, 'HEADING ISSUES', 6.7, true );
        $pdf->set_color( 71, 85, 105 ); $pdf->text( 270, 340, 'Missing H1: ' . number_format_i18n( $heading_counts['missing_h1'] ), 5.9 ); $pdf->text( 270, 324, 'Multiple H1: ' . number_format_i18n( $heading_counts['multiple_h1'] ), 5.9 ); $pdf->text( 270, 308, 'Skipped levels: ' . number_format_i18n( $heading_counts['skipped_heading_level'] ), 5.9 );

        $pdf->rounded_rect( 372, 277, 199, 145, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 382, 405, 'VISUAL LANGUAGE', 8.2, true );
        $pdf->legend_item( 392, 379, 'Green - healthy', 'Strong / connected', array( 34, 197, 94 ) ); $pdf->legend_item( 392, 352, 'Blue - normal', 'Connected link', array( 37, 99, 235 ) ); $pdf->legend_item( 392, 325, 'Orange - review', 'Needs improvement', array( 249, 115, 22 ) ); $pdf->legend_item( 392, 298, 'Red - problem', 'Attention needed', array( 239, 68, 68 ) );

        $pdf->set_y( 250 );
        $pdf->heading( 'Report appendices', 'The following pages print complete records from the completed scan. No "View all" shortcut is used inside the PDF.' );

        // Complete pages table.
        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only PDF appendix queries use plugin-owned table identifiers that have passed ILSM_Database::checked_table(); values remain prepared.
        $pdf->new_page(); $pdf->report_header( 'Site-wide Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Complete data appendix' ); $pdf->heading( 'All scanned pages', 'Every page in this completed scan snapshot. Table headers repeat automatically on each new PDF page.' );
        $offset = 0; do {
            $chunk = $wpdb->get_results( $wpdb->prepare( "SELECT post_id,title,post_type,incoming_count,outgoing_count,broken_count,weak_anchor_count,is_orphan,seo_score FROM {$p} WHERE scan_id=%d ORDER BY id LIMIT 300 OFFSET %d", $scan, $offset ), ARRAY_A ); $rows = array(); foreach ( $chunk as $r ) { $rows[] = array( $r['post_id'], $this->display_text( $r['title'] ), $r['post_type'], $r['incoming_count'], $r['outgoing_count'], $r['broken_count'], $r['weak_anchor_count'], $r['seo_score'] ); } $pdf->table( array( 'ID', 'Page', 'Type', 'In', 'Out', 'Broken', 'Weak', 'SEO' ), $rows, array( 35, 225, 55, 35, 35, 45, 45, 52 ), 6.2 ); $offset += 300; } while ( count( $chunk ) === 300 );

        $pdf->new_page(); $pdf->report_header( 'Site-wide Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Complete data appendix' ); $pdf->heading( 'All internal links', 'Every scanned internal-link occurrence is printed. Repeated links remain repeated because each occurrence is real crawl evidence.' );
        $offset = 0; do {
            $chunk = $wpdb->get_results( $wpdb->prepare( "SELECT source_title,anchor_text,target_title,target_url,link_location,follow_status,issue_type,http_status FROM {$l} WHERE scan_id=%d AND destination_type<>'external' ORDER BY id LIMIT 300 OFFSET %d", $scan, $offset ), ARRAY_A ); $rows = array(); foreach ( $chunk as $r ) { $rows[] = array( $this->display_text( $r['source_title'] ), $this->display_text( $r['anchor_text'] ?: '(empty)' ), $this->display_text( $r['target_title'] ?: $r['target_url'] ), $r['link_location'], $r['follow_status'], $this->pdf_status_label( $r['issue_type'] ), $r['http_status'] ?: '-' ); } $pdf->table( array( 'Source', 'Anchor', 'Target', 'Location', 'Follow', 'Status', 'HTTP' ), $rows, array( 125, 105, 135, 55, 45, 45, 30 ), 5.9 ); $offset += 300; } while ( count( $chunk ) === 300 );

        $pdf->new_page(); $pdf->report_header( 'Site-wide Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Complete data appendix' ); $pdf->heading( 'All external links', 'Third-party destinations are reported separately and are not mixed into internal-link architecture metrics.' );
        $offset = 0; do {
            $chunk = $wpdb->get_results( $wpdb->prepare( "SELECT source_title,anchor_text,target_url,link_location,follow_status,issue_type,http_status FROM {$l} WHERE scan_id=%d AND destination_type='external' ORDER BY id LIMIT 300 OFFSET %d", $scan, $offset ), ARRAY_A ); $rows = array(); foreach ( $chunk as $r ) { $rows[] = array( $this->display_text( $r['source_title'] ), $this->display_text( $r['anchor_text'] ?: '(empty)' ), $this->display_text( $r['target_url'] ), $r['link_location'], $r['follow_status'], $this->pdf_status_label( $r['issue_type'] ), $r['http_status'] ?: '-' ); } $pdf->table( array( 'Source', 'Anchor', 'External target', 'Location', 'Follow', 'Status', 'HTTP' ), $rows, array( 125, 100, 145, 55, 45, 45, 30 ), 5.9 ); $offset += 300; } while ( count( $chunk ) === 300 );

        $sections = array(
            array( 'Orphan pages', 'Every page marked orphan in this scan.', 'orphans', array( 'Page', 'URL', 'Type', 'SEO', 'Outgoing' ), array( 185, 220, 55, 45, 50 ) ),
            array( 'Broken internal links', 'Every internal link marked broken in this scan.', 'broken', array( 'Source', 'Anchor', 'Target', 'HTTP' ), array( 155, 120, 220, 50 ) ),
            array( 'Redirected internal links', 'Every internal link marked redirected in this scan.', 'redirects', array( 'Source', 'Anchor', 'Target', 'Redirect target', 'HTTP' ), array( 120, 90, 135, 160, 40 ) ),
            array( 'Weak and empty anchors', 'Every internal link marked with weak or empty anchor text.', 'weak_anchors', array( 'Source', 'Anchor', 'Target', 'Issue' ), array( 155, 110, 205, 75 ) ),
            array( 'SEO issue log', 'Every issue stored by the completed scan.', 'issues', array( 'Issue', 'Severity', 'Message', 'Post ID' ), array( 95, 65, 335, 50 ) ),
        );
        foreach ( $sections as $sec ) {
            $pdf->new_page();
            $pdf->report_header( 'Site-wide Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Complete data appendix' );
            $pdf->heading( $sec[0], $sec[1] );
            switch ( $sec[2] ) {
                case 'orphans':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only export; plugin table identifier is strictly allowlisted above.
                    $all = $wpdb->get_results( $wpdb->prepare( "SELECT title,url,post_type,seo_score,outgoing_count FROM {$p} WHERE scan_id=%d AND is_orphan=1 ORDER BY title", $scan ), ARRAY_A );
                    break;
                case 'broken':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only export; plugin table identifier is strictly allowlisted above.
                    $all = $wpdb->get_results( $wpdb->prepare( "SELECT source_title,anchor_text,target_url,http_status FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type='broken' ORDER BY source_title", $scan ), ARRAY_A );
                    break;
                case 'redirects':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only export; plugin table identifier is strictly allowlisted above.
                    $all = $wpdb->get_results( $wpdb->prepare( "SELECT source_title,anchor_text,target_url,redirect_url,http_status FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type='redirect' ORDER BY source_title", $scan ), ARRAY_A );
                    break;
                case 'weak_anchors':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only export; plugin table identifier is strictly allowlisted above.
                    $all = $wpdb->get_results( $wpdb->prepare( "SELECT source_title,anchor_text,target_title,target_url,issue_type FROM {$l} WHERE scan_id=%d AND destination_type<>'external' AND issue_type IN ('weak_anchor','empty_anchor') ORDER BY source_title", $scan ), ARRAY_A );
                    break;
                case 'issues':
                default:
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only export; plugin table identifier is strictly allowlisted above.
                    $all = $wpdb->get_results( $wpdb->prepare( "SELECT issue_type,severity,message,post_id FROM {$i} WHERE scan_id=%d ORDER BY severity DESC,id", $scan ), ARRAY_A );
                    break;
            }
            $rows = array();
            foreach ( $all as $r ) {
                $vals = array_values( $r );
                foreach ( $vals as &$v ) { $v = $this->display_text( $v ); }
                unset( $v );
                $rows[] = $vals;
            }
            if ( ! $rows ) { $rows[] = array_pad( array( 'None found' ), count( $sec[3] ), '-' ); }
            $pdf->table( $sec[3], $rows, $sec[4], 6.1 );
        }
        // phpcs:enable
        $pdf->heading( 'Report integrity note', 'This export contains only data stored for completed scan #' . $scan . '. It does not add traffic, ranking, Core Web Vitals, mobile-friendliness, or other metrics that DMA InternLink Mapper did not measure.' );
        $pdf->output( 'dma-internlink-mapper-site-wide-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-scan-' . $scan . '.pdf' );
    }

    private function export_selected_page_pdf( $post_id ) {
        global $wpdb;
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Select a page in the Knowledge Graph before exporting the selected-page report.', 'dma-internlink-mapper' ) );
        }
        $ctx  = $this->pdf_scan_context();
        $scan = (int) $ctx['scan_id'];
        $p    = ILSM_Database::table( 'pages' );
        $l    = ILSM_Database::table( 'links' );
        $i    = ILSM_Database::table( 'issues' );
        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only export from plugin-owned allowlisted tables.
        $page = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p} WHERE scan_id=%d AND post_id=%d", $scan, $post_id ), ARRAY_A );
        if ( ! $page ) { wp_die( esc_html__( 'The selected page is not present in the latest completed scan.', 'dma-internlink-mapper' ) ); }
        $incoming = $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id,source_title,anchor_text,source_url,link_location,follow_status,issue_type,http_status FROM {$l} WHERE scan_id=%d AND target_post_id=%d AND destination_type<>'external' ORDER BY source_title,id", $scan, $post_id ), ARRAY_A );
        $outgoing = $wpdb->get_results( $wpdb->prepare( "SELECT target_post_id,target_title,anchor_text,target_url,destination_type,link_location,follow_status,issue_type,http_status FROM {$l} WHERE scan_id=%d AND source_post_id=%d ORDER BY destination_type,target_title,id", $scan, $post_id ), ARRAY_A );
        $issues   = $wpdb->get_results( $wpdb->prepare( "SELECT issue_type,severity,message FROM {$i} WHERE scan_id=%d AND post_id=%d ORDER BY severity DESC,id", $scan, $post_id ), ARRAY_A );
        $neighbors = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p} WHERE scan_id=%d AND post_id IN (SELECT source_post_id FROM {$l} WHERE scan_id=%d AND target_post_id=%d UNION SELECT target_post_id FROM {$l} WHERE scan_id=%d AND source_post_id=%d) ORDER BY incoming_count DESC LIMIT 80", $scan, $scan, $post_id, $scan, $post_id ), ARRAY_A );
        $graph_nodes = array_merge( array( $page ), $neighbors );
        $graph_edges = $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id,target_post_id FROM {$l} WHERE scan_id=%d AND (source_post_id=%d OR target_post_id=%d) AND destination_type<>'external'", $scan, $post_id, $post_id ), ARRAY_A );
        $total_pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE scan_id=%d", $scan ) );
        $total_internal = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type<>'external'", $scan ) );
        $total_external = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE scan_id=%d AND destination_type='external'", $scan ) );
        $total_orphans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE scan_id=%d AND is_orphan=1", $scan ) );
        // phpcs:enable

        $external_count = 0; $external_broken = 0; $internal_out = 0; $out_broken = 0; $out_redirect = 0;
        foreach ( $outgoing as $r ) {
            if ( 'external' === $r['destination_type'] ) { $external_count++; if ( 'broken' === $r['issue_type'] ) { $external_broken++; } } else { $internal_out++; }
            if ( 'broken' === $r['issue_type'] ) { $out_broken++; }
            if ( 'redirect' === $r['issue_type'] ) { $out_redirect++; }
        }
        $score = (int) $page['seo_score'];
        $authority = max( 0, min( 100, (int) round( ( (int) $page['incoming_count'] * 1.2 ) + ( (int) $page['outgoing_count'] * .35 ) ) ) );
        $architecture_score = $this->pdf_architecture_score( max( 1, $total_pages ), max( 1, $total_internal ), $total_orphans, 0, 0 );

        $pdf = new ILSM_PDF_Report( 'DMA InternLink Mapper' );
        $pdf->report_header( 'Knowledge Graph Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Generated: ' . $ctx['generated'] );

        // Executive summary like mockup page 1.
        $pdf->rounded_rect( 24, 650, 547, 64, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 690, 'EXECUTIVE SUMMARY', 8.8, true );
        $pdf->set_color( 30, 41, 59 );
        $pdf->wrapped_text( 34, 674, 'This selected-page report shows the current page neighborhood inside the site knowledge graph. It uses only the latest completed local scan and does not add unmeasured metrics.', 62, 6.8, 9, false, 4 );
        $pdf->icon_metric( 243, 666, 74, 'Pages Scanned', number_format_i18n( $total_pages ), array( 37, 99, 235 ), '□' );
        $pdf->icon_metric( 326, 666, 74, 'Internal Links', number_format_i18n( $total_internal ), array( 22, 163, 74 ), '↗' );
        $pdf->icon_metric( 409, 666, 74, 'Orphan Pages', number_format_i18n( $total_orphans ), array( 124, 58, 237 ), '◎' );
        $pdf->icon_metric( 492, 666, 74, 'SEO Health', $score . '/100', array( 239, 68, 68 ), '♡' );

        $pdf->rounded_rect( 24, 295, 388, 342, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 620, 'SITE ARCHITECTURE OVERVIEW', 8.6, true );
        $pdf->graph_snapshot( $graph_nodes, $graph_edges, 34, 355, 368, 245, $post_id );
        $pdf->legend_item( 44, 324, 'Incoming', number_format_i18n( count( $incoming ) ), array( 16, 185, 129 ) );
        $pdf->legend_item( 124, 324, 'Outgoing', number_format_i18n( count( $outgoing ) ), array( 37, 99, 235 ) );
        $pdf->legend_item( 204, 324, 'External', number_format_i18n( $external_count ), array( 249, 115, 22 ) );
        $pdf->legend_item( 284, 324, 'Selected page', $this->display_text( $page['title'] ), array( 124, 58, 237 ) );

        $pdf->rounded_rect( 420, 536, 151, 101, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 430, 620, 'BRAIN SIGNALS', 8.6, true );
        $pdf->set_color( 15, 23, 42 ); $pdf->text( 430, 595, 'Indexed pages', 7.1 ); $pdf->text( 546, 595, number_format_i18n( $total_pages ), 7.1, true );
        $pdf->text( 430, 573, 'Orphan pages', 7.1 ); $pdf->text( 546, 573, number_format_i18n( $total_orphans ), 7.1, true );
        $pdf->text( 430, 551, 'Architecture health', 7.1 ); $pdf->text( 536, 551, $architecture_score . '/100', 7.1, true );

        $pdf->rounded_rect( 420, 295, 151, 229, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 430, 506, 'SELECTED PAGE INSIGHTS', 8.6, true );
        $pdf->filled_circle( 433, 483, 3.5, 34, 197, 94 ); $pdf->set_color( 15, 23, 42 ); $pdf->text( 442, 481, $this->display_text( $page['title'] ), 7.2, true );
        $pdf->set_color( 100, 116, 139 ); $pdf->text( 442, 466, $this->display_text( $page['url'] ), 5.8 );
        $pdf->line( 430, 454, 558, 454, 226, 232, 240, .4 );
        $pdf->progress_ring( 459, 418, 24, $score, 13 ); $pdf->progress_ring( 528, 418, 24, (int) min( 100, count( $incoming ) ), 13 );
        $pdf->set_color( 71, 85, 105 ); $pdf->text( 439, 383, 'SEO Score', 6.1 ); $pdf->text( 506, 383, 'Incoming Links', 6.1 );
        $pdf->progress_ring( 459, 344, 24, (int) min( 100, count( $outgoing ) ), 13 ); $pdf->progress_ring( 528, 344, 24, $authority, 13 );
        $pdf->set_color( 71, 85, 105 ); $pdf->text( 436, 309, 'Outgoing Links', 6.1 ); $pdf->text( 511, 309, 'Authority', 6.1 );

        $pdf->rounded_rect( 24, 219, 388, 64, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 264, 'INTERNAL LINKING OVERVIEW', 8.6, true );
        $pdf->stat_box( 44, 230, 70, 'Incoming Links', count( $incoming ), array( 22, 163, 74 ) );
        $pdf->stat_box( 130, 230, 70, 'Outgoing Links', $internal_out, array( 37, 99, 235 ) );
        $pdf->stat_box( 216, 230, 70, 'Broken Links', $out_broken, array( 239, 68, 68 ) );
        $pdf->stat_box( 302, 230, 70, 'Redirects', $out_redirect, array( 249, 115, 22 ) );

        $pdf->rounded_rect( 420, 219, 151, 64, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 430, 264, 'EXTERNAL LINKS OVERVIEW', 8.6, true );
        $pdf->stat_box( 430, 230, 62, 'External', $external_count, array( 249, 115, 22 ) );
        $pdf->stat_box( 500, 230, 62, 'Broken ext.', $external_broken, array( 239, 68, 68 ) );

        $pdf->rounded_rect( 24, 91, 263, 114, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 188, 'TOP INCOMING LINKS TO SELECTED PAGE', 8.2, true );
        $yy = 166; if ( ! $incoming ) { $pdf->set_color( 71, 85, 105 ); $pdf->text( 34, $yy, 'No incoming internal links found.', 6.3 ); } else { foreach ( array_slice( $incoming, 0, 5 ) as $r ) { $pdf->set_color( 15, 23, 42 ); $pdf->text( 34, $yy, $this->display_text( $r['source_title'] ), 6.1, true ); $pdf->set_color( 100, 116, 139 ); $pdf->text( 34, $yy - 10, 'Anchor: ' . $this->display_text( $r['anchor_text'] ?: 'No anchor text recorded' ), 5.5 ); $pdf->pill( 259, $yy - 4, $r['http_status'] ? (string) $r['http_status'] : 'OK', array( 220, 252, 231 ), array( 22, 101, 52 ), 22 ); $yy -= 20; } }

        $pdf->rounded_rect( 299, 91, 272, 114, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 309, 188, 'TOP OUTGOING LINKS FROM SELECTED PAGE', 8.2, true );
        $yy = 166; if ( ! $outgoing ) { $pdf->set_color( 71, 85, 105 ); $pdf->text( 309, $yy, 'No outgoing links found.', 6.3 ); } else { foreach ( array_slice( $outgoing, 0, 5 ) as $r ) { $pdf->set_color( 15, 23, 42 ); $pdf->text( 309, $yy, $this->display_text( $r['target_title'] ?: $r['target_url'] ), 6.1, true ); $pdf->set_color( 100, 116, 139 ); $pdf->text( 309, $yy - 10, 'Anchor: ' . $this->display_text( $r['anchor_text'] ?: 'No anchor text recorded' ), 5.5 ); $pdf->pill( 539, $yy - 4, 'external' === $r['destination_type'] ? 'EXT' : 'INT', 'external' === $r['destination_type'] ? array( 254, 243, 199 ) : array( 220, 252, 231 ), 'external' === $r['destination_type'] ? array( 146, 64, 14 ) : array( 22, 101, 52 ), 22 ); $yy -= 20; } }

        // Page 2: detailed link table plus charts, similar to the supplied mockup.
        $pdf->new_page();
        $pdf->report_header( 'Knowledge Graph Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Generated: ' . $ctx['generated'] );
        $pdf->heading( 'Detailed link analysis', 'Every selected-page relationship appears in the appendix. This visual page gives a readable scan summary before the complete appendix.' );
        $rows = array(); $n = 1; foreach ( array_slice( array_merge( $incoming, $outgoing ), 0, 10 ) as $r ) { $target = isset( $r['target_title'] ) ? ( $r['target_title'] ?: $r['target_url'] ) : $page['title']; $source = isset( $r['source_title'] ) ? $r['source_title'] : $page['title']; $rows[] = array( $n++, $this->display_text( $source ), $this->display_text( $target ), isset( $r['destination_type'] ) && 'external' === $r['destination_type'] ? 'External' : 'Internal', $r['link_location'], $r['follow_status'], $this->pdf_status_label( $r['issue_type'] ), $score . '/100' ); }
        if ( ! $rows ) { $rows[] = array( '1', 'No links found', '-', '-', '-', '-', '-', '-' ); }
        $pdf->table( array( '#', 'Source / Anchor', 'Target Page', 'Type', 'Location', 'Follow', 'Status', 'SEO' ), $rows, array( 22, 125, 130, 48, 50, 42, 52, 45 ), 5.8 );

        $healthy = max( 0, count( $outgoing ) - $out_broken - $out_redirect );
        $pdf->rounded_rect( 24, 439, 166, 145, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 566, 'ANCHOR TEXT DISTRIBUTION', 8.2, true );
        $pdf->donut_chart( 91, 505, 35, array( array( $healthy, array( 37, 99, 235 ), 'Healthy' ), array( (int) $page['weak_anchor_count'], array( 249, 115, 22 ), 'Weak' ), array( $out_redirect, array( 124, 58, 237 ), 'Redirects' ), array( $out_broken, array( 239, 68, 68 ), 'Broken' ) ), number_format_i18n( count( $outgoing ) ), 'Outgoing' );
        $pdf->legend_item( 130, 529, 'Healthy', number_format_i18n( $healthy ), array( 37, 99, 235 ) ); $pdf->legend_item( 130, 508, 'Weak', number_format_i18n( $page['weak_anchor_count'] ), array( 249, 115, 22 ) ); $pdf->legend_item( 130, 487, 'Broken', number_format_i18n( $out_broken ), array( 239, 68, 68 ) );

        $pdf->rounded_rect( 198, 439, 166, 145, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 208, 566, 'LINK TYPE DISTRIBUTION', 8.2, true );
        $pdf->donut_chart( 265, 505, 35, array( array( $internal_out, array( 34, 197, 94 ), 'Internal' ), array( $external_count, array( 249, 115, 22 ), 'External' ), array( $out_redirect, array( 37, 99, 235 ), 'Redirects' ), array( $out_broken, array( 239, 68, 68 ), 'Broken' ) ), number_format_i18n( count( $outgoing ) ), 'Total' );
        $pdf->legend_item( 304, 529, 'Internal Links', number_format_i18n( $internal_out ), array( 34, 197, 94 ) ); $pdf->legend_item( 304, 508, 'External Links', number_format_i18n( $external_count ), array( 249, 115, 22 ) ); $pdf->legend_item( 304, 487, 'Broken Links', number_format_i18n( $out_broken ), array( 239, 68, 68 ) );

        $pdf->rounded_rect( 372, 439, 199, 145, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 382, 566, 'TECHNICAL SEO HEALTH', 8.2, true );
        $pdf->progress_ring( 432, 516, 31, $score, 15 );
        $pdf->score_list( 489, 547, array( array( 'Page SEO Score', $score ), array( 'Link Health', max( 0, 100 - ( $out_broken * 8 ) - ( $out_redirect * 4 ) ) ), array( 'Internal Support', min( 100, count( $incoming ) ) ), array( 'External Hygiene', $external_broken ? 70 : 100 ) ) );

        $pdf->rounded_rect( 24, 276, 547, 145, 8, array( 255, 255, 255 ), array( 226, 232, 240 ) );
        $pdf->set_color( 79, 70, 229 ); $pdf->text( 34, 404, 'PAGE ISSUE SUMMARY', 8.2, true );
        $yy = 380;
        if ( ! $issues ) { $pdf->set_color( 71, 85, 105 ); $pdf->text( 34, $yy, 'No page-specific issues were stored for this selected page.', 6.6 ); }
        foreach ( array_slice( $issues, 0, 6 ) as $issue ) { $pdf->filled_circle( 34, $yy + 2, 3, 'high' === strtolower( $issue['severity'] ) ? 239 : 249, 'high' === strtolower( $issue['severity'] ) ? 68 : 115, 'high' === strtolower( $issue['severity'] ) ? 68 : 22 ); $pdf->set_color( 15, 23, 42 ); $pdf->text( 44, $yy, $this->display_text( $issue['issue_type'] ), 6.4, true ); $pdf->set_color( 71, 85, 105 ); $pdf->text( 122, $yy, $this->display_text( $issue['message'] ), 6.2 ); $yy -= 18; }

        $pdf->set_y( 248 );
        $pdf->heading( 'Report appendices', 'The following pages print the complete selected-page relationships and issue log.' );

        $pdf->new_page(); $pdf->report_header( 'Selected Page Report', $ctx['site_url'], 'Scan: Completed Scan #' . $scan, 'Complete page relationships' );
        $pdf->heading( 'All incoming internal links', 'Every scanned internal-link occurrence pointing to the selected page.' );
        $rows = array(); foreach ( $incoming as $r ) { $rows[] = array( $this->display_text( $r['source_title'] ), $this->display_text( $r['anchor_text'] ?: '(empty)' ), $r['link_location'], $r['follow_status'], $this->pdf_status_label( $r['issue_type'] ), $r['http_status'] ?: '-' ); } if ( ! $rows ) { $rows[] = array( 'None found', '-', '-', '-', '-', '-' ); } $pdf->table( array( 'Source', 'Anchor', 'Location', 'Follow', 'Status', 'HTTP' ), $rows, array( 185, 135, 55, 50, 60, 40 ), 6.2 );
        $pdf->heading( 'All outgoing links', 'Every scanned outgoing occurrence from the selected page, including external destinations reported separately by type.' );
        $rows = array(); foreach ( $outgoing as $r ) { $rows[] = array( $this->display_text( $r['target_title'] ?: $r['target_url'] ), $this->display_text( $r['anchor_text'] ?: '(empty)' ), 'external' === $r['destination_type'] ? 'External' : 'Internal', $r['link_location'], $this->pdf_status_label( $r['issue_type'] ), $r['http_status'] ?: '-' ); } if ( ! $rows ) { $rows[] = array( 'None found', '-', '-', '-', '-', '-' ); } $pdf->table( array( 'Target', 'Anchor', 'Type', 'Location', 'Status', 'HTTP' ), $rows, array( 180, 135, 55, 55, 60, 40 ), 6.2 );
        $pdf->heading( 'Page issue log', 'Issues are included only when they were stored for this page in the completed scan.' );
        $rows = array(); foreach ( $issues as $r ) { $rows[] = array( $this->display_text( $r['issue_type'] ), $this->display_text( $r['severity'] ), $this->display_text( $r['message'] ) ); } if ( ! $rows ) { $rows[] = array( 'None found', '-', 'No page-specific issues were stored in this scan.' ); } $pdf->table( array( 'Issue', 'Severity', 'Message' ), $rows, array( 110, 70, 345 ), 6.3 );
        $pdf->heading( 'Report integrity note', 'No live page is fetched during this export and no SEO metric is invented. The report is a printable view of completed scan #' . $scan . '.' );
        $pdf->output( 'dma-internlink-mapper-page-' . absint( $post_id ) . '-scan-' . $scan . '.pdf' );
    }



    /**
     * Whether an Elementor document is structural site chrome rather than body content.
     * Header/footer/theme-builder documents are never eligible as contextual-link sources.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private static function ilsm_is_structural_elementor_document( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return false;
        }

        if ( 'elementor_library' === get_post_type( $post_id ) ) {
            $template_type = strtolower( (string) get_post_meta( $post_id, '_elementor_template_type', true ) );
            if ( in_array( $template_type, array( 'header', 'footer' ), true ) ) {
                return true;
            }
        }

        $title = strtolower( (string) get_the_title( $post_id ) );
        $slug  = strtolower( (string) get_post_field( 'post_name', $post_id ) );
        foreach ( array( 'header', 'footer' ) as $structural_name ) {
            if ( $structural_name === $slug || preg_match( '/(^|[-_ ])' . preg_quote( $structural_name, '/' ) . '($|[-_ ])/i', $slug . ' ' . $title ) ) {
                if ( 'elementor_library' === get_post_type( $post_id ) ) {
                    return true;
                }
            }
        }
        return false;
    }
}
