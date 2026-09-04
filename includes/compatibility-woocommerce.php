<?php
/** WooCommerce Product schema compatibility. Loaded by the public compatibility wrapper only when WooCommerce is active. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string,mixed> */
function erankly_build_woocommerce_product_data( int $post_id ): array {
	if ( 'product' !== get_post_type( $post_id ) || ! erankly_should_render_woocommerce_product_schema( $post_id ) ) {
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

/** @return array<string,mixed> */
function erankly_get_woocommerce_simple_offer( WC_Product $product, string $permalink ): array {
	$price = $product->get_price();
	if ( '' === $price ) {
		return array();
	}

	$offer    = array(
		'@type'         => 'Offer',
		'price'         => $price,
		'priceCurrency' => get_woocommerce_currency(),
		'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		'url'           => $permalink,
	);
	$sale_end = $product->get_date_on_sale_to();
	if ( $product->is_on_sale() && $sale_end instanceof WC_DateTime ) {
		$offer['priceValidUntil'] = $sale_end->date( 'Y-m-d' );
	}

	return $offer;
}

/** @return array<string,mixed> */
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

/** @return array<int,array<string,mixed>> */
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

function erankly_get_woocommerce_product_brand( int $post_id ): string {
	foreach ( array( 'product_brand', 'pa_brand', 'pwb-brand' ) as $taxonomy ) {
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
