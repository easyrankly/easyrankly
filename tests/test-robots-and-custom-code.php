<?php
/** Robots and custom-code sanitization regressions. */

final class ERankly_Robots_And_Custom_Code_Test extends WP_UnitTestCase {

	public function test_indexifembedded_is_only_emitted_with_noindex(): void {
		$with_noindex = erankly_apply_global_entity_robot_row(
			array( 'noindex' => true ),
			array( 'indexifembedded' => 1 )
		);
		$without_noindex = erankly_apply_global_entity_robot_row(
			array( 'nosnippet' => true ),
			array( 'indexifembedded' => 1 )
		);

		$this->assertTrue( $with_noindex['indexifembedded'] );
		$this->assertArrayNotHasKey( 'indexifembedded', $without_noindex );
	}

	public function test_custom_code_byte_truncation_keeps_valid_utf8(): void {
		$limit = erankly_custom_code_max_bytes();
		$value = str_repeat( 'a', $limit - 1 ) . "\xE2\x82\xACtail";
		$clean = erankly_truncate_custom_code_bytes( $value, $limit );

		$this->assertLessThanOrEqual( $limit, strlen( $clean ) );
		$this->assertSame( 1, preg_match( '//u', $clean ) );
		$this->assertSame( $limit - 1, strlen( $clean ) );
	}

	public function test_custom_code_blocks_have_a_combined_location_budget(): void {
		$limit  = erankly_custom_code_max_total_bytes();
		$blocks = erankly_sanitize_custom_code_blocks(
			array(
				array( 'enabled' => 1, 'code' => str_repeat( 'a', $limit ) ),
				array( 'enabled' => 1, 'code' => 'second block' ),
			)
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( $limit, strlen( (string) $blocks[0]['code'] ) );
	}

	public function test_one_marked_legacy_block_can_survive_the_ten_block_ui_limit(): void {
		$input = array_fill(
			0,
			erankly_custom_code_max_blocks(),
			array(
				'enabled'         => 1,
				'code'            => '<meta name="probe" content="1">',
				'target_contexts' => array( 'singular' ),
			)
		);
		$input[] = erankly_custom_code_migrated_block( '<meta name="legacy" content="1">' );

		$blocks = erankly_sanitize_custom_code_blocks( $input );

		$this->assertCount( erankly_custom_code_max_blocks() + 1, $blocks );
		$this->assertSame( 1, $blocks[ erankly_custom_code_max_blocks() ]['legacy_migrated'] );
	}
}
