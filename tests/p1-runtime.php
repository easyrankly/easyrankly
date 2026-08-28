<?php
/**
 * WordPress-runtime regressions for the P1 hardening work.
 *
 * Run from the site root with: studio wp eval-file wp-content/plugins/easyrankly/tests/p1-runtime.php
 *
 * @package EasyRankly
 */

// phpcs:disable -- Runtime integration harness with temporary fixtures and reflection assertions.

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! class_exists( 'ERankly_Migration_Export_Reader' ) ) {
	require_once ERANKLY_PATH . 'includes/migrations.php';
}
if ( ! class_exists( 'ERankly_Import_Job_Runner' ) ) {
	require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';
}
erankly_p1_assert( false !== has_action( ERANKLY_MIGRATION_VERIFY_CRON_HOOK, 'erankly_process_migration_verification' ), 'background verification cron callback must be registered' );

/** Fails one P1 runtime invariant. */
function erankly_p1_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'P1 regression failed: ' . $message );
	}
}

$fixture_directory = trailingslashit( get_temp_dir() ) . 'erankly-p1-' . wp_generate_password( 12, false, false );
erankly_p1_assert( wp_mkdir_p( $fixture_directory ), 'temporary fixture directory must be created' );

$managed_paths = array();
try {
	$lock_cases = array(
		array( ERankly_Import_Job_Runner::class, null, 'acquire_lock', 'renew_lock', 'release_lock', 'erankly_import_lock_' ),
		array( ERankly_Migration_Verification_Job::class, null, 'acquire_lock', 'renew_lock', 'release_lock', 'erankly_migration_verify_lock_' ),
		array( ERankly_Migration_Journal::class, new ERankly_Migration_Journal(), 'acquire_rollback_lock', 'renew_rollback_lock', 'release_rollback_lock', 'erankly_migration_rollback_lock_' ),
	);
	foreach ( $lock_cases as $lock_case ) {
		list( $class, $instance, $acquire_name, $renew_name, $release_name, $prefix ) = $lock_case;
		$lock_id  = wp_generate_uuid4();
		$lock_key = $prefix . substr( hash( 'sha256', $lock_id ), 0, 24 );
		$stale    = array(
			'token'   => 'stale-owner',
			'expires' => time() - 10,
		);
		add_option( $lock_key, $stale, '', 'no' );
		try {
			$acquire = new ReflectionMethod( $class, $acquire_name );
			$renew   = new ReflectionMethod( $class, $renew_name );
			$release = new ReflectionMethod( $class, $release_name );
			$token   = (string) $acquire->invoke( $instance, $lock_id );
			erankly_p1_assert( '' !== $token && 'stale-owner' !== $token, $class . ' must take over a stale lock atomically' );
			$release->invoke( $instance, $lock_id, 'stale-owner' );
			$current = get_option( $lock_key, array() );
			erankly_p1_assert( is_array( $current ) && $token === (string) ( $current['token'] ?? '' ), $class . ' must not release a successor token' );
			$expires = (int) ( $current['expires'] ?? 0 );
			erankly_p1_assert( true === $renew->invoke( $instance, $lock_id, $token ), $class . ' must renew its owned lease' );
			$current = get_option( $lock_key, array() );
			erankly_p1_assert( is_array( $current ) && (int) ( $current['expires'] ?? 0 ) > $expires, $class . ' renewal must advance the expiry' );
			$release->invoke( $instance, $lock_id, $token );
			erankly_p1_assert( false === get_option( $lock_key, false ), $class . ' must release its exact token' );
		} finally {
			delete_option( $lock_key );
		}
	}

	$csv_fixture = $fixture_directory . '/rankmath.csv';
	file_put_contents(
		$csv_fixture,
		"id,seo_title,seo_description\n1,First title,First description\n2,Second title,Second description\n"
	);
	$csv = ERankly_Migration_Upload_Store::stage_trusted_file( $csv_fixture, 'rankmath' );
	erankly_p1_assert( ! empty( $csv['ok'] ), 'Rank Math metadata CSV must be certified' );
	$managed_paths[] = (string) $csv['path'];

	$first = ERankly_Migration_Export_Reader::content_batch( (string) $csv['path'], 'rankmath', array(), 1 );
	erankly_p1_assert( 1 === count( $first['records'] ), 'first CSV batch must contain one record' );
	erankly_p1_assert( ! empty( $first['cursor']['byte'] ) && empty( $first['done'] ), 'first CSV batch must persist a non-terminal byte cursor' );
	$second = ERankly_Migration_Export_Reader::content_batch( (string) $csv['path'], 'rankmath', $first['cursor'], 1 );
	erankly_p1_assert( 1 === count( $second['records'] ) && ! empty( $second['done'] ), 'second CSV batch must resume and finish' );
	erankly_p1_assert( (int) $second['cursor']['byte'] > (int) $first['cursor']['byte'], 'CSV byte cursor must move forward' );
	$csv_cursor_rejected = false;
	try {
		ERankly_Migration_Export_Reader::content_batch(
			(string) $csv['path'],
			'rankmath',
			array(
				'row'  => 1,
				'byte' => (int) filesize( (string) $csv['path'] ) + 1,
			),
			1
		);
	} catch ( RuntimeException ) {
		$csv_cursor_rejected = true;
	}
	erankly_p1_assert( $csv_cursor_rejected, 'a CSV checkpoint outside the source file must fail closed' );
	$wrong_stream = ERankly_Migration_Export_Reader::redirect_batch( (string) $csv['path'], 'rankmath', array(), 10 );
	erankly_p1_assert( ! empty( $wrong_stream['done'] ) && array() === $wrong_stream['records'], 'metadata CSV must never enter the redirect mapper' );

	$json_fixture = $fixture_directory . '/aioseo.json';
	file_put_contents(
		$json_fixture,
		wp_json_encode(
			array(
				'redirects' => array(
					array( 'source_url' => '/old-one', 'target_url' => '/new-one', 'type' => 301 ),
					array( 'source_url' => '/old-two', 'target_url' => '/new-two', 'type' => 302 ),
				),
			)
		)
	);
	$json = ERankly_Migration_Upload_Store::stage_trusted_file( $json_fixture, 'aioseo' );
	erankly_p1_assert( ! empty( $json['ok'] ), 'AIOSEO JSON must be certified' );
	$managed_paths[] = (string) $json['path'];
	$sidecar         = (string) $json['path'] . '.ndjson';
	erankly_p1_assert( is_file( $sidecar ) && ERankly_Migration_Upload_Store::owns( $sidecar ), 'AIOSEO JSON must have a managed private NDJSON sidecar' );

	$json_first = ERankly_Migration_Export_Reader::redirect_batch( (string) $json['path'], 'aioseo', array(), 1 );
	$json_second = ERankly_Migration_Export_Reader::redirect_batch( (string) $json['path'], 'aioseo', $json_first['cursor'], 1 );
	erankly_p1_assert( 1 === count( $json_first['records'] ) && 1 === count( $json_second['records'] ), 'JSON batches must each read one normalized row' );
	erankly_p1_assert( ! empty( $json_second['done'] ) && (int) $json_second['cursor']['byte'] > (int) $json_first['cursor']['byte'], 'JSON byte cursor must resume and finish' );
	$json_cursor_rejected = false;
	try {
		ERankly_Migration_Export_Reader::redirect_batch(
			(string) $json['path'],
			'aioseo',
			array(
				'row'  => 1,
				'byte' => (int) filesize( $sidecar ) + 1,
			),
			1
		);
	} catch ( RuntimeException ) {
		$json_cursor_rejected = true;
	}
	erankly_p1_assert( $json_cursor_rejected, 'a JSON checkpoint outside the normalized index must fail closed' );
	erankly_p1_assert( ERankly_Migration_Upload_Store::export_max_bytes( 'json' ) <= ERankly_Migration_Upload_Store::export_max_bytes( 'csv' ), 'JSON upload and reader limits must be aligned' );

	$import_path = trailingslashit( ERankly_Migration_Upload_Store::directory() ) . 'erankly-import-' . bin2hex( random_bytes( 16 ) ) . '.json';
	file_put_contents( $import_path, '{}' );
	chmod( $import_path, 0600 );
	$managed_paths[] = $import_path;
	$stage_spool     = new ReflectionMethod( ERankly_Import_Job_Runner::class, 'stage_spool' );
	$spool           = $stage_spool->invoke(
		null,
		$import_path,
		array(
			'plugin'    => 'erankly',
			'settings'  => array(),
			'redirects' => array(
				array( 'source_path' => '/one', 'target_url' => '/two' ),
				array( 'source_path' => '/three', 'target_url' => '/four' ),
			),
		)
	);
	erankly_p1_assert( ! empty( $spool['ok'] ) && (int) $spool['stage_ends']['redirects'] > (int) $spool['stage_offsets']['redirects'], 'native JSON must be staged once into a seekable spool' );
	$spool_path     = (string) $spool['path'];
	$managed_paths[] = $spool_path;
	erankly_p1_assert( ! file_exists( $import_path ) && str_ends_with( $spool_path, '.json.spool' ), 'native staging must publish a dedicated cross-platform spool and remove the decoded source' );
	$spool_file = new SplFileObject( $spool_path, 'rb' );
	$header     = json_decode( trim( (string) $spool_file->fgets() ), true );
	erankly_p1_assert( ! isset( $header['payload']['redirects'] ), 'bulk streams must not be duplicated in the spool header' );

	$origin_check = new ReflectionMethod( ERankly_Migration_Live_Verifier::class, 'is_same_origin' );
	$verifier     = new ERankly_Migration_Live_Verifier();
	$home         = home_url( '/' );
	$other_scheme = str_starts_with( $home, 'https://' ) ? preg_replace( '/^https:/', 'http:', $home ) : preg_replace( '/^http:/', 'https:', $home );
	erankly_p1_assert( true === $origin_check->invoke( $verifier, $home ), 'home URL must be accepted as same-origin' );
	erankly_p1_assert( false === $origin_check->invoke( $verifier, (string) $other_scheme ), 'a scheme change must be rejected' );

	$request_args = array();
	$fake_http    = static function ( mixed $preempt, array $args, string $url ) use ( &$request_args ): array {
		unset( $preempt, $url );
		$request_args[] = $args;
		return array(
			'headers'  => array(),
			'body'     => '<html><head><title>EasyRankly P1</title></head></html>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $fake_http, 10, 3 );
	add_filter( 'erankly_migration_live_batch_size', static fn(): int => 1 );
	try {
		$report = array(
			'html_baseline' => array(
				'state' => 'captured',
				'pages' => array(
					home_url( '/?p1=one' ) => array( 'request_state' => 'ok', 'semantic_hash' => 'before-one', 'fields' => array( 'title' ) ),
					home_url( '/?p1=two' ) => array( 'request_state' => 'ok', 'semantic_hash' => 'before-two', 'fields' => array( 'title' ) ),
				),
				'redirects' => array(),
				'surfaces'  => array(),
			),
		);
		$batch_one = ( new ERankly_Migration_Live_Verifier() )->verify_batch( $report );
		erankly_p1_assert( empty( $batch_one['done'] ) && 1 === (int) $batch_one['checkpoint']['position'], 'live verifier must stop after its configured batch size' );
		$batch_two = ( new ERankly_Migration_Live_Verifier() )->verify_batch( $report, $batch_one['checkpoint'] );
		erankly_p1_assert( ! empty( $batch_two['done'] ) && 2 === count( $request_args ), 'live verifier must resume the next target without repeating the first' );
		erankly_p1_assert( ! empty( $request_args[0]['reject_unsafe_urls'] ) && 0 === $request_args[0]['redirection'] && (int) $request_args[0]['limit_response_size'] > 0, 'live HTTP requests must be safe, non-following and byte-bounded' );

		$reports_option = 'erankly_migration_reports_v1';
		$reports_before = get_option( $reports_option, null );
		$report_id      = wp_generate_uuid4();
		$job_key        = 'erankly_migration_verify_job_' . substr( hash( 'sha256', $report_id ), 0, 24 );
		$lock_key       = 'erankly_migration_verify_lock_' . substr( hash( 'sha256', $report_id ), 0, 24 );
		$reports        = is_array( $reports_before ) ? $reports_before : array();
		$reports[ $report_id ] = array_merge(
			$report,
			array(
				'id'                          => $report_id,
				'mode'                        => 'import',
				'status'                      => 'complete',
				'source'                      => 'rankmath',
				'source_fingerprint_verified' => true,
				'counts'                      => array(),
				'evidence'                    => array(),
				'warnings'                    => array(),
			)
		);
		update_option( $reports_option, $reports, false );
		try {
			erankly_p1_assert( ERankly_Migration_Verification_Job::queue( $report_id ), 'live verification must queue without issuing HTTP in the admin action' );
			$queued = get_option( $job_key, null );
			erankly_p1_assert( is_array( $queued ) && 'queued' === (string) $queued['status'], 'queued verification must have a durable checkpoint' );
			ERankly_Migration_Verification_Job::process( $report_id );
			$running = get_option( $job_key, null );
			erankly_p1_assert( is_array( $running ) && 1 === (int) $running['checkpoint']['position'], 'first background worker must persist its next task position' );
			ERankly_Migration_Verification_Job::process( $report_id );
			$finished_report = erankly_migration_manager()->get_report( $report_id );
			erankly_p1_assert( false === get_option( $job_key, false ) && is_array( $finished_report ) && 'queued' !== (string) ( $finished_report['live_verification']['state'] ?? '' ), 'terminal worker must persist the result and remove its job' );
		} finally {
			delete_option( $job_key );
			delete_option( $lock_key );
			wp_clear_scheduled_hook( ERANKLY_MIGRATION_VERIFY_CRON_HOOK, array( $report_id ) );
			if ( null === $reports_before ) {
				delete_option( $reports_option );
			} else {
				update_option( $reports_option, $reports_before, false );
			}
		}
	} finally {
		remove_filter( 'pre_http_request', $fake_http, 10 );
		remove_all_filters( 'erankly_migration_live_batch_size' );
	}
} finally {
	foreach ( $managed_paths as $managed_path ) {
		ERankly_Migration_Upload_Store::delete( $managed_path );
	}
	foreach ( array( $csv_fixture ?? '', $json_fixture ?? '' ) as $fixture ) {
		if ( '' !== $fixture && is_file( $fixture ) ) {
			unlink( $fixture );
		}
	}
	if ( is_dir( $fixture_directory ) ) {
		rmdir( $fixture_directory );
	}
}

echo "P1 runtime regressions passed.\n";
