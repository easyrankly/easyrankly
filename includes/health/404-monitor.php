<?php
/**
 * Health 404 monitoring and aggregation functions.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Records an aggregate counter when the current frontend request is a 404.
 *
 * @return void
 */
function erankly_health_maybe_record_404(): void {
	if ( is_admin() || ! is_404() ) {
		return;
	}

	$path = erankly_health_current_request_path();

	if ( '' === $path ) {
		return;
	}

	erankly_health_record_404_path( $path, erankly_health_internal_referrer_path() );
}

/**
 * Returns the normalized path for the current request.
 *
 * Query strings are intentionally ignored so the scanner aggregates repeated
 * missing URLs instead of creating separate counters for tracking parameters.
 *
 * @return string
 */
function erankly_health_current_request_path(): string {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

	if ( '' === $request_uri ) {
		return '';
	}

	$path = wp_parse_url( $request_uri, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	// Preserve token punctuation until the sampled-in storage sanitizer can
	// classify it. sanitize_text_field() removes percent-encoded octets and could
	// otherwise shorten a secret below the anonymization threshold.
	$path = wp_strip_all_tags( rawurldecode( $path ), true );
	$path = preg_replace( '#/+#', '/', $path );
	$path = is_string( $path ) ? $path : '';

	if ( '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );

	return '/' === $path ? $path : untrailingslashit( $path );
}

/**
 * Returns the same-host referrer path for the current request, or ''.
 *
 * Only internal (same-host) referrers are ever considered; external referrers
 * are discarded and never stored. The returned path is decoded and slash-
 * normalized but NOT yet anonymized. Anonymization runs inside
 * erankly_health_record_404_path() after the sampling gate, mirroring how the
 * 404 path itself is handled. Collection can be disabled with the
 * 'erankly_health_collect_referrers' filter.
 *
 * @return string
 */
function erankly_health_internal_referrer_path(): string {
	/**
	 * Filters whether internal HTTP referrers are recorded alongside 404s.
	 *
	 * Only same-host referrer paths are ever considered, and they are anonymized
	 * before storage. Return false to disable referrer collection entirely.
	 *
	 * @param bool $enabled Whether to collect internal referrers.
	 */
	if ( ! apply_filters( 'erankly_health_collect_referrers', true ) ) {
		return '';
	}

	$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : '';

	if ( '' === $referer ) {
		return '';
	}

	$referer_host = wp_parse_url( $referer, PHP_URL_HOST );
	$home_host    = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! is_string( $referer_host ) || ! is_string( $home_host ) || strtolower( $referer_host ) !== strtolower( $home_host ) ) {
		return '';
	}

	$path = wp_parse_url( $referer, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path = preg_replace( '#/+#', '/', rawurldecode( $path ) );
	$path = is_string( $path ) && '' !== $path ? '/' . ltrim( $path, '/' ) : '';

	if ( '' === $path ) {
		return '';
	}

	// Skip wp-admin / login referrers: they record where the visitor navigated
	// from, not where a broken link actually lives on the site. Segment-based so
	// it also covers subdirectory multisite paths (e.g. /sub/wp-admin/...).
	$segments = explode( '/', strtolower( trim( $path, '/' ) ) );

	if ( in_array( 'wp-admin', $segments, true ) || in_array( 'wp-login.php', $segments, true ) ) {
		return '';
	}

	return $path;
}

/**
 * Merges an internal-referrer path into a 404 entry's bounded referrer map.
 *
 * @param array<string,mixed> $entry    404 entry.
 * @param string              $referrer Anonymized internal referrer path ('' to skip).
 * @return array<string,mixed>
 */
function erankly_health_merge_referrer( array $entry, string $referrer ): array {
	if ( '' === $referrer ) {
		return $entry;
	}

	$referrers = isset( $entry['referrers'] ) && is_array( $entry['referrers'] ) ? $entry['referrers'] : array();

	$referrers[ $referrer ] = ( isset( $referrers[ $referrer ] ) ? absint( $referrers[ $referrer ] ) : 0 ) + 1;

	$max = max( 1, (int) apply_filters( 'erankly_health_max_referrers', ERANKLY_HEALTH_404_MAX_REFERRERS ) );

	if ( count( $referrers ) > $max ) {
		arsort( $referrers );
		$referrers = array_slice( $referrers, 0, $max, true );
	}

	$entry['referrers'] = $referrers;

	return $entry;
}

/**
 * Updates aggregate counters for one normalized 404 path.
 *
 * @param string $path     Normalized request path.
 * @param string $referrer Optional referrer URL for the request.
 * @return void
 */
function erankly_health_record_404_path( string $path, string $referrer = '' ): void {
	/**
	 * Filters the 404 counter sampling rate.
	 *
	 * A rate of 5 stores approximately one sample every five requests and adds
	 * five to the estimated counter, avoiding a database write for most bot 404s.
	 * Use 1 for exact synchronous counters.
	 *
	 * The path passed here is normalized but not yet anonymized, because the
	 * sampling decision runs first: anonymization can issue several user-lookup
	 * queries, so doing it only for sampled-in requests keeps a flood of bot 404s
	 * from amplifying database load.
	 *
	 * @param int    $sample_rate Sampling rate.
	 * @param string $path        Normalized (not yet anonymized) 404 path.
	 */
	$sample_rate = max( 1, (int) apply_filters( 'erankly_health_404_sample_rate', 5, $path ) );

	if ( $sample_rate > 1 && 1 !== wp_rand( 1, $sample_rate ) ) {
		return;
	}

	// A legacy entry may be re-keyed by the stricter sanitizer below. Move its
	// manual state first, otherwise this visit could overwrite the only old-hash
	// entry before the retention cron has a chance to discover the relationship.
	if ( ! erankly_health_ensure_404_storage_current() ) {
		return;
	}

	$path = erankly_health_sanitize_404_path( $path );

	if ( '' === $path ) {
		return;
	}

	// Anonymize the referrer only now (after the sampling gate), and drop self-referrals.
	$referrer = '' !== $referrer ? erankly_health_sanitize_404_path( $referrer ) : '';

	if ( $referrer === $path ) {
		$referrer = '';
	}

	$now      = time();
	$hash     = md5( $path );
	$frequent = erankly_health_get_404_entries( ERANKLY_HEALTH_404_FREQUENT_OPTION );

	if ( isset( $frequent[ $hash ] ) ) {
		$entry = $frequent[ $hash ];

		if ( $now - (int) $entry['window_start'] < ERANKLY_HEALTH_404_WINDOW ) {
			$entry['path']      = $path;
			$entry['count']     = absint( $entry['count'] ) + $sample_rate;
			$entry['last_seen'] = $now;
			$entry              = erankly_health_merge_referrer( $entry, $referrer );

			$frequent[ $hash ] = $entry;
			update_option( ERANKLY_HEALTH_404_FREQUENT_OPTION, erankly_health_prune_404_entries( $frequent, ERANKLY_HEALTH_404_MAX_FREQUENT ), false );
			return;
		}

		unset( $frequent[ $hash ] );
		update_option( ERANKLY_HEALTH_404_FREQUENT_OPTION, $frequent, false );
	}

	$candidates = erankly_health_get_404_entries( ERANKLY_HEALTH_404_CANDIDATES_OPTION );
	$entry      = isset( $candidates[ $hash ] ) ? $candidates[ $hash ] : array(
		'path'         => $path,
		'count'        => 0,
		'window_start' => $now,
		'first_seen'   => $now,
		'last_seen'    => $now,
	);

	if ( $now - (int) $entry['window_start'] >= ERANKLY_HEALTH_404_WINDOW ) {
		$entry = array(
			'path'         => $path,
			'count'        => 0,
			'window_start' => $now,
			'first_seen'   => $now,
			'last_seen'    => $now,
		);
	}

	$entry['path']      = $path;
	$entry['count']     = absint( $entry['count'] ) + $sample_rate;
	$entry['last_seen'] = $now;
	$entry              = erankly_health_merge_referrer( $entry, $referrer );

	if ( absint( $entry['count'] ) >= ERANKLY_HEALTH_404_THRESHOLD ) {
		unset( $candidates[ $hash ] );
		update_option( ERANKLY_HEALTH_404_CANDIDATES_OPTION, erankly_health_prune_404_entries( $candidates, ERANKLY_HEALTH_404_MAX_CANDIDATES ), false );

		$frequent[ $hash ] = $entry;
		update_option( ERANKLY_HEALTH_404_FREQUENT_OPTION, erankly_health_prune_404_entries( $frequent, ERANKLY_HEALTH_404_MAX_FREQUENT ), false );
		return;
	}

	$candidates[ $hash ] = $entry;
	update_option( ERANKLY_HEALTH_404_CANDIDATES_OPTION, erankly_health_prune_404_entries( $candidates, ERANKLY_HEALTH_404_MAX_CANDIDATES ), false );
}

/**
 * Reads and normalizes stored 404 entries.
 *
 * @param string $option_name Option name.
 * @return array<string,array<string,int|string>>
 */
function erankly_health_get_404_entries( string $option_name ): array {
	$entries    = get_option( $option_name, array() );
	$normalized = erankly_health_normalize_404_entries_with_map( $entries );

	return $normalized['entries'];
}

/**
 * Purely normalizes stored entries and maps every valid old hash to its new one.
 *
 * No option is read or written here. Keeping calculation separate lets the
 * migration build the complete relationship between candidates, frequent rows
 * and states before any persistent value changes.
 *
 * @param mixed $entries Raw stored option value.
 * @return array{entries:array<string,array<string,mixed>>,hash_map:array<string,string>}
 */
function erankly_health_normalize_404_entries_with_map( $entries ): array {
	$result = array(
		'entries'  => array(),
		'hash_map' => array(),
	);

	if ( ! is_array( $entries ) ) {
		return $result;
	}

	foreach ( $entries as $hash => $entry ) {
		if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || ! is_array( $entry ) ) {
			continue;
		}

		$path = isset( $entry['path'] ) ? erankly_health_sanitize_404_path( (string) $entry['path'] ) : '';

		if ( '' === $path ) {
			continue;
		}

		$clean_hash = md5( $path );

		$result['hash_map'][ $hash ] = $clean_hash;

		$normalized = array(
			'path'         => $path,
			'count'        => isset( $entry['count'] ) ? absint( $entry['count'] ) : 0,
			'window_start' => isset( $entry['window_start'] ) ? absint( $entry['window_start'] ) : 0,
			'first_seen'   => isset( $entry['first_seen'] ) ? absint( $entry['first_seen'] ) : 0,
			'last_seen'    => isset( $entry['last_seen'] ) ? absint( $entry['last_seen'] ) : 0,
			'referrers'    => isset( $entry['referrers'] ) ? erankly_health_sanitize_referrers( $entry['referrers'] ) : array(),
		);

		$result['entries'][ $clean_hash ] = isset( $result['entries'][ $clean_hash ] )
			? erankly_health_merge_404_entries( $result['entries'][ $clean_hash ], $normalized )
			: $normalized;
	}

	return $result;
}

/**
 * Merges entries that collapse to the same anonymized path.
 *
 * Re-keying prevents historical raw token hashes from keeping parallel rows
 * after the anonymizer becomes stricter.
 *
 * @param array<string,mixed> $current  Existing normalized entry.
 * @param array<string,mixed> $incoming Additional normalized entry.
 * @return array<string,mixed>
 */
function erankly_health_merge_404_entries( array $current, array $incoming ): array {
	$first_seen   = array_filter( array( absint( $current['first_seen'] ?? 0 ), absint( $incoming['first_seen'] ?? 0 ) ) );
	$window_start = array_filter( array( absint( $current['window_start'] ?? 0 ), absint( $incoming['window_start'] ?? 0 ) ) );
	$referrers    = isset( $current['referrers'] ) && is_array( $current['referrers'] ) ? $current['referrers'] : array();

	foreach ( (array) ( $incoming['referrers'] ?? array() ) as $path => $count ) {
		$referrers[ $path ] = absint( $referrers[ $path ] ?? 0 ) + absint( $count );
	}

	return array(
		'path'         => (string) ( $current['path'] ?? $incoming['path'] ?? '' ),
		'count'        => absint( $current['count'] ?? 0 ) + absint( $incoming['count'] ?? 0 ),
		'window_start' => empty( $window_start ) ? 0 : min( $window_start ),
		'first_seen'   => empty( $first_seen ) ? 0 : min( $first_seen ),
		'last_seen'    => max( absint( $current['last_seen'] ?? 0 ), absint( $incoming['last_seen'] ?? 0 ) ),
		'referrers'    => erankly_health_sanitize_referrers( $referrers ),
	);
}

/**
 * Keeps the most recent manual state when anonymized paths collapse together.
 *
 * A resolved state wins a timestamp tie because it records a more definitive
 * administrator decision than ignored.
 *
 * @param array<string,mixed> $current  State already assigned to the new hash.
 * @param array<string,mixed> $incoming Additional state being migrated.
 * @return array<string,mixed>
 */
function erankly_health_merge_404_states( array $current, array $incoming ): array {
	$current_time  = absint( $current['updated_at'] ?? 0 );
	$incoming_time = absint( $incoming['updated_at'] ?? 0 );

	if ( $incoming_time > $current_time ) {
		return $incoming;
	}
	if ( $incoming_time < $current_time ) {
		return $current;
	}

	return 'resolved' === (string) ( $incoming['status'] ?? '' ) ? $incoming : $current;
}

/**
 * Validates raw manual states without reading persistent storage.
 *
 * @param mixed $states Raw state option value.
 * @return array<string,array{status:string,updated_at:int}>
 */
function erankly_health_normalize_404_states( $states ): array {
	if ( ! is_array( $states ) ) {
		return array();
	}

	$clean = array();

	foreach ( $states as $hash => $state ) {
		if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || ! is_array( $state ) ) {
			continue;
		}

		$status = isset( $state['status'] ) ? (string) $state['status'] : '';

		if ( ! in_array( $status, array( 'ignored', 'resolved' ), true ) ) {
			continue;
		}

		$clean[ $hash ] = array(
			'status'     => $status,
			'updated_at' => isset( $state['updated_at'] ) ? absint( $state['updated_at'] ) : 0,
		);
	}

	return $clean;
}

/**
 * Moves manual states to normalized entry hashes and discards only stale ones.
 *
 * @param mixed                $states      Raw stored states.
 * @param array<string,string> $hash_map    Old entry hash => normalized hash.
 * @param array<string,bool>   $live_hashes Set of normalized hashes still live.
 * @return array<string,array{status:string,updated_at:int}>
 */
function erankly_health_migrate_404_states_to_normalized_hashes( $states, array $hash_map, array $live_hashes ): array {
	$migrated = array();

	foreach ( erankly_health_normalize_404_states( $states ) as $hash => $state ) {
		$new_hash = $hash_map[ $hash ] ?? $hash;

		if ( ! isset( $live_hashes[ $new_hash ] ) ) {
			continue;
		}

		$migrated[ $new_hash ] = isset( $migrated[ $new_hash ] )
			? erankly_health_merge_404_states( $migrated[ $new_hash ], $state )
			: $state;
	}

	return $migrated;
}

/**
 * Persists an option and verifies exact at-rest equality when WordPress returns
 * false (which also happens for a legitimate no-op update).
 *
 * @param string $option Option name.
 * @param mixed  $value  Desired value.
 * @return bool
 */
function erankly_health_update_option_exact( string $option, $value ): bool {
	if ( update_option( $option, $value, false ) ) {
		return true;
	}

	$missing = new stdClass();
	$stored  = get_option( $option, $missing );

	return $missing !== $stored && $value === $stored;
}

/**
 * Atomically orders the one-time 404 storage migration across requests.
 *
 * The unique option insert acts as a database lock. A five-minute timeout lets
 * a later request recover from a PHP process that died without releasing it.
 *
 * @return bool Whether this request owns the migration or it is already done.
 */
function erankly_health_acquire_404_storage_lock(): bool {
	$now = time();

	if ( add_option( ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION, $now, '', false ) ) {
		return true;
	}

	$started = absint( get_option( ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION, 0 ) );

	if ( $started > 0 && $now - $started > 300 ) {
		delete_option( ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION );

		return add_option( ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION, $now, '', false );
	}

	return false;
}

/**
 * Brings candidates, frequent entries and their manual states to the current
 * anonymized hash schema without ever exposing a half-migrated relationship.
 *
 * States are persisted first. Entry options follow only after exact state
 * persistence is confirmed, and the version marker is always written last.
 * Re-running any interrupted step is safe because normalization and collision
 * resolution are deterministic.
 *
 * @return bool Whether storage is current and callers may safely write entries.
 */
function erankly_health_ensure_404_storage_current(): bool {
	if ( absint( get_option( ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION, 0 ) ) >= ERANKLY_HEALTH_404_STORAGE_VERSION ) {
		return true;
	}

	if ( ! erankly_health_acquire_404_storage_lock() ) {
		return absint( get_option( ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION, 0 ) ) >= ERANKLY_HEALTH_404_STORAGE_VERSION;
	}

	try {
		// Another request may have completed while this one was acquiring the lock.
		if ( absint( get_option( ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION, 0 ) ) >= ERANKLY_HEALTH_404_STORAGE_VERSION ) {
			return true;
		}

		$raw_candidates = get_option( ERANKLY_HEALTH_404_CANDIDATES_OPTION, array() );
		$raw_frequent   = get_option( ERANKLY_HEALTH_404_FREQUENT_OPTION, array() );
		$raw_states     = get_option( ERANKLY_HEALTH_404_STATES_OPTION, array() );
		$candidate_data = erankly_health_normalize_404_entries_with_map( $raw_candidates );
		$frequent_data  = erankly_health_normalize_404_entries_with_map( $raw_frequent );
		$candidates     = $candidate_data['entries'];
		$frequent       = $frequent_data['entries'];

		// A normalized path must not occupy both collections. If either historical
		// row was already frequent, keep the merged aggregate in that collection.
		foreach ( array_intersect_key( $candidates, $frequent ) as $hash => $entry ) {
			$frequent[ $hash ] = erankly_health_merge_404_entries( $frequent[ $hash ], $entry );
			unset( $candidates[ $hash ] );
		}

		// Candidate collisions can cross the frequency threshold during migration.
		foreach ( $candidates as $hash => $entry ) {
			if ( absint( $entry['count'] ?? 0 ) >= ERANKLY_HEALTH_404_THRESHOLD ) {
				$frequent[ $hash ] = isset( $frequent[ $hash ] )
					? erankly_health_merge_404_entries( $frequent[ $hash ], $entry )
					: $entry;
				unset( $candidates[ $hash ] );
			}
		}

		$hash_map    = array_merge( $candidate_data['hash_map'], $frequent_data['hash_map'] );
		$live_hashes = array_fill_keys( array_keys( $candidates + $frequent ), true );
		$states      = erankly_health_migrate_404_states_to_normalized_hashes( $raw_states, $hash_map, $live_hashes );

		if ( ! erankly_health_update_option_exact( ERANKLY_HEALTH_404_STATES_OPTION, $states ) ) {
			return false;
		}
		if ( ! erankly_health_update_option_exact( ERANKLY_HEALTH_404_CANDIDATES_OPTION, $candidates ) ) {
			return false;
		}
		if ( ! erankly_health_update_option_exact( ERANKLY_HEALTH_404_FREQUENT_OPTION, $frequent ) ) {
			return false;
		}

		return erankly_health_update_option_exact( ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION, ERANKLY_HEALTH_404_STORAGE_VERSION );
	} finally {
		delete_option( ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION );
	}
}

/**
 * Validates a stored referrer map: anonymized path => positive count, capped.
 *
 * @param mixed $referrers Raw stored value.
 * @return array<string,int>
 */
function erankly_health_sanitize_referrers( $referrers ): array {
	if ( ! is_array( $referrers ) ) {
		return array();
	}

	$clean = array();

	foreach ( $referrers as $ref_path => $count ) {
		$ref_path = erankly_health_sanitize_404_path( (string) $ref_path );

		if ( '' === $ref_path ) {
			continue;
		}

		$clean[ $ref_path ] = ( isset( $clean[ $ref_path ] ) ? $clean[ $ref_path ] : 0 ) + absint( $count );
	}

	$max = max( 1, (int) apply_filters( 'erankly_health_max_referrers', ERANKLY_HEALTH_404_MAX_REFERRERS ) );

	if ( count( $clean ) > $max ) {
		arsort( $clean );
		$clean = array_slice( $clean, 0, $max, true );
	}

	return $clean;
}

/**
 * Sanitizes and anonymizes a stored 404 path.
 *
 * Path segments that look like personal data (emails, UUIDs, long numbers, opaque
 * tokens) are replaced with neutral placeholders before storage. This is a
 * best-effort heuristic that strips the most common identifiers; it reduces, but
 * does not guarantee the absence of, personal data in the stored path.
 *
 * @param string $path Request path.
 * @return string Anonymized, sanitized path.
 */
function erankly_health_sanitize_404_path( string $path ): string {
	// Classify token punctuation before sanitize_text_field(), which deliberately
	// strips percent-encoded octets and can turn an opaque value into a short,
	// apparently harmless fragment. The final value is still text-sanitized.
	$path = wp_strip_all_tags( wp_unslash( $path ), true );
	$path = preg_replace( '#/+#', '/', $path );
	$path = is_string( $path ) ? $path : '';

	if ( '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );
	$path = '/' === $path ? $path : untrailingslashit( $path );

	return sanitize_text_field( erankly_health_anonymize_path_segments( $path ) );
}

/**
 * Replaces path segments that resemble personal data with neutral placeholders.
 *
 * Targets:
 * - Email addresses (URL-encoded or literal) → [email]
 * - UUIDs / GUIDs (8-4-4-4-12 hex) → [id]
 * - Long numeric strings ≥ 8 digits (phone numbers, user IDs) → [n]
 * - Opaque tokens, including token-like mixed-class values as short as 8 chars → [token]
 *
 * The replacement is irreversible: a placeholder discards the original segment,
 * so a replaced value cannot be recovered from the stored path. It is a
 * best-effort filter for the common identifier shapes above, not a guarantee
 * that no identifying data ever remains.
 *
 * @param string $path Normalized path beginning with /.
 * @return string Anonymized path, capped at ERANKLY_HEALTH_PATH_MAX_LENGTH chars.
 */
function erankly_health_anonymize_path_segments( string $path ): string {
	$segments = explode( '/', $path );

	foreach ( $segments as $index => &$segment ) {
		if ( '' === $segment ) {
			continue;
		}
		$decoded          = rawurldecode( $segment );
		$previous_segment = $index > 0 ? strtolower( rawurldecode( (string) $segments[ $index - 1 ] ) ) : '';
		$sensitive_route  = in_array( $previous_segment, array( 'reset', 'verify', 'activate', 'invite', 'magic-link', 'token' ), true );

		// Email addresses (URL-encoded or literal).
		if ( preg_match( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $decoded ) ) {
			$segment = '[email]';
			continue;
		}

		// UUID / GUID: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $decoded ) ) {
			$segment = '[id]';
			continue;
		}

		// Long numeric strings (≥ 8 digits), likely user/order IDs or phone numbers.
		if ( preg_match( '/^\d{8,}$/', $decoded ) ) {
			$segment = '[n]';
			continue;
		}

		if ( erankly_health_path_segment_is_opaque_token( $decoded, $sensitive_route ) ) {
			$segment = '[token]';
			continue;
		}

		// Usernames. Each uncached check costs up to two user queries on an
		// anonymous 404 request, so cap the lookups per request: long crafted
		// paths must not be able to amplify database load.
		static $checked_users = array();
		static $user_lookups  = 0;
		if ( ! isset( $checked_users[ $decoded ] ) ) {
			if ( $user_lookups >= 5 ) {
				continue;
			}
			++$user_lookups;
			$checked_users[ $decoded ] = get_user_by( 'slug', $decoded ) || get_user_by( 'login', $decoded );
		}
		if ( $checked_users[ $decoded ] ) {
			$segment = '[user]';
			continue;
		}
	}

	unset( $segment );

	$anonymized = implode( '/', $segments );

	// Hard cap to prevent oversized option values.
	if ( strlen( $anonymized ) > ERANKLY_HEALTH_PATH_MAX_LENGTH ) {
		$anonymized = substr( $anonymized, 0, ERANKLY_HEALTH_PATH_MAX_LENGTH );
	}

	return $anonymized;
}

/**
 * Determines whether a decoded path segment resembles a secret or opaque ID.
 *
 * Hyphenated lowercase slugs remain readable for 404 diagnostics. Compact
 * values are redacted when they are very long, hash-like, vowel-free, or mix
 * token character classes such as case, digits, underscores, dots, or tildes.
 *
 * @param string $segment           Decoded path segment.
 * @param bool   $sensitive_context Whether it follows a reset/verification route.
 * @return bool
 */
function erankly_health_path_segment_is_opaque_token( string $segment, bool $sensitive_context = false ): bool {
	$length = strlen( $segment );

	if ( $length < 8 || ! preg_match( '/^[a-zA-Z0-9_\-\.~%+=]+$/', $segment ) ) {
		return false;
	}

	if ( $length >= 40 || preg_match( '/^[a-f0-9]+$/i', $segment ) ) {
		return true;
	}

	$has_hyphen = false !== strpos( $segment, '-' );

	// Preserve clear lowercase word slugs, optionally hyphenated or ending in a
	// four-digit year. A single 24+ character alphabetic run retains the older
	// privacy-first treatment because it is indistinguishable from an opaque ID.
	if (
		preg_match( '/^[a-z]+(?:-[a-z]+)*(?:-(?:19|20)\d{2})?$/', $segment )
		&& ( $has_hyphen || $length < 24 )
	) {
		return false;
	}

	if ( ! preg_match( '/[aeiouy]/i', $segment ) ) {
		return true;
	}

	$has_upper           = (bool) preg_match( '/[A-Z]/', $segment );
	$has_lower           = (bool) preg_match( '/[a-z]/', $segment );
	$has_digit           = (bool) preg_match( '/\d/', $segment );
	$has_token_punct     = (bool) preg_match( '/[_\.~%+=]/', $segment );
	$class_count         = (int) $has_upper + (int) $has_lower + (int) $has_digit + (int) $has_token_punct;
	$digit_count         = preg_match_all( '/\d/', $segment );
	$digit_count         = false === $digit_count ? 0 : $digit_count;
	$digit_density       = $digit_count / $length;
	$case_transitions    = preg_match_all( '/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[a-z])/', $segment );
	$case_transitions    = false === $case_transitions ? 0 : $case_transitions;
	$hyphen_digit_groups = 0;

	if ( $has_hyphen ) {
		foreach ( explode( '-', $segment ) as $group ) {
			if ( preg_match( '/\d/', $group ) ) {
				++$hyphen_digit_groups;
			}
		}
	}

	if (
		$sensitive_context
		&& ( $has_digit || $has_token_punct || $has_upper || $case_transitions >= 2 )
	) {
		return true;
	}

	if ( $length <= 15 ) {
		return ( $has_upper && $has_lower && $has_digit )
			|| $case_transitions >= 3
			|| ( $has_token_punct && $class_count >= 2 )
			|| $hyphen_digit_groups >= 2
			|| ( $has_hyphen && $has_digit && $digit_density >= 0.3 )
			|| ( ! $has_hyphen && $has_digit && ( $has_upper || $has_lower ) && $digit_count >= 3 && $digit_density >= 0.25 );
	}

	if ( $length <= 39 ) {
		return $class_count >= 2
			|| $case_transitions >= 3
			|| $hyphen_digit_groups >= 2
			|| ( $has_hyphen && $has_digit && $digit_density >= 0.2 )
			|| ( $length >= 24 && ! $has_hyphen );
	}

	return $length >= 24 && ! $has_hyphen;
}

/**
 * Keeps the newest aggregate entries within a fixed cap.
 *
 * @param array<string,array<string,int|string>> $entries Entries.
 * @param int                                    $max     Maximum entries.
 * @return array<string,array<string,int|string>>
 */
function erankly_health_prune_404_entries( array $entries, int $max ): array {
	uasort(
		$entries,
		static function ( array $a, array $b ): int {
			return absint( $b['last_seen'] ?? 0 ) <=> absint( $a['last_seen'] ?? 0 );
		}
	);

	return array_slice( $entries, 0, max( 1, $max ), true );
}

/**
 * Removes 404 aggregate entries that are older than the retention window.
 *
 * Fired daily by ERANKLY_HEALTH_404_PRUNE_HOOK. Removes any entry whose
 * last_seen timestamp is outside the retention period, keeping the option size
 * bounded independently of the max-entries cap.
 *
 * @return void
 */
function erankly_health_prune_stale_404_data(): void {
	if ( ! erankly_health_ensure_404_storage_current() ) {
		return;
	}

	$cutoff  = time() - ( ERANKLY_HEALTH_404_RETENTION_DAYS * DAY_IN_SECONDS );
	$options = array(
		ERANKLY_HEALTH_404_CANDIDATES_OPTION,
		ERANKLY_HEALTH_404_FREQUENT_OPTION,
	);

	foreach ( $options as $option ) {
		$stored  = get_option( $option, array() );
		$entries = erankly_health_get_404_entries( $option );
		$pruned  = array();

		foreach ( $entries as $hash => $entry ) {
			if ( absint( $entry['last_seen'] ) >= $cutoff ) {
				$pruned[ $hash ] = $entry;
			}
		}

		if ( ! is_array( $stored ) || $pruned !== $stored ) {
			update_option( $option, $pruned, false );
		}
	}

	// Drop manual states only after the hash migration is known to be complete.
	$states = erankly_health_get_404_states();

	if ( ! empty( $states ) ) {
		$live = erankly_health_get_404_entries( ERANKLY_HEALTH_404_FREQUENT_OPTION )
			+ erankly_health_get_404_entries( ERANKLY_HEALTH_404_CANDIDATES_OPTION );
		$kept = array();

		$kept = array_intersect_key( $states, $live );

		if ( $kept !== $states ) {
			update_option( ERANKLY_HEALTH_404_STATES_OPTION, $kept, false );
		}
	}

	// AI suggestions are durable; remove them when their target is gone or their
	// historical source path now matches a sensitive-data pattern.
	if ( function_exists( 'erankly_health_prune_stored_ai_suggestions' ) ) {
		erankly_health_prune_stored_ai_suggestions();
	}
}





/**
 * Returns frequent 404 entries for the active monitoring window.
 *
 * @return array<string,array<string,int|string>>
 */
function erankly_health_get_frequent_404s(): array {
	if ( ! erankly_health_ensure_404_storage_current() ) {
		return array();
	}

	$now      = time();
	$entries  = erankly_health_get_404_entries( ERANKLY_HEALTH_404_FREQUENT_OPTION );
	$frequent = array();

	foreach ( $entries as $hash => $entry ) {
		if (
			absint( $entry['count'] ) >= ERANKLY_HEALTH_404_THRESHOLD
			&& $now - absint( $entry['window_start'] ) < ERANKLY_HEALTH_404_WINDOW
		) {
			$frequent[ $hash ] = $entry;
		}
	}

	uasort(
		$frequent,
		static function ( array $a, array $b ): int {
			$count_compare = absint( $b['count'] ?? 0 ) <=> absint( $a['count'] ?? 0 );

			if ( 0 !== $count_compare ) {
				return $count_compare;
			}

			return absint( $b['last_seen'] ?? 0 ) <=> absint( $a['last_seen'] ?? 0 );
		}
	);

	return $frequent;
}

/**
 * Deletes all stored frequent 404 scanner data.
 *
 * @return void
 */
function erankly_health_clear_404s(): void {
	delete_option( ERANKLY_HEALTH_404_CANDIDATES_OPTION );
	delete_option( ERANKLY_HEALTH_404_FREQUENT_OPTION );
	delete_option( ERANKLY_HEALTH_404_STATES_OPTION );
}

/**
 * Returns the manual 404 resolution states ( hash => ['status','updated_at'] ).
 *
 * @return array<string,array<string,int|string>>
 */
function erankly_health_get_404_states(): array {
	return erankly_health_normalize_404_states( get_option( ERANKLY_HEALTH_404_STATES_OPTION, array() ) );
}

/**
 * Sets or clears a manual 404 resolution state.
 *
 * @param string $hash   md5 hash of the 404 path (the frequent-entry key).
 * @param string $status 'ignored'|'resolved' to set, or 'active' to clear.
 * @return void
 */
function erankly_health_set_404_state( string $hash, string $status ): void {
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
		return;
	}
	if ( ! erankly_health_ensure_404_storage_current() ) {
		return;
	}

	$states = erankly_health_get_404_states();

	if ( 'active' === $status ) {
		unset( $states[ $hash ] );
	} elseif ( in_array( $status, array( 'ignored', 'resolved' ), true ) ) {
		$states[ $hash ] = array(
			'status'     => $status,
			'updated_at' => time(),
		);
	} else {
		return;
	}

	$max = ERANKLY_HEALTH_404_MAX_FREQUENT + ERANKLY_HEALTH_404_MAX_CANDIDATES;

	if ( count( $states ) > $max ) {
		uasort(
			$states,
			static function ( array $a, array $b ): int {
				return absint( $b['updated_at'] ?? 0 ) <=> absint( $a['updated_at'] ?? 0 );
			}
		);
		$states = array_slice( $states, 0, $max, true );
	}

	update_option( ERANKLY_HEALTH_404_STATES_OPTION, $states, false );
}

/**
 * Handles the admin request that sets a manual 404 resolution state.
 *
 * @return void
 */
function erankly_health_handle_set_404_state(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_404_state' );

	$hash   = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
	$status = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';

	if ( in_array( $status, array( 'ignored', 'resolved', 'active' ), true ) ) {
		erankly_health_set_404_state( $hash, $status );
	}

	$notice = 'ignored' === $status ? 'ignored' : ( 'resolved' === $status ? 'resolved' : 'restored' );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                 => 'erankly',
				'erankly_tab'          => 'health',
				'erankly_health_state' => $notice,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Handles the admin request that clears frequent 404 scanner data.
 *
 * @return void
 */
function erankly_health_handle_clear_404s(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to clear Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_clear_404s' );
	erankly_health_clear_404s();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                 => 'erankly',
				'erankly_tab'          => 'health',
				'erankly_health_clear' => '1',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Formats a stored timestamp for the admin UI.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function erankly_health_format_timestamp( int $timestamp ): string {
	if ( $timestamp <= 0 ) {
		return '';
	}

	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/*
 * ---------------------------------------------------------------------------
 * Operational Health: 404 → redirect suggestions.
 *
 * Given a recorded 404 entry, suggest the most likely current URL so an admin
 * can turn it into a redirect in one step. Lookups are read-only (no writes)
 * and cached per path. Anonymized paths (containing [id]/[email]/… placeholders)
 * are never matched: their literal form is not a real, resolvable URL.
 * ---------------------------------------------------------------------------
 */

/**
 * Whether a stored 404 path contains anonymization placeholders and therefore
 * cannot be offered as a one-click redirect source/suggestion.
 *
 * @param string $path Stored 404 path.
 * @return bool
 */
function erankly_health_path_is_anonymized( string $path ): bool {
	return (bool) preg_match( '/\[(?:email|id|n|token|user)\]/', $path );
}

/**
 * Extracts the normalized last path segment ("slug") from a 404 path.
 *
 * @param string $path Stored 404 path.
 * @return string Sanitized slug, or '' when none.
 */
function erankly_health_404_slug_from_path( string $path ): string {
	$path = trim( $path, '/' );

	if ( '' === $path ) {
		return '';
	}

	$segments = explode( '/', $path );

	return sanitize_title( (string) end( $segments ) );
}

/**
 * Returns a site-relative path (leading slash, no host/fragment) for a URL.
 *
 * @param string $url Absolute or relative URL.
 * @return string
 */
function erankly_health_url_to_relative( string $url ): string {
	$relative = (string) wp_make_link_relative( $url );
	$relative = strtok( $relative, '#' );
	$relative = is_string( $relative ) ? $relative : '';

	return '' === $relative ? '' : '/' . ltrim( $relative, '/' );
}

/**
 * Returns the site-relative permalink for a published post, or '' on failure.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_health_permalink_path( int $post_id ): string {
	$url = get_permalink( $post_id );

	return is_string( $url ) && '' !== $url ? erankly_health_url_to_relative( $url ) : '';
}

/**
 * Normalized text similarity in the 0..1 range.
 *
 * @param string $a First string.
 * @param string $b Second string.
 * @return float
 */
function erankly_health_similarity( string $a, string $b ): float {
	if ( '' === $a || '' === $b ) {
		return 0.0;
	}

	if ( $a === $b ) {
		return 1.0;
	}

	$percent = 0.0;
	similar_text( $a, $b, $percent );

	return $percent / 100;
}

/**
 * Builds a suggestion payload consumed by the Health panel.
 *
 * @param string $target     Site-relative target path.
 * @param string $confidence high|medium|low.
 * @param string $reason     Machine reason (old_slug|exact_slug|term|fuzzy).
 * @param string $title      Target title for display.
 * @return array<string,string>
 */
function erankly_health_build_suggestion( string $target, string $confidence, string $reason, string $title = '' ): array {
	$labels = array(
		'old_slug'   => __( 'Previous URL of this page', 'easyrankly' ),
		'exact_slug' => __( 'Matches an existing page slug', 'easyrankly' ),
		'term'       => __( 'Matches a category/tag archive', 'easyrankly' ),
		'fuzzy'      => __( 'Closest matching page', 'easyrankly' ),
		'ai'         => __( 'AI: related topic', 'easyrankly' ),
	);

	return array(
		'target'     => $target,
		'confidence' => $confidence,
		'reason'     => $reason,
		'label'      => $labels[ $reason ] ?? __( 'Suggested target', 'easyrankly' ),
		'title'      => $title,
	);
}

/**
 * Matches a 404 slug against WordPress' stored previous slugs (_wp_old_slug).
 *
 * @param string $slug Normalized 404 slug.
 * @return array<string,string>|null
 */
function erankly_health_match_old_slug( string $slug ): ?array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core tables; the meta value is a prepared placeholder.
	$post_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE m.meta_key = '_wp_old_slug' AND m.meta_value = %s AND p.post_status = 'publish'
			ORDER BY p.post_modified DESC LIMIT 1",
			$slug
		)
	);

	if ( $post_id <= 0 ) {
		return null;
	}

	$target = erankly_health_permalink_path( $post_id );

	return '' === $target ? null : erankly_health_build_suggestion( $target, 'high', 'old_slug', (string) get_the_title( $post_id ) );
}

/**
 * Matches a 404 path against current published content/terms with the same slug.
 *
 * @param string $path Stored 404 path.
 * @param string $slug Normalized last-segment slug.
 * @return array<string,string>|null
 */
function erankly_health_match_exact_slug( string $path, string $slug ): ?array {
	$post_types = array_keys( erankly_get_public_post_types() );

	// Hierarchical / full-path match (pages and hierarchical CPTs).
	$clean_path = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', trim( $path, '/' ) ) ) ) );
	$page       = '' !== $clean_path ? get_page_by_path( $clean_path, OBJECT, $post_types ) : null;

	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		$target = erankly_health_permalink_path( (int) $page->ID );

		if ( '' !== $target ) {
			return erankly_health_build_suggestion( $target, 'high', 'exact_slug', (string) get_the_title( $page ) );
		}
	}

	if ( '' === $slug ) {
		return null;
	}

	// Flat match on the last segment (posts and non-hierarchical CPTs).
	$posts = get_posts(
		array(
			'name'             => $slug,
			'post_type'        => $post_types,
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'suppress_filters' => false,
		)
	);

	if ( ! empty( $posts ) ) {
		$target = erankly_health_permalink_path( (int) $posts[0]->ID );

		if ( '' !== $target ) {
			return erankly_health_build_suggestion( $target, 'high', 'exact_slug', (string) get_the_title( $posts[0] ) );
		}
	}

	// Term archives (categories, tags, public custom taxonomies).
	foreach ( array_keys( erankly_get_public_taxonomies() ) as $taxonomy ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );

		if ( $term instanceof WP_Term ) {
			$link = get_term_link( $term );

			if ( ! is_wp_error( $link ) ) {
				$target = erankly_health_url_to_relative( (string) $link );

				if ( '' !== $target ) {
					return erankly_health_build_suggestion( $target, 'medium', 'term', (string) $term->name );
				}
			}
		}
	}

	return null;
}

/**
 * Returns the normalized last-segment slugs of a 404 entry's internal referrers.
 *
 * @param array<string,mixed> $entry 404 entry.
 * @return array<int,string>
 */
function erankly_health_referrer_tail_segments( array $entry ): array {
	$referrers = isset( $entry['referrers'] ) && is_array( $entry['referrers'] ) ? array_keys( $entry['referrers'] ) : array();
	$tails     = array();

	foreach ( $referrers as $referrer ) {
		$referrer = trim( (string) $referrer, '/' );

		if ( '' === $referrer ) {
			continue;
		}

		$segments = explode( '/', $referrer );
		$tail     = sanitize_title( (string) end( $segments ) );

		if ( '' !== $tail ) {
			$tails[] = $tail;
		}
	}

	return array_values( array_unique( $tails ) );
}

/**
 * Finds the most similar published slug/title for a 404 slug.
 *
 * @param string              $slug  Normalized 404 slug.
 * @param array<string,mixed> $entry Full 404 entry (referrers may refine ranking later).
 * @return array<string,string>|null
 */
function erankly_health_match_fuzzy( string $slug, array $entry ): ?array {
	global $wpdb;

	if ( '' === $slug ) {
		return null;
	}

	$post_types = array_keys( erankly_get_public_post_types() );

	if ( empty( $post_types ) ) {
		return null;
	}

	$limit        = max( 1, (int) apply_filters( 'erankly_health_suggestion_candidate_limit', ERANKLY_HEALTH_SUGGESTION_CANDIDATE_LIMIT ) );
	$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
	$args         = array_merge( $post_types, array( $limit ) );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table lookup for bounded redirect suggestion candidates.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The placeholder list is generated from public post types and each value is bound via prepare().
		$wpdb->prepare(
			"SELECT ID, post_name, post_title FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_name <> '' AND post_type IN ($placeholders)
				ORDER BY post_modified DESC LIMIT %d",
			$args
		)
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	$best_id    = 0;
	$best_ratio = 0.0;
	$ref_tails  = erankly_health_referrer_tail_segments( $entry );

	foreach ( (array) $rows as $row ) {
		$candidate_title = sanitize_title( (string) $row->post_title );
		$ratio           = max(
			erankly_health_similarity( $slug, (string) $row->post_name ),
			'' !== $candidate_title ? erankly_health_similarity( $slug, $candidate_title ) : 0.0
		);

		// Light tie-breaker: nudge a candidate whose slug equals the last segment of an
		// internal referrer (the page that links to the dead URL).
		if ( ! empty( $ref_tails ) && in_array( (string) $row->post_name, $ref_tails, true ) ) {
			$ratio = min( 1.0, $ratio + 0.05 );
		}

		if ( $ratio > $best_ratio ) {
			$best_ratio = $ratio;
			$best_id    = (int) $row->ID;
		}
	}

	$min_ratio = (float) apply_filters( 'erankly_health_suggestion_min_ratio', ERANKLY_HEALTH_SUGGESTION_MIN_RATIO );

	if ( $best_id <= 0 || $best_ratio < $min_ratio ) {
		return null;
	}

	$target = erankly_health_permalink_path( $best_id );

	if ( '' === $target ) {
		return null;
	}

	return erankly_health_build_suggestion( $target, $best_ratio >= 0.9 ? 'high' : 'medium', 'fuzzy', (string) get_the_title( $best_id ) );
}

/**
 * Computes a redirect suggestion for a 404 entry (no caching).
 *
 * Tries, in order: a previous WordPress slug, an exact current slug/term, then
 * the closest fuzzy slug/title match.
 *
 * @param array<string,mixed> $entry 404 entry.
 * @param string              $path  Stored 404 path.
 * @return array<string,string>|null
 */
