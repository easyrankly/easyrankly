<?php
/**
 * Reset module.
 *
 * Restores EasyRankly to a clean state without deactivating the plugin: wipes
 * settings, redirects, and post/term/special-page metadata. On Multisite a
 * Network Admin gets two scopes — a local reset for the primary site's own
 * content, and a global reset that also sweeps every site on the network.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		erankly_reset_site_data();
		erankly_reset_redirect( array( 'erankly_reset_notice' => 'local' ) );
	}

	// The network-wide reset is only ever reachable from Network Admin — a
	// per-site admin on Multisite never sees this button (see erankly_reset_render_panel()).
	if ( 'reset_global' === $action && is_multisite() && is_network_admin() ) {
		check_admin_referer( 'erankly_reset_global' );
		erankly_reset_network();
		erankly_reset_redirect( array( 'erankly_reset_notice' => 'global' ) );
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
 * so a per-site reset must leave it untouched — erankly_reset_network()
 * handles that scope separately.
 *
 * @return void
 */
function erankly_reset_site_data(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'erankly_health_prune_404_cron' );

	delete_option( ERANKLY_SPECIAL_META_OPTION );
	delete_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION );
	delete_option( 'erankly_redirects_runtime_rules' );
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	delete_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION );
	delete_option( 'erankly_health_404_candidates' );
	delete_option( 'erankly_health_404_frequent' );
	delete_option( 'erankly_health_404_states' );
	delete_option( 'erankly_health_thin_content' );
	delete_option( 'erankly_health_bl_state' );
	delete_option( 'erankly_health_bl_results' );

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_redirects' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset removes the plugin-owned redirects table; it is recreated on demand next time the module boots.

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes plugin-owned post meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes plugin-owned term meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reset removes all plugin-owned transients (sitemap caches, Health 404 suggestion caches).
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_transient_erankly_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_erankly_' ) . '%'
		)
	);

	if ( ! is_multisite() ) {
		erankly_update_plugin_option( ERANKLY_OPTION, erankly_default_settings() );
		// 'pending' is the literal value the first-run wizard checks for (see
		// erankly_setup_wizard_maybe_redirect()); deleting the option instead
		// would leave the wizard dismissed, but a clean install must re-run it.
		erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'pending' );
	}
}

/**
 * Wipes the network-wide global settings plus every site's local data.
 *
 * Multisite only. Resets the shared settings option to defaults, clears the
 * multilingual module's network-wide state, then sweeps every site with
 * erankly_reset_site_data().
 *
 * @return void
 */
function erankly_reset_network(): void {
	global $wpdb;

	erankly_update_plugin_option( ERANKLY_OPTION, erankly_default_settings() );
	// 'pending' re-arms the first-run wizard; deleting the option would not
	// (erankly_setup_wizard_maybe_redirect() checks for the literal 'pending').
	erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'pending' );

	delete_site_option( 'erankly_ml_sites' );
	delete_site_option( 'erankly_ml_db_version' );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . 'erankly_ml_relations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reset removes the plugin-owned multilingual relations table; it is recreated on demand.

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		erankly_reset_site_data();
		restore_current_blog();
	}
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
			 * nested <form> — browsers do not support nested forms and a button
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

	if ( 'global' === $notice ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'EasyRankly has been reset across the entire network.', 'easyrankly' ) . '</p></div>';
	}
}
