<?php
/** Redirect helpers shared by migration UI and background workers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * @param array<string,mixed> $row Redirect row from the export file.
 * @return array<string,mixed>|null
 */
function erankly_import_prepare_redirect( array $row ): ?array {
	$match_type     = isset( $row['match_type'] ) ? sanitize_key( (string) $row['match_type'] ) : '';
	$match_type     = '' !== $match_type ? $match_type : ( ! empty( $row['is_wildcard'] ) ? 'wildcard' : ( ! empty( $row['is_regex'] ) ? 'regex' : 'exact' ) );
	if ( in_array( $match_type, array( 'contains', 'starts_with', 'ends_with' ), true ) ) {
		$source  = sanitize_text_field( (string) ( $row['source_path'] ?? '' ) );
		$literal = preg_quote( ERankly_Redirects_Normalizer::normalize_path( $source ), '#' );
		if ( 'starts_with' === $match_type ) {
			$literal = '^' . $literal;
		} elseif ( 'ends_with' === $match_type ) {
			$literal .= '$';
		}
		$row['source_path'] = $literal;
		$row['note']        = trim( (string) ( $row['note'] ?? '' ) . ' ' . __( '[Matching mode converted to a safe regular expression during import.]', 'easyrankly' ) );
		$match_type         = 'regex';
	}
	if ( ! in_array( $match_type, ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true ) || '' !== erankly_import_redirect_unsupported_reason( $row ) ) {
		return null;
	}
	$is_wildcard    = 'wildcard' === $match_type;
	$is_regex       = 'regex' === $match_type;
	$case_sensitive = ! empty( $row['case_sensitive'] ) ? 1 : 0;
	$trailing_slash = isset( $row['trailing_slash'] ) && in_array( $row['trailing_slash'], ERankly_Redirects_Normalizer::VALID_TRAILING_SLASH_MODES, true ) ? (string) $row['trailing_slash'] : 'ignore';
	$query_mode     = isset( $row['query_mode'] ) && in_array( $row['query_mode'], ERankly_Redirects_Normalizer::VALID_QUERY_MODES, true ) ? (string) $row['query_mode'] : 'ignore';

	$source_path = isset( $row['source_path'] )
		? ERankly_Redirects_Normalizer::normalize_source( sanitize_text_field( (string) $row['source_path'] ), (bool) $is_regex, (bool) $is_wildcard, (bool) $case_sensitive, $trailing_slash )
		: '';

	$status_code = isset( $row['status_code'] ) ? absint( $row['status_code'] ) : 301;
	if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
		return null;
	}

	$is_status_only = ERankly_Redirects_Normalizer::is_status_only_code( $status_code );
	$target_url     = isset( $row['target_url'] )
		? ERankly_Redirects_Normalizer::normalize_target_url( (string) $row['target_url'] )
		: '';

	if ( '' === $source_path || ( ! $is_status_only && '' === $target_url ) ) {
		return null;
	}

	// Same gates as the Redirects admin save path: reject catastrophic regex,
	// malformed wildcards and non-path exact sources before they reach runtime.
	if ( strlen( $source_path ) > 512 ) {
		return null;
	}

	if ( $is_wildcard && ! ERankly_Redirects_Normalizer::is_valid_wildcard_source( $source_path ) ) {
		return null;
	}

	if ( $is_regex && ! ERankly_Redirects_Normalizer::is_valid_regex( $source_path ) ) {
		return null;
	}

	if ( ! $is_regex && ! $is_wildcard && ! ERankly_Redirects_Normalizer::is_valid_internal_path( $source_path ) ) {
		return null;
	}

	return array(
		'source_path'      => $source_path,
		'source_hash'      => ERankly_Redirects_Normalizer::source_hash( $source_path ),
		'source_query'     => 'exact' === $query_mode ? sanitize_text_field( (string) ( $row['source_query'] ?? '' ) ) : '',
		'target_url'       => $target_url,
		'status_code'      => $status_code,
		'match_type'       => $match_type,
		'case_sensitive'   => $case_sensitive,
		'trailing_slash'   => $trailing_slash,
		'query_mode'       => $query_mode,
		'is_active'        => array_key_exists( 'is_active', $row ) ? ( ! empty( $row['is_active'] ) ? 1 : 0 ) : 1,
		'source_plugin'    => isset( $row['source_plugin'] ) ? sanitize_key( (string) $row['source_plugin'] ) : '',
		'source_reference' => isset( $row['source_reference'] ) ? sanitize_text_field( (string) $row['source_reference'] ) : '',
		'migration_id'     => isset( $row['migration_id'] ) ? sanitize_text_field( (string) $row['migration_id'] ) : '',
		'note'             => isset( $row['note'] ) ? sanitize_textarea_field( (string) $row['note'] ) : '',
	);
}

/** Return why a redirect cannot be safely broadened into the canonical model. */
function erankly_import_redirect_unsupported_reason( array $row ): string {
	if ( isset( $row['visibility'] ) && ! in_array( (string) $row['visibility'], array( '', 'all' ), true ) ) {
		return 'audience';
	}
	$conditions = $row['conditions'] ?? array();
	if ( is_string( $conditions ) ) {
		$decoded    = json_decode( $conditions, true );
		$conditions = is_array( $decoded ) ? $decoded : trim( $conditions );
	}
	if ( ! empty( $row['required_role'] ) || ! empty( $conditions ) ) {
		return 'condition';
	}
	if ( ! empty( $row['start_at'] ) || ! empty( $row['end_at'] ) ) {
		return 'schedule';
	}

	return '';
}
