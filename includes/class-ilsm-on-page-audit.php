<?php
/**
 * Read-only, same-site on-page SEO audit.
 *
 * @package Internal_Link_SEO_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_On_Page_Audit {
	/** Register the authenticated audit endpoint. */
	public static function register() {
		add_action( 'wp_ajax_ilsm_on_page_audit', array( __CLASS__, 'ajax' ) );
	}

	/** Render the admin workspace. */
	public static function render() {
		$default_url = home_url( '/' );
		echo '<section class="ilsm-panel ilsm-onpage-hero"><div><span class="ilsm-onpage-eyebrow">' . esc_html__( 'Rendered HTML audit', 'dma-internlink-mapper' ) . '</span><h2>' . esc_html__( 'Audit one page and focus keyphrase', 'dma-internlink-mapper' ) . '</h2><p>' . esc_html__( 'Check the current public HTML, content signals, indexability, structured data, links, images, robots rules, and AI crawler access. The audit never edits content.', 'dma-internlink-mapper' ) . '</p></div><div class="ilsm-onpage-safety"><i class="fa fa-lock" aria-hidden="true"></i><strong>' . esc_html__( 'Same-site URLs only', 'dma-internlink-mapper' ) . '</strong><span>' . esc_html__( 'Prevents unsafe external requests.', 'dma-internlink-mapper' ) . '</span></div></section>';
		echo '<section class="ilsm-panel"><form id="ilsm-onpage-form" class="ilsm-onpage-form"><label><span>' . esc_html__( 'Page URL', 'dma-internlink-mapper' ) . '</span><input id="ilsm-onpage-url" type="url" required value="' . esc_attr( $default_url ) . '" placeholder="' . esc_attr( home_url( '/example-page/' ) ) . '"></label><label><span>' . esc_html__( 'Focus keyphrase', 'dma-internlink-mapper' ) . '</span><input id="ilsm-onpage-keyphrase" type="text" required maxlength="200" placeholder="' . esc_attr__( 'Example: Morocco desert tours', 'dma-internlink-mapper' ) . '"></label><button class="ilsm-btn ilsm-btn-primary ilsm-btn-large" type="submit"><i class="fa fa-search" aria-hidden="true"></i> ' . esc_html__( 'Run On-Page Audit', 'dma-internlink-mapper' ) . '</button></form><p class="description">' . esc_html__( 'A score summarizes observable checks; it is not a ranking guarantee and does not replace Search Console or human editorial review.', 'dma-internlink-mapper' ) . '</p></section>';
		echo '<div id="ilsm-onpage-status" class="ilsm-onpage-status" aria-live="polite"></div><div id="ilsm-onpage-results" class="ilsm-onpage-results" hidden></div>';
	}

	/** Run an authenticated same-site audit. */
	public static function ajax() {
		if ( ! current_user_can( 'ilsm_view_reports' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dma-internlink-mapper' ) ), 403 );
		}
		check_ajax_referer( 'ilsm_admin', 'nonce' );

		$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$keyphrase = sanitize_text_field( wp_unslash( $_POST['keyphrase'] ?? '' ) );
		if ( '' === $url || '' === $keyphrase || ILSM_Text::length( $keyphrase ) > 200 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid page URL and focus keyphrase.', 'dma-internlink-mapper' ) ), 400 );
		}
		if ( ! ILSM_Link_Normalizer::is_internal( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'For security, only public URLs on this WordPress site can be audited.', 'dma-internlink-mapper' ) ), 400 );
		}

		$normalized = ILSM_Link_Normalizer::normalize( $url );
		$post_id = absint( url_to_postid( $normalized ) );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || ! ILSM_SEO_Inspector::is_reportable( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'This URL could not be resolved to a public, supported WordPress content item.', 'dma-internlink-mapper' ) ), 404 );
		}

		$snapshot = ILSM_Rendered_Page::snapshot( $post, false );
		if ( empty( $snapshot['ok'] ) || empty( $snapshot['verified'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $snapshot['error'] ?? __( 'The rendered public page could not be verified.', 'dma-internlink-mapper' ) ) ), 502 );
		}

		wp_send_json_success( self::analyze( $post, $snapshot, $keyphrase ) );
	}

	/** Build a transparent weighted report. */
	private static function analyze( WP_Post $post, $snapshot, $keyphrase ) {
		$checks = array();
		$add = static function( $category, $status, $label, $evidence, $recommendation, $weight = 1, $action_url = '', $action_label = '' ) use ( &$checks ) {
			$check = array(
				'category' => sanitize_key( $category ),
				'status' => in_array( $status, array( 'pass', 'warning', 'fail', 'info' ), true ) ? $status : 'info',
				'label' => sanitize_text_field( $label ),
				'evidence' => sanitize_text_field( $evidence ),
				'recommendation' => sanitize_text_field( $recommendation ),
				'weight' => max( 0, absint( $weight ) ),
			);
			if ( $action_url ) {
				$check['action_url'] = esc_url_raw( $action_url );
				$check['action_label'] = sanitize_text_field( $action_label );
			}
			$checks[] = $check;
		};

		$title = trim( (string) ( $snapshot['seo_title'] ?? '' ) );
		$meta = trim( (string) ( $snapshot['meta_description'] ?? '' ) );
		$canonical = trim( (string) ( $snapshot['canonical'] ?? '' ) );
		$body = trim( (string) ( $snapshot['body_text'] ?? '' ) );
		$headings = (array) ( $snapshot['headings'] ?? array() );
		$images = (array) ( $snapshot['images'] ?? array() );
		$links = (array) ( $snapshot['links'] ?? array() );
		$word_count = absint( $snapshot['word_count'] ?? 0 );
		$title_len = ILSM_Text::length( $title );
		$meta_len = ILSM_Text::length( $meta );
		$keyword = self::normalize_phrase( $keyphrase );
		$contains = static function( $haystack ) use ( $keyword ) { return '' !== $keyword && false !== strpos( self::normalize_phrase( $haystack ), $keyword ); };
		$search_metrics = ILSM_Search_Console_Import::metrics_for_url( (string) ( $snapshot['url'] ?? '' ) );
		if ( ! empty( $search_metrics['impressions'] ) ) {
			/* translators: 1: clicks, 2: impressions, 3: average position. */
			$search_evidence = sprintf( __( '%1$s clicks, %2$s impressions, average position %3$s in the imported report period.', 'dma-internlink-mapper' ), number_format_i18n( absint( $search_metrics['clicks'] ?? 0 ) ), number_format_i18n( absint( $search_metrics['impressions'] ?? 0 ) ), number_format_i18n( (float) ( $search_metrics['position'] ?? 0 ), 1 ) );
			$add( 'search', 'info', __( 'Imported Search Console evidence', 'dma-internlink-mapper' ), $search_evidence, __( 'Use this historical evidence to prioritize review; it does not alter the technical SEO score or prove current indexation.', 'dma-internlink-mapper' ), 0 );
		}

		$add( 'technical', 200 === absint( $snapshot['http_status'] ?? 0 ) ? 'pass' : 'fail', __( 'HTTP status', 'dma-internlink-mapper' ), 'HTTP ' . absint( $snapshot['http_status'] ?? 0 ), __( 'Indexable pages should normally return HTTP 200.', 'dma-internlink-mapper' ), 5 );
		$add( 'technical', ! empty( $snapshot['indexable'] ) ? 'pass' : 'fail', __( 'Indexability', 'dma-internlink-mapper' ), ! empty( $snapshot['indexable'] ) ? __( 'No noindex directive detected.', 'dma-internlink-mapper' ) : __( 'A noindex directive was detected.', 'dma-internlink-mapper' ), __( 'Confirm that the page should be indexable before changing robots directives.', 'dma-internlink-mapper' ), 6 );
		$canonical_ok = $canonical && untrailingslashit( $canonical ) === untrailingslashit( (string) $snapshot['url'] );
		$add( 'technical', $canonical_ok ? 'pass' : ( $canonical ? 'warning' : 'fail' ), __( 'Canonical URL', 'dma-internlink-mapper' ), $canonical ?: __( 'Missing', 'dma-internlink-mapper' ), __( 'Use one valid canonical that represents the preferred public URL.', 'dma-internlink-mapper' ), 5 );
		$add( 'technical', 1 === absint( count( (array) ( $headings['h1'] ?? array() ) ) ) ? 'pass' : 'warning', __( 'Primary heading', 'dma-internlink-mapper' ), sprintf( '%d H1', count( (array) ( $headings['h1'] ?? array() ) ) ), __( 'Use one clear primary heading that describes the page.', 'dma-internlink-mapper' ), 4 );

		$add( 'metadata', $title_len >= 20 && $title_len <= 70 ? 'pass' : ( $title ? 'warning' : 'fail' ), __( 'Title element', 'dma-internlink-mapper' ), sprintf( '%d characters: %s', $title_len, $title ?: __( 'Missing', 'dma-internlink-mapper' ) ), __( 'Write a concise, descriptive title for people; Google may generate a different title link.', 'dma-internlink-mapper' ), 5 );
		$add( 'metadata', $meta_len >= 100 && $meta_len <= 170 ? 'pass' : ( $meta ? 'warning' : 'fail' ), __( 'Meta description', 'dma-internlink-mapper' ), sprintf( '%d characters', $meta_len ), __( 'Write a useful page-specific summary; Google may choose another snippet.', 'dma-internlink-mapper' ), 4 );

		$add( 'content', $word_count >= 300 ? 'pass' : ( $word_count >= 150 ? 'warning' : 'fail' ), __( 'Visible content', 'dma-internlink-mapper' ), sprintf( '%d words', $word_count ), __( 'Cover the user task completely. Word count is diagnostic, not a Google ranking target.', 'dma-internlink-mapper' ), 5 );
		$missing_alt = absint( $images['missing_alt'] ?? 0 );
		$empty_alt   = absint( $images['empty_alt'] ?? 0 );
		/* translators: 1: total images, 2: images without an ALT attribute, 3: images with an intentionally empty ALT attribute. */
		$alt_evidence = sprintf( __( '%1$d images; %2$d missing ALT; %3$d empty ALT', 'dma-internlink-mapper' ), absint( $images['total'] ?? 0 ), $missing_alt, $empty_alt );
		$add( 'content', 0 === $missing_alt ? ( $empty_alt ? 'info' : 'pass' ) : 'warning', __( 'Image ALT attributes', 'dma-internlink-mapper' ), $alt_evidence, __( 'Add concise contextual ALT text to informative images. Keep ALT empty only for decorative images and review empty values in their visual context.', 'dma-internlink-mapper' ), 4 );
		$internal = 0; $external = 0;
		foreach ( $links as $link ) { if ( 'internal' === ( $link['scope'] ?? '' ) ) { $internal++; } else { $external++; } }
		$add( 'content', $internal > 0 ? 'pass' : 'warning', __( 'Crawlable internal links', 'dma-internlink-mapper' ), sprintf( '%d internal; %d external', $internal, $external ), __( 'Link naturally to useful related pages with descriptive anchor text.', 'dma-internlink-mapper' ), 3 );

		global $wpdb;
		$scan_id = ILSM_Database::latest_completed_scan_id();
		if ( $scan_id ) {
			$links_table = ILSM_Database::table( 'links' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned scan data must be read directly and must not be served from a persistent object cache.
			$incoming_anchor = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) total,COALESCE(SUM(LOWER(TRIM(anchor_text))=LOWER(TRIM(%s))),0) exact_count FROM %i WHERE scan_id=%d AND target_post_id=%d AND destination_type<>'external'",
					$keyphrase,
					$links_table,
					$scan_id,
					$post->ID
				),
				ARRAY_A
			);
			$total_incoming = absint( $incoming_anchor['total'] ?? 0 );
			$exact_incoming = absint( $incoming_anchor['exact_count'] ?? 0 );
			$opportunity_url = add_query_arg(
				array(
					'page' => 'ilsm-link-opportunities',
					'target_post_id' => $post->ID,
					'keyword' => $keyphrase,
				),
				admin_url( 'admin.php' )
			);
			if ( $exact_incoming ) {
				/* translators: 1: number of incoming links using the exact keyphrase, 2: total incoming internal links. */
				$anchor_evidence = sprintf( __( '%1$d of %2$d incoming internal links use this exact keyphrase as anchor text.', 'dma-internlink-mapper' ), $exact_incoming, $total_incoming );
			} elseif ( $total_incoming ) {
				/* translators: %d: total incoming internal links. */
				$anchor_evidence = sprintf( __( 'None of the %d incoming internal links use this exact keyphrase as anchor text.', 'dma-internlink-mapper' ), $total_incoming );
			} else {
				$anchor_evidence = __( 'No incoming internal links to this page were found in the latest completed scan.', 'dma-internlink-mapper' );
			}
			$add(
				'keyphrase',
				$exact_incoming ? 'pass' : 'warning',
				__( 'Incoming links using this keyphrase', 'dma-internlink-mapper' ),
				$anchor_evidence,
				__( 'Use the exact keyphrase or a close descriptive variation only where it reads naturally on a relevant source page. Avoid repetitive exact-match anchors.', 'dma-internlink-mapper' ),
				0,
				$opportunity_url,
				__( 'Find the best source pages', 'dma-internlink-mapper' )
			);
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/u', $body, 2 ) ?: array();
		$first_sentence = trim( (string) ( $sentences[0] ?? '' ) );
		if ( '' === $first_sentence ) { $first_sentence = implode( ' ', array_slice( preg_split( '/\s+/u', $body ) ?: array(), 0, 40 ) ); }
		$h1_text = implode( ' ', (array) ( $headings['h1'] ?? array() ) );
		$subheadings = implode( ' ', array_merge( (array) ( $headings['h2'] ?? array() ), (array) ( $headings['h3'] ?? array() ) ) );
		$slug = rawurldecode( (string) wp_parse_url( (string) $snapshot['url'], PHP_URL_PATH ) );
		$url_phrase = preg_replace( '/[-_\/]+/u', ' ', $slug );
		$title_exact = $contains( $title );
		$h1_exact = $contains( $h1_text );
		$url_exact = $contains( $url_phrase );
		foreach ( array(
			array( __( 'Exact keyphrase in SEO title', 'dma-internlink-mapper' ), $title_exact, $title, 8, true ),
			array( __( 'Exact keyphrase in H1', 'dma-internlink-mapper' ), $h1_exact, $h1_text, 8, true ),
			array( __( 'Exact keyphrase in URL', 'dma-internlink-mapper' ), $url_exact, $slug, 0, false ),
			array( __( 'Keyphrase in first sentence', 'dma-internlink-mapper' ), $contains( $first_sentence ), ILSM_Text::substring( $first_sentence, 0, 220 ), 3, false ),
			array( __( 'Keyphrase in meta description', 'dma-internlink-mapper' ), $contains( $meta ), $meta, 3, false ),
			array( __( 'Keyphrase in subheading', 'dma-internlink-mapper' ), $contains( $subheadings ), ILSM_Text::substring( $subheadings, 0, 180 ), 2, false ),
		) as $item ) {
			$status = $item[1] ? 'pass' : ( $item[4] ? 'fail' : 'warning' );
			if ( $item[1] ) {
				/* translators: %s: Focus keyphrase found in the audited page element. */
				$evidence = sprintf( __( 'Exact phrase found: %s', 'dma-internlink-mapper' ), $keyphrase );
			} else {
				/* translators: %s: Current text of the audited page element. */
				$evidence = sprintf( __( 'Exact phrase not found. Current value: %s', 'dma-internlink-mapper' ), $item[2] ?: __( 'Empty', 'dma-internlink-mapper' ) );
			}
			$add( 'keyphrase', $status, $item[0], $evidence, __( 'Use the exact phrase once where it reads naturally; avoid repetition and keyword stuffing.', 'dma-internlink-mapper' ), $item[3] );
		}
		$core_missing = array();
		if ( ! $title_exact ) { $core_missing[] = __( 'SEO title', 'dma-internlink-mapper' ); }
		if ( ! $h1_exact ) { $core_missing[] = __( 'H1', 'dma-internlink-mapper' ); }
		if ( ! $url_exact ) { $core_missing[] = __( 'URL', 'dma-internlink-mapper' ); }
		if ( $core_missing ) {
			/* translators: %s: Comma-separated page elements missing the focus keyphrase. */
			$core_evidence = sprintf( __( 'Missing from: %s', 'dma-internlink-mapper' ), implode( ', ', $core_missing ) );
		} else {
			$core_evidence = __( 'Exact phrase is present in the SEO title, H1, and URL.', 'dma-internlink-mapper' );
		}
		$core_status = ( ! $title_exact || ! $h1_exact ) ? 'fail' : ( $url_exact ? 'pass' : 'info' );
		$add( 'keyphrase', $core_status, __( 'Core exact-keyphrase placement', 'dma-internlink-mapper' ), $core_evidence, __( 'Treat the title and H1 as prominent relevance signals, but keep every element useful and readable for people. Do not change an established URL only to add an exact keyphrase; preserve it unless a carefully planned redirect is genuinely necessary.', 'dma-internlink-mapper' ), 0 );
		$body_occurrences = '' !== $keyword ? substr_count( self::normalize_phrase( $body ), $keyword ) : 0;
		$add( 'keyphrase', 'info', __( 'Exact keyphrase usage', 'dma-internlink-mapper' ), sprintf( '%d exact occurrences', $body_occurrences ), __( 'There is no required keyword density. Prefer complete, natural coverage and related terminology.', 'dma-internlink-mapper' ), 0 );

		$schema_types = array_values( array_unique( array_filter( (array) ( $snapshot['schema_types'] ?? array() ) ) ) );
		$add( 'structured', $schema_types ? 'pass' : 'info', __( 'JSON-LD structured data', 'dma-internlink-mapper' ), $schema_types ? implode( ', ', $schema_types ) : __( 'No JSON-LD types detected.', 'dma-internlink-mapper' ), __( 'Use only schema that matches visible content and validate eligibility with Google Rich Results Test.', 'dma-internlink-mapper' ), 2 );

		$robots = self::robots_status( (string) $snapshot['url'] );
		foreach ( $robots as $agent => $allowed ) {
			$label = sprintf( '%s access', $agent );
			$recommendation = 'Googlebot' === $agent ? __( 'Google Search and its AI search features require crawl access to understand the page.', 'dma-internlink-mapper' ) : __( 'Crawler access is a publisher choice. Review the crawler purpose before allowing or blocking it.', 'dma-internlink-mapper' );
			$status = 'Googlebot' === $agent ? ( $allowed ? 'pass' : 'warning' ) : 'info';
			$add( 'crawlers', $status, $label, $allowed ? __( 'Allowed by robots.txt', 'dma-internlink-mapper' ) : __( 'Blocked by robots.txt', 'dma-internlink-mapper' ), $recommendation, 'Googlebot' === $agent ? 5 : 0 );
		}

		$earned = 0; $possible = 0; $counts = array( 'pass' => 0, 'warning' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $checks as $check ) {
			$counts[ $check['status'] ]++;
			if ( $check['weight'] ) { $possible += $check['weight']; $earned += 'pass' === $check['status'] ? $check['weight'] : ( 'warning' === $check['status'] ? $check['weight'] * 0.5 : 0 ); }
		}

		return array(
			'url' => esc_url_raw( $snapshot['url'] ), 'post_id' => $post->ID, 'title' => sanitize_text_field( get_the_title( $post ) ),
			'keyphrase' => $keyphrase, 'score' => $possible ? (int) round( 100 * $earned / $possible ) : 0,
			'counts' => $counts, 'checks' => $checks, 'generated_at' => current_time( 'mysql' ),
			'notice' => __( 'This audit measures observable page signals. Google uses many systems and does not publish a checklist that guarantees rankings.', 'dma-internlink-mapper' ),
		);
	}

	/** Approximate applicable robots.txt access for named well-behaved crawlers. */
	private static function robots_status( $url ) {
		$response = wp_safe_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 5, 'redirection' => 2, 'reject_unsafe_urls' => true, 'sslverify' => true, 'limit_response_size' => 262144 ) );
		$body = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$out = array();
		foreach ( array( 'Googlebot', 'OAI-SearchBot', 'GPTBot', 'ChatGPT-User', 'Google-Extended' ) as $agent ) {
			$out[ $agent ] = self::robots_allows( $body, $agent, $path );
		}
		return $out;
	}

	/** Minimal longest-match robots evaluator for Disallow/Allow rules. */
	private static function robots_allows( $robots, $agent, $path ) {
		if ( '' === trim( $robots ) ) { return true; }
		$groups = array();
		$current = array( 'agents' => array(), 'rules' => array() );
		foreach ( preg_split( '/\R/u', preg_replace( '/\s*#.*$/m', '', $robots ) ) ?: array() as $line ) {
			if ( ! preg_match( '/^\s*([a-z-]+)\s*:\s*(.*?)\s*$/i', $line, $match ) ) { continue; }
			$directive = strtolower( $match[1] );
			if ( 'user-agent' === $directive ) {
				if ( $current['rules'] ) { $groups[] = $current; $current = array( 'agents' => array(), 'rules' => array() ); }
				$current['agents'][] = strtolower( $match[2] );
			} elseif ( in_array( $directive, array( 'allow', 'disallow' ), true ) && $current['agents'] ) {
				$current['rules'][] = array( $directive, trim( $match[2] ) );
			}
		}
		if ( $current['agents'] ) { $groups[] = $current; }
		$specific = array_values( array_filter( $groups, static function( $group ) use ( $agent ) { return in_array( strtolower( $agent ), $group['agents'], true ); } ) );
		$applicable = $specific ?: array_values( array_filter( $groups, static function( $group ) { return in_array( '*', $group['agents'], true ); } ) );
		$best = array( 'length' => -1, 'allow' => true );
		foreach ( $applicable as $group ) {
			foreach ( $group['rules'] as $rule ) {
				$pattern = trim( $rule[1] );
				if ( '' === $pattern || 0 !== strpos( $path, rtrim( $pattern, '$' ) ) ) { continue; }
				$length = strlen( $pattern );
				$allow = 'allow' === $rule[0];
				if ( $length > $best['length'] || ( $length === $best['length'] && $allow ) ) { $best = array( 'length' => $length, 'allow' => $allow ); }
			}
		}
		return (bool) $best['allow'];
	}

	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	/** Normalize case and whitespace while preserving exact word order. */
	private static function normalize_phrase( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', self::lower( $value ) ) );
	}
}
