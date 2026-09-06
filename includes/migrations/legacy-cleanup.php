<?php
/** Removal of state left behind by retired migration subsystems. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes options and tables owned by migration features that no longer ship.
 *
 * Reset, uninstall and the version upgrade routine all call this so a site updated from an earlier
 * release does not keep orphaned rows that nothing can ever read again.
 *
 * @param bool $allow_active_migration Whether an explicit destructive lifecycle operation may clean while a
 *                                     current migration checkpoint exists.
 * @return bool False when a database statement fails or an active migration must be left untouched.
 */
function erankly_migration_purge_legacy_state( bool $allow_active_migration = false ): bool {
	global $wpdb;

	$active_option = defined( 'ERANKLY_MIGRATION_ACTIVE_JOB_OPTION' ) ? ERANKLY_MIGRATION_ACTIVE_JOB_OPTION : 'erankly_migration_active_job_v1';
	$active_job    = get_option( $active_option, array() );
	if ( ! $allow_active_migration && is_array( $active_job ) && ! empty( $active_job['id'] ) ) {
		return false;
	}

	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk removal of prefixed options retired subsystems left behind.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( 'erankly_migration_verify_job_' ) . '%',
			$wpdb->esc_like( 'erankly_migration_rollback_' ) . '%',
			$wpdb->esc_like( 'erankly_migration_rollback_lock_' ) . '%',
			'erankly_migration_queue_db_version',
			'erankly_migration_journal_db_version',
			'erankly_migration_evidence_db_version'
		)
	);

	if ( false === $deleted ) {
		return false;
	}

	foreach ( array( 'erankly_migration_queue', 'erankly_migration_changes', 'erankly_migration_exceptions' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Retired tables have no API.
			return false;
		}
	}

	foreach ( array( 'erankly_migration_rollback_batch', 'erankly_migration_verify_batch' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	return true;
}
