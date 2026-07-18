<?php
/**
 * Minimal request-context and PHP compatibility helpers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP 8.0-compatible replacement for array_is_list().
 *
 * @param array<mixed> $arr Array to inspect.
 * @return bool
 */
function erankly_array_is_list( array $arr ): bool {
	if ( array() === $arr ) {
		return true;
	}

	return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
}

/**
 * Returns public post types supported by EasyRankly.
 *
 * @return array<string,WP_Post_Type>
 */
function erankly_get_public_post_types(): array {
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );

	/** This filter is part of the public helper API. */
	return apply_filters( 'erankly_post_types', $post_types );
}

/**
 * Returns public taxonomies supported by EasyRankly.
 *
 * @return array<string,WP_Taxonomy>
 */
function erankly_get_public_taxonomies(): array {
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
	unset( $taxonomies['post_format'], $taxonomies['product_shipping_class'] );

	/** This filter is part of the public helper API. */
	return apply_filters( 'erankly_taxonomies', $taxonomies );
}

/**
 * Returns whether a request is likely a frontend HTML request.
 *
 * @return bool
 */
function erankly_is_frontend_html_request(): bool {
	return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
}
