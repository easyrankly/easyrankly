<?php
/** Frontend redirect runner. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ERankly_Redirects_Runner {

	private ERankly_Redirects_Repository $repository;

	public function __construct( ERankly_Redirects_Repository $repository ) {
		$this->repository = $repository;
	}

	/** Register frontend hook. */
	public function register_hooks(): void {
		// Core identifies and serves REST requests at priority 10. Running after
		// that callback makes REST_REQUEST reliable while still preceding query
		// execution and template selection for ordinary frontend requests.
		add_action( 'parse_request', array( $this, 'maybe_redirect' ), 11 );
	}

	/** Try to redirect the current frontend request. */
	public function maybe_redirect(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $request_uri || $this->should_skip_request( $request_uri ) ) {
			return;
		}
		// Redirect behavior is intentionally fixed for administrators so the
		// setting cannot accidentally lock a site owner out of a route.
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_path  = ERankly_Redirects_Normalizer::normalize_path( $request_uri );
		$current_query = ERankly_Redirects_Normalizer::extract_query( $request_uri );
		$source_hash   = ERankly_Redirects_Normalizer::source_hash( $current_path );
		$exact_rule    = $this->repository->get_exact_rule_cached( $source_hash );
		$patterns      = $this->repository->get_pattern_rules( $current_path, $current_query );
		$advanced_rule = empty( $patterns ) ? null : $this->find_advanced_match( $request_uri, $patterns );
		$redirect      = $exact_rule;
		$target_url    = '';

		if ( $advanced_rule ) {
			if ( ! $exact_rule || ERankly_Redirects_Normalizer::compare_rules( $advanced_rule, $exact_rule ) < 0 ) {
				$redirect = $advanced_rule;
			}
		}

		if ( $redirect ) {
			$evaluation = ERankly_Redirects_Normalizer::evaluate_rule( $redirect, $request_uri );
			$target_url = (string) $evaluation['target_url'];
		}

		if ( ! $redirect ) {
			return;
		}

		$status_code = (int) $redirect['status_code'];

		if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
			return;
		}

		if ( ERankly_Redirects_Normalizer::is_status_only_code( $status_code ) ) {
			$this->repository->increment_hit( (int) $redirect['id'] );
			$this->send_status_only_response( $status_code );
			exit;
		}

		if ( '' === $target_url || $this->is_loop( $current_path, $target_url ) ) {
			return;
		}

		$this->allow_safe_external_host_for_target( $target_url );
		$this->repository->increment_hit( (int) $redirect['id'] );

		wp_safe_redirect( $target_url, $status_code, 'EasyRankly' );
		exit;
	}

	/**
 * Determines whether WordPress or another core endpoint owns the request. Explicit path and query checks
 * complement REST_REQUEST so a changed hook priority or custom REST prefix cannot expose core endpoints to broad
 * rules.
 *
 * @return bool True when redirect matching must not run.
 */
	private function should_skip_request( string $request_uri ): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}

		global $pagenow;

		if ( 'wp-login.php' === (string) $pagenow ) {
			return true;
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only core request routing.
			return true;
		}

		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$request_path = is_string( $request_path ) ? '/' . ltrim( $request_path, '/' ) : '';

		if ( 'wp-login.php' === basename( $request_path ) ) {
			return true;
		}

		$rest_url  = rest_url();
		$rest_path = wp_parse_url( $rest_url, PHP_URL_PATH );
		$rest_path = is_string( $rest_path ) ? '/' . trim( $rest_path, '/' ) : '';

		return '' !== $rest_path
			&& ( $request_path === $rest_path || str_starts_with( $request_path, $rest_path . '/' ) );
	}

	/** Send a status-only response (410/451) with no Location header. */
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
	 * @return array<string,mixed>|null
	 */
	private function find_advanced_match( string $request_uri, array $redirects ): ?array {
		// Realistic paths are short; a multi-kilobyte path is only ever a vector for
		// driving up pattern-matching cost, so refuse to run regexes against it.
		if ( strlen( $request_uri ) > 4096 ) {
			return null;
		}

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporarily suppresses invalid stored regex warnings during matching.
			static function (): bool {
				return true;
			}
		);

		$match            = null;
		foreach ( $redirects as $redirect ) {
			$evaluation = ERankly_Redirects_Normalizer::evaluate_rule( $redirect, $request_uri );
			if ( ! empty( $evaluation['matches'] ) ) {
				$match = $redirect;
				break;
			}
		}

		restore_error_handler();

		return $match;
	}

	/** Prevent redirects that resolve to the same local path or a redirect cycle. */
	private function is_loop( string $current_path, string $target_url ): bool {
		$visited = array( $current_path => true );
		$next    = $target_url;
		$hops    = 0;

		while ( $hops < 10 ) {
			$target_path = ERankly_Redirects_Normalizer::target_to_local_path( $next );

			if ( null === $target_path ) {
				return false;
			}

			if ( isset( $visited[ $target_path ] ) ) {
				return true;
			}

			$visited[ $target_path ] = true;

			if ( $target_path === $current_path ) {
				return true;
			}

			$rule = $this->repository->get_exact_rule_cached( ERankly_Redirects_Normalizer::source_hash( $target_path ) );
			if ( ! is_array( $rule ) || empty( $rule['target_url'] ) ) {
				return false;
			}

			if ( ERankly_Redirects_Normalizer::is_status_only_code( (int) ( $rule['status_code'] ?? 0 ) ) ) {
				return false;
			}

			$next = ERankly_Redirects_Normalizer::normalize_target_url( (string) $rule['target_url'] );
			++$hops;
		}

		return true;
	}

	/** Allow wp_safe_redirect() to redirect to a validated external target host. */
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
