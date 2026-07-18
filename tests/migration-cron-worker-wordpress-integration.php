<?php
// phpcs:ignoreFile -- WP-CLI integration harness mutates an ephemeral test site.
/**
 * Processes the seeded migration through the real callback in a fresh request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

$included = array_map( 'wp_normalize_path', get_included_files() );
if ( in_array( wp_normalize_path( ERANKLY_PATH . 'includes/import-export.php' ), $included, true ) ) {
	throw new RuntimeException( 'The Cron worker request unexpectedly preloaded Import / Export.' );
}

$fixture = get_option( 'erankly_migration_cron_test_fixture', array() );
$job_id  = is_array( $fixture ) ? sanitize_text_field( (string) ( $fixture['job_id'] ?? '' ) ) : '';
$post_id = is_array( $fixture ) ? absint( $fixture['post_id'] ?? 0 ) : 0;
if ( '' === $job_id || $post_id < 1 ) {
	throw new RuntimeException( 'The migration Cron fixture is unavailable.' );
}

$loops = 0;
do {
	do_action( ERANKLY_MIGRATION_CRON_HOOK, $job_id );
	$active = erankly_migration_job_runner()->active_job();
	if ( is_array( $active ) && 'paused' === (string) ( $active['status'] ?? '' ) ) {
		throw new RuntimeException( 'The fresh Cron worker paused with ' . sanitize_text_field( (string) ( $active['last_error'] ?? 'unknown error' ) ) . '.' );
	}
	if ( ++$loops > 50 ) {
		throw new RuntimeException( 'The fresh Cron worker did not reach a terminal state.' );
	}
} while ( is_array( $active ) );

$report = erankly_migration_manager()->get_report( $job_id );
if ( ! is_array( $report ) || ! in_array( (string) ( $report['status'] ?? '' ), array( 'complete', 'partial' ), true ) ) {
	throw new RuntimeException( 'The fresh Cron worker did not persist a terminal report.' );
}
if ( 'Cron {{post_title}}' !== (string) get_post_meta( $post_id, '_erankly_title', true ) ) {
	throw new RuntimeException( 'The fresh Cron worker did not apply the migrated value.' );
}

delete_option( 'erankly_migration_cron_test_fixture' );
delete_option( 'wpseo_version' );
wp_delete_post( $post_id, true );

WP_CLI::success( 'Fresh-request migration Cron worker integration passed.' );
