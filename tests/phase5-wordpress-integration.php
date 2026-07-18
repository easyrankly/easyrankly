<?php
// phpcs:ignoreFile -- WP-CLI harness mutates an ephemeral certification site.
/**
 * Real WordPress/MySQL Phase 5 private-upload and switch-decision tests.
 *
 * Run inside a fresh WordPress installation with EasyRankly active:
 * wp eval-file wp-content/plugins/easyrankly/tests/phase5-wordpress-integration.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

require_once ERANKLY_PATH . 'includes/import-export.php';
require_once ERANKLY_PATH . 'includes/reset.php';

function erankly_phase5_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function erankly_phase5_finish_job( string $job_id, int $maximum = 60 ): array {
	$runner = erankly_migration_job_runner();
	$loops  = 0;
	while ( is_array( $runner->active_job() ) ) {
		$runner->process( $job_id );
		if ( ++$loops > $maximum ) {
			throw new RuntimeException( 'The Phase 5 worker did not reach a terminal state.' );
		}
	}
	$report = erankly_migration_manager()->get_report( $job_id );
	erankly_phase5_wp_assert( is_array( $report ), 'A terminal Phase 5 job must persist its report.' );
	return $report;
}

add_filter( 'erankly_migration_batch_size', static fn(): int => 10 );
wp_set_current_user( 1 );

$fixture = ERANKLY_PATH . 'tests/fixtures/migrations/yoast-redirects-official.csv';
$staged  = ERankly_Migration_Upload_Store::stage_trusted_file( $fixture );
erankly_phase5_wp_assert( ! empty( $staged['ok'] ) && 'yoast' === $staged['source'], 'WordPress must auto-detect the certified Yoast Premium export.' );
$private_path = wp_normalize_path( (string) $staged['path'] );
erankly_phase5_wp_assert( ! str_starts_with( $private_path, untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/' ), 'The source export must not be stored below the public WordPress tree.' );
erankly_phase5_wp_assert( ! str_starts_with( $private_path, untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/' ), 'The source export must not be stored below wp-content.' );

$preview = erankly_migration_job_runner()->start_from_export( 'yoast', $private_path, true );
erankly_phase5_wp_assert( ! empty( $preview['ok'] ) && ! empty( $preview['job']['source_file_managed'] ), 'A privately staged export must enter the worker as lifecycle-managed source data.' );
$preview_report = erankly_phase5_finish_job( (string) $preview['job']['id'] );
erankly_phase5_wp_assert( ! file_exists( $private_path ), 'A managed source export must be deleted after preview reaches a terminal report.' );
erankly_phase5_wp_assert( ! empty( $preview_report['source_file_lifecycle']['deleted'] ), 'The report must prove terminal source-file deletion.' );
erankly_phase5_wp_assert( 'ready' === $preview_report['verification']['state'] && ! empty( $preview_report['verification']['ready_to_import'] ), 'A clean export preview must explicitly authorize the real import.' );
erankly_phase5_wp_assert( ! erankly_migration_manager()->adapter( 'yoast' )->uses_export_file(), 'A terminal preview must release its deleted path from the shared adapter.' );

$staged = ERankly_Migration_Upload_Store::stage_trusted_file( $fixture );
$import = erankly_migration_job_runner()->start_from_export( 'yoast', (string) $staged['path'], false );
erankly_phase5_wp_assert( ! empty( $import['ok'] ), 'The same certified export must be accepted again for the approved import.' );
$import_report = erankly_phase5_finish_job( (string) $import['job']['id'] );
erankly_phase5_wp_assert( ! file_exists( (string) $staged['path'] ), 'A managed source export must be deleted after the real import.' );
erankly_phase5_wp_assert( 2 === (int) $import_report['counts']['redirects_created'], 'The real Yoast Premium export import must create both certified redirect rules.' );
erankly_phase5_wp_assert( 'safe' === $import_report['verification']['state'] && ! empty( $import_report['verification']['ready_to_switch'] ), 'Only the clean verified import must authorize a controlled plugin switch.' );

$staged = ERankly_Migration_Upload_Store::stage_trusted_file( $fixture );
$cancel = erankly_migration_job_runner()->start_from_export( 'yoast', (string) $staged['path'], true );
erankly_phase5_wp_assert( ! empty( $cancel['ok'] ), 'The cancellation lifecycle fixture must start.' );
erankly_phase5_wp_assert( erankly_migration_job_runner()->cancel( (string) $cancel['job']['id'] ), 'A managed upload job must remain cancellable.' );
$cancel_report = erankly_migration_manager()->get_report( (string) $cancel['job']['id'] );
erankly_phase5_wp_assert( is_array( $cancel_report ) && 'cancelled' === $cancel_report['status'], 'Cancellation must produce a terminal report.' );
erankly_phase5_wp_assert( ! file_exists( (string) $staged['path'] ) && ! empty( $cancel_report['source_file_lifecycle']['deleted'] ), 'Cancellation must delete the managed private source export.' );
erankly_phase5_wp_assert( 'blocked' === $cancel_report['verification']['state'], 'A cancelled run must prohibit switching source plugins.' );
erankly_phase5_wp_assert( ! erankly_migration_manager()->adapter( 'yoast' )->uses_export_file(), 'Cancellation must release its deleted path from the shared adapter.' );

$mismatch = ERankly_Migration_Upload_Store::stage_trusted_file( $fixture, 'aioseo' );
erankly_phase5_wp_assert( empty( $mismatch['ok'] ) && 'source_mismatch' === $mismatch['error'], 'A selected source/signature mismatch must fail closed in real WordPress.' );

$_GET['report_id'] = (string) $import_report['id'];
ob_start();
erankly_import_export_render_panel();
$panel = (string) ob_get_clean();
unset( $_GET['report_id'] );
erankly_phase5_wp_assert( ! str_contains( $panel, 'name="erankly_migration_export_file"' ) && str_contains( $panel, 'Back to all import and export tools' ), 'A selected terminal report must stay focused instead of mixing in unrelated import forms.' );
erankly_phase5_wp_assert( str_contains( $panel, 'Data import complete' ) && str_contains( $panel, 'Import contract verified' ), 'The admin report must explain the authoritative contract-only outcome without claiming frontend comparison.' );
erankly_phase5_wp_assert( str_contains( $panel, 'Immutable source fingerprint' ) && str_contains( $panel, 'Proof boundary:' ) && str_contains( $panel, 'Decision SHA-256' ) && str_contains( $panel, 'Technical details' ), 'The simplified report must retain gate checks, proof boundary and decision fingerprint behind technical disclosure.' );

wp_set_current_user( 0 );
ob_start();
erankly_import_export_render_panel();
$unauthorized_panel = (string) ob_get_clean();
erankly_phase5_wp_assert( '' === $unauthorized_panel, 'Users without the settings capability must not receive migration controls.' );
wp_set_current_user( 1 );

$orphan      = ERankly_Migration_Upload_Store::stage_trusted_file( ERANKLY_PATH . 'tests/fixtures/migrations/aioseo-redirects-official.json' );
$orphan_path = (string) $orphan['path'];
erankly_phase5_wp_assert( file_exists( $orphan_path ), 'The reset lifecycle fixture must create a managed orphan.' );
erankly_reset_site_data();
erankly_phase5_wp_assert( ! file_exists( $orphan_path ), 'A complete EasyRankly reset must remove orphaned private migration uploads.' );

WP_CLI::success( 'Phase 5 real WordPress/MySQL private-upload certification passed.' );
