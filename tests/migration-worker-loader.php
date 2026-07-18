<?php
// phpcs:ignoreFile -- Dependency-boundary regression harness.
/**
 * Verifies that the migration worker loader owns every shared runtime helper.
 *
 * @package EasyRankly
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ERANKLY_PATH', dirname( __DIR__ ) . '/' );

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
}

require ERANKLY_PATH . 'includes/migrations.php';

$required = array(
	'erankly_table_exists',
	'erankly_import_convert_variables',
	'erankly_import_variable_diagnostics',
	'erankly_ensure_redirect_classes_available',
	'erankly_import_prepare_redirect',
);

foreach ( $required as $function ) {
	if ( ! function_exists( $function ) ) {
		fwrite( STDERR, "Migration worker runtime helper is missing: {$function}.\n" );
		exit( 1 );
	}
}

foreach ( array( 'ERankly_Migration_Admin_Presenter', 'ERankly_Migration_Adapter_Yoast', 'ERankly_Migration_Adapter_RankMath', 'ERankly_Migration_Adapter_AIOSEO', 'ERankly_Migration_Adapter_SEOPress' ) as $deferred_class ) {
	if ( class_exists( $deferred_class, false ) ) {
		fwrite( STDERR, "Migration worker loader eagerly loaded {$deferred_class}.\n" );
		exit( 1 );
	}
}

if ( ! erankly_migration_load_adapter( 'yoast' ) || ! class_exists( 'ERankly_Migration_Adapter_Yoast', false ) || class_exists( 'ERankly_Migration_Adapter_RankMath', false ) ) {
	fwrite( STDERR, "Migration adapter loader is not source-targeted.\n" );
	exit( 1 );
}

$included = array_map( static fn( string $path ): string => str_replace( '\\', '/', $path ), get_included_files() );
if ( in_array( str_replace( '\\', '/', ERANKLY_PATH . 'includes/import-export.php' ), $included, true ) ) {
	fwrite( STDERR, "The migration worker loader pulled in the Import / Export UI module.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Migration worker dependency boundary passed.\n" );
