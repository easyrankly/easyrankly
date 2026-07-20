<?php
/**
 * SEO checklist helpers for singular content.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ERANKLY_SEO_CHECKLIST_TITLE_LIMIT        = 65;
const ERANKLY_SEO_CHECKLIST_DESCRIPTION_LIMIT  = 160;
const ERANKLY_SEO_CHECKLIST_MIN_CONTENT_LENGTH = 300;

/**
 * Returns checklist group labels.
 *
 * @return array<string,string>
 */
function erankly_get_seo_checklist_group_labels(): array {
	return array(
		'appearance' => __( 'Search appearance', 'easyrankly' ),
		'indexing'   => __( 'Indexing', 'easyrankly' ),
	);
}

/**
 * Returns the effective SEO title used for checklist checks.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_get_post_checklist_effective_title( int $post_id ): string {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return '';
	}

	$title = erankly_get_post_meta_string( $post_id, 'title' );

	if ( '' !== $title ) {
		$title = erankly_replace_variables( $title, $post_id, array( 'seo_title' ) );
	} else {
		$template = erankly_get_global_post_type_meta( $post->post_type, 'title' );

		if ( '' !== $template ) {
			$title = erankly_replace_variables( $template, $post_id, array( 'seo_title' ) );
		} else {
			$title = get_the_title( $post );
		}
	}

	return erankly_normalize_seo_text( $title );
}

/**
 * Returns the effective meta description used for checklist checks.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_get_post_checklist_effective_description( int $post_id ): string {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return '';
	}

	$description = erankly_get_post_meta_string( $post_id, 'description' );

	if ( '' !== $description ) {
		return erankly_normalize_seo_text(
			erankly_replace_variables( $description, $post_id, array( 'meta_description' ) )
		);
	}

	$template = erankly_get_global_post_type_meta( $post->post_type, 'description' );

	if ( '' !== $template ) {
		return erankly_normalize_seo_text(
			erankly_replace_variables( $template, $post_id, array( 'meta_description' ) )
		);
	}

	$source = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;

	return erankly_trim_text( strip_shortcodes( (string) $source ), ERANKLY_SEO_CHECKLIST_DESCRIPTION_LIMIT );
}

/**
 * Returns whether the site provides a default preview image fallback.
 *
 * @return bool
 */
function erankly_site_has_default_preview_image(): bool {
	if ( '' !== trim( (string) erankly_get_setting( 'default_social_image_url', '' ) ) ) {
		return true;
	}

	if ( absint( erankly_get_setting( 'default_og_image', 0 ) ) > 0 ) {
		return true;
	}

	return '' !== erankly_get_organization_logo_url();
}

/**
 * Returns whether a post has a preview image available.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function erankly_post_has_preview_image( int $post_id ): bool {
	if ( get_post_thumbnail_id( $post_id ) > 0 ) {
		return true;
	}

	if ( ! empty( erankly_get_post_content_image_urls( $post_id ) ) ) {
		return true;
	}

	return erankly_site_has_default_preview_image();
}

/**
 * Returns whether a post can be indexed by search engines.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function erankly_post_is_indexable( int $post_id ): bool {
	return ! erankly_get_post_meta_bool( $post_id, 'noindex' );
}

/**
 * Returns whether a post has enough searchable content.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function erankly_post_has_minimum_content( int $post_id ): bool {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	$source = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
	$text   = preg_replace( '/\s+/u', ' ', trim( wp_strip_all_tags( strip_shortcodes( (string) $source ) ) ) );

	return is_string( $text ) && mb_strlen( $text, 'UTF-8' ) >= ERANKLY_SEO_CHECKLIST_MIN_CONTENT_LENGTH;
}

/**
 * Returns whether a post has a custom social image set.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function erankly_post_has_custom_social_image( int $post_id ): bool {
	if ( absint( get_post_meta( $post_id, '_erankly_og_image_id', true ) ) > 0 ) {
		return true;
	}

	return '' !== erankly_get_post_meta_string( $post_id, 'social_image_url' );
}

/**
 * Returns whether a post has a custom canonical URL set.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function erankly_post_has_canonical( int $post_id ): bool {
	return '' !== erankly_get_post_meta_string( $post_id, 'canonical' );
}

/**
 * Returns whether a checklist text value is within the recommended limit.
 *
 * @param string $text  Text value.
 * @param int    $limit Character limit.
 * @return bool
 */
function erankly_seo_checklist_text_within_limit( string $text, int $limit ): bool {
	$text = trim( $text );

	if ( '' === $text ) {
		return false;
	}

	return mb_strlen( $text, 'UTF-8' ) <= $limit;
}

/**
 * Returns the checklist items with their completion state.
 *
 * @param int $post_id Post ID.
 * @return array<string,array{label:string,done:bool,group:string}>
 */
function erankly_get_seo_checklist_items( int $post_id ): array {
	$simplified_mode = (bool) erankly_get_setting( 'simplified_mode', 1 );
	$title           = erankly_get_post_checklist_effective_title( $post_id );
	$description     = erankly_get_post_checklist_effective_description( $post_id );

	$items = array(
		'title'         => array(
			'label' => __( 'SEO title length', 'easyrankly' ),
			'done'  => erankly_seo_checklist_text_within_limit( $title, ERANKLY_SEO_CHECKLIST_TITLE_LIMIT ),
			'group' => 'appearance',
		),
		'description'   => array(
			'label' => __( 'Meta description length', 'easyrankly' ),
			'done'  => erankly_seo_checklist_text_within_limit( $description, ERANKLY_SEO_CHECKLIST_DESCRIPTION_LIMIT ),
			'group' => 'appearance',
		),
		'preview_image' => array(
			'label' => __( 'Preview image', 'easyrankly' ),
			'done'  => erankly_post_has_preview_image( $post_id ),
			'group' => 'appearance',
		),
		'indexable'     => array(
			'label' => __( 'Search engine indexing', 'easyrankly' ),
			'done'  => erankly_post_is_indexable( $post_id ),
			'group' => 'indexing',
		),
		'content'       => array(
			'label' => __( 'Content length', 'easyrankly' ),
			'done'  => erankly_post_has_minimum_content( $post_id ),
			'group' => 'indexing',
		),
	);

	if ( ! $simplified_mode ) {
		$items['social_image'] = array(
			'label' => __( 'Social image', 'easyrankly' ),
			'done'  => erankly_post_has_custom_social_image( $post_id ),
			'group' => 'appearance',
		);
		$items['canonical']    = array(
			'label' => __( 'Canonical URL', 'easyrankly' ),
			'done'  => erankly_post_has_canonical( $post_id ),
			'group' => 'appearance',
		);
	}

	return $items;
}

/**
 * Returns the aggregate checklist status.
 *
 * @param array<string,array{label:string,done:bool,group:string}> $items Checklist items.
 * @return string One of 'incomplete', 'partial' or 'complete'.
 */
function erankly_get_seo_checklist_status( array $items ): string {
	$done = count( array_filter( wp_list_pluck( $items, 'done' ) ) );

	if ( 0 === $done ) {
		return 'incomplete';
	}

	return count( $items ) === $done ? 'complete' : 'partial';
}

/**
 * Returns editor-side checklist configuration for a post.
 *
 * @param WP_Post $post Post object.
 * @return array<string,mixed>
 */
function erankly_get_seo_checklist_editor_config( WP_Post $post ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The post argument is retained for the stable per-post configuration API.
	return array(
		'descriptionLimit'       => ERANKLY_SEO_CHECKLIST_DESCRIPTION_LIMIT,
		'hasDefaultPreviewImage' => erankly_site_has_default_preview_image(),
		'minContentLength'       => ERANKLY_SEO_CHECKLIST_MIN_CONTENT_LENGTH,
		'titleLimit'             => ERANKLY_SEO_CHECKLIST_TITLE_LIMIT,
	);
}

/**
 * Renders the SEO checklist panel for the classic post meta box.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_post_seo_checklist( WP_Post $post ): void {
	$items        = erankly_get_seo_checklist_items( $post->ID );
	$group_labels = erankly_get_seo_checklist_group_labels();
	$grouped      = array();

	foreach ( $items as $key => $item ) {
		$group = $item['group'];

		if ( ! isset( $grouped[ $group ] ) ) {
			$grouped[ $group ] = array();
		}

		$grouped[ $group ][ $key ] = $item;
	}
	?>
	<div class="erankly-seo-checklist" data-erankly-seo-checklist>
		<?php foreach ( $grouped as $group_key => $group_items ) : ?>
		<div class="erankly-seo-checklist-group">
			<p class="erankly-seo-checklist-group-label"><?php echo esc_html( $group_labels[ $group_key ] ?? $group_key ); ?></p>
			<ul class="erankly-seo-checklist-items">
				<?php foreach ( $group_items as $key => $item ) : ?>
				<li class="erankly-seo-checklist-item<?php echo $item['done'] ? ' is-done' : ''; ?>" data-erankly-seo-checklist-item="<?php echo esc_attr( $key ); ?>">
					<svg class="erankly-seo-checklist-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
						<path class="erankly-seo-checklist-icon-cross" d="M4 4l8 8m0-8-8 8"></path>
						<path class="erankly-seo-checklist-icon-check" d="M3.25 8.25 6.5 11.5 12.75 4.75"></path>
					</svg>
					<span class="erankly-seo-checklist-label"><?php echo esc_html( $item['label'] ); ?></span>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
}
