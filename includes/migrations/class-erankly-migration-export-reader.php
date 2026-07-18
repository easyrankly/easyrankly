<?php
/**
 * Resumable readers for official SEO-plugin CSV/JSON exports.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates and maps source-plugin exports without writing source data. */
final class ERankly_Migration_Export_Reader {
	/** Maximum JSON file size decoded in one request. */
	private const JSON_MAX_BYTES = 20 * 1024 * 1024;

	/**
	 * Inspects the file signature for one source adapter.
	 *
	 * @param string $path   Local export path.
	 * @param string $source yoast|rankmath|aioseo|seopress.
	 * @return array{status:string,format:string,reason:string}
	 */
	public static function inspect( string $path, string $source ): array {
		if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
			return array(
				'status' => 'unsupported',
				'format' => '',
				'reason' => 'unreadable_export',
			);
		}

		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		try {
			if ( 'csv' === $extension ) {
				$headers = self::csv_headers( $path );
				$format  = self::csv_format( $headers, $source );
				return array(
					'status' => '' === $format ? 'unsupported' : 'supported',
					'format' => $format,
					'reason' => '' === $format ? 'unknown_csv_signature' : '',
				);
			}

			if ( 'json' === $extension && 'aioseo' === $source ) {
				$rows = self::json_rows( $path );
				return array(
					'status' => $rows ? 'supported' : 'unsupported',
					'format' => $rows ? 'aioseo-redirects-json' : '',
					'reason' => $rows ? '' : 'unknown_json_signature',
				);
			}
		} catch ( RuntimeException ) {
			return array(
				'status' => 'unsupported',
				'format' => '',
				'reason' => 'unreadable_export',
			);
		}

		return array(
			'status' => 'unsupported',
			'format' => '',
			'reason' => 'unsupported_export_type',
		);
	}

	/**
	 * Returns a bounded record estimate for inventory reporting.
	 *
	 * @param string $path Local export path.
	 * @return int
	 */
	public static function count_records( string $path ): int {
		if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
			return 0;
		}

		try {
			if ( 'json' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				return count( self::json_rows( $path ) );
			}

			$file  = new SplFileObject( $path, 'rb' );
			$count = -1;
			foreach ( $file as $line ) {
				if ( '' !== trim( (string) $line ) ) {
					++$count;
				}
			}
			return max( 0, $count );
		} catch ( RuntimeException ) {
			return 0;
		}
	}

	/**
	 * Reads one normalized content page from an official export.
	 *
	 * @param string              $path   Local export path.
	 * @param string              $source Adapter slug.
	 * @param array<string,mixed> $cursor Resume cursor.
	 * @param int                 $limit  Maximum source rows.
	 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
	 */
	public static function content_batch( string $path, string $source, array $cursor, int $limit ): array {
		$inspection = self::inspect( $path, $source );
		$format     = (string) $inspection['format'];
		if ( ! in_array( $format, array( 'rankmath-metadata-csv', 'seopress-metadata-csv' ), true ) ) {
			return array(
				'records' => array(),
				'cursor'  => array( 'row' => 0 ),
				'done'    => true,
			);
		}

		$page    = self::csv_page( $path, absint( $cursor['row'] ?? 0 ), $limit );
		$records = array();
		foreach ( $page['rows'] as $index => $row ) {
			$record = self::map_content_row( $source, $row, $page['start'] + $index + 2 );
			if ( $record ) {
				$records[] = $record;
			}
		}

		return array(
			'records' => $records,
			'cursor'  => array( 'row' => $page['next'] ),
			'done'    => $page['done'],
		);
	}

	/**
	 * Reads one normalized redirect page from an official export.
	 *
	 * @param string              $path   Local export path.
	 * @param string              $source Adapter slug.
	 * @param array<string,mixed> $cursor Resume cursor.
	 * @param int                 $limit  Maximum source rows.
	 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
	 */
	public static function redirect_batch( string $path, string $source, array $cursor, int $limit ): array {
		$inspection = self::inspect( $path, $source );
		if ( 'supported' !== (string) ( $inspection['status'] ?? '' ) ) {
			return array(
				'records' => array(),
				'cursor'  => array( 'row' => 0 ),
				'done'    => true,
			);
		}
		if ( 'aioseo-redirects-json' === (string) $inspection['format'] ) {
			$rows    = self::json_rows( $path );
			$offset  = max( 0, absint( $cursor['row'] ?? 0 ) );
			$chunk   = array_slice( $rows, $offset, max( 1, min( 500, $limit ) ) );
			$records = array();
			foreach ( $chunk as $index => $row ) {
				$record = self::map_redirect_row( $source, $row, $offset + $index + 1 );
				if ( $record ) {
					$records[] = $record;
				}
			}
			$next = $offset + count( $chunk );
			return array(
				'records' => $records,
				'cursor'  => array( 'row' => $next ),
				'done'    => $next >= count( $rows ),
			);
		}

		$page    = self::csv_page( $path, absint( $cursor['row'] ?? 0 ), $limit );
		$records = array();
		foreach ( $page['rows'] as $index => $row ) {
			$record = self::map_redirect_row( $source, $row, $page['start'] + $index + 2 );
			if ( $record ) {
				$records[] = $record;
			}
		}

		return array(
			'records' => $records,
			'cursor'  => array( 'row' => $page['next'] ),
			'done'    => $page['done'],
		);
	}

	/**
	 * Returns normalized CSV headers with a UTF-8 BOM removed.
	 *
	 * @param string $path Local CSV path.
	 * @return array<int,string>
	 */
	private static function csv_headers( string $path ): array {
		$file      = new SplFileObject( $path, 'rb' );
		$delimiter = self::csv_delimiter( $path );
		$file->setCsvControl( $delimiter, '"', '' );
		$row = $file->fgetcsv( $delimiter, '"', '' );
		if ( ! is_array( $row ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( mixed $header ): string {
						$header = preg_replace( '/^\xEF\xBB\xBF/', '', trim( (string) $header ) );
						return sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( (string) $header ) ) );
					},
					$row
				),
				'strlen'
			)
		);
	}

	/**
	 * Returns a certified format identifier for a header signature.
	 *
	 * @param array<int,string> $headers Normalized CSV headers.
	 * @param string            $source  Adapter slug.
	 * @return string
	 */
	private static function csv_format( array $headers, string $source ): string {
		$has = static fn( array $required ): bool => empty( array_diff( $required, $headers ) );
		if ( 'yoast' === $source && $has( array( 'origin', 'target', 'type', 'format' ) ) ) {
			return 'yoast-redirects-csv';
		}
		if ( 'rankmath' === $source && $has( array( 'source', 'destination' ) ) ) {
			return 'rankmath-redirects-csv';
		}
		if ( 'rankmath' === $source && in_array( 'id', $headers, true ) && ( in_array( 'seo_title', $headers, true ) || in_array( 'seo_description', $headers, true ) ) ) {
			return 'rankmath-metadata-csv';
		}
		if ( 'seopress' === $source && $has( array( 'id', 'meta_title', 'meta_desc' ) ) ) {
			return 'seopress-metadata-csv';
		}
		if ( 'aioseo' === $source && $has( array( 'source_url', 'target_url' ) ) ) {
			return 'aioseo-redirects-csv';
		}

		return '';
	}

	/**
	 * Detects the supported comma or semicolon CSV delimiter.
	 *
	 * @param string $path Local CSV path.
	 * @return string
	 */
	private static function csv_delimiter( string $path ): string {
		$file       = new SplFileObject( $path, 'rb' );
		$line       = (string) $file->fgets();
		$commas     = str_getcsv( $line, ',', '"', '' );
		$semicolons = str_getcsv( $line, ';', '"', '' );

		return count( $semicolons ) > count( $commas ) ? ';' : ',';
	}

	/**
	 * Reads one CSV page by physical data-row offset.
	 *
	 * @param string $path   Local CSV path.
	 * @param int    $offset Physical data-row offset.
	 * @param int    $limit  Maximum source rows.
	 * @return array{rows:array<int,array<string,string>>,start:int,next:int,done:bool}
	 */
	private static function csv_page( string $path, int $offset, int $limit ): array {
		$headers = self::csv_headers( $path );
		$limit   = max( 1, min( 500, $limit ) );
		$file    = new SplFileObject( $path, 'rb' );
		$file->setCsvControl( self::csv_delimiter( $path ), '"', '' );
		$file->setFlags( SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE );
		$file->seek( $offset + 1 );
		$rows    = array();
		$scanned = 0;

		while ( ! $file->eof() && $scanned < $limit ) {
			$values = $file->current();
			$file->next();
			if ( ! is_array( $values ) || array( null ) === $values || array( '' ) === $values ) {
				continue;
			}
			++$scanned;
			$values   = array_pad( array_map( 'strval', $values ), count( $headers ), '' );
			$combined = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
			$rows[]   = false === $combined ? array() : $combined;
		}

		return array(
			'rows'  => $rows,
			'start' => $offset,
			'next'  => $offset + $scanned,
			'done'  => $file->eof(),
		);
	}

	/**
	 * Maps Rank Math or SEOPress metadata CSV rows.
	 *
	 * @param string              $source Adapter slug.
	 * @param array<string,mixed> $row    Normalized CSV row.
	 * @param int                 $line   Physical source line.
	 * @return array<string,mixed>
	 */
	private static function map_content_row( string $source, array $row, int $line ): array {
		$object_id = absint( $row['id'] ?? 0 );
		if ( $object_id < 1 ) {
			return array();
		}

		$object_type = 'post';
		$meta        = array();
		if ( 'rankmath' === $source ) {
			$type        = sanitize_key( (string) ( $row['object_type'] ?? $row['type'] ?? 'post' ) );
			$object_type = in_array( $type, array( 'post', 'term', 'user' ), true ) ? $type : 'post';
			$meta        = array(
				'_erankly_title'               => erankly_import_convert_variables( (string) ( $row['seo_title'] ?? '' ), 'rankmath' ),
				'_erankly_description'         => erankly_import_convert_variables( (string) ( $row['seo_description'] ?? '' ), 'rankmath' ),
				'_erankly_canonical'           => (string) ( $row['canonical_url'] ?? '' ),
				'_erankly_og_title'            => erankly_import_convert_variables( (string) ( $row['social_facebook_title'] ?? '' ), 'rankmath' ),
				'_erankly_og_description'      => erankly_import_convert_variables( (string) ( $row['social_facebook_description'] ?? '' ), 'rankmath' ),
				'_erankly_og_image_url'        => (string) ( $row['social_facebook_thumbnail'] ?? '' ),
				'_erankly_twitter_title'       => erankly_import_convert_variables( (string) ( $row['social_twitter_title'] ?? '' ), 'rankmath' ),
				'_erankly_twitter_description' => erankly_import_convert_variables( (string) ( $row['social_twitter_description'] ?? '' ), 'rankmath' ),
				'_erankly_twitter_image_url'   => (string) ( $row['social_twitter_thumbnail'] ?? '' ),
				'_erankly_focus_keywords'      => self::list_value( (string) ( $row['focus_keyword'] ?? '' ) ),
				'_erankly_cornerstone'         => self::truthy( $row['is_pillar_content'] ?? '' ),
			);
			$meta        = array_merge( $meta, self::robots_meta( (string) ( $row['robots'] ?? '' ), (string) ( $row['advanced_robots'] ?? '' ) ) );
			$schema      = self::schema_blocks( $row['schema_data'] ?? '' );
			if ( $schema ) {
				$meta['_erankly_schema_mode']   = 'merge';
				$meta['_erankly_schema_blocks'] = $schema;
			}
			if ( '' !== trim( (string) ( $row['primary_term'] ?? '' ) ) ) {
				$meta['_erankly_legacy_editorial'] = array( 'rankmath_primary_term_slug' => sanitize_title( (string) $row['primary_term'] ) );
			}
		} elseif ( 'seopress' === $source ) {
			$object_type = '' !== trim( (string) ( $row['taxonomy'] ?? '' ) ) ? 'term' : 'post';
			$meta        = array(
				'_erankly_title'               => erankly_import_convert_variables( (string) ( $row['meta_title'] ?? '' ), 'seopress' ),
				'_erankly_description'         => erankly_import_convert_variables( (string) ( $row['meta_desc'] ?? '' ), 'seopress' ),
				'_erankly_canonical'           => (string) ( $row['canonical_url'] ?? '' ),
				'_erankly_breadcrumb_name'     => (string) ( $row['custom_breadcrumbs'] ?? '' ),
				'_erankly_og_title'            => erankly_import_convert_variables( (string) ( $row['fb_title'] ?? '' ), 'seopress' ),
				'_erankly_og_description'      => erankly_import_convert_variables( (string) ( $row['fb_desc'] ?? '' ), 'seopress' ),
				'_erankly_og_image_url'        => (string) ( $row['fb_img'] ?? '' ),
				'_erankly_twitter_title'       => erankly_import_convert_variables( (string) ( $row['tw_title'] ?? '' ), 'seopress' ),
				'_erankly_twitter_description' => erankly_import_convert_variables( (string) ( $row['tw_desc'] ?? '' ), 'seopress' ),
				'_erankly_twitter_image_url'   => (string) ( $row['tw_img'] ?? '' ),
				'_erankly_focus_keywords'      => self::list_value( (string) ( $row['target_kw'] ?? '' ) ),
			);
			if ( self::truthy( $row['noindex'] ?? '' ) ) {
				$meta['_erankly_index_directive'] = 'noindex';
			}
			if ( self::truthy( $row['nofollow'] ?? '' ) ) {
				$meta['_erankly_follow_directive'] = 'nofollow';
			}
			if ( self::truthy( $row['noimageindex'] ?? '' ) ) {
				$meta['_erankly_image_directive'] = 'noimageindex';
			}
			if ( self::truthy( $row['nosnippet'] ?? '' ) ) {
				$meta['_erankly_snippet_directive'] = 'nosnippet';
			}
			if ( absint( $row['primary_cat'] ?? 0 ) > 0 ) {
				$meta['_erankly_primary_terms'] = array( 'category' => absint( $row['primary_cat'] ) );
			}
		}

		$meta = array_filter( $meta, static fn( mixed $value ): bool => ! ( '' === $value || array() === $value || null === $value || false === $value ) );
		return array(
			'object_type'      => $object_type,
			'object_id'        => $object_id,
			'meta'             => $meta,
			'source_reference' => 'export-line:' . $line,
		);
	}

	/**
	 * Maps one official redirect export row.
	 *
	 * @param string              $source Adapter slug.
	 * @param array<string,mixed> $row    Normalized export row.
	 * @param int                 $line   Physical source line or JSON position.
	 * @return array<string,mixed>
	 */
	private static function map_redirect_row( string $source, array $row, int $line ): array {
		$origin       = '';
		$target       = '';
		$type         = 301;
		$match        = 'exact';
		$active       = 1;
		$case         = 0;
		$query_mode   = 'preserve';
		$source_query = '';
		$visibility   = 'all';

		if ( 'yoast' === $source ) {
			$origin = (string) ( $row['origin'] ?? '' );
			$target = (string) ( $row['target'] ?? '' );
			$type   = absint( $row['type'] ?? 301 );
			$match  = 'regex' === strtolower( trim( (string) ( $row['format'] ?? '' ) ) ) ? 'regex' : 'exact';
			$case   = 1;
		} elseif ( 'rankmath' === $source ) {
			if ( isset( $row['source'] ) ) {
				$origin     = (string) $row['source'];
				$target     = (string) ( $row['destination'] ?? '' );
				$type       = absint( $row['type'] ?? 301 );
				$comparison = strtolower( trim( (string) ( $row['matching'] ?? 'exact' ) ) );
				$match      = array(
					'contains' => 'contains',
					'start'    => 'starts_with',
					'end'      => 'ends_with',
					'regex'    => 'regex',
				)[ $comparison ] ?? 'exact';
				$active     = in_array( strtolower( trim( (string) ( $row['status'] ?? 'active' ) ) ), array( 'active', '1', 'yes' ), true ) ? 1 : 0;
				$case       = self::truthy( $row['ignore'] ?? '' ) ? 0 : 1;
			} else {
				$origin = (string) ( $row['url'] ?? $row['permalink'] ?? '' );
				$target = (string) ( $row['redirect_to'] ?? '' );
				$type   = absint( $row['redirect_type'] ?? 301 );
			}
		} elseif ( 'seopress' === $source ) {
			$origin     = (string) ( $row['url'] ?? '' );
			$target     = (string) ( $row['redirect_url'] ?? '' );
			$type       = absint( $row['redirect_type'] ?? 301 );
			$active     = self::truthy( $row['redirect_active'] ?? '' ) ? 1 : 0;
			$visibility = array(
				'only_logged_in'     => 'logged_in',
				'only_not_logged_in' => 'logged_out',
			)[ strtolower( trim( (string) ( $row['redirect_status'] ?? '' ) ) ) ] ?? 'all';
		} elseif ( 'aioseo' === $source ) {
			$origin     = self::first_value( $row, array( 'source_url', 'source', 'origin' ) );
			$target     = self::first_value( $row, array( 'target_url', 'target', 'destination', 'url_to' ) );
			$type_value = self::first_value( $row, array( 'type', 'status_code', 'http_code' ) );
			$type       = absint( '' === $type_value ? 301 : $type_value );
			$match      = self::truthy( self::first_value( $row, array( 'regex', 'is_regex' ) ) ) || 'regex' === strtolower( self::first_value( $row, array( 'match_type', 'source_url_match' ) ) ) ? 'regex' : 'exact';
			$active     = in_array( strtolower( self::first_value( $row, array( 'status', 'is_active' ) ) ), array( '', 'active', '1', 'yes', 'true' ), true ) ? 1 : 0;
		}

		$origin = trim( $origin );
		$target = trim( $target );
		if ( '' === $origin || ( '' === $target && ! in_array( $type, array( 410, 451 ), true ) ) ) {
			return array();
		}
		if ( 'regex' !== $match ) {
			$query = (string) wp_parse_url( $origin, PHP_URL_QUERY );
			if ( '' !== $query ) {
				$source_query = $query;
				$query_mode   = 'exact';
			}
		}

		return array(
			'source_path'      => $origin,
			'source_query'     => $source_query,
			'target_url'       => $target,
			'status_code'      => $type,
			'match_type'       => $match,
			'case_sensitive'   => $case,
			'trailing_slash'   => 'ignore',
			'query_mode'       => $query_mode,
			'is_active'        => $active,
			'visibility'       => $visibility,
			'source_reference' => 'export-line:' . $line,
		);
	}

	/**
	 * Extracts redirect-like objects from an AIOSEO JSON export.
	 *
	 * @param string $path Local AIOSEO JSON path.
	 * @return array<int,array<string,mixed>>
	 */
	private static function json_rows( string $path ): array {
		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > self::JSON_MAX_BYTES ) {
			return array();
		}
		$file     = new SplFileObject( $path, 'rb' );
		$contents = '';
		foreach ( $file as $line ) {
			$contents .= (string) $line;
		}
		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$rows = array();
		$walk = static function ( mixed $node ) use ( &$walk, &$rows ): void {
			if ( ! is_array( $node ) ) {
				return;
			}
			$keys       = array_map( 'sanitize_key', array_map( 'strval', array_keys( $node ) ) );
			$has_source = self::first_header( $keys, array( 'source_url', 'source', 'origin' ) );
			$has_target = self::first_header( $keys, array( 'target_url', 'target', 'destination', 'url_to' ) );
			if ( $has_source && $has_target ) {
				$clean = array();
				foreach ( $node as $key => $value ) {
					$clean[ sanitize_key( (string) $key ) ] = $value;
				}
				$rows[] = $clean;
				return;
			}
			foreach ( $node as $child ) {
				$walk( $child );
			}
		};
		$walk( $data );

		return $rows;
	}

	/**
	 * Converts CSV robots columns to explicit EasyRankly directives.
	 *
	 * @param string $robots   Basic robots directives.
	 * @param string $advanced Advanced robots directives.
	 * @return array<string,mixed>
	 */
	private static function robots_meta( string $robots, string $advanced ): array {
		$values = array_map( 'trim', explode( ',', strtolower( $robots ) ) );
		$meta   = array();
		if ( in_array( 'noindex', $values, true ) ) {
			$meta['_erankly_index_directive'] = 'noindex';
		} elseif ( in_array( 'index', $values, true ) ) {
			$meta['_erankly_index_directive'] = 'index';
		}
		if ( in_array( 'nofollow', $values, true ) ) {
			$meta['_erankly_follow_directive'] = 'nofollow';
		}
		if ( in_array( 'noarchive', $values, true ) ) {
			$meta['_erankly_archive_directive'] = 'noarchive';
		}
		if ( in_array( 'nosnippet', $values, true ) ) {
			$meta['_erankly_snippet_directive'] = 'nosnippet';
		}
		if ( in_array( 'noimageindex', $values, true ) ) {
			$meta['_erankly_image_directive'] = 'noimageindex';
		}
		foreach ( explode( ',', $advanced ) as $directive ) {
			$parts = array_map( 'trim', explode( '=', $directive, 2 ) );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$key = array(
				'max-snippet'       => '_erankly_max_snippet',
				'max-video-preview' => '_erankly_max_video_preview',
				'max-image-preview' => '_erankly_max_image_preview',
			)[ strtolower( $parts[0] ) ] ?? '';
			if ( '' !== $key ) {
				$meta[ $key ] = $parts[1];
			}
		}

		return $meta;
	}

	/**
	 * Converts Rank Math schema_data JSON to custom schema blocks.
	 *
	 * @param mixed $payload Source schema payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function schema_blocks( mixed $payload ): array {
		if ( is_string( $payload ) ) {
			$payload = json_decode( $payload, true );
		}
		if ( ! is_array( $payload ) ) {
			return array();
		}
		$entities = isset( $payload['@graph'] ) && is_array( $payload['@graph'] ) ? $payload['@graph'] : ( isset( $payload['@type'] ) ? array( $payload ) : array_values( array_filter( $payload, 'is_array' ) ) );
		$blocks   = array();
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
				continue;
			}
			unset( $entity['metadata'] );
			$json = wp_json_encode( $entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $json ) && '' !== $json ) {
				$blocks[] = array(
					'type'   => 'custom',
					'fields' => array( 'custom_json' => erankly_import_convert_variables( $json, 'rankmath' ) ),
				);
			}
		}
		return $blocks;
	}

	/**
	 * Returns a comma/newline-delimited list.
	 *
	 * @param string $value Source list.
	 * @return array<int,string>
	 */
	private static function list_value( string $value ): array {
		$values = preg_split( '/[\r\n,]+/', $value );
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', is_array( $values ) ? $values : array() ), 'strlen' ) ) );
	}

	/**
	 * Source truthy helper.
	 *
	 * @param mixed $value Source value.
	 * @return bool
	 */
	private static function truthy( mixed $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on', 'active', 'enabled' ), true );
	}

	/**
	 * Returns the first matching header name.
	 *
	 * @param array<int,string> $headers    Normalized headers.
	 * @param array<int,string> $candidates Accepted aliases.
	 * @return string
	 */
	private static function first_header( array $headers, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $headers, true ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Returns the first scalar row value among aliases.
	 *
	 * @param array<string,mixed> $row        Normalized row.
	 * @param array<int,string>   $candidates Accepted aliases.
	 * @return string
	 */
	private static function first_value( array $row, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( isset( $row[ $candidate ] ) && is_scalar( $row[ $candidate ] ) ) {
				return trim( (string) $row[ $candidate ] );
			}
		}
		return '';
	}
}
