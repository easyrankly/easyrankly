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
	return add_query_arg(
		array(
			'page'        => 'erankly',
			'erankly_tab' => 'import-export',
		),
		admin_url( 'options-general.php' )
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

	if ( 'yoast' === $action ) {
		erankly_import_export_handle_third_party( 'yoast' );
	}

	if ( 'rankmath' === $action ) {
		erankly_import_export_handle_third_party( 'rankmath' );
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

	$plugin_id = is_array( $data ) && isset( $data['plugin'] ) && is_string( $data['plugin'] )
		? sanitize_key( $data['plugin'] )
		: '';

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || ! in_array( $plugin_id, array( 'erankly', 'easyrankly' ), true ) ) {
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
 * @param string $source Source plugin: yoast|rankmath.
 * @return void
 */
function erankly_import_export_handle_third_party( string $source ): void {
	check_admin_referer( 'erankly_io_' . $source );

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

// Export.

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

// Import (EasyRankly format).

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
	$target_url  = isset( $row['target_url'] )
		? ERankly_Redirects_Normalizer::normalize_target_url( (string) $row['target_url'] )
		: '';

	if ( '' === $source_path || '' === $target_url ) {
		return null;
	}

	$visibility = isset( $row['visibility'] ) ? sanitize_key( (string) $row['visibility'] ) : 'all';

	if ( ! in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ) {
		$visibility = 'all';
	}

	$status_code = isset( $row['status_code'] ) ? absint( $row['status_code'] ) : 301;

	if ( ! ERankly_Redirects_Normalizer::is_valid_status_code( $status_code ) ) {
		$status_code = 301;
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

// Import (Yoast SEO / Rank Math).

/**
 * Imports useful per-content SEO data from a third-party plugin.
 *
 * Existing EasyRankly values are never overwritten, so the import only fills in
 * fields that are currently empty.
 *
 * @param string $source Source plugin: yoast|rankmath.
 * @return array{post_meta:int,term_meta:int}
 */
function erankly_import_third_party( string $source ): array {
	$counts = array(
		'post_meta' => 0,
		'term_meta' => 0,
	);

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
 * @param string $source Source plugin: yoast|rankmath.
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
	);

	$pattern  = 'yoast' === $source ? '/%%([^%]+)%%/' : '/%([^%\s]+)%/';
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

// Admin UI.

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

	$export_url   = wp_nonce_url( add_query_arg( 'erankly_io_action', 'export', erankly_import_export_url() ), 'erankly_io_export' );
	$has_yoast    = erankly_third_party_data_exists( 'yoast' );
	$has_rankmath = erankly_third_party_data_exists( 'rankmath' );
	$action_url   = erankly_import_export_url();

	erankly_import_export_render_notice();
	?>
	<div class="erankly-io">
		<section class="erankly-io-section">
			<h3><?php esc_html_e( 'Export', 'easyrankly' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Download a single JSON file containing all EasyRankly data: settings, redirects, and the SEO metadata for posts and terms. Keep it as a backup or import it on another site.', 'easyrankly' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export all data', 'easyrankly' ); ?></a></p>
		</section>

		<section class="erankly-io-section">
			<h3><?php esc_html_e( 'Import', 'easyrankly' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Upload a JSON file previously exported by EasyRankly. Settings and redirects are replaced; post and term metadata is matched by ID and overwritten.', 'easyrankly' ); ?></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form">
				<?php wp_nonce_field( 'erankly_io_import' ); ?>
				<input type="hidden" name="erankly_io_action" value="import">
				<input type="file" name="erankly_import_file" accept=".json,application/json" required>
				<?php submit_button( __( 'Import file', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>

		<section class="erankly-io-section">
			<h3><?php esc_html_e( 'Import from other plugins', 'easyrankly' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Copy the useful SEO metadata (titles, descriptions, canonical URLs, social tags, robots flags and breadcrumb labels) from another plugin into EasyRankly. Existing EasyRankly values are never overwritten, and irrelevant data is ignored.', 'easyrankly' ); ?></p>

			<div class="erankly-io-third-party">
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php wp_nonce_field( 'erankly_io_yoast' ); ?>
					<input type="hidden" name="erankly_io_action" value="yoast">
					<strong><?php esc_html_e( 'Yoast SEO', 'easyrankly' ); ?></strong>
					<?php if ( $has_yoast ) : ?>
						<?php submit_button( __( 'Import from Yoast SEO', 'easyrankly' ), 'secondary', 'submit', false ); ?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No Yoast SEO data found.', 'easyrankly' ); ?></p>
					<?php endif; ?>
				</form>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php wp_nonce_field( 'erankly_io_rankmath' ); ?>
					<input type="hidden" name="erankly_io_action" value="rankmath">
					<strong><?php esc_html_e( 'Rank Math', 'easyrankly' ); ?></strong>
					<?php if ( $has_rankmath ) : ?>
						<?php submit_button( __( 'Import from Rank Math', 'easyrankly' ), 'secondary', 'submit', false ); ?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No Rank Math data found.', 'easyrankly' ); ?></p>
					<?php endif; ?>
				</form>
			</div>
		</section>
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

	if ( 'yoast' === $notice || 'rankmath' === $notice ) {
		$label   = 'yoast' === $notice ? __( 'Yoast SEO', 'easyrankly' ) : __( 'Rank Math', 'easyrankly' );
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
 * @param string $source Source plugin: yoast|rankmath.
 * @return bool
 */
function erankly_third_party_data_exists( string $source ): bool {
	global $wpdb;

	if ( 'yoast' === $source && is_array( get_option( 'wpseo_taxonomy_meta' ) ) ) {
		return true;
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
