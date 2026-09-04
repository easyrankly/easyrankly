<?php
/** Crash-safe, batched EasyRankly JSON import worker. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Processes complete EasyRankly backups without one long/N+1 request. */
final class ERankly_Import_Job_Runner {
	private const FILE_PREFIX   = 'erankly-import-';
	private const LOCK_PREFIX   = 'erankly_import_lock_';
	private const LOCK_TTL      = 300;
	private const SPOOL_VERSION = 1;
	private const STAGES        = array( 'settings', 'redirects', 'user_meta', 'post_meta', 'term_meta' );

	/**
 * @param array<string,mixed> $data     Already validated decoded payload.
 * @return array<string,mixed>
 */
	public static function start( array $file, array $data, int $maximum ): array {
		$active = self::active_job();
		if ( is_array( $active ) ) {
			return array(
				'ok'    => false,
				'error' => 'import_already_running',
				'job'   => $active,
			);
		}
		if ( 'erankly' !== (string) ( $data['plugin'] ?? '' ) ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_upload',
			);
		}

		$stored = ERankly_Migration_Upload_Store::store_import_http_upload( $file, $maximum );
		if ( empty( $stored['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => sanitize_key( (string) ( $stored['error'] ?? 'private_storage_write_failed' ) ),
			);
		}
		$source_path = (string) $stored['path'];
		$spool       = self::stage_spool( $source_path, $data );
		if ( empty( $spool['ok'] ) ) {
			self::delete_file( $source_path );
			return array(
				'ok'    => false,
				'error' => 'private_storage_write_failed',
			);
		}
		$path = (string) $spool['path'];

		$job_id = wp_generate_uuid4();
		$job    = array(
			'id'            => $job_id,
			'status'        => 'queued',
			'path'          => wp_normalize_path( $path ),
			'spool_size'    => (int) $spool['size'],
			'spool_mtime'   => (int) $spool['mtime'],
			'stage_offsets' => $spool['stage_offsets'],
			'stage_ends'    => $spool['stage_ends'],
			'stage'         => 'settings',
			'offset'        => 0,
			'byte'          => 0,
			'batches'       => 0,
			'processed'     => 0,
			'started_at'    => gmdate( 'c' ),
			'updated_at'    => gmdate( 'c' ),
			'counts'        => self::empty_counts(),
			'totals'        => array(
				'redirects' => count( is_array( $data['redirects'] ?? null ) ? $data['redirects'] : array() ),
				'user_meta' => count( is_array( $data['user_meta'] ?? null ) ? $data['user_meta'] : array() ),
				'post_meta' => count( is_array( $data['post_meta'] ?? null ) ? $data['post_meta'] : array() ),
				'term_meta' => count( is_array( $data['term_meta'] ?? null ) ? $data['term_meta'] : array() ),
			),
		);
		if ( ! add_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, $job, '', 'no' ) ) {
			self::delete_file( $path );
			return array(
				'ok'    => false,
				'error' => 'checkpoint_unavailable',
			);
		}
		delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );
		self::schedule( $job_id );

		return array(
			'ok'  => true,
			'job' => $job,
		);
	}

	public static function active_job(): ?array {
		$job = get_option( defined( 'ERANKLY_IMPORT_ACTIVE_JOB_OPTION' ) ? ERANKLY_IMPORT_ACTIVE_JOB_OPTION : 'erankly_import_active_job_v1', null );
		if ( is_array( $job ) && ! empty( $job['id'] ) ) {
			self::schedule( (string) $job['id'] );
			return $job;
		}

		return null;
	}

	/** @return array<string,mixed> */
	public static function apply_payload_batch( array $data, array $checkpoint = array() ): array {
		$job = array(
			'stage'  => sanitize_key( (string) ( $checkpoint['stage'] ?? 'settings' ) ),
			'offset' => absint( $checkpoint['offset'] ?? 0 ),
			'counts' => array_merge( self::empty_counts(), is_array( $checkpoint['counts'] ?? null ) ? $checkpoint['counts'] : array() ),
		);
		if ( ! in_array( $job['stage'], array_merge( self::STAGES, array( 'complete' ) ), true ) ) {
			$job['stage']  = 'settings';
			$job['offset'] = 0;
		}
		self::apply_next_payload_batch( $data, $job );

		return array_merge(
			$job['counts'],
			array(
				'cursor' => array(
					'stage'  => $job['stage'],
					'offset' => $job['offset'],
					'counts' => $job['counts'],
				),
				'done'   => 'complete' === $job['stage'],
			)
		);
	}

	/**
 * @return array<string,mixed>|null
 * @throws RuntimeException When the private source cannot be verified or decoded.
 */
	public static function process( string $job_id ): ?array {
		$job = get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, null );
		if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['id'] ?? '' ), $job_id ) ) {
			return null;
		}
		$token = self::acquire_lock( $job_id );
		if ( '' === $token ) {
			self::schedule( $job_id, 10 );
			return $job;
		}

		try {
			$path  = (string) ( $job['path'] ?? '' );
			$size  = is_file( $path ) ? filesize( $path ) : false;
			$mtime = is_file( $path ) ? filemtime( $path ) : false;
			if ( ! self::owns( $path ) || is_link( $path ) || false === $size || false === $mtime || (int) ( $job['spool_size'] ?? -1 ) !== $size || (int) ( $job['spool_mtime'] ?? -1 ) !== $mtime ) {
				throw new RuntimeException( 'The private import source changed or disappeared.' );
			}

			if ( ! self::renew_lock( $job_id, $token ) ) {
				throw new RuntimeException( 'The import worker lease was lost before applying the batch.' );
			}

			$job['status'] = 'running';
			self::apply_next_spool_batch( $path, $job );
			if ( ! self::renew_lock( $job_id, $token ) ) {
				throw new RuntimeException( 'The import worker lease was lost before saving its checkpoint.' );
			}
			++$job['batches'];
			$job['updated_at'] = gmdate( 'c' );

			if ( 'complete' === (string) ( $job['stage'] ?? '' ) ) {
				self::finish( $job, 'complete' );
				return null;
			}
			if ( ! update_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, $job, false ) ) {
				throw new RuntimeException( 'The import checkpoint could not be saved.' );
			}
			self::schedule( $job_id );
			return $job;
		} catch ( Throwable $error ) {
			// A worker that lost its lease must never overwrite or delete the
			// checkpoint now owned by its successor. The next cron run resumes it.
			if ( ! self::owns_lock( $job_id, $token ) ) {
				self::schedule( $job_id, 10 );
				$current = get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, null );
				return is_array( $current ) ? $current : null;
			}
			$job['error']      = sanitize_text_field( get_class( $error ) );
			$job['updated_at'] = gmdate( 'c' );
			self::finish( $job, 'failed' );
			return null;
		} finally {
			self::release_lock( $job_id, $token );
		}
	}

	/**
 * @param string              $path Managed destination replacing the upload.
 * @return array<string,mixed>
 */
	private static function stage_spool( string $path, array $data ): array {
		$spool_path = $path . '.spool';
		$handle     = fopen( $spool_path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive private spool creation prevents partial readers.
		if ( false === $handle ) {
			return array( 'ok' => false );
		}

		$payload = $data;
		foreach ( array_slice( self::STAGES, 1 ) as $stream ) {
			unset( $payload[ $stream ] );
		}
		$stage_offsets = array( 'settings' => 0 );
		$stage_ends    = array();

		try {
			self::write_spool_line(
				$handle,
				array(
					'erankly_import_spool' => self::SPOOL_VERSION,
					'payload'              => $payload,
				)
			);
			$stage_ends['settings'] = (int) ftell( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Durable private byte checkpoint.
			foreach ( array_slice( self::STAGES, 1 ) as $stream ) {
				$stage_offsets[ $stream ] = (int) ftell( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Durable private byte checkpoint.
				$records                  = is_array( $data[ $stream ] ?? null ) ? $data[ $stream ] : array();
				foreach ( $records as $record ) {
					self::write_spool_line( $handle, array( 'record' => $record ) );
				}
				$stage_ends[ $stream ] = (int) ftell( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Durable private byte checkpoint.
			}
		} catch ( Throwable ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired private spool handle.
			self::delete_file( $spool_path );
			return array( 'ok' => false );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired private spool handle.
		unset( $payload );
		if ( ! chmod( $spool_path, 0600 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private spool permissions must not depend on the process umask.
			self::delete_file( $spool_path );
			return array( 'ok' => false );
		}
		if ( ! self::delete_file( $path ) ) {
			self::delete_file( $spool_path );
			return array( 'ok' => false );
		}
		clearstatcache( true, $spool_path );
		$size  = filesize( $spool_path );
		$mtime = filemtime( $spool_path );

		return false === $size || false === $mtime
			? array( 'ok' => false )
			: array(
				'ok'            => true,
				'path'          => $spool_path,
				'size'          => (int) $size,
				'mtime'         => (int) $mtime,
				'stage_offsets' => $stage_offsets,
				'stage_ends'    => $stage_ends,
			);
	}

	/**
 * @param resource $handle Open private spool handle.
 * @throws RuntimeException When JSON encoding or the complete write fails.
 */
	private static function write_spool_line( $handle, mixed $value ): void {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Unable to encode a private import spool row.' );
		}
		$bytes  = $json . "\n";
		$length = strlen( $bytes );
		$offset = 0;
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $bytes, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Complete private staging write.
			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( 'Unable to write a private import spool row.' );
			}
			$offset += $written;
		}
	}

	/** @throws RuntimeException When the spool marker, cursor or row is invalid. */
	private static function apply_next_spool_batch( string $path, array &$job ): void {
		$offsets = is_array( $job['stage_offsets'] ?? null ) ? $job['stage_offsets'] : array();
		$ends    = is_array( $job['stage_ends'] ?? null ) ? $job['stage_ends'] : array();
		$limit   = max( 10, min( 500, (int) apply_filters( 'erankly_import_batch_size', defined( 'ERANKLY_IMPORT_BATCH_SIZE' ) ? ERANKLY_IMPORT_BATCH_SIZE : 100 ) ) );

		while ( 'complete' !== (string) $job['stage'] ) {
			$stage = sanitize_key( (string) $job['stage'] );
			if ( ! isset( $offsets[ $stage ], $ends[ $stage ] ) || (int) $ends[ $stage ] < (int) $offsets[ $stage ] ) {
				throw new RuntimeException( 'The private import spool checkpoint is invalid.' );
			}

			$file = new SplFileObject( $path, 'rb' );
			if ( 'settings' === $stage ) {
				$file->fseek( (int) $offsets[ $stage ] );
				$header = json_decode( trim( (string) $file->fgets() ), true );
				$data   = is_array( $header ) && self::SPOOL_VERSION === (int) ( $header['erankly_import_spool'] ?? 0 ) && is_array( $header['payload'] ?? null )
					? $header['payload']
					: null;
				if ( ! is_array( $data ) || 'erankly' !== (string) ( $data['plugin'] ?? '' ) ) {
					throw new RuntimeException( 'The private import spool marker is invalid.' );
				}
				self::apply_settings( $data, $job['counts'] );
				self::advance_stage( $job );
				continue;
			}

			$byte = max( (int) $offsets[ $stage ], absint( $job['byte'] ?? 0 ) );
			$end  = (int) $ends[ $stage ];
			if ( $byte >= $end ) {
				self::advance_stage( $job );
				continue;
			}
			$file->fseek( $byte );
			$records      = array();
			$record_count = 0;
			while ( $record_count < $limit && (int) $file->ftell() < $end ) {
				$line = $file->fgets();
				if ( false === $line ) {
					throw new RuntimeException( 'The private import spool ended before its checkpoint.' );
				}
				$envelope = json_decode( trim( $line ), true );
				if ( ! is_array( $envelope ) || ! array_key_exists( 'record', $envelope ) ) {
					throw new RuntimeException( 'A private import spool row is invalid.' );
				}
				$records[] = $envelope['record'];
				++$record_count;
			}

			if ( 'redirects' === $stage ) {
				self::apply_redirects( $records, $job['counts'] );
			} else {
				self::apply_meta( $stage, $records, $job['counts'] );
			}
			$job['offset']    = absint( $job['offset'] ?? 0 ) + count( $records );
			$job['processed'] = absint( $job['processed'] ?? 0 ) + count( $records );
			$job['byte']      = (int) $file->ftell();
			if ( $job['byte'] >= $end ) {
				self::advance_stage( $job );
			}
			return;
		}
	}

	/**
 * Applies one bounded stage page, skipping empty terminal stages.
 *
 * @param array<string,mixed> $job  Mutable cursor and counters.
 */
	private static function apply_next_payload_batch( array $data, array &$job ): void {
		$limit = max( 10, min( 500, (int) apply_filters( 'erankly_import_batch_size', defined( 'ERANKLY_IMPORT_BATCH_SIZE' ) ? ERANKLY_IMPORT_BATCH_SIZE : 100 ) ) );
		while ( 'complete' !== (string) $job['stage'] ) {
			$stage  = (string) $job['stage'];
			$offset = absint( $job['offset'] ?? 0 );
			if ( 'settings' === $stage ) {
				self::apply_settings( $data, $job['counts'] );
				self::advance_stage( $job );
				continue;
			}

			$records = is_array( $data[ $stage ] ?? null ) ? $data[ $stage ] : array();
			if ( $offset >= count( $records ) ) {
				self::advance_stage( $job );
				continue;
			}
			$batch = array_slice( $records, $offset, $limit );
			if ( 'redirects' === $stage ) {
				self::apply_redirects( $batch, $job['counts'] );
			} else {
				self::apply_meta( $stage, $batch, $job['counts'] );
			}
			$job['offset']    = $offset + count( $batch );
			$job['processed'] = absint( $job['processed'] ?? 0 ) + count( $batch );
			if ( $job['offset'] >= count( $records ) ) {
				self::advance_stage( $job );
			}
			return;
		}
	}

	/** Applies settings once, loading the canonical sanitizer in cron context. */
	private static function apply_settings( array $data, array &$counts ): void {
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			if ( ! function_exists( 'erankly_sanitize_settings' ) ) {
				require_once ERANKLY_PATH . 'includes/admin.php';
				erankly_admin_load_settings_modules();
			}
			$clean = erankly_sanitize_settings( $data['settings'] );
			erankly_update_plugin_option( ERANKLY_OPTION, $clean );
			$counts['settings'] = 1;
		}
		if ( isset( $data['special_meta'] ) && is_array( $data['special_meta'] ) ) {
			erankly_update_special_meta_map( $data['special_meta'] );
			$counts['settings'] = 1;
		}

		/** Fires after a native EasyRankly export has restored core settings. Add-ons may read extra payload keys they own. */
		do_action( 'erankly_imported_payload', $data );
	}

	private static function apply_redirects( array $records, array &$counts ): void {
		erankly_ensure_redirect_classes_available();
		if ( ! class_exists( 'ERankly_Redirects_Repository' ) || ! class_exists( 'ERankly_Redirects_Normalizer' ) ) {
			return;
		}
		if ( class_exists( 'ERankly_Redirects_Activator' ) ) {
			ERankly_Redirects_Activator::activate();
		}
		$repository = new ERankly_Redirects_Repository();
		$repository->begin_bulk();
		try {
			foreach ( $records as $row ) {
				$redirect = is_array( $row ) ? erankly_import_prepare_redirect( $row ) : null;
				if ( null !== $redirect && in_array( $repository->upsert_by_hash( $redirect ), array( 'created', 'updated' ), true ) ) {
					++$counts['redirects'];
				}
			}
		} finally {
			$repository->end_bulk();
		}
	}

	/** Applies metadata after resolving every object ID in one grouped query. */
	private static function apply_meta( string $stage, array $records, array &$counts ): void {
		global $wpdb;

		$definitions = array(
			'user_meta' => array( 'user', $wpdb->users, 'ID' ),
			'post_meta' => array( 'post', $wpdb->posts, 'ID' ),
			'term_meta' => array( 'term', $wpdb->terms, 'term_id' ),
		);
		if ( ! isset( $definitions[ $stage ] ) ) {
			return;
		}
		list( $object_type, $table, $id_column ) = $definitions[ $stage ];
		$ids                                     = array_values( array_unique( array_filter( array_map( static fn( $entry ): int => is_array( $entry ) ? absint( $entry['id'] ?? 0 ) : 0, $records ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$existing_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped identity resolution per bounded import batch.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Three identifier replacements precede the dynamic bounded integer list.
				"SELECT %i FROM %i WHERE %i IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only the generated integer placeholder list is interpolated.
				array_merge( array( $id_column, $table, $id_column ), $ids )
			)
		);
		$existing     = array_fill_keys( array_map( 'absint', is_array( $existing_ids ) ? $existing_ids : array() ), true );
		$allowed      = erankly_get_meta_keys();
		foreach ( $records as $entry ) {
			$id  = is_array( $entry ) ? absint( $entry['id'] ?? 0 ) : 0;
			$key = is_array( $entry ) ? (string) ( $entry['key'] ?? '' ) : '';
			if ( ! isset( $existing[ $id ], $allowed[ $key ] ) ) {
				continue;
			}
			$value   = erankly_sanitize_registered_meta( $entry['value'] ?? '', $key );
			$updated = match ( $object_type ) {
				'post' => update_post_meta( $id, $key, $value ),
				'user' => update_user_meta( $id, $key, $value ),
				'term' => update_term_meta( $id, $key, $value ),
				default => update_metadata( $object_type, $id, $key, $value ),
			};
			if ( false !== $updated ) {
				++$counts[ $stage ];
			}
		}
	}

	private static function advance_stage( array &$job ): void {
		$index         = array_search( (string) $job['stage'], self::STAGES, true );
		$job['stage']  = false === $index || $index >= count( self::STAGES ) - 1 ? 'complete' : self::STAGES[ $index + 1 ];
		$job['offset'] = 0;
		if ( 'complete' !== $job['stage'] && is_array( $job['stage_offsets'] ?? null ) ) {
			$job['byte'] = absint( $job['stage_offsets'][ $job['stage'] ] ?? 0 );
		}
	}

	/** Finalizes evidence, removes the private file and clears the active job. */
	private static function finish( array $job, string $status ): void {
		$job['status']       = $status;
		$job['completed_at'] = gmdate( 'c' );
		self::delete_file( (string) ( $job['path'] ?? '' ) );
		unset( $job['path'], $job['spool_size'], $job['spool_mtime'], $job['stage_offsets'], $job['stage_ends'], $job['byte'] );
		update_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, $job, false );
		delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
		wp_clear_scheduled_hook( ERANKLY_IMPORT_CRON_HOOK, array( (string) $job['id'] ) );
	}

	private static function empty_counts(): array {
		return array(
			'settings'  => 0,
			'redirects' => 0,
			'post_meta' => 0,
			'term_meta' => 0,
			'user_meta' => 0,
		);
	}

	private static function schedule( string $job_id, int $delay = 1 ): void {
		if ( ! defined( 'ERANKLY_IMPORT_CRON_HOOK' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		$args = array( $job_id );
		if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( ERANKLY_IMPORT_CRON_HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), ERANKLY_IMPORT_CRON_HOOK, $args, true );
	}

	/** Acquires a recoverable per-job lock. */
	private static function acquire_lock( string $job_id ): string {
		global $wpdb;

		$key   = self::lock_key( $job_id );
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'   => $token,
			'expires' => time() + self::LOCK_TTL,
		);
		if ( add_option( $key, $lock, '', 'no' ) ) {
			return $token;
		}
		$existing = get_option( $key, array() );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) < time() ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap prevents two workers taking the same stale lease.
				$wpdb->prepare(
					'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
					$wpdb->options,
					maybe_serialize( $lock ),
					$key,
					maybe_serialize( $existing )
				)
			);
			if ( 1 === $updated ) {
				wp_cache_delete( $key, 'options' );
				return $token;
			}
		}

		return '';
	}

	/** Releases only the current request's lock token. */
	private static function release_lock( string $job_id, string $token ): void {
		global $wpdb;

		$key      = self::lock_key( $job_id );
		$existing = get_option( $key, array() );
		if ( ! is_array( $existing ) || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			return;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete cannot release a successor's lease.
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$key,
				maybe_serialize( $existing )
			)
		);
		wp_cache_delete( $key, 'options' );
	}

	/** Renews a lease only while its token still owns the stored lock. */
	private static function renew_lock( string $job_id, string $token ): bool {
		global $wpdb;

		$key      = self::lock_key( $job_id );
		$existing = get_option( $key, array() );
		if ( ! is_array( $existing ) || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			return false;
		}
		$renewed            = $existing;
		$renewed['expires'] = max( time() + self::LOCK_TTL, (int) ( $existing['expires'] ?? 0 ) + 1 );
		$updated            = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lease renewal fences stale workers.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $renewed ),
				$key,
				maybe_serialize( $existing )
			)
		);
		if ( 1 === $updated ) {
			wp_cache_delete( $key, 'options' );
		}

		return 1 === $updated;
	}

	private static function owns_lock( string $job_id, string $token ): bool {
		$existing = get_option( self::lock_key( $job_id ), array() );

		return is_array( $existing )
			&& (int) ( $existing['expires'] ?? 0 ) >= time()
			&& hash_equals( (string) ( $existing['token'] ?? '' ), $token );
	}

	/** Returns the bounded lock option key. */
	private static function lock_key( string $job_id ): string {
		return self::LOCK_PREFIX . substr( hash( 'sha256', $job_id ), 0, 24 );
	}

	/** Verifies that a path is one of this site's managed import files. */
	private static function owns( string $path ): bool {
		$directory = ERankly_Migration_Upload_Store::directory( false );
		$path      = wp_normalize_path( $path );

		return '' !== $directory
			&& hash_equals( wp_normalize_path( $directory ), wp_normalize_path( dirname( $path ) ) )
			&& 1 === preg_match( '/^' . self::FILE_PREFIX . '[a-f0-9]{32}\.json(?:\.spool)?$/', basename( $path ) );
	}

	/** Deletes only a verified managed import file. */
	private static function delete_file( string $path ): bool {
		return self::owns( $path ) && ( ! file_exists( $path ) || ( is_file( $path ) && ! is_link( $path ) && unlink( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Guarded private OS-temp file.
	}

	public static function purge_all(): bool {
		$directory = ERankly_Migration_Upload_Store::directory( false );
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return true;
		}
		$success = true;
		$paths   = glob( $directory . '/' . self::FILE_PREFIX . '*' );
		foreach ( is_array( $paths ) ? $paths : array() as $path ) {
			$success = self::delete_file( (string) $path ) && $success;
		}

		return $success;
	}
}
