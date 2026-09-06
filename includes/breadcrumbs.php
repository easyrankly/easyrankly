<?php
/** Breadcrumbs. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

if ( ! function_exists( 'easyrankly_breadcrumbs' ) ) {
	// Legacy public function kept for backward compatibility.
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	/** Legacy alias for the public breadcrumbs template function. */
	function easyrankly_breadcrumbs( array $args = array() ): string {
		return erankly_breadcrumbs( $args );
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}

function erankly_get_post_breadcrumb_name( int $post_id ): string {
	$name = erankly_get_post_meta_string( $post_id, 'breadcrumb_name' );

	if ( '' === $name && (bool) erankly_get_setting( 'simplified_mode', 1 ) ) {
		$name = erankly_get_post_meta_string( $post_id, 'title' );

		if ( '' !== $name ) {
			$name = erankly_replace_variables( $name, $post_id, array( 'seo_title' ) );
		}
	}

	$name = '' !== $name ? erankly_normalize_seo_text( $name ) : get_the_title( $post_id );

	return (string) apply_filters( 'erankly_post_breadcrumb_name', $name, $post_id );
}

/** @return array<int,array<string,string>> */
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

		/*
		 * A static front page is the same resource as the leading Home crumb
		 * (identity via show_on_front/page_on_front, not the visible label).
		 * Re-adding it produces a duplicate Home→Home BreadcrumbList.
		 * With only the Home crumb left, the existing < 2 convention omits output.
		 */
		$is_static_front = is_front_page()
			|| (
				'page' === (string) get_option( 'show_on_front' )
				&& $post_id > 0
				&& (int) get_option( 'page_on_front' ) === $post_id
			);

		if ( ! $is_static_front ) {
			$type = get_post_type( $post_id );

			if ( 'post' === $type ) {
				$categories = get_the_category( $post_id );
				$primary    = erankly_get_primary_term( $post_id, 'category' );

				if ( $primary instanceof WP_Term || ! empty( $categories[0] ) ) {
					$category = $primary instanceof WP_Term ? $primary : $categories[0];
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
		}
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

	$resolved = apply_filters( 'erankly_breadcrumb_items', $items );

	return is_array( $resolved ) ? $resolved : array();
}

/** @return array<string,mixed> */
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

/**
 * Whether BreadcrumbList JSON-LD should be emitted for the current request.
 *
 * Visual trail and structured data are separate: Google's structured-data
 * policies expect markup to match what people see. The default `when_visible`
 * mode therefore emits JSON-LD only when a trail is actually rendered.
 */
function erankly_should_emit_breadcrumb_schema(): bool {
	if ( ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		return false;
	}

	$mode = function_exists( 'erankly_sanitize_breadcrumb_jsonld_mode' )
		? erankly_sanitize_breadcrumb_jsonld_mode( erankly_get_setting( 'breadcrumb_jsonld_mode', 'when_visible' ) )
		: 'when_visible';

	if ( 'off' === $mode ) {
		return false;
	}

	if ( 'always' === $mode ) {
		return true;
	}

	return erankly_has_visible_breadcrumbs();
}

/**
 * Detects a visible EasyRankly breadcrumb trail: shortcode, block, or theme support.
 */
function erankly_has_visible_breadcrumbs(): bool {
	$visible = current_theme_supports( 'erankly-breadcrumbs' );

	if ( is_singular() ) {
		$post = get_post();

		if ( $post instanceof WP_Post ) {
			if (
				has_shortcode( $post->post_content, 'erankly_breadcrumbs' )
				|| has_shortcode( $post->post_content, 'easyrankly_breadcrumbs' )
			) {
				$visible = true;
			}

			if ( function_exists( 'has_block' ) && has_block( 'easyrankly/breadcrumbs', $post ) ) {
				$visible = true;
			}
		}
	}

	return (bool) apply_filters( 'erankly_has_visible_breadcrumbs', $visible );
}

/** @param array<string,mixed>|string $atts Shortcode attributes. */
function erankly_breadcrumbs_shortcode( $atts = array() ): string {
	unset( $atts );

	return erankly_breadcrumbs( array( 'echo' => false ) );
}

function erankly_render_breadcrumbs_block( array $attributes = array(), string $content = '' ): string {
	unset( $attributes, $content );

	return erankly_breadcrumbs( array( 'echo' => false ) );
}

function erankly_register_breadcrumb_integrations(): void {
	add_shortcode( 'erankly_breadcrumbs', 'erankly_breadcrumbs_shortcode' );
	add_shortcode( 'easyrankly_breadcrumbs', 'erankly_breadcrumbs_shortcode' );

	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	$block_dir = ERANKLY_PATH . 'blocks/breadcrumbs';

	wp_register_script(
		'erankly-breadcrumbs-block',
		ERANKLY_URL . 'blocks/breadcrumbs/index.js',
		array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor' ),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-breadcrumbs-block', 'easyrankly', ERANKLY_PATH . 'languages' );

	if ( file_exists( $block_dir . '/block.json' ) ) {
		register_block_type(
			$block_dir,
			array(
				'render_callback'      => 'erankly_render_breadcrumbs_block',
				'editor_script_handles' => array( 'erankly-breadcrumbs-block' ),
			)
		);

		return;
	}

	register_block_type(
		'easyrankly/breadcrumbs',
		array(
			'api_version'     => 3,
			'title'           => __( 'EasyRankly Breadcrumbs', 'easyrankly' ),
			'description'     => __( 'Visible breadcrumb trail that matches EasyRankly structured data when JSON-LD is set to emit with a visible trail.', 'easyrankly' ),
			'category'        => 'theme',
			'icon'            => 'arrow-right-alt',
			'render_callback' => 'erankly_render_breadcrumbs_block',
			'supports'        => array(
				'html'  => false,
				'align' => array( 'wide', 'full' ),
			),
		)
	);
}
