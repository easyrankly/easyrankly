<?php
/**
 * Post editor meta box.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers meta boxes.
 *
 * @return void
 */
function erankly_register_meta_box(): void {
	$screen = get_current_screen();

	// Gutenberg renders the React document panels instead of legacy meta boxes.
	if ( $screen instanceof WP_Screen && $screen->is_block_editor() ) {
		return;
	}

	foreach ( erankly_get_public_post_types() as $post_type => $object ) {
		if ( ! $object->show_ui ) {
			continue;
		}

		add_meta_box(
			'erankly',
			__( 'EasyRankly', 'easyrankly' ),
			'erankly_render_meta_box',
			$post_type,
			'normal',
			'default'
		);
	}
}

/**
 * Registers taxonomy SEO fields.
 *
 * @return void
 */
function erankly_register_taxonomy_fields(): void {
	foreach ( erankly_get_public_taxonomies() as $taxonomy => $object ) {
		if ( ! $object->show_ui ) {
			continue;
		}

		add_action( $taxonomy . '_add_form_fields', 'erankly_render_add_term_fields' );
		add_action( $taxonomy . '_edit_form_fields', 'erankly_render_edit_term_fields' );
		add_action( 'created_' . $taxonomy, 'erankly_save_term_fields' );
		add_action( 'edited_' . $taxonomy, 'erankly_save_term_fields' );
	}
}

/**
 * Returns a preview value for a post type global metadata template.
 *
 * @param WP_Post $post  Post object.
 * @param string  $field Metadata field.
 * @param int     $limit Character limit.
 * @return string
 */
function erankly_get_post_global_meta_placeholder( WP_Post $post, string $field, int $limit ): string {
	$template = erankly_get_global_post_type_meta( $post->post_type, $field );

	if ( '' === $template ) {
		return '';
	}

	$exclude = 'description' === $field ? array( 'meta_description' ) : array( 'seo_title' );
	$value   = erankly_replace_variables( $template, $post->ID, $exclude );

	return erankly_trim_text( $value, $limit );
}

/**
 * Returns a preview value for a site-wide social metadata template.
 *
 * @param int    $post_id Post ID.
 * @param string $setting Setting key.
 * @param int    $limit   Character limit.
 * @return string
 */
function erankly_get_post_global_social_placeholder( int $post_id, string $setting, int $limit ): string {
	$template = (string) erankly_get_setting( $setting, '' );

	if ( '' === $template ) {
		return '';
	}

	return erankly_trim_text( erankly_replace_variables( $template, $post_id ), $limit );
}

/**
 * Returns a taxonomy global metadata template placeholder.
 *
 * @param string $taxonomy Taxonomy name.
 * @param string $field    Metadata field.
 * @return string
 */
function erankly_get_term_global_meta_placeholder( string $taxonomy, string $field ): string {
	return erankly_get_global_taxonomy_meta( $taxonomy, $field );
}

/**
 * Renders the General fields shared by the tabbed box and the sidebar box.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_post_general_fields( WP_Post $post ): void {
	$title                   = erankly_get_post_meta_string( $post->ID, 'title' );
	$description             = erankly_get_post_meta_string( $post->ID, 'description' );
	$canonical               = erankly_get_post_meta_string( $post->ID, 'canonical' );
	$breadcrumb_name         = erankly_get_post_meta_string( $post->ID, 'breadcrumb_name' );
	$breadcrumbs_enabled     = (bool) erankly_get_setting( 'enable_breadcrumbs', 1 );
	$simplified_mode         = (bool) erankly_get_setting( 'simplified_mode', 1 );
	$title_placeholder       = erankly_get_post_global_meta_placeholder( $post, 'title', 70 );
	$description_placeholder = erankly_get_post_global_meta_placeholder( $post, 'description', 160 );
	?>
	<div class="erankly-field">
		<label for="erankly-title"><strong><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-title" class="widefat erankly-counted-field" type="text" name="erankly_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( $title_placeholder ); ?>" data-erankly-limit="65" data-erankly-counter="erankly-title-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
			<?php erankly_render_variable_picker(); ?>
		</div>
		<span id="erankly-title-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-description"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea id="erankly-description" class="widefat erankly-counted-field" rows="3" name="erankly_description" placeholder="<?php echo esc_attr( $description_placeholder ); ?>" data-erankly-limit="160" data-erankly-counter="erankly-description-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
			<?php erankly_render_variable_picker(); ?>
		</div>
		<span id="erankly-description-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<?php if ( ! $simplified_mode ) : ?>
	<div class="erankly-field">
		<label for="erankly-canonical"><strong><?php esc_html_e( 'Canonical URL', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-canonical" class="widefat" type="text" name="erankly_canonical" value="<?php echo esc_attr( $canonical ); ?>">
			<?php erankly_render_variable_picker(); ?>
		</div>
	</div>
	<?php endif; ?>
	<?php if ( $breadcrumbs_enabled && ! $simplified_mode ) : ?>
	<div class="erankly-field">
		<label for="erankly-breadcrumb-name"><strong><?php esc_html_e( 'Breadcrumb name', 'easyrankly' ); ?></strong></label>
		<input id="erankly-breadcrumb-name" class="widefat" type="text" name="erankly_breadcrumb_name" value="<?php echo esc_attr( $breadcrumb_name ); ?>" placeholder="<?php echo esc_attr( get_the_title( $post ) ); ?>" maxlength="120">
		<span class="description"><?php esc_html_e( 'Optional short name used in visible breadcrumbs and BreadcrumbList schema.', 'easyrankly' ); ?></span>
	</div>
	<?php endif; ?>
	<?php
}

/**
 * Renders the Social fields shared by the tabbed box and the sidebar box.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_post_social_fields( WP_Post $post ): void {
	$og_title                   = erankly_get_post_meta_string( $post->ID, 'og_title' );
	$og_description             = erankly_get_post_meta_string( $post->ID, 'og_description' );
	$twitter_title              = erankly_get_post_meta_string( $post->ID, 'twitter_title' );
	$twitter_desc               = erankly_get_post_meta_string( $post->ID, 'twitter_description' );
	$twitter_card               = erankly_get_post_meta_string( $post->ID, 'twitter_card_type' );
	$social_image_url           = erankly_get_post_meta_string( $post->ID, 'social_image_url' );
	$og_title_placeholder       = erankly_get_post_global_social_placeholder( $post->ID, 'default_og_title', 60 );
	$og_description_placeholder = erankly_get_post_global_social_placeholder( $post->ID, 'default_og_description', 200 );
	$twitter_title_placeholder  = erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_title', 70 );
	$twitter_desc_placeholder   = erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_description', 200 );
	$social_image_placeholder   = erankly_get_post_global_social_placeholder( $post->ID, 'default_social_image_url', 2048 );
	?>
	<div class="erankly-field">
		<label for="erankly-og-title"><strong><?php esc_html_e( 'Open Graph Title', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-og-title" class="widefat erankly-counted-field" type="text" name="erankly_og_title" value="<?php echo esc_attr( $og_title ); ?>" placeholder="<?php echo esc_attr( $og_title_placeholder ); ?>" data-erankly-limit="60" data-erankly-counter="erankly-og-title-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
			<?php erankly_render_variable_picker(); ?>
		</div>
		<span id="erankly-og-title-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-og-description"><strong><?php esc_html_e( 'Open Graph Description', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea id="erankly-og-description" class="widefat erankly-counted-field" rows="3" name="erankly_og_description" placeholder="<?php echo esc_attr( $og_description_placeholder ); ?>" data-erankly-limit="200" data-erankly-counter="erankly-og-description-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $og_description ); ?></textarea>
			<?php erankly_render_variable_picker(); ?>
		</div>
		<span id="erankly-og-description-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-twitter-title"><strong><?php esc_html_e( 'X (Twitter) Title', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-twitter-title" class="widefat erankly-counted-field" type="text" name="erankly_twitter_title" value="<?php echo esc_attr( $twitter_title ); ?>" placeholder="<?php echo esc_attr( $twitter_title_placeholder ); ?>" data-erankly-limit="70" data-erankly-counter="erankly-twitter-title-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
			<?php erankly_render_variable_picker(); ?>
		</div>
		<span id="erankly-twitter-title-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-twitter-description"><strong><?php esc_html_e( 'X (Twitter) Description', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea id="erankly-twitter-description" class="widefat erankly-counted-field" rows="3" name="erankly_twitter_description" placeholder="<?php echo esc_attr( $twitter_desc_placeholder ); ?>" data-erankly-limit="200" data-erankly-counter="erankly-twitter-description-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $twitter_desc ); ?></textarea>
			<?php erankly_render_variable_picker(); ?>
		</div>
		<span id="erankly-twitter-description-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-twitter-card-type"><strong><?php esc_html_e( 'X (Twitter) Card Type', 'easyrankly' ); ?></strong></label>
		<select id="erankly-twitter-card-type" class="widefat" name="erankly_twitter_card_type">
			<option value="" <?php selected( $twitter_card, '' ); ?>><?php esc_html_e( 'Default (summary_large_image)', 'easyrankly' ); ?></option>
			<option value="summary" <?php selected( $twitter_card, 'summary' ); ?>>summary</option>
		</select>
	</div>
	<div class="erankly-field">
		<label for="erankly-social-image-url"><strong><?php esc_html_e( 'Social Image URL', 'easyrankly' ); ?></strong></label>
		<?php
		erankly_render_media_url_field(
			'erankly-social-image-url',
			'erankly_social_image_url',
			$social_image_url,
			'' !== $social_image_placeholder ? $social_image_placeholder : erankly_default_social_image_placeholder()
		);
		?>
	</div>
	<?php
}

/**
 * Renders the Visibility fields shared by the tabbed box and the sidebar box.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_post_visibility_fields( WP_Post $post ): void {
	$noindex                  = erankly_get_post_meta_bool( $post->ID, 'noindex' );
	$nofollow                 = erankly_get_post_meta_bool( $post->ID, 'nofollow' );
	$noarchive                = erankly_get_post_meta_bool( $post->ID, 'noarchive' );
	$disable_sitemap          = erankly_get_post_meta_bool( $post->ID, 'disable_sitemap' );
	$simplified_mode          = (bool) erankly_get_setting( 'simplified_mode', 1 );
	$hide_from_search_results = $noindex && $disable_sitemap;
	$exclude_search           = erankly_get_post_meta_bool( $post->ID, 'exclude_search' );
	$exclude_archive          = erankly_get_post_meta_bool( $post->ID, 'exclude_archive' );
	$exclude_from_news        = erankly_get_post_meta_bool( $post->ID, 'exclude_from_news' );
	?>
	<fieldset class="erankly-field erankly-checkboxes">
		<?php if ( $simplified_mode ) : ?>
			<label><input type="checkbox" name="erankly_hide_from_search_results" value="1" <?php checked( $hide_from_search_results ); ?>> <?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></label>
			<span class="description"><?php esc_html_e( 'Sets noindex and removes this page from the sitemap. Does not affect nofollow or noarchive — use Advanced settings to control those separately.', 'easyrankly' ); ?></span>
		<?php else : ?>
			<label><input type="checkbox" name="erankly_noindex" value="1" <?php checked( $noindex ); ?>> <?php esc_html_e( 'Noindex', 'easyrankly' ); ?></label><br>
			<label><input type="checkbox" name="erankly_nofollow" value="1" <?php checked( $nofollow ); ?>> <?php esc_html_e( 'Nofollow', 'easyrankly' ); ?></label><br>
			<label><input type="checkbox" name="erankly_noarchive" value="1" <?php checked( $noarchive ); ?>> <?php esc_html_e( 'Noarchive', 'easyrankly' ); ?></label><br>
			<label><input type="checkbox" name="erankly_disable_sitemap" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
		<?php endif; ?>
	</fieldset>
	<fieldset class="erankly-field erankly-checkboxes">
		<legend><strong><?php esc_html_e( 'Archive Settings', 'easyrankly' ); ?></strong></legend>
		<label><input type="checkbox" name="erankly_exclude_search" value="1" <?php checked( $exclude_search ); ?>> <?php esc_html_e( 'Exclude this page from all search queries on this site', 'easyrankly' ); ?></label><br>
		<label><input type="checkbox" name="erankly_exclude_archive" value="1" <?php checked( $exclude_archive ); ?>> <?php esc_html_e( 'Exclude this page from all archive queries on this site.', 'easyrankly' ); ?></label>
	</fieldset>
	<?php if ( (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) ) : ?>
	<fieldset class="erankly-field erankly-checkboxes">
		<legend><strong><?php esc_html_e( 'Google News Settings', 'easyrankly' ); ?></strong></legend>
		<label><input type="checkbox" name="erankly_exclude_from_news" value="1" <?php checked( $exclude_from_news ); ?>> <?php esc_html_e( 'Exclude this page from Google News sitemap', 'easyrankly' ); ?></label>
	</fieldset>
	<?php endif; ?>
	<?php
}

/**
 * Renders the single tabbed meta box (classic editor fallback).
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'erankly_save_meta_box', 'erankly_meta_box_nonce' );
	$simplified_mode = (bool) erankly_get_setting( 'simplified_mode', 1 );
	?>
	<div class="erankly-meta-box">
		<div class="nav-tab-wrapper wp-clearfix erankly-tabs" role="tablist" aria-label="<?php esc_attr_e( 'SEO settings', 'easyrankly' ); ?>">
			<button type="button" class="nav-tab nav-tab-active erankly-tab is-active" id="erankly-tab-general" role="tab" aria-selected="true" aria-controls="erankly-panel-general" data-erankly-tab="general"><?php esc_html_e( 'Search appearance', 'easyrankly' ); ?></button>
			<?php if ( ! $simplified_mode ) : ?>
			<button type="button" class="nav-tab erankly-tab" id="erankly-tab-social" role="tab" aria-selected="false" aria-controls="erankly-panel-social" data-erankly-tab="social"><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></button>
			<?php endif; ?>
			<button type="button" class="nav-tab erankly-tab" id="erankly-tab-visibility" role="tab" aria-selected="false" aria-controls="erankly-panel-visibility" data-erankly-tab="visibility"><?php esc_html_e( 'Search visibility', 'easyrankly' ); ?></button>
			<?php if ( is_multisite() && function_exists( 'erankly_multilingual_enabled' ) && erankly_multilingual_enabled() ) : ?>
			<button type="button" class="nav-tab erankly-tab" id="erankly-tab-translations" role="tab" aria-selected="false" aria-controls="erankly-panel-translations" data-erankly-tab="translations"><?php esc_html_e( 'Translations', 'easyrankly' ); ?></button>
			<?php endif; ?>
		</div>

		<div class="erankly-tab-panel is-active" id="erankly-panel-general" role="tabpanel" aria-labelledby="erankly-tab-general" data-erankly-panel="general">
			<?php erankly_render_post_general_fields( $post ); ?>
		</div>

		<?php if ( ! $simplified_mode ) : ?>
		<div class="erankly-tab-panel" id="erankly-panel-social" role="tabpanel" aria-labelledby="erankly-tab-social" data-erankly-panel="social" hidden>
			<?php erankly_render_post_social_fields( $post ); ?>
		</div>
		<?php endif; ?>

		<div class="erankly-tab-panel" id="erankly-panel-visibility" role="tabpanel" aria-labelledby="erankly-tab-visibility" data-erankly-panel="visibility" hidden>
			<?php erankly_render_post_visibility_fields( $post ); ?>
		</div>

		<?php if ( is_multisite() && function_exists( 'erankly_multilingual_enabled' ) && erankly_multilingual_enabled() && function_exists( 'erankly_ml_render_post_translations' ) ) : ?>
		<div class="erankly-tab-panel" id="erankly-panel-translations" role="tabpanel" aria-labelledby="erankly-tab-translations" data-erankly-panel="translations" hidden>
			<?php erankly_ml_render_post_translations( $post ); ?>
		</div>
		<?php endif; ?>

	</div>
	<?php
}

/**
 * Renders SEO fields on add term screens.
 *
 * @param string $taxonomy Taxonomy name.
 * @return void
 */
function erankly_render_add_term_fields( string $taxonomy ): void {
	?>
	<div class="form-field term-erankly-wrap">
		<h2><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h2>
		<?php erankly_render_term_meta_fields( 0, $taxonomy ); ?>
	</div>
	<?php
}

/**
 * Renders SEO fields on edit term screens.
 *
 * @param WP_Term $term Term object.
 * @return void
 */
function erankly_render_edit_term_fields( WP_Term $term ): void {
	?>
	<tr class="form-field term-erankly-wrap">
		<th scope="row"><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></th>
		<td><?php erankly_render_term_meta_fields( $term->term_id, $term->taxonomy ); ?></td>
	</tr>
	<?php
}

/**
 * Renders shared taxonomy SEO controls.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy name.
 * @return void
 */
function erankly_render_term_meta_fields( int $term_id, string $taxonomy ): void {
	wp_nonce_field( 'erankly_save_term_fields', 'erankly_term_fields_nonce' );

	$title                    = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'title' ) : '';
	$description              = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'description' ) : '';
	$canonical                = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'canonical' ) : '';
	$noindex                  = $term_id > 0 && erankly_get_term_meta_bool( $term_id, 'noindex' );
	$nofollow                 = $term_id > 0 && erankly_get_term_meta_bool( $term_id, 'nofollow' );
	$noarchive                = $term_id > 0 && erankly_get_term_meta_bool( $term_id, 'noarchive' );
	$disable_sitemap          = $term_id > 0 && erankly_get_term_meta_bool( $term_id, 'disable_sitemap' );
	$simplified_mode          = (bool) erankly_get_setting( 'simplified_mode', 1 );
	$hide_from_search_results = $noindex && $disable_sitemap;
	$og_title                 = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'og_title' ) : '';
	$og_description           = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'og_description' ) : '';
	$twitter_title            = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_title' ) : '';
	$twitter_desc             = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_description' ) : '';
	$twitter_card             = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_card_type' ) : '';
	$social_image_url         = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'social_image_url' ) : '';
	$id_suffix                = $term_id > 0 ? (string) $term_id : sanitize_key( $taxonomy );
	$title_placeholder        = erankly_get_term_global_meta_placeholder( $taxonomy, 'title' );
	$description_placeholder  = erankly_get_term_global_meta_placeholder( $taxonomy, 'description' );
	?>
	<div class="erankly-meta-box erankly-term-meta-box">
		<div class="nav-tab-wrapper wp-clearfix erankly-tabs" role="tablist" aria-label="<?php esc_attr_e( 'SEO settings', 'easyrankly' ); ?>">
			<button type="button" class="nav-tab nav-tab-active erankly-tab is-active" id="erankly-term-tab-general-<?php echo esc_attr( $id_suffix ); ?>" role="tab" aria-selected="true" aria-controls="erankly-term-panel-general-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-tab="term-general-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Search appearance', 'easyrankly' ); ?></button>
			<button type="button" class="nav-tab erankly-tab" id="erankly-term-tab-social-<?php echo esc_attr( $id_suffix ); ?>" role="tab" aria-selected="false" aria-controls="erankly-term-panel-social-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-tab="term-social-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></button>
			<button type="button" class="nav-tab erankly-tab" id="erankly-term-tab-visibility-<?php echo esc_attr( $id_suffix ); ?>" role="tab" aria-selected="false" aria-controls="erankly-term-panel-visibility-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-tab="term-visibility-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Search visibility', 'easyrankly' ); ?></button>
			<?php if ( is_multisite() && function_exists( 'erankly_multilingual_enabled' ) && erankly_multilingual_enabled() ) : ?>
			<button type="button" class="nav-tab erankly-tab" id="erankly-term-tab-translations-<?php echo esc_attr( $id_suffix ); ?>" role="tab" aria-selected="false" aria-controls="erankly-term-panel-translations-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-tab="term-translations-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Translations', 'easyrankly' ); ?></button>
			<?php endif; ?>
		</div>

		<div class="erankly-tab-panel is-active" id="erankly-term-panel-general-<?php echo esc_attr( $id_suffix ); ?>" role="tabpanel" aria-labelledby="erankly-term-tab-general-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-panel="term-general-<?php echo esc_attr( $id_suffix ); ?>">
			<div class="erankly-field">
				<label for="erankly-term-title-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<input id="erankly-term-title-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" type="text" name="erankly_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( $title_placeholder ); ?>" data-erankly-limit="65" data-erankly-counter="erankly-term-title-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
					<?php erankly_render_variable_picker(); ?>
				</div>
				<span id="erankly-term-title-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-description-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<textarea id="erankly-term-description-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" rows="3" name="erankly_description" placeholder="<?php echo esc_attr( $description_placeholder ); ?>" data-erankly-limit="160" data-erankly-counter="erankly-term-description-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
					<?php erankly_render_variable_picker(); ?>
				</div>
				<span id="erankly-term-description-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<?php if ( ! $simplified_mode ) : ?>
			<div class="erankly-field">
				<label for="erankly-term-canonical-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Canonical URL', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<input id="erankly-term-canonical-<?php echo esc_attr( $id_suffix ); ?>" class="widefat" type="text" name="erankly_canonical" value="<?php echo esc_attr( $canonical ); ?>">
					<?php erankly_render_variable_picker(); ?>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<div class="erankly-tab-panel" id="erankly-term-panel-social-<?php echo esc_attr( $id_suffix ); ?>" role="tabpanel" aria-labelledby="erankly-term-tab-social-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-panel="term-social-<?php echo esc_attr( $id_suffix ); ?>" hidden>
			<div class="erankly-field">
				<label for="erankly-term-og-title-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Open Graph Title', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<input id="erankly-term-og-title-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" type="text" name="erankly_og_title" value="<?php echo esc_attr( $og_title ); ?>" data-erankly-limit="60" data-erankly-counter="erankly-term-og-title-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
					<?php erankly_render_variable_picker(); ?>
				</div>
				<span id="erankly-term-og-title-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-og-description-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Open Graph Description', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<textarea id="erankly-term-og-description-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" rows="3" name="erankly_og_description" data-erankly-limit="200" data-erankly-counter="erankly-term-og-description-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $og_description ); ?></textarea>
					<?php erankly_render_variable_picker(); ?>
				</div>
				<span id="erankly-term-og-description-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-twitter-title-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'X (Twitter) Title', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<input id="erankly-term-twitter-title-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" type="text" name="erankly_twitter_title" value="<?php echo esc_attr( $twitter_title ); ?>" data-erankly-limit="70" data-erankly-counter="erankly-term-twitter-title-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
					<?php erankly_render_variable_picker(); ?>
				</div>
				<span id="erankly-term-twitter-title-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-twitter-description-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'X (Twitter) Description', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<textarea id="erankly-term-twitter-description-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" rows="3" name="erankly_twitter_description" data-erankly-limit="200" data-erankly-counter="erankly-term-twitter-description-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $twitter_desc ); ?></textarea>
					<?php erankly_render_variable_picker(); ?>
				</div>
				<span id="erankly-term-twitter-description-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-twitter-card-type-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'X (Twitter) Card Type', 'easyrankly' ); ?></strong></label>
				<select id="erankly-term-twitter-card-type-<?php echo esc_attr( $id_suffix ); ?>" class="widefat" name="erankly_twitter_card_type">
					<option value="" <?php selected( $twitter_card, '' ); ?>><?php esc_html_e( 'Default (summary_large_image)', 'easyrankly' ); ?></option>
					<option value="summary" <?php selected( $twitter_card, 'summary' ); ?>>summary</option>
				</select>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-social-image-url-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Social Image URL', 'easyrankly' ); ?></strong></label>
				<?php
				erankly_render_media_url_field(
					'erankly-term-social-image-url-' . $id_suffix,
					'erankly_social_image_url',
					$social_image_url,
					erankly_default_social_image_placeholder()
				);
				?>
			</div>
		</div>

		<div class="erankly-tab-panel" id="erankly-term-panel-visibility-<?php echo esc_attr( $id_suffix ); ?>" role="tabpanel" aria-labelledby="erankly-term-tab-visibility-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-panel="term-visibility-<?php echo esc_attr( $id_suffix ); ?>" hidden>
			<fieldset class="erankly-field erankly-checkboxes">
				<?php if ( $simplified_mode ) : ?>
					<label><input type="checkbox" name="erankly_hide_from_search_results" value="1" <?php checked( $hide_from_search_results ); ?>> <?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></label>
					<span class="description"><?php esc_html_e( 'Sets noindex and removes this term from the sitemap. Does not affect nofollow or noarchive.', 'easyrankly' ); ?></span>
				<?php else : ?>
					<label><input type="checkbox" name="erankly_noindex" value="1" <?php checked( $noindex ); ?>> <?php esc_html_e( 'Noindex', 'easyrankly' ); ?></label><br>
					<label><input type="checkbox" name="erankly_nofollow" value="1" <?php checked( $nofollow ); ?>> <?php esc_html_e( 'Nofollow', 'easyrankly' ); ?></label><br>
					<label><input type="checkbox" name="erankly_noarchive" value="1" <?php checked( $noarchive ); ?>> <?php esc_html_e( 'Noarchive', 'easyrankly' ); ?></label><br>
					<label><input type="checkbox" name="erankly_disable_sitemap" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
				<?php endif; ?>
			</fieldset>
		</div>

		<?php if ( is_multisite() && function_exists( 'erankly_multilingual_enabled' ) && erankly_multilingual_enabled() && function_exists( 'erankly_ml_render_term_translations' ) ) : ?>
		<div class="erankly-tab-panel" id="erankly-term-panel-translations-<?php echo esc_attr( $id_suffix ); ?>" role="tabpanel" aria-labelledby="erankly-term-tab-translations-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-panel="term-translations-<?php echo esc_attr( $id_suffix ); ?>" hidden>
			<?php erankly_ml_render_term_translations( $term_id, $taxonomy ); ?>
		</div>
		<?php endif; ?>

	</div>
	<?php
}


/**
 * Returns grouped dynamic variables for editor pickers.
 *
 * @return array<string,array{label:string,variables:array<string,string>}>
 */
function erankly_get_variable_groups(): array {
	return array(
		'content'    => array(
			'label'     => __( 'Content', 'easyrankly' ),
			'variables' => array(
				'post_title'         => __( 'Post title', 'easyrankly' ),
				'post_excerpt'       => __( 'Post excerpt', 'easyrankly' ),
				'post_content'       => __( 'Post content', 'easyrankly' ),
				'post_url'           => __( 'Post URL', 'easyrankly' ),
				'post_date'          => __( 'Post date', 'easyrankly' ),
				'post_modified_date' => __( 'Post modified date', 'easyrankly' ),
				'post_author'        => __( 'Post author', 'easyrankly' ),
				'post_categories'    => __( 'Post categories', 'easyrankly' ),
				'featured_image'     => __( 'Featured image URL', 'easyrankly' ),
				'post_type_name'     => __( 'Post type name', 'easyrankly' ),
			),
		),
		'taxonomy'   => array(
			'label'     => __( 'Taxonomy', 'easyrankly' ),
			'variables' => array(
				'term_name'        => __( 'Term name', 'easyrankly' ),
				'term_description' => __( 'Term description', 'easyrankly' ),
				'term_slug'        => __( 'Term slug', 'easyrankly' ),
				'term_url'         => __( 'Term URL', 'easyrankly' ),
				'taxonomy_name'    => __( 'Taxonomy name', 'easyrankly' ),
			),
		),
		'seo'        => array(
			'label'     => __( 'SEO', 'easyrankly' ),
			'variables' => array(
				'seo_title'        => __( 'SEO title', 'easyrankly' ),
				'meta_description' => __( 'Meta description', 'easyrankly' ),
				'canonical_url'    => __( 'Canonical URL', 'easyrankly' ),
				'search_query'     => __( 'Search query', 'easyrankly' ),
			),
		),
		'pagination' => array(
			'label'     => __( 'Pagination', 'easyrankly' ),
			'variables' => array(
				'page_number' => __( 'Current page number', 'easyrankly' ),
				'max_pages'   => __( 'Total pages', 'easyrankly' ),
			),
		),
		'site'       => array(
			'label'     => __( 'Site', 'easyrankly' ),
			'variables' => array(
				'site_name'             => __( 'Site name', 'easyrankly' ),
				'site_description'      => __( 'Site description', 'easyrankly' ),
				'site_url'              => __( 'Site URL', 'easyrankly' ),
				'site_language'         => __( 'Site language', 'easyrankly' ),
				'organization_name'     => __( 'Organization name', 'easyrankly' ),
				'organization_logo_url' => __( 'Organization logo URL', 'easyrankly' ),
				'site_icon_url'         => __( 'Site icon URL', 'easyrankly' ),
				'schema_identity_id'    => __( 'Schema identity ID', 'easyrankly' ),
			),
		),
	);
}

/**
 * Renders a dynamic variable picker for a field.
 *
 * @return void
 */
function erankly_render_variable_picker(): void {
	?>
	<button type="button" class="erankly-variable-trigger" data-erankly-variable-trigger aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e( 'Insert variable', 'easyrankly' ); ?>">
		<svg aria-hidden="true" focusable="false" viewBox="0 0 256 256">
			<path d="M43.18,128a29.78,29.78,0,0,1,8,10.26c4.8,9.9,4.8,22,4.8,33.74,0,24.31,1,36,24,36a8,8,0,0,1,0,16c-17.48,0-29.32-6.14-35.2-18.26-4.8-9.9-4.8-22-4.8-33.74,0-24.31-1-36-24-36a8,8,0,0,1,0-16c23,0,24-11.69,24-36,0-11.72,0-23.84,4.8-33.74C50.68,38.14,62.52,32,80,32a8,8,0,0,1,0,16C57,48,56,59.69,56,84c0,11.72,0,23.84-4.8,33.74A29.78,29.78,0,0,1,43.18,128ZM240,120c-23,0-24-11.69-24-36,0-11.72,0-23.84-4.8-33.74C205.32,38.14,193.48,32,176,32a8,8,0,0,0,0,16c23,0,24,11.69,24,36,0,11.72,0,23.84,4.8,33.74a29.78,29.78,0,0,0,8,10.26,29.78,29.78,0,0,0-8,10.26c-4.8,9.9-4.8,22-4.8,33.74,0,24.31-1,36-24,36a8,8,0,0,0,0,16c17.48,0,29.32-6.14,35.2-18.26,4.8-9.9,4.8-22,4.8-33.74,0-24.31,1-36,24-36a8,8,0,0,0,0-16Z"></path>
		</svg>
		<span class="screen-reader-text"><?php esc_html_e( 'Insert variable', 'easyrankly' ); ?></span>
	</button>
	<div class="erankly-variable-menu" data-erankly-variable-menu hidden>
		<input class="erankly-variable-search" type="search" data-erankly-variable-search placeholder="<?php esc_attr_e( 'Search variables...', 'easyrankly' ); ?>" aria-label="<?php esc_attr_e( 'Search variables', 'easyrankly' ); ?>">
		<div class="erankly-variable-list">
			<?php foreach ( erankly_get_variable_groups() as $group ) : ?>
				<div class="erankly-variable-group" data-erankly-variable-group>
					<div class="erankly-variable-group-title"><?php echo esc_html( $group['label'] ); ?></div>
					<?php foreach ( $group['variables'] as $key => $label ) : ?>
						<?php $variable = '{{' . $key . '}}'; ?>
						<button type="button" class="erankly-variable-option" data-erankly-variable="<?php echo esc_attr( $variable ); ?>" data-erankly-variable-search-text="<?php echo esc_attr( strtolower( $label . ' ' . $key ) ); ?>">
							<span><?php echo esc_html( $label ); ?></span>
							<code><?php echo esc_html( $variable ); ?></code>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Renders a repeatable schema block.
 *
 * @param array<string,mixed> $block       Schema block.
 * @param string              $index       Field index.
 * @param string              $name_prefix Field name prefix.
 * @param bool                $is_global   Whether to render global targeting controls.
 * @return void
 */
function erankly_render_schema_block( array $block, string $index, string $name_prefix, bool $is_global = false ): void {
	$enabled     = ! isset( $block['enabled'] ) || ! empty( $block['enabled'] );
	$fields      = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : array();
	$custom_json = isset( $fields['custom_json'] ) ? (string) $fields['custom_json'] : '';
	?>
	<details class="erankly-schema-block" data-erankly-schema-block>
		<summary class="erankly-schema-block-header">
			<span class="erankly-schema-title"><?php esc_html_e( 'JSON-LD schema', 'easyrankly' ); ?></span>
			<div class="erankly-schema-row-actions">
				<button type="button" class="button-link button-link-delete" data-erankly-remove-schema><?php esc_html_e( 'Delete', 'easyrankly' ); ?></button>
			</div>
		</summary>
		<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][type]" value="custom">
		<div class="erankly-schema-panel" data-erankly-schema-panel>
			<?php if ( $is_global ) : ?>
				<?php erankly_render_schema_targeting_fields( $block, $index, $name_prefix, $enabled ); ?>
			<?php endif; ?>

			<?php erankly_render_schema_textarea_field( $index, $name_prefix, 'custom_json', __( 'JSON-LD code', 'easyrankly' ), $custom_json, 10 ); ?>
			<p class="description"><?php esc_html_e( 'Paste one JSON-LD object or use a top-level @graph array for multiple schemas. Supports {{variables}}.', 'easyrankly' ); ?></p>
		</div>
	</details>
	<?php
}

/**
 * Renders targeting controls for a global schema block.
 *
 * @param array<string,mixed> $block       Schema block.
 * @param string              $index       Field index.
 * @param string              $name_prefix Field name prefix.
 * @param bool                $enabled     Whether the block is enabled.
 * @return void
 */
function erankly_render_schema_targeting_fields( array $block, string $index, string $name_prefix, bool $enabled ): void {
	$target_contexts   = isset( $block['target_contexts'] ) && is_array( $block['target_contexts'] ) ? array_map( 'sanitize_key', $block['target_contexts'] ) : array();
	$target_post_types = isset( $block['target_post_types'] ) && is_array( $block['target_post_types'] ) ? array_map( 'sanitize_key', $block['target_post_types'] ) : array();
	$include_items     = isset( $block['include_items'] ) ? (string) $block['include_items'] : '';
	$exclude_items     = isset( $block['exclude_items'] ) ? (string) $block['exclude_items'] : '';
	$contexts          = array(
		'front_page'        => __( 'Front page', 'easyrankly' ),
		'posts_page'        => __( 'Posts page', 'easyrankly' ),
		'singular'          => __( 'Singular content', 'easyrankly' ),
		'post_type_archive' => __( 'Post type archives', 'easyrankly' ),
		'search'            => __( 'Search results', 'easyrankly' ),
	);
	?>
	<fieldset class="erankly-schema-targeting">
		<legend><?php esc_html_e( 'Global application rules', 'easyrankly' ); ?></legend>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Enable this schema block', 'easyrankly' ); ?>
		</label>

		<div class="erankly-schema-targeting-grid">
			<div>
				<strong><?php esc_html_e( 'Apply on', 'easyrankly' ); ?></strong>
				<?php foreach ( $contexts as $context => $label ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][target_contexts][]" value="<?php echo esc_attr( $context ); ?>" <?php checked( in_array( $context, $target_contexts, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<div>
				<strong><?php esc_html_e( 'Post types', 'easyrankly' ); ?></strong>
				<?php foreach ( erankly_get_public_post_types() as $post_type => $object ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][target_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $target_post_types, true ) ); ?>>
						<?php echo esc_html( $object->labels->singular_name ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="erankly-schema-targeting-grid">
			<label>
				<strong><?php esc_html_e( 'Include IDs or slugs', 'easyrankly' ); ?></strong>
				<textarea class="widefat" rows="3" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][include_items]"><?php echo esc_textarea( $include_items ); ?></textarea>
			</label>
			<label>
				<strong><?php esc_html_e( 'Exclude IDs or slugs', 'easyrankly' ); ?></strong>
				<textarea class="widefat" rows="3" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][exclude_items]"><?php echo esc_textarea( $exclude_items ); ?></textarea>
			</label>
		</div>
	</fieldset>
	<?php
}

/**
 * Renders a schema textarea field.
 *
 * @param string $index       Field index.
 * @param string $name_prefix Field name prefix.
 * @param string $key         Field key.
 * @param string $label       Field label.
 * @param string $value       Field value.
 * @param int    $rows        Textarea rows.
 * @return void
 */
function erankly_render_schema_textarea_field( string $index, string $name_prefix, string $key, string $label, string $value, int $rows ): void {
	?>
	<div class="erankly-schema-field">
		<span><?php echo esc_html( $label ); ?></span>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea class="widefat" rows="<?php echo esc_attr( (string) $rows ); ?>" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][fields][<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $value ); ?></textarea>
			<?php erankly_render_variable_picker(); ?>
		</div>
	</div>
	<?php
}


/**
 * Renders a media picker that fills an image URL text field.
 *
 * @param string $id                 Input ID.
 * @param string $name               URL input name.
 * @param string $value              URL input value.
 * @param string $placeholder        URL input placeholder.
 * @param string $attachment_id_name Optional attachment ID input name.
 * @param int    $attachment_id      Optional attachment ID value.
 * @param bool   $show_preview       Whether to render the image thumbnail preview.
 * @return void
 */
function erankly_render_media_url_field( string $id, string $name, string $value, string $placeholder = '', string $attachment_id_name = '', int $attachment_id = 0, bool $show_preview = true ): void {
	$preview = '' !== $value && false === strpos( $value, '{{' ) ? $value : '';
	?>
	<div class="erankly-media-url-field" data-erankly-media-url-field>
		<?php if ( '' !== $attachment_id_name ) : ?>
			<input type="hidden" data-erankly-media-url-id name="<?php echo esc_attr( $attachment_id_name ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
		<?php endif; ?>
		<div class="erankly-media-url-control">
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="<?php echo esc_attr( $id ); ?>" class="widefat" type="text" data-erankly-media-url-input name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
				<?php erankly_render_variable_picker(); ?>
			</div>
			<button type="button" class="button erankly-select-media-url" data-erankly-select-media-url><?php esc_html_e( 'Select image', 'easyrankly' ); ?></button>
			<button type="button" class="button erankly-clear-media-url" data-erankly-clear-media-url><?php esc_html_e( 'Remove', 'easyrankly' ); ?></button>
		</div>
		<?php if ( $show_preview ) : ?>
			<div class="erankly-media-preview erankly-media-url-preview" data-erankly-media-url-preview>
				<?php if ( '' !== $preview ) : ?>
					<img src="<?php echo esc_url( $preview ); ?>" alt="">
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Saves meta box values.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function erankly_save_meta_box( int $post_id ): void {
	if ( ! isset( $_POST['erankly_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['erankly_meta_box_nonce'] ) ), 'erankly_save_meta_box' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = array(
		'_erankly_title'           => 'erankly_title',
		'_erankly_description'     => 'erankly_description',
		'_erankly_canonical'       => 'erankly_canonical',
		'_erankly_breadcrumb_name' => 'erankly_breadcrumb_name',
	);

	// The field isn't rendered when breadcrumbs are off, so its POST key is absent —
	// skip it to keep any previously stored value.
	if ( ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		unset( $fields['_erankly_breadcrumb_name'] );
	}

	$fields = array_merge(
		$fields,
		array(
			'_erankly_og_title'            => 'erankly_og_title',
			'_erankly_og_description'      => 'erankly_og_description',
			'_erankly_twitter_title'       => 'erankly_twitter_title',
			'_erankly_twitter_description' => 'erankly_twitter_description',
			'_erankly_twitter_card_type'   => 'erankly_twitter_card_type',
			'_erankly_social_image_url'    => 'erankly_social_image_url',
		)
	);

	$simplified_mode = (bool) erankly_get_setting( 'simplified_mode', 1 );

	if ( $simplified_mode ) {
		unset(
			$fields['_erankly_canonical'],
			$fields['_erankly_breadcrumb_name'],
			$fields['_erankly_og_title'],
			$fields['_erankly_og_description'],
			$fields['_erankly_twitter_title'],
			$fields['_erankly_twitter_description'],
			$fields['_erankly_twitter_card_type'],
			$fields['_erankly_social_image_url']
		);
	}

	foreach ( $fields as $key => $field ) {
		$raw_value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by erankly_sanitize_registered_meta() on the next line.
		$value     = erankly_sanitize_registered_meta( $raw_value, $key );

		if ( '' === $value || 0 === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			// update_post_meta() expects slashed data; without wp_slash() it would
			// strip literal backslashes from the sanitized value.
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	$hide_from_search_results = $simplified_mode && isset( $_POST['erankly_hide_from_search_results'] );

	// "Hide from search results" sets only noindex + disable_sitemap.
	$booleans = array(
		'_erankly_noindex'           => $hide_from_search_results || ( ! $simplified_mode && isset( $_POST['erankly_noindex'] ) ),
		'_erankly_disable_sitemap'   => $hide_from_search_results || ( ! $simplified_mode && isset( $_POST['erankly_disable_sitemap'] ) ),
		'_erankly_exclude_search'    => isset( $_POST['erankly_exclude_search'] ),
		'_erankly_exclude_archive'   => isset( $_POST['erankly_exclude_archive'] ),
		'_erankly_exclude_from_news' => isset( $_POST['erankly_exclude_from_news'] ),
	);

	// nofollow and noarchive are advanced-only controls: in simplified mode their
	// checkboxes are not rendered, so leave any stored value untouched (the UI
	// promises "Hide from search results" does not affect them).
	if ( ! $simplified_mode ) {
		$booleans['_erankly_nofollow']  = isset( $_POST['erankly_nofollow'] );
		$booleans['_erankly_noarchive'] = isset( $_POST['erankly_noarchive'] );
	}

	foreach ( $booleans as $key => $enabled ) {
		if ( $enabled ) {
			update_post_meta( $post_id, $key, '1' );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}

/**
 * Saves taxonomy SEO fields.
 *
 * @param int $term_id Term ID.
 * @return void
 */
function erankly_save_term_fields( int $term_id ): void {
	if ( ! isset( $_POST['erankly_term_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['erankly_term_fields_nonce'] ) ), 'erankly_save_term_fields' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}

	$fields = array(
		'_erankly_title'               => 'erankly_title',
		'_erankly_description'         => 'erankly_description',
		'_erankly_canonical'           => 'erankly_canonical',
		'_erankly_og_title'            => 'erankly_og_title',
		'_erankly_og_description'      => 'erankly_og_description',
		'_erankly_twitter_title'       => 'erankly_twitter_title',
		'_erankly_twitter_description' => 'erankly_twitter_description',
		'_erankly_twitter_card_type'   => 'erankly_twitter_card_type',
		'_erankly_social_image_url'    => 'erankly_social_image_url',
	);

	$simplified_mode = (bool) erankly_get_setting( 'simplified_mode', 1 );

	if ( $simplified_mode ) {
		unset( $fields['_erankly_canonical'] );
	}

	foreach ( $fields as $key => $field ) {
		$raw_value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by erankly_sanitize_registered_meta() on the next line.
		$value     = erankly_sanitize_registered_meta( $raw_value, $key );

		if ( '' === $value || 0 === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			// update_term_meta() expects slashed data; without wp_slash() it would
			// strip literal backslashes from the sanitized value.
			update_term_meta( $term_id, $key, wp_slash( $value ) );
		}
	}

	$hide_from_search_results = $simplified_mode && isset( $_POST['erankly_hide_from_search_results'] );

	// "Hide from search results" intentionally sets only noindex + disable_sitemap.
	$booleans = array(
		'_erankly_noindex'         => $hide_from_search_results || ( ! $simplified_mode && isset( $_POST['erankly_noindex'] ) ),
		'_erankly_disable_sitemap' => $hide_from_search_results || ( ! $simplified_mode && isset( $_POST['erankly_disable_sitemap'] ) ),
	);

	// nofollow and noarchive are advanced-only controls: in simplified mode their
	// checkboxes are not rendered, so leave any stored value untouched.
	if ( ! $simplified_mode ) {
		$booleans['_erankly_nofollow']  = isset( $_POST['erankly_nofollow'] );
		$booleans['_erankly_noarchive'] = isset( $_POST['erankly_noarchive'] );
	}

	foreach ( $booleans as $key => $enabled ) {
		if ( $enabled ) {
			update_term_meta( $term_id, $key, '1' );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}
}
