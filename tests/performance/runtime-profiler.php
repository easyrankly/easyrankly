<?php
/**
 * Opt-in MU-plugin profiler for the performance scenario matrix.
 *
 * Copy or symlink this file into wp-content/mu-plugins, then define
 * ERANKLY_PROFILE_OUTPUT to an absolute writable JSONL path in wp-config.php.
 * Nothing is recorded unless that constant exists.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'ERANKLY_PROFILE_OUTPUT' ) ) {
	return;
}

$GLOBALS['erankly_profile_started_at'] = microtime( true );
$GLOBALS['erankly_profile_media']      = false;

add_action(
	'erankly_admin_media_enqueued',
	static function (): void {
		$GLOBALS['erankly_profile_media'] = true;
	}
);

if ( function_exists( 'xdebug_start_code_coverage' ) ) {
	xdebug_start_code_coverage( defined( 'XDEBUG_CC_UNUSED' ) ? XDEBUG_CC_UNUSED : 0 );
} elseif ( function_exists( 'pcov\\start' ) ) {
	\pcov\start();
}

add_action(
	'shutdown',
	static function (): void {
		if ( ! defined( 'ERANKLY_PATH' ) ) {
			return;
		}

		global $wpdb, $wp_scripts, $wp_styles;

		$plugin_path = wp_normalize_path( ERANKLY_PATH );
		$files       = array();
		$total_bytes = 0;

		foreach ( get_included_files() as $file ) {
			$normalized = wp_normalize_path( $file );
			if ( ! str_starts_with( $normalized, $plugin_path ) ) {
				continue;
			}

			$relative           = ltrim( substr( $normalized, strlen( $plugin_path ) ), '/' );
			$bytes              = is_file( $file ) ? (int) filesize( $file ) : 0;
			$files[ $relative ] = $bytes;
			$total_bytes       += $bytes;
		}

		$asset_rows = static function ( $dependency ): array {
			if ( ! is_object( $dependency ) || ! isset( $dependency->queue, $dependency->registered ) ) {
				return array();
			}

			$rows = array();
			foreach ( (array) $dependency->queue as $handle ) {
				$registered = $dependency->registered[ $handle ] ?? null;
				$src        = is_object( $registered ) ? (string) $registered->src : '';
				$rows[]     = array(
					'handle' => $handle,
					'src'    => $src,
				);
			}

			return $rows;
		};

		$coverage = array();
		if ( function_exists( 'xdebug_get_code_coverage' ) ) {
			$coverage = xdebug_get_code_coverage();
			xdebug_stop_code_coverage( false );
		} elseif ( function_exists( 'pcov\\collect' ) ) {
			$coverage = \pcov\collect();
			\pcov\stop();
		}

		$coverage_summary = array();
		foreach ( $coverage as $file => $lines ) {
			$normalized = wp_normalize_path( (string) $file );
			if ( ! str_starts_with( $normalized, $plugin_path ) || ! is_array( $lines ) ) {
				continue;
			}

			$coverage_summary[ ltrim( substr( $normalized, strlen( $plugin_path ) ), '/' ) ] = array(
				'executable' => count( $lines ),
				'used'       => count( array_filter( $lines, static fn( $value ): bool => (int) $value > 0 ) ),
			);
		}

		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$scenario = defined( 'ERANKLY_PROFILE_SCENARIO' ) ? (string) ERANKLY_PROFILE_SCENARIO : 'unlabelled';
		if ( ! defined( 'ERANKLY_PROFILE_SCENARIO' ) && isset( $_GET['erankly_profile_scenario'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only test label.
			$scenario = sanitize_key( wp_unslash( $_GET['erankly_profile_scenario'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only test label.
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$row         = array(
			'scenario'            => $scenario,
			'url'                 => esc_url_raw( home_url( $request_uri ) ),
			'screen'              => $screen instanceof WP_Screen ? $screen->id : '',
			'elapsed_ms'          => round( ( microtime( true ) - (float) $GLOBALS['erankly_profile_started_at'] ) * 1000, 3 ),
			'memory_peak_bytes'   => memory_get_peak_usage( true ),
			'queries'             => isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0,
			'plugin_source_bytes' => $total_bytes,
			'included_files'      => $files,
			'scripts'             => $asset_rows( $wp_scripts ),
			'styles'              => $asset_rows( $wp_styles ),
			'media_library'       => ! empty( $GLOBALS['erankly_profile_media'] ),
			'coverage'            => $coverage_summary,
		);

		file_put_contents( (string) ERANKLY_PROFILE_OUTPUT, wp_json_encode( $row ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Explicit opt-in test artifact.
	},
	PHP_INT_MAX
);
