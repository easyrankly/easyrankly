<?php
/** Admin bootstrap. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether the current WordPress version exposes the unified editor slotfills needed by the Site
 * Editor special-page panels.
 *
 * @return bool True when the Site Editor panels can be used.
 */
function erankly_site_editor_special_page_panels_supported(): bool {
	global $wp_version;

	return version_compare( (string) $wp_version, '6.6', '>=' );
}

/**
 * Determines whether special-page SEO defaults should be edited in the Site Editor instead of the classic
 * EasyRankly settings fallback.
 *
 * @return bool True when the contextual Site Editor panels are available.
 */
function erankly_use_site_editor_special_page_panels(): bool {
	return wp_is_block_theme() && erankly_site_editor_special_page_panels_supported();
}

function erankly_admin_bootstrap(): void {
	if ( is_multisite() ) {
		add_action( 'network_admin_menu', 'erankly_admin_register_network_settings_page' );
		add_action( 'network_admin_edit_erankly_network_save', 'erankly_admin_save_network_settings' );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_network_plugin_action_links' );
		add_action( 'admin_menu', 'erankly_admin_register_site_settings_page' );
		// Per-site special-page metadata falls back to the subsite settings
		// page unless the Site Editor panels are available.
		add_action( 'admin_post_erankly_save_site_special_meta', 'erankly_admin_save_site_special_meta' );
	} else {
		add_action( 'admin_menu', 'erankly_admin_register_settings_page' );
		add_action( 'admin_init', 'erankly_admin_maybe_register_settings' );
		add_filter( 'plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_plugin_action_links' );
	}

	add_action( 'add_meta_boxes', 'erankly_admin_register_meta_boxes' );
	add_action( 'admin_init', 'erankly_admin_maybe_register_taxonomy_fields' );
	add_action( 'admin_init', 'erankly_admin_maybe_handle_import_export' );
	add_action( 'admin_init', 'erankly_admin_maybe_handle_reset' );
	add_action( 'save_post', 'erankly_admin_save_meta_box', 10, 2 );
	add_action( 'admin_enqueue_scripts', 'erankly_admin_enqueue_assets' );
}

function erankly_admin_load_settings_modules(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/settings-page.php';
	require_once ERANKLY_PATH . 'admin/settings/nav-icons.php';
	require_once ERANKLY_PATH . 'admin/settings/section-links.php';
	require_once ERANKLY_PATH . 'admin/settings/panels.php';
	require_once ERANKLY_PATH . 'admin/settings/page-renderer.php';
}

/** Loads the Import / Export controller and migration UI on demand. */
function erankly_admin_load_import_export_module(): void {
	require_once ERANKLY_PATH . 'includes/import-export.php';
}

function erankly_admin_load_reset_module(): void {
	require_once ERANKLY_PATH . 'includes/reset.php';
}

/**
 * Returns the requested top-level settings tab. This deliberately performs only request routing. Availability
 * and capability checks remain in erankly_render_settings_page().
 */
function erankly_admin_requested_settings_tab(): string {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	return isset( $_GET['erankly_tab'] )
		? sanitize_key( wp_unslash( $_GET['erankly_tab'] ) )
		: 'general';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Resolves a requested settings tab to a panel that can exist in this context. Unknown slugs are preserved for
 * extension tabs registered through the public erankly_settings_tabs filter.
 */
function erankly_admin_resolve_settings_tab( string $requested_tab ): string {
	$is_site_admin_on_network = is_multisite() && ! is_network_admin();

	if ( $is_site_admin_on_network ) {
		$site_tabs = array();

		if ( ! erankly_use_site_editor_special_page_panels() ) {
			$site_tabs[] = 'special-pages';
		}
		if ( erankly_redirects_enabled() ) {
			$site_tabs[] = 'redirects';
		}

		/** Filters the per-site settings tabs available on Multisite. */
		$site_tabs = apply_filters( 'erankly_admin_site_settings_tabs', $site_tabs );
		$site_tabs = is_array( $site_tabs ) ? array_values( array_filter( $site_tabs, 'is_string' ) ) : array();

		return in_array( $requested_tab, $site_tabs, true )
			? $requested_tab
			: ( $site_tabs[0] ?? '' );
	}

	if ( 'advanced' === $requested_tab && (bool) erankly_get_setting( 'simplified_mode', 1 ) ) {
		return 'settings';
	}

	$unavailable = (
		( 'sitemap' === $requested_tab && ! erankly_sitemap_enabled() )
		|| ( 'redirects' === $requested_tab && ( is_network_admin() || ! erankly_redirects_enabled() ) )
		|| ( 'custom-code' === $requested_tab && ! erankly_custom_code_enabled() )
		|| ( 'special-pages' === $requested_tab )
	);

	return $unavailable ? 'features' : $requested_tab;
}

function erankly_admin_register_settings_page(): void {
	$hook = add_options_page(
		__( 'EasyRankly', 'easyrankly' ),
		__( 'EasyRankly', 'easyrankly' ),
		'manage_options',
		'erankly',
		'erankly_admin_render_settings_page'
	);

	erankly_admin_hook_settings_tab_canonicalization( $hook );
}

/**
 * Hooks the canonical tab redirect on a settings screen.
 *
 * @param string|false $hook Page hook suffix returned by the menu registration.
 */
function erankly_admin_hook_settings_tab_canonicalization( $hook ): void {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return;
	}

	add_action( 'load-' . $hook, 'erankly_admin_canonicalize_settings_tab' );
}

/**
 * Sends the browser to the tab that will actually render.
 *
 * The resolver silently substitutes a tab that is not available here (a disabled module, Redirects in Network
 * Admin, or Advanced while Simplified mode is on). Without this the address bar kept naming the requested tab
 * while a different panel was on screen, so bookmarks and copied links pointed at a page that never renders.
 */
function erankly_admin_canonicalize_settings_tab(): void {
	$requested = erankly_admin_requested_settings_tab();

	if ( '' === $requested ) {
		return;
	}

	$resolved = erankly_admin_resolve_settings_tab( $requested );

	// A resolved tab that would itself resolve elsewhere would bounce forever.
	if ( '' === $resolved || $resolved === $requested || erankly_admin_resolve_settings_tab( $resolved ) !== $resolved ) {
		return;
	}

	wp_safe_redirect( add_query_arg( 'erankly_tab', $resolved ) );
	exit;
}

/** Registers the Network Admin settings menu. */
function erankly_admin_register_network_settings_page(): void {
	$hook = add_submenu_page(
		'settings.php',
		__( 'EasyRankly', 'easyrankly' ),
		__( 'EasyRankly', 'easyrankly' ),
		'manage_network_options',
		'erankly',
		'erankly_admin_render_settings_page'
	);

	erankly_admin_hook_settings_tab_canonicalization( $hook );
}

/**
 * Registers the per-site settings menu on Multisite. Classic themes and block themes before WordPress 6.6 expose
 * the special-page fallback. Block themes on WordPress 6.6+ register this page only when a per-site module such
 * as Redirects is enabled, or an add-on reports one via `erankly_admin_site_settings_modules_enabled`.
 * Import/Export stays network-admin-only on Multisite.
 */
function erankly_admin_register_site_settings_page(): void {
	if (
		erankly_use_site_editor_special_page_panels()
		&& ! erankly_redirects_enabled()
		&& ! apply_filters( 'erankly_admin_site_settings_modules_enabled', false )
	) {
		return;
	}

	erankly_admin_register_settings_page();
}

function erankly_admin_render_settings_page(): void {
	erankly_admin_load_settings_modules();

	$tab          = erankly_admin_requested_settings_tab();
	$resolved_tab = erankly_admin_resolve_settings_tab( $tab );

	// Load against the *resolved* tab: assets already do. Keying off the raw
	// query value meant a bookmark to a tab that resolves elsewhere (Advanced
	// with Simplified mode on resolves to Settings) rendered the Settings panel
	// without its Reset module, while the Reset scripts were enqueued anyway.
	if ( 'import-export' === $resolved_tab ) {
		erankly_admin_load_import_export_module();
	} elseif ( 'settings' === $resolved_tab ) {
		erankly_admin_load_reset_module();
	}

	erankly_render_settings_page();
}

/** Loads the settings registration callback only for relevant requests. */
function erankly_admin_maybe_register_settings(): void {
	global $pagenow;

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( 'options.php' !== $pagenow && 'erankly' !== $page ) {
		return;
	}

	erankly_admin_load_settings_modules();
	erankly_register_settings();
}

/** Loads and handles the Network Admin save action. */
function erankly_admin_save_network_settings(): void {
	erankly_admin_load_settings_modules();
	erankly_save_network_settings();
}

function erankly_admin_save_site_special_meta(): void {
	erankly_admin_load_settings_modules();
	erankly_save_site_special_meta();
}

/** Loads post editor code only when WordPress registers meta boxes. */
function erankly_admin_register_meta_boxes(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_register_meta_box();
}

/** Loads taxonomy editor code only on taxonomy screens. */
function erankly_admin_maybe_register_taxonomy_fields(): void {
	global $pagenow;

	if ( ! in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true ) ) {
		return;
	}

	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_register_taxonomy_fields();
}

/** Loads post meta saving code only when a post is actually saved. */
function erankly_admin_save_meta_box( int $post_id, WP_Post $post ): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_save_meta_box( $post_id, $post );
}

/** Loads import/export code only for its settings request. */
function erankly_admin_maybe_handle_import_export(): void {
	$page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	$tab        = erankly_admin_requested_settings_tab();
	$has_action = isset( $_GET['erankly_io_action'] ) || isset( $_POST['erankly_io_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- The module verifies the action-specific nonce before mutation.

	if ( ! $has_action && ( 'erankly' !== $page || 'import-export' !== $tab ) ) {
		return;
	}
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	// Load the full settings modules, not just import-export.php: a JSON import
	// restores settings through erankly_sanitize_settings(), which lives in
	// settings-page.php. On Multisite no other admin_init callback loads it, so
	// requiring only the import module would silently skip the settings restore.
	erankly_admin_load_settings_modules();
	erankly_admin_load_import_export_module();
	erankly_import_export_handle_actions();
}

/** Loads reset code only for its settings request. */
function erankly_admin_maybe_handle_reset(): void {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( 'erankly' !== $page || ! isset( $_POST['erankly_reset_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The module verifies the action-specific nonce before mutation.
		return;
	}
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	// Load the full settings modules: erankly_default_settings() lives in
	// includes/helpers/defaults.php, which is pulled in transitively by
	// settings-page.php, and a reset must restore the same defaults a fresh
	// install would register.
	erankly_admin_load_settings_modules();
	erankly_admin_load_reset_module();
	erankly_reset_handle_actions();
}

/** @return array<int,string> */
function erankly_plugin_action_links( array $links ): array {
	return erankly_add_plugin_action_links( $links, admin_url( 'options-general.php?page=erankly' ) );
}

/**
 * Adds plugin action links in the Network Admin plugins list.
 *
 * @return array<int,string>
 */
function erankly_network_plugin_action_links( array $links ): array {
	return erankly_add_plugin_action_links( $links, network_admin_url( 'settings.php?page=erankly' ) );
}

/**
 * @param string            $settings_url Settings page URL for the current context.
 * @return array<int,string>
 */
function erankly_add_plugin_action_links( array $links, string $settings_url ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $settings_url ),
		esc_html__( 'Settings', 'easyrankly' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Renders the shared expand/collapse toggle button for an expandable table panel (see .erankly-panel-* in
 * admin-core.css and bindExpandablePanel() in admin.js). Used by the Redirects, Broken-Link, and Frequent 404
 * sections.
 *
 * @param string $target_id ID of the [data-erankly-expandable] section it controls.
 */
function erankly_admin_render_panel_expand_toggle( string $target_id ): void {
	?>
	<button type="button" class="button erankly-panel-expand-toggle" data-erankly-expand-toggle aria-pressed="false" aria-controls="<?php echo esc_attr( $target_id ); ?>" title="<?php esc_attr_e( 'Expand table', 'easyrankly' ); ?>">
		<svg class="erankly-panel-expand-icon-expand" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
			<path d="M8 3H5a2 2 0 0 0-2 2v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
			<path d="M21 8V5a2 2 0 0 0-2-2h-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
			<path d="M3 16v3a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
			<path d="M16 21h3a2 2 0 0 0 2-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
		</svg>
		<svg class="erankly-panel-expand-icon-collapse" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
			<path d="M8 3v3a2 2 0 0 1-2 2H3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
			<path d="M21 8h-3a2 2 0 0 1-2-2V3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
			<path d="M3 16h3a2 2 0 0 1 2 2v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
			<path d="M16 21v-3a2 2 0 0 1 2-2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
		</svg>
		<span class="screen-reader-text"><?php esc_html_e( 'Expand table', 'easyrankly' ); ?></span>
	</button>
	<?php
}

/** Enqueues shared design tokens and cross-surface components. */
function erankly_enqueue_shared_styles(): void {
	wp_enqueue_style(
		'erankly-shared',
		ERANKLY_URL . 'assets/css/shared.css',
		array(),
		ERANKLY_VERSION
	);
}

/**
 * Enqueues the modular admin script bundle. Modules attach helpers to `window.ERanklyAdmin`; admin.js bootstraps
 * them on DOMContentLoaded. Returns the bootstrap handle for localize_script().
 */
function erankly_admin_enqueue_scripts( array $requested_modules ): string {
	$registry = array(
		'media'     => array( 'erankly-admin-media', 'admin-media.js' ),
		'tabs'      => array( 'erankly-admin-tabs', 'admin-tabs.js' ),
		'fields'    => array( 'erankly-admin-fields', 'admin-fields.js' ),
		'variables' => array( 'erankly-admin-variables', 'admin-variables.js' ),
		'schema'    => array( 'erankly-admin-schema', 'admin-schema.js' ),
		'widgets'   => array( 'erankly-admin-widgets', 'admin-widgets.js' ),
		'settings'  => array( 'erankly-admin-settings', 'admin-settings.js' ),
		'panels'    => array( 'erankly-admin-panels', 'admin-panels.js' ),
		'reset'     => array( 'erankly-admin-reset', 'admin-reset.js' ),
	);
	$deps     = array();
	$selected = array_values( array_unique( $requested_modules ) );

	foreach ( $selected as $module ) {
		if ( ! isset( $registry[ $module ] ) ) {
			continue;
		}

		list( $handle, $file ) = $registry[ $module ];
		$module_deps = array();

		if ( 'schema' === $module ) {
			wp_enqueue_script(
				'erankly-schema-jsonld',
				ERANKLY_URL . 'assets/js/schema-jsonld.js',
				array( 'wp-i18n' ),
				ERANKLY_VERSION,
				true
			);
			wp_set_script_translations( 'erankly-schema-jsonld', 'easyrankly', ERANKLY_PATH . 'languages' );
			$module_deps[] = 'erankly-schema-jsonld';
		}

		wp_enqueue_script(
			$handle,
			ERANKLY_URL . 'assets/js/' . $file,
			$module_deps,
			ERANKLY_VERSION,
			true
		);
		$deps[] = $handle;
	}

	wp_enqueue_script(
		'erankly-admin',
		ERANKLY_URL . 'assets/js/admin.js',
		$deps,
		ERANKLY_VERSION,
		true
	);

	return 'erankly-admin';
}

/** @return array<int,string> */
function erankly_admin_asset_modules( string $surface ): array {
	$settings_modules = array(
		'general'       => array( 'tabs', 'variables', 'schema', 'widgets', 'settings' ),
		'features'      => array( 'tabs', 'settings' ),
		'social'        => array( 'tabs', 'media', 'variables', 'settings' ),
		'schema'        => array( 'tabs', 'variables', 'schema', 'widgets', 'settings' ),
		'sitemap'       => array( 'tabs', 'settings' ),
		'custom-code'   => array( 'tabs', 'variables', 'schema', 'settings' ),
		'settings'      => array( 'tabs', 'settings', 'reset' ),
		'advanced'      => array( 'tabs', 'variables', 'settings' ),
		'import-export' => array( 'tabs', 'fields' ),
		'redirects'     => array( 'tabs', 'panels' ),
		'special-pages' => array( 'tabs', 'media', 'variables', 'settings' ),
	);

	if ( str_starts_with( $surface, 'settings:' ) ) {
		$tab = substr( $surface, strlen( 'settings:' ) );

		// Add-on tabs historically received the complete bundle. Keep that public
		// compatibility surface while core tabs use the strict manifest above.
		$modules = $settings_modules[ $tab ] ?? array_keys(
			array(
				'media'     => true,
				'tabs'      => true,
				'fields'    => true,
				'variables' => true,
				'schema'    => true,
				'widgets'   => true,
				'settings'  => true,
				'panels'    => true,
			)
		);
	} else {
		$surfaces = array(
			'classic-editor' => array( 'media', 'fields', 'variables', 'schema', 'panels' ),
			'taxonomy'       => array( 'media', 'fields', 'variables', 'schema', 'panels' ),
		);

		$modules = $surfaces[ $surface ] ?? array();
	}

	/** @param string            $surface Surface identifier such as "settings:general". */
	$modules = apply_filters( 'erankly_admin_asset_modules', $modules, $surface );

	return is_array( $modules ) ? array_values( array_filter( $modules, 'is_string' ) ) : array();
}

require_once ERANKLY_PATH . 'admin/assets/settings.php';
require_once ERANKLY_PATH . 'admin/assets/editor.php';
