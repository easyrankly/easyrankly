<?php
/**
 * PHPUnit bootstrap for the WordPress core test suite.
 *
 * Development-only file: excluded from release builds (see .distignore), so the
 * direct-access guard and browser-oriented prefix rules do not apply here. It
 * runs in CLI before WordPress loads, which is why ABSPATH cannot be required.
 */

$tests_dir = getenv( 'WP_TESTS_DIR' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local bootstrap variable; file is dev-only and excluded from releases.
$tests_dir = is_string( $tests_dir ) && '' !== $tests_dir ? $tests_dir : '/tmp/wordpress-tests-lib'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- See above.

$polyfills_dir = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local bootstrap variable.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_string( $polyfills_dir ) && '' !== $polyfills_dir ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_dir );
}

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Set WP_TESTS_DIR.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only failure notice before WordPress loads; no WP_Filesystem available.
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/easyrankly.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
