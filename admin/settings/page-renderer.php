<?php
/**
 * Settings page orchestrator: server-side tab/subtab routing (network vs per-site scoping on Multisite),
 * panel data preparation, panel dispatch. Loads renderer files only for the active panel; the noscript
 * submit button is the no-JS save path and must not be hidden on autosave panels.
 */
defined( 'ABSPATH' ) || exit;
function erankly_render_settings_page(): void {
	$required_cap = is_network_admin() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $required_cap ) ) {
		return;
	}
	$settings                 = erankly_get_settings();
	$redirects_enabled        = erankly_redirects_enabled();
	$sitemap_enabled          = erankly_sitemap_enabled();
	$custom_code_enabled      = erankly_custom_code_enabled();
	$is_site_admin_on_network = is_multisite() && ! is_network_admin();
	$show_redirects_tab       = $redirects_enabled && ! is_network_admin();
	$show_sitemap_tab         = ! $is_site_admin_on_network && $sitemap_enabled;
	$show_custom_code_tab     = ! $is_site_admin_on_network && $custom_code_enabled;
	$show_feature_modules_nav = $show_redirects_tab || $show_sitemap_tab || $show_custom_code_tab;
	$show_site_special_tab = $is_site_admin_on_network && ! erankly_use_site_editor_special_page_panels();
	$site_panels           = array();
	if ( $show_site_special_tab ) {
		$site_panels[] = 'settings-special-pages';
	}
	if ( $show_redirects_tab ) {
		$site_panels[] = 'settings-redirects';
	}
	$show_import_export_tab = ! is_multisite() || is_network_admin();
	$requested_tab          = isset( $_GET['erankly_tab'] ) ? sanitize_key( wp_unslash( $_GET['erankly_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing for display; no state change (capability checked above).
	$requested_subtab       = isset( $_GET['erankly_subtab'] ) ? sanitize_key( wp_unslash( $_GET['erankly_subtab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only subtab routing for display; no state change.
	$active_panel           = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-general';
	$active_subtab          = '';
	$screen                 = get_current_screen();
	$screen_context         = array(
		'screen_id'   => $screen instanceof WP_Screen ? $screen->id : '',
		'scope'       => is_network_admin() ? 'network' : 'site',
		'current_tab' => $requested_tab,
	);
	if ( '' !== $requested_tab ) {
		$requested_tab = erankly_admin_resolve_settings_tab( $requested_tab );
	}
	$subtab_panel_map = array();
	if ( '' !== $requested_subtab ) {
		$post_type_objects  = erankly_get_public_post_types();
		$taxonomy_objects   = erankly_get_public_taxonomies();
		$special_page_items = erankly_special_page_keys();
		$general_subtabs    = array();
		if ( ! $is_site_admin_on_network ) {
			$general_subtabs = array_merge(
				erankly_get_global_meta_nav_subtabs(
					'global_post_type_meta',
					$post_type_objects,
					! array_key_exists( 'global_post_type_meta_linked', $settings ) || ! empty( $settings['global_post_type_meta_linked'] )
				),
				erankly_get_global_meta_nav_subtabs(
					'global_taxonomy_meta',
					$taxonomy_objects,
					! array_key_exists( 'global_taxonomy_meta_linked', $settings ) || ! empty( $settings['global_taxonomy_meta_linked'] )
				)
			);
			if ( ! is_multisite() && ! erankly_use_site_editor_special_page_panels() ) {
				$general_subtabs = array_merge(
					$general_subtabs,
					erankly_get_special_page_nav_subtabs( $special_page_items )
				);
			}
		}
		$site_special_subtabs = $show_site_special_tab ? erankly_get_special_page_nav_subtabs( $special_page_items ) : array();
		$social_subtabs       = $is_site_admin_on_network ? array() : erankly_get_social_nav_subtabs( $settings );
		foreach ( $general_subtabs as $item ) {
			if ( empty( $item['disabled'] ) ) {
				$subtab_panel_map[ $item['subtab'] ] = 'settings-general';
			}
		}
		foreach ( $site_special_subtabs as $item ) {
			$subtab_panel_map[ $item['subtab'] ] = 'settings-special-pages';
		}
		foreach ( $social_subtabs as $item ) {
			if ( empty( $item['disabled'] ) ) {
				$subtab_panel_map[ $item['subtab'] ] = 'settings-social';
			}
		}
	}
	$extra_tabs = erankly_normalize_settings_tabs(
		apply_filters( 'erankly_settings_tabs', array(), $screen_context ),
		$screen_context
	);
	$feature_module_tabs = array();
	$additional_tabs     = array();
	foreach ( $extra_tabs as $extra_slug => $extra_tab ) {
		if ( 'feature_modules' === ( $extra_tab['group'] ?? '' ) ) {
			$feature_module_tabs[ $extra_slug ] = $extra_tab;
		} else {
			$additional_tabs[ $extra_slug ] = $extra_tab;
		}
	}
	if ( ! empty( $feature_module_tabs ) ) {
		$show_feature_modules_nav = true;
	}
	if ( $is_site_admin_on_network ) {
		foreach ( $extra_tabs as $extra_slug => $extra_tab ) {
			$site_panels[] = 'settings-' . $extra_slug;
		}
	}
	$tab_panel_map = array(
		'general'       => 'settings-general',
		'features'      => 'settings-features',
		'social'        => 'settings-social',
		'schema'        => 'settings-schema',
		'sitemap'       => 'settings-sitemap',
		'custom-code'   => 'settings-custom-code',
		'settings'      => 'settings-settings',
		'advanced'      => 'settings-advanced',
		'import-export' => 'settings-import-export',
		'redirects'     => 'settings-redirects',
		'special-pages' => 'settings-special-pages',
	);
	foreach ( $extra_tabs as $extra_slug => $extra_tab ) {
		$tab_panel_map[ $extra_slug ] = 'settings-' . $extra_slug;
	}
	if ( '' !== $requested_tab && isset( $tab_panel_map[ $requested_tab ] ) ) {
		$candidate = $tab_panel_map[ $requested_tab ];
		if ( ! $is_site_admin_on_network || in_array( $candidate, $site_panels, true ) ) {
			$active_panel = $candidate;
		}
	}
	if ( '' !== $requested_subtab && isset( $subtab_panel_map[ $requested_subtab ] ) ) {
		$candidate = $subtab_panel_map[ $requested_subtab ];
		if ( ! $is_site_admin_on_network || in_array( $candidate, $site_panels, true ) ) {
			$active_panel  = $candidate;
			$active_subtab = $requested_subtab;
		}
	}
	if ( 'settings-redirects' === $active_panel && ! $show_redirects_tab ) {
		$active_panel  = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-features';
		$active_subtab = '';
	}
	if ( 'settings-custom-code' === $active_panel && ! $show_custom_code_tab ) {
		$active_panel  = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-features';
		$active_subtab = '';
	}
	if ( 'settings-advanced' === $active_panel && ! empty( $settings['simplified_mode'] ) ) {
		$active_panel  = 'settings-settings';
		$active_subtab = '';
	}
	if ( $is_site_admin_on_network && ! in_array( $active_panel, $site_panels, true ) ) {
		$active_panel  = $site_panels[0] ?? '';
		$active_subtab = '';
	}
	if ( in_array( $active_panel, array( 'settings-general', 'settings-social', 'settings-schema', 'settings-advanced', 'settings-special-pages', 'settings-custom-code' ), true ) ) {
		require_once ERANKLY_PATH . 'admin/field-renderers.php';
	}
	if ( in_array( $active_panel, array( 'settings-general', 'settings-social', 'settings-schema', 'settings-special-pages' ), true ) ) {
		require_once ERANKLY_PATH . 'admin/settings/renderers.php';
	}
	if ( 'settings-general' === $active_panel ) {
		$schema_person_user_id    = isset( $settings['schema_person_user_id'] ) ? absint( $settings['schema_person_user_id'] ) : 0;
		$schema_person_user       = $schema_person_user_id > 0 ? get_userdata( $schema_person_user_id ) : false;
		$show_organization_fields = 'person' !== $settings['schema_identity'];
	}
	if ( 'settings-schema' === $active_panel ) {
		$global_schema_blocks = isset( $settings['global_schema_blocks'] ) && is_array( $settings['global_schema_blocks'] ) ? $settings['global_schema_blocks'] : array();
		$global_schema_name   = ERANKLY_OPTION . '[global_schema_blocks]';
	}
	if ( 'settings-sitemap' === $active_panel ) {
		erankly_load_sitemap_helpers();
		$sitemap_url = erankly_get_sitemap_url( '/wp-sitemap.xml' );
	}
	if ( 'settings-custom-code' === $active_panel ) {
		$head_code_blocks       = isset( $settings['head_code_blocks'] ) && is_array( $settings['head_code_blocks'] ) ? array_values( $settings['head_code_blocks'] ) : array();
		$head_code_name         = ERANKLY_OPTION . '[head_code_blocks]';
		$body_open_code_blocks  = isset( $settings['body_open_code_blocks'] ) && is_array( $settings['body_open_code_blocks'] ) ? array_values( $settings['body_open_code_blocks'] ) : array();
		$body_open_code_name    = ERANKLY_OPTION . '[body_open_code_blocks]';
		$body_close_code_blocks = isset( $settings['body_close_code_blocks'] ) && is_array( $settings['body_close_code_blocks'] ) ? array_values( $settings['body_close_code_blocks'] ) : array();
		$body_close_code_name   = ERANKLY_OPTION . '[body_close_code_blocks]';
	}
	$standalone_panels = array( 'settings-import-export', 'settings-redirects', 'settings-special-pages' );
	foreach ( array_keys( $extra_tabs ) as $extra_slug ) {
		$standalone_panels[] = 'settings-' . $extra_slug;
	}
	$show_settings_submit = ! $is_site_admin_on_network && ! in_array( $active_panel, $standalone_panels, true );
	?>
	<div class="wrap erankly-settings">
		<?php
		$user_id        = get_current_user_id();
		$stored_notices = $user_id > 0 ? get_transient( 'erankly_settings_notices_' . $user_id ) : false;
		if ( is_array( $stored_notices ) ) {
			delete_transient( 'erankly_settings_notices_' . $user_id );
			foreach ( $stored_notices as $notice ) {
				if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
					continue;
				}
				$type = isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'warning';
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $type ),
					esc_html( (string) $notice['message'] )
				);
			}
		}
		if ( is_network_admin() ) {
			if ( isset( $_GET['erankly_settings_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag for incomplete-save notice.
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Settings were saved, but the configuration is incomplete.', 'easyrankly' ) . '</p></div>';
			} elseif ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag for the save confirmation notice.
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'easyrankly' ) . '</p></div>';
			}
		} else {
			if ( $is_site_admin_on_network && isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag for the save confirmation notice.
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'easyrankly' ) . '</p></div>';
			}
			settings_errors( ERANKLY_OPTION );
		}
		?>
		<?php if ( $is_site_admin_on_network ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %s: Network Admin settings URL. */
					esc_html__( 'Global SEO settings are managed from the %s.', 'easyrankly' ),
					'<a href="' . esc_url( network_admin_url( 'settings.php?page=erankly' ) ) . '">' . esc_html__( 'Network Admin', 'easyrankly' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php endif; ?>
		<div class="erankly-settings-layout">
			<div class="erankly-settings-sidebar-nav" data-erankly-sidebar-nav>
				<h1><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h1>
				<button type="button" class="erankly-settings-sidebar-toggle" aria-expanded="false" data-erankly-sidebar-toggle>
					<span data-erankly-sidebar-toggle-label></span>
				</button>
				<nav class="erankly-settings-nav-tablist" aria-label="<?php esc_attr_e( 'Plugin settings', 'easyrankly' ); ?>" data-erankly-settings-tablist data-erankly-server-tabs data-erankly-active-panel="<?php echo esc_attr( $active_panel ); ?>" data-erankly-active-subtab="<?php echo esc_attr( $active_subtab ); ?>">
				<?php if ( ! $is_site_admin_on_network ) : ?>
					<?php erankly_render_settings_nav_link( 'general', __( 'General', 'easyrankly' ), $active_panel ); ?>
					<?php erankly_render_settings_nav_link( 'social', __( 'Social', 'easyrankly' ), $active_panel ); ?>
					<?php erankly_render_settings_nav_link( 'schema', __( 'Schema', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( $show_site_special_tab ) : ?>
					<?php erankly_render_settings_nav_link( 'special-pages', __( 'General', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( ! $is_site_admin_on_network ) : ?>
					<?php erankly_render_settings_nav_link( 'advanced', __( 'Advanced', 'easyrankly' ), $active_panel, ! empty( $settings['simplified_mode'] ) ); ?>
					<?php erankly_render_settings_nav_link( 'features', __( 'Features', 'easyrankly' ), $active_panel ); ?>
					<?php erankly_render_settings_nav_link( 'settings', __( 'Settings', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( $show_import_export_tab ) : ?>
					<?php erankly_render_settings_nav_link( 'import-export', __( 'Import / Export', 'easyrankly' ), $active_panel ); ?>
				<?php endif; ?>
				<?php if ( $show_feature_modules_nav ) : ?>
				<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-feature-modules">
					<span class="erankly-settings-nav-heading" id="erankly-settings-nav-feature-modules"><?php esc_html_e( 'Feature modules', 'easyrankly' ); ?></span>
					<?php if ( $show_redirects_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'redirects', __( 'Redirects', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
					<?php if ( $show_sitemap_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'sitemap', __( 'Sitemap', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
					<?php if ( $show_custom_code_tab ) : ?>
						<?php erankly_render_settings_nav_link( 'custom-code', __( 'Custom code', 'easyrankly' ), $active_panel ); ?>
					<?php endif; ?>
					<?php foreach ( $feature_module_tabs as $extra_slug => $extra_tab ) : ?>
						<?php erankly_render_settings_nav_link( $extra_slug, $extra_tab['label'], $active_panel ); ?>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php if ( ! empty( $additional_tabs ) ) : ?>
				<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-modules">
					<span class="erankly-settings-nav-heading" id="erankly-settings-nav-modules"><?php esc_html_e( 'Additional Modules', 'easyrankly' ); ?></span>
					<?php foreach ( $additional_tabs as $extra_slug => $extra_tab ) : ?>
						<?php erankly_render_settings_nav_link( $extra_slug, $extra_tab['label'], $active_panel ); ?>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-useful-resources">
					<span class="erankly-settings-nav-heading" id="erankly-settings-nav-useful-resources"><?php esc_html_e( 'Useful resources', 'easyrankly' ); ?></span>
					<a class="erankly-settings-nav-item" href="<?php echo esc_url( add_query_arg( 'utm_source', 'easyrankly-settings-nav', 'https://docs.easyrankly.com/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo wp_kses( erankly_nav_icon( 'documentation' ), erankly_nav_icon_allowed_html() ); ?><span class="erankly-settings-nav-label"><?php esc_html_e( 'Documentation', 'easyrankly' ); ?></span></a>
					<a class="erankly-settings-nav-item" href="<?php echo esc_url( add_query_arg( 'utm_source', 'easyrankly-settings-nav', 'https://easyrankly.com/help/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo wp_kses( erankly_nav_icon( 'help' ), erankly_nav_icon_allowed_html() ); ?><span class="erankly-settings-nav-label"><?php esc_html_e( 'Need help?', 'easyrankly' ); ?></span></a>
				</div>
				</nav>
				<span class="erankly-autosave-status" data-erankly-autosave-status aria-live="polite"></span>
			</div>
			<div class="erankly-settings-content">
				<?php if ( is_network_admin() ) : ?>
				<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=erankly_network_save' ) ); ?>">
					<?php wp_nonce_field( 'erankly_network_settings' ); ?>
				<?php elseif ( ! $is_site_admin_on_network ) : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'erankly' ); ?>
				<?php endif; ?>
					<input type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[erankly_settings_panel]" value="<?php echo esc_attr( erankly_active_panel_submission_slug( $active_panel ) ); ?>">
					<?php if ( 'settings-features' === $active_panel ) : ?>
							<?php erankly_render_settings_panel_features( $settings, $redirects_enabled, $sitemap_enabled, $custom_code_enabled ); ?>
					<?php endif; ?>
					<?php if ( 'settings-general' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_general( $settings, $schema_person_user_id, $schema_person_user, $show_organization_fields ); ?>
					<?php endif; ?>
					<?php if ( 'settings-social' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_social( $settings ); ?>
					<?php endif; ?>
					<?php if ( 'settings-schema' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_schema( $settings, $global_schema_blocks, $global_schema_name ); ?>
					<?php endif; ?>
				<?php if ( $sitemap_enabled && 'settings-sitemap' === $active_panel ) : ?>
					<?php erankly_render_settings_panel_sitemap( $settings, $sitemap_url ); ?>
				<?php endif; ?>
					<?php if ( $custom_code_enabled && 'settings-custom-code' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_custom_code( $head_code_blocks, $head_code_name, $body_open_code_blocks, $body_open_code_name, $body_close_code_blocks, $body_close_code_name ); ?>
					<?php endif; ?>
					<?php if ( 'settings-settings' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_settings( $settings ); ?>
					<?php endif; ?>
					<?php if ( 'settings-advanced' === $active_panel ) : ?>
						<?php erankly_render_settings_panel_advanced( $settings ); ?>
					<?php endif; ?>
					<?php if ( $show_settings_submit ) : ?>
					<noscript>
						<div class="erankly-settings-submit">
							<?php submit_button(); ?>
						</div>
					</noscript>
					<?php endif; ?>
				<?php if ( ! $is_site_admin_on_network ) : ?>
				</form>
				<?php endif; ?>
			<?php if ( $show_site_special_tab && 'settings-special-pages' === $active_panel ) : ?>
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-special-pages" role="region" aria-labelledby="erankly-settings-tab-special-pages" data-erankly-settings-panel="settings-special-pages">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'erankly_site_special_meta' ); ?>
					<input type="hidden" name="action" value="erankly_save_site_special_meta">
					<div class="erankly-settings-section">
						<div class="erankly-section-title-row">
							<h2 class="erankly-section-title"><?php esc_html_e( 'Special pages and archives', 'easyrankly' ); ?></h2>
							<?php erankly_render_section_doc_link( 'special-pages' ); ?>
						</div>
						<div class="erankly-card">
							<?php erankly_render_special_page_defaults( erankly_special_page_keys(), array( 'global_special_meta' => erankly_get_site_special_meta() ) ); ?>
						</div>
					</div>
				</form>
			</div>
			<?php endif; ?>
			<?php if ( $show_import_export_tab && 'settings-import-export' === $active_panel && function_exists( 'erankly_import_export_render_panel' ) ) : ?>
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-import-export" role="region" aria-labelledby="erankly-settings-tab-import-export" data-erankly-settings-panel="settings-import-export">
				<?php erankly_import_export_render_panel(); ?>
			</div>
			<?php endif; ?>
			<?php if ( $show_redirects_tab && 'settings-redirects' === $active_panel && function_exists( 'erankly_redirects_render_panel' ) ) : ?>
			<div class="erankly-tab-panel is-active" role="region" aria-labelledby="erankly-settings-tab-redirects" data-erankly-settings-panel="settings-redirects">
				<?php erankly_redirects_render_panel(); ?>
			</div>
			<?php endif; ?>
			<?php
			foreach ( $extra_tabs as $extra_slug => $extra_tab ) :
				if ( ! current_user_can( $extra_tab['capability'] ) ) {
					continue;
				}
				$extra_panel = 'settings-' . $extra_slug;
				if ( $extra_panel !== $active_panel ) {
					continue;
				}
				?>
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-<?php echo esc_attr( $extra_slug ); ?>" role="region" aria-labelledby="erankly-settings-tab-<?php echo esc_attr( $extra_slug ); ?>" data-erankly-settings-panel="<?php echo esc_attr( $extra_panel ); ?>">
				<?php
				do_action( 'erankly_render_settings_tab_' . $extra_slug, $screen_context );
				?>
			</div>
			<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}
