<?php
/**
 * Aligns Plugin Check with the WordPress.org distribution tree.
 *
 * Development checkouts keep tests, tooling, and repo metadata beside the
 * plugin. Tools → Plugin Check scans that folder. Those paths are already
 * excluded from the shipping ZIP by `.distignore`; this filter makes the
 * in-dashboard check use the same surface.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directories excluded from Plugin Check, matching `.distignore`.
 *
 * @return array<int,string>
 */
function erankly_plugin_check_ignored_directories(): array {
	return array(
		'.git',
		'.github',
		'.codegraph',
		'.idea',
		'.vscode',
		'vendor',
		'node_modules',
		'tests',
		'docs',
		'bin',
		'coverage',
		'build',
		'dist',
	);
}

/**
 * Files excluded from Plugin Check, matching `.distignore`.
 *
 * @return array<int,string>
 */
function erankly_plugin_check_ignored_files(): array {
	return array(
		'.gitignore',
		'.gitattributes',
		'.distignore',
		'.editorconfig',
		'.env',
		'.eslintcache',
		'.phpcs-cache',
		'.phpunit.result.cache',
		'.stylelintcache',
		'composer.json',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'phpcs.xml',
		'phpcs.xml.dist',
		'phpunit.xml',
		'phpunit.xml.dist',
		'eslint.config.mjs',
		'stylelint.config.mjs',
	);
}

/**
 * Adds EasyRankly distribution excludes to Plugin Check directory ignores.
 *
 * @param array<int,string> $directories Directories already ignored.
 * @return array<int,string>
 */
function erankly_plugin_check_ignore_directories( array $directories ): array {
	return array_values( array_unique( array_merge( $directories, erankly_plugin_check_ignored_directories() ) ) );
}

/**
 * Adds EasyRankly distribution excludes to Plugin Check file ignores.
 *
 * @param array<int,string> $files Files already ignored.
 * @return array<int,string>
 */
function erankly_plugin_check_ignore_files( array $files ): array {
	return array_values( array_unique( array_merge( $files, erankly_plugin_check_ignored_files() ) ) );
}

add_filter( 'wp_plugin_check_ignore_directories', 'erankly_plugin_check_ignore_directories' );
add_filter( 'wp_plugin_check_ignore_files', 'erankly_plugin_check_ignore_files' );
