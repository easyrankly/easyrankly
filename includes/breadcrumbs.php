<?php
/**
 * Breadcrumbs.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders or returns breadcrumbs.
 *
 * @param array<string,mixed> $args Arguments.
 * @return string
 */
function erankly_breadcrumbs( array $args = array() ): string {
	if ( ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		return '';
	}

	$args = wp_parse_args(
		$args,
		array(
			'echo'      => true,
			'separator' => '/',
		)
	);

	$items = erankly_get_breadcrumb_items();

	if ( count( $items ) < 2 ) {
		return '';
	}

	$html  = '<nav class="erankly-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumbs', 'easyrankly' ) . '">';
	$html .= '<ol>';

	$last_index = count( $items ) - 1;

	foreach ( $items as $index => $item ) {
		$name = isset( $item['name'] ) ? (string) $item['name'] : '';
		$url  = isset( $item['url'] ) ? (string) $item['url'] : '';

		if ( '' === $name ) {
			continue;
		}

		$html .= '<li>';

		if ( $index < $last_index && '' !== $url ) {
			$html .= '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
		} else {
			$html .= '<span aria-current="page">' . esc_html( $name ) . '</span>';
		}

		$html .= '</li>';
	}

	$html .= '</ol>';
	$html .= '</nav>';

	/**
	 * Filters breadcrumbs HTML.
	 *
	 * @param string                  $html  Breadcrumbs HTML.
	 * @param array<int,array<string,string>> $items Breadcrumb items.
	 */
	$html = (string) apply_filters( 'erankly_breadcrumbs_html', $html, $items );

	$allowed_html        = wp_kses_allowed_html( 'post' );
	$allowed_html['nav'] = array(
		'aria-label' => true,
		'class'      => true,
		'id'         => true,
	);

	foreach ( array( 'a', 'li', 'ol', 'span' ) as $tag ) {
		$allowed_html[ $tag ]                 = isset( $allowed_html[ $tag ] ) ? $allowed_html[ $tag ] : array();
		$allowed_html[ $tag ]['aria-current'] = true;
		$allowed_html[ $tag ]['class']        = true;
		$allowed_html[ $tag ]['id']           = true;
	}

	$html = wp_kses( $html, $allowed_html );

	if ( (bool) $args['echo'] ) {
		echo wp_kses( $html, $allowed_html );
	}

	return $html;
}

/**
 * Returns the breadcrumb label for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_get_post_breadcrumb_name( int $post_id ): string {
	if ( (bool) erankly_get_setting( 'simplified_mode', 1 ) ) {
		$name = erankly_get_post_meta_string( $post_id, 'title' );

		if ( '' !== $name ) {
			$name = erankly_replace_variables( $name, $post_id, array( 'seo_title' ) );
		}
	} else {
		$name = erankly_get_post_meta_string( $post_id, 'breadcrumb_name' );
	}

	$name = '' !== $name ? erankly_normalize_seo_text( $name ) : get_the_title( $post_id );

	return (string) apply_filters( 'erankly_post_breadcrumb_name', $name, $post_id );
}

/**
 * Returns breadcrumb items.
 *
 * @return array<int,array<string,string>>
 */
function erankly_get_breadcrumb_items(): array {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$items = array(
		array(
			'name' => __( 'Home', 'easyrankly' ),
			'url'  => home_url( '/' ),
		),
	);

	if ( is_singular() ) {
		$post_id = get_queried_object_id();
		$type    = get_post_type( $post_id );

		if ( 'post' === $type ) {
			$categories = get_the_category( $post_id );

			if ( ! empty( $categories[0] ) ) {
				$category = $categories[0];
				$parents  = array_reverse( get_ancestors( $category->term_id, 'category', 'taxonomy' ) );

				foreach ( $parents as $parent_id ) {
					$parent = get_term( $parent_id, 'category' );

					if ( $parent instanceof WP_Term ) {
						$parent_link = get_term_link( $parent );

						$items[] = array(
							'name' => $parent->name,
							'url'  => is_wp_error( $parent_link ) ? '' : $parent_link,
						);
					}
				}

				$category_link = get_term_link( $category );

				$items[] = array(
					'name' => $category->name,
					'url'  => is_wp_error( $category_link ) ? '' : $category_link,
				);
			}
		} elseif ( 'page' !== $type ) {
			$archive = get_post_type_archive_link( (string) $type );
			$object  = get_post_type_object( (string) $type );

			if ( is_string( $archive ) && $object instanceof WP_Post_Type ) {
				$items[] = array(
					'name' => $object->labels->name,
					'url'  => $archive,
				);
			}
		}

		$ancestors = array_reverse( get_post_ancestors( $post_id ) );

		foreach ( $ancestors as $ancestor_id ) {
			$items[] = array(
				'name' => erankly_get_post_breadcrumb_name( $ancestor_id ),
				'url'  => get_permalink( $ancestor_id ),
			);
		}

		$items[] = array(
			'name' => erankly_get_post_breadcrumb_name( $post_id ),
			'url'  => '',
		);
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );

			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $term->taxonomy );

				if ( $ancestor instanceof WP_Term ) {
					$ancestor_link = get_term_link( $ancestor );

					$items[] = array(
						'name' => $ancestor->name,
						'url'  => is_wp_error( $ancestor_link ) ? '' : $ancestor_link,
					);
				}
			}

			$items[] = array(
				'name' => $term->name,
				'url'  => '',
			);
		}
	} elseif ( is_archive() ) {
		$items[] = array(
			'name' => wp_strip_all_tags( get_the_archive_title() ),
			'url'  => '',
		);
	} elseif ( is_search() ) {
		$items[] = array(
			'name' => get_search_query(),
			'url'  => '',
		);
	} elseif ( is_404() ) {
		$items[] = array(
			'name' => __( 'Page not found', 'easyrankly' ),
			'url'  => '',
		);
	}

	/**
	 * Filters breadcrumb items.
	 *
	 * @param array<int,array<string,string>> $items Breadcrumb items.
	 */
	$resolved = apply_filters( 'erankly_breadcrumb_items', $items );

	return is_array( $resolved ) ? $resolved : array();
}

/**
 * Returns BreadcrumbList schema.
 *
 * @return array<string,mixed>
 */
function erankly_schema_breadcrumb_list(): array {
	$items = erankly_get_breadcrumb_items();

	if ( count( $items ) < 2 ) {
		return array();
	}

	$list = array();

	foreach ( $items as $index => $item ) {
		$name = isset( $item['name'] ) ? (string) $item['name'] : '';
		$url  = isset( $item['url'] ) ? (string) $item['url'] : erankly_get_canonical();

		if ( '' === $name ) {
			continue;
		}

		$list[] = array_filter(
			array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $name,
				'item'     => '' !== $url ? $url : erankly_get_canonical(),
			)
		);
	}

	if ( empty( $list ) ) {
		return array();
	}

	$schema = array(
		'@type'           => 'BreadcrumbList',
		'@id'             => erankly_get_canonical() . '#breadcrumb',
		'itemListElement' => $list,
	);

	return apply_filters( 'erankly_schema_breadcrumb_list', $schema, $items );
}
