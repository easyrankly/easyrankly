<?php
/** EasyRankly public multilingual provider API v1. */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- The v1 public contract is loaded atomically before third-party plugins can register.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Public provider contract frozen for extension API major 1. */
interface ERankly_Multilingual_Provider_Interface {
	/** Returns the stable provider identifier. */
	public function get_id(): string;
	/** Returns the provider release version. */
	public function get_version(): string;
	/** Returns the required EasyRankly extension API major. */
	public function get_api_version(): int;
	/** Returns the provider selection priority. */
	public function get_priority(): int;

	public function get_topology(): string;
	/** Performs a read-only readiness check. */
	public function preflight(): bool|WP_Error;

	public function is_enabled(): bool;

	public function register_hooks(): void;

	public function get_context(): array;
	/** @param bool                $navigable Whether only navigable alternates are requested. */
	public function get_alternates( array $context, bool $navigable ): array;
	/** Localizes one URL for the supplied context. */
	public function localize_url( string $url, array $context ): string;
}

/** Deterministic, fail-closed registry for multilingual providers. */
final class ERankly_Multilingual_Provider_Registry {
	/** @var self|null */
	private static ?self $instance = null;

	/** @var array<string,ERankly_Multilingual_Provider_Interface> */
	private array $providers = array();

	/** @var ERankly_Multilingual_Provider_Interface|null */
	private ?ERankly_Multilingual_Provider_Interface $selected = null;

	private bool $closed = false;

	private bool $booted = false;

	/** @var array<int,array{code:string,message:string,data:array<string,mixed>}> */
	private array $diagnostics = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** @return bool|WP_Error */
	public function register( ERankly_Multilingual_Provider_Interface $provider ): bool|WP_Error {
		if ( $this->closed ) {
			return $this->reject(
				$provider,
				new WP_Error( 'erankly_provider_registration_closed', __( 'The EasyRankly multilingual provider registry is already closed.', 'easyrankly' ), array( 'retryable' => false ) )
			);
		}

		$id = $provider->get_id();
		if ( '' === $id || sanitize_key( $id ) !== $id ) {
			return $this->reject(
				$provider,
				new WP_Error( 'erankly_provider_invalid_id', __( 'The multilingual provider ID is invalid.', 'easyrankly' ), array( 'retryable' => false ) )
			);
		}

		if ( ERANKLY_EXTENSION_API_VERSION !== $provider->get_api_version() ) {
			return $this->reject(
				$provider,
				new WP_Error(
					'erankly_provider_api_mismatch',
					__( 'The multilingual provider requires an incompatible EasyRankly extension API.', 'easyrankly' ),
					array(
						'expected_api' => ERANKLY_EXTENSION_API_VERSION,
						'provider_api' => $provider->get_api_version(),
						'retryable'    => false,
					)
				)
			);
		}

		if ( '' === trim( $provider->get_version() ) ) {
			return $this->reject(
				$provider,
				new WP_Error( 'erankly_provider_invalid_version', __( 'The multilingual provider version is missing.', 'easyrankly' ), array( 'retryable' => false ) )
			);
		}

		if ( isset( $this->providers[ $id ] ) ) {
			return $this->reject(
				$provider,
				new WP_Error( 'erankly_provider_duplicate_id', __( 'A multilingual provider with this ID is already registered.', 'easyrankly' ), array( 'retryable' => false ) )
			);
		}

		$this->providers[ $id ] = $provider;
		do_action( 'erankly_multilingual_provider_registered', $provider );

		return true;
	}

	/**
 * Closes registration, resolves conflicts and boots one provider at most.
 *
 * @return ERankly_Multilingual_Provider_Interface|null
 */
	public function close_and_boot(): ?ERankly_Multilingual_Provider_Interface {
		if ( $this->closed ) {
			return $this->selected;
		}

		$this->closed = true;
		$providers    = $this->providers;
		$candidate    = null;

		if ( count( $providers ) > 1 ) {
			$choice = function_exists( 'erankly_get_plugin_option' )
				? sanitize_key( (string) erankly_get_plugin_option( 'erankly_multilingual_provider_id', '' ) )
				: '';
			$choice = sanitize_key( (string) apply_filters( 'erankly_multilingual_provider_choice', $choice, array_keys( $providers ) ) );

			if ( '' !== $choice && isset( $providers[ $choice ] ) ) {
				$candidate = $providers[ $choice ];
			} else {
				$this->diagnostic(
					'erankly_provider_conflict',
					__( 'More than one multilingual provider is registered. No provider was started.', 'easyrankly' ),
					array(
						'provider_ids' => array_keys( $providers ),
						'priorities'   => array_map( static fn( ERankly_Multilingual_Provider_Interface $provider ): int => $provider->get_priority(), $providers ),
					)
				);

				foreach ( $providers as $provider ) {
					$this->reject( $provider, new WP_Error( 'erankly_provider_conflict', __( 'The multilingual provider conflict must be resolved by an administrator.', 'easyrankly' ), array( 'retryable' => false ) ) );
				}

				return null;
			}
		} elseif ( 1 === count( $providers ) ) {
			$candidate = reset( $providers );
		}

		if ( ! $candidate instanceof ERankly_Multilingual_Provider_Interface ) {
			return null;
		}

		$preflight = $this->run_preflight( $candidate );
		if ( is_wp_error( $preflight ) ) {
			$this->reject( $candidate, $preflight );

			return null;
		}

		$this->selected = $candidate;
		do_action( 'erankly_multilingual_provider_selected', $candidate );

		try {
			$candidate->register_hooks();
			$this->booted = true;
			add_action( 'wp_head', 'erankly_render_hreflang_alternates', 2 );
			do_action( 'erankly_multilingual_provider_booted', $candidate );
		} catch ( Throwable $throwable ) {
			$this->selected = null;
			$this->booted   = false;
			$this->reject(
				$candidate,
				new WP_Error(
					'erankly_provider_boot_failed',
					__( 'The selected multilingual provider could not start.', 'easyrankly' ),
					array(
						'fallback_allowed' => false,
						'owner_state'      => 'unknown',
						'retryable'        => false,
					)
				)
			);
		}

		return $this->selected;
	}

	/** Returns the selected provider after registry closure. */
	public function selected(): ?ERankly_Multilingual_Provider_Interface {
		return $this->closed && $this->booted ? $this->selected : null;
	}

	/** Returns true after registration has closed. */
	public function is_closed(): bool {
		return $this->closed;
	}

	/** @return array<string,ERankly_Multilingual_Provider_Interface> */
	public function providers(): array {
		return $this->providers;
	}

	/** @return array<int,array{code:string,message:string,data:array<string,mixed>}> */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	public function add_diagnostic( string $code, string $message, array $data = array() ): void {
		$this->diagnostic( $code, $message, $data );
	}

	/**
 * Runs and normalizes read-only provider preflight.
 *
 * @return true|WP_Error
 */
	private function run_preflight( ERankly_Multilingual_Provider_Interface $provider ): bool|WP_Error {
		try {
			$result = $provider->preflight();
		} catch ( Throwable ) {
			$result = false;
		}

		if ( true === $result ) {
			return true;
		}

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$data = is_array( $data ) ? $data : array();
			$data = array_merge(
				array(
					'fallback_allowed' => false,
					'owner_state'      => 'unknown',
					'retryable'        => false,
				),
				$data
			);
			$result->add_data( $data );

			return $result;
		}

		return new WP_Error(
			'erankly_provider_preflight_failed',
			__( 'The multilingual provider did not pass its preflight check.', 'easyrankly' ),
			array(
				'fallback_allowed' => false,
				'owner_state'      => 'unknown',
				'retryable'        => false,
			)
		);
	}

	/**
 * Rejects a provider and records the public rejection action.
 *
 * @return WP_Error
 */
	private function reject( ERankly_Multilingual_Provider_Interface $provider, WP_Error $error ): WP_Error {
		$this->diagnostic(
			$error->get_error_code(),
			$error->get_error_message(),
			array_merge( array( 'provider_id' => $provider->get_id() ), is_array( $error->get_error_data() ) ? $error->get_error_data() : array() )
		);
		do_action( 'erankly_multilingual_provider_rejected', $provider, $error );

		return $error;
	}

	private function diagnostic( string $code, string $message, array $data = array() ): void {
		$signature = $code . ':' . (string) ( $data['provider_id'] ?? '' );
		foreach ( $this->diagnostics as $diagnostic ) {
			if ( $signature === $diagnostic['code'] . ':' . (string) ( $diagnostic['data']['provider_id'] ?? '' ) ) {
				return;
			}
		}

		$this->diagnostics[] = array(
			'code'    => $code,
			'message' => $message,
			'data'    => $data,
		);
	}
}

function erankly_get_extension_api_version(): int {
	return ERANKLY_EXTENSION_API_VERSION;
}

/** @return bool|WP_Error */
function erankly_register_multilingual_provider( ERankly_Multilingual_Provider_Interface $provider ): bool|WP_Error {
	return ERankly_Multilingual_Provider_Registry::instance()->register( $provider );
}

function erankly_get_multilingual_provider(): ?ERankly_Multilingual_Provider_Interface {
	return ERankly_Multilingual_Provider_Registry::instance()->selected();
}

/** Closes the request registry and boots one provider at most. */
function erankly_close_multilingual_provider_registry(): ?ERankly_Multilingual_Provider_Interface {
	return ERankly_Multilingual_Provider_Registry::instance()->close_and_boot();
}

/** @return array<int,array{code:string,message:string,data:array<string,mixed>}> */
function erankly_get_multilingual_diagnostics(): array {
	return ERankly_Multilingual_Provider_Registry::instance()->diagnostics();
}

/** @return array<string,mixed> */
function erankly_get_multilingual_context(): array {
	static $resolved = false;
	static $context  = array();

	if ( $resolved ) {
		return $context;
	}

	$resolved = true;
	$provider = erankly_get_multilingual_provider();
	if ( ! $provider instanceof ERankly_Multilingual_Provider_Interface || ! $provider->is_enabled() ) {
		return array();
	}

	try {
		$raw = $provider->get_context();
	} catch ( Throwable ) {
		$raw = array();
	}

	if ( ! is_array( $raw ) ) {
		$raw = array();
	}

	$context = array(
		'language_id'    => sanitize_text_field( (string) ( $raw['language_id'] ?? '' ) ),
		'hreflang'       => sanitize_text_field( (string) ( $raw['hreflang'] ?? '' ) ),
		'kind'           => sanitize_key( (string) ( $raw['kind'] ?? 'other' ) ),
		'route_kind'     => sanitize_key( (string) ( $raw['route_kind'] ?? 'other' ) ),
		'object_id'      => max( 0, (int) ( $raw['object_id'] ?? 0 ) ),
		'object_subtype' => sanitize_key( (string) ( $raw['object_subtype'] ?? '' ) ),
		'blog_id'        => max( 0, (int) ( $raw['blog_id'] ?? get_current_blog_id() ) ),
		'url'            => esc_url_raw( (string) ( $raw['url'] ?? '' ) ),
		'locale'         => sanitize_text_field( (string) ( $raw['locale'] ?? get_locale() ) ),
		'is_preview'     => ! empty( $raw['is_preview'] ),
	);

	return $context;
}

/**
 * Invokes the selected provider alternate resolver once per mode.
 *
 * @param bool $navigable Whether to request the visitor-navigable set.
 * @return array<string,string>
 */
function erankly_get_provider_alternates( bool $navigable ): array {
	$provider = erankly_get_multilingual_provider();
	if ( ! $provider instanceof ERankly_Multilingual_Provider_Interface || ! $provider->is_enabled() ) {
		return array();
	}

	try {
		$value = $provider->get_alternates( erankly_get_multilingual_context(), $navigable );
	} catch ( Throwable ) {
		$value = array();
	}

	return is_array( $value ) ? $value : array();
}

/**
 * Resolves and memoizes the hreflang output owner after the main query exists.
 *
 * @return string Provider ID or none.
 */
function erankly_get_hreflang_output_owner(): string {
	static $owner = null;

	if ( null !== $owner ) {
		return $owner;
	}

	$provider = erankly_get_multilingual_provider();
	if ( ! $provider instanceof ERankly_Multilingual_Provider_Interface || ! $provider->is_enabled() ) {
		$owner = 'none';

		return $owner;
	}

	$provider_id = $provider->get_id();
	$candidate   = sanitize_key(
		(string) apply_filters(
			'erankly_hreflang_output_owner',
			$provider_id,
			erankly_get_multilingual_context(),
			$provider_id
		)
	);

	if ( 'none' === $candidate ) {
		$owner = 'none';

		return $owner;
	}

	$registered = ERankly_Multilingual_Provider_Registry::instance()->providers();
	if ( ! isset( $registered[ $candidate ] ) ) {
		ERankly_Multilingual_Provider_Registry::instance()->add_diagnostic(
			'erankly_hreflang_owner_invalid',
			__( 'The selected hreflang output owner is not a registered provider; output was suppressed.', 'easyrankly' ),
			array( 'provider_id' => $candidate )
		);
		$owner = 'none';

		return $owner;
	}

	// This core can render only the selected provider result. Returning another
	// registered provider ID intentionally cedes/suppresses EasyRankly output.
	$owner = $candidate;

	return $owner;
}

function erankly_render_multilingual_provider_notices(): void {
	if ( ! current_user_can( is_network_admin() ? 'manage_network_options' : 'manage_options' ) ) {
		return;
	}

	foreach ( erankly_get_multilingual_diagnostics() as $diagnostic ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $diagnostic['message'] )
		);
	}
}

/** @return array<string,mixed> */
function erankly_add_multilingual_debug_information( array $info ): array {
	$provider = erankly_get_multilingual_provider();
	$codes    = array_values( array_unique( array_column( erankly_get_multilingual_diagnostics(), 'code' ) ) );

	$info['easyrankly_multilingual_bridge'] = array(
		'label'  => __( 'EasyRankly multilingual bridge', 'easyrankly' ),
		'fields' => array(
			'api'         => array(
				'label' => __( 'Extension API', 'easyrankly' ),
				'value' => (string) ERANKLY_EXTENSION_API_VERSION,
			),
			'provider'    => array(
				'label' => __( 'Selected provider', 'easyrankly' ),
				'value' => $provider instanceof ERankly_Multilingual_Provider_Interface ? $provider->get_id() : 'none',
			),
			'diagnostics' => array(
				'label' => __( 'Diagnostics', 'easyrankly' ),
				'value' => $codes ? implode( ', ', $codes ) : 'none',
			),
		),
	);

	return $info;
}
