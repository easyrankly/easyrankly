<?php
/**
 * Shared helpers — sitemap URLs and cache invalidation.
 *
 * Part of the helpers.php loader; always loaded early on every request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a sitemap URL, using query parameters if permalinks are disabled.
 *
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
 * Returns the URL of the core wp_sitemaps XSL stylesheet.
 *
 * The specialist sitemaps reuse the native stylesheet so their browser view is
 * visually identical to the core /wp-sitemap.xml pages.
 *
 * @return string
 */
function erankly_get_sitemap_stylesheet_url(): string {
	return wp_sitemaps_get_server()->renderer->get_sitemap_stylesheet_url();
}

/**
 * Determines whether the sitemap feature is enabled.
 *
 * @return bool
 */
function erankly_sitemap_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_sitemap', 0 );
}

/**
 * Determines whether the Health feature is enabled.
 *
 * @return bool
 */
function erankly_health_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_health', 0 );
}

/**
 * Clears sitemap transients.
 *
 * @param mixed ...$hook_args Hook arguments (not used, hook may pass any number of args).
 * @return void
 */
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

/**
 * Invalidates sitemap caches after a meaningful post save.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function erankly_flush_sitemap_cache_for_post( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	erankly_flush_sitemap_cache();
}

/**
 * Invalidates sitemap caches after a post deletion.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function erankly_flush_sitemap_cache_for_deleted_post( int $post_id ): void {
	if ( $post_id > 0 ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Invalidates sitemap caches after a publication status transition.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Previous status.
 * @param WP_Post $post       Post object.
 * @return void
 */
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
 * @param int    $term_id  Term ID.
 * @param string $meta_key Meta key.
 * @return void
 */
function erankly_flush_sitemap_cache_for_term_meta( mixed $meta_id, int $term_id, string $meta_key ): void {
	unset( $meta_id, $term_id );

	if ( str_starts_with( $meta_key, '_erankly_' ) ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Returns a versioned sitemap transient key.
 *
 * Versioning makes invalidation a constant-time option update. Older transient
 * rows expire naturally instead of being deleted with a wildcard SQL query.
 *
 * @param string $suffix Cache key suffix.
 * @return string
 */
function erankly_get_sitemap_cache_key( string $suffix ): string {
	static $version = null;

	if ( null === $version ) {
		$version = max( 1, (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 ) );
	}

	return ERANKLY_SITEMAP_TRANSIENT_PREFIX . $version . '_' . sanitize_key( $suffix );
}
