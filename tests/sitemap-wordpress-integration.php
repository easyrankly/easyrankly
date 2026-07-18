<?php
// phpcs:ignoreFile -- WP-CLI integration harness mutates an ephemeral test site.
/**
 * Real WordPress regression coverage for sitemap visibility and social images.
 *
 * Run inside a fresh WordPress installation with EasyRankly active:
 * wp eval-file wp-content/plugins/easyrankly/tests/sitemap-wordpress-integration.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

/**
 * Fails the integration test when a sitemap invariant is not satisfied.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @return void
 */
function erankly_sitemap_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

erankly_load_sitemap_helpers();
erankly_load_content_helpers();
require_once ERANKLY_PATH . 'includes/sitemap/core.php';
require_once ERANKLY_PATH . 'includes/sitemap/image.php';
require_once ERANKLY_PATH . 'includes/sitemap/video.php';
require_once ERANKLY_PATH . 'includes/sitemap/news.php';

$original_settings = erankly_get_stored_settings();
$test_settings     = array_merge(
	$original_settings,
	array(
		'enable_sitemap'       => 1,
		'enable_image_sitemap' => 1,
		'enable_video_sitemap' => 1,
		'enable_news_sitemap'  => 1,
		'news_sitemap_post_types' => array( 'post' ),
		'news_publication_name'   => 'EasyRankly Sitemap Test',
	)
);
erankly_update_plugin_option( ERANKLY_OPTION, $test_settings );
erankly_clear_settings_cache();
erankly_flush_sitemap_cache();

$video_markup = '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>';
$post_ids     = array();
$term_ids     = array();

$create_post = static function ( string $title ) use ( $video_markup, &$post_ids ): int {
	$post_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => '<p>Sitemap fixture.</p>' . $video_markup,
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);

	erankly_sitemap_wp_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Every sitemap fixture post must be created.' );
	$post_ids[] = (int) $post_id;

	return (int) $post_id;
};

$visible_post         = $create_post( 'Visible sitemap fixture' );
$canonical_noindex    = $create_post( 'Canonical noindex sitemap fixture' );
$legacy_noindex       = $create_post( 'Legacy noindex sitemap fixture' );
$explicit_index       = $create_post( 'Explicit index sitemap fixture' );
$sitemap_disabled     = $create_post( 'Explicit sitemap exclusion fixture' );
$visible_og_url       = 'https://cdn.example.test/visible-og.jpg';
$visible_twitter_url  = 'https://cdn.example.test/visible-twitter.jpg';
$hidden_image_url     = 'https://cdn.example.test/hidden-noindex.jpg';
$legacy_image_url     = 'https://cdn.example.test/hidden-legacy.jpg';
$explicit_image_url   = 'https://cdn.example.test/explicit-index.jpg';
$disabled_image_url   = 'https://cdn.example.test/disabled.jpg';

update_post_meta( $visible_post, '_erankly_og_image_url', $visible_og_url );
update_post_meta( $visible_post, '_erankly_twitter_image_url', $visible_twitter_url );
update_post_meta( $canonical_noindex, '_erankly_index_directive', 'noindex' );
update_post_meta( $canonical_noindex, '_erankly_og_image_url', $hidden_image_url );
update_post_meta( $legacy_noindex, '_erankly_noindex', '1' );
update_post_meta( $legacy_noindex, '_erankly_og_image_url', $legacy_image_url );
update_post_meta( $explicit_index, '_erankly_index_directive', 'index' );
update_post_meta( $explicit_index, '_erankly_noindex', '1' );
update_post_meta( $explicit_index, '_erankly_og_image_url', $explicit_image_url );
update_post_meta( $sitemap_disabled, '_erankly_disable_sitemap', '1' );
update_post_meta( $sitemap_disabled, '_erankly_og_image_url', $disabled_image_url );

$eligible_posts = new WP_Query(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'post__in'       => $post_ids,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => erankly_get_sitemap_exclusion_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- This is the behavior under test.
	)
);
$eligible_post_ids = array_map( 'intval', $eligible_posts->posts );

erankly_sitemap_wp_assert( in_array( $visible_post, $eligible_post_ids, true ), 'An unblocked post must remain eligible.' );
erankly_sitemap_wp_assert( in_array( $explicit_index, $eligible_post_ids, true ), 'Canonical index must override a stale legacy noindex flag.' );
erankly_sitemap_wp_assert( ! in_array( $canonical_noindex, $eligible_post_ids, true ), 'Canonical noindex must exclude a post without relying on the legacy flag.' );
erankly_sitemap_wp_assert( ! in_array( $legacy_noindex, $eligible_post_ids, true ), 'Legacy-only noindex metadata must remain supported.' );
erankly_sitemap_wp_assert( ! in_array( $sitemap_disabled, $eligible_post_ids, true ), 'Explicit sitemap exclusion must remain supported.' );

$create_term = static function ( string $name ) use ( &$term_ids ): int {
	$term = wp_insert_term( $name, 'category' );
	erankly_sitemap_wp_assert( ! is_wp_error( $term ) && ! empty( $term['term_id'] ), 'Every sitemap fixture term must be created.' );
	$term_ids[] = (int) $term['term_id'];

	return (int) $term['term_id'];
};

$visible_term      = $create_term( 'Visible sitemap term fixture' );
$noindex_term      = $create_term( 'Canonical noindex sitemap term fixture' );
$legacy_term       = $create_term( 'Legacy noindex sitemap term fixture' );
$explicit_term     = $create_term( 'Explicit index sitemap term fixture' );
update_term_meta( $noindex_term, '_erankly_index_directive', 'noindex' );
update_term_meta( $legacy_term, '_erankly_noindex', '1' );
update_term_meta( $explicit_term, '_erankly_index_directive', 'index' );
update_term_meta( $explicit_term, '_erankly_noindex', '1' );

$eligible_term_ids = get_terms(
	array(
		'taxonomy'   => 'category',
		'include'    => $term_ids,
		'hide_empty' => false,
		'fields'     => 'ids',
		'meta_query' => erankly_get_sitemap_term_exclusion_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- This is the behavior under test.
	)
);
erankly_sitemap_wp_assert( ! is_wp_error( $eligible_term_ids ), 'The sitemap term query must complete.' );
$eligible_term_ids = array_map( 'intval', $eligible_term_ids );

erankly_sitemap_wp_assert( in_array( $visible_term, $eligible_term_ids, true ), 'An unblocked term must remain eligible.' );
erankly_sitemap_wp_assert( in_array( $explicit_term, $eligible_term_ids, true ), 'Canonical term index must override a stale legacy flag.' );
erankly_sitemap_wp_assert( ! in_array( $noindex_term, $eligible_term_ids, true ), 'Canonical noindex must exclude a term.' );
erankly_sitemap_wp_assert( ! in_array( $legacy_term, $eligible_term_ids, true ), 'Legacy-only term noindex metadata must remain supported.' );

$social_images = erankly_get_sitemap_images( $visible_post );
erankly_sitemap_wp_assert( in_array( $visible_og_url, $social_images, true ), 'The separate Open Graph URL must enter the image sitemap.' );
erankly_sitemap_wp_assert( in_array( $visible_twitter_url, $social_images, true ), 'The separate Twitter URL must enter the image sitemap.' );

update_post_meta( $visible_post, '_erankly_twitter_image_url', $visible_og_url );
$deduplicated_images = erankly_get_sitemap_images( $visible_post );
erankly_sitemap_wp_assert( 1 === count( array_keys( $deduplicated_images, $visible_og_url, true ) ), 'Identical OG and Twitter URLs must be emitted only once.' );
update_post_meta( $visible_post, '_erankly_twitter_image_url', $visible_twitter_url );

$image_xml = erankly_get_image_sitemap_xml();
erankly_sitemap_wp_assert( str_contains( $image_xml, esc_xml( $visible_og_url ) ) && str_contains( $image_xml, esc_xml( $visible_twitter_url ) ), 'The image sitemap must emit both separate social URLs.' );
erankly_sitemap_wp_assert( str_contains( $image_xml, esc_xml( $explicit_image_url ) ), 'The image sitemap SQL path must honor canonical index precedence.' );
erankly_sitemap_wp_assert( ! str_contains( $image_xml, esc_xml( $hidden_image_url ) ), 'The image sitemap SQL path must exclude canonical noindex.' );
erankly_sitemap_wp_assert( ! str_contains( $image_xml, esc_xml( $legacy_image_url ) ), 'The image sitemap SQL path must retain legacy noindex compatibility.' );
erankly_sitemap_wp_assert( ! str_contains( $image_xml, esc_xml( $disabled_image_url ) ), 'The image sitemap SQL path must retain explicit sitemap exclusion.' );

$visible_permalink  = get_permalink( $visible_post );
$noindex_permalink  = get_permalink( $canonical_noindex );
$legacy_permalink   = get_permalink( $legacy_noindex );
$explicit_permalink = get_permalink( $explicit_index );
$disabled_permalink = get_permalink( $sitemap_disabled );
$video_xml          = erankly_get_video_sitemap_xml();
$news_xml           = erankly_get_news_sitemap_xml();

foreach ( array( 'video' => $video_xml, 'news' => $news_xml ) as $type => $xml ) {
	erankly_sitemap_wp_assert( str_contains( $xml, esc_xml( (string) $visible_permalink ) ), ucfirst( $type ) . ' sitemap must retain an eligible post.' );
	erankly_sitemap_wp_assert( str_contains( $xml, esc_xml( (string) $explicit_permalink ) ), ucfirst( $type ) . ' sitemap must honor canonical index precedence.' );
	erankly_sitemap_wp_assert( ! str_contains( $xml, esc_xml( (string) $noindex_permalink ) ), ucfirst( $type ) . ' sitemap must exclude canonical noindex.' );
	erankly_sitemap_wp_assert( ! str_contains( $xml, esc_xml( (string) $legacy_permalink ) ), ucfirst( $type ) . ' sitemap must retain legacy noindex compatibility.' );
	erankly_sitemap_wp_assert( ! str_contains( $xml, esc_xml( (string) $disabled_permalink ) ), ucfirst( $type ) . ' sitemap must retain explicit sitemap exclusion.' );
}

foreach ( $post_ids as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $term_ids as $term_id ) {
	wp_delete_term( $term_id, 'category' );
}
erankly_update_plugin_option( ERANKLY_OPTION, $original_settings );
erankly_clear_settings_cache();
erankly_flush_sitemap_cache();

WP_CLI::success( 'Sitemap visibility and separate social-image integration passed.' );
