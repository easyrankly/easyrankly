<?php
/**
 * Shared admin field renderers (variable picker, JSON-LD schema blocks, custom-code blocks, media URL
 * fields) reused by the settings page, the classic meta box and the React editor panels. The data-* hooks
 * in the markup are the contract with admin.js / admin-schema.js: change both sides together.
 */
defined( 'ABSPATH' ) || exit;
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
				'post_year'          => __( 'Post year', 'easyrankly' ),
				'post_month'         => __( 'Post month', 'easyrankly' ),
				'post_day'           => __( 'Post day', 'easyrankly' ),
				'post_modified_date' => __( 'Post modified date', 'easyrankly' ),
				'post_author'        => __( 'Post author', 'easyrankly' ),
				'post_categories'    => __( 'Post categories', 'easyrankly' ),
				'post_tags'          => __( 'Post tags', 'easyrankly' ),
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
				'author_name'      => __( 'Archive author name', 'easyrankly' ),
				'author_first_name' => __( 'Author first name', 'easyrankly' ),
				'author_last_name' => __( 'Author last name', 'easyrankly' ),
				'author_bio'       => __( 'Archive author biography', 'easyrankly' ),
				'author_url'       => __( 'Archive author URL', 'easyrankly' ),
				'author_website'   => __( 'Author website', 'easyrankly' ),
				'author_profile_url' => __( 'Author website with archive fallback', 'easyrankly' ),
				'archive_date'     => __( 'Archive date', 'easyrankly' ),
				'search_query'     => __( 'Search query', 'easyrankly' ),
			),
		),
		'pagination' => array(
			'label'     => __( 'Pagination', 'easyrankly' ),
			'variables' => array(
				'pagination'         => __( 'Conditional pagination label', 'easyrankly' ),
				'current_pagination' => __( 'Current page number after page one', 'easyrankly' ),
				'page_number'        => __( 'Current page number', 'easyrankly' ),
				'max_pages'          => __( 'Total pages', 'easyrankly' ),
			),
		),
		'site'       => array(
			'label'     => __( 'Site', 'easyrankly' ),
			'variables' => array(
				'site_name'             => __( 'Site name', 'easyrankly' ),
				'current_year'          => __( 'Current year', 'easyrankly' ),
				'current_month'         => __( 'Current month', 'easyrankly' ),
				'current_day'           => __( 'Current day', 'easyrankly' ),
				'current_date'          => __( 'Current date', 'easyrankly' ),
				'site_description'      => __( 'Site description', 'easyrankly' ),
				'site_url'              => __( 'Site URL', 'easyrankly' ),
				'site_language'         => __( 'Site language', 'easyrankly' ),
				'organization_name'     => __( 'Organization name', 'easyrankly' ),
				'website_name'          => __( 'Website name', 'easyrankly' ),
				'website_description'   => __( 'Website description', 'easyrankly' ),
				'organization_logo_url' => __( 'Organization logo URL', 'easyrankly' ),
				'site_icon_url'         => __( 'Site icon URL', 'easyrankly' ),
				'schema_identity_id'    => __( 'Schema identity ID', 'easyrankly' ),
			),
		),
	);
}
function erankly_render_variable_picker( array $examples = array(), array $allowed_groups = array() ): void {
	if ( array() === $examples ) {
		static $site_examples = null;
		if ( null === $site_examples ) {
			if ( ! function_exists( 'erankly_get_admin_variable_examples' ) ) {
				erankly_load_content_helpers();
			}
			$site_examples = erankly_get_admin_variable_examples();
		}
		$examples = $site_examples;
	}
	$groups = erankly_get_variable_groups();
	if ( array() !== $allowed_groups ) {
		$allowed = array_fill_keys( array_map( 'sanitize_key', $allowed_groups ), true );
		$groups  = array_intersect_key( $groups, $allowed );
		$variable_keys = array();
		foreach ( $groups as $group ) {
			$variable_keys += array_fill_keys( array_keys( (array) $group['variables'] ), true );
		}
		$examples = array_intersect_key( $examples, $variable_keys );
	}
	$listbox_id = wp_unique_id( 'erankly-variable-listbox-' );
	?>
	<span class="erankly-variable-preview" data-erankly-variable-preview aria-hidden="true" <?php echo $examples ? 'data-erankly-variable-examples="' . esc_attr( (string) wp_json_encode( $examples ) ) . '"' : ''; ?>></span>
	<div class="erankly-variable-menu" id="<?php echo esc_attr( $listbox_id ); ?>" data-erankly-variable-menu role="listbox" hidden>
		<?php foreach ( $groups as $group_key => $group ) : ?>
			<?php $group_id = $listbox_id . '-' . sanitize_html_class( (string) $group_key ); ?>
			<div class="erankly-variable-group" role="group" aria-labelledby="<?php echo esc_attr( $group_id ); ?>" data-erankly-variable-group>
				<span class="erankly-variable-group-label" id="<?php echo esc_attr( $group_id ); ?>"><?php echo esc_html( (string) $group['label'] ); ?></span>
			<?php foreach ( $group['variables'] as $key => $label ) : ?>
				<?php $variable = '{{' . $key . '}}'; ?>
				<button type="button" class="erankly-variable-option" role="option" data-erankly-variable="<?php echo esc_attr( $variable ); ?>" data-erankly-variable-search-text="<?php echo esc_attr( strtolower( $label . ' ' . $key . ' ' . $variable ) ); ?>">
					<span class="erankly-variable-option-primary"><?php echo esc_html( $variable ); ?></span>
					<span class="erankly-variable-option-secondary"><?php echo esc_html( $label ); ?></span>
				</button>
			<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
function erankly_render_schema_block( array $block, string $index, string $name_prefix, bool $is_global = false ): void {
	$enabled     = ! isset( $block['enabled'] ) || ! empty( $block['enabled'] );
	$fields      = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : array();
	$custom_json = isset( $fields['custom_json'] ) ? (string) $fields['custom_json'] : '';
	?>
	<details class="erankly-schema-block" data-erankly-schema-block>
		<summary class="erankly-schema-block-header">
			<span class="erankly-schema-title"><?php esc_html_e( 'JSON-LD schema', 'easyrankly' ); ?></span>
		</summary>
		<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][type]" value="custom">
		<div class="erankly-schema-panel" data-erankly-schema-panel>
			<?php if ( $is_global ) : ?>
				<?php erankly_render_schema_targeting_fields( $block, $index, $name_prefix, $enabled, '', true ); ?>
			<?php endif; ?>
			<?php erankly_render_schema_textarea_field( $index, $name_prefix, 'custom_json', __( 'JSON-LD code', 'easyrankly' ), $custom_json, 10 ); ?>
			<p class="description">
				<?php if ( $is_global ) : ?>
					<?php esc_html_e( 'Paste one JSON-LD object or use a top-level @graph array for multiple schemas. Supports {{variables}}.', 'easyrankly' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'One JSON-LD object or @graph array; supports {{variables}}.', 'easyrankly' ); ?>
				<?php endif; ?>
			</p>
			<button type="button" class="button erankly-btn-danger erankly-schema-delete" data-erankly-remove-schema><?php esc_html_e( 'Delete', 'easyrankly' ); ?></button>
		</div>
	</details>
	<?php
}
function erankly_render_schema_targeting_fields( array $block, string $index, string $name_prefix, bool $enabled, string $toggle_label = '', bool $include_archive_contexts = false, string $ui_context = 'schema' ): void {
	$target_contexts   = isset( $block['target_contexts'] ) && is_array( $block['target_contexts'] ) ? array_map( 'sanitize_key', $block['target_contexts'] ) : array();
	$target_post_types = isset( $block['target_post_types'] ) && is_array( $block['target_post_types'] ) ? array_map( 'sanitize_key', $block['target_post_types'] ) : array();
	$include_items     = isset( $block['include_items'] ) ? (string) $block['include_items'] : '';
	$exclude_items     = isset( $block['exclude_items'] ) ? (string) $block['exclude_items'] : '';
	$name_hash         = sanitize_html_class( md5( $name_prefix ) );
	$is_custom_code    = 'custom-code' === $ui_context;
	$id_prefix         = $is_custom_code ? 'erankly-code-' : 'erankly-schema-';
	$include_items_id  = $id_prefix . sanitize_html_class( $index ) . '-' . $name_hash . '-include-items';
	$exclude_items_id  = $id_prefix . sanitize_html_class( $index ) . '-' . $name_hash . '-exclude-items';
	$contexts          = array(
		'front_page'        => __( 'Front page', 'easyrankly' ),
		'posts_page'        => __( 'Posts page', 'easyrankly' ),
		'singular'          => __( 'Singular content', 'easyrankly' ),
		'post_type_archive' => __( 'Post type archives', 'easyrankly' ),
		'search'            => __( 'Search results', 'easyrankly' ),
	);
	if ( $include_archive_contexts ) {
		$contexts['taxonomy'] = __( 'Taxonomy archives', 'easyrankly' );
		$contexts['author']   = __( 'Author archives', 'easyrankly' );
		$contexts['date']     = __( 'Date archives', 'easyrankly' );
		$contexts['404']      = __( '404 page', 'easyrankly' );
	}
	?>
	<fieldset class="<?php echo $is_custom_code ? 'erankly-code-targeting' : 'erankly-schema-targeting'; ?>">
		<legend><?php echo $is_custom_code ? esc_html__( 'Snippet application rules', 'easyrankly' ) : esc_html__( 'Global schema application rules', 'easyrankly' ); ?></legend>
		<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $enabled ); ?>> <?php echo '' !== $toggle_label ? esc_html( $toggle_label ) : esc_html__( 'Enable this schema block', 'easyrankly' ); ?></label>
		<div class="<?php echo $is_custom_code ? 'erankly-code-targeting-grid' : 'erankly-schema-targeting-grid'; ?>">
			<fieldset class="<?php echo $is_custom_code ? 'erankly-code-targeting-group' : 'erankly-schema-targeting-group'; ?>">
				<legend><?php esc_html_e( 'Apply on', 'easyrankly' ); ?></legend>
				<?php foreach ( $contexts as $context => $label ) : ?>
					<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][target_contexts][]" value="<?php echo esc_attr( $context ); ?>" <?php checked( in_array( $context, $target_contexts, true ) ); ?> data-erankly-target-context="<?php echo esc_attr( $context ); ?>"> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Choose at least one context. A block with no context is saved as disabled and is never emitted.', 'easyrankly' ); ?></p>
			</fieldset>
			<fieldset class="<?php echo $is_custom_code ? 'erankly-code-targeting-group' : 'erankly-schema-targeting-group'; ?>" data-erankly-targeting-for="post-types">
				<legend><?php esc_html_e( 'Post types', 'easyrankly' ); ?></legend>
				<p class="description"><?php esc_html_e( 'Applies to singular content and post type archives. Leave empty to match every public type in those contexts. Post type archives with no types selected are saved as disabled.', 'easyrankly' ); ?></p>
				<?php foreach ( erankly_get_public_post_types() as $post_type => $object ) : ?>
					<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][target_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $target_post_types, true ) ); ?>> <?php echo esc_html( $object->labels->singular_name ); ?></label>
				<?php endforeach; ?>
			</fieldset>
		</div>
		<div class="<?php echo $is_custom_code ? 'erankly-code-targeting-grid' : 'erankly-schema-targeting-grid'; ?>" data-erankly-targeting-for="include-exclude">
			<div class="erankly-field">
				<label for="<?php echo esc_attr( $include_items_id ); ?>"><?php esc_html_e( 'Include IDs or slugs', 'easyrankly' ); ?></label>
				<textarea id="<?php echo esc_attr( $include_items_id ); ?>" class="widefat" rows="3" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][include_items]"><?php echo esc_textarea( $include_items ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional. Limits the block to matching singular posts, terms, or authors. Has no effect on front page, search, date archives, or 404.', 'easyrankly' ); ?></p>
			</div>
			<div class="erankly-field">
				<label for="<?php echo esc_attr( $exclude_items_id ); ?>"><?php esc_html_e( 'Exclude IDs or slugs', 'easyrankly' ); ?></label>
				<textarea id="<?php echo esc_attr( $exclude_items_id ); ?>" class="widefat" rows="3" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][exclude_items]"><?php echo esc_textarea( $exclude_items ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional. Skips matching singular posts, terms, or authors.', 'easyrankly' ); ?></p>
			</div>
		</div>
	</fieldset>
	<?php
}
function erankly_render_custom_code_block( array $block, string $index, string $name_prefix, bool $can_unfiltered ): void {
	$enabled = ! isset( $block['enabled'] ) || ! empty( $block['enabled'] );
	$name    = isset( $block['name'] ) ? trim( (string) $block['name'] ) : '';
	$code    = isset( $block['code'] ) ? (string) $block['code'] : '';
	$field_id = 'erankly-code-' . sanitize_html_class( $index ) . '-' . sanitize_html_class( md5( $name_prefix ) );
	$name_id  = $field_id . '-name';
	?>
	<details class="erankly-code-block" data-erankly-code-block>
		<summary class="erankly-code-block-header">
			<span class="erankly-code-title" data-erankly-code-title><?php echo esc_html( '' !== $name ? $name : __( 'Code snippet', 'easyrankly' ) ); ?></span>
		</summary>
		<div class="erankly-code-panel" data-erankly-code-panel>
			<?php if ( ! empty( $block['legacy_migrated'] ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][legacy_migrated]" value="1">
			<?php endif; ?>
			<div class="erankly-code-field">
				<label for="<?php echo esc_attr( $name_id ); ?>"><?php esc_html_e( 'Snippet name', 'easyrankly' ); ?></label>
				<input id="<?php echo esc_attr( $name_id ); ?>" class="widefat" type="text" maxlength="120" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Code snippet', 'easyrankly' ); ?>" data-erankly-code-name>
			</div>
			<?php erankly_render_schema_targeting_fields( $block, $index, $name_prefix, $enabled, __( 'Enable this code snippet', 'easyrankly' ), true, 'custom-code' ); ?>
			<div class="erankly-code-field">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Code', 'easyrankly' ); ?></label>
				<textarea id="<?php echo esc_attr( $field_id ); ?>" class="widefat code" rows="12" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][code]" <?php echo $can_unfiltered ? '' : 'readonly'; ?>><?php echo esc_textarea( $code ); ?></textarea>
			</div>
			<button type="button" class="button erankly-btn-danger erankly-code-delete" data-erankly-remove-code><?php esc_html_e( 'Delete', 'easyrankly' ); ?></button>
		</div>
	</details>
	<?php
}
function erankly_render_schema_textarea_field( string $index, string $name_prefix, string $key, string $label, string $value, int $rows ): void {
	$field_id = 'erankly-schema-' . sanitize_html_class( $index ) . '-' . sanitize_html_class( $key );
	$error_id = $field_id . '-error';
	?>
	<div class="erankly-schema-field">
		<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<textarea id="<?php echo esc_attr( $field_id ); ?>" class="widefat" rows="<?php echo esc_attr( (string) $rows ); ?>" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][fields][<?php echo esc_attr( $key ); ?>]" aria-describedby="<?php echo esc_attr( $error_id ); ?>" data-erankly-json-ld-input><?php echo esc_textarea( $value ); ?></textarea>
			<?php erankly_render_variable_picker(); ?>
		</div>
		<p class="erankly-schema-json-error" id="<?php echo esc_attr( $error_id ); ?>" data-erankly-json-ld-error role="alert" hidden><?php echo esc_html( erankly_invalid_json_ld_message() ); ?></p>
	</div>
	<?php
}
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
