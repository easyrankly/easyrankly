<?php
/** Third-party SEO migration subsystem loader. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/migrations/runtime-database.php';
require_once ERANKLY_PATH . 'includes/migrations/runtime-redirects.php';
require_once ERANKLY_PATH . 'includes/migrations/runtime-variables.php';
require_once ERANKLY_PATH . 'includes/migrations/runtime-backup.php';

$erankly_migration_files = array(
	'class-erankly-migration-adapter.php',
	'class-erankly-migration-upload-store.php',
	'class-erankly-migration-manager.php',
	'class-erankly-migration-source-changed-exception.php',
	'class-erankly-migration-job-runner.php',
);

foreach ( $erankly_migration_files as $erankly_migration_file ) {
	require_once ERANKLY_PATH . 'includes/migrations/' . $erankly_migration_file;
}

unset( $erankly_migration_file, $erankly_migration_files );

/**
 * Acquires the short-lived gate used while either data-transfer worker creates
 * its durable checkpoint. Native restores and third-party migrations mutate
 * the same settings, metadata and redirect data, so checking their separate
 * active-job options without this gate would leave a start-time race.
 *
 * @return string Lock token, or an empty string when another start is active.
 */
function erankly_acquire_data_transfer_start_lock(): string {
	global $wpdb;

	$key   = 'erankly_data_transfer_start_lock_v1';
	$token = wp_generate_uuid4();
	$lock  = array(
		'token'   => $token,
		'expires' => time() + 300,
	);

	if ( add_option( $key, $lock, '', 'no' ) ) {
		return $token;
	}

	$existing = get_option( $key, array() );
	if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) >= time() ) {
		return '';
	}

	$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap prevents two transfer starts from passing the cross-worker check together.
		$wpdb->prepare(
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
			$wpdb->options,
			maybe_serialize( $lock ),
			$key,
			maybe_serialize( $existing )
		)
	);
	if ( 1 === $updated ) {
		wp_cache_delete( $key, 'options' );
	}

	return 1 === $updated ? $token : '';
}

/** Releases the data-transfer start gate only when this request owns it. */
function erankly_release_data_transfer_start_lock( string $token ): void {
	global $wpdb;

	$key      = 'erankly_data_transfer_start_lock_v1';
	$existing = get_option( $key, array() );
	if ( ! is_array( $existing ) || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
		return;
	}

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete cannot release a successor's start gate.
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
			$wpdb->options,
			$key,
			maybe_serialize( $existing )
		)
	);
	wp_cache_delete( $key, 'options' );
}

/**
 * Loads one source adapter only when a request actually selects it.
 *
 * @return bool Whether the adapter class is available.
 */
function erankly_migration_load_adapter( string $source ): bool {
	$adapters = array(
		'yoast'    => array( 'class-erankly-migration-adapter-yoast.php', 'ERankly_Migration_Adapter_Yoast' ),
		'rankmath' => array( 'class-erankly-migration-adapter-rankmath.php', 'ERankly_Migration_Adapter_RankMath' ),
		'aioseo'   => array( 'class-erankly-migration-adapter-aioseo.php', 'ERankly_Migration_Adapter_AIOSEO' ),
		'seopress' => array( 'class-erankly-migration-adapter-seopress.php', 'ERankly_Migration_Adapter_SEOPress' ),
	);
	$source   = sanitize_key( $source );

	if ( ! isset( $adapters[ $source ] ) ) {
		return false;
	}

	$class = $adapters[ $source ][1];
	if ( ! class_exists( $class, false ) ) {
		require_once ERANKLY_PATH . 'includes/migrations/' . $adapters[ $source ][0];
	}

	return class_exists( $class, false );
}

/**
 * Returns the shared migration manager instance.
 *
 * @return ERankly_Migration_Manager
 */
function erankly_migration_manager(): ERankly_Migration_Manager {
	static $manager = null;

	if ( null === $manager ) {
		$manager = new ERankly_Migration_Manager();
	}

	return $manager;
}

/**
 * Returns the shared resumable migration runner.
 *
 * @return ERankly_Migration_Job_Runner
 */
function erankly_migration_job_runner(): ERankly_Migration_Job_Runner {
	static $runner = null;

	if ( null === $runner ) {
		$runner = new ERankly_Migration_Job_Runner();
	}

	return $runner;
}
