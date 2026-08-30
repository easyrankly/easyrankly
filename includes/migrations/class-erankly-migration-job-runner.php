<?php
/**
 * Resumable third-party SEO migration worker.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates discovery, staging, writes, checkpoints and recovery. */
final class ERankly_Migration_Job_Runner {
	private const LOCK_TTL     = 300;
	private const DETAIL_LIMIT = 100;

	/**
	 * Shared source adapter and report manager.
	 *
	 * @var ERankly_Migration_Manager
	 */
	private ERankly_Migration_Manager $manager;

	/**
	 * Durable event queue and checkpoint store.
	 *
	 * @var ERankly_Migration_Job_Store
	 */
	private ERankly_Migration_Job_Store $store;

	/**
	 * Complete value-free exception ledger.
	 *
	 * @var ERankly_Migration_Evidence_Store
	 */
	private ERankly_Migration_Evidence_Store $evidence_store;

	/**
	 * Persistent conditional rollback journal.
	 *
	 * @var ERankly_Migration_Journal
	 */
	private ERankly_Migration_Journal $journal;

	/**
	 * Request-local active job with aggregated counters.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $active_job_cache = null;

	/** Creates the runner around the shared migration services. */
	public function __construct() {
		$this->manager        = erankly_migration_manager();
		$this->store          = new ERankly_Migration_Job_Store();
		$this->journal        = erankly_migration_journal();
		$this->evidence_store = erankly_migration_evidence_store();
	}

	/**
	 * Starts a new resumable preview or import.
	 *
	 * @param string $source      Adapter slug.
	 * @param bool   $dry_run     Whether writes are simulated.
	 * @param string $export_file Optional local official CSV/JSON export.
	 * @return array{ok:bool,job?:array<string,mixed>,error?:string}
	 * @throws RuntimeException When an owned checkpoint cannot be removed after a failed start.
	 */
	public function start( string $source, bool $dry_run, string $export_file = '' ): array {
		$active = $this->raw_active_job();
		if ( is_array( $active ) ) {
			return array(
				'ok'    => false,
				'job'   => $this->with_counts( $active ),
				'error' => 'migration_already_running',
			);
		}

		$adapter = $this->manager->adapter( $source );
		if ( ! $adapter ) {
			return array(
				'ok'    => false,
				'error' => 'unknown_source',
			);
		}
		if ( '' !== $export_file ) {
			if ( ! $adapter->use_export_file( $export_file ) ) {
				return array(
					'ok'    => false,
					'error' => 'invalid_source_export',
				);
			}
		} else {
			$adapter->use_export_file( '' );
		}

		$profile = $adapter->profile();
		if ( 'unsupported' === (string) ( $profile['storage_status'] ?? '' ) ) {
			return array(
				'ok'    => false,
				'error' => 'unsupported_source_storage',
			);
		}
		if ( ! $adapter->is_available() ) {
			return array(
				'ok'    => false,
				'error' => 'no_source_data',
			);
		}
		if ( ! $this->store->ensure_schema() ) {
			return array(
				'ok'    => false,
				'error' => 'queue_storage_unavailable',
			);
		}
		if ( ! $this->evidence_store->ensure_schema() ) {
			return array(
				'ok'    => false,
				'error' => 'evidence_storage_unavailable',
			);
		}
		if ( ! $dry_run && ! $this->journal->ensure_schema() ) {
			return array(
				'ok'    => false,
				'error' => 'rollback_storage_unavailable',
			);
		}
		$this->journal->prune_expired();

		$inventory   = $adapter->inventory();
		$fingerprint = $adapter->fingerprint();
		if ( '' === $fingerprint ) {
			return array(
				'ok'    => false,
				'error' => 'source_fingerprint_unavailable',
			);
		}

		$job_id                       = wp_generate_uuid4();
		$report                       = $this->manager->new_report( $source, $dry_run, $job_id );
		$report['source_profile']     = $profile;
		$report['source_inventory']   = $inventory;
		$report['source_fingerprint'] = $fingerprint;
		$managed_source               = $adapter->uses_export_file() && class_exists( 'ERankly_Migration_Upload_Store' ) && ERankly_Migration_Upload_Store::owns( $adapter->export_file() );
		if ( $adapter->uses_export_file() ) {
			$report['source_file_lifecycle'] = array(
				'managed_temporary' => $managed_source,
				'retention'         => $managed_source ? 'until_terminal_report' : 'caller_managed',
				'deleted'           => false,
			);
		}
		foreach ( is_array( $profile['module_support'] ?? null ) ? $profile['module_support'] : array() as $module => $support ) {
			if ( 'review_required' === $support ) {
				$report['warnings'][] = array(
					'code'      => 'source_module_review_required',
					'message'   => sprintf( 'The detected %s module has global or add-on data that requires manual review after migration.', sanitize_key( (string) $module ) ),
					'reference' => sanitize_key( (string) $module ),
					'blocking'  => false,
				);
			}
		}
		$job = array(
			'id'                  => $job_id,
			'source'              => $adapter->slug(),
			'dry_run'             => $dry_run,
			'status'              => 'queued',
			'stream'              => 'settings',
			'cursor'              => array(),
			'batches'             => 0,
			'cancel_requested'    => false,
			'started_at'          => gmdate( 'c' ),
			'updated_at'          => gmdate( 'c' ),
			'last_error'          => '',
			'source_mode'         => $adapter->uses_export_file() ? 'official_export' : 'database',
			'source_file'         => $adapter->uses_export_file() ? $adapter->export_file() : '',
			'source_file_managed' => $managed_source,
			'source_fingerprint'  => $fingerprint,
			'report'              => $report,
		);
		if ( ! add_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, $job, '', 'no' ) ) {
			$active = $this->raw_active_job();
			return is_array( $active )
				? array(
					'ok'    => false,
					'job'   => $this->with_counts( $active ),
					'error' => 'migration_already_running',
				)
				: array(
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
			return array(
				'ok'    => false,
				'error' => 'job_checkpoint_unavailable',
			);
		}

		return array(
			'ok'  => true,
			'job' => $this->with_counts( $job ),
		);
	}

	/**
	 * Starts a resumable migration from an official source-plugin export.
	 *
	 * @param string $source      Adapter slug.
	 * @param string $export_file Local readable CSV/JSON file.
	 * @param bool   $dry_run     Whether writes are simulated.
	 * @return array{ok:bool,job?:array<string,mixed>,error?:string}
	 */
	public function start_from_export( string $source, string $export_file, bool $dry_run ): array {
		return $this->start( $source, $dry_run, $export_file );
	}

	/**
	 * Returns the active job with counters rebuilt from durable queue events.
	 *
	 * @return array<string,mixed>|null
	 */
	public function active_job(): ?array {
		if ( is_array( $this->active_job_cache ) ) {
			return $this->active_job_cache;
		}
		$job = $this->raw_active_job();
		if ( is_array( $job ) && 'paused' !== (string) ( $job['status'] ?? '' ) && empty( $job['cancel_requested'] ) ) {
			$this->schedule( (string) $job['id'] );
		}

		$this->active_job_cache = is_array( $job ) ? $this->with_counts( $job ) : null;

		return $this->active_job_cache;
	}

	/**
	 * Requests cancellation and processes it immediately when the lock is free.
	 *
	 * @param string $job_id Migration UUID.
	 * @return bool
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
	 * Processes one bounded unit of work and schedules the next checkpoint.
	 *
	 * @param string $job_id Migration UUID.
	 * @return array<string,mixed>|null Current job or null after completion.
	 * @throws RuntimeException When queue storage or checkpoint persistence fails.
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
				$this->finish_cancelled( $job );
				return null;
			}

			if ( 'paused' === (string) ( $job['status'] ?? '' ) ) {
				return $job;
			}

			$adapter = $this->manager->adapter( (string) $job['source'] );
			if ( ! $adapter ) {
				throw new RuntimeException( 'Migration adapter is no longer available.' );
			}
			$source_file = (string) ( $job['source_file'] ?? '' );
			if ( '' !== $source_file ) {
				if ( ! $adapter->use_export_file( $source_file ) ) {
					throw new RuntimeException( 'The official source export is no longer readable.' );
				}
			} else {
				$adapter->use_export_file( '' );
			}
			if ( ! $this->store->ensure_schema() ) {
				throw new RuntimeException( 'Migration queue storage is unavailable.' );
			}

			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'erankly_import_variable_diagnostics' ) ) {
				erankly_import_variable_diagnostics( null, true );
			}

			$stream = (string) ( $job['stream'] ?? 'settings' );
			if ( in_array( $stream, array( 'settings', 'content', 'redirect' ), true ) ) {
				$job['status'] = 'discovering';
				$this->process_discovery_page( $job, $adapter, $stream );
			} else {
				$job['status'] = 'applying';
				$this->process_apply_page( $job );
			}

			++$job['batches'];
			$job['updated_at'] = gmdate( 'c' );
			$this->collect_warnings( $job, $adapter );

			if ( 'finish' === (string) $job['stream'] ) {
				$this->finish_successfully( $job );
				return null;
			}

			$this->save_job( $job );
			$this->schedule( $job_id );

			return $job;
		} catch ( Throwable $error ) {
			$job = $this->raw_active_job();
			if ( is_array( $job ) && hash_equals( (string) $job['id'], $job_id ) ) {
				$job['status']     = 'paused';
				$job['last_error'] = sanitize_text_field( get_class( $error ) );
				$job['updated_at'] = gmdate( 'c' );
				if ( $error instanceof ERankly_Migration_Source_Changed_Exception ) {
					$this->add_warning( $job, 'source_changed_after_start', 'Source SEO data changed after this migration started. No queued value was applied; cancel this job and run a fresh preview.', '' );
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

	/**
	 * Stages one adapter page and advances its keyset cursor.
	 *
	 * @param array<string,mixed>       $job     Active job.
	 * @param ERankly_Migration_Adapter $adapter Source adapter.
	 * @param string                    $stream  settings|content|redirect.
	 * @return void
	 * @throws ERankly_Migration_Source_Changed_Exception When the immutable source fingerprint changes.
	 */
	private function process_discovery_page( array &$job, ERankly_Migration_Adapter $adapter, string $stream ): void {
		if ( 'settings' === $stream ) {
			$this->stage_global_settings( $job, $adapter->global_settings() );
			$job['stream'] = 'content';
			$job['cursor'] = array();
			return;
		}

		$limit = max( 10, min( 500, (int) apply_filters( 'erankly_migration_batch_size', ERANKLY_MIGRATION_BATCH_SIZE ) ) );
		$page  = 'content' === $stream
			? $adapter->content_batch( is_array( $job['cursor'] ) ? $job['cursor'] : array(), $limit )
			: $adapter->redirect_batch( is_array( $job['cursor'] ) ? $job['cursor'] : array(), $limit );

		foreach ( is_array( $page['records'] ?? null ) ? $page['records'] : array() as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( 'content' === $stream ) {
				$this->stage_content_record( $job, $record );
			} else {
				$this->stage_redirect_record( $job, $adapter, $record );
			}
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

		$current_fingerprint  = $adapter->fingerprint();
		$expected_fingerprint = (string) ( $job['source_fingerprint'] ?? '' );
		if ( '' === $current_fingerprint || '' === $expected_fingerprint || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) {
			throw new ERankly_Migration_Source_Changed_Exception( 'Source SEO data changed during discovery.' );
		}
		if ( is_array( $job['report'] ?? null ) ) {
			$job['report']['source_fingerprint_verified'] = true;
			$job['report']['source_verified_at']          = gmdate( 'c' );
		}

		$job['stream'] = ! empty( $job['dry_run'] ) ? 'finish' : 'apply';
		$job['cursor'] = array();
	}

	/**
	 * Stages sanitized global settings without overwriting customized targets.
	 *
	 * @param array<string,mixed> $job      Active job.
	 * @param array<string,mixed> $settings Adapter-normalized settings.
	 * @return void
	 */
	private function stage_global_settings( array &$job, array $settings ): void {
		if ( ! $settings ) {
			return;
		}

		erankly_load_default_helpers();
		// Global metadata maps live in the content helper bundle. Background
		// workers do not render a frontend/admin surface first, so they must load
		// the bundle explicitly before reconciling special and entity defaults.
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
		$proposed  = array_replace( $current_effective, $settings );
		$sanitized = erankly_sanitize_settings( $proposed );
		$stored    = erankly_get_stored_settings();

		foreach ( array_keys( $settings ) as $key ) {
			if ( ! array_key_exists( $key, $sanitized ) ) {
				continue;
			}
			$value      = $sanitized[ $key ];
			$is_special = 'global_special_meta' === $key;
			$exists     = $is_special && is_multisite()
				? false !== get_option( ERANKLY_SPECIAL_META_OPTION, false )
				: array_key_exists( $key, $stored );
			$current    = $is_special ? erankly_get_global_entity_meta_map( $key ) : ( $exists ? $stored[ $key ] : $defaults[ $key ] );
			$stored_value = $is_special && is_multisite()
				? get_option( ERANKLY_SPECIAL_META_OPTION, array() )
				: ( $exists ? $stored[ $key ] : null );
			$preserved_paths = array();
			$value = $this->reconcile_setting_value( $current, $value, $defaults[ $key ], $stored_value, $exists, $key, $preserved_paths );
			$reference = 'settings:' . $key;
			$occurrence = $this->store->occurrence_key( (string) $job['id'], 'setting', $reference, $key );
			if ( $this->store->occurrence_exists( $occurrence ) ) {
				continue;
			}
			foreach ( $preserved_paths as $path ) {
				$this->add_warning(
					$job,
					'existing_setting_value_preserved',
					sprintf(
						/* translators: %s: EasyRankly settings path. */
						__( 'An existing EasyRankly value differs from the source and will be preserved: %s. Review it before importing.', 'easyrankly' ),
						$path
					),
					'settings:' . $path
				);
				$this->add_detail( $job, 'existing_setting_value_preserved', 'settings:' . $path, $key );
			}

			$status = 'ready';
			$apply  = ! empty( $job['dry_run'] ) ? 'preview' : 'pending';
			if ( $this->canonical_json( $current ) === $this->canonical_json( $value ) ) {
				$status = $preserved_paths ? 'existing' : 'identical';
				$apply  = 'none';
			}

			$encoded    = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$value_hash = hash( 'sha256', false === $encoded ? '' : $encoded );
			$inserted   = $this->store->add_event(
				array(
					'job_id'           => (string) $job['id'],
					'item_kind'        => 'setting',
					'source_reference' => $reference,
					'target_field'     => $key,
					'identity'         => 'setting:' . $key,
					'value_hash'       => $value_hash,
					'discovery_status' => $status,
					'apply_status'     => $apply,
					'payload'          => array(
						'key'             => $key,
						'value'           => $value,
						'expected_exists' => $exists,
						'expected_current'=> $current,
						'special'         => $is_special,
						'transformed'     => $this->canonical_json( $settings[ $key ] ) !== $this->canonical_json( $value ),
					),
				)
			);
			if ( $inserted && 'existing' === $status ) {
				$this->add_detail( $job, 'existing_setting_preserved', $reference, $key );
			}
		}
	}

	/**
	 * Merges one proposed source setting into the target at leaf granularity.
	 *
	 * Missing and default-valued leaves are safe migration targets. A genuinely
	 * customized EasyRankly leaf is retained and reported without preventing
	 * unrelated source leaves in the same settings map from being imported.
	 *
	 * @param mixed             $current         Current effective value.
	 * @param mixed             $proposed        Sanitized source proposal.
	 * @param mixed             $default         EasyRankly default value.
	 * @param mixed             $stored          Explicitly stored value.
	 * @param bool              $stored_exists   Whether this path is explicitly stored.
	 * @param string            $path            Dot-delimited settings path.
	 * @param array<int,string> $preserved_paths Customized leaves retained by reference.
	 * @return mixed
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
		if ( is_string( $current ) && is_string( $proposed ) && str_replace( '{{pagination}}', '{{page_number}}', $proposed ) === $current ) {
			return $proposed;
		}
		if ( ! $stored_exists || $this->canonical_json( $current ) === $this->canonical_json( $default ) ) {
			return $proposed;
		}

		$preserved_paths[] = $path;

		return $current;
	}

	/**
	 * Converts one content record into idempotent object and field events.
	 *
	 * @param array<string,mixed> $job    Active job.
	 * @param array<string,mixed> $record Normalized adapter record.
	 * @return void
	 */
	private function stage_content_record( array &$job, array $record ): void {
		$object_type = sanitize_key( (string) ( $record['object_type'] ?? '' ) );
		$object_id   = absint( $record['object_id'] ?? 0 );
		$reference   = sanitize_text_field( (string) ( $record['source_reference'] ?? $object_type . ':' . $object_id ) );
		$meta        = is_array( $record['meta'] ?? null ) ? $record['meta'] : array();
		$valid       = in_array( $object_type, array( 'post', 'term', 'user' ), true ) && $object_id > 0;
		$object_ref  = $valid ? 'object:' . $object_type . ':' . $object_id : 'invalid:' . $reference;

		$object_occurrence = $this->store->occurrence_key( (string) $job['id'], 'object', $object_ref, '' );
		$inserted          = false;
		if ( ! $this->store->occurrence_exists( $object_occurrence ) ) {
			$inserted = $this->store->add_event(
				array(
					'job_id'           => (string) $job['id'],
					'item_kind'        => 'object',
					'object_type'      => $object_type,
					'source_reference' => $object_ref,
					'target_field'     => '',
					'identity'         => $object_ref,
					'discovery_status' => $valid ? 'found' : 'invalid',
					'apply_status'     => 'none',
					'payload'          => $valid ? array(
						'object_type' => $object_type,
						'object_id'   => $object_id,
					) : array(),
				)
			);
		}
		if ( ! $valid ) {
			if ( $inserted ) {
				$this->add_detail( $job, 'invalid_object', $reference, '' );
			}
			return;
		}

		$allowed = erankly_get_meta_keys();
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $allowed[ $key ] ) || ! $this->manager->is_meaningful( $value ) ) {
				continue;
			}

			$occurrence = $this->store->occurrence_key( (string) $job['id'], 'meta', $reference, $key );
			if ( $this->store->occurrence_exists( $occurrence ) ) {
				continue;
			}

			$clean      = erankly_sanitize_registered_meta( $value, $key );
			$identity   = $object_type . ':' . $object_id . ':' . $key;
			$encoded    = wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$value_hash = hash( 'sha256', false === $encoded ? '' : $encoded );
			$status     = 'ready';
			$apply      = ! empty( $job['dry_run'] ) ? 'preview' : 'pending';

			if ( ! $this->manager->is_meaningful( $clean ) ) {
				$status = 'invalid';
				$apply  = 'none';
			} else {
				$first = $this->store->first_identity( (string) $job['id'], 'meta', $identity, array( 'ready' ) );
				if ( is_array( $first ) ) {
					$status = hash_equals( (string) $first['value_hash'], $value_hash ) ? 'duplicate' : 'conflict';
					$apply  = 'none';
				} elseif ( metadata_exists( $object_type, $object_id, $key ) ) {
					$current = get_metadata( $object_type, $object_id, $key, true );
					$status  = wp_json_encode( $current ) === wp_json_encode( $clean ) ? 'identical' : 'existing';
					$apply   = 'none';
				}
			}

			$inserted = $this->store->add_event(
				array(
					'job_id'           => (string) $job['id'],
					'item_kind'        => 'meta',
					'object_type'      => $object_type,
					'source_reference' => $reference,
					'target_field'     => $key,
					'identity'         => $identity,
					'value_hash'       => $value_hash,
					'discovery_status' => $status,
					'apply_status'     => $apply,
					'payload'          => array(
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'key'         => $key,
						'value'       => $clean,
						'transformed' => wp_json_encode( $value ) !== wp_json_encode( $clean ),
					),
				)
			);

			if ( $inserted ) {
				$code = array(
					'invalid'  => 'invalid_value',
					'existing' => 'existing_value_preserved',
					'conflict' => 'source_conflict',
				)[ $status ] ?? '';
				if ( '' !== $code ) {
					$this->add_detail( $job, $code, $reference, $key );
				}
			}
		}
	}

	/**
	 * Stages one normalized redirect with ownership and identity checks.
	 *
	 * @param array<string,mixed>       $job     Active job.
	 * @param ERankly_Migration_Adapter $adapter Source adapter.
	 * @param array<string,mixed>       $row     Normalized redirect row.
	 * @return void
	 */
	private function stage_redirect_record( array &$job, ERankly_Migration_Adapter $adapter, array $row ): void {
		$reference = sanitize_text_field( (string) ( $row['source_reference'] ?? '' ) );
		if ( '' === $reference ) {
			$reference = 'redirect:' . substr( hash( 'sha256', (string) wp_json_encode( $row ) ), 0, 24 );
		}
		$occurrence = $this->store->occurrence_key( (string) $job['id'], 'redirect', $reference, 'rule' );
		if ( $this->store->occurrence_exists( $occurrence ) ) {
			return;
		}

		erankly_ensure_redirect_classes_available();
		if ( ! class_exists( 'ERankly_Redirects_Normalizer' ) || ! class_exists( 'ERankly_Redirects_Repository' ) ) {
			$this->store->add_event(
				array(
					'job_id'           => (string) $job['id'],
					'item_kind'        => 'redirect',
					'source_reference' => $reference,
					'target_field'     => 'rule',
					'identity'         => 'failed:' . $reference,
					'discovery_status' => 'failed',
					'apply_status'     => 'none',
				)
			);
			$this->add_warning( $job, 'redirect_engine_unavailable', 'The EasyRankly redirect engine could not be loaded.', $reference );
			return;
		}

		$row['source_plugin'] = $adapter->slug();
		$row['migration_id']  = (string) $job['id'];
		$redirect             = erankly_import_prepare_redirect( $row );
		if ( null === $redirect ) {
			if ( $this->store->add_event(
				array(
					'job_id'           => (string) $job['id'],
					'item_kind'        => 'redirect',
					'source_reference' => $reference,
					'target_field'     => 'rule',
					'identity'         => 'invalid:' . $reference,
					'discovery_status' => 'invalid',
					'apply_status'     => 'none',
				)
			) ) {
				$this->add_detail( $job, 'invalid_redirect', $reference, '' );
			}
			return;
		}

		$rule_hash  = ERankly_Redirects_Normalizer::rule_hash( $redirect );
		$value_hash = $this->manager->redirect_value_hash( $redirect );
		$first      = $this->store->first_identity( (string) $job['id'], 'redirect', $rule_hash, array( 'ready_create', 'ready_update', 'unchanged', 'conflict' ) );
		$status     = 'ready_create';
		$apply      = ! empty( $job['dry_run'] ) ? 'preview' : 'pending';
		$table      = ERankly_Redirects_Repository::get_table_name();
		$repository = erankly_table_exists( $table ) ? new ERankly_Redirects_Repository() : null;

		if ( is_array( $first ) ) {
			$status = hash_equals( (string) ( $first['value_hash'] ?? '' ), $value_hash ) ? 'duplicate' : 'conflict';
			$apply  = 'none';
		} elseif ( $repository ) {
			$existing = $repository->find_by_hash( $rule_hash );
			if ( $existing && $adapter->slug() !== (string) ( $existing['source_plugin'] ?? '' ) ) {
				$status = 'conflict';
				$apply  = 'none';
			} elseif ( $existing && $this->manager->same_redirect( $existing, $redirect ) ) {
				$status = 'unchanged';
				$apply  = 'none';
			} elseif ( $existing ) {
				$status = 'ready_update';
			}
		}

		$inserted = $this->store->add_event(
			array(
				'job_id'           => (string) $job['id'],
				'item_kind'        => 'redirect',
				'source_reference' => $reference,
				'target_field'     => 'rule',
				'identity'         => $rule_hash,
				'value_hash'       => $value_hash,
				'discovery_status' => $status,
				'apply_status'     => $apply,
				'payload'          => array(
					'rule_hash' => $rule_hash,
					'redirect'  => $redirect,
				),
			)
		);
		if ( $inserted && 'conflict' === $status ) {
			$this->add_detail( $job, 'redirect_conflict_preserved', $reference, '' );
		}
	}

	/**
	 * Applies one bounded queue page and records each result durably.
	 *
	 * @param array<string,mixed> $job Active job.
	 * @return void
	 * @throws RuntimeException When staging cleanup cannot be completed.
	 * @throws RuntimeException When an apply checkpoint cannot be persisted.
	 */
	private function process_apply_page( array &$job ): void {
		$limit = max( 10, min( 500, (int) apply_filters( 'erankly_migration_batch_size', ERANKLY_MIGRATION_BATCH_SIZE ) ) );
		$rows  = $this->store->pending( (string) $job['id'], $limit );
		if ( ! $rows ) {
			$job['stream'] = 'finish';
			return;
		}

		$repository    = null;
		$has_redirects = false;
		foreach ( $rows as $row ) {
			if ( 'redirect' === (string) ( $row['item_kind'] ?? '' ) ) {
				$has_redirects = true;
				break;
			}
		}
		if ( $has_redirects ) {
			erankly_ensure_redirect_classes_available();
			if ( class_exists( 'ERankly_Redirects_Repository' ) && class_exists( 'ERankly_Redirects_Activator' ) && ! erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
				ERankly_Redirects_Activator::activate();
			}
			if ( class_exists( 'ERankly_Redirects_Repository' ) && erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
				$repository = new ERankly_Redirects_Repository();
				$repository->begin_bulk();
			}
		}

		try {
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
				$payload = is_array( $payload ) ? $payload : array();
				$outcome = 'failed';

				if ( 'setting' === (string) ( $row['item_kind'] ?? '' ) ) {
					$outcome = $this->apply_setting_item( $job, $row, $payload );
				} elseif ( 'meta' === (string) ( $row['item_kind'] ?? '' ) ) {
					$outcome = $this->apply_meta_item( $job, $row, $payload );
				} elseif ( 'redirect' === (string) ( $row['item_kind'] ?? '' ) ) {
					$outcome = $this->apply_redirect_item( $job, $row, $payload, $repository );
				}

				if ( ! $this->store->update_apply_status( absint( $row['id'] ?? 0 ), $outcome ) ) {
					throw new RuntimeException( 'Migration write checkpoint could not be saved.' );
				}
				if ( in_array( $outcome, array( 'failed', 'preserved', 'conflict' ), true ) ) {
					$code = 'failed' === $outcome ? 'write_failed' : ( 'preserved' === $outcome ? 'existing_value_preserved_during_apply' : 'redirect_conflict_preserved_during_apply' );
					$this->add_detail( $job, $code, (string) ( $row['source_reference'] ?? '' ), (string) ( $row['target_field'] ?? '' ) );
				}
			}
		} finally {
			if ( $repository ) {
				$repository->end_bulk();
			}
		}
	}

	/**
	 * Applies one idempotent global setting under the shared settings mutex.
	 *
	 * @param array<string,mixed> $job Active job.
	 * @param array<string,mixed> $row Queue row.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return string written|preserved|failed.
	 */
	private function apply_setting_item( array $job, array $row, array $payload ): string {
		$key     = sanitize_key( (string) ( $payload['key'] ?? '' ) );
		$value   = $payload['value'] ?? null;
		$special = ! empty( $payload['special'] ) && 'global_special_meta' === $key;
		erankly_load_default_helpers();
		if ( '' === $key || ! array_key_exists( $key, erankly_default_settings() ) ) {
			return 'failed';
		}

		$stored  = erankly_get_stored_settings();
		$exists  = $special && is_multisite() ? false !== get_option( ERANKLY_SPECIAL_META_OPTION, false ) : array_key_exists( $key, $stored );
		$current = $special ? erankly_get_global_entity_meta_map( $key ) : ( $exists ? $stored[ $key ] : erankly_default_settings()[ $key ] );
		if ( $this->canonical_json( $current ) === $this->canonical_json( $value ) ) {
			$event_key = $this->journal->prepare_setting( (string) $job['id'], absint( $row['id'] ?? 0 ), $payload );
			return '' !== $event_key && $this->journal->mark_applied( $event_key ) ? 'written' : 'failed';
		}
		if ( $exists !== (bool) ( $payload['expected_exists'] ?? false ) || $this->canonical_json( $current ) !== $this->canonical_json( $payload['expected_current'] ?? null ) ) {
			return 'preserved';
		}

		$event_key = $this->journal->prepare_setting( (string) $job['id'], absint( $row['id'] ?? 0 ), $payload );
		if ( '' === $event_key ) {
			return 'failed';
		}
		if ( $special ) {
			if ( ! is_array( $value ) || erankly_update_special_meta_map( $value ) !== $value ) {
				return 'failed';
			}
		} else {
			$updated = erankly_update_plugin_settings( array( $key => $value ) );
			if ( is_wp_error( $updated ) || ! $updated ) {
				return 'failed';
			}
		}

		return $this->journal->mark_applied( $event_key ) ? 'written' : 'failed';
	}

	/**
	 * Applies an idempotent metadata item.
	 *
	 * @param array<string,mixed> $job Active job.
	 * @param array<string,mixed> $row Queue row.
	 * @param array<string,mixed> $payload Queue payload.
	 * @return string written|preserved|failed.
	 */
	private function apply_meta_item( array $job, array $row, array $payload ): string {
		$object_type = sanitize_key( (string) ( $payload['object_type'] ?? '' ) );
		$object_id   = absint( $payload['object_id'] ?? 0 );
		$key         = sanitize_key( (string) ( $payload['key'] ?? '' ) );
		$value       = $payload['value'] ?? null;

		if ( ! in_array( $object_type, array( 'post', 'term', 'user' ), true ) || $object_id < 1 || '' === $key ) {
			return 'failed';
		}
		if ( metadata_exists( $object_type, $object_id, $key ) ) {
			$current = get_metadata( $object_type, $object_id, $key, true );
			if ( wp_json_encode( $current ) !== wp_json_encode( $value ) ) {
				return 'preserved';
			}
			$event_key = $this->journal->prepare_meta( (string) $job['id'], absint( $row['id'] ?? 0 ), $payload );
			return '' !== $event_key && $this->journal->mark_applied( $event_key ) ? 'written' : 'failed';
		}

		$event_key = $this->journal->prepare_meta( (string) $job['id'], absint( $row['id'] ?? 0 ), $payload );
		if ( '' === $event_key || false === update_metadata( $object_type, $object_id, $key, $value ) ) {
			return 'failed';
		}

		return $this->journal->mark_applied( $event_key ) ? 'written' : 'failed';
	}

	/**
	 * Applies an idempotent redirect item.
	 *
	 * @param array<string,mixed>               $job        Active job.
	 * @param array<string,mixed>               $row        Queue row.
	 * @param array<string,mixed>               $payload    Queue payload.
	 * @param ERankly_Redirects_Repository|null $repository Redirect repository.
	 * @return string created|updated|conflict|failed.
	 */
	private function apply_redirect_item( array $job, array $row, array $payload, ?ERankly_Redirects_Repository $repository ): string {
		if ( ! $repository || ! is_array( $payload['redirect'] ?? null ) || empty( $payload['rule_hash'] ) ) {
			return 'failed';
		}

		$redirect = $payload['redirect'];
		$hash     = (string) $payload['rule_hash'];
		$existing = $repository->find_by_hash( $hash );
		$decision = (string) ( $row['discovery_status'] ?? '' );
		$source   = (string) $job['source'];

		if ( 'ready_create' === $decision ) {
			if ( ! $existing ) {
				$event_key = $this->journal->prepare_redirect( (string) $job['id'], absint( $row['id'] ?? 0 ), $redirect, null );
				if ( '' === $event_key ) {
					return 'failed';
				}
				$created_id = $repository->create( $redirect );
				return $created_id > 0 && $this->journal->mark_applied( $event_key, $created_id ) ? 'created' : 'failed';
			}
			if (
				(string) ( $existing['source_plugin'] ?? '' ) === $source
				&& (string) ( $existing['migration_id'] ?? '' ) === (string) $job['id']
				&& $this->manager->same_redirect( $existing, $redirect )
			) {
				return 'created';
			}
			return 'conflict';
		}

		if ( ! $existing || (string) ( $existing['source_plugin'] ?? '' ) !== $source ) {
			return 'conflict';
		}
		if ( $this->manager->same_redirect( $existing, $redirect ) && (string) ( $existing['migration_id'] ?? '' ) === (string) $job['id'] ) {
			return 'updated';
		}

		$event_key = $this->journal->prepare_redirect( (string) $job['id'], absint( $row['id'] ?? 0 ), $redirect, $existing );
		$target_id = absint( $existing['id'] ?? 0 );
		if ( '' === $event_key || ! $repository->update( $target_id, $redirect ) ) {
			return 'failed';
		}

		return $this->journal->mark_applied( $event_key, $target_id ) ? 'updated' : 'failed';
	}

	/**
	 * Finalizes and persists a successful/partial report.
	 *
	 * @param array<string,mixed> $job Active job.
	 * @return void
	 * @throws RuntimeException When staging cleanup cannot be completed.
	 */
	private function finish_successfully( array $job ): void {
		if ( ! empty( $job['final_report_ready'] ) && is_array( $job['report'] ?? null ) ) {
			$report = $job['report'];
		} else {
			$report                  = is_array( $job['report'] ?? null ) ? $job['report'] : $this->manager->new_report( (string) $job['source'], ! empty( $job['dry_run'] ), (string) $job['id'] );
			$report['counts']        = $this->store->counts( (string) $job['id'], $this->manager );
			$report['details']       = $this->clean_diagnostics( is_array( $report['details'] ?? null ) ? $report['details'] : array() );
			$report['warnings']      = $this->clean_diagnostics( is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array() );
			$report['execution']     = array(
				'resumable' => true,
				'batches'   => absint( $job['batches'] ?? 0 ),
				'worker'    => 'wp-cron',
			);
			$failed                  = (int) $report['counts']['settings_failed'] + (int) $report['counts']['fields_failed'] + (int) $report['counts']['redirects_failed'];
			$successful              = (int) $report['counts']['settings_written'] + (int) $report['counts']['fields_written'] + (int) $report['counts']['redirects_created'] + (int) $report['counts']['redirects_updated'];
			$report['status']        = $failed > 0 ? ( $successful > 0 ? 'partial' : 'failed' ) : 'complete';
			$auditor                 = new ERankly_Migration_Auditor();
			$report['evidence']      = $auditor->build( (string) $job['id'], $report, $this->store, $this->journal, $this->evidence_store );
			$verifier                = new ERankly_Migration_Live_Verifier();
			$report['html_baseline'] = $verifier->capture_baseline( $report['evidence'], (string) $job['source'] );

			$job['report']             = $report;
			$job['status']             = 'finalizing';
			$job['final_report_ready'] = true;
			$job['updated_at']         = gmdate( 'c' );
			$this->save_job( $job );
		}

		$this->manager->finish_report( $report );
		if ( ! $this->store->delete_job( (string) $job['id'] ) ) {
			throw new RuntimeException( 'Finished migration staging rows could not be removed.' );
		}
		$this->delete_active_job_if_owned( (string) $job['id'] );
		delete_option( $this->cancel_key( (string) $job['id'] ) );
		$this->active_job_cache = null;
		wp_clear_scheduled_hook( ERANKLY_MIGRATION_CRON_HOOK, array( (string) $job['id'] ) );
		$this->finalize_source_file( $job, $report );
		$this->release_export_adapter( $job );
	}

	/**
	 * Persists a cancelled report and clears its staging rows.
	 *
	 * @param array<string,mixed> $job Active job.
	 * @return void
	 * @throws RuntimeException When staging cleanup cannot be completed.
	 */
	private function finish_cancelled( array $job ): void {
		if ( ! empty( $job['final_report_ready'] ) && is_array( $job['report'] ?? null ) ) {
			$report = $job['report'];
		} else {
			$report              = is_array( $job['report'] ?? null ) ? $job['report'] : $this->manager->new_report( (string) $job['source'], ! empty( $job['dry_run'] ), (string) $job['id'] );
			$report['counts']    = $this->store->counts( (string) $job['id'], $this->manager );
			$report['status']    = 'cancelled';
			$report['warnings']  = array_slice(
				array_merge(
					is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array(),
					array(
						array(
							'code'      => 'migration_cancelled',
							'message'   => 'The administrator cancelled the migration. Source data and existing EasyRankly values were preserved.',
							'reference' => '',
						),
					)
				),
				0,
				self::DETAIL_LIMIT
			);
			$report['warnings']  = $this->clean_diagnostics( $report['warnings'] );
			$report['details']   = $this->clean_diagnostics( is_array( $report['details'] ?? null ) ? $report['details'] : array() );
			$report['execution'] = array(
				'resumable' => true,
				'batches'   => absint( $job['batches'] ?? 0 ),
				'worker'    => 'wp-cron',
			);

			$job['report']             = $report;
			$job['status']             = 'finalizing';
			$job['cancel_requested']   = true;
			$job['final_report_ready'] = true;
			$job['updated_at']         = gmdate( 'c' );
			$this->save_job( $job );
		}

		$this->manager->finish_report( $report );
		if ( ! $this->store->delete_job( (string) $job['id'] ) ) {
			throw new RuntimeException( 'Cancelled migration staging rows could not be removed.' );
		}
		$this->delete_active_job_if_owned( (string) $job['id'] );
		delete_option( $this->cancel_key( (string) $job['id'] ) );
		$this->active_job_cache = null;
		wp_clear_scheduled_hook( ERANKLY_MIGRATION_CRON_HOOK, array( (string) $job['id'] ) );
		$this->finalize_source_file( $job, $report );
		$this->release_export_adapter( $job );
	}

	/**
	 * Releases a terminal export path from the shared adapter instance.
	 *
	 * @param array<string,mixed> $job Terminal job.
	 * @return void
	 */
	private function release_export_adapter( array $job ): void {
		if ( 'official_export' !== (string) ( $job['source_mode'] ?? '' ) ) {
			return;
		}

		$adapter = $this->manager->adapter( (string) ( $job['source'] ?? '' ) );
		if ( $adapter ) {
			$adapter->use_export_file( '' );
		}
	}

	/**
	 * Deletes a managed source upload only after the terminal checkpoint is gone.
	 *
	 * @param array<string,mixed> $job    Terminal job.
	 * @param array<string,mixed> $report Persisted terminal report.
	 * @return void
	 */
	private function finalize_source_file( array $job, array $report ): void {
		if ( empty( $job['source_file_managed'] ) || ! class_exists( 'ERankly_Migration_Upload_Store' ) ) {
			return;
		}

		$path                            = (string) ( $job['source_file'] ?? '' );
		$deleted                         = '' !== $path && ERankly_Migration_Upload_Store::delete( $path );
		$report['source_file_lifecycle'] = array(
			'managed_temporary' => true,
			'retention'         => 'until_terminal_report',
			'deleted'           => $deleted,
			'deleted_at'        => $deleted ? gmdate( 'c' ) : '',
		);
		if ( ! $deleted ) {
			$report['warnings']   = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array();
			$report['warnings'][] = array(
				'code'      => 'managed_upload_cleanup_deferred',
				'message'   => 'The private source upload could not be removed immediately and will be retried by stale-file cleanup.',
				'reference' => '',
			);
			$report['warnings']   = array_slice( $report['warnings'], 0, self::DETAIL_LIMIT );
		}

		$this->manager->finish_report( $report );
	}

	/**
	 * Collects bounded adapter and variable diagnostics from the current page.
	 *
	 * @param array<string,mixed>       $job     Active job.
	 * @param ERankly_Migration_Adapter $adapter Source adapter.
	 * @return void
	 */
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
	 * @param array<string,mixed> $job       Active job.
	 * @param string              $code      Warning code.
	 * @param string              $message   Human-readable warning.
	 * @param string              $reference Source reference.
	 * @param bool                $blocking  Whether the warning blocks go-live.
	 * @return void
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

	/**
	 * Adds a unique bounded record detail to the job report.
	 *
	 * @param array<string,mixed> $job       Active job.
	 * @param string              $code      Detail code.
	 * @param string              $reference Source reference.
	 * @param string              $field     Target field.
	 * @return void
	 */
	private function add_detail( array &$job, string $code, string $reference, string $field ): void {
		$report  = is_array( $job['report'] ?? null ) ? $job['report'] : array();
		$details = is_array( $report['details'] ?? null ) ? $report['details'] : array();
		$key     = sanitize_key( $code ) . '|' . sanitize_text_field( $reference ) . '|' . sanitize_key( $field );
		foreach ( $details as $detail ) {
			if ( (string) ( $detail['_key'] ?? '' ) === $key ) {
				return;
			}
		}
		if ( count( $details ) >= self::DETAIL_LIMIT ) {
			return;
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
	 * @param array<int,array<string,mixed>> $diagnostics Stored diagnostics.
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

	/**
	 * Returns the raw active job option.
	 *
	 * @return array<string,mixed>|null
	 */
	private function raw_active_job(): ?array {
		$job = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );
		if ( is_array( $job ) && ! empty( $job['id'] ) && true === get_option( $this->cancel_key( (string) $job['id'] ), false ) ) {
			$job['cancel_requested'] = true;
		}

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/**
	 * Adds live queue counters without mutating the stored checkpoint.
	 *
	 * @param array<string,mixed> $job Active job.
	 * @return array<string,mixed>
	 */
	private function with_counts( array $job ): array {
		if ( erankly_table_exists( ERankly_Migration_Job_Store::table_name() ) ) {
			$job['counts'] = $this->store->counts( (string) $job['id'], $this->manager );
		} else {
			$job['counts'] = $this->manager->empty_counts();
		}

		return $job;
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

	/**
	 * Saves an active job as a non-autoloaded option.
	 *
	 * @param array<string,mixed> $job Active job.
	 * @return void
	 * @throws RuntimeException When the checkpoint cannot be persisted.
	 */
	private function save_job( array $job ): void {
		update_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, $job, false );
		$this->active_job_cache = null;
		$stored                 = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );
		if ( ! is_array( $stored ) || wp_json_encode( $stored ) !== wp_json_encode( $job ) ) {
			throw new RuntimeException( 'Migration job checkpoint could not be persisted.' );
		}
	}

	/**
	 * Schedules the next single-event worker invocation.
	 *
	 * @param string $job_id Migration UUID.
	 * @param int    $delay  Delay in seconds.
	 * @return bool
	 */
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
	 * @param string $job_id Migration UUID.
	 * @return string Lock token or an empty string.
	 */
	private function acquire_lock( string $job_id ): string {
		global $wpdb;

		$key     = $this->lock_key( $job_id );
		$token   = wp_generate_uuid4();
		$value   = array(
			'token'   => $token,
			'created' => time(),
		);
		$created = add_option(
			$key,
			$value,
			'',
			'no'
		);
		if ( $created ) {
			return $token;
		}

		$lock = get_option( $key, array() );
		if ( ! is_array( $lock ) || (int) ( $lock['created'] ?? 0 ) >= time() - self::LOCK_TTL ) {
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
		wp_cache_delete( $key, 'options' );

		return 1 === $updated ? $token : '';
	}

	/**
	 * Releases a lock only when the caller still owns it.
	 *
	 * @param string $job_id Migration UUID.
	 * @param string $token  Lock ownership token.
	 * @return void
	 */
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
	 * @param string $job_id Migration UUID.
	 * @return void
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

	/**
	 * Returns the bounded option name for one job lock.
	 *
	 * @param string $job_id Migration UUID.
	 * @return string
	 */
	private function lock_key( string $job_id ): string {
		return 'erankly_migration_lock_' . substr( hash( 'sha256', $job_id ), 0, 24 );
	}

	/**
	 * Returns the bounded option name for one durable cancellation request.
	 *
	 * @param string $job_id Migration UUID.
	 * @return string
	 */
	private function cancel_key( string $job_id ): string {
		return 'erankly_migration_cancel_' . substr( hash( 'sha256', $job_id ), 0, 24 );
	}
}
