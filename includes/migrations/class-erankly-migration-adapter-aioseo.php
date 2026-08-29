<?php
/**
 * All in One SEO and AIOSEO Pro migration adapter.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** AIOSEO v3/v4 Free/Pro adapter. */
final class ERankly_Migration_Adapter_AIOSEO extends ERankly_Migration_Adapter {
	private const TABLE_SUFFIXES = array( 'aioseo_posts', 'aioseo_terms', 'aioseo_redirects' );

	/**
	 * Returns the adapter slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'aioseo';
	}

	/**
	 * Returns the source label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'All in One SEO';
	}

	/**
	 * Returns the detected source version.
	 *
	 * @return string
	 */
	public function version(): string {
		if ( function_exists( 'aioseo' ) ) {
			$instance = aioseo();
			if ( is_object( $instance ) && isset( $instance->version ) && is_scalar( $instance->version ) ) {
				return sanitize_text_field( (string) $instance->version );
			}
		}

		return $this->detect_version(
			'AIOSEO_VERSION',
			array( 'aioseo_version', 'aioseo_db_version' ),
			array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' )
		);
	}

	/** Returns Lite or Pro from installed code and Pro-only storage. */
	public function edition(): string {
		$pro = ! empty( $this->installed_plugins( array( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ) ) )
			|| $this->table_has_rows( 'aioseo_terms' )
			|| $this->table_has_rows( 'aioseo_redirects' );

		return $pro ? 'pro' : 'lite';
	}

	/** Returns separately certified AIOSEO feature profiles. */
	public function modules(): array {
		$modules = array( 'search-appearance', 'social', 'schema', 'robots' );
		if ( $this->table_has_rows( 'aioseo_terms' ) ) {
			$modules[] = 'term-seo';
		}
		if ( $this->table_has_rows( 'aioseo_redirects' ) ) {
			$modules[] = 'redirects';
		}
		if ( $this->table_column_has_values( 'aioseo_posts', 'local_seo' ) ) {
			$modules[] = 'local-seo';
		}
		if ( $this->table_column_has_values( 'aioseo_posts', 'videos' ) ) {
			$modules[] = 'video-sitemap';
		}
		if ( 'pro' === $this->edition() ) {
			$modules[] = 'pro';
		}

		return array_values( array_unique( $modules ) );
	}

	/** Returns certification state for detected AIOSEO modules. */
	public function module_support(): array {
		$mapped  = array( 'search-appearance', 'social', 'schema', 'robots', 'term-seo', 'redirects', 'pro' );
		$support = array();
		foreach ( $this->modules() as $module ) {
			$support[ $module ] = in_array( $module, $mapped, true ) ? 'supported' : 'ignored';
		}
		return $support;
	}

	/** Covers AIOSEO 3 postmeta and the versioned v4 table family. */
	protected function supported_versions(): array {
		return array(
			'min' => '3.0.0',
			'max' => '4.999.999',
		);
	}

	/**
	 * Returns the Pro redirection profile proven by AIOSEO export signatures.
	 *
	 * @param string $format Certified export format.
	 * @return array{edition:string,modules:array<int,string>,module_support:array<string,string>}
	 */
	protected function export_source_profile( string $format ): array {
		if ( in_array( $format, array( 'aioseo-redirects-csv', 'aioseo-redirects-json' ), true ) ) {
			return array(
				'edition'        => 'pro',
				'modules'        => array( 'pro', 'redirects' ),
				'module_support' => array(
					'pro'       => 'supported',
					'redirects' => 'supported',
				),
			);
		}

		return parent::export_source_profile( $format );
	}

	/** Declares every AIOSEO surface and required table signature. */
	protected function storage_definitions(): array {
		return array(
			'global_options' => array(
				'type'   => 'option',
				'option' => 'aioseo_options',
			),
			'global_dynamic' => array(
				'type'   => 'option',
				'option' => 'aioseo_options_dynamic',
			),
			'global_localized' => array(
				'type'   => 'option',
				'option' => 'aioseo_options_localized',
			),
			'global_dynamic_localized' => array(
				'type'   => 'option',
				'option' => 'aioseo_options_dynamic_localized',
			),
			'v4_posts'      => array(
				'type'                => 'table',
				'suffix'              => 'aioseo_posts',
				'columns'             => array( 'id', 'post_id', 'title', 'description', 'canonical_url' ),
				'fingerprint_columns' => array( 'post_id', 'title', 'description', 'canonical_url', 'og_title', 'twitter_title', 'robots_default', 'schema', 'keyphrases', 'primary_term' ),
			),
			'pro_terms'     => array(
				'type'                => 'table',
				'suffix'              => 'aioseo_terms',
				'columns'             => array( 'id', 'term_id', 'title', 'description' ),
				'fingerprint_columns' => array( 'term_id', 'title', 'description', 'canonical_url', 'og_title', 'twitter_title', 'robots_default', 'schema', 'keyphrases', 'primary_term' ),
			),
			'pro_redirects' => array(
				'type'                => 'table',
				'suffix'              => 'aioseo_redirects',
				'columns'             => array( 'id', 'source_url', 'target_url', 'type', 'source_url_match', 'query_param', 'enabled' ),
				'fingerprint_columns' => array( 'source_url', 'target_url', 'type', 'source_url_match', 'query_param', 'enabled', 'ignore_case' ),
			),
			'v3_postmeta'   => array(
				'type'        => 'meta',
				'object_type' => 'post',
				'prefixes'    => array( '_aioseop_' ),
			),
		);
	}

	/**
	 * Returns the supported source capabilities.
	 *
	 * @return array<int,string>
	 */
	public function capabilities(): array {
		return array( 'global titles and descriptions', 'global robots and sitemap rules', 'site identity', 'default schema types', 'v3 and v4 posts', 'PRO terms', 'social', 'advanced robots', 'schema configuration', 'primary terms', 'keyphrases', 'pillar content', 'PRO redirects' );
	}

	/** Returns normalized AIOSEO global settings. */
	public function global_settings(): array {
		if ( $this->uses_export_file() ) {
			return array();
		}

		$options = array_replace_recursive( $this->option_array( 'aioseo_options' ), $this->option_array( 'aioseo_options_localized' ) );
		$dynamic = array_replace_recursive( $this->option_array( 'aioseo_options_dynamic' ), $this->option_array( 'aioseo_options_dynamic_localized' ) );
		if ( ! $options && ! $dynamic ) {
			return array();
		}

		$convert  = static fn( mixed $value ): string => erankly_import_convert_variables( is_scalar( $value ) ? (string) $value : '', 'aioseo' );
		$settings = array();
		$global   = $this->nested_value( $options, 'searchAppearance.global', array() );
		$global   = is_array( $global ) ? $global : array();
		$site_title = $this->first_nested_value( $global, array( 'siteTitle', 'title' ) );
		$site_desc  = $this->first_nested_value( $global, array( 'metaDescription', 'description' ) );
		if ( is_scalar( $site_title ) && '' !== trim( (string) $site_title ) ) {
			$settings['website_name'] = $convert( $site_title );
		}
		if ( is_scalar( $site_desc ) && '' !== trim( (string) $site_desc ) ) {
			$settings['website_description'] = $convert( $site_desc );
		}

		$post_types = $this->nested_value( $dynamic, 'searchAppearance.postTypes', array() );
		if ( ! is_array( $post_types ) ) {
			$post_types = $this->nested_value( $options, 'searchAppearance.postTypes', array() );
		}
		$post_map = array();
		foreach ( array_keys( erankly_get_public_post_types() ) as $post_type ) {
			$config = isset( $post_types[ $post_type ] ) && is_array( $post_types[ $post_type ] ) ? $post_types[ $post_type ] : array();
			if ( ! $config ) {
				continue;
			}
			$robots       = $this->first_nested_value( $config, array( 'advanced.robotsMeta', 'robotsMeta', 'robots' ), array() );
			$page_type    = (string) $this->first_nested_value( $config, array( 'webPageType', 'schema.webPageType' ), 'WebPage' );
			$schema_type  = strtolower( (string) $this->first_nested_value( $config, array( 'schemaType', 'schema.type' ), '' ) );
			$article_type = in_array( $schema_type, array( 'article', 'blogposting', 'newsarticle' ), true )
				? (string) $this->first_nested_value( $config, array( 'articleType', 'schema.articleType' ), 'Article' )
				: '';
			if ( 'none' === strtolower( $page_type ) ) {
				$page_type = 'none';
			}
			$map_path    = 'sitemap.general.postTypes.' . $post_type;
			$in_sitemap  = $this->has_nested_value( $options, $map_path ) ? $this->enabled( $this->nested_value( $options, $map_path ) ) : null;
			$post_map[ $post_type ] = $this->global_meta_row(
				$convert( $config['title'] ?? '' ),
				$convert( $config['metaDescription'] ?? $config['description'] ?? '' ),
				$robots,
				$in_sitemap,
				$page_type,
				$article_type
			);
		}
		if ( $post_map ) {
			$settings['global_post_type_meta']        = $post_map;
			$settings['global_post_type_meta_linked'] = 0;
		}

		$taxonomies = $this->nested_value( $dynamic, 'searchAppearance.taxonomies', array() );
		if ( ! is_array( $taxonomies ) ) {
			$taxonomies = $this->nested_value( $options, 'searchAppearance.taxonomies', array() );
		}
		$taxonomy_map = array();
		foreach ( array_keys( erankly_get_public_taxonomies() ) as $taxonomy ) {
			$config = isset( $taxonomies[ $taxonomy ] ) && is_array( $taxonomies[ $taxonomy ] ) ? $taxonomies[ $taxonomy ] : array();
			if ( ! $config ) {
				continue;
			}
			$robots      = $this->first_nested_value( $config, array( 'advanced.robotsMeta', 'robotsMeta', 'robots' ), array() );
			$map_path    = 'sitemap.general.taxonomies.' . $taxonomy;
			$in_sitemap  = $this->has_nested_value( $options, $map_path ) ? $this->enabled( $this->nested_value( $options, $map_path ) ) : null;
			$taxonomy_map[ $taxonomy ] = $this->global_meta_row( $convert( $config['title'] ?? '' ), $convert( $config['metaDescription'] ?? $config['description'] ?? '' ), $robots, $in_sitemap );
		}
		if ( $taxonomy_map ) {
			$settings['global_taxonomy_meta']        = $taxonomy_map;
			$settings['global_taxonomy_meta_linked'] = 0;
		}

		$special = array();
		$special_paths = array(
			'homepage' => array( 'searchAppearance.global', 'searchAppearance.homePage' ),
			'author'   => array( 'searchAppearance.archives.author', 'searchAppearance.authorArchives' ),
			'date'     => array( 'searchAppearance.archives.date', 'searchAppearance.dateArchives' ),
			'search'   => array( 'searchAppearance.advanced.searchPage', 'searchAppearance.searchPage' ),
			'404'      => array( 'searchAppearance.advanced.404Page', 'searchAppearance.404Page' ),
		);
		foreach ( $special_paths as $context => $paths ) {
			$config = $this->first_nested_value( $options, $paths, array() );
			if ( ! is_array( $config ) || ! $config ) {
				continue;
			}
			$robots = $this->first_nested_value( $config, array( 'advanced.robotsMeta', 'robotsMeta', 'robots' ), array() );
			$special[ $context ] = $this->global_meta_row(
				$this->special_template( $config['title'] ?? $config['siteTitle'] ?? '', 'aioseo', $context ),
				$this->special_template( $config['metaDescription'] ?? $config['description'] ?? '', 'aioseo', $context ),
				$robots
			);
		}
		if ( $special ) {
			$settings['global_special_meta'] = $special;
		}

		$schema   = $this->nested_value( $global, 'schema', array() );
		$schema   = is_array( $schema ) ? $schema : array();
		$identity = strtolower( (string) $this->first_nested_value( $schema, array( 'siteRepresents', 'siteRepresentsType' ), '' ) );
		if ( in_array( $identity, array( 'person', 'organization' ), true ) ) {
			$settings['schema_identity'] = $identity;
		}
		$organization = $this->nested_value( $schema, 'organization', array() );
		$organization = is_array( $organization ) ? $organization : array();
		if ( ! empty( $organization['name'] ) ) {
			$settings['organization_name'] = sanitize_text_field( (string) $organization['name'] );
		}
		if ( ! empty( $organization['description'] ) ) {
			$settings['organization_description'] = sanitize_textarea_field( (string) $organization['description'] );
		}
		$logo_id = absint( $this->first_nested_value( $organization, array( 'logo.id', 'logo.attachmentId', 'logoId' ), 0 ) );
		$logo_url = (string) $this->first_nested_value( $organization, array( 'logo.url', 'logoUrl' ), '' );
		if ( $logo_id > 0 ) {
			$settings['organization_logo'] = $logo_id;
		}
		if ( '' !== $logo_url ) {
			$settings['organization_logo_url'] = esc_url_raw( $logo_url );
		}
		if ( 'person' === ( $settings['schema_identity'] ?? '' ) ) {
			$person = $this->nested_value( $schema, 'person', array() );
			$person = is_array( $person ) ? $person : array();
			$settings['schema_person_user_id'] = $this->person_user_id_or_warning( $person['userId'] ?? $person['id'] ?? 0, (string) ( $person['name'] ?? '' ) );
		}

		$profiles = $this->social_profile_list(
			array(
				$this->nested_value( $options, 'social.profiles.urls', array() ),
				$this->nested_value( $options, 'social.profiles.additionalUrls', array() ),
			)
		);
		if ( '' !== $profiles ) {
			$settings['social_profiles'] = $profiles;
		}
		$twitter_site = $this->first_nested_value( $options, array( 'social.twitter.username', 'social.twitter.site' ), '' );
		if ( is_scalar( $twitter_site ) && '' !== trim( (string) $twitter_site ) ) {
			$settings['twitter_site'] = (string) $twitter_site;
		}
		$default_image = $this->first_nested_value( $options, array( 'social.facebook.general.defaultImage', 'social.facebook.defaultImage' ), '' );
		if ( is_scalar( $default_image ) && '' !== trim( (string) $default_image ) ) {
			$settings['default_social_image_url'] = esc_url_raw( (string) $default_image );
		}

		foreach ( array(
			'enable_sitemap'     => array( 'sitemap.general.enable', 'sitemap.general.enabled' ),
			'enable_breadcrumbs' => array( 'breadcrumbs.enable', 'breadcrumbs.enabled' ),
		) as $target => $paths ) {
			foreach ( $paths as $path ) {
				if ( $this->has_nested_value( $options, $path ) ) {
					$settings[ $target ] = $this->enabled( $this->nested_value( $options, $path ) ) ? 1 : 0;
					break;
				}
			}
		}
		if ( $this->table_has_rows( 'aioseo_redirects' ) ) {
			$settings['enable_redirects']        = 1;
			$settings['redirect_exclude_admins'] = 0;
		}

		return $settings;
	}

	/**
	 * Determines whether AIOSEO data is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( $this->uses_export_file() ) {
			return 'supported' === (string) $this->profile()['storage_status'];
		}

		return $this->table_has_rows( 'aioseo_posts' )
			|| $this->table_has_rows( 'aioseo_terms' )
			|| $this->table_has_rows( 'aioseo_redirects' )
			|| $this->has_meta( 'post', array(), array( '_aioseop_' ) )
			|| $this->has_option_map( 'aioseo_options' )
			|| $this->has_option_map( 'aioseo_options_dynamic' )
			|| $this->has_option_map( 'aioseo_options_localized' )
			|| $this->has_option_map( 'aioseo_options_dynamic_localized' );
	}

	/**
	 * Yields normalized content records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function content_records(): iterable {
		foreach ( array(
			'aioseo_posts' => array( 'post', 'post_id' ),
			'aioseo_terms' => array( 'term', 'term_id' ),
		) as $suffix => $config ) {
			foreach ( $this->table_rows( $suffix ) as $row ) {
				$object_id = absint( $row[ $config[1] ] ?? 0 );
				if ( $object_id < 1 || ( 'post' === $config[0] ? null === get_post( $object_id ) : ! get_term( $object_id ) instanceof WP_Term ) ) {
					continue;
				}

				$mapped = $this->map_row( $row, $config[0], $object_id );
				if ( ! empty( $mapped ) ) {
					yield array(
						'object_type'      => $config[0],
						'object_id'        => $object_id,
						'meta'             => $mapped,
						'source_reference' => $suffix . ':' . absint( $row['id'] ?? $object_id ),
					);
				}
			}
		}

		// AIOSEO 3.x data predates the v4 custom tables and remains common on
		// long-lived sites and backups.
		foreach ( $this->meta_objects( 'post', array(), array( '_aioseop_' ) ) as $record ) {
			$mapped = $this->map_v3_meta( $record['meta'] );
			if ( ! empty( $mapped ) ) {
				yield array(
					'object_type'      => 'post',
					'object_id'        => $record['id'],
					'meta'             => $mapped,
					'source_reference' => 'v3-post:' . $record['id'],
				);
			}
		}
	}

	/**
	 * Returns one resumable AIOSEO v4/v3 content page.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
	 * @param int                 $limit  Maximum source rows or objects to scan.
	 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
	 */
	public function content_batch( array $cursor, int $limit ): array {
		if ( $this->uses_export_file() ) {
			return ERankly_Migration_Export_Reader::content_batch( $this->export_file(), $this->slug(), $cursor, $limit );
		}

		$stages = array( 'aioseo_posts', 'aioseo_terms', 'v3_postmeta' );
		$stage  = sanitize_key( (string) ( $cursor['stage'] ?? 'aioseo_posts' ) );
		$stage  = in_array( $stage, $stages, true ) ? $stage : 'aioseo_posts';
		$limit  = max( 1, min( 500, $limit ) );

		while ( true ) {
			$records = array();
			if ( 'v3_postmeta' === $stage ) {
				$page = $this->meta_object_batch( 'post', array(), array( '_aioseop_' ), absint( $cursor['after_id'] ?? 0 ), $limit );
				foreach ( $page['records'] as $record ) {
					$mapped = $this->map_v3_meta( $record['meta'] );
					if ( $mapped ) {
						$records[] = array(
							'object_type'      => 'post',
							'object_id'        => $record['id'],
							'meta'             => $mapped,
							'source_reference' => 'v3-post:' . $record['id'],
						);
					}
				}
			} else {
				$config = 'aioseo_posts' === $stage ? array( 'post', 'post_id' ) : array( 'term', 'term_id' );
				$page   = $this->source_table_batch( $stage, absint( $cursor['after_id'] ?? 0 ), $limit );
				foreach ( $page['records'] as $row ) {
					$object_id = absint( $row[ $config[1] ] ?? 0 );
					if ( $object_id < 1 || ( 'post' === $config[0] ? null === get_post( $object_id ) : ! get_term( $object_id ) instanceof WP_Term ) ) {
						continue;
					}
					$mapped = $this->map_row( $row, $config[0], $object_id );
					if ( $mapped ) {
						$records[] = array(
							'object_type'      => $config[0],
							'object_id'        => $object_id,
							'meta'             => $mapped,
							'source_reference' => $stage . ':' . absint( $row['id'] ?? $object_id ),
						);
					}
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

	/**
	 * Yields normalized redirect records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function redirect_records(): iterable {
		foreach ( $this->table_rows( 'aioseo_redirects' ) as $row ) {
			$source = (string) ( $row['source_url'] ?? '' );
			if ( '' === $source ) {
				continue;
			}

			$match      = strtolower( (string) ( $row['source_url_match'] ?? 'exact' ) );
			$match_type = 'regex' === $match ? 'regex' : 'exact';
			$query      = 'regex' === $match_type ? '' : (string) wp_parse_url( $source, PHP_URL_QUERY );
			$query_mode = $this->aioseo_query_mode( (string) ( $row['query_param'] ?? '' ), $query );

			yield array(
				'source_path'      => $source,
				'source_query'     => 'exact' === $query_mode ? $query : '',
				'target_url'       => (string) ( $row['target_url'] ?? '' ),
				'status_code'      => absint( $row['type'] ?? 301 ),
				'match_type'       => $match_type,
				'case_sensitive'   => empty( $row['ignore_case'] ) ? 1 : 0,
				'trailing_slash'   => 'ignore',
				'query_mode'       => $query_mode,
				'is_active'        => empty( $row['enabled'] ) ? 0 : 1,
				'source_reference' => 'redirect:' . absint( $row['id'] ?? 0 ),
			);
		}
	}

	/**
	 * Returns one keyset-paginated AIOSEO Pro redirect page.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
	 * @param int                 $limit  Maximum source rows to scan.
	 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
	 */
	public function redirect_batch( array $cursor, int $limit ): array {
		if ( $this->uses_export_file() ) {
			return ERankly_Migration_Export_Reader::redirect_batch( $this->export_file(), $this->slug(), $cursor, $limit );
		}

		$page    = $this->source_table_batch( 'aioseo_redirects', absint( $cursor['after_id'] ?? 0 ), $limit );
		$records = array();

		foreach ( $page['records'] as $row ) {
			$source = (string) ( $row['source_url'] ?? '' );
			if ( '' === $source ) {
				continue;
			}
			$match      = strtolower( (string) ( $row['source_url_match'] ?? 'exact' ) );
			$match_type = 'regex' === $match ? 'regex' : 'exact';
			$query      = 'regex' === $match_type ? '' : (string) wp_parse_url( $source, PHP_URL_QUERY );
			$query_mode = $this->aioseo_query_mode( (string) ( $row['query_param'] ?? '' ), $query );
			$records[]  = array(
				'source_path'      => $source,
				'source_query'     => 'exact' === $query_mode ? $query : '',
				'target_url'       => (string) ( $row['target_url'] ?? '' ),
				'status_code'      => absint( $row['type'] ?? 301 ),
				'match_type'       => $match_type,
				'case_sensitive'   => empty( $row['ignore_case'] ) ? 1 : 0,
				'trailing_slash'   => 'ignore',
				'query_mode'       => $query_mode,
				'is_active'        => empty( $row['enabled'] ) ? 0 : 1,
				'source_reference' => 'redirect:' . absint( $row['id'] ?? 0 ),
			);
		}

		return array(
			'records' => $records,
			'cursor'  => $page['done'] ? array( 'stage' => 'done' ) : array( 'after_id' => $page['after_id'] ),
			'done'    => $page['done'],
		);
	}

	/**
	 * Maps an AIOSEO v4 database row.
	 *
	 * @param array<string,mixed> $row         Source row.
	 * @param string              $object_type post|term.
	 * @param int                 $object_id   Object ID.
	 * @return array<string,mixed>
	 */
	private function map_row( array $row, string $object_type, int $object_id ): array {
		$get    = static fn( string $key ): string => isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
		$og_url = $get( 'og_image_custom_url' );
		$tw_url = $get( 'twitter_image_custom_url' );
		if ( '' === $og_url ) {
			$og_url = $get( 'og_image_url' );
		}
		if ( '' === $tw_url ) {
			$tw_url = $get( 'twitter_image_url' );
		}
		$mapped = array(
			'_erankly_title'               => erankly_import_convert_variables( $get( 'title' ), 'aioseo' ),
			'_erankly_description'         => erankly_import_convert_variables( $get( 'description' ), 'aioseo' ),
			'_erankly_canonical'           => $get( 'canonical_url' ),
			'_erankly_og_title'            => erankly_import_convert_variables( $get( 'og_title' ), 'aioseo' ),
			'_erankly_og_description'      => erankly_import_convert_variables( $get( 'og_description' ), 'aioseo' ),
			'_erankly_og_image_url'        => $og_url,
			'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'twitter_title' ), 'aioseo' ),
			'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'twitter_description' ), 'aioseo' ),
			'_erankly_twitter_image_url'   => $tw_url,
			'_erankly_twitter_card_type'   => $this->twitter_card( $get( 'twitter_card' ) ),
		);

		if ( $this->enabled( $row['twitter_use_og'] ?? false ) ) {
			if ( '' === $mapped['_erankly_twitter_title'] ) {
				$mapped['_erankly_twitter_title'] = $mapped['_erankly_og_title'];
			}
			if ( '' === $mapped['_erankly_twitter_description'] ) {
				$mapped['_erankly_twitter_description'] = $mapped['_erankly_og_description'];
			}
			if ( '' === $mapped['_erankly_twitter_image_url'] ) {
				$mapped['_erankly_twitter_image_url'] = $mapped['_erankly_og_image_url'];
			}
		}

		if ( isset( $row['robots_default'] ) && ! $this->enabled( $row['robots_default'] ) ) {
			$mapped['_erankly_index_directive']   = $this->enabled( $row['robots_noindex'] ?? false ) ? 'noindex' : 'index';
			$mapped['_erankly_follow_directive']  = $this->enabled( $row['robots_nofollow'] ?? false ) ? 'nofollow' : 'follow';
			$mapped['_erankly_archive_directive'] = $this->enabled( $row['robots_noarchive'] ?? false ) ? 'noarchive' : 'archive';
			$mapped['_erankly_snippet_directive'] = $this->enabled( $row['robots_nosnippet'] ?? false ) ? 'nosnippet' : 'snippet';
			$mapped['_erankly_image_directive']   = $this->enabled( $row['robots_noimageindex'] ?? false ) ? 'noimageindex' : 'imageindex';

			if ( isset( $row['robots_max_snippet'] ) && null !== $row['robots_max_snippet'] && '' !== (string) $row['robots_max_snippet'] ) {
				$mapped['_erankly_max_snippet'] = (string) $row['robots_max_snippet'];
			}
			if ( isset( $row['robots_max_videopreview'] ) && null !== $row['robots_max_videopreview'] && '' !== (string) $row['robots_max_videopreview'] ) {
				$mapped['_erankly_max_video_preview'] = (string) $row['robots_max_videopreview'];
			}
			if ( ! empty( $row['robots_max_imagepreview'] ) ) {
				$mapped['_erankly_max_image_preview'] = strtolower( (string) $row['robots_max_imagepreview'] );
			}
		}

		$editorial = array();
		$keywords  = $this->keywords( $row['keyphrases'] ?? $row['keywords'] ?? '' );
		if ( ! empty( $keywords ) ) {
			$editorial['focus_keywords'] = $keywords;
		}
		if ( $this->enabled( $row['pillar_content'] ?? false ) ) {
			$editorial['cornerstone'] = true;
		}

		if ( 'post' === $object_type ) {
			$primary = $this->primary_terms( $row['primary_term'] ?? '' );
			if ( ! empty( $primary ) ) {
				$mapped['_erankly_primary_terms'] = $primary;
			}
		}

		$schema_payload = isset( $row['schema'] ) ? json_decode( (string) $row['schema'], true ) : array();
		$entities       = array();
		foreach ( $this->extract_schema_entities( $schema_payload ) as $entity ) {
			$entities[] = $this->convert_schema_variables( $entity );
		}

		$old_schema = $this->old_schema_entity( $get( 'schema_type' ), $row['schema_type_options'] ?? '' );
		if ( ! empty( $old_schema ) ) {
			$entities[] = $old_schema;
		}

		$schema_options = is_array( $schema_payload ) ? $schema_payload : array();
		$page_type      = isset( $schema_options['default']['data']['WebPage']['webPageType'] ) ? (string) $schema_options['default']['data']['WebPage']['webPageType'] : '';
		$article_type   = isset( $schema_options['default']['graphName'] ) && false !== stripos( (string) $schema_options['default']['graphName'], 'article' ) ? 'Article' : '';
		$type_templates = $this->schema_type_templates( $page_type, $article_type );
		$blocks         = array_merge( $this->schema_blocks( $entities ), $type_templates['blocks'] );

		if ( ! empty( $blocks ) ) {
			$mapped['_erankly_schema_mode']   = 'merge';
			$mapped['_erankly_schema_blocks'] = $blocks;
		}
		if ( ! empty( $type_templates['disabled'] ) ) {
			$mapped['_erankly_schema_disabled_types'] = $type_templates['disabled'];
		}
		if ( isset( $schema_options['default']['isEnabled'] ) && false === $schema_options['default']['isEnabled'] && empty( $blocks ) ) {
			$mapped['_erankly_schema_mode'] = 'disabled';
		}

		if ( ! empty( $row['schema'] ) && empty( $blocks ) ) {
			$this->add_warning( 'schema_configuration_not_migrated', 'An AIOSEO schema configuration could not be converted to rendered EasyRankly JSON-LD.', $object_type . ':' . $object_id );
		}

		return $this->with_extension_meta( $mapped, $editorial );
	}

	/**
	 * Maps an AIOSEO v3 post metadata record.
	 *
	 * @param array<string,mixed> $meta V3 post metadata.
	 * @return array<string,mixed>
	 */
	private function map_v3_meta( array $meta ): array {
		$og     = maybe_unserialize( $meta['_aioseop_opengraph_settings'] ?? array() );
		$og     = is_array( $og ) ? $og : array();
		$mapped = array(
			'_erankly_title'             => erankly_import_convert_variables( $this->value( $meta, '_aioseop_title' ), 'aioseo' ),
			'_erankly_description'       => erankly_import_convert_variables( $this->value( $meta, '_aioseop_description' ), 'aioseo' ),
			'_erankly_canonical'         => $this->value( $meta, '_aioseop_custom_link' ),
			'_erankly_og_title'          => erankly_import_convert_variables( (string) ( $og['aioseop_opengraph_settings_title'] ?? '' ), 'aioseo' ),
			'_erankly_og_description'    => erankly_import_convert_variables( (string) ( $og['aioseop_opengraph_settings_desc'] ?? '' ), 'aioseo' ),
			'_erankly_og_image_url'      => (string) ( $og['aioseop_opengraph_settings_image'] ?? '' ),
			'_erankly_twitter_image_url' => (string) ( $og['aioseop_opengraph_settings_customimg_twitter'] ?? '' ),
			'_erankly_twitter_card_type' => $this->twitter_card( (string) ( $og['aioseop_opengraph_settings_setcard'] ?? '' ) ),
		);

		foreach ( array(
			'_aioseop_noindex'  => array( '_erankly_index_directive', 'noindex', 'index' ),
			'_aioseop_nofollow' => array( '_erankly_follow_directive', 'nofollow', 'follow' ),
		) as $source => $values ) {
			$value = strtolower( $this->value( $meta, $source ) );
			if ( 'on' === $value || 'off' === $value ) {
				$mapped[ $values[0] ] = 'on' === $value ? $values[1] : $values[2];
			}
		}

		$editorial = array();
		$keywords  = $this->keywords( $meta['_aioseop_keywords'] ?? '' );
		if ( ! empty( $keywords ) ) {
			$editorial['focus_keywords'] = $keywords;
		}
		if ( $this->enabled( $meta['_aioseop_sitemap_exclude'] ?? false ) || $this->enabled( $meta['_aioseop_disable'] ?? false ) ) {
			$mapped['_erankly_disable_sitemap'] = true;
		}

		return $this->with_extension_meta( $mapped, $editorial );
	}

	/**
	 * Yields rows from an AIOSEO source table in bounded batches.
	 *
	 * @param string $suffix Table suffix.
	 * @return iterable<int,array<string,mixed>>
	 */
	private function table_rows( string $suffix ): iterable {
		if ( ! in_array( $suffix, self::TABLE_SUFFIXES, true ) ) {
			return;
		}

		$cursor = 0;
		do {
			$page = $this->source_table_batch( $suffix, $cursor, 200 );
			if ( empty( $page['records'] ) ) {
				break;
			}
			foreach ( $page['records'] as $row ) {
				yield $row;
			}
			$cursor = (int) $page['after_id'];
		} while ( empty( $page['done'] ) );
	}

	/**
	 * Determines whether an AIOSEO source table contains rows.
	 *
	 * @param string $suffix Table suffix.
	 * @return bool
	 */
	private function table_has_rows( string $suffix ): bool {
		global $wpdb;

		if ( ! in_array( $suffix, self::TABLE_SUFFIXES, true ) ) {
			return false;
		}
		$table = $wpdb->prefix . $suffix;
		if ( ! erankly_table_exists( $table ) ) {
			return false;
		}

		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence check against a whitelisted prefixed table.
	}

	/**
	 * Checks whether a certified optional AIOSEO column contains data.
	 *
	 * @param string $suffix Table suffix.
	 * @param string $column Certified optional column.
	 * @return bool
	 */
	private function table_column_has_values( string $suffix, string $column ): bool {
		global $wpdb;

		$allowed = array(
			'aioseo_posts' => array( 'local_seo', 'videos' ),
		);
		if ( ! isset( $allowed[ $suffix ] ) || ! in_array( $column, $allowed[ $suffix ], true ) ) {
			return false;
		}

		$table = $wpdb->prefix . $suffix;
		if ( ! erankly_table_exists( $table ) ) {
			return false;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Storage signature inspection.
		if ( null === $exists ) {
			return false;
		}

		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE %i IS NOT NULL AND %i <> %s LIMIT 1', $table, $column, $column, '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column are selected from the internal whitelist above and escaped as identifiers.
	}

	/**
	 * Normalizes an AIOSEO Twitter card value.
	 *
	 * @param string $value Source card value.
	 * @return string
	 */
	private function twitter_card( string $value ): string {
		$value = strtolower( $value );
		if ( '' === $value || 'default' === $value ) {
			return '';
		}
		return false !== strpos( $value, 'large' ) ? 'summary_large_image' : 'summary';
	}

	/**
	 * Maps AIOSEO query handling to the target model.
	 *
	 * @param string $value Source query option.
	 * @param string $query Query found in the source URL.
	 * @return string
	 */
	private function aioseo_query_mode( string $value, string $query ): string {
		$value = strtolower( $value );
		if ( false !== strpos( $value, 'pass' ) || false !== strpos( $value, 'preserve' ) ) {
			return 'preserve';
		}
		if ( false !== strpos( $value, 'exact' ) || '' !== $query ) {
			return 'exact';
		}
		return 'ignore';
	}

	/**
	 * Normalizes AIOSEO primary-term data.
	 *
	 * @param mixed $value Source primary-term data.
	 * @return array<string,int>
	 */
	private function primary_terms( mixed $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = JSON_ERROR_NONE === json_last_error() ? $decoded : array();
		}
		$primary = array();
		foreach ( is_array( $value ) ? $value : array() as $taxonomy => $term_id ) {
			if ( is_array( $term_id ) ) {
				$taxonomy = $term_id['taxonomy'] ?? $taxonomy;
				$term_id  = $term_id['term_id'] ?? $term_id['id'] ?? 0;
			}
			$term = get_term( absint( $term_id ) );
			if ( $term instanceof WP_Term ) {
				$primary[ $term->taxonomy ] = $term->term_id;
			} elseif ( is_string( $taxonomy ) && absint( $term_id ) > 0 ) {
				$primary[ sanitize_key( $taxonomy ) ] = absint( $term_id );
			}
		}
		return $primary;
	}

	/**
	 * Builds a JSON-LD entity from an old AIOSEO schema record.
	 *
	 * @param string $type    Source schema type.
	 * @param mixed  $options Source schema options.
	 * @return array<string,mixed>
	 */
	private function old_schema_entity( string $type, mixed $options ): array {
		$type = preg_replace( '/[^A-Za-z0-9_-]/', '', $type ) ?? '';
		if ( ! in_array( $type, array( 'SoftwareApplication', 'Product', 'Recipe', 'Course' ), true ) ) {
			return array();
		}
		$data = is_string( $options ) ? json_decode( $options, true ) : $options;
		$data = is_array( $data ) ? ( $data[ strtolower( 'SoftwareApplication' === $type ? 'software' : $type ) ] ?? $data[ strtolower( $type ) ] ?? array() ) : array();
		$node = array( '@type' => $type );
		foreach ( array( 'name', 'description', 'brand', 'category', 'price', 'currency', 'provider', 'dishType', 'cuisineType' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				$node[ $key ] = erankly_import_convert_variables( (string) $data[ $key ], 'aioseo' );
			}
		}
		return $node;
	}

	/**
	 * Converts AIOSEO variables throughout a schema value.
	 *
	 * @param mixed $value Schema value.
	 * @return mixed
	 */
	private function convert_schema_variables( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return erankly_import_convert_variables( $value, 'aioseo' );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->convert_schema_variables( $child );
		}
		return $value;
	}
}
