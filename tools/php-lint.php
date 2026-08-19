<?php
/** Cross-platform PHP syntax check for runtime and test files. */

$root = dirname( __DIR__ );
$paths = array(
	$root . '/dma-internlink-mapper.php',
	$root . '/uninstall.php',
	$root . '/includes',
	$root . '/tests',
	$root . '/tools',
);

$files = array();
foreach ( $paths as $path ) {
	if ( is_file( $path ) ) {
		$files[] = $path;
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

sort( $files );
$failed = false;
foreach ( $files as $file ) {
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file );
	$output = array();
	$status = 0;
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		$failed = true;
		fwrite( STDERR, implode( PHP_EOL, $output ) . PHP_EOL );
	}
}

if ( $failed ) {
	exit( 1 );
}

echo 'PHP syntax OK: ' . count( $files ) . " files\n";
