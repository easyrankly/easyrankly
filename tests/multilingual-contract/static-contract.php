<?php
// phpcs:ignoreFile -- Standalone manifest validation has no WordPress runtime.
/**
 * Standalone M1 contract integrity check.
 *
 * @package EasyRankly
 */

$root     = dirname( __DIR__, 2 );
$manifest = require __DIR__ . '/manifest.php';
$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( 1 === (int) ( $manifest['schema_version'] ?? 0 ), 'M1 manifest schema must be version 1.' );
$assert( 'eccebfb5fed965b92e383eea8d7b67916c2a2267' === (string) ( $manifest['baseline']['commit'] ?? '' ), 'M1 manifest must pin the requested baseline commit.' );
$assert( array( 3, 250, 501 ) === ( $manifest['fixture_sizes'] ?? array() ), 'M1 fixture sizes must be exactly 3, 250 and 501.' );
$assert(
	array(
		'bundled' => 'easyrankly-bundled-multilingual',
		'addon'   => 'easyrankly-multilingual',
	) === ( $manifest['provider_ids'] ?? array() ),
	'M1 provider IDs must be limited to the bundled and official add-on identifiers.'
);
$assert( array( 'legacy-baseline', 'multisite-conformance' ) === array_keys( $manifest['suites'] ?? array() ), 'M1 suites must remain explicitly separated.' );
$assert( 'pass' === ( $manifest['suites']['legacy-baseline']['expected'] ?? '' ), 'legacy-baseline must remain green.' );
$assert( 'fail-on-2.0-baseline' === ( $manifest['suites']['multisite-conformance']['expected'] ?? '' ), 'multisite-conformance must remain expected-red on 2.0.' );
$expected_conformance_ids = array_map( static fn( int $number ): string => sprintf( 'ML-CONF-%03d', $number ), range( 1, 9 ) );
$assert( $expected_conformance_ids === array_keys( $manifest['conformance_defects'] ?? array() ), 'Conformance defects must be exactly ML-CONF-001 through ML-CONF-009.' );

foreach ( $manifest['suites'] as $suite ) {
	$assert( is_file( $root . '/' . $suite['file'] ), 'A declared M1 suite file is missing: ' . $suite['file'] );
}

foreach ( $manifest['conformance_defects'] as $id => $defect ) {
	$assert( in_array( $defect['milestone'] ?? '', array( 'M2', 'M4' ), true ), $id . ' must be assigned to M2 or M4.' );
}

$assert( is_file( __DIR__ . '/snapshots/legacy-baseline.php' ), 'The provider-neutral legacy snapshot is missing.' );
$assert( false === strpos( (string) file_get_contents( $root . '/easyrankly.php' ), 'ERANKLY_EXTENSION_API_VERSION' ), 'M1 must not introduce the M2 provider API.' );

$conformance_source = (string) file_get_contents( __DIR__ . '/multisite-conformance.php' );
preg_match_all( '/[\'\"](ML-CONF-\\d{3})[\'\"]/', $conformance_source, $conformance_matches );
$conformance_ids = array_values( array_unique( $conformance_matches[1] ?? array() ) );
sort( $conformance_ids, SORT_STRING );
$assert( $expected_conformance_ids === $conformance_ids, 'The conformance scenario must emit exactly ML-CONF-001 through ML-CONF-009.' );

$bootstrap_source = (string) file_get_contents( __DIR__ . '/bootstrap.php' );
$runner_source    = (string) file_get_contents( __DIR__ . '/run.sh' );
$expected_csv     = implode( ',', $expected_conformance_ids );
$assert( str_contains( $bootstrap_source, 'ERANKLY_ML_CONTRACT_FAILURE_IDS=' ), 'The result harness must expose exact failure IDs for runner verification.' );
$assert( str_contains( $runner_source, 'EXPECTED_CONFORMANCE_IDS="' . $expected_csv . '"' ), 'The runner must pin the exact bundled conformance failure-ID set.' );
$assert( str_contains( $runner_source, 'actual_ids' ) && str_contains( $runner_source, 'ERANKLY_ML_CONTRACT_FAILURE_IDS=' ), 'The runner must compare conformance output, not only its exit code.' );

$prepare_source = (string) file_get_contents( __DIR__ . '/prepare.php' );
$assert( str_contains( $prepare_source, "'bundled' === \$provider ? 1 : 0" ), 'Preparation must enable the legacy module only for the bundled provider.' );
$assert( str_contains( $runner_source, 'ERANKLY_ML_CONTRACT_DRIVER_FILE' ), 'The runner must preserve the external add-on adapter seam.' );

$shared_php_files = array(
	'bootstrap.php',
	'concurrency-verify.php',
	'concurrency-worker.php',
	'fixtures.php',
	'legacy-baseline.php',
	'multisite-conformance.php',
	'prepare.php',
);
$forbidden_classes = array( 'ERankly_ML_Repository', 'ERankly_ML_Resolver', 'ERankly_ML_Admin' );
$class_token_ids    = array( T_STRING );
if ( defined( 'T_NAME_QUALIFIED' ) ) {
	$class_token_ids[] = T_NAME_QUALIFIED;
}
if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
	$class_token_ids[] = T_NAME_FULLY_QUALIFIED;
}

foreach ( $shared_php_files as $relative_file ) {
	$source = (string) file_get_contents( __DIR__ . '/' . $relative_file );
	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	for ( $index = 0; $index < $count; ++$index ) {
		$token = $tokens[ $index ];
		if ( ! is_array( $token ) ) {
			continue;
		}

		$class_name = ltrim( (string) $token[1], '\\' );
		$class_name = false !== strrpos( $class_name, '\\' ) ? substr( $class_name, strrpos( $class_name, '\\' ) + 1 ) : $class_name;
		if ( in_array( $token[0], $class_token_ids, true ) && in_array( $class_name, $forbidden_classes, true ) ) {
			$failures[] = $relative_file . ' depends directly on bundled class ' . $class_name . '.';
		}

		if ( T_STRING === $token[0] && 'erankly_ml_boot' === $token[1] ) {
			for ( $next = $index + 1; $next < $count; ++$next ) {
				if ( is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
					continue;
				}
				if ( '(' === $tokens[ $next ] ) {
					$failures[] = $relative_file . ' calls the bundled boot function directly.';
				}
				break;
			}
		}

		if ( T_STRING === $token[0] && in_array( strtolower( $token[1] ), array( 'class_exists', 'interface_exists', 'is_a', 'is_subclass_of' ), true ) ) {
			$window = array_slice( $tokens, $index, 12 );
			foreach ( $window as $window_token ) {
				if ( ! is_array( $window_token ) || T_CONSTANT_ENCAPSED_STRING !== $window_token[0] ) {
					continue;
				}
				$referenced_class = trim( $window_token[1], "'\"\\" );
				if ( in_array( $referenced_class, $forbidden_classes, true ) ) {
					$failures[] = $relative_file . ' probes bundled class ' . $referenced_class . ' directly.';
				}
				break;
			}
		}

		if ( T_VARIABLE === $token[0] && '$GLOBALS' === $token[1] ) {
			$window = array_slice( $tokens, $index, 8 );
			foreach ( $window as $window_token ) {
				if ( is_array( $window_token ) && T_CONSTANT_ENCAPSED_STRING === $window_token[0] && str_contains( $window_token[1], 'erankly_ml_admin' ) ) {
					$failures[] = $relative_file . ' accesses the bundled admin global directly.';
					break;
				}
			}
		}
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "EasyRankly Multilingual M1 static contract passed.\n" );
