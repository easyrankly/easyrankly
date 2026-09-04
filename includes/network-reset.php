<?php
/** Resumable network reset job. Loaded only for Network Admin and WP-Cron requests. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules one reset batch on the current network's main site. A reset token is included in the Cron arguments
 * so events left by a replaced job cannot process or advance the new job.
 *
 * @return bool|WP_Error
 */
function erankly_schedule_network_reset_batch( string $token, int $delay = 5 ): bool|WP_Error {
	if ( ! is_multisite() || '' === $token ) {
		return new WP_Error( 'erankly_reset_invalid', __( 'The network reset state is invalid.', 'easyrankly' ) );
	}

	$state = get_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, array() );

	if (
		! is_array( $state )
		|| (string) ( $state['token'] ?? '' ) !== $token
		|| ! in_array( (string) ( $state['status'] ?? '' ), array( 'pending', 'running', 'retrying' ), true )
	) {
		return new WP_Error( 'erankly_reset_inactive', __( 'The network reset is no longer active.', 'easyrankly' ) );
	}

	$main_site_id = (int) get_main_site_id();

	if ( $main_site_id < 1 ) {
		return new WP_Error( 'erankly_reset_main_site', __( 'The current network main site could not be resolved.', 'easyrankly' ) );
	}

	$args     = array( $token );
	$switched = get_current_blog_id() !== $main_site_id;

	try {
		if ( $switched ) {
			switch_to_blog( $main_site_id );
		}

		if ( wp_next_scheduled( ERANKLY_NETWORK_RESET_CRON_HOOK, $args ) ) {
			return true;
		}

		$scheduled = wp_schedule_single_event(
			time() + max( 1, $delay ),
			ERANKLY_NETWORK_RESET_CRON_HOOK,
			$args,
			true
		);

		if ( true === $scheduled || wp_next_scheduled( ERANKLY_NETWORK_RESET_CRON_HOOK, $args ) ) {
			return true;
		}

		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}

		return new WP_Error( 'erankly_reset_schedule', __( 'WordPress could not schedule the next network reset batch.', 'easyrankly' ) );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Reads an exact reset-state snapshot for an atomic progress commit. The direct read is intentionally confined
 * to Network Admin and Cron. Public requests never load this module, while the raw serialized value lets a
 * worker prove that no other worker has advanced or replaced the job in the meantime.
 *
 * @return array{raw:string|false,state:mixed}
 * @throws RuntimeException When the reset state cannot be read.
 */
function erankly_get_network_reset_snapshot(): array {
	global $wpdb;

	$network_id = (int) get_current_network_id();
	$raw        = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An exact raw snapshot is required for compare-and-swap progress commits.
		$wpdb->prepare(
			'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1',
			$wpdb->sitemeta,
			$network_id,
			ERANKLY_NETWORK_RESET_JOB_OPTION
		)
	);

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not read the network reset state.', 'easyrankly' ) );
	}

	return array(
		'raw'   => null === $raw ? false : (string) $raw,
		'state' => null === $raw ? false : maybe_unserialize( $raw ),
	);
}

/**
 * Saves reset progress only if the observed state has not changed.
 *
 * @param string              $expected_raw Exact serialized snapshot being replaced.
 * @return bool Whether the compare-and-swap succeeded.
 * @throws RuntimeException When the reset state cannot be updated.
 */
function erankly_save_network_reset_state( array $state, string $expected_raw ): bool {
	global $wpdb;

	if ( empty( $state['token'] ) ) {
		return false;
	}

	$network_id          = (int) get_current_network_id();
	$state['updated_at'] = time();
	$replacement         = (string) maybe_serialize( $state );

	if ( $replacement === $expected_raw ) {
		return true;
	}

	$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update prevents concurrent workers from regressing reset progress.
		$wpdb->prepare(
			'UPDATE %i SET meta_value = %s WHERE site_id = %d AND meta_key = %s AND BINARY meta_value = %s',
			$wpdb->sitemeta,
			$replacement,
			$network_id,
			ERANKLY_NETWORK_RESET_JOB_OPTION,
			$expected_raw
		)
	);

	if ( false === $updated ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not save the network reset state.', 'easyrankly' ) );
	}

	if ( $updated > 0 ) {
		wp_cache_delete( $network_id . ':' . ERANKLY_NETWORK_RESET_JOB_OPTION, 'site-options' );
	}

	return $updated > 0;
}

/**
 * Deletes a terminal reset state only if it is still the observed job.
 *
 * @param string $expected_raw Exact serialized snapshot being deleted.
 * @return bool Whether the compare-and-delete succeeded.
 * @throws RuntimeException When the reset state cannot be deleted.
 */
function erankly_delete_network_reset_state( string $expected_raw ): bool {
	global $wpdb;

	$network_id = (int) get_current_network_id();
	$deleted    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional deletion cannot remove a concurrently restarted reset.
		$wpdb->prepare(
			'DELETE FROM %i WHERE site_id = %d AND meta_key = %s AND BINARY meta_value = %s',
			$wpdb->sitemeta,
			$network_id,
			ERANKLY_NETWORK_RESET_JOB_OPTION,
			$expected_raw
		)
	);

	if ( false === $deleted ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not clear the completed network reset state.', 'easyrankly' ) );
	}

	if ( $deleted > 0 ) {
		wp_cache_delete( $network_id . ':' . ERANKLY_NETWORK_RESET_JOB_OPTION, 'site-options' );
	}

	return $deleted > 0;
}

/** Marks the current reset as failed without reviving a cancelled job. */
function erankly_fail_network_reset( string $token, string $message ): void {
	for ( $attempt = 0; $attempt < 3; $attempt++ ) {
		$snapshot = erankly_get_network_reset_snapshot();
		$state    = $snapshot['state'];

		if (
			false === $snapshot['raw']
			|| ! is_array( $state )
			|| (string) ( $state['token'] ?? '' ) !== $token
			|| ! in_array( (string) ( $state['status'] ?? '' ), array( 'pending', 'running', 'retrying' ), true )
		) {
			return;
		}

		$state['status']     = 'failed';
		$state['last_error'] = wp_strip_all_tags( $message );

		if ( erankly_save_network_reset_state( $state, $snapshot['raw'] ) ) {
			return;
		}
	}
}

/**
 * Commits reset progress and schedules the next batch.
 *
 * @param string              $expected_raw Exact serialized snapshot being replaced.
 * @param int                 $delay        Delay before the next batch.
 */
function erankly_continue_network_reset( array $state, string $expected_raw, int $delay = 5 ): void {
	if ( ! erankly_save_network_reset_state( $state, $expected_raw ) ) {
		return;
	}

	$token     = (string) $state['token'];
	$scheduled = erankly_schedule_network_reset_batch( $token, $delay );

	if ( is_wp_error( $scheduled ) ) {
		erankly_fail_network_reset( $token, $scheduled->get_error_message() );
	}
}

/**
 * Queues a resumable reset for the current network.
 *
 * @return bool Whether the job is active and scheduled.
 */
function erankly_queue_network_reset(): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	$state = array(
		'token'             => wp_generate_uuid4(),
		'status'            => 'pending',
		'phase'             => 'network',
		'last_processed_id' => 0,
		'attempts'          => 0,
		'last_error'        => '',
		'updated_at'        => time(),
	);

	update_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $state );

	$stored = get_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, array() );

	if (
		! is_array( $stored )
		|| (string) ( $stored['token'] ?? '' ) !== (string) $state['token']
	) {
		return false;
	}

	$scheduled = erankly_schedule_network_reset_batch( (string) $state['token'] );

	if ( is_wp_error( $scheduled ) ) {
		erankly_fail_network_reset( (string) $state['token'], $scheduled->get_error_message() );
		return false;
	}

	return true;
}

/**
 * Processes one bounded network reset batch.
 *
 * @param string $token Reset job token supplied by WP-Cron.
 */
function erankly_process_network_reset_batch( string $token = '' ): void {
	if ( ! is_multisite() || '' === $token ) {
		return;
	}

	try {
		$snapshot = erankly_get_network_reset_snapshot();
		$state    = $snapshot['state'];

		if (
			false === $snapshot['raw']
			|| ! is_array( $state )
			|| (string) ( $state['token'] ?? '' ) !== $token
			|| ! in_array( (string) ( $state['status'] ?? '' ), array( 'pending', 'running', 'retrying' ), true )
		) {
			return;
		}

		require_once ERANKLY_PATH . 'includes/reset.php';

		$state['status']     = 'running';
		$state['attempts']   = 0;
		$state['last_error'] = '';
		$phase               = (string) ( $state['phase'] ?? 'network' );

		if ( 'network' === $phase ) {
			erankly_reset_network_shared_data();
			$state['phase'] = 'sites';
			erankly_continue_network_reset( $state, $snapshot['raw'] );
			return;
		}

		if ( 'sites' !== $phase ) {
			// A pre-3.0 job may still name the removed extension-owned phase.
			// Skip it without inspecting or mutating extension storage.
			$state['phase']             = 'sites';
			$state['last_processed_id'] = 0;
			erankly_continue_network_reset( $state, $snapshot['raw'] );
			return;
		}

		$site_ids = erankly_get_network_site_ids_batch(
			(int) ( $state['last_processed_id'] ?? 0 ),
			ERANKLY_NETWORK_RESET_BATCH_SIZE + 1
		);
		$has_more = count( $site_ids ) > ERANKLY_NETWORK_RESET_BATCH_SIZE;

		if ( $has_more ) {
			array_pop( $site_ids );
		}

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			try {
				erankly_reset_site_data();
			} finally {
				restore_current_blog();
			}
		}

		if ( $site_ids ) {
			$state['last_processed_id'] = (int) end( $site_ids );
		}

		if ( $has_more ) {
			erankly_continue_network_reset( $state, $snapshot['raw'] );
			return;
		}

		$state['status']      = 'completed';
		$state['finished_at'] = time();
		erankly_save_network_reset_state( $state, $snapshot['raw'] );
	} catch ( Throwable $error ) {
		// Error recovery must not escape the Cron callback. If the state store is
		// temporarily unavailable too, the active job remains available for the
		// Network Admin self-healing check once database access is restored.
		try {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$current_snapshot = erankly_get_network_reset_snapshot();
				$current          = $current_snapshot['state'];

				if (
					false === $current_snapshot['raw']
					|| ! is_array( $current )
					|| (string) ( $current['token'] ?? '' ) !== $token
					|| ! in_array( (string) ( $current['status'] ?? '' ), array( 'pending', 'running', 'retrying' ), true )
				) {
					return;
				}

				$current['attempts']   = (int) ( $current['attempts'] ?? 0 ) + 1;
				$current['last_error'] = wp_strip_all_tags( $error->getMessage() );
				$current['status']     = $current['attempts'] >= 3 ? 'failed' : 'retrying';

				if ( ! erankly_save_network_reset_state( $current, $current_snapshot['raw'] ) ) {
					continue;
				}

				if ( 'failed' === $current['status'] ) {
					return;
				}

				$scheduled = erankly_schedule_network_reset_batch(
					$token,
					MINUTE_IN_SECONDS * $current['attempts']
				);

				if ( is_wp_error( $scheduled ) ) {
					erankly_fail_network_reset( $token, $scheduled->get_error_message() );
				}

				return;
			}
		} catch ( Throwable ) {
			return;
		}
	}
}

/**
 * Displays network reset progress and makes an active job self-healing. This runs only in Network Admin, keeping
 * reset-state checks out of public requests. Visiting Network Admin recreates a missing Cron event when
 * possible.
 */
function erankly_render_network_reset_status_notice(): void {
	if (
		! is_multisite()
		|| ! is_network_admin()
		|| ! current_user_can( 'manage_network_options' )
	) {
		return;
	}

	try {
		$snapshot = erankly_get_network_reset_snapshot();
		$state    = $snapshot['state'];

		if ( false === $snapshot['raw'] || ! is_array( $state ) || empty( $state['token'] ) ) {
			return;
		}

		$status = (string) ( $state['status'] ?? '' );

		if ( in_array( $status, array( 'pending', 'running', 'retrying' ), true ) ) {
			$scheduled = erankly_schedule_network_reset_batch( (string) $state['token'] );

			if ( is_wp_error( $scheduled ) ) {
				erankly_fail_network_reset( (string) $state['token'], $scheduled->get_error_message() );
				$snapshot = erankly_get_network_reset_snapshot();
				$state    = $snapshot['state'];
				$status   = (string) ( $state['status'] ?? 'failed' );
			}
		}

		if ( 'completed' === $status ) {
			if ( false === $snapshot['raw'] || ! erankly_delete_network_reset_state( $snapshot['raw'] ) ) {
				return;
			}

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The EasyRankly network reset completed successfully.', 'easyrankly' ) . '</p></div>';
			return;
		}

		if ( 'failed' === $status ) {
			$error = (string) ( $state['last_error'] ?? __( 'Unknown error.', 'easyrankly' ) );
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: reset error message. */
					__( 'The EasyRankly network reset stopped after repeated errors: %s Run the network reset again to retry.', 'easyrankly' ),
					$error
				)
			);
			echo '</p></div>';
			return;
		}

		$phase = (string) ( $state['phase'] ?? 'network' );

		if ( 'network' === $phase ) {
			$phase_label = __( 'network settings cleanup', 'easyrankly' );
		} elseif ( 'sites' === $phase ) {
			$phase_label = __( 'site cleanup', 'easyrankly' );
		} else {
			$phase_label = __( 'site cleanup', 'easyrankly' );
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: current reset phase. */
				__( 'The EasyRankly network reset is running in the background (%s).', 'easyrankly' ),
				$phase_label
			)
		);
		echo '</p></div>';
	} catch ( Throwable ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'EasyRankly could not inspect or repair the network reset job because a database operation failed. Resolve the database issue, then reload this page.', 'easyrankly' ) . '</p></div>';
	}
}
