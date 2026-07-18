<?php
/**
 * Shared helpers — settings access and feature flags.
 *
 * Part of the helpers.php loader; always loaded early on every request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns settings merged with the complete dynamic default model.

 * Prefer erankly_get_setting() for runtime reads; this full merge is intended
 * for settings forms, activation/reset and partial-write normalization.
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
 * Returns only persisted settings, cached for the current request.
 *
 * Unlike erankly_get_settings(), this does not build dynamic defaults. Runtime
 * feature checks should use this path with an explicit per-key fallback.
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

/**
 * Clears the request-level settings cache after settings change.
 *
 * @return void
 */
function erankly_clear_settings_cache(): void {
	unset( $GLOBALS['erankly_settings_cache'], $GLOBALS['erankly_stored_settings_cache'] );
}

/**
 * Returns whether at least one bloat-removal feature is enabled.
 *
 * @return bool
 */
function erankly_bloat_enabled(): bool {
	$keys = array(
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
		if ( ! empty( erankly_get_setting( $key, 0 ) ) ) {
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
	$settings = erankly_get_stored_settings();

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default_value;
}
