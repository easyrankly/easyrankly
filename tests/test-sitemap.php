<?php
/** Sitemap integration regressions. */

final class ERankly_Sitemap_Test extends WP_UnitTestCase {

	private string $visibility_mode = '';

	/** @var array<int,array<string,string>> */
	private array $extra_site_urls = array();

	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'erankly_test_doc',
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'has_archive'        => 'erankly-test-docs',
				'rewrite'            => array( 'slug' => 'erankly-test-docs' ),
				'supports'           => array( 'title', 'editor', 'author' ),
			)
		);

		// Every test gets a fresh transient namespace even though PHP statics live
		// for the duration of the full PHPUnit process.
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, wp_rand( 10000, PHP_INT_MAX ), false );
	}

	public function tear_down(): void {
		remove_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_visibility' ), 10 );
		remove_filter( 'erankly_sitemap_site_urls', array( $this, 'filter_site_urls' ), 10 );
		unregister_post_type( 'erankly_test_doc' );

		parent::tear_down();
	}

	public function test_core_filters_remain_registered_when_optional_module_is_off(): void {
		$this->assertFalse( erankly_sitemap_enabled() );
		$this->assertNotFalse( has_filter( 'wp_sitemaps_posts_query_args', 'erankly_filter_core_sitemap_posts_query_args' ) );
		$this->assertNotFalse( has_filter( 'wp_sitemaps_taxonomies_query_args', 'erankly_filter_core_sitemap_terms_query_args' ) );
		$this->assertNotFalse( has_filter( 'wp_sitemaps_users_query_args', 'erankly_filter_core_sitemap_users_query_args' ) );
	}

	public function test_core_post_url_list_is_cached_in_the_current_generation(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'erankly_test_doc',
				'post_status' => 'publish',
			)
		);
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, wp_rand( 10000, PHP_INT_MAX ), false );

		$provider = new WP_Sitemaps_Posts();
		$urls     = $provider->get_url_list( 1, 'erankly_test_doc' );
		$cached   = get_transient( erankly_get_sitemap_cache_key( 'core_posts_erankly_test_doc_1' ) );

		$this->assertNotEmpty( $urls );
		$this->assertIsArray( $cached );
		$this->assertSame( $urls, $cached['urls'] );
	}

	public function test_post_sitemap_excludes_password_noindex_and_non_self_canonical(): void {
		$included = self::factory()->post->create(
			array(
				'post_type'   => 'erankly_test_doc',
				'post_status' => 'publish',
			)
		);
		$password = self::factory()->post->create(
			array(
				'post_type'     => 'erankly_test_doc',
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$noindex = self::factory()->post->create(
			array(
				'post_type'   => 'erankly_test_doc',
				'post_status' => 'publish',
			)
		);
		$canonical = self::factory()->post->create(
			array(
				'post_type'   => 'erankly_test_doc',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $noindex, '_erankly_index_directive', 'noindex' );
		update_post_meta( $canonical, '_erankly_canonical', 'https://canonical.invalid/elsewhere/' );

		$provider = new WP_Sitemaps_Posts();
		$locs     = array_column( $provider->get_url_list( 1, 'erankly_test_doc' ), 'loc' );

		$this->assertContains( get_permalink( $included ), $locs );
		$this->assertNotContains( get_permalink( $password ), $locs );
		$this->assertNotContains( get_permalink( $noindex ), $locs );
		$this->assertNotContains( get_permalink( $canonical ), $locs );
	}

	public function test_explicit_index_overrides_global_noindex_but_not_sitemap_disable(): void {
		$inherited = self::factory()->post->create(
			array(
				'post_type'   => 'erankly_test_doc',
				'post_status' => 'publish',
			)
		);
		$explicit = self::factory()->post->create(
			array(
				'post_type'   => 'erankly_test_doc',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $explicit, '_erankly_index_directive', 'index' );

		$this->visibility_mode = 'noindex';
		add_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_visibility' ), 10, 2 );

		$provider = new WP_Sitemaps_Posts();
		$locs     = array_column( $provider->get_url_list( 1, 'erankly_test_doc' ), 'loc' );

		$this->assertNotContains( get_permalink( $inherited ), $locs );
		$this->assertContains( get_permalink( $explicit ), $locs );

		$this->visibility_mode = 'disable';
		$this->assertSame( array(), $provider->get_url_list( 1, 'erankly_test_doc' ) );
	}

	public function test_noindex_author_is_excluded(): void {
		$visible_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$hidden_author  = self::factory()->user->create( array( 'role' => 'author' ) );

		self::factory()->post->create(
			array(
				'post_author' => $visible_author,
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $hidden_author,
				'post_status' => 'publish',
			)
		);
		update_user_meta( $hidden_author, '_erankly_index_directive', 'noindex' );

		$provider = new WP_Sitemaps_Users();
		$locs     = array_column( $provider->get_url_list( 1 ), 'loc' );

		$this->assertContains( get_author_posts_url( $visible_author ), $locs );
		$this->assertNotContains( get_author_posts_url( $hidden_author ), $locs );
	}

	public function test_term_canonical_template_is_evaluated_for_its_own_term(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
			)
		);
		update_term_meta( $term_id, '_erankly_canonical', '{{term_url}}' );
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, wp_rand( 10000, PHP_INT_MAX ), false );

		$this->assertNotContains( $term_id, erankly_get_non_self_canonical_term_ids( 'category' ) );
	}

	public function test_user_stats_follow_the_current_sitemap_cache_generation(): void {
		$first_user = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_author' => $first_user,
				'post_status' => 'publish',
			)
		);
		$this->assertContains( $first_user, erankly_get_sitemap_user_ids() );

		$second_user = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_author' => $second_user,
				'post_status' => 'publish',
			)
		);
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, wp_rand( 10000, PHP_INT_MAX ), false );

		$this->assertContains( $second_user, erankly_get_sitemap_user_ids() );
	}

	public function test_relative_content_image_is_resolved(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<img src="/wp-content/uploads/example.jpg" alt="">',
			)
		);

		$this->assertContains(
			home_url( '/wp-content/uploads/example.jpg' ),
			erankly_get_post_content_image_urls( $post_id )
		);
	}

	public function test_site_provider_adds_cpt_archive_and_safe_extra_url(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'erankly_test_doc',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		require_once ERANKLY_PATH . 'includes/class-erankly-site-sitemaps-provider.php';

		$this->extra_site_urls = array(
			array( 'loc' => '/extra-landing/' ),
			array( 'loc' => 'https://outside.invalid/not-allowed/' ),
		);
		add_filter( 'erankly_sitemap_site_urls', array( $this, 'filter_site_urls' ), 10, 1 );

		$provider = new ERankly_Site_Sitemaps_Provider();
		$locs     = array_column( $provider->get_url_list( 1 ), 'loc' );

		$this->assertContains( get_post_type_archive_link( 'erankly_test_doc' ), $locs );
		$this->assertContains( home_url( '/extra-landing/' ), $locs );
		$this->assertNotContains( 'https://outside.invalid/not-allowed/', $locs );
	}

	/** @param array<string,mixed> $map */
	public function filter_visibility( array $map, string $setting_key ): array {
		if ( 'global_post_type_meta' !== $setting_key ) {
			return $map;
		}

		$map['erankly_test_doc'] = array(
			'noindex'        => 'noindex' === $this->visibility_mode ? 1 : 0,
			'disable_sitemap' => 'disable' === $this->visibility_mode ? 1 : 0,
		);

		return $map;
	}

	/** @param array<int,array<string,string>> $entries */
	public function filter_site_urls( array $entries ): array {
		return array_merge( $entries, $this->extra_site_urls );
	}
}
