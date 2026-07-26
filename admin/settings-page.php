<?php
/**
 * Settings page.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers settings.
 *
 * @return void
 */
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

/**
 * Sanitizes settings.
 *
 * @param mixed $input Raw input.
 * @return array<string,mixed>
 */
function erankly_sanitize_settings( mixed $input ): array {
	$input                   = is_array( $input ) ? $input : array();
	$defaults                = erankly_default_settings();
	$identity                = isset( $input['schema_identity'] ) ? erankly_sanitize_text( $input['schema_identity'] ) : '';
	$person_user_id          = isset( $input['schema_person_user_id'] ) ? absint( $input['schema_person_user_id'] ) : 0;
	$local_business_types    = erankly_get_local_business_types();
	$local_business_type     = isset( $input['local_business_type'] ) ? erankly_sanitize_text( $input['local_business_type'] ) : 'LocalBusiness';
	$redirect_exclude_admins = isset( $input['redirect_exclude_admins'] ) ? ! empty( $input['redirect_exclude_admins'] ) : (bool) erankly_get_setting( 'redirect_exclude_admins', 0 );
	$ai_enabled              = ! empty( $input['ai_enabled'] ) && erankly_ai_provider_available();

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
		// Site Editor-capable block themes edit these values outside this form,
		// so the settings screen carries only this marker and must not erase the
		// separately edited map.
		$global_special_meta = erankly_get_global_entity_meta_map( 'global_special_meta' );
	}

	$settings = array(
		'organization_name'                   => isset( $input['organization_name'] ) ? erankly_sanitize_text( $input['organization_name'] ) : $defaults['organization_name'],
		'website_name'                        => isset( $input['website_name'] ) ? erankly_sanitize_text( $input['website_name'] ) : $defaults['website_name'],
		'website_description'                 => isset( $input['website_description'] ) ? erankly_sanitize_textarea( $input['website_description'] ) : $defaults['website_description'],
		'organization_logo'                   => isset( $input['organization_logo'] ) ? absint( $input['organization_logo'] ) : $defaults['organization_logo'],
		'organization_logo_url'               => isset( $input['organization_logo_url'] ) ? erankly_sanitize_url_template( $input['organization_logo_url'] ) : $defaults['organization_logo_url'],
		'organization_description'            => isset( $input['organization_description'] ) ? erankly_sanitize_textarea( $input['organization_description'] ) : '',
		'organization_email'                  => isset( $input['organization_email'] ) ? sanitize_email( (string) $input['organization_email'] ) : '',
		'organization_phone'                  => isset( $input['organization_phone'] ) ? erankly_sanitize_phone( $input['organization_phone'] ) : '',
		'organization_legal_name'             => isset( $input['organization_legal_name'] ) ? erankly_sanitize_text( $input['organization_legal_name'] ) : '',
		'organization_vat_id'                 => isset( $input['organization_vat_id'] ) ? erankly_sanitize_text( $input['organization_vat_id'] ) : '',
		'organization_tax_id'                 => isset( $input['organization_tax_id'] ) ? erankly_sanitize_text( $input['organization_tax_id'] ) : '',
		'organization_street_address'         => isset( $input['organization_street_address'] ) ? erankly_sanitize_text( $input['organization_street_address'] ) : '',
		'organization_locality'               => isset( $input['organization_locality'] ) ? erankly_sanitize_text( $input['organization_locality'] ) : '',
		'organization_region'                 => isset( $input['organization_region'] ) ? erankly_sanitize_text( $input['organization_region'] ) : '',
		'organization_postal_code'            => isset( $input['organization_postal_code'] ) ? erankly_sanitize_text( $input['organization_postal_code'] ) : '',
		'organization_country'                => isset( $input['organization_country'] ) ? erankly_sanitize_country_code( $input['organization_country'] ) : '',
		'social_profiles'                     => isset( $input['social_profiles'] ) ? erankly_sanitize_url_list( $input['social_profiles'] ) : '',
		'default_og_image'                    => isset( $input['default_og_image'] ) ? absint( $input['default_og_image'] ) : 0,
		'default_social_image_url'            => isset( $input['default_social_image_url'] ) ? erankly_sanitize_url_template( $input['default_social_image_url'] ) : '',
		'default_og_title'                    => $default_og_title,
		'default_og_description'              => $default_og_description,
		'default_twitter_title'               => $default_twitter_title,
		'default_twitter_description'         => $default_twitter_description,
		'social_defaults_linked'              => $social_defaults_linked ? 1 : 0,
		'twitter_site'                        => isset( $input['twitter_site'] ) ? erankly_sanitize_twitter_handle( $input['twitter_site'] ) : '',
		'global_post_type_meta_linked'        => ! empty( $input['global_post_type_meta_linked'] ) ? 1 : 0,
		'global_post_type_meta'               => isset( $input['global_post_type_meta'] ) ? erankly_sanitize_global_entity_meta( $input['global_post_type_meta'], array_keys( erankly_get_public_post_types() ), ! empty( $input['global_post_type_meta_linked'] ) ) : array(),
		'global_taxonomy_meta_linked'         => ! empty( $input['global_taxonomy_meta_linked'] ) ? 1 : 0,
		'global_taxonomy_meta'                => isset( $input['global_taxonomy_meta'] ) ? erankly_sanitize_global_entity_meta( $input['global_taxonomy_meta'], array_keys( erankly_get_public_taxonomies() ), ! empty( $input['global_taxonomy_meta_linked'] ) ) : array(),
		'global_special_meta'                 => $global_special_meta,
		'schema_identity'                     => 'person' === $identity ? 'person' : 'organization',
		'schema_person_user_id'               => $person_user_id,
		'enable_local_business'               => ! empty( $input['enable_local_business'] ) ? 1 : 0,
		'local_business_type'                 => $local_business_type,
		'local_business_page_path'            => isset( $input['local_business_page_path'] ) ? erankly_sanitize_relative_path( $input['local_business_page_path'] ) : '',
		'local_business_price_range'          => isset( $input['local_business_price_range'] ) ? erankly_trim_text( erankly_sanitize_text( $input['local_business_price_range'] ), 99 ) : '',
		'local_business_latitude'             => isset( $input['local_business_latitude'] ) ? erankly_sanitize_coordinate( $input['local_business_latitude'], -90, 90 ) : '',
		'local_business_longitude'            => isset( $input['local_business_longitude'] ) ? erankly_sanitize_coordinate( $input['local_business_longitude'], -180, 180 ) : '',
		'local_business_menu_url'             => isset( $input['local_business_menu_url'] ) ? erankly_sanitize_url( $input['local_business_menu_url'] ) : '',
		'local_business_cuisine'              => isset( $input['local_business_cuisine'] ) ? erankly_sanitize_text( $input['local_business_cuisine'] ) : '',
		'local_business_hours'                => isset( $input['local_business_hours'] ) ? erankly_sanitize_opening_hours( $input['local_business_hours'] ) : erankly_default_opening_hours(),
		'global_schema_blocks'                => isset( $input['global_schema_blocks'] ) ? erankly_sanitize_schema_blocks( $input['global_schema_blocks'], true ) : array(),
		'simplified_mode'                     => ! empty( $input['simplified_mode'] ) ? 1 : 0,
		'resolve_placeholders'                => ! empty( $input['resolve_placeholders'] ) ? 1 : 0,
		'ai_enabled'                          => $ai_enabled ? 1 : 0,
		// Preserve the saved prompt when the AI tab is not part of the submitted
		// form (e.g. simplified mode, or AI disabled), so other saves never wipe it.
		'ai_prompt_template'                  => isset( $input['ai_prompt_template'] ) && function_exists( 'erankly_ai_sanitize_prompt_template' )
			? erankly_ai_sanitize_prompt_template( $input['ai_prompt_template'] )
			: (string) erankly_get_setting( 'ai_prompt_template', '' ),
		'ai_link_suggestions_prompt_template' => isset( $input['ai_link_suggestions_prompt_template'] ) && function_exists( 'erankly_lb_ai_sanitize_prompt_template' )
			? erankly_lb_ai_sanitize_prompt_template( $input['ai_link_suggestions_prompt_template'] )
			: (string) erankly_get_setting( 'ai_link_suggestions_prompt_template', '' ),
		'ai_content_limit'                    => isset( $input['ai_content_limit'] ) && function_exists( 'erankly_ai_sanitize_content_limit' )
			? erankly_ai_sanitize_content_limit( $input['ai_content_limit'] )
			: (int) erankly_get_setting( 'ai_content_limit', 4000 ),
		'enable_content_analysis'             => ! empty( $input['enable_content_analysis'] ) ? 1 : 0,
		'enable_sitemap'                      => ! empty( $input['enable_sitemap'] ) ? 1 : 0,
		'enable_health'                       => ! empty( $input['enable_health'] ) ? 1 : 0,
		'enable_link_building'                => $ai_enabled && ! empty( $input['enable_link_building'] ) ? 1 : 0,
		'enable_news_sitemap'                 => ! empty( $input['enable_news_sitemap'] ) ? 1 : 0,
		'news_sitemap_post_types'             => isset( $input['news_sitemap_post_types'] ) && is_array( $input['news_sitemap_post_types'] ) ? array_intersect( array_map( 'sanitize_text_field', $input['news_sitemap_post_types'] ), array_keys( erankly_get_public_post_types() ) ) : array( 'post' ),
		'news_publication_name'               => isset( $input['news_publication_name'] ) ? sanitize_text_field( (string) $input['news_publication_name'] ) : '',
		'enable_image_sitemap'                => ! empty( $input['enable_image_sitemap'] ) ? 1 : 0,
		'enable_video_sitemap'                => ! empty( $input['enable_video_sitemap'] ) ? 1 : 0,
		'enable_breadcrumbs'                  => ! empty( $input['enable_breadcrumbs'] ) ? 1 : 0,
		'robots_txt_extra'                    => isset( $input['robots_txt_extra'] ) ? erankly_sanitize_textarea( $input['robots_txt_extra'] ) : '',
		'noindex_paginated'                   => ! empty( $input['noindex_paginated'] ) ? 1 : 0,
		'paginated_title_format'              => isset( $input['paginated_title_format'] ) ? erankly_sanitize_text( $input['paginated_title_format'] ) : '',
		'attachment_redirect'                 => ( isset( $input['attachment_redirect'] ) && in_array( $input['attachment_redirect'], array( 'parent', 'file', 'none' ), true ) ) ? $input['attachment_redirect'] : 'none',
		'robots_max_image_preview_large'      => ! empty( $input['robots_max_image_preview_large'] ) ? 1 : 0,
		'robots_max_snippet'                  => isset( $input['robots_max_snippet'] ) ? erankly_sanitize_robots_preview_value( $input['robots_max_snippet'] ) : '',
		'robots_max_video_preview'            => isset( $input['robots_max_video_preview'] ) ? erankly_sanitize_robots_preview_value( $input['robots_max_video_preview'] ) : '',
		'robots_nosnippet'                    => ! empty( $input['robots_nosnippet'] ) ? 1 : 0,
		'robots_indexifembedded'              => ! empty( $input['robots_indexifembedded'] ) ? 1 : 0,
		'enable_redirects'                    => ! empty( $input['enable_redirects'] ) ? 1 : 0,
		'redirect_exclude_admins'             => $redirect_exclude_admins ? 1 : 0,
		// The form submits the inverted add_head_credit checkbox; imported settings
		// still carry the stored hide_head_credit key, which wins when present.
		'hide_head_credit'                    => isset( $input['hide_head_credit'] ) ? ( ! empty( $input['hide_head_credit'] ) ? 1 : 0 ) : ( ! empty( $input['add_head_credit'] ) ? 0 : 1 ),
		'bloat_remove_emoji'                  => ! empty( $input['bloat_remove_emoji'] ) ? 1 : 0,
		'bloat_remove_generator'              => ! empty( $input['bloat_remove_generator'] ) ? 1 : 0,
		'bloat_remove_feed_links'             => ! empty( $input['bloat_remove_feed_links'] ) ? 1 : 0,
		'bloat_remove_rsd_link'               => ! empty( $input['bloat_remove_rsd_link'] ) ? 1 : 0,
		'bloat_remove_wlwmanifest'            => ! empty( $input['bloat_remove_wlwmanifest'] ) ? 1 : 0,
		'bloat_remove_shortlink'              => ! empty( $input['bloat_remove_shortlink'] ) ? 1 : 0,
		'bloat_remove_rest_link'              => ! empty( $input['bloat_remove_rest_link'] ) ? 1 : 0,
		'bloat_remove_oembed'                 => ! empty( $input['bloat_remove_oembed'] ) ? 1 : 0,
		'bloat_remove_wp_embed'               => ! empty( $input['bloat_remove_wp_embed'] ) ? 1 : 0,
		'bloat_remove_adjacent_posts'         => ! empty( $input['bloat_remove_adjacent_posts'] ) ? 1 : 0,
		'bloat_remove_jquery_migrate'         => ! empty( $input['bloat_remove_jquery_migrate'] ) ? 1 : 0,
		'bloat_disable_self_pingbacks'        => ! empty( $input['bloat_disable_self_pingbacks'] ) ? 1 : 0,
		'bloat_disable_trackbacks'            => ! empty( $input['bloat_disable_trackbacks'] ) ? 1 : 0,
		'bloat_remove_dashicons'              => ! empty( $input['bloat_remove_dashicons'] ) ? 1 : 0,
		'bloat_disable_heartbeat'             => ! empty( $input['bloat_disable_heartbeat'] ) ? 1 : 0,
		'bloat_limit_heartbeat_admin'         => ! empty( $input['bloat_limit_heartbeat_admin'] ) ? 1 : 0,
		'bloat_disable_xmlrpc'                => ! empty( $input['bloat_disable_xmlrpc'] ) ? 1 : 0,
		'bloat_remove_global_styles'          => ! empty( $input['bloat_remove_global_styles'] ) ? 1 : 0,
		'bloat_remove_duotone'                => ! empty( $input['bloat_remove_duotone'] ) ? 1 : 0,
		'bloat_remove_block_library_css'      => ! empty( $input['bloat_remove_block_library_css'] ) ? 1 : 0,
		'bloat_limit_revisions'               => ! empty( $input['bloat_limit_revisions'] ) ? 1 : 0,
		'bloat_disable_speculative_loading'   => ! empty( $input['bloat_disable_speculative_loading'] ) ? 1 : 0,
	);

	$stored = erankly_get_plugin_option( ERANKLY_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	/**
	 * Filters already-stored extension keys that core must preserve unchanged.
	 *
	 * Only keys absent from the current core defaults are eligible. New request
	 * input can never create an unknown key through this path.
	 *
	 * @param array<string,mixed> $extension_settings Existing extension settings.
	 * @param array<string,mixed> $input              Current submitted settings.
	 */
	$extension_settings = apply_filters(
		'erankly_preserved_extension_settings',
		array_diff_key( $stored, $defaults ),
		$input
	);
	$extension_settings = is_array( $extension_settings ) ? $extension_settings : array();

	return array_replace( $extension_settings, $settings );
}

/**
 * Sanitizes max-snippet and max-video-preview values.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_robots_preview_value( mixed $value ): string {
	$value = trim( (string) $value );

	if ( '' === $value || ! preg_match( '/^-?\d+$/', $value ) ) {
		return '';
	}

	$number = (int) $value;

	return $number < -1 ? '' : (string) $number;
}


/**
 * Settings keys owned by the General panel. Used to scope the autosave REST
 * route (registered in easyrankly.php) so it can only ever touch these
 * fields, never the ones that live on other panels (Features, Social,
 * Schema, Sitemap, Advanced, Bloat, AI).
 *
 * @return array<int,string>
 */
function erankly_general_panel_setting_keys(): array {
	return array(
		'organization_name',
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

/**
 * Registry of settings panels that autosave via REST (see
 * erankly_rest_save_settings_panel() in easyrankly.php). Each entry lists the
 * top-level erankly_settings[...] keys that panel owns, and an optional
 * 'normalize' callback, a callable( array $merged, array $changes ): array,
 * run on the merged array before sanitizing, for panels whose sanitizer
 * branches on isset() in a way that only makes sense for a full-page
 * submission (see erankly_normalize_head_credit_for_autosave() for the
 * concrete case this exists for).
 *
 * @return array<string,array{keys:array<int,string>,normalize?:callable}>
 */
function erankly_settings_autosave_panels(): array {
	return array(
		'general'  => array( 'keys' => erankly_general_panel_setting_keys() ),
		'ai'       => array( 'keys' => array( 'ai_prompt_template', 'ai_link_suggestions_prompt_template', 'ai_content_limit' ) ),
		'advanced' => array(
			'keys' => array(
				'robots_max_image_preview_large',
				'robots_max_snippet',
				'robots_max_video_preview',
				'robots_nosnippet',
				'robots_indexifembedded',
				'robots_txt_extra',
				'noindex_paginated',
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
				'enable_health',
				'enable_link_building',
				'enable_content_analysis',
				'ai_enabled',
			),
		),
		'bloat'    => array(
			'keys' => array(
				'bloat_remove_emoji',
				'bloat_remove_generator',
				'bloat_remove_feed_links',
				'bloat_remove_rsd_link',
				'bloat_remove_wlwmanifest',
				'bloat_remove_shortlink',
				'bloat_remove_rest_link',
				'bloat_remove_oembed',
				'bloat_remove_wp_embed',
				'bloat_remove_adjacent_posts',
				'bloat_remove_jquery_migrate',
				'bloat_disable_self_pingbacks',
				'bloat_disable_trackbacks',
				'bloat_remove_dashicons',
				'bloat_disable_heartbeat',
				'bloat_limit_heartbeat_admin',
				'bloat_disable_xmlrpc',
				'bloat_remove_global_styles',
				'bloat_remove_duotone',
				'bloat_remove_block_library_css',
				'bloat_limit_revisions',
				'bloat_disable_speculative_loading',
			),
		),
		'settings' => array(
			'keys'      => array(
				'simplified_mode',
				'add_head_credit',
				'resolve_placeholders',
				'redirect_exclude_admins',
			),
			'normalize' => 'erankly_normalize_head_credit_for_autosave',
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
				'enable_breadcrumbs',
				'enable_local_business',
				'local_business_type',
				'local_business_page_path',
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
}

/**
 * Restores erankly_sanitize_settings()'s hide_head_credit/add_head_credit
 * mutual exclusivity for the Settings-panel autosave.
 *
 * The classic full-page form never submits a hide_head_credit key (only a
 * live add_head_credit checkbox), and a legacy import always carries
 * hide_head_credit but never add_head_credit. The sanitizer relies on that
 * either/or to know which one to trust. Autosave's merge onto
 * erankly_get_settings() breaks that invariant: hide_head_credit is a normal
 * stored key, so it's always present after the merge, which would make the
 * sanitizer ignore a freshly submitted add_head_credit forever. Stripping it
 * here restores the invariant for this one caller only, without touching the
 * shared sanitizer that the classic form (and imports) still depend on.
 *
 * @param array<string,mixed> $merged  Settings merged from erankly_get_settings() and this request's whitelisted changes.
 * @param array<string,mixed> $changes This request's own whitelisted payload, before the merge.
 * @return array<string,mixed>
 */
function erankly_normalize_head_credit_for_autosave( array $merged, array $changes ): array {
	// Defensive: the current JS always sends add_head_credit explicitly
	// (checked or not), but if a payload ever omitted it entirely, fall back
	// to the value already on record instead of letting its absence read as
	// "unchecked" and flip a setting this request never touched.
	if ( ! array_key_exists( 'add_head_credit', $changes ) ) {
		$merged['add_head_credit'] = empty( $merged['hide_head_credit'] ) ? 1 : 0;
	}

	unset( $merged['hide_head_credit'] );

	return $merged;
}

/**
 * Saves settings submitted from the Network Admin settings page.
 *
 * @return void
 */
function erankly_save_network_settings(): void {
	check_admin_referer( 'erankly_network_settings' );

	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	$raw       = isset( $_POST[ ERANKLY_OPTION ] ) ? wp_unslash( (array) $_POST[ ERANKLY_OPTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside erankly_sanitize_settings().
	$sanitized = erankly_sanitize_settings( $raw );

	erankly_update_plugin_option( ERANKLY_OPTION, $sanitized );

	$redirect = network_admin_url( 'settings.php?page=erankly' );

	// Keep the redirect on whatever tab was active so saving never bounces back
	// to General. The form's _wp_http_referer carries the active tab (the admin
	// JS syncs it as the user switches tabs).
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

	wp_safe_redirect( add_query_arg( 'updated', '1', $redirect ) );
	exit;
}

/**
 * Saves special-page metadata submitted from a subsite's "General" tab on Multisite.
 *
 * Special pages are stored per site (ERANKLY_SPECIAL_META_OPTION) rather than in
 * the network-wide settings, so each site keeps its own homepage / archive metadata.
 *
 * @return void
 */
function erankly_save_site_special_meta(): void {
	check_admin_referer( 'erankly_site_special_meta' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	$raw = isset( $_POST[ ERANKLY_OPTION ]['global_special_meta'] ) ? wp_unslash( (array) $_POST[ ERANKLY_OPTION ]['global_special_meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside erankly_update_special_meta_map().

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


/**
 * Normalises tabs registered through the `erankly_settings_tabs` filter.
 *
 * Add-ons register a settings tab by returning an entry keyed by a tab slug:
 * array( 'my-addon' => array( 'label' => 'My Add-on', 'capability' => 'manage_options' ) ).
 * The body of each tab is printed by the matching `erankly_render_settings_tab_{$slug}`
 * action. Malformed, wrong-scope and unauthorized entries are dropped.
 *
 * @param mixed               $tabs           Raw filter output.
 * @param array<string,mixed> $screen_context Current settings screen context.
 * @return array<string,array{label:string,capability:string,scope:string,position:int}>
 */
function erankly_normalize_settings_tabs( mixed $tabs, array $screen_context ): array {
	if ( ! is_array( $tabs ) ) {
		return array();
	}

	$reserved = array( 'general', 'features', 'social', 'schema', 'sitemap', 'health', 'settings', 'advanced', 'bloat', 'import-export', 'redirects', 'special-pages', 'links', 'ai' );
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

/**
 * Builds inner-tab routing entries for a linked/defaults tab group.
 *
 * @param string                                 $setting_key Settings array key.
 * @param array<string,WP_Post_Type|WP_Taxonomy> $objects     Public objects.
 * @param bool                                   $disabled    Whether the internal tabs are currently disabled.
 * @return array<int,array{subtab:string,disabled:bool}>
 */
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

/**
 * Builds inner-tab routing entries for special-page defaults.
 *
 * @param array<string,string> $entities Special-page entity labels.
 * @return array<int,array{subtab:string,disabled:bool}>
 */
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

/**
 * Builds inner-tab routing entries for social defaults.
 *
 * @param array<string,mixed> $settings Current settings.
 * @return array<int,array{subtab:string,disabled:bool}>
 */
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

/**
 * Returns a real, no-JavaScript URL for a top-level settings tab.
 *
 * @param string $tab Tab slug.
 * @return string
 */
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

/**
 * Prints one server-routed settings navigation link.
 *
 * @param string $slug         Tab slug.
 * @param string $label        Visible label.
 * @param string $active_panel Active panel ID.
 * @param bool   $hidden       Whether the link is currently unavailable.
 * @return void
 */
function erankly_render_settings_nav_link( string $slug, string $label, string $active_panel, bool $hidden = false ): void {
	$panel     = 'settings-' . $slug;
	$is_active = $panel === $active_panel;
	?>
	<a class="erankly-settings-nav-item<?php echo $is_active ? ' is-active' : ''; ?>" id="erankly-settings-tab-<?php echo esc_attr( $slug ); ?>" href="<?php echo esc_url( erankly_settings_tab_url( $slug ) ); ?>" data-erankly-tab="<?php echo esc_attr( $panel ); ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?> <?php echo 'advanced' === $slug ? 'data-erankly-advanced-tab' : ''; ?> <?php echo $hidden ? 'hidden' : ''; ?>><?php echo esc_html( $label ); ?></a>
	<?php
}

/**
 * Renders settings page.
 *
 * @return void
 */
function erankly_render_settings_page(): void {
	$required_cap = is_network_admin() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	$settings                 = erankly_get_settings();
	$redirects_enabled        = erankly_redirects_enabled();
	$sitemap_enabled          = erankly_sitemap_enabled();
	$health_enabled           = erankly_health_enabled();
	$ai_provider_available    = erankly_ai_provider_available();
	$is_site_admin_on_network = is_multisite() && ! is_network_admin();
	// Health and Redirects are per-site features: show them on individual sites,
	// never in the Network Admin global options.
	$show_health_tab    = $health_enabled && ! is_network_admin();
	$show_redirects_tab = $redirects_enabled && ! is_network_admin();
	$show_links_tab     = erankly_link_building_enabled() && ! is_network_admin();
	// The AI tab (prompt editor) lives in the main settings form, shown only in
	// advanced mode while AI features are enabled.
	$show_ai_tab              = ! $is_site_admin_on_network && erankly_ai_module_enabled() && empty( $settings['simplified_mode'] );
	$show_sitemap_tab         = ! $is_site_admin_on_network && $sitemap_enabled;
	$show_feature_modules_nav = $show_redirects_tab || $show_sitemap_tab || $show_health_tab || $show_links_tab || $show_ai_tab;
	// Special-page metadata is per site on Multisite: edited from each subsite's
	// "General" tab unless the block-theme Site Editor panels are available.
	$show_site_special_tab = $is_site_admin_on_network && ! erankly_use_site_editor_special_page_panels();
	$site_panels           = array();

	if ( $show_site_special_tab ) {
		$site_panels[] = 'settings-special-pages';
	}
	if ( $show_health_tab ) {
		$site_panels[] = 'settings-health';
	}
	if ( $show_links_tab ) {
		$site_panels[] = 'settings-links';
	}
	if ( $show_redirects_tab ) {
		$site_panels[] = 'settings-redirects';
	}

	// Import / Export is network-admin-only on Multisite because the panel itself
	// requires manage_network_options. Per-site admins must never see it there, or
	// they get routed to a tab whose body renders empty.
	$show_import_export_tab = ! is_multisite() || is_network_admin();
	$requested_tab          = isset( $_GET['erankly_tab'] ) ? sanitize_key( wp_unslash( $_GET['erankly_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
	$requested_subtab       = isset( $_GET['erankly_subtab'] ) ? sanitize_key( wp_unslash( $_GET['erankly_subtab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
	$active_panel           = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-general';
	$active_subtab          = '';
	$screen                 = get_current_screen();
	$screen_context         = array(
		'screen_id'   => $screen instanceof WP_Screen ? $screen->id : '',
		'scope'       => is_network_admin() ? 'network' : 'site',
		'current_tab' => $requested_tab,
	);

	if ( '' !== $requested_tab ) {
		$requested_tab = erankly_admin_resolve_settings_tab( $requested_tab );
	}

	$subtab_panel_map = array();
	if ( '' !== $requested_subtab ) {
		$post_type_objects  = erankly_get_public_post_types();
		$taxonomy_objects   = erankly_get_public_taxonomies();
		$special_page_items = erankly_special_page_keys();
		$general_subtabs    = array();

		if ( ! $is_site_admin_on_network ) {
			$general_subtabs = array_merge(
				erankly_get_global_meta_nav_subtabs(
					'global_post_type_meta',
					$post_type_objects,
					! array_key_exists( 'global_post_type_meta_linked', $settings ) || ! empty( $settings['global_post_type_meta_linked'] )
				),
				erankly_get_global_meta_nav_subtabs(
					'global_taxonomy_meta',
					$taxonomy_objects,
					! array_key_exists( 'global_taxonomy_meta_linked', $settings ) || ! empty( $settings['global_taxonomy_meta_linked'] )
				)
			);

			if ( ! is_multisite() && ! erankly_use_site_editor_special_page_panels() ) {
				$general_subtabs = array_merge(
					$general_subtabs,
					erankly_get_special_page_nav_subtabs( $special_page_items )
				);
			}
		}

		$site_special_subtabs = $show_site_special_tab ? erankly_get_special_page_nav_subtabs( $special_page_items ) : array();
		$social_subtabs       = $is_site_admin_on_network ? array() : erankly_get_social_nav_subtabs( $settings );
		foreach ( $general_subtabs as $item ) {
			if ( empty( $item['disabled'] ) ) {
				$subtab_panel_map[ $item['subtab'] ] = 'settings-general';
			}
		}
		foreach ( $site_special_subtabs as $item ) {
			$subtab_panel_map[ $item['subtab'] ] = 'settings-special-pages';
		}
		foreach ( $social_subtabs as $item ) {
			if ( empty( $item['disabled'] ) ) {
				$subtab_panel_map[ $item['subtab'] ] = 'settings-social';
			}
		}
	}

	/**
	 * Filters the third-party tabs added to the EasyRankly settings screen.
	 *
	 * Each entry is keyed by a tab slug and provides a label and an optional capability.
	 * The tab body is printed by the `erankly_render_settings_tab_{$slug}` action.
	 *
	 * @since 2.1.0 Descriptor schema and screen context frozen for extensions.
	 *
	 * @param array<string,array<string,string>> $tabs Registered extension tabs.
	 */
	$extra_tabs = erankly_normalize_settings_tabs(
		apply_filters( 'erankly_settings_tabs', array(), $screen_context ),
		$screen_context
	);

	// Map short tab names to panel IDs so server-side routing works for every tab.
	// used by the post-save redirect and the no-JS fallback.
	$tab_panel_map = array(
		'general'       => 'settings-general',
		'features'      => 'settings-features',
		'social'        => 'settings-social',
		'schema'        => 'settings-schema',
		'sitemap'       => 'settings-sitemap',
		'health'        => 'settings-health',
		'links'         => 'settings-links',
		'settings'      => 'settings-settings',
		'advanced'      => 'settings-advanced',
		'ai'            => 'settings-ai',
		'bloat'         => 'settings-bloat',
		'import-export' => 'settings-import-export',
		'redirects'     => 'settings-redirects',
		'special-pages' => 'settings-special-pages',
	);

	// Let extension tabs participate in server-side routing / deep-linking.
	foreach ( $extra_tabs as $extra_slug => $extra_tab ) {
		$tab_panel_map[ $extra_slug ] = 'settings-' . $extra_slug;
	}

	if ( '' !== $requested_tab && isset( $tab_panel_map[ $requested_tab ] ) ) {
		$candidate = $tab_panel_map[ $requested_tab ];

		// Site admins on a per-site network admin can only access available
		// per-site panels.
		if ( ! $is_site_admin_on_network || in_array( $candidate, $site_panels, true ) ) {
			$active_panel = $candidate;
		}
	}

	if ( '' !== $requested_subtab && isset( $subtab_panel_map[ $requested_subtab ] ) ) {
		$candidate = $subtab_panel_map[ $requested_subtab ];

		if ( ! $is_site_admin_on_network || in_array( $candidate, $site_panels, true ) ) {
			$active_panel  = $candidate;
			$active_subtab = $requested_subtab;
		}
	}

	if ( 'settings-redirects' === $active_panel && ! $show_redirects_tab ) {
		$active_panel  = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-features';
		$active_subtab = '';
	}

	if ( 'settings-health' === $active_panel && ! $show_health_tab ) {
		$active_panel  = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-features';
		$active_subtab = '';
	}

	if ( 'settings-links' === $active_panel && ! $show_links_tab ) {
		$active_panel  = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-features';
		$active_subtab = '';
	}

	if ( 'settings-ai' === $active_panel && ! $show_ai_tab ) {
		$active_panel  = 'settings-features';
		$active_subtab = '';
	}

	// On per-site network admin, default to the first panel that is actually
	// available for the active theme and enabled features.
	if ( $is_site_admin_on_network && ! in_array( $active_panel, $site_panels, true ) ) {
		$active_panel  = $site_panels[0] ?? '';
		$active_subtab = '';
	}

	if ( in_array( $active_panel, array( 'settings-general', 'settings-social', 'settings-schema', 'settings-advanced', 'settings-special-pages' ), true ) ) {
		require_once ERANKLY_PATH . 'admin/field-renderers.php';
	}

	if ( in_array( $active_panel, array( 'settings-general', 'settings-social', 'settings-schema', 'settings-special-pages' ), true ) ) {
		require_once ERANKLY_PATH . 'admin/settings/renderers.php';
	}

	// Compute panel-specific data only after routing has selected the one renderer
	// that will execute on this request.
	if ( 'settings-general' === $active_panel ) {
		$schema_person_user_id    = isset( $settings['schema_person_user_id'] ) ? absint( $settings['schema_person_user_id'] ) : 0;
		$schema_person_user       = $schema_person_user_id > 0 ? get_userdata( $schema_person_user_id ) : false;
		$show_organization_fields = 'person' !== $settings['schema_identity'];
	}

	if ( 'settings-schema' === $active_panel ) {
		$global_schema_blocks = isset( $settings['global_schema_blocks'] ) && is_array( $settings['global_schema_blocks'] ) ? $settings['global_schema_blocks'] : array();
		$global_schema_name   = ERANKLY_OPTION . '[global_schema_blocks]';
	}

	if ( 'settings-sitemap' === $active_panel ) {
		erankly_load_sitemap_helpers();
		$sitemap_url = erankly_get_sitemap_url( '/wp-sitemap.xml' );
	}

	if ( 'settings-bloat' === $active_panel ) {
		// Simplified mode's master toggle drives only cleanups with no functional
		// side effects; riskier options remain individually controlled.
		$bloat_safe_keys   = array( 'bloat_remove_emoji', 'bloat_remove_generator', 'bloat_remove_rsd_link', 'bloat_remove_wlwmanifest', 'bloat_remove_shortlink', 'bloat_remove_rest_link', 'bloat_disable_self_pingbacks' );
		$safe_bloat_active = array_reduce( $bloat_safe_keys, static fn( bool $carry, string $key ) => $carry && ! empty( $settings[ $key ] ), true );
	}

	// With every built-in panel now autosaving, $show_settings_submit ends up
	// false for every reachable $active_panel today, but the computation
	// itself isn't dead: it's what keeps the button correctly hidden on the
	// very first server-rendered paint (the JS in bindSettingsTabs()'s
	// activate() only corrects it after DOMContentLoaded, which would
	// otherwise flash the button briefly), and it'll matter again the moment
	// a future panel, built-in or a third-party extension tab, doesn't
	// autosave.
	$show_settings_submit = ! $is_site_admin_on_network && ! in_array( $active_panel, array( 'settings-health', 'settings-links', 'settings-import-export', 'settings-redirects' ), true );

	// Panels that autosave via REST (see erankly_settings_autosave_panels())
	// no longer need the shared button once they're actually reachable.
	// single-site, or Network Admin on Multisite (a per-site admin on
	// Multisite never gets these tabs at all, mirroring $is_site_admin_on_network).
	// Driven entirely by the registry so this never needs editing again as
	// panels are added.
	if ( ! is_multisite() || is_network_admin() ) {
		$autosave_panel_slugs = array_map(
			static fn( $key ) => 'settings-' . $key,
			array_keys( erankly_settings_autosave_panels() )
		);

		if ( in_array( $active_panel, $autosave_panel_slugs, true ) ) {
			$show_settings_submit = false;
		}
	}

	// Extension tabs render their own form, so hide the shared "Save Changes" button on them.
	if ( in_array( $active_panel, array_map( static fn( $slug ) => 'settings-' . $slug, array_keys( $extra_tabs ) ), true ) ) {
		$show_settings_submit = false;
	}
	?>
	<div class="wrap erankly-settings">
		<?php
		if ( is_network_admin() ) {
			if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'easyrankly' ) . '</p></div>';
			}
		} else {
			// Per-site special-page saves redirect with updated=1 (no Settings API errors store).
			if ( $is_site_admin_on_network && isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'easyrankly' ) . '</p></div>';
			}
			settings_errors( ERANKLY_OPTION );
		}
		?>
		<?php if ( $is_site_admin_on_network ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %s: Network Admin settings URL. */
					esc_html__( 'Global SEO settings are managed from the %s.', 'easyrankly' ),
					'<a href="' . esc_url( network_admin_url( 'settings.php?page=erankly' ) ) . '">' . esc_html__( 'Network Admin', 'easyrankly' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php endif; ?>
		<div class="erankly-settings-layout">
			<div class="erankly-settings-sidebar-nav" data-erankly-sidebar-nav>
				<h1><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h1>
				<button type="button" class="erankly-settings-sidebar-toggle" aria-expanded="false" data-erankly-sidebar-toggle>
					<span data-erankly-sidebar-toggle-label></span>
				</button>
				<nav class="erankly-settings-nav-tablist" aria-label="<?php esc_attr_e( 'Plugin settings', 'easyrankly' ); ?>" data-erankly-settings-tablist data-erankly-server-tabs data-erankly-active-panel="<?php echo esc_attr( $active_panel ); ?>" data-erankly-active-subtab="<?php echo esc_attr( $active_subtab ); ?>">
				<?php if ( ! $is_site_admin_on_network ) : ?>
					<?php erankly_render_settings_nav_link( 'general', __( 'General', 'easyrankly' ), $active_panel ); ?>
					<?php erankly_render_settings_nav_link( 'social', __( 'Social', 'easyrankly' ), $active_panel ); ?>
					<?php erankly_render_settings_nav_link( 'schema', __( 'Schema', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( $show_site_special_tab ) : ?>
					<?php erankly_render_settings_nav_link( 'special-pages', __( 'General', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( ! $is_site_admin_on_network ) : ?>
					<?php erankly_render_settings_nav_link( 'bloat', __( 'Bloat', 'easyrankly' ), $active_panel ); ?>
					<?php erankly_render_settings_nav_link( 'advanced', __( 'Advanced', 'easyrankly' ), $active_panel, ! empty( $settings['simplified_mode'] ) ); ?>
					<?php erankly_render_settings_nav_link( 'features', __( 'Features', 'easyrankly' ), $active_panel ); ?>
					<?php erankly_render_settings_nav_link( 'settings', __( 'Settings', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( $show_import_export_tab ) : ?>
					<?php erankly_render_settings_nav_link( 'import-export', __( 'Import / Export', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( $show_feature_modules_nav ) : ?>
				<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-feature-modules">
					<span class="erankly-settings-nav-heading" id="erankly-settings-nav-feature-modules"><?php esc_html_e( 'Feature modules', 'easyrankly' ); ?></span>
					<?php if ( $show_redirects_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'redirects', __( 'Redirects', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
					<?php if ( $show_sitemap_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'sitemap', __( 'Sitemap', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
					<?php if ( $show_health_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'health', __( 'Health', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
					<?php if ( $show_links_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'links', __( 'Internal links', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
					<?php if ( $show_ai_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'ai', __( 'AI', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<?php
				$visible_extra_tabs = array();
				foreach ( $extra_tabs as $extra_slug => $extra_tab ) {
					if ( current_user_can( $extra_tab['capability'] ) ) {
						$visible_extra_tabs[ $extra_slug ] = $extra_tab;
					}
				}
				?>
				<?php if ( ! empty( $visible_extra_tabs ) ) : ?>
				<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-modules">
					<span class="erankly-settings-nav-heading" id="erankly-settings-nav-modules"><?php esc_html_e( 'Additional Modules', 'easyrankly' ); ?></span>
					<?php foreach ( $visible_extra_tabs as $extra_slug => $extra_tab ) : ?>
						<?php erankly_render_settings_nav_link( $extra_slug, $extra_tab['label'], $active_panel ); ?>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-useful-resources">
					<span class="erankly-settings-nav-heading" id="erankly-settings-nav-useful-resources"><?php esc_html_e( 'Useful resources', 'easyrankly' ); ?></span>
					<a class="erankly-settings-nav-item" href="<?php echo esc_url( add_query_arg( 'utm_source', 'easyrankly-settings-nav', 'https://docs.easyrankly.com/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'easyrankly' ); ?></a>
				</div>
				</nav>
				<span class="erankly-autosave-status" data-erankly-autosave-status aria-live="polite"></span>
			</div>

			<div class="erankly-settings-content">
				<?php if ( is_network_admin() ) : ?>
				<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=erankly_network_save' ) ); ?>">
					<?php wp_nonce_field( 'erankly_network_settings' ); ?>
				<?php elseif ( ! $is_site_admin_on_network ) : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'erankly' ); ?>
				<?php endif; ?>

					<?php if ( 'settings-features' === $active_panel ) : ?>
							<?php erankly_render_settings_panel_features( $settings, $redirects_enabled, $sitemap_enabled, $health_enabled, $ai_provider_available, $active_panel ); ?>
					<?php endif; ?>

					<?php if ( 'settings-general' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_general( $settings, $schema_person_user_id, $schema_person_user, $show_organization_fields, $active_panel ); ?>
					<?php endif; ?>

					<?php if ( 'settings-social' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_social( $settings ); ?>
					<?php endif; ?>

					<?php if ( 'settings-schema' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_schema( $settings, $global_schema_blocks, $global_schema_name ); ?>
					<?php endif; ?>

				<?php if ( $sitemap_enabled && 'settings-sitemap' === $active_panel ) : ?>
					<?php erankly_render_settings_panel_sitemap( $settings, $sitemap_url ); ?>
				<?php endif; ?>

					<?php if ( 'settings-settings' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_settings( $settings, $redirects_enabled ); ?>
					<?php endif; ?>

					<?php if ( 'settings-advanced' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_advanced( $settings ); ?>
					<?php endif; ?>

					<?php if ( 'settings-bloat' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_bloat( $settings, $safe_bloat_active ); ?>
					<?php endif; ?>

					<?php if ( $show_ai_tab && 'settings-ai' === $active_panel ) : ?>
						<?php erankly_ai_render_settings_panel( $active_panel ); ?>
					<?php endif; ?>

					<div class="erankly-settings-submit" data-erankly-settings-submit <?php echo $show_settings_submit ? '' : 'hidden'; ?>>
						<?php submit_button(); ?>
					</div>
				<?php if ( ! $is_site_admin_on_network ) : ?>
				</form>
				<?php endif; ?>

			<?php if ( $show_site_special_tab && 'settings-special-pages' === $active_panel ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-special-pages' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-special-pages" role="tabpanel" aria-labelledby="erankly-settings-tab-special-pages" data-erankly-settings-panel="settings-special-pages" data-erankly-standalone-panel <?php echo 'settings-special-pages' === $active_panel ? '' : 'hidden'; ?>>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'erankly_site_special_meta' ); ?>
					<input type="hidden" name="action" value="erankly_save_site_special_meta">
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Special pages and archives', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<?php erankly_render_special_page_defaults( erankly_special_page_keys(), array( 'global_special_meta' => erankly_get_site_special_meta() ) ); ?>
						</div>
					</div>
				</form>
			</div>
			<?php endif; ?>

			<?php if ( $show_import_export_tab && 'settings-import-export' === $active_panel && function_exists( 'erankly_import_export_render_panel' ) ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-import-export' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-import-export" role="tabpanel" aria-labelledby="erankly-settings-tab-import-export" data-erankly-settings-panel="settings-import-export" <?php echo 'settings-import-export' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_import_export_render_panel(); ?>
			</div>
			<?php endif; ?>

			<?php if ( $show_health_tab && 'settings-health' === $active_panel && function_exists( 'erankly_health_render_panel' ) ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-health' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-health" role="tabpanel" aria-labelledby="erankly-settings-tab-health" data-erankly-settings-panel="settings-health" <?php echo 'settings-health' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_health_render_panel(); ?>
			</div>
			<?php endif; ?>

			<?php if ( $show_links_tab && 'settings-links' === $active_panel && function_exists( 'erankly_lb_render_panel' ) ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-links' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-links" role="tabpanel" aria-labelledby="erankly-settings-tab-links" data-erankly-settings-panel="settings-links" <?php echo 'settings-links' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_lb_render_panel(); ?>
			</div>
			<?php endif; ?>

			<?php if ( $show_redirects_tab && 'settings-redirects' === $active_panel && function_exists( 'erankly_redirects_render_panel' ) ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-redirects' === $active_panel ? ' is-active' : ''; ?>" role="tabpanel" aria-labelledby="erankly-settings-tab-redirects" data-erankly-settings-panel="settings-redirects" <?php echo 'settings-redirects' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_redirects_render_panel(); ?>
			</div>
			<?php endif; ?>

			<?php
			foreach ( $extra_tabs as $extra_slug => $extra_tab ) :
				if ( ! current_user_can( $extra_tab['capability'] ) ) {
					continue;
				}
				$extra_panel = 'settings-' . $extra_slug;
				if ( $extra_panel !== $active_panel ) {
					continue;
				}
				?>
			<div class="erankly-tab-panel<?php echo $extra_panel === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-<?php echo esc_attr( $extra_slug ); ?>" role="tabpanel" aria-labelledby="erankly-settings-tab-<?php echo esc_attr( $extra_slug ); ?>" data-erankly-settings-panel="<?php echo esc_attr( $extra_panel ); ?>" data-erankly-standalone-panel <?php echo $extra_panel === $active_panel ? '' : 'hidden'; ?>>
				<?php
				/**
				 * Renders the body of a third-party settings tab.
				 *
				 * The dynamic portion of the hook name is the tab slug registered through the
				 * `erankly_settings_tabs` filter.
				 *
				 * @since 2.1.0 Screen context frozen for extension renderers.
				 *
				 * @param array<string,mixed> $screen_context Current screen context.
				 */
				do_action( 'erankly_render_settings_tab_' . $extra_slug, $screen_context );
				?>
			</div>
			<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}
