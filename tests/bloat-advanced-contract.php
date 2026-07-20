<?php
/**
 * Standalone contract coverage for advanced bloat cleanups.
 *
 * Run: php tests/bloat-advanced-contract.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress stubs.

define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'ERANKLY_BLOAT_REVISIONS_LIMIT' ) ) {
	define( 'ERANKLY_BLOAT_REVISIONS_LIMIT', 5 );
}

if ( ! defined( 'ERANKLY_BLOAT_HEARTBEAT_ADMIN_INTERVAL' ) ) {
	define( 'ERANKLY_BLOAT_HEARTBEAT_ADMIN_INTERVAL', 60 );
}

class WP_Post {
	/** @var string */
	public $post_type = 'post';
}

$GLOBALS['erankly_is_admin'] = false;

function is_admin(): bool {
	return ! empty( $GLOBALS['erankly_is_admin'] );
}

require_once dirname( __DIR__ ) . '/includes/bloat.php';

/**
 * @param string $message Assertion message.
 * @param bool   $condition Condition that must be true.
 */
function erankly_bloat_assert( string $message, bool $condition ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	echo "OK: {$message}\n";
}

$post = new WP_Post();

erankly_bloat_assert(
	'revisions limit caps unlimited revisions to 5',
	5 === erankly_bloat_limit_revisions( -1, $post )
);

erankly_bloat_assert(
	'revisions limit caps a higher existing limit to 5',
	5 === erankly_bloat_limit_revisions( 20, $post )
);

erankly_bloat_assert(
	'revisions limit keeps a stricter existing limit',
	3 === erankly_bloat_limit_revisions( 3, $post )
);

erankly_bloat_assert(
	'revisions limit keeps revisions disabled (0)',
	0 === erankly_bloat_limit_revisions( 0, $post )
);

$GLOBALS['erankly_is_admin'] = true;
$heartbeat = erankly_bloat_limit_heartbeat_admin( array( 'interval' => 15 ) );
erankly_bloat_assert(
	'admin heartbeat interval is raised to 60 seconds',
	60 === (int) ( $heartbeat['interval'] ?? 0 )
);

$GLOBALS['erankly_is_admin'] = false;
$heartbeat_front = erankly_bloat_limit_heartbeat_admin( array( 'interval' => 15 ) );
erankly_bloat_assert(
	'frontend heartbeat interval is left unchanged',
	15 === (int) ( $heartbeat_front['interval'] ?? 0 )
);

// Mirrored from admin/settings-page.php — new advanced options must never join this set.
$safe_keys = array(
	'bloat_remove_emoji',
	'bloat_remove_generator',
	'bloat_remove_rsd_link',
	'bloat_remove_wlwmanifest',
	'bloat_remove_shortlink',
	'bloat_remove_rest_link',
	'bloat_disable_self_pingbacks',
);

$advanced_keys = array(
	'bloat_remove_wp_embed',
	'bloat_remove_adjacent_posts',
	'bloat_limit_heartbeat_admin',
	'bloat_remove_global_styles',
	'bloat_remove_duotone',
	'bloat_disable_trackbacks',
	'bloat_limit_revisions',
	'bloat_remove_block_library_css',
	'bloat_disable_speculative_loading',
);

foreach ( $advanced_keys as $key ) {
	erankly_bloat_assert(
		"{$key} must stay out of the simplified-mode safe set",
		! in_array( $key, $safe_keys, true )
	);
}

$defaults_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/helpers/defaults.php' );
$panels_source   = (string) file_get_contents( dirname( __DIR__ ) . '/admin/settings/panels.php' );

foreach ( $advanced_keys as $key ) {
	erankly_bloat_assert(
		"{$key} defaults to off in erankly_default_settings()",
		1 === preg_match( '/' . preg_quote( "'{$key}'", '/' ) . '\s*=>\s*0/', $defaults_source )
	);

	erankly_bloat_assert(
		"{$key} is exposed in advanced bloat UI without the safe marker",
		false !== strpos( $panels_source, "[{$key}]" )
		&& ! preg_match(
			'/name="[^"]*\[' . preg_quote( $key, '/' ) . '\]"[^>]*data-erankly-bloat-safe/',
			$panels_source
		)
	);
}

echo "All bloat advanced contract checks passed.\n";
