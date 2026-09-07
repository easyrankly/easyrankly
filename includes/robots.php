<?php
/** Robots meta and robots.txt. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns whether singular content or comments are on a paginated request. This is intentionally separate from
 * is_paged(), which represents archive pagination. AIOSEO applies its global pagination policy to both scopes.
 */
function erankly_is_paginated_content_request(): bool {
	$page  = (int) get_query_var( 'page', 0 );
	$cpage = (int) get_query_var( 'cpage', 0 );

	// Block themes can build comment query vars directly on the main query.
	if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query && isset( $GLOBALS['wp_query']->query['cpage'] ) ) {
		$cpage = max( $cpage, (int) $GLOBALS['wp_query']->query['cpage'] );
	}

	return $page > 1 || $cpage > 0;
}

/**
 * Filters native WordPress robots meta output.
 *
 * @return array<string,bool|string>
 */
function erankly_filter_wp_robots( array $robots ): array {
	if ( is_singular() ) {
		$post_id   = get_queried_object_id();
		$post_type = get_post_type( $post_id );
		$post_type = is_string( $post_type ) ? $post_type : '';

		if ( erankly_get_post_meta_bool( $post_id, 'noindex' ) || ( '' !== $post_type && erankly_get_global_post_type_directive( $post_type, 'noindex' ) ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}

		if ( erankly_get_post_meta_bool( $post_id, 'nofollow' ) || ( '' !== $post_type && erankly_get_global_post_type_directive( $post_type, 'nofollow' ) ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		if ( erankly_get_post_meta_bool( $post_id, 'noarchive' ) || ( '' !== $post_type && erankly_get_global_post_type_directive( $post_type, 'noarchive' ) ) ) {
			$robots['noarchive'] = true;
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			if ( erankly_get_term_meta_bool( $term->term_id, 'noindex' ) || erankly_get_global_taxonomy_directive( $term->taxonomy, 'noindex' ) ) {
				$robots['noindex'] = true;
				unset( $robots['index'] );
			}

			if ( erankly_get_term_meta_bool( $term->term_id, 'nofollow' ) || erankly_get_global_taxonomy_directive( $term->taxonomy, 'nofollow' ) ) {
				$robots['nofollow'] = true;
				unset( $robots['follow'] );
			}

			if ( erankly_get_term_meta_bool( $term->term_id, 'noarchive' ) || erankly_get_global_taxonomy_directive( $term->taxonomy, 'noarchive' ) ) {
				$robots['noarchive'] = true;
			}
		}
	} elseif ( is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		$post_type = is_string( $post_type ) ? $post_type : '';

		if ( '' !== $post_type && erankly_get_global_post_type_directive( $post_type, 'noindex' ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}

		if ( '' !== $post_type && erankly_get_global_post_type_directive( $post_type, 'nofollow' ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		if ( '' !== $post_type && erankly_get_global_post_type_directive( $post_type, 'noarchive' ) ) {
			$robots['noarchive'] = true;
		}
	}

	// Special pages (search, 404, author/date archives, homepage, blog page) read their
	// directives from global_special_meta, configurable under General → Special pages.
	$special_key = erankly_current_special_page_key();

	if ( '' !== $special_key ) {
		if ( erankly_get_global_entity_directive( 'global_special_meta', $special_key, 'noindex' ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}

		if ( erankly_get_global_entity_directive( 'global_special_meta', $special_key, 'nofollow' ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		if ( erankly_get_global_entity_directive( 'global_special_meta', $special_key, 'noarchive' ) ) {
			$robots['noarchive'] = true;
		}
	}

	$is_archive_paged = is_paged();
	$is_content_paged = erankly_is_paginated_content_request();

	// Providers expose archive and content/comment pagination as distinct scopes.
	if ( $is_archive_paged && (bool) erankly_get_setting( 'noindex_paginated', 0 ) ) {
		$robots['noindex'] = true;
		unset( $robots['index'] );
	}
	if ( $is_content_paged && (bool) erankly_get_setting( 'noindex_paginated_content', 0 ) ) {
		$robots['noindex'] = true;
		unset( $robots['index'] );
	}
	if ( ( $is_archive_paged || $is_content_paged ) && (bool) erankly_get_setting( 'nofollow_paginated', 0 ) ) {
		$robots['nofollow'] = true;
		unset( $robots['follow'] );
	}

	if ( empty( $robots['noindex'] ) ) {
		$robots['index'] = true;
	}

	if ( empty( $robots['nofollow'] ) ) {
		$robots['follow'] = true;
	}

	// Only an explicitly stored value emits the directive. With no stored value, WordPress
	// core adds its own max-image-preview:large on public sites (wp_robots_max_image_preview_large()).
	$max_image_preview = sanitize_key( (string) erankly_get_setting( 'robots_max_image_preview', '' ) );
	if ( in_array( $max_image_preview, array( 'none', 'standard', 'large' ), true ) ) {
		$robots['max-image-preview'] = $max_image_preview;
	}

	$max_snippet = trim( (string) erankly_get_setting( 'robots_max_snippet', '' ) );

	if ( '' !== $max_snippet ) {
		$robots['max-snippet'] = $max_snippet;
	}

	$max_video_preview = trim( (string) erankly_get_setting( 'robots_max_video_preview', '' ) );

	if ( '' !== $max_video_preview ) {
		$robots['max-video-preview'] = $max_video_preview;
	}

	if ( (bool) erankly_get_setting( 'robots_nosnippet', 0 ) ) {
		$robots['nosnippet'] = true;
	}
	if ( (bool) erankly_get_setting( 'robots_noimageindex', 0 ) ) {
		$robots['noimageindex'] = true;
	}
	if ( (bool) erankly_get_setting( 'robots_notranslate', 0 ) ) {
		$robots['notranslate'] = true;
	}

	// Google only honors indexifembedded with noindex. It lets an embedded copy
	// be indexed while the standalone URL remains excluded from results.
	if ( ! empty( $robots['noindex'] ) && (bool) erankly_get_setting( 'robots_indexifembedded', 0 ) ) {
		$robots['indexifembedded'] = true;
	}

	$robots = erankly_apply_current_global_entity_robots( $robots );
	$robots = erankly_apply_current_object_robots_overrides( $robots );

	if ( ! empty( $robots['nosnippet'] ) ) {
		unset( $robots['max-snippet'] );
	}
	if ( ! empty( $robots['noimageindex'] ) ) {
		unset( $robots['max-image-preview'] );
	}
	if ( empty( $robots['noindex'] ) ) {
		unset( $robots['indexifembedded'] );
	}

	// Imported positive overrides must never bypass WordPress site visibility.
	if ( ! (bool) get_option( 'blog_public' ) ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['index'], $robots['follow'], $robots['indexifembedded'] );
	}

	if ( empty( $robots['noindex'] ) ) {
		$robots['index'] = true;
	}

	if ( empty( $robots['nofollow'] ) ) {
		$robots['follow'] = true;
	}

	/** Filters robots directives before WordPress renders the meta tag. */
	return apply_filters( 'erankly_robots', $robots );
}

/** Sends the feed-level robots policy through the HTTP header used by AIOSEO. */
function erankly_send_feed_robots_header(): void {
	if ( ! is_feed() || ! (bool) erankly_get_setting( 'noindex_feeds', 0 ) || headers_sent() ) {
		return;
	}

	header( 'X-Robots-Tag: noindex, follow', true );
}

/** @return array<string,bool|string> */
function erankly_apply_current_global_entity_robots( array $robots ): array {
	$contexts = array();

	if ( is_singular() ) {
		$post_type = get_post_type( get_queried_object_id() );
		if ( is_string( $post_type ) && '' !== $post_type ) {
			$contexts[] = array( 'global_post_type_meta', $post_type );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$contexts[] = array( 'global_taxonomy_meta', $term->taxonomy );
		}
	} elseif ( is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		if ( is_string( $post_type ) && '' !== $post_type ) {
			$contexts[] = array( 'global_post_type_meta', $post_type );
		}
	}

	$special_key = erankly_current_special_page_key();
	if ( '' !== $special_key ) {
		$contexts[] = array( 'global_special_meta', $special_key );
	}

	foreach ( $contexts as $context ) {
		$row    = erankly_get_global_entity_meta_row( $context[0], $context[1] );
		$robots = erankly_apply_global_entity_robot_row( $robots, $row );
	}

	return $robots;
}

/**
 * Applies one sanitized global entity robot row.
 *
 * @return array<string,bool|string>
 */
function erankly_apply_global_entity_robot_row( array $robots, array $row ): array {
	$pairs = array(
		'index_directive'   => array( 'index', 'noindex' ),
		'follow_directive'  => array( 'follow', 'nofollow' ),
		'archive_directive' => array( 'archive', 'noarchive' ),
		'snippet_directive' => array( 'snippet', 'nosnippet' ),
		'image_directive'   => array( 'imageindex', 'noimageindex' ),
	);

	foreach ( $pairs as $key => $values ) {
		if ( ! isset( $row[ $key ] ) || ! is_string( $row[ $key ] ) ) {
			continue;
		}
		list( $allow, $deny ) = $values;
		if ( $deny === $row[ $key ] ) {
			$robots[ $deny ] = true;
			unset( $robots[ $allow ] );
		} elseif ( $allow === $row[ $key ] ) {
			unset( $robots[ $deny ] );
			if ( in_array( $allow, array( 'index', 'follow' ), true ) ) {
				$robots[ $allow ] = true;
			}
		}
	}

	// noodp was retired: DMOZ shut down in 2017 and no engine reads the directive.
	if ( array_key_exists( 'notranslate', $row ) ) {
		if ( ! empty( $row['notranslate'] ) ) {
			$robots['notranslate'] = true;
		} else {
			unset( $robots['notranslate'] );
		}
	}

	foreach ( array(
		'max_snippet'       => 'max-snippet',
		'max_video_preview' => 'max-video-preview',
		'max_image_preview' => 'max-image-preview',
	) as $key => $directive ) {
		if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
			$robots[ $directive ] = trim( (string) $row[ $key ] );
		}
	}

	if ( array_key_exists( 'indexifembedded', $row ) ) {
		if ( ! empty( $row['indexifembedded'] ) && ! empty( $robots['noindex'] ) ) {
			$robots['indexifembedded'] = true;
		} else {
			unset( $robots['indexifembedded'] );
		}
	}

	if ( ! empty( $robots['nosnippet'] ) ) {
		unset( $robots['max-snippet'] );
	}
	if ( ! empty( $robots['noimageindex'] ) ) {
		unset( $robots['max-image-preview'] );
	}
	if ( empty( $robots['noindex'] ) ) {
		unset( $robots['indexifembedded'] );
	}

	return $robots;
}

/**
 * Applies tri-state per-object directives after global defaults.
 *
 * @return array<string,bool|string>
 */
function erankly_apply_current_object_robots_overrides( array $robots ): array {
	$object_type = '';
	$object_id   = 0;

	if ( is_singular() ) {
		$object_type = 'post';
		$object_id   = get_queried_object_id();
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$object_type = 'term';
			$object_id   = $term->term_id;
		}
	} elseif ( is_author() ) {
		$object_type = 'user';
		$object_id   = get_queried_object_id();
	}

	if ( '' === $object_type || $object_id < 1 ) {
		return $robots;
	}

	$directives = array(
		'index'   => erankly_get_object_robots_directive( $object_type, $object_id, 'index' ),
		'follow'  => erankly_get_object_robots_directive( $object_type, $object_id, 'follow' ),
		'archive' => erankly_get_object_robots_directive( $object_type, $object_id, 'archive' ),
		'snippet' => erankly_get_object_robots_directive( $object_type, $object_id, 'snippet' ),
		'image'   => erankly_get_object_robots_directive( $object_type, $object_id, 'image' ),
	);

	$pairs = array(
		'index'   => array( 'index', 'noindex' ),
		'follow'  => array( 'follow', 'nofollow' ),
		'archive' => array( 'archive', 'noarchive' ),
		'snippet' => array( 'snippet', 'nosnippet' ),
		'image'   => array( 'imageindex', 'noimageindex' ),
	);

	foreach ( $pairs as $axis => $values ) {
		list( $allow, $deny ) = $values;

		if ( $deny === $directives[ $axis ] ) {
			$robots[ $deny ] = true;
			unset( $robots[ $allow ] );
		} elseif ( $allow === $directives[ $axis ] ) {
			unset( $robots[ $deny ] );

			if ( in_array( $allow, array( 'index', 'follow' ), true ) ) {
				$robots[ $allow ] = true;
			}
		}
	}

	$get_meta = static function ( string $key ) use ( $object_type, $object_id ): mixed {
		if ( 'term' === $object_type ) {
			return get_term_meta( $object_id, $key, true );
		}

		if ( 'user' === $object_type ) {
			return get_user_meta( $object_id, $key, true );
		}

		return get_post_meta( $object_id, $key, true );
	};

	$max_snippet = trim( (string) $get_meta( '_erankly_max_snippet' ) );
	$max_video   = trim( (string) $get_meta( '_erankly_max_video_preview' ) );
	$max_image   = trim( (string) $get_meta( '_erankly_max_image_preview' ) );

	if ( '' !== $max_snippet ) {
		$robots['max-snippet'] = $max_snippet;
	}

	if ( '' !== $max_video ) {
		$robots['max-video-preview'] = $max_video;
	}

	if ( in_array( $max_image, array( 'none', 'standard', 'large' ), true ) ) {
		$robots['max-image-preview'] = $max_image;
	}

	if ( '1' === (string) $get_meta( '_erankly_indexifembedded' ) && ! empty( $robots['noindex'] ) ) {
		$robots['indexifembedded'] = true;
	}

	return $robots;
}

/**
 * Filters virtual robots.txt content.
 *
 * @param bool   $is_public Whether the site discourages search engines.
 */
function erankly_filter_robots_txt( string $output, bool $is_public ): string {
	if ( erankly_detect_external_seo_head_owner() && ! (bool) apply_filters( 'erankly_enable_robots_txt_with_external_seo', false ) ) {
		return $output;
	}

	erankly_load_sitemap_helpers();

	$parsed = erankly_robots_txt_parse_groups( explode( "\n", $output ) );

	// Core's do_robots() already prints the wildcard group, and it derives the admin paths from site_url(). On a
	// subdirectory Multisite that path is "/en/wp-admin/", so hardcoding "/wp-admin/" appended a second, wrong
	// pair of rules to every sub-site. Deriving the prefix the same way core does makes our rules identical to
	// the ones already present, so the group-scoped dedupe below drops them instead of duplicating them.
	$site_path = untrailingslashit( (string) ( wp_parse_url( site_url(), PHP_URL_PATH ) ?? '' ) );

	$required = $is_public
		? array(
			'Disallow: ' . $site_path . '/wp-admin/',
			'Allow: ' . $site_path . '/wp-admin/admin-ajax.php',
		)
		: array( 'Disallow: /' );

	$wildcard = null;

	foreach ( $parsed['groups'] as $index => $group ) {
		if ( in_array( '*', $group['agents'], true ) ) {
			$wildcard = $index;
			break;
		}
	}

	if ( null === $wildcard ) {
		// Empty $output (the settings preview calls this filter directly) has no group to extend.
		$parsed['groups'][] = array(
			'agents' => array( '*' ),
			'rules'  => array(),
		);

		$wildcard = array_key_last( $parsed['groups'] );
	}

	foreach ( $required as $rule ) {
		if ( ! in_array( $rule, $parsed['groups'][ $wildcard ]['rules'], true ) ) {
			$parsed['groups'][ $wildcard ]['rules'][] = $rule;
		}
	}

	if ( $is_public && erankly_sitemap_enabled() && ! erankly_should_suppress_sitemaps() ) {
		// Core wp_sitemaps serves the main sitemap index at /wp-sitemap.xml.
		$parsed['globals'][] = 'Sitemap: ' . esc_url_raw( erankly_get_sitemap_url( '/wp-sitemap.xml' ) );
	}

	$custom = trim( (string) erankly_get_setting( 'robots_txt_extra', '' ) );

	if ( '' !== $custom ) {
		$extra = erankly_robots_txt_parse_groups( explode( "\n", $custom ) );

		// Rules typed without a User-agent header of their own belong to the wildcard group: that is where they
		// ended up when the extra lines were simply appended to the flat list.
		foreach ( $extra['preamble'] as $rule ) {
			$parsed['groups'][ $wildcard ]['rules'][] = $rule;
		}

		$parsed['groups']  = array_merge( $parsed['groups'], $extra['groups'] );
		$parsed['globals'] = array_merge( $parsed['globals'], $extra['globals'] );
	}

	$lines = erankly_robots_txt_render_groups( $parsed );

	/** @param bool              $is_public Whether the site is public. */
	$lines = apply_filters( 'erankly_robots_txt_lines', $lines, $is_public );

	return implode( "\n", array_map( 'trim', (array) $lines ) ) . "\n";
}

/**
 * Splits robots.txt lines into User-agent groups plus file-level directives.
 *
 * robots.txt is positional: every rule belongs to the User-agent header above it. Deduplicating the flat line
 * list (as this module used to do with array_unique()) silently deletes a repeated `User-agent: *` header and
 * re-attaches its rules to whatever group came before, so the grouping has to be rebuilt before any dedupe.
 *
 * @param array<int,string> $lines Raw robots.txt lines.
 * @return array{preamble:array<int,string>,groups:array<int,array{agents:array<int,string>,rules:array<int,string>}>,globals:array<int,string>}
 */
function erankly_robots_txt_parse_groups( array $lines ): array {
	$preamble  = array();
	$groups    = array();
	$globals   = array();
	$current   = null;
	$in_header = false;

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		$separator = strpos( $line, ':' );
		$directive = false === $separator ? '' : strtolower( trim( substr( $line, 0, $separator ) ) );

		if ( 'user-agent' === $directive ) {
			// Consecutive User-agent lines share a single group; only a rule line closes the header.
			if ( null === $current || ! $in_header ) {
				$groups[]  = array(
					'agents' => array(),
					'rules'  => array(),
				);
				$current   = array_key_last( $groups );
				$in_header = true;
			}

			$groups[ $current ]['agents'][] = trim( substr( $line, $separator + 1 ) );
			continue;
		}

		// Sitemap and Host are file-level: they belong to no group and may appear anywhere.
		if ( in_array( $directive, array( 'sitemap', 'host' ), true ) ) {
			$globals[] = $line;
			continue;
		}

		if ( null === $current ) {
			$preamble[] = $line;
			continue;
		}

		$in_header                     = false;
		$groups[ $current ]['rules'][] = $line;
	}

	return array(
		'preamble' => $preamble,
		'groups'   => $groups,
		'globals'  => $globals,
	);
}

/**
 * Flattens parsed robots.txt groups back into lines, deduplicating within each group only.
 *
 * @param array{preamble:array<int,string>,groups:array<int,array{agents:array<int,string>,rules:array<int,string>}>,globals:array<int,string>} $parsed Parsed groups.
 * @return array<int,string>
 */
function erankly_robots_txt_render_groups( array $parsed ): array {
	$lines = array_values( array_unique( $parsed['preamble'] ) );

	foreach ( $parsed['groups'] as $group ) {
		$agents = array_values( array_unique( $group['agents'] ) );

		if ( empty( $agents ) ) {
			continue;
		}

		foreach ( $agents as $agent ) {
			$lines[] = 'User-agent: ' . $agent;
		}

		foreach ( array_values( array_unique( $group['rules'] ) ) as $rule ) {
			$lines[] = $rule;
		}
	}

	foreach ( array_values( array_unique( $parsed['globals'] ) ) as $global ) {
		$lines[] = $global;
	}

	return $lines;
}

/**
 * Forces robots.txt handling when the core rewrite rule is missing. On some Multisite networks (notably staging
 * clones or sub-sites created outside wp_initialize_site) the stored rewrite rules can lack the core
 * `robots\.txt$` rule. A request for /robots.txt is then treated as a regular page, canonical-redirected to
 * /robots.txt/, and never reaches do_robots(). Detecting the raw request path here and forcing the `robots`
 * query var makes the virtual robots.txt behave exactly like /?robots=1, independently of the rewrite-rule flush
 * state of the current site.
 *
 * @param WP $wp Current WordPress environment instance.
 */
function erankly_force_robots_txt_request( WP $wp ): void {
	// Core already routed the request to the robots handler: nothing to do.
	if ( ! empty( $wp->query_vars['robots'] ) || ! empty( $wp->query_vars['robots_txt'] ) ) {
		return;
	}

	if ( 'robots.txt' !== trim( (string) ( $wp->request ?? '' ), '/' ) ) {
		return;
	}

	// Mirror exactly what the core `robots\.txt$` => index.php?robots=1 rule
	// would have produced, so do_robots() (and the robots_txt filter) run.
	$wp->query_vars = array( 'robots' => '1' );
}

function erankly_register_rewrites(): void {
	if ( ! erankly_should_serve_sitemaps() ) {
		return;
	}

	// Core wp_sitemaps handles /wp-sitemap.xml and the post/taxonomy/user sub-sitemaps.
	// We only need rules for the specialised sitemaps (image, video, news), which core
	// doesn't support because they need non-standard XML namespaces.
	add_rewrite_rule( '^sitemap-(image|video|news)-([0-9]+)\.xml$', 'index.php?erankly_sitemap=$matches[1]&erankly_sitemap_page=$matches[2]', 'top' );

	add_filter(
		'query_vars',
		static function ( array $vars ): array {
			$vars[] = 'erankly_sitemap';
			$vars[] = 'erankly_sitemap_page';

			return $vars;
		}
	);
}

function erankly_maybe_render_virtual_files(): void {
	$sitemap = get_query_var( 'erankly_sitemap' );

	if ( ! is_string( $sitemap ) || '' === $sitemap ) {
		return;
	}

	$page = absint( get_query_var( 'erankly_sitemap_page', 1 ) );

	erankly_render_sitemap_response( $sitemap, $page );
}
