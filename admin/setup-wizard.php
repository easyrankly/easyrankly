<?php
/**
 * First-run setup wizard.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the capability required to manage the wizard.
 *
 * @return string
 */
function erankly_setup_wizard_capability(): string {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Returns a setup wizard URL.
 *
 * @param string $step Optional wizard step.
 * @return string
 */
function erankly_setup_wizard_url( string $step = '' ): string {
	$url = is_multisite()
		? network_admin_url( 'settings.php?page=erankly-setup' )
		: admin_url( 'options-general.php?page=erankly-setup' );

	if ( '' !== $step ) {
		$url = add_query_arg( 'step', sanitize_key( $step ), $url );
	}

	return $url;
}

/**
 * Returns the settings URL for the current installation type.
 *
 * @return string
 */
function erankly_setup_wizard_settings_url(): string {
	return is_multisite()
		? network_admin_url( 'settings.php?page=erankly' )
		: admin_url( 'options-general.php?page=erankly' );
}

/**
 * Registers the hidden setup page.
 *
 * @return void
 */
function erankly_setup_wizard_register_page(): void {
	$parent_slug = is_network_admin() ? 'settings.php' : 'options-general.php';

	$hook = add_submenu_page(
		$parent_slug,
		__( 'EasyRankly setup', 'easyrankly' ),
		__( 'EasyRankly setup', 'easyrankly' ),
		erankly_setup_wizard_capability(),
		'erankly-setup',
		'erankly_setup_wizard_render'
	);

	remove_submenu_page( $parent_slug, 'erankly-setup' );

	// The page is removed from the submenu to keep it hidden, which leaves the
	// global $title unset when WordPress builds wp-admin/admin-header.php. Set it
	// on the page load hook (before the header is rendered) to avoid a
	// strip_tags( null ) deprecation notice on PHP 8.1+.
	if ( $hook ) {
		add_action(
			"load-{$hook}",
			static function (): void {
				$GLOBALS['title'] = __( 'EasyRankly setup', 'easyrankly' );
			}
		);
	}
}

/**
 * Redirects administrators to the wizard after a fresh installation.
 *
 * @return void
 */
function erankly_setup_wizard_maybe_redirect(): void {
	global $pagenow;

	if ( 'pending' !== erankly_get_plugin_option( ERANKLY_SETUP_STATUS_OPTION, '' ) ) {
		return;
	}

	if ( is_multisite() && ! is_network_admin() ) {
		return;
	}

	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		return;
	}

	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'get' !== $request_method ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	if ( 'erankly-setup' === $page ) {
		return;
	}

	if ( in_array( $pagenow, array( 'plugins.php', 'plugin-install.php', 'update.php', 'update-core.php', 'admin-post.php' ), true ) ) {
		return;
	}

	wp_safe_redirect( erankly_setup_wizard_url() );
	exit;
}

/**
 * Saves the setup choices.
 *
 * @return void
 */
function erankly_setup_wizard_save(): void {
	check_admin_referer( 'erankly_setup_save' );

	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	$mode             = isset( $_POST['simplified_mode'] ) ? sanitize_key( wp_unslash( $_POST['simplified_mode'] ) ) : '1';
	$twitter_site_raw = isset( $_POST['twitter_site'] ) ? sanitize_text_field( wp_unslash( $_POST['twitter_site'] ) ) : '';
	$settings         = erankly_get_settings();

	$settings['simplified_mode'] = '0' === $mode ? 0 : 1;
	$settings['twitter_site']    = erankly_sanitize_twitter_handle( $twitter_site_raw );

	erankly_update_plugin_option( ERANKLY_OPTION, $settings );
	erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'completed' );

	wp_safe_redirect( erankly_setup_wizard_url( 'complete' ) );
	exit;
}

/**
 * Dismisses the automatic first-run wizard.
 *
 * @return void
 */
function erankly_setup_wizard_skip(): void {
	check_admin_referer( 'erankly_setup_skip' );

	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'skipped' );

	wp_safe_redirect( erankly_setup_wizard_settings_url() );
	exit;
}

/**
 * Renders the setup wizard.
 *
 * @return void
 */
function erankly_setup_wizard_render(): void {
	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only wizard step.

	if ( ! in_array( $step, array( 'welcome', 'configure', 'complete' ), true ) ) {
		$step = 'welcome';
	}

	$settings = erankly_get_settings();
	?>
	<div class="wrap erankly-setup">
		<div class="erankly-setup-card">
			<div class="erankly-setup-header">
				<h1><?php esc_html_e( 'EasyRankly setup', 'easyrankly' ); ?></h1>
				<p><?php esc_html_e( 'Configure the few preferences that EasyRankly cannot determine automatically.', 'easyrankly' ); ?></p>
			</div>

			<ol class="erankly-setup-steps" aria-label="<?php esc_attr_e( 'Setup progress', 'easyrankly' ); ?>">
				<li class="<?php echo 'welcome' === $step ? 'is-current' : 'is-complete'; ?>"><?php esc_html_e( 'Welcome', 'easyrankly' ); ?></li>
				<li class="<?php echo 'configure' === $step ? 'is-current' : ( 'complete' === $step ? 'is-complete' : '' ); ?>"><?php esc_html_e( 'Preferences', 'easyrankly' ); ?></li>
				<li class="<?php echo 'complete' === $step ? 'is-current' : ''; ?>"><?php esc_html_e( 'Ready', 'easyrankly' ); ?></li>
			</ol>

			<div class="erankly-setup-content">
				<?php if ( 'welcome' === $step ) : ?>
					<h2><?php esc_html_e( 'Welcome to EasyRankly', 'easyrankly' ); ?></h2>
					<p><?php esc_html_e( 'This short wizard asks for your preferred interface mode and, if available, the X account associated with the site.', 'easyrankly' ); ?></p>
					<p><?php esc_html_e( 'You can change both choices later from the EasyRankly settings.', 'easyrankly' ); ?></p>
					<div class="erankly-setup-actions">
						<a class="button button-primary" href="<?php echo esc_url( erankly_setup_wizard_url( 'configure' ) ); ?>"><?php esc_html_e( 'Start setup', 'easyrankly' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="erankly_setup_skip">
							<?php wp_nonce_field( 'erankly_setup_skip' ); ?>
							<button type="submit" class="button button-link"><?php esc_html_e( 'Skip for now', 'easyrankly' ); ?></button>
						</form>
					</div>
				<?php elseif ( 'configure' === $step ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="erankly_setup_save">
						<?php wp_nonce_field( 'erankly_setup_save' ); ?>

						<fieldset class="erankly-setup-section">
							<legend><?php esc_html_e( 'Interface mode', 'easyrankly' ); ?></legend>
							<label class="erankly-setup-choice">
								<input type="radio" name="simplified_mode" value="1" <?php checked( ! empty( $settings['simplified_mode'] ) ); ?>>
								<span>
									<strong><?php esc_html_e( 'Simplified mode', 'easyrankly' ); ?></strong>
									<small><?php esc_html_e( 'Recommended. Shows the essential controls and automates advanced SEO defaults.', 'easyrankly' ); ?></small>
								</span>
							</label>
							<label class="erankly-setup-choice">
								<input type="radio" name="simplified_mode" value="0" <?php checked( empty( $settings['simplified_mode'] ) ); ?>>
								<span>
									<strong><?php esc_html_e( 'Advanced mode', 'easyrankly' ); ?></strong>
									<small><?php esc_html_e( 'Shows every available control for manual configuration.', 'easyrankly' ); ?></small>
								</span>
							</label>
						</fieldset>

						<div class="erankly-setup-section">
							<label for="erankly-setup-twitter-site"><strong><?php esc_html_e( 'X (Twitter) Site', 'easyrankly' ); ?></strong></label>
							<input id="erankly-setup-twitter-site" class="regular-text" type="text" name="twitter_site" value="<?php echo esc_attr( (string) $settings['twitter_site'] ); ?>" placeholder="@example" maxlength="64" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Optional. Enter an @handle or an x.com profile URL. It is used for the twitter:site meta tag.', 'easyrankly' ); ?></p>
						</div>

						<div class="erankly-setup-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'easyrankly' ); ?></button>
							<a class="button" href="<?php echo esc_url( erankly_setup_wizard_url() ); ?>"><?php esc_html_e( 'Back', 'easyrankly' ); ?></a>
						</div>
					</form>
				<?php else : ?>
					<h2><?php esc_html_e( 'EasyRankly is ready', 'easyrankly' ); ?></h2>
					<p><?php esc_html_e( 'Your preferences have been saved. You can now review the complete plugin settings or return to the dashboard.', 'easyrankly' ); ?></p>
					<div class="erankly-setup-summary">
						<p><strong><?php esc_html_e( 'Interface mode:', 'easyrankly' ); ?></strong> <?php echo ! empty( $settings['simplified_mode'] ) ? esc_html__( 'Simplified', 'easyrankly' ) : esc_html__( 'Advanced', 'easyrankly' ); ?></p>
						<p><strong><?php esc_html_e( 'X (Twitter) Site:', 'easyrankly' ); ?></strong> <?php echo '' !== $settings['twitter_site'] ? esc_html( (string) $settings['twitter_site'] ) : esc_html__( 'Not configured', 'easyrankly' ); ?></p>
					</div>
					<div class="erankly-setup-actions">
						<a class="button button-primary" href="<?php echo esc_url( erankly_setup_wizard_settings_url() ); ?>"><?php esc_html_e( 'Open EasyRankly settings', 'easyrankly' ); ?></a>
						<a class="button" href="<?php echo esc_url( is_multisite() ? network_admin_url() : admin_url() ); ?>"><?php esc_html_e( 'Return to dashboard', 'easyrankly' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}
