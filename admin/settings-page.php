<?php
/**
 * Settings API: option registration, panel-scoped sanitizer, autosave panel registry, network/site save
 * handlers, extension tab normalization, sidebar nav links. Raw $_POST is wp_unslash()ed then sanitized
 * field-by-field inside erankly_sanitize_settings(); unknown extension keys survive unchanged via the
 * erankly_preserved_extension_settings filter. Legacy head/body_code snippets auto-migrate to the matching
 * *_code_blocks array on privileged saves (unfiltered_html or system context).
 */
defined( 'ABSPATH' ) || exit;
require_once ERANKLY_PATH . 'admin/settings/nav-icons.php';
function erankly_register_settings(): void {
	erankly_load_default_helpers();
	register_setting(
		'erankly',
		ERANKLY_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'erankly_sanitize_settings',
			'default'           => erankly_default_settings(),
		)
	);
}
function erankly_sanitize_settings( mixed $input ): array {
	$input = is_array( $input ) ? $input : array();
	$panel = isset( $input['erankly_settings_panel'] ) ? sanitize_key( (string) $input['erankly_settings_panel'] ) : '';
	unset( $input['erankly_settings_panel'] );
	if ( '' !== $panel ) {
		$input = erankly_merge_settings_submission( $input, $panel );
	}
	$defaults                = erankly_default_settings();
	$identity                = isset( $input['schema_identity'] ) ? erankly_sanitize_text( $input['schema_identity'] ) : '';
	$person_user_id          = isset( $input['schema_person_user_id'] ) ? absint( $input['schema_person_user_id'] ) : 0;
	$local_business_types    = erankly_get_local_business_types();
	$local_business_type     = isset( $input['local_business_type'] ) ? erankly_sanitize_text( $input['local_business_type'] ) : 'LocalBusiness';
	if ( $person_user_id > 0 && ! get_userdata( $person_user_id ) ) {
		$person_user_id = 0;
	}
	if ( ! isset( $local_business_types[ $local_business_type ] ) ) {
		$local_business_type = 'LocalBusiness';
	}
	$social_defaults_linked      = ! empty( $input['social_defaults_linked'] );
	$default_og_title            = isset( $input['default_og_title'] ) ? erankly_sanitize_text( $input['default_og_title'] ) : '';
	$default_og_description      = isset( $input['default_og_description'] ) ? erankly_sanitize_textarea( $input['default_og_description'] ) : '';
	$default_twitter_title       = isset( $input['default_twitter_title'] ) ? erankly_sanitize_text( $input['default_twitter_title'] ) : '';
	$default_twitter_description = isset( $input['default_twitter_description'] ) ? erankly_sanitize_textarea( $input['default_twitter_description'] ) : '';
	$global_special_meta         = array();
	if ( $social_defaults_linked ) {
		$default_twitter_title       = $default_og_title;
		$default_twitter_description = $default_og_description;
	}
	if ( isset( $input['global_special_meta'] ) ) {
		$global_special_meta = erankly_sanitize_global_entity_meta( $input['global_special_meta'], array_keys( erankly_special_page_keys() ), false, true );
	} elseif ( ! empty( $input['preserve_global_special_meta'] ) && ! is_multisite() && erankly_use_site_editor_special_page_panels() ) {
		$global_special_meta = erankly_get_global_entity_meta_map( 'global_special_meta' );
	}
	$organization_logo_url    = isset( $input['organization_logo_url'] ) ? erankly_sanitize_url_template( $input['organization_logo_url'] ) : $defaults['organization_logo_url'];
	$default_social_image_url = isset( $input['default_social_image_url'] ) ? erankly_sanitize_url_template( $input['default_social_image_url'] ) : '';
	$organization_logo        = isset( $input['organization_logo'] ) ? absint( $input['organization_logo'] ) : $defaults['organization_logo'];
	$default_og_image         = isset( $input['default_og_image'] ) ? absint( $input['default_og_image'] ) : 0;
	$organization_logo = erankly_drop_stale_media_id( $organization_logo, $organization_logo_url );
	$default_og_image  = erankly_drop_stale_media_id( $default_og_image, $default_social_image_url );
	$stored_for_schema = erankly_get_stored_settings();
	$settings = array(
		'organization_name'              => isset( $input['organization_name'] ) ? erankly_sanitize_text( $input['organization_name'] ) : $defaults['organization_name'],
		'website_name'                   => isset( $input['website_name'] ) ? erankly_sanitize_text( $input['website_name'] ) : $defaults['website_name'],
		'website_description'            => isset( $input['website_description'] ) ? erankly_sanitize_textarea( $input['website_description'] ) : $defaults['website_description'],
		'organization_logo'              => $organization_logo,
		'organization_logo_url'          => $organization_logo_url,
		'organization_description'       => isset( $input['organization_description'] ) ? erankly_sanitize_textarea( $input['organization_description'] ) : '',
		'organization_email'             => isset( $input['organization_email'] ) ? sanitize_email( (string) $input['organization_email'] ) : '',
		'organization_phone'             => isset( $input['organization_phone'] ) ? erankly_sanitize_phone( $input['organization_phone'] ) : '',
		'organization_legal_name'        => isset( $input['organization_legal_name'] ) ? erankly_sanitize_text( $input['organization_legal_name'] ) : '',
		'organization_vat_id'            => isset( $input['organization_vat_id'] ) ? erankly_sanitize_text( $input['organization_vat_id'] ) : '',
		'organization_tax_id'            => isset( $input['organization_tax_id'] ) ? erankly_sanitize_text( $input['organization_tax_id'] ) : '',
		'organization_street_address'    => isset( $input['organization_street_address'] ) ? erankly_sanitize_text( $input['organization_street_address'] ) : '',
		'organization_locality'          => isset( $input['organization_locality'] ) ? erankly_sanitize_text( $input['organization_locality'] ) : '',
		'organization_region'            => isset( $input['organization_region'] ) ? erankly_sanitize_text( $input['organization_region'] ) : '',
		'organization_postal_code'       => isset( $input['organization_postal_code'] ) ? erankly_sanitize_text( $input['organization_postal_code'] ) : '',
		'organization_country'           => isset( $input['organization_country'] ) ? erankly_sanitize_country_code( $input['organization_country'] ) : '',
		'social_profiles'                => isset( $input['social_profiles'] ) ? erankly_sanitize_url_list( $input['social_profiles'] ) : '',
		'default_og_image'               => $default_og_image,
		'default_social_image_url'       => $default_social_image_url,
		'default_og_title'               => $default_og_title,
		'default_og_description'         => $default_og_description,
		'default_twitter_title'          => $default_twitter_title,
		'default_twitter_description'    => $default_twitter_description,
		'social_defaults_linked'         => $social_defaults_linked ? 1 : 0,
		'twitter_site'                   => isset( $input['twitter_site'] ) ? erankly_sanitize_twitter_handle( $input['twitter_site'] ) : '',
		'global_post_type_meta_linked'   => ! empty( $input['global_post_type_meta_linked'] ) ? 1 : 0,
		'global_post_type_meta'          => isset( $input['global_post_type_meta'] ) ? erankly_sanitize_global_entity_meta( $input['global_post_type_meta'], array_keys( erankly_get_public_post_types() ), ! empty( $input['global_post_type_meta_linked'] ), false, true ) : array(),
		'global_post_type_schema'        => isset( $input['global_post_type_schema'] ) ? erankly_sanitize_global_post_type_schema( $input['global_post_type_schema'] ) : $defaults['global_post_type_schema'],
		'global_taxonomy_meta_linked'    => ! empty( $input['global_taxonomy_meta_linked'] ) ? 1 : 0,
		'global_taxonomy_meta'           => isset( $input['global_taxonomy_meta'] ) ? erankly_sanitize_global_entity_meta( $input['global_taxonomy_meta'], array_keys( erankly_get_public_taxonomies() ), ! empty( $input['global_taxonomy_meta_linked'] ), false, true ) : array(),
		'global_special_meta'            => $global_special_meta,
		'schema_identity'                => 'person' === $identity ? 'person' : 'organization',
		'schema_person_user_id'          => $person_user_id,
		'enable_local_business'          => ! empty( $input['enable_local_business'] ) ? 1 : 0,
		'local_business_type'            => $local_business_type,
		'local_business_page_path'       => array_key_exists( 'local_business_page_path', $input )
			? erankly_sanitize_relative_path( $input['local_business_page_path'] )
			: (string) ( $stored_for_schema['local_business_page_path'] ?? '' ),
		'local_business_pages'           => array_key_exists( 'local_business_pages', $input )
			? erankly_sanitize_local_business_pages( $input['local_business_pages'] )
			: ( isset( $stored_for_schema['local_business_pages'] ) && is_array( $stored_for_schema['local_business_pages'] ) ? erankly_sanitize_local_business_pages( $stored_for_schema['local_business_pages'] ) : array() ),
		'local_business_price_range'     => isset( $input['local_business_price_range'] ) ? erankly_trim_text( erankly_sanitize_text( $input['local_business_price_range'] ), 99 ) : '',
		'local_business_latitude'        => isset( $input['local_business_latitude'] ) ? erankly_sanitize_coordinate( $input['local_business_latitude'], -90, 90 ) : '',
		'local_business_longitude'       => isset( $input['local_business_longitude'] ) ? erankly_sanitize_coordinate( $input['local_business_longitude'], -180, 180 ) : '',
		'local_business_menu_url'        => isset( $input['local_business_menu_url'] ) ? erankly_sanitize_url( $input['local_business_menu_url'] ) : '',
		'local_business_cuisine'         => isset( $input['local_business_cuisine'] ) ? erankly_sanitize_text( $input['local_business_cuisine'] ) : '',
		'local_business_hours'           => isset( $input['local_business_hours'] ) ? erankly_sanitize_opening_hours( $input['local_business_hours'] ) : erankly_default_opening_hours(),
		'global_schema_blocks'           => isset( $input['global_schema_blocks'] )
			? erankly_sanitize_schema_blocks(
				$input['global_schema_blocks'],
				true,
				isset( $stored_for_schema['global_schema_blocks'] ) && is_array( $stored_for_schema['global_schema_blocks'] )
					? $stored_for_schema['global_schema_blocks']
					: array()
			)
			: array(),
		'enable_website_search_action'   => ! empty( $input['enable_website_search_action'] ) ? 1 : 0,
		'simplified_mode'                => ! empty( $input['simplified_mode'] ) ? 1 : 0,
		'resolve_placeholders'           => ! empty( $input['resolve_placeholders'] ) ? 1 : 0,
		'enable_sitemap'                 => ! empty( $input['enable_sitemap'] ) ? 1 : 0,
		'enable_news_sitemap'            => ! empty( $input['enable_news_sitemap'] ) ? 1 : 0,
		'news_sitemap_post_types'        => isset( $input['news_sitemap_post_types'] ) && is_array( $input['news_sitemap_post_types'] ) ? array_intersect( array_map( 'sanitize_text_field', $input['news_sitemap_post_types'] ), array_keys( erankly_get_public_post_types() ) ) : array( 'post' ),
		'news_publication_name'          => isset( $input['news_publication_name'] ) ? sanitize_text_field( (string) $input['news_publication_name'] ) : '',
		'enable_image_sitemap'           => ! empty( $input['enable_image_sitemap'] ) ? 1 : 0,
		'enable_video_sitemap'           => ! empty( $input['enable_video_sitemap'] ) ? 1 : 0,
		'enable_breadcrumbs'             => ! empty( $input['enable_breadcrumbs'] ) ? 1 : 0,
		'breadcrumb_jsonld_mode'         => isset( $input['breadcrumb_jsonld_mode'] ) ? erankly_sanitize_breadcrumb_jsonld_mode( $input['breadcrumb_jsonld_mode'] ) : 'when_visible',
		'robots_txt_extra'               => isset( $input['robots_txt_extra'] ) ? erankly_sanitize_textarea( $input['robots_txt_extra'] ) : '',
		'noindex_paginated'              => ! empty( $input['noindex_paginated'] ) ? 1 : 0,
		'noindex_paginated_content'      => ! empty( $input['noindex_paginated_content'] ) ? 1 : 0,
		'nofollow_paginated'             => ! empty( $input['nofollow_paginated'] ) ? 1 : 0,
		'noindex_feeds'                  => ! empty( $input['noindex_feeds'] ) ? 1 : 0,
		'paginated_title_format'         => isset( $input['paginated_title_format'] ) ? erankly_sanitize_text( $input['paginated_title_format'] ) : '',
		'attachment_redirect'            => ( isset( $input['attachment_redirect'] ) && in_array( $input['attachment_redirect'], array( 'parent', 'file', 'none' ), true ) ) ? $input['attachment_redirect'] : 'none',
		'robots_max_image_preview'       => ( isset( $input['robots_max_image_preview'] ) && in_array( $input['robots_max_image_preview'], array( '', 'none', 'standard', 'large' ), true ) ) ? $input['robots_max_image_preview'] : '',
		'robots_max_snippet'             => isset( $input['robots_max_snippet'] ) ? erankly_sanitize_robots_preview_value( $input['robots_max_snippet'] ) : '',
		'robots_max_video_preview'       => isset( $input['robots_max_video_preview'] ) ? erankly_sanitize_robots_preview_value( $input['robots_max_video_preview'] ) : '',
		'robots_nosnippet'               => ! empty( $input['robots_nosnippet'] ) ? 1 : 0,
		'robots_noimageindex'            => ! empty( $input['robots_noimageindex'] ) ? 1 : 0,
		'robots_notranslate'             => ! empty( $input['robots_notranslate'] ) ? 1 : 0,
		'robots_indexifembedded'         => ! empty( $input['robots_indexifembedded'] ) ? 1 : 0,
		'enable_redirects'               => ! empty( $input['enable_redirects'] ) ? 1 : 0,
		'enable_custom_code'             => erankly_sanitize_custom_code_toggle( $input['enable_custom_code'] ?? 0 ),
		'head_code_blocks'               => erankly_sanitize_custom_code_blocks_field( $input['head_code_blocks'] ?? null, 'head_code_blocks' ),
		'body_open_code_blocks'          => erankly_sanitize_custom_code_blocks_field( $input['body_open_code_blocks'] ?? null, 'body_open_code_blocks' ),
		'body_close_code_blocks'         => erankly_sanitize_custom_code_blocks_field( $input['body_close_code_blocks'] ?? null, 'body_close_code_blocks' ),
		'head_code'                      => erankly_sanitize_custom_code_field( $input['head_code'] ?? null, 'head_code' ),
		'body_open_code'                 => erankly_sanitize_custom_code_field( $input['body_open_code'] ?? null, 'body_open_code' ),
		'body_close_code'                => erankly_sanitize_custom_code_field( $input['body_close_code'] ?? null, 'body_close_code' ),
	);
	$can_migrate_code = ! function_exists( 'get_current_user_id' ) || 0 === (int) get_current_user_id() || current_user_can( 'unfiltered_html' );
	if ( $can_migrate_code ) {
		foreach ( array( 'head_code' => 'head_code_blocks', 'body_open_code' => 'body_open_code_blocks', 'body_close_code' => 'body_close_code_blocks' ) as $legacy_key => $blocks_key ) {
			$legacy_code = trim( (string) $settings[ $legacy_key ] );
			if ( '' === $legacy_code ) {
				continue;
			}
			$existing = is_array( $settings[ $blocks_key ] ) ? array_values( $settings[ $blocks_key ] ) : array();
			if ( count( $existing ) >= erankly_custom_code_max_blocks() ) {
				continue;
			}
			$existing[]             = erankly_custom_code_migrated_block( $legacy_code );
			$settings[ $blocks_key ] = $existing;
			$settings[ $legacy_key ] = '';
		}
	}
	$stored = erankly_get_plugin_option( ERANKLY_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$extension_settings = apply_filters(
		'erankly_preserved_extension_settings',
		array_diff_key( $stored, $defaults ),
		$input
	);
	$extension_settings = is_array( $extension_settings ) ? $extension_settings : array();
	$settings           = array_replace( $extension_settings, $settings );

	if ( ! empty( $settings['enable_local_business'] ) && function_exists( 'erankly_local_business_requirement_gaps' ) ) {
		$gaps = erankly_local_business_requirement_gaps( $settings );

		if ( ! empty( $gaps ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				ERANKLY_OPTION,
				'erankly_local_business_incomplete',
				sprintf(
					/* translators: %s: comma-separated missing field names. */
					__( 'Local business schema is incomplete and will not be emitted until these fields are set: %s.', 'easyrankly' ),
					implode( ', ', $gaps )
				),
				'error'
			);
		}
	}

	return $settings;
}
function erankly_sanitize_robots_preview_value( mixed $value ): string {
	$value = trim( (string) $value );
	if ( '' === $value || ! preg_match( '/^-?\d+$/', $value ) ) {
		return '';
	}
	$number = (int) $value;
	return $number < -1 ? '' : (string) $number;
}
function erankly_general_panel_setting_keys(): array {
	return array(
		'organization_name',
		'website_name',
		'website_description',
		'organization_description',
		'organization_email',
		'organization_phone',
		'organization_legal_name',
		'organization_vat_id',
		'organization_tax_id',
		'organization_street_address',
		'organization_locality',
		'organization_region',
		'organization_postal_code',
		'organization_country',
		'schema_identity',
		'schema_person_user_id',
		'global_post_type_meta',
		'global_post_type_meta_linked',
		'global_taxonomy_meta',
		'global_taxonomy_meta_linked',
		'global_special_meta',
		'preserve_global_special_meta',
	);
}
function erankly_settings_autosave_panels(): array {
	$panels = array(
		'general'  => array( 'keys' => erankly_general_panel_setting_keys() ),
		'advanced' => array(
			'keys' => array(
				'robots_max_image_preview',
				'robots_max_snippet',
				'robots_max_video_preview',
				'robots_nosnippet',
				'robots_noimageindex',
				'robots_notranslate',
				'robots_indexifembedded',
				'robots_txt_extra',
				'noindex_paginated',
				'noindex_paginated_content',
				'nofollow_paginated',
				'noindex_feeds',
				'paginated_title_format',
				'attachment_redirect',
			),
		),
		'sitemap'  => array(
			'keys' => array(
				'enable_news_sitemap',
				'news_sitemap_post_types',
				'news_publication_name',
				'enable_image_sitemap',
				'enable_video_sitemap',
			),
		),
		'features' => array(
			'keys' => array(
				'enable_redirects',
				'enable_sitemap',
				'enable_custom_code',
			),
		),
		'custom-code' => array(
			'keys' => array(
				'head_code_blocks',
				'body_open_code_blocks',
				'body_close_code_blocks',
			),
		),
		'settings' => array(
			'keys' => array(
				'simplified_mode',
				'resolve_placeholders',
			),
		),
		'social'   => array(
			'keys' => array(
				'organization_logo',
				'organization_logo_url',
				'default_social_image_url',
				'default_og_image',
				'default_og_title',
				'default_og_description',
				'default_twitter_title',
				'default_twitter_description',
				'social_defaults_linked',
				'twitter_site',
				'social_profiles',
			),
		),
		'schema'   => array(
			'keys' => array(
				'global_post_type_schema',
				'enable_breadcrumbs',
				'breadcrumb_jsonld_mode',
				'enable_website_search_action',
				'enable_local_business',
				'local_business_type',
				'local_business_page_path',
				'local_business_pages',
				'local_business_price_range',
				'local_business_latitude',
				'local_business_longitude',
				'local_business_menu_url',
				'local_business_cuisine',
				'local_business_hours',
				'global_schema_blocks',
			),
		),
	);
	$panels = apply_filters( 'erankly_settings_autosave_panels', $panels );
	return is_array( $panels ) ? $panels : array();
}
function erankly_save_network_settings(): void {
	check_admin_referer( 'erankly_network_settings' );
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}
	$raw       = isset( $_POST[ ERANKLY_OPTION ] ) ? wp_unslash( (array) $_POST[ ERANKLY_OPTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized field-by-field in erankly_sanitize_settings().
	$sanitized = erankly_sanitize_settings( $raw );
	erankly_update_plugin_option( ERANKLY_OPTION, $sanitized );
	$errors = function_exists( 'get_settings_errors' ) ? get_settings_errors( ERANKLY_OPTION ) : array();
	$user_id = get_current_user_id();
	if ( $user_id > 0 && ! empty( $errors ) ) {
		set_transient( 'erankly_settings_notices_' . $user_id, $errors, 5 * MINUTE_IN_SECONDS );
	}
	$redirect = network_admin_url( 'settings.php?page=erankly' );
	$referer = wp_get_referer();
	if ( $referer ) {
		$query = (string) wp_parse_url( $referer, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $referer_args );
			if ( ! empty( $referer_args['erankly_tab'] ) ) {
				$redirect = add_query_arg( 'erankly_tab', sanitize_key( $referer_args['erankly_tab'] ), $redirect );
			}
			if ( ! empty( $referer_args['erankly_subtab'] ) ) {
				$redirect = add_query_arg( 'erankly_subtab', sanitize_key( $referer_args['erankly_subtab'] ), $redirect );
			}
		}
	}
	$has_error = false;
	foreach ( $errors as $error ) {
		if ( isset( $error['type'] ) && 'error' === $error['type'] ) {
			$has_error = true;
			break;
		}
	}
	wp_safe_redirect( add_query_arg( $has_error ? array( 'erankly_settings_error' => '1' ) : array( 'updated' => '1' ), $redirect ) );
	exit;
}
function erankly_save_site_special_meta(): void {
	check_admin_referer( 'erankly_site_special_meta' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}
	$raw = isset( $_POST[ ERANKLY_OPTION ]['global_special_meta'] ) ? wp_unslash( (array) $_POST[ ERANKLY_OPTION ]['global_special_meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized in erankly_update_special_meta_map() via erankly_sanitize_special_meta_map().
	erankly_update_special_meta_map( $raw );
	$redirect_args = array(
		'page'        => 'erankly',
		'erankly_tab' => 'special-pages',
		'updated'     => '1',
	);
	$referer       = wp_get_referer();
	if ( $referer ) {
		$query = (string) wp_parse_url( $referer, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $referer_args );
			if ( ! empty( $referer_args['erankly_subtab'] ) ) {
				$redirect_args['erankly_subtab'] = sanitize_key( $referer_args['erankly_subtab'] );
			}
		}
	}
	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'options-general.php' ) ) );
	exit;
}
function erankly_normalize_settings_tabs( mixed $tabs, array $screen_context ): array {
	if ( ! is_array( $tabs ) ) {
		return array();
	}
	$reserved = array( 'general', 'features', 'social', 'schema', 'sitemap', 'custom-code', 'settings', 'advanced', 'import-export', 'redirects', 'special-pages' );
	$scope    = (string) ( $screen_context['scope'] ?? 'site' );
	$clean    = array();
	foreach ( $tabs as $slug => $tab ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || in_array( $slug, $reserved, true ) || ! is_array( $tab ) ) {
			continue;
		}
		$label      = isset( $tab['label'] ) ? (string) $tab['label'] : '';
		$tab_scope  = isset( $tab['scope'] ) ? sanitize_key( (string) $tab['scope'] ) : 'site';
		$capability = ( isset( $tab['capability'] ) && '' !== $tab['capability'] ) ? sanitize_key( (string) $tab['capability'] ) : 'manage_options';
		if ( '' === $label || ! in_array( $tab_scope, array( 'site', 'network' ), true ) || $scope !== $tab_scope || ! current_user_can( $capability ) ) {
			continue;
		}
		$clean[ $slug ] = array(
			'label'      => $label,
			'capability' => $capability,
			'scope'      => $tab_scope,
			'position'   => isset( $tab['position'] ) ? (int) $tab['position'] : 100,
			'group'      => isset( $tab['group'] ) ? sanitize_key( (string) $tab['group'] ) : '',
		);
	}
	uksort(
		$clean,
		static function ( string $left, string $right ) use ( $clean ): int {
			$position = $clean[ $left ]['position'] <=> $clean[ $right ]['position'];
			return 0 !== $position ? $position : strcmp( $left, $right );
		}
	);
	return $clean;
}
function erankly_get_global_meta_nav_subtabs( string $setting_key, array $objects, bool $disabled = false ): array {
	$items = array();
	foreach ( array_keys( $objects ) as $key ) {
		$items[] = array(
			'subtab'   => sanitize_key( $setting_key . '-' . $key ),
			'disabled' => $disabled,
		);
	}
	return $items;
}
function erankly_get_special_page_nav_subtabs( array $entities ): array {
	$items = array();
	foreach ( array_keys( $entities ) as $key ) {
		$items[] = array(
			'subtab'   => sanitize_key( 'global_special_meta-all-' . $key ),
			'disabled' => false,
		);
	}
	return $items;
}
function erankly_get_social_nav_subtabs( array $settings ): array {
	$og_title            = isset( $settings['default_og_title'] ) ? (string) $settings['default_og_title'] : '';
	$og_description      = isset( $settings['default_og_description'] ) ? (string) $settings['default_og_description'] : '';
	$twitter_title       = isset( $settings['default_twitter_title'] ) ? (string) $settings['default_twitter_title'] : '';
	$twitter_description = isset( $settings['default_twitter_description'] ) ? (string) $settings['default_twitter_description'] : '';
	$is_linked           = ( ! array_key_exists( 'social_defaults_linked', $settings ) || ! empty( $settings['social_defaults_linked'] ) )
		&& $og_title === $twitter_title
		&& $og_description === $twitter_description;
	$items               = array();
	foreach ( array( 'og', 'twitter' ) as $key ) {
		$items[] = array(
			'subtab'   => sanitize_key( 'social-defaults-' . $key ),
			'disabled' => $is_linked,
		);
	}
	return $items;
}
function erankly_settings_tab_url( string $tab ): string {
	$base = is_network_admin()
		? network_admin_url( 'settings.php' )
		: admin_url( 'options-general.php' );
	return add_query_arg(
		array(
			'page'        => 'erankly',
			'erankly_tab' => sanitize_key( $tab ),
		),
		$base
	);
}
function erankly_render_settings_nav_link( string $slug, string $label, string $active_panel, bool $hidden = false ): void {
	$panel     = 'settings-' . $slug;
	$is_active = $panel === $active_panel;
	?>
	<a class="erankly-settings-nav-item<?php echo $is_active ? ' is-active' : ''; ?>" id="erankly-settings-tab-<?php echo esc_attr( $slug ); ?>" href="<?php echo esc_url( erankly_settings_tab_url( $slug ) ); ?>" data-erankly-tab="<?php echo esc_attr( $panel ); ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?> <?php echo $hidden ? 'hidden' : ''; ?>><?php echo wp_kses( erankly_nav_icon( $slug ), erankly_nav_icon_allowed_html() ); ?><span class="erankly-settings-nav-label"><?php echo esc_html( $label ); ?></span></a>
	<?php
}
