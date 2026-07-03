<?php
/**
 * Shared helpers — input sanitization.
 *
 * Part of the helpers.php loader; always loaded early on every request.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes a plain text field.
 *
 * Expects an already-unslashed value: callers reading from $_POST must
 * wp_unslash() first. Unslashing here too would corrupt literal backslashes.
 *
 * @param mixed $value Raw (unslashed) value.
 * @return string
 */
function erankly_sanitize_text( mixed $value ): string {
	return sanitize_text_field( (string) $value );
}

/**
 * Sanitizes textarea text without markup.
 *
 * Expects an already-unslashed value (see erankly_sanitize_text()).
 *
 * @param mixed $value Raw (unslashed) value.
 * @return string
 */
function erankly_sanitize_textarea( mixed $value ): string {
	return sanitize_textarea_field( (string) $value );
}

/**
 * Normalizes an X/Twitter handle.
 *
 * @param mixed $value Raw handle or profile URL.
 * @return string
 */
function erankly_sanitize_twitter_handle( mixed $value ): string {
	$value = trim( erankly_sanitize_text( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( preg_match( '#^(?:https?://)?(?:www\.)?(?:x|twitter)\.com/#i', $value ) ) {
		$url   = str_starts_with( $value, 'http' ) ? $value : 'https://' . $value;
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$value = (string) strtok( trim( $path, '/' ), '/' );
	}

	$handle = ltrim( $value, '@' );
	$handle = preg_replace( '/[^A-Za-z0-9_]/', '', $handle );
	$handle = substr( (string) $handle, 0, 15 );

	return '' === $handle ? '' : '@' . $handle;
}

/**
 * Sanitizes a URL field.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_url( mixed $value ): string {
	$value = trim( (string) $value );

	return '' === $value ? '' : esc_url_raw( $value );
}

/**
 * Sanitizes an absolute HTTP(S) URL.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_absolute_url( mixed $value ): string {
	$url = erankly_sanitize_url( $value );

	if ( '' === $url ) {
		return '';
	}

	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	$host   = wp_parse_url( $url, PHP_URL_HOST );

	return is_string( $scheme ) && is_string( $host ) && in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ? $url : '';
}

/**
 * Sanitizes a URL field that may contain EasyRankly {{variables}}.
 *
 * Literal URLs go through esc_url_raw(). Templated URLs are finalized only after
 * variable replacement, so preserve placeholders while still removing invalid
 * text input and disallowed protocols at save time.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_url_template( mixed $value ): string {
	$value = trim( erankly_sanitize_text( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( ! str_contains( $value, '{{' ) ) {
		return erankly_sanitize_url( $value );
	}

	$value = (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ): string {
			return '{{' . strtolower( (string) $matches[1] ) . '}}';
		},
		$value
	);

	return trim( wp_kses_bad_protocol( $value, wp_allowed_protocols() ) );
}

/**
 * Sanitizes a newline-separated list of absolute HTTP(S) URLs.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function erankly_sanitize_url_list( mixed $value ): string {
	$value = erankly_sanitize_textarea( $value );
	$lines = preg_split( '/\R/', $value );

	if ( ! is_array( $lines ) ) {
		return '';
	}

	$urls = array();

	foreach ( $lines as $line ) {
		$url = erankly_sanitize_absolute_url( $line );

		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}

	return implode( "\n", array_values( array_unique( $urls ) ) );
}

/**
 * Returns supported LocalBusiness schema types.
 *
 * @return array<string,string>
 */
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

	/**
	 * Filters supported LocalBusiness schema types.
	 *
	 * @param array<string,string> $types LocalBusiness types.
	 */
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

/**
 * Returns whether a LocalBusiness type supports food-specific properties.
 *
 * @param string $type Schema.org type.
 * @return bool
 */
function erankly_is_food_business_type( string $type ): bool {
	return in_array( $type, array( 'Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'FoodEstablishment' ), true );
}

/**
 * Sanitizes a relative site path.
 *
 * @param mixed $value Raw path.
 * @return string
 */
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

/**
 * Sanitizes a business telephone number.
 *
 * @param mixed $value Raw telephone number.
 * @return string
 */
function erankly_sanitize_phone( mixed $value ): string {
	$value  = erankly_sanitize_text( $value );
	$value  = preg_replace( '/[^0-9+().\-\s]/', '', $value );
	$digits = is_string( $value ) ? preg_replace( '/\D/', '', $value ) : '';

	if ( ! is_string( $value ) || ! is_string( $digits ) || strlen( $digits ) < 5 ) {
		return '';
	}

	return trim( $value );
}

/**
 * Sanitizes an ISO 3166-1 alpha-2 country code.
 *
 * @param mixed $value Raw country code.
 * @return string
 */
function erankly_sanitize_country_code( mixed $value ): string {
	$value = strtoupper( erankly_sanitize_text( $value ) );

	return 1 === preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
}

/**
 * Sanitizes a geographic coordinate.
 *
 * @param mixed $value   Raw coordinate.
 * @param float $minimum Minimum value.
 * @param float $maximum Maximum value.
 * @return string
 */
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

/**
 * Sanitizes a 24-hour time value.
 *
 * @param mixed $value Raw time.
 * @return string
 */
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
 * @param mixed $value Raw hours.
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
 * Produces a compact SEO string.
 *
 * @param string $value Raw string.
 * @param int    $limit Character limit.
 * @return string
 */
function erankly_trim_text( string $value, int $limit = 160 ): string {
	$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );

	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

	if ( '' === $value || $length <= $limit ) {
		return $value;
	}

	$excerpt = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit - 1 ) : substr( $value, 0, $limit - 1 );

	return rtrim( $excerpt, " \t\n\r\0\x0B.,;:-" );
}

/**
 * Produces a compact SEO string without applying a character limit.
 *
 * @param string $value Raw string.
 * @return string
 */
function erankly_normalize_seo_text( string $value ): string {
	$value = preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $value ) ) );

	return is_string( $value ) ? trim( $value ) : '';
}
