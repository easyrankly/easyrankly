<?php
// phpcs:ignoreFile -- Standalone provider-handshake test supplies a minimal WordPress shim.
/**
 * EasyRankly 2.1 provider-registry contract.
 *
 * @package EasyRankly
 */

define( 'ABSPATH', __DIR__ );
define( 'ERANKLY_EXTENSION_API_VERSION', 1 );

final class WP_Error {
	private array $data;

	public function __construct( private string $code, private string $message, array $data = array() ) {
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): array {
		return $this->data;
	}

	public function add_data( array $data ): void {
		$this->data = $data;
	}
}

function __( string $message ): string {
	return $message;
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}

function sanitize_text_field( string $value ): string {
	return trim( $value );
}

function esc_url_raw( string $value ): string {
	return $value;
}

function get_current_blog_id(): int {
	return 1;
}

function get_locale(): string {
	return 'en_US';
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function add_action( string $hook, mixed $callback, int $priority = 10 ): void {
	$GLOBALS['m2_actions'][ $hook ][ $priority ][] = $callback;
}

function do_action( string $hook, mixed ...$args ): void {
	$GLOBALS['m2_fired_actions'][] = array( $hook, $args );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $args );

	if ( 'erankly_multilingual_provider_choice' === $hook && '' !== ( $GLOBALS['m2_provider_choice'] ?? '' ) ) {
		return $GLOBALS['m2_provider_choice'];
	}

	return $value;
}

function erankly_ml_bundled_owner_id(): string {
	return 'easyrankly-bundled-multilingual';
}

require dirname( __DIR__, 2 ) . '/includes/class-erankly-multilingual-provider-registry.php';

final class ERankly_M2_Fake_Provider implements ERankly_Multilingual_Provider_Interface {
	public int $boot_count = 0;

	public function __construct(
		private string $id,
		private int $priority = 100,
		private int $api = 1,
		private bool|WP_Error $preflight_result = true,
		private bool $enabled = true
	) {}

	public function get_id(): string {
		return $this->id;
	}

	public function get_version(): string {
		return '1.0.0';
	}

	public function get_api_version(): int {
		return $this->api;
	}

	public function get_priority(): int {
		return $this->priority;
	}

	public function get_topology(): string {
		return 'network';
	}

	public function preflight(): bool|WP_Error {
		return $this->preflight_result;
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}

	public function register_hooks(): void {
		++$this->boot_count;
	}

	public function get_context(): array {
		return array();
	}

	public function get_alternates( array $context, bool $navigable ): array {
		unset( $context, $navigable );

		return array();
	}

	public function localize_url( string $url, array $context ): string {
		unset( $context );

		return $url;
	}
}

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$registry = new ERankly_Multilingual_Provider_Registry();
$assert( null === $registry->close_and_boot(), 'Zero providers must select nothing.' );

$registry = new ERankly_Multilingual_Provider_Registry();
$only     = new ERankly_M2_Fake_Provider( 'easyrankly-multilingual' );
$assert( true === $registry->register( $only ), 'One valid provider must register.' );
$assert( $only === $registry->close_and_boot() && 1 === $only->boot_count, 'One valid provider must boot exactly once.' );
$assert( $only === $registry->close_and_boot() && 1 === $only->boot_count, 'Registry closure must be idempotent.' );

foreach ( array( 'core-first', 'addon-first' ) as $order ) {
	$registry = new ERankly_Multilingual_Provider_Registry();
	$bundled  = new ERankly_M2_Fake_Provider( 'easyrankly-bundled-multilingual', -100 );
	$addon    = new ERankly_M2_Fake_Provider( 'easyrankly-multilingual', 100 );
	$sequence = 'core-first' === $order ? array( $bundled, $addon ) : array( $addon, $bundled );
	foreach ( $sequence as $provider ) {
		$registry->register( $provider );
	}
	$assert( $addon === $registry->close_and_boot(), $order . ' must deterministically select the external provider.' );
	$assert( 1 === $addon->boot_count && 0 === $bundled->boot_count, $order . ' must boot only the external provider.' );
}

foreach ( array( array( 100, 10 ), array( 100, 100 ) ) as $priorities ) {
	$registry = new ERankly_Multilingual_Provider_Registry();
	$first    = new ERankly_M2_Fake_Provider( 'external-first', $priorities[0] );
	$second   = new ERankly_M2_Fake_Provider( 'external-second', $priorities[1] );
	$registry->register( $first );
	$registry->register( $second );
	$assert( null === $registry->close_and_boot(), 'Two external providers must fail closed regardless of priority.' );
	$assert( 0 === $first->boot_count && 0 === $second->boot_count, 'A provider conflict must not boot either candidate.' );
}

$GLOBALS['m2_provider_choice'] = 'external-second';
$registry                      = new ERankly_Multilingual_Provider_Registry();
$first                         = new ERankly_M2_Fake_Provider( 'external-first', 100 );
$second                        = new ERankly_M2_Fake_Provider( 'external-second', 100 );
$registry->register( $first );
$registry->register( $second );
$assert( $second === $registry->close_and_boot(), 'A persisted and verified provider ID must resolve a conflict.' );
$GLOBALS['m2_provider_choice'] = '';

$late = $registry->register( new ERankly_M2_Fake_Provider( 'late-provider' ) );
$assert( is_wp_error( $late ) && 'erankly_provider_registration_closed' === $late->get_error_code(), 'Late registration must return the frozen public error.' );

$registry = new ERankly_Multilingual_Provider_Registry();
$mismatch = $registry->register( new ERankly_M2_Fake_Provider( 'api-two', 100, 2 ) );
$assert( is_wp_error( $mismatch ) && 'erankly_provider_api_mismatch' === $mismatch->get_error_code(), 'An incompatible API major must be rejected.' );

$fallback_error = new WP_Error(
	'candidate_preflight_failed',
	'Candidate preflight failed.',
	array( 'fallback_allowed' => true, 'owner_state' => 'core', 'retryable' => true )
);
$registry       = new ERankly_Multilingual_Provider_Registry();
$bundled        = new ERankly_M2_Fake_Provider( 'easyrankly-bundled-multilingual', -100 );
$candidate      = new ERankly_M2_Fake_Provider( 'easyrankly-multilingual', 100, 1, $fallback_error );
$registry->register( $bundled );
$registry->register( $candidate );
$assert( $bundled === $registry->close_and_boot(), 'Explicit pre-adoption fallback permission must retain the bundle.' );

$claimed_error = new WP_Error(
	'candidate_claimed_failed',
	'Claimed provider failed.',
	array( 'fallback_allowed' => true, 'owner_state' => 'claimed', 'retryable' => false )
);
$registry      = new ERankly_Multilingual_Provider_Registry();
$bundled       = new ERankly_M2_Fake_Provider( 'easyrankly-bundled-multilingual', -100 );
$candidate     = new ERankly_M2_Fake_Provider( 'easyrankly-multilingual', 100, 1, $claimed_error );
$registry->register( $bundled );
$registry->register( $candidate );
$assert( null === $registry->close_and_boot() && 0 === $bundled->boot_count, 'A claimed owner failure must suppress fallback even if an error incorrectly opts in.' );

$registry = new ERankly_Multilingual_Provider_Registry();
$bundled  = new ERankly_M2_Fake_Provider( 'easyrankly-bundled-multilingual', -100 );
$disabled = new ERankly_M2_Fake_Provider( 'easyrankly-multilingual', 100, 1, true, false );
$registry->register( $bundled );
$registry->register( $disabled );
$assert( $disabled === $registry->close_and_boot() && 0 === $bundled->boot_count, 'A disabled selected provider must remain runtime owner without fallback reappearance.' );

$registry = new ERankly_Multilingual_Provider_Registry();
$bundled  = new ERankly_M2_Fake_Provider( 'easyrankly-bundled-multilingual', -100 );
$failed   = new ERankly_M2_Fake_Provider( 'easyrankly-multilingual', 100, 1, false );
$registry->register( $bundled );
$registry->register( $failed );
$assert( null === $registry->close_and_boot() && 0 === $bundled->boot_count, 'A raw false preflight result must fail closed.' );

$assert( ! class_exists( 'ERankly_ML_Repository', false ) && ! class_exists( 'ERankly_ML_Resolver', false ) && ! class_exists( 'ERankly_ML_Admin', false ), 'A fake external provider must not load bundled ERankly_ML runtime classes.' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "EasyRankly Multilingual M2 provider registry passed.\n" );
