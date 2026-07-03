<?php
/**
 * Redirect module loader.
 *
 * The redirect feature is opt-in: its classes are only required, and its hooks
 * only registered when the "Features" tab toggle (enable_redirects) is on.
 * When disabled, none of the redirect code below the gate is loaded.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema version for the redirects table.
 */
define( 'ERANKLY_REDIRECTS_DB_VERSION', '1.0.0' );

/**
 * Option name tracking the installed redirects table version.
 */
define( 'ERANKLY_REDIRECTS_DB_VERSION_OPTION', 'erankly_redirects_db_version' );

/**
 * Whether the redirect feature is enabled in settings.
 *
 * @return bool
 */
function erankly_redirects_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_redirects' ) );
}

/**
 * Boots the redirect module when enabled.
 *
 * Loads the redirect classes, ensures the custom table exists/upgrades, and
 * registers the frontend runner plus the admin action handler. Runs on
 * plugins_loaded so the frontend runner can hook parse_request early.
 *
 * @return void
 */
function erankly_redirects_boot(): void {
	if ( ! erankly_redirects_enabled() ) {
		return;
	}

	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-normalizer.php';
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-activator.php';
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-repository.php';
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-runner.php';
	erankly_redirects_maybe_upgrade_db();

	$repository = new ERankly_Redirects_Repository();

	$runner = new ERankly_Redirects_Runner( $repository );
	$runner->register_hooks();

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( is_admin() && 'erankly' === $page ) {
		require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-admin.php';

		$admin = new ERankly_Redirects_Admin( $repository );
		$admin->register_hooks();

		// Shared instance for the settings page redirect management renderer.
		$GLOBALS['erankly_redirects_admin'] = $admin;
	}
}
add_action( 'plugins_loaded', 'erankly_redirects_boot', 5 );

/**
 * Creates the redirects table on first use and ensures the schema is current.
 *
 * Runs every time the module boots; the version-string comparison is the
 * idempotency guard that prevents re-running dbDelta on an already up-to-date
 * table.
 *
 * @return void
 */
function erankly_redirects_maybe_upgrade_db(): void {
	$installed = (string) get_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION, '' );

	if ( ERANKLY_REDIRECTS_DB_VERSION === $installed ) {
		return;
	}

	ERankly_Redirects_Activator::activate();
	update_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION, ERANKLY_REDIRECTS_DB_VERSION, false );
}

/**
 * Purges full-page caches after a redirect mutation.
 *
 * The redirect engine runs on parse_request, so a page served from a full-page
 * cache never reaches PHP and keeps showing the pre-redirect HTML (or a stale
 * redirect) until that cache expires. Called by the repository after every
 * create/update/delete/toggle — including CSV/JSON imports, which go through
 * the same repository methods. Runs at most once per request.
 *
 * @return void
 */
function erankly_redirects_flush_external_caches(): void {
	static $flushed = false;

	if ( $flushed ) {
		return;
	}

	$flushed = true;

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
	 * Fires after the known full-page caches have been purged following a
	 * redirect mutation. Hook custom cache stacks (CDN, reverse proxy) here.
	 *
	 * @since 1.0.0
	 */
	do_action( 'erankly_redirects_caches_flushed' );
}

/**
 * Renders the redirect management UI for the settings page.
 *
 * Only outputs the full management interface when the feature is enabled and
 * the admin handler has been booted.
 *
 * @return void
 */
function erankly_redirects_render_panel(): void {
	if ( ! erankly_redirects_enabled() ) {
		return;
	}

	$admin = $GLOBALS['erankly_redirects_admin'] ?? null;

	if ( $admin instanceof ERankly_Redirects_Admin ) {
		$admin->render_panel();
	}
}
