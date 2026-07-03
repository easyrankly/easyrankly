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
define( 'ERANKLY_HEALTH_404_CANDIDATES_OPTION', 'erankly_health_404_candidates' );
define( 'ERANKLY_HEALTH_404_FREQUENT_OPTION', 'erankly_health_404_frequent' );

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

	// WordPress privacy tools — 404 paths are anonymized and not user-linked, but
	// site admins can initiate a full wipe from the Privacy → Erase Personal Data flow.

	if ( is_admin() ) {
		add_action( 'admin_post_erankly_health_clear_404s', 'erankly_health_handle_clear_404s' );
		add_action( 'admin_post_erankly_health_scan_thin', 'erankly_health_handle_scan_thin' );
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

	erankly_health_record_404_path( $path );
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
 * Updates aggregate counters for one normalized 404 path.
 *
 * @param string $path Normalized request path.
 * @return void
 */
function erankly_health_record_404_path( string $path ): void {
	$path = erankly_health_sanitize_404_path( $path );

	if ( '' === $path ) {
		return;
	}

	/**
	 * Filters the 404 counter sampling rate.
	 *
	 * A rate of 5 stores approximately one sample every five requests and adds
	 * five to the estimated counter, avoiding a database write for most bot 404s.
	 * Use 1 for exact synchronous counters.
	 *
	 * @param int    $sample_rate Sampling rate.
	 * @param string $path        Normalized 404 path.
	 */
	$sample_rate = max( 1, (int) apply_filters( 'erankly_health_404_sample_rate', 5, $path ) );

	if ( $sample_rate > 1 && 1 !== wp_rand( 1, $sample_rate ) ) {
		return;
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
		);
	}

	return $clean;
}

/**
 * Sanitizes and anonymizes a stored 404 path.
 *
 * Path segments that look like personal data (emails, UUIDs, long numbers, opaque
 * tokens) are replaced with neutral placeholders before storage so the original URL
 * can never be reconstructed.
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
 * The replacement is irreversible so stored paths cannot be used to reconstruct
 * the original URL or identify an individual.
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
 * Renders the Health settings tab.
 *
 * @return void
 */
function erankly_health_render_panel(): void {
	$frequent_404s = erankly_health_get_frequent_404s();
	$thin_content  = erankly_health_get_thin_content();
	$was_cleared   = isset( $_GET['erankly_health_clear'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_clear'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$was_scanned   = isset( $_GET['erankly_health_scanned'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_scanned'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	?>
	<h2><?php esc_html_e( 'Health', 'easyrankly' ); ?></h2>
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
	<div class="erankly-settings-fields">
		<fieldset class="erankly-field">
			<legend><strong><?php esc_html_e( 'Frequent 404 scanner', 'easyrankly' ); ?></strong></legend>
			<p class="description">
				<?php
				printf(
					/* translators: 1: 404 threshold. 2: Monitoring window in hours. */
					esc_html__( 'The scanner lists only paths that reach at least %1$d estimated 404 hits within %2$d hours. Lower-volume 404s are sampled into short-lived aggregate counters and are not listed individually.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_404_THRESHOLD ),
					absint( ERANKLY_HEALTH_404_WINDOW / HOUR_IN_SECONDS )
				);
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %d: Retention period in days. */
					esc_html__( 'Privacy: paths are anonymized before storage — emails, UUIDs, long numbers, and tokens are replaced with neutral placeholders. Data is automatically purged after %d days.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_404_RETENTION_DAYS )
				);
				?>
			</p>

			<?php if ( empty( $frequent_404s ) ) : ?>
				<p><?php esc_html_e( 'No frequent 404s detected in the current monitoring window.', 'easyrankly' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Path', 'easyrankly' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Estimated hits', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'First seen', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last seen', 'easyrankly' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $frequent_404s as $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $entry['path'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( absint( $entry['count'] ) ) ); ?></td>
								<td><?php echo esc_html( erankly_health_format_timestamp( absint( $entry['first_seen'] ) ) ); ?></td>
								<td><?php echo esc_html( erankly_health_format_timestamp( absint( $entry['last_seen'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="erankly_health_clear_404s">
				<?php wp_nonce_field( 'erankly_health_clear_404s' ); ?>
				<?php submit_button( __( 'Clear 404 scanner data', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		</fieldset>
		<fieldset class="erankly-field">
			<legend><strong><?php esc_html_e( 'Content insights (heuristic)', 'easyrankly' ); ?></strong></legend>
			<p class="description">
				<?php
				printf(
					/* translators: 1: Minimum character threshold. */
					esc_html__( 'This is a heuristic, not a definitive SEO diagnosis. Pages are flagged as potentially thin when they meet at least 2 of these 3 conditions: fewer than %1$d characters of visible text, no internal inbound links (no other page on this site links to it), no internal outbound links (it does not link to any other page on this site). Results are cached — run the scan again to refresh.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_THIN_MIN_CHARS )
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Pages built with Elementor, Divi, or WPBakery are automatically excluded — their content lives in post meta, not the post body, which would otherwise cause false positives. Gutenberg block content is analysed correctly.', 'easyrankly' ); ?>
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
						esc_html__( 'Last scan: %2$s — %1$d pages analysed.', 'easyrankly' ),
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
	<?php
}
