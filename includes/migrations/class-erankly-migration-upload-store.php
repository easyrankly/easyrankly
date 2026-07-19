<?php
/**
 * Private lifecycle for official migration-export uploads.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stages validated imports and source exports outside the public WordPress tree. */
final class ERankly_Migration_Upload_Store {
	private const FILE_PREFIX        = 'erankly-source-';
	private const IMPORT_FILE_PREFIX = 'erankly-import-';
	private const SOURCES            = array( 'yoast', 'rankmath', 'aioseo', 'seopress' );

	/**
	 * Stages one genuine PHP upload and identifies its source signature.
	 *
	 * @param array<string,mixed> $file             One normalized $_FILES entry.
	 * @param string              $requested_source auto or a supported adapter slug.
	 * @return array<string,mixed>
	 */
	public static function store_http_upload( array $file, string $requested_source = 'auto' ): array {
		$requested_source = sanitize_key( $requested_source );
		if ( 'auto' !== $requested_source && ! in_array( $requested_source, self::SOURCES, true ) ) {
			return self::failure( 'invalid_source' );
		}

		$stored = self::store_wordpress_upload(
			$file,
			self::FILE_PREFIX,
			array(
				'csv'  => 'text/csv',
				'json' => 'application/json',
			),
			max( 1024, (int) apply_filters( 'erankly_migration_export_max_bytes', 100 * MB_IN_BYTES ) )
		);
		if ( empty( $stored['ok'] ) ) {
			return $stored;
		}

		$path      = (string) $stored['path'];
		$detection = self::detect_source( $path, $requested_source );
		if ( empty( $detection['ok'] ) ) {
			self::delete( $path );
			return $detection;
		}

		return array(
			'ok'            => true,
			'path'          => $path,
			'source'        => (string) $detection['source'],
			'format'        => (string) $detection['format'],
			'original_name' => (string) $stored['original_name'],
			'size'          => (int) $stored['size'],
		);
	}

	/**
	 * Stores a validated complete EasyRankly JSON import in private storage.
	 *
	 * @param array<string,mixed> $file    One normalized $_FILES entry.
	 * @param int                 $maximum Maximum accepted bytes.
	 * @return array<string,mixed>
	 */
	public static function store_import_http_upload( array $file, int $maximum ): array {
		return self::store_wordpress_upload(
			$file,
			self::IMPORT_FILE_PREFIX,
			array( 'json' => 'application/json' ),
			max( 1024, $maximum )
		);
	}

	/**
	 * Stages a trusted local fixture or integration-owned source file.
	 *
	 * This is intentionally separate from the HTTP entry point. Admin requests
	 * must always use store_http_upload(), which enforces is_uploaded_file().
	 *
	 * @param string $path             Trusted local source path.
	 * @param string $requested_source auto or a supported adapter slug.
	 * @return array<string,mixed>
	 */
	public static function stage_trusted_file( string $path, string $requested_source = 'auto' ): array {
		return self::stage( $path, basename( $path ), $requested_source );
	}

	/**
	 * Returns the private directory, creating it when requested.
	 *
	 * @param bool $create Whether to create the directory.
	 * @return string Empty when no non-public writable directory is available.
	 */
	public static function directory( bool $create = true ): string {
		$site_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$token   = substr( hash( 'sha256', wp_normalize_path( ABSPATH ) . '|' . (string) $site_id ), 0, 20 );
		$base    = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$path    = (string) apply_filters( 'erankly_migration_private_directory', trailingslashit( $base ) . 'easyrankly-migrations-' . $token, $site_id );
		$path    = untrailingslashit( wp_normalize_path( $path ) );

		if ( '' === $path || self::is_public_path( $path ) ) {
			return '';
		}
		if ( $create && ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return '';
		}
		if ( ! is_dir( $path ) || is_link( $path ) || ! wp_is_writable( $path ) ) {
			return '';
		}

		$real = realpath( $path );
		$real = false === $real ? '' : untrailingslashit( wp_normalize_path( $real ) );
		if ( '' === $real || self::is_public_path( $real ) ) {
			return '';
		}

		if ( $create ) {
			$restricted = chmod( $real, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private OS temp storage needs restrictive permissions and is not a WordPress media file.
			clearstatcache( true, $real );
			$permissions = fileperms( $real );
			if ( ! $restricted || false === $permissions || 0 !== ( $permissions & 0077 ) ) {
				return '';
			}
		}

		return $real;
	}

	/**
	 * Returns whether a path belongs to this site's managed private directory.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	public static function owns( string $path ): bool {
		$directory = self::directory( false );
		$path      = wp_normalize_path( $path );
		$basename  = basename( $path );

		return '' !== $directory
			&& hash_equals( $directory, untrailingslashit( wp_normalize_path( dirname( $path ) ) ) )
			&& 1 === preg_match( '/^' . preg_quote( self::FILE_PREFIX, '/' ) . '[a-f0-9]{32}\.(csv|json)$/', $basename );
	}

	/**
	 * Deletes one managed file without accepting arbitrary server paths.
	 *
	 * @param string $path Managed path.
	 * @return bool
	 */
	public static function delete( string $path ): bool {
		if ( ! self::owns( $path ) ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}

		$deleted = unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The file is verified private OS-temp data; bypassing wp_delete_file filters prevents path substitution.
		clearstatcache( true, $path );
		return $deleted && ! file_exists( $path );
	}

	/**
	 * Removes abandoned managed uploads while preserving the active job source.
	 *
	 * @return int Number of files removed.
	 */
	public static function prune_stale(): int {
		$directory = self::directory( false );
		if ( '' === $directory ) {
			return 0;
		}

		$active      = function_exists( 'get_option' ) ? get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, array() ) : array();
		$active_file = is_array( $active ) ? wp_normalize_path( (string) ( $active['source_file'] ?? '' ) ) : '';
		$ttl         = max( 300, (int) apply_filters( 'erankly_migration_upload_ttl', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) );
		$cutoff      = time() - $ttl;
		$deleted     = 0;

		try {
			$iterator = new DirectoryIterator( $directory );
		} catch ( UnexpectedValueException ) {
			return 0;
		}

		foreach ( $iterator as $file ) {
			if ( $file->isDot() || $file->isLink() || ! $file->isFile() || $file->getMTime() >= $cutoff ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( '' !== $active_file && hash_equals( $active_file, $path ) ) {
				continue;
			}
			if ( self::delete( $path ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Removes every managed upload for the current site during reset/uninstall.
	 *
	 * Files outside the managed directory and filenames outside the random
	 * managed pattern are never touched.
	 *
	 * @param bool $remove_directory Whether to remove the empty private directory.
	 * @return bool Whether every managed file and requested directory was removed.
	 */
	public static function purge_all( bool $remove_directory = false ): bool {
		$directory = self::directory( false );
		if ( '' === $directory ) {
			return true;
		}

		try {
			$iterator = new DirectoryIterator( $directory );
		} catch ( UnexpectedValueException ) {
			return false;
		}

		$success = true;
		foreach ( $iterator as $file ) {
			if ( $file->isDot() ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( ! self::owns( $path ) ) {
				continue;
			}
			if ( $file->isLink() || ! $file->isFile() || ! self::delete( $path ) ) {
				$success = false;
			}
		}

		if ( $remove_directory && $success ) {
			$success = rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The path is the verified empty per-site private directory.
		}

		return $success;
	}

	/**
	 * Copies, detects and validates one trusted source file.
	 *
	 * @param string $source_path     Temporary/trusted source path.
	 * @param string $original_name   Original filename used only for extension validation.
	 * @param string $requested_source auto or a supported adapter slug.
	 * @return array<string,mixed>
	 */
	private static function stage( string $source_path, string $original_name, string $requested_source ): array {
		$requested_source = sanitize_key( $requested_source );
		if ( 'auto' !== $requested_source && ! in_array( $requested_source, self::SOURCES, true ) ) {
			return self::failure( 'invalid_source' );
		}
		if ( ! is_file( $source_path ) || is_link( $source_path ) || ! is_readable( $source_path ) ) {
			return self::failure( 'unreadable_upload' );
		}

		$extension = strtolower( (string) pathinfo( sanitize_file_name( $original_name ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'json' ), true ) ) {
			return self::failure( 'unsupported_extension' );
		}

		$size    = filesize( $source_path );
		$maximum = max( 1024, (int) apply_filters( 'erankly_migration_export_max_bytes', 100 * MB_IN_BYTES ) );
		if ( false === $size || $size < 1 ) {
			return self::failure( 'empty_upload' );
		}
		if ( $size > $maximum ) {
			return self::failure( 'upload_too_large' );
		}

		$directory = self::directory();
		if ( '' === $directory ) {
			return self::failure( 'private_storage_unavailable' );
		}

		$destination = $directory . '/' . self::FILE_PREFIX . self::random_token() . '.' . $extension;
		$stored      = copy( $source_path, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Trusted fixtures are copied only into non-public managed storage.
		if ( ! $stored || ! is_file( $destination ) || is_link( $destination ) ) {
			if ( is_file( $destination ) && ! is_link( $destination ) ) {
				self::delete( $destination );
			}
			return self::failure( 'private_storage_write_failed' );
		}
		$restricted = chmod( $destination, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Sensitive source export in private OS temp storage.
		clearstatcache( true, $destination );
		$permissions = fileperms( $destination );
		if ( ! $restricted || false === $permissions || 0 !== ( $permissions & 0077 ) ) {
			self::delete( $destination );
			return self::failure( 'private_storage_permissions_failed' );
		}

		$detection = self::detect_source( $destination, $requested_source );
		if ( empty( $detection['ok'] ) ) {
			self::delete( $destination );
			return $detection;
		}

		return array(
			'ok'            => true,
			'path'          => $destination,
			'source'        => (string) $detection['source'],
			'format'        => (string) $detection['format'],
			'original_name' => sanitize_file_name( $original_name ),
			'size'          => (int) $size,
		);
	}

	/**
	 * Moves one genuine PHP upload through the WordPress uploader into private storage.
	 *
	 * The upload_dir filter exists only for this synchronous call. The returned path
	 * is then checked against the private directory and random managed filename so
	 * another upload filter cannot redirect retained data into a public location.
	 *
	 * @param array<string,mixed>  $file        One normalized $_FILES entry.
	 * @param string               $file_prefix Managed random filename prefix.
	 * @param array<string,string> $mimes       Allowed extension-to-MIME map.
	 * @param int                  $maximum     Maximum accepted bytes.
	 * @return array<string,mixed>
	 */
	private static function store_wordpress_upload( array $file, string $file_prefix, array $mimes, int $maximum ): array {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			return self::failure( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'upload_too_large' : 'upload_failed' );
		}

		$tmp_name  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name      = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( (string) $file['name'] ) ) : '';
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $tmp_name || '' === $name || ! is_uploaded_file( $tmp_name ) ) {
			return self::failure( 'invalid_http_upload' );
		}
		if ( ! isset( $mimes[ $extension ] ) ) {
			return self::failure( 'unsupported_extension' );
		}

		clearstatcache( true, $tmp_name );
		$size = filesize( $tmp_name );
		if ( false === $size || $size < 1 ) {
			return self::failure( 'empty_upload' );
		}
		if ( $size > $maximum ) {
			return self::failure( 'upload_too_large' );
		}

		$directory = self::directory();
		if ( '' === $directory ) {
			return self::failure( 'private_storage_unavailable' );
		}
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			return self::failure( 'private_storage_unavailable' );
		}

		$upload_dir_filter = static function ( array $uploads ) use ( $directory ): array {
			$uploads['path']    = $directory;
			$uploads['url']     = '';
			$uploads['subdir']  = '';
			$uploads['basedir'] = $directory;
			$uploads['baseurl'] = '';
			$uploads['error']   = false;
			return $uploads;
		};
		$filename_callback = static function ( string $unused_directory, string $unused_name, string $unused_extension ) use ( $file_prefix, $extension ): string {
			unset( $unused_directory, $unused_name, $unused_extension );
			return $file_prefix . self::random_token() . '.' . $extension;
		};

		$normalized_file = array(
			'name'     => $name,
			'type'     => isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '',
			'tmp_name' => $tmp_name,
			'error'    => $error,
			'size'     => (int) $size,
		);

		add_filter( 'upload_dir', $upload_dir_filter, PHP_INT_MAX );
		try {
			$handled = wp_handle_upload(
				$normalized_file,
				array(
					'test_form'                => false,
					'test_size'                => true,
					'test_type'                => true,
					'mimes'                    => $mimes,
					'unique_filename_callback' => $filename_callback,
				)
			);
		} finally {
			remove_filter( 'upload_dir', $upload_dir_filter, PHP_INT_MAX );
		}

		$handled_path = is_array( $handled ) && ! empty( $handled['file'] )
			? untrailingslashit( wp_normalize_path( (string) $handled['file'] ) )
			: '';
		if ( ! is_array( $handled ) || ! empty( $handled['error'] ) || '' === $handled_path ) {
			if ( '' !== $handled_path ) {
				self::delete_staged_path( $handled_path, $directory, $file_prefix, array_keys( $mimes ) );
			}
			return self::failure( 'upload_failed' );
		}

		$path = $handled_path;
		if ( ! self::is_staged_path( $path, $directory, $file_prefix, array_keys( $mimes ) ) ) {
			self::delete_staged_path( $path, $directory, $file_prefix, array_keys( $mimes ) );
			return self::failure( 'private_storage_write_failed' );
		}

		clearstatcache( true, $path );
		$stored_size = filesize( $path );
		if ( false === $stored_size || $stored_size < 1 || $stored_size > $maximum || is_link( $path ) ) {
			self::delete_staged_path( $path, $directory, $file_prefix, array_keys( $mimes ) );
			return self::failure( false !== $stored_size && $stored_size > $maximum ? 'upload_too_large' : 'private_storage_write_failed' );
		}

		$restricted = chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Sensitive imports stay in private OS-temp storage.
		clearstatcache( true, $path );
		$permissions = fileperms( $path );
		if ( ! $restricted || false === $permissions || 0 !== ( $permissions & 0077 ) ) {
			self::delete_staged_path( $path, $directory, $file_prefix, array_keys( $mimes ) );
			return self::failure( 'private_storage_permissions_failed' );
		}

		return array(
			'ok'            => true,
			'path'          => $path,
			'original_name' => $name,
			'size'          => (int) $stored_size,
		);
	}

	/** Returns a cryptographically random managed filename token. */
	private static function random_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception ) {
			return str_replace( '-', '', wp_generate_uuid4() );
		}
	}

	/**
	 * Checks a freshly staged path against the exact private upload contract.
	 *
	 * @param string            $path         Candidate file.
	 * @param string            $directory    Expected private directory.
	 * @param string            $file_prefix  Expected filename prefix.
	 * @param array<int,string> $extensions  Allowed extensions.
	 */
	private static function is_staged_path( string $path, string $directory, string $file_prefix, array $extensions ): bool {
		$path       = wp_normalize_path( $path );
		$directory  = untrailingslashit( wp_normalize_path( $directory ) );
		$extensions = array_values( array_filter( array_map( 'sanitize_key', $extensions ) ) );
		$pattern    = '/^' . preg_quote( $file_prefix, '/' ) . '[a-f0-9]{32}\.(' . implode( '|', array_map( static fn( string $extension ): string => preg_quote( $extension, '/' ), $extensions ) ) . ')$/';

		return $extensions
			&& hash_equals( $directory, untrailingslashit( wp_normalize_path( dirname( $path ) ) ) )
			&& 1 === preg_match( $pattern, basename( $path ) )
			&& is_file( $path )
			&& ! is_link( $path );
	}

	/**
	 * Deletes only a path satisfying the freshly staged private upload contract.
	 *
	 * @param string            $path        Candidate file.
	 * @param string            $directory   Expected private directory.
	 * @param string            $file_prefix Expected filename prefix.
	 * @param array<int,string> $extensions Allowed extensions.
	 */
	private static function delete_staged_path( string $path, string $directory, string $file_prefix, array $extensions ): bool {
		return self::is_staged_path( $path, $directory, $file_prefix, $extensions )
			&& unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact private directory and managed random filename were verified above.
	}

	/**
	 * Identifies a certified source signature and rejects mismatches.
	 *
	 * @param string $path             Managed export path.
	 * @param string $requested_source auto or a supported adapter slug.
	 * @return array<string,mixed>
	 */
	private static function detect_source( string $path, string $requested_source ): array {
		$matches = array();
		foreach ( self::SOURCES as $source ) {
			$inspection = ERankly_Migration_Export_Reader::inspect( $path, $source );
			if ( 'supported' === (string) ( $inspection['status'] ?? '' ) ) {
				$matches[ $source ] = (string) ( $inspection['format'] ?? '' );
			}
		}

		if ( 'auto' !== $requested_source ) {
			if ( isset( $matches[ $requested_source ] ) ) {
				return array(
					'ok'     => true,
					'source' => $requested_source,
					'format' => $matches[ $requested_source ],
				);
			}
			return self::failure( $matches ? 'source_mismatch' : 'unsupported_export_signature' );
		}

		if ( 1 !== count( $matches ) ) {
			return self::failure( $matches ? 'ambiguous_export_signature' : 'unsupported_export_signature' );
		}

		$source = (string) array_key_first( $matches );
		return array(
			'ok'     => true,
			'source' => $source,
			'format' => $matches[ $source ],
		);
	}

	/**
	 * Rejects private paths inside the public WordPress/content trees.
	 *
	 * @param string $path Candidate directory.
	 * @return bool
	 */
	private static function is_public_path( string $path ): bool {
		$path  = untrailingslashit( wp_normalize_path( $path ) );
		$roots = array( ABSPATH );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = WP_CONTENT_DIR;
		}

		foreach ( $roots as $root ) {
			$root = untrailingslashit( wp_normalize_path( (string) $root ) );
			if ( '' !== $root && ( hash_equals( $root, $path ) || str_starts_with( $path, $root . '/' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a stable failure payload.
	 *
	 * @param string $error Machine-readable error code.
	 * @return array{ok:false,error:string}
	 */
	private static function failure( string $error ): array {
		return array(
			'ok'    => false,
			'error' => sanitize_key( $error ),
		);
	}
}
