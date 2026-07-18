<?php
/**
 * Third-party SEO migration subsystem loader.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$erankly_migration_files = array(
	'class-erankly-migration-adapter.php',
	'class-erankly-migration-export-reader.php',
	'class-erankly-migration-upload-store.php',
	'class-erankly-migration-adapter-yoast.php',
	'class-erankly-migration-adapter-rankmath.php',
	'class-erankly-migration-adapter-aioseo.php',
	'class-erankly-migration-adapter-seopress.php',
	'class-erankly-migration-go-live-gate.php',
	'class-erankly-migration-admin-presenter.php',
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
