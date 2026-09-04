<?php
/** Redirect cache helpers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_redirects_cache_key( string $source_hash ): string {
	$generation = (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '0' );

	return 'erankly_redirect_' . $generation . '_' . $source_hash;
}

/**
 * Rotates the namespace used by exact-redirect object-cache entries. Old positive and negative entries become
 * unreachable without requiring an object-cache implementation to support group flushing.
 */
function erankly_rotate_redirects_cache_generation(): void {
	$generation = wp_generate_uuid4();

	update_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, $generation, false );

	if ( (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '' ) !== $generation ) {
		set_transient( 'erankly_redirect_cache_rotation_failed', 1, DAY_IN_SECONDS );

		return;
	}
}

/**
 * Purges full-page caches after redirect data changes. The redirect engine runs before normal page rendering, so
 * full-page caches can preserve either an old redirect or the pre-redirect response. Multisite reset batches may
 * switch across several sites in one request, hence the per-site guard rather than a request-wide boolean.
 */
function erankly_redirects_flush_external_caches(): void {
	static $flushed_sites = array();

	$site_id = (int) get_current_blog_id();

	if ( isset( $flushed_sites[ $site_id ] ) ) {
		return;
	}

	$flushed_sites[ $site_id ] = true;

	// WP Super Cache.
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	// W3 Total Cache.
	if ( function_exists( 'w3tc_flush_posts' ) ) {
		w3tc_flush_posts();
	} elseif ( function_exists( 'w3tc_pgcache_flush' ) ) {
		w3tc_pgcache_flush();
	}

	// WP Rocket.
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	// SiteGround Optimizer.
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}

	// WP Fastest Cache.
	if ( function_exists( 'wpfc_clear_all_cache' ) ) {
		wpfc_clear_all_cache( true );
	}

	// Plugins that expose a purge action: LiteSpeed Cache, Cache Enabler,
	// Breeze, Hummingbird, Nginx Helper. Firing an unregistered action is a no-op.
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally firing third-party cache purge hooks.
	do_action( 'litespeed_purge_all' );
	do_action( 'cache_enabler_clear_complete_cache' );
	do_action( 'breeze_clear_all_cache' );
	do_action( 'wphb_clear_page_cache' );
	do_action( 'rt_nginx_helper_purge_all' );
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

	/**
 * Fires after the known full-page caches have been purged following a redirect mutation. Hook custom cache
 * stacks (CDN, reverse proxy) here.
 *
 * @since 1.0.0
 */
	do_action( 'erankly_redirects_caches_flushed' );
}
