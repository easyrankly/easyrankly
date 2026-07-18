<?php
// phpcs:ignoreFile -- CLI orchestrator intentionally manages child processes and result files.
/**
 * Executes one manifest-declared standalone suite and records each test.
 *
 * @package EasyRankly
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "Certification suites may only run from CLI.\n" );
	exit( 2 );
}

$options = getopt( '', array( 'suite::', 'output::', 'runtime::' ) );
$suite   = isset( $options['suite'] ) ? (string) $options['suite'] : 'required_standalone_tests';
$output  = isset( $options['output'] ) ? (string) $options['output'] : '';
$runtime = isset( $options['runtime'] ) ? (string) $options['runtime'] : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$root    = dirname( __DIR__, 2 );
$manifest = require __DIR__ . '/manifest.php';

if ( ! isset( $manifest[ $suite ] ) || ! is_array( $manifest[ $suite ] ) ) {
	fwrite( STDERR, "Unknown certification suite: {$suite}.\n" );
	exit( 2 );
}

$failed = false;
foreach ( $manifest[ $suite ] as $test ) {
	$test = (string) $test;
	$path = $root . '/' . $test;
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Missing certification test: {$test}.\n" );
		$exit_code = 2;
		$duration  = 0;
	} else {
		$started = hrtime( true );
		$process = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', $path ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root
		);
		if ( ! is_resource( $process ) ) {
			fwrite( STDERR, "Unable to start certification test: {$test}.\n" );
			$exit_code = 2;
			$duration  = 0;
		} else {
			$stdout    = stream_get_contents( $pipes[1] );
			$stderr    = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit_code = proc_close( $process );
			$duration  = (int) round( ( hrtime( true ) - $started ) / 1_000_000 );
			if ( '' !== (string) $stdout ) {
				fwrite( STDOUT, (string) $stdout );
			}
			if ( '' !== (string) $stderr ) {
				fwrite( STDERR, (string) $stderr );
			}
		}
	}

	$status = 0 === $exit_code ? 'pass' : 'fail';
	$row    = implode(
		"\t",
		array( 'standalone', $runtime, '', '', 'contract', $test, $status, (string) $duration, (string) $exit_code )
	) . "\n";
	if ( '' !== $output && false === file_put_contents( $output, $row, FILE_APPEND | LOCK_EX ) ) {
		fwrite( STDERR, "Unable to record certification result for {$test}.\n" );
		exit( 2 );
	}
	if ( 0 !== $exit_code ) {
		$failed = true;
	}
}

exit( $failed ? 1 : 0 );
