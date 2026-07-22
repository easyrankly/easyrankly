<?php
// phpcs:ignoreFile -- Verifies the two-process database race in an ephemeral network.
/**
 * Concurrent new-group allocation verifier.
 *
 * @package EasyRankly
 */

require __DIR__ . '/bootstrap.php';

$result = new ERankly_ML_Contract_Result( 'legacy-baseline-concurrency' );
$driver = erankly_ml_contract_driver();
$a      = get_site_option( 'erankly_ml_contract_result_a', array() );
$b      = get_site_option( 'erankly_ml_contract_result_b', array() );

$result->check( (int) ( $a['group_id'] ?? 0 ) > 0 && (int) ( $b['group_id'] ?? 0 ) > 0, 'ML-BASE-010', 'Both concurrent group allocations must complete.' );
$result->check( (int) $a['group_id'] !== (int) $b['group_id'], 'ML-BASE-010', 'Concurrent new groups must receive distinct IDs.' );

foreach ( array( 'a' => $a, 'b' => $b ) as $worker => $row ) {
	$members = $driver->group_for( get_current_blog_id(), 'post', (int) ( $row['post_id'] ?? 0 ) );
	$result->check(
		1 === count( $members )
			&& (int) $members[0]['group_id'] === (int) $row['group_id']
			&& (int) $members[0]['object_id'] === (int) $row['post_id'],
		'ML-BASE-010',
		'Worker ' . strtoupper( $worker ) . ' must own exactly its allocated group row.'
	);
}

$result->finish();
