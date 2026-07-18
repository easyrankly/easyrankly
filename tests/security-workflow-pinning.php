<?php
/**
 * Supply-chain regression: every external GitHub Action must use a full SHA.
 *
 * Run: php tests/security-workflow-pinning.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Standalone source contract, not WordPress runtime code.

function erankly_workflow_security_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$root      = dirname( __DIR__ );
$workflows = array_merge(
	glob( $root . '/.github/workflows/*.yml' ) ?: array(),
	glob( $root . '/.github/workflows/*.yaml' ) ?: array()
);
$actions   = 0;

erankly_workflow_security_assert( array() !== $workflows, 'At least one GitHub Actions workflow must exist.' );

foreach ( $workflows as $workflow ) {
	$contents = file_get_contents( $workflow );
	erankly_workflow_security_assert( false !== $contents, 'Workflow must be readable: ' . basename( $workflow ) );

	preg_match_all( '/^\s*uses:\s*([^\s#]+)(?:\s+#.*)?$/m', (string) $contents, $matches );
	foreach ( $matches[1] as $reference ) {
		if ( 0 === strpos( $reference, './' ) || 0 === strpos( $reference, 'docker://' ) ) {
			continue;
		}

		++$actions;
		$separator = strrpos( $reference, '@' );
		$revision  = false === $separator ? '' : substr( $reference, $separator + 1 );
		erankly_workflow_security_assert(
			1 === preg_match( '/^[a-f0-9]{40}$/', $revision ),
			'External action must be pinned to a full commit SHA: ' . $reference
		);
	}
}

erankly_workflow_security_assert( $actions >= 4, 'The certification workflow must retain all expected external actions.' );

fwrite( STDOUT, "GitHub Actions SHA-pinning security contract passed.\n" );
