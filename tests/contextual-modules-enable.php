<?php
// phpcs:ignoreFile -- WP-CLI fixture prepares a fresh request for the boundary test.
/**
 * Enables optional modules for the next contextual-loading integration request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration fixture must run through WP-CLI.' );
}

$settings = erankly_get_plugin_option( ERANKLY_OPTION, array() );
$settings = is_array( $settings ) ? $settings : array();

delete_option( 'erankly_contextual_modules_original_settings' );
add_option( 'erankly_contextual_modules_original_settings', $settings, '', false );

$settings['ai_enabled']          = 1;
$settings['enable_health']       = 1;
$settings['enable_link_building'] = 1;

erankly_update_plugin_option( ERANKLY_OPTION, $settings );

WP_CLI::success( 'Contextual module fixture enabled.' );
