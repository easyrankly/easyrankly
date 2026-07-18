<?php
// phpcs:ignoreFile -- WP-CLI integration harness exercises durable rollback pages.
/** Verifies bounded rollback cursor persistence across separate worker calls. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

if ( ! function_exists( 'erankly_migration_journal' ) ) {
	require_once ERANKLY_PATH . 'includes/migrations.php';
}

function erankly_rollback_resume_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$journal = erankly_migration_journal();
erankly_rollback_resume_assert( $journal->ensure_schema(), 'Rollback journal storage is unavailable.' );
$job_id  = wp_generate_uuid4();
$post_id = wp_insert_post(
	array(
		'post_title'  => 'Rollback cursor fixture',
		'post_status' => 'publish',
		'post_type'   => 'post',
	),
	true
);
erankly_rollback_resume_assert( ! is_wp_error( $post_id ), 'Rollback fixture post could not be created.' );

$writes = array(
	'_erankly_title'       => 'Imported title',
	'_erankly_description' => 'Imported description',
	'_erankly_canonical'   => 'https://example.test/imported',
);
$queue_id = 0;
foreach ( $writes as $key => $value ) {
	++$queue_id;
	$payload = array( 'object_type' => 'post', 'object_id' => (int) $post_id, 'key' => $key, 'value' => $value );
	$event   = $journal->prepare_meta( $job_id, $queue_id, $payload );
	erankly_rollback_resume_assert( '' !== $event, 'A rollback fixture event could not be prepared.' );
	update_post_meta( (int) $post_id, $key, $value );
	erankly_rollback_resume_assert( $journal->mark_applied( $event ), 'A rollback fixture event could not be marked applied.' );
}

$batch_filter = static fn(): int => 1;
add_filter( 'erankly_migration_rollback_batch_size', $batch_filter );

$first      = $journal->rollback( $job_id );
$checkpoint = $journal->rollback_checkpoint( $job_id );
erankly_rollback_resume_assert( 'running' === ( $first['status'] ?? '' ) && 1 === (int) ( $first['rolled_back'] ?? 0 ), 'The first rollback request was not bounded to one row.' );
erankly_rollback_resume_assert( is_array( $checkpoint ) && (int) ( $checkpoint['cursor'] ?? PHP_INT_MAX ) < PHP_INT_MAX, 'The descending rollback cursor was not persisted.' );
erankly_rollback_resume_assert( false !== wp_next_scheduled( ERANKLY_MIGRATION_ROLLBACK_CRON_HOOK, array( $job_id ) ), 'The next rollback page was not scheduled.' );

$second = $journal->process_rollback( $job_id );
erankly_rollback_resume_assert( 'running' === ( $second['status'] ?? '' ) && 2 === (int) ( $second['rolled_back'] ?? 0 ), 'The second rollback page did not resume from its checkpoint.' );

$third = $journal->process_rollback( $job_id );
erankly_rollback_resume_assert( 'complete' === ( $third['status'] ?? '' ) && 3 === (int) ( $third['rolled_back'] ?? 0 ) && 3 === (int) ( $third['batches'] ?? 0 ), 'The terminal rollback checkpoint is incomplete.' );
erankly_rollback_resume_assert( 0 === (int) $journal->summary( $job_id )['available'], 'Terminal rollback left available journal rows.' );
foreach ( array_keys( $writes ) as $key ) {
	erankly_rollback_resume_assert( ! metadata_exists( 'post', (int) $post_id, $key ), "Rollback did not remove {$key}." );
}

remove_filter( 'erankly_migration_rollback_batch_size', $batch_filter );
$journal->clear_rollback_checkpoint( $job_id );
wp_clear_scheduled_hook( ERANKLY_MIGRATION_ROLLBACK_CRON_HOOK, array( $job_id ) );
wp_delete_post( (int) $post_id, true );

WP_CLI::success( 'Resumable bounded rollback cursor integration passed.' );
