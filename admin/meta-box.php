<?php
/**
 * Post editor meta box.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/seo-checklist.php';
require_once ERANKLY_PATH . 'admin/field-renderers.php';

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
	// The post being edited is its own {{post_title}}-style example, so the
	// preview shows this post's real title/excerpt/etc., not a stand-in.
	$examples = erankly_get_admin_variable_examples( $post );
	?>
	<div class="erankly-field">
		<label for="erankly-title"><strong><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-title" class="widefat erankly-counted-field" type="text" name="erankly_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( $title_placeholder ); ?>" data-erankly-limit="65" data-erankly-counter="erankly-title-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
			<?php erankly_render_variable_picker( $examples ); ?>
		</div>
		<span id="erankly-title-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-description"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea id="erankly-description" class="widefat erankly-counted-field" rows="3" name="erankly_description" placeholder="<?php echo esc_attr( $description_placeholder ); ?>" data-erankly-limit="160" data-erankly-counter="erankly-description-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
			<?php erankly_render_variable_picker( $examples ); ?>
		</div>
		<span id="erankly-description-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<?php if ( ! $simplified_mode ) : ?>
	<div class="erankly-field">
		<label for="erankly-canonical"><strong><?php esc_html_e( 'Canonical URL', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-canonical" class="widefat" type="text" name="erankly_canonical" value="<?php echo esc_attr( $canonical ); ?>">
			<?php erankly_render_variable_picker( $examples ); ?>
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
	<?php if ( ! $simplified_mode ) : ?>
	<div class="erankly-field">
		<label for="erankly-focus-keywords"><strong><?php esc_html_e( 'Focus keywords', 'easyrankly' ); ?></strong></label>
		<input id="erankly-focus-keywords" class="widefat" type="text" name="erankly_focus_keywords" value="<?php echo esc_attr( implode( ', ', (array) get_post_meta( $post->ID, '_erankly_focus_keywords', true ) ) ); ?>" placeholder="<?php esc_attr_e( 'Separate multiple keywords with commas', 'easyrankly' ); ?>">
	</div>
	<div class="erankly-field">
		<label><input type="checkbox" class="erankly-toggle" name="erankly_cornerstone" value="1" <?php checked( get_post_meta( $post->ID, '_erankly_cornerstone', true ), '1' ); ?>> <?php esc_html_e( 'Cornerstone / pillar content', 'easyrankly' ); ?></label>
	</div>
		<?php
		$primary_terms = get_post_meta( $post->ID, '_erankly_primary_terms', true );
		$primary_terms = is_array( $primary_terms ) ? $primary_terms : array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $tax_object ) :
			if ( ! $tax_object->public || ! has_term( '', $taxonomy, $post ) ) {
				continue;
			}
			?>
			<div class="erankly-field">
				<?php /* translators: %s: singular taxonomy label, for example Category. */ ?>
				<label><strong><?php echo esc_html( sprintf( __( 'Primary %s', 'easyrankly' ), $tax_object->labels->singular_name ) ); ?></strong></label>
			<?php
			wp_dropdown_categories(
				array(
					'taxonomy'          => $taxonomy,
					'name'              => 'erankly_primary_terms[' . $taxonomy . ']',
					'selected'          => isset( $primary_terms[ $taxonomy ] ) ? absint( $primary_terms[ $taxonomy ] ) : 0,
					'show_option_none'  => __( 'Automatic', 'easyrankly' ),
					'option_none_value' => 0,
					'hide_empty'        => false,
				)
			);
			?>
		</div>
		<?php endforeach; ?>
	<?php endif; ?>
	<?php if ( function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) : ?>
	<div class="erankly-field erankly-ai-field">
		<?php erankly_ai_render_editor_privacy_notice(); ?>
		<button type="button" class="button erankly-ai-generate"
			data-erankly-ai-object-id="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-erankly-ai-object-type="post"
			data-erankly-ai-target="seo"
			data-erankly-ai-title-target="#erankly-title"
			data-erankly-ai-desc-target="#erankly-description"><?php esc_html_e( 'Generate with AI', 'easyrankly' ); ?></button>
		<span class="erankly-ai-status description" data-erankly-ai-status aria-live="polite"></span>
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
	$legacy_image_url           = erankly_get_post_meta_string( $post->ID, 'social_image_url' );
	$og_image_url               = erankly_get_post_meta_string( $post->ID, 'og_image_url' );
	$og_image_alt               = erankly_get_post_meta_string( $post->ID, 'og_image_alt' );
	$twitter_image_url          = erankly_get_post_meta_string( $post->ID, 'twitter_image_url' );
	$twitter_image_alt          = erankly_get_post_meta_string( $post->ID, 'twitter_image_alt' );
	$og_title_placeholder       = erankly_get_post_global_social_placeholder( $post->ID, 'default_og_title', 60 );
	$og_description_placeholder = erankly_get_post_global_social_placeholder( $post->ID, 'default_og_description', 200 );
	$twitter_title_placeholder  = erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_title', 70 );
	$twitter_desc_placeholder   = erankly_get_post_global_social_placeholder( $post->ID, 'default_twitter_description', 200 );
	$social_image_placeholder   = erankly_get_post_global_social_placeholder( $post->ID, 'default_social_image_url', 2048 );
	$examples                   = erankly_get_admin_variable_examples( $post );
	?>
	<div class="erankly-field">
		<label for="erankly-og-title"><strong><?php esc_html_e( 'Open Graph Title', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-og-title" class="widefat erankly-counted-field" type="text" name="erankly_og_title" value="<?php echo esc_attr( $og_title ); ?>" placeholder="<?php echo esc_attr( $og_title_placeholder ); ?>" data-erankly-limit="60" data-erankly-counter="erankly-og-title-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
			<?php erankly_render_variable_picker( $examples ); ?>
		</div>
		<span id="erankly-og-title-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-og-description"><strong><?php esc_html_e( 'Open Graph Description', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea id="erankly-og-description" class="widefat erankly-counted-field" rows="3" name="erankly_og_description" placeholder="<?php echo esc_attr( $og_description_placeholder ); ?>" data-erankly-limit="200" data-erankly-counter="erankly-og-description-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $og_description ); ?></textarea>
			<?php erankly_render_variable_picker( $examples ); ?>
		</div>
		<span id="erankly-og-description-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-twitter-title"><strong><?php esc_html_e( 'X (Twitter) Title', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-twitter-title" class="widefat erankly-counted-field" type="text" name="erankly_twitter_title" value="<?php echo esc_attr( $twitter_title ); ?>" placeholder="<?php echo esc_attr( $twitter_title_placeholder ); ?>" data-erankly-limit="70" data-erankly-counter="erankly-twitter-title-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
			<?php erankly_render_variable_picker( $examples ); ?>
		</div>
		<span id="erankly-twitter-title-counter" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<div class="erankly-field">
		<label for="erankly-twitter-description"><strong><?php esc_html_e( 'X (Twitter) Description', 'easyrankly' ); ?></strong></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea id="erankly-twitter-description" class="widefat erankly-counted-field" rows="3" name="erankly_twitter_description" placeholder="<?php echo esc_attr( $twitter_desc_placeholder ); ?>" data-erankly-limit="200" data-erankly-counter="erankly-twitter-description-counter" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $twitter_desc ); ?></textarea>
			<?php erankly_render_variable_picker( $examples ); ?>
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
		<label for="erankly-og-image-url"><strong><?php esc_html_e( 'Open Graph image URL', 'easyrankly' ); ?></strong></label>
		<?php
		erankly_render_media_url_field(
			'erankly-og-image-url',
			'erankly_og_image_url',
			$og_image_url,
			'' !== $legacy_image_url ? $legacy_image_url : ( '' !== $social_image_placeholder ? $social_image_placeholder : erankly_default_social_image_placeholder() )
		);
		?>
		<input class="widefat" type="text" name="erankly_og_image_alt" value="<?php echo esc_attr( $og_image_alt ); ?>" placeholder="<?php esc_attr_e( 'Open Graph image alt text', 'easyrankly' ); ?>">
	</div>
	<div class="erankly-field">
		<label for="erankly-twitter-image-url"><strong><?php esc_html_e( 'X (Twitter) image URL', 'easyrankly' ); ?></strong></label>
		<?php
		erankly_render_media_url_field(
			'erankly-twitter-image-url',
			'erankly_twitter_image_url',
			$twitter_image_url,
			'' !== $legacy_image_url ? $legacy_image_url : ( '' !== $social_image_placeholder ? $social_image_placeholder : erankly_default_social_image_placeholder() )
		);
		?>
		<input class="widefat" type="text" name="erankly_twitter_image_alt" value="<?php echo esc_attr( $twitter_image_alt ); ?>" placeholder="<?php esc_attr_e( 'X image alt text', 'easyrankly' ); ?>">
	</div>
	<?php if ( function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) : ?>
	<div class="erankly-field erankly-ai-field">
		<?php erankly_ai_render_editor_privacy_notice(); ?>
		<button type="button" class="button erankly-ai-generate"
			data-erankly-ai-object-id="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-erankly-ai-object-type="post"
			data-erankly-ai-target="social"
			data-erankly-ai-title-targets="#erankly-og-title,#erankly-twitter-title"
			data-erankly-ai-desc-targets="#erankly-og-description,#erankly-twitter-description"><?php esc_html_e( 'Generate with AI', 'easyrankly' ); ?></button>
		<span class="erankly-ai-status description" data-erankly-ai-status aria-live="polite"></span>
	</div>
	<?php endif; ?>
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
	$index_directive          = erankly_get_object_robots_directive( 'post', $post->ID, 'index' );
	$follow_directive         = erankly_get_object_robots_directive( 'post', $post->ID, 'follow' );
	$archive_directive        = erankly_get_object_robots_directive( 'post', $post->ID, 'archive' );
	$snippet_directive        = erankly_get_object_robots_directive( 'post', $post->ID, 'snippet' );
	$image_directive          = erankly_get_object_robots_directive( 'post', $post->ID, 'image' );
	$hide_from_search_results = 'noindex' === $index_directive && $disable_sitemap;
	$exclude_search           = erankly_get_post_meta_bool( $post->ID, 'exclude_search' );
	$exclude_archive          = erankly_get_post_meta_bool( $post->ID, 'exclude_archive' );
	$exclude_from_news        = erankly_get_post_meta_bool( $post->ID, 'exclude_from_news' );
	?>
	<fieldset class="erankly-field erankly-checkboxes">
		<?php if ( $simplified_mode ) : ?>
			<input type="hidden" name="erankly_existing_index_directive" value="<?php echo esc_attr( $index_directive ); ?>">
			<input type="hidden" name="erankly_existing_hide" value="<?php echo $hide_from_search_results ? '1' : '0'; ?>">
			<label><input type="checkbox" class="erankly-toggle" name="erankly_hide_from_search_results" value="1" <?php checked( $hide_from_search_results ); ?>> <strong><?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></strong></label>
			<span class="description"><?php esc_html_e( 'Sets noindex and removes this page from the sitemap. Does not affect nofollow or noarchive — use Advanced settings to control those separately.', 'easyrankly' ); ?></span>
		<?php else : ?>
			<?php erankly_render_robots_directive_select( 'erankly_index_directive', $index_directive, __( 'Indexing', 'easyrankly' ), __( 'Index', 'easyrankly' ), __( 'Noindex', 'easyrankly' ) ); ?>
			<?php erankly_render_robots_directive_select( 'erankly_follow_directive', $follow_directive, __( 'Link following', 'easyrankly' ), __( 'Follow', 'easyrankly' ), __( 'Nofollow', 'easyrankly' ) ); ?>
			<?php erankly_render_robots_directive_select( 'erankly_archive_directive', $archive_directive, __( 'Cached copy', 'easyrankly' ), __( 'Allow archive', 'easyrankly' ), __( 'Noarchive', 'easyrankly' ) ); ?>
			<?php erankly_render_robots_directive_select( 'erankly_snippet_directive', $snippet_directive, __( 'Text snippet', 'easyrankly' ), __( 'Allow snippet', 'easyrankly' ), __( 'Nosnippet', 'easyrankly' ) ); ?>
			<?php erankly_render_robots_directive_select( 'erankly_image_directive', $image_directive, __( 'Image indexing', 'easyrankly' ), __( 'Allow image indexing', 'easyrankly' ), __( 'Noimageindex', 'easyrankly' ) ); ?>
			<label><?php esc_html_e( 'Max snippet', 'easyrankly' ); ?> <input class="small-text" type="number" min="-1" name="erankly_max_snippet" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_erankly_max_snippet', true ) ); ?>"></label><br>
			<label><?php esc_html_e( 'Max video preview', 'easyrankly' ); ?> <input class="small-text" type="number" min="-1" name="erankly_max_video_preview" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_erankly_max_video_preview', true ) ); ?>"></label><br>
			<label><?php esc_html_e( 'Max image preview', 'easyrankly' ); ?> <select name="erankly_max_image_preview"><option value="inherit"><?php esc_html_e( 'Inherit', 'easyrankly' ); ?></option>
			<?php
			foreach ( array( 'none', 'standard', 'large' ) as $preview ) :
				?>
				<option value="<?php echo esc_attr( $preview ); ?>" <?php selected( get_post_meta( $post->ID, '_erankly_max_image_preview', true ), $preview ); ?>><?php echo esc_html( $preview ); ?></option><?php endforeach; ?></select></label><br>
			<label><input type="checkbox" class="erankly-toggle" name="erankly_indexifembedded" value="1" <?php checked( get_post_meta( $post->ID, '_erankly_indexifembedded', true ), '1' ); ?>> <?php esc_html_e( 'Index if embedded when noindex applies', 'easyrankly' ); ?></label><br>
			<label><input type="checkbox" class="erankly-toggle" name="erankly_disable_sitemap" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
		<?php endif; ?>
	</fieldset>
	<fieldset class="erankly-field erankly-checkboxes">
		<legend><strong><?php esc_html_e( 'Archive Settings', 'easyrankly' ); ?></strong></legend>
		<label><input type="checkbox" class="erankly-toggle" name="erankly_exclude_search" value="1" <?php checked( $exclude_search ); ?>> <?php esc_html_e( 'Exclude this page from all search queries on this site', 'easyrankly' ); ?></label><br>
		<label><input type="checkbox" class="erankly-toggle" name="erankly_exclude_archive" value="1" <?php checked( $exclude_archive ); ?>> <?php esc_html_e( 'Exclude this page from all archive queries on this site.', 'easyrankly' ); ?></label>
	</fieldset>
	<?php if ( (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) ) : ?>
	<fieldset class="erankly-field erankly-checkboxes">
		<legend><strong><?php esc_html_e( 'Google News Settings', 'easyrankly' ); ?></strong></legend>
		<label><input type="checkbox" class="erankly-toggle" name="erankly_exclude_from_news" value="1" <?php checked( $exclude_from_news ); ?>> <?php esc_html_e( 'Exclude this page from Google News sitemap', 'easyrankly' ); ?></label>
	</fieldset>
	<?php endif; ?>
	<?php
}

/**
 * Renders a tri-state robots directive control.
 *
 * @param string $name        Input name.
 * @param string $value       Current value.
 * @param string $label       Field label.
 * @param string $allow_label Explicit allow label.
 * @param string $deny_label  Explicit deny label.
 * @return void
 */
function erankly_render_robots_directive_select( string $name, string $value, string $label, string $allow_label, string $deny_label ): void {
	$axis  = str_replace( array( 'erankly_', '_directive' ), '', $name );
	$allow = array(
		'index'   => 'index',
		'follow'  => 'follow',
		'archive' => 'archive',
		'snippet' => 'snippet',
		'image'   => 'imageindex',
	);
	$deny  = array(
		'index'   => 'noindex',
		'follow'  => 'nofollow',
		'archive' => 'noarchive',
		'snippet' => 'nosnippet',
		'image'   => 'noimageindex',
	);
	?>
	<label><?php echo esc_html( $label ); ?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="inherit" <?php selected( $value, 'inherit' ); ?>><?php esc_html_e( 'Inherit', 'easyrankly' ); ?></option>
			<option value="<?php echo esc_attr( $allow[ $axis ] ); ?>" <?php selected( $value, $allow[ $axis ] ); ?>><?php echo esc_html( $allow_label ); ?></option>
			<option value="<?php echo esc_attr( $deny[ $axis ] ); ?>" <?php selected( $value, $deny[ $axis ] ); ?>><?php echo esc_html( $deny_label ); ?></option>
		</select>
	</label><br>
	<?php
}

/**
 * Renders per-content schema controls.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_post_schema_fields( WP_Post $post ): void {
	$mode           = erankly_get_post_meta_string( $post->ID, 'schema_mode' );
	$blocks         = get_post_meta( $post->ID, '_erankly_schema_blocks', true );
	$disabled_types = get_post_meta( $post->ID, '_erankly_schema_disabled_types', true );
	$blocks         = is_array( $blocks ) ? $blocks : array();
	?>
	<div class="erankly-field">
		<label for="erankly-schema-mode"><strong><?php esc_html_e( 'Schema mode', 'easyrankly' ); ?></strong></label>
		<select id="erankly-schema-mode" name="erankly_schema_mode">
			<option value="default" <?php selected( $mode, 'default' ); ?>><?php esc_html_e( 'Automatic schema', 'easyrankly' ); ?></option>
			<option value="merge" <?php selected( $mode, 'merge' ); ?>><?php esc_html_e( 'Automatic + custom schema', 'easyrankly' ); ?></option>
			<option value="replace" <?php selected( $mode, 'replace' ); ?>><?php esc_html_e( 'Custom schema only', 'easyrankly' ); ?></option>
			<option value="disabled" <?php selected( $mode, 'disabled' ); ?>><?php esc_html_e( 'Disable schema for this content', 'easyrankly' ); ?></option>
		</select>
	</div>
	<div class="erankly-field">
		<label for="erankly-schema-disabled-types"><strong><?php esc_html_e( 'Suppress automatic schema types', 'easyrankly' ); ?></strong></label>
		<input id="erankly-schema-disabled-types" class="widefat" type="text" name="erankly_schema_disabled_types" value="<?php echo esc_attr( implode( ', ', is_array( $disabled_types ) ? $disabled_types : array() ) ); ?>" placeholder="Article, Product, FAQPage">
	</div>
	<div class="erankly-schema-builder" data-erankly-schema-builder>
		<div class="erankly-schema-blocks <?php echo empty( $blocks ) ? 'is-empty' : ''; ?>" data-erankly-schema-blocks>
			<?php foreach ( $blocks as $index => $block ) : ?>
				<?php erankly_render_schema_block( is_array( $block ) ? $block : array(), (string) $index, 'erankly_schema_blocks' ); ?>
			<?php endforeach; ?>
		</div>
		<template data-erankly-schema-template><?php erankly_render_schema_block( array(), '__INDEX__', 'erankly_schema_blocks' ); ?></template>
		<p class="erankly-schema-actions"><button type="button" class="button button-secondary" data-erankly-add-schema><?php esc_html_e( 'Add JSON-LD schema', 'easyrankly' ); ?></button></p>
	</div>
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
			<button type="button" class="nav-tab erankly-tab" id="erankly-tab-schema" role="tab" aria-selected="false" aria-controls="erankly-panel-schema" data-erankly-tab="schema"><?php esc_html_e( 'Schema', 'easyrankly' ); ?></button>
			<?php endif; ?>
			<button type="button" class="nav-tab erankly-tab" id="erankly-tab-visibility" role="tab" aria-selected="false" aria-controls="erankly-panel-visibility" data-erankly-tab="visibility"><?php esc_html_e( 'Search visibility', 'easyrankly' ); ?></button>
			<button type="button" class="nav-tab erankly-tab" id="erankly-tab-checklist" role="tab" aria-selected="false" aria-controls="erankly-panel-checklist" data-erankly-tab="checklist"><?php esc_html_e( 'SEO checklist', 'easyrankly' ); ?></button>
			<?php if ( erankly_internal_links_available() && erankly_ai_module_enabled() ) : ?>
			<button type="button" class="nav-tab erankly-tab" id="erankly-tab-internal-links" role="tab" aria-selected="false" aria-controls="erankly-panel-internal-links" data-erankly-tab="internal-links"><?php esc_html_e( 'Internal links', 'easyrankly' ); ?></button>
			<?php endif; ?>
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
		<div class="erankly-tab-panel" id="erankly-panel-schema" role="tabpanel" aria-labelledby="erankly-tab-schema" data-erankly-panel="schema" hidden>
			<?php erankly_render_post_schema_fields( $post ); ?>
		</div>
		<?php endif; ?>

		<div class="erankly-tab-panel" id="erankly-panel-visibility" role="tabpanel" aria-labelledby="erankly-tab-visibility" data-erankly-panel="visibility" hidden>
			<?php erankly_render_post_visibility_fields( $post ); ?>
		</div>

		<div class="erankly-tab-panel" id="erankly-panel-checklist" role="tabpanel" aria-labelledby="erankly-tab-checklist" data-erankly-panel="checklist" hidden>
			<?php erankly_render_post_seo_checklist( $post ); ?>
		</div>

		<?php if ( erankly_internal_links_available() && erankly_ai_module_enabled() && function_exists( 'erankly_render_post_internal_links_panel' ) ) : ?>
		<div class="erankly-tab-panel" id="erankly-panel-internal-links" role="tabpanel" aria-labelledby="erankly-tab-internal-links" data-erankly-panel="internal-links" hidden>
			<?php erankly_render_post_internal_links_panel( $post ); ?>
		</div>
		<?php endif; ?>

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
	$index_directive          = $term_id > 0 ? erankly_get_object_robots_directive( 'term', $term_id, 'index' ) : 'inherit';
	$follow_directive         = $term_id > 0 ? erankly_get_object_robots_directive( 'term', $term_id, 'follow' ) : 'inherit';
	$archive_directive        = $term_id > 0 ? erankly_get_object_robots_directive( 'term', $term_id, 'archive' ) : 'inherit';
	$snippet_directive        = $term_id > 0 ? erankly_get_object_robots_directive( 'term', $term_id, 'snippet' ) : 'inherit';
	$image_directive          = $term_id > 0 ? erankly_get_object_robots_directive( 'term', $term_id, 'image' ) : 'inherit';
	$hide_from_search_results = 'noindex' === $index_directive && $disable_sitemap;
	$og_title                 = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'og_title' ) : '';
	$og_description           = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'og_description' ) : '';
	$twitter_title            = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_title' ) : '';
	$twitter_desc             = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_description' ) : '';
	$twitter_card             = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_card_type' ) : '';
	$social_image_url         = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'social_image_url' ) : '';
	$og_image_url             = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'og_image_url' ) : '';
	$og_image_alt             = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'og_image_alt' ) : '';
	$twitter_image_url        = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_image_url' ) : '';
	$twitter_image_alt        = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'twitter_image_alt' ) : '';
	$id_suffix                = $term_id > 0 ? (string) $term_id : sanitize_key( $taxonomy );
	$title_placeholder        = erankly_get_term_global_meta_placeholder( $taxonomy, 'title' );
	$description_placeholder  = erankly_get_term_global_meta_placeholder( $taxonomy, 'description' );
	// The term being edited is its own {{term_name}}-style example.
	$term_object = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
	$examples    = erankly_get_admin_variable_examples( null, $term_object instanceof WP_Term ? $term_object : null );
	?>
	<div class="erankly-meta-box erankly-term-meta-box">
		<div class="nav-tab-wrapper wp-clearfix erankly-tabs" role="tablist" aria-label="<?php esc_attr_e( 'SEO settings', 'easyrankly' ); ?>">
			<button type="button" class="nav-tab nav-tab-active erankly-tab is-active" id="erankly-term-tab-general-<?php echo esc_attr( $id_suffix ); ?>" role="tab" aria-selected="true" aria-controls="erankly-term-panel-general-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-tab="term-general-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Search appearance', 'easyrankly' ); ?></button>
			<?php if ( ! $simplified_mode ) : ?>
			<button type="button" class="nav-tab erankly-tab" id="erankly-term-tab-social-<?php echo esc_attr( $id_suffix ); ?>" role="tab" aria-selected="false" aria-controls="erankly-term-panel-social-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-tab="term-social-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></button>
			<?php endif; ?>
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
					<?php erankly_render_variable_picker( $examples ); ?>
				</div>
				<span id="erankly-term-title-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-description-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<textarea id="erankly-term-description-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" rows="3" name="erankly_description" placeholder="<?php echo esc_attr( $description_placeholder ); ?>" data-erankly-limit="160" data-erankly-counter="erankly-term-description-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
					<?php erankly_render_variable_picker( $examples ); ?>
				</div>
				<span id="erankly-term-description-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<?php if ( ! $simplified_mode ) : ?>
			<div class="erankly-field">
				<label for="erankly-term-canonical-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Canonical URL', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<input id="erankly-term-canonical-<?php echo esc_attr( $id_suffix ); ?>" class="widefat" type="text" name="erankly_canonical" value="<?php echo esc_attr( $canonical ); ?>">
					<?php erankly_render_variable_picker( $examples ); ?>
				</div>
			</div>
			<?php endif; ?>
			<?php if ( $term_id > 0 && function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) : ?>
			<div class="erankly-field erankly-ai-field">
				<?php erankly_ai_render_editor_privacy_notice(); ?>
				<button type="button" class="button erankly-ai-generate"
					data-erankly-ai-object-id="<?php echo esc_attr( (string) $term_id ); ?>"
					data-erankly-ai-object-type="term"
					data-erankly-ai-target="seo"
					data-erankly-ai-title-target="#erankly-term-title-<?php echo esc_attr( $id_suffix ); ?>"
					data-erankly-ai-desc-target="#erankly-term-description-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Generate with AI', 'easyrankly' ); ?></button>
				<span class="erankly-ai-status description" data-erankly-ai-status aria-live="polite"></span>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $simplified_mode ) : ?>
		<div class="erankly-tab-panel" id="erankly-term-panel-social-<?php echo esc_attr( $id_suffix ); ?>" role="tabpanel" aria-labelledby="erankly-term-tab-social-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-panel="term-social-<?php echo esc_attr( $id_suffix ); ?>" hidden>
			<div class="erankly-field">
				<label for="erankly-term-og-title-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Open Graph Title', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<input id="erankly-term-og-title-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" type="text" name="erankly_og_title" value="<?php echo esc_attr( $og_title ); ?>" data-erankly-limit="60" data-erankly-counter="erankly-term-og-title-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
					<?php erankly_render_variable_picker( $examples ); ?>
				</div>
				<span id="erankly-term-og-title-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-og-description-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Open Graph Description', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<textarea id="erankly-term-og-description-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" rows="3" name="erankly_og_description" data-erankly-limit="200" data-erankly-counter="erankly-term-og-description-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $og_description ); ?></textarea>
					<?php erankly_render_variable_picker( $examples ); ?>
				</div>
				<span id="erankly-term-og-description-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-twitter-title-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'X (Twitter) Title', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<input id="erankly-term-twitter-title-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" type="text" name="erankly_twitter_title" value="<?php echo esc_attr( $twitter_title ); ?>" data-erankly-limit="70" data-erankly-counter="erankly-term-twitter-title-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
					<?php erankly_render_variable_picker( $examples ); ?>
				</div>
				<span id="erankly-term-twitter-title-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-twitter-description-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'X (Twitter) Description', 'easyrankly' ); ?></strong></label>
				<div class="erankly-variable-field" data-erankly-variable-field>
					<textarea id="erankly-term-twitter-description-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-counted-field" rows="3" name="erankly_twitter_description" data-erankly-limit="200" data-erankly-counter="erankly-term-twitter-description-counter-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $twitter_desc ); ?></textarea>
					<?php erankly_render_variable_picker( $examples ); ?>
				</div>
				<span id="erankly-term-twitter-description-counter-<?php echo esc_attr( $id_suffix ); ?>" class="erankly-character-counter" aria-live="polite"></span>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-twitter-card-type-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'X (Twitter) Card Type', 'easyrankly' ); ?></strong></label>
				<select id="erankly-term-twitter-card-type-<?php echo esc_attr( $id_suffix ); ?>" class="widefat erankly-term-twitter-card-type" name="erankly_twitter_card_type">
					<option value="" <?php selected( $twitter_card, '' ); ?>><?php esc_html_e( 'Default (summary_large_image)', 'easyrankly' ); ?></option>
					<option value="summary" <?php selected( $twitter_card, 'summary' ); ?>>summary</option>
				</select>
			</div>
			<div class="erankly-field">
				<label for="erankly-term-og-image-url-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'Open Graph image URL', 'easyrankly' ); ?></strong></label>
				<?php
				erankly_render_media_url_field(
					'erankly-term-og-image-url-' . $id_suffix,
					'erankly_og_image_url',
					$og_image_url,
					'' !== $social_image_url ? $social_image_url : erankly_default_social_image_placeholder()
				);
				?>
				<input class="widefat" type="text" name="erankly_og_image_alt" value="<?php echo esc_attr( $og_image_alt ); ?>" placeholder="<?php esc_attr_e( 'Open Graph image alt text', 'easyrankly' ); ?>">
			</div>
			<div class="erankly-field">
				<label for="erankly-term-twitter-image-url-<?php echo esc_attr( $id_suffix ); ?>"><strong><?php esc_html_e( 'X (Twitter) image URL', 'easyrankly' ); ?></strong></label>
				<?php
				erankly_render_media_url_field(
					'erankly-term-twitter-image-url-' . $id_suffix,
					'erankly_twitter_image_url',
					$twitter_image_url,
					'' !== $social_image_url ? $social_image_url : erankly_default_social_image_placeholder()
				);
				?>
				<input class="widefat" type="text" name="erankly_twitter_image_alt" value="<?php echo esc_attr( $twitter_image_alt ); ?>" placeholder="<?php esc_attr_e( 'X image alt text', 'easyrankly' ); ?>">
			</div>
			<?php if ( $term_id > 0 && function_exists( 'erankly_ai_enabled' ) && erankly_ai_enabled() ) : ?>
			<div class="erankly-field erankly-ai-field">
				<?php erankly_ai_render_editor_privacy_notice(); ?>
				<button type="button" class="button erankly-ai-generate"
					data-erankly-ai-object-id="<?php echo esc_attr( (string) $term_id ); ?>"
					data-erankly-ai-object-type="term"
					data-erankly-ai-target="social"
					data-erankly-ai-title-targets="#erankly-term-og-title-<?php echo esc_attr( $id_suffix ); ?>,#erankly-term-twitter-title-<?php echo esc_attr( $id_suffix ); ?>"
					data-erankly-ai-desc-targets="#erankly-term-og-description-<?php echo esc_attr( $id_suffix ); ?>,#erankly-term-twitter-description-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Generate with AI', 'easyrankly' ); ?></button>
				<span class="erankly-ai-status description" data-erankly-ai-status aria-live="polite"></span>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<div class="erankly-tab-panel" id="erankly-term-panel-visibility-<?php echo esc_attr( $id_suffix ); ?>" role="tabpanel" aria-labelledby="erankly-term-tab-visibility-<?php echo esc_attr( $id_suffix ); ?>" data-erankly-panel="term-visibility-<?php echo esc_attr( $id_suffix ); ?>" hidden>
			<fieldset class="erankly-field erankly-checkboxes">
				<?php if ( $simplified_mode ) : ?>
					<input type="hidden" name="erankly_existing_index_directive" value="<?php echo esc_attr( $index_directive ); ?>">
					<input type="hidden" name="erankly_existing_hide" value="<?php echo $hide_from_search_results ? '1' : '0'; ?>">
					<label><input type="checkbox" class="erankly-toggle" name="erankly_hide_from_search_results" value="1" <?php checked( $hide_from_search_results ); ?>> <strong><?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></strong></label>
					<span class="description"><?php esc_html_e( 'Sets noindex and removes this term from the sitemap. Does not affect nofollow or noarchive.', 'easyrankly' ); ?></span>
				<?php else : ?>
					<?php erankly_render_robots_directive_select( 'erankly_index_directive', $index_directive, __( 'Indexing', 'easyrankly' ), __( 'Index', 'easyrankly' ), __( 'Noindex', 'easyrankly' ) ); ?>
					<?php erankly_render_robots_directive_select( 'erankly_follow_directive', $follow_directive, __( 'Link following', 'easyrankly' ), __( 'Follow', 'easyrankly' ), __( 'Nofollow', 'easyrankly' ) ); ?>
					<?php erankly_render_robots_directive_select( 'erankly_archive_directive', $archive_directive, __( 'Cached copy', 'easyrankly' ), __( 'Allow archive', 'easyrankly' ), __( 'Noarchive', 'easyrankly' ) ); ?>
					<?php erankly_render_robots_directive_select( 'erankly_snippet_directive', $snippet_directive, __( 'Text snippet', 'easyrankly' ), __( 'Allow snippet', 'easyrankly' ), __( 'Nosnippet', 'easyrankly' ) ); ?>
					<?php erankly_render_robots_directive_select( 'erankly_image_directive', $image_directive, __( 'Image indexing', 'easyrankly' ), __( 'Allow image indexing', 'easyrankly' ), __( 'Noimageindex', 'easyrankly' ) ); ?>
					<label><?php esc_html_e( 'Max snippet', 'easyrankly' ); ?> <input class="small-text" type="number" min="-1" name="erankly_max_snippet" value="<?php echo esc_attr( (string) get_term_meta( $term_id, '_erankly_max_snippet', true ) ); ?>"></label><br>
					<label><?php esc_html_e( 'Max video preview', 'easyrankly' ); ?> <input class="small-text" type="number" min="-1" name="erankly_max_video_preview" value="<?php echo esc_attr( (string) get_term_meta( $term_id, '_erankly_max_video_preview', true ) ); ?>"></label><br>
					<label><?php esc_html_e( 'Max image preview', 'easyrankly' ); ?> <select name="erankly_max_image_preview"><option value="inherit"><?php esc_html_e( 'Inherit', 'easyrankly' ); ?></option>
					<?php foreach ( array( 'none', 'standard', 'large' ) as $preview ) : ?>
						<option value="<?php echo esc_attr( $preview ); ?>" <?php selected( get_term_meta( $term_id, '_erankly_max_image_preview', true ), $preview ); ?>><?php echo esc_html( $preview ); ?></option>
					<?php endforeach; ?></select></label><br>
					<label><input type="checkbox" class="erankly-toggle" name="erankly_indexifembedded" value="1" <?php checked( get_term_meta( $term_id, '_erankly_indexifembedded', true ), '1' ); ?>> <?php esc_html_e( 'Index if embedded when noindex applies', 'easyrankly' ); ?></label><br>
					<label><input type="checkbox" class="erankly-toggle" name="erankly_disable_sitemap" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
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
			'_erankly_og_image_url'        => 'erankly_og_image_url',
			'_erankly_og_image_alt'        => 'erankly_og_image_alt',
			'_erankly_twitter_image_url'   => 'erankly_twitter_image_url',
			'_erankly_twitter_image_alt'   => 'erankly_twitter_image_alt',
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
			$fields['_erankly_og_image_url'],
			$fields['_erankly_og_image_alt'],
			$fields['_erankly_twitter_image_url'],
			$fields['_erankly_twitter_image_alt']
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
	$existing_index_directive = isset( $_POST['erankly_existing_index_directive'] ) ? sanitize_key( wp_unslash( $_POST['erankly_existing_index_directive'] ) ) : 'inherit';
	$existing_hide            = ! empty( $_POST['erankly_existing_hide'] );

	if ( $simplified_mode ) {
		if ( $hide_from_search_results ) {
			update_post_meta( $post_id, '_erankly_index_directive', 'noindex' );
			update_post_meta( $post_id, '_erankly_noindex', '1' );
		} elseif ( $existing_hide && 'noindex' === $existing_index_directive ) {
			update_post_meta( $post_id, '_erankly_index_directive', 'inherit' );
			delete_post_meta( $post_id, '_erankly_noindex' );
		}
	} else {
		$directive_fields = array(
			'_erankly_index_directive'   => 'erankly_index_directive',
			'_erankly_follow_directive'  => 'erankly_follow_directive',
			'_erankly_archive_directive' => 'erankly_archive_directive',
			'_erankly_snippet_directive' => 'erankly_snippet_directive',
			'_erankly_image_directive'   => 'erankly_image_directive',
			'_erankly_max_snippet'       => 'erankly_max_snippet',
			'_erankly_max_video_preview' => 'erankly_max_video_preview',
			'_erankly_max_image_preview' => 'erankly_max_image_preview',
		);

		foreach ( $directive_fields as $key => $field ) {
			$value = erankly_sanitize_registered_meta( isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '', $key ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the called field-specific sanitizer.

			if ( '' === $value || 'inherit' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		foreach ( array(
			'noindex'   => 'index',
			'nofollow'  => 'follow',
			'noarchive' => 'archive',
		) as $legacy => $axis ) {
			$value = isset( $_POST[ 'erankly_' . $axis . '_directive' ] ) ? sanitize_key( wp_unslash( $_POST[ 'erankly_' . $axis . '_directive' ] ) ) : 'inherit';

			if ( $legacy === $value ) {
				update_post_meta( $post_id, '_erankly_' . $legacy, '1' );
			} else {
				delete_post_meta( $post_id, '_erankly_' . $legacy );
			}
		}

		if ( isset( $_POST['erankly_indexifembedded'] ) ) {
			update_post_meta( $post_id, '_erankly_indexifembedded', '1' );
		} else {
			delete_post_meta( $post_id, '_erankly_indexifembedded' );
		}
	}

	// "Hide from search results" sets only noindex + disable_sitemap.
	$booleans = array(
		'_erankly_exclude_search'    => isset( $_POST['erankly_exclude_search'] ),
		'_erankly_exclude_archive'   => isset( $_POST['erankly_exclude_archive'] ),
		'_erankly_exclude_from_news' => isset( $_POST['erankly_exclude_from_news'] ),
	);

	if ( ! $simplified_mode || $hide_from_search_results || $existing_hide ) {
		$booleans['_erankly_disable_sitemap'] = $hide_from_search_results || ( ! $simplified_mode && isset( $_POST['erankly_disable_sitemap'] ) );
	}

	foreach ( $booleans as $key => $enabled ) {
		if ( $enabled ) {
			update_post_meta( $post_id, $key, '1' );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}

	if ( ! $simplified_mode ) {
		$complex_fields = array(
			'_erankly_focus_keywords'        => isset( $_POST['erankly_focus_keywords'] ) ? wp_unslash( $_POST['erankly_focus_keywords'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			'_erankly_primary_terms'         => isset( $_POST['erankly_primary_terms'] ) ? wp_unslash( $_POST['erankly_primary_terms'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			'_erankly_schema_blocks'         => isset( $_POST['erankly_schema_blocks'] ) ? wp_unslash( $_POST['erankly_schema_blocks'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			'_erankly_schema_disabled_types' => isset( $_POST['erankly_schema_disabled_types'] ) ? preg_split( '/[\r\n,]+/', wp_unslash( $_POST['erankly_schema_disabled_types'] ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		);

		foreach ( $complex_fields as $key => $raw_value ) {
			$value = erankly_sanitize_registered_meta( $raw_value, $key );

			if ( empty( $value ) ) {
				delete_post_meta( $post_id, $key );
			} else {
				// Metadata APIs unslash values before persistence; preserve JSON-LD
				// escapes inside nested schema block arrays.
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		$schema_mode = erankly_sanitize_registered_meta( isset( $_POST['erankly_schema_mode'] ) ? wp_unslash( $_POST['erankly_schema_mode'] ) : '', '_erankly_schema_mode' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the called field-specific sanitizer.
		if ( '' === $schema_mode || 'default' === $schema_mode ) {
			delete_post_meta( $post_id, '_erankly_schema_mode' );
		} else {
			update_post_meta( $post_id, '_erankly_schema_mode', $schema_mode );
		}

		if ( isset( $_POST['erankly_cornerstone'] ) ) {
			update_post_meta( $post_id, '_erankly_cornerstone', '1' );
		} else {
			delete_post_meta( $post_id, '_erankly_cornerstone' );
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
		'_erankly_og_image_url'        => 'erankly_og_image_url',
		'_erankly_og_image_alt'        => 'erankly_og_image_alt',
		'_erankly_twitter_image_url'   => 'erankly_twitter_image_url',
		'_erankly_twitter_image_alt'   => 'erankly_twitter_image_alt',
	);

	$simplified_mode = (bool) erankly_get_setting( 'simplified_mode', 1 );

	if ( $simplified_mode ) {
		unset(
			$fields['_erankly_canonical'],
			$fields['_erankly_og_title'],
			$fields['_erankly_og_description'],
			$fields['_erankly_twitter_title'],
			$fields['_erankly_twitter_description'],
			$fields['_erankly_twitter_card_type'],
			$fields['_erankly_og_image_url'],
			$fields['_erankly_og_image_alt'],
			$fields['_erankly_twitter_image_url'],
			$fields['_erankly_twitter_image_alt']
		);
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
	$existing_index_directive = isset( $_POST['erankly_existing_index_directive'] ) ? sanitize_key( wp_unslash( $_POST['erankly_existing_index_directive'] ) ) : 'inherit';
	$existing_hide            = ! empty( $_POST['erankly_existing_hide'] );

	if ( $simplified_mode ) {
		if ( $hide_from_search_results ) {
			update_term_meta( $term_id, '_erankly_index_directive', 'noindex' );
			update_term_meta( $term_id, '_erankly_noindex', '1' );
		} elseif ( $existing_hide && 'noindex' === $existing_index_directive ) {
			delete_term_meta( $term_id, '_erankly_index_directive' );
			delete_term_meta( $term_id, '_erankly_noindex' );
		}
	} else {
		$directive_fields = array(
			'_erankly_index_directive'   => 'erankly_index_directive',
			'_erankly_follow_directive'  => 'erankly_follow_directive',
			'_erankly_archive_directive' => 'erankly_archive_directive',
			'_erankly_snippet_directive' => 'erankly_snippet_directive',
			'_erankly_image_directive'   => 'erankly_image_directive',
			'_erankly_max_snippet'       => 'erankly_max_snippet',
			'_erankly_max_video_preview' => 'erankly_max_video_preview',
			'_erankly_max_image_preview' => 'erankly_max_image_preview',
		);

		foreach ( $directive_fields as $key => $field ) {
			$value = erankly_sanitize_registered_meta( isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '', $key ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the called field-specific sanitizer.

			if ( '' === $value || 'inherit' === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}

		if ( isset( $_POST['erankly_indexifembedded'] ) ) {
			update_term_meta( $term_id, '_erankly_indexifembedded', '1' );
		} else {
			delete_term_meta( $term_id, '_erankly_indexifembedded' );
		}

		foreach ( array(
			'noindex'   => 'index',
			'nofollow'  => 'follow',
			'noarchive' => 'archive',
		) as $legacy => $axis ) {
			$value = isset( $_POST[ 'erankly_' . $axis . '_directive' ] ) ? sanitize_key( wp_unslash( $_POST[ 'erankly_' . $axis . '_directive' ] ) ) : 'inherit';

			if ( $legacy === $value ) {
				update_term_meta( $term_id, '_erankly_' . $legacy, '1' );
			} else {
				delete_term_meta( $term_id, '_erankly_' . $legacy );
			}
		}
	}

	// "Hide from search results" intentionally sets only noindex + disable_sitemap.
	$booleans = array();

	if ( ! $simplified_mode || $hide_from_search_results || $existing_hide ) {
		$booleans['_erankly_disable_sitemap'] = $hide_from_search_results || ( ! $simplified_mode && isset( $_POST['erankly_disable_sitemap'] ) );
	}

	foreach ( $booleans as $key => $enabled ) {
		if ( $enabled ) {
			update_term_meta( $term_id, $key, '1' );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}
}
