<?php
// phpcs:ignoreFile -- Pure CLI release-decision contract; WordPress is not loaded.
/**
 * Strict release-level gate for the migration subsystem.
 *
 * @package EasyRankly
 */

/** Converts a Phase 7 certification record into a release GO/NO-GO verdict. */
final class ERankly_Release_Go_Live_Gate {
	private const CONTRACT_VERSION = 1;
	private const MAX_AGE_SECONDS  = 86400;

	/** Evaluates certification provenance against the exact current workspace. */
	public function evaluate( array $record, array $manifest, array $current, string $certification_sha256 ): array {
		$checks = array();
		$checks[] = $this->check( 'certification_status', 'pass' === ( $record['certification_status'] ?? '' ), 0 );
		$checks[] = $this->check( 'plugin_version', '' !== (string) ( $current['plugin_version'] ?? '' ) && (string) ( $current['plugin_version'] ?? '' ) === (string) ( $record['plugin_version'] ?? '' ) && (string) ( $record['plugin_version'] ?? '' ) === (string) ( $manifest['plugin_version'] ?? '' ), 0 );
		$checks[] = $this->check( 'workspace_integrity', '' !== (string) ( $current['workspace_sha256'] ?? '' ) && hash_equals( (string) ( $record['workspace_sha256'] ?? '' ), (string) ( $current['workspace_sha256'] ?? '' ) ), 0 );

		$expected_cells = array();
		foreach ( $manifest['certification_cells'] ?? array() as $cell ) {
			$expected_cells[ erankly_certification_cell_key( $cell ) ] = true;
		}
		$actual_cells = array();
		foreach ( $record['matrix'] ?? array() as $cell ) {
			if ( is_array( $cell ) && 'pass' === ( $cell['status'] ?? '' ) ) {
				$actual_cells[ erankly_certification_cell_key( $cell ) ] = true;
			}
		}
		$matrix_missing = count( array_diff_key( $expected_cells, $actual_cells ) ) + count( array_diff_key( $actual_cells, $expected_cells ) );
		$checks[]       = $this->check( 'matrix_complete', 0 === $matrix_missing && count( $expected_cells ) > 0, $matrix_missing );

		$generated = strtotime( (string) ( $record['generated_at_utc'] ?? '' ) );
		$now       = (int) ( $current['now'] ?? time() );
		$age       = false === $generated ? self::MAX_AGE_SECONDS + 1 : $now - $generated;
		$fresh     = false !== $generated && $age >= -300 && $age <= self::MAX_AGE_SECONDS;
		$checks[]  = $this->check( 'certification_freshness', $fresh, $fresh ? 0 : max( 1, abs( $age ) ) );

		$record_commit  = (string) ( $record['git']['commit'] ?? '' );
		$current_commit = (string) ( $current['git_commit'] ?? '' );
		$checks[]       = $this->check( 'commit_integrity', '' !== $record_commit && $record_commit === $current_commit, 0 );
		$checks[]       = $this->check( 'clean_worktree', empty( $record['git']['worktree_dirty'] ) && empty( $current['worktree_dirty'] ), 0 );

		$packages = array();
		foreach ( $record['licensed_pro_evidence']['packages'] ?? array() as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$source  = strtolower( (string) ( $package['source'] ?? '' ) );
			$edition = strtolower( (string) ( $package['edition'] ?? '' ) );
			$sha256  = strtolower( (string) ( $package['sha256'] ?? '' ) );
			$version = trim( (string) ( $package['version'] ?? '' ) );
			$status  = strtolower( (string) ( $package['status'] ?? '' ) );
			if ( '' !== $source && 'pass' === $status && '' !== $version && 1 === preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
				$packages[ $source ] = $edition;
			}
		}
		$required_packages = array( 'yoast' => 'premium', 'rankmath' => 'pro', 'aioseo' => 'pro', 'seopress' => 'pro' );
		$missing_packages  = count( array_diff_assoc( $required_packages, $packages ) ) + count( array_diff_assoc( $packages, $required_packages ) );
		$evidence_hash     = strtolower( (string) ( $record['licensed_pro_evidence']['evidence_record_sha256'] ?? '' ) );
		$pro_pass          = 'pass' === ( $record['licensed_pro_evidence']['status'] ?? '' ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $evidence_hash ) && 0 === $missing_packages;
		$checks[]          = $this->check( 'licensed_pro_evidence', $pro_pass, $pro_pass ? 0 : max( 1, $missing_packages ) );

		$blockers = array_values(
			array_map(
				static fn( array $check ): string => (string) $check['code'],
				array_filter( $checks, static fn( array $check ): bool => 'fail' === $check['status'] )
			)
		);
		$payload = array(
			'contract_version'        => self::CONTRACT_VERSION,
			'state'                   => $blockers ? 'blocked' : 'go_live',
			'verdict'                 => $blockers ? 'fail' : 'pass',
			'go_live'                 => ! $blockers,
			'plugin_version'           => (string) ( $record['plugin_version'] ?? '' ),
			'certification_sha256'     => strtolower( $certification_sha256 ),
			'certified_workspace_sha256' => (string) ( $record['workspace_sha256'] ?? '' ),
			'checks'                  => $checks,
			'blockers'                => $blockers,
			'next_actions'            => $blockers ? $this->next_actions( $blockers ) : array( 'publish_certified_bytes', 'retain_evidence', 'monitor_migrations' ),
		);
		$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload['decision_sha256'] = false === $encoded ? '' : hash( 'sha256', $encoded );
		$payload['evaluated_at_utc'] = gmdate( 'c', $now );

		return $payload;
	}

	/** Returns one stable release check. */
	private function check( string $code, bool $passed, int $count ): array {
		return array(
			'code'   => $code,
			'status' => $passed ? 'pass' : 'fail',
			'count'  => $passed ? 0 : max( 1, $count ),
		);
	}

	/** Maps blockers to explicit remediation codes. */
	private function next_actions( array $blockers ): array {
		$actions = array();
		foreach ( $blockers as $blocker ) {
			$actions[] = match ( $blocker ) {
				'licensed_pro_evidence'   => 'certify_authorized_pro_packages',
				'clean_worktree'           => 'commit_exact_certified_bytes',
				'certification_freshness'  => 'rerun_complete_certification',
				default                    => 'rerun_complete_certification',
			};
		}

		return array_values( array_unique( $actions ) );
	}
}
