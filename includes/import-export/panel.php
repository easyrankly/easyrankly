<?php
/** Import / Export settings panel. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
	$sources             = array();
	$source_availability = array();
	$default_source      = '';
	foreach ( erankly_migration_manager()->adapters() as $key => $adapter ) {
		$version         = $adapter->version();
		$sources[ $key ] = trim( $adapter->label() . ( '' !== $version ? ' ' . $version : '' ) );

		$available                   = $adapter->is_available();
		$source_availability[ $key ] = $available;
		if ( $available && '' === $default_source ) {
			$default_source = $key;
		}
	}

	$has_any_source    = '' !== $default_source;
	$active_job        = erankly_migration_job_runner()->active_job();
	$active_import_job = ERankly_Import_Job_Runner::active_job();
	$import_max        = erankly_import_export_max_bytes();
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
		?>
		<p class="erankly-migration-back"><a href="<?php echo esc_url( erankly_import_export_url() ); ?>">&larr; <?php esc_html_e( 'Back to all import and export tools', 'easyrankly' ); ?></a></p>
		<?php
		return;
	}
	?>
		<div class="erankly-settings-section">
			<div class="erankly-section-title-row">
				<h2 class="erankly-section-title"><?php esc_html_e( 'Export', 'easyrankly' ); ?></h2>
				<?php erankly_render_section_doc_link( 'export' ); ?>
			</div>
			<section class="erankly-card">
				<p class="description"><?php esc_html_e( 'Download a JSON backup of your EasyRankly settings, redirects and SEO metadata.', 'easyrankly' ); ?></p>
				<?php if ( is_multisite() ) : ?>
					<p class="description"><?php esc_html_e( 'On this network the file holds the network-wide settings plus this primary site\'s content (redirects, post/term metadata, special page defaults), not a whole-network export of every site.', 'easyrankly' ); ?></p>
				<?php endif; ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export data', 'easyrankly' ); ?></a></p>
			</section>
		</div>

		<div class="erankly-settings-section">
			<div class="erankly-section-title-row">
				<h2 class="erankly-section-title"><?php esc_html_e( 'Import', 'easyrankly' ); ?></h2>
				<?php erankly_render_section_doc_link( 'import' ); ?>
			</div>
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

		<?php if ( $has_any_source ) : ?>
		<div class="erankly-settings-section">
			<div class="erankly-section-title-row">
				<h2 class="erankly-section-title"><?php esc_html_e( 'Import from other plugins', 'easyrankly' ); ?></h2>
				<?php erankly_render_section_doc_link( 'import-other-plugins' ); ?>
			</div>
			<section class="erankly-card">
				<p class="description"><?php esc_html_e( 'Migrate Free and PRO data: titles, descriptions, canonicals, separate social images, robots directives, primary terms, schemas and redirects. Existing EasyRankly values and unrelated redirects are preserved and reported as conflicts.', 'easyrankly' ); ?></p>
			<?php if ( ! is_array( $active_job ) ) : ?>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="erankly-io-third-party">
						<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
						<input type="hidden" name="erankly_io_action" value="migrate">
						<label class="screen-reader-text" for="erankly-io-source"><?php esc_html_e( 'Source plugin', 'easyrankly' ); ?></label>
						<select name="erankly_migration_source" id="erankly-io-source">
							<?php foreach ( $sources as $key => $label ) : ?>
								<?php if ( $source_availability[ $key ] ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default_source ); ?>><?php echo esc_html( $label ); ?></option>
								<?php else : ?>
									<?php /* translators: %s: source plugin name. */ ?>
									<option value="<?php echo esc_attr( $key ); ?>" disabled><?php echo esc_html( sprintf( __( '%s: no data found', 'easyrankly' ), $label ) ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-primary" name="erankly_migration_mode" value="preview"><?php esc_html_e( 'Preview migration', 'easyrankly' ); ?></button>
						<p class="description"><?php esc_html_e( 'The preview scans everything without writing data. When it is ready, the migration assistant will offer the reviewed import as the next step.', 'easyrankly' ); ?></p>
					</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'A migration is already active. Its checkpoint, controls and live counters are shown above; finish or cancel it before starting another source.', 'easyrankly' ); ?></p>
			<?php endif; ?>
			</section>
		</div>
		<?php endif; ?>
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
			$counts  = is_array( $finished['counts'] ?? null ) ? $finished['counts'] : array();
			$skipped = absint( $counts['redirects_unsupported'] ?? 0 ) + absint( $counts['redirects_invalid'] ?? 0 );
			$message = sprintf(
				/* translators: 1: imported redirects, 2: transformed redirects, 3: skipped redirects. */
				__( 'The background import completed. Redirects: %1$d (%2$d safely transformed, %3$d skipped for review). Settings and metadata were restored from the saved checkpoint.', 'easyrankly' ),
				absint( $counts['redirects'] ?? 0 ),
				absint( $counts['redirects_transformed'] ?? 0 ),
				$skipped
			);
			$class = $skipped > 0 ? 'notice-warning' : 'notice-success';
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

	if ( 'custom-code-capability' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'This backup contains custom code. Your account needs the unfiltered HTML capability before it can be restored.', 'easyrankly' ) . '</p></div>';
		return;
	}

	if ( 'transfer-starting' === $notice ) {
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Another import or migration is being initialized. Wait a moment, then reload this page before starting a new data transfer.', 'easyrankly' ) . '</p></div>';
		return;
	}

	if ( 'unsupported-format' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'This backup format is not supported. Import an EasyRankly 2.0, 3.0 or 4.0 export.', 'easyrankly' ) . '</p></div>';
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
		'migration-backup-expired',
		'migration-restore-error',
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

	$backup_notices = array(
		'migration-restore-running' => array( 'notice-info', __( 'Restoring the pre-import backup. It continues in restart-safe background batches; reload this page to follow it.', 'easyrankly' ) ),
		'migration-backup-expired'  => array( 'notice-error', __( 'The pre-import backup is no longer stored. No value was changed.', 'easyrankly' ) ),
		'migration-restore-error'   => array( 'notice-error', __( 'The pre-import backup could not be restored. Download it from the report and import it manually.', 'easyrankly' ) ),
	);
	if ( isset( $backup_notices[ $notice ] ) ) {
		echo '<div class="notice ' . esc_attr( $backup_notices[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $backup_notices[ $notice ][1] ) . '</p></div>';
		return;
	}

	if ( in_array( $notice, array( 'migration-started', 'migration-running', 'migration-backup-failed', 'migration-backup-too-large', 'migration-start-error', 'migration-action-error' ), true ) ) {
		if ( 'migration-started' === $notice ) {
			$message = __( 'Migration queued. It will continue in restart-safe background batches; you can leave this page.', 'easyrankly' );
			$class   = 'notice-success';
		} elseif ( 'migration-running' === $notice ) {
			$message = __( 'The migration is still running from its latest saved checkpoint.', 'easyrankly' );
			$class   = 'notice-info';
		} elseif ( 'migration-backup-failed' === $notice ) {
			$message = __( 'Migration blocked: the automatic pre-import backup could not be written, so there would be no way to undo the import. Check that PHP can write to the system temporary directory, then try again.', 'easyrankly' );
			$class   = 'notice-error';
		} elseif ( 'migration-backup-too-large' === $notice ) {
			$message = __( 'Migration blocked: the complete pre-import backup exceeds this server’s safe import limit, so it could not be restored reliably. Increase the import limit or available PHP memory, then try again.', 'easyrankly' );
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
		$transformed = isset( $_GET['er_redirects_transformed'] ) ? absint( $_GET['er_redirects_transformed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped     = isset( $_GET['er_redirects_skipped'] ) ? absint( $_GET['er_redirects_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message   = sprintf(
			/* translators: 1: settings count, 2: redirects count, 3: transformed redirects, 4: skipped redirects, 5: post meta count, 6: term meta count, 7: user meta count. */
			__( 'Import complete. Settings: %1$d. Redirects: %2$d (%3$d safely transformed, %4$d skipped for review). Post metadata: %5$d. Term metadata: %6$d. User metadata: %7$d.', 'easyrankly' ),
			$settings,
			$redirects,
			$transformed,
			$skipped,
			$post_meta,
			$term_meta,
			$user_meta
		);
		echo '<div class="notice ' . ( $skipped > 0 ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
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
