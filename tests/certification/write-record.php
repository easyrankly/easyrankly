<?php
// phpcs:ignoreFile -- CLI artifact writer intentionally uses local filesystem and process APIs.
/**
 * Writes a machine-verifiable Phase 7 certification record.
 *
 * @package EasyRankly
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "Certification records may only be generated from CLI.\n" );
	exit( 2 );
}

$options      = getopt( '', array( 'output:', 'results:', 'test-results:', 'pro-evidence::' ) );
$output       = isset( $options['output'] ) ? (string) $options['output'] : '';
$results      = isset( $options['results'] ) ? (string) $options['results'] : '';
$test_results = isset( $options['test-results'] ) ? (string) $options['test-results'] : '';
$pro          = isset( $options['pro-evidence'] ) ? (string) $options['pro-evidence'] : '';
$root         = dirname( __DIR__, 2 );

require_once __DIR__ . '/helpers.php';

if ( '' === $output || ! is_file( $results ) || ! is_file( $test_results ) ) {
	fwrite( STDERR, "Usage: php write-record.php --output=<json> --results=<tsv> --test-results=<tsv> [--pro-evidence=<json>]\n" );
	exit( 2 );
}

$manifest = require __DIR__ . '/manifest.php';

$actual_cells = array();
$lines        = file( $results, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
foreach ( is_array( $lines ) ? $lines : array() as $line ) {
	$columns = explode( "\t", $line );
	if ( 6 !== count( $columns ) ) {
		fwrite( STDERR, "Malformed certification result row.\n" );
		exit( 2 );
	}
	$cell = array_combine( array( 'layer', 'php', 'wordpress', 'database', 'topology', 'status' ), $columns );
	if ( ! is_array( $cell ) || 'pass' !== $cell['status'] ) {
		fwrite( STDERR, "A certification cell did not pass.\n" );
		exit( 1 );
	}
	$key = erankly_certification_cell_key( $cell );
	if ( isset( $actual_cells[ $key ] ) ) {
		fwrite( STDERR, "Duplicate certification result cell: {$key}.\n" );
		exit( 2 );
	}
	$actual_cells[ $key ] = $cell;
}

$expected_cells = array();
foreach ( $manifest['certification_cells'] as $cell ) {
	$expected_cells[ erankly_certification_cell_key( $cell ) ] = $cell;
}
$missing = array_diff_key( $expected_cells, $actual_cells );
$extra   = array_diff_key( $actual_cells, $expected_cells );
if ( $missing || $extra ) {
	fwrite( STDERR, 'Certification matrix mismatch. Missing: ' . implode( ', ', array_keys( $missing ) ) . '; extra: ' . implode( ', ', array_keys( $extra ) ) . ".\n" );
	exit( 1 );
}

$actual_tests = array();
$test_lines   = file( $test_results, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
foreach ( is_array( $test_lines ) ? $test_lines : array() as $line ) {
	$columns = explode( "\t", $line );
	if ( 9 !== count( $columns ) ) {
		fwrite( STDERR, "Malformed per-test certification result row.\n" );
		exit( 2 );
	}
	$test = array_combine( array( 'layer', 'php', 'wordpress', 'database', 'topology', 'test', 'status', 'duration_ms', 'exit_code' ), $columns );
	if ( ! is_array( $test ) || 'pass' !== $test['status'] || 0 !== (int) $test['exit_code'] ) {
		fwrite( STDERR, 'A required certification test did not pass: ' . (string) ( $test['test'] ?? 'unknown' ) . ".\n" );
		exit( 1 );
	}
	$key = erankly_certification_cell_key( $test ) . '|' . (string) $test['test'];
	if ( isset( $actual_tests[ $key ] ) ) {
		fwrite( STDERR, "Duplicate per-test certification result: {$key}.\n" );
		exit( 2 );
	}
	$path = $root . '/' . (string) $test['test'];
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, 'A passing certification result references a missing test: ' . (string) $test['test'] . ".\n" );
		exit( 1 );
	}
	$test['duration_ms'] = max( 0, (int) $test['duration_ms'] );
	$test['exit_code']   = (int) $test['exit_code'];
	$test['sha256']      = hash_file( 'sha256', $path );
	$actual_tests[ $key ] = $test;
}

$expected_tests = array();
foreach ( $manifest['certification_cells'] as $cell ) {
	$suite = '';
	if ( 'standalone' === (string) ( $cell['layer'] ?? '' ) ) {
		$suite = 'required_standalone_tests';
	} elseif ( 'wordpress' === (string) ( $cell['layer'] ?? '' ) ) {
		$suite = 'multisite' === (string) ( $cell['topology'] ?? '' ) ? 'required_multisite_tests' : 'required_wordpress_tests';
	}
	if ( '' === $suite ) {
		continue;
	}
	foreach ( $manifest[ $suite ] as $test_file ) {
		$key                    = erankly_certification_cell_key( $cell ) . '|' . (string) $test_file;
		$expected_tests[ $key ] = true;
	}
}
$missing_tests = array_diff_key( $expected_tests, $actual_tests );
$extra_tests   = array_diff_key( $actual_tests, $expected_tests );
if ( $missing_tests || $extra_tests ) {
	fwrite( STDERR, 'Certification test evidence mismatch. Missing: ' . implode( ', ', array_keys( $missing_tests ) ) . '; extra: ' . implode( ', ', array_keys( $extra_tests ) ) . ".\n" );
	exit( 1 );
}

$pro_evidence = array(
	'status' => 'not_supplied',
	'note'   => 'Licensed paid-plugin binaries are external evidence and are not bundled or simulated.',
);
if ( '' !== $pro ) {
	$decoded = is_file( $pro ) ? json_decode( (string) file_get_contents( $pro ), true ) : null;
	if ( ! is_array( $decoded ) || 'pass' !== ( $decoded['status'] ?? '' ) || empty( $decoded['packages'] ) ) {
		fwrite( STDERR, "The supplied licensed PRO evidence file is invalid or not passing.\n" );
		exit( 1 );
	}
	$expected_paid = array(
		'yoast'    => 'premium',
		'rankmath' => 'pro',
		'aioseo'   => 'pro',
		'seopress' => 'pro',
	);
	$packages = array();
	foreach ( $decoded['packages'] as $package ) {
		$source  = is_array( $package ) ? strtolower( (string) ( $package['source'] ?? '' ) ) : '';
		$edition = is_array( $package ) ? strtolower( (string) ( $package['edition'] ?? '' ) ) : '';
		$version = is_array( $package ) ? (string) ( $package['version'] ?? '' ) : '';
		$sha256  = is_array( $package ) ? strtolower( (string) ( $package['sha256'] ?? '' ) ) : '';
		$status   = is_array( $package ) ? strtolower( (string) ( $package['status'] ?? '' ) ) : '';
		if ( ! isset( $expected_paid[ $source ] ) || isset( $packages[ $source ] ) || $expected_paid[ $source ] !== $edition || '' === trim( $version ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || 'pass' !== $status ) {
			fwrite( STDERR, "Licensed PRO evidence must contain one passing versioned SHA-256 record per paid source.\n" );
			exit( 1 );
		}
		$packages[ $source ] = array(
			'source'  => $source,
			'edition' => $edition,
			'version' => trim( $version ),
			'sha256'  => $sha256,
			'status'  => 'pass',
		);
	}
	if ( array_keys( $expected_paid ) !== array_keys( $packages ) ) {
		fwrite( STDERR, "Licensed PRO evidence is incomplete.\n" );
		exit( 1 );
	}
	$pro_evidence = array(
		'status'                 => 'pass',
		'evidence_record_sha256' => hash_file( 'sha256', $pro ),
		'packages'               => array_values( $packages ),
	);
}

$fixture_dir = $root . '/tests/fixtures/migrations/';
$fixtures    = array();
foreach ( $manifest['fixture_hashes'] as $filename => $expected_hash ) {
	$actual_hash = hash_file( 'sha256', $fixture_dir . $filename );
	if ( ! is_string( $actual_hash ) || ! hash_equals( $expected_hash, $actual_hash ) ) {
		fwrite( STDERR, "Fixture integrity failed while writing the record: {$filename}.\n" );
		exit( 1 );
	}
	$fixtures[ $filename ] = $actual_hash;
}

$record = array(
	'schema_version'       => 1,
	'generated_at_utc'     => gmdate( 'c' ),
	'certification_status' => 'pass',
	'certification_scope'  => 'migration_data_contract_and_easyrankly_runtime',
	'plugin_version'       => (string) $manifest['plugin_version'],
	'workspace_sha256'     => erankly_certification_workspace_hash( $root ),
	'git'                  => array(
		'commit'         => false !== getenv( 'ERANKLY_CERT_GIT_COMMIT' ) ? trim( (string) getenv( 'ERANKLY_CERT_GIT_COMMIT' ) ) : erankly_certification_git( array( 'rev-parse', 'HEAD' ), $root ),
		'worktree_dirty' => false !== getenv( 'ERANKLY_CERT_GIT_DIRTY' ) ? '1' === getenv( 'ERANKLY_CERT_GIT_DIRTY' ) : '' !== erankly_certification_git( array( 'status', '--porcelain' ), $root ),
	),
	'manifest_sha256'      => hash_file( 'sha256', __DIR__ . '/manifest.php' ),
	'fixture_sha256'       => $fixtures,
	'matrix'               => array_values( $actual_cells ),
	'tests'                => array_values( $actual_tests ),
	'sources'              => $manifest['sources'],
	'licensed_pro_evidence'=> $pro_evidence,
);

$directory = dirname( $output );
if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
	fwrite( STDERR, "The certification artifact directory could not be created.\n" );
	exit( 1 );
}
$json = json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( false === $json || false === file_put_contents( $output, $json . "\n", LOCK_EX ) ) {
	fwrite( STDERR, "The certification record could not be written.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Migration certification record written: {$output}\n" );
