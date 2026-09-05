<?php
/** Migration report renderers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the selected post-migration report and recent report history. */
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

	$counts             = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
	$download_url       = wp_nonce_url(
		add_query_arg(
			array(
				'erankly_io_action' => 'migration-report',
				'report_id'         => (string) $report['id'],
			),
			erankly_import_export_url()
		),
		'erankly_migration_report_' . (string) $report['id']
	);
	$source_version     = '' !== (string) ( $report['source_version'] ?? '' ) ? ' ' . (string) $report['source_version'] : '';
	$profile            = is_array( $report['source_profile'] ?? null ) ? $report['source_profile'] : array();
	$inventory          = is_array( $report['source_inventory'] ?? null ) ? $report['source_inventory'] : array();
	$verification       = is_array( $report['verification'] ?? null ) ? $report['verification'] : array();
	$evidence           = is_array( $report['evidence'] ?? null ) ? $report['evidence'] : array();
	$accounting         = is_array( $evidence['accounting'] ?? null ) ? $evidence['accounting'] : array();
	$semantic           = is_array( $evidence['semantic_comparison'] ?? null ) ? $evidence['semantic_comparison'] : array();
	$redirect_audit     = is_array( $evidence['redirect_audit'] ?? null ) ? $evidence['redirect_audit'] : array();
	$rollback           = 'import' === (string) ( $report['mode'] ?? '' ) ? erankly_migration_journal()->summary( (string) $report['id'] ) : array();
	$baseline           = is_array( $report['html_baseline'] ?? null ) ? $report['html_baseline'] : array();
	$live               = is_array( $report['live_verification'] ?? null ) ? $report['live_verification'] : array();
	$gate               = erankly_migration_manager()->evaluate_go_live_gate( $report, true );
	$csv_url            = wp_nonce_url(
		add_query_arg(
			array(
				'erankly_io_action' => 'migration-exceptions',
				'report_id'         => (string) $report['id'],
			),
			erankly_import_export_url()
		),
		'erankly_migration_exceptions_' . (string) $report['id']
	);
	$source_owns_output = false;
	$active_owners      = function_exists( 'erankly_external_seo_head_owners' ) ? erankly_external_seo_head_owners() : array();
	if ( 'import' === (string) ( $report['mode'] ?? '' ) ) {
		$source_owns_output = (bool) apply_filters( 'erankly_migration_source_owns_output', erankly_detect_external_seo_head_owner(), sanitize_key( (string) ( $report['source'] ?? '' ) ) );
	}
	$ui = ( new ERankly_Migration_Admin_Presenter() )->present( $report, $gate, $rollback, $source_owns_output );
	if ( $source_owns_output ) {
		$owner_labels = array_values( array_unique( array_filter( array_map( static fn( array $owner ): string => sanitize_text_field( (string) ( $owner['label'] ?? '' ) ), $active_owners ) ) ) );
		if ( $owner_labels ) {
			$ui['active_owner_label'] = implode( ', ', $owner_labels );
		}
	}
	$copy         = erankly_migration_guided_copy( $ui );
	$check_totals = is_array( $ui['check_totals'] ?? null ) ? $ui['check_totals'] : array();
	$completed_at = erankly_migration_format_datetime( (string) ( $report['completed_at'] ?? '' ) );
	?>
	<div class="erankly-settings-section erankly-migration-report">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Migration assistant', 'easyrankly' ); ?></h3>
		<section class="erankly-card erankly-migration-card erankly-migration-card--<?php echo esc_attr( sanitize_key( (string) ( $ui['tone'] ?? 'info' ) ) ); ?>">
			<p class="erankly-migration-context">
				<strong><?php echo esc_html( (string) ( $report['source_label'] ?? $report['source'] ) . $source_version ); ?></strong>
				<span aria-hidden="true">&middot;</span>
				<?php echo 'preview' === (string) ( $report['mode'] ?? '' ) ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>
				<?php if ( '' !== $completed_at ) : ?>
					<span aria-hidden="true">&middot;</span>
					<time datetime="<?php echo esc_attr( (string) ( $report['completed_at'] ?? '' ) ); ?>"><?php echo esc_html( $completed_at ); ?></time>
				<?php endif; ?>
			</p>
			<div class="erankly-migration-message" role="status" aria-live="polite">
				<h4><?php echo esc_html( $copy['title'] ); ?></h4>
				<p class="erankly-migration-instruction"><?php echo esc_html( $copy['instruction'] ); ?></p>
				<p><?php echo esc_html( $copy['body'] ); ?></p>
			</div>
			<?php erankly_migration_render_steps( $ui ); ?>
			<ul class="erankly-migration-metrics" aria-label="<?php esc_attr_e( 'Migration summary', 'easyrankly' ); ?>">
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['settings_count'] ?? 0 ) ) ); ?></strong><span><?php echo 'preview' === (string) ( $report['mode'] ?? '' ) ? esc_html__( 'Global settings ready', 'easyrankly' ) : esc_html__( 'Global settings imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['metadata_count'] ?? 0 ) ) ); ?></strong><span><?php echo 'preview' === (string) ( $report['mode'] ?? '' ) ? esc_html__( 'SEO fields ready', 'easyrankly' ) : esc_html__( 'SEO fields imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['redirect_count'] ?? 0 ) ) ); ?></strong><span><?php echo 'preview' === (string) ( $report['mode'] ?? '' ) ? esc_html__( 'Redirects ready', 'easyrankly' ) : esc_html__( 'Redirects imported', 'easyrankly' ); ?></span></li>
				<li class="<?php echo absint( $ui['problem_count'] ?? 0 ) > 0 ? 'has-problems' : 'has-no-problems'; ?>"><strong><?php echo esc_html( number_format_i18n( absint( $ui['problem_count'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Items needing attention', 'easyrankly' ); ?></span></li>
			</ul>
			<?php erankly_migration_render_guided_action( $ui, $report ); ?>
			<?php erankly_migration_render_attention( $ui, $report, $gate ); ?>

			<details id="erankly-migration-technical" class="erankly-migration-disclosure">
				<summary>
					<span><?php esc_html_e( 'Technical details', 'easyrankly' ); ?></span>
					<span class="erankly-migration-disclosure-summary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: passed checks, 2: checks needing attention, 3: waiting checks, 4: checks not required. */
								__( '%1$d passed, %2$d need attention, %3$d waiting, %4$d not required', 'easyrankly' ),
								absint( $check_totals['pass'] ?? 0 ),
								absint( $check_totals['fail'] ?? 0 ),
								absint( $check_totals['pending'] ?? 0 ),
								absint( $check_totals['not_applicable'] ?? 0 )
							)
						);
						?>
					</span>
				</summary>
				<div class="erankly-migration-disclosure-content">
					<?php erankly_migration_render_go_live_gate( $gate ); ?>
			<?php if ( $profile ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: edition, 2: source mode, 3: storage format, 4: source fingerprint state. */
						esc_html__( 'Edition: %1$s. Source: %2$s. Certified signature: %3$s. Fingerprint: %4$s.', 'easyrankly' ),
						esc_html( strtoupper( (string) ( $profile['edition'] ?? 'unknown' ) ) ),
						esc_html( (string) ( $profile['mode'] ?? 'database' ) ),
						esc_html( (string) ( $profile['storage_format'] ?? 'unknown' ) ),
						! empty( $report['source_fingerprint_verified'] ) ? esc_html__( 'verified before apply', 'easyrankly' ) : esc_html__( 'captured', 'easyrankly' )
					);
					?>
				</p>
				<?php if ( ! empty( $profile['modules'] ) && is_array( $profile['modules'] ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'Detected modules:', 'easyrankly' ); ?>
						<?php echo esc_html( implode( ', ', array_map( 'sanitize_key', $profile['modules'] ) ) ); ?>.
						<?php if ( ! empty( $inventory['total'] ) ) : ?>
							<?php echo esc_html( sprintf( /* translators: %d: source inventory count. */ __( 'Source inventory: %d records across certified surfaces.', 'easyrankly' ), absint( $inventory['total'] ) ) ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Area', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Found', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Ready / written', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Preserved / unchanged', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Conflicts / invalid', 'easyrankly' ); ?></th></tr></thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Global settings', 'easyrankly' ); ?></td>
						<td><?php echo esc_html( (string) ( $counts['settings_found'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( 'preview' === (string) $report['mode'] ? ( $counts['settings_ready'] ?? 0 ) : ( $counts['settings_written'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['settings_skipped_existing'] ?? 0 ) + ( $counts['settings_identical'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['settings_conflicts'] ?? 0 ) + ( $counts['settings_invalid'] ?? 0 ) + ( $counts['settings_failed'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'SEO metadata', 'easyrankly' ); ?></td>
						<td><?php echo esc_html( (string) ( $counts['fields_found'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( 'preview' === (string) $report['mode'] ? ( $counts['fields_ready'] ?? 0 ) : ( $counts['fields_written'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['fields_skipped_existing'] ?? 0 ) + ( $counts['fields_duplicate'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['fields_conflicts'] ?? 0 ) + ( $counts['fields_invalid'] ?? 0 ) + ( $counts['fields_failed'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Redirects', 'easyrankly' ); ?></td>
						<td><?php echo esc_html( (string) ( $counts['redirects_found'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( 'preview' === (string) $report['mode'] ? ( ( $counts['redirects_ready_create'] ?? 0 ) + ( $counts['redirects_ready_update'] ?? 0 ) ) : ( ( $counts['redirects_created'] ?? 0 ) + ( $counts['redirects_updated'] ?? 0 ) ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['redirects_unchanged'] ?? 0 ) + ( $counts['redirects_duplicate'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['redirects_conflicts'] ?? 0 ) + ( $counts['redirects_invalid'] ?? 0 ) + ( $counts['redirects_unsupported'] ?? 0 ) + ( $counts['redirects_failed'] ?? 0 ) ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: 1: post count, 2: term count, 3: user count. */
					esc_html__( 'Objects scanned. Posts: %1$d; terms: %2$d; authors: %3$d.', 'easyrankly' ),
					(int) ( $counts['posts_found'] ?? 0 ),
					(int) ( $counts['terms_found'] ?? 0 ),
					(int) ( $counts['users_found'] ?? 0 )
				);
				?>
			</p>
			<?php if ( $accounting ) : ?>
				<h4><?php esc_html_e( 'Evidence ledger', 'easyrankly' ); ?></h4>
				<p>
					<strong><?php echo 'pass' === (string) ( $evidence['invariant']['status'] ?? '' ) ? esc_html__( 'Passed', 'easyrankly' ) : esc_html__( 'Failed', 'easyrankly' ); ?></strong>. <?php esc_html_e( 'Every discovered occurrence is assigned to exactly one terminal outcome.', 'easyrankly' ); ?>
				</p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Area', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Discovered', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Imported', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Identical', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Preserved', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Conflict / invalid / failed', 'easyrankly' ); ?></th></tr></thead>
					<tbody>
						<?php
						foreach ( array(
							'settings'  => __( 'Global settings', 'easyrankly' ),
							'metadata'  => __( 'SEO metadata', 'easyrankly' ),
							'redirects' => __( 'Redirects', 'easyrankly' ),
						) as $area_key => $area_label ) :
							?>
										<?php
										$area     = is_array( $accounting[ $area_key ] ?? null ) ? $accounting[ $area_key ] : array();
										$terminal = is_array( $area['terminal'] ?? null ) ? $area['terminal'] : array();
										?>
							<tr>
								<td><?php echo esc_html( $area_label ); ?></td>
								<td><?php echo esc_html( (string) ( $area['discovered'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $terminal['imported'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $terminal['identical'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $terminal['preserved'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( ( $terminal['conflict'] ?? 0 ) + ( $terminal['invalid'] ?? 0 ) + ( $terminal['unsupported'] ?? 0 ) + ( $terminal['failed'] ?? 0 ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					printf(
						/* translators: 1: transformed values, 2: unresolved placeholder warnings. */
						esc_html__( 'Normalized transformations: %1$d. Unresolved placeholder diagnostics: %2$d.', 'easyrankly' ),
						absint( $evidence['modifiers']['transformed'] ?? 0 ),
						count( is_array( $evidence['modifiers']['unresolved_placeholders'] ?? null ) ? $evidence['modifiers']['unresolved_placeholders'] : array() )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( $semantic ) : ?>
				<details>
					<summary><?php esc_html_e( 'Normalized before/after comparison', 'easyrankly' ); ?></summary>
					<ul>
						<?php foreach ( $semantic as $domain => $result ) : ?>
							<li><strong><?php echo esc_html( strtoupper( (string) $domain ) ); ?></strong>: <?php echo esc_html( sprintf( /* translators: 1: matches, 2: mismatches, 3: planned. */ __( '%1$d match; %2$d mismatch; %3$d planned.', 'easyrankly' ), absint( $result['matched'] ?? 0 ), absint( $result['mismatch'] ?? 0 ), absint( $result['planned'] ?? 0 ) ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<?php if ( $redirect_audit ) : ?>
				<details>
					<summary><?php esc_html_e( 'Redirect safety audit', 'easyrankly' ); ?></summary>
					<p><?php echo esc_html( sprintf( /* translators: 1: loops, 2: chains, 3: collisions, 4: dangerous regex. */ __( 'Loops: %1$d. Chains: %2$d. Collisions: %3$d. Dangerous regex: %4$d. Status and Location were tested with redirects disabled in the HTTP client.', 'easyrankly' ), count( $redirect_audit['loops'] ?? array() ), count( $redirect_audit['chains'] ?? array() ), count( $redirect_audit['collisions'] ?? array() ), count( $redirect_audit['dangerous_regex'] ?? array() ) ) ); ?></p>
				</details>
			<?php endif; ?>
			<?php if ( 'import' === (string) ( $report['mode'] ?? '' ) ) : ?>
				<h4><?php esc_html_e( 'Final verification evidence', 'easyrankly' ); ?></h4>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: baseline state, 2: live verification state, 3: exact matches, 4: expected provider changes, 5: regressions, 6: failed requests. */
							__( 'Saved baseline: %1$s. Current verification: %2$s. Exact matches: %3$d; expected provider changes: %4$d; regressions: %5$d; unreachable probes: %6$d.', 'easyrankly' ),
							sanitize_key( (string) ( $baseline['state'] ?? 'unavailable' ) ),
							sanitize_key( (string) ( $live['state'] ?? 'pending' ) ),
							absint( $live['matched'] ?? 0 ),
							absint( $live['expected_changes'] ?? 0 ),
							absint( $live['mismatch'] ?? 0 ),
							absint( $live['request_failed'] ?? 0 )
						)
					);
					?>
				</p>
			<?php endif; ?>
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
						<?php foreach ( array_slice( $report['details'], 0, 20 ) as $detail ) : ?>
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
					<?php if ( absint( $ui['problem_count'] ?? 0 ) > 0 ) : ?>
						<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download items to review', 'easyrankly' ); ?></a>
					<?php endif; ?>
				</div>
				</div>
			</details>

			<?php if ( ! empty( $ui['rollback_available'] ) || in_array( (string) ( $ui['state'] ?? '' ), array( 'verification_failed', 'rollback_failed' ), true ) ) : ?>
				<details id="erankly-migration-recovery" class="erankly-migration-disclosure erankly-migration-recovery"<?php echo 'rollback_failed' === (string) ( $ui['state'] ?? '' ) ? ' open' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed boolean attribute. ?>>
					<summary>
						<span><?php esc_html_e( 'Recovery and rollback', 'easyrankly' ); ?></span>
						<span class="erankly-migration-disclosure-summary"><?php esc_html_e( 'Use only if you need to abandon this migration', 'easyrankly' ); ?></span>
					</summary>
					<div class="erankly-migration-disclosure-content">
						<?php if ( 'rollback_failed' === (string) ( $ui['state'] ?? '' ) ) : ?>
							<p><strong><?php esc_html_e( 'Automated recovery did not finish safely.', 'easyrankly' ); ?></strong> <?php esc_html_e( 'Reactivate the previous SEO plugin and inspect the technical evidence before making manual data changes.', 'easyrankly' ); ?></p>
						<?php elseif ( ! empty( $ui['rollback_available'] ) ) : ?>
							<p><?php esc_html_e( 'The safe rollback changes only values that still exactly match this migration. Later manual edits are preserved.', 'easyrankly' ); ?></p>
							<?php if ( ! empty( $rollback['expires_at'] ) ) : ?>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %s: localized rollback expiry. */ __( 'Available until %s.', 'easyrankly' ), erankly_migration_format_datetime( (string) $rollback['expires_at'] ) ) ); ?></p>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Roll back this migration now? Only unchanged migration values will be affected.', 'easyrankly' ) ); ?>');">
								<?php wp_nonce_field( 'erankly_migration_evidence_' . (string) $report['id'] ); ?>
								<input type="hidden" name="erankly_io_action" value="migration-rollback">
								<input type="hidden" name="erankly_migration_report_id" value="<?php echo esc_attr( (string) $report['id'] ); ?>">
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Roll back this migration', 'easyrankly' ); ?></button>
							</form>
						<?php else : ?>
							<p><?php esc_html_e( 'The automatic rollback window is no longer available. Download the technical report before attempting manual recovery.', 'easyrankly' ); ?></p>
						<?php endif; ?>
					</div>
				</details>
			<?php endif; ?>

			<?php if ( count( $reports ) > 1 ) : ?>
				<details class="erankly-migration-disclosure erankly-migration-history">
					<summary><?php esc_html_e( 'Recent migration reports', 'easyrankly' ); ?> (<?php echo esc_html( (string) count( $reports ) ); ?>)</summary>
					<ul>
						<?php foreach ( $reports as $recent ) : ?>
							<?php
							$recent_url = add_query_arg(
								array( 'report_id' => (string) ( $recent['id'] ?? '' ) ),
								erankly_import_export_url()
							);
							?>
							<li>
								<a href="<?php echo esc_url( $recent_url ); ?>"><?php echo esc_html( (string) ( $recent['source_label'] ?? $recent['source'] ?? '' ) ); ?></a>. <?php echo 'preview' === (string) ( $recent['mode'] ?? '' ) ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>. <?php echo esc_html( erankly_migration_format_datetime( (string) ( $recent['completed_at'] ?? '' ) ) ); ?>
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
	$cancelling = ! empty( $job['cancel_requested'] );
	$source     = erankly_migration_manager()->adapter( (string) ( $job['source'] ?? '' ) );
	$action_url = erankly_import_export_url();
	$job_id     = sanitize_text_field( (string) ( $job['id'] ?? '' ) );
	$stage      = array(
		'content'  => __( 'Discovering SEO metadata', 'easyrankly' ),
		'redirect' => __( 'Discovering redirects', 'easyrankly' ),
		'apply'    => __( 'Applying validated records', 'easyrankly' ),
		'finish'   => __( 'Finalizing the migration report', 'easyrankly' ),
	)[ $stream ] ?? __( 'Preparing the next batch', 'easyrankly' );
	if ( $cancelling ) {
		$stage = __( 'Cancellation requested', 'easyrankly' );
	}
	$title = $cancelling ? __( 'Cancellation requested', 'easyrankly' ) : ( 'paused' === $status ? __( 'Migration paused safely', 'easyrankly' ) : ( ! empty( $job['dry_run'] ) ? __( 'Preview in progress', 'easyrankly' ) : __( 'Import in progress', 'easyrankly' ) ) );
	?>
	<div class="erankly-settings-section erankly-migration-progress">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Migration assistant', 'easyrankly' ); ?></h3>
		<section class="erankly-card erankly-migration-card <?php echo 'paused' === $status ? 'erankly-migration-card--warning' : ''; ?>" aria-busy="<?php echo 'paused' === $status || $cancelling ? 'false' : 'true'; ?>">
			<p class="erankly-migration-context">
				<strong><?php echo esc_html( $source ? $source->label() : (string) ( $job['source'] ?? '' ) ); ?></strong>
				<span aria-hidden="true">&middot;</span>
				<?php echo ! empty( $job['dry_run'] ) ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>
			</p>
			<div class="erankly-migration-message" role="status" aria-live="polite">
				<h4><?php echo esc_html( $title ); ?></h4>
				<p class="erankly-migration-instruction"><?php echo esc_html( $stage ); ?></p>
				<?php if ( $cancelling ) : ?>
					<p><?php esc_html_e( 'The request is saved and will run as soon as the current batch releases its lock. No new batch will be applied.', 'easyrankly' ); ?></p>
				<?php elseif ( 'paused' === $status ) : ?>
					<p><?php esc_html_e( 'The worker stopped at a safe checkpoint. Check the PHP or database log, then resume when ready.', 'easyrankly' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'You can leave this page. The migration continues in restart-safe background batches.', 'easyrankly' ); ?></p>
				<?php endif; ?>
			</div>
			<ul class="erankly-migration-metrics" aria-label="<?php esc_attr_e( 'Current migration progress', 'easyrankly' ); ?>">
				<li><strong><?php echo esc_html( number_format_i18n( ! empty( $job['dry_run'] ) ? absint( $counts['settings_ready'] ?? 0 ) : absint( $counts['settings_written'] ?? 0 ) ) ); ?></strong><span><?php echo ! empty( $job['dry_run'] ) ? esc_html__( 'Global settings ready', 'easyrankly' ) : esc_html__( 'Global settings imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $counts['objects_found'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Objects found', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( ! empty( $job['dry_run'] ) ? absint( $counts['fields_ready'] ?? 0 ) : absint( $counts['fields_written'] ?? 0 ) ) ); ?></strong><span><?php echo ! empty( $job['dry_run'] ) ? esc_html__( 'SEO fields ready', 'easyrankly' ) : esc_html__( 'SEO fields imported', 'easyrankly' ); ?></span></li>
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
					<p><?php echo esc_html( sprintf( /* translators: 1: fields found, 2: fields ready, 3: redirects imported. */ __( 'SEO fields found: %1$d; ready: %2$d. Redirects imported so far: %3$d.', 'easyrankly' ), absint( $counts['fields_found'] ?? 0 ), absint( $counts['fields_ready'] ?? 0 ), absint( $counts['redirects_created'] ?? 0 ) + absint( $counts['redirects_updated'] ?? 0 ) ) ); ?></p>
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
						<p><?php esc_html_e( 'Already imported EasyRankly values will be kept and included in the final report.', 'easyrankly' ); ?></p>
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
