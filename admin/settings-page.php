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

	if ( $social_defaults_linked ) {
		$default_twitter_title       = $default_og_title;
		$default_twitter_description = $default_og_description;
	}

	$settings = array(
		'organization_name'              => isset( $input['organization_name'] ) ? erankly_sanitize_text( $input['organization_name'] ) : $defaults['organization_name'],
		'organization_logo'              => isset( $input['organization_logo'] ) ? absint( $input['organization_logo'] ) : $defaults['organization_logo'],
		'organization_logo_url'          => isset( $input['organization_logo_url'] ) ? erankly_sanitize_text( $input['organization_logo_url'] ) : $defaults['organization_logo_url'],
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
		'social_profiles'                => isset( $input['social_profiles'] ) ? erankly_sanitize_textarea( $input['social_profiles'] ) : '',
		'default_og_image'               => isset( $input['default_og_image'] ) ? absint( $input['default_og_image'] ) : 0,
		'default_social_image_url'       => isset( $input['default_social_image_url'] ) ? erankly_sanitize_text( $input['default_social_image_url'] ) : '',
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
		'global_special_meta'            => isset( $input['global_special_meta'] ) ? erankly_sanitize_global_entity_meta( $input['global_special_meta'], erankly_special_page_keys_list(), false ) : array(),
		'global_special_meta_linked'     => 0,
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
 * Sanitizes global title and description templates keyed by entity name.
 *
 * @param mixed             $input        Raw input.
 * @param array<int,string> $allowed_keys Entity keys allowed in settings.
 * @param bool              $linked       Whether one template should apply to every entity.
 * @return array<string,array<string,string>>
 */
function erankly_sanitize_global_entity_meta( mixed $input, array $allowed_keys, bool $linked = false ): array {
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

		if ( '' === $title && '' === $description && 0 === array_sum( $directives ) ) {
			continue;
		}

		$clean[ $entity ] = array(
			'title'       => $title,
			'description' => $description,
		) + $directives;
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
		'nofollow'        => ( ! empty( $fields['nofollow'] ) ) ? 1 : 0,
		'noarchive'       => ( ! empty( $fields['noarchive'] ) ) ? 1 : 0,
		'disable_sitemap' => ( $hide || ! empty( $fields['disable_sitemap'] ) ) ? 1 : 0,
	);
}

/**
 * Saves settings submitted from the Network Admin settings page.
 *
 * @return void
 */
function erankly_save_network_settings(): void {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_network_settings' );

	$raw       = isset( $_POST[ ERANKLY_OPTION ] ) ? wp_unslash( (array) $_POST[ ERANKLY_OPTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside erankly_sanitize_settings().
	$sanitized = erankly_sanitize_settings( $raw );

	update_site_option( ERANKLY_OPTION, $sanitized );
	set_site_transient(
		'erankly_settings_saved_' . get_current_user_id(),
		1,
		MINUTE_IN_SECONDS
	);

	wp_safe_redirect( network_admin_url( 'settings.php?page=erankly' ) );
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
	$active_panel       = $is_site_admin_on_network ? '' : 'settings-general';

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

	// On per-site network admin, default to first available per-site panel.
	if ( $is_site_admin_on_network && ! in_array( $active_panel, array( 'settings-health', 'settings-import-export', 'settings-redirects' ), true ) ) {
		$active_panel = $show_redirects_tab ? 'settings-redirects' : ( $show_health_tab ? 'settings-health' : 'settings-import-export' );
	}

	$show_settings_submit = ! $is_site_admin_on_network && ! in_array( $active_panel, array( 'settings-health', 'settings-import-export', 'settings-redirects', 'settings-multilingual' ), true );

	// Extension tabs render their own form, so hide the shared "Save Changes" button on them.
	if ( in_array( $active_panel, array_map( static fn( $slug ) => 'settings-' . $slug, array_keys( $extra_tabs ) ), true ) ) {
		$show_settings_submit = false;
	}
	?>
	<div class="wrap erankly-settings">
		<h1><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h1>
		<?php
		if ( is_network_admin() ) {
			$settings_saved_transient = 'erankly_settings_saved_' . get_current_user_id();

			if ( get_site_transient( $settings_saved_transient ) ) {
				delete_site_transient( $settings_saved_transient );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'easyrankly' ) . '</p></div>';
			}
		} else {
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
		<?php if ( is_network_admin() ) : ?>
		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=erankly_network_save' ) ); ?>">
			<?php wp_nonce_field( 'erankly_network_settings' ); ?>
		<?php elseif ( ! $is_site_admin_on_network ) : ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'erankly' ); ?>
		<?php endif; ?>

			<div class="nav-tab-wrapper wp-clearfix erankly-settings-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Plugin settings', 'easyrankly' ); ?>" data-erankly-settings-tablist data-erankly-active-panel="<?php echo esc_attr( $active_panel ); ?>">
				<?php if ( ! $is_site_admin_on_network ) : ?>
				<button type="button" class="nav-tab erankly-tab<?php echo 'settings-general' === $active_panel ? ' nav-tab-active is-active' : ''; ?>" id="erankly-settings-tab-general" role="tab" aria-selected="<?php echo 'settings-general' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-general" data-erankly-tab="settings-general"><?php esc_html_e( 'General', 'easyrankly' ); ?></button>
				<button type="button" class="nav-tab erankly-tab<?php echo 'settings-features' === $active_panel ? ' nav-tab-active is-active' : ''; ?>" id="erankly-settings-tab-features" role="tab" aria-selected="<?php echo 'settings-features' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-features" data-erankly-tab="settings-features"><?php esc_html_e( 'Features', 'easyrankly' ); ?></button>
				<button type="button" class="nav-tab erankly-tab" id="erankly-settings-tab-social" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-social" data-erankly-tab="settings-social"><?php esc_html_e( 'Social', 'easyrankly' ); ?></button>
				<button type="button" class="nav-tab erankly-tab" id="erankly-settings-tab-schema" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-schema" data-erankly-tab="settings-schema"><?php esc_html_e( 'Schema', 'easyrankly' ); ?></button>
					<?php if ( $sitemap_enabled ) : ?>
				<button type="button" class="nav-tab erankly-tab" id="erankly-settings-tab-sitemap" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-sitemap" data-erankly-tab="settings-sitemap"><?php esc_html_e( 'Sitemap', 'easyrankly' ); ?></button>
				<?php endif; ?>
					<?php if ( is_multisite() && is_network_admin() && $multilingual_enabled ) : ?>
				<button type="button" class="nav-tab erankly-tab<?php echo 'settings-multilingual' === $active_panel ? ' nav-tab-active is-active' : ''; ?>" id="erankly-settings-tab-multilingual" role="tab" aria-selected="<?php echo 'settings-multilingual' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-multilingual" data-erankly-tab="settings-multilingual"><?php esc_html_e( 'Multilingual', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php endif; ?>
				<?php if ( $show_health_tab ) : ?>
				<button type="button" class="nav-tab erankly-tab<?php echo 'settings-health' === $active_panel ? ' nav-tab-active is-active' : ''; ?>" id="erankly-settings-tab-health" role="tab" aria-selected="<?php echo 'settings-health' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-health" data-erankly-tab="settings-health"><?php esc_html_e( 'Health', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php if ( ! $is_site_admin_on_network ) : ?>
				<button type="button" class="nav-tab erankly-tab" id="erankly-settings-tab-settings" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-settings" data-erankly-tab="settings-settings"><?php esc_html_e( 'Settings', 'easyrankly' ); ?></button>
				<button type="button" class="nav-tab erankly-tab" id="erankly-settings-tab-advanced" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-advanced" data-erankly-tab="settings-advanced" data-erankly-advanced-tab <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>><?php esc_html_e( 'Advanced', 'easyrankly' ); ?></button>
				<button type="button" class="nav-tab erankly-tab" id="erankly-settings-tab-bloat" role="tab" aria-selected="false" aria-controls="erankly-settings-panel-bloat" data-erankly-tab="settings-bloat"><?php esc_html_e( 'Bloat', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<button type="button" class="nav-tab erankly-tab<?php echo 'settings-import-export' === $active_panel ? ' nav-tab-active is-active' : ''; ?>" id="erankly-settings-tab-import-export" role="tab" aria-selected="<?php echo 'settings-import-export' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-import-export" data-erankly-tab="settings-import-export"><?php esc_html_e( 'Import / Export', 'easyrankly' ); ?></button>
				<?php if ( $show_redirects_tab ) : ?>
				<button type="button" class="nav-tab erankly-tab<?php echo 'settings-redirects' === $active_panel ? ' nav-tab-active is-active' : ''; ?>" id="erankly-settings-tab-redirects" role="tab" aria-selected="<?php echo 'settings-redirects' === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-redirects" data-erankly-tab="settings-redirects"><?php esc_html_e( 'Redirects', 'easyrankly' ); ?></button>
				<?php endif; ?>
				<?php
				foreach ( $extra_tabs as $extra_slug => $extra_tab ) :
					if ( ! current_user_can( $extra_tab['capability'] ) ) {
						continue;
					}
					$extra_panel = 'settings-' . $extra_slug;
					?>
				<button type="button" class="nav-tab erankly-tab<?php echo $extra_panel === $active_panel ? ' nav-tab-active is-active' : ''; ?>" id="erankly-settings-tab-<?php echo esc_attr( $extra_slug ); ?>" role="tab" aria-selected="<?php echo $extra_panel === $active_panel ? 'true' : 'false'; ?>" aria-controls="erankly-settings-panel-<?php echo esc_attr( $extra_slug ); ?>" data-erankly-tab="<?php echo esc_attr( $extra_panel ); ?>"><?php echo esc_html( $extra_tab['label'] ); ?></button>
				<?php endforeach; ?>
			</div>

				<div class="erankly-tab-panel<?php echo 'settings-features' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-features" role="tabpanel" aria-labelledby="erankly-settings-tab-features" data-erankly-settings-panel="settings-features" <?php echo 'settings-features' === $active_panel ? '' : 'hidden'; ?>>
					<h2><?php esc_html_e( 'Features', 'easyrankly' ); ?></h2>
					<div class="erankly-settings-fields">
						<fieldset class="erankly-field erankly-checkboxes">
							<legend><strong><?php esc_html_e( 'Redirect management', 'easyrankly' ); ?></strong></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_redirects]" value="1" <?php checked( $redirects_enabled ); ?>> <?php esc_html_e( 'Enable the redirect manager', 'easyrankly' ); ?></label>
							<p class="description"><?php esc_html_e( 'When enabled, EasyRankly loads its redirect engine and stores rules in a dedicated database table. While disabled, no redirect code runs on the front end. Save changes to apply, then manage your redirects from the Redirects tab.', 'easyrankly' ); ?></p>
						</fieldset>
						<fieldset class="erankly-field erankly-checkboxes">
							<legend><strong><?php esc_html_e( 'Sitemap', 'easyrankly' ); ?></strong></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_sitemap]" value="1" <?php checked( $sitemap_enabled ); ?>> <?php esc_html_e( 'Enable the sitemap module', 'easyrankly' ); ?></label>
							<p class="description"><?php esc_html_e( 'When enabled, EasyRankly loads its XML sitemap generator, disables WordPress core sitemaps, and exposes sitemap settings. While disabled, no sitemap code runs on the front end. Save changes to apply.', 'easyrankly' ); ?></p>
						</fieldset>
						<fieldset class="erankly-field erankly-checkboxes">
							<legend><strong><?php esc_html_e( 'Health', 'easyrankly' ); ?></strong></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_health]" value="1" <?php checked( $health_enabled ); ?>> <?php esc_html_e( 'Enable Health monitoring', 'easyrankly' ); ?></label>
							<p class="description"><?php esc_html_e( 'When enabled, EasyRankly loads the Health module and continuously monitors selected site signals. This can have a small performance impact because lightweight counters are updated while requests are handled. While disabled, no Health module or scanner code is loaded. Save changes to apply, then review findings from the Health tab.', 'easyrankly' ); ?></p>
						</fieldset>
						<fieldset class="erankly-field erankly-checkboxes">
							<legend><strong><?php esc_html_e( 'Multilingual', 'easyrankly' ); ?></strong></legend>
							<p class="description" style="margin:2px 0 8px;"><?php erankly_render_multisite_status(); ?></p>
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_multilingual]" value="1" <?php checked( $multilingual_enabled ); ?> <?php disabled( ! is_multisite() ); ?>> <?php esc_html_e( 'Enable multilingual', 'easyrankly' ); ?></label>
							<p class="description"><?php esc_html_e( 'Requires WordPress Multisite.', 'easyrankly' ); ?> <?php esc_html_e( 'Enables cross-site language alternates for translated content. After saving, configure site language codes and link translations from the Multilingual tab (Network Admin only).', 'easyrankly' ); ?></p>
							<?php if ( ! is_multisite() ) : ?>
							<p class="description"><em><?php esc_html_e( 'This feature cannot be enabled because the site is not running WordPress Multisite.', 'easyrankly' ); ?></em></p>
							<?php endif; ?>
						</fieldset>
					</div>
				</div>

				<div class="erankly-tab-panel<?php echo 'settings-general' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-general" role="tabpanel" aria-labelledby="erankly-settings-tab-general" data-erankly-settings-panel="settings-general" <?php echo 'settings-general' === $active_panel ? '' : 'hidden'; ?>>
					<h2><?php esc_html_e( 'General', 'easyrankly' ); ?></h2>
					<div class="erankly-settings-fields">
					<div class="erankly-field">
						<label for="erankly-organization-name"><strong><?php esc_html_e( 'Organization or person name', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="erankly-organization-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_name]" value="<?php echo esc_attr( (string) $settings['organization_name'] ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
						<div class="erankly-schema-identity-fields<?php echo 'person' === $settings['schema_identity'] ? ' is-person' : ''; ?>" data-erankly-schema-identity-fields>
						<div class="erankly-field">
							<label for="erankly-schema-identity"><strong><?php esc_html_e( 'Identity type', 'easyrankly' ); ?></strong></label>
							<select id="erankly-schema-identity" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_identity]" data-erankly-schema-identity>
								<option value="organization" <?php selected( $settings['schema_identity'], 'organization' ); ?>><?php esc_html_e( 'Organization', 'easyrankly' ); ?></option>
								<option value="person" <?php selected( $settings['schema_identity'], 'person' ); ?>><?php esc_html_e( 'Person', 'easyrankly' ); ?></option>
							</select>
						</div>
						<div class="erankly-field" data-erankly-person-reference-field <?php echo 'person' === $settings['schema_identity'] ? '' : 'hidden'; ?>>
							<label><strong><?php esc_html_e( 'Person reference user', 'easyrankly' ); ?></strong></label>
							<div class="erankly-user-search-wrap" data-erankly-user-search-wrap>
								<input type="hidden"
									name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_person_user_id]"
									value="<?php echo esc_attr( (string) $schema_person_user_id ); ?>"
									data-erankly-user-id>
								<div class="erankly-autocomplete-control erankly-user-control">
									<div class="erankly-autocomplete-value erankly-user-selected" data-erankly-user-selected<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>>
										<input type="text"
											class="widefat erankly-user-selected-input"
											readonly
											value="<?php echo ( $schema_person_user instanceof WP_User ) ? esc_attr( sprintf( /* translators: 1: User display name, 2: User ID. */ __( '%1$s (ID: %2$d)', 'easyrankly' ), $schema_person_user->display_name, $schema_person_user->ID ) ) : ''; ?>"
											data-erankly-user-selected-name>
									</div>
									<div class="erankly-autocomplete-search erankly-user-search" data-erankly-user-search-input-wrap<?php echo ( $schema_person_user instanceof WP_User ) ? ' hidden' : ''; ?>>
										<input type="search"
											class="widefat erankly-user-search-input"
											placeholder="<?php esc_attr_e( 'Search users…', 'easyrankly' ); ?>"
											autocomplete="off"
											aria-autocomplete="list"
											aria-label="<?php esc_attr_e( 'Search users', 'easyrankly' ); ?>"
											data-erankly-user-search-input>
										<ul class="erankly-autocomplete-results erankly-user-results" role="listbox" hidden data-erankly-user-results></ul>
									</div>
									<button type="button" class="button erankly-user-remove" data-erankly-user-remove<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'easyrankly' ); ?></button>
								</div>
							</div>
							</div>
							<p class="description erankly-schema-person-reference-description" data-erankly-person-reference-description <?php echo 'person' === $settings['schema_identity'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Uses the selected WordPress profile for the global Person JSON-LD schema.', 'easyrankly' ); ?></p>
						</div>
						<div data-erankly-organization-only <?php echo $show_organization_fields ? '' : 'hidden'; ?>>
							<div class="erankly-field">
								<label for="erankly-organization-description"><strong><?php esc_html_e( 'Organization description', 'easyrankly' ); ?></strong></label>
								<textarea id="erankly-organization-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_description]"><?php echo esc_textarea( (string) $settings['organization_description'] ); ?></textarea>
							</div>
							<div class="erankly-inline-fields erankly-inline-fields-two-columns">
								<div class="erankly-field">
									<label for="erankly-organization-email"><strong><?php esc_html_e( 'Business email', 'easyrankly' ); ?></strong></label>
									<input id="erankly-organization-email" class="widefat" type="email" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_email]" value="<?php echo esc_attr( (string) $settings['organization_email'] ); ?>">
								</div>
								<div class="erankly-field">
									<label for="erankly-organization-phone"><strong><?php esc_html_e( 'Business telephone', 'easyrankly' ); ?></strong></label>
									<input id="erankly-organization-phone" class="widefat" type="tel" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_phone]" value="<?php echo esc_attr( (string) $settings['organization_phone'] ); ?>" placeholder="+1 555 123 4567">
									<p class="description"><?php esc_html_e( 'Include country and area codes.', 'easyrankly' ); ?></p>
								</div>
							</div>
							<?php erankly_render_organization_details( $settings ); ?>
						</div>
					</div>

				<div class="erankly-defaults-section">
					<h3><?php esc_html_e( 'Post type defaults', 'easyrankly' ); ?></h3>
					<?php erankly_render_global_meta_defaults( 'global_post_type_meta', erankly_get_public_post_types(), $settings ); ?>
				</div>

				<div class="erankly-defaults-section">
					<h3><?php esc_html_e( 'Taxonomy defaults', 'easyrankly' ); ?></h3>
					<?php erankly_render_global_meta_defaults( 'global_taxonomy_meta', erankly_get_public_taxonomies(), $settings ); ?>
				</div>

				<div class="erankly-defaults-section">
					<h3><?php esc_html_e( 'Special pages and archives', 'easyrankly' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Configure SEO defaults for detected theme templates and WordPress-generated contexts such as search results, the 404 page, and archive views. Block themes list Site Editor templates first; classic themes follow the standard PHP template hierarchy.', 'easyrankly' ); ?></p>
					<?php erankly_render_special_page_defaults( erankly_special_page_keys(), $settings ); ?>
				</div>
			</div>

			<div class="erankly-tab-panel" id="erankly-settings-panel-social" role="tabpanel" aria-labelledby="erankly-settings-tab-social" data-erankly-settings-panel="settings-social" hidden>
				<h2><?php esc_html_e( 'Social', 'easyrankly' ); ?></h2>
				<div class="erankly-settings-fields">
					<div class="erankly-field">
						<label for="erankly-organization-logo-url"><strong><?php esc_html_e( 'Organization logo', 'easyrankly' ); ?></strong></label>
						<?php
						$organization_logo_id  = absint( $settings['organization_logo'] );
						$organization_logo_url = isset( $settings['organization_logo_url'] ) ? (string) $settings['organization_logo_url'] : '';

						if ( '' === $organization_logo_url && $organization_logo_id > 0 ) {
							$organization_logo_url = erankly_get_image_url( $organization_logo_id, 'full' );
						}

						if ( '' === $organization_logo_url ) {
							$organization_logo_url = erankly_default_organization_logo_url_template();
						}

						erankly_render_media_url_field(
							'erankly-organization-logo-url',
							ERANKLY_OPTION . '[organization_logo_url]',
							$organization_logo_url,
							erankly_default_organization_logo_placeholder(),
							ERANKLY_OPTION . '[organization_logo]',
							$organization_logo_id,
							false
						);
						?>
					</div>
					<div class="erankly-field">
						<label for="erankly-default-social-image-url"><strong><?php esc_html_e( 'Default social image URL', 'easyrankly' ); ?></strong></label>
						<?php
						erankly_render_media_url_field(
							'erankly-default-social-image-url',
							ERANKLY_OPTION . '[default_social_image_url]',
							(string) $settings['default_social_image_url'],
							erankly_default_social_image_placeholder(),
							ERANKLY_OPTION . '[default_og_image]',
							absint( $settings['default_og_image'] ),
							false
						);
						?>
					</div>
					<div class="erankly-defaults-section">
						<h3><?php esc_html_e( 'Social defaults', 'easyrankly' ); ?></h3>
						<?php erankly_render_social_meta_defaults( $settings ); ?>
					</div>
					<div class="erankly-field">
						<label for="erankly-twitter-site"><strong><?php esc_html_e( 'X (Twitter) Site', 'easyrankly' ); ?></strong></label>
						<input id="erankly-twitter-site" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[twitter_site]" value="<?php echo esc_attr( (string) $settings['twitter_site'] ); ?>" placeholder="@example">
						<p class="description"><?php esc_html_e( 'Used for the twitter:site meta tag.', 'easyrankly' ); ?></p>
					</div>
					<div class="erankly-field">
						<label for="erankly-social-profiles"><strong><?php esc_html_e( 'Social profiles', 'easyrankly' ); ?></strong></label>
						<textarea id="erankly-social-profiles" class="widefat" rows="5" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[social_profiles]"><?php echo esc_textarea( (string) $settings['social_profiles'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One absolute URL per line.', 'easyrankly' ); ?></p>
					</div>
					<?php if ( empty( $settings['bloat_remove_oembed'] ) ) : ?>
					<div class="erankly-field">
						<strong><?php esc_html_e( 'oEmbed JSON', 'easyrankly' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Active by default on every public page. EasyRankly outputs an oEmbed JSON discovery link (e.g. for LinkedIn) so platforms can fetch rich link-preview data. To disable it, enable "Remove oEmbed discovery links" in the Bloat tab.', 'easyrankly' ); ?></p>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="erankly-tab-panel" id="erankly-settings-panel-schema" role="tabpanel" aria-labelledby="erankly-settings-tab-schema" data-erankly-settings-panel="settings-schema" hidden>
				<h2><?php esc_html_e( 'Schema', 'easyrankly' ); ?></h2>
				<div class="erankly-settings-fields">
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Breadcrumbs', 'easyrankly' ); ?></strong></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_breadcrumbs]" value="1" <?php checked( $settings['enable_breadcrumbs'], 1 ); ?>> <?php esc_html_e( 'Enable breadcrumbs function', 'easyrankly' ); ?></label>
					</fieldset>
						<div class="erankly-field">
							<strong><?php esc_html_e( 'Post Date Settings', 'easyrankly' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Active by default on every page of the site. EasyRankly includes published and modified dates in automatic post schema when post data is available.', 'easyrankly' ); ?></p>
						</div>
						<?php erankly_render_local_business_settings( $settings ); ?>
					</div>
				<div class="erankly-schema-builder" data-erankly-schema-builder data-erankly-custom-schema-section data-erankly-next-index="<?php echo esc_attr( (string) count( $global_schema_blocks ) ); ?>" <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
					<div class="erankly-settings-fields">
						<div class="erankly-field">
							<strong><?php esc_html_e( 'Custom JSON-LD Schema', 'easyrankly' ); ?></strong>
						</div>
					</div>
					<div class="erankly-schema-blocks <?php echo empty( $global_schema_blocks ) ? 'is-empty' : ''; ?>" data-erankly-schema-blocks>
						<?php foreach ( $global_schema_blocks as $index => $block ) : ?>
							<?php erankly_render_schema_block( is_array( $block ) ? $block : array(), (string) $index, $global_schema_name, true ); ?>
						<?php endforeach; ?>
					</div>

					<template data-erankly-schema-template>
						<?php erankly_render_schema_block( array(), '__INDEX__', $global_schema_name, true ); ?>
					</template>

					<p class="erankly-schema-actions"><button type="button" class="button button-secondary" data-erankly-add-schema><?php esc_html_e( 'Add Schema', 'easyrankly' ); ?></button></p>
				</div>
			</div>

			<?php if ( $sitemap_enabled ) : ?>
			<div class="erankly-tab-panel" id="erankly-settings-panel-sitemap" role="tabpanel" aria-labelledby="erankly-settings-tab-sitemap" data-erankly-settings-panel="settings-sitemap" hidden>
				<h2><?php esc_html_e( 'Sitemap', 'easyrankly' ); ?></h2>
				<div class="erankly-settings-fields">
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'XML sitemap', 'easyrankly' ); ?></strong></legend>
						<p class="description">
							<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open wp-sitemap.xml', 'easyrankly' ); ?></a>
						</p>
						<p class="description"><?php esc_html_e( 'Author sitemap: included only when at least two authors have sitemap-eligible published content. On single-author sites it is disabled to avoid duplicate archive URLs for SEO.', 'easyrankly' ); ?></p>
						<p class="description"><?php esc_html_e( 'Image, Video and News sitemaps are integrated directly into the core wp-sitemap.xml index when enabled.', 'easyrankly' ); ?></p>
					</fieldset>
					<div class="erankly-defaults-section">
						<h3><?php esc_html_e( 'Additional sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-default-tabs" data-erankly-tabs-root>
							<div class="erankly-default-tabs-bar">
								<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="erankly-additional-sitemap-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Additional sitemap settings', 'easyrankly' ); ?>">
									<button type="button" class="nav-tab erankly-tab nav-tab-active is-active" id="erankly-additional-sitemap-news-tab" role="tab" aria-selected="true" aria-controls="erankly-additional-sitemap-news-panel" data-erankly-tab="additional-sitemap-news"><?php esc_html_e( 'Google News sitemap', 'easyrankly' ); ?></button>
									<button type="button" class="nav-tab erankly-tab" id="erankly-additional-sitemap-image-tab" role="tab" aria-selected="false" aria-controls="erankly-additional-sitemap-image-panel" data-erankly-tab="additional-sitemap-image"><?php esc_html_e( 'Image sitemap', 'easyrankly' ); ?></button>
									<button type="button" class="nav-tab erankly-tab" id="erankly-additional-sitemap-video-tab" role="tab" aria-selected="false" aria-controls="erankly-additional-sitemap-video-panel" data-erankly-tab="additional-sitemap-video"><?php esc_html_e( 'Video sitemap', 'easyrankly' ); ?></button>
								</div>
							</div>

							<div class="erankly-tab-panel erankly-default-tab-panel is-active" id="erankly-additional-sitemap-news-panel" role="tabpanel" aria-labelledby="erankly-additional-sitemap-news-tab" data-erankly-panel="additional-sitemap-news">
								<fieldset class="erankly-field erankly-checkboxes">
									<legend><strong><?php esc_html_e( 'Google News sitemap', 'easyrankly' ); ?></strong></legend>
									<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_news_sitemap]" value="1" <?php checked( $settings['enable_news_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate Google News sitemap', 'easyrankly' ); ?></label>
									<p class="description">
										<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-news-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-news-1.xml', 'easyrankly' ); ?></a>
									</p>
									<p class="description"><?php esc_html_e( 'Includes only posts (post type: post) published in the last 48 hours. Submitting a News sitemap does not guarantee inclusion in Google News — editorial review by Google is still required.', 'easyrankly' ); ?></p>
									<div class="erankly-field">
										<p><strong><?php esc_html_e( 'Included post types', 'easyrankly' ); ?></strong></p>
										<div class="erankly-checkboxes">
											<?php
											$news_post_types = (array) erankly_get_setting( 'news_sitemap_post_types', array( 'post' ) );
											foreach ( erankly_get_public_post_types() as $post_type => $object ) :
												?>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[news_sitemap_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $news_post_types, true ) ); ?>>
													<?php echo esc_html( $object->labels->singular_name ); ?>
												</label><br>
											<?php endforeach; ?>
										</div>
									</div>
									<div class="erankly-field">
										<label for="erankly-news-publication-name"><strong><?php esc_html_e( 'News publication name', 'easyrankly' ); ?></strong></label>
										<input
											id="erankly-news-publication-name"
											class="widefat"
											type="text"
											name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[news_publication_name]"
											value="<?php echo esc_attr( (string) $settings['news_publication_name'] ); ?>"
											maxlength="200"
										>
										<p class="description">
											<?php esc_html_e( 'The publication name to include in the Google News sitemap. Leave blank to use the organization name or the site title. An empty name will prevent the sitemap from being generated.', 'easyrankly' ); ?>
										</p>
									</div>
								</fieldset>
							</div>

							<div class="erankly-tab-panel erankly-default-tab-panel" id="erankly-additional-sitemap-image-panel" role="tabpanel" aria-labelledby="erankly-additional-sitemap-image-tab" data-erankly-panel="additional-sitemap-image" hidden>
								<fieldset class="erankly-field erankly-checkboxes">
									<legend><strong><?php esc_html_e( 'Image sitemap', 'easyrankly' ); ?></strong></legend>
									<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_image_sitemap]" value="1" <?php checked( $settings['enable_image_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate image sitemap', 'easyrankly' ); ?></label>
									<p class="description">
										<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-image-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-image-1.xml', 'easyrankly' ); ?></a>
									</p>
									<p class="description"><?php esc_html_e( 'Associates images with the public pages that contain them. Images are extracted from post content (featured image, embedded images, Gutenberg image/gallery blocks). Attachment pages are not used as page URLs.', 'easyrankly' ); ?></p>
								</fieldset>
							</div>

							<div class="erankly-tab-panel erankly-default-tab-panel" id="erankly-additional-sitemap-video-panel" role="tabpanel" aria-labelledby="erankly-additional-sitemap-video-tab" data-erankly-panel="additional-sitemap-video" hidden>
								<fieldset class="erankly-field erankly-checkboxes">
									<legend><strong><?php esc_html_e( 'Video sitemap', 'easyrankly' ); ?></strong></legend>
									<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_video_sitemap]" value="1" <?php checked( $settings['enable_video_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate video sitemap', 'easyrankly' ); ?></label>
									<p class="description">
										<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-video-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-video-1.xml', 'easyrankly' ); ?></a>
									</p>
									<p class="description"><?php esc_html_e( 'Includes published posts that contain YouTube, Vimeo or self-hosted HTML5 videos. Multiple videos on the same page are each included. Submitting a Video sitemap does not guarantee Google indexing; the embedded player must also be crawlable.', 'easyrankly' ); ?></p>
								</fieldset>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<div class="erankly-tab-panel" id="erankly-settings-panel-settings" role="tabpanel" aria-labelledby="erankly-settings-tab-settings" data-erankly-settings-panel="settings-settings" hidden>
				<h2><?php esc_html_e( 'Settings', 'easyrankly' ); ?></h2>
				<div class="erankly-settings-fields">
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Interface mode', 'easyrankly' ); ?></strong></legend>
						<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[simplified_mode]" value="1" <?php checked( $settings['simplified_mode'], 1 ); ?>> <?php esc_html_e( 'Simplified mode', 'easyrankly' ); ?></label>
						<p class="description"><?php esc_html_e( 'When enabled, Noindex and Disable sitemap are shown as one Hide from search results control.', 'easyrankly' ); ?></p>
						<p class="description"><a href="<?php echo esc_url( erankly_setup_wizard_url( 'configure' ) ); ?>"><?php esc_html_e( 'Run the setup wizard again', 'easyrankly' ); ?></a></p>
					</fieldset>
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'SEO checklist', 'easyrankly' ); ?></strong></legend>
						<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_seo_checklist]" value="1" <?php checked( erankly_seo_checklist_preference_enabled() ); ?>> <?php esc_html_e( 'Show the SEO checklist on posts and pages', 'easyrankly' ); ?></label>
						<p class="description"><?php esc_html_e( 'Adds a small floating checklist (meta title, meta description, featured image) to the post editor and to the frontend for users who can edit the content. Available only while Simplified mode is active.', 'easyrankly' ); ?></p>
					</fieldset>
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Page source', 'easyrankly' ); ?></strong></legend>
						<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[add_head_credit]" value="1" <?php checked( empty( $settings['hide_head_credit'] ) ); ?>> <?php esc_html_e( 'Add the "optimized with EasyRankly" comment to the page source', 'easyrankly' ); ?></label>
						<p class="description"><?php esc_html_e( 'EasyRankly wraps its <head> output in an HTML comment that identifies the plugin in the page source. Disable this to remove that comment.', 'easyrankly' ); ?></p>
					</fieldset>
					<?php if ( $redirects_enabled ) : ?>
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Redirects', 'easyrankly' ); ?></strong></legend>
						<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[redirect_exclude_admins]" value="1" <?php checked( ! empty( $settings['redirect_exclude_admins'] ) ); ?>> <?php esc_html_e( 'Do not apply any redirect to administrators', 'easyrankly' ); ?></label>
						<p class="description"><?php esc_html_e( 'When enabled, users with the "manage_options" capability (typically Administrators) will never be redirected, making it easier to manage redirects without being affected by them.', 'easyrankly' ); ?></p>
					</fieldset>
					<?php endif; ?>
				</div>
			</div>

			<div class="erankly-tab-panel" id="erankly-settings-panel-advanced" role="tabpanel" aria-labelledby="erankly-settings-tab-advanced" data-erankly-settings-panel="settings-advanced" data-erankly-advanced-panel hidden>
				<h2><?php esc_html_e( 'Advanced', 'easyrankly' ); ?></h2>
				<div class="erankly-settings-fields">
					<p class="description"><?php esc_html_e( 'Noindex for search results, the 404 page, and WordPress author/date archive contexts is now configured per page under General → Special pages and archives.', 'easyrankly' ); ?></p>
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Robots preview directives', 'easyrankly' ); ?></strong></legend>
						<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_image_preview_large]" value="1" <?php checked( $settings['robots_max_image_preview_large'], 1 ); ?>> <?php esc_html_e( 'Allow max-image-preview:large', 'easyrankly' ); ?></label>
						<div class="erankly-inline-fields erankly-inline-fields-two-columns">
							<div class="erankly-field">
								<label for="erankly-robots-max-snippet"><?php esc_html_e( 'max-snippet', 'easyrankly' ); ?></label>
								<input id="erankly-robots-max-snippet" class="widefat" type="number" step="1" min="-1" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_snippet]" value="<?php echo esc_attr( (string) $settings['robots_max_snippet'] ); ?>">
							</div>
							<div class="erankly-field">
								<label for="erankly-robots-max-video-preview"><?php esc_html_e( 'max-video-preview', 'easyrankly' ); ?></label>
								<input id="erankly-robots-max-video-preview" class="widefat" type="number" step="1" min="-1" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_video_preview]" value="<?php echo esc_attr( (string) $settings['robots_max_video_preview'] ); ?>">
							</div>
						</div>
						<div class="erankly-checkbox-options">
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_nosnippet]" value="1" <?php checked( $settings['robots_nosnippet'], 1 ); ?>> <?php esc_html_e( 'Add nosnippet', 'easyrankly' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_indexifembedded]" value="1" <?php checked( $settings['robots_indexifembedded'], 1 ); ?>> <?php esc_html_e( 'Add indexifembedded when noindex is active', 'easyrankly' ); ?></label>
						</div>
					</fieldset>

					<h3><?php esc_html_e( 'Attachment pages', 'easyrankly' ); ?></h3>
					<fieldset class="erankly-field">
						<legend><strong><?php esc_html_e( 'Redirect attachment pages', 'easyrankly' ); ?></strong></legend>
						<select name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[attachment_redirect]" id="erankly-attachment-redirect">
							<option value="parent" <?php selected( $settings['attachment_redirect'], 'parent' ); ?>><?php esc_html_e( 'Redirect to parent post (fallback: media file)', 'easyrankly' ); ?></option>
							<option value="file" <?php selected( $settings['attachment_redirect'], 'file' ); ?>><?php esc_html_e( 'Redirect to media file', 'easyrankly' ); ?></option>
							<option value="none" <?php selected( $settings['attachment_redirect'], 'none' ); ?>><?php esc_html_e( 'Leave attachment pages unchanged', 'easyrankly' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Attachment pages are thin-content pages WordPress generates for each uploaded file. Redirecting them is the recommended SEO practice.', 'easyrankly' ); ?></p>
					</fieldset>

					<h3><?php esc_html_e( 'Pagination', 'easyrankly' ); ?></h3>
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Paginated archive pages', 'easyrankly' ); ?></strong></legend>
						<div class="erankly-checkbox-options">
							<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[noindex_paginated]" value="1" <?php checked( $settings['noindex_paginated'], 1 ); ?>> <?php esc_html_e( 'Noindex page 2, 3, … of archives', 'easyrankly' ); ?></label>
						</div>
						<p class="description"><?php esc_html_e( 'When enabled, pages beyond the first of any archive (category, tag, author, date, blog) receive a noindex directive. Canonical URLs are already self-referencing; leave this off unless you have a specific reason to block crawling of deep pagination.', 'easyrankly' ); ?></p>
					</fieldset>
					<div class="erankly-field">
						<label for="erankly-paginated-title-format"><strong><?php esc_html_e( 'Paginated title suffix', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="erankly-paginated-title-format" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[paginated_title_format]" value="<?php echo esc_attr( (string) $settings['paginated_title_format'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Page {{page_number}} of {{max_pages}}', 'easyrankly' ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
						<p class="description"><?php esc_html_e( 'Appended to the SEO title on page 2, 3, … — separated by a dash. Leave empty to keep the base title unchanged. Available variables: {{page_number}}, {{max_pages}}.', 'easyrankly' ); ?></p>
					</div>

					<h3><?php esc_html_e( 'Tools', 'easyrankly' ); ?></h3>
					<div class="erankly-field">
						<label for="erankly-robots-txt-extra"><strong><?php esc_html_e( 'robots.txt — custom rules', 'easyrankly' ); ?></strong></label>
						<textarea id="erankly-robots-txt-extra" class="widefat code" rows="8" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_txt_extra]"><?php echo esc_textarea( (string) $settings['robots_txt_extra'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Extra directives appended to the virtual robots.txt after the auto-generated rules (User-agent, Disallow, Sitemap). One directive per line.', 'easyrankly' ); ?></p>
					</div>
					<div class="erankly-field">
						<strong><?php esc_html_e( 'robots.txt preview', 'easyrankly' ); ?></strong>
						<textarea class="widefat code" rows="12" readonly aria-label="<?php esc_attr_e( 'robots.txt preview', 'easyrankly' ); ?>"><?php echo esc_textarea( erankly_filter_robots_txt( '', (bool) get_option( 'blog_public' ) ) ); ?></textarea>
						<p class="description">
							<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open robots.txt', 'easyrankly' ); ?></a>
						</p>
					</div>
				</div>
			</div>

				<div class="erankly-tab-panel" id="erankly-settings-panel-bloat" role="tabpanel" aria-labelledby="erankly-settings-tab-bloat" data-erankly-settings-panel="settings-bloat" hidden>
					<h2><?php esc_html_e( 'Bloat', 'easyrankly' ); ?></h2>

					<div class="erankly-bloat-view erankly-bloat-view-simple" data-erankly-bloat-view="simple" <?php echo ! empty( $settings['simplified_mode'] ) ? '' : 'hidden'; ?>>
						<div class="erankly-settings-fields">
							<fieldset class="erankly-field erankly-checkboxes">
								<legend><strong><?php esc_html_e( 'WordPress optimization', 'easyrankly' ); ?></strong></legend>
								<label><input type="checkbox" data-erankly-bloat-master <?php checked( $safe_bloat_active ); ?>> <?php esc_html_e( 'Lighten WordPress', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes safe WordPress bloat in one click: emojis, WP Generator meta tag, RSD link, Windows Live Writer link, shortlink, REST API discovery link, and self-pingbacks. Cleanups that can affect functionality (RSS feed links, oEmbed, jQuery Migrate, Dashicons, frontend Heartbeat, XML-RPC) are available individually when Simplified mode is off.', 'easyrankly' ); ?></p>
							</fieldset>
						</div>
					</div>

					<div class="erankly-bloat-view erankly-bloat-view-advanced" data-erankly-bloat-view="advanced" <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
						<div class="erankly-settings-fields">

							<fieldset class="erankly-field erankly-checkboxes">
								<legend><strong><?php esc_html_e( 'Emojis', 'easyrankly' ); ?></strong></legend>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_emoji]" value="1" <?php checked( $settings['bloat_remove_emoji'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <?php esc_html_e( 'Remove WordPress emojis', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes the emoji detection script, emoji CSS, DNS prefetch for s.w.org, and the TinyMCE emoji plugin.', 'easyrankly' ); ?></p>
							</fieldset>

							<fieldset class="erankly-field erankly-checkboxes">
								<legend><strong><?php esc_html_e( 'Head tags', 'easyrankly' ); ?></strong></legend>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_generator]" value="1" <?php checked( $settings['bloat_remove_generator'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <?php esc_html_e( 'Remove WP Generator meta tag', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Hides the WordPress version number from the HTML source.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_feed_links]" value="1" <?php checked( $settings['bloat_remove_feed_links'], 1 ); ?> data-erankly-bloat-item> <?php esc_html_e( 'Remove RSS feed links', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes the auto-discovery &lt;link&gt; tags for post and comment RSS feeds.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_rsd_link]" value="1" <?php checked( $settings['bloat_remove_rsd_link'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <?php esc_html_e( 'Remove Really Simple Discovery link', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes the RSD &lt;link&gt; tag used by legacy XML-RPC clients.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_wlwmanifest]" value="1" <?php checked( $settings['bloat_remove_wlwmanifest'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <?php esc_html_e( 'Remove Windows Live Writer manifest link', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes the wlwmanifest.xml &lt;link&gt; tag used by Windows Live Writer.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_shortlink]" value="1" <?php checked( $settings['bloat_remove_shortlink'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <?php esc_html_e( 'Remove shortlink', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes the &lt;link rel="shortlink"&gt; tag and the Link HTTP header.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_rest_link]" value="1" <?php checked( $settings['bloat_remove_rest_link'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <?php esc_html_e( 'Remove REST API discovery link', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes the &lt;link rel="https://api.w.org/"&gt; auto-discovery tag and the Link HTTP header.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_oembed]" value="1" <?php checked( $settings['bloat_remove_oembed'], 1 ); ?> data-erankly-bloat-item> <?php esc_html_e( 'Remove oEmbed discovery links', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes oEmbed &lt;link&gt; tags, the oEmbed REST endpoint, and the embed.min.js script.', 'easyrankly' ); ?></p>
							</fieldset>

							<fieldset class="erankly-field erankly-checkboxes">
								<legend><strong><?php esc_html_e( 'Scripts', 'easyrankly' ); ?></strong></legend>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_jquery_migrate]" value="1" <?php checked( $settings['bloat_remove_jquery_migrate'], 1 ); ?> data-erankly-bloat-item> <?php esc_html_e( 'Remove jQuery Migrate', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes jQuery Migrate from the frontend. Only disable if your theme and plugins do not rely on deprecated jQuery APIs.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_dashicons]" value="1" <?php checked( $settings['bloat_remove_dashicons'], 1 ); ?> data-erankly-bloat-item> <?php esc_html_e( 'Remove Dashicons for guests', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Deregisters the Dashicons stylesheet for non-logged-in visitors. Logged-in users (e.g. admins with the toolbar) are unaffected.', 'easyrankly' ); ?></p>
							</fieldset>

							<fieldset class="erankly-field erankly-checkboxes">
								<legend><strong><?php esc_html_e( 'Features', 'easyrankly' ); ?></strong></legend>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_disable_self_pingbacks]" value="1" <?php checked( $settings['bloat_disable_self_pingbacks'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <?php esc_html_e( 'Disable self-pingbacks', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Prevents WordPress from sending a pingback to itself when you link to your own posts.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_disable_heartbeat]" value="1" <?php checked( $settings['bloat_disable_heartbeat'], 1 ); ?> data-erankly-bloat-item> <?php esc_html_e( 'Disable Heartbeat on frontend', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Deregisters the WordPress Heartbeat script on the frontend. The Heartbeat API in the admin (autosave, post locking) remains unaffected.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_disable_xmlrpc]" value="1" <?php checked( $settings['bloat_disable_xmlrpc'], 1 ); ?> data-erankly-bloat-item> <?php esc_html_e( 'Disable XML-RPC', 'easyrankly' ); ?></label>
								<p class="description"><?php esc_html_e( 'Disables XML-RPC authenticated methods, removes the pingback endpoints, and strips the X-Pingback header. Leave enabled if you use the WordPress mobile app, Jetpack, or remote publishing tools.', 'easyrankly' ); ?></p>
							</fieldset>

						</div>
					</div>
				</div>

				<div class="erankly-settings-submit" data-erankly-settings-submit <?php echo $show_settings_submit ? '' : 'hidden'; ?>>
					<?php submit_button(); ?>
				</div>
			<?php if ( ! $is_site_admin_on_network ) : ?>
			</form>
			<?php endif; ?>

		<?php if ( is_multisite() && is_network_admin() && $multilingual_enabled && function_exists( 'erankly_ml_render_network_panel' ) ) : ?>
		<div class="erankly-tab-panel<?php echo 'settings-multilingual' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-multilingual" role="tabpanel" aria-labelledby="erankly-settings-tab-multilingual" data-erankly-settings-panel="settings-multilingual" <?php echo 'settings-multilingual' === $active_panel ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Multilingual', 'easyrankly' ); ?></h2>
			<?php erankly_ml_render_network_panel(); ?>
		</div>
		<?php endif; ?>

		<div class="erankly-tab-panel<?php echo 'settings-import-export' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-import-export" role="tabpanel" aria-labelledby="erankly-settings-tab-import-export" data-erankly-settings-panel="settings-import-export" <?php echo 'settings-import-export' === $active_panel ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Import / Export', 'easyrankly' ); ?></h2>
			<?php erankly_import_export_render_panel(); ?>
		</div>

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
		<legend><strong><?php esc_html_e( 'Local business', 'easyrankly' ); ?></strong></legend>
		<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_local_business]" value="1" <?php checked( $enabled ); ?> data-erankly-local-business-toggle> <?php esc_html_e( 'Add LocalBusiness schema for one physical location', 'easyrankly' ); ?></label>
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
				<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][closed]" value="1" <?php checked( $closed ); ?> data-erankly-day-closed> <?php esc_html_e( 'Closed', 'easyrankly' ); ?></label>
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

	$tabs_id            = 'erankly-' . sanitize_key( $setting_key ) . '-tabs';
	$toggle_id          = 'erankly-' . sanitize_key( $setting_key ) . '-linked';
	$toggle_on_label    = $is_taxonomy ? __( 'Taxonomy metadata templates are linked', 'easyrankly' ) : __( 'Post type metadata templates are linked', 'easyrankly' );
	$toggle_off_label   = $is_taxonomy ? __( 'Taxonomy metadata templates are separate', 'easyrankly' ) : __( 'Post type metadata templates are separate', 'easyrankly' );
	$link_action_label  = $is_taxonomy ? __( 'Link taxonomy templates', 'easyrankly' ) : __( 'Link post type templates', 'easyrankly' );
	$split_action_label = $is_taxonomy ? __( 'Separate taxonomy templates', 'easyrankly' ) : __( 'Separate post type templates', 'easyrankly' );
	$linked_panel_label = $is_taxonomy ? __( 'All taxonomies', 'easyrankly' ) : __( 'All post types', 'easyrankly' );
	?>
	<div class="erankly-default-tabs <?php echo $is_linked ? 'is-linked' : ''; ?>" data-erankly-tabs-root data-erankly-linked-defaults>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Default metadata by content type', 'easyrankly' ); ?>">
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
			<button type="button" class="button erankly-linked-defaults-toggle" aria-label="<?php echo esc_attr( $is_linked ? $split_action_label : $link_action_label ); ?>" title="<?php echo esc_attr( $is_linked ? $split_action_label : $link_action_label ); ?>" data-erankly-linked-toggle data-erankly-linked-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-off-label="<?php echo esc_attr( $toggle_off_label ); ?>" data-erankly-linked-action-on-label="<?php echo esc_attr( $split_action_label ); ?>" data-erankly-linked-action-off-label="<?php echo esc_attr( $link_action_label ); ?>">
				<span data-erankly-linked-action><?php echo esc_html( $is_linked ? $split_action_label : $link_action_label ); ?></span>
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
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<h4>
						<span class="erankly-default-entity-label"><?php echo esc_html( $label ); ?></span>
						<span class="erankly-default-linked-label"><?php echo esc_html( $linked_panel_label ); ?></span>
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

	$toggle_on_label    = __( 'Social templates are linked', 'easyrankly' );
	$toggle_off_label   = __( 'Social templates are separate', 'easyrankly' );
	$link_action_label  = __( 'Link social templates', 'easyrankly' );
	$split_action_label = __( 'Separate social templates', 'easyrankly' );
	$linked_panel_label = __( 'Open Graph & X (Twitter)', 'easyrankly' );
	?>
	<div class="erankly-default-tabs <?php echo $is_linked ? 'is-linked' : ''; ?>" data-erankly-tabs-root data-erankly-linked-defaults>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="erankly-social-defaults-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Default social metadata by network', 'easyrankly' ); ?>">
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
			<button type="button" class="button erankly-linked-defaults-toggle" aria-label="<?php echo esc_attr( $is_linked ? $split_action_label : $link_action_label ); ?>" title="<?php echo esc_attr( $is_linked ? $split_action_label : $link_action_label ); ?>" data-erankly-linked-toggle data-erankly-linked-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-off-label="<?php echo esc_attr( $toggle_off_label ); ?>" data-erankly-linked-action-on-label="<?php echo esc_attr( $split_action_label ); ?>" data-erankly-linked-action-off-label="<?php echo esc_attr( $link_action_label ); ?>">
				<span data-erankly-linked-action><?php echo esc_html( $is_linked ? $split_action_label : $link_action_label ); ?></span>
			</button>
			<span class="screen-reader-text" aria-live="polite" data-erankly-linked-status><?php echo esc_html( $is_linked ? $toggle_on_label : $toggle_off_label ); ?></span>
		</div>

		<?php
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
					<h4>
						<span class="erankly-default-entity-label"><?php echo esc_html( $network['label'] ); ?></span>
						<span class="erankly-default-linked-label"><?php echo esc_html( $linked_panel_label ); ?></span>
					</h4>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-title"><strong><?php esc_html_e( 'Default title', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $network['id_prefix'] ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $network['title_key'] ); ?>]" value="<?php echo esc_attr( $title ); ?>" data-erankly-linked-field="title">
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-description"><strong><?php esc_html_e( 'Default description', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $network['id_prefix'] ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $network['description_key'] ); ?>]" data-erankly-linked-field="description"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker(); ?>
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
 * post types and taxonomies, but without the "linked" toggle. Block themes show
 * Site Editor templates first, with WordPress archive contexts separated when a
 * dedicated template is not detected.
 *
 * @param array<string,string> $entities Map of entity key => admin label.
 * @param array<string,mixed>  $settings Current settings.
 * @return void
 */
function erankly_render_special_page_defaults( array $entities, array $settings ): void {
	if ( empty( $entities ) ) {
		return;
	}

	$setting_key = 'global_special_meta';
	$values      = isset( $settings[ $setting_key ] ) && is_array( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : array();
	$statuses    = erankly_get_special_page_template_statuses();
	$is_block    = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	$primary     = $entities;
	$secondary   = array();
	$hidden      = array();

	if ( $is_block ) {
		$primary = array();

		foreach ( $entities as $key => $label ) {
			if ( ! empty( $statuses[ $key ]['has_template'] ) ) {
				$primary[ $key ] = $label;
			}
		}

		foreach ( array( 'author', 'date' ) as $key ) {
			if ( isset( $entities[ $key ] ) && ! isset( $primary[ $key ] ) ) {
				$secondary[ $key ] = $entities[ $key ];
			}
		}

		foreach ( $entities as $key => $label ) {
			if ( ! isset( $primary[ $key ] ) && ! isset( $secondary[ $key ] ) ) {
				$hidden[ $key ] = $label;
			}
		}
	}

	if ( ! empty( $primary ) ) {
		erankly_render_special_page_defaults_group( $primary, $values, $setting_key, 'primary', __( 'Default metadata by detected template', 'easyrankly' ) );
	} elseif ( $is_block ) {
		?>
		<p class="description"><?php esc_html_e( 'No matching Site Editor templates were detected for these SEO defaults.', 'easyrankly' ); ?></p>
		<?php
	}

	if ( ! empty( $secondary ) ) {
		?>
		<div class="erankly-additional-contexts">
			<h4><?php esc_html_e( 'Additional WordPress archive contexts', 'easyrankly' ); ?></h4>
			<p class="description"><?php esc_html_e( 'These contexts can be generated by WordPress even when the block theme does not expose a dedicated Site Editor template.', 'easyrankly' ); ?></p>
			<?php erankly_render_special_page_defaults_group( $secondary, $values, $setting_key, 'archive-contexts', __( 'Default metadata by WordPress archive context', 'easyrankly' ) ); ?>
		</div>
		<?php
	}

	if ( ! empty( $hidden ) ) {
		erankly_render_hidden_special_page_defaults( $hidden, $values, $setting_key );
	}
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
	$tabs_id = 'erankly-' . sanitize_key( $setting_key . '-' . $group_key ) . '-tabs';
	?>
	<div class="erankly-default-tabs" data-erankly-tabs-root>
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
 * Preserves settings for special page contexts that are not shown in block theme UI.
 *
 * @param array<string,string> $entities    Map of entity key => admin label.
 * @param array<string,mixed>  $values      Current settings for the group.
 * @param string               $setting_key Settings array key.
 * @return void
 */
function erankly_render_hidden_special_page_defaults( array $entities, array $values, string $setting_key ): void {
	?>
	<div hidden>
		<?php
		foreach ( array_keys( $entities ) as $key ) :
			$row             = isset( $values[ $key ] ) && is_array( $values[ $key ] ) ? $values[ $key ] : array();
			$title           = isset( $row['title'] ) ? (string) $row['title'] : '';
			$description     = isset( $row['description'] ) ? (string) $row['description'] : '';
			$noindex         = ! empty( $row['noindex'] ) ? '1' : '0';
			$nofollow        = ! empty( $row['nofollow'] ) ? '1' : '0';
			$noarchive       = ! empty( $row['noarchive'] ) ? '1' : '0';
			$disable_sitemap = ! empty( $row['disable_sitemap'] ) ? '1' : '0';
			$name_prefix     = ERANKLY_OPTION . '[' . $setting_key . '][' . (string) $key . ']';
			?>
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[title]" value="<?php echo esc_attr( $title ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[description]" value="<?php echo esc_attr( $description ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[noindex]" value="<?php echo esc_attr( $noindex ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="<?php echo esc_attr( $nofollow ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="<?php echo esc_attr( $noarchive ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[disable_sitemap]" value="<?php echo esc_attr( $disable_sitemap ); ?>">
			<?php
		endforeach;
		?>
	</div>
	<?php
}

/**
 * Renders global visibility defaults.
 *
 * @param string $setting_key     Settings array key.
 * @param string $entity_key      Entity key.
 * @param bool   $noindex         Noindex default.
 * @param bool   $nofollow        Nofollow default.
 * @param bool   $noarchive       Noarchive default.
 * @param bool   $disable_sitemap Disable sitemap default.
 * @return void
 */
function erankly_render_global_visibility_defaults( string $setting_key, string $entity_key, bool $noindex, bool $nofollow, bool $noarchive, bool $disable_sitemap ): void {
	$name_prefix = ERANKLY_OPTION . '[' . $setting_key . '][' . $entity_key . ']';
	$is_simple   = (bool) erankly_get_setting( 'simplified_mode', 1 );
	$is_hidden   = $noindex && $disable_sitemap;
	?>
	<fieldset class="erankly-field erankly-checkboxes erankly-visibility-defaults">
		<legend><strong><?php esc_html_e( 'Visibility defaults', 'easyrankly' ); ?></strong></legend>
		<div class="erankly-checkbox-options">
			<?php if ( $is_simple ) : ?>
				<label><input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[hide_from_search_results]" value="1" <?php checked( $is_hidden ); ?>> <?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></label>
				<?php // The simplified control only drives noindex + disable_sitemap; carry the advanced-only directives through so saving in simplified mode never wipes them. ?>
				<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="<?php echo $nofollow ? '1' : '0'; ?>">
				<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="<?php echo $noarchive ? '1' : '0'; ?>">
			<?php else : ?>
				<label><input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[noindex]" value="1" <?php checked( $noindex ); ?>> <?php esc_html_e( 'Noindex', 'easyrankly' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="1" <?php checked( $nofollow ); ?>> <?php esc_html_e( 'Nofollow', 'easyrankly' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="1" <?php checked( $noarchive ); ?>> <?php esc_html_e( 'Noarchive', 'easyrankly' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[disable_sitemap]" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
			<?php endif; ?>
		</div>
	</fieldset>
	<?php
}
