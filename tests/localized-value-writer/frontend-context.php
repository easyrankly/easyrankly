<?php
// phpcs:ignoreFile -- Direct WordPress bootstrap proves the API remains closed outside admin, REST, and WP-CLI.
/**
 * Unauthorized frontend-context assertion.
 *
 * @package EasyRankly
 */

$wp_load = (string) ( $argv[1] ?? '' );
if ( '' === $wp_load || ! is_file( $wp_load ) ) {
	fwrite( STDERR, "A valid wp-load.php path is required.\n" );
	exit( 2 );
}

require $wp_load;
wp_set_current_user( 1 );

$result = erankly_get_localized_value_source_state( 'organization_name' );
if ( ! is_wp_error( $result ) || 'erankly_localized_value_source_forbidden' !== $result->get_error_code() ) {
	fwrite( STDERR, "The localized-source API did not fail closed in a frontend context.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Localized-source API frontend context remained fail-closed.\n" );
