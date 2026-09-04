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
		'noindex_paginated',
		'noindex_paginated_content',
		'nofollow_paginated',
		'noindex_feeds',
		'robots_max_image_preview_large',
		'robots_nosnippet',
		'robots_noimageindex',
		'robots_notranslate',
		'robots_noodp',
		'robots_indexifembedded',
		'enable_redirects',
		'redirect_exclude_admins',
	);

	/**
 * Filters setting keys backed by standalone on/off toggles. Add-ons must register keys they render as checkboxes
 * so a partial Features save does not leave them stuck on.
 */
	$keys = apply_filters( 'erankly_settings_toggle_keys', $keys );

	return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
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
	$raw_keys = array_keys( $input );

	if ( '' !== $panel ) {
		$panel_keys  = erankly_settings_panel_keys( $panel );
		$toggle_keys = array_fill_keys( erankly_settings_toggle_keys(), true );

		foreach ( $panel_keys as $key ) {
			if ( isset( $toggle_keys[ $key ] ) && ! array_key_exists( $key, $input ) ) {
				$input[ $key ] = 0;
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
