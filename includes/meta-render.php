<?php
/**
 * Frontend head rendering and attachment redirects.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the minimal SEO head. */
function erankly_render_head(): void {
	static $rendered = false;

	if ( $rendered ) {
		return;
	}

	$rendered    = true;
	$description = erankly_get_description();
	$canonical   = erankly_get_canonical();

	if ( '' !== $description ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
	}

	if ( '' !== $canonical ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
	}

	erankly_render_opengraph_tags();
	erankly_render_oembed_link();
	erankly_render_schema();
}

/** Redirects attachment pages to their parent post or media file. */
function erankly_redirect_attachment(): void {
	if ( ! is_attachment() ) {
		return;
	}

	$mode = (string) erankly_get_setting( 'attachment_redirect', 'none' );

	if ( 'none' === $mode ) {
		return;
	}

	$post_id    = get_queried_object_id();
	$parent_id  = (int) wp_get_post_parent_id( $post_id );
	$target_url = '';

	if ( 'parent' === $mode && $parent_id > 0 ) {
		$permalink  = get_permalink( $parent_id );
		$target_url = is_string( $permalink ) ? $permalink : '';
	}

	if ( '' === $target_url ) {
		$file_url   = wp_get_attachment_url( $post_id );
		$target_url = is_string( $file_url ) ? $file_url : '';
	}

	if ( '' === $target_url ) {
		return;
	}

	if ( wp_parse_url( $target_url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		wp_safe_redirect( $target_url, 301, 'EasyRankly' );
	} else {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External media can live on a CDN.
		wp_redirect( $target_url, 301, 'EasyRankly' );
	}

	exit;
}
