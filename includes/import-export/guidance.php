<?php
/**
 * Migration guidance renderers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the authoritative, fail-closed go-live decision.
 *
 * @param array<string,mixed> $gate Go-live gate payload.
 * @return void
 */
function erankly_migration_render_go_live_gate( array $gate ): void {
	$state         = sanitize_key( (string) ( $gate['state'] ?? 'blocked' ) );
	$scope         = sanitize_key( (string) ( $gate['proof_scope'] ?? 'none' ) );
	$titles        = array(
		'preview_only'      => __( 'Preview evidence only', 'easyrankly' ),
		'blocked'           => __( 'Final verification blocked', 'easyrankly' ),
		'ready_for_cutover' => __( 'Ready for final verification', 'easyrankly' ),
		'go_live'           => 'contract_only' === $scope ? __( 'Import contract verified', 'easyrankly' ) : __( 'Migration fully verified', 'easyrankly' ),
		'rollback_required' => __( 'Recovery decision required', 'easyrankly' ),
		'rolled_back'       => __( 'Rollback completed', 'easyrankly' ),
		'rollback_failed'   => __( 'Rollback incomplete. Manual recovery required', 'easyrankly' ),
	);
	$classes       = array(
		'preview_only'      => 'info',
		'blocked'           => 'error',
		'ready_for_cutover' => 'warning',
		'go_live'           => 'success',
		'rollback_required' => 'error',
		'rolled_back'       => 'info',
		'rollback_failed'   => 'error',
	);
	$descriptions  = array(
		'preview_only'      => __( 'A preview can validate source coverage, but it never authorizes deactivation of the source SEO plugin.', 'easyrankly' ),
		'blocked'           => __( 'Keep the source SEO plugin active. At least one mandatory proof is missing or failed.', 'easyrankly' ),
		'ready_for_cutover' => __( 'The import checks passed. Deactivate the source plugin without deleting its data, clear any cache you use, then run the final verification.', 'easyrankly' ),
		'go_live'           => __( 'All mandatory proofs for this migration scope passed. Keep the report and source backup during monitoring.', 'easyrankly' ),
		'rollback_required' => __( 'Post-cutover output differs from the baseline or could not be proven. Retry after cache checks or run the conditional rollback.', 'easyrankly' ),
		'rolled_back'       => __( 'The conditional rollback completed; later manual edits were preserved.', 'easyrankly' ),
		'rollback_failed'   => __( 'Automated rollback did not complete safely. Reactivate the source plugin and inspect the rollback evidence.', 'easyrankly' ),
	);
	$check_labels  = array(
		'terminal_status'         => __( 'Migration reached a successful terminal state', 'easyrankly' ),
		'source_integrity'        => __( 'Immutable source fingerprint verified before apply', 'easyrankly' ),
		'exact_accounting'        => __( 'Every discovered occurrence classified exactly once', 'easyrankly' ),
		'write_failures'          => __( 'No failed writes', 'easyrankly' ),
		'invalid_records'         => __( 'No invalid source records', 'easyrankly' ),
		'conflicts'               => __( 'No unresolved conflicts', 'easyrankly' ),
		'unsupported_records'     => __( 'No unsupported records', 'easyrankly' ),
		'preserved_values'        => __( 'No values silently preserved instead of migrated', 'easyrankly' ),
		'diagnostics'             => __( 'No unresolved diagnostics', 'easyrankly' ),
		'semantic_match'          => __( 'Stored semantics match normalized source values', 'easyrankly' ),
		'unresolved_placeholders' => __( 'No unresolved source placeholders', 'easyrankly' ),
		'redirect_storage'        => __( 'Every imported redirect matches persistent storage', 'easyrankly' ),
		'redirect_loops'          => __( 'No redirect loops', 'easyrankly' ),
		'redirect_chains'         => __( 'No redirect chains', 'easyrankly' ),
		'redirect_collisions'     => __( 'No redirect collisions', 'easyrankly' ),
		'redirect_regex'          => __( 'No dangerous redirect regular expressions', 'easyrankly' ),
		'rollback_window'         => __( 'Rollback journal covers every migration write and is not expired', 'easyrankly' ),
		'frontend_baseline'       => __( 'Old-plugin frontend baseline captured before cutover', 'easyrankly' ),
		'live_verification'       => __( 'Current HTML, redirects, robots.txt and sitemap preserve the saved SEO meaning', 'easyrankly' ),
		'rollback_result'         => __( 'Conditional rollback completed without failures', 'easyrankly' ),
	);
	$status_labels = array(
		'pass'           => __( 'Passed', 'easyrankly' ),
		'fail'           => __( 'Needs attention', 'easyrankly' ),
		'pending'        => __( 'Waiting', 'easyrankly' ),
		'not_applicable' => __( 'Not required', 'easyrankly' ),
	);

	if ( ! isset( $titles[ $state ], $classes[ $state ] ) ) {
		$state = 'blocked';
	}
	?>
	<div class="erankly-migration-gate erankly-migration-gate--<?php echo esc_attr( $classes[ $state ] ); ?>">
		<p><strong><?php echo esc_html( $titles[ $state ] ); ?></strong></p>
		<p><?php echo esc_html( $descriptions[ $state ] ); ?></p>
		<?php if ( 'contract_only' === $scope ) : ?>
			<p><strong><?php esc_html_e( 'Proof boundary:', 'easyrankly' ); ?></strong> <?php esc_html_e( 'the certified export contract and stored migration evidence passed. The source plugin did not own this site frontend, so no old-plugin HTML comparison was possible or claimed.', 'easyrankly' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $gate['checks'] ) && is_array( $gate['checks'] ) ) : ?>
			<ul>
				<?php foreach ( $gate['checks'] as $check ) : ?>
					<?php
					$code   = sanitize_key( (string) ( $check['code'] ?? '' ) );
					$status = sanitize_key( (string) ( $check['status'] ?? '' ) );
					$count  = absint( $check['count'] ?? 0 );
					?>
					<?php if ( isset( $check_labels[ $code ], $status_labels[ $status ] ) ) : ?>
						<li><strong><?php echo esc_html( $status_labels[ $status ] ); ?></strong>: <?php echo esc_html( $check_labels[ $code ] ); ?><?php echo $count > 0 ? esc_html( ' (' . $count . ')' ) : ''; ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: proof scope, 2: decision SHA-256. */
				esc_html__( 'Proof scope: %1$s. Decision SHA-256: %2$s.', 'easyrankly' ),
				esc_html( $scope ),
				esc_html( (string) ( $gate['decision_sha256'] ?? '' ) )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Formats one persisted UTC date for the current WordPress locale and timezone.
 *
 * @param string $value ISO-8601 or database date.
 * @return string
 */
function erankly_migration_format_datetime( string $value ): string {
	$timestamp = strtotime( $value );
	if ( false === $timestamp ) {
		return sanitize_text_field( $value );
	}

	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Returns the concise copy for one presenter state.
 *
 * @param array<string,mixed> $ui Presenter payload.
 * @return array{title:string,instruction:string,body:string}
 */
function erankly_migration_guided_copy( array $ui ): array {
	$state        = sanitize_key( (string) ( $ui['state'] ?? 'blocked' ) );
	$source_label = sanitize_text_field( (string) ( $ui['active_owner_label'] ?? $ui['source_label'] ?? __( 'the previous SEO plugin', 'easyrankly' ) ) );
	$copy         = array(
		'preview_ready'       => array(
			'title'       => __( 'Preview complete', 'easyrankly' ),
			'instruction' => __( 'Step 1 of 3. Import your SEO data', 'easyrankly' ),
			'body'        => __( 'No blocking issue was found. Existing EasyRankly values will still be preserved and reported.', 'easyrankly' ),
		),
		'preview_blocked'     => array(
			'title'       => __( 'Review required before importing', 'easyrankly' ),
			'instruction' => __( 'Resolve the items that need attention', 'easyrankly' ),
			'body'        => __( 'Keep the source plugin active. No final switch is authorized while a required check is unresolved.', 'easyrankly' ),
		),
		'source_active'       => array(
			'title'       => __( 'Import complete', 'easyrankly' ),
			/* translators: %s: source SEO plugin name. */
			'instruction' => sprintf( __( 'Step 2 of 3. Deactivate %s', 'easyrankly' ), $source_label ),
			/* translators: %s: source SEO plugin name. */
			'body'        => sprintf( __( 'Do not delete %s or its data yet. Keep this report open while you deactivate it from the Plugins screen.', 'easyrankly' ), $source_label ),
		),
		'ready_to_verify'     => array(
			'title'       => __( 'The previous SEO plugin is no longer active', 'easyrankly' ),
			'instruction' => __( 'Step 3 of 3. Verify the site', 'easyrankly' ),
			'body'        => __( 'Clear any WordPress, page or CDN cache you use, then let EasyRankly compare the current site with the saved baseline.', 'easyrankly' ),
		),
		'complete'            => array(
			'title'       => __( 'Migration complete and verified', 'easyrankly' ),
			'instruction' => __( 'EasyRankly is now managing the site SEO output', 'easyrankly' ),
			'body'        => __( 'The checked SEO meaning, redirects and sitemap inventory were preserved. Expected provider-specific markup and endpoint changes were accepted. Keep the old plugin data during the monitoring window.', 'easyrankly' ),
		),
		'contract_verified'   => array(
			'title'       => __( 'Data import complete', 'easyrankly' ),
			'instruction' => __( 'The certified import contract was verified', 'easyrankly' ),
			'body'        => __( 'A before-and-after frontend comparison was not available for this file import. Review representative pages before deleting the source backup.', 'easyrankly' ),
		),
		'verification_failed' => array(
			'title'       => __( 'The final verification needs attention', 'easyrankly' ),
			'instruction' => __( 'Review the differences before deciding', 'easyrankly' ),
			'body'        => __( 'Do not delete the previous plugin data. Clear the caches you use, inspect the differences, then retry or use the safe rollback.', 'easyrankly' ),
		),
		'rolled_back'         => array(
			'title'       => __( 'Rollback complete', 'easyrankly' ),
			'instruction' => __( 'Reactivate the previous SEO plugin', 'easyrankly' ),
			'body'        => __( 'Only values that still matched this migration were restored or removed. Later manual edits were preserved.', 'easyrankly' ),
		),
		'rollback_failed'     => array(
			'title'       => __( 'Manual recovery is required', 'easyrankly' ),
			'instruction' => __( 'Review the rollback evidence before changing data', 'easyrankly' ),
			'body'        => __( 'Reactivate the previous SEO plugin and use the technical report to complete recovery safely.', 'easyrankly' ),
		),
		'blocked'             => array(
			'title'       => __( 'Migration not ready', 'easyrankly' ),
			'instruction' => __( 'Resolve the items that need attention', 'easyrankly' ),
			'body'        => __( 'Keep the previous SEO plugin active. EasyRankly will not authorize the final switch while required evidence is missing.', 'easyrankly' ),
		),
	);

	return $copy[ $state ] ?? $copy['blocked'];
}

/**
 * Renders the three-step migration progress indicator.
 *
 * @param array<string,mixed> $ui Presenter payload.
 * @return void
 */
function erankly_migration_render_steps( array $ui ): void {
	$state   = sanitize_key( (string) ( $ui['state'] ?? 'blocked' ) );
	$visible = in_array( $state, array( 'preview_ready', 'preview_blocked', 'source_active', 'ready_to_verify', 'complete', 'contract_verified', 'verification_failed', 'blocked' ), true );
	if ( ! $visible ) {
		return;
	}

	$step     = max( 1, min( 3, absint( $ui['step'] ?? 1 ) ) );
	$finished = in_array( $state, array( 'complete', 'contract_verified' ), true );
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

/**
 * Renders the only primary action for the current migration state.
 *
 * @param array<string,mixed> $ui     Presenter payload.
 * @param array<string,mixed> $report Persisted report.
 * @return void
 */
function erankly_migration_render_guided_action( array $ui, array $report ): void {
	$action      = sanitize_key( (string) ( $ui['primary_action'] ?? '' ) );
	$report_id   = sanitize_text_field( (string) ( $report['id'] ?? '' ) );
	$source      = sanitize_key( (string) ( $report['source'] ?? '' ) );
	$source_name = sanitize_text_field( (string) ( $ui['active_owner_label'] ?? $ui['source_label'] ?? '' ) );
	$recheck_url = add_query_arg( 'report_id', $report_id, erankly_import_export_url() );
	?>
	<div class="erankly-migration-primary-action">
		<?php if ( 'open_plugins' === $action ) : ?>
			<?php
			$plugins_url  = is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
			$plugins_url  = add_query_arg(
				array(
					'plugin_status' => 'rolled_back' === (string) ( $ui['state'] ?? '' ) ? 'all' : 'active',
					's'             => $source_name,
				),
				$plugins_url
			);
			$button_label = 'rolled_back' === (string) ( $ui['state'] ?? '' ) ? __( 'Open Plugins to reactivate it', 'easyrankly' ) : __( 'Open Plugins in a new tab', 'easyrankly' );
			?>
			<a class="button button-primary" href="<?php echo esc_url( $plugins_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $button_label ); ?></a>
			<?php if ( 'source_active' === (string) ( $ui['state'] ?? '' ) ) : ?>
				<a class="button" href="<?php echo esc_url( $recheck_url ); ?>"><?php esc_html_e( 'I deactivated it. Check again', 'easyrankly' ); ?></a>
			<?php endif; ?>
		<?php elseif ( 'verify_live' === $action && ! empty( $ui['can_verify_live'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>">
				<?php wp_nonce_field( 'erankly_migration_evidence_' . $report_id ); ?>
				<input type="hidden" name="erankly_io_action" value="migration-verify-live">
				<input type="hidden" name="erankly_migration_report_id" value="<?php echo esc_attr( $report_id ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Start final verification', 'easyrankly' ); ?></button>
			</form>
		<?php elseif ( 'run_import' === $action && ! empty( $ui['can_run_import'] ) ) : ?>
			<?php if ( ! empty( $ui['is_database_migration'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Import the reviewed data now? Existing EasyRankly values will still be preserved.', 'easyrankly' ) ); ?>');">
					<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
					<input type="hidden" name="erankly_io_action" value="migrate">
					<input type="hidden" name="erankly_migration_source" value="<?php echo esc_attr( $source ); ?>">
					<input type="hidden" name="erankly_migration_mode" value="import">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import the reviewed data', 'easyrankly' ); ?></button>
				</form>
			<?php else : ?>
				<a class="button button-primary" href="#erankly-migration-export-form"><?php esc_html_e( 'Upload the official export again', 'easyrankly' ); ?></a>
			<?php endif; ?>
		<?php elseif ( 'review_differences' === $action ) : ?>
			<a class="button button-primary" href="#erankly-migration-attention"><?php esc_html_e( 'Review the differences', 'easyrankly' ); ?></a>
		<?php elseif ( 'review_recovery' === $action ) : ?>
			<a class="button button-primary" href="#erankly-migration-recovery"><?php esc_html_e( 'Open recovery details', 'easyrankly' ); ?></a>
		<?php elseif ( 'review_issues' === $action ) : ?>
			<a class="button button-primary" href="#erankly-migration-attention"><?php esc_html_e( 'Review items that need attention', 'easyrankly' ); ?></a>
		<?php elseif ( 'open_settings' === $action ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'erankly_tab', 'general', erankly_import_export_url() ) ); ?>"><?php esc_html_e( 'Go to EasyRankly settings', 'easyrankly' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Renders only actionable blockers and live differences in the primary layer.
 *
 * @param array<string,mixed> $ui     Presenter payload.
 * @param array<string,mixed> $report Persisted report.
 * @param array<string,mixed> $gate   Go-live gate.
 * @return void
 */
function erankly_migration_render_attention( array $ui, array $report, array $gate ): void {
	$checks   = is_array( $gate['checks'] ?? null ) ? $gate['checks'] : array();
	$warnings = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array();
	$live     = is_array( $report['live_verification'] ?? null ) ? $report['live_verification'] : array();
	$failed   = array_values( array_filter( $checks, static fn( array $check ): bool => 'fail' === (string) ( $check['status'] ?? '' ) ) );
	$has_live = (int) ( $live['mismatch'] ?? 0 ) > 0 || (int) ( $live['request_failed'] ?? 0 ) > 0;
	if ( ! $failed && ! $warnings && ! $has_live ) {
		return;
	}

	$labels = array(
		'terminal_status'         => __( 'The migration did not finish successfully.', 'easyrankly' ),
		'source_integrity'        => __( 'The source data changed while it was being processed.', 'easyrankly' ),
		'exact_accounting'        => __( 'Not every discovered value has a final outcome.', 'easyrankly' ),
		'write_failures'          => __( 'Some values could not be written.', 'easyrankly' ),
		'invalid_records'         => __( 'Some source records are invalid.', 'easyrankly' ),
		'conflicts'               => __( 'Some existing EasyRankly values were preserved and need review.', 'easyrankly' ),
		'unsupported_records'     => __( 'Some source records are not supported.', 'easyrankly' ),
		'preserved_values'        => __( 'Some source values were preserved instead of imported.', 'easyrankly' ),
		'diagnostics'             => __( 'The report contains warnings that need review.', 'easyrankly' ),
		'semantic_match'          => __( 'Some normalized values differ from the source meaning.', 'easyrankly' ),
		'unresolved_placeholders' => __( 'Some source template variables could not be resolved.', 'easyrankly' ),
		'redirect_storage'        => __( 'Some redirects do not match their stored values.', 'easyrankly' ),
		'redirect_loops'          => __( 'A redirect loop was detected.', 'easyrankly' ),
		'redirect_chains'         => __( 'A redirect chain was detected.', 'easyrankly' ),
		'redirect_collisions'     => __( 'Two redirects use the same source path.', 'easyrankly' ),
		'redirect_regex'          => __( 'A redirect pattern requires manual review.', 'easyrankly' ),
		'rollback_window'         => __( 'The safe rollback window is unavailable or incomplete.', 'easyrankly' ),
		'frontend_baseline'       => __( 'The previous frontend output could not be captured.', 'easyrankly' ),
		'live_verification'       => __( 'The current site output differs from the saved baseline.', 'easyrankly' ),
		'rollback_result'         => __( 'The rollback did not complete safely.', 'easyrankly' ),
	);
	?>
	<section id="erankly-migration-attention" class="erankly-migration-attention" aria-labelledby="erankly-migration-attention-title">
		<h4 id="erankly-migration-attention-title"><?php esc_html_e( 'What needs attention', 'easyrankly' ); ?></h4>
		<ul>
			<?php foreach ( $failed as $check ) : ?>
				<?php $code = sanitize_key( (string) ( $check['code'] ?? '' ) ); ?>
				<?php if ( isset( $labels[ $code ] ) && ( 'live_verification' !== $code || ! $has_live ) ) : ?>
					<li><?php echo esc_html( $labels[ $code ] ); ?><?php echo absint( $check['count'] ?? 0 ) > 0 ? esc_html( ' (' . absint( $check['count'] ) . ')' ) : ''; ?></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( is_array( $live['pages'] ?? null ) ? $live['pages'] : array() as $page ) : ?>
				<?php if ( in_array( (string) ( $page['status'] ?? '' ), array( 'mismatch', 'request_failed' ), true ) ) : ?>
					<li><?php echo esc_html( 'request_failed' === (string) ( $page['status'] ?? '' ) ? __( 'A page could not be reached: ', 'easyrankly' ) : __( 'Page SEO output differs: ', 'easyrankly' ) ); ?><code><?php echo esc_html( (string) ( $page['url'] ?? '' ) ); ?></code></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( is_array( $live['redirects'] ?? null ) ? $live['redirects'] : array() as $redirect ) : ?>
				<?php if ( in_array( (string) ( $redirect['status'] ?? '' ), array( 'mismatch', 'request_failed' ), true ) ) : ?>
					<li><?php esc_html_e( 'Redirect response differs: ', 'easyrankly' ); ?><code><?php echo esc_html( (string) ( $redirect['source_path'] ?? '' ) ); ?></code></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( is_array( $live['surfaces'] ?? null ) ? $live['surfaces'] : array() as $surface => $result ) : ?>
				<?php if ( in_array( (string) ( $result['status'] ?? '' ), array( 'mismatch', 'request_failed' ), true ) ) : ?>
					<li><?php echo esc_html( sprintf( /* translators: %s: robots.txt or sitemap. */ __( '%s differs from the saved baseline.', 'easyrankly' ), sanitize_text_field( (string) $surface ) ) ); ?></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( array_slice( $warnings, 0, 10 ) as $warning ) : ?>
				<li><?php echo esc_html( (string) ( $warning['message'] ?? $warning['code'] ?? '' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( 'verification_failed' === (string) ( $ui['state'] ?? '' ) && ! empty( $gate['can_verify_live'] ) && empty( $ui['source_owns_output'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>">
				<?php wp_nonce_field( 'erankly_migration_evidence_' . (string) $report['id'] ); ?>
				<input type="hidden" name="erankly_io_action" value="migration-verify-live">
				<input type="hidden" name="erankly_migration_report_id" value="<?php echo esc_attr( (string) $report['id'] ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Run verification again', 'easyrankly' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
	<?php
}
