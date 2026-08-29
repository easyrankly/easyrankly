<?php
/**
 * Rank Math SEO and Rank Math PRO migration adapter.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rank Math Free/PRO adapter. */
final class ERankly_Migration_Adapter_RankMath extends ERankly_Migration_Adapter {
	/**
	 * Normalizes keyed values from the rank_math_modules option.
	 *
	 * @param mixed $value Source option value.
	 * @return bool
	 */
	private static function enabled_module_value( mixed $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true', 'active', 'enabled' ), true );
	}

	/**
	 * Returns the adapter slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'rankmath';
	}

	/**
	 * Returns the source label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * Returns the detected source version.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->detect_version(
			'RANK_MATH_VERSION',
			array( 'rank_math_version' ),
			array( 'seo-by-rank-math/rank-math.php' )
		);
	}

	/** Returns Free or PRO from installed code. */
	public function edition(): string {
		return defined( 'RANK_MATH_PRO_VERSION' ) || ! empty( $this->installed_plugins( array( 'seo-by-rank-math-pro/rank-math-pro.php' ) ) ) ? 'pro' : 'free';
	}

	/** Returns enabled Rank Math modules plus the PRO product profile. */
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

	/** Returns certification state for detected Rank Math modules. */
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

	/**
	 * Returns the product/module profile proven by Rank Math's export format.
	 *
	 * @param string $format Certified export format.
	 * @return array{edition:string,modules:array<int,string>,module_support:array<string,string>}
	 */
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

	/**
	 * Returns the supported source capabilities.
	 *
	 * @return array<int,string>
	 */
	public function capabilities(): array {
		return array( 'posts', 'terms', 'authors', 'social', 'advanced robots', 'schema', 'primary terms', 'focus keyphrases', 'pillar content', 'redirections', 'multi-source and regex redirects' );
	}

	/**
	 * Determines whether Rank Math data is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( $this->uses_export_file() ) {
			return 'supported' === (string) $this->profile()['storage_status'];
		}

		return $this->has_meta( 'post', $this->keys(), array( 'rank_math_schema_', 'rank_math_primary_' ) )
			|| $this->has_meta( 'term', $this->keys(), array( 'rank_math_schema_' ) )
			|| $this->has_meta( 'user', $this->keys() )
			|| $this->redirect_table_has_rows();
	}

	/**
	 * Yields normalized content records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
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
	 * Returns one keyset-paginated Rank Math metadata page.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
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

	/**
	 * Yields normalized redirect records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function redirect_records(): iterable {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! erankly_table_exists( $table ) ) {
			return;
		}

		$cursor = 0;
		do {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the source plugin's redirect table in bounded batches.
				$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT 200', $table, $cursor ),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}
			$batch_count = count( $rows );

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
		} while ( 200 === $batch_count );
	}

	/**
	 * Returns a bounded redirect page, including a cursor within multi-source
	 * rules so one unusually large Rank Math row cannot monopolize a worker.
	 *
	 * @param array<string,mixed> $cursor Resume cursor.
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
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset scan of third-party redirects.
			$wpdb->prepare( "SELECT * FROM %i WHERE id {$operator} %d ORDER BY id ASC LIMIT %d", $table, $boundary, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The comparison operator is selected from the two internal literals above.
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

	/**
	 * Maps Rank Math metadata.
	 *
	 * @param array<string,mixed> $meta        Source metadata.
	 * @param string              $object_type post|term|user.
	 * @return array<string,mixed>
	 */
	private function map_meta( array $meta, string $object_type ): array {
		$get                                  = fn( string $key ): string => $this->value( $meta, $key );
		$mapped                               = array(
			'_erankly_title'               => erankly_import_convert_variables( $get( 'rank_math_title' ), 'rankmath' ),
			'_erankly_description'         => erankly_import_convert_variables( $get( 'rank_math_description' ), 'rankmath' ),
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

		$editorial = array();
		$keywords  = $this->keywords( $meta['rank_math_focus_keyword'] ?? '' );
		if ( ! empty( $keywords ) ) {
			$editorial['focus_keywords'] = $keywords;
		}
		if ( $this->enabled( $meta['rank_math_pillar_content'] ?? '' ) ) {
			$editorial['cornerstone'] = true;
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

		return $this->with_extension_meta( $mapped, $editorial );
	}

	/**
	 * Normalizes a Rank Math Twitter card value.
	 *
	 * @param string $value Source card value.
	 * @return string
	 */
	private function twitter_card( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return false !== strpos( strtolower( $value ), 'large' ) ? 'summary_large_image' : 'summary';
	}

	/**
	 * Converts Rank Math variables throughout a schema value.
	 *
	 * @param mixed $value Schema value.
	 * @return mixed
	 */
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

	/**
	 * Returns the exact Rank Math metadata keys to scan.
	 *
	 * @return array<int,string>
	 */
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
			'rank_math_focus_keyword',
			'rank_math_pillar_content',
		);
	}

	/**
	 * Determines whether the Rank Math redirect table contains data.
	 *
	 * @return bool
	 */
	private function redirect_table_has_rows(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! erankly_table_exists( $table ) ) {
			return false;
		}

		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence check against the fixed prefixed source table.
	}
}
