<?php
/**
 * Dependency-free regression tests for the atomic AI rate limiter.
 *
 * Run: php tests/security-ai-rate-limit.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress stubs.

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'easyrankly_test' );

final class WP_Error {
	private string $code;
	private array $data;

	public function __construct( string $code, string $message = '', array $data = array() ) {
		unset( $message );
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

final class wpdb {
	public string $base_prefix = 'wp_';
	public int $lock_result = 1;
	public int $acquire_calls = 0;
	public int $release_calls = 0;

	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	public function get_var( string $query ): int {
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			++$this->release_calls;
			return 1;
		}

		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			++$this->acquire_calls;
			return $this->lock_result;
		}

		return 0;
	}
}

$GLOBALS['wpdb']                         = new wpdb();
$GLOBALS['erankly_ai_test_user_id']      = 7;
$GLOBALS['erankly_ai_test_blog_id']      = 3;
$GLOBALS['erankly_ai_test_multisite']    = true;
$GLOBALS['erankly_ai_test_transients']   = array();
$GLOBALS['erankly_ai_test_store_fails']  = false;
$GLOBALS['erankly_ai_test_rate_config']  = array( 'window' => 60, 'max' => 2 );
$GLOBALS['erankly_ai_test_failures']     = array();

function __( string $message, string $domain = '' ): string {
	unset( $domain );
	return $message;
}

function get_current_user_id(): int {
	return $GLOBALS['erankly_ai_test_user_id'];
}

function sanitize_key( string $value ): string {
	return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $value ) );
}

function apply_filters( string $hook, $value, ...$args ) {
	unset( $args );
	return 'erankly_ai_rate_limit' === $hook ? $GLOBALS['erankly_ai_test_rate_config'] : $value;
}

function is_multisite(): bool {
	return $GLOBALS['erankly_ai_test_multisite'];
}

function get_current_blog_id(): int {
	return $GLOBALS['erankly_ai_test_blog_id'];
}

function get_transient( string $key ) {
	return $GLOBALS['erankly_ai_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, $value, int $expiration ): bool {
	unset( $expiration );
	if ( $GLOBALS['erankly_ai_test_store_fails'] ) {
		return false;
	}

	$GLOBALS['erankly_ai_test_transients'][ $key ] = $value;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/ai.php';

function erankly_ai_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		$GLOBALS['erankly_ai_test_failures'][] = $message;
	}
}

function erankly_ai_test_reset(): void {
	$GLOBALS['wpdb']->base_prefix          = 'wp_';
	$GLOBALS['wpdb']->lock_result          = 1;
	$GLOBALS['wpdb']->acquire_calls        = 0;
	$GLOBALS['wpdb']->release_calls        = 0;
	$GLOBALS['erankly_ai_test_user_id']    = 7;
	$GLOBALS['erankly_ai_test_transients'] = array();
	$GLOBALS['erankly_ai_test_store_fails'] = false;
	$GLOBALS['erankly_ai_test_rate_config'] = array( 'window' => 60, 'max' => 2 );
}

erankly_ai_test_reset();
$first_install_lock = erankly_ai_rate_limit_lock_name( 'counter' );
$GLOBALS['wpdb']->base_prefix = 'second_';
$second_install_lock = erankly_ai_rate_limit_lock_name( 'counter' );
erankly_ai_test_assert( $first_install_lock !== $second_install_lock, 'Separate WordPress installations on one database server must not share advisory-lock names.' );
erankly_ai_test_assert( strlen( $first_install_lock ) <= 64 && strlen( $second_install_lock ) <= 64, 'Advisory-lock names must stay within the MySQL/MariaDB limit.' );

erankly_ai_test_reset();
$first  = erankly_ai_consume_rate_limit( 'generate' );
$second = erankly_ai_consume_rate_limit( 'generate' );
$third  = erankly_ai_consume_rate_limit( 'generate' );
erankly_ai_test_assert( true === $first && true === $second, 'Requests below the limit must pass.' );
erankly_ai_test_assert( $third instanceof WP_Error && 'erankly_ai_rate_limited' === $third->get_error_code(), 'The next request must be rejected.' );
erankly_ai_test_assert( 3 === $GLOBALS['wpdb']->acquire_calls, 'Every counted request must acquire the database lock.' );
erankly_ai_test_assert( 3 === $GLOBALS['wpdb']->release_calls, 'Every acquired lock must be released, including rejected requests.' );
erankly_ai_test_assert( 2 === (int) reset( $GLOBALS['erankly_ai_test_transients'] ), 'A rejected request must not increment the counter.' );

erankly_ai_test_reset();
$GLOBALS['wpdb']->lock_result = 0;
$busy = erankly_ai_consume_rate_limit( 'generate' );
erankly_ai_test_assert( $busy instanceof WP_Error && 'erankly_ai_rate_busy' === $busy->get_error_code(), 'Lock contention must fail closed.' );
erankly_ai_test_assert( array() === $GLOBALS['erankly_ai_test_transients'], 'A request without the lock must not touch the counter.' );
erankly_ai_test_assert( 0 === $GLOBALS['wpdb']->release_calls, 'An unacquired lock must not be released.' );

erankly_ai_test_reset();
$GLOBALS['erankly_ai_test_store_fails'] = true;
$storage = erankly_ai_consume_rate_limit( 'generate' );
erankly_ai_test_assert( $storage instanceof WP_Error && 'erankly_ai_rate_storage' === $storage->get_error_code(), 'Counter storage failure must fail closed.' );
erankly_ai_test_assert( 1 === $GLOBALS['wpdb']->release_calls, 'Storage failure must still release the lock.' );

erankly_ai_test_reset();
$GLOBALS['erankly_ai_test_rate_config'] = array( 'window' => 60, 'max' => 0 );
erankly_ai_test_assert( true === erankly_ai_consume_rate_limit( 'generate' ), 'An explicitly disabled limit must remain supported.' );
erankly_ai_test_assert( 0 === $GLOBALS['wpdb']->acquire_calls, 'A disabled limit must not acquire a lock.' );

erankly_ai_test_reset();
$GLOBALS['erankly_ai_test_user_id'] = 0;
$unauthenticated = erankly_ai_consume_rate_limit( 'generate' );
erankly_ai_test_assert( $unauthenticated instanceof WP_Error && 'erankly_ai_rate_unauthenticated' === $unauthenticated->get_error_code(), 'Anonymous callers must remain blocked.' );

$pot = file_get_contents( dirname( __DIR__ ) . '/languages/easyrankly.pot' );
erankly_ai_test_assert( false !== $pot, 'The EasyRankly POT catalog must be readable.' );

$pot_entries = preg_split( '/\R{2,}/', trim( (string) $pot ) );
foreach ( false === $pot_entries ? array() : $pot_entries as $entry ) {
	erankly_ai_test_assert( 1 === preg_match_all( '/^msgid\s+/m', $entry ), 'Every POT entry must contain exactly one msgid directive.' );
	erankly_ai_test_assert( preg_match_all( '/^msgstr(?:\[\d+\])?\s+/m', $entry ) >= 1, 'Every POT entry must contain at least one msgstr directive.' );

	foreach ( preg_split( '/\R/', $entry ) ?: array() as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
			continue;
		}

		if ( preg_match( '/^(?:msgid|msgid_plural|msgstr(?:\[\d+\])?|msgctxt)\s+(".*")$/', $line, $matches ) ) {
			$quoted = $matches[1];
		} elseif ( preg_match( '/^".*"$/', $line ) ) {
			$quoted = $line;
		} else {
			erankly_ai_test_assert( false, "Invalid POT directive: {$line}" );
			continue;
		}

		json_decode( $quoted );
		erankly_ai_test_assert( JSON_ERROR_NONE === json_last_error(), "Invalid quoted POT string: {$line}" );
	}
}

foreach (
	array(
		'Another AI request is being checked. Please retry in a moment.',
		'The AI usage limit could not be recorded. Please try again later.',
	) as $message
) {
	$count = preg_match_all( '/^msgid "' . preg_quote( $message, '/' ) . '"$/m', (string) $pot );
	erankly_ai_test_assert( 1 === $count, "The POT catalog must contain exactly one entry for: {$message}" );
}

if ( ! empty( $GLOBALS['erankly_ai_test_failures'] ) ) {
	foreach ( $GLOBALS['erankly_ai_test_failures'] as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "Atomic AI rate-limit security contract passed.\n" );
