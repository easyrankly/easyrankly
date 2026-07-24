<?php
/**
 * Plugin Name: EasyRankly
 * Plugin URI:  https://easyrankly.com
 * Description: Lightweight, modular, developer-first SEO essentials for WordPress.
 * Version:     3.0.0
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

define( 'ERANKLY_VERSION', '3.0.0' );
define( 'ERANKLY_EXTENSION_API_VERSION', 1 );
define( 'ERANKLY_FILE', __FILE__ );
define( 'ERANKLY_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERANKLY_URL', plugin_dir_url( __FILE__ ) );
define( 'ERANKLY_OPTION', 'erankly_settings' );
// Special-page metadata is stored per site on Multisite (see erankly_get_site_special_meta()).
define( 'ERANKLY_SPECIAL_META_OPTION', 'erankly_special_meta' );
define( 'ERANKLY_VERSION_OPTION', 'erankly_version' );
define( 'ERANKLY_SETUP_STATUS_OPTION', 'erankly_setup_wizard_status' );
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
define( 'ERANKLY_EXTENSION_EXTRACTION_NOTICE_OPTION', 'erankly_extension_extraction_notice_v1' );
define( 'ERANKLY_MIGRATION_ACTIVE_JOB_OPTION', 'erankly_migration_active_job_v1' );
define( 'ERANKLY_MIGRATION_CRON_HOOK', 'erankly_migration_process_batch' );
define( 'ERANKLY_MIGRATION_BATCH_SIZE', 100 );
define( 'ERANKLY_MIGRATION_ROLLBACK_CRON_HOOK', 'erankly_migration_rollback_batch' );
define( 'ERANKLY_MIGRATION_ROLLBACK_BATCH_SIZE', 100 );
define( 'ERANKLY_IMPORT_ACTIVE_JOB_OPTION', 'erankly_import_active_job_v1' );
define( 'ERANKLY_IMPORT_LAST_RESULT_OPTION', 'erankly_import_last_result_v1' );
define( 'ERANKLY_IMPORT_CRON_HOOK', 'erankly_import_process_batch' );
define( 'ERANKLY_IMPORT_BATCH_SIZE', 100 );

require_once ERANKLY_PATH . 'includes/helpers.php';
require_once ERANKLY_PATH . 'includes/settings-lock.php';
require_once ERANKLY_PATH . 'includes/localized-value-writer.php';
require_once ERANKLY_PATH . 'includes/class-erankly-multilingual-provider-registry.php';
require_once ERANKLY_PATH . 'includes/seo-state.php';

/**
 * Maps a legacy hot option to its compact runtime-state key.
 *
 * The legacy options remain mirrored for rollback compatibility, while the
 * autoloaded state avoids separate queries for values read during bootstrap.
 *
 * @param string $option Option name.
 * @return string
 */
function erankly_runtime_state_key( string $option ): string {
	$keys = array(
		ERANKLY_VERSION_OPTION            => 'version',
		ERANKLY_SETUP_STATUS_OPTION       => 'setup_status',
		ERANKLY_REWRITE_GENERATION_OPTION => 'rewrite_generation',
	);

	return $keys[ $option ] ?? '';
}

/**
 * Returns the compact, autoloaded runtime state for a single-site install.
 *
 * Existing installations are migrated lazily once. Network options keep their
 * existing storage because WordPress has no equivalent autoload flag for them.
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
			'setup_status'       => get_option( ERANKLY_SETUP_STATUS_OPTION, '' ),
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

/**
 * Updates one compact runtime value and mirrors its legacy option.
 *
 * @param string $option Legacy option name.
 * @param mixed  $value  Value to store.
 * @return void
 */
function erankly_update_runtime_state( string $option, mixed $value ): void {
	global $erankly_runtime_state_cache;

	$key           = erankly_runtime_state_key( $option );
	$state         = erankly_get_runtime_state();
	$state[ $key ] = $value;

	update_option( ERANKLY_RUNTIME_STATE_OPTION, $state, true );
	update_option( $option, $value, false );

	$erankly_runtime_state_cache = $state;
}

/**
 * Gets a plugin option using network storage on Multisite.
 *
 * @param string $key           Option name.
 * @param mixed  $default_value Default value.
 * @return mixed
 */
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
 * @param string $key   Option name.
 * @param mixed  $value Value to store.
 * @return void
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

/**
 * Boots the plugin after all plugins are available for compatibility checks.
 *
 * @return void
 */
function erankly_bootstrap(): void {
	erankly_close_multilingual_provider_registry();
	add_action( 'admin_notices', 'erankly_render_multilingual_provider_notices' );
	add_action( 'network_admin_notices', 'erankly_render_multilingual_provider_notices' );
	add_action( 'admin_notices', 'erankly_render_extension_extraction_notice' );
	add_action( 'network_admin_notices', 'erankly_render_extension_extraction_notice' );
	add_filter( 'debug_information', 'erankly_add_multilingual_debug_information' );

	add_action( ERANKLY_MIGRATION_CRON_HOOK, 'erankly_process_migration_job' );
	add_action( ERANKLY_MIGRATION_ROLLBACK_CRON_HOOK, 'erankly_process_migration_rollback' );
	add_action( ERANKLY_IMPORT_CRON_HOOK, 'erankly_process_import_job' );
	add_action( 'erankly_health_prune_404_cron', 'erankly_clear_disabled_health_cron', 0 );
	add_action( 'init', 'erankly_register_meta' );
	add_action( 'init', 'erankly_register_rewrites' );
	add_action( 'init', 'erankly_maybe_flush_after_upgrade', 20 );
	add_action( 'init', 'erankly_maybe_flush_rewrite_rules', 30 );
	// Existing reports remain readable and deletable while the optional module
	// is enabled, even if AI is later disabled or its provider is disconnected.
	if ( erankly_content_analysis_enabled() ) {
		add_action( 'rest_api_init', 'erankly_bootstrap_content_analysis_rest_routes', 5 );
	}

	// WP-CLI defines DOING_CRON only when it dispatches an event, after plugins
	// have booted, so register the worker for CLI requests explicitly.
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

	if ( erankly_bloat_enabled() ) {
		require_once ERANKLY_PATH . 'includes/bloat.php';
		erankly_bloat_bootstrap();
	}

	if ( erankly_redirects_enabled() ) {
		require_once ERANKLY_PATH . 'includes/redirects.php';
		erankly_redirects_boot();
	}

	if ( erankly_sitemap_enabled() && ! erankly_should_suppress_sitemaps() ) {
		erankly_load_sitemap_helpers();
		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/sitemap/core.php';
		add_filter( 'wp_sitemaps_posts_query_args', 'erankly_filter_core_sitemap_posts_query_args', 20, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', 'erankly_filter_core_sitemap_terms_query_args', 20, 2 );
		add_filter( 'wp_sitemaps_post_types', 'erankly_filter_core_sitemap_post_types', 20 );
		add_filter( 'wp_sitemaps_taxonomies', 'erankly_filter_core_sitemap_taxonomies', 20 );
		add_filter( 'wp_sitemaps_add_provider', 'erankly_filter_core_sitemap_add_provider', 20, 2 );

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

	if ( erankly_ai_module_enabled() ) {
		add_action( 'rest_api_init', 'erankly_bootstrap_ai_rest_routes', 5 );

		if ( is_admin() ) {
			erankly_load_ai_module();
		}
	}

	if ( erankly_health_enabled() ) {
		require_once ERANKLY_PATH . 'includes/health.php';
		erankly_health_boot();
	}

	if ( erankly_link_building_enabled() || ( is_admin() && erankly_ai_module_enabled() ) ) {
		require_once ERANKLY_PATH . 'includes/link-building.php';
		if ( erankly_link_building_enabled() ) {
			erankly_lb_boot();
		}
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
	} else {
		add_action( 'update_option_' . ERANKLY_OPTION, 'erankly_handle_settings_updated', 10, 2 );
	}
}
add_action( 'plugins_loaded', 'erankly_bootstrap', 5 );

/**
 * Loads the AI implementation and its minimal helper set once.
 *
 * @return void
 */
function erankly_load_ai_module(): void {
	erankly_load_ai_helpers();
	require_once ERANKLY_PATH . 'includes/ai.php';
}

/**
 * Loads and registers AI routes only while WordPress initializes REST.
 *
 * REST_REQUEST is not reliably available at plugins_loaded, so the loader is
 * attached directly to rest_api_init instead of guessing from the request URI.
 *
 * @return void
 */
function erankly_bootstrap_ai_rest_routes(): void {
	if ( ! erankly_ai_module_enabled() ) {
		return;
	}

	erankly_load_ai_module();
	erankly_ai_register_rest_routes();
}

/**
 * Loads the lightweight content-analysis route shell during REST discovery.
 *
 * The full analysis implementation is deferred to the endpoint callback.
 *
 * @return void
 */
function erankly_bootstrap_content_analysis_rest_routes(): void {
	if ( ! erankly_content_analysis_enabled() ) {
		return;
	}

	require_once ERANKLY_PATH . 'includes/ai-content-analysis-routes.php';
	erankly_content_analysis_register_rest_routes();
}

/**
 * Removes a stale Health schedule if a disabled subsite receives its next run.
 *
 * The settings-update callback clears the current site's event immediately.
 * This small fallback lets Multisite installations clean schedules belonging
 * to other sites without an unbounded network sweep during the toggle request.
 *
 * @return void
 */
function erankly_clear_disabled_health_cron(): void {
	if ( ! erankly_health_enabled() ) {
		wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );
	}
}

/**
 * Lazily loads and advances one resumable third-party migration batch.
 *
 * Keeping the adapters out of ordinary frontend requests preserves the
 * plugin's modular bootstrap while still registering a WP-Cron callback early.
 *
 * @param string $job_id Migration UUID.
 * @return void
 */
function erankly_process_migration_job( string $job_id ): void {
	require_once ERANKLY_PATH . 'includes/migrations.php';
	erankly_migration_job_runner()->process( $job_id );
}

/**
 * Advances one bounded, crash-safe conditional rollback page.
 *
 * @param string $job_id Migration UUID.
 */
function erankly_process_migration_rollback( string $job_id ): void {
	require_once ERANKLY_PATH . 'includes/migrations.php';
	$result = erankly_migration_journal()->process_rollback( $job_id );
	erankly_migration_record_rollback_result( $job_id, $result );
}

/**
 * Advances one bounded EasyRankly JSON import batch.
 *
 * @param string $job_id Import UUID.
 */
function erankly_process_import_job( string $job_id ): void {
	require_once ERANKLY_PATH . 'includes/migrations.php';
	require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';
	ERankly_Import_Job_Runner::process( $job_id );
}

/**
 * Loads frontend-only modules after WordPress has resolved an HTML request.
 *
 * REST requests normally terminate before the wp hook, so they do not parse the
 * canonical, social, schema, or breadcrumb implementations.
 */
function erankly_bootstrap_frontend_modules(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'includes/meta-render.php';

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
 * Rotates the network-wide generation used by per-site rewrite signatures.
 *
 * A fresh generation on every activation guarantees that sites skipped by a
 * bounded network deactivation rebuild their rules after reactivation, even if
 * another component flushed the rules while EasyRankly was inactive.
 *
 * @return void
 * @throws RuntimeException When the generation cannot be persisted.
 */
function erankly_rotate_rewrite_generation(): void {
	$generation = wp_generate_uuid4();

	erankly_update_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, $generation );

	if ( (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '' ) !== $generation ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not initialize its rewrite generation.', 'easyrankly' ) );
	}
}

/**
 * Runs on plugin activation.
 *
 * @return void
 * @throws RuntimeException When atomic initialization fails.
 */
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

	if ( $is_new_install ) {
		erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'pending' );
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
 * @return int
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

/**
 * Returns whether a network lifecycle sweep must run through WP-CLI.
 *
 * @return bool
 */
function erankly_network_lifecycle_requires_cli(): bool {
	$limit = (int) apply_filters( 'erankly_network_web_lifecycle_limit', ERANKLY_NETWORK_WEB_LIFECYCLE_LIMIT );

	return erankly_get_current_network_site_count() > max( 1, $limit );
}

/**
 * Returns the rewrite configuration currently expected by this site.
 *
 * Every site stores the last signature it applied. An activation, plugin
 * upgrade, or network-wide sitemap setting change alters this value
 * automatically, so the next request to each site can rebuild its own rules
 * without scanning the network or coordinating a background job.
 *
 * @return string
 */
function erankly_get_rewrite_signature(): string {
	$generation = (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '0' );

	return ERANKLY_VERSION . ':' . $generation . ':' . ( erankly_sitemap_enabled() ? '1' : '0' );
}

/**
 * Records the plugin version after an upgrade.
 *
 * Per-site rewrite updates are handled independently by
 * erankly_maybe_flush_rewrite_rules() through the lazy rewrite signature.
 *
 * @return void
 */
function erankly_maybe_flush_after_upgrade(): void {
	$stored = (string) erankly_get_plugin_option( ERANKLY_VERSION_OPTION, '' );

	if ( ERANKLY_VERSION !== $stored ) {
		if ( '' !== $stored && version_compare( $stored, '3.0.0', '<' ) ) {
			erankly_update_plugin_option( ERANKLY_EXTENSION_EXTRACTION_NOTICE_OPTION, 1 );
		}

		erankly_update_plugin_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );
	}
}

/**
 * Explains the 3.0 extension boundary after an upgrade from core 2.x.
 *
 * Existing extension data is deliberately not inspected or mutated by core.
 *
 * @return void
 */
function erankly_render_extension_extraction_notice(): void {
	if ( ! current_user_can( is_network_admin() ? 'manage_network_options' : 'manage_options' ) ) {
		return;
	}

	if ( ! erankly_get_plugin_option( ERANKLY_EXTENSION_EXTRACTION_NOTICE_OPTION, 0 ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'EasyRankly 3.0 no longer includes multilingual features. Existing multilingual data was left unchanged; install or activate EasyRankly Multilingual to continue using it.', 'easyrankly' );
	echo '</p></div>';
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
 * Handles settings updates that affect feature bootstrapping.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New option value.
 * @return void
 */
function erankly_handle_settings_updated( mixed $old_value, mixed $value ): void {
	erankly_clear_settings_cache();

	$old_health_enabled  = is_array( $old_value ) && ! empty( $old_value['enable_health'] );
	$new_health_enabled  = is_array( $value ) && ! empty( $value['enable_health'] );
	$old_sitemap_enabled = is_array( $old_value ) && ! empty( $old_value['enable_sitemap'] );
	$new_sitemap_enabled = is_array( $value ) && ! empty( $value['enable_sitemap'] );

	if ( $old_health_enabled && ! $new_health_enabled ) {
		wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );
	}

	if ( $old_sitemap_enabled !== $new_sitemap_enabled ) {
		erankly_load_sitemap_helpers();
		erankly_flush_sitemap_cache();
		return;
	}

	if ( $new_sitemap_enabled ) {
		erankly_load_sitemap_helpers();
		erankly_flush_sitemap_cache();
	}
}

/**
 * Lazily applies the current rewrite signature to this site.
 *
 * @return void
 */
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
 * Removes deactivation-only state from the current site.
 *
 * @return void
 * @throws RuntimeException When a scheduled task cannot be removed.
 */
function erankly_deactivate_current_site(): void {
	foreach ( array( ERANKLY_NETWORK_RESET_CRON_HOOK, 'erankly_health_prune_404_cron', ERANKLY_MIGRATION_CRON_HOOK ) as $hook ) {
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
 * Cancels and verifies removal of the current network reset job.
 *
 * A stale active job must never survive deactivation: the Network Admin
 * self-healing notice would otherwise schedule it again after reactivation.
 *
 * @return void
 * @throws RuntimeException When the reset state cannot be removed.
 */
function erankly_cancel_network_reset_job(): void {
	$missing = 'erankly-reset-missing-' . wp_generate_uuid4();

	delete_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION );

	if ( get_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $missing ) !== $missing ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not cancel the active network reset during deactivation.', 'easyrankly' ) );
	}
}

/**
 * Runs on plugin deactivation.
 *
 * @param bool $network_deactivating Whether this is a network deactivation.
 * @return void
 */
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
 * editing again as panels are added. The char class is just a safe charset
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
 * here. The permission_callback is what keeps subsite admins out.
 *
 * @param WP_REST_Request $request Request object.
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
 * archives" panel, the Multisite fallback for sites that can't use the Site
 * Editor panels (see erankly_use_site_editor_special_page_panels()). Kept
 * separate from erankly_register_settings_autosave_route(): this panel
 * doesn't merge into ERANKLY_OPTION/erankly_get_settings() the way every
 * other panel does. erankly_update_special_meta_map() already owns reading,
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
 * step. Unlike erankly_rest_save_settings_panel(), there's no risk of this
 * payload clobbering another panel's fields since this data isn't part of
 * ERANKLY_OPTION on Multisite at all.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function erankly_rest_save_special_pages( WP_REST_Request $request ): WP_REST_Response {
	erankly_load_content_helpers();
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
