<?php
// phpcs:ignoreFile -- Test fixture builder mutates only an explicitly ephemeral Multisite installation.
/**
 * Deterministic Multisite fixtures for M1.
 *
 * @package EasyRankly
 */

function erankly_ml_contract_sites( int $required ): array {
	$network_id = get_current_network_id();
	$sites      = get_sites( array( 'network_id' => $network_id, 'number' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );

	if ( count( $sites ) < $required && '1' !== (string) getenv( 'ERANKLY_ML_CONTRACT_EPHEMERAL' ) ) {
		throw new RuntimeException( 'Refusing to create Multisite fixtures outside an explicitly ephemeral environment.' );
	}

	$network = get_network( $network_id );
	if ( ! $network instanceof WP_Network ) {
		throw new RuntimeException( 'The fixture network is unavailable.' );
	}

	while ( count( $sites ) < $required ) {
		$position = count( $sites ) + 1;
		$domain   = is_subdomain_install()
			? 'm1-site-' . $position . '.' . preg_replace( '#^www\.#', '', (string) $network->domain )
			: (string) $network->domain;
		$path     = is_subdomain_install()
			? '/'
			: trailingslashit( (string) $network->path ) . 'm1-site-' . $position . '/';
		$blog_id  = wpmu_create_blog(
			$domain,
			$path,
			'EasyRankly M1 site ' . $position,
			1,
			array( 'public' => 0 ),
			$network_id
		);

		if ( is_wp_error( $blog_id ) ) {
			throw new RuntimeException( 'Unable to create site fixture ' . $position . ': ' . $blog_id->get_error_message() );
		}

		$sites = get_sites( array( 'network_id' => $network_id, 'number' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
	}

	return array_slice( $sites, 0, $required );
}

function erankly_ml_contract_language_code( int $position ): string {
	$known = array( 1 => 'it', 2 => 'en', 3 => 'de' );
	return $known[ $position ] ?? 'en-x' . str_pad( (string) $position, 4, '0', STR_PAD_LEFT );
}

function erankly_ml_contract_site_map( array $sites ): array {
	$map = array();
	foreach ( array_values( $sites ) as $index => $site ) {
		$position = $index + 1;
		$map[ (int) $site->blog_id ] = array(
			'hreflang'     => erankly_ml_contract_language_code( $position ),
			'enabled'      => true,
			'is_default'   => 1 === $position,
			'notice_title' => 'Available in {language}',
			'notice_text'  => 'Read this content in {language}.',
			'notice_link'  => 'Open {language}',
		);
	}
	return $map;
}

function erankly_ml_contract_create_post( int $blog_id, string $slug, string $status = 'publish' ): int {
	switch_to_blog( $blog_id );
	try {
		update_option( 'permalink_structure', '/%postname%/' );
		$post_id = wp_insert_post(
			array(
				'post_title'   => ucwords( str_replace( '-', ' ', $slug ) ),
				'post_name'    => $slug,
				'post_content' => 'EasyRankly M1 characterization content.',
				'post_status'  => $status,
				'post_type'    => 'post',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Unable to create post fixture: ' . $post_id->get_error_message() );
		}
		return (int) $post_id;
	} finally {
		restore_current_blog();
	}
}

function erankly_ml_contract_create_term( int $blog_id, string $slug ): int {
	switch_to_blog( $blog_id );
	try {
		$term = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), 'category', array( 'slug' => $slug ) );
		if ( is_wp_error( $term ) ) {
			throw new RuntimeException( 'Unable to create term fixture: ' . $term->get_error_message() );
		}
		return (int) $term['term_id'];
	} finally {
		restore_current_blog();
	}
}

function erankly_ml_contract_create_second_network_fixture(): array {
	global $wpdb;

	$domain = 'm1-second-network.example.test';
	$existing_network = $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM {$wpdb->site} WHERE domain = %s AND path = %s LIMIT 1", $domain, '/' )
	);

	if ( $existing_network ) {
		$network_id = (int) $existing_network;
	} else {
		$inserted = $wpdb->insert( $wpdb->site, array( 'domain' => $domain, 'path' => '/' ), array( '%s', '%s' ) );
		if ( false === $inserted ) {
			throw new RuntimeException( 'Unable to create the second-network fixture.' );
		}
		$network_id = (int) $wpdb->insert_id;
		wp_cache_delete( $network_id, 'networks' );
	}

	$existing_site = $wpdb->get_var(
		$wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND domain = %s AND path = %s LIMIT 1", $network_id, $domain, '/' )
	);

	if ( $existing_site ) {
		$blog_id = (int) $existing_site;
	} else {
		$inserted = $wpdb->insert(
			$wpdb->blogs,
			array(
				'site_id'      => $network_id,
				'domain'       => $domain,
				'path'         => '/',
				'registered'   => current_time( 'mysql', true ),
				'last_updated' => current_time( 'mysql', true ),
				'public'       => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Unable to create the second-network site fixture.' );
		}
		$blog_id = (int) $wpdb->insert_id;
		clean_blog_cache( $blog_id );
	}

	return array( 'network_id' => $network_id, 'blog_id' => $blog_id );
}
