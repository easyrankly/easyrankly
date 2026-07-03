<?php
/**
 * Specialist sitemaps provider.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects specialist sitemaps into the core wp-sitemap.xml index.
 */
final class ERankly_Specialist_Sitemaps_Provider extends WP_Sitemaps_Provider {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name = 'erankly';
	}

	/**
	 * Returns the URLs to include in the index.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_sitemap_entries(): array {
		$entries = array();

		if ( (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) && erankly_count_news_sitemap_posts() > 0 ) {
			$entries[] = array(
				'loc'     => $this->get_specialist_sitemap_url( 'news', 1 ),
				'lastmod' => erankly_get_news_sitemap_lastmod(),
			);
		}

		if ( (bool) erankly_get_setting( 'enable_image_sitemap', 0 ) ) {
			$image_count = erankly_count_image_sitemap_items();

			if ( $image_count > 0 ) {
				$pages = (int) ceil( $image_count / ERANKLY_SITEMAP_PER_PAGE );

				for ( $page = 1; $page <= $pages; $page++ ) {
					$entries[] = array(
						'loc' => $this->get_specialist_sitemap_url( 'image', $page ),
					);
				}
			}
		}

		if ( (bool) erankly_get_setting( 'enable_video_sitemap', 0 ) ) {
			$video_count = erankly_count_video_sitemap_posts();

			if ( $video_count > 0 ) {
				$pages = (int) ceil( $video_count / ERANKLY_SITEMAP_PER_PAGE );

				for ( $page = 1; $page <= $pages; $page++ ) {
					$entries[] = array(
						'loc' => $this->get_specialist_sitemap_url( 'video', $page ),
					);
				}
			}
		}

		return $entries;
	}

	/**
	 * Required by WP_Sitemaps_Provider but unused here because we render
	 * specialist XML formats directly via template_redirect.
	 *
	 * @param int    $page_num       Page number.
	 * @param string $object_subtype Subtype.
	 * @return array<int,array<string,string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ): array {
		return array();
	}

	/**
	 * Gets the max number of pages. Unused.
	 *
	 * @param string $object_subtype Subtype.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ): int {
		return 0;
	}

	/**
	 * Gets the URL for a specialist sitemap.
	 *
	 * @param string $type Sitemap type (e.g. image, video, news).
	 * @param int    $page Page number.
	 * @return string
	 */
	private function get_specialist_sitemap_url( string $type, int $page ): string {
		global $wp_rewrite;

		if ( ! $wp_rewrite->using_permalinks() ) {
			return home_url( '/?erankly_sitemap=' . $type . '&erankly_sitemap_page=' . $page );
		}

		return home_url( sprintf( '/sitemap-%s-%d.xml', $type, $page ) );
	}
}
