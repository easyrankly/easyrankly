<?php
/** Open Graph and Twitter card output. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Returns the automatic social title used for singular content in simplified mode. */
function erankly_get_simplified_social_title( int $post_id ): string {
	$title = erankly_get_post_meta_string( $post_id, 'title' );

	if ( '' !== $title ) {
		$title = erankly_replace_variables( $title, $post_id, array( 'seo_title' ) );
	} else {
		$title = get_the_title( $post_id );
	}

	return erankly_normalize_seo_text( $title );
}

/** Returns the automatic social description used for singular content in simplified mode. */
function erankly_get_simplified_social_description( int $post_id ): string {
	$description = erankly_get_post_meta_string( $post_id, 'description' );

	if ( '' !== $description ) {
		$description = erankly_replace_variables( $description, $post_id, array( 'meta_description' ) );
	} else {
		$post = get_post( $post_id );

		if ( $post instanceof WP_Post ) {
			$description = has_excerpt( $post ) ? get_the_excerpt( $post ) : excerpt_remove_blocks( $post->post_content );
		}
	}

	return erankly_normalize_seo_text( $description );
}

function erankly_render_opengraph_tags(): void {
	$title         = erankly_get_og_title();
	$description   = erankly_get_og_description();
	$url           = erankly_get_canonical();
	$image         = erankly_get_og_image();
	$image_details = erankly_get_social_image_attachment_data( $image );
	$image_alt     = erankly_get_social_image_alt( 'og', '', $image );
	$twitter_image = erankly_get_twitter_image( $image );
	$twitter_alt   = erankly_get_twitter_image_alt( $twitter_image, $image, $image_alt );
	$twitter_title = erankly_get_twitter_title( $title );
	$twitter_desc  = erankly_get_twitter_description( $description );
	$twitter_site  = erankly_get_twitter_site();
	$type          = is_singular( 'post' ) ? 'article' : 'website';

	if ( is_singular() && 'product' === get_post_type() ) {
		$type = 'product';
	}

	$tags = array(
		'og:locale'           => str_replace( '-', '_', get_bloginfo( 'language' ) ),
		'og:site_name'        => get_bloginfo( 'name' ),
		'og:type'             => $type,
		'og:title'            => $title,
		'og:description'      => $description,
		'og:url'              => $url,
		'og:image'            => $image,
		'og:image:width'      => ! empty( $image_details['width'] ) ? (string) $image_details['width'] : '',
		'og:image:height'     => ! empty( $image_details['height'] ) ? (string) $image_details['height'] : '',
		'og:image:alt'        => '' !== $image ? $image_alt : '',
		'twitter:card'        => erankly_get_twitter_card_type( '' !== $twitter_image ? $twitter_image : $image ),
		'twitter:site'        => $twitter_site,
		'twitter:title'       => $twitter_title,
		'twitter:description' => $twitter_desc,
		'twitter:image'       => $twitter_image,
		'twitter:image:alt'   => '' !== $twitter_image ? $twitter_alt : '',
	);

	/** @param array<string,string> $tags Tags keyed by property/name. */
	$tags = apply_filters( 'erankly_opengraph_tags', array_filter( $tags ) );

	foreach ( $tags as $property => $content ) {
		if ( '' === (string) $content ) {
			continue;
		}

		$attribute = str_starts_with( (string) $property, 'twitter:' ) ? 'name' : 'property';

		printf(
			'<meta %1$s="%2$s" content="%3$s">' . "\n",
			esc_attr( $attribute ),
			esc_attr( (string) $property ),
			esc_attr( (string) $content )
		);
	}
}

/**
 * Resolves a social title or description through the shared fallback chain. Order: explicit per-content meta,
 * simplified-mode automatic value, special-page template, global default template, then the caller-provided
 * fallback.
 *
 * @param string $meta_key    Per-content meta key (without plugin prefix).
 * @param string $setting_key Global default setting key.
 */
function erankly_resolve_social_text( string $meta_key, string $setting_key, string $fallback, int $limit ): string {
	$is_title = str_contains( $meta_key, 'title' );
	$value    = '';

	if ( is_singular() ) {
		$post_id = get_queried_object_id();
		$value   = erankly_get_post_meta_string( $post_id, $meta_key );

		if ( '' !== $value ) {
			$value = erankly_replace_variables( $value, $post_id );
		}

		if ( '' === $value && (bool) erankly_get_setting( 'simplified_mode', 1 ) ) {
			$value = $is_title
				? erankly_get_simplified_social_title( $post_id )
				: erankly_get_simplified_social_description( $post_id );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$value = erankly_get_term_meta_string( $term->term_id, $meta_key );

			if ( '' !== $value ) {
				$value = erankly_replace_variables( $value );
			}
		}
	} elseif ( is_author() ) {
		$value = trim( (string) get_user_meta( (int) get_queried_object_id(), '_erankly_' . $meta_key, true ) );

		if ( '' !== $value ) {
			$value = erankly_replace_variables( $value );
		}
	}

	if ( '' === $value ) {
		$special_key = erankly_current_special_page_key();

		if ( '' !== $special_key ) {
			// Prefer the special page's own social field; fall back to its SEO
			// title/description so an unset social value still has a sensible value.
			$value = erankly_get_global_entity_meta( 'global_special_meta', $special_key, $meta_key );

			if ( '' === $value ) {
				$value = erankly_get_global_entity_meta( 'global_special_meta', $special_key, $is_title ? 'title' : 'description' );
			}

			if ( '' !== $value ) {
				$value = erankly_replace_variables( $value, 0, array( $is_title ? 'seo_title' : 'meta_description' ) );
			}
		}
	}

	if ( '' === $value ) {
		$value = (string) erankly_get_setting( $setting_key, '' );

		if ( '' !== $value ) {
			$value = erankly_replace_variables( $value );
		}
	}

	if ( '' === $value ) {
		$value = $fallback;
	}

	return erankly_trim_text( $value, $limit );
}

function erankly_get_og_title(): string {
	$title = erankly_resolve_social_text( 'og_title', 'default_og_title', erankly_get_title(), 60 );

	/** @param string $title Computed Open Graph title. */
	return (string) apply_filters( 'erankly_og_title', $title );
}

function erankly_get_og_description(): string {
	$description = erankly_resolve_social_text( 'og_description', 'default_og_description', erankly_get_description(), 200 );

	/** @param string $description Computed Open Graph description. */
	return (string) apply_filters( 'erankly_og_description', $description );
}

function erankly_get_twitter_title( string $fallback = '' ): string {
	$title = erankly_resolve_social_text( 'twitter_title', 'default_twitter_title', $fallback, 70 );

	return (string) apply_filters( 'erankly_twitter_title', $title );
}

function erankly_get_twitter_description( string $fallback = '' ): string {
	$description = erankly_resolve_social_text( 'twitter_description', 'default_twitter_description', $fallback, 200 );

	return (string) apply_filters( 'erankly_twitter_description', $description );
}

/**
 * Resolves the X card type for the current request.
 *
 * @param string $image Image X will actually show (its own, or the Open Graph one it falls back to).
 */
function erankly_get_twitter_card_type( string $image = '' ): string {
	$card_type = '';

	if ( is_singular() ) {
		$card_type = erankly_get_post_meta_string( get_queried_object_id(), 'twitter_card_type' );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$card_type = erankly_get_term_meta_string( $term->term_id, 'twitter_card_type' );
		}
	} elseif ( is_author() ) {
		$card_type = trim( (string) get_user_meta( (int) get_queried_object_id(), '_erankly_twitter_card_type', true ) );
	}

	if ( ! in_array( $card_type, array( 'summary', 'summary_large_image' ), true ) ) {
		$card_type = 'summary_large_image';
	}

	// summary_large_image declares a card X cannot build without a picture, and the default applied to every
	// URL: a term with no image announced a large card and shipped no image with it. Degrade to the card that
	// actually matches what is being sent.
	if ( '' === $image ) {
		$card_type = 'summary';
	}

	/** @param string $image Image X will actually show, empty when there is none. */
	return (string) apply_filters( 'erankly_twitter_card_type', $card_type, $image );
}

function erankly_get_twitter_site(): string {
	$site = trim( (string) erankly_get_setting( 'twitter_site', '' ) );

	if ( '' !== $site && '@' !== $site[0] ) {
		$site = '@' . $site;
	}

	return (string) apply_filters( 'erankly_twitter_site', $site );
}

function erankly_get_twitter_image( string $fallback = '' ): string {
	$image = '';

	if ( is_singular() ) {
		$post_id   = get_queried_object_id();
		erankly_migrate_legacy_social_image_for_object( 'post', $post_id );
		$image     = erankly_get_post_meta_string( $post_id, 'twitter_image_url' );
		$custom_id = absint( get_post_meta( $post_id, '_erankly_twitter_image_id', true ) );

		if ( '' !== $image ) {
			$image = esc_url_raw( erankly_replace_variables( $image, $post_id ) );
		}

		if ( '' === $image && $custom_id > 0 ) {
			$image = erankly_get_image_url( $custom_id, 'full' );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			erankly_migrate_legacy_social_image_for_object( 'term', $term->term_id );
			$image = erankly_get_term_meta_string( $term->term_id, 'twitter_image_url' );

			if ( '' !== $image ) {
				$image = esc_url_raw( erankly_replace_variables( $image ) );
			}
		}
	} elseif ( is_author() ) {
		$user_id = (int) get_queried_object_id();
		$image   = trim( (string) get_user_meta( $user_id, '_erankly_twitter_image_url', true ) );

		if ( '' !== $image ) {
			$image = esc_url_raw( erankly_replace_variables( $image ) );
		}
	} elseif ( '' !== erankly_current_special_page_key() ) {
		$image = erankly_get_special_page_social_image();
	}

	if ( '' === $image ) {
		$image = $fallback;
	}

	return (string) apply_filters( 'erankly_twitter_image', $image );
}

/** Renders the oEmbed JSON discovery link. Always active on every public page. */
function erankly_render_oembed_link(): void {
	// Suppress the native WP discovery (runs at priority 10, JSON + XML) so
	// that our single JSON-only link does not appear twice on singular pages.
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

	$canonical = erankly_get_canonical();

	if ( '' === $canonical ) {
		return;
	}

	printf(
		'<link rel="alternate" type="application/json+oembed" href="%s">' . "\n",
		esc_url( get_oembed_endpoint_url( $canonical, 'json' ) )
	);
}

/**
 * Returns the social sharing image configured for the current special page. Resolves the special-page social
 * image URL, then the picked media library attachment, or an empty string when none is set.
 */
function erankly_get_special_page_social_image(): string {
	$special_key = erankly_current_special_page_key();

	if ( '' === $special_key ) {
		return '';
	}

	$image = erankly_get_global_entity_meta( 'global_special_meta', $special_key, 'social_image_url' );

	if ( '' !== $image ) {
		return esc_url_raw( erankly_replace_variables( $image ) );
	}

	$map       = erankly_get_global_entity_meta_map( 'global_special_meta' );
	$custom_id = isset( $map[ $special_key ]['og_image_id'] ) ? absint( $map[ $special_key ]['og_image_id'] ) : 0;

	return $custom_id > 0 ? erankly_get_image_url( $custom_id, 'full' ) : '';
}

function erankly_get_og_image(): string {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$image = '';

	if ( is_singular() ) {
		$post_id     = get_queried_object_id();
		erankly_migrate_legacy_social_image_for_object( 'post', $post_id );
		$custom_id   = absint( get_post_meta( $post_id, '_erankly_og_image_id', true ) );
		$featured_id = get_post_thumbnail_id( $post_id );
		$image       = erankly_get_post_meta_string( $post_id, 'og_image_url' );

		if ( '' !== $image ) {
			$image = esc_url_raw( erankly_replace_variables( $image, $post_id ) );
		}

		if ( '' === $image && $custom_id > 0 ) {
			$image = erankly_get_image_url( $custom_id, 'full' );
		}

		if ( '' === $image && $featured_id > 0 ) {
			$image = erankly_get_image_url( (int) $featured_id, 'full' );
		}

		if ( '' === $image ) {
			$content_images = erankly_get_post_content_image_urls( $post_id );
			$image          = $content_images[0] ?? '';
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			erankly_migrate_legacy_social_image_for_object( 'term', $term->term_id );
			$image = erankly_get_term_meta_string( $term->term_id, 'og_image_url' );

			if ( '' !== $image ) {
				$image = esc_url_raw( erankly_replace_variables( $image ) );
			}
		}
	} elseif ( is_author() ) {
		$image = trim( (string) get_user_meta( (int) get_queried_object_id(), '_erankly_og_image_url', true ) );

		if ( '' !== $image ) {
			$image = esc_url_raw( erankly_replace_variables( $image ) );
		}
	} elseif ( '' !== erankly_current_special_page_key() ) {
		$image = erankly_get_special_page_social_image();
	}

	if ( '' === $image ) {
		$image = esc_url_raw( erankly_replace_variables( (string) erankly_get_setting( 'default_social_image_url', '' ) ) );
	}

	if ( '' === $image ) {
		$image = erankly_get_image_url( absint( erankly_get_setting( 'default_og_image', 0 ) ), 'full' );
	}

	if ( '' === $image ) {
		$image = erankly_get_organization_logo_url();
	}

	$resolved = (string) apply_filters( 'erankly_og_image', $image );

	return $resolved;
}

/** Resolves a Media Library attachment from full, resized, scaled or CDN-rewritten URLs. */
function erankly_get_social_image_attachment_id( string $image ): int {
	static $ids = array();

	$image = trim( $image );
	if ( '' === $image || str_contains( $image, '{{' ) || ! function_exists( 'attachment_url_to_postid' ) ) {
		return 0;
	}

	if ( array_key_exists( $image, $ids ) ) {
		return $ids[ $image ];
	}

	$attachment_id = attachment_url_to_postid( $image );
	if ( $attachment_id > 0 ) {
		$ids[ $image ] = $attachment_id;
		return $attachment_id;
	}

	$uploads    = wp_get_upload_dir();
	$base_url   = isset( $uploads['baseurl'] ) ? trim( (string) $uploads['baseurl'] ) : '';
	$base_path  = rtrim( (string) wp_parse_url( $base_url, PHP_URL_PATH ), '/' ) . '/';
	$image_path = rawurldecode( (string) wp_parse_url( $image, PHP_URL_PATH ) );
	$candidates = array();

	// The URL path remains stable for the common CDN case where only the host is rewritten.
	if ( '/' !== $base_path && str_starts_with( $image_path, $base_path ) ) {
		$candidates[] = ltrim( substr( $image_path, strlen( $base_path ) ), '/' );
	}

	// A year/month + filename candidate disambiguates same-named uploads across
	// different monthly folders, which a bare basename LIKE match cannot.
	if ( preg_match( '#(\d{4}/\d{2}/[^/]+)$#', $image_path, $date_match ) ) {
		$candidates[] = $date_match[1];
	}

	$basename      = wp_basename( $image_path );
	$candidates[]  = $basename;
	$stem          = pathinfo( $basename, PATHINFO_FILENAME );
	$extension     = pathinfo( $basename, PATHINFO_EXTENSION );
	$original_stem = preg_replace( '/(?:-scaled|-\d+x\d+)$/', '', $stem );
	if ( is_string( $original_stem ) && $original_stem !== $stem ) {
		$candidates[] = $original_stem . ( '' !== $extension ? '.' . $extension : '' );
	}

	global $wpdb;
	foreach ( array_values( array_unique( array_filter( $candidates ) ) ) as $candidate ) {
		$attachment_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s) ORDER BY post_id DESC LIMIT 1",
				$candidate,
				'%/' . $wpdb->esc_like( $candidate )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted fallback when core cannot reverse-map an intermediate or CDN URL.

		if ( $attachment_id > 0 ) {
			break;
		}
	}

	/** @param int $attachment_id Resolved attachment ID, or zero. */
	$ids[ $image ] = max( 0, (int) apply_filters( 'erankly_social_image_attachment_id', $attachment_id, $image ) );

	return $ids[ $image ];
}

/**
 * Returns dimensions for the exact intermediate file where possible.
 *
 * @return array{id:int,width:int,height:int}
 */
function erankly_get_social_image_attachment_data( string $image ): array {
	$attachment_id = erankly_get_social_image_attachment_id( $image );
	$data          = array( 'id' => $attachment_id, 'width' => 0, 'height' => 0 );

	if ( $attachment_id < 1 ) {
		return $data;
	}

	$metadata = wp_get_attachment_metadata( $attachment_id );
	$basename = wp_basename( rawurldecode( (string) wp_parse_url( $image, PHP_URL_PATH ) ) );
	if ( ! is_array( $metadata ) ) {
		return $data;
	}

	foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
		if ( is_array( $size ) && $basename === (string) ( $size['file'] ?? '' ) ) {
			$data['width']  = absint( $size['width'] ?? 0 );
			$data['height'] = absint( $size['height'] ?? 0 );
			return $data;
		}
	}

	$data['width']  = absint( $metadata['width'] ?? 0 );
	$data['height'] = absint( $metadata['height'] ?? 0 );

	return $data;
}

/** Returns the Media Library alt text for the resolved social image. */
function erankly_get_social_image_attachment_alt( string $image ): string {
	static $alts = array();

	if ( array_key_exists( $image, $alts ) ) {
		return $alts[ $image ];
	}

	$attachment_id  = erankly_get_social_image_attachment_id( $image );
	$alt            = $attachment_id > 0 ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '';
	$alts[ $image ] = erankly_sanitize_text( $alt );

	return $alts[ $image ];
}

/**
 * Returns the alternative text for the X image. A shared social description is valid only if both networks
 * resolve to the same image. A distinct X image gets its own explicit or Media Library value instead of
 * inheriting a potentially inaccurate description.
 *
 * @param string $twitter_image Resolved X image URL.
 * @param string $og_image      Resolved Open Graph image URL.
 * @param string $og_alt        Resolved shared Open Graph alternative text.
 */
function erankly_get_twitter_image_alt( string $twitter_image, string $og_image, string $og_alt ): string {
	return erankly_get_social_image_alt(
		'twitter',
		$twitter_image === $og_image ? $og_alt : '',
		$twitter_image
	);
}

/**
 * @param string $network  Either `og` or `twitter`.
 * @param string $image    Resolved social image URL.
 */
function erankly_get_social_image_alt( string $network, string $fallback = '', string $image = '' ): string {
	$key = 'twitter' === $network ? 'twitter_image_alt' : 'og_image_alt';
	$alt = '';

	if ( is_singular() ) {
		$alt = erankly_get_post_meta_string( get_queried_object_id(), $key );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$alt = erankly_get_term_meta_string( $term->term_id, $key );
		}
	} elseif ( is_author() ) {
		$alt = trim( (string) get_user_meta( (int) get_queried_object_id(), '_erankly_' . $key, true ) );
	}

	if ( '' !== $alt ) {
		return $alt;
	}

	if ( '' !== $fallback ) {
		return $fallback;
	}

	return erankly_get_social_image_attachment_alt( $image );
}
