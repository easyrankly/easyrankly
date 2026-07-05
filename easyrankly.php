<?php
/**
 * Plugin Name: EasyRankly
 * Plugin URI:  https://easyrankly.com
 * Description: Lightweight, modular, developer-first SEO essentials for WordPress.
 * Version:     2.0.0
 * Requires at least: 6.2.0
 * Requires PHP: 8.0
 * Author:      EasyRankly
 * Author URI:  https://easyrankly.com/
 * Text Domain: easyrankly
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * Network:     true
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bail if WordPress loads this file twice in one request (e.g. during a ZIP update).
if ( defined( 'ERANKLY_VERSION' ) ) {
	return;
}

define( 'ERANKLY_VERSION', '2.0.0' );
define( 'ERANKLY_FILE', __FILE__ );
define( 'ERANKLY_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERANKLY_URL', plugin_dir_url( __FILE__ ) );
define( 'ERANKLY_OPTION', 'erankly_settings' );
// Special-page metadata is stored per site on Multisite (see erankly_get_site_special_meta()).
define( 'ERANKLY_SPECIAL_META_OPTION', 'erankly_special_meta' );
define( 'ERANKLY_VERSION_OPTION', 'erankly_version' );
define( 'ERANKLY_SETUP_STATUS_OPTION', 'erankly_setup_wizard_status' );
define( 'ERANKLY_REWRITE_FLUSH_OPTION', 'erankly_flush_rewrite_rules' );
define( 'ERANKLY_SITEMAP_TRANSIENT_PREFIX', 'erankly_sitemap_' );
define( 'ERANKLY_SITEMAP_CACHE_VERSION_OPTION', 'erankly_sitemap_cache_version' );

require_once ERANKLY_PATH . 'includes/helpers.php';

/**
 * Gets a plugin option using network storage on Multisite.
 *
 * @param string $key           Option name.
 * @param mixed  $default_value Default value.
 * @return mixed
 */
function erankly_get_plugin_option( string $key, mixed $default_value = false ): mixed {
	return is_multisite() ? get_site_option( $key, $default_value ) : get_option( $key, $default_value );
}

/**
 * Updates a plugin option using network storage on Multisite.
 *
 * @param string $key   Option name.
 * @param mixed  $value Value to store.
 * @return void
 */
function erankly_update_plugin_option( string $key, mixed $value ): void {
	if ( is_multisite() ) {
		update_site_option( $key, $value );
	} else {
		// The settings array is read on every request, so autoload it; other options aren't.
		update_option( $key, $value, ERANKLY_OPTION === $key );
	}

	if ( ERANKLY_OPTION === $key ) {
		erankly_clear_settings_cache();
	}
}
require_once ERANKLY_PATH . 'includes/compatibility.php';
require_once ERANKLY_PATH . 'includes/meta.php';
require_once ERANKLY_PATH . 'includes/robots.php';
require_once ERANKLY_PATH . 'includes/redirects.php';
require_once ERANKLY_PATH . 'includes/seo-checklist.php';
require_once ERANKLY_PATH . 'includes/special-meta.php';
// Loaded unconditionally so the AI generation REST route is registered on the
// front-end rest_api_init too. The feature self-disables when the WordPress 7.0
// Connectors/AI Client APIs are absent or no provider is connected.
require_once ERANKLY_PATH . 'includes/ai.php';

if ( is_admin() ) {
	require_once ERANKLY_PATH . 'includes/admin.php';
}

/**
 * Boots the plugin after all plugins are available for compatibility checks.
 *
 * @return void
 */
function erankly_bootstrap(): void {
	add_action( 'init', 'erankly_register_meta' );
	add_action( 'init', 'erankly_register_rewrites' );
	add_action( 'init', 'erankly_maybe_flush_after_upgrade', 20 );
	add_action( 'init', 'erankly_maybe_flush_rewrite_rules', 30 );

	if ( is_multisite() ) {
		require_once ERANKLY_PATH . 'includes/multilingual.php';
		if ( erankly_multilingual_enabled() ) {
			erankly_ml_boot();
		}
	}

	if ( erankly_bloat_enabled() ) {
		require_once ERANKLY_PATH . 'includes/bloat.php';
		erankly_bloat_bootstrap();
	}

	// These core sitemap filters run unless another SEO plugin owns sitemaps, so per-post
	// "Hide from search results" still applies in /wp-sitemap.xml even when our Sitemap module is off.
	if ( ! erankly_should_suppress_sitemaps() ) {
		require_once ERANKLY_PATH . 'includes/sitemap/core.php';
		add_filter( 'wp_sitemaps_posts_query_args', 'erankly_filter_core_sitemap_posts_query_args', 20, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', 'erankly_filter_core_sitemap_terms_query_args', 20, 2 );
		add_filter( 'wp_sitemaps_post_types', 'erankly_filter_core_sitemap_post_types', 20 );
		add_filter( 'wp_sitemaps_taxonomies', 'erankly_filter_core_sitemap_taxonomies', 20 );
		add_filter( 'wp_sitemaps_add_provider', 'erankly_filter_core_sitemap_add_provider', 20, 2 );

		if ( erankly_sitemap_enabled() ) {
			// Specialised sitemaps (image, video, news) that require non-standard XML
			// namespaces are still served as EasyRankly virtual files. Each implementation
			// file is parsed only when its feature is enabled, so unused sitemap types add
			// no per-request cost.
			if ( (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) ) {
				require_once ERANKLY_PATH . 'includes/sitemap/news.php';
			}

			if ( (bool) erankly_get_setting( 'enable_image_sitemap', 0 ) ) {
				require_once ERANKLY_PATH . 'includes/sitemap/image.php';
			}

			if ( (bool) erankly_get_setting( 'enable_video_sitemap', 0 ) ) {
				require_once ERANKLY_PATH . 'includes/sitemap/video.php';
			}

			require_once ERANKLY_PATH . 'includes/class-erankly-specialist-sitemaps-provider.php';
			add_action(
				'init',
				function () {
					wp_register_sitemap_provider( 'erankly', new ERankly_Specialist_Sitemaps_Provider() );
				}
			);
			add_action( 'template_redirect', 'erankly_maybe_render_virtual_files', 0 );
		}
	}

	if ( erankly_sitemap_enabled() ) {
		// Cache invalidation for specialised sitemaps (image / video / news).
		add_action( 'save_post', 'erankly_flush_sitemap_cache_for_post' );
		add_action( 'deleted_post', 'erankly_flush_sitemap_cache_for_deleted_post' );
		add_action( 'transition_post_status', 'erankly_flush_sitemap_cache_for_status', 10, 3 );
		add_action( 'profile_update', 'erankly_flush_sitemap_cache' );
		add_action( 'user_register', 'erankly_flush_sitemap_cache' );
		add_action( 'deleted_user', 'erankly_flush_sitemap_cache' );
		add_action( 'created_term', 'erankly_flush_sitemap_cache' );
		add_action( 'edited_term', 'erankly_flush_sitemap_cache' );
		add_action( 'delete_term', 'erankly_flush_sitemap_cache' );
		add_action( 'added_term_meta', 'erankly_flush_sitemap_cache_for_term_meta', 10, 3 );
		add_action( 'updated_term_meta', 'erankly_flush_sitemap_cache_for_term_meta', 10, 3 );
		add_action( 'deleted_term_meta', 'erankly_flush_sitemap_cache_for_term_meta', 10, 3 );
	}

	if ( erankly_health_enabled() ) {
		require_once ERANKLY_PATH . 'includes/health.php';
		erankly_health_boot();
	}

	if ( is_admin() ) {
		erankly_admin_bootstrap();
	}

	if ( erankly_is_frontend_html_request() ) {
		add_action( 'wp', 'erankly_bootstrap_frontend_modules', 1 );
	}

	add_action( 'rest_api_init', 'erankly_register_user_search_route' );
	add_action( 'rest_api_init', 'erankly_register_settings_autosave_route' );
	add_action( 'rest_api_init', 'erankly_register_special_pages_autosave_route' );
	add_action( 'rest_api_init', 'erankly_register_special_meta_setting', 5 );
	add_filter( 'robots_txt', 'erankly_filter_robots_txt', 20, 2 );
	add_action( 'parse_request', 'erankly_force_robots_txt_request' );
	add_action( 'pre_get_posts', 'erankly_filter_visibility_queries' );
	add_action( 'added_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );
	add_action( 'updated_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );
	add_action( 'deleted_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );

	if ( is_multisite() ) {
		add_action( 'update_site_option_' . ERANKLY_OPTION, 'erankly_handle_network_settings_updated', 10, 3 );
		add_action( 'wp_initialize_site', 'erankly_on_new_blog_site' );
	} else {
		add_action( 'update_option_' . ERANKLY_OPTION, 'erankly_handle_settings_updated', 10, 2 );
	}
}
add_action( 'plugins_loaded', 'erankly_bootstrap', 5 );

/**
 * Loads frontend-only modules after WordPress has resolved an HTML request.
 *
 * REST requests normally terminate before the wp hook, so they do not parse the
 * canonical, social, schema, or breadcrumb implementations.
 */
function erankly_bootstrap_frontend_modules(): void {
	if ( (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
	} elseif ( ! function_exists( 'erankly_breadcrumbs' ) ) {
		/**
		 * Preserves the public template API while the breadcrumb module is off.
		 *
		 * @param array<string,mixed> $args Ignored arguments.
		 * @return string
		 */
		function erankly_breadcrumbs( array $args = array() ): string {
			unset( $args );
			return '';
		}
	}

	if ( ! function_exists( 'easyrankly_breadcrumbs' ) && function_exists( 'erankly_breadcrumbs' ) ) {
		// Legacy public function kept for backward compatibility.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		/**
		 * Legacy alias for the public breadcrumbs template function.
		 *
		 * @param array<string,mixed> $args Arguments.
		 * @return string
		 */
		function easyrankly_breadcrumbs( array $args = array() ): string {
			return erankly_breadcrumbs( $args );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	}

	// template_redirect runs after this wp:1 action, so the callback is defined in time.
	if ( 'none' !== (string) erankly_get_setting( 'attachment_redirect', 'none' ) ) {
		add_action( 'template_redirect', 'erankly_redirect_attachment' );
	}

	if ( ! erankly_should_output_head() ) {
		return;
	}

	require_once ERANKLY_PATH . 'includes/canonical.php';
	require_once ERANKLY_PATH . 'includes/opengraph.php';
	require_once ERANKLY_PATH . 'includes/schema.php';

	remove_action( 'wp_head', 'rel_canonical' );
	remove_action( 'wp_head', 'wp_generator' );
	add_filter( 'the_generator', '__return_empty_string' );
	add_filter( 'pre_get_document_title', 'erankly_filter_document_title', 20 );
	add_filter( 'document_title_parts', 'erankly_filter_document_title_parts', 20 );
	add_action( 'wp_head', 'erankly_render_head', 1 );
	add_filter( 'wp_robots', 'erankly_filter_wp_robots', 20 );
}

/**
 * Runs on plugin activation.
 *
 * @return void
 */
function erankly_activate(): void {
	$is_new_install = false === erankly_get_plugin_option( ERANKLY_OPTION, false );

	if ( is_multisite() ) {
		if ( $is_new_install ) {
			add_site_option( ERANKLY_OPTION, erankly_default_settings() );
		}

		add_site_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );
	} else {
		if ( $is_new_install ) {
			add_option( ERANKLY_OPTION, erankly_default_settings(), '', 'yes' );
		}

		add_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION, '', 'no' );
	}

	if ( $is_new_install ) {
		erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'pending' );
	}

	erankly_register_rewrites();
	flush_rewrite_rules();
}
register_activation_hook( ERANKLY_FILE, 'erankly_activate' );

/**
 * Schedules a rewrite-rules flush whenever the plugin version changes.
 *
 * Runs on every init but exits immediately when the stored version matches.
 * On a version mismatch (i.e. after a plugin update) it updates the stored
 * version and sets the flush flag so erankly_maybe_flush_rewrite_rules()
 * rebuilds the rules on the same request.
 *
 * @return void
 */
function erankly_maybe_flush_after_upgrade(): void {
	$stored = (string) erankly_get_plugin_option( ERANKLY_VERSION_OPTION, '' );

	if ( ERANKLY_VERSION === $stored ) {
		return;
	}

	erankly_update_plugin_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );

	if ( is_multisite() ) {
		foreach (
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id
		) {
			switch_to_blog( (int) $site_id );
			update_option( ERANKLY_REWRITE_FLUSH_OPTION, 1, false );
			restore_current_blog();
		}
	} else {
		update_option( ERANKLY_REWRITE_FLUSH_OPTION, 1, false );
	}
}

/**
 * Adapter for the update_site_option_ hook, which passes args in a different order.
 *
 * @param string $option    Option name.
 * @param mixed  $value     New value.
 * @param mixed  $old_value Previous value.
 * @return void
 */
function erankly_handle_network_settings_updated( string $option, mixed $value, mixed $old_value ): void {
	erankly_handle_settings_updated( $old_value, $value );
}

/**
 * Initialises rewrite rules for a newly created network site.
 *
 * @param WP_Site $new_site The newly created site object.
 * @return void
 */
function erankly_on_new_blog_site( WP_Site $new_site ): void {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active_for_network( plugin_basename( ERANKLY_FILE ) ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	erankly_register_rewrites();
	flush_rewrite_rules( false );
	restore_current_blog();
}

/**
 * Handles settings updates that affect feature bootstrapping.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New option value.
 * @return void
 */
function erankly_handle_settings_updated( mixed $old_value, mixed $value ): void {
	erankly_clear_settings_cache();

	$old_sitemap_enabled = is_array( $old_value ) && ! empty( $old_value['enable_sitemap'] );
	$new_sitemap_enabled = is_array( $value ) && ! empty( $value['enable_sitemap'] );

	if ( $old_sitemap_enabled !== $new_sitemap_enabled ) {
		erankly_flush_sitemap_cache();

		if ( is_multisite() ) {
			foreach (
				get_sites(
					array(
						'fields' => 'ids',
						'number' => 0,
					)
				) as $site_id
			) {
				switch_to_blog( (int) $site_id );
				update_option( ERANKLY_REWRITE_FLUSH_OPTION, 1, false );
				restore_current_blog();
			}
		} else {
			update_option( ERANKLY_REWRITE_FLUSH_OPTION, 1, false );
		}

		return;
	}

	if ( $new_sitemap_enabled ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Flushes rewrite rules after feature settings have changed.
 *
 * @return void
 */
function erankly_maybe_flush_rewrite_rules(): void {
	if ( ! (bool) get_option( ERANKLY_REWRITE_FLUSH_OPTION, 0 ) ) {
		return;
	}

	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	flush_rewrite_rules( false );
}

/**
 * Runs on plugin deactivation.
 *
 * @return void
 */
function erankly_deactivate(): void {
	// Clear the daily 404-retention cron; erankly_health_boot() reschedules it when active.
	wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );

	if ( is_multisite() ) {
		foreach (
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $erankly_site_id
		) {
			switch_to_blog( (int) $erankly_site_id );
			erankly_flush_sitemap_cache();
			flush_rewrite_rules();
			restore_current_blog();
		}
	} else {
		erankly_flush_sitemap_cache();
		flush_rewrite_rules();
	}
}
register_deactivation_hook( ERANKLY_FILE, 'erankly_deactivate' );

/**
 * Registers the REST route for the admin user search autocomplete.
 *
 * @return void
 */
function erankly_register_user_search_route(): void {
	register_rest_route(
		'erankly/v1',
		'/users/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'erankly_rest_user_search',
			'permission_callback' => static fn() => current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' ),
			'args'                => array(
				'q' => array(
					'default'           => '',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

/**
 * Handles the user search REST request.
 *
 * Returns up to 20 users matching the query. Network-wide lookups (blog_id = 0)
 * are reserved for users who can manage the whole network; a regular site admin
 * is scoped to the members of their own site so they cannot enumerate every
 * account on the network. On single-site the blog_id is ignored.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function erankly_rest_user_search( WP_REST_Request $request ): WP_REST_Response {
	$query = (string) $request->get_param( 'q' );

	// Only network managers may search across every site; everyone else is
	// limited to their current site, which on single-site means all users.
	$blog_id = ( is_multisite() && ! current_user_can( 'manage_network_options' ) )
		? get_current_blog_id()
		: 0;

	$args = array(
		'blog_id' => $blog_id, // 0 = network-wide on multisite; ignored on single-site.
		'number'  => 20,
		'orderby' => 'display_name',
		'order'   => 'ASC',
		'fields'  => array( 'ID', 'display_name', 'user_email' ),
	);

	if ( '' !== $query ) {
		$args['search']         = '*' . $query . '*';
		$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
	}

	$users   = get_users( $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_users_get_users -- intentional admin-only user lookup with strict capability check.
	$results = array();

	foreach ( $users as $user ) {
		if ( ! isset( $user->ID, $user->display_name ) ) {
			continue;
		}

		if ( isset( $user->user_email ) && '' !== $user->user_email ) {
			$meta = (string) $user->user_email;
		} else {
			/* translators: %d: User ID. */
			$meta = sprintf( __( 'ID %d', 'easyrankly' ), (int) $user->ID );
		}

		$results[] = array(
			'id'     => (int) $user->ID,
			/* translators: 1: User display name, 2: User ID. */
			'text'   => sprintf( __( '%1$s (ID: %2$d)', 'easyrankly' ), $user->display_name, $user->ID ),
			'name'   => (string) $user->display_name,
			'meta'   => $meta,
			'avatar' => (string) get_avatar_url( (int) $user->ID, array( 'size' => 48 ) ),
		);
	}

	return new WP_REST_Response( $results, 200 );
}

/**
 * Registers the REST route that autosaves settings panels.
 *
 * One route serves every autosave-enabled panel (see
 * erankly_settings_autosave_panels() in admin/settings-page.php for the
 * per-panel whitelist registry); the `panel` slug is validated against that
 * registry inside the handler, not the route pattern, so this never needs
 * editing again as panels are added — the char class is just a safe charset
 * for a path segment, not an allowlist (the registry lookup is what actually
 * prevents an unknown or cross-panel request from touching anything).
 *
 * @return void
 */
function erankly_register_settings_autosave_route(): void {
	register_rest_route(
		'erankly/v1',
		'/settings/(?P<panel>[a-z-]+)',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'erankly_rest_save_settings_panel',
			// Multisite stores these settings network-wide (see erankly_get_settings()),
			// so editing them there requires the network capability, not just the
			// per-site one — a subsite admin must not be able to reach this route.
			'permission_callback' => static fn() => current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ),
			'args'                => array(
				'settings' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
}

/**
 * Saves a partial payload from a settings panel autosave.
 *
 * Looks up the requested panel in erankly_settings_autosave_panels(), merges
 * its whitelisted fields onto the currently stored settings (so panels that
 * aren't part of this request are left untouched), optionally runs a
 * panel-specific normalize hook, then runs the result through the same
 * sanitizer the full options.php submission uses and persists it. Several of
 * those admin-only helpers (erankly_use_site_editor_special_page_panels(),
 * add_settings_error()/get_settings_errors()) aren't loaded on a bare REST
 * request the way they are on wp-admin requests, so they're pulled in on
 * demand here.
 *
 * On Multisite, erankly_get_settings()/erankly_update_plugin_option() already
 * route through the network-wide site option regardless of which admin
 * screen the request came from, so no Network Admin detection is needed
 * here — the permission_callback is what keeps subsite admins out.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function erankly_rest_save_settings_panel( WP_REST_Request $request ) {
	require_once ERANKLY_PATH . 'includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/template.php';
	require_once ERANKLY_PATH . 'admin/settings-page.php';

	$panel_key = sanitize_key( (string) $request['panel'] );
	$registry  = erankly_settings_autosave_panels();

	if ( ! isset( $registry[ $panel_key ] ) ) {
		return new WP_Error( 'erankly_unknown_settings_panel', __( 'Unknown settings panel.', 'easyrankly' ), array( 'status' => 404 ) );
	}

	$panel_config = $registry[ $panel_key ];
	$payload      = (array) $request->get_param( 'settings' );
	$changes      = array_intersect_key( $payload, array_flip( $panel_config['keys'] ) );
	$merged       = array_merge( erankly_get_settings(), $changes );

	if ( ! empty( $panel_config['normalize'] ) ) {
		$merged = call_user_func( $panel_config['normalize'], $merged, $changes );
	}

	$sanitized = erankly_sanitize_settings( $merged );

	erankly_update_plugin_option( ERANKLY_OPTION, $sanitized );

	return new WP_REST_Response(
		array(
			'saved'    => true,
			'warnings' => wp_list_pluck( get_settings_errors( ERANKLY_OPTION ), 'message' ),
		),
		200
	);
}

/**
 * Registers the REST route that autosaves the per-site "Special pages and
 * archives" panel — the Multisite fallback for sites that can't use the Site
 * Editor panels (see erankly_use_site_editor_special_page_panels()). Kept
 * separate from erankly_register_settings_autosave_route(): this panel
 * doesn't merge into ERANKLY_OPTION/erankly_get_settings() the way every
 * other panel does — erankly_update_special_meta_map() already owns reading,
 * sanitizing and writing this data (a dedicated per-site option on
 * Multisite), so it doesn't fit the shared registry's shape.
 *
 * @return void
 */
function erankly_register_special_pages_autosave_route(): void {
	register_rest_route(
		'erankly/v1',
		'/settings/special-pages',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'erankly_rest_save_special_pages',
			// Always per-site, even on Multisite: this panel is only ever
			// shown to a per-site admin (see $is_site_admin_on_network in
			// admin/settings-page.php), never to Network Admin, so it doesn't
			// need the manage_network_options ternary the other route uses.
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'args'                => array(
				'settings' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
}

/**
 * Saves the "Special pages and archives" autosave payload.
 *
 * Uses erankly_update_special_meta_map() (includes/special-meta.php, always
 * loaded), which already sanitizes its input and routes the write to the
 * correct storage, so the whitelisted map is passed straight through with no merge
 * step — unlike erankly_rest_save_settings_panel(), there's no risk of this
 * payload clobbering another panel's fields since this data isn't part of
 * ERANKLY_OPTION on Multisite at all.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function erankly_rest_save_special_pages( WP_REST_Request $request ): WP_REST_Response {
	$payload = (array) $request->get_param( 'settings' );
	$map     = isset( $payload['global_special_meta'] ) && is_array( $payload['global_special_meta'] ) ? $payload['global_special_meta'] : array();

	erankly_update_special_meta_map( $map );

	return new WP_REST_Response(
		array(
			'saved'    => true,
			'warnings' => array(),
		),
		200
	);
}
