<?php
/**
 * Neutral per-object SEO eligibility contract for multilingual providers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a stable empty SEO-state payload.
 *
 * @return array{exists:bool,published:bool,public:bool,indexable:bool,canonical_url:string,canonical_is_self:bool,reason_codes:array<int,string>}
 */
function erankly_empty_object_seo_state(): array {
	return array(
		'exists'            => false,
		'published'         => false,
		'public'            => false,
		'indexable'         => false,
		'canonical_url'     => '',
		'canonical_is_self' => false,
		'reason_codes'      => array(),
	);
}

/**
 * Normalizes a URL for self-canonical comparison.
 *
 * @param string $url URL.
 * @return string
 */
function erankly_normalize_canonical_comparison_url( string $url ): string {
	$url = esc_url_raw( trim( $url ) );

	if ( ! erankly_is_absolute_http_url( $url ) ) {
		return '';
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return '';
	}

	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
	$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
	$path   = (string) ( $parts['path'] ?? '/' );
	$path   = '/' === $path ? '/' : untrailingslashit( $path );
	$query  = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

	return $scheme . '://' . $host . $port . $path . $query;
}

/**
 * Resolves effective noindex state without mutating the global main query.
 *
 * @param string $kind       Object kind.
 * @param int    $object_id  Object ID.
 * @param string $subtype    Post type, taxonomy, or special-page key.
 * @return bool
 */
function erankly_object_seo_state_is_noindex( string $kind, int $object_id, string $subtype ): bool {
	if ( in_array( $kind, array( 'post', 'posts_page' ), true ) ) {
		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post ) {
			return true;
		}

		$directive = erankly_get_object_robots_directive( 'post', $object_id, 'index' );
		if ( 'index' === $directive ) {
			return false;
		}
		if ( 'noindex' === $directive ) {
			return true;
		}

		return erankly_get_global_post_type_directive( $post->post_type, 'noindex' );
	}

	if ( 'term' === $kind ) {
		$directive = erankly_get_object_robots_directive( 'term', $object_id, 'index' );
		if ( 'index' === $directive ) {
			return false;
		}
		if ( 'noindex' === $directive ) {
			return true;
		}

		return '' !== $subtype && erankly_get_global_taxonomy_directive( $subtype, 'noindex' );
	}

	if ( 'archive' === $kind ) {
		if ( '' !== $subtype && post_type_exists( $subtype ) ) {
			return erankly_get_global_post_type_directive( $subtype, 'noindex' );
		}
		if ( '' !== $subtype && taxonomy_exists( $subtype ) ) {
			return erankly_get_global_taxonomy_directive( $subtype, 'noindex' );
		}

		return '' !== $subtype && erankly_get_global_entity_directive( 'global_special_meta', $subtype, 'noindex' );
	}

	$special_key = 'home' === $kind ? 'homepage' : ( 'posts_page' === $kind ? 'blog' : $subtype );

	return '' !== $special_key && erankly_get_global_entity_directive( 'global_special_meta', $special_key, 'noindex' );
}

/**
 * Returns publication, visibility, indexability and canonical state.
 *
 * Required input keys are kind, object_id, object_subtype, blog_id and url.
 * Stable reason codes are: invalid_context, inaccessible_blog, not_found,
 * not_published, not_public, noindex, canonical_missing and
 * canonical_not_self.
 *
 * @param array<string,mixed> $context Provider object context.
 * @return array{exists:bool,published:bool,public:bool,indexable:bool,canonical_url:string,canonical_is_self:bool,reason_codes:array<int,string>}
 */
function erankly_get_object_seo_state( array $context ): array {
	$state         = erankly_empty_object_seo_state();
	$required      = array( 'kind', 'object_id', 'object_subtype', 'blog_id', 'url' );
	$allowed_kinds = array( 'post', 'term', 'home', 'posts_page', 'archive', 'other' );

	if ( array_diff( $required, array_keys( $context ) ) ) {
		$state['reason_codes'][] = 'invalid_context';

		return $state;
	}

	$kind      = sanitize_key( (string) $context['kind'] );
	$object_id = max( 0, (int) $context['object_id'] );
	$subtype   = sanitize_key( (string) $context['object_subtype'] );
	$blog_id   = max( 0, (int) $context['blog_id'] );
	$self_url  = esc_url_raw( (string) $context['url'] );

	if ( ! in_array( $kind, $allowed_kinds, true ) || $blog_id < 1 || ! erankly_is_absolute_http_url( $self_url ) ) {
		$state['reason_codes'][] = 'invalid_context';

		return $state;
	}

	$switched = false;
	if ( get_current_blog_id() !== $blog_id ) {
		if ( ! is_multisite() || ! get_site( $blog_id ) || ! switch_to_blog( $blog_id ) ) {
			$state['reason_codes'][] = 'inaccessible_blog';

			return $state;
		}
		$switched = true;
	}

	try {
		erankly_load_content_helpers();
		$canonical = '';

		if ( in_array( $kind, array( 'post', 'posts_page' ), true ) ) {
			$post = get_post( $object_id );
			if ( $post instanceof WP_Post && ( '' === $subtype || $subtype === $post->post_type ) ) {
				$state['exists']    = true;
				$state['published'] = 'publish' === $post->post_status;
				$state['public']    = $state['published']
					&& '' === (string) $post->post_password
					&& ( function_exists( 'is_post_publicly_viewable' ) ? is_post_publicly_viewable( $post ) : is_post_type_viewable( $post->post_type ) );

				$canonical = erankly_get_post_meta_string( $object_id, 'canonical' );
				if ( '' !== $canonical ) {
					$canonical = erankly_replace_variables( $canonical, $object_id, array( 'canonical_url' ) );
				} else {
					$wp_canonical = wp_get_canonical_url( $object_id );
					$canonical    = is_string( $wp_canonical ) ? $wp_canonical : (string) get_permalink( $object_id );
				}
			}
		} elseif ( 'term' === $kind ) {
			$term = get_term( $object_id, '' !== $subtype ? $subtype : '' );
			if ( $term instanceof WP_Term && ( '' === $subtype || $subtype === $term->taxonomy ) ) {
				$taxonomy           = get_taxonomy( $term->taxonomy );
				$state['exists']    = true;
				$state['published'] = true;
				$state['public']    = $taxonomy instanceof WP_Taxonomy && is_taxonomy_viewable( $taxonomy );
				$canonical          = erankly_get_term_meta_string( $term->term_id, 'canonical' );
				if ( '' !== $canonical ) {
					$canonical = erankly_replace_variables( $canonical, 0, array( 'canonical_url' ) );
				} else {
					$link      = get_term_link( $term );
					$canonical = is_wp_error( $link ) ? '' : (string) $link;
				}
			}
		} elseif ( 'archive' === $kind ) {
			$state['exists']    = '' === $subtype || post_type_exists( $subtype ) || taxonomy_exists( $subtype ) || in_array( $subtype, array( 'author', 'date' ), true );
			$state['published'] = $state['exists'];
			if ( post_type_exists( $subtype ) ) {
				$state['public'] = is_post_type_viewable( $subtype );
			} elseif ( taxonomy_exists( $subtype ) ) {
				$taxonomy        = get_taxonomy( $subtype );
				$state['public'] = $taxonomy instanceof WP_Taxonomy && is_taxonomy_viewable( $taxonomy );
			} else {
				$state['public'] = $state['exists'];
			}
			$canonical = $self_url;
		} else {
			$state['exists']    = true;
			$state['published'] = true;
			$state['public']    = true;
			$canonical          = $self_url;
		}

		$canonical                  = esc_url_raw( (string) apply_filters( 'erankly_canonical', $canonical ) );
		$state['canonical_url']     = erankly_is_absolute_http_url( $canonical ) ? $canonical : '';
		$state['canonical_is_self'] = '' !== $state['canonical_url']
			&& erankly_normalize_canonical_comparison_url( $state['canonical_url'] ) === erankly_normalize_canonical_comparison_url( $self_url );

		$noindex            = $state['exists'] && erankly_object_seo_state_is_noindex( $kind, $object_id, $subtype );
		$state['indexable'] = $state['exists'] && $state['published'] && $state['public'] && ! $noindex;

		if ( ! $state['exists'] ) {
			$state['reason_codes'][] = 'not_found';
		}
		if ( $state['exists'] && ! $state['published'] ) {
			$state['reason_codes'][] = 'not_published';
		}
		if ( $state['exists'] && ! $state['public'] ) {
			$state['reason_codes'][] = 'not_public';
		}
		if ( $noindex ) {
			$state['reason_codes'][] = 'noindex';
		}
		if ( $state['exists'] && '' === $state['canonical_url'] ) {
			$state['reason_codes'][] = 'canonical_missing';
		} elseif ( $state['exists'] && ! $state['canonical_is_self'] ) {
			$state['reason_codes'][] = 'canonical_not_self';
		}

		return $state;
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}
