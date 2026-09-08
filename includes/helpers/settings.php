<?php
/** Shared helpers: settings access and feature flags. Part of the helpers.php loader; always loaded early on every request. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns settings merged with the complete dynamic default model. Prefer erankly_get_setting() for runtime
 * reads; this full merge is intended for settings forms, activation/reset and partial-write normalization.
 *
 * @return array<string,mixed>
 */
function erankly_get_settings(): array {
	if ( isset( $GLOBALS['erankly_settings_cache'] ) && is_array( $GLOBALS['erankly_settings_cache'] ) ) {
		return $GLOBALS['erankly_settings_cache'];
	}

	erankly_load_default_helpers();
	$settings = erankly_get_stored_settings();

	$GLOBALS['erankly_settings_cache'] = wp_parse_args( $settings, erankly_default_settings() );

	return $GLOBALS['erankly_settings_cache'];
}

/**
 * Returns only persisted settings, cached for the current request. Unlike erankly_get_settings(), this does not
 * build dynamic defaults. Runtime feature checks should use this path with an explicit per-key fallback.
 *
 * @return array<string,mixed>
 */
function erankly_get_stored_settings(): array {
	if ( isset( $GLOBALS['erankly_stored_settings_cache'] ) && is_array( $GLOBALS['erankly_stored_settings_cache'] ) ) {
		return $GLOBALS['erankly_stored_settings_cache'];
	}

	$settings = is_multisite()
		? get_site_option( ERANKLY_OPTION, array() )
		: get_option( ERANKLY_OPTION, array() );

	$GLOBALS['erankly_stored_settings_cache'] = is_array( $settings ) ? $settings : array();

	return $GLOBALS['erankly_stored_settings_cache'];
}

/** Clears the request-level settings cache after settings change. */
function erankly_clear_settings_cache(): void {
	unset( $GLOBALS['erankly_settings_cache'], $GLOBALS['erankly_stored_settings_cache'] );
}

/** @return array<int,string> */
function erankly_settings_toggle_keys(): array {
	$keys = array(
		'social_defaults_linked',
		'global_post_type_meta_linked',
		'global_taxonomy_meta_linked',
		'enable_local_business',
		'simplified_mode',
		'resolve_placeholders',
		'enable_sitemap',
		'enable_news_sitemap',
		'enable_image_sitemap',
		'enable_video_sitemap',
		'enable_breadcrumbs',
		'enable_website_search_action',
		'noindex_paginated',
		'noindex_paginated_content',
		'nofollow_paginated',
		'noindex_feeds',
		'robots_nosnippet',
		'robots_noimageindex',
		'robots_notranslate',
		'robots_indexifembedded',
		'enable_redirects',
		'enable_custom_code',
	);

	/**
 * Filters setting keys backed by standalone on/off toggles. Add-ons must register keys they render as checkboxes
 * so a partial Features save does not leave them stuck on.
 */
	$keys = apply_filters( 'erankly_settings_toggle_keys', $keys );

	return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
}

/**
 * Setting keys backed by a repeatable block builder.
 *
 * These render as a list of blocks, so deleting every block leaves no field behind and the key disappears from
 * the submission entirely. Without this list the merge below would read that absence as "untouched" and restore
 * the stored blocks, which is how a cleared Custom code panel kept printing its snippets on the frontend.
 *
 * @return array<int,string>
 */
function erankly_settings_collection_keys(): array {
	$keys = array(
		'global_schema_blocks',
		'head_code_blocks',
		'body_open_code_blocks',
		'body_close_code_blocks',
	);

	/**
 * Filters setting keys backed by repeatable block builders. Add-ons must register keys they render as a
 * block list so clearing the list actually clears the stored value.
 */
	$keys = apply_filters( 'erankly_settings_collection_keys', $keys );

	return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
}

/**
 * Registers an add-on checkbox in the Features panel as one atomic pipeline.
 *
 * Calling this during plugin loading wires the visible control, the server and
 * client autosave allowlists, unchecked-toggle handling, refresh behavior and
 * any repeatable collections owned by the feature. This avoids a control that
 * renders and submits successfully but is silently discarded by a missing
 * companion filter.
 *
 * The render callback receives the complete settings array and the sanitized
 * setting key. It must print the checkbox field whose name uses that key.
 *
 * @param string              $setting_key    Boolean setting key.
 * @param callable            $render_callback Callback used by the Features renderer.
 * @param array<int,string>   $collection_keys Repeatable setting collections owned by the feature.
 */
function erankly_register_settings_feature_module( string $setting_key, callable $render_callback, array $collection_keys = array() ): void {
	$setting_key    = sanitize_key( $setting_key );
	$collection_keys = array_values( array_filter( array_map( 'sanitize_key', $collection_keys ) ) );

	if ( '' === $setting_key ) {
		return;
	}

	add_action(
		'erankly_settings_features_modules',
		static function ( array $settings ) use ( $render_callback, $setting_key ): void {
			call_user_func( $render_callback, $settings, $setting_key );
		}
	);

	add_filter(
		'erankly_settings_toggle_keys',
		static function ( array $keys ) use ( $setting_key ): array {
			$keys[] = $setting_key;
			return array_values( array_unique( $keys ) );
		}
	);

	if ( $collection_keys ) {
		add_filter(
			'erankly_settings_collection_keys',
			static function ( array $keys ) use ( $collection_keys ): array {
				return array_values( array_unique( array_merge( $keys, $collection_keys ) ) );
			}
		);
	}

	add_filter(
		'erankly_settings_autosave_panels',
		static function ( array $panels ) use ( $setting_key, $collection_keys ): array {
			$panels['features']         = isset( $panels['features'] ) && is_array( $panels['features'] ) ? $panels['features'] : array();
			$panels['features']['keys'] = isset( $panels['features']['keys'] ) && is_array( $panels['features']['keys'] ) ? $panels['features']['keys'] : array();
			$panels['features']['keys'] = array_values( array_unique( array_merge( $panels['features']['keys'], array( $setting_key ), $collection_keys ) ) );
			return $panels;
		}
	);

	add_filter(
		'erankly_settings_autosave_client_panels',
		static function ( array $panels ) use ( $setting_key ): array {
			$panels['features']                = isset( $panels['features'] ) && is_array( $panels['features'] ) ? $panels['features'] : array();
			$panels['features']['restUrl']     = $panels['features']['restUrl'] ?? esc_url_raw( rest_url( 'erankly/v1/settings/features' ) );
			$panels['features']['reloadOnSave'] = true;
			$refresh_keys                       = isset( $panels['features']['refreshKeys'] ) && is_array( $panels['features']['refreshKeys'] ) ? $panels['features']['refreshKeys'] : array();
			$panels['features']['refreshKeys']  = array_values( array_unique( array_merge( $refresh_keys, array( $setting_key ) ) ) );
			return $panels;
		}
	);
}

/**
 * @param string $panel Panel slug such as "features" or "general".
 * @return array<int,string>
 */
function erankly_settings_panel_keys( string $panel ): array {
	$panel = sanitize_key( $panel );

	if ( '' === $panel || ! function_exists( 'erankly_settings_autosave_panels' ) ) {
		return array();
	}

	$registry = erankly_settings_autosave_panels();

	return isset( $registry[ $panel ]['keys'] ) && is_array( $registry[ $panel ]['keys'] )
		? $registry[ $panel ]['keys']
		: array();
}

/**
 * Merges a partial settings submission over the stored map. Classic HTML forms only send the active panel.
 * Unchecked toggles are omitted, so absent toggle keys in the submitted panel scope default to off before merge.
 *
 * @param array<string,mixed> $input Raw submitted settings fragment.
 * @param string              $panel Panel slug from erankly_settings_panel.
 * @return array<string,mixed>
 */
function erankly_merge_settings_submission( array $input, string $panel = '' ): array {
	$stored   = erankly_get_settings();
	$panel    = sanitize_key( $panel );

	if ( '' !== $panel ) {
		$panel_keys      = erankly_settings_panel_keys( $panel );
		$toggle_keys     = array_fill_keys( erankly_settings_toggle_keys(), true );
		$collection_keys = array_fill_keys( erankly_settings_collection_keys(), true );

		foreach ( $panel_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( isset( $toggle_keys[ $key ] ) ) {
				$input[ $key ] = 0;
				continue;
			}

			// An emptied block builder submits no field at all: absence means "cleared", not "unchanged".
			if ( isset( $collection_keys[ $key ] ) ) {
				$input[ $key ] = array();
			}
		}
	}

	return array_replace( $stored, $input );
}

/** @param string $active_panel Active tab slug such as "settings-features". */
function erankly_active_panel_submission_slug( string $active_panel ): string {
	if ( str_starts_with( $active_panel, 'settings-' ) ) {
		return sanitize_key( substr( $active_panel, 9 ) );
	}

	return sanitize_key( $active_panel );
}

function erankly_get_setting( string $key, mixed $default_value = null ): mixed {
	$settings    = erankly_get_stored_settings();
	$value       = array_key_exists( $key, $settings ) ? $settings[ $key ] : $default_value;
	$provider    = function_exists( 'erankly_get_multilingual_provider' ) ? erankly_get_multilingual_provider() : null;
	$query       = $GLOBALS['wp_query'] ?? null;
	$query_ready = did_action( 'wp' ) > 0
		|| ( $query instanceof WP_Query && ( $query->is_singular || $query->is_home || $query->is_front_page || $query->is_archive || $query->is_search || $query->is_404 || $query->is_feed ) );
	$context     = $provider instanceof ERankly_Multilingual_Provider_Interface && $query_ready ? erankly_get_multilingual_context() : array();

	/** @param mixed               $value         Stored or default value. */
	return apply_filters( 'erankly_setting_value', $value, $key, $default_value, $context );
}

/**
 * Moves the per-post-type Schema.org types out of global_post_type_meta into their own setting. They used to
 * ride along with the title and description rows, which meant the "Same for all" toggle hid them for every
 * content type but the first, and a General save could drop them.
 */
function erankly_maybe_migrate_post_type_schema(): void {
	if ( erankly_get_plugin_option( 'erankly_migrated_post_type_schema_v1', false ) ) {
		return;
	}

	erankly_load_default_helpers();
	$settings = erankly_get_stored_settings();

	if ( empty( $settings ) || isset( $settings['global_post_type_schema'] ) ) {
		erankly_update_plugin_option( 'erankly_migrated_post_type_schema_v1', true );

		return;
	}

	$legacy = ( isset( $settings['global_post_type_meta'] ) && is_array( $settings['global_post_type_meta'] ) )
		? $settings['global_post_type_meta']
		: array();
	$schema = array();

	foreach ( array_keys( erankly_get_public_post_types() ) as $post_type ) {
		$post_type = (string) $post_type;
		$row       = ( isset( $legacy[ $post_type ] ) && is_array( $legacy[ $post_type ] ) ) ? $legacy[ $post_type ] : array();
		$defaults  = erankly_default_post_type_schema_row( $post_type );

		$webpage_type = array_key_exists( 'webpage_type', $row ) ? erankly_sanitize_schema_type_name( $row['webpage_type'] ) : '';
		// An empty stored value was how the retired free-text field expressed
		// "emit no Article node", so it maps to the explicit "none" choice.
		$article_type = array_key_exists( 'article_type', $row )
			? ( '' === trim( (string) $row['article_type'] ) ? 'none' : erankly_sanitize_schema_type_name( $row['article_type'] ) )
			: '';

		$schema[ $post_type ] = array(
			'webpage_type' => '' !== $webpage_type ? $webpage_type : $defaults['webpage_type'],
			'article_type' => '' !== $article_type ? $article_type : $defaults['article_type'],
		);
	}

	foreach ( $legacy as $post_type => $row ) {
		if ( is_array( $row ) ) {
			unset( $legacy[ $post_type ]['webpage_type'], $legacy[ $post_type ]['article_type'] );
		}
	}

	$settings['global_post_type_schema'] = $schema;
	$settings['global_post_type_meta']   = $legacy;

	erankly_update_plugin_settings( $settings, '', true );
	erankly_clear_settings_cache();
	erankly_update_plugin_option( 'erankly_migrated_post_type_schema_v1', true );
}

/** Applies one-time settings migrations. */
function erankly_maybe_migrate_settings(): void {
	if ( erankly_get_plugin_option( 'erankly_migrated_title_defaults_v1', false ) ) {
		return;
	}

	erankly_load_default_helpers();
	$settings = erankly_get_stored_settings();

	if ( empty( $settings ) ) {
		erankly_update_plugin_option( 'erankly_migrated_title_defaults_v1', true );

		return;
	}

	$changed             = false;
	$legacy_post_title   = '{{post_title}} - {{site_name}}';
	$legacy_term_title   = '{{term_name}} - {{site_name}}';
	$title_template_keys = array( 'global_post_type_meta', 'global_taxonomy_meta' );
	$single_title_keys   = array( 'default_og_title', 'default_twitter_title' );

	foreach ( $title_template_keys as $meta_key ) {
		if ( empty( $settings[ $meta_key ] ) || ! is_array( $settings[ $meta_key ] ) ) {
			continue;
		}

		foreach ( $settings[ $meta_key ] as $entity_key => $meta ) {
			if ( ! is_array( $meta ) || ! isset( $meta['title'] ) ) {
				continue;
			}

			$replacement = 'global_taxonomy_meta' === $meta_key ? '{{term_name}}' : '{{post_title}}';
			$legacy      = 'global_taxonomy_meta' === $meta_key ? $legacy_term_title : $legacy_post_title;

			if ( $legacy === (string) $meta['title'] ) {
				$settings[ $meta_key ][ $entity_key ]['title'] = $replacement;
				$changed                                       = true;
			}
		}
	}

	foreach ( $single_title_keys as $title_key ) {
		if ( isset( $settings[ $title_key ] ) && $legacy_post_title === (string) $settings[ $title_key ] ) {
			$settings[ $title_key ] = '{{post_title}}';
			$changed                = true;
		}
	}

	if ( array_key_exists( 'website_name', $settings ) && '' === trim( (string) $settings['website_name'] ) ) {
		unset( $settings['website_name'] );
		$changed = true;
	}

	if ( array_key_exists( 'website_description', $settings ) && '' === trim( (string) $settings['website_description'] ) ) {
		unset( $settings['website_description'] );
		$changed = true;
	}

	if ( $changed ) {
		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();
	}

	erankly_update_plugin_option( 'erankly_migrated_title_defaults_v1', true );
}

/**
 * Migrates the shared LocalBusiness path into a per-blog page ID map.
 */
function erankly_maybe_migrate_local_business_pages(): void {
	if ( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) ) {
		return;
	}

	erankly_load_default_helpers();
	$settings = erankly_get_stored_settings();

	if ( empty( $settings ) ) {
		erankly_update_plugin_option( 'erankly_migrated_local_business_pages_v1', true );

		return;
	}

	$existing = isset( $settings['local_business_pages'] ) && is_array( $settings['local_business_pages'] )
		? array_filter( array_map( 'absint', $settings['local_business_pages'] ) )
		: array();

	if ( ! empty( $existing ) ) {
		erankly_update_plugin_option( 'erankly_migrated_local_business_pages_v1', true );

		return;
	}

	$path = erankly_sanitize_relative_path( $settings['local_business_page_path'] ?? '' );

	if ( '' === $path ) {
		erankly_update_plugin_option( 'erankly_migrated_local_business_pages_v1', true );

		return;
	}

	$map   = array();
	$sites = is_multisite() ? get_sites( array( 'number' => 200 ) ) : array( (object) array( 'blog_id' => get_current_blog_id() ) );

	foreach ( $sites as $site ) {
		$blog_id  = absint( is_object( $site ) ? $site->blog_id : $site );
		$switched = is_multisite() && get_current_blog_id() !== $blog_id;

		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		$page = get_page_by_path( trim( $path, '/' ), OBJECT, 'page' );

		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			$map[ $blog_id ] = (int) $page->ID;
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	if ( ! empty( $map ) ) {
		$settings['local_business_pages'] = $map;
		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();
	}

	erankly_update_plugin_option( 'erankly_migrated_local_business_pages_v1', true );
}
