<?php
/** Plugin activation logic. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ERankly_Redirects_Activator {
	/** Activation callback. */
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = ERankly_Redirects_Repository::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_path VARCHAR(512) NOT NULL,
			source_hash CHAR(32) NOT NULL,
			rule_hash CHAR(32) NULL DEFAULT NULL,
			source_query VARCHAR(512) NOT NULL DEFAULT '',
			target_url TEXT NOT NULL,
			status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			match_type VARCHAR(20) NOT NULL DEFAULT 'exact',
			is_regex TINYINT(1) NOT NULL DEFAULT 0,
			is_wildcard TINYINT(1) NOT NULL DEFAULT 0,
			case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
			trailing_slash VARCHAR(20) NOT NULL DEFAULT 'ignore',
			query_mode VARCHAR(20) NOT NULL DEFAULT 'ignore',
			priority INT NOT NULL DEFAULT 10,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			visibility VARCHAR(20) NOT NULL DEFAULT 'all',
			required_role VARCHAR(60) NOT NULL DEFAULT '',
			conditions LONGTEXT NULL,
			start_at DATETIME NULL,
			end_at DATETIME NULL,
			source_plugin VARCHAR(40) NOT NULL DEFAULT '',
			source_reference VARCHAR(191) NOT NULL DEFAULT '',
			migration_id VARCHAR(64) NOT NULL DEFAULT '',
			note TEXT NULL,
			hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_hit_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY source_hash (source_hash),
			UNIQUE KEY rule_hash (rule_hash),
			KEY is_active (is_active),
			KEY runtime_priority (is_active, priority),
			KEY migration_id (migration_id),
			KEY is_regex (is_regex),
			KEY is_wildcard (is_wildcard)
		) {$charset_collate};";

		dbDelta( $sql );

		// dbDelta does not reliably downgrade an existing UNIQUE index to a
		// normal index. Rule identity now includes match semantics, so multiple
		// rules may legitimately share the same normalized path hash.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration inspection.
		foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
			if ( 'source_hash' === (string) ( $index['Key_name'] ?? '' ) && 0 === (int) ( $index['Non_unique'] ?? 1 ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX source_hash, ADD KEY source_hash (source_hash)', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required one-time schema migration.
				break;
			}
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE rule_hash IS NULL OR rule_hash = %s', $table_name, '' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bounded-by-rule-count migration.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$match_type = ! empty( $row['is_wildcard'] ) ? 'wildcard' : ( ! empty( $row['is_regex'] ) ? 'regex' : 'exact' );
			$rule_hash  = ERankly_Redirects_Normalizer::rule_hash(
				array_merge(
					$row,
					array( 'match_type' => $match_type )
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema backfill.
			$wpdb->update(
				$table_name,
				array(
					'match_type' => $match_type,
					'rule_hash'  => $rule_hash,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}
}
