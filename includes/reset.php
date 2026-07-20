<?php
/**
 * Reset module.
 *
 * Restores EasyRankly to a clean state without deactivating the plugin: wipes
 * settings, redirects, and post/term/special-page metadata. On Multisite a
 * Network Admin gets two scopes: a local reset for the primary site's own
 * content, and a global reset that also sweeps every site on the network.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Reset is a contextual module, but every reset scope needs the complete
// dynamic defaults model to rebuild the option atomically.
erankly_load_default_helpers();
require_once ERANKLY_PATH . 'includes/helpers/redirect-cache.php';

/**
 * Returns the settings page URL for the Settings tab (where the Reset box lives).
 *
 * @return string
 */
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

/**
 * Dispatches reset form submissions on the settings page.
 *
 * @return void
 */
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

	if ( 'reset_local' === $action ) {
		check_admin_referer( 'erankly_reset_local' );

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
		check_admin_referer( 'erankly_reset_global' );

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

/**
 * Redirects back to the Settings tab with a notice argument.
 *
 * @param array<string,mixed> $args Query args.
 * @return void
 */
function erankly_reset_redirect( array $args ): void {
	wp_safe_redirect( add_query_arg( $args, erankly_reset_url() ) );
	exit;
}

/**
 * Wipes the current site's own EasyRankly data: redirects, post/term meta,
 * special-page metadata, Health data, and sitemap caches.
 *
 * On single-site this also resets the plugin settings themselves, since those
 * are stored per site there. On Multisite the settings option is network-wide,
 * so a per-site reset must leave it untouched. erankly_reset_network()
 * handles that scope separately.
 *
 * @return void
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
	require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';
	if ( ! ERankly_Migration_Upload_Store::purge_all() ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private migration uploads during reset.', 'easyrankly' ) );
	}
	if ( ! ERankly_Import_Job_Runner::purge_all() ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private import uploads during reset.', 'easyrankly' ) );
	}

	wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );
	wp_unschedule_hook( ERANKLY_MIGRATION_CRON_HOOK );
	wp_unschedule_hook( ERANKLY_MIGRATION_ROLLBACK_CRON_HOOK );
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
	delete_option( 'erankly_health_404_candidates' );
	delete_option( 'erankly_health_404_frequent' );
	delete_option( 'erankly_health_404_states' );
	delete_option( 'erankly_health_404_storage_version' );
	delete_option( 'erankly_health_404_storage_lock' );
	delete_option( 'erankly_health_ai_suggestions' );
	delete_option( 'erankly_health_thin_content' );
	delete_option( 'erankly_health_bl_state' );
	delete_option( 'erankly_health_bl_queue' );
	delete_option( 'erankly_health_bl_visited' );
	delete_option( 'erankly_health_bl_links' );
	delete_option( 'erankly_health_bl_check_queue' );
	delete_option( 'erankly_health_bl_found' );
	delete_option( 'erankly_health_bl_results' );
	delete_option( 'erankly_lb_graph' );
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

	$deleted_transients = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes all plugin-owned transients (sitemap caches, Health 404 suggestion caches).
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_transient_erankly_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_erankly_' ) . '%'
		)
	);

	if ( false === $deleted_transients ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove transient data during reset.', 'easyrankly' ) );
	}

	if ( ! is_multisite() ) {
		erankly_update_plugin_option( ERANKLY_OPTION, erankly_default_settings() );
		// 'pending' is the literal value the first-run wizard checks for (see
		// erankly_setup_wizard_maybe_redirect()); deleting the option instead
		// would leave the wizard dismissed, but a clean install must re-run it.
		erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'pending' );
	}
}

/**
 * Deletes one bounded batch of multilingual relations for the current network.
 *
 * @param int $after_relation_id Return rows whose ID is greater than this cursor.
 * @param int $limit             Maximum rows to delete in this batch.
 * @return array{last_processed_id:int,has_more:bool}
 * @throws RuntimeException When multilingual storage cannot be inspected or updated.
 */
function erankly_reset_network_relations_batch( int $after_relation_id = 0, int $limit = 1000 ): array {
	global $wpdb;

	$after_relation_id = max( 0, $after_relation_id );
	$limit             = max( 1, $limit );
	$ml_table          = $wpdb->base_prefix . 'erankly_ml_relations';
	$exists            = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The optional multilingual table may not have been created.
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ml_table ) )
	);

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not inspect multilingual storage during reset.', 'easyrankly' ) );
	}

	if ( $ml_table !== $exists ) {
		return array(
			'last_processed_id' => $after_relation_id,
			'has_more'          => false,
		);
	}

	$relation_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset query keeps network reset resumable.
		$wpdb->prepare(
			'SELECT relations.id FROM %i AS relations INNER JOIN %i AS blogs ON blogs.blog_id = relations.blog_id WHERE blogs.site_id = %d AND relations.id > %d ORDER BY relations.id ASC LIMIT %d',
			$ml_table,
			$wpdb->blogs,
			(int) get_current_network_id(),
			$after_relation_id,
			$limit + 1
		)
	);

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not retrieve multilingual relations during reset.', 'easyrankly' ) );
	}

	$relation_ids = array_map( 'intval', (array) $relation_ids );
	$has_more     = count( $relation_ids ) > $limit;

	if ( $has_more ) {
		array_pop( $relation_ids );
	}

	if ( $relation_ids ) {
		$placeholders = implode( ', ', array_fill( 0, count( $relation_ids ), '%d' ) );
		$sql          = "DELETE FROM %i WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list is generated internally; values are prepared below.
		$args         = array_merge( array( $ml_table ), $relation_ids );
		$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic placeholder count is fully prepared above.

		if ( $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not delete multilingual relations during reset.', 'easyrankly' ) );
		}

		$after_relation_id = (int) end( $relation_ids );
	}

	return array(
		'last_processed_id' => $after_relation_id,
		'has_more'          => $has_more,
	);
}

/**
 * Rotates the namespace used by multilingual object-cache entries.
 *
 * Versioning makes a network reset independent of object-cache drop-in group
 * flush support. Old entries become unreachable immediately and retain their
 * existing one-hour expiry, so no installation-wide cache flush is required.
 *
 * @return void
 * @throws RuntimeException When the new cache generation cannot be persisted.
 */
function erankly_rotate_multilingual_cache_generation(): void {
	$generation = wp_generate_uuid4();

	update_site_option( ERANKLY_ML_CACHE_GENERATION_OPTION, $generation );

	if ( (string) get_site_option( ERANKLY_ML_CACHE_GENERATION_OPTION, '' ) !== $generation ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not invalidate multilingual caches during reset.', 'easyrankly' ) );
	}
}

/**
 * Resets the current network's shared settings and multilingual state.
 *
 * This is the first resumable worker phase, not part of the form submission.
 * Every operation is idempotent and verified so a transient failure can be
 * retried without losing the reset job that records its status.
 *
 * @return void
 * @throws RuntimeException When shared reset state cannot be persisted.
 */
function erankly_reset_network_shared_data(): void {
	$default_settings = erankly_default_settings();

	erankly_update_plugin_option( ERANKLY_OPTION, $default_settings );

	if ( erankly_get_plugin_option( ERANKLY_OPTION, false ) !== $default_settings ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not reset the network settings.', 'easyrankly' ) );
	}

	// 'pending' re-arms the first-run wizard; deleting the option would not
	// (erankly_setup_wizard_maybe_redirect() checks for the literal 'pending').
	erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'pending' );

	if ( 'pending' !== erankly_get_plugin_option( ERANKLY_SETUP_STATUS_OPTION, '' ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not reset the network setup status.', 'easyrankly' ) );
	}

	$missing = 'erankly-missing-' . wp_generate_uuid4();

	foreach ( array( 'erankly_ml_sites', 'erankly_ml_db_version' ) as $option_name ) {
		delete_site_option( $option_name );

		if ( get_site_option( $option_name, $missing ) !== $missing ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not reset the multilingual network settings.', 'easyrankly' ) );
		}
	}

	erankly_rotate_multilingual_cache_generation();
}

/**
 * Queues a resumable reset for the current network.
 *
 * No plugin data is mutated until the job has been stored and scheduled. The
 * worker then resets shared state, multilingual relations, and per-site data in
 * bounded, retryable phases.
 *
 * @return bool Whether the background cleanup was queued.
 */
function erankly_reset_network(): bool {
	require_once ERANKLY_PATH . 'includes/network-reset.php';

	return erankly_queue_network_reset();
}

/**
 * Renders the Reset card shown on the Settings tab, after Preferences.
 *
 * @return void
 */
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
	<div class="erankly-settings-section">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Reset', 'easyrankly' ); ?></h3>
		<section class="erankly-io-section erankly-card">
			<p class="description"><?php esc_html_e( 'Restore EasyRankly to a clean install. This permanently deletes settings, redirects and SEO metadata; export a backup first if you want to keep a copy.', 'easyrankly' ); ?></p>
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

/**
 * Renders the reset admin notice for the current request.
 *
 * @return void
 */
function erankly_reset_render_notice(): void {
	$notice = isset( $_GET['erankly_reset_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_reset_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.

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
