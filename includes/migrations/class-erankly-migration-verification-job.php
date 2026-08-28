<?php
/**
 * Crash-safe background orchestration for live migration verification.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists the live-verification cursor and advances it through WP-Cron. */
final class ERankly_Migration_Verification_Job {
	private const JOB_PREFIX  = 'erankly_migration_verify_job_';
	private const LOCK_PREFIX = 'erankly_migration_verify_lock_';
	private const LOCK_TTL    = 120;

	/**
	 * Queues one report idempotently without making network requests in admin.
	 *
	 * @param string $report_id Migration report UUID.
	 */
	public static function queue( string $report_id ): bool {
		$report_id = sanitize_text_field( $report_id );
		$manager   = erankly_migration_manager();
		$report    = $manager->get_report( $report_id );
		if ( '' === $report_id || ! is_array( $report ) || 'import' !== (string) ( $report['mode'] ?? '' ) ) {
			return false;
		}

		$key      = self::job_key( $report_id );
		$existing = get_option( $key, null );
		if ( is_array( $existing ) && hash_equals( (string) ( $existing['report_id'] ?? '' ), $report_id ) ) {
			self::schedule( $report_id );
			return true;
		}

		$job = array(
			'report_id'  => $report_id,
			'status'     => 'queued',
			'checkpoint' => array(),
			'batches'    => 0,
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
		if ( ! add_option( $key, $job, '', 'no' ) ) {
			// Another request may have won the atomic create after our initial
			// read. Treat that exact report job as the same idempotent queueing
			// operation instead of surfacing a false failure to the administrator.
			$existing = get_option( $key, null );
			if ( is_array( $existing ) && hash_equals( (string) ( $existing['report_id'] ?? '' ), $report_id ) ) {
				self::schedule( $report_id );
				return true;
			}
			return false;
		}

		$report['live_verification'] = array(
			'state'      => 'queued',
			'queued_at'  => gmdate( 'c' ),
			'report_id'  => $report_id,
			'background' => true,
		);
		if ( ! $manager->update_report( $report ) ) {
			delete_option( $key );
			return false;
		}
		self::schedule( $report_id );

		return true;
	}

	/**
	 * Advances one bounded verification batch and persists its next cursor.
	 *
	 * @param string $report_id Migration report UUID.
	 * @throws RuntimeException Internally when a durable checkpoint cannot be saved.
	 */
	public static function process( string $report_id ): void {
		$report_id = sanitize_text_field( $report_id );
		$key       = self::job_key( $report_id );
		$job       = get_option( $key, null );
		if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['report_id'] ?? '' ), $report_id ) ) {
			return;
		}

		$token = self::acquire_lock( $report_id );
		if ( '' === $token ) {
			self::schedule( $report_id, 10 );
			return;
		}

		try {
			$manager = erankly_migration_manager();
			$report  = $manager->get_report( $report_id );
			if ( ! is_array( $report ) || 'import' !== (string) ( $report['mode'] ?? '' ) ) {
				delete_option( $key );
				return;
			}

			$verifier   = new ERankly_Migration_Live_Verifier();
			$checkpoint = is_array( $job['checkpoint'] ?? null ) ? $job['checkpoint'] : array();
			$batch      = $verifier->verify_batch( $report, $checkpoint );
			if ( ! self::renew_lock( $report_id, $token ) ) {
				self::schedule( $report_id, 10 );
				return;
			}

			if ( ! empty( $batch['done'] ) ) {
				$report['live_verification'] = is_array( $batch['result'] ?? null ) ? $batch['result'] : self::failed_result();
				if ( ! $manager->update_report( $report ) ) {
					throw new RuntimeException( 'The final live-verification report could not be persisted.' );
				}
				delete_option( $key );
				wp_clear_scheduled_hook( ERANKLY_MIGRATION_VERIFY_CRON_HOOK, array( $report_id ) );
				return;
			}

			$job['status']     = 'running';
			$job['checkpoint'] = is_array( $batch['checkpoint'] ?? null ) ? $batch['checkpoint'] : array();
			$job['updated_at'] = gmdate( 'c' );
			++$job['batches'];
			if ( ! update_option( $key, $job, false ) ) {
				throw new RuntimeException( 'The live-verification checkpoint could not be persisted.' );
			}
			self::schedule( $report_id );
		} catch ( Throwable $error ) {
			if ( self::owns_lock( $report_id, $token ) ) {
				$manager = erankly_migration_manager();
				$report  = $manager->get_report( $report_id );
				$saved   = false;
				if ( is_array( $report ) ) {
					$result                      = self::failed_result();
					$result['error']             = sanitize_key( get_class( $error ) );
					$report['live_verification'] = $result;
					$saved                       = $manager->update_report( $report );
				}
				if ( $saved ) {
					delete_option( $key );
					wp_clear_scheduled_hook( ERANKLY_MIGRATION_VERIFY_CRON_HOOK, array( $report_id ) );
				} else {
					self::schedule( $report_id, 30 );
				}
			} else {
				self::schedule( $report_id, 10 );
			}
		} finally {
			self::release_lock( $report_id, $token );
		}
	}

	/** Removes every dynamic verification checkpoint during reset/uninstall. */
	public static function purge_all(): bool {
		global $wpdb;

		$patterns = array( self::JOB_PREFIX, self::LOCK_PREFIX );
		$success  = true;
		foreach ( $patterns as $prefix ) {
			$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact plugin-owned dynamic option discovery for cache-aware deletion.
				$wpdb->prepare(
					'SELECT option_name FROM %i WHERE option_name LIKE %s',
					$wpdb->options,
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			if ( ! is_array( $names ) ) {
				$success = false;
				continue;
			}
			foreach ( $names as $name ) {
				$success = delete_option( (string) $name ) && $success;
			}
		}
		if ( defined( 'ERANKLY_MIGRATION_VERIFY_CRON_HOOK' ) ) {
			wp_clear_scheduled_hook( ERANKLY_MIGRATION_VERIFY_CRON_HOOK );
		}

		return $success;
	}

	/** Returns a controlled fail-closed terminal result. */
	private static function failed_result(): array {
		return array(
			'verified_at'      => gmdate( 'c' ),
			'state'            => 'inconclusive',
			'matched'          => 0,
			'expected_changes' => 0,
			'mismatch'         => 0,
			'request_failed'   => 1,
			'pages'            => array(),
			'redirects'        => array(),
			'surfaces'         => array(),
			'follow_redirects' => false,
			'background'       => true,
		);
	}

	/**
	 * Schedules the next page without duplicate events.
	 *
	 * @param string $report_id Migration report UUID.
	 * @param int    $delay     Delay in seconds.
	 */
	private static function schedule( string $report_id, int $delay = 1 ): void {
		$args = array( $report_id );
		if ( false !== wp_next_scheduled( ERANKLY_MIGRATION_VERIFY_CRON_HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), ERANKLY_MIGRATION_VERIFY_CRON_HOOK, $args, true );
	}

	/**
	 * Acquires a per-report lease with atomic stale takeover.
	 *
	 * @param string $report_id Migration report UUID.
	 */
	private static function acquire_lock( string $report_id ): string {
		global $wpdb;

		$key   = self::lock_key( $report_id );
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'   => $token,
			'expires' => time() + self::LOCK_TTL,
		);
		if ( add_option( $key, $lock, '', 'no' ) ) {
			return $token;
		}
		$existing = get_option( $key, array() );
		if ( ! is_array( $existing ) || (int) ( $existing['expires'] ?? 0 ) >= time() ) {
			return '';
		}
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic stale-lease takeover.
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

		return '';
	}

	/**
	 * Renews a lease only while this token still owns it.
	 *
	 * @param string $report_id Migration report UUID.
	 * @param string $token     Owned lease token.
	 */
	private static function renew_lock( string $report_id, string $token ): bool {
		global $wpdb;

		$key      = self::lock_key( $report_id );
		$existing = get_option( $key, array() );
		if ( ! is_array( $existing ) || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			return false;
		}
		$renewed            = $existing;
		$renewed['expires'] = max( time() + self::LOCK_TTL, (int) ( $existing['expires'] ?? 0 ) + 1 );
		$updated            = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic token-fenced lease renewal.
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

	/**
	 * Releases only this token's exact persisted lease value.
	 *
	 * @param string $report_id Migration report UUID.
	 * @param string $token     Owned lease token.
	 */
	private static function release_lock( string $report_id, string $token ): void {
		global $wpdb;

		$key      = self::lock_key( $report_id );
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

	/**
	 * Returns whether this token still owns an unexpired lease.
	 *
	 * @param string $report_id Migration report UUID.
	 * @param string $token     Candidate lease token.
	 */
	private static function owns_lock( string $report_id, string $token ): bool {
		$existing = get_option( self::lock_key( $report_id ), array() );

		return is_array( $existing )
			&& (int) ( $existing['expires'] ?? 0 ) >= time()
			&& hash_equals( (string) ( $existing['token'] ?? '' ), $token );
	}

	/**
	 * Returns the bounded job option key.
	 *
	 * @param string $report_id Migration report UUID.
	 */
	private static function job_key( string $report_id ): string {
		return self::JOB_PREFIX . substr( hash( 'sha256', $report_id ), 0, 24 );
	}

	/**
	 * Returns the bounded lock option key.
	 *
	 * @param string $report_id Migration report UUID.
	 */
	private static function lock_key( string $report_id ): string {
		return self::LOCK_PREFIX . substr( hash( 'sha256', $report_id ), 0, 24 );
	}
}
