<?php
// phpcs:ignoreFile -- Verifies two independent processes cannot lose a localized-source update.
/**
 * Public writer concurrency verification and cleanup.
 *
 * @package EasyRankly
 */

wp_set_current_user( 1 );

$fixture  = (array) get_option( 'erankly_writer_concurrency_fixture', array() );
$worker_a = (array) get_option( 'erankly_writer_concurrency_result_a', array() );
$worker_b = (array) get_option( 'erankly_writer_concurrency_result_b', array() );
$state    = erankly_get_localized_value_source_state( 'organization_name' );
$failures = array();

if ( empty( $worker_a['success'] ) ) {
	$failures[] = 'The first writer did not complete.';
}
if ( 'erankly_localized_value_source_revision_conflict' !== (string) ( $worker_b['error'] ?? '' ) ) {
	$failures[] = 'The second writer did not reject its stale shared snapshot.';
}
if ( is_wp_error( $state ) || 'Concurrent writer A' !== (string) ( $state['value'] ?? '' ) ) {
	$failures[] = 'The winning write was lost or not verified.';
}
if ( (string) ( $fixture['unrelated'] ?? '' ) !== (string) erankly_get_setting( 'organization_phone', '' ) ) {
	$failures[] = 'The concurrent source write lost an unrelated setting.';
}

if ( ! $failures ) {
	$retry = erankly_update_localized_value_source(
		'organization_name',
		'Concurrent writer B',
		(string) $state['fingerprint']
	);
	if ( is_wp_error( $retry ) || empty( $retry['changed'] ) ) {
		$failures[] = 'The stale writer could not retry from a fresh fingerprint.';
	} else {
		$restore = erankly_update_localized_value_source(
			'organization_name',
			(string) $fixture['original_value'],
			(string) $retry['fingerprint']
		);
		$again = erankly_update_localized_value_source(
			'organization_name',
			(string) $fixture['original_value'],
			(string) $retry['fingerprint']
		);
		if ( is_wp_error( $restore ) || empty( $restore['changed'] ) ) {
			$failures[] = 'The concurrency fixture could not restore the original source value.';
		}
		if ( is_wp_error( $again ) || empty( $again['idempotent'] ) ) {
			$failures[] = 'The concurrency restore was not retry-idempotent.';
		}
	}
}

delete_option( 'erankly_writer_concurrency_fixture' );
delete_option( 'erankly_writer_concurrency_result_a' );
delete_option( 'erankly_writer_concurrency_result_b' );

if ( $failures ) {
	WP_CLI::error( implode( "\n", $failures ) );
}

WP_CLI::success( 'Two-process writer CAS, retry, preservation, and restore assertions passed.' );
