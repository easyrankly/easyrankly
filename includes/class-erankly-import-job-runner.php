<?php
/**
 * Crash-safe, batched EasyRankly JSON import worker.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Processes complete EasyRankly backups without one long/N+1 request. */
final class ERankly_Import_Job_Runner {
	private const FILE_PREFIX = 'erankly-import-';
	private const LOCK_PREFIX = 'erankly_import_lock_';
	private const STAGES      = array( 'settings', 'redirects', 'user_meta', 'post_meta', 'term_meta' );

	/**
	 * Stores a validated PHP upload in private storage and creates a checkpoint.
	 *
	 * @param array<string,mixed> $file     Normalized $_FILES entry.
	 * @param array<string,mixed> $data     Already validated decoded payload.
	 * @param int                 $maximum  Maximum accepted bytes.
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
		$path = (string) $stored['path'];

		$job_id = wp_generate_uuid4();
		$job    = array(
			'id'         => $job_id,
			'status'     => 'queued',
			'path'       => wp_normalize_path( $path ),
			'sha256'     => hash_file( 'sha256', $path ),
			'stage'      => 'settings',
			'offset'     => 0,
			'batches'    => 0,
			'processed'  => 0,
			'started_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'counts'     => self::empty_counts(),
			'totals'     => array(
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

	/** Returns and self-heals scheduling for the current import checkpoint. */
	public static function active_job(): ?array {
		$job = get_option( defined( 'ERANKLY_IMPORT_ACTIVE_JOB_OPTION' ) ? ERANKLY_IMPORT_ACTIVE_JOB_OPTION : 'erankly_import_active_job_v1', null );
		if ( is_array( $job ) && ! empty( $job['id'] ) ) {
			self::schedule( (string) $job['id'] );
			return $job;
		}

		return null;
	}

	/**
	 * Applies one bounded page from an in-memory payload for compatibility APIs.
	 *
	 * @param array<string,mixed> $data       Decoded EasyRankly payload.
	 * @param array<string,mixed> $checkpoint Optional stage/offset/counts checkpoint.
	 * @return array<string,mixed>
	 */
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
		self::apply_next_batch( $data, $job );

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
	 * Processes one data batch and writes the next durable stage/offset.
	 *
	 * @param string $job_id Import UUID.
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
			$path = (string) ( $job['path'] ?? '' );
			if ( ! self::owns( $path ) || ! is_file( $path ) || ! hash_equals( (string) ( $job['sha256'] ?? '' ), (string) hash_file( 'sha256', $path ) ) ) {
				throw new RuntimeException( 'The private import source changed or disappeared.' );
			}
			$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verified bounded private import file.
			$data = is_string( $json ) ? json_decode( $json, true, defined( 'ERANKLY_IMPORT_JSON_MAX_DEPTH' ) ? ERANKLY_IMPORT_JSON_MAX_DEPTH : 64 ) : null;
			unset( $json );
			if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() || 'erankly' !== (string) ( $data['plugin'] ?? '' ) ) {
				throw new RuntimeException( 'The private import source is no longer valid JSON.' );
			}

			$job['status'] = 'running';
			self::apply_next_batch( $data, $job );
			unset( $data );
			++$job['batches'];
			$job['updated_at'] = gmdate( 'c' );

			if ( 'complete' === (string) ( $job['stage'] ?? '' ) ) {
				self::finish( $job, 'complete' );
				return null;
			}
			update_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, $job, false );
			self::schedule( $job_id );
			return $job;
		} catch ( Throwable $error ) {
			$job['error']      = sanitize_text_field( get_class( $error ) );
			$job['updated_at'] = gmdate( 'c' );
			self::finish( $job, 'failed' );
			return null;
		} finally {
			self::release_lock( $job_id, $token );
		}
	}

	/**
	 * Applies one bounded stage page, skipping empty terminal stages.
	 *
	 * @param array<string,mixed> $data Decoded import data.
	 * @param array<string,mixed> $job  Mutable cursor and counters.
	 */
	private static function apply_next_batch( array $data, array &$job ): void {
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

	/**
	 * Applies settings once, loading the canonical sanitizer in cron context.
	 *
	 * @param array<string,mixed> $data   Decoded import data.
	 * @param array<string,int>   $counts Cumulative counters.
	 */
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
	}

	/**
	 * Applies a bounded redirect page with one cache invalidation.
	 *
	 * @param array<int,mixed>  $records Redirect rows.
	 * @param array<string,int> $counts  Cumulative counters.
	 */
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

	/**
	 * Applies metadata after resolving every object ID in one grouped query.
	 *
	 * @param string            $stage   user_meta|post_meta|term_meta.
	 * @param array<int,mixed>  $records Metadata rows.
	 * @param array<string,int> $counts  Cumulative counters.
	 */
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
			$value = erankly_sanitize_registered_meta( $entry['value'] ?? '', $key );
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

	/**
	 * Advances to the next declared stream and resets its offset.
	 *
	 * @param array<string,mixed> $job Mutable checkpoint.
	 */
	private static function advance_stage( array &$job ): void {
		$index         = array_search( (string) $job['stage'], self::STAGES, true );
		$job['stage']  = false === $index || $index >= count( self::STAGES ) - 1 ? 'complete' : self::STAGES[ $index + 1 ];
		$job['offset'] = 0;
	}

	/**
	 * Finalizes evidence, removes the private file and clears the active job.
	 *
	 * @param array<string,mixed> $job    Terminal job.
	 * @param string              $status complete|failed.
	 */
	private static function finish( array $job, string $status ): void {
		$job['status']       = $status;
		$job['completed_at'] = gmdate( 'c' );
		self::delete_file( (string) ( $job['path'] ?? '' ) );
		unset( $job['path'], $job['sha256'] );
		update_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, $job, false );
		delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
		wp_clear_scheduled_hook( ERANKLY_IMPORT_CRON_HOOK, array( (string) $job['id'] ) );
	}

	/** Returns zeroed cumulative import counters. */
	private static function empty_counts(): array {
		return array(
			'settings'  => 0,
			'redirects' => 0,
			'post_meta' => 0,
			'term_meta' => 0,
			'user_meta' => 0,
		);
	}

	/**
	 * Schedules the next import page idempotently.
	 *
	 * @param string $job_id Import UUID.
	 * @param int    $delay  Delay in seconds.
	 */
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

	/**
	 * Acquires a recoverable per-job lock.
	 *
	 * @param string $job_id Import UUID.
	 */
	private static function acquire_lock( string $job_id ): string {
		$key   = self::lock_key( $job_id );
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'   => $token,
			'expires' => time() + 300,
		);
		if ( add_option( $key, $lock, '', 'no' ) ) {
			return $token;
		}
		$existing = get_option( $key, array() );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) < time() ) {
			delete_option( $key );
			return add_option( $key, $lock, '', 'no' ) ? $token : '';
		}

		return '';
	}

	/**
	 * Releases only the current request's lock token.
	 *
	 * @param string $job_id Import UUID.
	 * @param string $token  Owned lock token.
	 */
	private static function release_lock( string $job_id, string $token ): void {
		$key      = self::lock_key( $job_id );
		$existing = get_option( $key, array() );
		if ( is_array( $existing ) && hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			delete_option( $key );
		}
	}

	/**
	 * Returns the bounded lock option key.
	 *
	 * @param string $job_id Import UUID.
	 */
	private static function lock_key( string $job_id ): string {
		return self::LOCK_PREFIX . substr( hash( 'sha256', $job_id ), 0, 24 );
	}

	/**
	 * Verifies that a path is one of this site's managed import files.
	 *
	 * @param string $path Candidate path.
	 */
	private static function owns( string $path ): bool {
		$directory = ERankly_Migration_Upload_Store::directory( false );
		$path      = wp_normalize_path( $path );

		return '' !== $directory
			&& hash_equals( wp_normalize_path( $directory ), wp_normalize_path( dirname( $path ) ) )
			&& 1 === preg_match( '/^' . self::FILE_PREFIX . '[a-f0-9]{32}\.json$/', basename( $path ) );
	}

	/**
	 * Deletes only a verified managed import file.
	 *
	 * @param string $path Candidate managed path.
	 */
	private static function delete_file( string $path ): bool {
		return self::owns( $path ) && ( ! file_exists( $path ) || ( is_file( $path ) && ! is_link( $path ) && unlink( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Guarded private OS-temp file.
	}

	/** Removes abandoned managed import files during reset/uninstall. */
	public static function purge_all(): bool {
		$directory = ERankly_Migration_Upload_Store::directory( false );
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return true;
		}
		$success = true;
		$paths   = glob( $directory . '/' . self::FILE_PREFIX . '*.json' );
		foreach ( is_array( $paths ) ? $paths : array() as $path ) {
			$success = self::delete_file( (string) $path ) && $success;
		}

		return $success;
	}
}
