<?php
/**
 * Health module.
 *
 * This file is required only when the Health feature is enabled.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERANKLY_HEALTH_404_THRESHOLD', 10 );
define( 'ERANKLY_HEALTH_404_WINDOW', DAY_IN_SECONDS );
define( 'ERANKLY_HEALTH_404_MAX_CANDIDATES', 200 );
define( 'ERANKLY_HEALTH_404_MAX_FREQUENT', 100 );
/** Maximum distinct internal referrers stored per 404 entry. */
define( 'ERANKLY_HEALTH_404_MAX_REFERRERS', 5 );
define( 'ERANKLY_HEALTH_404_CANDIDATES_OPTION', 'erankly_health_404_candidates' );
define( 'ERANKLY_HEALTH_404_FREQUENT_OPTION', 'erankly_health_404_frequent' );
/** Stores manual 404 resolution states ( hash => ignored|resolved ). */
define( 'ERANKLY_HEALTH_404_STATES_OPTION', 'erankly_health_404_states' );

define( 'ERANKLY_HEALTH_THIN_MIN_CHARS', 300 );
define( 'ERANKLY_HEALTH_THIN_MAX_RESULTS', 100 );
define( 'ERANKLY_HEALTH_THIN_OPTION', 'erankly_health_thin_content' );
/** Number of posts whose post_content is loaded per batch during the thin-content scan. */
define( 'ERANKLY_HEALTH_THIN_SCAN_BATCH', 200 );

/** Number of days aggregate 404 data is kept before the retention cron removes it. */
define( 'ERANKLY_HEALTH_404_RETENTION_DAYS', 30 );
/** WP-Cron hook name for the daily 404 data retention sweep. */
define( 'ERANKLY_HEALTH_404_PRUNE_HOOK', 'erankly_health_prune_404_cron' );
/** Hard cap on the length of a stored path after anonymization (characters). */
define( 'ERANKLY_HEALTH_PATH_MAX_LENGTH', 255 );

/** Transient key prefix for cached 404 → redirect suggestions. */
define( 'ERANKLY_HEALTH_SUGGESTION_PREFIX', 'erankly_health_sugg_' );
/** Minimum text similarity (0..1) for a fuzzy slug/title suggestion to be offered. */
define( 'ERANKLY_HEALTH_SUGGESTION_MIN_RATIO', 0.8 );
/** Maximum published rows scanned when looking for a fuzzy suggestion. */
define( 'ERANKLY_HEALTH_SUGGESTION_CANDIDATE_LIMIT', 2000 );
/** Transient key prefix for cached AI (semantic) 404 → redirect suggestions. */
define( 'ERANKLY_HEALTH_AI_SUGGESTION_PREFIX', 'erankly_health_aisugg_' );

/*
 * Broken-Link Candidates crawler.
 *
 * A manually-started crawl that seeds from the site's indexable URLs, spiders
 * internal links up to a bounded depth, and records every discovered link (with
 * anchor text and the page it was found on) so their HTTP status can be checked.
 * The run is driven in small batches from the admin via REST to stay within PHP
 * time limits; all crawl state lives in a single no-autoload option.
 */
/** Option holding the in-progress crawl state (queue, visited pages, link map). */
define( 'ERANKLY_HEALTH_BL_STATE_OPTION', 'erankly_health_bl_state' );
/** Option holding the finished crawl results (broken links + occurrences). */
define( 'ERANKLY_HEALTH_BL_RESULTS_OPTION', 'erankly_health_bl_results' );
/** Hard cap on the number of pages fetched during one crawl (seeds + spidered). */
define( 'ERANKLY_HEALTH_BL_MAX_PAGES', 300 );
/** Maximum spider depth followed from the seed set (0 = seeds only). */
define( 'ERANKLY_HEALTH_BL_MAX_DEPTH', 3 );
/** Hard cap on distinct links tracked (and therefore HTTP-checked) per crawl. */
define( 'ERANKLY_HEALTH_BL_MAX_LINKS', 3000 );
/** Maximum broken-link rows stored in the final results. */
define( 'ERANKLY_HEALTH_BL_MAX_RESULTS', 200 );
/** Per-request HTTP timeout (seconds) for page fetches and link status checks. */
define( 'ERANKLY_HEALTH_BL_HTTP_TIMEOUT', 8 );
/** Pages fetched (and parsed) per discovery batch/tick. */
define( 'ERANKLY_HEALTH_BL_FETCH_BATCH', 8 );
/** Links status-checked per checking batch/tick. */
define( 'ERANKLY_HEALTH_BL_CHECK_BATCH', 25 );
/** Transient key prefix for cached per-URL HTTP status codes. */
define( 'ERANKLY_HEALTH_BL_CACHE_PREFIX', 'erankly_health_bl_st_' );
/** Cache TTL for internal URL status checks. */
define( 'ERANKLY_HEALTH_BL_CACHE_TTL_INTERNAL', HOUR_IN_SECONDS );
/** Cache TTL for external URL status checks. */
define( 'ERANKLY_HEALTH_BL_CACHE_TTL_EXTERNAL', 12 * HOUR_IN_SECONDS );

/**
 * Registers Health hooks.
 *
 * @return void
 */
function erankly_health_boot(): void {
	add_action( 'template_redirect', 'erankly_health_maybe_record_404', 100 );

	// Daily retention sweep for 404 aggregate data.
	add_action( ERANKLY_HEALTH_404_PRUNE_HOOK, 'erankly_health_prune_stale_404_data' );
	erankly_health_maybe_schedule_retention_cron();

	// REST routes driving the manual Broken-Link Candidates crawl in batches.
	add_action( 'rest_api_init', 'erankly_health_bl_register_rest_routes' );

	// WordPress privacy tools — 404 paths are anonymized and not user-linked, but
	// site admins can initiate a full wipe from the Privacy → Erase Personal Data flow.

	if ( is_admin() ) {
		add_action( 'admin_post_erankly_health_clear_404s', 'erankly_health_handle_clear_404s' );
		add_action( 'admin_post_erankly_health_scan_thin', 'erankly_health_handle_scan_thin' );
		add_action( 'admin_post_erankly_health_404_set_state', 'erankly_health_handle_set_404_state' );
		add_action( 'admin_post_erankly_health_ai_suggest', 'erankly_health_handle_ai_suggest' );
		add_action( 'admin_post_erankly_health_bl_ai_suggest', 'erankly_health_bl_handle_ai_suggest' );
		add_action( 'admin_post_erankly_health_bl_clear', 'erankly_health_bl_handle_clear' );
	}
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

	$path = sanitize_text_field( rawurldecode( $path ) );
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
 * normalized but NOT yet anonymized — anonymization runs inside
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
	$entries = get_option( $option_name, array() );

	if ( ! is_array( $entries ) ) {
		return array();
	}

	$clean = array();

	foreach ( $entries as $hash => $entry ) {
		if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || ! is_array( $entry ) ) {
			continue;
		}

		$path = isset( $entry['path'] ) ? erankly_health_sanitize_404_path( (string) $entry['path'] ) : '';

		if ( '' === $path ) {
			continue;
		}

		$clean[ $hash ] = array(
			'path'         => $path,
			'count'        => isset( $entry['count'] ) ? absint( $entry['count'] ) : 0,
			'window_start' => isset( $entry['window_start'] ) ? absint( $entry['window_start'] ) : 0,
			'first_seen'   => isset( $entry['first_seen'] ) ? absint( $entry['first_seen'] ) : 0,
			'last_seen'    => isset( $entry['last_seen'] ) ? absint( $entry['last_seen'] ) : 0,
			'referrers'    => isset( $entry['referrers'] ) ? erankly_health_sanitize_referrers( $entry['referrers'] ) : array(),
		);
	}

	return $clean;
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
	$path = sanitize_text_field( wp_unslash( $path ) );
	$path = preg_replace( '#/+#', '/', $path );
	$path = is_string( $path ) ? $path : '';

	if ( '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );
	$path = '/' === $path ? $path : untrailingslashit( $path );

	return erankly_health_anonymize_path_segments( $path );
}

/**
 * Replaces path segments that resemble personal data with neutral placeholders.
 *
 * Targets:
 * - Email addresses (URL-encoded or literal) → [email]
 * - UUIDs / GUIDs (8-4-4-4-12 hex) → [id]
 * - Long numeric strings ≥ 8 digits (phone numbers, user IDs) → [n]
 * - Opaque tokens ≥ 40 chars (JWTs, session tokens, base64) → [token]
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

	foreach ( $segments as &$segment ) {
		if ( '' === $segment ) {
			continue;
		}

		// Email addresses (URL-encoded or literal).
		if ( preg_match( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', rawurldecode( $segment ) ) ) {
			$segment = '[email]';
			continue;
		}

		// UUID / GUID: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment ) ) {
			$segment = '[id]';
			continue;
		}

		// Long numeric strings (≥ 8 digits) — likely user/order IDs or phone numbers.
		if ( preg_match( '/^\d{8,}$/', $segment ) ) {
			$segment = '[n]';
			continue;
		}

		// Long opaque tokens (≥ 40 chars) and Short tokens (≥ 8 chars).
		if ( strlen( $segment ) >= 8 && preg_match( '/^[a-zA-Z0-9_\-\.~%]+$/', $segment ) ) {
			// To avoid replacing regular words like 'about' or 'contact', we only target segments
			// that lack vowels (likely hex/hashes) OR are purely numeric OR are very long.
			$decoded_seg = rawurldecode( $segment );
			if ( strlen( $segment ) >= 40 || preg_match( '/^[a-f0-9]+$/i', $segment ) || ! preg_match( '/[aeiouy]/i', $decoded_seg ) ) {
				$segment = '[token]';
				continue;
			}
		}

		// Usernames. Each uncached check costs up to two user queries on an
		// anonymous 404 request, so cap the lookups per request: long crafted
		// paths must not be able to amplify database load.
		static $checked_users = array();
		static $user_lookups  = 0;
		$decoded              = rawurldecode( $segment );
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
 * Schedules the daily 404 retention cron event if not already scheduled.
 *
 * Called from erankly_health_boot() on every request so the schedule is
 * restored automatically after the site clears its cron table.
 *
 * @return void
 */
function erankly_health_maybe_schedule_retention_cron(): void {
	if ( ! wp_next_scheduled( ERANKLY_HEALTH_404_PRUNE_HOOK ) ) {
		wp_schedule_event( time(), 'daily', ERANKLY_HEALTH_404_PRUNE_HOOK );
	}
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
	$cutoff  = time() - ( ERANKLY_HEALTH_404_RETENTION_DAYS * DAY_IN_SECONDS );
	$options = array(
		ERANKLY_HEALTH_404_CANDIDATES_OPTION,
		ERANKLY_HEALTH_404_FREQUENT_OPTION,
	);

	foreach ( $options as $option ) {
		$entries = erankly_health_get_404_entries( $option );
		$pruned  = array();

		foreach ( $entries as $hash => $entry ) {
			if ( absint( $entry['last_seen'] ) >= $cutoff ) {
				$pruned[ $hash ] = $entry;
			}
		}

		if ( count( $pruned ) !== count( $entries ) ) {
			update_option( $option, $pruned, false );
		}
	}

	// Drop manual states whose 404 entry no longer exists.
	$states = erankly_health_get_404_states();

	if ( ! empty( $states ) ) {
		$live = erankly_health_get_404_entries( ERANKLY_HEALTH_404_FREQUENT_OPTION )
			+ erankly_health_get_404_entries( ERANKLY_HEALTH_404_CANDIDATES_OPTION );
		$kept = array_intersect_key( $states, $live );

		if ( count( $kept ) !== count( $states ) ) {
			update_option( ERANKLY_HEALTH_404_STATES_OPTION, $kept, false );
		}
	}
}





/**
 * Returns frequent 404 entries for the active monitoring window.
 *
 * @return array<string,array<string,int|string>>
 */
function erankly_health_get_frequent_404s(): array {
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
	$states = get_option( ERANKLY_HEALTH_404_STATES_OPTION, array() );

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
function erankly_health_compute_redirect_suggestion( array $entry, string $path ): ?array {
	$slug = erankly_health_404_slug_from_path( $path );

	if ( '' === $slug ) {
		return null;
	}

	$old_slug = erankly_health_match_old_slug( $slug );
	if ( null !== $old_slug ) {
		return $old_slug;
	}

	$exact = erankly_health_match_exact_slug( $path, $slug );
	if ( null !== $exact ) {
		return $exact;
	}

	return erankly_health_match_fuzzy( $slug, $entry );
}

/**
 * Suggests the most likely current target for a recorded 404, cached per path.
 *
 * @param array<string,mixed> $entry 404 entry (must contain 'path').
 * @return array<string,string>|null Suggestion payload, or null when none/ineligible.
 */
function erankly_health_suggest_redirect_target( array $entry ): ?array {
	$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';

	if ( '' === $path || erankly_health_path_is_anonymized( $path ) ) {
		return null;
	}

	$cache_key = ERANKLY_HEALTH_SUGGESTION_PREFIX . md5( $path );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		// 'none' is the cached-miss sentinel; anything else is a suggestion array.
		return is_array( $cached ) ? $cached : null;
	}

	$suggestion = erankly_health_compute_redirect_suggestion( $entry, $path );

	/**
	 * Filters the computed 404 → redirect suggestion (or null when none).
	 *
	 * @param array<string,string>|null $suggestion Suggestion payload or null.
	 * @param array<string,mixed>       $entry      The 404 entry.
	 */
	$suggestion = apply_filters( 'erankly_health_404_suggestion', $suggestion, $entry );

	$ttl = (int) apply_filters( 'erankly_health_suggestion_ttl', 12 * HOUR_IN_SECONDS, $entry );

	set_transient( $cache_key, null === $suggestion ? 'none' : $suggestion, max( MINUTE_IN_SECONDS, $ttl ) );

	return $suggestion;
}

/*
 * ---------------------------------------------------------------------------
 * Operational Health: AI (semantic) 404 → redirect suggestions.
 *
 * Optional fallback used only when the lexical engine found nothing AND AI is
 * enabled (erankly_ai_enabled). A bounded, lexically-retrieved candidate pool is
 * sent to the site's configured AI provider, which picks the best same-topic
 * target from that list (or none). The chosen path is validated against the pool
 * so a hallucinated URL can never become a redirect. Results are cached per path.
 * ---------------------------------------------------------------------------
 */

/**
 * Builds a bounded pool of published candidates for the AI, ranked by lexical
 * proximity to the 404 slug. Each item is { path (site-relative), title }.
 *
 * @param string $slug  Normalized 404 slug.
 * @param int    $limit Maximum candidates (0 = filtered default).
 * @return array<int,array{path:string,title:string}>
 */
function erankly_health_ai_candidate_pool( string $slug, int $limit = 0 ): array {
	global $wpdb;

	if ( '' === $slug ) {
		return array();
	}

	$limit = $limit > 0 ? $limit : (int) apply_filters( 'erankly_health_ai_candidate_limit', 25 );
	$limit = max( 1, $limit );

	$post_types = array_keys( erankly_get_public_post_types() );

	if ( empty( $post_types ) ) {
		return array();
	}

	$scan_limit   = max( $limit, (int) apply_filters( 'erankly_health_suggestion_candidate_limit', ERANKLY_HEALTH_SUGGESTION_CANDIDATE_LIMIT ) );
	$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
	$args         = array_merge( $post_types, array( $scan_limit ) );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table lookup for bounded AI redirect suggestion candidates.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The placeholder list is generated from public post types and each value is bound via prepare().
		$wpdb->prepare(
			"SELECT ID, post_name, post_title FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_name <> '' AND post_type IN ($placeholders)
				ORDER BY post_modified DESC LIMIT %d",
			$args
		)
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	$scored = array();

	foreach ( (array) $rows as $row ) {
		$title_slug = sanitize_title( (string) $row->post_title );
		$scored[]   = array(
			'id'    => (int) $row->ID,
			'title' => (string) $row->post_title,
			'ratio' => max(
				erankly_health_similarity( $slug, (string) $row->post_name ),
				'' !== $title_slug ? erankly_health_similarity( $slug, $title_slug ) : 0.0
			),
		);
	}

	usort(
		$scored,
		static function ( array $a, array $b ): int {
			return $b['ratio'] <=> $a['ratio'];
		}
	);

	$pool = array();

	foreach ( array_slice( $scored, 0, $limit ) as $candidate ) {
		$path = erankly_health_permalink_path( (int) $candidate['id'] );

		if ( '' === $path ) {
			continue;
		}

		$pool[] = array(
			'path'  => $path,
			'title' => wp_strip_all_tags( (string) $candidate['title'] ),
		);
	}

	return $pool;
}

/**
 * Normalizes a path for tolerant equality (lowercase, no trailing slash).
 *
 * @param string $path Path.
 * @return string
 */
function erankly_health_path_match_key( string $path ): string {
	$path = strtolower( trim( $path ) );

	return '/' === $path ? '/' : untrailingslashit( $path );
}

/**
 * Builds the system/user prompt for the AI redirect suggestion.
 *
 * @param string                                     $slug  Normalized 404 slug.
 * @param array<int,array{path:string,title:string}> $pool  Candidate pool.
 * @param array<string,mixed>                        $entry 404 entry.
 * @return array{system:string,user:string}
 */
function erankly_health_ai_build_prompt( string $slug, array $pool, array $entry ): array {
	$lines = array();

	foreach ( $pool as $i => $candidate ) {
		$lines[] = ( $i + 1 ) . '. ' . $candidate['title'] . ' - ' . $candidate['path'];
	}

	$system = __( 'You are an SEO assistant that picks redirect targets for broken URLs. You receive the slug words of a deleted or missing page and a numbered list of existing pages on the same site (title - path). Choose the ONE existing page that covers the same topic, or a closely related one. Choose ONLY from the list. If no page is a sensible match, return none. Infer the language from the words and do not translate. Respond with ONLY a JSON object: {"target": "<exact path from the list>" or null, "confidence": "high"|"medium"|"low", "reason": "<short>"}.', 'easyrankly' );

	$user = sprintf(
		/* translators: 1: slug words of the broken URL. 2: numbered candidate list. */
		__( "Broken URL topic (slug words): %1\$s\n\nExisting pages:\n%2\$s\n\nReturn the JSON object only.", 'easyrankly' ),
		str_replace( '-', ' ', $slug ),
		implode( "\n", $lines )
	);

	$prompt = array(
		'system' => $system,
		'user'   => $user,
	);

	/**
	 * Filters the AI redirect-suggestion prompt.
	 *
	 * @param array{system:string,user:string}            $prompt System/user prompt.
	 * @param string                                      $slug   Normalized 404 slug.
	 * @param array<int,array{path:string,title:string}>  $pool   Candidate pool.
	 * @param array<string,mixed>                         $entry  404 entry.
	 */
	return apply_filters( 'erankly_health_ai_suggestion_prompt', $prompt, $slug, $pool, $entry );
}

/**
 * Parses the model's JSON answer and validates the target against the pool.
 *
 * @param string                                     $raw  Raw model output.
 * @param array<int,array{path:string,title:string}> $pool Candidate pool.
 * @return array<string,string>|null Suggestion payload, or null when none/invalid.
 */
function erankly_health_ai_parse_suggestion( string $raw, array $pool ): ?array {
	$json = trim( $raw );
	$json = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $json );

	if ( preg_match( '/\{.*\}/s', $json, $matches ) ) {
		$json = $matches[0];
	}

	$data = json_decode( $json, true );

	if ( ! is_array( $data ) || empty( $data['target'] ) || ! is_string( $data['target'] ) ) {
		return null;
	}

	// Anti-hallucination: the target must be one of the candidate paths.
	$target_key  = erankly_health_path_match_key( $data['target'] );
	$match_path  = '';
	$match_title = '';

	foreach ( $pool as $candidate ) {
		if ( erankly_health_path_match_key( (string) $candidate['path'] ) === $target_key ) {
			$match_path  = (string) $candidate['path'];
			$match_title = (string) $candidate['title'];
			break;
		}
	}

	if ( '' === $match_path ) {
		return null;
	}

	$confidence = isset( $data['confidence'] ) && in_array( $data['confidence'], array( 'high', 'medium', 'low' ), true ) ? (string) $data['confidence'] : 'medium';

	$suggestion                = erankly_health_build_suggestion( $match_path, $confidence, 'ai', $match_title );
	$suggestion['reason_text'] = isset( $data['reason'] ) ? sanitize_text_field( (string) $data['reason'] ) : '';

	return $suggestion;
}

/**
 * Caches an AI suggestion result ('none' sentinel when there is no match).
 *
 * @param string                    $cache_key  Transient key.
 * @param array<string,string>|null $suggestion Suggestion or null.
 * @return void
 */
function erankly_health_cache_ai_suggestion( string $cache_key, ?array $suggestion ): void {
	$ttl = (int) apply_filters( 'erankly_health_suggestion_ttl', 12 * HOUR_IN_SECONDS, array() );

	set_transient( $cache_key, null === $suggestion ? 'none' : $suggestion, max( MINUTE_IN_SECONDS, $ttl ) );
}

/**
 * Reads any cached AI suggestion for a 404 path without calling the provider.
 *
 * @param string $path Stored 404 path.
 * @return array{state:string,suggestion:array<string,string>|null}
 *               state = 'fresh' (never tried) | 'none' (tried, no match) | 'hit'.
 */
function erankly_health_ai_cached_suggestion( string $path ): array {
	$cached = get_transient( ERANKLY_HEALTH_AI_SUGGESTION_PREFIX . md5( $path ) );

	if ( is_array( $cached ) ) {
		return array(
			'state'      => 'hit',
			'suggestion' => $cached,
		);
	}

	if ( 'none' === $cached ) {
		return array(
			'state'      => 'none',
			'suggestion' => null,
		);
	}

	return array(
		'state'      => 'fresh',
		'suggestion' => null,
	);
}

/**
 * Suggests a redirect target for a 404 using the AI provider (on-demand).
 *
 * Gated by erankly_ai_enabled(); meant as a fallback when the lexical engine
 * returned nothing. The result is cached per path. Returns null on a provider
 * error WITHOUT caching, so the admin can retry.
 *
 * @param array<string,mixed> $entry 404 entry (must contain 'path').
 * @return array<string,string>|null
 */
function erankly_health_ai_suggest_redirect_target( array $entry ): ?array {
	if ( ! function_exists( 'erankly_ai_enabled' ) || ! erankly_ai_enabled() ) {
		return null;
	}

	$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';

	if ( '' === $path || erankly_health_path_is_anonymized( $path ) ) {
		return null;
	}

	$cache_key = ERANKLY_HEALTH_AI_SUGGESTION_PREFIX . md5( $path );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null;
	}

	$slug = erankly_health_404_slug_from_path( $path );
	$pool = '' !== $slug ? erankly_health_ai_candidate_pool( $slug ) : array();

	if ( empty( $pool ) ) {
		erankly_health_cache_ai_suggestion( $cache_key, null );

		return null;
	}

	$prompt = erankly_health_ai_build_prompt( $slug, $pool, $entry );
	$raw    = erankly_ai_call_model( (string) $prompt['system'], (string) $prompt['user'] );

	if ( is_wp_error( $raw ) ) {
		// Transient/provider failure: do not cache so the admin can retry.
		return null;
	}

	$suggestion = erankly_health_ai_parse_suggestion( (string) $raw, $pool );

	erankly_health_cache_ai_suggestion( $cache_key, $suggestion );

	return $suggestion;
}

/**
 * Handles the on-demand "Suggest with AI" admin-post request for one 404.
 *
 * @return void
 */
function erankly_health_handle_ai_suggest(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_ai_suggest' );

	$hash    = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
	$outcome = 'error';

	if ( ! function_exists( 'erankly_ai_enabled' ) || ! erankly_ai_enabled() ) {
		$outcome = 'disabled';
	} elseif ( preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
		$entries = erankly_health_get_404_entries( ERANKLY_HEALTH_404_FREQUENT_OPTION );

		if ( isset( $entries[ $hash ] ) ) {
			$path       = (string) $entries[ $hash ]['path'];
			$suggestion = erankly_health_ai_suggest_redirect_target( $entries[ $hash ] );

			if ( null !== $suggestion ) {
				$outcome = 'suggested';
			} else {
				// Distinguish "AI ran, no match" (cached 'none') from a provider error (not cached).
				$outcome = 'none' === erankly_health_ai_cached_suggestion( $path )['state'] ? 'none' : 'error';
			}
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'erankly',
				'erankly_tab'       => 'health',
				'erankly_health_ai' => $outcome,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Returns a redirects repository instance when the Redirects module is active,
 * or null when it is disabled or its classes are unavailable.
 *
 * @return ERankly_Redirects_Repository|null
 */
function erankly_health_get_redirects_repository() {
	if (
		! function_exists( 'erankly_redirects_enabled' )
		|| ! erankly_redirects_enabled()
		|| ! class_exists( 'ERankly_Redirects_Repository' )
		|| ! class_exists( 'ERankly_Redirects_Normalizer' )
	) {
		return null;
	}

	return new ERankly_Redirects_Repository();
}

/**
 * Computes the redirect-normalized source hash for a stored 404 path.
 *
 * Returns '' for anonymized or empty paths, which cannot map to a real source.
 *
 * @param string $path Stored 404 path.
 * @return string
 */
function erankly_health_redirect_hash_for_path( string $path ): string {
	if ( '' === $path || erankly_health_path_is_anonymized( $path ) || ! class_exists( 'ERankly_Redirects_Normalizer' ) ) {
		return '';
	}

	$normalized = ERankly_Redirects_Normalizer::normalize_source( $path, false, false );

	return '' === $normalized ? '' : ERankly_Redirects_Normalizer::source_hash( $normalized );
}

/**
 * Splits frequent 404s into rows still needing attention and a count of those
 * already covered by an active redirect (which are hidden from the active list).
 *
 * @param array<string,array<string,mixed>> $frequent_404s Frequent 404 entries.
 * @return array{active:array<int,array<string,mixed>>,handled:int}
 */
function erankly_health_partition_404s( array $frequent_404s ): array {
	$repo    = erankly_health_get_redirects_repository();
	$states  = erankly_health_get_404_states();
	$active  = array();
	$managed = array();
	$handled = 0;

	foreach ( $frequent_404s as $entry_hash => $entry ) {
		$entry['hash'] = (string) $entry_hash;

		$redirect_hash = $repo ? erankly_health_redirect_hash_for_path( (string) $entry['path'] ) : '';

		// Covered by an active redirect → hidden (counts into the handled summary).
		if ( '' !== $redirect_hash && $repo->find_active_exact_by_hash( $redirect_hash ) ) {
			++$handled;
			continue;
		}

		// Manually ignored/resolved → moved to the "managed" list.
		if ( isset( $states[ $entry_hash ] ) ) {
			$entry['state'] = (string) $states[ $entry_hash ]['status'];
			$managed[]      = $entry;
			continue;
		}

		$active[] = $entry;
	}

	return array(
		'active'  => $active,
		'managed' => $managed,
		'handled' => $handled,
	);
}

/**
 * Renders the "Suggestion" cell for a frequent 404 row.
 *
 * @param array<string,string>|null $suggestion Suggestion payload or null.
 * @param bool                      $anonymized Whether the source path is anonymized.
 * @param bool                      $ai_tried   Whether the AI already ran and found nothing.
 * @return void
 */
function erankly_health_render_404_suggestion_cell( ?array $suggestion, bool $anonymized, bool $ai_tried = false ): void {
	if ( null !== $suggestion ) {
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><code>%2$s</code></a><br><span class="description">%3$s</span>',
			esc_url( home_url( (string) $suggestion['target'] ) ),
			esc_html( (string) $suggestion['target'] ),
			esc_html( (string) $suggestion['label'] )
		);

		return;
	}

	echo '<span class="description">';
	if ( $anonymized ) {
		echo esc_html__( 'Path anonymized, no automatic match.', 'easyrankly' );
	} elseif ( $ai_tried ) {
		echo esc_html__( 'No match (AI included).', 'easyrankly' );
	} else {
		echo esc_html__( 'No automatic match.', 'easyrankly' );
	}
	echo '</span>';
}

/**
 * Renders the "Action" cell for a frequent 404 row.
 *
 * The primary action deep-links to the Redirects tab with the add form
 * pre-filled (source, suggested target, 301, provenance note). The redirect is
 * only created once the admin reviews and saves it there.
 *
 * @param array<string,mixed>       $entry             404 entry.
 * @param array<string,string>|null $suggestion        Suggestion payload or null.
 * @param bool                      $anonymized        Whether the source path is anonymized.
 * @param bool                      $redirects_enabled Whether the Redirects module is on.
 * @param bool                      $ai_button         Whether to offer the on-demand "Suggest with AI" button.
 * @return void
 */
function erankly_health_render_404_action_cell( array $entry, ?array $suggestion, bool $anonymized, bool $redirects_enabled, bool $ai_button = false ): void {
	if ( ! $redirects_enabled ) {
		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( add_query_arg( 'erankly_tab', 'features', erankly_setup_wizard_settings_url() ) ),
			esc_html__( 'Enable Redirects to fix', 'easyrankly' )
		);
	} elseif ( $anonymized ) {
		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a>',
			esc_url( add_query_arg( 'erankly_tab', 'redirects', erankly_setup_wizard_settings_url() ) ),
			esc_html__( 'Create manually', 'easyrankly' )
		);
	} else {
		$normalized_source = class_exists( 'ERankly_Redirects_Normalizer' )
			? ERankly_Redirects_Normalizer::normalize_source( (string) $entry['path'], false, false )
			: (string) $entry['path'];

		$create_url = add_query_arg(
			array(
				'erankly_tab'                      => 'redirects',
				'erankly_redirects_prefill_source' => $normalized_source,
				'erankly_redirects_prefill_target' => null !== $suggestion ? (string) $suggestion['target'] : '',
				'erankly_redirects_prefill_status' => 301,
				'erankly_redirects_prefill_note'   => __( 'Created from Health 404 scanner', 'easyrankly' ),
			),
			erankly_setup_wizard_settings_url()
		);

		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( $create_url ),
			esc_html__( 'Create 301 redirect', 'easyrankly' )
		);
	}

	if ( $ai_button ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 0 0 6px;">
			<input type="hidden" name="action" value="erankly_health_ai_suggest">
			<input type="hidden" name="hash" value="<?php echo esc_attr( isset( $entry['hash'] ) ? (string) $entry['hash'] : '' ); ?>">
			<?php wp_nonce_field( 'erankly_health_ai_suggest' ); ?>
			<button type="submit" class="button-link"><?php esc_html_e( 'Suggest with AI', 'easyrankly' ); ?></button>
		</form>
		<?php
	}

	erankly_health_render_404_state_forms( isset( $entry['hash'] ) ? (string) $entry['hash'] : '', 'active' );
}

/**
 * Renders the manual-state admin-post forms (Ignore / Mark resolved / Restore).
 *
 * @param string $hash          md5 hash (frequent-entry key) identifying the 404.
 * @param string $current_state 'active'|'ignored'|'resolved'.
 * @return void
 */
function erankly_health_render_404_state_forms( string $hash, string $current_state ): void {
	if ( '' === $hash ) {
		return;
	}

	$buttons = 'active' === $current_state
		? array(
			'ignored'  => __( 'Ignore', 'easyrankly' ),
			'resolved' => __( 'Mark resolved', 'easyrankly' ),
		)
		: array( 'active' => __( 'Restore', 'easyrankly' ) );

	foreach ( $buttons as $state => $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 0 0 6px;">
			<input type="hidden" name="action" value="erankly_health_404_set_state">
			<input type="hidden" name="hash" value="<?php echo esc_attr( $hash ); ?>">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<?php wp_nonce_field( 'erankly_health_404_state' ); ?>
			<button type="submit" class="button-link"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}

/**
 * Normalizes a URL or path to a root-relative path for internal link matching.
 *
 * @param string $url URL or path to normalize.
 * @return string Normalized root-relative path, or empty string if not resolvable.
 */
function erankly_health_normalize_link_path( string $url ): string {
	$path = wp_parse_url( $url, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );

	return '/' === $path ? $path : untrailingslashit( $path );
}

/**
 * Runs a full thin-content scan over all published pages and caches the results.
 *
 * A page is flagged as thin when it meets at least 2 of the following 3 conditions:
 * - Fewer than ERANKLY_HEALTH_THIN_MIN_CHARS characters of plain text.
 * - No internal inbound links (no other indexed page on this site links to it).
 * - No internal outbound links (it does not link to any other indexed page on this site).
 *
 * Results are stored in wp_options (no autoload) and overwrite any previous scan.
 *
 * @return void
 */
function erankly_health_run_thin_content_scan(): void {
	global $wpdb;

	$post_types   = array_keys( erankly_get_public_post_types() );
	$empty_result = array(
		'scanned_at'    => time(),
		'scanned_count' => 0,
		'pages'         => array(),
	);

	if ( empty( $post_types ) ) {
		update_option( ERANKLY_HEALTH_THIN_OPTION, $empty_result, false );
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// Collect candidate post IDs only — post_content is streamed in batches below so
	// the full corpus is never loaded into memory at once (large-site safe).
	$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- On-demand thin-content scan; IDs only.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder list is generated from the validated number of public post types and values are passed separately to prepare().
		$wpdb->prepare(
			"SELECT p.ID
				FROM {$wpdb->posts} p
				WHERE p.post_status = 'publish'
					AND p.post_type IN ({$placeholders})
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
						AND pm.meta_key = '_erankly_noindex'
						AND pm.meta_value = '1'
					)",
			$post_types
		)
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	);

	$post_ids = array_map( 'intval', (array) $post_ids );

	if ( empty( $post_ids ) ) {
		update_option( ERANKLY_HEALTH_THIN_OPTION, $empty_result, false );
		return;
	}

	$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	// Build a map of normalized permalink path => post ID for all scanned posts.
	// Only post IDs are needed here, so post_content stays out of memory.
	$path_map = array();

	foreach ( $post_ids as $post_id ) {
		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			continue;
		}

		$path = erankly_health_normalize_link_path( $permalink );

		if ( '' !== $path ) {
			$path_map[ $path ] = $post_id;
		}
	}

	// Stream post_content in batches. Each batch updates the global inbound/outbound
	// link graph and stores only small per-post scalars (character count and outbound
	// flag); the content itself is discarded after every batch.
	$inbound_counts = array(); // post_id (int) => int.
	$has_outbound   = array(); // post_id (int) => bool.
	$char_counts    = array(); // post_id (int) => int (non-builder posts only).

	foreach ( array_chunk( $post_ids, ERANKLY_HEALTH_THIN_SCAN_BATCH ) as $batch_ids ) {
		// $id_placeholders is built from array_fill('%d'), so it contains only literal
		// %d tokens; all values are bound through prepare() in every query below.
		$id_placeholders = implode( ', ', array_fill( 0, count( $batch_ids ), '%d' ) );

		// Page-builder posts (Elementor, Divi, WPBakery) keep their content in meta, not
		// post_content, so a char count would always look "thin". Detect and exclude them.
		$builder_sql      = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders}) AND meta_key IN ('_elementor_edit_mode', '_et_pb_use_builder', '_wpb_vc_js_status') AND meta_value IN ('builder', 'true', '1')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core WP table name; placeholders are literal %d tokens bound via prepare().
		$builder_rows     = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One meta query per batch to detect page-builder posts.
			$wpdb->prepare( $builder_sql, ...$batch_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built above; all values bound as %d via prepare().
		);
		$builder_post_ids = array_flip( array_map( 'absint', (array) $builder_rows ) );

		// Custom field text for this batch, included in the char-count heuristic.
		$custom_fields = array();
		$meta_sql      = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders}) AND meta_key NOT LIKE '\_%'"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core WP table name; placeholders are literal %d tokens bound via prepare().
		$meta_rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One meta query per batch to include custom fields.
			$wpdb->prepare( $meta_sql, ...$batch_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built above; all values bound as %d via prepare().
			ARRAY_A
		);

		if ( is_array( $meta_rows ) ) {
			foreach ( $meta_rows as $mrow ) {
				$pid = (int) $mrow['post_id'];
				$val = trim( (string) $mrow['meta_value'] );

				// Ignore serialized data, numeric values, URLs, and space-less strings
				// (likely IDs/keys) so only human-readable text is counted.
				if ( is_serialized( $val ) || is_numeric( $val ) || filter_var( $val, FILTER_VALIDATE_URL ) || ! str_contains( $val, ' ' ) ) {
					continue;
				}

				$custom_fields[ $pid ] = ( isset( $custom_fields[ $pid ] ) ? $custom_fields[ $pid ] : '' ) . ' ' . wp_strip_all_tags( $val );
			}
		}

		$posts_sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$id_placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core WP table name; placeholders are literal %d tokens bound via prepare().
		$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Streams post_content one batch at a time for the on-demand thin-content scan.
			$wpdb->prepare( $posts_sql, ...$batch_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built above; all values bound as %d via prepare().
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			continue;
		}

		foreach ( $rows as $row ) {
			$post_id      = (int) $row['ID'];
			$post_content = (string) $row['post_content'];

			// Inbound/outbound link graph. Runs for every post — including page-builder
			// posts — so their links still count toward the pages they reference.
			$found_out = false;

			preg_match_all( '/<a\s[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $post_content, $matches );

			foreach ( $matches[1] as $href ) {
				$href = trim( $href );

				if ( '' === $href || '#' === $href[0] ) {
					continue;
				}

				if (
					0 === stripos( $href, 'mailto:' ) ||
					0 === stripos( $href, 'tel:' ) ||
					0 === stripos( $href, 'javascript:' )
				) {
					continue;
				}

				// Internal only if no host (root-relative) or host matches this site.
				$href_host = wp_parse_url( $href, PHP_URL_HOST );

				if ( is_string( $href_host ) && '' !== $href_host && $href_host !== $home_host ) {
					continue; // External link.
				}

				$href_path = erankly_health_normalize_link_path( $href );

				if ( '' === $href_path || ! isset( $path_map[ $href_path ] ) ) {
					continue; // Does not resolve to a known indexed page.
				}

				$target_id = $path_map[ $href_path ];

				if ( $target_id === $post_id ) {
					continue; // Self-link.
				}

				$found_out                    = true;
				$inbound_counts[ $target_id ] = ( isset( $inbound_counts[ $target_id ] ) ? $inbound_counts[ $target_id ] : 0 ) + 1;
			}

			$has_outbound[ $post_id ] = $found_out;

			// Page-builder posts are excluded from the character-count heuristic.
			if ( isset( $builder_post_ids[ $post_id ] ) ) {
				continue;
			}

			// Exclude FSE header/footer/navigation blocks to only analyze the main content.
			$post_content = preg_replace( '#<!-- wp:(navigation|site-title|site-logo|template-part|site-tagline|query|post-navigation-link)[^>]*-->.*?<!-- /wp:\1 -->#s', '', $post_content );
			$post_content = preg_replace( '#<!-- wp:(navigation|site-title|site-logo|template-part|site-tagline|query|post-navigation-link)[^>]*/?-->#s', '', $post_content );

			// Run do_blocks() so Gutenberg block content is included in the character
			// count; shortcodes are evaluated too. wp_strip_all_tags() then removes any
			// remaining markup before measuring.
			$rendered_content = function_exists( 'do_blocks' ) ? do_blocks( $post_content ) : $post_content;
			$stripped         = wp_strip_all_tags( strip_shortcodes( $rendered_content ) );

			if ( isset( $custom_fields[ $post_id ] ) ) {
				$stripped .= ' ' . $custom_fields[ $post_id ];
			}

			$char_counts[ $post_id ] = mb_strlen( trim( preg_replace( '/\s+/', ' ', $stripped ) ) );
		}
	}

	// Evaluate the 2-of-3 thin-content heuristic from the accumulated per-post data.
	// Only non-builder posts have a char-count entry; page-builder posts are excluded.
	$thin_pages = array();

	foreach ( $char_counts as $post_id => $char_count ) {
		$is_thin_chars = $char_count < ERANKLY_HEALTH_THIN_MIN_CHARS;
		$page_has_in   = ! empty( $inbound_counts[ $post_id ] );
		$page_has_out  = ! empty( $has_outbound[ $post_id ] );

		$score = (int) $is_thin_chars + (int) ( ! $page_has_in ) + (int) ( ! $page_has_out );

		if ( $score < 2 ) {
			continue;
		}

		$thin_pages[] = array(
			'id'           => $post_id,
			'title'        => (string) get_the_title( $post_id ),
			'edit_url'     => (string) get_edit_post_link( $post_id ),
			'char_count'   => $char_count,
			'has_inbound'  => $page_has_in,
			'has_outbound' => $page_has_out,
			'score'        => $score,
		);
	}

	// Sort: most conditions met first, then fewest characters.
	usort(
		$thin_pages,
		static function ( array $a, array $b ): int {
			$cmp = $b['score'] <=> $a['score'];
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			return $a['char_count'] <=> $b['char_count'];
		}
	);

	if ( count( $thin_pages ) > ERANKLY_HEALTH_THIN_MAX_RESULTS ) {
		$thin_pages = array_slice( $thin_pages, 0, ERANKLY_HEALTH_THIN_MAX_RESULTS );
	}

	update_option(
		ERANKLY_HEALTH_THIN_OPTION,
		array(
			'scanned_at'    => time(),
			'scanned_count' => count( $post_ids ),
			'pages'         => $thin_pages,
		),
		false
	);
}

/**
 * Returns cached thin-content scan results, or null if no scan has been run yet.
 *
 * @return array{scanned_at:int,scanned_count:int,pages:array<int,array<string,mixed>>}|null
 */
function erankly_health_get_thin_content(): ?array {
	$data = get_option( ERANKLY_HEALTH_THIN_OPTION, null );

	if ( ! is_array( $data ) ) {
		return null;
	}

	return array(
		'scanned_at'    => isset( $data['scanned_at'] ) ? absint( $data['scanned_at'] ) : 0,
		'scanned_count' => isset( $data['scanned_count'] ) ? absint( $data['scanned_count'] ) : 0,
		'pages'         => isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array(),
	);
}

/**
 * Handles the admin request that triggers a thin-content scan.
 *
 * @return void
 */
function erankly_health_handle_scan_thin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to run Health scans.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_scan_thin' );
	erankly_health_run_thin_content_scan();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                   => 'erankly',
				'erankly_tab'            => 'health',
				'erankly_health_scanned' => '1',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Returns a fresh, empty Broken-Link crawl state skeleton.
 *
 * @return array<string,mixed>
 */
function erankly_health_bl_default_state(): array {
	return array(
		'status'      => 'idle', // idle|discovering|checking|done.
		'started_at'  => 0,
		'updated_at'  => 0,
		'queue'       => array(), // Pending pages to fetch: list of array{url:string,depth:int}.
		'visited'     => array(), // page-path (string) => true, to avoid refetching/looping.
		'pages_done'  => 0,
		'links'       => array(), // link URL (string) => array{type,occurrences,nofollow}.
		'check_queue' => array(), // list of link URLs still to status-check.
		'checks_done' => 0,
		'found'       => array(), // Accumulated broken/unreachable links during checking.
		'stats'       => array(
			'pages'          => 0,
			'links'          => 0,
			'checked'        => 0,
			'broken'         => 0,
			'fetch_ok'       => 0, // Pages whose HTML was retrieved over HTTP (loopback).
			'fetch_fallback' => 0, // Pages whose HTML was read from the database instead.
			'fetch_failed'   => 0, // Pages that could not be read at all.
		),
	);
}

/**
 * Reads the current crawl state, falling back to a fresh skeleton.
 *
 * @return array<string,mixed>
 */
function erankly_health_bl_get_state(): array {
	$state = get_option( ERANKLY_HEALTH_BL_STATE_OPTION, null );

	if ( ! is_array( $state ) || ! isset( $state['status'] ) ) {
		return erankly_health_bl_default_state();
	}

	// Merge onto the skeleton so newly added keys are always present.
	return array_merge( erankly_health_bl_default_state(), $state );
}

/**
 * Persists the crawl state (no autoload — it can grow large during a run).
 *
 * @param array<string,mixed> $state Crawl state.
 * @return void
 */
function erankly_health_bl_save_state( array $state ): void {
	$state['updated_at'] = time();
	update_option( ERANKLY_HEALTH_BL_STATE_OPTION, $state, false );
}

/**
 * Clears any in-progress crawl state, returning the crawler to idle.
 *
 * @return void
 */
function erankly_health_bl_reset_state(): void {
	delete_option( ERANKLY_HEALTH_BL_STATE_OPTION );
}

/**
 * Builds the seed URL set for a crawl: the site home plus the permalinks of
 * published, indexable content (the same corpus the sitemap advertises).
 *
 * The spider expands from here; seeds are capped so a very large site cannot
 * enqueue an unbounded initial queue.
 *
 * @return array<int,string> Absolute seed URLs (deduplicated, capped).
 */
function erankly_health_bl_seed_urls(): array {
	$seeds = array( home_url( '/' ) );

	$post_types = array_keys( erankly_get_public_post_types() );

	if ( ! empty( $post_types ) ) {
		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => ERANKLY_HEALTH_BL_MAX_PAGES,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		foreach ( $query->posts as $post_id ) {
			// Skip content explicitly marked noindex — it is not part of the
			// indexable corpus and should not seed the crawl.
			if ( '1' === (string) get_post_meta( (int) $post_id, '_erankly_noindex', true ) ) {
				continue;
			}

			$permalink = get_permalink( (int) $post_id );

			if ( is_string( $permalink ) && '' !== $permalink ) {
				$seeds[] = $permalink;
			}
		}
	}

	$seeds = array_values( array_unique( $seeds ) );

	if ( count( $seeds ) > ERANKLY_HEALTH_BL_MAX_PAGES ) {
		$seeds = array_slice( $seeds, 0, ERANKLY_HEALTH_BL_MAX_PAGES );
	}

	return $seeds;
}

/**
 * Initializes a new crawl: seeds the fetch queue at depth 0 and marks the state
 * as discovering. Any previous run (state and results) is discarded first.
 *
 * @return array<string,mixed> The freshly initialized state.
 */
function erankly_health_bl_start_crawl(): array {
	erankly_health_bl_reset_state();
	delete_option( ERANKLY_HEALTH_BL_RESULTS_OPTION );

	$state               = erankly_health_bl_default_state();
	$state['status']     = 'discovering';
	$state['started_at'] = time();

	foreach ( erankly_health_bl_seed_urls() as $url ) {
		$canonical = erankly_health_bl_canonicalize( $url );

		if ( '' === $canonical || isset( $state['visited'][ $canonical ] ) ) {
			continue;
		}

		// Mark as seen at enqueue time so the spider never re-queues a seed.
		$state['visited'][ $canonical ] = true;
		$state['queue'][]               = array(
			'url'   => $canonical,
			'depth' => 0,
		);
	}

	erankly_health_bl_save_state( $state );

	return $state;
}

/**
 * Builds the compact progress payload returned to the admin JS driver.
 *
 * @param array<string,mixed> $state Crawl state.
 * @return array<string,mixed>
 */
function erankly_health_bl_progress_payload( array $state ): array {
	$stats = isset( $state['stats'] ) && is_array( $state['stats'] ) ? $state['stats'] : array();

	return array(
		'status'      => (string) $state['status'],
		'pages_done'  => (int) $state['pages_done'],
		'queued'      => count( (array) $state['queue'] ),
		'checks_done' => (int) $state['checks_done'],
		'check_total' => (int) $state['checks_done'] + count( (array) $state['check_queue'] ),
		'stats'       => array(
			'pages'   => isset( $stats['pages'] ) ? (int) $stats['pages'] : 0,
			'links'   => isset( $stats['links'] ) ? (int) $stats['links'] : 0,
			'checked' => isset( $stats['checked'] ) ? (int) $stats['checked'] : 0,
			'broken'  => isset( $stats['broken'] ) ? (int) $stats['broken'] : 0,
		),
	);
}

/**
 * Permission callback for the Broken-Link crawl REST routes.
 *
 * @return bool
 */
function erankly_health_bl_rest_permission(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Registers the REST routes that drive the manual crawl from the admin UI.
 *
 * @return void
 */
function erankly_health_bl_register_rest_routes(): void {
	$routes = array(
		'start'  => 'erankly_health_bl_rest_start',
		'tick'   => 'erankly_health_bl_rest_tick',
		'cancel' => 'erankly_health_bl_rest_cancel',
	);

	foreach ( $routes as $path => $callback ) {
		register_rest_route(
			'erankly/v1',
			'/health/broken-links/' . $path,
			array(
				'methods'             => 'POST',
				'callback'            => $callback,
				'permission_callback' => 'erankly_health_bl_rest_permission',
			)
		);
	}
}

/**
 * REST: starts (or restarts) a crawl and returns the initial progress payload.
 *
 * @return WP_REST_Response
 */
function erankly_health_bl_rest_start(): WP_REST_Response {
	$state = erankly_health_bl_start_crawl();

	return new WP_REST_Response( erankly_health_bl_progress_payload( $state ), 200 );
}

/**
 * REST: advances the crawl by one batch and returns the current progress.
 *
 * Discovering: fetch a batch of pages, extract links, spider internal ones.
 * Checking: not implemented yet (Phase 3) — finalized immediately for now.
 *
 * @return WP_REST_Response
 */
function erankly_health_bl_rest_tick(): WP_REST_Response {
	$state = erankly_health_bl_get_state();

	if ( 'idle' === $state['status'] || 'done' === $state['status'] ) {
		return new WP_REST_Response( erankly_health_bl_progress_payload( $state ), 200 );
	}

	if ( 'discovering' === $state['status'] ) {
		$state = erankly_health_bl_run_discovery_batch( $state );
	} elseif ( 'checking' === $state['status'] ) {
		$state = erankly_health_bl_run_checking_batch( $state );
	}

	erankly_health_bl_save_state( $state );

	return new WP_REST_Response( erankly_health_bl_progress_payload( $state ), 200 );
}

/**
 * REST: cancels an in-progress crawl and returns the crawler to idle.
 *
 * @return WP_REST_Response
 */
function erankly_health_bl_rest_cancel(): WP_REST_Response {
	erankly_health_bl_reset_state();

	return new WP_REST_Response( erankly_health_bl_progress_payload( erankly_health_bl_default_state() ), 200 );
}

/**
 * Returns the lowercased host of this site's home URL (memoized per request).
 *
 * @return string
 */
function erankly_health_bl_home_host(): string {
	static $host = null;

	if ( null === $host ) {
		$parsed = wp_parse_url( home_url(), PHP_URL_HOST );
		$host   = is_string( $parsed ) ? strtolower( $parsed ) : '';
	}

	return $host;
}

/**
 * Removes RFC 3986 dot-segments ("." and "..") from a URL path.
 *
 * @param string $path Path component (may begin with "/").
 * @return string
 */
function erankly_health_bl_remove_dot_segments( string $path ): string {
	$leading_slash  = '' !== $path && '/' === $path[0];
	$trailing_slash = '' !== $path && '/' === $path[ strlen( $path ) - 1 ];
	$segments       = explode( '/', $path );
	$out            = array();

	foreach ( $segments as $segment ) {
		if ( '.' === $segment || '' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			array_pop( $out );
			continue;
		}
		$out[] = $segment;
	}

	$result = implode( '/', $out );

	if ( $leading_slash ) {
		$result = '/' . $result;
	}
	if ( $trailing_slash && '' !== $result && '/' !== $result[ strlen( $result ) - 1 ] ) {
		$result .= '/';
	}

	return '' === $result ? '/' : $result;
}

/**
 * Resolves a possibly-relative href against a base URL into an absolute URL.
 *
 * Handles absolute, protocol-relative, root-relative, and path-relative hrefs.
 * Returns '' for empty/fragment-only hrefs or unresolvable input.
 *
 * @param string $href Raw href attribute value.
 * @param string $base Absolute URL of the page the href was found on.
 * @return string Absolute URL, or '' if it cannot be resolved.
 */
function erankly_health_bl_resolve_url( string $href, string $base ): string {
	$href = trim( $href );

	if ( '' === $href || '#' === $href[0] ) {
		return '';
	}

	// Protocol-relative → borrow the base scheme.
	if ( 0 === strpos( $href, '//' ) ) {
		$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
		$href   = ( is_string( $scheme ) && '' !== $scheme ? $scheme : 'https' ) . ':' . $href;
	}

	$parts = wp_parse_url( $href );

	if ( false === $parts ) {
		return '';
	}

	// Already absolute.
	if ( isset( $parts['scheme'] ) ) {
		return $href;
	}

	$base_parts = wp_parse_url( $base );

	if ( ! is_array( $base_parts ) || empty( $base_parts['host'] ) ) {
		return '';
	}

	$scheme    = isset( $base_parts['scheme'] ) ? $base_parts['scheme'] : 'https';
	$authority = $base_parts['host'] . ( isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '' );

	if ( 0 === strpos( $href, '/' ) ) {
		$path = $href; // Root-relative (may carry its own query string).
	} else {
		$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$dir       = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
		$path      = ( '' === $dir ? '/' : $dir ) . $href;
	}

	return $scheme . '://' . $authority . $path;
}

/**
 * Canonicalizes an absolute URL to a stable key: lowercased host, http(s) only,
 * fragment stripped, path normalized (dot-segments removed, no trailing slash).
 *
 * The query string is preserved (it can change the response). Returns '' for
 * non-http(s) URLs (mailto:, tel:, javascript:, data:) or unparseable input.
 *
 * @param string $url Absolute URL.
 * @return string Canonical URL, or '' if not a checkable http(s) URL.
 */
function erankly_health_bl_canonicalize( string $url ): string {
	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';

	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return '';
	}

	$host = strtolower( $parts['host'] );
	$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
	$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
	$path = erankly_health_bl_remove_dot_segments( '/' . ltrim( $path, '/' ) );

	if ( '/' !== $path ) {
		$path = untrailingslashit( $path );
	}

	$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

	return $scheme . '://' . $host . $port . $path . $query;
}

/**
 * Whether a canonical URL points to this site (same host as the home URL).
 *
 * @param string $canonical_url Canonical URL.
 * @return bool
 */
function erankly_health_bl_is_internal( string $canonical_url ): bool {
	$host = wp_parse_url( $canonical_url, PHP_URL_HOST );

	return is_string( $host ) && strtolower( $host ) === erankly_health_bl_home_host();
}

/**
 * Whether a URL path looks like a non-HTML asset (image, document, archive…)
 * that should be status-checked but never spidered for further links.
 *
 * @param string $canonical_url Canonical URL.
 * @return bool
 */
function erankly_health_bl_is_asset_url( string $canonical_url ): bool {
	$path = (string) wp_parse_url( $canonical_url, PHP_URL_PATH );
	$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

	if ( '' === $ext ) {
		return false;
	}

	$assets = explode(
		' ',
		'jpg jpeg png gif webp avif svg ico bmp tif tiff '
		. 'pdf doc docx xls xlsx ppt pptx odt ods rtf csv '
		. 'zip gz tar rar 7z dmg exe '
		. 'mp3 wav ogg mp4 m4v mov avi webm mkv '
		. 'css js json xml rss woff woff2 ttf eot'
	);

	return in_array( $ext, $assets, true );
}

/**
 * Fetches a page's HTML, returning the body only for a successful text/html
 * response. Non-HTML, errors, and non-2xx responses yield null (no links).
 *
 * @param string $url Absolute page URL to fetch.
 * @return string|null HTML body, or null when it cannot/should not be parsed.
 */
function erankly_health_bl_fetch_html( string $url ): ?string {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => ERANKLY_HEALTH_BL_HTTP_TIMEOUT,
			'redirection' => 5,
			// Pages fetched here are always on this site (loopback), where a
			// self-signed or mismatched certificate on staging would otherwise
			// abort every request. Mirrors WordPress core's loopback convention.
			'sslverify'   => (bool) apply_filters( 'erankly_health_bl_sslverify', false, $url, true ),
			'user-agent'  => 'EasyRankly Broken-Link Crawler/1.0; ' . home_url( '/' ),
			'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return null;
	}

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$content_type = wp_remote_retrieve_header( $response, 'content-type' );

	if ( is_string( $content_type ) && '' !== $content_type && false === stripos( $content_type, 'html' ) ) {
		return null; // Not an HTML document.
	}

	$body = wp_remote_retrieve_body( $response );

	if ( ! is_string( $body ) || '' === $body ) {
		return null;
	}

	// Guard against pathologically large documents.
	if ( strlen( $body ) > 2 * MB_IN_BYTES ) {
		$body = substr( $body, 0, 2 * MB_IN_BYTES );
	}

	return $body;
}

/**
 * Fallback link source for an internal page when the HTTP (loopback) fetch fails
 * — common on local/staging. Renders the post's stored content (blocks and
 * shortcodes expanded, anchors preserved) so links inside the body are still
 * discovered even when the site cannot make requests to itself.
 *
 * This is a degraded source: it only covers singular published content and its
 * body, so theme-level links (menus, footers, widgets) and page-builder layouts
 * stored in post meta are not seen here — those need a working HTTP fetch.
 *
 * @param string $url Absolute internal page URL.
 * @return string|null Rendered HTML, or null when no usable content is available.
 */
function erankly_health_bl_render_internal_content( string $url ): ?string {
	$post_id = url_to_postid( $url );

	if ( ! $post_id ) {
		return null;
	}

	$post = get_post( $post_id );

	if ( ! ( $post instanceof WP_Post ) || 'publish' !== $post->post_status ) {
		return null;
	}

	$content = (string) $post->post_content;

	if ( '' === $content ) {
		return null;
	}

	// Expand blocks and shortcodes so links they output are included. Rendered
	// without altering post globals: static body links (the common case) resolve
	// fine; a handful of context-dependent dynamic blocks may not, which is an
	// acceptable trade-off for this loopback fallback.
	$rendered = do_shortcode( do_blocks( $content ) );

	return '' === $rendered ? null : $rendered;
}

/**
 * Extracts anchor links from an HTML document, resolved against the page URL.
 *
 * @param string $html     Page HTML.
 * @param string $base_url Absolute URL of the page (for resolving relatives).
 * @return array<int,array{url:string,anchor:string,nofollow:bool,internal:bool,spiderable:bool}>
 */
function erankly_health_bl_extract_links( string $html, string $base_url ): array {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return array();
	}

	$dom      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );

	// Force UTF-8 interpretation regardless of the document's own declaration.
	$dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html );

	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	$anchors = $dom->getElementsByTagName( 'a' );
	$links   = array();
	$seen    = array(); // Per-page dedup so one page counts a target once.

	foreach ( $anchors as $anchor ) {
		if ( ! ( $anchor instanceof DOMElement ) ) {
			continue;
		}

		$href = $anchor->getAttribute( 'href' );
		$url  = erankly_health_bl_canonicalize( erankly_health_bl_resolve_url( $href, $base_url ) );

		if ( '' === $url || isset( $seen[ $url ] ) ) {
			continue;
		}

		$seen[ $url ] = true;

		$text = trim( (string) preg_replace( '/\s+/', ' ', $anchor->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM textContent is a built-in PHP property.

		// Fall back to a child image's alt/title, then a generic marker.
		if ( '' === $text ) {
			$images = $anchor->getElementsByTagName( 'img' );

			if ( $images->length > 0 && $images->item( 0 ) instanceof DOMElement ) {
				$img  = $images->item( 0 );
				$text = trim( (string) ( '' !== $img->getAttribute( 'alt' ) ? $img->getAttribute( 'alt' ) : $img->getAttribute( 'title' ) ) );
			}
		}

		if ( mb_strlen( $text ) > 200 ) {
			$text = mb_substr( $text, 0, 200 );
		}

		$rel        = strtolower( $anchor->getAttribute( 'rel' ) );
		$nofollow   = '' !== $rel && in_array( 'nofollow', preg_split( '/\s+/', $rel ), true );
		$internal   = erankly_health_bl_is_internal( $url );
		$spiderable = $internal && ! erankly_health_bl_is_asset_url( $url );

		$links[] = array(
			'url'        => $url,
			'anchor'     => $text,
			'nofollow'   => $nofollow,
			'internal'   => $internal,
			'spiderable' => $spiderable,
		);
	}

	return $links;
}

/**
 * Records one discovered link into the crawl state's link map, bounded by the
 * distinct-link and per-link occurrence caps.
 *
 * @param array<string,mixed> $state  Crawl state (by reference).
 * @param array<string,mixed> $link   One record from erankly_health_bl_extract_links().
 * @param string              $source Canonical URL of the page it was found on.
 * @return void
 */
function erankly_health_bl_record_link( array &$state, array $link, string $source ): void {
	$url = (string) $link['url'];

	if ( ! isset( $state['links'][ $url ] ) ) {
		if ( count( $state['links'] ) >= ERANKLY_HEALTH_BL_MAX_LINKS ) {
			return; // Distinct-link budget exhausted.
		}

		$state['links'][ $url ] = array(
			'type'        => $link['internal'] ? 'internal' : 'external',
			'occurrences' => array(),
		);
	}

	// Cap occurrences per link so the state option stays bounded.
	if ( count( $state['links'][ $url ]['occurrences'] ) < 20 ) {
		$state['links'][ $url ]['occurrences'][] = array(
			'source'   => $source,
			'anchor'   => (string) $link['anchor'],
			'nofollow' => (bool) $link['nofollow'],
		);
	}
}

/**
 * Processes one discovery batch: fetches up to ERANKLY_HEALTH_BL_FETCH_BATCH
 * pages, records their links, and spiders new internal pages within the depth
 * and page budgets. Transitions to the checking phase once the queue drains.
 *
 * @param array<string,mixed> $state Crawl state.
 * @return array<string,mixed> Updated state.
 */
function erankly_health_bl_run_discovery_batch( array $state ): array {
	$processed = 0;

	while ( $processed < ERANKLY_HEALTH_BL_FETCH_BATCH && ! empty( $state['queue'] ) ) {
		if ( $state['pages_done'] >= ERANKLY_HEALTH_BL_MAX_PAGES ) {
			$state['queue'] = array();
			break;
		}

		$item  = array_shift( $state['queue'] );
		$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
		$depth = isset( $item['depth'] ) ? (int) $item['depth'] : 0;

		if ( '' === $url ) {
			continue;
		}

		++$processed;
		++$state['pages_done'];

		$html         = erankly_health_bl_fetch_html( $url );
		$via_fallback = false;

		// Loopback fetch failed (typical on local/staging): fall back to the
		// page's stored content so body links are still discovered.
		if ( null === $html ) {
			$html         = erankly_health_bl_render_internal_content( $url );
			$via_fallback = ( null !== $html );
		}

		if ( null === $html ) {
			++$state['stats']['fetch_failed'];
			continue; // Neither source available — nothing to extract.
		}

		if ( $via_fallback ) {
			++$state['stats']['fetch_fallback'];
		} else {
			++$state['stats']['fetch_ok'];
		}

		foreach ( erankly_health_bl_extract_links( $html, $url ) as $link ) {
			erankly_health_bl_record_link( $state, $link, $url );

			// Spider new internal pages within the depth and page budgets.
			if (
				$link['spiderable']
				&& ( $depth + 1 ) <= ERANKLY_HEALTH_BL_MAX_DEPTH
				&& ! isset( $state['visited'][ $link['url'] ] )
				&& ( count( $state['visited'] ) < ERANKLY_HEALTH_BL_MAX_PAGES )
			) {
				$state['visited'][ $link['url'] ] = true;
				$state['queue'][]                 = array(
					'url'   => $link['url'],
					'depth' => $depth + 1,
				);
			}
		}
	}

	// Discovery is done when the queue is empty or the page budget is spent.
	if ( empty( $state['queue'] ) || $state['pages_done'] >= ERANKLY_HEALTH_BL_MAX_PAGES ) {
		$state['queue']          = array();
		$state['check_queue']    = array_keys( $state['links'] );
		$state['stats']['pages'] = (int) $state['pages_done'];
		$state['stats']['links'] = count( $state['links'] );
		$state['status']         = 'checking';
	}

	return $state;
}

/**
 * Probes one URL's HTTP status with a HEAD request, falling back to GET when
 * HEAD is unusable (errored, not allowed, or bot-blocked at the HEAD level).
 *
 * @param string $url      Canonical URL to probe.
 * @param bool   $internal Whether the URL is on this site (loopback → no SSL verify).
 * @return array{code:int,state:string} state is 'ok'|'broken'|'unreachable'.
 */
function erankly_health_bl_probe( string $url, bool $internal = false ): array {
	$args = array(
		'timeout'     => ERANKLY_HEALTH_BL_HTTP_TIMEOUT,
		'redirection' => 5,
		'sslverify'   => (bool) apply_filters( 'erankly_health_bl_sslverify', ! $internal, $url, $internal ),
		'user-agent'  => 'EasyRankly Broken-Link Crawler/1.0; ' . home_url( '/' ),
		'headers'     => array( 'Accept' => '*/*' ),
	);

	$response = wp_remote_head( $url, $args );
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

	// HEAD is often blocked or unsupported; a GET gives the authoritative status.
	if ( in_array( $code, array( 0, 400, 403, 405, 501 ), true ) ) {
		$get      = wp_remote_get( $url, array_merge( $args, array( 'limit_response_size' => 4096 ) ) );
		$get_code = is_wp_error( $get ) ? 0 : (int) wp_remote_retrieve_response_code( $get );

		if ( 0 !== $get_code ) {
			$code = $get_code;
		}
	}

	if ( 0 === $code || 429 === $code ) {
		// Network failure, DNS, timeout, or transient rate-limiting: not a
		// definitive broken link — reported separately as "unreachable".
		return array(
			'code'  => $code,
			'state' => 'unreachable',
		);
	}

	if ( $code >= 400 && $code <= 599 ) {
		return array(
			'code'  => $code,
			'state' => 'broken',
		);
	}

	return array(
		'code'  => $code,
		'state' => 'ok',
	);
}

/**
 * Returns a URL's HTTP status, served from a short-lived per-URL cache so
 * re-running a scan (or re-checking a shared link) avoids duplicate requests.
 *
 * @param string $url      Canonical URL.
 * @param bool   $internal Whether the URL belongs to this site.
 * @return array{code:int,state:string}
 */
function erankly_health_bl_check_url_status( string $url, bool $internal ): array {
	$cache_key = ERANKLY_HEALTH_BL_CACHE_PREFIX . md5( $url );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && isset( $cached['state'] ) ) {
		return $cached;
	}

	$result = null;

	// Internal links that resolve to a published post are healthy without an
	// HTTP round-trip — this keeps valid internal links from being flagged as
	// "unreachable" when loopback requests fail (local/staging).
	if ( $internal ) {
		$post_id = url_to_postid( $url );

		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			$result = array(
				'code'  => 200,
				'state' => 'ok',
			);
		}
	}

	if ( null === $result ) {
		$result = erankly_health_bl_probe( $url, $internal );
	}

	// Unreachable results are cached briefly so a transient failure can be retried
	// on the next scan; definitive results honor the internal/external TTLs.
	if ( 'unreachable' === $result['state'] ) {
		$ttl = 5 * MINUTE_IN_SECONDS;
	} else {
		$ttl = $internal ? ERANKLY_HEALTH_BL_CACHE_TTL_INTERNAL : ERANKLY_HEALTH_BL_CACHE_TTL_EXTERNAL;
	}

	set_transient( $cache_key, $result, $ttl );

	return $result;
}

/**
 * Processes one checking batch: status-checks up to ERANKLY_HEALTH_BL_CHECK_BATCH
 * links, accumulating broken and unreachable ones (with their occurrences) into
 * the state. Finalizes the results once the check queue drains.
 *
 * @param array<string,mixed> $state Crawl state.
 * @return array<string,mixed> Updated state.
 */
function erankly_health_bl_run_checking_batch( array $state ): array {
	$processed = 0;

	while ( $processed < ERANKLY_HEALTH_BL_CHECK_BATCH && ! empty( $state['check_queue'] ) ) {
		$url = (string) array_shift( $state['check_queue'] );
		++$processed;
		++$state['checks_done'];

		if ( '' === $url ) {
			continue;
		}

		$meta = isset( $state['links'][ $url ] ) && is_array( $state['links'][ $url ] )
			? $state['links'][ $url ]
			: array(
				'type'        => erankly_health_bl_is_internal( $url ) ? 'internal' : 'external',
				'occurrences' => array(),
			);

		$status = erankly_health_bl_check_url_status( $url, 'internal' === $meta['type'] );

		if ( 'ok' === $status['state'] ) {
			continue;
		}

		if ( 'broken' === $status['state'] ) {
			++$state['stats']['broken'];
		}

		if ( count( $state['found'] ) < ERANKLY_HEALTH_BL_MAX_RESULTS ) {
			$state['found'][] = array(
				'url'         => $url,
				'type'        => (string) $meta['type'],
				'code'        => (int) $status['code'],
				'state'       => (string) $status['state'],
				'occurrences' => array_slice( (array) $meta['occurrences'], 0, 20 ),
			);
		}
	}

	$state['stats']['checked'] = (int) $state['checks_done'];

	if ( empty( $state['check_queue'] ) ) {
		erankly_health_bl_finalize_results( $state );
		$state['status'] = 'done';

		// Results are persisted separately; drop the heavy working data so the
		// lingering state option stays small.
		$state['links']   = array();
		$state['found']   = array();
		$state['visited'] = array();
	}

	return $state;
}

/**
 * Sorts the accumulated broken/unreachable links and persists them as the final
 * results (internal first, definitive breaks before unreachable, most-linked
 * first). Stored capped in a no-autoload option.
 *
 * @param array<string,mixed> $state Crawl state.
 * @return void
 */
function erankly_health_bl_finalize_results( array $state ): void {
	$found = isset( $state['found'] ) && is_array( $state['found'] ) ? $state['found'] : array();

	usort(
		$found,
		static function ( array $a, array $b ): int {
			// Internal broken links first (they are directly fixable via redirect).
			$a_internal = ( 'internal' === ( $a['type'] ?? '' ) ) ? 0 : 1;
			$b_internal = ( 'internal' === ( $b['type'] ?? '' ) ) ? 0 : 1;
			if ( $a_internal !== $b_internal ) {
				return $a_internal <=> $b_internal;
			}

			// Definitive 4xx/5xx before "unreachable".
			$a_broken = ( 'broken' === ( $a['state'] ?? '' ) ) ? 0 : 1;
			$b_broken = ( 'broken' === ( $b['state'] ?? '' ) ) ? 0 : 1;
			if ( $a_broken !== $b_broken ) {
				return $a_broken <=> $b_broken;
			}

			// Then most-referenced first.
			return count( (array) ( $b['occurrences'] ?? array() ) ) <=> count( (array) ( $a['occurrences'] ?? array() ) );
		}
	);

	if ( count( $found ) > ERANKLY_HEALTH_BL_MAX_RESULTS ) {
		$found = array_slice( $found, 0, ERANKLY_HEALTH_BL_MAX_RESULTS );
	}

	$stats = isset( $state['stats'] ) && is_array( $state['stats'] ) ? $state['stats'] : array();

	update_option(
		ERANKLY_HEALTH_BL_RESULTS_OPTION,
		array(
			'scanned_at'     => time(),
			'pages'          => isset( $stats['pages'] ) ? (int) $stats['pages'] : 0,
			'links'          => isset( $stats['links'] ) ? (int) $stats['links'] : 0,
			'checked'        => isset( $stats['checked'] ) ? (int) $stats['checked'] : 0,
			'broken'         => isset( $stats['broken'] ) ? (int) $stats['broken'] : 0,
			'fetch_ok'       => isset( $stats['fetch_ok'] ) ? (int) $stats['fetch_ok'] : 0,
			'fetch_fallback' => isset( $stats['fetch_fallback'] ) ? (int) $stats['fetch_fallback'] : 0,
			'fetch_failed'   => isset( $stats['fetch_failed'] ) ? (int) $stats['fetch_failed'] : 0,
			'items'          => array_values( $found ),
		),
		false
	);
}

/**
 * Returns the finished Broken-Link results, or null if no scan has completed.
 *
 * @return array{scanned_at:int,pages:int,links:int,checked:int,broken:int,fetch_ok:int,fetch_fallback:int,fetch_failed:int,items:array<int,array<string,mixed>>}|null
 */
function erankly_health_bl_get_results(): ?array {
	$data = get_option( ERANKLY_HEALTH_BL_RESULTS_OPTION, null );

	if ( ! is_array( $data ) ) {
		return null;
	}

	return array(
		'scanned_at'     => isset( $data['scanned_at'] ) ? absint( $data['scanned_at'] ) : 0,
		'pages'          => isset( $data['pages'] ) ? absint( $data['pages'] ) : 0,
		'links'          => isset( $data['links'] ) ? absint( $data['links'] ) : 0,
		'checked'        => isset( $data['checked'] ) ? absint( $data['checked'] ) : 0,
		'broken'         => isset( $data['broken'] ) ? absint( $data['broken'] ) : 0,
		'fetch_ok'       => isset( $data['fetch_ok'] ) ? absint( $data['fetch_ok'] ) : 0,
		'fetch_fallback' => isset( $data['fetch_fallback'] ) ? absint( $data['fetch_fallback'] ) : 0,
		'fetch_failed'   => isset( $data['fetch_failed'] ) ? absint( $data['fetch_failed'] ) : 0,
		'items'          => isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array(),
	);
}

/**
 * Handles the on-demand "Suggest with AI" request for one internal broken link.
 *
 * Reuses the same AI suggestion engine (and per-path cache) as the frequent-404
 * scanner, keyed by the broken link's site-relative path.
 *
 * @return void
 */
function erankly_health_bl_handle_ai_suggest(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_bl_ai_suggest' );

	$raw_url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$canonical = erankly_health_bl_canonicalize( $raw_url );
	$outcome   = 'error';

	if ( ! function_exists( 'erankly_ai_enabled' ) || ! erankly_ai_enabled() ) {
		$outcome = 'disabled';
	} elseif ( '' !== $canonical && erankly_health_bl_is_internal( $canonical ) ) {
		$path = erankly_health_normalize_link_path( $canonical );

		if ( '' !== $path ) {
			$suggestion = erankly_health_ai_suggest_redirect_target( array( 'path' => $path ) );

			if ( null !== $suggestion ) {
				$outcome = 'suggested';
			} else {
				$outcome = 'none' === erankly_health_ai_cached_suggestion( $path )['state'] ? 'none' : 'error';
			}
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'erankly',
				'erankly_tab'       => 'health',
				'erankly_health_ai' => $outcome,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Handles clearing the finished Broken-Link results (and any stale run state).
 *
 * @return void
 */
function erankly_health_bl_handle_clear(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_bl_clear' );

	erankly_health_bl_reset_state();
	delete_option( ERANKLY_HEALTH_BL_RESULTS_OPTION );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                      => 'erankly',
				'erankly_tab'               => 'health',
				'erankly_health_bl_cleared' => '1',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Returns a human-readable status label for a broken-link result row.
 *
 * @param array<string,mixed> $item Result item.
 * @return string
 */
function erankly_health_bl_status_label( array $item ): string {
	$code = isset( $item['code'] ) ? (int) $item['code'] : 0;

	if ( 'unreachable' === ( $item['state'] ?? '' ) ) {
		return 429 === $code
			? __( 'Rate-limited (429)', 'easyrankly' )
			: __( 'Unreachable', 'easyrankly' );
	}

	return (string) $code;
}

/**
 * Renders the "Found on" cell: the pages where a broken link appears, with the
 * anchor text used and a link to edit each source page.
 *
 * @param array<int,array<string,mixed>> $occurrences Occurrence records.
 * @return void
 */
function erankly_health_bl_render_found_on( array $occurrences ): void {
	if ( empty( $occurrences ) ) {
		echo '<span class="description">&mdash;</span>';
		return;
	}

	$shown     = array_slice( $occurrences, 0, 3 );
	$remaining = count( $occurrences ) - count( $shown );

	foreach ( $shown as $occ ) {
		$source  = isset( $occ['source'] ) ? (string) $occ['source'] : '';
		$anchor  = isset( $occ['anchor'] ) ? (string) $occ['anchor'] : '';
		$post_id = '' !== $source ? url_to_postid( $source ) : 0;
		$edit    = $post_id ? (string) get_edit_post_link( $post_id ) : '';
		$label   = $post_id ? (string) get_the_title( $post_id ) : erankly_health_normalize_link_path( $source );

		if ( '' === $label ) {
			$label = $source;
		}

		echo '<div class="erankly-bl-occ">';
		if ( '' !== $anchor ) {
			printf( '<span class="erankly-bl-anchor">&ldquo;%s&rdquo;</span> ', esc_html( $anchor ) );
		} else {
			printf( '<span class="erankly-bl-anchor description">%s</span> ', esc_html__( '(no anchor text)', 'easyrankly' ) );
		}

		if ( '' !== $edit ) {
			printf( '<span class="description">&mdash; <a href="%1$s">%2$s</a></span>', esc_url( $edit ), esc_html( $label ) );
		} else {
			printf( '<span class="description">&mdash; %s</span>', esc_html( $label ) );
		}
		echo '</div>';
	}

	if ( $remaining > 0 ) {
		printf(
			'<span class="description">%s</span>',
			/* translators: %d: number of additional pages the broken link appears on. */
			esc_html( sprintf( _n( '+%d more page', '+%d more pages', $remaining, 'easyrankly' ), $remaining ) )
		);
	}
}

/**
 * Renders the "Action" cell for a broken-link row.
 *
 * Internal broken links reuse the 404 workflow (lexical + AI suggestion and a
 * pre-filled "Create 301 redirect" deep link). External broken links cannot be
 * fixed with a redirect, so the action links to editing the source page.
 *
 * @param array<string,mixed> $item          Result item.
 * @param bool                $redirects_on  Whether the Redirects module is on.
 * @return void
 */
function erankly_health_bl_render_action_cell( array $item, bool $redirects_on ): void {
	$url  = isset( $item['url'] ) ? (string) $item['url'] : '';
	$type = isset( $item['type'] ) ? (string) $item['type'] : 'external';

	if ( 'internal' === $type ) {
		$path       = erankly_health_normalize_link_path( $url );
		$suggestion = '' !== $path ? erankly_health_suggest_redirect_target( array( 'path' => $path ) ) : null;
		$ai_button  = false;
		$ai_tried   = false;

		if ( null === $suggestion && '' !== $path && function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) {
			$cache = erankly_health_ai_cached_suggestion( $path );

			if ( 'hit' === $cache['state'] ) {
				$suggestion = $cache['suggestion'];
			} elseif ( 'none' === $cache['state'] ) {
				$ai_tried = true;
			} else {
				$ai_button = true;
			}
		}

		if ( null !== $suggestion ) {
			printf(
				'<div class="erankly-bl-suggestion"><span class="description">%1$s</span> <a href="%2$s" target="_blank" rel="noopener noreferrer"><code>%3$s</code></a></div>',
				esc_html__( 'Suggested target:', 'easyrankly' ),
				esc_url( home_url( (string) $suggestion['target'] ) ),
				esc_html( (string) $suggestion['target'] )
			);
		} elseif ( $ai_tried ) {
			printf( '<div class="description">%s</div>', esc_html__( 'No match (AI included).', 'easyrankly' ) );
		}

		if ( ! $redirects_on ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( add_query_arg( 'erankly_tab', 'features', erankly_setup_wizard_settings_url() ) ),
				esc_html__( 'Enable Redirects to fix', 'easyrankly' )
			);
		} elseif ( '' !== $path ) {
			$normalized_source = class_exists( 'ERankly_Redirects_Normalizer' )
				? ERankly_Redirects_Normalizer::normalize_source( $path, false, false )
				: $path;

			$create_url = add_query_arg(
				array(
					'erankly_tab'                      => 'redirects',
					'erankly_redirects_prefill_source' => $normalized_source,
					'erankly_redirects_prefill_target' => null !== $suggestion ? (string) $suggestion['target'] : '',
					'erankly_redirects_prefill_status' => 301,
					'erankly_redirects_prefill_note'   => __( 'Created from Broken-Link crawler', 'easyrankly' ),
				),
				erankly_setup_wizard_settings_url()
			);

			printf(
				'<a class="button button-primary" href="%1$s">%2$s</a>',
				esc_url( $create_url ),
				esc_html__( 'Create 301 redirect', 'easyrankly' )
			);
		}

		if ( $ai_button ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 0 0 6px;">
				<input type="hidden" name="action" value="erankly_health_bl_ai_suggest">
				<input type="hidden" name="url" value="<?php echo esc_attr( $url ); ?>">
				<?php wp_nonce_field( 'erankly_health_bl_ai_suggest' ); ?>
				<button type="submit" class="button-link"><?php esc_html_e( 'Suggest with AI', 'easyrankly' ); ?></button>
			</form>
			<?php
		}

		return;
	}

	// External broken link: fix means editing the page that contains it.
	$first   = isset( $item['occurrences'][0]['source'] ) ? (string) $item['occurrences'][0]['source'] : '';
	$post_id = '' !== $first ? url_to_postid( $first ) : 0;

	if ( $post_id && get_edit_post_link( $post_id ) ) {
		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a>',
			esc_url( (string) get_edit_post_link( $post_id ) ),
			esc_html__( 'Edit page', 'easyrankly' )
		);
	} elseif ( '' !== $first ) {
		printf(
			'<a class="button button-secondary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $first ),
			esc_html__( 'Open source page', 'easyrankly' )
		);
	} else {
		echo '<span class="description">&mdash;</span>';
	}
}

/**
 * Renders the "Broken-Link Candidates" section of the Health tab.
 *
 * @return void
 */
function erankly_health_bl_render_section(): void {
	$results      = erankly_health_bl_get_results();
	$redirects_on = function_exists( 'erankly_redirects_enabled' ) && erankly_redirects_enabled();
	$ai_on        = function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled();
	$run_status   = (string) erankly_health_bl_get_state()['status'];
	$was_cleared  = isset( $_GET['erankly_health_bl_cleared'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_bl_cleared'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$has_items    = null !== $results && ! empty( $results['items'] );
	?>
	<div class="erankly-settings-section erankly-panel-expandable" id="erankly-bl-table-wrap" data-erankly-expandable>
		<h3 class="erankly-section-title"><?php esc_html_e( 'Broken-Link Candidates', 'easyrankly' ); ?></h3>
		<section class="erankly-card">
		<?php if ( $was_cleared ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Broken-link scan data cleared.', 'easyrankly' ); ?></p></div>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: max pages crawled. 2: spider depth. 3: max links checked. */
				esc_html__( 'On demand, crawls the rendered HTML of your indexable pages (up to %1$d pages, %2$d levels deep) and checks the status of every distinct link found — internal and external, up to %3$d. Links returning 4xx/5xx are listed below with their anchor text and source page.', 'easyrankly' ),
				absint( ERANKLY_HEALTH_BL_MAX_PAGES ),
				absint( ERANKLY_HEALTH_BL_MAX_DEPTH ),
				absint( ERANKLY_HEALTH_BL_MAX_LINKS )
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Fix internal broken links with a 301 redirect (optionally AI-suggested); external ones link back to the page to edit. Results are cached briefly, so re-scanning is fast.', 'easyrankly' ); ?>
		</p>
		<?php if ( $ai_on ) : ?>
			<p class="description">
				<?php esc_html_e( 'AI suggestions run only when you click "Suggest with AI" on an internal broken link, and only send that link\'s slug words plus candidate page titles/paths to your configured AI provider.', 'easyrankly' ); ?>
			</p>
		<?php endif; ?>

		<div class="erankly-panel-toolbar">
			<div
				id="erankly-bl"
				class="erankly-panel-controls"
				data-rest-base="<?php echo esc_url( rest_url( 'erankly/v1/health/broken-links/' ) ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-status="<?php echo esc_attr( $run_status ); ?>"
			>
				<button type="button" class="button button-secondary" id="erankly-bl-start"><?php esc_html_e( 'Run broken-link scan', 'easyrankly' ); ?></button>
				<button type="button" class="button-link erankly-bl-cancel" id="erankly-bl-cancel" hidden><?php esc_html_e( 'Cancel', 'easyrankly' ); ?></button>
				<?php if ( $has_items ) : ?>
					<label for="erankly-bl-filter" class="screen-reader-text"><?php esc_html_e( 'Filter broken links', 'easyrankly' ); ?></label>
					<input type="search" id="erankly-bl-filter" class="erankly-panel-filter" data-erankly-filter placeholder="<?php esc_attr_e( 'Filter links…', 'easyrankly' ); ?>" autocomplete="off">
				<?php endif; ?>
			</div>
			<?php erankly_admin_render_panel_expand_toggle( 'erankly-bl-table-wrap' ); ?>
		</div>

		<div id="erankly-bl-progress" class="erankly-bl-progress" role="status" aria-live="polite" hidden></div>

		<table class="widefat striped erankly-panel-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Broken link', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Found on', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'easyrankly' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $has_items ) : ?>
					<tr>
						<td class="erankly-panel-empty" colspan="5">
							<?php
							if ( null === $results ) {
								esc_html_e( 'No scan has been run yet. Click "Run broken-link scan" to start.', 'easyrankly' );
							} else {
								esc_html_e( 'No broken links found.', 'easyrankly' );
							}
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $results['items'] as $item ) : ?>
						<tr class="erankly-bl-row" data-erankly-filter-row data-filter-text="<?php echo esc_attr( strtolower( (string) $item['url'] ) ); ?>">
							<td><a href="<?php echo esc_url( (string) $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) $item['url'] ); ?></code></a></td>
							<td><?php echo esc_html( erankly_health_bl_status_label( $item ) ); ?></td>
							<td>
								<?php
								echo 'internal' === ( $item['type'] ?? '' )
									? esc_html__( 'Internal', 'easyrankly' )
									: esc_html__( 'External', 'easyrankly' );
								?>
							</td>
							<td><?php erankly_health_bl_render_found_on( isset( $item['occurrences'] ) && is_array( $item['occurrences'] ) ? $item['occurrences'] : array() ); ?></td>
							<td><?php erankly_health_bl_render_action_cell( $item, $redirects_on ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( null !== $results ) : ?>
			<?php if ( 0 === (int) $results['fetch_ok'] && ( (int) $results['fetch_fallback'] + (int) $results['fetch_failed'] ) > 0 ) : ?>
				<div class="erankly-panel-callout">
					<svg class="erankly-panel-callout-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
						<path d="M12 9v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
						<path d="M12 17h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
					</svg>
					<p>
						<strong><?php esc_html_e( 'Couldn’t reach your site over HTTP.', 'easyrankly' ); ?></strong>
						<?php esc_html_e( 'Common on local or staging setups. Internal links were read from the database, so theme and page-builder links may be missing; external checks still need internet.', 'easyrankly' ); ?>
					</p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: date/time. 2: pages fetched over HTTP. 3: pages read from the database. 4: pages that failed. 5: links checked. 6: broken count. */
					esc_html__( 'Last scan: %1$s — %2$d pages fetched, %3$d read from database, %4$d unreadable; %5$d links checked, %6$d broken.', 'easyrankly' ),
					esc_html( erankly_health_format_timestamp( absint( $results['scanned_at'] ) ) ),
					absint( $results['fetch_ok'] ),
					absint( $results['fetch_fallback'] ),
					absint( $results['fetch_failed'] ),
					absint( $results['checked'] ),
					absint( $results['broken'] )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="erankly_health_bl_clear">
				<?php wp_nonce_field( 'erankly_health_bl_clear' ); ?>
				<?php submit_button( __( 'Clear broken-link scan data', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		</section>
	</div>
	<?php
}

/**
 * Renders the Health settings tab.
 *
 * @return void
 */
function erankly_health_render_panel(): void {
	$frequent_404s = erankly_health_get_frequent_404s();
	$thin_content  = erankly_health_get_thin_content();
	$was_cleared   = isset( $_GET['erankly_health_clear'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_clear'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$was_scanned   = isset( $_GET['erankly_health_scanned'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_scanned'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$health_state  = isset( $_GET['erankly_health_state'] ) ? sanitize_key( wp_unslash( $_GET['erankly_health_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$health_ai     = isset( $_GET['erankly_health_ai'] ) ? sanitize_key( wp_unslash( $_GET['erankly_health_ai'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	?>
	<?php if ( $was_cleared ) : ?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'Frequent 404 scanner data cleared.', 'easyrankly' ); ?></p>
		</div>
	<?php endif; ?>
	<?php if ( $was_scanned ) : ?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'Content insights scan completed.', 'easyrankly' ); ?></p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( $health_state, array( 'ignored', 'resolved', 'restored' ), true ) ) : ?>
		<div class="notice notice-success inline">
			<p>
				<?php
				if ( 'ignored' === $health_state ) {
					esc_html_e( '404 marked as ignored.', 'easyrankly' );
				} elseif ( 'resolved' === $health_state ) {
					esc_html_e( '404 marked as resolved.', 'easyrankly' );
				} else {
					esc_html_e( '404 restored to the active list.', 'easyrankly' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( $health_ai, array( 'suggested', 'none', 'error', 'disabled' ), true ) ) : ?>
		<?php $erankly_ai_notice_class = 'suggested' === $health_ai ? 'notice-success' : ( 'error' === $health_ai ? 'notice-error' : ( 'disabled' === $health_ai ? 'notice-warning' : 'notice-info' ) ); ?>
		<div class="notice <?php echo esc_attr( $erankly_ai_notice_class ); ?> inline">
			<p>
				<?php
				if ( 'suggested' === $health_ai ) {
					esc_html_e( 'AI suggestion ready.', 'easyrankly' );
				} elseif ( 'none' === $health_ai ) {
					esc_html_e( 'The AI found no sensible match for this 404.', 'easyrankly' );
				} elseif ( 'disabled' === $health_ai ) {
					esc_html_e( 'Enable AI features to use AI suggestions.', 'easyrankly' );
				} else {
					esc_html_e( 'The AI request failed. Please try again.', 'easyrankly' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>
	<div class="erankly-settings-fields">
		<div class="erankly-settings-section erankly-panel-expandable" id="erankly-404-table-wrap" data-erankly-expandable>
			<h3 class="erankly-section-title"><?php esc_html_e( 'Frequent 404 scanner', 'easyrankly' ); ?></h3>
			<section class="erankly-card">
			<p class="description">
				<?php
				printf(
					/* translators: 1: 404 threshold. 2: Monitoring window in hours. */
					esc_html__( 'Lists only paths reaching at least %1$d estimated 404 hits within %2$d hours. Lower-volume 404s are sampled into short-lived counters and not listed individually.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_404_THRESHOLD ),
					absint( ERANKLY_HEALTH_404_WINDOW / HOUR_IN_SECONDS )
				);
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %d: Retention period in days. */
					esc_html__( 'Privacy: paths are anonymized before storage (emails, UUIDs, long numbers and tokens become neutral placeholders). Only same-site referrer paths are recorded; external referrers are never stored. Data is purged after %d days.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_404_RETENTION_DAYS )
				);
				?>
			</p>
			<?php if ( function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) : ?>
				<p class="description">
					<?php esc_html_e( 'AI suggestions: clicking "Suggest with AI" sends the broken URL\'s slug words and the titles/paths of candidate pages to your configured AI provider. It runs only on click.', 'easyrankly' ); ?>
				</p>
			<?php endif; ?>

			<?php
			$erankly_redirects_on   = function_exists( 'erankly_redirects_enabled' ) && erankly_redirects_enabled();
			$erankly_404_partition  = erankly_health_partition_404s( $frequent_404s );
			$erankly_404_active     = $erankly_404_partition['active'];
			$erankly_404_handled    = (int) $erankly_404_partition['handled'];
			$erankly_404_managed    = $erankly_404_partition['managed'];
			$erankly_404_has_active = ! empty( $erankly_404_active );
			?>
			<div class="erankly-panel-toolbar">
				<div class="erankly-panel-controls">
					<?php if ( $erankly_404_has_active ) : ?>
						<label for="erankly-404-filter" class="screen-reader-text"><?php esc_html_e( 'Filter 404 paths', 'easyrankly' ); ?></label>
						<input type="search" id="erankly-404-filter" class="erankly-panel-filter" data-erankly-filter placeholder="<?php esc_attr_e( 'Filter paths…', 'easyrankly' ); ?>" autocomplete="off">
					<?php endif; ?>
				</div>
				<?php erankly_admin_render_panel_expand_toggle( 'erankly-404-table-wrap' ); ?>
			</div>

			<table class="widefat striped erankly-panel-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Path', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Estimated hits', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'First seen', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last seen', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Suggestion', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'easyrankly' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $erankly_404_has_active ) : ?>
						<tr>
							<td class="erankly-panel-empty" colspan="6">
								<?php
								if ( empty( $frequent_404s ) ) {
									esc_html_e( 'No frequent 404s detected in the current monitoring window.', 'easyrankly' );
								} else {
									esc_html_e( 'All frequent 404s are currently handled by an active redirect.', 'easyrankly' );
								}
								?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $erankly_404_active as $entry ) : ?>
							<?php
							$erankly_path       = (string) $entry['path'];
							$erankly_anonymized = erankly_health_path_is_anonymized( $erankly_path );
							$erankly_suggestion = erankly_health_suggest_redirect_target( $entry );
							$erankly_ai_button  = false;
							$erankly_ai_tried   = false;

							// Fallback: when the lexical engine found nothing and AI is on, surface a
							// cached AI suggestion or offer the on-demand "Suggest with AI" button.
							if ( null === $erankly_suggestion && ! $erankly_anonymized && function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) {
								$erankly_ai_cache = erankly_health_ai_cached_suggestion( $erankly_path );

								if ( 'hit' === $erankly_ai_cache['state'] ) {
									$erankly_suggestion = $erankly_ai_cache['suggestion'];
								} elseif ( 'none' === $erankly_ai_cache['state'] ) {
									$erankly_ai_tried = true;
								} else {
									$erankly_ai_button = true;
								}
							}
							?>
							<tr data-erankly-filter-row data-filter-text="<?php echo esc_attr( strtolower( $erankly_path ) ); ?>">
								<td>
									<code><?php echo esc_html( $erankly_path ); ?></code>
									<?php
									$erankly_refs = isset( $entry['referrers'] ) && is_array( $entry['referrers'] ) ? $entry['referrers'] : array();
									arsort( $erankly_refs );
									$erankly_refs = array_slice( array_keys( $erankly_refs ), 0, 3 );
									?>
									<?php if ( ! empty( $erankly_refs ) ) : ?>
										<br>
										<span class="description">
											<?php
											printf(
												/* translators: %s: comma-separated internal pages linking to this 404. */
												esc_html__( 'Linked from: %s', 'easyrankly' ),
												esc_html( implode( ', ', $erankly_refs ) )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( absint( $entry['count'] ) ) ); ?></td>
								<td><?php echo esc_html( erankly_health_format_timestamp( absint( $entry['first_seen'] ) ) ); ?></td>
								<td><?php echo esc_html( erankly_health_format_timestamp( absint( $entry['last_seen'] ) ) ); ?></td>
								<td><?php erankly_health_render_404_suggestion_cell( $erankly_suggestion, $erankly_anonymized, $erankly_ai_tried ); ?></td>
								<td><?php erankly_health_render_404_action_cell( $entry, $erankly_suggestion, $erankly_anonymized, $erankly_redirects_on, $erankly_ai_button ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $erankly_404_handled > 0 ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %d: number of 404 paths now covered by an active redirect. */
						esc_html( _n( '%d frequent 404 is now handled by a redirect.', '%d frequent 404s are now handled by a redirect.', $erankly_404_handled, 'easyrankly' ) ),
						absint( $erankly_404_handled )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=erankly&erankly_tab=redirects' ) ); ?>"><?php esc_html_e( 'View redirect hit metrics', 'easyrankly' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $erankly_404_managed ) ) : ?>
				<details class="erankly-health-managed" style="margin-top:8px;">
					<summary>
						<?php
						/* translators: %d: number of ignored or resolved 404 paths. */
						echo esc_html( sprintf( _n( 'Ignored / resolved (%d)', 'Ignored / resolved (%d)', count( $erankly_404_managed ), 'easyrankly' ), absint( count( $erankly_404_managed ) ) ) );
						?>
					</summary>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Path', 'easyrankly' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'easyrankly' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Action', 'easyrankly' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $erankly_404_managed as $managed_entry ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $managed_entry['path'] ); ?></code></td>
									<td>
										<?php
										echo 'ignored' === ( $managed_entry['state'] ?? '' )
											? esc_html__( 'Ignored', 'easyrankly' )
											: esc_html__( 'Resolved', 'easyrankly' );
										?>
									</td>
									<td><?php erankly_health_render_404_state_forms( isset( $managed_entry['hash'] ) ? (string) $managed_entry['hash'] : '', (string) ( $managed_entry['state'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="erankly_health_clear_404s">
				<?php wp_nonce_field( 'erankly_health_clear_404s' ); ?>
				<?php submit_button( __( 'Clear 404 scanner data', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
			</section>
		</div>
		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Content insights (heuristic)', 'easyrankly' ); ?></h3>
			<fieldset class="erankly-field erankly-card">
			<legend class="screen-reader-text"><?php esc_html_e( 'Content insights (heuristic)', 'easyrankly' ); ?></legend>
			<p class="description">
				<?php
				printf(
					/* translators: 1: Minimum character threshold. */
					esc_html__( 'A heuristic, not a definitive SEO diagnosis. Pages are flagged as potentially thin when they meet at least 2 of 3 conditions: under %1$d characters of visible text, no internal inbound links, and no internal outbound links. Results are cached; re-run the scan to refresh.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_THIN_MIN_CHARS )
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Elementor, Divi and WPBakery pages are excluded — their content lives in post meta, not the post body, causing false positives. Gutenberg block content is analysed correctly.', 'easyrankly' ); ?>
			</p>

			<?php if ( null === $thin_content ) : ?>
				<p><?php esc_html_e( 'No scan has been run yet. Click the button below to start.', 'easyrankly' ); ?></p>
			<?php elseif ( empty( $thin_content['pages'] ) ) : ?>
				<p><?php esc_html_e( 'No heuristically thin content detected.', 'easyrankly' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Page', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Characters', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Inbound links', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Outbound links', 'easyrankly' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $thin_content['pages'] as $page ) : ?>
							<tr>
								<td>
									<?php if ( ! empty( $page['edit_url'] ) ) : ?>
										<a href="<?php echo esc_url( (string) $page['edit_url'] ); ?>"><?php echo esc_html( (string) $page['title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( (string) $page['title'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( absint( $page['char_count'] ) ) ); ?></td>
								<td>
									<?php if ( $page['has_inbound'] ) : ?>
										<?php esc_html_e( 'Yes', 'easyrankly' ); ?>
									<?php else : ?>
										<strong><?php esc_html_e( 'No', 'easyrankly' ); ?></strong>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $page['has_outbound'] ) : ?>
										<?php esc_html_e( 'Yes', 'easyrankly' ); ?>
									<?php else : ?>
										<strong><?php esc_html_e( 'No', 'easyrankly' ); ?></strong>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( null !== $thin_content ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: Number of pages analysed. 2: Formatted date/time of last scan. */
						esc_html__( 'Last scan: %2$s, %1$d pages analysed.', 'easyrankly' ),
						absint( $thin_content['scanned_count'] ),
						esc_html( erankly_health_format_timestamp( absint( $thin_content['scanned_at'] ) ) )
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="erankly_health_scan_thin">
				<?php wp_nonce_field( 'erankly_health_scan_thin' ); ?>
				<?php submit_button( __( 'Run content insights scan', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		</fieldset>
		</div>
		<?php erankly_health_bl_render_section(); ?>
	</div>
	<?php
}
