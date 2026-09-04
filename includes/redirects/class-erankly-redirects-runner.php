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

		$current_path  = ERankly_Redirects_Normalizer::normalize_path( $request_uri );
		$current_query = ERankly_Redirects_Normalizer::extract_query( $request_uri );
		$source_hash   = ERankly_Redirects_Normalizer::source_hash( $current_path );
		$exact_rule    = $this->repository->get_exact_rule_cached( $source_hash );
		$patterns      = $this->repository->get_pattern_rules( $current_path, $current_query );
		$now           = current_time( 'mysql' );
		$advanced_rule = empty( $patterns ) ? null : $this->find_advanced_match( $request_uri, $current_query, $patterns, $now );
		$redirect      = $exact_rule;
		$target_url    = '';
		$matched_path  = $current_path;

		if ( $advanced_rule ) {
			$advanced_priority = (int) ( $advanced_rule['priority'] ?? 10 );
			$exact_priority    = $exact_rule ? (int) ( $exact_rule['priority'] ?? 10 ) : PHP_INT_MAX;
			$advanced_id       = (int) ( $advanced_rule['id'] ?? 0 );
			$exact_id          = $exact_rule ? (int) ( $exact_rule['id'] ?? 0 ) : PHP_INT_MAX;

			if ( ! $exact_rule || $advanced_priority < $exact_priority || ( $advanced_priority === $exact_priority && $advanced_id < $exact_id ) ) {
				$redirect = $advanced_rule;
			}
		}

		if ( $redirect ) {
			if ( $advanced_rule && (int) $advanced_rule['id'] === (int) $redirect['id'] ) {
				$case_sensitive = ! empty( $redirect['case_sensitive'] );
				$trailing_slash = (string) ( $redirect['trailing_slash'] ?? 'ignore' );
				$matched_path   = ERankly_Redirects_Normalizer::normalize_match_path(
					$request_uri,
					$case_sensitive,
					$trailing_slash
				);
				$capture_path   = ERankly_Redirects_Normalizer::normalize_match_path_for_capture(
					$request_uri,
					$case_sensitive,
					$trailing_slash
				);
				$match_type     = (string) ( $redirect['match_type'] ?? ( ! empty( $redirect['is_wildcard'] ) ? 'wildcard' : 'regex' ) );

				if ( 'wildcard' === $match_type ) {
					$target_url = ERankly_Redirects_Normalizer::apply_wildcard_target(
						(string) $redirect['source_path'],
						$capture_path,
						(string) $redirect['target_url'],
						$case_sensitive
					);
				} elseif ( 'regex' === $match_type ) {
					$target_url = ERankly_Redirects_Normalizer::apply_regex_target(
						(string) $redirect['source_path'],
						$capture_path,
						(string) $redirect['target_url'],
						$case_sensitive
					);
				} else {
					$target_url = (string) $redirect['target_url'];
				}
			} else {
				$target_url = (string) $redirect['target_url'];
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
		if ( erankly_get_setting( 'redirect_exclude_admins', 1 ) && current_user_can( 'manage_options' ) ) {
			return;
		}

		// Per-redirect visibility condition.
		if ( ! $this->passes_visibility( $redirect ) ) {
			return;
		}

		if ( ! $this->passes_conditions( $redirect ) ) {
			return;
		}

		if ( ERankly_Redirects_Normalizer::is_status_only_code( $status_code ) ) {
			$this->repository->increment_hit( (int) $redirect['id'] );
			$this->send_status_only_response( $status_code );
			exit;
		}

		if ( 'preserve' === (string) ( $redirect['query_mode'] ?? 'ignore' ) ) {
			$target_url = ERankly_Redirects_Normalizer::preserve_query( $target_url, $current_query );
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

		return false;
	}

	/** Evaluates portable request conditions stored with imported redirect rules. Unknown condition keys fail closed so a migration never broadens a rule. */
	private function passes_conditions( array $redirect ): bool {
		if ( empty( $redirect['conditions'] ) ) {
			return true;
		}

		$conditions = is_string( $redirect['conditions'] ) ? json_decode( $redirect['conditions'], true ) : $redirect['conditions'];
		if ( ! is_array( $conditions ) ) {
			return false;
		}

		foreach ( $conditions as $key => $expected ) {
			$key      = sanitize_key( (string) $key );
			$expected = is_scalar( $expected ) ? (string) $expected : '';

			if ( 'referrer_contains' === $key ) {
				$actual = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
			} elseif ( 'user_agent_contains' === $key ) {
				$actual = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			} elseif ( 'language' === $key ) {
				$actual = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
			} elseif ( str_starts_with( $key, 'cookie_' ) ) {
				$cookie = substr( $key, 7 );
				$actual = isset( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
			} else {
				return false;
			}

			if ( '' === $expected || false === stripos( $actual, $expected ) ) {
				return false;
			}
		}

		return true;
	}

	/**
 * @param string                         $current_query Current request query string.
 * @return array<string,mixed>|null
 */
	private function find_advanced_match( string $request_uri, string $current_query, array $redirects, string $now ): ?array {
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
		$normalized_paths = array();
		foreach ( $redirects as $redirect ) {
			if ( ! $this->is_rule_in_schedule( $redirect, $now ) ) {
				continue;
			}

			if ( ! $this->passes_visibility( $redirect ) || ! $this->passes_conditions( $redirect ) ) {
				continue;
			}

			$query_mode = (string) ( $redirect['query_mode'] ?? 'ignore' );
			if ( 'exact' === $query_mode && (string) ( $redirect['source_query'] ?? '' ) !== $current_query ) {
				continue;
			}

			$case_sensitive = ! empty( $redirect['case_sensitive'] );
			$trailing_slash = (string) ( $redirect['trailing_slash'] ?? 'ignore' );
			$path_key       = ( $case_sensitive ? '1' : '0' ) . '|' . $trailing_slash;
			if ( ! isset( $normalized_paths[ $path_key ] ) ) {
				$normalized_paths[ $path_key ] = ERankly_Redirects_Normalizer::normalize_match_path( $request_uri, $case_sensitive, $trailing_slash );
			}
			$current_path = $normalized_paths[ $path_key ];
			$source_path  = (string) ( $redirect['_runtime_source_path'] ?? ERankly_Redirects_Normalizer::normalize_match_path( (string) $redirect['source_path'], $case_sensitive, $trailing_slash ) );
			$match_type   = (string) ( $redirect['match_type'] ?? ( ! empty( $redirect['is_wildcard'] ) ? 'wildcard' : ( ! empty( $redirect['is_regex'] ) ? 'regex' : 'exact' ) ) );
			$matched      = false;

			if ( 'wildcard' === $match_type ) {
				$pattern = (string) ( $redirect['_runtime_pattern'] ?? ERankly_Redirects_Normalizer::build_wildcard_pattern( (string) $redirect['source_path'], $case_sensitive ) );
				$matched = 1 === preg_match( $pattern, $current_path );
			} elseif ( 'regex' === $match_type ) {
				$pattern = (string) ( $redirect['_runtime_pattern'] ?? ERankly_Redirects_Normalizer::build_regex_pattern( (string) $redirect['source_path'], $case_sensitive ) );
				$matched = 1 === preg_match( $pattern, $current_path );
			} elseif ( 'contains' === $match_type ) {
				$matched = str_contains( $current_path, $source_path );
			} elseif ( 'starts_with' === $match_type ) {
				$matched = str_starts_with( $current_path, $source_path );
			} elseif ( 'ends_with' === $match_type ) {
				$matched = str_ends_with( $current_path, $source_path );
			} else {
				$matched = $source_path === $current_path;
			}

			if ( $matched ) {
				$match = $redirect;
				break;
			}
		}

		restore_error_handler();

		return $match;
	}

	private function is_rule_in_schedule( array $redirect, string $now ): bool {
		$start = ! empty( $redirect['start_at'] ) ? (string) $redirect['start_at'] : '';
		$end   = ! empty( $redirect['end_at'] ) ? (string) $redirect['end_at'] : '';

		return ( '' === $start || $now >= $start ) && ( '' === $end || $now <= $end );
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
