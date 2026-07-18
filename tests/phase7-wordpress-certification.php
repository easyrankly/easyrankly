<?php
// phpcs:ignoreFile -- WP-CLI harness deliberately builds third-party source storage.
/**
 * Phase 7 real WordPress/MySQL source, export and scale certification.
 *
 * Run:
 * wp eval-file wp-content/plugins/easyrankly/tests/phase7-wordpress-certification.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run the Phase 7 WordPress certification through WP-CLI.' );
}

require_once ERANKLY_PATH . 'includes/import-export.php';
require_once ERANKLY_PATH . 'includes/reset.php';

/** Fails the certification immediately. */
function erankly_phase7_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
}

/** Finishes one resumable migration and returns its terminal report. */
function erankly_phase7_finish_job( string $job_id, int $maximum = 240 ): array {
	$loops = 0;
	do {
		erankly_migration_job_runner()->process( $job_id );
		$active = erankly_migration_job_runner()->active_job();
		erankly_phase7_wp_assert( ++$loops <= $maximum, 'A Phase 7 migration did not reach a terminal checkpoint.' );
	} while ( is_array( $active ) );

	$report = erankly_migration_manager()->get_report( $job_id );
	erankly_phase7_wp_assert( is_array( $report ), 'Every Phase 7 migration must persist a terminal report.' );

	return $report;
}

/** Certifies a completed import and conditionally rolls it back. */
function erankly_phase7_certify_import( array $started, string $source, string $edition, string $surface ): array {
	erankly_phase7_wp_assert( ! empty( $started['ok'] ) && ! empty( $started['job']['id'] ), $source . ' ' . $surface . ' import must start.' );
	$job_id = (string) $started['job']['id'];
	$report = erankly_phase7_finish_job( $job_id );
	$counts = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();

	erankly_phase7_wp_assert( 'complete' === ( $report['status'] ?? '' ), $source . ' ' . $surface . ' import must complete.' );
	erankly_phase7_wp_assert( ! empty( $report['source_fingerprint_verified'] ), $source . ' ' . $surface . ' source fingerprint must remain immutable.' );
	erankly_phase7_wp_assert( $edition === ( $report['source_profile']['edition'] ?? '' ), $source . ' ' . $surface . ' edition must be proven by its source signature.' );
	erankly_phase7_wp_assert( 'pass' === ( $report['evidence']['invariant']['status'] ?? '' ), $source . ' ' . $surface . ' accounting invariant must pass.' );
	erankly_phase7_wp_assert( 0 === (int) ( $counts['fields_failed'] ?? 0 ) + (int) ( $counts['redirects_failed'] ?? 0 ), $source . ' ' . $surface . ' import must have zero failed writes.' );
	erankly_phase7_wp_assert( (int) ( $counts['fields_written'] ?? 0 ) + (int) ( $counts['redirects_created'] ?? 0 ) + (int) ( $counts['redirects_updated'] ?? 0 ) > 0, $source . ' ' . $surface . ' import must write at least one certified value.' );

	$rollback = erankly_migration_journal()->rollback( $job_id );
	erankly_phase7_wp_assert( 0 === (int) ( $rollback['failed'] ?? 0 ), $source . ' ' . $surface . ' rollback must have zero failures.' );
	erankly_phase7_wp_assert( (int) ( $rollback['rolled_back'] ?? 0 ) > 0, $source . ' ' . $surface . ' rollback must consume its migration writes.' );

	return $report;
}

/** Creates a post with a stable import ID required by an official CSV fixture. */
function erankly_phase7_import_post( int $post_id, string $title ): int {
	$existing = get_post( $post_id );
	if ( $existing instanceof WP_Post ) {
		return $post_id;
	}
	$created = wp_insert_post(
		array(
			'import_id'   => $post_id,
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => 'post',
		),
		true
	);
	erankly_phase7_wp_assert( ! is_wp_error( $created ) && $post_id === (int) $created, 'The official export fixture post ID must be available.' );

	return $post_id;
}

/** Creates the exact term ID used by the immutable SEOPress export fixture. */
function erankly_phase7_import_term( int $term_id ): void {
	global $wpdb;
	if ( get_term( $term_id ) instanceof WP_Term ) {
		return;
	}
	$wpdb->insert(
		$wpdb->terms,
		array(
			'term_id'    => $term_id,
			'name'       => 'Phase 7 SEOPress term',
			'slug'       => 'phase-7-seopress-term',
			'term_group' => 0,
		),
		array( '%d', '%s', '%s', '%d' )
	);
	$wpdb->insert(
		$wpdb->term_taxonomy,
		array(
			'term_id'     => $term_id,
			'taxonomy'    => 'category',
			'description' => '',
			'parent'      => 0,
			'count'       => 0,
		),
		array( '%d', '%s', '%s', '%d', '%d' )
	);
	clean_term_cache( $term_id, 'category' );
	erankly_phase7_wp_assert( get_term( $term_id ) instanceof WP_Term, 'The official SEOPress term fixture must exist in WordPress.' );
}

wp_set_current_user( 1 );
erankly_phase7_wp_assert( ! is_multisite(), 'Run this harness on the single-site matrix; Multisite has its own Phase 7 harness.' );

global $wpdb;
$created_posts = array();

// Yoast Free metadata plus Premium redirects.
$yoast_post     = wp_insert_post( array( 'post_title' => 'Phase 7 Yoast', 'post_status' => 'publish' ), true );
erankly_phase7_wp_assert( ! is_wp_error( $yoast_post ), 'Yoast source post must be created.' );
$created_posts[] = (int) $yoast_post;
update_post_meta( (int) $yoast_post, '_yoast_wpseo_title', 'Yoast %%title%%' );
update_post_meta( (int) $yoast_post, '_yoast_wpseo_metadesc', 'Yoast certified description' );
update_post_meta( (int) $yoast_post, '_yoast_wpseo_schema_page_type', 'AboutPage' );
update_option( 'wpseo_version', '28.0.0', false );
update_option(
	'wpseo-premium-redirects-base',
	array(
		array( 'origin' => '/phase7-yoast-old', 'url' => '/phase7-yoast-new', 'type' => 301, 'format' => 'plain' ),
	),
	false
);

// Rank Math metadata plus a real redirect-table signature. The PRO constant is
// declared only in this isolated certification process.
if ( ! defined( 'RANK_MATH_PRO_VERSION' ) ) {
	define( 'RANK_MATH_PRO_VERSION', '3.0.0' );
}
$rank_post       = wp_insert_post( array( 'post_title' => 'Phase 7 Rank Math', 'post_status' => 'publish' ), true );
erankly_phase7_wp_assert( ! is_wp_error( $rank_post ), 'Rank Math source post must be created.' );
$created_posts[] = (int) $rank_post;
update_post_meta( (int) $rank_post, 'rank_math_title', '%title% | %sitename%' );
update_post_meta( (int) $rank_post, 'rank_math_description', 'Rank Math certified description' );
update_post_meta( (int) $rank_post, 'rank_math_schema_Article', array( '@type' => 'Article', 'headline' => '%seo_title%' ) );
update_option( 'rank_math_version', '1.0.247', false );
$rank_table = $wpdb->prefix . 'rank_math_redirections';
$wpdb->query( "DROP TABLE IF EXISTS {$rank_table}" );
$wpdb->query( "CREATE TABLE {$rank_table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, sources longtext NOT NULL, url_to text NOT NULL, header_code smallint unsigned NOT NULL, status varchar(20) NOT NULL, PRIMARY KEY (id)) {$wpdb->get_charset_collate()}" );
$wpdb->insert(
	$rank_table,
	array(
		'sources'     => wp_json_encode( array( array( 'pattern' => '/phase7-rank-old', 'comparison' => 'exact' ) ) ),
		'url_to'      => '/phase7-rank-new',
		'header_code' => 302,
		'status'      => 'active',
	)
);

// AIOSEO Lite v3 metadata plus the PRO redirect-table signature.
$aio_post        = wp_insert_post( array( 'post_title' => 'Phase 7 AIOSEO', 'post_status' => 'publish' ), true );
erankly_phase7_wp_assert( ! is_wp_error( $aio_post ), 'AIOSEO source post must be created.' );
$created_posts[] = (int) $aio_post;
update_post_meta( (int) $aio_post, '_aioseop_title', 'AIOSEO certified title' );
update_post_meta( (int) $aio_post, '_aioseop_description', 'AIOSEO certified description' );
update_option( 'aioseo_version', '4.8.5', false );
$aio_table = $wpdb->prefix . 'aioseo_redirects';
$wpdb->query( "DROP TABLE IF EXISTS {$aio_table}" );
$wpdb->query( "CREATE TABLE {$aio_table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, source_url text NOT NULL, target_url text NOT NULL, type smallint unsigned NOT NULL, source_url_match varchar(20) NOT NULL, query_param varchar(20) NOT NULL DEFAULT 'ignore', enabled tinyint NOT NULL DEFAULT 1, ignore_case tinyint NOT NULL DEFAULT 1, PRIMARY KEY (id)) {$wpdb->get_charset_collate()}" );
$wpdb->insert(
	$aio_table,
	array(
		'source_url'      => '/phase7-aio-old',
		'target_url'      => '/phase7-aio-new',
		'type'            => 307,
		'source_url_match'=> 'exact',
		'query_param'     => 'ignore',
		'enabled'         => 1,
		'ignore_case'     => 1,
	)
);

// SEOPress Free metadata plus PRO schema and redirect fields.
$seopress_post   = wp_insert_post( array( 'post_title' => 'Phase 7 SEOPress', 'post_status' => 'publish' ), true );
erankly_phase7_wp_assert( ! is_wp_error( $seopress_post ), 'SEOPress source post must be created.' );
$created_posts[] = (int) $seopress_post;
update_post_meta( (int) $seopress_post, '_seopress_titles_title', 'SEOPress %%post_title%%' );
update_post_meta( (int) $seopress_post, '_seopress_titles_desc', 'SEOPress certified description' );
update_post_meta( (int) $seopress_post, '_seopress_pro_rich_snippets_type', 'events' );
update_post_meta( (int) $seopress_post, '_seopress_pro_rich_snippets_events_name', 'Phase 7 event' );
update_post_meta( (int) $seopress_post, '_seopress_redirections_value', '/phase7-seopress-new' );
update_post_meta( (int) $seopress_post, '_seopress_redirections_enabled', 'yes' );
update_post_meta( (int) $seopress_post, '_seopress_redirections_type', '301' );
update_option( 'seopress_version', '8.8.0', false );

$database_cases = array(
	array( 'yoast', 'premium' ),
	array( 'rankmath', 'pro' ),
	array( 'aioseo', 'pro' ),
	array( 'seopress', 'pro' ),
);
foreach ( $database_cases as $case ) {
	erankly_phase7_certify_import( erankly_migration_job_runner()->start( $case[0], false ), $case[0], $case[1], 'database' );
}

// Create the immutable IDs referenced by the official metadata exports.
$created_posts[] = erankly_phase7_import_post( 31, 'Rank Math official export target' );
$created_posts[] = erankly_phase7_import_post( 41, 'SEOPress official export target' );
erankly_phase7_import_term( 42 );

$fixture_path = ERANKLY_PATH . 'tests/fixtures/migrations/';
$export_cases = array(
	array( 'yoast', 'premium', 'yoast-redirects-official.csv' ),
	array( 'rankmath', 'pro', 'rankmath-metadata-official.csv' ),
	array( 'rankmath', 'free-or-pro', 'rankmath-redirects-official.csv' ),
	array( 'aioseo', 'pro', 'aioseo-redirects-official.json' ),
	array( 'seopress', 'free-or-pro', 'seopress-metadata-official.csv' ),
);
foreach ( $export_cases as $case ) {
	erankly_phase7_certify_import( erankly_migration_job_runner()->start_from_export( $case[0], $fixture_path . $case[2], false ), $case[0], $case[1], 'official export' );
}

// Bounded scale certification. The default is intentionally large enough to
// force multiple discovery/apply checkpoints while remaining CI-friendly.
$scale          = max( 100, min( 2000, (int) ( getenv( 'ERANKLY_CERT_SCALE' ) ?: 500 ) ) );
$scale_post_ids = array();
for ( $index = 0; $index < $scale; ++$index ) {
	$post_id = wp_insert_post( array( 'post_title' => 'Phase 7 scale ' . $index, 'post_status' => 'publish' ), true );
	erankly_phase7_wp_assert( ! is_wp_error( $post_id ), 'Scale source posts must be created.' );
	$scale_post_ids[] = (int) $post_id;
	update_post_meta( (int) $post_id, 'rank_math_title', 'Scale %title% ' . $index );
	update_post_meta( (int) $post_id, 'rank_math_description', 'Bounded migration scale fixture ' . $index );
}

$started_at = microtime( true );
$peak_start = memory_get_peak_usage( true );
$scale_job  = erankly_migration_job_runner()->start( 'rankmath', true );
erankly_phase7_wp_assert( ! empty( $scale_job['ok'] ), 'The scale preview must start.' );
$scale_report = erankly_phase7_finish_job( (string) $scale_job['job']['id'], 600 );
$elapsed      = microtime( true ) - $started_at;
$peak_delta   = max( 0, memory_get_peak_usage( true ) - $peak_start );
$max_seconds  = max( 30, (int) ( getenv( 'ERANKLY_CERT_MAX_SECONDS' ) ?: 180 ) );
$max_memory   = max( 64, (int) ( getenv( 'ERANKLY_CERT_MAX_MEMORY_MB' ) ?: 256 ) ) * MB_IN_BYTES;

erankly_phase7_wp_assert( 'complete' === ( $scale_report['status'] ?? '' ), 'The scale preview must complete.' );
erankly_phase7_wp_assert( (int) ( $scale_report['counts']['posts_found'] ?? 0 ) >= $scale, 'The scale preview must account for every generated source post.' );
erankly_phase7_wp_assert( (int) ( $scale_report['execution']['batches'] ?? 0 ) > 1, 'The scale preview must prove bounded multi-batch execution.' );
erankly_phase7_wp_assert( $elapsed <= $max_seconds, 'The scale preview exceeded its explicit wall-clock budget.' );
erankly_phase7_wp_assert( $peak_delta <= $max_memory, 'The scale preview exceeded its explicit incremental-memory budget.' );

erankly_reset_site_data();
foreach ( array_unique( array_merge( $created_posts, $scale_post_ids ) ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
wp_delete_term( 42, 'category' );
foreach ( array( 'wpseo_version', 'wpseo-premium-redirects-base', 'rank_math_version', 'aioseo_version', 'seopress_version' ) as $option ) {
	delete_option( $option );
}
$wpdb->query( "DROP TABLE IF EXISTS {$rank_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$aio_table}" );

WP_CLI::success(
	sprintf(
		'Phase 7 single-site certification passed: four database adapters, five official exports and %d-post scale preview in %.2fs.',
		$scale,
		$elapsed
	)
);
