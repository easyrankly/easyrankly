<?php
/**
 * Shared helpers: input sanitization.
 *
 * Common text and URL primitives loaded early on every request. LocalBusiness
 * and schema-specific sanitizers live in sanitization-schema.php.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes a plain text field.
 *
 * Expects an already-unslashed value: callers reading from $_POST must
 * wp_unslash() first. Unslashing here too would corrupt literal backslashes.
 *
 * @param mixed $value Raw (unslashed) value.
 * @return string
 */
function erankly_sanitize_text( mixed $value ): string {
	return sanitize_text_field( (string) $value );
}

/**
 * Sanitizes textarea text without markup.
 *
 * Expects an already-unslashed value (see erankly_sanitize_text()).
 *
 * @param mixed $value Raw (unslashed) value.
 * @return string
 */
function erankly_sanitize_textarea( mixed $value ): string {
	return sanitize_textarea_field( (string) $value );
}

/**
 * Normalizes an X/Twitter handle.
 *
 * @param mixed $value Raw handle or profile URL.
 * @return string
 */
function erankly_sanitize_twitter_handle( mixed $value ): string {
	$value = trim( erankly_sanitize_text( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( preg_match( '#^(?:https?://)?(?:www\.)?(?:x|twitter)\.com/#i', $value ) ) {
		$url   = str_starts_with( $value, 'http' ) ? $value : 'https://' . $value;
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$value = (string) strtok( trim( $path, '/' ), '/' );
	}

	$handle = ltrim( $value, '@' );
	$handle = preg_replace( '/[^A-Za-z0-9_]/', '', $handle );
	$handle = substr( (string) $handle, 0, 15 );

	return '' === $handle ? '' : '@' . $handle;
}

/**
 * Sanitizes a URL field.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_url( mixed $value ): string {
	$value = trim( (string) $value );

	return '' === $value ? '' : esc_url_raw( $value );
}

/**
 * Sanitizes an absolute HTTP(S) URL.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_absolute_url( mixed $value ): string {
	$url = erankly_sanitize_url( $value );

	if ( '' === $url ) {
		return '';
	}

	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	$host   = wp_parse_url( $url, PHP_URL_HOST );

	return is_string( $scheme ) && is_string( $host ) && in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ? $url : '';
}

/**
 * Sanitizes a URL field that may contain EasyRankly {{variables}}.
 *
 * Literal URLs go through esc_url_raw(). Templated URLs are finalized only after
 * variable replacement, so preserve placeholders while still removing invalid
 * text input and disallowed protocols at save time.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_url_template( mixed $value ): string {
	$value = trim( erankly_sanitize_text( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( ! str_contains( $value, '{{' ) ) {
		return erankly_sanitize_url( $value );
	}

	$value = (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ): string {
			return '{{' . strtolower( (string) $matches[1] ) . '}}';
		},
		$value
	);

	return trim( wp_kses_bad_protocol( $value, wp_allowed_protocols() ) );
}

/**
 * Sanitizes a newline-separated list of absolute HTTP(S) URLs.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_url_list( mixed $value ): string {
	$value = erankly_sanitize_textarea( $value );
	$lines = preg_split( '/\R/', $value );

	if ( ! is_array( $lines ) ) {
		return '';
	}

	$urls = array();

	foreach ( $lines as $line ) {
		$url = erankly_sanitize_absolute_url( $line );

		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}

	return implode( "\n", array_values( array_unique( $urls ) ) );
}

/**
 * Produces a compact SEO string.
 *
 * @param string $value Raw string.
 * @param int    $limit Character limit.
 * @return string
 */
function erankly_trim_text( string $value, int $limit = 160 ): string {
	$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );

	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

	if ( '' === $value || $length <= $limit ) {
		return $value;
	}

	$excerpt = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit - 1 ) : substr( $value, 0, $limit - 1 );

	return rtrim( $excerpt, " \t\n\r\0\x0B.,;:-" );
}

/**
 * Produces a compact SEO string without applying a character limit.
 *
 * @param string $value Raw string.
 * @return string
 */
function erankly_normalize_seo_text( string $value ): string {
	$value = preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $value ) ) );
	if ( is_string( $value ) ) {
		$value = preg_replace( '/(?:\s*(?:-|\||–|—)\s*)+$/u', '', $value );
	}

	return is_string( $value ) ? trim( $value ) : '';
}
