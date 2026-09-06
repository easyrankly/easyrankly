<?php
/**
 * Shared helpers: input sanitization. Common text and URL primitives loaded early on every request.
 * LocalBusiness and schema-specific sanitizers live in sanitization-schema.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes a plain text field. Expects an already-unslashed value: callers reading from $_POST must
 * wp_unslash() first. Unslashing here too would corrupt literal backslashes.
 */
function erankly_sanitize_text( mixed $value ): string {
	return sanitize_text_field( (string) $value );
}

/** Sanitizes textarea text without markup. Expects an already-unslashed value (see erankly_sanitize_text()). */
function erankly_sanitize_textarea( mixed $value ): string {
	return sanitize_textarea_field( (string) $value );
}

/** @param mixed $value Raw handle or profile URL. */
function erankly_sanitize_twitter_handle( mixed $value ): string {
	$value = trim( erankly_sanitize_text( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( preg_match( '#^(?:https?://)?(?:www\.)?(?:x|twitter)\.com/#i', $value ) ) {
		$url   = str_starts_with( $value, 'http' ) ? $value : 'https://' . $value;
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$value = (string) strtok( trim( $path, '/' ), '/' );
	}

	$handle = ltrim( $value, '@' );
	$handle = preg_replace( '/[^A-Za-z0-9_]/', '', $handle );
	$handle = substr( (string) $handle, 0, 15 );

	return '' === $handle ? '' : '@' . $handle;
}

/** Sanitizes a URL field. */
function erankly_sanitize_url( mixed $value ): string {
	$value = trim( (string) $value );

	return '' === $value ? '' : esc_url_raw( $value );
}

/** Sanitizes an absolute HTTP(S) URL. */
function erankly_sanitize_absolute_url( mixed $value ): string {
	$url = erankly_sanitize_url( $value );

	if ( '' === $url ) {
		return '';
	}

	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	$host   = wp_parse_url( $url, PHP_URL_HOST );

	return is_string( $scheme ) && is_string( $host ) && in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ? $url : '';
}

/**
 * Sanitizes a URL field that may contain EasyRankly {{variables}}. Literal URLs go through esc_url_raw().
 * Templated URLs are finalized only after variable replacement, so preserve placeholders while still removing
 * invalid text input and disallowed protocols at save time.
 */
function erankly_sanitize_url_template( mixed $value ): string {
	$value = trim( erankly_sanitize_text( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( ! str_contains( $value, '{{' ) ) {
		return erankly_sanitize_url( $value );
	}

	$value = (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ): string {
			return '{{' . strtolower( (string) $matches[1] ) . '}}';
		},
		$value
	);

	return trim( wp_kses_bad_protocol( $value, wp_allowed_protocols() ) );
}

/**
 * Drops a stored media attachment ID when an explicitly set, concrete image URL no longer matches it. The URL
 * template is the primary source at runtime (see erankly_get_og_image() and erankly_get_organization_logo_url()),
 * so keeping a divergent ID would only preserve a silent second source of truth. Consistent media-picker pairs
 * and {{variable}} templates are always kept.
 */
function erankly_drop_stale_media_id( int $attachment_id, string $url ): int {
	if ( $attachment_id <= 0 || '' === $url || str_contains( $url, '{{' ) || ! function_exists( 'erankly_get_image_url' ) ) {
		return $attachment_id;
	}

	$attachment_url = erankly_get_image_url( $attachment_id, 'full' );

	return ( '' !== $attachment_url && $attachment_url !== $url ) ? 0 : $attachment_id;
}

/** Sanitizes a newline-separated list of absolute HTTP(S) URLs. */
function erankly_sanitize_url_list( mixed $value ): string {
	$value = erankly_sanitize_textarea( $value );
	$lines = preg_split( '/\R/', $value );

	if ( ! is_array( $lines ) ) {
		return '';
	}

	$urls = array();

	foreach ( $lines as $line ) {
		$url = erankly_sanitize_absolute_url( $line );

		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}

	return implode( "\n", array_values( array_unique( $urls ) ) );
}

/**
 * Sanitizes a custom code snippet (HEAD / BODY). Preserves raw HTML/JS verbatim
 * so tracking pixels, verification meta tags and inline scripts keep working.
 * Only users with unfiltered_html (or system contexts without a current user,
 * such as WP-Cron/CLI imports) may persist markup.
 *
 * Expects an already-unslashed value (see erankly_sanitize_text()). Prefer
 * erankly_sanitize_custom_code_field() from settings saves so unrelated panel
 * saves by low-privilege users preserve the stored value instead of wiping it.
 */
function erankly_sanitize_custom_code( mixed $value ): string {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return '';
	}

	// Drop invalid UTF-8 (prevents option-table corruption) and null bytes.
	// Everything else is intentional code and must survive verbatim.
	if ( function_exists( 'wp_check_invalid_utf8' ) ) {
		$value = wp_check_invalid_utf8( $value, true );
	}
	$value = (string) preg_replace( '/\x00+/', '', $value );
	$value = trim( $value );

	if ( '' === $value ) {
		return '';
	}

	// Bound the option size: 100 KB is plenty for snippets and keeps the
	// settings row small. Truncate loudly instead of silently so the admin
	// knows the tail was dropped rather than debugging missing tags.
	if ( strlen( $value ) > erankly_custom_code_max_bytes() ) {
		$value = erankly_truncate_custom_code_bytes( $value, erankly_custom_code_max_bytes() );

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_custom_code_too_long',
				__( 'Custom code exceeds 100 KB and was truncated.', 'easyrankly' ),
				'warning'
			);
		}
	}

	// System contexts (WP-Cron import batches, WP-CLI, activation) run without a
	// current user; the payload there comes from a trusted export initiated by an
	// admin, so preserve it instead of wiping.
	$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

	if ( 0 !== (int) $user_id && ! current_user_can( 'unfiltered_html' ) ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_custom_code_capability',
				__( 'Custom code was not saved because your user role cannot post unfiltered HTML.', 'easyrankly' ),
				'error'
			);
		}

		return '';
	}

	return $value;
}

/**
 * Truncates already-valid UTF-8 code by bytes without leaving an incomplete
 * multibyte sequence in the option value.
 */
function erankly_truncate_custom_code_bytes( string $value, int $limit ): string {
	if ( $limit < 1 ) {
		return '';
	}
	if ( strlen( $value ) <= $limit ) {
		return $value;
	}
	if ( function_exists( 'mb_strcut' ) ) {
		return (string) mb_strcut( $value, 0, $limit, 'UTF-8' );
	}

	$value = substr( $value, 0, $limit );
	while ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
		$value = substr( $value, 0, -1 );
	}

	return $value;
}

/**
 * Sanitizes the enable_custom_code toggle. Like the code fields, a user
 * without unfiltered_html must not be able to flip the module (e.g. by
 * enabling it empty or disabling it to hide injected code from review).
 * System contexts without a current user (WP-Cron/CLI) pass through.
 */
function erankly_sanitize_custom_code_toggle( mixed $raw_input ): int {
	$new      = ! empty( $raw_input ) ? 1 : 0;
	$user_id  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$stored   = function_exists( 'erankly_get_stored_settings' ) ? (int) ! empty( erankly_get_stored_settings()['enable_custom_code'] ) : 0;

	if ( 0 !== $user_id && ! current_user_can( 'unfiltered_html' ) ) {
		if ( $new !== $stored && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_custom_code_capability',
				__( 'Custom code was not saved because your user role cannot post unfiltered HTML.', 'easyrankly' ),
				'error'
			);
		}

		return $stored;
	}

	return $new;
}

/**
 * Sanitizes one custom code field with stored-value preservation.
 *
 * The merged $input always carries the stored snippet (see
 * erankly_merge_settings_submission()), even when the active panel is
 * Features. Re-sanitizing that carried-over value with a capability check
 * would wipe existing code whenever a user without unfiltered_html saves an
 * unrelated panel. Instead: privileged/system contexts sanitize the new
 * input, while low-privilege users keep the stored value (with an error only
 * when they actually attempted a change).
 *
 * @param mixed  $raw_input Raw merged input value, or null when absent.
 * @param string $key       One of head_code, body_open_code, body_close_code.
 */
function erankly_sanitize_custom_code_field( mixed $raw_input, string $key ): string {
	$stored_all   = function_exists( 'erankly_get_stored_settings' ) ? erankly_get_stored_settings() : array();
	$stored_value = isset( $stored_all[ $key ] ) ? (string) $stored_all[ $key ] : '';
	$user_id      = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

	if ( null === $raw_input ) {
		return '';
	}

	if ( 0 !== $user_id && ! current_user_can( 'unfiltered_html' ) ) {
		if ( trim( (string) $raw_input ) !== $stored_value && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_custom_code_capability',
				__( 'Custom code was not saved because your user role cannot post unfiltered HTML.', 'easyrankly' ),
				'error'
			);
		}

		return $stored_value;
	}

	return erankly_sanitize_custom_code( $raw_input );
}

/** Maximum code snippets per location. Bounds the autoloaded option size. */
function erankly_custom_code_max_blocks(): int {
	return 10;
}

/** Maximum bytes per snippet. Matches the legacy single-snippet cap so migration is lossless. */
function erankly_custom_code_max_bytes(): int {
	return 100 * 1024;
}

/** Maximum combined stored code per output location. */
function erankly_custom_code_max_total_bytes(): int {
	return 100 * 1024;
}

/** @return array<string,bool> */
function erankly_custom_code_context_allowlist(): array {
	return array_fill_keys( array( 'front_page', 'posts_page', 'singular', 'post_type_archive', 'search', 'taxonomy', 'author', 'date', '404' ), true );
}

/**
 * Sanitizes one location's repeatable code blocks. Shape mirrors global
 * schema blocks (enabled + target_contexts + target_post_types +
 * include/exclude items) so the admin targeting UI and the frontend
 * matcher share semantics; only the payload differs (raw `code` instead
 * of JSON-LD `fields`). Empty-code blocks are dropped.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_sanitize_custom_code_blocks( mixed $value ): array {
	$value      = is_array( $value ) ? array_values( $value ) : array();
	$contexts   = erankly_custom_code_context_allowlist();
	$post_types = array_fill_keys( array_keys( erankly_get_public_post_types() ), true );
	$max_blocks      = erankly_custom_code_max_blocks();
	$max_bytes       = erankly_custom_code_max_bytes();
	$remaining_bytes = erankly_custom_code_max_total_bytes();
	$blocks          = array();

	if ( count( $value ) > $max_blocks ) {
		$value = array_slice( $value, 0, $max_blocks );

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_custom_code_too_many',
				__( 'Only the first 10 code snippets per location were kept.', 'easyrankly' ),
				'warning'
			);
		}
	}

	foreach ( $value as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$clean = array(
			'enabled'           => ! empty( $block['enabled'] ) ? 1 : 0,
			'code'              => '',
			'target_contexts'   => array(),
			'target_post_types' => array(),
			'include_items'     => isset( $block['include_items'] ) ? erankly_sanitize_schema_target_items( $block['include_items'] ) : '',
			'exclude_items'     => isset( $block['exclude_items'] ) ? erankly_sanitize_schema_target_items( $block['exclude_items'] ) : '',
		);

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

		// Raw snippet: capability-gated, UTF-8 cleaned, per-block and
		// per-location byte caps keep the autoloaded settings option bounded.
		$raw = isset( $block['code'] ) ? trim( (string) $block['code'] ) : '';

		if ( '' === $raw ) {
			continue;
		}

		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$raw = wp_check_invalid_utf8( $raw, true );
		}
		$raw = trim( (string) preg_replace( '/\x00+/', '', (string) $raw ) );

		if ( '' === $raw ) {
			continue;
		}

		if ( $remaining_bytes < 1 ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					ERANKLY_OPTION,
					'erankly_custom_code_total_too_long',
					__( 'Custom code for this location exceeds 100 KB; additional snippets were not saved.', 'easyrankly' ),
					'warning'
				);
			}
			break;
		}

		$allowed_bytes = min( $max_bytes, $remaining_bytes );
		if ( strlen( $raw ) > $allowed_bytes ) {
			$raw = erankly_truncate_custom_code_bytes( $raw, $allowed_bytes );

			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					ERANKLY_OPTION,
					'erankly_custom_code_too_long',
					$allowed_bytes < $max_bytes
						? __( 'Custom code for this location exceeds 100 KB and was truncated.', 'easyrankly' )
						: __( 'A code snippet exceeds 100 KB and was truncated.', 'easyrankly' ),
					'warning'
				);
			}
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( 0 !== $user_id && ! current_user_can( 'unfiltered_html' ) ) {
			// Low-privilege submissions never reach here through the field
			// wrapper (stored blocks are returned instead); this is a
			// defense-in-depth fallback for direct calls.
			continue;
		}

		$clean['code'] = $raw;
		$blocks[]      = $clean;
		$remaining_bytes -= strlen( $raw );
	}

	return array_values( $blocks );
}

/**
 * Sanitizes one location's blocks with stored-value preservation (see
 * erankly_sanitize_custom_code_field()). Low-privilege users keep stored
 * blocks; only an actual change attempt raises the capability error.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_sanitize_custom_code_blocks_field( mixed $raw_input, string $key ): array {
	$stored_all    = function_exists( 'erankly_get_stored_settings' ) ? erankly_get_stored_settings() : array();
	$stored_blocks = isset( $stored_all[ $key ] ) && is_array( $stored_all[ $key ] ) ? array_values( $stored_all[ $key ] ) : array();
	$user_id       = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

	if ( null === $raw_input ) {
		return array();
	}

	if ( 0 !== $user_id && ! current_user_can( 'unfiltered_html' ) ) {
		$attempted = is_array( $raw_input ) ? array_values( $raw_input ) : array();

		if ( wp_json_encode( $attempted ) !== wp_json_encode( $stored_blocks ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_custom_code_capability',
				__( 'Custom code was not saved because your user role cannot post unfiltered HTML.', 'easyrankly' ),
				'error'
			);
		}

		return $stored_blocks;
	}

	return erankly_sanitize_custom_code_blocks( $raw_input );
}

/** Builds a full-visibility block for legacy single-snippet migration. */
function erankly_custom_code_migrated_block( string $code ): array {
	return array(
		'enabled'           => 1,
		'code'              => $code,
		'target_contexts'   => array_keys( erankly_custom_code_context_allowlist() ),
		'target_post_types' => array_keys( erankly_get_public_post_types() ),
		'include_items'     => '',
		'exclude_items'     => '',
	);
}

/** Produces a compact SEO string. */
function erankly_trim_text( string $value, int $limit = 160 ): string {
	$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );

	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

	if ( '' === $value || $length <= $limit ) {
		return $value;
	}

	$excerpt = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit - 1 ) : substr( $value, 0, $limit - 1 );

	return rtrim( $excerpt, " \t\n\r\0\x0B.,;:-" );
}

/** Produces a compact SEO string without applying a character limit. */
function erankly_normalize_seo_text( string $value ): string {
	$value = preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $value ) ) );
	if ( is_string( $value ) ) {
		$value = preg_replace( '/(?:\s*(?:-|\||–|—)\s*)+$/u', '', $value );
	}

	return is_string( $value ) ? trim( $value ) : '';
}
