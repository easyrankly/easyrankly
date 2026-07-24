<?php
// phpcs:ignoreFile -- One of two independent processes writing from the same CAS snapshot.
/**
 * Public writer concurrent stale-snapshot worker.
 *
 * @package EasyRankly
 */

wp_set_current_user( 1 );

$worker  = sanitize_key( (string) getenv( 'ERANKLY_WRITER_WORKER' ) );
$fixture = (array) get_option( 'erankly_writer_concurrency_fixture', array() );
if ( ! in_array( $worker, array( 'a', 'b' ), true ) || empty( $fixture['expected_fingerprint'] ) ) {
	WP_CLI::error( 'The writer worker fixture is invalid.' );
}

if ( 'b' === $worker ) {
	global $wpdb;
	$ready = false;
	for ( $attempt = 0; $attempt < 150; ++$attempt ) {
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'erankly_writer_concurrency_result_a'
			)
		);
		if ( null !== $stored ) {
			$ready = true;
			break;
		}
		usleep( 100000 );
	}
	if ( ! $ready ) {
		WP_CLI::error( 'Writer A did not reach the stale-snapshot barrier.' );
	}
}

$result = erankly_update_localized_value_source(
	'organization_name',
	'a' === $worker ? 'Concurrent writer A' : 'Concurrent writer B',
	(string) $fixture['expected_fingerprint']
);
update_option(
	'erankly_writer_concurrency_result_' . $worker,
	array(
		'success'     => ! is_wp_error( $result ),
		'error'       => is_wp_error( $result ) ? $result->get_error_code() : '',
		'fingerprint' => is_array( $result ) ? (string) ( $result['fingerprint'] ?? '' ) : '',
	),
	false
);

WP_CLI::success( 'Public writer concurrency worker ' . strtoupper( $worker ) . ' completed.' );
