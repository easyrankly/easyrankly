<?php
// phpcs:ignoreFile -- Disposable normal Multisite uninstall fixture.
$fixture = get_option( 'm2_uninstall_fixture', array() );
delete_site_option( 'erankly_ml_storage_owner' );
delete_network_option( (int) ( $fixture['network_id'] ?? 0 ), 'erankly_ml_storage_owner' );
update_site_option( 'erankly_ml_sites', array( get_current_blog_id() => array( 'hreflang' => 'it', 'enabled' => true, 'is_default' => true ) ) );
update_network_option( (int) ( $fixture['network_id'] ?? 0 ), 'erankly_ml_sites', array( (int) ( $fixture['blog_id'] ?? 0 ) => array( 'hreflang' => 'fr', 'enabled' => true, 'is_default' => true ) ) );
WP_CLI::success( 'M2 normal Multisite uninstall fixture prepared.' );
