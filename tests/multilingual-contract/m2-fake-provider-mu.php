<?php
// phpcs:ignoreFile -- Disposable MU plugin proving add-on-first lazy runtime selection.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! interface_exists( 'ERankly_Multilingual_Provider_Interface', false ) ) {
			return;
		}

		$provider = new class() implements ERankly_Multilingual_Provider_Interface {
			private function count( string $method ): void {
				$GLOBALS['erankly_m2_fake_provider_counts'][ $method ] = (int) ( $GLOBALS['erankly_m2_fake_provider_counts'][ $method ] ?? 0 ) + 1;
			}
			public function get_id(): string { return 'm2-fake-provider'; }
			public function get_version(): string { return '1.0.0-test'; }
			public function get_api_version(): int { return 1; }
			public function get_priority(): int { return 100; }
			public function get_topology(): string { return 'network'; }
			public function preflight(): bool|WP_Error { return true; }
			public function is_enabled(): bool { return true; }
			public function register_hooks(): void { $this->count( 'register_hooks' ); }
			public function get_context(): array {
				$this->count( 'get_context' );
				return array(
					'language_id' => 'fake:it',
					'hreflang' => 'it',
					'kind' => 'other',
					'route_kind' => 'other',
					'object_id' => 0,
					'object_subtype' => '',
					'blog_id' => get_current_blog_id(),
					'url' => home_url( '/' ),
					'locale' => get_locale(),
					'is_preview' => false,
				);
			}
			public function get_alternates( array $context, bool $navigable ): array {
				$this->count( 'get_alternates' );
				unset( $context, $navigable );

				return array( 'it' => home_url( '/' ), 'x-default' => home_url( '/' ) );
			}
			public function localize_url( string $url, array $context ): string {
				$this->count( 'localize_url' );
				unset( $context );

				return add_query_arg( 'm2-language', 'it', $url );
			}
		};

		erankly_register_multilingual_provider( $provider );
	},
	1
);
