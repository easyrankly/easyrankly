<?php
/**
 * Multilingual module — hreflang resolver.
 *
 * Determines which `<link rel="alternate" hreflang="…">` tags to output for
 * the current page by walking the object's translation group.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves hreflang alternates for the current request.
 */
final class ERankly_ML_Resolver {

	/**
	 * Translation-group repository.
	 *
	 * @var ERankly_ML_Repository
	 */
	private ERankly_ML_Repository $repo;

	/**
	 * When true, noindex translations are included in the resolved alternates.
	 *
	 * The default (false) builds the SEO hreflang set, which must exclude
	 * noindex pages. Visitor-facing consumers (e.g. a browser-language redirect
	 * add-on) resolve through resolve_navigable() instead, because a human
	 * must be able to reach any published translation regardless of its
	 * search-engine visibility.
	 *
	 * @var bool
	 */
	private bool $include_noindex = false;

	/**
	 * Constructor.
	 *
	 * @param ERankly_ML_Repository $repo Repository instance.
	 */
	public function __construct( ERankly_ML_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Returns hreflang alternates for the current WordPress request.
	 *
	 * Hooked to the `erankly_hreflang_alternates` filter with a higher priority
	 * so it replaces the Polylang-only result when the network ML module is active.
	 *
	 * @param array<string,string> $existing Alternates built by earlier providers.
	 * @return array<string,string>
	 */
	public function resolve( array $existing ): array {
		$current_blog = get_current_blog_id();
		$members      = $this->get_current_object_group( $current_blog );

		if ( count( $members ) < 2 ) {
			// No manual translation group for this object: fall back to the automatic
			// slug-based matching. This must not depend on the admin interface mode
			// (simplified_mode), otherwise switching the UI to Advanced would silently
			// drop hreflang output and break add-on browser-language redirects.
			return $this->resolve_simplified( $existing );
		}

		$enabled_sites = ERankly_ML_Sites::get_enabled();
		$default_blog  = ERankly_ML_Sites::get_default_blog_id();
		$alternates    = array();
		$default_url   = '';

		foreach ( $members as $member ) {
			$blog_id     = $member['blog_id'];
			$object_type = $member['object_type'];
			$object_id   = $member['object_id'];

			if ( ! isset( $enabled_sites[ $blog_id ] ) ) {
				continue;
			}

			$url = $this->resolve_url( $blog_id, $object_type, $object_id );

			if ( '' === $url ) {
				continue;
			}

			$hreflang = ERankly_ML_Sites::get_hreflang( $blog_id );

			if ( '' === $hreflang ) {
				continue;
			}

			$alternates[ $hreflang ] = $url;

			if ( $blog_id === $default_blog ) {
				$default_url = $url;
			}
		}

		if ( count( $alternates ) < 2 ) {
			return $existing;
		}

		if ( '' !== $default_url ) {
			$alternates['x-default'] = $default_url;
		}

		return $alternates;
	}

	/**
	 * Returns the alternates for the current request including noindex translations.
	 *
	 * Same resolution as resolve(), but every *published* translation is included
	 * regardless of its `_erankly_noindex` flag. Meant for visitor-facing
	 * navigation (language redirect, switchers), never for hreflang output.
	 *
	 * @param array<string,string> $existing Alternates built by earlier providers.
	 * @return array<string,string>
	 */
	public function resolve_navigable( array $existing = array() ): array {
		$this->include_noindex = true;

		try {
			return $this->resolve( $existing );
		} finally {
			$this->include_noindex = false;
		}
	}

	/**
	 * Returns the translation group for the queried object on the current blog.
	 *
	 * @param int $blog_id Current blog ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_current_object_group( int $blog_id ): array {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( $post_id > 0 ) {
				return $this->repo->get_group_for_object( $blog_id, 'post', $post_id );
			}
		}

		if ( is_front_page() ) {
			return $this->repo->get_group_for_object( $blog_id, 'home', 0 );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				return $this->repo->get_group_for_object( $blog_id, 'term', $term->term_id );
			}
		}

		return array();
	}

	// Simplified automatic mode (slug-based cross-site matching).

	/**
	 * Builds hreflang alternates automatically by matching content slugs across
	 * all enabled sites.
	 *
	 * Used as a fallback when no manual translation group exists. For the front
	 * page every enabled site's home URL is included; for singular posts/pages
	 * the post slug is looked up on each other site; for taxonomy terms the term
	 * slug is matched in the same taxonomy.
	 *
	 * @param array<string,string> $existing Alternates from earlier providers.
	 * @return array<string,string>
	 */
	private function resolve_simplified( array $existing ): array {
		$enabled_sites = ERankly_ML_Sites::get_enabled();
		$current_blog  = get_current_blog_id();

		if ( count( $enabled_sites ) < 2 || ! isset( $enabled_sites[ $current_blog ] ) ) {
			return $existing;
		}

		$default_blog = ERankly_ML_Sites::get_default_blog_id();
		$alternates   = array();
		$default_url  = '';

		if ( is_front_page() ) {
			foreach ( array_keys( $enabled_sites ) as $blog_id ) {
				$hreflang = ERankly_ML_Sites::get_hreflang( $blog_id );
				if ( '' === $hreflang ) {
					continue;
				}
				$url = ( $blog_id === $current_blog )
					? home_url( '/' )
					: $this->get_blog_home_url( $blog_id );
				if ( '' !== $url ) {
					$alternates[ $hreflang ] = $url;
					if ( $blog_id === $default_blog ) {
						$default_url = $url;
					}
				}
			}
		} elseif ( is_singular() ) {
			$post = get_post( get_queried_object_id() );
			if ( ! $post instanceof WP_Post ) {
				return $existing;
			}

			$current_hreflang = ERankly_ML_Sites::get_hreflang( $current_blog );
			if ( '' !== $current_hreflang ) {
				$noindex = (bool) get_post_meta( $post->ID, '_erankly_noindex', true );
				if ( $this->include_noindex || ! $noindex ) {
					$permalink = get_permalink( $post );
					if ( $permalink ) {
						$alternates[ $current_hreflang ] = (string) $permalink;
						if ( $current_blog === $default_blog ) {
							$default_url = (string) $permalink;
						}
					}
				}
			}

			foreach ( array_keys( $enabled_sites ) as $blog_id ) {
				if ( $blog_id === $current_blog ) {
					continue;
				}
				$hreflang = ERankly_ML_Sites::get_hreflang( $blog_id );
				if ( '' === $hreflang ) {
					continue;
				}
				$url = $this->find_same_post_on_blog( $blog_id, $post->post_name, $post->post_type );
				if ( '' !== $url ) {
					$alternates[ $hreflang ] = $url;
					if ( $blog_id === $default_blog ) {
						$default_url = $url;
					}
				}
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return $existing;
			}

			$current_hreflang = ERankly_ML_Sites::get_hreflang( $current_blog );
			$term_link        = get_term_link( $term );
			if ( '' !== $current_hreflang && ! is_wp_error( $term_link ) ) {
				$noindex = (bool) get_term_meta( $term->term_id, '_erankly_noindex', true );
				if ( $this->include_noindex || ! $noindex ) {
					$alternates[ $current_hreflang ] = (string) $term_link;
					if ( $current_blog === $default_blog ) {
						$default_url = (string) $term_link;
					}
				}
			}

			foreach ( array_keys( $enabled_sites ) as $blog_id ) {
				if ( $blog_id === $current_blog ) {
					continue;
				}
				$hreflang = ERankly_ML_Sites::get_hreflang( $blog_id );
				if ( '' === $hreflang ) {
					continue;
				}
				$url = $this->find_same_term_on_blog( $blog_id, $term->slug, $term->taxonomy );
				if ( '' !== $url ) {
					$alternates[ $hreflang ] = $url;
					if ( $blog_id === $default_blog ) {
						$default_url = $url;
					}
				}
			}
		}

		if ( count( $alternates ) < 2 ) {
			return $existing;
		}

		if ( '' !== $default_url ) {
			$alternates['x-default'] = $default_url;
		}

		return $alternates;
	}

	/**
	 * Returns the home URL for a blog, switching context as needed.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string
	 */
	private function get_blog_home_url( int $blog_id ): string {
		switch_to_blog( $blog_id );
		$url = home_url( '/' );
		restore_current_blog();
		return $url;
	}

	/**
	 * Finds a published post matching a slug and post type on another blog.
	 *
	 * Skips noindex posts. Results are cached in the object cache for the
	 * duration of the request to avoid redundant queries per page load.
	 *
	 * @param int    $blog_id   Target blog ID.
	 * @param string $slug      Post slug (post_name).
	 * @param string $post_type Post type.
	 * @return string Permalink, or empty string if no match.
	 */
	private function find_same_post_on_blog( int $blog_id, string $slug, string $post_type ): string {
		// The result depends on the resolution mode: a noindex post yields '' for
		// the SEO set but a URL for the navigable set, so the modes cache apart.
		$mode      = $this->include_noindex ? 'nav' : 'seo';
		$cache_key = 'erml_simp_' . $mode . '_' . $blog_id . '_' . $post_type . '_' . md5( $slug );
		$cached    = wp_cache_get( $cache_key, 'erankly_ml' );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );

		$url = '';

		try {
			$query = new WP_Query(
				array(
					'name'           => $slug,
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'fields'         => 'ids',
				)
			);

			$post_ids = $query->posts;

			if ( ! empty( $post_ids ) ) {
				$post_id = (int) reset( $post_ids );
				$noindex = (bool) get_post_meta( $post_id, '_erankly_noindex', true );
				if ( $this->include_noindex || ! $noindex ) {
					$permalink = get_permalink( $post_id );
					$url       = $permalink ? (string) $permalink : '';
				}
			}
		} finally {
			ERankly_ML_Sites::restore_blog_for_link();
		}

		wp_cache_set( $cache_key, $url, 'erankly_ml', HOUR_IN_SECONDS );

		return $url;
	}

	/**
	 * Finds a term matching a slug in a taxonomy on another blog.
	 *
	 * Skips noindex terms. Results are cached in the object cache.
	 *
	 * @param int    $blog_id  Target blog ID.
	 * @param string $slug     Term slug.
	 * @param string $taxonomy Taxonomy name.
	 * @return string Term URL, or empty string if no match.
	 */
	private function find_same_term_on_blog( int $blog_id, string $slug, string $taxonomy ): string {
		$mode      = $this->include_noindex ? 'nav' : 'seo';
		$cache_key = 'erml_simp_term_' . $mode . '_' . $blog_id . '_' . $taxonomy . '_' . md5( $slug );
		$cached    = wp_cache_get( $cache_key, 'erankly_ml' );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );

		$url = '';

		try {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$noindex = (bool) get_term_meta( $term->term_id, '_erankly_noindex', true );
				if ( $this->include_noindex || ! $noindex ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$url = (string) $link;
					}
				}
			}
		} finally {
			ERankly_ML_Sites::restore_blog_for_link();
		}

		wp_cache_set( $cache_key, $url, 'erankly_ml', HOUR_IN_SECONDS );

		return $url;
	}

	/**
	 * Resolves the public URL for a member object, switching blogs as needed.
	 *
	 * Returns empty string if the target is not publicly accessible (private,
	 * trashed, noindex, or blog not found). In navigable mode (resolve_navigable)
	 * noindex targets are kept; only unpublished ones are excluded.
	 *
	 * @param int    $blog_id     Blog ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string Absolute URL, or empty string on failure.
	 */
	private function resolve_url( int $blog_id, string $object_type, int $object_id ): string {
		$current_blog = get_current_blog_id();
		$is_same_blog = ( $blog_id === $current_blog );

		if ( ! $is_same_blog ) {
			ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );
		}

		$url = '';

		try {
			if ( 'home' === $object_type ) {
				$url = home_url( '/' );
			} elseif ( 'post' === $object_type ) {
				$post = get_post( $object_id );
				if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
					$noindex = (bool) get_post_meta( $post->ID, '_erankly_noindex', true );
					if ( $this->include_noindex || ! $noindex ) {
						$url = (string) get_permalink( $post );
					}
				}
			} elseif ( 'term' === $object_type ) {
				$term = get_term( $object_id );
				if ( $term instanceof WP_Term ) {
					$noindex = (bool) get_term_meta( $term->term_id, '_erankly_noindex', true );
					if ( $this->include_noindex || ! $noindex ) {
						$link = get_term_link( $term );
						if ( ! is_wp_error( $link ) ) {
							$url = $link;
						}
					}
				}
			}
		} finally {
			if ( ! $is_same_blog ) {
				ERankly_ML_Sites::restore_blog_for_link();
			}
		}

		return $url;
	}
}
