<?php
/**
 * Verifies an EasyRankly archive against its manifest and clean source tree.
 *
 * Usage: php bin/verify-release.php [--archive=dist/easyrankly-X.Y.Z.zip]
 */

declare(strict_types=1);

require_once __DIR__ . '/release-lib.php';

$temporary_directory = '';
try {
	if ( ! class_exists( ZipArchive::class ) ) {
		throw new RuntimeException( 'The PHP zip extension is required to verify a release.' );
	}
	$options = getopt( '', array( 'archive:', 'manifest:' ) );
	$root    = erankly_release_root();
	$version = erankly_release_version( $root );
	erankly_release_assert_pot_references( $root );
	erankly_release_assert_clean( $root );

	$archive = isset( $options['archive'] ) ? (string) $options['archive'] : 'dist/easyrankly-' . $version . '.zip';
	if ( ! str_starts_with( $archive, '/' ) ) {
		$archive = $root . '/' . ltrim( $archive, '/' );
	}
	$archive = erankly_release_normalize_path( $archive );
	$manifest_path = isset( $options['manifest'] )
		? (string) $options['manifest']
		: substr( $archive, 0, -4 ) . '.manifest.json';
	if ( ! str_starts_with( $manifest_path, '/' ) ) {
		$manifest_path = $root . '/' . ltrim( $manifest_path, '/' );
	}
	$checksum_path = substr( $archive, 0, -4 ) . '.sha256';
	if ( ! is_file( $archive ) || ! is_file( $manifest_path ) || ! is_file( $checksum_path ) ) {
		throw new RuntimeException( 'Archive, manifest, or checksum file is missing.' );
	}

	$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
	if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['schema'] ?? 0 ) ) {
		throw new RuntimeException( 'Release manifest is invalid.' );
	}
	$commit       = erankly_release_git( array( 'rev-parse', 'HEAD' ), $root );
	$archive_hash = hash_file( 'sha256', $archive );
	$archive_size = filesize( $archive );
	if (
		'easyrankly' !== (string) ( $manifest['slug'] ?? '' )
		|| $version !== (string) ( $manifest['version'] ?? '' )
		|| $commit !== (string) ( $manifest['commit'] ?? '' )
		|| ! is_string( $archive_hash )
		|| $archive_hash !== (string) ( $manifest['archive_sha256'] ?? '' )
		|| false === $archive_size
		|| (int) $archive_size !== (int) ( $manifest['archive_bytes'] ?? -1 )
	) {
		throw new RuntimeException( 'Archive metadata does not match the manifest and current commit.' );
	}
	$checksum = trim( (string) file_get_contents( $checksum_path ) );
	if ( $archive_hash . '  ' . basename( $archive ) !== $checksum ) {
		throw new RuntimeException( 'Checksum sidecar does not match the archive.' );
	}

	$source_files  = erankly_release_collect_files( $root );
	$manifest_rows = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : array();
	$manifest_map  = array();
	foreach ( $manifest_rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['path'], $row['sha256'], $row['bytes'] ) ) {
			throw new RuntimeException( 'Release manifest contains an invalid file row.' );
		}
		$path = trim( erankly_release_normalize_path( (string) $row['path'] ), '/' );
		if ( '' === $path || isset( $manifest_map[ $path ] ) ) {
			throw new RuntimeException( 'Release manifest contains an empty or duplicate path.' );
		}
		$manifest_map[ $path ] = $row;
	}
	ksort( $manifest_map, SORT_STRING );
	if ( array_keys( $source_files ) !== array_keys( $manifest_map ) || count( $manifest_map ) !== (int) ( $manifest['file_count'] ?? -1 ) ) {
		throw new RuntimeException( 'Manifest file inventory differs from the source tree.' );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $archive, ZipArchive::RDONLY ) ) {
		throw new RuntimeException( 'Unable to open release archive.' );
	}
	$archive_map = array();
	try {
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$name = $zip->getNameIndex( $index );
			if ( ! is_string( $name ) || ! str_starts_with( $name, 'easyrankly/' ) || str_ends_with( $name, '/' ) ) {
				throw new RuntimeException( 'Archive contains an invalid entry name.' );
			}
			$relative = substr( $name, strlen( 'easyrankly/' ) );
			if ( '' === $relative || str_contains( $relative, '../' ) || str_starts_with( $relative, '/' ) || isset( $archive_map[ $relative ] ) ) {
				throw new RuntimeException( 'Archive contains an unsafe or duplicate entry.' );
			}
			$operating_system = 0;
			$attributes       = 0;
			if ( ! $zip->getExternalAttributesIndex( $index, $operating_system, $attributes ) || 0120000 === ( ( $attributes >> 16 ) & 0170000 ) ) {
				throw new RuntimeException( 'Archive contains an unverifiable or symbolic-link entry.' );
			}
			$contents = $zip->getFromIndex( $index );
			if ( ! is_string( $contents ) ) {
				throw new RuntimeException( 'Unable to read archive entry: ' . $name );
			}
			$archive_map[ $relative ] = array(
				'bytes'  => strlen( $contents ),
				'sha256' => hash( 'sha256', $contents ),
			);
		}
		ksort( $archive_map, SORT_STRING );
		if ( array_keys( $archive_map ) !== array_keys( $manifest_map ) ) {
			throw new RuntimeException( 'Archive inventory differs from the manifest.' );
		}
		foreach ( $source_files as $relative => $absolute ) {
			$source_hash = hash_file( 'sha256', $absolute );
			$source_size = filesize( $absolute );
			$row         = $manifest_map[ $relative ];
			$entry       = $archive_map[ $relative ];
			if (
				! is_string( $source_hash )
				|| false === $source_size
				|| $source_hash !== (string) $row['sha256']
				|| $source_hash !== $entry['sha256']
				|| (int) $source_size !== (int) $row['bytes']
				|| (int) $source_size !== $entry['bytes']
			) {
				throw new RuntimeException( 'Archive content differs from source: ' . $relative );
			}
		}

		$temporary_directory = sys_get_temp_dir() . '/easyrankly-release-verify-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $temporary_directory, 0700, true ) || ! $zip->extractTo( $temporary_directory ) ) {
			throw new RuntimeException( 'Unable to extract release archive for executable checks.' );
		}
	} finally {
		$zip->close();
	}

	foreach ( array_keys( $manifest_map ) as $relative ) {
		if ( ! str_ends_with( strtolower( $relative ), '.php' ) ) {
			continue;
		}
		$result = erankly_release_command( array( PHP_BINARY, '-l', $temporary_directory . '/easyrankly/' . $relative ), $root );
		if ( 0 !== $result['code'] ) {
			throw new RuntimeException( trim( $result['stderr'] . "\n" . $result['stdout'] ) );
		}
	}
	$phpcs = $root . '/vendor/bin/phpcs';
	if ( ! is_file( $phpcs ) ) {
		throw new RuntimeException( 'Composer dependencies are required for archive PHPCS verification.' );
	}
	$phpcs_result = erankly_release_command(
		array( PHP_BINARY, $phpcs, '--standard=' . $root . '/phpcs.xml.dist', '--extensions=php', $temporary_directory . '/easyrankly' ),
		$root
	);
	if ( 0 !== $phpcs_result['code'] ) {
		throw new RuntimeException( trim( $phpcs_result['stderr'] . "\n" . $phpcs_result['stdout'] ) );
	}

	erankly_release_remove_tree( $temporary_directory );
	$temporary_directory = '';
	fwrite( STDOUT, 'Release verified: ' . $archive . "\n" );
	fwrite( STDOUT, 'Files: ' . count( $manifest_map ) . "\n" );
	fwrite( STDOUT, 'SHA-256: ' . $archive_hash . "\n" );
} catch ( Throwable $error ) {
	if ( '' !== $temporary_directory && is_dir( $temporary_directory ) ) {
		try {
			erankly_release_remove_tree( $temporary_directory );
		} catch ( Throwable ) {
			// Preserve the primary verification failure.
		}
	}
	fwrite( STDERR, 'Release verification failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
