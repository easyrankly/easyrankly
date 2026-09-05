<?php
/**
 * Atomic EasyRankly settings writer. This runtime is provider-neutral. It serializes whole-settings writes from
 * core and extensions without owning any extension data or lifecycle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ERANKLY_SETTINGS_LOCK_OPTION' ) ) {
	define( 'ERANKLY_SETTINGS_LOCK_OPTION', 'erankly_settings_lock_v1' );
}

/** Reads the settings lock in the current site or network scope. */
function erankly_get_settings_lock(): mixed {
	return is_multisite()
		? get_network_option( get_current_network_id(), ERANKLY_SETTINGS_LOCK_OPTION, false )
		: get_option( ERANKLY_SETTINGS_LOCK_OPTION, false );
}

/** Adds the settings lock atomically. */
function erankly_add_settings_lock( array $value ): bool {
	return is_multisite()
		? add_network_option( get_current_network_id(), ERANKLY_SETTINGS_LOCK_OPTION, $value )
		: add_option( ERANKLY_SETTINGS_LOCK_OPTION, $value, '', false );
}

/** Deletes the settings lock only when its serialized snapshot still matches. */
function erankly_compare_delete_settings_lock( array $expected ): bool {
	global $wpdb;

	$serialized = maybe_serialize( $expected );

	if ( is_multisite() ) {
		$network_id = get_current_network_id();
		$deleted    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete is the settings mutex CAS.
			$wpdb->prepare(
				'DELETE FROM %i WHERE site_id = %d AND meta_key = %s AND meta_value = %s',
				$wpdb->sitemeta,
				$network_id,
				ERANKLY_SETTINGS_LOCK_OPTION,
				$serialized
			)
		);
		wp_cache_delete( $network_id . ':' . ERANKLY_SETTINGS_LOCK_OPTION, 'site-options' );
	} else {
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete is the settings mutex CAS.
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				ERANKLY_SETTINGS_LOCK_OPTION,
				$serialized
			)
		);
		wp_cache_delete( ERANKLY_SETTINGS_LOCK_OPTION, 'options' );
	}

	return 1 === $deleted;
}

/**
 * @param int $ttl Lease duration in seconds.
 * @return string|WP_Error Opaque token or retryable error.
 */
function erankly_acquire_settings_lock( int $ttl = 30 ): string|WP_Error {
	$ttl   = max( 5, min( 300, $ttl ) );
	$token = wp_generate_uuid4() . ':' . wp_generate_password( 24, false, false );
	$now   = time();
	$value = array(
		'token'       => $token,
		'acquired_at' => $now,
		'expires_at'  => $now + $ttl,
	);

	for ( $attempt = 0; $attempt < 2; ++$attempt ) {
		if ( erankly_add_settings_lock( $value ) ) {
			return $token;
		}

		$current = erankly_get_settings_lock();
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) >= $now ) {
			break;
		}

		erankly_compare_delete_settings_lock( $current );
	}

	return new WP_Error(
		'erankly_settings_locked',
		__( 'EasyRankly settings are being updated by another request.', 'easyrankly' ),
		array( 'retryable' => true )
	);
}

/** Returns whether a settings lock token is current and unexpired. */
function erankly_settings_lock_is_valid( string $token ): bool {
	$current = erankly_get_settings_lock();

	return is_array( $current )
		&& hash_equals( (string) ( $current['token'] ?? '' ), $token )
		&& (int) ( $current['expires_at'] ?? 0 ) >= time();
}

/** Releases a settings lock held by the caller. */
function erankly_release_settings_lock( string $token ): bool {
	$current = erankly_get_settings_lock();

	if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
		return false;
	}

	return erankly_compare_delete_settings_lock( $current );
}

/**
 * Applies an interlocked merge or explicit replacement to EasyRankly settings.
 *
 * @param array<string,mixed> $changes    Changes or replacement snapshot.
 * @param string              $lock_token Existing settings lock token, if any.
 * @param bool                $replace    Replace the snapshot instead of merging.
 * @return bool|WP_Error
 */
function erankly_update_plugin_settings( array $changes, string $lock_token = '', bool $replace = false ): bool|WP_Error {
	$owns_lock = '' === $lock_token;

	if ( $owns_lock ) {
		$lock_token = erankly_acquire_settings_lock();
		if ( is_wp_error( $lock_token ) ) {
			return $lock_token;
		}
	} elseif ( ! erankly_settings_lock_is_valid( $lock_token ) ) {
		return new WP_Error( 'erankly_settings_lease_lost', __( 'The shared settings lease expired.', 'easyrankly' ), array( 'retryable' => true ) );
	}

	$previous_context                          = $GLOBALS['erankly_settings_write_context'] ?? null;
	$GLOBALS['erankly_settings_write_context'] = array(
		'token'             => $lock_token,
		'release_on_update' => false,
		'replace'           => $replace,
	);

	try {
		$current = is_multisite() ? get_site_option( ERANKLY_OPTION, array() ) : get_option( ERANKLY_OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		if ( $replace && function_exists( 'erankly_default_settings' ) ) {
			$extension_settings = array_diff_key( $current, erankly_default_settings() );
			$extension_settings = apply_filters( 'erankly_preserved_extension_settings', $extension_settings, $changes );
			$extension_settings = is_array( $extension_settings ) ? $extension_settings : array();
			$next               = array_replace( $extension_settings, $changes );
		} else {
			$next = $replace ? $changes : array_replace( $current, $changes );
		}

		$before  = $current;
		$updated = is_multisite()
			? update_site_option( ERANKLY_OPTION, $next )
			: update_option( ERANKLY_OPTION, $next, true );

		if ( function_exists( 'erankly_clear_settings_cache' ) ) {
			erankly_clear_settings_cache();
		}

		$stored = is_multisite() ? get_site_option( ERANKLY_OPTION, array() ) : get_option( ERANKLY_OPTION, array() );

		if ( $updated || $stored === $next ) {
			return true;
		}

		// Settings API sanitize callbacks may reshape $next before persistence.
		// WordPress then returns false from update_option when that sanitized
		// snapshot already matches storage — an idempotent success, not a failure.
		global $wpdb;

		return is_array( $stored ) && $stored === $before && '' === (string) $wpdb->last_error;
	} finally {
		if ( null === $previous_context ) {
			unset( $GLOBALS['erankly_settings_write_context'] );
		} else {
			$GLOBALS['erankly_settings_write_context'] = $previous_context;
		}

		if ( $owns_lock ) {
			erankly_release_settings_lock( $lock_token );
		}
	}
}

/**
 * Interlocks direct Settings API writers with the shared settings mutex.
 *
 * @param mixed  $old_value  Previous value supplied by WordPress.
 * @param int    $network_id Network ID when WordPress supplies it.
 */
function erankly_interlock_settings_pre_update( mixed $value, mixed $old_value, string $option = ERANKLY_OPTION, int $network_id = 0 ): mixed {
	unset( $option, $network_id );

	if ( ! is_array( $value ) ) {
		return is_array( $old_value ) ? $old_value : array();
	}

	$context = $GLOBALS['erankly_settings_write_context'] ?? null;
	if ( ! is_array( $context ) || empty( $context['token'] ) || ! erankly_settings_lock_is_valid( (string) $context['token'] ) ) {
		$token = erankly_acquire_settings_lock();
		if ( is_wp_error( $token ) ) {
			return is_array( $old_value ) ? $old_value : array();
		}

		$context                                   = array(
			'token'             => $token,
			'release_on_update' => true,
		);
		$GLOBALS['erankly_settings_write_context'] = $context;
	}

	$current = is_multisite() ? get_site_option( ERANKLY_OPTION, array() ) : get_option( ERANKLY_OPTION, array() );
	$current = is_array( $current ) ? $current : array();

	return ! empty( $context['replace'] ) ? $value : array_replace( $current, $value );
}

/** Releases a lock acquired for a direct Settings API update. */
function erankly_release_direct_settings_lock(): void {
	$context = $GLOBALS['erankly_settings_write_context'] ?? null;

	if ( ! is_array( $context ) || empty( $context['release_on_update'] ) || empty( $context['token'] ) ) {
		return;
	}

	erankly_release_settings_lock( (string) $context['token'] );
	unset( $GLOBALS['erankly_settings_write_context'] );
}
