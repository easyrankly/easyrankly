<?php
/**
 * Dependency-free regression tests for optional feature-module dependencies.
 *
 * Run: php tests/feature-module-dependencies.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress function stubs.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['erankly_feature_test_settings'] = array();

function erankly_get_setting( string $key, mixed $default_value = null ): mixed {
	return $GLOBALS['erankly_feature_test_settings'][ $key ] ?? $default_value;
}

require_once dirname( __DIR__ ) . '/includes/helpers/feature-modules.php';

function erankly_feature_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Feature module dependency test failed: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['erankly_feature_test_settings'] = array(
	'ai_enabled'             => 0,
	'enable_content_analysis' => 1,
	'enable_link_building'    => 1,
	'simplified_mode'         => 1,
);

erankly_feature_test_assert( ! erankly_link_building_enabled(), 'Internal link suggestions activated while AI was disabled.' );
erankly_feature_test_assert( erankly_content_analysis_enabled(), 'Content Analysis did not activate independently from simplified mode and AI availability.' );

$GLOBALS['erankly_feature_test_settings']['simplified_mode'] = 0;
erankly_feature_test_assert( erankly_content_analysis_enabled(), 'Content Analysis changed state when simplified mode changed.' );

$GLOBALS['erankly_feature_test_settings']['ai_enabled'] = 1;

erankly_feature_test_assert( erankly_link_building_enabled(), 'Internal link suggestions did not activate when both modules were enabled.' );

$GLOBALS['erankly_feature_test_settings']['enable_link_building'] = 0;

erankly_feature_test_assert( ! erankly_link_building_enabled(), 'Internal link suggestions activated without their own toggle.' );

$GLOBALS['erankly_feature_test_settings']['enable_content_analysis'] = 0;
erankly_feature_test_assert( ! erankly_content_analysis_enabled(), 'Content Analysis activated without its own toggle.' );

fwrite( STDOUT, "Feature module dependency tests passed.\n" );
