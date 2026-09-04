<?php
/** Shared helpers: sitemap URLs and cache invalidation. Loaded only for sitemap, robots, lifecycle and related settings work. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $path The root-relative sitemap path (e.g. '/wp-sitemap.xml').
 * @return string The resolved absolute URL.
 */
function erankly_get_sitemap_url( string $path ): string {
	if ( (bool) get_option( 'permalink_structure' ) ) {
		return home_url( '/' . ltrim( $path, '/' ) );
	}

	if ( '/wp-sitemap.xml' === $path ) {
		return home_url( '/?sitemap=index' );
	}

	if ( preg_match( '/^\/sitemap-(image|video|news)-([0-9]+)\.xml$/', $path, $matches ) ) {
		return home_url( '/?erankly_sitemap=' . $matches[1] . '&erankly_sitemap_page=' . $matches[2] );
	}

	return home_url( '/' . ltrim( $path, '/' ) );
}

/**
 * Returns the URL of the core wp_sitemaps XSL stylesheet. The specialist sitemaps reuse the native stylesheet so
 * their browser view is visually identical to the core /wp-sitemap.xml pages.
 */
function erankly_get_sitemap_stylesheet_url(): string {
	return wp_sitemaps_get_server()->renderer->get_sitemap_stylesheet_url();
}

/** @param mixed ...$hook_args Hook arguments (not used, hook may pass any number of args). */
function erankly_flush_sitemap_cache( mixed ...$hook_args ): void {
	unset( $hook_args );
	static $flushed_sites = array();

	$site_id = get_current_blog_id();

	if ( isset( $flushed_sites[ $site_id ] ) ) {
		return;
	}

	$flushed_sites[ $site_id ] = true;
	$version                   = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

	update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, max( 1, $version + 1 ), false );
}

/** Invalidates sitemap caches after a meaningful post save. */
function erankly_flush_sitemap_cache_for_post( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	erankly_flush_sitemap_cache();
}

/** Invalidates sitemap caches after a post deletion. */
function erankly_flush_sitemap_cache_for_deleted_post( int $post_id ): void {
	if ( $post_id > 0 ) {
		erankly_flush_sitemap_cache();
	}
}

/** Invalidates sitemap caches after a publication status transition. */
function erankly_flush_sitemap_cache_for_status( string $new_status, string $old_status, WP_Post $post ): void {
	if ( $new_status === $old_status || wp_is_post_revision( $post->ID ) ) {
		return;
	}

	if ( 'publish' === $new_status || 'publish' === $old_status ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Invalidates sitemap caches only for EasyRankly term metadata.
 *
 * @param mixed  $meta_id  Meta row ID or deleted row IDs.
 */
function erankly_flush_sitemap_cache_for_term_meta( mixed $meta_id, int $term_id, string $meta_key ): void {
	unset( $meta_id, $term_id );

	if ( str_starts_with( $meta_key, '_erankly_' ) ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Invalidates sitemap caches only for EasyRankly post metadata.
 *
 * @param mixed  $meta_id    Meta row ID or deleted row IDs.
 */
function erankly_flush_sitemap_cache_for_post_meta( mixed $meta_id, int $object_id, string $meta_key ): void {
	unset( $meta_id, $object_id );

	if ( str_starts_with( $meta_key, '_erankly_' ) ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Returns a versioned sitemap transient key. Versioning makes invalidation a constant-time option update. Older
 * transient rows expire naturally instead of being deleted with a wildcard SQL query.
 */
function erankly_get_sitemap_cache_key( string $suffix ): string {
	static $version = null;

	if ( null === $version ) {
		$version = max( 1, (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 ) );
	}

	return ERANKLY_SITEMAP_TRANSIENT_PREFIX . $version . '_' . sanitize_key( $suffix );
}
