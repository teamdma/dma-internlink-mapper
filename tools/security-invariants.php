<?php
/** Fast dependency-free security regression checks. */

$root = dirname( __DIR__ );
$runtime = array( $root . '/dma-internlink-mapper.php', $root . '/uninstall.php' );
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$runtime[] = $file->getPathname();
	}
}

$failures = array();
$forbidden = array(
	'wp_ajax_nopriv_' => 'Privileged plugin AJAX endpoints must remain authenticated.',
	'<script'          => 'Runtime PHP must use the WordPress script enqueue APIs.',
	'<style'           => 'Runtime PHP must use the WordPress style enqueue APIs.',
	'onclick='         => 'Inline event handlers are not allowed in runtime PHP.',
	'javascript:'      => 'javascript: URLs are not allowed in runtime PHP.',
);

foreach ( $runtime as $file ) {
	$contents = strtolower( file_get_contents( $file ) );
	foreach ( $forbidden as $needle => $reason ) {
		if ( false !== strpos( $contents, strtolower( $needle ) ) ) {
			$failures[] = $file . ': ' . $reason;
		}
	}
}

$broken_file = $root . '/includes/class-ilsm-broken-link-maintenance.php';
$broken = file_get_contents( $broken_file );
$start = strpos( $broken, 'private static function process_bulk_resolve' );
$body = false === $start ? '' : substr( $broken, $start, 2500 );
$capability = strpos( $body, "current_user_can( 'ilsm_insert_links' )" );
$nonce = strpos( $body, "check_ajax_referer( 'ilsm_broken_links', 'nonce' )" );
$post = strpos( $body, '$_POST' );
if ( false === $capability || false === $nonce || false === $post || $capability > $post || $nonce > $post ) {
	$failures[] = $broken_file . ': broken-link request data must remain behind capability and nonce checks.';
}

$uninstall = file_get_contents( $root . '/uninstall.php' );
foreach ( array( "wp_clear_scheduled_hook( 'ilsm_broken_link_monitor' )", "'ilsm_broken_link_monitor_state'", "'ilsm_migration_lock'" ) as $required ) {
	if ( false === strpos( $uninstall, $required ) ) {
		$failures[] = $root . '/uninstall.php: missing cleanup invariant ' . $required;
	}
}

if ( $failures ) {
	fwrite( STDERR, "Security invariant check failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'Security invariants OK: ' . count( $runtime ) . " runtime PHP files\n";
