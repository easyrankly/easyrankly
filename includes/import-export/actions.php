<?php
/** Import / Export module. Exports and restores all EasyRankly data (settings, redirects, post, term, and user meta) as a single JSON file. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/migrations.php';
require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-admin-presenter.php';
require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';

/** Default maximum size for a complete EasyRankly JSON import. */
define( 'ERANKLY_IMPORT_DEFAULT_MAX_BYTES', 10 * 1024 * 1024 );

/** Memory reserved for WordPress and the import writes after JSON decoding. */
define( 'ERANKLY_IMPORT_MEMORY_RESERVE_BYTES', 8 * 1024 * 1024 );

/** Coarse first-stage cap for the raw upload relative to available memory. */
define( 'ERANKLY_IMPORT_RAW_MEMORY_FACTOR', 16 );

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
 * Reads a verified local file without ever allocating more than the application cap. Upload callers still enforce
 * that the path is a genuine PHP upload; restore callers use the same reader for a private file owned by this
 * plugin. The stat check provides a fast rejection, while the bounded read closes the race where the file changes
 * after its size was inspected.
 *
 * @param string $path    Genuine PHP upload temporary path.
 * @param int    $maximum Maximum number of bytes to read.
 * @return array{ok:bool,error:string,contents:string}
 */
function erankly_import_export_read_bounded_file( string $path, int $maximum ): array {
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

/** Backward-compatible upload-specific name for the bounded local-file reader. */
function erankly_import_export_read_bounded_upload( string $path, int $maximum ): array {
	return erankly_import_export_read_bounded_file( $path, $maximum );
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

	if ( isset( $_GET['erankly_io_action'] ) && 'migration-backup' === sanitize_key( wp_unslash( $_GET['erankly_io_action'] ) ) ) {
		$report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : '';
		check_admin_referer( 'erankly_migration_backup_' . $report_id );
		erankly_migration_backup_download( $report_id );
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

	if ( in_array( $action, array( 'migration-process', 'migration-cancel' ), true ) ) {
		$job_id = isset( $_POST['erankly_migration_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['erankly_migration_job_id'] ) ) : '';
		erankly_import_export_handle_migration_job( $job_id, $action );
	}

	if ( 'migration-restore-backup' === $action ) {
		$report_id = isset( $_POST['erankly_migration_report_id'] ) ? sanitize_text_field( wp_unslash( $_POST['erankly_migration_report_id'] ) ) : '';
		erankly_import_export_handle_backup_restore( $report_id );
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
		$notice = match ( $error ) {
			'import_already_running'      => 'import-running',
			'migration_already_running'   => 'migration-running',
			'transfer_start_in_progress'  => 'transfer-starting',
			'unfiltered_html_required'    => 'custom-code-capability',
			'unsupported_format'          => 'unsupported-format',
			default                       => 'import-error',
		};
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
	} elseif ( 'import_already_running' === $error ) {
		$notice = 'import-running';
	} elseif ( 'transfer_start_in_progress' === $error ) {
		$notice = 'transfer-starting';
	} elseif ( in_array( $error, array( 'backup_write_failed', 'private_storage_unavailable' ), true ) ) {
		$notice = 'migration-backup-failed';
	} elseif ( 'backup_too_large' === $error ) {
		$notice = 'migration-backup-too-large';
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

/**
 * Restores the automatic pre-import backup of one migration report.
 *
 * This is the migration's undo path. It replays the complete backup document through the ordinary import
 * worker, so recovery uses the same code an administrator would trigger by uploading that file by hand.
 */
function erankly_import_export_handle_backup_restore( string $report_id ): void {
	check_admin_referer( 'erankly_migration_backup_' . $report_id );

	try {
		$result = erankly_migration_restore_backup( $report_id );
	} catch ( Throwable ) {
		$result = array(
			'ok'    => false,
			'error' => 'restore_exception',
		);
	}

	$error  = (string) ( $result['error'] ?? '' );
	$notice = 'import-running';
	if ( empty( $result['ok'] ) ) {
		$notice = match ( $error ) {
			'import_already_running'      => 'import-running',
			'migration_already_running'   => 'migration-running',
			'transfer_start_in_progress'  => 'transfer-starting',
			'unfiltered_html_required'    => 'custom-code-capability',
			'backup_unavailable'          => 'migration-backup-expired',
			default                       => 'migration-restore-error',
		};
	}

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => $notice,
			'report_id'         => $report_id,
		)
	);
}

/** Streams one retained migration report as a JSON download. */
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

/** Streams the stored pre-import backup of one migration report. */
function erankly_migration_backup_download( string $report_id ): void {
	$report = erankly_migration_manager()->get_report( $report_id );
	$backup = is_array( $report ) ? erankly_migration_backup_state( $report ) : array();
	$path   = (string) ( $backup['path'] ?? '' );
	if ( '' === $path ) {
		erankly_import_export_redirect(
			array(
				'erankly_io_notice' => 'migration-backup-expired',
				'report_id'         => $report_id,
			)
		);
	}

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=erankly-pre-import-backup-' . gmdate( 'Y-m-d-His' ) . '.json' );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming an owned private file avoids loading a large backup into memory.
	exit;
}

function erankly_import_export_redirect( array $args ): void {
	wp_safe_redirect( add_query_arg( $args, erankly_import_export_url() ) );
	exit;
}
