<?php
// phpcs:ignoreFile -- Disposable WP-CLI fixture exercises the public writer against real WordPress storage.
/**
 * Public localized-source writer runtime assertions.
 *
 * @package EasyRankly
 */

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

wp_set_current_user( 1 );

$assert( 1 === ERANKLY_EXTENSION_API_VERSION, 'The frozen public extension API major changed.' );
$assert( function_exists( 'erankly_get_localized_value_source_state' ), 'The public localized-source read API is absent.' );
$assert( function_exists( 'erankly_update_localized_value_source' ), 'The public localized-source writer API is absent.' );
$assert( ! is_dir( WP_PLUGIN_DIR . '/easyrankly-multilingual' ), 'The core-only fixture unexpectedly contains the add-on.' );

$unknown = erankly_get_localized_value_source_state( 'arbitrary_private_option' );
$assert(
	is_wp_error( $unknown ) && 'erankly_localized_value_source_key_unregistered' === $unknown->get_error_code(),
	'An unregistered source key was not rejected.'
);

$initial_settings = erankly_get_stored_settings();
$initial_state    = erankly_get_localized_value_source_state( 'organization_name' );
$assert( ! is_wp_error( $initial_state ), 'The current registered source state could not be read.' );
$assert(
	is_array( $initial_state )
		&& 'erankly-localized-source/1' === ( $initial_state['contract'] ?? '' )
		&& 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', (string) ( $initial_state['fingerprint'] ?? '' ) ),
	'The current source state does not expose the bounded CAS contract.'
);

$unrelated = 'writer-unrelated-' . wp_generate_password( 12, false );
$seeded    = erankly_update_plugin_settings( array( 'organization_phone' => $unrelated ) );
$assert( true === $seeded, 'The fixture could not seed an unrelated setting.' );
$initial_state = erankly_get_localized_value_source_state( 'organization_name' );

$written = erankly_update_localized_value_source(
	'organization_name',
	'Writer API organization',
	(string) $initial_state['fingerprint']
);
$assert(
	! is_wp_error( $written )
		&& ! empty( $written['changed'] )
		&& empty( $written['idempotent'] )
		&& 'Writer API organization' === ( $written['value'] ?? '' ),
	'A valid source write did not complete and verify.'
);
$assert(
	$unrelated === erankly_get_setting( 'organization_phone', '' ),
	'The localized-source write lost an unrelated concurrent setting.'
);

$stale = erankly_update_localized_value_source(
	'organization_name',
	'Stale competing value',
	(string) $initial_state['fingerprint']
);
$assert(
	is_wp_error( $stale ) && 'erankly_localized_value_source_revision_conflict' === $stale->get_error_code(),
	'A stale expected fingerprint did not fail closed.'
);

$retry = erankly_update_localized_value_source(
	'organization_name',
	'Writer API organization',
	(string) $initial_state['fingerprint']
);
$assert(
	! is_wp_error( $retry ) && ! empty( $retry['idempotent'] ) && empty( $retry['changed'] ),
	'An exact completed write was not retry-idempotent.'
);

$invalid = erankly_update_localized_value_source(
	'organization_name',
	"<script>secret-writer-value</script>\n",
	(string) $written['fingerprint']
);
$assert(
	is_wp_error( $invalid ) && 'erankly_localized_value_source_invalid' === $invalid->get_error_code(),
	'A value changed by the core sanitizer was not rejected.'
);
$assert(
	false === str_contains( serialize( $invalid ), 'secret-writer-value' ),
	'An invalid raw value leaked into the public error.'
);

$write_failure_state = erankly_get_localized_value_source_state( 'website_name' );
$force_write_failure = static function ( mixed $value, mixed $old_value ): mixed {
	unset( $value );

	return $old_value;
};
add_filter( 'pre_update_option_erankly_settings', $force_write_failure, PHP_INT_MAX, 2 );
$write_failure = erankly_update_localized_value_source(
	'website_name',
	'Writer failure fixture',
	(string) $write_failure_state['fingerprint']
);
remove_filter( 'pre_update_option_erankly_settings', $force_write_failure, PHP_INT_MAX );
$assert(
	is_wp_error( $write_failure ) && 'erankly_localized_value_source_write_failed' === $write_failure->get_error_code(),
	'A storage failure did not return the bounded write error.'
);
$assert(
	false === str_contains( serialize( $write_failure ), 'Writer failure fixture' ),
	'A failed write leaked the attempted value.'
);

$verify_failure_state = erankly_get_localized_value_source_state( 'website_description' );
$force_verify_read    = static function ( mixed $settings ) use ( $verify_failure_state ): mixed {
	if ( is_array( $settings ) ) {
		$settings['website_description'] = (string) $verify_failure_state['value'];
	}

	return $settings;
};
$force_verify_failure = static function () use ( $force_verify_read ): void {
	add_filter( 'option_erankly_settings', $force_verify_read, PHP_INT_MAX, 1 );
	erankly_clear_settings_cache();
};
add_action( 'erankly_localized_value_source_write_checkpoint', $force_verify_failure, 10, 0 );
$verify_failure = erankly_update_localized_value_source(
	'website_description',
	'Writer verification fixture',
	(string) $verify_failure_state['fingerprint']
);
remove_action( 'erankly_localized_value_source_write_checkpoint', $force_verify_failure, 10 );
remove_filter( 'option_erankly_settings', $force_verify_read, PHP_INT_MAX );
erankly_clear_settings_cache();
$assert(
	is_wp_error( $verify_failure ) && 'erankly_localized_value_source_verify_failed' === $verify_failure->get_error_code(),
	'A post-write verification mismatch did not fail closed.'
);
$assert(
	false === str_contains( serialize( $verify_failure ), 'Writer verification fixture' ),
	'A verification error leaked the attempted value.'
);

$current = erankly_get_localized_value_source_state( 'organization_name' );
$restore = erankly_update_localized_value_source(
	'organization_name',
	(string) $initial_state['value'],
	(string) $current['fingerprint']
);
$again = erankly_update_localized_value_source(
	'organization_name',
	(string) $initial_state['value'],
	(string) $current['fingerprint']
);
$assert(
	! is_wp_error( $restore ) && ! empty( $restore['changed'] ),
	'The verified source value could not be restored.'
);
$assert(
	! is_wp_error( $again ) && ! empty( $again['idempotent'] ) && empty( $again['changed'] ),
	'A second identical restore was not idempotent.'
);

wp_set_current_user( 0 );
$forbidden = erankly_get_localized_value_source_state( 'organization_name' );
$assert(
	is_wp_error( $forbidden ) && 'erankly_localized_value_source_forbidden' === $forbidden->get_error_code(),
	'A caller without manage_options accessed the public source state.'
);
wp_set_current_user( 1 );

update_option( ERANKLY_OPTION, $initial_settings, true );
erankly_clear_settings_cache();

if ( $failures ) {
	WP_CLI::error( implode( "\n", $failures ) );
}

WP_CLI::success( 'Public localized-source read/write/CAS/verification/restore/privacy assertions passed.' );
