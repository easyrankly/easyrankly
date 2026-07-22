<?php
// phpcs:ignoreFile -- Read-only verification after disposable uninstall.
global $wpdb;

$fixture = get_option( 'm2_uninstall_fixture', array() );
$table   = $wpdb->base_prefix . 'erankly_ml_relations';
$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
$current = get_site_option( 'erankly_ml_storage_owner', false );
$second  = get_network_option( (int) ( $fixture['network_id'] ?? 0 ), 'erankly_ml_storage_owner', false );

if ( ! is_array( $current ) || 'claimed' !== ( $current['state'] ?? '' ) || ! is_array( $second ) || 'retained' !== ( $second['state'] ?? '' ) || $table !== $exists ) {
	WP_CLI::error( 'Core uninstall did not preserve claimed/retained multi-network multilingual storage.' );
}

WP_CLI::success( 'M2 retained multi-network uninstall preservation passed.' );
