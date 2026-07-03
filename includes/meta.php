<?php
/**
 * Metadata registration and computed SEO values.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Title/description and hreflang live in dedicated files; required here because
// meta.php is always loaded, so their functions stay globally available.
require_once ERANKLY_PATH . 'includes/title-description.php';
require_once ERANKLY_PATH . 'includes/hreflang.php';

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
			return erankly_sanitize_url_template( $value );
		case '_erankly_breadcrumb_name':
			return erankly_sanitize_text( $value );
		case '_erankly_social_image_url':
			return erankly_sanitize_url_template( $value );
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

	if ( erankly_array_is_list( $decoded ) ) {
		$schemas = array();

		foreach ( $decoded as $schema ) {
			if ( is_array( $schema ) && ! erankly_array_is_list( $schema ) ) {
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
