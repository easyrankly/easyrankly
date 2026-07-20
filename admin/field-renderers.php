<?php
/**
 * Shared admin field renderers used by settings and classic-editor surfaces.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns grouped dynamic variables for admin pickers.
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
				'current_year'          => __( 'Current year', 'easyrankly' ),
				'current_month'         => __( 'Current month', 'easyrankly' ),
				'current_day'           => __( 'Current day', 'easyrankly' ),
				'current_date'          => __( 'Current date', 'easyrankly' ),
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
 * @param array<string,string> $examples Example values for friendly previews.
 * @return void
 */
function erankly_render_variable_picker( array $examples = array() ): void {
	?>
	<span class="erankly-variable-preview" data-erankly-variable-preview aria-hidden="true" <?php echo $examples ? 'data-erankly-variable-examples="' . esc_attr( (string) wp_json_encode( $examples ) ) . '"' : ''; ?>></span>
	<div class="erankly-variable-menu" data-erankly-variable-menu role="listbox" hidden>
		<?php foreach ( erankly_get_variable_groups() as $group ) : ?>
			<?php foreach ( $group['variables'] as $key => $label ) : ?>
				<?php $variable = '{{' . $key . '}}'; ?>
				<button type="button" class="erankly-variable-option" role="option" data-erankly-variable="<?php echo esc_attr( $variable ); ?>" data-erankly-variable-search-text="<?php echo esc_attr( strtolower( $label . ' ' . $key . ' ' . $variable ) ); ?>">
					<span class="erankly-variable-option-primary"><?php echo esc_html( $variable ); ?></span>
					<span class="erankly-variable-option-secondary"><?php echo esc_html( $label ); ?></span>
				</button>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Renders a repeatable schema block.
 *
 * @param array<string,mixed> $block       Schema block.
 * @param string              $index       Field index.
 * @param string              $name_prefix Field name prefix.
 * @param bool                $is_global   Whether to render targeting controls.
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
			<p class="description">
				<?php if ( $is_global ) : ?>
					<?php esc_html_e( 'Paste one JSON-LD object or use a top-level @graph array for multiple schemas. Supports {{variables}}.', 'easyrankly' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'One JSON-LD object or @graph array; supports {{variables}}.', 'easyrankly' ); ?>
				<?php endif; ?>
			</p>
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
		<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Enable this schema block', 'easyrankly' ); ?></label>
		<div class="erankly-schema-targeting-grid">
			<div>
				<strong><?php esc_html_e( 'Apply on', 'easyrankly' ); ?></strong>
				<?php foreach ( $contexts as $context => $label ) : ?>
					<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][target_contexts][]" value="<?php echo esc_attr( $context ); ?>" <?php checked( in_array( $context, $target_contexts, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>
			<div>
				<strong><?php esc_html_e( 'Post types', 'easyrankly' ); ?></strong>
				<?php foreach ( erankly_get_public_post_types() as $post_type => $object ) : ?>
					<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][target_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $target_post_types, true ) ); ?>> <?php echo esc_html( $object->labels->singular_name ); ?></label>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="erankly-schema-targeting-grid">
			<label><strong><?php esc_html_e( 'Include IDs or slugs', 'easyrankly' ); ?></strong><textarea class="widefat" rows="3" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][include_items]"><?php echo esc_textarea( $include_items ); ?></textarea></label>
			<label><strong><?php esc_html_e( 'Exclude IDs or slugs', 'easyrankly' ); ?></strong><textarea class="widefat" rows="3" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][exclude_items]"><?php echo esc_textarea( $exclude_items ); ?></textarea></label>
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
 * @param bool   $show_preview       Whether to render the image preview.
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
