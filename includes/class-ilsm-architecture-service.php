<?php
/**
 * Real architecture graph data built from the latest completed scan.
 *
 * @package Internal_Link_SEO_Mapper
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Architecture_Service {
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_action( 'wp_ajax_ilsm_architecture_data', array( $this, 'ajax_data' ) );
        add_action( 'wp_ajax_ilsm_knowledge_context', array( $this, 'ajax_knowledge_context' ) );
    }

    public function ajax_data() {
        if ( ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_admin', 'nonce' );

        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'page';
        if ( ! in_array( $mode, array( 'page', 'site', 'knowledge' ), true ) ) { $mode = 'page'; }
        $root_id = isset( $_POST['root_id'] ) ? absint( $_POST['root_id'] ) : 0;
        $max_depth = isset( $_POST['max_depth'] ) ? absint( $_POST['max_depth'] ) : 0;
        $max_depth = min( 20, $max_depth );
        if ( in_array( $mode, array( 'site', 'knowledge' ), true ) ) {
            // Site-wide views are rooted at the real homepage and include every level.
            $root_id  = 0;
            $max_depth = 0;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input is validated and normalized immediately before use.
        $requested = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array();
        $post_types = $this->allowed_post_types( $requested );
        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all';
        $min_in = isset( $_POST['min_in'] ) ? absint( $_POST['min_in'] ) : 0;
        $min_out = isset( $_POST['min_out'] ) ? absint( $_POST['min_out'] ) : 0;

        $data = $this->build( compact( 'mode', 'root_id', 'max_depth', 'post_types', 'status', 'min_in', 'min_out' ) );
        if ( is_wp_error( $data ) ) {
            wp_send_json_error( array( 'message' => $data->get_error_message() ), 400 );
        }
        wp_send_json_success( $data );
    }


    /**
     * Return lightweight frontend/admin context for one selected Knowledge Graph node.
     *
     * This is deliberately fetched per selected node rather than appended to every
     * graph node, avoiding hundreds or thousands of taxonomy lookups on large sites.
     */
    public function ajax_knowledge_context() {
        if ( ! current_user_can( 'ilsm_view_reports' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
        }
        check_ajax_referer( 'ilsm_admin', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post    = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || ! ILSM_SEO_Inspector::is_reportable( $post ) ) {
            wp_send_json_error( array( 'message' => __( 'The selected content is not available.', 'dma-internlink-mapper' ) ), 404 );
        }

        $post_type_object = get_post_type_object( $post->post_type );
        $type_label       = $post_type_object && ! empty( $post_type_object->labels->singular_name )
            ? $post_type_object->labels->singular_name
            : $post->post_type;

        $type_admin_url = current_user_can( 'edit_posts' )
            ? admin_url( 'edit.php?post_type=' . rawurlencode( $post->post_type ) )
            : '';

        $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
        $term_links = array();

        foreach ( $taxonomies as $taxonomy => $taxonomy_object ) {
            if ( ! $taxonomy_object->public ) {
                continue;
            }

            $terms = get_the_terms( $post_id, $taxonomy );
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                $term_url = get_term_link( $term );
                if ( is_wp_error( $term_url ) ) {
                    continue;
                }

                $term_links[] = array(
                    'taxonomy'       => sanitize_key( $taxonomy ),
                    'taxonomy_label' => sanitize_text_field( $taxonomy_object->labels->singular_name ),
                    'name'           => sanitize_text_field( $term->name ),
                    'url'            => esc_url_raw( $term_url ),
                );

                // Keep the toolbar concise. Full taxonomy analysis remains elsewhere.
                if ( count( $term_links ) >= 8 ) {
                    break 2;
                }
            }
        }

        wp_send_json_success(
            array(
                'id'             => $post_id,
                'url'            => esc_url_raw( get_permalink( $post_id ) ),
                'post_type'      => sanitize_key( $post->post_type ),
                'post_type_label'=> sanitize_text_field( $type_label ),
                'post_type_url'  => esc_url_raw( $type_admin_url ),
                'terms'          => $term_links,
            )
        );
    }

    private function allowed_post_types( $requested ) {
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        $allowed = array_values( array_filter( array_keys( $objects ), array( 'ILSM_SEO_Inspector', 'is_supported_post_type' ) ) );
        $requested = array_values( array_filter( array_map( 'sanitize_key', $requested ) ) );
        $selected = array_values( array_intersect( $requested, $allowed ) );
        return $selected ?: $allowed;
    }

    public function build( $args ) {
        global $wpdb;
        $scan_id = ILSM_Database::latest_completed_scan_id();
        if ( ! $scan_id ) {
            return new WP_Error( 'no_scan', __( 'Run and complete a scan before opening architecture views.', 'dma-internlink-mapper' ) );
        }

        $pages_table = ILSM_Database::table( 'pages' );
        $links_table = ILSM_Database::table( 'links' );
        $types = $args['post_types'];
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $node_limit = 2500;
        $edge_limit = 25000;
        $query_args = array_merge( array( $scan_id ), $types, array( $node_limit + 1 ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The dynamic placeholder list matches the validated argument array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
                "SELECT post_id,title,url,post_type,incoming_count,outgoing_count,weak_anchor_count,broken_count,is_orphan,seo_score FROM {$pages_table} WHERE scan_id=%d AND post_type IN ({$placeholders}) ORDER BY post_id ASC LIMIT %d",
                $query_args
            ),
            ARRAY_A
        );
        $node_limit_reached = count( $rows ) > $node_limit;
        if ( $node_limit_reached ) { $rows = array_slice( $rows, 0, $node_limit ); }

        if ( ! $rows ) {
            return new WP_Error( 'no_pages', __( 'No scanned pages match the selected content types.', 'dma-internlink-mapper' ) );
        }

        // Prime WordPress post and meta caches once to avoid N+1 lookups while
        // constructing large architecture graphs.
        $post_ids = array_values( array_filter( array_map( 'absint', array_column( $rows, 'post_id' ) ) ) );
        if ( $post_ids ) {
            _prime_post_caches( $post_ids, false, true );
        }

        $nodes = array();
        foreach ( $rows as $row ) {
            $id = absint( $row['post_id'] );
            $post = get_post( $id );
            if ( ! ILSM_SEO_Inspector::is_reportable( $post ) ) { continue; }
            $nodes[ $id ] = array(
                'id' => $id,
                'title' => html_entity_decode( $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                'url' => esc_url_raw( $row['url'] ),
                'path' => (string) wp_parse_url( $row['url'], PHP_URL_PATH ),
                'edit_url' => current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id, 'raw' ) : '',
                'post_type' => sanitize_key( $row['post_type'] ),
                'status' => 'healthy',
                'depth' => null,
                'parent_id' => 0,
                'relationship_type' => '',
                'incoming_count' => absint( $row['incoming_count'] ),
                'outgoing_count' => absint( $row['outgoing_count'] ),
                'child_count' => 0,
                'seo_score' => absint( $row['seo_score'] ),
                'is_orphan' => (bool) $row['is_orphan'],
                'is_noindex' => ! ILSM_SEO_Inspector::is_indexable( $id ),
                'is_redirect' => false,
                'is_broken' => absint( $row['broken_count'] ) > 0,
                'authority_score' => absint( $row['incoming_count'] ) * 2 + absint( $row['outgoing_count'] ),
                'post_parent' => absint( $post->post_parent ),
            );
        }
        if ( ! $nodes ) { return new WP_Error( 'no_public_pages', __( 'No public scanned pages are available.', 'dma-internlink-mapper' ) ); }

        $root_id = absint( $args['root_id'] );
        if ( in_array( $args['mode'], array( 'site', 'knowledge' ), true ) || ! $root_id || ! isset( $nodes[ $root_id ] ) ) {
            $front = absint( get_option( 'page_on_front' ) );
            if ( $front && isset( $nodes[ $front ] ) ) {
                $root_id = $front;
            } else {
                $home = untrailingslashit( home_url( '/' ) );
                $root_id = 0;
                foreach ( $nodes as $candidate_id => $candidate ) {
                    if ( untrailingslashit( (string) $candidate['url'] ) === $home ) {
                        $root_id = (int) $candidate_id;
                        break;
                    }
                }
                if ( ! $root_id ) {
                    foreach ( $nodes as $candidate_id => $candidate ) {
                        if ( 'page' === $candidate['post_type'] ) {
                            $root_id = (int) $candidate_id;
                            break;
                        }
                    }
                }
                if ( ! $root_id ) {
                    $root_id = (int) array_key_first( $nodes );
                }
            }
        }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic identifiers are produced by the strict ILSM_Database allowlist. Plugin-owned custom tables require direct database access. Mutable scan data must be read fresh; persistent object caching would be stale.
        $link_rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed or allowlisted SQL identifiers are interpolated.
                "SELECT source_post_id,target_post_id,anchor_text,issue_type,follow_status,http_status FROM {$links_table} WHERE scan_id=%d AND target_post_id>0 ORDER BY id ASC LIMIT %d",
                $scan_id,
                $edge_limit + 1
            ),
            ARRAY_A
        );
        $edge_limit_reached = count( $link_rows ) > $edge_limit;
        if ( $edge_limit_reached ) { $link_rows = array_slice( $link_rows, 0, $edge_limit ); }

        $all_edges = array();
        $link_strength = array();
        foreach ( $link_rows as $link ) {
            $source = absint( $link['source_post_id'] );
            $target = absint( $link['target_post_id'] );
            if ( ! isset( $nodes[ $source ], $nodes[ $target ] ) || $source === $target ) { continue; }
            $key = $source . ':' . $target;
            $link_strength[ $key ] = isset( $link_strength[ $key ] ) ? $link_strength[ $key ] + 1 : 1;
            $all_edges[ $key ] = array(
                'source' => $source,
                'target' => $target,
                'type' => 'internal',
                'anchor' => sanitize_text_field( $link['anchor_text'] ),
                'status' => sanitize_key( $link['issue_type'] ?: 'healthy' ),
                'follow' => sanitize_key( $link['follow_status'] ),
                'relationship_type' => 'contextual',
                'strength' => $link_strength[ $key ],
            );
        }

        $this->assign_parents( $nodes, $root_id, $link_strength );
        $hierarchy_edges = array();
        foreach ( $nodes as $id => &$node ) {
            if ( $node['parent_id'] && isset( $nodes[ $node['parent_id'] ] ) ) {
                $nodes[ $node['parent_id'] ]['child_count']++;
                $hierarchy_edges[] = array(
                    'source' => $node['parent_id'],
                    'target' => $id,
                    'type' => 'hierarchy',
                    'anchor' => '',
                    'status' => 'healthy',
                    'follow' => 'follow',
                    'relationship_type' => $node['relationship_type'],
                );
            }
        }
        unset( $node );

        $this->assign_depths( $nodes, $root_id );
        $filtered = $this->filter_nodes( $nodes, $root_id, $args );
        $ids = array_fill_keys( array_keys( $filtered ), true );
        if ( 'knowledge' === $args['mode'] ) {
            $edges = array_values( array_filter( $all_edges, static function( $edge ) use ( $ids ) {
                return isset( $ids[ $edge['source'] ], $ids[ $edge['target'] ] );
            } ) );
        } else {
            $edges = array_values( array_filter( $hierarchy_edges, static function( $edge ) use ( $ids ) {
                return isset( $ids[ $edge['source'] ], $ids[ $edge['target'] ] );
            } ) );
        }

        $metrics = $this->metrics( $filtered, $all_edges, $ids, $root_id );
        // Database-level totals remain separate from the bounded visualization payload.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned scan tables; identifiers are internal allowlisted values and scan data must be current.
        $total_matching_pages = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder list are internally generated.
                "SELECT COUNT(*) FROM {$pages_table} WHERE scan_id=%d AND post_type IN ({$placeholders})",
                array_merge( array( $scan_id ), $types )
            )
        );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned scan table; identifier is internally allowlisted and scan data must be current.
        $total_internal_edges = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is an internally generated allowlisted identifier.
                "SELECT COUNT(*) FROM {$links_table} WHERE scan_id=%d AND target_post_id>0",
                $scan_id
            )
        );
        return array(
            'nodes' => array_values( $filtered ),
            'edges' => $edges,
            'meta' => array(
                'totals' => $metrics,
                'filters' => array(
                    'mode' => $args['mode'],
                    'root_id' => $root_id,
                    'post_types' => $types,
                    'max_depth' => absint( $args['max_depth'] ),
                ),
                'generated_at' => current_time( 'mysql' ),
                'scan_id' => $scan_id,
                'architecture_score' => $metrics['architecture_score'],
                'metrics_scope' => ( $node_limit_reached || $edge_limit_reached ) ? 'visualized-subset' : 'complete-visualization-set',
                'limited' => ( $node_limit_reached || $edge_limit_reached ),
                'node_limit_reached' => $node_limit_reached,
                'edge_limit_reached' => $edge_limit_reached,
                'node_limit' => $node_limit,
                'edge_limit' => $edge_limit,
                'total_matching_pages' => $total_matching_pages,
                'total_internal_edges' => $total_internal_edges,
            ),
        );
    }

    private function assign_parents( &$nodes, $root_id, $strength ) {
        $path_map = array();
        $incoming_strength = array();
        foreach ( $nodes as $id => $node ) {
            $path_map[ untrailingslashit( $node['path'] ) ] = $id;
        }
        foreach ( $strength as $key => $weight ) {
            $parts = explode( ':', $key, 2 );
            if ( 2 !== count( $parts ) ) { continue; }
            $source = absint( $parts[0] );
            $target = absint( $parts[1] );
            if ( ! $source || ! $target || $source === $target || ! isset( $nodes[ $source ], $nodes[ $target ] ) ) { continue; }
            $incoming_strength[ $target ][ $source ] = (int) $weight;
        }
        foreach ( $nodes as $id => &$node ) {
            if ( $id === $root_id ) { continue; }
            if ( $node['post_parent'] && isset( $nodes[ $node['post_parent'] ] ) && $node['post_parent'] !== $id ) {
                $node['parent_id'] = $node['post_parent'];
                $node['relationship_type'] = 'native';
                continue;
            }
            $path = untrailingslashit( $node['path'] );
            $parent_path = untrailingslashit( dirname( $path ) );
            if ( $parent_path && '/' !== $parent_path && isset( $path_map[ $parent_path ] ) && $path_map[ $parent_path ] !== $id ) {
                $node['parent_id'] = $path_map[ $parent_path ];
                $node['relationship_type'] = 'url_inferred';
                continue;
            }
            $best_parent = 0;
            $best_weight = 0;
            foreach ( $incoming_strength[ $id ] ?? array() as $source => $weight ) {
                if ( $weight > $best_weight ) {
                    $best_parent = absint( $source );
                    $best_weight = (int) $weight;
                }
            }
            $node['parent_id'] = $best_parent ?: $root_id;
            $node['relationship_type'] = $best_parent ? 'link_inferred' : 'root_fallback';
        }
        unset( $node );
        $this->break_cycles( $nodes, $root_id );
    }

    private function break_cycles( &$nodes, $root_id ) {
        foreach ( array_keys( $nodes ) as $id ) {
            $seen = array(); $current = $id; $steps = 0;
            while ( isset( $nodes[ $current ] ) && $nodes[ $current ]['parent_id'] && $steps++ < count( $nodes ) ) {
                if ( isset( $seen[ $current ] ) ) {
                    $nodes[ $id ]['parent_id'] = $root_id === $id ? 0 : $root_id;
                    $nodes[ $id ]['relationship_type'] = 'cycle_repaired';
                    break;
                }
                $seen[ $current ] = true;
                $current = absint( $nodes[ $current ]['parent_id'] );
            }
        }
        $nodes[ $root_id ]['parent_id'] = 0;
        $nodes[ $root_id ]['relationship_type'] = 'root';
    }

    private function assign_depths( &$nodes, $root_id ) {
        $nodes[ $root_id ]['depth'] = 0;
        $children = array();
        foreach ( $nodes as $id => $node ) {
            $parent_id = absint( $node['parent_id'] );
            if ( $parent_id && isset( $nodes[ $parent_id ] ) ) {
                $children[ $parent_id ][] = $id;
            }
        }
        $queue = array( $root_id );
        $offset = 0;
        while ( isset( $queue[ $offset ] ) ) {
            $parent = $queue[ $offset++ ];
            $depth = absint( $nodes[ $parent ]['depth'] );
            foreach ( $children[ $parent ] ?? array() as $id ) {
                if ( null === $nodes[ $id ]['depth'] ) {
                    $nodes[ $id ]['depth'] = $depth + 1;
                    $queue[] = $id;
                }
            }
        }
        foreach ( $nodes as &$node ) {
            if ( null === $node['depth'] ) { $node['depth'] = 999; }
        }
        unset( $node );
    }

    private function filter_nodes( $nodes, $root_id, $args ) {
        $result = array();
        foreach ( $nodes as $id => $node ) {
            if ( 'page' === $args['mode'] && 999 === $node['depth'] ) { continue; }
            if ( $args['max_depth'] && $node['depth'] > $args['max_depth'] ) { continue; }
            if ( $node['incoming_count'] < $args['min_in'] || $node['outgoing_count'] < $args['min_out'] ) { continue; }
            if ( 'orphan' === $args['status'] && ! $node['is_orphan'] ) { continue; }
            if ( 'broken' === $args['status'] && ! $node['is_broken'] ) { continue; }
            if ( 'weak' === $args['status'] && $node['seo_score'] >= 60 ) { continue; }
            if ( 'healthy' === $args['status'] && ( $node['is_broken'] || $node['is_orphan'] || $node['seo_score'] < 60 ) ) { continue; }
            $result[ $id ] = $node;
        }
        if ( isset( $nodes[ $root_id ] ) ) { $result[ $root_id ] = $nodes[ $root_id ]; }
        return $result;
    }

    private function metrics( $nodes, $all_edges, $ids, $root_id ) {
        $count = count( $nodes );
        $depths = array_map( static function( $node ) { return 999 === $node['depth'] ? 0 : absint( $node['depth'] ); }, $nodes );
        $orphans = count( array_filter( $nodes, static function( $node ) { return $node['is_orphan']; } ) );
        $broken_pages = count( array_filter( $nodes, static function( $node ) { return $node['is_broken']; } ) );
        $without_children = count( array_filter( $nodes, static function( $node ) { return 0 === $node['child_count']; } ) );
        $without_outgoing = count( array_filter( $nodes, static function( $node ) { return 0 === $node['outgoing_count']; } ) );
        $broken_links = 0; $redirect_links = 0; $internal_links = 0;
        foreach ( $all_edges as $edge ) {
            if ( ! isset( $ids[ $edge['source'] ], $ids[ $edge['target'] ] ) ) { continue; }
            $internal_links++;
            if ( 'broken' === $edge['status'] ) { $broken_links++; }
            if ( 'redirect' === $edge['status'] ) { $redirect_links++; }
        }
        $orphan_ratio = $count ? $orphans / $count : 0;
        $broken_ratio = $internal_links ? $broken_links / $internal_links : 0;
        $deep = count( array_filter( $depths, static function( $d ) { return $d > 3; } ) );
        $deep_ratio = $count ? $deep / $count : 0;
        $no_out_ratio = $count ? $without_outgoing / $count : 0;
        // Each ratio is already multiplied by its maximum penalty in points.
        // Do not multiply the combined penalty by 100 again, or almost every
        // non-perfect architecture is incorrectly clamped to zero.
        $penalty = ( $orphan_ratio * 30 ) + ( $broken_ratio * 30 ) + ( $deep_ratio * 20 ) + ( $no_out_ratio * 20 );
        $score   = max( 0, min( 100, (int) round( 100 - $penalty ) ) );
        return array(
            'total_pages' => $count,
            'total_internal_links' => $internal_links,
            'total_levels' => $depths ? max( $depths ) + 1 : 0,
            'average_depth' => $count ? round( array_sum( $depths ) / $count, 2 ) : 0,
            'maximum_depth' => $depths ? max( $depths ) : 0,
            'orphan_pages' => $orphans,
            'broken_pages' => $broken_pages,
            'broken_internal_links' => $broken_links,
            'redirect_links' => $redirect_links,
            'pages_without_children' => $without_children,
            'pages_without_outgoing' => $without_outgoing,
            'root_id' => $root_id,
            'architecture_score' => apply_filters( 'ilsm_architecture_health_score', $score, $nodes ),
        );
    }
}
