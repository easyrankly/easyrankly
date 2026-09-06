<?php
/** Plugin uninstall cleanup. */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/helpers/redirect-cache.php';
require_once __DIR__ . '/includes/migrations/class-erankly-migration-upload-store.php';
require_once __DIR__ . '/includes/migrations/legacy-cleanup.php';
require_once __DIR__ . '/includes/class-erankly-import-job-runner.php';

global $wpdb;

/**
 * Removes per-site options, the redirect table, post meta and transients for one site. Global settings
 * (erankly_settings, erankly_version) are handled separately because on Multisite they are stored as network
 * options and must be deleted once.
 *
 * @throws RuntimeException When a database cleanup operation fails.
 */
function erankly_uninstall_site(): void {
	global $wpdb;
	if ( ! ERankly_Import_Job_Runner::purge_all() ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private import uploads during uninstall.', 'easyrankly' ) );
	}
	if ( ! ERankly_Migration_Upload_Store::purge_all( true ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private migration uploads during uninstall.', 'easyrankly' ) );
	}
	if ( ! erankly_migration_purge_legacy_state( true ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove retired migration state during uninstall.', 'easyrankly' ) );
	}

	wp_unschedule_hook( 'erankly_network_reset_batch' );
	wp_unschedule_hook( 'erankly_migration_process_batch' );
	wp_unschedule_hook( 'erankly_import_process_batch' );
	$erankly_active_migration = get_option( 'erankly_migration_active_job_v1', array() );
	if ( is_array( $erankly_active_migration ) && ! empty( $erankly_active_migration['id'] ) ) {
		delete_option( 'erankly_migration_lock_' . substr( hash( 'sha256', (string) $erankly_active_migration['id'] ), 0, 24 ) );
		delete_option( 'erankly_migration_cancel_' . substr( hash( 'sha256', (string) $erankly_active_migration['id'] ), 0, 24 ) );
	}
	$erankly_active_import = get_option( 'erankly_import_active_job_v1', array() );
	if ( is_array( $erankly_active_import ) && ! empty( $erankly_active_import['id'] ) ) {
		delete_option( 'erankly_import_lock_' . substr( hash( 'sha256', (string) $erankly_active_import['id'] ), 0, 24 ) );
	}
	delete_option( 'erankly_data_transfer_start_lock_v1' );
	delete_option( 'erankly_special_meta' );
	delete_option( 'erankly_runtime_state' );
	delete_option( 'erankly_redirects_db_version' );
	$erankly_redirect_prefix_options = get_option( 'erankly_redirects_runtime_rules_prefix_index', array() );
	foreach ( is_array( $erankly_redirect_prefix_options ) ? $erankly_redirect_prefix_options : array() as $erankly_redirect_prefix_option ) {
		if ( is_string( $erankly_redirect_prefix_option ) && 1 === preg_match( '/^erankly_redirects_runtime_rules_prefix_[a-f0-9]{24}$/', $erankly_redirect_prefix_option ) ) {
			delete_option( $erankly_redirect_prefix_option );
		}
	}
	delete_option( 'erankly_redirects_runtime_rules_global' );
	delete_option( 'erankly_redirects_runtime_rules_all' );
	delete_option( 'erankly_redirects_runtime_rules_prefix_index' );
	delete_option( 'erankly_redirects_runtime_rules' );
	delete_option( 'erankly_redirects_cache_generation' );
	delete_option( 'erankly_flush_rewrite_rules' );
	delete_option( 'erankly_rewrite_signature' );
	delete_option( 'rewrite_rules' );
	delete_option( 'erankly_sitemap_cache_version' );
	delete_option( 'erankly_migration_reports_v1' );
	delete_option( 'erankly_migration_active_job_v1' );
	delete_option( 'erankly_import_active_job_v1' );
	delete_option( 'erankly_import_last_result_v1' );
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

	$erankly_deleted_user_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes plugin-owned user meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $erankly_deleted_user_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove user metadata during uninstall.', 'easyrankly' ) );
	}

	$erankly_deleted_transients = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes core-owned transients (sitemap caches, visibility caches, redirect-cache flags).
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_transient_erankly_sitemap_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_erankly_sitemap_' ) . '%',
			$wpdb->esc_like( '_transient_erankly_visibility_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_erankly_visibility_' ) . '%',
			'_transient_erankly_redirect_cache_rotation_failed',
			'_transient_timeout_erankly_redirect_cache_rotation_failed'
		)
	);

	if ( false === $erankly_deleted_transients ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove transient data during uninstall.', 'easyrankly' ) );
	}
}

try {
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
			'erankly_rewrite_generation',
			'erankly_network_reset_job',
			'erankly_settings_lock_v1',
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

	} else {
		delete_option( 'erankly_settings' );
		delete_option( 'erankly_version' );
		delete_option( 'erankly_rewrite_generation' );
		delete_option( 'erankly_settings_lock_v1' );
		erankly_uninstall_site();
	}
} catch ( Throwable $erankly_uninstall_error ) {
	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Uninstall has no persistent plugin logger; server logs retain the actionable internal failure.
		sprintf(
			'EasyRankly uninstall failed (%1$s): %2$s',
			get_class( $erankly_uninstall_error ),
			sanitize_text_field( $erankly_uninstall_error->getMessage() )
		)
	);

	$erankly_public_error = esc_html__( 'EasyRankly could not complete its data cleanup. No further cleanup steps were attempted. Check the server error log, resolve the reported storage problem, and retry the uninstall.', 'easyrankly' );
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $erankly_public_error );
	}

	wp_die(
		esc_html( $erankly_public_error ),
		esc_html__( 'EasyRankly uninstall incomplete', 'easyrankly' ),
		array(
			'response'  => 500,
			'back_link' => true,
		)
	);
}
