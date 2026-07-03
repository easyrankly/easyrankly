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
 * Respects both _erankly_noindex and _erankly_disable_sitemap per-post settings.
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
		if ( 1 !== $page || ! (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) ) {
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
 * Gutenberg image/gallery blocks), SEO social image meta, and stored OG/Twitter
 * attachment IDs. Only absolute http(s) URLs are returned; duplicates are dropped.
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

	// 3. SEO social image meta (URL stored directly).
	$social_image = erankly_get_post_meta_string( $post_id, 'social_image_url' );

	if ( '' !== $social_image ) {
		$images[] = esc_url_raw( erankly_replace_variables( $social_image, $post_id ) );
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
 * Returns meta query clauses that exclude blocked sitemap URLs.
 *
 * @return array<int|string,mixed>
 */
function erankly_get_sitemap_exclusion_meta_query(): array {
	return array(
		'relation' => 'AND',
		array(
			'relation' => 'OR',
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
 * Terms use the same _erankly_noindex / _erankly_disable_sitemap keys as
 * posts, so the post exclusion clauses apply unchanged.
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
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = %s
					AND pm_noindex.meta_value = %s
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = %s
					AND pm_sitemap.meta_value = %s
			)
	";

	$args = array_merge(
		$post_types,
		array( '_erankly_noindex', '1', '_erankly_disable_sitemap', '1' )
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic post type placeholders are generated above and every value is bound here.
	$row          = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query is prepared immediately above; only WordPress table identifiers are interpolated.
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

// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Optional sitemap functions are registered only when their feature is enabled.
if ( (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) ) {
/**
 * Counts posts eligible for the Google News sitemap (published in the last 48 hours).
 *
 * @return int
 */
function erankly_count_news_sitemap_posts(): int {
	$stats = erankly_get_news_sitemap_stats();

	return $stats['count'];
}

/**
 * Returns aggregate Google News sitemap statistics.
 *
 * @return array{count:int,lastmod:string}
 */
function erankly_get_news_sitemap_stats(): array {
	global $wpdb;

	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	/**
	 * Filters the post types included in the Google News sitemap.
	 *
	 * @param array<int,string> $post_types Post type names.
	 */
	$setting_types = (array) erankly_get_setting( 'news_sitemap_post_types', array( 'post' ) );
	$post_types    = erankly_filter_sitemap_post_type_names_by_global_directives( (array) apply_filters( 'erankly_news_sitemap_post_types', $setting_types ) );

	if ( empty( $post_types ) ) {
		$cache = array(
			'count'   => 0,
			'lastmod' => '',
		);
		return $cache;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$sql          = "
		SELECT COUNT(p.ID) AS total, MAX(p.post_date_gmt) AS lastmod
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_type IN ({$placeholders})
			AND p.post_date_gmt >= %s
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = %s
					AND pm_noindex.meta_value = %s
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = %s
					AND pm_sitemap.meta_value = %s
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_news
				WHERE pm_news.post_id = p.ID
					AND pm_news.meta_key = %s
					AND pm_news.meta_value = %s
			)
	";
	$prepared_sql = $wpdb->prepare(
		$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query contains generated placeholders and every value is bound here.
		array_merge(
			$post_types,
			array(
				gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) ),
				'_erankly_noindex',
				'1',
				'_erankly_disable_sitemap',
				'1',
				'_erankly_exclude_from_news',
				'1',
			)
		)
	);
	$row          = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query is prepared immediately above; only WordPress table identifiers are interpolated.
		$prepared_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
		ARRAY_A
	);

	$cache = array(
		'count'   => is_array( $row ) && isset( $row['total'] ) ? absint( $row['total'] ) : 0,
		'lastmod' => is_array( $row ) && ! empty( $row['lastmod'] ) ? erankly_format_sitemap_gmt_date( (string) $row['lastmod'] ) : '',
	);

	return $cache;
}

/**
 * Returns the latest publication date among Google News sitemap posts.
 *
 * @return string W3C date string or empty string.
 */
function erankly_get_news_sitemap_lastmod(): string {
	$stats = erankly_get_news_sitemap_stats();

	return $stats['lastmod'];
}

/**
 * Returns the Google News sitemap XML.
 *
 * Includes only posts (post type: post) published in the last 48 hours.
 * Follows the Google News Sitemap spec:
 * https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap
 *
 * @return string XML string, or empty string when no eligible posts exist.
 */
function erankly_get_news_sitemap_xml(): string {
	$cache_key = erankly_get_sitemap_cache_key( 'news' );
	$cached    = get_transient( $cache_key );

	if ( is_string( $cached ) ) {
		return $cached;
	}

	/**
	 * Filters the post types included in the Google News sitemap.
	 *
	 * @param array<int,string> $post_types Post type names.
	 */
	$setting_types = (array) erankly_get_setting( 'news_sitemap_post_types', array( 'post' ) );
	$post_types    = erankly_filter_sitemap_post_type_names_by_global_directives( (array) apply_filters( 'erankly_news_sitemap_post_types', $setting_types ) );

	if ( empty( $post_types ) ) {
		return '';
	}

	$query = new WP_Query(
		array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'posts_per_page'         => ERANKLY_SITEMAP_PER_PAGE,
			'fields'                 => 'ids',
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'date_query'             => array(
				array(
					'after'  => '48 hours ago',
					'column' => 'post_date_gmt',
				),
			),
			'meta_query'             => array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to exclude noindex/disable_news posts.
				erankly_get_sitemap_exclusion_meta_query(),
				array(
					array(
						'key'     => '_erankly_exclude_from_news',
						'compare' => 'NOT EXISTS',
					),
				)
			),
		)
	);

	if ( empty( $query->posts ) ) {
		// Cache the empty result too, or every hit repeats the query until a
		// post is published in the 48-hour window.
		set_transient( $cache_key, '', HOUR_IN_SECONDS );
		return '';
	}

	// The Google News spec requires a non-empty <news:name>, so resolve one through a
	// fallback chain and bail later rather than emit invalid XML.
	$pub_name = trim( (string) erankly_get_setting( 'news_publication_name', '' ) );

	if ( '' === $pub_name ) {
		$pub_name = trim( (string) erankly_get_organization_name() );
	}

	if ( '' === $pub_name ) {
		$pub_name = trim( (string) get_bloginfo( 'name' ) );
	}

	/**
	 * Filters the publication name used in the Google News sitemap.
	 *
	 * Configure the name under Settings → Sitemap → "News publication name".
	 * Note: a News sitemap does not guarantee inclusion in Google News.
	 *
	 * @param string $name Publication name.
	 */
	$pub_name = trim( (string) apply_filters( 'erankly_news_sitemap_publication_name', $pub_name ) );

	if ( '' === $pub_name ) {
		// No resolvable publication name — return empty rather than emit an invalid
		// sitemap. The caller (render_sitemap_response) will send a 404 in this case.
		set_transient( $cache_key, '', HOUR_IN_SECONDS );
		return '';
	}

	/**
	 * Filters the publication language used in the Google News sitemap (ISO 639 two-letter code).
	 *
	 * @param string $lang Publication language code.
	 */
	$pub_lang = (string) apply_filters(
		'erankly_news_sitemap_publication_language',
		strtolower( substr( get_locale(), 0, 2 ) )
	);

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( erankly_get_sitemap_stylesheet_url() ) . '"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
	$xml .= '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

	foreach ( $query->posts as $post_id ) {
		$post_id = (int) $post_id;
		$loc     = get_permalink( $post_id );
		$pubdate = get_post_time( DATE_W3C, true, $post_id );
		$title   = get_the_title( $post_id );

		if ( ! is_string( $loc ) || '' === $loc ) {
			continue;
		}

		$lastmod = get_post_modified_time( DATE_W3C, true, $post_id );

		/**
		 * Filters an individual Google News sitemap URL entry.
		 *
		 * Return an empty array (or an entry with empty 'loc') to exclude the URL.
		 *
		 * @param array<string,string> $entry   Sitemap entry with keys: loc, lastmod, pubdate, title.
		 * @param int                  $post_id Post ID.
		 */
		$entry = apply_filters(
			'erankly_news_sitemap_url',
			array(
				'loc'     => $loc,
				'lastmod' => is_string( $lastmod ) ? $lastmod : '',
				'pubdate' => is_string( $pubdate ) ? $pubdate : '',
				'title'   => $title,
			),
			$post_id
		);

		if ( empty( $entry['loc'] ) ) {
			continue;
		}

		$xml .= "\t<url>\n";
		$xml .= "\t\t<loc>" . esc_xml( esc_url_raw( $entry['loc'] ) ) . "</loc>\n";

		if ( ! empty( $entry['lastmod'] ) ) {
			$xml .= "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
		}

		$xml .= "\t\t<news:news>\n";
		$xml .= "\t\t\t<news:publication>\n";
		$xml .= "\t\t\t\t<news:name>" . esc_html( $pub_name ) . "</news:name>\n";
		$xml .= "\t\t\t\t<news:language>" . esc_html( $pub_lang ) . "</news:language>\n";
		$xml .= "\t\t\t</news:publication>\n";
		$xml .= "\t\t\t<news:publication_date>" . esc_html( $entry['pubdate'] ) . "</news:publication_date>\n";
		$xml .= "\t\t\t<news:title>" . esc_html( $entry['title'] ) . "</news:title>\n";
		$xml .= "\t\t</news:news>\n";
		$xml .= "\t</url>\n";
	}

	$xml .= '</urlset>';

	set_transient( $cache_key, $xml, HOUR_IN_SECONDS );

	return $xml;
}
}
// phpcs:enable Generic.WhiteSpace.ScopeIndent

// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Optional sitemap functions are registered only when their feature is enabled.
if ( (bool) erankly_get_setting( 'enable_image_sitemap', 0 ) ) {
/**
 * Counts published sitemap-eligible posts for the image sitemap.
 *
 * Posts without any associated image are skipped
 * during XML generation. An exact count would require loading every post's
 * content, which is not practical for large sites.
 *
 * @return int
 */
function erankly_count_image_sitemap_items(): int {
	$cache_key = erankly_get_sitemap_cache_key( 'image_count' );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return (int) $cached;
	}

	global $wpdb;

	$post_types = array_keys( erankly_get_sitemap_post_types() );

	if ( empty( $post_types ) ) {
		set_transient( $cache_key, 0, HOUR_IN_SECONDS );
		return 0;
	}

	$placeholders    = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$like_img        = '%' . $wpdb->esc_like( '<img' ) . '%';
	$like_wp_image   = '%' . $wpdb->esc_like( 'wp:image' ) . '%';
	$like_wp_gallery = '%' . $wpdb->esc_like( 'wp:gallery' ) . '%';

	$sql = "
		SELECT COUNT(p.ID)
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_type IN ({$placeholders})
			AND (
				p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_thumb
					WHERE pm_thumb.post_id = p.ID
						AND pm_thumb.meta_key = '_thumbnail_id'
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_soc
					WHERE pm_soc.post_id = p.ID
						AND pm_soc.meta_key IN ('_erankly_social_image_url', '_erankly_og_image_id', '_erankly_twitter_image_id')
						AND pm_soc.meta_value != ''
				)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = %s
					AND pm_noindex.meta_value = %s
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = %s
					AND pm_sitemap.meta_value = %s
			)
	";

	$args = array_merge(
		$post_types,
		array(
			$like_img,
			$like_wp_image,
			$like_wp_gallery,
			'_erankly_noindex',
			'1',
			'_erankly_disable_sitemap',
			'1',
		)
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$count        = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query is prepared immediately above; only WordPress table identifiers are interpolated.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
	);

	set_transient( $cache_key, $count, HOUR_IN_SECONDS );

	return $count;
}

/**
 * Returns the image sitemap XML for the given page.
 *
 * Associates images with the public pages that contain them. Uses each post's
 * own permalink as <loc> (NOT the attachment page). Includes only images that
 * are genuinely attached to or embedded in a publicly viewable, non-excluded page.
 * Does not emit the Google-deprecated <image:title> element.
 *
 * Follows the Google Image Sitemap spec:
 * https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps
 *
 * @param int $page Page number.
 * @return string XML string, or empty string when disabled or no image posts found.
 */
function erankly_get_image_sitemap_xml( int $page = 1 ): string {
	if ( ! (bool) erankly_get_setting( 'enable_image_sitemap', 0 ) ) {
		return '';
	}

	$cache_key = erankly_get_sitemap_cache_key( 'image_' . $page );
	$cached    = get_transient( $cache_key );

	if ( is_string( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$post_types = array_keys( erankly_get_sitemap_post_types() );

	if ( empty( $post_types ) ) {
		return '';
	}

	$page            = max( 1, $page );
	$offset          = ( $page - 1 ) * ERANKLY_SITEMAP_PER_PAGE;
	$placeholders    = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$like_img        = '%' . $wpdb->esc_like( '<img' ) . '%';
	$like_wp_image   = '%' . $wpdb->esc_like( 'wp:image' ) . '%';
	$like_wp_gallery = '%' . $wpdb->esc_like( 'wp:gallery' ) . '%';

	$sql = "
		SELECT p.ID
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_type IN ({$placeholders})
			AND (
				p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_thumb
					WHERE pm_thumb.post_id = p.ID
						AND pm_thumb.meta_key = '_thumbnail_id'
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_soc
					WHERE pm_soc.post_id = p.ID
						AND pm_soc.meta_key IN ('_erankly_social_image_url', '_erankly_og_image_id', '_erankly_twitter_image_id')
						AND pm_soc.meta_value != ''
				)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = %s
					AND pm_noindex.meta_value = %s
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = %s
					AND pm_sitemap.meta_value = %s
			)
			ORDER BY p.post_modified_gmt DESC
			LIMIT %d OFFSET %d
	";

	$args = array_merge(
		$post_types,
		array(
			$like_img,
			$like_wp_image,
			$like_wp_gallery,
			'_erankly_noindex',
			'1',
			'_erankly_disable_sitemap',
			'1',
			ERANKLY_SITEMAP_PER_PAGE,
			$offset,
		)
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$results      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query is prepared immediately above; only WordPress table identifiers are interpolated.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
	);

	if ( empty( $results ) ) {
		return '';
	}

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( erankly_get_sitemap_stylesheet_url() ) . '"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
	$xml .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

	$has_entries = false;

	foreach ( $results as $row ) {
		$post_id = (int) $row->ID;
		$images  = erankly_get_sitemap_images( $post_id );

		if ( empty( $images ) ) {
			continue;
		}

		$loc     = get_permalink( $post_id );
		$lastmod = get_post_modified_time( DATE_W3C, true, $post_id );

		if ( ! is_string( $loc ) || '' === $loc ) {
			continue;
		}

		$image_nodes = '';
		foreach ( $images as $image_url ) {
			/**
			 * Filters an individual image sitemap entry.
			 *
			 * Return an empty array to exclude the image.
			 *
			 * @param array<string,string> $entry   Sitemap entry.
			 * @param int                  $post_id Post ID.
			 */
			$entry = apply_filters(
				'erankly_image_sitemap_url',
				array(
					'loc'       => $loc,
					'image_loc' => $image_url,
				),
				$post_id
			);

			if ( empty( $entry['image_loc'] ) ) {
				continue;
			}

			$image_nodes .= "\t\t<image:image>\n";
			$image_nodes .= "\t\t\t<image:loc>" . esc_xml( esc_url_raw( $entry['image_loc'] ) ) . "</image:loc>\n";
			// <image:title> is deliberately omitted: Google deprecated it.
			$image_nodes .= "\t\t</image:image>\n";
		}

		if ( '' === $image_nodes ) {
			continue;
		}

		$has_entries = true;
		$xml        .= "\t<url>\n";
		$xml        .= "\t\t<loc>" . esc_xml( esc_url_raw( $loc ) ) . "</loc>\n";

		if ( is_string( $lastmod ) && '' !== $lastmod ) {
			$xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
		}

		$xml .= $image_nodes;
		$xml .= "\t</url>\n";
	}

	// Every candidate post was filtered out (no usable image): serve a 404
	// instead of caching an empty <urlset>.
	if ( ! $has_entries ) {
		return '';
	}

	$xml .= '</urlset>';

	set_transient( $cache_key, $xml, HOUR_IN_SECONDS );

	return $xml;
}
}
// phpcs:enable Generic.WhiteSpace.ScopeIndent

// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Optional sitemap functions are registered only when their feature is enabled.
if ( (bool) erankly_get_setting( 'enable_video_sitemap', 0 ) ) {
/**
 * Counts posts with embedded YouTube or Vimeo videos eligible for the video sitemap.
 *
 * Detects watch URLs, youtu.be short URLs, vimeo.com page URLs, YouTube embed
 * iframes, Vimeo player iframes, self-hosted HTML5 videos, and wp:video blocks.
 *
 * @return int
 */
function erankly_count_video_sitemap_posts(): int {
	$cache_key = erankly_get_sitemap_cache_key( 'video_count' );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return (int) $cached;
	}

	global $wpdb;

	$post_types = array_keys( erankly_get_sitemap_post_types() );

	if ( empty( $post_types ) ) {
		set_transient( $cache_key, 0, HOUR_IN_SECONDS );
		return 0;
	}

	$placeholders     = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$like_yt_watch    = '%' . $wpdb->esc_like( 'youtube.com/watch' ) . '%';
	$like_ytb         = '%' . $wpdb->esc_like( 'youtu.be/' ) . '%';
	$like_vim         = '%' . $wpdb->esc_like( 'vimeo.com/' ) . '%';
	$like_yt_embed    = '%' . $wpdb->esc_like( 'youtube.com/embed/' ) . '%';
	$like_yt_nocookie = '%' . $wpdb->esc_like( 'youtube-nocookie.com/embed/' ) . '%';
	$like_vim_embed   = '%' . $wpdb->esc_like( 'player.vimeo.com/video/' ) . '%';
	$like_html_video  = '%' . $wpdb->esc_like( '<video' ) . '%';
	$like_wp_video    = '%' . $wpdb->esc_like( 'wp:video' ) . '%';

	$sql = "
		SELECT COUNT(p.ID)
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_type IN ({$placeholders})
			AND (
				p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR (
					(p.post_content LIKE %s OR p.post_content LIKE %s)
					AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} pm_thumb
						WHERE pm_thumb.post_id = p.ID
							AND pm_thumb.meta_key = '_thumbnail_id'
					)
				)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = %s
					AND pm_noindex.meta_value = %s
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = %s
					AND pm_sitemap.meta_value = %s
			)
	";

	$args = array_merge(
		$post_types,
		array(
			$like_yt_watch,
			$like_ytb,
			$like_vim,
			$like_yt_embed,
			$like_yt_nocookie,
			$like_vim_embed,
			$like_html_video,
			$like_wp_video,
			'_erankly_noindex',
			'1',
			'_erankly_disable_sitemap',
			'1',
		)
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$count        = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query is prepared immediately above; only WordPress table identifiers are interpolated.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
	);

	set_transient( $cache_key, $count, HOUR_IN_SECONDS );

	return $count;
}

/**
 * Returns the video sitemap XML for the given page.
 *
 * Includes published posts that contain embedded YouTube or Vimeo videos, detected
 * via watch URLs, youtu.be short links, iframes, or Gutenberg core/embed blocks.
 * Multiple videos on the same page each produce a separate <video:video> element
 * within the same <url> entry (per the Google Video Sitemap spec §2.3).
 *
 * Note: submitting a Video sitemap does not guarantee indexing by Google; the
 * embedded player must also be crawlable.
 *
 * Follows the Google Video Sitemap spec:
 * https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps
 *
 * @param int $page Page number.
 * @return string XML string, or empty string when disabled or no video posts found.
 */
function erankly_get_video_sitemap_xml( int $page = 1 ): string {
	if ( ! (bool) erankly_get_setting( 'enable_video_sitemap', 0 ) ) {
		return '';
	}

	$cache_key = erankly_get_sitemap_cache_key( 'video_' . $page );
	$cached    = get_transient( $cache_key );

	if ( is_string( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$post_types = array_keys( erankly_get_sitemap_post_types() );

	if ( empty( $post_types ) ) {
		return '';
	}

	$page             = max( 1, $page );
	$offset           = ( $page - 1 ) * ERANKLY_SITEMAP_PER_PAGE;
	$placeholders     = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$like_yt_watch    = '%' . $wpdb->esc_like( 'youtube.com/watch' ) . '%';
	$like_ytb         = '%' . $wpdb->esc_like( 'youtu.be/' ) . '%';
	$like_vim         = '%' . $wpdb->esc_like( 'vimeo.com/' ) . '%';
	$like_yt_embed    = '%' . $wpdb->esc_like( 'youtube.com/embed/' ) . '%';
	$like_yt_nocookie = '%' . $wpdb->esc_like( 'youtube-nocookie.com/embed/' ) . '%';
	$like_vim_embed   = '%' . $wpdb->esc_like( 'player.vimeo.com/video/' ) . '%';
	$like_video       = '%' . $wpdb->esc_like( '<video' ) . '%';
	$like_wp_video    = '%' . $wpdb->esc_like( 'wp:video' ) . '%';

	$sql = "
		SELECT p.ID, p.post_content, p.post_modified_gmt
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_type IN ({$placeholders})
			AND (
				p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR (
					(p.post_content LIKE %s OR p.post_content LIKE %s)
					AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} pm_thumb
						WHERE pm_thumb.post_id = p.ID
							AND pm_thumb.meta_key = '_thumbnail_id'
					)
				)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = %s
					AND pm_noindex.meta_value = %s
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = %s
					AND pm_sitemap.meta_value = %s
			)
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d
	";

	$args = array_merge(
		$post_types,
		array(
			$like_yt_watch,
			$like_ytb,
			$like_vim,
			$like_yt_embed,
			$like_yt_nocookie,
			$like_vim_embed,
			$like_video,
			$like_wp_video,
			'_erankly_noindex',
			'1',
			'_erankly_disable_sitemap',
			'1',
			ERANKLY_SITEMAP_PER_PAGE,
			$offset,
		)
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$results      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query is prepared immediately above; only WordPress table identifiers are interpolated.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
	);

	if ( empty( $results ) ) {
		return '';
	}

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( erankly_get_sitemap_stylesheet_url() ) . '"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
	$xml .= '        xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

	$has_entries = false;

	foreach ( $results as $row ) {
		$post_id    = (int) $row->ID;
		$loc        = get_permalink( $post_id );
		$video_urls = erankly_extract_sitemap_video_urls( (string) $row->post_content );

		if ( ! is_string( $loc ) || '' === $loc || empty( $video_urls ) ) {
			continue;
		}

		$title       = get_the_title( $post_id );
		$description = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		$pubdate     = get_post_time( DATE_W3C, true, $post_id );
		$lastmod     = isset( $row->post_modified_gmt ) ? erankly_format_sitemap_gmt_date( (string) $row->post_modified_gmt ) : '';

		$page_videos_xml = '';

		foreach ( $video_urls as $video_url ) {
			$content_url   = erankly_get_sitemap_video_content_url( $video_url );
			$embed_url     = erankly_get_sitemap_video_embed_url( $video_url );
			$thumbnail_url = erankly_get_sitemap_video_thumbnail_url( $post_id, $video_url );

			if ( ( '' === $embed_url && '' === $content_url ) || '' === $thumbnail_url ) {
				continue;
			}

			/**
			 * Filters an individual video sitemap entry.
			 *
			 * Return an empty array to exclude the video. The 'video_url' key contains
			 * the original source URL detected in the post content. If a post has
			 * multiple videos this filter fires once per video.
			 *
			 * @param array<string,string> $entry   Sitemap entry.
			 * @param int                  $post_id Post ID.
			 */
			$entry = apply_filters(
				'erankly_video_sitemap_url',
				array(
					'loc'              => $loc,
					'lastmod'          => $lastmod,
					'thumbnail_loc'    => $thumbnail_url,
					'title'            => $title,
					'description'      => $description,
					'player_loc'       => $embed_url,
					'content_loc'      => $content_url,
					'publication_date' => is_string( $pubdate ) ? $pubdate : '',
					'video_url'        => $video_url,
				),
				$post_id
			);

			if ( empty( $entry['thumbnail_loc'] ) ) {
				continue;
			}

			$video_block  = "\t\t<video:video>\n";
			$video_block .= "\t\t\t<video:thumbnail_loc>" . esc_xml( esc_url_raw( $entry['thumbnail_loc'] ) ) . "</video:thumbnail_loc>\n";
			$video_block .= "\t\t\t<video:title>" . esc_html( (string) $entry['title'] ) . "</video:title>\n";
			$video_block .= "\t\t\t<video:description>" . esc_html( substr( (string) $entry['description'], 0, 2048 ) ) . "</video:description>\n";

			if ( ! empty( $entry['player_loc'] ) ) {
				$video_block .= "\t\t\t<video:player_loc>" . esc_xml( esc_url_raw( $entry['player_loc'] ) ) . "</video:player_loc>\n";
			} elseif ( ! empty( $entry['content_loc'] ) ) {
				$video_block .= "\t\t\t<video:content_loc>" . esc_xml( esc_url_raw( $entry['content_loc'] ) ) . "</video:content_loc>\n";
			}

			if ( ! empty( $entry['publication_date'] ) ) {
				$video_block .= "\t\t\t<video:publication_date>" . esc_html( $entry['publication_date'] ) . "</video:publication_date>\n";
			}

			$video_block .= "\t\t</video:video>\n";

			$page_videos_xml .= $video_block;
		}

		if ( '' === $page_videos_xml ) {
			continue;
		}

		$has_entries = true;
		$xml        .= "\t<url>\n";
		$xml        .= "\t\t<loc>" . esc_xml( esc_url_raw( $loc ) ) . "</loc>\n";

		if ( '' !== $lastmod ) {
			$xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
		}

		$xml .= $page_videos_xml;
		$xml .= "\t</url>\n";
	}

	// Every candidate post was filtered out (no usable video entry): serve a
	// 404 instead of caching an empty <urlset>.
	if ( ! $has_entries ) {
		return '';
	}

	$xml .= '</urlset>';

	set_transient( $cache_key, $xml, HOUR_IN_SECONDS );

	return $xml;
}

/**
 * Extracts all YouTube and Vimeo video URLs from post content.
 *
 * Detects:
 * - YouTube watch URLs (youtube.com/watch?v=) and youtu.be/ short links.
 * - YouTube embed iframes (<iframe src="youtube.com/embed/...">), including
 *   youtube-nocookie.com variants.
 * - Vimeo page URLs (vimeo.com/\d+).
 * - Vimeo player iframes (<iframe src="player.vimeo.com/video/...">).
 * - Gutenberg core/embed blocks whose JSON "url" attribute contains any of the above.
 *
 * Self-hosted video files (wp:video src= MP4/WebM) are not included because they
 * require a direct file URL rather than an embeddable player page.
 *
 * @param string $content Post content (raw, unfiltered).
 * @return array<int,string> Unique, deduplicated video page URLs (normalised to watch/vimeo-page form).
 */
function erankly_extract_sitemap_video_urls( string $content ): array {
	$urls = array();

	// 1. Canonical watch / short / vimeo page URLs.
	// These are stored verbatim in Gutenberg wp:embed block JSON attrs too.
	preg_match_all(
		'#https?://(?:www\.|m\.)?(?:(?:youtube\.com/watch\S*[?&]v=|youtu\.be/)[a-zA-Z0-9_-]{11}|vimeo\.com/\d+)#',
		$content,
		$watch_matches
	);
	foreach ( $watch_matches[0] as $url ) {
		$urls[] = $url;
	}

	// 2. YouTube embed iframes — normalise to canonical watch URL.
	// Video IDs are regex-validated as [a-zA-Z0-9_-]{11} (case-sensitive; no sanitize_key).
	preg_match_all(
		'#<iframe[^>]*\ssrc=["\']https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([a-zA-Z0-9_-]{11})(?:[^"\']*)["\']#i',
		$content,
		$yt_iframes
	);
	foreach ( $yt_iframes[1] as $video_id ) {
		$urls[] = 'https://www.youtube.com/watch?v=' . $video_id;
	}

	// 3. Vimeo player iframes — normalise to vimeo.com page URL.
	preg_match_all(
		'#<iframe[^>]*\ssrc=["\']https?://(?:www\.)?player\.vimeo\.com/video/(\d+)(?:[^"\']*)["\']#i',
		$content,
		$vim_iframes
	);
	foreach ( $vim_iframes[1] as $video_id ) {
		$urls[] = 'https://vimeo.com/' . absint( $video_id );
	}

	// 4. HTML5 video tags and Gutenberg wp:video.
	preg_match_all(
		'#<(?:video|source)[^>]*\ssrc=["\']([^"\']+)["\']#i',
		$content,
		$html_videos
	);
	foreach ( $html_videos[1] as $src ) {
		$urls[] = esc_url_raw( $src );
	}

	// Deduplicate preserving insertion order, re-index.
	return array_values( array_unique( array_filter( $urls ) ) );
}


/**
 * Returns the embed player URL for a YouTube or Vimeo video URL.
 *
 * Accepts canonical watch URLs, youtu.be short URLs, vimeo.com page URLs, as well
 * as already-embed forms (youtube.com/embed/, player.vimeo.com/video/), so callers
 * do not need to normalise before passing.
 *
 * @param string $video_url Video URL (page, short, or embed form).
 * @return string Embed URL, or empty string if unsupported.
 */
function erankly_get_sitemap_video_embed_url( string $video_url ): string {
	// Already a YouTube embed URL (incl. youtube-nocookie).
	if ( preg_match( '#youtube(?:-nocookie)?\.com/embed/([a-zA-Z0-9_-]{11})#', $video_url, $m ) ) {
		return 'https://www.youtube.com/embed/' . $m[1]; // $m[1] is regex-validated.
	}

	// Already a Vimeo player URL.
	if ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $video_url, $m ) ) {
		return 'https://player.vimeo.com/video/' . absint( $m[1] );
	}

	// YouTube watch or youtu.be short URL.
	if ( preg_match( '#(?:youtube\.com/watch\S*[?&]v=|youtu\.be/)([a-zA-Z0-9_-]{11})#', $video_url, $m ) ) {
		return 'https://www.youtube.com/embed/' . $m[1]; // $m[1] is regex-validated.
	}

	// Vimeo page URL.
	if ( preg_match( '#vimeo\.com/(\d+)#', $video_url, $m ) ) {
		return 'https://player.vimeo.com/video/' . absint( $m[1] );
	}

	return '';
}

/**
 * Returns the direct file URL for self-hosted HTML5 videos.
 *
 * @param string $video_url Video URL.
 * @return string Content URL, or empty string if unsupported.
 */
function erankly_get_sitemap_video_content_url( string $video_url ): string {
	$path = wp_parse_url( $video_url, PHP_URL_PATH );
	if ( is_string( $path ) && preg_match( '#\.(mp4|webm|m4v|mov|ogg)$#i', $path ) ) {
		return $video_url;
	}

	return '';
}

/**
 * Returns the thumbnail URL for a video sitemap entry.
 *
 * Uses the post's featured image when available; falls back to the YouTube
 * thumbnail API. Vimeo does not expose a public thumbnail API URL.
 *
 * @param int    $post_id   Post ID.
 * @param string $video_url Video page URL (used only for the YouTube fallback).
 * @return string Thumbnail URL, or empty string.
 */
function erankly_get_sitemap_video_thumbnail_url( int $post_id, string $video_url ): string {
	$featured_id = (int) get_post_thumbnail_id( $post_id );

	if ( $featured_id > 0 ) {
		$url = erankly_get_image_url( $featured_id, 'full' );

		if ( '' !== $url ) {
			return $url;
		}
	}

	// YouTube public thumbnail API — video ID is regex-validated, no sanitize_key.
	if ( preg_match( '#(?:youtube\.com/watch\S*[?&]v=|youtu\.be/)([a-zA-Z0-9_-]{11})#', $video_url, $m ) ) {
		return 'https://img.youtube.com/vi/' . $m[1] . '/0.jpg';
	}

	// Vimeo and self-hosted HTML5 videos have no thumbnail unless a featured image
	// is set: EasyRankly does not use any third-party Vimeo thumbnail service, so such
	// entries are skipped by the sitemap when no featured image exists.
	return '';
}
}
// phpcs:enable Generic.WhiteSpace.ScopeIndent
