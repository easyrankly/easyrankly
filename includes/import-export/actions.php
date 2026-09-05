<?php
/** Import / Export module. Exports and restores all EasyRankly data (settings, redirects, post, term, and user meta) as a single JSON file. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/migrations.php';
require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-admin-presenter.php';
require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';

/** Export file format version. Bumped when the JSON structure changes. */
define( 'ERANKLY_EXPORT_FORMAT', '3.0' );

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
 * Returns the safe application limit for a complete EasyRankly JSON import. The configured ceiling is
 * additionally constrained by the memory currently available to PHP. Decoding JSON into associative arrays can
 * require many times the source size, so the raw upload must remain only a small fraction of the remaining
 * memory after a reserve for WordPress and the database writes.
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
 * Reads a local upload without ever allocating more than the application cap. The caller must still enforce that
 * the path is a genuine PHP upload. The stat check provides a fast rejection, while the bounded read closes the
 * race where the file changes after its size was inspected.
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
 * Profiles JSON structure without materializing PHP arrays. The single-pass scanner counts every container,
 * string, and primitive while tracking balanced delimiters and nesting. Its memory use is bounded by the
 * supported depth rather than by the number of JSON values.
 *
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

/** Dispatches import/export form submissions on the settings page. */
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

function erankly_import_export_handle_import(): void {
	check_admin_referer( 'erankly_io_import' );

	$file = isset( $_FILES['erankly_import_file'] ) && is_array( $_FILES['erankly_import_file'] )
		? $_FILES['erankly_import_file'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The bounded reader and private upload store validate the complete normalized upload entry.
		: array();
	if (
		empty( $file ) ||
		! isset( $file['tmp_name'], $file['error'] )
	) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$upload_error = (int) $file['error'];
	if ( in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'too-large' ) );
	}
	if ( UPLOAD_ERR_OK !== $upload_error ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$tmp_name = (string) $file['tmp_name'];

	// Defence in depth: only ever read a genuine PHP upload, never an arbitrary
	// server path that could reach this handler through a crafted request.
	if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$maximum = erankly_import_export_max_bytes();
	$read    = erankly_import_export_read_bounded_upload( $tmp_name, $maximum );
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

	$started = ERankly_Import_Job_Runner::start( $file, $data, $maximum );
	unset( $data );
	if ( empty( $started['ok'] ) ) {
		$error  = (string) ( $started['error'] ?? '' );
		$notice = 'import_already_running' === $error ? 'import-running' : ( 'unsupported_format' === $error ? 'unsupported-format' : 'import-error' );
		erankly_import_export_redirect( array( 'erankly_io_notice' => $notice ) );
	}
	$job       = is_array( $started['job'] ?? null ) ? $started['job'] : array();
	$job_id    = (string) ( $job['id'] ?? '' );
	$processed = ERankly_Import_Job_Runner::process( $job_id );
	if ( is_array( $processed ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'import-running' ) );
	}

	$finished = get_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, array() );
	if ( ! is_array( $finished ) || 'complete' !== (string) ( $finished['status'] ?? '' ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'import-error' ) );
	}
	$counts = is_array( $finished['counts'] ?? null ) ? $finished['counts'] : array();
	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => 'imported',
			'er_settings'       => (int) ( $counts['settings'] ?? 0 ),
			'er_redirects'      => (int) ( $counts['redirects'] ?? 0 ),
			'er_redirects_transformed' => (int) ( $counts['redirects_transformed'] ?? 0 ),
			'er_redirects_skipped' => (int) ( ( $counts['redirects_unsupported'] ?? 0 ) + ( $counts['redirects_invalid'] ?? 0 ) ),
			'er_post_meta'      => (int) ( $counts['post_meta'] ?? 0 ),
			'er_term_meta'      => (int) ( $counts['term_meta'] ?? 0 ),
			'er_user_meta'      => (int) ( $counts['user_meta'] ?? 0 ),
		)
	);
}

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

/** Handles a manual resume or cancellation of the active migration job. */
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

/** Streams a saved migration report as JSON. */
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
		$queued = ERankly_Migration_Verification_Job::queue( $report_id );
		$notice = $queued ? 'migration-live-running' : 'migration-evidence-error';
	} else {
		$result = erankly_migration_journal()->rollback( $report_id );
		erankly_migration_record_rollback_result( $report_id, $result );
		$status = (string) ( $result['status'] ?? '' );
		$notice = 'running' === $status
			? 'migration-rollback-running'
			: ( 'expired' === $status ? 'migration-rollback-expired' : ( 'failed' === $status ? 'migration-rollback-error' : 'migration-rolled-back' ) );
	}

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => $notice,
			'report_id'         => $report_id,
		)
	);
}

function erankly_import_export_redirect( array $args ): void {
	wp_safe_redirect( add_query_arg( $args, erankly_import_export_url() ) );
	exit;
}
