<?php
/**
 * Dependency-free regression tests for memory-safe complete JSON imports.
 *
 * Run: php tests/security-import-memory.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress stubs and temporary files.

define( 'ABSPATH', __DIR__ . '/' );
define( 'ERANKLY_PATH', dirname( __DIR__ ) . '/' );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['erankly_import_test_max_bytes'] = 4096;

function apply_filters( string $hook, $value, ...$args ) {
	unset( $args );
	return 'erankly_import_export_max_bytes' === $hook
		? $GLOBALS['erankly_import_test_max_bytes']
		: $value;
}

function wp_convert_hr_to_bytes( string $value ): int {
	$value = trim( $value );
	if ( '-1' === $value ) {
		return -1;
	}

	$unit   = strtolower( substr( $value, -1 ) );
	$number = (int) $value;
	if ( 'g' === $unit ) {
		return $number * 1024 * 1024 * 1024;
	}
	if ( 'm' === $unit ) {
		return $number * MB_IN_BYTES;
	}
	if ( 'k' === $unit ) {
		return $number * 1024;
	}

	return $number;
}

require_once dirname( __DIR__ ) . '/includes/import-export.php';

function erankly_import_memory_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$maximum = erankly_import_export_max_bytes();
erankly_import_memory_assert( $maximum >= 1024 && $maximum <= 4096, 'The configured ceiling must be enforced and may only be reduced by the PHP memory budget.' );

$directory = sys_get_temp_dir() . '/erankly-import-memory-' . bin2hex( random_bytes( 6 ) );
erankly_import_memory_assert( mkdir( $directory, 0700 ), 'The isolated test directory must be created.' );

$valid_path = $directory . '/valid.json';
$valid_json = '{"plugin":"erankly"}';
erankly_import_memory_assert( strlen( $valid_json ) === file_put_contents( $valid_path, $valid_json ), 'The valid fixture must be written.' );
$valid = erankly_import_export_read_bounded_upload( $valid_path, $maximum );
erankly_import_memory_assert( ! empty( $valid['ok'] ) && $valid_json === $valid['contents'], 'A valid file below the limit must be read completely.' );

$exact_path = $directory . '/exact.json';
erankly_import_memory_assert( $maximum === file_put_contents( $exact_path, str_repeat( 'x', $maximum ) ), 'The boundary fixture must be written.' );
$exact = erankly_import_export_read_bounded_upload( $exact_path, $maximum );
erankly_import_memory_assert( ! empty( $exact['ok'] ) && $maximum === strlen( $exact['contents'] ), 'A file exactly at the limit must be accepted without truncation.' );

$oversized_path = $directory . '/oversized.json';
erankly_import_memory_assert( $maximum + 1 === file_put_contents( $oversized_path, str_repeat( 'x', $maximum + 1 ) ), 'The oversized fixture must be written.' );
$oversized = erankly_import_export_read_bounded_upload( $oversized_path, $maximum );
erankly_import_memory_assert( empty( $oversized['ok'] ) && 'too-large' === $oversized['error'] && '' === $oversized['contents'], 'An oversized file must be rejected without returning file contents.' );

$empty_path = $directory . '/empty.json';
erankly_import_memory_assert( 0 === file_put_contents( $empty_path, '' ), 'The empty fixture must be written.' );
$empty = erankly_import_export_read_bounded_upload( $empty_path, $maximum );
erankly_import_memory_assert( empty( $empty['ok'] ) && 'invalid' === $empty['error'], 'An empty upload must be rejected.' );

$normal_json = '{"plugin":"erankly","settings":{"separator":"-"},"post_meta":[{"id":1,"key":"title","value":"Example"}]}';
erankly_import_memory_assert( '' === erankly_import_export_json_memory_error( $normal_json ), 'A normal EasyRankly export structure must fit the decode budget.' );

$pathological_json = '{"plugin":"erankly","junk":[' . str_repeat( '[0],', 260000 ) . '[0]]}';
erankly_import_memory_assert(
	'too-complex' === erankly_import_export_json_memory_error( $pathological_json ),
	'A compact JSON document with enough small nested arrays to exhaust decoded memory must be rejected before json_decode().'
);

$deep_json = str_repeat( '[', ERANKLY_IMPORT_JSON_MAX_DEPTH + 1 ) . '0' . str_repeat( ']', ERANKLY_IMPORT_JSON_MAX_DEPTH + 1 );
erankly_import_memory_assert( 'too-complex' === erankly_import_export_json_memory_error( $deep_json ), 'JSON deeper than the supported export schema must be rejected before decoding.' );
erankly_import_memory_assert( 'invalid' === erankly_import_export_json_memory_error( '{"plugin":"erankly"]' ), 'Unbalanced JSON must remain an ordinary invalid-file error.' );

$source       = (string) file_get_contents( dirname( __DIR__ ) . '/includes/import-export.php' );
$upload_store = (string) file_get_contents( dirname( __DIR__ ) . '/includes/migrations/class-erankly-migration-upload-store.php' );
$job_runner   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-erankly-import-job-runner.php' );
erankly_import_memory_assert( 1 === preg_match( '/file_get_contents\(\s*\$path,\s*false,\s*null,\s*0,\s*\$maximum\s*\+\s*1\s*\)/', $source ), 'The production read must remain byte-bounded even after the size stat.' );
erankly_import_memory_assert( false !== strpos( $source, 'erankly_import_export_read_bounded_upload( $tmp_name, $maximum )' ), 'The HTTP import handler must use the bounded reader before decoding.' );
erankly_import_memory_assert( false !== strpos( $source, 'ERankly_Import_Job_Runner::start( $file, $data, $maximum )' ), 'The validated normalized upload entry and its byte cap must reach the private store.' );
erankly_import_memory_assert( false !== strpos( $upload_store, 'wp_handle_upload(' ) && false === strpos( $upload_store, 'move_uploaded_file(' ), 'Genuine HTTP uploads must pass through the WordPress uploader instead of calling move_uploaded_file() directly.' );
erankly_import_memory_assert( false !== strpos( $upload_store, "add_filter( 'upload_dir'" ) && false !== strpos( $upload_store, "remove_filter( 'upload_dir'" ), 'The private upload directory override must be installed and removed around one synchronous WordPress upload.' );
erankly_import_memory_assert( false === strpos( $job_runner, 'move_uploaded_file(' ), 'The resumable import runner must delegate HTTP file movement to the shared WordPress uploader.' );
$preflight_position = strpos( $source, 'erankly_import_export_json_memory_error( $contents )' );
$decode_position    = strpos( $source, 'json_decode( $contents' );
erankly_import_memory_assert( false !== $preflight_position && false !== $decode_position && $preflight_position < $decode_position, 'The structural memory preflight must run before json_decode().' );

unlink( $valid_path );
unlink( $exact_path );
unlink( $oversized_path );
unlink( $empty_path );
rmdir( $directory );

fwrite( STDOUT, "Memory-safe complete JSON import security contract passed.\n" );
