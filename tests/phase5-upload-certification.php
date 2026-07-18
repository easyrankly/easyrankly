<?php
// phpcs:ignoreFile -- Standalone private-upload and decision-engine certification harness.
/**
 * Phase 5 private upload lifecycle and report-verification tests.
 *
 * Run: php tests/phase5-upload-certification.php
 *
 * @package EasyRankly
 */

define( 'ERANKLY_MIGRATION_ACTIVE_JOB_OPTION', 'erankly_migration_active_job_v1' );

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0777, true );
}

function wp_is_writable( $path ) {
	return is_writable( $path );
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^a-zA-Z0-9._-]/', '-', basename( (string) $name ) );
}

function wp_delete_file( $path ) {
	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
	}
}

function get_current_blog_id() {
	return 1;
}

function get_temp_dir() {
	static $directory = '';

	if ( '' === $directory ) {
		$directory = trailingslashit( sys_get_temp_dir() ) . 'easyrankly-phase5-tests-' . (string) getmypid() . '/';
	}

	return $directory;
}

function delete_option( $name ) {
	unset( $GLOBALS['erankly_phase2_options'][ $name ] );
	return true;
}

require __DIR__ . '/phase4-adapter-certification.php';

/** Returns managed test files without exposing paths in assertion output. */
function erankly_phase5_managed_files(): array {
	$directory = ERankly_Migration_Upload_Store::directory( false );
	return '' === $directory ? array() : ( glob( $directory . '/erankly-source-*' ) ?: array() );
}

ERankly_Migration_Upload_Store::purge_all();
$fixtures = __DIR__ . '/fixtures/migrations/';
$sources  = array(
	'yoast-redirects-official.csv'   => 'yoast',
	'rankmath-metadata-official.csv' => 'rankmath',
	'aioseo-redirects-official.json' => 'aioseo',
	'seopress-metadata-official.csv' => 'seopress',
);

foreach ( $sources as $fixture => $expected_source ) {
	$staged = ERankly_Migration_Upload_Store::stage_trusted_file( $fixtures . $fixture );
	erankly_phase2_assert( ! empty( $staged['ok'] ) && $expected_source === ( $staged['source'] ?? '' ), 'Automatic detection must identify the certified official export: ' . $fixture . ' (' . ( $staged['error'] ?? $staged['source'] ?? 'unknown' ) . ').' );
	erankly_phase2_assert( ERankly_Migration_Upload_Store::owns( (string) $staged['path'] ), 'Every accepted export must use a random managed private filename.' );
	$permissions = fileperms( (string) $staged['path'] );
	erankly_phase2_assert( false !== $permissions && 0 === ( $permissions & 0077 ), 'Managed exports must not grant group or public filesystem permissions.' );
	erankly_phase2_assert( ERankly_Migration_Upload_Store::delete( (string) $staged['path'] ), 'A managed export must be deletable through the guarded lifecycle API.' );
}

$before   = count( erankly_phase5_managed_files() );
$mismatch = ERankly_Migration_Upload_Store::stage_trusted_file( $fixtures . 'yoast-redirects-official.csv', 'aioseo' );
erankly_phase2_assert( empty( $mismatch['ok'] ) && 'source_mismatch' === $mismatch['error'], 'An explicitly selected wrong source must fail closed.' );
erankly_phase2_assert( $before === count( erankly_phase5_managed_files() ), 'A source mismatch must not retain its private staging copy.' );

$invalid_path = get_temp_dir() . 'invalid-export.csv';
wp_mkdir_p( dirname( $invalid_path ) );
file_put_contents( $invalid_path, "source,target\n/foo,/bar\n" );
$invalid = ERankly_Migration_Upload_Store::stage_trusted_file( $invalid_path );
erankly_phase2_assert( empty( $invalid['ok'] ) && 'unsupported_export_signature' === $invalid['error'], 'A generic CSV must not be accepted as an official source export.' );
erankly_phase2_assert( $before === count( erankly_phase5_managed_files() ), 'A rejected signature must be erased immediately.' );
unlink( $invalid_path );

$http_rejection = ERankly_Migration_Upload_Store::store_http_upload(
	array(
		'error'    => UPLOAD_ERR_OK,
		'tmp_name' => $fixtures . 'yoast-redirects-official.csv',
		'name'     => 'yoast-redirects.csv',
	)
);
erankly_phase2_assert( empty( $http_rejection['ok'] ) && 'invalid_http_upload' === $http_rejection['error'], 'The HTTP entry point must reject a local path that PHP did not receive as an upload.' );

$released_file    = ERankly_Migration_Upload_Store::stage_trusted_file( $fixtures . 'yoast-redirects-official.csv' );
$released_adapter = new ERankly_Migration_Adapter_Yoast();
erankly_phase2_assert( $released_adapter->use_export_file( (string) $released_file['path'] ), 'The released-path regression fixture must select its valid export.' );
ERankly_Migration_Upload_Store::delete( (string) $released_file['path'] );
$released_profile = $released_adapter->profile();
erankly_phase2_assert( 'unsupported' === $released_profile['storage_status'] && 'unreadable_export' === $released_profile['storage_reason'], 'A concurrently removed export must fail closed without crashing profile rendering.' );
erankly_phase2_assert( 0 === $released_adapter->inventory()['total'] && '' === $released_adapter->fingerprint(), 'A removed export must expose neither inventory nor a stale fingerprint.' );

$stale = ERankly_Migration_Upload_Store::stage_trusted_file( $fixtures . 'yoast-redirects-official.csv' );
touch( (string) $stale['path'], time() - 172800 );
update_option(
	ERANKLY_MIGRATION_ACTIVE_JOB_OPTION,
	array(
		'id'          => 'active-private-upload',
		'source_file' => (string) $stale['path'],
	),
	false
);
erankly_phase2_assert( 0 === ERankly_Migration_Upload_Store::prune_stale() && file_exists( (string) $stale['path'] ), 'Stale cleanup must preserve the source file owned by the active resumable job.' );
delete_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION );
erankly_phase2_assert( 1 === ERankly_Migration_Upload_Store::prune_stale() && ! file_exists( (string) $stale['path'] ), 'Stale cleanup must erase an abandoned managed upload.' );

$orphan = ERankly_Migration_Upload_Store::stage_trusted_file( $fixtures . 'aioseo-redirects-official.json' );
erankly_phase2_assert( ! empty( $orphan['ok'] ) && ERankly_Migration_Upload_Store::purge_all(), 'Reset/uninstall cleanup must erase every managed private upload.' );
erankly_phase2_assert( array() === erankly_phase5_managed_files(), 'No managed upload may survive a full private-store purge.' );

$manager     = new ERankly_Migration_Manager();
$empty_counts = erankly_phase2_invoke( $manager, 'empty_counts', array() );
$preview     = $manager->new_report( 'yoast', true, 'phase5-preview' );
$preview['status']                      = 'complete';
$preview['counts']                      = $empty_counts;
$preview['source_fingerprint_verified'] = true;
$preview                                = $manager->finish_report( $preview );
erankly_phase2_assert( 'ready' === $preview['verification']['state'] && ! empty( $preview['verification']['ready_to_import'] ), 'A clean verified preview must produce an explicit ready-to-import decision.' );

$import                                 = $manager->new_report( 'yoast', false, 'phase5-import' );
$import['status']                       = 'complete';
$import['counts']                       = $empty_counts;
$import['source_fingerprint_verified']  = true;
$import                                 = $manager->finish_report( $import );
erankly_phase2_assert( 'blocked' === $import['verification']['state'] && empty( $import['verification']['ready_to_switch'] ), 'A legacy clean import without Phase 6 evidence must fail closed instead of authorizing a controlled switch.' );

$review                                 = $manager->new_report( 'yoast', false, 'phase5-review' );
$review['status']                       = 'complete';
$review['counts']                       = $empty_counts;
$review['counts']['fields_conflicts']   = 1;
$review['source_fingerprint_verified']  = true;
$review                                 = $manager->finish_report( $review );
erankly_phase2_assert( 'blocked' === $review['verification']['state'] && empty( $review['verification']['ready_to_switch'] ), 'Preserved conflicts must fail the authoritative gate and must not authorize a switch.' );

$blocked                                = $manager->new_report( 'yoast', false, 'phase5-blocked' );
$blocked['status']                      = 'partial';
$blocked['counts']                      = $empty_counts;
$blocked['counts']['fields_failed']     = 1;
$blocked['source_fingerprint_verified'] = true;
$blocked                                = $manager->finish_report( $blocked );
erankly_phase2_assert( 'blocked' === $blocked['verification']['state'] && empty( $blocked['verification']['ready_to_switch'] ), 'A partial import or failed write must prohibit source-plugin deactivation.' );

fwrite( STDOUT, "Phase 5 private upload and verification certification passed.\n" );
