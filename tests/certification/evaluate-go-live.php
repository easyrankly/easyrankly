<?php
// phpcs:ignoreFile -- CLI gate writer intentionally reads local evidence and writes a release artifact.
/**
 * Evaluates the strict Phase 8 release gate.
 *
 * @package EasyRankly
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "The release gate may only run from CLI.\n" );
	exit( 2 );
}

$options       = getopt( '', array( 'certification:', 'output:', 'allow-blocked' ) );
$certification = isset( $options['certification'] ) ? (string) $options['certification'] : '';
$output        = isset( $options['output'] ) ? (string) $options['output'] : '';
$allow_blocked = array_key_exists( 'allow-blocked', $options );
$root          = dirname( __DIR__, 2 );

if ( '' === $certification || '' === $output || ! is_file( $certification ) ) {
	fwrite( STDERR, "Usage: php evaluate-go-live.php --certification=<json> --output=<json> [--allow-blocked]\n" );
	exit( 2 );
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/class-erankly-release-go-live-gate.php';

$record   = json_decode( (string) file_get_contents( $certification ), true );
$manifest = require __DIR__ . '/manifest.php';
$plugin   = (string) file_get_contents( $root . '/easyrankly.php' );
$matches  = array();
preg_match( "/define\( 'ERANKLY_VERSION', '([^']+)' \);/", $plugin, $matches );
if ( ! is_array( $record ) || empty( $matches[1] ) ) {
	fwrite( STDERR, "The certification record or plugin version is invalid.\n" );
	exit( 2 );
}

$gate_git_commit = getenv( 'ERANKLY_GATE_GIT_COMMIT' );
$gate_git_dirty  = getenv( 'ERANKLY_GATE_GIT_DIRTY' );
$current         = array(
	'plugin_version'   => (string) $matches[1],
	'workspace_sha256' => erankly_certification_workspace_hash( $root ),
	'git_commit'       => false !== $gate_git_commit ? trim( (string) $gate_git_commit ) : erankly_certification_git( array( 'rev-parse', 'HEAD' ), $root ),
	'worktree_dirty'   => false !== $gate_git_dirty ? '1' === $gate_git_dirty : '' !== erankly_certification_git( array( 'status', '--porcelain' ), $root ),
	'now'              => time(),
);
$decision = ( new ERankly_Release_Go_Live_Gate() )->evaluate( $record, $manifest, $current, hash_file( 'sha256', $certification ) );
$directory = dirname( $output );
if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
	fwrite( STDERR, "The release-gate artifact directory could not be created.\n" );
	exit( 2 );
}
$json = json_encode( $decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( false === $json || false === file_put_contents( $output, $json . "\n", LOCK_EX ) ) {
	fwrite( STDERR, "The release-gate artifact could not be written.\n" );
	exit( 2 );
}

$state = strtoupper( (string) $decision['state'] );
fwrite( STDOUT, "Phase 8 release gate: {$state}. Artifact: {$output}\n" );
if ( empty( $decision['go_live'] ) && ! $allow_blocked ) {
	fwrite( STDERR, 'GO-LIVE blocked by: ' . implode( ', ', $decision['blockers'] ?? array() ) . ".\n" );
	exit( 1 );
}
