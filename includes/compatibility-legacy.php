<?php
/**
 * Legacy developer API aliases.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Returns legacy hook names mapped to their canonical hooks. */
function erankly_legacy_developer_api_hook_aliases(): array {
	return array(
		'easyrankly_breadcrumb_items'                    => 'erankly_breadcrumb_items',
		'easyrankly_breadcrumbs_html'                    => 'erankly_breadcrumbs_html',
		'easyrankly_canonical'                           => 'erankly_canonical',
		'easyrankly_description'                         => 'erankly_description',
		'easyrankly_enable_head_output'                  => 'erankly_enable_head_output',
		'easyrankly_enable_robots_txt_with_external_seo' => 'erankly_enable_robots_txt_with_external_seo',
		'easyrankly_enable_sitemaps_with_external_seo'   => 'erankly_enable_sitemaps_with_external_seo',
		'easyrankly_faq_items'                           => 'erankly_faq_items',
		'easyrankly_hreflang_alternates'                 => 'erankly_hreflang_alternates',
		'easyrankly_image_sitemap_url'                   => 'erankly_image_sitemap_url',
		'easyrankly_include_user_sitemap'                => 'erankly_include_user_sitemap',
		'easyrankly_local_business_types'                => 'erankly_local_business_types',
		'easyrankly_localized_url'                       => 'erankly_localized_url',
		'easyrankly_news_sitemap_post_types'             => 'erankly_news_sitemap_post_types',
		'easyrankly_news_sitemap_publication_language'   => 'erankly_news_sitemap_publication_language',
		'easyrankly_news_sitemap_publication_name'       => 'erankly_news_sitemap_publication_name',
		'easyrankly_news_sitemap_url'                    => 'erankly_news_sitemap_url',
		'easyrankly_og_description'                      => 'erankly_og_description',
		'easyrankly_og_image'                            => 'erankly_og_image',
		'easyrankly_og_title'                            => 'erankly_og_title',
		'easyrankly_opengraph_tags'                      => 'erankly_opengraph_tags',
		'easyrankly_organization_schema_details'         => 'erankly_organization_schema_details',
		'easyrankly_post_breadcrumb_name'                => 'erankly_post_breadcrumb_name',
		'easyrankly_post_types'                          => 'erankly_post_types',
		'easyrankly_redirect_hit_sample_rate'            => 'erankly_redirect_hit_sample_rate',
		'easyrankly_render_woocommerce_product_schema'   => 'erankly_render_woocommerce_product_schema',
		'easyrankly_robots'                              => 'erankly_robots',
		'easyrankly_robots_txt_lines'                    => 'erankly_robots_txt_lines',
		'easyrankly_schema'                              => 'erankly_schema',
		'easyrankly_schema_article'                      => 'erankly_schema_article',
		'easyrankly_schema_blogposting'                  => 'erankly_schema_blogposting',
		'easyrankly_schema_breadcrumb_list'              => 'erankly_schema_breadcrumb_list',
		'easyrankly_schema_faq'                          => 'erankly_schema_faq',
		'easyrankly_schema_howto'                        => 'erankly_schema_howto',
		'easyrankly_schema_event'                        => 'erankly_schema_event',
		'easyrankly_schema_video_object'                 => 'erankly_schema_video_object',
		'easyrankly_schema_video_objects'                => 'erankly_schema_video_objects',
		'easyrankly_schema_service_args'                 => 'erankly_schema_service_args',
		'easyrankly_event_post_types'                    => 'erankly_event_post_types',
		'easyrankly_schema_local_business'               => 'erankly_schema_local_business',
		'easyrankly_schema_localbusiness'                => 'erankly_schema_localbusiness',
		'easyrankly_schema_organization'                 => 'erankly_schema_organization',
		'easyrankly_schema_person'                       => 'erankly_schema_person',
		'easyrankly_schema_service'                      => 'erankly_schema_service',
		'easyrankly_schema_webpage'                      => 'erankly_schema_webpage',
		'easyrankly_schema_website'                      => 'erankly_schema_website',
		'easyrankly_sitemap_images'                      => 'erankly_sitemap_images',
		'easyrankly_sitemap_post_types'                  => 'erankly_sitemap_post_types',
		'easyrankly_special_pages'                       => 'erankly_special_pages',
		'easyrankly_taxonomies'                          => 'erankly_taxonomies',
		'easyrankly_title'                               => 'erankly_title',
		'easyrankly_twitter_card_type'                   => 'erankly_twitter_card_type',
		'easyrankly_twitter_description'                 => 'erankly_twitter_description',
		'easyrankly_twitter_image'                       => 'erankly_twitter_image',
		'easyrankly_twitter_site'                        => 'erankly_twitter_site',
		'easyrankly_twitter_title'                       => 'erankly_twitter_title',
		'easyrankly_video_sitemap_url'                   => 'erankly_video_sitemap_url',
		'easyrankly_woocommerce_structured_data_enabled' => 'erankly_woocommerce_structured_data_enabled',
	);
}

/** Registers legacy hooks after their canonical equivalents. */
function erankly_register_legacy_developer_api_hook_aliases(): void {
	foreach ( erankly_legacy_developer_api_hook_aliases() as $legacy_hook => $canonical_hook ) {
		add_filter(
			$canonical_hook,
			static function ( mixed $value, mixed ...$args ) use ( $legacy_hook ): mixed {
				if ( ! has_filter( $legacy_hook ) ) {
					return $value;
				}

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Declared legacy compatibility surface.
				return apply_filters_ref_array( $legacy_hook, array_merge( array( $value ), $args ) );
			},
			999,
			99
		);
	}
}

erankly_register_legacy_developer_api_hook_aliases();
