<?php
/**
 * Metadata registration and computed SEO values.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the registered EasyRankly meta keys mapped to their value type.
 *
 * Shared by meta registration and the import/export module so both work from a
 * single source of truth.
 *
 * @return array<string,string>
 */
function erankly_get_meta_keys(): array {
	return array(
		'_erankly_title'               => 'string',
		'_erankly_description'         => 'string',
		'_erankly_canonical'           => 'string',
		'_erankly_breadcrumb_name'     => 'string',
		'_erankly_og_title'            => 'string',
		'_erankly_og_description'      => 'string',
		'_erankly_twitter_title'       => 'string',
		'_erankly_twitter_description' => 'string',
		'_erankly_twitter_card_type'   => 'string',
		'_erankly_social_image_url'    => 'string',
		'_erankly_noindex'             => 'boolean',
		'_erankly_nofollow'            => 'boolean',
		'_erankly_noarchive'           => 'boolean',
		'_erankly_og_image_id'         => 'integer',
		'_erankly_twitter_image_id'    => 'integer',
		'_erankly_disable_sitemap'     => 'boolean',
		'_erankly_exclude_search'      => 'boolean',
		'_erankly_exclude_archive'     => 'boolean',
		'_erankly_exclude_from_news'   => 'boolean',
	);
}

/**
 * Registers protected post meta keys.
 *
 * @return void
 */
function erankly_register_meta(): void {
	$meta = erankly_get_meta_keys();

	foreach ( $meta as $key => $type ) {
		register_post_meta(
			'',
			$key,
			array(
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => 'array' === $type ? array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'object',
						),
					),
				) : true,
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $object_id ): bool {
					unset( $allowed, $meta_key );
					return $object_id > 0 ? current_user_can( 'edit_post', $object_id ) : current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => 'erankly_sanitize_registered_meta',
			)
		);

		register_term_meta(
			'',
			$key,
			array(
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $object_id ): bool {
					unset( $allowed, $meta_key );
					// edit_term is the contextual meta capability; the generic
					// edit_terms check does not resolve for custom taxonomies.
					return $object_id > 0 && current_user_can( 'edit_term', $object_id );
				},
				'sanitize_callback' => 'erankly_sanitize_registered_meta',
			)
		);
	}
}

/**
 * Sanitizes registered post meta.
 *
 * @param mixed  $value    Raw value.
 * @param string $meta_key Meta key.
 * @return mixed
 */
function erankly_sanitize_registered_meta( mixed $value, string $meta_key ): mixed {
	switch ( $meta_key ) {
		case '_erankly_title':
			return erankly_sanitize_text( $value );
		case '_erankly_description':
			return erankly_sanitize_textarea( $value );
		case '_erankly_canonical':
			return erankly_sanitize_text( $value );
		case '_erankly_breadcrumb_name':
			return erankly_sanitize_text( $value );
		case '_erankly_social_image_url':
			return erankly_sanitize_text( $value );
		case '_erankly_og_title':
			return erankly_sanitize_text( $value );
		case '_erankly_og_description':
		case '_erankly_twitter_description':
			return erankly_sanitize_textarea( $value );
		case '_erankly_twitter_title':
			return erankly_sanitize_text( $value );
		case '_erankly_twitter_card_type':
			$value = erankly_sanitize_text( $value );

			return in_array( $value, array( 'summary', 'summary_large_image' ), true ) ? $value : '';
		case '_erankly_og_image_id':
		case '_erankly_twitter_image_id':
			return absint( $value );
		case '_erankly_noindex':
		case '_erankly_nofollow':
		case '_erankly_noarchive':
		case '_erankly_disable_sitemap':
		case '_erankly_exclude_search':
		case '_erankly_exclude_archive':
		case '_erankly_exclude_from_news':
			return (bool) $value;
		default:
			return $value;
	}
}

/**
 * Sanitizes repeatable schema blocks.
 *
 * @param mixed $value     Raw schema blocks.
 * @param bool  $is_global Whether to sanitize global targeting fields.
 * @return array<int,array<string,mixed>>
 */
function erankly_sanitize_schema_blocks( mixed $value, bool $is_global = false ): array {
	// The Settings API already unslashes the input; unslashing again would
	// corrupt backslashes inside custom JSON-LD (e.g. \" or \uXXXX escapes).
	$value      = is_array( $value ) ? $value : array();
	$contexts   = array_fill_keys( array( 'front_page', 'posts_page', 'singular', 'post_type_archive', 'search' ), true );
	$post_types = array_fill_keys( array_keys( erankly_get_public_post_types() ), true );
	$blocks     = array();

	foreach ( $value as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$clean = array(
			'type'   => 'custom',
			'fields' => array(),
		);

		if ( $is_global ) {
			$clean['enabled']           = ! empty( $block['enabled'] ) ? 1 : 0;
			$clean['target_contexts']   = array();
			$clean['target_post_types'] = array();
			$clean['include_items']     = isset( $block['include_items'] ) ? erankly_sanitize_schema_target_items( $block['include_items'] ) : '';
			$clean['exclude_items']     = isset( $block['exclude_items'] ) ? erankly_sanitize_schema_target_items( $block['exclude_items'] ) : '';

			if ( isset( $block['target_contexts'] ) && is_array( $block['target_contexts'] ) ) {
				foreach ( $block['target_contexts'] as $context ) {
					$context = sanitize_key( (string) $context );

					if ( isset( $contexts[ $context ] ) ) {
						$clean['target_contexts'][] = $context;
					}
				}
			}

			if ( isset( $block['target_post_types'] ) && is_array( $block['target_post_types'] ) ) {
				foreach ( $block['target_post_types'] as $post_type ) {
					$post_type = sanitize_key( (string) $post_type );

					if ( isset( $post_types[ $post_type ] ) ) {
						$clean['target_post_types'][] = $post_type;
					}
				}
			}

			$clean['target_contexts']   = array_values( array_unique( $clean['target_contexts'] ) );
			$clean['target_post_types'] = array_values( array_unique( $clean['target_post_types'] ) );
		}

		$clean['fields']['custom_json'] = isset( $block['fields']['custom_json'] ) ? erankly_sanitize_textarea( $block['fields']['custom_json'] ) : '';

		if ( '' !== trim( (string) $clean['fields']['custom_json'] ) && ! erankly_is_valid_custom_json_ld( (string) $clean['fields']['custom_json'] ) ) {
			erankly_add_schema_json_settings_error();
			continue;
		}

		if ( ! erankly_schema_block_has_content( $clean ) ) {
			continue;
		}

		$blocks[] = $clean;
	}

	return $blocks;
}

/**
 * Sanitizes comma or newline-separated schema target IDs and slugs.
 *
 * @param mixed $value Raw target list.
 * @return string
 */
function erankly_sanitize_schema_target_items( mixed $value ): string {
	$items = preg_split( '/[\r\n,]+/', (string) $value );

	if ( ! is_array( $items ) ) {
		return '';
	}

	$clean = array();

	foreach ( $items as $item ) {
		$item = trim( $item );

		if ( '' === $item ) {
			continue;
		}

		$clean[] = ctype_digit( $item ) ? $item : sanitize_title( $item );
	}

	return implode( "\n", array_values( array_unique( array_filter( $clean ) ) ) );
}

/**
 * Returns whether a sanitized schema block contains useful content.
 *
 * @param array<string,mixed> $block Schema block.
 * @return bool
 */
function erankly_schema_block_has_content( array $block ): bool {
	return isset( $block['fields']['custom_json'] ) && '' !== trim( (string) $block['fields']['custom_json'] );
}

/**
 * Adds an admin settings error for invalid custom JSON-LD.
 *
 * @return void
 */
function erankly_add_schema_json_settings_error(): void {
	static $added = false;

	if ( $added || ! function_exists( 'add_settings_error' ) ) {
		return;
	}

	$added = true;

	add_settings_error(
		ERANKLY_OPTION,
		'erankly_invalid_json_ld',
		__( 'Custom JSON-LD was not saved because it is not valid. Use one JSON-LD object, an array of objects, or an object with @graph.', 'easyrankly' ),
		'error'
	);
}

/**
 * Returns whether custom JSON-LD is syntactically usable by EasyRankly.
 *
 * @param string $json Raw JSON-LD.
 * @return bool
 */
function erankly_is_valid_custom_json_ld( string $json ): bool {
	return ! empty( erankly_decode_custom_json_ld( $json ) );
}

/**
 * Decodes custom JSON-LD into graph entries.
 *
 * Supports one object, an array of objects, or an object containing @graph.
 *
 * @param string $json Raw JSON-LD.
 * @return array<int,array<string,mixed>>
 */
function erankly_decode_custom_json_ld( string $json ): array {
	$decoded = json_decode( $json, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
		return array();
	}

	return erankly_normalize_custom_json_ld_data( $decoded );
}

/**
 * Normalizes decoded JSON-LD into graph entries.
 *
 * @param array<mixed> $decoded Decoded JSON data.
 * @return array<int,array<string,mixed>>
 */
function erankly_normalize_custom_json_ld_data( array $decoded ): array {
	if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
		$decoded = $decoded['@graph'];
	}

	if ( array_is_list( $decoded ) ) {
		$schemas = array();

		foreach ( $decoded as $schema ) {
			if ( is_array( $schema ) && ! array_is_list( $schema ) ) {
				unset( $schema['@context'] );

				if ( ! empty( $schema ) ) {
					$schemas[] = $schema;
				}
			}
		}

		return $schemas;
	}

	unset( $decoded['@context'] );

	return empty( $decoded ) ? array() : array( $decoded );
}

/**
 * Excludes content from frontend search and archive queries when configured.
 *
 * @param WP_Query $query Query object.
 * @return void
 */
function erankly_filter_visibility_queries( WP_Query $query ): void {
	if ( is_admin() || wp_doing_ajax() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_search() && erankly_has_visibility_exclusions( '_erankly_exclude_search' ) ) {
		erankly_add_query_exclusion_meta_clause( $query, '_erankly_exclude_search' );
	}

	if ( $query->is_archive() && erankly_has_visibility_exclusions( '_erankly_exclude_archive' ) ) {
		erankly_add_query_exclusion_meta_clause( $query, '_erankly_exclude_archive' );
	}
}

/**
 * Returns whether a visibility meta key is used by at least one post.
 *
 * The lightweight existence lookup is cached so sites that never use the
 * feature avoid adding postmeta joins to every search or archive request.
 *
 * @param string $meta_key Visibility meta key.
 * @return bool
 */
function erankly_has_visibility_exclusions( string $meta_key ): bool {
	global $wpdb;

	$allowed = array( '_erankly_exclude_search', '_erankly_exclude_archive' );

	if ( ! in_array( $meta_key, $allowed, true ) ) {
		return false;
	}

	$cache_key = 'erankly_visibility_' . md5( $meta_key );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return '1' === (string) $cached;
	}

	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A transient-cached indexed existence check avoids expensive meta queries on normal archive requests.
		$wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1' LIMIT 1",
			$meta_key
		)
	);
	$value = null !== $found ? '1' : '0';

	set_transient( $cache_key, $value, DAY_IN_SECONDS );

	return '1' === $value;
}

/**
 * Invalidates visibility existence caches after relevant post meta changes.
 *
 * The first argument is a single meta row ID on added_post_meta/updated_post_meta
 * but an array of meta IDs on deleted_post_meta, so it is typed to accept both.
 *
 * @param int|array $meta_id  Meta row ID, or array of IDs on deletion.
 * @param int       $post_id  Post ID.
 * @param string    $meta_key Meta key.
 * @return void
 */
function erankly_invalidate_visibility_exclusion_cache( int|array $meta_id, int $post_id, string $meta_key ): void {
	unset( $meta_id, $post_id );

	if ( in_array( $meta_key, array( '_erankly_exclude_search', '_erankly_exclude_archive' ), true ) ) {
		delete_transient( 'erankly_visibility_' . md5( $meta_key ) );
	}
}

/**
 * Adds a meta query clause that excludes posts with a truthy visibility flag.
 *
 * @param WP_Query $query    Query object.
 * @param string   $meta_key Protected meta key.
 * @return void
 */
function erankly_add_query_exclusion_meta_clause( WP_Query $query, string $meta_key ): void {
	$meta_query = $query->get( 'meta_query' );
	$existing   = is_array( $meta_query ) ? $meta_query : array();
	$exclusion  = array(
		'relation' => 'OR',
		array(
			'key'     => $meta_key,
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => $meta_key,
			'value'   => '1',
			'compare' => '!=',
		),
	);

	if ( empty( $existing ) ) {
		$query->set( 'meta_query', $exclusion );
		return;
	}

	$query->set(
		'meta_query',
		array(
			'relation' => 'AND',
			$existing,
			$exclusion,
		)
	);
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
		unset( $parts['site'], $parts['tagline'] );
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
		$title       = erankly_get_global_entity_meta( 'global_special_meta', $special_key, 'title' );

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
		$description = erankly_get_global_entity_meta( 'global_special_meta', 'author', 'description' );

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

/**
 * Renders the minimal SEO head.
 *
 * @return void
 */
function erankly_render_head(): void {
	static $rendered = false;

	if ( $rendered ) {
		return;
	}

	$rendered    = true;
	$description = erankly_get_description();
	$canonical   = erankly_get_canonical();

	erankly_render_head_credit( 'open' );

	if ( '' !== $description ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
	}

	if ( '' !== $canonical ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
	}

	erankly_render_hreflang_alternates();
	erankly_render_opengraph_tags();
	erankly_render_oembed_link();
	erankly_render_schema();

	erankly_render_head_credit( 'close' );
}

/**
 * Renders the HTML comment that brackets EasyRankly's <head> output.
 *
 * Mirrors the debug markers other SEO plugins emit so the meta tags below
 * are identifiable in the page source. The product name is filterable, which
 * lets a licensed add-on advertise its own product name in the markers while
 * its license is active.
 * Returning an empty string from the `erankly_head_credit_name` filter
 * removes the markers entirely (e.g. add_filter( 'erankly_head_credit_name', '__return_empty_string' )).
 *
 * @param string $position Either 'open' (top marker, with version) or 'close' (closing marker).
 * @return void
 */
function erankly_render_head_credit( string $position ): void {
	// The site owner can switch the markers off from Settings; that choice wins
	// over any product-name relabeling done by an add-on (e.g. advertising "Premium").
	if ( ! empty( erankly_get_setting( 'hide_head_credit', 0 ) ) ) {
		return;
	}

	/**
	 * Filters the product name shown in the <head> debug markers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Product name. Default "EasyRankly". Empty string hides the markers.
	 */
	$name = (string) apply_filters( 'erankly_head_credit_name', 'EasyRankly' );

	// Keep the comment well-formed regardless of what the filter returns: an HTML
	// comment must not contain a literal "--" or be closed early by "-->".
	$name = trim( preg_replace( '/-{2,}|[<>]/', '', wp_strip_all_tags( $name ) ) );

	if ( '' === $name ) {
		return;
	}

	if ( 'open' === $position ) {
		printf(
			'<!-- This site is optimized with the %1$s SEO plugin v%2$s - https://easyrankly.com -->' . "\n",
			esc_html( $name ),
			esc_html( ERANKLY_VERSION )
		);

		return;
	}

	printf( '<!-- / %s SEO plugin. -->' . "\n", esc_html( $name ) );
}

/**
 * Renders hreflang alternate links.
 *
 * @return void
 */
function erankly_render_hreflang_alternates(): void {
	foreach ( erankly_get_hreflang_alternates() as $hreflang => $url ) {
		printf(
			'<link rel="alternate" hreflang="%1$s" href="%2$s">' . "\n",
			esc_attr( $hreflang ),
			esc_url( $url )
		);
	}
}

/**
 * Returns validated hreflang alternates for the current request.
 *
 * @return array<string,string>
 */
function erankly_get_hreflang_alternates(): array {
	$alternates = erankly_get_polylang_hreflang_alternates();

	/**
	 * Filters hreflang alternate URLs.
	 *
	 * Expected shape: array( 'it-IT' => 'https://example.com/it/pagina/', 'x-default' => 'https://example.com/' ).
	 *
	 * @param array<string,string> $alternates Hreflang alternates.
	 */
	$alternates = apply_filters( 'erankly_hreflang_alternates', $alternates );

	return erankly_clean_hreflang_alternates( $alternates );
}

/**
 * Returns the language alternates a visitor can navigate to for the current request.
 *
 * Same shape as erankly_get_hreflang_alternates(), but built for human
 * navigation rather than search-engine signalling: published translations are
 * included even when they are noindex. Used by visitor-facing features such as
 * a browser-language redirect add-on. Never use this set for hreflang output.
 *
 * @return array<string,string>
 */
function erankly_get_navigable_hreflang_alternates(): array {
	// Polylang alternates carry no noindex filtering, so the SEO builder is reused.
	$alternates = erankly_get_polylang_hreflang_alternates();

	// When the network multilingual module is active, replace with its
	// navigable resolution (includes noindex, excludes unpublished).
	$resolver = $GLOBALS['erankly_ml_resolver'] ?? null;
	if ( $resolver instanceof ERankly_ML_Resolver ) {
		$alternates = $resolver->resolve_navigable( $alternates );
	}

	/**
	 * Filters the visitor-navigable language alternates.
	 *
	 * Expected shape: array( 'it-IT' => 'https://example.com/it/pagina/', 'x-default' => 'https://example.com/' ).
	 *
	 * @param array<string,string> $alternates Navigable alternates.
	 */
	$alternates = apply_filters( 'erankly_navigable_hreflang_alternates', $alternates );

	return erankly_clean_hreflang_alternates( $alternates );
}

/**
 * Validates and sanitises a raw hreflang => URL map.
 *
 * @param mixed $alternates Raw alternates (any filter output).
 * @return array<string,string>
 */
function erankly_clean_hreflang_alternates( $alternates ): array {
	if ( ! is_array( $alternates ) ) {
		return array();
	}

	$clean = array();

	foreach ( $alternates as $hreflang => $url ) {
		$hreflang = sanitize_text_field( (string) $hreflang );
		$url      = esc_url_raw( (string) $url );

		if ( '' === $hreflang || ! erankly_is_absolute_http_url( $url ) ) {
			continue;
		}

		$clean[ $hreflang ] = $url;
	}

	return $clean;
}

/**
 * Returns Polylang hreflang alternates when Polylang is active.
 *
 * @return array<string,string>
 */
function erankly_get_polylang_hreflang_alternates(): array {
	$alternates = array();
	$locale_map = erankly_get_polylang_locale_map();

	if ( is_singular() && function_exists( 'pll_get_post_translations' ) ) {
		$alternates = erankly_get_polylang_post_alternates( get_queried_object_id(), $locale_map );
	} elseif ( is_home() && ! is_front_page() && function_exists( 'pll_get_post_translations' ) ) {
		$posts_page_id = absint( get_option( 'page_for_posts' ) );

		if ( $posts_page_id > 0 ) {
			$alternates = erankly_get_polylang_post_alternates( $posts_page_id, $locale_map );
		}
	} elseif ( ( is_category() || is_tag() || is_tax() ) && function_exists( 'pll_get_term_translations' ) ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$translations = pll_get_term_translations( $term->term_id );

			if ( is_array( $translations ) ) {
				foreach ( $translations as $language => $term_id ) {
					$link = get_term_link( absint( $term_id ) );

					if ( ! is_wp_error( $link ) && is_string( $link ) ) {
						$alternates[ $locale_map[ $language ] ?? (string) $language ] = $link;
					}
				}
			}
		}
	} elseif ( ( is_front_page() || is_home() ) && function_exists( 'pll_home_url' ) && function_exists( 'pll_languages_list' ) ) {
		$languages = pll_languages_list( array( 'fields' => 'slug' ) );

		if ( is_array( $languages ) ) {
			foreach ( $languages as $language ) {
				$url = pll_home_url( (string) $language );

				if ( is_string( $url ) && '' !== $url ) {
					$alternates[ $locale_map[ $language ] ?? (string) $language ] = $url;
				}
			}
		}
	}

	return $alternates;
}

/**
 * Returns Polylang post alternates for a post-like object.
 *
 * @param int                  $post_id    Post ID.
 * @param array<string,string> $locale_map Locale map keyed by language slug.
 * @return array<string,string>
 */
function erankly_get_polylang_post_alternates( int $post_id, array $locale_map ): array {
	if ( $post_id <= 0 || ! function_exists( 'pll_get_post_translations' ) ) {
		return array();
	}

	$translations = pll_get_post_translations( $post_id );

	if ( ! is_array( $translations ) ) {
		return array();
	}

	$alternates = array();

	foreach ( $translations as $language => $translation_id ) {
		$url = get_permalink( absint( $translation_id ) );

		if ( is_string( $url ) && '' !== $url ) {
			$alternates[ $locale_map[ $language ] ?? (string) $language ] = $url;
		}
	}

	return $alternates;
}

/**
 * Returns a Polylang locale map keyed by language slug.
 *
 * @return array<string,string>
 */
function erankly_get_polylang_locale_map(): array {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	if ( ! function_exists( 'pll_languages_list' ) ) {
		$map = array();
		return $map;
	}

	$slugs   = pll_languages_list( array( 'fields' => 'slug' ) );
	$locales = pll_languages_list( array( 'fields' => 'locale' ) );

	if ( ! is_array( $slugs ) || ! is_array( $locales ) || count( $slugs ) !== count( $locales ) ) {
		$map = array();
		return $map;
	}

	$map = array();

	foreach ( $slugs as $index => $slug ) {
		if ( isset( $locales[ $index ] ) ) {
			$map[ (string) $slug ] = (string) $locales[ $index ];
		}
	}

	return $map;
}

/**
 * Redirects attachment pages to the parent post or media file.
 *
 * Fired on template_redirect. The destination is controlled by the
 * `attachment_redirect` setting ('parent', 'file', 'none').
 *
 * @return void
 */
function erankly_redirect_attachment(): void {
	if ( ! is_attachment() ) {
		return;
	}

	$mode = (string) erankly_get_setting( 'attachment_redirect', 'none' );

	if ( 'none' === $mode ) {
		return;
	}

	$post_id    = get_queried_object_id();
	$parent_id  = (int) wp_get_post_parent_id( $post_id );
	$target_url = '';

	if ( 'parent' === $mode && $parent_id > 0 ) {
		$permalink  = get_permalink( $parent_id );
		$target_url = is_string( $permalink ) ? $permalink : '';
	}

	// Fall through to file URL when mode is 'file', or when 'parent' but no parent exists.
	if ( '' === $target_url ) {
		$file_url   = wp_get_attachment_url( $post_id );
		$target_url = is_string( $file_url ) ? $file_url : '';
	}

	if ( '' === $target_url ) {
		return;
	}

	// Use wp_safe_redirect for same-host URLs; fall back to wp_redirect for CDN/external.
	if ( wp_parse_url( $target_url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		wp_safe_redirect( $target_url, 301, 'EasyRankly' );
	} else {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External file URL (e.g. CDN); safe_redirect would block it.
		wp_redirect( $target_url, 301, 'EasyRankly' );
	}

	exit;
}
