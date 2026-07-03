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
 * Returns a language-aware URL where multilingual plugins expose one.
 *
 * @param string $url URL.
 * @return string
 */
function erankly_localize_url( string $url ): string {
	if ( function_exists( 'pll_home_url' ) && home_url( '/' ) === $url ) {
		$pll_url = pll_home_url();

		if ( is_string( $pll_url ) && '' !== $pll_url ) {
			return $pll_url;
		}
	}

	/**
	 * Allows WPML, Polylang, or custom stacks to localize SEO URLs.
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

	$price = $product->get_price();

	if ( '' !== $price ) {
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

	return array_filter( $data );
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
