<?php
// phpcs:ignoreFile -- CLI certification helpers intentionally use local process and filesystem APIs.
/**
 * Shared helpers for migration certification and release gates.
 *
 * @package EasyRankly
 */

/** Returns a stable key for one matrix cell. */
function erankly_certification_cell_key( array $cell ): string {
	return implode(
		'|',
		array(
			(string) ( $cell['layer'] ?? '' ),
			(string) ( $cell['php'] ?? '' ),
			(string) ( $cell['wordpress'] ?? '' ),
			(string) ( $cell['database'] ?? '' ),
			(string) ( $cell['topology'] ?? '' ),
		)
	);
}

/** Executes a fixed Git command without invoking a shell. */
function erankly_certification_git( array $arguments, string $cwd ): string {
	$command = array_merge( array( 'git' ), $arguments );
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		return '';
	}
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );

	return 0 === $status ? trim( (string) $stdout ) : trim( (string) $stderr );
}

/** Hashes every relevant workspace file in a deterministic order. */
function erankly_certification_workspace_hash( string $root ): string {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $file ): bool {
				if ( $file->isDir() ) {
					return ! in_array( $file->getFilename(), array( '.git', 'vendor', 'node_modules', 'artifacts' ), true );
				}
				return true;
			}
		)
	);
	$files = array();
	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && $file->isFile() ) {
			$path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			$files[ $path ] = hash_file( 'sha256', $file->getPathname() );
		}
	}
	ksort( $files, SORT_STRING );
	$context = hash_init( 'sha256' );
	foreach ( $files as $path => $hash ) {
		hash_update( $context, $path . "\0" . $hash . "\n" );
	}

	return hash_final( $context );
}
