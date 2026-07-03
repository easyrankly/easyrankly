<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Removes per-site options, the redirect table, post meta and transients for one site.
 *
 * Global settings (erankly_settings, erankly_version) are handled separately
 * because on Multisite they are stored as network options and must be deleted once.
 *
 * @return void
 */
function erankly_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );

	delete_option( 'erankly_redirects_db_version' );
	delete_option( 'erankly_redirects_runtime_rules' );
	delete_option( 'erankly_flush_rewrite_rules' );
	delete_option( 'erankly_sitemap_cache_version' );
	delete_option( 'erankly_health_404_candidates' );
	delete_option( 'erankly_health_404_frequent' );
	delete_option( 'erankly_health_thin_content' );

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_redirects' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup removes the plugin-owned redirects table.

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes plugin-owned post meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes plugin-owned term meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes plugin-owned sitemap transients.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_transient_erankly_sitemap_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_erankly_sitemap_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	// Global settings are stored as network options — delete once for the entire network.
	delete_site_option( 'erankly_settings' );
	delete_site_option( 'erankly_version' );
	delete_site_option( 'erankly_setup_wizard_status' );

	// Multilingual module data: network options and the network-wide relations table.
	delete_site_option( 'erankly_ml_sites' );
	delete_site_option( 'erankly_ml_db_version' );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . 'erankly_ml_relations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup removes the plugin-owned multilingual relations table.

	// Per-site data must be cleaned up on every site.
	$erankly_site_ids = get_sites(
		array(
			'fields' => 'ids',
		)
	);

	foreach ( $erankly_site_ids as $erankly_site_id ) {
		switch_to_blog( (int) $erankly_site_id );
		erankly_uninstall_site();
		restore_current_blog();
	}
} else {
	delete_option( 'erankly_settings' );
	delete_option( 'erankly_version' );
	delete_option( 'erankly_setup_wizard_status' );
	erankly_uninstall_site();
}
