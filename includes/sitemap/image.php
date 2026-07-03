<?php
/**
 * XML sitemap generation — Image sitemap.
 *
 * Loaded only when the image feature is enabled (see erankly_bootstrap()),
 * so these functions are parsed only on sites that use this sitemap type.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		array( $like_img, $like_wp_image, $like_wp_gallery )
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$count        = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The aggregate query is prepared immediately above; table names and the exclusion clause are internal constants.
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
			ORDER BY p.post_modified_gmt DESC
			LIMIT %d OFFSET %d
	';

	$args = array_merge(
		$post_types,
		array( $like_img, $like_wp_image, $like_wp_gallery, ERANKLY_SITEMAP_PER_PAGE, $offset )
	);

	$prepared_sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated above and every value is bound here.
	$results      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The image query is prepared immediately above; table names and the exclusion clause are internal constants.
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
