<?php
/**
 * Compatibility guards.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns legacy hook aliases published before the erankly_* developer API was
 * documented consistently.
 *
 * @return array<string,string> Map of legacy hook name => canonical hook name.
 */
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
		'easyrankly_health_404_sample_rate'              => 'erankly_health_404_sample_rate',
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

/**
 * Registers legacy easyrankly_* filter aliases against the canonical erankly_*
 * hooks. The canonical filter runs first; legacy callbacks then receive the
 * current value and the original hook arguments.
 *
 * @return void
 */
function erankly_register_legacy_developer_api_hook_aliases(): void {
	foreach ( erankly_legacy_developer_api_hook_aliases() as $legacy_hook => $canonical_hook ) {
		add_filter(
			$canonical_hook,
			static function ( mixed $value, mixed ...$args ) use ( $legacy_hook ): mixed {
				if ( ! has_filter( $legacy_hook ) ) {
					return $value;
				}

				// Legacy hook aliases are declared in erankly_legacy_developer_api_hook_aliases().
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
				return apply_filters_ref_array( $legacy_hook, array_merge( array( $value ), $args ) );
			},
			999,
			99
		);
	}
}

erankly_register_legacy_developer_api_hook_aliases();

/**
 * Detects SEO plugins that normally own head output.
 *
 * @return bool
 */
function erankly_detect_external_seo_head_owner(): bool {
	static $result = null;

	if ( null !== $result ) {
		return $result;
	}

	$known_constants = array(
		'WPSEO_VERSION',
		'RANK_MATH_VERSION',
		'AIOSEO_VERSION',
		'SEOPRESS_VERSION',
		'THE_SEO_FRAMEWORK_VERSION',
		'SLIM_SEO_VERSION',
	);

	foreach ( $known_constants as $constant ) {
		if ( defined( $constant ) ) {
			$result = true;
			return $result;
		}
	}

	$result = false;

	return $result;
}

/**
 * Determines whether EasyRankly should render frontend head output.
 *
 * @return bool
 */
function erankly_should_output_head(): bool {
	$should_output = erankly_is_frontend_html_request() && ! erankly_detect_external_seo_head_owner();

	/**
	 * Filters whether EasyRankly renders head metadata.
	 *
	 * @param bool $should_output True to render metadata.
	 */
	return (bool) apply_filters( 'erankly_enable_head_output', $should_output );
}

/**
 * Returns a language-aware URL where a custom stack exposes one.
 *
 * @param string $url URL.
 * @return string
 */
function erankly_localize_url( string $url ): string {
	/**
	 * Allows a custom multilingual stack to localize SEO URLs.
	 *
	 * @param string $url URL.
	 */
	return (string) apply_filters( 'erankly_localized_url', $url );
}

/**
 * Detects whether WooCommerce APIs are available.
 *
 * @return bool
 */
function erankly_is_woocommerce_active(): bool {
	return function_exists( 'wc_get_product' );
}

/**
 * Determines whether WooCommerce is expected to output Product structured data.
 *
 * @return bool
 */
function erankly_woocommerce_structured_data_enabled(): bool {
	$enabled = erankly_is_woocommerce_active() && class_exists( 'WC_Structured_Data' );

	/**
	 * Filters whether WooCommerce owns Product structured data output.
	 *
	 * @param bool $enabled True when WooCommerce Product JSON-LD should be treated as active.
	 */
	return (bool) apply_filters( 'erankly_woocommerce_structured_data_enabled', $enabled );
}

/**
 * Determines whether EasyRankly should output automatic WooCommerce Product schema.
 *
 * @param int $post_id Product post ID.
 * @return bool
 */
function erankly_should_render_woocommerce_product_schema( int $post_id ): bool {
	$should_render = ! erankly_woocommerce_structured_data_enabled();

	/**
	 * Filters whether EasyRankly outputs automatic Product schema for WooCommerce products.
	 *
	 * By default EasyRankly defers to WooCommerce structured data to avoid duplicate Product JSON-LD.
	 *
	 * @param bool $should_render True to output EasyRankly Product schema.
	 * @param int  $post_id       Product post ID.
	 */
	return (bool) apply_filters( 'erankly_render_woocommerce_product_schema', $should_render, $post_id );
}

/**
 * Determines whether EasyRankly's sitemaps should be suppressed.
 *
 * When a known SEO plugin that ships its own sitemap system is active the
 * virtual video/news sitemaps served by EasyRankly must not run concurrently.
 * Site admins can override with the {@see 'erankly_enable_sitemaps_with_external_seo'} filter.
 *
 * @return bool True when EasyRankly should suppress its own sitemap output.
 */
function erankly_should_suppress_sitemaps(): bool {
	$suppress = erankly_detect_external_seo_head_owner();

	/**
	 * Filters whether EasyRankly suppresses its own sitemap output when an external SEO plugin is active.
	 *
	 * Return false to allow EasyRankly's video/news sitemaps to run alongside another SEO plugin.
	 *
	 * @param bool $suppress True to suppress EasyRankly sitemaps.
	 */
	return (bool) apply_filters( 'erankly_enable_sitemaps_with_external_seo', $suppress );
}

/**
 * Renders an admin notice when EasyRankly's head/sitemap output is disabled
 * because another SEO plugin is active.
 *
 * @return void
 */
function erankly_compatibility_notice_external_seo(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! erankly_detect_external_seo_head_owner() ) {
		return;
	}

	$screen = get_current_screen();

	// Limit to EasyRankly pages and the plugin list to avoid polluting all admin screens.
	if ( $screen instanceof WP_Screen ) {
		$show = str_contains( (string) $screen->id, 'erankly' )
			|| 'plugins' === $screen->base;

		if ( ! $show ) {
			return;
		}
	}

	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<?php
			esc_html_e(
				'EasyRankly: another SEO plugin is active. Head metadata (title, meta description, canonical, Open Graph, Schema.org) and sitemap output are disabled to avoid conflicts. Redirects, health monitor, and breadcrumbs continue to work.',
				'easyrankly'
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'erankly_compatibility_notice_external_seo' );

/**
 * Returns WooCommerce product schema additions when available.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>
 */
function erankly_get_woocommerce_product_data( int $post_id ): array {
	if ( 'product' !== get_post_type( $post_id ) || ! erankly_is_woocommerce_active() || ! erankly_should_render_woocommerce_product_schema( $post_id ) ) {
		return array();
	}

	$product = wc_get_product( $post_id );

	if ( ! $product ) {
		return array();
	}

	$permalink = (string) get_permalink( $post_id );
	$data      = array(
		'@type'       => 'Product',
		'@id'         => $permalink . '#product',
		'name'        => $product->get_name(),
		'description' => erankly_trim_text( '' !== $product->get_short_description() ? $product->get_short_description() : $product->get_description(), 500 ),
		'url'         => $permalink,
	);

	$image = erankly_get_og_image();

	if ( '' !== $image ) {
		$data['image'] = $image;
	}

	$sku = $product->get_sku();

	if ( '' !== $sku ) {
		$data['sku'] = $sku;
	}

	$brand = erankly_get_woocommerce_product_brand( $post_id );

	if ( '' !== $brand ) {
		$data['brand'] = array(
			'@type' => 'Brand',
			'name'  => $brand,
		);
	}

	// Native WooCommerce GTIN/UPC/EAN/ISBN field (WooCommerce 8.5+). Older
	// versions don't expose this method at all, so guard with method_exists().
	if ( method_exists( $product, 'get_global_unique_id' ) ) {
		$gtin = $product->get_global_unique_id();

		if ( '' !== $gtin ) {
			$data['gtin'] = $gtin;
		}
	}

	$offer = $product instanceof WC_Product_Variable
		? erankly_get_woocommerce_variable_offer( $product, $permalink )
		: erankly_get_woocommerce_simple_offer( $product, $permalink );

	if ( ! empty( $offer ) ) {
		$data['offers'] = $offer;
	}

	$rating = (float) $product->get_average_rating();
	$count  = (int) $product->get_rating_count();

	if ( $rating > 0 && $count > 0 ) {
		$data['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $rating,
			'reviewCount' => (string) $count,
		);
	}

	$reviews = erankly_get_woocommerce_product_reviews( $post_id );

	if ( ! empty( $reviews ) ) {
		$data['review'] = $reviews;
	}

	return array_filter( $data );
}

/**
 * Builds an Offer node for a simple (single-price) WooCommerce product.
 *
 * @param WC_Product $product   Product.
 * @param string     $permalink Product permalink.
 * @return array<string,mixed>
 */
function erankly_get_woocommerce_simple_offer( WC_Product $product, string $permalink ): array {
	$price = $product->get_price();

	if ( '' === $price ) {
		return array();
	}

	$offer = array(
		'@type'         => 'Offer',
		'price'         => $price,
		'priceCurrency' => get_woocommerce_currency(),
		'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		'url'           => $permalink,
	);

	// Only bound the price when a sale is actually active, so priceValidUntil
	// is always a future date that matches the sale price being advertised.
	$sale_end = $product->get_date_on_sale_to();

	if ( $product->is_on_sale() && $sale_end instanceof WC_DateTime ) {
		$offer['priceValidUntil'] = $sale_end->date( 'Y-m-d' );
	}

	return $offer;
}

/**
 * Builds an AggregateOffer node for a variable WooCommerce product, covering
 * the price range across its variations instead of a single parent price.
 *
 * @param WC_Product_Variable $product   Variable product.
 * @param string              $permalink Product permalink.
 * @return array<string,mixed>
 */
function erankly_get_woocommerce_variable_offer( WC_Product_Variable $product, string $permalink ): array {
	$prices = $product->get_variation_prices( false );

	if ( empty( $prices['price'] ) ) {
		return array();
	}

	$offer_count = count( $product->get_visible_children() );

	return array(
		'@type'         => 'AggregateOffer',
		'lowPrice'      => min( $prices['price'] ),
		'highPrice'     => max( $prices['price'] ),
		'priceCurrency' => get_woocommerce_currency(),
		'offerCount'    => $offer_count > 0 ? $offer_count : count( $prices['price'] ),
		'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		'url'           => $permalink,
	);
}

/**
 * Returns approved WooCommerce product reviews as schema.org Review nodes.
 *
 * @param int $post_id Product post ID.
 * @param int $limit   Maximum number of reviews to include.
 * @return array<int,array<string,mixed>>
 */
function erankly_get_woocommerce_product_reviews( int $post_id, int $limit = 10 ): array {
	$comments = get_comments(
		array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => $limit,
		)
	);

	if ( empty( $comments ) ) {
		return array();
	}

	$reviews = array();

	foreach ( $comments as $comment ) {
		$review = array(
			'@type'         => 'Review',
			'author'        => array(
				'@type' => 'Person',
				'name'  => '' !== $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'easyrankly' ),
			),
			'datePublished' => get_comment_date( 'Y-m-d', $comment ),
			'reviewBody'    => erankly_trim_text( $comment->comment_content, 1000 ),
		);

		$rating = get_comment_meta( $comment->comment_ID, 'rating', true );

		if ( is_numeric( $rating ) && (float) $rating > 0 ) {
			$review['reviewRating'] = array(
				'@type'       => 'Rating',
				'ratingValue' => (string) $rating,
				'bestRating'  => '5',
				'worstRating' => '1',
			);
		}

		$reviews[] = $review;
	}

	return $reviews;
}

/**
 * Returns a WooCommerce product brand from common brand taxonomies.
 *
 * @param int $post_id Product post ID.
 * @return string
 */
function erankly_get_woocommerce_product_brand( int $post_id ): string {
	$taxonomies = array( 'product_brand', 'pa_brand', 'pwb-brand' );

	foreach ( $taxonomies as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			continue;
		}

		$term = reset( $terms );

		if ( $term instanceof WP_Term && '' !== $term->name ) {
			return $term->name;
		}
	}

	return '';
}
