<?php
/** Shared video URL extraction helpers. Used by the video sitemap and VideoObject schema so both modules stay in sync. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts all embedded video URLs from post content. Detects YouTube, Vimeo, HTML5 video tags, and Gutenberg
 * wp:video blocks.
 *
 * @param string $content Post content (raw, unfiltered).
 */
function erankly_extract_video_urls( string $content ): array {
	$urls = array();

	preg_match_all(
		'#https?://(?:www\.|m\.)?(?:(?:youtube\.com/watch\S*[?&]v=|youtu\.be/)[a-zA-Z0-9_-]{11}|vimeo\.com/\d+)#',
		$content,
		$watch_matches
	);
	foreach ( $watch_matches[0] as $url ) {
		$urls[] = $url;
	}

	preg_match_all(
		'#<iframe[^>]+\ssrc=["\']https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([a-zA-Z0-9_-]{11})(?:[^"\']*)["\']#i',
		$content,
		$yt_iframes
	);
	foreach ( $yt_iframes[1] as $video_id ) {
		$urls[] = 'https://www.youtube.com/watch?v=' . $video_id;
	}

	preg_match_all(
		'#<iframe[^>]+\ssrc=["\']https?://(?:www\.)?player\.vimeo\.com/video/(\d+)(?:[^"\']*)["\']#i',
		$content,
		$vim_iframes
	);
	foreach ( $vim_iframes[1] as $video_id ) {
		$urls[] = 'https://vimeo.com/' . absint( $video_id );
	}

	preg_match_all(
		'#<(?:video|source)[^>]*\ssrc=["\']([^"\']+)["\']#i',
		$content,
		$html_videos
	);
	foreach ( $html_videos[1] as $src ) {
		$urls[] = esc_url_raw( $src );
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

/**
 * Back-compat alias used by the video sitemap.
 *
 * @return array<int,string>
 */
function erankly_extract_sitemap_video_urls( string $content ): array {
	return erankly_extract_video_urls( $content );
}

/**
 * @param string $video_url Video URL (page, short, or embed form).
 * @return string Embed URL, or empty string if unsupported.
 */
function erankly_get_video_embed_url( string $video_url ): string {
	if ( preg_match( '#youtube(?:-nocookie)?\.com/embed/([a-zA-Z0-9_-]{11})#', $video_url, $m ) ) {
		return 'https://www.youtube.com/embed/' . $m[1];
	}

	if ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $video_url, $m ) ) {
		return 'https://player.vimeo.com/video/' . absint( $m[1] );
	}

	if ( preg_match( '#(?:youtube\.com/watch\S*[?&]v=|youtu\.be/)([a-zA-Z0-9_-]{11})#', $video_url, $m ) ) {
		return 'https://www.youtube.com/embed/' . $m[1];
	}

	if ( preg_match( '#vimeo\.com/(\d+)#', $video_url, $m ) ) {
		return 'https://player.vimeo.com/video/' . absint( $m[1] );
	}

	return '';
}

/** Back-compat alias used by the video sitemap. */
function erankly_get_sitemap_video_embed_url( string $video_url ): string {
	return erankly_get_video_embed_url( $video_url );
}

/** @return string Content URL, or empty string if unsupported. */
function erankly_get_video_content_url( string $video_url ): string {
	$path = wp_parse_url( $video_url, PHP_URL_PATH );

	if ( is_string( $path ) && preg_match( '#\.(mp4|webm|m4v|mov|ogg)$#i', $path ) ) {
		return $video_url;
	}

	return '';
}

/** Back-compat alias used by the video sitemap. */
function erankly_get_sitemap_video_content_url( string $video_url ): string {
	return erankly_get_video_content_url( $video_url );
}

/** @return string Thumbnail URL, or empty string. */
function erankly_get_video_thumbnail_url( int $post_id, string $video_url ): string {
	$featured_id = (int) get_post_thumbnail_id( $post_id );

	if ( $featured_id > 0 ) {
		$url = erankly_get_image_url( $featured_id, 'full' );

		if ( '' !== $url ) {
			return $url;
		}
	}

	if ( preg_match( '#(?:youtube\.com/watch\S*[?&]v=|youtu\.be/)([a-zA-Z0-9_-]{11})#', $video_url, $m ) ) {
		return 'https://img.youtube.com/vi/' . $m[1] . '/0.jpg';
	}

	return '';
}

/** Back-compat alias used by the video sitemap. */
function erankly_get_sitemap_video_thumbnail_url( int $post_id, string $video_url ): string {
	return erankly_get_video_thumbnail_url( $post_id, $video_url );
}
