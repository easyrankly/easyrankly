<?php
// phpcs:ignoreFile -- WP-CLI integration harness inspects one ephemeral request.
/**
 * Real WordPress loading-boundary and asset-manifest integration test.
 *
 * Run inside a fresh WordPress installation with EasyRankly active:
 * wp eval-file wp-content/plugins/easyrankly/tests/performance-wordpress-integration.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

function erankly_performance_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$included = array_map( 'wp_normalize_path', get_included_files() );
erankly_performance_wp_assert( ! in_array( wp_normalize_path( ERANKLY_PATH . 'includes/import-export.php' ), $included, true ), 'Ordinary bootstrap must not include Import / Export.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_migration_job_runner' ), 'Ordinary bootstrap must not load migration classes.' );
erankly_performance_wp_assert( ! in_array( wp_normalize_path( ERANKLY_PATH . 'includes/ai.php' ), $included, true ), 'Ordinary bootstrap must not include disabled AI.' );
erankly_performance_wp_assert( ! in_array( wp_normalize_path( ERANKLY_PATH . 'includes/health.php' ), $included, true ), 'Ordinary bootstrap must not include disabled Health.' );

require_once ERANKLY_PATH . 'includes/admin.php';
erankly_admin_bootstrap();

$included = array_map( 'wp_normalize_path', get_included_files() );
erankly_performance_wp_assert( in_array( wp_normalize_path( ERANKLY_PATH . 'admin/setup-wizard-loader.php' ), $included, true ), 'Admin bootstrap must load the lightweight setup router.' );
erankly_performance_wp_assert( ! in_array( wp_normalize_path( ERANKLY_PATH . 'admin/setup-wizard.php' ), $included, true ), 'Ordinary admin bootstrap must defer the setup form and renderer.' );
erankly_performance_wp_assert( function_exists( 'erankly_setup_wizard_render' ), 'The deferred setup loader must preserve the public render callback.' );

$alloptions = wp_load_alloptions();
erankly_performance_wp_assert( isset( $alloptions[ ERANKLY_RUNTIME_STATE_OPTION ] ), 'Compact runtime state must be autoloaded.' );
$runtime_state = get_option( ERANKLY_RUNTIME_STATE_OPTION, array() );
erankly_performance_wp_assert( ERANKLY_VERSION === ( $runtime_state['version'] ?? '' ), 'Runtime state must contain the activated plugin version.' );
erankly_performance_wp_assert( '' !== ( $runtime_state['rewrite_generation'] ?? '' ), 'Runtime state must contain the rewrite generation.' );
erankly_performance_wp_assert( get_option( ERANKLY_SETUP_STATUS_OPTION, '' ) === ( $runtime_state['setup_status'] ?? null ), 'Runtime state must mirror the legacy setup status.' );

global $erankly_runtime_state_cache;
unset( $erankly_runtime_state_cache );
$queries_before = get_num_queries();
erankly_get_plugin_option( ERANKLY_VERSION_OPTION, '' );
erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '0' );
erankly_get_plugin_option( ERANKLY_SETUP_STATUS_OPTION, '' );
$queries_after = get_num_queries();
erankly_performance_wp_assert( $queries_after === $queries_before, 'Hot runtime-state reads must use the autoloaded aggregate without new queries.' );

wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'erankly_health_prune_404_cron' );
erankly_performance_wp_assert( false !== wp_next_scheduled( 'erankly_health_prune_404_cron' ), 'Health cron fixture must be scheduled.' );
erankly_handle_settings_updated( array( 'enable_health' => 1 ), array( 'enable_health' => 0 ) );
erankly_performance_wp_assert( false === wp_next_scheduled( 'erankly_health_prune_404_cron' ), 'Turning Health off must clear its scheduled cron.' );

erankly_admin_load_settings_modules();

$included = array_map( 'wp_normalize_path', get_included_files() );
erankly_performance_wp_assert( ! in_array( wp_normalize_path( ERANKLY_PATH . 'includes/import-export.php' ), $included, true ), 'General settings loader must not include Import / Export.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_migration_job_runner' ), 'General settings loader must not load migration classes.' );
erankly_performance_wp_assert( ! in_array( wp_normalize_path( ERANKLY_PATH . 'admin/meta-box.php' ), $included, true ), 'General settings loader must not include the classic editor meta box.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_get_seo_checklist_items' ), 'General settings loader must not load the editor-only SEO checklist.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_extract_video_urls' ), 'General settings loader must not load video extraction helpers.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_render_variable_picker' ), 'Base settings registration must defer active-panel field renderers.' );

$queries_before = get_num_queries();
erankly_get_setting( 'site_name_separator', '|' );
erankly_get_setting( 'enable_schema', 1 );
$queries_after = get_num_queries();
erankly_performance_wp_assert( $queries_after === $queries_before, 'Key-level settings reads must reuse the autoloaded option without new queries.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_default_settings' ), 'Key-level settings reads must not build the full default model.' );

// Exercise the real admin_init registration path before rendering the page.
// This must own its default-model dependency instead of relying on a renderer
// to have loaded it first.
erankly_register_settings();
$registered_settings = get_registered_settings();
erankly_performance_wp_assert( isset( $registered_settings[ ERANKLY_OPTION ] ), 'Settings registration must complete with its default-model dependency loaded.' );

erankly_performance_wp_assert(
	array( 'tabs', 'variables', 'schema', 'widgets', 'settings' ) === erankly_admin_asset_modules( 'settings:general' ),
	'General must receive only its declared JavaScript modules.'
);
erankly_performance_wp_assert(
	array( 'tabs', 'fields' ) === erankly_admin_asset_modules( 'settings:import-export' ),
	'Import / Export must receive only tab and upload-field JavaScript.'
);
erankly_performance_wp_assert(
	in_array( 'media', erankly_admin_asset_modules( 'settings:social' ), true )
		&& ! in_array( 'media', erankly_admin_asset_modules( 'settings:import-export' ), true ),
	'The Media Library must remain limited to surfaces with a media picker.'
);
erankly_performance_wp_assert( 'extension-example' === erankly_admin_resolve_settings_tab( 'extension-example' ), 'Unknown extension tabs must remain routable.' );

wp_set_current_user( 1 );
$_GET['erankly_tab'] = 'general';
ob_start();
erankly_render_settings_page();
$general_html = (string) ob_get_clean();
erankly_performance_wp_assert( str_contains( $general_html, 'erankly-settings-panel-general' ), 'General must render as the selected server-side panel.' );
erankly_performance_wp_assert( ! str_contains( $general_html, 'erankly-settings-panel-import-export' ), 'General HTML must not contain the hidden Import / Export panel.' );
erankly_performance_wp_assert( function_exists( 'erankly_render_variable_picker' ), 'General must load shared field renderers when its active panel needs them.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_get_seo_checklist_items' ), 'Rendering General must still exclude the editor-only SEO checklist.' );
erankly_performance_wp_assert( ! function_exists( 'erankly_migration_job_runner' ), 'Rendering General must still exclude migration classes.' );
$plugin_path          = wp_normalize_path( ERANKLY_PATH );
$general_source_bytes = array_sum(
	array_map(
		static fn( string $file ): int => str_starts_with( wp_normalize_path( $file ), $plugin_path ) ? (int) filesize( $file ) : 0,
		get_included_files()
	)
);
erankly_performance_wp_assert( $general_source_bytes < 550 * 1024, 'General settings source footprint must remain below 550 KB.' );

erankly_setup_wizard_load_screen();
erankly_performance_wp_assert( function_exists( 'erankly_setup_wizard_handle_save' ), 'The explicit setup loader must load the save handler.' );
erankly_performance_wp_assert( function_exists( 'erankly_setup_wizard_render_screen' ), 'The explicit setup loader must load the renderer.' );

erankly_admin_load_import_export_module();
$included = array_map( 'wp_normalize_path', get_included_files() );
erankly_performance_wp_assert( in_array( wp_normalize_path( ERANKLY_PATH . 'includes/import-export.php' ), $included, true ), 'The explicit Import / Export loader must load its backend.' );
erankly_performance_wp_assert( function_exists( 'erankly_migration_job_runner' ), 'The explicit Import / Export loader must load migration classes.' );

WP_CLI::success( sprintf( 'Performance loading-boundary integration passed (General PHP source: %d B).', $general_source_bytes ) );
