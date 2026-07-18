<?php
/**
 * Redirect helpers shared by migration UI and background workers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads redirect class files on demand even when the module is disabled.
 *
 * @return void
 */
function erankly_ensure_redirect_classes_available(): void {
	$base = ERANKLY_PATH . 'includes/redirects/';
	require_once ERANKLY_PATH . 'includes/helpers/redirect-cache.php';

	$files = array(
		'class-erankly-redirects-normalizer.php',
		'class-erankly-redirects-activator.php',
		'class-erankly-redirects-repository.php',
	);

	foreach ( $files as $file ) {
		if ( file_exists( $base . $file ) ) {
			require_once $base . $file;
		}
	}
}

/**
 * Normalizes an exported redirect row into repository-ready data.
 *
 * @param array<string,mixed> $row Redirect row from the export file.
 * @return array<string,mixed>|null
 */
function erankly_import_prepare_redirect( array $row ): ?array {
	$match_type     = isset( $row['match_type'] ) ? sanitize_key( (string) $row['match_type'] ) : '';
	$match_type     = in_array( $match_type, ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true ) ? $match_type : ( ! empty( $row['is_wildcard'] ) ? 'wildcard' : ( ! empty( $row['is_regex'] ) ? 'regex' : 'exact' ) );
	$is_wildcard    = 'wildcard' === $match_type ? 1 : 0;
	$is_regex       = 'regex' === $match_type ? 1 : 0;
	$case_sensitive = ! empty( $row['case_sensitive'] ) ? 1 : 0;
	$trailing_slash = isset( $row['trailing_slash'] ) && in_array( $row['trailing_slash'], ERankly_Redirects_Normalizer::VALID_TRAILING_SLASH_MODES, true ) ? (string) $row['trailing_slash'] : 'ignore';
	$query_mode     = isset( $row['query_mode'] ) && in_array( $row['query_mode'], ERankly_Redirects_Normalizer::VALID_QUERY_MODES, true ) ? (string) $row['query_mode'] : 'ignore';

	$source_path = isset( $row['source_path'] )
		? ERankly_Redirects_Normalizer::normalize_source( sanitize_text_field( (string) $row['source_path'] ), (bool) $is_regex, (bool) $is_wildcard, (bool) $case_sensitive, $trailing_slash )
		: '';

	$status_code = isset( $row['status_code'] ) ? absint( $row['status_code'] ) : 301;
	if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
		$status_code = 301;
	}

	$is_status_only = ERankly_Redirects_Normalizer::is_status_only_code( $status_code );
	$target_url     = isset( $row['target_url'] )
		? ERankly_Redirects_Normalizer::normalize_target_url( (string) $row['target_url'] )
		: '';

	if ( '' === $source_path || ( ! $is_status_only && '' === $target_url ) ) {
		return null;
	}

	$visibility = isset( $row['visibility'] ) ? sanitize_key( (string) $row['visibility'] ) : 'all';
	if ( ! in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ) {
		$visibility = 'all';
	}

	return array(
		'source_path'      => $source_path,
		'source_hash'      => ERankly_Redirects_Normalizer::source_hash( $source_path ),
		'source_query'     => 'exact' === $query_mode ? sanitize_text_field( (string) ( $row['source_query'] ?? '' ) ) : '',
		'target_url'       => $target_url,
		'status_code'      => $status_code,
		'match_type'       => $match_type,
		'is_regex'         => $is_regex,
		'is_wildcard'      => $is_wildcard,
		'case_sensitive'   => $case_sensitive,
		'trailing_slash'   => $trailing_slash,
		'query_mode'       => $query_mode,
		'priority'         => isset( $row['priority'] ) ? intval( $row['priority'] ) : 10,
		'is_active'        => ! empty( $row['is_active'] ) ? 1 : 0,
		'visibility'       => $visibility,
		'required_role'    => isset( $row['required_role'] ) ? sanitize_key( (string) $row['required_role'] ) : '',
		'conditions'       => isset( $row['conditions'] ) ? ( is_string( $row['conditions'] ) ? $row['conditions'] : wp_json_encode( $row['conditions'] ) ) : null,
		'start_at'         => ! empty( $row['start_at'] ) ? sanitize_text_field( (string) $row['start_at'] ) : null,
		'end_at'           => ! empty( $row['end_at'] ) ? sanitize_text_field( (string) $row['end_at'] ) : null,
		'source_plugin'    => isset( $row['source_plugin'] ) ? sanitize_key( (string) $row['source_plugin'] ) : '',
		'source_reference' => isset( $row['source_reference'] ) ? sanitize_text_field( (string) $row['source_reference'] ) : '',
		'migration_id'     => isset( $row['migration_id'] ) ? sanitize_text_field( (string) $row['migration_id'] ) : '',
		'note'             => isset( $row['note'] ) ? sanitize_textarea_field( (string) $row['note'] ) : '',
	);
}
