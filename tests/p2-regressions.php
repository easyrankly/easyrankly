<?php
/**
 * Dependency-free release-engineering regressions for P2.
 *
 * Run: php tests/p2-regressions.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

/** Fails the harness when an invariant is not satisfied. */
function erankly_p2_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "P2 regression failed: {$message}\n" );
		exit( 1 );
	}
}

$root                = dirname( __DIR__ );
$import_loader_path  = $root . '/includes/import-export.php';
$import_module_paths = glob( $root . '/includes/import-export/*.php' ) ?: array();
sort( $import_module_paths, SORT_STRING );
$import_loader = (string) file_get_contents( $import_loader_path );
$import_source = implode(
	"\n",
	array_map(
		static fn( string $path ): string => (string) file_get_contents( $path ),
		array_merge( array( $import_loader_path ), $import_module_paths )
	)
);
$main_source   = (string) file_get_contents( $root . '/easyrankly.php' );
$schema_source = (string) file_get_contents( $root . '/includes/schema.php' );
$video_source  = (string) file_get_contents( $root . '/includes/helpers/video.php' );
$admin_source  = (string) file_get_contents( $root . '/includes/admin.php' );
$settings_source = (string) file_get_contents( $root . '/admin/settings-page.php' );
$settings_renderer_source = (string) file_get_contents( $root . '/admin/settings/page-renderer.php' );
$readme        = (string) file_get_contents( $root . '/readme.txt' );
$distignore    = (string) file_get_contents( $root . '/.distignore' );
$composer      = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );

$expected_import_modules = array( 'actions.php', 'export.php', 'guidance.php', 'panel.php', 'report.php' );
$actual_import_modules   = array_map( 'basename', $import_module_paths );
erankly_p2_assert( $expected_import_modules === $actual_import_modules, 'import/export responsibilities must remain split into the expected modules' );
erankly_p2_assert( ! str_contains( $import_loader, 'function erankly_' ), 'the import/export facade must remain a loader without business logic' );
foreach ( $import_module_paths as $module_path ) {
	$line_count = count( file( $module_path ) ?: array() );
	erankly_p2_assert( $line_count <= 800, basename( $module_path ) . ' must remain below the structural size ceiling' );
}

erankly_p2_assert( ! str_contains( $settings_source, 'function erankly_render_settings_page(' ), 'settings registration and sanitization must stay separate from page rendering' );
erankly_p2_assert( str_contains( $settings_renderer_source, 'function erankly_render_settings_page(' ), 'the settings page renderer must remain available' );
erankly_p2_assert( str_contains( $admin_source, "require_once ERANKLY_PATH . 'admin/settings/page-renderer.php';" ), 'the admin settings loader must include the extracted page renderer' );
erankly_p2_assert( count( file( $root . '/admin/settings-page.php' ) ?: array() ) <= 700, 'settings-page.php must remain below the structural size ceiling' );
erankly_p2_assert( count( file( $root . '/admin/settings/page-renderer.php' ) ?: array() ) <= 700, 'page-renderer.php must remain below the structural size ceiling' );

$removed_functions = array(
	'erankly_import_apply_legacy_unbounded',
	'erankly_import_third_party_posts',
	'erankly_import_yoast_terms',
	'erankly_import_rankmath_terms',
	'erankly_import_aioseo_posts',
	'erankly_import_aioseo_terms',
	'erankly_map_aioseo_meta',
	'erankly_apply_imported_meta',
	'erankly_third_party_source_keys',
	'erankly_map_yoast_meta',
	'erankly_map_rankmath_meta',
);
foreach ( $removed_functions as $function_name ) {
	erankly_p2_assert( ! str_contains( $import_source, 'function ' . $function_name . '(' ), $function_name . ' must not return to the production importer' );
}

erankly_p2_assert( str_contains( $import_source, 'function erankly_import_apply(' ), 'the deprecated bounded native-import wrapper must remain compatible' );
erankly_p2_assert( str_contains( $import_source, 'function erankly_import_third_party(' ), 'the deprecated adapter wrapper must remain compatible' );
erankly_p2_assert( str_contains( $schema_source, 'function erankly_schema_blogposting(' ), 'the public BlogPosting filter surface must not be mistaken for dead code' );
erankly_p2_assert( str_contains( $main_source, "file_exists( \$erankly_plugin_check_helper )" ), 'development-only Plugin Check support must be optional at runtime' );
erankly_p2_assert( str_contains( $distignore, '/includes/plugin-check.php' ) && str_contains( $distignore, '/tests' ), 'the release archive must exclude checkout-only tooling' );
erankly_p2_assert( ! str_contains( $video_source, 'vumbnail.com' ) && str_contains( $readme, 'EasyRankly does not use vumbnail.com.' ), 'the undeclared Vumbnail dependency must stay removed and documented' );
erankly_p2_assert( is_array( $composer ) && isset( $composer['scripts']['check:php'], $composer['scripts']['test:p0'], $composer['scripts']['test:p2'] ), 'tracked PHP quality scripts must remain available' );
erankly_p2_assert( strlen( $readme ) < 10000, 'WordPress.org readme must remain below 10,000 bytes' );

$orphan_classes = array(
	'erankly-alert',
	'erankly-panel-callout',
	'erankly-panel-controls',
	'erankly-panel-empty',
	'erankly-panel-filter',
	'erankly-popover-surface',
	'erankly-seo-checklist-help',
	'erankly-seo-checklist-intro',
	'erankly-suggest-menu',
	'erankly-suggest-option',
);
$css            = implode( "\n", array_map( static fn( string $path ): string => (string) file_get_contents( $path ), glob( $root . '/assets/css/*.css' ) ?: array() ) );
foreach ( $orphan_classes as $class_name ) {
	erankly_p2_assert( ! str_contains( $css, '.' . $class_name ), $class_name . ' must not return without a production consumer' );
}

fwrite( STDOUT, "P2 regressions passed.\n" );
