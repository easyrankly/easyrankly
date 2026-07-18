<?php
// phpcs:ignoreFile -- Standalone fail-closed decision-contract certification harness.
/**
 * Phase 8 authoritative go-live gate certification.
 *
 * Run: php tests/phase8-go-live-gate.php
 *
 * @package EasyRankly
 */

require __DIR__ . '/phase7-contract-certification.php';

/** Returns a complete clean database-import report awaiting live proof. */
function erankly_phase8_clean_report(): array {
	return array(
		'id'                          => 'phase8-clean-report',
		'mode'                        => 'import',
		'source'                      => 'yoast',
		'status'                      => 'complete',
		'source_fingerprint_verified' => true,
		'source_profile'              => array( 'mode' => 'database' ),
		'counts'                      => array(
			'objects_invalid'      => 0,
			'fields_written'       => 2,
			'fields_failed'        => 0,
			'fields_invalid'       => 0,
			'fields_conflicts'     => 0,
			'fields_unsupported'   => 0,
			'redirects_created'    => 1,
			'redirects_updated'    => 0,
			'redirects_failed'     => 0,
			'redirects_invalid'    => 0,
			'redirects_conflicts'  => 0,
		),
		'warnings'                    => array(),
		'evidence'                    => array(
			'invariant'           => array( 'status' => 'pass' ),
			'accounting'          => array(
				'metadata'  => array( 'terminal' => array( 'preserved' => 0 ) ),
				'redirects' => array( 'terminal' => array( 'preserved' => 0, 'unsupported' => 0 ) ),
			),
			'modifiers'           => array( 'unresolved_placeholders' => array() ),
			'semantic_comparison' => array(
				'title'  => array( 'mismatch' => 0 ),
				'schema' => array( 'mismatch' => 0 ),
			),
			'redirect_audit'      => array(
				'storage_summary' => array( 'imported' => 1, 'tested' => 1, 'passed' => 1, 'failed' => 0 ),
				'loops'           => array(),
				'chains'          => array(),
				'collisions'      => array(),
				'dangerous_regex' => array(),
			),
			'rollback'            => array( 'available' => 3, 'expired' => false ),
		),
		'html_baseline'                => array( 'state' => 'captured' ),
		'live_verification'            => array( 'state' => 'pending' ),
	);
}

/** Returns one gate check by stable code. */
function erankly_phase8_check( array $decision, string $code ): array {
	foreach ( $decision['checks'] ?? array() as $check ) {
		if ( $code === ( $check['code'] ?? '' ) ) {
			return $check;
		}
	}

	return array();
}

$gate     = new ERankly_Migration_Go_Live_Gate();
$clean    = erankly_phase8_clean_report();
$rollback = $clean['evidence']['rollback'];
$ready    = $gate->evaluate( $clean, $rollback );

erankly_phase2_assert( 1 === ( $ready['contract_version'] ?? 0 ), 'The go-live decision contract must be explicitly versioned.' );
erankly_phase2_assert( 'ready_for_cutover' === ( $ready['state'] ?? '' ) && 'pending' === ( $ready['verdict'] ?? '' ), 'A fully clean database import must stop at ready-for-cutover until live proof exists.' );
erankly_phase2_assert( ! empty( $ready['ready_for_cutover'] ) && empty( $ready['go_live'] ) && empty( $ready['rollback_required'] ) && ! empty( $ready['can_verify_live'] ), 'Ready-for-cutover booleans must authorize only the live-proof workflow.' );
erankly_phase2_assert( array() === ( $ready['blockers'] ?? null ), 'A clean preflight must contain no blockers.' );
erankly_phase2_assert( 64 === strlen( (string) ( $ready['decision_sha256'] ?? '' ) ), 'Every decision must carry a SHA-256 fingerprint.' );
erankly_phase2_assert( $ready['decision_sha256'] === $gate->evaluate( $clean, $rollback )['decision_sha256'], 'The decision fingerprint must ignore evaluation time and remain deterministic for identical evidence.' );

$required_codes = array(
	'terminal_status',
	'source_integrity',
	'exact_accounting',
	'write_failures',
	'invalid_records',
	'conflicts',
	'unsupported_records',
	'preserved_values',
	'diagnostics',
	'semantic_match',
	'unresolved_placeholders',
	'redirect_storage',
	'redirect_loops',
	'redirect_chains',
	'redirect_collisions',
	'redirect_regex',
	'rollback_window',
	'frontend_baseline',
	'live_verification',
);
erankly_phase2_assert( $required_codes === array_column( $ready['checks'], 'code' ), 'The gate must expose every mandatory proof in a stable order.' );

$live_pass = $clean;
$live_pass['live_verification'] = array( 'state' => 'verified', 'mismatch' => 0, 'request_failed' => 0 );
$go_live = $gate->evaluate( $live_pass, $rollback );
erankly_phase2_assert( 'go_live' === $go_live['state'] && 'pass' === $go_live['verdict'] && ! empty( $go_live['go_live'] ) && empty( $go_live['can_verify_live'] ), 'Only passing post-cutover evidence may produce a full go-live PASS.' );

$live_fail = $clean;
$live_fail['live_verification'] = array( 'state' => 'differences_found', 'mismatch' => 1, 'request_failed' => 0 );
$rollback_required = $gate->evaluate( $live_fail, $rollback );
erankly_phase2_assert( 'rollback_required' === $rollback_required['state'] && ! empty( $rollback_required['rollback_required'] ) && ! empty( $rollback_required['can_verify_live'] ), 'A live mismatch must require rollback while still permitting one evidence retry.' );
erankly_phase2_assert( 'fail' === ( erankly_phase8_check( $rollback_required, 'live_verification' )['status'] ?? '' ), 'The live-verification check must identify the rollback trigger.' );

$mutations = array(
	'conflicts'               => static function ( array &$report, array &$current_rollback ): void { $report['counts']['fields_conflicts'] = 1; },
	'unsupported_records'     => static function ( array &$report, array &$current_rollback ): void { $report['counts']['fields_unsupported'] = 1; },
	'preserved_values'        => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['accounting']['metadata']['terminal']['preserved'] = 1; },
	'diagnostics'             => static function ( array &$report, array &$current_rollback ): void { $report['warnings'][] = array( 'code' => 'review' ); },
	'semantic_match'          => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['semantic_comparison']['schema']['mismatch'] = 1; },
	'unresolved_placeholders' => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['modifiers']['unresolved_placeholders'][] = array( 'token' => 'unknown' ); },
	'redirect_storage'        => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['redirect_audit']['storage_summary']['failed'] = 1; },
	'redirect_loops'          => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['redirect_audit']['loops'][] = '/loop'; },
	'redirect_chains'         => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['redirect_audit']['chains'][] = array( '/a', '/b', '/c' ); },
	'redirect_collisions'     => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['redirect_audit']['collisions'][] = '/same'; },
	'redirect_regex'          => static function ( array &$report, array &$current_rollback ): void { $report['evidence']['redirect_audit']['dangerous_regex'][] = '/(a+)+/'; },
	'rollback_window'         => static function ( array &$report, array &$current_rollback ): void { $current_rollback['expired'] = true; },
	'frontend_baseline'       => static function ( array &$report, array &$current_rollback ): void { $report['html_baseline']['state'] = 'capture_failed'; },
);
foreach ( $mutations as $expected_blocker => $mutate ) {
	$report_under_test   = $clean;
	$rollback_under_test = $rollback;
	$mutate( $report_under_test, $rollback_under_test );
	$blocked = $gate->evaluate( $report_under_test, $rollback_under_test );
	erankly_phase2_assert( 'blocked' === ( $blocked['state'] ?? '' ) && in_array( $expected_blocker, $blocked['blockers'] ?? array(), true ), 'Mandatory proof must fail closed: ' . $expected_blocker . '.' );
	erankly_phase2_assert( empty( $blocked['ready_for_cutover'] ) && empty( $blocked['go_live'] ) && empty( $blocked['can_verify_live'] ), 'A preflight blocker must expose no cutover or live-verification authority: ' . $expected_blocker . '.' );
}

$official = $clean;
$official['source_profile']['mode'] = 'official_export';
$official['html_baseline']['state'] = 'not_source_owned';
$contract_pass = $gate->evaluate( $official, $rollback );
erankly_phase2_assert( 'go_live' === $contract_pass['state'] && 'contract_only' === $contract_pass['proof_scope'] && ! empty( $contract_pass['go_live'] ), 'A clean official export must distinguish contract-only PASS from a full old-plugin HTML comparison.' );
erankly_phase2_assert( 'not_applicable' === ( erankly_phase8_check( $contract_pass, 'frontend_baseline' )['status'] ?? '' ) && 'not_applicable' === ( erankly_phase8_check( $contract_pass, 'live_verification' )['status'] ?? '' ), 'Contract-only proof must never pretend that frontend baseline or live comparison ran.' );

$preview = $clean;
$preview['mode'] = 'preview';
$preview_only = $gate->evaluate( $preview, array() );
erankly_phase2_assert( 'preview_only' === $preview_only['state'] && 'not_applicable' === $preview_only['verdict'] && empty( $preview_only['go_live'] ), 'Preview reports must never authorize go-live.' );

$rolled_back = $clean;
$rolled_back['rollback_result'] = array( 'status' => 'complete', 'failed' => 0 );
$rolled_back_decision = $gate->evaluate( $rolled_back, array() );
erankly_phase2_assert( 'rolled_back' === $rolled_back_decision['state'] && empty( $rolled_back_decision['go_live'] ), 'A completed rollback must terminate go-live authority.' );

$rollback_failed = $clean;
$rollback_failed['rollback_result'] = array( 'status' => 'failed', 'failed' => 1 );
$rollback_failed_decision = $gate->evaluate( $rollback_failed, array() );
erankly_phase2_assert( 'rollback_failed' === $rollback_failed_decision['state'] && 'fail' === $rollback_failed_decision['verdict'], 'A failed rollback must demand manual recovery.' );

$presenter = new ERankly_Migration_Admin_Presenter();
$source_active_ui = $presenter->present( $clean, $ready, $rollback, true );
erankly_phase2_assert( 'source_active' === ( $source_active_ui['state'] ?? '' ) && 2 === ( $source_active_ui['step'] ?? 0 ) && 'open_plugins' === ( $source_active_ui['primary_action'] ?? '' ), 'An active source must produce only the guided deactivation step.' );
erankly_phase2_assert( empty( $source_active_ui['can_verify_live'] ), 'The presenter must never expose live verification while another SEO plugin owns frontend output.' );

$source_inactive_ui = $presenter->present( $clean, $ready, $rollback, false );
erankly_phase2_assert( 'ready_to_verify' === ( $source_inactive_ui['state'] ?? '' ) && 3 === ( $source_inactive_ui['step'] ?? 0 ) && 'verify_live' === ( $source_inactive_ui['primary_action'] ?? '' ), 'An inactive source must advance to the single final-verification action.' );
erankly_phase2_assert( ! empty( $source_inactive_ui['can_verify_live'] ), 'The presenter may expose verification only when both gate and runtime ownership checks allow it.' );

$complete_ui = $presenter->present( $live_pass, $go_live, $rollback, false );
erankly_phase2_assert( 'complete' === ( $complete_ui['state'] ?? '' ) && 'open_settings' === ( $complete_ui['primary_action'] ?? '' ), 'Full live proof must render a completed migration state.' );

$contract_ui = $presenter->present( $official, $contract_pass, $rollback, false );
erankly_phase2_assert( 'contract_verified' === ( $contract_ui['state'] ?? '' ), 'Official-export evidence must remain visibly distinct from full frontend verification.' );

$mismatch_ui = $presenter->present( $live_fail, $rollback_required, $rollback, false );
erankly_phase2_assert( 'verification_failed' === ( $mismatch_ui['state'] ?? '' ) && 'review_differences' === ( $mismatch_ui['primary_action'] ?? '' ), 'Live differences must lead with review rather than destructive rollback.' );

$preview_ready = $preview;
$preview_ready['verification'] = array( 'ready_to_import' => true );
$preview_ready_ui = $presenter->present( $preview_ready, $preview_only, array(), true );
erankly_phase2_assert( 'preview_ready' === ( $preview_ready_ui['state'] ?? '' ) && 'run_import' === ( $preview_ready_ui['primary_action'] ?? '' ), 'A clean preview must expose the reviewed import as its only next action.' );

$preview_blocked_ui = $presenter->present( $preview, $preview_only, array(), true );
erankly_phase2_assert( 'preview_blocked' === ( $preview_blocked_ui['state'] ?? '' ), 'A preview without import authority must remain in review.' );

$rolled_back_ui = $presenter->present( $rolled_back, $rolled_back_decision, array(), false );
erankly_phase2_assert( 'rolled_back' === ( $rolled_back_ui['state'] ?? '' ) && 'open_plugins' === ( $rolled_back_ui['primary_action'] ?? '' ), 'A completed rollback must guide source-plugin reactivation.' );

$rollback_failed_ui = $presenter->present( $rollback_failed, $rollback_failed_decision, array(), false );
erankly_phase2_assert( 'rollback_failed' === ( $rollback_failed_ui['state'] ?? '' ) && 'review_recovery' === ( $rollback_failed_ui['primary_action'] ?? '' ), 'A failed rollback must guide manual recovery without claiming completion.' );

$blocked_ui = $presenter->present( $report_under_test, $blocked, $rollback_under_test, true );
erankly_phase2_assert( 'blocked' === ( $blocked_ui['state'] ?? '' ) && 'review_issues' === ( $blocked_ui['primary_action'] ?? '' ), 'A blocked gate must remain a blocked user interaction state.' );
erankly_phase2_assert( 19 === array_sum( $source_active_ui['check_totals'] ?? array() ), 'The compact technical summary must account for every authoritative gate check.' );

$manager_report = $clean;
$manager_report['id'] = 'phase8-manager-report';
$manager_report['completed_at'] = '';
$manager = new ERankly_Migration_Manager();
$manager_report = $manager->finish_report( $manager_report );
erankly_phase2_assert( 'ready_for_cutover' === ( $manager_report['go_live_gate']['state'] ?? '' ) && ! empty( $manager_report['verification']['ready_to_switch'] ), 'Persisted reports must embed the authoritative decision and synchronize the legacy summary.' );

$admin_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/import-export.php' );
erankly_phase2_assert( false !== strpos( $admin_source, "empty( \$gate['can_verify_live'] )" ) && false !== strpos( $admin_source, "'migration-gate-blocked'" ), 'The privileged live action must enforce the gate server-side, not only hide its button.' );
erankly_phase2_assert( false !== strpos( $admin_source, 'Decision SHA-256' ) && false !== strpos( $admin_source, 'Proof boundary:' ), 'The admin report must disclose the decision fingerprint and contract-only proof boundary.' );
erankly_phase2_assert( false !== strpos( $admin_source, 'erankly-migration-technical' ) && false !== strpos( $admin_source, 'erankly-migration-recovery' ), 'Technical evidence and rollback must remain available through progressive disclosure.' );
erankly_phase2_assert( false !== strpos( $admin_source, '$reported_notices' ) && false !== strpos( $admin_source, 'stale migration-started' ), 'Terminal reports must replace stale query-string migration notices as the visible source of truth.' );

require_once __DIR__ . '/certification/helpers.php';
require_once __DIR__ . '/certification/class-erankly-release-go-live-gate.php';
$release_packages = array();
foreach ( array( 'yoast' => 'premium', 'rankmath' => 'pro', 'aioseo' => 'pro', 'seopress' => 'pro' ) as $source => $edition ) {
	$release_packages[] = array(
		'source'  => $source,
		'edition' => $edition,
		'version' => 'certified-test-version',
		'sha256'  => str_repeat( substr( hash( 'sha256', $source ), 0, 1 ), 64 ),
		'status'  => 'pass',
	);
}
$release_record = array(
	'generated_at_utc'     => gmdate( 'c', 1700000000 ),
	'certification_status' => 'pass',
	'plugin_version'       => (string) $manifest['plugin_version'],
	'workspace_sha256'     => str_repeat( 'a', 64 ),
	'git'                  => array( 'commit' => 'certified-commit', 'worktree_dirty' => false ),
	'matrix'               => array_map( static fn( array $cell ): array => array_merge( $cell, array( 'status' => 'pass' ) ), $manifest['certification_cells'] ),
	'licensed_pro_evidence'=> array( 'status' => 'pass', 'evidence_record_sha256' => str_repeat( 'd', 64 ), 'packages' => $release_packages ),
);
$release_current = array(
	'plugin_version'   => (string) $manifest['plugin_version'],
	'workspace_sha256' => str_repeat( 'a', 64 ),
	'git_commit'       => 'certified-commit',
	'worktree_dirty'   => false,
	'now'              => 1700000300,
);
$release_gate = new ERankly_Release_Go_Live_Gate();
$release_pass = $release_gate->evaluate( $release_record, $manifest, $release_current, str_repeat( 'b', 64 ) );
erankly_phase2_assert( 'go_live' === $release_pass['state'] && ! empty( $release_pass['go_live'] ) && array() === $release_pass['blockers'], 'Exact fresh clean certification plus all four authorized PRO packages must pass the release gate.' );

$release_mutations = array(
	'certification_status'    => static function ( array &$record, array &$current ): void { $record['certification_status'] = 'fail'; },
	'plugin_version'          => static function ( array &$record, array &$current ): void { $record['plugin_version'] = '0.0.0'; },
	'workspace_integrity'     => static function ( array &$record, array &$current ): void { $current['workspace_sha256'] = str_repeat( 'c', 64 ); },
	'matrix_complete'         => static function ( array &$record, array &$current ): void { array_pop( $record['matrix'] ); },
	'certification_freshness' => static function ( array &$record, array &$current ): void { $current['now'] += 90000; },
	'commit_integrity'        => static function ( array &$record, array &$current ): void { $current['git_commit'] = 'different-commit'; },
	'clean_worktree'          => static function ( array &$record, array &$current ): void { $current['worktree_dirty'] = true; },
	'licensed_pro_evidence'   => static function ( array &$record, array &$current ): void { $record['licensed_pro_evidence'] = array( 'status' => 'not_supplied' ); },
);
foreach ( $release_mutations as $expected_blocker => $mutate ) {
	$record_under_test  = $release_record;
	$current_under_test = $release_current;
	$mutate( $record_under_test, $current_under_test );
	$release_blocked = $release_gate->evaluate( $record_under_test, $manifest, $current_under_test, str_repeat( 'b', 64 ) );
	erankly_phase2_assert( 'blocked' === $release_blocked['state'] && in_array( $expected_blocker, $release_blocked['blockers'], true ), 'Release proof must fail closed: ' . $expected_blocker . '.' );
}

fwrite( STDOUT, "Phase 8 authoritative go-live gate certification passed.\n" );
