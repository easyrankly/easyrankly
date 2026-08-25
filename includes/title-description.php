<?php
/**
 * Document title and meta description computation.
 *
 * Loaded by meta.php (always required), so these functions are globally
 * available wherever the head metadata is built.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the document title.
 *
 * @param string $title Original title.
 * @return string
 */
function erankly_filter_document_title( string $title ): string {
	$seo_title = erankly_get_title();

	return '' !== $seo_title ? $seo_title : $title;
}

/**
 * Filters title parts when themes use title-tag support.
 *
 * @param array<string,string> $parts Title parts.
 * @return array<string,string>
 */
function erankly_filter_document_title_parts( array $parts ): array {
	$seo_title = erankly_get_title();

	if ( '' !== $seo_title ) {
		$parts['title'] = $seo_title;
		unset( $parts['site'], $parts['tagline'], $parts['page'] );
	}

	return $parts;
}

/**
 * Returns the computed SEO title.
 *
 * @return string
 */
function erankly_get_title(): string {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$title = '';

	if ( is_singular() ) {
		$post_id = get_queried_object_id();
		$title   = erankly_get_post_meta_string( $post_id, 'title' );

		if ( '' === $title ) {
			$post_type = get_post_type( $post_id );

			if ( is_string( $post_type ) ) {
				$title = erankly_get_global_post_type_meta( $post_type, 'title' );
			}
		}

		if ( '' === $title ) {
			$title = single_post_title( '', false );
		} else {
			$title = erankly_replace_variables( $title, $post_id, array( 'seo_title' ) );
		}
	} elseif ( is_home() || is_front_page() ) {
		$special_key = is_front_page() ? 'homepage' : 'blog';
		$title       = erankly_get_global_entity_meta( 'global_special_meta', $special_key, 'title' );

		if ( '' !== $title ) {
			$title = erankly_replace_variables( $title, 0, array( 'seo_title' ) );
		} else {
			$title = get_bloginfo( 'name' );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$title = erankly_get_term_meta_string( $term->term_id, 'title' );

			if ( '' === $title ) {
				$title = erankly_get_global_taxonomy_meta( $term->taxonomy, 'title' );
			}

			if ( '' !== $title ) {
				$title = erankly_replace_variables( $title, 0, array( 'seo_title' ) );
			}
		}

		if ( '' === $title ) {
			$title = get_the_archive_title();
		}
	} elseif ( is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		$title     = is_string( $post_type ) ? erankly_get_global_post_type_meta( $post_type, 'title' ) : '';

		if ( '' !== $title ) {
			$title = erankly_replace_variables( $title, 0, array( 'seo_title' ) );
		}

		if ( '' === $title ) {
			$title = get_the_archive_title();
		}
	} elseif ( is_author() || is_date() ) {
		$special_key = is_author() ? 'author' : 'date';
		$title       = is_author() ? trim( (string) get_user_meta( (int) get_queried_object_id(), '_erankly_title', true ) ) : '';
		$title       = '' !== $title ? $title : erankly_get_global_entity_meta( 'global_special_meta', $special_key, 'title' );

		if ( '' !== $title ) {
			$title = erankly_replace_variables( $title, 0, array( 'seo_title' ) );
		} else {
			$title = get_the_archive_title();
		}
	} elseif ( is_archive() ) {
		$title = get_the_archive_title();
	} elseif ( is_search() ) {
		$title = erankly_get_global_entity_meta( 'global_special_meta', 'search', 'title' );

		if ( '' !== $title ) {
			$title = erankly_replace_variables( $title, 0, array( 'seo_title' ) );
		} else {
			$title = sprintf(
				/* translators: %s: Search query. */
				__( 'Search results for %s', 'easyrankly' ),
				get_search_query()
			);
		}
	} elseif ( is_404() ) {
		$title = erankly_get_global_entity_meta( 'global_special_meta', '404', 'title' );

		if ( '' !== $title ) {
			$title = erankly_replace_variables( $title, 0, array( 'seo_title' ) );
		} else {
			$title = __( 'Page not found', 'easyrankly' );
		}
	}

	// Append paginated suffix (e.g. "Page 2 of 5") for any paged context.
	$current_page = max( (int) get_query_var( 'paged', 0 ), (int) get_query_var( 'page', 0 ) );
	if ( $current_page > 1 ) {
		$suffix = trim( (string) erankly_get_setting( 'paginated_title_format', '' ) );
		if ( '' !== $suffix ) {
			$suffix = trim( erankly_replace_variables( $suffix, 0, array( 'seo_title' ) ) );
			if ( '' !== $suffix ) {
				$title = trim( $title . ' - ' . $suffix );
			}
		}
	}

	$title = erankly_normalize_seo_text( $title );

	/**
	 * Filters the computed SEO title.
	 *
	 * @param string $title Computed title.
	 */
	$resolved = (string) apply_filters( 'erankly_title', $title );

	return $resolved;
}

/**
 * Returns the computed meta description.
 *
 * @return string
 */
function erankly_get_description(): string {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$description                    = '';
	$description_generated_fallback = false;

	if ( is_singular() ) {
		$post_id     = get_queried_object_id();
		$description = erankly_get_post_meta_string( $post_id, 'description' );

		if ( '' === $description ) {
			$post_type = get_post_type( $post_id );

			if ( is_string( $post_type ) ) {
				$description = erankly_get_global_post_type_meta( $post_type, 'description' );
			}
		}

		if ( '' === $description ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post ) {
				$description                    = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
				$description_generated_fallback = true;
			}
		} else {
			$description = erankly_replace_variables( $description, $post_id, array( 'meta_description' ) );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$description = erankly_get_term_meta_string( $term->term_id, 'description' );

			if ( '' === $description ) {
				$description = erankly_get_global_taxonomy_meta( $term->taxonomy, 'description' );
			}

			if ( '' !== $description ) {
				$description = erankly_replace_variables( $description, 0, array( 'meta_description' ) );
			}
		}

		if ( '' === $description ) {
			$description                    = term_description();
			$description_generated_fallback = true;
		}
	} elseif ( is_post_type_archive() ) {
		$post_type   = get_query_var( 'post_type' );
		$post_type   = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		$description = is_string( $post_type ) ? erankly_get_global_post_type_meta( $post_type, 'description' ) : '';

		if ( '' !== $description ) {
			$description = erankly_replace_variables( $description, 0, array( 'meta_description' ) );
		}
	} elseif ( is_author() ) {
		$description = trim( (string) get_user_meta( (int) get_queried_object_id(), '_erankly_description', true ) );
		$description = '' !== $description ? $description : erankly_get_global_entity_meta( 'global_special_meta', 'author', 'description' );

		if ( '' !== $description ) {
			$description = erankly_replace_variables( $description, 0, array( 'meta_description' ) );
		} else {
			$description                    = get_the_author_meta( 'description', (int) get_queried_object_id() );
			$description_generated_fallback = true;
		}
	} elseif ( is_date() ) {
		$description = erankly_get_global_entity_meta( 'global_special_meta', 'date', 'description' );

		if ( '' !== $description ) {
			$description = erankly_replace_variables( $description, 0, array( 'meta_description' ) );
		}
	} elseif ( is_search() ) {
		$description = erankly_get_global_entity_meta( 'global_special_meta', 'search', 'description' );

		if ( '' !== $description ) {
			$description = erankly_replace_variables( $description, 0, array( 'meta_description' ) );
		}
	} elseif ( is_404() ) {
		$description = erankly_get_global_entity_meta( 'global_special_meta', '404', 'description' );

		if ( '' !== $description ) {
			$description = erankly_replace_variables( $description, 0, array( 'meta_description' ) );
		}
	} elseif ( is_home() || is_front_page() ) {
		$special_key = is_front_page() ? 'homepage' : 'blog';
		$description = erankly_get_global_entity_meta( 'global_special_meta', $special_key, 'description' );

		if ( '' !== $description ) {
			$description = erankly_replace_variables( $description, 0, array( 'meta_description' ) );
		} else {
			$description = get_bloginfo( 'description' );
		}
	}

	$description = $description_generated_fallback ? erankly_trim_text( strip_shortcodes( $description ), 160 ) : erankly_normalize_seo_text( $description );

	/**
	 * Filters the computed meta description.
	 *
	 * @param string $description Computed description.
	 */
	$resolved = (string) apply_filters( 'erankly_description', $description );

	return $resolved;
}
