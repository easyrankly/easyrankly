<?php
/**
 * Public, allowlisted writer for localized EasyRankly source values.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the public localized-source contract definition for one key.
 *
 * This deliberately closed registry is not filterable. Dynamic post-type and
 * taxonomy keys are accepted only when WordPress reports the subtype public.
 *
 * @param string $key Public localized-source key.
 * @return array{key:string,path:array<int,string>,format:string,max_length:int}|WP_Error
 */
function erankly_get_localized_value_source_definition( string $key ): array|WP_Error {
	$key = sanitize_key( $key );
	erankly_load_default_helpers();

	$definitions = array(
		'home_seo_title'             => array( 'global_special_meta', 'homepage', 'title' ),
		'home_seo_description'       => array( 'global_special_meta', 'homepage', 'description' ),
		'posts_page_seo_title'       => array( 'global_special_meta', 'blog', 'title' ),
		'posts_page_seo_description' => array( 'global_special_meta', 'blog', 'description' ),
		'search_seo_title'           => array( 'global_special_meta', 'search', 'title' ),
		'search_seo_description'     => array( 'global_special_meta', 'search', 'description' ),
		'not_found_seo_title'        => array( 'global_special_meta', '404', 'title' ),
		'not_found_seo_description'  => array( 'global_special_meta', '404', 'description' ),
		'archive_seo_title'          => array( 'global_special_meta', 'author', 'title' ),
		'archive_seo_description'    => array( 'global_special_meta', 'author', 'description' ),
		'organization_name'          => array( 'organization_name' ),
		'organization_description'   => array( 'organization_description' ),
		'website_name'               => array( 'website_name' ),
		'website_description'        => array( 'website_description' ),
	);

	$path = $definitions[ $key ] ?? array();

	if ( ! $path && str_starts_with( $key, 'seo_title_post_' ) ) {
		$post_type = sanitize_key( substr( $key, strlen( 'seo_title_post_' ) ) );
		if ( isset( erankly_get_public_post_types()[ $post_type ] ) ) {
			$path = array( 'global_post_type_meta', $post_type, 'title' );
		}
	} elseif ( ! $path && str_starts_with( $key, 'seo_description_post_' ) ) {
		$post_type = sanitize_key( substr( $key, strlen( 'seo_description_post_' ) ) );
		if ( isset( erankly_get_public_post_types()[ $post_type ] ) ) {
			$path = array( 'global_post_type_meta', $post_type, 'description' );
		}
	} elseif ( ! $path && str_starts_with( $key, 'seo_title_tax_' ) ) {
		$taxonomy = sanitize_key( substr( $key, strlen( 'seo_title_tax_' ) ) );
		if ( isset( erankly_get_public_taxonomies()[ $taxonomy ] ) ) {
			$path = array( 'global_taxonomy_meta', $taxonomy, 'title' );
		}
	} elseif ( ! $path && str_starts_with( $key, 'seo_description_tax_' ) ) {
		$taxonomy = sanitize_key( substr( $key, strlen( 'seo_description_tax_' ) ) );
		if ( isset( erankly_get_public_taxonomies()[ $taxonomy ] ) ) {
			$path = array( 'global_taxonomy_meta', $taxonomy, 'description' );
		}
	}

	if ( ! $path ) {
		return erankly_localized_value_source_error(
			'erankly_localized_value_source_key_unregistered',
			__( 'The requested localized EasyRankly source key is not registered.', 'easyrankly' ),
			422,
			array( 'key' => $key )
		);
	}

	$format = str_ends_with( $key, '_description' ) || str_contains( $key, '_description_' ) ? 'textarea' : 'text';

	return array(
		'key'        => $key,
		'path'       => $path,
		'format'     => $format,
		'max_length' => 'text' === $format ? 2048 : 65535,
	);
}

/**
 * Returns the current canonical value and a fingerprint suitable for CAS.
 *
 * The value is intentionally present in a successful state response because a
 * migration must materialize it as the former language override. Error payloads
 * never contain the value.
 *
 * @param string $key Public localized-source key.
 * @return array{contract:string,key:string,value:string,value_hash:string,fingerprint:string,format:string}|WP_Error
 */
function erankly_get_localized_value_source_state( string $key ): array|WP_Error {
	$authorized = erankly_localized_value_source_authorized();
	if ( is_wp_error( $authorized ) ) {
		return $authorized;
	}

	$definition = erankly_get_localized_value_source_definition( $key );
	if ( is_wp_error( $definition ) ) {
		return $definition;
	}

	erankly_localized_value_source_refresh_settings();

	return erankly_localized_value_source_state_from_definition( $definition );
}

/**
 * Writes one registered source value with fingerprint CAS and verification.
 *
 * Repeating a completed write or restore is idempotent even when its expected
 * fingerprint is now stale. A different desired value must match the current
 * fingerprint. The shared settings mutex serializes this operation with every
 * other EasyRankly settings writer.
 *
 * @param string $key                  Public localized-source key.
 * @param mixed  $value                Already-unslashed source value.
 * @param string $expected_fingerprint Fingerprint returned by the read API.
 * @return array{contract:string,key:string,value:string,value_hash:string,fingerprint:string,format:string,changed:bool,idempotent:bool}|WP_Error
 */
function erankly_update_localized_value_source( string $key, mixed $value, string $expected_fingerprint ): array|WP_Error {
	$authorized = erankly_localized_value_source_authorized();
	if ( is_wp_error( $authorized ) ) {
		return $authorized;
	}

	$definition = erankly_get_localized_value_source_definition( $key );
	if ( is_wp_error( $definition ) ) {
		return $definition;
	}

	if ( 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $expected_fingerprint ) ) {
		return erankly_localized_value_source_error(
			'erankly_localized_value_source_fingerprint_invalid',
			__( 'A valid expected localized-source fingerprint is required.', 'easyrankly' ),
			422,
			array( 'key' => (string) $definition['key'] )
		);
	}

	$lock = erankly_ml_acquire_ownership_lock();
	if ( is_wp_error( $lock ) ) {
		return erankly_localized_value_source_error(
			'erankly_localized_value_source_locked',
			__( 'The localized EasyRankly source writer is currently locked.', 'easyrankly' ),
			423,
			array(
				'key'       => (string) $definition['key'],
				'cause'     => sanitize_key( $lock->get_error_code() ),
				'retryable' => true,
			)
		);
	}

	try {
		erankly_localized_value_source_refresh_settings();
		$current = erankly_localized_value_source_state_from_definition( $definition );
		$desired = erankly_localized_value_source_sanitize_candidate( $definition, $value );
		if ( is_wp_error( $desired ) ) {
			return $desired;
		}

		if ( hash_equals( (string) $current['value_hash'], hash( 'sha256', $desired ) ) ) {
			return array_merge(
				$current,
				array(
					'changed'    => false,
					'idempotent' => true,
				)
			);
		}

		if ( ! hash_equals( (string) $current['fingerprint'], $expected_fingerprint ) ) {
			return erankly_localized_value_source_error(
				'erankly_localized_value_source_revision_conflict',
				__( 'The localized EasyRankly source changed after it was read.', 'easyrankly' ),
				409,
				array(
					'key'                 => (string) $definition['key'],
					'current_fingerprint' => (string) $current['fingerprint'],
				)
			);
		}

		$settings = erankly_get_settings();
		erankly_localized_value_source_set_path( $settings, (array) $definition['path'], $desired );
		$sanitized = erankly_localized_value_source_sanitize_settings( $settings );
		$root      = (string) $definition['path'][0];
		$result    = erankly_update_plugin_settings( array( $root => $sanitized[ $root ] ?? array() ), $lock );

		if ( is_wp_error( $result ) || true !== $result ) {
			return erankly_localized_value_source_error(
				'erankly_localized_value_source_write_failed',
				__( 'EasyRankly could not persist the localized source value.', 'easyrankly' ),
				503,
				array(
					'key'       => (string) $definition['key'],
					'cause'     => is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : 'settings_update_failed',
					'retryable' => true,
				)
			);
		}

		/**
		 * Fires after storage write and before verification.
		 *
		 * Payloads contain identifiers and fingerprints only, never values.
		 *
		 * @param string $key         Registered public key.
		 * @param string $fingerprint Previous source fingerprint.
		 */
		do_action( 'erankly_localized_value_source_write_checkpoint', (string) $definition['key'], $expected_fingerprint );

		$verified = erankly_localized_value_source_state_from_definition( $definition );
		if ( ! hash_equals( hash( 'sha256', $desired ), (string) $verified['value_hash'] ) ) {
			return erankly_localized_value_source_error(
				'erankly_localized_value_source_verify_failed',
				__( 'EasyRankly could not verify the localized source write.', 'easyrankly' ),
				503,
				array(
					'key'                 => (string) $definition['key'],
					'current_fingerprint' => (string) $verified['fingerprint'],
					'retryable'           => false,
				)
			);
		}

		return array_merge(
			$verified,
			array(
				'changed'    => true,
				'idempotent' => false,
			)
		);
	} finally {
		erankly_ml_release_ownership_lock( $lock );
	}
}

/**
 * Forces the CAS read after lock acquisition to bypass stale request/cache data.
 *
 * The settings option is autoloaded, so deleting only its individual cache key
 * is insufficient. Clearing alloptions and notoptions is required for a
 * long-running process to observe a write completed by another process before
 * it acquired the shared mutex.
 */
function erankly_localized_value_source_refresh_settings(): void {
	erankly_clear_settings_cache();
	wp_cache_delete( ERANKLY_OPTION, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/**
 * Checks the capability and lifecycle context for every public API operation.
 *
 * @return true|WP_Error
 */
function erankly_localized_value_source_authorized(): bool|WP_Error {
	$context_allowed = is_admin()
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST );

	if ( is_multisite() ) {
		return erankly_localized_value_source_error(
			'erankly_localized_value_source_topology_unsupported',
			__( 'The localized EasyRankly source writer is available only on Single Site.', 'easyrankly' ),
			409
		);
	}

	if ( ! $context_allowed || ! current_user_can( 'manage_options' ) ) {
		return erankly_localized_value_source_error(
			'erankly_localized_value_source_forbidden',
			__( 'This request cannot access the localized EasyRankly source writer.', 'easyrankly' ),
			403
		);
	}

	return true;
}

/**
 * Builds one source state without re-running public authorization.
 *
 * @param array{key:string,path:array<int,string>,format:string,max_length:int} $definition Definition.
 * @return array{contract:string,key:string,value:string,value_hash:string,fingerprint:string,format:string}
 */
function erankly_localized_value_source_state_from_definition( array $definition ): array {
	$value       = erankly_localized_value_source_get_path( erankly_get_settings(), (array) $definition['path'] );
	$value       = is_scalar( $value ) ? (string) $value : '';
	$value_hash  = hash( 'sha256', $value );
	$fingerprint = 'sha256:' . hash(
		'sha256',
		(string) wp_json_encode(
			array(
				'contract'   => 'erankly-localized-source/1',
				'key'        => (string) $definition['key'],
				'value_hash' => $value_hash,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);

	return array(
		'contract'    => 'erankly-localized-source/1',
		'key'         => (string) $definition['key'],
		'value'       => $value,
		'value_hash'  => $value_hash,
		'fingerprint' => $fingerprint,
		'format'      => (string) $definition['format'],
	);
}

/**
 * Sanitizes a candidate through the full core settings rules.
 *
 * A mutation is rejected when sanitization would alter it. Add-on values are
 * already sanitized by their closed registry, while hostile direct calls fail
 * instead of silently changing meaning.
 *
 * @param array{key:string,path:array<int,string>,format:string,max_length:int} $definition Definition.
 * @param mixed                                                                 $value      Candidate.
 * @return string|WP_Error
 */
function erankly_localized_value_source_sanitize_candidate( array $definition, mixed $value ): string|WP_Error {
	if ( ! is_string( $value ) || strlen( $value ) > (int) $definition['max_length'] ) {
		return erankly_localized_value_source_error(
			'erankly_localized_value_source_invalid',
			__( 'The localized EasyRankly source value is invalid.', 'easyrankly' ),
			422,
			array( 'key' => (string) $definition['key'] )
		);
	}

	$settings = erankly_get_settings();
	erankly_localized_value_source_set_path( $settings, (array) $definition['path'], $value );
	$clean = erankly_localized_value_source_sanitize_settings( $settings );
	$clean = erankly_localized_value_source_get_path( $clean, (array) $definition['path'] );
	$clean = is_scalar( $clean ) ? (string) $clean : '';

	if ( ! hash_equals( $value, $clean ) ) {
		return erankly_localized_value_source_error(
			'erankly_localized_value_source_invalid',
			__( 'The localized EasyRankly source value is invalid.', 'easyrankly' ),
			422,
			array( 'key' => (string) $definition['key'] )
		);
	}

	return $clean;
}

/**
 * Loads and applies the canonical settings sanitizer.
 *
 * @param array<string,mixed> $settings Full current settings.
 * @return array<string,mixed>
 */
function erankly_localized_value_source_sanitize_settings( array $settings ): array {
	if ( ! function_exists( 'erankly_sanitize_settings' ) ) {
		require_once ERANKLY_PATH . 'admin/settings-page.php';
	}

	return erankly_sanitize_settings( $settings );
}

/**
 * Returns one nested source value.
 *
 * @param array<string,mixed> $settings Full current settings.
 * @param array<int,string>   $path     Allowlisted path.
 * @return mixed
 */
function erankly_localized_value_source_get_path( array $settings, array $path ): mixed {
	$value = $settings;
	foreach ( $path as $part ) {
		$value = is_array( $value ) ? ( $value[ $part ] ?? '' ) : '';
	}

	return $value;
}

/**
 * Sets one nested source value.
 *
 * @param array<string,mixed> $settings Full current settings.
 * @param array<int,string>   $path     Allowlisted path.
 * @param string              $value    Sanitized value.
 */
function erankly_localized_value_source_set_path( array &$settings, array $path, string $value ): void {
	$target = &$settings;
	$last   = array_pop( $path );
	foreach ( $path as $part ) {
		if ( ! isset( $target[ $part ] ) || ! is_array( $target[ $part ] ) ) {
			$target[ $part ] = array();
		}
		$target = &$target[ $part ];
	}
	$target[ (string) $last ] = $value;
}

/**
 * Returns one bounded machine-readable public error.
 *
 * @param string              $code    Stable code.
 * @param string              $message Safe localized message.
 * @param int                 $status  HTTP status.
 * @param array<string,mixed> $data    Bounded non-sensitive data.
 */
function erankly_localized_value_source_error( string $code, string $message, int $status, array $data = array() ): WP_Error {
	$data['status']    = $status;
	$data['retryable'] = isset( $data['retryable'] ) ? (bool) $data['retryable'] : in_array( $status, array( 423, 503 ), true );

	return new WP_Error( sanitize_key( $code ), $message, $data );
}
