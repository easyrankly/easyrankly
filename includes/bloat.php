<?php
/**
 * WordPress bloat removal.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard cap applied when "Limit post revisions" is enabled.
 *
 * Override in wp-config.php before the plugin loads if needed.
 */
if ( ! defined( 'ERANKLY_BLOAT_REVISIONS_LIMIT' ) ) {
	define( 'ERANKLY_BLOAT_REVISIONS_LIMIT', 5 );
}

/**
 * Admin Heartbeat interval (seconds) when "Limit Heartbeat in admin" is enabled.
 */
if ( ! defined( 'ERANKLY_BLOAT_HEARTBEAT_ADMIN_INTERVAL' ) ) {
	define( 'ERANKLY_BLOAT_HEARTBEAT_ADMIN_INTERVAL', 60 );
}

/**
 * Registers all bloat-removal hooks based on saved settings.
 *
 * @return void
 */
function erankly_bloat_bootstrap(): void {
	$settings = erankly_get_stored_settings();

	if ( ! empty( $settings['bloat_remove_emoji'] ) ) {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'wp_resource_hints', 'erankly_bloat_remove_emoji_dns_prefetch', 10, 2 );
		add_filter( 'tiny_mce_plugins', 'erankly_bloat_disable_emoji_tinymce' );
	}

	if ( ! empty( $settings['bloat_remove_generator'] ) ) {
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
	}

	if ( ! empty( $settings['bloat_remove_feed_links'] ) ) {
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
	}

	if ( ! empty( $settings['bloat_remove_rsd_link'] ) ) {
		remove_action( 'wp_head', 'rsd_link' );
	}

	if ( ! empty( $settings['bloat_remove_wlwmanifest'] ) ) {
		remove_action( 'wp_head', 'wlwmanifest_link' );
	}

	if ( ! empty( $settings['bloat_remove_shortlink'] ) ) {
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
	}

	if ( ! empty( $settings['bloat_remove_rest_link'] ) ) {
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	}

	if ( ! empty( $settings['bloat_remove_oembed'] ) ) {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
	}

	if ( ! empty( $settings['bloat_remove_wp_embed'] ) ) {
		add_action( 'wp_enqueue_scripts', 'erankly_bloat_remove_wp_embed', 100 );
		add_action( 'wp_footer', 'erankly_bloat_remove_wp_embed', 1 );
	}

	if ( ! empty( $settings['bloat_remove_adjacent_posts'] ) ) {
		// Unused in core since 5.6, but still remove if a theme/plugin re-hooks it.
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
	}

	if ( ! empty( $settings['bloat_remove_jquery_migrate'] ) ) {
		add_action( 'wp_default_scripts', 'erankly_bloat_remove_jquery_migrate' );
	}

	if ( ! empty( $settings['bloat_disable_self_pingbacks'] ) ) {
		add_action( 'pre_ping', 'erankly_bloat_disable_self_pingbacks' );
	}

	if ( ! empty( $settings['bloat_disable_trackbacks'] ) ) {
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'pre_option_default_ping_status', 'erankly_bloat_closed_ping_status' );
		add_filter( 'xmlrpc_methods', 'erankly_bloat_remove_pingback_methods' );
		add_filter( 'wp_headers', 'erankly_bloat_remove_x_pingback_header' );
	}

	if ( ! empty( $settings['bloat_remove_dashicons'] ) ) {
		add_action( 'wp_enqueue_scripts', 'erankly_bloat_remove_dashicons', 100 );
	}

	if ( ! empty( $settings['bloat_disable_heartbeat'] ) ) {
		add_action( 'wp_enqueue_scripts', 'erankly_bloat_disable_heartbeat', 1 );
	}

	if ( ! empty( $settings['bloat_limit_heartbeat_admin'] ) ) {
		add_filter( 'heartbeat_settings', 'erankly_bloat_limit_heartbeat_admin' );
	}

	if ( ! empty( $settings['bloat_disable_xmlrpc'] ) ) {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', 'erankly_bloat_remove_pingback_methods' );
		add_filter( 'wp_headers', 'erankly_bloat_remove_x_pingback_header' );
	}

	if ( ! empty( $settings['bloat_remove_global_styles'] ) ) {
		erankly_bloat_remove_global_styles();
	}

	if ( ! empty( $settings['bloat_remove_duotone'] ) ) {
		erankly_bloat_remove_duotone();
	}

	if ( ! empty( $settings['bloat_remove_block_library_css'] ) ) {
		add_action( 'wp_enqueue_scripts', 'erankly_bloat_remove_block_library_css', 100 );
	}

	if ( ! empty( $settings['bloat_limit_revisions'] ) ) {
		add_filter( 'wp_revisions_to_keep', 'erankly_bloat_limit_revisions', 10, 2 );
	}

	if ( ! empty( $settings['bloat_disable_speculative_loading'] ) && function_exists( 'wp_get_speculation_rules_configuration' ) ) {
		add_filter( 'wp_speculation_rules_configuration', '__return_null' );
	}
}

/**
 * Removes emoji DNS prefetch hints.
 *
 * @param array<int,mixed> $urls          Resource hint URLs.
 * @param string           $relation_type Relation type.
 * @return array<int,mixed>
 */
function erankly_bloat_remove_emoji_dns_prefetch( array $urls, string $relation_type ): array {
	if ( 'dns-prefetch' !== $relation_type ) {
		return $urls;
	}

	return array_values(
		array_filter(
			$urls,
			static function ( $url ) {
				$url = is_array( $url ) ? ( $url['href'] ?? '' ) : (string) $url;
				return false === strpos( $url, 'twemoji' ) && false === strpos( $url, 's.w.org' );
			}
		)
	);
}

/**
 * Removes the emoji TinyMCE plugin.
 *
 * @param array<int,string> $plugins TinyMCE plugins list.
 * @return array<int,string>
 */
function erankly_bloat_disable_emoji_tinymce( array $plugins ): array {
	return array_values( array_filter( $plugins, static fn( string $p ) => 'wpemoji' !== $p ) );
}

/**
 * Dequeues the wp-embed script on the frontend.
 *
 * @return void
 */
function erankly_bloat_remove_wp_embed(): void {
	if ( is_admin() ) {
		return;
	}

	wp_dequeue_script( 'wp-embed' );
	wp_deregister_script( 'wp-embed' );
}

/**
 * Removes jQuery Migrate from the jQuery dependency chain on the frontend.
 *
 * @param WP_Scripts $scripts Scripts registry.
 * @return void
 */
function erankly_bloat_remove_jquery_migrate( WP_Scripts $scripts ): void {
	if ( is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
		return;
	}

	$jquery = $scripts->registered['jquery'];

	if ( $jquery->deps ) {
		$jquery->deps = array_values(
			array_filter( $jquery->deps, static fn( string $dep ) => 'jquery-migrate' !== $dep )
		);
	}
}

/**
 * Removes self-referencing URLs from the pingback list.
 *
 * @param array<int,string> $links Pingback URLs (passed by reference).
 * @return void
 */
function erankly_bloat_disable_self_pingbacks( array &$links ): void {
	$home = (string) get_option( 'home' );

	foreach ( $links as $key => $link ) {
		if ( str_starts_with( (string) $link, $home ) ) {
			unset( $links[ $key ] );
		}
	}
}

/**
 * Forces the default ping status option to closed.
 *
 * @return string
 */
function erankly_bloat_closed_ping_status(): string {
	return 'closed';
}

/**
 * Dequeues Dashicons for non-logged-in users.
 *
 * @return void
 */
function erankly_bloat_remove_dashicons(): void {
	if ( ! is_user_logged_in() ) {
		wp_dequeue_style( 'dashicons' );
		wp_deregister_style( 'dashicons' );
	}
}

/**
 * Dequeues the WordPress Heartbeat script on the frontend.
 *
 * @return void
 */
function erankly_bloat_disable_heartbeat(): void {
	wp_dequeue_script( 'heartbeat' );
	wp_deregister_script( 'heartbeat' );
}

/**
 * Slows Heartbeat in wp-admin without disabling it (keeps autosave / locking).
 *
 * @param array<string,mixed> $settings Heartbeat settings.
 * @return array<string,mixed>
 */
function erankly_bloat_limit_heartbeat_admin( array $settings ): array {
	if ( ! is_admin() ) {
		return $settings;
	}

	$interval = (int) ERANKLY_BLOAT_HEARTBEAT_ADMIN_INTERVAL;
	if ( $interval < 15 ) {
		$interval = 15;
	}

	$settings['interval'] = $interval;

	return $settings;
}

/**
 * Removes classic-theme-styles and global-styles on classic themes.
 *
 * Block themes rely on global styles for design tokens, so they are left alone.
 *
 * @return void
 */
function erankly_bloat_remove_global_styles(): void {
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles' );
	remove_action( 'enqueue_block_assets', 'wp_enqueue_classic_theme_styles' );

	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		return;
	}

	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
	remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
}

/**
 * Unhooks frontend duotone SVG / CSS output without touching the block editor.
 *
 * @return void
 */
function erankly_bloat_remove_duotone(): void {
	if ( ! class_exists( 'WP_Duotone', false ) ) {
		return;
	}

	remove_filter( 'render_block', array( 'WP_Duotone', 'render_duotone_support' ), 10 );
	remove_action( 'wp_enqueue_scripts', array( 'WP_Duotone', 'output_block_styles' ), 9 );
	remove_action( 'wp_enqueue_scripts', array( 'WP_Duotone', 'output_global_styles' ), 11 );
	remove_action( 'wp_footer', array( 'WP_Duotone', 'output_footer_assets' ), 10 );
}

/**
 * Dequeues the combined block library CSS when it is safe to do so.
 *
 * Skips block themes and any singular view whose content contains blocks.
 * Archives and other multi-post views are left unchanged.
 *
 * @return void
 */
function erankly_bloat_remove_block_library_css(): void {
	if ( is_admin() ) {
		return;
	}

	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		return;
	}

	if ( ! is_singular() ) {
		return;
	}

	$post = get_post();
	if ( ! $post instanceof WP_Post || has_blocks( $post ) ) {
		return;
	}

	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'classic-theme-styles' );
}

/**
 * Caps stored revisions without overriding a stricter existing limit.
 *
 * @param int     $num  Current revisions-to-keep value (-1 = unlimited).
 * @param WP_Post $post Post object.
 * @return int
 */
function erankly_bloat_limit_revisions( $num, $post ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the filter signature.
	$num   = (int) $num;
	$limit = (int) ERANKLY_BLOAT_REVISIONS_LIMIT;
	if ( $limit < 1 ) {
		$limit = 5;
	}

	// Honour a stricter existing cap (including 0 = revisions disabled).
	if ( $num >= 0 && $num < $limit ) {
		return $num;
	}

	return $limit;
}

/**
 * Removes the pingback methods from the XML-RPC interface.
 *
 * The `xmlrpc_enabled` filter only blocks methods that require
 * authentication; the unauthenticated pingback methods must be
 * unregistered separately.
 *
 * @param array<string,mixed> $methods Registered XML-RPC methods.
 * @return array<string,mixed>
 */
function erankly_bloat_remove_pingback_methods( array $methods ): array {
	unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

	return $methods;
}

/**
 * Removes the X-Pingback header from frontend responses.
 *
 * @param array<string,string> $headers HTTP headers.
 * @return array<string,string>
 */
function erankly_bloat_remove_x_pingback_header( array $headers ): array {
	unset( $headers['X-Pingback'] );

	return $headers;
}
