<?php
/**
 * Import / Export module.
 *
 * Exports and restores all EasyRankly data (settings, redirects, post, term,
 * and user meta) as a single JSON file.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/migrations.php';

/**
 * Export file format version. Bumped when the JSON structure changes.
 */
define( 'ERANKLY_EXPORT_FORMAT', '2.0' );

/** Default maximum size for a complete EasyRankly JSON import. */
define( 'ERANKLY_IMPORT_DEFAULT_MAX_BYTES', 10 * 1024 * 1024 );

/** Memory reserved for WordPress and the import writes after JSON decoding. */
define( 'ERANKLY_IMPORT_MEMORY_RESERVE_BYTES', 8 * 1024 * 1024 );

/** Coarse first-stage cap for the raw upload relative to available memory. */
define( 'ERANKLY_IMPORT_RAW_MEMORY_FACTOR', 16 );

/** Maximum supported nesting depth for the EasyRankly export schema. */
define( 'ERANKLY_IMPORT_JSON_MAX_DEPTH', 64 );

/** Absolute JSON node cap, including containers, keys, and scalar values. */
define( 'ERANKLY_IMPORT_JSON_MAX_NODES', 500000 );

/** Conservative decoded-memory allowance assigned to every JSON node. */
define( 'ERANKLY_IMPORT_JSON_NODE_BYTES', 512 );

/**
 * Returns the safe application limit for a complete EasyRankly JSON import.
 *
 * The configured ceiling is additionally constrained by the memory currently
 * available to PHP. Decoding JSON into associative arrays can require many
 * times the source size, so the raw upload must remain only a small fraction of
 * the remaining memory after a reserve for WordPress and the database writes.
 *
 * @return int Maximum number of bytes accepted from the uploaded file.
 */
function erankly_import_export_max_bytes(): int {
	$configured   = max(
		1024,
		(int) apply_filters( 'erankly_import_export_max_bytes', ERANKLY_IMPORT_DEFAULT_MAX_BYTES )
	);
	$memory_limit = function_exists( 'wp_convert_hr_to_bytes' )
		? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) )
		: -1;

	if ( $memory_limit <= 0 ) {
		return $configured;
	}

	$available  = $memory_limit - memory_get_usage( true ) - ERANKLY_IMPORT_MEMORY_RESERVE_BYTES;
	$memory_cap = max(
		1024,
		(int) floor( $available / ERANKLY_IMPORT_RAW_MEMORY_FACTOR )
	);

	return min( $configured, $memory_cap );
}

/**
 * Reads a local upload without ever allocating more than the application cap.
 *
 * The caller must still enforce that the path is a genuine PHP upload. The
 * stat check provides a fast rejection, while the bounded read closes the race
 * where the file changes after its size was inspected.
 *
 * @param string $path    Genuine PHP upload temporary path.
 * @param int    $maximum Maximum number of bytes to read.
 * @return array{ok:bool,error:string,contents:string}
 */
function erankly_import_export_read_bounded_upload( string $path, int $maximum ): array {
	$maximum = max( 1, $maximum );

	if ( '' === $path || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
		return array(
			'ok'       => false,
			'error'    => 'invalid',
			'contents' => '',
		);
	}

	clearstatcache( true, $path );
	$size = filesize( $path );
	if ( false === $size || $size < 1 ) {
		return array(
			'ok'       => false,
			'error'    => 'invalid',
			'contents' => '',
		);
	}
	if ( $size > $maximum ) {
		return array(
			'ok'       => false,
			'error'    => 'too-large',
			'contents' => '',
		);
	}

	$contents = file_get_contents( $path, false, null, 0, $maximum + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A verified local PHP upload needs a hard byte-bounded read before JSON decoding.
	if ( false === $contents ) {
		return array(
			'ok'       => false,
			'error'    => 'invalid',
			'contents' => '',
		);
	}
	if ( strlen( $contents ) > $maximum ) {
		return array(
			'ok'       => false,
			'error'    => 'too-large',
			'contents' => '',
		);
	}

	return array(
		'ok'       => true,
		'error'    => '',
		'contents' => $contents,
	);
}

/**
 * Profiles JSON structure without materializing PHP arrays.
 *
 * The single-pass scanner counts every container, string, and primitive while
 * tracking balanced delimiters and nesting. Its memory use is bounded by the
 * supported depth rather than by the number of JSON values.
 *
 * @param string $json Raw JSON document.
 * @return array{valid:bool,nodes:int,depth:int}
 */
function erankly_import_export_json_memory_profile( string $json ): array {
	$length       = strlen( $json );
	$nodes        = 0;
	$max_depth    = 0;
	$stack        = array();
	$in_string    = false;
	$escaped      = false;
	$in_primitive = false;

	for ( $index = 0; $index < $length; ++$index ) {
		$character = $json[ $index ];

		if ( $in_string ) {
			if ( $escaped ) {
				$escaped = false;
			} elseif ( '\\' === $character ) {
				$escaped = true;
			} elseif ( '"' === $character ) {
				$in_string = false;
			}
			continue;
		}

		if ( '"' === $character ) {
			++$nodes;
			$in_string    = true;
			$in_primitive = false;
			continue;
		}

		if ( '{' === $character || '[' === $character ) {
			++$nodes;
			$stack[]      = $character;
			$max_depth    = max( $max_depth, count( $stack ) );
			$in_primitive = false;

			if ( $max_depth > ERANKLY_IMPORT_JSON_MAX_DEPTH || $nodes > ERANKLY_IMPORT_JSON_MAX_NODES ) {
				return array(
					'valid' => true,
					'nodes' => $nodes,
					'depth' => $max_depth,
				);
			}
			continue;
		}

		if ( '}' === $character || ']' === $character ) {
			$expected = '}' === $character ? '{' : '[';
			if ( empty( $stack ) || array_pop( $stack ) !== $expected ) {
				return array(
					'valid' => false,
					'nodes' => $nodes,
					'depth' => $max_depth,
				);
			}
			$in_primitive = false;
			continue;
		}

		if ( false !== strpos( " \t\n\r,:", $character ) ) {
			$in_primitive = false;
			continue;
		}

		if ( ! $in_primitive ) {
			++$nodes;
			$in_primitive = true;
		}

		if ( $nodes > ERANKLY_IMPORT_JSON_MAX_NODES ) {
			return array(
				'valid' => true,
				'nodes' => $nodes,
				'depth' => $max_depth,
			);
		}
	}

	return array(
		'valid' => ! $in_string && ! $escaped && empty( $stack ),
		'nodes' => $nodes,
		'depth' => $max_depth,
	);
}

/**
 * Returns why a JSON document cannot safely be decoded in this request.
 *
 * @param string $json Raw JSON document.
 * @return string Empty when safe; otherwise invalid or too-complex.
 */
function erankly_import_export_json_memory_error( string $json ): string {
	$profile = erankly_import_export_json_memory_profile( $json );

	if ( empty( $profile['valid'] ) ) {
		return 'invalid';
	}
	if ( $profile['depth'] > ERANKLY_IMPORT_JSON_MAX_DEPTH || $profile['nodes'] > ERANKLY_IMPORT_JSON_MAX_NODES ) {
		return 'too-complex';
	}

	$memory_limit = function_exists( 'wp_convert_hr_to_bytes' )
		? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) )
		: -1;

	if ( $memory_limit <= 0 ) {
		return '';
	}

	// The raw string is already included in current usage. Reserve another raw
	// length for decoded string contents, plus a conservative per-node budget.
	$available = $memory_limit
		- memory_get_usage( true )
		- ERANKLY_IMPORT_MEMORY_RESERVE_BYTES
		- strlen( $json );

	if ( $available < ERANKLY_IMPORT_JSON_NODE_BYTES ) {
		return 'too-complex';
	}

	$memory_node_cap = intdiv( $available, ERANKLY_IMPORT_JSON_NODE_BYTES );

	return $profile['nodes'] > $memory_node_cap ? 'too-complex' : '';
}

/**
 * Loads redirect class files on demand even when the module is disabled.
 *
 * This lets export/import handle redirect data without requiring the feature
 * to be switched on first.
 *
 * @return void
 */
function erankly_ensure_redirect_classes_available(): void {
	$base = ERANKLY_PATH . 'includes/redirects/';
	require_once ERANKLY_PATH . 'includes/helpers/redirect-cache.php';

	$files = array(
		'class-erankly-redirects-normalizer.php',
		'class-erankly-redirects-activator.php',
		'class-erankly-redirects-repository.php',
	);

	foreach ( $files as $file ) {
		if ( file_exists( $base . $file ) ) {
			require_once $base . $file;
		}
	}
}


/**
 * Returns the settings page URL for the Import / Export tab.
 *
 * @return string
 */
function erankly_import_export_url(): string {
	// On Multisite the Import/Export tab lives under Network Admin → Settings, so
	// the form target and redirect must resolve to network/settings.php; on a
	// single site it stays on the standard options-general.php settings screen.
	$base = is_network_admin()
		? network_admin_url( 'settings.php' )
		: admin_url( 'options-general.php' );

	return add_query_arg(
		array(
			'page'        => 'erankly',
			'erankly_tab' => 'import-export',
		),
		$base
	);
}

/**
 * Dispatches import/export form submissions on the settings page.
 *
 * @return void
 */
function erankly_import_export_handle_actions(): void {
	// On Multisite the settings option is a network option; gate write access accordingly.
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 'erankly' !== $page ) {
		return;
	}

	ERankly_Migration_Upload_Store::prune_stale();

	// Export is a nonce-protected GET link that streams a download.
	if ( isset( $_GET['erankly_io_action'] ) && 'export' === sanitize_key( wp_unslash( $_GET['erankly_io_action'] ) ) ) {
		// check_admin_referer() dies on failure, so no error branch is needed.
		check_admin_referer( 'erankly_io_export' );

		erankly_export_download();
	}

	if ( isset( $_GET['erankly_io_action'] ) && 'migration-report' === sanitize_key( wp_unslash( $_GET['erankly_io_action'] ) ) ) {
		$report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : '';
		check_admin_referer( 'erankly_migration_report_' . $report_id );
		erankly_migration_report_download( $report_id );
	}

	if ( isset( $_GET['erankly_io_action'] ) && 'migration-exceptions' === sanitize_key( wp_unslash( $_GET['erankly_io_action'] ) ) ) {
		$report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : '';
		check_admin_referer( 'erankly_migration_exceptions_' . $report_id );
		erankly_migration_exceptions_download( $report_id );
	}

	if ( ! isset( $_POST['erankly_io_action'] ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['erankly_io_action'] ) );

	if ( 'import' === $action ) {
		erankly_import_export_handle_import();
	}

	if ( 'migrate' === $action ) {
		$source = isset( $_POST['erankly_migration_source'] ) ? sanitize_key( wp_unslash( $_POST['erankly_migration_source'] ) ) : '';
		erankly_import_export_handle_third_party( $source );
	}

	if ( 'migrate-export' === $action ) {
		$source = isset( $_POST['erankly_migration_export_source'] ) ? sanitize_key( wp_unslash( $_POST['erankly_migration_export_source'] ) ) : 'auto';
		erankly_import_export_handle_third_party_export( $source );
	}

	if ( in_array( $action, array( 'migration-process', 'migration-cancel' ), true ) ) {
		$job_id = isset( $_POST['erankly_migration_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['erankly_migration_job_id'] ) ) : '';
		erankly_import_export_handle_migration_job( $job_id, $action );
	}

	if ( in_array( $action, array( 'migration-verify-live', 'migration-rollback' ), true ) ) {
		$report_id = isset( $_POST['erankly_migration_report_id'] ) ? sanitize_text_field( wp_unslash( $_POST['erankly_migration_report_id'] ) ) : '';
		erankly_import_export_handle_migration_evidence_action( $report_id, $action );
	}

	// Backward compatibility for forms or integrations created before the
	// preview/report workflow was introduced.
	if ( in_array( $action, array( 'yoast', 'rankmath', 'aioseo', 'seopress' ), true ) ) {
		erankly_import_export_handle_third_party( $action );
	}
}

/**
 * Handles a full-data JSON import upload.
 *
 * @return void
 */
function erankly_import_export_handle_import(): void {
	check_admin_referer( 'erankly_io_import' );

	if (
		empty( $_FILES['erankly_import_file'] ) ||
		! isset( $_FILES['erankly_import_file']['tmp_name'], $_FILES['erankly_import_file']['error'] )
	) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$upload_error = (int) $_FILES['erankly_import_file']['error'];
	if ( in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'too-large' ) );
	}
	if ( UPLOAD_ERR_OK !== $upload_error ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$tmp_name = (string) $_FILES['erankly_import_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	// Defence in depth: only ever read a genuine PHP upload, never an arbitrary
	// server path that could reach this handler through a crafted request.
	if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$read = erankly_import_export_read_bounded_upload( $tmp_name, erankly_import_export_max_bytes() );
	if ( empty( $read['ok'] ) ) {
		$notice = 'too-large' === (string) ( $read['error'] ?? '' ) ? 'too-large' : 'invalid';
		erankly_import_export_redirect( array( 'erankly_io_notice' => $notice ) );
	}
	$contents = (string) $read['contents'];

	if ( '' === trim( $contents ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$memory_error = erankly_import_export_json_memory_error( $contents );
	if ( '' !== $memory_error ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => $memory_error ) );
	}

	$data = json_decode( $contents, true, ERANKLY_IMPORT_JSON_MAX_DEPTH );
	unset( $contents, $read );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'erankly' ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$counts = erankly_import_apply( $data );

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => 'imported',
			'er_settings'       => (int) $counts['settings'],
			'er_redirects'      => (int) $counts['redirects'],
			'er_post_meta'      => (int) $counts['post_meta'],
			'er_term_meta'      => (int) $counts['term_meta'],
			'er_user_meta'      => (int) $counts['user_meta'],
		)
	);
}

/**
 * Handles an import from a third-party SEO plugin.
 *
 * @param string $source Source plugin slug.
 * @return void
 */
function erankly_import_export_handle_third_party( string $source ): void {
	check_admin_referer( 'erankly_io_third_party' );

	$mode = isset( $_POST['erankly_migration_mode'] ) ? sanitize_key( wp_unslash( $_POST['erankly_migration_mode'] ) ) : 'import';
	try {
		$result = erankly_migration_job_runner()->start( $source, 'preview' === $mode );
	} catch ( Throwable ) {
		$result = array(
			'ok'    => false,
			'error' => 'migration_start_exception',
		);
	}
	$job   = is_array( $result['job'] ?? null ) ? $result['job'] : array();
	$error = (string) ( $result['error'] ?? '' );
	if ( ! empty( $result['ok'] ) ) {
		$notice = 'migration-started';
	} elseif ( 'migration_already_running' === $error ) {
		$notice = 'migration-running';
	} elseif ( 'unsupported_source_storage' === $error ) {
		$notice = 'migration-source-unsupported';
	} else {
		$notice = 'migration-start-error';
	}

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => $notice,
			'report_id'         => (string) ( $job['id'] ?? '' ),
		)
	);
}

/**
 * Validates, privately stages and starts an official-export migration.
 *
 * @param string $requested_source auto or a supported adapter slug.
 * @return void
 */
function erankly_import_export_handle_third_party_export( string $requested_source ): void {
	check_admin_referer( 'erankly_io_third_party_export' );

	$file   = isset( $_FILES['erankly_migration_export_file'] ) && is_array( $_FILES['erankly_migration_export_file'] )
		? $_FILES['erankly_migration_export_file'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The upload store validates error, origin, name, size, extension and certified content signature.
		: array();
	$staged = ERankly_Migration_Upload_Store::store_http_upload( $file, $requested_source );
	if ( empty( $staged['ok'] ) ) {
		erankly_import_export_redirect(
			array(
				'erankly_io_notice'    => 'migration-export-invalid',
				'erankly_upload_error' => sanitize_key( (string) ( $staged['error'] ?? 'upload_failed' ) ),
			)
		);
	}

	$path   = (string) $staged['path'];
	$source = sanitize_key( (string) $staged['source'] );
	$mode   = isset( $_POST['erankly_migration_mode'] ) ? sanitize_key( wp_unslash( $_POST['erankly_migration_mode'] ) ) : 'preview';
	try {
		$result = erankly_migration_job_runner()->start_from_export( $source, $path, 'preview' === $mode );
	} catch ( Throwable ) {
		$result = array(
			'ok'    => false,
			'error' => 'migration_start_exception',
		);
	}

	$job   = is_array( $result['job'] ?? null ) ? $result['job'] : array();
	$error = (string) ( $result['error'] ?? '' );
	if ( ! empty( $result['ok'] ) ) {
		$notice = 'migration-started';
	} else {
		ERankly_Migration_Upload_Store::delete( $path );
		$notice = 'migration_already_running' === $error ? 'migration-running' : ( 'unsupported_source_storage' === $error ? 'migration-source-unsupported' : 'migration-start-error' );
	}

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => $notice,
			'report_id'         => (string) ( $job['id'] ?? '' ),
		)
	);
}

/**
 * Handles a manual resume or cancellation of the active migration job.
 *
 * @param string $job_id Migration UUID.
 * @param string $action migration-process|migration-cancel.
 * @return void
 */
function erankly_import_export_handle_migration_job( string $job_id, string $action ): void {
	check_admin_referer( 'erankly_migration_job_' . $job_id );

	$runner = erankly_migration_job_runner();
	try {
		if ( 'migration-cancel' === $action ) {
			$runner->cancel( $job_id );
		} else {
			$runner->process( $job_id );
		}
	} catch ( Throwable ) {
		erankly_import_export_redirect(
			array(
				'erankly_io_notice' => 'migration-action-error',
				'report_id'         => $job_id,
			)
		);
	}

	$active = $runner->active_job();
	$report = erankly_migration_manager()->get_report( $job_id );
	$notice = is_array( $active ) ? 'migration-running' : 'migration-error';

	if ( is_array( $report ) ) {
		$status = (string) ( $report['status'] ?? 'failed' );
		$notice = 'complete' === $status ? 'migration' : ( 'cancelled' === $status ? 'migration-cancelled' : ( 'partial' === $status ? 'migration-partial' : 'migration-error' ) );
	}

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => $notice,
			'report_id'         => $job_id,
		)
	);
}

/**
 * Streams a saved migration report as JSON.
 *
 * @param string $report_id Migration report UUID.
 * @return void
 */
function erankly_migration_report_download( string $report_id ): void {
	$report = erankly_migration_manager()->get_report( $report_id );

	if ( ! is_array( $report ) ) {
		wp_die( esc_html__( 'Migration report not found.', 'easyrankly' ), '', array( 'response' => 404 ) );
	}

	$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( ! is_string( $json ) ) {
		wp_die( esc_html__( 'The migration report could not be encoded.', 'easyrankly' ), '', array( 'response' => 500 ) );
	}

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="easyrankly-migration-' . sanitize_file_name( $report_id ) . '.json"' );
	header( 'Content-Length: ' . strlen( $json ) );
	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
	exit;
}

/**
 * Streams the complete value-free exception ledger as CSV.
 *
 * @param string $report_id Migration report UUID.
 */
function erankly_migration_exceptions_download( string $report_id ): void {
	$report = erankly_migration_manager()->get_report( $report_id );
	if ( ! is_array( $report ) ) {
		wp_die( esc_html__( 'Migration report not found.', 'easyrankly' ), '', array( 'response' => 404 ) );
	}
	$evidence   = is_array( $report['evidence'] ?? null ) ? $report['evidence'] : array();
	$exceptions = is_array( $evidence['exceptions'] ?? null ) ? $evidence['exceptions'] : array();
	$store      = erankly_migration_evidence_store();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="easyrankly-migration-exceptions-' . sanitize_file_name( $report_id ) . '.csv"' );
	$stream = fopen( 'php://output', 'w' );
	if ( false === $stream ) {
		wp_die( esc_html__( 'The exception report could not be opened.', 'easyrankly' ), '', array( 'response' => 500 ) );
	}
	$columns = array( 'area', 'outcome', 'reference', 'target', 'object_type', 'object_id', 'edit_url' );
	fputcsv( $stream, $columns );
	$write_exception = static function ( array $exception ) use ( $stream, $columns ): void {
		$row = array();
		foreach ( $columns as $column ) {
			$value = (string) ( $exception[ $column ] ?? '' );
			if ( preg_match( '/^[=+\-@]/', $value ) ) {
				$value = "'" . $value;
			}
			$row[] = $value;
		}
		fputcsv( $stream, $row );
	};
	if ( $store->count( $report_id ) > 0 ) {
		$after_id = 0;
		do {
			$page = $store->page( $report_id, $after_id, 500 );
			foreach ( $page as $exception ) {
				$after_id = absint( $exception['id'] ?? $after_id );
				$write_exception( $exception );
			}
			$page_count = count( $page );
		} while ( 500 === $page_count );
	} else {
		foreach ( $exceptions as $exception ) {
			$write_exception( $exception );
		}
	}
	fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a write-only HTTP response stream, not a filesystem artifact.
	exit;
}

/**
 * Handles live comparison and conditional rollback from a saved report.
 *
 * @param string $report_id Migration report UUID.
 * @param string $action migration-verify-live|migration-rollback.
 */
function erankly_import_export_handle_migration_evidence_action( string $report_id, string $action ): void {
	check_admin_referer( 'erankly_migration_evidence_' . $report_id );
	$manager = erankly_migration_manager();
	$report  = $manager->get_report( $report_id );
	if ( ! is_array( $report ) || 'import' !== (string) ( $report['mode'] ?? '' ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'migration-evidence-error' ) );
	}

	if ( 'migration-verify-live' === $action ) {
		$gate = $manager->evaluate_go_live_gate( $report, true );
		if ( empty( $gate['can_verify_live'] ) ) {
			erankly_import_export_redirect(
				array(
					'erankly_io_notice' => 'migration-gate-blocked',
					'report_id'         => $report_id,
				)
			);
		}
		$source_owns_output = (bool) apply_filters( 'erankly_migration_source_owns_output', erankly_detect_external_seo_head_owner(), sanitize_key( (string) ( $report['source'] ?? '' ) ) );
		if ( $source_owns_output ) {
			erankly_import_export_redirect(
				array(
					'erankly_io_notice' => 'migration-source-still-active',
					'report_id'         => $report_id,
				)
			);
		}
		$verifier                    = new ERankly_Migration_Live_Verifier();
		$report['live_verification'] = $verifier->verify( $report );
		$manager->update_report( $report );
		$gate   = $manager->evaluate_go_live_gate( $report, true );
		$notice = ! empty( $gate['go_live'] ) ? 'migration-live-verified' : 'migration-live-review';
	} else {
		$result                    = erankly_migration_journal()->rollback( $report_id );
		$report['rollback_result'] = array_merge( $result, array( 'requested_at' => gmdate( 'c' ) ) );
		if ( isset( $report['evidence'] ) && is_array( $report['evidence'] ) ) {
			$report['evidence']['rollback'] = erankly_migration_journal()->summary( $report_id );
		}
		$report['verification']['state']           = 'rolled_back';
		$report['verification']['ready_to_switch'] = false;
		$notice                                    = 'expired' === (string) ( $result['status'] ?? '' ) ? 'migration-rollback-expired' : ( 'failed' === (string) ( $result['status'] ?? '' ) ? 'migration-rollback-error' : 'migration-rolled-back' );
		$manager->update_report( $report );
	}

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => $notice,
			'report_id'         => $report_id,
		)
	);
}

/**
 * Redirects back to the Import / Export tab with notice arguments.
 *
 * @param array<string,mixed> $args Query args.
 * @return void
 */
function erankly_import_export_redirect( array $args ): void {
	wp_safe_redirect( add_query_arg( $args, erankly_import_export_url() ) );
	exit;
}

/**
 * Builds the complete export payload.
 *
 * @return array<string,mixed>
 */
function erankly_export_build_data(): array {
	global $wpdb;

	$meta_keys = array_keys( erankly_get_meta_keys() );

	$data = array(
		'plugin'      => 'erankly',
		'format'      => ERANKLY_EXPORT_FORMAT,
		'version'     => ERANKLY_VERSION,
		'exported_at' => gmdate( 'c' ),
		'site_url'    => home_url(),
		'settings'    => erankly_get_plugin_option( ERANKLY_OPTION, array() ),
		'redirects'   => array(),
		'post_meta'   => array(),
		'term_meta'   => array(),
		'user_meta'   => array(),
	);

	// Always export redirects when the table has data, even if the module is off —
	// that data should stay portable regardless of the feature toggle.
	erankly_ensure_redirect_classes_available();

	if ( class_exists( 'ERankly_Redirects_Repository' ) ) {
		$repository = new ERankly_Redirects_Repository();
		// get_all_for_export() returns an empty array when the table does not exist.
		$data['redirects'] = $repository->get_all_for_export();
	}

	$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

	// Post meta.
	$post_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export needs all EasyRankly post meta rows.
		$wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_keys
		),
		ARRAY_A
	);

	if ( is_array( $post_rows ) ) {
		foreach ( $post_rows as $row ) {
			$data['post_meta'][] = array(
				'id'    => (int) $row['post_id'],
				'key'   => (string) $row['meta_key'],
				'value' => maybe_unserialize( $row['meta_value'] ),
			);
		}
	}

	// Term meta.
	$term_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export needs all EasyRankly term meta rows.
		$wpdb->prepare(
			"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_keys
		),
		ARRAY_A
	);

	if ( is_array( $term_rows ) ) {
		foreach ( $term_rows as $row ) {
			$data['term_meta'][] = array(
				'id'    => (int) $row['term_id'],
				'key'   => (string) $row['meta_key'],
				'value' => maybe_unserialize( $row['meta_value'] ),
			);
		}
	}

	$user_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export needs all EasyRankly user meta rows.
		$wpdb->prepare(
			"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_keys
		),
		ARRAY_A
	);

	foreach ( is_array( $user_rows ) ? $user_rows : array() as $row ) {
		$data['user_meta'][] = array(
			'id'    => (int) $row['user_id'],
			'key'   => (string) $row['meta_key'],
			'value' => maybe_unserialize( $row['meta_value'] ),
		);
	}

	// Per-site special page metadata. On Multisite this lives in a dedicated
	// per-site option outside the (network-wide) settings array, so it has to be
	// exported on its own; on single-site it is already part of 'settings'.
	$special_meta = get_option( ERANKLY_SPECIAL_META_OPTION, null );

	if ( is_array( $special_meta ) ) {
		$data['special_meta'] = $special_meta;
	}

	return $data;
}

/**
 * Streams the export payload as a JSON download.
 *
 * @return void
 */
function erankly_export_download(): void {
	$data     = erankly_export_build_data();
	$filename = 'erankly-export-' . gmdate( 'Y-m-d-His' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

/**
 * Restores an EasyRankly export payload.
 *
 * @param array<string,mixed> $data Decoded export data.
 * @return array{settings:int,redirects:int,post_meta:int,term_meta:int,user_meta:int}
 */
function erankly_import_apply( array $data ): array {
	$counts = array(
		'settings'  => 0,
		'redirects' => 0,
		'post_meta' => 0,
		'term_meta' => 0,
		'user_meta' => 0,
	);

	// Settings.
	if ( isset( $data['settings'] ) && is_array( $data['settings'] ) && function_exists( 'erankly_sanitize_settings' ) ) {
		$clean = erankly_sanitize_settings( $data['settings'] );
		erankly_update_plugin_option( ERANKLY_OPTION, $clean );
		$counts['settings'] = 1;
	}

	// Accept per-site special-page metadata on either architecture so imported
	// configuration files remain portable.
	if ( isset( $data['special_meta'] ) && is_array( $data['special_meta'] ) ) {
		erankly_update_special_meta_map( $data['special_meta'] );
		$counts['settings'] = 1;
	}

	// Redirects — restore regardless of whether the module is currently enabled.
	// The redirect table is created on demand so data is never lost.
	if ( ! empty( $data['redirects'] ) && is_array( $data['redirects'] ) ) {
		erankly_ensure_redirect_classes_available();

		if ( class_exists( 'ERankly_Redirects_Repository' ) && class_exists( 'ERankly_Redirects_Normalizer' ) ) {
			// Make sure the DB table exists even if the module was never activated.
			if ( class_exists( 'ERankly_Redirects_Activator' ) ) {
				ERankly_Redirects_Activator::activate();
			}

			$repository = new ERankly_Redirects_Repository();
			$repository->begin_bulk();

			try {
				foreach ( $data['redirects'] as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$redirect = erankly_import_prepare_redirect( $row );

					if ( null === $redirect ) {
						continue;
					}

					if ( in_array( $repository->upsert_by_hash( $redirect ), array( 'created', 'updated' ), true ) ) {
						++$counts['redirects'];
					}
				}
			} finally {
				$repository->end_bulk();
			}
		}
	}

	if ( ! empty( $data['user_meta'] ) && is_array( $data['user_meta'] ) ) {
		$allowed = erankly_get_meta_keys();

		foreach ( $data['user_meta'] as $entry ) {
			$user_id = is_array( $entry ) && isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$key     = is_array( $entry ) && isset( $entry['key'] ) ? (string) $entry['key'] : '';

			if ( $user_id < 1 || ! get_user_by( 'id', $user_id ) || ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			update_user_meta( $user_id, $key, wp_slash( erankly_sanitize_registered_meta( $entry['value'] ?? '', $key ) ) );
			++$counts['user_meta'];
		}
	}

	// Post meta.
	if ( ! empty( $data['post_meta'] ) && is_array( $data['post_meta'] ) ) {
		$allowed = erankly_get_meta_keys();

		foreach ( $data['post_meta'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$post_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$key     = isset( $entry['key'] ) ? (string) $entry['key'] : '';

			if ( $post_id <= 0 || ! isset( $allowed[ $key ] ) || ! get_post( $post_id ) ) {
				continue;
			}

			// wp_slash(): update_post_meta() unslashes its input, which would strip
			// literal backslashes from the imported value.
			update_post_meta( $post_id, $key, wp_slash( erankly_sanitize_registered_meta( $entry['value'] ?? '', $key ) ) );
			++$counts['post_meta'];
		}
	}

	// Term meta.
	if ( ! empty( $data['term_meta'] ) && is_array( $data['term_meta'] ) ) {
		$allowed = erankly_get_meta_keys();

		foreach ( $data['term_meta'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$term_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$key     = isset( $entry['key'] ) ? (string) $entry['key'] : '';

			if ( $term_id <= 0 || ! isset( $allowed[ $key ] ) || ! get_term( $term_id ) instanceof WP_Term ) {
				continue;
			}

			update_term_meta( $term_id, $key, wp_slash( erankly_sanitize_registered_meta( $entry['value'] ?? '', $key ) ) );
			++$counts['term_meta'];
		}
	}

	return $counts;
}

/**
 * Normalizes an exported redirect row into repository-ready data.
 *
 * @param array<string,mixed> $row Redirect row from the export file.
 * @return array<string,mixed>|null
 */
function erankly_import_prepare_redirect( array $row ): ?array {
	$match_type     = isset( $row['match_type'] ) ? sanitize_key( (string) $row['match_type'] ) : '';
	$match_type     = in_array( $match_type, ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true ) ? $match_type : ( ! empty( $row['is_wildcard'] ) ? 'wildcard' : ( ! empty( $row['is_regex'] ) ? 'regex' : 'exact' ) );
	$is_wildcard    = 'wildcard' === $match_type ? 1 : 0;
	$is_regex       = 'regex' === $match_type ? 1 : 0;
	$case_sensitive = ! empty( $row['case_sensitive'] ) ? 1 : 0;
	$trailing_slash = isset( $row['trailing_slash'] ) && in_array( $row['trailing_slash'], ERankly_Redirects_Normalizer::VALID_TRAILING_SLASH_MODES, true ) ? (string) $row['trailing_slash'] : 'ignore';
	$query_mode     = isset( $row['query_mode'] ) && in_array( $row['query_mode'], ERankly_Redirects_Normalizer::VALID_QUERY_MODES, true ) ? (string) $row['query_mode'] : 'ignore';

	$source_path = isset( $row['source_path'] )
		? ERankly_Redirects_Normalizer::normalize_source( sanitize_text_field( (string) $row['source_path'] ), (bool) $is_regex, (bool) $is_wildcard, (bool) $case_sensitive, $trailing_slash )
		: '';

	$status_code = isset( $row['status_code'] ) ? absint( $row['status_code'] ) : 301;

	if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
		$status_code = 301;
	}

	$is_status_only = ERankly_Redirects_Normalizer::is_status_only_code( $status_code );
	$target_url     = isset( $row['target_url'] )
		? ERankly_Redirects_Normalizer::normalize_target_url( (string) $row['target_url'] )
		: '';

	if ( '' === $source_path || ( ! $is_status_only && '' === $target_url ) ) {
		return null;
	}

	$visibility = isset( $row['visibility'] ) ? sanitize_key( (string) $row['visibility'] ) : 'all';

	if ( ! in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ) {
		$visibility = 'all';
	}

	return array(
		'source_path'      => $source_path,
		'source_hash'      => ERankly_Redirects_Normalizer::source_hash( $source_path ),
		'source_query'     => 'exact' === $query_mode ? sanitize_text_field( (string) ( $row['source_query'] ?? '' ) ) : '',
		'target_url'       => $target_url,
		'status_code'      => $status_code,
		'match_type'       => $match_type,
		'is_regex'         => $is_regex,
		'is_wildcard'      => $is_wildcard,
		'case_sensitive'   => $case_sensitive,
		'trailing_slash'   => $trailing_slash,
		'query_mode'       => $query_mode,
		'priority'         => isset( $row['priority'] ) ? intval( $row['priority'] ) : 10,
		'is_active'        => ! empty( $row['is_active'] ) ? 1 : 0,
		'visibility'       => $visibility,
		'required_role'    => isset( $row['required_role'] ) ? sanitize_key( (string) $row['required_role'] ) : '',
		'conditions'       => isset( $row['conditions'] ) ? ( is_string( $row['conditions'] ) ? $row['conditions'] : wp_json_encode( $row['conditions'] ) ) : null,
		'start_at'         => ! empty( $row['start_at'] ) ? sanitize_text_field( (string) $row['start_at'] ) : null,
		'end_at'           => ! empty( $row['end_at'] ) ? sanitize_text_field( (string) $row['end_at'] ) : null,
		'source_plugin'    => isset( $row['source_plugin'] ) ? sanitize_key( (string) $row['source_plugin'] ) : '',
		'source_reference' => isset( $row['source_reference'] ) ? sanitize_text_field( (string) $row['source_reference'] ) : '',
		'migration_id'     => isset( $row['migration_id'] ) ? sanitize_text_field( (string) $row['migration_id'] ) : '',
		'note'             => isset( $row['note'] ) ? sanitize_textarea_field( (string) $row['note'] ) : '',
	);
}

/**
 * Imports useful per-content SEO data from a third-party plugin.
 *
 * Existing EasyRankly values are never overwritten, so the import only fills in
 * fields that are currently empty.
 *
 * @param string $source Source plugin slug.
 * @return array{post_meta:int,term_meta:int}
 */
function erankly_import_third_party( string $source ): array {
	$report = erankly_migration_manager()->run( $source, false );
	$counts = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();

	return array(
		'post_meta' => (int) ( $counts['post_fields_written'] ?? 0 ),
		'term_meta' => (int) ( $counts['term_fields_written'] ?? 0 ),
	);
}

/**
 * Imports post meta from a third-party plugin.
 *
 * @param string                             $source Source plugin: yoast|rankmath.
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_third_party_posts( string $source, array &$counts ): void {
	global $wpdb;

	$source_keys = erankly_third_party_source_keys( $source );

	if ( empty( $source_keys ) ) {
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $source_keys ), '%s' ) );
	$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party post meta rows for migration.
		$wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$source_keys
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	$by_post = array();

	foreach ( $rows as $row ) {
		$by_post[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
	}

	foreach ( $by_post as $post_id => $meta ) {
		if ( ! get_post( $post_id ) ) {
			continue;
		}

		$mapped = 'yoast' === $source
			? erankly_map_yoast_meta( $meta )
			: erankly_map_rankmath_meta( $meta );

		$counts['post_meta'] += erankly_apply_imported_meta( 'post', $post_id, $mapped );
	}
}

/**
 * Imports Yoast term SEO from the wpseo_taxonomy_meta option.
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_yoast_terms( array &$counts ): void {
	$taxonomy_meta = get_option( 'wpseo_taxonomy_meta' );

	if ( ! is_array( $taxonomy_meta ) ) {
		return;
	}

	foreach ( $taxonomy_meta as $terms ) {
		if ( ! is_array( $terms ) ) {
			continue;
		}

		foreach ( $terms as $term_id => $meta ) {
			$term_id = absint( $term_id );

			if ( $term_id <= 0 || ! is_array( $meta ) || ! get_term( $term_id ) instanceof WP_Term ) {
				continue;
			}

			$mapped               = erankly_map_yoast_meta( $meta, true );
			$counts['term_meta'] += erankly_apply_imported_meta( 'term', $term_id, $mapped );
		}
	}
}

/**
 * Imports Rank Math term SEO from term meta.
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_rankmath_terms( array &$counts ): void {
	global $wpdb;

	$source_keys = erankly_third_party_source_keys( 'rankmath' );

	if ( empty( $source_keys ) ) {
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $source_keys ), '%s' ) );
	$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party term meta rows for migration.
		$wpdb->prepare(
			"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$source_keys
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	$by_term = array();

	foreach ( $rows as $row ) {
		$by_term[ (int) $row['term_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
	}

	foreach ( $by_term as $term_id => $meta ) {
		if ( ! get_term( $term_id ) instanceof WP_Term ) {
			continue;
		}

		$mapped               = erankly_map_rankmath_meta( $meta );
		$counts['term_meta'] += erankly_apply_imported_meta( 'term', $term_id, $mapped );
	}
}

/**
 * Returns whether a custom database table exists.
 *
 * @param string $table Fully-qualified table name (including prefix).
 * @return bool
 */
function erankly_table_exists( string $table ): bool {
	global $wpdb;

	// esc_like(): underscores in the table name are LIKE wildcards otherwise, which
	// could match a differently-named table and skew the exact comparison below.
	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence check for an optional third-party table.
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
	);

	return $found === $table;
}

/**
 * Imports All in One SEO post data from the wp_aioseo_posts custom table.
 *
 * Unlike Yoast and Rank Math, AIOSEO v4+ stores per-post SEO data in its own
 * table rather than in postmeta.
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_aioseo_posts( array &$counts ): void {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'aioseo_posts' );

	if ( ! erankly_table_exists( $table ) ) {
		return;
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party rows for migration.
		"SELECT post_id, title, description, canonical_url, og_title, og_description, og_image_custom_url, twitter_title, twitter_description, robots_default, robots_noindex, robots_nofollow, robots_noarchive FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted $wpdb prefix.
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			continue;
		}

		$mapped               = erankly_map_aioseo_meta( $row );
		$counts['post_meta'] += erankly_apply_imported_meta( 'post', $post_id, $mapped );
	}
}

/**
 * Imports All in One SEO term data from the wp_aioseo_terms custom table.
 *
 * The terms table only exists on recent AIOSEO versions, so its absence is
 * treated as "nothing to import".
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_aioseo_terms( array &$counts ): void {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'aioseo_terms' );

	if ( ! erankly_table_exists( $table ) ) {
		return;
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party rows for migration.
		"SELECT term_id, title, description, canonical_url, og_title, og_description, og_image_custom_url, twitter_title, twitter_description, robots_default, robots_noindex, robots_nofollow, robots_noarchive FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted $wpdb prefix.
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$term_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

		if ( $term_id <= 0 || ! get_term( $term_id ) instanceof WP_Term ) {
			continue;
		}

		$mapped               = erankly_map_aioseo_meta( $row );
		$counts['term_meta'] += erankly_apply_imported_meta( 'term', $term_id, $mapped );
	}
}

/**
 * Maps an All in One SEO post/term row to EasyRankly meta.
 *
 * @param array<string,mixed> $row Source row from wp_aioseo_posts/wp_aioseo_terms.
 * @return array<string,mixed>
 */
function erankly_map_aioseo_meta( array $row ): array {
	$get = static function ( string $key ) use ( $row ): string {
		return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	};

	$mapped = array(
		'_erankly_title'               => erankly_import_convert_variables( $get( 'title' ), 'aioseo' ),
		'_erankly_description'         => erankly_import_convert_variables( $get( 'description' ), 'aioseo' ),
		'_erankly_canonical'           => $get( 'canonical_url' ),
		'_erankly_og_title'            => erankly_import_convert_variables( $get( 'og_title' ), 'aioseo' ),
		'_erankly_og_description'      => erankly_import_convert_variables( $get( 'og_description' ), 'aioseo' ),
		'_erankly_social_image_url'    => $get( 'og_image_custom_url' ),
		'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'twitter_title' ), 'aioseo' ),
		'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'twitter_description' ), 'aioseo' ),
	);

	// robots_default = 1 means "use the site defaults", so the per-row flags are
	// only meaningful when the post/term has its own custom robots settings.
	if ( '1' !== $get( 'robots_default' ) ) {
		if ( '1' === $get( 'robots_noindex' ) ) {
			$mapped['_erankly_noindex'] = true;
		}

		if ( '1' === $get( 'robots_nofollow' ) ) {
			$mapped['_erankly_nofollow'] = true;
		}

		if ( '1' === $get( 'robots_noarchive' ) ) {
			$mapped['_erankly_noarchive'] = true;
		}
	}

	return $mapped;
}

/**
 * Writes mapped meta values without overwriting existing EasyRankly data.
 *
 * @param string              $object_type 'post' or 'term'.
 * @param int                 $object_id   Object ID.
 * @param array<string,mixed> $mapped      EasyRankly meta key => value.
 * @return int Number of fields written.
 */
function erankly_apply_imported_meta( string $object_type, int $object_id, array $mapped ): int {
	$written = 0;

	foreach ( $mapped as $key => $value ) {
		// Skip empty strings, nulls, and zero image IDs; keep boolean true flags.
		if ( true !== $value && empty( $value ) ) {
			continue;
		}

		$existing = 'post' === $object_type
			? get_post_meta( $object_id, $key, true )
			: get_term_meta( $object_id, $key, true );

		if ( '' !== $existing && null !== $existing && false !== $existing ) {
			continue;
		}

		$clean = erankly_sanitize_registered_meta( $value, $key );

		if ( '' === $clean || false === $clean ) {
			continue;
		}

		// wp_slash(): update_*_meta() unslashes its input, which would strip
		// literal backslashes from the migrated value.
		if ( 'post' === $object_type ) {
			update_post_meta( $object_id, $key, wp_slash( $clean ) );
		} else {
			update_term_meta( $object_id, $key, wp_slash( $clean ) );
		}

		++$written;
	}

	return $written;
}

/**
 * Returns the source meta keys read from a third-party plugin.
 *
 * @param string $source Source plugin: yoast|rankmath.
 * @return array<int,string>
 */
function erankly_third_party_source_keys( string $source ): array {
	if ( 'yoast' === $source ) {
		return array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_bctitle',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_meta-robots-adv',
		);
	}

	return array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_canonical_url',
		'rank_math_breadcrumb_title',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_facebook_image',
		'rank_math_facebook_image_id',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
		'rank_math_twitter_image_id',
		'rank_math_robots',
	);
}

/**
 * Maps Yoast meta (post meta keys or wpseo_taxonomy_meta keys) to EasyRankly meta.
 *
 * @param array<string,mixed> $meta    Source meta.
 * @param bool                $is_term Whether the keys use the wpseo_taxonomy_meta short form.
 * @return array<string,mixed>
 */
function erankly_map_yoast_meta( array $meta, bool $is_term = false ): array {
	// Term meta in wpseo_taxonomy_meta uses short keys (wpseo_title); post meta
	// uses the full prefix (_yoast_wpseo_title). Normalize to the short form.
	$prefix = $is_term ? 'wpseo_' : '_yoast_wpseo_';
	$get    = static function ( string $key ) use ( $meta, $prefix ): string {
		return isset( $meta[ $prefix . $key ] ) ? (string) $meta[ $prefix . $key ] : '';
	};

	$mapped = array(
		'_erankly_title'               => erankly_import_convert_variables( $get( 'title' ), 'yoast' ),
		'_erankly_description'         => erankly_import_convert_variables( $is_term ? $get( 'desc' ) : $get( 'metadesc' ), 'yoast' ),
		'_erankly_canonical'           => $get( 'canonical' ),
		'_erankly_breadcrumb_name'     => $get( 'bctitle' ),
		'_erankly_og_title'            => erankly_import_convert_variables( $get( 'opengraph-title' ), 'yoast' ),
		'_erankly_og_description'      => erankly_import_convert_variables( $get( 'opengraph-description' ), 'yoast' ),
		'_erankly_social_image_url'    => $get( 'opengraph-image' ),
		'_erankly_og_image_id'         => absint( $get( 'opengraph-image-id' ) ),
		'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'twitter-title' ), 'yoast' ),
		'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'twitter-description' ), 'yoast' ),
	);

	// Robots: Yoast stores "1" for noindex and "1" for nofollow; the advanced
	// field is a comma list that may contain "noarchive".
	if ( '1' === $get( 'meta-robots-noindex' ) || 'noindex' === $get( 'noindex' ) ) {
		$mapped['_erankly_noindex'] = true;
	}

	if ( '1' === $get( 'meta-robots-nofollow' ) ) {
		$mapped['_erankly_nofollow'] = true;
	}

	if ( false !== strpos( $get( 'meta-robots-adv' ), 'noarchive' ) ) {
		$mapped['_erankly_noarchive'] = true;
	}

	return $mapped;
}

/**
 * Maps Rank Math post/term meta to EasyRankly meta.
 *
 * @param array<string,mixed> $meta Source meta.
 * @return array<string,mixed>
 */
function erankly_map_rankmath_meta( array $meta ): array {
	$get = static function ( string $key ) use ( $meta ): string {
		return isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
	};

	$mapped = array(
		'_erankly_title'               => erankly_import_convert_variables( $get( 'rank_math_title' ), 'rankmath' ),
		'_erankly_description'         => erankly_import_convert_variables( $get( 'rank_math_description' ), 'rankmath' ),
		'_erankly_canonical'           => $get( 'rank_math_canonical_url' ),
		'_erankly_breadcrumb_name'     => $get( 'rank_math_breadcrumb_title' ),
		'_erankly_og_title'            => erankly_import_convert_variables( $get( 'rank_math_facebook_title' ), 'rankmath' ),
		'_erankly_og_description'      => erankly_import_convert_variables( $get( 'rank_math_facebook_description' ), 'rankmath' ),
		'_erankly_social_image_url'    => $get( 'rank_math_facebook_image' ),
		'_erankly_og_image_id'         => absint( $get( 'rank_math_facebook_image_id' ) ),
		'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'rank_math_twitter_title' ), 'rankmath' ),
		'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'rank_math_twitter_description' ), 'rankmath' ),
		'_erankly_twitter_image_id'    => absint( $get( 'rank_math_twitter_image_id' ) ),
	);

	// Robots is a serialized array such as ["noindex","nofollow","noarchive"].
	$robots = maybe_unserialize( $get( 'rank_math_robots' ) );

	if ( is_array( $robots ) ) {
		if ( in_array( 'noindex', $robots, true ) ) {
			$mapped['_erankly_noindex'] = true;
		}

		if ( in_array( 'nofollow', $robots, true ) ) {
			$mapped['_erankly_nofollow'] = true;
		}

		if ( in_array( 'noarchive', $robots, true ) ) {
			$mapped['_erankly_noarchive'] = true;
		}
	}

	return $mapped;
}

/**
 * Collects bounded, deduplicated variable-conversion diagnostics for a run.
 *
 * @param array<string,string>|null $warning Warning to add.
 * @param bool                      $reset   Whether to clear prior diagnostics.
 * @return array<int,array<string,string>>
 */
function erankly_import_variable_diagnostics( ?array $warning = null, bool $reset = false ): array {
	static $warnings = array();

	if ( $reset ) {
		$warnings = array();
	}

	if ( is_array( $warning ) && count( $warnings ) < 100 ) {
		$key = sanitize_key( (string) ( $warning['reference'] ?? '' ) );
		if ( '' !== $key && ! isset( $warnings[ $key ] ) ) {
			$warnings[ $key ] = array(
				'code'      => 'unsupported_template_variable',
				'message'   => sanitize_text_field( (string) ( $warning['message'] ?? '' ) ),
				'reference' => sanitize_text_field( (string) ( $warning['reference'] ?? '' ) ),
			);
		}
	}

	return array_values( $warnings );
}

/**
 * Converts third-party template variables to EasyRankly's {{token}} syntax.
 *
 * Known variables are mapped to their EasyRankly equivalents; unknown variables
 * are stripped so imported templates never render raw placeholders.
 *
 * @param string $value  Raw template string.
 * @param string $source Source plugin: yoast|rankmath|aioseo|seopress.
 * @return string
 */
function erankly_import_convert_variables( string $value, string $source ): string {
	$value = (string) $value;

	if ( '' === $value ) {
		return '';
	}

	$map = array(
		'title'                => '{{post_title}}',
		'seo_title'            => '{{post_title}}',
		'post_title'           => '{{post_title}}',
		'sitename'             => '{{site_name}}',
		'sitetitle'            => '{{site_name}}',
		'site_name'            => '{{site_name}}',
		'site_title'           => '{{site_name}}',
		'sitedesc'             => '{{site_description}}',
		'tagline'              => '{{site_description}}',
		'excerpt'              => '{{post_excerpt}}',
		'excerpt_only'         => '{{post_excerpt}}',
		'post_excerpt'         => '{{post_excerpt}}',
		'seo_description'      => '{{post_excerpt}}',
		'post_content'         => '{{post_content}}',
		'post_thumbnail'       => '{{featured_image}}',
		'post_thumbnail_url'   => '{{featured_image}}',
		'sep'                  => '-',
		'separator_sa'         => '-',
		'page'                 => '{{page_number}}',
		'pagenumber'           => '{{page_number}}',
		'pagetotal'            => '{{max_pages}}',
		'primary_category'     => '{{post_categories}}',
		'category'             => '{{post_categories}}',
		'categories'           => '{{post_categories}}',
		'post_category'        => '{{post_categories}}',
		'tag'                  => '{{post_tags}}',
		'tags'                 => '{{post_tags}}',
		'post_tag'             => '{{post_tags}}',
		'term'                 => '{{term_name}}',
		'term_title'           => '{{term_name}}',
		'term_description'     => '{{term_description}}',
		'taxonomy_description' => '{{term_description}}',
		'category_description' => '{{term_description}}',
		'tag_description'      => '{{term_description}}',
		'name'                 => '{{post_author}}',
		'post_author'          => '{{post_author}}',
		'date'                 => '{{post_date}}',
		'post_date'            => '{{post_date}}',
		'post_year'            => '{{post_year}}',
		'post_month'           => '{{post_month}}',
		'post_day'             => '{{post_day}}',
		'modified'             => '{{post_modified_date}}',
		'post_modified_date'   => '{{post_modified_date}}',
		'url'                  => '{{post_url}}',
		'permalink'            => '{{post_url}}',
		'currentyear'          => '{{current_year}}',
		'current_year'         => '{{current_year}}',
		'currentmonth'         => '{{current_month}}',
		'current_month'        => '{{current_month}}',
		'currentday'           => '{{current_day}}',
		'current_day'          => '{{current_day}}',
		'currentdate'          => '{{current_date}}',
		'current_date'         => '{{current_date}}',
		'pt_single'            => '{{post_type_name}}',
		'pt_plural'            => '{{post_type_name}}',
		'post_type'            => '{{post_type_name}}',
		'searchphrase'         => '{{search_query}}',
		'search_term'          => '{{search_query}}',
		'search_keywords'      => '{{search_query}}',
		// All in One SEO (#tag) aliases.
		'tax_name'             => '{{term_name}}',
		'taxonomy_title'       => '{{term_name}}',
		'author_name'          => '{{post_author}}',
	);

	switch ( $source ) {
		case 'yoast':
		case 'seopress':
			$pattern = '/%%([^%]+)%%/';
			break;
		case 'aioseo':
			$pattern = '/#([a-z0-9_]+)/i';
			break;
		default:
			$pattern = '/%([^%\s]+)%/';
			break;
	}
	$replaced = preg_replace_callback(
		$pattern,
		static function ( array $matches ) use ( $map, $source ): string {
			// Rank Math allows arguments, e.g. %customfield(key)% — drop them.
			$name = strtolower( trim( explode( '(', $matches[1] )[0] ) );

			if ( isset( $map[ $name ] ) ) {
				return $map[ $name ];
			}

			erankly_import_variable_diagnostics(
				array(
					'message'   => sprintf(
						'aioseo' === $source ? 'Unrecognized AIOSEO hash token was preserved for review: %s.' : 'Unsupported %1$s template variable was removed: %2$s.',
						'aioseo' === $source ? sanitize_text_field( (string) $matches[0] ) : sanitize_key( $source ),
						sanitize_text_field( (string) $matches[0] )
					),
					'reference' => sanitize_key( $source ) . ':' . sanitize_key( $name ),
				)
			);

			return 'aioseo' === $source ? (string) $matches[0] : '';
		},
		$value
	);

	$replaced = is_string( $replaced ) ? $replaced : $value;

	// Collapse whitespace and trim stray separators left by removed variables.
	$replaced = preg_replace( '/\s{2,}/', ' ', $replaced ) ?? $replaced;
	$replaced = trim( $replaced );
	$replaced = trim( $replaced, ' -|' );

	return trim( $replaced );
}

/**
 * Renders the focused second upload required after an official-export preview.
 *
 * @param array<string,mixed> $report     Reviewed preview report.
 * @param string              $action_url Form action.
 * @param int                 $upload_max Maximum upload size.
 * @return void
 */
function erankly_migration_render_reviewed_export_upload( array $report, string $action_url, int $upload_max ): void {
	$source_label = sanitize_text_field( (string) ( $report['source_label'] ?? $report['source'] ?? '' ) );
	?>
	<div id="erankly-migration-export-form" class="erankly-settings-section">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Import the reviewed file', 'easyrankly' ); ?></h3>
		<section class="erankly-io-section erankly-card">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: source plugin name, 2: maximum upload size. */
						__( 'Upload the same official %1$s export that you just reviewed. EasyRankly will validate it again before importing. Maximum size: %2$s.', 'easyrankly' ),
						$source_label,
						size_format( $upload_max )
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form">
				<?php wp_nonce_field( 'erankly_io_third_party_export' ); ?>
				<input type="hidden" name="erankly_io_action" value="migrate-export">
				<input type="hidden" name="erankly_migration_export_source" value="<?php echo esc_attr( (string) ( $report['source'] ?? '' ) ); ?>">
				<input type="hidden" name="erankly_migration_mode" value="import">
				<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-reviewed-migration-export-file">
					<input type="file" id="erankly-reviewed-migration-export-file" name="erankly_migration_export_file" accept=".csv,.json,text/csv,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
					<span class="erankly-dropzone-icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M12 15.5V4M12 4L8 8M12 4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M4 15.5v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</span>
					<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
						<strong><?php esc_html_e( 'Choose the reviewed export', 'easyrankly' ); ?></strong>
						<?php esc_html_e( 'or drag and drop it here', 'easyrankly' ); ?>
					</span>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import the reviewed file', 'easyrankly' ); ?></button>
			</form>
		</section>
	</div>
	<?php
}

/**
 * Renders the Import / Export settings tab.
 *
 * @return void
 */
function erankly_import_export_render_panel(): void {
	// On Multisite the settings option is a network option; mirror the write-access gate.
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	ERankly_Migration_Upload_Store::prune_stale();

	$export_url = wp_nonce_url( add_query_arg( 'erankly_io_action', 'export', erankly_import_export_url() ), 'erankly_io_export' );
	$action_url = erankly_import_export_url();

	// Third-party import sources, in the order they appear in the dropdown.
	$sources         = array();
	$source_profiles = array();
	foreach ( erankly_migration_manager()->adapters() as $key => $adapter ) {
		$profile                 = $adapter->profile();
		$source_profiles[ $key ] = $profile;
		$edition                 = strtoupper( (string) ( $profile['edition'] ?? '' ) );
		$version                 = (string) ( $profile['version'] ?? '' );
		$sources[ $key ]         = trim( $adapter->label() . ( '' !== $edition ? ' ' . $edition : '' ) . ( '' !== $version ? ' ' . $version : '' ) );
	}

	$source_availability = array();
	$default_source      = '';

	foreach ( $sources as $key => $label ) {
		$has_data                    = erankly_third_party_data_exists( $key );
		$available                   = $has_data && 'supported' === (string) ( $source_profiles[ $key ]['storage_status'] ?? '' );
		$source_availability[ $key ] = $available;
		if ( $available && '' === $default_source ) {
			$default_source = $key;
		}
	}

	$has_any_source         = '' !== $default_source;
	$has_unsupported_source = false;
	foreach ( $source_profiles as $profile ) {
		if ( 'unsupported' === (string) ( $profile['storage_status'] ?? '' ) ) {
			$has_unsupported_source = true;
			break;
		}
	}
	$active_job        = erankly_migration_job_runner()->active_job();
	$import_max        = erankly_import_export_max_bytes();
	$upload_max        = max( 1024, (int) apply_filters( 'erankly_migration_export_max_bytes', 100 * MB_IN_BYTES ) );
	$focused_report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report context.
	$focused_report    = '' !== $focused_report_id ? erankly_migration_manager()->get_report( $focused_report_id ) : null;

	erankly_import_export_render_notice();
	erankly_migration_render_report();
	if ( is_array( $active_job ) ) {
		return;
	}
	if ( is_array( $focused_report ) ) {
		$focused_profile      = is_array( $focused_report['source_profile'] ?? null ) ? $focused_report['source_profile'] : array();
		$focused_verification = is_array( $focused_report['verification'] ?? null ) ? $focused_report['verification'] : array();
		if ( 'preview' === (string) ( $focused_report['mode'] ?? '' ) && 'official_export' === (string) ( $focused_profile['mode'] ?? '' ) && ! empty( $focused_verification['ready_to_import'] ) ) {
			erankly_migration_render_reviewed_export_upload( $focused_report, $action_url, $upload_max );
		}
		?>
		<p class="erankly-migration-back"><a href="<?php echo esc_url( erankly_import_export_url() ); ?>">&larr; <?php esc_html_e( 'Back to all import and export tools', 'easyrankly' ); ?></a></p>
		<?php
		return;
	}
	?>
	<div class="erankly-io">
		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Export', 'easyrankly' ); ?></h3>
			<section class="erankly-io-section erankly-card">
				<p class="description"><?php esc_html_e( 'Download a JSON backup of your EasyRankly settings, redirects and SEO metadata. Keep it as a backup or import it on another site.', 'easyrankly' ); ?></p>
				<?php if ( is_multisite() ) : ?>
					<p class="description"><?php esc_html_e( 'On this network the file holds the network-wide settings plus this primary site\'s content (redirects, post/term metadata, special page defaults) — not a whole-network export of every site.', 'easyrankly' ); ?></p>
				<?php endif; ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export data', 'easyrankly' ); ?></a></p>
			</section>
		</div>

		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Import', 'easyrankly' ); ?></h3>
			<section class="erankly-io-section erankly-card">
				<p class="description"><?php esc_html_e( 'Upload a JSON file previously exported by EasyRankly. Settings, redirects and special page defaults are replaced; post and term metadata is matched by ID and overwritten.', 'easyrankly' ); ?></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: maximum safe complete-import size. */
						esc_html__( 'For memory safety, complete JSON imports are limited to %s on this request.', 'easyrankly' ),
						esc_html( size_format( $import_max ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form">
					<?php wp_nonce_field( 'erankly_io_import' ); ?>
					<input type="hidden" name="erankly_io_action" value="import">
					<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( (string) $import_max ); ?>">
					<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-import-file">
						<input type="file" id="erankly-import-file" name="erankly_import_file" accept=".json,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
						<span class="erankly-dropzone-icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 15.5V4M12 4L8 8M12 4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M4 15.5v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
						<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
							<strong><?php esc_html_e( 'Click to choose a file', 'easyrankly' ); ?></strong>
							<?php esc_html_e( 'or drag and drop a JSON file here', 'easyrankly' ); ?>
						</span>
					</label>
					<?php submit_button( __( 'Import file', 'easyrankly' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		</div>

		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Import from other plugins', 'easyrankly' ); ?></h3>
			<section class="erankly-io-section erankly-card">
				<p class="description"><?php esc_html_e( 'Migrate Free and PRO data: titles, descriptions, canonicals, separate social images, robots directives, keyphrases, primary terms, schemas and redirects. Existing EasyRankly values and unrelated redirects are preserved and reported as conflicts.', 'easyrankly' ); ?></p>
				<?php if ( $has_unsupported_source ) : ?>
					<p class="notice notice-warning inline"><span><?php esc_html_e( 'SEO source data was detected with an unrecognized version or storage signature. EasyRankly will not guess: use a certified official export or update the adapter before migrating.', 'easyrankly' ); ?></span></p>
				<?php endif; ?>

			<?php if ( ! is_array( $active_job ) ) : ?>
				<h4><?php esc_html_e( 'From the installed database', 'easyrankly' ); ?></h4>
				<?php if ( $has_any_source ) : ?>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="erankly-io-third-party">
						<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
						<input type="hidden" name="erankly_io_action" value="migrate">
						<label class="screen-reader-text" for="erankly-io-source"><?php esc_html_e( 'Source plugin', 'easyrankly' ); ?></label>
						<select name="erankly_migration_source" id="erankly-io-source">
							<?php foreach ( $sources as $key => $label ) : ?>
								<?php if ( $source_availability[ $key ] ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default_source ); ?>><?php echo esc_html( $label ); ?></option>
								<?php else : ?>
									<option value="<?php echo esc_attr( $key ); ?>" disabled>
										<?php
										if ( 'unsupported' === (string) ( $source_profiles[ $key ]['storage_status'] ?? '' ) ) {
											/* translators: %s: source plugin name. */
											echo esc_html( sprintf( __( '%s: unsupported storage signature', 'easyrankly' ), $label ) );
										} else {
											/* translators: %s: source plugin name. */
											echo esc_html( sprintf( __( '%s: no data found', 'easyrankly' ), $label ) );
										}
										?>
									</option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-primary" name="erankly_migration_mode" value="preview"><?php esc_html_e( 'Preview migration', 'easyrankly' ); ?></button>
						<p class="description"><?php esc_html_e( 'The preview scans everything without writing data. When it is ready, the migration assistant will offer the reviewed import as the next step.', 'easyrankly' ); ?></p>
					</form>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No certified source-plugin database data was found. You can use an official export instead.', 'easyrankly' ); ?></p>
				<?php endif; ?>

				<h4><?php esc_html_e( 'From an official export file', 'easyrankly' ); ?></h4>
				<p class="description">
					<?php
					printf(
						/* translators: %s: maximum upload size. */
						esc_html__( 'Use an official CSV or JSON export when the original plugin is unavailable or its database signature is unsupported. EasyRankly validates the exact format and stores the file privately only until the job ends. Maximum size: %s.', 'easyrankly' ),
						esc_html( size_format( $upload_max ) )
					);
					?>
				</p>
				<form id="erankly-migration-export-form" method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form erankly-io-third-party">
					<?php wp_nonce_field( 'erankly_io_third_party_export' ); ?>
					<input type="hidden" name="erankly_io_action" value="migrate-export">
					<label for="erankly-migration-export-source"><?php esc_html_e( 'Export source', 'easyrankly' ); ?></label>
					<select name="erankly_migration_export_source" id="erankly-migration-export-source">
						<option value="auto"><?php esc_html_e( 'Detect automatically', 'easyrankly' ); ?></option>
						<?php foreach ( erankly_migration_manager()->adapters() as $key => $adapter ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $adapter->label() ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-migration-export-file">
						<input type="file" id="erankly-migration-export-file" name="erankly_migration_export_file" accept=".csv,.json,text/csv,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
						<span class="erankly-dropzone-icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 15.5V4M12 4L8 8M12 4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M4 15.5v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
						<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
							<strong><?php esc_html_e( 'Choose an official export', 'easyrankly' ); ?></strong>
							<?php esc_html_e( 'or drag and drop a CSV/JSON file here', 'easyrankly' ); ?>
						</span>
					</label>
					<button type="submit" class="button button-primary" name="erankly_migration_mode" value="preview"><?php esc_html_e( 'Preview file migration', 'easyrankly' ); ?></button>
					<p class="description"><?php esc_html_e( 'The private upload is deleted after preview. If every check passes, the assistant will ask you to upload the same file once more for the reviewed import.', 'easyrankly' ); ?></p>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'A migration is already active. Its checkpoint, controls and live counters are shown above; finish or cancel it before starting another source.', 'easyrankly' ); ?></p>
			<?php endif; ?>
			</section>
		</div>
	</div>
	<?php
}

/**
 * Renders the import/export admin notice for the current request.
 *
 * @return void
 */
function erankly_import_export_render_notice(): void {
	$notice = isset( $_GET['erankly_io_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_io_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $notice ) {
		return;
	}

	$report_id        = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report context.
	$report           = '' !== $report_id ? erankly_migration_manager()->get_report( $report_id ) : null;
	$reported_notices = array(
		'migration-started',
		'migration-running',
		'migration',
		'migration-partial',
		'migration-cancelled',
		'migration-error',
		'migration-live-verified',
		'migration-live-review',
		'migration-gate-blocked',
		'migration-source-still-active',
		'migration-rolled-back',
		'migration-rollback-expired',
		'migration-rollback-error',
		'migration-evidence-error',
	);
	if ( is_array( $report ) && ! is_array( erankly_migration_job_runner()->active_job() ) && in_array( $notice, $reported_notices, true ) ) {
		// A terminal report is the single source of user-facing status. This
		// also prevents a stale migration-started query argument from claiming
		// that a completed job is still queued.
		return;
	}

	if ( 'nonce' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed. Please try again.', 'easyrankly' ) . '</p></div>';
		return;
	}

	if ( 'invalid' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The file could not be imported. Please upload a valid EasyRankly export file.', 'easyrankly' ) . '</p></div>';
		return;
	}

	if ( 'too-large' === $notice ) {
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: maximum safe complete-import size. */
					__( 'The EasyRankly export exceeds the safe import limit of %s and was rejected before being read into memory.', 'easyrankly' ),
					size_format( erankly_import_export_max_bytes() )
				)
			)
		);
		return;
	}

	if ( 'too-complex' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The EasyRankly export is too structurally complex for the available PHP memory and was rejected before JSON decoding.', 'easyrankly' ) . '</p></div>';
		return;
	}

	$evidence_notices = array(
		'migration-live-verified'       => array( 'notice-success', __( 'Live verification passed: the sampled SEO output, redirects, robots.txt and sitemap are equivalent after the provider change.', 'easyrankly' ) ),
		'migration-live-review'         => array( 'notice-warning', __( 'Live verification found differences or unreachable samples. Review the report before deleting the source plugin.', 'easyrankly' ) ),
		'migration-gate-blocked'        => array( 'notice-error', __( 'Live verification is unavailable because the go-live preflight gate is blocked. Resolve every blocking check and run a fresh migration before cutover.', 'easyrankly' ) ),
		'migration-source-still-active' => array( 'notice-warning', __( 'Live verification was not run because another SEO plugin still owns the frontend output. Deactivate the migrated source plugin, purge caches, then verify again.', 'easyrankly' ) ),
		'migration-rolled-back'         => array( 'notice-success', __( 'Conditional rollback finished. Later manual edits were preserved and are listed separately in the report.', 'easyrankly' ) ),
		'migration-rollback-expired'    => array( 'notice-error', __( 'The rollback safety window has expired. No value was changed.', 'easyrankly' ) ),
		'migration-rollback-error'      => array( 'notice-error', __( 'Rollback could not be completed. Review the journal summary before making manual changes.', 'easyrankly' ) ),
		'migration-evidence-error'      => array( 'notice-error', __( 'The requested migration evidence action is not valid for this report.', 'easyrankly' ) ),
	);
	if ( isset( $evidence_notices[ $notice ] ) ) {
		echo '<div class="notice ' . esc_attr( $evidence_notices[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $evidence_notices[ $notice ][1] ) . '</p></div>';
		return;
	}

	if ( 'migration-export-invalid' === $notice ) {
		$error    = isset( $_GET['erankly_upload_error'] ) ? sanitize_key( wp_unslash( $_GET['erankly_upload_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection from a fixed message map.
		$messages = array(
			'upload_too_large'                   => __( 'The official export exceeds the permitted upload size.', 'easyrankly' ),
			'source_mismatch'                    => __( 'The selected source does not match the certified signature of this export file.', 'easyrankly' ),
			'ambiguous_export_signature'         => __( 'The export signature is ambiguous. Select the source plugin explicitly and try again.', 'easyrankly' ),
			'private_storage_unavailable'        => __( 'A private non-public temporary directory is not available. Configure a writable system temporary directory before uploading source SEO data.', 'easyrankly' ),
			'private_storage_write_failed'       => __( 'The official export could not be stored in the private temporary directory.', 'easyrankly' ),
			'private_storage_permissions_failed' => __( 'The private temporary file could not be restricted to the current server account. No migration was started and the upload was removed.', 'easyrankly' ),
			'unsupported_extension'              => __( 'Only official CSV and JSON exports are accepted.', 'easyrankly' ),
			'unsupported_export_signature'       => __( 'The file does not match a certified Yoast, Rank Math, AIOSEO or SEOPress export signature.', 'easyrankly' ),
		);
		$message  = $messages[ $error ] ?? __( 'The official export upload failed validation. No migration was started and no file was retained.', 'easyrankly' );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	if ( in_array( $notice, array( 'migration-started', 'migration-running', 'migration-source-unsupported', 'migration-start-error', 'migration-action-error' ), true ) ) {
		if ( 'migration-started' === $notice ) {
			$message = __( 'Migration queued. It will continue in restart-safe background batches; you can leave this page.', 'easyrankly' );
			$class   = 'notice-success';
		} elseif ( 'migration-running' === $notice ) {
			$message = __( 'The migration is still running from its latest saved checkpoint.', 'easyrankly' );
			$class   = 'notice-info';
		} elseif ( 'migration-source-unsupported' === $notice ) {
			$message = __( 'Migration blocked: the detected source version or database signature is not certified. No data was written; use a supported official export or update EasyRankly.', 'easyrankly' );
			$class   = 'notice-error';
		} elseif ( 'migration-start-error' === $notice ) {
			$message = __( 'The migration could not be queued. Check database permissions and source-plugin data, then try again.', 'easyrankly' );
			$class   = 'notice-error';
		} else {
			$message = __( 'The migration command could not be saved. No unchecked write was performed; review the database/PHP log and retry from the saved checkpoint.', 'easyrankly' );
			$class   = 'notice-error';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	if ( in_array( $notice, array( 'migration', 'migration-partial', 'migration-cancelled', 'migration-error' ), true ) ) {
		$report_id    = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report lookup.
		$report       = erankly_migration_manager()->get_report( $report_id );
		$is_error     = 'migration-error' === $notice || ! is_array( $report );
		$is_partial   = 'migration-partial' === $notice;
		$is_cancelled = 'migration-cancelled' === $notice;
		if ( $is_error ) {
			$message = __( 'The migration could not be completed. Review the report for details.', 'easyrankly' );
		} elseif ( $is_cancelled ) {
			$message = __( 'Migration cancelled at its saved checkpoint. Values already written were kept; review the final report.', 'easyrankly' );
		} elseif ( $is_partial ) {
			$message = __( 'Migration completed with write errors. Existing EasyRankly data was preserved; review the report before switching SEO plugins.', 'easyrankly' );
		} elseif ( 'preview' === (string) $report['mode'] ) {
			$message = __( 'Migration preview complete. No EasyRankly data was changed.', 'easyrankly' );
		} else {
			$message = __( 'Migration complete. Existing EasyRankly data was preserved.', 'easyrankly' );
		}
		$notice_class = $is_error ? 'notice-error' : ( $is_partial || $is_cancelled ? 'notice-warning' : 'notice-success' );
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	$post_meta = isset( $_GET['er_post_meta'] ) ? absint( $_GET['er_post_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$term_meta = isset( $_GET['er_term_meta'] ) ? absint( $_GET['er_term_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$user_meta = isset( $_GET['er_user_meta'] ) ? absint( $_GET['er_user_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'imported' === $notice ) {
		$settings  = isset( $_GET['er_settings'] ) ? absint( $_GET['er_settings'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirects = isset( $_GET['er_redirects'] ) ? absint( $_GET['er_redirects'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message   = sprintf(
			/* translators: 1: settings count, 2: redirects count, 3: post meta count, 4: term meta count, 5: user meta count. */
			__( 'Import complete. Settings: %1$d. Redirects: %2$d. Post metadata: %3$d. Term metadata: %4$d. User metadata: %5$d.', 'easyrankly' ),
			$settings,
			$redirects,
			$post_meta,
			$term_meta,
			$user_meta
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	$source_labels = array(
		'yoast'    => __( 'Yoast SEO', 'easyrankly' ),
		'rankmath' => __( 'Rank Math', 'easyrankly' ),
		'aioseo'   => __( 'All in One SEO', 'easyrankly' ),
		'seopress' => __( 'SEOPress', 'easyrankly' ),
	);

	if ( isset( $source_labels[ $notice ] ) ) {
		$label   = $source_labels[ $notice ];
		$message = sprintf(
			/* translators: 1: source plugin name, 2: post meta count, 3: term meta count. */
			__( 'Imported from %1$s. Post metadata: %2$d. Term metadata: %3$d.', 'easyrankly' ),
			$label,
			$post_meta,
			$term_meta
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}

/**
 * Renders the authoritative, fail-closed go-live decision.
 *
 * @param array<string,mixed> $gate Go-live gate payload.
 * @return void
 */
function erankly_migration_render_go_live_gate( array $gate ): void {
	$state         = sanitize_key( (string) ( $gate['state'] ?? 'blocked' ) );
	$scope         = sanitize_key( (string) ( $gate['proof_scope'] ?? 'none' ) );
	$titles        = array(
		'preview_only'      => __( 'Preview evidence only', 'easyrankly' ),
		'blocked'           => __( 'Final verification blocked', 'easyrankly' ),
		'ready_for_cutover' => __( 'Ready for final verification', 'easyrankly' ),
		'go_live'           => 'contract_only' === $scope ? __( 'Import contract verified', 'easyrankly' ) : __( 'Migration fully verified', 'easyrankly' ),
		'rollback_required' => __( 'Recovery decision required', 'easyrankly' ),
		'rolled_back'       => __( 'Rollback completed', 'easyrankly' ),
		'rollback_failed'   => __( 'Rollback incomplete — manual recovery required', 'easyrankly' ),
	);
	$classes       = array(
		'preview_only'      => 'info',
		'blocked'           => 'error',
		'ready_for_cutover' => 'warning',
		'go_live'           => 'success',
		'rollback_required' => 'error',
		'rolled_back'       => 'info',
		'rollback_failed'   => 'error',
	);
	$descriptions  = array(
		'preview_only'      => __( 'A preview can validate source coverage, but it never authorizes deactivation of the source SEO plugin.', 'easyrankly' ),
		'blocked'           => __( 'Keep the source SEO plugin active. At least one mandatory proof is missing or failed.', 'easyrankly' ),
		'ready_for_cutover' => __( 'The import checks passed. Deactivate the source plugin without deleting its data, clear any cache you use, then run the final verification.', 'easyrankly' ),
		'go_live'           => __( 'All mandatory proofs for this migration scope passed. Keep the report and source backup during monitoring.', 'easyrankly' ),
		'rollback_required' => __( 'Post-cutover output differs from the baseline or could not be proven. Retry after cache checks or run the conditional rollback.', 'easyrankly' ),
		'rolled_back'       => __( 'The conditional rollback completed; later manual edits were preserved.', 'easyrankly' ),
		'rollback_failed'   => __( 'Automated rollback did not complete safely. Reactivate the source plugin and inspect the rollback evidence.', 'easyrankly' ),
	);
	$check_labels  = array(
		'terminal_status'         => __( 'Migration reached a successful terminal state', 'easyrankly' ),
		'source_integrity'        => __( 'Immutable source fingerprint verified before apply', 'easyrankly' ),
		'exact_accounting'        => __( 'Every discovered occurrence classified exactly once', 'easyrankly' ),
		'write_failures'          => __( 'No failed writes', 'easyrankly' ),
		'invalid_records'         => __( 'No invalid source records', 'easyrankly' ),
		'conflicts'               => __( 'No unresolved conflicts', 'easyrankly' ),
		'unsupported_records'     => __( 'No unsupported records', 'easyrankly' ),
		'preserved_values'        => __( 'No values silently preserved instead of migrated', 'easyrankly' ),
		'diagnostics'             => __( 'No unresolved diagnostics', 'easyrankly' ),
		'semantic_match'          => __( 'Stored semantics match normalized source values', 'easyrankly' ),
		'unresolved_placeholders' => __( 'No unresolved source placeholders', 'easyrankly' ),
		'redirect_storage'        => __( 'Every imported redirect matches persistent storage', 'easyrankly' ),
		'redirect_loops'          => __( 'No redirect loops', 'easyrankly' ),
		'redirect_chains'         => __( 'No redirect chains', 'easyrankly' ),
		'redirect_collisions'     => __( 'No redirect collisions', 'easyrankly' ),
		'redirect_regex'          => __( 'No dangerous redirect regular expressions', 'easyrankly' ),
		'rollback_window'         => __( 'Rollback journal covers every migration write and is not expired', 'easyrankly' ),
		'frontend_baseline'       => __( 'Old-plugin frontend baseline captured before cutover', 'easyrankly' ),
		'live_verification'       => __( 'Current HTML, redirects, robots.txt and sitemap preserve the saved SEO meaning', 'easyrankly' ),
		'rollback_result'         => __( 'Conditional rollback completed without failures', 'easyrankly' ),
	);
	$status_labels = array(
		'pass'           => __( 'Passed', 'easyrankly' ),
		'fail'           => __( 'Needs attention', 'easyrankly' ),
		'pending'        => __( 'Waiting', 'easyrankly' ),
		'not_applicable' => __( 'Not required', 'easyrankly' ),
	);

	if ( ! isset( $titles[ $state ], $classes[ $state ] ) ) {
		$state = 'blocked';
	}
	?>
	<div class="erankly-migration-gate erankly-migration-gate--<?php echo esc_attr( $classes[ $state ] ); ?>">
		<p><strong><?php echo esc_html( $titles[ $state ] ); ?></strong></p>
		<p><?php echo esc_html( $descriptions[ $state ] ); ?></p>
		<?php if ( 'contract_only' === $scope ) : ?>
			<p><strong><?php esc_html_e( 'Proof boundary:', 'easyrankly' ); ?></strong> <?php esc_html_e( 'the certified export contract and stored migration evidence passed. The source plugin did not own this site frontend, so no old-plugin HTML comparison was possible or claimed.', 'easyrankly' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $gate['checks'] ) && is_array( $gate['checks'] ) ) : ?>
			<ul>
				<?php foreach ( $gate['checks'] as $check ) : ?>
					<?php
					$code   = sanitize_key( (string) ( $check['code'] ?? '' ) );
					$status = sanitize_key( (string) ( $check['status'] ?? '' ) );
					$count  = absint( $check['count'] ?? 0 );
					?>
					<?php if ( isset( $check_labels[ $code ], $status_labels[ $status ] ) ) : ?>
						<li><strong><?php echo esc_html( $status_labels[ $status ] ); ?></strong> — <?php echo esc_html( $check_labels[ $code ] ); ?><?php echo $count > 0 ? esc_html( ' (' . $count . ')' ) : ''; ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: proof scope, 2: decision SHA-256. */
				esc_html__( 'Proof scope: %1$s. Decision SHA-256: %2$s.', 'easyrankly' ),
				esc_html( $scope ),
				esc_html( (string) ( $gate['decision_sha256'] ?? '' ) )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Formats one persisted UTC date for the current WordPress locale and timezone.
 *
 * @param string $value ISO-8601 or database date.
 * @return string
 */
function erankly_migration_format_datetime( string $value ): string {
	$timestamp = strtotime( $value );
	if ( false === $timestamp ) {
		return sanitize_text_field( $value );
	}

	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Returns the concise copy for one presenter state.
 *
 * @param array<string,mixed> $ui Presenter payload.
 * @return array{title:string,instruction:string,body:string}
 */
function erankly_migration_guided_copy( array $ui ): array {
	$state        = sanitize_key( (string) ( $ui['state'] ?? 'blocked' ) );
	$source_label = sanitize_text_field( (string) ( $ui['active_owner_label'] ?? $ui['source_label'] ?? __( 'the previous SEO plugin', 'easyrankly' ) ) );
	$copy         = array(
		'preview_ready'       => array(
			'title'       => __( 'Preview complete', 'easyrankly' ),
			'instruction' => __( 'Step 1 of 3 — Import your SEO data', 'easyrankly' ),
			'body'        => __( 'No blocking issue was found. Existing EasyRankly values will still be preserved and reported.', 'easyrankly' ),
		),
		'preview_blocked'     => array(
			'title'       => __( 'Review required before importing', 'easyrankly' ),
			'instruction' => __( 'Resolve the items that need attention', 'easyrankly' ),
			'body'        => __( 'Keep the source plugin active. No final switch is authorized while a required check is unresolved.', 'easyrankly' ),
		),
		'source_active'       => array(
			'title'       => __( 'Import complete', 'easyrankly' ),
			/* translators: %s: source SEO plugin name. */
			'instruction' => sprintf( __( 'Step 2 of 3 — Deactivate %s', 'easyrankly' ), $source_label ),
			/* translators: %s: source SEO plugin name. */
			'body'        => sprintf( __( 'Do not delete %s or its data yet. Keep this report open while you deactivate it from the Plugins screen.', 'easyrankly' ), $source_label ),
		),
		'ready_to_verify'     => array(
			'title'       => __( 'The previous SEO plugin is no longer active', 'easyrankly' ),
			'instruction' => __( 'Step 3 of 3 — Verify the site', 'easyrankly' ),
			'body'        => __( 'Clear any WordPress, page or CDN cache you use, then let EasyRankly compare the current site with the saved baseline.', 'easyrankly' ),
		),
		'complete'            => array(
			'title'       => __( 'Migration complete and verified', 'easyrankly' ),
			'instruction' => __( 'EasyRankly is now managing the site SEO output', 'easyrankly' ),
			'body'        => __( 'The checked SEO meaning, redirects and sitemap inventory were preserved. Expected provider-specific markup and endpoint changes were accepted. Keep the old plugin data during the monitoring window.', 'easyrankly' ),
		),
		'contract_verified'   => array(
			'title'       => __( 'Data import complete', 'easyrankly' ),
			'instruction' => __( 'The certified import contract was verified', 'easyrankly' ),
			'body'        => __( 'A before-and-after frontend comparison was not available for this file import. Review representative pages before deleting the source backup.', 'easyrankly' ),
		),
		'verification_failed' => array(
			'title'       => __( 'The final verification needs attention', 'easyrankly' ),
			'instruction' => __( 'Review the differences before deciding', 'easyrankly' ),
			'body'        => __( 'Do not delete the previous plugin data. Clear the caches you use, inspect the differences, then retry or use the safe rollback.', 'easyrankly' ),
		),
		'rolled_back'         => array(
			'title'       => __( 'Rollback complete', 'easyrankly' ),
			'instruction' => __( 'Reactivate the previous SEO plugin', 'easyrankly' ),
			'body'        => __( 'Only values that still matched this migration were restored or removed. Later manual edits were preserved.', 'easyrankly' ),
		),
		'rollback_failed'     => array(
			'title'       => __( 'Manual recovery is required', 'easyrankly' ),
			'instruction' => __( 'Review the rollback evidence before changing data', 'easyrankly' ),
			'body'        => __( 'Reactivate the previous SEO plugin and use the technical report to complete recovery safely.', 'easyrankly' ),
		),
		'blocked'             => array(
			'title'       => __( 'Migration not ready', 'easyrankly' ),
			'instruction' => __( 'Resolve the items that need attention', 'easyrankly' ),
			'body'        => __( 'Keep the previous SEO plugin active. EasyRankly will not authorize the final switch while required evidence is missing.', 'easyrankly' ),
		),
	);

	return $copy[ $state ] ?? $copy['blocked'];
}

/**
 * Renders the three-step migration progress indicator.
 *
 * @param array<string,mixed> $ui Presenter payload.
 * @return void
 */
function erankly_migration_render_steps( array $ui ): void {
	$state   = sanitize_key( (string) ( $ui['state'] ?? 'blocked' ) );
	$visible = in_array( $state, array( 'preview_ready', 'preview_blocked', 'source_active', 'ready_to_verify', 'complete', 'contract_verified', 'verification_failed', 'blocked' ), true );
	if ( ! $visible ) {
		return;
	}

	$step     = max( 1, min( 3, absint( $ui['step'] ?? 1 ) ) );
	$finished = in_array( $state, array( 'complete', 'contract_verified' ), true );
	$labels   = array(
		1 => __( 'Import data', 'easyrankly' ),
		2 => __( 'Deactivate old plugin', 'easyrankly' ),
		3 => __( 'Verify site', 'easyrankly' ),
	);
	?>
	<ol class="erankly-migration-steps" aria-label="<?php esc_attr_e( 'Migration progress', 'easyrankly' ); ?>">
		<?php foreach ( $labels as $index => $label ) : ?>
			<?php
			$is_complete = $index < $step || ( $finished && 3 === $index );
			$is_current  = $index === $step && ! $finished;
			$classes     = $is_complete ? ' is-complete' : ( $is_current ? ' is-current' : '' );
			?>
			<li class="erankly-migration-step<?php echo esc_attr( $classes ); ?>"<?php echo $is_current ? ' aria-current="step"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute. ?>>
				<span class="erankly-migration-step-marker" aria-hidden="true"><?php echo $is_complete ? '&#10003;' : esc_html( (string) $index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed checkmark entity or escaped integer. ?></span>
				<span><?php echo esc_html( $label ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
}

/**
 * Renders the only primary action for the current migration state.
 *
 * @param array<string,mixed> $ui     Presenter payload.
 * @param array<string,mixed> $report Persisted report.
 * @return void
 */
function erankly_migration_render_guided_action( array $ui, array $report ): void {
	$action      = sanitize_key( (string) ( $ui['primary_action'] ?? '' ) );
	$report_id   = sanitize_text_field( (string) ( $report['id'] ?? '' ) );
	$source      = sanitize_key( (string) ( $report['source'] ?? '' ) );
	$source_name = sanitize_text_field( (string) ( $ui['active_owner_label'] ?? $ui['source_label'] ?? '' ) );
	$recheck_url = add_query_arg( 'report_id', $report_id, erankly_import_export_url() );
	?>
	<div class="erankly-migration-primary-action">
		<?php if ( 'open_plugins' === $action ) : ?>
			<?php
			$plugins_url  = is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
			$plugins_url  = add_query_arg(
				array(
					'plugin_status' => 'rolled_back' === (string) ( $ui['state'] ?? '' ) ? 'all' : 'active',
					's'             => $source_name,
				),
				$plugins_url
			);
			$button_label = 'rolled_back' === (string) ( $ui['state'] ?? '' ) ? __( 'Open Plugins to reactivate it', 'easyrankly' ) : __( 'Open Plugins in a new tab', 'easyrankly' );
			?>
			<a class="button button-primary" href="<?php echo esc_url( $plugins_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $button_label ); ?></a>
			<?php if ( 'source_active' === (string) ( $ui['state'] ?? '' ) ) : ?>
				<a class="button" href="<?php echo esc_url( $recheck_url ); ?>"><?php esc_html_e( 'I deactivated it — check again', 'easyrankly' ); ?></a>
			<?php endif; ?>
		<?php elseif ( 'verify_live' === $action && ! empty( $ui['can_verify_live'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>">
				<?php wp_nonce_field( 'erankly_migration_evidence_' . $report_id ); ?>
				<input type="hidden" name="erankly_io_action" value="migration-verify-live">
				<input type="hidden" name="erankly_migration_report_id" value="<?php echo esc_attr( $report_id ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Start final verification', 'easyrankly' ); ?></button>
			</form>
		<?php elseif ( 'run_import' === $action && ! empty( $ui['can_run_import'] ) ) : ?>
			<?php if ( ! empty( $ui['is_database_migration'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Import the reviewed data now? Existing EasyRankly values will still be preserved.', 'easyrankly' ) ); ?>');">
					<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
					<input type="hidden" name="erankly_io_action" value="migrate">
					<input type="hidden" name="erankly_migration_source" value="<?php echo esc_attr( $source ); ?>">
					<input type="hidden" name="erankly_migration_mode" value="import">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import the reviewed data', 'easyrankly' ); ?></button>
				</form>
			<?php else : ?>
				<a class="button button-primary" href="#erankly-migration-export-form"><?php esc_html_e( 'Upload the official export again', 'easyrankly' ); ?></a>
			<?php endif; ?>
		<?php elseif ( 'review_differences' === $action ) : ?>
			<a class="button button-primary" href="#erankly-migration-attention"><?php esc_html_e( 'Review the differences', 'easyrankly' ); ?></a>
		<?php elseif ( 'review_recovery' === $action ) : ?>
			<a class="button button-primary" href="#erankly-migration-recovery"><?php esc_html_e( 'Open recovery details', 'easyrankly' ); ?></a>
		<?php elseif ( 'review_issues' === $action ) : ?>
			<a class="button button-primary" href="#erankly-migration-attention"><?php esc_html_e( 'Review items that need attention', 'easyrankly' ); ?></a>
		<?php elseif ( 'open_settings' === $action ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'erankly_tab', 'general', erankly_import_export_url() ) ); ?>"><?php esc_html_e( 'Go to EasyRankly settings', 'easyrankly' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Renders only actionable blockers and live differences in the primary layer.
 *
 * @param array<string,mixed> $ui     Presenter payload.
 * @param array<string,mixed> $report Persisted report.
 * @param array<string,mixed> $gate   Go-live gate.
 * @return void
 */
function erankly_migration_render_attention( array $ui, array $report, array $gate ): void {
	$checks   = is_array( $gate['checks'] ?? null ) ? $gate['checks'] : array();
	$warnings = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array();
	$live     = is_array( $report['live_verification'] ?? null ) ? $report['live_verification'] : array();
	$failed   = array_values( array_filter( $checks, static fn( array $check ): bool => 'fail' === (string) ( $check['status'] ?? '' ) ) );
	$has_live = (int) ( $live['mismatch'] ?? 0 ) > 0 || (int) ( $live['request_failed'] ?? 0 ) > 0;
	if ( ! $failed && ! $warnings && ! $has_live ) {
		return;
	}

	$labels = array(
		'terminal_status'         => __( 'The migration did not finish successfully.', 'easyrankly' ),
		'source_integrity'        => __( 'The source data changed while it was being processed.', 'easyrankly' ),
		'exact_accounting'        => __( 'Not every discovered value has a final outcome.', 'easyrankly' ),
		'write_failures'          => __( 'Some values could not be written.', 'easyrankly' ),
		'invalid_records'         => __( 'Some source records are invalid.', 'easyrankly' ),
		'conflicts'               => __( 'Some existing EasyRankly values were preserved and need review.', 'easyrankly' ),
		'unsupported_records'     => __( 'Some source records are not supported.', 'easyrankly' ),
		'preserved_values'        => __( 'Some source values were preserved instead of imported.', 'easyrankly' ),
		'diagnostics'             => __( 'The report contains warnings that need review.', 'easyrankly' ),
		'semantic_match'          => __( 'Some normalized values differ from the source meaning.', 'easyrankly' ),
		'unresolved_placeholders' => __( 'Some source template variables could not be resolved.', 'easyrankly' ),
		'redirect_storage'        => __( 'Some redirects do not match their stored values.', 'easyrankly' ),
		'redirect_loops'          => __( 'A redirect loop was detected.', 'easyrankly' ),
		'redirect_chains'         => __( 'A redirect chain was detected.', 'easyrankly' ),
		'redirect_collisions'     => __( 'Two redirects use the same source path.', 'easyrankly' ),
		'redirect_regex'          => __( 'A redirect pattern requires manual review.', 'easyrankly' ),
		'rollback_window'         => __( 'The safe rollback window is unavailable or incomplete.', 'easyrankly' ),
		'frontend_baseline'       => __( 'The previous frontend output could not be captured.', 'easyrankly' ),
		'live_verification'       => __( 'The current site output differs from the saved baseline.', 'easyrankly' ),
		'rollback_result'         => __( 'The rollback did not complete safely.', 'easyrankly' ),
	);
	?>
	<section id="erankly-migration-attention" class="erankly-migration-attention" aria-labelledby="erankly-migration-attention-title">
		<h4 id="erankly-migration-attention-title"><?php esc_html_e( 'What needs attention', 'easyrankly' ); ?></h4>
		<ul>
			<?php foreach ( $failed as $check ) : ?>
				<?php $code = sanitize_key( (string) ( $check['code'] ?? '' ) ); ?>
				<?php if ( isset( $labels[ $code ] ) && ( 'live_verification' !== $code || ! $has_live ) ) : ?>
					<li><?php echo esc_html( $labels[ $code ] ); ?><?php echo absint( $check['count'] ?? 0 ) > 0 ? esc_html( ' (' . absint( $check['count'] ) . ')' ) : ''; ?></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( is_array( $live['pages'] ?? null ) ? $live['pages'] : array() as $page ) : ?>
				<?php if ( in_array( (string) ( $page['status'] ?? '' ), array( 'mismatch', 'request_failed' ), true ) ) : ?>
					<li><?php echo esc_html( 'request_failed' === (string) ( $page['status'] ?? '' ) ? __( 'A page could not be reached: ', 'easyrankly' ) : __( 'Page SEO output differs: ', 'easyrankly' ) ); ?><code><?php echo esc_html( (string) ( $page['url'] ?? '' ) ); ?></code></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( is_array( $live['redirects'] ?? null ) ? $live['redirects'] : array() as $redirect ) : ?>
				<?php if ( in_array( (string) ( $redirect['status'] ?? '' ), array( 'mismatch', 'request_failed' ), true ) ) : ?>
					<li><?php esc_html_e( 'Redirect response differs: ', 'easyrankly' ); ?><code><?php echo esc_html( (string) ( $redirect['source_path'] ?? '' ) ); ?></code></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( is_array( $live['surfaces'] ?? null ) ? $live['surfaces'] : array() as $surface => $result ) : ?>
				<?php if ( in_array( (string) ( $result['status'] ?? '' ), array( 'mismatch', 'request_failed' ), true ) ) : ?>
					<li><?php echo esc_html( sprintf( /* translators: %s: robots.txt or sitemap. */ __( '%s differs from the saved baseline.', 'easyrankly' ), sanitize_text_field( (string) $surface ) ) ); ?></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( array_slice( $warnings, 0, 10 ) as $warning ) : ?>
				<li><?php echo esc_html( (string) ( $warning['message'] ?? $warning['code'] ?? '' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( 'verification_failed' === (string) ( $ui['state'] ?? '' ) && ! empty( $gate['can_verify_live'] ) && empty( $ui['source_owns_output'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>">
				<?php wp_nonce_field( 'erankly_migration_evidence_' . (string) $report['id'] ); ?>
				<input type="hidden" name="erankly_io_action" value="migration-verify-live">
				<input type="hidden" name="erankly_migration_report_id" value="<?php echo esc_attr( (string) $report['id'] ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Run verification again', 'easyrankly' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Renders the selected post-migration report and recent report history.
 *
 * @return void
 */
function erankly_migration_render_report(): void {
	$report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report selection.
	$active    = erankly_migration_job_runner()->active_job();
	if ( is_array( $active ) ) {
		erankly_migration_render_active_job( $active );
		return;
	}
	$reports = erankly_migration_manager()->reports();
	$report  = '' !== $report_id ? erankly_migration_manager()->get_report( $report_id ) : null;

	if ( ! is_array( $report ) ) {
		return;
	}

	$counts             = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
	$download_url       = wp_nonce_url(
		add_query_arg(
			array(
				'erankly_io_action' => 'migration-report',
				'report_id'         => (string) $report['id'],
			),
			erankly_import_export_url()
		),
		'erankly_migration_report_' . (string) $report['id']
	);
	$source_version     = '' !== (string) ( $report['source_version'] ?? '' ) ? ' ' . (string) $report['source_version'] : '';
	$profile            = is_array( $report['source_profile'] ?? null ) ? $report['source_profile'] : array();
	$inventory          = is_array( $report['source_inventory'] ?? null ) ? $report['source_inventory'] : array();
	$verification       = is_array( $report['verification'] ?? null ) ? $report['verification'] : array();
	$evidence           = is_array( $report['evidence'] ?? null ) ? $report['evidence'] : array();
	$accounting         = is_array( $evidence['accounting'] ?? null ) ? $evidence['accounting'] : array();
	$semantic           = is_array( $evidence['semantic_comparison'] ?? null ) ? $evidence['semantic_comparison'] : array();
	$redirect_audit     = is_array( $evidence['redirect_audit'] ?? null ) ? $evidence['redirect_audit'] : array();
	$rollback           = 'import' === (string) ( $report['mode'] ?? '' ) ? erankly_migration_journal()->summary( (string) $report['id'] ) : array();
	$baseline           = is_array( $report['html_baseline'] ?? null ) ? $report['html_baseline'] : array();
	$live               = is_array( $report['live_verification'] ?? null ) ? $report['live_verification'] : array();
	$gate               = erankly_migration_manager()->evaluate_go_live_gate( $report, true );
	$csv_url            = wp_nonce_url(
		add_query_arg(
			array(
				'erankly_io_action' => 'migration-exceptions',
				'report_id'         => (string) $report['id'],
			),
			erankly_import_export_url()
		),
		'erankly_migration_exceptions_' . (string) $report['id']
	);
	$source_owns_output = false;
	$active_owners      = function_exists( 'erankly_external_seo_head_owners' ) ? erankly_external_seo_head_owners() : array();
	if ( 'import' === (string) ( $report['mode'] ?? '' ) ) {
		$source_owns_output = (bool) apply_filters( 'erankly_migration_source_owns_output', erankly_detect_external_seo_head_owner(), sanitize_key( (string) ( $report['source'] ?? '' ) ) );
	}
	$ui = ( new ERankly_Migration_Admin_Presenter() )->present( $report, $gate, $rollback, $source_owns_output );
	if ( $source_owns_output ) {
		$owner_labels = array_values( array_unique( array_filter( array_map( static fn( array $owner ): string => sanitize_text_field( (string) ( $owner['label'] ?? '' ) ), $active_owners ) ) ) );
		if ( $owner_labels ) {
			$ui['active_owner_label'] = implode( ', ', $owner_labels );
		}
	}
	$copy         = erankly_migration_guided_copy( $ui );
	$check_totals = is_array( $ui['check_totals'] ?? null ) ? $ui['check_totals'] : array();
	$completed_at = erankly_migration_format_datetime( (string) ( $report['completed_at'] ?? '' ) );
	?>
	<div class="erankly-settings-section erankly-migration-report">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Migration assistant', 'easyrankly' ); ?></h3>
		<section class="erankly-io-section erankly-card erankly-migration-card erankly-migration-card--<?php echo esc_attr( sanitize_key( (string) ( $ui['tone'] ?? 'info' ) ) ); ?>">
			<p class="erankly-migration-context">
				<strong><?php echo esc_html( (string) ( $report['source_label'] ?? $report['source'] ) . $source_version ); ?></strong>
				<span aria-hidden="true">&middot;</span>
				<?php echo 'preview' === (string) ( $report['mode'] ?? '' ) ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>
				<?php if ( '' !== $completed_at ) : ?>
					<span aria-hidden="true">&middot;</span>
					<time datetime="<?php echo esc_attr( (string) ( $report['completed_at'] ?? '' ) ); ?>"><?php echo esc_html( $completed_at ); ?></time>
				<?php endif; ?>
			</p>
			<div class="erankly-migration-message" role="status" aria-live="polite">
				<h4><?php echo esc_html( $copy['title'] ); ?></h4>
				<p class="erankly-migration-instruction"><?php echo esc_html( $copy['instruction'] ); ?></p>
				<p><?php echo esc_html( $copy['body'] ); ?></p>
			</div>
			<?php erankly_migration_render_steps( $ui ); ?>
			<ul class="erankly-migration-metrics" aria-label="<?php esc_attr_e( 'Migration summary', 'easyrankly' ); ?>">
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['metadata_count'] ?? 0 ) ) ); ?></strong><span><?php echo 'preview' === (string) ( $report['mode'] ?? '' ) ? esc_html__( 'SEO fields ready', 'easyrankly' ) : esc_html__( 'SEO fields imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $ui['redirect_count'] ?? 0 ) ) ); ?></strong><span><?php echo 'preview' === (string) ( $report['mode'] ?? '' ) ? esc_html__( 'Redirects ready', 'easyrankly' ) : esc_html__( 'Redirects imported', 'easyrankly' ); ?></span></li>
				<li class="<?php echo absint( $ui['problem_count'] ?? 0 ) > 0 ? 'has-problems' : 'has-no-problems'; ?>"><strong><?php echo esc_html( number_format_i18n( absint( $ui['problem_count'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Items needing attention', 'easyrankly' ); ?></span></li>
			</ul>
			<?php erankly_migration_render_guided_action( $ui, $report ); ?>
			<?php erankly_migration_render_attention( $ui, $report, $gate ); ?>

			<details id="erankly-migration-technical" class="erankly-migration-disclosure">
				<summary>
					<span><?php esc_html_e( 'Technical details', 'easyrankly' ); ?></span>
					<span class="erankly-migration-disclosure-summary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: passed checks, 2: checks needing attention, 3: waiting checks, 4: checks not required. */
								__( '%1$d passed, %2$d need attention, %3$d waiting, %4$d not required', 'easyrankly' ),
								absint( $check_totals['pass'] ?? 0 ),
								absint( $check_totals['fail'] ?? 0 ),
								absint( $check_totals['pending'] ?? 0 ),
								absint( $check_totals['not_applicable'] ?? 0 )
							)
						);
						?>
					</span>
				</summary>
				<div class="erankly-migration-disclosure-content">
					<?php erankly_migration_render_go_live_gate( $gate ); ?>
			<?php if ( $profile ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: edition, 2: source mode, 3: storage format, 4: source fingerprint state. */
						esc_html__( 'Edition: %1$s. Source: %2$s. Certified signature: %3$s. Fingerprint: %4$s.', 'easyrankly' ),
						esc_html( strtoupper( (string) ( $profile['edition'] ?? 'unknown' ) ) ),
						esc_html( (string) ( $profile['mode'] ?? 'database' ) ),
						esc_html( (string) ( $profile['storage_format'] ?? 'unknown' ) ),
						! empty( $report['source_fingerprint_verified'] ) ? esc_html__( 'verified before apply', 'easyrankly' ) : esc_html__( 'captured', 'easyrankly' )
					);
					?>
				</p>
				<?php if ( ! empty( $profile['modules'] ) && is_array( $profile['modules'] ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'Detected modules:', 'easyrankly' ); ?>
						<?php echo esc_html( implode( ', ', array_map( 'sanitize_key', $profile['modules'] ) ) ); ?>.
						<?php if ( ! empty( $inventory['total'] ) ) : ?>
							<?php echo esc_html( sprintf( /* translators: %d: source inventory count. */ __( 'Source inventory: %d records across certified surfaces.', 'easyrankly' ), absint( $inventory['total'] ) ) ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Area', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Found', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Ready / written', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Preserved / unchanged', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Conflicts / invalid', 'easyrankly' ); ?></th></tr></thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'SEO metadata', 'easyrankly' ); ?></td>
						<td><?php echo esc_html( (string) ( $counts['fields_found'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( 'preview' === (string) $report['mode'] ? ( $counts['fields_ready'] ?? 0 ) : ( $counts['fields_written'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['fields_skipped_existing'] ?? 0 ) + ( $counts['fields_duplicate'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['fields_conflicts'] ?? 0 ) + ( $counts['fields_invalid'] ?? 0 ) + ( $counts['fields_failed'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Redirects', 'easyrankly' ); ?></td>
						<td><?php echo esc_html( (string) ( $counts['redirects_found'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( 'preview' === (string) $report['mode'] ? ( ( $counts['redirects_ready_create'] ?? 0 ) + ( $counts['redirects_ready_update'] ?? 0 ) ) : ( ( $counts['redirects_created'] ?? 0 ) + ( $counts['redirects_updated'] ?? 0 ) ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['redirects_unchanged'] ?? 0 ) + ( $counts['redirects_duplicate'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( ( $counts['redirects_conflicts'] ?? 0 ) + ( $counts['redirects_invalid'] ?? 0 ) + ( $counts['redirects_failed'] ?? 0 ) ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: 1: post count, 2: term count, 3: user count. */
					esc_html__( 'Objects scanned — posts: %1$d; terms: %2$d; authors: %3$d.', 'easyrankly' ),
					(int) ( $counts['posts_found'] ?? 0 ),
					(int) ( $counts['terms_found'] ?? 0 ),
					(int) ( $counts['users_found'] ?? 0 )
				);
				?>
			</p>
			<?php if ( $accounting ) : ?>
				<h4><?php esc_html_e( 'Evidence ledger', 'easyrankly' ); ?></h4>
				<p>
					<strong><?php echo 'pass' === (string) ( $evidence['invariant']['status'] ?? '' ) ? esc_html__( 'Passed', 'easyrankly' ) : esc_html__( 'Failed', 'easyrankly' ); ?></strong>
					— <?php esc_html_e( 'every discovered occurrence is assigned to exactly one terminal outcome.', 'easyrankly' ); ?>
				</p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Area', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Discovered', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Imported', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Identical', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Preserved', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Conflict / invalid / failed', 'easyrankly' ); ?></th></tr></thead>
					<tbody>
						<?php
						foreach ( array(
							'metadata'  => __( 'SEO metadata', 'easyrankly' ),
							'redirects' => __( 'Redirects', 'easyrankly' ),
						) as $area_key => $area_label ) :
							?>
										<?php
										$area     = is_array( $accounting[ $area_key ] ?? null ) ? $accounting[ $area_key ] : array();
										$terminal = is_array( $area['terminal'] ?? null ) ? $area['terminal'] : array();
										?>
							<tr>
								<td><?php echo esc_html( $area_label ); ?></td>
								<td><?php echo esc_html( (string) ( $area['discovered'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $terminal['imported'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $terminal['identical'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $terminal['preserved'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( ( $terminal['conflict'] ?? 0 ) + ( $terminal['invalid'] ?? 0 ) + ( $terminal['unsupported'] ?? 0 ) + ( $terminal['failed'] ?? 0 ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					printf(
						/* translators: 1: transformed values, 2: unresolved placeholder warnings. */
						esc_html__( 'Normalized transformations: %1$d. Unresolved placeholder diagnostics: %2$d.', 'easyrankly' ),
						absint( $evidence['modifiers']['transformed'] ?? 0 ),
						count( is_array( $evidence['modifiers']['unresolved_placeholders'] ?? null ) ? $evidence['modifiers']['unresolved_placeholders'] : array() )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( $semantic ) : ?>
				<details>
					<summary><?php esc_html_e( 'Normalized before/after comparison', 'easyrankly' ); ?></summary>
					<ul>
						<?php foreach ( $semantic as $domain => $result ) : ?>
							<li><strong><?php echo esc_html( strtoupper( (string) $domain ) ); ?></strong> — <?php echo esc_html( sprintf( /* translators: 1: matches, 2: mismatches, 3: planned. */ __( '%1$d match; %2$d mismatch; %3$d planned.', 'easyrankly' ), absint( $result['matched'] ?? 0 ), absint( $result['mismatch'] ?? 0 ), absint( $result['planned'] ?? 0 ) ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<?php if ( $redirect_audit ) : ?>
				<details>
					<summary><?php esc_html_e( 'Redirect safety audit', 'easyrankly' ); ?></summary>
					<p><?php echo esc_html( sprintf( /* translators: 1: loops, 2: chains, 3: collisions, 4: dangerous regex. */ __( 'Loops: %1$d. Chains: %2$d. Collisions: %3$d. Dangerous regex: %4$d. Status and Location were tested with redirects disabled in the HTTP client.', 'easyrankly' ), count( $redirect_audit['loops'] ?? array() ), count( $redirect_audit['chains'] ?? array() ), count( $redirect_audit['collisions'] ?? array() ), count( $redirect_audit['dangerous_regex'] ?? array() ) ) ); ?></p>
				</details>
			<?php endif; ?>
			<?php if ( 'import' === (string) ( $report['mode'] ?? '' ) ) : ?>
				<h4><?php esc_html_e( 'Final verification evidence', 'easyrankly' ); ?></h4>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: baseline state, 2: live verification state, 3: exact matches, 4: expected provider changes, 5: regressions, 6: failed requests. */
							__( 'Saved baseline: %1$s. Current verification: %2$s. Exact matches: %3$d; expected provider changes: %4$d; regressions: %5$d; unreachable probes: %6$d.', 'easyrankly' ),
							sanitize_key( (string) ( $baseline['state'] ?? 'unavailable' ) ),
							sanitize_key( (string) ( $live['state'] ?? 'pending' ) ),
							absint( $live['matched'] ?? 0 ),
							absint( $live['expected_changes'] ?? 0 ),
							absint( $live['mismatch'] ?? 0 ),
							absint( $live['request_failed'] ?? 0 )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $report['warnings'] ) && is_array( $report['warnings'] ) ) : ?>
				<details>
					<summary><?php esc_html_e( 'Warnings requiring review', 'easyrankly' ); ?> (<?php echo esc_html( (string) count( $report['warnings'] ) ); ?>)</summary>
					<ul>
						<?php foreach ( array_slice( $report['warnings'], 0, 20 ) as $warning ) : ?>
							<li><?php echo esc_html( (string) ( $warning['message'] ?? $warning['code'] ?? '' ) ); ?><?php echo ! empty( $warning['reference'] ) ? ' — ' . esc_html( (string) $warning['reference'] ) : ''; ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<?php if ( ! empty( $report['details'] ) && is_array( $report['details'] ) ) : ?>
				<details>
					<summary><?php esc_html_e( 'Record-level diagnostics', 'easyrankly' ); ?> (<?php echo esc_html( (string) count( $report['details'] ) ); ?>)</summary>
					<ul>
						<?php foreach ( array_slice( $report['details'], 0, 20 ) as $detail ) : ?>
							<li>
								<code><?php echo esc_html( (string) ( $detail['code'] ?? '' ) ); ?></code>
								<?php echo ! empty( $detail['reference'] ) ? ' — ' . esc_html( (string) $detail['reference'] ) : ''; ?>
								<?php echo ! empty( $detail['field'] ) ? ' — ' . esc_html( (string) $detail['field'] ) : ''; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
				<div class="erankly-migration-report-actions">
					<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download technical report', 'easyrankly' ); ?></a>
					<?php if ( absint( $ui['problem_count'] ?? 0 ) > 0 ) : ?>
						<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download items to review', 'easyrankly' ); ?></a>
					<?php endif; ?>
				</div>
				</div>
			</details>

			<?php if ( ! empty( $ui['rollback_available'] ) || in_array( (string) ( $ui['state'] ?? '' ), array( 'verification_failed', 'rollback_failed' ), true ) ) : ?>
				<details id="erankly-migration-recovery" class="erankly-migration-disclosure erankly-migration-recovery"<?php echo 'rollback_failed' === (string) ( $ui['state'] ?? '' ) ? ' open' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed boolean attribute. ?>>
					<summary>
						<span><?php esc_html_e( 'Recovery and rollback', 'easyrankly' ); ?></span>
						<span class="erankly-migration-disclosure-summary"><?php esc_html_e( 'Use only if you need to abandon this migration', 'easyrankly' ); ?></span>
					</summary>
					<div class="erankly-migration-disclosure-content">
						<?php if ( 'rollback_failed' === (string) ( $ui['state'] ?? '' ) ) : ?>
							<p><strong><?php esc_html_e( 'Automated recovery did not finish safely.', 'easyrankly' ); ?></strong> <?php esc_html_e( 'Reactivate the previous SEO plugin and inspect the technical evidence before making manual data changes.', 'easyrankly' ); ?></p>
						<?php elseif ( ! empty( $ui['rollback_available'] ) ) : ?>
							<p><?php esc_html_e( 'The safe rollback changes only values that still exactly match this migration. Later manual edits are preserved.', 'easyrankly' ); ?></p>
							<?php if ( ! empty( $rollback['expires_at'] ) ) : ?>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %s: localized rollback expiry. */ __( 'Available until %s.', 'easyrankly' ), erankly_migration_format_datetime( (string) $rollback['expires_at'] ) ) ); ?></p>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Roll back this migration now? Only unchanged migration values will be affected.', 'easyrankly' ) ); ?>');">
								<?php wp_nonce_field( 'erankly_migration_evidence_' . (string) $report['id'] ); ?>
								<input type="hidden" name="erankly_io_action" value="migration-rollback">
								<input type="hidden" name="erankly_migration_report_id" value="<?php echo esc_attr( (string) $report['id'] ); ?>">
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Roll back this migration', 'easyrankly' ); ?></button>
							</form>
						<?php else : ?>
							<p><?php esc_html_e( 'The automatic rollback window is no longer available. Download the technical report before attempting manual recovery.', 'easyrankly' ); ?></p>
						<?php endif; ?>
					</div>
				</details>
			<?php endif; ?>

			<?php if ( count( $reports ) > 1 ) : ?>
				<details class="erankly-migration-disclosure erankly-migration-history">
					<summary><?php esc_html_e( 'Recent migration reports', 'easyrankly' ); ?> (<?php echo esc_html( (string) count( $reports ) ); ?>)</summary>
					<ul>
						<?php foreach ( $reports as $recent ) : ?>
							<?php
							$recent_url = add_query_arg(
								array( 'report_id' => (string) ( $recent['id'] ?? '' ) ),
								erankly_import_export_url()
							);
							?>
							<li>
								<a href="<?php echo esc_url( $recent_url ); ?>"><?php echo esc_html( (string) ( $recent['source_label'] ?? $recent['source'] ?? '' ) ); ?></a>
								— <?php echo 'preview' === (string) ( $recent['mode'] ?? '' ) ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>
								— <?php echo esc_html( erankly_migration_format_datetime( (string) ( $recent['completed_at'] ?? '' ) ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
	</div>
	<?php
}

/**
 * Renders live counters and recovery controls for a resumable migration.
 *
 * @param array<string,mixed> $job Active migration job.
 * @return void
 */
function erankly_migration_render_active_job( array $job ): void {
	$counts     = is_array( $job['counts'] ?? null ) ? $job['counts'] : array();
	$status     = sanitize_key( (string) ( $job['status'] ?? 'queued' ) );
	$stream     = sanitize_key( (string) ( $job['stream'] ?? 'content' ) );
	$cancelling = ! empty( $job['cancel_requested'] );
	$source     = erankly_migration_manager()->adapter( (string) ( $job['source'] ?? '' ) );
	$action_url = erankly_import_export_url();
	$job_id     = sanitize_text_field( (string) ( $job['id'] ?? '' ) );
	$stage      = array(
		'content'  => __( 'Discovering SEO metadata', 'easyrankly' ),
		'redirect' => __( 'Discovering redirects', 'easyrankly' ),
		'apply'    => __( 'Applying validated records', 'easyrankly' ),
		'finish'   => __( 'Finalizing the migration report', 'easyrankly' ),
	)[ $stream ] ?? __( 'Preparing the next batch', 'easyrankly' );
	if ( $cancelling ) {
		$stage = __( 'Cancellation requested', 'easyrankly' );
	}
	$title = $cancelling ? __( 'Cancellation requested', 'easyrankly' ) : ( 'paused' === $status ? __( 'Migration paused safely', 'easyrankly' ) : ( ! empty( $job['dry_run'] ) ? __( 'Preview in progress', 'easyrankly' ) : __( 'Import in progress', 'easyrankly' ) ) );
	?>
	<div class="erankly-settings-section erankly-migration-progress">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Migration assistant', 'easyrankly' ); ?></h3>
		<section class="erankly-io-section erankly-card erankly-migration-card <?php echo 'paused' === $status ? 'erankly-migration-card--warning' : ''; ?>" aria-busy="<?php echo 'paused' === $status || $cancelling ? 'false' : 'true'; ?>">
			<p class="erankly-migration-context">
				<strong><?php echo esc_html( $source ? $source->label() : (string) ( $job['source'] ?? '' ) ); ?></strong>
				<span aria-hidden="true">&middot;</span>
				<?php echo ! empty( $job['dry_run'] ) ? esc_html__( 'Preview', 'easyrankly' ) : esc_html__( 'Import', 'easyrankly' ); ?>
			</p>
			<div class="erankly-migration-message" role="status" aria-live="polite">
				<h4><?php echo esc_html( $title ); ?></h4>
				<p class="erankly-migration-instruction"><?php echo esc_html( $stage ); ?></p>
				<?php if ( $cancelling ) : ?>
					<p><?php esc_html_e( 'The request is saved and will run as soon as the current batch releases its lock. No new batch will be applied.', 'easyrankly' ); ?></p>
				<?php elseif ( 'paused' === $status ) : ?>
					<p><?php esc_html_e( 'The worker stopped at a safe checkpoint. Check the PHP or database log, then resume when ready.', 'easyrankly' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'You can leave this page. The migration continues in restart-safe background batches.', 'easyrankly' ); ?></p>
				<?php endif; ?>
			</div>
			<ul class="erankly-migration-metrics" aria-label="<?php esc_attr_e( 'Current migration progress', 'easyrankly' ); ?>">
				<li><strong><?php echo esc_html( number_format_i18n( absint( $counts['objects_found'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Objects found', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( ! empty( $job['dry_run'] ) ? absint( $counts['fields_ready'] ?? 0 ) : absint( $counts['fields_written'] ?? 0 ) ) ); ?></strong><span><?php echo ! empty( $job['dry_run'] ) ? esc_html__( 'SEO fields ready', 'easyrankly' ) : esc_html__( 'SEO fields imported', 'easyrankly' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( absint( $counts['redirects_found'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Redirects found', 'easyrankly' ); ?></span></li>
			</ul>
			<?php if ( 'paused' === $status && ! $cancelling ) : ?>
				<div class="erankly-migration-primary-action">
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php wp_nonce_field( 'erankly_migration_job_' . $job_id ); ?>
					<input type="hidden" name="erankly_io_action" value="migration-process">
					<input type="hidden" name="erankly_migration_job_id" value="<?php echo esc_attr( $job_id ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Resume migration', 'easyrankly' ); ?></button>
				</form>
				</div>
			<?php endif; ?>
			<details class="erankly-migration-disclosure">
				<summary>
					<span><?php esc_html_e( 'Progress details', 'easyrankly' ); ?></span>
					<span class="erankly-migration-disclosure-summary"><?php echo esc_html( sprintf( /* translators: %d: number of completed batches. */ __( '%d saved batches', 'easyrankly' ), absint( $job['batches'] ?? 0 ) ) ); ?></span>
				</summary>
				<div class="erankly-migration-disclosure-content">
					<p><?php echo esc_html( sprintf( /* translators: %s: localized checkpoint date. */ __( 'Latest safe checkpoint: %s', 'easyrankly' ), erankly_migration_format_datetime( (string) ( $job['updated_at'] ?? '' ) ) ) ); ?></p>
					<p><?php echo esc_html( sprintf( /* translators: 1: fields found, 2: fields ready, 3: redirects imported. */ __( 'SEO fields found: %1$d; ready: %2$d. Redirects imported so far: %3$d.', 'easyrankly' ), absint( $counts['fields_found'] ?? 0 ), absint( $counts['fields_ready'] ?? 0 ), absint( $counts['redirects_created'] ?? 0 ) + absint( $counts['redirects_updated'] ?? 0 ) ) ); ?></p>
					<?php if ( ! $cancelling && 'paused' !== $status ) : ?>
						<form method="post" action="<?php echo esc_url( $action_url ); ?>">
							<?php wp_nonce_field( 'erankly_migration_job_' . $job_id ); ?>
							<input type="hidden" name="erankly_io_action" value="migration-process">
							<input type="hidden" name="erankly_migration_job_id" value="<?php echo esc_attr( $job_id ); ?>">
							<button type="submit" class="button"><?php esc_html_e( 'Process the next batch now', 'easyrankly' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</details>
			<?php if ( ! $cancelling ) : ?>
				<details class="erankly-migration-disclosure erankly-migration-recovery">
					<summary><?php esc_html_e( 'Cancel migration', 'easyrankly' ); ?></summary>
					<div class="erankly-migration-disclosure-content">
						<p><?php esc_html_e( 'Already imported EasyRankly values will be kept and included in the final report.', 'easyrankly' ); ?></p>
						<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Cancel this migration? Already imported EasyRankly values will be kept.', 'easyrankly' ) ); ?>');">
					<?php wp_nonce_field( 'erankly_migration_job_' . $job_id ); ?>
					<input type="hidden" name="erankly_io_action" value="migration-cancel">
					<input type="hidden" name="erankly_migration_job_id" value="<?php echo esc_attr( $job_id ); ?>">
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Cancel migration', 'easyrankly' ); ?></button>
						</form>
					</div>
				</details>
			<?php endif; ?>
		</section>
	</div>
	<?php if ( 'paused' !== $status ) : ?>
		<script>window.setTimeout(function(){ window.location.reload(); }, 15000);</script>
	<?php endif; ?>
	<?php
}

/**
 * Returns whether importable data from a third-party plugin exists.
 *
 * @param string $source Source plugin slug.
 * @return bool
 */
function erankly_third_party_data_exists( string $source ): bool {
	$adapter = erankly_migration_manager()->adapter( $source );
	if ( $adapter ) {
		return $adapter->is_available();
	}

	global $wpdb;

	if ( 'yoast' === $source && is_array( get_option( 'wpseo_taxonomy_meta' ) ) ) {
		return true;
	}

	// AIOSEO v4+ keeps its data in custom tables rather than postmeta.
	if ( 'aioseo' === $source ) {
		$table = esc_sql( $wpdb->prefix . 'aioseo_posts' );

		if ( ! erankly_table_exists( $table ) ) {
			return false;
		}

		return null !== $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Presence check; table name from trusted $wpdb prefix.
	}

	$source_keys  = erankly_third_party_source_keys( $source );
	$placeholders = implode( ', ', array_fill( 0, count( $source_keys ), '%s' ) );
	$found        = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight presence check for importer availability.
		$wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$source_keys
		)
	);

	return null !== $found;
}
