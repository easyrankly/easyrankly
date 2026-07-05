<?php
/**
 * SEO checklist helpers for singular content.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the checklist items with their completion state.
 *
 * @param int $post_id Post ID.
 * @return array<string,array{label:string,done:bool}>
 */
function erankly_get_seo_checklist_items( int $post_id ): array {
	return array(
		'title'          => array(
			'label' => __( 'Meta title', 'easyrankly' ),
			'done'  => '' !== erankly_get_post_meta_string( $post_id, 'title' ),
		),
		'description'    => array(
			'label' => __( 'Meta description', 'easyrankly' ),
			'done'  => '' !== erankly_get_post_meta_string( $post_id, 'description' ),
		),
		'featured_image' => array(
			'label' => __( 'Featured image', 'easyrankly' ),
			'done'  => (int) get_post_thumbnail_id( $post_id ) > 0,
		),
	);
}

/**
 * Returns the aggregate checklist status.
 *
 * @param array<string,array{label:string,done:bool}> $items Checklist items.
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
 * Renders the SEO checklist panel for the classic post meta box.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_post_seo_checklist( WP_Post $post ): void {
	$items  = erankly_get_seo_checklist_items( $post->ID );
	$status = erankly_get_seo_checklist_status( $items );
	$done   = count( array_filter( wp_list_pluck( $items, 'done' ) ) );
	?>
	<div class="erankly-seo-checklist is-<?php echo esc_attr( $status ); ?>" data-erankly-seo-checklist>
		<div class="erankly-seo-checklist-intro">
			<p class="description erankly-seo-checklist-help"><?php esc_html_e( 'Complete these items to improve this page\'s search appearance.', 'easyrankly' ); ?></p>
			<span class="erankly-seo-checklist-count" data-erankly-seo-checklist-count><?php echo esc_html( $done . '/' . count( $items ) ); ?></span>
		</div>
		<ul class="erankly-seo-checklist-items">
			<?php foreach ( $items as $key => $item ) : ?>
			<li class="erankly-seo-checklist-item<?php echo $item['done'] ? ' is-done' : ''; ?>" data-erankly-seo-checklist-item="<?php echo esc_attr( $key ); ?>">
				<span class="erankly-seo-checklist-check" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>
				</span>
				<span class="erankly-seo-checklist-label"><?php echo esc_html( $item['label'] ); ?></span>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}
