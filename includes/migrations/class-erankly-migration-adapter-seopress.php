<?php
/**
 * SEOPress and SEOPress PRO migration adapter.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** SEOPress Free/PRO adapter. */
final class ERankly_Migration_Adapter_SEOPress extends ERankly_Migration_Adapter {
	/**
	 * Returns the adapter slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'seopress';
	}

	/**
	 * Returns the source label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'SEOPress';
	}

	/**
	 * Returns the detected source version.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->detect_version(
			'SEOPRESS_VERSION',
			array( 'seopress_version', 'seopress_db_version' ),
			array( 'wp-seopress/seopress.php' )
		);
	}

	/** Returns Free or PRO from installed code and paid data surfaces. */
	public function edition(): string {
		$pro = defined( 'SEOPRESS_PRO_VERSION' )
			|| ! empty( $this->installed_plugins( array( 'wp-seopress-pro/seopress-pro.php' ) ) )
			|| $this->has_meta( 'post', array( '_seopress_pro_schemas_manual', '_seopress_pro_rich_snippets_type' ), array( '_seopress_pro_rich_snippets_' ) )
			|| $this->has_redirect_posts();

		return $pro ? 'pro' : 'free';
	}

	/** Returns independently visible SEOPress feature profiles. */
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

	/** Returns certification state for detected SEOPress modules. */
	public function module_support(): array {
		$support = array();
		foreach ( $this->modules() as $module ) {
			$support[ $module ] = in_array( $module, array( 'titles', 'social', 'robots', 'schemas', 'redirects', 'pro' ), true ) ? 'supported' : 'review_required';
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

	/**
	 * Returns the edition-neutral profile proven by SEOPress metadata exports.
	 *
	 * @param string $format Certified export format.
	 * @return array{edition:string,modules:array<int,string>,module_support:array<string,string>}
	 */
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

	/**
	 * Returns the supported source capabilities.
	 *
	 * @return array<int,string>
	 */
	public function capabilities(): array {
		return array( 'posts', 'terms', 'social', 'robots', 'primary category', 'target keywords', 'PRO schemas', 'PRO redirects', 'regex, query and login redirect conditions' );
	}

	/**
	 * Determines whether SEOPress data is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( $this->uses_export_file() ) {
			return 'supported' === (string) $this->profile()['storage_status'];
		}

		return $this->has_meta( 'post', $this->content_keys(), array( '_seopress_pro_rich_snippets_' ) )
			|| $this->has_meta( 'term', $this->content_keys() )
			|| $this->has_meta( 'post', $this->redirect_keys() )
			|| $this->has_meta( 'term', $this->redirect_keys() )
			|| $this->has_redirect_posts();
	}

	/**
	 * Yields normalized content records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
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
	 * Returns one keyset-paginated SEOPress content page.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
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

	/**
	 * Yields normalized redirect records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
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
	 * Returns one keyset-paginated SEOPress Free/PRO redirect page.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
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

	/**
	 * Maps one SEOPress redirect metadata owner.
	 *
	 * @param string                                 $object_type post|term.
	 * @param array{id:int,meta:array<string,mixed>} $record Source record.
	 * @return array<string,mixed>|null
	 */
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

	/**
	 * Maps SEOPress metadata.
	 *
	 * @param array<string,mixed> $meta        Source metadata.
	 * @param string              $object_type post|term.
	 * @param int                 $object_id   Object ID.
	 * @return array<string,mixed>
	 */
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

		$editorial = array();
		$keywords  = $this->keywords( $meta['_seopress_analysis_target_kw'] ?? '' );
		if ( ! empty( $keywords ) ) {
			$editorial['focus_keywords'] = $keywords;
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

		$legacy_payload = array();
		foreach ( $meta as $key => $value ) {
			if ( str_starts_with( (string) $key, '_seopress_pro_rich_snippets_' ) || in_array( $key, array( '_seopress_pro_schemas_manual', '_seopress_analysis_data' ), true ) ) {
				if ( ! empty( $value ) ) {
					$legacy_payload[ $key ] = $value;
				}
			}
		}
		if ( ! empty( $legacy_payload ) ) {
			$mapped['_erankly_legacy_editorial'] = array( 'seopress' => $legacy_payload );
			if ( empty( $mapped['_erankly_schema_blocks'] ) && ! empty( $meta['_seopress_pro_rich_snippets_type'] ) ) {
				$this->add_warning( 'legacy_schema_review', 'A legacy SEOPress PRO schema was preserved for manual review.', $object_type . ':' . $object_id );
			}
		}

		return $this->with_extension_meta( $mapped, $editorial );
	}

	/**
	 * Converts the most common legacy SEOPress PRO schema fields.
	 *
	 * @param array<string,mixed> $meta Source metadata.
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

	/**
	 * Returns exact SEOPress content metadata keys.
	 *
	 * @return array<int,string>
	 */
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
			'_seopress_analysis_target_kw',
			'_seopress_analysis_data',
			'_seopress_pro_schemas_manual',
			'_seopress_pro_rich_snippets_type',
			'_seopress_pro_rich_snippets_disable_all',
			'_seopress_pro_rich_snippets_disable',
		);
	}

	/**
	 * Returns exact SEOPress redirect metadata keys.
	 *
	 * @return array<int,string>
	 */
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

	/**
	 * Determines whether SEOPress redirect posts exist.
	 *
	 * @return bool
	 */
	private function has_redirect_posts(): bool {
		global $wpdb;

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", 'seopress_404' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Source plugin CPT presence check.
	}
}
