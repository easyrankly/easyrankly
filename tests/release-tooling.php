<?php
/**
 * Dependency-free regressions for deterministic release tooling.
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/bin/release-lib.php';

/** Fails the harness when a release invariant is not satisfied. */
function erankly_release_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'Release tooling regression failed: ' . $message . "\n" );
		exit( 1 );
	}
}

$root     = dirname( __DIR__ );
$patterns = erankly_release_ignore_patterns( $root );
$files    = erankly_release_collect_files( $root );

erankly_release_test_assert( '2.0.0' === erankly_release_version( $root ), 'all shipping version surfaces must stay aligned to 2.0.0' );
erankly_release_test_assert( count( $files ) >= 100, 'the production inventory is unexpectedly small' );
erankly_release_test_assert( isset( $files['easyrankly.php'], $files['readme.txt'], $files['uninstall.php'] ), 'required plugin entrypoints must ship' );
erankly_release_test_assert( isset( $files['includes/migrations/class-erankly-migration-verification-job.php'] ), 'the background verifier must ship' );
erankly_release_test_assert( ! isset( $files['includes/plugin-check.php'] ), 'checkout-only Plugin Check integration must not ship' );
erankly_release_test_assert( erankly_release_is_ignored( 'tests/p0-regressions.php', $patterns ), 'tests must be excluded' );
erankly_release_test_assert( erankly_release_is_ignored( 'vendor/bin/phpcs', $patterns ), 'Composer dependencies must be excluded' );
erankly_release_test_assert( erankly_release_is_ignored( 'bin/build-release.php', $patterns ), 'release tooling must be excluded' );
erankly_release_test_assert( erankly_release_is_ignored( '.github/workflows/release-quality.yml', $patterns ), 'CI configuration must be excluded' );
erankly_release_test_assert( erankly_release_is_ignored( 'admin/.DS_Store', $patterns ), 'nested operating-system metadata must be excluded' );
erankly_release_test_assert( erankly_release_is_ignored( 'assets/debug.log', $patterns ), 'nested log files must be excluded' );
erankly_release_test_assert( ! erankly_release_is_ignored( 'assets/js/editor.js', $patterns ), 'production assets must remain eligible' );

foreach ( array_keys( $files ) as $relative ) {
	erankly_release_test_assert( ! erankly_release_is_ignored( $relative, $patterns ), 'ignored path leaked into production inventory: ' . $relative );
}

fwrite( STDOUT, "Release tooling regressions passed.\n" );
