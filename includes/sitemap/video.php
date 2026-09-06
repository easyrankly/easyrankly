<?php
/**
 * XML sitemap generation: Video sitemap. Loaded only when the video feature is enabled (see
 * erankly_bootstrap()), so these functions are parsed only on sites that use this sitemap type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

erankly_load_video_helpers();

/**
 * Builds the final filtered video entries for one post.
 *
 * @return array<int,array<string,string>>
 */
function erankly_get_video_sitemap_entries_for_post( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$loc        = (string) get_permalink( $post );
	$video_urls = erankly_extract_sitemap_video_urls( (string) $post->post_content );

	/**
	 * Filters source video URLs for one post. Builder, ACF and additional
	 * provider integrations can add their URLs here.
	 */
	$video_urls = apply_filters( 'erankly_sitemap_video_urls', $video_urls, $post_id, (string) $post->post_content );
	$video_urls = is_array( $video_urls ) ? $video_urls : array();
	$video_urls = array_values(
		array_unique(
			array_filter(
				array_map(
					static fn( mixed $url ): string => erankly_absolutize_content_url( (string) $url, $loc ),
					$video_urls
				),
				'erankly_is_absolute_http_url'
			)
		)
	);

	if ( ! $video_urls ) {
		return array();
	}

	$title       = trim( (string) get_the_title( $post_id ) );
	$description = trim( wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ) );
	if ( '' === $description ) {
		$description = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
	}
	if ( '' === $title ) {
		$title = get_bloginfo( 'name' );
	}
	if ( '' === $description ) {
		$description = $title;
	}

	$pubdate = get_post_time( DATE_W3C, true, $post_id );
	$lastmod = erankly_format_sitemap_gmt_date( (string) $post->post_modified_gmt );
	$entries = array();

	foreach ( $video_urls as $video_url ) {
		$content_url   = erankly_get_sitemap_video_content_url( $video_url );
		$embed_url     = erankly_get_sitemap_video_embed_url( $video_url );
		$thumbnail_url = erankly_get_sitemap_video_thumbnail_url( $post_id, $video_url );

		/**
		 * Filters one final video sitemap entry. Integrations for providers other
		 * than YouTube/Vimeo can supply player_loc/content_loc and thumbnail_loc.
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

		if ( ! is_array( $entry ) ) {
			continue;
		}

		$entry['loc']           = erankly_absolutize_content_url( (string) ( $entry['loc'] ?? '' ), $loc );
		$entry['thumbnail_loc'] = erankly_absolutize_content_url( (string) ( $entry['thumbnail_loc'] ?? '' ), $loc );
		$entry['player_loc']    = erankly_absolutize_content_url( (string) ( $entry['player_loc'] ?? '' ), $loc );
		$entry['content_loc']   = erankly_absolutize_content_url( (string) ( $entry['content_loc'] ?? '' ), $loc );

		if (
			! erankly_is_absolute_http_url( $entry['loc'] )
			|| ! erankly_is_absolute_http_url( $entry['thumbnail_loc'] )
			|| ( ! erankly_is_absolute_http_url( $entry['player_loc'] ) && ! erankly_is_absolute_http_url( $entry['content_loc'] ) )
		) {
			continue;
		}

		$entry['title']            = trim( (string) ( $entry['title'] ?? '' ) );
		$entry['description']      = trim( wp_strip_all_tags( (string) ( $entry['description'] ?? '' ) ) );
		$entry['lastmod']          = (string) ( $entry['lastmod'] ?? '' );
		$entry['publication_date'] = (string) ( $entry['publication_date'] ?? '' );

		if ( '' === $entry['title'] || '' === $entry['description'] ) {
			continue;
		}

		$entries[] = $entry;
	}

	return $entries;
}

/**
 * Returns exact eligible post IDs. The transient avoids repeating the content
 * scan for the index count and each rendered page.
 *
 * @return array<int,int>
 */
function erankly_get_video_sitemap_post_ids(): array {
	$cache_key = erankly_get_sitemap_cache_key( 'video_post_ids' );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return array_values( array_filter( array_map( 'absint', $cached ) ) );
	}

	global $wpdb;

	$post_types = array_keys( erankly_get_sitemap_post_types() );
	if ( ! $post_types ) {
		set_transient( $cache_key, array(), HOUR_IN_SECONDS );
		return array();
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
		SELECT p.ID
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
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
			)
	" . erankly_get_sitemap_exclusion_sql( 'p', $post_types ) . '
		ORDER BY p.post_modified_gmt DESC, p.ID DESC
	';
	$args = array_merge(
		$post_types,
		array( $like_yt_watch, $like_ytb, $like_vim, $like_yt_embed, $like_yt_nocookie, $like_vim_embed, $like_html_video, $like_wp_video )
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$ids          = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Candidate query is prepared immediately above.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
	$ids          = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

	/** Builder/ACF integrations can add post IDs before exact extraction. */
	$ids = apply_filters( 'erankly_video_sitemap_candidate_post_ids', $ids, $post_types );
	$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
	$ids = array_values(
		array_filter(
			$ids,
			static fn( int $post_id ): bool => erankly_is_post_sitemap_eligible( $post_id, $post_types )
				&& ! empty( erankly_get_video_sitemap_entries_for_post( $post_id ) )
		)
	);

	set_transient( $cache_key, $ids, HOUR_IN_SECONDS );

	return $ids;
}

/** Counts exact URL entries for video sitemap pagination. */
function erankly_count_video_sitemap_posts(): int {
	return count( erankly_get_video_sitemap_post_ids() );
}

/**
 * Returns the video sitemap XML for one exact page.
 *
 * @return string XML string, or empty string when disabled or no videos exist.
 */
function erankly_get_video_sitemap_xml( int $page = 1 ): string {
	if ( ! (bool) erankly_get_setting( 'enable_video_sitemap', 0 ) ) {
		return '';
	}

	$page      = max( 1, $page );
	$cache_key = erankly_get_sitemap_cache_key( 'video_' . $page );
	$cached    = get_transient( $cache_key );
	if ( is_string( $cached ) ) {
		return $cached;
	}

	$post_ids = array_slice( erankly_get_video_sitemap_post_ids(), ( $page - 1 ) * ERANKLY_SITEMAP_PER_PAGE, ERANKLY_SITEMAP_PER_PAGE );
	if ( ! $post_ids ) {
		return '';
	}

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( erankly_get_sitemap_stylesheet_url() ) . '"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
	$xml .= '        xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

	foreach ( $post_ids as $post_id ) {
		$entries = erankly_get_video_sitemap_entries_for_post( $post_id );
		if ( ! $entries ) {
			continue;
		}

		$xml .= "\t<url>\n";
		$xml .= "\t\t<loc>" . esc_xml( $entries[0]['loc'] ) . "</loc>\n";
		if ( '' !== $entries[0]['lastmod'] ) {
			$xml .= "\t\t<lastmod>" . esc_html( $entries[0]['lastmod'] ) . "</lastmod>\n";
		}

		foreach ( $entries as $entry ) {
			$description = function_exists( 'mb_substr' )
				? mb_substr( $entry['description'], 0, 2048, 'UTF-8' )
				: substr( $entry['description'], 0, 2048 );

			$xml .= "\t\t<video:video>\n";
			$xml .= "\t\t\t<video:thumbnail_loc>" . esc_xml( $entry['thumbnail_loc'] ) . "</video:thumbnail_loc>\n";
			$xml .= "\t\t\t<video:title>" . esc_html( $entry['title'] ) . "</video:title>\n";
			$xml .= "\t\t\t<video:description>" . esc_html( $description ) . "</video:description>\n";
			if ( '' !== $entry['player_loc'] ) {
				$xml .= "\t\t\t<video:player_loc>" . esc_xml( $entry['player_loc'] ) . "</video:player_loc>\n";
			} else {
				$xml .= "\t\t\t<video:content_loc>" . esc_xml( $entry['content_loc'] ) . "</video:content_loc>\n";
			}
			if ( '' !== $entry['publication_date'] ) {
				$xml .= "\t\t\t<video:publication_date>" . esc_html( $entry['publication_date'] ) . "</video:publication_date>\n";
			}
			$xml .= "\t\t</video:video>\n";
		}

		$xml .= "\t</url>\n";
	}

	$xml .= '</urlset>';
	set_transient( $cache_key, $xml, HOUR_IN_SECONDS );

	return $xml;
}
