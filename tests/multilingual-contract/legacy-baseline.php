<?php
// phpcs:ignoreFile -- WP-CLI integration characterization mutates only an ephemeral Multisite fixture.
/**
 * Green characterization suite for the embedded EasyRankly 2.0 provider.
 *
 * @package EasyRankly
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

$result   = new ERankly_ML_Contract_Result( 'legacy-baseline' );
$driver   = erankly_ml_contract_driver();
$snapshot = erankly_ml_contract_snapshot();
$scale    = max( 3, (int) ( getenv( 'ERANKLY_ML_CONTRACT_SCALE' ) ?: 3 ) );
$manifest = erankly_ml_contract_manifest();
$provider = erankly_ml_contract_provider_name();

$result->check( in_array( $scale, $manifest['fixture_sizes'], true ), 'ML-BASE-011', 'The requested fixture size must be declared by M1.', array( 'scale' => $scale ) );
$result->same( $manifest['provider_ids'][ $provider ], $driver->id(), 'ML-BASE-001', 'The driver must identify the selected contract provider.' );
$result->same( $snapshot['provider_contract']['frontend_keys'], erankly_ml_contract_sorted_keys( $driver->frontend_contract() ), 'ML-BASE-006', 'The driver must expose the normalized frontend contract.' );
$result->same( $snapshot['provider_contract']['rest_keys'], erankly_ml_contract_sorted_keys( $driver->rest_contract() ), 'ML-BASE-007', 'The driver must expose the normalized REST contract.' );

$sites = erankly_ml_contract_sites( $scale );
$result->same( $scale, count( $sites ), 'ML-BASE-011', 'The current network must expose the requested site inventory.' );

$site_ids = array_map( static fn( WP_Site $site ): int => (int) $site->blog_id, $sites );
$primary  = array_slice( $site_ids, 0, 3 );
$main     = $primary[0];

$driver->save_site_map( erankly_ml_contract_site_map( $sites ) );
$result->same( $scale, count( $driver->site_map() ), 'ML-BASE-001', 'The legacy site registry must preserve every configured site.' );
$result->same( $main, $driver->default_blog_id(), 'ML-BASE-001', 'The first configured site must be the x-default site.' );

$fallback_blog  = $primary[2];
$result->same( $snapshot['locale_fallback'], $driver->locale_fallback( $fallback_blog, 'en_US' ), 'ML-BASE-001', 'A missing hreflang configuration must fall back to the target site locale.' );

switch_to_blog( $main );

$manual_posts = array();
$inferred_posts = array();
$noindex_posts = array();
$unpublished_posts = array();
$term_ids = array();
$inferred_term_ids = array();

foreach ( $primary as $index => $blog_id ) {
	$manual_posts[ $blog_id ]      = erankly_ml_contract_create_post( $blog_id, 'm1-manual-' . ( $index + 1 ) );
	$inferred_posts[ $blog_id ]    = erankly_ml_contract_create_post( $blog_id, 'm1-inferred-shared' );
	$noindex_posts[ $blog_id ]     = erankly_ml_contract_create_post( $blog_id, 'm1-noindex-' . ( $index + 1 ) );
	$unpublished_posts[ $blog_id ] = erankly_ml_contract_create_post(
		$blog_id,
		'm1-unpublished-' . ( $index + 1 ),
		0 === $index ? 'publish' : ( 1 === $index ? 'draft' : 'private' )
	);
	$term_ids[ $blog_id ] = erankly_ml_contract_create_term( $blog_id, 'm1-term-shared' );
	$inferred_term_ids[ $blog_id ] = erankly_ml_contract_create_term( $blog_id, 'm1-inferred-term-shared' );
}

$manual_group = $driver->link( 0, $main, 'post', $manual_posts[ $main ] );
foreach ( array_slice( $primary, 1 ) as $blog_id ) {
	$driver->link( $manual_group, $blog_id, 'post', $manual_posts[ $blog_id ] );
}

erankly_ml_contract_set_post_query( $manual_posts[ $main ] );
$manual_seo = $driver->seo_alternates();
$manual_nav = $driver->navigable_alternates();
$manual_head = $driver->render_hreflang();

$result->same( $snapshot['manual_post']['alternates'], erankly_ml_contract_normalize_alternates( $manual_seo, $site_ids ), 'ML-BASE-002', 'Manual post relations must reproduce the normalized SEO URL snapshot.' );
$result->same( $snapshot['manual_post']['alternates'], erankly_ml_contract_normalize_alternates( $manual_nav, $site_ids ), 'ML-BASE-004', 'Manual post relations must reproduce the normalized navigable URL snapshot.' );
$result->same( $snapshot['manual_post']['alternates'], erankly_ml_contract_normalize_head( $manual_head, $site_ids )['alternates'], 'ML-BASE-005', 'The hreflang renderer must reproduce the normalized alternate-link snapshot.' );
$result->check( isset( $manual_seo['x-default'] ) && $manual_seo['x-default'] === $manual_seo['it'], 'ML-BASE-005', 'x-default must point to the configured default translation.' );

require_once ERANKLY_PATH . 'includes/canonical.php';
update_post_meta( $manual_posts[ $main ], '_erankly_canonical', $snapshot['manual_post']['manual_canonical'] );
$canonical = erankly_get_canonical();
$result->same( $snapshot['manual_post']['manual_canonical'], $canonical, 'ML-BASE-005', 'The manual canonical must remain the rendered canonical value.' );
$result->same( $snapshot['manual_post']['manual_canonical_kept_alternate'], isset( $driver->seo_alternates()['it'] ), 'ML-BASE-005', 'The 2.0 snapshot intentionally records that manual canonical does not remove the legacy alternate.' );

$full_head = $driver->render_full_head();
$result->same( $snapshot['manual_post']['head'], erankly_ml_contract_normalize_head( $full_head, $site_ids ), 'ML-BASE-005', 'The complete head must reproduce canonical, alternate and credit semantics.' );

$robots_target_blog = $primary[1];
$robots_target_post = $manual_posts[ $robots_target_blog ];
foreach ( $snapshot['robots_matrix'] as $robots_case ) {
	switch_to_blog( $robots_target_blog );
	update_post_meta( $robots_target_post, '_erankly_index_directive', $robots_case['directive'] );
	if ( $robots_case['legacy_noindex'] ) {
		update_post_meta( $robots_target_post, '_erankly_noindex', '1' );
	} else {
		delete_post_meta( $robots_target_post, '_erankly_noindex' );
	}
	erankly_ml_contract_set_post_query( $robots_target_post );
	$robots_state = erankly_ml_contract_robots_index_state();
	restore_current_blog();

	erankly_ml_contract_set_post_query( $manual_posts[ $main ] );
	$seo_has_translation = isset( $driver->seo_alternates()['en'] );
	$result->same( $robots_case['robots'], $robots_state, 'ML-BASE-005', 'The core robots snapshot must cover the explicit tri-state and legacy boolean interaction.' );
	$result->same( $robots_case['seo_has_translation'], $seo_has_translation, 'ML-BASE-004', 'The provider eligibility snapshot must preserve its interaction with tri-state and legacy noindex metadata.' );
}
switch_to_blog( $robots_target_blog );
delete_post_meta( $robots_target_post, '_erankly_index_directive' );
delete_post_meta( $robots_target_post, '_erankly_noindex' );
restore_current_blog();
erankly_ml_contract_set_post_query( $manual_posts[ $main ] );

$noindex_group = $driver->link( 0, $main, 'post', $noindex_posts[ $main ] );
foreach ( array_slice( $primary, 1 ) as $blog_id ) {
	$driver->link( $noindex_group, $blog_id, 'post', $noindex_posts[ $blog_id ] );
}
switch_to_blog( $primary[1] );
update_post_meta( $noindex_posts[ $primary[1] ], '_erankly_noindex', true );
restore_current_blog();
erankly_ml_contract_set_post_query( $noindex_posts[ $main ] );
$result->same( $snapshot['legacy_noindex']['seo_keys'], erankly_ml_contract_sorted_keys( $driver->seo_alternates() ), 'ML-BASE-004', 'The SEO set must exclude a legacy noindex translation.' );
$result->same( $snapshot['legacy_noindex']['navigable_keys'], erankly_ml_contract_sorted_keys( $driver->navigable_alternates() ), 'ML-BASE-004', 'The navigable set must retain a published legacy noindex translation.' );

$unpublished_group = $driver->link( 0, $main, 'post', $unpublished_posts[ $main ] );
foreach ( array_slice( $primary, 1 ) as $blog_id ) {
	$driver->link( $unpublished_group, $blog_id, 'post', $unpublished_posts[ $blog_id ] );
}
erankly_ml_contract_set_post_query( $unpublished_posts[ $main ] );
$result->same( $snapshot['unpublished']['seo_keys'], erankly_ml_contract_sorted_keys( $driver->seo_alternates() ), 'ML-BASE-004', 'Draft/private translations must not produce an SEO set.' );
$result->same( $snapshot['unpublished']['navigable_keys'], erankly_ml_contract_sorted_keys( $driver->navigable_alternates() ), 'ML-BASE-004', 'Draft/private translations must not produce a navigable set.' );

erankly_ml_contract_set_post_query( $inferred_posts[ $main ] );
$inferred_seo = $driver->seo_alternates();
$inferred_switcher = $driver->render_shortcode( 'switcher' );
$result->same( $snapshot['inferred']['seo_keys'], erankly_ml_contract_sorted_keys( $inferred_seo ), 'ML-BASE-003', 'Matching post slugs must reproduce the inferred SEO snapshot.' );
$result->same( $snapshot['inferred']['switcher_rendered'], '' !== $inferred_switcher, 'ML-BASE-003', 'The baseline snapshot records that the switcher does not consume inferred matches.' );

$home_group = $driver->link( 0, $main, 'home', 0 );
foreach ( array_slice( $primary, 1 ) as $blog_id ) {
	$driver->link( $home_group, $blog_id, 'home', 0 );
}
erankly_ml_contract_set_home_query();
$result->same( $snapshot['home']['seo_keys'], erankly_ml_contract_sorted_keys( $driver->seo_alternates() ), 'ML-BASE-002', 'Manual home relations must reproduce the home alternate snapshot.' );

$term_group = $driver->link( 0, $main, 'term', $term_ids[ $main ] );
foreach ( array_slice( $primary, 1 ) as $blog_id ) {
	$driver->link( $term_group, $blog_id, 'term', $term_ids[ $blog_id ] );
}
erankly_ml_contract_set_term_query( $term_ids[ $main ], 'category' );
$result->same( $snapshot['term']['seo_keys'], erankly_ml_contract_sorted_keys( $driver->seo_alternates() ), 'ML-BASE-002', 'Manual term relations must reproduce the term alternate snapshot.' );

erankly_ml_contract_set_term_query( $inferred_term_ids[ $main ], 'category' );
$result->same( $snapshot['inferred_term']['seo_keys'], erankly_ml_contract_sorted_keys( $driver->seo_alternates() ), 'ML-BASE-003', 'Matching term slugs must reproduce the inferred term snapshot.' );

erankly_ml_contract_set_post_query( $manual_posts[ $main ] );
do_action( 'wp_enqueue_scripts' );
$frontend = $driver->frontend_contract();
$asset    = (string) $frontend['asset_handle'];
$before_assets = array(
	'style'  => wp_style_is( $asset, 'registered' ) && ! wp_style_is( $asset, 'enqueued' ) ? 'registered' : 'unexpected',
	'script' => wp_script_is( $asset, 'registered' ) && ! wp_script_is( $asset, 'enqueued' ) ? 'registered' : 'unexpected',
);
$result->same( $snapshot['shortcodes']['asset_states']['before'], $before_assets, 'ML-BASE-006', 'Multilingual frontend assets must start registered but not enqueued.' );
$switcher = $driver->render_shortcode( 'switcher' );
$notice   = $driver->render_shortcode( 'notice' );
$result->same( $snapshot['shortcodes']['switcher'], erankly_ml_contract_normalize_switcher( $switcher, $site_ids ), 'ML-BASE-006', 'The switcher must reproduce its normalized semantic HTML snapshot.' );
$result->same( $snapshot['shortcodes']['notice'], erankly_ml_contract_normalize_notice( $notice, $site_ids ), 'ML-BASE-006', 'The translation notice must reproduce its normalized data snapshot.' );
$after_assets = array(
	'style'  => wp_style_is( $asset, 'enqueued' ) ? 'enqueued' : 'unexpected',
	'script' => wp_script_is( $asset, 'enqueued' ) ? 'enqueued' : 'unexpected',
);
$result->same( $snapshot['shortcodes']['asset_states']['after'], $after_assets, 'ML-BASE-006', 'Rendering a multilingual shortcode must enqueue both contextual assets.' );

wp_set_current_user( 1 );
$server = rest_get_server();
$routes = $server->get_routes();
$rest_contract = $driver->rest_contract();
$result->check( isset( $routes[ $rest_contract['search_route'] ] ), 'ML-BASE-007', 'The provider search route must be registered.' );
$result->check( isset( $routes[ $rest_contract['settings_route'] ] ), 'ML-BASE-007', 'The provider settings route must be registered.' );
$result->check( isset( $GLOBALS['wp_rest_additional_fields']['post'][ $rest_contract['editor_field'] ] ), 'ML-BASE-007', 'The provider editor REST field must be registered.' );

$login = 'm1-editor-' . wp_generate_password( 8, false, false );
$user_id = wp_create_user( $login, wp_generate_password( 24 ), $login . '@example.test' );
$result->check( ! is_wp_error( $user_id ), 'ML-BASE-007', 'The cross-site capability fixture user must be created.' );
if ( ! is_wp_error( $user_id ) ) {
	add_user_to_blog( $main, (int) $user_id, 'editor' );
	wp_set_current_user( (int) $user_id );
	$request = new WP_REST_Request( 'GET', $rest_contract['search_route'] );
	$request->set_param( 'blog_id', $primary[1] );
	$request->set_param( 'object_type', 'post' );
	$request->set_param( 'q', 'M1 Manual' );
	$blocked = rest_do_request( $request );
	$result->same( $snapshot['rest']['blocked_search'], $blocked->get_data(), 'ML-BASE-007', 'A non-member must not enumerate a target site through REST search.' );

	$forged_source = erankly_ml_contract_create_post( $main, 'm1-forged-cross-site-link' );
	$driver->attempt_cross_site_post_link( $forged_source, $primary[1], $manual_posts[ $primary[1] ] );
	$result->same( 0, $driver->find_group_id( $main, 'post', $forged_source ), 'ML-BASE-007', 'A non-member forged relation update must not create a group.' );

	add_user_to_blog( $primary[1], (int) $user_id, 'editor' );
	$allowed = rest_do_request( $request );
	$result->same( $snapshot['rest']['allowed_search'], erankly_ml_contract_normalize_rest_search( (array) $allowed->get_data(), $site_ids ), 'ML-BASE-007', 'A target-site editor search must reproduce the normalized REST snapshot.' );
	wp_set_current_user( 1 );
}

$cache_source = erankly_ml_contract_create_post( $main, 'm1-cache-source' );
$cache_old    = erankly_ml_contract_create_post( $primary[1], 'm1-cache-old' );
$cache_new    = erankly_ml_contract_create_post( $primary[1], 'm1-cache-new' );
$cache_group  = $driver->link( 0, $main, 'post', $cache_source );
$driver->link( $cache_group, $primary[1], 'post', $cache_old );
$driver->group_for( $main, 'post', $cache_source );
$driver->find_group_id( $primary[1], 'post', $cache_old );
$driver->link( $cache_group, $primary[1], 'post', $cache_new );
$cache_members = $driver->group_for( $main, 'post', $cache_source );
$cache_ids = array_map( static fn( array $member ): int => (int) $member['object_id'], $cache_members );
$result->check( in_array( $cache_new, $cache_ids, true ) && ! in_array( $cache_old, $cache_ids, true ), 'ML-BASE-008', 'Replacing a blog slot must invalidate the group cache.' );
$result->same( 0, $driver->find_group_id( $primary[1], 'post', $cache_old ), 'ML-BASE-008', 'Replacing a blog slot must invalidate the removed object lookup cache.' );

$delete_source = erankly_ml_contract_create_post( $main, 'm1-delete-source' );
$delete_target = erankly_ml_contract_create_post( $primary[1], 'm1-delete-target' );
$delete_group  = $driver->link( 0, $main, 'post', $delete_source );
$driver->link( $delete_group, $primary[1], 'post', $delete_target );
switch_to_blog( $primary[1] );
wp_delete_post( $delete_target, true );
restore_current_blog();
$result->same( 1, count( $driver->group_for( $main, 'post', $delete_source ) ), 'ML-BASE-009', 'Deleting a translated post must remove only its relation.' );

$delete_term_source = erankly_ml_contract_create_term( $main, 'm1-delete-term-source' );
$delete_term_target = erankly_ml_contract_create_term( $primary[1], 'm1-delete-term-target' );
$delete_term_group  = $driver->link( 0, $main, 'term', $delete_term_source );
$driver->link( $delete_term_group, $primary[1], 'term', $delete_term_target );
switch_to_blog( $primary[1] );
wp_delete_term( $delete_term_target, 'category' );
restore_current_blog();
$result->same( 1, count( $driver->group_for( $main, 'term', $delete_term_source ) ), 'ML-BASE-009', 'Deleting a translated term must remove only its relation.' );

$network = get_network();
$temporary_blog = wpmu_create_blog(
	(string) $network->domain,
	trailingslashit( (string) $network->path ) . 'm1-delete-site/',
	'EasyRankly M1 deletion site',
	1,
	array( 'public' => 0 ),
	(int) $network->id
);
$result->check( ! is_wp_error( $temporary_blog ), 'ML-BASE-009', 'The site-deletion fixture must be created.' );
if ( ! is_wp_error( $temporary_blog ) ) {
	$temporary_post = erankly_ml_contract_create_post( (int) $temporary_blog, 'm1-delete-site-post' );
	$site_source = erankly_ml_contract_create_post( $main, 'm1-delete-site-source' );
	$site_group = $driver->link( 0, $main, 'post', $site_source );
	$driver->link( $site_group, (int) $temporary_blog, 'post', $temporary_post );
	if ( ! function_exists( 'wpmu_delete_blog' ) ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	wpmu_delete_blog( (int) $temporary_blog, true );
	$result->check( ! isset( $driver->site_map()[ (int) $temporary_blog ] ), 'ML-BASE-009', 'Deleting a site must remove it from the language registry.' );
	$site_members = $driver->group_for( $main, 'post', $site_source );
	$result->check(
		1 === count( $site_members ) && (int) $site_members[0]['blog_id'] === $main,
		'ML-BASE-009',
		'Deleting a site must remove only that site relations.'
	);
}

$robots_txt = erankly_filter_robots_txt( '', true );
$normalized_robots = erankly_ml_contract_normalize_robots_txt( $robots_txt, $site_ids );
$expected_sitemaps = array();
for ( $position = 1; $position <= $scale; ++$position ) {
	$expected_sitemaps[] = sprintf( $snapshot['robots_txt']['sitemap_template'], $position );
}
sort( $expected_sitemaps, SORT_STRING );
$result->same( $snapshot['robots_txt']['rules'], $normalized_robots['rules'], 'ML-BASE-005', 'robots.txt must preserve the normalized public crawler rules.' );
$result->same( $expected_sitemaps, $normalized_robots['sitemaps'], 'ML-BASE-005', 'robots.txt must list every enabled normalized site sitemap exactly once.' );

$second_network = erankly_ml_contract_create_second_network_fixture();
update_network_option( $second_network['network_id'], 'erankly_ml_sites', array( $second_network['blog_id'] => array( 'hreflang' => 'fr', 'enabled' => true, 'is_default' => true ) ) );
$result->same( $scale, count( $driver->site_map() ), 'ML-BASE-011', 'A second network option must not change the current network registry.' );
$result->same( 'fr', (string) get_network_option( $second_network['network_id'], 'erankly_ml_sites' )[ $second_network['blog_id'] ]['hreflang'], 'ML-BASE-011', 'The second network must retain its own language-map option.' );
$result->same( $snapshot['storage'], $driver->storage_descriptor(), 'ML-BASE-011', 'The provider storage descriptor must preserve shared-table and network-option semantics.' );
$result->same( $snapshot['ownership'], $driver->ownership_snapshot(), 'ML-BASE-012', 'The provider ownership guards must accept one resolver/emitter and detect duplicates.' );

erankly_ml_contract_prepare_concurrency_fixture();
$result->finish();
