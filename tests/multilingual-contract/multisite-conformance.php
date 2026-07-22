<?php
// phpcs:ignoreFile -- Expected-red M1 conformance suite for known EasyRankly 2.0 defects.
/**
 * Multisite conformance suite.
 *
 * All nine ML-CONF assertions intentionally fail on baseline eccebfb. They are
 * kept separate from legacy-baseline so the fallback can retain a green parity
 * gate while M2 and M4 close the documented defects.
 *
 * @package EasyRankly
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

$result   = new ERankly_ML_Contract_Result( 'multisite-conformance' );
$driver   = erankly_ml_contract_driver();
$manifest = erankly_ml_contract_manifest();
$defects  = $manifest['conformance_defects'];

$result->same( 9, count( $defects ), 'ML-CONF-MANIFEST', 'The conformance suite must declare exactly the nine baseline defects.' );
$result->check(
	array() === array_diff( array_column( $defects, 'milestone' ), array( 'M2', 'M4' ) ),
	'ML-CONF-MANIFEST',
	'Every expected-red defect must be assigned explicitly to M2 or M4.'
);

$hreflang_source = (string) file_get_contents( ERANKLY_PATH . 'includes/hreflang.php' );
$resolver_source = (string) file_get_contents( ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-resolver.php' );
$admin_source     = (string) file_get_contents( ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-admin.php' );
$module_source    = (string) file_get_contents( ERANKLY_PATH . 'includes/multilingual.php' );
$meta_head_source = (string) file_get_contents( ERANKLY_PATH . 'includes/meta-render.php' );
$reset_source     = (string) file_get_contents( ERANKLY_PATH . 'includes/reset.php' );
$uninstall_source = (string) file_get_contents( ERANKLY_PATH . 'uninstall.php' );
$readme_source    = (string) file_get_contents( ERANKLY_PATH . 'readme.txt' );

$result->check(
	! str_contains( $hreflang_source, "\$GLOBALS['erankly_ml_resolver']" )
		&& ! str_contains( $hreflang_source, 'instanceof ERankly_ML_Resolver' )
		&& str_contains( $hreflang_source, 'erankly_get_multilingual_provider' ),
	'ML-CONF-001',
	'Navigable alternates must use the selected provider API rather than a concrete global resolver.',
	array( 'milestone' => $defects['ML-CONF-001']['milestone'] )
);

$result->check(
	str_contains( $resolver_source, 'erankly_get_object_seo_state' )
		&& ! str_contains( $resolver_source, "'_erankly_noindex'" )
		&& str_contains( $resolver_source, 'canonical_is_self' ),
	'ML-CONF-002',
	'The Network resolver must consume effective indexability and canonical state.',
	array( 'milestone' => $defects['ML-CONF-002']['milestone'] )
);

$sites    = erankly_ml_contract_sites( 3 );
$site_ids = array_map( static fn( WP_Site $site ): int => (int) $site->blog_id, $sites );
$driver->save_site_map( erankly_ml_contract_site_map( $sites ) );
$inferred = array();
foreach ( $site_ids as $blog_id ) {
$inferred[ $blog_id ] = erankly_ml_contract_create_post( $blog_id, 'm1-conformance-inferred' );
}
switch_to_blog( $site_ids[0] );
erankly_ml_contract_set_post_query( $inferred[ $site_ids[0] ] );
$inferred_seo      = $driver->seo_alternates();
$inferred_switcher = $driver->render_shortcode( 'switcher' );
$result->check(
	count( $inferred_seo ) >= 2 && '' !== $inferred_switcher,
	'ML-CONF-003',
	'An inferred translation set must be shared by SEO and the switcher.',
	array( 'milestone' => $defects['ML-CONF-003']['milestone'] )
);

$result->check(
	! str_contains( $admin_source, "get_sites( array( 'number' => 200 ) )" )
		&& ( str_contains( $admin_source, 'paged' ) || str_contains( $admin_source, 'offset' ) ),
	'ML-CONF-004',
	'Network admin inventories must be paginated instead of capped at 200.',
	array( 'milestone' => $defects['ML-CONF-004']['milestone'] )
);

$valid_map = $driver->site_map();
$invalid_map = $valid_map;
$invalid_map[ $site_ids[0] ]['hreflang']   = 'en';
$invalid_map[ $site_ids[0] ]['is_default'] = true;
$invalid_map[ $site_ids[1] ]['hreflang']   = 'en';
$invalid_map[ $site_ids[1] ]['is_default'] = true;
$driver->save_site_map( $invalid_map );
$result->check(
	$valid_map === $driver->site_map(),
	'ML-CONF-005',
	'Ambiguous default and duplicate hreflang input must be rejected without replacing the saved map.',
	array( 'milestone' => $defects['ML-CONF-005']['milestone'] )
);
$driver->save_site_map( $valid_map );

require_once ERANKLY_PATH . 'includes/import-export.php';
$export = erankly_export_build_data( array(), 10 );
$result->check(
	isset( $export['multilingual'] )
		&& isset( $export['multilingual']['sites'], $export['multilingual']['relations'] ),
	'ML-CONF-006',
	'The export contract must contain the multilingual site map and relations.',
	array( 'milestone' => $defects['ML-CONF-006']['milestone'] )
);

$result->check(
	str_contains( $reset_source, 'erankly_ml_storage_owner' )
		&& str_contains( $uninstall_source, 'erankly_ml_storage_owner' ),
	'ML-CONF-007',
	'Core cleanup must inspect the ownership marker before touching multilingual storage.',
	array( 'milestone' => $defects['ML-CONF-007']['milestone'] )
);

$result->check(
	! str_contains( $readme_source, 'outputs hreflang alternates in the head and XML sitemaps' ),
	'ML-CONF-008',
	'User-facing documentation must not claim XML sitemap alternates before they exist.',
	array( 'milestone' => $defects['ML-CONF-008']['milestone'] )
);

$result->check(
	str_contains( $module_source, "add_action( 'wp_head'" )
		&& ! str_contains( $meta_head_source, 'erankly_render_hreflang_alternates();' ),
	'ML-CONF-009',
	'Hreflang must have an independently owned wp_head callback outside the aggregate core SEO renderer.',
	array( 'milestone' => $defects['ML-CONF-009']['milestone'] )
);

$result->finish();
