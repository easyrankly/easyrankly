<?php
// phpcs:ignoreFile -- WP-CLI harness mutates an ephemeral Multisite network.
/**
 * Phase 7 Multisite isolation certification.
 *
 * Run:
 * wp eval-file wp-content/plugins/easyrankly/tests/phase7-multisite-certification.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run the Phase 7 Multisite certification through WP-CLI.' );
}

require_once ERANKLY_PATH . 'includes/import-export.php';
require_once ERANKLY_PATH . 'includes/reset.php';

function erankly_phase7_ms_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
}

function erankly_phase7_ms_finish( string $job_id ): array {
	$loops = 0;
	do {
		erankly_migration_job_runner()->process( $job_id );
		$active = erankly_migration_job_runner()->active_job();
		erankly_phase7_ms_assert( ++$loops < 120, 'A Multisite migration did not terminate.' );
	} while ( is_array( $active ) );

	$report = erankly_migration_manager()->get_report( $job_id );
	erankly_phase7_ms_assert( is_array( $report ) && 'complete' === ( $report['status'] ?? '' ), 'Each site must persist its own completed migration report.' );

	return $report;
}

erankly_phase7_ms_assert( is_multisite(), 'This certification requires an installed Multisite network.' );
wp_set_current_user( 1 );

$network = get_network();
erankly_phase7_ms_assert( $network instanceof WP_Network, 'The current WordPress network must be available.' );

$child_domain = is_subdomain_install() ? 'phase7-child.' . preg_replace( '#^www\.#', '', (string) $network->domain ) : (string) $network->domain;
$child_path   = is_subdomain_install() ? '/' : trailingslashit( (string) $network->path ) . 'phase7-child/';
$second_blog_id = wpmu_create_blog(
	$child_domain,
	$child_path,
	'Phase 7 child site',
	1,
	array( 'public' => 0 ),
	(int) $network->id
);
erankly_phase7_ms_assert( ! is_wp_error( $second_blog_id ) && (int) $second_blog_id > 1, 'The isolated child site must be created.' );

$blog_ids = array( get_main_site_id(), (int) $second_blog_id );
$records  = array();

foreach ( $blog_ids as $position => $blog_id ) {
	switch_to_blog( $blog_id );
	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Phase 7 site ' . $blog_id,
			'post_status' => 'publish',
		),
		true
	);
	erankly_phase7_ms_assert( ! is_wp_error( $post_id ), 'Each site must create an independent source post.' );
	$title = 'Migrated site ' . $blog_id;
	update_post_meta( (int) $post_id, '_yoast_wpseo_title', $title );
	update_option( 'wpseo_version', '28.0.0', false );

	$started = erankly_migration_job_runner()->start( 'yoast', false );
	erankly_phase7_ms_assert( ! empty( $started['ok'] ), 'Each site-scoped migration must start independently.' );
	$report = erankly_phase7_ms_finish( (string) $started['job']['id'] );
	erankly_phase7_ms_assert( 'pass' === ( $report['evidence']['invariant']['status'] ?? '' ), 'Each site-scoped accounting invariant must pass.' );
	erankly_phase7_ms_assert( $title === get_post_meta( (int) $post_id, '_erankly_title', true ), 'Each site must receive only its own migrated value.' );

	$records[ $blog_id ] = array(
		'job_id'      => (string) $report['id'],
		'post_id'     => (int) $post_id,
		'title'       => $title,
		'queue_table' => ERankly_Migration_Job_Store::table_name(),
		'journal'     => ERankly_Migration_Journal::table_name(),
	);
	restore_current_blog();
}

erankly_phase7_ms_assert( $records[ $blog_ids[0] ]['queue_table'] !== $records[ $blog_ids[1] ]['queue_table'], 'Migration queues must be physically site-scoped.' );
erankly_phase7_ms_assert( $records[ $blog_ids[0] ]['journal'] !== $records[ $blog_ids[1] ]['journal'], 'Rollback journals must be physically site-scoped.' );

foreach ( $blog_ids as $blog_id ) {
	$other_blog_id = $blog_id === $blog_ids[0] ? $blog_ids[1] : $blog_ids[0];
	switch_to_blog( $blog_id );
	erankly_phase7_ms_assert( is_array( erankly_migration_manager()->get_report( $records[ $blog_id ]['job_id'] ) ), 'A site must retain its own report.' );
	erankly_phase7_ms_assert( null === erankly_migration_manager()->get_report( $records[ $other_blog_id ]['job_id'] ), 'A site must not read another site migration report.' );
	erankly_phase7_ms_assert( $records[ $blog_id ]['title'] === get_post_meta( $records[ $blog_id ]['post_id'], '_erankly_title', true ), 'Switching blogs must not change migrated metadata ownership.' );

	$rollback = erankly_migration_journal()->rollback( $records[ $blog_id ]['job_id'] );
	erankly_phase7_ms_assert( 1 === (int) ( $rollback['rolled_back'] ?? 0 ) && 0 === (int) ( $rollback['failed'] ?? 0 ), 'Each site must roll back exactly its own write.' );
	erankly_phase7_ms_assert( ! metadata_exists( 'post', $records[ $blog_id ]['post_id'], '_erankly_title' ), 'A site rollback must remove its own unchanged migration value.' );

	erankly_reset_site_data();
	wp_delete_post( $records[ $blog_id ]['post_id'], true );
	delete_option( 'wpseo_version' );
	restore_current_blog();
}

if ( ! function_exists( 'wpmu_delete_blog' ) ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}
wpmu_delete_blog( (int) $second_blog_id, true );

WP_CLI::success( 'Phase 7 Multisite queue, report, metadata and rollback isolation certification passed.' );
