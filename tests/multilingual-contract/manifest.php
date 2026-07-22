<?php
// phpcs:ignoreFile -- Declarative test contract; it is never loaded by the plugin runtime.
/**
 * EasyRankly Multilingual M1 characterization manifest.
 *
 * @package EasyRankly
 */

return array(
	'schema_version'  => 1,
	'baseline'        => array(
		'ref'     => 'origin/beta',
		'commit'  => 'eccebfb5fed965b92e383eea8d7b67916c2a2267',
		'version' => '2.0.0',
	),
	'fixture_sizes'   => array( 3, 250, 501 ),
	'provider_ids'    => array(
		'bundled' => 'easyrankly-bundled-multilingual',
		'addon'   => 'easyrankly-multilingual',
	),
	'suites'          => array(
		'legacy-baseline'      => array(
			'file'          => 'tests/multilingual-contract/legacy-baseline.php',
			'expected'      => 'pass',
			'providers'     => array( 'bundled', 'addon' ),
			'clean_runtime' => true,
		),
		'multisite-conformance' => array(
			'file'      => 'tests/multilingual-contract/multisite-conformance.php',
			'expected'  => 'fail-on-2.0-baseline',
			'providers' => array( 'bundled', 'addon' ),
		),
	),
	'parity_behaviors' => array(
		'ML-BASE-001' => 'site-language registry, locale fallback and default selection',
		'ML-BASE-002' => 'manual post, term and home translation groups',
		'ML-BASE-003' => 'slug inference for posts and terms',
		'ML-BASE-004' => 'SEO and navigable alternate separation',
		'ML-BASE-005' => 'head, canonical, robots.txt and x-default output',
		'ML-BASE-006' => 'shortcode HTML and contextual frontend assets',
		'ML-BASE-007' => 'REST routes, editor field and cross-site capability gates',
		'ML-BASE-008' => 'repository cache invalidation and one-slot replacement',
		'ML-BASE-009' => 'post, term and site deletion cleanup',
		'ML-BASE-010' => 'atomic concurrent allocation of new group IDs',
		'ML-BASE-011' => '3, 250 and 501 site inventories plus multi-network scoping',
		'ML-BASE-012' => 'single resolver and single hreflang emitter guards',
	),
	'conformance_defects' => array(
		'ML-CONF-001' => array(
			'milestone' => 'M2',
			'requirement' => 'GEN-001',
			'description' => 'Navigable alternates use a neutral selected-provider contract instead of a concrete global resolver.',
		),
		'ML-CONF-002' => array(
			'milestone' => 'M4',
			'requirement' => 'SEO-001/SEO-004',
			'description' => 'Multisite eligibility uses effective robots and canonical state, not only the legacy noindex boolean.',
		),
		'ML-CONF-003' => array(
			'milestone' => 'M4',
			'requirement' => 'REL-006',
			'description' => 'SEO, navigable and switcher consumers share the same explicit-or-inferred resolver.',
		),
		'ML-CONF-004' => array(
			'milestone' => 'M4',
			'requirement' => 'PERF-002',
			'description' => 'Network inventories are paginated and never truncated at 200 sites.',
		),
		'ML-CONF-005' => array(
			'milestone' => 'M4',
			'requirement' => 'LANG-006',
			'description' => 'Ambiguous defaults and duplicate hreflang codes are rejected.',
		),
		'ML-CONF-006' => array(
			'milestone' => 'M4',
			'requirement' => 'DATA-001',
			'description' => 'The multilingual site map and relations are present in the add-on export contract.',
		),
		'ML-CONF-007' => array(
			'milestone' => 'M2',
			'requirement' => 'LIFE-003/LIFE-004',
			'description' => 'Core reset and uninstall preserve storage when ownership or an adoption journal forbids cleanup.',
		),
		'ML-CONF-008' => array(
			'milestone' => 'M2',
			'requirement' => 'SEO-007',
			'description' => 'Core documentation does not claim XML sitemap alternates that are not rendered.',
		),
		'ML-CONF-009' => array(
			'milestone' => 'M2',
			'requirement' => 'SEO-010',
			'description' => 'Hreflang emission is independently owned and survives suppression of the core SEO head.',
		),
	),
);
