<?php
/**
 * Plugin Name: EasyRankly
 * Plugin URI:  https://easyrankly.com
 * Description: Lightweight, modular, developer-first SEO essentials for WordPress.
 * Version:     2.0.0
 * Requires at least: 6.2
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
define( 'ERANKLY_EXTENSION_API_VERSION', 1 );
define( 'ERANKLY_FILE', __FILE__ );
define( 'ERANKLY_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERANKLY_URL', plugin_dir_url( __FILE__ ) );
define( 'ERANKLY_OPTION', 'erankly_settings' );
// Special-page metadata is stored per site on Multisite (see erankly_get_site_special_meta()).
define( 'ERANKLY_SPECIAL_META_OPTION', 'erankly_special_meta' );
define( 'ERANKLY_VERSION_OPTION', 'erankly_version' );
define( 'ERANKLY_RUNTIME_STATE_OPTION', 'erankly_runtime_state' );
define( 'ERANKLY_REWRITE_FLUSH_OPTION', 'erankly_flush_rewrite_rules' );
define( 'ERANKLY_SITEMAP_TRANSIENT_PREFIX', 'erankly_sitemap_' );
define( 'ERANKLY_SITEMAP_CACHE_VERSION_OPTION', 'erankly_sitemap_cache_version' );
define( 'ERANKLY_REWRITE_SIGNATURE_OPTION', 'erankly_rewrite_signature' );
define( 'ERANKLY_REWRITE_GENERATION_OPTION', 'erankly_rewrite_generation' );
define( 'ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION', 'erankly_redirects_cache_generation' );
define( 'ERANKLY_NETWORK_SITE_BATCH_SIZE', 100 );
define( 'ERANKLY_NETWORK_RESET_JOB_OPTION', 'erankly_network_reset_job' );
define( 'ERANKLY_NETWORK_RESET_CRON_HOOK', 'erankly_network_reset_batch' );
define( 'ERANKLY_NETWORK_RESET_BATCH_SIZE', 10 );
define( 'ERANKLY_NETWORK_WEB_LIFECYCLE_LIMIT', 100 );
define( 'ERANKLY_MIGRATION_ACTIVE_JOB_OPTION', 'erankly_migration_active_job_v1' );
define( 'ERANKLY_MIGRATION_CRON_HOOK', 'erankly_migration_process_batch' );
define( 'ERANKLY_MIGRATION_BATCH_SIZE', 100 );
define( 'ERANKLY_IMPORT_ACTIVE_JOB_OPTION', 'erankly_import_active_job_v1' );
define( 'ERANKLY_IMPORT_LAST_RESULT_OPTION', 'erankly_import_last_result_v1' );
define( 'ERANKLY_IMPORT_CRON_HOOK', 'erankly_import_process_batch' );
define( 'ERANKLY_IMPORT_BATCH_SIZE', 100 );

/** Native export document version. Bumped when the JSON structure changes. */
define( 'ERANKLY_EXPORT_FORMAT', '4.0' );

/** Maximum supported nesting depth for the EasyRankly export schema. */
define( 'ERANKLY_IMPORT_JSON_MAX_DEPTH', 64 );

require_once ERANKLY_PATH . 'includes/helpers.php';
$erankly_plugin_check_helper = ERANKLY_PATH . 'includes/plugin-check.php';
if ( file_exists( $erankly_plugin_check_helper ) && ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
	require_once $erankly_plugin_check_helper;
}
unset( $erankly_plugin_check_helper );
require_once ERANKLY_PATH . 'includes/settings-lock.php';
require_once ERANKLY_PATH . 'includes/localized-value-writer.php';
require_once ERANKLY_PATH . 'includes/class-erankly-multilingual-provider-registry.php';
require_once ERANKLY_PATH . 'includes/seo-state.php';

/**
 * Maps a legacy hot option to its compact runtime-state key. The legacy options remain mirrored for rollback
 * compatibility, while the autoloaded state avoids separate queries for values read during bootstrap.
 */
function erankly_runtime_state_key( string $option ): string {
	$keys = array(
		ERANKLY_VERSION_OPTION            => 'version',
		ERANKLY_REWRITE_GENERATION_OPTION => 'rewrite_generation',
	);

	return $keys[ $option ] ?? '';
}

/**
 * Returns the compact, autoloaded runtime state for a single-site install. Existing installations are migrated
 * lazily once. Network options keep their existing storage because WordPress has no equivalent autoload flag for
 * them.
 *
 * @return array<string,mixed>
 */
function erankly_get_runtime_state(): array {
	global $erankly_runtime_state_cache;

	if ( isset( $erankly_runtime_state_cache ) && is_array( $erankly_runtime_state_cache ) ) {
		return $erankly_runtime_state_cache;
	}

	$state = get_option( ERANKLY_RUNTIME_STATE_OPTION, false );

	if ( ! is_array( $state ) ) {
		$state = array(
			'version'            => get_option( ERANKLY_VERSION_OPTION, '' ),
			'rewrite_generation' => get_option( ERANKLY_REWRITE_GENERATION_OPTION, '0' ),
		);

		if ( ! add_option( ERANKLY_RUNTIME_STATE_OPTION, $state, '', true ) ) {
			$stored_state = get_option( ERANKLY_RUNTIME_STATE_OPTION, false );

			if ( is_array( $stored_state ) ) {
				$state = $stored_state;
			} else {
				update_option( ERANKLY_RUNTIME_STATE_OPTION, $state, true );
			}
		}
	}

	$erankly_runtime_state_cache = $state;

	return $state;
}

/** Updates one compact runtime value and mirrors its legacy option. */
function erankly_update_runtime_state( string $option, mixed $value ): void {
	global $erankly_runtime_state_cache;

	$key           = erankly_runtime_state_key( $option );
	$state         = erankly_get_runtime_state();
	$state[ $key ] = $value;

	update_option( ERANKLY_RUNTIME_STATE_OPTION, $state, true );
	update_option( $option, $value, false );

	$erankly_runtime_state_cache = $state;
}

/** Gets a plugin option using network storage on Multisite. */
function erankly_get_plugin_option( string $key, mixed $default_value = false ): mixed {
	$runtime_key = erankly_runtime_state_key( $key );

	if ( ! is_multisite() && '' !== $runtime_key ) {
		$state = erankly_get_runtime_state();

		return array_key_exists( $runtime_key, $state ) ? $state[ $runtime_key ] : $default_value;
	}

	return is_multisite() ? get_site_option( $key, $default_value ) : get_option( $key, $default_value );
}

/**
 * Updates a plugin option using network storage on Multisite.
 *
 * @throws RuntimeException When the atomic settings update fails.
 */
function erankly_update_plugin_option( string $key, mixed $value ): void {
	if ( ERANKLY_OPTION === $key ) {
		$result = erankly_update_plugin_settings( is_array( $value ) ? $value : array() );

		if ( is_wp_error( $result ) || ! $result ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not update its settings atomically.', 'easyrankly' ) );
		}
	} elseif ( is_multisite() ) {
		update_site_option( $key, $value );
	} elseif ( '' !== erankly_runtime_state_key( $key ) ) {
		erankly_update_runtime_state( $key, $value );
	} else {
		// The settings array is read on every request, so autoload it; other options aren't.
		update_option( $key, $value, ERANKLY_OPTION === $key );
	}
}

// Interlock direct Settings API writers (including options.php) with the same
// provider-neutral mutex used by the public localized-source writer.
add_filter( 'pre_update_option_' . ERANKLY_OPTION, 'erankly_interlock_settings_pre_update', 10, 3 );
add_filter( 'pre_update_site_option_' . ERANKLY_OPTION, 'erankly_interlock_settings_pre_update', 10, 4 );
add_action( 'update_option_' . ERANKLY_OPTION, 'erankly_release_direct_settings_lock', 10, 0 );
add_action( 'update_site_option_' . ERANKLY_OPTION, 'erankly_release_direct_settings_lock', 10, 0 );
add_action( 'shutdown', 'erankly_release_direct_settings_lock', PHP_INT_MAX );

require_once ERANKLY_PATH . 'includes/compatibility.php';
require_once ERANKLY_PATH . 'includes/meta.php';
require_once ERANKLY_PATH . 'includes/meta-visibility.php';
require_once ERANKLY_PATH . 'includes/robots.php';
require_once ERANKLY_PATH . 'includes/special-meta.php';

if ( is_admin() ) {
	require_once ERANKLY_PATH . 'includes/admin.php';
}

/** Boots the plugin after all plugins are available for compatibility checks. */
function erankly_bootstrap(): void {
	require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
	add_action( 'admin_notices', 'erankly_render_invalid_json_ld_notice' );
	add_action( 'admin_notices', 'erankly_render_multilingual_provider_notices' );
	add_action( 'network_admin_notices', 'erankly_render_multilingual_provider_notices' );
	add_filter( 'debug_information', 'erankly_add_multilingual_debug_information' );
	add_action( 'update_option_' . ERANKLY_SPECIAL_META_OPTION, 'erankly_handle_sitemap_visibility_updated', 10, 2 );

	add_action( ERANKLY_MIGRATION_CRON_HOOK, 'erankly_process_migration_job' );
	add_action( ERANKLY_IMPORT_CRON_HOOK, 'erankly_process_import_job' );
	add_action( 'init', 'erankly_register_meta' );
	add_action( 'init', 'erankly_register_breadcrumb_integrations', 5 );
	add_action( 'init', 'erankly_register_rewrites' );
	add_action( 'init', 'erankly_maybe_migrate_settings', 15 );
	add_action( 'init', 'erankly_maybe_migrate_post_type_schema', 16 );
	add_action( 'init', 'erankly_maybe_migrate_local_business_pages', 17 );
	add_action( 'init', 'erankly_maybe_flush_after_upgrade', 20 );
	add_action( 'init', 'erankly_maybe_flush_rewrite_rules', 30 );

	if (
		is_multisite()
		&& (
			wp_doing_cron()
			|| is_network_admin()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		)
	) {
		require_once ERANKLY_PATH . 'includes/network-reset.php';
		add_action( ERANKLY_NETWORK_RESET_CRON_HOOK, 'erankly_process_network_reset_batch' );
		add_action( 'network_admin_notices', 'erankly_render_network_reset_status_notice' );
	}

	if ( erankly_redirects_enabled() ) {
		require_once ERANKLY_PATH . 'includes/redirects.php';
		erankly_redirects_boot();
	}

	if ( erankly_custom_code_enabled() ) {
		require_once ERANKLY_PATH . 'includes/custom-code.php';
		erankly_custom_code_boot();
	}

	// The native WordPress sitemap remains available when EasyRankly's optional
	// sitemap module is off. Keep its URL lists aligned with EasyRankly's robots,
	// canonical and per-object visibility rules unless another SEO plugin owns
	// sitemap output.
	if ( ! erankly_should_suppress_sitemaps() ) {
		erankly_load_sitemap_helpers();
		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/sitemap/core.php';
		add_filter( 'wp_sitemaps_posts_query_args', 'erankly_filter_core_sitemap_posts_query_args', 20, 2 );
		add_filter( 'wp_sitemaps_posts_pre_url_list', 'erankly_filter_core_sitemap_posts_pre_url_list', 20, 3 );
		add_filter( 'wp_sitemaps_posts_pre_max_num_pages', 'erankly_filter_core_sitemap_posts_pre_max_num_pages', 20, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', 'erankly_filter_core_sitemap_terms_query_args', 20, 2 );
		add_filter( 'wp_sitemaps_users_query_args', 'erankly_filter_core_sitemap_users_query_args', 20 );
		add_filter( 'wp_sitemaps_post_types', 'erankly_filter_core_sitemap_post_types', 20 );
		add_filter( 'wp_sitemaps_taxonomies', 'erankly_filter_core_sitemap_taxonomies', 20 );
		add_filter( 'wp_sitemaps_add_provider', 'erankly_filter_core_sitemap_add_provider', 20, 2 );
		add_filter( 'posts_where', 'erankly_filter_sitemap_posts_where', 20, 2 );

		add_action( 'save_post', 'erankly_flush_sitemap_cache_for_post' );
		add_action( 'deleted_post', 'erankly_flush_sitemap_cache_for_deleted_post' );
		add_action( 'transition_post_status', 'erankly_flush_sitemap_cache_for_status', 10, 3 );
		add_action( 'profile_update', 'erankly_flush_sitemap_cache' );
		add_action( 'user_register', 'erankly_flush_sitemap_cache' );
		add_action( 'deleted_user', 'erankly_flush_sitemap_cache' );
		add_action( 'added_user_meta', 'erankly_flush_sitemap_cache_for_user_meta', 10, 3 );
		add_action( 'updated_user_meta', 'erankly_flush_sitemap_cache_for_user_meta', 10, 3 );
		add_action( 'deleted_user_meta', 'erankly_flush_sitemap_cache_for_user_meta', 10, 3 );
		add_action( 'added_term_meta', 'erankly_flush_sitemap_cache_for_term_meta', 10, 3 );
		add_action( 'updated_term_meta', 'erankly_flush_sitemap_cache_for_term_meta', 10, 3 );
		add_action( 'deleted_term_meta', 'erankly_flush_sitemap_cache_for_term_meta', 10, 3 );
		add_action( 'created_term', 'erankly_flush_sitemap_cache', 10, 3 );
		add_action( 'edited_term', 'erankly_flush_sitemap_cache', 10, 3 );
		add_action( 'delete_term', 'erankly_flush_sitemap_cache', 10, 5 );
		add_action( 'added_post_meta', 'erankly_flush_sitemap_cache_for_post_meta', 10, 3 );
		add_action( 'updated_post_meta', 'erankly_flush_sitemap_cache_for_post_meta', 10, 3 );
		add_action( 'deleted_post_meta', 'erankly_flush_sitemap_cache_for_post_meta', 10, 3 );
	}

	if ( erankly_should_serve_sitemaps() ) {
		require_once ERANKLY_PATH . 'includes/class-erankly-site-sitemaps-provider.php';
		add_action(
			'init',
			function () {
				wp_register_sitemap_provider( 'erankly-site', new ERankly_Site_Sitemaps_Provider() );
			}
		);

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

		$has_specialist_sitemaps = (bool) erankly_get_setting( 'enable_news_sitemap', 0 )
			|| (bool) erankly_get_setting( 'enable_image_sitemap', 0 )
			|| (bool) erankly_get_setting( 'enable_video_sitemap', 0 );

		if ( $has_specialist_sitemaps ) {
			require_once ERANKLY_PATH . 'includes/class-erankly-specialist-sitemaps-provider.php';
			add_action(
				'init',
				function () {
					wp_register_sitemap_provider( 'erankly', new ERankly_Specialist_Sitemaps_Provider() );
				}
			);
		}
		add_action( 'template_redirect', 'erankly_maybe_render_virtual_files', 0 );
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
	add_action( 'template_redirect', 'erankly_send_feed_robots_header', 1 );
	add_action( 'pre_get_posts', 'erankly_filter_visibility_queries' );
	add_action( 'added_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );
	add_action( 'updated_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );
	add_action( 'deleted_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );

	if ( is_multisite() ) {
		add_action( 'update_site_option_' . ERANKLY_OPTION, 'erankly_handle_network_settings_updated', 10, 3 );
	} else {
		add_action( 'update_option_' . ERANKLY_OPTION, 'erankly_handle_settings_updated', 10, 2 );
	}

	/** Fires after EasyRankly core has finished booting. Add-ons should load feature modules here so core helpers and settings are available. */
	do_action( 'erankly_bootstrap' );
}
add_action( 'plugins_loaded', 'erankly_bootstrap', 5 );
add_action( 'plugins_loaded', 'erankly_close_multilingual_provider_registry', 20 );

/**
 * Lazily loads and advances one resumable third-party migration batch. Keeping the adapters out of ordinary
 * frontend requests preserves the plugin's modular bootstrap while still registering a WP-Cron callback early.
 */
function erankly_process_migration_job( string $job_id ): void {
	require_once ERANKLY_PATH . 'includes/migrations.php';
	erankly_migration_job_runner()->process( $job_id );
}

function erankly_process_import_job( string $job_id ): void {
	require_once ERANKLY_PATH . 'includes/migrations.php';
	require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';
	ERankly_Import_Job_Runner::process( $job_id );
}

/**
 * Loads frontend-only modules after WordPress has resolved an HTML request. REST requests normally terminate
 * before the wp hook, so they do not parse the canonical, social, schema, or breadcrumb implementations.
 */
function erankly_bootstrap_frontend_modules(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'includes/meta-render.php';

	require_once ERANKLY_PATH . 'includes/breadcrumbs.php';

	if ( ! function_exists( 'easyrankly_breadcrumbs' ) && function_exists( 'erankly_breadcrumbs' ) ) {
		// Legacy public function kept for backward compatibility.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		/** Legacy alias for the public breadcrumbs template function. */
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
	add_filter( 'pre_get_document_title', 'erankly_filter_document_title', 20 );
	add_filter( 'document_title_parts', 'erankly_filter_document_title_parts', 20 );
	add_action( 'wp_head', 'erankly_render_head', 1 );
	add_filter( 'wp_robots', 'erankly_filter_wp_robots', 20 );
}

/**
 * Rotates the network-wide generation used by per-site rewrite signatures. A fresh generation on every
 * activation guarantees that sites skipped by a bounded network deactivation rebuild their rules after
 * reactivation, even if another component flushed the rules while EasyRankly was inactive.
 *
 * @throws RuntimeException When the generation cannot be persisted.
 */
function erankly_rotate_rewrite_generation(): void {
	$generation = wp_generate_uuid4();

	erankly_update_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, $generation );

	if ( (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '' ) !== $generation ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not initialize its rewrite generation.', 'easyrankly' ) );
	}
}

/** @throws RuntimeException When atomic initialization fails. */
function erankly_activate(): void {
	erankly_load_default_helpers();
	$is_new_install = false === erankly_get_plugin_option( ERANKLY_OPTION, false );

	if ( $is_new_install ) {
		$created = erankly_update_plugin_settings( erankly_default_settings(), '', true );

		if ( is_wp_error( $created ) || ! $created ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not initialize its settings.', 'easyrankly' ) );
		}
	}

	if ( is_multisite() ) {
		add_site_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );
	} else {
		add_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION, '', 'no' );
	}

	erankly_rotate_rewrite_generation();
	erankly_register_rewrites();
	flush_rewrite_rules( false );
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, erankly_get_rewrite_signature(), true );
}
register_activation_hook( ERANKLY_FILE, 'erankly_activate' );

/**
 * Returns a keyset-paginated batch of site IDs for the current network.
 *
 * @param int $after_site_id Return sites whose ID is greater than this value.
 * @param int $limit         Maximum IDs to return.
 * @return int[]
 * @throws RuntimeException When the site batch cannot be read.
 */
function erankly_get_network_site_ids_batch(
	int $after_site_id = 0,
	int $limit = ERANKLY_NETWORK_SITE_BATCH_SIZE
): array {
	global $wpdb;

	$site_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination keeps lifecycle and reset sweeps bounded.
		$wpdb->prepare(
			'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d',
			$wpdb->blogs,
			(int) get_current_network_id(),
			max( 0, $after_site_id ),
			max( 1, $limit )
		)
	);

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not retrieve the next network site batch.', 'easyrankly' ) );
	}

	return array_map( 'intval', (array) $site_ids );
}

/**
 * Counts sites in the current network.
 *
 * @throws RuntimeException When the site count cannot be read.
 */
function erankly_get_current_network_site_count(): int {
	global $wpdb;

	$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The count selects the safe lifecycle execution path.
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE site_id = %d',
			$wpdb->blogs,
			(int) get_current_network_id()
		)
	);

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not count the current network sites.', 'easyrankly' ) );
	}

	return (int) $count;
}

/** Returns whether a network lifecycle sweep must run through WP-CLI. */
function erankly_network_lifecycle_requires_cli(): bool {
	$limit = (int) apply_filters( 'erankly_network_web_lifecycle_limit', ERANKLY_NETWORK_WEB_LIFECYCLE_LIMIT );

	return erankly_get_current_network_site_count() > max( 1, $limit );
}

/**
 * Returns the rewrite configuration currently expected by this site. Every site stores the last signature it
 * applied. An activation, plugin upgrade, or network-wide sitemap setting change alters this value
 * automatically, so the next request to each site can rebuild its own rules without scanning the network or
 * coordinating a background job.
 */
function erankly_get_rewrite_signature(): string {
	$generation = (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '0' );

	return ERANKLY_VERSION . ':' . $generation . ':' . ( erankly_should_serve_sitemaps() ? '1' : '0' );
}

/**
 * Deletes retired keys from the persisted settings array. The settings writer merges changes over the stored
 * array and the interlock re-adds current values, so keys whose readers were removed would otherwise be
 * preserved forever as "extension settings". Called once from the version upgrade routine.
 *
 * @param string[] $keys Setting keys that no longer have a reader.
 * @return bool Whether the reduced settings snapshot was durably stored.
 */
function erankly_remove_retired_setting_keys( array $keys ): bool {
	$stored = is_multisite() ? get_site_option( ERANKLY_OPTION, array() ) : get_option( ERANKLY_OPTION, array() );

	if ( ! is_array( $stored ) ) {
		return true;
	}

	$remaining = array_diff_key( $stored, array_fill_keys( $keys, true ) );

	if ( $remaining === $stored ) {
		return true;
	}

	$lock_token = erankly_acquire_settings_lock();

	if ( is_wp_error( $lock_token ) ) {
		return false;
	}

	// The replace context makes the settings interlock pass the reduced array through unchanged instead of
	// merging it back over the stored keys (see erankly_interlock_settings_pre_update()).
	$GLOBALS['erankly_settings_write_context'] = array(
		'token'             => $lock_token,
		'release_on_update' => false,
		'replace'           => true,
	);

	try {
		if ( is_multisite() ) {
			update_site_option( ERANKLY_OPTION, $remaining );
		} else {
			update_option( ERANKLY_OPTION, $remaining, true );
		}

		$persisted = is_multisite() ? get_site_option( ERANKLY_OPTION, array() ) : get_option( ERANKLY_OPTION, array() );

		return is_array( $persisted ) && $persisted === $remaining;
	} finally {
		unset( $GLOBALS['erankly_settings_write_context'] );
		erankly_release_settings_lock( $lock_token );
	}
}

/**
 * Records the plugin version after an upgrade. Per-site rewrite updates are handled independently by
 * erankly_maybe_flush_rewrite_rules() through the lazy rewrite signature.
 */
function erankly_maybe_flush_after_upgrade(): void {
	$stored = (string) erankly_get_plugin_option( ERANKLY_VERSION_OPTION, '' );

	if ( ERANKLY_VERSION === $stored ) {
		return;
	}

	// Migration verification, the rollback journal and the staging queue were retired. Their tables, options
	// and cron hooks have no reader left, so an upgraded site must not keep carrying them.
	require_once ERANKLY_PATH . 'includes/migrations/legacy-cleanup.php';
	if ( ! erankly_migration_purge_legacy_state() ) {
		return;
	}

	// The noodp robots directive (dropped from Google's robots meta spec years ago; its only data source,
	// DMOZ, shut down in 2017) and the pre-2.0 robots_max_image_preview_large fallback have no reader left.
	if ( ! erankly_remove_retired_setting_keys( array( 'robots_noodp', 'robots_max_image_preview_large' ) ) ) {
		return;
	}

	erankly_update_plugin_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );
}

/** Adapter for the update_site_option_ hook, which passes args in a different order. */
function erankly_handle_network_settings_updated( string $option, mixed $value, mixed $old_value ): void {
	erankly_handle_settings_updated( $old_value, $value );
}

function erankly_handle_settings_updated( mixed $old_value, mixed $value ): void {
	erankly_clear_settings_cache();

	$old_value = is_array( $old_value ) ? $old_value : array();
	$value     = is_array( $value ) ? $value : array();
	$keys      = array(
		'enable_sitemap',
		'enable_news_sitemap',
		'news_sitemap_post_types',
		'news_publication_name',
		'enable_image_sitemap',
		'enable_video_sitemap',
		'global_post_type_meta',
		'global_taxonomy_meta',
		'global_special_meta',
	);

	foreach ( $keys as $key ) {
		if ( ( $old_value[ $key ] ?? null ) !== ( $value[ $key ] ?? null ) ) {
			erankly_load_sitemap_helpers();
			erankly_flush_sitemap_cache();
			break;
		}
	}
}

/** Invalidates author/home/archive sitemap state stored outside the main settings option. */
function erankly_handle_sitemap_visibility_updated( mixed $old_value, mixed $value ): void {
	if ( $old_value === $value ) {
		return;
	}

	erankly_load_sitemap_helpers();
	erankly_flush_sitemap_cache();
}

/** Lazily applies the current rewrite signature to this site. */
function erankly_maybe_flush_rewrite_rules(): void {
	$signature      = erankly_get_rewrite_signature();
	$last_signature = (string) get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, '' );

	if ( $signature === $last_signature ) {
		return;
	}

	erankly_load_sitemap_helpers();
	erankly_flush_sitemap_cache();
	flush_rewrite_rules( false );
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, $signature, true );
}

/**
 * Removes deactivation-only state from the current site. Clears every EasyRankly WP-Cron hook so pending import,
 * migration and rollback pages cannot fire after reactivation. Active job checkpoints are intentionally retained
 * so an administrator can resume from the admin UI (see migration Phase 3/5 lifecycle docs). Reset and uninstall
 * delete those checkpoints; deactivation must not.
 *
 * @throws RuntimeException When a scheduled task cannot be removed.
 */
function erankly_deactivate_current_site(): void {
	foreach (
		array(
			ERANKLY_NETWORK_RESET_CRON_HOOK,
			ERANKLY_MIGRATION_CRON_HOOK,
			ERANKLY_IMPORT_CRON_HOOK,
		) as $hook
	) {
		$result = wp_unschedule_hook( $hook, true );

		if ( false === $result || is_wp_error( $result ) ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not remove its scheduled tasks during deactivation.', 'easyrankly' ) );
		}
	}

	erankly_load_sitemap_helpers();
	erankly_flush_sitemap_cache();
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	delete_option( ERANKLY_REWRITE_SIGNATURE_OPTION );
	// Invalidate the stored rules. Core rebuilds them without EasyRankly on the
	// site's next request; no costly hard flush is needed here.
	delete_option( 'rewrite_rules' );
}

/**
 * Cancels and verifies removal of the current network reset job. A stale active job must never survive
 * deactivation: the Network Admin self-healing notice would otherwise schedule it again after reactivation.
 *
 * @throws RuntimeException When the reset state cannot be removed.
 */
function erankly_cancel_network_reset_job(): void {
	$missing = 'erankly-reset-missing-' . wp_generate_uuid4();

	delete_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION );

	if ( get_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $missing ) !== $missing ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not cancel the active network reset during deactivation.', 'easyrankly' ) );
	}
}

/** @param bool $network_deactivating Whether this is a network deactivation. */
function erankly_deactivate( bool $network_deactivating = false ): void {
	if ( is_multisite() && $network_deactivating ) {
		if (
			! ( defined( 'WP_CLI' ) && WP_CLI )
			&& erankly_network_lifecycle_requires_cli()
		) {
			$plugin_slug = dirname( plugin_basename( ERANKLY_FILE ) );
			$command     = sprintf( 'wp plugin deactivate %s --network', $plugin_slug );
			$message     = '<p>' . esc_html__( 'This network is too large to deactivate EasyRankly safely in one web request.', 'easyrankly' ) . '</p>';
			$message    .= '<p>' . esc_html__( 'Run the following WP-CLI command so every site can remove its scheduled tasks and rewrite rules:', 'easyrankly' ) . '</p>';
			$message    .= '<p><code>' . esc_html( $command ) . '</code></p>';

			wp_die(
				$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup contains only escaped translated text and command output.
				esc_html__( 'EasyRankly network cleanup required', 'easyrankly' ),
				array(
					'response'  => 409,
					'back_link' => true,
				)
			);
		}

		erankly_cancel_network_reset_job();

		$last_site_id = 0;

		do {
			$site_ids   = erankly_get_network_site_ids_batch( $last_site_id );
			$site_count = count( $site_ids );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );

				try {
					erankly_deactivate_current_site();
				} catch ( Throwable $error ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Multisite deactivation diagnostics when WP_DEBUG is enabled.
							sprintf(
								'EasyRankly network deactivation failed for site %d: %s',
								(int) $site_id,
								$error->getMessage()
							)
						);
					}
				} finally {
					restore_current_blog();
				}
			}

			if ( $site_ids ) {
				$last_site_id = (int) end( $site_ids );
			}
		} while ( ERANKLY_NETWORK_SITE_BATCH_SIZE === $site_count );

		return;
	}

	erankly_deactivate_current_site();
}
register_deactivation_hook( ERANKLY_FILE, 'erankly_deactivate' );

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
 * Handles the user search REST request. Returns up to 20 users matching the query. Network-wide lookups (blog_id
 * = 0) are reserved for users who can manage the whole network; a regular site admin is scoped to the members of
 * their own site so they cannot enumerate every account on the network. On single-site the blog_id is ignored.
 *
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
 * Registers the REST route that autosaves settings panels. One route serves every autosave-enabled panel (see
 * erankly_settings_autosave_panels() in admin/settings-page.php for the per-panel whitelist registry); the
 * `panel` slug is validated against that registry inside the handler, not the route pattern, so this never needs
 * editing again as panels are added. The char class is just a safe charset for a path segment, not an allowlist
 * (the registry lookup is what actually prevents an unknown or cross-panel request from touching anything).
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
			// per-site one. A subsite admin must not be able to reach this route.
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
 * Saves a partial payload from a settings panel autosave. Looks up the requested panel in
 * erankly_settings_autosave_panels(), merges its whitelisted fields onto the currently stored settings (so
 * panels that aren't part of this request are left untouched), optionally runs a panel-specific normalize hook,
 * then runs the result through the same sanitizer the full options.php submission uses and persists it. Several
 * of those admin-only helpers (erankly_use_site_editor_special_page_panels(),
 * add_settings_error()/get_settings_errors()) aren't loaded on a bare REST request the way they are on wp-admin
 * requests, so they're pulled in on demand here. On Multisite,
 * erankly_get_settings()/erankly_update_plugin_option() already route through the network-wide site option
 * regardless of which admin screen the request came from, so no Network Admin detection is needed here. The
 * permission_callback is what keeps subsite admins out.
 *
 * @return WP_REST_Response|WP_Error
 */
function erankly_rest_save_settings_panel( WP_REST_Request $request ) {
	erankly_load_content_helpers();
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

	try {
		erankly_update_plugin_option( ERANKLY_OPTION, $sanitized );
	} catch ( RuntimeException $exception ) {
		return new WP_Error(
			'erankly_settings_locked',
			$exception->getMessage(),
			array( 'status' => 409 )
		);
	}

	$notices  = function_exists( 'get_settings_errors' ) ? get_settings_errors( ERANKLY_OPTION ) : array();
	$errors   = array();
	$warnings = array();

	foreach ( $notices as $notice ) {
		$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
		if ( '' === $message ) {
			continue;
		}
		if ( isset( $notice['type'] ) && 'error' === $notice['type'] ) {
			$errors[] = $message;
		} else {
			$warnings[] = $message;
		}
	}

	return new WP_REST_Response(
		array(
			'saved'      => true,
			'incomplete' => array() !== $errors,
			'errors'     => $errors,
			'warnings'   => $warnings,
		),
		200
	);
}

/**
 * Registers the REST route that autosaves the per-site "Special pages and archives" panel, the Multisite
 * fallback for sites that can't use the Site Editor panels (see erankly_use_site_editor_special_page_panels()).
 * Kept separate from erankly_register_settings_autosave_route(): this panel doesn't merge into
 * ERANKLY_OPTION/erankly_get_settings() the way every other panel does. erankly_update_special_meta_map()
 * already owns reading, sanitizing and writing this data (a dedicated per-site option on Multisite), so it
 * doesn't fit the shared registry's shape.
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
 * Saves the "Special pages and archives" autosave payload. Uses erankly_update_special_meta_map()
 * (includes/special-meta.php, always loaded), which already sanitizes its input and routes the write to the
 * correct storage, so the whitelisted map is passed straight through with no merge step. Unlike
 * erankly_rest_save_settings_panel(), there's no risk of this payload clobbering another panel's fields since
 * this data isn't part of ERANKLY_OPTION on Multisite at all.
 *
 * @return WP_REST_Response|WP_Error
 */
function erankly_rest_save_special_pages( WP_REST_Request $request ) {
	erankly_load_content_helpers();
	$payload = (array) $request->get_param( 'settings' );
	$map     = isset( $payload['global_special_meta'] ) && is_array( $payload['global_special_meta'] ) ? $payload['global_special_meta'] : array();

	try {
		erankly_update_special_meta_map( $map );
	} catch ( RuntimeException $exception ) {
		return new WP_Error(
			'erankly_settings_locked',
			$exception->getMessage(),
			array( 'status' => 409 )
		);
	}

	return new WP_REST_Response(
		array(
			'saved'    => true,
			'warnings' => array(),
		),
		200
	);
}
