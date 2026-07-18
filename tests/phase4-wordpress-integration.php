<?php
// phpcs:ignoreFile -- WP-CLI harness mutates an ephemeral certification site.
/**
 * Real WordPress/MySQL Phase 4 profile, signature and fingerprint tests.
 *
 * Run inside a fresh WordPress installation with EasyRankly active:
 * wp eval-file wp-content/plugins/easyrankly/tests/phase4-wordpress-integration.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

require_once ERANKLY_PATH . 'includes/import-export.php';

function erankly_phase4_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function erankly_phase4_finish_job( string $job_id, int $maximum = 60 ): array {
	$runner = erankly_migration_job_runner();
	$loops  = 0;
	while ( is_array( $runner->active_job() ) ) {
		$runner->process( $job_id );
		if ( ++$loops > $maximum ) {
			throw new RuntimeException( 'The Phase 4 worker did not reach a terminal state.' );
		}
	}
	$report = erankly_migration_manager()->get_report( $job_id );
	erankly_phase4_wp_assert( is_array( $report ), 'The Phase 4 worker must persist its report.' );
	return $report;
}

add_filter( 'erankly_migration_batch_size', static fn(): int => 10 );

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Phase 4 source integrity fixture',
		'post_content' => 'Source adapter certification.',
		'post_status'  => 'publish',
	)
);
erankly_phase4_wp_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'The Phase 4 source post must be created.' );

update_option( 'wpseo_version', '28.0', false );
update_post_meta( $post_id, '_yoast_wpseo_title', '%%title%% | %%sitename%%' );
update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Original source description.' );
update_option(
	'wpseo-premium-redirects-base',
	array(
		array(
			'origin' => '/phase4-old',
			'url'    => '/phase4-new',
			'type'   => 308,
			'format' => 'plain',
		),
	),
	false
);

$adapter = erankly_migration_manager()->adapter( 'yoast' );
erankly_phase4_wp_assert( $adapter instanceof ERankly_Migration_Adapter_Yoast, 'The Yoast adapter must be registered.' );
$profile = $adapter->profile();
erankly_phase4_wp_assert( 'supported' === $profile['storage_status'] && 'certified' === $profile['version_status'], 'A certified Yoast 28 DB signature must be supported.' );
erankly_phase4_wp_assert( 'premium' === $profile['edition'] && in_array( 'redirects', $profile['modules'], true ), 'Yoast Premium redirect storage must set edition and module profiles.' );
$inventory = $adapter->inventory();
erankly_phase4_wp_assert( $inventory['total'] >= 2 && ! empty( $inventory['surfaces']['post_meta'] ) && ! empty( $inventory['surfaces']['premium_redirects'] ), 'Inventory must report metadata and Premium redirects separately.' );

$fingerprint = $adapter->fingerprint();
update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Source changed after snapshot.' );
erankly_phase4_wp_assert( ! hash_equals( $fingerprint, $adapter->fingerprint() ), 'The source fingerprint must detect an in-place metadata value change.' );
update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Original source description.' );
erankly_phase4_wp_assert( hash_equals( $fingerprint, $adapter->fingerprint() ), 'Restoring the same source values must restore the deterministic fingerprint.' );

$started = erankly_migration_job_runner()->start( 'yoast', false );
erankly_phase4_wp_assert( ! empty( $started['ok'] ), 'A certified Yoast import must start.' );
$job_id = (string) $started['job']['id'];
erankly_migration_job_runner()->process( $job_id );
update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Changed while discovery is running.' );

$loops = 0;
do {
	$active = erankly_migration_job_runner()->active_job();
	erankly_phase4_wp_assert( is_array( $active ), 'A changed-source job must remain available for safe cancellation.' );
	if ( 'paused' === (string) $active['status'] ) {
		break;
	}
	erankly_migration_job_runner()->process( $job_id );
	erankly_phase4_wp_assert( ++$loops < 30, 'The changed-source job must pause before apply.' );
} while ( true );

$warnings = wp_list_pluck( (array) $active['report']['warnings'], 'code' );
erankly_phase4_wp_assert( in_array( 'source_changed_after_start', $warnings, true ), 'A changed source must expose a specific fail-closed diagnostic.' );
erankly_phase4_wp_assert( ! metadata_exists( 'post', $post_id, '_erankly_description' ), 'No target metadata may be applied after source drift.' );
erankly_phase4_wp_assert( erankly_migration_job_runner()->cancel( $job_id ), 'The paused changed-source job must remain cancellable.' );

update_option( 'wpseo_version', '29.0.0', false );
$future = erankly_migration_job_runner()->start( 'yoast', true );
erankly_phase4_wp_assert( empty( $future['ok'] ) && 'unsupported_source_storage' === $future['error'], 'An unrecognized future major version must fail closed even when old keys are present.' );
update_option( 'wpseo_version', '28.0', false );

global $wpdb;
$aio_table = $wpdb->prefix . 'aioseo_posts';
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $aio_table ) );
$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned NOT NULL, PRIMARY KEY (id))', $aio_table ) );
$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (post_id) VALUES (%d)', $aio_table, $post_id ) );
$aio_profile = erankly_migration_manager()->adapter( 'aioseo' )->profile();
erankly_phase4_wp_assert( 'unsupported' === $aio_profile['storage_status'] && 'unknown_storage_signature' === $aio_profile['storage_reason'], 'A malformed AIOSEO custom table must fail closed before SELECT *.' );
$aio_start = erankly_migration_job_runner()->start( 'aioseo', true );
erankly_phase4_wp_assert( empty( $aio_start['ok'] ) && 'unsupported_source_storage' === $aio_start['error'], 'The worker must reject a malformed AIOSEO table.' );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $aio_table ) );

$export_file = ERANKLY_PATH . 'tests/fixtures/migrations/yoast-redirects-official.csv';
$export      = erankly_migration_job_runner()->start_from_export( 'yoast', $export_file, true );
erankly_phase4_wp_assert( ! empty( $export['ok'] ), 'The worker must accept a certified Yoast official CSV fallback.' );
$export_id     = (string) $export['job']['id'];
$export_report = erankly_phase4_finish_job( $export_id );
erankly_phase4_wp_assert( 'official_export' === $export_report['source_profile']['mode'] && 'yoast-redirects-csv' === $export_report['source_profile']['storage_format'] && 'premium' === $export_report['source_profile']['edition'], 'The report must prove the exact official export signature and Premium edition used.' );
erankly_phase4_wp_assert( 2 === (int) $export_report['counts']['redirects_found'] && ! empty( $export_report['source_fingerprint_verified'] ), 'Official export redirects and their immutable fingerprint must be reported.' );

delete_option( 'wpseo_version' );
delete_option( 'wpseo-premium-redirects-base' );
wp_delete_post( $post_id, true );

WP_CLI::success( 'Phase 4 real WordPress/MySQL adapter certification passed.' );
