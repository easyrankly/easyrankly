<?php
/** Metadata registration and computed SEO values. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Title/description and hreflang live in dedicated files; required here because
// meta.php is always loaded, so their functions stay globally available.
require_once ERANKLY_PATH . 'includes/title-description.php';
require_once ERANKLY_PATH . 'includes/hreflang.php';
require_once ERANKLY_PATH . 'includes/schema-jsonld.php';

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

/**
 * Returns the meta keys an import may write for one object type.
 *
 * Every registered key is writable on posts, but terms and users only ever read a subset at runtime.
 * Importers write through this list so a source plugin that stores schema or primary terms on a taxonomy
 * cannot leave rows behind that nothing in EasyRankly can consume. Legacy keys superseded by the tri-state
 * robots directives are never imported either: they are read only as a fallback when the directive is absent,
 * so writing both would make the boolean inert while still costing a row.
 *
 * @param string $object_type One of post, term or user.
 * @return array<string,string> Meta key => value type.
 */
function erankly_importable_meta_keys( string $object_type ): array {
	$keys = erankly_get_meta_keys();
	unset(
		$keys['_erankly_noindex'],
		$keys['_erankly_nofollow'],
		$keys['_erankly_noarchive'],
		$keys['_erankly_social_image_url']
	);

	if ( 'post' === $object_type ) {
		return $keys;
	}

	$shared = array(
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
	);
	if ( 'term' === $object_type ) {
		$shared[] = '_erankly_disable_sitemap';
	}

	return array_intersect_key( $keys, array_flip( $shared ) );
}

function erankly_register_meta(): void {
	$meta = erankly_get_meta_keys();

	foreach ( $meta as $key => $type ) {
		$rest_schema = erankly_get_registered_meta_rest_schema( $key, $type );
		$args        = array(
			'type'              => $type,
			'single'            => true,
			'show_in_rest'      => $rest_schema,
			'auth_callback'     => static function ( bool $allowed, string $meta_key, int $object_id ): bool {
				unset( $allowed, $meta_key );
				return $object_id > 0 ? current_user_can( 'edit_post', $object_id ) : current_user_can( 'edit_posts' );
			},
			'sanitize_callback' => 'erankly_sanitize_registered_meta',
		);

		if ( 'array' === $type ) {
			$args['default'] = array();
		}

		register_post_meta( '', $key, $args );

		$term_args                  = $args;
		$term_args['auth_callback'] = static function ( bool $allowed, string $meta_key, int $object_id ): bool {
			unset( $allowed, $meta_key );
			// edit_term is the contextual meta capability; the generic
			// edit_terms check does not resolve for custom taxonomies.
			return $object_id > 0 && current_user_can( 'edit_term', $object_id );
		};

		register_term_meta( '', $key, $term_args );
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

		$default = '';
		if ( 'array' === $type || 'object' === $type ) {
			$default = array();
		} elseif ( 'boolean' === $type ) {
			$default = false;
		} elseif ( 'integer' === $type ) {
			$default = 0;
		}

		register_meta(
			'user',
			$key,
			array(
				'type'              => $type,
				'single'            => true,
				'default'           => $default,
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

add_action( 'rest_api_init', 'erankly_register_schema_blocks_rest_guards' );

/**
 * Prevents Gutenberg from wiping or rejecting schema blocks when the editor holds null.
 */
function erankly_register_schema_blocks_rest_guards(): void {
	add_filter( 'rest_request_before_callbacks', 'erankly_rest_drop_null_schema_blocks', 10, 3 );
	add_filter( 'update_post_metadata', 'erankly_skip_null_schema_blocks_meta', 10, 4 );

	$post_types = get_post_types( array( 'show_in_rest' => true ) );

	foreach ( $post_types as $post_type ) {
		add_filter( "rest_pre_insert_{$post_type}", 'erankly_rest_stash_schema_blocks_previous', 10, 2 );
	}
}

/**
 * @param mixed                 $response Result to send to the client.
 * @param array<string,mixed>   $handler  Route handler.
 * @param WP_REST_Request       $request  Request.
 * @return mixed
 */
function erankly_rest_drop_null_schema_blocks( mixed $response, array $handler, WP_REST_Request $request ): mixed {
	unset( $handler );

	$meta = $request->get_param( 'meta' );

	if ( is_array( $meta ) && array_key_exists( '_erankly_schema_blocks', $meta ) && null === $meta['_erankly_schema_blocks'] ) {
		unset( $meta['_erankly_schema_blocks'] );
		$request->set_param( 'meta', $meta );
	}

	return $response;
}

/**
 * @param mixed  $check      Whether to allow updating.
 * @param int    $object_id  Object ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 * @return mixed
 */
function erankly_skip_null_schema_blocks_meta( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
	unset( $object_id );

	if ( '_erankly_schema_blocks' === $meta_key && null === $meta_value ) {
		return true;
	}

	return $check;
}

/**
 * @param stdClass        $prepared Prepared post data.
 * @param WP_REST_Request $request  Request.
 * @return stdClass
 */
function erankly_rest_stash_schema_blocks_previous( stdClass $prepared, WP_REST_Request $request ): stdClass {
	$id = isset( $request['id'] ) ? absint( $request['id'] ) : 0;

	if ( $id > 0 ) {
		$GLOBALS['erankly_schema_blocks_previous'] = get_post_meta( $id, '_erankly_schema_blocks', true );
	}

	return $prepared;
}

/** @return bool|array<string,mixed> */
function erankly_get_registered_meta_rest_schema( string $key, string $type ): bool|array {
	if ( 'array' !== $type && 'object' !== $type ) {
		$schema = true;
	} else {
		$schema = array( 'type' => $type );

		if ( '_erankly_schema_blocks' === $key ) {
			$schema = erankly_schema_blocks_rest_schema();
		} elseif ( '_erankly_schema_disabled_types' === $key ) {
			$schema['items']   = array( 'type' => 'string' );
			$schema['default'] = array();
		} elseif ( '_erankly_primary_terms' === $key ) {
			$schema['additionalProperties'] = array( 'type' => 'integer' );
		} elseif ( 'object' === $type ) {
			$schema['additionalProperties'] = true;
		}

		$prepare = static function ( mixed $value ): mixed {
			return is_array( $value ) ? $value : array();
		};

		$schema = array(
			'schema'            => $schema,
			'prepare_callback'  => $prepare,
		);
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
		case '_erankly_schema_mode':
			return erankly_sanitize_meta_enum( $value, array( 'default', 'merge', 'replace', 'disabled' ) );
		case '_erankly_schema_blocks':
			$previous = $GLOBALS['erankly_schema_blocks_previous'] ?? array();

			return erankly_sanitize_schema_blocks( $value, false, is_array( $previous ) ? $previous : array() );
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
	// Casting an array to string is a PHP warning, and the result never
	// matches an allowed value anyway.
	$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

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
 * Sanitizes schema type names used to suppress automatic graph nodes.
 *
 * @return array<int,string>
 */
function erankly_sanitize_schema_type_list( mixed $value ): array {
	$types = array();
	$seen  = array();

	foreach ( is_array( $value ) ? $value : array() as $type ) {
		$type = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $type );

		if ( ! is_string( $type ) || '' === $type ) {
			continue;
		}

		$key = strtolower( $type );

		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$types[]      = $type;
	}

	return array_values( $types );
}

/**
 * Sanitizes repeatable schema blocks.
 *
 * @param bool                              $is_global Whether to sanitize global targeting fields.
 * @param array<int,array<string,mixed>>    $previous  Previously stored blocks, used when incoming JSON is invalid.
 * @return array<int,array<string,mixed>>
 */
function erankly_sanitize_schema_blocks( mixed $value, bool $is_global = false, array $previous = array() ): array {
	if ( null === $value ) {
		return $previous;
	}

	// The Settings API already unslashes the input; unslashing again would
	// corrupt backslashes inside custom JSON-LD (e.g. \" or \uXXXX escapes).
	$value      = is_array( $value ) ? $value : array();
	$contexts   = erankly_target_context_allowlist();
	$post_types = array_fill_keys( array_keys( erankly_get_public_post_types() ), true );
	$blocks     = array();

	foreach ( $value as $index => $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$type  = isset( $block['type'] ) ? sanitize_key( (string) $block['type'] ) : 'custom';
		$clean = array(
			'type'   => '' !== $type ? $type : 'custom',
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

			if ( empty( $clean['target_contexts'] ) ) {
				$clean['enabled'] = 0;
				erankly_add_schema_targeting_settings_error();
			} elseif (
				array( 'post_type_archive' ) === $clean['target_contexts']
				&& empty( $clean['target_post_types'] )
			) {
				$clean['enabled'] = 0;
				erankly_add_schema_targeting_settings_error( 'post_type_archive' );
			}
		}

		$incoming_json = isset( $block['fields']['custom_json'] ) ? erankly_sanitize_textarea( $block['fields']['custom_json'] ) : '';
		$previous_json = '';

		if ( isset( $previous[ $index ]['fields']['custom_json'] ) ) {
			$previous_json = (string) $previous[ $index ]['fields']['custom_json'];
		}

		if ( '' !== trim( $incoming_json ) ) {
			$validation = erankly_validate_custom_json_ld( $incoming_json );

			if ( ! $validation['valid'] ) {
				erankly_add_schema_json_settings_error( $validation['message'] );

				if ( '' !== trim( $previous_json ) && erankly_validate_custom_json_ld( $previous_json )['valid'] ) {
					$incoming_json = $previous_json;
				} else {
					continue;
				}
			}
		}

		$clean['fields']['custom_json'] = $incoming_json;

		$fields = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : array();
		$fields = apply_filters( 'erankly_sanitize_schema_block_fields', $clean['fields'], $fields, $is_global, $block );

		if ( is_array( $fields ) ) {
			$clean['fields'] = $fields;
			$clean['fields']['custom_json'] = $incoming_json;
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

/** The message shown when custom JSON-LD is rejected, shared by the settings screen and the editors. */
function erankly_invalid_json_ld_message(): string {
	return __( 'Custom JSON-LD was not saved because it is not valid JSON-LD. Every node needs a valid @type or @id. Use one object, an array of objects, or an object with @graph.', 'easyrankly' );
}

function erankly_schema_targeting_error_message( string $code = '' ): string {
	if ( 'post_type_archive' === $code ) {
		return __( 'A schema block that targets only post type archives was saved as disabled because no post types were selected.', 'easyrankly' );
	}

	return __( 'A schema block without a valid context was saved as disabled. Choose at least one place where it should appear.', 'easyrankly' );
}

/**
 * Reports invalid custom JSON-LD. add_settings_error() only surfaces on Settings API screens, so a block
 * rejected while saving a post or a term used to disappear without a word; the queued notice covers the
 * editors and the REST meta writer, which never reach settings_errors().
 */
function erankly_add_schema_json_settings_error( string $message = '' ): void {
	static $added = false;

	if ( $added ) {
		return;
	}

	$added   = true;
	$message = '' !== $message ? $message : erankly_invalid_json_ld_message();

	if ( function_exists( 'add_settings_error' ) ) {
		add_settings_error( ERANKLY_OPTION, 'erankly_invalid_json_ld', $message, 'error' );
	}

	$user_id = get_current_user_id();

	if ( $user_id > 0 ) {
		set_transient( 'erankly_invalid_json_ld_' . $user_id, $message, 5 * MINUTE_IN_SECONDS );
	}
}

function erankly_add_schema_targeting_settings_error( string $code = '' ): void {
	static $added = array();

	$key = '' === $code ? 'contexts' : $code;

	if ( isset( $added[ $key ] ) ) {
		return;
	}

	$added[ $key ] = true;

	if ( function_exists( 'add_settings_error' ) ) {
		add_settings_error( ERANKLY_OPTION, 'erankly_schema_targeting_' . $key, erankly_schema_targeting_error_message( $code ), 'error' );
	}
}

/** Prints and clears the queued invalid JSON-LD notice on the next admin screen. */
function erankly_render_invalid_json_ld_notice(): void {
	$user_id = get_current_user_id();

	$notice = get_transient( 'erankly_invalid_json_ld_' . $user_id );

	if ( ! $notice ) {
		return;
	}

	delete_transient( 'erankly_invalid_json_ld_' . $user_id );

	// The settings screen prints the same message through settings_errors().
	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();

		if ( $screen instanceof WP_Screen && str_contains( (string) $screen->id, 'erankly' ) ) {
			return;
		}
	}

	$message = is_string( $notice ) && '' !== $notice ? $notice : erankly_invalid_json_ld_message();

	printf(
		'<div class="notice notice-error" id="erankly-invalid-json-ld-notice" role="alert"><p>%s</p></div>',
		esc_html( $message )
	);
}

function erankly_is_valid_custom_json_ld( string $json ): bool {
	return erankly_validate_custom_json_ld( $json )['valid'];
}

/**
 * Decodes custom JSON-LD into graph entries. Supports one object, an array of objects, or an object containing
 * @graph. Nodes that fail Schema.org-minimum validation are rejected as a document, not silently dropped.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_decode_custom_json_ld( string $json ): array {
	$result = erankly_validate_custom_json_ld( $json );

	return $result['valid'] ? $result['nodes'] : array();
}

/** @return array<int,array<string,mixed>> */
function erankly_normalize_custom_json_ld_data( array $decoded ): array {
	return erankly_validate_json_ld_nodes(
		isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] )
			? $decoded['@graph']
			: ( erankly_array_is_list( $decoded ) ? $decoded : array( $decoded ) )
	)['nodes'];
}
