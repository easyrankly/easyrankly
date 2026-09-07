<?php
/** Settings merge, robots.txt grouping and visibility-defaults regressions. */

final class ERankly_Settings_And_Robots_Regressions_Test extends WP_UnitTestCase {

	/**
	 * Loads the admin-only files these regressions live in.
	 *
	 * Both are required lazily on admin and REST requests, so under PHPUnit the settings registry is missing:
	 * erankly_settings_panel_keys() then guards with function_exists() and returns an empty list, which would
	 * make the merge assertions fail for the wrong reason, and the visibility renderer would be undefined.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ERANKLY_PATH . 'admin/settings-page.php';
		require_once ERANKLY_PATH . 'admin/field-renderers.php';
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';
		require_once ERANKLY_PATH . 'admin/settings/renderers.php';
	}

	public function tear_down(): void {
		// The settings cache is a static: without this it leaks the mutated store into later tests.
		erankly_clear_settings_cache();
		parent::tear_down();
	}

	public function test_robots_txt_parse_groups_keeps_repeated_user_agent_blocks_separate(): void {
		// Regression: array_unique() on the flat line list deleted a second "User-agent: *" header and glued its rules to the previous group.
		$parsed = erankly_robots_txt_parse_groups(
			array(
				'User-agent: *',
				'Disallow: /secret/',
				'User-agent: Googlebot',
				'Disallow: /',
				'User-agent: *',
				'Disallow: /private/',
			)
		);

		$this->assertCount( 3, $parsed['groups'] );
		$this->assertSame( array( '*' ), $parsed['groups'][0]['agents'] );
		$this->assertSame( array( 'Disallow: /secret/' ), $parsed['groups'][0]['rules'] );
		$this->assertSame( array( 'Googlebot' ), $parsed['groups'][1]['agents'] );
		$this->assertSame( array( 'Disallow: /' ), $parsed['groups'][1]['rules'] );
		$this->assertSame( array( '*' ), $parsed['groups'][2]['agents'] );
		$this->assertSame( array( 'Disallow: /private/' ), $parsed['groups'][2]['rules'] );

		$lines    = erankly_robots_txt_render_groups( $parsed );
		$reparsed = erankly_robots_txt_parse_groups( $lines );

		$this->assertCount( 3, $reparsed['groups'] );
		$this->assertSame( array( 'agents' => array( 'Googlebot' ), 'rules' => array( 'Disallow: /' ) ), $reparsed['groups'][1] );
		$this->assertSame( array( 'agents' => array( '*' ), 'rules' => array( 'Disallow: /private/' ) ), $reparsed['groups'][2] );
	}

	public function test_robots_txt_parse_groups_merges_consecutive_user_agent_headers_into_one_group(): void {
		// Regression: consecutive User-agent lines must share one group instead of spawning empty groups.
		$parsed = erankly_robots_txt_parse_groups(
			array(
				'User-agent: *',
				'User-agent: Googlebot',
				'Disallow: /',
			)
		);

		$this->assertCount( 1, $parsed['groups'] );
		$this->assertSame( array( '*', 'Googlebot' ), $parsed['groups'][0]['agents'] );
		$this->assertSame( array( 'Disallow: /' ), $parsed['groups'][0]['rules'] );

		$lines    = erankly_robots_txt_render_groups( $parsed );
		$reparsed = erankly_robots_txt_parse_groups( $lines );

		$this->assertCount( 1, $reparsed['groups'] );
		$this->assertSame( array( '*', 'Googlebot' ), $reparsed['groups'][0]['agents'] );
	}

	public function test_robots_txt_parse_groups_routes_sitemap_and_host_to_file_level_globals(): void {
		// Regression: Sitemap and Host are file-level directives and must not be swallowed by the preceding group.
		$parsed = erankly_robots_txt_parse_groups(
			array(
				'User-agent: *',
				'Disallow: /',
				'Sitemap: https://example.com/sitemap.xml',
				'Host: example.com',
			)
		);

		$this->assertSame( array( 'Sitemap: https://example.com/sitemap.xml', 'Host: example.com' ), $parsed['globals'] );
		$this->assertSame( array( 'Disallow: /' ), $parsed['groups'][0]['rules'] );

		$lines = erankly_robots_txt_render_groups( $parsed );

		$this->assertSame( array( 'Sitemap: https://example.com/sitemap.xml', 'Host: example.com' ), array_slice( $lines, -2 ) );
		$this->assertNotContains( 'Sitemap: https://example.com/sitemap.xml', array_slice( $lines, 0, -2 ) );
	}

	public function test_robots_txt_render_groups_dedupes_rules_within_a_group(): void {
		// Regression: dedupe must happen per group after parsing, not on the flat line list.
		$parsed = erankly_robots_txt_parse_groups(
			array(
				'User-agent: *',
				'Disallow: /a/',
				'Disallow: /a/',
				'Allow: /b/',
				'Allow: /b/',
			)
		);

		$this->assertSame( array( 'Disallow: /a/', 'Disallow: /a/', 'Allow: /b/', 'Allow: /b/' ), $parsed['groups'][0]['rules'] );
		$this->assertSame( array( 'User-agent: *', 'Disallow: /a/', 'Allow: /b/' ), erankly_robots_txt_render_groups( $parsed ) );
	}

	public function test_settings_collection_keys_list_every_block_builder(): void {
		// Regression: a cleared block builder submits no field at all, so every collection key must be known to the merge.
		$keys = erankly_settings_collection_keys();

		$this->assertContains( 'global_schema_blocks', $keys );
		$this->assertContains( 'head_code_blocks', $keys );
		$this->assertContains( 'body_open_code_blocks', $keys );
		$this->assertContains( 'body_close_code_blocks', $keys );
	}

	public function test_custom_code_panel_merge_clears_emptied_block_collections(): void {
		// Regression: an emptied Custom code panel submitted no block fields, so the merge restored the stored snippets.
		$settings                           = erankly_get_settings();
		$settings['global_schema_blocks']    = array( array( 'enabled' => 1, 'code' => '{"@type":"Thing"}' ) );
		$settings['head_code_blocks']        = array( array( 'enabled' => 1, 'code' => '<meta name="probe" content="head">' ) );
		$settings['body_open_code_blocks']   = array( array( 'enabled' => 1, 'code' => '<span>open</span>' ) );
		$settings['body_close_code_blocks']  = array( array( 'enabled' => 1, 'code' => '<span>close</span>' ) );

		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();

		$merged = erankly_merge_settings_submission( array(), 'custom-code' );

		$this->assertSame( array(), $merged['head_code_blocks'] );
		$this->assertSame( array(), $merged['body_open_code_blocks'] );
		$this->assertSame( array(), $merged['body_close_code_blocks'] );
		$this->assertSame( $settings['global_schema_blocks'], $merged['global_schema_blocks'] );
	}

	public function test_features_panel_merge_preserves_saved_block_collections(): void {
		// Regression: only the custom-code and schema panels own block collections, so a Features save must leave them untouched.
		$settings                           = erankly_get_settings();
		$settings['head_code_blocks']        = array( array( 'enabled' => 1, 'code' => '<meta name="probe" content="head">' ) );
		$settings['body_open_code_blocks']   = array( array( 'enabled' => 1, 'code' => '<span>open</span>' ) );
		$settings['body_close_code_blocks']  = array( array( 'enabled' => 1, 'code' => '<span>close</span>' ) );

		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();

		$merged = erankly_merge_settings_submission( array(), 'features' );

		$this->assertSame( $settings['head_code_blocks'], $merged['head_code_blocks'] );
		$this->assertSame( $settings['body_open_code_blocks'], $merged['body_open_code_blocks'] );
		$this->assertSame( $settings['body_close_code_blocks'], $merged['body_close_code_blocks'] );
	}

	public function test_hidden_disable_sitemap_field_is_rendered_when_checkbox_is_hidden(): void {
		// Regression: entities without a Disable sitemap checkbox need a hidden field so a save does not clear the stored flag.
		$settings                    = erankly_get_settings();
		$settings['simplified_mode'] = 0;

		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();

		ob_start();
		erankly_render_global_visibility_defaults( 'global_special_meta', 'author', false, false, false, true, false );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString(
			'name="' . ERANKLY_OPTION . '[global_special_meta][author][disable_sitemap]" value="1"',
			$html
		);
	}
}
