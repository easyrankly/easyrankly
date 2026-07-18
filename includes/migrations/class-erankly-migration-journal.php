<?php
/**
 * Conditional rollback journal for third-party SEO migrations.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Records every migration-owned write without retaining temporary source files. */
final class ERankly_Migration_Journal {
	private const SCHEMA_VERSION        = '1.0';
	private const SCHEMA_VERSION_OPTION = 'erankly_migration_journal_db_version';

	/** Returns the site-scoped journal table name. */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'erankly_migration_changes';
	}

	/** Creates or upgrades the persistent journal table. */
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
			event_key char(64) NOT NULL,
			job_id char(36) NOT NULL,
			item_kind varchar(16) NOT NULL,
			action varchar(24) NOT NULL,
			object_type varchar(8) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_field varchar(191) NOT NULL DEFAULT '',
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			previous_value longtext NULL,
			written_value longtext NOT NULL,
			written_hash char(64) NOT NULL,
			state varchar(24) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			rolled_back_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY job_state (job_id, state, id),
			KEY expires_at (expires_at)
		) {$charset_collate};"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name and charset clause.

		dbDelta( $sql );
		$available = erankly_table_exists( $table );
		if ( $available ) {
			update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
		}

		return $available;
	}

	/**
	 * Creates an idempotent pending journal entry before a metadata write.
	 *
	 * @param string              $job_id Migration UUID.
	 * @param int                 $queue_id Queue row ID.
	 * @param array<string,mixed> $payload Validated queue payload.
	 * @return string Event key, or an empty string on failure.
	 */
	public function prepare_meta( string $job_id, int $queue_id, array $payload ): string {
		$object_type = sanitize_key( (string) ( $payload['object_type'] ?? '' ) );
		$object_id   = absint( $payload['object_id'] ?? 0 );
		$key         = sanitize_key( (string) ( $payload['key'] ?? '' ) );
		if ( ! in_array( $object_type, array( 'post', 'term', 'user' ), true ) || $object_id < 1 || '' === $key ) {
			return '';
		}

		$exists   = metadata_exists( $object_type, $object_id, $key );
		$previous = array(
			'exists' => $exists,
			'value'  => $exists ? get_metadata( $object_type, $object_id, $key, true ) : null,
		);

		return $this->prepare(
			$job_id,
			$queue_id,
			'meta',
			$exists ? 'meta_update' : 'meta_create',
			$object_type,
			$object_id,
			$key,
			0,
			$previous,
			$payload['value'] ?? null
		);
	}

	/**
	 * Creates an idempotent pending journal entry before a redirect write.
	 *
	 * @param string                   $job_id Migration UUID.
	 * @param int                      $queue_id Queue row ID.
	 * @param array<string,mixed>      $redirect Intended redirect.
	 * @param array<string,mixed>|null $existing Existing redirect for an update.
	 * @return string Event key, or an empty string on failure.
	 */
	public function prepare_redirect( string $job_id, int $queue_id, array $redirect, ?array $existing ): string {
		return $this->prepare(
			$job_id,
			$queue_id,
			'redirect',
			$existing ? 'redirect_update' : 'redirect_create',
			'',
			0,
			'rule',
			absint( $existing['id'] ?? 0 ),
			array(
				'exists' => (bool) $existing,
				'value'  => $existing,
			),
			$redirect
		);
	}

	/**
	 * Marks a prepared event as applied and records a generated target ID.
	 *
	 * @param string $event_key Stable journal event key.
	 * @param int    $target_id Generated redirect ID, when applicable.
	 * @return bool Whether the durable checkpoint was saved.
	 */
	public function mark_applied( string $event_key, int $target_id = 0 ): bool {
		global $wpdb;

		if ( '' === $event_key ) {
			return false;
		}

		$data = array( 'state' => 'applied' );
		if ( $target_id > 0 ) {
			$data['target_id'] = $target_id;
		}

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable rollback checkpoint.
			self::table_name(),
			$data,
			array( 'event_key' => $event_key )
		);
	}

	/**
	 * Returns rollback availability and retention data for a report.
	 *
	 * @param string $job_id Migration UUID.
	 * @return array<string,mixed>
	 */
	public function summary( string $job_id ): array {
		global $wpdb;
		if ( ! erankly_table_exists( self::table_name() ) ) {
			return array(
				'total'       => 0,
				'available'   => 0,
				'rolled_back' => 0,
				'preserved'   => 0,
				'failed'      => 0,
				'expires_at'  => '',
				'expired'     => false,
			);
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small indexed journal summary.
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(state IN (%s, %s)) AS available, SUM(state = %s) AS rolled_back, SUM(state = %s) AS preserved, SUM(state = %s) AS failed, MAX(expires_at) AS expires_at FROM %i WHERE job_id = %s',
				'applied',
				'pending',
				'rolled_back',
				'preserved',
				'failed',
				self::table_name(),
				$job_id
			),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		return array(
			'total'       => absint( $row['total'] ?? 0 ),
			'available'   => absint( $row['available'] ?? 0 ),
			'rolled_back' => absint( $row['rolled_back'] ?? 0 ),
			'preserved'   => absint( $row['preserved'] ?? 0 ),
			'failed'      => absint( $row['failed'] ?? 0 ),
			'expires_at'  => sanitize_text_field( (string) ( $row['expires_at'] ?? '' ) ),
			'expired'     => ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) < time(),
		);
	}

	/**
	 * Rolls back only values that still equal the value written by this migration.
	 *
	 * @param string $job_id Migration UUID.
	 * @return array<string,int|string>
	 */
	public function rollback( string $job_id ): array {
		global $wpdb;

		$result  = array(
			'status'      => 'complete',
			'rolled_back' => 0,
			'preserved'   => 0,
			'failed'      => 0,
		);
		$summary = $this->summary( $job_id );
		if ( ! empty( $summary['expired'] ) ) {
			$result['status'] = 'expired';
			return $result;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed, user-requested rollback read.
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s AND state IN (%s, %s) ORDER BY id DESC', self::table_name(), $job_id, 'applied', 'pending' ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$result['status'] = 'failed';
			++$result['failed'];
			return $result;
		}

		erankly_ensure_redirect_classes_available();
		$repository = class_exists( 'ERankly_Redirects_Repository' ) && erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ? new ERankly_Redirects_Repository() : null;
		if ( $repository ) {
			$repository->begin_bulk();
		}

		try {
			foreach ( $rows as $row ) {
				$outcome = 'redirect' === (string) ( $row['item_kind'] ?? '' )
					? $this->rollback_redirect( $row, $repository )
					: $this->rollback_meta( $row );
				++$result[ $outcome ];
				$this->set_rollback_state( absint( $row['id'] ?? 0 ), $outcome );
			}
		} finally {
			if ( $repository ) {
				$repository->end_bulk();
			}
		}

		if ( $result['failed'] > 0 ) {
			$result['status'] = $result['rolled_back'] > 0 ? 'partial' : 'failed';
		} elseif ( $result['preserved'] > 0 ) {
			$result['status'] = 'partial';
		}

		return $result;
	}

	/** Deletes expired journal payloads after their safety window. */
	public function prune_expired(): void {
		global $wpdb;

		if ( ! erankly_table_exists( self::table_name() ) ) {
			return;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded lifecycle cleanup by indexed expiry.
			$wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', self::table_name(), gmdate( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Persists a generic pre-write journal row.
	 *
	 * @param string $job_id     Migration UUID.
	 * @param int    $queue_id   Queue row ID.
	 * @param string $kind       meta|redirect.
	 * @param string $action     Reversible action name.
	 * @param string $object_type post|term|user, or empty for redirects.
	 * @param int    $object_id  Metadata object ID.
	 * @param string $field      Target field.
	 * @param int    $target_id  Existing redirect ID.
	 * @param mixed  $previous   Pre-write value envelope.
	 * @param mixed  $written    Intended migration value.
	 * @return string Event key, or empty string.
	 */
	private function prepare( string $job_id, int $queue_id, string $kind, string $action, string $object_type, int $object_id, string $field, int $target_id, mixed $previous, mixed $written ): string {
		global $wpdb;

		if ( ! $this->ensure_schema() ) {
			return '';
		}

		$event_key        = hash( 'sha256', $job_id . "\n" . $queue_id );
		$encoded_previous = wp_json_encode( $previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$encoded_written  = wp_json_encode( $written, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded_previous || false === $encoded_written ) {
			return '';
		}

		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent journal lookup.
			$wpdb->prepare( 'SELECT event_key FROM %i WHERE event_key = %s LIMIT 1', self::table_name(), $event_key )
		);
		if ( null !== $existing ) {
			return $event_key;
		}

		$ttl     = max( DAY_IN_SECONDS, (int) apply_filters( 'erankly_migration_rollback_ttl', 7 * DAY_IN_SECONDS ) );
		$created = gmdate( 'Y-m-d H:i:s' );
		$result  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Durable pre-write journal.
			self::table_name(),
			array(
				'event_key'      => $event_key,
				'job_id'         => sanitize_text_field( $job_id ),
				'item_kind'      => sanitize_key( $kind ),
				'action'         => sanitize_key( $action ),
				'object_type'    => sanitize_key( $object_type ),
				'object_id'      => $object_id,
				'target_field'   => sanitize_key( $field ),
				'target_id'      => $target_id,
				'previous_value' => $encoded_previous,
				'written_value'  => $encoded_written,
				'written_hash'   => hash( 'sha256', $encoded_written ),
				'state'          => 'pending',
				'created_at'     => $created,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			)
		);

		return false === $result ? '' : $event_key;
	}

	/**
	 * Conditionally restores one metadata value.
	 *
	 * @param array<string,mixed> $row Journal row.
	 * @return string rolled_back|preserved|failed.
	 */
	private function rollback_meta( array $row ): string {
		$type    = sanitize_key( (string) ( $row['object_type'] ?? '' ) );
		$id      = absint( $row['object_id'] ?? 0 );
		$key     = sanitize_key( (string) ( $row['target_field'] ?? '' ) );
		$written = json_decode( (string) ( $row['written_value'] ?? '' ), true );
		$before  = json_decode( (string) ( $row['previous_value'] ?? '' ), true );
		if ( ! in_array( $type, array( 'post', 'term', 'user' ), true ) || $id < 1 || '' === $key || ! is_array( $before ) ) {
			return 'failed';
		}
		if ( ! metadata_exists( $type, $id, $key ) ) {
			return 'preserved';
		}
		$current = get_metadata( $type, $id, $key, true );
		if ( $this->canonical_json( $current ) !== $this->canonical_json( $written ) ) {
			return 'preserved';
		}

		if ( ! empty( $before['exists'] ) ) {
			return false === update_metadata( $type, $id, $key, $before['value'] ?? null ) ? 'failed' : 'rolled_back';
		}

		return delete_metadata( $type, $id, $key ) ? 'rolled_back' : 'failed';
	}

	/**
	 * Conditionally restores one redirect.
	 *
	 * @param array<string,mixed>               $row Journal row.
	 * @param ERankly_Redirects_Repository|null $repository Redirect repository.
	 * @return string rolled_back|preserved|failed.
	 */
	private function rollback_redirect( array $row, ?ERankly_Redirects_Repository $repository ): string {
		if ( ! $repository ) {
			return 'failed';
		}
		$id       = absint( $row['target_id'] ?? 0 );
		$written  = json_decode( (string) ( $row['written_value'] ?? '' ), true );
		$previous = json_decode( (string) ( $row['previous_value'] ?? '' ), true );
		if ( ! is_array( $written ) || ! is_array( $previous ) ) {
			return 'failed';
		}
		if ( $id < 1 && class_exists( 'ERankly_Redirects_Normalizer' ) ) {
			$found = $repository->find_by_hash( ERankly_Redirects_Normalizer::rule_hash( $written ) );
			$id    = absint( $found['id'] ?? 0 );
		}
		$current = $id > 0 ? $repository->find_by_id( $id ) : null;
		if ( ! $current ) {
			return 'preserved';
		}
		$manager = new ERankly_Migration_Manager();
		if ( ! $manager->same_redirect( $current, $written ) ) {
			return 'preserved';
		}

		if ( 'redirect_create' === (string) ( $row['action'] ?? '' ) ) {
			return $repository->delete( $id ) ? 'rolled_back' : 'failed';
		}

		$old = is_array( $previous['value'] ?? null ) ? $previous['value'] : null;
		return $old && $repository->update( $id, $old ) ? 'rolled_back' : 'failed';
	}

	/**
	 * Persists one per-record rollback result.
	 *
	 * @param int    $id    Journal row ID.
	 * @param string $state rolled_back|preserved|failed.
	 */
	private function set_rollback_state( int $id, string $state ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable rollback result.
			self::table_name(),
			array(
				'state'          => sanitize_key( $state ),
				'rolled_back_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Stable comparison for scalar, list and associative metadata.
	 *
	 * @param mixed $value Value to normalize.
	 * @return string Canonical JSON.
	 */
	private function canonical_json( mixed $value ): string {
		if ( is_array( $value ) ) {
			if ( ! erankly_array_is_list( $value ) ) {
				ksort( $value );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = is_array( $item ) ? json_decode( $this->canonical_json( $item ), true ) : $item;
			}
		}

		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
