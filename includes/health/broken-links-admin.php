<?php
/**
 * Health broken-link administration functions.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Returns a human-readable status label for a broken-link result row.
 *
 * @param array<string,mixed> $item Result item.
 * @return string
 */
function erankly_health_bl_status_label( array $item ): string {
	$code = isset( $item['code'] ) ? (int) $item['code'] : 0;

	if ( 'unreachable' === ( $item['state'] ?? '' ) ) {
		return 429 === $code
			? __( 'Rate-limited (429)', 'easyrankly' )
			: __( 'Unreachable', 'easyrankly' );
	}

	return (string) $code;
}

/**
 * Renders the "Found on" cell: the pages where a broken link appears, with the
 * anchor text used and a link to edit each source page.
 *
 * @param array<int,array<string,mixed>> $occurrences Occurrence records.
 * @return void
 */
function erankly_health_bl_render_found_on( array $occurrences ): void {
	if ( empty( $occurrences ) ) {
		echo '<span class="description">&mdash;</span>';
		return;
	}

	$shown     = array_slice( $occurrences, 0, 3 );
	$remaining = count( $occurrences ) - count( $shown );

	foreach ( $shown as $occ ) {
		$source  = isset( $occ['source'] ) ? (string) $occ['source'] : '';
		$anchor  = isset( $occ['anchor'] ) ? (string) $occ['anchor'] : '';
		$post_id = '' !== $source ? url_to_postid( $source ) : 0;
		$edit    = $post_id ? (string) get_edit_post_link( $post_id ) : '';
		$label   = $post_id ? (string) get_the_title( $post_id ) : erankly_health_normalize_link_path( $source );

		if ( '' === $label ) {
			$label = $source;
		}

		echo '<div class="erankly-bl-occ">';
		if ( '' !== $anchor ) {
			printf( '<span class="erankly-bl-anchor">&ldquo;%s&rdquo;</span> ', esc_html( $anchor ) );
		} else {
			printf( '<span class="erankly-bl-anchor description">%s</span> ', esc_html__( '(no anchor text)', 'easyrankly' ) );
		}

		if ( '' !== $edit ) {
			printf( '<span class="description">&mdash; <a href="%1$s">%2$s</a></span>', esc_url( $edit ), esc_html( $label ) );
		} else {
			printf( '<span class="description">&mdash; %s</span>', esc_html( $label ) );
		}
		echo '</div>';
	}

	if ( $remaining > 0 ) {
		printf(
			'<span class="description">%s</span>',
			/* translators: %d: number of additional pages the broken link appears on. */
			esc_html( sprintf( _n( '+%d more page', '+%d more pages', $remaining, 'easyrankly' ), $remaining ) )
		);
	}
}

/**
 * Renders the "Action" cell for a broken-link row.
 *
 * Internal broken links reuse the 404 workflow (lexical + AI suggestion and a
 * pre-filled "Create 301 redirect" deep link). External broken links cannot be
 * fixed with a redirect, so the action links to editing the source page.
 *
 * @param array<string,mixed> $item          Result item.
 * @param bool                $redirects_on  Whether the Redirects module is on.
 * @return void
 */
function erankly_health_bl_render_action_cell( array $item, bool $redirects_on ): void {
	$url  = isset( $item['url'] ) ? (string) $item['url'] : '';
	$type = isset( $item['type'] ) ? (string) $item['type'] : 'external';

	if ( 'internal' === $type ) {
		$path       = erankly_health_normalize_link_path( $url );
		$suggestion = '' !== $path ? erankly_health_suggest_redirect_target( array( 'path' => $path ) ) : null;
		$ai_button  = false;
		$ai_tried   = false;

		if ( null === $suggestion && '' !== $path && function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) {
			$cache = erankly_health_ai_cached_suggestion( $path );

			if ( 'hit' === $cache['state'] ) {
				$suggestion = $cache['suggestion'];
			} elseif ( 'none' === $cache['state'] ) {
				$ai_tried = true;
			} else {
				$ai_button = true;
			}
		}

		if ( null !== $suggestion ) {
			printf(
				'<div class="erankly-bl-suggestion"><span class="description">%1$s</span> <a href="%2$s" target="_blank" rel="noopener noreferrer"><code>%3$s</code></a></div>',
				esc_html__( 'Suggested target:', 'easyrankly' ),
				esc_url( home_url( (string) $suggestion['target'] ) ),
				esc_html( (string) $suggestion['target'] )
			);
		} elseif ( $ai_tried ) {
			printf( '<div class="description">%s</div>', esc_html__( 'No match (AI included).', 'easyrankly' ) );
		}

		if ( ! $redirects_on ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( add_query_arg( 'erankly_tab', 'features', erankly_setup_wizard_settings_url() ) ),
				esc_html__( 'Enable Redirects to fix', 'easyrankly' )
			);
		} elseif ( '' !== $path ) {
			$normalized_source = class_exists( 'ERankly_Redirects_Normalizer' )
				? ERankly_Redirects_Normalizer::normalize_source( $path, false, false )
				: $path;

			$create_url = add_query_arg(
				array(
					'erankly_tab'                      => 'redirects',
					'erankly_redirects_prefill_source' => $normalized_source,
					'erankly_redirects_prefill_target' => null !== $suggestion ? (string) $suggestion['target'] : '',
					'erankly_redirects_prefill_status' => 301,
					'erankly_redirects_prefill_note'   => __( 'Created from Broken-Link crawler', 'easyrankly' ),
				),
				erankly_setup_wizard_settings_url()
			);

			printf(
				'<a class="button button-primary" href="%1$s">%2$s</a>',
				esc_url( $create_url ),
				esc_html__( 'Create 301 redirect', 'easyrankly' )
			);
		}

		if ( $ai_button ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="erankly-inline-form">
				<input type="hidden" name="action" value="erankly_health_bl_ai_suggest">
				<input type="hidden" name="url" value="<?php echo esc_attr( $url ); ?>">
				<?php wp_nonce_field( 'erankly_health_bl_ai_suggest' ); ?>
				<button type="submit" class="button-link"><?php esc_html_e( 'Suggest with AI', 'easyrankly' ); ?></button>
			</form>
			<?php
		}

		return;
	}

	// External broken link: fix means editing the page that contains it.
	$first   = isset( $item['occurrences'][0]['source'] ) ? (string) $item['occurrences'][0]['source'] : '';
	$post_id = '' !== $first ? url_to_postid( $first ) : 0;

	if ( $post_id && get_edit_post_link( $post_id ) ) {
		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a>',
			esc_url( (string) get_edit_post_link( $post_id ) ),
			esc_html__( 'Edit page', 'easyrankly' )
		);
	} elseif ( '' !== $first ) {
		printf(
			'<a class="button button-secondary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $first ),
			esc_html__( 'Open source page', 'easyrankly' )
		);
	} else {
		echo '<span class="description">&mdash;</span>';
	}
}

/**
 * Renders the "Broken-Link Candidates" section of the Health tab.
 *
 * @return void
 */
function erankly_health_bl_render_section(): void {
	$results      = erankly_health_bl_get_results();
	$redirects_on = function_exists( 'erankly_redirects_enabled' ) && erankly_redirects_enabled();
	$ai_on        = function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled();
	$run_status   = (string) erankly_health_bl_get_state()['status'];
	$was_cleared  = isset( $_GET['erankly_health_bl_cleared'] ) && '1' === sanitize_key( wp_unslash( $_GET['erankly_health_bl_cleared'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected POST redirect.
	$has_items    = null !== $results && ! empty( $results['items'] );
	?>
	<div class="erankly-settings-section erankly-panel-expandable" id="erankly-bl-table-wrap" data-erankly-expandable>
		<h3 class="erankly-section-title"><?php esc_html_e( 'Broken-Link Candidates', 'easyrankly' ); ?></h3>
		<section class="erankly-card">
		<?php if ( $was_cleared ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Broken-link scan data cleared.', 'easyrankly' ); ?></p></div>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: max pages crawled. 2: spider depth. 3: max links checked. */
				esc_html__( 'On demand, crawls the rendered HTML of your indexable pages (up to %1$d pages, %2$d levels deep) and checks the status of every distinct link found — internal and external, up to %3$d. Links returning 4xx/5xx are listed below with their anchor text and source page.', 'easyrankly' ),
				absint( ERANKLY_HEALTH_BL_MAX_PAGES ),
				absint( ERANKLY_HEALTH_BL_MAX_DEPTH ),
				absint( ERANKLY_HEALTH_BL_MAX_LINKS )
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Fix internal broken links with a 301 redirect (optionally AI-suggested); external ones link back to the page to edit. Results are cached briefly, so re-scanning is fast.', 'easyrankly' ); ?>
		</p>
		<?php if ( $ai_on ) : ?>
			<p class="description">
				<?php esc_html_e( 'AI suggestions run only when you click "Suggest with AI" on an internal broken link, and only send that link\'s slug words plus candidate page titles/paths to your configured AI provider.', 'easyrankly' ); ?>
			</p>
		<?php endif; ?>

		<div class="erankly-panel-toolbar">
			<div
				id="erankly-bl"
				class="erankly-panel-controls"
				data-rest-base="<?php echo esc_url( rest_url( 'erankly/v1/health/broken-links/' ) ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-status="<?php echo esc_attr( $run_status ); ?>"
			>
				<button type="button" class="button button-secondary" id="erankly-bl-start"><?php esc_html_e( 'Run broken-link scan', 'easyrankly' ); ?></button>
				<button type="button" class="button-link erankly-bl-cancel" id="erankly-bl-cancel" hidden><?php esc_html_e( 'Cancel', 'easyrankly' ); ?></button>
				<?php if ( $has_items ) : ?>
					<label for="erankly-bl-filter" class="screen-reader-text"><?php esc_html_e( 'Filter broken links', 'easyrankly' ); ?></label>
					<input type="search" id="erankly-bl-filter" class="erankly-panel-filter" data-erankly-filter placeholder="<?php esc_attr_e( 'Filter links…', 'easyrankly' ); ?>" autocomplete="off">
				<?php endif; ?>
			</div>
			<?php erankly_admin_render_panel_expand_toggle( 'erankly-bl-table-wrap' ); ?>
		</div>

		<div id="erankly-bl-progress" class="erankly-bl-progress" role="status" aria-live="polite" hidden></div>

		<table class="widefat striped erankly-panel-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Broken link', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Found on', 'easyrankly' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'easyrankly' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $has_items ) : ?>
					<tr>
						<td class="erankly-panel-empty" colspan="5">
							<?php
							if ( null === $results ) {
								esc_html_e( 'No scan has been run yet. Click "Run broken-link scan" to start.', 'easyrankly' );
							} else {
								esc_html_e( 'No broken links found.', 'easyrankly' );
							}
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $results['items'] as $item ) : ?>
						<tr class="erankly-bl-row" data-erankly-filter-row data-filter-text="<?php echo esc_attr( strtolower( (string) $item['url'] ) ); ?>">
							<td><a href="<?php echo esc_url( (string) $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) $item['url'] ); ?></code></a></td>
							<td><?php echo esc_html( erankly_health_bl_status_label( $item ) ); ?></td>
							<td>
								<?php
								echo 'internal' === ( $item['type'] ?? '' )
									? esc_html__( 'Internal', 'easyrankly' )
									: esc_html__( 'External', 'easyrankly' );
								?>
							</td>
							<td><?php erankly_health_bl_render_found_on( isset( $item['occurrences'] ) && is_array( $item['occurrences'] ) ? $item['occurrences'] : array() ); ?></td>
							<td><?php erankly_health_bl_render_action_cell( $item, $redirects_on ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( null !== $results ) : ?>
			<?php if ( 0 === (int) $results['fetch_ok'] && ( (int) $results['fetch_fallback'] + (int) $results['fetch_failed'] ) > 0 ) : ?>
				<div class="erankly-panel-callout">
					<svg class="erankly-panel-callout-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
						<path d="M12 9v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
						<path d="M12 17h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
					</svg>
					<p>
						<strong><?php esc_html_e( 'Couldn’t reach your site over HTTP.', 'easyrankly' ); ?></strong>
						<?php esc_html_e( 'Common on local or staging setups. Internal links were read from the database, so theme and page-builder links may be missing; external checks still need internet.', 'easyrankly' ); ?>
					</p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: date/time. 2: pages fetched over HTTP. 3: pages read from the database. 4: pages that failed. 5: links checked. 6: broken count. */
					esc_html__( 'Last scan: %1$s — %2$d pages fetched, %3$d read from database, %4$d unreadable; %5$d links checked, %6$d broken.', 'easyrankly' ),
					esc_html( erankly_health_format_timestamp( absint( $results['scanned_at'] ) ) ),
					absint( $results['fetch_ok'] ),
					absint( $results['fetch_fallback'] ),
					absint( $results['fetch_failed'] ),
					absint( $results['checked'] ),
					absint( $results['broken'] )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="erankly_health_bl_clear">
				<?php wp_nonce_field( 'erankly_health_bl_clear' ); ?>
				<?php submit_button( __( 'Clear broken-link scan data', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		</section>
	</div>
	<?php
}
