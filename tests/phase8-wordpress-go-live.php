<?php
// phpcs:ignoreFile -- WP-CLI harness mutates an ephemeral certification site.
/**
 * Phase 8 real WordPress/MySQL cutover state-machine certification.
 *
 * Run: wp eval-file wp-content/plugins/easyrankly/tests/phase8-wordpress-go-live.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run the Phase 8 WordPress certification through WP-CLI.' );
}

require_once ERANKLY_PATH . 'includes/import-export.php';
require_once ERANKLY_PATH . 'includes/reset.php';

/** Fails one Phase 8 runtime assertion. */
function erankly_phase8_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** Finishes one real resumable migration. */
function erankly_phase8_wp_finish_job( string $job_id ): array {
	$loops = 0;
	do {
		erankly_migration_job_runner()->process( $job_id );
		$active = erankly_migration_job_runner()->active_job();
		erankly_phase8_wp_assert( ++$loops <= 80, 'The Phase 8 migration did not reach a terminal checkpoint.' );
	} while ( is_array( $active ) );

	$report = erankly_migration_manager()->get_report( $job_id );
	erankly_phase8_wp_assert( is_array( $report ), 'The Phase 8 migration must persist its report.' );

	return $report;
}

wp_set_current_user( 1 );
$source_owns_output = true;
$response_variant   = 'equivalent';
add_filter(
	'erankly_migration_source_owns_output',
	static function () use ( &$source_owns_output ): bool {
		return $source_owns_output;
	},
	10,
	3
);
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) use ( &$response_variant, &$source_owns_output ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/robots.txt' === $path ) {
			$extra_rule = ! $source_owns_output && 'robots_mismatch' === $response_variant ? "Disallow: /private/\n" : '';
			$body       = "# Provider comment\nUser-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" . $extra_rule . 'Sitemap: ' . home_url( $source_owns_output ? '/sitemap_index.xml' : '/wp-sitemap.xml' ) . "\n";
		} elseif ( '/sitemap_index.xml' === $path ) {
			$body = '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . esc_url( home_url( '/phase8-yoast-post-sitemap.xml' ) ) . '</loc></sitemap></sitemapindex>';
		} elseif ( '/wp-sitemap.xml' === $path ) {
			$body = '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . esc_url( home_url( '/wp-sitemap-posts-post-1.xml' ) ) . '</loc></sitemap></sitemapindex>';
		} elseif ( in_array( $path, array( '/phase8-yoast-post-sitemap.xml', '/wp-sitemap-posts-post-1.xml' ), true ) ) {
			$content_path = ! $source_owns_output && 'sitemap_mismatch' === $response_variant ? '/phase8-missing-content/' : '/phase8-content/';
			$body         = '<?xml version="1.0"?><urlset><url><loc>' . esc_url( home_url( $content_path ) ) . '</loc></url></urlset>';
		} else {
			$title = 'page_mismatch' === $response_variant ? 'Phase 8 mismatched title' : 'Phase 8 source title';
			if ( $source_owns_output ) {
				$body = '<!doctype html><html><head><title>' . esc_html( $title ) . '</title><meta name="robots" content="index, follow"><meta property="og:title" content="Phase 8 source title"><meta property="og:url" content="' . esc_url( $url ) . '"><meta name="twitter:card" content="summary"><meta name="twitter:label1" content="Written by"><meta name="twitter:data1" content="Admin"><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":"Article","headline":"Phase 8 source title"}]}</script></head><body>Fixture</body></html>';
			} else {
				$body = '<!doctype html><html><head><title>' . esc_html( $title ) . '</title><link rel="canonical" href="' . esc_url( $url ) . '"><meta name="robots" content="index, follow, max-image-preview:large"><meta property="og:title" content="Phase 8 source title"><meta property="og:url" content="' . esc_url( $url ) . '"><meta property="og:description" content="Generated destination summary"><meta name="twitter:card" content="summary"><meta name="twitter:title" content="Phase 8 source title"><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@id":"#article","@type":"Article"},{"@id":"#webpage","@type":"WebPage","mainEntity":{"@id":"#article"}}]}</script></head><body>Fixture</body></html>';
			}
		}

		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Phase 8 gate fixture',
		'post_content' => 'Clean cutover fixture.',
		'post_status'  => 'publish',
	),
	true
);
erankly_phase8_wp_assert( ! is_wp_error( $post_id ) && (int) $post_id > 0, 'The Phase 8 source post must be created.' );
update_post_meta( (int) $post_id, '_yoast_wpseo_title', 'Phase 8 source title' );
update_post_meta( (int) $post_id, '_yoast_wpseo_metadesc', 'Phase 8 source description' );
update_option( 'wpseo_version', '28.0.0', false );

$started = erankly_migration_job_runner()->start( 'yoast', false );
erankly_phase8_wp_assert( ! empty( $started['ok'] ) && ! empty( $started['job']['id'] ), 'The clean Phase 8 database migration must start.' );
$job_id = (string) $started['job']['id'];
$report = erankly_phase8_wp_finish_job( $job_id );
$gate   = $report['go_live_gate'] ?? array();
erankly_phase8_wp_assert( 'ready_for_cutover' === ( $gate['state'] ?? '' ) && ! empty( $gate['ready_for_cutover'] ) && ! empty( $gate['can_verify_live'] ), 'A clean real import must stop at controlled cutover authorization.' );
erankly_phase8_wp_assert( 'captured' === ( $report['html_baseline']['state'] ?? '' ), 'The clean database import must carry a real old-plugin frontend baseline.' );

$_GET['report_id'] = $job_id;
ob_start();
erankly_migration_render_report();
$ready_panel = (string) ob_get_clean();
unset( $_GET['report_id'] );
erankly_phase8_wp_assert( str_contains( $ready_panel, 'Import complete' ) && str_contains( $ready_panel, 'Step 2 of 3' ) && str_contains( $ready_panel, 'Open Plugins in a new tab' ), 'An active source must render the guided deactivation step.' );
erankly_phase8_wp_assert( ! str_contains( $ready_panel, 'Start final verification' ) && 1 === substr_count( $ready_panel, 'button-primary' ), 'The active-source screen must expose exactly one primary action and no verification action.' );
erankly_phase8_wp_assert( str_contains( $ready_panel, 'Technical details' ) && str_contains( $ready_panel, 'Decision SHA-256' ), 'The simplified screen must preserve all technical evidence behind disclosure.' );

$source_owns_output = false;
$_GET['report_id']  = $job_id;
ob_start();
erankly_migration_render_report();
$verify_panel = (string) ob_get_clean();
unset( $_GET['report_id'] );
erankly_phase8_wp_assert( str_contains( $verify_panel, 'Step 3 of 3' ) && str_contains( $verify_panel, 'Start final verification' ), 'After source deactivation the same report must advance to final verification.' );
erankly_phase8_wp_assert( 1 === substr_count( $verify_panel, 'button-primary' ), 'The final-verification screen must retain one primary action.' );

$_GET['report_id']          = $job_id;
$_GET['erankly_io_notice']  = 'migration-started';
ob_start();
erankly_import_export_render_notice();
$stale_notice = (string) ob_get_clean();
unset( $_GET['report_id'], $_GET['erankly_io_notice'] );
erankly_phase8_wp_assert( '' === trim( $stale_notice ), 'A completed report must suppress the stale queued notice from its URL.' );

$passed_report                    = $report;
$passed_report['live_verification'] = ( new ERankly_Migration_Live_Verifier() )->verify( $report );
erankly_phase8_wp_assert( 'verified' === ( $passed_report['live_verification']['state'] ?? '' ), 'Matching real HTTP probes must produce verified live evidence.' );
erankly_phase8_wp_assert( 0 === (int) ( $passed_report['live_verification']['mismatch'] ?? -1 ) && (int) ( $passed_report['live_verification']['expected_changes'] ?? 0 ) >= 3, 'Provider-specific HTML, robots and sitemap endpoints must be accepted only when their SEO meaning and final inventory remain equivalent.' );
erankly_migration_manager()->update_report( $passed_report );
$passed_report = erankly_migration_manager()->get_report( $job_id );
erankly_phase8_wp_assert( 'go_live' === ( $passed_report['go_live_gate']['state'] ?? '' ) && ! empty( $passed_report['go_live_gate']['go_live'] ), 'Matching live evidence must promote the persisted report to full go-live PASS.' );
$_GET['report_id'] = $job_id;
ob_start();
erankly_migration_render_report();
$passed_panel = (string) ob_get_clean();
unset( $_GET['report_id'] );
erankly_phase8_wp_assert( str_contains( $passed_panel, 'Migration complete and verified' ) && str_contains( $passed_panel, 'Expected provider-specific markup and endpoint changes were accepted' ), 'Equivalent provider output must render as complete without exposing expected changes as problems.' );
erankly_phase8_wp_assert( ! str_contains( $passed_panel, 'What needs attention' ) && str_contains( $passed_panel, '<strong>0</strong><span>Items needing attention</span>' ), 'Expected provider changes must not create an attention panel or inflate the problem count.' );

$legacy_report = $report;
foreach ( $legacy_report['html_baseline']['pages'] as &$legacy_page ) {
	unset( $legacy_page['profile'], $legacy_page['profile_version'] );
}
unset( $legacy_page );
foreach ( array( 'robots', 'sitemap' ) as $surface ) {
	$legacy_surface                  = $legacy_report['html_baseline']['surfaces'][ $surface ];
	$legacy_surface['semantic_hash'] = (string) ( $legacy_surface['legacy_semantic_hash'] ?? '' );
	$legacy_report['html_baseline']['surfaces'][ $surface ] = array_intersect_key(
		$legacy_surface,
		array_flip( array( 'request_state', 'status_code', 'semantic_hash', 'entry_count', 'url' ) )
	);
}
$legacy_live = ( new ERankly_Migration_Live_Verifier() )->verify( $legacy_report );
erankly_phase8_wp_assert( 'verified' === ( $legacy_live['state'] ?? '' ) && 0 === (int) ( $legacy_live['mismatch'] ?? -1 ) && (int) ( $legacy_live['expected_changes'] ?? 0 ) >= 3, 'Reports captured before field-level profiles existed must be upgraded safely through legacy coverage and endpoint checks.' );

$response_variant = 'robots_mismatch';
$robots_mismatch  = ( new ERankly_Migration_Live_Verifier() )->verify( $report );
erankly_phase8_wp_assert( 'differences_found' === ( $robots_mismatch['state'] ?? '' ) && 'mismatch' === ( $robots_mismatch['surfaces']['robots']['status'] ?? '' ), 'A real robots.txt rule change must remain a rollback-triggering regression.' );

$response_variant = 'sitemap_mismatch';
$sitemap_mismatch = ( new ERankly_Migration_Live_Verifier() )->verify( $report );
erankly_phase8_wp_assert( 'differences_found' === ( $sitemap_mismatch['state'] ?? '' ) && 'mismatch' === ( $sitemap_mismatch['surfaces']['sitemap']['status'] ?? '' ), 'A real final sitemap inventory change must remain a rollback-triggering regression.' );

$response_variant                    = 'page_mismatch';
$mismatch_report                     = $report;
$mismatch_report['live_verification'] = ( new ERankly_Migration_Live_Verifier() )->verify( $report );
erankly_phase8_wp_assert( 'differences_found' === ( $mismatch_report['live_verification']['state'] ?? '' ), 'Changed live HTML must be detected against the same persisted baseline.' );
erankly_migration_manager()->update_report( $mismatch_report );
$mismatch_report = erankly_migration_manager()->get_report( $job_id );
erankly_phase8_wp_assert( 'rollback_required' === ( $mismatch_report['go_live_gate']['state'] ?? '' ) && ! empty( $mismatch_report['go_live_gate']['rollback_required'] ), 'A real live mismatch must persist ROLLBACK REQUIRED.' );

$_GET['report_id'] = $job_id;
ob_start();
erankly_migration_render_report();
$mismatch_panel = (string) ob_get_clean();
unset( $_GET['report_id'] );
erankly_phase8_wp_assert( str_contains( $mismatch_panel, 'The final verification needs attention' ) && str_contains( $mismatch_panel, 'Review the differences' ), 'A mismatch must lead with an understandable review action.' );
erankly_phase8_wp_assert( str_contains( $mismatch_panel, 'Run verification again' ) && str_contains( $mismatch_panel, 'Roll back this migration' ), 'The mismatch state must retain retry and safe rollback as secondary recovery actions.' );
erankly_phase8_wp_assert( 1 === substr_count( $mismatch_panel, 'button-primary' ), 'The mismatch screen must expose only review as its primary action.' );

$rollback                           = erankly_migration_journal()->rollback( $job_id );
$mismatch_report['rollback_result'] = array_merge( $rollback, array( 'requested_at' => gmdate( 'c' ) ) );
$mismatch_report['evidence']['rollback'] = erankly_migration_journal()->summary( $job_id );
erankly_migration_manager()->update_report( $mismatch_report );
$rolled_back = erankly_migration_manager()->get_report( $job_id );
erankly_phase8_wp_assert( 'rolled_back' === ( $rolled_back['go_live_gate']['state'] ?? '' ) && 0 === (int) ( $rollback['failed'] ?? 0 ), 'A real conditional rollback must terminate in ROLLED BACK without failures.' );
erankly_phase8_wp_assert( ! metadata_exists( 'post', (int) $post_id, '_erankly_title' ), 'Rollback must remove the unchanged migration-owned target value.' );

erankly_reset_site_data();
wp_delete_post( (int) $post_id, true );
delete_option( 'wpseo_version' );

WP_CLI::success( 'Phase 8 real WordPress/MySQL go-live state-machine certification passed.' );
