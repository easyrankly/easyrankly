<?php
/**
 * Plugin activation logic.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the custom redirects table.
 */
final class ERankly_Redirects_Activator {
	/**
	 * Activation callback.
	 */
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = ERankly_Redirects_Repository::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_path VARCHAR(512) NOT NULL,
			source_hash CHAR(32) NOT NULL,
			target_url TEXT NOT NULL,
			status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			is_regex TINYINT(1) NOT NULL DEFAULT 0,
			is_wildcard TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			visibility VARCHAR(20) NOT NULL DEFAULT 'all',
			required_role VARCHAR(60) NOT NULL DEFAULT '',
			note TEXT NULL,
			hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_hit_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY is_active (is_active),
			KEY is_regex (is_regex),
			KEY is_wildcard (is_wildcard)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
