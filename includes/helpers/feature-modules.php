<?php
/**
 * Feature module toggles.
 *
 * Lightweight enabled checks for opt-in modules. Implementation files are
 * required only from erankly_bootstrap() when the matching toggle is on.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the redirect manager module is enabled.
 *
 * @return bool
 */
function erankly_redirects_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_redirects' ) );
}

/**
 * Whether the sitemap module is enabled.
 *
 * @return bool
 */
function erankly_sitemap_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_sitemap', 0 );
}
