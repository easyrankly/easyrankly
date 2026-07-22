<?php
// phpcs:ignoreFile -- Disposable multi-network uninstall fixture.
require __DIR__ . '/fixtures.php';

global $wpdb;
$second = erankly_ml_contract_create_second_network_fixture();
$marker = array(
	'contract'                => 1,
	'revision'                => 3,
	'current_owner'           => 'easyrankly-multilingual',
	'candidate_owner'         => '',
	'state'                   => 'claimed',
	'topology'                => 'network',
	'core_version'            => '2.1.0',
	'addon_version'           => '1.0.0',
	'lease_token'             => '',
	'lease_expires_at'        => 0,
	'legacy_enabled_snapshot' => true,
	'legacy_schema_version'   => '1.0.0',
	'rollback_possible'       => true,
	'fingerprint'             => 'sha256:' . hash( 'sha256', 'uninstall-retained' ),
	'prepared_at'             => time(),
	'claimed_at'              => time(),
	'journal'                 => array( 'phase' => 'claimed' ),
	'rollback_metadata'       => array(),
);
$retained = array_merge( $marker, array( 'revision' => 4, 'state' => 'retained' ) );
update_site_option( ERANKLY_ML_STORAGE_OWNER_OPTION, $marker );
update_site_option( 'erankly_ml_sites', array( get_current_blog_id() => array( 'hreflang' => 'it', 'enabled' => true, 'is_default' => true ) ) );
update_network_option( $second['network_id'], ERANKLY_ML_STORAGE_OWNER_OPTION, $retained );
update_network_option( $second['network_id'], 'erankly_ml_sites', array( $second['blog_id'] => array( 'hreflang' => 'fr', 'enabled' => true, 'is_default' => true ) ) );
update_option( 'm2_uninstall_fixture', $second );

$table = $wpdb->base_prefix . 'erankly_ml_relations';
$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, group_id BIGINT UNSIGNED NOT NULL, blog_id BIGINT UNSIGNED NOT NULL, object_type VARCHAR(20) NOT NULL, object_id BIGINT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))', $table ) );
$wpdb->insert( $table, array( 'group_id' => 9001, 'blog_id' => get_current_blog_id(), 'object_type' => 'post', 'object_id' => 9001, 'updated_at' => current_time( 'mysql' ) ) );

WP_CLI::success( 'M2 retained multi-network uninstall fixture prepared.' );
