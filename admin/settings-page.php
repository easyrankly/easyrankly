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
	$redirect_exclude_admins = ! empty( $input['redirect_exclude_admins'] );

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
		'website_name'                   => isset( $input['website_name'] ) ? erankly_sanitize_text( $input['website_name'] ) : $defaults['website_name'],
		'website_description'            => isset( $input['website_description'] ) ? erankly_sanitize_textarea( $input['website_description'] ) : $defaults['website_description'],
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
		'enable_sitemap'                 => ! empty( $input['enable_sitemap'] ) ? 1 : 0,
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
		'enable_redirects'               => ! empty( $input['enable_redirects'] ) ? 1 : 0,
		'redirect_exclude_admins'        => $redirect_exclude_admins ? 1 : 0,
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
 * Schema, Sitemap, Advanced).
 *
 * @return array<int,string>
 */
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

/**
 * Registry of settings panels that autosave via REST (see
 * erankly_rest_save_settings_panel() in easyrankly.php). Each entry lists the
 * top-level erankly_settings[...] keys that panel owns, and an optional
 * 'normalize' callback, a callable( array $merged, array $changes ): array,
 * run on the merged array before sanitizing, for panels whose sanitizer
 * branches on isset() in a way that only makes sense for a full-page
 * submission.
 *
 * @return array<string,array{keys:array<int,string>,normalize?:callable}>
 */
function erankly_settings_autosave_panels(): array {
	$panels = array(
		'general'  => array( 'keys' => erankly_general_panel_setting_keys() ),
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
			),
		),
		'settings' => array(
			'keys' => array(
				'simplified_mode',
				'resolve_placeholders',
				'redirect_exclude_admins',
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

	/**
	 * Filters the settings-panel autosave registry.
	 *
	 * @param array<string,array{keys:array<int,string>,normalize?:callable}> $panels Panel registry.
	 */
	$panels = apply_filters( 'erankly_settings_autosave_panels', $panels );

	return is_array( $panels ) ? $panels : array();
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

	$reserved = array( 'general', 'features', 'social', 'schema', 'sitemap', 'settings', 'advanced', 'import-export', 'redirects', 'special-pages' );
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
	<a class="erankly-settings-nav-item<?php echo $is_active ? ' is-active' : ''; ?>" id="erankly-settings-tab-<?php echo esc_attr( $slug ); ?>" href="<?php echo esc_url( erankly_settings_tab_url( $slug ) ); ?>" data-erankly-tab="<?php echo esc_attr( $panel ); ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?> <?php echo $hidden ? 'hidden' : ''; ?>><?php echo esc_html( $label ); ?></a>
	<?php
}
