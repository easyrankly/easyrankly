<?php
/** First-run setup wizard. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_setup_wizard_handle_save(): void {
	check_admin_referer( 'erankly_setup_save' );

	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	$mode             = isset( $_POST['simplified_mode'] ) ? sanitize_key( wp_unslash( $_POST['simplified_mode'] ) ) : '1';
	$identity_raw     = isset( $_POST['schema_identity'] ) ? sanitize_key( wp_unslash( $_POST['schema_identity'] ) ) : 'organization';
	$person_user_id   = isset( $_POST['schema_person_user_id'] ) ? absint( wp_unslash( $_POST['schema_person_user_id'] ) ) : 0;
	$name_raw         = isset( $_POST['organization_name'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_name'] ) ) : '';
	$twitter_site_raw = isset( $_POST['twitter_site'] ) ? sanitize_text_field( wp_unslash( $_POST['twitter_site'] ) ) : '';
	$settings         = erankly_get_settings();

	$settings['simplified_mode']       = '0' === $mode ? 0 : 1;
	$settings['schema_identity']       = 'person' === $identity_raw ? 'person' : 'organization';
	$settings['schema_person_user_id'] = $person_user_id > 0 && get_userdata( $person_user_id ) ? $person_user_id : 0;
	$settings['organization_name']     = '' !== $name_raw ? $name_raw : erankly_default_organization_name_template();
	$settings['twitter_site']          = erankly_sanitize_twitter_handle( $twitter_site_raw );

	erankly_update_plugin_option( ERANKLY_OPTION, $settings );
	erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'completed' );

	wp_safe_redirect( erankly_setup_wizard_url( 'complete' ) );
	exit;
}

/** Dismisses the automatic first-run wizard. */
function erankly_setup_wizard_handle_skip(): void {
	check_admin_referer( 'erankly_setup_skip' );

	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	erankly_update_plugin_option( ERANKLY_SETUP_STATUS_OPTION, 'skipped' );

	wp_safe_redirect( erankly_setup_wizard_settings_url() );
	exit;
}

function erankly_setup_wizard_render_screen(): void {
	if ( ! current_user_can( erankly_setup_wizard_capability() ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only wizard step.

	if ( ! in_array( $step, array( 'welcome', 'configure', 'complete' ), true ) ) {
		$step = 'welcome';
	}

	$settings = erankly_get_settings();

	$default_name_template = erankly_default_organization_name_template();
	$stored_name           = (string) $settings['organization_name'];
	$name_is_auto          = ( '' === $stored_name || $stored_name === $default_name_template );
	$name_value            = $name_is_auto ? '' : $stored_name;
	$resolved_name         = erankly_get_organization_name();
	$is_person             = 'person' === $settings['schema_identity'];
	$schema_person_user_id = absint( $settings['schema_person_user_id'] );
	$schema_person_user    = $schema_person_user_id > 0 ? get_userdata( $schema_person_user_id ) : false;
	?>
	<div class="wrap erankly-setup">
		<div class="erankly-setup-card">
			<div class="erankly-setup-header">
				<h1><?php esc_html_e( 'EasyRankly setup', 'easyrankly' ); ?></h1>
				<p><?php esc_html_e( 'Configure the few preferences that EasyRankly cannot determine automatically.', 'easyrankly' ); ?></p>
			</div>

			<ol class="erankly-tabs erankly-setup-steps" aria-label="<?php esc_attr_e( 'Setup progress', 'easyrankly' ); ?>">
				<li class="erankly-tab <?php echo 'welcome' === $step ? 'is-current is-active' : 'is-complete'; ?>"><?php esc_html_e( 'Welcome', 'easyrankly' ); ?></li>
				<li class="erankly-tab <?php echo 'configure' === $step ? 'is-current is-active' : ( 'complete' === $step ? 'is-complete' : '' ); ?>"><?php esc_html_e( 'Preferences', 'easyrankly' ); ?></li>
				<li class="erankly-tab <?php echo 'complete' === $step ? 'is-current is-active' : ''; ?>"><?php esc_html_e( 'Ready', 'easyrankly' ); ?></li>
			</ol>

			<div class="erankly-setup-content">
				<?php if ( 'welcome' === $step ) : ?>
					<h2><?php esc_html_e( 'Welcome to EasyRankly', 'easyrankly' ); ?></h2>
					<p><?php esc_html_e( 'This short wizard asks for your preferred interface mode, whether the site represents an organization or a person, and a few optional details EasyRankly cannot detect on its own.', 'easyrankly' ); ?></p>
					<p><?php esc_html_e( 'You can change every choice later from the EasyRankly settings.', 'easyrankly' ); ?></p>
					<div class="erankly-setup-actions">
						<a class="button button-primary" href="<?php echo esc_url( erankly_setup_wizard_url( 'configure' ) ); ?>"><?php esc_html_e( 'Start setup', 'easyrankly' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="erankly_setup_skip">
							<?php wp_nonce_field( 'erankly_setup_skip' ); ?>
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Skip for now', 'easyrankly' ); ?></button>
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
									<span class="erankly-setup-choice-title"><?php esc_html_e( 'Simplified mode', 'easyrankly' ); ?></span>
									<small><?php esc_html_e( 'Recommended. Shows the essential controls and automates advanced SEO defaults.', 'easyrankly' ); ?></small>
								</span>
							</label>
							<label class="erankly-setup-choice">
								<input type="radio" name="simplified_mode" value="0" <?php checked( empty( $settings['simplified_mode'] ) ); ?>>
								<span>
									<span class="erankly-setup-choice-title"><?php esc_html_e( 'Advanced mode', 'easyrankly' ); ?></span>
									<small><?php esc_html_e( 'Shows every available control for manual configuration.', 'easyrankly' ); ?></small>
								</span>
							</label>
						</fieldset>

						<fieldset class="erankly-setup-section">
							<legend><?php esc_html_e( 'Site identity', 'easyrankly' ); ?></legend>
							<p class="description"><?php esc_html_e( 'Tells search engines whether the site represents a company or brand, or a single individual. This is the basis of the JSON-LD knowledge graph and the publisher node.', 'easyrankly' ); ?></p>
							<label class="erankly-setup-choice">
								<input type="radio" name="schema_identity" value="organization" <?php checked( ! $is_person ); ?> data-erankly-setup-identity>
								<span>
									<span class="erankly-setup-choice-title"><?php esc_html_e( 'Organization', 'easyrankly' ); ?></span>
									<small><?php esc_html_e( 'A company, brand, store, or any team-run site.', 'easyrankly' ); ?></small>
								</span>
							</label>
							<label class="erankly-setup-choice">
								<input type="radio" name="schema_identity" value="person" <?php checked( $is_person ); ?> data-erankly-setup-identity>
								<span>
									<span class="erankly-setup-choice-title"><?php esc_html_e( 'Person', 'easyrankly' ); ?></span>
									<small><?php esc_html_e( 'A personal blog or portfolio run by a single individual.', 'easyrankly' ); ?></small>
								</span>
							</label>
						</fieldset>

							<div class="erankly-setup-section" data-erankly-setup-person <?php echo $is_person ? '' : 'hidden'; ?>>
								<span class="erankly-setup-field-label" id="erankly-setup-person-reference-label"><?php esc_html_e( 'Person reference user', 'easyrankly' ); ?></span>
							<div class="erankly-user-search-wrap" data-erankly-user-search-wrap>
								<input type="hidden"
									name="schema_person_user_id"
									value="<?php echo esc_attr( (string) $schema_person_user_id ); ?>"
									data-erankly-user-id>
								<div class="erankly-autocomplete-control erankly-user-control">
									<div class="erankly-autocomplete-value erankly-user-selected" data-erankly-user-selected<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>>
										<input type="text"
												class="widefat erankly-user-selected-input"
												readonly
												aria-labelledby="erankly-setup-person-reference-label"
											value="<?php echo ( $schema_person_user instanceof WP_User ) ? esc_attr( sprintf( /* translators: 1: User display name, 2: User ID. */ __( '%1$s (ID: %2$d)', 'easyrankly' ), $schema_person_user->display_name, $schema_person_user->ID ) ) : ''; ?>"
											data-erankly-user-selected-name>
									</div>
									<div class="erankly-autocomplete-search erankly-user-search" data-erankly-user-search-input-wrap<?php echo ( $schema_person_user instanceof WP_User ) ? ' hidden' : ''; ?>>
										<input type="search"
											class="widefat erankly-user-search-input"
											placeholder="<?php esc_attr_e( 'Search users…', 'easyrankly' ); ?>"
												autocomplete="off"
												aria-autocomplete="list"
												aria-labelledby="erankly-setup-person-reference-label"
											data-erankly-user-search-input>
										<ul class="erankly-autocomplete-results erankly-user-results" role="listbox" hidden data-erankly-user-results></ul>
									</div>
									<button type="button" class="button erankly-user-remove" data-erankly-user-remove<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'easyrankly' ); ?></button>
								</div>
							</div>
							<p class="description"><?php esc_html_e( 'The WordPress profile used for the global Person JSON-LD schema.', 'easyrankly' ); ?></p>
						</div>

						<div class="erankly-setup-row">
							<div class="erankly-setup-section">
									<label for="erankly-setup-name"><?php esc_html_e( 'Organization or person name', 'easyrankly' ); ?></label>
								<input id="erankly-setup-name" class="regular-text" type="text" name="organization_name" value="<?php echo esc_attr( $name_value ); ?>" placeholder="<?php echo esc_attr( $resolved_name ); ?>" maxlength="200" autocomplete="off">
								<p class="description"><?php esc_html_e( 'Leave blank to use the site name automatically.', 'easyrankly' ); ?></p>
							</div>

							<div class="erankly-setup-section">
									<label for="erankly-setup-twitter-site"><?php esc_html_e( 'X (Twitter) Site', 'easyrankly' ); ?></label>
								<input id="erankly-setup-twitter-site" class="regular-text" type="text" name="twitter_site" value="<?php echo esc_attr( (string) $settings['twitter_site'] ); ?>" placeholder="@example" maxlength="64" autocomplete="off">
								<p class="description"><?php esc_html_e( 'Optional. Enter an @handle or an x.com profile URL. It is used for the twitter:site meta tag.', 'easyrankly' ); ?></p>
							</div>
						</div>

						<div class="erankly-setup-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'easyrankly' ); ?></button>
							<a class="button" href="<?php echo esc_url( erankly_setup_wizard_url() ); ?>"><?php esc_html_e( 'Back', 'easyrankly' ); ?></a>
						</div>
					</form>
				<?php else : ?>
					<h2 class="erankly-setup-ready-title">
						<span class="erankly-setup-ready-icon" aria-hidden="true"><span class="dashicons dashicons-yes-alt"></span></span>
						<?php esc_html_e( 'EasyRankly is ready', 'easyrankly' ); ?>
					</h2>
					<p><?php esc_html_e( 'Your preferences have been saved. You can now review the complete plugin settings or return to the dashboard.', 'easyrankly' ); ?></p>
					<dl class="erankly-setup-summary">
						<div class="erankly-setup-summary-row">
							<dt><?php esc_html_e( 'Interface mode', 'easyrankly' ); ?></dt>
							<dd><?php echo ! empty( $settings['simplified_mode'] ) ? esc_html__( 'Simplified', 'easyrankly' ) : esc_html__( 'Advanced', 'easyrankly' ); ?></dd>
						</div>
						<div class="erankly-setup-summary-row">
							<dt><?php esc_html_e( 'Site identity', 'easyrankly' ); ?></dt>
							<dd><?php echo $is_person ? esc_html__( 'Person', 'easyrankly' ) : esc_html__( 'Organization', 'easyrankly' ); ?></dd>
						</div>
						<div class="erankly-setup-summary-row">
							<dt><?php esc_html_e( 'Name', 'easyrankly' ); ?></dt>
							<dd><?php echo '' !== $resolved_name ? esc_html( $resolved_name ) : esc_html__( 'Not configured', 'easyrankly' ); ?></dd>
						</div>
						<div class="erankly-setup-summary-row">
							<dt><?php esc_html_e( 'X (Twitter) Site', 'easyrankly' ); ?></dt>
							<dd><?php echo '' !== $settings['twitter_site'] ? esc_html( (string) $settings['twitter_site'] ) : esc_html__( 'Not configured', 'easyrankly' ); ?></dd>
						</div>
					</dl>
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
