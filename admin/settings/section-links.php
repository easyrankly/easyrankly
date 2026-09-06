<?php
/**
 * Per-section documentation links. Each settings section title gets a "Learn more"
 * anchor on the opposite side that points to the section's documentation page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the documentation URL for every settings section, keyed by section slug.
 * Leave a value empty to omit the link until its documentation exists.
 *
 * @return array<string,string> Map of section key to documentation URL.
 */
function erankly_section_doc_links(): array {
	/**
	 * Filters the per-section documentation URLs rendered next to each section title.
	 *
	 * @param array<string,string> $urls Map of section key to documentation URL.
	 */
	return apply_filters(
		'erankly_section_doc_links',
		array(
			'feature-modules'          => '',
			'site-identity'            => '',
			'post-type-defaults'       => '',
			'taxonomy-defaults'        => '',
			'special-pages'            => '',
			'default-images'           => '',
			'social-defaults'          => '',
			'social-profiles'          => '',
			'search-engines'           => '',
			'custom-schema'            => '',
			'xml-sitemap'              => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview',
			'news-sitemap'             => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap',
			'image-sitemap'            => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps',
			'video-sitemap'            => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps',
			'preferences'              => '',
			'indexing-robots'          => '',
			'robots-txt'               => '',
			'pagination'               => '',
			'attachment-pages'         => '',
			'editor-search-appearance' => '',
			'editor-social'            => '',
			'editor-visibility'        => '',
			'editor-schema'            => '',
			'term-meta'                => '',
			'redirect-form'            => '',
			'redirect-rules'           => '',
			'custom-code'              => '',
			'export'                   => '',
			'import'                   => '',
			'import-other-plugins'     => '',
			'migration-assistant'      => '',
			'reset'                    => '',
		)
	);
}

/**
 * Renders the "Learn more" documentation link for a settings section. Printed on the
 * opposite side of the section title inside a `.erankly-section-title-row` wrapper.
 *
 * @param string $section Section key as defined in erankly_section_doc_links().
 */
function erankly_render_section_doc_link( string $section ): void {
	$urls = erankly_section_doc_links();
	$url  = (string) ( $urls[ $section ] ?? '' );
	if ( '' === $url ) {
		return;
	}

	printf(
		'<a class="erankly-section-doc-link" href="%1$s" data-erankly-doc-section="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
		esc_url( $url ),
		esc_attr( $section ),
		esc_html__( 'Learn more', 'easyrankly' )
	);
}
