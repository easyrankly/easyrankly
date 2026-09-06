<?php
/** Schema and LocalBusiness sanitization helpers. Loaded only for full defaults, settings and rendered schema content. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the selectable WebPage subtypes, keyed by the stored value. "none" suppresses the page node entirely.
 * The list is closed on purpose: the field used to accept free text, which let a typo ("Webpage", "chi-siamo")
 * reach the output as an invalid @type. Values imported from another SEO plugin survive because the renderer
 * appends any stored type that is missing from this list.
 *
 * @return array<string,string>
 */
function erankly_get_webpage_schema_types(): array {
	$types = array(
		'WebPage'        => __( 'Web page (default)', 'easyrankly' ),
		'ItemPage'       => __( 'Item page', 'easyrankly' ),
		'AboutPage'      => __( 'About page', 'easyrankly' ),
		'ContactPage'    => __( 'Contact page', 'easyrankly' ),
		'ProfilePage'    => __( 'Profile page', 'easyrankly' ),
		'CollectionPage' => __( 'Collection page', 'easyrankly' ),
		'none'           => __( 'No page schema', 'easyrankly' ),
	);

	/** Filters the selectable WebPage subtypes for post type schema defaults. */
	return erankly_filter_schema_type_choices( apply_filters( 'erankly_webpage_schema_types', $types ) );
}

/**
 * Returns the selectable Article subtypes, keyed by the stored value. Google treats Article, BlogPosting and
 * NewsArticle identically, so the meaningful choice here is really "emit an Article node or not".
 *
 * @return array<string,string>
 */
function erankly_get_article_schema_types(): array {
	$types = array(
		'none'        => __( 'No article schema', 'easyrankly' ),
		'Article'     => __( 'Article', 'easyrankly' ),
		'BlogPosting' => __( 'Blog posting', 'easyrankly' ),
		'NewsArticle' => __( 'News article', 'easyrankly' ),
	);

	/** Filters the selectable Article subtypes for post type schema defaults. */
	return erankly_filter_schema_type_choices( apply_filters( 'erankly_article_schema_types', $types ) );
}

/**
 * Keeps only well-formed Schema.org type names (plus the "none" sentinel) from a filtered choice list.
 *
 * @param mixed $types Value => label map.
 * @return array<string,string>
 */
function erankly_filter_schema_type_choices( mixed $types ): array {
	if ( ! is_array( $types ) ) {
		return array();
	}

	$valid = array();

	foreach ( $types as $type => $label ) {
		$type = (string) $type;

		if ( is_string( $label ) && ( 'none' === $type || 1 === preg_match( '/^[A-Z][A-Za-z0-9]*$/', $type ) ) ) {
			$valid[ $type ] = $label;
		}
	}

	return $valid;
}

/**
 * Returns the Schema.org types a post type falls back to when nothing has been configured for it. Posts describe
 * themselves as blog postings; every other post type emits only the page node, because an Article node on a
 * landing page or a portfolio item is structured data that does not describe the content.
 *
 * @return array{webpage_type:string,article_type:string}
 */
function erankly_default_post_type_schema_row( string $post_type ): array {
	$row = array(
		'webpage_type' => 'WebPage',
		'article_type' => 'post' === $post_type ? 'BlogPosting' : 'none',
	);

	/** Filters the default Schema.org types for a post type. */
	$row = apply_filters( 'erankly_default_post_type_schema_row', $row, $post_type );

	return array(
		'webpage_type' => isset( $row['webpage_type'] ) ? (string) $row['webpage_type'] : 'WebPage',
		'article_type' => isset( $row['article_type'] ) ? (string) $row['article_type'] : 'none',
	);
}

/** @return array<string,string> */
function erankly_get_local_business_types(): array {
	$types = array(
		'LocalBusiness'     => __( 'Local business', 'easyrankly' ),
		'Store'             => __( 'Store', 'easyrankly' ),
		'Restaurant'        => __( 'Restaurant', 'easyrankly' ),
		'CafeOrCoffeeShop'  => __( 'Cafe or coffee shop', 'easyrankly' ),
		'BarOrPub'          => __( 'Bar or pub', 'easyrankly' ),
		'Bakery'            => __( 'Bakery', 'easyrankly' ),
		'FoodEstablishment' => __( 'Food establishment', 'easyrankly' ),
		'Dentist'           => __( 'Dentist', 'easyrankly' ),
		'MedicalClinic'     => __( 'Medical clinic', 'easyrankly' ),
		'Pharmacy'          => __( 'Pharmacy', 'easyrankly' ),
		'LegalService'      => __( 'Legal service', 'easyrankly' ),
		'RealEstateAgent'   => __( 'Real estate agent', 'easyrankly' ),
		'AutoRepair'        => __( 'Auto repair', 'easyrankly' ),
		'BeautySalon'       => __( 'Beauty salon', 'easyrankly' ),
		'HairSalon'         => __( 'Hair salon', 'easyrankly' ),
		'NailSalon'         => __( 'Nail salon', 'easyrankly' ),
		'HealthClub'        => __( 'Health club', 'easyrankly' ),
		'Hotel'             => __( 'Hotel', 'easyrankly' ),
		'BedAndBreakfast'   => __( 'Bed and breakfast', 'easyrankly' ),
		'LodgingBusiness'   => __( 'Lodging business', 'easyrankly' ),
	);

	$types = apply_filters( 'erankly_local_business_types', $types );

	if ( ! is_array( $types ) ) {
		return array();
	}

	$valid_types = array();

	foreach ( $types as $type => $label ) {
		if ( is_string( $type ) && is_string( $label ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $type ) ) {
			$valid_types[ $type ] = $label;
		}
	}

	return $valid_types;
}

function erankly_is_food_business_type( string $type ): bool {
	return in_array( $type, array( 'Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'FoodEstablishment' ), true );
}

/** Sanitizes a relative site path. */
function erankly_sanitize_relative_path( mixed $value ): string {
	$value = trim( (string) $value );

	if ( '' === $value || str_starts_with( $value, '//' ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $value ) ) {
		return '';
	}

	$path = wp_parse_url( $value, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( sanitize_text_field( $path ), '/' );

	return '/' === $path ? '/' : trailingslashit( $path );
}

/** Sanitizes a business telephone number. */
function erankly_sanitize_phone( mixed $value ): string {
	$value  = erankly_sanitize_text( $value );
	$value  = preg_replace( '/[^0-9+().\-\s]/', '', $value );
	$digits = is_string( $value ) ? preg_replace( '/\D/', '', $value ) : '';

	if ( ! is_string( $value ) || ! is_string( $digits ) || strlen( $digits ) < 5 ) {
		return '';
	}

	return trim( $value );
}

/** Sanitizes an ISO 3166-1 alpha-2 country code. */
function erankly_sanitize_country_code( mixed $value ): string {
	$value = strtoupper( erankly_sanitize_text( $value ) );

	return 1 === preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
}

/** Sanitizes a geographic coordinate. */
function erankly_sanitize_coordinate( mixed $value, float $minimum, float $maximum ): string {
	$value = trim( (string) $value );

	if ( '' === $value || ! is_numeric( $value ) ) {
		return '';
	}

	$number = (float) $value;

	if ( $number < $minimum || $number > $maximum ) {
		return '';
	}

	return rtrim( rtrim( number_format( $number, 6, '.', '' ), '0' ), '.' );
}

/** Sanitizes a 24-hour time value. */
function erankly_sanitize_time( mixed $value ): string {
	$value = erankly_sanitize_text( $value );

	return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
}

/**
 * Returns empty weekly opening hours.
 *
 * @return array<string,array{closed:int,intervals:array<int,array{opens:string,closes:string}>}>
 */
function erankly_default_opening_hours(): array {
	$hours = array();

	foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
		$hours[ $day ] = array(
			'closed'    => 0,
			'intervals' => array(
				array(
					'opens'  => '',
					'closes' => '',
				),
				array(
					'opens'  => '',
					'closes' => '',
				),
			),
		);
	}

	return $hours;
}

/**
 * Sanitizes weekly LocalBusiness opening hours.
 *
 * @return array<string,array{closed:int,intervals:array<int,array{opens:string,closes:string}>}>
 */
function erankly_sanitize_opening_hours( mixed $value ): array {
	$value = is_array( $value ) ? $value : array();
	$hours = erankly_default_opening_hours();

	foreach ( array_keys( $hours ) as $day ) {
		$raw_day                 = isset( $value[ $day ] ) && is_array( $value[ $day ] ) ? $value[ $day ] : array();
		$hours[ $day ]['closed'] = ! empty( $raw_day['closed'] ) ? 1 : 0;
		$raw_intervals           = isset( $raw_day['intervals'] ) && is_array( $raw_day['intervals'] ) ? $raw_day['intervals'] : array();

		foreach ( array( 0, 1 ) as $index ) {
			$raw_interval = isset( $raw_intervals[ $index ] ) && is_array( $raw_intervals[ $index ] ) ? $raw_intervals[ $index ] : array();
			$opens        = isset( $raw_interval['opens'] ) ? erankly_sanitize_time( $raw_interval['opens'] ) : '';
			$closes       = isset( $raw_interval['closes'] ) ? erankly_sanitize_time( $raw_interval['closes'] ) : '';

			if ( '' === $opens || '' === $closes ) {
				$opens  = '';
				$closes = '';
			}

			$hours[ $day ]['intervals'][ $index ] = array(
				'opens'  => $opens,
				'closes' => $closes,
			);
		}
	}

	return $hours;
}

/**
 * Sanitizes the per-site LocalBusiness page map. Keys are blog IDs, values are published page IDs.
 *
 * @return array<int,int>
 */
function erankly_sanitize_local_business_pages( mixed $value ): array {
	$value = is_array( $value ) ? $value : array();
	$clean = array();

	foreach ( $value as $blog_id => $page_id ) {
		$blog_id = absint( $blog_id );
		$page_id = absint( $page_id );

		if ( $blog_id <= 0 || $page_id <= 0 ) {
			continue;
		}

		$switched = is_multisite() && get_current_blog_id() !== $blog_id;

		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		$page = get_post( $page_id );

		if ( $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status ) {
			$clean[ $blog_id ] = $page_id;
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $clean;
}

function erankly_sanitize_breadcrumb_jsonld_mode( mixed $value ): string {
	$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

	return in_array( $value, array( 'off', 'when_visible', 'always' ), true ) ? $value : 'when_visible';
}
