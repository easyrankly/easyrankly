<?php
/**
 * Health settings surface loader.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

erankly_health_load_admin_actions();
require_once ERANKLY_PATH . 'includes/health/broken-links-admin.php';
require_once ERANKLY_PATH . 'includes/health/panel.php';
