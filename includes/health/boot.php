<?php
/**
 * Lightweight Health module router.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads the frontend 404 runtime only when it is consumed. */
function erankly_health_load_frontend(): void {
	require_once ERANKLY_PATH . 'includes/health/frontend.php';
}

/** Loads the shared link-path normalizer. */
function erankly_health_load_link_utils(): void {
	require_once ERANKLY_PATH . 'includes/health/link-utils.php';
}

/** Loads the redirect suggestion engine and its 404 storage dependency. */
function erankly_health_load_suggestions(): void {
	erankly_health_load_frontend();
	erankly_health_load_link_utils();
	require_once ERANKLY_PATH . 'includes/health/suggestions.php';
}

/** Loads the heavy Broken-Link crawler implementation. */
function erankly_health_load_broken_links_crawler(): void {
	erankly_health_load_link_utils();
	require_once ERANKLY_PATH . 'includes/health/broken-links-crawler.php';
}

/** Loads handlers used only by Health admin-post requests. */
function erankly_health_load_admin_actions(): void {
	erankly_health_load_suggestions();
	erankly_health_load_broken_links_crawler();
	require_once ERANKLY_PATH . 'includes/health/thin-content.php';
	require_once ERANKLY_PATH . 'includes/health/broken-links-actions.php';
}

/** Loads the complete Health settings surface on demand. */
function erankly_health_load_admin_surface(): void {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	require_once ERANKLY_PATH . 'includes/health/admin.php';
	erankly_health_ensure_404_storage_current();
	$loaded = true;
}

/** Records a frontend 404 after loading only the monitor runtime. */
function erankly_health_dispatch_frontend_404(): void {
	if ( is_admin() || ! is_404() ) {
		return;
	}

	erankly_health_load_frontend();
	erankly_health_maybe_record_404();
}

/** Registers the lightweight Broken-Link route declarations during REST init. */
function erankly_health_bootstrap_rest_routes(): void {
	require_once ERANKLY_PATH . 'includes/health/broken-links-routes.php';
	erankly_health_bl_register_rest_routes();
}

/** Loads retention code only when the scheduled event actually runs. */
function erankly_health_dispatch_retention(): void {
	erankly_health_load_suggestions();
	erankly_health_prune_stale_404_data();
}

/**
 * Schedules the daily 404 retention event if it is absent.
 *
 * @return void
 */
function erankly_health_maybe_schedule_retention_cron(): void {
	if ( ! wp_next_scheduled( ERANKLY_HEALTH_404_PRUNE_HOOK ) ) {
		wp_schedule_event( time(), 'daily', ERANKLY_HEALTH_404_PRUNE_HOOK );
	}
}

/** Returns the allowlisted Health admin action callbacks. */
function erankly_health_admin_action_callbacks(): array {
	return array(
		'admin_post_erankly_health_clear_404s'    => 'erankly_health_handle_clear_404s',
		'admin_post_erankly_health_scan_thin'     => 'erankly_health_handle_scan_thin',
		'admin_post_erankly_health_404_set_state' => 'erankly_health_handle_set_404_state',
		'admin_post_erankly_health_ai_suggest'    => 'erankly_health_handle_ai_suggest',
		'admin_post_erankly_health_bl_ai_suggest' => 'erankly_health_bl_handle_ai_suggest',
		'admin_post_erankly_health_bl_clear'      => 'erankly_health_bl_handle_clear',
	);
}

/** Loads and dispatches one allowlisted Health admin action. */
function erankly_health_dispatch_admin_action(): void {
	$callbacks = erankly_health_admin_action_callbacks();
	$hook      = current_action();

	if ( ! isset( $callbacks[ $hook ] ) ) {
		return;
	}

	erankly_health_load_admin_actions();
	call_user_func( $callbacks[ $hook ] );
}

/**
 * Drops cached fuzzy suggestion rows after publishable content changes.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function erankly_health_invalidate_fuzzy_candidate_cache_on_post_change( int $post_id ): void {
	if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	erankly_health_load_frontend();
	erankly_health_invalidate_fuzzy_candidate_cache();
}

/** Registers the lightweight Health dispatchers. */
function erankly_health_boot(): void {
	add_action( 'template_redirect', 'erankly_health_dispatch_frontend_404', 100 );
	add_action( ERANKLY_HEALTH_404_PRUNE_HOOK, 'erankly_health_dispatch_retention' );
	add_action( 'rest_api_init', 'erankly_health_bootstrap_rest_routes', 5 );
	add_action( 'save_post', 'erankly_health_invalidate_fuzzy_candidate_cache_on_post_change', 10, 1 );
	add_action( 'deleted_post', 'erankly_health_invalidate_fuzzy_candidate_cache_on_post_change', 10, 1 );
	erankly_health_maybe_schedule_retention_cron();

	if ( is_admin() ) {
		foreach ( array_keys( erankly_health_admin_action_callbacks() ) as $hook ) {
			add_action( $hook, 'erankly_health_dispatch_admin_action' );
		}
	}
}
