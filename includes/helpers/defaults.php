<?php
/**
 * Shared helpers — defaults and meta scaffolding.
 *
 * Part of the helpers.php loader; always loaded early on every request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether an array is a list (sequential integer keys from 0).
 *
 * PHP 8.0-compatible replacement for array_is_list(), which only exists from
 * PHP 8.1. The plugin declares "Requires PHP: 8.0", so the native function must
 * not be called directly.
 *
 * @param array<mixed> $arr Array to inspect.
 * @return bool True when keys are 0..n-1 in order.
 */
function erankly_array_is_list( array $arr ): bool {
	if ( array() === $arr ) {
		return true;
	}

	return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
}

/**
 * Returns the default global metadata template for post types.
 *
 * @return array<string,string>
 */
function erankly_default_post_type_meta_template(): array {
	return array(
		'title'       => '{{post_title}} - {{site_name}}',
		'description' => '{{post_excerpt}}',
	);
}

/**
 * Returns the default global metadata template for taxonomies.
 *
 * @return array<string,string>
 */
function erankly_default_taxonomy_meta_template(): array {
	return array(
		'title'       => '{{term_name}} - {{site_name}}',
		'description' => '{{term_description}}',
	);
}

/**
 * Returns the default global social metadata template for post content.
 *
 * @return array<string,string>
 */
function erankly_default_social_meta_template(): array {
	return array(
		'title'       => '{{post_title}} - {{site_name}}',
		'description' => '{{post_excerpt}}',
	);
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
 * Builds global metadata defaults for a list of entities.
 *
 * @param array<int,string>    $keys     Entity keys.
 * @param array<string,string> $template Metadata template fields.
 * @return array<string,array<string,string>>
 */
function erankly_build_global_entity_meta_defaults( array $keys, array $template ): array {
	$defaults = array();

	foreach ( $keys as $key ) {
		$key = sanitize_key( $key );

		if ( '' === $key ) {
			continue;
		}

		$defaults[ $key ] = array(
			'title'           => isset( $template['title'] ) ? (string) $template['title'] : '',
			'description'     => isset( $template['description'] ) ? (string) $template['description'] : '',
			'noindex'         => 0,
			'nofollow'        => 0,
			'noarchive'       => 0,
			'disable_sitemap' => 0,
		);
	}

	return $defaults;
}

/**
 * Returns default global metadata for all supported post types.
 *
 * @return array<string,array<string,string>>
 */
function erankly_default_global_post_type_meta(): array {
	return erankly_build_global_entity_meta_defaults( array_keys( erankly_get_public_post_types() ), erankly_default_post_type_meta_template() );
}

/**
 * Returns default global metadata for all supported taxonomies.
 *
 * @return array<string,array<string,string>>
 */
function erankly_default_global_taxonomy_meta(): array {
	return erankly_build_global_entity_meta_defaults( array_keys( erankly_get_public_taxonomies() ), erankly_default_taxonomy_meta_template() );
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

/**
 * Returns default global metadata for the special page entities.
 *
 * Titles and descriptions start empty. Search results and the 404 page default
 * to hidden; author and date archives default to visible. "Hidden" sets noindex
 * and disable_sitemap (nofollow and noarchive stay off, as advanced-only opt-ins)
 * so the simplified "Hide from search results" control round-trips correctly.
 *
 * @return array<string,array<string,string|int>>
 */
function erankly_default_global_special_meta(): array {
	$hidden_by_key = array(
		'search' => true,
		'404'    => true,
		'author' => false,
		'date'   => false,
	);

	$defaults = array();

	// Settings are read during plugins_loaded, before translations may load.
	foreach ( array_keys( erankly_special_page_keys( false ) ) as $key ) {
		$flag = ! empty( $hidden_by_key[ $key ] ) ? 1 : 0;

		$defaults[ $key ] = array(
			'title'           => '',
			'description'     => '',
			'noindex'         => $flag,
			'nofollow'        => 0,
			'noarchive'       => 0,
			'disable_sitemap' => $flag,
		);
	}

	return $defaults;
}

/**
 * Sanitizes global title and description templates keyed by entity name.
 *
 * Shared by settings forms, import/export and REST writers.
 *
 * @param mixed             $input        Raw input.
 * @param array<int,string> $allowed_keys Entity keys allowed in settings.
 * @param bool              $linked       Whether one template should apply to every entity.
 * @param bool              $with_social  Whether to also keep per-entity social fields.
 * @return array<string,array<string,string|int>>
 */
function erankly_sanitize_global_entity_meta( mixed $input, array $allowed_keys, bool $linked = false, bool $with_social = false ): array {
	$input          = is_array( $input ) ? $input : array();
	$keys           = array_map( 'sanitize_key', $allowed_keys );
	$allowed        = array_fill_keys( $keys, true );
	$clean          = array();
	$directive_keys = array( 'noindex', 'nofollow', 'noarchive', 'disable_sitemap' );

	if ( $linked && ! empty( $keys ) ) {
		$title       = '';
		$description = '';
		$directives  = array_fill_keys( $directive_keys, 0 );

		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}

			$current_title       = isset( $input[ $key ]['title'] ) ? erankly_sanitize_text( $input[ $key ]['title'] ) : '';
			$current_description = isset( $input[ $key ]['description'] ) ? erankly_sanitize_textarea( $input[ $key ]['description'] ) : '';
			$current_directives  = erankly_sanitize_global_entity_directives( $input[ $key ] );

			if ( '' !== $current_title || '' !== $current_description || array_sum( $current_directives ) > 0 ) {
				$title       = $current_title;
				$description = $current_description;
				$directives  = $current_directives;
				break;
			}
		}

		if ( '' === $title && '' === $description && 0 === array_sum( $directives ) ) {
			return array();
		}

		foreach ( $keys as $key ) {
			$clean[ $key ] = array(
				'title'       => $title,
				'description' => $description,
			) + $directives;
		}

		return $clean;
	}

	foreach ( $input as $entity => $fields ) {
		$entity = sanitize_key( (string) $entity );

		if ( ! isset( $allowed[ $entity ] ) || ! is_array( $fields ) ) {
			continue;
		}

		$title       = isset( $fields['title'] ) ? erankly_sanitize_text( $fields['title'] ) : '';
		$description = isset( $fields['description'] ) ? erankly_sanitize_textarea( $fields['description'] ) : '';
		$directives  = erankly_sanitize_global_entity_directives( $fields );
		$social      = $with_social ? erankly_sanitize_global_entity_social( $fields ) : array();
		$no_social   = ! $with_social || erankly_global_entity_social_is_empty( $social );

		if ( '' === $title && '' === $description && 0 === array_sum( $directives ) && $no_social ) {
			continue;
		}

		$clean[ $entity ] = array(
			'title'       => $title,
			'description' => $description,
		) + $directives + $social;
	}

	return $clean;
}

/**
 * Sanitizes global robots and sitemap directives.
 *
 * @param array<string,mixed> $fields Raw fields.
 * @return array<string,int>
 */
function erankly_sanitize_global_entity_directives( array $fields ): array {
	$hide = ! empty( $fields['hide_from_search_results'] );

	return array(
		'noindex'         => ( $hide || ! empty( $fields['noindex'] ) ) ? 1 : 0,
		'nofollow'        => ! empty( $fields['nofollow'] ) ? 1 : 0,
		'noarchive'       => ! empty( $fields['noarchive'] ) ? 1 : 0,
		'disable_sitemap' => ( $hide || ! empty( $fields['disable_sitemap'] ) ) ? 1 : 0,
	);
}

/**
 * Sanitizes the social fields of a global entity.
 *
 * @param array<string,mixed> $fields Raw fields.
 * @return array<string,string|int>
 */
function erankly_sanitize_global_entity_social( array $fields ): array {
	return array(
		'og_title'            => isset( $fields['og_title'] ) ? erankly_sanitize_text( $fields['og_title'] ) : '',
		'og_description'      => isset( $fields['og_description'] ) ? erankly_sanitize_textarea( $fields['og_description'] ) : '',
		'twitter_title'       => isset( $fields['twitter_title'] ) ? erankly_sanitize_text( $fields['twitter_title'] ) : '',
		'twitter_description' => isset( $fields['twitter_description'] ) ? erankly_sanitize_textarea( $fields['twitter_description'] ) : '',
		'social_image_url'    => isset( $fields['social_image_url'] ) ? erankly_sanitize_url_template( $fields['social_image_url'] ) : '',
		'og_image_id'         => isset( $fields['og_image_id'] ) ? absint( $fields['og_image_id'] ) : 0,
	);
}

/**
 * Determines whether a sanitized social field set carries no usable value.
 *
 * @param array<string,string|int> $social Sanitized social fields.
 * @return bool
 */
function erankly_global_entity_social_is_empty( array $social ): bool {
	return '' === ( $social['og_title'] ?? '' )
		&& '' === ( $social['og_description'] ?? '' )
		&& '' === ( $social['twitter_title'] ?? '' )
		&& '' === ( $social['twitter_description'] ?? '' )
		&& '' === ( $social['social_image_url'] ?? '' )
		&& 0 === (int) ( $social['og_image_id'] ?? 0 );
}

/**
 * Returns default plugin settings.
 *
 * @return array<string,mixed>
 */
function erankly_default_settings(): array {
	$social_template = erankly_default_social_meta_template();

	return array(
		'organization_name'                   => erankly_default_organization_name_template(),
		'organization_logo'                   => 0,
		'organization_logo_url'               => erankly_default_organization_logo_url_template(),
		'organization_description'            => '',
		'organization_email'                  => '',
		'organization_phone'                  => '',
		'organization_legal_name'             => '',
		'organization_vat_id'                 => '',
		'organization_tax_id'                 => '',
		'organization_street_address'         => '',
		'organization_locality'               => '',
		'organization_region'                 => '',
		'organization_postal_code'            => '',
		'organization_country'                => '',
		'social_profiles'                     => '',
		'default_og_image'                    => 0,
		'default_social_image_url'            => '',
		'default_og_title'                    => $social_template['title'],
		'default_og_description'              => $social_template['description'],
		'default_twitter_title'               => $social_template['title'],
		'default_twitter_description'         => $social_template['description'],
		'social_defaults_linked'              => 1,
		'twitter_site'                        => '',
		'global_post_type_meta'               => erankly_default_global_post_type_meta(),
		'global_post_type_meta_linked'        => 1,
		'global_taxonomy_meta'                => erankly_default_global_taxonomy_meta(),
		'global_taxonomy_meta_linked'         => 1,
		'global_special_meta'                 => erankly_default_global_special_meta(),
		'schema_identity'                     => 'organization',
		'schema_person_user_id'               => 0,
		'enable_local_business'               => 0,
		'local_business_type'                 => 'LocalBusiness',
		'local_business_page_path'            => '',
		'local_business_price_range'          => '',
		'local_business_latitude'             => '',
		'local_business_longitude'            => '',
		'local_business_menu_url'             => '',
		'local_business_cuisine'              => '',
		'local_business_hours'                => erankly_default_opening_hours(),
		'global_schema_blocks'                => array(),
		'simplified_mode'                     => 1,
		'resolve_placeholders'                => 1,
		'ai_enabled'                          => 0,
		'ai_prompt_template'                  => '',
		'ai_link_suggestions_prompt_template' => '',
		'ai_content_limit'                    => 4000,
		'enable_sitemap'                      => 0,
		'enable_health'                       => 0,
		'enable_link_building'                => 0,
		'enable_news_sitemap'                 => 0,
		'news_sitemap_post_types'             => array( 'post' ),
		'news_publication_name'               => '',
		'enable_image_sitemap'                => 0,
		'enable_video_sitemap'                => 0,
		'enable_breadcrumbs'                  => 1,
		'robots_txt_extra'                    => '',
		'noindex_paginated'                   => 0,
		'paginated_title_format'              => '',
		'attachment_redirect'                 => 'none',
		'robots_max_image_preview_large'      => 1,
		'robots_max_snippet'                  => '',
		'robots_max_video_preview'            => '',
		'robots_nosnippet'                    => 0,
		'robots_indexifembedded'              => 0,
		'enable_multilingual'                 => 0,
		'enable_redirects'                    => 0,
		'redirect_exclude_admins'             => 0,
		'hide_head_credit'                    => 1,
		'bloat_remove_emoji'                  => 0,
		'bloat_remove_generator'              => 0,
		'bloat_remove_feed_links'             => 0,
		'bloat_remove_rsd_link'               => 0,
		'bloat_remove_wlwmanifest'            => 0,
		'bloat_remove_shortlink'              => 0,
		'bloat_remove_rest_link'              => 0,
		'bloat_remove_oembed'                 => 0,
		'bloat_remove_jquery_migrate'         => 0,
		'bloat_disable_self_pingbacks'        => 0,
		'bloat_remove_dashicons'              => 0,
		'bloat_disable_heartbeat'             => 0,
		'bloat_disable_xmlrpc'                => 0,
	);
}
