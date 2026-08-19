<?php

use PHPUnit\Framework\TestCase;

final class LinkNormalizerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ilsm_test_home_url'] = 'https://example.com';
		$GLOBALS['ilsm_test_site_url'] = 'https://example.com';
	}

	public function test_internal_url_is_canonicalized_and_query_is_sorted() {
		$this->assertSame(
			'https://example.com/path?a=1&b=2',
			ILSM_Link_Normalizer::normalize( 'https://www.example.com/path?b=2&a=1#section' )
		);
	}

	public function test_non_web_schemes_are_rejected() {
		$this->assertSame( '', ILSM_Link_Normalizer::normalize( 'javascript:alert(1)' ) );
		$this->assertSame( '', ILSM_Link_Normalizer::normalize_any( 'data:text/plain,test' ) );
		$this->assertFalse( ILSM_Link_Normalizer::is_internal( 'ftp://example.com/file' ) );
	}

	public function test_external_urls_are_not_internal() {
		$this->assertFalse( ILSM_Link_Normalizer::is_internal( 'https://cdn.example.net/file' ) );
		$this->assertSame( '', ILSM_Link_Normalizer::normalize( 'https://cdn.example.net/file' ) );
	}

	public function test_www_alias_is_internal_but_unrelated_subdomain_is_not() {
		$this->assertTrue( ILSM_Link_Normalizer::is_internal( 'https://www.example.com/page' ) );
		$this->assertFalse( ILSM_Link_Normalizer::is_internal( 'https://shop.example.com/page' ) );
	}

	public function test_credentials_are_rejected() {
		$this->assertFalse( ILSM_Link_Normalizer::is_internal( 'https://user:pass@example.com/private' ) );
		$this->assertSame( '', ILSM_Link_Normalizer::normalize_any( 'https://user:pass@example.com/private' ) );
	}

	public function test_non_standard_port_is_not_internal_unless_configured() {
		$this->assertFalse( ILSM_Link_Normalizer::is_internal( 'https://example.com:8443/private' ) );
		$GLOBALS['ilsm_test_home_url'] = 'https://example.com:8443';
		$this->assertTrue( ILSM_Link_Normalizer::is_internal( 'https://example.com:8443/page' ) );
	}
}
