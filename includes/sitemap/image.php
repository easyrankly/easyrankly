<?php
/**
 * XML sitemap generation: Image sitemap. Loaded only when the image feature is enabled (see
 * erankly_bootstrap()), so these functions are parsed only on sites that use this sitemap type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the exact, cached list of eligible posts that produce at least one
 * image entry. The exact list keeps index pagination and rendered pages in
 * sync; the cold scan is paid once per sitemap cache generation.
 *
 * @return array<int,int>
 */
function erankly_get_image_sitemap_post_ids(): array {
	$cache_key = erankly_get_sitemap_cache_key( 'image_post_ids' );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return array_values( array_filter( array_map( 'absint', $cached ) ) );
	}

	global $wpdb;

	$post_types = array_keys( erankly_get_sitemap_post_types() );

	if ( empty( $post_types ) ) {
		set_transient( $cache_key, array(), HOUR_IN_SECONDS );
		return array();
	}

	$placeholders    = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$like_img        = '%' . $wpdb->esc_like( '<img' ) . '%';
	$like_wp_image   = '%' . $wpdb->esc_like( 'wp:image' ) . '%';
	$like_wp_gallery = '%' . $wpdb->esc_like( 'wp:gallery' ) . '%';
	$like_wp_cover      = '%' . $wpdb->esc_like( 'wp:cover' ) . '%';
	$like_wp_media_text = '%' . $wpdb->esc_like( 'wp:media-text' ) . '%';
	$like_gallery       = '%' . $wpdb->esc_like( '[gallery' ) . '%';

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
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_thumb
					WHERE pm_thumb.post_id = p.ID
						AND pm_thumb.meta_key = '_thumbnail_id'
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_gallery
					WHERE pm_gallery.post_id = p.ID
						AND pm_gallery.meta_key = '_product_image_gallery'
						AND pm_gallery.meta_value != ''
				)
			)
	" . erankly_get_sitemap_exclusion_sql( 'p', $post_types ) . '
		ORDER BY p.post_modified_gmt DESC, p.ID DESC
	';

	$args = array_merge(
		$post_types,
		array( $like_img, $like_wp_image, $like_wp_gallery, $like_wp_cover, $like_wp_media_text, $like_gallery )
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$ids          = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The candidate query is prepared immediately above; table names and the exclusion clause are internal constants.
		$prepared_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
	);
	$ids          = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

	/**
	 * Filters candidate post IDs before exact image discovery. Integrations for
	 * ACF and page builders can add their post IDs here, then add actual image
	 * URLs with the existing `erankly_sitemap_images` filter.
	 */
	$ids = apply_filters( 'erankly_image_sitemap_candidate_post_ids', $ids, $post_types );
	$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
	$ids = array_values(
		array_filter(
			$ids,
			static fn( int $post_id ): bool => erankly_is_post_sitemap_eligible( $post_id, $post_types )
				&& ! empty( erankly_get_image_sitemap_entries_for_post( $post_id ) )
		)
	);

	set_transient( $cache_key, $ids, HOUR_IN_SECONDS );

	return $ids;
}

/** Counts exact URL entries for image sitemap pagination. */
function erankly_count_image_sitemap_items(): int {
	return count( erankly_get_image_sitemap_post_ids() );
}

/** @return array<int,string> Final filtered image URLs for one public page. */
function erankly_get_image_sitemap_entries_for_post( int $post_id ): array {
	$loc     = (string) get_permalink( $post_id );
	$entries = array();

	foreach ( erankly_get_sitemap_images( $post_id ) as $image_url ) {
		/** Filters an individual image sitemap entry. Return an empty array to exclude the image. */
		$entry = apply_filters(
			'erankly_image_sitemap_url',
			array(
				'loc'       => $loc,
				'image_loc' => $image_url,
			),
			$post_id
		);

		if ( ! is_array( $entry ) || empty( $entry['image_loc'] ) ) {
			continue;
		}

		$image_url = erankly_absolutize_content_url( (string) $entry['image_loc'], $loc );
		if ( erankly_is_absolute_http_url( $image_url ) ) {
			$entries[] = $image_url;
		}
	}

	// Google accepts at most 1,000 image entries for a single page URL.
	return array_slice( array_values( array_unique( $entries ) ), 0, 1000 );
}

/**
 * Returns the image sitemap XML for the given page. Associates images with the public pages that contain them.
 * Uses each post's own permalink as <loc> (NOT the attachment page). Includes only images that are genuinely
 * attached to or embedded in a publicly viewable, non-excluded page. Does not emit the Google-deprecated
 * <image:title> element. Follows the Google Image Sitemap spec:
 * https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps
 *
 * @return string XML string, or empty string when disabled or no image posts found.
 */
function erankly_get_image_sitemap_xml( int $page = 1 ): string {
	if ( ! (bool) erankly_get_setting( 'enable_image_sitemap', 0 ) ) {
		return '';
	}

	$page      = max( 1, $page );
	$cache_key = erankly_get_sitemap_cache_key( 'image_' . $page );
	$cached    = get_transient( $cache_key );

	if ( is_string( $cached ) ) {
		return $cached;
	}

	$post_ids = array_slice( erankly_get_image_sitemap_post_ids(), ( $page - 1 ) * ERANKLY_SITEMAP_PER_PAGE, ERANKLY_SITEMAP_PER_PAGE );

	if ( empty( $post_ids ) ) {
		return '';
	}

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( erankly_get_sitemap_stylesheet_url() ) . '"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
	$xml .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

	$has_entries = false;

	foreach ( $post_ids as $post_id ) {
		$images = erankly_get_image_sitemap_entries_for_post( $post_id );

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
			$image_nodes .= "\t\t<image:image>\n";
			$image_nodes .= "\t\t\t<image:loc>" . esc_xml( $image_url ) . "</image:loc>\n";
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
