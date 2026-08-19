<?php
/** Explainable, local-only search-intent classification and compatibility. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ILSM_Search_Intent {
	private static $labels = array(
		'informational' => 'Informational',
		'commercial'    => 'Commercial',
		'transactional' => 'Transactional',
		'navigational'  => 'Navigational',
	);

	/** Classify a post using weighted title, focus phrase, headings/body and post type. */
	public static function classify_post( $post, $live_body_text = '' ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) { return self::empty_result(); }
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$focus = class_exists( 'ILSM_SEO_Provider_Registry' ) ? implode( ' ', ILSM_SEO_Provider_Registry::focus_keyphrases( $post->ID ) ) : '';
		$body  = '' !== trim( (string) $live_body_text ) ? (string) $live_body_text : wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true );
		$scores = array_fill_keys( array_keys( self::$labels ), 0.0 );
		$evidence = array_fill_keys( array_keys( self::$labels ), array() );
		self::score_text( $title . ' ' . $focus, 4.0, $scores, $evidence );
		self::score_text( ILSM_Text::substring( $body, 0, 50000 ), 1.0, $scores, $evidence );
		$type = sanitize_key( $post->post_type );
		if ( in_array( $type, array( 'trip', 'product', 'tour', 'experience', 'transfer' ), true ) ) { $scores['transactional'] += 8; $scores['commercial'] += 4; $evidence['transactional'][] = __( 'Commercial content type', 'dma-internlink-mapper' ); }
		if ( 'post' === $type ) { $scores['informational'] += 4; $evidence['informational'][] = __( 'Editorial post type', 'dma-internlink-mapper' ); }
		return self::finalize( $scores, $evidence );
	}

	private static function score_text( $text, $weight, &$scores, &$evidence ) {
		$text = ILSM_Text::lower( ' ' . preg_replace( '/[^\p{L}\p{N}\s?]+/u', ' ', (string) $text ) . ' ' );
		$signals = array(
			'informational' => array( 'how'=>3,'what'=>3,'why'=>3,'when'=>3,'where'=>3,'guide'=>4,'tips'=>4,'learn'=>3,'history'=>3,'itinerary'=>2,'best time'=>4,'things to do'=>4,'distance'=>3,'weather'=>3,'question'=>2 ),
			'commercial'    => array( 'best'=>3,'compare'=>5,'comparison'=>5,'review'=>4,'top'=>2,'options'=>3,'which'=>3,'recommended'=>4,'luxury'=>2,'private'=>2,'affordable'=>3,'price'=>3,'cost'=>3 ),
			'transactional' => array( 'book'=>6,'booking'=>6,'reserve'=>6,'buy'=>6,'enquire'=>5,'inquire'=>5,'contact us'=>4,'get a quote'=>6,'availability'=>5,'departures'=>3,'request'=>3,'whatsapp'=>3,'from €'=>4,'per person'=>4 ),
			'navigational'  => array( 'login'=>7,'log in'=>7,'dashboard'=>7,'account'=>6,'contact'=>4,'about us'=>4,'homepage'=>5,'official site'=>5,'customer service'=>5,'directions'=>4 ),
		);
		foreach ( $signals as $intent => $phrases ) {
			foreach ( $phrases as $phrase => $points ) {
				if ( false !== ILSM_Text::position( $text, ' ' . $phrase . ' ' ) ) { $scores[ $intent ] += $points * $weight; if ( count( $evidence[ $intent ] ) < 4 ) { $evidence[ $intent ][] = $phrase; } }
			}
		}
		if ( false !== strpos( $text, '?' ) ) { $scores['informational'] += 3 * $weight; $evidence['informational'][] = __( 'Question format', 'dma-internlink-mapper' ); }
	}

	private static function finalize( $scores, $evidence ) {
		arsort( $scores ); $total = array_sum( $scores ); $keys = array_keys( $scores );
		if ( $total <= 0 ) { return self::empty_result(); }
		$percent = array(); foreach ( $scores as $key => $score ) { $percent[ $key ] = (int) round( $score / $total * 100 ); }
		$primary = $keys[0]; $secondary = isset( $keys[1] ) && $percent[ $keys[1] ] >= 25 ? $keys[1] : '';
		$confidence = min( 95, max( 35, 50 + ( $percent[ $primary ] - ( $percent[ $keys[1] ] ?? 0 ) ) ) );
		return array( 'primary'=>$primary, 'label'=>self::$labels[ $primary ], 'secondary'=>$secondary, 'secondary_label'=>$secondary ? self::$labels[ $secondary ] : '', 'confidence'=>$confidence, 'scores'=>$percent, 'evidence'=>array_values( array_unique( $evidence[ $primary ] ) ) );
	}

	private static function empty_result() { return array( 'primary'=>'informational', 'label'=>self::$labels['informational'], 'secondary'=>'', 'secondary_label'=>'', 'confidence'=>35, 'scores'=>array( 'informational'=>25,'commercial'=>25,'transactional'=>25,'navigational'=>25 ), 'evidence'=>array( __( 'No strong intent signal', 'dma-internlink-mapper' ) ) ); }

	/** Intent is only a bounded supporting signal; topical relevance remains decisive. */
	public static function compatibility_boost( $source, $target ) {
		if ( (int) ( $source['confidence'] ?? 0 ) < 55 || (int) ( $target['confidence'] ?? 0 ) < 55 ) { return 0; }
		$pair = (string) ( $source['primary'] ?? '' ) . '>' . (string) ( $target['primary'] ?? '' );
		$strong = array( 'informational>commercial', 'informational>transactional', 'commercial>transactional', 'informational>informational', 'transactional>informational' );
		return in_array( $pair, $strong, true ) ? ( 'commercial>transactional' === $pair ? 8 : 5 ) : ( false !== strpos( $pair, 'navigational' ) ? -4 : 0 );
	}
}
