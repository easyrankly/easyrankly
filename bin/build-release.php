<?php
/**
 * Builds a deterministic EasyRankly WordPress.org archive.
 *
 * Usage: php bin/build-release.php [--output=dist/easyrankly-X.Y.Z.zip] [--force]
 */

declare(strict_types=1);

require_once __DIR__ . '/release-lib.php';

try {
	if ( ! class_exists( ZipArchive::class ) ) {
		throw new RuntimeException( 'The PHP zip extension is required to build a release.' );
	}
	$options = getopt( '', array( 'output:', 'force', 'allow-dirty', 'source-date-epoch:' ) );
	$root    = erankly_release_root();
	$version = erankly_release_version( $root );
	erankly_release_assert_pot_references( $root );
	if ( ! isset( $options['allow-dirty'] ) ) {
		erankly_release_assert_clean( $root );
	}

	$output = isset( $options['output'] ) ? (string) $options['output'] : 'dist/easyrankly-' . $version . '.zip';
	if ( ! str_starts_with( $output, '/' ) ) {
		$output = $root . '/' . ltrim( $output, '/' );
	}
	$output = erankly_release_normalize_path( $output );
	if ( ! str_ends_with( strtolower( $output ), '.zip' ) ) {
		throw new RuntimeException( 'Release output must use a .zip extension.' );
	}
	$output_base = substr( $output, 0, -4 );
	$manifest    = $output_base . '.manifest.json';
	$checksum    = $output_base . '.sha256';
	foreach ( array( $output, $manifest, $checksum ) as $target ) {
		if ( file_exists( $target ) && ! isset( $options['force'] ) ) {
			throw new RuntimeException( 'Output already exists; pass --force to replace it: ' . $target );
		}
	}

	$commit = erankly_release_git( array( 'rev-parse', 'HEAD' ), $root );
	$epoch  = isset( $options['source-date-epoch'] )
		? (int) $options['source-date-epoch']
		: (int) ( getenv( 'SOURCE_DATE_EPOCH' ) ?: erankly_release_git( array( 'show', '-s', '--format=%ct', 'HEAD' ), $root ) );
	$epoch  = max( 315532800, $epoch );
	$files  = erankly_release_collect_files( $root );
	$parent = dirname( $output );
	if ( ! is_dir( $parent ) && ! mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
		throw new RuntimeException( 'Unable to create release output directory.' );
	}

	$temporary = $output . '.tmp-' . bin2hex( random_bytes( 8 ) );
	$zip       = new ZipArchive();
	if ( true !== $zip->open( $temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Unable to create temporary release archive.' );
	}
	$file_manifest = array();
	try {
		foreach ( $files as $relative => $absolute ) {
			$contents = file_get_contents( $absolute );
			if ( ! is_string( $contents ) ) {
				throw new RuntimeException( 'Unable to read release input: ' . $relative );
			}
			$archive_path = 'easyrankly/' . $relative;
			if ( ! $zip->addFromString( $archive_path, $contents ) ) {
				throw new RuntimeException( 'Unable to add archive entry: ' . $archive_path );
			}
			$zip->setMtimeName( $archive_path, $epoch );
			$zip->setCompressionName( $archive_path, ZipArchive::CM_DEFLATE, 9 );
			// ZIP operating-system identifier 3 is Unix. Use the stable numeric ID so
			// the build script remains compatible with the PHP 8.0 Zip extension.
			$zip->setExternalAttributesName( $archive_path, 3, 0100644 << 16 );
			$file_manifest[] = array(
				'path'   => $relative,
				'bytes'  => strlen( $contents ),
				'sha256' => hash( 'sha256', $contents ),
			);
		}
	} catch ( Throwable $error ) {
		$zip->close();
		@unlink( $temporary );
		throw $error;
	}
	if ( ! $zip->close() ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to finalize release archive.' );
	}
	chmod( $temporary, 0644 );
	if ( file_exists( $output ) && ! unlink( $output ) ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to replace existing release archive.' );
	}
	if ( ! rename( $temporary, $output ) ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to publish release archive.' );
	}

	$archive_hash = hash_file( 'sha256', $output );
	$archive_size = filesize( $output );
	if ( ! is_string( $archive_hash ) || false === $archive_size ) {
		throw new RuntimeException( 'Unable to fingerprint release archive.' );
	}
	$metadata = array(
		'schema'            => 1,
		'slug'              => 'easyrankly',
		'version'           => $version,
		'commit'            => $commit,
		'source_date_epoch' => $epoch,
		'generated_at'      => gmdate( 'c', $epoch ),
		'archive'           => basename( $output ),
		'archive_bytes'     => (int) $archive_size,
		'archive_sha256'    => $archive_hash,
		'file_count'        => count( $file_manifest ),
		'files'             => $file_manifest,
	);
	$json = json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) ) {
		throw new RuntimeException( 'Unable to encode release manifest.' );
	}
	erankly_release_atomic_write( $manifest, $json . "\n" );
	erankly_release_atomic_write( $checksum, $archive_hash . '  ' . basename( $output ) . "\n" );

	fwrite( STDOUT, 'Built ' . $output . "\n" );
	fwrite( STDOUT, 'Files: ' . count( $file_manifest ) . "\n" );
	fwrite( STDOUT, 'SHA-256: ' . $archive_hash . "\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Release build failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
