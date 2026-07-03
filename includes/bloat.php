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
 * Registers all bloat-removal hooks based on saved settings.
 *
 * @return void
 */
function erankly_bloat_bootstrap(): void {
	$settings = erankly_get_settings();

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

	if ( ! empty( $settings['bloat_remove_jquery_migrate'] ) ) {
		add_action( 'wp_default_scripts', 'erankly_bloat_remove_jquery_migrate' );
	}

	if ( ! empty( $settings['bloat_disable_self_pingbacks'] ) ) {
		add_action( 'pre_ping', 'erankly_bloat_disable_self_pingbacks' );
	}

	if ( ! empty( $settings['bloat_remove_dashicons'] ) ) {
		add_action( 'wp_enqueue_scripts', 'erankly_bloat_remove_dashicons', 100 );
	}

	if ( ! empty( $settings['bloat_disable_heartbeat'] ) ) {
		add_action( 'wp_enqueue_scripts', 'erankly_bloat_disable_heartbeat', 1 );
	}

	if ( ! empty( $settings['bloat_disable_xmlrpc'] ) ) {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', 'erankly_bloat_remove_pingback_methods' );
		add_filter( 'wp_headers', 'erankly_bloat_remove_x_pingback_header' );
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
