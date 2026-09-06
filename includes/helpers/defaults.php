<?php
/** Shared helpers: defaults and meta scaffolding. Loaded lazily for full settings, activation, reset, and migration writes. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string,string> */
function erankly_default_post_type_meta_template(): array {
	return array(
		'title'       => '{{post_title}}',
		'description' => '{{post_excerpt}}',
	);
}

/** @return array<string,string> */
function erankly_default_taxonomy_meta_template(): array {
	return array(
		'title'       => '{{term_name}}',
		'description' => '{{term_description}}',
	);
}

/** @return array<string,string> */
function erankly_default_social_meta_template(): array {
	return array(
		'title'       => '{{post_title}}',
		'description' => '{{post_excerpt}}',
	);
}

/** @return array<string,array<string,string>> */
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

/** @return array<string,array<string,string>> */
function erankly_default_global_post_type_meta(): array {
	return erankly_build_global_entity_meta_defaults( array_keys( erankly_get_public_post_types() ), erankly_default_post_type_meta_template() );
}

/**
 * Returns the default Schema.org types per post type. These live outside global_post_type_meta because the
 * "Same for all" toggle links titles and descriptions across content types, while a page and a post must be
 * able to describe themselves differently.
 *
 * @return array<string,array{webpage_type:string,article_type:string}>
 */
function erankly_default_global_post_type_schema(): array {
	$defaults = array();

	foreach ( array_keys( erankly_get_public_post_types() ) as $post_type ) {
		$defaults[ $post_type ] = erankly_default_post_type_schema_row( (string) $post_type );
	}

	return $defaults;
}

/**
 * Sanitizes the per-post-type Schema.org types. Unknown post types are dropped; unknown type names are kept
 * only when they are well-formed, so a value imported from another SEO plugin is not silently discarded.
 *
 * @return array<string,array{webpage_type:string,article_type:string}>
 */
function erankly_sanitize_global_post_type_schema( mixed $input ): array {
	$input   = is_array( $input ) ? $input : array();
	$allowed = array_fill_keys( array_map( 'sanitize_key', array_keys( erankly_get_public_post_types() ) ), true );
	$clean   = array();

	foreach ( $input as $post_type => $fields ) {
		$post_type = sanitize_key( (string) $post_type );

		if ( ! isset( $allowed[ $post_type ] ) || ! is_array( $fields ) ) {
			continue;
		}

		$defaults     = erankly_default_post_type_schema_row( $post_type );
		$webpage_type = array_key_exists( 'webpage_type', $fields ) ? erankly_sanitize_schema_type_name( $fields['webpage_type'] ) : '';
		$article_type = array_key_exists( 'article_type', $fields ) ? erankly_sanitize_schema_type_name( $fields['article_type'] ) : '';

		$clean[ $post_type ] = array(
			'webpage_type' => '' !== $webpage_type ? $webpage_type : $defaults['webpage_type'],
			'article_type' => '' !== $article_type ? $article_type : $defaults['article_type'],
		);
	}

	return $clean;
}

/** @return array<string,array<string,string>> */
function erankly_default_global_taxonomy_meta(): array {
	return erankly_build_global_entity_meta_defaults( array_keys( erankly_get_public_taxonomies() ), erankly_default_taxonomy_meta_template() );
}

/**
 * Returns default global metadata for the special page entities. Titles and descriptions start empty. Search
 * results and the 404 page default to hidden; author and date archives default to visible. "Hidden" sets noindex
 * and disable_sitemap (nofollow and noarchive stay off, as advanced-only opt-ins) so the simplified "Hide from
 * search results" control round-trips correctly.
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
 * Sanitizes global title and description templates keyed by entity name. Shared by settings forms, import/export
 * and REST writers.
 *
 * @param array<int,string> $allowed_keys Entity keys allowed in settings.
 * @param bool              $linked       Whether one template should apply to every entity.
 * @param bool              $with_social  Whether to also keep per-entity social fields.
 * @param bool              $warn_on_divergence Whether a settings warning should be recorded when linked
 *                                    rows carry differing values (they are replaced by the first row).
 * @return array<string,array<string,string|int>>
 */
function erankly_sanitize_global_entity_meta( mixed $input, array $allowed_keys, bool $linked = false, bool $with_social = false, bool $warn_on_divergence = false ): array {
	$input          = is_array( $input ) ? $input : array();
	$keys           = array_map( 'sanitize_key', $allowed_keys );
	$allowed        = array_fill_keys( $keys, true );
	$clean          = array();
	$has_directives = static function ( array $directives ): bool {
		foreach ( $directives as $key => $value ) {
			if ( in_array( $key, array( 'noindex', 'nofollow', 'noarchive', 'disable_sitemap' ), true ) ) {
				if ( ! empty( $value ) ) {
					return true;
				}
				continue;
			}

			// Optional advanced fields are meaningful even when explicitly off:
			// they can override a restrictive site-wide migration default.
			return true;
		}

		return false;
	};

	if ( $linked && ! empty( $keys ) ) {
		// The unified panel always edits the first entity's fields, so the
		// first entity is the single source of truth — never the first
		// non-empty row, whose arbitrary win silently discarded values
		// authored on other tabs.
		$first_key   = $keys[0];
		$first_row   = ( isset( $input[ $first_key ] ) && is_array( $input[ $first_key ] ) ) ? $input[ $first_key ] : array();
		$title       = isset( $first_row['title'] ) ? erankly_sanitize_text( $first_row['title'] ) : '';
		$description = isset( $first_row['description'] ) ? erankly_sanitize_textarea( $first_row['description'] ) : '';
		$directives  = erankly_sanitize_global_entity_directives( $first_row );

		$diverged = false;
		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) || $key === $first_key ) {
				continue;
			}

			$other_title       = isset( $input[ $key ]['title'] ) ? erankly_sanitize_text( $input[ $key ]['title'] ) : '';
			$other_description = isset( $input[ $key ]['description'] ) ? erankly_sanitize_textarea( $input[ $key ]['description'] ) : '';
			$other_directives  = erankly_sanitize_global_entity_directives( $input[ $key ] );

			if ( $other_title !== $title || $other_description !== $description || $other_directives !== $directives ) {
				$diverged = true;
			}
		}

		if ( '' === $title && '' === $description && ! $has_directives( $directives ) ) {
			return array();
		}

		if ( $diverged && $warn_on_divergence && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_linked_defaults_diverged',
				__( 'The unified default was applied to every content type because the submitted per-type values differed; the differing values were replaced.', 'easyrankly' ),
				'warning'
			);
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

		if ( '' === $title && '' === $description && ! $has_directives( $directives ) && $no_social ) {
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
 * Sanitizes a Schema.org type name used by post-type defaults, plus the "none" sentinel that suppresses a node.
 * Schema.org type names are UpperCamelCase alphanumerics: stripping the invalid characters (as this used to do)
 * turned a typo into a different invalid type instead of rejecting it, and the result was emitted as an @type.
 */
function erankly_sanitize_schema_type_name( mixed $value ): string {
	// Casting an array to string warns and yields the literal "Array", which
	// would otherwise pass the pattern below and reach the output as an @type.
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';

	if ( 'none' === strtolower( $value ) ) {
		return 'none';
	}

	if ( 'QAPage' === $value ) {
		return 'WebPage';
	}

	return 1 === preg_match( '/^[A-Z][A-Za-z0-9]{0,99}$/', $value ) ? $value : '';
}

/**
 * Sanitizes global robots and sitemap directives.
 *
 * @return array<string,int|string>
 */
function erankly_sanitize_global_entity_directives( array $fields ): array {
	$hide = ! empty( $fields['hide_from_search_results'] );

	$directives = array(
		'noindex'         => ( $hide || ! empty( $fields['noindex'] ) ) ? 1 : 0,
		'nofollow'        => ! empty( $fields['nofollow'] ) ? 1 : 0,
		'noarchive'       => ! empty( $fields['noarchive'] ) ? 1 : 0,
		'disable_sitemap' => ( $hide || ! empty( $fields['disable_sitemap'] ) ) ? 1 : 0,
	);

	// noodp was retired: DMOZ shut down in 2017 and no engine reads the directive.
	foreach ( array( 'notranslate', 'indexifembedded' ) as $key ) {
		if ( array_key_exists( $key, $fields ) ) {
			$directives[ $key ] = ! empty( $fields[ $key ] ) ? 1 : 0;
		}
	}

	foreach ( array(
		'index_directive'   => array( 'index', 'noindex' ),
		'follow_directive'  => array( 'follow', 'nofollow' ),
		'archive_directive' => array( 'archive', 'noarchive' ),
		'snippet_directive' => array( 'snippet', 'nosnippet' ),
		'image_directive'   => array( 'imageindex', 'noimageindex' ),
	) as $key => $allowed ) {
		if ( ! array_key_exists( $key, $fields ) ) {
			continue;
		}
		$value = sanitize_key( (string) $fields[ $key ] );
		if ( in_array( $value, $allowed, true ) ) {
			$directives[ $key ] = $value;
		}
	}

	foreach ( array( 'max_snippet', 'max_video_preview' ) as $key ) {
		if ( ! array_key_exists( $key, $fields ) ) {
			continue;
		}
		$value = trim( (string) $fields[ $key ] );
		if ( preg_match( '/^-?\d+$/', $value ) && (int) $value >= -1 ) {
			$directives[ $key ] = (string) (int) $value;
		}
	}

	if ( array_key_exists( 'max_image_preview', $fields ) ) {
		$value = sanitize_key( (string) $fields['max_image_preview'] );
		if ( in_array( $value, array( 'none', 'standard', 'large' ), true ) ) {
			$directives['max_image_preview'] = $value;
		}
	}

	return $directives;
}

/**
 * Sanitizes the social fields of a global entity.
 *
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

/** Determines whether a sanitized social field set carries no usable value. */
function erankly_global_entity_social_is_empty( array $social ): bool {
	return '' === ( $social['og_title'] ?? '' )
		&& '' === ( $social['og_description'] ?? '' )
		&& '' === ( $social['twitter_title'] ?? '' )
		&& '' === ( $social['twitter_description'] ?? '' )
		&& '' === ( $social['social_image_url'] ?? '' )
		&& 0 === (int) ( $social['og_image_id'] ?? 0 );
}

/** @return array<string,mixed> */
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
		'global_post_type_schema'        => erankly_default_global_post_type_schema(),
		'global_taxonomy_meta'           => erankly_default_global_taxonomy_meta(),
		'global_taxonomy_meta_linked'    => 1,
		'global_special_meta'            => erankly_default_global_special_meta(),
		'schema_identity'                => 'organization',
		'schema_person_user_id'          => 0,
		'enable_local_business'          => 0,
		'local_business_type'            => 'LocalBusiness',
		'local_business_page_path'       => '',
		'local_business_pages'           => array(),
		'local_business_price_range'     => '',
		'local_business_latitude'        => '',
		'local_business_longitude'       => '',
		'local_business_menu_url'        => '',
		'local_business_cuisine'         => '',
		'local_business_hours'           => erankly_default_opening_hours(),
		'global_schema_blocks'           => array(),
		'enable_website_search_action'   => 0,
		'simplified_mode'                => 1,
		'resolve_placeholders'           => 1,
		'enable_sitemap'                 => 0,
		'enable_news_sitemap'            => 0,
		'news_sitemap_post_types'        => array( 'post' ),
		'news_publication_name'          => '',
		'enable_image_sitemap'           => 0,
		'enable_video_sitemap'           => 0,
		'enable_breadcrumbs'             => 1,
		'breadcrumb_jsonld_mode'         => 'when_visible',
		'robots_txt_extra'               => '',
		'noindex_paginated'              => 0,
		'noindex_paginated_content'      => 0,
		'nofollow_paginated'             => 0,
		'noindex_feeds'                  => 0,
		'paginated_title_format'         => '',
		'attachment_redirect'            => 'none',
		'robots_max_image_preview'       => '',
		'robots_max_snippet'             => '',
		'robots_max_video_preview'       => '',
		'robots_nosnippet'               => 0,
		'robots_noimageindex'            => 0,
		'robots_notranslate'             => 0,
		'robots_indexifembedded'         => 0,
		'enable_redirects'               => 0,
		'enable_custom_code'             => 0,
		// Repeatable, location-targeted snippets (see erankly_sanitize_custom_code_blocks).
		'head_code_blocks'               => array(),
		'body_open_code_blocks'          => array(),
		'body_close_code_blocks'         => array(),
		// Legacy single-snippet storage (pre-block UI). Kept as a frontend
		// fallback and auto-migrated to blocks on next privileged save.
		'head_code'                      => '',
		'body_open_code'                 => '',
		'body_close_code'                => '',
	);
}
