<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/helpers/redirect-cache.php';

global $wpdb;

/**
 * Removes per-site options, the redirect table, post meta and transients for one site.
 *
 * Global settings (erankly_settings, erankly_version) are handled separately
 * because on Multisite they are stored as network options and must be deleted once.
 *
 * @return void
 * @throws RuntimeException When a database cleanup operation fails.
 */
function erankly_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );
	wp_unschedule_hook( 'erankly_network_reset_batch' );

	delete_option( 'erankly_special_meta' );
	delete_option( 'erankly_redirects_db_version' );
	delete_option( 'erankly_redirects_runtime_rules' );
	delete_option( 'erankly_redirects_cache_generation' );
	delete_option( 'erankly_flush_rewrite_rules' );
	delete_option( 'erankly_rewrite_signature' );
	delete_option( 'rewrite_rules' );
	delete_option( 'erankly_sitemap_cache_version' );
	delete_option( 'erankly_health_404_candidates' );
	delete_option( 'erankly_health_404_frequent' );
	delete_option( 'erankly_health_404_states' );
	delete_option( 'erankly_health_thin_content' );
	delete_option( 'erankly_health_bl_state' );
	delete_option( 'erankly_health_bl_results' );
	delete_option( 'erankly_lb_graph' );

	$erankly_dropped_redirects = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup removes the plugin-owned redirects table.
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_redirects' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall intentionally drops plugin-owned storage.
	);

	if ( false === $erankly_dropped_redirects ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove redirect storage during uninstall.', 'easyrankly' ) );
	}

	erankly_redirects_flush_external_caches();

	$erankly_deleted_post_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes plugin-owned post meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $erankly_deleted_post_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove post metadata during uninstall.', 'easyrankly' ) );
	}

	$erankly_deleted_term_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes plugin-owned term meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $erankly_deleted_term_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove term metadata during uninstall.', 'easyrankly' ) );
	}

	$erankly_deleted_transients = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes all plugin-owned transients (sitemap caches, Health 404 suggestion caches).
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_transient_erankly_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_erankly_' ) . '%'
		)
	);

	if ( false === $erankly_deleted_transients ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove transient data during uninstall.', 'easyrankly' ) );
	}
}

if ( is_multisite() ) {
	$erankly_site_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count is needed before any destructive work starts.
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->blogs )
	);

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not count multisite sites before uninstall.', 'easyrankly' ) );
	}

	// Plugin files disappear as soon as uninstall returns, so a large cleanup
	// cannot continue through WP-Cron. Refuse the unsafe web path before deleting
	// anything and direct the administrator to the timeout-free WP-CLI lifecycle.
	if (
		(int) $erankly_site_count > max( 1, (int) apply_filters( 'erankly_network_web_lifecycle_limit', 100 ) )
		&& ! ( defined( 'WP_CLI' ) && WP_CLI )
	) {
		$erankly_plugin_slug = basename( __DIR__ );
		$erankly_command     = sprintf( 'wp plugin uninstall %s', $erankly_plugin_slug );
		$erankly_message     = '<p>' . esc_html__( 'This installation is too large to uninstall EasyRankly safely in one web request.', 'easyrankly' ) . '</p>';
		$erankly_message    .= '<p>' . esc_html__( 'Run the following WP-CLI command so cleanup can finish without an HTTP timeout:', 'easyrankly' ) . '</p>';
		$erankly_message    .= '<p><code>' . esc_html( $erankly_command ) . '</code></p>';

		wp_die(
			$erankly_message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Message markup contains only escaped translated text and command output.
			esc_html__( 'EasyRankly network cleanup required', 'easyrankly' ),
			array(
				'response'  => 409,
				'back_link' => true,
			)
		);
	}

	// Per-site cleanup is necessarily synchronous during uninstall because the
	// plugin code will no longer be available to a future Cron request. This query
	// intentionally includes every network because plugin deletion removes the
	// shared files installation-wide. Large installations are routed to WP-CLI
	// above; keyset pagination keeps memory bounded there.
	$erankly_last_site_id = 0;
	$erankly_batch_size   = 100;

	do {
		$erankly_site_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset pagination is required for complete network uninstall cleanup.
			$wpdb->prepare(
				'SELECT blog_id FROM %i WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d',
				$wpdb->blogs,
				$erankly_last_site_id,
				$erankly_batch_size
			)
		);

		if ( $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not retrieve the next network site batch during uninstall.', 'easyrankly' ) );
		}

		$erankly_site_ids   = array_map( 'intval', (array) $erankly_site_ids );
		$erankly_site_count = count( $erankly_site_ids );

		foreach ( $erankly_site_ids as $erankly_site_id ) {
			switch_to_blog( $erankly_site_id );

			try {
				erankly_uninstall_site();
			} finally {
				restore_current_blog();
			}
		}

		if ( $erankly_site_ids ) {
			$erankly_last_site_id = (int) end( $erankly_site_ids );
		}
	} while ( $erankly_batch_size === $erankly_site_count );

	// Removing plugin files is installation-wide, not scoped to the Network Admin
	// that initiated it. Delete network settings for every network only after the
	// per-site sweep has completed, so a failed enumeration cannot erase settings
	// while leaving the rest of the uninstall unfinished.
	$erankly_network_option_names = array(
		'erankly_settings',
		'erankly_version',
		'erankly_setup_wizard_status',
		'erankly_rewrite_generation',
		'erankly_ml_sites',
		'erankly_ml_db_version',
		'erankly_ml_cache_generation',
		'erankly_network_reset_job',
	);

	$erankly_last_network_id = 0;
	$erankly_network_batch   = 100;

	do {
		$erankly_network_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination keeps multi-network cleanup memory-bounded.
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$wpdb->site,
				$erankly_last_network_id,
				$erankly_network_batch
			)
		);

		if ( $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not retrieve networks during uninstall.', 'easyrankly' ) );
		}

		$erankly_network_ids   = array_map( 'intval', (array) $erankly_network_ids );
		$erankly_network_count = count( $erankly_network_ids );

		foreach ( $erankly_network_ids as $erankly_network_id ) {
			foreach ( $erankly_network_option_names as $erankly_network_option_name ) {
				delete_network_option( $erankly_network_id, $erankly_network_option_name );

				if ( $wpdb->last_error ) {
					throw new RuntimeException( esc_html__( 'EasyRankly could not remove network options during uninstall.', 'easyrankly' ) );
				}

				// delete_network_option() invalidates existing rows. Clear the key
				// explicitly as well so an already-stale persistent cache entry cannot
				// survive when the database row was missing.
				wp_cache_delete( $erankly_network_id . ':' . $erankly_network_option_name, 'site-options' );
			}
		}

		if ( $erankly_network_ids ) {
			$erankly_last_network_id = (int) end( $erankly_network_ids );
		}
	} while ( $erankly_network_batch === $erankly_network_count );

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . 'erankly_ml_relations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup removes the plugin-owned multilingual relations table.

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove multilingual storage during uninstall.', 'easyrankly' ) );
	}
} else {
	delete_option( 'erankly_settings' );
	delete_option( 'erankly_version' );
	delete_option( 'erankly_setup_wizard_status' );
	delete_option( 'erankly_rewrite_generation' );
	erankly_uninstall_site();
}
