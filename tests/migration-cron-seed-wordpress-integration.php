<?php
// phpcs:ignoreFile -- WP-CLI integration harness mutates an ephemeral test site.
/**
 * Seeds a migration job that a separate WordPress request must process.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

$included = array_map( 'wp_normalize_path', get_included_files() );
if ( in_array( wp_normalize_path( ERANKLY_PATH . 'includes/import-export.php' ), $included, true ) ) {
	throw new RuntimeException( 'The seed request must not preload Import / Export.' );
}

require_once ERANKLY_PATH . 'includes/migrations.php';

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Cron worker dependency fixture',
		'post_content' => 'Worker fixture.',
		'post_status'  => 'publish',
	)
);
if ( is_wp_error( $post_id ) || $post_id < 1 ) {
	throw new RuntimeException( 'The Cron worker fixture post could not be created.' );
}

update_option( 'wpseo_version', '28.0.0', false );
update_post_meta( (int) $post_id, '_yoast_wpseo_title', 'Cron %%title%%' );

$started = erankly_migration_job_runner()->start( 'yoast', false );
if ( empty( $started['ok'] ) || empty( $started['job']['id'] ) ) {
	throw new RuntimeException( 'The Cron worker fixture job could not be queued: ' . sanitize_key( (string) ( $started['error'] ?? 'unknown' ) ) );
}

update_option(
	'erankly_migration_cron_test_fixture',
	array(
		'job_id'  => (string) $started['job']['id'],
		'post_id' => (int) $post_id,
	),
	false
);

WP_CLI::success( 'Migration Cron fixture queued for a fresh request.' );
