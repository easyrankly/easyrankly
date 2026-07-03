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
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect search term.
		$current_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$per_page      = 25;
		$total_items   = $this->repository->count_redirects( $search );
		$total_pages   = max( 1, (int) ceil( $total_items / $per_page ) );
		$redirects     = $this->repository->list_redirects( $search, $current_page, $per_page );

		?>
		<div class="erankly-redirects-wrap">
			<?php $this->render_notices(); ?>

			<section class="erankly-redirects-panel erankly-redirects-form-panel">
				<h2><?php echo $edit_redirect ? esc_html__( 'Edit Redirect', 'easyrankly' ) : esc_html__( 'Add Redirect', 'easyrankly' ); ?></h2>
				<?php $this->render_redirect_form( $edit_redirect ); ?>
			</section>

			<hr>

			<form method="get" class="erankly-redirects-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="erankly_tab" value="redirects">
				<label for="erankly-redirects-search-source" class="screen-reader-text"><?php esc_html_e( 'Search source path', 'easyrankly' ); ?></label>
				<input id="erankly-redirects-search-source" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search source path', 'easyrankly' ); ?>">
				<?php submit_button( __( 'Search', 'easyrankly' ), 'secondary', '', false ); ?>
				<?php if ( '' !== $search ) : ?>
					<a class="button" href="<?php echo esc_url( $this->admin_url() ); ?>"><?php esc_html_e( 'Clear', 'easyrankly' ); ?></a>
				<?php endif; ?>
			</form>

			<?php $this->render_redirect_table( $redirects ); ?>
			<?php $this->render_pagination( $current_page, $total_pages, $search ); ?>
		</div>
		<?php
	}

	// Form / section renderers.

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
	 * Render create/edit redirect form.
	 *
	 * @param array<string,mixed>|null $redirect Redirect row.
	 */
	private function render_redirect_form( ?array $redirect ): void {
		$id            = $redirect ? (int) $redirect['id'] : 0;
		$source_path   = $redirect ? (string) $redirect['source_path'] : '';
		$target_url    = $redirect ? (string) $redirect['target_url'] : '';
		$status_code   = $redirect ? (int) $redirect['status_code'] : 301;
		$is_active     = $redirect ? (bool) $redirect['is_active'] : true;
		$visibility    = $redirect ? (string) $redirect['visibility'] : 'all';
		$required_role = $redirect ? (string) $redirect['required_role'] : '';
		$note          = $redirect ? (string) ( $redirect['note'] ?? '' ) : '';

		// Derive match_type from stored flags.
		$match_type = 'exact';
		if ( $redirect ) {
			if ( ! empty( $redirect['is_wildcard'] ) ) {
				$match_type = 'wildcard';
			} elseif ( ! empty( $redirect['is_regex'] ) ) {
				$match_type = 'regex';
			}
		}

		$roles = get_editable_roles();

		?>
		<form method="post" action="<?php echo esc_url( $this->admin_url() ); ?>" class="erankly-redirects-form">
			<?php wp_nonce_field( 'erankly_redirects_save_redirect' ); ?>
			<input type="hidden" name="erankly_redirects_action" value="save_redirect">
			<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $id ); ?>">

			<label>
				<span><?php esc_html_e( 'Source URL', 'easyrankly' ); ?></span>
				<input type="text" name="source_path" value="<?php echo esc_attr( $source_path ); ?>" required placeholder="/old-page">
			</label>

			<label>
				<span><?php esc_html_e( 'Target URL', 'easyrankly' ); ?></span>
				<input type="text" name="target_url" value="<?php echo esc_attr( $target_url ); ?>" required placeholder="/new-page or https://example.com/new-page">
			</label>

			<div class="erankly-redirects-grid">
				<label>
					<span><?php esc_html_e( 'HTTP Code', 'easyrankly' ); ?></span>
					<select name="status_code">
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
						<option value="" <?php selected( $required_role, '' ); ?>><?php esc_html_e( '— Any logged-in user —', 'easyrankly' ); ?></option>
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
				</select>
				<p class="description"><?php esc_html_e( 'Exact: literal path. Wildcard: use * in source and target (e.g. /old/* → /new/*). Regex: full regular expression.', 'easyrankly' ); ?></p>
			</label>

			<label>
				<span><?php esc_html_e( 'Note', 'easyrankly' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'easyrankly' ); ?></span></span>
				<textarea name="note" rows="2"><?php echo esc_textarea( $note ); ?></textarea>
			</label>

			<label class="erankly-redirects-checkbox">
				<input type="checkbox" name="is_active" value="1" <?php checked( $is_active ); ?>>
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
	 */
	private function render_redirect_table( array $redirects ): void {
		?>
		<table class="widefat fixed striped erankly-redirects-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Target', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Code', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Type', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Active', 'easyrankly' ); ?></th>
					<th><?php esc_html_e( 'Condition', 'easyrankly' ); ?></th>
						<th><?php esc_html_e( 'Estimated Hits', 'easyrankly' ); ?></th>
						<th><?php esc_html_e( 'Last Sampled Hit', 'easyrankly' ); ?></th>
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
						<tr>
							<td>
								<code><?php echo esc_html( (string) $redirect['source_path'] ); ?></code>
								<?php if ( ! empty( $redirect['note'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( wp_trim_words( (string) $redirect['note'], 12, '…' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( (string) $redirect['target_url'] ); ?></code></td>
							<td><?php echo esc_html( (string) (int) $redirect['status_code'] ); ?></td>
							<td><?php echo esc_html( $this->format_match_type( $redirect ) ); ?></td>
							<td><?php echo ! empty( $redirect['is_active'] ) ? esc_html__( 'Yes', 'easyrankly' ) : esc_html__( 'No', 'easyrankly' ); ?></td>
							<td><?php echo esc_html( $this->format_condition( $redirect ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $redirect['hit_count'] ) ); ?></td>
							<td><?php echo empty( $redirect['last_hit_at'] ) ? esc_html__( 'Never', 'easyrankly' ) : esc_html( (string) $redirect['last_hit_at'] ); ?></td>
							<td class="erankly-redirects-actions">
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'easyrankly' ); ?></a>
								<a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo ! empty( $redirect['is_active'] ) ? esc_html__( 'Disable', 'easyrankly' ) : esc_html__( 'Enable', 'easyrankly' ); ?></a>
								<a class="button-link-delete erankly-redirects-delete" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'easyrankly' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
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
	 */
	private function render_pagination( int $current_page, int $total_pages, string $search ): void {
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

	// Action handlers.

	/**
	 * Save redirect action.
	 */
	private function handle_save_redirect(): void {
		// check_admin_referer() dies on failure, so no error branch is needed.
		check_admin_referer( 'erankly_redirects_save_redirect' );

		$id = isset( $_POST['redirect_id'] ) && is_string( $_POST['redirect_id'] )
			? absint( wp_unslash( $_POST['redirect_id'] ) )
			: 0;

		$input = array(
			'source_path'   => isset( $_POST['source_path'] ) && is_string( $_POST['source_path'] )
				? sanitize_text_field( wp_unslash( $_POST['source_path'] ) )
				: '',
			'target_url'    => isset( $_POST['target_url'] ) && is_string( $_POST['target_url'] )
				? sanitize_text_field( wp_unslash( $_POST['target_url'] ) )
				: '',
			'status_code'   => isset( $_POST['status_code'] ) && is_string( $_POST['status_code'] )
				? absint( wp_unslash( $_POST['status_code'] ) )
				: 301,
			'is_active'     => isset( $_POST['is_active'] ) ? 1 : 0,
			'note'          => isset( $_POST['note'] ) && is_string( $_POST['note'] )
				? sanitize_textarea_field( wp_unslash( $_POST['note'] ) )
				: '',
			'match_type'    => isset( $_POST['match_type'] ) && is_string( $_POST['match_type'] )
				? sanitize_key( wp_unslash( $_POST['match_type'] ) )
				: 'exact',
			'visibility'    => isset( $_POST['visibility'] ) && is_string( $_POST['visibility'] )
				? sanitize_key( wp_unslash( $_POST['visibility'] ) )
				: 'all',
			'required_role' => isset( $_POST['required_role'] ) && is_string( $_POST['required_role'] )
				? sanitize_key( wp_unslash( $_POST['required_role'] ) )
				: '',
		);

		list( $data, $errors ) = $this->prepare_redirect_data( $input );

		if ( ! empty( $errors ) ) {
			$this->redirect_with_error( 'invalid' );
		}

		if ( $id > 0 ) {
			$success = $this->repository->update( $id, $data );
			$this->redirect_after_action( $success ? array( 'erankly_redirects_notice' => 'updated' ) : array( 'erankly_redirects_error' => 'save' ) );
		}

		$created_id = $this->repository->create( $data );
		$this->redirect_after_action( $created_id > 0 ? array( 'erankly_redirects_notice' => 'created' ) : array( 'erankly_redirects_error' => 'save' ) );
	}

	/**
	 * Delete redirect action.
	 */
	private function handle_delete_redirect(): void {
		$id = isset( $_GET['redirect_id'] ) && is_string( $_GET['redirect_id'] )
			? absint( wp_unslash( $_GET['redirect_id'] ) )
			: 0;

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
		$id = isset( $_GET['redirect_id'] ) && is_string( $_GET['redirect_id'] )
			? absint( wp_unslash( $_GET['redirect_id'] ) )
			: 0;

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
		$source_raw  = isset( $input['source_path'] ) ? sanitize_text_field( (string) $input['source_path'] ) : '';
		$target_raw  = isset( $input['target_url'] ) ? trim( sanitize_text_field( (string) $input['target_url'] ) ) : '';
		$status_code = isset( $input['status_code'] ) ? absint( $input['status_code'] ) : 301;
		$is_active   = ! empty( $input['is_active'] ) ? 1 : 0;
		$note        = isset( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';

		// Derive match flags from match_type select (new UI) or legacy is_regex/is_wildcard columns (data import).
		if ( isset( $input['match_type'] ) ) {
			$match_type  = sanitize_key( (string) $input['match_type'] );
			$is_regex    = 'regex' === $match_type ? 1 : 0;
			$is_wildcard = 'wildcard' === $match_type ? 1 : 0;
		} else {
			$is_wildcard = ! empty( $input['is_wildcard'] ) ? 1 : 0;
			$is_regex    = ( ! $is_wildcard && ! empty( $input['is_regex'] ) ) ? 1 : 0;
		}

		$source_path = ERankly_Redirects_Normalizer::normalize_source( $source_raw, (bool) $is_regex, (bool) $is_wildcard );
		$target_url  = ERankly_Redirects_Normalizer::normalize_target_url( $target_raw );
		$errors      = array();

		// Visibility.
		$visibility = isset( $input['visibility'] ) ? sanitize_key( (string) $input['visibility'] ) : 'all';

		if ( ! in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ) {
			$visibility = 'all';
		}

		// Required role — only meaningful for logged_in, and only when the role exists.
		$required_role = isset( $input['required_role'] ) ? sanitize_key( (string) $input['required_role'] ) : '';

		if ( 'logged_in' !== $visibility || ( '' !== $required_role && ! array_key_exists( $required_role, get_editable_roles() ) ) ) {
			$required_role = '';
		}

		if ( '' === $source_path ) {
			$errors[] = 'source_required';
		}

		if ( strlen( $source_path ) > 512 ) {
			$errors[] = 'source_too_long';
		}

		if ( '' === $target_url ) {
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

		return array(
			array(
				'source_path'   => $source_path,
				'source_hash'   => ERankly_Redirects_Normalizer::source_hash( $source_path ),
				'target_url'    => $target_url,
				'status_code'   => $status_code,
				'is_regex'      => $is_regex,
				'is_wildcard'   => $is_wildcard,
				'is_active'     => $is_active,
				'visibility'    => $visibility,
				'required_role' => $required_role,
				'note'          => $note,
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
