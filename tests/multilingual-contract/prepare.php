<?php
// phpcs:ignoreFile -- WP-CLI setup for a disposable M1 test installation.
/**
 * Prepares provider-specific feature ownership for the next request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() ) {
	throw new RuntimeException( 'Run the M1 preparation step through WP-CLI on Multisite.' );
}

$provider = sanitize_key( (string) getenv( 'ERANKLY_ML_CONTRACT_PROVIDER' ) );
$provider = '' !== $provider ? $provider : 'bundled';

if ( ! in_array( $provider, array( 'bundled', 'addon' ), true ) ) {
	throw new RuntimeException( 'Unsupported M1 preparation provider: ' . $provider );
}

$settings                         = erankly_get_settings();
$settings['enable_multilingual'] = 'bundled' === $provider ? 1 : 0;
$settings['simplified_mode']     = 1;
$settings['enable_sitemap']      = 0;
erankly_update_plugin_option( ERANKLY_OPTION, $settings );

$enabled = (bool) erankly_get_setting( 'enable_multilingual', 0 );
if ( $enabled !== ( 'bundled' === $provider ) ) {
	throw new RuntimeException( 'The M1 provider feature-state preparation did not persist.' );
}

WP_CLI::success( 'EasyRankly M1 fixture prepared for provider: ' . $provider . '.' );
