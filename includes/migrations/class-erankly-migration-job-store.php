<?php
/**
 * Crash-safe staging storage for resumable third-party migrations.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores idempotent discovery events and pending writes. */
final class ERankly_Migration_Job_Store {
	private const SCHEMA_VERSION        = '1.0';
	private const SCHEMA_VERSION_OPTION = 'erankly_migration_queue_db_version';

	/**
	 * Returns the site-scoped queue table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'erankly_migration_queue';
	}

	/**
	 * Creates or upgrades the temporary migration queue table.
	 *
	 * @return bool True when storage is available.
	 */
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
			occurrence_key char(64) NOT NULL,
			identity_hash char(64) NOT NULL,
			item_kind varchar(16) NOT NULL,
			object_type varchar(8) NOT NULL DEFAULT '',
			source_reference varchar(255) NOT NULL DEFAULT '',
			target_field varchar(191) NOT NULL DEFAULT '',
			value_hash char(64) NOT NULL DEFAULT '',
			discovery_status varchar(24) NOT NULL,
			apply_status varchar(24) NOT NULL DEFAULT 'none',
			payload longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY occurrence_key (occurrence_key),
			KEY job_identity (job_id, identity_hash),
			KEY job_apply (job_id, apply_status, id)
		) {$charset_collate};"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name and charset clause.

		dbDelta( $sql );
		$available = erankly_table_exists( $table );
		if ( $available ) {
			update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
		}

		return $available;
	}

	/**
	 * Builds a globally unique retry key for one source occurrence.
	 *
	 * @param string $job_id           Migration UUID.
	 * @param string $kind             object|meta|redirect.
	 * @param string $source_reference Stable adapter reference.
	 * @param string $target_field     Target field or redirect identity.
	 * @return string
	 */
	public function occurrence_key( string $job_id, string $kind, string $source_reference, string $target_field ): string {
		return hash( 'sha256', $job_id . "\n" . $kind . "\n" . $source_reference . "\n" . $target_field );
	}

	/**
	 * Returns whether a source occurrence was already staged by an earlier try.
	 *
	 * @param string $occurrence_key Retry key.
	 * @return bool
	 * @throws RuntimeException When the occurrence lookup fails.
	 */
	public function occurrence_exists( string $occurrence_key ): bool {
		global $wpdb;

		$event_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue idempotency lookup.
			$wpdb->prepare( 'SELECT id FROM %i WHERE occurrence_key = %s LIMIT 1', self::table_name(), $occurrence_key )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Migration queue occurrence could not be read.' );
		}

		return null !== $event_id;
	}

	/**
	 * Stores one discovery occurrence exactly once.
	 *
	 * @param array<string,mixed> $event Queue row without generated columns.
	 * @return bool True only when a new row was inserted.
	 * @throws RuntimeException When a new queue event cannot be persisted.
	 */
	public function add_event( array $event ): bool {
		global $wpdb;

		$job_id           = sanitize_text_field( (string) ( $event['job_id'] ?? '' ) );
		$kind             = sanitize_key( (string) ( $event['item_kind'] ?? '' ) );
		$source_reference = sanitize_text_field( (string) ( $event['source_reference'] ?? '' ) );
		$target_field     = sanitize_key( (string) ( $event['target_field'] ?? '' ) );
		$identity         = (string) ( $event['identity'] ?? $source_reference );
		$payload          = isset( $event['payload'] ) ? wp_json_encode( $event['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';

		if ( '' === $job_id || ! in_array( $kind, array( 'object', 'meta', 'redirect' ), true ) || false === $payload ) {
			throw new RuntimeException( 'Invalid migration queue event.' );
		}

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Durable queue write.
			self::table_name(),
			array(
				'job_id'           => $job_id,
				'occurrence_key'   => $this->occurrence_key( $job_id, $kind, $source_reference, $target_field ),
				'identity_hash'    => hash( 'sha256', $identity ),
				'item_kind'        => $kind,
				'object_type'      => sanitize_key( (string) ( $event['object_type'] ?? '' ) ),
				'source_reference' => $source_reference,
				'target_field'     => $target_field,
				'value_hash'       => sanitize_text_field( (string) ( $event['value_hash'] ?? '' ) ),
				'discovery_status' => sanitize_key( (string) ( $event['discovery_status'] ?? '' ) ),
				'apply_status'     => sanitize_key( (string) ( $event['apply_status'] ?? 'none' ) ),
				'payload'          => (string) $payload,
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false !== $result ) {
			return true;
		}

		$occurrence_key = $this->occurrence_key( $job_id, $kind, $source_reference, $target_field );
		if ( $this->occurrence_exists( $occurrence_key ) ) {
			return false;
		}

		throw new RuntimeException( 'Migration queue event could not be persisted.' );
	}

	/**
	 * Returns the first proposal for a target identity in selected states.
	 *
	 * @param string            $job_id   Migration UUID.
	 * @param string            $kind     meta|redirect.
	 * @param string            $identity Target identity before hashing.
	 * @param array<int,string> $statuses Allowed discovery states.
	 * @return array<string,mixed>|null
	 * @throws RuntimeException When the identity lookup fails.
	 */
	public function first_identity( string $job_id, string $kind, string $identity, array $statuses ): ?array {
		global $wpdb;

		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		if ( ! $statuses ) {
			return null;
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = 'SELECT * FROM %i WHERE job_id = %s AND item_kind = %s AND identity_hash = %s AND discovery_status IN (' . $placeholders . ') ORDER BY id ASC LIMIT 1';
		$params       = array_merge( array( self::table_name(), $job_id, $kind, hash( 'sha256', $identity ) ), $statuses );
		$row          = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue identity lookup.
			$wpdb->prepare( $sql, $params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic status list is paired with placeholders.
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Migration queue identity could not be read.' );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the next bounded set of writes.
	 *
	 * @param string $job_id Migration UUID.
	 * @param int    $limit  Maximum writes.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When pending writes cannot be read.
	 */
	public function pending( string $job_id, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded queue read.
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s AND apply_status = %s ORDER BY id ASC LIMIT %d', self::table_name(), $job_id, 'pending', max( 1, min( 500, $limit ) ) ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Pending migration writes could not be read.' );
		}

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Records the durable result of one attempted write.
	 *
	 * @param int    $id     Queue row ID.
	 * @param string $status Apply outcome.
	 * @return bool
	 */
	public function update_apply_status( int $id, string $status ): bool {
		global $wpdb;

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable queue checkpoint; cache would undermine recovery.
			self::table_name(),
			array( 'apply_status' => sanitize_key( $status ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns a bounded evidence page in durable event order.
	 *
	 * @param string $job_id  Migration UUID.
	 * @param int    $after_id Last consumed queue ID.
	 * @param int    $limit    Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function evidence_page( string $job_id, int $after_id, int $limit = 500 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded terminal evidence read before queue cleanup.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %s AND id > %d ORDER BY id ASC LIMIT %d',
				self::table_name(),
				$job_id,
				max( 0, $after_id ),
				max( 1, min( 1000, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Rebuilds report counters from durable events instead of mutable PHP state.
	 *
	 * @param string                    $job_id  Migration UUID.
	 * @param ERankly_Migration_Manager $manager Manager providing the counter map.
	 * @return array<string,int>
	 * @throws RuntimeException When progress counters cannot be rebuilt.
	 */
	public function counts( string $job_id, ERankly_Migration_Manager $manager ): array {
		global $wpdb;

		$counts = $manager->empty_counts();
		$groups = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate progress read.
			$wpdb->prepare(
				'SELECT item_kind, object_type, discovery_status, apply_status, COUNT(*) AS total FROM %i WHERE job_id = %s GROUP BY item_kind, object_type, discovery_status, apply_status',
				self::table_name(),
				$job_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Migration progress counters could not be rebuilt.' );
		}

		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$total     = absint( $group['total'] ?? 0 );
			$kind      = (string) ( $group['item_kind'] ?? '' );
			$type      = (string) ( $group['object_type'] ?? '' );
			$discovery = (string) ( $group['discovery_status'] ?? '' );
			$apply     = (string) ( $group['apply_status'] ?? '' );

			if ( 'object' === $kind ) {
				if ( 'invalid' === $discovery ) {
					$counts['objects_invalid'] += $total;
				} else {
					$counts['objects_found'] += $total;
					if ( isset( $counts[ $type . 's_found' ] ) ) {
						$counts[ $type . 's_found' ] += $total;
					}
				}
				continue;
			}

			if ( 'meta' === $kind ) {
				$counts['fields_found'] += $total;
				if ( 'ready' === $discovery ) {
					$counts['fields_ready'] += $total;
					if ( isset( $counts[ $type . '_fields_ready' ] ) ) {
						$counts[ $type . '_fields_ready' ] += $total;
					}
				} elseif ( 'existing' === $discovery ) {
					$counts['fields_skipped_existing'] += $total;
				} elseif ( 'identical' === $discovery ) {
					$counts['fields_identical'] += $total;
				} elseif ( 'duplicate' === $discovery ) {
					$counts['fields_duplicate'] += $total;
				} elseif ( 'conflict' === $discovery ) {
					$counts['fields_conflicts'] += $total;
				} elseif ( 'invalid' === $discovery ) {
					$counts['fields_invalid'] += $total;
				}

				if ( 'written' === $apply ) {
					$counts['fields_written'] += $total;
					if ( isset( $counts[ $type . '_fields_written' ] ) ) {
						$counts[ $type . '_fields_written' ] += $total;
					}
				} elseif ( 'failed' === $apply ) {
					$counts['fields_failed'] += $total;
				} elseif ( 'preserved' === $apply ) {
					$counts['fields_skipped_existing'] += $total;
				}
				continue;
			}

			if ( 'redirect' === $kind ) {
				$counts['redirects_found'] += $total;
				if ( 'ready_create' === $discovery ) {
					$counts['redirects_ready_create'] += $total;
				} elseif ( 'ready_update' === $discovery ) {
					$counts['redirects_ready_update'] += $total;
				} elseif ( 'unchanged' === $discovery ) {
					$counts['redirects_unchanged'] += $total;
				} elseif ( 'duplicate' === $discovery ) {
					$counts['redirects_duplicate'] += $total;
				} elseif ( 'conflict' === $discovery ) {
					$counts['redirects_conflicts'] += $total;
				} elseif ( 'invalid' === $discovery ) {
					$counts['redirects_invalid'] += $total;
				} elseif ( 'failed' === $discovery ) {
					$counts['redirects_failed'] += $total;
				}

				if ( 'created' === $apply ) {
					$counts['redirects_created'] += $total;
				} elseif ( 'updated' === $apply ) {
					$counts['redirects_updated'] += $total;
				} elseif ( 'failed' === $apply ) {
					$counts['redirects_failed'] += $total;
				} elseif ( 'conflict' === $apply ) {
					$counts['redirects_conflicts'] += $total;
				}
			}
		}

		return $counts;
	}

	/**
	 * Deletes all staging rows belonging to a finished/cancelled job.
	 *
	 * @param string $job_id Migration UUID.
	 * @return bool
	 */
	public function delete_job( string $job_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( self::table_name(), array( 'job_id' => $job_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit queue cleanup.
	}
}
