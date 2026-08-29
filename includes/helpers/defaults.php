<?php
/**
 * Shared helpers: defaults and meta scaffolding.
 *
 * Loaded lazily for full settings, activation, reset, and migration writes.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the default global metadata template for post types.
 *
 * @return array<string,string>
 */
function erankly_default_post_type_meta_template(): array {
	return array(
		'title'       => '{{post_title}}',
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
		'title'       => '{{term_name}}',
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
		'title'       => '{{post_title}}',
		'description' => '{{post_excerpt}}',
	);
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
	$defaults = erankly_build_global_entity_meta_defaults( array_keys( erankly_get_public_post_types() ), erankly_default_post_type_meta_template() );

	foreach ( $defaults as $post_type => &$row ) {
		$row['webpage_type'] = 'WebPage';
		$row['article_type'] = 'post' === $post_type ? 'BlogPosting' : '';
	}
	unset( $row );

	return $defaults;
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

		$schema_by_key = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}
			$schema = array();
			if ( array_key_exists( 'webpage_type', $input[ $key ] ) ) {
				$schema['webpage_type'] = erankly_sanitize_schema_type_name( $input[ $key ]['webpage_type'] );
			}
			if ( array_key_exists( 'article_type', $input[ $key ] ) ) {
				$schema['article_type'] = erankly_sanitize_schema_type_name( $input[ $key ]['article_type'] );
			}
			$schema_by_key[ $key ] = $schema;

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

		$has_schema = (bool) array_filter(
			$schema_by_key,
			static fn( array $schema ): bool => (bool) array_filter( $schema, 'strlen' )
		);
		if ( '' === $title && '' === $description && 0 === array_sum( $directives ) && ! $has_schema ) {
			return array();
		}

		foreach ( $keys as $key ) {
			$clean[ $key ] = array(
				'title'       => $title,
				'description' => $description,
			) + $directives + ( $schema_by_key[ $key ] ?? array() );
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
		$schema      = array();
		if ( array_key_exists( 'webpage_type', $fields ) ) {
			$schema['webpage_type'] = erankly_sanitize_schema_type_name( $fields['webpage_type'] );
		}
		if ( array_key_exists( 'article_type', $fields ) ) {
			$schema['article_type'] = erankly_sanitize_schema_type_name( $fields['article_type'] );
		}
		$no_social   = ! $with_social || erankly_global_entity_social_is_empty( $social );
		$no_schema   = ! array_filter( $schema, 'strlen' );

		if ( '' === $title && '' === $description && 0 === array_sum( $directives ) && $no_social && $no_schema ) {
			continue;
		}

		$clean[ $entity ] = array(
			'title'       => $title,
			'description' => $description,
		) + $directives + $social + $schema;
	}

	return $clean;
}

/**
 * Sanitizes a Schema.org type name used by post-type defaults.
 *
 * @param mixed $value Raw type name.
 * @return string
 */
function erankly_sanitize_schema_type_name( mixed $value ): string {
	$value = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );

	return is_string( $value ) ? substr( $value, 0, 100 ) : '';
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
		'organization_name'              => erankly_default_organization_name_template(),
		'website_name'                   => erankly_default_website_name_template(),
		'website_description'            => erankly_default_website_description_template(),
		'organization_logo'              => 0,
		'organization_logo_url'          => erankly_default_organization_logo_url_template(),
		'organization_description'       => '',
		'organization_email'             => '',
		'organization_phone'             => '',
		'organization_legal_name'        => '',
		'organization_vat_id'            => '',
		'organization_tax_id'            => '',
		'organization_street_address'    => '',
		'organization_locality'          => '',
		'organization_region'            => '',
		'organization_postal_code'       => '',
		'organization_country'           => '',
		'social_profiles'                => '',
		'default_og_image'               => 0,
		'default_social_image_url'       => '',
		'default_og_title'               => $social_template['title'],
		'default_og_description'         => $social_template['description'],
		'default_twitter_title'          => $social_template['title'],
		'default_twitter_description'    => $social_template['description'],
		'social_defaults_linked'         => 1,
		'twitter_site'                   => '',
		'global_post_type_meta'          => erankly_default_global_post_type_meta(),
		'global_post_type_meta_linked'   => 1,
		'global_taxonomy_meta'           => erankly_default_global_taxonomy_meta(),
		'global_taxonomy_meta_linked'    => 1,
		'global_special_meta'            => erankly_default_global_special_meta(),
		'schema_identity'                => 'organization',
		'schema_person_user_id'          => 0,
		'enable_local_business'          => 0,
		'local_business_type'            => 'LocalBusiness',
		'local_business_page_path'       => '',
		'local_business_price_range'     => '',
		'local_business_latitude'        => '',
		'local_business_longitude'       => '',
		'local_business_menu_url'        => '',
		'local_business_cuisine'         => '',
		'local_business_hours'           => erankly_default_opening_hours(),
		'global_schema_blocks'           => array(),
		'simplified_mode'                => 1,
		'resolve_placeholders'           => 1,
		'enable_sitemap'                 => 0,
		'enable_news_sitemap'            => 0,
		'news_sitemap_post_types'        => array( 'post' ),
		'news_publication_name'          => '',
		'enable_image_sitemap'           => 0,
		'enable_video_sitemap'           => 0,
		'enable_breadcrumbs'             => 1,
		'robots_txt_extra'               => '',
		'noindex_paginated'              => 0,
		'paginated_title_format'         => '',
		'attachment_redirect'            => 'none',
		'robots_max_image_preview_large' => 1,
		'robots_max_snippet'             => '',
		'robots_max_video_preview'       => '',
		'robots_nosnippet'               => 0,
		'robots_indexifembedded'         => 0,
		'enable_redirects'               => 0,
		'redirect_exclude_admins'        => 1,
	);
}
