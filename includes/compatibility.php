<?php
/** Compatibility guards. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/compatibility-legacy.php';

/**
 * Returns the active plugins that can own frontend SEO output. The structured result lets migration screens name
 * the source that must be deactivated without changing the long-standing boolean compatibility API.
 *
 * @return array<int,array{slug:string,label:string}>
 */
function erankly_external_seo_head_owners(): array {
	static $owners = null;

	if ( is_array( $owners ) ) {
		return $owners;
	}

	$definitions = array(
		'yoast'             => array(
			'label'     => 'Yoast SEO',
			'constants' => array( 'WPSEO_VERSION', 'WPSEO_PREMIUM_VERSION' ),
		),
		'rankmath'          => array(
			'label'     => 'Rank Math',
			'constants' => array( 'RANK_MATH_VERSION', 'RANK_MATH_PRO_VERSION' ),
		),
		'aioseo'            => array(
			'label'     => 'All in One SEO',
			'constants' => array( 'AIOSEO_VERSION' ),
		),
		'seopress'          => array(
			'label'     => 'SEOPress',
			'constants' => array( 'SEOPRESS_VERSION' ),
		),
		'the-seo-framework' => array(
			'label'     => 'The SEO Framework',
			'constants' => array( 'THE_SEO_FRAMEWORK_VERSION' ),
		),
		'slim-seo'          => array(
			'label'     => 'Slim SEO',
			'constants' => array( 'SLIM_SEO_VER', 'SLIM_SEO_VERSION' ),
		),
	);
	$owners      = array();

	foreach ( $definitions as $slug => $definition ) {
		foreach ( $definition['constants'] as $constant ) {
			if ( defined( $constant ) ) {
				$owners[] = array(
					'slug'  => $slug,
					'label' => $definition['label'],
				);
				break;
			}
		}
	}

	return $owners;
}

function erankly_detect_external_seo_head_owner(): bool {
	return array() !== erankly_external_seo_head_owners();
}

function erankly_should_output_head(): bool {
	$should_output = erankly_is_frontend_html_request() && ! erankly_detect_external_seo_head_owner();

	/** @param bool $should_output True to render metadata. */
	return (bool) apply_filters( 'erankly_enable_head_output', $should_output );
}

function erankly_localize_url( string $url ): string {
	$provider    = erankly_get_multilingual_provider();
	$context     = erankly_get_multilingual_context();
	$provider_id = $provider instanceof ERankly_Multilingual_Provider_Interface ? $provider->get_id() : '';

	if ( $provider instanceof ERankly_Multilingual_Provider_Interface && $provider->is_enabled() ) {
		try {
			$url = $provider->localize_url( $url, $context );
		} catch ( Throwable ) {
			$url = '';
		}
	}

	return (string) apply_filters( 'erankly_localized_url', $url, $context, $provider_id );
}

function erankly_is_woocommerce_active(): bool {
	return function_exists( 'wc_get_product' );
}

function erankly_woocommerce_structured_data_enabled(): bool {
	$enabled = erankly_is_woocommerce_active() && class_exists( 'WC_Structured_Data' );

	/** @param bool $enabled Whether WooCommerce Product JSON-LD is active. */
	return (bool) apply_filters( 'erankly_woocommerce_structured_data_enabled', $enabled );
}

function erankly_should_render_woocommerce_product_schema( int $post_id ): bool {
	$should_render = ! erankly_woocommerce_structured_data_enabled();

	/** @param bool $should_render Whether EasyRankly should render Product schema. */
	return (bool) apply_filters( 'erankly_render_woocommerce_product_schema', $should_render, $post_id );
}

/**
 * Preserves the public product-data API while loading its implementation only when WooCommerce is active.
 *
 * @return array<string,mixed>
 */
function erankly_get_woocommerce_product_data( int $post_id ): array {
	if ( ! erankly_is_woocommerce_active() ) {
		return array();
	}

	require_once ERANKLY_PATH . 'includes/compatibility-woocommerce.php';

	return erankly_build_woocommerce_product_data( $post_id );
}

/**
 * Determines whether EasyRankly's sitemaps should be suppressed. When a known SEO plugin that ships its own
 * sitemap system is active the virtual video/news sitemaps served by EasyRankly must not run concurrently. Site
 * admins can override with the {@see 'erankly_enable_sitemaps_with_external_seo'} filter.
 *
 * @return bool True when EasyRankly should suppress its own sitemap output.
 */
function erankly_should_suppress_sitemaps(): bool {
	$suppress = erankly_detect_external_seo_head_owner();

	/**
	 * Filters whether EasyRankly suppresses sitemap integration when another
	 * SEO plugin owns the output. This is the unambiguous public hook.
	 *
	 * @param bool $suppress True to suppress EasyRankly sitemap integration.
	 */
	$suppress = (bool) apply_filters( 'erankly_suppress_sitemaps_with_external_seo', $suppress );

	/**
	 * Legacy compatibility hook. Despite its historical name, its boolean is
	 * still the suppression state. New integrations should use
	 * `erankly_suppress_sitemaps_with_external_seo` above.
	 *
	 * @param bool $suppress True to suppress EasyRankly sitemaps.
	 */
	return (bool) apply_filters( 'erankly_enable_sitemaps_with_external_seo', $suppress );
}

/**
 * Determines whether EasyRankly should register and serve its sitemap output. Keeping this decision in one
 * function prevents persisted rewrite rules from outliving the renderer when another SEO plugin takes ownership.
 *
 * @return bool True when EasyRankly sitemap routes and renderers should run.
 */
function erankly_should_serve_sitemaps(): bool {
	return erankly_sitemap_enabled() && ! erankly_should_suppress_sitemaps();
}

/** Renders an admin notice when EasyRankly's head/sitemap output is disabled because another SEO plugin is active. */
function erankly_compatibility_notice_external_seo(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! erankly_detect_external_seo_head_owner() ) {
		return;
	}

	$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	$tab       = isset( $_GET['erankly_tab'] ) ? sanitize_key( wp_unslash( $_GET['erankly_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	$report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report context.
	if ( 'erankly' === $page && 'import-export' === $tab && '' !== $report_id ) {
		// The migration assistant already explains the conflict and the exact
		// next action; a second global notice would contradict that hierarchy.
		return;
	}

	$screen = get_current_screen();

	// Limit to EasyRankly pages and the plugin list to avoid polluting all admin screens.
	if ( $screen instanceof WP_Screen ) {
		$show = str_contains( (string) $screen->id, 'erankly' )
			|| 'plugins' === $screen->base;

		if ( ! $show ) {
			return;
		}
	}

	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<?php
			esc_html_e(
				'EasyRankly: another SEO plugin is active. Head metadata (title, meta description, canonical, Open Graph, Schema.org) and sitemap output are disabled to avoid conflicts. Redirects and breadcrumbs continue to work.',
				'easyrankly'
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'erankly_compatibility_notice_external_seo' );
