<?php
/** Persistent value-free exception ledger for migration reports. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores complete exception rows separately from bounded report summaries. */
final class ERankly_Migration_Evidence_Store {
	private const SCHEMA_VERSION        = '1.0';
	private const SCHEMA_VERSION_OPTION = 'erankly_migration_evidence_db_version';

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'erankly_migration_exceptions';
	}

	public function ensure_schema(): bool {
		global $wpdb;

		$table = self::table_name();
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) && erankly_table_exists( $table ) ) {
			return true;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id char(36) NOT NULL,
			queue_id bigint(20) unsigned NOT NULL,
			area varchar(16) NOT NULL,
			outcome varchar(24) NOT NULL,
			reference varchar(255) NOT NULL DEFAULT '',
			target varchar(191) NOT NULL DEFAULT '',
			object_type varchar(8) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			edit_url text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_queue (job_id, queue_id),
			KEY job_page (job_id, id)
		) {$charset_collate};"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name and charset clause.

		dbDelta( $sql );
		$available = erankly_table_exists( $table );
		if ( $available ) {
			update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
		}

		return $available;
	}

	/** @throws RuntimeException When durable evidence cannot be stored. */
	public function add( string $job_id, int $queue_id, array $exception ): void {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Durable migration evidence write.
			self::table_name(),
			array(
				'job_id'      => sanitize_text_field( $job_id ),
				'queue_id'    => $queue_id,
				'area'        => sanitize_key( (string) ( $exception['area'] ?? '' ) ),
				'outcome'     => sanitize_key( (string) ( $exception['outcome'] ?? '' ) ),
				'reference'   => sanitize_text_field( (string) ( $exception['reference'] ?? '' ) ),
				'target'      => sanitize_key( (string) ( $exception['target'] ?? '' ) ),
				'object_type' => sanitize_key( (string) ( $exception['object_type'] ?? '' ) ),
				'object_id'   => absint( $exception['object_id'] ?? 0 ),
				'edit_url'    => esc_url_raw( (string) ( $exception['edit_url'] ?? '' ) ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( false !== $result ) {
			return;
		}

		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent evidence lookup.
			$wpdb->prepare( 'SELECT id FROM %i WHERE job_id = %s AND queue_id = %d LIMIT 1', self::table_name(), $job_id, $queue_id )
		);
		if ( null === $exists ) {
			throw new RuntimeException( 'Migration exception evidence could not be persisted.' );
		}
	}

	public function count( string $job_id ): int {
		global $wpdb;

		if ( ! erankly_table_exists( self::table_name() ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed evidence count.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id = %s', self::table_name(), $job_id )
		);
	}

	/** @param int    $after_id Last consumed ledger ID. */
	public function page( string $job_id, int $after_id, int $limit = 500 ): array {
		global $wpdb;

		if ( ! erankly_table_exists( self::table_name() ) ) {
			return array();
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded evidence download page.
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s AND id > %d ORDER BY id ASC LIMIT %d', self::table_name(), $job_id, max( 0, $after_id ), max( 1, min( 1000, $limit ) ) ),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/** Deletes the exception ledger when its bounded parent report is evicted. */
	public function delete_job( string $job_id ): void {
		global $wpdb;

		if ( erankly_table_exists( self::table_name() ) ) {
			$wpdb->delete( self::table_name(), array( 'job_id' => $job_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Parent report lifecycle cleanup.
		}
	}
}
