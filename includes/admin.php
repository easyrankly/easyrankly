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
 * Boots admin features.
 *
 * @return void
 */
function erankly_admin_bootstrap(): void {
	require_once ERANKLY_PATH . 'admin/setup-wizard.php';

	if ( is_multisite() ) {
		add_action( 'network_admin_menu', 'erankly_admin_register_network_settings_page' );
		add_action( 'network_admin_menu', 'erankly_setup_wizard_register_page' );
		add_action( 'network_admin_edit_erankly_network_save', 'erankly_admin_save_network_settings' );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_network_plugin_action_links' );
		add_action( 'admin_menu', 'erankly_admin_register_site_settings_page' );
		// ML sites form is submitted to a separate network action endpoint.
		add_action( 'network_admin_edit_erankly_ml_sites_save', 'erankly_admin_ml_sites_save' );
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
	add_action( 'save_post', 'erankly_admin_save_meta_box', 10, 2 );
	add_action( 'admin_enqueue_scripts', 'erankly_admin_enqueue_assets' );
}

/**
 * Loads modules used exclusively by the EasyRankly settings screen.
 *
 * @return void
 */
function erankly_admin_load_settings_modules(): void {
	require_once ERANKLY_PATH . 'admin/settings-page.php';
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	require_once ERANKLY_PATH . 'includes/import-export.php';
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
 * Registers the per-site settings menu on Multisite when needed.
 *
 * @return void
 */
function erankly_admin_register_site_settings_page(): void {
	if ( ! erankly_redirects_enabled() && ! erankly_health_enabled() ) {
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
 * Loads post editor code only when WordPress registers meta boxes.
 *
 * @return void
 */
function erankly_admin_register_meta_boxes(): void {
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
	$has_action = isset( $_GET['erankly_io_action'] ) || isset( $_POST['erankly_io_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- The module verifies the action-specific nonce before mutation.

	if ( 'erankly' !== $page && ! $has_action ) {
		return;
	}

	// Load the full settings modules, not just import-export.php: a JSON import
	// restores settings through erankly_sanitize_settings(), which lives in
	// settings-page.php. On Multisite no other admin_init callback loads it, so
	// requiring only the import module would silently skip the settings restore.
	erankly_admin_load_settings_modules();
	erankly_import_export_handle_actions();
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

	if ( ! $is_settings && ! $is_setup && ! $is_editor && ! $is_taxonomy ) {
		return;
	}

	if ( $is_block_editor ) {
		erankly_admin_enqueue_block_editor_assets();
		return;
	}

	wp_enqueue_style(
		'erankly-admin',
		ERANKLY_URL . 'assets/css/admin.css',
		array(),
		ERANKLY_VERSION
	);

	if ( $is_setup ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_script(
		'erankly-admin',
		ERANKLY_URL . 'assets/js/admin.js',
		array(),
		ERANKLY_VERSION,
		true
	);

	if ( $is_settings ) {
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

	if ( $is_settings && erankly_redirects_enabled() ) {
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
				'deleteConfirm' => __( 'Delete this redirect?', 'easyrankly' ),
			)
		);
	}

	$ml_active = is_multisite() && function_exists( 'erankly_multilingual_enabled' ) && erankly_multilingual_enabled();

	// The editor/taxonomy screens use the cross-site search; the Network Admin
	// settings screen uses the language-map table (default-site radios).
	$ml_on_editor   = $ml_active && ( $is_editor || $is_taxonomy );
	$ml_on_settings = $ml_active && $is_settings && is_network_admin();

	if ( $ml_on_editor || $ml_on_settings ) {
		wp_enqueue_style(
			'erankly-multilingual',
			ERANKLY_URL . 'assets/css/multilingual.css',
			array( 'erankly-admin' ),
			ERANKLY_VERSION
		);

		wp_enqueue_script(
			'erankly-multilingual',
			ERANKLY_URL . 'assets/js/multilingual.js',
			array(),
			ERANKLY_VERSION,
			true
		);

		// The localized search config is only needed on the editor screens.
		if ( $ml_on_editor ) {
			wp_localize_script(
				'erankly-multilingual',
				'eranklyML',
				array(
					'restUrl' => esc_url_raw( rest_url( 'erankly/v1/ml/search' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'searching' => __( 'Searching…', 'easyrankly' ),
						'noResults' => __( 'No matches found.', 'easyrankly' ),
						'remove'    => __( 'Remove', 'easyrankly' ),
					),
				)
			);
		}
	}
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

	wp_enqueue_style(
		'erankly-editor',
		ERANKLY_URL . 'assets/css/editor.css',
		array( 'wp-components' ),
		ERANKLY_VERSION
	);

	wp_enqueue_script(
		'erankly-editor',
		ERANKLY_URL . 'assets/js/editor.js',
		array(
			'wp-api-fetch',
			'wp-block-editor',
			'wp-components',
			'wp-data',
			'wp-edit-post',
			'wp-element',
			'wp-i18n',
			'wp-plugins',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-editor', 'easyrankly', ERANKLY_PATH . 'languages' );

	wp_localize_script(
		'erankly-editor',
		'eranklyEditor',
		array(
			'breadcrumbsEnabled'            => (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ),
			'newsSitemapEnabled'            => (bool) erankly_get_setting( 'enable_news_sitemap', 0 ),
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
			'multilingual'                  => is_multisite() && function_exists( 'erankly_multilingual_enabled' ) && erankly_multilingual_enabled(),
			'translationSearchPath'         => '/erankly/v1/ml/search',
		)
	);
}

/**
 * Dispatches the network ML sites save action.
 *
 * @return void
 */
function erankly_admin_ml_sites_save(): void {
	if ( ! function_exists( 'erankly_multilingual_enabled' ) ) {
		require_once ERANKLY_PATH . 'includes/multilingual.php';
		erankly_ml_boot();
	}

	$admin = $GLOBALS['erankly_ml_admin'] ?? null;
	if ( $admin instanceof ERankly_ML_Admin ) {
		$admin->save_ml_sites();
	}
}
