<?php
/**
 * Frontend redirect runner.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs frontend redirect matching.
 */
final class ERankly_Redirects_Runner {
	/**
	 * Redirect repository.
	 *
	 * @var ERankly_Redirects_Repository
	 */
	private ERankly_Redirects_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param ERankly_Redirects_Repository $repository Redirect repository.
	 */
	public function __construct( ERankly_Redirects_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register frontend hook.
	 */
	public function register_hooks(): void {
		add_action( 'parse_request', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Try to redirect the current frontend request.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $request_uri ) {
			return;
		}

		$current_path = ERankly_Redirects_Normalizer::normalize_path( $request_uri );
		$source_hash  = ERankly_Redirects_Normalizer::source_hash( $current_path );
		$redirect     = $this->repository->get_exact_rule_cached( $source_hash );
		$target_url   = '';

		if ( $redirect ) {
			$target_url = (string) $redirect['target_url'];
		} else {
			$patterns = $this->repository->get_pattern_rules();
			$redirect = $this->find_regex_match( $current_path, $patterns );

			if ( $redirect ) {
				if ( ! empty( $redirect['is_wildcard'] ) ) {
					$target_url = ERankly_Redirects_Normalizer::apply_wildcard_target(
						(string) $redirect['source_path'],
						$current_path,
						(string) $redirect['target_url']
					);
				} else {
					$target_url = ERankly_Redirects_Normalizer::apply_regex_target(
						(string) $redirect['source_path'],
						$current_path,
						(string) $redirect['target_url']
					);
				}
			}
		}

		if ( ! $redirect ) {
			return;
		}

		$status_code = (int) $redirect['status_code'];

		if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
			return;
		}

		// Global setting: never redirect administrators.
		if ( erankly_get_setting( 'redirect_exclude_admins', 0 ) && current_user_can( 'manage_options' ) ) {
			return;
		}

		// Per-redirect visibility condition.
		if ( ! $this->passes_visibility( $redirect ) ) {
			return;
		}

		if ( ERankly_Redirects_Normalizer::is_status_only_code( $status_code ) ) {
			$this->repository->increment_hit( (int) $redirect['id'] );
			$this->send_status_only_response( $status_code );
			exit;
		}

		$target_url = ERankly_Redirects_Normalizer::normalize_target_url( $target_url );

		if ( '' === $target_url || $this->is_loop( $current_path, $target_url ) ) {
			return;
		}

		$this->allow_safe_external_host_for_target( $target_url );
		$this->repository->increment_hit( (int) $redirect['id'] );

		wp_safe_redirect( $target_url, $status_code, 'EasyRankly' );
		exit;
	}

	/**
	 * Send a status-only response (410/451) with no Location header.
	 *
	 * @param int $status_code HTTP status code.
	 */
	private function send_status_only_response( int $status_code ): void {
		nocache_headers();

		if ( 451 === $status_code ) {
			header( 'Link: <https://en.wikipedia.org/wiki/HTTP_451>; rel="blocked-by"' );
		}

		$title   = 410 === $status_code ? __( 'Gone', 'easyrankly' ) : __( 'Unavailable For Legal Reasons', 'easyrankly' );
		$message = 410 === $status_code
			? __( 'The requested resource is no longer available and has been permanently removed.', 'easyrankly' )
			: __( 'This resource is unavailable for legal reasons.', 'easyrankly' );

		// The wp_die response value is an integer status code, not rendered output.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_die( esc_html( $message ), esc_html( $title ), array( 'response' => absint( $status_code ) ) );
	}

	/**
	 * Check whether a redirect should apply to the current visitor.
	 *
	 * @param array<string,mixed> $redirect Redirect row.
	 * @return bool
	 */
	private function passes_visibility( array $redirect ): bool {
		$visibility = isset( $redirect['visibility'] ) ? (string) $redirect['visibility'] : 'all';

		if ( 'all' === $visibility ) {
			return true;
		}

		if ( 'logged_out' === $visibility ) {
			return ! is_user_logged_in();
		}

		if ( 'logged_in' === $visibility ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			$required_role = isset( $redirect['required_role'] ) ? (string) $redirect['required_role'] : '';

			if ( '' === $required_role ) {
				return true;
			}

			$user = wp_get_current_user();

			return in_array( $required_role, (array) $user->roles, true );
		}

		return true;
	}

	/**
	 * Find a regex fallback match.
	 *
	 * @param string                         $current_path Normalized current path.
	 * @param array<int,array<string,mixed>> $redirects    Regex and wildcard redirects.
	 * @return array<string,mixed>|null
	 */
	private function find_regex_match( string $current_path, array $redirects ): ?array {
		// Realistic paths are short; a multi-kilobyte path is only ever a vector for
		// driving up pattern-matching cost, so refuse to run regexes against it.
		if ( strlen( $current_path ) > 4096 ) {
			return null;
		}

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporarily suppresses invalid stored regex warnings during matching.
			static function (): bool {
				return true;
			}
		);

		$match = null;
		foreach ( $redirects as $redirect ) {
			if ( ! empty( $redirect['is_wildcard'] ) ) {
				$pattern = ERankly_Redirects_Normalizer::build_wildcard_pattern( (string) $redirect['source_path'] );
			} else {
				$pattern = ERankly_Redirects_Normalizer::build_regex_pattern( (string) $redirect['source_path'] );
			}

			if ( 1 === preg_match( $pattern, $current_path ) ) {
				$match = $redirect;
				break;
			}
		}

		restore_error_handler();

		return $match;
	}

	/**
	 * Prevent redirects that resolve to the same local path.
	 *
	 * @param string $current_path Current normalized path.
	 * @param string $target_url Target URL.
	 * @return bool
	 */
	private function is_loop( string $current_path, string $target_url ): bool {
		$target_path = ERankly_Redirects_Normalizer::target_to_local_path( $target_url );

		return null !== $target_path && $target_path === $current_path;
	}

	/**
	 * Allow wp_safe_redirect() to redirect to a validated external target host.
	 *
	 * @param string $target_url Target URL.
	 */
	private function allow_safe_external_host_for_target( string $target_url ): void {
		if ( ERankly_Redirects_Normalizer::is_internal_url( $target_url ) ) {
			return;
		}

		if ( ! ERankly_Redirects_Normalizer::is_safe_absolute_url( $target_url ) ) {
			return;
		}

		$host = wp_parse_url( $target_url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ) use ( $host ): array {
				$hosts[] = strtolower( $host );

				return array_values( array_unique( $hosts ) );
			}
		);
	}
}
