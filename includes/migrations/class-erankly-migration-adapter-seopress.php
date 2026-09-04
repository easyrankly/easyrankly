<?php
/** SEOPress and SEOPress PRO migration adapter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** SEOPress Free/PRO adapter. */
final class ERankly_Migration_Adapter_SEOPress extends ERankly_Migration_Adapter {

	public function slug(): string {
		return 'seopress';
	}

	public function label(): string {
		return 'SEOPress';
	}

	/** Returns the detected source version. */
	public function version(): string {
		return $this->detect_version(
			'SEOPRESS_VERSION',
			array( 'seopress_version', 'seopress_db_version' ),
			array( 'wp-seopress/seopress.php' )
		);
	}

	public function edition(): string {
		$pro = defined( 'SEOPRESS_PRO_VERSION' )
			|| ! empty( $this->installed_plugins( array( 'wp-seopress-pro/seopress-pro.php' ) ) )
			|| $this->has_meta( 'post', array( '_seopress_pro_schemas_manual', '_seopress_pro_rich_snippets_type' ), array( '_seopress_pro_rich_snippets_' ) )
			|| $this->has_redirect_posts();

		return $pro ? 'pro' : 'free';
	}

	public function modules(): array {
		$modules = array( 'titles', 'social', 'robots' );
		if ( $this->has_meta( 'post', array( '_seopress_pro_schemas_manual', '_seopress_pro_rich_snippets_type' ), array( '_seopress_pro_rich_snippets_' ) ) ) {
			$modules[] = 'schemas';
		}
		if ( $this->has_redirect_posts() || $this->has_meta( 'post', $this->redirect_keys() ) || $this->has_meta( 'term', $this->redirect_keys() ) ) {
			$modules[] = 'redirects';
		}
		if ( 'pro' === $this->edition() ) {
			$modules[] = 'pro';
		}

		return array_values( array_unique( $modules ) );
	}

	public function module_support(): array {
		$support = array();
		foreach ( $this->modules() as $module ) {
			$support[ $module ] = in_array( $module, array( 'titles', 'social', 'robots', 'schemas', 'redirects', 'pro' ), true ) ? 'supported' : 'ignored';
		}
		return $support;
	}

	/** Covers current and legacy SEOPress meta/CPT signatures. */
	protected function supported_versions(): array {
		return array(
			'min' => '3.0.0',
			'max' => '10.999.999',
		);
	}

	/** @return array{edition:string,modules:array<int,string>,module_support:array<string,string>} */
	protected function export_source_profile( string $format ): array {
		if ( 'seopress-metadata-csv' === $format ) {
			return array(
				'edition'        => 'free-or-pro',
				'modules'        => array( 'titles', 'social', 'robots', 'redirects' ),
				'module_support' => array(
					'titles'    => 'supported',
					'social'    => 'supported',
					'robots'    => 'supported',
					'redirects' => 'supported',
				),
			);
		}

		return parent::export_source_profile( $format );
	}

	/** Declares every SEOPress surface consumed by this adapter. */
	protected function storage_definitions(): array {
		return array(
			'global_titles'       => array(
				'type'   => 'option',
				'option' => 'seopress_titles_option_name',
				'shape'  => 'array',
			),
			'global_social'       => array(
				'type'   => 'option',
				'option' => 'seopress_social_option_name',
				'shape'  => 'array',
			),
			'global_sitemap'      => array(
				'type'   => 'option',
				'option' => 'seopress_xml_sitemap_option_name',
				'shape'  => 'array',
			),
			'global_toggles'      => array(
				'type'   => 'option',
				'option' => 'seopress_toggle',
				'shape'  => 'array',
			),
			'global_advanced'     => array(
				'type'   => 'option',
				'option' => 'seopress_advanced_option_name',
				'shape'  => 'array',
			),
			'post_content_meta'  => array(
				'type'        => 'meta',
				'object_type' => 'post',
				'keys'        => $this->content_keys(),
				'prefixes'    => array( '_seopress_pro_rich_snippets_' ),
			),
			'term_content_meta'  => array(
				'type'        => 'meta',
				'object_type' => 'term',
				'keys'        => $this->content_keys(),
			),
			'post_redirect_meta' => array(
				'type'        => 'meta',
				'object_type' => 'post',
				'keys'        => $this->redirect_keys(),
			),
			'term_redirect_meta' => array(
				'type'        => 'meta',
				'object_type' => 'term',
				'keys'        => $this->redirect_keys(),
			),
			'redirect_cpt'       => array(
				'type'      => 'post_type',
				'post_type' => 'seopress_404',
			),
		);
	}

	/** @return array<int,string> */
	public function capabilities(): array {
		return array( 'global titles and descriptions', 'global robots and sitemap rules', 'site identity', 'attachment redirects', 'posts', 'terms', 'social', 'robots', 'primary category', 'target keywords', 'PRO schemas', 'PRO redirects', 'regex, query and login redirect conditions' );
	}

	public function global_settings(): array {
		if ( $this->uses_export_file() ) {
			return array();
		}

		$titles  = $this->option_array( 'seopress_titles_option_name' );
		$social  = $this->option_array( 'seopress_social_option_name' );
		$sitemap = $this->option_array( 'seopress_xml_sitemap_option_name' );
		$toggles = $this->option_array( 'seopress_toggle' );
		$advanced = $this->option_array( 'seopress_advanced_option_name' );
		if ( ! $titles && ! $social && ! $sitemap && ! $toggles && ! $advanced ) {
			return array();
		}

		$convert  = static fn( mixed $value ): string => erankly_import_convert_variables( is_scalar( $value ) ? (string) $value : '', 'seopress' );
		$settings = array();
		$singles = $titles['seopress_titles_single_titles'] ?? array();
		$singles = is_array( $singles ) ? $singles : array();
		$post_map = array();
		foreach ( array_keys( erankly_get_public_post_types() ) as $post_type ) {
			$config = isset( $singles[ $post_type ] ) && is_array( $singles[ $post_type ] ) ? $singles[ $post_type ] : array();
			if ( ! $config ) {
				continue;
			}
			$in_sitemap = null;
			$current_list = $sitemap['seopress_xml_sitemap_post_types_list'] ?? null;
			if ( is_array( $current_list ) ) {
				$value      = $current_list[ $post_type ] ?? false;
				$value      = is_array( $value ) ? ( $value['include'] ?? false ) : $value;
				$in_sitemap = $this->enabled( $value );
			} elseif ( $this->has_nested_value( $sitemap, array( 'post_types', $post_type, 'include' ) ) ) {
				$in_sitemap = $this->enabled( $this->nested_value( $sitemap, array( 'post_types', $post_type, 'include' ) ) );
			}
			$post_map[ $post_type ] = $this->global_meta_row(
				$convert( $config['title'] ?? '' ),
				$convert( $config['description'] ?? $config['desc'] ?? '' ),
				$config,
				$in_sitemap,
				'WebPage',
				'post' === $post_type ? 'BlogPosting' : ''
			);
		}
		if ( $post_map ) {
			$settings['global_post_type_meta']        = $post_map;
			$settings['global_post_type_meta_linked'] = 0;
		}

		$taxonomies = $titles['seopress_titles_tax_titles'] ?? array();
		$taxonomies = is_array( $taxonomies ) ? $taxonomies : array();
		$taxonomy_map = array();
		foreach ( array_keys( erankly_get_public_taxonomies() ) as $taxonomy ) {
			$config = isset( $taxonomies[ $taxonomy ] ) && is_array( $taxonomies[ $taxonomy ] ) ? $taxonomies[ $taxonomy ] : array();
			if ( ! $config ) {
				continue;
			}
			$in_sitemap = null;
			$current_list = $sitemap['seopress_xml_sitemap_taxonomies_list'] ?? null;
			if ( is_array( $current_list ) ) {
				$value      = $current_list[ $taxonomy ] ?? false;
				$value      = is_array( $value ) ? ( $value['include'] ?? false ) : $value;
				$in_sitemap = $this->enabled( $value );
			} elseif ( $this->has_nested_value( $sitemap, array( 'taxonomies', $taxonomy, 'include' ) ) ) {
				$in_sitemap = $this->enabled( $this->nested_value( $sitemap, array( 'taxonomies', $taxonomy, 'include' ) ) );
			}
			$taxonomy_map[ $taxonomy ] = $this->global_meta_row( $convert( $config['title'] ?? '' ), $convert( $config['description'] ?? $config['desc'] ?? '' ), $config, $in_sitemap );
		}
		if ( $taxonomy_map ) {
			$settings['global_taxonomy_meta']        = $taxonomy_map;
			$settings['global_taxonomy_meta_linked'] = 0;
		}

		$special = array();
		$special_keys = array(
			'homepage' => array(
				'title'   => array( 'seopress_titles_home_site_title' ),
				'desc'    => array( 'seopress_titles_home_site_desc' ),
				'noindex' => array( 'seopress_titles_home_robots' ),
				'disable' => array(),
			),
			'author'   => array(
				'title'   => array( 'seopress_titles_archives_author_title' ),
				'desc'    => array( 'seopress_titles_archives_author_desc' ),
				'noindex' => array( 'seopress_titles_archives_author_noindex' ),
				'disable' => array( 'seopress_titles_archives_author_disable' ),
			),
			'date'     => array(
				'title'   => array( 'seopress_titles_archives_date_title' ),
				'desc'    => array( 'seopress_titles_archives_date_desc' ),
				'noindex' => array( 'seopress_titles_archives_date_noindex' ),
				'disable' => array( 'seopress_titles_archives_date_disable' ),
			),
			'search'   => array(
				'title'   => array( 'seopress_titles_archives_search_title' ),
				'desc'    => array( 'seopress_titles_archives_search_desc' ),
				'noindex' => array( 'seopress_titles_archives_search_title_noindex', 'seopress_titles_archives_search_noindex' ),
				'disable' => array(),
			),
			'404'      => array(
				'title'   => array( 'seopress_titles_archives_404_title' ),
				'desc'    => array( 'seopress_titles_archives_404_desc' ),
				'noindex' => array( 'seopress_titles_archives_404_noindex' ),
				'disable' => array(),
			),
		);
		foreach ( $special_keys as $context => $keys ) {
			$known_keys = array_merge( $keys['title'], $keys['desc'], $keys['noindex'], $keys['disable'] );
			if ( ! array_intersect( $known_keys, array_keys( $titles ) ) ) {
				continue;
			}
			$has_noindex = (bool) array_intersect( $keys['noindex'], array_keys( $titles ) );
			$disabled    = (bool) array_filter( $keys['disable'], fn( string $key ): bool => $this->enabled( $titles[ $key ] ?? false ) );
			$noindex     = $disabled || ( $has_noindex && $this->enabled( $this->first_nested_value( $titles, $keys['noindex'], false ) ) );
			$special[ $context ] = $this->global_meta_row(
				$this->special_template( $this->first_nested_value( $titles, $keys['title'], '' ), 'seopress', $context ),
				$this->special_template( $this->first_nested_value( $titles, $keys['desc'], '' ), 'seopress', $context ),
				( $has_noindex || $disabled ) ? array( 'noindex' => $noindex ) : null,
				$noindex ? false : null
			);
		}
		if ( $special ) {
			$settings['global_special_meta'] = $special;
		}

		$identity = strtolower( (string) ( $social['seopress_social_knowledge_type'] ?? '' ) );
		if ( in_array( $identity, array( 'person', 'organization' ), true ) ) {
			$settings['schema_identity'] = $identity;
		}
		$name = sanitize_text_field( (string) ( $social['seopress_social_knowledge_name'] ?? '' ) );
		if ( 'person' === ( $settings['schema_identity'] ?? '' ) ) {
			$settings['schema_person_user_id'] = $this->person_user_id_or_warning( $social['seopress_social_knowledge_user_id'] ?? 0, $name );
		} elseif ( '' !== $name ) {
			$settings['organization_name'] = $name;
		}
		if ( ! empty( $social['seopress_social_knowledge_img_attachment_id'] ) ) {
			$settings['organization_logo'] = absint( $social['seopress_social_knowledge_img_attachment_id'] );
		}
		if ( ! empty( $social['seopress_social_knowledge_img'] ) ) {
			$settings['organization_logo_url'] = esc_url_raw( (string) $social['seopress_social_knowledge_img'] );
		}
		foreach ( array(
			'organization_description'    => array( 'seopress_social_knowledge_desc' ),
			'organization_email'          => array( 'seopress_social_knowledge_email' ),
			'organization_phone'          => array( 'seopress_social_knowledge_phone' ),
			'organization_legal_name'     => array( 'seopress_social_knowledge_legal_name' ),
			'organization_tax_id'         => array( 'seopress_social_knowledge_tax_id' ),
			'organization_street_address' => array( 'seopress_social_knowledge_street', 'seopress_social_knowledge_address_street' ),
			'organization_locality'       => array( 'seopress_social_knowledge_locality', 'seopress_social_knowledge_address_locality' ),
			'organization_region'         => array( 'seopress_social_knowledge_region', 'seopress_social_knowledge_address_region' ),
			'organization_postal_code'    => array( 'seopress_social_knowledge_postal_code', 'seopress_social_knowledge_address_pc' ),
			'organization_country'        => array( 'seopress_social_knowledge_country', 'seopress_social_knowledge_address_country' ),
		) as $target => $source_keys ) {
			$value = $this->first_nested_value( $social, $source_keys, '' );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$settings[ $target ] = (string) $value;
			}
		}

		$profile_keys = array( 'seopress_social_accounts_facebook', 'seopress_social_accounts_twitter', 'seopress_social_accounts_pinterest', 'seopress_social_accounts_instagram', 'seopress_social_accounts_youtube', 'seopress_social_accounts_linkedin', 'seopress_social_accounts_extra' );
		$profile_values = array();
		foreach ( $profile_keys as $key ) {
			$profile_values[] = $social[ $key ] ?? '';
		}
		$profiles = $this->social_profile_list( $profile_values );
		if ( '' !== $profiles ) {
			$settings['social_profiles'] = $profiles;
		}
		$twitter_site = $this->social_handle( $social['seopress_social_accounts_twitter'] ?? '' );
		if ( '' !== $twitter_site ) {
			$settings['twitter_site'] = $twitter_site;
		}
		if ( ! empty( $social['seopress_social_facebook_img'] ) ) {
			$settings['default_social_image_url'] = esc_url_raw( (string) $social['seopress_social_facebook_img'] );
		}

		if ( array_key_exists( 'seopress_xml_sitemap_general_enable', $sitemap ) ) {
			$settings['enable_sitemap'] = $this->enabled( $sitemap['seopress_xml_sitemap_general_enable'] ) ? 1 : 0;
		} else {
			foreach ( array( 'toggle-xml-sitemap', 'xml-sitemap', 'xml_sitemap', 'sitemap' ) as $key ) {
				if ( array_key_exists( $key, $toggles ) ) {
					$settings['enable_sitemap'] = $this->enabled( $toggles[ $key ] ) ? 1 : 0;
					break;
				}
			}
		}
		if ( array_key_exists( 'seopress_xml_sitemap_img_enable', $sitemap ) ) {
			$settings['enable_image_sitemap'] = $this->enabled( $sitemap['seopress_xml_sitemap_img_enable'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'toggle-breadcrumbs', $toggles ) ) {
			$settings['enable_breadcrumbs'] = $this->enabled( $toggles['toggle-breadcrumbs'] ) ? 1 : 0;
		}
		if ( $this->enabled( $advanced['seopress_advanced_advanced_attachments_file'] ?? false ) ) {
			$settings['attachment_redirect'] = 'file';
		} elseif ( $this->enabled( $advanced['seopress_advanced_advanced_attachments'] ?? false ) ) {
			$settings['attachment_redirect'] = 'parent';
		} elseif ( array_key_exists( 'seopress_advanced_advanced_attachments', $advanced ) || array_key_exists( 'seopress_advanced_advanced_attachments_file', $advanced ) ) {
			$settings['attachment_redirect'] = 'none';
		}
		if ( in_array( 'redirects', $this->modules(), true ) ) {
			$settings['enable_redirects']        = 1;
			$settings['redirect_exclude_admins'] = 0;
		}

		return $settings;
	}

	public function is_available(): bool {
		if ( $this->uses_export_file() ) {
			return 'supported' === (string) $this->profile()['storage_status'];
		}

		return $this->has_meta( 'post', $this->content_keys(), array( '_seopress_pro_rich_snippets_' ) )
			|| $this->has_meta( 'term', $this->content_keys() )
			|| $this->has_meta( 'post', $this->redirect_keys() )
			|| $this->has_meta( 'term', $this->redirect_keys() )
			|| $this->has_redirect_posts()
			|| $this->has_option_map( 'seopress_titles_option_name' )
			|| $this->has_option_map( 'seopress_social_option_name' )
			|| $this->has_option_map( 'seopress_xml_sitemap_option_name' )
			|| $this->has_option_map( 'seopress_toggle' )
			|| $this->has_option_map( 'seopress_advanced_option_name' );
	}

	/** @return iterable<int,array<string,mixed>> */
	public function content_records(): iterable {
		foreach ( array( 'post', 'term' ) as $object_type ) {
			$prefixes = 'post' === $object_type ? array( '_seopress_pro_rich_snippets_' ) : array();
			foreach ( $this->meta_objects( $object_type, $this->content_keys(), $prefixes ) as $record ) {
				$mapped = $this->map_meta( $record['meta'], $object_type, $record['id'] );
				if ( ! empty( $mapped ) ) {
					yield array(
						'object_type'      => $object_type,
						'object_id'        => $record['id'],
						'meta'             => $mapped,
						'source_reference' => $object_type . ':' . $record['id'],
					);
				}
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

		$stages = array( 'post', 'term' );
		$stage  = sanitize_key( (string) ( $cursor['stage'] ?? 'post' ) );
		$stage  = in_array( $stage, $stages, true ) ? $stage : 'post';
		$limit  = max( 1, min( 500, $limit ) );

		while ( true ) {
			$prefixes = 'post' === $stage ? array( '_seopress_pro_rich_snippets_' ) : array();
			$page     = $this->meta_object_batch( $stage, $this->content_keys(), $prefixes, absint( $cursor['after_id'] ?? 0 ), $limit );
			$records  = array();
			foreach ( $page['records'] as $record ) {
				$mapped = $this->map_meta( $record['meta'], $stage, $record['id'] );
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
				if ( 'term' === $stage ) {
					return array(
						'records' => $records,
						'cursor'  => array( 'stage' => 'done' ),
						'done'    => true,
					);
				}
				$stage  = 'term';
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
		foreach ( array( 'post', 'term' ) as $object_type ) {
			foreach ( $this->meta_objects( $object_type, $this->redirect_keys() ) as $record ) {
				$meta = $record['meta'];
				if ( 'post' === $object_type ) {
					$post = get_post( $record['id'] );
					if ( ! $post instanceof WP_Post ) {
						continue;
					}
					$source = 'seopress_404' === $post->post_type ? $post->post_title : get_permalink( $post );
				} else {
					$source = get_term_link( $record['id'] );
				}

				if ( ! is_string( $source ) || '' === trim( $source ) ) {
					continue;
				}

				$type       = absint( $meta['_seopress_redirections_type'] ?? 301 );
				$is_regex   = $this->enabled( $meta['_seopress_redirections_enabled_regex'] ?? false );
				$param_mode = (string) ( $meta['_seopress_redirections_param'] ?? '' );
				$query      = $is_regex ? '' : (string) wp_parse_url( $source, PHP_URL_QUERY );
				$query_mode = 'with_ignored_param' === $param_mode ? 'preserve' : ( 'exact_match' === $param_mode ? 'exact' : 'ignore' );
				$visibility = array(
					'only_logged_in'     => 'logged_in',
					'only_not_logged_in' => 'logged_out',
				)[ (string) ( $meta['_seopress_redirections_logged_status'] ?? '' ) ] ?? 'all';

				yield array(
					'source_path'      => $source,
					'source_query'     => 'exact' === $query_mode ? $query : '',
					'target_url'       => (string) ( $meta['_seopress_redirections_value'] ?? '' ),
					'status_code'      => $type,
					'match_type'       => $is_regex ? 'regex' : 'exact',
					'case_sensitive'   => 0,
					'trailing_slash'   => 'ignore',
					'query_mode'       => $query_mode,
					'is_active'        => $this->enabled( $meta['_seopress_redirections_enabled'] ?? false ) ? 1 : 0,
					'visibility'       => $visibility,
					'source_reference' => $object_type . '-redirect:' . $record['id'],
				);
			}
		}
	}

	/**
 * @param int                 $limit  Maximum source objects to scan.
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 */
	public function redirect_batch( array $cursor, int $limit ): array {
		if ( $this->uses_export_file() ) {
			return ERankly_Migration_Export_Reader::redirect_batch( $this->export_file(), $this->slug(), $cursor, $limit );
		}

		$stages = array( 'post', 'term' );
		$stage  = sanitize_key( (string) ( $cursor['stage'] ?? 'post' ) );
		$stage  = in_array( $stage, $stages, true ) ? $stage : 'post';
		$limit  = max( 1, min( 500, $limit ) );

		while ( true ) {
			$page    = $this->meta_object_batch( $stage, $this->redirect_keys(), array(), absint( $cursor['after_id'] ?? 0 ), $limit );
			$records = array();
			foreach ( $page['records'] as $record ) {
				$redirect = $this->map_redirect_record( $stage, $record );
				if ( is_array( $redirect ) ) {
					$records[] = $redirect;
				}
			}

			if ( $page['done'] ) {
				if ( 'term' === $stage ) {
					return array(
						'records' => $records,
						'cursor'  => array( 'stage' => 'done' ),
						'done'    => true,
					);
				}
				$stage  = 'term';
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

	/** @return array<string,mixed>|null */
	private function map_redirect_record( string $object_type, array $record ): ?array {
		$meta = $record['meta'];
		if ( 'post' === $object_type ) {
			$post = get_post( $record['id'] );
			if ( ! $post instanceof WP_Post ) {
				return null;
			}
			$source = 'seopress_404' === $post->post_type ? $post->post_title : get_permalink( $post );
		} else {
			$source = get_term_link( $record['id'] );
		}

		if ( ! is_string( $source ) || '' === trim( $source ) ) {
			return null;
		}

		$type       = absint( $meta['_seopress_redirections_type'] ?? 301 );
		$is_regex   = $this->enabled( $meta['_seopress_redirections_enabled_regex'] ?? false );
		$param_mode = (string) ( $meta['_seopress_redirections_param'] ?? '' );
		$query      = $is_regex ? '' : (string) wp_parse_url( $source, PHP_URL_QUERY );
		$query_mode = 'with_ignored_param' === $param_mode ? 'preserve' : ( 'exact_match' === $param_mode ? 'exact' : 'ignore' );
		$visibility = array(
			'only_logged_in'     => 'logged_in',
			'only_not_logged_in' => 'logged_out',
		)[ (string) ( $meta['_seopress_redirections_logged_status'] ?? '' ) ] ?? 'all';

		return array(
			'source_path'      => $source,
			'source_query'     => 'exact' === $query_mode ? $query : '',
			'target_url'       => (string) ( $meta['_seopress_redirections_value'] ?? '' ),
			'status_code'      => $type,
			'match_type'       => $is_regex ? 'regex' : 'exact',
			'case_sensitive'   => 0,
			'trailing_slash'   => 'ignore',
			'query_mode'       => $query_mode,
			'is_active'        => $this->enabled( $meta['_seopress_redirections_enabled'] ?? false ) ? 1 : 0,
			'visibility'       => $visibility,
			'source_reference' => $object_type . '-redirect:' . $record['id'],
		);
	}

	/** @return array<string,mixed> */
	private function map_meta( array $meta, string $object_type, int $object_id ): array {
		$get                                  = fn( string $key ): string => $this->value( $meta, $key );
		$mapped                               = array(
			'_erankly_title'               => erankly_import_convert_variables( $get( '_seopress_titles_title' ), 'seopress' ),
			'_erankly_description'         => erankly_import_convert_variables( $get( '_seopress_titles_desc' ), 'seopress' ),
			'_erankly_canonical'           => $get( '_seopress_robots_canonical' ),
			'_erankly_breadcrumb_name'     => $get( '_seopress_robots_breadcrumbs' ),
			'_erankly_og_title'            => erankly_import_convert_variables( $get( '_seopress_social_fb_title' ), 'seopress' ),
			'_erankly_og_description'      => erankly_import_convert_variables( $get( '_seopress_social_fb_desc' ), 'seopress' ),
			'_erankly_og_image_url'        => $get( '_seopress_social_fb_img' ),
			'_erankly_og_image_id'         => absint( $get( '_seopress_social_fb_img_attachment_id' ) ),
			'_erankly_twitter_title'       => erankly_import_convert_variables( $get( '_seopress_social_twitter_title' ), 'seopress' ),
			'_erankly_twitter_description' => erankly_import_convert_variables( $get( '_seopress_social_twitter_desc' ), 'seopress' ),
			'_erankly_twitter_image_url'   => $get( '_seopress_social_twitter_img' ),
			'_erankly_twitter_image_id'    => absint( $get( '_seopress_social_twitter_img_attachment_id' ) ),
		);
		$mapped['_erankly_og_image_alt']      = $this->attachment_alt( $mapped['_erankly_og_image_id'] );
		$mapped['_erankly_twitter_image_alt'] = $this->attachment_alt( $mapped['_erankly_twitter_image_id'] );

		if ( $this->enabled( $meta['_seopress_robots_index'] ?? false ) ) {
			$mapped['_erankly_index_directive'] = 'noindex';
		}
		if ( $this->enabled( $meta['_seopress_robots_follow'] ?? false ) ) {
			$mapped['_erankly_follow_directive'] = 'nofollow';
		}
		if ( $this->enabled( $meta['_seopress_robots_archive'] ?? false ) ) {
			$mapped['_erankly_archive_directive'] = 'noarchive';
		}
		if ( $this->enabled( $meta['_seopress_robots_snippet'] ?? false ) ) {
			$mapped['_erankly_snippet_directive'] = 'nosnippet';
		}
		if ( $this->enabled( $meta['_seopress_robots_imageindex'] ?? false ) ) {
			$mapped['_erankly_image_directive'] = 'noimageindex';
		}

		if ( 'post' === $object_type && absint( $meta['_seopress_robots_primary_cat'] ?? 0 ) > 0 ) {
			$term = get_term( absint( $meta['_seopress_robots_primary_cat'] ) );
			if ( $term instanceof WP_Term ) {
				$mapped['_erankly_primary_terms'] = array( $term->taxonomy => $term->term_id );
			}
		}

		if ( $this->enabled( $meta['_seopress_pro_rich_snippets_disable_all'] ?? false ) ) {
			$mapped['_erankly_schema_mode'] = 'disabled';
		} else {
			$entities = $this->extract_schema_entities( $meta['_seopress_pro_schemas_manual'] ?? array() );
			$legacy   = $this->legacy_schema_entity( $meta );
			if ( ! empty( $legacy ) ) {
				$entities[] = $legacy;
			}
			$blocks = $this->schema_blocks( $entities );
			if ( ! empty( $blocks ) ) {
				$mapped['_erankly_schema_mode']   = 'merge';
				$mapped['_erankly_schema_blocks'] = $blocks;
			}
		}

		$disabled = maybe_unserialize( $meta['_seopress_pro_rich_snippets_disable'] ?? array() );
		if ( is_array( $disabled ) ) {
			$disabled = array_values( array_filter( $disabled, static fn( mixed $type ): bool => is_string( $type ) && preg_match( '/^[A-Za-z][A-Za-z0-9_-]+$/', $type ) === 1 ) );
			if ( ! empty( $disabled ) ) {
				$mapped['_erankly_schema_disabled_types'] = $disabled;
			}
		}

		if ( empty( $mapped['_erankly_schema_blocks'] ) && ! empty( $meta['_seopress_pro_rich_snippets_type'] ) ) {
			$this->add_warning( 'schema_configuration_not_migrated', 'A legacy SEOPress PRO schema could not be converted to rendered EasyRankly JSON-LD.', $object_type . ':' . $object_id );
		}

		return $this->with_extension_meta( $mapped );
	}

	/**
 * Converts the most common legacy SEOPress PRO schema fields.
 *
 * @return array<string,mixed>
 */
	private function legacy_schema_entity( array $meta ): array {
		$source_type = strtolower( $this->value( $meta, '_seopress_pro_rich_snippets_type' ) );
		$type_map    = array(
			'articles'      => 'Article',
			'article'       => 'Article',
			'events'        => 'Event',
			'event'         => 'Event',
			'products'      => 'Product',
			'product'       => 'Product',
			'recipes'       => 'Recipe',
			'recipe'        => 'Recipe',
			'courses'       => 'Course',
			'course'        => 'Course',
			'softwares'     => 'SoftwareApplication',
			'software'      => 'SoftwareApplication',
			'videos'        => 'VideoObject',
			'video'         => 'VideoObject',
			'services'      => 'Service',
			'service'       => 'Service',
			'localbusiness' => 'LocalBusiness',
			'jobs'          => 'JobPosting',
			'job'           => 'JobPosting',
		);
		if ( ! isset( $type_map[ $source_type ] ) ) {
			return array();
		}

		$type = $type_map[ $source_type ];
		$node = array( '@type' => $type );
		foreach ( array(
			'name'        => array( 'name', 'title' ),
			'description' => array( 'description', 'desc' ),
			'startDate'   => array( 'start_date' ),
			'endDate'     => array( 'end_date' ),
		) as $property => $suffixes ) {
			foreach ( $suffixes as $suffix ) {
				$key = '_seopress_pro_rich_snippets_' . $source_type . '_' . $suffix;
				if ( isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) && '' !== trim( (string) $meta[ $key ] ) ) {
					$node[ $property ] = erankly_import_convert_variables( (string) $meta[ $key ], 'seopress' );
					break;
				}
			}
		}

		if ( ! isset( $node['name'] ) && in_array( $type, array( 'Article', 'Event', 'Product', 'Recipe', 'Course', 'SoftwareApplication', 'VideoObject', 'Service', 'JobPosting' ), true ) ) {
			$node['name'] = '{{post_title}}';
		}
		if ( ! isset( $node['description'] ) ) {
			$node['description'] = '{{post_excerpt}}';
		}

		return $node;
	}

	/** @return array<int,string> */
	private function content_keys(): array {
		return array(
			'_seopress_titles_title',
			'_seopress_titles_desc',
			'_seopress_robots_canonical',
			'_seopress_robots_breadcrumbs',
			'_seopress_social_fb_title',
			'_seopress_social_fb_desc',
			'_seopress_social_fb_img',
			'_seopress_social_fb_img_attachment_id',
			'_seopress_social_twitter_title',
			'_seopress_social_twitter_desc',
			'_seopress_social_twitter_img',
			'_seopress_social_twitter_img_attachment_id',
			'_seopress_robots_index',
			'_seopress_robots_follow',
			'_seopress_robots_archive',
			'_seopress_robots_snippet',
			'_seopress_robots_imageindex',
			'_seopress_robots_primary_cat',
			'_seopress_pro_schemas_manual',
			'_seopress_pro_rich_snippets_type',
			'_seopress_pro_rich_snippets_disable_all',
			'_seopress_pro_rich_snippets_disable',
		);
	}

	/** @return array<int,string> */
	private function redirect_keys(): array {
		return array(
			'_seopress_redirections_value',
			'_seopress_redirections_enabled',
			'_seopress_redirections_enabled_regex',
			'_seopress_redirections_logged_status',
			'_seopress_redirections_param',
			'_seopress_redirections_type',
		);
	}

	private function has_redirect_posts(): bool {
		global $wpdb;

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", 'seopress_404' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Source plugin CPT presence check.
	}
}
