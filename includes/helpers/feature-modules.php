<?php
/**
 * Feature module toggles. Lightweight enabled checks for opt-in modules. Implementation files are required only
 * from erankly_bootstrap() when the matching toggle is on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_redirects_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_redirects' ) );
}

function erankly_sitemap_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_sitemap', 0 );
}

function erankly_custom_code_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_custom_code' ) );
}
