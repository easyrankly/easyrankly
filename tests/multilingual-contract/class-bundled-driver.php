<?php
// phpcs:ignoreFile -- Test-only adapter for the embedded EasyRankly 2.0 module.
/**
 * Bundled provider driver for the shared M1 contract.
 *
 * @package EasyRankly
 */

final class ERankly_ML_Contract_Bundled_Driver implements ERankly_ML_Contract_Driver {
	private ERankly_ML_Repository $repository;

	public function __construct() {
		if ( ! class_exists( 'ERankly_ML_Repository' ) ) {
			throw new RuntimeException( 'The bundled multilingual provider is not booted.' );
		}
		$this->repository = new ERankly_ML_Repository();
	}

	public function id(): string {
		return 'easyrankly-bundled-multilingual';
	}

	public function save_site_map( array $map ): void {
		ERankly_ML_Sites::save( $map );
	}

	public function site_map(): array {
		return ERankly_ML_Sites::get_all();
	}

	public function default_blog_id(): int {
		return ERankly_ML_Sites::get_default_blog_id();
	}

	public function locale_fallback( int $blog_id, string $locale ): string {
		$map             = ERankly_ML_Sites::get_all();
		$original_locale = (string) get_blog_option( $blog_id, 'WPLANG', '' );
		$fallback_map    = $map;
		$fallback_map[ $blog_id ]['hreflang'] = '';

		update_blog_option( $blog_id, 'WPLANG', $locale );
		update_site_option( 'erankly_ml_sites', $fallback_map );
		try {
			return ERankly_ML_Sites::get_hreflang( $blog_id );
		} finally {
			update_site_option( 'erankly_ml_sites', $map );
			update_blog_option( $blog_id, 'WPLANG', $original_locale );
		}
	}

	public function link( int $group_id, int $blog_id, string $object_type, int $object_id ): int {
		return $this->repository->link( $group_id, $blog_id, $object_type, $object_id );
	}

	public function unlink( int $blog_id, string $object_type, int $object_id ): void {
		$this->repository->unlink( $blog_id, $object_type, $object_id );
	}

	public function find_group_id( int $blog_id, string $object_type, int $object_id ): int {
		return $this->repository->find_group_id( $blog_id, $object_type, $object_id );
	}

	public function group_for( int $blog_id, string $object_type, int $object_id ): array {
		return $this->repository->get_group_for_object( $blog_id, $object_type, $object_id );
	}

	public function seo_alternates(): array {
		return erankly_get_hreflang_alternates();
	}

	public function navigable_alternates(): array {
		return erankly_get_navigable_hreflang_alternates();
	}

	public function render_hreflang(): string {
		ob_start();
		erankly_render_hreflang_alternates();
		return (string) ob_get_clean();
	}

	public function render_full_head(): string {
		add_filter( 'erankly_enable_head_output', '__return_true' );
		erankly_bootstrap_frontend_modules();
		ob_start();
		erankly_render_head();
		erankly_render_hreflang_alternates();
		$html = (string) ob_get_clean();
		remove_filter( 'erankly_enable_head_output', '__return_true' );
		return $html;
	}

	public function render_shortcode( string $surface ): string {
		$shortcodes = array(
			'switcher' => '[erankly_language_switcher]',
			'notice'   => '[erankly_translation_notice]',
		);

		if ( ! isset( $shortcodes[ $surface ] ) ) {
			throw new InvalidArgumentException( 'Unknown bundled frontend surface: ' . $surface );
		}

		return do_shortcode( $shortcodes[ $surface ] );
	}

	public function frontend_contract(): array {
		return array(
			'asset_handle' => 'erankly-multilingual-frontend',
		);
	}

	public function rest_contract(): array {
		return array(
			'search_route'   => '/erankly/v1/ml/search',
			'settings_route' => '/erankly/v1/settings/multilingual',
			'editor_field'   => 'erankly_ml_links',
		);
	}

	public function attempt_cross_site_post_link( int $source_post_id, int $target_blog_id, int $target_post_id ): void {
		$admin = $GLOBALS['erankly_ml_admin'] ?? null;
		if ( ! $admin instanceof ERankly_ML_Admin ) {
			throw new RuntimeException( 'The bundled admin relation adapter is unavailable.' );
		}

		$post = get_post( $source_post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( 'The bundled cross-site mutation source is unavailable.' );
		}

		$admin->update_post_translations_rest_field(
			array( array( 'blog_id' => $target_blog_id, 'object_id' => $target_post_id, 'action' => 'link' ) ),
			$post
		);
	}

	public function storage_descriptor(): array {
		return array(
			'relation_table'  => str_ends_with( ERankly_ML_Repository::get_table_name(), 'erankly_ml_relations' ) ? '{base-prefix}erankly_ml_relations' : '{unexpected}',
			'relation_scope'  => 'network-shared',
			'registry_scope'  => 'network-option',
		);
	}

	public function ownership_snapshot(): array {
		erankly_ml_boot();
		erankly_ml_boot();
		$resolver_count = $this->resolver_count();

		$duplicate_resolver = new ERankly_ML_Resolver( new ERankly_ML_Repository() );
		add_filter( 'erankly_hreflang_alternates', array( $duplicate_resolver, 'resolve' ), 21 );
		$resolver_duplicate_detected = 1 !== $this->resolver_count();
		remove_filter( 'erankly_hreflang_alternates', array( $duplicate_resolver, 'resolve' ), 21 );

		$emitter_count     = $this->emitter_count();
		$duplicate_emitter = new class() {
			public function render_hreflang_duplicate(): void {}
		};
		add_action( 'wp_head', array( $duplicate_emitter, 'render_hreflang_duplicate' ), 2 );
		$emitter_duplicate_detected = 1 !== $this->emitter_count();
		remove_action( 'wp_head', array( $duplicate_emitter, 'render_hreflang_duplicate' ), 2 );

		return array(
			'resolver_count'              => $resolver_count,
			'resolver_duplicate_detected' => $resolver_duplicate_detected,
			'emitter_count'               => $emitter_count,
			'emitter_duplicate_detected'  => $emitter_duplicate_detected,
		);
	}

	private function resolver_count(): int {
		$provider = function_exists( 'erankly_get_multilingual_provider' ) ? erankly_get_multilingual_provider() : null;
		$count    = $provider instanceof ERankly_Multilingual_Provider_Interface && $this->id() === $provider->get_id() ? 1 : 0;

		return $count + erankly_ml_contract_count_callbacks(
			'erankly_hreflang_alternates',
			static fn( mixed $callback ): bool => is_array( $callback )
				&& isset( $callback[0], $callback[1] )
				&& 'resolve' === $callback[1]
				&& is_a( $callback[0], ERankly_ML_Resolver::class )
		);
	}

	private function emitter_count(): int {
		return erankly_ml_contract_count_callbacks(
			'wp_head',
			static function ( mixed $callback ): bool {
				if ( is_string( $callback ) ) {
					return 'erankly_render_hreflang_alternates' === $callback;
				}

				return is_array( $callback )
					&& isset( $callback[1] )
					&& str_contains( strtolower( (string) $callback[1] ), 'hreflang' );
			}
		);
	}
}
