<?php
/**
 * Shared helpers: dynamic template variables.
 *
 * Part of the helpers.php loader; always loaded early on every request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces dynamic template variables in a text value.
 *
 * @param string            $value   Raw text value.
 * @param int               $post_id Optional post ID.
 * @param array<int,string> $exclude Variables that should not resolve for this call.
 * @return string
 */
function erankly_replace_variables( string $value, int $post_id = 0, array $exclude = array() ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	if ( $post_id <= 0 && is_singular() ) {
		$post_id = get_queried_object_id();
	}

	$exclude = array_fill_keys( array_map( 'strtolower', $exclude ), true );

	return (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $post_id, $exclude ): string {
			$key = strtolower( (string) $matches[1] );

			if ( isset( $exclude[ $key ] ) ) {
				return '';
			}

			return erankly_get_variable_value( $key, $post_id );
		},
		$value
	);
}

/**
 * Replaces dynamic variables inside a JSON-LD string.
 *
 * @param string $value   Raw JSON-LD.
 * @param int    $post_id Optional post ID.
 * @return string
 */
function erankly_replace_json_ld_variables( string $value, int $post_id = 0 ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	if ( $post_id <= 0 && is_singular() ) {
		$post_id = get_queried_object_id();
	}

	return (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $post_id ): string {
			$replacement = erankly_get_variable_value( strtolower( (string) $matches[1] ), $post_id );
			$json        = wp_json_encode( $replacement, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( ! is_string( $json ) ) {
				return '';
			}

			return '"' === $json[0] ? substr( $json, 1, -1 ) : $json;
		},
		$value
	);
}

/**
 * Returns the value for a supported dynamic variable.
 *
 * @param string $key     Variable key.
 * @param int    $post_id Optional post ID.
 * @return string
 */
function erankly_get_variable_value( string $key, int $post_id = 0 ): string {
	static $resolving = array();
	static $cache     = array();

	$cache_key = $key . ':' . $post_id;

	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	if ( isset( $resolving[ $key ] ) ) {
		return '';
	}

	$resolving[ $key ] = true;
	$post              = $post_id > 0 ? get_post( $post_id ) : null;
	$queried           = get_queried_object();
	$value             = '';

	switch ( $key ) {
		case 'post_title':
			$value = $post_id > 0 ? get_the_title( $post_id ) : '';
			break;
		case 'post_excerpt':
			if ( $post instanceof WP_Post ) {
				$value = has_excerpt( $post ) ? get_the_excerpt( $post ) : erankly_trim_text( $post->post_content, 160 );
			}
			break;
		case 'post_content':
			$value = $post instanceof WP_Post ? wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) : '';
			break;
		case 'post_url':
			$value = $post_id > 0 ? (string) get_permalink( $post_id ) : '';
			break;
		case 'post_date':
			$value = $post_id > 0 ? get_the_date( DATE_W3C, $post_id ) : '';
			break;
		case 'post_year':
			$value = $post_id > 0 ? get_the_date( 'Y', $post_id ) : '';
			break;
		case 'post_month':
			$value = $post_id > 0 ? get_the_date( 'F', $post_id ) : '';
			break;
		case 'post_day':
			$value = $post_id > 0 ? get_the_date( 'j', $post_id ) : '';
			break;
		case 'post_modified_date':
			$value = $post_id > 0 ? get_the_modified_date( DATE_W3C, $post_id ) : '';
			break;
		case 'post_author':
			if ( $post instanceof WP_Post ) {
				$value = get_the_author_meta( 'display_name', (int) $post->post_author );
			}
			break;
		case 'post_categories':
			$value = erankly_get_post_category_names( $post_id );
			break;
		case 'post_tags':
			$value = erankly_get_post_tag_names( $post_id );
			break;
		case 'seo_title':
			$value = function_exists( 'erankly_get_title' ) ? erankly_get_title() : '';
			break;
		case 'meta_description':
			$value = function_exists( 'erankly_get_description' ) ? erankly_get_description() : '';
			break;
		case 'canonical_url':
			$value = function_exists( 'erankly_get_canonical' ) ? erankly_get_canonical() : '';
			break;
		case 'featured_image':
			$image = $post_id > 0 ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
			$value = is_string( $image ) ? $image : '';
			break;
		case 'post_type_name':
			if ( $post instanceof WP_Post ) {
				$post_type = get_post_type_object( $post->post_type );
				$value     = $post_type instanceof WP_Post_Type ? $post_type->labels->singular_name : $post->post_type;
			} elseif ( $queried instanceof WP_Post_Type ) {
				$value = $queried->labels->singular_name;
			}
			break;
		case 'term_name':
			$value = $queried instanceof WP_Term ? $queried->name : '';
			break;
		case 'term_description':
			$value = $queried instanceof WP_Term ? $queried->description : '';
			break;
		case 'term_slug':
			$value = $queried instanceof WP_Term ? $queried->slug : '';
			break;
		case 'term_url':
			if ( $queried instanceof WP_Term ) {
				$term_link = get_term_link( $queried );
				$value     = is_wp_error( $term_link ) ? '' : $term_link;
			}
			break;
		case 'taxonomy_name':
			if ( $queried instanceof WP_Term ) {
				$taxonomy = get_taxonomy( $queried->taxonomy );
				$value    = $taxonomy instanceof WP_Taxonomy ? $taxonomy->labels->singular_name : $queried->taxonomy;
			}
			break;
		case 'search_query':
			$value = is_search() ? get_search_query() : '';
			break;
		case 'page_number':
			$paged = max( (int) get_query_var( 'paged', 0 ), (int) get_query_var( 'page', 0 ) );
			$value = (string) max( 1, $paged );
			break;
		case 'max_pages':
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core WP global, read-only.
			$value = isset( $GLOBALS['wp_query'] ) ? (string) max( 1, (int) $GLOBALS['wp_query']->max_num_pages ) : '1';
			break;
		case 'current_year':
			$value = wp_date( 'Y' );
			break;
		case 'current_month':
			$value = wp_date( 'F' );
			break;
		case 'current_day':
			$value = wp_date( 'j' );
			break;
		case 'current_date':
			$value = wp_date( (string) get_option( 'date_format' ) );
			break;
		case 'site_name':
			$value = get_bloginfo( 'name' );
			break;
		case 'site_description':
			$value = get_bloginfo( 'description' );
			break;
		case 'site_url':
			$value = home_url( '/' );
			break;
		case 'site_language':
			$value = get_bloginfo( 'language' );
			break;
		case 'organization_name':
			$value = erankly_get_organization_name();
			break;
		case 'website_name':
			$value = function_exists( 'erankly_get_website_name' ) ? erankly_get_website_name() : get_bloginfo( 'name' );
			break;
		case 'website_description':
			$value = function_exists( 'erankly_get_website_description' ) ? erankly_get_website_description() : get_bloginfo( 'description' );
			break;
		case 'organization_logo':
		case 'organization_logo_url':
			$value = erankly_get_organization_logo_url();
			break;
		case 'site_icon':
		case 'site_icon_url':
			$value = erankly_get_site_icon_url();
			break;
		case 'schema_identity_id':
			$value = function_exists( 'erankly_schema_identity_id' ) ? erankly_schema_identity_id() : home_url( '/#organization' );
			break;
	}

	unset( $resolving[ $key ] );

	$cache[ $cache_key ] = trim( wp_strip_all_tags( (string) $value ) );

	return $cache[ $cache_key ];
}

/**
 * Returns comma-separated category names for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_get_post_category_names( int $post_id ): string {
	if ( $post_id <= 0 ) {
		return '';
	}

	$categories = get_the_category( $post_id );

	if ( empty( $categories ) || is_wp_error( $categories ) ) {
		return '';
	}

	$names = wp_list_pluck( $categories, 'name' );

	return implode( ', ', array_map( 'sanitize_text_field', $names ) );
}

/**
 * Returns comma-separated tag names for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_get_post_tag_names( int $post_id ): string {
	if ( $post_id <= 0 ) {
		return '';
	}

	$tags = get_the_tags( $post_id );

	if ( empty( $tags ) || is_wp_error( $tags ) ) {
		return '';
	}

	$names = wp_list_pluck( $tags, 'name' );

	return implode( ', ', array_map( 'sanitize_text_field', $names ) );
}

/**
 * Resolves a variable's example value for admin field previews, given an
 * explicit post/term instead of the global queried object. Admin screens
 * (Settings defaults, classic meta boxes) don't have one to fall back on.
 *
 * @param string       $key  Variable key.
 * @param WP_Post|null $post Sample or currently-edited post, if any.
 * @param WP_Term|null $term Sample or currently-edited term, if any.
 * @return string Empty string when this key has no example value here.
 */
function erankly_get_variable_preview_value( string $key, ?WP_Post $post = null, ?WP_Term $term = null ): string {
	$value = '';

	switch ( $key ) {
		case 'post_title':
			$value = $post ? get_the_title( $post ) : '';
			break;
		case 'post_excerpt':
			if ( $post ) {
				$value = has_excerpt( $post ) ? get_the_excerpt( $post ) : erankly_trim_text( $post->post_content, 160 );
			}
			break;
		case 'post_content':
			$value = $post ? erankly_trim_text( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 160 ) : '';
			break;
		case 'post_url':
			$value = $post ? (string) get_permalink( $post ) : '';
			break;
		case 'post_date':
			$value = $post ? get_the_date( DATE_W3C, $post ) : '';
			break;
		case 'post_year':
			$value = $post ? get_the_date( 'Y', $post ) : '';
			break;
		case 'post_month':
			$value = $post ? get_the_date( 'F', $post ) : '';
			break;
		case 'post_day':
			$value = $post ? get_the_date( 'j', $post ) : '';
			break;
		case 'post_modified_date':
			$value = $post ? get_the_modified_date( DATE_W3C, $post ) : '';
			break;
		case 'post_author':
			$value = $post ? get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
			break;
		case 'post_categories':
			$value = $post ? erankly_get_post_category_names( $post->ID ) : '';
			break;
		case 'post_tags':
			$value = $post ? erankly_get_post_tag_names( $post->ID ) : '';
			break;
		case 'seo_title':
			$value = $post ? get_the_title( $post ) : '';
			break;
		case 'meta_description':
			if ( $post ) {
				$value = has_excerpt( $post ) ? get_the_excerpt( $post ) : erankly_trim_text( $post->post_content, 160 );
			}
			break;
		case 'canonical_url':
			$value = $post ? (string) get_permalink( $post ) : '';
			break;
		case 'featured_image':
			if ( $post ) {
				$image = get_the_post_thumbnail_url( $post, 'full' );
				$value = is_string( $image ) ? $image : '';
			}
			break;
		case 'post_type_name':
			if ( $post ) {
				$post_type = get_post_type_object( $post->post_type );
				$value     = $post_type instanceof WP_Post_Type ? $post_type->labels->singular_name : $post->post_type;
			}
			break;
		case 'term_name':
			$value = $term ? $term->name : '';
			break;
		case 'term_description':
			$value = $term ? $term->description : '';
			break;
		case 'term_slug':
			$value = $term ? $term->slug : '';
			break;
		case 'term_url':
			if ( $term ) {
				$term_link = get_term_link( $term );
				$value     = is_wp_error( $term_link ) ? '' : $term_link;
			}
			break;
		case 'taxonomy_name':
			if ( $term ) {
				$taxonomy = get_taxonomy( $term->taxonomy );
				$value    = $taxonomy instanceof WP_Taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy;
			}
			break;
		case 'site_name':
			$value = get_bloginfo( 'name' );
			break;
		case 'current_year':
			$value = wp_date( 'Y' );
			break;
		case 'current_month':
			$value = wp_date( 'F' );
			break;
		case 'current_day':
			$value = wp_date( 'j' );
			break;
		case 'current_date':
			$value = wp_date( (string) get_option( 'date_format' ) );
			break;
		case 'site_description':
			$value = get_bloginfo( 'description' );
			break;
		case 'site_url':
			$value = home_url( '/' );
			break;
		case 'site_language':
			$value = get_bloginfo( 'language' );
			break;
		case 'organization_name':
			$value = function_exists( 'erankly_get_organization_name' ) ? erankly_get_organization_name() : '';
			break;
		case 'website_name':
			$value = function_exists( 'erankly_get_website_name' ) ? erankly_get_website_name() : get_bloginfo( 'name' );
			break;
		case 'website_description':
			$value = function_exists( 'erankly_get_website_description' ) ? erankly_get_website_description() : get_bloginfo( 'description' );
			break;
		case 'organization_logo':
		case 'organization_logo_url':
			$value = function_exists( 'erankly_get_organization_logo_url' ) ? erankly_get_organization_logo_url() : '';
			break;
		case 'site_icon':
		case 'site_icon_url':
			$value = function_exists( 'erankly_get_site_icon_url' ) ? erankly_get_site_icon_url() : '';
			break;
		case 'schema_identity_id':
			if ( function_exists( 'erankly_schema_identity_id' ) ) {
				$value = erankly_schema_identity_id();
			} else {
				$type  = (string) erankly_get_setting( 'schema_identity', 'organization' );
				$value = home_url( 'person' === $type ? '/#person' : '/#organization' );
			}
			break;
		case 'page_number':
		case 'max_pages':
			// Admin screens have no paginated query; use the same floor the
			// frontend resolver uses so {{page_number}} / {{max_pages}} can
			// still preview on settings fields.
			$value = '1';
			break;
	}

	return trim( wp_strip_all_tags( (string) $value ) );
}

/**
 * Builds the example-values map for an admin {{variable}} field preview,
 * skipping any key that has no example (its {{token}} then stays literal
 * in the field, e.g. when a post type has no published posts yet).
 *
 * Site-level keys resolve even without a sample post or term, so identity
 * fields on Settings can preview values such as {{site_description}}.
 *
 * @param WP_Post|null $post Sample or currently-edited post, if any.
 * @param WP_Term|null $term Sample or currently-edited term, if any.
 * @return array<string,string>
 */
function erankly_get_admin_variable_examples( ?WP_Post $post = null, ?WP_Term $term = null ): array {
	// Keep this list aligned with erankly_get_variable_groups(). Empty
	// preview values are skipped below, so request-only keys such as
	// search_query stay literal until they have a stand-in.
	$keys = array(
		'post_title',
		'post_excerpt',
		'post_content',
		'post_url',
		'post_date',
		'post_year',
		'post_month',
		'post_day',
		'post_modified_date',
		'post_author',
		'post_categories',
		'post_tags',
		'post_type_name',
		'featured_image',
		'term_name',
		'term_description',
		'term_slug',
		'term_url',
		'taxonomy_name',
		'seo_title',
		'meta_description',
		'canonical_url',
		'search_query',
		'page_number',
		'max_pages',
		'site_name',
		'current_year',
		'current_month',
		'current_day',
		'current_date',
		'site_description',
		'site_url',
		'site_language',
		'organization_name',
		'website_name',
		'website_description',
		'organization_logo_url',
		'site_icon_url',
		'schema_identity_id',
	);

	if ( function_exists( 'erankly_get_variable_groups' ) ) {
		foreach ( erankly_get_variable_groups() as $group ) {
			if ( empty( $group['variables'] ) || ! is_array( $group['variables'] ) ) {
				continue;
			}

			foreach ( array_keys( $group['variables'] ) as $key ) {
				$keys[] = (string) $key;
			}
		}

		$keys = array_values( array_unique( $keys ) );
	}

	$examples = array();

	foreach ( $keys as $key ) {
		$value = erankly_get_variable_preview_value( $key, $post, $term );

		if ( '' !== $value ) {
			$examples[ $key ] = $value;
		}
	}

	return $examples;
}

/**
 * Returns the most recently published post of a type, to stand in as the
 * "{{post_title}}"-style example on global default-template admin fields
 * that aren't tied to any single post.
 *
 * @param string $post_type Post type slug.
 * @return WP_Post|null
 */
function erankly_get_sample_post_for_type( string $post_type ): ?WP_Post {
	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	return ! empty( $posts ) ? $posts[0] : null;
}

/**
 * Returns the first term of a taxonomy, to stand in as the "{{term_name}}"
 * example on global default-template admin fields that aren't tied to any
 * single term.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Term|null
 */
function erankly_get_sample_term_for_taxonomy( string $taxonomy ): ?WP_Term {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'number'     => 1,
			'hide_empty' => false,
		)
	);

	return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
}
