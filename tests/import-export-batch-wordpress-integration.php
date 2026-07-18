<?php
// phpcs:ignoreFile -- WP-CLI integration harness exercises bounded real writes.
/** Verifies complete-import resume cursors and keyset export pages in WordPress. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

if ( ! function_exists( 'erankly_export_page' ) ) {
	require_once ERANKLY_PATH . 'includes/import-export.php';
}

function erankly_io_batch_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$post_ids = array();
foreach ( range( 1, 25 ) as $number ) {
	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Import batch fixture ' . $number,
			'post_status' => 'publish',
			'post_type'   => 'post',
		),
		true
	);
	erankly_io_batch_wp_assert( ! is_wp_error( $post_id ), 'A batch fixture post could not be created.' );
	$post_ids[] = (int) $post_id;
}

$payload = array(
	'plugin'    => 'erankly',
	'post_meta' => array_map(
		static fn( int $post_id ): array => array( 'id' => $post_id, 'key' => '_erankly_title', 'value' => 'Imported title ' . $post_id ),
		$post_ids
	),
);
$batch_filter = static fn(): int => 10;
add_filter( 'erankly_import_batch_size', $batch_filter );

$result = ERankly_Import_Job_Runner::apply_payload_batch( $payload );
erankly_io_batch_wp_assert( empty( $result['done'] ) && 'post_meta' === ( $result['cursor']['stage'] ?? '' ) && 10 === (int) ( $result['cursor']['offset'] ?? 0 ), 'The first import page did not stop at its ten-record cursor.' );
erankly_io_batch_wp_assert( '' !== (string) get_post_meta( $post_ids[9], '_erankly_title', true ) && '' === (string) get_post_meta( $post_ids[10], '_erankly_title', true ), 'The first import page wrote beyond its cursor.' );

while ( empty( $result['done'] ) ) {
	$result = ERankly_Import_Job_Runner::apply_payload_batch( $payload, $result['cursor'] );
}
erankly_io_batch_wp_assert( 25 === (int) ( $result['post_meta'] ?? 0 ), 'Cumulative import counts were not preserved across resume checkpoints.' );
foreach ( $post_ids as $post_id ) {
	erankly_io_batch_wp_assert( 'Imported title ' . $post_id === get_post_meta( $post_id, '_erankly_title', true ), 'A resumed metadata value is missing or changed.' );
	delete_post_meta( $post_id, '_erankly_title' );
}

// Exercise the actual durable file/checkpoint worker, not only its compatibility API.
$directory = ERankly_Migration_Upload_Store::directory();
erankly_io_batch_wp_assert( '' !== $directory, 'Private import storage is unavailable.' );
$path    = $directory . '/erankly-import-' . str_replace( '-', '', wp_generate_uuid4() ) . '.json';
$encoded = wp_json_encode( $payload );
erankly_io_batch_wp_assert( is_string( $encoded ) && false !== file_put_contents( $path, $encoded ), 'The private import fixture could not be written.' );
chmod( $path, 0600 );
$job_id = wp_generate_uuid4();
$job    = array(
	'id'         => $job_id,
	'status'     => 'queued',
	'path'       => wp_normalize_path( $path ),
	'sha256'     => hash_file( 'sha256', $path ),
	'stage'      => 'settings',
	'offset'     => 0,
	'batches'    => 0,
	'processed'  => 0,
	'started_at' => gmdate( 'c' ),
	'updated_at' => gmdate( 'c' ),
	'counts'     => array( 'settings' => 0, 'redirects' => 0, 'post_meta' => 0, 'term_meta' => 0, 'user_meta' => 0 ),
	'totals'     => array( 'redirects' => 0, 'post_meta' => 25, 'term_meta' => 0, 'user_meta' => 0 ),
);
delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );
erankly_io_batch_wp_assert( add_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, $job, '', 'no' ), 'The durable import checkpoint could not be created.' );

$active = ERankly_Import_Job_Runner::process( $job_id );
erankly_io_batch_wp_assert( is_array( $active ) && 10 === (int) ( $active['offset'] ?? 0 ), 'The durable worker did not persist its first ten-record cursor.' );
erankly_io_batch_wp_assert( false !== wp_next_scheduled( ERANKLY_IMPORT_CRON_HOOK, array( $job_id ) ), 'The durable worker did not schedule its next batch.' );
$loops = 0;
while ( is_array( get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, null ) ) ) {
	ERankly_Import_Job_Runner::process( $job_id );
	if ( ++$loops > 10 ) {
		throw new RuntimeException( 'The durable import worker did not terminate.' );
	}
}
$finished = get_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, array() );
erankly_io_batch_wp_assert( 'complete' === ( $finished['status'] ?? '' ) && 25 === (int) ( $finished['counts']['post_meta'] ?? 0 ), 'The durable worker lost its terminal counts.' );
erankly_io_batch_wp_assert( ! file_exists( $path ) && false === wp_next_scheduled( ERANKLY_IMPORT_CRON_HOOK, array( $job_id ) ), 'The durable worker retained its private file or Cron event.' );
foreach ( $post_ids as $post_id ) {
	erankly_io_batch_wp_assert( 'Imported title ' . $post_id === get_post_meta( $post_id, '_erankly_title', true ), 'The durable worker lost a resumed metadata value.' );
}

$after_id = 0;
$seen     = array();
do {
	$page = erankly_export_page( 'post_meta', $after_id, 7 );
	erankly_io_batch_wp_assert( count( $page ) <= 7, 'A keyset export page exceeded its declared limit.' );
	foreach ( $page as $row ) {
		$after_id = max( $after_id, absint( $row['_cursor'] ?? 0 ) );
		if ( in_array( absint( $row['id'] ?? 0 ), $post_ids, true ) && '_erankly_title' === (string) ( $row['key'] ?? '' ) ) {
			$seen[] = absint( $row['id'] );
		}
	}
} while ( 7 === count( $page ) );
sort( $seen );
$expected = $post_ids;
sort( $expected );
erankly_io_batch_wp_assert( $expected === array_values( array_unique( $seen ) ), 'Keyset export pagination lost or duplicated fixture metadata.' );

remove_filter( 'erankly_import_batch_size', $batch_filter );
delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );
foreach ( $post_ids as $post_id ) {
	wp_delete_post( $post_id, true );
}

WP_CLI::success( 'Resumable complete-import and keyset-export integration passed.' );
