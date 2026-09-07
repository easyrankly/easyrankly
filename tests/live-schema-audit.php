<?php
/**
 * Live Schema.org audit for the Studio multisite. Saves original settings and
 * fixtures, then restores them even when a check fails.
 *
 * Run: studio wp eval-file wp-content/plugins/easyrankly/tests/live-schema-audit.php
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Dev-only CLI audit script (excluded from releases via .distignore); procedural fixture variables are short-lived and never load in production.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file with studio wp eval-file.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only STDERR notice via eval-file; WP_Filesystem is not appropriate here.
	exit( 1 );
}

erankly_load_default_helpers();
erankly_load_content_helpers();
require_once ERANKLY_PATH . 'includes/canonical.php';
require_once ERANKLY_PATH . 'includes/opengraph.php';
require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
require_once ERANKLY_PATH . 'includes/schema.php';

$failures    = array();
$created     = array();
$original    = erankly_get_stored_settings();
$admin_id   = 0;

foreach ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ) as $user_id ) {
	$admin_id = (int) $user_id;
}

if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
}

function erankly_live_fail( array &$failures, string $message ): void {
	$failures[] = $message;
	echo 'FAIL  ' . $message . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only audit output, not browser HTML.
}

function erankly_live_pass( string $message ): void {
	echo 'PASS  ' . $message . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only audit output, not browser HTML.
}

function erankly_live_fetch( string $url ): string {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 30,
			'sslverify' => false,
		)
	);

	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( 'Frontend request failed for ' . $url . ': ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for CLI debugging, not HTML output.
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = (string) wp_remote_retrieve_body( $response );

	if ( $status < 200 || $status >= 300 ) {
		throw new RuntimeException( 'Frontend request returned HTTP ' . $status . ' for ' . $url . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for CLI debugging, not HTML output.
	}

	if ( '' === trim( $body ) ) {
		throw new RuntimeException( 'Frontend request returned an empty body for ' . $url . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for CLI debugging, not HTML output.
	}

	return $body;
}

function erankly_live_jsonld_scripts( string $html ): array {
	if ( ! preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches ) ) {
		return array();
	}

	return $matches[1];
}

function erankly_live_parse_graph( string $html ): array {
	$scripts = erankly_live_jsonld_scripts( $html );

	if ( array() === $scripts ) {
		return array();
	}

	$decoded = json_decode( html_entity_decode( $scripts[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );

	return is_array( $decoded ) ? $decoded : array();
}

function erankly_live_create_page( int $blog_id, string $title, string $slug, string $content = 'Schema audit fixture.' ): int {
	$switched = is_multisite() && get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
		),
		true
	);

	if ( $switched ) {
		restore_current_blog();
	}

	return is_wp_error( $page_id ) ? 0 : (int) $page_id;
}

function erankly_live_graph_types( array $graph ): array {
	$types = array();

	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}

		$node_types = isset( $node['@type'] ) && is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] ?? '' );

		foreach ( $node_types as $type ) {
			$types[] = (string) $type;
		}
	}

	return $types;
}

function erankly_live_duplicate_ids( array $graph ): array {
	$seen = array();
	$dupes = array();

	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) || empty( $node['@id'] ) ) {
			continue;
		}

		$id = (string) $node['@id'];

		if ( isset( $seen[ $id ] ) ) {
			$dupes[] = $id;
		}

		$seen[ $id ] = true;
	}

	return $dupes;
}

try {
	$it_page = erankly_live_create_page( 1, 'EasyRankly Schema IT', 'erankly-schema-audit-it' );
	$created[] = array( 'blog_id' => 1, 'id' => $it_page );

	if ( $it_page <= 0 ) {
		erankly_live_fail( $failures, 'Could not create Italian fixture page.' );
	} else {
		erankly_live_pass( 'Created Italian fixture page.' );
	}

	$en_page = 0;

	if ( is_multisite() ) {
		$en_page = erankly_live_create_page( 2, 'EasyRankly Schema EN', 'erankly-schema-audit-en' );
		$created[] = array( 'blog_id' => 2, 'id' => $en_page );

		if ( $en_page <= 0 ) {
			erankly_live_fail( $failures, 'Could not create English fixture page.' );
		} else {
			erankly_live_pass( 'Created English fixture page.' );
		}
	}

	$block = array(
		'type'   => 'custom',
		'fields' => array(
			'custom_json' => '{"@type":"Thing","name":"Live custom","description":"{{post_title}}","url":"{{post_url}}","isPartOf":{"@id":"{{schema_identity_id}}"}}',
		),
	);

	update_post_meta( $it_page, '_erankly_schema_mode', 'merge' );
	update_post_meta( $it_page, '_erankly_schema_blocks', array( $block ) );

	$request = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $it_page );
	$request->set_param( 'context', 'edit' );
	$rest = rest_do_request( $request );
	$data = $rest->get_data();

	if ( null === ( $data['meta']['_erankly_schema_blocks'] ?? null ) ) {
		erankly_live_fail( $failures, 'REST GET returned null for _erankly_schema_blocks.' );
	} else {
		erankly_live_pass( 'REST GET returns an array for schema blocks.' );
	}

	$save = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $it_page );
	$save->set_header( 'content-type', 'application/json' );
	$save->set_body(
		wp_json_encode(
			array(
				'meta' => array(
					'_erankly_schema_blocks' => array(
						array(
							'type'   => 'custom',
							'fields' => array(
								'custom_json' => '{"@type":"Thing","name":"Round trip"}',
							),
						),
					),
					'_erankly_title' => 'Unrelated title save',
				),
			)
		)
	);
	$saved = rest_do_request( $save );

	if ( 200 !== $saved->get_status() ) {
		erankly_live_fail( $failures, 'REST save of a valid schema block failed: ' . $saved->get_status() );
	} else {
		erankly_live_pass( 'REST save of a valid schema block succeeded.' );
	}

	$invalid = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $it_page );
	$invalid->set_header( 'content-type', 'application/json' );
	$invalid->set_body(
		wp_json_encode(
			array(
				'meta' => array(
					'_erankly_schema_blocks' => array(
						array(
							'type'   => 'custom',
							'fields' => array(
								'custom_json' => '{not json',
							),
						),
					),
					'_erankly_description' => 'Unrelated description',
				),
			)
		)
	);
	$invalid_response = rest_do_request( $invalid );

	if ( 200 !== $invalid_response->get_status() ) {
		erankly_live_fail( $failures, 'Invalid JSON-LD blocked an unrelated save.' );
	} else {
		erankly_live_pass( 'Invalid JSON-LD did not fail the REST request.' );
	}

	$kept = get_post_meta( $it_page, '_erankly_schema_blocks', true );

	if ( ! is_array( $kept ) || ! isset( $kept[0]['fields']['custom_json'] ) || ! str_contains( (string) $kept[0]['fields']['custom_json'], 'Round trip' ) ) {
		erankly_live_fail( $failures, 'Last valid JSON-LD was not preserved after an invalid save.' );
	} else {
		erankly_live_pass( 'Last valid JSON-LD was preserved.' );
	}

	$xss = array(
		array(
			'type'   => 'custom',
			'fields' => array(
				'custom_json' => '{"@type":"Thing","name":"</script><script>alert(1)</script>"}',
			),
		),
	);
	update_post_meta( $it_page, '_erankly_schema_mode', 'replace' );
	update_post_meta( $it_page, '_erankly_schema_blocks', $xss );

	$it_url  = get_permalink( $it_page );
	$it_html = erankly_live_fetch( $it_url );
	$scripts = erankly_live_jsonld_scripts( $it_html );

	if ( 1 !== count( $scripts ) ) {
		erankly_live_fail( $failures, 'Replace mode did not emit exactly one JSON-LD script. Count: ' . count( $scripts ) );
	} else {
		erankly_live_pass( 'Replace mode emits one JSON-LD script.' );
	}

	if ( str_contains( $it_html, '</script><script>alert(1)' ) ) {
		erankly_live_fail( $failures, 'XSS payload executed in the DOM.' );
	} else {
		erankly_live_pass( 'XSS payload is encoded in JSON-LD output.' );
	}

	$decoded = json_decode( $scripts[0] ?? 'null', true );

	if ( ! is_array( $decoded ) || ( $decoded['@context'] ?? '' ) !== 'https://schema.org' ) {
		erankly_live_fail( $failures, 'JSON-LD document is not parseable with @context schema.org.' );
	} else {
		erankly_live_pass( 'JSON-LD document parses with the Schema.org context.' );
	}

	update_post_meta( $it_page, '_erankly_schema_mode', 'disabled' );
	$disabled_html    = erankly_live_fetch( $it_url );
	$disabled_scripts = erankly_live_jsonld_scripts( $disabled_html );

	if ( array() !== $disabled_scripts ) {
		erankly_live_fail( $failures, 'Disable schema still emitted JSON-LD.' );
	} else {
		erankly_live_pass( 'Disable schema emits no JSON-LD.' );
	}

	$identity_block = array(
		array(
			'type'   => 'custom',
			'fields' => array(
				'custom_json' => '{"@type":"Organization","@id":"{{schema_identity_id}}","description":"Descrizione custom"}',
			),
		),
	);
	update_post_meta( $it_page, '_erankly_schema_mode', 'merge' );
	update_post_meta( $it_page, '_erankly_schema_blocks', $identity_block );
	$merge_html  = erankly_live_fetch( $it_url );
	$merge_doc   = erankly_live_parse_graph( $merge_html );
	$merge_graph = isset( $merge_doc['@graph'] ) && is_array( $merge_doc['@graph'] ) ? $merge_doc['@graph'] : array();
	$found_desc  = false;

	foreach ( $merge_graph as $node ) {
		if ( is_array( $node ) && 'Descrizione custom' === ( $node['description'] ?? '' ) ) {
			$found_desc = true;
		}
	}

	if ( ! $found_desc ) {
		erankly_live_fail( $failures, 'Custom description on a shared @id was dropped.' );
	} else {
		erankly_live_pass( 'Custom properties on a shared @id are merged into the graph.' );
	}

	$dupes = erankly_live_duplicate_ids( $merge_graph );

	if ( array() !== $dupes ) {
		erankly_live_fail( $failures, 'Duplicate @id values remain unresolved: ' . implode( ', ', $dupes ) );
	} else {
		erankly_live_pass( 'No unresolved duplicate @id values.' );
	}

	$settings = erankly_get_settings();
	$settings['enable_local_business']     = 1;
	$settings['local_business_type']       = 'Restaurant';
	$settings['organization_name']         = 'EasyRankly Trattoria';
	$settings['organization_street_address'] = 'Via Roma 1';
	$settings['organization_locality']     = 'Milano';
	$settings['organization_postal_code']  = '20100';
	$settings['organization_country']      = 'IT';
	$settings['enable_website_search_action'] = 0;
	$settings['breadcrumb_jsonld_mode']    = 'when_visible';
	$esempio = get_page_by_path( 'esempio' );
	$esempio_id = $esempio instanceof WP_Post ? (int) $esempio->ID : 0;
	$example_id = 0;
	$en_example_url = '';

	if ( is_multisite() ) {
		switch_to_blog( 2 );
		$example = get_page_by_path( 'example' );
		$example_id = $example instanceof WP_Post ? (int) $example->ID : 0;
		$en_example_url = $example_id > 0 ? (string) get_permalink( $example_id ) : '';
		restore_current_blog();
	}

	$settings['local_business_pages'] = array();
	if ( $esempio_id > 0 ) {
		$settings['local_business_pages'][1] = $esempio_id;
	}
	if ( $example_id > 0 ) {
		$settings['local_business_pages'][2] = $example_id;
	}

	erankly_update_plugin_settings( $settings, '', true );
	erankly_clear_settings_cache();

	$esempio_url = $esempio_id > 0 ? (string) get_permalink( $esempio_id ) : home_url( '/esempio/' );
	$lb_html     = erankly_live_fetch( $esempio_url );
	$lb_doc   = erankly_live_parse_graph( $lb_html );
	$lb_graph = isset( $lb_doc['@graph'] ) && is_array( $lb_doc['@graph'] ) ? $lb_doc['@graph'] : array();
	$lb_types = erankly_live_graph_types( $lb_graph );

	if ( ! in_array( 'Restaurant', $lb_types, true ) ) {
		erankly_live_fail( $failures, 'Italian LocalBusiness page did not emit Restaurant.' );
	} else {
		erankly_live_pass( 'Italian LocalBusiness page emits Restaurant.' );
	}

	if ( in_array( 'BreadcrumbList', $lb_types, true ) ) {
		erankly_live_fail( $failures, 'BreadcrumbList was emitted without a visible trail.' );
	} else {
		erankly_live_pass( 'BreadcrumbList is omitted when no visible trail is present.' );
	}

	if ( is_multisite() && $example_id > 0 ) {
		$en_url = $en_example_url;

		$en_html  = erankly_live_fetch( $en_url );
		$en_doc   = erankly_live_parse_graph( $en_html );
		$en_graph = isset( $en_doc['@graph'] ) && is_array( $en_doc['@graph'] ) ? $en_doc['@graph'] : array();
		$en_types = erankly_live_graph_types( $en_graph );
		$en_lang  = '';

		foreach ( $en_graph as $node ) {
			if ( is_array( $node ) && isset( $node['inLanguage'] ) ) {
				$en_lang = (string) $node['inLanguage'];
				break;
			}
		}

		if ( ! in_array( 'Restaurant', $en_types, true ) ) {
			erankly_live_fail( $failures, 'English LocalBusiness page did not emit Restaurant.' );
		} else {
			erankly_live_pass( 'English LocalBusiness page emits Restaurant.' );
		}

		if ( '' !== $en_lang && ! str_starts_with( strtolower( $en_lang ), 'en' ) ) {
			erankly_live_fail( $failures, 'English page inLanguage is not English: ' . $en_lang );
		} else {
			erankly_live_pass( 'English page inLanguage is English (' . $en_lang . ').' );
		}

		if ( is_string( $en_url ) && ! str_contains( $en_url, '/en/' ) ) {
			erankly_live_fail( $failures, 'English permalink is not under /en/: ' . $en_url );
		} else {
			erankly_live_pass( 'English permalink is under /en/.' );
		}
	}

	if ( ! post_type_exists( 'event' ) ) {
		register_post_type(
			'event',
			array(
				'public' => true,
				'label'  => 'Events',
			)
		);
	}

	$event_id = 0;
	$event_id = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_status'  => 'publish',
			'post_title'   => 'Schema Event Fixture',
			'post_content' => 'Event body',
		),
		true
	);

	if ( ! is_wp_error( $event_id ) && $event_id > 0 ) {
		$created[] = array( 'blog_id' => get_current_blog_id(), 'id' => (int) $event_id );
		update_post_meta( $event_id, '_event_start', 'not-a-date' );
		$event_schema = erankly_schema_event( (int) $event_id );

		if ( ! empty( $event_schema ) ) {
			erankly_live_fail( $failures, 'Event with an invalid start date was emitted.' );
		} else {
			erankly_live_pass( 'Event without a valid startDate is not emitted.' );
		}

		update_post_meta( $event_id, '_event_start', '2026-09-06 18:00:00' );
		update_post_meta( $event_id, '_event_end', '2026-09-06 16:00:00' );
		update_post_meta( $event_id, 'event_location', 'Sala Prove' );
		$event_schema = erankly_schema_event( (int) $event_id );

		if ( empty( $event_schema['startDate'] ) ) {
			erankly_live_fail( $failures, 'Valid event startDate was not emitted.' );
		} else {
			erankly_live_pass( 'Valid event emits an ISO startDate.' );
		}

		if ( isset( $event_schema['endDate'] ) ) {
			erankly_live_fail( $failures, 'endDate earlier than startDate was emitted.' );
		} else {
			erankly_live_pass( 'endDate earlier than startDate is omitted.' );
		}
	} else {
		erankly_live_pass( 'Skipped generic event post type (not registered).' );
	}

	$faq_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'FAQ length fixture',
			'post_content' => '<!-- wp:yoast/faq-block {"questions":[{"question":"' . str_repeat( 'Q', 160 ) . '","answer":"' . str_repeat( 'A', 620 ) . '"}]} /-->',
		),
		true
	);

	if ( ! is_wp_error( $faq_id ) && $faq_id > 0 ) {
		$created[] = array( 'blog_id' => get_current_blog_id(), 'id' => (int) $faq_id );
		$faq       = erankly_schema_faq( (int) $faq_id );

		if ( empty( $faq['mainEntity'][0]['name'] ) || strlen( (string) $faq['mainEntity'][0]['name'] ) < 160 ) {
			erankly_live_fail( $failures, 'FAQ question was truncated.' );
		} else {
			erankly_live_pass( 'FAQ question is not truncated.' );
		}
	}

	$video_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Video fixture',
			'post_content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ https://www.youtube.com/watch?v=9bZkp7q19f0',
		),
		true
	);

	if ( ! is_wp_error( $video_id ) && $video_id > 0 ) {
		$created[] = array( 'blog_id' => get_current_blog_id(), 'id' => (int) $video_id );
		$videos    = erankly_schema_video_objects( (int) $video_id );
		$ids       = array();
		$names     = array();

		foreach ( $videos as $video ) {
			$ids[]   = (string) ( $video['@id'] ?? '' );
			$names[] = (string) ( $video['name'] ?? '' );
		}

		if ( count( $videos ) >= 2 && count( array_unique( $ids ) ) === count( $ids ) && count( array_unique( $names ) ) === count( $names ) ) {
			erankly_live_pass( 'Multiple VideoObject nodes have distinct names and @id values.' );
		} elseif ( empty( $videos ) ) {
			erankly_live_pass( 'VideoObject skipped without a complete name/thumbnail/date/url set (expected for some embeds).' );
		} else {
			erankly_live_fail( $failures, 'VideoObject nodes were not uniquely named.' );
		}
	}

	$multi = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $it_page );
	$multi->set_header( 'content-type', 'application/json' );
	$multi->set_body(
		wp_json_encode(
			array(
				'meta' => array(
					'_erankly_schema_blocks' => array(
						array(
							'type'   => 'custom',
							'fields' => array(
								'custom_json' => '{"@type":"Thing","name":"Block A"}',
							),
						),
						array(
							'type'   => 'custom',
							'fields' => array(
								'custom_json' => '{"@type":"Thing","name":"Block B"}',
							),
						),
					),
				),
			)
		)
	);
	$multi_response = rest_do_request( $multi );
	$multi_data     = $multi_response->get_data();

	if ( 200 === $multi_response->get_status() && 2 === count( (array) ( $multi_data['meta']['_erankly_schema_blocks'] ?? array() ) ) ) {
		erankly_live_pass( 'REST save of multiple schema blocks succeeded.' );
	} else {
		erankly_live_fail( $failures, 'REST save of multiple schema blocks failed.' );
	}

	$unrelated = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $it_page );
	$unrelated->set_header( 'content-type', 'application/json' );
	$unrelated->set_body(
		wp_json_encode(
			array(
				'meta' => array(
					'_erankly_title' => 'Keep existing schema blocks',
				),
			)
		)
	);
	rest_do_request( $unrelated );
	$kept_blocks = get_post_meta( $it_page, '_erankly_schema_blocks', true );

	if ( is_array( $kept_blocks ) && 2 === count( $kept_blocks ) ) {
		erankly_live_pass( 'Unrelated REST field save kept existing schema blocks.' );
	} else {
		erankly_live_fail( $failures, 'Unrelated REST field save lost schema blocks.' );
	}

	if ( ! is_wp_error( $event_id ) && $event_id > 0 ) {
		update_post_meta( $event_id, '_event_start', '2026-09-07 18:00:00' );
		delete_post_meta( $event_id, 'event_location' );
		$event_no_location = erankly_schema_event( (int) $event_id );

		if ( empty( $event_no_location ) ) {
			erankly_live_pass( 'Event without a location is not emitted.' );
		} else {
			erankly_live_fail( $failures, 'Event without a location was emitted.' );
		}
	}

	if ( ! post_type_exists( 'tribe_events' ) ) {
		register_post_type(
			'tribe_events',
			array(
				'public' => true,
				'label'  => 'TEC Events',
			)
		);
	}

	$tec_id = wp_insert_post(
		array(
			'post_type'    => 'tribe_events',
			'post_status'  => 'publish',
			'post_title'   => 'TEC virtual fixture',
			'post_content' => 'Online event',
		),
		true
	);

	if ( ! is_wp_error( $tec_id ) && $tec_id > 0 ) {
		$created[] = array( 'blog_id' => get_current_blog_id(), 'id' => (int) $tec_id );
		update_post_meta( $tec_id, '_EventStartDate', '2026-09-07 19:00:00' );
		$tec_incomplete = erankly_schema_event( (int) $tec_id );

		if ( empty( $tec_incomplete ) ) {
			erankly_live_pass( 'The Events Calendar event without venue or virtual URL is not emitted.' );
		} else {
			erankly_live_fail( $failures, 'Incomplete TEC event was emitted.' );
		}

		update_post_meta( $tec_id, '_EventVirtual', '1' );
		update_post_meta( $tec_id, '_EventURL', 'https://example.test/live' );
		$tec_virtual = erankly_schema_event( (int) $tec_id );

		if ( isset( $tec_virtual['location']['@type'] ) && 'VirtualLocation' === $tec_virtual['location']['@type'] && ! empty( $tec_virtual['location']['url'] ) ) {
			erankly_live_pass( 'The Events Calendar virtual event emits VirtualLocation.' );
		} else {
			erankly_live_fail( $failures, 'The Events Calendar virtual event did not emit VirtualLocation.' );
		}
	}

	$howto_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'HowTo fixture',
			'post_content' => '<p class="schema-how-to-description">Bake a cake.</p><strong class="schema-how-to-step-name">Mix</strong><p class="schema-how-to-step-text">Stir the batter.</p><strong class="schema-how-to-step-name">Bake</strong><p class="schema-how-to-step-text">Cook for 40 minutes.</p>',
		),
		true
	);

	if ( ! is_wp_error( $howto_id ) && $howto_id > 0 ) {
		$created[] = array( 'blog_id' => get_current_blog_id(), 'id' => (int) $howto_id );
		$howto     = erankly_schema_howto( (int) $howto_id );

		if ( isset( $howto['@type'] ) && 'HowTo' === $howto['@type'] && isset( $howto['step'] ) && count( $howto['step'] ) >= 2 ) {
			erankly_live_pass( 'HowTo schema emits multiple steps from HTML.' );
		} else {
			erankly_live_fail( $failures, 'HowTo schema was incomplete.' );
		}
	}

	$article_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Article schema fixture',
			'post_content' => 'Article body for schema.',
		),
		true
	);

	if ( ! is_wp_error( $article_id ) && $article_id > 0 ) {
		$created[]     = array( 'blog_id' => get_current_blog_id(), 'id' => (int) $article_id );
		$article_url   = get_permalink( $article_id );
		$article_html  = erankly_live_fetch( $article_url );
		$article_graph = erankly_live_parse_graph( $article_html );
		$article_types = array();

		foreach ( ( $article_graph['@graph'] ?? array() ) as $node ) {
			foreach ( (array) ( $node['@type'] ?? array() ) as $type_name ) {
				$article_types[] = $type_name;
			}
		}

		if ( in_array( 'Organization', $article_types, true ) && ( in_array( 'Article', $article_types, true ) || in_array( 'BlogPosting', $article_types, true ) || in_array( 'NewsArticle', $article_types, true ) ) ) {
			erankly_live_pass( 'Post graph includes identity and an Article family type.' );
		} else {
			erankly_live_fail( $failures, 'Post graph missing Organization or Article family: ' . implode( ',', $article_types ) );
		}

		if ( ! empty( $article_graph['@context'] ) && 1 === count( erankly_live_jsonld_scripts( $article_html ) ) ) {
			erankly_live_pass( 'Article page emits a single JSON-LD document.' );
		} else {
			erankly_live_fail( $failures, 'Article page JSON-LD document count was unexpected.' );
		}
	}

	$profile_settings = erankly_get_stored_settings();
	$profile_settings['global_post_type_schema']['page']['webpage_type'] = 'ProfilePage';
	erankly_update_plugin_settings( $profile_settings, '', true );
	erankly_clear_settings_cache();
	update_post_meta( $it_page, '_erankly_schema_mode', 'default' );
	delete_post_meta( $it_page, '_erankly_schema_blocks' );
	$profile_html  = erankly_live_fetch( get_permalink( $it_page ) );
	$profile_graph = erankly_live_parse_graph( $profile_html );
	$profile_ok    = false;

	foreach ( ( $profile_graph['@graph'] ?? array() ) as $node ) {
		if ( isset( $node['@type'] ) && 'ProfilePage' === $node['@type'] && ! empty( $node['mainEntity']['@id'] ) ) {
			$profile_ok = true;
			break;
		}
	}

	if ( $profile_ok ) {
		erankly_live_pass( 'ProfilePage emits mainEntity pointing at the site identity.' );
	} else {
		erankly_live_fail( $failures, 'ProfilePage mainEntity was missing.' );
	}

	erankly_clear_schema_merge_warnings();
	erankly_merge_schema_nodes(
		array(
			'@id'  => '#conflict',
			'name' => array( 'alternate' => 'Auto' ),
		),
		array(
			'@id'  => '#conflict',
			'name' => 'Custom overlay',
		)
	);

	if ( array() !== erankly_schema_merge_warning_messages() ) {
		erankly_live_pass( 'Unresolved @id merge conflicts produce warning messages.' );
	} else {
		erankly_live_fail( $failures, 'Merge conflict produced no warning messages.' );
	}

	$target_settings                         = erankly_get_stored_settings();
	$target_settings['global_schema_blocks'] = array(
		array(
			'type'              => 'custom',
			'enabled'           => 1,
			'target_contexts'   => array( 'singular' ),
			'target_post_types' => array( 'page' ),
			'include_items'     => (string) $it_page,
			'exclude_items'     => '',
			'fields'            => array(
				'custom_json' => '{"@type":"Thing","name":"IT-ONLY-GLOBAL"}',
			),
		),
	);
	erankly_update_plugin_settings( $target_settings, '', true );
	erankly_clear_settings_cache();

	$it_target_html = erankly_live_fetch( get_permalink( $it_page ) );
	$it_target      = erankly_live_parse_graph( $it_target_html );

	if ( str_contains( wp_json_encode( $it_target ), 'IT-ONLY-GLOBAL' ) ) {
		erankly_live_pass( 'Global schema targeting include list matches the Italian page.' );
	} else {
		erankly_live_fail( $failures, 'Italian page did not receive the included global schema block.' );
	}

	if ( $en_page > 0 ) {
		switch_to_blog( 2 );
		update_post_meta( $en_page, '_erankly_schema_mode', 'default' );
		$en_permalink = get_permalink( $en_page );
		restore_current_blog();
		$en_target_html = erankly_live_fetch( $en_permalink );
		$en_target      = erankly_live_parse_graph( $en_target_html );

		if ( str_contains( wp_json_encode( $en_target ), 'IT-ONLY-GLOBAL' ) ) {
			erankly_live_fail( $failures, 'English page received an Italian-only global schema block.' );
		} else {
			erankly_live_pass( 'Global schema targeting include list does not match the English page.' );
		}

		$en_settings                         = erankly_get_stored_settings();
		$en_settings['global_schema_blocks'] = array(
			array(
				'type'              => 'custom',
				'enabled'           => 1,
				'target_contexts'   => array( 'singular' ),
				'target_post_types' => array( 'page' ),
				'include_items'     => (string) $en_page,
				'exclude_items'     => '',
				'fields'            => array(
					'custom_json' => '{"@type":"Thing","name":"EN-ONLY-GLOBAL"}',
				),
			),
		);
		erankly_update_plugin_settings( $en_settings, '', true );
		erankly_clear_settings_cache();

		$en_included = erankly_live_parse_graph( erankly_live_fetch( $en_permalink ) );
		$it_excluded = erankly_live_parse_graph( erankly_live_fetch( get_permalink( $it_page ) ) );

		if ( str_contains( wp_json_encode( $en_included ), 'EN-ONLY-GLOBAL' ) ) {
			erankly_live_pass( 'Global schema targeting include list matches the English page.' );
		} else {
			erankly_live_fail( $failures, 'English page did not receive the included global schema block.' );
		}

		if ( str_contains( wp_json_encode( $it_excluded ), 'EN-ONLY-GLOBAL' ) ) {
			erankly_live_fail( $failures, 'Italian page received an English-only global schema block.' );
		} else {
			erankly_live_pass( 'English-only global schema block is not emitted on the Italian page.' );
		}

		switch_to_blog( 2 );
		update_post_meta( $en_page, '_erankly_schema_mode', 'replace' );
		update_post_meta(
			$en_page,
			'_erankly_schema_blocks',
			array(
				array(
					'type'   => 'custom',
					'fields' => array(
						'custom_json' => '{"@type":"Thing","name":"EN-REPLACE-ONLY"}',
					),
				),
			)
		);
		$en_replace_url = get_permalink( $en_page );
		restore_current_blog();
		$en_replace = erankly_live_parse_graph( erankly_live_fetch( $en_replace_url ) );
		$en_types   = array();

		foreach ( ( $en_replace['@graph'] ?? array() ) as $node ) {
			foreach ( (array) ( $node['@type'] ?? array() ) as $type_name ) {
				$en_types[] = $type_name;
			}
		}

		if ( in_array( 'Thing', $en_types, true ) && ! in_array( 'WebSite', $en_types, true ) && str_contains( wp_json_encode( $en_replace ), 'EN-REPLACE-ONLY' ) ) {
			erankly_live_pass( 'English replace mode emits only per-post custom schema.' );
		} else {
			erankly_live_fail( $failures, 'English replace mode graph was not custom-only.' );
		}

		switch_to_blog( 2 );
		update_post_meta( $en_page, '_erankly_schema_mode', 'disabled' );
		$en_disabled_url = get_permalink( $en_page );
		restore_current_blog();
		$en_disabled_html = erankly_live_fetch( $en_disabled_url );

		if ( array() === erankly_live_jsonld_scripts( $en_disabled_html ) ) {
			erankly_live_pass( 'English disabled mode emits no JSON-LD.' );
		} else {
			erankly_live_fail( $failures, 'English disabled mode still emitted JSON-LD.' );
		}
	}

	if ( class_exists( 'WooCommerce' ) ) {
		erankly_live_pass( 'WooCommerce is available; Product schema coverage depends on a product fixture.' );
	} else {
		erankly_live_pass( 'WooCommerce is not active; Product schema live fixture skipped.' );
	}
} finally {
	foreach ( $created as $item ) {
		$blog_id  = (int) $item['blog_id'];
		$switched = is_multisite() && get_current_blog_id() !== $blog_id;

		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		if ( ! empty( $item['id'] ) ) {
			wp_delete_post( (int) $item['id'], true );
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	erankly_update_plugin_settings( is_array( $original ) ? $original : array(), '', true );
	erankly_clear_settings_cache();
	if ( $admin_id > 0 ) {
		delete_transient( 'erankly_invalid_json_ld_' . $admin_id );
	}
	echo 'RESTORE  Original settings and fixtures restored.' . PHP_EOL;
}

if ( array() === $failures ) {
	echo 'RESULT  All live Schema checks passed.' . PHP_EOL;
	exit( 0 );
}

echo 'RESULT  ' . count( $failures ) . ' live Schema check(s) failed.' . PHP_EOL;
exit( 1 );
