<?php
/** Shared helpers: miscellaneous utilities. Loaded on SEO-rendering and rich admin surfaces. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_get_taxonomy_admin_label( WP_Taxonomy $taxonomy ): string {
	$label = $taxonomy->labels->singular_name;
	$owner = erankly_get_taxonomy_owner_label( $taxonomy );

	if ( '' === $owner ) {
		return $label;
	}

	return sprintf(
		/* translators: 1: owner post type label, 2: taxonomy label. */
		__( '%1$s: %2$s', 'easyrankly' ),
		$owner,
		$label
	);
}

function erankly_get_taxonomy_owner_label( WP_Taxonomy $taxonomy ): string {
	$object_types = array_values( array_filter( array_map( 'sanitize_key', (array) $taxonomy->object_type ) ) );

	if ( empty( $object_types ) ) {
		return '';
	}

	$preferred = in_array( 'product', $object_types, true ) ? 'product' : $object_types[0];
	$object    = get_post_type_object( $preferred );

	if ( ! $object instanceof WP_Post_Type ) {
		return '';
	}

	return $object->labels->name;
}

function erankly_current_url(): string {
	global $wp;

	$path = isset( $wp->request ) ? ltrim( (string) $wp->request, '/' ) : '';
	$url  = home_url( $path );

	return user_trailingslashit( $url );
}

function erankly_get_image_url( int $attachment_id, string $size = 'full' ): string {
	if ( $attachment_id <= 0 ) {
		return '';
	}

	$image = wp_get_attachment_image_url( $attachment_id, $size );

	return is_string( $image ) ? $image : '';
}

/** @return array<int,string> */
function erankly_get_social_profiles(): array {
	$profiles = (string) erankly_get_setting( 'social_profiles', '' );
	$lines    = preg_split( '/\R/', $profiles );

	if ( ! is_array( $lines ) ) {
		return array();
	}

	$urls = array();

	foreach ( $lines as $line ) {
		$url = erankly_sanitize_absolute_url( $line );

		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Emits a validated XML response and exits.
 *
 * @param string $content_type Expected XML content type.
 * @return never
 */
function erankly_send_response( string $body, string $content_type ) {
	if (
		'application/xml' !== $content_type
		|| '' === trim( $body )
		|| str_contains( $body, '<!DOCTYPE' )
		|| str_contains( $body, '<!ENTITY' )
		|| ! class_exists( 'DOMDocument' )
	) {
		status_header( 500 );
		exit;
	}

	$etag = '"' . hash( 'sha256', $body ) . '"';
	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) === $etag ) {
		status_header( 304 );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=300, stale-while-revalidate=60' );
		exit;
	}

	// Keep the final parse even though EasyRankly builds the base XML internally:
	// sitemap entries and URLs pass through public extension filters, so the
	// completed document is not fully trusted. Conditional ETag hits return above
	// without paying this cost; fresh responses favor fail-closed XML output.
	$previous_errors              = libxml_use_internal_errors( true );
	$document                     = new DOMDocument();
	$document->preserveWhiteSpace = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOMDocument property name.
	$is_valid_xml                 = $document->loadXML( $body, LIBXML_NONET );

	libxml_clear_errors();
	libxml_use_internal_errors( $previous_errors );

	if ( ! $is_valid_xml ) {
		status_header( 500 );
		exit;
	}

	status_header( 200 );
	header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );
	header( 'X-Robots-Tag: noindex, follow', true );
	header( 'Cache-Control: public, max-age=300, stale-while-revalidate=60' );
	header( 'ETag: ' . $etag );

	$document->save( 'php://output' );
	exit;
}
