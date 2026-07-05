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
 * Whether the multilingual module is enabled.
 *
 * @return bool
 */
function erankly_multilingual_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_multilingual' ) );
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
 * Whether the Link Building module is enabled.
 *
 * @return bool
 */
function erankly_link_building_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_link_building', 0 );
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
