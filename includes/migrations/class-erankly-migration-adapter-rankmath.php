<?php
/** Rank Math SEO and Rank Math PRO migration adapter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rank Math Free/PRO adapter. */
final class ERankly_Migration_Adapter_RankMath extends ERankly_Migration_Adapter {

	private static function enabled_module_value( mixed $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true', 'active', 'enabled' ), true );
	}

	public function slug(): string {
		return 'rankmath';
	}

	public function label(): string {
		return 'Rank Math';
	}

	/** Returns the detected source version. */
	public function version(): string {
		return $this->detect_version(
			'RANK_MATH_VERSION',
			array( 'rank_math_version' ),
			array( 'seo-by-rank-math/rank-math.php' )
		);
	}

	public function edition(): string {
		return defined( 'RANK_MATH_PRO_VERSION' ) || ! empty( $this->installed_plugins( array( 'seo-by-rank-math-pro/rank-math-pro.php' ) ) ) ? 'pro' : 'free';
	}

	public function modules(): array {
		$stored  = get_option( 'rank_math_modules', array() );
		$modules = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $value ) {
			$name = is_int( $key ) ? (string) $value : ( self::enabled_module_value( $value ) ? (string) $key : '' );
			$name = sanitize_key( $name );
			if ( '' !== $name ) {
				$modules[] = $name;
			}
		}
		if ( 'pro' === $this->edition() ) {
			$modules[] = 'pro';
		}
		if ( $this->redirect_table_has_rows() ) {
			$modules[] = 'redirections';
		}

		return array_values( array_unique( $modules ) );
	}

	public function module_support(): array {
		$mapped  = array( 'pro', 'schema', 'rich-snippet', 'redirections', 'advanced-robots', 'image-seo' );
		$support = array();
		foreach ( $this->modules() as $module ) {
			// Modules outside the adapter contract are intentionally ignored. Their
			// enabled state is still recorded in the source profile, but it must not
			// create a blocking warning when no EasyRankly-owned value is imported.
			$support[ $module ] = in_array( $module, $mapped, true ) ? 'supported' : 'ignored';
		}
		return $support;
	}

	/** Rank Math keeps its public storage contract in the 1.0 line. */
	protected function supported_versions(): array {
		return array(
			'min' => '0.9.0',
			'max' => '1.999.999',
		);
	}

	/** @return array{edition:string,modules:array<int,string>,module_support:array<string,string>} */
	protected function export_source_profile( string $format ): array {
		if ( 'rankmath-metadata-csv' === $format ) {
			return array(
				'edition'        => 'pro',
				'modules'        => array( 'pro', 'schema', 'advanced-robots' ),
				'module_support' => array(
					'pro'             => 'supported',
					'schema'          => 'supported',
					'advanced-robots' => 'supported',
				),
			);
		}
		if ( 'rankmath-redirects-csv' === $format ) {
			return array(
				'edition'        => 'free-or-pro',
				'modules'        => array( 'redirections' ),
				'module_support' => array( 'redirections' => 'supported' ),
			);
		}

		return parent::export_source_profile( $format );
	}

	/** Declares every Rank Math surface consumed by this adapter. */
	protected function storage_definitions(): array {
		return array(
			'global_titles' => array(
				'type'   => 'option',
				'option' => 'rank-math-options-titles',
				'shape'  => 'array',
			),
			'global_general' => array(
				'type'   => 'option',
				'option' => 'rank-math-options-general',
				'shape'  => 'array',
			),
			'global_sitemap' => array(
				'type'   => 'option',
				'option' => 'rank-math-options-sitemap',
				'shape'  => 'array',
			),
			'modules_option' => array(
				'type'   => 'option',
				'option' => 'rank_math_modules',
				'shape'  => 'array',
			),
			'post_meta'    => array(
				'type'        => 'meta',
				'object_type' => 'post',
				'keys'        => $this->keys(),
				'prefixes'    => array( 'rank_math_schema_', 'rank_math_primary_' ),
			),
			'term_meta'    => array(
				'type'        => 'meta',
				'object_type' => 'term',
				'keys'        => $this->keys(),
				'prefixes'    => array( 'rank_math_schema_' ),
			),
			'user_meta'    => array(
				'type'        => 'meta',
				'object_type' => 'user',
				'keys'        => $this->keys(),
			),
			'redirections' => array(
				'type'                => 'table',
				'suffix'              => 'rank_math_redirections',
				'columns'             => array( 'id', 'sources', 'url_to', 'header_code', 'status' ),
				'fingerprint_columns' => array( 'sources', 'url_to', 'header_code', 'status' ),
			),
		);
	}

	/** @return array<int,string> */
	public function capabilities(): array {
		return array( 'global titles and descriptions', 'global robots and sitemap rules', 'site identity', 'default schema types', 'posts', 'terms', 'authors', 'social', 'advanced robots', 'schema', 'primary terms', 'redirections', 'multi-source and regex redirects' );
	}

	public function global_settings(): array {
		if ( $this->uses_export_file() ) {
			return array();
		}

		$titles  = $this->option_array( 'rank-math-options-titles' );
		$general = $this->option_array( 'rank-math-options-general' );
		$sitemap = $this->option_array( 'rank-math-options-sitemap' );
		if ( ! $titles && ! $general && ! $sitemap ) {
			return array();
		}

		$convert  = static fn( mixed $value ): string => erankly_import_convert_variables( is_scalar( $value ) ? (string) $value : '', 'rankmath' );
		$settings = array();
		$global_robots = array( $titles['robots_global'] ?? array(), $titles['advanced_robots_global'] ?? array() );
		$post_map = array();
		foreach ( array_keys( erankly_get_public_post_types() ) as $post_type ) {
			$prefix = 'pt_' . $post_type . '_';
			$keys   = array( $prefix . 'title', $prefix . 'description', $prefix . 'robots', $prefix . 'custom_robots', $prefix . 'advanced_robots', $prefix . 'default_rich_snippet', $prefix . 'default_article_type' );
			$found  = (bool) array_intersect( $keys, array_keys( $titles ) ) || array_key_exists( $prefix . 'sitemap', $sitemap );
			if ( ! $found ) {
				continue;
			}

			$schema_type = strtolower( trim( (string) ( $titles[ $prefix . 'default_rich_snippet' ] ?? '' ) ) );
			$article_type = '';
			if ( in_array( $schema_type, array( 'article', 'blogposting', 'newsarticle' ), true ) ) {
				$article_type = (string) ( $titles[ $prefix . 'default_article_type' ] ?? ( 'blogposting' === $schema_type ? 'BlogPosting' : 'Article' ) );
			}
			$in_sitemap = array_key_exists( $prefix . 'sitemap', $sitemap ) ? $this->enabled( $sitemap[ $prefix . 'sitemap' ] ) : null;
			$has_custom_robots = array_key_exists( $prefix . 'custom_robots', $titles )
				? $this->enabled( $titles[ $prefix . 'custom_robots' ] )
				: array_key_exists( $prefix . 'robots', $titles );
			$robots = $has_custom_robots
				? array( $titles[ $prefix . 'robots' ] ?? array(), $titles[ $prefix . 'advanced_robots' ] ?? array() )
				: $global_robots;
			$post_map[ $post_type ] = $this->global_meta_row(
				$convert( $titles[ $prefix . 'title' ] ?? '' ),
				$convert( $titles[ $prefix . 'description' ] ?? '' ),
				$robots,
				$in_sitemap,
				'WebPage',
				$article_type
			);
		}
		if ( $post_map ) {
			$settings['global_post_type_meta']        = $post_map;
			$settings['global_post_type_meta_linked'] = 0;
		}

		$taxonomy_map = array();
		foreach ( array_keys( erankly_get_public_taxonomies() ) as $taxonomy ) {
			$prefix = 'tax_' . $taxonomy . '_';
			$keys   = array( $prefix . 'title', $prefix . 'description', $prefix . 'robots', $prefix . 'custom_robots', $prefix . 'advanced_robots' );
			$found  = (bool) array_intersect( $keys, array_keys( $titles ) ) || array_key_exists( $prefix . 'sitemap', $sitemap );
			if ( ! $found ) {
				continue;
			}
			$in_sitemap = array_key_exists( $prefix . 'sitemap', $sitemap ) ? $this->enabled( $sitemap[ $prefix . 'sitemap' ] ) : null;
			$has_custom_robots = array_key_exists( $prefix . 'custom_robots', $titles )
				? $this->enabled( $titles[ $prefix . 'custom_robots' ] )
				: array_key_exists( $prefix . 'robots', $titles );
			$robots = $has_custom_robots
				? array( $titles[ $prefix . 'robots' ] ?? array(), $titles[ $prefix . 'advanced_robots' ] ?? array() )
				: $global_robots;
			$taxonomy_map[ $taxonomy ] = $this->global_meta_row(
				$convert( $titles[ $prefix . 'title' ] ?? '' ),
				$convert( $titles[ $prefix . 'description' ] ?? '' ),
				$robots,
				$in_sitemap
			);
		}
		if ( $taxonomy_map ) {
			$settings['global_taxonomy_meta']        = $taxonomy_map;
			$settings['global_taxonomy_meta_linked'] = 0;
		}

		$special = array();
		$special_sources = array(
			'homepage' => array( 'homepage_title', 'homepage_description', 'homepage_robots', 'homepage_custom_robots', 'homepage_advanced_robots' ),
			'author'   => array( 'author_archive_title', 'author_archive_description', 'author_robots', 'author_custom_robots', 'author_advanced_robots' ),
			'date'     => array( 'date_archive_title', 'date_archive_description', 'date_archive_robots', 'date_archive_custom_robots', 'date_archive_advanced_robots' ),
			'search'   => array( 'search_title', 'search_description', 'search_robots', 'search_custom_robots', 'search_advanced_robots' ),
			'404'      => array( '404_title', '404_description', '404_robots', '404_custom_robots', '404_advanced_robots' ),
		);
		foreach ( $special_sources as $context => $keys ) {
			if ( ! array_intersect( $keys, array_keys( $titles ) ) ) {
				continue;
			}
			$has_custom_robots = array_key_exists( $keys[3], $titles )
				? $this->enabled( $titles[ $keys[3] ] )
				: array_key_exists( $keys[2], $titles );
			$robots = $has_custom_robots
				? array( $titles[ $keys[2] ] ?? array(), $titles[ $keys[4] ] ?? array() )
				: $global_robots;
			$special[ $context ] = $this->global_meta_row(
				$this->special_template( $titles[ $keys[0] ] ?? '', 'rankmath', $context ),
				$this->special_template( $titles[ $keys[1] ] ?? '', 'rankmath', $context ),
				$robots
			);
		}
		foreach ( array(
			'author' => 'disable_author_archives',
			'date'   => 'disable_date_archives',
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
		if ( array_key_exists( 'noindex_search', $titles ) ) {
			if ( ! isset( $special['search'] ) ) {
				$special['search'] = $this->global_meta_row( '', '' );
			}
			$hidden                                = $this->enabled( $titles['noindex_search'] );
			$special['search']['noindex']         = $hidden ? 1 : 0;
			$special['search']['disable_sitemap'] = $hidden ? 1 : 0;
		}
		if ( isset( $special['homepage'] ) ) {
			$special['homepage']['og_title']            = $convert( $titles['homepage_facebook_title'] ?? '' );
			$special['homepage']['og_description']      = $convert( $titles['homepage_facebook_description'] ?? '' );
			$special['homepage']['twitter_title']       = $convert( $titles['homepage_twitter_title'] ?? '' );
			$special['homepage']['twitter_description'] = $convert( $titles['homepage_twitter_description'] ?? '' );
			$special['homepage']['social_image_url']    = esc_url_raw( (string) ( $titles['homepage_facebook_image'] ?? '' ) );
			$special['homepage']['og_image_id']         = absint( $titles['homepage_facebook_image_id'] ?? 0 );
		}
		if ( $special ) {
			$settings['global_special_meta'] = $special;
		}

		$identity = strtolower( (string) ( $titles['knowledgegraph_type'] ?? '' ) );
		if ( in_array( $identity, array( 'person', 'company', 'organization' ), true ) ) {
			$settings['schema_identity'] = 'person' === $identity ? 'person' : 'organization';
		}
		$name = sanitize_text_field( (string) ( $titles['knowledgegraph_name'] ?? '' ) );
		if ( 'person' === ( $settings['schema_identity'] ?? '' ) ) {
			$settings['schema_person_user_id'] = $this->person_user_id_or_warning( $titles['knowledgegraph_id'] ?? 0, $name );
		} elseif ( '' !== $name ) {
			$settings['organization_name'] = $name;
		}
		if ( ! empty( $titles['website_name'] ) ) {
			$settings['website_name'] = sanitize_text_field( (string) $titles['website_name'] );
		}
		if ( ! empty( $titles['knowledgegraph_logo_id'] ) ) {
			$settings['organization_logo'] = absint( $titles['knowledgegraph_logo_id'] );
		}
		if ( ! empty( $titles['knowledgegraph_logo'] ) ) {
			$settings['organization_logo_url'] = esc_url_raw( (string) $titles['knowledgegraph_logo'] );
		}

		$profiles = $this->social_profile_list(
			array(
				$titles['social_url_facebook'] ?? '',
				$titles['social_url_twitter'] ?? '',
				$titles['social_url_instagram'] ?? '',
				$titles['social_url_linkedin'] ?? '',
				$titles['social_url_youtube'] ?? '',
				$titles['social_url_pinterest'] ?? '',
				$titles['social_url_tiktok'] ?? '',
			)
		);
		if ( '' !== $profiles ) {
			$settings['social_profiles'] = $profiles;
		}
		$twitter_site = $this->social_handle( $titles['social_url_twitter'] ?? '' );
		if ( '' !== $twitter_site ) {
			$settings['twitter_site'] = $twitter_site;
		}

		if ( array_key_exists( 'breadcrumbs', $general ) ) {
			$settings['enable_breadcrumbs'] = $this->enabled( $general['breadcrumbs'] ) ? 1 : 0;
		}
		if ( $this->enabled( $general['attachment_redirect_urls'] ?? false ) ) {
			$settings['attachment_redirect'] = 'parent';
		}
		if ( array_key_exists( 'noindex_archive_subpages', $titles ) ) {
			$settings['noindex_paginated'] = $this->enabled( $titles['noindex_archive_subpages'] ) ? 1 : 0;
		}
		if ( in_array( 'sitemap', $this->modules(), true ) || $sitemap ) {
			$settings['enable_sitemap'] = 1;
		}
		if ( array_key_exists( 'include_images', $sitemap ) ) {
			$settings['enable_image_sitemap'] = $this->enabled( $sitemap['include_images'] ) ? 1 : 0;
		}
		if ( in_array( 'redirections', $this->modules(), true ) ) {
			$settings['enable_redirects']        = 1;

			$fallback = sanitize_key( (string) ( $general['redirections_fallback'] ?? 'default' ) );
			if ( ! in_array( $fallback, array( '', 'default' ), true ) ) {
				$this->add_warning(
					'redirect_fallback_not_supported',
					'Rank Math applies a site-wide redirect fallback that EasyRankly cannot reproduce. Disable the fallback or recreate the intended routes as explicit rules before switching providers.',
					'rank-math-options-general:redirections_fallback'
				);
			}
			if ( $this->enabled( $general['redirections_post_redirect'] ?? false ) ) {
				$this->add_warning(
					'automatic_slug_redirects_not_supported',
					'Rank Math automatically creates redirect rules after URL slug changes. EasyRankly does not provide an equivalent automatic rule generator.',
					'rank-math-options-general:redirections_post_redirect'
				);
			}
		}

		return $settings;
	}

	public function is_available(): bool {
		if ( $this->uses_export_file() ) {
			return 'supported' === (string) $this->profile()['storage_status'];
		}

		return $this->has_meta( 'post', $this->keys(), array( 'rank_math_schema_', 'rank_math_primary_' ) )
			|| $this->has_meta( 'term', $this->keys(), array( 'rank_math_schema_' ) )
			|| $this->has_meta( 'user', $this->keys() )
			|| $this->redirect_table_has_rows()
			|| $this->has_option_map( 'rank-math-options-titles' )
			|| $this->has_option_map( 'rank-math-options-general' )
			|| $this->has_option_map( 'rank-math-options-sitemap' );
	}

	/** @return iterable<int,array<string,mixed>> */
	public function content_records(): iterable {
		foreach ( array( 'post', 'term', 'user' ) as $object_type ) {
			$prefixes = 'post' === $object_type ? array( 'rank_math_schema_', 'rank_math_primary_' ) : array( 'rank_math_schema_' );
			foreach ( $this->meta_objects( $object_type, $this->keys(), $prefixes ) as $record ) {
				$mapped = $this->map_meta( $record['meta'], $object_type );
				if ( empty( $mapped ) ) {
					continue;
				}

				yield array(
					'object_type'      => $object_type,
					'object_id'        => $record['id'],
					'meta'             => $mapped,
					'source_reference' => $object_type . ':' . $record['id'],
				);
			}
		}
	}

	/**
 * @param int                 $limit  Maximum source objects to scan.
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 */
	public function content_batch( array $cursor, int $limit ): array {
		if ( $this->uses_export_file() ) {
			return ERankly_Migration_Export_Reader::content_batch( $this->export_file(), $this->slug(), $cursor, $limit );
		}

		$stages = array( 'post', 'term', 'user' );
		$stage  = sanitize_key( (string) ( $cursor['stage'] ?? 'post' ) );
		$stage  = in_array( $stage, $stages, true ) ? $stage : 'post';
		$limit  = max( 1, min( 500, $limit ) );

		while ( true ) {
			$prefixes = 'post' === $stage ? array( 'rank_math_schema_', 'rank_math_primary_' ) : array( 'rank_math_schema_' );
			$page     = $this->meta_object_batch( $stage, $this->keys(), $prefixes, absint( $cursor['after_id'] ?? 0 ), $limit );
			$records  = array();

			foreach ( $page['records'] as $record ) {
				$mapped = $this->map_meta( $record['meta'], $stage );
				if ( $mapped ) {
					$records[] = array(
						'object_type'      => $stage,
						'object_id'        => $record['id'],
						'meta'             => $mapped,
						'source_reference' => $stage . ':' . $record['id'],
					);
				}
			}

			if ( $page['done'] ) {
				$index = array_search( $stage, $stages, true );
				if ( false === $index || count( $stages ) - 1 === $index ) {
					return array(
						'records' => $records,
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

			if ( $records || ! $page['done'] ) {
				return array(
					'records' => $records,
					'cursor'  => $cursor,
					'done'    => false,
				);
			}
		}
	}

	/** @return iterable<int,array<string,mixed>> */
	public function redirect_records(): iterable {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! erankly_table_exists( $table ) ) {
			return;
		}

		$cursor = 0;
		do {
			$page = $this->source_table_batch( 'rank_math_redirections', $cursor, 200 );
			$rows = $page['records'];
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor  = max( $cursor, absint( $row['id'] ?? 0 ) );
				$sources = maybe_unserialize( $row['sources'] ?? array() );
				if ( ! is_array( $sources ) ) {
					$this->add_warning( 'invalid_redirect_sources', 'A Rank Math redirect has an unreadable source list.', 'redirect:' . $cursor );
					continue;
				}

				foreach ( $sources as $index => $source ) {
					if ( ! is_array( $source ) || empty( $source['pattern'] ) ) {
						continue;
					}

					$comparison = (string) ( $source['comparison'] ?? 'exact' );
					$match_type = array(
						'exact'    => 'exact',
						'contains' => 'contains',
						'start'    => 'starts_with',
						'end'      => 'ends_with',
						'regex'    => 'regex',
					)[ $comparison ] ?? 'exact';
					$pattern    = (string) $source['pattern'];
					$query      = 'regex' === $match_type ? '' : (string) wp_parse_url( $pattern, PHP_URL_QUERY );

					yield array(
						'source_path'      => $pattern,
						'source_query'     => $query,
						'target_url'       => (string) ( $row['url_to'] ?? '' ),
						'status_code'      => absint( $row['header_code'] ?? 301 ),
						'match_type'       => $match_type,
						'case_sensitive'   => isset( $source['ignore'] ) && 'case' === $source['ignore'] ? 0 : 1,
						'trailing_slash'   => 'ignore',
						'query_mode'       => '' !== $query ? 'exact' : 'ignore',
						'is_active'        => 'active' === (string) ( $row['status'] ?? 'active' ) ? 1 : 0,
						'source_reference' => 'redirect:' . $cursor . ':source:' . absint( $index ),
					);
				}
			}
		} while ( empty( $page['done'] ) );
	}

	/**
 * Returns a bounded redirect page, including a cursor within multi-source rules so one unusually large Rank Math
 * row cannot monopolize a worker.
 *
 * @param int                 $limit  Maximum normalized redirect records.
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 * @throws RuntimeException When the Rank Math redirect query fails.
 */
	public function redirect_batch( array $cursor, int $limit ): array {
		if ( $this->uses_export_file() ) {
			return ERankly_Migration_Export_Reader::redirect_batch( $this->export_file(), $this->slug(), $cursor, $limit );
		}

		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		$limit = max( 1, min( 500, $limit ) );
		if ( ! erankly_table_exists( $table ) ) {
			return array(
				'records' => array(),
				'cursor'  => array( 'stage' => 'done' ),
				'done'    => true,
			);
		}

		$after_id     = absint( $cursor['after_id'] ?? 0 );
		$resume_id    = absint( $cursor['row_id'] ?? 0 );
		$source_start = absint( $cursor['source_offset'] ?? 0 );
		$operator     = $resume_id > 0 ? '>=' : '>';
		$boundary     = $resume_id > 0 ? $resume_id : $after_id;
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset scan of certified third-party redirect columns.
			$wpdb->prepare( "SELECT id, sources, url_to, header_code, status FROM %i WHERE id {$operator} %d ORDER BY id ASC LIMIT %d", $table, $boundary, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The comparison operator is selected from the two internal literals above.
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Rank Math redirect page could not be read.' );
		}
		$rows         = is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
		$records      = array();
		$source_count = 0;

		foreach ( $rows as $row ) {
			$row_id  = absint( $row['id'] ?? 0 );
			$sources = maybe_unserialize( $row['sources'] ?? array() );
			if ( ! is_array( $sources ) ) {
				$this->add_warning( 'invalid_redirect_sources', 'A Rank Math redirect has an unreadable source list.', 'redirect:' . $row_id );
				$after_id     = max( $after_id, $row_id );
				$resume_id    = 0;
				$source_start = 0;
				continue;
			}

			$position = 0;
			foreach ( $sources as $index => $source ) {
				if ( $row_id === $resume_id && $position < $source_start ) {
					++$position;
					continue;
				}
				if ( $source_count >= $limit ) {
					return array(
						'records' => $records,
						'cursor'  => array(
							'after_id'      => $after_id,
							'row_id'        => $row_id,
							'source_offset' => $position,
						),
						'done'    => false,
					);
				}
				++$source_count;
				++$position;
				if ( ! is_array( $source ) || empty( $source['pattern'] ) ) {
					continue;
				}

				$comparison = (string) ( $source['comparison'] ?? 'exact' );
				$match_type = array(
					'exact'    => 'exact',
					'contains' => 'contains',
					'start'    => 'starts_with',
					'end'      => 'ends_with',
					'regex'    => 'regex',
				)[ $comparison ] ?? 'exact';
				$pattern    = (string) $source['pattern'];
				$query      = 'regex' === $match_type ? '' : (string) wp_parse_url( $pattern, PHP_URL_QUERY );
				$records[]  = array(
					'source_path'      => $pattern,
					'source_query'     => $query,
					'target_url'       => (string) ( $row['url_to'] ?? '' ),
					'status_code'      => absint( $row['header_code'] ?? 301 ),
					'match_type'       => $match_type,
					'case_sensitive'   => isset( $source['ignore'] ) && 'case' === $source['ignore'] ? 0 : 1,
					'trailing_slash'   => 'ignore',
					'query_mode'       => '' !== $query ? 'exact' : 'ignore',
					'is_active'        => 'active' === (string) ( $row['status'] ?? 'active' ) ? 1 : 0,
					'source_reference' => 'redirect:' . $row_id . ':source:' . absint( $index ),
				);
			}

			$after_id     = max( $after_id, $row_id );
			$resume_id    = 0;
			$source_start = 0;
		}

		$done = count( $rows ) < $limit;

		return array(
			'records' => $records,
			'cursor'  => $done ? array( 'stage' => 'done' ) : array(
				'after_id'      => $after_id,
				'row_id'        => 0,
				'source_offset' => 0,
			),
			'done'    => $done,
		);
	}

	/** @return array<string,mixed> */
	private function map_meta( array $meta, string $object_type ): array {
		$get                                  = fn( string $key ): string => $this->value( $meta, $key );
		$title                                = erankly_import_convert_variables( $get( 'rank_math_title' ), 'rankmath' );
		$description                          = erankly_import_convert_variables( $get( 'rank_math_description' ), 'rankmath' );
		if ( 'user' === $object_type ) {
			$title       = str_replace( '{{post_author}}', '{{author_name}}', $title );
			$description = str_replace( '{{post_author}}', '{{author_name}}', $description );
		}
		$mapped                               = array(
			'_erankly_title'               => $title,
			'_erankly_description'         => $description,
			'_erankly_canonical'           => $get( 'rank_math_canonical_url' ),
			'_erankly_breadcrumb_name'     => $get( 'rank_math_breadcrumb_title' ),
			'_erankly_og_title'            => erankly_import_convert_variables( $get( 'rank_math_facebook_title' ), 'rankmath' ),
			'_erankly_og_description'      => erankly_import_convert_variables( $get( 'rank_math_facebook_description' ), 'rankmath' ),
			'_erankly_og_image_url'        => $get( 'rank_math_facebook_image' ),
			'_erankly_og_image_id'         => absint( $get( 'rank_math_facebook_image_id' ) ),
			'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'rank_math_twitter_title' ), 'rankmath' ),
			'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'rank_math_twitter_description' ), 'rankmath' ),
			'_erankly_twitter_image_url'   => $get( 'rank_math_twitter_image' ),
			'_erankly_twitter_image_id'    => absint( $get( 'rank_math_twitter_image_id' ) ),
			'_erankly_twitter_card_type'   => $this->twitter_card( $get( 'rank_math_twitter_card_type' ) ),
		);
		$mapped['_erankly_og_image_alt']      = $this->attachment_alt( $mapped['_erankly_og_image_id'] );
		$mapped['_erankly_twitter_image_alt'] = $this->attachment_alt( $mapped['_erankly_twitter_image_id'] );

		if ( $this->enabled( $get( 'rank_math_twitter_use_facebook' ) ) ) {
			if ( '' === $mapped['_erankly_twitter_title'] ) {
				$mapped['_erankly_twitter_title'] = $mapped['_erankly_og_title'];
			}
			if ( '' === $mapped['_erankly_twitter_description'] ) {
				$mapped['_erankly_twitter_description'] = $mapped['_erankly_og_description'];
			}
			if ( '' === $mapped['_erankly_twitter_image_url'] ) {
				$mapped['_erankly_twitter_image_url'] = $mapped['_erankly_og_image_url'];
			}
			if ( 0 === $mapped['_erankly_twitter_image_id'] ) {
				$mapped['_erankly_twitter_image_id'] = $mapped['_erankly_og_image_id'];
			}
			if ( '' === $mapped['_erankly_twitter_image_alt'] ) {
				$mapped['_erankly_twitter_image_alt'] = $mapped['_erankly_og_image_alt'];
			}
		}

		$robots = maybe_unserialize( $meta['rank_math_robots'] ?? array() );
		$robots = is_array( $robots ) ? $robots : array_filter( array_map( 'trim', explode( ',', (string) $robots ) ) );
		if ( in_array( 'noindex', $robots, true ) ) {
			$mapped['_erankly_index_directive'] = 'noindex';
		} elseif ( in_array( 'index', $robots, true ) ) {
			$mapped['_erankly_index_directive'] = 'index';
		}
		if ( in_array( 'nofollow', $robots, true ) ) {
			$mapped['_erankly_follow_directive'] = 'nofollow';
		} elseif ( in_array( 'follow', $robots, true ) ) {
			$mapped['_erankly_follow_directive'] = 'follow';
		}
		if ( in_array( 'noarchive', $robots, true ) ) {
			$mapped['_erankly_archive_directive'] = 'noarchive';
		}
		if ( in_array( 'nosnippet', $robots, true ) ) {
			$mapped['_erankly_snippet_directive'] = 'nosnippet';
		}
		if ( in_array( 'noimageindex', $robots, true ) ) {
			$mapped['_erankly_image_directive'] = 'noimageindex';
		}
		if ( in_array( 'indexifembedded', $robots, true ) ) {
			$mapped['_erankly_indexifembedded'] = true;
		}

		$advanced = maybe_unserialize( $meta['rank_math_advanced_robots'] ?? array() );
		if ( is_array( $advanced ) ) {
			foreach ( array(
				'max-snippet'       => '_erankly_max_snippet',
				'max-video-preview' => '_erankly_max_video_preview',
				'max-image-preview' => '_erankly_max_image_preview',
			) as $source => $target ) {
				if ( isset( $advanced[ $source ] ) && false !== $advanced[ $source ] && '' !== (string) $advanced[ $source ] ) {
					$mapped[ $target ] = strtolower( (string) $advanced[ $source ] );
				}
			}
		}

		if ( 'post' === $object_type ) {
			$primary = array();
			foreach ( $meta as $key => $value ) {
				if ( str_starts_with( (string) $key, 'rank_math_primary_' ) && absint( $value ) > 0 ) {
					$taxonomy = substr( (string) $key, strlen( 'rank_math_primary_' ) );
					$term     = get_term( absint( $value ) );
					if ( $term instanceof WP_Term ) {
						$taxonomy = $term->taxonomy;
					}
					$primary[ $taxonomy ] = absint( $value );
				}
			}
			if ( ! empty( $primary ) ) {
				$mapped['_erankly_primary_terms'] = $primary;
			}
		}

		$entities = array();
		foreach ( $meta as $key => $value ) {
			if ( ! str_starts_with( (string) $key, 'rank_math_schema_' ) || in_array( $key, array( 'rank_math_schema_type', 'rank_math_schema_generator' ), true ) ) {
				continue;
			}
			foreach ( $this->extract_schema_entities( maybe_unserialize( $value ) ) as $entity ) {
				$entities[] = $this->convert_schema_variables( $entity );
			}
		}

		$blocks = $this->schema_blocks( $entities );
		if ( ! empty( $blocks ) ) {
			$mapped['_erankly_schema_mode']   = 'merge';
			$mapped['_erankly_schema_blocks'] = $blocks;
		}

		return $this->with_extension_meta( $mapped );
	}

	private function twitter_card( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return false !== strpos( strtolower( $value ), 'large' ) ? 'summary_large_image' : 'summary';
	}

	private function convert_schema_variables( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return erankly_import_convert_variables( $value, 'rankmath' );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->convert_schema_variables( $child );
		}

		return $value;
	}

	/** @return array<int,string> */
	private function keys(): array {
		return array(
			'rank_math_title',
			'rank_math_description',
			'rank_math_canonical_url',
			'rank_math_breadcrumb_title',
			'rank_math_facebook_title',
			'rank_math_facebook_description',
			'rank_math_facebook_image',
			'rank_math_facebook_image_id',
			'rank_math_twitter_title',
			'rank_math_twitter_description',
			'rank_math_twitter_image',
			'rank_math_twitter_image_id',
			'rank_math_twitter_card_type',
			'rank_math_twitter_use_facebook',
			'rank_math_robots',
			'rank_math_advanced_robots',
		);
	}

	private function redirect_table_has_rows(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! erankly_table_exists( $table ) ) {
			return false;
		}

		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence check against the fixed prefixed source table.
	}
}
