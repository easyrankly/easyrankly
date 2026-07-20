<?php
/**
 * Standalone regression coverage for shared social-image alternative text.
 *
 * Run: php tests/opengraph-image-alt-contract.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress function stubs.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['erankly_alt_test_meta'] = array(
	'og_image_alt'      => 'Shared social description',
	'twitter_image_alt' => '',
);
$GLOBALS['erankly_alt_test_attachment_ids'] = array(
	'https://example.test/media/x-image.jpg' => 42,
);
$GLOBALS['erankly_alt_test_attachment_meta'] = array(
	42 => 'X attachment description',
);
$GLOBALS['erankly_alt_test_lookup_count'] = 0;

function is_singular(): bool {
	return true;
}

function get_queried_object_id(): int {
	return 101;
}

function erankly_get_post_meta_string( int $post_id, string $key ): string {
	unset( $post_id );

	return (string) ( $GLOBALS['erankly_alt_test_meta'][ $key ] ?? '' );
}

function attachment_url_to_postid( string $url ): int {
	++$GLOBALS['erankly_alt_test_lookup_count'];

	return (int) ( $GLOBALS['erankly_alt_test_attachment_ids'][ $url ] ?? 0 );
}

function wp_get_upload_dir(): array {
	return array( 'baseurl' => 'https://example.test/media' );
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function get_post_meta( int $post_id, string $key, bool $single = false ): string {
	unset( $key, $single );

	return (string) ( $GLOBALS['erankly_alt_test_attachment_meta'][ $post_id ] ?? '' );
}

function erankly_sanitize_text( mixed $value ): string {
	return trim( strip_tags( (string) $value ) );
}

require_once dirname( __DIR__ ) . '/includes/opengraph.php';

function erankly_alt_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Open Graph image alt contract failed: {$message}\n" );
		exit( 1 );
	}
}

$og_image = 'https://example.test/media/og-image.jpg';
$og_alt   = erankly_get_social_image_alt( 'og', '', $og_image );

erankly_alt_test_assert( 'Shared social description' === $og_alt, 'An explicit shared social alt must take precedence.' );
erankly_alt_test_assert( 0 === $GLOBALS['erankly_alt_test_lookup_count'], 'Explicit alt text must not trigger an attachment lookup.' );
erankly_alt_test_assert(
	'Shared social description' === erankly_get_twitter_image_alt( $og_image, $og_image, $og_alt ),
	'X must reuse the shared social alt when both networks use the same image.'
);
erankly_alt_test_assert( 0 === $GLOBALS['erankly_alt_test_lookup_count'], 'A shared X fallback must not trigger an attachment lookup.' );
erankly_alt_test_assert(
	'X attachment description' === erankly_get_twitter_image_alt( 'https://example.test/media/x-image.jpg', $og_image, $og_alt ),
	'A distinct X attachment must use its own Media Library alt text.'
);
erankly_alt_test_assert( 1 === $GLOBALS['erankly_alt_test_lookup_count'], 'A local Media Library URL must be resolved once.' );
erankly_alt_test_assert(
	'X attachment description' === erankly_get_twitter_image_alt( 'https://example.test/media/x-image.jpg', $og_image, $og_alt ),
	'A repeated local image must reuse the request cache.'
);
erankly_alt_test_assert( 1 === $GLOBALS['erankly_alt_test_lookup_count'], 'The request cache must prevent duplicate attachment lookups.' );
erankly_alt_test_assert(
	'' === erankly_get_twitter_image_alt( 'https://cdn.example.test/x-image.jpg', $og_image, $og_alt ),
	'A distinct external X image must not inherit an inaccurate shared alt.'
);
erankly_alt_test_assert( 1 === $GLOBALS['erankly_alt_test_lookup_count'], 'A remote image must not trigger a Media Library database lookup.' );

$GLOBALS['erankly_alt_test_meta']['twitter_image_alt'] = 'Explicit X description';

erankly_alt_test_assert(
	'Explicit X description' === erankly_get_twitter_image_alt( 'https://cdn.example.test/x-image.jpg', $og_image, $og_alt ),
	'An explicit X override must take precedence for a distinct image.'
);

fwrite( STDOUT, "Open Graph image alt contract passed.\n" );
