<?php
/**
 * Small link-path helpers shared by Health admin and crawler code.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes a URL or path to a root-relative path for internal link matching.
 *
 * @param string $url URL or path to normalize.
 * @return string Normalized root-relative path, or empty string if not resolvable.
 */
function erankly_health_normalize_link_path( string $url ): string {
	$path = wp_parse_url( $url, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );

	return '/' === $path ? $path : untrailingslashit( $path );
}
