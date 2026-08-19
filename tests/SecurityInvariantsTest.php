<?php

use PHPUnit\Framework\TestCase;

final class SecurityInvariantsTest extends TestCase {
	private function runtime_php_files() {
		$root = dirname( __DIR__ );
		$files = array( $root . '/dma-internlink-mapper.php', $root . '/uninstall.php' );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}

	public function test_no_privileged_nopriv_ajax_endpoints_are_registered() {
		foreach ( $this->runtime_php_files() as $file ) {
			$this->assertStringNotContainsString( 'wp_ajax_nopriv_', file_get_contents( $file ), $file );
		}
	}

	public function test_runtime_php_does_not_print_script_or_style_tags() {
		foreach ( $this->runtime_php_files() as $file ) {
			$contents = strtolower( file_get_contents( $file ) );
			$this->assertStringNotContainsString( '<script', $contents, $file );
			$this->assertStringNotContainsString( '<style', $contents, $file );
		}
	}

	public function test_broken_link_request_is_gated_before_post_data_is_read() {
		$file = dirname( __DIR__ ) . '/includes/class-ilsm-broken-link-maintenance.php';
		$contents = file_get_contents( $file );
		$start = strpos( $contents, 'private static function process_bulk_resolve' );
		$this->assertNotFalse( $start );
		$body = substr( $contents, $start, 2500 );
		$capability = strpos( $body, "current_user_can( 'ilsm_insert_links' )" );
		$nonce = strpos( $body, "check_ajax_referer( 'ilsm_broken_links', 'nonce' )" );
		$post = strpos( $body, '$_POST' );
		$this->assertNotFalse( $capability );
		$this->assertNotFalse( $nonce );
		$this->assertNotFalse( $post );
		$this->assertLessThan( $post, $capability );
		$this->assertLessThan( $post, $nonce );
	}

	public function test_full_uninstall_clears_monitor_state_and_schedule() {
		$contents = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'ilsm_broken_link_monitor' )", $contents );
		$this->assertStringContainsString( "'ilsm_broken_link_monitor_state'", $contents );
		$this->assertStringContainsString( "'ilsm_migration_lock'", $contents );
	}
}
