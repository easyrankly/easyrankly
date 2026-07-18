<?php
// phpcs:ignoreFile -- Standalone source contract intentionally uses local filesystem and globals.
/** Static contract for bounded export and resumable complete imports. */

declare(strict_types=1);

$root       = dirname( __DIR__ );
$controller = (string) file_get_contents( $root . '/includes/import-export.php' );
$runner     = (string) file_get_contents( $root . '/includes/class-erankly-import-job-runner.php' );
$repository = (string) file_get_contents( $root . '/includes/redirects/class-erankly-redirects-repository.php' );

function erankly_io_batch_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Import/export batch contract failed: {$message}\n" );
		exit( 1 );
	}
}

erankly_io_batch_assert( str_contains( $controller, 'erankly_export_stream_array( $stream )' ), 'the HTTP export is not streamed by data type' );
erankly_io_batch_assert( ! preg_match( '/function erankly_export_download\(\).*?erankly_export_build_data\(/s', $controller ), 'the download still materializes the full export payload' );
erankly_io_batch_assert( preg_match( '/WHERE \{\$cursor_column\} > %d.*?ORDER BY \{\$cursor_column\} ASC LIMIT %d/s', $controller ) === 1, 'metadata export is not keyset-paginated' );
erankly_io_batch_assert( str_contains( $repository, 'WHERE id > %d ORDER BY id ASC LIMIT %d' ), 'redirect export is not keyset-paginated' );
erankly_io_batch_assert( str_contains( $controller, 'ERankly_Import_Job_Runner::start( $tmp_name, $data )' ) && str_contains( $controller, 'ERankly_Import_Job_Runner::process( $job_id )' ), 'the upload handler does not create and advance a resumable job' );
erankly_io_batch_assert( str_contains( $runner, "'stage'      => 'settings'" ) && str_contains( $runner, "'offset'     => 0" ), 'the import job has no durable stage/offset cursor' );
erankly_io_batch_assert( str_contains( $runner, 'array_slice( $records, $offset, $limit )' ), 'import writes are not bounded to one batch' );
erankly_io_batch_assert( preg_match( '/SELECT \{\$id_column\} FROM \{\$table\} WHERE \{\$id_column\} IN/s', $runner ) === 1, 'object IDs are still resolved one record at a time' );
erankly_io_batch_assert( str_contains( $runner, 'wp_schedule_single_event' ) && str_contains( $runner, 'ERANKLY_IMPORT_ACTIVE_JOB_OPTION' ), 'the import cursor is not recoverable through WP-Cron' );

fwrite( STDOUT, "Bounded export and resumable import contract passed.\n" );
