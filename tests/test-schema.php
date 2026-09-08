<?php
/** Schema.org validation, REST meta, merge, modes, and builder regressions. */

final class ERankly_Schema_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase::tear_down() calls unregister_all_meta_keys(), which drops the meta the
		// plugin registered once on `init`. Without this the REST responses lose
		// _erankly_schema_blocks from the second test in the run onwards.
		erankly_register_meta();

		erankly_load_default_helpers();
		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
		require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
		require_once ERANKLY_PATH . 'includes/schema.php';
	}

	public function test_custom_json_ld_rejects_plain_json_objects(): void {
		$result = erankly_validate_custom_json_ld( '{"foo":"bar"}' );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'semantic', $result['code'] );
	}

	public function test_custom_json_ld_rejects_syntax_errors(): void {
		$result = erankly_validate_custom_json_ld( '{not json' );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'syntax', $result['code'] );
	}

	public function test_custom_json_ld_accepts_type_or_id_and_graph(): void {
		$this->assertTrue( erankly_validate_custom_json_ld( '{"@type":"Thing","name":"Test"}' )['valid'] );
		$this->assertTrue( erankly_validate_custom_json_ld( '{"@id":"https://example.com/#x"}' )['valid'] );
		$this->assertTrue(
			erankly_validate_custom_json_ld( '{"@graph":[{"@type":"Thing","name":"A"},{"@id":"#b"}]}' )['valid']
		);
	}

	public function test_custom_json_ld_rejects_empty_objects_and_mixed_arrays(): void {
		$this->assertFalse( erankly_validate_custom_json_ld( '{}' )['valid'] );
		$this->assertFalse( erankly_validate_custom_json_ld( '[{},{"@type":"Thing"}]' )['valid'] );
		$this->assertFalse( erankly_validate_custom_json_ld( '{"@graph":["Thing"]}' )['valid'] );
	}

	public function test_schema_blocks_rest_schema_declares_type_and_fields(): void {
		$schema = erankly_schema_blocks_rest_schema();

		$this->assertSame( 'array', $schema['type'] );
		$this->assertSame( array(), $schema['default'] );
		$this->assertSame( 'object', $schema['items']['type'] );
		$this->assertArrayHasKey( 'type', $schema['items']['properties'] );
		$this->assertArrayHasKey( 'fields', $schema['items']['properties'] );
		$this->assertSame( 'string', $schema['items']['properties']['fields']['properties']['custom_json']['type'] );
		$this->assertTrue( $schema['items']['additionalProperties'] );
	}

	public function test_rest_round_trip_never_returns_null_schema_blocks(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Schema REST',
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'meta' => array(
						'_erankly_schema_blocks' => array(
							array(
								'type'   => 'custom',
								'fields' => array(
									'custom_json' => '{"@type":"Thing","name":"Test"}',
								),
							),
						),
					),
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['meta']['_erankly_schema_blocks'] );
		$this->assertNotNull( $data['meta']['_erankly_schema_blocks'] );
		$this->assertSame( 'Thing', json_decode( $data['meta']['_erankly_schema_blocks'][0]['fields']['custom_json'], true )['@type'] );

		$get = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
		$get->set_param( 'context', 'edit' );
		$got = rest_do_request( $get );
		$this->assertSame( 200, $got->get_status() );
		$payload = $got->get_data();
		$this->assertIsArray( $payload['meta']['_erankly_schema_blocks'] );
		$this->assertCount( 1, $payload['meta']['_erankly_schema_blocks'] );
	}

	public function test_rest_empty_array_is_not_null(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$get     = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$get->set_param( 'context', 'edit' );
		$payload = rest_do_request( $get )->get_data();

		$this->assertIsArray( $payload['meta']['_erankly_schema_blocks'] );
		$this->assertSame( array(), $payload['meta']['_erankly_schema_blocks'] );
	}

	public function test_invalid_json_keeps_previous_valid_block(): void {
		$previous = array(
			array(
				'type'   => 'custom',
				'fields' => array(
					'custom_json' => '{"@type":"Thing","name":"Kept"}',
				),
			),
		);
		$clean    = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'   => 'custom',
					'fields' => array(
						'custom_json' => '{not json',
					),
				),
			),
			false,
			$previous
		);

		$this->assertSame( 'Kept', json_decode( $clean[0]['fields']['custom_json'], true )['name'] );
	}

	public function test_valid_json_clears_stale_invalid_notice(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_transient( 'erankly_invalid_json_ld_' . $user_id, 'Stale error', 5 * MINUTE_IN_SECONDS );

		$clean = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'   => 'custom',
					'fields' => array(
						'custom_json' => '{"@type":"Thing","name":"Valid"}',
					),
				),
			)
		);

		$this->assertCount( 1, $clean );
		$this->assertFalse( get_transient( 'erankly_invalid_json_ld_' . $user_id ) );
	}

	public function test_xss_payload_is_kept_as_json_string(): void {
		$json  = '{"@type":"Thing","name":"</script><script>alert(1)</script>"}';
		$clean = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'   => 'custom',
					'fields' => array(
						'custom_json' => $json,
					),
				),
			)
		);

		$this->assertStringContainsString( '</script>', $clean[0]['fields']['custom_json'] );
		$encoded = wp_json_encode(
			array( '@graph' => erankly_decode_custom_json_ld( $clean[0]['fields']['custom_json'] ) ),
			JSON_HEX_TAG
		);
		$this->assertStringNotContainsString( '</script>', $encoded );
	}

	public function test_same_id_nodes_merge_with_custom_overlay(): void {
		$graph = erankly_dedupe_schema_graph(
			array(
				array(
					'@type' => 'Organization',
					'@id'   => 'https://example.test/#organization',
					'name'  => 'Auto',
					'url'   => 'https://example.test/',
				),
				array(
					'@type'       => 'Organization',
					'@id'         => 'https://example.test/#organization',
					'description' => 'Custom description',
				),
			)
		);

		$this->assertCount( 1, $graph );
		$this->assertSame( 'Auto', $graph[0]['name'] );
		$this->assertSame( 'Custom description', $graph[0]['description'] );
	}

	public function test_merge_unions_types_and_dedupes_identical_nodes_without_id(): void {
		$graph = erankly_dedupe_schema_graph(
			array(
				array( '@type' => 'Person', 'name' => 'A' ),
				array( '@type' => 'Person', 'name' => 'A' ),
				array(
					'@type' => 'Person',
					'@id'   => '#p',
					'name'  => 'Base',
				),
				array(
					'@type' => 'Organization',
					'@id'   => '#p',
					'url'   => 'https://example.test/',
				),
			)
		);

		$this->assertCount( 2, $graph );
		$this->assertContains( 'Person', (array) $graph[1]['@type'] );
		$this->assertContains( 'Organization', (array) $graph[1]['@type'] );
		$this->assertSame( 'https://example.test/', $graph[1]['url'] );
	}

	public function test_event_datetime_rejects_invalid_values(): void {
		$this->assertSame( '', erankly_schema_event_datetime( 'not-a-date' ) );
		$this->assertNotSame( '', erankly_schema_event_datetime( '2026-09-06 10:00:00' ) );
	}

	public function test_event_finalize_requires_start_date_and_drops_earlier_end(): void {
		$this->assertSame( array(), erankly_schema_event_finalize( array( '@type' => 'Event' ) ) );
		$this->assertSame(
			array(),
			erankly_schema_event_finalize(
				array(
					'@type'     => 'Event',
					'startDate' => '2026-09-06T10:00:00+00:00',
				)
			)
		);

		$schema = erankly_schema_event_finalize(
			array(
				'@type'     => 'Event',
				'startDate' => '2026-09-06T10:00:00+00:00',
				'endDate'   => '2026-09-05T10:00:00+00:00',
				'location'  => array(
					'@type' => 'Place',
					'name'  => 'Venue',
				),
			)
		);

		$this->assertArrayNotHasKey( 'endDate', $schema );
		$this->assertSame( 'Venue', $schema['location']['name'] );
	}

	public function test_event_virtual_location_requires_url(): void {
		$this->assertSame(
			array(),
			erankly_schema_event_finalize(
				array(
					'@type'     => 'Event',
					'startDate' => '2026-09-06T10:00:00+00:00',
					'location'  => array(
						'@type' => 'VirtualLocation',
					),
				)
			)
		);

		$schema = erankly_schema_event_finalize(
			array(
				'@type'     => 'Event',
				'startDate' => '2026-09-06T10:00:00+00:00',
				'location'  => array(
					'@type' => 'VirtualLocation',
					'url'   => 'https://example.test/live',
				),
			)
		);

		$this->assertSame( 'VirtualLocation', $schema['location']['@type'] );
	}

	public function test_merge_conflict_surfaces_a_warning(): void {
		erankly_clear_schema_merge_warnings();
		erankly_merge_schema_nodes(
			array(
				'@id'  => '#org',
				'name' => array(
					'alternate' => 'Auto',
				),
			),
			array(
				'@id'  => '#org',
				'name' => 'Custom',
			)
		);

		$this->assertNotEmpty( erankly_get_schema_merge_warnings() );
		$this->assertNotEmpty( erankly_schema_merge_warning_messages() );
	}

	public function test_howto_from_html_emits_steps(): void {
		$html   = '<strong class="schema-how-to-step-name">Mix</strong><p class="schema-how-to-step-text">Stir the batter.</p>';
		$schema = erankly_schema_howto_from_html( $html, 0 );

		$this->assertSame( 'HowTo', $schema['@type'] );
		$this->assertSame( 'Mix', $schema['step'][0]['name'] );
		$this->assertSame( 'Stir the batter.', $schema['step'][0]['text'] );
	}

	public function test_faq_keeps_long_visible_text(): void {
		$question = str_repeat( 'Q', 180 );
		$answer   = str_repeat( 'A', 800 );
		$items    = array(
			array(
				'question' => $question,
				'answer'   => $answer,
			),
		);

		add_filter(
			'erankly_faq_items',
			static function () use ( $items ) {
				return $items;
			},
			20
		);

		$schema = erankly_schema_faq( 1 );
		remove_all_filters( 'erankly_faq_items' );
		add_filter( 'erankly_faq_items', 'erankly_faq_items_from_content', 10, 2 );

		$this->assertSame( $question, $schema['mainEntity'][0]['name'] );
		$this->assertSame( $answer, $schema['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_qapage_is_sanitized_to_webpage(): void {
		$this->assertSame( 'WebPage', erankly_sanitize_schema_type_name( 'QAPage' ) );
		$this->assertArrayNotHasKey( 'QAPage', erankly_get_webpage_schema_types() );
	}

	public function test_search_action_is_off_by_default(): void {
		$defaults = erankly_default_settings();

		$this->assertSame( 0, (int) $defaults['enable_website_search_action'] );
		$this->assertSame( 'when_visible', $defaults['breadcrumb_jsonld_mode'] );
	}

	public function test_disabled_types_are_case_insensitive_and_unique(): void {
		$list = erankly_sanitize_schema_type_list( array( 'Article', 'article', 'FAQPage', 'faqpage' ) );

		$this->assertCount( 2, $list );
	}

	public function test_global_block_without_context_is_saved_disabled(): void {
		$blocks = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'            => 'custom',
					'enabled'         => 1,
					'target_contexts' => array(),
					'fields'          => array(
						'custom_json' => '{"@type":"Thing","name":"Global"}',
					),
				),
			),
			true
		);

		$this->assertSame( 0, (int) $blocks[0]['enabled'] );
	}

	public function test_local_business_gaps_include_page_and_address(): void {
		$gaps = erankly_local_business_requirement_gaps(
			array(
				'organization_name'            => '',
				'organization_street_address'  => '',
				'organization_locality'        => '',
				'organization_postal_code'     => '',
				'organization_country'         => '',
				'local_business_pages'         => array(),
				'local_business_page_path'     => '',
			)
		);

		$this->assertNotEmpty( $gaps );
	}

	public function test_variables_survive_placeholder_probe(): void {
		$json = '{"@type":"WebPage","@id":"{{post_url}}#custom","name":"{{post_title}}","publisher":{"@id":"{{schema_identity_id}}"}}';

		$this->assertTrue( erankly_validate_custom_json_ld( $json )['valid'] );
	}

	public function test_video_names_are_unique_for_multiple_videos(): void {
		$this->assertSame( 'Title', erankly_schema_video_name( 'Title', 0, 1 ) );
		$this->assertNotSame(
			erankly_schema_video_name( 'Title', 0, 2 ),
			erankly_schema_video_name( 'Title', 1, 2 )
		);
	}
}
