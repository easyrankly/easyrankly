<?php
/**
 * Fail-closed migration go-live decision engine.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Converts persisted migration evidence into a deterministic cutover verdict. */
final class ERankly_Migration_Go_Live_Gate {
	private const CONTRACT_VERSION = 1;

	/**
	 * Evaluates a terminal report using the current rollback state.
	 *
	 * @param array<string,mixed> $report Terminal migration report.
	 * @param array<string,mixed> $rollback Current rollback-journal summary.
	 * @return array<string,mixed> Machine-readable go-live decision.
	 */
	public function evaluate( array $report, array $rollback = array() ): array {
		$mode = sanitize_key( (string) ( $report['mode'] ?? '' ) );
		if ( 'import' !== $mode ) {
			return $this->decision( 'preview_only', 'not_applicable', 'none', false, false, false, array(), array( 'run_import' ) );
		}

		$rollback_result = is_array( $report['rollback_result'] ?? null ) ? $report['rollback_result'] : array();
		if ( $rollback_result ) {
			$rollback_failed = (int) ( $rollback_result['failed'] ?? 0 ) > 0 || in_array( (string) ( $rollback_result['status'] ?? '' ), array( 'failed', 'expired' ), true );
			$check           = $this->check( 'rollback_result', $rollback_failed ? 'fail' : 'pass', (int) ( $rollback_result['failed'] ?? 0 ), true );
			return $this->decision(
				$rollback_failed ? 'rollback_failed' : 'rolled_back',
				$rollback_failed ? 'fail' : 'not_applicable',
				'none',
				false,
				false,
				false,
				array( $check ),
				$rollback_failed ? array( 'repair_rollback', 'reactivate_source', 'purge_caches' ) : array( 'reactivate_source', 'purge_caches', 'run_fresh_preview' )
			);
		}

		$counts     = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
		$evidence   = is_array( $report['evidence'] ?? null ) ? $report['evidence'] : array();
		$accounting = is_array( $evidence['accounting'] ?? null ) ? $evidence['accounting'] : array();
		$semantic   = is_array( $evidence['semantic_comparison'] ?? null ) ? $evidence['semantic_comparison'] : array();
		$audit      = is_array( $evidence['redirect_audit'] ?? null ) ? $evidence['redirect_audit'] : array();
		$warnings   = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array();
		$profile    = is_array( $report['source_profile'] ?? null ) ? $report['source_profile'] : array();
		$baseline   = is_array( $report['html_baseline'] ?? null ) ? $report['html_baseline'] : array();
		$live       = is_array( $report['live_verification'] ?? null ) ? $report['live_verification'] : array();

		$failed      = (int) ( $counts['fields_failed'] ?? 0 ) + (int) ( $counts['redirects_failed'] ?? 0 );
		$invalid     = (int) ( $counts['objects_invalid'] ?? 0 ) + (int) ( $counts['fields_invalid'] ?? 0 ) + (int) ( $counts['redirects_invalid'] ?? 0 );
		$conflicts   = (int) ( $counts['fields_conflicts'] ?? 0 ) + (int) ( $counts['redirects_conflicts'] ?? 0 );
		$unsupported = (int) ( $counts['fields_unsupported'] ?? 0 ) + $this->terminal_total( $accounting, 'redirects', 'unsupported' );
		$preserved   = $this->terminal_total( $accounting, 'metadata', 'preserved' ) + $this->terminal_total( $accounting, 'redirects', 'preserved' );
		$mismatches  = 0;
		foreach ( $semantic as $comparison ) {
			$mismatches += is_array( $comparison ) ? (int) ( $comparison['mismatch'] ?? 0 ) : 0;
		}
		$unresolved = count( is_array( $evidence['modifiers']['unresolved_placeholders'] ?? null ) ? $evidence['modifiers']['unresolved_placeholders'] : array() );
		$loops      = count( is_array( $audit['loops'] ?? null ) ? $audit['loops'] : array() );
		$chains     = count( is_array( $audit['chains'] ?? null ) ? $audit['chains'] : array() );
		$collisions = count( is_array( $audit['collisions'] ?? null ) ? $audit['collisions'] : array() );
		$regex      = count( is_array( $audit['dangerous_regex'] ?? null ) ? $audit['dangerous_regex'] : array() );
		$writes     = (int) ( $counts['fields_written'] ?? 0 ) + (int) ( $counts['redirects_created'] ?? 0 ) + (int) ( $counts['redirects_updated'] ?? 0 );
		$redirects  = (int) ( $counts['redirects_created'] ?? 0 ) + (int) ( $counts['redirects_updated'] ?? 0 );

		$checks   = array();
		$checks[] = $this->check( 'terminal_status', 'complete' === (string) ( $report['status'] ?? '' ) ? 'pass' : 'fail', 'complete' === (string) ( $report['status'] ?? '' ) ? 0 : 1, true );
		$checks[] = $this->check( 'source_integrity', ! empty( $report['source_fingerprint_verified'] ) ? 'pass' : 'fail', ! empty( $report['source_fingerprint_verified'] ) ? 0 : 1, true );
		$checks[] = $this->check( 'exact_accounting', 'pass' === (string) ( $evidence['invariant']['status'] ?? '' ) ? 'pass' : 'fail', 'pass' === (string) ( $evidence['invariant']['status'] ?? '' ) ? 0 : 1, true );
		$checks[] = $this->check( 'write_failures', 0 === $failed ? 'pass' : 'fail', $failed, true );
		$checks[] = $this->check( 'invalid_records', 0 === $invalid ? 'pass' : 'fail', $invalid, true );
		$checks[] = $this->check( 'conflicts', 0 === $conflicts ? 'pass' : 'fail', $conflicts, true );
		$checks[] = $this->check( 'unsupported_records', 0 === $unsupported ? 'pass' : 'fail', $unsupported, true );
		$checks[] = $this->check( 'preserved_values', 0 === $preserved ? 'pass' : 'fail', $preserved, true );
		$checks[] = $this->check( 'diagnostics', empty( $warnings ) ? 'pass' : 'fail', count( $warnings ), true );
		$checks[] = $this->check( 'semantic_match', 0 === $mismatches ? 'pass' : 'fail', $mismatches, true );
		$checks[] = $this->check( 'unresolved_placeholders', 0 === $unresolved ? 'pass' : 'fail', $unresolved, true );

		if ( $redirects > 0 ) {
			$storage = is_array( $audit['storage_summary'] ?? null ) ? $audit['storage_summary'] : array();
			if ( ! $storage ) {
				$probes            = is_array( $audit['storage_probes'] ?? null ) ? $audit['storage_probes'] : array();
				$storage['tested'] = count( $probes );
				$storage['failed'] = count( array_filter( $probes, static fn( array $probe ): bool => 'pass' !== (string) ( $probe['storage_status'] ?? '' ) ) );
			}
			$storage_failed = (int) ( $storage['failed'] ?? 0 ) + max( 0, $redirects - (int) ( $storage['tested'] ?? 0 ) );
			$checks[]       = $this->check( 'redirect_storage', 0 === $storage_failed ? 'pass' : 'fail', $storage_failed, true );
		} else {
			$checks[] = $this->check( 'redirect_storage', 'not_applicable', 0, false );
		}
		$checks[] = $this->check( 'redirect_loops', 0 === $loops ? 'pass' : 'fail', $loops, true );
		$checks[] = $this->check( 'redirect_chains', 0 === $chains ? 'pass' : 'fail', $chains, true );
		$checks[] = $this->check( 'redirect_collisions', 0 === $collisions ? 'pass' : 'fail', $collisions, true );
		$checks[] = $this->check( 'redirect_regex', 0 === $regex ? 'pass' : 'fail', $regex, true );

		if ( $writes > 0 ) {
			$rollback_valid = empty( $rollback['expired'] ) && (int) ( $rollback['available'] ?? 0 ) >= $writes;
			$checks[]       = $this->check( 'rollback_window', $rollback_valid ? 'pass' : 'fail', $rollback_valid ? 0 : max( 1, $writes - (int) ( $rollback['available'] ?? 0 ) ), true );
		} else {
			$checks[] = $this->check( 'rollback_window', 'not_applicable', 0, false );
		}

		$source_mode = sanitize_key( (string) ( $profile['mode'] ?? 'database' ) );
		$scope       = 'full_cutover';
		if ( 'official_export' === $source_mode && 'not_source_owned' === (string) ( $baseline['state'] ?? '' ) ) {
			$scope    = 'contract_only';
			$checks[] = $this->check( 'frontend_baseline', 'not_applicable', 0, false );
			$checks[] = $this->check( 'live_verification', 'not_applicable', 0, false );
		} else {
			$baseline_pass = 'captured' === (string) ( $baseline['state'] ?? '' );
			$checks[]      = $this->check( 'frontend_baseline', $baseline_pass ? 'pass' : 'fail', $baseline_pass ? 0 : 1, true );
			$live_state    = sanitize_key( (string) ( $live['state'] ?? 'pending' ) );
			if ( 'verified' === $live_state && 0 === (int) ( $live['mismatch'] ?? 0 ) && 0 === (int) ( $live['request_failed'] ?? 0 ) ) {
				$checks[] = $this->check( 'live_verification', 'pass', 0, true );
			} elseif ( in_array( $live_state, array( 'differences_found', 'inconclusive', 'no_baseline' ), true ) ) {
				$checks[] = $this->check( 'live_verification', 'fail', max( 1, (int) ( $live['mismatch'] ?? 0 ) + (int) ( $live['request_failed'] ?? 0 ) ), true );
			} else {
				$checks[] = $this->check( 'live_verification', 'pending', 0, false );
			}
		}

		$preflight_blockers = $this->blockers( $checks, array( 'live_verification' ) );
		$all_blockers       = $this->blockers( $checks );
		$live_check         = $this->find_check( $checks, 'live_verification' );
		if ( $preflight_blockers ) {
			return $this->decision( 'blocked', 'fail', $scope, false, false, false, $checks, array( 'keep_source_active', 'resolve_gate_blockers', 'run_fresh_preview' ) );
		}
		if ( 'contract_only' === $scope ) {
			return $this->decision( 'go_live', 'pass', $scope, false, true, false, $checks, array( 'retain_source_backup', 'monitor_frontend' ) );
		}
		if ( 'pass' === (string) ( $live_check['status'] ?? '' ) && ! $all_blockers ) {
			return $this->decision( 'go_live', 'pass', $scope, false, true, false, $checks, array( 'retain_source_backup', 'monitor_frontend' ) );
		}
		if ( 'fail' === (string) ( $live_check['status'] ?? '' ) ) {
			return $this->decision( 'rollback_required', 'fail', $scope, false, false, true, $checks, array( 'retry_live_verification', 'conditional_rollback', 'reactivate_source', 'purge_caches' ) );
		}

		return $this->decision( 'ready_for_cutover', 'pending', $scope, true, false, false, $checks, array( 'controlled_deactivation', 'purge_caches', 'verify_live' ) );
	}

	/**
	 * Returns one stable check row.
	 *
	 * @param string $code Check code.
	 * @param string $status pass|fail|pending|not_applicable.
	 * @param int    $count Number of affected records.
	 * @param bool   $blocking Whether failure blocks the decision.
	 * @return array<string,mixed>
	 */
	private function check( string $code, string $status, int $count, bool $blocking ): array {
		return array(
			'code'     => sanitize_key( $code ),
			'status'   => sanitize_key( $status ),
			'count'    => max( 0, $count ),
			'blocking' => $blocking,
		);
	}

	/**
	 * Returns terminal accounting for one area/outcome.
	 *
	 * @param array<string,mixed> $accounting Evidence accounting ledger.
	 * @param string              $area Accounting area.
	 * @param string              $outcome Terminal outcome.
	 * @return int
	 */
	private function terminal_total( array $accounting, string $area, string $outcome ): int {
		return (int) ( $accounting[ $area ]['terminal'][ $outcome ] ?? 0 );
	}

	/**
	 * Returns failing blocking check codes, excluding optional codes.
	 *
	 * @param array<int,array<string,mixed>> $checks Gate checks.
	 * @param array<int,string>              $exclude Check codes to exclude.
	 * @return array<int,string>
	 */
	private function blockers( array $checks, array $exclude = array() ): array {
		$blockers = array();
		foreach ( $checks as $check ) {
			$code = sanitize_key( (string) ( $check['code'] ?? '' ) );
			if ( ! in_array( $code, $exclude, true ) && ! empty( $check['blocking'] ) && 'fail' === (string) ( $check['status'] ?? '' ) ) {
				$blockers[] = $code;
			}
		}

		return array_values( array_unique( $blockers ) );
	}

	/**
	 * Finds one check by code.
	 *
	 * @param array<int,array<string,mixed>> $checks Gate checks.
	 * @param string                         $code Check code.
	 * @return array<string,mixed>
	 */
	private function find_check( array $checks, string $code ): array {
		foreach ( $checks as $check ) {
			if ( (string) ( $check['code'] ?? '' ) === $code ) {
				return $check;
			}
		}

		return array();
	}

	/**
	 * Builds and hashes the final decision.
	 *
	 * @param string                         $state Gate state.
	 * @param string                         $verdict pass|fail|pending|not_applicable.
	 * @param string                         $scope Proof scope.
	 * @param bool                           $ready Whether controlled cutover is authorized.
	 * @param bool                           $go_live Whether go-live passed.
	 * @param bool                           $rollback_required Whether rollback is required.
	 * @param array<int,array<string,mixed>> $checks Gate checks.
	 * @param array<int,string>              $next_actions Remediation or monitoring actions.
	 * @return array<string,mixed>
	 */
	private function decision( string $state, string $verdict, string $scope, bool $ready, bool $go_live, bool $rollback_required, array $checks, array $next_actions ): array {
		$blockers                   = $this->blockers( $checks );
		$payload                    = array(
			'contract_version'  => self::CONTRACT_VERSION,
			'state'             => sanitize_key( $state ),
			'verdict'           => sanitize_key( $verdict ),
			'proof_scope'       => sanitize_key( $scope ),
			'ready_for_cutover' => $ready,
			'go_live'           => $go_live,
			'rollback_required' => $rollback_required,
			'can_verify_live'   => in_array( $state, array( 'ready_for_cutover', 'rollback_required' ), true ),
			'checks'            => $checks,
			'blockers'          => $blockers,
			'next_actions'      => array_values( array_map( 'sanitize_key', $next_actions ) ),
		);
		$encoded                    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload['decision_sha256'] = false === $encoded ? '' : hash( 'sha256', $encoded );
		$payload['evaluated_at']    = gmdate( 'c' );

		return $payload;
	}
}
