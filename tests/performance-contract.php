<?php
/**
 * Standalone performance and loading-boundary contract.
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// Standalone CLI contract variables are intentionally local to this file.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$root = dirname( __DIR__ );

/**
 * Fails the contract with a useful message.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure detail.
 * @return void
 */
function erankly_perf_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Performance contract failed: {$message}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		exit( 1 );
	}
}

/**
 * Returns the total byte size of repository-relative files.
 *
 * @param string            $root  Repository root.
 * @param array<int,string> $files Repository-relative paths.
 * @return int
 */
function erankly_perf_bytes( string $root, array $files ): int {
	return array_sum(
		array_map(
			static fn( string $file ): int => (int) filesize( $root . '/' . $file ),
			$files
		)
	);
}

$admin           = (string) file_get_contents( $root . '/includes/admin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$bootstrap       = (string) file_get_contents( $root . '/easyrankly.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$helpers         = (string) file_get_contents( $root . '/includes/helpers.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$settings        = (string) file_get_contents( $root . '/admin/settings-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$setup_loader    = (string) file_get_contents( $root . '/admin/setup-wizard-loader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$health_entry    = (string) file_get_contents( $root . '/includes/health.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$health_boot     = (string) file_get_contents( $root . '/includes/health/boot.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$health_routes   = (string) file_get_contents( $root . '/includes/health/broken-links-routes.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$health_crawler  = (string) file_get_contents( $root . '/includes/health/broken-links-crawler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
$loader_start    = strpos( $admin, 'function erankly_admin_load_settings_modules' );
$loader_end      = strpos( $admin, 'function erankly_admin_load_import_export_module', (int) $loader_start );
$base_loader     = false !== $loader_start && false !== $loader_end ? substr( $admin, $loader_start, $loader_end - $loader_start ) : '';
$ai_loader_start = strpos( $helpers, 'function erankly_load_ai_helpers' );
$ai_loader_end   = strpos( $helpers, 'function erankly_load_sitemap_helpers', (int) $ai_loader_start );
$ai_loader       = false !== $ai_loader_start && false !== $ai_loader_end ? substr( $helpers, $ai_loader_start, $ai_loader_end - $ai_loader_start ) : '';

$kernel = erankly_perf_bytes(
	$root,
	array(
		'includes/helpers/core.php',
		'includes/helpers/settings.php',
		'includes/helpers/feature-modules.php',
		'includes/helpers/sanitization.php',
	)
);
erankly_perf_assert( $kernel < 30 * 1024, "always-loaded helper kernel is {$kernel} bytes (budget: < 30 KB)" );

$frontend_bootstrap = erankly_perf_bytes(
	$root,
	array(
		'easyrankly.php',
		'includes/helpers.php',
		'includes/helpers/core.php',
		'includes/helpers/settings.php',
		'includes/helpers/feature-modules.php',
		'includes/helpers/sanitization.php',
		'includes/compatibility.php',
		'includes/compatibility-legacy.php',
		'includes/meta.php',
		'includes/meta-visibility.php',
		'includes/robots.php',
		'includes/special-meta.php',
	)
);
erankly_perf_assert( $frontend_bootstrap <= 146250, "frontend bootstrap is {$frontend_bootstrap} bytes (budget: <= 146250)" );

$ai_rest_source = erankly_perf_bytes(
	$root,
	array(
		'includes/helpers/content-defaults.php',
		'includes/helpers/utils.php',
		'includes/ai.php',
	)
);
erankly_perf_assert( $ai_rest_source < 72 * 1024, "AI REST source is {$ai_rest_source} bytes (budget: < 72 KB)" );

$health_idle_source = erankly_perf_bytes(
	$root,
	array(
		'includes/health.php',
		'includes/health/constants.php',
		'includes/health/boot.php',
	)
);
erankly_perf_assert( $health_idle_source < 12 * 1024, "idle Health source is {$health_idle_source} bytes (budget: < 12 KB)" );

$health_route_source = erankly_perf_bytes( $root, array( 'includes/health/broken-links-routes.php' ) );
erankly_perf_assert( $health_route_source < 6 * 1024, "Health REST route shell is {$health_route_source} bytes (budget: < 6 KB)" );

$import_js  = erankly_perf_bytes(
	$root,
	array( 'assets/js/admin-tabs.js', 'assets/js/admin-fields.js', 'assets/js/admin.js' )
);
$import_css = erankly_perf_bytes(
	$root,
	array( 'assets/css/shared.css', 'assets/css/admin-core.css', 'assets/css/migration.css' )
);
erankly_perf_assert( $import_js <= 35 * 1024, "Import / Export JS is {$import_js} bytes (budget: <= 35 KB)" );
erankly_perf_assert( $import_css <= 25 * 1024, "Import / Export CSS is {$import_css} bytes (budget: <= 25 KB)" );

erankly_perf_assert( str_contains( $admin, 'function erankly_admin_load_import_export_module' ), 'missing contextual Import / Export loader' );
erankly_perf_assert( '' !== $base_loader && ! str_contains( $base_loader, 'import-export.php' ), 'base settings loader still includes import-export.php' );
erankly_perf_assert( '' !== $base_loader && ! str_contains( $base_loader, 'admin/meta-box.php' ), 'base settings loader still includes the classic editor meta box' );
erankly_perf_assert( preg_match( '/function erankly_process_migration_job\(.*?includes\/migrations\.php.*?->process/s', $bootstrap ) === 1, 'cron worker does not load migrations.php directly' );
erankly_perf_assert( ! preg_match( '/function erankly_process_migration_job\(.*?import-export\.php/s', $bootstrap ), 'cron worker still loads import-export.php' );
$migration_loader = (string) file_get_contents( $root . '/includes/migrations.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
foreach ( array( 'runtime-database.php', 'runtime-redirects.php', 'runtime-variables.php', 'runtime-rollbacks.php' ) as $runtime_file ) {
	erankly_perf_assert( str_contains( $migration_loader, $runtime_file ), "migration worker loader is missing {$runtime_file}" );
}
erankly_perf_assert( str_contains( $settings, 'data-erankly-server-tabs' ), 'settings navigation is not server-routed' );
erankly_perf_assert( str_contains( $settings, 'erankly_settings_tab_url' ), 'settings navigation has no no-JS URL builder' );
erankly_perf_assert( ! str_contains( $admin, 'assets/css/admin.css' ), 'legacy monolithic admin.css is still enqueued' );
erankly_perf_assert( ! file_exists( $root . '/assets/css/admin.css' ), 'legacy monolithic admin.css still exists' );
erankly_perf_assert( str_contains( $admin, 'admin/setup-wizard-loader.php' ), 'admin bootstrap does not use the lightweight setup loader' );
erankly_perf_assert( ! str_contains( $admin, 'admin/setup-wizard.php' ), 'admin bootstrap still includes the full setup wizard' );
erankly_perf_assert( strlen( $setup_loader ) < 8 * 1024, 'lightweight setup loader exceeds its 8 KB source budget' );
erankly_perf_assert( preg_match( '/function erankly_setup_wizard_load_screen\(\).*?admin\/setup-wizard\.php/s', $setup_loader ) === 1, 'setup implementation is not deferred behind its request loader' );
erankly_perf_assert( str_contains( $bootstrap, "define( 'ERANKLY_RUNTIME_STATE_OPTION', 'erankly_runtime_state' )" ), 'compact runtime-state option is missing' );
erankly_perf_assert( preg_match( '/add_option\( ERANKLY_RUNTIME_STATE_OPTION, \$state, \'\', true \)/', $bootstrap ) === 1, 'runtime-state option is not created as autoloaded' );
erankly_perf_assert( preg_match( '/\$old_health_enabled && ! \$new_health_enabled.*?wp_clear_scheduled_hook\( \'erankly_health_prune_404_cron\' \)/s', $bootstrap ) === 1, 'Health disable transition does not clear its cron' );
erankly_perf_assert( str_contains( $bootstrap, "add_action( 'rest_api_init', 'erankly_bootstrap_ai_rest_routes', 5 );" ), 'AI has no REST-context dispatcher' );
erankly_perf_assert( preg_match( '/if \( erankly_ai_module_enabled\(\) \).*?if \( is_admin\(\) \).*?erankly_load_ai_module\(\)/s', $bootstrap ) === 1, 'enabled AI is not loaded contextually for admin' );
erankly_perf_assert( preg_match( '/function erankly_bootstrap_ai_rest_routes\(\).*?erankly_load_ai_module\(\).*?erankly_ai_register_rest_routes\(\)/s', $bootstrap ) === 1, 'AI REST dispatcher does not own implementation loading and route registration' );
erankly_perf_assert( '' !== $ai_loader && str_contains( $ai_loader, 'includes/helpers/content-defaults.php' ) && str_contains( $ai_loader, 'includes/helpers/utils.php' ), 'AI helper loader is missing its minimal dependencies' );
erankly_perf_assert( ! str_contains( $ai_loader, 'global-meta.php' ) && ! str_contains( $ai_loader, 'template-variables.php' ), 'AI helper loader includes unrelated rich-content helpers' );
foreach ( array( '404-monitor.php', 'suggestions.php', 'thin-content.php', 'broken-links-crawler.php', 'broken-links-admin.php', 'panel.php' ) as $deferred_health_file ) {
	erankly_perf_assert( ! str_contains( $health_entry, $deferred_health_file ), "Health entrypoint eagerly includes {$deferred_health_file}" );
}
erankly_perf_assert( preg_match( '/function erankly_health_dispatch_frontend_404\(\).*?! is_404\(\).*?erankly_health_load_frontend\(\)/s', $health_boot ) === 1, 'Health frontend loader is not guarded by the resolved 404 condition' );
erankly_perf_assert( str_contains( $health_boot, "add_action( 'rest_api_init', 'erankly_health_bootstrap_rest_routes', 5 );" ), 'Health has no lightweight REST dispatcher' );
erankly_perf_assert( str_contains( $health_routes, 'erankly_health_load_broken_links_crawler();' ), 'Health REST callbacks do not lazy-load the crawler' );
erankly_perf_assert( ! str_contains( $health_crawler, 'register_rest_route' ), 'Health crawler still owns REST route declarations' );
erankly_perf_assert( ! str_contains( $health_crawler, 'array_shift( $state[' ), 'Broken-Link queues still use linear array_shift operations' );
foreach ( array( 'ERANKLY_HEALTH_BL_QUEUE_OPTION', 'ERANKLY_HEALTH_BL_VISITED_OPTION', 'ERANKLY_HEALTH_BL_LINKS_OPTION', 'ERANKLY_HEALTH_BL_CHECK_QUEUE_OPTION', 'ERANKLY_HEALTH_BL_FOUND_OPTION' ) as $segment_constant ) {
	erankly_perf_assert( str_contains( $health_crawler, $segment_constant ), "Broken-Link state is missing segment {$segment_constant}" );
}
erankly_perf_assert( str_contains( $admin, "'health' === \$settings_tab" ) && str_contains( $admin, 'erankly_admin_load_health_module();' ), 'Health admin surface is not loaded contextually from its tab' );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI metrics.
printf(
	'Performance contract passed (kernel=%d B, frontend bootstrap=%d B, AI REST=%d B, idle Health=%d B, Health route shell=%d B, import JS=%d B, import CSS=%d B).' . "\n",
	$kernel,
	$frontend_bootstrap,
	$ai_rest_source,
	$health_idle_source,
	$health_route_source,
	$import_js,
	$import_css
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
