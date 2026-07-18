<?php
/**
 * XML sitemap generation.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap URL limit per file.
 */
const ERANKLY_SITEMAP_PER_PAGE = 1000;


// Core wp_sitemaps integration (posts / taxonomies / users).

/**
 * Injects EasyRankly's per-post exclusion meta_query into core sitemap post queries.
 *
 * Respects the canonical index directive, the legacy noindex flag and the
 * explicit sitemap exclusion setting.
 *
 * @param array<string,mixed> $args      WP_Query args built by the core sitemap provider.
 * @param string              $post_type Post type being queried.
 * @return array<string,mixed>
 */
function erankly_filter_core_sitemap_posts_query_args( array $args, string $post_type ): array {
	// Skip attachment queries — attachment pages are handled separately or suppressed.
	if ( 'attachment' === $post_type ) {
		return $args;
	}

	// Skip post types that are globally noindex'd or disabled in the sitemap.
	if ( erankly_get_global_post_type_directive( $post_type, 'noindex' ) || erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
		// Return a query that matches nothing; the provider will emit an empty list.
		$args['post__in'] = array( 0 );
		return $args;
	}

	// Merge in the per-post exclusion meta_query.
	$exclusion = erankly_get_sitemap_exclusion_meta_query();

	if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		$args['meta_query'] = $exclusion; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to honor per-content sitemap exclusion flags.
	} else {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			$args['meta_query'],
			$exclusion,
		);
	}

	return $args;
}

/**
 * Injects EasyRankly's per-term exclusion meta_query into core sitemap term queries.
 *
 * @param array<string,mixed> $args     WP_Term_Query args built by the core sitemap provider.
 * @param string              $taxonomy Taxonomy being queried.
 * @return array<string,mixed>
 */
function erankly_filter_core_sitemap_terms_query_args( array $args, string $taxonomy ): array {
	if ( erankly_get_global_taxonomy_directive( $taxonomy, 'noindex' ) || erankly_get_global_taxonomy_directive( $taxonomy, 'disable_sitemap' ) ) {
		$args['include'] = array( 0 );
		return $args;
	}

	$exclusion = erankly_get_sitemap_term_exclusion_meta_query();

	if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		$args['meta_query'] = $exclusion; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	} else {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			$args['meta_query'],
			$exclusion,
		);
	}

	return $args;
}

/**
 * Removes globally noindex'd or sitemap-disabled post types from the core sitemap.
 *
 * @param array<string,WP_Post_Type> $post_types Post type objects indexed by name.
 * @return array<string,WP_Post_Type>
 */
function erankly_filter_core_sitemap_post_types( array $post_types ): array {
	// Always suppress attachment pages from sitemaps.
	unset( $post_types['attachment'] );

	foreach ( array_keys( $post_types ) as $post_type ) {
		if ( erankly_get_global_post_type_directive( $post_type, 'noindex' ) || erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
			unset( $post_types[ $post_type ] );
		}
	}

	return $post_types;
}

/**
 * Removes globally noindex'd or sitemap-disabled taxonomies from the core sitemap.
 *
 * @param array<string,WP_Taxonomy> $taxonomies Taxonomy objects indexed by name.
 * @return array<string,WP_Taxonomy>
 */
function erankly_filter_core_sitemap_taxonomies( array $taxonomies ): array {
	foreach ( array_keys( $taxonomies ) as $taxonomy ) {
		if ( erankly_get_global_taxonomy_directive( $taxonomy, 'noindex' ) || erankly_get_global_taxonomy_directive( $taxonomy, 'disable_sitemap' ) ) {
			unset( $taxonomies[ $taxonomy ] );
		}
	}

	return $taxonomies;
}

/**
 * Removes the user sitemap provider when it is disabled in EasyRankly settings.
 *
 * @param WP_Sitemaps_Provider|null $provider Provider object.
 * @param string                    $name     Provider name.
 * @return WP_Sitemaps_Provider|null
 */
function erankly_filter_core_sitemap_add_provider( $provider, string $name ) {
	if ( 'users' === $name && ! erankly_should_include_user_sitemap() ) {
		return null;
	}

	return $provider;
}

/**
 * Sends sitemap response.
 *
 * @param string $type Sitemap type.
 * @param int    $page Sitemap page.
 * @return never
 */
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
		if ( 1 !== $page || ! (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) || ! function_exists( 'erankly_get_news_sitemap_xml' ) ) {
			status_header( 404 );
			exit;
		}

		$xml = erankly_get_news_sitemap_xml();

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
		// Unknown type — 404.
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
 * Returns image URLs for a post sitemap entry.
 *
 * Sources (in order): featured image, images found in post content (img tags,
 * Gutenberg image/gallery blocks), separate and legacy SEO social image URLs,
 * and stored OG/Twitter attachment IDs. Only absolute http(s) URLs are returned;
 * duplicates are dropped.
 *
 * @param int $post_id Post ID.
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

	// 3. Separate OG / Twitter URLs plus the legacy shared social-image URL.
	foreach ( array( 'og_image_url', 'twitter_image_url', 'social_image_url' ) as $meta_key ) {
		$social_image = erankly_get_post_meta_string( $post_id, $meta_key );

		if ( '' !== $social_image ) {
			$images[] = esc_url_raw( erankly_replace_variables( $social_image, $post_id ) );
		}
	}

	// 4. OG / Twitter attachment IDs stored in meta.
	foreach ( array( '_erankly_og_image_id', '_erankly_twitter_image_id' ) as $meta_key ) {
		$image_id = absint( get_post_meta( $post_id, $meta_key, true ) );

		if ( $image_id > 0 ) {
			$images[] = erankly_get_image_url( $image_id, 'full' );
		}
	}

	/**
	 * Filters image sitemap URLs for a post.
	 *
	 * Each element may be a URL string or an array with a 'loc' key.
	 *
	 * @param array<int,string|array<string,string>> $images  Image URLs or entries with a loc key.
	 * @param int                                    $post_id Post ID.
	 */
	$images = apply_filters( 'erankly_sitemap_images', $images, $post_id );

	if ( ! is_array( $images ) ) {
		return array();
	}

	$clean = array();

	foreach ( $images as $image ) {
		$url = is_array( $image ) && isset( $image['loc'] ) ? (string) $image['loc'] : (string) $image;
		$url = esc_url_raw( $url );

		if ( erankly_is_absolute_http_url( $url ) ) {
			$clean[] = $url;
		}
	}

	return array_values( array_unique( $clean ) );
}

/**
 * Returns post types eligible for sitemap output.
 *
 * @return array<string,WP_Post_Type>
 */
function erankly_get_sitemap_post_types(): array {
	$post_types = erankly_get_public_post_types();

	unset( $post_types['attachment'] );

	foreach ( $post_types as $post_type => $object ) {
		if ( ! $object instanceof WP_Post_Type || ( 'page' !== $post_type && ! $object->publicly_queryable ) ) {
			unset( $post_types[ $post_type ] );
			continue;
		}

		if ( erankly_get_global_post_type_directive( $post_type, 'noindex' ) || erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
			unset( $post_types[ $post_type ] );
		}
	}

	/**
	 * Filters post types included in the XML sitemap.
	 *
	 * @param array<string,WP_Post_Type> $post_types Sitemap post type objects.
	 */
	return apply_filters( 'erankly_sitemap_post_types', $post_types );
}

/**
 * Filters post type names by global sitemap/robots directives.
 *
 * @param array<int|string,mixed> $post_types Post type names.
 * @return array<int,string>
 */
function erankly_filter_sitemap_post_type_names_by_global_directives( array $post_types ): array {
	$filtered = array();

	foreach ( $post_types as $post_type ) {
		$post_type = sanitize_key( (string) $post_type );

		if ( '' === $post_type ) {
			continue;
		}

		if ( erankly_get_global_post_type_directive( $post_type, 'noindex' ) || erankly_get_global_post_type_directive( $post_type, 'disable_sitemap' ) ) {
			continue;
		}

		$filtered[] = $post_type;
	}

	return array_values( array_unique( $filtered ) );
}

/**
 * Returns the SQL suffix that excludes posts blocked from sitemap output.
 *
 * The tri-state directive is canonical when it contains a recognized value.
 * The legacy boolean is consulted only when that directive is absent, retaining
 * compatibility without allowing a stale legacy flag to override explicit
 * `index` metadata.
 *
 * @param string $post_alias Alias of the posts table in the owning query.
 * @return string
 */
function erankly_get_sitemap_exclusion_sql( string $post_alias = 'p' ): string {
	global $wpdb;

	if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $post_alias ) ) {
		$post_alias = 'p';
	}

	return "
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
}

/**
 * Returns meta query clauses that exclude blocked sitemap URLs.
 *
 * The canonical tri-state directive takes precedence over the legacy boolean.
 * A recognized explicit `index` therefore remains eligible even if stale
 * `_erankly_noindex` metadata is still present.
 *
 * @return array<int|string,mixed>
 */
function erankly_get_sitemap_exclusion_meta_query(): array {
	return array(
		'relation' => 'AND',
		array(
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
		),
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
		array(
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
		),
	);
}

/**
 * Returns meta query clauses that exclude noindex terms from taxonomy sitemaps.
 *
 * Terms use the same canonical and legacy keys as posts, so the post exclusion
 * clauses apply unchanged.
 *
 * @return array<int|string,mixed>
 */
function erankly_get_sitemap_term_exclusion_meta_query(): array {
	return erankly_get_sitemap_exclusion_meta_query();
}

/**
 * Determines whether the user sitemap should be exposed.
 *
 * Single-author sites do not need author archive URLs in XML sitemaps because
 * those archives usually duplicate the main content listing.
 *
 * @return bool
 */
function erankly_should_include_user_sitemap(): bool {
	$author_hidden = erankly_get_global_entity_directive( 'global_special_meta', 'author', 'noindex' )
		|| erankly_get_global_entity_directive( 'global_special_meta', 'author', 'disable_sitemap' );
	$include       = ! $author_hidden && erankly_count_sitemap_users() > 1;

	/**
	 * Filters whether author archive URLs are included in the XML sitemap.
	 *
	 * @param bool $include Whether the user sitemap should be included.
	 */
	return (bool) apply_filters( 'erankly_include_user_sitemap', $include );
}

/**
 * Counts users with sitemap-eligible published content.
 *
 * @return int
 */
function erankly_count_sitemap_users(): int {
	$stats = erankly_get_sitemap_user_stats();

	return $stats['count'];
}

/**
 * Returns aggregate statistics for sitemap-eligible authors.
 *
 * @return array{count:int,lastmod:string}
 */
function erankly_get_sitemap_user_stats(): array {
	global $wpdb;

	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	// The wp_sitemaps_add_provider filter fires on init for every request, so
	// this aggregate query must be transient-cached, not just per-request.
	$transient_key = erankly_get_sitemap_cache_key( 'user_stats' );
	$cached        = get_transient( $transient_key );

	if ( is_array( $cached ) && isset( $cached['count'], $cached['lastmod'] ) ) {
		$cache = array(
			'count'   => absint( $cached['count'] ),
			'lastmod' => (string) $cached['lastmod'],
		);
		return $cache;
	}

	$post_types = array_keys( erankly_get_sitemap_post_types() );

	if ( empty( $post_types ) ) {
		$cache = array(
			'count'   => 0,
			'lastmod' => '',
		);
		set_transient( $transient_key, $cache, HOUR_IN_SECONDS );
		return $cache;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$sql          = "
		SELECT COUNT(DISTINCT p.post_author) AS total, MAX(p.post_modified_gmt) AS lastmod
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_author > 0
			AND p.post_type IN ({$placeholders})
	" . erankly_get_sitemap_exclusion_sql( 'p' );

	$prepared_sql = $wpdb->prepare( $sql, $post_types ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic post type placeholders are generated above and every value is bound here.
	$row          = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The aggregate query is prepared immediately above; table names and the exclusion clause are internal constants.
		$prepared_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
		ARRAY_A
	);

	$cache = array(
		'count'   => is_array( $row ) && isset( $row['total'] ) ? absint( $row['total'] ) : 0,
		'lastmod' => is_array( $row ) && ! empty( $row['lastmod'] ) ? erankly_format_sitemap_gmt_date( (string) $row['lastmod'] ) : '',
	);

	set_transient( $transient_key, $cache, HOUR_IN_SECONDS );

	return $cache;
}

/**
 * Formats a GMT MySQL datetime for XML sitemap output.
 *
 * @param string $date GMT MySQL datetime.
 * @return string
 */
function erankly_format_sitemap_gmt_date( string $date ): string {
	if ( '' === $date || str_starts_with( $date, '0000-00-00' ) ) {
		return '';
	}

	$timestamp = strtotime( $date . ' UTC' );

	return false === $timestamp ? '' : gmdate( DATE_W3C, $timestamp );
}
