<?php
// phpcs:ignoreFile -- WP-CLI integration harness mutates an ephemeral test site.
/**
 * Real WordPress/MySQL integration test for the Phase 3 worker.
 *
 * Run inside a fresh WordPress installation with EasyRankly active:
 * wp eval-file wp-content/plugins/easyrankly/tests/phase3-wordpress-integration.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

require_once ERANKLY_PATH . 'includes/import-export.php';

function erankly_phase3_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function erankly_phase3_finish_active_job( string $job_id ): array {
	$runner = erankly_migration_job_runner();
	$loops  = 0;
	while ( is_array( $runner->active_job() ) ) {
		$runner->process( $job_id );
		if ( ++$loops > 50 ) {
			throw new RuntimeException( 'The migration worker did not reach a terminal state.' );
		}
	}

	$report = erankly_migration_manager()->get_report( $job_id );
	erankly_phase3_wp_assert( is_array( $report ), 'The completed worker must persist its report.' );
	return $report;
}

add_filter( 'erankly_migration_batch_size', static fn(): int => 10 );

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Migration integration fixture',
		'post_content' => 'Fixture content.',
		'post_status'  => 'publish',
	)
);
erankly_phase3_wp_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'The fixture post must be created.' );

update_post_meta( $post_id, '_yoast_wpseo_title', '%%title%% | %%sitename%%' );
update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Migrated from the Yoast fixture.' );
update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '2' );
update_post_meta( $post_id, '_yoast_wpseo_focuskw', 'fixture keyphrase' );
update_post_meta( $post_id, '_yoast_wpseo_redirect', '/fixture-new' );
update_post_meta( $post_id, '_erankly_title', 'Keep the existing EasyRankly title' );
update_option(
	'wpseo-premium-redirects-base',
	array(
		array(
			'origin' => '/fixture-old',
			'url'    => '/fixture-target',
			'type'   => 308,
			'format' => 'plain',
		),
	),
	false
);

$preview = erankly_migration_job_runner()->start( 'yoast', true );
erankly_phase3_wp_assert( ! empty( $preview['ok'] ) && ! empty( $preview['job']['id'] ), 'The preview must be queued.' );
$preview_id = (string) $preview['job']['id'];
$runner     = erankly_migration_job_runner();
$runner->process( $preview_id );
$first_checkpoint = $runner->active_job();
erankly_phase3_wp_assert( is_array( $first_checkpoint ), 'The first preview checkpoint must remain active.' );
$replayed_job           = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION );
$replayed_job['stream'] = 'content';
$replayed_job['cursor'] = array();
update_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, $replayed_job, false );
$runner->process( $preview_id );
$after_replay = $runner->active_job();
erankly_phase3_wp_assert( is_array( $after_replay ), 'The replayed preview checkpoint must remain active.' );
erankly_phase3_wp_assert( $first_checkpoint['counts'] === $after_replay['counts'], 'Replaying a staged page must not double-count its source events.' );
$preview_report = erankly_phase3_finish_active_job( $preview_id );
erankly_phase3_wp_assert( 'complete' === $preview_report['status'], 'The resumable preview must complete.' );
erankly_phase3_wp_assert( 0 === (int) $preview_report['counts']['fields_written'], 'Preview must not write metadata.' );
erankly_phase3_wp_assert( ! metadata_exists( 'post', $post_id, '_erankly_description' ), 'Preview must leave the target description absent.' );

$import = erankly_migration_job_runner()->start( 'yoast', false );
erankly_phase3_wp_assert( ! empty( $import['ok'] ) && ! empty( $import['job']['id'] ), 'The real migration must be queued after preview.' );
$import_id = (string) $import['job']['id'];
$loops     = 0;
do {
	$active = $runner->active_job();
	erankly_phase3_wp_assert( is_array( $active ), 'The import must remain active until its apply phase.' );
	if ( 'apply' === (string) $active['stream'] ) {
		break;
	}
	$runner->process( $import_id );
	if ( ++$loops > 20 ) {
		throw new RuntimeException( 'The import did not reach its apply phase.' );
	}
} while ( true );

global $wpdb;
$pending_description = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT * FROM %i WHERE job_id = %s AND item_kind = %s AND target_field = %s AND apply_status = %s LIMIT 1',
		$wpdb->prefix . 'erankly_migration_queue',
		$import_id,
		'meta',
		'_erankly_description',
		'pending'
	),
	ARRAY_A
);
erankly_phase3_wp_assert( is_array( $pending_description ), 'The fixture description must be staged before apply.' );
$pending_payload = json_decode( (string) $pending_description['payload'], true );
update_metadata( 'post', (int) $pending_payload['object_id'], (string) $pending_payload['key'], $pending_payload['value'] );
$import_report = erankly_phase3_finish_active_job( $import_id );
erankly_phase3_wp_assert( 'complete' === $import_report['status'], 'The resumable import must complete.' );
erankly_phase3_wp_assert( 'Keep the existing EasyRankly title' === get_post_meta( $post_id, '_erankly_title', true ), 'Existing EasyRankly metadata must be preserved.' );
erankly_phase3_wp_assert( 'Migrated from the Yoast fixture.' === get_post_meta( $post_id, '_erankly_description', true ), 'Validated metadata must be written.' );
erankly_phase3_wp_assert( (int) $import_report['counts']['redirects_created'] >= 2, 'Yoast Premium and per-post redirects must be created.' );
erankly_phase3_wp_assert( ! empty( $import_report['execution']['resumable'] ) && (int) $import_report['execution']['batches'] > 1, 'The report must prove resumable multi-batch execution.' );

$queue_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'erankly_migration_queue' ) );
erankly_phase3_wp_assert( 0 === (int) $queue_count, 'Finished jobs must clean their staging rows.' );

// Simulate a crash after queue cleanup but before the final active-job option
// is removed. The durable final report must be reused instead of being rebuilt
// from the now-empty staging table.
$finalizing_job = array(
	'id'                 => $import_id,
	'source'             => 'yoast',
	'dry_run'            => false,
	'status'             => 'finalizing',
	'stream'             => 'finish',
	'cursor'             => array(),
	'batches'            => (int) $import_report['execution']['batches'],
	'cancel_requested'   => false,
	'started_at'         => (string) $import_report['started_at'],
	'updated_at'         => gmdate( 'c' ),
	'last_error'         => '',
	'report'             => $import_report,
	'final_report_ready' => true,
);
update_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, $finalizing_job, false );
$runner->process( $import_id );
$replayed_report = erankly_migration_manager()->get_report( $import_id );
erankly_phase3_wp_assert( ! is_array( $runner->active_job() ), 'A retried finalizer must remove its active checkpoint.' );
erankly_phase3_wp_assert( $import_report['counts'] === $replayed_report['counts'], 'A retried finalizer must retain the durable report counters.' );

// Verify that an atomic start slot rejects a second job and that cancellation
// remains durable while another worker owns the processing lock.
$cancel_job = $runner->start( 'yoast', true );
erankly_phase3_wp_assert( ! empty( $cancel_job['ok'] ), 'The cancellation fixture must start.' );
$cancel_id = (string) $cancel_job['job']['id'];
$competing = $runner->start( 'yoast', true );
erankly_phase3_wp_assert( empty( $competing['ok'] ) && 'migration_already_running' === $competing['error'], 'A second migration must not replace the active checkpoint.' );
erankly_phase3_wp_assert( $cancel_id === (string) $competing['job']['id'], 'The rejected start must return the original active job.' );

$acquire_lock = new ReflectionMethod( ERankly_Migration_Job_Runner::class, 'acquire_lock' );
$release_lock = new ReflectionMethod( ERankly_Migration_Job_Runner::class, 'release_lock' );
if ( PHP_VERSION_ID < 80500 ) {
	$acquire_lock->setAccessible( true );
	$release_lock->setAccessible( true );
}
$lock_token   = (string) $acquire_lock->invoke( $runner, $cancel_id );
erankly_phase3_wp_assert( '' !== $lock_token, 'The cancellation fixture must own the worker lock.' );
erankly_phase3_wp_assert( $runner->cancel( $cancel_id ), 'Cancellation must be accepted while the worker is locked.' );
$pending_cancel = $runner->active_job();
erankly_phase3_wp_assert( is_array( $pending_cancel ) && ! empty( $pending_cancel['cancel_requested'] ), 'A locked worker must expose the durable cancellation request.' );
ob_start();
erankly_migration_render_active_job( $pending_cancel );
$cancel_html = (string) ob_get_clean();
erankly_phase3_wp_assert( str_contains( $cancel_html, 'Cancellation requested' ), 'The progress UI must expose a pending cancellation.' );
erankly_phase3_wp_assert( ! str_contains( $cancel_html, 'value="migration-process"' ), 'The progress UI must hide competing controls while cancellation is pending.' );
$release_lock->invoke( $runner, $cancel_id, $lock_token );
$runner->process( $cancel_id );
$cancel_report = erankly_migration_manager()->get_report( $cancel_id );
erankly_phase3_wp_assert( ! is_array( $runner->active_job() ), 'Cancellation must remove the active checkpoint.' );
erankly_phase3_wp_assert( is_array( $cancel_report ) && 'cancelled' === $cancel_report['status'], 'Cancellation must persist a terminal report.' );
$cancel_key = 'erankly_migration_cancel_' . substr( hash( 'sha256', $cancel_id ), 0, 24 );
erankly_phase3_wp_assert( false === get_option( $cancel_key, false ), 'Cancellation must clean its durable request option.' );

// A stale lock is taken over through compare-and-swap. A second caller cannot
// acquire it, and the owner can release it without touching a successor.
$stale_job_id = wp_generate_uuid4();
$stale_key    = 'erankly_migration_lock_' . substr( hash( 'sha256', $stale_job_id ), 0, 24 );
update_option(
	$stale_key,
	array(
		'token'   => 'stale-token',
		'created' => time() - 301,
	),
	false
);
$stale_owner = (string) $acquire_lock->invoke( $runner, $stale_job_id );
erankly_phase3_wp_assert( '' !== $stale_owner, 'Exactly one worker must take over an expired lock.' );
erankly_phase3_wp_assert( '' === (string) $acquire_lock->invoke( $runner, $stale_job_id ), 'A live lock must reject a competing worker.' );
$release_lock->invoke( $runner, $stale_job_id, $stale_owner );
erankly_phase3_wp_assert( false === get_option( $stale_key, false ), 'The lock owner must release its lock.' );

WP_CLI::success( 'Phase 3 real WordPress/MySQL migration integration passed.' );
