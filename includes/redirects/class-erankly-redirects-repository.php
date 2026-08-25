<?php
/**
 * Redirect database access.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for the erankly_redirects table.
 */
final class ERankly_Redirects_Repository {
	/**
	 * Non-autoloaded option containing frontend-ready active rules.
	 */
	private const RUNTIME_RULES_OPTION         = 'erankly_redirects_runtime_rules';
	private const RUNTIME_RULES_VERSION        = 4;
	private const RUNTIME_GLOBAL_OPTION        = 'erankly_redirects_runtime_rules_global';
	private const RUNTIME_ALL_OPTION           = 'erankly_redirects_runtime_rules_all';
	private const RUNTIME_PREFIX_INDEX_OPTION  = 'erankly_redirects_runtime_rules_prefix_index';
	private const RUNTIME_PREFIX_OPTION_PREFIX = 'erankly_redirects_runtime_rules_prefix_';

	/**
	 * Sentinel value stored in object cache when no exact redirect exists for a hash.
	 */
	private const NO_REDIRECT_SENTINEL = '__erankly_no_redirect__';

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	private string $cache_group = 'erankly_redirects';

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private string $table_name;

	/**
	 * Request-level runtime manifest, or a freshly compiled rule set.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $runtime_rules = null;

	/**
	 * Whether mutation invalidation is deferred during a bulk import.
	 *
	 * @var bool
	 */
	private bool $bulk_mode = false;

	/**
	 * Whether a bulk import changed at least one rule.
	 *
	 * @var bool
	 */
	private bool $bulk_dirty = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->table_name = self::get_table_name();
	}

	/**
	 * Get the full table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'erankly_redirects';
	}

	/**
	 * Get cache key for a source hash.
	 *
	 * @param string $source_hash Source hash.
	 * @return string
	 */
	public function get_cache_key( string $source_hash ): string {
		return erankly_redirects_cache_key( $source_hash );
	}

	/**
	 * Read exact redirect from cache.
	 *
	 * Returns the cached redirect array on a positive hit, the NO_REDIRECT_SENTINEL string
	 * on a negative hit, or false when the key is not in cache.
	 *
	 * @param string $source_hash Source hash.
	 * @return array<string,mixed>|string|false
	 */
	public function get_cached_exact( string $source_hash ) {
		return wp_cache_get( $this->get_cache_key( $source_hash ), $this->cache_group );
	}

	/**
	 * Store exact redirect in cache.
	 *
	 * @param string              $source_hash Source hash.
	 * @param array<string,mixed> $redirect Redirect row.
	 */
	public function set_cached_exact( string $source_hash, array $redirect ): void {
		wp_cache_set( $this->get_cache_key( $source_hash ), $redirect, $this->cache_group, HOUR_IN_SECONDS );
	}

	/**
	 * Store a negative-cache sentinel so subsequent requests skip the exact-match DB query.
	 *
	 * @param string $source_hash Source hash.
	 */
	public function set_cached_exact_miss( string $source_hash ): void {
		wp_cache_set( $this->get_cache_key( $source_hash ), self::NO_REDIRECT_SENTINEL, $this->cache_group, HOUR_IN_SECONDS );
	}

	/**
	 * Check whether a cached value is the negative-cache sentinel.
	 *
	 * @param mixed $value Value returned by get_cached_exact().
	 * @return bool
	 */
	public function is_cached_exact_miss( $value ): bool {
		return is_string( $value ) && self::NO_REDIRECT_SENTINEL === $value;
	}

	/**
	 * Delete exact redirect cache.
	 *
	 * Removes both positive hits and negative-cache sentinels for the given hash.
	 *
	 * @param string $source_hash Source hash.
	 */
	public function delete_cached_exact( string $source_hash ): void {
		wp_cache_delete( $this->get_cache_key( $source_hash ), $this->cache_group );
	}

	/**
	 * Returns only advanced rules that can match the current route/query.
	 *
	 * The non-autoloaded option is rebuilt lazily after redirect mutations. This
	 * removes custom-table queries from normal frontend requests for patterns.
	 *
	 * Passing no route preserves the historical all-patterns API for extensions.
	 * Runtime requests use prefix/query buckets so unrelated rules are never
	 * traversed by the matcher.
	 *
	 * @param string $request_path  Normalized request path, or empty for all rules.
	 * @param string $current_query Current raw query string.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_pattern_rules( string $request_path = '', string $current_query = '' ): array {
		global $wpdb;

		if ( null === $this->runtime_rules ) {
			$manifest = get_option( self::RUNTIME_RULES_OPTION, null );
			if ( is_array( $manifest ) && self::RUNTIME_RULES_VERSION === (int) ( $manifest['version'] ?? 0 ) ) {
				$this->runtime_rules = $manifest;
			} else {
				$sql  = $wpdb->prepare(
					'SELECT id, source_path, source_hash, source_query, target_url, status_code, match_type, is_regex, is_wildcard, case_sensitive, trailing_slash, query_mode, priority, visibility, required_role, conditions, start_at, end_at
					FROM %i
					WHERE is_active = 1 AND (
						match_type <> %s OR case_sensitive = 1 OR trailing_slash <> %s OR query_mode <> %s OR visibility <> %s OR conditions IS NOT NULL OR start_at IS NOT NULL OR end_at IS NOT NULL
					)
					ORDER BY priority ASC, id ASC',
					$this->table_name,
					'exact',
					'ignore',
					'ignore',
					'all'
				);
				$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rebuilds compact runtime buckets after explicit invalidation.

				$this->runtime_rules = $this->compile_runtime_rules( is_array( $rows ) ? $rows : array() );
				$this->persist_runtime_rules( $this->runtime_rules );
			}
		}

		if ( '' === $request_path ) {
			if ( isset( $this->runtime_rules['all'] ) && is_array( $this->runtime_rules['all'] ) ) {
				return $this->runtime_rules['all'];
			}
			$all = get_option( self::RUNTIME_ALL_OPTION, array() );

			return is_array( $all ) ? $all : array();
		}

		$prefix    = $this->runtime_prefix_key( $request_path );
		$query_key = hash( 'sha256', $current_query );
		if ( isset( $this->runtime_rules['global'], $this->runtime_rules['prefix'] ) ) {
			$global = is_array( $this->runtime_rules['global'] ) ? $this->runtime_rules['global'] : array();
			$local  = is_array( $this->runtime_rules['prefix'][ $prefix ] ?? null ) ? $this->runtime_rules['prefix'][ $prefix ] : array();
		} else {
			$global = get_option( self::RUNTIME_GLOBAL_OPTION, array() );
			$local  = get_option( $this->runtime_prefix_option_name( $prefix ), array() );
			$global = is_array( $global ) ? $global : array();
			$local  = is_array( $local ) ? $local : array();
		}
		$candidates = array_merge(
			is_array( $global['any'] ?? null ) ? $global['any'] : array(),
			is_array( $global['query'][ $query_key ] ?? null ) ? $global['query'][ $query_key ] : array(),
			is_array( $local['any'] ?? null ) ? $local['any'] : array(),
			is_array( $local['query'][ $query_key ] ?? null ) ? $local['query'][ $query_key ] : array()
		);

		usort(
			$candidates,
			static fn( array $left, array $right ): int => array( (int) ( $left['priority'] ?? 10 ), (int) ( $left['id'] ?? 0 ) ) <=> array( (int) ( $right['priority'] ?? 10 ), (int) ( $right['id'] ?? 0 ) )
		);

		return $candidates;
	}

	/**
	 * Compiles database rows into route/query buckets and reusable match data.
	 *
	 * @param array<int,array<string,mixed>> $rows Active advanced rows.
	 * @return array<string,mixed>
	 */
	private function compile_runtime_rules( array $rows ): array {
		$compiled = array(
			'version' => self::RUNTIME_RULES_VERSION,
			'all'     => array(),
			'global'  => array(
				'any'   => array(),
				'query' => array(),
			),
			'prefix'  => array(),
		);

		foreach ( $rows as $row ) {
			$match_type                  = (string) ( $row['match_type'] ?? ( ! empty( $row['is_wildcard'] ) ? 'wildcard' : ( ! empty( $row['is_regex'] ) ? 'regex' : 'exact' ) ) );
			$case_sensitive              = ! empty( $row['case_sensitive'] );
			$trailing_slash              = (string) ( $row['trailing_slash'] ?? 'ignore' );
			$row['_runtime_source_path'] = ERankly_Redirects_Normalizer::normalize_match_path( (string) ( $row['source_path'] ?? '' ), $case_sensitive, $trailing_slash );
			if ( 'wildcard' === $match_type ) {
				$row['_runtime_pattern'] = ERankly_Redirects_Normalizer::build_wildcard_pattern( (string) ( $row['source_path'] ?? '' ), $case_sensitive );
			} elseif ( 'regex' === $match_type ) {
				$row['_runtime_pattern'] = ERankly_Redirects_Normalizer::build_regex_pattern( (string) ( $row['source_path'] ?? '' ), $case_sensitive );
			}

			$prefix = '';
			if ( 'exact' === $match_type ) {
				$prefix = $this->runtime_prefix_key( (string) ( $row['source_path'] ?? '' ) );
			} elseif ( in_array( $match_type, array( 'starts_with', 'wildcard' ), true ) ) {
				$source = (string) ( $row['source_path'] ?? '' );
				if ( 'wildcard' === $match_type ) {
					$wildcard = strpos( $source, '*' );
					$source   = false === $wildcard ? $source : substr( $source, 0, $wildcard );
				}
				// A partial first segment (for example /shop*) can also match
				// /shopping and therefore cannot be placed in an exact segment bucket.
				$trimmed = trim( $source, '/' );
				if ( false !== strpos( $trimmed, '/' ) || str_ends_with( $source, '/' ) ) {
					$prefix = $this->runtime_prefix_key( $source );
				}
			}

			$bucket =& $compiled['global'];
			if ( '' !== $prefix ) {
				if ( ! isset( $compiled['prefix'][ $prefix ] ) ) {
					$compiled['prefix'][ $prefix ] = array(
						'any'   => array(),
						'query' => array(),
					);
				}
				$bucket =& $compiled['prefix'][ $prefix ];
			}

			if ( 'exact' === (string) ( $row['query_mode'] ?? 'ignore' ) ) {
				$query_key                       = hash( 'sha256', (string) ( $row['source_query'] ?? '' ) );
				$bucket['query'][ $query_key ][] = $row;
			} else {
				$bucket['any'][] = $row;
			}
			$compiled['all'][] = $row;
			unset( $bucket );
		}

		return $compiled;
	}

	/**
	 * Persists independently loadable global and path-prefix rule segments.
	 *
	 * The public manifest stays small. Runtime requests therefore deserialize
	 * only the global bucket and the one bucket matching their first path segment.
	 *
	 * @param array<string,mixed> $compiled Freshly compiled runtime rules.
	 */
	private function persist_runtime_rules( array $compiled ): void {
		$global = is_array( $compiled['global'] ?? null ) ? $compiled['global'] : array();
		$all    = is_array( $compiled['all'] ?? null ) ? $compiled['all'] : array();
		update_option( self::RUNTIME_GLOBAL_OPTION, $global, false );
		update_option( self::RUNTIME_ALL_OPTION, $all, false );

		$prefix_options = array();
		foreach ( is_array( $compiled['prefix'] ?? null ) ? $compiled['prefix'] : array() as $prefix => $rules ) {
			$option = $this->runtime_prefix_option_name( (string) $prefix );
			update_option( $option, is_array( $rules ) ? $rules : array(), false );
			$prefix_options[] = $option;
		}

		$previous = get_option( self::RUNTIME_PREFIX_INDEX_OPTION, array() );
		foreach ( is_array( $previous ) ? array_diff( $previous, $prefix_options ) : array() as $stale_option ) {
			if ( is_string( $stale_option ) && 1 === preg_match( '/^erankly_redirects_runtime_rules_prefix_[a-f0-9]{24}$/', $stale_option ) ) {
				delete_option( $stale_option );
			}
		}

		update_option( self::RUNTIME_PREFIX_INDEX_OPTION, array_values( $prefix_options ), false );
		update_option(
			self::RUNTIME_RULES_OPTION,
			array(
				'version'      => self::RUNTIME_RULES_VERSION,
				'prefix_count' => count( $prefix_options ),
			),
			false
		);
	}

	/**
	 * Returns a coarse, case-insensitive first-path-segment bucket key.
	 *
	 * @param string $path Normalized or raw request path.
	 */
	private function runtime_prefix_key( string $path ): string {
		$path     = ERankly_Redirects_Normalizer::normalize_path( $path );
		$segments = explode( '/', trim( strtolower( $path ), '/' ) );

		return sanitize_key( (string) ( $segments[0] ?? '' ) );
	}

	/**
	 * Returns the fixed-length option name for one path-prefix bucket.
	 *
	 * @param string $prefix Normalized first path segment.
	 */
	private function runtime_prefix_option_name( string $prefix ): string {
		return self::RUNTIME_PREFIX_OPTION_PREFIX . substr( hash( 'sha256', $prefix ), 0, 24 );
	}

	/**
	 * Retrieve an exact match rule using cache fallback to DB.
	 *
	 * @param string $source_hash Source hash.
	 * @return array<string,mixed>|null
	 */
	public function get_exact_rule_cached( string $source_hash ): ?array {
		$cached = $this->get_cached_exact( $source_hash );
		if ( $this->is_cached_exact_miss( $cached ) ) {
			return null;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$row = $this->find_active_exact_by_hash( $source_hash );
		if ( $row ) {
			$this->set_cached_exact( $source_hash, $row );
			return $row;
		}

		$this->set_cached_exact_miss( $source_hash );
		return null;
	}

	/**
	 * Invalidates frontend redirect rules after a mutation.
	 *
	 * Clears the request-level rules, the persisted runtime-rules option, and any
	 * full-page caches that would otherwise keep serving stale responses for the
	 * affected URLs.
	 *
	 * @return void
	 */
	public function invalidate_runtime_rules(): void {
		if ( $this->bulk_mode ) {
			$this->bulk_dirty = true;
			return;
		}

		$this->runtime_rules = null;
		$prefix_options      = get_option( self::RUNTIME_PREFIX_INDEX_OPTION, array() );
		foreach ( is_array( $prefix_options ) ? $prefix_options : array() as $option ) {
			if ( is_string( $option ) && 1 === preg_match( '/^erankly_redirects_runtime_rules_prefix_[a-f0-9]{24}$/', $option ) ) {
				delete_option( $option );
			}
		}
		delete_option( self::RUNTIME_GLOBAL_OPTION );
		delete_option( self::RUNTIME_ALL_OPTION );
		delete_option( self::RUNTIME_PREFIX_INDEX_OPTION );
		delete_option( self::RUNTIME_RULES_OPTION );
		erankly_redirects_flush_external_caches();
	}

	/** Starts a bulk mutation and defers cache invalidation. */
	public function begin_bulk(): void {
		$this->bulk_mode  = true;
		$this->bulk_dirty = false;
	}

	/** Ends a bulk mutation and invalidates runtime caches once. */
	public function end_bulk(): void {
		$this->bulk_mode = false;

		if ( $this->bulk_dirty ) {
			$this->bulk_dirty = false;
			$this->invalidate_runtime_rules();
		}
	}

	/**
	 * Find an active exact redirect by hash.
	 *
	 * @param string $source_hash Source hash.
	 * @return array<string,mixed>|null
	 */
	public function find_active_exact_by_hash( string $source_hash ): ?array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE source_hash = %s AND is_active = 1 AND match_type = %s AND case_sensitive = 0 AND trailing_slash = %s AND query_mode = %s AND visibility = %s AND conditions IS NULL AND start_at IS NULL AND end_at IS NULL ORDER BY priority ASC, id ASC LIMIT 1',
			$this->table_name,
			$source_hash,
			'exact',
			'ignore',
			'ignore',
			'all'
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns active exact redirect source hashes from one batched lookup.
	 *
	 * @param array<int,string> $source_hashes Source hashes.
	 * @return array<string,true> Map of hashes with an active exact rule.
	 */
	public function find_active_exact_hashes( array $source_hashes ): array {
		global $wpdb;

		$source_hashes = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $source_hashes ),
					static fn( string $hash ): bool => '' !== $hash
				)
			)
		);

		if ( empty( $source_hashes ) ) {
			return array();
		}

		$active = array();

		foreach ( array_chunk( $source_hashes, 100 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = $wpdb->prepare(
				"SELECT source_hash FROM %i WHERE source_hash IN ($placeholders) AND is_active = 1 AND match_type = %s AND case_sensitive = 0 AND trailing_slash = %s AND query_mode = %s AND visibility = %s AND conditions IS NULL AND start_at IS NULL AND end_at IS NULL",
				array_merge(
					array( $this->table_name ),
					$chunk,
					array( 'exact', 'ignore', 'ignore', 'all' )
				)
			);

			$found = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Batched redirect coverage lookup prepared above.

			foreach ( is_array( $found ) ? $found : array() as $hash ) {
				$hash = (string) $hash;

				if ( '' !== $hash ) {
					$active[ $hash ] = true;
				}
			}
		}

		return $active;
	}

	/**
	 * Find a redirect by rule identity hash, regardless of active state.
	 *
	 * @param string $rule_hash Rule identity hash.
	 * @return array<string,mixed>|null
	 */
	public function find_by_hash( string $rule_hash ): ?array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE rule_hash = %s LIMIT 1',
			$this->table_name,
			$rule_hash
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a redirect by ID.
	 *
	 * @param int $id Redirect ID.
	 * @return array<string,mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d LIMIT 1',
			$this->table_name,
			$id
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Parse a search string into a free-text remainder plus structured filters.
	 *
	 * Recognizes `status:on`/`status:off` (also `active`/`inactive`), `code:301`,
	 * `type:exact|regex|wildcard`, and `visibility:all|logged_in|logged_out` (also
	 * `public` as an alias for `all`) tokens anywhere in the string, so that, e.g.,
	 * searching "code: 301" matches the status code column exactly instead of doing
	 * a substring match that could also match "301" appearing inside an unrelated
	 * source path. Filters can be combined; a standalone `&` or `and` between tokens
	 * is treated the same as whitespace (implicit AND), it's purely a readability aid.
	 *
	 * @param string $search Raw search term.
	 * @return array{text:string,status:bool|null,code:int|null,type:string|null,visibility:string|null}
	 */
	private function parse_search( string $search ): array {
		$status     = null;
		$code       = null;
		$type       = null;
		$visibility = null;

		$search = preg_replace_callback(
			'/\bstatus\s*:\s*(on|off|active|inactive)\b/i',
			static function ( array $matches ) use ( &$status ): string {
				$status = in_array( strtolower( $matches[1] ), array( 'on', 'active' ), true );
				return '';
			},
			$search
		);

		$search = preg_replace_callback(
			'/\bcode\s*:\s*(\d+)\b/i',
			static function ( array $matches ) use ( &$code ): string {
				$code = (int) $matches[1];
				return '';
			},
			$search
		);

		$search = preg_replace_callback(
			'/\btype\s*:\s*(exact|regex|wildcard|contains|starts_with|ends_with)\b/i',
			static function ( array $matches ) use ( &$type ): string {
				$type = strtolower( $matches[1] );
				return '';
			},
			$search
		);

		$search = preg_replace_callback(
			'/\bvisibility\s*:\s*(all|public|logged[-_]?in|logged[-_]?out)\b/i',
			static function ( array $matches ) use ( &$visibility ): string {
				$value      = strtolower( str_replace( '-', '_', $matches[1] ) );
				$visibility = 'public' === $value ? 'all' : $value;
				return '';
			},
			$search
		);

		// A standalone "&" or the word "and" is just a visual separator between
		// filter tokens, not an operator. Collapse it into whitespace like any
		// other token boundary.
		$search = preg_replace( '/(?:^|\s)(?:&|and)(?:\s|$)/i', ' ', (string) $search );

		return array(
			'text'       => trim( preg_replace( '/\s+/', ' ', (string) $search ) ),
			'status'     => $status,
			'code'       => $code,
			'type'       => $type,
			'visibility' => $visibility,
		);
	}

	/**
	 * Build a WHERE clause and matching bind params for a search term.
	 *
	 * @param string $search Raw search term.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_search_clause( string $search ): array {
		global $wpdb;

		$parsed     = $this->parse_search( $search );
		$conditions = array();
		$params     = array();

		if ( '' !== $parsed['text'] ) {
			$conditions[] = 'source_path LIKE %s';
			$params[]     = '%' . $wpdb->esc_like( $parsed['text'] ) . '%';
		}

		if ( null !== $parsed['status'] ) {
			$conditions[] = 'is_active = %d';
			$params[]     = $parsed['status'] ? 1 : 0;
		}

		if ( null !== $parsed['code'] ) {
			$conditions[] = 'status_code = %d';
			$params[]     = $parsed['code'];
		}

		if ( null !== $parsed['type'] ) {
			$conditions[] = 'match_type = %s';
			$params[]     = $parsed['type'];
		}

		if ( null !== $parsed['visibility'] ) {
			$conditions[] = 'visibility = %s';
			$params[]     = $parsed['visibility'];
		}

		if ( empty( $conditions ) ) {
			return array( '', array() );
		}

		return array( ' WHERE ' . implode( ' AND ', $conditions ), $params );
	}

	/**
	 * Columns allowed for admin table sorting, mapped to their DB column name.
	 *
	 * @var array<string,string>
	 */
	private const SORTABLE_COLUMNS = array(
		'hit_count'   => 'hit_count',
		'last_hit_at' => 'last_hit_at',
	);

	/**
	 * List redirects for admin.
	 *
	 * @param string $search  Search term. Supports `status:on|off` and `code:301` filters
	 *                        in addition to free-text matching against the source path.
	 * @param int    $page Page number.
	 * @param int    $per_page Rows per page.
	 * @param string $orderby Column to sort by. Must be a key of SORTABLE_COLUMNS; any other
	 *                        value falls back to the default `id DESC` order.
	 * @param string $order   Sort direction, `asc` or `desc`.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_redirects( string $search, int $page, int $per_page, string $orderby = '', string $order = 'desc' ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		list( $where, $params ) = $this->build_search_clause( $search );

		$order_sql = 'id DESC';

		if ( isset( self::SORTABLE_COLUMNS[ $orderby ] ) ) {
			$direction = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';
			$order_sql = self::SORTABLE_COLUMNS[ $orderby ] . ' ' . $direction . ', id DESC';
		}

		// Dynamic SQL fragments below are generated from internal whitelists only.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"SELECT * FROM %i{$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
			array_merge( array( $this->table_name ), $params, array( $per_page, $offset ) )
		);

		// Custom redirect table query prepared above; dynamic fragments are internal whitelist output.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count redirects for admin pagination.
	 *
	 * @param string $search Search term. Supports `status:on|off`, `code:301`, `type:exact|regex|wildcard`,
	 *                       and `visibility:all|logged_in|logged_out` filters (combinable, optionally
	 *                       separated by "&" or "and") in addition to free-text matching against the source path.
	 * @return int
	 */
	public function count_redirects( string $search = '' ): int {
		global $wpdb;

		list( $where, $params ) = $this->build_search_clause( $search );

		// Dynamic SQL fragments below are generated from internal whitelists only.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"SELECT COUNT(*) FROM %i{$where}",
			array_merge( array( $this->table_name ), $params )
		);

		// Custom redirect table query prepared above; dynamic fragments are internal whitelist output.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Returns one keyset-paginated redirect export page.
	 *
	 * @param int $after_id Last exported redirect ID.
	 * @param int $limit    Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function export_page( int $after_id, int $limit = 500 ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT id AS _cursor, source_path, source_query, target_url, status_code, match_type, is_regex, is_wildcard, case_sensitive, trailing_slash, query_mode, priority, is_active, visibility, required_role, conditions, start_at, end_at, source_plugin, source_reference, migration_id, note FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
			$this->table_name,
			max( 0, $after_id ),
			max( 1, min( 1000, $limit ) )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded custom-table export page prepared above.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create a redirect.
	 *
	 * @param array<string,mixed> $data Redirect data.
	 * @return int Inserted ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$data   = $this->normalize_data( $data );
		$now    = current_time( 'mysql' );
		$insert = array_merge(
			$data,
			array(
				'hit_count'   => 0,
				'last_hit_at' => null,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$result = $wpdb->insert( $this->table_name, $insert ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table mutation.

		if ( false === $result ) {
			return 0;
		}

		$this->delete_cached_exact( (string) $data['source_hash'] );
		$this->invalidate_runtime_rules();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a redirect.
	 *
	 * @param int                 $id Redirect ID.
	 * @param array<string,mixed> $data Redirect data.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$old                = $this->find_by_id( $id );
		$data               = $this->normalize_data( $data );
		$data['updated_at'] = current_time( 'mysql' );
		$result             = $wpdb->update( $this->table_name, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table mutation.

		if ( $old && isset( $old['source_hash'] ) ) {
			$this->delete_cached_exact( (string) $old['source_hash'] );
		}
		$this->delete_cached_exact( (string) $data['source_hash'] );
		$this->invalidate_runtime_rules();

		return false !== $result;
	}

	/**
	 * Delete a redirect.
	 *
	 * @param int $id Redirect ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$old = $this->find_by_id( $id );
		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE id = %d',
			$this->table_name,
			$id
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

		if ( $old && isset( $old['source_hash'] ) ) {
			$this->delete_cached_exact( (string) $old['source_hash'] );
		}
		$this->invalidate_runtime_rules();

		return false !== $result;
	}

	/**
	 * Toggle active state.
	 *
	 * @param int $id Redirect ID.
	 * @return bool
	 */
	public function toggle_active( int $id ): bool {
		global $wpdb;

		$old = $this->find_by_id( $id );

		if ( ! $old ) {
			return false;
		}

		$new_value = empty( $old['is_active'] ) ? 1 : 0;
		$sql       = $wpdb->prepare(
			'UPDATE %i SET is_active = %d, updated_at = %s WHERE id = %d',
			$this->table_name,
			$new_value,
			current_time( 'mysql' ),
			$id
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.
		$this->delete_cached_exact( (string) $old['source_hash'] );
		$this->invalidate_runtime_rules();

		return false !== $result;
	}

	/**
	 * Upsert a redirect by its complete matching identity.
	 *
	 * @param array<string,mixed> $data Redirect data.
	 * @return string created|updated|failed
	 */
	public function upsert_by_hash( array $data ): string {
		$data     = $this->normalize_data( $data );
		$existing = $this->find_by_hash( (string) $data['rule_hash'] );

		if ( $existing ) {
			return $this->update( (int) $existing['id'], $data ) ? 'updated' : 'failed';
		}

		return $this->create( $data ) > 0 ? 'created' : 'failed';
	}

	/**
	 * Applies backward-compatible defaults and computes both path and rule hashes.
	 *
	 * @param array<string,mixed> $data Redirect data.
	 * @return array<string,mixed>
	 */
	private function normalize_data( array $data ): array {
		$match_type = isset( $data['match_type'] ) && in_array( $data['match_type'], ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true )
			? (string) $data['match_type']
			: ( ! empty( $data['is_wildcard'] ) ? 'wildcard' : ( ! empty( $data['is_regex'] ) ? 'regex' : 'exact' ) );
		$defaults   = array(
			'source_path'      => '',
			'source_query'     => '',
			'target_url'       => '',
			'status_code'      => 301,
			'match_type'       => $match_type,
			'is_regex'         => 'regex' === $match_type ? 1 : 0,
			'is_wildcard'      => 'wildcard' === $match_type ? 1 : 0,
			'case_sensitive'   => 0,
			'trailing_slash'   => 'ignore',
			'query_mode'       => 'ignore',
			'priority'         => 10,
			'is_active'        => 1,
			'visibility'       => 'all',
			'required_role'    => '',
			'conditions'       => null,
			'start_at'         => null,
			'end_at'           => null,
			'source_plugin'    => '',
			'source_reference' => '',
			'migration_id'     => '',
			'note'             => null,
		);
		$data       = array_intersect_key( array_merge( $defaults, $data ), $defaults );
		if ( is_array( $data['conditions'] ) ) {
			$data['conditions'] = wp_json_encode( $data['conditions'] );
		} elseif ( ! is_string( $data['conditions'] ) || '' === trim( $data['conditions'] ) ) {
			$data['conditions'] = null;
		}
		$data['start_at']    = empty( $data['start_at'] ) ? null : (string) $data['start_at'];
		$data['end_at']      = empty( $data['end_at'] ) ? null : (string) $data['end_at'];
		$data['match_type']  = $match_type;
		$data['is_regex']    = 'regex' === $match_type ? 1 : 0;
		$data['is_wildcard'] = 'wildcard' === $match_type ? 1 : 0;
		$data['source_hash'] = ERankly_Redirects_Normalizer::source_hash( ERankly_Redirects_Normalizer::normalize_path( (string) $data['source_path'] ) );
		$data['rule_hash']   = ERankly_Redirects_Normalizer::rule_hash( $data );

		return $data;
	}

	/**
	 * Increment redirect stats with one lightweight write.
	 *
	 * @param int $id Redirect ID.
	 */
	public function increment_hit( int $id ): void {
		global $wpdb;

		/**
		 * Filters the redirect statistics sampling rate.
		 *
		 * A rate of 10 performs approximately one database write every ten hits
		 * and increments the counter by ten. Use 1 for exact synchronous counts.
		 *
		 * @param int $sample_rate Sampling rate.
		 * @param int $id          Redirect ID.
		 */
		$sample_rate = max( 1, (int) apply_filters( 'erankly_redirect_hit_sample_rate', 10, $id ) );

		if ( $sample_rate > 1 && 1 !== wp_rand( 1, $sample_rate ) ) {
			return;
		}

		$sql = $wpdb->prepare(
			'UPDATE %i SET hit_count = hit_count + %d, last_hit_at = %s WHERE id = %d',
			$this->table_name,
			$sample_rate,
			current_time( 'mysql' ),
			$id
		);

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.
	}
}
