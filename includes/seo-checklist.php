<?php
/**
 * Floating SEO checklist for singular content.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimum featured image width recommended for social sharing.
 */
const ERANKLY_SEO_CHECKLIST_IMAGE_WIDTH = 1200;

/**
 * Minimum featured image height recommended for social sharing.
 */
const ERANKLY_SEO_CHECKLIST_IMAGE_HEIGHT = 630;

/**
 * Returns the saved SEO checklist preference.
 *
 * The checklist is enabled by default until its dedicated option is saved.
 *
 * @return bool
 */
function erankly_seo_checklist_preference_enabled(): bool {
	return (bool) erankly_get_setting( 'enable_seo_checklist', 1 );
}

/**
 * Returns whether the SEO checklist is active.
 *
 * Simplified mode is the master control and overrides the saved checklist
 * preference when disabled.
 *
 * @return bool
 */
function erankly_seo_checklist_enabled(): bool {
	return (bool) erankly_get_setting( 'simplified_mode', 1 )
		&& erankly_seo_checklist_preference_enabled();
}

/**
 * Returns the "How to do it" documentation URL.
 *
 * @return string
 */
function erankly_seo_checklist_docs_url(): string {
	/**
	 * Filters the SEO checklist documentation URL.
	 *
	 * @param string $url Documentation URL.
	 */
	return (string) apply_filters( 'erankly_seo_checklist_docs_url', 'https://docs.easyrankly.com/seo-checklist/' );
}

/**
 * Hooks the checklist into the post editor and singular frontend views.
 *
 * @return void
 */
function erankly_seo_checklist_boot(): void {
	if ( ! erankly_seo_checklist_enabled() ) {
		return;
	}

	if ( is_admin() ) {
		add_action( 'admin_enqueue_scripts', 'erankly_seo_checklist_admin_enqueue' );
		add_action( 'admin_footer', 'erankly_seo_checklist_render_admin' );
		return;
	}

	if ( ! erankly_is_frontend_html_request() ) {
		return;
	}

	add_action( 'wp_enqueue_scripts', 'erankly_seo_checklist_frontend_enqueue' );
	add_action( 'wp_footer', 'erankly_seo_checklist_render_frontend' );
}

/**
 * Returns the post being edited when the current admin screen is a post editor.
 *
 * @return WP_Post|null
 */
function erankly_seo_checklist_get_admin_post(): ?WP_Post {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'post' !== $screen->base ) {
		return null;
	}

	if ( ! array_key_exists( $screen->post_type, erankly_get_public_post_types() ) ) {
		return null;
	}

	$post = get_post();

	return $post instanceof WP_Post ? $post : null;
}

/**
 * Returns the queried post when the checklist should render on the frontend.
 *
 * @return WP_Post|null
 */
function erankly_seo_checklist_get_frontend_post(): ?WP_Post {
	if ( ! is_singular() || is_preview() || is_customize_preview() ) {
		return null;
	}

	$post = get_queried_object();

	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	if ( ! array_key_exists( $post->post_type, erankly_get_public_post_types() ) ) {
		return null;
	}

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return null;
	}

	return $post;
}

/**
 * Enqueues checklist assets on post editor screens.
 *
 * @return void
 */
function erankly_seo_checklist_admin_enqueue(): void {
	if ( ! erankly_seo_checklist_get_admin_post() instanceof WP_Post ) {
		return;
	}

	$screen = get_current_screen();

	// The block editor updates items from the core data stores; the classic
	// editor fetches featured image dimensions through the REST API.
	$deps = $screen instanceof WP_Screen && $screen->is_block_editor()
		? array( 'wp-data', 'wp-edit-post' )
		: array( 'wp-api-fetch' );

	erankly_seo_checklist_enqueue_assets( $deps );
}

/**
 * Enqueues checklist assets on singular frontend views.
 *
 * @return void
 */
function erankly_seo_checklist_frontend_enqueue(): void {
	if ( ! erankly_seo_checklist_get_frontend_post() instanceof WP_Post ) {
		return;
	}

	erankly_seo_checklist_enqueue_assets( array() );
}

/**
 * Registers and enqueues the checklist style and script.
 *
 * @param array<int,string> $script_deps Script dependencies.
 * @return void
 */
function erankly_seo_checklist_enqueue_assets( array $script_deps ): void {
	wp_enqueue_style(
		'erankly-seo-checklist',
		ERANKLY_URL . 'assets/css/seo-checklist.css',
		array(),
		ERANKLY_VERSION
	);

	wp_enqueue_script(
		'erankly-seo-checklist',
		ERANKLY_URL . 'assets/js/seo-checklist.js',
		$script_deps,
		ERANKLY_VERSION,
		true
	);
}

/**
 * Renders the checklist in the admin footer of post editor screens.
 *
 * @return void
 */
function erankly_seo_checklist_render_admin(): void {
	$post = erankly_seo_checklist_get_admin_post();

	if ( $post instanceof WP_Post ) {
		erankly_render_seo_checklist( $post );
	}
}

/**
 * Renders the checklist in the frontend footer of singular views.
 *
 * @return void
 */
function erankly_seo_checklist_render_frontend(): void {
	$post = erankly_seo_checklist_get_frontend_post();

	if ( $post instanceof WP_Post ) {
		erankly_render_seo_checklist( $post );
	}
}

/**
 * Returns the checklist items with their completion state.
 *
 * @param int $post_id Post ID.
 * @return array<string,array{label:string,done:bool}>
 */
function erankly_get_seo_checklist_items( int $post_id ): array {
	$thumbnail_done = false;
	$thumbnail_id   = (int) get_post_thumbnail_id( $post_id );

	if ( $thumbnail_id > 0 ) {
		$image          = wp_get_attachment_image_src( $thumbnail_id, 'full' );
		$thumbnail_done = is_array( $image )
			&& (int) $image[1] >= ERANKLY_SEO_CHECKLIST_IMAGE_WIDTH
			&& (int) $image[2] >= ERANKLY_SEO_CHECKLIST_IMAGE_HEIGHT;
	}

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
			'done'  => $thumbnail_done,
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
 * Renders the floating checklist popup.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_seo_checklist( WP_Post $post ): void {
	$items  = erankly_get_seo_checklist_items( $post->ID );
	$status = erankly_get_seo_checklist_status( $items );
	$done   = count( array_filter( wp_list_pluck( $items, 'done' ) ) );
	?>
	<div class="erankly-seo-checklist is-<?php echo esc_attr( $status ); ?>" data-erankly-seo-checklist data-erankly-min-width="<?php echo esc_attr( (string) ERANKLY_SEO_CHECKLIST_IMAGE_WIDTH ); ?>" data-erankly-min-height="<?php echo esc_attr( (string) ERANKLY_SEO_CHECKLIST_IMAGE_HEIGHT ); ?>">
		<div class="erankly-seo-checklist-panel" id="erankly-seo-checklist-panel" data-erankly-seo-checklist-panel hidden>
			<div class="erankly-seo-checklist-header">
				<span class="erankly-seo-checklist-title"><?php esc_html_e( 'SEO checklist', 'easyrankly' ); ?></span>
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
			<a class="erankly-seo-checklist-docs" href="<?php echo esc_url( erankly_seo_checklist_docs_url() ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'How to do it', 'easyrankly' ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
		</div>
		<div class="erankly-seo-checklist-actions">
			<button type="button" class="erankly-seo-checklist-hide" data-erankly-seo-checklist-hide>
				<?php esc_html_e( 'Hide for 10 seconds', 'easyrankly' ); ?>
			</button>
			<button type="button" class="erankly-seo-checklist-toggle" data-erankly-seo-checklist-toggle aria-expanded="false" aria-controls="erankly-seo-checklist-panel" aria-label="<?php esc_attr_e( 'SEO checklist', 'easyrankly' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M3 5.5l1.5 1.5L7 4.5"></path>
					<path d="M3 12l1.5 1.5L7 11"></path>
					<path d="M3 18.5l1.5 1.5L7 17.5"></path>
					<line x1="11" y1="6" x2="21" y2="6"></line>
					<line x1="11" y1="12.5" x2="21" y2="12.5"></line>
					<line x1="11" y1="19" x2="21" y2="19"></line>
				</svg>
			</button>
		</div>
	</div>
	<?php
}
