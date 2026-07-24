<?php
/**
 * Feature module toggles.
 *
 * Lightweight enabled checks for opt-in modules. Implementation files are
 * required only from erankly_bootstrap() when the matching toggle is on.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the redirect manager module is enabled.
 *
 * @return bool
 */
function erankly_redirects_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_redirects' ) );
}

/**
 * Whether the sitemap module is enabled.
 *
 * @return bool
 */
function erankly_sitemap_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_sitemap', 0 );
}

/**
 * Whether the Health module is enabled.
 *
 * @return bool
 */
function erankly_health_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_health', 0 );
}

/**
 * Whether the admin has turned on AI features in settings.
 *
 * Does not check provider availability; use erankly_ai_provider_available()
 * or erankly_ai_available() (when the AI module is loaded) for that.
 *
 * @return bool
 */
function erankly_ai_module_enabled(): bool {
	return (bool) erankly_get_setting( 'ai_enabled', 0 );
}

/**
 * Whether the Content Analysis editor module is enabled.
 *
 * This toggle is intentionally independent from simplified mode and AI
 * provider availability. The latter controls report generation, while the
 * module flag controls whether the editor surface and its REST routes exist.
 *
 * @return bool
 */
function erankly_content_analysis_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_content_analysis', 0 );
}

/**
 * Turns off AI features when their last provider is no longer available.
 *
 * Provider discovery is complete only after WordPress has run `init`, so this
 * is called from `admin_init` rather than while feature modules are bootstrapped.
 * Persisting the disabled state also means reconnecting a provider does not
 * silently opt the site back into AI features.
 *
 * @return void
 */
function erankly_maybe_disable_ai_without_provider(): void {
	// The toggle is network-wide on Multisite. A provider can still be
	// configured on another site, so only the Network Admin context may
	// reconcile the shared value.
	if ( is_multisite() && ! is_network_admin() ) {
		return;
	}

	if ( ! erankly_ai_module_enabled() || erankly_ai_provider_available() ) {
		return;
	}

	$settings                         = erankly_get_settings();
	$settings['ai_enabled']           = 0;
	$settings['enable_link_building'] = 0;

	erankly_update_plugin_option( ERANKLY_OPTION, $settings );
}

/**
 * Whether the Link Building module is enabled.
 *
 * Internal link suggestions are an AI-dependent module. Keeping the
 * dependency in this lightweight runtime check prevents stale or imported
 * settings from booting Link Building while AI features are off.
 *
 * @return bool
 */
function erankly_link_building_enabled(): bool {
	return erankly_ai_module_enabled() && (bool) erankly_get_setting( 'enable_link_building', 0 );
}

/**
 * Whether internal link suggestions are available in the post editor.
 *
 * Requires Link Building and AI features to be enabled, plus a connected provider.
 *
 * @return bool
 */
function erankly_internal_links_available(): bool {
	if ( ! erankly_link_building_enabled() || ! erankly_ai_module_enabled() ) {
		return false;
	}

	return erankly_ai_provider_available();
}
