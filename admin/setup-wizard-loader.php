<?php
/**
 * Lightweight first-run setup wizard loader.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the capability required to manage the wizard.
 *
 * @return string
 */
function erankly_setup_wizard_capability(): string {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Returns a setup wizard URL.
 *
 * @param string $step Optional wizard step.
 * @return string
 */
function erankly_setup_wizard_url( string $step = '' ): string {
	$url = is_multisite()
		? network_admin_url( 'settings.php?page=erankly-setup' )
		: admin_url( 'options-general.php?page=erankly-setup' );

	if ( '' !== $step ) {
		$url = add_query_arg( 'step', sanitize_key( $step ), $url );
	}

	return $url;
}

/**
 * Returns the settings URL for the current installation type.
 *
 * @return string
 */
function erankly_setup_wizard_settings_url(): string {
	return is_multisite()
		? network_admin_url( 'settings.php?page=erankly' )
		: admin_url( 'options-general.php?page=erankly' );
}

/**
 * Registers the hidden setup page.
 *
 * The page is kept registered but removed from the visible submenu. Without a
 * submenu entry WordPress cannot resolve the screen title, so the load hook
 * below restores `$title` before admin-header.php calls strip_tags().
 *
 * @return void
 */
function erankly_setup_wizard_register_page(): void {
	$parent_slug = is_network_admin() ? 'settings.php' : 'options-general.php';

	$hook = add_submenu_page(
		$parent_slug,
		__( 'EasyRankly setup', 'easyrankly' ),
		__( 'EasyRankly setup', 'easyrankly' ),
		erankly_setup_wizard_capability(),
		'erankly-setup',
		'erankly_setup_wizard_render'
	);

	remove_submenu_page( $parent_slug, 'erankly-setup' );

	if ( is_string( $hook ) && '' !== $hook ) {
		add_action( "load-{$hook}", 'erankly_setup_wizard_set_admin_title' );
	}
}

/**
 * Sets the admin screen title for the hidden setup wizard page.
 *
 * @return void
 */
function erankly_setup_wizard_set_admin_title(): void {
	global $title;

	$title = __( 'EasyRankly setup', 'easyrankly' );
}

/**
 * Redirects administrators to the wizard after a fresh installation.
 *
 * @return void
 */
function erankly_setup_wizard_maybe_redirect(): void {
	global $pagenow;

	if ( 'pending' !== erankly_get_plugin_option( ERANKLY_SETUP_STATUS_OPTION, '' ) ) {
		return;
	}

	if ( is_multisite() && ! is_network_admin() ) {
		return;
	}

	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		return;
	}

	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'get' !== $request_method ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	if ( 'erankly-setup' === $page ) {
		return;
	}

	if ( in_array( $pagenow, array( 'plugins.php', 'plugin-install.php', 'update.php', 'update-core.php', 'admin-post.php' ), true ) ) {
		return;
	}

	wp_safe_redirect( erankly_setup_wizard_url() );
	exit;
}

/**
 * Loads the wizard form processor and renderer only when requested.
 *
 * @return void
 */
function erankly_setup_wizard_load_screen(): void {
	require_once ERANKLY_PATH . 'admin/setup-wizard.php';
}

/**
 * Saves the setup choices through the deferred implementation.
 *
 * @return void
 */
function erankly_setup_wizard_save(): void {
	erankly_setup_wizard_load_screen();
	erankly_setup_wizard_handle_save();
}

/**
 * Dismisses the setup wizard through the deferred implementation.
 *
 * @return void
 */
function erankly_setup_wizard_skip(): void {
	erankly_setup_wizard_load_screen();
	erankly_setup_wizard_handle_skip();
}

/**
 * Renders the setup wizard through the deferred implementation.
 *
 * @return void
 */
function erankly_setup_wizard_render(): void {
	erankly_setup_wizard_load_screen();
	erankly_setup_wizard_render_screen();
}
