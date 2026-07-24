<?php
// phpcs:ignoreFile -- M10 boundary test intentionally scans distributed source text.
/**
 * Certifies the EasyRankly 3.0 multilingual extraction boundary.
 *
 * @package EasyRankly
 */

declare(strict_types=1);

$root     = dirname( __DIR__, 2 );
$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$forbidden_paths = array(
	'assets/css/multilingual-frontend.css',
	'assets/css/multilingual.css',
	'assets/js/multilingual-frontend.js',
	'assets/js/multilingual.js',
	'includes/class-erankly-bundled-multilingual-provider.php',
	'includes/multilingual-ownership.php',
	'includes/multilingual.php',
	'includes/multilingual',
);
foreach ( $forbidden_paths as $relative ) {
	$assert( ! file_exists( $root . '/' . $relative ), 'Core 3.0 still contains concrete multilingual path: ' . $relative );
}

$files  = array();
$ignore = array( '.git', '.github', '.codegraph', 'bin', 'build', 'coverage', 'dist', 'docs', 'node_modules', 'tests', 'vendor' );
$walk   = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $current ) use ( $ignore ): bool {
			return ! $current->isDir() || ! in_array( $current->getFilename(), $ignore, true );
		}
	)
);
foreach ( $walk as $file ) {
	if ( $file instanceof SplFileInfo && $file->isFile() ) {
		$files[] = $file->getPathname();
	}
}
sort( $files, SORT_STRING );

$source = '';
foreach ( $files as $file ) {
	$contents = file_get_contents( $file );
	if ( false === $contents ) {
		$failures[] = 'Unreadable distributed source: ' . $file;
		continue;
	}
	$source .= "\n" . $contents;
}

$forbidden_tokens = array(
	'ERankly_ML_'                          => 'concrete legacy class',
	'ERankly_Bundled_Multilingual_Provider' => 'bundled provider implementation',
	'ERANKLY_ML_'                          => 'multilingual storage/cache constant',
	'erankly_ml_'                          => 'multilingual storage/lifecycle function',
	'enable_multilingual'                  => 'legacy concrete feature toggle',
	'erankly_ml_relations'                 => 'legacy relation table',
	'erankly_ml_sites'                     => 'legacy site map option',
	'erankly_ml_db_version'                => 'legacy schema option',
	'erankly_ml_storage_owner'             => 'extension ownership marker',
	'erankly_ml_cache_generation'          => 'extension cache generation',
	'/erankly/v1/ml/'                      => 'legacy concrete REST route',
	'/erankly/v1/settings/multilingual'    => 'legacy settings REST route',
	'erankly-multilingual-frontend'        => 'legacy frontend asset handle',
	'erankly-multilingual-admin'           => 'legacy admin asset handle',
);
foreach ( $forbidden_tokens as $needle => $meaning ) {
	$assert( ! str_contains( $source, $needle ), 'Core 3.0 contains forbidden ' . $meaning . ': ' . $needle );
}

$main     = (string) file_get_contents( $root . '/easyrankly.php' );
$registry = (string) file_get_contents( $root . '/includes/class-erankly-multilingual-provider-registry.php' );
$writer   = (string) file_get_contents( $root . '/includes/localized-value-writer.php' );
$lock     = (string) file_get_contents( $root . '/includes/settings-lock.php' );
$settings = (string) file_get_contents( $root . '/admin/settings-page.php' );
$uninstall = (string) file_get_contents( $root . '/uninstall.php' );

$assert( str_contains( $main, "* Version:     3.0.0" ), 'Core header is not 3.0.0.' );
$assert( str_contains( $main, "define( 'ERANKLY_VERSION', '3.0.0' )" ), 'ERANKLY_VERSION is not 3.0.0.' );
$assert( str_contains( $main, "define( 'ERANKLY_EXTENSION_API_VERSION', 1 )" ), 'Provider API major 1 was removed.' );
$assert( str_contains( $main, 'Existing multilingual data was left unchanged' ), 'The 2.1-to-3.0 extraction notice is missing.' );
$assert( str_contains( $registry, 'interface ERankly_Multilingual_Provider_Interface' ), 'The neutral provider interface is missing.' );
$assert( str_contains( $registry, 'function erankly_register_multilingual_provider' ), 'The neutral provider registration API is missing.' );
$assert( str_contains( $registry, 'function erankly_get_multilingual_provider' ), 'The neutral selected-provider API is missing.' );
$assert( ! str_contains( $registry, 'bundled' ), 'The 3.0 registry still contains bundled fallback selection.' );
$assert( str_contains( $writer, 'function erankly_get_localized_value_source_state' ), 'The localized-source state API is missing.' );
$assert( str_contains( $writer, 'function erankly_update_localized_value_source' ), 'The localized-source writer API is missing.' );
$assert( str_contains( $lock, 'function erankly_acquire_settings_lock' ), 'The provider-neutral settings mutex is missing.' );
$assert( str_contains( $lock, 'erankly_preserved_extension_settings' ), 'Whole-settings replacement does not preserve extension keys.' );
$assert( str_contains( $settings, 'erankly_settings_tabs' ), 'The neutral settings-tab extension point is missing.' );
$assert( str_contains( $settings, 'erankly_preserved_extension_settings' ), 'Settings sanitization does not preserve stored extension keys.' );
$assert( ! str_contains( $uninstall, 'multilingual' ), 'Core uninstall still contains multilingual lifecycle logic.' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, 'EasyRankly M10 core 3.0 source boundary passed for ' . count( $files ) . " distributed files.\n" );
