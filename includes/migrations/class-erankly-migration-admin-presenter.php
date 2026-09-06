<?php
/** User-facing migration state presenter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Converts a persisted migration report into one deterministic admin interaction state. */
final class ERankly_Migration_Admin_Presenter {
	/**
 * Builds the compact state consumed by the migration report UI.
 *
 * @param bool                $source_owns_output Whether another SEO plugin still owns frontend output.
 * @param bool                $backup_available   Whether the pre-import backup can still be restored.
 * @return array<string,mixed>
 */
	public function present( array $report, bool $source_owns_output, bool $backup_available ): array {
		$mode         = sanitize_key( (string) ( $report['mode'] ?? 'import' ) );
		$verification = is_array( $report['verification'] ?? null ) ? $report['verification'] : array();
		$profile      = is_array( $report['source_profile'] ?? null ) ? $report['source_profile'] : array();
		$counts       = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
		$checks       = is_array( $verification['checks'] ?? null ) ? $verification['checks'] : array();
		$state_code   = sanitize_key( (string) ( $verification['state'] ?? 'blocked' ) );

		if ( 'preview' === $mode ) {
			$step = 1;
			if ( ! empty( $verification['ready_to_import'] ) ) {
				$state  = 'preview_ready';
				$action = 'run_import';
				$tone   = 'success';
			} else {
				$state  = 'preview_blocked';
				$action = 'review_issues';
				$tone   = 'warning';
			}
		} elseif ( 'blocked' === $state_code ) {
			$step   = 2;
			$state  = 'blocked';
			$action = 'review_issues';
			$tone   = 'error';
		} elseif ( 'review' === $state_code ) {
			$step   = 2;
			$state  = 'needs_review';
			$action = 'review_issues';
			$tone   = 'warning';
		} elseif ( $source_owns_output ) {
			$step   = 2;
			$state  = 'source_active';
			$action = 'open_plugins';
			$tone   = 'success';
		} else {
			$step   = 3;
			$state  = 'complete';
			$action = 'open_settings';
			$tone   = 'success';
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
			'metadata_count'        => $metadata,
			'settings_count'        => $settings_count,
			'redirect_count'        => $redirects,
			'problem_count'         => $this->problem_count( $counts, $checks, $report ),
			'check_totals'          => $this->check_totals( $checks ),
			'backup_available'      => $backup_available,
			'can_run_import'        => 'preview' === $mode && ! empty( $verification['ready_to_import'] ),
			'is_database_migration' => 'database' === (string) ( $profile['mode'] ?? 'database' ),
		);
	}

	/**
 * Counts verification checks by status.
 *
 * @return array<string,int>
 */
	private function check_totals( array $checks ): array {
		$totals = array(
			'pass'           => 0,
			'fail'           => 0,
			'warn'           => 0,
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
