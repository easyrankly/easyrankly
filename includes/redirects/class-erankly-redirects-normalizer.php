<?php
/**
 * URL and redirect normalization helpers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes redirect source paths and validates targets.
 */
final class ERankly_Redirects_Normalizer {
	/**
	 * Per-pattern PCRE limits used to contain backtracking-heavy redirects.
	 *
	 * @var string
	 */
	private const PCRE_LIMITS = '(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=100000)';

	/**
	 * Valid redirect status codes.
	 *
	 * @var int[]
	 */
	public const VALID_STATUS_CODES = array( 301, 302, 410, 451 );

	/**
	 * Status codes that terminate the request with a status header only —
	 * they carry no Location and therefore need no target URL.
	 *
	 * @var int[]
	 */
	public const STATUS_ONLY_CODES = array( 410, 451 );

	/**
	 * Human-readable labels for each supported status code.
	 *
	 * @var array<int,string>
	 */
	public const STATUS_CODE_LABELS = array(
		301 => '301 — Moved Permanently',
		302 => '302 — Found (Temporary)',
		410 => '410 — Gone',
		451 => '451 — Unavailable For Legal Reasons',
	);

	/**
	 * Return the human-readable label for a status code.
	 *
	 * @param int $code HTTP status code.
	 * @return string
	 */
	public static function status_code_label( int $code ): string {
		return self::STATUS_CODE_LABELS[ $code ] ?? (string) $code;
	}

	/**
	 * Normalize an exact source path.
	 *
	 * @param string $path Raw path or URL.
	 * @return string
	 */
	public static function normalize_path( string $path ): string {
		$path = trim( $path );
		$path = self::extract_path( $path );
		$path = rawurldecode( $path );
		$path = preg_replace( '/\s+/', '', $path ) ?? $path;
		$path = '/' . ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path ) ?? $path;
		$path = '/' === $path ? '/' : untrailingslashit( $path );

		return strtolower( $path );
	}

	/**
	 * Normalize source data before persistence.
	 *
	 * Regex patterns are trimmed and made path-like, but the expression body is
	 * otherwise preserved so character classes and modifiers remain meaningful.
	 * Wildcard patterns are lowercased and stripped of the query string while
	 * the literal '*' markers are kept intact.
	 *
	 * @param string $source_path Raw source path, regex, or wildcard.
	 * @param bool   $is_regex    Whether this source is a manual regex.
	 * @param bool   $is_wildcard Whether this source uses wildcard (*) syntax.
	 * @return string
	 */
	public static function normalize_source( string $source_path, bool $is_regex, bool $is_wildcard = false ): string {
		if ( $is_regex ) {
			// Do NOT call wp_unslash() here — the caller has already unslashed the raw POST
			// value (admin path) or the data arrives from JSON with no WordPress-added slashes
			// (import path). A second unslash would corrupt backslashes in patterns like \d, \..
			// Do NOT strip a "query string" either: '?' is a common regex metacharacter
			// (optional quantifiers, non-capturing groups "(?:", named groups "(?<") and
			// stripping from the first '?' onward would truncate/corrupt the pattern body.
			$source_path = trim( $source_path );

			return '' === $source_path || '^' === $source_path[0] ? $source_path : '/' . ltrim( $source_path, '/' );
		}

		if ( $is_wildcard ) {
			// Same reasoning as for regex: unslashing is the caller's responsibility.
			$source_path = trim( $source_path );
			$source_path = self::strip_query_string( $source_path );
			$source_path = strtolower( $source_path );

			if ( '' !== $source_path && '/' !== $source_path[0] && '*' !== $source_path[0] ) {
				$source_path = '/' . $source_path;
			}

			return $source_path;
		}

		return self::normalize_path( $source_path );
	}

	/**
	 * Build a preg pattern from a wildcard source path.
	 *
	 * Each '*' becomes a '(.+)' capture group. The resulting pattern anchors
	 * to the full path and is case-insensitive.
	 *
	 * @param string $source Normalized wildcard source (may contain '*').
	 * @return string
	 */
	public static function build_wildcard_pattern( string $source ): string {
		$parts   = explode( '*', $source );
		$escaped = array_map( static fn( string $p ): string => preg_quote( $p, '#' ), $parts );

		return '#' . self::PCRE_LIMITS . '^' . implode( '(.+)', $escaped ) . '$#i';
	}

	/**
	 * Apply wildcard back-references to a target URL.
	 *
	 * Each '*' in the target is replaced by the corresponding capture group
	 * from the wildcard source pattern ($1, $2, …).
	 *
	 * @param string $source      Normalized wildcard source.
	 * @param string $path        Current normalized request path.
	 * @param string $target_url  Stored target URL (may contain '*').
	 * @return string
	 */
	public static function apply_wildcard_target( string $source, string $path, string $target_url ): string {
		$pattern = self::build_wildcard_pattern( $source );
		$i       = 0;
		$target  = preg_replace_callback(
			'/\*/',
			static function () use ( &$i ): string {
				return '$' . ( ++$i );
			},
			$target_url
		);

		if ( ! is_string( $target ) ) {
			return $target_url;
		}

		$result = preg_replace( $pattern, $target, $path, 1 );

		return is_string( $result ) ? $result : $target_url;
	}

	/**
	 * Check whether a wildcard source path is valid.
	 *
	 * Must contain at least one '*', start with '/' or '*', and have no whitespace.
	 *
	 * @param string $source_path Normalized wildcard source.
	 * @return bool
	 */
	public static function is_valid_wildcard_source( string $source_path ): bool {
		return str_contains( $source_path, '*' )
			&& preg_match( '/\s/', $source_path ) === 0
			&& ( str_starts_with( $source_path, '/' ) || str_starts_with( $source_path, '*' ) );
	}

	/**
	 * Create the unique source hash.
	 *
	 * @param string $source_path Normalized source.
	 * @return string
	 */
	public static function source_hash( string $source_path ): string {
		return md5( $source_path );
	}

	/**
	 * Validate and normalize a target URL for storage.
	 *
	 * @param string $target_url Raw target URL.
	 * @return string Empty string when invalid.
	 */
	public static function normalize_target_url( string $target_url ): string {
		$target_url = trim( wp_unslash( $target_url ) );

		if ( '' === $target_url ) {
			return '';
		}

		if ( self::is_internal_url( $target_url ) ) {
			if ( preg_match( '/[\r\n\s]/', $target_url ) ) {
				return '';
			}

			return $target_url;
		}

		$target_url = esc_url_raw( $target_url, array( 'http', 'https' ) );

		if ( '' === $target_url || ! self::is_safe_absolute_url( $target_url ) ) {
			return '';
		}

		return $target_url;
	}

	/**
	 * Check whether a status code is supported.
	 *
	 * @param int $status_code Status code.
	 * @return bool
	 */
	public static function is_valid_status_code( int $status_code ): bool {
		return in_array( $status_code, self::VALID_STATUS_CODES, true );
	}

	/**
	 * Check whether a status code terminates the request without a Location target.
	 *
	 * @param int $status_code HTTP status code.
	 * @return bool
	 */
	public static function is_status_only_code( int $status_code ): bool {
		return in_array( $status_code, self::STATUS_ONLY_CODES, true );
	}

	/**
	 * Check whether a non-regex source is a valid internal path.
	 *
	 * @param string $source_path Normalized source path.
	 * @return bool
	 */
	public static function is_valid_internal_path( string $source_path ): bool {
		return preg_match( '#^/[^\s]*$#', $source_path ) === 1 && ! str_starts_with( $source_path, '//' );
	}

	/**
	 * Validate an absolute external URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_safe_absolute_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a URL is internal and path-relative.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_internal_url( string $url ): bool {
		return str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' );
	}

	/**
	 * Convert a stored regex body into a preg pattern.
	 *
	 * @param string $regex Stored regex body.
	 * @return string
	 */
	public static function build_regex_pattern( string $regex ): string {
		return '#' . self::PCRE_LIMITS . '(?:' . str_replace( '#', '\#', $regex ) . ')#i';
	}

	/**
	 * Test whether a stored regex is valid.
	 *
	 * @param string $regex Stored regex body.
	 * @return bool
	 */
	public static function is_valid_regex( string $regex ): bool {
		if ( '' === trim( $regex ) ) {
			return false;
		}

		// Reject overly long patterns outright; combined with the catastrophic-backtracking
		// probe below this stops a stored regex from stalling every front-end request.
		if ( strlen( $regex ) > 512 ) {
			return false;
		}

		$pattern = self::build_regex_pattern( $regex );

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporarily suppresses invalid user regex warnings during validation.
			static function (): bool {
				return true;
			}
		);

		// First confirm the pattern compiles at all.
		$compiles = preg_match( $pattern, '' );

		// Then probe it against an adversarial input: a pattern that backtracks
		// catastrophically blows past the lowered limit and returns false, so we reject
		// it before it can ever reach the front-end matcher.
		$probe = false !== $compiles ? preg_match( $pattern, str_repeat( 'a', 1000 ) . '!' ) : false;

		restore_error_handler();

		return false !== $compiles && false !== $probe;
	}

	/**
	 * Apply regex backreferences to a target URL.
	 *
	 * @param string $regex Stored regex body.
	 * @param string $path Current normalized path.
	 * @param string $target_url Stored target URL.
	 * @return string
	 */
	public static function apply_regex_target( string $regex, string $path, string $target_url ): string {
		$pattern = self::build_regex_pattern( $regex );
		$result  = preg_replace( $pattern, $target_url, $path, 1 );

		return is_string( $result ) ? $result : $target_url;
	}

	/**
	 * Return target path when a target points at the current site.
	 *
	 * @param string $target_url Target URL.
	 * @return string|null
	 */
	public static function target_to_local_path( string $target_url ): ?string {
		if ( self::is_internal_url( $target_url ) ) {
			return self::normalize_path( $target_url );
		}

		$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$target_host = wp_parse_url( $target_url, PHP_URL_HOST );

		if ( ! $home_host || ! $target_host || strtolower( (string) $home_host ) !== strtolower( (string) $target_host ) ) {
			return null;
		}

		return self::normalize_path( $target_url );
	}

	/**
	 * Extract only path from a path or absolute URL.
	 *
	 * @param string $value Path or URL.
	 * @return string
	 */
	private static function extract_path( string $value ): string {
		$value = self::strip_query_string( $value );
		$path  = wp_parse_url( $value, PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : $value;
	}

	/**
	 * Remove query string and fragment.
	 *
	 * @param string $value URL-ish value.
	 * @return string
	 */
	private static function strip_query_string( string $value ): string {
		$value = preg_replace( '/[?#].*$/', '', $value );

		return is_string( $value ) ? $value : '';
	}
}
