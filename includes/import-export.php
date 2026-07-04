<?php
/**
 * Import / Export module.
 *
 * Exports and restores all EasyRankly data (settings, redirects, post and term
 * meta) as a single JSON file, and imports useful SEO data from Yoast SEO and
 * Rank Math.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export file format version. Bumped when the JSON structure changes.
 */
define( 'ERANKLY_EXPORT_FORMAT', '1.0' );

/**
 * Loads redirect class files on demand even when the module is disabled.
 *
 * This lets export/import handle redirect data without requiring the feature
 * to be switched on first.
 *
 * @return void
 */
function erankly_ensure_redirect_classes_available(): void {
	$base = ERANKLY_PATH . 'includes/redirects/';

	$files = array(
		'class-erankly-redirects-normalizer.php',
		'class-erankly-redirects-activator.php',
		'class-erankly-redirects-repository.php',
	);

	foreach ( $files as $file ) {
		if ( file_exists( $base . $file ) ) {
			require_once $base . $file;
		}
	}
}


/**
 * Returns the settings page URL for the Import / Export tab.
 *
 * @return string
 */
function erankly_import_export_url(): string {
	// On Multisite the Import/Export tab lives under Network Admin → Settings, so
	// the form target and redirect must resolve to network/settings.php; on a
	// single site it stays on the standard options-general.php settings screen.
	$base = is_network_admin()
		? network_admin_url( 'settings.php' )
		: admin_url( 'options-general.php' );

	return add_query_arg(
		array(
			'page'        => 'erankly',
			'erankly_tab' => 'import-export',
		),
		$base
	);
}

/**
 * Dispatches import/export form submissions on the settings page.
 *
 * @return void
 */
function erankly_import_export_handle_actions(): void {
	// On Multisite the settings option is a network option; gate write access accordingly.
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 'erankly' !== $page ) {
		return;
	}

	// Export is a nonce-protected GET link that streams a download.
	if ( isset( $_GET['erankly_io_action'] ) && 'export' === sanitize_key( wp_unslash( $_GET['erankly_io_action'] ) ) ) {
		// check_admin_referer() dies on failure, so no error branch is needed.
		check_admin_referer( 'erankly_io_export' );

		erankly_export_download();
	}

	if ( ! isset( $_POST['erankly_io_action'] ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['erankly_io_action'] ) );

	if ( 'import' === $action ) {
		erankly_import_export_handle_import();
	}

	if ( in_array( $action, array( 'yoast', 'rankmath', 'aioseo' ), true ) ) {
		erankly_import_export_handle_third_party( $action );
	}
}

/**
 * Handles a full-data JSON import upload.
 *
 * @return void
 */
function erankly_import_export_handle_import(): void {
	check_admin_referer( 'erankly_io_import' );

	if (
		empty( $_FILES['erankly_import_file'] ) ||
		! isset( $_FILES['erankly_import_file']['tmp_name'], $_FILES['erankly_import_file']['error'] ) ||
		UPLOAD_ERR_OK !== (int) $_FILES['erankly_import_file']['error']
	) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$tmp_name = (string) $_FILES['erankly_import_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	// Defence in depth: only ever read a genuine PHP upload, never an arbitrary
	// server path that could reach this handler through a crafted request.
	if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;

	$contents = ( $wp_filesystem instanceof WP_Filesystem_Base ) ? $wp_filesystem->get_contents( $tmp_name ) : false;

	if ( false === $contents || '' === trim( (string) $contents ) ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$data = json_decode( (string) $contents, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'erankly' ) {
		erankly_import_export_redirect( array( 'erankly_io_notice' => 'invalid' ) );
	}

	$counts = erankly_import_apply( $data );

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => 'imported',
			'er_settings'       => (int) $counts['settings'],
			'er_redirects'      => (int) $counts['redirects'],
			'er_post_meta'      => (int) $counts['post_meta'],
			'er_term_meta'      => (int) $counts['term_meta'],
		)
	);
}

/**
 * Handles an import from a third-party SEO plugin.
 *
 * @param string $source Source plugin: yoast|rankmath|aioseo.
 * @return void
 */
function erankly_import_export_handle_third_party( string $source ): void {
	check_admin_referer( 'erankly_io_third_party' );

	$counts = erankly_import_third_party( $source );

	erankly_import_export_redirect(
		array(
			'erankly_io_notice' => $source,
			'er_post_meta'      => (int) $counts['post_meta'],
			'er_term_meta'      => (int) $counts['term_meta'],
		)
	);
}

/**
 * Redirects back to the Import / Export tab with notice arguments.
 *
 * @param array<string,mixed> $args Query args.
 * @return void
 */
function erankly_import_export_redirect( array $args ): void {
	wp_safe_redirect( add_query_arg( $args, erankly_import_export_url() ) );
	exit;
}

/**
 * Builds the complete export payload.
 *
 * @return array<string,mixed>
 */
function erankly_export_build_data(): array {
	global $wpdb;

	$meta_keys = array_keys( erankly_get_meta_keys() );

	$data = array(
		'plugin'      => 'erankly',
		'format'      => ERANKLY_EXPORT_FORMAT,
		'version'     => ERANKLY_VERSION,
		'exported_at' => gmdate( 'c' ),
		'site_url'    => home_url(),
		'settings'    => erankly_get_plugin_option( ERANKLY_OPTION, array() ),
		'redirects'   => array(),
		'post_meta'   => array(),
		'term_meta'   => array(),
	);

	// Always export redirects when the table has data, even if the module is off —
	// that data should stay portable regardless of the feature toggle.
	erankly_ensure_redirect_classes_available();

	if ( class_exists( 'ERankly_Redirects_Repository' ) ) {
		$repository = new ERankly_Redirects_Repository();
		// get_all_for_export() returns an empty array when the table does not exist.
		$data['redirects'] = $repository->get_all_for_export();
	}

	$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

	// Post meta.
	$post_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export needs all EasyRankly post meta rows.
		$wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_keys
		),
		ARRAY_A
	);

	if ( is_array( $post_rows ) ) {
		foreach ( $post_rows as $row ) {
			$data['post_meta'][] = array(
				'id'    => (int) $row['post_id'],
				'key'   => (string) $row['meta_key'],
				'value' => (string) $row['meta_value'],
			);
		}
	}

	// Term meta.
	$term_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export needs all EasyRankly term meta rows.
		$wpdb->prepare(
			"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_keys
		),
		ARRAY_A
	);

	if ( is_array( $term_rows ) ) {
		foreach ( $term_rows as $row ) {
			$data['term_meta'][] = array(
				'id'    => (int) $row['term_id'],
				'key'   => (string) $row['meta_key'],
				'value' => (string) $row['meta_value'],
			);
		}
	}

	// Per-site special page metadata. On Multisite this lives in a dedicated
	// per-site option outside the (network-wide) settings array, so it has to be
	// exported on its own; on single-site it is already part of 'settings'.
	$special_meta = get_option( ERANKLY_SPECIAL_META_OPTION, null );

	if ( is_array( $special_meta ) ) {
		$data['special_meta'] = $special_meta;
	}

	return $data;
}

/**
 * Streams the export payload as a JSON download.
 *
 * @return void
 */
function erankly_export_download(): void {
	$data     = erankly_export_build_data();
	$filename = 'erankly-export-' . gmdate( 'Y-m-d-His' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

/**
 * Restores an EasyRankly export payload.
 *
 * @param array<string,mixed> $data Decoded export data.
 * @return array{settings:int,redirects:int,post_meta:int,term_meta:int}
 */
function erankly_import_apply( array $data ): array {
	$counts = array(
		'settings'  => 0,
		'redirects' => 0,
		'post_meta' => 0,
		'term_meta' => 0,
	);

	// Settings.
	if ( isset( $data['settings'] ) && is_array( $data['settings'] ) && function_exists( 'erankly_sanitize_settings' ) ) {
		$clean = erankly_sanitize_settings( $data['settings'] );
		erankly_update_plugin_option( ERANKLY_OPTION, $clean );
		$counts['settings'] = 1;
	}

	// Accept per-site special-page metadata on either architecture so imported
	// configuration files remain portable.
	if ( isset( $data['special_meta'] ) && is_array( $data['special_meta'] ) ) {
		erankly_update_special_meta_map( $data['special_meta'] );
		$counts['settings'] = 1;
	}

	// Redirects — restore regardless of whether the module is currently enabled.
	// The redirect table is created on demand so data is never lost.
	if ( ! empty( $data['redirects'] ) && is_array( $data['redirects'] ) ) {
		erankly_ensure_redirect_classes_available();

		if ( class_exists( 'ERankly_Redirects_Repository' ) && class_exists( 'ERankly_Redirects_Normalizer' ) ) {
			// Make sure the DB table exists even if the module was never activated.
			if ( class_exists( 'ERankly_Redirects_Activator' ) ) {
				ERankly_Redirects_Activator::activate();
			}

			$repository = new ERankly_Redirects_Repository();

			foreach ( $data['redirects'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$redirect = erankly_import_prepare_redirect( $row );

				if ( null === $redirect ) {
					continue;
				}

				if ( in_array( $repository->upsert_by_hash( $redirect ), array( 'created', 'updated' ), true ) ) {
					++$counts['redirects'];
				}
			}
		}
	}

	// Post meta.
	if ( ! empty( $data['post_meta'] ) && is_array( $data['post_meta'] ) ) {
		$allowed = erankly_get_meta_keys();

		foreach ( $data['post_meta'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$post_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$key     = isset( $entry['key'] ) ? (string) $entry['key'] : '';

			if ( $post_id <= 0 || ! isset( $allowed[ $key ] ) || ! get_post( $post_id ) ) {
				continue;
			}

			// wp_slash(): update_post_meta() unslashes its input, which would strip
			// literal backslashes from the imported value.
			update_post_meta( $post_id, $key, wp_slash( erankly_sanitize_registered_meta( $entry['value'] ?? '', $key ) ) );
			++$counts['post_meta'];
		}
	}

	// Term meta.
	if ( ! empty( $data['term_meta'] ) && is_array( $data['term_meta'] ) ) {
		$allowed = erankly_get_meta_keys();

		foreach ( $data['term_meta'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$term_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$key     = isset( $entry['key'] ) ? (string) $entry['key'] : '';

			if ( $term_id <= 0 || ! isset( $allowed[ $key ] ) || ! get_term( $term_id ) instanceof WP_Term ) {
				continue;
			}

			update_term_meta( $term_id, $key, wp_slash( erankly_sanitize_registered_meta( $entry['value'] ?? '', $key ) ) );
			++$counts['term_meta'];
		}
	}

	return $counts;
}

/**
 * Normalizes an exported redirect row into repository-ready data.
 *
 * @param array<string,mixed> $row Redirect row from the export file.
 * @return array<string,mixed>|null
 */
function erankly_import_prepare_redirect( array $row ): ?array {
	$is_wildcard = ! empty( $row['is_wildcard'] ) ? 1 : 0;
	$is_regex    = ( ! $is_wildcard && ! empty( $row['is_regex'] ) ) ? 1 : 0;

	$source_path = isset( $row['source_path'] )
		? ERankly_Redirects_Normalizer::normalize_source( sanitize_text_field( (string) $row['source_path'] ), (bool) $is_regex, (bool) $is_wildcard )
		: '';

	$status_code = isset( $row['status_code'] ) ? absint( $row['status_code'] ) : 301;

	if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
		$status_code = 301;
	}

	$is_status_only = ERankly_Redirects_Normalizer::is_status_only_code( $status_code );
	$target_url     = isset( $row['target_url'] )
		? ERankly_Redirects_Normalizer::normalize_target_url( (string) $row['target_url'] )
		: '';

	if ( '' === $source_path || ( ! $is_status_only && '' === $target_url ) ) {
		return null;
	}

	$visibility = isset( $row['visibility'] ) ? sanitize_key( (string) $row['visibility'] ) : 'all';

	if ( ! in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ) {
		$visibility = 'all';
	}

	return array(
		'source_path'   => $source_path,
		'source_hash'   => ERankly_Redirects_Normalizer::source_hash( $source_path ),
		'target_url'    => $target_url,
		'status_code'   => $status_code,
		'is_regex'      => $is_regex,
		'is_wildcard'   => $is_wildcard,
		'is_active'     => ! empty( $row['is_active'] ) ? 1 : 0,
		'visibility'    => $visibility,
		'required_role' => isset( $row['required_role'] ) ? sanitize_key( (string) $row['required_role'] ) : '',
		'note'          => isset( $row['note'] ) ? sanitize_textarea_field( (string) $row['note'] ) : '',
	);
}

/**
 * Imports useful per-content SEO data from a third-party plugin.
 *
 * Existing EasyRankly values are never overwritten, so the import only fills in
 * fields that are currently empty.
 *
 * @param string $source Source plugin: yoast|rankmath|aioseo.
 * @return array{post_meta:int,term_meta:int}
 */
function erankly_import_third_party( string $source ): array {
	$counts = array(
		'post_meta' => 0,
		'term_meta' => 0,
	);

	if ( 'aioseo' === $source ) {
		erankly_import_aioseo_posts( $counts );
		erankly_import_aioseo_terms( $counts );

		return $counts;
	}

	erankly_import_third_party_posts( $source, $counts );

	if ( 'yoast' === $source ) {
		erankly_import_yoast_terms( $counts );
	} else {
		erankly_import_rankmath_terms( $counts );
	}

	return $counts;
}

/**
 * Imports post meta from a third-party plugin.
 *
 * @param string                             $source Source plugin: yoast|rankmath.
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_third_party_posts( string $source, array &$counts ): void {
	global $wpdb;

	$source_keys = erankly_third_party_source_keys( $source );

	if ( empty( $source_keys ) ) {
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $source_keys ), '%s' ) );
	$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party post meta rows for migration.
		$wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$source_keys
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	$by_post = array();

	foreach ( $rows as $row ) {
		$by_post[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
	}

	foreach ( $by_post as $post_id => $meta ) {
		if ( ! get_post( $post_id ) ) {
			continue;
		}

		$mapped = 'yoast' === $source
			? erankly_map_yoast_meta( $meta )
			: erankly_map_rankmath_meta( $meta );

		$counts['post_meta'] += erankly_apply_imported_meta( 'post', $post_id, $mapped );
	}
}

/**
 * Imports Yoast term SEO from the wpseo_taxonomy_meta option.
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_yoast_terms( array &$counts ): void {
	$taxonomy_meta = get_option( 'wpseo_taxonomy_meta' );

	if ( ! is_array( $taxonomy_meta ) ) {
		return;
	}

	foreach ( $taxonomy_meta as $terms ) {
		if ( ! is_array( $terms ) ) {
			continue;
		}

		foreach ( $terms as $term_id => $meta ) {
			$term_id = absint( $term_id );

			if ( $term_id <= 0 || ! is_array( $meta ) || ! get_term( $term_id ) instanceof WP_Term ) {
				continue;
			}

			$mapped               = erankly_map_yoast_meta( $meta, true );
			$counts['term_meta'] += erankly_apply_imported_meta( 'term', $term_id, $mapped );
		}
	}
}

/**
 * Imports Rank Math term SEO from term meta.
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_rankmath_terms( array &$counts ): void {
	global $wpdb;

	$source_keys = erankly_third_party_source_keys( 'rankmath' );

	if ( empty( $source_keys ) ) {
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $source_keys ), '%s' ) );
	$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party term meta rows for migration.
		$wpdb->prepare(
			"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$source_keys
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	$by_term = array();

	foreach ( $rows as $row ) {
		$by_term[ (int) $row['term_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
	}

	foreach ( $by_term as $term_id => $meta ) {
		if ( ! get_term( $term_id ) instanceof WP_Term ) {
			continue;
		}

		$mapped               = erankly_map_rankmath_meta( $meta );
		$counts['term_meta'] += erankly_apply_imported_meta( 'term', $term_id, $mapped );
	}
}

/**
 * Returns whether a custom database table exists.
 *
 * @param string $table Fully-qualified table name (including prefix).
 * @return bool
 */
function erankly_table_exists( string $table ): bool {
	global $wpdb;

	// esc_like(): underscores in the table name are LIKE wildcards otherwise, which
	// could match a differently-named table and skew the exact comparison below.
	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence check for an optional third-party table.
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
	);

	return $found === $table;
}

/**
 * Imports All in One SEO post data from the wp_aioseo_posts custom table.
 *
 * Unlike Yoast and Rank Math, AIOSEO v4+ stores per-post SEO data in its own
 * table rather than in postmeta.
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_aioseo_posts( array &$counts ): void {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'aioseo_posts' );

	if ( ! erankly_table_exists( $table ) ) {
		return;
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party rows for migration.
		"SELECT post_id, title, description, canonical_url, og_title, og_description, og_image_custom_url, twitter_title, twitter_description, robots_default, robots_noindex, robots_nofollow, robots_noarchive FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted $wpdb prefix.
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			continue;
		}

		$mapped               = erankly_map_aioseo_meta( $row );
		$counts['post_meta'] += erankly_apply_imported_meta( 'post', $post_id, $mapped );
	}
}

/**
 * Imports All in One SEO term data from the wp_aioseo_terms custom table.
 *
 * The terms table only exists on recent AIOSEO versions, so its absence is
 * treated as "nothing to import".
 *
 * @param array{post_meta:int,term_meta:int} $counts Running counts (by reference).
 * @return void
 */
function erankly_import_aioseo_terms( array &$counts ): void {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'aioseo_terms' );

	if ( ! erankly_table_exists( $table ) ) {
		return;
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Importer needs third-party rows for migration.
		"SELECT term_id, title, description, canonical_url, og_title, og_description, og_image_custom_url, twitter_title, twitter_description, robots_default, robots_noindex, robots_nofollow, robots_noarchive FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted $wpdb prefix.
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$term_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

		if ( $term_id <= 0 || ! get_term( $term_id ) instanceof WP_Term ) {
			continue;
		}

		$mapped               = erankly_map_aioseo_meta( $row );
		$counts['term_meta'] += erankly_apply_imported_meta( 'term', $term_id, $mapped );
	}
}

/**
 * Maps an All in One SEO post/term row to EasyRankly meta.
 *
 * @param array<string,mixed> $row Source row from wp_aioseo_posts/wp_aioseo_terms.
 * @return array<string,mixed>
 */
function erankly_map_aioseo_meta( array $row ): array {
	$get = static function ( string $key ) use ( $row ): string {
		return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	};

	$mapped = array(
		'_erankly_title'               => erankly_import_convert_variables( $get( 'title' ), 'aioseo' ),
		'_erankly_description'         => erankly_import_convert_variables( $get( 'description' ), 'aioseo' ),
		'_erankly_canonical'           => $get( 'canonical_url' ),
		'_erankly_og_title'            => erankly_import_convert_variables( $get( 'og_title' ), 'aioseo' ),
		'_erankly_og_description'      => erankly_import_convert_variables( $get( 'og_description' ), 'aioseo' ),
		'_erankly_social_image_url'    => $get( 'og_image_custom_url' ),
		'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'twitter_title' ), 'aioseo' ),
		'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'twitter_description' ), 'aioseo' ),
	);

	// robots_default = 1 means "use the site defaults", so the per-row flags are
	// only meaningful when the post/term has its own custom robots settings.
	if ( '1' !== $get( 'robots_default' ) ) {
		if ( '1' === $get( 'robots_noindex' ) ) {
			$mapped['_erankly_noindex'] = true;
		}

		if ( '1' === $get( 'robots_nofollow' ) ) {
			$mapped['_erankly_nofollow'] = true;
		}

		if ( '1' === $get( 'robots_noarchive' ) ) {
			$mapped['_erankly_noarchive'] = true;
		}
	}

	return $mapped;
}

/**
 * Writes mapped meta values without overwriting existing EasyRankly data.
 *
 * @param string              $object_type 'post' or 'term'.
 * @param int                 $object_id   Object ID.
 * @param array<string,mixed> $mapped      EasyRankly meta key => value.
 * @return int Number of fields written.
 */
function erankly_apply_imported_meta( string $object_type, int $object_id, array $mapped ): int {
	$written = 0;

	foreach ( $mapped as $key => $value ) {
		// Skip empty strings, nulls, and zero image IDs; keep boolean true flags.
		if ( true !== $value && empty( $value ) ) {
			continue;
		}

		$existing = 'post' === $object_type
			? get_post_meta( $object_id, $key, true )
			: get_term_meta( $object_id, $key, true );

		if ( '' !== $existing && null !== $existing && false !== $existing ) {
			continue;
		}

		$clean = erankly_sanitize_registered_meta( $value, $key );

		if ( '' === $clean || false === $clean ) {
			continue;
		}

		// wp_slash(): update_*_meta() unslashes its input, which would strip
		// literal backslashes from the migrated value.
		if ( 'post' === $object_type ) {
			update_post_meta( $object_id, $key, wp_slash( $clean ) );
		} else {
			update_term_meta( $object_id, $key, wp_slash( $clean ) );
		}

		++$written;
	}

	return $written;
}

/**
 * Returns the source meta keys read from a third-party plugin.
 *
 * @param string $source Source plugin: yoast|rankmath.
 * @return array<int,string>
 */
function erankly_third_party_source_keys( string $source ): array {
	if ( 'yoast' === $source ) {
		return array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_bctitle',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_meta-robots-adv',
		);
	}

	return array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_canonical_url',
		'rank_math_breadcrumb_title',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_facebook_image',
		'rank_math_facebook_image_id',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
		'rank_math_twitter_image_id',
		'rank_math_robots',
	);
}

/**
 * Maps Yoast meta (post meta keys or wpseo_taxonomy_meta keys) to EasyRankly meta.
 *
 * @param array<string,mixed> $meta    Source meta.
 * @param bool                $is_term Whether the keys use the wpseo_taxonomy_meta short form.
 * @return array<string,mixed>
 */
function erankly_map_yoast_meta( array $meta, bool $is_term = false ): array {
	// Term meta in wpseo_taxonomy_meta uses short keys (wpseo_title); post meta
	// uses the full prefix (_yoast_wpseo_title). Normalize to the short form.
	$prefix = $is_term ? 'wpseo_' : '_yoast_wpseo_';
	$get    = static function ( string $key ) use ( $meta, $prefix ): string {
		return isset( $meta[ $prefix . $key ] ) ? (string) $meta[ $prefix . $key ] : '';
	};

	$mapped = array(
		'_erankly_title'               => erankly_import_convert_variables( $get( 'title' ), 'yoast' ),
		'_erankly_description'         => erankly_import_convert_variables( $is_term ? $get( 'desc' ) : $get( 'metadesc' ), 'yoast' ),
		'_erankly_canonical'           => $get( 'canonical' ),
		'_erankly_breadcrumb_name'     => $get( 'bctitle' ),
		'_erankly_og_title'            => erankly_import_convert_variables( $get( 'opengraph-title' ), 'yoast' ),
		'_erankly_og_description'      => erankly_import_convert_variables( $get( 'opengraph-description' ), 'yoast' ),
		'_erankly_social_image_url'    => $get( 'opengraph-image' ),
		'_erankly_og_image_id'         => absint( $get( 'opengraph-image-id' ) ),
		'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'twitter-title' ), 'yoast' ),
		'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'twitter-description' ), 'yoast' ),
	);

	// Robots: Yoast stores "1" for noindex and "1" for nofollow; the advanced
	// field is a comma list that may contain "noarchive".
	if ( '1' === $get( 'meta-robots-noindex' ) || 'noindex' === $get( 'noindex' ) ) {
		$mapped['_erankly_noindex'] = true;
	}

	if ( '1' === $get( 'meta-robots-nofollow' ) ) {
		$mapped['_erankly_nofollow'] = true;
	}

	if ( false !== strpos( $get( 'meta-robots-adv' ), 'noarchive' ) ) {
		$mapped['_erankly_noarchive'] = true;
	}

	return $mapped;
}

/**
 * Maps Rank Math post/term meta to EasyRankly meta.
 *
 * @param array<string,mixed> $meta Source meta.
 * @return array<string,mixed>
 */
function erankly_map_rankmath_meta( array $meta ): array {
	$get = static function ( string $key ) use ( $meta ): string {
		return isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
	};

	$mapped = array(
		'_erankly_title'               => erankly_import_convert_variables( $get( 'rank_math_title' ), 'rankmath' ),
		'_erankly_description'         => erankly_import_convert_variables( $get( 'rank_math_description' ), 'rankmath' ),
		'_erankly_canonical'           => $get( 'rank_math_canonical_url' ),
		'_erankly_breadcrumb_name'     => $get( 'rank_math_breadcrumb_title' ),
		'_erankly_og_title'            => erankly_import_convert_variables( $get( 'rank_math_facebook_title' ), 'rankmath' ),
		'_erankly_og_description'      => erankly_import_convert_variables( $get( 'rank_math_facebook_description' ), 'rankmath' ),
		'_erankly_social_image_url'    => $get( 'rank_math_facebook_image' ),
		'_erankly_og_image_id'         => absint( $get( 'rank_math_facebook_image_id' ) ),
		'_erankly_twitter_title'       => erankly_import_convert_variables( $get( 'rank_math_twitter_title' ), 'rankmath' ),
		'_erankly_twitter_description' => erankly_import_convert_variables( $get( 'rank_math_twitter_description' ), 'rankmath' ),
		'_erankly_twitter_image_id'    => absint( $get( 'rank_math_twitter_image_id' ) ),
	);

	// Robots is a serialized array such as ["noindex","nofollow","noarchive"].
	$robots = maybe_unserialize( $get( 'rank_math_robots' ) );

	if ( is_array( $robots ) ) {
		if ( in_array( 'noindex', $robots, true ) ) {
			$mapped['_erankly_noindex'] = true;
		}

		if ( in_array( 'nofollow', $robots, true ) ) {
			$mapped['_erankly_nofollow'] = true;
		}

		if ( in_array( 'noarchive', $robots, true ) ) {
			$mapped['_erankly_noarchive'] = true;
		}
	}

	return $mapped;
}

/**
 * Converts third-party template variables to EasyRankly's {{token}} syntax.
 *
 * Known variables are mapped to their EasyRankly equivalents; unknown variables
 * are stripped so imported templates never render raw placeholders.
 *
 * @param string $value  Raw template string.
 * @param string $source Source plugin: yoast|rankmath|aioseo.
 * @return string
 */
function erankly_import_convert_variables( string $value, string $source ): string {
	$value = (string) $value;

	if ( '' === $value ) {
		return '';
	}

	$map = array(
		'title'            => '{{post_title}}',
		'sitename'         => '{{site_name}}',
		'site_name'        => '{{site_name}}',
		'excerpt'          => '{{post_excerpt}}',
		'excerpt_only'     => '{{post_excerpt}}',
		'sep'              => '-',
		'separator_sa'     => '-',
		'page'             => '',
		'pagenumber'       => '',
		'pagetotal'        => '',
		'primary_category' => '{{post_categories}}',
		'category'         => '{{post_categories}}',
		'term'             => '{{term_name}}',
		'term_title'       => '{{term_name}}',
		'term_description' => '{{term_description}}',
		'name'             => '{{post_author}}',
		'date'             => '{{post_date}}',
		'modified'         => '{{post_modified_date}}',
		'currentyear'      => gmdate( 'Y' ),
		// All in One SEO (#tag) aliases.
		'post_title'       => '{{post_title}}',
		'site_title'       => '{{site_name}}',
		'post_excerpt'     => '{{post_excerpt}}',
		'categories'       => '{{post_categories}}',
		'tax_name'         => '{{term_name}}',
		'taxonomy_title'   => '{{term_name}}',
		'author_name'      => '{{post_author}}',
		'post_date'        => '{{post_date}}',
		'post_year'        => '{{post_date}}',
		'current_year'     => gmdate( 'Y' ),
	);

	switch ( $source ) {
		case 'yoast':
			$pattern = '/%%([^%]+)%%/';
			break;
		case 'aioseo':
			$pattern = '/#([a-z0-9_]+)/i';
			break;
		default:
			$pattern = '/%([^%\s]+)%/';
			break;
	}
	$replaced = preg_replace_callback(
		$pattern,
		static function ( array $matches ) use ( $map ): string {
			// Rank Math allows arguments, e.g. %customfield(key)% — drop them.
			$name = strtolower( trim( explode( '(', $matches[1] )[0] ) );

			return $map[ $name ] ?? '';
		},
		$value
	);

	$replaced = is_string( $replaced ) ? $replaced : $value;

	// Collapse whitespace and trim stray separators left by removed variables.
	$replaced = preg_replace( '/\s{2,}/', ' ', $replaced ) ?? $replaced;
	$replaced = trim( $replaced );
	$replaced = trim( $replaced, ' -|' );

	return trim( $replaced );
}

/**
 * Renders the Import / Export settings tab.
 *
 * @return void
 */
function erankly_import_export_render_panel(): void {
	// On Multisite the settings option is a network option; mirror the write-access gate.
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	$export_url = wp_nonce_url( add_query_arg( 'erankly_io_action', 'export', erankly_import_export_url() ), 'erankly_io_export' );
	$action_url = erankly_import_export_url();

	// Third-party import sources, in the order they appear in the dropdown.
	$sources = array(
		'yoast'    => __( 'Yoast SEO', 'easyrankly' ),
		'rankmath' => __( 'Rank Math', 'easyrankly' ),
		'aioseo'   => __( 'All in One SEO', 'easyrankly' ),
	);

	$source_availability = array();
	$default_source      = '';

	foreach ( $sources as $key => $label ) {
		$available                   = erankly_third_party_data_exists( $key );
		$source_availability[ $key ] = $available;
		if ( $available && '' === $default_source ) {
			$default_source = $key;
		}
	}

	$has_any_source = '' !== $default_source;

	erankly_import_export_render_notice();
	?>
	<div class="erankly-io">
		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Export', 'easyrankly' ); ?></h3>
			<section class="erankly-io-section erankly-card">
				<p class="description"><?php esc_html_e( 'Download a JSON backup of your EasyRankly settings, redirects and SEO metadata. Keep it as a backup or import it on another site.', 'easyrankly' ); ?></p>
				<?php if ( is_multisite() ) : ?>
					<p class="description"><?php esc_html_e( 'On this network the file holds the network-wide settings plus this primary site\'s content (redirects, post/term metadata, special page defaults) — not a whole-network export of every site.', 'easyrankly' ); ?></p>
				<?php endif; ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export data', 'easyrankly' ); ?></a></p>
			</section>
		</div>

		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Import', 'easyrankly' ); ?></h3>
			<section class="erankly-io-section erankly-card">
				<p class="description"><?php esc_html_e( 'Upload a JSON file previously exported by EasyRankly. Settings, redirects and special page defaults are replaced; post and term metadata is matched by ID and overwritten.', 'easyrankly' ); ?></p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form">
					<?php wp_nonce_field( 'erankly_io_import' ); ?>
					<input type="hidden" name="erankly_io_action" value="import">
					<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-import-file">
						<input type="file" id="erankly-import-file" name="erankly_import_file" accept=".json,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
						<span class="erankly-dropzone-icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 15.5V4M12 4L8 8M12 4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M4 15.5v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
						<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
							<strong><?php esc_html_e( 'Click to choose a file', 'easyrankly' ); ?></strong>
							<?php esc_html_e( 'or drag and drop a JSON file here', 'easyrankly' ); ?>
						</span>
					</label>
					<?php submit_button( __( 'Import file', 'easyrankly' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		</div>

		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Import from other plugins', 'easyrankly' ); ?></h3>
			<section class="erankly-io-section erankly-card">
				<p class="description"><?php esc_html_e( 'Copy SEO metadata (titles, descriptions, canonical URLs, social tags, robots flags) from another plugin into EasyRankly. Existing EasyRankly values are never overwritten.', 'easyrankly' ); ?></p>

			<?php if ( $has_any_source ) : ?>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="erankly-io-third-party">
					<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
					<label class="screen-reader-text" for="erankly-io-source"><?php esc_html_e( 'Source plugin', 'easyrankly' ); ?></label>
					<select name="erankly_io_action" id="erankly-io-source">
						<?php foreach ( $sources as $key => $label ) : ?>
							<?php if ( $source_availability[ $key ] ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default_source ); ?>><?php echo esc_html( $label ); ?></option>
							<?php else : ?>
								<option value="<?php echo esc_attr( $key ); ?>" disabled>
									<?php
									/* translators: %s: source plugin name. */
									echo esc_html( sprintf( __( '%s: no data found', 'easyrankly' ), $label ) );
									?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Import', 'easyrankly' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No data found from Yoast SEO, Rank Math or All in One SEO.', 'easyrankly' ); ?></p>
			<?php endif; ?>
			</section>
		</div>
	</div>
	<?php
}

/**
 * Renders the import/export admin notice for the current request.
 *
 * @return void
 */
function erankly_import_export_render_notice(): void {
	$notice = isset( $_GET['erankly_io_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_io_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $notice ) {
		return;
	}

	if ( 'nonce' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed. Please try again.', 'easyrankly' ) . '</p></div>';
		return;
	}

	if ( 'invalid' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The file could not be imported. Please upload a valid EasyRankly export file.', 'easyrankly' ) . '</p></div>';
		return;
	}

	$post_meta = isset( $_GET['er_post_meta'] ) ? absint( $_GET['er_post_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$term_meta = isset( $_GET['er_term_meta'] ) ? absint( $_GET['er_term_meta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'imported' === $notice ) {
		$settings  = isset( $_GET['er_settings'] ) ? absint( $_GET['er_settings'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirects = isset( $_GET['er_redirects'] ) ? absint( $_GET['er_redirects'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message   = sprintf(
			/* translators: 1: settings count, 2: redirects count, 3: post meta count, 4: term meta count. */
			__( 'Import complete. Settings: %1$d. Redirects: %2$d. Post metadata: %3$d. Term metadata: %4$d.', 'easyrankly' ),
			$settings,
			$redirects,
			$post_meta,
			$term_meta
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	$source_labels = array(
		'yoast'    => __( 'Yoast SEO', 'easyrankly' ),
		'rankmath' => __( 'Rank Math', 'easyrankly' ),
		'aioseo'   => __( 'All in One SEO', 'easyrankly' ),
	);

	if ( isset( $source_labels[ $notice ] ) ) {
		$label   = $source_labels[ $notice ];
		$message = sprintf(
			/* translators: 1: source plugin name, 2: post meta count, 3: term meta count. */
			__( 'Imported from %1$s. Post metadata: %2$d. Term metadata: %3$d.', 'easyrankly' ),
			$label,
			$post_meta,
			$term_meta
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}

/**
 * Returns whether importable data from a third-party plugin exists.
 *
 * @param string $source Source plugin: yoast|rankmath|aioseo.
 * @return bool
 */
function erankly_third_party_data_exists( string $source ): bool {
	global $wpdb;

	if ( 'yoast' === $source && is_array( get_option( 'wpseo_taxonomy_meta' ) ) ) {
		return true;
	}

	// AIOSEO v4+ keeps its data in custom tables rather than postmeta.
	if ( 'aioseo' === $source ) {
		$table = esc_sql( $wpdb->prefix . 'aioseo_posts' );

		if ( ! erankly_table_exists( $table ) ) {
			return false;
		}

		return null !== $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Presence check; table name from trusted $wpdb prefix.
	}

	$source_keys  = erankly_third_party_source_keys( $source );
	$placeholders = implode( ', ', array_fill( 0, count( $source_keys ), '%s' ) );
	$found        = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight presence check for importer availability.
		$wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$source_keys
		)
	);

	return null !== $found;
}
