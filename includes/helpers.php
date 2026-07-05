<?php
/**
 * Shared helpers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Foundational helpers, split by responsibility. All are loaded on every request
// because they are used across the whole plugin; load order is irrelevant since
// each file only defines functions.
require_once ERANKLY_PATH . 'includes/helpers/defaults.php';
require_once ERANKLY_PATH . 'includes/helpers/sitemap-cache.php';
require_once ERANKLY_PATH . 'includes/helpers/settings.php';
require_once ERANKLY_PATH . 'includes/helpers/sanitization.php';
require_once ERANKLY_PATH . 'includes/helpers/global-meta.php';
require_once ERANKLY_PATH . 'includes/helpers/template-variables.php';
require_once ERANKLY_PATH . 'includes/helpers/utils.php';
require_once ERANKLY_PATH . 'includes/helpers/video.php';
