<?php
// phpcs:ignoreFile -- WP-CLI harness mutates an ephemeral certification site.
/**
 * Real WordPress/MySQL Phase 6 evidence, live-verification and rollback tests.
 *
 * Run inside a fresh WordPress installation with EasyRankly active:
 * wp eval-file wp-content/plugins/easyrankly/tests/phase6-wordpress-integration.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

require_once ERANKLY_PATH . 'includes/import-export.php';
require_once ERANKLY_PATH . 'includes/reset.php';

function erankly_phase6_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function erankly_phase6_finish_job( string $job_id ): array {
	$runner = erankly_migration_job_runner();
	$loops  = 0;
	while ( is_array( $runner->active_job() ) ) {
		$runner->process( $job_id );
		if ( ++$loops > 80 ) {
			throw new RuntimeException( 'The Phase 6 worker did not reach a terminal state.' );
		}
	}
	$report = erankly_migration_manager()->get_report( $job_id );
	erankly_phase6_wp_assert( is_array( $report ), 'The Phase 6 worker must persist a terminal report.' );
	return $report;
}

add_filter( 'erankly_migration_batch_size', static fn(): int => 10 );
add_filter( 'erankly_migration_live_sample_limit', static fn(): int => 3 );
wp_set_current_user( 1 );

$source_owns_output = true;
add_filter(
	'erankly_migration_source_owns_output',
	static function () use ( &$source_owns_output ): bool {
		return $source_owns_output;
	},
	10,
	3
);

$redirect_locations = array(
	'/phase6-chain-a' => home_url( '/phase6-chain-b' ),
	'/phase6-chain-b' => home_url( '/phase6-target' ),
	'/(phase6+)+$'    => home_url( '/phase6-regex-target' ),
);
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) use ( $redirect_locations ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( isset( $redirect_locations[ $path ] ) ) {
			return array(
				'headers'  => array( 'location' => $redirect_locations[ $path ] ),
				'body'     => '',
				'response' => array(
					'code'    => 301,
					'message' => 'Moved Permanently',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		if ( '/robots.txt' === $path ) {
			$body = "User-agent: *\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n";
		} elseif ( in_array( $path, array( '/sitemap_index.xml', '/wp-sitemap.xml' ), true ) ) {
			$body = '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . esc_url( home_url( '/wp-sitemap-posts-post-1.xml' ) ) . '</loc></sitemap></sitemapindex>';
		} elseif ( '/wp-sitemap-posts-post-1.xml' === $path ) {
			$body = '<?xml version="1.0"?><urlset><url><loc>' . esc_url( home_url( '/phase6-content/' ) ) . '</loc></url></urlset>';
		} else {
			$body = '<!doctype html><html><head><title>Phase 6 title</title><link rel="canonical" href="' . esc_url( $url ) . '"><meta name="robots" content="index, follow"><meta property="og:title" content="Phase 6 title"><meta name="twitter:title" content="Phase 6 title"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script></head><body>Fixture</body></html>';
		}

		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Phase 6 migration fixture',
		'post_content' => 'Evidence fixture.',
		'post_status'  => 'publish',
	)
);
erankly_phase6_wp_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'The evidence fixture post must be created.' );
update_post_meta( $post_id, '_yoast_wpseo_title', 'Phase 6 title' );
update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Phase 6 description' );
update_post_meta( $post_id, '_yoast_wpseo_canonical', home_url( '/phase6-source-canonical' ) );
update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', 'Phase 6 title' );
update_post_meta( $post_id, '_yoast_wpseo_twitter-title', 'Phase 6 title' );
update_post_meta( $post_id, '_erankly_canonical', home_url( '/keep-manual-canonical' ) );
update_option(
	'wpseo-premium-redirects-base',
	array(
		array(
			'origin' => '/phase6-chain-a',
			'url'    => '/phase6-chain-b',
			'type'   => 301,
			'format' => 'plain',
		),
		array(
			'origin' => '/phase6-chain-b',
			'url'    => '/phase6-target',
			'type'   => 301,
			'format' => 'plain',
		),
		array(
			'origin' => '/(phase6+)+$',
			'url'    => '/phase6-regex-target',
			'type'   => 302,
			'format' => 'regex',
		),
	),
	false
);

$started = erankly_migration_job_runner()->start( 'yoast', false );
erankly_phase6_wp_assert( ! empty( $started['ok'] ) && ! empty( $started['job']['id'] ), 'The Phase 6 import must start.' );
$job_id = (string) $started['job']['id'];
$report = erankly_phase6_finish_job( $job_id );

$evidence   = $report['evidence'] ?? array();
$accounting = $evidence['accounting'] ?? array();
erankly_phase6_wp_assert( 'pass' === (string) ( $evidence['invariant']['status'] ?? '' ), 'Every discovered occurrence must be classified exactly once.' );
erankly_phase6_wp_assert( ! empty( $accounting['metadata']['balanced'] ) && ! empty( $accounting['redirects']['balanced'] ), 'Metadata and redirect ledgers must balance.' );
erankly_phase6_wp_assert( (int) $accounting['metadata']['discovered'] === array_sum( $accounting['metadata']['terminal'] ), 'Metadata terminal outcomes must equal discovery count.' );
erankly_phase6_wp_assert( (int) $accounting['redirects']['discovered'] === array_sum( $accounting['redirects']['terminal'] ), 'Redirect terminal outcomes must equal discovery count.' );
erankly_phase6_wp_assert( ! empty( $evidence['redirect_audit']['chains'] ), 'The redirect audit must detect imported chains.' );
erankly_phase6_wp_assert( ! empty( $evidence['redirect_audit']['dangerous_regex'] ), 'The redirect audit must flag a dangerous imported regex.' );
erankly_phase6_wp_assert( 'captured' === (string) ( $report['html_baseline']['state'] ?? '' ), 'The old-plugin HTML/robots/sitemap/redirect baseline must be captured.' );
erankly_phase6_wp_assert( (int) ( $evidence['rollback']['available'] ?? 0 ) > 1, 'The report must expose a multi-record rollback journal.' );
erankly_phase6_wp_assert( ! empty( $evidence['exceptions'][0]['edit_url'] ), 'Record exceptions must link directly to the object editor.' );
erankly_phase6_wp_assert( (int) ( $evidence['exception_count'] ?? 0 ) === erankly_migration_evidence_store()->count( $job_id ), 'The persistent exception ledger must contain every reported exception.' );
erankly_phase6_wp_assert( 'complete_paged_storage' === (string) ( $evidence['exception_ledger'] ?? '' ), 'The report must prove that CSV exceptions use complete paged storage.' );

$_GET['report_id'] = $job_id;
ob_start();
erankly_migration_render_report();
$panel = (string) ob_get_clean();
unset( $_GET['report_id'] );
erankly_phase6_wp_assert( str_contains( $panel, 'Evidence ledger' ) && str_contains( $panel, 'Download items to review' ), 'The admin report must expose the invariant and CSV exception export.' );
erankly_phase6_wp_assert( str_contains( $panel, 'Roll back this migration' ) && ! str_contains( $panel, 'Start final verification' ), 'A blocked report must retain safe rollback while withholding live-verification authority.' );
erankly_phase6_wp_assert( str_contains( $panel, 'Final verification blocked' ) && str_contains( $panel, 'No redirect chains' ) && str_contains( $panel, 'No dangerous redirect regular expressions' ), 'The technical gate must surface the intentional redirect blockers in this evidence fixture.' );

$source_owns_output = false;
$live               = ( new ERankly_Migration_Live_Verifier() )->verify( $report );
erankly_phase6_wp_assert( 'verified' === $live['state'] && 0 === (int) $live['mismatch'], 'Post-cutover probes must match the captured baseline without following redirect chains.' );
erankly_phase6_wp_assert( (int) ( $live['expected_changes'] ?? 0 ) >= 1, 'A provider sitemap endpoint change must be recorded as expected instead of becoming a rollback trigger.' );

update_post_meta( $post_id, '_erankly_description', 'Manual edit after migration' );
$rollback = erankly_migration_journal()->rollback( $job_id );
erankly_phase6_wp_assert( 'partial' === $rollback['status'], 'A later manual edit must produce a safe partial rollback.' );
erankly_phase6_wp_assert( (int) $rollback['rolled_back'] > 0 && (int) $rollback['preserved'] > 0, 'Rollback must distinguish restored values from later manual edits.' );
erankly_phase6_wp_assert( 'Manual edit after migration' === get_post_meta( $post_id, '_erankly_description', true ), 'Rollback must never overwrite a later manual edit.' );
erankly_phase6_wp_assert( ! metadata_exists( 'post', $post_id, '_erankly_title' ), 'An unchanged migration-created metadata value must be removed by rollback.' );
erankly_phase6_wp_assert( home_url( '/keep-manual-canonical' ) === get_post_meta( $post_id, '_erankly_canonical', true ), 'A pre-existing EasyRankly conflict must remain untouched.' );

global $wpdb;
$remaining_redirects = $wpdb->get_var(
	$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE migration_id = %s', $wpdb->prefix . 'erankly_redirects', $job_id )
);
erankly_phase6_wp_assert( 0 === (int) $remaining_redirects, 'Unchanged migration-created redirects must be removed by rollback.' );
erankly_phase6_wp_assert( 0 === (int) erankly_migration_journal()->summary( $job_id )['available'], 'A completed rollback must consume every available journal entry.' );

erankly_reset_site_data();
erankly_phase6_wp_assert( ! erankly_table_exists( ERankly_Migration_Journal::table_name() ), 'Reset must remove conditional rollback storage.' );
erankly_phase6_wp_assert( ! erankly_table_exists( ERankly_Migration_Evidence_Store::table_name() ), 'Reset must remove the complete exception ledger.' );

WP_CLI::success( 'Phase 6 real WordPress/MySQL evidence and rollback certification passed.' );
