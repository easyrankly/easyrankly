<?php
/**
 * Dependency-free smoke tests for the Phase 1 redirect target model.
 *
 * Run: php tests/phase1-smoke.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress function stubs.

define( 'ABSPATH', __DIR__ . '/' );

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_unslash( string $value ): string {
	return stripslashes( $value );
}

function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

function home_url(): string {
	return 'https://example.com';
}

function wp_http_validate_url( string $url ): bool {
	return false !== filter_var( $url, FILTER_VALIDATE_URL );
}

function wp_json_encode( $value ): string {
	return (string) json_encode( $value, JSON_UNESCAPED_SLASHES );
}

require_once dirname( __DIR__ ) . '/includes/redirects/class-erankly-redirects-normalizer.php';

function erankly_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

erankly_test_assert( '/mixed/path' === ERankly_Redirects_Normalizer::normalize_path( '/Mixed/Path/?x=1' ), 'legacy path normalization' );
erankly_test_assert( '/Mixed/Path/' === ERankly_Redirects_Normalizer::normalize_match_path( '/Mixed/Path/?x=1', true, 'exact' ), 'case and trailing slash preservation' );
erankly_test_assert( 'a=1&b=2' === ERankly_Redirects_Normalizer::extract_query( '/old?a=1&b=2' ), 'query extraction' );
erankly_test_assert( in_array( 307, ERankly_Redirects_Normalizer::VALID_STATUS_CODES, true ), '307 support' );
erankly_test_assert( in_array( 308, ERankly_Redirects_Normalizer::VALID_STATUS_CODES, true ), '308 support' );
erankly_test_assert( ERankly_Redirects_Normalizer::is_status_only_code( 410 ), '410 status-only support' );
erankly_test_assert( '/new?x=1#part' === ERankly_Redirects_Normalizer::preserve_query( '/new#part', 'x=1' ), 'query preservation before fragment' );
erankly_test_assert( 1 === preg_match( ERankly_Redirects_Normalizer::build_wildcard_pattern( '/old/*' ), '/OLD/page' ), 'case-insensitive wildcard matching' );
erankly_test_assert( 0 === preg_match( ERankly_Redirects_Normalizer::build_wildcard_pattern( '/old/*', true ), '/OLD/page' ), 'case-sensitive wildcard matching' );
erankly_test_assert( '/new/one/two' === ERankly_Redirects_Normalizer::apply_wildcard_target( '/old/*/*', '/old/one/two', '/new/*/*' ), 'wildcard back-references' );
erankly_test_assert( '/new/42' === ERankly_Redirects_Normalizer::apply_regex_target( '^/old/(\d+)$', '/old/42', '/new/$1' ), 'regex back-references' );

$base = array(
	'match_type'     => 'exact',
	'source_path'    => '/old',
	'query_mode'     => 'ignore',
	'trailing_slash' => 'ignore',
	'visibility'     => 'all',
);

erankly_test_assert(
	ERankly_Redirects_Normalizer::rule_hash( $base ) !== ERankly_Redirects_Normalizer::rule_hash( array_merge( $base, array( 'match_type' => 'contains' ) ) ),
	'match type participates in identity'
);
erankly_test_assert(
	ERankly_Redirects_Normalizer::rule_hash( $base ) !== ERankly_Redirects_Normalizer::rule_hash( array_merge( $base, array( 'conditions' => array( 'language' => 'it' ) ) ) ),
	'conditions participate in identity'
);
erankly_test_assert(
	ERankly_Redirects_Normalizer::rule_hash( $base ) === ERankly_Redirects_Normalizer::rule_hash( array_merge( $base, array( 'target_url' => '/changed', 'priority' => 1, 'source_plugin' => 'yoast' ) ) ),
	'target, priority and provenance do not split one source rule into duplicates'
);

fwrite( STDOUT, "Phase 1 smoke tests passed.\n" );
