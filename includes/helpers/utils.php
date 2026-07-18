<?php
/**
 * Shared helpers — miscellaneous utilities.
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
	$icon  = $success ? 'dashicons-yes' : 'dashicons-no-alt';

	printf(
		'<span class="erankly-inline-status %1$s"><span class="dashicons %2$s" aria-hidden="true"></span>%3$s</span>',
		esc_attr( $class ),
		esc_attr( $icon ),
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
	erankly_render_inline_status_badge(
		function_exists( 'wp_get_connectors' )
			? __( 'Connectors API (WordPress 7.0): Detected', 'easyrankly' )
			: __( 'Connectors API (WordPress 7.0): Not detected', 'easyrankly' ),
		function_exists( 'wp_get_connectors' )
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
	erankly_render_inline_status_badge(
		erankly_ai_provider_available()
			? __( 'AI provider: Connected', 'easyrankly' )
			: __( 'AI provider: Not connected', 'easyrankly' ),
		erankly_ai_provider_available()
	);
}

/**
 * Whether an AI provider appears configured for the Features panel.
 *
 * When the AI module is loaded, delegates to erankly_ai_available(). Otherwise
 * runs a lightweight Connectors API scan so the Features toggle can show
 * provider status without parsing includes/ai.php.
 *
 * @return bool
 */
function erankly_ai_provider_available(): bool {
	if ( function_exists( 'erankly_ai_available' ) ) {
		return erankly_ai_available();
	}

	static $cache = array();

	$blog_id = is_multisite() ? get_current_blog_id() : 0;

	if ( isset( $cache[ $blog_id ] ) ) {
		return $cache[ $blog_id ];
	}

	$available = false;

	if ( function_exists( 'wp_get_connectors' ) ) {
		foreach ( (array) wp_get_connectors() as $connector ) {
			if ( ! is_array( $connector ) || ( $connector['type'] ?? '' ) !== 'ai_provider' ) {
				continue;
			}

			$plugin    = is_array( $connector['plugin'] ?? null ) ? $connector['plugin'] : array();
			$is_active = $plugin['is_active'] ?? null;

			if ( is_callable( $is_active ) && ! call_user_func( $is_active ) ) {
				continue;
			}

			foreach ( array( 'is_connected', 'connected', 'authenticated' ) as $status_key ) {
				if ( isset( $connector[ $status_key ] ) && (bool) $connector[ $status_key ] ) {
					$available = true;
					break 2;
				}
			}

			if ( isset( $connector['status'] ) && is_string( $connector['status'] ) ) {
				if ( in_array( strtolower( $connector['status'] ), array( 'connected', 'active', 'authenticated' ), true ) ) {
					$available = true;
					break;
				}
			}

			$auth   = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : array();
			$method = (string) ( $auth['method'] ?? '' );

			if ( 'none' === $method ) {
				$available = true;
				break;
			}

			if ( 'api_key' === $method ) {
				$setting  = (string) ( $auth['setting_name'] ?? '' );
				$env_var  = (string) ( $auth['env_var_name'] ?? '' );
				$constant = (string) ( $auth['constant_name'] ?? '' );

				if ( '' !== $env_var ) {
					$env = getenv( $env_var );
					if ( is_string( $env ) && '' !== trim( $env ) ) {
						$available = true;
						break;
					}
				}

				if ( '' !== $constant && defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
					$available = true;
					break;
				}

				if ( '' !== $setting && '' !== trim( (string) get_option( $setting, '' ) ) ) {
					$available = true;
					break;
				}

				if ( is_multisite() && '' !== $setting && '' !== trim( (string) get_site_option( $setting, '' ) ) ) {
					$available = true;
					break;
				}
			}
		}
	}

	/**
	 * Filters whether AI features are considered available.
	 *
	 * @param bool $available Detected availability.
	 */
	$cache[ $blog_id ] = (bool) apply_filters( 'erankly_ai_available', $available );

	return $cache[ $blog_id ];
}

/**
 * URL of the core Connectors settings screen.
 *
 * @return string
 */
function erankly_ai_connectors_admin_url(): string {
	/**
	 * Filters the URL to the core Connectors settings screen.
	 *
	 * @param string $url Connectors screen URL.
	 */
	return (string) apply_filters( 'erankly_ai_connectors_url', admin_url( 'options-connectors.php' ) );
}
