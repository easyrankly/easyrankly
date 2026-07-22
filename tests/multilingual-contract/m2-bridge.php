<?php
// phpcs:ignoreFile -- WP-CLI integration mutates only the disposable M2 Multisite fixture.
/**
 * EasyRankly 2.1 bridge, SEO, filters and ownership lifecycle contract.
 *
 * @package EasyRankly
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

$result   = new ERankly_ML_Contract_Result( 'm2-bridge' );
$driver   = erankly_ml_contract_driver();
$provider = erankly_get_multilingual_provider();

$result->same( 1, erankly_get_extension_api_version(), 'M2-API-001', 'The bridge must expose extension API major 1.' );
$result->check( $provider instanceof ERankly_Multilingual_Provider_Interface, 'M2-API-002', 'One provider must be selected.' );
$result->same( 'easyrankly-bundled-multilingual', $provider instanceof ERankly_Multilingual_Provider_Interface ? $provider->get_id() : '', 'M2-FALLBACK-001', 'The no-add-on baseline must select the bundled fallback.' );

$sites    = erankly_ml_contract_sites( 3 );
$site_ids = array_map( static fn( WP_Site $site ): int => (int) $site->blog_id, $sites );
$driver->save_site_map( erankly_ml_contract_site_map( $sites ) );
$post_id = erankly_ml_contract_create_post( $site_ids[0], 'm2-public-contract' );
switch_to_blog( $site_ids[0] );
erankly_ml_contract_set_post_query( $post_id );

$legacy_filter_calls = 0;
$legacy_filter       = static function ( array $value ) use ( &$legacy_filter_calls ): array {
	++$legacy_filter_calls;

	return $value;
};
$filter_context      = array();
$filter_provider_id  = '';
$context_filter      = static function ( mixed $value, array $context, string $provider_id ) use ( &$filter_context, &$filter_provider_id ): mixed {
	$filter_context     = $context;
	$filter_provider_id = $provider_id;

	return $value;
};

add_filter( 'erankly_hreflang_alternates', $legacy_filter, 10, 1 );
add_filter( 'erankly_hreflang_alternates', $context_filter, 20, 3 );
erankly_get_hreflang_alternates();
remove_filter( 'erankly_hreflang_alternates', $legacy_filter, 10 );
remove_filter( 'erankly_hreflang_alternates', $context_filter, 20 );
$result->same( 1, $legacy_filter_calls, 'M2-FILTER-001', 'A legacy one-argument alternate callback must run once.' );
$result->same( 'easyrankly-bundled-multilingual', $filter_provider_id, 'M2-FILTER-002', 'Alternate filters must receive the selected provider ID.' );
$result->same( 'post', (string) ( $filter_context['kind'] ?? '' ), 'M2-FILTER-003', 'Alternate filters must receive normalized request context.' );

$legacy_navigable_calls = 0;
$legacy_navigable       = static function ( array $value ) use ( &$legacy_navigable_calls ): array {
	++$legacy_navigable_calls;

	return $value;
};
add_filter( 'erankly_navigable_hreflang_alternates', $legacy_navigable, 10, 1 );
erankly_get_navigable_hreflang_alternates();
remove_filter( 'erankly_navigable_hreflang_alternates', $legacy_navigable, 10 );
$result->same( 1, $legacy_navigable_calls, 'M2-FILTER-004', 'A legacy one-argument navigable callback must run once.' );

$localized_args = array();
$legacy_url      = static function ( string $url ) use ( &$legacy_filter_calls ): string {
	++$legacy_filter_calls;

	return $url;
};
$context_url     = static function ( string $url, array $context, string $provider_id ) use ( &$localized_args ): string {
	$localized_args = array( $context, $provider_id );

	return $url;
};
add_filter( 'erankly_localized_url', $legacy_url, 10, 1 );
add_filter( 'erankly_localized_url', $context_url, 20, 3 );
$localized = erankly_localize_url( get_permalink( $post_id ) );
remove_filter( 'erankly_localized_url', $legacy_url, 10 );
remove_filter( 'erankly_localized_url', $context_url, 20 );
$result->same( get_permalink( $post_id ), $localized, 'M2-FILTER-005', 'The bundled fallback must preserve localized URL baseline output.' );
$result->same( 'easyrankly-bundled-multilingual', (string) ( $localized_args[1] ?? '' ), 'M2-FILTER-006', 'Localized URL filters must receive the provider ID after provider output.' );

$hreflang_callbacks = erankly_ml_contract_count_callbacks(
	'wp_head',
	static fn( mixed $callback ): bool => 'erankly_render_hreflang_alternates' === $callback
);
$result->same( 1, $hreflang_callbacks, 'M2-HREFLANG-001', 'Exactly one independent hreflang callback must be registered.' );

$cede_hreflang = static fn( string $owner ): string => 'none';
add_filter( 'erankly_hreflang_output_owner', $cede_hreflang, 10, 1 );
ob_start();
erankly_render_hreflang_alternates();
$ceded_output = (string) ob_get_clean();
remove_filter( 'erankly_hreflang_output_owner', $cede_hreflang, 10 );
$result->same( '', $ceded_output, 'M2-HREFLANG-002', 'An explicit external hreflang owner must suppress EasyRankly output without registering a second callback.' );

$seo_context = array(
	'kind'           => 'post',
	'object_id'      => $post_id,
	'object_subtype' => 'post',
	'blog_id'        => $site_ids[0],
	'url'            => get_permalink( $post_id ),
);
$seo_state   = erankly_get_object_seo_state( $seo_context );
$result->same(
	array( 'exists', 'published', 'public', 'indexable', 'canonical_url', 'canonical_is_self', 'reason_codes' ),
	array_keys( $seo_state ),
	'M2-SEO-001',
	'The public SEO state must preserve its exact ordered key contract.'
);
$result->check( $seo_state['exists'] && $seo_state['published'] && $seo_state['public'] && $seo_state['indexable'] && $seo_state['canonical_is_self'], 'M2-SEO-002', 'A public self-canonical post must be indexable.' );
update_post_meta( $post_id, '_erankly_index_directive', 'noindex' );
$noindex_state = erankly_get_object_seo_state( $seo_context );
$result->check( ! $noindex_state['indexable'] && in_array( 'noindex', $noindex_state['reason_codes'], true ), 'M2-SEO-003', 'SEO state must consume the explicit robots tri-state.' );
delete_post_meta( $post_id, '_erankly_index_directive' );
restore_current_blog();

$lock = erankly_ml_acquire_ownership_lock();
$result->check( is_string( $lock ), 'M2-OWN-001', 'The shared ownership mutex must be acquirable.' );
$second_lock = erankly_ml_acquire_ownership_lock();
$result->check( is_wp_error( $second_lock ) && 'erankly_ml_ownership_locked' === $second_lock->get_error_code(), 'M2-OWN-002', 'A concurrent mutex owner must be rejected.' );
if ( is_string( $lock ) ) {
	$result->check( erankly_ml_renew_ownership_lock( $lock ), 'M2-OWN-003', 'The current mutex owner must renew its lease with CAS.' );
	$result->check( erankly_ml_release_ownership_lock( $lock ), 'M2-OWN-004', 'Only the current mutex owner must release the lease.' );
}

$fingerprint = 'sha256:' . hash( 'sha256', 'm2-disposable-storage' );
$pending     = erankly_ml_prepare_storage_claim( 'easyrankly-multilingual', '1.0.0', $fingerprint );
$result->check( is_array( $pending ) && 'pending' === $pending['state'], 'M2-OWN-005', 'Adoption must begin with a revisioned pending journal.' );
$ready = is_array( $pending )
	? erankly_ml_verify_storage_claim( (string) $pending['lease_token'], (int) $pending['revision'], $fingerprint )
	: new WP_Error( 'm2_pending_missing' );
$result->check( is_array( $ready ) && 'ready' === $ready['state'], 'M2-OWN-006', 'Verified adoption must advance pending to ready by CAS.' );
$result->check( ! erankly_ml_legacy_writes_allowed(), 'M2-OWN-007', 'A live adoption journal must freeze legacy mutations.' );

$crash_hook = static function ( string $phase ): void {
	if ( 'legacy_toggle_off' === $phase ) {
		throw new RuntimeException( 'Simulated claim crash.' );
	}
};
add_action( 'erankly_ml_lifecycle_checkpoint', $crash_hook, 10, 1 );
$crashed = false;
if ( is_array( $ready ) ) {
	try {
		erankly_ml_claim_storage( (string) $ready['lease_token'], (int) $ready['revision'], $fingerprint );
	} catch ( RuntimeException ) {
		$crashed = true;
	}
}
remove_action( 'erankly_ml_lifecycle_checkpoint', $crash_hook, 10 );
$after_crash = erankly_ml_get_storage_owner_marker();
$result->check( $crashed && 'ready' === ( $after_crash['state'] ?? '' ) && ! erankly_get_setting( 'enable_multilingual', 0 ), 'M2-OWN-008', 'A crash after toggle-off must remain restartable and fail closed.' );

$claimed = is_array( $ready )
	? erankly_ml_claim_storage( (string) $ready['lease_token'], (int) $ready['revision'], $fingerprint )
	: new WP_Error( 'm2_ready_missing' );
$result->check( is_array( $claimed ) && 'claimed' === $claimed['state'] && 'easyrankly-multilingual' === $claimed['current_owner'], 'M2-OWN-009', 'Claim must atomically transfer current owner and close the lease.' );
$result->check( ! erankly_ml_bundled_runtime_allowed() && ! erankly_ml_storage_cleanup_allowed(), 'M2-FALLBACK-002', 'Claimed storage must suppress fallback and cleanup.' );

$settings_before                 = erankly_get_stored_settings();
$settings_before['m2_unrelated'] = 'preserve-me';
$writer_one                      = erankly_update_plugin_settings( $settings_before );
$writer_two                      = erankly_update_plugin_settings( array( 'enable_multilingual' => 1, 'm2_writer_two' => 'merged' ) );
$stored                          = erankly_get_stored_settings();
$result->check( true === $writer_one && true === $writer_two && 'preserve-me' === ( $stored['m2_unrelated'] ?? '' ) && 'merged' === ( $stored['m2_writer_two'] ?? '' ), 'M2-OWN-010', 'Sequential concurrent-writer equivalents must merge against current settings.' );
$result->same( 0, (int) ( $stored['enable_multilingual'] ?? 0 ), 'M2-OWN-011', 'No settings writer may re-enable the legacy toggle while claimed.' );

$cas_lock = erankly_ml_acquire_ownership_lock();
if ( is_string( $cas_lock ) && is_array( $claimed ) ) {
	$stale = erankly_ml_cas_storage_owner_marker( $claimed, (int) $claimed['revision'] - 1, $cas_lock );
	$result->check( is_wp_error( $stale ) && 'erankly_ml_revision_conflict' === $stale->get_error_code(), 'M2-OWN-012', 'A stale ownership revision must fail CAS.' );
	erankly_ml_release_ownership_lock( $cas_lock );
}

$bundled_probe = new ERankly_Bundled_Multilingual_Provider();
$blocked       = $bundled_probe->preflight();
$result->check( is_wp_error( $blocked ) && false === ( $blocked->get_error_data()['fallback_allowed'] ?? true ), 'M2-FALLBACK-003', 'Bundled preflight must fail closed after claim.' );

require_once ERANKLY_PATH . 'includes/reset.php';
global $wpdb;
$relations_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->base_prefix . 'erankly_ml_relations' ) );
$reset_claimed     = erankly_reset_network_relations_batch( 0, 1000 );
$relations_after  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->base_prefix . 'erankly_ml_relations' ) );
$result->check( false === $reset_claimed['has_more'] && $relations_before === $relations_after && 'claimed' === erankly_ml_get_storage_owner_marker()['state'], 'M2-RESET-001', 'Network reset must preserve relations and marker while storage is claimed.' );

$rollback_ready = erankly_ml_prepare_storage_rollback( $fingerprint );
$rolled_back    = is_array( $rollback_ready )
	? erankly_ml_complete_storage_rollback( (string) $rollback_ready['lease_token'], (int) $rollback_ready['revision'], true )
	: new WP_Error( 'm2_rollback_missing' );
$result->check( is_array( $rolled_back ) && 'core' === $rolled_back['state'] && erankly_ml_bundled_owner_id() === $rolled_back['current_owner'], 'M2-ROLLBACK-001', 'Verified rollback must restore core ownership through rollback_ready.' );
$result->check( erankly_get_setting( 'enable_multilingual', 0 ) && erankly_ml_storage_cleanup_allowed(), 'M2-ROLLBACK-002', 'Rollback may restore the legacy toggle only after core ownership is verified.' );

$second_network = erankly_ml_contract_create_second_network_fixture();
$retained       = array_merge(
	erankly_ml_get_storage_owner_marker(),
	array(
		'current_owner'   => 'easyrankly-multilingual',
		'candidate_owner' => '',
		'state'           => 'retained',
		'lease_token'     => '',
	)
);
update_network_option( $second_network['network_id'], ERANKLY_ML_STORAGE_OWNER_OPTION, $retained );
$result->check( erankly_ml_any_network_requires_storage_retention(), 'M2-UNINSTALL-001', 'One retained network must block installation-wide multilingual cleanup.' );
delete_network_option( $second_network['network_id'], ERANKLY_ML_STORAGE_OWNER_OPTION );

$normal_batch = erankly_reset_network_relations_batch( 0, 10000 );
$result->check( false === $normal_batch['has_more'], 'M2-RESET-002', 'Core-owned relations must support normal bounded reset.' );
erankly_reset_network_shared_data();
$result->check( array() === erankly_ml_get_storage_owner_marker() && false === get_site_option( 'erankly_ml_sites', false ), 'M2-RESET-003', 'Normal core-owned reset must remove the marker and network language map.' );

$result->finish();
