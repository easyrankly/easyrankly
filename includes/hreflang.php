<?php
/**
 * Hreflang alternate links.
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
	$alternates = array();

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
	$alternates = array();

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
