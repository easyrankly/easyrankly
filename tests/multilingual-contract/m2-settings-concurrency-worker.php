<?php
// phpcs:ignoreFile -- One half of an intentional two-process settings race.
/**
 * Concurrent shared-settings mutex worker for the M2 bridge.
 *
 * @package EasyRankly
 */

require __DIR__ . '/bootstrap.php';

$worker = sanitize_key( (string) getenv( 'ERANKLY_ML_CONTRACT_WORKER' ) );
if ( ! in_array( $worker, array( 'a', 'b' ), true ) ) {
	throw new RuntimeException( 'The M2 settings concurrency worker must be a or b.' );
}

global $wpdb;
$network_id   = get_current_network_id();
$gate_option  = 'erankly_ml_contract_m2_settings_gate';
$result_a_key = 'erankly_ml_contract_m2_settings_result_a';
$result_b_key = 'erankly_ml_contract_m2_settings_result_b';

$read_network_option_directly = static function ( string $option_name ) use ( $wpdb, $network_id ): mixed {
	$value = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1",
			$network_id,
			$option_name
		)
	);

	return null === $value ? null : maybe_unserialize( $value );
};

if ( 'a' === $worker ) {
	add_filter(
		'pre_update_site_option_erankly_settings',
		static function ( mixed $value ) use ( $read_network_option_directly, $gate_option, $result_b_key ): mixed {
			update_site_option( $gate_option, 'a-holds-lock' );

			for ( $attempt = 0; $attempt < 150; ++$attempt ) {
				if ( null !== $read_network_option_directly( $result_b_key ) ) {
					return $value;
				}
				usleep( 100000 );
			}

			throw new RuntimeException( 'The competing M2 settings writer did not attempt its update.' );
		},
		20,
		1
	);

	$result = erankly_update_plugin_settings( array( 'm2_concurrent_a' => 'writer-a' ) );
	update_site_option(
		$result_a_key,
		array(
			'success' => true === $result,
			'error'   => is_wp_error( $result ) ? $result->get_error_code() : '',
		)
	);

	WP_CLI::success( 'M2 settings concurrency worker A completed.' );
	return;
}

$gate_open = false;
for ( $attempt = 0; $attempt < 150; ++$attempt ) {
	if ( 'a-holds-lock' === $read_network_option_directly( $gate_option ) ) {
		$gate_open = true;
		break;
	}
	usleep( 100000 );
}

if ( ! $gate_open ) {
	throw new RuntimeException( 'The M2 settings lock holder did not reach the race barrier.' );
}

$result = erankly_update_plugin_settings( array( 'm2_concurrent_b' => 'writer-b' ) );
update_site_option(
	$result_b_key,
	array(
		'success' => true === $result,
		'error'   => is_wp_error( $result ) ? $result->get_error_code() : '',
	)
);

WP_CLI::success( 'M2 settings concurrency worker B completed.' );
