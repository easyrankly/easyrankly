<?php
/** Shared helpers: post, term and global entity metadata. Part of the helpers.php loader; always loaded early on every request. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns valid image URLs embedded in post content, in document order. Images inside code examples are ignored.
 * The raw block markup fallback covers image URLs stored in Gutenberg attributes when no rendered img tag is
 * present.
 *
 * @return array<int,string>
 */
function erankly_get_post_content_image_urls( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || '' === $post->post_content ) {
		return array();
	}

	$content = (string) preg_replace( '#<(pre|code)[^>]*>.*?</\1>#is', '', $post->post_content );
	$content = is_string( $content ) ? $content : $post->post_content;
	$images  = array();

	if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $content_matches ) ) {
		foreach ( $content_matches[1] as $src ) {
			$src = esc_url_raw( (string) $src );

			if ( erankly_is_absolute_http_url( $src ) ) {
				$images[] = $src;
			}
		}
	}

	if ( preg_match_all( '/"(?:url|link|href)":\s*"(https?:\/\/[^"]+\.(?:jpg|jpeg|png|gif|webp|avif|svg|bmp)[^"]*)"/', $content, $block_matches, PREG_SET_ORDER ) ) {
		foreach ( $block_matches as $match ) {
			$src = esc_url_raw( $match[1] );

			if ( erankly_is_absolute_http_url( $src ) ) {
				$images[] = $src;
			}
		}
	}

	return array_values( array_unique( $images ) );
}

/** @param string $key     Meta key without plugin prefix. */
function erankly_get_post_meta_string( int $post_id, string $key ): string {
	$value = get_post_meta( $post_id, '_erankly_' . $key, true );

	return is_string( $value ) ? trim( $value ) : '';
}

/** @param string $key     Meta key without plugin prefix. */
function erankly_get_post_meta_bool( int $post_id, string $key ): bool {
	return '1' === (string) get_post_meta( $post_id, '_erankly_' . $key, true );
}

/** @param string $key     Meta key without plugin prefix. */
function erankly_get_term_meta_string( int $term_id, string $key ): string {
	$value = get_term_meta( $term_id, '_erankly_' . $key, true );

	return is_string( $value ) ? trim( $value ) : '';
}

/** @param string $key     Meta key without plugin prefix. */
function erankly_get_term_meta_bool( int $term_id, string $key ): bool {
	return '1' === (string) get_term_meta( $term_id, '_erankly_' . $key, true );
}

/** @return WP_Term|null */
function erankly_get_primary_term( int $post_id, string $taxonomy ): ?WP_Term {
	$primary_terms = get_post_meta( $post_id, '_erankly_primary_terms', true );
	$term_id       = is_array( $primary_terms ) && isset( $primary_terms[ $taxonomy ] ) ? absint( $primary_terms[ $taxonomy ] ) : 0;

	if ( $term_id < 1 || ! has_term( $term_id, $taxonomy, $post_id ) ) {
		return null;
	}

	$term = get_term( $term_id, $taxonomy );

	return $term instanceof WP_Term ? $term : null;
}

/**
 * Returns the explicit robots directive for a post or term. Legacy boolean metadata is used only when the
 * tri-state field has never been stored, which keeps upgrades lossless while allowing an explicit positive
 * directive to override a restrictive global default.
 *
 * @param string $object_type `post`, `term`, or `user`.
 * @param string $axis        index, follow, archive, snippet, or image.
 */
function erankly_get_object_robots_directive( string $object_type, int $object_id, string $axis ): string {
	$key = '_erankly_' . $axis . '_directive';

	if ( 'term' === $object_type ) {
		$value = get_term_meta( $object_id, $key, true );
	} elseif ( 'user' === $object_type ) {
		$value = get_user_meta( $object_id, $key, true );
	} else {
		$value = get_post_meta( $object_id, $key, true );
	}

	$value = is_string( $value ) ? trim( $value ) : '';

	if ( '' !== $value && 'inherit' !== $value ) {
		return $value;
	}

	$legacy = array(
		'index'   => 'noindex',
		'follow'  => 'nofollow',
		'archive' => 'noarchive',
	);

	if ( ! isset( $legacy[ $axis ] ) || 'inherit' === $value ) {
		return 'inherit';
	}

	$legacy_key = '_erankly_' . $legacy[ $axis ];
	$legacy_val = 'term' === $object_type
		? get_term_meta( $object_id, $legacy_key, true )
		: ( 'user' === $object_type ? get_user_meta( $object_id, $legacy_key, true ) : get_post_meta( $object_id, $legacy_key, true ) );

	return '1' === (string) $legacy_val ? $legacy[ $axis ] : 'inherit';
}

function erankly_get_global_post_type_meta( string $post_type, string $field ): string {
	return erankly_get_global_entity_meta( 'global_post_type_meta', $post_type, $field );
}

function erankly_get_global_taxonomy_meta( string $taxonomy, string $field ): string {
	return erankly_get_global_entity_meta( 'global_taxonomy_meta', $taxonomy, $field );
}

function erankly_get_global_post_type_directive( string $post_type, string $field ): bool {
	return erankly_get_global_entity_directive( 'global_post_type_meta', $post_type, $field );
}

function erankly_get_global_taxonomy_directive( string $taxonomy, string $field ): bool {
	return erankly_get_global_entity_directive( 'global_taxonomy_meta', $taxonomy, $field );
}

/**
 * Returns the special page metadata stored for the current site. On Multisite this lives in a per-site option so
 * a homepage title or description set on one network site never applies to other (possibly different-language)
 * sites. Falls back to the shared defaults so a site that has never saved keeps the expected behaviour (search
 * results and the 404 page noindexed).
 *
 * @return array<string,array<string,string|int>>
 */
function erankly_get_site_special_meta(): array {
	$stored = get_option( ERANKLY_SPECIAL_META_OPTION, null );

	if ( ! is_array( $stored ) ) {
		erankly_load_default_helpers();
		$stored = erankly_default_global_special_meta();
	}

	$context = function_exists( 'erankly_get_multilingual_context' ) ? erankly_get_multilingual_context() : array();

	$filtered = apply_filters( 'erankly_site_special_meta', $stored, $context );

	return is_array( $filtered ) ? $filtered : $stored;
}

/**
 * Returns the stored metadata map for a global entity group. Special page metadata is per site on Multisite;
 * post type and taxonomy defaults stay network-wide. On single-site every group lives in the shared settings
 * array.
 *
 * @return array<string,mixed>
 */
function erankly_get_global_entity_meta_map( string $setting_key ): array {
	if ( 'global_special_meta' === $setting_key && is_multisite() ) {
		$stored = erankly_get_site_special_meta();
	} else {
		$stored = erankly_get_setting( $setting_key, array() );
		$stored = is_array( $stored ) ? $stored : array();
	}

	$context = function_exists( 'erankly_get_multilingual_context' ) ? erankly_get_multilingual_context() : array();

	$filtered = apply_filters( 'erankly_global_entity_meta_map', $stored, $setting_key, $context );

	return is_array( $filtered ) ? $filtered : $stored;
}

/**
 * Returns the complete metadata row for one global entity. Linked groups fall back to the first available row,
 * matching the title and visibility lookup behavior while preserving optional advanced robot fields.
 *
 * @return array<string,mixed>
 */
function erankly_get_global_entity_meta_row( string $setting_key, string $entity ): array {
	$templates = erankly_get_global_entity_meta_map( $setting_key );

	if ( isset( $templates[ $entity ] ) && is_array( $templates[ $entity ] ) ) {
		return $templates[ $entity ];
	}

	if ( ! erankly_global_entity_meta_is_linked( $setting_key ) ) {
		return array();
	}

	foreach ( $templates as $template ) {
		if ( is_array( $template ) ) {
			return $template;
		}
	}

	return array();
}

/**
 * Determines whether a global entity group shares one template across all entities. Special pages are always
 * configured individually, so they are never "linked".
 */
function erankly_global_entity_meta_is_linked( string $setting_key ): bool {
	if ( 'global_special_meta' === $setting_key ) {
		return false;
	}

	return (bool) erankly_get_setting( $setting_key . '_linked', 1 );
}

function erankly_get_global_entity_meta( string $setting_key, string $entity, string $field ): string {
	$templates = erankly_get_global_entity_meta_map( $setting_key );

	if ( isset( $templates[ $entity ] ) && is_array( $templates[ $entity ] ) ) {
		$value = $templates[ $entity ][ $field ] ?? '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	if ( ! erankly_global_entity_meta_is_linked( $setting_key ) ) {
		return '';
	}

	foreach ( $templates as $template ) {
		if ( ! is_array( $template ) ) {
			continue;
		}

		$value = $template[ $field ] ?? '';

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}
	}

	return '';
}

function erankly_get_global_entity_directive( string $setting_key, string $entity, string $field ): bool {
	if ( ! in_array( $field, array( 'noindex', 'nofollow', 'noarchive', 'disable_sitemap' ), true ) ) {
		return false;
	}

	$templates = erankly_get_global_entity_meta_map( $setting_key );

	if ( isset( $templates[ $entity ] ) && is_array( $templates[ $entity ] ) ) {
		return ! empty( $templates[ $entity ][ $field ] );
	}

	if ( ! erankly_global_entity_meta_is_linked( $setting_key ) ) {
		return false;
	}

	foreach ( $templates as $template ) {
		if ( is_array( $template ) && ! empty( $template[ $field ] ) ) {
			return true;
		}
	}

	return false;
}
