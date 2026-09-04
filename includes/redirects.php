<?php
/** Redirect module. This file is required only when the redirect manager feature is enabled. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/helpers/redirect-cache.php';

/** Database schema version for the redirects table. */
define( 'ERANKLY_REDIRECTS_DB_VERSION', '2.0.0' );

/** Option name tracking the installed redirects table version. */
define( 'ERANKLY_REDIRECTS_DB_VERSION_OPTION', 'erankly_redirects_db_version' );

function erankly_redirects_boot(): void {
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-normalizer.php';
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-activator.php';
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-repository.php';
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-runner.php';
	require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-rest.php';
	erankly_redirects_maybe_upgrade_db();

	$repository = new ERankly_Redirects_Repository();

	$runner = new ERankly_Redirects_Runner( $repository );
	$runner->register_hooks();

	// Registered unconditionally (not gated to the settings page) because REST
	// requests hit /wp-json/ directly rather than options-general.php?page=erankly.
	$rest = new ERankly_Redirects_Rest( $repository );
	$rest->register_hooks();

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( is_admin() && 'erankly' === $page ) {
		require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-admin.php';

		$admin = new ERankly_Redirects_Admin( $repository );
		$admin->register_hooks();

		// Shared instance for the settings page redirect management renderer.
		$GLOBALS['erankly_redirects_admin'] = $admin;
	}
}

/**
 * Creates the redirects table on first use and ensures the schema is current. Runs every time the module boots;
 * the version-string comparison is the idempotency guard that prevents re-running dbDelta on an already
 * up-to-date table.
 */
function erankly_redirects_maybe_upgrade_db(): void {
	$installed = (string) get_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION, '' );

	if ( ERANKLY_REDIRECTS_DB_VERSION === $installed ) {
		return;
	}

	ERankly_Redirects_Activator::activate();
	( new ERankly_Redirects_Repository() )->invalidate_runtime_rules();
	erankly_rotate_redirects_cache_generation();
	update_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION, ERANKLY_REDIRECTS_DB_VERSION, false );
}

/**
 * Renders the redirect management UI for the settings page. Only outputs the full management interface when the
 * feature is enabled and the admin handler has been booted.
 */
function erankly_redirects_render_panel(): void {
	$admin = $GLOBALS['erankly_redirects_admin'] ?? null;

	if ( $admin instanceof ERankly_Redirects_Admin ) {
		$admin->render_panel();
	}
}
