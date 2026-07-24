<?php
// phpcs:ignoreFile -- Disposable fixture prepares a shared stale snapshot for two independent writer processes.
/**
 * Public writer concurrency preparation.
 *
 * @package EasyRankly
 */

wp_set_current_user( 1 );

$state = erankly_get_localized_value_source_state( 'organization_name' );
if ( is_wp_error( $state ) ) {
	WP_CLI::error( $state );
}

$unrelated = 'concurrency-preserved-' . wp_generate_password( 12, false );
$seeded    = erankly_update_plugin_settings( array( 'organization_phone' => $unrelated ) );
if ( true !== $seeded ) {
	WP_CLI::error( 'The writer concurrency fixture could not seed an unrelated setting.' );
}

update_option(
	'erankly_writer_concurrency_fixture',
	array(
		'original_value'       => (string) $state['value'],
		'expected_fingerprint' => (string) $state['fingerprint'],
		'unrelated'            => $unrelated,
	),
	false
);
delete_option( 'erankly_writer_concurrency_result_a' );
delete_option( 'erankly_writer_concurrency_result_b' );

WP_CLI::success( 'Public writer concurrency fixture prepared.' );
