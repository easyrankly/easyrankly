<?php
/** Admin page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Tools page for redirect management. */
final class ERankly_Redirects_Admin {

	private const SLUG = 'erankly';

	private ERankly_Redirects_Repository $repository;

	public function __construct( ERankly_Redirects_Repository $repository ) {
		$this->repository = $repository;
	}

	/** @return array<int,string> Translated labels shared by every Redirects control. */
	public static function status_code_labels(): array {
		return array(
			301 => __( '301: Moved permanently', 'easyrankly' ),
			302 => __( '302: Found (temporary)', 'easyrankly' ),
			307 => __( '307: Temporary redirect (preserve method)', 'easyrankly' ),
			308 => __( '308: Permanent redirect (preserve method)', 'easyrankly' ),
			410 => __( '410: Gone', 'easyrankly' ),
			451 => __( '451: Unavailable for legal reasons', 'easyrankly' ),
		);
	}

	private static function match_type_label( string $match_type ): string {
		$labels = array(
			'exact'    => __( 'Exact URL', 'easyrankly' ),
			'wildcard' => __( 'Wildcard pattern', 'easyrankly' ),
			'regex'    => __( 'Regular expression', 'easyrankly' ),
		);

		return $labels[ $match_type ] ?? $match_type;
	}

	private static function query_mode_label( string $query_mode ): string {
		$labels = array(
			'ignore'   => __( 'Any, discarded', 'easyrankly' ),
			'preserve' => __( 'Any, appended to target', 'easyrankly' ),
			'exact'    => __( 'Exact required query', 'easyrankly' ),
		);

		return $labels[ $query_mode ] ?? $query_mode;
	}

	private static function format_last_hit( string $mysql_date ): string {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_date, wp_timezone() );
		if ( ! $date instanceof DateTimeImmutable ) {
			return $mysql_date;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$date->getTimestamp(),
			wp_timezone()
		);
	}

	/**
 * Register admin hooks. The menu entry and asset loading are handled by the EasyRankly settings page; this class
 * only processes redirect actions and renders the panel content inside the "Redirects" settings tab.
 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.

		if ( self::SLUG !== $page ) {
			return;
		}

		if ( isset( $_POST['erankly_redirects_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified inside the action handler before mutation.
			$action = sanitize_key( wp_unslash( $_POST['erankly_redirects_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified inside the action handler before mutation.

			if ( 'save_redirect' === $action ) {
				$this->handle_save_redirect();
			}
		}

		if ( isset( $_GET['erankly_redirects_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified inside delete/toggle handlers before mutation.
			$action = sanitize_key( wp_unslash( $_GET['erankly_redirects_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified inside delete/toggle handlers before mutation.

			if ( 'delete' === $action ) {
				$this->handle_delete_redirect();
			}

			if ( 'toggle' === $action ) {
				$this->handle_toggle_redirect();
			}
		}
	}

	/**
 * Render the redirect management UI inside the EasyRankly settings page. No <div class="wrap"> or <h1> wrapper
 * is emitted because this content is rendered within the existing settings page markup. The panel shows the
 * add/edit form and the redirect table; global redirect settings live on the main Settings tab and import/export
 * lives on the Import / Export tab.
 */
	public function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$edit_id       = isset( $_GET['erankly_redirects_edit'] ) ? absint( $_GET['erankly_redirects_edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit form selection.
		$edit_redirect = $edit_id > 0 ? $this->repository->find_by_id( $edit_id ) : null;
		$prefill       = null === $edit_redirect ? $this->read_prefill() : null;
		$form_state    = $this->consume_form_state();
		if ( is_array( $form_state ) && (int) ( $form_state['id'] ?? 0 ) === $edit_id && is_array( $form_state['values'] ?? null ) ) {
			if ( $edit_id > 0 && is_array( $edit_redirect ) ) {
				$edit_redirect = array_merge( $edit_redirect, $form_state['values'] );
			} elseif ( 0 === $edit_id ) {
				$prefill = $form_state['values'];
			}
		}
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect search term.
		$current_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$orderby       = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table sorting; validated against a column whitelist in the repository.
		$order         = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table sorting.
		$per_page      = 25;
		$total_items   = $this->repository->count_redirects( $search );
		$total_pages   = max( 1, (int) ceil( $total_items / $per_page ) );
		$current_page  = min( $current_page, $total_pages );
		$redirects     = $this->repository->list_redirects( $search, $current_page, $per_page, $orderby, $order );
		$table_state   = $this->table_state_args( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table state.
		unset( $table_state['paged'] );
		if ( $current_page > 1 ) {
			$table_state['paged'] = $current_page;
		}

		?>
			<?php $this->render_notices(); ?>

			<div class="erankly-settings-section">
				<div class="erankly-section-title-row">
					<h2 class="erankly-section-title"><?php echo $edit_redirect ? esc_html__( 'Edit Redirect', 'easyrankly' ) : esc_html__( 'Add Redirect', 'easyrankly' ); ?></h2>
					<?php erankly_render_section_doc_link( 'redirect-form' ); ?>
				</div>
				<section class="erankly-card">
					<p class="description"><?php esc_html_e( 'Redirects do not run for logged-in administrators. Test saved rules in a signed-out or incognito window.', 'easyrankly' ); ?></p>
					<?php $this->render_redirect_form( $edit_redirect, $prefill, $table_state ); ?>
				</section>
			</div>

			<div class="erankly-settings-section erankly-panel-expandable" id="erankly-redirects-table-wrap" data-erankly-expandable>
				<div class="erankly-section-title-row">
					<h2 class="erankly-section-title"><?php esc_html_e( 'Redirect rules', 'easyrankly' ); ?></h2>
					<?php erankly_render_section_doc_link( 'redirect-rules' ); ?>
				</div>
				<section class="erankly-card">
				<div class="erankly-panel-toolbar erankly-redirects-toolbar">
					<form method="get" class="erankly-redirects-search">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<input type="hidden" name="erankly_tab" value="redirects">
						<?php if ( '' !== $orderby ) : ?>
							<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
							<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
						<?php endif; ?>
						<label for="erankly-redirects-search-source" class="screen-reader-text"><?php esc_html_e( 'Search redirect rules', 'easyrankly' ); ?></label>
						<input id="erankly-redirects-search-source" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search rules…', 'easyrankly' ); ?>">
						<?php submit_button( __( 'Search', 'easyrankly' ), 'secondary', '', false ); ?>
						<?php if ( '' !== $search ) : ?>
							<a class="button" href="<?php echo esc_url( $this->admin_url() ); ?>"><?php esc_html_e( 'Clear', 'easyrankly' ); ?></a>
						<?php endif; ?>
					</form>
					<?php erankly_admin_render_panel_expand_toggle( 'erankly-redirects-table-wrap' ); ?>
				</div>
				<?php $this->render_item_count( $current_page, $per_page, count( $redirects ), $total_items ); ?>

				<?php $this->render_redirect_table( $redirects, $orderby, $order, $search, $table_state ); ?>
				<?php $this->render_pagination( $current_page, $total_pages, $search, $orderby, $order ); ?>
				</section>
			</div>

			<div class="erankly-redirects-dialog-overlay" data-erankly-redirect-delete-modal hidden>
				<div class="erankly-redirects-dialog" role="alertdialog" aria-modal="true" aria-labelledby="erankly-redirect-delete-title" aria-describedby="erankly-redirect-delete-description">
					<h2 id="erankly-redirect-delete-title"><?php esc_html_e( 'Delete redirect?', 'easyrankly' ); ?></h2>
					<p id="erankly-redirect-delete-description" data-erankly-redirect-delete-description></p>
					<div class="erankly-redirects-dialog-actions">
						<button type="button" class="button" data-erankly-redirect-delete-cancel><?php esc_html_e( 'Cancel', 'easyrankly' ); ?></button>
						<button type="button" class="button erankly-btn-danger" data-erankly-redirect-delete-confirm><?php esc_html_e( 'Delete redirect', 'easyrankly' ); ?></button>
					</div>
				</div>
			</div>
		<?php
	}

	/** @return array<string,string|int> */
	private function table_state_args( array $input ): array {
		$args = array();

		if ( isset( $input['s'] ) && '' !== trim( (string) $input['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( (string) $input['s'] ) );
		}
		if ( isset( $input['paged'] ) && absint( $input['paged'] ) > 1 ) {
			$args['paged'] = absint( $input['paged'] );
		}
		if ( isset( $input['orderby'] ) && in_array( sanitize_key( (string) $input['orderby'] ), array( 'hit_count', 'last_hit_at' ), true ) ) {
			$args['orderby'] = sanitize_key( (string) $input['orderby'] );
			$args['order']   = isset( $input['order'] ) && 'asc' === strtolower( sanitize_key( (string) $input['order'] ) ) ? 'asc' : 'desc';
		}

		return $args;
	}

	/** @return array<string,mixed>|null */
	private function consume_form_state(): ?array {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return null;
		}

		$key   = 'erankly_redirect_form_' . $user_id;
		$state = get_transient( $key );
		delete_transient( $key );

		return is_array( $state ) ? $state : null;
	}

	private function store_form_state( int $id, array $input ): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		$match_type   = isset( $input['match_type'] ) ? sanitize_key( (string) $input['match_type'] ) : 'exact';
		$source_path  = isset( $input['source_path'] ) ? sanitize_text_field( wp_unslash( (string) $input['source_path'] ) ) : '';
		$source_query = isset( $input['source_query'] ) ? ltrim( sanitize_text_field( wp_unslash( (string) $input['source_query'] ) ), '?' ) : '';
		$query_mode   = isset( $input['query_mode'] ) ? sanitize_key( (string) $input['query_mode'] ) : 'ignore';
		$embedded     = 'regex' === $match_type ? '' : ERankly_Redirects_Normalizer::extract_query( $source_path );
		if ( '' !== $embedded && '' === $source_query ) {
			$source_query = sanitize_text_field( $embedded );
			$query_mode   = 'exact';
			$source_path  = preg_replace( '/[?#].*$/', '', $source_path ) ?? $source_path;
		}

		set_transient(
			'erankly_redirect_form_' . $user_id,
			array(
				'id'     => $id,
				'values' => array(
					'source_path'    => $source_path,
					'target_url'     => isset( $input['target_url'] ) ? sanitize_text_field( wp_unslash( (string) $input['target_url'] ) ) : '',
					'status_code'   => isset( $input['status_code'] ) ? absint( $input['status_code'] ) : 301,
					'match_type'    => $match_type,
					'query_mode'    => $query_mode,
					'source_query'  => $source_query,
					'case_sensitive' => ! empty( $input['case_sensitive'] ) ? 1 : 0,
					'trailing_slash' => isset( $input['trailing_slash'] ) && 'exact' === $input['trailing_slash'] ? 'exact' : 'ignore',
					'is_active'      => ! empty( $input['is_active'] ) ? 1 : 0,
					'note'           => isset( $input['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $input['note'] ) ) : '',
				),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private function render_item_count( int $current_page, int $per_page, int $visible_items, int $total_items ): void {
		if ( 0 === $total_items ) {
			echo '<p class="displaying-num">' . esc_html__( '0 redirects', 'easyrankly' ) . '</p>';
			return;
		}

		$first = ( ( $current_page - 1 ) * $per_page ) + 1;
		$last  = $first + max( 0, $visible_items - 1 );
		printf(
			'<p class="displaying-num">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: first visible redirect, 2: last visible redirect, 3: total matching redirects. */
					__( 'Showing %1$d–%2$d of %3$d redirects.', 'easyrankly' ),
					$first,
					$last,
					$total_items
				)
			)
		);
	}

	private function render_notices(): void {
		$migration_report = get_option( 'erankly_redirects_v3_migration_report', array() );
		if ( is_array( $migration_report ) && ( ! empty( $migration_report['transformed'] ) || ! empty( $migration_report['disabled'] ) ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: transformed legacy redirects, 2: disabled conditional redirects. */
						__( 'Redirect upgrade completed: %1$d legacy matching rules were converted and %2$d conditional, scheduled, or duplicate rules were disabled for manual review.', 'easyrankly' ),
						count( $migration_report['transformed'] ?? array() ),
						count( $migration_report['disabled'] ?? array() )
					)
				)
			);
		}

		$notice = isset( $_GET['erankly_redirects_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_redirects_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display after redirect.
		$error  = isset( $_GET['erankly_redirects_error'] ) ? sanitize_key( wp_unslash( $_GET['erankly_redirects_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display after redirect.

		if ( '' !== $error ) {
			$messages = array(
				'source_required'       => __( 'Enter a source URL or path.', 'easyrankly' ),
				'source_too_long'       => __( 'The source URL is too long.', 'easyrankly' ),
				'source_query_too_long' => __( 'The required query string is too long.', 'easyrankly' ),
				'target_required'       => __( 'Enter a valid target URL for this redirect code.', 'easyrankly' ),
				'status_code'           => __( 'Choose a supported HTTP status code.', 'easyrankly' ),
				'source_wildcard'       => __( 'Enter a valid wildcard source pattern.', 'easyrankly' ),
				'source_regex'          => __( 'Enter a valid and safe regular expression.', 'easyrankly' ),
				'source_path'           => __( 'Enter an internal source path beginning with a slash.', 'easyrankly' ),
				'matching_mode'         => __( 'Choose supported path and query matching modes.', 'easyrankly' ),
				'invalid'               => __( 'Please check the redirect fields and try again.', 'easyrankly' ),
				'nonce'                 => __( 'Security check failed. Please try again.', 'easyrankly' ),
				'save'                  => __( 'The redirect could not be saved. A duplicate matching rule may already exist.', 'easyrankly' ),
				'delete'                => __( 'The redirect could not be deleted.', 'easyrankly' ),
				'toggle'                => __( 'The redirect status could not be changed.', 'easyrankly' ),
			);
			$message  = $messages[ $error ] ?? __( 'An error occurred.', 'easyrankly' );
			// "inline" keeps the notice here: wp-admin/js/common.js relocates every notice without it to just
			// after the first <h1> of the .wrap, which in this layout is the 220px navigation sidebar.
			echo '<div class="notice notice-error is-dismissible inline"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'created' => __( 'Redirect created.', 'easyrankly' ),
			'updated' => __( 'Redirect updated.', 'easyrankly' ),
			'deleted' => __( 'Redirect deleted.', 'easyrankly' ),
			'toggled' => __( 'Redirect status updated.', 'easyrankly' ),
		);
		$message  = $messages[ $notice ] ?? '';

		if ( '' !== $message ) {
			echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
 * Read pre-fill parameters for a NEW redirect handed off from a deep link. The values only seed the Add form for
 * display and review; the actual save still runs through handle_save_redirect()/prepare_redirect_data() with its
 * own nonce and full normalization/validation. Returns null when no pre-fill parameters are present.
 *
 * @return array<string,mixed>|null
 */
	private function read_prefill(): ?array {
		$source_present = isset( $_GET['erankly_redirects_prefill_source'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form pre-fill from a deep link; the save is nonce-protected.
		$target_present = isset( $_GET['erankly_redirects_prefill_target'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form pre-fill from a deep link; the save is nonce-protected.

		if ( ! $source_present && ! $target_present ) {
			return null;
		}

		$source_path = $source_present ? sanitize_text_field( wp_unslash( $_GET['erankly_redirects_prefill_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form pre-fill; the save is nonce-protected.
		$target_url  = $target_present ? sanitize_text_field( wp_unslash( $_GET['erankly_redirects_prefill_target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form pre-fill; the save is nonce-protected.
		$status_code = isset( $_GET['erankly_redirects_prefill_status'] ) ? absint( $_GET['erankly_redirects_prefill_status'] ) : 301; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form pre-fill; the save is nonce-protected.
		$note        = isset( $_GET['erankly_redirects_prefill_note'] ) ? sanitize_textarea_field( wp_unslash( $_GET['erankly_redirects_prefill_note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form pre-fill; the save is nonce-protected.

		if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
			$status_code = 301;
		}

		return array(
			'id'          => 0,
			'source_path' => $source_path,
			'target_url'  => $target_url,
			'status_code' => $status_code,
			'is_active'   => true,
			'note'        => $note,
		);
	}

	/**
 * @param array<string,mixed>|null $redirect Redirect row (Edit mode).
 * @param array<string,mixed>|null $prefill  Seed values for a NEW redirect (Add mode).
 */
	private function render_redirect_form( ?array $redirect, ?array $prefill = null, array $table_state = array() ): void {
		// $redirect drives Edit mode; $prefill only seeds the fields of a NEW redirect while the form
		// stays in Add mode ( $id = 0 ). The save still runs through prepare_redirect_data() validation.
		$source      = $redirect ?? $prefill;
		$id          = $redirect ? (int) $redirect['id'] : 0;
		$source_path = $source ? (string) ( $source['source_path'] ?? '' ) : '';
		$target_url  = $source ? (string) ( $source['target_url'] ?? '' ) : '';
		$status_code = $source ? (int) ( $source['status_code'] ?? 301 ) : 301;
		$match_type  = $source && in_array( (string) ( $source['match_type'] ?? '' ), ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true ) ? (string) $source['match_type'] : 'exact';
		$query_mode  = $source && in_array( (string) ( $source['query_mode'] ?? '' ), ERankly_Redirects_Normalizer::VALID_QUERY_MODES, true ) ? (string) $source['query_mode'] : 'ignore';
		$source_query = $source ? (string) ( $source['source_query'] ?? '' ) : '';
		$case_sensitive = $source ? ! empty( $source['case_sensitive'] ) : false;
		$trailing_slash = $source && 'exact' === (string) ( $source['trailing_slash'] ?? '' ) ? 'exact' : 'ignore';
		$is_active   = $source ? (bool) ( $source['is_active'] ?? true ) : true;
		$note        = $source ? (string) ( $source['note'] ?? '' ) : '';
		$code_labels   = self::status_code_labels();
		$status_codes  = ERankly_Redirects_Normalizer::VALID_STATUS_CODES;
		$advanced_open = 'exact' !== $match_type || 'ignore' !== $query_mode || $case_sensitive || 'exact' === $trailing_slash;

		?>
		<form method="post" action="<?php echo esc_url( $this->admin_url() ); ?>" class="erankly-redirects-form">
			<?php wp_nonce_field( 'erankly_redirects_save_redirect' ); ?>
			<input type="hidden" name="erankly_redirects_action" value="save_redirect">
			<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php foreach ( $table_state as $state_key => $state_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $state_key ); ?>" value="<?php echo esc_attr( (string) $state_value ); ?>">
			<?php endforeach; ?>

			<label>
				<span><?php esc_html_e( 'Source URL', 'easyrankly' ); ?></span>
				<input type="text" name="source_path" value="<?php echo esc_attr( $source_path ); ?>" required placeholder="/old-page">
				<span class="description"><?php esc_html_e( 'If this value contains a query string, it is moved to “Required query string” and matched exactly.', 'easyrankly' ); ?></span>
			</label>

			<details class="erankly-settings-details erankly-redirects-advanced"<?php echo $advanced_open ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Advanced matching', 'easyrankly' ); ?></summary>
				<div class="erankly-settings-details-content">
					<p class="description"><?php esc_html_e( 'Use these controls only when one exact source path is not enough. Exact rules take precedence over wildcard rules, which take precedence over regular expressions.', 'easyrankly' ); ?></p>
					<div class="erankly-field">
						<label for="erankly-redirects-match-type"><?php esc_html_e( 'Match type', 'easyrankly' ); ?></label>
						<select name="match_type" id="erankly-redirects-match-type">
							<option value="exact" <?php selected( $match_type, 'exact' ); ?>><?php esc_html_e( 'Exact URL', 'easyrankly' ); ?></option>
							<option value="wildcard" <?php selected( $match_type, 'wildcard' ); ?>><?php esc_html_e( 'Wildcard pattern', 'easyrankly' ); ?></option>
							<option value="regex" <?php selected( $match_type, 'regex' ); ?>><?php esc_html_e( 'Regular expression', 'easyrankly' ); ?></option>
						</select>
						<p class="description" id="erankly-redirects-match-help"></p>
					</div>

					<div class="erankly-field">
						<label for="erankly-redirects-query-mode"><?php esc_html_e( 'Query parameters', 'easyrankly' ); ?></label>
						<select name="query_mode" id="erankly-redirects-query-mode">
						<option value="ignore" <?php selected( $query_mode, 'ignore' ); ?>><?php esc_html_e( 'Match any query and discard it', 'easyrankly' ); ?></option>
						<option value="preserve" <?php selected( $query_mode, 'preserve' ); ?>><?php esc_html_e( 'Match any query and append it to the target', 'easyrankly' ); ?></option>
						<option value="exact" <?php selected( $query_mode, 'exact' ); ?>><?php esc_html_e( 'Match only the required query string', 'easyrankly' ); ?></option>
						</select>
					</div>

					<div class="erankly-field" id="erankly-redirects-source-query-field">
						<label for="erankly-redirects-source-query"><?php esc_html_e( 'Required query string', 'easyrankly' ); ?></label>
						<input type="text" name="source_query" id="erankly-redirects-source-query" value="<?php echo esc_attr( $source_query ); ?>" placeholder="product=123&amp;view=compact">
						<p class="description"><?php esc_html_e( 'Enter the query string without the leading question mark. Parameter order is significant.', 'easyrankly' ); ?></p>
					</div>

					<div class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="case_sensitive" value="1" <?php checked( $case_sensitive ); ?>> <?php esc_html_e( 'Case-sensitive matching', 'easyrankly' ); ?></label>
						<label><input type="checkbox" class="erankly-toggle" name="trailing_slash" value="exact" <?php checked( $trailing_slash, 'exact' ); ?>> <?php esc_html_e( 'Treat the trailing slash as significant', 'easyrankly' ); ?></label>
					</div>

					<div class="erankly-field erankly-redirects-test">
						<label for="erankly-redirects-test-url"><?php esc_html_e( 'Test URL', 'easyrankly' ); ?></label>
						<div class="erankly-redirects-test-controls">
							<input type="text" id="erankly-redirects-test-url" placeholder="/old-page?product=123">
							<button type="button" class="button" id="erankly-redirects-test-button"><?php esc_html_e( 'Test rule', 'easyrankly' ); ?></button>
						</div>
						<p class="description" id="erankly-redirects-test-result" aria-live="polite"></p>
						<p class="description"><?php esc_html_e( 'This test checks normalization and matching only. It does not reproduce the logged-in administrator exclusion, loop detection, or the final safe redirect.', 'easyrankly' ); ?></p>
					</div>
				</div>
			</details>

			<div id="erankly-redirects-target-field">
				<label>
					<span><?php esc_html_e( 'Target URL', 'easyrankly' ); ?></span>
					<input type="text" name="target_url" value="<?php echo esc_attr( $target_url ); ?>" placeholder="/new-page or https://example.com/new-page">
				</label>
			</div>

			<label>
				<span><?php esc_html_e( 'HTTP Code', 'easyrankly' ); ?></span>
				<select name="status_code" id="erankly-redirects-status-code">
					<?php foreach ( $status_codes as $code ) : ?>
						<?php $code_label = $code_labels[ $code ] ?? ERankly_Redirects_Normalizer::status_code_label( $code ); ?>
						<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $status_code, $code ); ?>>
							<?php echo esc_html( $code_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( 'Note', 'easyrankly' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'easyrankly' ); ?></span></span>
				<textarea class="widefat" name="note" rows="3"><?php echo esc_textarea( $note ); ?></textarea>
			</label>

			<label class="erankly-redirects-checkbox">
				<input type="checkbox" class="erankly-toggle" name="is_active" value="1" <?php checked( $is_active ); ?>>
				<span><?php esc_html_e( 'Active', 'easyrankly' ); ?></span>
			</label>

			<?php submit_button( $id > 0 ? __( 'Update Redirect', 'easyrankly' ) : __( 'Add Redirect', 'easyrankly' ), 'primary', 'submit', false ); ?>
			<?php if ( $id > 0 ) : ?>
				<a class="button" href="<?php echo esc_url( $this->admin_url( $table_state ) ); ?>"><?php esc_html_e( 'Cancel', 'easyrankly' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * @param string              $order       Active sort direction, `asc` or `desc`.
	 * @param string              $search      Current search term, preserved in sort links.
	 * @param array<string,mixed> $table_state Search, page and sort state preserved by row actions.
	 */
	private function render_redirect_table( array $redirects, string $orderby, string $order, string $search, array $table_state ): void {
		?>
		<table class="widefat fixed striped erankly-panel-table erankly-redirects-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Redirect rules with source, matching details, target, HTTP code, status, sampled hits, and actions.', 'easyrankly' ); ?></caption>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Target', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Code', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Active', 'easyrankly' ); ?></th>
						<?php $this->render_sortable_column_header( 'hit_count', __( 'Estimated Hits', 'easyrankly' ), $orderby, $order, $search ); ?>
						<?php $this->render_sortable_column_header( 'last_hit_at', __( 'Last Sampled Hit', 'easyrankly' ), $orderby, $order, $search ); ?>
					<th><?php esc_html_e( 'Actions', 'easyrankly' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $redirects ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No redirects found.', 'easyrankly' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $redirects as $redirect ) : ?>
						<?php
						$id           = (int) $redirect['id'];
						$is_active    = ! empty( $redirect['is_active'] );
						$source_label = (string) $redirect['source_path'];
						$edit_url     = $this->admin_url( array_merge( $table_state, array( 'erankly_redirects_edit' => $id ) ) );
						$delete_url = wp_nonce_url(
							add_query_arg(
								array(
									'erankly_redirects_action' => 'delete',
									'redirect_id' => $id,
								),
								$this->admin_url( $table_state )
							),
							'erankly_redirects_delete_' . $id
						);
						$toggle_url = wp_nonce_url(
							add_query_arg(
								array(
									'erankly_redirects_action' => 'toggle',
									'redirect_id' => $id,
								),
								$this->admin_url( $table_state )
							),
							'erankly_redirects_toggle_' . $id
						);
						?>
						<tr data-redirect-id="<?php echo esc_attr( (string) $id ); ?>">
							<td>
							<code><?php echo esc_html( $source_label ); ?></code>
							<br><span class="description erankly-redirects-match-details">
								<?php
								printf(
									/* translators: 1: path match type, 2: query-string behavior. */
									esc_html__( 'Path: %1$s · Query: %2$s', 'easyrankly' ),
									esc_html( self::match_type_label( (string) ( $redirect['match_type'] ?? 'exact' ) ) ),
									esc_html( self::query_mode_label( (string) ( $redirect['query_mode'] ?? 'ignore' ) ) )
								);
								?>
							</span>
							<?php if ( 'exact' === (string) ( $redirect['query_mode'] ?? '' ) && '' !== (string) ( $redirect['source_query'] ?? '' ) ) : ?>
								<br><code class="description">?<?php echo esc_html( (string) $redirect['source_query'] ); ?></code>
							<?php endif; ?>
								<?php if ( ! empty( $redirect['note'] ) ) : ?>
									<br><span class="description erankly-redirects-note"><?php echo esc_html( wp_trim_words( (string) $redirect['note'], 12, '…' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo '' !== (string) $redirect['target_url'] ? '<code>' . esc_html( (string) $redirect['target_url'] ) . '</code>' : esc_html__( 'Not applicable', 'easyrankly' ); ?></td>
							<td><?php echo esc_html( (string) (int) $redirect['status_code'] ); ?></td>
							<td class="erankly-redirects-active-cell"><?php echo $is_active ? esc_html__( 'Yes', 'easyrankly' ) : esc_html__( 'No', 'easyrankly' ); ?></td>
							<td class="erankly-redirects-col-optional"><?php echo esc_html( number_format_i18n( (int) $redirect['hit_count'] ) ); ?></td>
						<td class="erankly-redirects-col-optional"><?php echo empty( $redirect['last_hit_at'] ) ? esc_html__( 'Never', 'easyrankly' ) : esc_html( self::format_last_hit( (string) $redirect['last_hit_at'] ) ); ?></td>
						<td class="erankly-redirects-actions">
							<a href="<?php echo esc_url( $edit_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Edit redirect from %s', 'easyrankly' ), $source_label ) ); ?>"><?php esc_html_e( 'Edit', 'easyrankly' ); ?></a>
							<a class="erankly-redirects-toggle" data-id="<?php echo esc_attr( (string) $id ); ?>" data-active="<?php echo $is_active ? '1' : '0'; ?>" data-source="<?php echo esc_attr( $source_label ); ?>" aria-label="<?php echo esc_attr( sprintf( $is_active ? __( 'Disable redirect from %s', 'easyrankly' ) : __( 'Enable redirect from %s', 'easyrankly' ), $source_label ) ); ?>" href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $is_active ? esc_html__( 'Disable', 'easyrankly' ) : esc_html__( 'Enable', 'easyrankly' ); ?></a>
							<a class="button-link-delete erankly-redirects-delete" data-id="<?php echo esc_attr( (string) $id ); ?>" data-source="<?php echo esc_attr( $source_label ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Delete redirect from %s', 'easyrankly' ), $source_label ) ); ?>" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'easyrankly' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
 * @param string $column        Column key. Must match a key in ERankly_Redirects_Repository::SORTABLE_COLUMNS.
 * @param string $active_column Column currently sorted by.
 * @param string $active_order  Current sort direction, `asc` or `desc`.
 * @param string $search        Current search term, preserved in the sort link.
 */
	private function render_sortable_column_header( string $column, string $label, string $active_column, string $active_order, string $search ): void {
		$is_active  = $active_column === $column;
		$next_order = ( $is_active && 'asc' === $active_order ) ? 'desc' : 'asc';

		$args = array(
			'page'        => self::SLUG,
			'erankly_tab' => 'redirects',
			'orderby'     => $column,
			'order'       => $next_order,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$url = add_query_arg( $args, admin_url( 'options-general.php' ) );

		// WordPress marks the column actually sorted with "sorted"; "sortable" plus a direction is only the hint
		// for the next click. Emitting "desc" on every column made the stylesheet paint a filled arrow on both
		// Hits columns at once, as if the table were sorted by each of them.
		$classes = array( 'erankly-redirects-col-optional', 'sortable' );

		if ( $is_active ) {
			$classes[] = 'sorted';
			$classes[] = $active_order;
		} else {
			$classes[] = $next_order;
		}

		$aria_sort = $is_active ? ( 'asc' === $active_order ? 'ascending' : 'descending' ) : 'none';
		?>
		<th scope="col" aria-sort="<?php echo esc_attr( $aria_sort ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<a href="<?php echo esc_url( $url ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<span class="sorting-indicator"></span>
			</a>
		</th>
		<?php
	}

	/**
 * @param string $orderby Active sort column, preserved across pages.
 * @param string $order Active sort direction, preserved across pages.
 */
	private function render_pagination( int $current_page, int $total_pages, string $search, string $orderby = '', string $order = 'desc' ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args = array(
			'page'        => self::SLUG,
			'erankly_tab' => 'redirects',
		);

		if ( '' !== $search ) {
			$base_args['s'] = $search;
		}

		if ( '' !== $orderby ) {
			$base_args['orderby'] = $orderby;
			$base_args['order']   = $order;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), admin_url( 'options-general.php' ) ),
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => __( '&laquo;', 'easyrankly' ),
				'next_text' => __( '&raquo;', 'easyrankly' ),
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	private function handle_save_redirect(): void {
		// check_admin_referer() dies on failure, so no error branch is needed.
		check_admin_referer( 'erankly_redirects_save_redirect' );

		$id                    = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		list( $data, $errors ) = $this->prepare_redirect_data( $_POST );
		$table_state           = $this->table_state_args( $_POST );

		if ( ! empty( $errors ) ) {
			$this->store_form_state( $id, $_POST );
			$args = array_merge( $table_state, array( 'erankly_redirects_error' => (string) reset( $errors ) ) );
			if ( $id > 0 ) {
				$args['erankly_redirects_edit'] = $id;
			}
			$this->redirect_after_action( $args );
		}

		if ( $id > 0 ) {
			$success = $this->repository->update( $id, $data );
			$this->redirect_after_action( array_merge( $table_state, $success ? array( 'erankly_redirects_notice' => 'updated' ) : array( 'erankly_redirects_error' => 'save' ) ) );

			return;
		}

		$created_id = $this->repository->create( $data );
		$this->redirect_after_action( array_merge( $table_state, $created_id > 0 ? array( 'erankly_redirects_notice' => 'created' ) : array( 'erankly_redirects_error' => 'save' ) ) );
	}

	private function handle_delete_redirect(): void {
		$id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

		if ( $id <= 0 ) {
			$this->redirect_with_error( 'delete' );
		}

		check_admin_referer( 'erankly_redirects_delete_' . $id );

		$success = $this->repository->delete( $id );
		$this->redirect_after_action( array_merge( $this->table_state_args( $_GET ), $success ? array( 'erankly_redirects_notice' => 'deleted' ) : array( 'erankly_redirects_error' => 'delete' ) ) );
	}

	private function handle_toggle_redirect(): void {
		$id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

		if ( $id <= 0 ) {
			$this->redirect_with_error( 'toggle' );
		}

		check_admin_referer( 'erankly_redirects_toggle_' . $id );

		$success = $this->repository->toggle_active( $id );
		$this->redirect_after_action( array_merge( $this->table_state_args( $_GET ), $success ? array( 'erankly_redirects_notice' => 'toggled' ) : array( 'erankly_redirects_error' => 'toggle' ) ) );
	}

	/**
	 * Validate and normalize redirect input from the Add/Edit form.
 *
 * @return array{0:array<string,mixed>,1:array<int,string>}
 */
	private function prepare_redirect_data( array $input ): array {
		$source_raw     = isset( $input['source_path'] ) ? sanitize_text_field( wp_unslash( $input['source_path'] ) ) : '';
		$target_raw     = isset( $input['target_url'] ) ? trim( (string) wp_unslash( $input['target_url'] ) ) : '';
		$status_code    = isset( $input['status_code'] ) ? absint( $input['status_code'] ) : 301;
		$match_type     = isset( $input['match_type'] ) ? sanitize_key( (string) $input['match_type'] ) : 'exact';
		$match_type     = in_array( $match_type, ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true ) ? $match_type : '';
		$query_mode     = isset( $input['query_mode'] ) ? sanitize_key( (string) $input['query_mode'] ) : 'ignore';
		$query_mode     = in_array( $query_mode, ERankly_Redirects_Normalizer::VALID_QUERY_MODES, true ) ? $query_mode : '';
		$source_query   = isset( $input['source_query'] ) ? ltrim( sanitize_text_field( wp_unslash( $input['source_query'] ) ), '?' ) : '';
		$case_sensitive = ! empty( $input['case_sensitive'] ) ? 1 : 0;
		$trailing_slash = isset( $input['trailing_slash'] ) && 'exact' === $input['trailing_slash'] ? 'exact' : 'ignore';
		$is_active      = ! empty( $input['is_active'] ) ? 1 : 0;
		$note           = isset( $input['note'] ) ? sanitize_textarea_field( wp_unslash( $input['note'] ) ) : '';
		$embedded_query = 'regex' === $match_type ? '' : ERankly_Redirects_Normalizer::extract_query( $source_raw );
		if ( '' !== $embedded_query && '' === $source_query ) {
			$source_query = sanitize_text_field( $embedded_query );
			$query_mode   = 'exact';
		}
		$source_path    = '' === $match_type ? '' : ERankly_Redirects_Normalizer::normalize_source( $source_raw, 'regex' === $match_type, 'wildcard' === $match_type, (bool) $case_sensitive, $trailing_slash );
		$is_status_only = ERankly_Redirects_Normalizer::is_status_only_code( $status_code );
		$target_url     = $is_status_only ? '' : ERankly_Redirects_Normalizer::normalize_target_url( $target_raw );
		$errors         = array();

		if ( '' === $source_path ) {
			$errors[] = 'source_required';
		}

		if ( strlen( $source_path ) > 512 ) {
			$errors[] = 'source_too_long';
		}
		if ( strlen( $source_query ) > 512 ) {
			$errors[] = 'source_query_too_long';
		}

		if ( ! $is_status_only && '' === $target_url ) {
			$errors[] = 'target_required';
		}

		if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
			$errors[] = 'status_code';
		}

		if ( 'wildcard' === $match_type && ! ERankly_Redirects_Normalizer::is_valid_wildcard_source( $source_path ) ) {
			$errors[] = 'source_wildcard';
		} elseif ( 'regex' === $match_type && ! ERankly_Redirects_Normalizer::is_valid_regex( $source_path ) ) {
			$errors[] = 'source_regex';
		} elseif ( 'exact' === $match_type && ! ERankly_Redirects_Normalizer::is_valid_internal_path( $source_path ) ) {
			$errors[] = 'source_path';
		}
		if ( '' === $match_type || '' === $query_mode ) {
			$errors[] = 'matching_mode';
		}

		return array(
			array(
				'source_path' => $source_path,
				'target_url'  => $target_url,
				'status_code' => $status_code,
				'match_type' => $match_type,
				'source_query' => 'exact' === $query_mode ? $source_query : '',
				'query_mode' => $query_mode,
				'case_sensitive' => $case_sensitive,
				'trailing_slash' => $trailing_slash,
				'is_active'   => $is_active,
				'note'        => $note,
			),
			$errors,
		);
	}

	private function redirect_with_error( string $error ): void {
		$this->redirect_after_action( array_merge( $this->table_state_args( $_REQUEST ), array( 'erankly_redirects_error' => $error ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- State is read only; mutations verify their action nonce first.
	}

	/** Redirect to admin page after an action. */
	private function redirect_after_action( array $args ): void {
		wp_safe_redirect( $this->admin_url( $args ) );
		exit;
	}

	private function admin_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'        => self::SLUG,
					'erankly_tab' => 'redirects',
				),
				$args
			),
			admin_url( 'options-general.php' )
		);
	}
}
