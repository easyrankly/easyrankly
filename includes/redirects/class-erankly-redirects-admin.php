<?php
/**
 * Admin page.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools page for redirect management.
 */
final class ERankly_Redirects_Admin {
	/**
	 * Admin menu slug.
	 */
	private const SLUG = 'erankly';

	/**
	 * Redirect repository.
	 *
	 * @var ERankly_Redirects_Repository
	 */
	private ERankly_Redirects_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param ERankly_Redirects_Repository $repository Redirect repository.
	 */
	public function __construct( ERankly_Redirects_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register admin hooks.
	 *
	 * The menu entry and asset loading are handled by the EasyRankly settings
	 * page; this class only processes redirect actions and renders the panel
	 * content inside the "Redirects" settings tab.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle create/update/delete/toggle actions.
	 */
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
	 * Render the redirect management UI inside the EasyRankly settings page.
	 *
	 * No <div class="wrap"> or <h1> wrapper is emitted because this content is
	 * rendered within the existing settings page markup. The panel shows the
	 * add/edit form and the redirect table; global redirect settings live on the
	 * main Settings tab and import/export lives on the Import / Export tab.
	 */
	public function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$edit_id       = isset( $_GET['erankly_redirects_edit'] ) ? absint( $_GET['erankly_redirects_edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit form selection.
		$edit_redirect = $edit_id > 0 ? $this->repository->find_by_id( $edit_id ) : null;
		$prefill       = null === $edit_redirect ? $this->read_prefill() : null;
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect search term.
		$current_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$orderby       = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table sorting; validated against a column whitelist in the repository.
		$order         = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table sorting.
		$per_page      = 25;
		$total_items   = $this->repository->count_redirects( $search );
		$total_pages   = max( 1, (int) ceil( $total_items / $per_page ) );
		$redirects     = $this->repository->list_redirects( $search, $current_page, $per_page, $orderby, $order );

		// The structured search filters (status:/code:/type:/visibility:) are an
		// advanced-only affordance; simplified mode keeps the search plain so the
		// hint text never references syntax the user has no way to discover.
		$show_search_filters = ! (bool) erankly_get_setting( 'simplified_mode', 1 );

		?>
		<div class="erankly-redirects-wrap">
			<?php $this->render_notices(); ?>

			<div class="erankly-settings-section">
				<h3 class="erankly-section-title"><?php echo $edit_redirect ? esc_html__( 'Edit Redirect', 'easyrankly' ) : esc_html__( 'Add Redirect', 'easyrankly' ); ?></h3>
				<section class="erankly-redirects-panel erankly-redirects-form-panel erankly-card">
					<?php $this->render_redirect_form( $edit_redirect, $prefill ); ?>
				</section>
			</div>

			<div class="erankly-settings-section erankly-panel-expandable" id="erankly-redirects-table-wrap" data-erankly-expandable>
				<h3 class="erankly-section-title"><?php esc_html_e( 'Redirect rules', 'easyrankly' ); ?></h3>
				<section class="erankly-card">
				<div class="erankly-panel-toolbar erankly-redirects-toolbar">
					<form method="get" class="erankly-redirects-search">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<input type="hidden" name="erankly_tab" value="redirects">
						<?php if ( $show_search_filters ) : ?>
							<label for="erankly-redirects-search-source" class="screen-reader-text"><?php esc_html_e( 'Search source path, or use structured filters such as status:on or code:301', 'easyrankly' ); ?></label>
							<div class="erankly-redirects-search-field">
								<input id="erankly-redirects-search-source" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search path or filter (e.g. key:value and key:value)', 'easyrankly' ); ?>" title="<?php esc_attr_e( 'Free text matches the source path. Click for filter suggestions (status, code, type, visibility), then combine several with "and" or "&".', 'easyrankly' ); ?>" autocomplete="off">
								<div id="erankly-redirects-search-suggest" class="erankly-redirects-search-suggest" hidden></div>
							</div>
						<?php else : ?>
							<label for="erankly-redirects-search-source" class="screen-reader-text"><?php esc_html_e( 'Search source path', 'easyrankly' ); ?></label>
							<input id="erankly-redirects-search-source" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search path…', 'easyrankly' ); ?>">
						<?php endif; ?>
						<?php submit_button( __( 'Search', 'easyrankly' ), 'secondary', '', false ); ?>
						<?php if ( '' !== $search ) : ?>
							<a class="button" href="<?php echo esc_url( $this->admin_url() ); ?>"><?php esc_html_e( 'Clear', 'easyrankly' ); ?></a>
						<?php endif; ?>
					</form>
					<?php erankly_admin_render_panel_expand_toggle( 'erankly-redirects-table-wrap' ); ?>
				</div>

				<?php $this->render_redirect_table( $redirects, $orderby, $order, $search ); ?>
				<?php $this->render_pagination( $current_page, $total_pages, $search, $orderby, $order ); ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 */
	private function render_notices(): void {
		$notice = isset( $_GET['erankly_redirects_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_redirects_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display after redirect.
		$error  = isset( $_GET['erankly_redirects_error'] ) ? sanitize_key( wp_unslash( $_GET['erankly_redirects_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display after redirect.

		if ( '' !== $error ) {
			$messages = array(
				'invalid' => __( 'Please check the redirect fields and try again.', 'easyrankly' ),
				'nonce'   => __( 'Security check failed. Please try again.', 'easyrankly' ),
				'save'    => __( 'The redirect could not be saved. A duplicate source may already exist.', 'easyrankly' ),
				'delete'  => __( 'The redirect could not be deleted.', 'easyrankly' ),
				'toggle'  => __( 'The redirect status could not be changed.', 'easyrankly' ),
			);
			$message  = $messages[ $error ] ?? __( 'An error occurred.', 'easyrankly' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Read pre-fill parameters for a NEW redirect handed off from another tab
	 * (e.g. the Health 404 scanner's "Create 301 redirect" action).
	 *
	 * The values only seed the Add form for display and review; the actual save
	 * still runs through handle_save_redirect()/prepare_redirect_data() with its
	 * own nonce and full normalization/validation. Returns null when no pre-fill
	 * parameters are present.
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
			'id'            => 0,
			'source_path'   => $source_path,
			'target_url'    => $target_url,
			'status_code'   => $status_code,
			'is_active'     => true,
			'visibility'    => 'all',
			'required_role' => '',
			'is_regex'      => 0,
			'is_wildcard'   => 0,
			'note'          => $note,
		);
	}

	/**
	 * Render create/edit redirect form.
	 *
	 * @param array<string,mixed>|null $redirect Redirect row (Edit mode).
	 * @param array<string,mixed>|null $prefill  Seed values for a NEW redirect (Add mode), e.g. a 404
	 *                                           handed off from the Health tab. Ignored when $redirect is set.
	 */
	private function render_redirect_form( ?array $redirect, ?array $prefill = null ): void {
		// $redirect drives Edit mode; $prefill only seeds the fields of a NEW redirect while the form
		// stays in Add mode ( $id = 0 ). The save still runs through prepare_redirect_data() validation.
		$source           = $redirect ?? $prefill;
		$id               = $redirect ? (int) $redirect['id'] : 0;
		$source_path      = $source ? (string) ( $source['source_path'] ?? '' ) : '';
		$source_query     = $source ? (string) ( $source['source_query'] ?? '' ) : '';
		$target_url       = $source ? (string) ( $source['target_url'] ?? '' ) : '';
		$status_code      = $source ? (int) ( $source['status_code'] ?? 301 ) : 301;
		$is_active        = $source ? (bool) ( $source['is_active'] ?? true ) : true;
		$visibility       = $source ? (string) ( $source['visibility'] ?? 'all' ) : 'all';
		$required_role    = $source ? (string) ( $source['required_role'] ?? '' ) : '';
		$note             = $source ? (string) ( $source['note'] ?? '' ) : '';
		$case_sensitive   = $source ? ! empty( $source['case_sensitive'] ) : false;
		$trailing_slash   = $source ? (string) ( $source['trailing_slash'] ?? 'ignore' ) : 'ignore';
		$query_mode       = $source ? (string) ( $source['query_mode'] ?? 'ignore' ) : 'ignore';
		$priority         = $source ? (int) ( $source['priority'] ?? 10 ) : 10;
		$start_at         = $source ? (string) ( $source['start_at'] ?? '' ) : '';
		$end_at           = $source ? (string) ( $source['end_at'] ?? '' ) : '';
		$source_plugin    = $source ? (string) ( $source['source_plugin'] ?? '' ) : '';
		$source_reference = $source ? (string) ( $source['source_reference'] ?? '' ) : '';
		$migration_id     = $source ? (string) ( $source['migration_id'] ?? '' ) : '';
		$conditions       = $source && is_string( $source['conditions'] ?? null ) ? (string) $source['conditions'] : '';

		// Derive match_type from stored flags.
		$match_type = $source && isset( $source['match_type'] ) ? (string) $source['match_type'] : 'exact';
		if ( $source ) {
			if ( empty( $source['match_type'] ) && ! empty( $source['is_wildcard'] ) ) {
				$match_type = 'wildcard';
			} elseif ( empty( $source['match_type'] ) && ! empty( $source['is_regex'] ) ) {
				$match_type = 'regex';
			}
		}

		$roles = get_editable_roles();

		?>
		<form method="post" action="<?php echo esc_url( $this->admin_url() ); ?>" class="erankly-redirects-form">
			<?php wp_nonce_field( 'erankly_redirects_save_redirect' ); ?>
			<input type="hidden" name="erankly_redirects_action" value="save_redirect">
			<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="conditions" value="<?php echo esc_attr( $conditions ); ?>">
			<input type="hidden" name="migration_id" value="<?php echo esc_attr( $migration_id ); ?>">

			<label>
				<span><?php esc_html_e( 'Source URL', 'easyrankly' ); ?></span>
				<input type="text" name="source_path" value="<?php echo esc_attr( $source_path ); ?>" required placeholder="/old-page">
			</label>

			<div id="erankly-redirects-target-field">
				<label>
					<span><?php esc_html_e( 'Target URL', 'easyrankly' ); ?></span>
					<input type="text" name="target_url" value="<?php echo esc_attr( $target_url ); ?>" placeholder="/new-page or https://example.com/new-page">
				</label>
				<p class="description"><?php esc_html_e( 'Not used for 410 (Gone) or 451 (Unavailable For Legal Reasons). Those codes end the request without redirecting.', 'easyrankly' ); ?></p>
			</div>

			<div class="erankly-redirects-grid">
				<label>
					<span><?php esc_html_e( 'HTTP Code', 'easyrankly' ); ?></span>
					<select name="status_code" id="erankly-redirects-status-code">
						<?php foreach ( ERankly_Redirects_Normalizer::VALID_STATUS_CODES as $code ) : ?>
							<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $status_code, $code ); ?>>
								<?php echo esc_html( ERankly_Redirects_Normalizer::status_code_label( $code ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Apply to', 'easyrankly' ); ?></span>
					<select name="visibility" id="erankly-redirects-visibility">
						<option value="all" <?php selected( $visibility, 'all' ); ?>><?php esc_html_e( 'Everyone', 'easyrankly' ); ?></option>
						<option value="logged_out" <?php selected( $visibility, 'logged_out' ); ?>><?php esc_html_e( 'Logged-out users only', 'easyrankly' ); ?></option>
						<option value="logged_in" <?php selected( $visibility, 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users only', 'easyrankly' ); ?></option>
					</select>
				</label>
			</div>

			<div class="erankly-redirects-role-field" id="erankly-redirects-role-field">
				<label>
					<span><?php esc_html_e( 'Required role', 'easyrankly' ); ?></span>
					<select name="required_role">
						<option value="" <?php selected( $required_role, '' ); ?>><?php esc_html_e( 'Any logged-in user', 'easyrankly' ); ?></option>
						<?php foreach ( $roles as $role_slug => $role_data ) : ?>
							<option value="<?php echo esc_attr( $role_slug ); ?>" <?php selected( $required_role, $role_slug ); ?>>
								<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<p class="description"><?php esc_html_e( 'Only applies when "Apply to" is set to logged-in users.', 'easyrankly' ); ?></p>
			</div>

			<label>
				<span><?php esc_html_e( 'Match type', 'easyrankly' ); ?></span>
				<select name="match_type">
					<option value="exact" <?php selected( $match_type, 'exact' ); ?>><?php esc_html_e( 'Exact', 'easyrankly' ); ?></option>
					<option value="wildcard" <?php selected( $match_type, 'wildcard' ); ?>><?php esc_html_e( 'Wildcard  (*)', 'easyrankly' ); ?></option>
					<option value="regex" <?php selected( $match_type, 'regex' ); ?>><?php esc_html_e( 'Regex', 'easyrankly' ); ?></option>
					<option value="contains" <?php selected( $match_type, 'contains' ); ?>><?php esc_html_e( 'Contains', 'easyrankly' ); ?></option>
					<option value="starts_with" <?php selected( $match_type, 'starts_with' ); ?>><?php esc_html_e( 'Starts with', 'easyrankly' ); ?></option>
					<option value="ends_with" <?php selected( $match_type, 'ends_with' ); ?>><?php esc_html_e( 'Ends with', 'easyrankly' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Exact: literal path. Wildcard: use * in source and target (e.g. /old/* → /new/*). Regex: full regular expression.', 'easyrankly' ); ?></p>
			</label>

			<div class="erankly-redirects-grid">
				<label><span><?php esc_html_e( 'Query string', 'easyrankly' ); ?></span><select name="query_mode"><option value="ignore" <?php selected( $query_mode, 'ignore' ); ?>><?php esc_html_e( 'Ignore', 'easyrankly' ); ?></option><option value="preserve" <?php selected( $query_mode, 'preserve' ); ?>><?php esc_html_e( 'Ignore and preserve on target', 'easyrankly' ); ?></option><option value="exact" <?php selected( $query_mode, 'exact' ); ?>><?php esc_html_e( 'Match exactly', 'easyrankly' ); ?></option></select></label>
				<label><span><?php esc_html_e( 'Trailing slash', 'easyrankly' ); ?></span><select name="trailing_slash"><option value="ignore" <?php selected( $trailing_slash, 'ignore' ); ?>><?php esc_html_e( 'Ignore', 'easyrankly' ); ?></option><option value="exact" <?php selected( $trailing_slash, 'exact' ); ?>><?php esc_html_e( 'Match exactly', 'easyrankly' ); ?></option></select></label>
			</div>
			<label><span><?php esc_html_e( 'Exact query to match', 'easyrankly' ); ?></span><input type="text" name="source_query" value="<?php echo esc_attr( $source_query ); ?>" placeholder="utm_source=newsletter"></label>
			<div class="erankly-redirects-grid">
				<label><span><?php esc_html_e( 'Priority', 'easyrankly' ); ?></span><input type="number" name="priority" value="<?php echo esc_attr( (string) $priority ); ?>"></label>
				<label class="erankly-redirects-checkbox"><input type="checkbox" class="erankly-toggle" name="case_sensitive" value="1" <?php checked( $case_sensitive ); ?>><span><?php esc_html_e( 'Case-sensitive match', 'easyrankly' ); ?></span></label>
			</div>
			<div class="erankly-redirects-grid">
				<label><span><?php esc_html_e( 'Starts at', 'easyrankly' ); ?></span><input type="datetime-local" name="start_at" value="<?php echo esc_attr( '' !== $start_at ? str_replace( ' ', 'T', substr( $start_at, 0, 16 ) ) : '' ); ?>"></label>
				<label><span><?php esc_html_e( 'Ends at', 'easyrankly' ); ?></span><input type="datetime-local" name="end_at" value="<?php echo esc_attr( '' !== $end_at ? str_replace( ' ', 'T', substr( $end_at, 0, 16 ) ) : '' ); ?>"></label>
			</div>
			<div class="erankly-redirects-grid">
				<label><span><?php esc_html_e( 'Origin plugin', 'easyrankly' ); ?></span><input type="text" name="source_plugin" value="<?php echo esc_attr( $source_plugin ); ?>" placeholder="yoast, rank-math, aioseo, seopress"></label>
				<label><span><?php esc_html_e( 'Origin reference', 'easyrankly' ); ?></span><input type="text" name="source_reference" value="<?php echo esc_attr( $source_reference ); ?>"></label>
			</div>

			<label>
				<span><?php esc_html_e( 'Note', 'easyrankly' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'easyrankly' ); ?></span></span>
				<textarea name="note" rows="2"><?php echo esc_textarea( $note ); ?></textarea>
			</label>

			<label class="erankly-redirects-checkbox">
				<input type="checkbox" class="erankly-toggle" name="is_active" value="1" <?php checked( $is_active ); ?>>
				<span><?php esc_html_e( 'Active', 'easyrankly' ); ?></span>
			</label>

			<?php submit_button( $id > 0 ? __( 'Update Redirect', 'easyrankly' ) : __( 'Add Redirect', 'easyrankly' ), 'primary', 'submit', false ); ?>
			<?php if ( $id > 0 ) : ?>
				<a class="button" href="<?php echo esc_url( $this->admin_url() ); ?>"><?php esc_html_e( 'Cancel', 'easyrankly' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render redirects table.
	 *
	 * @param array<int,array<string,mixed>> $redirects Redirect rows.
	 * @param string                         $orderby   Active sort column.
	 * @param string                         $order     Active sort direction, `asc` or `desc`.
	 * @param string                         $search    Current search term, preserved in sort links.
	 */
	private function render_redirect_table( array $redirects, string $orderby, string $order, string $search ): void {
		?>
		<table class="widefat fixed striped erankly-panel-table erankly-redirects-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Target', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Code', 'easyrankly' ); ?></th>
					<th class="erankly-redirects-col-optional"><?php esc_html_e( 'Type', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Active', 'easyrankly' ); ?></th>
					<th class="erankly-redirects-col-optional"><?php esc_html_e( 'Condition', 'easyrankly' ); ?></th>
						<?php $this->render_sortable_column_header( 'hit_count', __( 'Estimated Hits', 'easyrankly' ), $orderby, $order, $search ); ?>
						<?php $this->render_sortable_column_header( 'last_hit_at', __( 'Last Sampled Hit', 'easyrankly' ), $orderby, $order, $search ); ?>
					<th><?php esc_html_e( 'Actions', 'easyrankly' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $redirects ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No redirects found.', 'easyrankly' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $redirects as $redirect ) : ?>
						<?php
						$id         = (int) $redirect['id'];
						$is_active  = ! empty( $redirect['is_active'] );
						$edit_url   = add_query_arg( array( 'erankly_redirects_edit' => $id ), $this->admin_url() );
						$delete_url = wp_nonce_url(
							add_query_arg(
								array(
									'erankly_redirects_action' => 'delete',
									'redirect_id' => $id,
								),
								$this->admin_url()
							),
							'erankly_redirects_delete_' . $id
						);
						$toggle_url = wp_nonce_url(
							add_query_arg(
								array(
									'erankly_redirects_action' => 'toggle',
									'redirect_id' => $id,
								),
								$this->admin_url()
							),
							'erankly_redirects_toggle_' . $id
						);
						?>
						<tr data-redirect-id="<?php echo esc_attr( (string) $id ); ?>">
							<td>
								<code><?php echo esc_html( (string) $redirect['source_path'] ); ?></code>
								<?php if ( ! empty( $redirect['note'] ) ) : ?>
									<br><span class="description erankly-redirects-note"><?php echo esc_html( wp_trim_words( (string) $redirect['note'], 12, '…' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo '' !== (string) $redirect['target_url'] ? '<code>' . esc_html( (string) $redirect['target_url'] ) . '</code>' : esc_html__( 'Not applicable', 'easyrankly' ); ?></td>
							<td><?php echo esc_html( (string) (int) $redirect['status_code'] ); ?></td>
							<td class="erankly-redirects-col-optional"><?php echo esc_html( $this->format_match_type( $redirect ) ); ?></td>
							<td class="erankly-redirects-active-cell"><?php echo $is_active ? esc_html__( 'Yes', 'easyrankly' ) : esc_html__( 'No', 'easyrankly' ); ?></td>
							<td class="erankly-redirects-col-optional"><?php echo esc_html( $this->format_condition( $redirect ) ); ?></td>
							<td class="erankly-redirects-col-optional"><?php echo esc_html( number_format_i18n( (int) $redirect['hit_count'] ) ); ?></td>
							<td class="erankly-redirects-col-optional"><?php echo empty( $redirect['last_hit_at'] ) ? esc_html__( 'Never', 'easyrankly' ) : esc_html( (string) $redirect['last_hit_at'] ); ?></td>
							<td class="erankly-redirects-actions">
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'easyrankly' ); ?></a>
								<a class="erankly-redirects-toggle" data-id="<?php echo esc_attr( (string) $id ); ?>" data-active="<?php echo $is_active ? '1' : '0'; ?>" href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $is_active ? esc_html__( 'Disable', 'easyrankly' ) : esc_html__( 'Enable', 'easyrankly' ); ?></a>
								<a class="button-link-delete erankly-redirects-delete" data-id="<?php echo esc_attr( (string) $id ); ?>" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'easyrankly' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a sortable `<th>` header cell, WP_List_Table style.
	 *
	 * @param string $column        Column key. Must match a key in ERankly_Redirects_Repository::SORTABLE_COLUMNS.
	 * @param string $label         Visible column label.
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

		$url     = add_query_arg( $args, admin_url( 'options-general.php' ) );
		$classes = array( 'erankly-redirects-col-optional', 'sortable', $is_active ? $active_order : 'desc' );
		?>
		<th class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<a href="<?php echo esc_url( $url ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<span class="sorting-indicator"></span>
			</a>
		</th>
		<?php
	}

	/**
	 * Format the redirect condition for display in the table.
	 *
	 * @param array<string,mixed> $redirect Redirect row.
	 * @return string
	 */
	private function format_condition( array $redirect ): string {
		$visibility    = isset( $redirect['visibility'] ) ? (string) $redirect['visibility'] : 'all';
		$required_role = isset( $redirect['required_role'] ) ? (string) $redirect['required_role'] : '';

		if ( 'logged_out' === $visibility ) {
			return __( 'Logged-out only', 'easyrankly' );
		}

		if ( 'logged_in' === $visibility ) {
			if ( '' !== $required_role ) {
				$roles = wp_roles()->get_names();
				$label = isset( $roles[ $required_role ] ) ? translate_user_role( $roles[ $required_role ] ) : $required_role;

				return sprintf(
					/* translators: %s: role name. */
					__( 'Logged-in (%s)', 'easyrankly' ),
					$label
				);
			}

			return __( 'Logged-in only', 'easyrankly' );
		}

		return __( 'Everyone', 'easyrankly' );
	}

	/**
	 * Return a human-readable match-type label for table display.
	 *
	 * @param array<string,mixed> $redirect Redirect row.
	 * @return string
	 */
	private function format_match_type( array $redirect ): string {
		$match_type = isset( $redirect['match_type'] ) ? (string) $redirect['match_type'] : '';
		$labels     = array(
			'exact'       => __( 'Exact', 'easyrankly' ),
			'wildcard'    => __( 'Wildcard', 'easyrankly' ),
			'regex'       => __( 'Regex', 'easyrankly' ),
			'contains'    => __( 'Contains', 'easyrankly' ),
			'starts_with' => __( 'Starts with', 'easyrankly' ),
			'ends_with'   => __( 'Ends with', 'easyrankly' ),
		);

		if ( isset( $labels[ $match_type ] ) ) {
			return $labels[ $match_type ];
		}

		if ( ! empty( $redirect['is_wildcard'] ) ) {
			return __( 'Wildcard', 'easyrankly' );
		}

		if ( ! empty( $redirect['is_regex'] ) ) {
			return __( 'Regex', 'easyrankly' );
		}

		return __( 'Exact', 'easyrankly' );
	}

	/**
	 * Render pagination links.
	 *
	 * @param int    $current_page Current page.
	 * @param int    $total_pages Total pages.
	 * @param string $search Search term.
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

	/**
	 * Save redirect action.
	 */
	private function handle_save_redirect(): void {
		// check_admin_referer() dies on failure, so no error branch is needed.
		check_admin_referer( 'erankly_redirects_save_redirect' );

		$id                    = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		list( $data, $errors ) = $this->prepare_redirect_data( $_POST );

		if ( ! empty( $errors ) ) {
			$this->redirect_with_error( 'invalid' );
		}

		if ( $id > 0 ) {
			$success = $this->repository->update( $id, $data );
			$this->redirect_after_action( $success ? array( 'erankly_redirects_notice' => 'updated' ) : array( 'erankly_redirects_error' => 'save' ) );

			return;
		}

		$created_id = $this->repository->create( $data );
		$this->redirect_after_action( $created_id > 0 ? array( 'erankly_redirects_notice' => 'created' ) : array( 'erankly_redirects_error' => 'save' ) );
	}

	/**
	 * Delete redirect action.
	 */
	private function handle_delete_redirect(): void {
		$id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

		if ( $id <= 0 ) {
			$this->redirect_with_error( 'delete' );
		}

		check_admin_referer( 'erankly_redirects_delete_' . $id );

		$success = $this->repository->delete( $id );
		$this->redirect_after_action( $success ? array( 'erankly_redirects_notice' => 'deleted' ) : array( 'erankly_redirects_error' => 'delete' ) );
	}

	/**
	 * Toggle redirect active state.
	 */
	private function handle_toggle_redirect(): void {
		$id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

		if ( $id <= 0 ) {
			$this->redirect_with_error( 'toggle' );
		}

		check_admin_referer( 'erankly_redirects_toggle_' . $id );

		$success = $this->repository->toggle_active( $id );
		$this->redirect_after_action( $success ? array( 'erankly_redirects_notice' => 'toggled' ) : array( 'erankly_redirects_error' => 'toggle' ) );
	}

	/**
	 * Validate and normalize redirect input.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array{0:array<string,mixed>,1:array<int,string>}
	 */
	private function prepare_redirect_data( array $input ): array {
		$source_raw       = isset( $input['source_path'] ) ? sanitize_text_field( wp_unslash( $input['source_path'] ) ) : '';
		$target_raw       = isset( $input['target_url'] ) ? trim( (string) wp_unslash( $input['target_url'] ) ) : '';
		$status_code      = isset( $input['status_code'] ) ? absint( $input['status_code'] ) : 301;
		$is_active        = ! empty( $input['is_active'] ) ? 1 : 0;
		$note             = isset( $input['note'] ) ? sanitize_textarea_field( wp_unslash( $input['note'] ) ) : '';
		$case_sensitive   = ! empty( $input['case_sensitive'] ) ? 1 : 0;
		$trailing_slash   = isset( $input['trailing_slash'] ) ? sanitize_key( wp_unslash( $input['trailing_slash'] ) ) : 'ignore';
		$query_mode       = isset( $input['query_mode'] ) ? sanitize_key( wp_unslash( $input['query_mode'] ) ) : 'ignore';
		$priority         = isset( $input['priority'] ) ? max( -10000, min( 10000, intval( $input['priority'] ) ) ) : 10;
		$source_plugin    = isset( $input['source_plugin'] ) ? sanitize_key( wp_unslash( $input['source_plugin'] ) ) : '';
		$source_reference = isset( $input['source_reference'] ) ? sanitize_text_field( wp_unslash( $input['source_reference'] ) ) : '';
		$migration_id     = isset( $input['migration_id'] ) ? sanitize_text_field( wp_unslash( $input['migration_id'] ) ) : '';
		$conditions       = null;
		if ( isset( $input['conditions'] ) ) {
			$raw_conditions = is_array( $input['conditions'] ) ? $input['conditions'] : json_decode( wp_unslash( (string) $input['conditions'] ), true );

			if ( is_array( $raw_conditions ) && ! empty( $raw_conditions ) ) {
				$conditions = wp_json_encode( $raw_conditions );
			}
		}

		// Derive match flags from match_type select (new UI) or legacy is_regex/is_wildcard columns (data import).
		if ( isset( $input['match_type'] ) ) {
			$match_type  = sanitize_key( wp_unslash( $input['match_type'] ) );
			$match_type  = in_array( $match_type, ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true ) ? $match_type : 'exact';
			$is_regex    = 'regex' === $match_type ? 1 : 0;
			$is_wildcard = 'wildcard' === $match_type ? 1 : 0;
		} else {
			$is_wildcard = ! empty( $input['is_wildcard'] ) ? 1 : 0;
			$is_regex    = ( ! $is_wildcard && ! empty( $input['is_regex'] ) ) ? 1 : 0;
			$match_type  = $is_wildcard ? 'wildcard' : ( $is_regex ? 'regex' : 'exact' );
		}

		if ( ! in_array( $trailing_slash, ERankly_Redirects_Normalizer::VALID_TRAILING_SLASH_MODES, true ) ) {
			$trailing_slash = 'ignore';
		}
		if ( ! in_array( $query_mode, ERankly_Redirects_Normalizer::VALID_QUERY_MODES, true ) ) {
			$query_mode = 'ignore';
		}

		$source_query_raw = isset( $input['source_query'] ) ? sanitize_text_field( wp_unslash( $input['source_query'] ) ) : '';
		if ( '' === $source_query_raw && 'regex' !== $match_type ) {
			$source_query_raw = ERankly_Redirects_Normalizer::extract_query( $source_raw );
		}
		$source_query   = 'exact' === $query_mode ? $source_query_raw : '';
		$source_path    = ERankly_Redirects_Normalizer::normalize_source( $source_raw, (bool) $is_regex, (bool) $is_wildcard, (bool) $case_sensitive, $trailing_slash );
		$is_status_only = ERankly_Redirects_Normalizer::is_status_only_code( $status_code );
		$target_url     = $is_status_only ? '' : ERankly_Redirects_Normalizer::normalize_target_url( $target_raw );
		$errors         = array();
		$normalize_date = static function ( mixed $value ): ?string {
			$value = sanitize_text_field( wp_unslash( (string) $value ) );
			if ( '' === $value ) {
				return null;
			}
			$date = date_create_immutable( $value, wp_timezone() );
			return $date instanceof DateTimeImmutable ? $date->format( 'Y-m-d H:i:s' ) : null;
		};
		$start_at       = $normalize_date( $input['start_at'] ?? '' );
		$end_at         = $normalize_date( $input['end_at'] ?? '' );

		// Visibility.
		$visibility = isset( $input['visibility'] ) ? sanitize_key( wp_unslash( $input['visibility'] ) ) : 'all';

		if ( ! in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ) {
			$visibility = 'all';
		}

		// Required role. It is only meaningful for logged_in, and only when the role exists.
		$required_role = isset( $input['required_role'] ) ? sanitize_key( wp_unslash( $input['required_role'] ) ) : '';

		if ( 'logged_in' !== $visibility || ( '' !== $required_role && ! array_key_exists( $required_role, get_editable_roles() ) ) ) {
			$required_role = '';
		}

		if ( '' === $source_path ) {
			$errors[] = 'source_required';
		}

		if ( strlen( $source_path ) > 512 ) {
			$errors[] = 'source_too_long';
		}

		if ( ! $is_status_only && '' === $target_url ) {
			$errors[] = 'target_required';
		}

		if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
			$errors[] = 'status_code';
		}

		if ( $is_wildcard && ! ERankly_Redirects_Normalizer::is_valid_wildcard_source( $source_path ) ) {
			$errors[] = 'wildcard';
		}

		if ( $is_regex && ! ERankly_Redirects_Normalizer::is_valid_regex( $source_path ) ) {
			$errors[] = 'regex';
		}

		if ( ! $is_regex && ! $is_wildcard && ! ERankly_Redirects_Normalizer::is_valid_internal_path( $source_path ) ) {
			$errors[] = 'source_path';
		}

		if ( null !== $start_at && null !== $end_at && $start_at > $end_at ) {
			$errors[] = 'schedule';
		}

		return array(
			array(
				'source_path'      => $source_path,
				'source_hash'      => ERankly_Redirects_Normalizer::source_hash( $source_path ),
				'source_query'     => $source_query,
				'target_url'       => $target_url,
				'status_code'      => $status_code,
				'match_type'       => $match_type,
				'is_regex'         => $is_regex,
				'is_wildcard'      => $is_wildcard,
				'case_sensitive'   => $case_sensitive,
				'trailing_slash'   => $trailing_slash,
				'query_mode'       => $query_mode,
				'priority'         => $priority,
				'is_active'        => $is_active,
				'visibility'       => $visibility,
				'required_role'    => $required_role,
				'conditions'       => $conditions,
				'start_at'         => $start_at,
				'end_at'           => $end_at,
				'source_plugin'    => $source_plugin,
				'source_reference' => $source_reference,
				'migration_id'     => $migration_id,
				'note'             => $note,
			),
			$errors,
		);
	}

	/**
	 * Redirect to admin page with an error code.
	 *
	 * @param string $error Error code.
	 */
	private function redirect_with_error( string $error ): void {
		$this->redirect_after_action( array( 'erankly_redirects_error' => $error ) );
	}

	/**
	 * Redirect to admin page after an action.
	 *
	 * @param array<string,mixed> $args Query args.
	 */
	private function redirect_after_action( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, $this->admin_url() ) );
		exit;
	}

	/**
	 * Get plugin admin URL.
	 *
	 * @return string
	 */
	private function admin_url(): string {
		return add_query_arg(
			array(
				'page'        => self::SLUG,
				'erankly_tab' => 'redirects',
			),
			admin_url( 'options-general.php' )
		);
	}
}
