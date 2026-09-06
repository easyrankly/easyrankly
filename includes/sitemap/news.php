<?php
/**
 * XML sitemap generation: News sitemap. Loaded only when the Google News feature is enabled (see
 * erankly_bootstrap()), so these functions are parsed only on sites that use this sitemap type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Counts the exact posts that will be emitted by the Google News sitemap. */
function erankly_count_news_sitemap_posts(): int {
	$stats = erankly_get_news_sitemap_stats();

	return $stats['count'];
}

/** @return array<int,string> */
function erankly_get_news_sitemap_post_types(): array {
	$setting_types = (array) erankly_get_setting( 'news_sitemap_post_types', array( 'post' ) );

	return erankly_filter_sitemap_post_type_names_by_global_directives(
		(array) apply_filters( 'erankly_news_sitemap_post_types', $setting_types )
	);
}

/** Returns the SQL suffix for the per-post News exclusion flag. */
function erankly_get_news_sitemap_exclusion_sql( string $post_alias = 'p' ): string {
	global $wpdb;

	if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $post_alias ) ) {
		$post_alias = 'p';
	}

	return "
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm_erankly_news_excluded
			WHERE pm_erankly_news_excluded.post_id = {$post_alias}.ID
				AND pm_erankly_news_excluded.meta_key = '_erankly_exclude_from_news'
				AND pm_erankly_news_excluded.meta_value = '1'
		)
	";
}

/**
 * Builds one final News sitemap entry, including the public extension filter.
 *
 * @return array<string,string> Empty when the post cannot produce valid News XML.
 */
function erankly_get_news_sitemap_entry( int $post_id ): array {
	$loc     = get_permalink( $post_id );
	$pubdate = get_post_time( DATE_W3C, true, $post_id );
	$lastmod = get_post_modified_time( DATE_W3C, true, $post_id );
	$title   = get_the_title( $post_id );

	if ( ! is_string( $loc ) || '' === $loc ) {
		return array();
	}

	/**
	 * Filters an individual Google News sitemap URL entry. Return an empty array (or an entry with an empty
	 * required field) to exclude the URL.
	 *
	 * @param array<string,string> $entry Sitemap entry with keys: loc, lastmod, pubdate, title.
	 */
	$entry = apply_filters(
		'erankly_news_sitemap_url',
		array(
			'loc'     => $loc,
			'lastmod' => is_string( $lastmod ) ? $lastmod : '',
			'pubdate' => is_string( $pubdate ) ? $pubdate : '',
			'title'   => is_string( $title ) ? $title : '',
		),
		$post_id
	);

	if ( ! is_array( $entry ) ) {
		return array();
	}

	$loc       = esc_url_raw( (string) ( $entry['loc'] ?? '' ) );
	$pubdate   = trim( (string) ( $entry['pubdate'] ?? '' ) );
	$lastmod   = trim( (string) ( $entry['lastmod'] ?? '' ) );
	$title     = trim( wp_strip_all_tags( (string) ( $entry['title'] ?? '' ) ) );
	$published = strtotime( $pubdate );
	$modified  = '' === $lastmod ? false : strtotime( $lastmod );

	if ( ! erankly_is_absolute_http_url( $loc ) || '' === $title || false === $published ) {
		return array();
	}

	return array(
		'loc'     => $loc,
		'lastmod' => false === $modified ? '' : gmdate( DATE_W3C, $modified ),
		'pubdate' => gmdate( DATE_W3C, $published ),
		'title'   => $title,
	);
}

/**
 * Returns the exact, cached page entries after all eligibility and entry filters have run. Keeping this list
 * rather than a database aggregate makes the sitemap index page count match rendered output.
 *
 * @return array<int,array{post_id:int,entry:array<string,string>}>
 */
function erankly_get_news_sitemap_entries(): array {
	static $entries_by_cache_key = array();

	$cache_key = erankly_get_sitemap_cache_key( 'news_entries' );
	if ( isset( $entries_by_cache_key[ $cache_key ] ) && is_array( $entries_by_cache_key[ $cache_key ] ) ) {
		return $entries_by_cache_key[ $cache_key ];
	}
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		$entries_by_cache_key[ $cache_key ] = array_values(
			array_filter(
				$cached,
				static fn( mixed $row ): bool => is_array( $row )
					&& absint( $row['post_id'] ?? 0 ) > 0
					&& is_array( $row['entry'] ?? null )
			)
		);

		return $entries_by_cache_key[ $cache_key ];
	}

	$post_types = erankly_get_news_sitemap_post_types();
	if ( empty( $post_types ) ) {
		$entries_by_cache_key[ $cache_key ] = array();
		set_transient( $cache_key, $entries_by_cache_key[ $cache_key ], HOUR_IN_SECONDS );
		return $entries_by_cache_key[ $cache_key ];
	}

	global $wpdb;

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$sql          = "
		SELECT p.ID
		FROM {$wpdb->posts} p
		WHERE p.post_status = 'publish'
			AND p.post_type IN ({$placeholders})
			AND p.post_date_gmt >= %s
	" . erankly_get_sitemap_exclusion_sql( 'p', $post_types )
	. erankly_get_news_sitemap_exclusion_sql( 'p' ) . '
		ORDER BY p.post_date_gmt DESC, p.ID DESC
	';
	$prepared_sql = $wpdb->prepare(
		$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query contains generated placeholders and every value is bound here.
		array_merge( $post_types, array( gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) ) ) )
	);
	$post_ids     = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The bounded News candidate query is prepared immediately above.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
	);
	if ( '' !== (string) $wpdb->last_error ) {
		return array();
	}

	$entries = array();
	foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) ) as $post_id ) {
		if ( ! erankly_is_post_sitemap_eligible( $post_id, $post_types ) ) {
			continue;
		}

		$entry = erankly_get_news_sitemap_entry( $post_id );
		if ( ! empty( $entry ) ) {
			$entries[] = array(
				'post_id' => $post_id,
				'entry'   => $entry,
			);
		}
	}

	set_transient( $cache_key, $entries, HOUR_IN_SECONDS );
	$entries_by_cache_key[ $cache_key ] = $entries;

	return $entries_by_cache_key[ $cache_key ];
}

/** @return array{count:int,lastmod:string} */
function erankly_get_news_sitemap_stats(): array {
	static $stats_by_cache_key = array();

	$cache_key = erankly_get_sitemap_cache_key( 'news_stats' );
	if ( isset( $stats_by_cache_key[ $cache_key ] ) && is_array( $stats_by_cache_key[ $cache_key ] ) ) {
		return $stats_by_cache_key[ $cache_key ];
	}
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && isset( $cached['count'], $cached['lastmod'] ) ) {
		$stats_by_cache_key[ $cache_key ] = array(
			'count'   => absint( $cached['count'] ),
			'lastmod' => (string) $cached['lastmod'],
		);

		return $stats_by_cache_key[ $cache_key ];
	}

	$lastmod_timestamp = 0;
	foreach ( erankly_get_news_sitemap_entries() as $row ) {
		$lastmod   = is_array( $row['entry'] ?? null ) ? (string) ( $row['entry']['lastmod'] ?? '' ) : '';
		$timestamp = '' === $lastmod ? false : strtotime( $lastmod );
		if ( false !== $timestamp ) {
			$lastmod_timestamp = max( $lastmod_timestamp, $timestamp );
		}
	}

	$stats_by_cache_key[ $cache_key ] = array(
		'count'   => count( erankly_get_news_sitemap_entries() ),
		'lastmod' => $lastmod_timestamp > 0 ? gmdate( DATE_W3C, $lastmod_timestamp ) : '',
	);
	set_transient( $cache_key, $stats_by_cache_key[ $cache_key ], HOUR_IN_SECONDS );

	return $stats_by_cache_key[ $cache_key ];
}

/** @return string W3C date string or empty string. */
function erankly_get_news_sitemap_lastmod(): string {
	$stats = erankly_get_news_sitemap_stats();

	return $stats['lastmod'];
}

/**
 * Returns the Google News sitemap XML for one exact page of filtered entries.
 *
 * @return string XML string, or empty string when no eligible posts exist.
 */
function erankly_get_news_sitemap_xml( int $page = 1 ): string {
	$page      = max( 1, $page );
	$cache_key = erankly_get_sitemap_cache_key( 'news_' . $page );
	$cached    = get_transient( $cache_key );

	if ( is_string( $cached ) ) {
		return $cached;
	}

	$entries = array_slice(
		erankly_get_news_sitemap_entries(),
		( $page - 1 ) * ERANKLY_SITEMAP_PER_PAGE,
		ERANKLY_SITEMAP_PER_PAGE
	);
	if ( empty( $entries ) ) {
		set_transient( $cache_key, '', HOUR_IN_SECONDS );
		return '';
	}

	// The Google News spec requires a non-empty <news:name>, so resolve one through a fallback chain and bail
	// rather than emit invalid XML.
	$pub_name = trim( (string) erankly_get_setting( 'news_publication_name', '' ) );
	if ( '' === $pub_name ) {
		$pub_name = trim( (string) erankly_get_organization_name() );
	}
	if ( '' === $pub_name ) {
		$pub_name = trim( (string) get_bloginfo( 'name' ) );
	}

	/** Filters the publication name used in the Google News sitemap. */
	$pub_name = trim( (string) apply_filters( 'erankly_news_sitemap_publication_name', $pub_name ) );
	if ( '' === $pub_name ) {
		set_transient( $cache_key, '', HOUR_IN_SECONDS );
		return '';
	}

	/** Filters the publication language used in the Google News sitemap (ISO 639 two-letter code). */
	$pub_lang = (string) apply_filters(
		'erankly_news_sitemap_publication_language',
		strtolower( substr( get_locale(), 0, 2 ) )
	);

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( erankly_get_sitemap_stylesheet_url() ) . '"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
	$xml .= '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

	foreach ( $entries as $row ) {
		$entry = is_array( $row['entry'] ?? null ) ? $row['entry'] : array();
		if ( empty( $entry ) ) {
			continue;
		}

		$xml .= "\t<url>\n";
		$xml .= "\t\t<loc>" . esc_xml( (string) $entry['loc'] ) . "</loc>\n";
		if ( '' !== (string) ( $entry['lastmod'] ?? '' ) ) {
			$xml .= "\t\t<lastmod>" . esc_html( (string) $entry['lastmod'] ) . "</lastmod>\n";
		}
		$xml .= "\t\t<news:news>\n";
		$xml .= "\t\t\t<news:publication>\n";
		$xml .= "\t\t\t\t<news:name>" . esc_html( $pub_name ) . "</news:name>\n";
		$xml .= "\t\t\t\t<news:language>" . esc_html( $pub_lang ) . "</news:language>\n";
		$xml .= "\t\t\t</news:publication>\n";
		$xml .= "\t\t\t<news:publication_date>" . esc_html( (string) $entry['pubdate'] ) . "</news:publication_date>\n";
		$xml .= "\t\t\t<news:title>" . esc_html( (string) $entry['title'] ) . "</news:title>\n";
		$xml .= "\t\t</news:news>\n";
		$xml .= "\t</url>\n";
	}

	$xml .= '</urlset>';
	set_transient( $cache_key, $xml, HOUR_IN_SECONDS );

	return $xml;
}
