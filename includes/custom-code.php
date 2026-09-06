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

		$code = (string) apply_filters( $filter, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Callers pass only erankly_custom_* filters (head/body_open/body_close).

		if ( '' !== trim( $code ) ) {
			$out[] = $code;
		}
	}

	// Legacy fallback: the pre-block single snippet always printed everywhere.
	// It is appended (not exclusive) so no stored code ever goes silent during
	// the transition window — migration clears it once appended as a block.
	$legacy = trim( (string) erankly_get_setting( $legacy_key, '' ) );

	if ( '' !== $legacy ) {
		$legacy = (string) apply_filters( $filter, $legacy ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Same fixed erankly_custom_* filter as above.

		if ( '' !== trim( $legacy ) ) {
			$out[] = $legacy;
		}
	}

	return $out;
}

/**
 * Whether one code block targets the current request. Shares targeting with
 * global schema blocks via erankly_targeted_block_matches_request(), which
 * lives in the always-loaded helpers so custom code still works when the
 * frontend schema renderer is off.
 */
function erankly_custom_code_block_matches_request( array $block ): bool {
	return erankly_targeted_block_matches_request( $block );
}

function erankly_custom_code_matches_post_type_archive( array $block ): bool {
	return erankly_targeted_block_matches_post_type_archive( $block );
}

function erankly_custom_code_matches_singular( array $block ): bool {
	return erankly_targeted_block_matches_singular( $block );
}

function erankly_custom_code_target_list_contains_post( string $value, int $post_id ): bool {
	return erankly_target_list_contains_item( $value, 'post', $post_id );
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
