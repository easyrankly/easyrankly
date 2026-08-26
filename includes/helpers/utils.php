<?php
/**
 * Shared helpers: miscellaneous utilities.
 *
 * Loaded on SEO-rendering and rich admin surfaces.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a contextual taxonomy label for admin screens.
 *
 * @param WP_Taxonomy $taxonomy Taxonomy object.
 * @return string
 */
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

/**
 * Returns the primary post type label for a taxonomy.
 *
 * @param WP_Taxonomy $taxonomy Taxonomy object.
 * @return string
 */
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

/**
 * Returns the current canonical request URL without query strings.
 *
 * @return string
 */
function erankly_current_url(): string {
	global $wp;

	$path = isset( $wp->request ) ? ltrim( (string) $wp->request, '/' ) : '';
	$url  = home_url( $path );

	return user_trailingslashit( $url );
}

/**
 * Returns attachment image URL by ID.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Image size.
 * @return string
 */
function erankly_get_image_url( int $attachment_id, string $size = 'full' ): string {
	if ( $attachment_id <= 0 ) {
		return '';
	}

	$image = wp_get_attachment_image_url( $attachment_id, $size );

	return is_string( $image ) ? $image : '';
}

/**
 * Returns sameAs URLs from settings.
 *
 * @return array<int,string>
 */
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
 * @param string $body         XML response body.
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

	// Parse before output so malformed or externally declared XML never reaches
	// the response stream.
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

	$document->save( 'php://output' );
	exit;
}

/**
 * Renders a compact inline success/error status badge with a dashicon.
 *
 * @param string $message Status label.
 * @param bool   $success Whether the requirement is met.
 * @return void
 */
function erankly_render_inline_status_badge( string $message, bool $success ): void {
	$class = $success ? 'is-success' : 'is-error';

	printf(
		'<span class="erankly-inline-status %1$s"><svg class="erankly-inline-status-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path class="erankly-inline-status-icon-cross" d="M4 4l8 8m0-8-8 8"></path><path class="erankly-inline-status-icon-check" d="M3.25 8.25 6.5 11.5 12.75 4.75"></path></svg>%2$s</span>',
		esc_attr( $class ),
		esc_html( $message )
	);
}

/**
 * Renders an inline "WordPress Multisite: Detected / Not detected" status badge.
 *
 * Features that require a WordPress network (e.g. the multilingual module) use this
 * so the admin can immediately see whether the current install qualifies. When the
 * network is not detected the related controls must be rendered disabled so the
 * feature cannot be switched on, avoiding confusion and runtime errors.
 *
 * @return void
 */
function erankly_render_multisite_status(): void {
	erankly_render_inline_status_badge(
		is_multisite()
			? __( 'WordPress Multisite: Detected', 'easyrankly' )
			: __( 'WordPress Multisite: Not detected', 'easyrankly' ),
		is_multisite()
	);
}
