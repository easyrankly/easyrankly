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
			case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
			trailing_slash VARCHAR(20) NOT NULL DEFAULT 'ignore',
			query_mode VARCHAR(20) NOT NULL DEFAULT 'ignore',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
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
			KEY migration_id (migration_id),
			KEY match_type (match_type)
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

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema compatibility inspection.
		$legacy  = in_array( 'conditions', is_array( $columns ) ? $columns : array(), true );
		$needs_canonical_backfill = $legacy && '3.0.0' !== (string) get_option( 'erankly_redirects_db_version', '' );
		if ( $needs_canonical_backfill ) {
			// Free the unique identity namespace before the canonical backfill. A
			// converted legacy rule may otherwise collide with a later row before
			// that later row can be disabled safely.
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET rule_hash = MD5(CONCAT('erankly-v3-staging|', id, '|', COALESCE(rule_hash, '')))", $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Collision-safe staging identity for the schema migration.
		}
		$rows    = $needs_canonical_backfill ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table_name ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bounded-by-rule-count migration.
		$report  = array( 'transformed' => array(), 'disabled' => array() );
		$seen_hashes = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id         = (int) $row['id'];
			$match_type = (string) ( $row['match_type'] ?? '' );
			if ( ! in_array( $match_type, array( 'exact', 'wildcard', 'regex', 'contains', 'starts_with', 'ends_with' ), true ) ) {
				$match_type = ! empty( $row['is_wildcard'] ) ? 'wildcard' : ( ! empty( $row['is_regex'] ) ? 'regex' : 'exact' );
			}

			$conditions = $row['conditions'] ?? array();
			if ( is_string( $conditions ) ) {
				$decoded    = json_decode( $conditions, true );
				$conditions = is_array( $decoded ) ? $decoded : trim( $conditions );
			}
			$unsupported = $legacy && (
				'all' !== (string) ( $row['visibility'] ?? 'all' )
				|| '' !== (string) ( $row['required_role'] ?? '' )
				|| ! empty( $conditions )
				|| ! empty( $row['start_at'] )
				|| ! empty( $row['end_at'] )
			);
			if ( $unsupported ) {
				$report['disabled'][] = $id;
				$note = trim( (string) ( $row['note'] ?? '' ) . ' ' . __( '[Disabled during the redirect upgrade because the rule used audience, request, or schedule conditions.]', 'easyrankly' ) );
				$wpdb->update( $table_name, array( 'is_active' => 0, 'note' => $note ), array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fail-closed legacy migration.
			}

			if ( in_array( $match_type, array( 'contains', 'starts_with', 'ends_with' ), true ) ) {
				$literal = preg_quote( (string) $row['source_path'], '#' );
				if ( 'starts_with' === $match_type ) {
					$literal = '^' . $literal;
				} elseif ( 'ends_with' === $match_type ) {
					$literal .= '$';
				}
				$row['source_path'] = $literal;
				$match_type         = 'regex';
				$report['transformed'][] = $id;
			}

			$row['match_type'] = $match_type;
			$rule_hash  = ERankly_Redirects_Normalizer::rule_hash(
				$row
			);
			// Conditional legacy rows may share their canonical identity. Keep a
			// unique archival hash while they remain disabled for manual review.
			if ( $unsupported || isset( $seen_hashes[ $rule_hash ] ) ) {
				if ( ! $unsupported ) {
					$report['disabled'][] = $id;
					$note = trim( (string) ( $row['note'] ?? '' ) . ' ' . __( '[Disabled during the redirect upgrade because another rule now has the same canonical match.]', 'easyrankly' ) );
					$wpdb->update( $table_name, array( 'is_active' => 0, 'note' => $note ), array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical collisions are disabled for manual review.
				}
				$rule_hash = md5( 'legacy-disabled|' . $id . '|' . $rule_hash );
			} else {
				$seen_hashes[ $rule_hash ] = true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema backfill.
			$wpdb->update(
				$table_name,
				array(
					'source_path' => (string) $row['source_path'],
					'source_hash' => ERankly_Redirects_Normalizer::source_hash( (string) $row['source_path'] ),
					'match_type'  => $match_type,
					'rule_hash'   => $rule_hash,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		if ( ! empty( $report['transformed'] ) || ! empty( $report['disabled'] ) ) {
			update_option( 'erankly_redirects_v3_migration_report', $report, false );
		}
	}
}
