<?php
/** XML sitemap generation. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sitemap URL limit per file. */
const ERANKLY_SITEMAP_PER_PAGE = 1000;

// Core wp_sitemaps integration (posts / taxonomies / users).

/**
 * Injects EasyRankly's per-post exclusion meta_query into core sitemap post queries. Respects the canonical
 * index directive, the legacy noindex flag and the explicit sitemap exclusion setting.
 *
 * @param array<string,mixed> $args      WP_Query args built by the core sitemap provider.
 * @param string              $post_type Post type being queried.
 * @return array<string,mixed>
 */
function erankly_filter_core_sitemap_posts_query_args( array $args, string $post_type ): array {
	// Skip attachment queries. Attachment pages are handled separately or suppressed.
	if ( 'attachment' === $post_type ) {
		return $args;
	}

	// A global sitemap exclusion has no positive per-post counterpart, so it is
	// absolute. A global noindex can instead be overridden by an explicit
	// per-post `index` directive and is enforced by the shared SQL filter below.
	if ( erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
		// Return a query that matches nothing; the provider will emit an empty list.
		$args['post__in'] = array( 0 );
		return $args;
	}

	// A correlated NOT EXISTS clause is cheaper than adding several postmeta
	// joins to both the URL query and the core max-page count query.
	$args['erankly_sitemap_query']      = true;
	$args['erankly_sitemap_post_types'] = array( $post_type );
	$args['has_password']               = false;

	if ( 'page' === $post_type ) {
		$excluded_pages = isset( $args['post__not_in'] ) ? array_map( 'absint', (array) $args['post__not_in'] ) : array();
		$front_page_id  = 'page' === get_option( 'show_on_front' ) ? absint( get_option( 'page_on_front' ) ) : 0;
		$posts_page_id  = absint( get_option( 'page_for_posts' ) );

		if ( $front_page_id > 0 && erankly_special_page_is_sitemap_hidden( 'homepage', $front_page_id ) ) {
			$excluded_pages[] = $front_page_id;
		}
		if ( $posts_page_id > 0 && erankly_special_page_is_sitemap_hidden( 'blog', $posts_page_id ) ) {
			$excluded_pages[] = $posts_page_id;
		}

		if ( $excluded_pages ) {
			$args['post__not_in'] = array_values( array_unique( $excluded_pages ) );
		}
	}

	return $args;
}

/** Whether a configured special page must stay out of sitemap output. */
function erankly_special_page_is_sitemap_hidden( string $key, int $post_id = 0 ): bool {
	if ( erankly_get_global_entity_directive( 'global_special_meta', $key, 'disable_sitemap' ) ) {
		return true;
	}

	if ( ! erankly_get_global_entity_directive( 'global_special_meta', $key, 'noindex' ) ) {
		return false;
	}

	return $post_id < 1 || 'index' !== erankly_get_object_robots_directive( 'post', $post_id, 'index' );
}

/**
 * Removes the virtual latest-posts homepage that core prepends to the page
 * sitemap when the homepage special-page policy is noindex/disabled.
 *
 * @param array<int,array<string,string>>|null $url_list Preempted list.
 * @return array<int,array<string,string>>|null
 */
function erankly_filter_core_sitemap_posts_pre_url_list( ?array $url_list, string $post_type, int $page_num ): ?array {
	if (
		null !== $url_list
		|| 'page' !== $post_type
		|| 1 !== $page_num
		|| 'posts' !== get_option( 'show_on_front' )
		|| ! erankly_special_page_is_sitemap_hidden( 'homepage' )
	) {
		return $url_list;
	}

	$args = erankly_get_core_sitemap_posts_query_args( $post_type );
	$args['paged'] = $page_num;
	$query = new WP_Query( $args );
	$list  = array();

	foreach ( $query->posts as $post ) {
		$entry = array(
			'loc'     => get_permalink( $post ),
			'lastmod' => wp_date( DATE_W3C, strtotime( $post->post_modified_gmt ) ),
		);
		$entry = apply_filters( 'wp_sitemaps_posts_entry', $entry, $post, $post_type );
		if ( is_array( $entry ) && ! empty( $entry['loc'] ) ) {
			$list[] = $entry;
		}
	}

	return $list;
}

/** Corrects core's forced one-page minimum when its virtual homepage is hidden. */
function erankly_filter_core_sitemap_posts_pre_max_num_pages( ?int $pages, string $post_type ): ?int {
	if (
		null !== $pages
		|| 'page' !== $post_type
		|| 'posts' !== get_option( 'show_on_front' )
		|| ! erankly_special_page_is_sitemap_hidden( 'homepage' )
	) {
		return $pages;
	}

	$args                  = erankly_get_core_sitemap_posts_query_args( $post_type );
	$args['fields']        = 'ids';
	$args['no_found_rows'] = false;
	$query                 = new WP_Query( $args );

	return isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 0;
}

/** @return array<string,mixed> Native post-sitemap defaults with public filters applied. */
function erankly_get_core_sitemap_posts_query_args( string $post_type ): array {
	return apply_filters(
		'wp_sitemaps_posts_query_args',
		array(
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'post_type'              => $post_type,
			'posts_per_page'         => wp_sitemaps_get_max_urls( 'post' ),
			'post_status'            => array( 'publish' ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'ignore_sticky_posts'    => true,
		),
		$post_type
	);
}

/**
 * Applies the shared post eligibility SQL only to queries marked above (and
 * to EasyRankly's News query). Other front-end and admin queries are untouched.
 */
function erankly_filter_sitemap_posts_where( string $where, WP_Query $query ): string {
	if ( ! $query->get( 'erankly_sitemap_query' ) ) {
		return $where;
	}

	global $wpdb;

	$post_types = array_map( 'sanitize_key', (array) $query->get( 'erankly_sitemap_post_types' ) );

	$where .= erankly_get_sitemap_exclusion_sql( $wpdb->posts, $post_types );

	if ( $query->get( 'erankly_news_sitemap_query' ) && function_exists( 'erankly_get_news_sitemap_exclusion_sql' ) ) {
		$where .= erankly_get_news_sitemap_exclusion_sql( $wpdb->posts );
	}

	return $where;
}

/**
 * @param array<string,mixed> $args     WP_Term_Query args built by the core sitemap provider.
 * @return array<string,mixed>
 */
function erankly_filter_core_sitemap_terms_query_args( array $args, string $taxonomy ): array {
	if ( erankly_get_global_taxonomy_directive( $taxonomy, 'disable_sitemap' ) ) {
		$args['include'] = array( 0 );
		return $args;
	}

	$exclusion = erankly_get_sitemap_term_exclusion_meta_query(
		erankly_get_global_taxonomy_directive( $taxonomy, 'noindex' )
	);

	if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		$args['meta_query'] = $exclusion; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	} else {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			$args['meta_query'],
			$exclusion,
		);
	}

	$canonical_exclusions = erankly_get_non_self_canonical_term_ids( $taxonomy );
	if ( $canonical_exclusions ) {
		$existing        = isset( $args['exclude'] ) ? array_map( 'absint', (array) $args['exclude'] ) : array();
		$args['exclude'] = array_values( array_unique( array_merge( $existing, $canonical_exclusions ) ) );
	}

	return $args;
}

/**
 * Excludes noindex authors and author archives whose custom canonical points
 * somewhere else.
 *
 * @return array<string,mixed>
 */
function erankly_filter_core_sitemap_users_query_args( array $args ): array {
	$exclusion = erankly_get_sitemap_exclusion_meta_query( false, false );

	if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		$args['meta_query'] = $exclusion; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- User sitemap must honor per-author robots metadata.
	} else {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			$args['meta_query'],
			$exclusion,
		);
	}

	$canonical_exclusions = erankly_get_non_self_canonical_user_ids();
	if ( $canonical_exclusions ) {
		$existing        = isset( $args['exclude'] ) ? array_map( 'absint', (array) $args['exclude'] ) : array();
		$args['exclude'] = array_values( array_unique( array_merge( $existing, $canonical_exclusions ) ) );
	}

	$eligible_authors = erankly_get_sitemap_user_ids();
	if ( isset( $args['include'] ) && is_array( $args['include'] ) ) {
		$eligible_authors = array_values( array_intersect( array_map( 'absint', $args['include'] ), $eligible_authors ) );
	}
	$args['include'] = $eligible_authors ?: array( 0 );

	return $args;
}

/**
 * @param array<string,WP_Post_Type> $post_types Post type objects indexed by name.
 * @return array<string,WP_Post_Type>
 */
function erankly_filter_core_sitemap_post_types( array $post_types ): array {
	// Always suppress attachment pages from sitemaps.
	unset( $post_types['attachment'] );

	foreach ( array_keys( $post_types ) as $post_type ) {
		if ( erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
			unset( $post_types[ $post_type ] );
		}
	}

	return $post_types;
}

/**
 * @param array<string,WP_Taxonomy> $taxonomies Taxonomy objects indexed by name.
 * @return array<string,WP_Taxonomy>
 */
function erankly_filter_core_sitemap_taxonomies( array $taxonomies ): array {
	foreach ( array_keys( $taxonomies ) as $taxonomy ) {
		if ( erankly_get_global_taxonomy_directive( $taxonomy, 'disable_sitemap' ) ) {
			unset( $taxonomies[ $taxonomy ] );
		}
	}

	return $taxonomies;
}

/** @return WP_Sitemaps_Provider|null */
function erankly_filter_core_sitemap_add_provider( $provider, string $name ) {
	if ( 'users' === $name && ! erankly_should_include_user_sitemap() ) {
		return null;
	}

	return $provider;
}

/** @return never */
function erankly_render_sitemap_response( string $type, int $page = 1 ) {
	$type = sanitize_key( $type );
	$page = max( 1, $page );

	// This virtual-file handler only serves the specialised sitemaps (image, video, news).
	// Standard post/taxonomy/user sitemaps come from the native wp_sitemaps API.

	if ( ! erankly_sitemap_enabled() ) {
		status_header( 404 );
		exit;
	}

	if ( in_array( $type, array( 'news', 'news-sitemap' ), true ) ) {
		if ( ! (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) || ! function_exists( 'erankly_get_news_sitemap_xml' ) ) {
			status_header( 404 );
			exit;
		}

		$xml = erankly_get_news_sitemap_xml( $page );

		if ( '' === $xml ) {
			status_header( 404 );
			exit;
		}

		erankly_send_response( $xml, 'application/xml' );
	}

	if ( 'image' === $type ) {
		if ( ! (bool) erankly_get_setting( 'enable_image_sitemap', 0 ) || ! function_exists( 'erankly_get_image_sitemap_xml' ) ) {
			status_header( 404 );
			exit;
		}

		$xml = erankly_get_image_sitemap_xml( $page );
	} elseif ( 'video' === $type ) {
		if ( ! (bool) erankly_get_setting( 'enable_video_sitemap', 0 ) || ! function_exists( 'erankly_get_video_sitemap_xml' ) ) {
			status_header( 404 );
			exit;
		}

		$xml = erankly_get_video_sitemap_xml( $page );
	} else {
		// Unknown type. Return a 404.
		status_header( 404 );
		exit;
	}

	if ( '' === $xml ) {
		status_header( 404 );
		exit;
	}

	erankly_send_response( $xml, 'application/xml' );
}

/**
 * Returns image URLs for a post sitemap entry. Only images that belong to the
 * rendered page are eligible: featured image, post content, Gutenberg blocks,
 * gallery shortcodes and the WooCommerce product gallery. Social preview
 * images are intentionally excluded because they need not appear on the page.
 *
 * @return array<int,string>
 */
function erankly_get_sitemap_images( int $post_id ): array {
	$images = array();

	// 1. Featured image.
	$featured_id = get_post_thumbnail_id( $post_id );

	if ( $featured_id > 0 ) {
		$images[] = erankly_get_image_url( (int) $featured_id, 'full' );
	}

	// 2. Images embedded in post content.
	$images = array_merge( $images, erankly_get_post_content_image_urls( $post_id ) );

	// 3. WooCommerce stores additional product images outside post_content.
	$gallery_ids = array_filter(
		array_map(
			'absint',
			explode( ',', (string) get_post_meta( $post_id, '_product_image_gallery', true ) )
		)
	);
	foreach ( $gallery_ids as $image_id ) {
		$images[] = erankly_get_image_url( $image_id, 'full' );
	}

	/**
 * Filters image sitemap URLs for a post. Each element may be a URL string or an array with a 'loc' key.
 *
 * @param array<int,string|array<string,string>> $images  Image URLs or entries with a loc key.
 */
	$images = apply_filters( 'erankly_sitemap_images', $images, $post_id );

	if ( ! is_array( $images ) ) {
		return array();
	}

	$clean = array();

	foreach ( $images as $image ) {
		$url = is_array( $image ) && isset( $image['loc'] ) ? (string) $image['loc'] : (string) $image;
		$url = erankly_absolutize_content_url( $url, (string) get_permalink( $post_id ) );

		if ( erankly_is_absolute_http_url( $url ) ) {
			$clean[] = $url;
		}
	}

	return array_values( array_unique( $clean ) );
}

/** @return array<string,WP_Post_Type> */
function erankly_get_sitemap_post_types(): array {
	$post_types = erankly_get_public_post_types();

	unset( $post_types['attachment'] );

	foreach ( $post_types as $post_type => $object ) {
		if ( ! $object instanceof WP_Post_Type || ( 'page' !== $post_type && ! $object->publicly_queryable ) ) {
			unset( $post_types[ $post_type ] );
			continue;
		}

		if ( erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
			unset( $post_types[ $post_type ] );
		}
	}

	/** @param array<string,WP_Post_Type> $post_types Sitemap post type objects. */
	return apply_filters( 'erankly_sitemap_post_types', $post_types );
}

/**
 * Filters post type names by global sitemap/robots directives.
 *
 * @return array<int,string>
 */
function erankly_filter_sitemap_post_type_names_by_global_directives( array $post_types ): array {
	$filtered = array();

	foreach ( $post_types as $post_type ) {
		$post_type = sanitize_key( (string) $post_type );

		if ( '' === $post_type ) {
			continue;
		}

		if ( erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
			continue;
		}

		$filtered[] = $post_type;
	}

	return array_values( array_unique( $filtered ) );
}

/** @return array<int,int> */
function erankly_get_non_self_canonical_post_ids( array $post_types = array() ): array {
	$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );
	if ( ! $post_types ) {
		$post_types = array_keys( erankly_get_sitemap_post_types() );
	}

	$cache_key = erankly_get_sitemap_cache_key( 'canonical_posts_' . md5( implode( ',', $post_types ) ) );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return array_values( array_filter( array_map( 'absint', $cached ) ) );
	}

	$ids = get_posts(
		array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_key'               => '_erankly_canonical', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Restricted to the small set of posts with an explicit canonical and cached.
			'meta_value'             => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare'           => '!=',
		)
	);
	$excluded = array();

	foreach ( $ids as $post_id ) {
		$post_id   = absint( $post_id );
		$canonical = erankly_get_post_meta_string( $post_id, 'canonical' );
		$canonical = erankly_replace_variables( $canonical, $post_id, array( 'canonical_url' ) );
		$permalink = (string) get_permalink( $post_id );

		if ( erankly_normalize_canonical_comparison_url( $canonical ) !== erankly_normalize_canonical_comparison_url( $permalink ) ) {
			$excluded[] = $post_id;
		}
	}

	/** Filters post IDs excluded because their explicit canonical is not self-referential. */
	$excluded = apply_filters( 'erankly_sitemap_non_self_canonical_post_ids', $excluded, $post_types );
	$excluded = is_array( $excluded ) ? array_values( array_unique( array_filter( array_map( 'absint', $excluded ) ) ) ) : array();
	set_transient( $cache_key, $excluded, HOUR_IN_SECONDS );

	return $excluded;
}

/** Returns a request-independent value for a sitemap canonical template variable. */
function erankly_get_sitemap_global_canonical_variable_value( string $key ): string {
	$allowed = array(
		'current_year',
		'current_month',
		'current_day',
		'current_date',
		'site_name',
		'site_description',
		'site_url',
		'site_language',
		'organization_name',
		'website_name',
		'website_description',
		'organization_logo',
		'organization_logo_url',
		'site_icon',
		'site_icon_url',
		'schema_identity_id',
	);

	return in_array( $key, $allowed, true ) ? erankly_get_variable_preview_value( $key ) : '';
}

/** Replaces a term canonical template without relying on the request's queried object. */
function erankly_replace_sitemap_term_canonical_variables( string $value, WP_Term $term ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	return (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $term ): string {
			$key   = strtolower( (string) $matches[1] );
			$value = '';

			switch ( $key ) {
				case 'term_name':
					$value = $term->name;
					break;
				case 'term_description':
					$value = $term->description;
					break;
				case 'term_slug':
					$value = $term->slug;
					break;
				case 'term_url':
					$link  = get_term_link( $term );
					$value = is_wp_error( $link ) ? '' : (string) $link;
					break;
				case 'taxonomy_name':
					$taxonomy = get_taxonomy( $term->taxonomy );
					$value    = $taxonomy instanceof WP_Taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy;
					break;
				default:
					$value = erankly_get_sitemap_global_canonical_variable_value( $key );
			}

			return trim( wp_strip_all_tags( $value ) );
		},
		$value
	);
}

/** Replaces an author canonical template without relying on the request's queried object. */
function erankly_replace_sitemap_user_canonical_variables( string $value, WP_User $user ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	return (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $user ): string {
			$key        = strtolower( (string) $matches[1] );
			$archive_url = (string) get_author_posts_url( (int) $user->ID );
			$website     = (string) $user->user_url;
			$value       = '';

			switch ( $key ) {
				case 'author_name':
					$value = $user->display_name;
					break;
				case 'author_bio':
					$value = $user->description;
					break;
				case 'author_first_name':
					$value = $user->first_name;
					break;
				case 'author_last_name':
					$value = $user->last_name;
					break;
				case 'author_url':
					$value = $archive_url;
					break;
				case 'author_website':
					$value = $website;
					break;
				case 'author_profile_url':
					$value = '' !== trim( $website ) ? $website : $archive_url;
					break;
				default:
					$value = erankly_get_sitemap_global_canonical_variable_value( $key );
			}

			return trim( wp_strip_all_tags( $value ) );
		},
		$value
	);
}

/** @return array<int,int> */
function erankly_get_non_self_canonical_term_ids( string $taxonomy ): array {
	$taxonomy = sanitize_key( $taxonomy );
	$cache_key = erankly_get_sitemap_cache_key( 'canonical_terms_' . $taxonomy );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return array_values( array_filter( array_map( 'absint', $cached ) ) );
	}

	$ids = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => '_erankly_canonical', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit canonical terms only; result is cached.
			'meta_value' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare' => '!=',
		)
	);
	$excluded = array();

	if ( ! is_wp_error( $ids ) ) {
		foreach ( $ids as $term_id ) {
			$term_id   = absint( $term_id );
			$term      = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$canonical = erankly_get_term_meta_string( $term_id, 'canonical' );
			$canonical = erankly_replace_sitemap_term_canonical_variables( $canonical, $term );
			$term_link = get_term_link( $term );
			$term_link = is_wp_error( $term_link ) ? '' : (string) $term_link;

			if ( erankly_normalize_canonical_comparison_url( $canonical ) !== erankly_normalize_canonical_comparison_url( $term_link ) ) {
				$excluded[] = $term_id;
			}
		}
	}

	$excluded = apply_filters( 'erankly_sitemap_non_self_canonical_term_ids', $excluded, $taxonomy );
	$excluded = is_array( $excluded ) ? array_values( array_unique( array_filter( array_map( 'absint', $excluded ) ) ) ) : array();
	set_transient( $cache_key, $excluded, HOUR_IN_SECONDS );

	return $excluded;
}

/** @return array<int,int> */
function erankly_get_non_self_canonical_user_ids(): array {
	$cache_key = erankly_get_sitemap_cache_key( 'canonical_users' );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return array_values( array_filter( array_map( 'absint', $cached ) ) );
	}

	$ids = get_users(
		array(
			'fields'       => 'ID',
			'meta_key'     => '_erankly_canonical', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit canonical users only; result is cached.
			'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare' => '!=',
		)
	);
	$excluded = array();

	foreach ( $ids as $user_id ) {
		$user_id   = absint( $user_id );
		$user      = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			continue;
		}
		$canonical = trim( (string) get_user_meta( $user_id, '_erankly_canonical', true ) );
		$canonical = erankly_replace_sitemap_user_canonical_variables( $canonical, $user );
		$self      = get_author_posts_url( $user_id );

		if ( erankly_normalize_canonical_comparison_url( $canonical ) !== erankly_normalize_canonical_comparison_url( $self ) ) {
			$excluded[] = $user_id;
		}
	}

	$excluded = apply_filters( 'erankly_sitemap_non_self_canonical_user_ids', $excluded );
	$excluded = is_array( $excluded ) ? array_values( array_unique( array_filter( array_map( 'absint', $excluded ) ) ) ) : array();
	set_transient( $cache_key, $excluded, HOUR_IN_SECONDS );

	return $excluded;
}

/** Returns whether a post can safely be emitted by a specialist sitemap. */
function erankly_is_post_sitemap_eligible( int $post_id, array $allowed_post_types = array() ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	$allowed_post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $allowed_post_types ) ) ) );
	if ( $allowed_post_types && ! in_array( $post->post_type, $allowed_post_types, true ) ) {
		return false;
	}

	if (
		erankly_get_global_post_type_directive( $post->post_type, 'disable_sitemap' )
		|| erankly_get_post_meta_bool( $post_id, 'disable_sitemap' )
	) {
		return false;
	}

	$permalink = (string) get_permalink( $post );
	$state     = erankly_get_object_seo_state(
		array(
			'kind'           => 'post',
			'object_id'      => $post_id,
			'object_subtype' => $post->post_type,
			'blog_id'        => get_current_blog_id(),
			'url'            => $permalink,
		)
	);

	return $state['indexable'] && $state['canonical_is_self'];
}

/**
 * Returns the SQL suffix that excludes posts blocked from sitemap output. The tri-state directive is canonical
 * when it contains a recognized value. The legacy boolean is consulted only when that directive is absent,
 * retaining compatibility without allowing a stale legacy flag to override explicit `index` metadata.
 *
 * @param string            $post_alias Alias of the posts table in the owning query.
 * @param array<int,string> $post_types Post types included by the owning query.
 */
function erankly_get_sitemap_exclusion_sql( string $post_alias = 'p', array $post_types = array() ): string {
	global $wpdb;

	if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $post_alias ) ) {
		$post_alias = 'p';
	}

	$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );
	if ( ! $post_types ) {
		$post_types = array_keys( erankly_get_sitemap_post_types() );
	}

	$sql = "
		AND {$post_alias}.post_password = ''
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm_erankly_index_noindex
			WHERE pm_erankly_index_noindex.post_id = {$post_alias}.ID
				AND pm_erankly_index_noindex.meta_key = '_erankly_index_directive'
				AND pm_erankly_index_noindex.meta_value = 'noindex'
		)
		AND (
			EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_erankly_index_override
				WHERE pm_erankly_index_override.post_id = {$post_alias}.ID
					AND pm_erankly_index_override.meta_key = '_erankly_index_directive'
					AND pm_erankly_index_override.meta_value IN ('index', 'inherit', 'noindex')
			)
			OR NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_erankly_legacy_noindex
				WHERE pm_erankly_legacy_noindex.post_id = {$post_alias}.ID
					AND pm_erankly_legacy_noindex.meta_key = '_erankly_noindex'
					AND pm_erankly_legacy_noindex.meta_value = '1'
			)
		)
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm_erankly_sitemap_disabled
			WHERE pm_erankly_sitemap_disabled.post_id = {$post_alias}.ID
				AND pm_erankly_sitemap_disabled.meta_key = '_erankly_disable_sitemap'
				AND pm_erankly_sitemap_disabled.meta_value = '1'
		)
	";

	$canonical_exclusions = erankly_get_non_self_canonical_post_ids( $post_types );
	if ( $canonical_exclusions ) {
		$sql .= ' AND ' . $post_alias . '.ID NOT IN (' . implode( ',', $canonical_exclusions ) . ')';
	}

	$globally_noindex = array_values(
		array_filter(
			$post_types,
			static fn( string $post_type ): bool => erankly_get_global_post_type_directive( $post_type, 'noindex' )
		)
	);

	if ( $globally_noindex ) {
		$placeholders = implode( ', ', array_fill( 0, count( $globally_noindex ), '%s' ) );
		$sql         .= $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias and placeholders are validated/generated internally.
			" AND (
				{$post_alias}.post_type NOT IN ({$placeholders})
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_erankly_global_index_override
					WHERE pm_erankly_global_index_override.post_id = {$post_alias}.ID
						AND pm_erankly_global_index_override.meta_key = '_erankly_index_directive'
						AND pm_erankly_global_index_override.meta_value = 'index'
				)
			)",
			$globally_noindex
		);
	}

	return $sql;
}

/**
 * Returns meta query clauses that exclude blocked sitemap URLs. The canonical tri-state directive takes
 * precedence over the legacy boolean. A recognized explicit `index` therefore remains eligible even if stale
 * `_erankly_noindex` metadata is still present.
 *
 * @return array<int|string,mixed>
 */
function erankly_get_sitemap_exclusion_meta_query( bool $require_explicit_index = false, bool $include_disable_sitemap = true ): array {
	$index_clause = $require_explicit_index
		? array(
			'key'     => '_erankly_index_directive',
			'value'   => 'index',
			'compare' => '=',
		)
		: array(
			'relation' => 'OR',
			array(
				'key'     => '_erankly_index_directive',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_erankly_index_directive',
				'value'   => 'noindex',
				'compare' => '!=',
			),
		);

	$query = array(
		'relation' => 'AND',
		$index_clause,
		array(
			'relation' => 'OR',
			array(
				'key'     => '_erankly_index_directive',
				'value'   => array( 'index', 'inherit', 'noindex' ),
				'compare' => 'IN',
			),
			array(
				'key'     => '_erankly_noindex',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_erankly_noindex',
				'value'   => '1',
				'compare' => '!=',
			),
		),
	);

	if ( $include_disable_sitemap ) {
		$query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_erankly_disable_sitemap',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_erankly_disable_sitemap',
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	return $query;
}

/**
 * Returns meta query clauses that exclude noindex terms from taxonomy sitemaps. Terms use the same canonical and
 * legacy keys as posts, so the post exclusion clauses apply unchanged.
 *
 * @return array<int|string,mixed>
 */
function erankly_get_sitemap_term_exclusion_meta_query( bool $require_explicit_index = false ): array {
	return erankly_get_sitemap_exclusion_meta_query( $require_explicit_index );
}

/** Returns the SQL suffix that excludes noindex or non-canonical authors. */
function erankly_get_sitemap_user_exclusion_sql( string $user_id_expression ): string {
	global $wpdb;

	if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_.]*$/D', $user_id_expression ) ) {
		return '';
	}

	$sql = "
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->usermeta} um_erankly_index_noindex
			WHERE um_erankly_index_noindex.user_id = {$user_id_expression}
				AND um_erankly_index_noindex.meta_key = '_erankly_index_directive'
				AND um_erankly_index_noindex.meta_value = 'noindex'
		)
		AND (
			EXISTS (
				SELECT 1 FROM {$wpdb->usermeta} um_erankly_index_override
				WHERE um_erankly_index_override.user_id = {$user_id_expression}
					AND um_erankly_index_override.meta_key = '_erankly_index_directive'
					AND um_erankly_index_override.meta_value IN ('index', 'inherit', 'noindex')
			)
			OR NOT EXISTS (
				SELECT 1 FROM {$wpdb->usermeta} um_erankly_legacy_noindex
				WHERE um_erankly_legacy_noindex.user_id = {$user_id_expression}
					AND um_erankly_legacy_noindex.meta_key = '_erankly_noindex'
					AND um_erankly_legacy_noindex.meta_value = '1'
			)
		)
	";

	$canonical_exclusions = erankly_get_non_self_canonical_user_ids();
	if ( $canonical_exclusions ) {
		$sql .= ' AND ' . $user_id_expression . ' NOT IN (' . implode( ',', $canonical_exclusions ) . ')';
	}

	return $sql;
}

/**
 * Determines whether the user sitemap should be exposed. Single-author sites do not need author archive URLs in
 * XML sitemaps because those archives usually duplicate the main content listing.
 */
function erankly_should_include_user_sitemap(): bool {
	$author_hidden = erankly_get_global_entity_directive( 'global_special_meta', 'author', 'noindex' )
		|| erankly_get_global_entity_directive( 'global_special_meta', 'author', 'disable_sitemap' );
	$include       = ! $author_hidden && erankly_count_sitemap_users() > 1;

	/** @param bool $include Whether the user sitemap should be included. */
	return (bool) apply_filters( 'erankly_include_user_sitemap', $include );
}

function erankly_count_sitemap_users(): int {
	$stats = erankly_get_sitemap_user_stats();

	return $stats['count'];
}

/** @return array<int,int> */
function erankly_get_sitemap_user_ids(): array {
	$stats = erankly_get_sitemap_user_stats();

	return $stats['ids'];
}

/** @return array{count:int,ids:array<int,int>} */
function erankly_get_sitemap_user_stats(): array {
	global $wpdb;

	// The wp_sitemaps_add_provider filter fires on init for every request, so
	// this author lookup must be transient-cached, not just per-request.
	$transient_key = erankly_get_sitemap_cache_key( 'user_stats' );
	static $cache_by_transient_key = array();

	if ( isset( $cache_by_transient_key[ $transient_key ] ) ) {
		return $cache_by_transient_key[ $transient_key ];
	}

	$cached        = get_transient( $transient_key );

	if ( is_array( $cached ) && isset( $cached['count'], $cached['ids'] ) && is_array( $cached['ids'] ) ) {
		$cache_by_transient_key[ $transient_key ] = array(
			'count' => absint( $cached['count'] ),
			'ids'   => array_values( array_filter( array_map( 'absint', $cached['ids'] ) ) ),
		);
		return $cache_by_transient_key[ $transient_key ];
	}

	$post_types = array_keys( erankly_get_sitemap_post_types() );

	if ( empty( $post_types ) ) {
		$cache_by_transient_key[ $transient_key ] = array(
			'count' => 0,
			'ids'   => array(),
		);
		set_transient( $transient_key, $cache_by_transient_key[ $transient_key ], HOUR_IN_SECONDS );
		return $cache_by_transient_key[ $transient_key ];
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$sql          = "
		SELECT DISTINCT p.post_author AS author_id
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_author > 0
			AND p.post_type IN ({$placeholders})
	" . erankly_get_sitemap_exclusion_sql( 'p', $post_types )
	. erankly_get_sitemap_user_exclusion_sql( 'p.post_author' ) . '
		ORDER BY p.post_author ASC
	';

	$prepared_sql = $wpdb->prepare( $sql, $post_types ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic post type placeholders are generated above and every value is bound here.
	$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The aggregate query is prepared immediately above; table names and the exclusion clause are internal constants.
		$prepared_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
		ARRAY_A
	);
	$ids          = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$user_id = isset( $row['author_id'] ) ? absint( $row['author_id'] ) : 0;
		if ( $user_id > 0 ) {
			$ids[] = $user_id;
		}
	}

	$cache_by_transient_key[ $transient_key ] = array(
		'count' => count( $ids ),
		'ids'   => $ids,
	);

	set_transient( $transient_key, $cache_by_transient_key[ $transient_key ], HOUR_IN_SECONDS );

	return $cache_by_transient_key[ $transient_key ];
}

function erankly_format_sitemap_gmt_date( string $date ): string {
	if ( '' === $date || str_starts_with( $date, '0000-00-00' ) ) {
		return '';
	}

	$timestamp = strtotime( $date . ' UTC' );

	return false === $timestamp ? '' : gmdate( DATE_W3C, $timestamp );
}
