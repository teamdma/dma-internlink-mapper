<?php

use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {
	public function test_weak_anchor_detection_is_case_and_punctuation_insensitive() {
		$this->assertTrue( ILSM_Text::is_weak_anchor( 'READ MORE...' ) );
		$this->assertTrue( ILSM_Text::is_weak_anchor( 'Click here →' ) );
	}

	public function test_descriptive_short_anchor_is_not_marked_weak() {
		$this->assertFalse( ILSM_Text::is_weak_anchor( 'Pricing' ) );
		$this->assertFalse( ILSM_Text::is_weak_anchor( 'Hotels' ) );
	}
}
