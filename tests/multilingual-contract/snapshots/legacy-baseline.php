<?php
// phpcs:ignoreFile -- Immutable normalized snapshot data for the M1 test harness.
/**
 * Provider-neutral behavior snapshot for the legacy Multisite contract.
 *
 * @package EasyRankly
 */

return array(
	'provider_contract' => array(
		'frontend_keys' => array( 'asset_handle' ),
		'rest_keys'     => array( 'editor_field', 'search_route', 'settings_route' ),
	),
	'locale_fallback' => 'en',
	'manual_post' => array(
		'alternates' => array(
			'de'        => '{site:3}/m1-manual-3/',
			'en'        => '{site:2}/m1-manual-2/',
			'it'        => '{site:1}/m1-manual-1/',
			'x-default' => '{site:1}/m1-manual-1/',
		),
		'head' => array(
			'canonical'  => 'https://canonical.example.test/manual/',
			'alternates' => array(
				'de'        => '{site:3}/m1-manual-3/',
				'en'        => '{site:2}/m1-manual-2/',
				'it'        => '{site:1}/m1-manual-1/',
				'x-default' => '{site:1}/m1-manual-1/',
			),
			'credit' => false,
		),
		'manual_canonical'                => 'https://canonical.example.test/manual/',
		'manual_canonical_kept_alternate' => true,
	),
	'robots_matrix' => array(
		array( 'directive' => 'inherit', 'legacy_noindex' => false, 'robots' => 'index', 'seo_has_translation' => true ),
		array( 'directive' => 'inherit', 'legacy_noindex' => true, 'robots' => 'noindex', 'seo_has_translation' => false ),
		array( 'directive' => 'index', 'legacy_noindex' => false, 'robots' => 'index', 'seo_has_translation' => true ),
		array( 'directive' => 'index', 'legacy_noindex' => true, 'robots' => 'index', 'seo_has_translation' => false ),
		array( 'directive' => 'noindex', 'legacy_noindex' => false, 'robots' => 'noindex', 'seo_has_translation' => true ),
		array( 'directive' => 'noindex', 'legacy_noindex' => true, 'robots' => 'noindex', 'seo_has_translation' => false ),
	),
	'legacy_noindex' => array(
		'seo_keys'       => array( 'de', 'it', 'x-default' ),
		'navigable_keys' => array( 'de', 'en', 'it', 'x-default' ),
	),
	'unpublished' => array(
		'seo_keys'       => array(),
		'navigable_keys' => array(),
	),
	'inferred' => array(
		'seo_keys'          => array( 'de', 'en', 'it', 'x-default' ),
		'switcher_rendered' => false,
	),
	'inferred_term' => array(
		'seo_keys' => array( 'de', 'en', 'it', 'x-default' ),
	),
	'home' => array(
		'seo_keys' => array( 'de', 'en', 'it', 'x-default' ),
	),
	'term' => array(
		'seo_keys' => array( 'de', 'en', 'it', 'x-default' ),
	),
	'shortcodes' => array(
		'switcher' => array(
			'marker'    => true,
			'select_id' => '{switcher-id}',
			'label_for' => '{switcher-id}',
			'options'   => array(
				array( 'hreflang' => 'de', 'url' => '{site:3}/m1-manual-3/', 'current' => false, 'label' => 'Deutsch' ),
				array( 'hreflang' => 'en', 'url' => '{site:2}/m1-manual-2/', 'current' => false, 'label' => 'English' ),
				array( 'hreflang' => 'it', 'url' => '{site:1}/m1-manual-1/', 'current' => true, 'label' => 'Italiano' ),
			),
		),
		'notice' => array(
			'marker'       => true,
			'post_id'      => '{object-id}',
			'current_lang' => 'it',
			'translations' => array(
				array(
					'hreflang' => 'de',
					'url'      => '{site:3}/m1-manual-3/',
					'native'   => 'Deutsch',
					'title'    => 'Available in {language}',
					'text'     => 'Read this content in {language}.',
					'link'     => 'Open {language}',
				),
				array(
					'hreflang' => 'en',
					'url'      => '{site:2}/m1-manual-2/',
					'native'   => 'English',
					'title'    => 'Available in {language}',
					'text'     => 'Read this content in {language}.',
					'link'     => 'Open {language}',
				),
			),
		),
		'asset_states' => array(
			'before' => array( 'style' => 'registered', 'script' => 'registered' ),
			'after'  => array( 'style' => 'enqueued', 'script' => 'enqueued' ),
		),
	),
	'rest' => array(
		'blocked_search' => array(),
		'allowed_search' => array(
			array(
				'id'    => '{object-id}',
				'title' => 'M1 Manual 2',
				'url'   => '{site:2}/m1-manual-2/',
			),
		),
	),
	'robots_txt' => array(
		'rules'            => array( 'Allow: /wp-admin/admin-ajax.php', 'Disallow: /wp-admin/', 'User-agent: *' ),
		'sitemap_template' => '{site:%d}/wp-sitemap.xml',
	),
	'storage' => array(
		'relation_table' => '{base-prefix}erankly_ml_relations',
		'relation_scope' => 'network-shared',
		'registry_scope' => 'network-option',
	),
	'ownership' => array(
		'resolver_count'              => 1,
		'resolver_duplicate_detected' => true,
		'emitter_count'               => 1,
		'emitter_duplicate_detected'  => true,
	),
);
