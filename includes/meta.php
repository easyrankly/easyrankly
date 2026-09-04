<?php
/** Metadata registration and computed SEO values. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Title/description and hreflang live in dedicated files; required here because
// meta.php is always loaded, so their functions stay globally available.
require_once ERANKLY_PATH . 'includes/title-description.php';
require_once ERANKLY_PATH . 'includes/hreflang.php';

/**
 * Returns the registered EasyRankly meta keys mapped to their value type. Shared by meta registration and the
 * import/export module so both work from a single source of truth.
 *
 * @return array<string,string>
 */
function erankly_get_meta_keys(): array {
	$keys = array(
		'_erankly_title'                 => 'string',
		'_erankly_description'           => 'string',
		'_erankly_canonical'             => 'string',
		'_erankly_breadcrumb_name'       => 'string',
		'_erankly_og_title'              => 'string',
		'_erankly_og_description'        => 'string',
		'_erankly_twitter_title'         => 'string',
		'_erankly_twitter_description'   => 'string',
		'_erankly_twitter_card_type'     => 'string',
		'_erankly_social_image_url'      => 'string',
		'_erankly_og_image_url'          => 'string',
		'_erankly_og_image_alt'          => 'string',
		'_erankly_twitter_image_url'     => 'string',
		'_erankly_twitter_image_alt'     => 'string',
		'_erankly_noindex'               => 'boolean',
		'_erankly_nofollow'              => 'boolean',
		'_erankly_noarchive'             => 'boolean',
		'_erankly_index_directive'       => 'string',
		'_erankly_follow_directive'      => 'string',
		'_erankly_archive_directive'     => 'string',
		'_erankly_snippet_directive'     => 'string',
		'_erankly_image_directive'       => 'string',
		'_erankly_max_snippet'           => 'string',
		'_erankly_max_video_preview'     => 'string',
		'_erankly_max_image_preview'     => 'string',
		'_erankly_indexifembedded'       => 'boolean',
		'_erankly_og_image_id'           => 'integer',
		'_erankly_twitter_image_id'      => 'integer',
		'_erankly_primary_terms'         => 'object',
		// Kept readable for backwards compatibility. New migrations use the
		// native editorial keys above instead of writing opaque source payloads.
		'_erankly_legacy_editorial'      => 'object',
		'_erankly_schema_mode'           => 'string',
		'_erankly_schema_blocks'         => 'array',
		'_erankly_schema_disabled_types' => 'array',
		'_erankly_disable_sitemap'       => 'boolean',
		'_erankly_exclude_search'        => 'boolean',
		'_erankly_exclude_archive'       => 'boolean',
		'_erankly_exclude_from_news'     => 'boolean',
	);

	/**
 * Filters the registered EasyRankly meta keys. Add-ons may register extra keys so import/export and REST meta
 * share one list.
 *
 * @param array<string,string> $keys Meta key => value type.
 */
	$keys = apply_filters( 'erankly_meta_keys', $keys );

	return is_array( $keys ) ? $keys : array();
}

function erankly_register_meta(): void {
	$meta = erankly_get_meta_keys();

	foreach ( $meta as $key => $type ) {
		$rest_schema = erankly_get_registered_meta_rest_schema( $key, $type );

		register_post_meta(
			'',
			$key,
			array(
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => $rest_schema,
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
				'show_in_rest'      => $rest_schema,
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

	$user_meta = array(
		'_erankly_title',
		'_erankly_description',
		'_erankly_canonical',
		'_erankly_og_title',
		'_erankly_og_description',
		'_erankly_twitter_title',
		'_erankly_twitter_description',
		'_erankly_twitter_card_type',
		'_erankly_og_image_url',
		'_erankly_og_image_alt',
		'_erankly_twitter_image_url',
		'_erankly_twitter_image_alt',
		'_erankly_index_directive',
		'_erankly_follow_directive',
		'_erankly_archive_directive',
		'_erankly_snippet_directive',
		'_erankly_image_directive',
		'_erankly_max_snippet',
		'_erankly_max_video_preview',
		'_erankly_max_image_preview',
		'_erankly_indexifembedded',
		'_erankly_schema_mode',
		'_erankly_schema_blocks',
	);

	foreach ( $user_meta as $key ) {
		$type = $meta[ $key ];

		register_meta(
			'user',
			$key,
			array(
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => erankly_get_registered_meta_rest_schema( $key, $type ),
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $object_id ): bool {
					unset( $allowed, $meta_key );
					return $object_id > 0 && current_user_can( 'edit_user', $object_id );
				},
				'sanitize_callback' => 'erankly_sanitize_registered_meta',
			)
		);
	}
}

/** @return bool|array<string,mixed> */
function erankly_get_registered_meta_rest_schema( string $key, string $type ): bool|array {
	if ( 'array' !== $type && 'object' !== $type ) {
		$schema = true;
	} else {
		$schema = array( 'type' => $type );

		if ( '_erankly_schema_blocks' === $key ) {
			$schema['items'] = array( 'type' => 'object' );
		} elseif ( '_erankly_schema_disabled_types' === $key ) {
			$schema['items'] = array( 'type' => 'string' );
		} elseif ( '_erankly_primary_terms' === $key ) {
			$schema['additionalProperties'] = array( 'type' => 'integer' );
		} elseif ( 'object' === $type ) {
			$schema['additionalProperties'] = true;
		}

		$schema = array( 'schema' => $schema );
	}

	/** Filters the REST schema used when registering a meta key. */
	return apply_filters( 'erankly_registered_meta_rest_schema', $schema, $key, $type );
}

/** Sanitizes registered post meta. */
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
		case '_erankly_og_image_url':
		case '_erankly_twitter_image_url':
			return erankly_sanitize_url_template( $value );
		case '_erankly_og_image_alt':
		case '_erankly_twitter_image_alt':
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
		case '_erankly_index_directive':
			return erankly_sanitize_meta_enum( $value, array( 'inherit', 'index', 'noindex' ) );
		case '_erankly_follow_directive':
			return erankly_sanitize_meta_enum( $value, array( 'inherit', 'follow', 'nofollow' ) );
		case '_erankly_archive_directive':
			return erankly_sanitize_meta_enum( $value, array( 'inherit', 'archive', 'noarchive' ) );
		case '_erankly_snippet_directive':
			return erankly_sanitize_meta_enum( $value, array( 'inherit', 'snippet', 'nosnippet' ) );
		case '_erankly_image_directive':
			return erankly_sanitize_meta_enum( $value, array( 'inherit', 'imageindex', 'noimageindex' ) );
		case '_erankly_max_image_preview':
			return erankly_sanitize_meta_enum( $value, array( 'inherit', 'none', 'standard', 'large' ) );
		case '_erankly_max_snippet':
		case '_erankly_max_video_preview':
			$value = trim( (string) $value );
			$value = preg_match( '/^-?\d+$/', $value ) ? (int) $value : -2;
			return $value >= -1 ? (string) $value : '';
		case '_erankly_primary_terms':
			return erankly_sanitize_primary_terms( $value );
		case '_erankly_legacy_editorial':
			return erankly_sanitize_legacy_editorial_data( $value );
		case '_erankly_schema_mode':
			return erankly_sanitize_meta_enum( $value, array( 'default', 'merge', 'replace', 'disabled' ) );
		case '_erankly_schema_blocks':
			return erankly_sanitize_schema_blocks( $value, false );
		case '_erankly_schema_disabled_types':
			return erankly_sanitize_schema_type_list( $value );
		case '_erankly_og_image_id':
		case '_erankly_twitter_image_id':
			return absint( $value );
		case '_erankly_noindex':
		case '_erankly_nofollow':
		case '_erankly_noarchive':
		case '_erankly_indexifembedded':
		case '_erankly_disable_sitemap':
		case '_erankly_exclude_search':
		case '_erankly_exclude_archive':
		case '_erankly_exclude_from_news':
			return (bool) $value;
		default:
			return apply_filters( 'erankly_sanitize_extension_meta', $value, $meta_key );
	}
}

/** Sanitizes an enum while treating an omitted value as inheritance/default. */
function erankly_sanitize_meta_enum( mixed $value, array $allowed ): string {
	$value = sanitize_key( (string) $value );

	return in_array( $value, $allowed, true ) ? $value : '';
}

/**
 * Sanitizes taxonomy-to-primary-term mappings.
 *
 * @return array<string,int>
 */
function erankly_sanitize_primary_terms( mixed $value ): array {
	$clean = array();

	foreach ( is_array( $value ) ? $value : array() as $taxonomy => $term_id ) {
		$taxonomy = sanitize_key( (string) $taxonomy );
		$term_id  = absint( $term_id );

		if ( '' !== $taxonomy && $term_id > 0 ) {
			$clean[ $taxonomy ] = $term_id;
		}
	}

	return $clean;
}

/**
 * Keeps plugin-specific editorial payloads losslessly enough for future reports.
 *
 * @return array<string,mixed>
 */
function erankly_sanitize_legacy_editorial_data( mixed $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	return map_deep( $value, static fn( mixed $item ): mixed => is_scalar( $item ) ? sanitize_text_field( (string) $item ) : $item );
}

/**
 * Sanitizes schema type names used to suppress automatic graph nodes.
 *
 * @return array<int,string>
 */
function erankly_sanitize_schema_type_list( mixed $value ): array {
	$types = array();

	foreach ( is_array( $value ) ? $value : array() as $type ) {
		$type = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $type );

		if ( is_string( $type ) && '' !== $type ) {
			$types[] = $type;
		}
	}

	return array_values( array_unique( $types ) );
}

/**
 * Sanitizes repeatable schema blocks.
 *
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

/** Sanitizes comma or newline-separated schema target IDs and slugs. */
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

/** Returns whether a sanitized schema block contains useful content. */
function erankly_schema_block_has_content( array $block ): bool {
	return isset( $block['fields']['custom_json'] ) && '' !== trim( (string) $block['fields']['custom_json'] );
}

/** Adds an admin settings error for invalid custom JSON-LD. */
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

function erankly_is_valid_custom_json_ld( string $json ): bool {
	return ! empty( erankly_decode_custom_json_ld( $json ) );
}

/**
 * Decodes custom JSON-LD into graph entries. Supports one object, an array of objects, or an object containing
 *
 * @graph.
 * @return array<int,array<string,mixed>>
 */
function erankly_decode_custom_json_ld( string $json ): array {
	$decoded = json_decode( $json, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
		return array();
	}

	return erankly_normalize_custom_json_ld_data( $decoded );
}

/** @return array<int,array<string,mixed>> */
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
