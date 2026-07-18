<?php
/**
 * Third-party SEO migration subsystem loader.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/migrations/runtime-database.php';
require_once ERANKLY_PATH . 'includes/migrations/runtime-redirects.php';
require_once ERANKLY_PATH . 'includes/migrations/runtime-variables.php';
require_once ERANKLY_PATH . 'includes/migrations/runtime-rollbacks.php';

$erankly_migration_files = array(
	'class-erankly-migration-adapter.php',
	'class-erankly-migration-export-reader.php',
	'class-erankly-migration-upload-store.php',
	'class-erankly-migration-go-live-gate.php',
	'class-erankly-migration-manager.php',
	'class-erankly-migration-job-store.php',
	'class-erankly-migration-evidence-store.php',
	'class-erankly-migration-journal.php',
	'class-erankly-migration-auditor.php',
	'class-erankly-migration-live-verifier.php',
	'class-erankly-migration-source-changed-exception.php',
	'class-erankly-migration-job-runner.php',
);

foreach ( $erankly_migration_files as $erankly_migration_file ) {
	require_once ERANKLY_PATH . 'includes/migrations/' . $erankly_migration_file;
}

unset( $erankly_migration_file, $erankly_migration_files );

/**
 * Loads one source adapter only when a request actually selects it.
 *
 * @param string $source Adapter slug.
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

/** Loads every adapter for source-selection screens and compatibility tests. */
function erankly_migration_load_all_adapters(): void {
	foreach ( array( 'yoast', 'rankmath', 'aioseo', 'seopress' ) as $source ) {
		erankly_migration_load_adapter( $source );
	}
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

/** Returns the shared conditional rollback journal. */
function erankly_migration_journal(): ERankly_Migration_Journal {
	static $journal = null;

	if ( null === $journal ) {
		$journal = new ERankly_Migration_Journal();
	}

	return $journal;
}

/** Returns the shared complete exception ledger. */
function erankly_migration_evidence_store(): ERankly_Migration_Evidence_Store {
	static $store = null;

	if ( null === $store ) {
		$store = new ERankly_Migration_Evidence_Store();
	}

	return $store;
}
