<?php
/**
 * XML sitemap generation — News sitemap.
 *
 * Loaded only when the Google News feature is enabled (see erankly_bootstrap()),
 * so these functions are parsed only on sites that use this sitemap type.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	" . erankly_get_sitemap_exclusion_sql( 'p' ) . "
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_news
					WHERE pm_news.post_id = p.ID
						AND pm_news.meta_key = '_erankly_exclude_from_news'
						AND pm_news.meta_value = '1'
				)
		";
	$prepared_sql = $wpdb->prepare(
		$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query contains generated placeholders and every value is bound here.
		array_merge( $post_types, array( gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) ) ) )
	);
	$row          = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The aggregate query is prepared immediately above; table names and the exclusion clause are internal constants.
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
