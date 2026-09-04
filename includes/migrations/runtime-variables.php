<?php
/** Template-variable helpers shared by migration UI and background workers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
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
		'post_excerpt_only'    => '{{post_excerpt}}',
		'attachment_caption'   => '{{post_excerpt}}',
		'caption'              => '{{post_excerpt}}',
		'seo_description'      => '{{post_excerpt}}',
		'post_content'         => '{{post_content}}',
		'post_thumbnail'       => '{{featured_image}}',
		'post_thumbnail_url'   => '{{featured_image}}',
		'featured_image_url'   => '{{featured_image}}',
		'sep'                  => '-',
		'separator_sa'         => '-',
		'page'                 => '{{pagination}}',
		'pagenumber'           => '{{page_number}}',
		'page_number'          => '{{page_number}}',
		'pagination'           => '{{pagination}}',
		'current_pagination'   => '{{current_pagination}}',
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
		'category_title'       => '{{term_name}}',
		'_category_title'      => '{{term_name}}',
		'tag_title'            => '{{term_name}}',
		'term_description'     => '{{term_description}}',
		'taxonomy_description' => '{{term_description}}',
		'category_description' => '{{term_description}}',
		'_category_description' => '{{term_description}}',
		'tag_description'      => '{{term_description}}',
		'name'                 => '{{post_author}}',
		'post_author'          => '{{post_author}}',
		'date'                 => '{{post_date}}',
		'post_date'            => '{{post_date}}',
		'post_date_w3c'        => '{{post_date}}',
		'post_year'            => '{{post_year}}',
		'post_month'           => '{{post_month}}',
		'post_day'             => '{{post_day}}',
		'modified'             => '{{post_modified_date}}',
		'post_modified_date'   => '{{post_modified_date}}',
		'post_modified_date_w3c' => '{{post_modified_date}}',
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
		'search_query'         => '{{search_query}}',
		'search_term'          => '{{search_query}}',
		'search_keywords'      => '{{search_query}}',
		'tax_name'             => '{{term_name}}',
		'taxonomy_title'       => '{{term_name}}',
		'author_name'          => '{{post_author}}',
		'author_first_name'    => '{{author_first_name}}',
		'author_last_name'     => '{{author_last_name}}',
		'author_bio'           => '{{author_bio}}',
		'author_description'   => '{{author_bio}}',
		'user_description'     => '{{author_bio}}',
		'author_link'          => '{{post_author}}',
		'author_link_alt'      => '{{author_url}}',
		'author_url'           => '{{author_url}}',
		'author_website'       => '{{author_website}}',
		'user_url'             => '{{author_website}}',
		'archive_date'         => '{{archive_date}}',
		'archive_title'        => '{{post_type_name}}',
		'cpt_plural'           => '{{post_type_name}}',
		'site_link'            => '{{site_name}}',
		'site_link_alt'        => '{{site_url}}',
		'blog_link'            => '{{site_name}}',
		'blog_title'           => '{{site_name}}',
		'site_description'     => '{{site_description}}',
		'post_link'            => '{{post_title}}',
		'post_link_alt'        => '{{post_url}}',
	);

	$source_overrides = array(
		'seopress' => array(
			'author_url' => '{{author_profile_url}}',
		),
	);
	if ( isset( $source_overrides[ $source ] ) ) {
		$map = array_merge( $map, $source_overrides[ $source ] );
	}

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
