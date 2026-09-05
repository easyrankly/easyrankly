<?php
/** Import / Export data serialization. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Returns only settings that are still part of the public import/export contract. */
function erankly_export_settings(): array {
	$settings = erankly_get_plugin_option( ERANKLY_OPTION, array() );
	$settings = is_array( $settings ) ? $settings : array();
	unset( $settings['redirect_exclude_admins'] );

	return $settings;
}

/** @param int    $after_id Last emitted keyset cursor. */
function erankly_export_page( string $stream, int $after_id, int $limit ): array {
	global $wpdb;

	if ( 'redirects' === $stream ) {
		erankly_ensure_redirect_classes_available();
		if ( ! class_exists( 'ERankly_Redirects_Repository' ) || ! erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			return array();
		}
		return ( new ERankly_Redirects_Repository() )->export_page( $after_id, $limit );
	}

	$definitions = array(
		'post_meta' => array( $wpdb->postmeta, 'meta_id', 'post_id' ),
		'term_meta' => array( $wpdb->termmeta, 'meta_id', 'term_id' ),
		'user_meta' => array( $wpdb->usermeta, 'umeta_id', 'user_id' ),
	);
	if ( ! isset( $definitions[ $stream ] ) ) {
		return array();
	}

	list( $table, $cursor_column, $object_column ) = $definitions[ $stream ];
	$meta_keys                                     = array_keys( erankly_get_meta_keys() );
	$placeholders                                  = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
	$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset export page.
		$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The dynamic placeholder list and replacement array are constructed from the same fixed key map.
			"SELECT %i AS _cursor, %i AS object_id, meta_key, meta_value FROM %i WHERE %i > %d AND meta_key IN ( {$placeholders} ) ORDER BY %i ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only the fixed meta-key placeholder list is interpolated; identifiers use %i.
			array_merge( array( $cursor_column, $object_column, $table, $cursor_column, max( 0, $after_id ) ), $meta_keys, array( $cursor_column, max( 1, min( 1000, $limit ) ) ) )
		),
		ARRAY_A
	);
	$result = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$result[] = array(
			'_cursor' => absint( $row['_cursor'] ?? 0 ),
			'id'      => absint( $row['object_id'] ?? 0 ),
			'key'     => (string) ( $row['meta_key'] ?? '' ),
			'value'   => maybe_unserialize( $row['meta_value'] ?? '' ),
		);
	}

	return $result;
}

/**
 * Emits a JSON array while holding at most one keyset page in memory.
 *
 * @throws RuntimeException When a row cannot be encoded or the cursor stalls.
 */
function erankly_export_stream_array( string $stream, int $limit = 500 ): void {
	$after_id = 0;
	$first    = true;
	echo '['; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response framing.
	do {
		$rows = erankly_export_page( $stream, $after_id, $limit );
		foreach ( $rows as $row ) {
			$cursor = absint( $row['_cursor'] ?? 0 );
			unset( $row['_cursor'] );
			$encoded = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $encoded || $cursor <= $after_id ) {
				throw new RuntimeException( 'Export page encoding or cursor validation failed.' );
			}
			echo $first ? $encoded : ',' . $encoded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output in a JSON download.
			$first    = false;
			$after_id = $cursor;
		}
		$count = count( $rows );
	} while ( $limit === $count );
	echo ']'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response framing.
}

function erankly_export_download(): void {
	$filename = 'erankly-export-' . gmdate( 'Y-m-d-His' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$header = array(
		'plugin'      => 'erankly',
		'format'      => ERANKLY_EXPORT_FORMAT,
		'version'     => ERANKLY_VERSION,
		'exported_at' => gmdate( 'c' ),
		'site_url'    => home_url(),
		'settings'    => erankly_export_settings(),
	);

	/** Filters the native EasyRankly export header. Add-ons may attach extra keys they own. Do not mutate `settings`. */
	$header = apply_filters( 'erankly_export_header', $header );
	$header = is_array( $header ) ? $header : array();

	$encoded_header = wp_json_encode( $header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $encoded_header ) {
		wp_die( esc_html__( 'The export header could not be encoded.', 'easyrankly' ), '', array( 'response' => 500 ) );
	}
	echo substr( $encoded_header, 0, -1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download header with only its outer terminator removed.
	foreach ( array( 'redirects', 'post_meta', 'term_meta', 'user_meta' ) as $stream ) {
		echo ',"' . $stream . '":'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed internal JSON property name.
		erankly_export_stream_array( $stream );
	}
	$special_meta = get_option( ERANKLY_SPECIAL_META_OPTION, null );
	if ( is_array( $special_meta ) ) {
		echo ',"special_meta":' . wp_json_encode( $special_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded download value.
	}
	echo '}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response terminator.
	exit;
}

/**
 * Restores an EasyRankly export payload.
 *
 * @return array<string,mixed> Cumulative counts plus cursor and done.
 */
function erankly_import_apply( array $data, array $checkpoint = array() ): array {
	_deprecated_function( __FUNCTION__, '2.0.0', 'ERankly_Import_Job_Runner' );

	return ERankly_Import_Job_Runner::apply_payload_batch( $data, $checkpoint );
}

/**
 * Imports useful per-content SEO data from a third-party plugin. Existing EasyRankly values are never
 * overwritten, so the import only fills in fields that are currently empty.
 *
 * @return array{post_meta:int,term_meta:int,queued:bool,job_id:string}
 */
function erankly_import_third_party( string $source ): array {
	_deprecated_function( __FUNCTION__, '2.0.0', 'erankly_migration_job_runner()->start()' );
	$result = erankly_migration_job_runner()->start( $source, false );
	$job    = is_array( $result['job'] ?? null ) ? $result['job'] : array();

	return array(
		'post_meta' => 0,
		'term_meta' => 0,
		'queued'    => ! empty( $result['ok'] ),
		'job_id'    => sanitize_text_field( (string) ( $job['id'] ?? '' ) ),
	);
}
