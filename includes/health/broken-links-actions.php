<?php
/**
 * Admin-post actions for Health Broken-Link results.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Handles an on-demand AI suggestion for one internal broken link. */
function erankly_health_bl_handle_ai_suggest(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_bl_ai_suggest' );

	$raw_url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$canonical = erankly_health_bl_canonicalize( $raw_url );
	$outcome   = 'error';

	if ( ! function_exists( 'erankly_ai_enabled' ) || ! erankly_ai_enabled() ) {
		$outcome = 'disabled';
	} elseif ( '' !== $canonical && erankly_health_bl_is_internal( $canonical ) ) {
		$path = erankly_health_normalize_link_path( $canonical );

		if ( '' !== $path ) {
			$suggestion = erankly_health_ai_suggest_redirect_target( array( 'path' => $path ) );

			if ( null !== $suggestion ) {
				$outcome = 'suggested';
			} else {
				$outcome = 'none' === erankly_health_ai_cached_suggestion( $path )['state'] ? 'none' : 'error';
			}
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'erankly',
				'erankly_tab'       => 'health',
				'erankly_health_ai' => $outcome,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/** Handles clearing finished Broken-Link results and stale run state. */
function erankly_health_bl_handle_clear(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_bl_clear' );

	erankly_health_bl_reset_state();
	delete_option( ERANKLY_HEALTH_BL_RESULTS_OPTION );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                      => 'erankly',
				'erankly_tab'               => 'health',
				'erankly_health_bl_cleared' => '1',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}
