<?php
/** Resumable readers for official SEO-plugin CSV/JSON exports. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates and maps source-plugin exports without writing source data. */
final class ERankly_Migration_Export_Reader {
	/** Maximum JSON file size decoded in one request. */
	private const JSON_MAX_BYTES = 20 * 1024 * 1024;
	/** Version of the durable normalized JSON sidecar format. */
	private const JSON_INDEX_VERSION = 1;

	/** Returns the hard-bounded JSON upload/read limit. */
	public static function json_max_bytes(): int {
		$filtered = (int) apply_filters( 'erankly_migration_export_json_max_bytes', self::JSON_MAX_BYTES );

		return max( 1024, min( self::JSON_MAX_BYTES, $filtered ) );
	}

	/**
 * Inspects the file signature for one source adapter.
 *
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
				$index     = self::json_index( $path );
				$supported = '' !== $index && self::json_index_has_rows( $index );
				return array(
					'status' => $supported ? 'supported' : 'unsupported',
					'format' => $supported ? 'aioseo-redirects-json' : '',
					'reason' => $supported ? '' : 'unknown_json_signature',
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

	public static function count_records( string $path ): int {
		if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
			return 0;
		}

		try {
			if ( 'json' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				$index = self::json_index( $path );
				if ( '' === $index ) {
					return 0;
				}
				$file  = new SplFileObject( $index, 'rb' );
				$count = -1;
				foreach ( $file as $line ) {
					if ( '' !== trim( (string) $line ) ) {
						++$count;
					}
				}
				return max( 0, $count );
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

	/** @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool} */
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

		$page    = self::csv_page( $path, $cursor, $limit );
		$records = array();
		foreach ( $page['rows'] as $index => $row ) {
			$record = self::map_content_row( $source, $row, $page['start'] + $index + 2 );
			if ( $record ) {
				$records[] = $record;
			}
		}

		return array(
			'records' => $records,
			'cursor'  => array(
				'row'  => $page['next'],
				'byte' => $page['byte'],
			),
			'done'    => $page['done'],
		);
	}

	/** @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool} */
	public static function redirect_batch( string $path, string $source, array $cursor, int $limit ): array {
		$inspection = self::inspect( $path, $source );
		if ( 'supported' !== (string) ( $inspection['status'] ?? '' ) ) {
			return array(
				'records' => array(),
				'cursor'  => array( 'row' => 0 ),
				'done'    => true,
			);
		}
		$format = (string) $inspection['format'];
		if ( 'aioseo-redirects-json' === $format ) {
			$page    = self::json_page( $path, $cursor, $limit );
			$records = array();
			foreach ( $page['rows'] as $index => $row ) {
				$record = self::map_redirect_row( $source, $row, $page['start'] + $index + 1 );
				if ( $record ) {
					$records[] = $record;
				}
			}
			return array(
				'records' => $records,
				'cursor'  => array(
					'row'  => $page['next'],
					'byte' => $page['byte'],
				),
				'done'    => $page['done'],
			);
		}
		if ( ! in_array( $format, array( 'yoast-redirects-csv', 'rankmath-redirects-csv', 'aioseo-redirects-csv', 'seopress-metadata-csv' ), true ) ) {
			return array(
				'records' => array(),
				'cursor'  => array( 'row' => 0 ),
				'done'    => true,
			);
		}

		$page    = self::csv_page( $path, $cursor, $limit );
		$records = array();
		foreach ( $page['rows'] as $index => $row ) {
			$record = self::map_redirect_row( $source, $row, $page['start'] + $index + 2 );
			if ( $record ) {
				$records[] = $record;
			}
		}

		return array(
			'records' => $records,
			'cursor'  => array(
				'row'  => $page['next'],
				'byte' => $page['byte'],
			),
			'done'    => $page['done'],
		);
	}

	/**
 * Returns normalized CSV headers with a UTF-8 BOM removed.
 *
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

	private static function csv_delimiter( string $path ): string {
		$file       = new SplFileObject( $path, 'rb' );
		$line       = (string) $file->fgets();
		$commas     = str_getcsv( $line, ',', '"', '' );
		$semicolons = str_getcsv( $line, ';', '"', '' );

		return count( $semicolons ) > count( $commas ) ? ';' : ',';
	}

	/**
 * Reads one CSV page from a durable byte cursor. A legacy row-only cursor is accepted once and upgraded to a
 * byte cursor.
 *
 * @return array{rows:array<int,array<string,string>>,start:int,next:int,byte:int,done:bool}
 * @throws RuntimeException When the durable byte cursor is invalid.
 */
	private static function csv_page( string $path, array $cursor, int $limit ): array {
		$headers   = self::csv_headers( $path );
		$limit     = max( 1, min( 500, $limit ) );
		$file      = new SplFileObject( $path, 'rb' );
		$delimiter = self::csv_delimiter( $path );
		$file->setCsvControl( $delimiter, '"', '' );
		$file->fgetcsv( $delimiter, '"', '' );
		$data_start = max( 0, (int) $file->ftell() );
		$offset     = absint( $cursor['row'] ?? 0 );
		$byte       = absint( $cursor['byte'] ?? 0 );
		$file_size  = filesize( $path );
		$rows       = array();
		$scanned    = 0;
		$skipped    = 0;

		if ( 0 < $byte && ( $byte < $data_start || ( false !== $file_size && $byte > $file_size ) ) ) {
			throw new RuntimeException( 'The CSV checkpoint is outside the source file.' );
		}
		if ( $byte >= $data_start ) {
			$file->fseek( $byte );
		} else {
			while ( ! $file->eof() && $skipped < $offset ) {
				$values = $file->fgetcsv( $delimiter, '"', '' );
				if ( ! is_array( $values ) || array( null ) === $values || array( '' ) === $values ) {
					continue;
				}
				++$skipped;
			}
		}

		while ( ! $file->eof() && $scanned < $limit ) {
			$values = $file->fgetcsv( $delimiter, '"', '' );
			if ( ! is_array( $values ) || array( null ) === $values || array( '' ) === $values ) {
				continue;
			}
			++$scanned;
			$values   = array_pad( array_map( 'strval', $values ), count( $headers ), '' );
			$combined = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
			$rows[]   = false === $combined ? array() : $combined;
		}

		$next_byte = max( $data_start, (int) $file->ftell() );
		return array(
			'rows'  => $rows,
			'start' => $offset,
			'next'  => $offset + $scanned,
			'byte'  => $next_byte,
			'done'  => $file->eof() || ( false !== $file_size && $next_byte >= $file_size ),
		);
	}

	/** @return array<string,mixed> */
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
			);
			$meta   = array_merge( $meta, self::robots_meta( (string) ( $row['robots'] ?? '' ), (string) ( $row['advanced_robots'] ?? '' ) ) );
			$schema = self::schema_blocks( $row['schema_data'] ?? '' );
			if ( $schema ) {
				$meta['_erankly_schema_mode']   = 'merge';
				$meta['_erankly_schema_blocks'] = $schema;
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

		$filtered = apply_filters( 'erankly_migration_mapped_meta', $meta, $source );
		$meta     = is_array( $filtered ) ? $filtered : $meta;

		return array(
			'object_type'      => $object_type,
			'object_id'        => $object_id,
			'meta'             => $meta,
			'source_reference' => 'export-line:' . $line,
		);
	}

	/**
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
		$query_mode   = 'ignore';
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
				$ignore     = strtolower( trim( (string) ( $row['ignore'] ?? '' ) ) );
				$case       = 'case' === $ignore || self::truthy( $ignore ) ? 0 : 1;
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
			$match      = self::truthy( $row['redirect_enabled_regex'] ?? $row['redirect_regex'] ?? '' ) ? 'regex' : 'exact';
			$param_mode = strtolower( trim( (string) ( $row['redirect_param'] ?? '' ) ) );
			$query_mode = 'with_ignored_param' === $param_mode ? 'preserve' : ( 'exact_match' === $param_mode ? 'exact' : 'ignore' );
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
			$active     = in_array( strtolower( self::first_value( $row, array( 'enabled', 'status', 'is_active' ) ) ), array( '', 'active', '1', 'yes', 'true', 'enabled' ), true ) ? 1 : 0;
			if ( array_key_exists( 'ignore_case', $row ) ) {
				$case = self::truthy( $row['ignore_case'] ) ? 0 : 1;
			}
			$query_option = strtolower( self::first_value( $row, array( 'query_param', 'query_mode' ) ) );
			if ( str_contains( $query_option, 'pass' ) || str_contains( $query_option, 'preserve' ) ) {
				$query_mode = 'preserve';
			} elseif ( str_contains( $query_option, 'exact' ) ) {
				$query_mode = 'exact';
			}
		}

		$origin = trim( $origin );
		$target = trim( $target );
		if ( '' === $origin || ( '' === $target && ! in_array( $type, array( 410, 451 ), true ) ) ) {
			return array();
		}
		if ( 'regex' !== $match ) {
			$query = (string) wp_parse_url( $origin, PHP_URL_QUERY );
			if ( '' !== $query && 'preserve' !== $query_mode ) {
				$source_query = $query;
				$query_mode   = 'exact';
			}
		} else {
			$query_mode = 'ignore';
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
 * Returns or atomically creates the normalized NDJSON sidecar for a source. The bounded source JSON is decoded
 * exactly once. Every subsequent batch seeks directly to its durable byte checkpoint in this private sidecar.
 *
 * @param string $path Local managed AIOSEO JSON path.
 * @return string Sidecar path, or an empty string when invalid.
 * @throws RuntimeException Internally when a normalized row cannot be staged.
 */
	private static function json_index( string $path ): string {
		$size  = filesize( $path );
		$mtime = filemtime( $path );
		if ( false === $size || false === $mtime || $size < 1 || $size > self::json_max_bytes() ) {
			return '';
		}
		$index = $path . '.ndjson';
		if ( self::json_index_is_current( $index, (int) $size, (int) $mtime ) ) {
			return $index;
		}
		if ( ! class_exists( 'ERankly_Migration_Upload_Store' ) || ! ERankly_Migration_Upload_Store::owns( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Hard-bounded private migration JSON is parsed once into an incremental sidecar.
		$data     = is_string( $contents ) ? json_decode( $contents, true ) : null;
		unset( $contents );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			return '';
		}

		try {
			$suffix = bin2hex( random_bytes( 16 ) );
		} catch ( Exception ) {
			$suffix = str_replace( '-', '', wp_generate_uuid4() );
		}
		$temporary = $index . '.' . $suffix . '.tmp';
		$handle    = fopen( $temporary, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive private sidecar creation prevents partial readers.
		if ( false === $handle ) {
			return '';
		}

		$count  = 0;
		$header = wp_json_encode(
			array(
				'erankly_index' => self::JSON_INDEX_VERSION,
				'source_size'   => (int) $size,
				'source_mtime'  => (int) $mtime,
			),
			JSON_UNESCAPED_SLASHES
		);
		try {
			if ( ! is_string( $header ) ) {
				throw new RuntimeException( 'Unable to write the JSON index header.' );
			}
			self::write_json_index_bytes( $handle, $header . "\n" );
			$walk = static function ( mixed $node ) use ( &$walk, $handle, &$count ): void {
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
					$encoded = wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					if ( ! is_string( $encoded ) ) {
						throw new RuntimeException( 'Unable to write a normalized JSON row.' );
					}
					self::write_json_index_bytes( $handle, $encoded . "\n" );
					++$count;
					return;
				}
				foreach ( $node as $child ) {
					$walk( $child );
				}
			};
			$walk( $data );
		} catch ( Throwable ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired private staging handle.
			unlink( $temporary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Internally generated sibling temp path with an unpredictable suffix.
			return '';
		}
		unset( $data );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired private staging handle.
		if ( $count < 1 || ! chmod( $temporary, 0600 ) || ! rename( $temporary, $index ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.rename_rename -- Private atomic sidecar publication.
			if ( is_file( $temporary ) && ! is_link( $temporary ) ) {
				unlink( $temporary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Internally generated sibling temp path.
			}
			return '';
		}
		clearstatcache( true, $index );

		return self::json_index_is_current( $index, (int) $size, (int) $mtime ) ? $index : '';
	}

	/**
 * Writes a complete sidecar fragment, including after a partial fwrite().
 *
 * @param resource $handle Open private sidecar handle.
 * @throws RuntimeException When the complete fragment cannot be written.
 */
	private static function write_json_index_bytes( $handle, string $bytes ): void {
		$length = strlen( $bytes );
		$offset = 0;
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $bytes, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Complete private sidecar staging write.
			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( 'Unable to write the normalized JSON sidecar.' );
			}
			$offset += $written;
		}
	}

	/** @param int    $mtime Expected source modification time. */
	private static function json_index_is_current( string $index, int $size, int $mtime ): bool {
		if ( ! is_file( $index ) || is_link( $index ) || ! is_readable( $index ) ) {
			return false;
		}
		$file   = new SplFileObject( $index, 'rb' );
		$header = json_decode( trim( (string) $file->fgets() ), true );

		return is_array( $header )
			&& self::JSON_INDEX_VERSION === (int) ( $header['erankly_index'] ?? 0 )
			&& (int) ( $header['source_size'] ?? -1 ) === $size
			&& (int) ( $header['source_mtime'] ?? -1 ) === $mtime;
	}

	/** Returns whether a valid sidecar contains at least one normalized row. */
	private static function json_index_has_rows( string $index ): bool {
		$file = new SplFileObject( $index, 'rb' );
		$file->fgets();

		return '' !== trim( (string) $file->fgets() );
	}

	/**
 * @param string              $path   Managed source JSON path.
 * @return array{rows:array<int,array<string,mixed>>,start:int,next:int,byte:int,done:bool}
 * @throws RuntimeException When the durable normalized sidecar is corrupt.
 */
	private static function json_page( string $path, array $cursor, int $limit ): array {
		$index = self::json_index( $path );
		if ( '' === $index ) {
			return array(
				'rows'  => array(),
				'start' => 0,
				'next'  => 0,
				'byte'  => 0,
				'done'  => true,
			);
		}
		$file = new SplFileObject( $index, 'rb' );
		$file->fgets();
		$data_start = max( 0, (int) $file->ftell() );
		$offset     = absint( $cursor['row'] ?? 0 );
		$byte       = absint( $cursor['byte'] ?? 0 );
		$file_size  = filesize( $index );
		$skipped    = 0;
		if ( 0 < $byte && ( $byte < $data_start || ( false !== $file_size && $byte > $file_size ) ) ) {
			throw new RuntimeException( 'The JSON checkpoint is outside the normalized index.' );
		}
		if ( $byte >= $data_start ) {
			$file->fseek( $byte );
		} else {
			while ( ! $file->eof() && $skipped < $offset ) {
				if ( '' !== trim( (string) $file->fgets() ) ) {
					++$skipped;
				}
			}
		}

		$rows    = array();
		$scanned = 0;
		$limit   = max( 1, min( 500, $limit ) );
		while ( ! $file->eof() && $scanned < $limit ) {
			$line = trim( (string) $file->fgets() );
			if ( '' === $line ) {
				continue;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'The normalized JSON index is corrupt.' );
			}
			$rows[] = $row;
			++$scanned;
		}
		$next_byte = max( $data_start, (int) $file->ftell() );
		return array(
			'rows'  => $rows,
			'start' => $offset,
			'next'  => $offset + $scanned,
			'byte'  => $next_byte,
			'done'  => $file->eof() || ( false !== $file_size && $next_byte >= $file_size ),
		);
	}

	/**
 * Converts CSV robots columns to explicit EasyRankly directives.
 *
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

	/** @return array<int,array<string,mixed>> */
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

	/** Source truthy helper. */
	private static function truthy( mixed $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on', 'active', 'enabled' ), true );
	}

	private static function first_header( array $headers, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $headers, true ) ) {
				return $candidate;
			}
		}
		return '';
	}

	private static function first_value( array $row, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( isset( $row[ $candidate ] ) && is_scalar( $row[ $candidate ] ) ) {
				return trim( (string) $row[ $candidate ] );
			}
		}
		return '';
	}
}
