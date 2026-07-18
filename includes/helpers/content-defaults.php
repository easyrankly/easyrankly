<?php
/**
 * Runtime defaults used by rendered SEO content and admin editors.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the default social image URL placeholder for admin fields.
 *
 * @return string
 */
function erankly_default_social_image_placeholder(): string {
	return home_url( '/social-image.webp' );
}

/**
 * Returns the default Organization or Person name template.
 *
 * @return string
 */
function erankly_default_organization_name_template(): string {
	return '{{site_name}}';
}

/**
 * Returns the default organization logo URL placeholder for admin fields.
 *
 * @return string
 */
function erankly_default_organization_logo_placeholder(): string {
	return home_url( '/organization-logo.webp' );
}

/**
 * Returns the default Organization logo URL template.
 *
 * @return string
 */
function erankly_default_organization_logo_url_template(): string {
	return '{{site_icon_url}}';
}

/**
 * Returns the WordPress Site Icon URL.
 *
 * @return string
 */
function erankly_get_site_icon_url(): string {
	if ( ! function_exists( 'get_site_icon_url' ) ) {
		return '';
	}

	return esc_url_raw( (string) get_site_icon_url( 512 ) );
}

/**
 * Returns the effective Organization logo URL.
 *
 * @return string
 */
function erankly_get_organization_logo_url(): string {
	$logo_url = esc_url_raw(
		erankly_replace_variables(
			(string) erankly_get_setting( 'organization_logo_url', '' ),
			0,
			array( 'organization_logo', 'site_icon' )
		)
	);

	if ( '' !== $logo_url ) {
		return $logo_url;
	}

	$logo = erankly_get_image_url( absint( erankly_get_setting( 'organization_logo', 0 ) ), 'full' );

	return '' !== $logo ? $logo : erankly_get_site_icon_url();
}

/**
 * Returns the effective Organization or Person name.
 *
 * @return string
 */
function erankly_get_organization_name(): string {
	$name = erankly_replace_variables(
		(string) erankly_get_setting( 'organization_name', erankly_default_organization_name_template() ),
		0,
		array( 'organization_name' )
	);

	return '' !== $name ? $name : get_bloginfo( 'name' );
}
/**
 * Returns the supported special page / archive entities keyed by slug.
 *
 * These are singleton page types (homepage, blog page, archives, search, 404)
 * that share the same metadata structure as post types and taxonomies but have
 * a single configuration each.
 *
 * @param bool $translate_labels Whether to translate labels for display.
 * @return array<string,string> Map of entity key => admin label.
 */
function erankly_special_page_keys( bool $translate_labels = true ): array {
	$keys = array(
		'homepage' => $translate_labels ? __( 'Homepage', 'easyrankly' ) : 'Homepage',
		'blog'     => $translate_labels ? __( 'Blog page', 'easyrankly' ) : 'Blog page',
		'author'   => $translate_labels ? __( 'Author archive', 'easyrankly' ) : 'Author archive',
		'date'     => $translate_labels ? __( 'Date archive', 'easyrankly' ) : 'Date archive',
		'search'   => $translate_labels ? __( 'Search results', 'easyrankly' ) : 'Search results',
		'404'      => $translate_labels ? __( '404 page', 'easyrankly' ) : '404 page',
	);

	/**
	 * Filters the supported special page entities.
	 *
	 * @param array<string,string> $keys Map of entity key => admin label.
	 */
	return (array) apply_filters( 'erankly_special_pages', $keys );
}

/**
 * Returns the special page entity key matching the current main query.
 *
 * Mirrors the page-type resolution used for titles, descriptions and robots so
 * the same metadata applies consistently. A static front page is handled as a
 * singular post, so it returns '' there; the 'homepage' key applies when the
 * front page shows the blog.
 *
 * @return string Entity key, or '' when the request is not a special page.
 */
function erankly_current_special_page_key(): string {
	if ( is_search() ) {
		return 'search';
	}

	if ( is_404() ) {
		return '404';
	}

	if ( is_author() ) {
		return 'author';
	}

	if ( is_date() ) {
		return 'date';
	}

	if ( ! is_singular() && is_front_page() ) {
		return 'homepage';
	}

	if ( is_home() ) {
		return 'blog';
	}

	return '';
}
