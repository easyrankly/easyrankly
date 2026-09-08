<?php
/** Per-section external documentation links. Missing sections intentionally render no link. */
defined( 'ABSPATH' ) || exit;
function erankly_section_doc_links(): array {
	return apply_filters(
		'erankly_section_doc_links',
		array(
			'search-engines'           => 'https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data',
			'custom-schema'            => 'https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data',
			'xml-sitemap'              => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview',
			'news-sitemap'             => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap',
			'image-sitemap'            => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps',
			'video-sitemap'            => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps',
			'editor-schema'            => 'https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data',
		)
	);
}
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
