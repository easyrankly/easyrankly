<?php
/**
 * Dependency-free privacy regressions for Health 404 path storage.
 *
 * Run: php tests/security-health-privacy.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress stubs.

define( 'ABSPATH', __DIR__ . '/' );
define( 'ERANKLY_HEALTH_PATH_MAX_LENGTH', 255 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ERANKLY_HEALTH_404_RETENTION_DAYS', 30 );
define( 'ERANKLY_HEALTH_404_THRESHOLD', 10 );
define( 'ERANKLY_HEALTH_404_WINDOW', DAY_IN_SECONDS );
define( 'ERANKLY_HEALTH_404_MAX_CANDIDATES', 200 );
define( 'ERANKLY_HEALTH_404_MAX_FREQUENT', 100 );
define( 'ERANKLY_HEALTH_404_MAX_REFERRERS', 5 );
define( 'ERANKLY_HEALTH_404_CANDIDATES_OPTION', 'erankly_health_404_candidates' );
define( 'ERANKLY_HEALTH_404_FREQUENT_OPTION', 'erankly_health_404_frequent' );
define( 'ERANKLY_HEALTH_404_STATES_OPTION', 'erankly_health_404_states' );
define( 'ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION', 'erankly_health_404_storage_version' );
define( 'ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION', 'erankly_health_404_storage_lock' );
define( 'ERANKLY_HEALTH_404_STORAGE_VERSION', 1 );
define( 'ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION', 'erankly_health_ai_suggestions' );

$GLOBALS['erankly_health_privacy_options'] = array();
$GLOBALS['erankly_health_privacy_failures'] = array();
$GLOBALS['erankly_health_privacy_update_failures'] = array();
$GLOBALS['erankly_health_privacy_false_after_write'] = array();

final class WP_Post {
	public int $ID = 11;
	public string $post_status = 'publish';
	public string $post_type = 'post';
}

function get_user_by( string $field, string $value ) {
	unset( $field );
	return 'known-user' === $value ? (object) array( 'ID' => 7 ) : false;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_text_field( $value ): string {
	$value = trim( strip_tags( (string) $value ) );

	return (string) preg_replace( '/%[a-f0-9]{2}/i', '', $value );
}

function wp_strip_all_tags( string $value, bool $remove_breaks = false ): string {
	$value = strip_tags( $value );

	return $remove_breaks ? (string) preg_replace( '/[\r\n\t ]+/', ' ', $value ) : $value;
}

function wp_unslash( $value ): string {
	return stripslashes( (string) $value );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( string $path = '' ): string {
	return 'https://example.com/' . ltrim( $path, '/' );
}

function url_to_postid( string $url ): int {
	return 'https://example.com/safe-page' === $url ? 11 : 0;
}

function get_post( int $post_id ) {
	return 11 === $post_id ? new WP_Post() : null;
}

function erankly_get_public_post_types(): array {
	return array( 'post' => 'post' );
}

function get_permalink( int $post_id ): string {
	return 11 === $post_id ? 'https://example.com/safe-page' : '';
}

function wp_make_link_relative( string $url ): string {
	$path = parse_url( $url, PHP_URL_PATH );
	return is_string( $path ) ? $path : '';
}

function apply_filters( string $hook, $value, ...$args ) {
	unset( $args );
	if ( 'erankly_health_404_sample_rate' === $hook ) {
		return 1;
	}
	return $value;
}

function wp_rand( int $min, int $max ): int {
	unset( $max );
	return $min;
}

function get_option( string $option, $default = false ) {
	return $GLOBALS['erankly_health_privacy_options'][ $option ] ?? $default;
}

function update_option( string $option, $value, bool $autoload = false ): bool {
	unset( $autoload );
	if ( ! empty( $GLOBALS['erankly_health_privacy_update_failures'][ $option ] ) ) {
		--$GLOBALS['erankly_health_privacy_update_failures'][ $option ];
		return false;
	}
	$GLOBALS['erankly_health_privacy_options'][ $option ] = $value;
	if ( ! empty( $GLOBALS['erankly_health_privacy_false_after_write'][ $option ] ) ) {
		--$GLOBALS['erankly_health_privacy_false_after_write'][ $option ];
		return false;
	}
	return true;
}

function add_option( string $option, $value = '', string $deprecated = '', $autoload = 'yes' ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $option, $GLOBALS['erankly_health_privacy_options'] ) ) {
		return false;
	}
	$GLOBALS['erankly_health_privacy_options'][ $option ] = $value;
	return true;
}

function delete_option( string $option ): bool {
	if ( ! array_key_exists( $option, $GLOBALS['erankly_health_privacy_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['erankly_health_privacy_options'][ $option ] );
	return true;
}

require_once dirname( __DIR__ ) . '/includes/health/404-monitor.php';
require_once dirname( __DIR__ ) . '/includes/health/suggestions.php';

function erankly_health_privacy_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		$GLOBALS['erankly_health_privacy_failures'][] = $message;
	}
}

function erankly_health_privacy_reset_storage(): void {
	$GLOBALS['erankly_health_privacy_options'] = array(
		ERANKLY_HEALTH_404_CANDIDATES_OPTION => array(),
		ERANKLY_HEALTH_404_FREQUENT_OPTION   => array(),
		ERANKLY_HEALTH_404_STATES_OPTION     => array(),
		ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION => array(),
	);
	$GLOBALS['erankly_health_privacy_update_failures'] = array();
	$GLOBALS['erankly_health_privacy_false_after_write'] = array();
}

/**
 * Builds a historical aggregate entry exactly as older storage contains it.
 *
 * @param string            $path      Raw stored path.
 * @param int               $count     Aggregate count.
 * @param int               $timestamp Observation timestamp.
 * @param array<string,int> $referrers Raw referrer map.
 * @return array<string,mixed>
 */
function erankly_health_privacy_entry( string $path, int $count, int $timestamp, array $referrers = array() ): array {
	return array(
		'path'         => $path,
		'count'        => $count,
		'window_start' => $timestamp,
		'first_seen'   => $timestamp,
		'last_seen'    => $timestamp,
		'referrers'    => $referrers,
	);
}

$ordinary = '/about/contact-us/how-to-reset-password-securely/wordpress-security-2026';
erankly_health_privacy_assert(
	$ordinary === erankly_health_anonymize_path_segments( $ordinary ),
	'Ordinary human-readable slugs must remain useful for 404 diagnostics.'
);

$sensitive = erankly_health_anonymize_path_segments(
	'/user%40example.com/550e8400-e29b-41d4-a716-446655440000/12345678/known-user'
);
erankly_health_privacy_assert(
	'/[email]/[id]/[n]/[user]' === $sensitive,
	'Known personal identifier shapes must be irreversibly redacted.'
);

$tokens = erankly_health_anonymize_path_segments(
	'/abcdefghijklmnopqrstuvwx/AbCdEfGhIjKlMnOp/abc123def456ghij/aB3dE5fG7hJ9kL2m'
);
erankly_health_privacy_assert(
	'/[token]/[token]/[token]/[token]' === $tokens,
	'Compact reset and base64-like tokens containing vowels must be redacted.'
);

erankly_health_privacy_assert(
	'/reset-token-with-vowels' === erankly_health_anonymize_path_segments( '/reset-token-with-vowels' ),
	'A readable hyphenated slug must not be mistaken for an opaque token.'
);

$token_cases = array(
	'/GhIj12Kl'                    => '/[token]',
	'/GhIj12KlMnOp'                => '/[token]',
	'/abc123-def456'               => '/[token]',
	'/Ab1_cd2E'                    => '/[token]',
	'/Ab1.cd2E'                    => '/[token]',
	'/Ab1~cd2E'                    => '/[token]',
	'/Ab1%25cd2E'                  => '/[token]',
	'/Ab1+cd2E'                    => '/[token]',
	'/Ab1=cd2E'                    => '/[token]',
	'/Ab1Cd2Ef3Gh4Ij5'             => '/[token]',
	'/' . str_repeat( 'Ab1', 13 )  => '/[token]',
	'/' . str_repeat( 'Ab1x', 10 ) => '/[token]',
);

foreach ( $token_cases as $input => $expected ) {
	erankly_health_privacy_assert(
		$expected === erankly_health_sanitize_404_path( $input ),
		"Opaque token path must be anonymized through the storage sanitizer: {$input}"
	);
}

// Boundary behavior: seven characters are below the compact-token floor, while
// 8, 15, 16, 39 and 40-character mixed tokens are privacy-sensitive.
$boundary_cases = array(
	'/Ab1Cd2E'                          => '/Ab1Cd2E',
	'/Ab1Cd2Ef'                         => '/[token]',
	'/Ab1Cd2Ef3Gh4Ij5'                  => '/[token]',
	'/Ab1Cd2Ef3Gh4Ij5K'                 => '/[token]',
	'/' . substr( str_repeat( 'Ab1x', 10 ), 0, 39 ) => '/[token]',
	'/' . str_repeat( 'Ab1x', 10 )      => '/[token]',
);

foreach ( $boundary_cases as $input => $expected ) {
	erankly_health_privacy_assert(
		$expected === erankly_health_sanitize_404_path( $input ),
		"Token boundary must be classified safely: {$input}"
	);
}

foreach ( array( 'reset', 'verify', 'activate', 'invite', 'magic-link', 'token' ) as $route ) {
	erankly_health_privacy_assert(
		"/{$route}/[token]" === erankly_health_sanitize_404_path( "/{$route}/GhIj12Kl" ),
		"A compact value following the sensitive {$route} route must be anonymized."
	);
}

foreach ( array( '/about', '/contact-us', '/reset-token-with-vowels', '/wordpress-security-2026', '/documentation', '/privacy-policy', '/release-notes-2026' ) as $slug ) {
	erankly_health_privacy_assert(
		$slug === erankly_health_sanitize_404_path( $slug ),
		"Readable content slug must remain available for diagnostics: {$slug}"
	);
}

$capped = erankly_health_anonymize_path_segments( '/' . str_repeat( 'readable-', 40 ) );
erankly_health_privacy_assert(
	strlen( $capped ) <= ERANKLY_HEALTH_PATH_MAX_LENGTH,
	'Anonymized paths must remain bounded.'
);

$raw_path        = '/abcdefghijklmnopqrstuvwx';
$second_raw_path = '/AbCdEfGhIjKlMnOp';
$now             = time();
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ] = array(
	md5( $raw_path ) => array(
		'path'         => $raw_path,
		'count'        => 3,
		'window_start' => $now,
		'first_seen'   => $now,
		'last_seen'    => $now,
		'referrers'    => array(),
	),
	md5( $second_raw_path ) => array(
		'path'         => $second_raw_path,
		'count'        => 2,
		'window_start' => $now,
		'first_seen'   => $now,
		'last_seen'    => $now,
		'referrers'    => array(),
	),
);
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_FREQUENT_OPTION ] = array();
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ]   = array(
	md5( $raw_path ) => array(
		'status'     => 'ignored',
		'updated_at' => $now - 10,
	),
	md5( $second_raw_path ) => array(
		'status'     => 'resolved',
		'updated_at' => $now,
	),
);
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION ] = array(
	md5( $raw_path ) => array(
		'path'       => $raw_path,
		'suggestion' => array( 'target' => '/safe-page' ),
		'updated_at' => $now,
	),
);

erankly_health_prune_stale_404_data();
$migrated = $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ];
erankly_health_privacy_assert( isset( $migrated[ md5( '/[token]' ) ] ), 'Historical rows must be re-keyed to their newly anonymized path.' );
erankly_health_privacy_assert( '/[token]' === $migrated[ md5( '/[token]' ) ]['path'], 'Historical raw tokens must be overwritten at rest.' );
erankly_health_privacy_assert( 5 === $migrated[ md5( '/[token]' ) ]['count'], 'Historical rows that collapse to one anonymized path must retain their aggregate count.' );
$migrated_states = $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ];
erankly_health_privacy_assert( ! isset( $migrated_states[ md5( $raw_path ) ], $migrated_states[ md5( $second_raw_path ) ] ), 'Historical state hashes must not remain after path re-keying.' );
erankly_health_privacy_assert(
	isset( $migrated_states[ md5( '/[token]' ) ] ) && 'resolved' === $migrated_states[ md5( '/[token]' ) ]['status'],
	'The newest manual state must migrate to the anonymized hash when historical paths collapse.'
);
erankly_health_privacy_assert(
	array() === $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION ],
	'Persistent AI suggestions containing a newly recognized sensitive path must be removed.'
);

// A real visit can re-save an in-memory normalized frequent entry before the
// cron runs. Its ignored/resolved state must already have moved to the new hash.
foreach ( array( 'ignored', 'resolved' ) as $status ) {
	erankly_health_privacy_reset_storage();
	$historical_path = '/account/AbCdEfGhIjKlMnOp';
	$normalized_path = '/account/[token]';
	$old_hash        = md5( $historical_path );
	$new_hash        = md5( $normalized_path );
	$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_FREQUENT_OPTION ][ $old_hash ] = erankly_health_privacy_entry( $historical_path, 10, $now );
	$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $old_hash ] = array(
		'status'     => $status,
		'updated_at' => $now - 5,
	);

	erankly_health_record_404_path( $historical_path );
	$after_visit = $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_FREQUENT_OPTION ];
	erankly_health_privacy_assert( isset( $after_visit[ $new_hash ] ), "A {$status} historical entry must be re-keyed before a visit is recorded." );

	erankly_health_prune_stale_404_data();
	$after_prune = $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ];
	erankly_health_privacy_assert(
		isset( $after_prune[ $new_hash ] ) && $status === ( $after_prune[ $new_hash ]['status'] ?? '' ),
		"The {$status} state must survive a visit that happens before the retention cron."
	);
	erankly_health_privacy_assert( ! isset( $after_prune[ $old_hash ] ), "The old {$status} state hash must be removed after migration." );

	$snapshot = serialize( $GLOBALS['erankly_health_privacy_options'] );
	erankly_health_prune_stale_404_data();
	erankly_health_privacy_assert( $snapshot === serialize( $GLOBALS['erankly_health_privacy_options'] ), "A second {$status} prune must be idempotent." );
}

// Collisions retain the newest state; on an exact timestamp tie, resolved wins.
foreach (
	array(
		array( 'prefix' => '/reset/', 'first' => array( 'resolved', $now - 20 ), 'second' => array( 'ignored', $now - 10 ), 'expected' => 'ignored' ),
		array( 'prefix' => '/verify/', 'first' => array( 'ignored', $now - 10 ), 'second' => array( 'resolved', $now - 10 ), 'expected' => 'resolved' ),
	) as $collision
) {
	erankly_health_privacy_reset_storage();
	$first_path  = $collision['prefix'] . 'AbCdEfGhIjKlMnOp';
	$second_path = $collision['prefix'] . 'QrStUvWxYz012345';
	$new_hash    = md5( $collision['prefix'] . '[token]' );
	$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ] = array(
		md5( $first_path )  => erankly_health_privacy_entry( $first_path, 2, $now ),
		md5( $second_path ) => erankly_health_privacy_entry( $second_path, 3, $now ),
	);
	$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ] = array(
		md5( $first_path )  => array( 'status' => $collision['first'][0], 'updated_at' => $collision['first'][1] ),
		md5( $second_path ) => array( 'status' => $collision['second'][0], 'updated_at' => $collision['second'][1] ),
	);

	erankly_health_prune_stale_404_data();
	$collision_states = $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ];
	erankly_health_privacy_assert(
		$collision['expected'] === ( $collision_states[ $new_hash ]['status'] ?? '' ),
		"Collision state precedence must select {$collision['expected']}."
	);
}

// A genuine state write failure must leave entries untouched so their old hashes
// still match the old states; a retry must then complete the migration.
erankly_health_privacy_reset_storage();
$failure_path = '/activate/AbCdEfGhIjKlMnOp';
$failure_hash = md5( $failure_path );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $failure_hash ] = erankly_health_privacy_entry( $failure_path, 3, $now );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $failure_hash ] = array( 'status' => 'ignored', 'updated_at' => $now );
$GLOBALS['erankly_health_privacy_update_failures'][ ERANKLY_HEALTH_404_STATES_OPTION ] = 1;
erankly_health_prune_stale_404_data();
erankly_health_privacy_assert(
	isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $failure_hash ] ),
	'A failed state migration must not re-key candidates.'
);
erankly_health_privacy_assert(
	isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $failure_hash ] ),
	'A failed state migration must preserve the old matching state.'
);
erankly_health_prune_stale_404_data();
$failure_new_hash = md5( '/activate/[token]' );
erankly_health_privacy_assert(
	'ignored' === ( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $failure_new_hash ]['status'] ?? '' ),
	'A retry after a persistence failure must migrate the preserved state.'
);

// If states are durable but the following entry write fails, getters must still
// normalize the old entry in memory to the already-migrated state hash.
erankly_health_privacy_reset_storage();
$entry_failure_path = '/verify/QrStUvWxYz012345';
$entry_failure_hash = md5( $entry_failure_path );
$entry_new_hash     = md5( '/verify/[token]' );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $entry_failure_hash ] = erankly_health_privacy_entry( $entry_failure_path, 3, $now );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $entry_failure_hash ] = array( 'status' => 'resolved', 'updated_at' => $now );
$GLOBALS['erankly_health_privacy_update_failures'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ] = 1;
erankly_health_prune_stale_404_data();
$in_memory_entries = erankly_health_get_404_entries( ERANKLY_HEALTH_404_CANDIDATES_OPTION );
$durable_states    = erankly_health_get_404_states();
erankly_health_privacy_assert(
	isset( $in_memory_entries[ $entry_new_hash ], $durable_states[ $entry_new_hash ] ),
	'A failure after state persistence must leave getter hashes compatible.'
);
erankly_health_privacy_assert(
	isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $entry_failure_hash ] )
	&& ! isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION ] ),
	'A failed entry write must retain the old durable entry and leave migration incomplete.'
);
erankly_health_prune_stale_404_data();
erankly_health_privacy_assert(
	isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $entry_new_hash ] )
	&& ERANKLY_HEALTH_404_STORAGE_VERSION === ( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION ] ?? 0 ),
	'A retry must finish an interruption between state and entry persistence.'
);

// A concurrent request that does not own the migration lock must not re-save an
// entry under a new hash. Once the owner releases the lock, the next request can
// complete the same deterministic migration.
erankly_health_privacy_reset_storage();
$concurrent_path = '/invite/AbCdEfGhIjKlMnOp';
$concurrent_hash = md5( $concurrent_path );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $concurrent_hash ] = erankly_health_privacy_entry( $concurrent_path, 3, $now );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $concurrent_hash ] = array( 'status' => 'ignored', 'updated_at' => $now );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION ] = $now;
erankly_health_record_404_path( $concurrent_path );
erankly_health_privacy_assert(
	isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $concurrent_hash ] )
	&& isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $concurrent_hash ] ),
	'A request that does not own the migration lock must leave matching old hashes untouched.'
);
delete_option( ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION );
erankly_health_prune_stale_404_data();
$concurrent_new_hash = md5( '/invite/[token]' );
erankly_health_privacy_assert(
	isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $concurrent_new_hash ] )
	&& 'ignored' === ( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $concurrent_new_hash ]['status'] ?? '' ),
	'The next request must safely complete a migration deferred by a concurrent owner.'
);

// WordPress returns false when update_option() is a no-op. Re-reading an exact
// value must allow migration to continue instead of treating that as a failure.
erankly_health_privacy_reset_storage();
$no_op_path = '/token/AbCdEfGhIjKlMnOp';
$no_op_hash = md5( $no_op_path );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ $no_op_hash ] = erankly_health_privacy_entry( $no_op_path, 3, $now );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_STATES_OPTION ][ $no_op_hash ] = array( 'status' => 'resolved', 'updated_at' => $now );
$GLOBALS['erankly_health_privacy_false_after_write'][ ERANKLY_HEALTH_404_STATES_OPTION ] = 1;
erankly_health_prune_stale_404_data();
erankly_health_privacy_assert(
	isset( $GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ md5( '/token/[token]' ) ] ),
	'An update_option() false result with the exact desired value at rest must count as success.'
);

// Historical short tokens must disappear from every durable 404 surface,
// including nested referrer keys and persistent AI-suggestion records.
erankly_health_privacy_reset_storage();
$at_rest_tokens = array( 'GhIj12Kl', 'abc123-def456', 'Ab1_cd2E', 'Ab1=cd2E' );
$candidate_path = '/reset/' . $at_rest_tokens[0];
$frequent_path  = '/verify/' . $at_rest_tokens[1];
$ai_path        = '/magic-link/' . $at_rest_tokens[3];
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ][ md5( $candidate_path ) ] = erankly_health_privacy_entry(
	$candidate_path,
	2,
	$now,
	array( '/invite/' . $at_rest_tokens[2] => 2 )
);
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_FREQUENT_OPTION ][ md5( $frequent_path ) ] = erankly_health_privacy_entry( $frequent_path, 10, $now );
$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION ][ md5( $ai_path ) ] = array(
	'path'       => $ai_path,
	'suggestion' => array( 'target' => '/safe-page', 'reason_text' => $at_rest_tokens[3] ),
	'updated_at' => $now,
);
erankly_health_prune_stale_404_data();
$serialized_storage = serialize(
	array(
		$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_CANDIDATES_OPTION ],
		$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_404_FREQUENT_OPTION ],
		$GLOBALS['erankly_health_privacy_options'][ ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION ],
	)
);
foreach ( $at_rest_tokens as $token ) {
	erankly_health_privacy_assert( false === strpos( $serialized_storage, $token ), "Historical token must not remain serialized at rest: {$token}" );
}

if ( ! empty( $GLOBALS['erankly_health_privacy_failures'] ) ) {
	foreach ( $GLOBALS['erankly_health_privacy_failures'] as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "Health 404 privacy security contract passed.\n" );
