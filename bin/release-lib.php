<?php
/**
 * Shared helpers for deterministic EasyRankly release tooling.
 */

declare(strict_types=1);

/** Returns the repository root. */
function erankly_release_root(): string {
	return dirname( __DIR__ );
}

/** Normalizes a filesystem path to forward slashes. */
function erankly_release_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}

/**
 * Executes a command without invoking a shell.
 *
 * @param array<int,string> $command Command and arguments.
 * @return array{code:int,stdout:string,stderr:string}
 */
function erankly_release_command( array $command, ?string $cwd = null ): array {
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process     = proc_open( $command, $descriptors, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start command: ' . implode( ' ', $command ) );
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );

	return array(
		'code'   => $code,
		'stdout' => is_string( $stdout ) ? $stdout : '',
		'stderr' => is_string( $stderr ) ? $stderr : '',
	);
}

/** Executes Git and returns trimmed stdout, failing on any error. */
function erankly_release_git( array $arguments, ?string $root = null ): string {
	$root   = $root ?? erankly_release_root();
	$result = erankly_release_command( array_merge( array( 'git', '-C', $root ), $arguments ), $root );
	if ( 0 !== $result['code'] ) {
		throw new RuntimeException( trim( $result['stderr'] ) ?: 'Git command failed.' );
	}

	return trim( $result['stdout'] );
}

/** Fails unless the repository has no tracked or untracked changes. */
function erankly_release_assert_clean( ?string $root = null ): void {
	$root   = $root ?? erankly_release_root();
	$status = erankly_release_git( array( 'status', '--porcelain=v1', '--untracked-files=all' ), $root );
	if ( '' !== $status ) {
		throw new RuntimeException( "Release builds require a clean working tree:\n" . $status );
	}
}

/** Reads and validates the version shared by every shipping metadata surface. */
function erankly_release_version( ?string $root = null ): string {
	$root      = $root ?? erankly_release_root();
	$plugin    = (string) file_get_contents( $root . '/easyrankly.php' );
	$readme    = (string) file_get_contents( $root . '/readme.txt' );
	$pot       = (string) file_get_contents( $root . '/languages/easyrankly.pot' );
	$versions  = array();
	$patterns  = array(
		'plugin header'  => array( $plugin, '/^\s*\*\s*Version:\s*([^\s]+)\s*$/m' ),
		'runtime constant' => array( $plugin, "/define\(\s*'ERANKLY_VERSION'\s*,\s*'([^']+)'\s*\)/" ),
		'stable tag'     => array( $readme, '/^Stable tag:\s*([^\s]+)\s*$/mi' ),
		'POT metadata'   => array( $pot, '/Project-Id-Version:\s*EasyRankly\s+([^\\\\]+)\\\\n/' ),
	);
	foreach ( $patterns as $label => $definition ) {
		if ( 1 !== preg_match( $definition[1], $definition[0], $matches ) ) {
			throw new RuntimeException( 'Unable to read ' . $label . ' version.' );
		}
		$versions[ $label ] = trim( $matches[1] );
	}
	$unique = array_values( array_unique( $versions ) );
	if ( 1 !== count( $unique ) ) {
		throw new RuntimeException( 'Release metadata versions differ: ' . json_encode( $versions, JSON_UNESCAPED_SLASHES ) );
	}
	if ( 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $unique[0] ) ) {
		throw new RuntimeException( 'Invalid release version: ' . $unique[0] );
	}

	return $unique[0];
}

/** Fails when a source reference in the POT points to a missing file or line. */
function erankly_release_assert_pot_references( ?string $root = null ): void {
	$root     = $root ?? erankly_release_root();
	$pot_path = $root . '/languages/easyrankly.pot';
	$pot      = file( $pot_path, FILE_IGNORE_NEW_LINES );
	if ( false === $pot ) {
		throw new RuntimeException( 'Unable to read the translation template.' );
	}

	$line_counts = array();
	$checked     = 0;
	foreach ( $pot as $pot_line ) {
		if ( ! str_starts_with( $pot_line, '#:' ) ) {
			continue;
		}

		$references = preg_split( '/\s+/', trim( substr( $pot_line, 2 ) ) );
		if ( false === $references ) {
			throw new RuntimeException( 'Unable to parse POT source references.' );
		}
		foreach ( $references as $reference ) {
			if ( '' === $reference ) {
				continue;
			}

			$line_number = 0;
			$relative    = $reference;
			if ( 1 === preg_match( '/^(.+):([1-9][0-9]*)$/', $reference, $matches ) ) {
				$relative    = $matches[1];
				$line_number = (int) $matches[2];
			}
			$relative = erankly_release_normalize_path( $relative );
			if ( str_starts_with( $relative, './' ) ) {
				$relative = substr( $relative, 2 );
			}
			if ( '' === $relative || str_starts_with( $relative, '/' ) || str_contains( $relative, '../' ) ) {
				throw new RuntimeException( 'POT contains an unsafe source reference: ' . $reference );
			}

			$source = $root . '/' . $relative;
			if ( ! is_file( $source ) ) {
				throw new RuntimeException( 'POT references a missing source file: ' . $relative );
			}
			if ( 0 < $line_number ) {
				if ( ! isset( $line_counts[ $relative ] ) ) {
					$source_lines = file( $source );
					if ( false === $source_lines ) {
						throw new RuntimeException( 'Unable to read POT source file: ' . $relative );
					}
					$line_counts[ $relative ] = count( $source_lines );
				}
				if ( $line_number > $line_counts[ $relative ] ) {
					throw new RuntimeException( 'POT references a missing source line: ' . $reference );
				}
			}
			++$checked;
		}
	}

	if ( 0 === $checked ) {
		throw new RuntimeException( 'The translation template has no source references.' );
	}
}

/** Returns normalized, fail-closed .distignore patterns. */
function erankly_release_ignore_patterns( ?string $root = null ): array {
	$root     = $root ?? erankly_release_root();
	$contents = file( $root . '/.distignore', FILE_IGNORE_NEW_LINES );
	if ( false === $contents ) {
		throw new RuntimeException( 'Unable to read .distignore.' );
	}
	$patterns = array();
	foreach ( $contents as $line ) {
		$line = trim( $line );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			continue;
		}
		if ( str_starts_with( $line, '!' ) ) {
			throw new RuntimeException( 'Negated .distignore patterns are unsupported: ' . $line );
		}
		$pattern = trim( ltrim( $line, '/' ), '/' );
		if ( '' !== $pattern ) {
			$patterns[] = $pattern;
		}
	}

	return array_values( array_unique( $patterns ) );
}

/** Returns whether one repository-relative path is excluded from the archive. */
function erankly_release_is_ignored( string $relative, array $patterns ): bool {
	$relative = trim( erankly_release_normalize_path( $relative ), '/' );
	if ( '' === $relative ) {
		return false;
	}
	$segments = explode( '/', $relative );
	$prefixes = array();
	$prefix   = '';
	foreach ( $segments as $segment ) {
		$prefix     = '' === $prefix ? $segment : $prefix . '/' . $segment;
		$prefixes[] = $prefix;
	}
	foreach ( $patterns as $pattern ) {
		if ( ! str_contains( $pattern, '/' ) ) {
			foreach ( $segments as $segment ) {
				if ( fnmatch( $pattern, $segment ) ) {
					return true;
				}
			}
		}
		foreach ( $prefixes as $candidate ) {
			if ( fnmatch( $pattern, $candidate, FNM_PATHNAME ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Returns the sorted production file map used to build the archive.
 *
 * @return array<string,string> Relative path => absolute path.
 */
function erankly_release_collect_files( ?string $root = null ): array {
	$root     = $root ?? erankly_release_root();
	$real     = realpath( $root );
	$patterns = erankly_release_ignore_patterns( $root );
	if ( false === $real ) {
		throw new RuntimeException( 'Repository root does not exist.' );
	}
	$root_normalized = rtrim( erankly_release_normalize_path( $real ), '/' );
	$directory       = new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS );
	$filter          = new RecursiveCallbackFilterIterator(
		$directory,
		static function ( SplFileInfo $item ) use ( $root_normalized, $patterns ): bool {
			$path     = erankly_release_normalize_path( $item->getPathname() );
			$relative = ltrim( substr( $path, strlen( $root_normalized ) ), '/' );
			if ( erankly_release_is_ignored( $relative, $patterns ) ) {
				return false;
			}
			if ( $item->isLink() ) {
				throw new RuntimeException( 'Symlinks are not allowed in release input: ' . $relative );
			}

			return $item->isDir() || $item->isFile();
		}
	);
	$iterator = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::LEAVES_ONLY );
	$files    = array();
	foreach ( $iterator as $item ) {
		if ( ! $item instanceof SplFileInfo || ! $item->isFile() ) {
			continue;
		}
		$path                 = erankly_release_normalize_path( $item->getPathname() );
		$relative             = ltrim( substr( $path, strlen( $root_normalized ) ), '/' );
		$files[ $relative ] = $item->getPathname();
	}
	ksort( $files, SORT_STRING );
	if ( ! isset( $files['easyrankly.php'], $files['readme.txt'], $files['uninstall.php'] ) ) {
		throw new RuntimeException( 'Release input is missing required plugin files.' );
	}

	return $files;
}

/** Writes one file atomically. */
function erankly_release_atomic_write( string $path, string $contents ): void {
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Unable to create output directory: ' . $directory );
	}
	$temporary = $path . '.tmp-' . bin2hex( random_bytes( 8 ) );
	if ( strlen( $contents ) !== file_put_contents( $temporary, $contents, LOCK_EX ) ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to write temporary output: ' . $temporary );
	}
	chmod( $temporary, 0644 );
	if ( file_exists( $path ) && ! unlink( $path ) ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to replace output: ' . $path );
	}
	if ( ! rename( $temporary, $path ) ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to publish output: ' . $path );
	}
}

/** Recursively removes a temporary directory created by release tooling. */
function erankly_release_remove_tree( string $path ): void {
	$real = realpath( $path );
	$temp = realpath( sys_get_temp_dir() );
	if ( false === $real || false === $temp || ! str_starts_with( erankly_release_normalize_path( $real ), rtrim( erankly_release_normalize_path( $temp ), '/' ) . '/' ) ) {
		throw new RuntimeException( 'Refusing to remove a non-temporary path.' );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}
	rmdir( $real );
}
