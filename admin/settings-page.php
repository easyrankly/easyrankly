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
		'organization_name'              => isset( $input['organization_name'] ) ? erankly_sanitize_text( $input['organization_name'] ) : $defaults['organization_name'],
		'organization_logo'              => isset( $input['organization_logo'] ) ? absint( $input['organization_logo'] ) : $defaults['organization_logo'],
		'organization_logo_url'          => isset( $input['organization_logo_url'] ) ? erankly_sanitize_url_template( $input['organization_logo_url'] ) : $defaults['organization_logo_url'],
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
		'default_og_image'               => isset( $input['default_og_image'] ) ? absint( $input['default_og_image'] ) : 0,
		'default_social_image_url'       => isset( $input['default_social_image_url'] ) ? erankly_sanitize_url_template( $input['default_social_image_url'] ) : '',
		'default_og_title'               => $default_og_title,
		'default_og_description'         => $default_og_description,
		'default_twitter_title'          => $default_twitter_title,
		'default_twitter_description'    => $default_twitter_description,
		'social_defaults_linked'         => $social_defaults_linked ? 1 : 0,
		'twitter_site'                   => isset( $input['twitter_site'] ) ? erankly_sanitize_twitter_handle( $input['twitter_site'] ) : '',
		'global_post_type_meta_linked'   => ! empty( $input['global_post_type_meta_linked'] ) ? 1 : 0,
		'global_post_type_meta'          => isset( $input['global_post_type_meta'] ) ? erankly_sanitize_global_entity_meta( $input['global_post_type_meta'], array_keys( erankly_get_public_post_types() ), ! empty( $input['global_post_type_meta_linked'] ) ) : array(),
		'global_taxonomy_meta_linked'    => ! empty( $input['global_taxonomy_meta_linked'] ) ? 1 : 0,
		'global_taxonomy_meta'           => isset( $input['global_taxonomy_meta'] ) ? erankly_sanitize_global_entity_meta( $input['global_taxonomy_meta'], array_keys( erankly_get_public_taxonomies() ), ! empty( $input['global_taxonomy_meta_linked'] ) ) : array(),
		'global_special_meta'            => $global_special_meta,
		'schema_identity'                => 'person' === $identity ? 'person' : 'organization',
		'schema_person_user_id'          => $person_user_id,
		'enable_local_business'          => ! empty( $input['enable_local_business'] ) ? 1 : 0,
		'local_business_type'            => $local_business_type,
		'local_business_page_path'       => isset( $input['local_business_page_path'] ) ? erankly_sanitize_relative_path( $input['local_business_page_path'] ) : '',
		'local_business_price_range'     => isset( $input['local_business_price_range'] ) ? erankly_trim_text( erankly_sanitize_text( $input['local_business_price_range'] ), 99 ) : '',
		'local_business_latitude'        => isset( $input['local_business_latitude'] ) ? erankly_sanitize_coordinate( $input['local_business_latitude'], -90, 90 ) : '',
		'local_business_longitude'       => isset( $input['local_business_longitude'] ) ? erankly_sanitize_coordinate( $input['local_business_longitude'], -180, 180 ) : '',
		'local_business_menu_url'        => isset( $input['local_business_menu_url'] ) ? erankly_sanitize_url( $input['local_business_menu_url'] ) : '',
		'local_business_cuisine'         => isset( $input['local_business_cuisine'] ) ? erankly_sanitize_text( $input['local_business_cuisine'] ) : '',
		'local_business_hours'           => isset( $input['local_business_hours'] ) ? erankly_sanitize_opening_hours( $input['local_business_hours'] ) : erankly_default_opening_hours(),
		'global_schema_blocks'           => isset( $input['global_schema_blocks'] ) ? erankly_sanitize_schema_blocks( $input['global_schema_blocks'], true ) : array(),
		'simplified_mode'                => ! empty( $input['simplified_mode'] ) ? 1 : 0,
		'resolve_placeholders'           => ! empty( $input['resolve_placeholders'] ) ? 1 : 0,
		'ai_enabled'                     => ! empty( $input['ai_enabled'] ) ? 1 : 0,
		// Preserve the saved prompt when the AI tab is not part of the submitted
		// form (e.g. simplified mode, or AI disabled), so other saves never wipe it.
		'ai_prompt_template'             => isset( $input['ai_prompt_template'] ) && function_exists( 'erankly_ai_sanitize_prompt_template' )
			? erankly_ai_sanitize_prompt_template( $input['ai_prompt_template'] )
			: (string) erankly_get_setting( 'ai_prompt_template', '' ),
		'enable_seo_checklist'           => ! empty( $input['enable_seo_checklist'] ) ? 1 : 0,
		'enable_sitemap'                 => ! empty( $input['enable_sitemap'] ) ? 1 : 0,
		'enable_health'                  => ! empty( $input['enable_health'] ) ? 1 : 0,
		'enable_news_sitemap'            => ! empty( $input['enable_news_sitemap'] ) ? 1 : 0,
		'news_sitemap_post_types'        => isset( $input['news_sitemap_post_types'] ) && is_array( $input['news_sitemap_post_types'] ) ? array_intersect( array_map( 'sanitize_text_field', $input['news_sitemap_post_types'] ), array_keys( erankly_get_public_post_types() ) ) : array( 'post' ),
		'news_publication_name'          => isset( $input['news_publication_name'] ) ? sanitize_text_field( (string) $input['news_publication_name'] ) : '',
		'enable_image_sitemap'           => ! empty( $input['enable_image_sitemap'] ) ? 1 : 0,
		'enable_video_sitemap'           => ! empty( $input['enable_video_sitemap'] ) ? 1 : 0,
		'enable_breadcrumbs'             => ! empty( $input['enable_breadcrumbs'] ) ? 1 : 0,
		'robots_txt_extra'               => isset( $input['robots_txt_extra'] ) ? erankly_sanitize_textarea( $input['robots_txt_extra'] ) : '',
		'noindex_paginated'              => ! empty( $input['noindex_paginated'] ) ? 1 : 0,
		'paginated_title_format'         => isset( $input['paginated_title_format'] ) ? erankly_sanitize_text( $input['paginated_title_format'] ) : '',
		'attachment_redirect'            => ( isset( $input['attachment_redirect'] ) && in_array( $input['attachment_redirect'], array( 'parent', 'file', 'none' ), true ) ) ? $input['attachment_redirect'] : 'none',
		'robots_max_image_preview_large' => ! empty( $input['robots_max_image_preview_large'] ) ? 1 : 0,
		'robots_max_snippet'             => isset( $input['robots_max_snippet'] ) ? erankly_sanitize_robots_preview_value( $input['robots_max_snippet'] ) : '',
		'robots_max_video_preview'       => isset( $input['robots_max_video_preview'] ) ? erankly_sanitize_robots_preview_value( $input['robots_max_video_preview'] ) : '',
		'robots_nosnippet'               => ! empty( $input['robots_nosnippet'] ) ? 1 : 0,
		'robots_indexifembedded'         => ! empty( $input['robots_indexifembedded'] ) ? 1 : 0,
		'enable_multilingual'            => ( is_multisite() && ! empty( $input['enable_multilingual'] ) ) ? 1 : 0,
		'enable_redirects'               => ! empty( $input['enable_redirects'] ) ? 1 : 0,
		'redirect_exclude_admins'        => $redirect_exclude_admins ? 1 : 0,
		// The form submits the inverted add_head_credit checkbox; imported settings
		// still carry the stored hide_head_credit key, which wins when present.
		'hide_head_credit'               => isset( $input['hide_head_credit'] ) ? ( ! empty( $input['hide_head_credit'] ) ? 1 : 0 ) : ( ! empty( $input['add_head_credit'] ) ? 0 : 1 ),
		'bloat_remove_emoji'             => ! empty( $input['bloat_remove_emoji'] ) ? 1 : 0,
		'bloat_remove_generator'         => ! empty( $input['bloat_remove_generator'] ) ? 1 : 0,
		'bloat_remove_feed_links'        => ! empty( $input['bloat_remove_feed_links'] ) ? 1 : 0,
		'bloat_remove_rsd_link'          => ! empty( $input['bloat_remove_rsd_link'] ) ? 1 : 0,
		'bloat_remove_wlwmanifest'       => ! empty( $input['bloat_remove_wlwmanifest'] ) ? 1 : 0,
		'bloat_remove_shortlink'         => ! empty( $input['bloat_remove_shortlink'] ) ? 1 : 0,
		'bloat_remove_rest_link'         => ! empty( $input['bloat_remove_rest_link'] ) ? 1 : 0,
		'bloat_remove_oembed'            => ! empty( $input['bloat_remove_oembed'] ) ? 1 : 0,
		'bloat_remove_jquery_migrate'    => ! empty( $input['bloat_remove_jquery_migrate'] ) ? 1 : 0,
		'bloat_disable_self_pingbacks'   => ! empty( $input['bloat_disable_self_pingbacks'] ) ? 1 : 0,
		'bloat_remove_dashicons'         => ! empty( $input['bloat_remove_dashicons'] ) ? 1 : 0,
		'bloat_disable_heartbeat'        => ! empty( $input['bloat_disable_heartbeat'] ) ? 1 : 0,
		'bloat_disable_xmlrpc'           => ! empty( $input['bloat_disable_xmlrpc'] ) ? 1 : 0,
	);

	return $settings;
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
 * 'normalize' callback — a callable( array $merged, array $changes ): array —
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
		'ai'       => array( 'keys' => array( 'ai_prompt_template' ) ),
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
				'ai_enabled',
				'enable_multilingual',
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
				'bloat_remove_jquery_migrate',
				'bloat_disable_self_pingbacks',
				'bloat_remove_dashicons',
				'bloat_disable_heartbeat',
				'bloat_disable_xmlrpc',
			),
		),
		'settings' => array(
			'keys'      => array(
				'simplified_mode',
				'enable_seo_checklist',
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
 * hide_head_credit but never add_head_credit — the sanitizer relies on that
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

	update_site_option( ERANKLY_OPTION, $sanitized );

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
 * action. Reserved core slugs and malformed entries are dropped.
 *
 * @param mixed $tabs Raw filter output.
 * @return array<string,array{label:string,capability:string}>
 */
function erankly_normalize_settings_tabs( mixed $tabs ): array {
	if ( ! is_array( $tabs ) ) {
		return array();
	}

	$reserved = array( 'general', 'features', 'social', 'schema', 'sitemap', 'multilingual', 'health', 'settings', 'advanced', 'bloat', 'import-export', 'redirects' );
	$clean    = array();

	foreach ( $tabs as $slug => $tab ) {
		$slug = sanitize_key( (string) $slug );

		if ( '' === $slug || in_array( $slug, $reserved, true ) || ! is_array( $tab ) ) {
			continue;
		}

		$label = isset( $tab['label'] ) ? (string) $tab['label'] : '';
		if ( '' === $label ) {
			continue;
		}

		$clean[ $slug ] = array(
			'label'      => $label,
			'capability' => ( isset( $tab['capability'] ) && '' !== $tab['capability'] ) ? (string) $tab['capability'] : 'manage_options',
		);
	}

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
 * Builds inner-tab routing entries for Multilingual translation notice tabs.
 *
 * @return array<int,array{subtab:string,disabled:bool}>
 */
function erankly_get_multilingual_notice_nav_subtabs(): array {
	if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
		return array();
	}

	$items = array();
	$sites = get_sites( array( 'number' => 200 ) );

	foreach ( $sites as $site ) {
		$bid = (int) $site->blog_id;

		$items[] = array(
			'subtab'   => sanitize_key( 'ml-notice-' . $bid ),
			'disabled' => false,
		);
	}

	return $items;
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

	$settings = erankly_get_settings();

	// Simplified mode's master toggle drives only the cleanups with no functional
	// side effects; the riskier ones stay individually controlled in advanced mode.
	$bloat_safe_keys   = array( 'bloat_remove_emoji', 'bloat_remove_generator', 'bloat_remove_rsd_link', 'bloat_remove_wlwmanifest', 'bloat_remove_shortlink', 'bloat_remove_rest_link', 'bloat_disable_self_pingbacks' );
	$safe_bloat_active = array_reduce( $bloat_safe_keys, static fn( bool $carry, string $k ) => $carry && ! empty( $settings[ $k ] ), true );

	$sitemap_url              = erankly_get_sitemap_url( '/wp-sitemap.xml' );
	$global_schema_blocks     = isset( $settings['global_schema_blocks'] ) && is_array( $settings['global_schema_blocks'] ) ? $settings['global_schema_blocks'] : array();
	$global_schema_name       = ERANKLY_OPTION . '[global_schema_blocks]';
	$schema_person_user_id    = isset( $settings['schema_person_user_id'] ) ? absint( $settings['schema_person_user_id'] ) : 0;
	$schema_person_user       = $schema_person_user_id > 0 ? get_userdata( $schema_person_user_id ) : false;
	$show_organization_fields = 'person' !== $settings['schema_identity'];
	$redirects_enabled        = erankly_redirects_enabled();
	$sitemap_enabled          = erankly_sitemap_enabled();
	$health_enabled           = erankly_health_enabled();
	$multilingual_enabled     = is_multisite() && function_exists( 'erankly_multilingual_enabled' ) && erankly_multilingual_enabled();
	$is_site_admin_on_network = is_multisite() && ! is_network_admin();
	// Health and Redirects are per-site features: show them on individual sites,
	// never in the Network Admin global options.
	$show_health_tab    = $health_enabled && ! is_network_admin();
	$show_redirects_tab = $redirects_enabled && ! is_network_admin();
	// The AI tab (prompt editor) lives in the main settings form, shown only in
	// advanced mode while AI features are enabled.
	$show_ai_tab = ! $is_site_admin_on_network && ! empty( $settings['ai_enabled'] ) && empty( $settings['simplified_mode'] ) && function_exists( 'erankly_ai_render_settings_panel' );
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

	$post_type_objects  = erankly_get_public_post_types();
	$taxonomy_objects   = erankly_get_public_taxonomies();
	$special_page_items = erankly_special_page_keys();

	$general_subtabs = array();
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
	$multilingual_subtabs = ( is_network_admin() && $multilingual_enabled ) ? erankly_get_multilingual_notice_nav_subtabs() : array();
	$subtab_panel_map     = array();

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
	foreach ( $multilingual_subtabs as $item ) {
		$subtab_panel_map[ $item['subtab'] ] = 'settings-multilingual';
	}

	/**
	 * Filters the third-party tabs added to the EasyRankly settings screen.
	 *
	 * Each entry is keyed by a tab slug and provides a label and an optional capability.
	 * The tab body is printed by the `erankly_render_settings_tab_{$slug}` action.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,array<string,string>> $tabs Registered extension tabs.
	 */
	$extra_tabs = erankly_normalize_settings_tabs( apply_filters( 'erankly_settings_tabs', array() ) );

	// Map short tab names to panel IDs so server-side routing works for every tab —
	// used by the post-save redirect and the no-JS fallback.
	$tab_panel_map = array(
		'general'       => 'settings-general',
		'features'      => 'settings-features',
		'social'        => 'settings-social',
		'schema'        => 'settings-schema',
		'sitemap'       => 'settings-sitemap',
		'multilingual'  => 'settings-multilingual',
		'health'        => 'settings-health',
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

	if ( 'settings-ai' === $active_panel && ! $show_ai_tab ) {
		$active_panel  = 'settings-features';
		$active_subtab = '';
	}

	// The Multilingual panel only exists in Network Admin while the feature is on.
	if ( 'settings-multilingual' === $active_panel && ! ( is_network_admin() && $multilingual_enabled ) ) {
		$active_panel  = 'settings-features';
		$active_subtab = '';
	}

	// On per-site network admin, default to the first panel that is actually
	// available for the active theme and enabled features.
	if ( $is_site_admin_on_network && ! in_array( $active_panel, $site_panels, true ) ) {
		$active_panel  = $site_panels[0] ?? '';
		$active_subtab = '';
	}

	// With every built-in panel now autosaving, $show_settings_submit ends up
	// false for every reachable $active_panel today — but the computation
	// itself isn't dead: it's what keeps the button correctly hidden on the
	// very first server-rendered paint (the JS in bindSettingsTabs()'s
	// activate() only corrects it after DOMContentLoaded, which would
	// otherwise flash the button briefly), and it'll matter again the moment
	// a future panel — built-in or a third-party extension tab — doesn't
	// autosave.
	$show_settings_submit = ! $is_site_admin_on_network && ! in_array( $active_panel, array( 'settings-health', 'settings-import-export', 'settings-redirects', 'settings-multilingual' ), true );

	// Panels that autosave via REST (see erankly_settings_autosave_panels())
	// no longer need the shared button once they're actually reachable —
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
		<h1><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h1>
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
				<button type="button" class="erankly-settings-sidebar-toggle" aria-expanded="false" data-erankly-sidebar-toggle>
					<span data-erankly-sidebar-toggle-label></span>
				</button>
				<div class="erankly-settings-nav-tablist" role="tablist" aria-orientation="vertical" aria-label="<?php esc_attr_e( 'Plugin settings', 'easyrankly' ); ?>" data-erankly-settings-tablist data-erankly-active-panel="<?php echo esc_attr( $active_panel ); ?>" data-erankly-active-subtab="<?php echo esc_attr( $active_subtab ); ?>">
				<?php if ( ! $is_site_admin_on_network ) : ?>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-general' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-general" role="tab" aria-selected="<?php echo 'settings-general' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-general" data-erankly-tab="settings-general"><?php esc_html_e( 'General', 'easyrankly' ); ?></button>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-features' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-features" role="tab" aria-selected="<?php echo 'settings-features' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-features" data-erankly-tab="settings-features"><?php esc_html_e( 'Features', 'easyrankly' ); ?></button>
				<button type="button" class="erankly-settings-nav-item" id="erankly-settings-tab-social" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-social" data-erankly-tab="settings-social"><?php esc_html_e( 'Social', 'easyrankly' ); ?></button>
				<button type="button" class="erankly-settings-nav-item" id="erankly-settings-tab-schema" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-schema" data-erankly-tab="settings-schema"><?php esc_html_e( 'Schema', 'easyrankly' ); ?></button>
					<?php if ( $sitemap_enabled ) : ?>
				<button type="button" class="erankly-settings-nav-item" id="erankly-settings-tab-sitemap" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-sitemap" data-erankly-tab="settings-sitemap"><?php esc_html_e( 'Sitemap', 'easyrankly' ); ?></button>
				<?php endif; ?>
					<?php if ( is_multisite() && is_network_admin() && $multilingual_enabled ) : ?>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-multilingual' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-multilingual" role="tab" aria-selected="<?php echo 'settings-multilingual' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-multilingual" data-erankly-tab="settings-multilingual"><?php esc_html_e( 'Multilingual', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php endif; ?>
				<?php if ( $show_site_special_tab ) : ?>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-special-pages' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-special-pages" role="tab" aria-selected="<?php echo 'settings-special-pages' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-special-pages" data-erankly-tab="settings-special-pages"><?php esc_html_e( 'General', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php if ( $show_health_tab ) : ?>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-health' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-health" role="tab" aria-selected="<?php echo 'settings-health' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-health" data-erankly-tab="settings-health"><?php esc_html_e( 'Health', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php if ( ! $is_site_admin_on_network ) : ?>
				<button type="button" class="erankly-settings-nav-item" id="erankly-settings-tab-settings" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-settings" data-erankly-tab="settings-settings"><?php esc_html_e( 'Settings', 'easyrankly' ); ?></button>
				<button type="button" class="erankly-settings-nav-item" id="erankly-settings-tab-advanced" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-advanced" data-erankly-tab="settings-advanced" data-erankly-advanced-tab <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>><?php esc_html_e( 'Advanced', 'easyrankly' ); ?></button>
					<?php if ( $show_ai_tab ) : ?>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-ai' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-ai" role="tab" aria-selected="<?php echo 'settings-ai' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-ai" data-erankly-tab="settings-ai"><?php esc_html_e( 'AI', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<button type="button" class="erankly-settings-nav-item" id="erankly-settings-tab-bloat" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-bloat" data-erankly-tab="settings-bloat"><?php esc_html_e( 'Bloat', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php if ( $show_import_export_tab ) : ?>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-import-export' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-import-export" role="tab" aria-selected="<?php echo 'settings-import-export' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-import-export" data-erankly-tab="settings-import-export"><?php esc_html_e( 'Import / Export', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php if ( $show_redirects_tab ) : ?>
				<button type="button" class="erankly-settings-nav-item<?php echo 'settings-redirects' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-redirects" role="tab" aria-selected="<?php echo 'settings-redirects' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-redirects" data-erankly-tab="settings-redirects"><?php esc_html_e( 'Redirects', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php
				foreach ( $extra_tabs as $extra_slug => $extra_tab ) :
					if ( ! current_user_can( $extra_tab['capability'] ) ) {
						continue;
					}
					$extra_panel = 'settings-' . $extra_slug;
					?>
				<button type="button" class="erankly-settings-nav-item<?php echo $extra_panel === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-tab-<?php echo esc_attr( $extra_slug ); ?>" role="tab" aria-selected="<?php echo $extra_panel === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-<?php echo esc_attr( $extra_slug ); ?>" data-erankly-tab="<?php echo esc_attr( $extra_panel ); ?>"><?php echo esc_html( $extra_tab['label'] ); ?></button>
				<?php endforeach; ?>
				</div>
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

					<?php erankly_render_settings_panel_features( $settings, $redirects_enabled, $sitemap_enabled, $health_enabled, $multilingual_enabled, $active_panel ); ?>

					<?php erankly_render_settings_panel_general( $settings, $schema_person_user_id, $schema_person_user, $show_organization_fields, $active_panel ); ?>

					<?php erankly_render_settings_panel_social( $settings ); ?>

					<?php erankly_render_settings_panel_schema( $settings, $global_schema_blocks, $global_schema_name ); ?>

				<?php if ( $sitemap_enabled ) : ?>
					<?php erankly_render_settings_panel_sitemap( $settings, $sitemap_url ); ?>
				<?php endif; ?>

					<?php erankly_render_settings_panel_settings( $settings, $redirects_enabled ); ?>

					<?php erankly_render_settings_panel_advanced( $settings ); ?>

					<?php erankly_render_settings_panel_bloat( $settings, $safe_bloat_active ); ?>

					<?php if ( $show_ai_tab ) : ?>
						<?php erankly_ai_render_settings_panel( $active_panel ); ?>
					<?php endif; ?>

					<div class="erankly-settings-submit" data-erankly-settings-submit <?php echo $show_settings_submit ? '' : 'hidden'; ?>>
						<?php submit_button(); ?>
					</div>
				<?php if ( ! $is_site_admin_on_network ) : ?>
				</form>
				<?php endif; ?>

			<?php if ( is_multisite() && is_network_admin() && $multilingual_enabled && function_exists( 'erankly_ml_render_network_panel' ) ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-multilingual' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-multilingual" role="tabpanel" aria-labelledby="erankly-settings-tab-multilingual" data-erankly-settings-panel="settings-multilingual" data-erankly-standalone-panel <?php echo 'settings-multilingual' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_ml_render_network_panel(); ?>
			</div>
			<?php endif; ?>

			<?php if ( $show_site_special_tab ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-special-pages' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-special-pages" role="tabpanel" aria-labelledby="erankly-settings-tab-special-pages" data-erankly-settings-panel="settings-special-pages" data-erankly-standalone-panel <?php echo 'settings-special-pages' === $active_panel ? '' : 'hidden'; ?>>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'erankly_site_special_meta' ); ?>
					<input type="hidden" name="action" value="erankly_save_site_special_meta">
					<div class="erankly-settings-fields">
						<div class="erankly-settings-section">
							<h3 class="erankly-section-title"><?php esc_html_e( 'Special pages and archives', 'easyrankly' ); ?></h3>
							<div class="erankly-card">
								<?php erankly_render_special_page_defaults( erankly_special_page_keys(), array( 'global_special_meta' => erankly_get_site_special_meta() ) ); ?>
							</div>
						</div>
					</div>
				</form>
			</div>
			<?php endif; ?>

			<?php if ( $show_import_export_tab ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-import-export' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-import-export" role="tabpanel" aria-labelledby="erankly-settings-tab-import-export" data-erankly-settings-panel="settings-import-export" <?php echo 'settings-import-export' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_import_export_render_panel(); ?>
			</div>
			<?php endif; ?>

			<?php if ( $show_health_tab && function_exists( 'erankly_health_render_panel' ) ) : ?>
			<div class="erankly-tab-panel<?php echo 'settings-health' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-health" role="tabpanel" aria-labelledby="erankly-settings-tab-health" data-erankly-settings-panel="settings-health" <?php echo 'settings-health' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_health_render_panel(); ?>
			</div>
			<?php endif; ?>

			<?php if ( $show_redirects_tab ) : ?>
			<div class="erankly-tab-panel erankly-redirect-management<?php echo 'settings-redirects' === $active_panel ? ' is-active' : ''; ?>" role="tabpanel" aria-labelledby="erankly-settings-tab-redirects" data-erankly-settings-panel="settings-redirects" <?php echo 'settings-redirects' === $active_panel ? '' : 'hidden'; ?>>
				<?php erankly_redirects_render_panel(); ?>
			</div>
			<?php endif; ?>

			<?php
			foreach ( $extra_tabs as $extra_slug => $extra_tab ) :
				if ( ! current_user_can( $extra_tab['capability'] ) ) {
					continue;
				}
				$extra_panel = 'settings-' . $extra_slug;
				?>
			<div class="erankly-tab-panel<?php echo $extra_panel === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-<?php echo esc_attr( $extra_slug ); ?>" role="tabpanel" aria-labelledby="erankly-settings-tab-<?php echo esc_attr( $extra_slug ); ?>" data-erankly-settings-panel="<?php echo esc_attr( $extra_panel ); ?>" data-erankly-standalone-panel <?php echo $extra_panel === $active_panel ? '' : 'hidden'; ?>>
				<?php
				/**
				 * Renders the body of a third-party settings tab.
				 *
				 * The dynamic portion of the hook name is the tab slug registered through the
				 * `erankly_settings_tabs` filter.
				 *
				 * @since 1.7.2
				 *
				 * @param array<string,mixed> $settings Current plugin settings.
				 */
				do_action( 'erankly_render_settings_tab_' . $extra_slug, $settings );
				?>
			</div>
			<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Renders advanced Organization identity fields.
 *
 * @param array<string,mixed> $settings Plugin settings.
 * @return void
 */
function erankly_render_organization_details( array $settings ): void {
	?>
	<details class="erankly-settings-details">
		<summary><?php esc_html_e( 'Legal information and address', 'easyrankly' ); ?></summary>
		<div class="erankly-settings-details-content">
			<div class="erankly-field">
				<label for="erankly-organization-legal-name"><strong><?php esc_html_e( 'Legal name', 'easyrankly' ); ?></strong></label>
				<input id="erankly-organization-legal-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_legal_name]" value="<?php echo esc_attr( (string) $settings['organization_legal_name'] ); ?>">
				<p class="description"><?php esc_html_e( 'Use this only when the registered name differs from the public organization name.', 'easyrankly' ); ?></p>
			</div>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-organization-vat-id"><strong><?php esc_html_e( 'VAT ID', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-vat-id" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_vat_id]" value="<?php echo esc_attr( (string) $settings['organization_vat_id'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-tax-id"><strong><?php esc_html_e( 'Tax ID', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-tax-id" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_tax_id]" value="<?php echo esc_attr( (string) $settings['organization_tax_id'] ); ?>">
				</div>
			</div>
			<div class="erankly-field">
				<label for="erankly-organization-street-address"><strong><?php esc_html_e( 'Street address', 'easyrankly' ); ?></strong></label>
				<input id="erankly-organization-street-address" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_street_address]" value="<?php echo esc_attr( (string) $settings['organization_street_address'] ); ?>">
			</div>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-organization-locality"><strong><?php esc_html_e( 'City / locality', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-locality" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_locality]" value="<?php echo esc_attr( (string) $settings['organization_locality'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-region"><strong><?php esc_html_e( 'Region / state', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-region" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_region]" value="<?php echo esc_attr( (string) $settings['organization_region'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-postal-code"><strong><?php esc_html_e( 'Postal code', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-postal-code" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_postal_code]" value="<?php echo esc_attr( (string) $settings['organization_postal_code'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-country"><strong><?php esc_html_e( 'Country code', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-country" class="widefat" type="text" maxlength="2" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_country]" value="<?php echo esc_attr( (string) $settings['organization_country'] ); ?>" placeholder="IT">
					<p class="description"><?php esc_html_e( 'Two-letter ISO 3166-1 code.', 'easyrankly' ); ?></p>
				</div>
			</div>
		</div>
	</details>
	<?php
}

/**
 * Renders LocalBusiness settings.
 *
 * @param array<string,mixed> $settings Plugin settings.
 * @return void
 */
function erankly_render_local_business_settings( array $settings ): void {
	$types        = erankly_get_local_business_types();
	$pages        = get_pages(
		array(
			'post_status' => 'publish',
			'sort_column' => 'menu_order,post_title',
		)
	);
	$hours        = isset( $settings['local_business_hours'] ) && is_array( $settings['local_business_hours'] ) ? $settings['local_business_hours'] : erankly_default_opening_hours();
	$enabled      = ! empty( $settings['enable_local_business'] );
	$type         = isset( $settings['local_business_type'] ) ? (string) $settings['local_business_type'] : 'LocalBusiness';
	$page_path    = isset( $settings['local_business_page_path'] ) ? (string) $settings['local_business_page_path'] : '';
	$page_options = array();

	foreach ( $pages as $page ) {
		$path = erankly_sanitize_relative_path( '/' . get_page_uri( $page ) . '/' );

		if ( '' !== $path ) {
			$page_options[ $path ] = get_the_title( $page ) . ' (' . $path . ')';
		}
	}
	?>
	<fieldset class="erankly-field erankly-checkboxes erankly-local-business" data-erankly-local-business>
		<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_local_business]" value="1" <?php checked( $enabled ); ?> data-erankly-local-business-toggle> <strong><?php esc_html_e( 'Add LocalBusiness schema for one physical location', 'easyrankly' ); ?></strong></label>
		<p class="description"><?php esc_html_e( 'Use only when the selected page visibly contains the same business details. Keep them consistent with your Google Business Profile.', 'easyrankly' ); ?></p>
		<div class="erankly-local-business-fields" data-erankly-local-business-fields <?php echo $enabled ? '' : 'hidden'; ?>>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-local-business-type"><strong><?php esc_html_e( 'Business type', 'easyrankly' ); ?></strong></label>
					<select id="erankly-local-business-type" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_type]" data-erankly-local-business-type>
						<?php foreach ( $types as $type_key => $type_label ) : ?>
							<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="erankly-field">
					<label for="erankly-local-business-page"><strong><?php esc_html_e( 'Location page', 'easyrankly' ); ?></strong></label>
					<select id="erankly-local-business-page" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_page_path]">
						<option value=""><?php esc_html_e( 'Select a published page', 'easyrankly' ); ?></option>
						<?php if ( '' !== $page_path && ! isset( $page_options[ $page_path ] ) ) : ?>
							<option value="<?php echo esc_attr( $page_path ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: saved relative page path. */ __( 'Saved path unavailable on this site (%s)', 'easyrankly' ), $page_path ) ); ?></option>
						<?php endif; ?>
						<?php foreach ( $page_options as $path => $label ) : ?>
							<option value="<?php echo esc_attr( $path ); ?>" <?php selected( $page_path, $path ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The relative path is shared across Multisite sites.', 'easyrankly' ); ?></p>
				</div>
			</div>
			<details class="erankly-settings-details">
				<summary><?php esc_html_e( 'Location details and opening hours', 'easyrankly' ); ?></summary>
				<div class="erankly-settings-details-content">
					<div class="erankly-inline-fields erankly-inline-fields-two-columns">
						<div class="erankly-field">
							<label for="erankly-local-business-price-range"><strong><?php esc_html_e( 'Price range', 'easyrankly' ); ?></strong></label>
							<input id="erankly-local-business-price-range" class="widefat" type="text" maxlength="99" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_price_range]" value="<?php echo esc_attr( (string) $settings['local_business_price_range'] ); ?>" placeholder="€€">
						</div>
						<div class="erankly-field">
							<label for="erankly-local-business-latitude"><strong><?php esc_html_e( 'Latitude', 'easyrankly' ); ?></strong></label>
							<input id="erankly-local-business-latitude" class="widefat" type="number" step="any" min="-90" max="90" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_latitude]" value="<?php echo esc_attr( (string) $settings['local_business_latitude'] ); ?>">
						</div>
						<div class="erankly-field">
							<label for="erankly-local-business-longitude"><strong><?php esc_html_e( 'Longitude', 'easyrankly' ); ?></strong></label>
							<input id="erankly-local-business-longitude" class="widefat" type="number" step="any" min="-180" max="180" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_longitude]" value="<?php echo esc_attr( (string) $settings['local_business_longitude'] ); ?>">
						</div>
					</div>
					<div data-erankly-food-business-fields <?php echo erankly_is_food_business_type( $type ) ? '' : 'hidden'; ?>>
						<div class="erankly-inline-fields erankly-inline-fields-two-columns">
							<div class="erankly-field">
								<label for="erankly-local-business-menu"><strong><?php esc_html_e( 'Menu URL', 'easyrankly' ); ?></strong></label>
								<input id="erankly-local-business-menu" class="widefat" type="url" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_menu_url]" value="<?php echo esc_attr( (string) $settings['local_business_menu_url'] ); ?>">
							</div>
							<div class="erankly-field">
								<label for="erankly-local-business-cuisine"><strong><?php esc_html_e( 'Cuisine served', 'easyrankly' ); ?></strong></label>
								<input id="erankly-local-business-cuisine" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_cuisine]" value="<?php echo esc_attr( (string) $settings['local_business_cuisine'] ); ?>" placeholder="<?php esc_attr_e( 'Italian, Mediterranean', 'easyrankly' ); ?>">
							</div>
						</div>
					</div>
					<h4><?php esc_html_e( 'Opening hours', 'easyrankly' ); ?></h4>
					<p class="description"><?php esc_html_e( 'Leave both intervals empty when no hours should be published. Overnight intervals are supported.', 'easyrankly' ); ?></p>
					<?php erankly_render_opening_hours_fields( $hours ); ?>
				</div>
			</details>
		</div>
	</fieldset>
	<?php
}

/**
 * Renders weekly opening-hours controls.
 *
 * @param array<string,mixed> $hours Opening hours.
 * @return void
 */
function erankly_render_opening_hours_fields( array $hours ): void {
	$days = array(
		'monday'    => __( 'Monday', 'easyrankly' ),
		'tuesday'   => __( 'Tuesday', 'easyrankly' ),
		'wednesday' => __( 'Wednesday', 'easyrankly' ),
		'thursday'  => __( 'Thursday', 'easyrankly' ),
		'friday'    => __( 'Friday', 'easyrankly' ),
		'saturday'  => __( 'Saturday', 'easyrankly' ),
		'sunday'    => __( 'Sunday', 'easyrankly' ),
	);
	?>
	<div class="erankly-opening-hours">
		<?php foreach ( $days as $day => $label ) : ?>
			<?php
			$day_hours = isset( $hours[ $day ] ) && is_array( $hours[ $day ] ) ? $hours[ $day ] : array();
			$closed    = ! empty( $day_hours['closed'] );
			$intervals = isset( $day_hours['intervals'] ) && is_array( $day_hours['intervals'] ) ? $day_hours['intervals'] : array();
			?>
			<div class="erankly-opening-hours-row" data-erankly-opening-day>
				<strong><?php echo esc_html( $label ); ?></strong>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][closed]" value="1" <?php checked( $closed ); ?> data-erankly-day-closed> <?php esc_html_e( 'Closed', 'easyrankly' ); ?></label>
				<div class="erankly-opening-intervals" data-erankly-opening-intervals <?php echo $closed ? 'hidden' : ''; ?>>
					<?php foreach ( array( 0, 1 ) as $index ) : ?>
						<?php
						$interval = isset( $intervals[ $index ] ) && is_array( $intervals[ $index ] ) ? $intervals[ $index ] : array();
						$opens    = isset( $interval['opens'] ) ? (string) $interval['opens'] : '';
						$closes   = isset( $interval['closes'] ) ? (string) $interval['closes'] : '';
						?>
						<span>
							<label>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: 1: day, 2: interval number. */ __( '%1$s interval %2$d opens', 'easyrankly' ), $label, $index + 1 ) ); ?></span>
								<input type="time" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][intervals][<?php echo esc_attr( (string) $index ); ?>][opens]" value="<?php echo esc_attr( $opens ); ?>">
							</label>
							<span aria-hidden="true">-</span>
							<label>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: 1: day, 2: interval number. */ __( '%1$s interval %2$d closes', 'easyrankly' ), $label, $index + 1 ) ); ?></span>
								<input type="time" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][intervals][<?php echo esc_attr( (string) $index ); ?>][closes]" value="<?php echo esc_attr( $closes ); ?>">
							</label>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Renders global SEO defaults for post types or taxonomies.
 *
 * @param string                                 $setting_key Settings array key.
 * @param array<string,WP_Post_Type|WP_Taxonomy> $objects     Public objects.
 * @param array<string,mixed>                    $settings    Current settings.
 * @return void
 */
function erankly_render_global_meta_defaults( string $setting_key, array $objects, array $settings ): void {
	$values             = isset( $settings[ $setting_key ] ) && is_array( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : array();
	$linked_setting_key = $setting_key . '_linked';
	$is_linked          = ! array_key_exists( $linked_setting_key, $settings ) || ! empty( $settings[ $linked_setting_key ] );
	$is_taxonomy        = 'global_taxonomy_meta' === $setting_key;

	if ( empty( $objects ) ) {
		echo '<p class="description">' . esc_html__( 'No public items available.', 'easyrankly' ) . '</p>';
		return;
	}

	$tabs_id           = 'erankly-' . sanitize_key( $setting_key ) . '-tabs';
	$toggle_id         = 'erankly-' . sanitize_key( $setting_key ) . '-linked';
	$toggle_base_label = $is_taxonomy ? __( 'Link taxonomy templates', 'easyrankly' ) : __( 'Link post type templates', 'easyrankly' );
	$toggle_on_label   = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: Yes', 'easyrankly' ),
		$toggle_base_label
	);
	$toggle_off_label = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: No', 'easyrankly' ),
		$toggle_base_label
	);
	$linked_panel_label = __( 'Unified', 'easyrankly' );
	?>
	<div class="erankly-default-tabs erankly-entity-default-tabs <?php echo $is_linked ? 'is-linked' : ''; ?>" data-erankly-tabs-root data-erankly-linked-defaults>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Default metadata by content type', 'easyrankly' ); ?>">
				<span class="erankly-tab erankly-linked-tabs-summary" aria-hidden="true"><?php echo esc_html( $linked_panel_label ); ?></span>
				<?php
				$is_first = true;
				foreach ( $objects as $key => $object ) :
					$label         = $object instanceof WP_Taxonomy ? erankly_get_taxonomy_admin_label( $object ) : $object->labels->singular_name;
					$tab_key       = sanitize_key( $setting_key . '-' . $key );
					$panel_id      = 'erankly-' . $tab_key . '-panel';
					$tab_id        = 'erankly-' . $tab_key . '-tab';
					$is_tab_active = $is_first && ! $is_linked;
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_tab_active ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_tab_active ? 'true' : 'false'; ?>" aria-disabled="<?php echo $is_linked ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>" <?php disabled( $is_linked ); ?> <?php echo $is_linked ? 'tabindex="-1"' : ''; ?>><?php echo esc_html( $label ); ?></button>
					<?php
					$is_first = false;
				endforeach;
				?>
			</div>
			<input id="<?php echo esc_attr( $toggle_id ); ?>-input" type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $linked_setting_key ); ?>]" value="<?php echo esc_attr( $is_linked ? '1' : '0' ); ?>" data-erankly-linked-input>
			<span class="erankly-linked-defaults-label"><?php echo esc_html( $toggle_base_label ); ?></span>
			<button type="button" class="erankly-tabs erankly-linked-defaults-toggle" aria-label="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" aria-pressed="<?php echo esc_attr( $is_linked ? 'true' : 'false' ); ?>" title="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" data-erankly-linked-toggle data-erankly-linked-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-off-label="<?php echo esc_attr( $toggle_off_label ); ?>" data-erankly-linked-action-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-action-off-label="<?php echo esc_attr( $toggle_off_label ); ?>">
				<span class="erankly-tab erankly-linked-defaults-option is-no" aria-hidden="true"><?php esc_html_e( 'No', 'easyrankly' ); ?></span>
				<span class="erankly-tab erankly-linked-defaults-option is-yes" aria-hidden="true"><?php esc_html_e( 'Yes', 'easyrankly' ); ?></span>
			</button>
			<span class="screen-reader-text" aria-live="polite" data-erankly-linked-status><?php echo esc_html( $is_linked ? $toggle_on_label : $toggle_off_label ); ?></span>
		</div>

		<?php
		$is_first = true;
		foreach ( $objects as $key => $object ) :
			$row             = isset( $values[ $key ] ) && is_array( $values[ $key ] ) ? $values[ $key ] : array();
			$title           = isset( $row['title'] ) ? (string) $row['title'] : '';
			$description     = isset( $row['description'] ) ? (string) $row['description'] : '';
			$noindex         = ! empty( $row['noindex'] );
			$nofollow        = ! empty( $row['nofollow'] );
			$noarchive       = ! empty( $row['noarchive'] );
			$disable_sitemap = ! empty( $row['disable_sitemap'] );
			$label           = $object instanceof WP_Taxonomy ? erankly_get_taxonomy_admin_label( $object ) : $object->labels->singular_name;
			$id_prefix       = 'erankly-' . sanitize_key( $setting_key ) . '-' . sanitize_key( $key );
			$panel_key       = sanitize_key( $setting_key . '-' . $key );
			$panel_id        = 'erankly-' . $panel_key . '-panel';
			$tab_id          = 'erankly-' . $panel_key . '-tab';
			// A sample post/term stands in for {{post_title}}/{{term_name}} etc. in
			// the preview since these fields are global templates, not tied to any
			// single post/term; the raw token stays literal when none exist yet.
			$sample_post     = $is_taxonomy ? null : erankly_get_sample_post_for_type( (string) $key );
			$sample_term     = $is_taxonomy ? erankly_get_sample_term_for_taxonomy( (string) $key ) : null;
			$examples        = erankly_get_admin_variable_examples( $sample_post, $sample_term );
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-title"><strong><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $id_prefix ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $title ); ?>">
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-description"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $id_prefix ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][description]"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<?php erankly_render_global_visibility_defaults( $setting_key, (string) $key, $noindex, $nofollow, $noarchive, $disable_sitemap ); ?>
				</div>
			</div>
			<?php
			$is_first = false;
		endforeach;
		?>
	</div>
	<?php
}

/**
 * Renders default Open Graph / X (Twitter) templates with a linked toggle.
 *
 * Mirrors the post type defaults UI: when linked (the default), one template
 * drives both networks; when separate, each network keeps its own values.
 *
 * @param array<string,mixed> $settings Current settings.
 * @return void
 */
function erankly_render_social_meta_defaults( array $settings ): void {
	$networks = array(
		'og'      => array(
			'label'           => __( 'Open Graph', 'easyrankly' ),
			'title_key'       => 'default_og_title',
			'description_key' => 'default_og_description',
			'id_prefix'       => 'erankly-default-og',
		),
		'twitter' => array(
			'label'           => __( 'X (Twitter)', 'easyrankly' ),
			'title_key'       => 'default_twitter_title',
			'description_key' => 'default_twitter_description',
			'id_prefix'       => 'erankly-default-twitter',
		),
	);

	$og_title            = isset( $settings['default_og_title'] ) ? (string) $settings['default_og_title'] : '';
	$og_description      = isset( $settings['default_og_description'] ) ? (string) $settings['default_og_description'] : '';
	$twitter_title       = isset( $settings['default_twitter_title'] ) ? (string) $settings['default_twitter_title'] : '';
	$twitter_description = isset( $settings['default_twitter_description'] ) ? (string) $settings['default_twitter_description'] : '';

	// Sites saved before the toggle existed inherit the linked default only when
	// their Open Graph and X (Twitter) templates already match, so customized
	// per-network values are never silently overwritten.
	$is_linked = ( ! array_key_exists( 'social_defaults_linked', $settings ) || ! empty( $settings['social_defaults_linked'] ) )
		&& $og_title === $twitter_title
		&& $og_description === $twitter_description;

	$toggle_base_label = __( 'Link social templates', 'easyrankly' );
	$toggle_on_label   = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: Yes', 'easyrankly' ),
		$toggle_base_label
	);
	$toggle_off_label = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: No', 'easyrankly' ),
		$toggle_base_label
	);
	$linked_panel_label = __( 'Unified', 'easyrankly' );
	?>
	<div class="erankly-default-tabs <?php echo $is_linked ? 'is-linked' : ''; ?>" data-erankly-tabs-root data-erankly-linked-defaults>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="erankly-social-defaults-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Default social metadata by network', 'easyrankly' ); ?>">
				<span class="erankly-tab erankly-linked-tabs-summary" aria-hidden="true"><?php echo esc_html( $linked_panel_label ); ?></span>
				<?php
				$is_first = true;
				foreach ( $networks as $key => $network ) :
					$tab_key       = sanitize_key( 'social-defaults-' . $key );
					$panel_id      = 'erankly-' . $tab_key . '-panel';
					$tab_id        = 'erankly-' . $tab_key . '-tab';
					$is_tab_active = $is_first && ! $is_linked;
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_tab_active ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_tab_active ? 'true' : 'false'; ?>" aria-disabled="<?php echo $is_linked ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>" <?php disabled( $is_linked ); ?> <?php echo $is_linked ? 'tabindex="-1"' : ''; ?>><?php echo esc_html( $network['label'] ); ?></button>
					<?php
					$is_first = false;
				endforeach;
				?>
			</div>
			<input id="erankly-social-defaults-linked-input" type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[social_defaults_linked]" value="<?php echo esc_attr( $is_linked ? '1' : '0' ); ?>" data-erankly-linked-input>
			<span class="erankly-linked-defaults-label"><?php echo esc_html( $toggle_base_label ); ?></span>
			<button type="button" class="erankly-tabs erankly-linked-defaults-toggle" aria-label="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" aria-pressed="<?php echo esc_attr( $is_linked ? 'true' : 'false' ); ?>" title="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" data-erankly-linked-toggle data-erankly-linked-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-off-label="<?php echo esc_attr( $toggle_off_label ); ?>" data-erankly-linked-action-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-action-off-label="<?php echo esc_attr( $toggle_off_label ); ?>">
				<span class="erankly-tab erankly-linked-defaults-option is-no" aria-hidden="true"><?php esc_html_e( 'No', 'easyrankly' ); ?></span>
				<span class="erankly-tab erankly-linked-defaults-option is-yes" aria-hidden="true"><?php esc_html_e( 'Yes', 'easyrankly' ); ?></span>
			</button>
			<span class="screen-reader-text" aria-live="polite" data-erankly-linked-status><?php echo esc_html( $is_linked ? $toggle_on_label : $toggle_off_label ); ?></span>
		</div>

		<?php
		// These templates apply to every post regardless of type, so the most
		// recently published post (of the default "post" type) stands in as
		// the {{post_title}}-style example; the raw token stays literal if none exist yet.
		$examples = erankly_get_admin_variable_examples( erankly_get_sample_post_for_type( 'post' ) );
		$is_first = true;
		foreach ( $networks as $key => $network ) :
			$title       = isset( $settings[ $network['title_key'] ] ) ? (string) $settings[ $network['title_key'] ] : '';
			$description = isset( $settings[ $network['description_key'] ] ) ? (string) $settings[ $network['description_key'] ] : '';
			$panel_key   = sanitize_key( 'social-defaults-' . $key );
			$panel_id    = 'erankly-' . $panel_key . '-panel';
			$tab_id      = 'erankly-' . $panel_key . '-tab';
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-title"><strong><?php esc_html_e( 'Default title', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $network['id_prefix'] ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $network['title_key'] ); ?>]" value="<?php echo esc_attr( $title ); ?>" data-erankly-linked-field="title">
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-description"><strong><?php esc_html_e( 'Default description', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $network['id_prefix'] ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $network['description_key'] ); ?>]" data-erankly-linked-field="description"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
				</div>
			</div>
			<?php
			$is_first = false;
		endforeach;
		?>
	</div>
	<?php
}

/**
 * Renders global SEO defaults for special pages and archives.
 *
 * Special pages are singleton entities sharing the same metadata structure as
 * post types and taxonomies, but without the "linked" toggle. This settings
 * renderer is the fallback for classic themes and for block themes on WordPress
 * versions where the contextual Site Editor panels are unavailable.
 *
 * @param array<string,string> $entities Map of entity key => admin label.
 * @param array<string,mixed>  $settings Current settings.
 * @return void
 */
function erankly_render_special_page_defaults( array $entities, array $settings ): void {
	if ( empty( $entities ) || erankly_use_site_editor_special_page_panels() ) {
		return;
	}

	$setting_key = 'global_special_meta';
	$values      = isset( $settings[ $setting_key ] ) && is_array( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : array();
	?>
	<p class="description"><?php esc_html_e( 'Set the default SEO metadata for WordPress contexts that are not individual posts or terms: homepage, blog, author and date archives, search results and the 404 page.', 'easyrankly' ); ?></p>
	<?php

	erankly_render_special_page_defaults_group( $entities, $values, $setting_key, 'all', __( 'Default metadata by WordPress context', 'easyrankly' ) );
}

/**
 * Renders one tab group for special page defaults.
 *
 * @param array<string,string> $entities    Map of entity key => admin label.
 * @param array<string,mixed>  $values      Current settings for the group.
 * @param string               $setting_key Settings array key.
 * @param string               $group_key   Unique group key.
 * @param string               $aria_label  Tablist label.
 * @return void
 */
function erankly_render_special_page_defaults_group( array $entities, array $values, string $setting_key, string $group_key, string $aria_label ): void {
	$tabs_id   = 'erankly-' . sanitize_key( $setting_key . '-' . $group_key ) . '-tabs';
	$is_simple = (bool) erankly_get_setting( 'simplified_mode', 1 );
	?>
	<div class="erankly-default-tabs erankly-entity-default-tabs" data-erankly-tabs-root>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" role="tablist" aria-label="<?php echo esc_attr( $aria_label ); ?>">
				<?php
				$is_first = true;
				foreach ( $entities as $key => $label ) :
					$tab_key  = sanitize_key( $setting_key . '-' . $group_key . '-' . $key );
					$panel_id = 'erankly-' . $tab_key . '-panel';
					$tab_id   = 'erankly-' . $tab_key . '-tab';
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_first ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_first ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php
					$is_first = false;
				endforeach;
				?>
			</div>
		</div>

		<?php
		$is_first = true;
		foreach ( $entities as $key => $label ) :
			$row             = isset( $values[ $key ] ) && is_array( $values[ $key ] ) ? $values[ $key ] : array();
			$title           = isset( $row['title'] ) ? (string) $row['title'] : '';
			$description     = isset( $row['description'] ) ? (string) $row['description'] : '';
			$noindex         = ! empty( $row['noindex'] );
			$nofollow        = ! empty( $row['nofollow'] );
			$noarchive       = ! empty( $row['noarchive'] );
			$disable_sitemap = ! empty( $row['disable_sitemap'] );
			$id_prefix       = 'erankly-' . sanitize_key( $setting_key ) . '-' . sanitize_key( (string) $key );
			$panel_key       = sanitize_key( $setting_key . '-' . $group_key . '-' . $key );
			$panel_id        = 'erankly-' . $panel_key . '-panel';
			$tab_id          = 'erankly-' . $panel_key . '-tab';
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<h4>
						<span class="erankly-default-entity-label"><?php echo esc_html( $label ); ?></span>
					</h4>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-title"><strong><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $id_prefix ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $title ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-description"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $id_prefix ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][description]"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<?php
					// Among special pages only the author archive ever appears in the
					// XML sitemap, so the "Disable sitemap" toggle is shown only there.
					erankly_render_global_visibility_defaults( $setting_key, (string) $key, $noindex, $nofollow, $noarchive, $disable_sitemap, 'author' === (string) $key );

					// Social sharing is an advanced-only panel; in simplified mode the
					// values are carried through as hidden inputs so saving never wipes them.
					erankly_render_special_page_social_defaults( $setting_key, (string) $key, $row, $id_prefix, $is_simple );
					?>
				</div>
			</div>
			<?php
			$is_first = false;
		endforeach;
		?>
	</div>
	<?php
}

/**
 * Renders the advanced-only social sharing defaults for one special page.
 *
 * In simplified mode the panel is hidden, but the stored values are carried
 * through as hidden inputs so saving in simplified mode never wipes them
 * (mirrors how the visibility panel preserves nofollow/noarchive).
 *
 * @param string              $setting_key Settings array key.
 * @param string              $key         Entity key.
 * @param array<string,mixed> $row         Current values for this entity.
 * @param string              $id_prefix   Field id prefix.
 * @param bool                $is_simple   Whether simplified mode is active.
 * @return void
 */
function erankly_render_special_page_social_defaults( string $setting_key, string $key, array $row, string $id_prefix, bool $is_simple ): void {
	$name           = ERANKLY_OPTION . '[' . $setting_key . '][' . $key . ']';
	$og_title       = isset( $row['og_title'] ) ? (string) $row['og_title'] : '';
	$og_description = isset( $row['og_description'] ) ? (string) $row['og_description'] : '';
	$tw_title       = isset( $row['twitter_title'] ) ? (string) $row['twitter_title'] : '';
	$tw_description = isset( $row['twitter_description'] ) ? (string) $row['twitter_description'] : '';
	$image_url      = isset( $row['social_image_url'] ) ? (string) $row['social_image_url'] : '';
	$image_id       = isset( $row['og_image_id'] ) ? absint( $row['og_image_id'] ) : 0;

	if ( $is_simple ) {
		?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[og_title]" value="<?php echo esc_attr( $og_title ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[og_description]" value="<?php echo esc_attr( $og_description ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[twitter_title]" value="<?php echo esc_attr( $tw_title ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[twitter_description]" value="<?php echo esc_attr( $tw_description ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[social_image_url]" value="<?php echo esc_attr( $image_url ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[og_image_id]" value="<?php echo esc_attr( (string) $image_id ); ?>">
		<?php
		return;
	}
	?>
	<div class="erankly-defaults-section erankly-special-social-defaults">
		<h4><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></h4>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-og-title"><strong><?php esc_html_e( 'Social title', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="<?php echo esc_attr( $id_prefix ); ?>-og-title" class="widefat" type="text" name="<?php echo esc_attr( $name ); ?>[og_title]" value="<?php echo esc_attr( $og_title ); ?>">
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-og-description"><strong><?php esc_html_e( 'Social description', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<textarea id="<?php echo esc_attr( $id_prefix ); ?>-og-description" class="widefat" rows="3" name="<?php echo esc_attr( $name ); ?>[og_description]"><?php echo esc_textarea( $og_description ); ?></textarea>
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-twitter-title"><strong><?php esc_html_e( 'X (Twitter) title', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="<?php echo esc_attr( $id_prefix ); ?>-twitter-title" class="widefat" type="text" name="<?php echo esc_attr( $name ); ?>[twitter_title]" value="<?php echo esc_attr( $tw_title ); ?>">
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-twitter-description"><strong><?php esc_html_e( 'X (Twitter) description', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<textarea id="<?php echo esc_attr( $id_prefix ); ?>-twitter-description" class="widefat" rows="3" name="<?php echo esc_attr( $name ); ?>[twitter_description]"><?php echo esc_textarea( $tw_description ); ?></textarea>
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-social-image"><strong><?php esc_html_e( 'Social image', 'easyrankly' ); ?></strong></label>
			<?php
			erankly_render_media_url_field(
				$id_prefix . '-social-image',
				$name . '[social_image_url]',
				$image_url,
				'',
				$name . '[og_image_id]',
				$image_id,
				true
			);
			?>
		</div>
	</div>
	<?php
}

/**
 * Renders global visibility defaults.
 *
 * @param string $setting_key     Settings array key.
 * @param string $entity_key      Entity key.
 * @param bool   $noindex              Noindex default.
 * @param bool   $nofollow             Nofollow default.
 * @param bool   $noarchive            Noarchive default.
 * @param bool   $disable_sitemap      Disable sitemap default.
 * @param bool   $show_disable_sitemap Whether the entity can appear in a sitemap.
 *                                     When false the "Disable sitemap" control is
 *                                     hidden (e.g. special pages other than the
 *                                     author archive, the only one the XML sitemap
 *                                     consumes this flag for).
 * @return void
 */
function erankly_render_global_visibility_defaults( string $setting_key, string $entity_key, bool $noindex, bool $nofollow, bool $noarchive, bool $disable_sitemap, bool $show_disable_sitemap = true ): void {
	$name_prefix = ERANKLY_OPTION . '[' . $setting_key . '][' . $entity_key . ']';
	$is_simple   = (bool) erankly_get_setting( 'simplified_mode', 1 );
	// When the sitemap toggle does not apply to this entity, "hide from search
	// results" is driven by noindex alone.
	$is_hidden = $show_disable_sitemap ? ( $noindex && $disable_sitemap ) : $noindex;
	?>
	<fieldset class="erankly-field erankly-checkboxes erankly-visibility-defaults">
		<legend><strong><?php esc_html_e( 'Visibility defaults', 'easyrankly' ); ?></strong></legend>
		<div class="erankly-checkbox-options">
			<?php if ( $is_simple ) : ?>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[hide_from_search_results]" value="1" <?php checked( $is_hidden ); ?>> <?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></label>
				<?php // The simplified control only drives noindex + disable_sitemap; carry the advanced-only directives through so saving in simplified mode never wipes them. ?>
				<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="<?php echo $nofollow ? '1' : '0'; ?>">
				<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="<?php echo $noarchive ? '1' : '0'; ?>">
			<?php else : ?>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[noindex]" value="1" <?php checked( $noindex ); ?>> <?php esc_html_e( 'Noindex', 'easyrankly' ); ?></label>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="1" <?php checked( $nofollow ); ?>> <?php esc_html_e( 'Nofollow', 'easyrankly' ); ?></label>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="1" <?php checked( $noarchive ); ?>> <?php esc_html_e( 'Noarchive', 'easyrankly' ); ?></label>
				<?php if ( $show_disable_sitemap ) : ?>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[disable_sitemap]" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</fieldset>
	<?php
}
