<?php
/**
 * Shared helpers — miscellaneous utilities.
 *
 * Part of the helpers.php loader; always loaded early on every request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns public post types supported by EasyRankly.
 *
 * @return array<string,WP_Post_Type>
 */
function erankly_get_public_post_types(): array {
	$post_types = get_post_types(
		array(
			'public' => true,
		),
		'objects'
	);

	unset( $post_types['attachment'] );

	/**
	 * Filters public post types handled by the plugin.
	 *
	 * @param array<string,WP_Post_Type> $post_types Public post type objects.
	 */
	return apply_filters( 'erankly_post_types', $post_types );
}

/**
 * Returns public taxonomies supported by EasyRankly.
 *
 * @return array<string,WP_Taxonomy>
 */
function erankly_get_public_taxonomies(): array {
	$taxonomies = get_taxonomies(
		array(
			'public' => true,
		),
		'objects'
	);

	unset(
		$taxonomies['post_format'],
		$taxonomies['product_shipping_class']
	);

	/**
	 * Filters public taxonomies handled by the plugin.
	 *
	 * @param array<string,WP_Taxonomy> $taxonomies Public taxonomy objects.
	 */
	return apply_filters( 'erankly_taxonomies', $taxonomies );
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
 * Returns whether a request is likely a frontend HTML request.
 *
 * @return bool
 */
function erankly_is_frontend_html_request(): bool {
	return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
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
	if ( is_multisite() ) {
		printf(
			'<span class="erankly-ms-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#1a7f37;"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
			esc_html__( 'WordPress Multisite: Detected', 'easyrankly' )
		);
		return;
	}

	printf(
		'<span class="erankly-ms-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#b32d2e;"><span class="dashicons dashicons-no-alt" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
		esc_html__( 'WordPress Multisite: Not detected', 'easyrankly' )
	);
}

/**
 * Renders an inline "Connectors API (WordPress 7.0): Detected / Not detected"
 * status badge, mirroring erankly_render_multisite_status().
 *
 * The AI features depend on the WordPress 7.0 Connectors API. This shows the
 * requirement graphically — same visual language as the Multisite badge — so an
 * admin can immediately see whether the current install exposes it, instead of
 * reading a plain italic "Requires WordPress 7.0…" line.
 *
 * @return void
 */
function erankly_render_connectors_status(): void {
	if ( function_exists( 'wp_get_connectors' ) ) {
		printf(
			'<span class="erankly-ai-requirement-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#1a7f37;"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
			esc_html__( 'Connectors API (WordPress 7.0): Detected', 'easyrankly' )
		);
		return;
	}

	printf(
		'<span class="erankly-ai-requirement-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#b32d2e;"><span class="dashicons dashicons-no-alt" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
		esc_html__( 'Connectors API (WordPress 7.0): Not detected', 'easyrankly' )
	);
}

/**
 * Renders an inline "AI provider: Connected / Not connected" status badge,
 * mirroring erankly_render_multisite_status().
 *
 * The AI features also require at least one AI provider connector to be
 * configured (not just the Connectors API to exist). This surfaces that
 * requirement the same way, so an admin who enabled AI features without
 * connecting a provider immediately sees why nothing works.
 *
 * @return void
 */
function erankly_render_ai_provider_status(): void {
	if ( erankly_ai_available() ) {
		printf(
			'<span class="erankly-ai-requirement-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#1a7f37;"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
			esc_html__( 'AI provider: Connected', 'easyrankly' )
		);
		return;
	}

	printf(
		'<span class="erankly-ai-requirement-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#b32d2e;"><span class="dashicons dashicons-no-alt" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
		esc_html__( 'AI provider: Not connected', 'easyrankly' )
	);
}
