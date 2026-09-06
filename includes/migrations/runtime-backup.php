<?php
/** Automatic pre-import backup and restore. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads the bounded native-import validators used to verify an automatic backup before it can be restored. */
function erankly_migration_load_backup_import_helpers(): void {
	if ( ! function_exists( 'erankly_import_export_read_bounded_file' ) ) {
		require_once ERANKLY_PATH . 'includes/import-export/actions.php';
	}
}

/**
 * Decodes a private backup under exactly the same byte, structural and memory limits as an uploaded import.
 *
 * @return array{ok:bool,error:string,data?:array<string,mixed>}
 */
function erankly_migration_read_backup_document( string $path ): array {
	erankly_migration_load_backup_import_helpers();

	$read = erankly_import_export_read_bounded_file( $path, erankly_import_export_max_bytes() );
	if ( empty( $read['ok'] ) ) {
		return array(
			'ok'    => false,
			'error' => 'too-large' === (string) ( $read['error'] ?? '' ) ? 'backup_too_large' : 'backup_unreadable',
		);
	}

	$raw = (string) ( $read['contents'] ?? '' );
	if ( '' === trim( $raw ) || '' !== erankly_import_export_json_memory_error( $raw ) ) {
		return array(
			'ok'    => false,
			'error' => 'backup_unreadable',
		);
	}

	$data = json_decode( $raw, true, ERANKLY_IMPORT_JSON_MAX_DEPTH );
	unset( $raw, $read );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || 'erankly' !== (string) ( $data['plugin'] ?? '' ) ) {
		return array(
			'ok'    => false,
			'error' => 'backup_unreadable',
		);
	}

	return array(
		'ok'   => true,
		'error' => '',
		'data' => $data,
	);
}

/**
 * Writes a complete EasyRankly backup before a migration performs its first write.
 *
 * This is the migration's undo path. It reuses the native export writer, so a restore replays exactly the
 * document the Export tab produces and therefore covers settings, redirects and every meta row, not only the
 * values one particular migration touched.
 *
 * @return array{ok:bool,path?:string,bytes?:int,created_at?:string,error?:string}
 */
function erankly_migration_create_backup(): array {
	require_once ERANKLY_PATH . 'includes/import-export/export.php';

	$path = ERankly_Migration_Upload_Store::reserve_backup_path();
	if ( '' === $path ) {
		return array(
			'ok'    => false,
			'error' => 'private_storage_unavailable',
		);
	}

	$handle = fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation in private OS-temp storage.
	if ( false === $handle ) {
		return array(
			'ok'    => false,
			'error' => 'backup_write_failed',
		);
	}

	try {
		erankly_export_write( $handle );
	} catch ( Throwable ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired private handle.
		ERankly_Migration_Upload_Store::delete( $path );
		return array(
			'ok'    => false,
			'error' => 'backup_write_failed',
		);
	}
	if ( ! fclose( $handle ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verify that all buffered backup data reached private storage.
		ERankly_Migration_Upload_Store::delete( $path );
		return array(
			'ok'    => false,
			'error' => 'backup_write_failed',
		);
	}

	if ( ! chmod( $path, 0600 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Backup permissions must not depend on the process umask.
		ERankly_Migration_Upload_Store::delete( $path );
		return array(
			'ok'    => false,
			'error' => 'backup_write_failed',
		);
	}

	clearstatcache( true, $path );
	$bytes = filesize( $path );
	if ( false === $bytes || $bytes < 2 ) {
		ERankly_Migration_Upload_Store::delete( $path );
		return array(
			'ok'    => false,
			'error' => 'backup_write_failed',
		);
	}

	$validated = erankly_migration_read_backup_document( $path );
	if ( empty( $validated['ok'] ) ) {
		ERankly_Migration_Upload_Store::delete( $path );
		return array(
			'ok'    => false,
			'error' => sanitize_key( (string) ( $validated['error'] ?? 'backup_write_failed' ) ),
		);
	}
	unset( $validated );

	return array(
		'ok'         => true,
		'path'       => wp_normalize_path( $path ),
		'bytes'      => (int) $bytes,
		'created_at' => gmdate( 'c' ),
	);
}

/**
 * Returns the backup descriptor of one report when the file is still restorable.
 *
 * @return array<string,mixed> Empty when no usable backup remains.
 */
function erankly_migration_backup_state( array $report ): array {
	$backup = is_array( $report['backup'] ?? null ) ? $report['backup'] : array();
	$path   = wp_normalize_path( (string) ( $backup['path'] ?? '' ) );
	if ( '' === $path || ! ERankly_Migration_Upload_Store::is_backup( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
		return array();
	}

	$created = strtotime( (string) ( $backup['created_at'] ?? '' ) );
	if ( false === $created || $created + ERankly_Migration_Upload_Store::backup_ttl() <= time() ) {
		return array();
	}
	$backup['expires_at'] = gmdate( 'c', $created + ERankly_Migration_Upload_Store::backup_ttl() );

	return $backup;
}

/**
 * Lists retained backup paths which are still inside the restore window.
 *
 * This remains useful to integrations that display available recovery artefacts. The upload store applies
 * the same bounded lifetime independently, so an old report can never extend a backup's retention period.
 *
 * @return array<int,string>
 */
function erankly_migration_referenced_backups(): array {
	$reports = get_option( 'erankly_migration_reports_v1', array() );
	if ( ! is_array( $reports ) ) {
		return array();
	}

	$paths  = array();
	$cutoff = time() - ERankly_Migration_Upload_Store::backup_ttl();
	foreach ( $reports as $report ) {
		$backup  = is_array( $report ) && is_array( $report['backup'] ?? null ) ? $report['backup'] : array();
		$created = strtotime( (string) ( $backup['created_at'] ?? '' ) );
		$path    = (string) ( $backup['path'] ?? '' );
		if ( '' !== $path && false !== $created && $created > $cutoff ) {
			$paths[] = wp_normalize_path( $path );
		}
	}

	return $paths;
}

/**
 * Restores the pre-import backup of one migration report.
 *
 * @return array{ok:bool,error?:string,job?:array<string,mixed>}
 */
function erankly_migration_restore_backup( string $report_id ): array {
	require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';

	$report = erankly_migration_manager()->get_report( $report_id );
	$backup = is_array( $report ) ? erankly_migration_backup_state( $report ) : array();
	$path   = (string) ( $backup['path'] ?? '' );
	if ( '' === $path ) {
		return array(
			'ok'    => false,
			'error' => 'backup_unavailable',
		);
	}

	// The spool stage consumes the file it reads, so restore from a copy and keep the backup itself
	// available for a second attempt.
	$working = ERankly_Migration_Upload_Store::reserve_import_path();
	if ( '' === $working || ! copy( $path, $working ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Private OS-temp duplication of an owned backup.
		return array(
			'ok'    => false,
			'error' => 'backup_unavailable',
		);
	}

	$decoded = erankly_migration_read_backup_document( $working );
	if ( empty( $decoded['ok'] ) || ! is_array( $decoded['data'] ?? null ) ) {
		ERankly_Migration_Upload_Store::delete( $working );
		return array(
			'ok'    => false,
			'error' => 'backup_unreadable',
		);
	}

	$result = ERankly_Import_Job_Runner::start_from_file( $working, $decoded['data'] );
	unset( $decoded );
	if ( empty( $result['ok'] ) ) {
		ERankly_Migration_Upload_Store::delete( $working );
	}

	return $result;
}
