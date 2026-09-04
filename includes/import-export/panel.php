<?php
/** Import / Export settings panel. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the focused second upload required after an official-export preview.
 *
 * @param int                 $csv_upload_max  Maximum CSV upload size.
 * @param int                 $json_upload_max Maximum JSON upload size.
 */
function erankly_migration_render_reviewed_export_upload( array $report, string $action_url, int $csv_upload_max, int $json_upload_max ): void {
	$source_label = sanitize_text_field( (string) ( $report['source_label'] ?? $report['source'] ?? '' ) );
	?>
	<div id="erankly-migration-export-form" class="erankly-settings-section">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Import the reviewed file', 'easyrankly' ); ?></h3>
		<section class="erankly-card">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: source plugin name, 2: maximum CSV size, 3: maximum JSON size. */
						__( 'Upload the same official %1$s export that you just reviewed. EasyRankly will validate it again before importing. Maximum size: CSV %2$s; JSON %3$s.', 'easyrankly' ),
						$source_label,
						size_format( $csv_upload_max ),
						size_format( $json_upload_max )
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form">
				<?php wp_nonce_field( 'erankly_io_third_party_export' ); ?>
				<input type="hidden" name="erankly_io_action" value="migrate-export">
				<input type="hidden" name="erankly_migration_export_source" value="<?php echo esc_attr( (string) ( $report['source'] ?? '' ) ); ?>">
				<input type="hidden" name="erankly_migration_mode" value="import">
				<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-reviewed-migration-export-file">
					<input type="file" id="erankly-reviewed-migration-export-file" name="erankly_migration_export_file" accept=".csv,.json,text/csv,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
					<span class="erankly-dropzone-icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M12 15.5V4M12 4L8 8M12 4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M4 15.5v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</span>
					<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
						<strong><?php esc_html_e( 'Choose the reviewed export', 'easyrankly' ); ?></strong>
						<?php esc_html_e( 'or drag and drop it here', 'easyrankly' ); ?>
					</span>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import the reviewed file', 'easyrankly' ); ?></button>
			</form>
		</section>
	</div>
	<?php
}

function erankly_import_export_render_panel(): void {
	// On Multisite the settings option is a network option; mirror the write-access gate.
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	ERankly_Migration_Upload_Store::prune_stale();

	$export_url = wp_nonce_url( add_query_arg( 'erankly_io_action', 'export', erankly_import_export_url() ), 'erankly_io_export' );
	$action_url = erankly_import_export_url();

	// Third-party import sources, in the order they appear in the dropdown.
	$sources         = array();
	$source_profiles = array();
	foreach ( erankly_migration_manager()->adapters() as $key => $adapter ) {
		$profile                 = $adapter->profile();
		$source_profiles[ $key ] = $profile;
		$edition                 = strtoupper( (string) ( $profile['edition'] ?? '' ) );
		$version                 = (string) ( $profile['version'] ?? '' );
		$sources[ $key ]         = trim( $adapter->label() . ( '' !== $edition ? ' ' . $edition : '' ) . ( '' !== $version ? ' ' . $version : '' ) );
	}

	$source_availability = array();
	$default_source      = '';

	foreach ( $sources as $key => $label ) {
		$has_data                    = erankly_third_party_data_exists( $key );
		$available                   = $has_data && 'supported' === (string) ( $source_profiles[ $key ]['storage_status'] ?? '' );
		$source_availability[ $key ] = $available;
		if ( $available && '' === $default_source ) {
			$default_source = $key;
		}
	}

	$has_any_source         = '' !== $default_source;
	$has_unsupported_source = false;
	foreach ( $source_profiles as $profile ) {
		if ( 'unsupported' === (string) ( $profile['storage_status'] ?? '' ) ) {
			$has_unsupported_source = true;
			break;
		}
	}
	$active_job        = erankly_migration_job_runner()->active_job();
	$active_import_job = ERankly_Import_Job_Runner::active_job();
	$import_max        = erankly_import_export_max_bytes();
	$csv_upload_max    = ERankly_Migration_Upload_Store::export_max_bytes( 'csv' );
	$json_upload_max   = ERankly_Migration_Upload_Store::export_max_bytes( 'json' );
	$focused_report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report context.
	$focused_report    = '' !== $focused_report_id ? erankly_migration_manager()->get_report( $focused_report_id ) : null;

	erankly_import_export_render_notice();
	if ( is_array( $active_import_job ) ) {
		$totals    = is_array( $active_import_job['totals'] ?? null ) ? array_sum( array_map( 'absint', $active_import_job['totals'] ) ) : 0;
		$processed = min( $totals, absint( $active_import_job['processed'] ?? 0 ) );
		?>
		<div class="notice notice-info inline">
			<p><strong><?php esc_html_e( 'EasyRankly import in progress', 'easyrankly' ); ?></strong></p>
			<p><?php echo esc_html( sprintf( /* translators: 1: processed records, 2: total records, 3: stage name. */ __( '%1$d of %2$d records processed. Current stage: %3$s. The private source and cursor are saved; WP-Cron will continue automatically.', 'easyrankly' ), $processed, $totals, sanitize_key( (string) ( $active_import_job['stage'] ?? '' ) ) ) ); ?></p>
		</div>
		<?php
		return;
	}
	erankly_migration_render_report();
	if ( is_array( $active_job ) ) {
		return;
	}
	if ( is_array( $focused_report ) ) {
		$focused_profile      = is_array( $focused_report['source_profile'] ?? null ) ? $focused_report['source_profile'] : array();
		$focused_verification = is_array( $focused_report['verification'] ?? null ) ? $focused_report['verification'] : array();
		if ( 'preview' === (string) ( $focused_report['mode'] ?? '' ) && 'official_export' === (string) ( $focused_profile['mode'] ?? '' ) && ! empty( $focused_verification['ready_to_import'] ) ) {
			erankly_migration_render_reviewed_export_upload( $focused_report, $action_url, $csv_upload_max, $json_upload_max );
		}
		?>
		<p class="erankly-migration-back"><a href="<?php echo esc_url( erankly_import_export_url() ); ?>">&larr; <?php esc_html_e( 'Back to all import and export tools', 'easyrankly' ); ?></a></p>
		<?php
		return;
	}
	?>
		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Export', 'easyrankly' ); ?></h3>
			<section class="erankly-card">
				<p class="description"><?php esc_html_e( 'Download a JSON backup of your EasyRankly settings, redirects and SEO metadata. Keep it as a backup or import it on another site.', 'easyrankly' ); ?></p>
				<?php if ( is_multisite() ) : ?>
					<p class="description"><?php esc_html_e( 'On this network the file holds the network-wide settings plus this primary site\'s content (redirects, post/term metadata, special page defaults), not a whole-network export of every site.', 'easyrankly' ); ?></p>
				<?php endif; ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export data', 'easyrankly' ); ?></a></p>
			</section>
		</div>

		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Import', 'easyrankly' ); ?></h3>
			<section class="erankly-card">
				<p class="description"><?php esc_html_e( 'Upload a JSON file previously exported by EasyRankly. Settings, redirects and special page defaults are replaced; post and term metadata is matched by ID and overwritten.', 'easyrankly' ); ?></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: maximum safe complete-import size. */
						esc_html__( 'For memory safety, complete JSON imports are limited to %s on this request.', 'easyrankly' ),
						esc_html( size_format( $import_max ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form">
					<?php wp_nonce_field( 'erankly_io_import' ); ?>
					<input type="hidden" name="erankly_io_action" value="import">
					<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( (string) $import_max ); ?>">
					<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-import-file">
						<input type="file" id="erankly-import-file" name="erankly_import_file" accept=".json,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
						<span class="erankly-dropzone-icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 15.5V4M12 4L8 8M12 4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M4 15.5v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
						<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
							<strong><?php esc_html_e( 'Click to choose a file', 'easyrankly' ); ?></strong>
							<?php esc_html_e( 'or drag and drop a JSON file here', 'easyrankly' ); ?>
						</span>
					</label>
					<?php submit_button( __( 'Import file', 'easyrankly' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		</div>

		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Import from other plugins', 'easyrankly' ); ?></h3>
			<section class="erankly-card">
				<p class="description"><?php esc_html_e( 'Migrate Free and PRO data: titles, descriptions, canonicals, separate social images, robots directives, primary terms, schemas and redirects. Existing EasyRankly values and unrelated redirects are preserved and reported as conflicts.', 'easyrankly' ); ?></p>
				<?php if ( $has_unsupported_source ) : ?>
					<p class="notice notice-warning inline"><span><?php esc_html_e( 'SEO source data was detected with an unrecognized version or storage signature. EasyRankly will not guess: use a certified official export or update the adapter before migrating.', 'easyrankly' ); ?></span></p>
				<?php endif; ?>

			<?php if ( ! is_array( $active_job ) ) : ?>
				<h4><?php esc_html_e( 'From the installed database', 'easyrankly' ); ?></h4>
				<?php if ( $has_any_source ) : ?>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="erankly-io-third-party">
						<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
						<input type="hidden" name="erankly_io_action" value="migrate">
						<label class="screen-reader-text" for="erankly-io-source"><?php esc_html_e( 'Source plugin', 'easyrankly' ); ?></label>
						<select name="erankly_migration_source" id="erankly-io-source">
							<?php foreach ( $sources as $key => $label ) : ?>
								<?php if ( $source_availability[ $key ] ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default_source ); ?>><?php echo esc_html( $label ); ?></option>
								<?php else : ?>
									<option value="<?php echo esc_attr( $key ); ?>" disabled>
										<?php
										if ( 'unsupported' === (string) ( $source_profiles[ $key ]['storage_status'] ?? '' ) ) {
											/* translators: %s: source plugin name. */
											echo esc_html( sprintf( __( '%s: unsupported storage signature', 'easyrankly' ), $label ) );
										} else {
											/* translators: %s: source plugin name. */
											echo esc_html( sprintf( __( '%s: no data found', 'easyrankly' ), $label ) );
										}
										?>
									</option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-primary" name="erankly_migration_mode" value="preview"><?php esc_html_e( 'Preview migration', 'easyrankly' ); ?></button>
						<p class="description"><?php esc_html_e( 'The preview scans everything without writing data. When it is ready, the migration assistant will offer the reviewed import as the next step.', 'easyrankly' ); ?></p>
					</form>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No certified source-plugin database data was found. You can use an official export instead.', 'easyrankly' ); ?></p>
				<?php endif; ?>

				<h4><?php esc_html_e( 'From an official export file', 'easyrankly' ); ?></h4>
				<p class="description">
					<?php
					printf(
						/* translators: 1: maximum CSV upload size, 2: maximum JSON upload size. */
						esc_html__( 'Use an official CSV or JSON export when the original plugin is unavailable or its database signature is unsupported. EasyRankly validates the exact format and stores the file privately only until the job ends. Maximum size: CSV %1$s; JSON %2$s.', 'easyrankly' ),
						esc_html( size_format( $csv_upload_max ) ),
						esc_html( size_format( $json_upload_max ) )
					);
					?>
				</p>
				<form id="erankly-migration-export-form" method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form erankly-io-third-party">
					<?php wp_nonce_field( 'erankly_io_third_party_export' ); ?>
					<input type="hidden" name="erankly_io_action" value="migrate-export">
					<label for="erankly-migration-export-source"><?php esc_html_e( 'Export source', 'easyrankly' ); ?></label>
					<select name="erankly_migration_export_source" id="erankly-migration-export-source">
						<option value="auto"><?php esc_html_e( 'Detect automatically', 'easyrankly' ); ?></option>
						<?php foreach ( erankly_migration_manager()->adapters() as $key => $adapter ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $adapter->label() ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-migration-export-file">
						<input type="file" id="erankly-migration-export-file" name="erankly_migration_export_file" accept=".csv,.json,text/csv,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
						<span class="erankly-dropzone-icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 15.5V4M12 4L8 8M12 4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M4 15.5v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
						<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
							<strong><?php esc_html_e( 'Choose an official export', 'easyrankly' ); ?></strong>
							<?php esc_html_e( 'or drag and drop a CSV/JSON file here', 'easyrankly' ); ?>
						</span>
					</label>
					<button type="submit" class="button button-primary" name="erankly_migration_mode" value="preview"><?php esc_html_e( 'Preview file migration', 'easyrankly' ); ?></button>
					<p class="description"><?php esc_html_e( 'The private upload is deleted after preview. If every check passes, the assistant will ask you to upload the same file once more for the reviewed import.', 'easyrankly' ); ?></p>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'A migration is already active. Its checkpoint, controls and live counters are shown above; finish or cancel it before starting another source.', 'easyrankly' ); ?></p>
			<?php endif; ?>
			</section>
		</div>
	<?php
}

function erankly_import_export_render_notice(): void {
	$notice = isset( $_GET['erankly_io_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_io_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $notice ) {
		return;
	}

	if ( 'import-running' === $notice ) {
		$active   = ERankly_Import_Job_Runner::active_job();
		$finished = get_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, array() );
		if ( is_array( $active ) ) {
			$message = __( 'Import queued. It is continuing in bounded, restart-safe background batches.', 'easyrankly' );
			$class   = 'notice-info';
		} elseif ( is_array( $finished ) && 'complete' === (string) ( $finished['status'] ?? '' ) ) {
			$message = __( 'The background import completed successfully. Settings, redirects and metadata were restored from the saved checkpoint.', 'easyrankly' );
			$class   = 'notice-success';
		} else {
			$message = __( 'The background import stopped before completion. No unchecked batch will be retried; review the PHP/database log before uploading again.', 'easyrankly' );
			$class   = 'notice-error';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	if ( 'import-error' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The import job could not be persisted or completed. The private upload was removed and no further background write is scheduled.', 'easyrankly' ) . '</p></div>';
		return;
	}

	$report_id        = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report context.
	$report           = '' !== $report_id ? erankly_migration_manager()->get_report( $report_id ) : null;
	$reported_notices = array(
		'migration-started',
		'migration-running',
		'migration',
		'migration-partial',
		'migration-cancelled',
		'migration-error',
		'migration-live-verified',
		'migration-live-review',
		'migration-gate-blocked',
		'migration-source-still-active',
		'migration-rolled-back',
		'migration-rollback-expired',
		'migration-rollback-error',
		'migration-evidence-error',
	);
	if ( is_array( $report ) && ! is_array( erankly_migration_job_runner()->active_job() ) && in_array( $notice, $reported_notices, true ) ) {
		// A terminal report is the single source of user-facing status. This
		// also prevents a stale migration-started query argument from claiming
		// that a completed job is still queued.
		return;
	}

	if ( 'nonce' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed. Please try again.', 'easyrankly' ) . '</p></div>';
		return;
	}

	if ( 'invalid' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The file could not be imported. Please upload a valid EasyRankly export file.', 'easyrankly' ) . '</p></div>';
		return;
	}

	if ( 'too-large' === $notice ) {
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: maximum safe complete-import size. */
					__( 'The EasyRankly export exceeds the safe import limit of %s and was rejected before being read into memory.', 'easyrankly' ),
					size_format( erankly_import_export_max_bytes() )
				)
			)
		);
		return;
	}

	if ( 'too-complex' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The EasyRankly export is too structurally complex for the available PHP memory and was rejected before JSON decoding.', 'easyrankly' ) . '</p></div>';
		return;
	}

	$evidence_notices = array(
		'migration-live-verified'       => array( 'notice-success', __( 'Live verification passed: the sampled SEO output, redirects, robots.txt and sitemap are equivalent after the provider change.', 'easyrankly' ) ),
		'migration-live-running'        => array( 'notice-info', __( 'Live verification was queued and will continue in bounded background batches. Reload this report shortly to see the final result.', 'easyrankly' ) ),
		'migration-live-review'         => array( 'notice-warning', __( 'Live verification found differences or unreachable samples. Review the report before deleting the source plugin.', 'easyrankly' ) ),
		'migration-gate-blocked'        => array( 'notice-error', __( 'Live verification is unavailable because the go-live preflight gate is blocked. Resolve every blocking check and run a fresh migration before cutover.', 'easyrankly' ) ),
		'migration-source-still-active' => array( 'notice-warning', __( 'Live verification was not run because another SEO plugin still owns the frontend output. Deactivate the migrated source plugin, purge caches, then verify again.', 'easyrankly' ) ),
		'migration-rolled-back'         => array( 'notice-success', __( 'Conditional rollback finished. Later manual edits were preserved and are listed separately in the report.', 'easyrankly' ) ),
		'migration-rollback-running'    => array( 'notice-info', __( 'Conditional rollback started and will continue in bounded background batches. Its cursor is persisted after every batch.', 'easyrankly' ) ),
		'migration-rollback-expired'    => array( 'notice-error', __( 'The rollback safety window has expired. No value was changed.', 'easyrankly' ) ),
		'migration-rollback-error'      => array( 'notice-error', __( 'Rollback could not be completed. Review the journal summary before making manual changes.', 'easyrankly' ) ),
		'migration-evidence-error'      => array( 'notice-error', __( 'The requested migration evidence action is not valid for this report.', 'easyrankly' ) ),
	);
	if ( isset( $evidence_notices[ $notice ] ) ) {
		echo '<div class="notice ' . esc_attr( $evidence_notices[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $evidence_notices[ $notice ][1] ) . '</p></div>';
		return;
	}

	if ( 'migration-export-invalid' === $notice ) {
		$error    = isset( $_GET['erankly_upload_error'] ) ? sanitize_key( wp_unslash( $_GET['erankly_upload_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection from a fixed message map.
		$messages = array(
			'upload_too_large'                   => __( 'The official export exceeds the permitted upload size.', 'easyrankly' ),
			'source_mismatch'                    => __( 'The selected source does not match the certified signature of this export file.', 'easyrankly' ),
			'ambiguous_export_signature'         => __( 'The export signature is ambiguous. Select the source plugin explicitly and try again.', 'easyrankly' ),
			'private_storage_unavailable'        => __( 'A private non-public temporary directory is not available. Configure a writable system temporary directory before uploading source SEO data.', 'easyrankly' ),
			'private_storage_write_failed'       => __( 'The official export could not be stored in the private temporary directory.', 'easyrankly' ),
			'private_storage_permissions_failed' => __( 'The private temporary file could not be restricted to the current server account. No migration was started and the upload was removed.', 'easyrankly' ),
			'unsupported_extension'              => __( 'Only official CSV and JSON exports are accepted.', 'easyrankly' ),
			'unsupported_export_signature'       => __( 'The file does not match a certified Yoast, Rank Math, AIOSEO or SEOPress export signature.', 'easyrankly' ),
		);
		$message  = $messages[ $error ] ?? __( 'The official export upload failed validation. No migration was started and no file was retained.', 'easyrankly' );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	if ( in_array( $notice, array( 'migration-started', 'migration-running', 'migration-source-unsupported', 'migration-start-error', 'migration-action-error' ), true ) ) {
		if ( 'migration-started' === $notice ) {
			$message = __( 'Migration queued. It will continue in restart-safe background batches; you can leave this page.', 'easyrankly' );
			$class   = 'notice-success';
		} elseif ( 'migration-running' === $notice ) {
			$message = __( 'The migration is still running from its latest saved checkpoint.', 'easyrankly' );
			$class   = 'notice-info';
		} elseif ( 'migration-source-unsupported' === $notice ) {
			$message = __( 'Migration blocked: the detected source version or database signature is not certified. No data was written; use a supported official export or update EasyRankly.', 'easyrankly' );
			$class   = 'notice-error';
		} elseif ( 'migration-start-error' === $notice ) {
			$message = __( 'The migration could not be queued. Check database permissions and source-plugin data, then try again.', 'easyrankly' );
			$class   = 'notice-error';
		} else {
			$message = __( 'The migration command could not be saved. No unchecked write was performed; review the database/PHP log and retry from the saved checkpoint.', 'easyrankly' );
			$class   = 'notice-error';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	if ( in_array( $notice, array( 'migration', 'migration-partial', 'migration-cancelled', 'migration-error' ), true ) ) {
		$report_id    = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report lookup.
		$report       = erankly_migration_manager()->get_report( $report_id );
		$is_error     = 'migration-error' === $notice || ! is_array( $report );
		$is_partial   = 'migration-partial' === $notice;
		$is_cancelled = 'migration-cancelled' === $notice;
		if ( $is_error ) {
			$message = __( 'The migration could not be completed. Review the report for details.', 'easyrankly' );
		} elseif ( $is_cancelled ) {
			$message = __( 'Migration cancelled at its saved checkpoint. Values already written were kept; review the final report.', 'easyrankly' );
		} elseif ( $is_partial ) {
			$message = __( 'Migration completed with write errors. Existing EasyRankly data was preserved; review the report before switching SEO plugins.', 'easyrankly' );
		} elseif ( 'preview' === (string) $report['mode'] ) {
			$message = __( 'Migration preview complete. No EasyRankly data was changed.', 'easyrankly' );
		} else {
			$message = __( 'Migration complete. Existing EasyRankly data was preserved.', 'easyrankly' );
		}
		$notice_class = $is_error ? 'notice-error' : ( $is_partial || $is_cancelled ? 'notice-warning' : 'notice-success' );
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	$post_meta = isset( $_GET['er_post_meta'] ) ? absint( $_GET['er_post_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$term_meta = isset( $_GET['er_term_meta'] ) ? absint( $_GET['er_term_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$user_meta = isset( $_GET['er_user_meta'] ) ? absint( $_GET['er_user_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'imported' === $notice ) {
		$settings  = isset( $_GET['er_settings'] ) ? absint( $_GET['er_settings'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirects = isset( $_GET['er_redirects'] ) ? absint( $_GET['er_redirects'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message   = sprintf(
			/* translators: 1: settings count, 2: redirects count, 3: post meta count, 4: term meta count, 5: user meta count. */
			__( 'Import complete. Settings: %1$d. Redirects: %2$d. Post metadata: %3$d. Term metadata: %4$d. User metadata: %5$d.', 'easyrankly' ),
			$settings,
			$redirects,
			$post_meta,
			$term_meta,
			$user_meta
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	$source_labels = array(
		'yoast'    => __( 'Yoast SEO', 'easyrankly' ),
		'rankmath' => __( 'Rank Math', 'easyrankly' ),
		'aioseo'   => __( 'All in One SEO', 'easyrankly' ),
		'seopress' => __( 'SEOPress', 'easyrankly' ),
	);

	if ( isset( $source_labels[ $notice ] ) ) {
		$label   = $source_labels[ $notice ];
		$message = sprintf(
			/* translators: 1: source plugin name, 2: post meta count, 3: term meta count. */
			__( 'Imported from %1$s. Post metadata: %2$d. Term metadata: %3$d.', 'easyrankly' ),
			$label,
			$post_meta,
			$term_meta
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
