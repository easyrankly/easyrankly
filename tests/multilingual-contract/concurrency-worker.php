<?php
// phpcs:ignoreFile -- One half of an intentional two-process database race.
/**
 * Concurrent new-group allocation worker.
 *
 * @package EasyRankly
 */

require __DIR__ . '/bootstrap.php';

$worker = sanitize_key( (string) getenv( 'ERANKLY_ML_CONTRACT_WORKER' ) );
if ( ! in_array( $worker, array( 'a', 'b' ), true ) ) {
	throw new RuntimeException( 'The concurrency worker must be a or b.' );
}

$other = 'a' === $worker ? 'b' : 'a';
$posts = get_site_option( 'erankly_ml_contract_concurrency_posts', array() );
if ( empty( $posts[ $worker ] ) ) {
	throw new RuntimeException( 'The concurrency fixture is missing.' );
}

update_site_option( 'erankly_ml_contract_ready_' . $worker, microtime( true ) );
$ready = false;
global $wpdb;
$other_option = 'erankly_ml_contract_ready_' . $other;
for ( $attempt = 0; $attempt < 150; ++$attempt ) {
	// Poll storage directly: get_site_option() memoizes the first miss inside
	// this PHP process and cannot observe the other worker's later write.
	$other_ready = $wpdb->get_var(
		$wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1", get_current_network_id(), $other_option )
	);
	if ( null !== $other_ready ) {
		$ready = true;
		break;
	}
	usleep( 100000 );
}

if ( ! $ready ) {
	throw new RuntimeException( 'The paired concurrency worker did not reach the barrier.' );
}

$group_id = erankly_ml_contract_driver()->link( 0, get_current_blog_id(), 'post', (int) $posts[ $worker ] );
update_site_option(
	'erankly_ml_contract_result_' . $worker,
	array( 'group_id' => $group_id, 'post_id' => (int) $posts[ $worker ], 'finished_at' => microtime( true ) )
);

WP_CLI::success( 'Concurrent group worker ' . strtoupper( $worker ) . ' completed.' );
