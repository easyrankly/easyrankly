<?php
/**
 * Reset module. Restores EasyRankly to a clean state without deactivating the plugin: wipes settings, redirects,
 * and post/term/special-page metadata. On Multisite a Network Admin gets two scopes: a local reset for the
 * primary site's own content, and a global reset that also sweeps every site on the network.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Reset is a contextual module, but every reset scope needs the complete
// dynamic defaults model to rebuild the option atomically.
erankly_load_default_helpers();
require_once ERANKLY_PATH . 'includes/helpers/redirect-cache.php';

/** Returns the settings page URL for the Settings tab (where the Reset box lives). */
function erankly_reset_url(): string {
	$base = is_network_admin()
		? network_admin_url( 'settings.php' )
		: admin_url( 'options-general.php' );

	return add_query_arg(
		array(
			'page'        => 'erankly',
			'erankly_tab' => 'settings',
		),
		$base
	);
}

/** Dispatches reset form submissions on the settings page. */
function erankly_reset_handle_actions(): void {
	// On Multisite the settings option is a network option; gate write access accordingly.
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( 'erankly' !== $page || ! isset( $_POST['erankly_reset_action'] ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['erankly_reset_action'] ) );
	if ( '' === $action ) {
		return;
	}

	// Core and add-ons share the same action-scoped nonce contract. Verify it
	// before notifying extensions so an unauthenticated request cannot trigger
	// work merely by choosing a custom action slug.
	check_admin_referer( 'erankly_' . $action );

	/**
 * Handles authenticated add-on reset actions before core's built-in paths. Extensions must render a nonce for
 * the `erankly_{$action}` action.
 *
 * @param string $action Authenticated reset action slug.
 */
	do_action( 'erankly_reset_action', $action );

	if ( 'reset_local' === $action ) {
		try {
			erankly_reset_site_data();
			erankly_reset_redirect( array( 'erankly_reset_notice' => 'local' ) );
		} catch ( Throwable ) {
			erankly_reset_redirect( array( 'erankly_reset_notice' => 'local_failed' ) );
		}
	}

	// The network-wide reset is only ever reachable from Network Admin. A
	// per-site admin on Multisite never sees this button (see erankly_reset_render_panel()).
	if ( 'reset_global' === $action && is_multisite() && is_network_admin() ) {
		try {
			$queued = erankly_reset_network();
		} catch ( Throwable ) {
			$queued = false;
		}

		erankly_reset_redirect(
			array( 'erankly_reset_notice' => $queued ? 'global_queued' : 'global_failed' )
		);
	}
}

function erankly_reset_redirect( array $args ): void {
	wp_safe_redirect( add_query_arg( $args, erankly_reset_url() ) );
	exit;
}

/**
 * Abandons any settings mutex lease before a destructive reset rewrite. Reset already requires an exclusive
 * capability check and may have deleted plugin data before restoring defaults. A leftover lease from a crashed
 * or concurrent settings save must not block that final write.
 */
function erankly_reset_abandon_settings_lock(): void {
	if ( ! defined( 'ERANKLY_SETTINGS_LOCK_OPTION' ) ) {
		return;
	}

	if ( is_multisite() ) {
		delete_network_option( get_current_network_id(), ERANKLY_SETTINGS_LOCK_OPTION );
		return;
	}

	delete_option( ERANKLY_SETTINGS_LOCK_OPTION );
}

/**
 * Wipes the current site's own EasyRankly data: redirects, post/term meta, special-page metadata, and sitemap
 * caches. On single-site this also resets the plugin settings themselves, since those are stored per site there.
 * On Multisite the settings option is network-wide, so a per-site reset must leave it untouched.
 * erankly_reset_network() handles that scope separately.
 *
 * @throws RuntimeException When a database cleanup operation fails.
 */
function erankly_reset_site_data(): void {
	global $wpdb;

	// Rotate before destructive work so stale positive and negative exact-match
	// caches become unreachable even if a later cleanup step fails.
	erankly_rotate_redirects_cache_generation();
	if ( ! class_exists( 'ERankly_Migration_Upload_Store' ) ) {
		require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-upload-store.php';
	}
	if ( ! class_exists( 'ERankly_Migration_Verification_Job' ) ) {
		require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-verification-job.php';
	}
	require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';
	if ( ! ERankly_Migration_Upload_Store::purge_all() ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private migration uploads during reset.', 'easyrankly' ) );
	}
	if ( ! ERankly_Import_Job_Runner::purge_all() ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private import uploads during reset.', 'easyrankly' ) );
	}
	if ( ! ERankly_Migration_Verification_Job::purge_all() ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove live-verification checkpoints during reset.', 'easyrankly' ) );
	}

	wp_unschedule_hook( ERANKLY_MIGRATION_CRON_HOOK );
	wp_unschedule_hook( ERANKLY_MIGRATION_ROLLBACK_CRON_HOOK );
	wp_unschedule_hook( ERANKLY_MIGRATION_VERIFY_CRON_HOOK );
	wp_unschedule_hook( ERANKLY_IMPORT_CRON_HOOK );
	$active_migration = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, array() );
	if ( is_array( $active_migration ) && ! empty( $active_migration['id'] ) ) {
		delete_option( 'erankly_migration_lock_' . substr( hash( 'sha256', (string) $active_migration['id'] ), 0, 24 ) );
		delete_option( 'erankly_migration_cancel_' . substr( hash( 'sha256', (string) $active_migration['id'] ), 0, 24 ) );
	}
	$active_import = get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, array() );
	if ( is_array( $active_import ) && ! empty( $active_import['id'] ) ) {
		delete_option( 'erankly_import_lock_' . substr( hash( 'sha256', (string) $active_import['id'] ), 0, 24 ) );
	}
	$migration_reports = get_option( 'erankly_migration_reports_v1', array() );
	foreach ( is_array( $migration_reports ) ? array_keys( $migration_reports ) : array() as $migration_report_id ) {
		$rollback_suffix = substr( hash( 'sha256', (string) $migration_report_id ), 0, 24 );
		delete_option( 'erankly_migration_rollback_' . $rollback_suffix );
		delete_option( 'erankly_migration_rollback_lock_' . $rollback_suffix );
	}

	delete_option( ERANKLY_SPECIAL_META_OPTION );
	delete_option( 'erankly_redirects_db_version' );
	$redirect_prefix_options = get_option( 'erankly_redirects_runtime_rules_prefix_index', array() );
	foreach ( is_array( $redirect_prefix_options ) ? $redirect_prefix_options : array() as $redirect_prefix_option ) {
		if ( is_string( $redirect_prefix_option ) && 1 === preg_match( '/^erankly_redirects_runtime_rules_prefix_[a-f0-9]{24}$/', $redirect_prefix_option ) ) {
			delete_option( $redirect_prefix_option );
		}
	}
	delete_option( 'erankly_redirects_runtime_rules_global' );
	delete_option( 'erankly_redirects_runtime_rules_all' );
	delete_option( 'erankly_redirects_runtime_rules_prefix_index' );
	delete_option( 'erankly_redirects_runtime_rules' );
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	delete_option( ERANKLY_REWRITE_SIGNATURE_OPTION );
	delete_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION );
	delete_option( 'erankly_migration_reports_v1' );
	delete_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION );
	delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
	delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );
	delete_option( 'erankly_migration_queue_db_version' );
	delete_option( 'erankly_migration_journal_db_version' );
	delete_option( 'erankly_migration_evidence_db_version' );

	$dropped_migration_queue = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset removes temporary migration staging storage.
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_migration_queue' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset intentionally drops plugin-owned staging storage.
	);
	if ( false === $dropped_migration_queue ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove migration staging storage during reset.', 'easyrankly' ) );
	}

	$dropped_migration_journal = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset removes plugin-owned rollback history.
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_migration_changes' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset intentionally drops plugin-owned rollback storage.
	);
	if ( false === $dropped_migration_journal ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove migration rollback storage during reset.', 'easyrankly' ) );
	}

	$dropped_migration_evidence = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset removes the complete migration exception ledger.
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_migration_exceptions' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset intentionally drops plugin-owned exception evidence.
	);
	if ( false === $dropped_migration_evidence ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove migration exception evidence during reset.', 'easyrankly' ) );
	}

	$dropped_redirects = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset removes the plugin-owned redirects table; it is recreated on demand next time the module boots.
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_redirects' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset intentionally drops plugin-owned storage.
	);

	if ( false === $dropped_redirects ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove redirect storage during reset.', 'easyrankly' ) );
	}

	erankly_redirects_flush_external_caches();

	$deleted_post_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes plugin-owned post meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $deleted_post_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove post metadata during reset.', 'easyrankly' ) );
	}

	$deleted_term_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes plugin-owned term meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $deleted_term_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove term metadata during reset.', 'easyrankly' ) );
	}

	$deleted_user_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes plugin-owned user meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $deleted_user_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove user metadata during reset.', 'easyrankly' ) );
	}

	$deleted_transients = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes core-owned transients (sitemap caches, visibility caches, redirect-cache flags).
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

	if ( false === $deleted_transients ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove transient data during reset.', 'easyrankly' ) );
	}

	if ( ! is_multisite() ) {
		// Reset is an exclusive admin action. Drop any abandoned settings lease so
		// restoring defaults cannot fail after the destructive cleanup already ran.
		erankly_reset_abandon_settings_lock();

		$result = erankly_update_plugin_settings( erankly_default_settings(), '', true );
		if ( is_wp_error( $result ) || ! $result ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not reset its settings.', 'easyrankly' ) );
		}
	}

	/** Fires after core has wiped this site's EasyRankly data. Add-ons should delete their own options, transients and cron events here. */
	do_action( 'erankly_reset_site_data' );
}

/**
 * Resets the current network's shared core settings. This is the first resumable worker phase, not part of the
 * form submission. Every operation is idempotent and verified so a transient failure can be retried without
 * losing the reset job that records its status.
 *
 * @throws RuntimeException When shared reset state cannot be persisted.
 */
function erankly_reset_network_shared_data(): void {
	$default_settings = erankly_default_settings();

	// Network reset is exclusive; clear an abandoned lease before rewriting shared settings.
	erankly_reset_abandon_settings_lock();

	$settings_reset = erankly_update_plugin_settings( $default_settings, '', true );
	if ( is_wp_error( $settings_reset ) || ! $settings_reset ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not reset the network settings.', 'easyrankly' ) );
	}

	$stored_settings = erankly_get_plugin_option( ERANKLY_OPTION, false );
	if ( ! is_array( $stored_settings ) || array_intersect_key( $stored_settings, $default_settings ) !== $default_settings ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not reset the network settings.', 'easyrankly' ) );
	}
}

/**
 * Queues a resumable reset for the current network. No plugin data is mutated until the job has been stored and
 * scheduled. The worker then resets shared state and per-site data in bounded, retryable phases.
 *
 * @return bool Whether the background cleanup was queued.
 */
function erankly_reset_network(): bool {
	require_once ERANKLY_PATH . 'includes/network-reset.php';

	return erankly_queue_network_reset();
}

/** Renders the Reset card shown on the Settings tab, after Preferences. */
function erankly_reset_render_panel(): void {
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	$action_url = erankly_reset_url();
	$is_network = is_multisite() && is_network_admin();

	$local_label   = $is_network ? __( 'Reset this site', 'easyrankly' ) : __( 'Reset plugin', 'easyrankly' );
	$title_local   = $is_network ? __( 'Reset this site?', 'easyrankly' ) : __( 'Reset EasyRankly?', 'easyrankly' );
	$confirm_local = $is_network
		? __( 'This will permanently delete this site\'s redirects, SEO metadata and special page defaults. This does not affect the network-wide settings or other sites. This action cannot be undone.', 'easyrankly' )
		: __( 'This will permanently delete all EasyRankly settings, redirects and SEO metadata on this site, and restore everything to their defaults. This action cannot be undone.', 'easyrankly' );
	?>
	<?php do_action( 'erankly_reset_panel' ); ?>

	<div class="erankly-settings-section">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Reset', 'easyrankly' ); ?></h3>
		<section class="erankly-card">
			<p class="description"><?php esc_html_e( 'This permanently deletes all settings, redirects, and SEO metadata. Back up first.', 'easyrankly' ); ?></p>
			<?php if ( $is_network ) : ?>
				<p class="description"><?php esc_html_e( 'Local reset cleans up only this Network Admin\'s primary site; network reset wipes the network-wide settings and every site.', 'easyrankly' ); ?></p>
			<?php endif; ?>
			<?php
			/*
			 * This panel is rendered inside the main settings <form> (options.php or
			 * the Network Admin save form), so these actions cannot be their own
			 * nested <form>. Browsers do not support nested forms and a button
			 * inside one would end up submitting the outer settings form instead.
			 * A confirmation modal opens first (see erankly-confirm-modal in
			 * admin.js); its own Delete button then assembles and submits a
			 * standalone form appended to <body>.
			 */
			?>
			<p class="erankly-reset-actions">
				<button
					type="button"
					class="button erankly-btn-danger erankly-reset-trigger"
					data-erankly-reset-url="<?php echo esc_url( $action_url ); ?>"
					data-erankly-reset-action="reset_local"
					data-erankly-reset-nonce="<?php echo esc_attr( wp_create_nonce( 'erankly_reset_local' ) ); ?>"
					data-erankly-reset-title="<?php echo esc_attr( $title_local ); ?>"
					data-erankly-reset-confirm="<?php echo esc_attr( $confirm_local ); ?>"
					data-erankly-reset-button="<?php echo esc_attr( $local_label ); ?>"
				><?php echo esc_html( $local_label ); ?></button>
				<?php if ( $is_network ) : ?>
					<?php
					$global_label   = __( 'Reset entire network', 'easyrankly' );
					$title_global   = __( 'Reset the entire network?', 'easyrankly' );
					$confirm_global = __( 'This will permanently delete the network-wide EasyRankly settings and every site\'s redirects, SEO metadata and special page defaults across the whole network. This action cannot be undone.', 'easyrankly' );
					?>
					<button
						type="button"
						class="button erankly-btn-danger erankly-reset-trigger"
						data-erankly-reset-url="<?php echo esc_url( $action_url ); ?>"
						data-erankly-reset-action="reset_global"
						data-erankly-reset-nonce="<?php echo esc_attr( wp_create_nonce( 'erankly_reset_global' ) ); ?>"
						data-erankly-reset-title="<?php echo esc_attr( $title_global ); ?>"
						data-erankly-reset-confirm="<?php echo esc_attr( $confirm_global ); ?>"
						data-erankly-reset-button="<?php echo esc_attr( $global_label ); ?>"
					><?php echo esc_html( $global_label ); ?></button>
				<?php endif; ?>
			</p>
		</section>
	</div>

	<div class="erankly-modal-overlay" data-erankly-reset-modal hidden>
		<div class="erankly-modal" role="alertdialog" aria-modal="true" aria-labelledby="erankly-reset-modal-title" aria-describedby="erankly-reset-modal-desc">
			<h2 id="erankly-reset-modal-title" class="erankly-modal-title" data-erankly-reset-modal-title></h2>
			<p id="erankly-reset-modal-desc" class="erankly-modal-desc" data-erankly-reset-modal-desc></p>
			<div class="erankly-modal-actions">
				<button type="button" class="button erankly-modal-cancel" data-erankly-reset-modal-cancel><?php esc_html_e( 'Cancel', 'easyrankly' ); ?></button>
				<button type="button" class="button erankly-btn-danger erankly-modal-confirm" data-erankly-reset-modal-confirm></button>
			</div>
		</div>
	</div>
	<?php
}

function erankly_reset_render_notice(): void {
	$notice = isset( $_GET['erankly_reset_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_reset_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.

	do_action( 'erankly_reset_notice', $notice );

	if ( 'local' === $notice ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'EasyRankly has been reset for this site.', 'easyrankly' ) . '</p></div>';
	}

	if ( 'local_failed' === $notice ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'EasyRankly could not complete the site reset because a database operation failed. Resolve the database issue, then run the reset again.', 'easyrankly' ) . '</p></div>';
	}

	if ( 'global_queued' === $notice ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The EasyRankly network reset has started and will continue in small background batches.', 'easyrankly' ) . '</p></div>';
	}

	if ( 'global_failed' === $notice ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'EasyRankly could not start the network reset, so no plugin data was changed. Resolve the database or Cron scheduling issue, then run the reset again.', 'easyrankly' ) . '</p></div>';
	}
}
