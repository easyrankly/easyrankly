<?php
/**
 * Health module loader.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/health/constants.php';
require_once ERANKLY_PATH . 'includes/health/404-monitor.php';
require_once ERANKLY_PATH . 'includes/health/suggestions.php';
require_once ERANKLY_PATH . 'includes/health/thin-content.php';
require_once ERANKLY_PATH . 'includes/health/broken-links-crawler.php';
require_once ERANKLY_PATH . 'includes/health/broken-links-admin.php';
require_once ERANKLY_PATH . 'includes/health/panel.php';
require_once ERANKLY_PATH . 'includes/health/boot.php';
