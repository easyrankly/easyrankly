<?php
/**
 * Admin bootstrap.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether the current WordPress version exposes the unified editor
 * slotfills needed by the Site Editor special-page panels.
 *
 * @return bool True when the Site Editor panels can be used.
 */
function erankly_site_editor_special_page_panels_supported(): bool {
	global $wp_version;

	return version_compare( (string) $wp_version, '6.6', '>=' );
}

/**
 * Determines whether special-page SEO defaults should be edited in the Site
 * Editor instead of the classic EasyRankly settings fallback.
 *
 * @return bool True when the contextual Site Editor panels are available.
 */
function erankly_use_site_editor_special_page_panels(): bool {
	return wp_is_block_theme() && erankly_site_editor_special_page_panels_supported();
}

/**
 * Boots admin features.
 *
 * @return void
 */
function erankly_admin_bootstrap(): void {
	require_once ERANKLY_PATH . 'admin/setup-wizard-loader.php';

	if ( is_multisite() ) {
		add_action( 'network_admin_menu', 'erankly_admin_register_network_settings_page' );
		add_action( 'network_admin_menu', 'erankly_setup_wizard_register_page' );
		add_action( 'network_admin_edit_erankly_network_save', 'erankly_admin_save_network_settings' );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_network_plugin_action_links' );
		add_action( 'admin_menu', 'erankly_admin_register_site_settings_page' );
		// Per-site special-page metadata falls back to the subsite settings
		// page unless the Site Editor panels are available.
		add_action( 'admin_post_erankly_save_site_special_meta', 'erankly_admin_save_site_special_meta' );
	} else {
		add_action( 'admin_menu', 'erankly_admin_register_settings_page' );
		add_action( 'admin_menu', 'erankly_setup_wizard_register_page' );
		add_action( 'admin_init', 'erankly_admin_maybe_register_settings' );
		add_filter( 'plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_plugin_action_links' );
	}

	add_action( 'admin_init', 'erankly_setup_wizard_maybe_redirect' );
	add_action( 'admin_post_erankly_setup_save', 'erankly_setup_wizard_save' );
	add_action( 'admin_post_erankly_setup_skip', 'erankly_setup_wizard_skip' );
	add_action( 'add_meta_boxes', 'erankly_admin_register_meta_boxes' );
	add_action( 'admin_init', 'erankly_admin_maybe_register_taxonomy_fields' );
	add_action( 'admin_init', 'erankly_admin_maybe_handle_import_export' );
	add_action( 'admin_init', 'erankly_admin_maybe_handle_reset' );
	add_action( 'save_post', 'erankly_admin_save_meta_box', 10, 2 );
	add_action( 'admin_enqueue_scripts', 'erankly_admin_enqueue_assets' );
}

/**
 * Loads modules used exclusively by the EasyRankly settings screen.
 *
 * @return void
 */
function erankly_admin_load_settings_modules(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/settings-page.php';
	require_once ERANKLY_PATH . 'admin/settings/panels.php';
}

/**
 * Loads the Import / Export controller and migration UI on demand.
 *
 * @return void
 */
function erankly_admin_load_import_export_module(): void {
	require_once ERANKLY_PATH . 'includes/import-export.php';
}

/**
 * Loads destructive reset handlers and their renderer on demand.
 *
 * @return void
 */
function erankly_admin_load_reset_module(): void {
	require_once ERANKLY_PATH . 'includes/reset.php';
}

/**
 * Returns the requested top-level settings tab.
 *
 * This deliberately performs only request routing. Availability and
 * capability checks remain in erankly_render_settings_page().
 *
 * @return string
 */
function erankly_admin_requested_settings_tab(): string {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	return isset( $_GET['erankly_tab'] )
		? sanitize_key( wp_unslash( $_GET['erankly_tab'] ) )
		: 'general';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Resolves a requested settings tab to a panel that can exist in this context.
 *
 * Unknown slugs are preserved for extension tabs registered through the public
 * erankly_settings_tabs filter.
 *
 * @param string $requested_tab Requested tab slug.
 * @return string
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

		/**
		 * Filters the per-site settings tabs available on Multisite.
		 *
		 * @param array<int,string> $site_tabs Tab slugs.
		 */
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
		|| ( 'special-pages' === $requested_tab )
	);

	return $unavailable ? 'features' : $requested_tab;
}

/**
 * Registers the single-site settings menu without loading its renderer.
 *
 * @return void
 */
function erankly_admin_register_settings_page(): void {
	add_options_page(
		__( 'EasyRankly', 'easyrankly' ),
		__( 'EasyRankly', 'easyrankly' ),
		'manage_options',
		'erankly',
		'erankly_admin_render_settings_page'
	);
}

/**
 * Registers the Network Admin settings menu.
 *
 * @return void
 */
function erankly_admin_register_network_settings_page(): void {
	add_submenu_page(
		'settings.php',
		__( 'EasyRankly', 'easyrankly' ),
		__( 'EasyRankly', 'easyrankly' ),
		'manage_network_options',
		'erankly',
		'erankly_admin_render_settings_page'
	);
}

/**
 * Registers the per-site settings menu on Multisite.
 *
 * Classic themes and block themes before WordPress 6.6 expose the special-page
 * fallback. Block themes on WordPress 6.6+ register this page only when a
 * per-site module such as Redirects is enabled, or an add-on reports one via
 * `erankly_admin_site_settings_modules_enabled`. Import/Export stays
 * network-admin-only on Multisite.
 *
 * @return void
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

/**
 * Loads and renders the settings screen on demand.
 *
 * @return void
 */
function erankly_admin_render_settings_page(): void {
	erankly_admin_load_settings_modules();

	$tab          = erankly_admin_requested_settings_tab();
	$resolved_tab = erankly_admin_resolve_settings_tab( $tab );

	if ( 'import-export' === $tab ) {
		erankly_admin_load_import_export_module();
	} elseif ( 'settings' === $tab ) {
		erankly_admin_load_reset_module();
	}

	erankly_render_settings_page();
}

/**
 * Loads the settings registration callback only for relevant requests.
 *
 * @return void
 */
function erankly_admin_maybe_register_settings(): void {
	global $pagenow;

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( 'options.php' !== $pagenow && 'erankly' !== $page ) {
		return;
	}

	erankly_admin_load_settings_modules();
	erankly_register_settings();
}

/**
 * Loads and handles the Network Admin save action.
 *
 * @return void
 */
function erankly_admin_save_network_settings(): void {
	erankly_admin_load_settings_modules();
	erankly_save_network_settings();
}

/**
 * Loads and handles the per-site special-page metadata save action.
 *
 * @return void
 */
function erankly_admin_save_site_special_meta(): void {
	erankly_admin_load_settings_modules();
	erankly_save_site_special_meta();
}

/**
 * Loads post editor code only when WordPress registers meta boxes.
 *
 * @return void
 */
function erankly_admin_register_meta_boxes(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_register_meta_box();
}

/**
 * Loads taxonomy editor code only on taxonomy screens.
 *
 * @return void
 */
function erankly_admin_maybe_register_taxonomy_fields(): void {
	global $pagenow;

	if ( ! in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true ) ) {
		return;
	}

	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_register_taxonomy_fields();
}

/**
 * Loads post meta saving code only when a post is actually saved.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function erankly_admin_save_meta_box( int $post_id, WP_Post $post ): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_save_meta_box( $post_id, $post );
}

/**
 * Loads import/export code only for its settings request.
 *
 * @return void
 */
function erankly_admin_maybe_handle_import_export(): void {
	$page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	$tab        = erankly_admin_requested_settings_tab();
	$has_action = isset( $_GET['erankly_io_action'] ) || isset( $_POST['erankly_io_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- The module verifies the action-specific nonce before mutation.

	if ( ! $has_action && ( 'erankly' !== $page || 'import-export' !== $tab ) ) {
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

/**
 * Loads reset code only for its settings request.
 *
 * @return void
 */
function erankly_admin_maybe_handle_reset(): void {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( 'erankly' !== $page || ! isset( $_POST['erankly_reset_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The module verifies the action-specific nonce before mutation.
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

/**
 * Adds plugin action links on single-site installs.
 *
 * @param array<int,string> $links Plugin links.
 * @return array<int,string>
 */
function erankly_plugin_action_links( array $links ): array {
	return erankly_add_plugin_action_links( $links, admin_url( 'options-general.php?page=erankly' ) );
}

/**
 * Adds plugin action links in the Network Admin plugins list.
 *
 * @param array<int,string> $links Plugin links.
 * @return array<int,string>
 */
function erankly_network_plugin_action_links( array $links ): array {
	return erankly_add_plugin_action_links( $links, network_admin_url( 'settings.php?page=erankly' ) );
}

/**
 * Prepends the Settings and Setup wizard links to a plugin action links list.
 *
 * @param array<int,string> $links        Plugin links.
 * @param string            $settings_url Settings page URL for the current context.
 * @return array<int,string>
 */
function erankly_add_plugin_action_links( array $links, string $settings_url ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $settings_url ),
		esc_html__( 'Settings', 'easyrankly' )
	);
	$setup_link    = sprintf(
		'<a href="%s">%s</a>',
		esc_url( erankly_setup_wizard_url( 'configure' ) ),
		esc_html__( 'Setup wizard', 'easyrankly' )
	);

	array_unshift( $links, $settings_link, $setup_link );

	return $links;
}

/**
 * Renders the shared expand/collapse toggle button for an expandable table
 * panel (see .erankly-panel-* in admin-core.css and bindExpandablePanel() in
 * admin.js). Used by the Redirects, Broken-Link, and Frequent 404 sections.
 *
 * @param string $target_id ID of the [data-erankly-expandable] section it controls.
 * @return void
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

/**
 * Enqueues shared design tokens and cross-surface components.
 *
 * @return void
 */
function erankly_enqueue_shared_styles(): void {
	wp_enqueue_style(
		'erankly-shared',
		ERANKLY_URL . 'assets/css/shared.css',
		array(),
		ERANKLY_VERSION
	);
}

/**
 * Enqueues the modular admin script bundle.
 *
 * Modules attach helpers to `window.ERanklyAdmin`; admin.js bootstraps them on
 * DOMContentLoaded. Returns the bootstrap handle for localize_script().
 *
 * @param array<int,string> $requested_modules Module identifiers.
 * @return string Script handle (`erankly-admin`).
 */
function erankly_admin_enqueue_scripts( array $requested_modules ): string {
	$registry = array(
		'media'     => array( 'erankly-admin-media', 'admin-media.js' ),
		'checklist' => array( 'erankly-admin-checklist', 'admin-checklist.js' ),
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
		wp_enqueue_script(
			$handle,
			ERANKLY_URL . 'assets/js/' . $file,
			array(),
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

/**
 * Returns the EasyRankly JavaScript modules needed by one admin surface.
 *
 * @param string $surface Surface identifier.
 * @return array<int,string>
 */
function erankly_admin_asset_modules( string $surface ): array {
	$settings_modules = array(
		'general'       => array( 'tabs', 'variables', 'schema', 'widgets', 'settings' ),
		'features'      => array( 'tabs', 'settings' ),
		'social'        => array( 'tabs', 'media', 'variables', 'settings' ),
		'schema'        => array( 'tabs', 'variables', 'schema', 'widgets', 'settings' ),
		'sitemap'       => array( 'tabs', 'settings' ),
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
			'setup'          => array( 'widgets' ),
			'classic-editor' => array( 'media', 'checklist', 'tabs', 'fields', 'variables', 'schema', 'panels' ),
			'taxonomy'       => array( 'media', 'tabs', 'fields', 'variables', 'schema', 'panels' ),
		);

		$modules = $surfaces[ $surface ] ?? array();
	}

	/**
	 * Filters the JS modules enqueued for one admin surface.
	 *
	 * @param array<int,string> $modules Module slugs.
	 * @param string            $surface Surface identifier such as "settings:general".
	 */
	$modules = apply_filters( 'erankly_admin_asset_modules', $modules, $surface );

	return is_array( $modules ) ? array_values( array_filter( $modules, 'is_string' ) ) : array();
}

/**
 * Enqueues admin assets only where needed.
 *
 * @param string $hook_suffix Admin hook.
 * @return void
 */
function erankly_admin_enqueue_assets( string $hook_suffix ): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen ) {
		return;
	}

	$is_settings     = 'settings_page_erankly' === $hook_suffix;
	$is_setup        = isset( $_GET['page'] ) && 'erankly-setup' === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	$is_editor       = in_array( $screen->post_type, array_keys( erankly_get_public_post_types() ), true );
	$is_taxonomy     = in_array( $screen->taxonomy, array_keys( erankly_get_public_taxonomies() ), true );
	$is_block_editor = $is_editor && $screen->is_block_editor();
	$is_site_editor  = 'site-editor' === $screen->base;

	if ( ! $is_settings && ! $is_setup && ! $is_editor && ! $is_taxonomy && ! $is_site_editor ) {
		return;
	}

	erankly_load_content_helpers();

	if ( $is_site_editor ) {
		erankly_admin_enqueue_site_editor_assets();
		return;
	}

	if ( $is_block_editor ) {
		erankly_admin_enqueue_block_editor_assets();
		return;
	}

	$settings_tab = $is_settings ? erankly_admin_resolve_settings_tab( erankly_admin_requested_settings_tab() ) : '';
	$surface      = $is_settings ? 'settings:' . $settings_tab : '';

	if ( $is_setup ) {
		$surface = 'setup';
	} elseif ( $is_taxonomy ) {
		$surface = 'taxonomy';
	} elseif ( $is_editor ) {
		$surface = 'classic-editor';
	}

	$asset_modules = erankly_admin_asset_modules( $surface );

	erankly_enqueue_shared_styles();

	wp_enqueue_style(
		'erankly-admin',
		ERANKLY_URL . 'assets/css/admin-core.css',
		array( 'erankly-shared' ),
		ERANKLY_VERSION
	);

	// Fields, tabs, schema builders and other admin components are shared by
	// settings, the classic editor, and taxonomy screens. Keep one canonical
	// implementation instead of duplicating it in classic-editor.css.
	if ( ! $is_setup ) {
		wp_enqueue_style( 'erankly-admin-settings', ERANKLY_URL . 'assets/css/admin-settings.css', array( 'erankly-admin' ), ERANKLY_VERSION );
	}

	if ( $is_settings ) {
		if ( 'import-export' === $settings_tab ) {
			wp_enqueue_style( 'erankly-migration', ERANKLY_URL . 'assets/css/migration.css', array( 'erankly-admin-settings' ), ERANKLY_VERSION );
		}

		if ( 'settings' === $settings_tab ) {
			wp_enqueue_style( 'erankly-reset', ERANKLY_URL . 'assets/css/reset.css', array( 'erankly-admin-settings' ), ERANKLY_VERSION );
		}
	} elseif ( $is_setup ) {
		wp_enqueue_style( 'erankly-setup', ERANKLY_URL . 'assets/css/setup.css', array( 'erankly-admin' ), ERANKLY_VERSION );
	} else {
		wp_enqueue_style( 'erankly-classic-editor', ERANKLY_URL . 'assets/css/classic-editor.css', array( 'erankly-admin-settings' ), ERANKLY_VERSION );
	}

	erankly_admin_enqueue_scripts( $asset_modules );

	// The wizard's "Person reference user" field reuses the searchable
	// user-search widget from the General settings panel (see bindUserSearch()
	// in admin.js), so it needs the same restUrl/nonce localized here.
	if ( $is_setup || ( $is_settings && 'general' === $settings_tab ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklyUserSearch',
			array(
				'restUrl' => esc_url_raw( rest_url( 'erankly/v1/users/search' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'searching'  => __( 'Searching…', 'easyrankly' ),
					'noResults'  => __( 'No matches found.', 'easyrankly' ),
					'remove'     => __( 'Remove', 'easyrankly' ),
					'noSelected' => __( 'No user selected', 'easyrankly' ),
				),
			)
		);
	}

	if ( $is_setup ) {
		wp_enqueue_script(
			'erankly-setup',
			ERANKLY_URL . 'assets/js/setup-wizard.js',
			array( 'erankly-admin' ),
			ERANKLY_VERSION,
			true
		);
		return;
	}

	if ( in_array( 'media', $asset_modules, true ) ) {
		wp_enqueue_media();
		do_action( 'erankly_admin_media_enqueued', $surface );
	}

	// Drives the "show resolved value, revert to raw {{token}} on focus"
	// behavior for every plain PHP-rendered {{variable}} field (settings
	// page defaults, classic meta boxes, term forms). See bindVariablePicker()
	// in admin.js. The block editor's React fields get the same preference
	// through eranklyEditor/eranklySiteEditor instead.
	if ( in_array( 'variables', $asset_modules, true ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklyVariablePreview',
			array(
				'resolvePlaceholders' => (bool) erankly_get_setting( 'resolve_placeholders', 1 ),
				'siteName'            => get_bloginfo( 'name' ),
			)
		);
	}

	if ( $is_editor && ! $is_block_editor ) {
		$post = get_post();

		if ( $post instanceof WP_Post ) {
			require_once ERANKLY_PATH . 'admin/meta-box.php';

			wp_localize_script(
				'erankly-admin',
				'eranklyChecklist',
				array_merge(
					array(
						'descriptionPlaceholder' => erankly_get_post_global_meta_placeholder( $post, 'description', ERANKLY_SEO_CHECKLIST_DESCRIPTION_LIMIT ),
						'simplifiedMode'         => (bool) erankly_get_setting( 'simplified_mode', 1 ),
						'siteName'               => get_bloginfo( 'name' ),
						'titlePlaceholder'       => erankly_get_post_global_meta_placeholder( $post, 'title', ERANKLY_SEO_CHECKLIST_TITLE_LIMIT ),
					),
					erankly_get_seo_checklist_editor_config( $post )
				)
			);
		}
	}

	// Strings for the shared expandable table panel (bindExpandablePanel).
	if ( in_array( 'panels', $asset_modules, true ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklyPanels',
			array(
				'expand'   => __( 'Expand table', 'easyrankly' ),
				'collapse' => __( 'Collapse table', 'easyrankly' ),
			)
		);
	}

	// Panels autosave via REST as they're wired up (see
	// erankly_settings_autosave_panels() in admin/settings-page.php); each
	// entry below is added the same turn its panel starts autosaving, so the
	// JS bootstrap (bindSettingsAutosave in admin.js) only ever binds panels
	// that actually have a config here. It is unconditional on multisite/network
	// state because different panels are reachable in each: General/Features/
	// etc. only on single-site or Network Admin, but Special pages is the
	// opposite (only a per-site admin on Multisite ever sees that tab).
	if ( $is_settings && in_array( 'settings', $asset_modules, true ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklySettingsAutosave',
			array(
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'i18n'   => array(
					'saving'  => __( 'Saving…', 'easyrankly' ),
					'saved'   => __( 'Saved', 'easyrankly' ),
					'warning' => __( 'Saved with warnings', 'easyrankly' ),
					'retry'   => __( 'Saving failed. Retrying…', 'easyrankly' ),
					'error'   => __( 'Could not save. Reload the page.', 'easyrankly' ),
				),
				// Deliberately hand-written rather than derived from
				// erankly_settings_autosave_panels(): on Network Admin,
				// admin/settings-page.php isn't loaded yet at this point in the
				// request (only the page-render callback loads it, which runs
				// after admin_enqueue_scripts), so calling into that registry
				// here would fatal on Multisite.
				'panels' => apply_filters(
					'erankly_settings_autosave_client_panels',
					array_filter(
					array(
						'general'       => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/general' ) ) ),
						'advanced'      => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/advanced' ) ) ),
						'sitemap'       => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/sitemap' ) ) ),
						// Its checkboxes control which OTHER tabs are visible
						// (Redirects/Sitemap and add-on feature tabs), so the admin JS
						// refreshes the EasyRankly settings wrapper after a successful
						// save to pick up the updated PHP-rendered navigation.
						'features'      => array(
							'restUrl'      => esc_url_raw( rest_url( 'erankly/v1/settings/features' ) ),
							'reloadOnSave' => true,
						),
						// Simplified mode drives PHP-rendered markup across the whole
						// page (Advanced tab visibility, social/visibility defaults
						// rendered as hidden inputs), so like Features it needs the
						// settings wrapper refreshed after save.
						'settings'      => array(
							'restUrl'      => esc_url_raw( rest_url( 'erankly/v1/settings/settings' ) ),
							'reloadOnSave' => true,
						),
						'social'        => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/social' ) ) ),
						'schema'        => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/schema' ) ) ),
						// Multisite-only (a per-site admin's "General" tab there);
						// its own bespoke route, not part of erankly_settings_autosave_panels()
						// See erankly_rest_save_special_pages() in easyrankly.php.
						'special-pages' => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/special-pages' ) ) ),
					),
					'is_array'
				)
				),
			)
		);
	}

	if ( $is_settings && 'redirects' === $settings_tab && erankly_redirects_enabled() ) {
		wp_enqueue_style(
			'erankly-redirects',
			ERANKLY_URL . 'assets/css/redirects.css',
			array( 'erankly-admin' ),
			ERANKLY_VERSION
		);

		wp_enqueue_script(
			'erankly-redirects',
			ERANKLY_URL . 'assets/js/redirects.js',
			array(),
			ERANKLY_VERSION,
			true
		);

		wp_localize_script(
			'erankly-redirects',
			'eranklyRedirects',
			array(
				'restUrlToggle' => esc_url_raw( rest_url( 'erankly/v1/redirects/toggle' ) ),
				'restUrlDelete' => esc_url_raw( rest_url( 'erankly/v1/redirects/delete' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'deleteConfirm' => __( 'Delete this redirect?', 'easyrankly' ),
				'enableLabel'   => __( 'Enable', 'easyrankly' ),
				'disableLabel'  => __( 'Disable', 'easyrankly' ),
				'activeYes'     => __( 'Yes', 'easyrankly' ),
				'activeNo'      => __( 'No', 'easyrankly' ),
				'toggleError'   => __( 'The redirect status could not be changed.', 'easyrankly' ),
				'deleteError'   => __( 'The redirect could not be deleted.', 'easyrankly' ),
			)
		);
	}

	/**
	 * Fires after EasyRankly has enqueued its admin assets for this screen.
	 *
	 * @param array<string,mixed> $context Screen flags for add-ons.
	 */
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => $hook_suffix,
			'screen'          => $screen,
			'is_settings'     => $is_settings,
			'is_setup'        => $is_setup,
			'is_editor'       => $is_editor,
			'is_taxonomy'     => $is_taxonomy,
			'is_block_editor' => false,
			'is_site_editor'  => false,
			'settings_tab'    => $settings_tab,
		)
	);
}

/**
 * Enqueues the core accordion FAQ schema block extension.
 *
 * @return void
 */
function erankly_enqueue_accordion_faq_schema_assets(): void {
	wp_enqueue_script(
		'erankly-accordion-faq-schema',
		ERANKLY_URL . 'assets/js/accordion-faq-schema.js',
		array(
			'wp-block-editor',
			'wp-components',
			'wp-compose',
			'wp-element',
			'wp-hooks',
			'wp-i18n',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-accordion-faq-schema', 'easyrankly', ERANKLY_PATH . 'languages' );
}

/**
 * Enqueues the native document setting panels for the block editor.
 *
 * @return void
 */
function erankly_admin_enqueue_block_editor_assets(): void {
	$post = get_post();

	if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
		return;
	}

	require_once ERANKLY_PATH . 'admin/meta-box.php';

	erankly_enqueue_editor_shared_assets();
	erankly_enqueue_accordion_faq_schema_assets();

	$editor_deps = array(
		'erankly-editor-shared',
		'wp-api-fetch',
		'wp-block-editor',
		'wp-components',
		'wp-data',
		'wp-edit-post',
		'wp-editor',
		'wp-element',
		'wp-hooks',
		'wp-i18n',
		'wp-plugins',
	);

	wp_enqueue_script(
		'erankly-editor',
		ERANKLY_URL . 'assets/js/editor.js',
		$editor_deps,
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-editor', 'easyrankly', ERANKLY_PATH . 'languages' );

	wp_localize_script(
		'erankly-editor',
		'eranklyEditor',
		array_merge(
			array(
				'breadcrumbsEnabled'            => (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ),
				'newsSitemapEnabled'            => (bool) erankly_get_setting( 'enable_news_sitemap', 0 ),
				'resolvePlaceholders'           => (bool) erankly_get_setting( 'resolve_placeholders', 1 ),
				'simplifiedMode'                => (bool) erankly_get_setting( 'simplified_mode', 1 ),
				'siteIconUrl'                   => get_site_icon_url( 48 ),
				'siteName'                      => get_bloginfo( 'name' ),
				'titlePlaceholder'              => erankly_get_post_global_meta_placeholder( $post, 'title', 70 ),
				'descriptionPlaceholder'        => erankly_get_post_global_meta_placeholder( $post, 'description', 160 ),
				'ogTitlePlaceholder'            => erankly_get_post_global_social_placeholder( $post->ID, 'default_og_title', 60 ),
				'ogDescriptionPlaceholder'      => erankly_get_post_global_social_placeholder( $post->ID, 'default_og_description', 200 ),
				'twitterTitlePlaceholder'       => erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_title', 70 ),
				'twitterDescriptionPlaceholder' => erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_description', 200 ),
				'socialImagePlaceholder'        => erankly_get_post_global_social_placeholder( $post->ID, 'default_social_image_url', 2048 ),
				'variables'                     => erankly_get_variable_groups(),
			),
			erankly_get_seo_checklist_editor_config( $post )
		)
	);

	/**
	 * Fires after EasyRankly has enqueued its admin assets for this screen.
	 *
	 * @param array<string,mixed> $context Screen flags for add-ons.
	 */
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => '',
			'screen'          => get_current_screen(),
			'is_settings'     => false,
			'is_setup'        => false,
			'is_editor'       => true,
			'is_taxonomy'     => false,
			'is_block_editor' => true,
			'is_site_editor'  => false,
			'settings_tab'    => '',
		)
	);
}

/**
 * Enqueues the editor stylesheet and the shared editor component bundle.
 *
 * Shared by the post editor and the Site Editor so both load the same
 * presentational controls and field builders.
 *
 * @return void
 */
function erankly_enqueue_editor_shared_assets(): void {
	erankly_enqueue_shared_styles();

	wp_enqueue_style(
		'erankly-editor',
		ERANKLY_URL . 'assets/css/editor.css',
		array( 'erankly-shared', 'wp-components' ),
		ERANKLY_VERSION
	);

	wp_enqueue_script(
		'erankly-editor-shared',
		ERANKLY_URL . 'assets/js/editor-shared.js',
		array(
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-i18n',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-editor-shared', 'easyrankly', ERANKLY_PATH . 'languages' );
	wp_localize_script(
		'erankly-editor-shared',
		'eranklyEditorShared',
		array(
			'panelOrder' => array_values(
				array_filter(
					(array) apply_filters(
						'erankly_editor_panel_order',
						array(
							'erankly-panel--appearance',
							'erankly-panel--social',
							'erankly-panel--schema',
							'erankly-panel--visibility',
							'erankly-panel--checklist',
							'erankly-panel--translations',
						)
					),
					'is_string'
				)
			),
		)
	);
}

/**
 * Enqueues the Site Editor special-page panels.
 *
 * Adds EasyRankly SEO default panels to the template inspector when a block
 * theme template maps to a WordPress special-page context. Values bind to the
 * native root/site Core Data entity, so the Site Editor saves them together
 * with template changes. Requires WordPress 6.6+ for the unified editor
 * slotfills.
 *
 * @return void
 */
function erankly_admin_enqueue_site_editor_assets(): void {
	if ( ! erankly_site_editor_special_page_panels_supported() ) {
		return;
	}

	if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	require_once ERANKLY_PATH . 'admin/field-renderers.php';

	erankly_enqueue_editor_shared_assets();
	erankly_enqueue_accordion_faq_schema_assets();

	wp_enqueue_script(
		'erankly-site-editor',
		ERANKLY_URL . 'assets/js/site-editor.js',
		array(
			'erankly-editor-shared',
			'wp-api-fetch',
			'wp-block-editor',
			'wp-components',
			'wp-core-data',
			'wp-data',
			'wp-editor',
			'wp-element',
			'wp-hooks',
			'wp-i18n',
			'wp-plugins',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-site-editor', 'easyrankly', ERANKLY_PATH . 'languages' );

	wp_localize_script(
		'erankly-site-editor',
		'eranklySiteEditor',
		array(
			'contextLabels'                  => erankly_special_page_keys(),
			'descriptionPlaceholder'         => '',
			'homeUrl'                        => home_url( '/' ),
			'ogDescriptionPlaceholder'       => (string) erankly_get_setting( 'default_og_description', '' ),
			'ogTitlePlaceholder'             => (string) erankly_get_setting( 'default_og_title', '' ),
			'resolvePlaceholders'            => (bool) erankly_get_setting( 'resolve_placeholders', 1 ),
			'simplifiedMode'                 => (bool) erankly_get_setting( 'simplified_mode', 1 ),
			'siteIconUrl'                    => get_site_icon_url( 48 ),
			'siteName'                       => get_bloginfo( 'name' ),
			'socialImagePlaceholder'         => (string) erankly_get_setting( 'default_social_image_url', '' ),
			'specialDescriptionPlaceholders' => erankly_admin_get_site_editor_special_description_placeholders(),
			'specialMetaSetting'             => ERANKLY_SPECIAL_META_OPTION,
			'specialPreviewUrls'             => erankly_admin_get_site_editor_special_preview_urls(),
			'specialTitlePlaceholders'       => erankly_admin_get_site_editor_special_title_placeholders(),
			'titlePlaceholder'               => '',
			'twitterDescriptionPlaceholder'  => (string) erankly_get_setting( 'default_twitter_description', '' ),
			'twitterTitlePlaceholder'        => (string) erankly_get_setting( 'default_twitter_title', '' ),
			'variables'                      => erankly_get_variable_groups(),
		)
	);
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => '',
			'screen'          => get_current_screen(),
			'is_settings'     => false,
			'is_setup'        => false,
			'is_editor'       => false,
			'is_taxonomy'     => false,
			'is_block_editor' => false,
			'is_site_editor'  => true,
			'settings_tab'    => '',
		)
	);

}

/**
 * Returns example frontend URLs for Site Editor SERP previews.
 *
 * @return array<string,string>
 */
function erankly_admin_get_site_editor_special_preview_urls(): array {
	$posts_page_id = (int) get_option( 'page_for_posts' );
	$posts_page    = $posts_page_id > 0 ? get_permalink( $posts_page_id ) : '';
	$author_id     = get_current_user_id();

	return array(
		'homepage' => home_url( '/' ),
		'blog'     => is_string( $posts_page ) && '' !== $posts_page ? $posts_page : home_url( '/' ),
		'author'   => $author_id > 0 ? get_author_posts_url( $author_id ) : home_url( '/author/example/' ),
		'date'     => get_month_link( (int) gmdate( 'Y' ), (int) gmdate( 'm' ) ),
		'search'   => add_query_arg( 's', __( 'example', 'easyrankly' ), home_url( '/' ) ),
		'404'      => home_url( '/404-preview/' ),
	);
}

/**
 * Returns title fallbacks matching special-page frontend behavior closely enough
 * for a generic template preview.
 *
 * @return array<string,string>
 */
function erankly_admin_get_site_editor_special_title_placeholders(): array {
	return array(
		'homepage' => get_bloginfo( 'name' ),
		'blog'     => get_bloginfo( 'name' ),
		'author'   => __( 'Author archive', 'easyrankly' ),
		'date'     => __( 'Date archive', 'easyrankly' ),
		'search'   => sprintf(
			/* translators: %s: Search query. */
			__( 'Search results for %s', 'easyrankly' ),
			__( 'example', 'easyrankly' )
		),
		'404'      => __( 'Page not found', 'easyrankly' ),
	);
}

/**
 * Returns description fallbacks for Site Editor SERP previews.
 *
 * @return array<string,string>
 */
function erankly_admin_get_site_editor_special_description_placeholders(): array {
	$tagline = get_bloginfo( 'description' );

	return array(
		'homepage' => $tagline,
		'blog'     => $tagline,
		'author'   => '',
		'date'     => '',
		'search'   => '',
		'404'      => '',
	);
}
