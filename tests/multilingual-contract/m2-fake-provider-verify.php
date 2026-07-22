<?php
// phpcs:ignoreFile -- WP-CLI verification for the disposable fake provider MU plugin.
$provider = erankly_get_multilingual_provider();

if ( ! $provider instanceof ERankly_Multilingual_Provider_Interface || 'm2-fake-provider' !== $provider->get_id() ) {
	WP_CLI::error( 'The fake external provider was not selected.' );
}

$settings                         = erankly_get_stored_settings();
$settings['enable_multilingual'] = 1;
erankly_update_plugin_option( ERANKLY_OPTION, $settings );

$legacy_called = false;
$legacy_filter = static function ( array $alternates ) use ( &$legacy_called ): array {
	$legacy_called = true;

	return $alternates;
};
add_filter( 'erankly_hreflang_alternates', $legacy_filter, 10, 1 );
$alternates = erankly_get_hreflang_alternates();
remove_filter( 'erankly_hreflang_alternates', $legacy_filter, 10 );
$localized = erankly_localize_url( home_url( '/fake/' ) );
$counts    = $GLOBALS['erankly_m2_fake_provider_counts'] ?? array();

if ( ! $legacy_called
	|| ! isset( $alternates['it'], $alternates['x-default'] )
	|| ! str_contains( $localized, 'm2-language=it' )
	|| 1 !== (int) ( $counts['register_hooks'] ?? 0 )
	|| 1 !== (int) ( $counts['get_context'] ?? 0 )
	|| 1 !== (int) ( $counts['get_alternates'] ?? 0 )
	|| 1 !== (int) ( $counts['localize_url'] ?? 0 )
	|| erankly_bundled_multilingual_provider_is_active()
	|| class_exists( 'ERankly_ML_Repository', false )
	|| class_exists( 'ERankly_ML_Resolver', false )
	|| class_exists( 'ERankly_ML_Admin', false ) ) {
	WP_CLI::error( 'The fake provider did not fully replace the bundled runtime.' );
}

WP_CLI::success( 'M2 fake provider replaced the fallback without loading bundled runtime classes.' );
