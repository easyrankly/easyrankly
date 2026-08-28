<?php
/**
 * Settings and classic editor assets.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues admin assets only where needed.
 *
 * @param string $hook_suffix Admin hook.
 * @return void
 */
function erankly_admin_enqueue_assets( string $hook_suffix ): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen ) {
		return;
	}

	$is_settings     = 'settings_page_erankly' === $hook_suffix;
	$is_setup        = isset( $_GET['page'] ) && 'erankly-setup' === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	$is_editor       = in_array( $screen->post_type, array_keys( erankly_get_public_post_types() ), true );
	$is_taxonomy     = in_array( $screen->taxonomy, array_keys( erankly_get_public_taxonomies() ), true );
	$is_block_editor = $is_editor && $screen->is_block_editor();
	$is_site_editor  = 'site-editor' === $screen->base;

	if ( ! $is_settings && ! $is_setup && ! $is_editor && ! $is_taxonomy && ! $is_site_editor ) {
		return;
	}

	erankly_load_content_helpers();

	if ( $is_site_editor ) {
		erankly_admin_enqueue_site_editor_assets();
		return;
	}

	if ( $is_block_editor ) {
		erankly_admin_enqueue_block_editor_assets();
		return;
	}

	$settings_tab = $is_settings ? erankly_admin_resolve_settings_tab( erankly_admin_requested_settings_tab() ) : '';
	$surface      = $is_settings ? 'settings:' . $settings_tab : '';

	if ( $is_setup ) {
		$surface = 'setup';
	} elseif ( $is_taxonomy ) {
		$surface = 'taxonomy';
	} elseif ( $is_editor ) {
		$surface = 'classic-editor';
	}

	$asset_modules = erankly_admin_asset_modules( $surface );

	erankly_enqueue_shared_styles();

	wp_enqueue_style(
		'erankly-admin',
		ERANKLY_URL . 'assets/css/admin-core.css',
		array( 'erankly-shared' ),
		ERANKLY_VERSION
	);

	// Fields, tabs, schema builders and other admin components are shared by
	// settings, the classic editor, and taxonomy screens. Keep one canonical
	// implementation instead of duplicating it in classic-editor.css.
	// Autocomplete chrome lives in admin-core.css so the setup wizard can
	// reuse the Person reference control without this heavier sheet.
	if ( ! $is_setup ) {
		wp_enqueue_style( 'erankly-admin-settings', ERANKLY_URL . 'assets/css/admin-settings.css', array( 'erankly-admin' ), ERANKLY_VERSION );
	}

	if ( $is_settings ) {
		if ( 'import-export' === $settings_tab ) {
			wp_enqueue_style( 'erankly-migration', ERANKLY_URL . 'assets/css/migration.css', array( 'erankly-admin-settings' ), ERANKLY_VERSION );
		}

		if ( 'settings' === $settings_tab ) {
			wp_enqueue_style( 'erankly-reset', ERANKLY_URL . 'assets/css/reset.css', array( 'erankly-admin-settings' ), ERANKLY_VERSION );
		}
	} elseif ( $is_setup ) {
		wp_enqueue_style( 'erankly-setup', ERANKLY_URL . 'assets/css/setup.css', array( 'erankly-admin' ), ERANKLY_VERSION );
	} else {
		wp_enqueue_style( 'erankly-classic-editor', ERANKLY_URL . 'assets/css/classic-editor.css', array( 'erankly-admin-settings' ), ERANKLY_VERSION );
	}

	erankly_admin_enqueue_scripts( $asset_modules );

	// The wizard's "Person reference user" field reuses the searchable
	// user-search widget from the General settings panel (see bindUserSearch()
	// in admin.js), so it needs the same restUrl/nonce localized here.
	if ( $is_setup || ( $is_settings && 'general' === $settings_tab ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklyUserSearch',
			array(
				'restUrl' => esc_url_raw( rest_url( 'erankly/v1/users/search' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'searching'  => __( 'Searching…', 'easyrankly' ),
					'noResults'  => __( 'No matches found.', 'easyrankly' ),
					'remove'     => __( 'Remove', 'easyrankly' ),
					'noSelected' => __( 'No user selected', 'easyrankly' ),
				),
			)
		);
	}

	if ( $is_setup ) {
		wp_enqueue_script(
			'erankly-setup',
			ERANKLY_URL . 'assets/js/setup-wizard.js',
			array( 'erankly-admin' ),
			ERANKLY_VERSION,
			true
		);
		return;
	}

	if ( in_array( 'media', $asset_modules, true ) ) {
		wp_enqueue_media();
		do_action( 'erankly_admin_media_enqueued', $surface );
	}

	// Drives the "show resolved value, revert to raw {{token}} on focus"
	// behavior for every plain PHP-rendered {{variable}} field (settings
	// page defaults, classic meta boxes, term forms). See bindVariablePicker()
	// in admin.js. The block editor's React fields get the same preference
	// through eranklyEditor/eranklySiteEditor instead.
	if ( in_array( 'variables', $asset_modules, true ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklyVariablePreview',
			array(
				'resolvePlaceholders' => (bool) erankly_get_setting( 'resolve_placeholders', 1 ),
				'siteDescription'     => get_bloginfo( 'description' ),
				'siteName'            => get_bloginfo( 'name' ),
			)
		);
	}

	if ( $is_editor && ! $is_block_editor ) {
		$post = get_post();

		if ( $post instanceof WP_Post ) {
			require_once ERANKLY_PATH . 'admin/meta-box.php';

			wp_localize_script(
				'erankly-admin',
				'eranklyChecklist',
				array_merge(
					array(
						'descriptionPlaceholder' => erankly_get_post_global_meta_placeholder( $post, 'description', ERANKLY_SEO_CHECKLIST_DESCRIPTION_LIMIT ),
						'simplifiedMode'         => (bool) erankly_get_setting( 'simplified_mode', 1 ),
						'siteDescription'        => get_bloginfo( 'description' ),
						'siteName'               => get_bloginfo( 'name' ),
						'titlePlaceholder'       => erankly_get_post_global_meta_placeholder( $post, 'title', ERANKLY_SEO_CHECKLIST_TITLE_LIMIT ),
						'variableExamples'       => erankly_get_admin_variable_examples( $post ),
					),
					erankly_get_seo_checklist_editor_config( $post )
				)
			);
		}
	}

	// Strings for the shared expandable table panel (bindExpandablePanel).
	if ( in_array( 'panels', $asset_modules, true ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklyPanels',
			array(
				'expand'   => __( 'Expand table', 'easyrankly' ),
				'collapse' => __( 'Collapse table', 'easyrankly' ),
			)
		);
	}

	// Panels autosave via REST as they're wired up (see
	// erankly_settings_autosave_panels() in admin/settings-page.php); each
	// entry below is added the same turn its panel starts autosaving, so the
	// JS bootstrap (bindSettingsAutosave in admin.js) only ever binds panels
	// that actually have a config here. It is unconditional on multisite/network
	// state because different panels are reachable in each: General/Features/
	// etc. only on single-site or Network Admin, but Special pages is the
	// opposite (only a per-site admin on Multisite ever sees that tab).
	if ( $is_settings && in_array( 'settings', $asset_modules, true ) ) {
		wp_localize_script(
			'erankly-admin',
			'eranklySettingsAutosave',
			array(
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'i18n'   => array(
					'saving'  => __( 'Saving…', 'easyrankly' ),
					'saved'   => __( 'Saved', 'easyrankly' ),
					'warning' => __( 'Saved with warnings', 'easyrankly' ),
					'retry'   => __( 'Saving failed. Retrying…', 'easyrankly' ),
					'error'   => __( 'Could not save. Reload the page.', 'easyrankly' ),
				),
				// Deliberately hand-written rather than derived from
				// erankly_settings_autosave_panels(): on Network Admin,
				// admin/settings-page.php isn't loaded yet at this point in the
				// request (only the page-render callback loads it, which runs
				// after admin_enqueue_scripts), so calling into that registry
				// here would fatal on Multisite.
				'panels' => apply_filters(
					'erankly_settings_autosave_client_panels',
					array_filter(
						array(
							'general'       => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/general' ) ) ),
							'advanced'      => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/advanced' ) ) ),
							'sitemap'       => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/sitemap' ) ) ),
							// Its checkboxes control which OTHER tabs are visible
							// (Redirects/Sitemap and add-on feature tabs), so the admin JS
							// refreshes the EasyRankly settings wrapper after a successful
							// save to pick up the updated PHP-rendered navigation.
							'features'      => array(
								'restUrl'      => esc_url_raw( rest_url( 'erankly/v1/settings/features' ) ),
								'reloadOnSave' => true,
							),
							// Simplified mode drives PHP-rendered markup across the whole
							// page (Advanced tab visibility, social/visibility defaults
							// rendered as hidden inputs), so like Features it needs the
							// settings wrapper refreshed after save.
							'settings'      => array(
								'restUrl'      => esc_url_raw( rest_url( 'erankly/v1/settings/settings' ) ),
								'reloadOnSave' => true,
							),
							'social'        => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/social' ) ) ),
							'schema'        => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/schema' ) ) ),
							// Multisite-only (a per-site admin's "General" tab there);
							// its own bespoke route, not part of erankly_settings_autosave_panels()
							// See erankly_rest_save_special_pages() in easyrankly.php.
							'special-pages' => array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/special-pages' ) ) ),
						),
						'is_array'
					)
				),
			)
		);
	}

	if ( $is_settings && 'redirects' === $settings_tab && erankly_redirects_enabled() ) {
		wp_enqueue_style(
			'erankly-redirects',
			ERANKLY_URL . 'assets/css/redirects.css',
			array( 'erankly-admin' ),
			ERANKLY_VERSION
		);

		wp_enqueue_script(
			'erankly-redirects',
			ERANKLY_URL . 'assets/js/redirects.js',
			array(),
			ERANKLY_VERSION,
			true
		);

		wp_localize_script(
			'erankly-redirects',
			'eranklyRedirects',
			array(
				'restUrlToggle' => esc_url_raw( rest_url( 'erankly/v1/redirects/toggle' ) ),
				'restUrlDelete' => esc_url_raw( rest_url( 'erankly/v1/redirects/delete' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'deleteConfirm' => __( 'Delete this redirect?', 'easyrankly' ),
				'enableLabel'   => __( 'Enable', 'easyrankly' ),
				'disableLabel'  => __( 'Disable', 'easyrankly' ),
				'activeYes'     => __( 'Yes', 'easyrankly' ),
				'activeNo'      => __( 'No', 'easyrankly' ),
				'toggleError'   => __( 'The redirect status could not be changed.', 'easyrankly' ),
				'deleteError'   => __( 'The redirect could not be deleted.', 'easyrankly' ),
			)
		);
	}

	/**
	 * Fires after EasyRankly has enqueued its admin assets for this screen.
	 *
	 * @param array<string,mixed> $context Screen flags for add-ons.
	 */
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => $hook_suffix,
			'screen'          => $screen,
			'is_settings'     => $is_settings,
			'is_setup'        => $is_setup,
			'is_editor'       => $is_editor,
			'is_taxonomy'     => $is_taxonomy,
			'is_block_editor' => false,
			'is_site_editor'  => false,
			'settings_tab'    => $settings_tab,
		)
	);
}
