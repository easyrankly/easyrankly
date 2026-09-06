<?php
/** Shared helpers: post, term and global entity metadata. Part of the helpers.php loader; always loaded early on every request. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves a relative media URL against its owning public page. */
function erankly_absolutize_content_url( string $url, string $base_url = '' ): string {
	$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

	if ( '' === $url || str_starts_with( $url, '#' ) ) {
		return '';
	}

	$url = esc_url_raw( $url );
	if ( erankly_is_absolute_http_url( $url ) ) {
		return $url;
	}

	$home = wp_parse_url( home_url( '/' ) );
	if ( ! is_array( $home ) || empty( $home['scheme'] ) || empty( $home['host'] ) ) {
		return '';
	}

	if ( str_starts_with( $url, '//' ) ) {
		return esc_url_raw( $home['scheme'] . ':' . $url );
	}

	$base  = wp_parse_url( '' !== $base_url ? $base_url : home_url( '/' ) );
	$base  = is_array( $base ) ? $base : $home;
	$path  = (string) ( $base['path'] ?? '/' );
	$path  = str_starts_with( $url, '/' ) ? $url : trailingslashit( dirname( $path ) ) . $url;
	$query = '';

	if ( str_contains( $path, '?' ) ) {
		list( $path, $query ) = explode( '?', $path, 2 );
		$query                = '?' . $query;
	}
	$has_trailing_slash = str_ends_with( $path, '/' );

	$segments = array();
	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			array_pop( $segments );
			continue;
		}
		$segments[] = $segment;
	}

	$authority = $home['scheme'] . '://' . $home['host'];
	if ( isset( $home['port'] ) ) {
		$authority .= ':' . absint( $home['port'] );
	}

	$normalized_path = '/' . implode( '/', $segments );
	if ( $has_trailing_slash && '/' !== $normalized_path ) {
		$normalized_path = trailingslashit( $normalized_path );
	}

	return esc_url_raw( $authority . $normalized_path . $query );
}

/**
 * Collects attachment IDs from native image-bearing Gutenberg blocks.
 *
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 * @return array<int,int>
 */
function erankly_get_image_block_attachment_ids( array $blocks ): array {
	$ids   = array();
	$names = array( 'core/image', 'core/gallery', 'core/media-text', 'core/cover' );

	foreach ( $blocks as $block ) {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		if ( in_array( $name, $names, true ) ) {
			if ( isset( $attrs['id'] ) ) {
				$ids[] = absint( $attrs['id'] );
			}
			if ( isset( $attrs['mediaId'] ) ) {
				$ids[] = absint( $attrs['mediaId'] );
			}
			foreach ( isset( $attrs['ids'] ) && is_array( $attrs['ids'] ) ? $attrs['ids'] : array() as $id ) {
				$ids[] = absint( $id );
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$ids = array_merge( $ids, erankly_get_image_block_attachment_ids( $block['innerBlocks'] ) );
		}
	}

	return array_values( array_unique( array_filter( $ids ) ) );
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
	$base    = (string) get_permalink( $post_id );

	if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $content_matches ) ) {
		foreach ( $content_matches[1] as $src ) {
			$src = erankly_absolutize_content_url( (string) $src, $base );

			if ( erankly_is_absolute_http_url( $src ) ) {
				$images[] = $src;
			}
		}
	}

	if ( preg_match_all( '/<img[^>]+srcset=["\']([^"\']+)["\'][^>]*>/i', $content, $srcset_matches ) ) {
		foreach ( $srcset_matches[1] as $srcset ) {
			foreach ( explode( ',', (string) $srcset ) as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ) );
				$src   = erankly_absolutize_content_url( (string) ( $parts[0] ?? '' ), $base );
				if ( erankly_is_absolute_http_url( $src ) ) {
					$images[] = $src;
				}
			}
		}
	}

	if ( preg_match_all( '/"(?:url|link|href)":\s*"(https?:\/\/[^"]+\.(?:jpg|jpeg|png|gif|webp|avif|svg|bmp)[^"]*)"/', $content, $block_matches, PREG_SET_ORDER ) ) {
		foreach ( $block_matches as $match ) {
			$src = erankly_absolutize_content_url( $match[1], $base );

			if ( erankly_is_absolute_http_url( $src ) ) {
				$images[] = $src;
			}
		}
	}

	if ( function_exists( 'parse_blocks' ) && has_blocks( $post->post_content ) ) {
		foreach ( erankly_get_image_block_attachment_ids( parse_blocks( $post->post_content ) ) as $attachment_id ) {
			$images[] = erankly_get_image_url( $attachment_id, 'full' );
		}
	}

	if ( preg_match_all( '/\[gallery\b([^\]]*)\]/i', $post->post_content, $gallery_matches ) ) {
		foreach ( $gallery_matches[1] as $raw_attributes ) {
			$attributes = shortcode_parse_atts( (string) $raw_attributes );
			$attributes = is_array( $attributes ) ? $attributes : array();
			foreach ( array_filter( array_map( 'absint', explode( ',', (string) ( $attributes['ids'] ?? '' ) ) ) ) as $attachment_id ) {
				$images[] = erankly_get_image_url( $attachment_id, 'full' );
			}
		}
	}

	return array_values( array_unique( array_filter( $images, 'erankly_is_absolute_http_url' ) ) );
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

/**
 * Returns the Schema.org type configured for a post type, or its default when nothing is stored.
 *
 * Deliberately does not borrow another post type's value the way title and description do when "Same for all"
 * is on: a post type registered after the settings were last saved has no row, and inheriting the first stored
 * row would give a brand-new custom post type the Article node configured for blog posts.
 *
 * @param string $field Either webpage_type or article_type.
 */
function erankly_get_post_type_schema_type( string $post_type, string $field ): string {
	if ( ! in_array( $field, array( 'webpage_type', 'article_type' ), true ) ) {
		return '';
	}

	foreach ( array( 'global_post_type_schema', 'global_post_type_meta' ) as $setting_key ) {
		// global_post_type_meta is the pre-2.9 location, still written by the
		// third-party migration adapters; it is read only as a fallback so an
		// import stays visible until the Schema panel is saved once.
		$rows = erankly_get_global_entity_meta_map( $setting_key );
		$row  = ( isset( $rows[ $post_type ] ) && is_array( $rows[ $post_type ] ) ) ? $rows[ $post_type ] : array();

		if ( ! array_key_exists( $field, $row ) ) {
			continue;
		}

		$value = is_string( $row[ $field ] ) ? trim( $row[ $field ] ) : '';

		// A stored empty string is how the retired free-text field expressed
		// "do not emit this node", so it is honoured rather than defaulted.
		return 'article_type' === $field && '' === $value ? 'none' : $value;
	}

	$defaults = erankly_default_post_type_schema_row( $post_type );

	return $defaults[ $field ];
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
