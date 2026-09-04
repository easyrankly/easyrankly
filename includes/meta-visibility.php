<?php
/** Frontend search and archive visibility filters. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Excludes content from frontend search and archive queries when configured. */
function erankly_filter_visibility_queries( WP_Query $query ): void {
	if ( is_admin() || wp_doing_ajax() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_search() && erankly_has_visibility_exclusions( '_erankly_exclude_search' ) ) {
		erankly_add_query_exclusion_meta_clause( $query, '_erankly_exclude_search' );
	}

	if ( $query->is_archive() && erankly_has_visibility_exclusions( '_erankly_exclude_archive' ) ) {
		erankly_add_query_exclusion_meta_clause( $query, '_erankly_exclude_archive' );
	}
}

/** Returns whether a visibility meta key is used by at least one post. */
function erankly_has_visibility_exclusions( string $meta_key ): bool {
	global $wpdb;

	$allowed = array( '_erankly_exclude_search', '_erankly_exclude_archive' );

	if ( ! in_array( $meta_key, $allowed, true ) ) {
		return false;
	}

	$cache_key = 'erankly_visibility_' . md5( $meta_key );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return '1' === (string) $cached;
	}

	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A transient-cached indexed existence check avoids expensive meta queries on normal archive requests.
		$wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1' LIMIT 1",
			$meta_key
		)
	);
	$value = null !== $found ? '1' : '0';

	set_transient( $cache_key, $value, DAY_IN_SECONDS );

	return '1' === $value;
}

/**
 * Invalidates visibility caches after relevant post meta changes.
 *
 * @param int|array $meta_id  Meta row ID, or array of IDs on deletion.
 */
function erankly_invalidate_visibility_exclusion_cache( int|array $meta_id, int $post_id, string $meta_key ): void {
	unset( $meta_id, $post_id );

	if ( in_array( $meta_key, array( '_erankly_exclude_search', '_erankly_exclude_archive' ), true ) ) {
		delete_transient( 'erankly_visibility_' . md5( $meta_key ) );
	}
}

/** Adds a meta query clause that excludes posts with a truthy visibility flag. */
function erankly_add_query_exclusion_meta_clause( WP_Query $query, string $meta_key ): void {
	$meta_query = $query->get( 'meta_query' );
	$existing   = is_array( $meta_query ) ? $meta_query : array();
	$exclusion  = array(
		'relation' => 'OR',
		array(
			'key'     => $meta_key,
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => $meta_key,
			'value'   => '1',
			'compare' => '!=',
		),
	);

	if ( empty( $existing ) ) {
		$query->set( 'meta_query', $exclusion );
		return;
	}

	$query->set(
		'meta_query',
		array(
			'relation' => 'AND',
			$existing,
			$exclusion,
		)
	);
}
