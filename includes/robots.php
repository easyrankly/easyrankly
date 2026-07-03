<?php
/**
 * Robots meta and robots.txt.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters native WordPress robots meta output.
 *
 * @param array<string,bool|string> $robots Robots directives.
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

	// Noindex for paginated archive pages (page 2, 3, …) when the option is active.
	if ( is_paged() && (bool) erankly_get_setting( 'noindex_paginated', 0 ) ) {
		$robots['noindex'] = true;
		unset( $robots['index'] );
	}

	if ( empty( $robots['noindex'] ) ) {
		$robots['index'] = true;
	}

	if ( empty( $robots['nofollow'] ) ) {
		$robots['follow'] = true;
	}

	if ( (bool) erankly_get_setting( 'robots_max_image_preview_large', 1 ) ) {
		$robots['max-image-preview'] = 'large';
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

	if ( ! empty( $robots['noindex'] ) && (bool) erankly_get_setting( 'robots_indexifembedded', 0 ) ) {
		$robots['indexifembedded'] = true;
	}

	/**
	 * Filters robots directives before WordPress renders the meta tag.
	 *
	 * @param array<string,bool|string> $robots Robots directives.
	 */
	return apply_filters( 'erankly_robots', $robots );
}

/**
 * Filters virtual robots.txt content.
 *
 * @param string $output    Robots.txt output.
 * @param bool   $is_public Whether the site discourages search engines.
 * @return string
 */
function erankly_filter_robots_txt( string $output, bool $is_public ): string {
	if ( erankly_detect_external_seo_head_owner() && ! (bool) apply_filters( 'erankly_enable_robots_txt_with_external_seo', false ) ) {
		return $output;
	}

	$lines = array_filter( array_map( 'trim', explode( "\n", $output ) ) );

	if ( $is_public ) {
		$lines[] = 'User-agent: *';
		$lines[] = 'Disallow: /wp-admin/';
		$lines[] = 'Allow: /wp-admin/admin-ajax.php';
	} else {
		$lines[] = 'User-agent: *';
		$lines[] = 'Disallow: /';
	}

	if ( erankly_sitemap_enabled() && ! erankly_should_suppress_sitemaps() ) {
		// Core wp_sitemaps serves the main sitemap index at /wp-sitemap.xml.
		$lines[] = 'Sitemap: ' . esc_url_raw( erankly_get_sitemap_url( '/wp-sitemap.xml' ) );

		// Declare specialised sitemaps explicitly for faster crawl discovery.
		// Google News bots in particular benefit from a direct robots.txt pointer.
		if ( (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) ) {
			$lines[] = 'Sitemap: ' . esc_url_raw( erankly_get_sitemap_url( '/sitemap-news-1.xml' ) );
		}

		if ( (bool) erankly_get_setting( 'enable_image_sitemap', 0 ) ) {
			$lines[] = 'Sitemap: ' . esc_url_raw( erankly_get_sitemap_url( '/sitemap-image-1.xml' ) );
		}

		if ( (bool) erankly_get_setting( 'enable_video_sitemap', 0 ) ) {
			$lines[] = 'Sitemap: ' . esc_url_raw( erankly_get_sitemap_url( '/sitemap-video-1.xml' ) );
		}
	}

	// Multilingual: list every enabled language site's sitemap so the root robots.txt
	// exposes the whole network. Driven by the Multilingual feature alone (/wp-sitemap.xml
	// is always served), but a site is skipped when an external SEO plugin owns its sitemap.
	// The current site overlaps the block above; array_unique() below drops the duplicate.
	if (
		is_multisite()
		&& function_exists( 'erankly_multilingual_enabled' )
		&& erankly_multilingual_enabled()
		&& class_exists( 'ERankly_ML_Sites' )
	) {
		foreach ( array_keys( ERankly_ML_Sites::get_enabled() ) as $blog_id ) {
			$blog_id = (int) $blog_id;

			switch_to_blog( $blog_id );

			if ( ! erankly_should_suppress_sitemaps() ) {
				$lines[] = 'Sitemap: ' . esc_url_raw( erankly_get_sitemap_url( '/wp-sitemap.xml' ) );
			}

			restore_current_blog();
		}
	}

	$custom = trim( (string) erankly_get_setting( 'robots_txt_extra', '' ) );

	if ( '' !== $custom ) {
		foreach ( explode( "\n", $custom ) as $custom_line ) {
			$custom_line = trim( $custom_line );

			if ( '' !== $custom_line ) {
				$lines[] = $custom_line;
			}
		}
	}

	$lines = array_values( array_unique( $lines ) );

	/**
	 * Filters robots.txt lines.
	 *
	 * @param array<int,string> $lines     Robots lines.
	 * @param bool              $is_public Whether the site is public.
	 */
	$lines = apply_filters( 'erankly_robots_txt_lines', $lines, $is_public );

	return implode( "\n", array_map( 'trim', (array) $lines ) ) . "\n";
}

/**
 * Forces robots.txt handling when the core rewrite rule is missing.
 *
 * On some Multisite networks (notably staging clones or sub-sites created
 * outside wp_initialize_site) the stored rewrite rules can lack the core
 * `robots\.txt$` rule. A request for /robots.txt is then treated as a regular
 * page, canonical-redirected to /robots.txt/, and never reaches do_robots().
 * Detecting the raw request path here and forcing the `robots` query var makes
 * the virtual robots.txt behave exactly like /?robots=1, independently of the
 * rewrite-rule flush state of the current site.
 *
 * @param WP $wp Current WordPress environment instance.
 * @return void
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

/**
 * Registers rewrite rules and query vars for virtual files.
 *
 * @return void
 */
function erankly_register_rewrites(): void {
	if ( ! erankly_sitemap_enabled() ) {
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

/**
 * Renders virtual sitemap files.
 *
 * @return void
 */
function erankly_maybe_render_virtual_files(): void {
	$sitemap = get_query_var( 'erankly_sitemap' );

	if ( ! is_string( $sitemap ) || '' === $sitemap ) {
		return;
	}

	$page = absint( get_query_var( 'erankly_sitemap_page', 1 ) );

	erankly_render_sitemap_response( $sitemap, $page );
}
