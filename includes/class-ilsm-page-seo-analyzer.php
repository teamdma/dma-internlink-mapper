<?php
/**
 * Transparent read-only SEO analysis based on rendered public HTML.
 * Internal-link metrics are supplied separately from the completed crawler.
 *
 * @package Internal_Link_SEO_Mapper
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Page_SEO_Analyzer {
    /**
     * Analyze one page without modifying its editable source.
     *
     * @param int   $post_id Post ID.
     * @param array $link_metrics Metrics from the latest completed crawl.
     * @param array $snapshot Optional already-fetched rendered snapshot.
     * @return array
     */
    public static function analyze( $post_id, $link_metrics = array(), $snapshot = null ) {
        $post_id = absint( $post_id );
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) { return array(); }

        if ( ! is_array( $snapshot ) ) {
            // Reuse the verified rendered snapshot produced by the completed
            // scan. Forcing a same-site HTTP request from an admin AJAX worker
            // can deadlock or time out on shared hosts with few PHP workers.
            // The cache key includes post_modified_gmt, so normal post updates
            // naturally invalidate saved-content snapshots.
            $snapshot = ILSM_Rendered_Page::snapshot( $post, false );
        }

        $page_title = trim( wp_strip_all_tags( get_the_title( $post ) ) );
        $verified = ! empty( $snapshot['ok'] ) && ! empty( $snapshot['verified'] );
        if ( ! $verified ) {
            return self::unavailable( $post, $page_title, $link_metrics, (string) ( $snapshot['error'] ?? '' ) );
        }

        $seo_title  = trim( (string) ( $snapshot['seo_title'] ?? '' ) );
        $meta       = trim( (string) ( $snapshot['meta_description'] ?? '' ) );
        $canonical  = trim( (string) ( $snapshot['canonical'] ?? '' ) );
        $public_url = trim( (string) ( $snapshot['url'] ?? get_permalink( $post_id ) ) );
        $indexable  = ! empty( $snapshot['indexable'] );
        $word_count = absint( $snapshot['word_count'] ?? 0 );
        $headings   = isset( $snapshot['headings'] ) && is_array( $snapshot['headings'] ) ? $snapshot['headings'] : array();
        $heading_sequence = isset( $snapshot['heading_sequence'] ) && is_array( $snapshot['heading_sequence'] ) ? $snapshot['heading_sequence'] : array();
        $images     = isset( $snapshot['images'] ) && is_array( $snapshot['images'] ) ? $snapshot['images'] : array();
        $body_text  = (string) ( $snapshot['body_text'] ?? '' );
        $keyphrases = ILSM_SEO_Provider_Registry::focus_keyphrases( $post_id );
        $keyphrase  = isset( $keyphrases[0] ) ? trim( (string) $keyphrases[0] ) : '';

        $checks = array();

        if ( '' === $seo_title ) {
            $title_detail = __( 'No rendered HTML title was detected.', 'dma-internlink-mapper' );
        } else {
            /* translators: %d: number of characters in the rendered HTML title. */
            $title_detail = sprintf( __( '%d characters in the rendered title tag', 'dma-internlink-mapper' ), ILSM_Text::length( $seo_title ) );
        }
        $checks[] = self::check(
            'title',
            __( 'Title', 'dma-internlink-mapper' ),
            10,
            '' !== $seo_title && ILSM_Text::length( $seo_title ) >= 20 && ILSM_Text::length( $seo_title ) <= 70 ? 10 : ( '' !== $seo_title ? 6 : 0 ),
            $title_detail
        );

        if ( '' === $meta ) {
            $meta_detail = __( 'No rendered meta description was detected.', 'dma-internlink-mapper' );
        } else {
            /* translators: %d: number of characters in the rendered meta description. */
            $meta_detail = sprintf( __( '%d characters in the rendered meta description', 'dma-internlink-mapper' ), ILSM_Text::length( $meta ) );
        }
        $checks[] = self::check(
            'meta',
            __( 'Meta description', 'dma-internlink-mapper' ),
            10,
            '' !== $meta && ILSM_Text::length( $meta ) >= 100 && ILSM_Text::length( $meta ) <= 170 ? 10 : ( '' !== $meta ? 6 : 0 ),
            $meta_detail
        );

        $checks[] = self::check(
            'indexability',
            __( 'Indexability', 'dma-internlink-mapper' ),
            15,
            $indexable ? 15 : 0,
            $indexable ? __( 'Rendered robots directives do not contain noindex.', 'dma-internlink-mapper' ) : __( 'The rendered page contains a noindex robots directive.', 'dma-internlink-mapper' )
        );

        $canonical_ok = $canonical && $public_url && untrailingslashit( $canonical ) === untrailingslashit( $public_url );
        $checks[] = self::check(
            'canonical',
            __( 'Canonical', 'dma-internlink-mapper' ),
            15,
            $canonical_ok ? 15 : ( $canonical ? 8 : 0 ),
            $canonical_ok ? __( 'Rendered canonical matches this public URL.', 'dma-internlink-mapper' ) : ( $canonical ? __( 'Rendered canonical points to a different URL.', 'dma-internlink-mapper' ) : __( 'No canonical URL was found in rendered HTML.', 'dma-internlink-mapper' ) )
        );

        $content_points = $word_count >= 300 ? 15 : ( $word_count >= 150 ? 10 : ( $word_count > 0 ? 5 : 0 ) );
        /* translators: %d: rendered main-content word count. */
        $content_detail = sprintf( _n( '%d rendered content word', '%d rendered content words', $word_count, 'dma-internlink-mapper' ), $word_count );
        $checks[] = self::check(
            'content',
            __( 'Content / word count', 'dma-internlink-mapper' ),
            15,
            $content_points,
            $content_detail
        );

        $heading_result = self::heading_structure( $headings, $heading_sequence );
        $checks[] = self::check(
            'headings',
            __( 'Headings', 'dma-internlink-mapper' ),
            15,
            $heading_result['points'],
            $heading_result['summary'],
            $heading_result['warnings']
        );

        $total_images = absint( $images['total'] ?? 0 );
        $with_alt     = absint( $images['with_alt'] ?? 0 );
        $empty_alt    = absint( $images['empty_alt'] ?? 0 );
        $missing_alt  = absint( $images['missing_alt'] ?? 0 );
        // An explicitly empty alt attribute can be correct for decorative images.
        // Score only the objective failure: a missing alt attribute.
        $image_points = 10;
        if ( $total_images > 0 && $missing_alt > 0 ) {
            $image_points = (int) round( 10 * max( 0, 1 - ( $missing_alt / $total_images ) ) );
        }
        $image_warnings = array();
        if ( $missing_alt ) {
            /* translators: %d: number of rendered images without an ALT attribute. */
            $image_warnings[] = sprintf( _n( '%d rendered image has no ALT attribute.', '%d rendered images have no ALT attribute.', $missing_alt, 'dma-internlink-mapper' ), $missing_alt );
        }
        if ( $empty_alt ) {
            /* translators: %d: number of rendered images with an empty ALT attribute. */
            $image_warnings[] = sprintf( _n( '%d rendered image uses an empty ALT attribute; this can be correct for a decorative image and should be reviewed in context.', '%d rendered images use empty ALT attributes; these can be correct for decorative images and should be reviewed in context.', $empty_alt, 'dma-internlink-mapper' ), $empty_alt );
        }
        if ( 0 === $total_images ) {
            $image_detail = __( 'No images were found in the rendered main content.', 'dma-internlink-mapper' );
        } else {
            /* translators: 1: images with non-empty ALT text, 2: images with empty ALT, 3: images missing ALT. */
            $image_detail = sprintf( __( '%1$d descriptive ALT · %2$d empty ALT · %3$d missing ALT', 'dma-internlink-mapper' ), $with_alt, $empty_alt, $missing_alt );
        }
        $checks[] = self::check(
            'images',
            __( 'Images / ALT', 'dma-internlink-mapper' ),
            10,
            $image_points,
            $image_detail,
            $image_warnings
        );

        $keyphrase_points = 0;
        $keyphrase_detail = __( 'No focus keyphrase supplied by a supported SEO plugin.', 'dma-internlink-mapper' );
        $keyphrase_na = '' === $keyphrase;
        if ( ! $keyphrase_na ) {
            $needle = self::lower( $keyphrase );
            $searchable = self::lower( $seo_title . ' ' . $meta . ' ' . $body_text );
            $found = false !== strpos( $searchable, $needle );
            $keyphrase_points = $found ? 10 : 5;
            if ( $found ) {
                /* translators: %s: configured focus keyphrase. */
                $keyphrase_detail = sprintf( __( 'Focus keyphrase is present in rendered page text: %s', 'dma-internlink-mapper' ), $keyphrase );
            } else {
                /* translators: %s: configured focus keyphrase. */
                $keyphrase_detail = sprintf( __( 'Focus keyphrase is configured but was not found in rendered page text: %s', 'dma-internlink-mapper' ), $keyphrase );
            }
        }
        $checks[] = self::check(
            'keyphrase',
            __( 'Focus keyphrase', 'dma-internlink-mapper' ),
            10,
            $keyphrase_points,
            $keyphrase_detail,
            array(),
            $keyphrase_na
        );

        $earned = 0;
        $possible = 0;
        foreach ( $checks as $check ) {
            if ( ! empty( $check['not_applicable'] ) ) { continue; }
            $earned += absint( $check['points'] );
            $possible += absint( $check['max'] );
        }
        $score = $possible ? (int) round( 100 * $earned / $possible ) : null;

        return array(
            'post_id'       => $post_id,
            'title'         => $page_title,
            'score'         => null === $score ? null : max( 0, min( 100, $score ) ),
            'label'         => null === $score ? __( 'Not analyzed', 'dma-internlink-mapper' ) : self::score_label( $score ),
            'verified'      => true,
            'analysis_source' => 'rendered-public-html',
            'source_url'    => esc_url_raw( $public_url ),
            'checks'        => $checks,
            'headings'      => self::heading_counts( $headings ),
            'word_count'    => $word_count,
            'images'        => array(
                'total'       => $total_images,
                'with_alt'    => $with_alt,
                'empty_alt'   => $empty_alt,
                'missing_alt' => $missing_alt,
            ),
            'internal_links' => self::link_metrics( $link_metrics ),
            'external_links' => self::external_link_metrics( $link_metrics ),
        );
    }

    /** Return a truthful N/A response rather than converting fetch failure into zeros. */
    private static function unavailable( WP_Post $post, $page_title, $link_metrics, $error ) {
        if ( $error ) {
            /* translators: %s: rendered-page verification error message. */
            $detail = sprintf( __( 'Rendered page could not be verified: %s', 'dma-internlink-mapper' ), $error );
        } else {
            $detail = __( 'Rendered page could not be verified.', 'dma-internlink-mapper' );
        }
        $labels = array(
            'title' => __( 'Title', 'dma-internlink-mapper' ),
            'meta' => __( 'Meta description', 'dma-internlink-mapper' ),
            'indexability' => __( 'Indexability', 'dma-internlink-mapper' ),
            'canonical' => __( 'Canonical', 'dma-internlink-mapper' ),
            'content' => __( 'Content / word count', 'dma-internlink-mapper' ),
            'headings' => __( 'Headings', 'dma-internlink-mapper' ),
            'images' => __( 'Images / ALT', 'dma-internlink-mapper' ),
            'keyphrase' => __( 'Focus keyphrase', 'dma-internlink-mapper' ),
        );
        $maxima = array( 'title'=>10, 'meta'=>10, 'indexability'=>15, 'canonical'=>15, 'content'=>15, 'headings'=>15, 'images'=>10, 'keyphrase'=>10 );
        $checks = array();
        foreach ( $labels as $id => $label ) {
            $checks[] = self::check( $id, $label, $maxima[$id], 0, $detail, array(), true );
        }
        return array(
            'post_id' => $post->ID,
            'title' => $page_title,
            'score' => null,
            'label' => __( 'Not analyzed', 'dma-internlink-mapper' ),
            'verified' => false,
            'analysis_source' => 'rendered-public-html',
            'analysis_error' => sanitize_text_field( $error ),
            'checks' => $checks,
            'headings' => array( 'h1'=>null, 'h2'=>null, 'h3'=>null, 'h4'=>null, 'h5'=>null, 'h6'=>null ),
            'word_count' => null,
            'images' => array( 'total'=>null, 'with_alt'=>null, 'empty_alt'=>null, 'missing_alt'=>null ),
            'internal_links' => self::link_metrics( $link_metrics ),
        );
    }

    private static function link_metrics( $link_metrics ) {
        return array(
            'incoming' => absint( $link_metrics['incoming'] ?? 0 ),
            'outgoing' => absint( $link_metrics['outgoing'] ?? 0 ),
            'broken'   => absint( $link_metrics['broken'] ?? 0 ),
            'weak'     => absint( $link_metrics['weak'] ?? 0 ),
            'orphan'   => 0 === absint( $link_metrics['incoming'] ?? 0 ),
        );
    }

    private static function external_link_metrics( $link_metrics ) {
        return array(
            'total'     => absint( $link_metrics['external_total'] ?? 0 ),
            'nofollow'  => absint( $link_metrics['external_nofollow'] ?? 0 ),
            'broken'    => absint( $link_metrics['external_broken'] ?? 0 ),
            'redirects' => absint( $link_metrics['external_redirects'] ?? 0 ),
        );
    }

    private static function check( $id, $label, $max, $points, $detail, $warnings = array(), $not_applicable = false ) {
        $max = max( 1, absint( $max ) );
        $points = max( 0, min( $max, absint( $points ) ) );
        $ratio = $points / $max;
        return array(
            'id' => sanitize_key( $id ),
            'label' => sanitize_text_field( $label ),
            'max' => $max,
            'points' => $points,
            'status' => $not_applicable ? 'na' : ( $ratio >= 0.8 ? 'good' : ( $ratio >= 0.5 ? 'warning' : 'bad' ) ),
            'detail' => sanitize_text_field( $detail ),
            'warnings' => array_values( array_filter( array_map( 'sanitize_text_field', (array) $warnings ) ) ),
            'not_applicable' => (bool) $not_applicable,
        );
    }

    private static function heading_structure( $headings, $sequence = array() ) {
        $counts = self::heading_counts( $headings );
        $warnings = array();
        if ( 0 === $counts['h1'] ) {
            $warnings[] = __( 'No H1 was detected in the rendered public page.', 'dma-internlink-mapper' );
        } elseif ( $counts['h1'] > 1 ) {
            /* translators: %d: number of H1 headings detected in the rendered public page. */
            $warnings[] = sprintf( __( '%d H1 headings were detected in the rendered public page.', 'dma-internlink-mapper' ), $counts['h1'] );
        }

        $jump_count = 0;
        $previous_level = 0;
        foreach ( (array) $sequence as $heading ) {
            $level = absint( $heading['level'] ?? 0 );
            if ( $level < 1 || $level > 6 ) { continue; }
            if ( $previous_level && $level > $previous_level + 1 ) {
                $jump_count++;
                /* translators: 1: previous heading level number, 2: next heading level number. */
                $warnings[] = sprintf( __( 'Rendered heading order jumps from H%1$d to H%2$d.', 'dma-internlink-mapper' ), $previous_level, $level );
            }
            $previous_level = $level;
        }

        $points = 15;
        if ( 0 === $counts['h1'] ) { $points -= 6; }
        elseif ( $counts['h1'] > 1 ) { $points -= min( 4, $counts['h1'] - 1 ); }
        $points -= min( 6, $jump_count * 3 );
        $points = max( 0, $points );

        $parts = array();
        for ( $i = 1; $i <= 6; $i++ ) { $parts[] = 'H' . $i . ': ' . $counts['h'.$i]; }
        return array( 'points' => $points, 'summary' => implode( ' · ', $parts ), 'warnings' => $warnings );
    }

    private static function heading_counts( $headings ) {
        $counts = array();
        for ( $i = 1; $i <= 6; $i++ ) { $counts['h'.$i] = count( (array) ( $headings['h'.$i] ?? array() ) ); }
        return $counts;
    }

    private static function score_label( $score ) {
        $score = absint( $score );
        if ( $score >= 90 ) { return __( 'Excellent', 'dma-internlink-mapper' ); }
        if ( $score >= 75 ) { return __( 'Good', 'dma-internlink-mapper' ); }
        if ( $score >= 60 ) { return __( 'Needs work', 'dma-internlink-mapper' ); }
        return __( 'Poor', 'dma-internlink-mapper' );
    }

    private static function lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
    }
}
