<?php
/**
 * Open Graph and Twitter card output.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the automatic social title used for singular content in simplified mode.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_get_simplified_social_title( int $post_id ): string {
	$title = erankly_get_post_meta_string( $post_id, 'title' );

	if ( '' !== $title ) {
		$title = erankly_replace_variables( $title, $post_id, array( 'seo_title' ) );
	} else {
		$title = get_the_title( $post_id );
	}

	return erankly_normalize_seo_text( $title );
}

/**
 * Returns the automatic social description used for singular content in simplified mode.
 *
 * @param int $post_id Post ID.
 * @return string
 */
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

/**
 * Renders Open Graph and Twitter meta tags.
 *
 * @return void
 */
function erankly_render_opengraph_tags(): void {
	$title         = erankly_get_og_title();
	$description   = erankly_get_og_description();
	$url           = erankly_get_canonical();
	$image         = erankly_get_og_image();
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
		'og:image:alt'        => '' !== $image ? $image_alt : '',
		'twitter:card'        => erankly_get_twitter_card_type(),
		'twitter:site'        => $twitter_site,
		'twitter:title'       => $twitter_title,
		'twitter:description' => $twitter_desc,
		'twitter:image'       => $twitter_image,
		'twitter:image:alt'   => '' !== $twitter_image ? $twitter_alt : '',
	);

	/**
	 * Filters Open Graph and Twitter tags.
	 *
	 * @param array<string,string> $tags Tags keyed by property/name.
	 */
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
 * Resolves a social title or description through the shared fallback chain.
 *
 * Order: explicit per-content meta, simplified-mode automatic value,
 * special-page template, global default template, then the caller-provided fallback.
 *
 * @param string $meta_key    Per-content meta key (without plugin prefix).
 * @param string $setting_key Global default setting key.
 * @param string $fallback    Final fallback value.
 * @param int    $limit       Character limit.
 * @return string
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

/**
 * Returns the computed Open Graph title.
 *
 * @return string
 */
function erankly_get_og_title(): string {
	$title = erankly_resolve_social_text( 'og_title', 'default_og_title', erankly_get_title(), 60 );

	/**
	 * Filters the computed Open Graph title.
	 *
	 * @param string $title Computed Open Graph title.
	 */
	return (string) apply_filters( 'erankly_og_title', $title );
}

/**
 * Returns the computed Open Graph description.
 *
 * @return string
 */
function erankly_get_og_description(): string {
	$description = erankly_resolve_social_text( 'og_description', 'default_og_description', erankly_get_description(), 200 );

	/**
	 * Filters the computed Open Graph description.
	 *
	 * @param string $description Computed Open Graph description.
	 */
	return (string) apply_filters( 'erankly_og_description', $description );
}

/**
 * Returns the computed X/Twitter title.
 *
 * @param string $fallback Fallback title.
 * @return string
 */
function erankly_get_twitter_title( string $fallback = '' ): string {
	$title = erankly_resolve_social_text( 'twitter_title', 'default_twitter_title', $fallback, 70 );

	/**
	 * Filters the computed X/Twitter title.
	 *
	 * @param string $title Computed X/Twitter title.
	 */
	return (string) apply_filters( 'erankly_twitter_title', $title );
}

/**
 * Returns the computed X/Twitter description.
 *
 * @param string $fallback Fallback description.
 * @return string
 */
function erankly_get_twitter_description( string $fallback = '' ): string {
	$description = erankly_resolve_social_text( 'twitter_description', 'default_twitter_description', $fallback, 200 );

	/**
	 * Filters the computed X/Twitter description.
	 *
	 * @param string $description Computed X/Twitter description.
	 */
	return (string) apply_filters( 'erankly_twitter_description', $description );
}

/**
 * Returns the selected X/Twitter card type.
 *
 * @return string
 */
function erankly_get_twitter_card_type(): string {
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

	/**
	 * Filters the X/Twitter card type.
	 *
	 * @param string $card_type X/Twitter card type.
	 */
	return (string) apply_filters( 'erankly_twitter_card_type', $card_type );
}

/**
 * Returns the configured X/Twitter site handle.
 *
 * @return string
 */
function erankly_get_twitter_site(): string {
	$site = trim( (string) erankly_get_setting( 'twitter_site', '' ) );

	if ( '' !== $site && '@' !== $site[0] ) {
		$site = '@' . $site;
	}

	/**
	 * Filters the X/Twitter site handle.
	 *
	 * @param string $site X/Twitter site handle.
	 */
	return (string) apply_filters( 'erankly_twitter_site', $site );
}

/**
 * Returns the best available X/Twitter image URL.
 *
 * @param string $fallback Fallback image URL.
 * @return string
 */
function erankly_get_twitter_image( string $fallback = '' ): string {
	$image = '';

	if ( is_singular() ) {
		$post_id   = get_queried_object_id();
		$image     = erankly_get_post_meta_string( $post_id, 'twitter_image_url' );
		$image     = '' !== $image ? $image : erankly_get_post_meta_string( $post_id, 'social_image_url' );
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
			$image = erankly_get_term_meta_string( $term->term_id, 'twitter_image_url' );
			$image = '' !== $image ? $image : erankly_get_term_meta_string( $term->term_id, 'social_image_url' );

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

	/**
	 * Filters the X/Twitter image URL.
	 *
	 * @param string $image X/Twitter image URL.
	 */
	return (string) apply_filters( 'erankly_twitter_image', $image );
}

/**
 * Renders the oEmbed JSON discovery link.
 *
 * Always active on every public page.
 *
 * @return void
 */
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
 * Returns the social sharing image configured for the current special page.
 *
 * Resolves the special-page social image URL, then the picked media library
 * attachment, or an empty string when none is set.
 *
 * @return string
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

/**
 * Returns the computed Open Graph image URL.
 *
 * @return string
 */
function erankly_get_og_image(): string {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$image = '';

	if ( is_singular() ) {
		$post_id     = get_queried_object_id();
		$custom_id   = absint( get_post_meta( $post_id, '_erankly_og_image_id', true ) );
		$featured_id = get_post_thumbnail_id( $post_id );
		$image       = erankly_get_post_meta_string( $post_id, 'og_image_url' );
		$image       = '' !== $image ? $image : erankly_get_post_meta_string( $post_id, 'social_image_url' );

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
			$image = erankly_get_term_meta_string( $term->term_id, 'og_image_url' );
			$image = '' !== $image ? $image : erankly_get_term_meta_string( $term->term_id, 'social_image_url' );

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

	/**
	 * Filters the Open Graph image URL.
	 *
	 * @param string $image Image URL.
	 */
	$resolved = (string) apply_filters( 'erankly_og_image', $image );

	return $resolved;
}

/**
 * Returns a Media Library attachment alternative text by its resolved URL.
 *
 * This is deliberately a fallback: explicitly authored social metadata always
 * wins, while selected WordPress images inherit their existing accessibility
 * description without creating duplicate per-network values.
 *
 * @param string $image URL of the resolved social image.
 * @return string
 */
function erankly_get_social_image_attachment_alt( string $image ): string {
	static $alts = array();

	$image = trim( $image );

	if (
		'' === $image
		|| str_contains( $image, '{{' )
		|| ! function_exists( 'attachment_url_to_postid' )
		|| ! function_exists( 'wp_get_upload_dir' )
		|| ! function_exists( 'wp_parse_url' )
	) {
		return '';
	}

	if ( array_key_exists( $image, $alts ) ) {
		return $alts[ $image ];
	}

	$uploads    = wp_get_upload_dir();
	$base_url   = isset( $uploads['baseurl'] ) ? trim( (string) $uploads['baseurl'] ) : '';
	$base_host  = strtolower( (string) wp_parse_url( $base_url, PHP_URL_HOST ) );
	$image_host = strtolower( (string) wp_parse_url( $image, PHP_URL_HOST ) );
	$base_path  = rtrim( (string) wp_parse_url( $base_url, PHP_URL_PATH ), '/' ) . '/';
	$image_path = (string) wp_parse_url( $image, PHP_URL_PATH );

	if ( '' === $base_host || $image_host !== $base_host || ! str_starts_with( $image_path, $base_path ) ) {
		$alts[ $image ] = '';
		return '';
	}

	$attachment_id  = attachment_url_to_postid( $image );
	$alt            = $attachment_id > 0 ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '';
	$alts[ $image ] = erankly_sanitize_text( $alt );

	return $alts[ $image ];
}

/**
 * Returns the alternative text for the X image.
 *
 * A shared social description is valid only if both networks resolve to the
 * same image. A distinct X image gets its own explicit or Media Library value
 * instead of inheriting a potentially inaccurate description.
 *
 * @param string $twitter_image Resolved X image URL.
 * @param string $og_image      Resolved Open Graph image URL.
 * @param string $og_alt        Resolved shared Open Graph alternative text.
 * @return string
 */
function erankly_get_twitter_image_alt( string $twitter_image, string $og_image, string $og_alt ): string {
	return erankly_get_social_image_alt(
		'twitter',
		$twitter_image === $og_image ? $og_alt : '',
		$twitter_image
	);
}

/**
 * Returns alternative text for a social image.
 *
 * @param string $network  Either `og` or `twitter`.
 * @param string $fallback Fallback alternative text.
 * @param string $image    Resolved social image URL.
 * @return string
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
