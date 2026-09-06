<?php
/** Migration report and progress renderers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Formats a stored UTC timestamp in the site's locale. */
function erankly_migration_format_datetime( string $value ): string {
	$timestamp = strtotime( $value );
	if ( false === $timestamp ) {
		return sanitize_text_field( $value );
	}

	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Returns the headline, instruction and body for one migration state.
 *
 * @return array{title:string,instruction:string,body:string}
 */
function erankly_migration_guided_copy( array $ui ): array {
	$state  = sanitize_key( (string) ( $ui['state'] ?? 'blocked' ) );
	$source = sanitize_text_field( (string) ( $ui['active_owner_label'] ?? $ui['source_label'] ?? '' ) );

	$copy = array(
		'preview_ready'   => array(
			__( 'The preview is ready', 'easyrankly' ),
			__( 'Review the counts below, then run the import.', 'easyrankly' ),
			__( 'Nothing has been written yet. The import writes only where EasyRankly is currently empty.', 'easyrankly' ),
		),
		'preview_blocked' => array(
			__( 'The preview found problems', 'easyrankly' ),
			__( 'Resolve the items that need attention, then preview again.', 'easyrankly' ),
			__( 'Nothing has been written. Your data is unchanged.', 'easyrankly' ),
		),
		'blocked'         => array(
			__( 'The import did not finish cleanly', 'easyrankly' ),
			__( 'Review the problems below before deactivating the old plugin.', 'easyrankly' ),
			__( 'Keep the previous SEO plugin active until this is resolved. The pre-import backup can restore the previous state.', 'easyrankly' ),
		),
		'needs_review'    => array(
			__( 'Imported, with items to review', 'easyrankly' ),
			__( 'Check the values EasyRankly preserved, then deactivate the old plugin.', 'easyrankly' ),
			__( 'Every field EasyRankly already had was kept as it was. Those are listed below.', 'easyrankly' ),
		),
		'source_active'   => array(
			__( 'Import complete', 'easyrankly' ),
			/* translators: %s: source plugin name. */
			sprintf( __( 'Deactivate %s so EasyRankly can output your SEO tags.', 'easyrankly' ), $source ),
			__( 'Two SEO plugins are writing the same tags right now. Deactivate the old one, then reload this page.', 'easyrankly' ),
		),
		'complete'        => array(
			__( 'Migration complete', 'easyrankly' ),
			__( 'EasyRankly now owns your SEO output.', 'easyrankly' ),
			__( 'Visit a few pages and check the titles and descriptions. The pre-import backup stays available for a while in case you need to go back.', 'easyrankly' ),
		),
	)[ $state ] ?? array(
		__( 'Migration report', 'easyrankly' ),
		__( 'Review the details below.', 'easyrankly' ),
		'',
	);

	return array(
		'title'       => $copy[0],
		'instruction' => $copy[1],
		'body'        => $copy[2],
	);
}

/** Renders the three-step progress indicator. */
function erankly_migration_render_steps( array $ui ): void {
	$state    = sanitize_key( (string) ( $ui['state'] ?? '' ) );
	$step     = max( 1, min( 3, absint( $ui['step'] ?? 1 ) ) );
	$finished = 'complete' === $state;
	$labels   = array(
		1 => __( 'Import data', 'easyrankly' ),
		2 => __( 'Deactivate old plugin', 'easyrankly' ),
		3 => __( 'Verify site', 'easyrankly' ),
	);
	?>
	<ol class="erankly-migration-steps" aria-label="<?php esc_attr_e( 'Migration progress', 'easyrankly' ); ?>">
		<?php foreach ( $labels as $index => $label ) : ?>
			<?php
			$is_complete = $index < $step || ( $finished && 3 === $index );
			$is_current  = $index === $step && ! $finished;
			$classes     = $is_complete ? ' is-complete' : ( $is_current ? ' is-current' : '' );
			?>
			<li class="erankly-migration-step<?php echo esc_attr( $classes ); ?>"<?php echo $is_current ? ' aria-current="step"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute. ?>>
				<span class="erankly-migration-step-marker" aria-hidden="true"><?php echo $is_complete ? '&#10003;' : esc_html( (string) $index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed checkmark entity or escaped integer. ?></span>
				<span><?php echo esc_html( $label ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
}

/** Renders the single primary action for the current migration state. */
function erankly_migration_render_guided_action( array $ui, array $report ): void {
	$action      = sanitize_key( (string) ( $ui['primary_action'] ?? '' ) );
	$report_id   = sanitize_text_field( (string) ( $report['id'] ?? '' ) );
	$source      = sanitize_key( (string) ( $report['source'] ?? '' ) );
	$source_name = sanitize_text_field( (string) ( $ui['active_owner_label'] ?? $ui['source_label'] ?? '' ) );
	?>
	<div class="erankly-migration-primary-action">
		<?php if ( 'open_plugins' === $action ) : ?>
			<?php
			$plugins_url = add_query_arg(
				array(
					'plugin_status' => 'active',
					's'             => $source_name,
				),
				is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' )
			);
			?>
			<a class="button button-primary" href="<?php echo esc_url( $plugins_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Plugins in a new tab', 'easyrankly' ); ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'report_id', $report_id, erankly_import_export_url() ) ); ?>"><?php esc_html_e( 'I deactivated it. Check again', 'easyrankly' ); ?></a>
		<?php elseif ( 'run_import' === $action && ! empty( $ui['can_run_import'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Import the reviewed data now? Existing EasyRankly values will still be preserved.', 'easyrankly' ) ); ?>');">
				<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
				<input type="hidden" name="erankly_io_action" value="migrate">
				<input type="hidden" name="erankly_migration_source" value="<?php echo esc_attr( $source ); ?>">
				<input type="hidden" name="erankly_migration_mode" value="import">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import the reviewed data', 'easyrankly' ); ?></button>
			</form>
		<?php elseif ( 'review_issues' === $action ) : ?>
			<a class="button button-primary" href="#erankly-migration-attention"><?php esc_html_e( 'Review items that need attention', 'easyrankly' ); ?></a>
		<?php elseif ( 'open_settings' === $action ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'erankly_tab', 'general', erankly_import_export_url() ) ); ?>"><?php esc_html_e( 'Go to EasyRankly settings', 'easyrankly' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
}

/** Renders the blocking warnings and preserved values that need a decision. */
function erankly_migration_render_attention( array $ui, array $report ): void {
	if ( absint( $ui['problem_count'] ?? 0 ) < 1 ) {
		return;
	}

	$warnings = array_values(
		array_filter(
			is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array(),
			static fn( mixed $warning ): bool => is_array( $warning ) && ( ! isset( $warning['blocking'] ) || (bool) $warning['blocking'] )
		)
	);
	$details  = is_array( $report['details'] ?? null ) ? $report['details'] : array();
	?>
	<div id="erankly-migration-attention" class="erankly-migration-attention">
		<h4><?php esc_html_e( 'Items needing attention', 'easyrankly' ); ?></h4>
		<?php if ( $warnings ) : ?>
			<ul>
				<?php foreach ( array_slice( $warnings, 0, 10 ) as $warning ) : ?>
					<li><?php echo esc_html( (string) ( $warning['message'] ?? $warning['code'] ?? '' ) ); ?><?php echo ! empty( $warning['reference'] ) ? ': ' . esc_html( (string) $warning['reference'] ) : ''; ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $details ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of per-record diagnostics. */
						_n( '%d record was recorded for review. Open Technical details for the full list.', '%d records were recorded for review. Open Technical details for the full list.', count( $details ), 'easyrankly' ),
						count( $details )
					)
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/** Renders the selected migration report and the recent report history. */
function erankly_migration_render_report(): void {
	$report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report selection.
	$active    = erankly_migration_job_runner()->active_job();
	if ( is_array( $active ) ) {
		erankly_migration_render_active_job( $active );
		return;
	}
	$reports = erankly_migration_manager()->reports();
	$report  = '' !== $report_id ? erankly_migration_manager()->get_report( $report_id ) : null;

	if ( ! is_array( $report ) ) {
		return;
	}

	$mode           = (string) ( $report['mode'] ?? '' );
	$is_preview     = 'preview' === $mode;
	$counts         = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
	$profile        = is_array( $report['source_profile'] ?? null ) ? $report['source_profile'] : array();
	$source_version = '' !== (string) ( $report['source_version'] ?? '' ) ? ' ' . (string) $report['source_version'] : '';
	$backup         = 'import' === $mode ? erankly_migration_backup_state( $report ) : array();
	$download_url   = wp_nonce_url(
		add_query_arg(
			array(
				'erankly_io_action' => 'migration-report',
				'report_id'         => (string) $report['id'],
			),
			erankly_import_export_url()
		),
		'erankly_migration_report_' . (string) $report['id']
	);
	$backup_url     = wp_nonce_url(
		add_query_arg(
			array(
				'erankly_io_action' => 'migration-backup',
				'report_id'         => (string) $report['id'],
			),
			erankly_import_export_url()
		),
		'erankly_migration_backup_' . (string) $report['id']
	);

	$source_owns_output = 'import' === $mode
		&& (bool) apply_filters( 'erankly_migration_source_owns_output', erankly_detect_external_seo_head_owner(), sanitize_key( (string) ( $report['source'] ?? '' ) ) );

	$ui = ( new ERankly_Migration_Admin_Presenter() )->present( $report, $source_owns_output, ! empty( $backup ) );
	if ( $source_owns_output ) {
		$owner_labels = array_values( array_unique( array_filter( array_map( static fn( array $owner ): string => sanitize_text_field( (string) ( $owner['label'] ?? '' ) ), erankly_external_seo_head_owners() ) ) ) );
		if ( $owner_labels ) {
			$ui['active_owner_label'] = implode( ', ', $owner_labels );
		}
	}
	$copy         = erankly_migration_guided_copy( $ui );
	$check_totals = is_array( $ui['check_totals'] ?? null ) ? $ui['check_totals'] : array();
	$completed_at = erankly_migration_format_datetime( (string) ( $report['completed_at'] ?? '' ) );
	?>
	<div class="erankly-settings-section erankly-migration-report">
		<div class="erankly-section-title-row">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Migration assistant', 'easyrankly' ); ?></h3>
			<?php erankly_render_section_doc_link( 'migration-assistant' ); ?>
		</div>
		<section class="erankly-card erankly-migration-card erankly-migration-card--<?php echo esc_attr( sanitize_key( (string) ( $ui['tone'] ?? 'info' ) ) ); ?>">
			<p class="erankly-migration-context">
				<strong><?php echo esc_html( (string) ( $report['source_label'] ?? $report['source'] ) . $source_version ); ?></strong>
				<span aria-hidden="true">&middot;</span>
				<?php echo $is_preview ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>
				<?php if ( '' !== $completed_at ) : ?>
					<span aria-hidden="true">&middot;</span>
					<time datetime="<?php echo esc_attr( (string) ( $report['completed_at'] ?? '' ) ); ?>"><?php echo esc_html( $completed_at ); ?></time>
				<?php endif; ?>
			</p>
			<div class="erankly-migration-message" role="status" aria-live="polite">
				<h4><?php echo esc_html( $copy['title'] ); ?></h4>
				<p class="erankly-migration-instruction"><?php echo esc_html( $copy['instruction'] ); ?></p>
				<?php if ( '' !== $copy['body'] ) : ?>
					<p><?php echo esc_html( $copy['body'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php erankly_migration_render_steps( $ui ); ?>
			<ul class="erankly-migration-metrics" aria-label="<?php esc_attr_e( 'Migration summary', 'easyrankly' ); ?>">
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['settings_count'] ?? 0 ) ) ); ?></strong><span><?php echo $is_preview ? esc_html__( 'Global settings ready', 'easyrankly' ) : esc_html__( 'Global settings imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['metadata_count'] ?? 0 ) ) ); ?></strong><span><?php echo $is_preview ? esc_html__( 'SEO fields ready', 'easyrankly' ) : esc_html__( 'SEO fields imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['redirect_count'] ?? 0 ) ) ); ?></strong><span><?php echo $is_preview ? esc_html__( 'Redirects ready', 'easyrankly' ) : esc_html__( 'Redirects imported', 'easyrankly' ); ?></span></li>
				<li class="<?php echo absint( $ui['problem_count'] ?? 0 ) > 0 ? 'has-problems' : 'has-no-problems'; ?>"><strong><?php echo esc_html( number_format_i18n( absint( $ui['problem_count'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Items needing attention', 'easyrankly' ); ?></span></li>
			</ul>
			<?php erankly_migration_render_guided_action( $ui, $report ); ?>
			<?php erankly_migration_render_attention( $ui, $report ); ?>

			<details id="erankly-migration-technical" class="erankly-migration-disclosure">
				<summary>
					<span><?php esc_html_e( 'Technical details', 'easyrankly' ); ?></span>
					<span class="erankly-migration-disclosure-summary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: passed checks, 2: failed checks, 3: checks with warnings, 4: checks not required. */
								__( '%1$d passed, %2$d failed, %3$d need attention, %4$d not required', 'easyrankly' ),
								absint( $check_totals['pass'] ?? 0 ),
								absint( $check_totals['fail'] ?? 0 ),
								absint( $check_totals['warn'] ?? 0 ),
								absint( $check_totals['not_applicable'] ?? 0 )
							)
						);
						?>
					</span>
				</summary>
				<div class="erankly-migration-disclosure-content">
					<p class="description">
						<?php
						printf(
							/* translators: 1: source version status, 2: source fingerprint state. */
							esc_html__( 'Source version: %1$s. Source anchor: %2$s.', 'easyrankly' ),
							esc_html( (string) ( $profile['version_status'] ?? 'unversioned' ) ),
							! empty( $report['source_fingerprint_verified'] ) ? esc_html__( 'verified at the end of the run', 'easyrankly' ) : esc_html__( 'captured at start', 'easyrankly' )
						);
						?>
					</p>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Area', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Found', 'easyrankly' ); ?></th><th><?php echo $is_preview ? esc_html__( 'Ready', 'easyrankly' ) : esc_html__( 'Written', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Already set', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Preserved / invalid', 'easyrankly' ); ?></th></tr></thead>
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Global settings', 'easyrankly' ); ?></td>
								<td><?php echo esc_html( (string) ( $counts['settings_found'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $is_preview ? ( $counts['settings_ready'] ?? 0 ) : ( $counts['settings_written'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $counts['settings_identical'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( ( $counts['settings_conflicts'] ?? 0 ) + ( $counts['settings_failed'] ?? 0 ) ) ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'SEO metadata', 'easyrankly' ); ?></td>
								<td><?php echo esc_html( (string) ( $counts['fields_found'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $is_preview ? ( $counts['fields_ready'] ?? 0 ) : ( $counts['fields_written'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $counts['fields_identical'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( ( $counts['fields_conflicts'] ?? 0 ) + ( $counts['fields_invalid'] ?? 0 ) + ( $counts['fields_failed'] ?? 0 ) ) ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Redirects', 'easyrankly' ); ?></td>
								<td><?php echo esc_html( (string) ( $counts['redirects_found'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $is_preview ? ( ( $counts['redirects_ready_create'] ?? 0 ) + ( $counts['redirects_ready_update'] ?? 0 ) ) : ( ( $counts['redirects_created'] ?? 0 ) + ( $counts['redirects_updated'] ?? 0 ) ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $counts['redirects_unchanged'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( ( $counts['redirects_conflicts'] ?? 0 ) + ( $counts['redirects_invalid'] ?? 0 ) + ( $counts['redirects_unsupported'] ?? 0 ) + ( $counts['redirects_failed'] ?? 0 ) ) ); ?></td>
							</tr>
						</tbody>
					</table>
					<p class="description">
						<?php
						printf(
							/* translators: 1: post count, 2: term count, 3: user count, 4: fields skipped as unsupported for the object type. */
							esc_html__( 'Objects scanned. Posts: %1$d; terms: %2$d; authors: %3$d. Fields skipped because EasyRankly does not read them for that object type: %4$d.', 'easyrankly' ),
							(int) ( $counts['posts_found'] ?? 0 ),
							(int) ( $counts['terms_found'] ?? 0 ),
							(int) ( $counts['users_found'] ?? 0 ),
							(int) ( $counts['fields_unsupported'] ?? 0 )
						);
						?>
					</p>
					<?php if ( ! empty( $report['warnings'] ) && is_array( $report['warnings'] ) ) : ?>
						<details>
							<summary><?php esc_html_e( 'Migration diagnostics', 'easyrankly' ); ?> (<?php echo esc_html( (string) count( $report['warnings'] ) ); ?>)</summary>
							<ul>
								<?php foreach ( array_slice( $report['warnings'], 0, 20 ) as $warning ) : ?>
									<li><?php echo esc_html( (string) ( $warning['message'] ?? $warning['code'] ?? '' ) ); ?><?php echo ! empty( $warning['reference'] ) ? ': ' . esc_html( (string) $warning['reference'] ) : ''; ?></li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
					<?php if ( ! empty( $report['details'] ) && is_array( $report['details'] ) ) : ?>
						<details>
							<summary><?php esc_html_e( 'Record-level diagnostics', 'easyrankly' ); ?> (<?php echo esc_html( (string) count( $report['details'] ) ); ?>)</summary>
							<ul>
								<?php foreach ( array_slice( $report['details'], 0, 50 ) as $detail ) : ?>
									<li>
										<code><?php echo esc_html( (string) ( $detail['code'] ?? '' ) ); ?></code>
										<?php echo ! empty( $detail['reference'] ) ? ': ' . esc_html( (string) $detail['reference'] ) : ''; ?>
										<?php echo ! empty( $detail['field'] ) ? ', ' . esc_html( (string) $detail['field'] ) : ''; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
					<div class="erankly-migration-report-actions">
						<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download technical report', 'easyrankly' ); ?></a>
					</div>
				</div>
			</details>

			<?php if ( 'import' === $mode ) : ?>
				<details id="erankly-migration-recovery" class="erankly-migration-disclosure erankly-migration-recovery">
					<summary>
						<span><?php esc_html_e( 'Undo this migration', 'easyrankly' ); ?></span>
						<span class="erankly-migration-disclosure-summary"><?php esc_html_e( 'Use only if you need to abandon this migration', 'easyrankly' ); ?></span>
					</summary>
					<div class="erankly-migration-disclosure-content">
						<?php if ( $backup ) : ?>
							<p><?php esc_html_e( 'A complete EasyRankly backup was taken before the first write. Restoring it replaces current settings, redirects and SEO fields with that snapshot.', 'easyrankly' ); ?></p>
							<?php if ( ! empty( $backup['expires_at'] ) ) : ?>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %s: localized expiry date. */ __( 'Kept until %s. Download it now if you want a copy that lasts longer.', 'easyrankly' ), erankly_migration_format_datetime( (string) $backup['expires_at'] ) ) ); ?></p>
							<?php endif; ?>
							<p>
								<a class="button" href="<?php echo esc_url( $backup_url ); ?>"><?php esc_html_e( 'Download the pre-import backup', 'easyrankly' ); ?></a>
							</p>
							<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Restore the pre-import backup? Current EasyRankly data will be replaced by the snapshot taken before this migration.', 'easyrankly' ) ); ?>');">
								<?php wp_nonce_field( 'erankly_migration_backup_' . (string) $report['id'] ); ?>
								<input type="hidden" name="erankly_io_action" value="migration-restore-backup">
								<input type="hidden" name="erankly_migration_report_id" value="<?php echo esc_attr( (string) $report['id'] ); ?>">
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Restore the pre-import backup', 'easyrankly' ); ?></button>
							</form>
						<?php else : ?>
							<p><?php esc_html_e( 'The automatic pre-import backup is no longer available. Download the technical report before attempting manual recovery.', 'easyrankly' ); ?></p>
						<?php endif; ?>
					</div>
				</details>
			<?php endif; ?>

			<?php if ( count( $reports ) > 1 ) : ?>
				<details class="erankly-migration-disclosure erankly-migration-history">
					<summary><?php esc_html_e( 'Recent migration reports', 'easyrankly' ); ?> (<?php echo esc_html( (string) count( $reports ) ); ?>)</summary>
					<ul>
						<?php foreach ( $reports as $recent ) : ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( array( 'report_id' => (string) ( $recent['id'] ?? '' ) ), erankly_import_export_url() ) ); ?>"><?php echo esc_html( (string) ( $recent['source_label'] ?? $recent['source'] ?? '' ) ); ?></a>. <?php echo 'preview' === (string) ( $recent['mode'] ?? '' ) ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>. <?php echo esc_html( erankly_migration_format_datetime( (string) ( $recent['completed_at'] ?? '' ) ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
	</div>
	<?php
}

/** Renders live counters and recovery controls for a resumable migration. */
function erankly_migration_render_active_job( array $job ): void {
	$counts     = is_array( $job['counts'] ?? null ) ? $job['counts'] : array();
	$status     = sanitize_key( (string) ( $job['status'] ?? 'queued' ) );
	$stream     = sanitize_key( (string) ( $job['stream'] ?? 'content' ) );
	$dry_run    = ! empty( $job['dry_run'] );
	$cancelling = ! empty( $job['cancel_requested'] );
	$source     = erankly_migration_manager()->adapter( (string) ( $job['source'] ?? '' ) );
	$action_url = erankly_import_export_url();
	$job_id     = sanitize_text_field( (string) ( $job['id'] ?? '' ) );
	$stage      = array(
		'settings' => __( 'Reading global settings', 'easyrankly' ),
		'content'  => __( 'Reading SEO metadata', 'easyrankly' ),
		'redirect' => __( 'Reading redirects', 'easyrankly' ),
		'finish'   => __( 'Finalizing the migration report', 'easyrankly' ),
	)[ $stream ] ?? __( 'Preparing the next batch', 'easyrankly' );
	if ( $cancelling ) {
		$stage = __( 'Cancellation requested', 'easyrankly' );
	}
	$title = $cancelling ? __( 'Cancellation requested', 'easyrankly' ) : ( 'paused' === $status ? __( 'Migration paused safely', 'easyrankly' ) : ( $dry_run ? __( 'Preview in progress', 'easyrankly' ) : __( 'Import in progress', 'easyrankly' ) ) );
	?>
	<div class="erankly-settings-section erankly-migration-progress">
		<div class="erankly-section-title-row">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Migration assistant', 'easyrankly' ); ?></h3>
			<?php erankly_render_section_doc_link( 'migration-assistant' ); ?>
		</div>
		<section class="erankly-card erankly-migration-card <?php echo 'paused' === $status ? 'erankly-migration-card--warning' : ''; ?>" aria-busy="<?php echo 'paused' === $status || $cancelling ? 'false' : 'true'; ?>">
			<p class="erankly-migration-context">
				<strong><?php echo esc_html( $source ? $source->label() : (string) ( $job['source'] ?? '' ) ); ?></strong>
				<span aria-hidden="true">&middot;</span>
				<?php echo $dry_run ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>
			</p>
			<div class="erankly-migration-message" role="status" aria-live="polite">
				<h4><?php echo esc_html( $title ); ?></h4>
				<p class="erankly-migration-instruction"><?php echo esc_html( $stage ); ?></p>
				<?php if ( $cancelling ) : ?>
					<p><?php esc_html_e( 'The request is saved and will run as soon as the current batch releases its lock. No further data will be written.', 'easyrankly' ); ?></p>
				<?php elseif ( 'paused' === $status ) : ?>
					<p><?php esc_html_e( 'The worker stopped at a safe checkpoint. Check the PHP or database log, then resume when ready.', 'easyrankly' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'You can leave this page. The migration continues in restart-safe background batches.', 'easyrankly' ); ?></p>
				<?php endif; ?>
			</div>
			<ul class="erankly-migration-metrics" aria-label="<?php esc_attr_e( 'Current migration progress', 'easyrankly' ); ?>">
				<li><strong><?php echo esc_html( number_format_i18n( absint( $dry_run ? ( $counts['settings_ready'] ?? 0 ) : ( $counts['settings_written'] ?? 0 ) ) ) ); ?></strong><span><?php echo $dry_run ? esc_html__( 'Global settings ready', 'easyrankly' ) : esc_html__( 'Global settings imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $counts['objects_found'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Objects found', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $dry_run ? ( $counts['fields_ready'] ?? 0 ) : ( $counts['fields_written'] ?? 0 ) ) ) ); ?></strong><span><?php echo $dry_run ? esc_html__( 'SEO fields ready', 'easyrankly' ) : esc_html__( 'SEO fields imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $counts['redirects_found'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Redirects found', 'easyrankly' ); ?></span></li>
			</ul>
			<?php if ( 'paused' === $status && ! $cancelling ) : ?>
				<div class="erankly-migration-primary-action">
					<form method="post" action="<?php echo esc_url( $action_url ); ?>">
						<?php wp_nonce_field( 'erankly_migration_job_' . $job_id ); ?>
						<input type="hidden" name="erankly_io_action" value="migration-process">
						<input type="hidden" name="erankly_migration_job_id" value="<?php echo esc_attr( $job_id ); ?>">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Resume migration', 'easyrankly' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
			<details class="erankly-migration-disclosure">
				<summary>
					<span><?php esc_html_e( 'Progress details', 'easyrankly' ); ?></span>
					<span class="erankly-migration-disclosure-summary"><?php echo esc_html( sprintf( /* translators: %d: number of completed batches. */ __( '%d saved batches', 'easyrankly' ), absint( $job['batches'] ?? 0 ) ) ); ?></span>
				</summary>
				<div class="erankly-migration-disclosure-content">
					<p><?php echo esc_html( sprintf( /* translators: %s: localized checkpoint date. */ __( 'Latest safe checkpoint: %s', 'easyrankly' ), erankly_migration_format_datetime( (string) ( $job['updated_at'] ?? '' ) ) ) ); ?></p>
					<p><?php echo esc_html( sprintf( /* translators: 1: fields found, 2: fields already set, 3: redirects handled. */ __( 'SEO fields found: %1$d; already set in EasyRankly: %2$d. Redirects handled so far: %3$d.', 'easyrankly' ), absint( $counts['fields_found'] ?? 0 ), absint( $counts['fields_identical'] ?? 0 ) + absint( $counts['fields_conflicts'] ?? 0 ), absint( $counts['redirects_created'] ?? 0 ) + absint( $counts['redirects_updated'] ?? 0 ) ) ); ?></p>
					<?php if ( ! $cancelling && 'paused' !== $status ) : ?>
						<form method="post" action="<?php echo esc_url( $action_url ); ?>">
							<?php wp_nonce_field( 'erankly_migration_job_' . $job_id ); ?>
							<input type="hidden" name="erankly_io_action" value="migration-process">
							<input type="hidden" name="erankly_migration_job_id" value="<?php echo esc_attr( $job_id ); ?>">
							<button type="submit" class="button"><?php esc_html_e( 'Process the next batch now', 'easyrankly' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</details>
			<?php if ( ! $cancelling ) : ?>
				<details class="erankly-migration-disclosure erankly-migration-recovery">
					<summary><?php esc_html_e( 'Cancel migration', 'easyrankly' ); ?></summary>
					<div class="erankly-migration-disclosure-content">
						<p><?php esc_html_e( 'Already imported EasyRankly values will be kept and included in the final report. The pre-import backup can still restore the previous state.', 'easyrankly' ); ?></p>
						<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Cancel this migration? Already imported EasyRankly values will be kept.', 'easyrankly' ) ); ?>');">
							<?php wp_nonce_field( 'erankly_migration_job_' . $job_id ); ?>
							<input type="hidden" name="erankly_io_action" value="migration-cancel">
							<input type="hidden" name="erankly_migration_job_id" value="<?php echo esc_attr( $job_id ); ?>">
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Cancel migration', 'easyrankly' ); ?></button>
						</form>
					</div>
				</details>
			<?php endif; ?>
		</section>
	</div>
	<?php if ( 'paused' !== $status ) : ?>
		<script>window.setTimeout(function(){ window.location.reload(); }, 15000);</script>
	<?php endif; ?>
	<?php
}

function erankly_third_party_data_exists( string $source ): bool {
	$adapter = erankly_migration_manager()->adapter( $source );

	return $adapter ? $adapter->is_available() : false;
}
