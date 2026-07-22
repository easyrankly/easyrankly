<?php
/**
 * EasyRankly 2.x bundled multilingual fallback provider.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter around the legacy Multisite implementation.
 *
 * Loading this adapter does not load any ERankly_ML_* runtime class. Those
 * files are required only if this provider wins selection and is enabled.
 */
final class ERankly_Bundled_Multilingual_Provider implements ERankly_Multilingual_Provider_Interface {
	/** Whether legacy hooks were registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/** Selected legacy resolver, received only after boot.
	 *
	 * @var object|null
	 */
	private ?object $resolver = null;

	/** {@inheritDoc} */
	public function get_id(): string {
		return 'easyrankly-bundled-multilingual';
	}

	/** {@inheritDoc} */
	public function get_version(): string {
		return ERANKLY_VERSION;
	}

	/** {@inheritDoc} */
	public function get_api_version(): int {
		return ERANKLY_EXTENSION_API_VERSION;
	}

	/** {@inheritDoc} */
	public function get_priority(): int {
		return -100;
	}

	/** {@inheritDoc} */
	public function get_topology(): string {
		return 'network-legacy';
	}

	/** {@inheritDoc} */
	public function preflight(): bool|WP_Error {
		$owners = array();
		$probes = array(
			'polylang'          => array( 'POLYLANG_VERSION', 'PLL_Model' ),
			'wpml'              => array( 'ICL_SITEPRESS_VERSION', 'SitePress' ),
			'translatepress'    => array( 'TRP_PLUGIN_VERSION', 'TRP_Translate_Press' ),
			'multilingualpress' => array( 'MULTILINGUALPRESS_VERSION', 'Inpsyde\\MultilingualPress\\Framework\\PluginProperties' ),
		);

		foreach ( $probes as $owner => $symbols ) {
			if ( defined( $symbols[0] ) || class_exists( $symbols[1], false ) ) {
				$owners[] = $owner;
			}
		}

		$owners = array_values( array_unique( array_filter( (array) apply_filters( 'erankly_external_multilingual_owners', $owners ) ) ) );
		if ( $owners ) {
			return new WP_Error(
				'erankly_multilingual_owner_conflict',
				__( 'Another multilingual owner is active. The EasyRankly bundled provider was not started.', 'easyrankly' ),
				array(
					'fallback_allowed' => false,
					'owner_state'      => 'external-conflict',
					'retryable'        => false,
					'owners'           => $owners,
				)
			);
		}

		$marker = erankly_ml_get_storage_owner_marker();
		if ( ! erankly_ml_bundled_runtime_allowed( $marker ) ) {
			$state = (string) ( $marker['state'] ?? 'unknown' );

			return new WP_Error(
				'erankly_bundled_storage_not_owned',
				__( 'Multilingual storage is owned by an add-on or an unfinished ownership journal. Reactivate the owner or complete a verified rollback.', 'easyrankly' ),
				array(
					'fallback_allowed' => false,
					'owner_state'      => $state,
					'retryable'        => in_array( $state, array( 'pending', 'ready', 'rollback_ready' ), true ),
				)
			);
		}

		return true;
	}

	/** {@inheritDoc} */
	public function is_enabled(): bool {
		return is_multisite()
			&& erankly_ml_bundled_runtime_allowed()
			&& erankly_multilingual_enabled();
	}

	/** {@inheritDoc} */
	public function register_hooks(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		if ( ! $this->is_enabled() ) {
			return;
		}

		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/multilingual.php';
		erankly_ml_boot( array( $this, 'set_runtime_resolver' ) );

		add_filter( 'erankly_settings_tabs', array( $this, 'register_settings_tab' ), 10, 2 );
		add_action( 'erankly_render_settings_tab_multilingual', array( $this, 'render_settings_tab' ), 10, 1 );
	}

	/**
	 * Receives the selected legacy resolver without publishing a global.
	 *
	 * @param object $resolver Selected legacy resolver.
	 */
	public function set_runtime_resolver( object $resolver ): void {
		$this->resolver = $resolver;
	}

	/** {@inheritDoc} */
	public function get_context(): array {
		$kind       = 'other';
		$route_kind = 'other';
		$object_id  = 0;
		$subtype    = '';

		if ( is_front_page() ) {
			$kind       = 'home';
			$route_kind = 'home';
		} elseif ( is_home() ) {
			$kind       = 'posts_page';
			$route_kind = 'posts_page';
			$object_id  = (int) get_option( 'page_for_posts', 0 );
			$subtype    = $object_id > 0 ? 'page' : '';
		} elseif ( is_singular() ) {
			$kind       = 'post';
			$route_kind = 'singular';
			$object_id  = get_queried_object_id();
			$subtype    = (string) get_post_type( $object_id );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$kind       = 'term';
				$route_kind = 'archive';
				$object_id  = $term->term_id;
				$subtype    = $term->taxonomy;
			}
		} elseif ( is_archive() ) {
			$kind       = 'archive';
			$route_kind = 'archive';
		} elseif ( is_search() ) {
			$route_kind = 'search';
		} elseif ( is_404() ) {
			$route_kind = '404';
		} elseif ( is_feed() ) {
			$route_kind = 'feed';
		}

		$blog_id  = get_current_blog_id();
		$hreflang = class_exists( 'ERankly_ML_Sites', false ) ? ERankly_ML_Sites::get_hreflang( $blog_id ) : '';

		return array(
			'language_id'    => 'network:' . get_current_network_id() . ':' . $blog_id,
			'hreflang'       => $hreflang,
			'kind'           => $kind,
			'route_kind'     => $route_kind,
			'object_id'      => $object_id,
			'object_subtype' => $subtype,
			'blog_id'        => $blog_id,
			'url'            => function_exists( 'erankly_current_url' ) ? erankly_current_url() : home_url( '/' ),
			'locale'         => get_locale(),
			'is_preview'     => is_preview(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $context   Request context.
	 * @param bool                $navigable Whether only navigable alternates are requested.
	 */
	public function get_alternates( array $context, bool $navigable ): array {
		unset( $context );

		if ( ! $this->is_enabled() || ! is_object( $this->resolver ) ) {
			return array();
		}

		if ( $navigable && method_exists( $this->resolver, 'resolve_navigable' ) ) {
			return (array) $this->resolver->resolve_navigable( array() );
		}

		return method_exists( $this->resolver, 'resolve' ) ? (array) $this->resolver->resolve( array() ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $url     Source URL.
	 * @param array<string,mixed> $context Request context.
	 */
	public function localize_url( string $url, array $context ): string {
		unset( $context );

		return $url;
	}

	/**
	 * Adds the bundled UI through the same public descriptor available to add-ons.
	 *
	 * @param array<string,mixed> $tabs           Existing descriptors.
	 * @param array<string,mixed> $screen_context Screen context.
	 * @return array<string,mixed>
	 */
	public function register_settings_tab( array $tabs, array $screen_context ): array {
		if ( 'network' !== ( $screen_context['scope'] ?? '' ) ) {
			return $tabs;
		}

		$tabs['multilingual'] = array(
			'label'      => __( 'Multilingual', 'easyrankly' ),
			'capability' => 'manage_network_options',
			'scope'      => 'network',
			'position'   => 70,
		);

		return $tabs;
	}

	/**
	 * Renders the legacy settings UI behind the generic action.
	 *
	 * @param array<string,mixed> $screen_context Screen context.
	 */
	public function render_settings_tab( array $screen_context ): void {
		unset( $screen_context );

		if ( function_exists( 'erankly_ml_render_network_panel' ) ) {
			erankly_ml_render_network_panel();
		}
	}
}
