<?php
/**
 * Special-page SEO settings integration.
 *
 * Exposes the per-context defaults through the native WordPress Site Settings
 * entity while preserving the existing storage model.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the special-page map in the native wp/v2/settings endpoint.
 *
 * Core Data exposes settings from this endpoint as the root/site entity. Editing
 * this property therefore participates in the Site Editor's native dirty-state
 * and save flow without attaching SEO data to wp_template records.
 *
 * @return void
 */
function erankly_register_special_meta_setting(): void {
	static $registered = false;

	if ( $registered ) {
		return;
	}

	$registered = true;
	erankly_load_content_helpers();
	erankly_load_default_helpers();

	register_setting(
		'general',
		ERANKLY_SPECIAL_META_OPTION,
		array(
			'type'              => 'object',
			'label'             => __( 'Special pages and archives', 'easyrankly' ),
			'description'       => __( 'Set the default SEO metadata for WordPress contexts that are not individual posts or terms: homepage, blog, author and date archives, search results and the 404 page.', 'easyrankly' ),
			'default'           => erankly_normalize_special_meta_map( erankly_default_global_special_meta() ),
			'sanitize_callback' => 'erankly_sanitize_special_meta_map',
			'show_in_rest'      => array(
				'schema' => erankly_get_special_meta_rest_schema(),
			),
		)
	);

	add_filter( 'rest_pre_get_setting', 'erankly_rest_pre_get_special_meta_setting', 10, 3 );
	add_filter( 'rest_pre_update_setting', 'erankly_rest_pre_update_special_meta_setting', 10, 4 );
}

/**
 * Returns the REST schema for the full special-page map.
 *
 * @return array<string,mixed>
 */
function erankly_get_special_meta_rest_schema(): array {
	$properties = array();

	foreach ( array_keys( erankly_special_page_keys() ) as $context ) {
		$properties[ $context ] = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'title'               => array( 'type' => 'string' ),
				'description'         => array( 'type' => 'string' ),
				'noindex'             => array( 'type' => 'boolean' ),
				'nofollow'            => array( 'type' => 'boolean' ),
				'noarchive'           => array( 'type' => 'boolean' ),
				'disable_sitemap'     => array( 'type' => 'boolean' ),
				'og_title'            => array( 'type' => 'string' ),
				'og_description'      => array( 'type' => 'string' ),
				'twitter_title'       => array( 'type' => 'string' ),
				'twitter_description' => array( 'type' => 'string' ),
				'social_image_url'    => array( 'type' => 'string' ),
				'og_image_id'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		);
	}

	return array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => $properties,
	);
}

/**
 * Normalizes the stored map for the REST API and Core Data.
 *
 * Every supported context is present so edits can update one nested row without
 * reconstructing missing defaults in JavaScript.
 *
 * @return array<string,array<string,mixed>>
 */
function erankly_get_special_meta_rest_value(): array {
	return erankly_normalize_special_meta_map(
		erankly_get_global_entity_meta_map( 'global_special_meta' )
	);
}

/**
 * Normalizes a special-page map to the complete REST field set.
 *
 * @param array<string,mixed> $map Stored or default map.
 * @return array<string,array<string,mixed>>
 */
function erankly_normalize_special_meta_map( array $map ): array {
	$value = array();

	foreach ( array_keys( erankly_special_page_keys() ) as $context ) {
		$row               = isset( $map[ $context ] ) && is_array( $map[ $context ] ) ? $map[ $context ] : array();
		$value[ $context ] = erankly_special_meta_row_defaults( $row );
	}

	return $value;
}

/**
 * Returns the virtual settings value from the plugin's existing storage.
 *
 * @param mixed               $result Preempted value, or null.
 * @param string              $name   REST setting name.
 * @param array<string,mixed> $args   Registered setting arguments.
 * @return mixed
 */
function erankly_rest_pre_get_special_meta_setting( mixed $result, string $name, array $args ): mixed {
	unset( $args );

	if ( ERANKLY_SPECIAL_META_OPTION !== $name ) {
		return $result;
	}

	return erankly_get_special_meta_rest_value();
}

/**
 * Writes the virtual settings property to the plugin's existing storage.
 *
 * @param bool                $updated Whether another callback handled the update.
 * @param string              $name    REST setting name.
 * @param mixed               $value   Submitted setting value.
 * @param array<string,mixed> $args    Registered setting arguments.
 * @return bool
 */
function erankly_rest_pre_update_special_meta_setting( bool $updated, string $name, mixed $value, array $args ): bool {
	unset( $args );

	if ( ERANKLY_SPECIAL_META_OPTION !== $name ) {
		return $updated;
	}

	erankly_update_special_meta_map( is_array( $value ) ? $value : array() );

	return true;
}

/**
 * Sanitizes the full special-page map.
 *
 * @param mixed $value Raw map.
 * @return array<string,array<string,string|int>>
 */
function erankly_sanitize_special_meta_map( mixed $value ): array {
	return erankly_sanitize_global_entity_meta(
		$value,
		array_keys( erankly_special_page_keys() ),
		false,
		true
	);
}

/**
 * Normalizes a stored special-page row to the full typed field set.
 *
 * @param array<string,mixed> $row Stored row.
 * @return array<string,mixed>
 */
function erankly_special_meta_row_defaults( array $row ): array {
	return array(
		'title'               => (string) ( $row['title'] ?? '' ),
		'description'         => (string) ( $row['description'] ?? '' ),
		'noindex'             => ! empty( $row['noindex'] ),
		'nofollow'            => ! empty( $row['nofollow'] ),
		'noarchive'           => ! empty( $row['noarchive'] ),
		'disable_sitemap'     => ! empty( $row['disable_sitemap'] ),
		'og_title'            => (string) ( $row['og_title'] ?? '' ),
		'og_description'      => (string) ( $row['og_description'] ?? '' ),
		'twitter_title'       => (string) ( $row['twitter_title'] ?? '' ),
		'twitter_description' => (string) ( $row['twitter_description'] ?? '' ),
		'social_image_url'    => (string) ( $row['social_image_url'] ?? '' ),
		'og_image_id'         => isset( $row['og_image_id'] ) ? absint( $row['og_image_id'] ) : 0,
	);
}

/**
 * Writes the full special-page metadata map to its storage.
 *
 * Per site on Multisite (a dedicated site option); nested in the shared settings
 * array on single site, so both contexts read it back through the same getter.
 *
 * @param array<string,mixed> $map Raw special-page map.
 * @return array<string,array<string,string|int>> Sanitized map that was stored.
 */
function erankly_update_special_meta_map( array $map ): array {
	$map = erankly_sanitize_special_meta_map( $map );

	if ( is_multisite() ) {
		// Autoloaded to match the settings form and import writers: the option is read
		// while rendering the document head on special pages.
		update_option( ERANKLY_SPECIAL_META_OPTION, $map, true );

		return $map;
	}

	$settings = get_option( ERANKLY_OPTION, array() );
	$settings = is_array( $settings ) ? $settings : array();

	$settings['global_special_meta'] = $map;

	erankly_update_plugin_option( ERANKLY_OPTION, $settings );

	return $map;
}
