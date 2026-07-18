<?php
// phpcs:ignoreFile -- CLI concurrency harness intentionally manages subprocesses and pipes.
/**
 * Proves that stateful standalone certification suites isolate temporary data.
 *
 * Run: php tests/concurrent-standalone-certification.php
 *
 * @package EasyRankly
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "Concurrent certification may only run from CLI.\n" );
	exit( 2 );
}

$root  = dirname( __DIR__ );
$tests = array(
	'tests/phase5-upload-certification.php',
	'tests/phase7-contract-certification.php',
	'tests/phase8-go-live-gate.php',
);
$processes = array();

foreach ( $tests as $test ) {
	$pipes   = array();
	$process = proc_open(
		array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', $test ),
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$root
	);

	if ( ! is_resource( $process ) ) {
		fwrite( STDERR, "Unable to start concurrent certification: {$test}.\n" );
		exit( 2 );
	}

	$processes[] = array(
		'test'    => $test,
		'process' => $process,
		'pipes'   => $pipes,
	);
}

$failed = 0;
foreach ( $processes as $entry ) {
	$stdout = stream_get_contents( $entry['pipes'][1] );
	$stderr = stream_get_contents( $entry['pipes'][2] );
	fclose( $entry['pipes'][1] );
	fclose( $entry['pipes'][2] );
	$exit_code = proc_close( $entry['process'] );

	if ( 0 !== $exit_code ) {
		++$failed;
		fwrite( STDERR, "Concurrent certification failed: {$entry['test']} (exit {$exit_code}).\n{$stdout}{$stderr}" );
	}
}

if ( 0 !== $failed ) {
	exit( 1 );
}

fwrite( STDOUT, "Concurrent Phase 5/7/8 temporary-file isolation certification passed.\n" );
