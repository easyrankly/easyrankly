<?php
/** Yoast SEO and Yoast SEO Premium migration adapter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Yoast Free/Premium adapter. */
final class ERankly_Migration_Adapter_Yoast extends ERankly_Migration_Adapter {

	public function slug(): string {
		return 'yoast';
	}

	public function label(): string {
		return 'Yoast SEO';
	}

	/** Returns the detected version. */
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

	/** Yoast versions covered by the certified meta/option signatures. */
	protected function supported_versions(): array {
		return array(
			'min' => '3.0.0',
			'max' => '28.999.999',
		);
	}

	/** Declares every Yoast storage surface consumed by this adapter. */
	protected function storage_definitions(): array {
		return array(
			'global_main'            => array(
				'type'   => 'option',
				'option' => 'wpseo',
				'shape'  => 'array',
			),
			'global_titles'          => array(
				'type'   => 'option',
				'option' => 'wpseo_titles',
				'shape'  => 'array',
			),
			'global_social'          => array(
				'type'   => 'option',
				'option' => 'wpseo_social',
				'shape'  => 'array',
			),
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

	/** @return array<int,string> */
	public function capabilities(): array {
		return array( 'global titles and descriptions', 'global robots and sitemap rules', 'site identity', 'default schema types', 'posts', 'terms', 'authors', 'social', 'robots', 'schema', 'primary terms', 'Premium redirects' );
	}

	public function global_settings(): array {
		$main   = $this->option_array( 'wpseo' );
		$titles = $this->option_array( 'wpseo_titles' );
		$social = $this->option_array( 'wpseo_social' );
		if ( ! $main && ! $titles && ! $social ) {
			return array();
		}

		$convert  = static fn( mixed $value ): string => erankly_import_convert_variables( is_scalar( $value ) ? (string) $value : '', 'yoast' );
		$first_scalar = static function ( array $values, string $default = '' ): string {
			foreach ( $values as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return (string) $value;
				}
			}

			return $default;
		};
		$settings = array();
		$post_map = array();
		foreach ( array_keys( erankly_get_public_post_types() ) as $post_type ) {
			$title_key       = 'title-' . $post_type;
			$description_key = 'metadesc-' . $post_type;
			$noindex_key     = 'noindex-' . $post_type;
			$page_schema_key = 'schema-page-type-' . $post_type;
			$article_key     = 'schema-article-type-' . $post_type;
			if ( ! array_intersect( array( $title_key, $description_key, $noindex_key, $page_schema_key, $article_key ), array_keys( $titles ) ) ) {
				continue;
			}
			$has_noindex = array_key_exists( $noindex_key, $titles );
			$noindex     = $has_noindex && $this->enabled( $titles[ $noindex_key ] );
			$page_type   = trim( (string) ( $titles[ $page_schema_key ] ?? 'WebPage' ) );
			$article_type = trim( (string) ( $titles[ $article_key ] ?? ( 'post' === $post_type ? 'BlogPosting' : '' ) ) );
			$page_type    = 'none' === strtolower( $page_type ) ? 'none' : $page_type;
			$article_type = 'none' === strtolower( $article_type ) ? 'none' : $article_type;
			$post_map[ $post_type ] = $this->global_meta_row(
				$convert( $titles[ $title_key ] ?? '' ),
				$convert( $titles[ $description_key ] ?? '' ),
				$has_noindex ? array( 'noindex' => $noindex ) : null,
				$noindex ? false : null,
				$page_type,
				$article_type
			);
		}
		if ( $post_map ) {
			$settings['global_post_type_meta']        = $post_map;
			$settings['global_post_type_meta_linked'] = 0;
		}

		$taxonomy_map = array();
		foreach ( array_keys( erankly_get_public_taxonomies() ) as $taxonomy ) {
			$title_key       = 'title-tax-' . $taxonomy;
			$description_key = 'metadesc-tax-' . $taxonomy;
			$noindex_key     = 'noindex-tax-' . $taxonomy;
			if ( ! array_intersect( array( $title_key, $description_key, $noindex_key ), array_keys( $titles ) ) ) {
				continue;
			}
			$has_noindex = array_key_exists( $noindex_key, $titles );
			$noindex     = $has_noindex && $this->enabled( $titles[ $noindex_key ] );
			$taxonomy_map[ $taxonomy ] = $this->global_meta_row( $convert( $titles[ $title_key ] ?? '' ), $convert( $titles[ $description_key ] ?? '' ), $has_noindex ? array( 'noindex' => $noindex ) : null, $noindex ? false : null );
		}
		if ( $taxonomy_map ) {
			$settings['global_taxonomy_meta']        = $taxonomy_map;
			$settings['global_taxonomy_meta_linked'] = 0;
		}

		$special = array();
		$sources = array(
			'homepage' => array( array( 'title-home-wpseo', 'title-home' ), array( 'metadesc-home-wpseo', 'metadesc-home' ), array() ),
			'author'   => array( array( 'title-author-wpseo' ), array( 'metadesc-author-wpseo' ), array( 'noindex-author-wpseo' ) ),
			'date'     => array( array( 'title-archive-wpseo' ), array( 'metadesc-archive-wpseo' ), array( 'noindex-archive-wpseo' ) ),
			'search'   => array( array( 'title-search-wpseo' ), array( 'metadesc-search-wpseo' ), array( 'noindex-search-wpseo' ) ),
			'404'      => array( array( 'title-404-wpseo' ), array( 'metadesc-404-wpseo' ), array() ),
		);
		foreach ( $sources as $context => $paths ) {
			$known_keys = array_merge( $paths[0], $paths[1], $paths[2] );
			if ( ! array_intersect( $known_keys, array_keys( $titles ) ) ) {
				continue;
			}
			$title       = $this->first_nested_value( $titles, $paths[0] );
			$description = $this->first_nested_value( $titles, $paths[1] );
			$has_noindex = ! empty( $paths[2] ) && (bool) array_intersect( $paths[2], array_keys( $titles ) );
			$noindex     = $has_noindex && $this->enabled( $this->first_nested_value( $titles, $paths[2], false ) );
			$special[ $context ] = $this->global_meta_row(
				$this->special_template( $title, 'yoast', $context ),
				$this->special_template( $description, 'yoast', $context ),
				$has_noindex ? array( 'noindex' => $noindex ) : null,
				$noindex ? false : null
			);
		}
		foreach ( array(
			'author' => 'disable-author',
			'date'   => 'disable-date',
		) as $context => $disable_key ) {
			if ( ! array_key_exists( $disable_key, $titles ) ) {
				continue;
			}
			if ( ! isset( $special[ $context ] ) ) {
				$special[ $context ] = $this->global_meta_row( '', '' );
			}
			if ( $this->enabled( $titles[ $disable_key ] ) ) {
				$special[ $context ]['noindex']         = 1;
				$special[ $context ]['disable_sitemap'] = 1;
			}
		}

		if ( ! isset( $special['homepage'] ) && ( array_intersect( array( 'og_frontpage_title', 'og_frontpage_desc', 'og_frontpage_image', 'og_frontpage_image_id' ), array_keys( $social ) ) || array_intersect( array( 'open_graph_frontpage_title', 'open_graph_frontpage_desc', 'open_graph_frontpage_image', 'open_graph_frontpage_image_id' ), array_keys( $titles ) ) ) ) {
			$special['homepage'] = $this->global_meta_row( '', '' );
		}
		if ( isset( $special['homepage'] ) ) {
			$special['homepage']['og_title'] = $convert(
				$first_scalar( array( $social['og_frontpage_title'] ?? '', $titles['open_graph_frontpage_title'] ?? '' ) )
			);
			$special['homepage']['og_description'] = $convert(
				$first_scalar( array( $social['og_frontpage_desc'] ?? '', $titles['open_graph_frontpage_desc'] ?? '' ) )
			);
			$special['homepage']['social_image_url'] = esc_url_raw(
				$first_scalar( array( $social['og_frontpage_image'] ?? '', $titles['open_graph_frontpage_image'] ?? '' ) )
			);
			$special['homepage']['og_image_id'] = absint( $social['og_frontpage_image_id'] ?? $titles['open_graph_frontpage_image_id'] ?? 0 );
		}
		foreach ( array( 'author', 'date' ) as $context ) {
			$suffix = 'author' === $context ? 'author-wpseo' : 'archive-wpseo';
			$social_keys = array( 'social-title-' . $suffix, 'social-description-' . $suffix, 'social-image-url-' . $suffix, 'social-image-id-' . $suffix );
			if ( ! array_intersect( $social_keys, array_keys( $titles ) ) ) {
				continue;
			}
			if ( ! isset( $special[ $context ] ) ) {
				$special[ $context ] = $this->global_meta_row( '', '' );
			}
			$special[ $context ]['og_title']         = $this->special_template( $titles[ $social_keys[0] ] ?? '', 'yoast', $context );
			$special[ $context ]['og_description']   = $this->special_template( $titles[ $social_keys[1] ] ?? '', 'yoast', $context );
			$special[ $context ]['social_image_url'] = esc_url_raw( (string) ( $titles[ $social_keys[2] ] ?? '' ) );
			$special[ $context ]['og_image_id']      = absint( $titles[ $social_keys[3] ] ?? 0 );
		}
		if ( $special ) {
			$settings['global_special_meta'] = $special;
		}

		$identity = strtolower( (string) ( $titles['company_or_person'] ?? '' ) );
		if ( in_array( $identity, array( 'person', 'company', 'organization' ), true ) ) {
			$settings['schema_identity'] = 'person' === $identity ? 'person' : 'organization';
		}
		$person_name = sanitize_text_field( (string) ( $titles['person_name'] ?? '' ) );
		if ( 'person' === ( $settings['schema_identity'] ?? '' ) ) {
			$settings['schema_person_user_id'] = $this->person_user_id_or_warning( $titles['company_or_person_user_id'] ?? 0, $person_name );
		} elseif ( ! empty( $titles['company_name'] ) ) {
			$settings['organization_name'] = sanitize_text_field( (string) $titles['company_name'] );
		}
		if ( ! empty( $titles['website_name'] ) ) {
			$settings['website_name'] = sanitize_text_field( (string) $titles['website_name'] );
		}
		$logo_id = absint( $titles['company_logo_id'] ?? 0 );
		if ( $logo_id > 0 ) {
			$settings['organization_logo'] = $logo_id;
		}
		if ( ! empty( $titles['company_logo'] ) ) {
			$settings['organization_logo_url'] = esc_url_raw( (string) $titles['company_logo'] );
		}
		foreach ( array(
			'organization_description' => 'org-description',
			'organization_email'       => 'org-email',
			'organization_phone'       => 'org-phone',
			'organization_legal_name'  => 'org-legal-name',
			'organization_vat_id'      => 'org-vat-id',
			'organization_tax_id'      => 'org-tax-id',
		) as $target => $source_key ) {
			if ( isset( $titles[ $source_key ] ) && is_scalar( $titles[ $source_key ] ) && '' !== trim( (string) $titles[ $source_key ] ) ) {
				$settings[ $target ] = (string) $titles[ $source_key ];
			}
		}

		$profiles = $this->social_profile_list(
			array(
				$social['facebook_site'] ?? '',
				$social['instagram_url'] ?? '',
				$social['linkedin_url'] ?? '',
				$social['myspace_url'] ?? '',
				$social['pinterest_url'] ?? '',
				$social['youtube_url'] ?? '',
				$social['wikipedia_url'] ?? '',
				$social['mastodon_url'] ?? '',
				$social['other_social_urls'] ?? array(),
			)
		);
		if ( '' !== $profiles ) {
			$settings['social_profiles'] = $profiles;
		}
		$twitter_site = $this->social_handle( $social['twitter_site'] ?? '' );
		if ( '' !== $twitter_site ) {
			$settings['twitter_site'] = $twitter_site;
		}
		if ( ! empty( $social['og_default_image'] ) ) {
			$settings['default_social_image_url'] = esc_url_raw( (string) $social['og_default_image'] );
		}
		if ( ! empty( $social['og_default_image_id'] ) ) {
			$settings['default_og_image'] = absint( $social['og_default_image_id'] );
		}

		if ( array_key_exists( 'breadcrumbs-enable', $titles ) ) {
			$settings['enable_breadcrumbs'] = $this->enabled( $titles['breadcrumbs-enable'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'enable_xml_sitemap', $main ) ) {
			$settings['enable_sitemap'] = $this->enabled( $main['enable_xml_sitemap'] ) ? 1 : 0;
			$settings['enable_image_sitemap'] = $settings['enable_sitemap'];
		}
		if ( $this->enabled( $titles['disable-attachment'] ?? false ) ) {
			$settings['attachment_redirect'] = 'file';
		} elseif ( array_key_exists( 'disable-attachment', $titles ) ) {
			$settings['attachment_redirect'] = 'none';
		}
		if ( array_key_exists( 'noindex-subpages-wpseo', $titles ) ) {
			$settings['noindex_paginated'] = $this->enabled( $titles['noindex-subpages-wpseo'] ) ? 1 : 0;
		}
		if ( $this->has_redirect_data() ) {
			$settings['enable_redirects'] = 1;
		}

		return $settings;
	}

	/** Reports whether this install holds any Yoast Premium redirect data. */
	private function has_redirect_data(): bool {
		return is_array( get_option( 'wpseo-premium-redirects-base' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-plain' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-regex' ) )
			|| $this->has_meta( 'post', array( '_yoast_wpseo_redirect' ) );
	}

	public function is_available(): bool {
		return $this->has_meta( 'post', $this->post_keys(), array( '_yoast_wpseo_primary_', '_yoast_wpseo_schema_' ) )
			|| $this->has_meta( 'term', $this->post_keys(), array( '_yoast_wpseo_schema_' ) )
			|| is_array( get_option( 'wpseo_taxonomy_meta' ) )
			|| $this->has_meta( 'user', $this->user_keys() )
			|| is_array( get_option( 'wpseo-premium-redirects-base' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-plain' ) )
			|| is_array( get_option( 'wpseo-premium-redirects-export-regex' ) )
			|| $this->has_option_map( 'wpseo' )
			|| $this->has_option_map( 'wpseo_titles' )
			|| $this->has_option_map( 'wpseo_social' );
	}

	/** @return iterable<int,array<string,mixed>> */
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
 * @param int                 $limit  Maximum source objects to scan.
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 */
	public function content_batch( array $cursor, int $limit ): array {
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

	/** @return iterable<int,array<string,mixed>> */
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
 * @param int                 $limit  Maximum source records to scan.
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 */
	public function redirect_batch( array $cursor, int $limit ): array {
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
 * @param int    $offset Number of entries already scanned.
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

		return $this->with_extension_meta( $mapped );
	}

	/** @return array<string,mixed> */
	private function map_user_meta( array $meta ): array {
		$description = $this->value( $meta, 'wpseo_metadesc' );
		$description = '' !== $description ? $description : $this->value( $meta, 'wpseo_desc' );
		$mapped      = array(
			'_erankly_title'       => $this->special_template( $this->value( $meta, 'wpseo_title' ), 'yoast', 'author' ),
			'_erankly_description' => $this->special_template( $description, 'yoast', 'author' ),
		);

		if ( $this->enabled( $meta['wpseo_noindex_author'] ?? '' ) ) {
			$mapped['_erankly_index_directive'] = 'noindex';
		}

		return $mapped;
	}

	/** @return array<int,string> */
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
			'_yoast_wpseo_schema_page_type',
			'_yoast_wpseo_schema_article_type',
			'_yoast_wpseo_redirect',
		);
	}

	/** @return array<int,string> */
	private function user_keys(): array {
		return array( 'wpseo_title', 'wpseo_desc', 'wpseo_metadesc', 'wpseo_noindex_author' );
	}

	/**
 * @param string $origin    Source URL or pattern.
 * @param bool   $is_regex  Whether the source is a regex.
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
