<?php
/**
 * Lightweight REST declarations for the Health Broken-Link crawler.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Permission callback for the Broken-Link crawl REST routes. */
function erankly_health_bl_rest_permission(): bool {
	return current_user_can( 'manage_options' );
}

/** Registers the REST routes that drive the manual crawl from the admin UI. */
function erankly_health_bl_register_rest_routes(): void {
	$routes = array(
		'start'  => 'erankly_health_bl_rest_start',
		'tick'   => 'erankly_health_bl_rest_tick',
		'cancel' => 'erankly_health_bl_rest_cancel',
	);

	foreach ( $routes as $path => $callback ) {
		register_rest_route(
			'erankly/v1',
			'/health/broken-links/' . $path,
			array(
				'methods'             => 'POST',
				'callback'            => $callback,
				'permission_callback' => 'erankly_health_bl_rest_permission',
			)
		);
	}
}

/** Starts a crawl after loading the heavy implementation. */
function erankly_health_bl_rest_start(): WP_REST_Response {
	erankly_health_load_broken_links_crawler();
	$state = erankly_health_bl_start_crawl();

	return new WP_REST_Response( erankly_health_bl_progress_payload( $state ), 200 );
}

/** Advances a crawl after loading the heavy implementation. */
function erankly_health_bl_rest_tick(): WP_REST_Response {
	erankly_health_load_broken_links_crawler();
	$state = erankly_health_bl_get_state();

	if ( 'idle' === $state['status'] || 'done' === $state['status'] ) {
		return new WP_REST_Response( erankly_health_bl_progress_payload( $state ), 200 );
	}

	if ( 'discovering' === $state['status'] ) {
		$state = erankly_health_bl_run_discovery_batch( $state );
	} elseif ( 'checking' === $state['status'] ) {
		$state = erankly_health_bl_run_checking_batch( $state );
	}

	erankly_health_bl_save_state( $state );

	return new WP_REST_Response( erankly_health_bl_progress_payload( $state ), 200 );
}

/** Cancels a crawl after loading the heavy implementation. */
function erankly_health_bl_rest_cancel(): WP_REST_Response {
	erankly_health_load_broken_links_crawler();
	erankly_health_bl_reset_state();

	return new WP_REST_Response( erankly_health_bl_progress_payload( erankly_health_bl_default_state() ), 200 );
}
