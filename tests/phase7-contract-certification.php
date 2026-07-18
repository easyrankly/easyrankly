<?php
// phpcs:ignoreFile -- Standalone certification harness intentionally loads prior phase stubs.
/**
 * Phase 7 immutable contract and matrix certification.
 *
 * Run: php tests/phase7-contract-certification.php
 *
 * @package EasyRankly
 */

require __DIR__ . '/phase5-upload-certification.php';

$manifest = require __DIR__ . '/certification/manifest.php';
$fixtures = __DIR__ . '/fixtures/migrations/';
$plugin   = (string) file_get_contents( dirname( __DIR__ ) . '/easyrankly.php' );
$matches  = array();
preg_match( "/define\( 'ERANKLY_VERSION', '([^']+)' \);/", $plugin, $matches );

erankly_phase2_assert( 1 === (int) ( $manifest['schema_version'] ?? 0 ), 'The certification manifest schema must be explicitly versioned.' );
erankly_phase2_assert( ( $matches[1] ?? '' ) === (string) ( $manifest['plugin_version'] ?? '' ), 'The certification manifest must target the exact plugin version under test.' );
erankly_phase2_assert( array( 'yoast', 'rankmath', 'aioseo', 'seopress' ) === array_keys( $manifest['sources'] ?? array() ), 'The certification matrix must cover every supported migration source.' );

$adapter_classes = array(
	'yoast'    => 'ERankly_Migration_Adapter_Yoast',
	'rankmath' => 'ERankly_Migration_Adapter_RankMath',
	'aioseo'   => 'ERankly_Migration_Adapter_AIOSEO',
	'seopress' => 'ERankly_Migration_Adapter_SEOPress',
);

foreach ( $manifest['sources'] as $source => $contract ) {
	erankly_phase2_assert( 2 === count( $contract['editions'] ?? array() ), $source . ' must certify both its Free/Lite and paid edition.' );
	erankly_phase2_assert( ! empty( $contract['pro_surfaces'] ), $source . ' must name its paid feature surfaces explicitly.' );

	$reflection = new ReflectionClass( $adapter_classes[ $source ] );
	$method     = $reflection->getMethod( 'supported_versions' );
	if ( PHP_VERSION_ID < 80500 ) {
		$method->setAccessible( true );
	}
	$actual_range = $method->invoke( $reflection->newInstance() );
	erankly_phase2_assert( $contract['version_range'] === $actual_range, $source . ' manifest and adapter version ranges must be identical.' );

	foreach ( array_merge( $contract['contract_fixtures'], $contract['official_formats'] ) as $fixture ) {
		erankly_phase2_assert( isset( $manifest['fixture_hashes'][ $fixture ] ), 'Every matrix fixture must have an immutable SHA-256: ' . $fixture . '.' );
		erankly_phase2_assert( is_file( $fixtures . $fixture ), 'Every matrix fixture must exist: ' . $fixture . '.' );
		erankly_phase2_assert( hash_equals( $manifest['fixture_hashes'][ $fixture ], hash_file( 'sha256', $fixtures . $fixture ) ), 'Fixture bytes changed without a manifest review: ' . $fixture . '.' );
	}
}

foreach ( array( 'required_standalone_tests', 'required_wordpress_tests', 'required_multisite_tests' ) as $suite ) {
	erankly_phase2_assert( ! empty( $manifest[ $suite ] ), 'Every certification layer must list required tests: ' . $suite . '.' );
	foreach ( $manifest[ $suite ] as $test_file ) {
		erankly_phase2_assert( is_file( dirname( __DIR__ ) . '/' . $test_file ), 'A required certification test is missing: ' . $test_file . '.' );
	}
}

$runner       = (string) file_get_contents( __DIR__ . '/certification/run.sh' );
$suite_runner = (string) file_get_contents( __DIR__ . '/certification/run-suite.php' );
erankly_phase2_assert( str_contains( $runner, 'run-suite.php' ) && str_contains( $runner, 'required_standalone_tests' ), 'The Docker runner must execute the standalone manifest through the suite runner.' );
erankly_phase2_assert( str_contains( $runner, 'manifest_tests required_wordpress_tests' ), 'The Docker runner must source single-site WordPress tests from the manifest.' );
erankly_phase2_assert( str_contains( $runner, 'manifest_tests required_multisite_tests' ), 'The Docker runner must source Multisite tests from the manifest.' );
erankly_phase2_assert( str_contains( $suite_runner, '$manifest[ $suite ]' ), 'The standalone suite runner must iterate the selected manifest suite.' );

erankly_phase2_assert( 'externally_supplied' === ( $manifest['evidence_layers']['licensed_pro_binaries'] ?? '' ), 'Licensed PRO binaries must never be represented as bundled fixtures.' );

fwrite( STDOUT, "Phase 7 immutable migration contract certification passed.\n" );
