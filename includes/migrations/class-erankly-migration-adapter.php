<?php
/** Shared contract and helpers for third-party SEO migrations. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Base class implemented by every source plugin adapter. */
abstract class ERankly_Migration_Adapter {
	/** @var array<int,array<string,mixed>> */
	protected array $warnings = array();

	/** Returns the stable source identifier. */
	abstract public function slug(): string;

	abstract public function label(): string;

	/** Returns the detected source version when available. */
	abstract public function version(): string;

	abstract public function is_available(): bool;

	/** @return iterable<int,array{object_type:string,object_id:int,meta:array<string,mixed>,source_reference:string}> */
	abstract public function content_records(): iterable;

	/** @return iterable<int,array<string,mixed>> */
	public function redirect_records(): iterable {
		return array();
	}

	/**
 * Returns normalized EasyRankly global settings discovered in the source. Values use EasyRankly setting keys and
 * are sanitized by the job runner as one complete snapshot before individual conflict decisions are staged.
 *
 * @return array<string,mixed>
 */
	public function global_settings(): array {
		return array();
	}

	/**
 * Returns one resumable page of normalized content records. Concrete adapters override this with keyset cursors.
 * The fallback keeps the public contract usable for third-party adapters, but is intentionally based on an
 * offset and therefore should not be used for large production imports.
 *
 * @param array<string,mixed> $cursor Resume cursor from the previous page.
 * @param int                 $limit  Maximum normalized records to return.
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 */
	public function content_batch( array $cursor, int $limit ): array {
		return $this->iterable_batch( $this->content_records(), $cursor, $limit );
	}

	/**
 * @param array<string,mixed> $cursor Resume cursor from the previous page.
 * @param int                 $limit  Maximum normalized records to return.
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 */
	public function redirect_batch( array $cursor, int $limit ): array {
		return $this->iterable_batch( $this->redirect_records(), $cursor, $limit );
	}

	/**
 * Describes the source surfaces covered by the adapter.
 *
 * @return array<int,string>
 */
	public function capabilities(): array {
		return array( 'posts', 'terms', 'social', 'robots' );
	}

	/** @return array<int,array<string,mixed>> */
	public function warnings(): array {
		return $this->warnings;
	}

	/** @return array<string,array<string,mixed>> */
	protected function storage_definitions(): array {
		return array();
	}

	/**
 * Returns the certified inclusive version range. Empty detected versions remain importable when the storage
 * signature is known, which covers inactive plugins and historical database-only copies.
 *
 * @return array{min:string,max:string}
 */
	protected function supported_versions(): array {
		return array(
			'min' => '',
			'max' => '',
		);
	}

	/** Fingerprints every source value consumed by the adapter. */
	public function fingerprint(): string {
		$anchors = array();
		foreach ( $this->storage_definitions() as $name => $definition ) {
			$anchors[ (string) $name ] = $this->storage_surface_fingerprint( $definition );
		}
		$encoded = wp_json_encode( $anchors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
 * Adds a bounded diagnostic to the post-migration report.
 *
 * @param string $reference Optional source record reference.
 * @param bool   $blocking  Whether this diagnostic must block go-live.
 */
	protected function add_warning( string $code, string $message, string $reference = '', bool $blocking = true ): void {
		if ( count( $this->warnings ) >= 100 ) {
			return;
		}

		$this->warnings[] = array(
			'code'      => sanitize_key( $code ),
			'message'   => sanitize_text_field( $message ),
			'reference' => sanitize_text_field( $reference ),
			'blocking'  => $blocking,
		);
	}

	/**
 * Iterates objects that own one of the requested metadata keys. IDs are fetched in stable, bounded batches and
 * WordPress primes the whole batch's metadata cache before individual records are mapped. This avoids loading a
 * large site's complete metadata table into PHP memory.
 *
 * @param array<int,string> $keys        Exact source meta keys.
 * @param array<int,string> $prefixes    Source meta key prefixes.
 * @return iterable<int,array{id:int,meta:array<string,mixed>}>
 */
	protected function meta_objects( string $object_type, array $keys, array $prefixes = array() ): iterable {
		$cursor = 0;

		do {
			$page        = $this->meta_object_batch( $object_type, $keys, $prefixes, $cursor, 200 );
			$batch_count = (int) $page['scanned'];
			$cursor      = (int) $page['after_id'];

			foreach ( $page['records'] as $record ) {
				yield $record;
			}
		} while ( ! $page['done'] && $batch_count > 0 );
	}

	/**
 * Reads one keyset-paginated metadata object page. The returned cursor advances across missing/deleted objects
 * too, preventing a malformed source row from trapping a resumable worker on the same page.
 *
 * @param array<int,string> $keys        Exact source meta keys.
 * @param array<int,string> $prefixes    Source meta key prefixes.
 * @param int               $after_id    Last source object ID already scanned.
 * @param int               $limit       Maximum source IDs to scan.
 * @return array{records:array<int,array{id:int,meta:array<string,mixed>}>,after_id:int,scanned:int,done:bool}
 * @throws RuntimeException When the source metadata query fails.
 */
	protected function meta_object_batch( string $object_type, array $keys, array $prefixes, int $after_id, int $limit ): array {
		global $wpdb;

		$config = array(
			'post' => array( $wpdb->postmeta, 'post_id' ),
			'term' => array( $wpdb->termmeta, 'term_id' ),
			'user' => array( $wpdb->usermeta, 'user_id' ),
		);
		$limit  = max( 1, min( 500, $limit ) );

		if ( ! isset( $config[ $object_type ] ) || ( empty( $keys ) && empty( $prefixes ) ) ) {
			return array(
				'records'  => array(),
				'after_id' => $after_id,
				'scanned'  => 0,
				'done'     => true,
			);
		}

		list( $table, $id_column ) = $config[ $object_type ];
		$clauses                   = array();
		$meta_params               = array();

		if ( ! empty( $keys ) ) {
			$clauses[]   = 'meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
			$meta_params = array_merge( $meta_params, $keys );
		}

		foreach ( $prefixes as $prefix ) {
			$clauses[]     = 'meta_key LIKE %s';
			$meta_params[] = $wpdb->esc_like( $prefix ) . '%';
		}

		$sql = 'SELECT DISTINCT %i FROM %i WHERE %i > %d AND (' . implode( ' OR ', $clauses ) . ') ORDER BY %i ASC LIMIT %d';
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Every identifier and value is passed to prepare below; only the internal placeholder clauses are assembled dynamically.
			$wpdb->prepare( $sql, array_merge( array( $id_column, $table, $id_column, $after_id ), $meta_params, array( $id_column, $limit ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholder list is paired with the internal key list.
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Source metadata page could not be read.' );
		}
		$ids     = is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
		$scanned = count( $ids );

		if ( empty( $ids ) ) {
			return array(
				'records'  => array(),
				'after_id' => $after_id,
				'scanned'  => 0,
				'done'     => true,
			);
		}

		$after_id = max( $after_id, (int) end( $ids ) );
		update_meta_cache( $object_type, $ids );
		$records = array();

		foreach ( $ids as $object_id ) {
			if ( ! $this->object_exists( $object_type, $object_id ) ) {
				continue;
			}

			$raw  = get_metadata( $object_type, $object_id );
			$meta = array();

			foreach ( is_array( $raw ) ? $raw : array() as $key => $values ) {
				if ( ! is_array( $values ) || ! array_key_exists( 0, $values ) ) {
					continue;
				}

				$meta[ (string) $key ] = maybe_unserialize( $values[0] );
			}

			$records[] = array(
				'id'   => $object_id,
				'meta' => $meta,
			);
		}

		return array(
			'records'  => $records,
			'after_id' => $after_id,
			'scanned'  => $scanned,
			'done'     => $scanned < $limit,
		);
	}

	/**
 * @param string $suffix   Trusted suffix supplied by an adapter.
 * @param int    $after_id Last source row ID already scanned.
 * @return array{records:array<int,array<string,mixed>>,after_id:int,scanned:int,done:bool}
 * @throws RuntimeException When the source table query fails.
 */
	protected function source_table_batch( string $suffix, int $after_id, int $limit ): array {
		global $wpdb;

		$required = array(
			'aioseo_posts'           => array( 'id', 'post_id', 'title', 'description', 'canonical_url' ),
			'aioseo_terms'           => array( 'id', 'term_id', 'title', 'description' ),
			'aioseo_redirects'       => array( 'id', 'source_url', 'target_url', 'type', 'source_url_match', 'query_param', 'enabled' ),
			'rank_math_redirections' => array( 'id', 'sources', 'url_to', 'header_code', 'status' ),
		);
		$allowed  = array(
			'aioseo_posts'           => array( 'id', 'post_id', 'title', 'description', 'canonical_url', 'og_title', 'og_description', 'og_image_url', 'og_image_custom_url', 'twitter_title', 'twitter_description', 'twitter_image_url', 'twitter_image_custom_url', 'twitter_card', 'twitter_use_og', 'robots_default', 'robots_noindex', 'robots_nofollow', 'robots_noarchive', 'robots_nosnippet', 'robots_noimageindex', 'robots_max_snippet', 'robots_max_videopreview', 'robots_max_imagepreview', 'primary_term', 'schema', 'schema_type', 'schema_type_options' ),
			'aioseo_terms'           => array( 'id', 'term_id', 'title', 'description', 'canonical_url', 'og_title', 'og_description', 'og_image_url', 'og_image_custom_url', 'twitter_title', 'twitter_description', 'twitter_image_url', 'twitter_image_custom_url', 'twitter_card', 'twitter_use_og', 'robots_default', 'robots_noindex', 'robots_nofollow', 'robots_noarchive', 'robots_nosnippet', 'robots_noimageindex', 'robots_max_snippet', 'robots_max_videopreview', 'robots_max_imagepreview', 'primary_term', 'schema', 'schema_type', 'schema_type_options' ),
			'aioseo_redirects'       => array( 'id', 'source_url', 'target_url', 'type', 'source_url_match', 'query_param', 'enabled', 'ignore_case' ),
			'rank_math_redirections' => array( 'id', 'sources', 'url_to', 'header_code', 'status' ),
		);
		$limit   = max( 1, min( 500, $limit ) );

		if ( ! isset( $allowed[ $suffix ] ) ) {
			return array(
				'records'  => array(),
				'after_id' => $after_id,
				'scanned'  => 0,
				'done'     => true,
			);
		}

		$table = $wpdb->prefix . $suffix;
		if ( ! erankly_table_exists( $table ) ) {
			return array(
				'records'  => array(),
				'after_id' => $after_id,
				'scanned'  => 0,
				'done'     => true,
			);
		}
		$available_columns = $this->table_columns( $table );
		if ( array_diff( $required[ $suffix ], $available_columns ) ) {
			throw new RuntimeException( 'Source plugin table has an unsupported storage signature.' );
		}
		$columns      = array_values( array_intersect( $allowed[ $suffix ], $available_columns ) );
		$placeholders = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
		$sql          = 'SELECT ' . $placeholders . ' FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d';
		$params       = array_merge( $columns, array( $table, $after_id, $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded third-party source scan.
			$wpdb->prepare( $sql, $params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Every selected identifier and value has a matching placeholder.
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Source plugin table page could not be read.' );
		}
		$rows    = is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
		$scanned = count( $rows );

		if ( $rows ) {
			$last     = end( $rows );
			$after_id = max( $after_id, absint( $last['id'] ?? 0 ) );
		}

		return array(
			'records'  => $rows,
			'after_id' => $after_id,
			'scanned'  => $scanned,
			'done'     => $scanned < $limit,
		);
	}

	/**
 * Offset fallback used only by adapters that have not implemented keysets.
 *
 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
 */
	private function iterable_batch( iterable $records, array $cursor, int $limit ): array {
		$offset  = max( 0, absint( $cursor['offset'] ?? 0 ) );
		$limit   = max( 1, min( 500, $limit ) );
		$page    = array();
		$index   = 0;
		$scanned = 0;

		foreach ( $records as $record ) {
			if ( $index++ < $offset ) {
				continue;
			}
			if ( $scanned >= $limit ) {
				return array(
					'records' => $page,
					'cursor'  => array( 'offset' => $offset + $scanned ),
					'done'    => false,
				);
			}
			++$scanned;
			if ( is_array( $record ) ) {
				$page[] = $record;
			}
		}

		return array(
			'records' => $page,
			'cursor'  => array( 'offset' => $offset + $scanned ),
			'done'    => true,
		);
	}

	/** Checks whether at least one requested metadata row exists. */
	protected function has_meta( string $object_type, array $keys, array $prefixes = array() ): bool {
		global $wpdb;

		$tables = array(
			'post' => array( $wpdb->postmeta, 'meta_id' ),
			'term' => array( $wpdb->termmeta, 'meta_id' ),
			'user' => array( $wpdb->usermeta, 'umeta_id' ),
		);

		if ( ! isset( $tables[ $object_type ] ) || ( empty( $keys ) && empty( $prefixes ) ) ) {
			return false;
		}

		$clauses = array();
		$params  = array();

		if ( ! empty( $keys ) ) {
			$clauses[] = 'meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
			$params    = array_merge( $params, $keys );
		}

		foreach ( $prefixes as $prefix ) {
			$clauses[] = 'meta_key LIKE %s';
			$params[]  = $wpdb->esc_like( $prefix ) . '%';
		}

		list( $table, $row_id_column ) = $tables[ $object_type ];
		$sql                           = 'SELECT %i FROM %i WHERE ' . implode( ' OR ', $clauses ) . ' LIMIT 1';
		$params                        = array_merge( array( $row_id_column, $table ), $params );

		return null !== $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers and values are prepared; only internal placeholder clauses are assembled dynamically.
	}

	private function object_exists( string $object_type, int $object_id ): bool {
		if ( 'post' === $object_type ) {
			return null !== get_post( $object_id );
		}

		if ( 'term' === $object_type ) {
			return get_term( $object_id ) instanceof WP_Term;
		}

		return false !== get_user_by( 'id', $object_id );
	}

	protected function value( array $meta, string $key ): string {
		$value = $meta[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** @return array<string|int,mixed> */
	protected function option_array( string $name ): array {
		$value = get_option( $name, array() );
		$value = maybe_unserialize( $value );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$value = $decoded;
			}
		}

		return is_array( $value ) ? $value : array();
	}

	/** Returns whether an option contains a non-empty source settings map. */
	protected function has_option_map( string $name ): bool {
		return ! empty( $this->option_array( $name ) );
	}

	/** @param string|array<int,string> $path   Dotted or segmented path. */
	protected function nested_value( array $source, string|array $path, mixed $default = '' ): mixed {
		$segments = is_array( $path ) ? $path : explode( '.', $path );
		$value    = $source;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
 * Checks whether a dotted path exists, including explicit false/zero values.
 *
 * @param string|array<int,string> $path Dotted or segmented path.
 */
	protected function has_nested_value( array $source, string|array $path ): bool {
		$sentinel = new stdClass();

		return $sentinel !== $this->nested_value( $source, $path, $sentinel );
	}

	protected function first_nested_value( array $source, array $paths, mixed $default = '' ): mixed {
		foreach ( $paths as $path ) {
			if ( $this->has_nested_value( $source, $path ) ) {
				return $this->nested_value( $source, $path, $default );
			}
		}

		return $default;
	}

	/**
 * @param mixed $value Source robots value or map.
 * @return array<string,int|string>
 */
	protected function global_robots( mixed $value ): array {
		$tokens       = array();
		$named_values = array();
		$canonical_key = static function ( string $key ): string {
			$normalized = preg_replace( '/[^a-z0-9]+/', '', strtolower( $key ) );
			$normalized = is_string( $normalized ) ? $normalized : '';
			$known      = array(
				'maxvideopreview',
				'maximagepreview',
				'maxsnippet',
				'indexifembedded',
				'noimageindex',
				'notranslate',
				'nosnippet',
				'noarchive',
				'nofollow',
				'noindex',
				'imageindex',
				'snippet',
				'archive',
				'follow',
				'index',
			); // noodp retired: DMOZ shut down in 2017, no engine reads it.
			foreach ( $known as $candidate ) {
				if ( $candidate === $normalized || str_ends_with( $normalized, $candidate ) ) {
					return $candidate;
				}
			}

			return '';
		};
		$walk = static function ( mixed $item, string $key = '' ) use ( &$walk, &$tokens, &$named_values, $canonical_key ): void {
			if ( is_array( $item ) ) {
				foreach ( $item as $child_key => $child ) {
					$walk( $child, is_string( $child_key ) ? $child_key : '' );
				}
				return;
			}
			$canonical = '' !== $key ? $canonical_key( $key ) : '';
			if ( '' !== $canonical && is_scalar( $item ) ) {
				$named_values[ $canonical ] = trim( (string) $item );
			}
			if ( is_scalar( $item ) ) {
				$parts  = preg_split( '/[^a-z0-9_-]+/', strtolower( (string) $item ) );
				$tokens = array_merge( $tokens, is_array( $parts ) ? $parts : array() );
			}
		};
		$walk( $value );
		$tokens = array_values( array_unique( array_filter( $tokens, 'strlen' ) ) );
		$truthy = static fn( mixed $item ): bool => in_array( strtolower( trim( (string) $item ) ), array( '1', 'on', 'yes', 'true', 'enabled' ), true );
		$state  = static function ( string $deny, string $allow = '' ) use ( $tokens, $named_values, $truthy ): bool {
			if ( in_array( $deny, $tokens, true ) || ( array_key_exists( $deny, $named_values ) && $truthy( $named_values[ $deny ] ) ) ) {
				return true;
			}
			if ( '' !== $allow && ( in_array( $allow, $tokens, true ) || ( array_key_exists( $allow, $named_values ) && $truthy( $named_values[ $allow ] ) ) ) ) {
				return false;
			}

			return false;
		};

		$result = array(
			'noindex'   => $state( 'noindex', 'index' ) ? 1 : 0,
			'nofollow'  => $state( 'nofollow', 'follow' ) ? 1 : 0,
			'noarchive' => $state( 'noarchive', 'archive' ) ? 1 : 0,
		);

		foreach ( array(
			'index'   => array( 'index_directive', 'index', 'noindex' ),
			'follow'  => array( 'follow_directive', 'follow', 'nofollow' ),
			'archive' => array( 'archive_directive', 'archive', 'noarchive' ),
			'snippet' => array( 'snippet_directive', 'snippet', 'nosnippet' ),
			'imageindex' => array( 'image_directive', 'imageindex', 'noimageindex' ),
		) as $allow_key => $definition ) {
			list( $target, $allow, $deny ) = $definition;
			$present = in_array( $allow, $tokens, true ) || in_array( $deny, $tokens, true ) || array_key_exists( $allow, $named_values ) || array_key_exists( $deny, $named_values );
			if ( $present ) {
				$result[ $target ] = $state( $deny, $allow ) ? $deny : $allow_key;
			}
		}

		foreach ( array( 'notranslate', 'indexifembedded' ) as $directive ) { // noodp retired.
			$present = in_array( $directive, $tokens, true ) || array_key_exists( $directive, $named_values );
			if ( $present ) {
				$result[ $directive ] = ( in_array( $directive, $tokens, true ) || $truthy( $named_values[ $directive ] ?? '' ) ) ? 1 : 0;
			}
		}

		foreach ( array(
			'maxsnippet'      => 'max_snippet',
			'maxvideopreview' => 'max_video_preview',
		) as $source_key => $target ) {
			if ( ! array_key_exists( $source_key, $named_values ) ) {
				continue;
			}
			$preview = trim( (string) $named_values[ $source_key ] );
			if ( preg_match( '/^-?\d+$/', $preview ) && (int) $preview >= -1 ) {
				$result[ $target ] = (string) (int) $preview;
			}
		}

		if ( array_key_exists( 'maximagepreview', $named_values ) ) {
			$preview = sanitize_key( (string) $named_values['maximagepreview'] );
			if ( in_array( $preview, array( 'none', 'standard', 'large' ), true ) ) {
				$result['max_image_preview'] = $preview;
			}
		}

		return $result;
	}

	/**
 * Returns whether the source explicitly supplied a robots policy. Empty/missing source values must not turn
 * EasyRankly's safe defaults into index/follow. Explicit false values on named directives still count as a
 * policy because they intentionally opt out of that directive.
 */
	protected function has_robot_configuration( mixed $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$normalized_key = is_string( $key ) ? preg_replace( '/[^a-z0-9]+/', '', strtolower( $key ) ) : '';
				$known_keys     = array( 'index', 'noindex', 'follow', 'nofollow', 'archive', 'noarchive', 'snippet', 'nosnippet', 'imageindex', 'noimageindex', 'notranslate', 'indexifembedded', 'maxsnippet', 'maxvideopreview', 'maximagepreview', 'robots', 'robotsmeta', 'customrobots', 'advancedrobots' ); // noodp retired.
				if ( in_array( $normalized_key, $known_keys, true ) || ( '' !== $normalized_key && array_filter( $known_keys, static fn( string $candidate ): bool => str_ends_with( $normalized_key, $candidate ) ) ) ) {
					return true;
				}
				if ( $this->has_robot_configuration( $child ) ) {
					return true;
				}
			}

			return false;
		}

		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return false;
		}

		$tokens = preg_split( '/[^a-z0-9_-]+/', strtolower( (string) $value ) );

		return (bool) array_intersect( is_array( $tokens ) ? $tokens : array(), array( 'index', 'noindex', 'follow', 'nofollow', 'archive', 'noarchive', 'snippet', 'nosnippet', 'imageindex', 'noimageindex', 'notranslate', 'indexifembedded' ) ); // noodp retired.
	}

	/**
 * @param bool|null           $in_sitemap  Explicit source sitemap inclusion.
 * @param string              $webpage_type Optional Schema.org WebPage subtype.
 * @param string              $article_type Optional Schema.org Article subtype.
 * @return array<string,string|int>
 */
	protected function global_meta_row( string $title, string $description, mixed $robots = null, ?bool $in_sitemap = null, string $webpage_type = '', string $article_type = '' ): array {
		erankly_load_default_helpers();
		$row = array(
			'title'       => $title,
			'description' => $description,
		);
		if ( $this->has_robot_configuration( $robots ) ) {
			$row += $this->global_robots( $robots );
		}
		if ( null !== $in_sitemap ) {
			$row['disable_sitemap'] = false === $in_sitemap ? 1 : 0;
		}

		if ( '' !== $webpage_type || '' !== $article_type ) {
			$row['webpage_type'] = erankly_sanitize_schema_type_name( $webpage_type );
			// The sources express "emit no Article node" as an empty value;
			// EasyRankly stores that choice explicitly.
			$row['article_type'] = '' === trim( $article_type ) ? 'none' : erankly_sanitize_schema_type_name( $article_type );
		}

		return $row;
	}

	/**
	 * Splits the Schema.org types out of a post type meta map. They live in their own setting because the
	 * "Same for all" toggle shares one title and description across content types, which would otherwise hide
	 * and overwrite an imported per-type schema choice.
	 *
	 * @param array<string,mixed> $post_map Rows built by global_meta_row().
	 * @return array{0:array<string,mixed>,1:array<string,array{webpage_type:string,article_type:string}>} Cleaned map and schema map.
	 */
	protected function split_post_type_schema( array $post_map ): array {
		erankly_load_default_helpers();
		$schema_map = array();

		foreach ( $post_map as $post_type => $row ) {
			if ( ! is_array( $row ) || ( ! array_key_exists( 'webpage_type', $row ) && ! array_key_exists( 'article_type', $row ) ) ) {
				continue;
			}

			$defaults     = erankly_default_post_type_schema_row( (string) $post_type );
			$webpage_type = erankly_sanitize_schema_type_name( $row['webpage_type'] ?? '' );
			$article_type = erankly_sanitize_schema_type_name( $row['article_type'] ?? '' );

			$schema_map[ (string) $post_type ] = array(
				'webpage_type' => '' !== $webpage_type ? $webpage_type : $defaults['webpage_type'],
				'article_type' => '' !== $article_type ? $article_type : $defaults['article_type'],
			);

			unset( $post_map[ $post_type ]['webpage_type'], $post_map[ $post_type ]['article_type'] );
		}

		return array( $post_map, $schema_map );
	}

	/**
 * Converts a template for a non-singular page context. Third-party plugins reuse author/date variables whose
 * closest generic EasyRankly equivalents are post-scoped. Archive defaults need dedicated context tokens so they
 * do not resolve to an empty string on the frontend.
 */
	protected function special_template( mixed $value, string $source, string $context ): string {
		$converted = erankly_import_convert_variables( is_scalar( $value ) ? (string) $value : '', $source );
		if ( 'author' === $context ) {
			$converted = str_replace( '{{post_author}}', '{{author_name}}', $converted );
		} elseif ( 'date' === $context ) {
			$converted = str_replace( '{{post_date}}', '{{archive_date}}', $converted );
		}

		return $converted;
	}

	/** Joins valid social profile URLs for EasyRankly's newline storage. */
	protected function social_profile_list( array $values ): string {
		$urls = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				$urls = array_merge( $urls, preg_split( '/[\r\n,]+/', implode( "\n", array_filter( $value, 'is_scalar' ) ) ) ?: array() );
			} elseif ( is_scalar( $value ) ) {
				$urls = array_merge( $urls, preg_split( '/[\r\n,]+/', (string) $value ) ?: array() );
			}
		}
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );

		return implode( "\n", $urls );
	}

	/** @param mixed $value Source handle or URL. */
	protected function social_handle( mixed $value ): string {
		erankly_load_default_helpers();

		return function_exists( 'erankly_sanitize_twitter_handle' )
			? erankly_sanitize_twitter_handle( $value )
			: '';
	}

	protected function person_user_id( mixed $candidate_id, string $name = '' ): int {
		$user_id = absint( $candidate_id );
		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			return $user_id;
		}
		if ( '' !== trim( $name ) ) {
			$users = get_users(
				array(
					'search'         => trim( $name ),
					'search_columns' => array( 'display_name', 'user_login' ),
					'number'         => 2,
					'fields'         => 'ids',
				)
			);
			if ( 1 === count( $users ) ) {
				return absint( $users[0] );
			}
		}

		return 0;
	}

	/** Resolves a Person identity and records a blocking diagnostic when unsafe. */
	protected function person_user_id_or_warning( mixed $candidate_id, string $name = '' ): int {
		$user_id = $this->person_user_id( $candidate_id, $name );
		if ( $user_id < 1 ) {
			$current_user_id = 'person' === (string) erankly_get_setting( 'schema_identity', '' ) ? absint( erankly_get_setting( 'schema_person_user_id', 0 ) ) : 0;
			if ( $current_user_id > 0 && get_userdata( $current_user_id ) ) {
				$this->add_warning(
					'schema_person_identity_preserved',
					__( 'The source Person name could not be matched automatically. The existing EasyRankly Person user was preserved; verify it in Schema settings before go-live.', 'easyrankly' ),
					'settings:schema_person_user_id',
					false
				);

				return $current_user_id;
			}
			$this->add_warning(
				'schema_person_identity_unmatched',
				__( 'The source identifies the site as a person, but no unique local WordPress user could be matched. Select the person in EasyRankly before go-live.', 'easyrankly' ),
				'settings:schema_person_user_id'
			);
		}

		return $user_id;
	}

	/**
 * Returns the current WordPress alternative text for an attachment. Source plugins normally resolve attachment
 * alt text at render time instead of copying it into their own metadata. Capturing it alongside a migrated
 * social-image ID preserves that behavior in EasyRankly.
 */
	protected function attachment_alt( int $attachment_id ): string {
		if ( $attachment_id < 1 ) {
			return '';
		}

		return sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
 * Filters mapped EasyRankly metadata before it is queued for import. Add-ons can extend or adjust the final
 * mapped metadata.
 *
 * @return array<string,mixed>
 */
	protected function with_extension_meta( array $mapped ): array {
		$filtered = apply_filters( 'erankly_migration_mapped_meta', $mapped, $this->slug() );

		return is_array( $filtered ) ? $filtered : $mapped;
	}

	protected function enabled( mixed $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true', 'active', 'enabled' ), true );
	}

	/** @return array<int,array<string,mixed>> */
	protected function schema_blocks( array $entities ): array {
		$blocks = array();

		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) || ( empty( $entity['@type'] ) && empty( $entity['@graph'] ) ) ) {
				continue;
			}

			unset( $entity['metadata'] );
			$json = wp_json_encode( $entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( ! is_string( $json ) || '' === $json ) {
				continue;
			}

			$blocks[] = array(
				'type'   => 'custom',
				'fields' => array( 'custom_json' => $json ),
			);
		}

		return $blocks;
	}

	/**
 * @param mixed $payload Decoded or encoded schema payload.
 * @return array<int,array<string,mixed>>
 */
	protected function extract_schema_entities( mixed $payload ): array {
		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return array();
			}
			$payload = $decoded;
		}

		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['@graph'] ) && is_array( $payload['@graph'] ) ) {
			return array_values( array_filter( $payload['@graph'], 'is_array' ) );
		}

		if ( isset( $payload['@type'] ) ) {
			return array( $payload );
		}

		$entities = array();
		foreach ( $payload as $child ) {
			if ( is_array( $child ) ) {
				$entities = array_merge( $entities, $this->extract_schema_entities( $child ) );
			}
		}

		return $entities;
	}

	/**
 * Creates complete runtime templates for source plugins that store only a selected page/article schema type
 * rather than the rendered graph.
 *
 * @return array{blocks:array<int,array<string,mixed>>,disabled:array<int,string>}
 */
	protected function schema_type_templates( string $page_type, string $article_type ): array {
		$entities = array();
		$disabled = array();

		if ( '' !== $page_type && ! in_array( strtolower( $page_type ), array( 'default', 'none', 'webpage' ), true ) ) {
			$disabled[] = 'WebPage';
			$entities[] = array(
				'@type'       => preg_replace( '/[^A-Za-z0-9_-]/', '', $page_type ),
				'@id'         => '{{post_url}}#webpage',
				'url'         => '{{post_url}}',
				'name'        => '{{post_title}}',
				'description' => '{{post_excerpt}}',
				'isPartOf'    => array( '@id' => '{{site_url}}#website' ),
			);
		} elseif ( 'none' === strtolower( $page_type ) ) {
			$disabled[] = 'WebPage';
		}

		if ( '' !== $article_type && ! in_array( strtolower( $article_type ), array( 'default', 'none', 'article', 'blogposting' ), true ) ) {
			$disabled   = array_merge( $disabled, array( 'Article', 'BlogPosting' ) );
			$entities[] = array(
				'@type'            => preg_replace( '/[^A-Za-z0-9_-]/', '', $article_type ),
				'@id'              => '{{post_url}}#article',
				'url'              => '{{post_url}}',
				'headline'         => '{{post_title}}',
				'description'      => '{{post_excerpt}}',
				'datePublished'    => '{{post_date}}',
				'dateModified'     => '{{post_modified_date}}',
				'author'           => array(
					'@type' => 'Person',
					'name'  => '{{post_author}}',
				),
				'mainEntityOfPage' => array( '@id' => '{{post_url}}#webpage' ),
			);
		} elseif ( 'none' === strtolower( $article_type ) ) {
			$disabled = array_merge( $disabled, array( 'Article', 'BlogPosting' ) );
		}

		return array(
			'blocks'   => $this->schema_blocks( $entities ),
			'disabled' => array_values( array_unique( $disabled ) ),
		);
	}

	/**
 * Returns a cheap change anchor for one certified source surface.
 *
 * Row count plus highest identifier is enough to detect the inserts and deletions that would make a run's
 * counters describe a state that no longer exists. It deliberately avoids per-row checksums: those need
 * MySQL-only aggregate functions that the SQLite drop-in cannot execute.
 *
 * @param array<string,mixed> $definition Certified surface definition.
 * @return array<string,mixed>|string
 */
	private function storage_surface_fingerprint( array $definition ): array|string {
		global $wpdb;

		$type = sanitize_key( (string) ( $definition['type'] ?? '' ) );

		if ( 'meta' === $type ) {
			$query = $this->meta_surface_query( $definition );
			if ( ! $query ) {
				return array();
			}
			$sql = "SELECT COUNT(*) AS row_count, COALESCE(MAX(%i),0) AS max_id FROM %i WHERE {$query['where']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the internal placeholder predicate is assembled here; identifiers use %i.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $query['row_id_column'], $query['table'] ), $query['params'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared anchor over an internal metadata predicate.

			return is_array( $row ) ? $row : array();
		}

		if ( 'table' === $type ) {
			$suffix   = sanitize_key( (string) ( $definition['suffix'] ?? '' ) );
			$table    = $wpdb->prefix . $suffix;
			$required = array_values( array_filter( array_map( 'sanitize_key', is_array( $definition['columns'] ?? null ) ? $definition['columns'] : array() ) ) );
			if ( '' === $suffix || ! erankly_table_exists( $table ) || array_diff( $required, $this->table_columns( $table ) ) ) {
				return array();
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS row_count, COALESCE(MAX(id),0) AS max_id FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Source anchor read.

			return is_array( $row ) ? $row : array();
		}

		if ( 'option' === $type ) {
			$value   = get_option( sanitize_key( (string) ( $definition['option'] ?? '' ) ), null );
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			return hash( 'sha256', false === $encoded ? '' : $encoded );
		}

		if ( 'post_type' === $type ) {
			$post_type = sanitize_key( (string) ( $definition['post_type'] ?? '' ) );
			if ( '' === $post_type ) {
				return array();
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS row_count, COALESCE(MAX(ID),0) AS max_id FROM {$wpdb->posts} WHERE post_type = %s", $post_type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table read for the source anchor.

			return is_array( $row ) ? $row : array();
		}

		return array();
	}

	/** @return array{table:string,id_column:string,row_id_column:string,where:string,params:array<int,string>}|array{} */
	private function meta_surface_query( array $definition ): array {
		global $wpdb;

		$config      = array(
			'post' => array( $wpdb->postmeta, 'post_id', 'meta_id' ),
			'term' => array( $wpdb->termmeta, 'term_id', 'meta_id' ),
			'user' => array( $wpdb->usermeta, 'user_id', 'umeta_id' ),
		);
		$object_type = sanitize_key( (string) ( $definition['object_type'] ?? '' ) );
		$keys        = array_values( array_filter( array_map( 'strval', is_array( $definition['keys'] ?? null ) ? $definition['keys'] : array() ) ) );
		$prefixes    = array_values( array_filter( array_map( 'strval', is_array( $definition['prefixes'] ?? null ) ? $definition['prefixes'] : array() ) ) );
		if ( ! isset( $config[ $object_type ] ) || ( empty( $keys ) && empty( $prefixes ) ) ) {
			return array();
		}

		$clauses = array();
		$params  = array();
		if ( $keys ) {
			$clauses[] = 'meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
			$params    = $keys;
		}
		foreach ( $prefixes as $prefix ) {
			$clauses[] = 'meta_key LIKE %s';
			$params[]  = $wpdb->esc_like( $prefix ) . '%';
		}

		return array(
			'table'         => $config[ $object_type ][0],
			'id_column'     => $config[ $object_type ][1],
			'row_id_column' => $config[ $object_type ][2],
			'where'         => '(' . implode( ' OR ', $clauses ) . ')',
			'params'        => $params,
		);
	}

	/**
 * @param string $table Certified prefixed source table.
 * @return array<int,string>
 */
	private function table_columns( string $table ): array {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Storage signature inspection.
		return is_array( $columns ) ? array_values( array_filter( array_map( 'sanitize_key', $columns ) ) ) : array();
	}

	/** Returns known/unversioned/unsupported for the detected source version. */
	/**
 * Reports whether the detected source version falls inside the certified range.
 *
 * @return string One of certified, unversioned or unsupported.
 */
	public function version_status( string $version ): string {
		if ( '' === trim( $version ) ) {
			return 'unversioned';
		}

		$range = $this->supported_versions();
		$min   = (string) ( $range['min'] ?? '' );
		$max   = (string) ( $range['max'] ?? '' );
		if ( ( '' !== $min && version_compare( $version, $min, '<' ) ) || ( '' !== $max && version_compare( $version, $max, '>' ) ) ) {
			return 'unsupported';
		}

		return 'certified';
	}

	/** @return array<string,array<string,mixed>> */
	protected function installed_plugins( array $basenames ): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			$plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_file( $plugin_api ) ) {
				require_once $plugin_api;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		$all   = get_plugins();
		$found = array();
		foreach ( $basenames as $basename ) {
			if ( isset( $all[ $basename ] ) && is_array( $all[ $basename ] ) ) {
				$found[ $basename ] = $all[ $basename ];
			}
		}

		return $found;
	}

	/** Returns a plugin version from a constant or stored options. */
	protected function detect_version( string $constant, array $options, array $plugins = array() ): string {
		if ( defined( $constant ) ) {
			return sanitize_text_field( (string) constant( $constant ) );
		}

		foreach ( $options as $option ) {
			$value = get_option( $option );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		foreach ( $this->installed_plugins( $plugins ) as $plugin ) {
			if ( isset( $plugin['Version'] ) && is_scalar( $plugin['Version'] ) && '' !== trim( (string) $plugin['Version'] ) ) {
				return sanitize_text_field( (string) $plugin['Version'] );
			}
		}

		return '';
	}
}
