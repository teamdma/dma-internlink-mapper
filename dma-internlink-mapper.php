<?php
/**
 * Plugin Name: DMA InternLink Mapper
 * Plugin URI:  https://desertmoroccoadventure.com/files/internal-link-seo-mapper/
 * Description: Internal-link scans, visual maps, SEO reports, local suggestions, broken-link maintenance, and optional Search Console CSV prioritization.
 * Version:     1.0.0
 * Author:      DMAdventure
 * Author URI:  https://www.desertmoroccoadventure.com/
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dma-internlink-mapper
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ilsm-structural-content.php';

define( 'ILSM_VERSION', '1.0.0' );
define( 'ILSM_DB_VERSION', '1.10.0' );
define( 'ILSM_FILE', __FILE__ );
define( 'ILSM_PATH', plugin_dir_path( __FILE__ ) );
define( 'ILSM_URL', plugin_dir_url( __FILE__ ) );
define( 'ILSM_DOCS_URL', 'https://desertmoroccoadventure.com/files/internal-link-seo-mapper/' );

/**
 * Add convenient links on the WordPress Plugins screen.
 *
 * @param string[] $links Existing plugin action links.
 * @return string[]
 */
function ilsm_plugin_action_links( $links ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return $links;
    }

    $custom_links = array(
        'settings' => sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( admin_url( 'admin.php?page=ilsm-settings' ) ),
            esc_html__( 'Settings', 'dma-internlink-mapper' )
        ),
        'dashboard' => sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( admin_url( 'admin.php?page=ilsm-dashboard' ) ),
            esc_html__( 'Dashboard', 'dma-internlink-mapper' )
        ),
        'documentation' => sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            esc_url( ILSM_DOCS_URL ),
            esc_html__( 'Documentation', 'dma-internlink-mapper' )
        ),
    );

    return array_merge( $custom_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( ILSM_FILE ), 'ilsm_plugin_action_links' );

require_once ILSM_PATH . 'includes/class-ilsm-activator.php';
require_once ILSM_PATH . 'includes/class-ilsm-migrator.php';
require_once ILSM_PATH . 'includes/class-ilsm-site-health.php';
require_once ILSM_PATH . 'includes/class-ilsm-text.php';
require_once ILSM_PATH . 'includes/contracts/interface-ilsm-seo-provider.php';
require_once ILSM_PATH . 'includes/providers/class-ilsm-seo-provider-yoast.php';
require_once ILSM_PATH . 'includes/providers/class-ilsm-seo-provider-rank-math.php';
require_once ILSM_PATH . 'includes/providers/class-ilsm-seo-provider-aioseo.php';
require_once ILSM_PATH . 'includes/providers/class-ilsm-seo-provider-registry.php';
require_once ILSM_PATH . 'includes/contracts/interface-ilsm-editor-adapter.php';
require_once ILSM_PATH . 'includes/adapters/class-ilsm-editor-adapter-elementor.php';
require_once ILSM_PATH . 'includes/adapters/class-ilsm-editor-adapter-gutenberg.php';
require_once ILSM_PATH . 'includes/adapters/class-ilsm-editor-adapter-classic.php';
require_once ILSM_PATH . 'includes/adapters/class-ilsm-editor-adapter-registry.php';
require_once ILSM_PATH . 'includes/class-ilsm-database.php';
require_once ILSM_PATH . 'includes/class-ilsm-locks.php';
require_once ILSM_PATH . 'includes/class-ilsm-seo-inspector.php';
require_once ILSM_PATH . 'includes/class-ilsm-opportunity-eligibility.php';
require_once ILSM_PATH . 'includes/class-ilsm-search-intent.php';
require_once ILSM_PATH . 'includes/class-ilsm-link-normalizer.php';
require_once ILSM_PATH . 'includes/class-ilsm-destination-resolver.php';
require_once ILSM_PATH . 'includes/class-ilsm-elementor-controls.php';
require_once ILSM_PATH . 'includes/class-ilsm-content-extractor.php';
require_once ILSM_PATH . 'includes/class-ilsm-rendered-page.php';
require_once ILSM_PATH . 'includes/class-ilsm-page-seo-analyzer.php';
require_once ILSM_PATH . 'includes/class-ilsm-on-page-audit.php';
require_once ILSM_PATH . 'includes/class-ilsm-local-assistant.php';
require_once ILSM_PATH . 'includes/class-ilsm-link-opportunities.php';
require_once ILSM_PATH . 'includes/class-ilsm-link-inserter.php';
require_once ILSM_PATH . 'includes/class-ilsm-crawler.php';
require_once ILSM_PATH . 'includes/class-ilsm-elementor.php';
require_once ILSM_PATH . 'includes/class-ilsm-scan-manager.php';
require_once ILSM_PATH . 'includes/class-ilsm-architecture-service.php';
require_once ILSM_PATH . 'includes/class-ilsm-pdf-report.php';
require_once ILSM_PATH . 'includes/class-ilsm-external-link-health.php';
require_once ILSM_PATH . 'includes/class-ilsm-broken-link-maintenance.php';
require_once ILSM_PATH . 'includes/class-ilsm-redirect-manager.php';
require_once ILSM_PATH . 'includes/class-ilsm-search-console-import.php';
require_once ILSM_PATH . 'includes/class-ilsm-obsidian-export.php';
require_once ILSM_PATH . 'includes/class-ilsm-admin-assets.php';
require_once ILSM_PATH . 'includes/class-ilsm-admin.php';
require_once ILSM_PATH . 'includes/core/class-ilsm-runtime.php';

/** Run an operation for every site without loading the entire network into memory. */
function ilsm_for_each_site( $callback ) {
    if ( ! is_multisite() ) {
        $callback();
        return;
    }

    $page = 1;
    do {
        $site_ids = get_sites(
            array(
                'fields' => 'ids',
                'number' => 100,
                'paged'  => $page,
            )
        );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            $callback();
            restore_current_blog();
        }
        $page++;
    } while ( 100 === count( $site_ids ) );
}

register_activation_hook(
    __FILE__,
    static function( $network_wide ) {
        if ( is_multisite() && $network_wide ) {
            ilsm_for_each_site( array( 'ILSM_Activator', 'activate' ) );
            return;
        }
        ILSM_Activator::activate();
    }
);

add_action(
    'wp_initialize_site',
    static function( $site ) {
        $network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
        if ( ! isset( $network_plugins[ plugin_basename( ILSM_FILE ) ] ) ) {
            return;
        }
        switch_to_blog( $site->blog_id );
        ILSM_Activator::activate();
        restore_current_blog();
    },
    20
);

register_deactivation_hook(
    __FILE__,
    static function( $network_wide ) {
        if ( is_multisite() && $network_wide ) {
            ilsm_for_each_site( array( 'ILSM_Activator', 'deactivate' ) );
            return;
        }
        ILSM_Activator::deactivate();
    }
);

ILSM_Obsidian_Export::register();
ILSM_On_Page_Audit::register();
ILSM_Search_Console_Import::register();
ILSM_Broken_Link_Maintenance::register();
ILSM_Redirect_Manager::register();

ILSM_Runtime::register();
