<?php
/**
 * Health broken-link crawler functions.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
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
 * Whether the Broken-Link crawler may issue an HTTP request to a URL.
 *
 * External targets must pass wp_http_validate_url() so probes cannot reach
 * loopback or private-network addresses (SSRF). Same-host internal URLs skip
 * that check because intentional loopback fetches are required for spidering.
 *
 * @param string $url      Canonical URL to probe or fetch.
 * @param bool   $internal Whether the URL belongs to this site.
 * @return bool
 */
function erankly_health_bl_is_http_request_allowed( string $url, bool $internal = false ): bool {
	if ( '' === $url ) {
		return false;
	}

	if ( $internal ) {
		return erankly_health_bl_is_internal( $url );
	}

	/**
	 * Filters whether an external URL may be probed during a Broken-Link scan.
	 *
	 * Return a boolean to override the default validation; null keeps the
	 * built-in wp_http_validate_url() check.
	 *
	 * @param bool|null $allowed Null to use the default validation.
	 * @param string    $url     Canonical external URL.
	 */
	$allowed = apply_filters( 'erankly_health_bl_allow_external_http_request', null, $url );

	if ( null !== $allowed ) {
		return (bool) $allowed;
	}

	return function_exists( 'wp_http_validate_url' ) ? (bool) wp_http_validate_url( $url ) : false;
}

/**
 * Fetches a page's HTML, returning the body only for a successful text/html
 * response. Non-HTML, errors, and non-2xx responses yield null (no links).
 *
 * @param string $url Absolute page URL to fetch.
 * @return string|null HTML body, or null when it cannot/should not be parsed.
 */
function erankly_health_bl_fetch_html( string $url ): ?string {
	if ( ! erankly_health_bl_is_http_request_allowed( $url, true ) ) {
		return null;
	}

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
	if ( ! erankly_health_bl_is_http_request_allowed( $url, $internal ) ) {
		return array(
			'code'  => 0,
			'state' => 'unreachable',
		);
	}

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
