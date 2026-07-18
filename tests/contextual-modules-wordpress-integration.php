<?php
// phpcs:ignoreFile -- WP-CLI integration harness inspects one ephemeral request.
/**
 * Real WordPress contextual loading test with AI, Health and Link Building on.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

function erankly_contextual_modules_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function erankly_contextual_modules_included( string $relative_path ): bool {
	return in_array(
		wp_normalize_path( ERANKLY_PATH . $relative_path ),
		array_map( 'wp_normalize_path', get_included_files() ),
		true
	);
}

$original_settings = get_option( 'erankly_contextual_modules_original_settings', null );
erankly_contextual_modules_assert( is_array( $original_settings ), 'The contextual module fixture snapshot is missing.' );
erankly_contextual_modules_assert( erankly_ai_module_enabled(), 'AI must be enabled for the contextual boundary request.' );
erankly_contextual_modules_assert( erankly_health_enabled(), 'Health must be enabled for the contextual boundary request.' );
erankly_contextual_modules_assert( erankly_link_building_enabled(), 'Link Building must be enabled for the contextual boundary request.' );

erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/ai.php' ), 'Ordinary CLI/frontend bootstrap must not parse AI when it is enabled.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/helpers/content-defaults.php' ), 'Ordinary bootstrap must not load AI content defaults.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/helpers/utils.php' ), 'Ordinary bootstrap must not load AI connector utilities.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health.php' ), 'Enabled Health must load its lightweight entrypoint.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/boot.php' ), 'Enabled Health must load its lightweight router.' );

foreach (
	array(
		'includes/health/frontend.php',
		'includes/health/404-monitor.php',
		'includes/health/suggestions.php',
		'includes/health/thin-content.php',
		'includes/health/broken-links-routes.php',
		'includes/health/broken-links-crawler.php',
		'includes/health/broken-links-admin.php',
		'includes/health/panel.php',
	) as $deferred_file
) {
	erankly_contextual_modules_assert( ! erankly_contextual_modules_included( $deferred_file ), "Ordinary bootstrap must defer {$deferred_file}." );
}

erankly_contextual_modules_assert( false !== has_action( 'rest_api_init', 'erankly_bootstrap_ai_rest_routes' ), 'AI must register its lightweight REST dispatcher.' );
erankly_contextual_modules_assert( false !== has_action( 'rest_api_init', 'erankly_health_bootstrap_rest_routes' ), 'Health must register its lightweight REST dispatcher.' );

$server = rest_get_server();

erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/ai.php' ), 'REST initialization must load AI.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/helpers/content-defaults.php' ), 'AI REST must load content defaults.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/helpers/utils.php' ), 'AI REST must load connector utilities.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/broken-links-routes.php' ), 'REST initialization must load Health route declarations.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/health/broken-links-crawler.php' ), 'REST initialization must defer the heavy Health crawler.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/health/404-monitor.php' ), 'REST initialization must not load the frontend 404 monitor.' );

$routes = $server->get_routes();
erankly_contextual_modules_assert( isset( $routes['/erankly/v1/ai/generate'] ), 'AI generation route must remain registered.' );
erankly_contextual_modules_assert( isset( $routes['/erankly/v1/health/broken-links/start'] ), 'Health crawler start route must remain registered.' );

$cancel_response = erankly_health_bl_rest_cancel();
erankly_contextual_modules_assert( $cancel_response instanceof WP_REST_Response, 'The lazy Health crawler callback must return a REST response.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/broken-links-crawler.php' ), 'Executing a Health crawler route must load its implementation.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/health/404-monitor.php' ), 'Health crawler execution must not load the frontend monitor.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/health/panel.php' ), 'Health crawler execution must not load admin panels.' );

erankly_health_load_frontend();
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/404-monitor.php' ), 'The explicit frontend loader must load the 404 monitor.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/health/suggestions.php' ), 'The frontend 404 loader must not load suggestions.' );
erankly_contextual_modules_assert( ! erankly_contextual_modules_included( 'includes/health/panel.php' ), 'The frontend 404 loader must not load panels.' );

erankly_health_load_admin_surface();
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/suggestions.php' ), 'The Health admin surface must load suggestions.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/thin-content.php' ), 'The Health admin surface must load thin-content scanning.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/broken-links-admin.php' ), 'The Health admin surface must load crawler rendering.' );
erankly_contextual_modules_assert( erankly_contextual_modules_included( 'includes/health/panel.php' ), 'The Health admin surface must load its panel.' );

erankly_update_plugin_option( ERANKLY_OPTION, $original_settings );
delete_option( 'erankly_contextual_modules_original_settings' );
wp_clear_scheduled_hook( ERANKLY_HEALTH_404_PRUNE_HOOK );

foreach (
	array(
		ERANKLY_HEALTH_404_CANDIDATES_OPTION,
		ERANKLY_HEALTH_404_FREQUENT_OPTION,
		ERANKLY_HEALTH_404_STATES_OPTION,
		ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION,
		ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION,
		ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION,
		ERANKLY_HEALTH_THIN_OPTION,
		ERANKLY_HEALTH_BL_STATE_OPTION,
		ERANKLY_HEALTH_BL_RESULTS_OPTION,
	) as $cleanup_option
) {
	delete_option( $cleanup_option );
}

WP_CLI::success( 'AI and Health contextual loading integration passed.' );
