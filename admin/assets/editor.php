<?php
/** Block and Site Editor assets. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_enqueue_accordion_faq_schema_assets(): void {
	wp_enqueue_script(
		'erankly-accordion-faq-schema',
		ERANKLY_URL . 'assets/js/accordion-faq-schema.js',
		array(
			'wp-block-editor',
			'wp-components',
			'wp-compose',
			'wp-element',
			'wp-hooks',
			'wp-i18n',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-accordion-faq-schema', 'easyrankly', ERANKLY_PATH . 'languages' );
}

function erankly_admin_enqueue_block_editor_assets(): void {
	$post = get_post();

	if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
		return;
	}

	require_once ERANKLY_PATH . 'admin/meta-box.php';

	erankly_enqueue_editor_shared_assets();
	erankly_enqueue_accordion_faq_schema_assets();

	$editor_deps = array(
		'erankly-editor-shared',
		'wp-api-fetch',
		'wp-block-editor',
		'wp-components',
		'wp-data',
		'wp-edit-post',
		'wp-editor',
		'wp-element',
		'wp-hooks',
		'wp-i18n',
		'wp-plugins',
	);

	wp_enqueue_script(
		'erankly-editor',
		ERANKLY_URL . 'assets/js/editor.js',
		$editor_deps,
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-editor', 'easyrankly', ERANKLY_PATH . 'languages' );

	wp_localize_script(
		'erankly-editor',
		'eranklyEditor',
		array(
			'breadcrumbsEnabled'            => (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ),
			'newsSitemapEnabled'            => (bool) erankly_get_setting( 'enable_news_sitemap', 0 ),
			'resolvePlaceholders'           => (bool) erankly_get_setting( 'resolve_placeholders', 1 ),
			'simplifiedMode'                => (bool) erankly_get_setting( 'simplified_mode', 1 ),
			'siteDescription'               => get_bloginfo( 'description' ),
			'siteName'                      => get_bloginfo( 'name' ),
			'titlePlaceholder'              => erankly_get_post_global_meta_placeholder( $post, 'title', 70 ),
			'descriptionPlaceholder'        => erankly_get_post_global_meta_placeholder( $post, 'description', 160 ),
			'ogTitlePlaceholder'            => erankly_get_post_global_social_placeholder( $post->ID, 'default_og_title', 60 ),
			'ogDescriptionPlaceholder'      => erankly_get_post_global_social_placeholder( $post->ID, 'default_og_description', 200 ),
			'twitterTitlePlaceholder'       => erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_title', 70 ),
			'twitterDescriptionPlaceholder' => erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_description', 200 ),
			'socialImagePlaceholder'        => erankly_get_post_global_social_placeholder( $post->ID, 'default_social_image_url', 2048 ),
			'variableExamples'              => erankly_get_admin_variable_examples( $post ),
			'variables'                     => erankly_get_variable_groups(),
		)
	);

	/**
 * Fires after EasyRankly has enqueued its admin assets for this screen.
 *
 * @param array<string,mixed> $context Screen flags for add-ons.
 */
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => '',
			'screen'          => get_current_screen(),
			'is_settings'     => false,
			'is_editor'       => true,
			'is_taxonomy'     => false,
			'is_block_editor' => true,
			'is_site_editor'  => false,
			'settings_tab'    => '',
		)
	);
}

/**
 * Enqueues the editor stylesheet and the shared editor component bundle. Shared by the post editor and the Site
 * Editor so both load the same presentational controls and field builders.
 */
function erankly_enqueue_editor_shared_assets(): void {
	erankly_enqueue_shared_styles();

	wp_enqueue_style(
		'erankly-editor',
		ERANKLY_URL . 'assets/css/editor.css',
		array( 'erankly-shared', 'wp-components' ),
		ERANKLY_VERSION
	);

	wp_enqueue_script(
		'erankly-editor-shared',
		ERANKLY_URL . 'assets/js/editor-shared.js',
		array(
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-i18n',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-editor-shared', 'easyrankly', ERANKLY_PATH . 'languages' );
	wp_localize_script(
		'erankly-editor-shared',
		'eranklyEditorShared',
		array(
			'panelOrder' => array_values(
				array_filter(
					(array) apply_filters(
						'erankly_editor_panel_order',
						array(
							'erankly-panel--appearance',
							'erankly-panel--social',
							'erankly-panel--schema',
							'erankly-panel--visibility',
							'erankly-panel--translations',
						)
					),
					'is_string'
				)
			),
		)
	);
}

/**
 * Enqueues the Site Editor special-page panels. Adds EasyRankly SEO default panels to the template inspector
 * when a block theme template maps to a WordPress special-page context. Values bind to the native root/site Core
 * Data entity, so the Site Editor saves them together with template changes. Requires WordPress 6.6+ for the
 * unified editor slotfills.
 */
function erankly_admin_enqueue_site_editor_assets(): void {
	if ( ! erankly_site_editor_special_page_panels_supported() ) {
		return;
	}

	if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	require_once ERANKLY_PATH . 'admin/field-renderers.php';

	erankly_enqueue_editor_shared_assets();
	erankly_enqueue_accordion_faq_schema_assets();

	wp_enqueue_script(
		'erankly-site-editor',
		ERANKLY_URL . 'assets/js/site-editor.js',
		array(
			'erankly-editor-shared',
			'wp-api-fetch',
			'wp-block-editor',
			'wp-components',
			'wp-core-data',
			'wp-data',
			'wp-editor',
			'wp-element',
			'wp-hooks',
			'wp-i18n',
			'wp-plugins',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-site-editor', 'easyrankly', ERANKLY_PATH . 'languages' );

	wp_localize_script(
		'erankly-site-editor',
		'eranklySiteEditor',
		array(
			'contextLabels'                  => erankly_special_page_keys(),
			'descriptionPlaceholder'         => '',
			'ogDescriptionPlaceholder'       => (string) erankly_get_setting( 'default_og_description', '' ),
			'ogTitlePlaceholder'             => (string) erankly_get_setting( 'default_og_title', '' ),
			'resolvePlaceholders'            => (bool) erankly_get_setting( 'resolve_placeholders', 1 ),
			'simplifiedMode'                 => (bool) erankly_get_setting( 'simplified_mode', 1 ),
			'siteDescription'                => get_bloginfo( 'description' ),
			'siteName'                       => get_bloginfo( 'name' ),
			'socialImagePlaceholder'         => (string) erankly_get_setting( 'default_social_image_url', '' ),
			'specialMetaSetting'             => ERANKLY_SPECIAL_META_OPTION,
			'titlePlaceholder'               => '',
			'twitterDescriptionPlaceholder'  => (string) erankly_get_setting( 'default_twitter_description', '' ),
			'twitterTitlePlaceholder'        => (string) erankly_get_setting( 'default_twitter_title', '' ),
			'variableExamples'               => erankly_get_admin_variable_examples(),
			'variables'                      => erankly_get_variable_groups(),
		)
	);
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => '',
			'screen'          => get_current_screen(),
			'is_settings'     => false,
			'is_editor'       => false,
			'is_taxonomy'     => false,
			'is_block_editor' => false,
			'is_site_editor'  => true,
			'settings_tab'    => '',
		)
	);
}
