<?php
/**
 * Internal link graph helpers.
 *
 * Builds and caches a site-wide map of inbound/outbound internal links between
 * published, indexable content. Used by the Link Building module.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option storing the cached link graph (no autoload). */
define( 'ERANKLY_LB_GRAPH_OPTION', 'erankly_lb_graph' );

/** Posts loaded per batch while building the graph. */
define( 'ERANKLY_LB_GRAPH_BATCH', 200 );

/**
 * Normalizes a URL or path to a root-relative path for internal link matching.
 *
 * @param string $url URL or path to normalize.
 * @return string Normalized root-relative path, or empty string if not resolvable.
 */
function erankly_lb_normalize_link_path( string $url ): string {
	$path = wp_parse_url( $url, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );

	return '/' === $path ? $path : untrailingslashit( $path );
}

/**
 * Returns the cached link graph, or null when no index has been built yet.
 *
 * @return array<string,mixed>|null
 */
function erankly_lb_get_graph(): ?array {
	$graph = get_option( ERANKLY_LB_GRAPH_OPTION, null );

	return is_array( $graph ) ? $graph : null;
}

/**
 * Deletes the cached link graph.
 *
 * @return void
 */
function erankly_lb_delete_graph(): void {
	delete_option( ERANKLY_LB_GRAPH_OPTION );
}

/**
 * Extracts internal links from post HTML content.
 *
 * @param string $content     Raw post content.
 * @param string $home_host   Site host for internal/external checks.
 * @param array<string,int>  $path_map Normalized path => post ID.
 * @param int                $post_id  Source post ID (self-links are skipped).
 * @return array<int,array{to:int,anchor:string}>
 */
function erankly_lb_extract_internal_links( string $content, string $home_host, array $path_map, int $post_id ): array {
	$links = array();

	preg_match_all( '/<a\s[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER );

	foreach ( $matches as $match ) {
		$href = trim( (string) $match[1] );

		if ( '' === $href || '#' === $href[0] ) {
			continue;
		}

		if (
			0 === stripos( $href, 'mailto:' ) ||
			0 === stripos( $href, 'tel:' ) ||
			0 === stripos( $href, 'javascript:' )
		) {
			continue;
		}

		$href_host = wp_parse_url( $href, PHP_URL_HOST );

		if ( is_string( $href_host ) && '' !== $href_host && $href_host !== $home_host ) {
			continue;
		}

		$href_path = erankly_lb_normalize_link_path( $href );

		if ( '' === $href_path || ! isset( $path_map[ $href_path ] ) ) {
			continue;
		}

		$target_id = (int) $path_map[ $href_path ];

		if ( $target_id === $post_id ) {
			continue;
		}

		$anchor = '';

		if ( preg_match( '/<a\s[^>]*>(.*?)<\/a>/is', $match[0], $anchor_match ) ) {
			$anchor = trim( wp_strip_all_tags( (string) $anchor_match[1] ) );
		}

		if ( mb_strlen( $anchor ) > 200 ) {
			$anchor = mb_substr( $anchor, 0, 200 );
		}

		$links[] = array(
			'to'     => $target_id,
			'anchor' => $anchor,
		);
	}

	return $links;
}

/**
 * Builds the full internal link graph and stores it in wp_options.
 *
 * @return array<string,mixed> Stored graph payload.
 */
function erankly_lb_build_graph(): array {
	global $wpdb;

	$post_types = array_keys( erankly_get_public_post_types() );
	$empty      = array(
		'built_at'    => time(),
		'post_count'  => 0,
		'orphan_count'=> 0,
		'posts'       => array(),
		'orphans'     => array(),
	);

	if ( empty( $post_types ) ) {
		update_option( ERANKLY_LB_GRAPH_OPTION, $empty, false );
		return $empty;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- On-demand link graph build; IDs only.
		$wpdb->prepare(
			"SELECT p.ID
				FROM {$wpdb->posts} p
				WHERE p.post_status = 'publish'
					AND p.post_type IN ({$placeholders})
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
						AND pm.meta_key = '_erankly_noindex'
						AND pm.meta_value = '1'
					)",
			$post_types
		)
	);

	$post_ids = array_map( 'intval', (array) $post_ids );

	if ( empty( $post_ids ) ) {
		update_option( ERANKLY_LB_GRAPH_OPTION, $empty, false );
		return $empty;
	}

	$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$path_map  = array();

	foreach ( $post_ids as $post_id ) {
		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			continue;
		}

		$path = erankly_lb_normalize_link_path( $permalink );

		if ( '' !== $path ) {
			$path_map[ $path ] = $post_id;
		}
	}

	$inbound_counts = array();
	$outbound       = array();

	foreach ( array_chunk( $post_ids, ERANKLY_LB_GRAPH_BATCH ) as $batch_ids ) {
		$id_placeholders = implode( ', ', array_fill( 0, count( $batch_ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch content load for link graph.
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$id_placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are literal %d tokens bound via prepare().
				...$batch_ids
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			continue;
		}

		foreach ( $rows as $row ) {
			$post_id      = (int) $row['ID'];
			$post_content = (string) $row['post_content'];
			$found        = erankly_lb_extract_internal_links( $post_content, $home_host, $path_map, $post_id );

			$outbound[ $post_id ] = $found;

			foreach ( $found as $link ) {
				$target_id = (int) $link['to'];

				if ( ! isset( $inbound_counts[ $target_id ] ) ) {
					$inbound_counts[ $target_id ] = 0;
				}

				++$inbound_counts[ $target_id ];
			}
		}
	}

	$posts   = array();
	$orphans = array();

	foreach ( $post_ids as $post_id ) {
		$inbound_count  = isset( $inbound_counts[ $post_id ] ) ? (int) $inbound_counts[ $post_id ] : 0;
		$outbound_links = isset( $outbound[ $post_id ] ) ? $outbound[ $post_id ] : array();
		$is_orphan      = 0 === $inbound_count;

		$posts[ $post_id ] = array(
			'inbound_count'  => $inbound_count,
			'outbound_count' => count( $outbound_links ),
			'outbound'       => $outbound_links,
			'is_orphan'      => $is_orphan,
		);

		if ( $is_orphan ) {
			$orphans[] = $post_id;
		}
	}

	usort(
		$orphans,
		static function ( int $a, int $b ) use ( $posts ): int {
			$out_a = $posts[ $a ]['outbound_count'] ?? 0;
			$out_b = $posts[ $b ]['outbound_count'] ?? 0;

			return $out_b <=> $out_a;
		}
	);

	$graph = array(
		'built_at'     => time(),
		'post_count'   => count( $posts ),
		'orphan_count' => count( $orphans ),
		'posts'        => $posts,
		'orphans'      => $orphans,
	);

	update_option( ERANKLY_LB_GRAPH_OPTION, $graph, false );

	return $graph;
}

/**
 * Returns post IDs that already link to a target post.
 *
 * @param array<string,mixed> $graph     Link graph.
 * @param int                 $target_id Target post ID.
 * @return array<int,int> Source post IDs keyed by ID.
 */
function erankly_lb_graph_inbound_sources( array $graph, int $target_id ): array {
	$sources = array();
	$posts   = isset( $graph['posts'] ) && is_array( $graph['posts'] ) ? $graph['posts'] : array();

	foreach ( $posts as $source_id => $meta ) {
		$source_id = (int) $source_id;
		$outbound  = isset( $meta['outbound'] ) && is_array( $meta['outbound'] ) ? $meta['outbound'] : array();

		foreach ( $outbound as $link ) {
			if ( (int) ( $link['to'] ?? 0 ) === $target_id ) {
				$sources[ $source_id ] = $source_id;
				break;
			}
		}
	}

	return $sources;
}
