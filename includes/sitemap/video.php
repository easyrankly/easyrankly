<?php
/**
 * XML sitemap generation — Video sitemap.
 *
 * Loaded only when the video feature is enabled (see erankly_bootstrap()),
 * so these functions are parsed only on sites that use this sitemap type.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
				" . "AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = '_erankly_noindex'
					AND pm_noindex.meta_value = '1'
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = '_erankly_disable_sitemap'
					AND pm_sitemap.meta_value = '1'
			)" . '
	';

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
				" . "AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_noindex
				WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = '_erankly_noindex'
					AND pm_noindex.meta_value = '1'
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_sitemap
				WHERE pm_sitemap.post_id = p.ID
					AND pm_sitemap.meta_key = '_erankly_disable_sitemap'
					AND pm_sitemap.meta_value = '1'
			)" . '
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
		'#<iframe[^>]+\ssrc=["\']https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([a-zA-Z0-9_-]{11})(?:[^"\']*)["\']#i',
		$content,
		$yt_iframes
	);
	foreach ( $yt_iframes[1] as $video_id ) {
		$urls[] = 'https://www.youtube.com/watch?v=' . $video_id;
	}

	// 3. Vimeo player iframes — normalise to vimeo.com page URL.
	preg_match_all(
		'#<iframe[^>]+\ssrc=["\']https?://(?:www\.)?player\.vimeo\.com/video/(\d+)(?:[^"\']*)["\']#i',
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

	// Vimeo redirect-based public thumbnail.
	if ( preg_match( '#vimeo\.com/(\d+)#', $video_url, $m ) ) {
		return 'https://vumbnail.com/' . $m[1] . '.jpg';
	}

	// Self-hosted HTML5 videos won't have a thumbnail unless featured_id is set.
	return '';
}
