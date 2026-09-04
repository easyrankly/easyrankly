<?php
/**
 * Shared contract and helpers for third-party SEO migrations.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class implemented by every source plugin adapter.
 */
abstract class ERankly_Migration_Adapter {
	/**
	 * Optional official source-plugin export used instead of live database data.
	 *
	 * @var string
	 */
	private string $export_file = '';

	/**
	 * Collected non-fatal warnings.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected array $warnings = array();

	/**
	 * Returns the stable source identifier.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Returns the human-readable source name.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Returns the detected source version when available.
	 *
	 * @return string
	 */
	abstract public function version(): string;

	/**
	 * Returns whether importable source data exists.
	 *
	 * @return bool
	 */
	abstract public function is_available(): bool;

	/**
	 * Yields normalized content records.
	 *
	 * @return iterable<int,array{object_type:string,object_id:int,meta:array<string,mixed>,source_reference:string}>
	 */
	abstract public function content_records(): iterable;

	/**
	 * Yields normalized redirect records.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function redirect_records(): iterable {
		return array();
	}

	/**
	 * Returns normalized EasyRankly global settings discovered in the source.
	 *
	 * Values use EasyRankly setting keys and are sanitized by the job runner as
	 * one complete snapshot before individual conflict decisions are staged.
	 *
	 * @return array<string,mixed>
	 */
	public function global_settings(): array {
		return array();
	}

	/**
	 * Returns one resumable page of normalized content records.
	 *
	 * Concrete adapters override this with keyset cursors. The fallback keeps the
	 * public contract usable for third-party adapters, but is intentionally based
	 * on an offset and therefore should not be used for large production imports.
	 *
	 * @param array<string,mixed> $cursor Resume cursor from the previous page.
	 * @param int                 $limit  Maximum normalized records to return.
	 * @return array{records:array<int,array<string,mixed>>,cursor:array<string,mixed>,done:bool}
	 */
	public function content_batch( array $cursor, int $limit ): array {
		return $this->iterable_batch( $this->content_records(), $cursor, $limit );
	}

	/**
	 * Returns one resumable page of normalized redirect records.
	 *
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

	/**
	 * Returns non-fatal adapter warnings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Selects a previously validated official export file as the source.
	 *
	 * The resumable runner persists this path in its checkpoint and restores it
	 * before every page. Upload ownership and cleanup intentionally belong to the
	 * Phase 5 wizard; adapters only accept local, readable CSV/JSON files.
	 *
	 * @param string $path Local source export path, or an empty string for DB mode.
	 * @return bool
	 */
	public function use_export_file( string $path ): bool {
		if ( '' === $path ) {
			$this->export_file = '';
			return true;
		}

		$real = realpath( $path );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) || ! in_array( $ext, array( 'csv', 'json' ), true ) ) {
			return false;
		}

		$maximum = class_exists( 'ERankly_Migration_Upload_Store' )
			? ERankly_Migration_Upload_Store::export_max_bytes( $ext )
			: max( 1024, (int) apply_filters( 'erankly_migration_export_max_bytes', 100 * MB_IN_BYTES ) );
		$size    = filesize( $real );
		if ( false === $size || $size < 1 || $size > $maximum ) {
			return false;
		}

		$this->export_file = $real;
		return true;
	}

	/** Returns whether an official export is the selected source. */
	public function uses_export_file(): bool {
		return '' !== $this->export_file;
	}

	/** Returns the selected local export path. */
	public function export_file(): string {
		return $this->export_file;
	}

	/**
	 * Returns the detected Free/paid edition.
	 *
	 * @return string
	 */
	public function edition(): string {
		return 'free';
	}

	/**
	 * Returns detected source modules and add-ons.
	 *
	 * @return array<int,string>
	 */
	public function modules(): array {
		return array();
	}

	/**
	 * Returns per-module certification states.
	 *
	 * @return array<string,string>
	 */
	public function module_support(): array {
		return array();
	}

	/**
	 * Returns the storage definitions certified by this adapter.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function storage_definitions(): array {
		return array();
	}

	/**
	 * Returns the certified inclusive version range.
	 *
	 * Empty detected versions remain importable when the storage signature is
	 * known, which covers inactive plugins and historical database-only copies.
	 *
	 * @return array{min:string,max:string}
	 */
	protected function supported_versions(): array {
		return array(
			'min' => '',
			'max' => '',
		);
	}

	/**
	 * Returns edition and modules proven by an official export signature.
	 *
	 * Concrete adapters override this when the export format itself carries a
	 * stronger edition signal than the currently installed source code.
	 *
	 * @param string $format Certified export format.
	 * @return array{edition:string,modules:array<int,string>,module_support:array<string,string>}
	 */
	protected function export_source_profile( string $format ): array {
		unset( $format );

		return array(
			'edition'        => $this->edition(),
			'modules'        => $this->modules(),
			'module_support' => $this->module_support(),
		);
	}

	/**
	 * Detects the exact source profile before discovery starts.
	 *
	 * @return array<string,mixed>
	 */
	public function profile(): array {
		if ( $this->uses_export_file() ) {
			$inspection     = class_exists( 'ERankly_Migration_Export_Reader' )
				? ERankly_Migration_Export_Reader::inspect( $this->export_file, $this->slug() )
				: array(
					'status' => 'unsupported',
					'format' => '',
					'reason' => 'export_reader_unavailable',
				);
			$export_profile = 'supported' === (string) ( $inspection['status'] ?? '' )
				? $this->export_source_profile( (string) ( $inspection['format'] ?? '' ) )
				: array(
					'edition'        => 'unknown',
					'modules'        => array(),
					'module_support' => array(),
				);

			return array(
				'source'           => $this->slug(),
				'label'            => $this->label(),
				'version'          => $this->version(),
				'version_status'   => 'export',
				'edition'          => (string) ( $export_profile['edition'] ?? 'unknown' ),
				'modules'          => is_array( $export_profile['modules'] ?? null ) ? $export_profile['modules'] : array(),
				'module_support'   => is_array( $export_profile['module_support'] ?? null ) ? $export_profile['module_support'] : array(),
				'mode'             => 'official_export',
				'storage_status'   => (string) ( $inspection['status'] ?? 'unsupported' ),
				'storage_format'   => (string) ( $inspection['format'] ?? '' ),
				'storage_reason'   => (string) ( $inspection['reason'] ?? '' ),
				'storage_surfaces' => array(),
				'capabilities'     => $this->capabilities(),
			);
		}

		$surfaces    = array();
		$present     = 0;
		$unsupported = false;
		foreach ( $this->storage_definitions() as $name => $definition ) {
			$surface    = $this->inspect_storage_surface( (string) $name, $definition );
			$surfaces[] = $surface;
			if ( 'supported' === $surface['status'] ) {
				++$present;
			} elseif ( 'unsupported' === $surface['status'] ) {
				$unsupported = true;
			}
		}

		$version        = $this->version();
		$version_status = $this->version_status( $version );
		$status         = 0 === $present ? 'empty' : 'supported';
		$reason         = '';
		if ( $unsupported ) {
			$status = 'unsupported';
			$reason = 'unknown_storage_signature';
		} elseif ( 'unsupported' === $version_status ) {
			$status = 'unsupported';
			$reason = 'unsupported_source_version';
		}

		return array(
			'source'           => $this->slug(),
			'label'            => $this->label(),
			'version'          => $version,
			'version_status'   => $version_status,
			'edition'          => $this->edition(),
			'modules'          => $this->modules(),
			'module_support'   => $this->module_support(),
			'mode'             => 'database',
			'storage_status'   => $status,
			'storage_format'   => $this->slug() . '-db-v1',
			'storage_reason'   => $reason,
			'storage_surfaces' => $surfaces,
			'capabilities'     => $this->capabilities(),
		);
	}

	/**
	 * Returns bounded source counts per certified storage surface.
	 *
	 * @return array{total:int,surfaces:array<string,int>}
	 */
	public function inventory(): array {
		if ( $this->uses_export_file() ) {
			$count = class_exists( 'ERankly_Migration_Export_Reader' ) ? ERankly_Migration_Export_Reader::count_records( $this->export_file ) : 0;
			return array(
				'total'    => $count,
				'surfaces' => array( 'official_export' => $count ),
			);
		}

		$counts = array();
		foreach ( $this->storage_definitions() as $name => $definition ) {
			$counts[ (string) $name ] = $this->storage_surface_count( $definition );
		}

		return array(
			'total'    => array_sum( $counts ),
			'surfaces' => $counts,
		);
	}

	/**
	 * Fingerprints every source value consumed by the adapter.
	 *
	 * @return string SHA-256 fingerprint.
	 */
	public function fingerprint(): string {
		if ( $this->uses_export_file() ) {
			if ( ! is_file( $this->export_file ) || is_link( $this->export_file ) || ! is_readable( $this->export_file ) ) {
				return '';
			}
			$hash = hash_file( 'sha256', $this->export_file );
			return is_string( $hash ) ? $hash : '';
		}

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
	 * @param string $code      Machine-readable warning code.
	 * @param string $message   Human-readable message.
	 * @param string $reference Optional source record reference.
	 * @param bool   $blocking  Whether this diagnostic must block go-live.
	 * @return void
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
	 * Iterates objects that own one of the requested metadata keys.
	 *
	 * IDs are fetched in stable, bounded batches and WordPress primes the whole
	 * batch's metadata cache before individual records are mapped. This avoids
	 * loading a large site's complete metadata table into PHP memory.
	 *
	 * @param string            $object_type post|term|user.
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
	 * Reads one keyset-paginated metadata object page.
	 *
	 * The returned cursor advances across missing/deleted objects too, preventing
	 * a malformed source row from trapping a resumable worker on the same page.
	 *
	 * @param string            $object_type post|term|user.
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
	 * Reads one page from a whitelisted source-plugin table suffix.
	 *
	 * @param string $suffix   Trusted suffix supplied by an adapter.
	 * @param int    $after_id Last source row ID already scanned.
	 * @param int    $limit    Maximum rows.
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
	 * @param iterable            $records Source records.
	 * @param array<string,mixed> $cursor  Offset cursor.
	 * @param int                 $limit   Maximum returned records.
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

	/**
	 * Checks whether at least one requested metadata row exists.
	 *
	 * @param string            $object_type post|term|user.
	 * @param array<int,string> $keys        Exact keys.
	 * @param array<int,string> $prefixes    Key prefixes.
	 * @return bool
	 */
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

	/**
	 * Checks whether a WordPress object still exists.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return bool
	 */
	private function object_exists( string $object_type, int $object_id ): bool {
		if ( 'post' === $object_type ) {
			return null !== get_post( $object_id );
		}

		if ( 'term' === $object_type ) {
			return get_term( $object_id ) instanceof WP_Term;
		}

		return false !== get_user_by( 'id', $object_id );
	}

	/**
	 * Returns the first scalar value for a source key.
	 *
	 * @param array<string,mixed> $meta Source metadata.
	 * @param string              $key  Source key.
	 * @return string
	 */
	protected function value( array $meta, string $key ): string {
		$value = $meta[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Reads a source option as an array, accepting native arrays and JSON.
	 *
	 * @param string $name Option name.
	 * @return array<string|int,mixed>
	 */
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

	/**
	 * Returns whether an option contains a non-empty source settings map.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	protected function has_option_map( string $name ): bool {
		return ! empty( $this->option_array( $name ) );
	}

	/**
	 * Reads a dotted path from a nested source settings map.
	 *
	 * @param array<string|int,mixed> $source  Source map.
	 * @param string|array<int,string> $path   Dotted or segmented path.
	 * @param mixed                    $default Fallback value.
	 * @return mixed
	 */
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
	 * @param array<string|int,mixed> $source Source map.
	 * @param string|array<int,string> $path Dotted or segmented path.
	 * @return bool
	 */
	protected function has_nested_value( array $source, string|array $path ): bool {
		$sentinel = new stdClass();

		return $sentinel !== $this->nested_value( $source, $path, $sentinel );
	}

	/**
	 * Returns the first existing value from alternative source paths.
	 *
	 * @param array<string|int,mixed> $source Source map.
	 * @param array<int,string|array<int,string>> $paths Candidate paths.
	 * @param mixed $default Fallback value.
	 * @return mixed
	 */
	protected function first_nested_value( array $source, array $paths, mixed $default = '' ): mixed {
		foreach ( $paths as $path ) {
			if ( $this->has_nested_value( $source, $path ) ) {
				return $this->nested_value( $source, $path, $default );
			}
		}

		return $default;
	}

	/**
	 * Normalizes source robots values to EasyRankly global directives.
	 *
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
				'noodp',
			);
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

		foreach ( array( 'notranslate', 'noodp', 'indexifembedded' ) as $directive ) {
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
	 * Returns whether the source explicitly supplied a robots policy.
	 *
	 * Empty/missing source values must not turn EasyRankly's safe defaults into
	 * index/follow. Explicit false values on named directives still count as a
	 * policy because they intentionally opt out of that directive.
	 *
	 * @param mixed $value Source robots configuration.
	 * @return bool
	 */
	protected function has_robot_configuration( mixed $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$normalized_key = is_string( $key ) ? preg_replace( '/[^a-z0-9]+/', '', strtolower( $key ) ) : '';
				$known_keys     = array( 'index', 'noindex', 'follow', 'nofollow', 'archive', 'noarchive', 'snippet', 'nosnippet', 'imageindex', 'noimageindex', 'notranslate', 'noodp', 'indexifembedded', 'maxsnippet', 'maxvideopreview', 'maximagepreview', 'robots', 'robotsmeta', 'customrobots', 'advancedrobots' );
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

		return (bool) array_intersect( is_array( $tokens ) ? $tokens : array(), array( 'index', 'noindex', 'follow', 'nofollow', 'archive', 'noarchive', 'snippet', 'nosnippet', 'imageindex', 'noimageindex', 'notranslate', 'noodp', 'indexifembedded' ) );
	}

	/**
	 * Builds a complete post-type/taxonomy/special-page default row.
	 *
	 * @param string              $title       Converted title template.
	 * @param string              $description Converted description template.
	 * @param mixed               $robots      Source robots configuration.
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
			$row['article_type'] = erankly_sanitize_schema_type_name( $article_type );
		}

		return $row;
	}

	/**
	 * Converts a template for a non-singular page context.
	 *
	 * Third-party plugins reuse author/date variables whose closest generic
	 * EasyRankly equivalents are post-scoped. Archive defaults need dedicated
	 * context tokens so they do not resolve to an empty string on the frontend.
	 *
	 * @param mixed  $value   Source template.
	 * @param string $source  Source plugin slug.
	 * @param string $context Special-page context.
	 * @return string
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

	/**
	 * Joins valid social profile URLs for EasyRankly's newline storage.
	 *
	 * @param array<int,mixed> $values Candidate URLs.
	 * @return string
	 */
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

	/**
	 * Normalizes a source X/Twitter username or profile URL.
	 *
	 * @param mixed $value Source handle or URL.
	 * @return string
	 */
	protected function social_handle( mixed $value ): string {
		erankly_load_default_helpers();

		return function_exists( 'erankly_sanitize_twitter_handle' )
			? erankly_sanitize_twitter_handle( $value )
			: '';
	}

	/**
	 * Resolves a source Person identity to a local WordPress user when possible.
	 *
	 * @param mixed  $candidate_id Source user ID.
	 * @param string $name         Source display name.
	 * @return int
	 */
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

	/**
	 * Resolves a Person identity and records a blocking diagnostic when unsafe.
	 *
	 * @param mixed  $candidate_id Source user ID.
	 * @param string $name         Source display name.
	 * @return int
	 */
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
	 * Returns the current WordPress alternative text for an attachment.
	 *
	 * Source plugins normally resolve attachment alt text at render time instead
	 * of copying it into their own metadata. Capturing it alongside a migrated
	 * social-image ID preserves that behavior in EasyRankly.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	protected function attachment_alt( int $attachment_id ): string {
		if ( $attachment_id < 1 ) {
			return '';
		}

		return sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Filters mapped EasyRankly metadata before it is queued for import.
	 *
	 * Add-ons can extend or adjust the final mapped metadata.
	 *
	 * @param array<string,mixed> $mapped Core-owned mapped meta.
	 * @param string              $source Adapter slug.
	 * @return array<string,mixed>
	 */
	protected function with_extension_meta( array $mapped ): array {
		$filtered = apply_filters( 'erankly_migration_mapped_meta', $mapped, $this->slug() );

		return is_array( $filtered ) ? $filtered : $mapped;
	}

	/**
	 * Checks whether a source value represents an enabled flag.
	 *
	 * @param mixed $value Source value.
	 * @return bool
	 */
	protected function enabled( mixed $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true', 'active', 'enabled' ), true );
	}

	/**
	 * Converts JSON-LD entities into EasyRankly custom schema blocks.
	 *
	 * @param array<int,array<string,mixed>> $entities Schema entities.
	 * @return array<int,array<string,mixed>>
	 */
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
	 * Finds top-level JSON-LD entities in a plugin schema payload.
	 *
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
	 * Creates complete runtime templates for source plugins that store only a
	 * selected page/article schema type rather than the rendered graph.
	 *
	 * @param string $page_type    Page type.
	 * @param string $article_type Article type.
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
	 * Inspects one declared storage surface without reading source records.
	 *
	 * @param string              $name       Stable surface name.
	 * @param array<string,mixed> $definition Surface definition.
	 * @return array<string,mixed>
	 */
	private function inspect_storage_surface( string $name, array $definition ): array {
		$type   = sanitize_key( (string) ( $definition['type'] ?? '' ) );
		$status = 'absent';
		$detail = '';

		if ( 'meta' === $type ) {
			$object_type = sanitize_key( (string) ( $definition['object_type'] ?? '' ) );
			$keys        = is_array( $definition['keys'] ?? null ) ? $definition['keys'] : array();
			$prefixes    = is_array( $definition['prefixes'] ?? null ) ? $definition['prefixes'] : array();
			$status      = $this->has_meta( $object_type, $keys, $prefixes ) ? 'supported' : 'absent';
		} elseif ( 'option' === $type ) {
			$option = sanitize_key( (string) ( $definition['option'] ?? '' ) );
			$value  = '' !== $option ? get_option( $option, null ) : null;
			if ( null !== $value ) {
				$shape  = (string) ( $definition['shape'] ?? 'mixed' );
				$valid  = 'array' !== $shape || is_array( $value );
				$status = $valid ? 'supported' : 'unsupported';
				$detail = $valid ? '' : 'unexpected_option_shape';
			}
		} elseif ( 'table' === $type ) {
			global $wpdb;
			$suffix = sanitize_key( (string) ( $definition['suffix'] ?? '' ) );
			$table  = $wpdb->prefix . $suffix;
			if ( '' !== $suffix && erankly_table_exists( $table ) ) {
				$required = array_values( array_filter( array_map( 'sanitize_key', is_array( $definition['columns'] ?? null ) ? $definition['columns'] : array() ) ) );
				$columns  = $this->table_columns( $table );
				$missing  = array_values( array_diff( $required, $columns ) );
				$status   = empty( $missing ) ? 'supported' : 'unsupported';
				$detail   = empty( $missing ) ? '' : 'missing_columns:' . implode( ',', $missing );
			}
		} elseif ( 'post_type' === $type ) {
			global $wpdb;
			$post_type = sanitize_key( (string) ( $definition['post_type'] ?? '' ) );
			if ( '' !== $post_type ) {
				$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only third-party storage signature.
					$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", $post_type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table comes from wpdb.
				);
				$status = null !== $exists ? 'supported' : 'absent';
			}
		}

		return array(
			'name'   => sanitize_key( $name ),
			'type'   => $type,
			'status' => $status,
			'detail' => sanitize_text_field( $detail ),
		);
	}

	/**
	 * Counts records on one declared surface.
	 *
	 * @param array<string,mixed> $definition Surface definition.
	 * @return int
	 */
	private function storage_surface_count( array $definition ): int {
		global $wpdb;

		$type = sanitize_key( (string) ( $definition['type'] ?? '' ) );
		if ( 'meta' === $type ) {
			$query = $this->meta_surface_query( $definition );
			if ( ! $query ) {
				return 0;
			}
			$sql    = "SELECT COUNT(DISTINCT %i) FROM %i WHERE {$query['where']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the internal placeholder predicate is assembled here; identifiers use %i below.
			$params = array_merge( array( $query['id_column'], $query['table'] ), $query['params'] );
			return absint( $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared read-only inventory over an internal metadata predicate.
		}

		if ( 'table' === $type ) {
			$suffix = sanitize_key( (string) ( $definition['suffix'] ?? '' ) );
			$table  = $wpdb->prefix . $suffix;
			if ( '' === $suffix || ! erankly_table_exists( $table ) ) {
				return 0;
			}
			return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only third-party inventory.
		}

		if ( 'option' === $type ) {
			$value = get_option( sanitize_key( (string) ( $definition['option'] ?? '' ) ), null );
			return is_array( $value ) ? count( $value ) : ( null === $value ? 0 : 1 );
		}

		if ( 'post_type' === $type ) {
			$post_type = sanitize_key( (string) ( $definition['post_type'] ?? '' ) );
			return '' === $post_type ? 0 : absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table read for third-party inventory.
		}

		return 0;
	}

	/**
	 * Returns a value-sensitive anchor for one declared source surface.
	 *
	 * @param array<string,mixed> $definition Surface definition.
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
			if ( $this->uses_portable_fingerprint() ) {
				return $this->portable_row_fingerprint(
					$query['table'],
					array( $query['row_id_column'], $query['id_column'], 'meta_key', 'meta_value' ),
					$query['row_id_column'],
					$query['where'],
					$query['params']
				);
			}
			$sql    = "SELECT COUNT(*) AS row_count, COALESCE(MAX(%i),0) AS max_id, COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',%i,%i,meta_key,meta_value))),0) AS checksum FROM %i WHERE {$query['where']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the internal placeholder predicate is assembled here; identifiers use %i below.
			$params = array_merge( array( $query['row_id_column'], $query['row_id_column'], $query['id_column'], $query['table'] ), $query['params'] );
			$row    = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared value-sensitive fingerprint over an internal metadata predicate.
			return is_array( $row ) ? $row : array();
		}

		if ( 'table' === $type ) {
			$suffix   = sanitize_key( (string) ( $definition['suffix'] ?? '' ) );
			$table    = $wpdb->prefix . $suffix;
			$required = array_values( array_filter( array_map( 'sanitize_key', is_array( $definition['columns'] ?? null ) ? $definition['columns'] : array() ) ) );
			if ( '' === $suffix || ! erankly_table_exists( $table ) || array_diff( $required, $this->table_columns( $table ) ) ) {
				return array();
			}
			$columns             = array_values( array_unique( array_merge( array( 'id' ), $required, is_array( $definition['fingerprint_columns'] ?? null ) ? array_map( 'sanitize_key', $definition['fingerprint_columns'] ) : array() ) ) );
			$columns             = array_values( array_intersect( $columns, $this->table_columns( $table ) ) );
			if ( $this->uses_portable_fingerprint() ) {
				return $this->portable_row_fingerprint( $table, $columns, 'id' );
			}
			$column_placeholders = implode( ',', array_fill( 0, count( $columns ), '%i' ) );
			$sql                 = "SELECT COUNT(*) AS row_count, COALESCE(MAX(id),0) AS max_id, COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',{$column_placeholders}))),0) AS checksum FROM %i"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The placeholder count matches the certified column list below.
			$row                 = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( $columns, array( $table ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Every certified column and table identifier is escaped through %i.
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
			if ( $this->uses_portable_fingerprint() ) {
				return $this->portable_row_fingerprint(
					$wpdb->posts,
					array( 'ID', 'post_title', 'post_name', 'post_status', 'post_modified_gmt' ),
					'ID',
					'post_type = %s',
					array( $post_type )
				);
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS row_count, COALESCE(MAX(ID),0) AS max_id, COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',ID,post_title,post_name,post_status,post_modified_gmt))),0) AS checksum FROM {$wpdb->posts} WHERE post_type = %s", $post_type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table read for source fingerprint.
			return is_array( $row ) ? $row : array();
		}

		return array();
	}

	/**
	 * Detects database layers that do not provide MySQL checksum functions.
	 *
	 * @return bool
	 */
	private function uses_portable_fingerprint(): bool {
		global $wpdb;

		return false !== stripos( get_class( $wpdb ), 'sqlite' ) || defined( 'SQLITE_DB_DROPIN_VERSION' ) || defined( 'SQLITE_DB_VERSION' );
	}

	/**
	 * Builds a deterministic SHA-256 fingerprint in bounded database pages.
	 *
	 * This path keeps WordPress Studio/SQLite compatible without loading a whole
	 * source surface into memory. Length-prefixing every scalar prevents row and
	 * column boundary collisions in the incremental hash.
	 *
	 * @param string              $table     Certified table name.
	 * @param array<int,string>   $columns   Certified columns to hash.
	 * @param string              $id_column Integer keyset-pagination column.
	 * @param string              $where     Optional prepared predicate.
	 * @param array<int,mixed>    $params    Predicate values.
	 * @return array{row_count:int,max_id:int,checksum:string}
	 */
	private function portable_row_fingerprint( string $table, array $columns, string $id_column, string $where = '', array $params = array() ): array {
		global $wpdb;

		$columns = array_values( array_unique( array_filter( array_map( 'sanitize_key', $columns ) ) ) );
		$id_column = sanitize_key( $id_column );
		if ( '' === $table || '' === $id_column || ! in_array( $id_column, array_map( 'strtolower', $columns ), true ) ) {
			return array(
				'row_count' => 0,
				'max_id'    => 0,
				'checksum'  => hash( 'sha256', '' ),
			);
		}

		$batch_size         = 500;
		$after_id           = 0;
		$row_count          = 0;
		$context            = hash_init( 'sha256' );
		$column_placeholders = implode( ',', array_fill( 0, count( $columns ), '%i' ) );

		do {
			$sql        = "SELECT {$column_placeholders} FROM %i WHERE %i > %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only a trusted identifier placeholder list is assembled.
			$sql_params = array_merge( $columns, array( $table, $id_column, $after_id ) );
			if ( '' !== $where ) {
				$sql         .= " AND ({$where})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Predicate is built internally by meta_surface_query or a fixed core-table condition.
				$sql_params = array_merge( $sql_params, $params );
			}
			$sql        .= ' ORDER BY %i ASC LIMIT %d';
			$sql_params  = array_merge( $sql_params, array( $id_column, $batch_size ) );
			$rows        = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- All identifiers and values use matching placeholders.
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'Source fingerprint page could not be read.' );
			}
			$rows = is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();

			foreach ( $rows as $row ) {
				$row = array_change_key_case( $row, CASE_LOWER );
				foreach ( $columns as $column ) {
					$value = isset( $row[ $column ] ) && is_scalar( $row[ $column ] ) ? (string) $row[ $column ] : '';
					hash_update( $context, pack( 'N', strlen( $value ) ) . $value );
				}
				$after_id = max( $after_id, absint( $row[ $id_column ] ?? 0 ) );
				++$row_count;
			}
		} while ( count( $rows ) === $batch_size );

		return array(
			'row_count' => $row_count,
			'max_id'    => $after_id,
			'checksum'  => hash_final( $context ),
		);
	}

	/**
	 * Builds a prepared metadata-surface predicate.
	 *
	 * @param array<string,mixed> $definition Surface definition.
	 * @return array{table:string,id_column:string,row_id_column:string,where:string,params:array<int,string>}|array{}
	 */
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
	 * Returns normalized columns for an existing source table.
	 *
	 * @param string $table Certified prefixed source table.
	 * @return array<int,string>
	 */
	private function table_columns( string $table ): array {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Storage signature inspection.
		return is_array( $columns ) ? array_values( array_filter( array_map( 'sanitize_key', $columns ) ) ) : array();
	}

	/**
	 * Returns known/unversioned/unsupported for the detected source version.
	 *
	 * @param string $version Detected plugin version.
	 * @return string
	 */
	private function version_status( string $version ): string {
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

	/**
	 * Returns installed plugin headers keyed by basename.
	 *
	 * @param array<int,string> $basenames Exact plugin basenames.
	 * @return array<string,array<string,mixed>>
	 */
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

	/**
	 * Returns a plugin version from a constant or stored options.
	 *
	 * @param string            $constant Constant name.
	 * @param array<int,string> $options  Candidate option names.
	 * @param array<int,string> $plugins Candidate plugin basenames.
	 * @return string
	 */
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
