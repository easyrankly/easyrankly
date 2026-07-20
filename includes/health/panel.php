<?php
/**
 * Health settings panel rendering functions.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Health settings tab.
 *
 * @return void
 */
function erankly_health_render_panel(): void {
	$frequent_404s = erankly_health_get_frequent_404s();
	$thin_content  = erankly_health_get_thin_content();
	$was_cleared   = isset( $_GET['erankly_health_clear'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_clear'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$was_scanned   = isset( $_GET['erankly_health_scanned'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_scanned'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$health_state  = isset( $_GET['erankly_health_state'] ) ? sanitize_key( wp_unslash( $_GET['erankly_health_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$health_ai     = isset( $_GET['erankly_health_ai'] ) ? sanitize_key( wp_unslash( $_GET['erankly_health_ai'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	?>
	<?php if ( $was_cleared ) : ?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'Frequent 404 scanner data cleared.', 'easyrankly' ); ?></p>
		</div>
	<?php endif; ?>
	<?php if ( $was_scanned ) : ?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'Content insights scan completed.', 'easyrankly' ); ?></p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( $health_state, array( 'ignored', 'resolved', 'restored' ), true ) ) : ?>
		<div class="notice notice-success inline">
			<p>
				<?php
				if ( 'ignored' === $health_state ) {
					esc_html_e( '404 marked as ignored.', 'easyrankly' );
				} elseif ( 'resolved' === $health_state ) {
					esc_html_e( '404 marked as resolved.', 'easyrankly' );
				} else {
					esc_html_e( '404 restored to the active list.', 'easyrankly' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( $health_ai, array( 'suggested', 'none', 'error', 'disabled' ), true ) ) : ?>
		<?php $erankly_ai_notice_class = 'suggested' === $health_ai ? 'notice-success' : ( 'error' === $health_ai ? 'notice-error' : ( 'disabled' === $health_ai ? 'notice-warning' : 'notice-info' ) ); ?>
		<div class="notice <?php echo esc_attr( $erankly_ai_notice_class ); ?> inline">
			<p>
				<?php
				if ( 'suggested' === $health_ai ) {
					esc_html_e( 'AI suggestion ready.', 'easyrankly' );
				} elseif ( 'none' === $health_ai ) {
					esc_html_e( 'The AI found no sensible match for this 404.', 'easyrankly' );
				} elseif ( 'disabled' === $health_ai ) {
					esc_html_e( 'Enable AI features to use AI suggestions.', 'easyrankly' );
				} else {
					esc_html_e( 'The AI request failed. Please try again.', 'easyrankly' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>
	<div class="erankly-settings-fields">
		<div class="erankly-settings-section erankly-panel-expandable" id="erankly-404-table-wrap" data-erankly-expandable>
			<h3 class="erankly-section-title"><?php esc_html_e( 'Frequent 404 scanner', 'easyrankly' ); ?></h3>
			<section class="erankly-card">
			<p class="description">
				<?php
				printf(
					/* translators: 1: 404 threshold. 2: Monitoring window in hours. */
					esc_html__( 'Lists only paths reaching at least %1$d estimated 404 hits within %2$d hours. Lower-volume 404s are sampled into short-lived counters and not listed individually.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_404_THRESHOLD ),
					absint( ERANKLY_HEALTH_404_WINDOW / HOUR_IN_SECONDS )
				);
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %d: Retention period in days. */
					esc_html__( 'Privacy: paths are anonymized before storage (emails, UUIDs, long numbers and tokens become neutral placeholders). Only same-site referrer paths are recorded; external referrers are never stored. Data is purged after %d days.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_404_RETENTION_DAYS )
				);
				?>
			</p>
			<?php if ( function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) : ?>
				<p class="description">
					<?php esc_html_e( 'AI suggestions: clicking "Suggest with AI" sends the broken URL\'s slug words and the titles/paths of candidate pages to your configured AI provider. It runs only on click.', 'easyrankly' ); ?>
				</p>
			<?php endif; ?>

			<?php
			$erankly_redirects_on   = function_exists( 'erankly_redirects_enabled' ) && erankly_redirects_enabled();
			$erankly_404_partition  = erankly_health_partition_404s( $frequent_404s );
			$erankly_404_active     = $erankly_404_partition['active'];
			$erankly_404_handled    = (int) $erankly_404_partition['handled'];
			$erankly_404_managed    = $erankly_404_partition['managed'];
			$erankly_404_has_active = ! empty( $erankly_404_active );
			?>
			<div class="erankly-panel-toolbar">
				<div class="erankly-panel-controls">
					<?php if ( $erankly_404_has_active ) : ?>
						<label for="erankly-404-filter" class="screen-reader-text"><?php esc_html_e( 'Filter 404 paths', 'easyrankly' ); ?></label>
						<input type="search" id="erankly-404-filter" class="erankly-panel-filter" data-erankly-filter placeholder="<?php esc_attr_e( 'Filter paths…', 'easyrankly' ); ?>" autocomplete="off">
					<?php endif; ?>
				</div>
				<?php erankly_admin_render_panel_expand_toggle( 'erankly-404-table-wrap' ); ?>
			</div>

			<table class="widefat striped erankly-panel-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Path', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Estimated hits', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'First seen', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last seen', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Suggestion', 'easyrankly' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'easyrankly' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $erankly_404_has_active ) : ?>
						<tr>
							<td class="erankly-panel-empty" colspan="6">
								<?php
								if ( empty( $frequent_404s ) ) {
									esc_html_e( 'No frequent 404s detected in the current monitoring window.', 'easyrankly' );
								} else {
									esc_html_e( 'All frequent 404s are currently handled by an active redirect.', 'easyrankly' );
								}
								?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $erankly_404_active as $entry ) : ?>
							<?php
							$erankly_path       = (string) $entry['path'];
							$erankly_anonymized = erankly_health_path_is_anonymized( $erankly_path );
							$erankly_suggestion = erankly_health_suggest_redirect_target( $entry );
							$erankly_ai_button  = false;
							$erankly_ai_tried   = false;

							// Fallback: when the lexical engine found nothing and AI is on, surface a
							// cached AI suggestion or offer the on-demand "Suggest with AI" button.
							if ( null === $erankly_suggestion && ! $erankly_anonymized && function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) {
								$erankly_ai_cache = erankly_health_ai_cached_suggestion( $erankly_path );

								if ( 'hit' === $erankly_ai_cache['state'] ) {
									$erankly_suggestion = $erankly_ai_cache['suggestion'];
								} elseif ( 'none' === $erankly_ai_cache['state'] ) {
									$erankly_ai_tried = true;
								} else {
									$erankly_ai_button = true;
								}
							}
							?>
							<tr data-erankly-filter-row data-filter-text="<?php echo esc_attr( strtolower( $erankly_path ) ); ?>">
								<td>
									<code><?php echo esc_html( $erankly_path ); ?></code>
									<?php
									$erankly_refs = isset( $entry['referrers'] ) && is_array( $entry['referrers'] ) ? $entry['referrers'] : array();
									arsort( $erankly_refs );
									$erankly_refs = array_slice( array_keys( $erankly_refs ), 0, 3 );
									?>
									<?php if ( ! empty( $erankly_refs ) ) : ?>
										<br>
										<span class="description">
											<?php
											printf(
												/* translators: %s: comma-separated internal pages linking to this 404. */
												esc_html__( 'Linked from: %s', 'easyrankly' ),
												esc_html( implode( ', ', $erankly_refs ) )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( absint( $entry['count'] ) ) ); ?></td>
								<td><?php echo esc_html( erankly_health_format_timestamp( absint( $entry['first_seen'] ) ) ); ?></td>
								<td><?php echo esc_html( erankly_health_format_timestamp( absint( $entry['last_seen'] ) ) ); ?></td>
								<td><?php erankly_health_render_404_suggestion_cell( $erankly_suggestion, $erankly_anonymized, $erankly_ai_tried ); ?></td>
								<td><?php erankly_health_render_404_action_cell( $entry, $erankly_suggestion, $erankly_anonymized, $erankly_redirects_on, $erankly_ai_button ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $erankly_404_handled > 0 ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %d: number of 404 paths now covered by an active redirect. */
						esc_html( _n( '%d frequent 404 is now handled by a redirect.', '%d frequent 404s are now handled by a redirect.', $erankly_404_handled, 'easyrankly' ) ),
						absint( $erankly_404_handled )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=erankly&erankly_tab=redirects' ) ); ?>"><?php esc_html_e( 'View redirect hit metrics', 'easyrankly' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $erankly_404_managed ) ) : ?>
				<details class="erankly-health-managed erankly-mt-sm">
					<summary>
						<?php
						/* translators: %d: number of ignored or resolved 404 paths. */
						echo esc_html( sprintf( _n( 'Ignored / resolved (%d)', 'Ignored / resolved (%d)', count( $erankly_404_managed ), 'easyrankly' ), absint( count( $erankly_404_managed ) ) ) );
						?>
					</summary>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Path', 'easyrankly' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'easyrankly' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Action', 'easyrankly' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $erankly_404_managed as $managed_entry ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $managed_entry['path'] ); ?></code></td>
									<td>
										<?php
										echo 'ignored' === ( $managed_entry['state'] ?? '' )
											? esc_html__( 'Ignored', 'easyrankly' )
											: esc_html__( 'Resolved', 'easyrankly' );
										?>
									</td>
									<td><?php erankly_health_render_404_state_forms( isset( $managed_entry['hash'] ) ? (string) $managed_entry['hash'] : '', (string) ( $managed_entry['state'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="erankly_health_clear_404s">
				<?php wp_nonce_field( 'erankly_health_clear_404s' ); ?>
				<?php submit_button( __( 'Clear 404 scanner data', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
			</section>
		</div>
		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Content insights (heuristic)', 'easyrankly' ); ?></h3>
			<fieldset class="erankly-field erankly-card">
			<legend class="screen-reader-text"><?php esc_html_e( 'Content insights (heuristic)', 'easyrankly' ); ?></legend>
			<p class="description">
				<?php
				printf(
					/* translators: 1: Minimum character threshold. */
					esc_html__( 'A heuristic, not a definitive SEO diagnosis. Pages are flagged as potentially thin when they meet at least 2 of 3 conditions: under %1$d characters of visible text, no internal inbound links, and no internal outbound links. Results are cached; re-run the scan to refresh.', 'easyrankly' ),
					absint( ERANKLY_HEALTH_THIN_MIN_CHARS )
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Elementor, Divi and WPBakery pages are excluded. Their content lives in post meta, not the post body, causing false positives. Gutenberg block content is analysed correctly.', 'easyrankly' ); ?>
			</p>

			<?php if ( null === $thin_content ) : ?>
				<p><?php esc_html_e( 'No scan has been run yet. Click the button below to start.', 'easyrankly' ); ?></p>
			<?php elseif ( empty( $thin_content['pages'] ) ) : ?>
				<p><?php esc_html_e( 'No heuristically thin content detected.', 'easyrankly' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Page', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Characters', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Inbound links', 'easyrankly' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Outbound links', 'easyrankly' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $thin_content['pages'] as $page ) : ?>
							<tr>
								<td>
									<?php if ( ! empty( $page['edit_url'] ) ) : ?>
										<a href="<?php echo esc_url( (string) $page['edit_url'] ); ?>"><?php echo esc_html( (string) $page['title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( (string) $page['title'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( absint( $page['char_count'] ) ) ); ?></td>
								<td>
									<?php if ( $page['has_inbound'] ) : ?>
										<?php esc_html_e( 'Yes', 'easyrankly' ); ?>
									<?php else : ?>
										<strong><?php esc_html_e( 'No', 'easyrankly' ); ?></strong>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $page['has_outbound'] ) : ?>
										<?php esc_html_e( 'Yes', 'easyrankly' ); ?>
									<?php else : ?>
										<strong><?php esc_html_e( 'No', 'easyrankly' ); ?></strong>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( null !== $thin_content ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: Number of pages analysed. 2: Formatted date/time of last scan. */
						esc_html__( 'Last scan: %2$s, %1$d pages analysed.', 'easyrankly' ),
						absint( $thin_content['scanned_count'] ),
						esc_html( erankly_health_format_timestamp( absint( $thin_content['scanned_at'] ) ) )
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="erankly_health_scan_thin">
				<?php wp_nonce_field( 'erankly_health_scan_thin' ); ?>
				<?php submit_button( __( 'Run content insights scan', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		</fieldset>
		</div>
		<?php erankly_health_bl_render_section(); ?>
	</div>
	<?php
}
