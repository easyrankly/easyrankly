<?php
/**
 * Template-variable helpers shared by migration UI and background workers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects bounded, deduplicated variable-conversion diagnostics for a run.
 *
 * @param array<string,string>|null $warning Warning to add.
 * @param bool                      $reset   Whether to clear prior diagnostics.
 * @return array<int,array<string,string>>
 */
function erankly_import_variable_diagnostics( ?array $warning = null, bool $reset = false ): array {
	static $warnings = array();

	if ( $reset ) {
		$warnings = array();
	}

	if ( is_array( $warning ) && count( $warnings ) < 100 ) {
		$key = sanitize_key( (string) ( $warning['reference'] ?? '' ) );
		if ( '' !== $key && ! isset( $warnings[ $key ] ) ) {
			$warnings[ $key ] = array(
				'code'      => 'unsupported_template_variable',
				'message'   => sanitize_text_field( (string) ( $warning['message'] ?? '' ) ),
				'reference' => sanitize_text_field( (string) ( $warning['reference'] ?? '' ) ),
			);
		}
	}

	return array_values( $warnings );
}

/**
 * Converts third-party template variables to EasyRankly's {{token}} syntax.
 *
 * @param string $value  Raw template string.
 * @param string $source Source plugin: yoast|rankmath|aioseo|seopress.
 * @return string
 */
function erankly_import_convert_variables( string $value, string $source ): string {
	if ( '' === $value ) {
		return '';
	}

	$map = array(
		'title'                => '{{post_title}}',
		'seo_title'            => '{{post_title}}',
		'post_title'           => '{{post_title}}',
		'sitename'             => '{{site_name}}',
		'sitetitle'            => '{{site_name}}',
		'site_name'            => '{{site_name}}',
		'site_title'           => '{{site_name}}',
		'sitedesc'             => '{{site_description}}',
		'tagline'              => '{{site_description}}',
		'excerpt'              => '{{post_excerpt}}',
		'excerpt_only'         => '{{post_excerpt}}',
		'post_excerpt'         => '{{post_excerpt}}',
		'seo_description'      => '{{post_excerpt}}',
		'post_content'         => '{{post_content}}',
		'post_thumbnail'       => '{{featured_image}}',
		'post_thumbnail_url'   => '{{featured_image}}',
		'sep'                  => '-',
		'separator_sa'         => '-',
		'page'                 => '{{page_number}}',
		'pagenumber'           => '{{page_number}}',
		'pagetotal'            => '{{max_pages}}',
		'primary_category'     => '{{post_categories}}',
		'category'             => '{{post_categories}}',
		'categories'           => '{{post_categories}}',
		'post_category'        => '{{post_categories}}',
		'tag'                  => '{{post_tags}}',
		'tags'                 => '{{post_tags}}',
		'post_tag'             => '{{post_tags}}',
		'term'                 => '{{term_name}}',
		'term_title'           => '{{term_name}}',
		'term_description'     => '{{term_description}}',
		'taxonomy_description' => '{{term_description}}',
		'category_description' => '{{term_description}}',
		'tag_description'      => '{{term_description}}',
		'name'                 => '{{post_author}}',
		'post_author'          => '{{post_author}}',
		'date'                 => '{{post_date}}',
		'post_date'            => '{{post_date}}',
		'post_year'            => '{{post_year}}',
		'post_month'           => '{{post_month}}',
		'post_day'             => '{{post_day}}',
		'modified'             => '{{post_modified_date}}',
		'post_modified_date'   => '{{post_modified_date}}',
		'url'                  => '{{post_url}}',
		'permalink'            => '{{post_url}}',
		'currentyear'          => '{{current_year}}',
		'current_year'         => '{{current_year}}',
		'currentmonth'         => '{{current_month}}',
		'current_month'        => '{{current_month}}',
		'currentday'           => '{{current_day}}',
		'current_day'          => '{{current_day}}',
		'currentdate'          => '{{current_date}}',
		'current_date'         => '{{current_date}}',
		'pt_single'            => '{{post_type_name}}',
		'pt_plural'            => '{{post_type_name}}',
		'post_type'            => '{{post_type_name}}',
		'searchphrase'         => '{{search_query}}',
		'search_term'          => '{{search_query}}',
		'search_keywords'      => '{{search_query}}',
		'tax_name'             => '{{term_name}}',
		'taxonomy_title'       => '{{term_name}}',
		'author_name'          => '{{post_author}}',
	);

	switch ( $source ) {
		case 'yoast':
		case 'seopress':
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
		static function ( array $matches ) use ( $map, $source ): string {
			$name = strtolower( trim( explode( '(', $matches[1] )[0] ) );

			if ( isset( $map[ $name ] ) ) {
				return $map[ $name ];
			}

			erankly_import_variable_diagnostics(
				array(
					'message'   => sprintf(
						'aioseo' === $source ? 'Unrecognized AIOSEO hash token was preserved for review: %s.' : 'Unsupported %1$s template variable was removed: %2$s.',
						'aioseo' === $source ? sanitize_text_field( (string) $matches[0] ) : sanitize_key( $source ),
						sanitize_text_field( (string) $matches[0] )
					),
					'reference' => sanitize_key( $source ) . ':' . sanitize_key( $name ),
				)
			);

			return 'aioseo' === $source ? (string) $matches[0] : '';
		},
		$value
	);

	$replaced = is_string( $replaced ) ? $replaced : $value;
	$replaced = preg_replace( '/\s{2,}/', ' ', $replaced ) ?? $replaced;
	$replaced = trim( $replaced );
	$replaced = trim( $replaced, ' -|' );

	return trim( $replaced );
}
