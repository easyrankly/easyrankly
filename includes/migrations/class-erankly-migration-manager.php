<?php
/** Orchestrates dry runs, conflict-safe writes and migration reports. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Third-party SEO migration service. */
final class ERankly_Migration_Manager {
	private const REPORTS_OPTION = 'erankly_migration_reports_v1';
	private const REPORT_LIMIT   = 10;

	/** @var array<string,ERankly_Migration_Adapter> */
	private array $adapters = array();

	/** @return array<string,ERankly_Migration_Adapter> */
	public function adapters(): array {
		foreach ( array( 'yoast', 'rankmath', 'aioseo', 'seopress' ) as $source ) {
			$this->adapter( $source );
		}

		return $this->adapters;
	}

	/** @return ERankly_Migration_Adapter|null */
	public function adapter( string $source ): ?ERankly_Migration_Adapter {
		$source = sanitize_key( $source );
		if ( isset( $this->adapters[ $source ] ) ) {
			return $this->adapters[ $source ];
		}

		$classes = array(
			'yoast'    => 'ERankly_Migration_Adapter_Yoast',
			'rankmath' => 'ERankly_Migration_Adapter_RankMath',
			'aioseo'   => 'ERankly_Migration_Adapter_AIOSEO',
			'seopress' => 'ERankly_Migration_Adapter_SEOPress',
		);
		if ( ! isset( $classes[ $source ] ) || ! function_exists( 'erankly_migration_load_adapter' ) || ! erankly_migration_load_adapter( $source ) ) {
			return null;
		}

		$class                     = $classes[ $source ];
		$this->adapters[ $source ] = new $class();

		return $this->adapters[ $source ];
	}

	/**
 * Builds the immutable header and zeroed counters for a migration report.
 *
 * @param bool   $dry_run Whether writes are simulated.
 * @param string $run_id  Optional pre-generated run UUID.
 * @return array<string,mixed>
 */
	public function new_report( string $source, bool $dry_run, string $run_id = '' ): array {
		$adapter = $this->adapter( $source );
		$run_id  = '' !== $run_id ? sanitize_text_field( $run_id ) : wp_generate_uuid4();

		return array(
			'id'             => $run_id,
			'source'         => sanitize_key( $source ),
			'source_label'   => $adapter ? $adapter->label() : sanitize_text_field( $source ),
			'source_version' => $adapter ? $adapter->version() : '',
			'mode'           => $dry_run ? 'preview' : 'import',
			'status'         => 'running',
			'started_at'     => gmdate( 'c' ),
			'completed_at'   => '',
			'capabilities'   => $adapter ? $adapter->capabilities() : array(),
			'counts'         => $this->empty_counts(),
			'details'        => array(),
			'warnings'       => array(),
		);
	}

	/** Checks whether stored and proposed redirects have identical behavior. */
	public function same_redirect( array $existing, array $proposed ): bool {
		return hash_equals( $this->redirect_value_hash( $existing ), $this->redirect_value_hash( $proposed ) );
	}

	/**
 * Hashes redirect behavior without provenance or migration-run fields.
 *
 * @param array<string,mixed> $redirect Normalized or stored redirect.
 */
	public function redirect_value_hash( array $redirect ): string {
		$keys     = array( 'source_path', 'source_query', 'target_url', 'status_code', 'match_type', 'case_sensitive', 'trailing_slash', 'query_mode', 'is_active' );
		$behavior = array();
		foreach ( $keys as $key ) {
			$value            = $redirect[ $key ] ?? '';
			$behavior[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
		}

		return hash( 'sha256', (string) wp_json_encode( $behavior ) );
	}

	public function is_meaningful( mixed $value ): bool {
		if ( true === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		return is_string( $value ) && '' !== trim( $value );
	}

	/** @return array<string,int> */
	public function empty_counts(): array {
		return array(
			'settings_found'          => 0,
			'settings_ready'          => 0,
			'settings_written'        => 0,
			'settings_identical'      => 0,
			'settings_conflicts'      => 0,
			'settings_failed'         => 0,
			'objects_found'           => 0,
			'posts_found'             => 0,
			'terms_found'             => 0,
			'users_found'             => 0,
			'objects_invalid'         => 0,
			'fields_found'            => 0,
			'fields_ready'            => 0,
			'fields_written'          => 0,
			'post_fields_ready'       => 0,
			'post_fields_written'     => 0,
			'term_fields_ready'       => 0,
			'term_fields_written'     => 0,
			'user_fields_ready'       => 0,
			'user_fields_written'     => 0,
			'fields_identical'        => 0,
			'fields_unsupported'      => 0,
			'fields_conflicts'        => 0,
			'fields_invalid'          => 0,
			'fields_failed'           => 0,
			'redirects_found'         => 0,
			'redirects_ready_create'  => 0,
			'redirects_ready_update'  => 0,
			'redirects_created'       => 0,
			'redirects_updated'       => 0,
			'redirects_unchanged'     => 0,
			'redirects_conflicts'     => 0,
			'redirects_invalid'       => 0,
			'redirects_unsupported'   => 0,
			'redirects_transformed'   => 0,
			'redirects_failed'        => 0,
		);
	}

	/**
 * Completes and persists a bounded report history.
 *
 * @return array<string,mixed>
 */
	public function finish_report( array $report ): array {
		if ( empty( $report['completed_at'] ) ) {
			$report['completed_at'] = gmdate( 'c' );
		}
		$report['verification']   = $this->build_verification( $report );
		$reports                  = get_option( self::REPORTS_OPTION, array() );
		$reports                  = is_array( $reports ) ? $reports : array();
		$reports[ $report['id'] ] = $report;

		$report_count = count( $reports );
		while ( $report_count > self::REPORT_LIMIT ) {
			$expired_report = (array) reset( $reports );
			array_shift( $reports );
			// The pre-import backup only exists to undo its own report, so it goes with it.
			$expired_backup = is_array( $expired_report['backup'] ?? null ) ? (string) ( $expired_report['backup']['path'] ?? '' ) : '';
			if ( '' !== $expired_backup && class_exists( 'ERankly_Migration_Upload_Store' ) ) {
				ERankly_Migration_Upload_Store::delete( $expired_backup );
			}
			--$report_count;
		}

		update_option( self::REPORTS_OPTION, $reports, false );

		return $report;
	}

	/**
 * Builds a deterministic post-preview/import decision and switch checklist.
 *
 * @return array<string,mixed>
 */
	private function build_verification( array $report ): array {
		$counts          = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
		$mode            = (string) ( $report['mode'] ?? '' );
		$status          = (string) ( $report['status'] ?? 'failed' );
		$failed          = (int) ( $counts['settings_failed'] ?? 0 ) + (int) ( $counts['fields_failed'] ?? 0 ) + (int) ( $counts['redirects_failed'] ?? 0 );
		$invalid         = (int) ( $counts['objects_invalid'] ?? 0 ) + (int) ( $counts['fields_invalid'] ?? 0 ) + (int) ( $counts['redirects_invalid'] ?? 0 ) + (int) ( $counts['redirects_unsupported'] ?? 0 );
		$conflicts       = (int) ( $counts['settings_conflicts'] ?? 0 ) + (int) ( $counts['fields_conflicts'] ?? 0 ) + (int) ( $counts['redirects_conflicts'] ?? 0 );
		$warnings        = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array();
		$blocking_warnings = array_filter(
			$warnings,
			static fn( mixed $warning ): bool => ! is_array( $warning ) || ! isset( $warning['blocking'] ) || (bool) $warning['blocking']
		);
		$source_verified = ! empty( $report['source_fingerprint_verified'] );
		$blocked         = 'complete' !== $status || $failed > 0 || ! $source_verified || ! empty( $blocking_warnings );
		$needs_review    = $invalid > 0 || $conflicts > 0;

		if ( $blocked ) {
			$state = 'blocked';
		} elseif ( $needs_review ) {
			$state = 'review';
		} elseif ( 'preview' === $mode ) {
			$state = 'ready';
		} else {
			$state = 'safe';
		}

		$checks = array(
			array(
				'code'   => 'source_integrity',
				'status' => $source_verified ? 'pass' : 'fail',
				'count'  => $source_verified ? 0 : 1,
			),
			array(
				'code'   => 'write_failures',
				'status' => $failed > 0 ? 'fail' : ( 'preview' === $mode ? 'not_applicable' : 'pass' ),
				'count'  => $failed,
			),
			array(
				'code'   => 'invalid_records',
				'status' => $invalid > 0 ? 'warn' : 'pass',
				'count'  => $invalid,
			),
			array(
				'code'   => 'conflicts',
				'status' => $conflicts > 0 ? 'warn' : 'pass',
				'count'  => $conflicts,
			),
			array(
				'code'   => 'diagnostics',
				'status' => $blocking_warnings ? 'warn' : 'pass',
				'count'  => count( $blocking_warnings ),
			),
		);

		$next_actions = array();
		if ( 'blocked' === $state ) {
			$next_actions[] = 'keep_source_active';
			$next_actions[] = 'resolve_blockers';
		} elseif ( 'preview' === $mode ) {
			if ( 'review' === $state ) {
				$next_actions[] = 'review_diagnostics';
			}
			$next_actions[] = 'run_import';
		} else {
			if ( 'review' === $state ) {
				$next_actions[] = 'review_diagnostics';
				$next_actions[] = 'keep_source_active';
			}
			$next_actions[] = 'controlled_deactivation';
			$next_actions[] = 'purge_caches';
			$next_actions[] = 'verify_frontend';
			$next_actions[] = 'retain_source_backup';
		}

		return array(
			'state'           => $state,
			'ready_to_import' => 'preview' === $mode && ! $blocked,
			'ready_to_switch' => 'import' === $mode && 'safe' === $state,
			'checks'          => $checks,
			'next_actions'    => $next_actions,
		);
	}

	/**
 * Returns a persisted migration report.
 *
 * @return array<string,mixed>|null
 */
	public function get_report( string $report_id ): ?array {
		$reports = get_option( self::REPORTS_OPTION, array() );
		if ( ! is_array( $reports ) || ! isset( $reports[ $report_id ] ) || ! is_array( $reports[ $report_id ] ) ) {
			return null;
		}
		return $reports[ $report_id ];
	}

	/** @return array<int,array<string,mixed>> */
	public function reports(): array {
		$reports = get_option( self::REPORTS_OPTION, array() );
		if ( ! is_array( $reports ) ) {
			return array();
		}
		return array_values( array_filter( array_reverse( array_values( $reports ) ), 'is_array' ) );
	}

	/** Replaces one existing report after a privileged verification or rollback action. */
	public function update_report( array $report ): bool {
		$report_id = sanitize_text_field( (string) ( $report['id'] ?? '' ) );
		$reports   = get_option( self::REPORTS_OPTION, array() );
		if ( '' === $report_id || ! is_array( $reports ) || ! isset( $reports[ $report_id ] ) ) {
			return false;
		}
		$reports[ $report_id ] = $report;

		return update_option( self::REPORTS_OPTION, $reports, false );
	}
}
