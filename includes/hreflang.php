<?php
/** Hreflang alternate links. Loaded by meta.php (always required), so these functions are globally available wherever the head metadata is built. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_render_hreflang_alternates(): void {
	$provider = erankly_get_multilingual_provider();

	if ( ! $provider instanceof ERankly_Multilingual_Provider_Interface
		|| $provider->get_id() !== erankly_get_hreflang_output_owner() ) {
		return;
	}

	foreach ( erankly_get_hreflang_alternates() as $hreflang => $url ) {
		printf(
			'<link rel="alternate" hreflang="%1$s" href="%2$s">' . "\n",
			esc_attr( $hreflang ),
			esc_url( $url )
		);
	}
}

/** @return array<string,string> */
function erankly_get_hreflang_alternates(): array {
	$provider    = erankly_get_multilingual_provider();
	$context     = erankly_get_multilingual_context();
	$provider_id = $provider instanceof ERankly_Multilingual_Provider_Interface ? $provider->get_id() : '';
	$alternates  = erankly_get_provider_alternates( false );

	/** Filters hreflang alternate URLs. Expected shape: array( 'it-IT' => 'https://example.com/it/pagina/', 'x-default' => 'https://example.com/' ). */
	$alternates = apply_filters( 'erankly_hreflang_alternates', $alternates, $context, $provider_id );

	return erankly_clean_hreflang_alternates( $alternates );
}

/**
 * Returns the language alternates a visitor can navigate to for the current request. Same shape as
 * erankly_get_hreflang_alternates(), but built for human navigation rather than search-engine signalling:
 * published translations are included even when they are noindex. Used by visitor-facing language navigation.
 * Never use this set for hreflang output.
 *
 * @return array<string,string>
 */
function erankly_get_navigable_hreflang_alternates(): array {
	$provider    = erankly_get_multilingual_provider();
	$context     = erankly_get_multilingual_context();
	$provider_id = $provider instanceof ERankly_Multilingual_Provider_Interface ? $provider->get_id() : '';
	$alternates  = erankly_get_provider_alternates( true );

	/**
	 * Filters the visitor-navigable language alternates. Expected shape: array( 'it-IT' =>
	 * 'https://example.com/it/pagina/', 'x-default' => 'https://example.com/' ).
	 */
	$alternates = apply_filters( 'erankly_navigable_hreflang_alternates', $alternates, $context, $provider_id );

	return erankly_clean_hreflang_alternates( $alternates );
}

function erankly_is_valid_hreflang_tag( string $hreflang ): bool {
	$hreflang = strtolower( trim( $hreflang ) );

	if ( 'x-default' === $hreflang ) {
		return true;
	}

	return (bool) preg_match( '/^[a-z]{2,3}(?:-[a-z]{2}|-[0-9]{3})?(?:-[a-z0-9]{5,8})*$/', $hreflang );
}

/**
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

		if ( '' === $hreflang || ! erankly_is_valid_hreflang_tag( $hreflang ) || ! erankly_is_absolute_http_url( $url ) ) {
			continue;
		}

		$clean_key = strtolower( $hreflang );
		if ( ! isset( $clean[ $clean_key ] ) ) {
			$clean[ $clean_key ] = $url;
		}
	}

	return $clean;
}
