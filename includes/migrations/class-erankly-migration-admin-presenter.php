<?php
/** User-facing migration state presenter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts the authoritative migration evidence into one deterministic admin interaction state. It never grants
 * authority that is absent from the persisted go-live gate.
 */
final class ERankly_Migration_Admin_Presenter {
	/**
 * Builds the compact state consumed by the migration report UI.
 *
 * @param bool                $source_owns_output Whether another SEO plugin owns frontend output.
 * @return array<string,mixed>
 */
	public function present( array $report, array $gate, array $rollback, bool $source_owns_output ): array {
		$mode         = sanitize_key( (string) ( $report['mode'] ?? 'import' ) );
		$gate_state   = sanitize_key( (string) ( $gate['state'] ?? 'blocked' ) );
		$proof_scope  = sanitize_key( (string) ( $gate['proof_scope'] ?? 'none' ) );
		$verification = is_array( $report['verification'] ?? null ) ? $report['verification'] : array();
		$profile      = is_array( $report['source_profile'] ?? null ) ? $report['source_profile'] : array();
		$counts       = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
		$checks       = is_array( $gate['checks'] ?? null ) ? $gate['checks'] : array();
		$check_totals = $this->check_totals( $checks );
		$problems     = $this->problem_count( $counts, $checks, $report );
		$state        = 'blocked';
		$step         = 1;
		$action       = 'review_issues';
		$tone         = 'error';

		if ( 'preview' === $mode ) {
			if ( ! empty( $verification['ready_to_import'] ) ) {
				$state  = 'preview_ready';
				$action = 'run_import';
				$tone   = 'success';
			} else {
				$state = 'preview_blocked';
				$tone  = 'warning';
			}
		} elseif ( 'ready_for_cutover' === $gate_state ) {
			$step = $source_owns_output ? 2 : 3;
			if ( $source_owns_output ) {
				$state  = 'source_active';
				$action = 'open_plugins';
				$tone   = 'success';
			} else {
				$state  = 'ready_to_verify';
				$action = 'verify_live';
				$tone   = 'info';
			}
		} elseif ( 'go_live' === $gate_state ) {
			$step   = 3;
			$state  = 'contract_only' === $proof_scope ? 'contract_verified' : 'complete';
			$action = 'open_settings';
			$tone   = 'success';
		} elseif ( 'rollback_required' === $gate_state ) {
			$step   = 3;
			$state  = 'verification_failed';
			$action = 'review_differences';
			$tone   = 'error';
		} elseif ( 'rolled_back' === $gate_state ) {
			$state  = 'rolled_back';
			$action = 'open_plugins';
			$tone   = 'info';
		} elseif ( 'rollback_failed' === $gate_state ) {
			$state  = 'rollback_failed';
			$action = 'review_recovery';
			$tone   = 'error';
		} elseif ( 'preview_only' === $gate_state ) {
			$state = 'preview_blocked';
			$tone  = 'warning';
		}

		$metadata  = 'preview' === $mode
			? absint( $counts['fields_ready'] ?? 0 )
			: absint( $counts['fields_written'] ?? 0 );
		$redirects = 'preview' === $mode
			? absint( $counts['redirects_ready_create'] ?? 0 ) + absint( $counts['redirects_ready_update'] ?? 0 )
			: absint( $counts['redirects_created'] ?? 0 ) + absint( $counts['redirects_updated'] ?? 0 );
		$settings_count = 'preview' === $mode
			? absint( $counts['settings_ready'] ?? 0 )
			: absint( $counts['settings_written'] ?? 0 );

		return array(
			'state'                 => $state,
			'tone'                  => $tone,
			'step'                  => $step,
			'steps_total'           => 3,
			'primary_action'        => $action,
			'source_label'          => sanitize_text_field( (string) ( $report['source_label'] ?? $report['source'] ?? '' ) ),
			'source_slug'           => sanitize_key( (string) ( $report['source'] ?? '' ) ),
			'source_mode'           => sanitize_key( (string) ( $profile['mode'] ?? 'database' ) ),
			'source_owns_output'    => $source_owns_output,
			'proof_scope'           => $proof_scope,
			'metadata_count'        => $metadata,
			'settings_count'        => $settings_count,
			'redirect_count'        => $redirects,
			'problem_count'         => $problems,
			'check_totals'          => $check_totals,
			'rollback_available'    => absint( $rollback['available'] ?? 0 ) > 0 && empty( $rollback['expired'] ),
			'can_verify_live'       => ! empty( $gate['can_verify_live'] ) && ! $source_owns_output,
			'can_run_import'        => 'preview' === $mode && ! empty( $verification['ready_to_import'] ),
			'is_database_migration' => 'database' === (string) ( $profile['mode'] ?? '' ),
		);
	}

	/**
 * Counts checks by their stable gate status.
 *
 * @return array<string,int>
 */
	private function check_totals( array $checks ): array {
		$totals = array(
			'pass'           => 0,
			'fail'           => 0,
			'pending'        => 0,
			'not_applicable' => 0,
		);

		foreach ( $checks as $check ) {
			$status = sanitize_key( (string) ( $check['status'] ?? '' ) );
			if ( isset( $totals[ $status ] ) ) {
				++$totals[ $status ];
			}
		}

		return $totals;
	}

	private function problem_count( array $counts, array $checks, array $report ): int {
		$problem_count = 0;
		foreach ( $checks as $check ) {
			if ( 'fail' === (string) ( $check['status'] ?? '' ) ) {
				$problem_count += max( 1, absint( $check['count'] ?? 0 ) );
			}
		}

		if ( 0 === $problem_count ) {
			$problem_count = absint( $counts['settings_failed'] ?? 0 )
				+ absint( $counts['settings_invalid'] ?? 0 )
				+ absint( $counts['settings_conflicts'] ?? 0 )
				+ absint( $counts['fields_failed'] ?? 0 )
				+ absint( $counts['fields_invalid'] ?? 0 )
				+ absint( $counts['fields_conflicts'] ?? 0 )
				+ absint( $counts['fields_unsupported'] ?? 0 )
				+ absint( $counts['redirects_failed'] ?? 0 )
				+ absint( $counts['redirects_invalid'] ?? 0 )
				+ absint( $counts['redirects_unsupported'] ?? 0 )
				+ absint( $counts['redirects_conflicts'] ?? 0 );
		}

		if ( 0 === $problem_count && ! empty( $report['warnings'] ) && is_array( $report['warnings'] ) ) {
			$problem_count = count(
				array_filter(
					$report['warnings'],
					static fn( mixed $warning ): bool => ! is_array( $warning ) || ! isset( $warning['blocking'] ) || (bool) $warning['blocking']
				)
			);
		}

		return $problem_count;
	}
}
