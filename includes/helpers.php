<?php
/**
 * Shared helpers.
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
 * Returns the supported special page / archive entity keys.
 *
 * Keys only, with no translated labels, so this is safe to call before the
 * `init` action (for example while assembling default settings on
 * `plugins_loaded`) without triggering just-in-time translation loading. Use
 * erankly_special_page_keys() when human-readable labels are required.
 *
 * @return array<int,string> List of entity keys.
 */
function erankly_special_page_keys_list(): array {
	/**
	 * Filters the supported special page entity keys.
	 *
	 * @param array<int,string> $keys List of entity keys.
	 */
	return array_values(
		array_filter(
			(array) apply_filters(
				'erankly_special_pages',
				array( 'homepage', 'blog', 'author', 'date', 'search', '404' )
			),
			'is_string'
		)
	);
}

/**
 * Returns the supported special page / archive entities keyed by slug.
 *
 * These are singleton page types (homepage, blog page, archives, search, 404)
 * that share the same metadata structure as post types and taxonomies but have
 * a single configuration each. The labels are translated, so this must only run
 * on or after the `init` action (i.e. for admin display, never while building
 * default settings). Use erankly_special_page_keys_list() for the keys alone.
 *
 * @return array<string,string> Map of entity key => admin label.
 */
function erankly_special_page_keys(): array {
	$labels = array(
		'homepage' => __( 'Homepage', 'easyrankly' ),
		'blog'     => __( 'Blog page', 'easyrankly' ),
		'author'   => __( 'Author archive', 'easyrankly' ),
		'date'     => __( 'Date archive', 'easyrankly' ),
		'search'   => __( 'Search results', 'easyrankly' ),
		'404'      => __( '404 page', 'easyrankly' ),
	);

	$keys = array();

	foreach ( erankly_special_page_keys_list() as $key ) {
		$keys[ $key ] = $labels[ $key ] ?? $key;
	}

	return $keys;
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
 * to hidden; author and date archives default to visible. "Hidden" mirrors the
 * plugin's unified convention (noindex + nofollow + noarchive + disable_sitemap)
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

	foreach ( erankly_special_page_keys_list() as $key ) {
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
 * Returns template status details for each special page entity.
 *
 * Block themes are scanned through get_block_templates(), matching the Site
 * Editor template list. Classic themes use the standard PHP template hierarchy
 * and distinguish dedicated files from archive/index fallbacks.
 *
 * @return array<string,array<string,string|bool>> Map of entity key => template status.
 */
function erankly_get_special_page_template_statuses(): array {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$dedicated_map = array(
		'homepage' => array( 'front-page', 'home' ),
		'blog'     => array( 'home' ),
		'author'   => array( 'author' ),
		'date'     => array( 'date' ),
		'search'   => array( 'search' ),
		'404'      => array( '404' ),
	);
	$fallback_map  = array(
		'homepage' => array( 'index' ),
		'blog'     => array( 'index' ),
		'author'   => array( 'archive', 'index' ),
		'date'     => array( 'archive', 'index' ),
		'search'   => array( 'index' ),
		'404'      => array( 'index' ),
	);
	$is_block      = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	$statuses      = array();

	foreach ( erankly_special_page_keys_list() as $key ) {
		$statuses[ $key ] = array(
			'template'       => '',
			'fallback'       => '',
			'has_template'   => false,
			'has_fallback'   => false,
			'is_block_theme' => $is_block,
		);
	}

	if ( $is_block ) {
		$slugs     = array();
		$templates = function_exists( 'get_block_templates' ) ? get_block_templates( array(), 'wp_template' ) : array();

		foreach ( $templates as $template ) {
			if ( isset( $template->slug ) && is_string( $template->slug ) ) {
				$slugs[ $template->slug ] = true;
			}
		}

		foreach ( array_keys( $statuses ) as $key ) {
			foreach ( $dedicated_map[ $key ] ?? array() as $candidate ) {
				if ( isset( $slugs[ $candidate ] ) ) {
					$statuses[ $key ]['template']     = $candidate . '.html';
					$statuses[ $key ]['has_template'] = true;
					break;
				}
			}

			if ( ! empty( $statuses[ $key ]['has_template'] ) ) {
				continue;
			}

			foreach ( $fallback_map[ $key ] ?? array() as $candidate ) {
				if ( isset( $slugs[ $candidate ] ) ) {
					$statuses[ $key ]['fallback']     = $candidate . '.html';
					$statuses[ $key ]['has_fallback'] = true;
					break;
				}
			}
		}
	} else {
		foreach ( array_keys( $statuses ) as $key ) {
			foreach ( $dedicated_map[ $key ] ?? array() as $candidate ) {
				$file = $candidate . '.php';

				if ( '' !== locate_template( array( $file ) ) ) {
					$statuses[ $key ]['template']     = $file;
					$statuses[ $key ]['has_template'] = true;
					break;
				}
			}

			if ( ! empty( $statuses[ $key ]['has_template'] ) ) {
				continue;
			}

			foreach ( $fallback_map[ $key ] ?? array() as $candidate ) {
				$file = $candidate . '.php';

				if ( '' !== locate_template( array( $file ) ) ) {
					$statuses[ $key ]['fallback']     = $file;
					$statuses[ $key ]['has_fallback'] = true;
					break;
				}
			}
		}
	}

	$cache = $statuses;

	return $cache;
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
		'global_special_meta_linked'     => 0,
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
		'enable_seo_checklist'           => 1,
		'enable_sitemap'                 => 0,
		'enable_health'                  => 0,
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
		'enable_multilingual'            => 0,
		'enable_redirects'               => 0,
		'redirect_exclude_admins'        => 0,
		'hide_head_credit'               => 0,
		'bloat_remove_emoji'             => 0,
		'bloat_remove_generator'         => 0,
		'bloat_remove_feed_links'        => 0,
		'bloat_remove_rsd_link'          => 0,
		'bloat_remove_wlwmanifest'       => 0,
		'bloat_remove_shortlink'         => 0,
		'bloat_remove_rest_link'         => 0,
		'bloat_remove_oembed'            => 0,
		'bloat_remove_jquery_migrate'    => 0,
		'bloat_disable_self_pingbacks'   => 0,
		'bloat_remove_dashicons'         => 0,
		'bloat_disable_heartbeat'        => 0,
		'bloat_disable_xmlrpc'           => 0,
	);
}

/**
 * Resolves a sitemap URL, using query parameters if permalinks are disabled.
 *
 * @param string $path The root-relative sitemap path (e.g. '/wp-sitemap.xml').
 * @return string The resolved absolute URL.
 */
function erankly_get_sitemap_url( string $path ): string {
	if ( (bool) get_option( 'permalink_structure' ) ) {
		return home_url( '/' . ltrim( $path, '/' ) );
	}

	if ( '/wp-sitemap.xml' === $path ) {
		return home_url( '/?sitemap=index' );
	}

	if ( preg_match( '/^\/sitemap-(image|video|news)-([0-9]+)\.xml$/', $path, $matches ) ) {
		return home_url( '/?erankly_sitemap=' . $matches[1] . '&erankly_sitemap_page=' . $matches[2] );
	}

	return home_url( '/' . ltrim( $path, '/' ) );
}

/**
 * Returns the URL of the core wp_sitemaps XSL stylesheet.
 *
 * The specialist sitemaps reuse the native stylesheet so their browser view is
 * visually identical to the core /wp-sitemap.xml pages.
 *
 * @return string
 */
function erankly_get_sitemap_stylesheet_url(): string {
	return wp_sitemaps_get_server()->renderer->get_sitemap_stylesheet_url();
}

/**
 * Determines whether the sitemap feature is enabled.
 *
 * @return bool
 */
function erankly_sitemap_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_sitemap', 0 );
}

/**
 * Determines whether the Health feature is enabled.
 *
 * @return bool
 */
function erankly_health_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_health', 0 );
}

/**
 * Clears sitemap transients.
 *
 * @param mixed ...$hook_args Hook arguments (not used, hook may pass any number of args).
 * @return void
 */
function erankly_flush_sitemap_cache( mixed ...$hook_args ): void {
	unset( $hook_args );
	static $flushed_sites = array();

	$site_id = get_current_blog_id();

	if ( isset( $flushed_sites[ $site_id ] ) ) {
		return;
	}

	$flushed_sites[ $site_id ] = true;
	$version                   = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

	update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, max( 1, $version + 1 ), false );
}

/**
 * Invalidates sitemap caches after a meaningful post save.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function erankly_flush_sitemap_cache_for_post( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	erankly_flush_sitemap_cache();
}

/**
 * Invalidates sitemap caches after a post deletion.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function erankly_flush_sitemap_cache_for_deleted_post( int $post_id ): void {
	if ( $post_id > 0 ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Invalidates sitemap caches after a publication status transition.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Previous status.
 * @param WP_Post $post       Post object.
 * @return void
 */
function erankly_flush_sitemap_cache_for_status( string $new_status, string $old_status, WP_Post $post ): void {
	if ( $new_status === $old_status || wp_is_post_revision( $post->ID ) ) {
		return;
	}

	if ( 'publish' === $new_status || 'publish' === $old_status ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Invalidates sitemap caches only for EasyRankly term metadata.
 *
 * @param mixed  $meta_id  Meta row ID or deleted row IDs.
 * @param int    $term_id  Term ID.
 * @param string $meta_key Meta key.
 * @return void
 */
function erankly_flush_sitemap_cache_for_term_meta( mixed $meta_id, int $term_id, string $meta_key ): void {
	unset( $meta_id, $term_id );

	if ( str_starts_with( $meta_key, '_erankly_' ) ) {
		erankly_flush_sitemap_cache();
	}
}

/**
 * Returns a versioned sitemap transient key.
 *
 * Versioning makes invalidation a constant-time option update. Older transient
 * rows expire naturally instead of being deleted with a wildcard SQL query.
 *
 * @param string $suffix Cache key suffix.
 * @return string
 */
function erankly_get_sitemap_cache_key( string $suffix ): string {
	static $version = null;

	if ( null === $version ) {
		$version = max( 1, (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 ) );
	}

	return ERANKLY_SITEMAP_TRANSIENT_PREFIX . $version . '_' . sanitize_key( $suffix );
}


/**
 * Returns merged settings.
 *
 * @return array<string,mixed>
 */
function erankly_get_settings(): array {
	if ( isset( $GLOBALS['erankly_settings_cache'] ) && is_array( $GLOBALS['erankly_settings_cache'] ) ) {
		return $GLOBALS['erankly_settings_cache'];
	}

	$settings = is_multisite()
		? get_site_option( ERANKLY_OPTION, array() )
		: get_option( ERANKLY_OPTION, array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$GLOBALS['erankly_settings_cache'] = wp_parse_args( $settings, erankly_default_settings() );

	return $GLOBALS['erankly_settings_cache'];
}

/**
 * Clears the request-level settings cache after settings change.
 *
 * @return void
 */
function erankly_clear_settings_cache(): void {
	unset( $GLOBALS['erankly_settings_cache'] );
}

/**
 * Returns whether at least one bloat-removal feature is enabled.
 *
 * @return bool
 */
function erankly_bloat_enabled(): bool {
	$settings = erankly_get_settings();
	$keys     = array(
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
	);

	foreach ( $keys as $key ) {
		if ( ! empty( $settings[ $key ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Reads a single setting.
 *
 * @param string $key           Setting key.
 * @param mixed  $default_value Default value.
 * @return mixed
 */
function erankly_get_setting( string $key, mixed $default_value = null ): mixed {
	$settings = erankly_get_settings();

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default_value;
}

/**
 * Sanitizes a plain text field.
 *
 * Expects an already-unslashed value: callers reading from $_POST must
 * wp_unslash() first. Unslashing here too would corrupt literal backslashes.
 *
 * @param mixed $value Raw (unslashed) value.
 * @return string
 */
function erankly_sanitize_text( mixed $value ): string {
	return sanitize_text_field( (string) $value );
}

/**
 * Sanitizes textarea text without markup.
 *
 * Expects an already-unslashed value (see erankly_sanitize_text()).
 *
 * @param mixed $value Raw (unslashed) value.
 * @return string
 */
function erankly_sanitize_textarea( mixed $value ): string {
	return sanitize_textarea_field( (string) $value );
}

/**
 * Normalizes an X/Twitter handle.
 *
 * @param mixed $value Raw handle or profile URL.
 * @return string
 */
function erankly_sanitize_twitter_handle( mixed $value ): string {
	$value = trim( erankly_sanitize_text( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( preg_match( '#^(?:https?://)?(?:www\.)?(?:x|twitter)\.com/#i', $value ) ) {
		$url   = str_starts_with( $value, 'http' ) ? $value : 'https://' . $value;
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$value = (string) strtok( trim( $path, '/' ), '/' );
	}

	$handle = ltrim( $value, '@' );
	$handle = preg_replace( '/[^A-Za-z0-9_]/', '', $handle );
	$handle = substr( (string) $handle, 0, 15 );

	return '' === $handle ? '' : '@' . $handle;
}

/**
 * Sanitizes a URL field.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_url( mixed $value ): string {
	$value = trim( (string) $value );

	return '' === $value ? '' : esc_url_raw( $value );
}

/**
 * Returns supported LocalBusiness schema types.
 *
 * @return array<string,string>
 */
function erankly_get_local_business_types(): array {
	$types = array(
		'LocalBusiness'     => __( 'Local business', 'easyrankly' ),
		'Store'             => __( 'Store', 'easyrankly' ),
		'Restaurant'        => __( 'Restaurant', 'easyrankly' ),
		'CafeOrCoffeeShop'  => __( 'Cafe or coffee shop', 'easyrankly' ),
		'BarOrPub'          => __( 'Bar or pub', 'easyrankly' ),
		'Bakery'            => __( 'Bakery', 'easyrankly' ),
		'FoodEstablishment' => __( 'Food establishment', 'easyrankly' ),
		'Dentist'           => __( 'Dentist', 'easyrankly' ),
		'MedicalClinic'     => __( 'Medical clinic', 'easyrankly' ),
		'Pharmacy'          => __( 'Pharmacy', 'easyrankly' ),
		'LegalService'      => __( 'Legal service', 'easyrankly' ),
		'RealEstateAgent'   => __( 'Real estate agent', 'easyrankly' ),
		'AutoRepair'        => __( 'Auto repair', 'easyrankly' ),
		'BeautySalon'       => __( 'Beauty salon', 'easyrankly' ),
		'HairSalon'         => __( 'Hair salon', 'easyrankly' ),
		'NailSalon'         => __( 'Nail salon', 'easyrankly' ),
		'HealthClub'        => __( 'Health club', 'easyrankly' ),
		'Hotel'             => __( 'Hotel', 'easyrankly' ),
		'BedAndBreakfast'   => __( 'Bed and breakfast', 'easyrankly' ),
		'LodgingBusiness'   => __( 'Lodging business', 'easyrankly' ),
	);

	/**
	 * Filters supported LocalBusiness schema types.
	 *
	 * @param array<string,string> $types LocalBusiness types.
	 */
	$types = apply_filters( 'erankly_local_business_types', $types );

	if ( ! is_array( $types ) ) {
		return array();
	}

	$valid_types = array();

	foreach ( $types as $type => $label ) {
		if ( is_string( $type ) && is_string( $label ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $type ) ) {
			$valid_types[ $type ] = $label;
		}
	}

	return $valid_types;
}

/**
 * Returns whether a LocalBusiness type supports food-specific properties.
 *
 * @param string $type Schema.org type.
 * @return bool
 */
function erankly_is_food_business_type( string $type ): bool {
	return in_array( $type, array( 'Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'FoodEstablishment' ), true );
}

/**
 * Sanitizes a relative site path.
 *
 * @param mixed $value Raw path.
 * @return string
 */
function erankly_sanitize_relative_path( mixed $value ): string {
	$value = trim( (string) $value );

	if ( '' === $value || str_starts_with( $value, '//' ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $value ) ) {
		return '';
	}

	$path = wp_parse_url( $value, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( sanitize_text_field( $path ), '/' );

	return '/' === $path ? '/' : trailingslashit( $path );
}

/**
 * Sanitizes a business telephone number.
 *
 * @param mixed $value Raw telephone number.
 * @return string
 */
function erankly_sanitize_phone( mixed $value ): string {
	$value  = erankly_sanitize_text( $value );
	$value  = preg_replace( '/[^0-9+().\-\s]/', '', $value );
	$digits = is_string( $value ) ? preg_replace( '/\D/', '', $value ) : '';

	if ( ! is_string( $value ) || ! is_string( $digits ) || strlen( $digits ) < 5 ) {
		return '';
	}

	return trim( $value );
}

/**
 * Sanitizes an ISO 3166-1 alpha-2 country code.
 *
 * @param mixed $value Raw country code.
 * @return string
 */
function erankly_sanitize_country_code( mixed $value ): string {
	$value = strtoupper( erankly_sanitize_text( $value ) );

	return 1 === preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
}

/**
 * Sanitizes a geographic coordinate.
 *
 * @param mixed $value   Raw coordinate.
 * @param float $minimum Minimum value.
 * @param float $maximum Maximum value.
 * @return string
 */
function erankly_sanitize_coordinate( mixed $value, float $minimum, float $maximum ): string {
	$value = trim( (string) $value );

	if ( '' === $value || ! is_numeric( $value ) ) {
		return '';
	}

	$number = (float) $value;

	if ( $number < $minimum || $number > $maximum ) {
		return '';
	}

	return rtrim( rtrim( number_format( $number, 6, '.', '' ), '0' ), '.' );
}

/**
 * Sanitizes a 24-hour time value.
 *
 * @param mixed $value Raw time.
 * @return string
 */
function erankly_sanitize_time( mixed $value ): string {
	$value = erankly_sanitize_text( $value );

	return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
}

/**
 * Returns empty weekly opening hours.
 *
 * @return array<string,array{closed:int,intervals:array<int,array{opens:string,closes:string}>}>
 */
function erankly_default_opening_hours(): array {
	$hours = array();

	foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
		$hours[ $day ] = array(
			'closed'    => 0,
			'intervals' => array(
				array(
					'opens'  => '',
					'closes' => '',
				),
				array(
					'opens'  => '',
					'closes' => '',
				),
			),
		);
	}

	return $hours;
}

/**
 * Sanitizes weekly LocalBusiness opening hours.
 *
 * @param mixed $value Raw hours.
 * @return array<string,array{closed:int,intervals:array<int,array{opens:string,closes:string}>}>
 */
function erankly_sanitize_opening_hours( mixed $value ): array {
	$value = is_array( $value ) ? $value : array();
	$hours = erankly_default_opening_hours();

	foreach ( array_keys( $hours ) as $day ) {
		$raw_day                 = isset( $value[ $day ] ) && is_array( $value[ $day ] ) ? $value[ $day ] : array();
		$hours[ $day ]['closed'] = ! empty( $raw_day['closed'] ) ? 1 : 0;
		$raw_intervals           = isset( $raw_day['intervals'] ) && is_array( $raw_day['intervals'] ) ? $raw_day['intervals'] : array();

		foreach ( array( 0, 1 ) as $index ) {
			$raw_interval = isset( $raw_intervals[ $index ] ) && is_array( $raw_intervals[ $index ] ) ? $raw_intervals[ $index ] : array();
			$opens        = isset( $raw_interval['opens'] ) ? erankly_sanitize_time( $raw_interval['opens'] ) : '';
			$closes       = isset( $raw_interval['closes'] ) ? erankly_sanitize_time( $raw_interval['closes'] ) : '';

			if ( '' === $opens || '' === $closes ) {
				$opens  = '';
				$closes = '';
			}

			$hours[ $day ]['intervals'][ $index ] = array(
				'opens'  => $opens,
				'closes' => $closes,
			);
		}
	}

	return $hours;
}

/**
 * Produces a compact SEO string.
 *
 * @param string $value Raw string.
 * @param int    $limit Character limit.
 * @return string
 */
function erankly_trim_text( string $value, int $limit = 160 ): string {
	$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );

	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

	if ( '' === $value || $length <= $limit ) {
		return $value;
	}

	$excerpt = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit - 1 ) : substr( $value, 0, $limit - 1 );

	return rtrim( $excerpt, " \t\n\r\0\x0B.,;:-" );
}

/**
 * Produces a compact SEO string without applying a character limit.
 *
 * @param string $value Raw string.
 * @return string
 */
function erankly_normalize_seo_text( string $value ): string {
	$value = preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $value ) ) );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Returns valid image URLs embedded in post content, in document order.
 *
 * Images inside code examples are ignored. The raw block markup fallback covers
 * image URLs stored in Gutenberg attributes when no rendered img tag is present.
 *
 * @param int $post_id Post ID.
 * @return array<int,string>
 */
function erankly_get_post_content_image_urls( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || '' === $post->post_content ) {
		return array();
	}

	$content = (string) preg_replace( '#<(pre|code)[^>]*>.*?</\1>#is', '', $post->post_content );
	$content = is_string( $content ) ? $content : $post->post_content;
	$images  = array();

	if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $content_matches ) ) {
		foreach ( $content_matches[1] as $src ) {
			$src = esc_url_raw( (string) $src );

			if ( erankly_is_absolute_http_url( $src ) ) {
				$images[] = $src;
			}
		}
	}

	if ( preg_match_all( '/"(?:url|link|href)":\s*"(https?:\/\/[^"]+\.(?:jpg|jpeg|png|gif|webp|avif|svg|bmp)[^"]*)"/', $content, $block_matches, PREG_SET_ORDER ) ) {
		foreach ( $block_matches as $match ) {
			$src = esc_url_raw( $match[1] );

			if ( erankly_is_absolute_http_url( $src ) ) {
				$images[] = $src;
			}
		}
	}

	return array_values( array_unique( $images ) );
}

/**
 * Returns whether a URL is an absolute HTTP(S) URL.
 *
 * @param string $url URL.
 * @return bool
 */
function erankly_is_absolute_http_url( string $url ): bool {
	$url = esc_url_raw( trim( $url ) );

	if ( '' === $url ) {
		return false;
	}

	$parts = wp_parse_url( $url );

	return is_array( $parts ) && ! empty( $parts['host'] ) && ! empty( $parts['scheme'] ) && in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
}

/**
 * Returns a post meta string.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key without plugin prefix.
 * @return string
 */
function erankly_get_post_meta_string( int $post_id, string $key ): string {
	$value = get_post_meta( $post_id, '_erankly_' . $key, true );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Returns a post meta boolean.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key without plugin prefix.
 * @return bool
 */
function erankly_get_post_meta_bool( int $post_id, string $key ): bool {
	return '1' === (string) get_post_meta( $post_id, '_erankly_' . $key, true );
}

/**
 * Returns a term meta string.
 *
 * @param int    $term_id Term ID.
 * @param string $key     Meta key without plugin prefix.
 * @return string
 */
function erankly_get_term_meta_string( int $term_id, string $key ): string {
	$value = get_term_meta( $term_id, '_erankly_' . $key, true );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Returns a term meta boolean.
 *
 * @param int    $term_id Term ID.
 * @param string $key     Meta key without plugin prefix.
 * @return bool
 */
function erankly_get_term_meta_bool( int $term_id, string $key ): bool {
	return '1' === (string) get_term_meta( $term_id, '_erankly_' . $key, true );
}

/**
 * Returns a global metadata template for a post type.
 *
 * @param string $post_type Post type name.
 * @param string $field     Template field.
 * @return string
 */
function erankly_get_global_post_type_meta( string $post_type, string $field ): string {
	return erankly_get_global_entity_meta( 'global_post_type_meta', $post_type, $field );
}

/**
 * Returns a global metadata template for a taxonomy.
 *
 * @param string $taxonomy Taxonomy name.
 * @param string $field    Template field.
 * @return string
 */
function erankly_get_global_taxonomy_meta( string $taxonomy, string $field ): string {
	return erankly_get_global_entity_meta( 'global_taxonomy_meta', $taxonomy, $field );
}

/**
 * Returns a global robots/sitemap directive for a post type.
 *
 * @param string $post_type Post type name.
 * @param string $field     Directive field.
 * @return bool
 */
function erankly_get_global_post_type_directive( string $post_type, string $field ): bool {
	return erankly_get_global_entity_directive( 'global_post_type_meta', $post_type, $field );
}

/**
 * Returns a global robots/sitemap directive for a taxonomy.
 *
 * @param string $taxonomy Taxonomy name.
 * @param string $field    Directive field.
 * @return bool
 */
function erankly_get_global_taxonomy_directive( string $taxonomy, string $field ): bool {
	return erankly_get_global_entity_directive( 'global_taxonomy_meta', $taxonomy, $field );
}

/**
 * Returns a global metadata template field for a keyed entity.
 *
 * @param string $setting_key Settings array key.
 * @param string $entity      Entity name.
 * @param string $field       Template field.
 * @return string
 */
function erankly_get_global_entity_meta( string $setting_key, string $entity, string $field ): string {
	$templates = erankly_get_setting( $setting_key, array() );

	if ( ! is_array( $templates ) ) {
		return '';
	}

	if ( isset( $templates[ $entity ] ) && is_array( $templates[ $entity ] ) ) {
		$value = $templates[ $entity ][ $field ] ?? '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	if ( ! erankly_get_setting( $setting_key . '_linked', 1 ) ) {
		return '';
	}

	foreach ( $templates as $template ) {
		if ( ! is_array( $template ) ) {
			continue;
		}

		$value = $template[ $field ] ?? '';

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}
	}

	return '';
}

/**
 * Returns a boolean global directive for a keyed entity.
 *
 * @param string $setting_key Settings array key.
 * @param string $entity      Entity name.
 * @param string $field       Directive field.
 * @return bool
 */
function erankly_get_global_entity_directive( string $setting_key, string $entity, string $field ): bool {
	if ( ! in_array( $field, array( 'noindex', 'nofollow', 'noarchive', 'disable_sitemap' ), true ) ) {
		return false;
	}

	$templates = erankly_get_setting( $setting_key, array() );

	if ( ! is_array( $templates ) ) {
		return false;
	}

	if ( isset( $templates[ $entity ] ) && is_array( $templates[ $entity ] ) ) {
		return ! empty( $templates[ $entity ][ $field ] );
	}

	if ( ! erankly_get_setting( $setting_key . '_linked', 1 ) ) {
		return false;
	}

	foreach ( $templates as $template ) {
		if ( is_array( $template ) && ! empty( $template[ $field ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Replaces dynamic template variables in a text value.
 *
 * @param string            $value   Raw text value.
 * @param int               $post_id Optional post ID.
 * @param array<int,string> $exclude Variables that should not resolve for this call.
 * @return string
 */
function erankly_replace_variables( string $value, int $post_id = 0, array $exclude = array() ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	if ( $post_id <= 0 && is_singular() ) {
		$post_id = get_queried_object_id();
	}

	$exclude = array_fill_keys( array_map( 'strtolower', $exclude ), true );

	return (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $post_id, $exclude ): string {
			$key = strtolower( (string) $matches[1] );

			if ( isset( $exclude[ $key ] ) ) {
				return '';
			}

			return erankly_get_variable_value( $key, $post_id );
		},
		$value
	);
}

/**
 * Replaces dynamic variables inside a JSON-LD string.
 *
 * @param string $value   Raw JSON-LD.
 * @param int    $post_id Optional post ID.
 * @return string
 */
function erankly_replace_json_ld_variables( string $value, int $post_id = 0 ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	if ( $post_id <= 0 && is_singular() ) {
		$post_id = get_queried_object_id();
	}

	return (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $post_id ): string {
			$replacement = erankly_get_variable_value( strtolower( (string) $matches[1] ), $post_id );
			$json        = wp_json_encode( $replacement, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( ! is_string( $json ) ) {
				return '';
			}

			return '"' === $json[0] ? substr( $json, 1, -1 ) : $json;
		},
		$value
	);
}

/**
 * Returns the value for a supported dynamic variable.
 *
 * @param string $key     Variable key.
 * @param int    $post_id Optional post ID.
 * @return string
 */
function erankly_get_variable_value( string $key, int $post_id = 0 ): string {
	static $resolving = array();
	static $cache     = array();

	$cache_key = $key . ':' . $post_id;

	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	if ( isset( $resolving[ $key ] ) ) {
		return '';
	}

	$resolving[ $key ] = true;
	$post              = $post_id > 0 ? get_post( $post_id ) : null;
	$queried           = get_queried_object();
	$value             = '';

	switch ( $key ) {
		case 'post_title':
			$value = $post_id > 0 ? get_the_title( $post_id ) : '';
			break;
		case 'post_excerpt':
			if ( $post instanceof WP_Post ) {
				$value = has_excerpt( $post ) ? get_the_excerpt( $post ) : erankly_trim_text( $post->post_content, 160 );
			}
			break;
		case 'post_content':
			$value = $post instanceof WP_Post ? wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) : '';
			break;
		case 'post_url':
			$value = $post_id > 0 ? (string) get_permalink( $post_id ) : '';
			break;
		case 'post_date':
			$value = $post_id > 0 ? get_the_date( DATE_W3C, $post_id ) : '';
			break;
		case 'post_modified_date':
			$value = $post_id > 0 ? get_the_modified_date( DATE_W3C, $post_id ) : '';
			break;
		case 'post_author':
			if ( $post instanceof WP_Post ) {
				$value = get_the_author_meta( 'display_name', (int) $post->post_author );
			}
			break;
		case 'post_categories':
			$value = erankly_get_post_category_names( $post_id );
			break;
		case 'seo_title':
			$value = function_exists( 'erankly_get_title' ) ? erankly_get_title() : '';
			break;
		case 'meta_description':
			$value = function_exists( 'erankly_get_description' ) ? erankly_get_description() : '';
			break;
		case 'canonical_url':
			$value = function_exists( 'erankly_get_canonical' ) ? erankly_get_canonical() : '';
			break;
		case 'featured_image':
			$image = $post_id > 0 ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
			$value = is_string( $image ) ? $image : '';
			break;
		case 'post_type_name':
			if ( $post instanceof WP_Post ) {
				$post_type = get_post_type_object( $post->post_type );
				$value     = $post_type instanceof WP_Post_Type ? $post_type->labels->singular_name : $post->post_type;
			} elseif ( $queried instanceof WP_Post_Type ) {
				$value = $queried->labels->singular_name;
			}
			break;
		case 'term_name':
			$value = $queried instanceof WP_Term ? $queried->name : '';
			break;
		case 'term_description':
			$value = $queried instanceof WP_Term ? $queried->description : '';
			break;
		case 'term_slug':
			$value = $queried instanceof WP_Term ? $queried->slug : '';
			break;
		case 'term_url':
			if ( $queried instanceof WP_Term ) {
				$term_link = get_term_link( $queried );
				$value     = is_wp_error( $term_link ) ? '' : $term_link;
			}
			break;
		case 'taxonomy_name':
			if ( $queried instanceof WP_Term ) {
				$taxonomy = get_taxonomy( $queried->taxonomy );
				$value    = $taxonomy instanceof WP_Taxonomy ? $taxonomy->labels->singular_name : $queried->taxonomy;
			}
			break;
		case 'search_query':
			$value = is_search() ? get_search_query() : '';
			break;
		case 'page_number':
			$paged = max( (int) get_query_var( 'paged', 0 ), (int) get_query_var( 'page', 0 ) );
			$value = (string) max( 1, $paged );
			break;
		case 'max_pages':
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core WP global, read-only.
			$value = isset( $GLOBALS['wp_query'] ) ? (string) max( 1, (int) $GLOBALS['wp_query']->max_num_pages ) : '1';
			break;
		case 'site_name':
			$value = get_bloginfo( 'name' );
			break;
		case 'site_description':
			$value = get_bloginfo( 'description' );
			break;
		case 'site_url':
			$value = home_url( '/' );
			break;
		case 'site_language':
			$value = get_bloginfo( 'language' );
			break;
		case 'organization_name':
			$value = erankly_get_organization_name();
			break;
		case 'organization_logo':
		case 'organization_logo_url':
			$value = erankly_get_organization_logo_url();
			break;
		case 'site_icon':
		case 'site_icon_url':
			$value = erankly_get_site_icon_url();
			break;
		case 'schema_identity_id':
			$value = function_exists( 'erankly_schema_identity_id' ) ? erankly_schema_identity_id() : home_url( '/#organization' );
			break;
	}

	unset( $resolving[ $key ] );

	$cache[ $cache_key ] = trim( wp_strip_all_tags( (string) $value ) );

	return $cache[ $cache_key ];
}

/**
 * Returns comma-separated category names for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_get_post_category_names( int $post_id ): string {
	if ( $post_id <= 0 ) {
		return '';
	}

	$categories = get_the_category( $post_id );

	if ( empty( $categories ) || is_wp_error( $categories ) ) {
		return '';
	}

	$names = wp_list_pluck( $categories, 'name' );

	return implode( ', ', array_map( 'sanitize_text_field', $names ) );
}

/**
 * Returns public post types supported by EasyRankly.
 *
 * @return array<string,WP_Post_Type>
 */
function erankly_get_public_post_types(): array {
	$post_types = get_post_types(
		array(
			'public' => true,
		),
		'objects'
	);

	unset( $post_types['attachment'] );

	/**
	 * Filters public post types handled by the plugin.
	 *
	 * @param array<string,WP_Post_Type> $post_types Public post type objects.
	 */
	return apply_filters( 'erankly_post_types', $post_types );
}

/**
 * Returns public taxonomies supported by EasyRankly.
 *
 * @return array<string,WP_Taxonomy>
 */
function erankly_get_public_taxonomies(): array {
	$taxonomies = get_taxonomies(
		array(
			'public' => true,
		),
		'objects'
	);

	unset(
		$taxonomies['post_format'],
		$taxonomies['product_shipping_class']
	);

	/**
	 * Filters public taxonomies handled by the plugin.
	 *
	 * @param array<string,WP_Taxonomy> $taxonomies Public taxonomy objects.
	 */
	return apply_filters( 'erankly_taxonomies', $taxonomies );
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
		$url = esc_url_raw( trim( $line ) );

		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Returns whether a request is likely a frontend HTML request.
 *
 * @return bool
 */
function erankly_is_frontend_html_request(): bool {
	return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
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
	if ( is_multisite() ) {
		printf(
			'<span class="erankly-ms-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#1a7f37;"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
			esc_html__( 'WordPress Multisite: Detected', 'easyrankly' )
		);
		return;
	}

	printf(
		'<span class="erankly-ms-status" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:400;line-height:1.4;color:#b32d2e;"><span class="dashicons dashicons-no-alt" style="font-size:14px;width:14px;height:14px;"></span>%s</span>',
		esc_html__( 'WordPress Multisite: Not detected', 'easyrankly' )
	);
}
