<?php
/**
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
