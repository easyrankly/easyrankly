<?php
/** Resumable third-party SEO migration worker. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads one source plugin in restart-safe batches and writes directly into EasyRankly.
 *
 * Discovery and writing happen in the same pass. A preview runs the identical code path and stops just
 * before each write, so the counters an administrator reviews are produced by the logic that will actually
 * run rather than by a parallel simulation.
 */
final class ERankly_Migration_Job_Runner {
	private const LOCK_TTL     = 300;
	private const DETAIL_LIMIT = 200;

	private ERankly_Migration_Manager $manager;

	/** @var array<string,mixed>|null */
	private ?array $active_job_cache = null;

	public function __construct() {
		$this->manager = erankly_migration_manager();
	}

	/**
 * Starts a new resumable preview or import.
 *
 * @param bool $dry_run Whether writes are simulated.
 * @return array{ok:bool,job?:array<string,mixed>,error?:string}
 * @throws RuntimeException When an owned checkpoint cannot be removed after a failed start.
 */
	public function start( string $source, bool $dry_run ): array {
		$active = $this->raw_active_job();
		if ( is_array( $active ) ) {
			return array(
				'ok'    => false,
				'job'   => $active,
				'error' => 'migration_already_running',
			);
		}
		$active_import = $this->active_import_job();
		if ( is_array( $active_import ) ) {
			return array(
				'ok'    => false,
				'job'   => $active_import,
				'error' => 'import_already_running',
			);
		}

		$adapter = $this->manager->adapter( $source );
		if ( ! $adapter ) {
			return array(
				'ok'    => false,
				'error' => 'unknown_source',
			);
		}
		if ( ! $adapter->is_available() ) {
			return array(
				'ok'    => false,
				'error' => 'no_source_data',
			);
		}

		$fingerprint = $adapter->fingerprint();
		if ( '' === $fingerprint ) {
			return array(
				'ok'    => false,
				'error' => 'source_fingerprint_unavailable',
			);
		}

		$job_id                       = wp_generate_uuid4();
		$report                       = $this->manager->new_report( $source, $dry_run, $job_id );
		$report['source_fingerprint'] = $fingerprint;
		$report['source_profile']     = array(
			'mode'           => 'database',
			'version_status' => $adapter->version_status( $adapter->version() ),
		);

		// An out-of-range version still imports: the mapping is keyed on storage layout, not on the version
		// string. The administrator is told so an unexpected result can be traced back to it.
		if ( 'unsupported' === (string) $report['source_profile']['version_status'] ) {
			$report['warnings'][] = array(
				'code'      => 'source_version_outside_certified_range',
				'message'   => 'The detected source version is outside the range this adapter was verified against. Review the imported values before deactivating the old plugin.',
				'reference' => sanitize_text_field( $adapter->version() ),
				'blocking'  => false,
			);
		}

		// A real import is the only irreversible step, so capture the undo artefact before the first write.
		if ( ! $dry_run ) {
			$backup = erankly_migration_create_backup();
			if ( empty( $backup['ok'] ) ) {
				return array(
					'ok'    => false,
					'error' => sanitize_key( (string) ( $backup['error'] ?? 'backup_write_failed' ) ),
				);
			}
			unset( $backup['ok'] );
			$report['backup'] = $backup;
		}

		$job = array(
			'id'                 => $job_id,
			'source'             => $adapter->slug(),
			'dry_run'            => $dry_run,
			'status'             => 'queued',
			'stream'             => 'settings',
			'cursor'             => array(),
			'batches'            => 0,
			'cancel_requested'   => false,
			'started_at'         => gmdate( 'c' ),
			'updated_at'         => gmdate( 'c' ),
			'last_error'         => '',
			'source_fingerprint' => $fingerprint,
			'counts'             => $this->manager->empty_counts(),
			'report'             => $report,
		);

		$start_token = erankly_acquire_data_transfer_start_lock();
		if ( '' === $start_token ) {
			$this->discard_unattached_backup( $report );
			return array(
				'ok'    => false,
				'error' => 'transfer_start_in_progress',
			);
		}

		try {
			// Recheck both workers inside the shared gate. The earlier checks make
			// common requests cheap; these close the race while a backup was made.
			$active = $this->raw_active_job();
			if ( is_array( $active ) ) {
				$this->discard_unattached_backup( $report );
				return array(
					'ok'    => false,
					'job'   => $active,
					'error' => 'migration_already_running',
				);
			}
			$active_import = $this->active_import_job();
			if ( is_array( $active_import ) ) {
				$this->discard_unattached_backup( $report );
				return array(
					'ok'    => false,
					'job'   => $active_import,
					'error' => 'import_already_running',
				);
			}

			if ( ! add_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, $job, '', 'no' ) ) {
				$this->discard_unattached_backup( $report );
				$active        = $this->raw_active_job();
				$active_import = $this->active_import_job();
				if ( is_array( $active ) ) {
					return array(
						'ok'    => false,
						'job'   => $active,
						'error' => 'migration_already_running',
					);
				}
				if ( is_array( $active_import ) ) {
					return array(
						'ok'    => false,
						'job'   => $active_import,
						'error' => 'import_already_running',
					);
				}

				return array(
					'ok'    => false,
					'error' => 'job_checkpoint_unavailable',
				);
			}
			$this->active_job_cache = null;

			try {
				$stored = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );
				if ( ! is_array( $stored ) || wp_json_encode( $stored ) !== wp_json_encode( $job ) ) {
					throw new RuntimeException( 'Migration job checkpoint could not be persisted.' );
				}
				if ( ! $this->schedule( $job_id ) ) {
					$this->add_warning( $job, 'cron_schedule_failed', 'The automatic worker could not be scheduled. Use Resume now from the migration screen.', '' );
					$this->save_job( $job );
				}
			} catch ( RuntimeException ) {
				$this->delete_active_job_if_owned( $job_id );
				$this->discard_unattached_backup( $report );
				return array(
					'ok'    => false,
					'error' => 'job_checkpoint_unavailable',
				);
			}

			return array(
				'ok'  => true,
				'job' => $job,
			);
		} finally {
			erankly_release_data_transfer_start_lock( $start_token );
		}
	}

	/** @return array<string,mixed>|null */
	public function active_job(): ?array {
		if ( is_array( $this->active_job_cache ) ) {
			return $this->active_job_cache;
		}
		$job = $this->raw_active_job();
		if ( is_array( $job ) && 'paused' !== (string) ( $job['status'] ?? '' ) && empty( $job['cancel_requested'] ) ) {
			$this->schedule( (string) $job['id'] );
		}

		$this->active_job_cache = $job;

		return $this->active_job_cache;
	}

	/**
 * Requests cancellation and processes it immediately when the lock is free.
 *
 * @throws RuntimeException When the cancellation request cannot be persisted.
 */
	public function cancel( string $job_id ): bool {
		$job = $this->raw_active_job();
		if ( ! is_array( $job ) || ! hash_equals( (string) $job['id'], $job_id ) ) {
			return false;
		}

		update_option( $this->cancel_key( $job_id ), true, false );
		if ( true !== get_option( $this->cancel_key( $job_id ), false ) ) {
			throw new RuntimeException( 'Migration cancellation request could not be persisted.' );
		}
		$this->active_job_cache = null;
		$this->process( $job_id );

		return true;
	}

	/**
 * Advances one batch of a resumable migration.
 *
 * @return array<string,mixed>|null Current job, or null once it reaches a terminal state.
 * @throws RuntimeException When checkpoint persistence fails.
 */
	public function process( string $job_id ): ?array {
		$job = $this->raw_active_job();
		if ( ! is_array( $job ) || ! hash_equals( (string) $job['id'], $job_id ) ) {
			return null;
		}

		$token = $this->acquire_lock( $job_id );
		if ( '' === $token ) {
			$this->schedule( $job_id, 10 );
			return $job;
		}

		try {
			$job = $this->raw_active_job();
			if ( ! is_array( $job ) || ! hash_equals( (string) $job['id'], $job_id ) ) {
				return null;
			}
			if ( ! empty( $job['cancel_requested'] ) ) {
				if ( ! $this->renew_lock( $job_id, $token ) ) {
					throw new RuntimeException( 'The migration worker lease was lost before cancellation.' );
				}
				$this->finish( $job, true );
				return null;
			}
			if ( 'paused' === (string) ( $job['status'] ?? '' ) ) {
				return $job;
			}

			$adapter = $this->manager->adapter( (string) $job['source'] );
			if ( ! $adapter ) {
				throw new RuntimeException( 'Migration adapter is no longer available.' );
			}

			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'erankly_import_variable_diagnostics' ) ) {
				erankly_import_variable_diagnostics( null, true );
			}

			if ( ! $this->renew_lock( $job_id, $token ) ) {
				throw new RuntimeException( 'The migration worker lease was lost before applying the batch.' );
			}

			$job['status'] = ! empty( $job['dry_run'] ) ? 'previewing' : 'importing';
			$this->process_stream( $job, $adapter, $token );
			if ( ! $this->renew_lock( $job_id, $token ) ) {
				throw new RuntimeException( 'The migration worker lease was lost before saving its checkpoint.' );
			}

			++$job['batches'];
			$job['updated_at'] = gmdate( 'c' );
			$this->collect_warnings( $job, $adapter );

			if ( 'finish' === (string) $job['stream'] ) {
				$this->finish( $job, false );
				return null;
			}

			$this->save_job( $job );
			$this->schedule( $job_id );

			return $job;
		} catch ( Throwable $error ) {
			// A stale worker must never overwrite a checkpoint after another worker
			// has taken its expired lease. A token owner, however, keeps the local
			// cursor and counters so a caught write error does not lose progress.
			if ( ! $this->owns_lock( $job_id, $token ) ) {
				$this->schedule( $job_id, 10 );
				$current = $this->raw_active_job();
				return is_array( $current ) ? $current : null;
			}

			$current = $this->raw_active_job();
			if ( is_array( $current ) && hash_equals( (string) $current['id'], $job_id ) ) {
				if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['id'] ?? '' ), $job_id ) ) {
					$job = $current;
				}
				$job['status']     = 'paused';
				$job['last_error'] = sanitize_text_field( get_class( $error ) );
				$job['updated_at'] = gmdate( 'c' );
				if ( $error instanceof ERankly_Migration_Source_Changed_Exception ) {
					$this->add_warning( $job, 'source_changed_after_start', 'Source SEO data changed after this migration started. Cancel this job and run a fresh preview.', '' );
				} else {
					$this->add_warning( $job, 'worker_interrupted', 'The worker paused after an unexpected error. The saved checkpoint can be resumed safely.', get_class( $error ) );
				}
				$this->save_job( $job );
				return $job;
			}

			return null;
		} finally {
			$this->release_lock( $job_id, $token );
		}
	}

	/** @throws ERankly_Migration_Source_Changed_Exception When the source changes mid-run. */
	private function process_stream( array &$job, ERankly_Migration_Adapter $adapter, string $token ): void {
		$stream = (string) ( $job['stream'] ?? 'settings' );
		$job_id = (string) ( $job['id'] ?? '' );

		if ( 'settings' === $stream ) {
			$this->write_global_settings( $job, $adapter->global_settings(), $token );
			$job['stream'] = 'content';
			$job['cursor'] = array();
			return;
		}

		$limit = max( 10, min( 500, (int) apply_filters( 'erankly_migration_batch_size', ERANKLY_MIGRATION_BATCH_SIZE ) ) );
		$page  = 'content' === $stream
			? $adapter->content_batch( is_array( $job['cursor'] ) ? $job['cursor'] : array(), $limit )
			: $adapter->redirect_batch( is_array( $job['cursor'] ) ? $job['cursor'] : array(), $limit );
		$records = is_array( $page['records'] ?? null ) ? $page['records'] : array();

		if ( ! $this->renew_lock( $job_id, $token ) ) {
			throw new RuntimeException( 'The migration worker lease was lost while reading the source batch.' );
		}

		if ( 'content' === $stream ) {
			$processed = 0;
			foreach ( $records as $record ) {
				if ( is_array( $record ) ) {
					$this->write_content_record( $job, $record );
				}
				++$processed;
				if ( 0 === $processed % 25 && ! $this->renew_lock( $job_id, $token ) ) {
					throw new RuntimeException( 'The migration worker lease was lost while writing content.' );
				}
			}
		} else {
			$this->write_redirect_batch( $job, $adapter, $records, $token );
		}

		$job['cursor'] = is_array( $page['cursor'] ?? null ) ? $page['cursor'] : array();
		if ( empty( $page['done'] ) ) {
			return;
		}

		if ( 'content' === $stream ) {
			$job['stream'] = 'redirect';
			$job['cursor'] = array();
			return;
		}

		// The source must be identical to what the run started from, otherwise the counters describe a state
		// that no longer exists.
		$current  = $adapter->fingerprint();
		$expected = (string) ( $job['source_fingerprint'] ?? '' );
		if ( '' === $current || '' === $expected || ! hash_equals( $expected, $current ) ) {
			throw new ERankly_Migration_Source_Changed_Exception( 'Source SEO data changed during the migration.' );
		}
		if ( is_array( $job['report'] ?? null ) ) {
			$job['report']['source_fingerprint_verified'] = true;
			$job['report']['source_verified_at']          = gmdate( 'c' );
		}

		$job['stream'] = 'finish';
		$job['cursor'] = array();
	}

	/** Writes sanitized global settings, preserving every customized EasyRankly leaf. */
	private function write_global_settings( array &$job, array $settings, string $token ): void {
		if ( ! $settings ) {
			return;
		}

		erankly_load_default_helpers();
		// Global metadata maps live in the content helper bundle. Background workers never render an admin or
		// frontend surface first, so they must load it explicitly before reconciling defaults.
		erankly_load_content_helpers();
		if ( ! function_exists( 'erankly_sanitize_settings' ) ) {
			require_once ERANKLY_PATH . 'admin/settings-page.php';
		}

		$defaults = erankly_default_settings();
		$settings = array_intersect_key( $settings, $defaults );
		if ( ! $settings ) {
			return;
		}

		$current_effective = erankly_get_settings();
		foreach ( array( 'global_post_type_meta', 'global_taxonomy_meta', 'global_special_meta' ) as $map_key ) {
			if ( isset( $settings[ $map_key ] ) && is_array( $settings[ $map_key ] ) ) {
				$current_map = 'global_special_meta' === $map_key ? erankly_get_global_entity_meta_map( $map_key ) : ( is_array( $current_effective[ $map_key ] ?? null ) ? $current_effective[ $map_key ] : array() );
				foreach ( $settings[ $map_key ] as $entity => $row ) {
					if ( is_array( $row ) && isset( $current_map[ $entity ] ) && is_array( $current_map[ $entity ] ) ) {
						$current_map[ $entity ] = array_replace( $current_map[ $entity ], $row );
					} else {
						$current_map[ $entity ] = $row;
					}
				}
				$settings[ $map_key ] = $current_map;
			}
		}
		$sanitized = erankly_sanitize_settings( array_replace( $current_effective, $settings ) );
		$stored    = erankly_get_stored_settings();

		foreach ( array_keys( $settings ) as $key ) {
			if ( ! $this->renew_lock( (string) ( $job['id'] ?? '' ), $token ) ) {
				throw new RuntimeException( 'The migration worker lease was lost while writing settings.' );
			}
			if ( ! array_key_exists( $key, $sanitized ) ) {
				continue;
			}
			++$job['counts']['settings_found'];

			$is_special   = 'global_special_meta' === $key;
			$exists       = $is_special && is_multisite()
				? false !== get_option( ERANKLY_SPECIAL_META_OPTION, false )
				: array_key_exists( $key, $stored );
			$current      = $is_special ? erankly_get_global_entity_meta_map( $key ) : ( $exists ? $stored[ $key ] : $defaults[ $key ] );
			$stored_value = $is_special && is_multisite()
				? get_option( ERANKLY_SPECIAL_META_OPTION, array() )
				: ( $exists ? $stored[ $key ] : null );

			$preserved_paths = array();
			$value           = $this->reconcile_setting_value( $current, $sanitized[ $key ], $defaults[ $key ], $stored_value, $exists, $key, $preserved_paths );

			foreach ( $preserved_paths as $path ) {
				++$job['counts']['settings_conflicts'];
				$this->add_detail( $job, 'existing_setting_value_preserved', 'settings:' . $path, $key );
			}

			if ( $this->canonical_json( $current ) === $this->canonical_json( $value ) ) {
				++$job['counts']['settings_identical'];
				continue;
			}
			if ( ! empty( $job['dry_run'] ) ) {
				++$job['counts']['settings_ready'];
				continue;
			}

			if ( $is_special ) {
				$written = is_array( $value ) && erankly_update_special_meta_map( $value ) === $value;
			} else {
				$updated = erankly_update_plugin_settings( array( $key => $value ) );
				$written = ! is_wp_error( $updated ) && (bool) $updated;
			}
			if ( $written ) {
				++$job['counts']['settings_written'];
			} else {
				++$job['counts']['settings_failed'];
				$this->add_detail( $job, 'write_failed', 'settings:' . $key, $key );
			}
		}
	}

	/**
 * Merges one proposed source setting into the target at leaf granularity. Missing and default-valued leaves are
 * safe migration targets. A genuinely customized EasyRankly leaf is retained and reported without preventing
 * unrelated source leaves in the same settings map from being imported.
 *
 * @param bool              $stored_exists   Whether this path is explicitly stored.
 * @param array<int,string> $preserved_paths Customized leaves retained by reference.
 */
	private function reconcile_setting_value( mixed $current, mixed $proposed, mixed $default, mixed $stored, bool $stored_exists, string $path, array &$preserved_paths ): mixed {
		if ( is_array( $current ) && is_array( $proposed ) ) {
			$result = $current;
			foreach ( $proposed as $child_key => $child_proposed ) {
				$child_name           = (string) $child_key;
				$child_path           = '' === $path ? $child_name : $path . '.' . $child_name;
				$child_current_exists = array_key_exists( $child_key, $current );
				$child_default_exists = is_array( $default ) && array_key_exists( $child_key, $default );
				$child_stored_exists  = $stored_exists && is_array( $stored ) && array_key_exists( $child_key, $stored );
				$result[ $child_key ] = $this->reconcile_setting_value(
					$child_current_exists ? $current[ $child_key ] : null,
					$child_proposed,
					$child_default_exists ? $default[ $child_key ] : null,
					$child_stored_exists ? $stored[ $child_key ] : null,
					$child_stored_exists,
					$child_path,
					$preserved_paths
				);
			}

			return $result;
		}

		if ( $this->canonical_json( $current ) === $this->canonical_json( $proposed ) ) {
			return $proposed;
		}
		if ( is_string( $current ) && is_string( $proposed ) ) {
			$legacy_variants = array(
				str_replace( '{{pagination}}', '{{page_number}}', $proposed ),
				str_replace( '{{page_number}}', '{{pagination}}', $proposed ),
				str_replace( '{{current_pagination}}', '{{pagination}}', $proposed ),
			);
			if ( in_array( $current, $legacy_variants, true ) ) {
				return $proposed;
			}
		}
		if ( ! $stored_exists || $this->canonical_json( $current ) === $this->canonical_json( $default ) ) {
			return $proposed;
		}

		$preserved_paths[] = $path;

		return $current;
	}

	/** Writes one object's metadata, never overwriting a value EasyRankly already holds. */
	private function write_content_record( array &$job, array $record ): void {
		$object_type = sanitize_key( (string) ( $record['object_type'] ?? '' ) );
		$object_id   = absint( $record['object_id'] ?? 0 );
		$reference   = sanitize_text_field( (string) ( $record['source_reference'] ?? $object_type . ':' . $object_id ) );
		$meta        = is_array( $record['meta'] ?? null ) ? $record['meta'] : array();

		if ( ! in_array( $object_type, array( 'post', 'term', 'user' ), true ) || $object_id < 1 ) {
			++$job['counts']['objects_invalid'];
			$this->add_detail( $job, 'invalid_object', $reference, '' );
			return;
		}

		++$job['counts']['objects_found'];
		++$job['counts'][ $object_type . 's_found' ];

		// Terms and users read a narrower set of keys than posts do. Filtering here rather than in each adapter
		// keeps every source from writing rows that no EasyRankly feature can ever read back.
		$allowed    = erankly_importable_meta_keys( $object_type );
		$registered = erankly_get_meta_keys();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( ! $this->manager->is_meaningful( $value ) ) {
				continue;
			}
			if ( ! isset( $allowed[ $key ] ) ) {
				if ( isset( $registered[ $key ] ) ) {
					++$job['counts']['fields_unsupported'];
				}
				continue;
			}

			++$job['counts']['fields_found'];
			$clean = erankly_sanitize_registered_meta( $value, $key );
			if ( ! $this->manager->is_meaningful( $clean ) ) {
				++$job['counts']['fields_invalid'];
				$this->add_detail( $job, 'invalid_value', $reference, $key );
				continue;
			}

			if ( metadata_exists( $object_type, $object_id, $key ) ) {
				$current = get_metadata( $object_type, $object_id, $key, true );
				if ( wp_json_encode( $current ) === wp_json_encode( $clean ) ) {
					++$job['counts']['fields_identical'];
				} else {
					++$job['counts']['fields_conflicts'];
					$this->add_detail( $job, 'existing_value_preserved', $reference, $key );
				}
				continue;
			}

			if ( ! empty( $job['dry_run'] ) ) {
				++$job['counts']['fields_ready'];
				++$job['counts'][ $object_type . '_fields_ready' ];
				continue;
			}

			if ( false === update_metadata( $object_type, $object_id, $key, $clean ) ) {
				++$job['counts']['fields_failed'];
				$this->add_detail( $job, 'write_failed', $reference, $key );
				continue;
			}

			++$job['counts']['fields_written'];
			++$job['counts'][ $object_type . '_fields_written' ];
		}
	}

	/**
 * Writes one page of redirects through the shared normalizer and repository.
 *
 * @param array<int,mixed> $records Raw source rows.
 */
	private function write_redirect_batch( array &$job, ERankly_Migration_Adapter $adapter, array $records, string $token ): void {
		if ( ! $records ) {
			return;
		}

		erankly_ensure_redirect_classes_available();
		if ( ! class_exists( 'ERankly_Redirects_Normalizer' ) || ! class_exists( 'ERankly_Redirects_Repository' ) ) {
			$job['counts']['redirects_failed'] += count( $records );
			$this->add_warning( $job, 'redirect_engine_unavailable', 'The EasyRankly redirect engine could not be loaded.', '' );
			return;
		}
		if ( empty( $job['dry_run'] ) && class_exists( 'ERankly_Redirects_Activator' ) && ! erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			ERankly_Redirects_Activator::activate();
		}

		$repository = erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ? new ERankly_Redirects_Repository() : null;
		if ( $repository && empty( $job['dry_run'] ) ) {
			$repository->begin_bulk();
		}

		try {
			$processed = 0;
			foreach ( $records as $row ) {
				if ( is_array( $row ) ) {
					$this->write_redirect_record( $job, $adapter, $row, $repository );
				}
				++$processed;
				if ( 0 === $processed % 25 && ! $this->renew_lock( (string) ( $job['id'] ?? '' ), $token ) ) {
					throw new RuntimeException( 'The migration worker lease was lost while writing redirects.' );
				}
			}
		} finally {
			if ( $repository && empty( $job['dry_run'] ) ) {
				$repository->end_bulk();
			}
		}
	}

	private function write_redirect_record( array &$job, ERankly_Migration_Adapter $adapter, array $row, ?ERankly_Redirects_Repository $repository ): void {
		$reference = sanitize_text_field( (string) ( $row['source_reference'] ?? '' ) );
		if ( '' === $reference ) {
			$reference = 'redirect:' . substr( hash( 'sha256', (string) wp_json_encode( $row ) ), 0, 24 );
		}
		++$job['counts']['redirects_found'];

		$row['source_plugin'] = $adapter->slug();
		$row['migration_id']  = (string) $job['id'];
		$legacy_match         = in_array( (string) ( $row['match_type'] ?? '' ), array( 'contains', 'starts_with', 'ends_with' ), true );

		$unsupported = erankly_import_redirect_unsupported_reason( $row );
		if ( '' !== $unsupported ) {
			++$job['counts']['redirects_unsupported'];
			$this->add_detail( $job, 'unsupported_redirect_' . $unsupported, $reference, '' );
			return;
		}

		$redirect = erankly_import_prepare_redirect( $row );
		if ( null === $redirect ) {
			++$job['counts']['redirects_invalid'];
			$this->add_detail( $job, 'invalid_redirect', $reference, '' );
			return;
		}
		if ( $legacy_match ) {
			++$job['counts']['redirects_transformed'];
			$this->add_detail( $job, 'redirect_match_safely_transformed', $reference, '' );
		}
		if ( ! $repository ) {
			if ( ! empty( $job['dry_run'] ) ) {
				++$job['counts']['redirects_ready_create'];
				return;
			}
			++$job['counts']['redirects_failed'];
			return;
		}

		$existing = $repository->find_by_hash( ERankly_Redirects_Normalizer::rule_hash( $redirect ) );
		if ( $existing && $adapter->slug() !== (string) ( $existing['source_plugin'] ?? '' ) ) {
			++$job['counts']['redirects_conflicts'];
			$this->add_detail( $job, 'redirect_conflict_preserved', $reference, '' );
			return;
		}
		if ( $existing && $this->manager->same_redirect( $existing, $redirect ) ) {
			++$job['counts']['redirects_unchanged'];
			return;
		}

		if ( ! empty( $job['dry_run'] ) ) {
			++$job['counts'][ $existing ? 'redirects_ready_update' : 'redirects_ready_create' ];
			return;
		}

		if ( $existing ) {
			if ( $repository->update( absint( $existing['id'] ?? 0 ), $redirect ) ) {
				++$job['counts']['redirects_updated'];
			} else {
				++$job['counts']['redirects_failed'];
				$this->add_detail( $job, 'write_failed', $reference, '' );
			}
			return;
		}

		if ( $repository->create( $redirect ) > 0 ) {
			++$job['counts']['redirects_created'];
		} else {
			++$job['counts']['redirects_failed'];
			$this->add_detail( $job, 'write_failed', $reference, '' );
		}
	}

	/**
 * Persists the terminal report and clears every checkpoint this job owns.
 *
 * @param bool $cancelled Whether the administrator stopped the run.
 * @throws RuntimeException When the owned checkpoint cannot be removed.
 */
	private function finish( array $job, bool $cancelled ): void {
		$report            = is_array( $job['report'] ?? null ) ? $job['report'] : $this->manager->new_report( (string) $job['source'], ! empty( $job['dry_run'] ), (string) $job['id'] );
		$report['counts']  = is_array( $job['counts'] ?? null ) ? $job['counts'] : $this->manager->empty_counts();
		$report['details'] = $this->clean_diagnostics( is_array( $report['details'] ?? null ) ? $report['details'] : array() );
		$report['execution'] = array(
			'resumable' => true,
			'batches'   => absint( $job['batches'] ?? 0 ),
			'worker'    => 'wp-cron',
		);

		if ( $cancelled ) {
			$report['warnings'][] = array(
				'code'      => 'migration_cancelled',
				'message'   => 'The administrator cancelled the migration. Source data and existing EasyRankly values were preserved.',
				'reference' => '',
				'blocking'  => false,
			);
			$report['status'] = 'cancelled';
		} else {
			$failed     = (int) $report['counts']['settings_failed'] + (int) $report['counts']['fields_failed'] + (int) $report['counts']['redirects_failed'];
			$successful = (int) $report['counts']['settings_written'] + (int) $report['counts']['fields_written'] + (int) $report['counts']['redirects_created'] + (int) $report['counts']['redirects_updated'];
			$report['status'] = $failed > 0 ? ( $successful > 0 ? 'partial' : 'failed' ) : 'complete';
		}
		$report['warnings'] = $this->clean_diagnostics( is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array() );

		$this->manager->finish_report( $report );
		$this->delete_active_job_if_owned( (string) $job['id'] );
		delete_option( $this->cancel_key( (string) $job['id'] ) );
		$this->active_job_cache = null;
		wp_clear_scheduled_hook( ERANKLY_MIGRATION_CRON_HOOK, array( (string) $job['id'] ) );
	}

	private function collect_warnings( array &$job, ERankly_Migration_Adapter $adapter ): void {
		$warnings = $adapter->warnings();
		if ( function_exists( 'erankly_import_variable_diagnostics' ) ) {
			$warnings = array_merge( $warnings, erankly_import_variable_diagnostics() );
		}
		foreach ( $warnings as $warning ) {
			if ( is_array( $warning ) ) {
				$this->add_warning(
					$job,
					(string) ( $warning['code'] ?? 'migration_warning' ),
					(string) ( $warning['message'] ?? '' ),
					(string) ( $warning['reference'] ?? '' ),
					! isset( $warning['blocking'] ) || (bool) $warning['blocking']
				);
			}
		}
	}

	/**
 * Adds a unique bounded warning to the job report.
 *
 * @param bool $blocking Whether the warning marks the migration as needing attention.
 */
	private function add_warning( array &$job, string $code, string $message, string $reference, bool $blocking = true ): void {
		$report   = is_array( $job['report'] ?? null ) ? $job['report'] : array();
		$warnings = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array();
		$key      = sanitize_key( $code ) . '|' . sanitize_text_field( $reference );
		foreach ( $warnings as $warning ) {
			if ( (string) ( $warning['_key'] ?? '' ) === $key ) {
				return;
			}
		}
		if ( count( $warnings ) >= self::DETAIL_LIMIT ) {
			return;
		}
		$warnings[]         = array(
			'_key'      => $key,
			'code'      => sanitize_key( $code ),
			'message'   => sanitize_text_field( $message ),
			'reference' => sanitize_text_field( $reference ),
			'blocking'  => $blocking,
		);
		$report['warnings'] = $warnings;
		$job['report']      = $report;
	}

	private function add_detail( array &$job, string $code, string $reference, string $field ): void {
		$report  = is_array( $job['report'] ?? null ) ? $job['report'] : array();
		$details = is_array( $report['details'] ?? null ) ? $report['details'] : array();
		if ( count( $details ) >= self::DETAIL_LIMIT ) {
			return;
		}
		$key = sanitize_key( $code ) . '|' . sanitize_text_field( $reference ) . '|' . sanitize_key( $field );
		foreach ( $details as $detail ) {
			if ( (string) ( $detail['_key'] ?? '' ) === $key ) {
				return;
			}
		}
		$details[]         = array(
			'_key'      => $key,
			'code'      => sanitize_key( $code ),
			'reference' => sanitize_text_field( $reference ),
			'field'     => sanitize_key( $field ),
		);
		$report['details'] = $details;
		$job['report']     = $report;
	}

	/**
 * Removes internal deduplication keys before a report is persisted.
 *
 * @return array<int,array<string,mixed>>
 */
	private function clean_diagnostics( array $diagnostics ): array {
		$clean = array();
		foreach ( array_slice( $diagnostics, 0, self::DETAIL_LIMIT ) as $diagnostic ) {
			if ( is_array( $diagnostic ) ) {
				unset( $diagnostic['_key'] );
				$clean[] = $diagnostic;
			}
		}

		return $clean;
	}

	/** @return array<string,mixed>|null */
	private function raw_active_job(): ?array {
		$job = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );
		if ( is_array( $job ) && ! empty( $job['id'] ) && true === get_option( $this->cancel_key( (string) $job['id'] ), false ) ) {
			$job['cancel_requested'] = true;
		}

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/** @return array<string,mixed>|null */
	private function active_import_job(): ?array {
		$job = get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, null );

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/** Returns a stable JSON representation for migration value comparisons. */
	private function canonical_json( mixed $value ): string {
		$normalize = static function ( mixed $item ) use ( &$normalize ): mixed {
			if ( ! is_array( $item ) ) {
				return $item;
			}
			if ( array_keys( $item ) !== range( 0, count( $item ) - 1 ) ) {
				ksort( $item );
			}
			foreach ( $item as $key => $child ) {
				$item[ $key ] = $normalize( $child );
			}
			return $item;
		};
		$encoded = wp_json_encode( $normalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $encoded ? '' : $encoded;
	}

	/** @throws RuntimeException When the checkpoint cannot be persisted. */
	private function save_job( array $job ): void {
		update_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, $job, false );
		$this->active_job_cache = null;
		$stored                 = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );
		if ( ! is_array( $stored ) || wp_json_encode( $stored ) !== wp_json_encode( $job ) ) {
			throw new RuntimeException( 'Migration job checkpoint could not be persisted.' );
		}
	}

	private function schedule( string $job_id, int $delay = 1 ): bool {
		$args = array( $job_id );
		if ( false !== wp_next_scheduled( ERANKLY_MIGRATION_CRON_HOOK, $args ) ) {
			return true;
		}

		$result = wp_schedule_single_event( time() + max( 1, $delay ), ERANKLY_MIGRATION_CRON_HOOK, $args, true );

		return ! is_wp_error( $result ) && false !== $result;
	}

	/**
 * Acquires an atomic, expiring option lock.
 *
 * @return string Lock token or an empty string.
 */
	private function acquire_lock( string $job_id ): string {
		global $wpdb;

		$key     = $this->lock_key( $job_id );
		$token   = wp_generate_uuid4();
		$value   = array(
			'token'   => $token,
			'expires' => time() + self::LOCK_TTL,
		);
		$created = add_option( $key, $value, '', 'no' );
		if ( $created ) {
			return $token;
		}

		$lock = get_option( $key, array() );
		$expires = is_array( $lock )
			? (int) ( $lock['expires'] ?? (int) ( $lock['created'] ?? 0 ) + self::LOCK_TTL )
			: 0;
		if ( $expires >= time() ) {
			return '';
		}

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap prevents two workers taking over the same stale lock.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $value ),
				$key,
				maybe_serialize( $lock )
			)
		);
		if ( 1 === $updated ) {
			wp_cache_delete( $key, 'options' );
		}

		return 1 === $updated ? $token : '';
	}

	/** Renews a lease only while this worker still owns a non-expired token. */
	private function renew_lock( string $job_id, string $token ): bool {
		global $wpdb;

		$key  = $this->lock_key( $job_id );
		$lock = get_option( $key, array() );
		if ( ! is_array( $lock ) || ! hash_equals( (string) ( $lock['token'] ?? '' ), $token ) ) {
			return false;
		}

		$expires = (int) ( $lock['expires'] ?? (int) ( $lock['created'] ?? 0 ) + self::LOCK_TTL );
		if ( $expires < time() ) {
			return false;
		}

		$renewed            = $lock;
		$renewed['expires'] = max( time() + self::LOCK_TTL, $expires + 1 );
		$updated            = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lease renewal fences stale workers before a checkpoint write.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $renewed ),
				$key,
				maybe_serialize( $lock )
			)
		);
		if ( 1 === $updated ) {
			wp_cache_delete( $key, 'options' );
		}

		return 1 === $updated;
	}

	/** Whether the caller still owns an unexpired lease. */
	private function owns_lock( string $job_id, string $token ): bool {
		$lock = get_option( $this->lock_key( $job_id ), array() );
		if ( ! is_array( $lock ) ) {
			return false;
		}

		$expires = (int) ( $lock['expires'] ?? (int) ( $lock['created'] ?? 0 ) + self::LOCK_TTL );

		return $expires >= time() && hash_equals( (string) ( $lock['token'] ?? '' ), $token );
	}

	/** Releases a lock only when the caller still owns it. */
	private function release_lock( string $job_id, string $token ): void {
		global $wpdb;

		$key  = $this->lock_key( $job_id );
		$lock = get_option( $key, array() );
		if ( is_array( $lock ) && hash_equals( (string) ( $lock['token'] ?? '' ), $token ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-matched delete cannot release a successor's lock.
				$wpdb->prepare(
					'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
					$wpdb->options,
					$key,
					maybe_serialize( $lock )
				)
			);
			wp_cache_delete( $key, 'options' );
		}
	}

	/**
 * Deletes the active checkpoint only when it still belongs to this job.
 *
 * @throws RuntimeException When the owned checkpoint cannot be removed.
 */
	private function delete_active_job_if_owned( string $job_id ): void {
		$active = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );
		if ( ! is_array( $active ) || ! hash_equals( (string) ( $active['id'] ?? '' ), $job_id ) ) {
			return;
		}

		delete_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION );
		$active = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );
		if ( is_array( $active ) && hash_equals( (string) ( $active['id'] ?? '' ), $job_id ) ) {
			throw new RuntimeException( 'Migration job checkpoint could not be removed.' );
		}
	}

	/** Removes a backup made for a job that could not acquire its checkpoint. */
	private function discard_unattached_backup( array $report ): void {
		$backup = is_array( $report['backup'] ?? null ) ? $report['backup'] : array();
		$path   = (string) ( $backup['path'] ?? '' );

		if ( '' !== $path ) {
			ERankly_Migration_Upload_Store::delete( $path );
		}
	}

	/** Returns the bounded option name for one job lock. */
	private function lock_key( string $job_id ): string {
		return 'erankly_migration_lock_' . substr( hash( 'sha256', $job_id ), 0, 24 );
	}

	private function cancel_key( string $job_id ): string {
		return 'erankly_migration_cancel_' . substr( hash( 'sha256', $job_id ), 0, 24 );
	}
}
