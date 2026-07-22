<?php
/**
 * Shared multilingual storage ownership and settings interlock.
 *
 * This file deliberately contains no bundled runtime classes. It is loaded by
 * both the normal plugin bootstrap and uninstall.php so lifecycle decisions
 * remain available when no multilingual provider can boot.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ERANKLY_ML_STORAGE_OWNER_OPTION' ) ) {
	define( 'ERANKLY_ML_STORAGE_OWNER_OPTION', 'erankly_ml_storage_owner' );
}

if ( ! defined( 'ERANKLY_ML_OWNERSHIP_LOCK_OPTION' ) ) {
	define( 'ERANKLY_ML_OWNERSHIP_LOCK_OPTION', 'erankly_ml_ownership_lock' );
}

/**
 * Returns the stable bundled-provider identifier without loading its class.
 *
 * @return string
 */
function erankly_ml_bundled_owner_id(): string {
	return 'easyrankly-bundled-multilingual';
}

/**
 * Reads one ownership-scoped option.
 *
 * @param string $name       Option name.
 * @param mixed  $default_value Default value.
 * @param int    $network_id Network ID, or zero for the current scope.
 * @return mixed
 */
function erankly_ml_get_ownership_option( string $name, mixed $default_value = false, int $network_id = 0 ): mixed {
	if ( is_multisite() ) {
		$network_id = $network_id > 0 ? $network_id : get_current_network_id();

		return get_network_option( $network_id, $name, $default_value );
	}

	return get_option( $name, $default_value );
}

/**
 * Adds one ownership-scoped option atomically.
 *
 * @param string $name       Option name.
 * @param mixed  $value      Option value.
 * @param int    $network_id Network ID, or zero for the current scope.
 * @return bool
 */
function erankly_ml_add_ownership_option( string $name, mixed $value, int $network_id = 0 ): bool {
	if ( is_multisite() ) {
		$network_id = $network_id > 0 ? $network_id : get_current_network_id();

		return add_network_option( $network_id, $name, $value );
	}

	return add_option( $name, $value, '', false );
}

/**
 * Updates one ownership-scoped option.
 *
 * @param string $name       Option name.
 * @param mixed  $value      Option value.
 * @param int    $network_id Network ID, or zero for the current scope.
 * @return bool
 */
function erankly_ml_update_ownership_option( string $name, mixed $value, int $network_id = 0 ): bool {
	if ( is_multisite() ) {
		$network_id = $network_id > 0 ? $network_id : get_current_network_id();

		return update_network_option( $network_id, $name, $value );
	}

	return update_option( $name, $value, false );
}

/**
 * Deletes an option only when its serialized value still matches the snapshot.
 *
 * @param string $name       Option name.
 * @param mixed  $expected   Expected current value.
 * @param int    $network_id Network ID, or zero for the current scope.
 * @return bool
 */
function erankly_ml_compare_delete_ownership_option( string $name, mixed $expected, int $network_id = 0 ): bool {
	global $wpdb;

	$serialized = maybe_serialize( $expected );

	if ( is_multisite() ) {
		$network_id = $network_id > 0 ? $network_id : get_current_network_id();
		$deleted    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-delete is the ownership mutex CAS.
			$wpdb->prepare(
				'DELETE FROM %i WHERE site_id = %d AND meta_key = %s AND meta_value = %s',
				$wpdb->sitemeta,
				$network_id,
				$name,
				$serialized
			)
		);
		wp_cache_delete( $network_id . ':' . $name, 'site-options' );
	} else {
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-delete is the ownership mutex CAS.
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$name,
				$serialized
			)
		);
		wp_cache_delete( $name, 'options' );
	}

	return 1 === $deleted;
}

/**
 * Replaces an option only when its serialized value still matches the snapshot.
 *
 * @param string $name       Option name.
 * @param mixed  $expected   Expected current value.
 * @param mixed  $next       Replacement value.
 * @param int    $network_id Network ID, or zero for the current scope.
 * @return bool
 */
function erankly_ml_compare_update_ownership_option( string $name, mixed $expected, mixed $next, int $network_id = 0 ): bool {
	global $wpdb;

	$expected_serialized = maybe_serialize( $expected );
	$next_serialized     = maybe_serialize( $next );

	if ( is_multisite() ) {
		$network_id = $network_id > 0 ? $network_id : get_current_network_id();
		$updated    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-update is the marker/lease CAS.
			$wpdb->prepare(
				'UPDATE %i SET meta_value = %s WHERE site_id = %d AND meta_key = %s AND meta_value = %s',
				$wpdb->sitemeta,
				$next_serialized,
				$network_id,
				$name,
				$expected_serialized
			)
		);
		wp_cache_delete( $network_id . ':' . $name, 'site-options' );
	} else {
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-update is the marker/lease CAS.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$next_serialized,
				$name,
				$expected_serialized
			)
		);
		wp_cache_delete( $name, 'options' );
	}

	return 1 === $updated;
}

/**
 * Acquires the shared settings/ownership mutex.
 *
 * @param int $ttl Lease duration in seconds.
 * @return string|WP_Error Opaque token or a retryable lock error.
 */
function erankly_ml_acquire_ownership_lock( int $ttl = 30 ): string|WP_Error {
	$ttl   = max( 5, min( 300, $ttl ) );
	$token = wp_generate_uuid4() . ':' . wp_generate_password( 24, false, false );
	$now   = time();
	$value = array(
		'token'       => $token,
		'acquired_at' => $now,
		'expires_at'  => $now + $ttl,
	);

	for ( $attempt = 0; $attempt < 2; ++$attempt ) {
		if ( erankly_ml_add_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, $value ) ) {
			return $token;
		}

		$current = erankly_ml_get_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, array() );
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) >= $now ) {
			break;
		}

		erankly_ml_compare_delete_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, $current );
	}

	return new WP_Error(
		'erankly_ml_ownership_locked',
		__( 'Multilingual ownership is being updated by another request.', 'easyrankly' ),
		array( 'retryable' => true )
	);
}

/**
 * Verifies that a mutex token is still current and unexpired.
 *
 * @param string $token Mutex token.
 * @return bool
 */
function erankly_ml_ownership_lock_is_valid( string $token ): bool {
	$current = erankly_ml_get_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, array() );

	return is_array( $current )
		&& hash_equals( (string) ( $current['token'] ?? '' ), $token )
		&& (int) ( $current['expires_at'] ?? 0 ) >= time();
}

/**
 * Renews a held mutex using compare-and-swap.
 *
 * @param string $token Mutex token.
 * @param int    $ttl   Lease duration in seconds.
 * @return bool
 */
function erankly_ml_renew_ownership_lock( string $token, int $ttl = 30 ): bool {
	$current = erankly_ml_get_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, array() );

	if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) || (int) ( $current['expires_at'] ?? 0 ) < time() ) {
		return false;
	}

	$next               = $current;
	$next['expires_at'] = max( (int) $current['expires_at'] + 1, time() + max( 5, min( 300, $ttl ) ) );

	return erankly_ml_compare_update_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, $current, $next );
}

/**
 * Releases a mutex only when the caller still owns it.
 *
 * @param string $token Mutex token.
 * @return bool
 */
function erankly_ml_release_ownership_lock( string $token ): bool {
	$current = erankly_ml_get_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, array() );

	if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
		return false;
	}

	return erankly_ml_compare_delete_ownership_option( ERANKLY_ML_OWNERSHIP_LOCK_OPTION, $current );
}

/**
 * Returns a normalized ownership marker while preserving its journal payload.
 *
 * @param mixed $raw Raw option value.
 * @return array<string,mixed>
 */
function erankly_ml_normalize_storage_owner_marker( mixed $raw ): array {
	if ( false === $raw || null === $raw || array() === $raw ) {
		return array();
	}

	// A present but malformed value is an unverified ownership state. Preserve
	// storage and suppress the fallback rather than treating corruption as an
	// absent pre-2.1 marker.
	if ( ! is_array( $raw ) ) {
		$raw = array(
			'contract'      => 0,
			'state'         => 'invalid',
			'current_owner' => 'unknown',
		);
	}

	return array(
		'contract'                => (int) ( $raw['contract'] ?? 0 ),
		'revision'                => max( 0, (int) ( $raw['revision'] ?? 0 ) ),
		'current_owner'           => sanitize_key( (string) ( $raw['current_owner'] ?? '' ) ),
		'candidate_owner'         => sanitize_key( (string) ( $raw['candidate_owner'] ?? '' ) ),
		'state'                   => sanitize_key( (string) ( $raw['state'] ?? '' ) ),
		'topology'                => sanitize_key( (string) ( $raw['topology'] ?? '' ) ),
		'core_version'            => sanitize_text_field( (string) ( $raw['core_version'] ?? '' ) ),
		'addon_version'           => sanitize_text_field( (string) ( $raw['addon_version'] ?? '' ) ),
		'lease_token'             => (string) ( $raw['lease_token'] ?? '' ),
		'lease_expires_at'        => max( 0, (int) ( $raw['lease_expires_at'] ?? 0 ) ),
		'legacy_enabled_snapshot' => ! empty( $raw['legacy_enabled_snapshot'] ),
		'legacy_schema_version'   => sanitize_text_field( (string) ( $raw['legacy_schema_version'] ?? '' ) ),
		'rollback_possible'       => ! empty( $raw['rollback_possible'] ),
		'fingerprint'             => sanitize_text_field( (string) ( $raw['fingerprint'] ?? '' ) ),
		'prepared_at'             => max( 0, (int) ( $raw['prepared_at'] ?? 0 ) ),
		'claimed_at'              => max( 0, (int) ( $raw['claimed_at'] ?? 0 ) ),
		'journal'                 => isset( $raw['journal'] ) && is_array( $raw['journal'] ) ? $raw['journal'] : array(),
		'rollback_metadata'       => isset( $raw['rollback_metadata'] ) && is_array( $raw['rollback_metadata'] ) ? $raw['rollback_metadata'] : array(),
	);
}

/**
 * Reads the current network ownership marker.
 *
 * @param int $network_id Network ID, or zero for current.
 * @return array<string,mixed>
 */
function erankly_ml_get_storage_owner_marker( int $network_id = 0 ): array {
	return erankly_ml_normalize_storage_owner_marker(
		erankly_ml_get_ownership_option( ERANKLY_ML_STORAGE_OWNER_OPTION, array(), $network_id )
	);
}

/**
 * Returns whether a marker is a verified core-owned terminal state.
 *
 * A missing marker represents a pre-2.1 core-owned installation. Every
 * unknown or journaled marker is retained rather than guessed safe.
 *
 * @param array<string,mixed>|null $marker Marker, or null to read current.
 * @return bool
 */
function erankly_ml_storage_cleanup_allowed( ?array $marker = null ): bool {
	$marker = null === $marker ? erankly_ml_get_storage_owner_marker() : erankly_ml_normalize_storage_owner_marker( $marker );

	if ( array() === $marker ) {
		return true;
	}

	return 1 === $marker['contract']
		&& 'core' === $marker['state']
		&& erankly_ml_bundled_owner_id() === $marker['current_owner']
		&& '' === $marker['candidate_owner']
		&& '' === $marker['lease_token'];
}

/**
 * Returns whether the bundled provider may read the legacy storage.
 *
 * @param array<string,mixed>|null $marker Marker, or null to read current.
 * @return bool
 */
function erankly_ml_bundled_runtime_allowed( ?array $marker = null ): bool {
	$marker = null === $marker ? erankly_ml_get_storage_owner_marker() : erankly_ml_normalize_storage_owner_marker( $marker );

	if ( array() === $marker ) {
		return true;
	}

	return 1 === $marker['contract']
		&& erankly_ml_bundled_owner_id() === $marker['current_owner']
		&& in_array( $marker['state'], array( 'core', 'pending', 'ready' ), true );
}

/**
 * Returns whether the bundled runtime may mutate multilingual storage.
 *
 * @return bool
 */
function erankly_ml_legacy_writes_allowed(): bool {
	$marker = erankly_ml_get_storage_owner_marker();

	return array() === $marker || erankly_ml_storage_cleanup_allowed( $marker );
}

/**
 * Returns whether the ownership state interlocks the legacy feature toggle off.
 *
 * @param array<string,mixed>|null $marker Marker, or null to read current.
 * @return bool
 */
function erankly_ml_toggle_must_stay_off( ?array $marker = null ): bool {
	$marker = null === $marker ? erankly_ml_get_storage_owner_marker() : erankly_ml_normalize_storage_owner_marker( $marker );

	return array() !== $marker && ! erankly_ml_storage_cleanup_allowed( $marker );
}

/**
 * Validates the lifecycle transition encoded by a marker CAS.
 *
 * @param string $from Previous state, empty for no marker.
 * @param string $to   Next state.
 * @return bool
 */
function erankly_ml_storage_owner_transition_allowed( string $from, string $to ): bool {
	$transitions = array(
		''               => array( 'pending', 'core' ),
		'core'           => array( 'pending', 'core' ),
		'pending'        => array( 'pending', 'ready', 'error' ),
		'ready'          => array( 'ready', 'claimed', 'error' ),
		'claimed'        => array( 'claimed', 'rollback_ready', 'retained', 'error' ),
		'rollback_ready' => array( 'rollback_ready', 'core', 'error' ),
		'error'          => array( 'error', 'pending' ),
		'retained'       => array( 'retained' ),
	);

	return isset( $transitions[ $from ] ) && in_array( $to, $transitions[ $from ], true );
}

/**
 * Writes the ownership marker with revision and serialized-value CAS.
 *
 * @param array<string,mixed> $next              Next marker payload.
 * @param int                 $expected_revision Expected current revision.
 * @param string              $lock_token        Shared mutex token.
 * @return array<string,mixed>|WP_Error Stored marker or error.
 */
function erankly_ml_cas_storage_owner_marker( array $next, int $expected_revision, string $lock_token ): array|WP_Error {
	if ( ! erankly_ml_ownership_lock_is_valid( $lock_token ) ) {
		return new WP_Error( 'erankly_ml_lease_lost', __( 'The multilingual ownership lease expired.', 'easyrankly' ), array( 'retryable' => true ) );
	}

	$raw      = erankly_ml_get_ownership_option( ERANKLY_ML_STORAGE_OWNER_OPTION, false );
	$current  = erankly_ml_normalize_storage_owner_marker( $raw );
	$revision = (int) ( $current['revision'] ?? 0 );

	if ( $expected_revision !== $revision ) {
		return new WP_Error(
			'erankly_ml_revision_conflict',
			__( 'Multilingual ownership changed before this operation completed.', 'easyrankly' ),
			array(
				'retryable' => true,
				'current'   => $current,
			)
		);
	}

	$next             = erankly_ml_normalize_storage_owner_marker( $next );
	$next['contract'] = 1;
	$next['revision'] = $expected_revision + 1;
	$from_state       = (string) ( $current['state'] ?? '' );

	if ( ! erankly_ml_storage_owner_transition_allowed( $from_state, $next['state'] ) ) {
		return new WP_Error( 'erankly_ml_invalid_transition', __( 'The requested multilingual ownership transition is not allowed.', 'easyrankly' ), array( 'retryable' => false ) );
	}

	$written = array() === $current && false === $raw
		? erankly_ml_add_ownership_option( ERANKLY_ML_STORAGE_OWNER_OPTION, $next )
		: erankly_ml_compare_update_ownership_option( ERANKLY_ML_STORAGE_OWNER_OPTION, $raw, $next );

	if ( ! $written ) {
		return new WP_Error( 'erankly_ml_cas_failed', __( 'Multilingual ownership could not be updated atomically.', 'easyrankly' ), array( 'retryable' => true ) );
	}

	do_action( 'erankly_ml_lifecycle_checkpoint', 'marker_' . $next['state'], $next );

	return $next;
}

/**
 * Builds a new pending adoption marker under the shared lock.
 *
 * @param string $candidate_owner Candidate provider ID.
 * @param string $addon_version   Candidate version.
 * @param string $fingerprint     Verified storage fingerprint.
 * @param int    $lease_ttl       Adoption lease seconds.
 * @return array<string,mixed>|WP_Error
 */
function erankly_ml_prepare_storage_claim( string $candidate_owner, string $addon_version, string $fingerprint, int $lease_ttl = 120 ): array|WP_Error {
	$lock = erankly_ml_acquire_ownership_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$current = erankly_ml_get_storage_owner_marker();
		if ( array() !== $current && ! erankly_ml_storage_cleanup_allowed( $current ) ) {
			return new WP_Error(
				'erankly_ml_owner_conflict',
				__( 'Multilingual storage already has an active ownership journal.', 'easyrankly' ),
				array(
					'retryable' => false,
					'current'   => $current,
				)
			);
		}

		$now   = time();
		$lease = wp_generate_uuid4() . ':' . wp_generate_password( 24, false, false );
		$next  = array(
			'contract'                => 1,
			'revision'                => (int) ( $current['revision'] ?? 0 ),
			'current_owner'           => erankly_ml_bundled_owner_id(),
			'candidate_owner'         => sanitize_key( $candidate_owner ),
			'state'                   => 'pending',
			'topology'                => 'network',
			'core_version'            => defined( 'ERANKLY_VERSION' ) ? ERANKLY_VERSION : '',
			'addon_version'           => $addon_version,
			'lease_token'             => $lease,
			'lease_expires_at'        => $now + max( 30, min( 900, $lease_ttl ) ),
			'legacy_enabled_snapshot' => function_exists( 'erankly_get_setting' ) && (bool) erankly_get_setting( 'enable_multilingual', 0 ),
			'legacy_schema_version'   => (string) erankly_ml_get_ownership_option( 'erankly_ml_db_version', '' ),
			'rollback_possible'       => true,
			'fingerprint'             => $fingerprint,
			'prepared_at'             => $now,
			'claimed_at'              => 0,
			'journal'                 => array(
				'phase'      => 'pending',
				'updated_at' => $now,
				'last_error' => '',
			),
			'rollback_metadata'       => array(
				'verified_at'  => 0,
				'completed_at' => 0,
			),
		);

		return erankly_ml_cas_storage_owner_marker( $next, (int) ( $current['revision'] ?? 0 ), $lock );
	} finally {
		erankly_ml_release_ownership_lock( $lock );
	}
}

/**
 * Advances a pending adoption to ready after the read-only verification.
 *
 * @param string $lease_token         Adoption lease token.
 * @param int    $expected_revision   Expected marker revision.
 * @param string $expected_fingerprint Expected storage fingerprint.
 * @return array<string,mixed>|WP_Error
 */
function erankly_ml_verify_storage_claim( string $lease_token, int $expected_revision, string $expected_fingerprint ): array|WP_Error {
	$lock = erankly_ml_acquire_ownership_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$marker = erankly_ml_get_storage_owner_marker();
		if ( 'pending' !== ( $marker['state'] ?? '' )
			|| ! hash_equals( (string) ( $marker['lease_token'] ?? '' ), $lease_token )
			|| (int) ( $marker['lease_expires_at'] ?? 0 ) < time()
			|| ! hash_equals( (string) ( $marker['fingerprint'] ?? '' ), $expected_fingerprint ) ) {
			return new WP_Error( 'erankly_ml_preflight_changed', __( 'The multilingual adoption preflight is no longer valid.', 'easyrankly' ), array( 'retryable' => false ) );
		}

		$marker['state']                 = 'ready';
		$marker['journal']['phase']      = 'ready';
		$marker['journal']['updated_at'] = time();

		return erankly_ml_cas_storage_owner_marker( $marker, $expected_revision, $lock );
	} finally {
		erankly_ml_release_ownership_lock( $lock );
	}
}

/**
 * Claims storage after forcing and verifying the legacy toggle off.
 *
 * @param string $lease_token          Adoption lease token.
 * @param int    $expected_revision    Expected marker revision.
 * @param string $expected_fingerprint Expected storage fingerprint.
 * @return array<string,mixed>|WP_Error
 */
function erankly_ml_claim_storage( string $lease_token, int $expected_revision, string $expected_fingerprint ): array|WP_Error {
	$lock = erankly_ml_acquire_ownership_lock( 60 );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$marker = erankly_ml_get_storage_owner_marker();
		if ( 'ready' !== ( $marker['state'] ?? '' )
			|| ! hash_equals( (string) ( $marker['lease_token'] ?? '' ), $lease_token )
			|| (int) ( $marker['lease_expires_at'] ?? 0 ) < time()
			|| ! hash_equals( (string) ( $marker['fingerprint'] ?? '' ), $expected_fingerprint ) ) {
			return new WP_Error( 'erankly_ml_claim_precondition_failed', __( 'Multilingual storage can no longer be claimed safely.', 'easyrankly' ), array( 'retryable' => false ) );
		}

		$updated = erankly_update_plugin_settings( array( 'enable_multilingual' => 0 ), $lock );
		if ( is_wp_error( $updated ) || (bool) erankly_get_setting( 'enable_multilingual', 0 ) ) {
			return new WP_Error( 'erankly_ml_legacy_toggle_on', __( 'The legacy multilingual toggle could not be verified off.', 'easyrankly' ), array( 'retryable' => false ) );
		}
		do_action( 'erankly_ml_lifecycle_checkpoint', 'legacy_toggle_off', $marker );

		$marker['current_owner']         = (string) $marker['candidate_owner'];
		$marker['candidate_owner']       = '';
		$marker['state']                 = 'claimed';
		$marker['claimed_at']            = time();
		$marker['lease_token']           = '';
		$marker['lease_expires_at']      = 0;
		$marker['journal']['phase']      = 'claimed';
		$marker['journal']['updated_at'] = time();

		return erankly_ml_cas_storage_owner_marker( $marker, $expected_revision, $lock );
	} finally {
		erankly_ml_release_ownership_lock( $lock );
	}
}

/**
 * Prepares a verified rollback while both writers remain frozen.
 *
 * @param string $fingerprint Expected legacy-compatible fingerprint.
 * @param int    $lease_ttl   Rollback lease seconds.
 * @return array<string,mixed>|WP_Error
 */
function erankly_ml_prepare_storage_rollback( string $fingerprint, int $lease_ttl = 120 ): array|WP_Error {
	$lock = erankly_ml_acquire_ownership_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$marker = erankly_ml_get_storage_owner_marker();
		if ( 'claimed' !== ( $marker['state'] ?? '' ) || empty( $marker['rollback_possible'] ) || ! hash_equals( (string) ( $marker['fingerprint'] ?? '' ), $fingerprint ) ) {
			return new WP_Error( 'erankly_ml_rollback_unavailable', __( 'Multilingual storage is not eligible for rollback.', 'easyrankly' ), array( 'retryable' => false ) );
		}

		$now                                        = time();
		$marker['candidate_owner']                  = erankly_ml_bundled_owner_id();
		$marker['state']                            = 'rollback_ready';
		$marker['lease_token']                      = wp_generate_uuid4() . ':' . wp_generate_password( 24, false, false );
		$marker['lease_expires_at']                 = $now + max( 30, min( 900, $lease_ttl ) );
		$marker['journal']['phase']                 = 'rollback_ready';
		$marker['journal']['updated_at']            = $now;
		$marker['rollback_metadata']['verified_at'] = $now;

		return erankly_ml_cas_storage_owner_marker( $marker, (int) $marker['revision'], $lock );
	} finally {
		erankly_ml_release_ownership_lock( $lock );
	}
}

/**
 * Completes rollback and only then restores/verifies the legacy toggle.
 *
 * @param string $lease_token       Rollback lease token.
 * @param int    $expected_revision Expected marker revision.
 * @param bool   $legacy_enabled    Current add-on enabled state to restore.
 * @return array<string,mixed>|WP_Error
 */
function erankly_ml_complete_storage_rollback( string $lease_token, int $expected_revision, bool $legacy_enabled ): array|WP_Error {
	$lock = erankly_ml_acquire_ownership_lock( 60 );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$marker = erankly_ml_get_storage_owner_marker();
		if ( 'rollback_ready' !== ( $marker['state'] ?? '' )
			|| ! hash_equals( (string) ( $marker['lease_token'] ?? '' ), $lease_token )
			|| (int) ( $marker['lease_expires_at'] ?? 0 ) < time() ) {
			return new WP_Error( 'erankly_ml_rollback_precondition_failed', __( 'The multilingual rollback lease is no longer valid.', 'easyrankly' ), array( 'retryable' => false ) );
		}

		$off = erankly_update_plugin_settings( array( 'enable_multilingual' => 0 ), $lock );
		if ( is_wp_error( $off ) || (bool) erankly_get_setting( 'enable_multilingual', 0 ) ) {
			return new WP_Error( 'erankly_ml_legacy_toggle_on', __( 'The legacy multilingual toggle could not be frozen for rollback.', 'easyrankly' ), array( 'retryable' => false ) );
		}

		$marker['current_owner']                     = erankly_ml_bundled_owner_id();
		$marker['candidate_owner']                   = '';
		$marker['state']                             = 'core';
		$marker['lease_token']                       = '';
		$marker['lease_expires_at']                  = 0;
		$marker['journal']['phase']                  = 'core';
		$marker['journal']['updated_at']             = time();
		$marker['rollback_metadata']['completed_at'] = time();
		$stored                                      = erankly_ml_cas_storage_owner_marker( $marker, $expected_revision, $lock );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		do_action( 'erankly_ml_lifecycle_checkpoint', 'rollback_owner_core', $stored );
		$restored = erankly_update_plugin_settings( array( 'enable_multilingual' => $legacy_enabled ? 1 : 0 ), $lock );
		if ( is_wp_error( $restored ) || (bool) erankly_get_setting( 'enable_multilingual', 0 ) !== $legacy_enabled ) {
			erankly_update_plugin_settings( array( 'enable_multilingual' => 0 ), $lock );

			return new WP_Error( 'erankly_ml_rollback_toggle_failed', __( 'Rollback ownership completed but the legacy toggle could not be verified; it remains off.', 'easyrankly' ), array( 'retryable' => false ) );
		}

		do_action( 'erankly_ml_lifecycle_checkpoint', 'rollback_complete', $stored );

		return $stored;
	} finally {
		erankly_ml_release_ownership_lock( $lock );
	}
}

/**
 * Applies an interlocked merge or explicit replacement to erankly_settings.
 *
 * @param array<string,mixed> $changes    Changes or replacement snapshot.
 * @param string              $lock_token Existing shared mutex token, if any.
 * @param bool                $replace    Replace the known snapshot instead of merging.
 * @return bool|WP_Error
 */
function erankly_update_plugin_settings( array $changes, string $lock_token = '', bool $replace = false ): bool|WP_Error {
	$owns_lock = '' === $lock_token;

	if ( $owns_lock ) {
		$lock_token = erankly_ml_acquire_ownership_lock();
		if ( is_wp_error( $lock_token ) ) {
			return $lock_token;
		}
	} elseif ( ! erankly_ml_ownership_lock_is_valid( $lock_token ) ) {
		return new WP_Error( 'erankly_ml_lease_lost', __( 'The shared settings lease expired.', 'easyrankly' ), array( 'retryable' => true ) );
	}

	$previous_context                             = $GLOBALS['erankly_ml_settings_write_context'] ?? null;
	$GLOBALS['erankly_ml_settings_write_context'] = array(
		'token'             => $lock_token,
		'release_on_update' => false,
		'replace'           => $replace,
	);

	try {
		$current = is_multisite() ? get_site_option( 'erankly_settings', array() ) : get_option( 'erankly_settings', array() );
		$current = is_array( $current ) ? $current : array();
		$next    = $replace ? $changes : array_replace( $current, $changes );

		if ( erankly_ml_toggle_must_stay_off() ) {
			$next['enable_multilingual'] = 0;
		}

		$updated = is_multisite()
			? update_site_option( 'erankly_settings', $next )
			: update_option( 'erankly_settings', $next, true );

		if ( function_exists( 'erankly_clear_settings_cache' ) ) {
			erankly_clear_settings_cache();
		}

		$stored = is_multisite() ? get_site_option( 'erankly_settings', array() ) : get_option( 'erankly_settings', array() );

		return ( $updated || $stored === $next );
	} finally {
		if ( null === $previous_context ) {
			unset( $GLOBALS['erankly_ml_settings_write_context'] );
		} else {
			$GLOBALS['erankly_ml_settings_write_context'] = $previous_context;
		}

		if ( $owns_lock ) {
			erankly_ml_release_ownership_lock( $lock_token );
		}
	}
}

/**
 * Interlocks direct Settings API writers that do not use the helper above.
 *
 * @param mixed  $value      Proposed value.
 * @param mixed  $old_value  Previous value supplied by WordPress.
 * @param string $option     Option name.
 * @param int    $network_id Network ID when WordPress supplies it.
 * @return mixed
 */
function erankly_ml_interlock_settings_pre_update( mixed $value, mixed $old_value, string $option = 'erankly_settings', int $network_id = 0 ): mixed {
	unset( $option, $network_id );

	if ( ! is_array( $value ) ) {
		return is_array( $old_value ) ? $old_value : array();
	}

	$context = $GLOBALS['erankly_ml_settings_write_context'] ?? null;
	if ( ! is_array( $context ) || empty( $context['token'] ) || ! erankly_ml_ownership_lock_is_valid( (string) $context['token'] ) ) {
		$token = erankly_ml_acquire_ownership_lock();
		if ( is_wp_error( $token ) ) {
			return is_array( $old_value ) ? $old_value : array();
		}

		$context                                      = array(
			'token'             => $token,
			'release_on_update' => true,
		);
		$GLOBALS['erankly_ml_settings_write_context'] = $context;
	}

	$current = is_multisite() ? get_site_option( 'erankly_settings', array() ) : get_option( 'erankly_settings', array() );
	$current = is_array( $current ) ? $current : array();
	$next    = ! empty( $context['replace'] ) ? $value : array_replace( $current, $value );

	if ( erankly_ml_toggle_must_stay_off() ) {
		$next['enable_multilingual'] = 0;
	}

	return $next;
}

/**
 * Releases a lock acquired for a direct Settings API update.
 *
 * @return void
 */
function erankly_ml_release_direct_settings_lock(): void {
	$context = $GLOBALS['erankly_ml_settings_write_context'] ?? null;

	if ( ! is_array( $context ) || empty( $context['release_on_update'] ) || empty( $context['token'] ) ) {
		return;
	}

	erankly_ml_release_ownership_lock( (string) $context['token'] );
	unset( $GLOBALS['erankly_ml_settings_write_context'] );
}

/**
 * Returns true if any network marker forbids global multilingual cleanup.
 *
 * Unknown/inaccessible network state is intentionally retained.
 *
 * @return bool
 */
function erankly_ml_any_network_requires_storage_retention(): bool {
	if ( ! is_multisite() ) {
		return ! erankly_ml_storage_cleanup_allowed();
	}

	global $wpdb;
	$last_id     = 0;
	$batch_size  = 100;
	$result_size = 0;

	do {
		$network_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete keyset audit is required before shared-table cleanup.
			$wpdb->prepare( 'SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $wpdb->site, $last_id, $batch_size )
		);

		if ( $wpdb->last_error ) {
			return true;
		}

		$network_ids = array_map( 'intval', (array) $network_ids );
		$result_size = count( $network_ids );
		foreach ( $network_ids as $network_id ) {
			$raw = get_network_option( $network_id, ERANKLY_ML_STORAGE_OWNER_OPTION, false );
			if ( false !== $raw && ! erankly_ml_storage_cleanup_allowed( erankly_ml_normalize_storage_owner_marker( $raw ) ) ) {
				return true;
			}
		}

		if ( $network_ids ) {
			$last_id = (int) end( $network_ids );
		}
	} while ( $batch_size === $result_size );

	return false;
}
