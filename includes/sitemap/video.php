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
 * Counts posts with embedded YouTube or Vimeo videos eligible for the video sitemap. Detects watch URLs,
 * youtu.be short URLs, vimeo.com page URLs, YouTube embed iframes, Vimeo player iframes, self-hosted HTML5
 * videos, and wp:video blocks.
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
	" . erankly_get_sitemap_exclusion_sql( 'p' );

	$args = array_merge(
		$post_types,
		array( $like_yt_watch, $like_ytb, $like_vim, $like_yt_embed, $like_yt_nocookie, $like_vim_embed, $like_html_video, $like_wp_video )
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$count        = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The aggregate query is prepared immediately above; table names and the exclusion clause are internal constants.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
	);

	set_transient( $cache_key, $count, HOUR_IN_SECONDS );

	return $count;
}

/**
 * Returns the video sitemap XML for the given page. Includes published posts that contain embedded YouTube or
 * Vimeo videos, detected via watch URLs, youtu.be short links, iframes, or Gutenberg core/embed blocks. Multiple
 * videos on the same page each produce a separate <video:video> element within the same <url> entry (per the
 * Google Video Sitemap spec §2.3). Note: submitting a Video sitemap does not guarantee indexing by Google; the
 * embedded player must also be crawlable. Follows the Google Video Sitemap spec:
 * https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps
 *
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
	" . erankly_get_sitemap_exclusion_sql( 'p' ) . '
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d
	';

	$args = array_merge(
		$post_types,
		array( $like_yt_watch, $like_ytb, $like_vim, $like_yt_embed, $like_yt_nocookie, $like_vim_embed, $like_video, $like_wp_video, ERANKLY_SITEMAP_PER_PAGE, $offset )
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$results      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The video query is prepared immediately above; table names and the exclusion clause are internal constants.
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
 * Filters an individual video sitemap entry. Return an empty array to exclude the video. The 'video_url' key
 * contains the original source URL detected in the post content. If a post has multiple videos this filter fires
 * once per video.
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
			$description  = (string) $entry['description'];
			if ( function_exists( 'mb_substr' ) ) {
				$description = mb_substr( $description, 0, 2048, 'UTF-8' );
			} else {
				$description = substr( $description, 0, 2048 );
			}
			$video_block .= "\t\t\t<video:description>" . esc_html( $description ) . "</video:description>\n";

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
