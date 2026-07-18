<?php
// phpcs:ignoreFile -- Standalone official-export adapter certification harness.
/**
 * Phase 4 adapter profiles and official export fallback tests.
 *
 * Run: php tests/phase4-adapter-certification.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

function sanitize_title( $value ) {
	return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ), '-' );
}

require __DIR__ . '/phase2-migration-smoke.php';

/** Collects all deterministic export pages. */
function erankly_phase4_export_collect( ERankly_Migration_Adapter $adapter, string $method, int $limit = 1 ): array {
	$cursor  = array();
	$records = array();
	$loops   = 0;
	do {
		$input  = $cursor;
		$page   = $adapter->{$method}( $input, $limit );
		$replay = $adapter->{$method}( $input, $limit );
		erankly_phase2_assert( wp_json_encode( $page ) === wp_json_encode( $replay ), 'Official export checkpoints must replay byte-identically.' );
		$records = array_merge( $records, $page['records'] );
		$cursor  = $page['cursor'];
		erankly_phase2_assert( ++$loops < 20, 'Official export cursor must reach a terminal page.' );
	} while ( empty( $page['done'] ) );

	return $records;
}

$fixtures = __DIR__ . '/fixtures/migrations/';

$yoast_file = $fixtures . 'yoast-redirects-official.csv';
$inspection = ERankly_Migration_Export_Reader::inspect( $yoast_file, 'yoast' );
erankly_phase2_assert( 'supported' === $inspection['status'] && 'yoast-redirects-csv' === $inspection['format'], 'Yoast Premium official Origin/Target/Type/Format CSV signature is certified.' );
$yoast = new ERankly_Migration_Adapter_Yoast();
erankly_phase2_assert( $yoast->use_export_file( $yoast_file ), 'Yoast official CSV can be selected as adapter source.' );
erankly_phase2_assert( 'premium' === $yoast->profile()['edition'] && in_array( 'redirects', $yoast->profile()['modules'], true ), 'Yoast redirect export proves the Premium redirect profile without installed source code.' );
$yoast_redirects = erankly_phase4_export_collect( $yoast, 'redirect_batch' );
erankly_phase2_assert( 2 === count( $yoast_redirects ) && 'regex' === $yoast_redirects[1]['match_type'] && 307 === $yoast_redirects[1]['status_code'], 'Yoast official CSV retains plain/regex behavior and status codes.' );

$rank_metadata_file = $fixtures . 'rankmath-metadata-official.csv';
$inspection         = ERankly_Migration_Export_Reader::inspect( $rank_metadata_file, 'rankmath' );
erankly_phase2_assert( 'rankmath-metadata-csv' === $inspection['format'], 'Rank Math PRO metadata CSV signature is certified.' );
$rank = new ERankly_Migration_Adapter_RankMath();
erankly_phase2_assert( $rank->use_export_file( $rank_metadata_file ), 'Rank Math metadata CSV can be selected.' );
erankly_phase2_assert( 'pro' === $rank->profile()['edition'], 'Rank Math metadata CSV proves its PRO profile.' );
$rank_content = erankly_phase4_export_collect( $rank, 'content_batch' );
$rank_meta    = $rank_content[0]['meta'];
erankly_phase2_assert( '{{post_title}} - {{site_name}}' === $rank_meta['_erankly_title'], 'Rank Math CSV variables remain runtime variables.' );
erankly_phase2_assert( 'noindex' === $rank_meta['_erankly_index_directive'] && 'nofollow' === $rank_meta['_erankly_follow_directive'] && '120' === $rank_meta['_erankly_max_snippet'], 'Rank Math CSV robots and advanced robots are retained.' );
erankly_phase2_assert( 2 === count( $rank_meta['_erankly_focus_keywords'] ) && true === $rank_meta['_erankly_cornerstone'] && ! empty( $rank_meta['_erankly_schema_blocks'] ), 'Rank Math CSV keyphrases, pillar state and schema are retained.' );
$rank_post_redirects = erankly_phase4_export_collect( $rank, 'redirect_batch' );
erankly_phase2_assert( 1 === count( $rank_post_redirects ) && 308 === $rank_post_redirects[0]['status_code'], 'Rank Math metadata CSV per-content redirects are retained.' );

$rank_redirect_file = $fixtures . 'rankmath-redirects-official.csv';
erankly_phase2_assert( $rank->use_export_file( $rank_redirect_file ), 'Rank Math PRO redirects CSV can replace the metadata export source.' );
$rank_redirects = erankly_phase4_export_collect( $rank, 'redirect_batch' );
erankly_phase2_assert( 2 === count( $rank_redirects ) && 'regex' === $rank_redirects[1]['match_type'] && 0 === $rank_redirects[1]['is_active'], 'Rank Math PRO redirect CSV retains regex and inactive status.' );

$aio_file = $fixtures . 'aioseo-redirects-official.json';
$inspection = ERankly_Migration_Export_Reader::inspect( $aio_file, 'aioseo' );
erankly_phase2_assert( 'aioseo-redirects-json' === $inspection['format'], 'AIOSEO Pro Complete Data JSON redirect signature is certified.' );
$aioseo = new ERankly_Migration_Adapter_AIOSEO();
erankly_phase2_assert( $aioseo->use_export_file( $aio_file ), 'AIOSEO Pro JSON can be selected.' );
erankly_phase2_assert( 'pro' === $aioseo->profile()['edition'] && in_array( 'redirects', $aioseo->profile()['modules'], true ), 'AIOSEO Complete Data redirect JSON proves its Pro redirect profile.' );
$aio_redirects = erankly_phase4_export_collect( $aioseo, 'redirect_batch' );
erankly_phase2_assert( 2 === count( $aio_redirects ) && 'exact' === $aio_redirects[0]['query_mode'] && 'regex' === $aio_redirects[1]['match_type'] && 0 === $aio_redirects[1]['is_active'], 'AIOSEO JSON retains query, regex and inactive behavior.' );

$seopress_file = $fixtures . 'seopress-metadata-official.csv';
$inspection    = ERankly_Migration_Export_Reader::inspect( $seopress_file, 'seopress' );
erankly_phase2_assert( 'seopress-metadata-csv' === $inspection['format'], 'SEOPress official metadata CSV signature is certified.' );
$seopress = new ERankly_Migration_Adapter_SEOPress();
erankly_phase2_assert( $seopress->use_export_file( $seopress_file ), 'SEOPress metadata CSV can be selected.' );
erankly_phase2_assert( 'free-or-pro' === $seopress->profile()['edition'], 'SEOPress metadata CSV remains edition-neutral when its shared signature cannot prove PRO.' );
$seopress_content = erankly_phase4_export_collect( $seopress, 'content_batch' );
erankly_phase2_assert( 2 === count( $seopress_content ) && 'post' === $seopress_content[0]['object_type'] && 'term' === $seopress_content[1]['object_type'], 'SEOPress CSV distinguishes posts and terms.' );
$seopress_meta = $seopress_content[0]['meta'];
erankly_phase2_assert( 'noindex' === $seopress_meta['_erankly_index_directive'] && 'nofollow' === $seopress_meta['_erankly_follow_directive'] && 12 === $seopress_meta['_erankly_primary_terms']['category'], 'SEOPress CSV robots and primary category are retained.' );
$seopress_redirects = erankly_phase4_export_collect( $seopress, 'redirect_batch' );
erankly_phase2_assert( 1 === count( $seopress_redirects ) && 302 === $seopress_redirects[0]['status_code'] && 'logged_in' === $seopress_redirects[0]['visibility'], 'SEOPress CSV redirect type and login visibility are retained without inventing an empty term redirect.' );

erankly_phase2_assert( 'unsupported' === ERankly_Migration_Export_Reader::inspect( $yoast_file, 'aioseo' )['status'], 'A file signed for another source fails closed.' );
erankly_phase2_assert( 2 === ERankly_Migration_Export_Reader::count_records( $yoast_file ), 'Export inventory excludes the CSV header.' );

fwrite( STDOUT, "Phase 4 adapter and official export certification passed.\n" );
