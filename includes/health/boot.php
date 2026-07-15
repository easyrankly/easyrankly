<?php
/**
 * Health module bootstrap.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Registers Health hooks.
 *
 * @return void
 */
function erankly_health_boot(): void {
	add_action( 'template_redirect', 'erankly_health_maybe_record_404', 100 );

	// Daily retention sweep for 404 aggregate data.
	add_action( ERANKLY_HEALTH_404_PRUNE_HOOK, 'erankly_health_prune_stale_404_data' );
	erankly_health_maybe_schedule_retention_cron();

	// REST routes driving the manual Broken-Link Candidates crawl in batches.
	add_action( 'rest_api_init', 'erankly_health_bl_register_rest_routes' );

	// WordPress privacy tools — 404 paths are anonymized and not user-linked, but
	// site admins can initiate a full wipe from the Privacy → Erase Personal Data flow.

	if ( is_admin() ) {
		add_action( 'admin_post_erankly_health_clear_404s', 'erankly_health_handle_clear_404s' );
		add_action( 'admin_post_erankly_health_scan_thin', 'erankly_health_handle_scan_thin' );
		add_action( 'admin_post_erankly_health_404_set_state', 'erankly_health_handle_set_404_state' );
		add_action( 'admin_post_erankly_health_ai_suggest', 'erankly_health_handle_ai_suggest' );
		add_action( 'admin_post_erankly_health_bl_ai_suggest', 'erankly_health_bl_handle_ai_suggest' );
		add_action( 'admin_post_erankly_health_bl_clear', 'erankly_health_bl_handle_clear' );
	}
}
