<?php
// phpcs:ignoreFile -- Small CLI bridge from the PHP manifest to the shell runner.
/** Lists the tests declared for one certification suite. */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

$options  = getopt( '', array( 'suite:' ) );
$suite    = isset( $options['suite'] ) ? (string) $options['suite'] : '';
$manifest = require __DIR__ . '/manifest.php';
if ( '' === $suite || ! isset( $manifest[ $suite ] ) || ! is_array( $manifest[ $suite ] ) ) {
	fwrite( STDERR, "Unknown certification suite.\n" );
	exit( 2 );
}

foreach ( $manifest[ $suite ] as $test ) {
	fwrite( STDOUT, (string) $test . "\n" );
}
