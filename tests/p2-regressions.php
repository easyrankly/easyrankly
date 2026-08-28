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

$root          = dirname( __DIR__ );
$import_source = (string) file_get_contents( $root . '/includes/import-export.php' );
$main_source   = (string) file_get_contents( $root . '/easyrankly.php' );
$schema_source = (string) file_get_contents( $root . '/includes/schema.php' );
$video_source  = (string) file_get_contents( $root . '/includes/helpers/video.php' );
$readme        = (string) file_get_contents( $root . '/readme.txt' );
$distignore    = (string) file_get_contents( $root . '/.distignore' );
$composer      = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );

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
