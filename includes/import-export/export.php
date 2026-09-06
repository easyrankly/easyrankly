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

/**
 * @param int $after_id Last emitted keyset cursor.
 * @throws RuntimeException When a database page cannot be read.
 */
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
	if ( '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( 'An export database page could not be read.' );
	}
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
 * Writes a complete string even when the stream accepts a partial write.
 *
 * @param resource $handle Open, writable stream.
 * @throws RuntimeException When the stream stops accepting data.
 */
function erankly_export_write_all( $handle, string $data ): void {
	$length = strlen( $data );
	$offset = 0;

	while ( $offset < $length ) {
		$written = fwrite( $handle, substr( $data, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The export stream may be a private backup or the explicitly requested download response.
		if ( false === $written || 0 === $written ) {
			throw new RuntimeException( 'The export stream could not be written completely.' );
		}
		$offset += $written;
	}
}

/**
 * Writes a JSON array to one stream while holding at most one keyset page in memory.
 *
 * @param resource $handle Open, writable stream.
 * @throws RuntimeException When a row cannot be encoded or the cursor stalls.
 */
function erankly_export_stream_array( $handle, string $stream, int $limit = 500 ): void {
	$after_id = 0;
	$first    = true;
	erankly_export_write_all( $handle, '[' );
	do {
		$rows = erankly_export_page( $stream, $after_id, $limit );
		foreach ( $rows as $row ) {
			$cursor = absint( $row['_cursor'] ?? 0 );
			unset( $row['_cursor'] );
			$encoded = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $encoded || $cursor <= $after_id ) {
				throw new RuntimeException( 'Export page encoding or cursor validation failed.' );
			}
			erankly_export_write_all( $handle, $first ? $encoded : ',' . $encoded );
			$first    = false;
			$after_id = $cursor;
		}
		$count = count( $rows );
	} while ( $limit === $count );
	erankly_export_write_all( $handle, ']' );
}

/**
 * Writes one complete EasyRankly backup document to a stream.
 *
 * Shared by the admin download and by the automatic pre-import backup, so a restore always reads exactly the
 * same format the export tab produces.
 *
 * @param resource $handle Open, writable stream.
 * @throws RuntimeException When the header cannot be encoded.
 */
function erankly_export_write( $handle ): void {
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
	if (
		! is_array( $header )
		|| 'erankly' !== (string) ( $header['plugin'] ?? '' )
		|| ERANKLY_EXPORT_FORMAT !== (string) ( $header['format'] ?? '' )
		|| ! is_array( $header['settings'] ?? null )
		|| ! is_string( $header['site_url'] ?? null )
	) {
		throw new RuntimeException( 'The export header was changed into an invalid document.' );
	}

	$encoded_header = wp_json_encode( $header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $encoded_header ) {
		throw new RuntimeException( 'The export header could not be encoded.' );
	}

	if ( ! str_ends_with( $encoded_header, '}' ) ) {
		throw new RuntimeException( 'The export header is not a JSON object.' );
	}

	erankly_export_write_all( $handle, substr( $encoded_header, 0, -1 ) );
	foreach ( array( 'redirects', 'post_meta', 'term_meta', 'user_meta' ) as $stream ) {
		erankly_export_write_all( $handle, ',"' . $stream . '":' );
		erankly_export_stream_array( $handle, $stream );
	}
	$special_meta = get_option( ERANKLY_SPECIAL_META_OPTION, null );
	$special_meta = is_array( $special_meta ) ? $special_meta : null;
	$encoded_special_meta = wp_json_encode( $special_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $encoded_special_meta ) {
		throw new RuntimeException( 'The special-page metadata could not be encoded.' );
	}
	erankly_export_write_all( $handle, ',"special_meta":' . $encoded_special_meta );
	erankly_export_write_all( $handle, '}' );
}

function erankly_export_download(): void {
	$filename = 'erankly-export-' . gmdate( 'Y-m-d-His' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$handle = fopen( 'php://output', 'wb' );
	if ( false === $handle ) {
		wp_die( esc_html__( 'The export stream could not be opened.', 'easyrankly' ), '', array( 'response' => 500 ) );
	}
	try {
		erankly_export_write( $handle );
	} catch ( RuntimeException ) {
		wp_die( esc_html__( 'The export could not be generated.', 'easyrankly' ), '', array( 'response' => 500 ) );
	} finally {
		fclose( $handle );
	}
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
