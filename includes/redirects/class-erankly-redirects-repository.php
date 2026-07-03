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
	private const RUNTIME_RULES_OPTION = 'erankly_redirects_runtime_rules';

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
	 * Request-level runtime rules.
	 *
	 * @var array{exact:array<string,array<string,mixed>>,patterns:array<int,array<string,mixed>>}|null
	 */
	private ?array $runtime_rules = null;

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
		return 'erankly_redirect_' . $source_hash;
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
	 * Returns all active redirects in a frontend-optimized structure.
	 *
	 * The non-autoloaded option is rebuilt lazily after redirect mutations. This
	 * removes custom-table queries from normal frontend requests for patterns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_pattern_rules(): array {
		global $wpdb;

		if ( null !== $this->runtime_rules ) {
			return $this->runtime_rules['patterns'];
		}

		$cached = get_option( self::RUNTIME_RULES_OPTION, null );

		if (
			is_array( $cached ) &&
			isset( $cached['patterns'] ) &&
			is_array( $cached['patterns'] )
		) {
			$this->runtime_rules = $cached;
			return $this->runtime_rules['patterns'];
		}

		$sql  = $wpdb->prepare(
			'SELECT id, source_path, source_hash, target_url, status_code, is_regex, is_wildcard, visibility, required_role
			FROM %i
			WHERE is_active = 1 AND (is_regex = 1 OR is_wildcard = 1)
			ORDER BY id ASC',
			$this->table_name
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rebuilds the versionless runtime rules option after explicit invalidation.

		$rules = array(
			'patterns' => is_array( $rows ) ? $rows : array(),
		);

		update_option( self::RUNTIME_RULES_OPTION, $rules, false );
		$this->runtime_rules = $rules;

		return $this->runtime_rules['patterns'];
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
		$this->runtime_rules = null;
		delete_option( self::RUNTIME_RULES_OPTION );
		erankly_redirects_flush_external_caches();
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
			'SELECT * FROM %i WHERE source_hash = %s AND is_active = 1 AND is_regex = 0 LIMIT 1',
			$this->table_name,
			$source_hash
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a redirect by source hash, regardless of active state.
	 *
	 * @param string $source_hash Source hash.
	 * @return array<string,mixed>|null
	 */
	public function find_by_hash( string $source_hash ): ?array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE source_hash = %s LIMIT 1',
			$this->table_name,
			$source_hash
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
			'/\btype\s*:\s*(exact|regex|wildcard)\b/i',
			static function ( array $matches ) use ( &$type ): string {
				$type = strtolower( $matches[1] );
				return '';
			},
			$search
		);

		$search = preg_replace_callback(
			'/\bvisibility\s*:\s*(all|public|logged[-_]?in|logged[-_]?out)\b/i',
			static function ( array $matches ) use ( &$visibility ): string {
				$value = strtolower( str_replace( '-', '_', $matches[1] ) );
				$visibility = 'public' === $value ? 'all' : $value;
				return '';
			},
			$search
		);

		// A standalone "&" or the word "and" is just a visual separator between
		// filter tokens, not an operator — collapse it into whitespace like any
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
			if ( 'regex' === $parsed['type'] ) {
				$conditions[] = 'is_regex = 1';
			} elseif ( 'wildcard' === $parsed['type'] ) {
				$conditions[] = 'is_wildcard = 1';
			} else {
				$conditions[] = 'is_regex = 0 AND is_wildcard = 0';
			}
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
	 * Return all redirects for CSV export.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_all_for_export(): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT source_path, target_url, status_code, is_regex, is_wildcard, is_active, visibility, required_role, note FROM %i ORDER BY id ASC',
			$this->table_name
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

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

		$now = current_time( 'mysql' );
		$sql = $wpdb->prepare(
			'INSERT INTO %i
				(source_path, source_hash, target_url, status_code, is_regex, is_wildcard, is_active, visibility, required_role, note, hit_count, last_hit_at, created_at, updated_at)
				VALUES (%s, %s, %s, %d, %d, %d, %d, %s, %s, %s, 0, NULL, %s, %s)',
			$this->table_name,
			(string) $data['source_path'],
			(string) $data['source_hash'],
			(string) $data['target_url'],
			(int) $data['status_code'],
			(int) $data['is_regex'],
			(int) $data['is_wildcard'],
			(int) $data['is_active'],
			(string) $data['visibility'],
			(string) $data['required_role'],
			isset( $data['note'] ) && '' !== $data['note'] ? (string) $data['note'] : null,
			$now,
			$now
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

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

		$old = $this->find_by_id( $id );
		$sql = $wpdb->prepare(
			'UPDATE %i
				SET source_path = %s,
					source_hash = %s,
					target_url = %s,
					status_code = %d,
					is_regex = %d,
					is_wildcard = %d,
					is_active = %d,
					visibility = %s,
					required_role = %s,
					note = %s,
					updated_at = %s
				WHERE id = %d',
			$this->table_name,
			(string) $data['source_path'],
			(string) $data['source_hash'],
			(string) $data['target_url'],
			(int) $data['status_code'],
			(int) $data['is_regex'],
			(int) $data['is_wildcard'],
			(int) $data['is_active'],
			(string) $data['visibility'],
			(string) $data['required_role'],
			isset( $data['note'] ) && '' !== $data['note'] ? (string) $data['note'] : null,
			current_time( 'mysql' ),
			$id
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirect table query prepared above.

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
	 * Upsert a redirect by source hash.
	 *
	 * @param array<string,mixed> $data Redirect data.
	 * @return string created|updated|failed
	 */
	public function upsert_by_hash( array $data ): string {
		$existing = $this->find_by_hash( (string) $data['source_hash'] );

		if ( $existing ) {
			return $this->update( (int) $existing['id'], $data ) ? 'updated' : 'failed';
		}

		return $this->create( $data ) > 0 ? 'created' : 'failed';
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
