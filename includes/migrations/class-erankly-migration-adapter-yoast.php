<?php
/**
 * Yoast SEO and Yoast SEO Premium migration adapter.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Yoast Free/Premium adapter. */
final class ERankly_Migration_Adapter_Yoast extends ERankly_Migration_Adapter {
	/**
	 * Returns the source slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'yoast';
	}

	/**
	 * Returns the source label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Yoast SEO';
	}

	/**
	 * Returns the detected version.
	 *
	 * @return string
	 */
	public function version(): string {
		$version = $this->detect_version(
			'WPSEO_VERSION',
			array( 'wpseo_version' ),
			array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' )
		);
		if ( '' !== $version ) {
			return $version;
		}

		$options = get_option( 'wpseo' );
		return is_array( $options ) && isset( $options['version'] ) && is_scalar( $options['version'] ) ? sanitize_text_field( (string) $options['version'] ) : '';
	}

	/** Returns Free or Premium from code and certified paid storage. */
	public function edition(): string {
		$premium = defined( 'WPSEO_PREMIUM_VERSION' )
			|| ! empty( $this->installed_plugins( array( 'wordpress-seo-premium/wp-seo-premium.php' ) ) )
			|| is_array( get_option( 'wpseo-premium-redirects-base' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-plain' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-regex' ) )
			|| $this->has_meta( 'post', array( '_yoast_wpseo_redirect' ) );

		return $premium ? 'premium' : 'free';
	}

	/** Returns separate Yoast product/add-on profiles. */
	public function modules(): array {
		$plugins = $this->installed_plugins(
			array(
				'wordpress-seo-premium/wp-seo-premium.php',
				'wpseo-news/wpseo-news.php',
				'wpseo-video/video-seo.php',
				'wpseo-woocommerce/wpseo-woocommerce.php',
				'local-seo-for-wordpress/local-seo.php',
				'wpseo-local/local-seo.php',
			)
		);
		$map     = array(
			'wordpress-seo-premium/wp-seo-premium.php' => 'premium',
			'wpseo-news/wpseo-news.php'                => 'news',
			'wpseo-video/video-seo.php'                => 'video',
			'wpseo-woocommerce/wpseo-woocommerce.php'  => 'woocommerce',
			'local-seo-for-wordpress/local-seo.php'    => 'local',
			'wpseo-local/local-seo.php'                => 'local',
		);
		$modules = array();
		foreach ( array_keys( $plugins ) as $plugin ) {
			$modules[] = $map[ $plugin ];
		}
		if ( 'premium' === $this->edition() ) {
			$modules[] = 'redirects';
		}

		return array_values( array_unique( $modules ) );
	}

	/** Returns certification state for each detected Yoast product. */
	public function module_support(): array {
		$support = array();
		foreach ( $this->modules() as $module ) {
			$support[ $module ] = in_array( $module, array( 'premium', 'redirects' ), true ) ? 'supported' : 'review_required';
		}
		return $support;
	}

	/** Yoast versions covered by the certified meta/option signatures. */
	protected function supported_versions(): array {
		return array(
			'min' => '3.0.0',
			'max' => '28.999.999',
		);
	}

	/**
	 * Returns the Premium product proven by Yoast's redirect export signature.
	 *
	 * @param string $format Certified export format.
	 * @return array{edition:string,modules:array<int,string>,module_support:array<string,string>}
	 */
	protected function export_source_profile( string $format ): array {
		if ( 'yoast-redirects-csv' === $format ) {
			return array(
				'edition'        => 'premium',
				'modules'        => array( 'premium', 'redirects' ),
				'module_support' => array(
					'premium'   => 'supported',
					'redirects' => 'supported',
				),
			);
		}

		return parent::export_source_profile( $format );
	}

	/** Declares every Yoast storage surface consumed by this adapter. */
	protected function storage_definitions(): array {
		return array(
			'post_meta'              => array(
				'type'        => 'meta',
				'object_type' => 'post',
				'keys'        => $this->post_keys(),
				'prefixes'    => array( '_yoast_wpseo_primary_', '_yoast_wpseo_schema_' ),
			),
			'term_meta'              => array(
				'type'        => 'meta',
				'object_type' => 'term',
				'keys'        => $this->post_keys(),
				'prefixes'    => array( '_yoast_wpseo_schema_' ),
			),
			'user_meta'              => array(
				'type'        => 'meta',
				'object_type' => 'user',
				'keys'        => $this->user_keys(),
			),
			'legacy_taxonomy'        => array(
				'type'   => 'option',
				'option' => 'wpseo_taxonomy_meta',
				'shape'  => 'array',
			),
			'premium_redirects'      => array(
				'type'   => 'option',
				'option' => 'wpseo-premium-redirects-base',
				'shape'  => 'array',
			),
			'legacy_plain_redirects' => array(
				'type'   => 'option',
				'option' => 'wpseo-premium-redirects-export-plain',
				'shape'  => 'array',
			),
			'legacy_regex_redirects' => array(
				'type'   => 'option',
				'option' => 'wpseo-premium-redirects-export-regex',
				'shape'  => 'array',
			),
		);
	}

	/**
	 * Returns supported source capabilities.
	 *
	 * @return array<int,string>
	 */
	public function capabilities(): array {
		return array( 'posts', 'terms', 'authors', 'social', 'robots', 'schema', 'primary terms', 'focus keyphrases', 'cornerstone content', 'Premium redirects' );
	}

	/**
	 * Checks whether Yoast data exists.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( $this->uses_export_file() ) {
			return 'supported' === (string) $this->profile()['storage_status'];
		}

		return $this->has_meta( 'post', $this->post_keys(), array( '_yoast_wpseo_primary_', '_yoast_wpseo_schema_' ) )
			|| $this->has_meta( 'term', $this->post_keys(), array( '_yoast_wpseo_schema_' ) )
			|| is_array( get_option( 'wpseo_taxonomy_meta' ) )
			|| $this->has_meta( 'user', $this->user_keys() )
			|| is_array( get_option( 'wpseo-premium-redirects-base' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-plain' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-regex' ) );
	}

	/**
	 * Yields normalized content records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function content_records(): iterable {
		foreach ( $this->meta_objects( 'post', $this->post_keys(), array( '_yoast_wpseo_primary_', '_yoast_wpseo_schema_' ) ) as $record ) {
			$mapped = $this->map_meta( $record['meta'], false );
			if ( ! empty( $mapped ) ) {
				yield array(
					'object_type'      => 'post',
					'object_id'        => $record['id'],
					'meta'             => $mapped,
					'source_reference' => 'post:' . $record['id'],
				);
			}
		}

		// Current Yoast releases store taxonomy overrides in native term meta.
		// The legacy wpseo_taxonomy_meta option is scanned afterwards for sites
		// upgraded from older versions; the manager deduplicates overlapping rows.
		foreach ( $this->meta_objects( 'term', $this->post_keys(), array( '_yoast_wpseo_schema_' ) ) as $record ) {
			$mapped = $this->map_meta( $record['meta'], false );
			if ( ! empty( $mapped ) ) {
				yield array(
					'object_type'      => 'term',
					'object_id'        => $record['id'],
					'meta'             => $mapped,
					'source_reference' => 'term-meta:' . $record['id'],
				);
			}
		}

		$taxonomy_meta = get_option( 'wpseo_taxonomy_meta' );
		if ( is_array( $taxonomy_meta ) ) {
			foreach ( $taxonomy_meta as $taxonomy => $terms ) {
				if ( ! is_array( $terms ) ) {
					continue;
				}

				foreach ( $terms as $term_id => $meta ) {
					$term_id = absint( $term_id );
					if ( $term_id < 1 || ! is_array( $meta ) || ! get_term( $term_id ) instanceof WP_Term ) {
						continue;
					}

					$mapped = $this->map_meta( $meta, true );
					if ( ! empty( $mapped ) ) {
						yield array(
							'object_type'      => 'term',
							'object_id'        => $term_id,
							'meta'             => $mapped,
							'source_reference' => 'term:' . sanitize_key( (string) $taxonomy ) . ':' . $term_id,
						);
					}
				}
			}
		}

		foreach ( $this->meta_objects( 'user', $this->user_keys() ) as $record ) {
			$mapped = $this->map_user_meta( $record['meta'] );
			if ( ! empty( $mapped ) ) {
				yield array(
					'object_type'      => 'user',
					'object_id'        => $record['id'],
					'meta'             => $mapped,
					'source_reference' => 'user:' . $record['id'],
				);
			}
		}
	}

	/**
	 * Returns a keyset-paginated content page for the background worker.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
	 * @param int                 $limit  Maximum source objects to scan.
	 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
	 */
	public function content_batch( array $cursor, int $limit ): array {
		if ( $this->uses_export_file() ) {
			return ERankly_Migration_Export_Reader::content_batch( $this->export_file(), $this->slug(), $cursor, $limit );
		}

		$stages = array( 'post', 'term_meta', 'term_option', 'user' );
		$stage  = sanitize_key( (string) ( $cursor['stage'] ?? 'post' ) );
		$stage  = in_array( $stage, $stages, true ) ? $stage : 'post';
		$limit  = max( 1, min( 500, $limit ) );

		while ( true ) {
			if ( 'term_option' === $stage ) {
				$page = $this->taxonomy_option_batch( absint( $cursor['offset'] ?? 0 ), $limit );
				if ( $page['done'] ) {
					$stage  = 'user';
					$cursor = array(
						'stage'    => $stage,
						'after_id' => 0,
					);
				} else {
					$cursor = array(
						'stage'  => $stage,
						'offset' => $page['offset'],
					);
				}

				if ( $page['records'] || ! $page['done'] ) {
					return array(
						'records' => $page['records'],
						'cursor'  => $cursor,
						'done'    => false,
					);
				}
				continue;
			}

			$config = array(
				'post'      => array( 'post', $this->post_keys(), array( '_yoast_wpseo_primary_', '_yoast_wpseo_schema_' ), false, 'post:' ),
				'term_meta' => array( 'term', $this->post_keys(), array( '_yoast_wpseo_schema_' ), false, 'term-meta:' ),
				'user'      => array( 'user', $this->user_keys(), array(), true, 'user:' ),
			)[ $stage ];
			$page   = $this->meta_object_batch( $config[0], $config[1], $config[2], absint( $cursor['after_id'] ?? 0 ), $limit );
			$mapped = array();

			foreach ( $page['records'] as $record ) {
				$meta = $config[3] ? $this->map_user_meta( $record['meta'] ) : $this->map_meta( $record['meta'], false );
				if ( $meta ) {
					$mapped[] = array(
						'object_type'      => $config[0],
						'object_id'        => $record['id'],
						'meta'             => $meta,
						'source_reference' => $config[4] . $record['id'],
					);
				}
			}

			if ( $page['done'] ) {
				$index = array_search( $stage, $stages, true );
				if ( false === $index || count( $stages ) - 1 === $index ) {
					return array(
						'records' => $mapped,
						'cursor'  => array( 'stage' => 'done' ),
						'done'    => true,
					);
				}
				$stage  = $stages[ $index + 1 ];
				$cursor = array(
					'stage'    => $stage,
					'after_id' => 0,
				);
			} else {
				$cursor = array(
					'stage'    => $stage,
					'after_id' => $page['after_id'],
				);
			}

			if ( $mapped || ! $page['done'] ) {
				return array(
					'records' => $mapped,
					'cursor'  => $cursor,
					'done'    => false,
				);
			}
		}
	}

	/**
	 * Yields normalized Premium redirect records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function redirect_records(): iterable {
		$base = get_option( 'wpseo-premium-redirects-base' );
		if ( is_array( $base ) ) {
			foreach ( $base as $index => $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['origin'] ) ) {
					continue;
				}

				yield $this->redirect_from_values(
					(string) $entry['origin'],
					(string) ( $entry['url'] ?? '' ),
					(int) ( $entry['type'] ?? 301 ),
					'regex' === (string) ( $entry['format'] ?? '' ),
					'premium-base:' . sanitize_text_field( (string) $index )
				);
			}
		}

		foreach ( array(
			'plain' => false,
			'regex' => true,
		) as $kind => $is_regex ) {
			$legacy = get_option( 'wpseo-premium-redirects-export-' . $kind );
			if ( ! is_array( $legacy ) ) {
				continue;
			}

			foreach ( $legacy as $origin => $target ) {
				if ( is_array( $target ) ) {
					$origin = $target['origin'] ?? $origin;
					$url    = $target['url'] ?? $target['target'] ?? '';
					$type   = (int) ( $target['type'] ?? 301 );
				} else {
					$url  = $target;
					$type = 301;
				}

				if ( ! is_scalar( $origin ) || ! is_scalar( $url ) ) {
					continue;
				}

				yield $this->redirect_from_values( (string) $origin, (string) $url, $type, $is_regex, 'premium-' . $kind . ':' . md5( (string) $origin ) );
			}
		}

		// Premium also stores the redirect selected directly in a post editor.
		foreach ( $this->meta_objects( 'post', array( '_yoast_wpseo_redirect' ) ) as $record ) {
			$target = $this->value( $record['meta'], '_yoast_wpseo_redirect' );
			$source = get_permalink( $record['id'] );
			if ( '' === $target || ! is_string( $source ) ) {
				continue;
			}

			yield $this->redirect_from_values( $source, $target, 301, false, 'post-redirect:' . $record['id'] );
		}
	}

	/**
	 * Returns one resumable Yoast Premium redirect page.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
	 * @param int                 $limit  Maximum source records to scan.
	 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
	 */
	public function redirect_batch( array $cursor, int $limit ): array {
		if ( $this->uses_export_file() ) {
			return ERankly_Migration_Export_Reader::redirect_batch( $this->export_file(), $this->slug(), $cursor, $limit );
		}

		$stages = array( 'premium_base', 'legacy_plain', 'legacy_regex', 'post_redirect' );
		$stage  = sanitize_key( (string) ( $cursor['stage'] ?? 'premium_base' ) );
		$stage  = in_array( $stage, $stages, true ) ? $stage : 'premium_base';
		$limit  = max( 1, min( 500, $limit ) );

		while ( true ) {
			if ( 'post_redirect' === $stage ) {
				$page   = $this->meta_object_batch( 'post', array( '_yoast_wpseo_redirect' ), array(), absint( $cursor['after_id'] ?? 0 ), $limit );
				$mapped = array();
				foreach ( $page['records'] as $record ) {
					$target = $this->value( $record['meta'], '_yoast_wpseo_redirect' );
					$source = get_permalink( $record['id'] );
					if ( '' !== $target && is_string( $source ) ) {
						$mapped[] = $this->redirect_from_values( $source, $target, 301, false, 'post-redirect:' . $record['id'] );
					}
				}

				return array(
					'records' => $mapped,
					'cursor'  => $page['done'] ? array( 'stage' => 'done' ) : array(
						'stage'    => $stage,
						'after_id' => $page['after_id'],
					),
					'done'    => $page['done'],
				);
			}

			$page = $this->redirect_option_batch( $stage, absint( $cursor['offset'] ?? 0 ), $limit );
			if ( $page['done'] ) {
				$index  = array_search( $stage, $stages, true );
				$stage  = $stages[ $index + 1 ];
				$cursor = array(
					'stage'    => $stage,
					'after_id' => 0,
				);
			} else {
				$cursor = array(
					'stage'  => $stage,
					'offset' => $page['offset'],
				);
			}

			if ( $page['records'] || ! $page['done'] ) {
				return array(
					'records' => $page['records'],
					'cursor'  => $cursor,
					'done'    => false,
				);
			}
		}
	}

	/**
	 * Pages the monolithic legacy taxonomy option deterministically.
	 *
	 * @param int $offset Number of option entries already scanned.
	 * @param int $limit  Maximum entries to scan.
	 * @return array{records:array<int,array<string,mixed>>,offset:int,done:bool}
	 */
	private function taxonomy_option_batch( int $offset, int $limit ): array {
		$taxonomy_meta = get_option( 'wpseo_taxonomy_meta' );
		$taxonomy_meta = is_array( $taxonomy_meta ) ? $taxonomy_meta : array();
		ksort( $taxonomy_meta, SORT_STRING );
		$position = 0;
		$scanned  = 0;
		$records  = array();

		foreach ( $taxonomy_meta as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}
			uksort( $terms, static fn( mixed $left, mixed $right ): int => absint( $left ) <=> absint( $right ) );
			foreach ( $terms as $term_id => $meta ) {
				if ( $position++ < $offset ) {
					continue;
				}
				if ( $scanned >= $limit ) {
					return array(
						'records' => $records,
						'offset'  => $offset + $scanned,
						'done'    => false,
					);
				}
				++$scanned;
				$term_id = absint( $term_id );
				if ( $term_id < 1 || ! is_array( $meta ) || ! get_term( $term_id ) instanceof WP_Term ) {
					continue;
				}
				$mapped = $this->map_meta( $meta, true );
				if ( $mapped ) {
					$records[] = array(
						'object_type'      => 'term',
						'object_id'        => $term_id,
						'meta'             => $mapped,
						'source_reference' => 'term:' . sanitize_key( (string) $taxonomy ) . ':' . $term_id,
					);
				}
			}
		}

		return array(
			'records' => $records,
			'offset'  => $offset + $scanned,
			'done'    => true,
		);
	}

	/**
	 * Pages one Yoast Premium redirect option.
	 *
	 * @param string $stage  Option stage.
	 * @param int    $offset Number of entries already scanned.
	 * @param int    $limit  Maximum entries.
	 * @return array{records:array<int,array<string,mixed>>,offset:int,done:bool}
	 */
	private function redirect_option_batch( string $stage, int $offset, int $limit ): array {
		$option = array(
			'premium_base' => 'wpseo-premium-redirects-base',
			'legacy_plain' => 'wpseo-premium-redirects-export-plain',
			'legacy_regex' => 'wpseo-premium-redirects-export-regex',
		)[ $stage ];
		$values = get_option( $option );
		$values = is_array( $values ) ? $values : array();
		if ( 'premium_base' !== $stage ) {
			ksort( $values, SORT_STRING );
		}
		$position = 0;
		$scanned  = 0;
		$records  = array();

		foreach ( $values as $origin => $entry ) {
			if ( $position++ < $offset ) {
				continue;
			}
			if ( $scanned >= $limit ) {
				return array(
					'records' => $records,
					'offset'  => $offset + $scanned,
					'done'    => false,
				);
			}
			++$scanned;

			if ( 'premium_base' === $stage ) {
				if ( ! is_array( $entry ) || empty( $entry['origin'] ) ) {
					continue;
				}
				$records[] = $this->redirect_from_values(
					(string) $entry['origin'],
					(string) ( $entry['url'] ?? '' ),
					(int) ( $entry['type'] ?? 301 ),
					'regex' === (string) ( $entry['format'] ?? '' ),
					'premium-base:' . sanitize_text_field( (string) $origin )
				);
				continue;
			}

			if ( is_array( $entry ) ) {
				$origin = $entry['origin'] ?? $origin;
				$url    = $entry['url'] ?? $entry['target'] ?? '';
				$type   = (int) ( $entry['type'] ?? 301 );
			} else {
				$url  = $entry;
				$type = 301;
			}
			if ( is_scalar( $origin ) && is_scalar( $url ) ) {
				$kind      = 'legacy_regex' === $stage ? 'regex' : 'plain';
				$records[] = $this->redirect_from_values( (string) $origin, (string) $url, $type, 'regex' === $kind, 'premium-' . $kind . ':' . md5( (string) $origin ) );
			}
		}

		return array(
			'records' => $records,
			'offset'  => $offset + $scanned,
			'done'    => true,
		);
	}

	/**
	 * Maps a Yoast post or legacy taxonomy option record.
	 *
	 * @param array<string,mixed> $meta    Source metadata.
	 * @param bool                $is_term Whether legacy short taxonomy keys are used.
	 * @return array<string,mixed>
	 */
	private function map_meta( array $meta, bool $is_term ): array {
		$prefix                               = $is_term ? 'wpseo_' : '_yoast_wpseo_';
		$get                                  = fn( string $key ): string => $this->value( $meta, $prefix . $key );
		$mapped                               = array(
			'_erankly_title'               => erankly_import_convert_variables( $get( 'title' ), 'yoast' ),
			'_erankly_description'         => erankly_import_convert_variables( $is_term ? ( '' !== $get( 'desc' ) ? $get( 'desc' ) : $get( 'metadesc' ) ) : $get( 'metadesc' ), 'yoast' ),
			'_erankly_canonical'           => $get( 'canonical' ),
			'_erankly_breadcrumb_name'     => $get( 'bctitle' ),
			'_erankly_og_title'            => erankly_import_convert_variables( $get( 'opengraph-title' ), 'yoast' ),
			'_erankly_og_description'      => erankly_import_convert_variables( $get( 'opengraph-description' ), 'yoast' ),
			'_erankly_og_image_url'        => $get( 'opengraph-image' ),
			'_erankly_og_image_id'         => absint( $get( 'opengraph-image-id' ) ),
			'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'twitter-title' ), 'yoast' ),
			'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'twitter-description' ), 'yoast' ),
			'_erankly_twitter_image_url'   => $get( 'twitter-image' ),
			'_erankly_twitter_image_id'    => absint( $get( 'twitter-image-id' ) ),
		);
		$mapped['_erankly_og_image_alt']      = $this->attachment_alt( $mapped['_erankly_og_image_id'] );
		$mapped['_erankly_twitter_image_alt'] = $this->attachment_alt( $mapped['_erankly_twitter_image_id'] );

		$noindex_key = $is_term ? $prefix . 'noindex' : $prefix . 'meta-robots-noindex';
		$noindex     = $this->value( $meta, $noindex_key );
		if ( in_array( $noindex, array( '1', 'noindex' ), true ) ) {
			$mapped['_erankly_index_directive'] = 'noindex';
		} elseif ( in_array( $noindex, array( '2', 'index' ), true ) ) {
			$mapped['_erankly_index_directive'] = 'index';
		}

		if ( ! $is_term && array_key_exists( $prefix . 'meta-robots-nofollow', $meta ) ) {
			$mapped['_erankly_follow_directive'] = '1' === $get( 'meta-robots-nofollow' ) ? 'nofollow' : 'follow';
		}

		$advanced = array_filter( array_map( 'trim', explode( ',', $get( 'meta-robots-adv' ) ) ) );
		if ( in_array( 'noarchive', $advanced, true ) ) {
			$mapped['_erankly_archive_directive'] = 'noarchive';
		}
		if ( in_array( 'nosnippet', $advanced, true ) ) {
			$mapped['_erankly_snippet_directive'] = 'nosnippet';
		}
		if ( in_array( 'noimageindex', $advanced, true ) ) {
			$mapped['_erankly_image_directive'] = 'noimageindex';
		}

		foreach ( array(
			'max-snippet'       => '_erankly_max_snippet',
			'max-video-preview' => '_erankly_max_video_preview',
			'max-image-preview' => '_erankly_max_image_preview',
		) as $source => $target ) {
			$value = $get( 'meta-robots-' . $source );
			if ( '' !== $value ) {
				$mapped[ $target ] = strtolower( $value );
			}
		}

		$keywords = $this->keywords( array( $get( 'focuskw' ), $meta[ $prefix . 'focuskeywords' ] ?? array() ) );
		if ( ! empty( $keywords ) ) {
			$mapped['_erankly_focus_keywords'] = $keywords;
		}

		if ( $this->enabled( $get( 'is_cornerstone' ) ) ) {
			$mapped['_erankly_cornerstone'] = true;
		}

		if ( ! $is_term ) {
			$primary = array();
			foreach ( $meta as $key => $value ) {
				if ( str_starts_with( (string) $key, '_yoast_wpseo_primary_' ) && absint( $value ) > 0 ) {
					$primary[ substr( (string) $key, strlen( '_yoast_wpseo_primary_' ) ) ] = absint( $value );
				}
			}
			if ( ! empty( $primary ) ) {
				$mapped['_erankly_primary_terms'] = $primary;
			}

			$schema = $this->schema_type_templates( $get( 'schema_page_type' ), $get( 'schema_article_type' ) );
			if ( ! empty( $schema['blocks'] ) ) {
				$mapped['_erankly_schema_mode']   = 'merge';
				$mapped['_erankly_schema_blocks'] = $schema['blocks'];
			}
			if ( ! empty( $schema['disabled'] ) ) {
				$mapped['_erankly_schema_disabled_types'] = $schema['disabled'];
			}
		}

		$legacy = array();
		foreach ( array( 'linkdex', 'content_score', 'inclusive_language_score', 'focuskeywords' ) as $key ) {
			if ( isset( $meta[ $prefix . $key ] ) && '' !== (string) $meta[ $prefix . $key ] ) {
				$legacy[ $key ] = $meta[ $prefix . $key ];
			}
		}
		if ( ! empty( $legacy ) ) {
			$mapped['_erankly_legacy_editorial'] = array( 'yoast' => $legacy );
		}

		return $mapped;
	}

	/**
	 * Maps Yoast author archive metadata.
	 *
	 * @param array<string,mixed> $meta Source user metadata.
	 * @return array<string,mixed>
	 */
	private function map_user_meta( array $meta ): array {
		$description = $this->value( $meta, 'wpseo_metadesc' );
		$description = '' !== $description ? $description : $this->value( $meta, 'wpseo_desc' );
		$mapped      = array(
			'_erankly_title'       => erankly_import_convert_variables( $this->value( $meta, 'wpseo_title' ), 'yoast' ),
			'_erankly_description' => erankly_import_convert_variables( $description, 'yoast' ),
		);

		if ( $this->enabled( $meta['wpseo_noindex_author'] ?? '' ) ) {
			$mapped['_erankly_index_directive'] = 'noindex';
		}

		return $mapped;
	}

	/**
	 * Returns Yoast post meta keys.
	 *
	 * @return array<int,string>
	 */
	private function post_keys(): array {
		return array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_bctitle',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
			'_yoast_wpseo_twitter-image',
			'_yoast_wpseo_twitter-image-id',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_meta-robots-adv',
			'_yoast_wpseo_meta-robots-max-snippet',
			'_yoast_wpseo_meta-robots-max-video-preview',
			'_yoast_wpseo_meta-robots-max-image-preview',
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_focuskeywords',
			'_yoast_wpseo_is_cornerstone',
			'_yoast_wpseo_schema_page_type',
			'_yoast_wpseo_schema_article_type',
			'_yoast_wpseo_linkdex',
			'_yoast_wpseo_content_score',
			'_yoast_wpseo_inclusive_language_score',
			'_yoast_wpseo_redirect',
		);
	}

	/**
	 * Returns Yoast author meta keys.
	 *
	 * @return array<int,string>
	 */
	private function user_keys(): array {
		return array( 'wpseo_title', 'wpseo_desc', 'wpseo_metadesc', 'wpseo_noindex_author' );
	}

	/**
	 * Builds a normalized Yoast redirect row.
	 *
	 * @param string $origin    Source URL or pattern.
	 * @param string $target    Target URL.
	 * @param int    $type      HTTP status code.
	 * @param bool   $is_regex  Whether the source is a regex.
	 * @param string $reference Source record reference.
	 * @return array<string,mixed>
	 */
	private function redirect_from_values( string $origin, string $target, int $type, bool $is_regex, string $reference ): array {
		$query = $is_regex ? '' : (string) wp_parse_url( $origin, PHP_URL_QUERY );

		return array(
			'source_path'      => $origin,
			'source_query'     => $query,
			'target_url'       => $target,
			'status_code'      => $type,
			'match_type'       => $is_regex ? 'regex' : 'exact',
			'case_sensitive'   => 0,
			'trailing_slash'   => 'ignore',
			'query_mode'       => '' !== $query ? 'exact' : 'ignore',
			'is_active'        => 1,
			'source_reference' => $reference,
		);
	}
}
