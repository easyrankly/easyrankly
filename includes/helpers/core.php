<?php
/** Minimal request-context and PHP compatibility helpers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns whether a URL is an absolute HTTP(S) URL. This primitive lives in the always-loaded kernel because
 * public provider consumers must not rely on the bundled runtime loading rich SEO helpers.
 */
function erankly_is_absolute_http_url( string $url ): bool {
	$url = esc_url_raw( trim( $url ) );

	if ( '' === $url ) {
		return false;
	}

	$parts = wp_parse_url( $url );

	return is_array( $parts ) && ! empty( $parts['host'] ) && ! empty( $parts['scheme'] ) && in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
}

/** PHP 8.0-compatible replacement for array_is_list(). */
function erankly_array_is_list( array $arr ): bool {
	if ( array() === $arr ) {
		return true;
	}

	return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
}

/** @return array<string,WP_Post_Type> */
function erankly_get_public_post_types(): array {
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );

	return apply_filters( 'erankly_post_types', $post_types );
}

/** @return array<string,WP_Taxonomy> */
function erankly_get_public_taxonomies(): array {
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
	unset( $taxonomies['post_format'], $taxonomies['product_shipping_class'] );

	return apply_filters( 'erankly_taxonomies', $taxonomies );
}

function erankly_is_frontend_html_request(): bool {
	return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
}
