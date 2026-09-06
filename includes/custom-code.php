<?php
/** Custom code module: location-targeted HEAD / BODY output. Loaded only when the feature is enabled. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers frontend output hooks for custom code snippets. */
function erankly_custom_code_boot(): void {
	add_action( 'wp_head', 'erankly_render_custom_head_code', 20 );
	add_action( 'wp_body_open', 'erankly_render_custom_body_open_code', 5 );
	add_action( 'wp_footer', 'erankly_render_custom_body_close_code', 20 );
}

/**
 * Whether custom code may print on the current request.
 *
 * Fail-closed list: only plain frontend HTML for visitors. Excludes admin,
 * AJAX/CRON/REST/XML-RPC, feeds, embeds, robots, trackbacks and previews
 * (Customizer / post preview) so tracking pixels never pollute stats and
 * code never runs in privileged or machine-readable contexts.
 */
function erankly_custom_code_should_output(): bool {
	if ( ! erankly_custom_code_enabled() ) {
		return false;
	}

	if ( function_exists( 'erankly_is_frontend_html_request' ) ) {
		if ( ! erankly_is_frontend_html_request() ) {
			return false;
		}
	} elseif ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
		return false;
	}

	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}

	if ( is_feed() || is_robots() || is_trackback() || is_embed() ) {
		return false;
	}

	if ( is_preview() || is_customize_preview() ) {
		return false;
	}

	return true;
}

/**
 * Returns the snippets for one location that match the current request.
 * Falls back to the legacy single snippet (pre-block UI) when no blocks
 * exist yet, so upgraded installs keep working before the first resave.
 *
 * @param string $blocks_key One of head_code_blocks, body_open_code_blocks, body_close_code_blocks.
 * @param string $legacy_key One of head_code, body_open_code, body_close_code.
 * @param string $filter     Filter applied to each snippet before output.
 * @return string[]
 */
function erankly_get_matching_custom_code( string $blocks_key, string $legacy_key, string $filter ): array {
	$stored = erankly_get_stored_settings();
	$blocks = isset( $stored[ $blocks_key ] ) && is_array( $stored[ $blocks_key ] ) ? $stored[ $blocks_key ] : array();
	$out    = array();

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) || empty( $block['enabled'] ) ) {
			continue;
		}

		$code = isset( $block['code'] ) ? trim( (string) $block['code'] ) : '';

		if ( '' === $code || ! erankly_custom_code_block_matches_request( $block ) ) {
			continue;
		}

		$code = (string) apply_filters( $filter, $code );

		if ( '' !== trim( $code ) ) {
			$out[] = $code;
		}
	}

	// Legacy fallback: the pre-block single snippet always printed everywhere.
	// It is appended (not exclusive) so no stored code ever goes silent during
	// the transition window — migration clears it once appended as a block.
	$legacy = trim( (string) erankly_get_setting( $legacy_key, '' ) );

	if ( '' !== $legacy ) {
		$legacy = (string) apply_filters( $filter, $legacy );

		if ( '' !== trim( $legacy ) ) {
			$out[] = $legacy;
		}
	}

	return $out;
}

/**
 * Whether one code block targets the current request. Semantics intentionally
 * mirror erankly_global_schema_block_matches_request(): empty contexts never
 * match; singular/archive matches additionally require the post type (and
 * honor include/exclude ID/slug lists for singular). Kept self-contained so
 * custom code works even when the schema module file is not loaded (e.g. an
 * external SEO plugin owns head output).
 */
function erankly_custom_code_block_matches_request( array $block ): bool {
	$contexts = isset( $block['target_contexts'] ) && is_array( $block['target_contexts'] ) ? $block['target_contexts'] : array();

	if ( empty( $contexts ) ) {
		return false;
	}

	if ( in_array( 'taxonomy', $contexts, true ) && ( is_category() || is_tag() || is_tax() ) ) {
		return true;
	}

	if ( in_array( 'author', $contexts, true ) && is_author() ) {
		return true;
	}

	if ( in_array( 'date', $contexts, true ) && is_date() ) {
		return true;
	}

	if ( in_array( '404', $contexts, true ) && is_404() ) {
		return true;
	}

	if ( in_array( 'front_page', $contexts, true ) && is_front_page() ) {
		return true;
	}

	if ( in_array( 'posts_page', $contexts, true ) && is_home() && ! is_front_page() ) {
		return true;
	}

	if ( in_array( 'search', $contexts, true ) && is_search() ) {
		return true;
	}

	if ( in_array( 'post_type_archive', $contexts, true ) && erankly_custom_code_matches_post_type_archive( $block ) ) {
		return true;
	}

	if ( in_array( 'singular', $contexts, true ) && erankly_custom_code_matches_singular( $block ) ) {
		return true;
	}

	return false;
}

function erankly_custom_code_matches_post_type_archive( array $block ): bool {
	if ( ! is_post_type_archive() ) {
		return false;
	}

	$target_post_types = isset( $block['target_post_types'] ) && is_array( $block['target_post_types'] ) ? $block['target_post_types'] : array();

	if ( empty( $target_post_types ) ) {
		return false;
	}

	$current_post_type = get_query_var( 'post_type' );

	if ( is_array( $current_post_type ) ) {
		foreach ( $current_post_type as $post_type ) {
			if ( in_array( (string) $post_type, $target_post_types, true ) ) {
				return true;
			}
		}

		return false;
	}

	if ( is_string( $current_post_type ) && '' !== $current_post_type ) {
		return in_array( $current_post_type, $target_post_types, true );
	}

	$queried = get_queried_object();

	return $queried instanceof WP_Post_Type && in_array( $queried->name, $target_post_types, true );
}

function erankly_custom_code_matches_singular( array $block ): bool {
	if ( ! is_singular() ) {
		return false;
	}

	$post_id = get_queried_object_id();

	if ( $post_id <= 0 ) {
		return false;
	}

	$target_post_types = isset( $block['target_post_types'] ) && is_array( $block['target_post_types'] ) ? $block['target_post_types'] : array();
	$post_type         = get_post_type( $post_id );

	if ( empty( $target_post_types ) || ! is_string( $post_type ) || ! in_array( $post_type, $target_post_types, true ) ) {
		return false;
	}

	if ( erankly_custom_code_target_list_contains_post( isset( $block['exclude_items'] ) ? (string) $block['exclude_items'] : '', $post_id ) ) {
		return false;
	}

	$include_items = isset( $block['include_items'] ) ? (string) $block['include_items'] : '';

	if ( '' === trim( $include_items ) ) {
		return true;
	}

	return erankly_custom_code_target_list_contains_post( $include_items, $post_id );
}

function erankly_custom_code_target_list_contains_post( string $value, int $post_id ): bool {
	$items = preg_split( '/[\r\n,]+/', $value );

	if ( ! is_array( $items ) || $post_id <= 0 ) {
		return false;
	}

	$post = get_post( $post_id );
	$slug = $post instanceof WP_Post ? $post->post_name : '';

	foreach ( $items as $item ) {
		$item = trim( (string) $item );

		if ( '' === $item ) {
			continue;
		}

		if ( ctype_digit( $item ) && absint( $item ) === $post_id ) {
			return true;
		}

		if ( '' !== $slug && sanitize_title( $item ) === $slug ) {
			return true;
		}
	}

	return false;
}

/** Prints the HEAD snippets verbatim (once per request). */
function erankly_render_custom_head_code(): void {
	static $rendered = false;

	if ( $rendered || ! erankly_custom_code_should_output() ) {
		return;
	}

	$snippets = erankly_get_matching_custom_code( 'head_code_blocks', 'head_code', 'erankly_custom_head_code' );

	if ( array() === $snippets ) {
		return;
	}

	$rendered = true;

	echo "\n<!-- EasyRankly head code -->\n" . implode( "\n", $snippets ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional verbatim custom code; capability-gated at save time.
}

/** Prints the start-of-BODY snippets verbatim (once per request). */
function erankly_render_custom_body_open_code(): void {
	static $rendered = false;

	if ( $rendered || ! erankly_custom_code_should_output() ) {
		return;
	}

	$snippets = erankly_get_matching_custom_code( 'body_open_code_blocks', 'body_open_code', 'erankly_custom_body_open_code' );

	if ( array() === $snippets ) {
		return;
	}

	$rendered = true;

	echo "\n<!-- EasyRankly body open code -->\n" . implode( "\n", $snippets ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional verbatim custom code; capability-gated at save time.
}

/** Prints the end-of-BODY snippets verbatim (once per request). */
function erankly_render_custom_body_close_code(): void {
	static $rendered = false;

	if ( $rendered || ! erankly_custom_code_should_output() ) {
		return;
	}

	$snippets = erankly_get_matching_custom_code( 'body_close_code_blocks', 'body_close_code', 'erankly_custom_body_close_code' );

	if ( array() === $snippets ) {
		return;
	}

	$rendered = true;

	echo "\n<!-- EasyRankly body close code -->\n" . implode( "\n", $snippets ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional verbatim custom code; capability-gated at save time.
}
