<?php
/**
 * Multilingual module — admin UI, REST endpoint, and save handlers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-side integration for the multilingual module.
 */
final class ERankly_ML_Admin {

	/**
	 * Repository instance.
	 *
	 * @var ERankly_ML_Repository
	 */
	private ERankly_ML_Repository $repo;

	/**
	 * Constructor.
	 *
	 * @param ERankly_ML_Repository $repo Repository instance.
	 */
	public function __construct( ERankly_ML_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Registers all admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// REST endpoint for cross-site title search.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Save translation links on post save.
		add_action( 'save_post', array( $this, 'save_post_relations' ), 20, 2 );

		// Save translation links on term save.
		add_action( 'edited_term', array( $this, 'save_term_relations' ), 20, 2 );
		add_action( 'created_term', array( $this, 'save_term_relations' ), 20, 2 );

		// Auto-register newly created network sites in the language map.
		add_action( 'wp_initialize_site', array( $this, 'on_create_site' ), 100 );

		// Cleanup on delete.
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 3 );
		add_action( 'wp_delete_site', array( $this, 'on_delete_site' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'on_delete_site' ) );

		// Network settings save for the sites language map.
		add_action( 'network_admin_edit_erankly_ml_sites_save', array( $this, 'save_ml_sites' ) );
	}

	// REST — cross-site title search.

	/**
	 * Registers the REST search route.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_field(
			array_keys( erankly_get_public_post_types() ),
			'erankly_ml_links',
			array(
				'get_callback'    => array( $this, 'get_post_translations_rest_field' ),
				'update_callback' => array( $this, 'update_post_translations_rest_field' ),
				'schema'          => array(
					'description' => __( 'EasyRankly multilingual post relations.', 'easyrankly' ),
					'type'        => 'array',
					'context'     => array( 'edit' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'blog_id'            => array( 'type' => 'integer' ),
							'site_name'          => array( 'type' => 'string' ),
							'hreflang'           => array( 'type' => 'string' ),
							'object_id'          => array( 'type' => 'integer' ),
							'original_object_id' => array( 'type' => 'integer' ),
							'title'              => array( 'type' => 'string' ),
							'url'                => array( 'type' => 'string' ),
							'action'             => array(
								'type' => 'string',
								'enum' => array( '', 'link', 'unlink' ),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			'erankly/v1',
			'/ml/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_search' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'blog_id'     => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'object_type' => array(
						'default'           => 'post',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'q'           => array(
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Returns translation rows for the block editor post response.
	 *
	 * @param array<string,mixed> $prepared_post Prepared REST post data.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_post_translations_rest_field( array $prepared_post ): array {
		$post_id = isset( $prepared_post['id'] ) ? absint( $prepared_post['id'] ) : 0;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array();
		}

		return $this->get_post_translation_rows( $post );
	}

	/**
	 * Updates translation rows submitted with a block editor post save.
	 *
	 * @param mixed   $value Submitted REST field value.
	 * @param WP_Post $post  Updated post.
	 * @return bool|WP_Error
	 */
	public function update_post_translations_rest_field( mixed $value, WP_Post $post ): bool|WP_Error {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'erankly_forbidden', __( 'You are not allowed to edit these translations.', 'easyrankly' ), array( 'status' => 403 ) );
		}

		if ( ! is_array( $value ) ) {
			return new WP_Error( 'erankly_invalid_translations', __( 'Invalid translation data.', 'easyrankly' ), array( 'status' => 400 ) );
		}

		$source_blog_id = get_current_blog_id();
		$current_rows   = $this->get_post_translation_rows( $post );
		$current_links  = array();
		$allowed_sites  = array();
		$raw            = array();

		foreach ( $current_rows as $row ) {
			$blog_id                   = (int) $row['blog_id'];
			$allowed_sites[ $blog_id ] = true;

			if ( (int) $row['object_id'] > 0 && 'unlink' !== $row['action'] ) {
				$current_links[ $blog_id ] = (int) $row['object_id'];
			}
		}

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$blog_id = isset( $row['blog_id'] ) ? absint( $row['blog_id'] ) : 0;
			$action  = isset( $row['action'] ) ? sanitize_key( (string) $row['action'] ) : '';

			if ( ! isset( $allowed_sites[ $blog_id ] ) || ! in_array( $action, array( 'link', 'unlink' ), true ) ) {
				continue;
			}

			$object_id = 'unlink' === $action
				? ( $current_links[ $blog_id ] ?? 0 )
				: ( isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0 );

			if ( $object_id > 0 ) {
				if ( 'link' === $action && ! $this->is_valid_translation_post( $blog_id, $object_id ) ) {
					continue;
				}

				if ( 'link' === $action && isset( $current_links[ $blog_id ] ) && $current_links[ $blog_id ] !== $object_id ) {
					$this->repo->unlink( $blog_id, 'post', $current_links[ $blog_id ] );
				}

				$raw[ $blog_id ] = array(
					'object_id' => $object_id,
					'action'    => $action,
				);
			}
		}

		$this->process_object_links( $source_blog_id, 'post', $post->ID, $raw );

		return true;
	}

	/**
	 * Checks that a REST-selected translation is a published public post.
	 *
	 * @param int $blog_id   Target site ID.
	 * @param int $object_id Target post ID.
	 * @return bool
	 */
	private function is_valid_translation_post( int $blog_id, int $object_id ): bool {
		$is_valid = false;

		ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );

		try {
			$post = get_post( $object_id );

			$is_valid = $post instanceof WP_Post
				&& current_user_can( 'edit_post', $object_id )
				&& 'publish' === $post->post_status
				&& isset( erankly_get_public_post_types()[ $post->post_type ] );
		} finally {
			ERankly_ML_Sites::restore_blog_for_link();
		}

		return $is_valid;
	}

	/**
	 * Checks that a translation target is a valid public object on its site.
	 *
	 * @param int    $blog_id     Target site ID.
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Target object ID.
	 * @return bool
	 */
	private function is_valid_translation_target( int $blog_id, string $object_type, int $object_id ): bool {
		if ( 'post' === $object_type ) {
			return $this->is_valid_translation_post( $blog_id, $object_id );
		}

		if ( 'term' !== $object_type ) {
			return false;
		}

		$is_valid = false;

		ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );

		try {
			$term = get_term( $object_id );

			$is_valid = $term instanceof WP_Term
				&& current_user_can( 'edit_term', $object_id )
				&& isset( erankly_get_public_taxonomies()[ $term->taxonomy ] );
		} finally {
			ERankly_ML_Sites::restore_blog_for_link();
		}

		return $is_valid;
	}

	/**
	 * Handles the REST search request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rest_search( WP_REST_Request $request ): WP_REST_Response {
		$blog_id     = (int) $request->get_param( 'blog_id' );
		$object_type = (string) $request->get_param( 'object_type' );
		$query       = (string) $request->get_param( 'q' );
		$results     = array();

		if ( $blog_id < 1 || ! is_multisite() ) {
			return new WP_REST_Response( array(), 200 );
		}

		// Only members of the target site (or super admins) may search it, so a
		// user with edit_posts on one site cannot enumerate the whole network.
		if ( ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), $blog_id ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );

		try {
			if ( 'term' === $object_type && current_user_can( 'manage_categories' ) ) {
				$results = $this->search_terms( $query );
			} elseif ( 'post' === $object_type && current_user_can( 'edit_posts' ) ) {
				$results = $this->search_posts( $query );
			}
		} finally {
			ERankly_ML_Sites::restore_blog_for_link();
		}

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Searches for posts by title on the switched blog.
	 *
	 * @param string $query Search query.
	 * @return array<int,array{id:int,title:string,url:string}>
	 */
	private function search_posts( string $query ): array {
		$args = array(
			'post_type'      => array_keys( erankly_get_public_post_types() ),
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'no_found_rows'  => true,
		);

		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		$wp_query = new WP_Query( $args );
		$results  = array();

		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$results[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'url'   => (string) get_permalink( $post ),
			);
		}

		return $results;
	}

	/**
	 * Searches for terms by name on the switched blog.
	 *
	 * @param string $query Search query.
	 * @return array<int,array{id:int,title:string,url:string}>
	 */
	private function search_terms( string $query ): array {
		$args = array(
			'taxonomy'   => array_keys( erankly_get_public_taxonomies() ),
			'number'     => 10,
			'hide_empty' => false,
		);

		if ( '' !== $query ) {
			$args['search'] = $query;
		}

		$terms   = get_terms( $args );
		$results = array();

		if ( is_wp_error( $terms ) ) {
			return $results;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$link      = get_term_link( $term );
			$results[] = array(
				'id'    => $term->term_id,
				'title' => $term->name,
				'url'   => ! is_wp_error( $link ) ? $link : '',
			);
		}

		return $results;
	}

	// Post / term save handlers.

	/**
	 * Saves translation relations submitted with a post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_post_relations( int $post_id, WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $post required by save_post hook signature.
		if ( ! isset( $_POST['erankly_meta_box_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['erankly_meta_box_nonce'] ) ), 'erankly_save_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['erankly_ml_links'] )
			? map_deep( wp_unslash( (array) $_POST['erankly_ml_links'] ), 'sanitize_text_field' )
			: array();
		$this->process_object_links( get_current_blog_id(), 'post', $post_id, $raw );
	}

	/**
	 * Saves translation relations submitted with a term.
	 *
	 * @param int $term_id  Term ID.
	 * @param int $tt_id    Term taxonomy ID (unused).
	 * @return void
	 */
	public function save_term_relations( int $term_id, int $tt_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $tt_id required by hook signature.
		if ( ! isset( $_POST['erankly_term_fields_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['erankly_term_fields_nonce'] ) ), 'erankly_save_term_fields' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$raw = isset( $_POST['erankly_ml_links'] )
			? map_deep( wp_unslash( (array) $_POST['erankly_ml_links'] ), 'sanitize_text_field' )
			: array();
		$this->process_object_links( get_current_blog_id(), 'term', $term_id, $raw );
	}

	/**
	 * Processes the submitted translation links and persists them.
	 *
	 * The `$raw` array has the shape:
	 *   [ blog_id => [ 'object_id' => int, 'action' => 'link'|'unlink' ] ]
	 *
	 * @param int                               $source_blog_id  Blog of the object being edited.
	 * @param string                            $object_type     'post' or 'term'.
	 * @param int                               $source_object_id Object ID being edited.
	 * @param array<string,array<string,mixed>> $raw     Raw POST links data.
	 * @return void
	 */
	private function process_object_links( int $source_blog_id, string $object_type, int $source_object_id, array $raw ): void {
		if ( ! in_array( $object_type, array( 'post', 'term' ), true ) ) {
			return;
		}

		// Ensure the source object is registered in a group.
		$group_id      = $this->repo->find_group_id( $source_blog_id, $object_type, $source_object_id );
		$allowed_sites = array_fill_keys( $this->get_translation_target_blog_ids( $source_blog_id ), true );
		$current_group = $this->repo->get_group_for_object( $source_blog_id, $object_type, $source_object_id );
		$current_links = array();

		foreach ( $current_group as $member ) {
			$member_blog_id = isset( $member['blog_id'] ) ? absint( $member['blog_id'] ) : 0;
			$member_object  = isset( $member['object_id'] ) ? absint( $member['object_id'] ) : 0;

			if ( $member_blog_id > 0 && $member_blog_id !== $source_blog_id && $member_object > 0 ) {
				$current_links[ $member_blog_id ] = $member_object;
			}
		}

		foreach ( $raw as $blog_id => $link_data ) {
			$blog_id = absint( $blog_id );

			if ( $blog_id < 1 || ! isset( $allowed_sites[ $blog_id ] ) || ! is_array( $link_data ) ) {
				continue;
			}

			$object_id = isset( $link_data['object_id'] ) ? absint( $link_data['object_id'] ) : 0;
			$action    = isset( $link_data['action'] ) ? sanitize_key( (string) $link_data['action'] ) : '';

			if ( 'unlink' === $action ) {
				if ( isset( $current_links[ $blog_id ] ) ) {
					$this->repo->unlink( $blog_id, $object_type, $current_links[ $blog_id ] );
				}
				continue;
			}

			if ( 'link' === $action && $object_id > 0 ) {
				// Validate the target on its own site (mirrors the REST path):
				// the classic-editor form must not link unpublished or
				// non-public objects on other network sites.
				if ( ! $this->is_valid_translation_target( $blog_id, $object_type, $object_id ) ) {
					continue;
				}

				// First link creates the source group if not yet registered.
				if ( 0 === $group_id ) {
					$group_id = $this->repo->link( 0, $source_blog_id, $object_type, $source_object_id );
				}

				$this->repo->link( $group_id, $blog_id, $object_type, $object_id );
			}
		}
	}

	// Cleanup hooks.

	/**
	 * Removes relations for a deleted post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_delete_post( int $post_id ): void {
		$this->repo->unlink( get_current_blog_id(), 'post', $post_id );
	}

	/**
	 * Removes relations for a deleted term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Unused.
	 * @param string $taxonomy Unused.
	 * @return void
	 */
	public function on_delete_term( int $term_id, int $tt_id, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		$this->repo->unlink( get_current_blog_id(), 'term', $term_id );
	}

	/**
	 * Registers a newly created network site in the language map.
	 *
	 * When Simplified mode is on (the default) the site is enabled immediately so
	 * its sitemap is exposed in robots.txt without manual setup; otherwise it is
	 * registered but left disabled so a network admin keeps explicit control.
	 *
	 * @param WP_Site $site New site object.
	 * @return void
	 */
	public function on_create_site( WP_Site $site ): void {
		$enabled = (bool) erankly_get_setting( 'simplified_mode', 1 );
		ERankly_ML_Sites::add_site( (int) $site->blog_id, $enabled );
	}

	/**
	 * Removes all relations for a deleted site.
	 *
	 * @param WP_Site $site Site object.
	 * @return void
	 */
	public function on_delete_site( WP_Site $site ): void {
		$this->repo->delete_blog( (int) $site->blog_id );
	}

	// Network settings — language map.

	/**
	 * Saves the site-language map submitted from the network settings page.
	 *
	 * @return void
	 */
	public function save_ml_sites(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
		}

		check_admin_referer( 'erankly_ml_sites_save' );

		$raw = isset( $_POST['erankly_ml_sites'] )
			? map_deep( wp_unslash( (array) $_POST['erankly_ml_sites'] ), 'sanitize_text_field' )
			: array();
		ERankly_ML_Sites::save( $raw );
		set_site_transient(
			'erankly_settings_saved_' . get_current_user_id(),
			1,
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( network_admin_url( 'settings.php?page=erankly&erankly_tab=multilingual' ) );
		exit;
	}

	// Meta box panel rendering.

	/**
	 * Renders the Translations tab panel for a post meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_post_translations_panel( WP_Post $post ): void {
		$blog_id = get_current_blog_id();
		$group   = $this->repo->get_group_for_object( $blog_id, 'post', $post->ID );
		$linked  = array();

		foreach ( $group as $member ) {
			if ( (int) $member['blog_id'] !== $blog_id ) {
				$linked[ (int) $member['blog_id'] ] = (int) $member['object_id'];
			}
		}

		$this->render_translations_panel( $blog_id, 'post', $linked );
	}

	/**
	 * Builds the block editor translation rows for a post.
	 *
	 * @param WP_Post $post Current post.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_post_translation_rows( WP_Post $post ): array {
		$source_blog_id = get_current_blog_id();
		$group          = $this->repo->get_group_for_object( $source_blog_id, 'post', $post->ID );
		$linked         = array();
		$rows           = array();

		foreach ( $group as $member ) {
			if ( (int) $member['blog_id'] !== $source_blog_id ) {
				$linked[ (int) $member['blog_id'] ] = (int) $member['object_id'];
			}
		}

		foreach ( $this->get_translation_target_blog_ids( $source_blog_id ) as $blog_id ) {
			$object_id = $linked[ $blog_id ] ?? 0;
			$title     = '';
			$url       = '';

			if ( $object_id > 0 ) {
				ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );
				$linked_post = get_post( $object_id );

				if ( $linked_post instanceof WP_Post ) {
					$title = get_the_title( $linked_post );
					$url   = (string) get_permalink( $linked_post );
				}

				ERankly_ML_Sites::restore_blog_for_link();
			}

			$rows[] = array(
				'blog_id'            => $blog_id,
				'site_name'          => (string) get_blog_option( $blog_id, 'blogname' ),
				'hreflang'           => ERankly_ML_Sites::get_hreflang( $blog_id ),
				'object_id'          => $object_id,
				'original_object_id' => $object_id,
				'title'              => $title,
				'url'                => $url,
				'action'             => '',
			);
		}

		return $rows;
	}

	/**
	 * Returns enabled translation target site IDs.
	 *
	 * @param int $source_blog_id Current site ID.
	 * @return array<int,int>
	 */
	private function get_translation_target_blog_ids( int $source_blog_id ): array {
		$enabled_sites = ERankly_ML_Sites::get_enabled();
		$sites         = get_sites( array( 'number' => 200 ) );
		$targets       = array();

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;

			if ( $blog_id !== $source_blog_id && isset( $enabled_sites[ $blog_id ] ) ) {
				$targets[] = $blog_id;
			}
		}

		return $targets;
	}

	/**
	 * Renders the Translations tab panel for a term edit screen.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function render_term_translations_panel( int $term_id, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $taxonomy available for extensibility.
		$blog_id = get_current_blog_id();
		$group   = $this->repo->get_group_for_object( $blog_id, 'term', $term_id );
		$linked  = array();

		foreach ( $group as $member ) {
			if ( (int) $member['blog_id'] !== $blog_id ) {
				$linked[ (int) $member['blog_id'] ] = (int) $member['object_id'];
			}
		}

		$this->render_translations_panel( $blog_id, 'term', $linked );
	}

	/**
	 * Shared translations panel markup.
	 *
	 * @param int            $source_blog_id Blog ID of the current site.
	 * @param string         $object_type    'post' or 'term'.
	 * @param array<int,int> $linked         Already-linked: [ blog_id => object_id ].
	 * @return void
	 */
	private function render_translations_panel( int $source_blog_id, string $object_type, array $linked ): void {
		$targets = $this->get_translation_target_blog_ids( $source_blog_id );

		if ( empty( $targets ) ) {
			$this->render_translations_empty_state();
			return;
		}
		?>
		<div class="erankly-ml-translations">
			<p class="erankly-ml-intro description">
				<?php esc_html_e( 'Link this content to its equivalent on other sites in the network. Linked items are exposed to search engines as alternate language versions.', 'easyrankly' ); ?>
			</p>
			<?php
			foreach ( $targets as $blog_id ) :
				$hreflang     = ERankly_ML_Sites::get_hreflang( $blog_id );
				$site_name    = (string) get_blog_option( $blog_id, 'blogname' );
				$object_id    = $linked[ $blog_id ] ?? 0;
				$linked_title = '';
				$linked_url   = '';

				if ( $object_id > 0 ) {
					ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );
					if ( 'term' === $object_type ) {
						$t = get_term( $object_id );
						if ( $t instanceof WP_Term ) {
							$linked_title = $t->name;
							$link         = get_term_link( $t );
							$linked_url   = ! is_wp_error( $link ) ? $link : '';
						}
					} else {
						$p = get_post( $object_id );
						if ( $p instanceof WP_Post ) {
							$linked_title = get_the_title( $p );
							$linked_url   = (string) get_permalink( $p );
						}
					}
					ERankly_ML_Sites::restore_blog_for_link();
				}

				$is_linked    = ( $object_id > 0 && '' !== $linked_title );
				$id_field     = 'erankly_ml_links[' . $blog_id . '][object_id]';
				$action_field = 'erankly_ml_links[' . $blog_id . '][action]';
				?>
				<div class="erankly-ml-field"
					data-erankly-ml-site="<?php echo esc_attr( (string) $blog_id ); ?>"
					data-erankly-ml-type="<?php echo esc_attr( $object_type ); ?>">
					<label class="erankly-ml-label"><strong><?php echo esc_html( $site_name . ' – ' . strtoupper( $hreflang ) ); ?></strong></label>
					<div class="erankly-autocomplete-control erankly-ml-control">
						<div class="erankly-autocomplete-value erankly-ml-linked" data-erankly-ml-linked <?php echo $is_linked ? '' : 'hidden'; ?>>
							<input type="text" class="widefat erankly-ml-linked-input" data-erankly-ml-linked-input value="<?php echo esc_attr( $linked_url ); ?>" readonly>
						</div>

						<div class="erankly-autocomplete-search erankly-ml-search" data-erankly-ml-search <?php echo $is_linked ? 'hidden' : ''; ?>>
							<input type="text"
								class="widefat erankly-ml-search-input"
								placeholder="<?php esc_attr_e( 'Search posts, pages, or terms…', 'easyrankly' ); ?>"
								data-erankly-ml-blog="<?php echo esc_attr( (string) $blog_id ); ?>"
								data-erankly-ml-type="<?php echo esc_attr( $object_type ); ?>"
								autocomplete="off"
								role="combobox"
								aria-expanded="false"
								aria-autocomplete="list">
							<ul class="erankly-autocomplete-results erankly-ml-results" role="listbox" hidden></ul>
						</div>

						<button type="button" class="button erankly-ml-unlink" data-erankly-ml-unlink>
							<?php esc_html_e( 'Remove', 'easyrankly' ); ?>
						</button>
					</div>
					<input type="hidden" name="<?php echo esc_attr( $id_field ); ?>" value="<?php echo esc_attr( (string) ( $is_linked ? $object_id : 0 ) ); ?>" class="erankly-ml-id-input">
					<input type="hidden" name="<?php echo esc_attr( $action_field ); ?>" value="<?php echo $is_linked ? 'link' : ''; ?>" class="erankly-ml-action-input">
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders the empty state shown when no other site is enabled for hreflang.
	 *
	 * @return void
	 */
	private function render_translations_empty_state(): void {
		?>
		<div class="erankly-ml-translations">
			<div class="notice notice-info inline erankly-ml-empty">
				<p><strong><?php esc_html_e( 'No translatable sites yet', 'easyrankly' ); ?></strong></p>
				<p class="description">
					<?php
					if ( current_user_can( 'manage_network_options' ) ) {
						printf(
							/* translators: %s: opening and closing anchor tags for the Network Admin settings link. */
							esc_html__( 'Enable one or more sites in %1$sNetwork Admin → Settings → EasyRankly → Multilingual%2$s, then return here to link translations.', 'easyrankly' ),
							'<a href="' . esc_url( network_admin_url( 'settings.php?page=erankly&erankly_tab=multilingual' ) ) . '">',
							'</a>'
						);
					} else {
						esc_html_e( 'Ask a network administrator to enable sites in the EasyRankly network settings, then return here to link translations.', 'easyrankly' );
					}
					?>
				</p>
			</div>
		</div>
		<?php
	}

	// Network settings panel rendering.

	/**
	 * Renders the Multilingual panel for the network settings page.
	 *
	 * @return void
	 */
	public function render_network_panel(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$site_map = ERankly_ML_Sites::get_all();
		$sites    = get_sites( array( 'number' => 200 ) );
		?>
		<div class="erankly-settings-fields erankly-ml-network">
			<p class="description erankly-ml-network-intro">
				<?php esc_html_e( 'Assign a language to each site, choose which sites take part in multilingual output, and pick the default (x-default) site search engines should fall back to. Codes are BCP-47 (for example it, en; add a region like en-US only to target a specific country).', 'easyrankly' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=erankly_ml_sites_save' ) ); ?>" class="erankly-ml-sites-form">
				<?php wp_nonce_field( 'erankly_ml_sites_save' ); ?>

				<table class="widefat striped erankly-ml-sites-table">
					<thead>
						<tr>
							<th scope="col" class="erankly-ml-col-site"><?php esc_html_e( 'Site', 'easyrankly' ); ?></th>
							<th scope="col" class="erankly-ml-col-code"><?php esc_html_e( 'Language code', 'easyrankly' ); ?></th>
							<th scope="col" class="erankly-ml-col-toggle"><?php esc_html_e( 'Enabled', 'easyrankly' ); ?></th>
							<th scope="col" class="erankly-ml-col-toggle"><?php esc_html_e( 'Default', 'easyrankly' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $sites as $site ) :
						$bid      = (int) $site->blog_id;
						$data     = isset( $site_map[ $bid ] ) && is_array( $site_map[ $bid ] ) ? $site_map[ $bid ] : array();
						$derived  = ERankly_ML_Sites::locale_to_hreflang( (string) get_blog_option( $bid, 'WPLANG', '' ) );
						$override = isset( $data['hreflang'] ) ? (string) $data['hreflang'] : '';
						$enabled  = ! empty( $data['enabled'] );
						$is_def   = ! empty( $data['is_default'] );
						$name     = (string) get_blog_option( $bid, 'blogname' );
						$field    = 'erankly_ml_sites[' . $bid . ']';
						?>
						<tr>
							<td class="erankly-ml-col-site">
								<span class="erankly-ml-site-title"><?php echo esc_html( $name ); ?></span>
								<span class="erankly-ml-site-host"><?php echo esc_html( $site->domain . $site->path ); ?></span>
							</td>
							<td class="erankly-ml-col-code">
								<input type="text"
									name="<?php echo esc_attr( $field ); ?>[hreflang]"
									value="<?php echo esc_attr( $override ); ?>"
									placeholder="<?php echo esc_attr( $derived ); ?>"
									maxlength="20"
									class="small-text erankly-ml-code-input"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: site name. */ __( 'Language code for %s', 'easyrankly' ), $name ) ); ?>">
								<span class="erankly-ml-derived" title="<?php esc_attr_e( 'Code derived from the site locale (used when left blank).', 'easyrankly' ); ?>">
									<?php esc_html_e( 'auto:', 'easyrankly' ); ?> <code><?php echo esc_html( $derived ); ?></code>
								</span>
							</td>
							<td class="erankly-ml-col-toggle">
								<label class="erankly-ml-toggle">
									<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( $enabled ); ?>>
									<span class="screen-reader-text"><?php esc_html_e( 'Enabled', 'easyrankly' ); ?></span>
								</label>
							</td>
							<td class="erankly-ml-col-toggle">
								<label class="erankly-ml-toggle">
									<input type="radio" name="erankly_ml_default_site" value="<?php echo esc_attr( (string) $bid ); ?>" <?php checked( $is_def ); ?>>
									<span class="screen-reader-text"><?php esc_html_e( 'Default (x-default)', 'easyrankly' ); ?></span>
								</label>
								<input type="hidden"
									name="<?php echo esc_attr( $field ); ?>[is_default]"
									value="<?php echo $is_def ? '1' : '0'; ?>"
									class="erankly-ml-is-default-hidden"
									id="erankly-ml-is-default-<?php echo esc_attr( (string) $bid ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<div class="erankly-ml-notice-texts">
					<h3><?php esc_html_e( 'Translation Notice texts', 'easyrankly' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Write the text of the [erankly_translation_notice] banner once per language. A visitor reading an article in another language sees the banner only when a version exists in their own language, written in their own language with the text from the matching site below. Use the {language} token to insert the native language name (e.g. "Italiano"). Leave a site blank to never show the banner for that language.', 'easyrankly' ); ?>
					</p>

					<div class="erankly-default-tabs erankly-ml-notice-tabs" data-erankly-tabs-root>
						<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="erankly-ml-notice-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Translation Notice texts', 'easyrankly' ); ?>">
							<?php
							$is_first = true;
							foreach ( $sites as $site ) :
								$bid           = (int) $site->blog_id;
								$name          = (string) get_blog_option( $bid, 'blogname' );
								$hreflang      = ERankly_ML_Sites::get_hreflang( $bid );
								$tab_key       = sanitize_key( 'ml-notice-' . $bid );
								$tab_id        = 'erankly-' . $tab_key . '-tab';
								$panel_id      = 'erankly-' . $tab_key . '-panel';
								$is_tab_active = $is_first;
								?>
								<button type="button" class="nav-tab erankly-tab <?php echo $is_tab_active ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_tab_active ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>">
									<?php echo esc_html( $name . ' – ' . strtoupper( $hreflang ) ); ?>
								</button>
								<?php
								$is_first = false;
							endforeach;
							?>
						</div>

					<?php
					$is_first = true;
					foreach ( $sites as $site ) :
						$bid      = (int) $site->blog_id;
						$data     = isset( $site_map[ $bid ] ) && is_array( $site_map[ $bid ] ) ? $site_map[ $bid ] : array();
						$name     = (string) get_blog_option( $bid, 'blogname' );
						$hreflang = ERankly_ML_Sites::get_hreflang( $bid );
						$n_def    = ERankly_ML_Sites::default_notice( $hreflang );
						$n_title  = isset( $data['notice_title'] ) && '' !== $data['notice_title'] ? (string) $data['notice_title'] : $n_def['title'];
						$n_text   = isset( $data['notice_text'] ) && '' !== $data['notice_text'] ? (string) $data['notice_text'] : $n_def['text'];
						$n_link   = isset( $data['notice_link'] ) && '' !== $data['notice_link'] ? (string) $data['notice_link'] : $n_def['link'];
						$field    = 'erankly_ml_sites[' . $bid . ']';
						$tab_key  = sanitize_key( 'ml-notice-' . $bid );
						$tab_id   = 'erankly-' . $tab_key . '-tab';
						$panel_id = 'erankly-' . $tab_key . '-panel';
						$title_id = 'erankly-ml-notice-title-' . $bid;
						$text_id  = 'erankly-ml-notice-text-' . $bid;
						$link_id  = 'erankly-ml-notice-link-' . $bid;
						?>
						<div class="erankly-tab-panel erankly-default-tab-panel erankly-ml-notice-site <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $tab_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
							<h4><?php echo esc_html( $name . ' – ' . strtoupper( $hreflang ) ); ?></h4>
							<div class="erankly-field">
								<label for="<?php echo esc_attr( $title_id ); ?>">
									<strong><?php esc_html_e( 'Title', 'easyrankly' ); ?></strong>
								</label>
								<input id="<?php echo esc_attr( $title_id ); ?>"
									type="text"
									class="widefat"
									name="<?php echo esc_attr( $field ); ?>[notice_title]"
									value="<?php echo esc_attr( $n_title ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. This article is also available in your language', 'easyrankly' ); ?>">
							</div>
							<div class="erankly-field">
								<label for="<?php echo esc_attr( $text_id ); ?>">
									<strong><?php esc_html_e( 'Text', 'easyrankly' ); ?></strong>
								</label>
								<input id="<?php echo esc_attr( $text_id ); ?>"
									type="text"
									class="widefat"
									name="<?php echo esc_attr( $field ); ?>[notice_text]"
									value="<?php echo esc_attr( $n_text ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Read this content in {language}.', 'easyrankly' ); ?>">
							</div>
							<div class="erankly-field">
								<label for="<?php echo esc_attr( $link_id ); ?>">
									<strong><?php esc_html_e( 'Link label', 'easyrankly' ); ?></strong>
								</label>
								<input id="<?php echo esc_attr( $link_id ); ?>"
									type="text"
									class="widefat"
									name="<?php echo esc_attr( $field ); ?>[notice_link]"
									value="<?php echo esc_attr( $n_link ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Read the {language} version', 'easyrankly' ); ?>">
							</div>
						</div>
						<?php $is_first = false; ?>
					<?php endforeach; ?>
					</div><!-- .erankly-ml-notice-tabs -->
				</div><!-- .erankly-ml-notice-texts -->

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'easyrankly' ); ?></button>
				</p>
			</form>

			<div class="erankly-ml-shortcodes-docs" style="margin-top:2rem;">
				<h3><?php esc_html_e( 'Frontend Shortcodes', 'easyrankly' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Drop either shortcode into your single-post templates or post content to give readers a language-switching UI.', 'easyrankly' ); ?>
				</p>

				<h4><?php esc_html_e( '1. Language Switcher', 'easyrankly' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Renders a <select> listing all linked translation languages. Selecting one navigates the visitor to that version of the article.', 'easyrankly' ); ?>
				</p>
				<p><code><?php echo esc_html( '[erankly_language_switcher]' ); ?></code></p>
				<table class="widefat striped" style="max-width:640px;margin-top:.5rem;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Attribute', 'easyrankly' ); ?></th>
							<th><?php esc_html_e( 'Default', 'easyrankly' ); ?></th>
							<th><?php esc_html_e( 'Description', 'easyrankly' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>class</code></td>
							<td><em><?php esc_html_e( 'none', 'easyrankly' ); ?></em></td>
							<td><?php esc_html_e( 'Extra CSS class(es) added to the <form> wrapper.', 'easyrankly' ); ?></td>
						</tr>
						<tr>
							<td><code>label</code></td>
							<td><code><?php esc_html_e( 'Choose a language', 'easyrankly' ); ?></code></td>
							<td><?php esc_html_e( 'Accessible (screen-reader) label for the <select>. Visible to assistive technology only.', 'easyrankly' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p class="description" style="margin-top:.5rem;">
					<?php esc_html_e( 'Example:', 'easyrankly' ); ?>
					<code><?php echo esc_html( '[erankly_language_switcher class="my-switcher" label="Select language"]' ); ?></code>
				</p>

				<h4 style="margin-top:1.5rem;"><?php esc_html_e( '2. Translation Notice', 'easyrankly' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Renders a dismissible notice <div>. It stays hidden server-side and is revealed by JavaScript only when the visitor\'s browser language matches an available translation, then displays the text configured for that language in the "Translation Notice texts" section above. When no version exists in the reader\'s language the card stays completely invisible. Dismissals are remembered in localStorage.', 'easyrankly' ); ?>
				</p>
				<p><code><?php echo esc_html( '[erankly_translation_notice]' ); ?></code></p>
				<p class="description">
					<?php esc_html_e( 'The notice texts are no longer passed as attributes: they are managed globally per language in the section above, so the banner always appears in the reader\'s own language. Only presentation attributes remain:', 'easyrankly' ); ?>
				</p>
				<table class="widefat striped" style="max-width:640px;margin-top:.5rem;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Attribute', 'easyrankly' ); ?></th>
							<th><?php esc_html_e( 'Default', 'easyrankly' ); ?></th>
							<th><?php esc_html_e( 'Description', 'easyrankly' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>title_tag</code></td>
							<td><code>h6</code></td>
							<td><?php esc_html_e( 'HTML tag for the heading. Allowed: h1 h2 h3 h4 h5 h6 p span div.', 'easyrankly' ); ?></td>
						</tr>
						<tr>
							<td><code>text_tag</code></td>
							<td><code>p</code></td>
							<td><?php esc_html_e( 'HTML tag for the paragraph. Allowed: p span div.', 'easyrankly' ); ?></td>
						</tr>
						<tr>
							<td><code>class</code></td>
							<td><em><?php esc_html_e( 'none', 'easyrankly' ); ?></em></td>
							<td><?php esc_html_e( 'Extra CSS class(es) added to the <div> wrapper.', 'easyrankly' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p class="description" style="margin-top:.5rem;">
					<?php
					esc_html_e( 'The {language} token (inside the global texts) is replaced client-side with the matched translation\'s native language name (e.g. "Italiano"). Example:', 'easyrankly' );
					?>
				</p>
				<p>
					<code><?php echo esc_html( '[erankly_translation_notice title_tag="h3" class="my-notice"]' ); ?></code>
				</p>
			</div><!-- .erankly-ml-shortcodes-docs -->
		</div>
		<?php
	}
}
