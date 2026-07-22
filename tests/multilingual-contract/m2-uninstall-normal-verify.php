<?php
// phpcs:ignoreFile -- Read-only verification after disposable normal uninstall.
global $wpdb;

$fixture = get_option( 'm2_uninstall_fixture', array() );
$table   = $wpdb->base_prefix . 'erankly_ml_relations';
$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

if ( false !== get_site_option( 'erankly_ml_sites', false )
	|| false !== get_network_option( (int) ( $fixture['network_id'] ?? 0 ), 'erankly_ml_sites', false )
	|| null !== $exists ) {
	WP_CLI::error( 'Normal core-owned Multisite uninstall did not remove multilingual storage.' );
}

WP_CLI::success( 'M2 normal core-owned Multisite uninstall cleanup passed.' );
