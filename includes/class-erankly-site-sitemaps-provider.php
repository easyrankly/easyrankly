<?php
/** Site-level sitemap provider for public CPT archives and opt-in extra URLs. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ERankly_Site_Sitemaps_Provider extends WP_Sitemaps_Provider {

	/** @var array<int,array<string,string>>|null */
	private ?array $entries = null;

	public function __construct() {
		$this->name        = 'erankly-site';
		$this->object_type = 'site';
	}

	/** @return array<int,array<string,string>> */
	public function get_url_list( $page_num, $object_subtype = '' ): array {
		unset( $object_subtype );

		$page_num = max( 1, absint( $page_num ) );

		return array_slice(
			$this->get_entries(),
			( $page_num - 1 ) * ERANKLY_SITEMAP_PER_PAGE,
			ERANKLY_SITEMAP_PER_PAGE
		);
	}

	public function get_max_num_pages( $object_subtype = '' ): int {
		unset( $object_subtype );

		return (int) ceil( count( $this->get_entries() ) / ERANKLY_SITEMAP_PER_PAGE );
	}

	/** @return array<int,array<string,string>> */
	private function get_entries(): array {
		if ( null !== $this->entries ) {
			return $this->entries;
		}

		$entries    = array();
		$post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		/** Filters CPT objects considered for archive URLs. */
		$post_types = apply_filters( 'erankly_sitemap_archive_post_types', $post_types );
		$post_types = is_array( $post_types ) ? $post_types : array();

		foreach ( $post_types as $post_type ) {
			if ( ! $post_type instanceof WP_Post_Type || ! $post_type->has_archive || ! is_post_type_viewable( $post_type ) ) {
				continue;
			}

			if (
				erankly_get_global_post_type_directive( $post_type->name, 'noindex' )
				|| erankly_get_global_post_type_directive( $post_type->name, 'disable_sitemap' )
				|| ! $this->archive_has_eligible_content( $post_type->name )
			) {
				continue;
			}

			$archive_url = get_post_type_archive_link( $post_type->name );
			if ( ! is_string( $archive_url ) || '' === $archive_url ) {
				continue;
			}

			// WooCommerce and similar plugins can map an archive to a real Page.
			// If that Page is already eligible for the core sitemap, avoid emitting
			// the same URL twice in two providers.
			$resolved_post_id = url_to_postid( $archive_url );
			if ( $resolved_post_id > 0 && erankly_is_post_sitemap_eligible( $resolved_post_id, array( 'page' ) ) ) {
				continue;
			}

			$entry = apply_filters(
				'erankly_sitemap_post_type_archive_entry',
				array( 'loc' => $archive_url ),
				$post_type
			);

			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		/**
		 * Filters site-level sitemap URLs. Integrations may add landing pages that
		 * WordPress does not store as posts. Each entry needs `loc`; `lastmod` is
		 * optional. Relative URLs are resolved against the site home URL.
		 *
		 * @param array<int,array<string,string>> $entries Site-level URL entries.
		 */
		$entries = apply_filters( 'erankly_sitemap_site_urls', $entries );
		$entries = is_array( $entries ) ? $entries : array();

		$this->entries = $this->sanitize_entries( $entries );

		return $this->entries;
	}

	private function archive_has_eligible_content( string $post_type ): bool {
		$args                   = erankly_get_core_sitemap_posts_query_args( $post_type );
		$args['fields']         = 'ids';
		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = true;
		$query                  = new WP_Query( $args );

		return ! empty( $query->posts );
	}

	/**
	 * Keeps only unique HTTP(S) URLs from this WordPress site's origin.
	 *
	 * @param array<int,mixed> $entries Untrusted filter output.
	 * @return array<int,array<string,string>>
	 */
	private function sanitize_entries( array $entries ): array {
		$home_parts = wp_parse_url( home_url( '/' ) );
		$home_origin = is_array( $home_parts )
			? strtolower( (string) ( $home_parts['scheme'] ?? '' ) ) . '://'
				. strtolower( (string) ( $home_parts['host'] ?? '' ) )
				. ( isset( $home_parts['port'] ) ? ':' . absint( $home_parts['port'] ) : '' )
			: '';
		$clean = array();

		foreach ( $entries as $entry ) {
			if ( is_string( $entry ) ) {
				$entry = array( 'loc' => $entry );
			}
			if ( ! is_array( $entry ) || empty( $entry['loc'] ) ) {
				continue;
			}

			$loc        = erankly_absolutize_content_url( (string) $entry['loc'], home_url( '/' ) );
			$normalized = erankly_normalize_canonical_comparison_url( $loc );
			if ( '' === $normalized || ! str_starts_with( $normalized, $home_origin . '/' ) ) {
				continue;
			}

			$item = array( 'loc' => $loc );
			if ( ! empty( $entry['lastmod'] ) && false !== strtotime( (string) $entry['lastmod'] ) ) {
				$item['lastmod'] = wp_date( DATE_W3C, strtotime( (string) $entry['lastmod'] ) );
			}

			$clean[ $normalized ] = $item;
		}

		return array_values( $clean );
	}
}
