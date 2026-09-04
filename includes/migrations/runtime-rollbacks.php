<?php
/** Shared report updates for web-request and WP-Cron rollback batches. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists one rollback checkpoint into its migration report.
 *
 * @param array<string,int|string> $result Current cumulative rollback result.
 * @return bool Whether an existing report was updated.
 */
function erankly_migration_record_rollback_result( string $job_id, array $result ): bool {
	$manager = erankly_migration_manager();
	$report  = $manager->get_report( $job_id );
	if ( ! is_array( $report ) || 'import' !== (string) ( $report['mode'] ?? '' ) ) {
		return false;
	}

	$status                    = sanitize_key( (string) ( $result['status'] ?? 'failed' ) );
	$previous                  = is_array( $report['rollback_result'] ?? null ) ? $report['rollback_result'] : array();
	$report['rollback_result'] = array_merge(
		$result,
		array(
			'requested_at' => (string) ( $previous['requested_at'] ?? gmdate( 'c' ) ),
			'updated_at'   => gmdate( 'c' ),
		)
	);
	if ( isset( $report['evidence'] ) && is_array( $report['evidence'] ) ) {
		$report['evidence']['rollback'] = erankly_migration_journal()->summary( $job_id );
	}
	$report['verification']['ready_to_switch'] = false;
	if ( 'running' === $status ) {
		$report['verification']['state'] = 'rollback_running';
	} elseif ( 'failed' === $status ) {
		$report['verification']['state'] = 'rollback_failed';
	} elseif ( 'partial' === $status ) {
		$report['verification']['state'] = 'rollback_partial';
	} elseif ( 'expired' === $status ) {
		$report['verification']['state'] = 'blocked';
	} else {
		$report['verification']['state'] = 'rolled_back';
	}

	$updated = $manager->update_report( $report );
	if ( 'running' !== $status ) {
		erankly_migration_journal()->clear_rollback_checkpoint( $job_id );
	}

	return $updated;
}
