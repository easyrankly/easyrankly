<?php
// phpcs:ignoreFile -- Standalone WordPress database snapshot harness.
/**
 * Phase 3 resumable adapter integration tests against Free/PRO DB snapshots.
 *
 * Run: php tests/phase3-migration-integration.php
 *
 * @package EasyRankly
 */

require __DIR__ . '/phase2-migration-smoke.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

final class WP_Post {
	public int $ID;
	public string $post_type;
	public string $post_title;

	public function __construct( int $id, string $post_type = 'post', string $post_title = '' ) {
		$this->ID         = $id;
		$this->post_type  = $post_type;
		$this->post_title = '' !== $post_title ? $post_title : 'Post ' . $id;
	}
}

function get_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! isset( $GLOBALS['erankly_phase3_fixture']['posts'][ (string) $post_id ] ) ) {
		return null;
	}
	$object = $GLOBALS['erankly_phase3_fixture']['post_objects'][ (string) $post_id ] ?? array();
	return new WP_Post( $post_id, (string) ( $object['post_type'] ?? 'post' ), (string) ( $object['post_title'] ?? '' ) );
}

function get_user_by( $field, $user_id ) {
	unset( $field );
	return isset( $GLOBALS['erankly_phase3_fixture']['users'][ (string) (int) $user_id ] ) ? (object) array( 'ID' => (int) $user_id ) : false;
}

function get_permalink( $post_id ) {
	if ( $post_id instanceof WP_Post ) {
		$post_id = $post_id->ID;
	}
	return get_post( $post_id ) instanceof WP_Post ? 'https://example.test/post/' . (int) $post_id . '/' : false;
}

function get_term_link( $term_id ) {
	return 'https://example.test/category/' . (int) $term_id . '/';
}

function update_meta_cache( $object_type, $ids ) {
	unset( $object_type, $ids );
	return true;
}

function get_metadata( $object_type, $object_id, $key = '', $single = false ) {
	$bucket = array(
		'post' => 'posts',
		'term' => 'terms',
		'user' => 'users',
	)[ $object_type ] ?? '';
	$meta = '' !== $bucket ? ( $GLOBALS['erankly_phase3_fixture'][ $bucket ][ (string) (int) $object_id ] ?? array() ) : array();
	if ( '' !== $key ) {
		$value = $meta[ $key ] ?? '';
		return $single ? $value : array( $value );
	}

	$result = array();
	foreach ( $meta as $meta_key => $value ) {
		$result[ $meta_key ] = array( $value );
	}
	return $result;
}

/** Minimal prepared-query carrier used by the fixture database. */
final class ERankly_Phase3_Query {
	public string $sql;
	/** @var array<int,mixed> */
	public array $args;

	public function __construct( string $sql, array $args ) {
		$this->sql  = $sql;
		$this->args = $args;
	}
}

/**
 * Small wpdb double that executes the exact keyset query shapes used by the
 * production adapters against captured table/meta snapshots.
 */
final class ERankly_Phase3_WPDB {
	public string $prefix = 'wp_';
	public string $postmeta = 'wp_postmeta';
	public string $termmeta = 'wp_termmeta';
	public string $usermeta = 'wp_usermeta';
	public string $posts = 'wp_posts';
	public string $last_error = '';

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = array();

	public function prepare( string $sql, ...$args ): ERankly_Phase3_Query {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index      = 0;
		$value_args = array();
		$prepared   = preg_replace_callback(
			'/%[idsf]/',
			static function ( array $match ) use ( &$args, &$index, &$value_args ): string {
				$value = $args[ $index++ ] ?? null;
				if ( '%i' === $match[0] ) {
					return (string) $value;
				}

				$value_args[] = $value;
				return $match[0];
			},
			$sql
		);

		return new ERankly_Phase3_Query( is_string( $prepared ) ? $prepared : $sql, $value_args );
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	/** @return array<int,int> */
	public function get_col( $query ): array {
		list( $sql, $args ) = $this->unpack( $query );
		if ( preg_match( '/SHOW COLUMNS FROM ([a-z0-9_]+)/i', $sql, $match ) ) {
			$table   = $match[1];
			$columns = array();
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				$columns = array_merge( $columns, array_keys( $row ) );
			}
			return array_values( array_unique( $columns ) );
		}
		if ( ! preg_match( '/SELECT DISTINCT (post_id|term_id|user_id) FROM ([a-z0-9_]+)/i', $sql, $match ) ) {
			return array();
		}
		$id_column = $match[1];
		$table     = $match[2];
		$after     = (int) ( $args[0] ?? 0 );
		$limit     = (int) ( $args[ count( $args ) - 1 ] ?? 200 );
		$ids       = array();
		foreach ( $this->tables[ $table ] ?? array() as $row ) {
			$id = (int) ( $row[ $id_column ] ?? 0 );
			if ( $id > $after ) {
				$ids[ $id ] = $id;
			}
		}
		ksort( $ids, SORT_NUMERIC );
		return array_slice( array_values( $ids ), 0, $limit );
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( $query, $output = ARRAY_A ): array {
		unset( $output );
		list( $sql, $args ) = $this->unpack( $query );
		if ( ! preg_match( '/SELECT \* FROM ([a-z0-9_]+) WHERE id\s*(>=|>)\s*%d/i', $sql, $match ) ) {
			return array();
		}
		$table    = $match[1];
		$operator = $match[2];
		$boundary = (int) ( $args[0] ?? 0 );
		$limit    = (int) ( $args[1] ?? 200 );
		$rows     = array_values( $this->tables[ $table ] ?? array() );
		usort( $rows, static fn( array $left, array $right ): int => (int) $left['id'] <=> (int) $right['id'] );
		$rows = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => '>=' === $operator ? (int) $row['id'] >= $boundary : (int) $row['id'] > $boundary
			)
		);
		return array_slice( $rows, 0, $limit );
	}

	public function get_var( $query ) {
		list( $sql, $args ) = $this->unpack( $query );
		if ( str_starts_with( strtoupper( trim( $sql ) ), 'SHOW TABLES LIKE' ) ) {
			$table = stripslashes( (string) ( $args[0] ?? '' ) );
			return array_key_exists( $table, $this->tables ) ? $table : null;
		}
		if ( preg_match( '/SELECT (?:id|meta_id|umeta_id) FROM ([a-z0-9_]+)/i', $sql, $match ) ) {
			$rows = $this->tables[ $match[1] ] ?? array();
			if ( ! $rows ) {
				return null;
			}
			$first = reset( $rows );
			return $first['id'] ?? $first['meta_id'] ?? $first['umeta_id'] ?? null;
		}
		return null;
	}

	/** @return array{0:string,1:array<int,mixed>} */
	private function unpack( $query ): array {
		return $query instanceof ERankly_Phase3_Query ? array( $query->sql, $query->args ) : array( (string) $query, array() );
	}
}

/** Loads one JSON snapshot into the wpdb/meta/options doubles. */
function erankly_phase3_load_fixture( string $filename ): array {
	$json    = file_get_contents( __DIR__ . '/fixtures/migrations/' . $filename );
	$fixture = json_decode( (string) $json, true );
	erankly_phase2_assert( is_array( $fixture ), 'The migration snapshot must decode as JSON.' );
	$GLOBALS['erankly_phase3_fixture'] = $fixture;
	$GLOBALS['erankly_phase2_options'] = is_array( $fixture['options'] ?? null ) ? $fixture['options'] : array();

	$wpdb = new ERankly_Phase3_WPDB();
	$wpdb->tables = is_array( $fixture['tables'] ?? null ) ? $fixture['tables'] : array();
	$meta_id = 1;
	foreach ( array( 'posts' => array( 'wp_postmeta', 'post_id' ), 'terms' => array( 'wp_termmeta', 'term_id' ), 'users' => array( 'wp_usermeta', 'user_id' ) ) as $bucket => $config ) {
		$wpdb->tables[ $config[0] ] = array();
		foreach ( is_array( $fixture[ $bucket ] ?? null ) ? $fixture[ $bucket ] : array() as $object_id => $meta ) {
			foreach ( is_array( $meta ) ? $meta : array() as $key => $value ) {
				$wpdb->tables[ $config[0] ][] = array(
					( 'users' === $bucket ? 'umeta_id' : 'meta_id' ) => $meta_id++,
					$config[1]   => (int) $object_id,
					'meta_key'   => $key,
					'meta_value' => $value,
				);
			}
		}
	}
	$GLOBALS['wpdb'] = $wpdb;

	return $fixture;
}

/**
 * Collects every page while proving that replaying the pre-page checkpoint
 * returns byte-identical records and a byte-identical next cursor.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_phase3_collect( ERankly_Migration_Adapter $adapter, string $method ): array {
	$cursor  = array();
	$records = array();
	$loops   = 0;

	do {
		$input  = $cursor;
		$page   = $adapter->{$method}( $input, 1 );
		$replay = $adapter->{$method}( $input, 1 );
		erankly_phase2_assert( wp_json_encode( $page ) === wp_json_encode( $replay ), $adapter->label() . ' must replay the same checkpoint deterministically.' );
		foreach ( $page['records'] as $record ) {
			$records[] = $record;
		}
		$cursor = $page['cursor'];
		++$loops;
		erankly_phase2_assert( $loops < 100, $adapter->label() . ' cursor must always reach a terminal page.' );
	} while ( empty( $page['done'] ) );

	$references = array_map( static fn( array $record ): string => (string) ( $record['source_reference'] ?? '' ), $records );
	erankly_phase2_assert( count( $references ) === count( array_unique( $references ) ), $adapter->label() . ' keyset traversal must not duplicate source records.' );

	return $records;
}

/** Finds one normalized record by source reference. */
function erankly_phase3_record( array $records, string $reference ): array {
	foreach ( $records as $record ) {
		if ( $reference === (string) ( $record['source_reference'] ?? '' ) ) {
			return $record;
		}
	}
	return array();
}

erankly_phase3_load_fixture( 'yoast-free-pro.json' );
$yoast_content   = erankly_phase3_collect( new ERankly_Migration_Adapter_Yoast(), 'content_batch' );
$yoast_redirects = erankly_phase3_collect( new ERankly_Migration_Adapter_Yoast(), 'redirect_batch' );
erankly_phase2_assert( 4 === count( $yoast_content ), 'Yoast snapshot imports posts, native/legacy terms and authors.' );
erankly_phase2_assert( 3 === count( $yoast_redirects ), 'Yoast Premium base, regex export and per-post redirects are all paged.' );
$yoast_post = erankly_phase3_record( $yoast_content, 'post:10' );
erankly_phase2_assert( 2 === count( $yoast_post['meta']['_erankly_focus_keywords'] ), 'Yoast Premium additional keyphrases survive the resumable path.' );
erankly_phase2_assert( 'https://example.test/yoast-canonical' === $yoast_post['meta']['_erankly_canonical'], 'Yoast canonical survives the resumable path.' );
erankly_phase2_assert( $yoast_post['meta']['_erankly_og_image_url'] !== $yoast_post['meta']['_erankly_twitter_image_url'], 'Yoast Open Graph and X images remain independent.' );
erankly_phase2_assert( 'index' === $yoast_post['meta']['_erankly_index_directive'] && 'nofollow' === $yoast_post['meta']['_erankly_follow_directive'] && 'nosnippet' === $yoast_post['meta']['_erankly_snippet_directive'], 'Yoast basic and advanced robots survive the resumable path.' );
erankly_phase2_assert( 'merge' === $yoast_post['meta']['_erankly_schema_mode'] && 2 === count( $yoast_post['meta']['_erankly_schema_blocks'] ), 'Yoast page and article schema survive the resumable path.' );
erankly_phase2_assert( 21 === $yoast_post['meta']['_erankly_primary_terms']['category'], 'Yoast primary terms survive the resumable path.' );
erankly_phase2_assert( 308 === $yoast_redirects[0]['status_code'] && 'regex' === $yoast_redirects[1]['match_type'], 'Yoast Premium status and regex behavior survive pagination.' );

erankly_phase3_load_fixture( 'rankmath-free-pro.json' );
$rank_content   = erankly_phase3_collect( new ERankly_Migration_Adapter_RankMath(), 'content_batch' );
$rank_redirects = erankly_phase3_collect( new ERankly_Migration_Adapter_RankMath(), 'redirect_batch' );
erankly_phase2_assert( 3 === count( $rank_content ), 'Rank Math snapshot imports post, term and author metadata.' );
erankly_phase2_assert( 3 === count( $rank_redirects ), 'A Rank Math multi-source row resumes within the row without loss.' );
erankly_phase2_assert( 'regex' === $rank_redirects[1]['match_type'] && 'starts_with' === $rank_redirects[2]['match_type'], 'Rank Math Pro match modes survive paginated redirects.' );
$rank_post = erankly_phase3_record( $rank_content, 'post:11' );
erankly_phase2_assert( $rank_post['meta']['_erankly_twitter_image_url'] === $rank_post['meta']['_erankly_og_image_url'] && 'summary_large_image' === $rank_post['meta']['_erankly_twitter_card_type'], 'Rank Math social inheritance and card type survive pagination.' );
erankly_phase2_assert( 'noindex' === $rank_post['meta']['_erankly_index_directive'] && '120' === $rank_post['meta']['_erankly_max_snippet'], 'Rank Math Free and Pro robots survive pagination.' );
erankly_phase2_assert( 99 === $rank_post['meta']['_erankly_primary_terms']['product_cat'] && ! empty( $rank_post['meta']['_erankly_schema_blocks'] ), 'Rank Math primary terms and schema survive pagination.' );

erankly_phase3_load_fixture( 'aioseo-free-pro.json' );
$aio_content   = erankly_phase3_collect( new ERankly_Migration_Adapter_AIOSEO(), 'content_batch' );
$aio_redirects = erankly_phase3_collect( new ERankly_Migration_Adapter_AIOSEO(), 'redirect_batch' );
erankly_phase2_assert( 3 === count( $aio_content ), 'AIOSEO v4 posts, Pro terms and legacy v3 postmeta are traversed.' );
erankly_phase2_assert( 1 === count( $aio_redirects ) && 'exact' === $aio_redirects[0]['query_mode'], 'AIOSEO Pro redirect query semantics survive the keyset path.' );
$aio_post = erankly_phase3_record( $aio_content, 'aioseo_posts:4' );
erankly_phase2_assert( $aio_post['meta']['_erankly_twitter_image_url'] === $aio_post['meta']['_erankly_og_image_url'] && 'summary_large_image' === $aio_post['meta']['_erankly_twitter_card_type'], 'AIOSEO Open Graph-to-X inheritance survives pagination.' );
erankly_phase2_assert( 'noindex' === $aio_post['meta']['_erankly_index_directive'] && 'noarchive' === $aio_post['meta']['_erankly_archive_directive'] && 'noimageindex' === $aio_post['meta']['_erankly_image_directive'], 'AIOSEO robots survive pagination.' );
erankly_phase2_assert( 2 === count( $aio_post['meta']['_erankly_focus_keywords'] ) && true === $aio_post['meta']['_erankly_cornerstone'], 'AIOSEO Pro keyphrases and pillar state survive pagination.' );
erankly_phase2_assert( 23 === $aio_post['meta']['_erankly_primary_terms']['category'] && ! empty( $aio_post['meta']['_erankly_schema_blocks'] ), 'AIOSEO primary terms and schema survive pagination.' );
erankly_phase2_assert( 307 === $aio_redirects[0]['status_code'] && 0 === $aio_redirects[0]['case_sensitive'], 'AIOSEO Pro redirect status and case behavior survive pagination.' );

erankly_phase3_load_fixture( 'seopress-free-pro.json' );
$seopress_content   = erankly_phase3_collect( new ERankly_Migration_Adapter_SEOPress(), 'content_batch' );
$seopress_redirects = erankly_phase3_collect( new ERankly_Migration_Adapter_SEOPress(), 'redirect_batch' );
erankly_phase2_assert( 2 === count( $seopress_content ), 'SEOPress post and term metadata are paged.' );
erankly_phase2_assert( 2 === count( $seopress_redirects ), 'SEOPress Pro post and term redirects are paged.' );
erankly_phase2_assert( 'logged_in' === $seopress_redirects[0]['visibility'] && 'regex' === $seopress_redirects[0]['match_type'], 'SEOPress Pro visibility and regex conditions survive resume.' );
$seopress_post = erankly_phase3_record( $seopress_content, 'post:14' );
erankly_phase2_assert( $seopress_post['meta']['_erankly_og_image_url'] !== $seopress_post['meta']['_erankly_twitter_image_url'], 'SEOPress Facebook and X images remain independent.' );
erankly_phase2_assert( 'noindex' === $seopress_post['meta']['_erankly_index_directive'] && 'nofollow' === $seopress_post['meta']['_erankly_follow_directive'] && 'nosnippet' === $seopress_post['meta']['_erankly_snippet_directive'], 'SEOPress robots survive pagination.' );
erankly_phase2_assert( 12 === $seopress_post['meta']['_erankly_primary_terms']['category'] && ! empty( $seopress_post['meta']['_erankly_schema_blocks'] ), 'SEOPress primary category and Pro schema survive pagination.' );
erankly_phase2_assert( '^/seopress/(.*)$' === $seopress_redirects[0]['source_path'], 'SEOPress Pro redirect CPT uses its stored source pattern.' );

fwrite( STDOUT, "Phase 3 resumable Free/PRO snapshot integration tests passed.\n" );
