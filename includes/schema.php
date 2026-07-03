<?php
/**
 * Schema.org JSON-LD graph.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders JSON-LD schema.
 *
 * @return void
 */
function erankly_render_schema(): void {
	$graph = erankly_get_schema_graph();

	if ( empty( $graph ) ) {
		return;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => array_values( $graph ),
	);

	// The JSON_HEX flags prevent values from closing the script element while
	// preserving valid JSON-LD for search engine parsers.
	echo '<script type="application/ld+json">';
	echo wp_json_encode(
		$schema,
		JSON_UNESCAPED_SLASHES
		| JSON_UNESCAPED_UNICODE
		| JSON_HEX_TAG
		| JSON_HEX_AMP
		| JSON_HEX_APOS
		| JSON_HEX_QUOT
	);
	echo '</script>' . "\n";
}

/**
 * Returns the page schema graph.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_get_schema_graph(): array {
	$graph = is_404() ? array() : erankly_schema_foundational_graph();

	$breadcrumbs = function_exists( 'erankly_schema_breadcrumb_list' )
		? erankly_schema_breadcrumb_list()
		: array();

	$breadcrumb_id = ! empty( $breadcrumbs ) && isset( $breadcrumbs['@id'] )
		? (string) $breadcrumbs['@id']
		: '';

	if ( is_singular() ) {
		$post_id = get_queried_object_id();
		$product = erankly_get_woocommerce_product_data( $post_id );

		$graph[] = erankly_schema_webpage( $post_id, $breadcrumb_id );

		if ( ! empty( $product ) ) {
			$graph[] = $product;
		} elseif ( 'post' === get_post_type( $post_id ) ) {
			$graph[] = erankly_schema_article( $post_id );
		}

		$faq = erankly_schema_faq( $post_id );

		if ( ! empty( $faq ) ) {
			$graph[] = $faq;
		}

		$local_business = erankly_schema_local_business_for_page( $post_id );

		if ( ! empty( $local_business ) ) {
			// LocalBusiness references the Organization via parentOrganization. Ensure that
			// node exists even when the primary identity is a Person (duplicates are removed
			// later by erankly_dedupe_schema_graph()).
			if ( 'person' === (string) erankly_get_setting( 'schema_identity', 'organization' ) ) {
				$graph[] = erankly_schema_organization();
			}

			$graph[] = $local_business;
		}
	} elseif ( ! is_404() ) {
		$graph[] = erankly_schema_webpage( 0, $breadcrumb_id );
	}

	foreach ( erankly_get_global_schema_graph() as $schema ) {
		$graph[] = $schema;
	}

	if ( ! empty( $breadcrumbs ) ) {
		$graph[] = $breadcrumbs;
	}

	/**
	 * Filters the complete schema graph.
	 *
	 * @param array<int,array<string,mixed>> $graph Schema graph.
	 */
	$graph = apply_filters( 'erankly_schema', array_filter( $graph ) );

	return is_array( $graph ) ? erankly_dedupe_schema_graph( $graph ) : array();
}

/**
 * Returns the base WebSite and identity graph nodes.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_schema_foundational_graph(): array {
	$identity = (string) erankly_get_setting( 'schema_identity', 'organization' );

	return array(
		'person' === $identity ? erankly_schema_person() : erankly_schema_organization(),
		erankly_schema_website(),
	);
}

/**
 * Removes duplicate schema graph nodes in the same JSON-LD graph.
 *
 * @param array<int,array<string,mixed>> $graph Schema graph.
 * @return array<int,array<string,mixed>>
 */
function erankly_dedupe_schema_graph( array $graph ): array {
	$seen   = array();
	$unique = array();

	foreach ( $graph as $schema ) {
		if ( ! is_array( $schema ) || empty( $schema ) ) {
			continue;
		}

		$id  = isset( $schema['@id'] ) && is_string( $schema['@id'] ) ? trim( $schema['@id'] ) : '';
		$key = '' !== $id ? 'id:' . $id : 'hash:' . md5( (string) wp_json_encode( $schema ) );

		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$unique[]     = $schema;
	}

	return $unique;
}

/**
 * Returns the configured primary identity schema ID.
 *
 * @return string
 */
function erankly_schema_identity_id(): string {
	$type = (string) erankly_get_setting( 'schema_identity', 'organization' );

	return home_url( 'person' === $type ? '/#person' : '/#organization' );
}

/**
 * Returns Organization schema.
 *
 * @return array<string,mixed>
 */
function erankly_schema_organization(): array {
	$logo    = erankly_get_organization_logo_url();
	$details = erankly_get_organization_schema_details();
	$schema  = array(
		'@type' => 'Organization',
		'@id'   => home_url( '/#organization' ),
		'name'  => erankly_get_organization_name(),
		'url'   => home_url( '/' ),
	);

	foreach ( array( 'description', 'email', 'telephone', 'legalName', 'vatID', 'taxID' ) as $property ) {
		if ( ! empty( $details[ $property ] ) ) {
			$schema[ $property ] = $details[ $property ];
		}
	}

	$address = erankly_schema_organization_address();

	if ( ! empty( $address ) ) {
		$schema['address'] = $address;
	}

	if ( '' !== $logo ) {
		$schema['logo'] = array(
			'@type' => 'ImageObject',
			'url'   => $logo,
		);
	}

	$same_as = erankly_get_social_profiles();

	if ( ! empty( $same_as ) ) {
		$schema['sameAs'] = $same_as;
	}

	/**
	 * Filters Organization schema.
	 *
	 * @param array<string,mixed> $schema Organization schema.
	 */
	return apply_filters( 'erankly_schema_organization', $schema );
}

/**
 * Returns extended Organization details.
 *
 * @return array<string,string>
 */
function erankly_get_organization_schema_details(): array {
	$details = array(
		'description' => trim( (string) erankly_get_setting( 'organization_description', '' ) ),
		'email'       => sanitize_email( (string) erankly_get_setting( 'organization_email', '' ) ),
		'telephone'   => erankly_sanitize_phone( erankly_get_setting( 'organization_phone', '' ) ),
		'legalName'   => trim( (string) erankly_get_setting( 'organization_legal_name', '' ) ),
		'vatID'       => trim( (string) erankly_get_setting( 'organization_vat_id', '' ) ),
		'taxID'       => trim( (string) erankly_get_setting( 'organization_tax_id', '' ) ),
	);

	/**
	 * Filters extended Organization details before schema output.
	 *
	 * @param array<string,string> $details Organization details.
	 */
	$details = apply_filters( 'erankly_organization_schema_details', $details );

	return is_array( $details ) ? array_filter( $details, 'is_string' ) : array();
}

/**
 * Returns the configured Organization PostalAddress.
 *
 * @param bool $require_complete Whether LocalBusiness-required fields must exist.
 * @return array<string,string>
 */
function erankly_schema_organization_address( bool $require_complete = false ): array {
	$address = array(
		'@type'           => 'PostalAddress',
		'streetAddress'   => trim( (string) erankly_get_setting( 'organization_street_address', '' ) ),
		'addressLocality' => trim( (string) erankly_get_setting( 'organization_locality', '' ) ),
		'addressRegion'   => trim( (string) erankly_get_setting( 'organization_region', '' ) ),
		'postalCode'      => trim( (string) erankly_get_setting( 'organization_postal_code', '' ) ),
		'addressCountry'  => erankly_sanitize_country_code( erankly_get_setting( 'organization_country', '' ) ),
	);

	if (
		$require_complete &&
		(
			'' === $address['streetAddress'] ||
			'' === $address['addressLocality'] ||
			'' === $address['postalCode'] ||
			'' === $address['addressCountry']
		)
	) {
		return array();
	}

	$address = array_filter(
		$address,
		static fn( string $value ): bool => '' !== $value
	);

	return count( $address ) > 1 ? $address : array();
}

/**
 * Returns Person schema.
 *
 * @return array<string,mixed>
 */
function erankly_schema_person(): array {
	$user_id = absint( erankly_get_setting( 'schema_person_user_id', 0 ) );
	$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

	if ( $user instanceof WP_User ) {
		$name = trim( (string) $user->display_name );

		if ( '' === $name ) {
			$name = erankly_get_organization_name();
		}

		if ( '' === trim( $name ) ) {
			$name = get_bloginfo( 'name' );
		}

		$author_url  = get_author_posts_url( $user->ID );
		$user_url    = esc_url_raw( (string) $user->user_url );
		$description = get_user_meta( $user->ID, 'description', true );
		$avatar      = get_avatar_url( $user->ID, array( 'size' => 512 ) );
		$schema      = array(
			'@type' => 'Person',
			'@id'   => home_url( '/#person' ),
			'name'  => $name,
			'url'   => $author_url,
		);

		if ( is_string( $description ) && '' !== trim( $description ) ) {
			$schema['description'] = erankly_trim_text( $description, 500 );
		}

		if ( is_string( $avatar ) && '' !== $avatar ) {
			$schema['image'] = esc_url_raw( $avatar );
		}

		if ( '' !== $user_url && esc_url_raw( $author_url ) !== $user_url ) {
			$schema['sameAs'] = array( $user_url );
		}

		/**
		 * Filters Person schema.
		 *
		 * @param array<string,mixed> $schema Person schema.
		 */
		return apply_filters( 'erankly_schema_person', array_filter( $schema ) );
	}

	$schema = array(
		'@type' => 'Person',
		'@id'   => home_url( '/#person' ),
		'name'  => erankly_get_organization_name(),
		'url'   => home_url( '/' ),
	);

	$same_as = erankly_get_social_profiles();

	if ( ! empty( $same_as ) ) {
		$schema['sameAs'] = $same_as;
	}

	/**
	 * Filters Person schema.
	 *
	 * @param array<string,mixed> $schema Person schema.
	 */
	return apply_filters( 'erankly_schema_person', $schema );
}

/**
 * Returns WebSite schema.
 *
 * @return array<string,mixed>
 */
function erankly_schema_website(): array {
	$schema = array(
		'@type'           => 'WebSite',
		'@id'             => home_url( '/#website' ),
		'url'             => home_url( '/' ),
		'name'            => get_bloginfo( 'name' ),
		'description'     => get_bloginfo( 'description' ),
		'publisher'       => array(
			'@id' => erankly_schema_identity_id(),
		),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => home_url( '/?s={search_term_string}' ),
			'query-input' => 'required name=search_term_string',
		),
	);

	/**
	 * Filters WebSite schema.
	 *
	 * @param array<string,mixed> $schema WebSite schema.
	 */
	return apply_filters( 'erankly_schema_website', $schema );
}

/**
 * Returns WebPage schema.
 *
 * @param int    $post_id       Optional post ID.
 * @param string $breadcrumb_id Optional BreadcrumbList @id to link via the breadcrumb property.
 * @return array<string,mixed>
 */
function erankly_schema_webpage( int $post_id = 0, string $breadcrumb_id = '' ): array {
	$canonical = erankly_get_canonical();
	$type      = ( 0 === $post_id && ( is_archive() || is_search() ) ) ? 'CollectionPage' : 'WebPage';
	$schema    = array(
		'@type'       => $type,
		'@id'         => $canonical . '#webpage',
		'url'         => $canonical,
		'name'        => erankly_get_title(),
		'description' => erankly_get_description(),
		'isPartOf'    => array(
			'@id' => home_url( '/#website' ),
		),
		'inLanguage'  => get_bloginfo( 'language' ),
	);

	if ( $post_id > 0 ) {
		$schema['datePublished'] = get_the_date( DATE_W3C, $post_id );
		$schema['dateModified']  = get_the_modified_date( DATE_W3C, $post_id );
	}

	// Link to the BreadcrumbList node only when the caller confirms one is emitted.
	if ( '' !== $breadcrumb_id ) {
		$schema['breadcrumb'] = array(
			'@id' => $breadcrumb_id,
		);
	}

	/**
	 * Filters WebPage schema.
	 *
	 * @param array<string,mixed> $schema  WebPage schema.
	 * @param int                 $post_id Post ID.
	 */
	return apply_filters( 'erankly_schema_webpage', array_filter( $schema ), $post_id );
}

/**
 * Returns Article schema.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>
 */
function erankly_schema_article( int $post_id = 0 ): array {
	if ( $post_id <= 0 ) {
		$post_id = get_queried_object_id();
	}

	$url       = erankly_get_canonical();
	$url       = '' !== $url ? $url : (string) get_permalink( $post_id );
	$image     = erankly_get_og_image();
	$author_id = (int) get_post_field( 'post_author', $post_id );
	$schema    = array(
		'@type'            => is_singular( 'post' ) ? 'BlogPosting' : 'Article',
		'@id'              => $url . '#article',
		'headline'         => erankly_get_title(),
		'description'      => erankly_get_description(),
		'url'              => $url,
		'datePublished'    => get_the_date( DATE_W3C, $post_id ),
		'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
		'author'           => erankly_schema_article_author( $author_id ),
		'publisher'        => array(
			'@id' => erankly_schema_identity_id(),
		),
		'mainEntityOfPage' => array(
			'@id' => $url . '#webpage',
		),
	);

	// erankly_get_og_image() already falls back through the post thumbnail,
	// the default OG image and finally the Organization logo.
	if ( '' !== $image ) {
		$schema['image'] = $image;
	}

	/**
	 * Filters Article schema.
	 *
	 * @param array<string,mixed> $schema  Article schema.
	 * @param int                 $post_id Post ID.
	 */
	return apply_filters( 'erankly_schema_article', array_filter( $schema ), $post_id );
}

/**
 * Returns the Article author node.
 *
 * Links to the Person identity node when the post author matches the
 * configured schema person, so the author and site identity stay connected.
 *
 * @param int $author_id Post author user ID.
 * @return array<string,mixed>
 */
function erankly_schema_article_author( int $author_id ): array {
	$author = array(
		'@type' => 'Person',
		'name'  => get_the_author_meta( 'display_name', $author_id ),
	);

	$author_url = $author_id > 0 ? get_author_posts_url( $author_id ) : '';

	if ( is_string( $author_url ) && '' !== $author_url ) {
		$author['url'] = $author_url;
	}

	$identity_user_id = absint( erankly_get_setting( 'schema_person_user_id', 0 ) );

	if (
		'person' === (string) erankly_get_setting( 'schema_identity', 'organization' ) &&
		$identity_user_id > 0 &&
		$identity_user_id === $author_id
	) {
		$author['@id'] = home_url( '/#person' );
	}

	return array_filter( $author );
}

/**
 * Returns BlogPosting schema.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>
 */
function erankly_schema_blogposting( int $post_id = 0 ): array {
	$schema          = erankly_schema_article( $post_id );
	$schema['@type'] = 'BlogPosting';

	return apply_filters( 'erankly_schema_blogposting', $schema, $post_id );
}

/**
 * Returns FAQPage schema when provided by filters.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>
 */
function erankly_schema_faq( int $post_id = 0 ): array {
	$schema = array();

	/**
	 * Filters FAQ items for a post.
	 *
	 * Expected item shape: array( 'question' => '...', 'answer' => '...' ).
	 *
	 * @param array<int,array<string,string>> $items   FAQ items.
	 * @param int                             $post_id Post ID.
	 */
	$items = apply_filters( 'erankly_faq_items', array(), $post_id );

	if ( is_array( $items ) && ! empty( $items ) ) {
		$entities = array();

		foreach ( $items as $item ) {
			$question = isset( $item['question'] ) ? erankly_trim_text( (string) $item['question'], 120 ) : '';
			$answer   = isset( $item['answer'] ) ? erankly_trim_text( (string) $item['answer'], 500 ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}

		if ( ! empty( $entities ) ) {
			$schema = array(
				'@type'      => 'FAQPage',
				'@id'        => erankly_get_canonical() . '#faqpage',
				'mainEntity' => $entities,
			);
		}
	}

	return apply_filters( 'erankly_schema_faq', $schema, $post_id );
}

/**
 * Returns Service schema.
 *
 * @param array<string,mixed> $args Service arguments.
 * @return array<string,mixed>
 */
function erankly_schema_service( array $args = array() ): array {
	$schema = wp_parse_args(
		$args,
		array(
			'@type'       => 'Service',
			'name'        => erankly_get_title(),
			'description' => erankly_get_description(),
			'url'         => erankly_get_canonical(),
			'provider'    => array(
				'@id' => erankly_schema_identity_id(),
			),
		)
	);

	return apply_filters( 'erankly_schema_service', array_filter( $schema ), $args );
}

/**
 * Returns LocalBusiness schema.
 *
 * @param array<string,mixed> $args Business arguments.
 * @return array<string,mixed>
 */
function erankly_schema_localbusiness( array $args = array() ): array {
	$schema = wp_parse_args(
		$args,
		array(
			'@type' => 'LocalBusiness',
			'@id'   => home_url( '/#localbusiness' ),
			'name'  => erankly_get_organization_name(),
			'url'   => home_url( '/' ),
		)
	);

	return apply_filters( 'erankly_schema_localbusiness', array_filter( $schema ), $args );
}

/**
 * Returns LocalBusiness schema for the configured location page.
 *
 * @param int $post_id Current singular post ID.
 * @return array<string,mixed>
 */
function erankly_schema_local_business_for_page( int $post_id ): array {
	if ( ! erankly_get_setting( 'enable_local_business', 0 ) || 'page' !== get_post_type( $post_id ) ) {
		return array();
	}

	$path = erankly_sanitize_relative_path( erankly_get_setting( 'local_business_page_path', '' ) );

	if ( '' === $path ) {
		return array();
	}

	$page = get_page_by_path( trim( $path, '/' ), OBJECT, 'page' );

	if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status || $page->ID !== $post_id ) {
		return array();
	}

	$name    = trim( erankly_get_organization_name() );
	$address = erankly_schema_organization_address( true );

	if ( '' === $name || empty( $address ) ) {
		return array();
	}

	$types = erankly_get_local_business_types();
	$type  = (string) erankly_get_setting( 'local_business_type', 'LocalBusiness' );
	$type  = isset( $types[ $type ] ) ? $type : 'LocalBusiness';
	$url   = get_permalink( $page );

	if ( ! is_string( $url ) || '' === $url ) {
		return array();
	}

	$schema = erankly_schema_localbusiness(
		array(
			'@type'              => $type,
			'@id'                => trailingslashit( $url ) . '#localbusiness',
			'name'               => $name,
			'url'                => $url,
			'address'            => $address,
			'parentOrganization' => array(
				'@id' => home_url( '/#organization' ),
			),
		)
	);
	$logo   = erankly_get_organization_logo_url();

	if ( '' !== $logo ) {
		$schema['image'] = $logo;
	}

	$email = sanitize_email( (string) erankly_get_setting( 'organization_email', '' ) );

	if ( '' !== $email ) {
		$schema['email'] = $email;
	}

	$telephone = erankly_sanitize_phone( erankly_get_setting( 'organization_phone', '' ) );

	if ( '' !== $telephone ) {
		$schema['telephone'] = $telephone;
	}

	$price_range = trim( (string) erankly_get_setting( 'local_business_price_range', '' ) );

	if ( '' !== $price_range ) {
		$schema['priceRange'] = $price_range;
	}

	$latitude  = erankly_sanitize_coordinate( erankly_get_setting( 'local_business_latitude', '' ), -90, 90 );
	$longitude = erankly_sanitize_coordinate( erankly_get_setting( 'local_business_longitude', '' ), -180, 180 );

	if ( '' !== $latitude && '' !== $longitude ) {
		$schema['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $latitude,
			'longitude' => (float) $longitude,
		);
	}

	$opening_hours = erankly_schema_opening_hours();

	if ( ! empty( $opening_hours ) ) {
		$schema['openingHoursSpecification'] = $opening_hours;
	}

	if ( erankly_is_food_business_type( $type ) ) {
		$menu = erankly_sanitize_url( erankly_get_setting( 'local_business_menu_url', '' ) );

		if ( '' !== $menu ) {
			$schema['menu'] = $menu;
		}

		$cuisine = trim( (string) erankly_get_setting( 'local_business_cuisine', '' ) );

		if ( '' !== $cuisine ) {
			$schema['servesCuisine'] = array_values( array_filter( array_map( 'trim', explode( ',', $cuisine ) ) ) );
		}
	}

	/**
	 * Filters LocalBusiness schema generated for the configured location page.
	 *
	 * @param array<string,mixed> $schema  LocalBusiness schema.
	 * @param int                 $post_id Location page ID.
	 */
	$schema = apply_filters( 'erankly_schema_local_business', $schema, $post_id );

	return is_array( $schema ) ? array_filter( $schema ) : array();
}

/**
 * Returns grouped OpeningHoursSpecification values.
 *
 * @param array<string,mixed>|null $configured_hours Optional hours override.
 * @return array<int,array<string,mixed>>
 */
function erankly_schema_opening_hours( ?array $configured_hours = null ): array {
	$hours  = erankly_sanitize_opening_hours( null === $configured_hours ? erankly_get_setting( 'local_business_hours', array() ) : $configured_hours );
	$days   = array(
		'monday'    => 'Monday',
		'tuesday'   => 'Tuesday',
		'wednesday' => 'Wednesday',
		'thursday'  => 'Thursday',
		'friday'    => 'Friday',
		'saturday'  => 'Saturday',
		'sunday'    => 'Sunday',
	);
	$groups = array();

	foreach ( $days as $day_key => $schema_day ) {
		$day_hours = $hours[ $day_key ];
		$schedule  = ! empty( $day_hours['closed'] ) ? array(
			array(
				'opens'  => '00:00',
				'closes' => '00:00',
			),
		) : array_values(
			array_filter(
				$day_hours['intervals'],
				static fn( array $interval ): bool => '' !== $interval['opens'] && '' !== $interval['closes']
			)
		);

		if ( empty( $schedule ) ) {
			continue;
		}

		$key = (string) wp_json_encode( $schedule );

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'days'     => array(),
				'schedule' => $schedule,
			);
		}

		$groups[ $key ]['days'][] = $schema_day;
	}

	$specifications = array();

	foreach ( $groups as $group ) {
		foreach ( $group['schedule'] as $interval ) {
			$specifications[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $group['days'],
				'opens'     => $interval['opens'],
				'closes'    => $interval['closes'],
			);
		}
	}

	return $specifications;
}

/**
 * Returns schema blocks configured globally for the current request.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_get_global_schema_graph(): array {
	$blocks = erankly_get_setting( 'global_schema_blocks', array() );

	if ( ! is_array( $blocks ) ) {
		return array();
	}

	$graph   = array();
	$post_id = is_singular() ? get_queried_object_id() : 0;

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) || empty( $block['type'] ) ) {
			continue;
		}

		if ( ! erankly_global_schema_block_matches_request( $block ) ) {
			continue;
		}

		$schemas = erankly_schema_from_configured_block( $block, $post_id );

		foreach ( $schemas as $schema ) {
			if ( ! empty( $schema ) ) {
				$graph[] = $schema;
			}
		}
	}

	return $graph;
}

/**
 * Returns whether a global schema block applies to the current request.
 *
 * @param array<string,mixed> $block Schema block.
 * @return bool
 */
function erankly_global_schema_block_matches_request( array $block ): bool {
	if ( empty( $block['enabled'] ) ) {
		return false;
	}

	$contexts = isset( $block['target_contexts'] ) && is_array( $block['target_contexts'] ) ? $block['target_contexts'] : array();

	if ( empty( $contexts ) ) {
		return false;
	}

	if ( in_array( 'front_page', $contexts, true ) && is_front_page() ) {
		return true;
	}

	if ( in_array( 'posts_page', $contexts, true ) && is_home() && ! is_front_page() ) {
		return true;
	}

	if ( in_array( 'search', $contexts, true ) && is_search() ) {
		return true;
	}

	if ( in_array( 'post_type_archive', $contexts, true ) && erankly_global_schema_matches_post_type_archive( $block ) ) {
		return true;
	}

	if ( in_array( 'singular', $contexts, true ) && erankly_global_schema_matches_singular( $block ) ) {
		return true;
	}

	return false;
}

/**
 * Returns whether a global schema block applies to the current post type archive.
 *
 * @param array<string,mixed> $block Schema block.
 * @return bool
 */
function erankly_global_schema_matches_post_type_archive( array $block ): bool {
	if ( ! is_post_type_archive() ) {
		return false;
	}

	$target_post_types = isset( $block['target_post_types'] ) && is_array( $block['target_post_types'] ) ? $block['target_post_types'] : array();

	if ( empty( $target_post_types ) ) {
		return false;
	}

	$current_post_type = get_query_var( 'post_type' );

	if ( is_array( $current_post_type ) ) {
		foreach ( $current_post_type as $post_type ) {
			if ( in_array( (string) $post_type, $target_post_types, true ) ) {
				return true;
			}
		}

		return false;
	}

	if ( is_string( $current_post_type ) && '' !== $current_post_type ) {
		return in_array( $current_post_type, $target_post_types, true );
	}

	$queried = get_queried_object();

	return $queried instanceof WP_Post_Type && in_array( $queried->name, $target_post_types, true );
}

/**
 * Returns whether a global schema block applies to the current singular object.
 *
 * @param array<string,mixed> $block Schema block.
 * @return bool
 */
function erankly_global_schema_matches_singular( array $block ): bool {
	if ( ! is_singular() ) {
		return false;
	}

	$post_id = get_queried_object_id();

	if ( $post_id <= 0 ) {
		return false;
	}

	$target_post_types = isset( $block['target_post_types'] ) && is_array( $block['target_post_types'] ) ? $block['target_post_types'] : array();
	$post_type         = get_post_type( $post_id );

	if ( empty( $target_post_types ) || ! is_string( $post_type ) || ! in_array( $post_type, $target_post_types, true ) ) {
		return false;
	}

	if ( erankly_schema_target_list_contains_post( isset( $block['exclude_items'] ) ? (string) $block['exclude_items'] : '', $post_id ) ) {
		return false;
	}

	$include_items = isset( $block['include_items'] ) ? (string) $block['include_items'] : '';

	if ( '' === trim( $include_items ) ) {
		return true;
	}

	return erankly_schema_target_list_contains_post( $include_items, $post_id );
}

/**
 * Returns whether a target list contains a post ID or slug.
 *
 * @param string $value   Target list.
 * @param int    $post_id Post ID.
 * @return bool
 */
function erankly_schema_target_list_contains_post( string $value, int $post_id ): bool {
	$items = preg_split( '/[\r\n,]+/', $value );

	if ( ! is_array( $items ) || $post_id <= 0 ) {
		return false;
	}

	$post = get_post( $post_id );
	$slug = $post instanceof WP_Post ? $post->post_name : '';

	foreach ( $items as $item ) {
		$item = trim( (string) $item );

		if ( '' === $item ) {
			continue;
		}

		if ( ctype_digit( $item ) && absint( $item ) === $post_id ) {
			return true;
		}

		if ( '' !== $slug && sanitize_title( $item ) === $slug ) {
			return true;
		}
	}

	return false;
}

/**
 * Builds schema data from a configured block.
 *
 * @param array<string,mixed> $block   Schema block.
 * @param int                 $post_id Post ID.
 * @return array<int,array<string,mixed>>
 */
function erankly_schema_from_configured_block( array $block, int $post_id ): array {
	$type = isset( $block['type'] ) ? (string) $block['type'] : '';

	return 'custom' === $type ? erankly_configured_custom_schemas( $block, $post_id ) : array();
}

/**
 * Builds configured custom JSON-LD schemas.
 *
 * @param array<string,mixed> $block   Schema block.
 * @param int                 $post_id Post ID.
 * @return array<int,array<string,mixed>>
 */
function erankly_configured_custom_schemas( array $block, int $post_id ): array {
	$json = erankly_schema_block_field( $block, 'custom_json', $post_id, true );

	if ( '' === $json ) {
		return array();
	}

	$schemas = array();
	$decoded = erankly_decode_custom_json_ld( erankly_replace_json_ld_variables( $json, $post_id ) );

	foreach ( $decoded as $schema ) {
		if ( ! empty( $schema ) ) {
			$schemas[] = erankly_filter_empty_schema_values( $schema );
		}
	}

	return array_filter( $schemas );
}

/**
 * Returns a configured schema block field.
 *
 * @param array<string,mixed> $block     Schema block.
 * @param string              $field     Field key.
 * @param int                 $post_id   Post ID.
 * @param bool                $raw_value Whether to return the raw stored value.
 * @return string
 */
function erankly_schema_block_field( array $block, string $field, int $post_id, bool $raw_value = false ): string {
	$fields = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : array();
	$value  = isset( $fields[ $field ] ) ? trim( (string) $fields[ $field ] ) : '';

	if ( $raw_value || '' === $value ) {
		return $value;
	}

	return trim( wp_strip_all_tags( erankly_replace_variables( $value, $post_id ) ) );
}

/**
 * Recursively removes empty schema values.
 *
 * @param array<string,mixed> $schema Schema data.
 * @return array<string,mixed>
 */
function erankly_filter_empty_schema_values( array $schema ): array {
	foreach ( $schema as $key => $value ) {
		if ( is_array( $value ) ) {
			$value = erankly_filter_empty_schema_values( $value );
		}

		if ( array() === $value || '' === $value || null === $value ) {
			unset( $schema[ $key ] );
			continue;
		}

		$schema[ $key ] = $value;
	}

	return $schema;
}
