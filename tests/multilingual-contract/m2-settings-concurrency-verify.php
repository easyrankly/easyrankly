<?php
// phpcs:ignoreFile -- Verifies an intentional two-process settings race.
/**
 * Concurrent shared-settings mutex verifier for the M2 bridge.
 *
 * @package EasyRankly
 */

require __DIR__ . '/bootstrap.php';

$result   = new ERankly_ML_Contract_Result( 'm2-settings-concurrency' );
$worker_a = get_site_option( 'erankly_ml_contract_m2_settings_result_a', array() );
$worker_b = get_site_option( 'erankly_ml_contract_m2_settings_result_b', array() );
$stored   = erankly_get_stored_settings();

$result->check( ! empty( $worker_a['success'] ), 'M2-OWN-013', 'The writer holding the shared settings mutex must complete.' );
$result->same( 'erankly_ml_ownership_locked', (string) ( $worker_b['error'] ?? '' ), 'M2-OWN-014', 'A simultaneous whole-settings writer must receive the retryable mutex error.' );
$result->same( 'writer-a', (string) ( $stored['m2_concurrent_a'] ?? '' ), 'M2-OWN-015', 'The successful concurrent write must persist.' );
$result->check( ! isset( $stored['m2_concurrent_b'] ), 'M2-OWN-016', 'A rejected concurrent writer must not partially update the settings option.' );

$retried = erankly_update_plugin_settings( array( 'm2_concurrent_b' => 'writer-b' ) );
$stored  = erankly_get_stored_settings();
$result->check( true === $retried, 'M2-OWN-017', 'The rejected writer must succeed after retrying with a fresh mutex.' );
$result->check(
	'writer-a' === ( $stored['m2_concurrent_a'] ?? '' ) && 'writer-b' === ( $stored['m2_concurrent_b'] ?? '' ),
	'M2-OWN-018',
	'The retry must merge against current settings without losing the first writer.'
);

$result->finish();
