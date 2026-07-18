<?php
/**
 * Shared helpers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads the dynamic default model only for full-settings operations. */
function erankly_load_default_helpers(): void {
	erankly_load_schema_sanitizers();
	require_once ERANKLY_PATH . 'includes/helpers/content-defaults.php';
	require_once ERANKLY_PATH . 'includes/helpers/defaults.php';
}

/** Loads helpers used by rendered SEO content and rich admin editors. */
function erankly_load_content_helpers(): void {
	erankly_load_schema_sanitizers();
	require_once ERANKLY_PATH . 'includes/helpers/content-defaults.php';
	require_once ERANKLY_PATH . 'includes/helpers/utils.php';
	require_once ERANKLY_PATH . 'includes/helpers/global-meta.php';
	require_once ERANKLY_PATH . 'includes/helpers/template-variables.php';
}

/** Loads only the defaults and connector utilities consumed by AI requests. */
function erankly_load_ai_helpers(): void {
	require_once ERANKLY_PATH . 'includes/helpers/content-defaults.php';
	require_once ERANKLY_PATH . 'includes/helpers/utils.php';
}

/** Loads sitemap URL, transient-key and invalidation helpers on demand. */
function erankly_load_sitemap_helpers(): void {
	require_once ERANKLY_PATH . 'includes/helpers/sitemap-cache.php';
}

/** Loads LocalBusiness and schema-specific sanitizers on demand. */
function erankly_load_schema_sanitizers(): void {
	require_once ERANKLY_PATH . 'includes/helpers/sanitization-schema.php';
}

/** Loads video extraction helpers for VideoObject and video-sitemap work. */
function erankly_load_video_helpers(): void {
	require_once ERANKLY_PATH . 'includes/helpers/video.php';
}

// Request-wide kernel: defaults, rich metadata, variables and video parsing
// remain out until their owning surface calls erankly_load_content_helpers().
require_once ERANKLY_PATH . 'includes/helpers/core.php';
require_once ERANKLY_PATH . 'includes/helpers/settings.php';
require_once ERANKLY_PATH . 'includes/helpers/feature-modules.php';
require_once ERANKLY_PATH . 'includes/helpers/sanitization.php';
