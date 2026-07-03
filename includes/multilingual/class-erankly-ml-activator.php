<?php
/**
 * Multilingual module — database activation.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or upgrades the network-wide hreflang relations table.
 */
final class ERankly_ML_Activator {

	/**
	 * Creates the table using dbDelta so it is idempotent.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = ERankly_ML_Repository::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta is the WordPress-sanctioned DDL API.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT 'post',
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY blog_object (blog_id, object_type(20), object_id),
			KEY group_id (group_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
