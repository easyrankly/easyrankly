<?php
// phpcs:ignoreFile -- Standalone migration fixture harness.
/**
 * Dependency-free Phase 2 adapter smoke tests.
 *
 * Run: php tests/phase2-migration-smoke.php
 *
 * @package EasyRankly
 */

// phpcs:disable -- Standalone fixture harness intentionally supplies small WordPress stubs.

define( 'ABSPATH', __DIR__ . '/' );
define( 'ERANKLY_PATH', dirname( __DIR__ ) . '/' );

class WP_Term {
	public int $term_id;
	public string $taxonomy;
	public function __construct( int $term_id, string $taxonomy ) {
		$this->term_id  = $term_id;
		$this->taxonomy = $taxonomy;
	}
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_textarea_field( $value ) {
	return sanitize_text_field( $value );
}
function absint( $value ) {
	return abs( (int) $value );
}
function maybe_unserialize( $value ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}
	$result = @unserialize( $value );
	return false === $result && 'b:0;' !== $value ? $value : $result;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_unslash( $value ) {
	return stripslashes( (string) $value );
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}
function esc_url_raw( $value ) {
	return filter_var( $value, FILTER_VALIDATE_URL ) ? (string) $value : '';
}
function home_url( $path = '' ) {
	return 'https://example.test' . (string) $path;
}
function wp_http_validate_url( $value ) {
	return false !== filter_var( $value, FILTER_VALIDATE_URL );
}
function wp_slash( $value ) {
	return is_array( $value ) ? array_map( 'wp_slash', $value ) : addslashes( (string) $value );
}
function get_term( $term_id ) {
	return (int) $term_id > 0 ? new WP_Term( (int) $term_id, 99 === (int) $term_id ? 'product_cat' : 'category' ) : null;
}
function get_post_meta( $post_id, $key, $single = false ) {
	unset( $key, $single );
	return 42 === (int) $post_id ? 'Open Graph attachment alt' : ( 43 === (int) $post_id ? 'X attachment alt' : '' );
}
function erankly_get_meta_keys() {
	return array(
		'_erankly_title'       => array(),
		'_erankly_description' => array(),
		'_erankly_canonical'   => array(),
	);
}
function erankly_sanitize_registered_meta( $value, $key ) {
	unset( $key );
	return is_string( $value ) ? trim( $value ) : $value;
}
function metadata_exists( $object_type, $object_id, $key ) {
	return array_key_exists( $object_type . ':' . $object_id . ':' . $key, $GLOBALS['erankly_phase2_metadata'] );
}
function update_metadata( $object_type, $object_id, $key, $value ) {
	$GLOBALS['erankly_phase2_metadata'][ $object_type . ':' . $object_id . ':' . $key ] = $value;
	return true;
}
function wp_generate_uuid4() {
	return '11111111-2222-4333-8444-555555555555';
}
function get_option( $name, $default = false ) {
	return $GLOBALS['erankly_phase2_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['erankly_phase2_options'][ $name ] = $value;
	return true;
}
function wp_raise_memory_limit( $context ) {
	unset( $context );
}
require_once dirname( __DIR__ ) . '/includes/import-export.php';
require_once dirname( __DIR__ ) . '/includes/redirects/class-erankly-redirects-normalizer.php';
erankly_migration_load_all_adapters();

function erankly_phase2_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function erankly_phase2_invoke( object $object, string $method, array $arguments ) {
	$reflection = new ReflectionMethod( $object, $method );
	if ( PHP_VERSION_ID < 80500 ) {
		$reflection->setAccessible( true );
	}
	return $reflection->invokeArgs( $object, $arguments );
}

final class ERankly_Phase2_Failing_Adapter extends ERankly_Migration_Adapter {
	public function slug(): string {
		return 'failing';
	}
	public function label(): string {
		return 'Failing fixture';
	}
	public function version(): string {
		return '1.0';
	}
	public function is_available(): bool {
		return true;
	}
	public function content_records(): iterable {
		throw new RuntimeException( 'Fixture interruption.' );
		yield array();
	}
}

$yoast = new ERankly_Migration_Adapter_Yoast();
$yoast_meta = array(
	'_yoast_wpseo_title' => '%%title%% - %%sitename%% - %%currentyear%%',
	'_yoast_wpseo_opengraph-image' => 'https://example.test/og.jpg',
	'_yoast_wpseo_opengraph-image-id' => '42',
	'_yoast_wpseo_twitter-image' => 'https://example.test/twitter.jpg',
	'_yoast_wpseo_twitter-image-id' => '43',
	'_yoast_wpseo_meta-robots-noindex' => '2',
	'_yoast_wpseo_meta-robots-adv' => 'nosnippet,noimageindex',
	'_yoast_wpseo_focuskw' => 'main phrase',
	'_yoast_wpseo_focuskeywords' => json_encode( array( array( 'keyword' => 'secondary phrase' ) ) ),
	'_yoast_wpseo_is_cornerstone' => '1',
	'_yoast_wpseo_primary_category' => '12',
	'_yoast_wpseo_schema_page_type' => 'AboutPage',
	'_yoast_wpseo_schema_article_type' => 'NewsArticle',
);
$mapped = erankly_phase2_invoke( $yoast, 'map_meta', array( $yoast_meta, false ) );
erankly_phase2_assert( '{{post_title}} - {{site_name}} - {{current_year}}' === $mapped['_erankly_title'], 'Yoast variables are converted without freezing the current year.' );
erankly_phase2_assert( $mapped['_erankly_og_image_url'] !== $mapped['_erankly_twitter_image_url'], 'Yoast OG and Twitter images stay separate.' );
erankly_phase2_assert( 'Open Graph attachment alt' === $mapped['_erankly_og_image_alt'] && 'X attachment alt' === $mapped['_erankly_twitter_image_alt'], 'Yoast attachment alt text stays separate by social network.' );
erankly_phase2_assert( 'index' === $mapped['_erankly_index_directive'], 'Yoast explicit index is preserved.' );
erankly_phase2_assert( 'nosnippet' === $mapped['_erankly_snippet_directive'], 'Yoast advanced robots are preserved.' );
erankly_phase2_assert( 2 === count( $mapped['_erankly_focus_keywords'] ), 'Yoast Premium keyphrases are combined.' );
erankly_phase2_assert( true === $mapped['_erankly_cornerstone'], 'Yoast cornerstone state is preserved.' );
erankly_phase2_assert( 'merge' === $mapped['_erankly_schema_mode'] && 2 === count( $mapped['_erankly_schema_blocks'] ), 'Yoast page and article schema selections become runtime templates.' );

erankly_import_variable_diagnostics( null, true );
$converted = erankly_import_convert_variables( '%%title%% %%unsupported_example%%', 'yoast' );
erankly_phase2_assert( '{{post_title}}' === $converted, 'Unsupported source variables are removed from rendered templates.' );
erankly_phase2_assert( 1 === count( erankly_import_variable_diagnostics() ), 'Unsupported source variables are included in the migration diagnostics.' );
$aio_hashtag = erankly_import_convert_variables( 'News #SEO', 'aioseo' );
erankly_phase2_assert( 'News #SEO' === $aio_hashtag, 'Unrecognized AIOSEO hash tokens are preserved because they may be literal hashtags.' );

$yoast_redirect = erankly_phase2_invoke( $yoast, 'redirect_from_values', array( '/old?a=1', '/new', 308, false, 'premium-base:1' ) );
erankly_phase2_assert( 'exact' === $yoast_redirect['match_type'] && 'exact' === $yoast_redirect['query_mode'] && 'a=1' === $yoast_redirect['source_query'], 'Yoast Premium redirect query behavior and 308 status are retained.' );
$prepared_redirect = erankly_import_prepare_redirect( $yoast_redirect );
erankly_phase2_assert( '/old' === $prepared_redirect['source_path'] && 'a=1' === $prepared_redirect['source_query'] && 308 === $prepared_redirect['status_code'], 'Source redirects are normalized without losing exact query matching.' );
erankly_phase2_assert(
	null === erankly_import_prepare_redirect(
		array(
			'source_path' => '[unterminated',
			'target_url'  => '/safe',
			'match_type'  => 'regex',
			'status_code' => 301,
		)
	),
	'Import must reject invalid regex sources the same way the Redirects admin does.'
);
erankly_phase2_assert(
	null === erankly_import_prepare_redirect(
		array(
			'source_path' => str_repeat( 'a', 513 ),
			'target_url'  => '/safe',
			'match_type'  => 'regex',
			'status_code' => 301,
		)
	),
	'Import must reject regex sources longer than the Redirects admin limit.'
);
erankly_phase2_assert(
	null === erankly_import_prepare_redirect(
		array(
			'source_path' => 'missing-star',
			'target_url'  => '/safe',
			'match_type'  => 'wildcard',
			'status_code' => 301,
		)
	),
	'Import must reject wildcard sources that fail the Redirects admin validator.'
);
$redirect_hash_manager = new ERankly_Migration_Manager();
$same_behavior         = array_merge( $prepared_redirect, array( 'source_reference' => 'another-source', 'migration_id' => 'another-run' ) );
$different_behavior    = array_merge( $prepared_redirect, array( 'target_url' => '/different-target' ) );
erankly_phase2_assert( $redirect_hash_manager->redirect_value_hash( $prepared_redirect ) === $redirect_hash_manager->redirect_value_hash( $same_behavior ), 'Redirect duplicate detection ignores provenance fields.' );
erankly_phase2_assert( $redirect_hash_manager->redirect_value_hash( $prepared_redirect ) !== $redirect_hash_manager->redirect_value_hash( $different_behavior ), 'Redirect source conflicts distinguish different behavior for the same match identity.' );

$rankmath = new ERankly_Migration_Adapter_RankMath();
$rank_meta = array(
	'rank_math_facebook_image' => 'https://example.test/facebook.jpg',
	'rank_math_twitter_use_facebook' => 'on',
	'rank_math_robots' => serialize( array( 'noindex', 'nofollow', 'noarchive' ) ),
	'rank_math_advanced_robots' => serialize( array( 'max-snippet' => '120', 'max-image-preview' => 'large' ) ),
	'rank_math_focus_keyword' => 'alpha,beta',
	'rank_math_pillar_content' => 'on',
	'rank_math_primary_product_cat' => '99',
	'rank_math_schema_Product' => array( '@type' => 'Product', 'name' => '%seo_title%', 'metadata' => array( 'type' => 'template' ) ),
);
$mapped = erankly_phase2_invoke( $rankmath, 'map_meta', array( $rank_meta, 'post', 7 ) );
erankly_phase2_assert( $mapped['_erankly_twitter_image_url'] === $mapped['_erankly_og_image_url'], 'Rank Math Twitter-use-Facebook behavior is retained.' );
erankly_phase2_assert( '120' === $mapped['_erankly_max_snippet'], 'Rank Math advanced robots are retained.' );
erankly_phase2_assert( 99 === $mapped['_erankly_primary_terms']['product_cat'], 'Rank Math primary taxonomy is retained.' );
erankly_phase2_assert( false === strpos( $mapped['_erankly_schema_blocks'][0]['fields']['custom_json'], 'metadata' ), 'Rank Math editor-only schema metadata is not emitted.' );

$aioseo = new ERankly_Migration_Adapter_AIOSEO();
$aio_row = array(
	'id' => 3, 'post_id' => 7, 'title' => '#post_title', 'og_title' => 'Open title', 'og_image_custom_url' => 'https://example.test/aio.jpg',
	'twitter_use_og' => 1, 'twitter_card' => 'summary_large_image', 'robots_default' => 0, 'robots_noindex' => 1,
	'robots_nofollow' => 0, 'robots_noarchive' => 1, 'robots_nosnippet' => 0, 'robots_noimageindex' => 1,
	'robots_max_snippet' => 90, 'robots_max_videopreview' => -1, 'robots_max_imagepreview' => 'standard',
	'keyphrases' => json_encode( array( 'focus' => array( 'keyphrase' => 'focus' ), 'additional' => array( array( 'keyphrase' => 'extra' ) ) ) ),
	'primary_term' => json_encode( array( 'category' => 12 ) ), 'pillar_content' => 1,
	'schema_type' => 'Product', 'schema_type_options' => json_encode( array( 'product' => array( 'name' => '#post_title', 'description' => '#post_excerpt' ) ) ),
);
$mapped = erankly_phase2_invoke( $aioseo, 'map_row', array( $aio_row, 'post', 7 ) );
erankly_phase2_assert( 'noindex' === $mapped['_erankly_index_directive'] && 'follow' === $mapped['_erankly_follow_directive'], 'AIOSEO custom robots preserve positive and negative directives.' );
erankly_phase2_assert( $mapped['_erankly_twitter_image_url'] === $mapped['_erankly_og_image_url'], 'AIOSEO Twitter-use-OG behavior is retained.' );
erankly_phase2_assert( 2 === count( $mapped['_erankly_focus_keywords'] ), 'AIOSEO focus and additional keyphrases are imported.' );
erankly_phase2_assert( ! empty( $mapped['_erankly_schema_blocks'] ), 'AIOSEO legacy schema options produce a schema block.' );
erankly_phase2_assert( 'preserve' === erankly_phase2_invoke( $aioseo, 'aioseo_query_mode', array( 'pass', '' ) ), 'AIOSEO pass-through query handling is retained.' );

$seopress = new ERankly_Migration_Adapter_SEOPress();
$seopress_meta = array(
	'_seopress_titles_title' => '%%post_title%% - %%sitetitle%%',
	'_seopress_robots_index' => 'yes', '_seopress_robots_follow' => 'yes', '_seopress_robots_snippet' => 'yes',
	'_seopress_social_fb_img' => 'https://example.test/fb.jpg', '_seopress_social_twitter_img' => 'https://example.test/x.jpg',
	'_seopress_analysis_target_kw' => 'one,two', '_seopress_robots_primary_cat' => 12,
	'_seopress_pro_rich_snippets_type' => 'events', '_seopress_pro_rich_snippets_events_name' => 'Launch',
	'_seopress_pro_rich_snippets_events_start_date' => '2026-08-01',
);
$mapped = erankly_phase2_invoke( $seopress, 'map_meta', array( $seopress_meta, 'post', 7 ) );
erankly_phase2_assert( '{{post_title}} - {{site_name}}' === $mapped['_erankly_title'], 'SEOPress variables are converted.' );
erankly_phase2_assert( 'noindex' === $mapped['_erankly_index_directive'] && 'nofollow' === $mapped['_erankly_follow_directive'], 'SEOPress robots flags are imported.' );
erankly_phase2_assert( $mapped['_erankly_og_image_url'] !== $mapped['_erankly_twitter_image_url'], 'SEOPress social images stay separate.' );
erankly_phase2_assert( ! empty( $mapped['_erankly_schema_blocks'] ), 'SEOPress PRO legacy schema is retained as JSON-LD.' );

$manager = new ERankly_Migration_Manager();
$method  = new ReflectionMethod( $manager, 'apply_meta_record' );
if ( PHP_VERSION_ID < 80500 ) {
	$method->setAccessible( true );
}
$counts                           = erankly_phase2_invoke( $manager, 'empty_counts', array() );
$report                           = array( 'counts' => $counts, 'details' => array() );
$planned                          = array();
$GLOBALS['erankly_phase2_metadata'] = array( 'post:7:_erankly_title' => 'Keep me' );
$mapped_meta                      = array(
	'_erankly_title'       => 'Do not replace',
	'_erankly_description' => 'Ready in preview',
);
$arguments                        = array( 'post', 7, $mapped_meta, 'post:7', true, &$planned, &$report );
$method->invokeArgs( $manager, $arguments );
erankly_phase2_assert( 1 === $report['counts']['fields_skipped_existing'], 'Existing EasyRankly metadata is preserved.' );
erankly_phase2_assert( 1 === $report['counts']['fields_ready'] && 0 === $report['counts']['fields_written'], 'Preview validates fields without writing them.' );
erankly_phase2_assert( ! isset( $GLOBALS['erankly_phase2_metadata']['post:7:_erankly_description'] ), 'Preview leaves storage untouched.' );

$duplicate_arguments = array( 'post', 7, array( '_erankly_description' => 'Ready in preview' ), 'v3-post:7', true, &$planned, &$report );
$method->invokeArgs( $manager, $duplicate_arguments );
erankly_phase2_assert( 1 === $report['counts']['fields_duplicate'], 'Repeated source values are deduplicated.' );

$conflict_arguments = array( 'post', 7, array( '_erankly_description' => 'Different source value' ), 'legacy-post:7', true, &$planned, &$report );
$method->invokeArgs( $manager, $conflict_arguments );
erankly_phase2_assert( 1 === $report['counts']['fields_conflicts'], 'Conflicting source values are reported without overwriting the first value.' );

$write_report    = array( 'counts' => erankly_phase2_invoke( $manager, 'empty_counts', array() ), 'details' => array() );
$write_planned   = array();
$write_arguments = array( 'post', 8, array( '_erankly_canonical' => 'https://example.test/canonical', '_erankly_description' => 'Regex \\d+ remains exact' ), 'post:8', false, &$write_planned, &$write_report );
$method->invokeArgs( $manager, $write_arguments );
erankly_phase2_assert( 2 === $write_report['counts']['fields_written'] && 2 === $write_report['counts']['post_fields_written'], 'Real imports record successful writes by object type.' );
erankly_phase2_assert( 'Regex \\d+ remains exact' === $GLOBALS['erankly_phase2_metadata']['post:8:_erankly_description'], 'Generic metadata writes preserve literal backslashes.' );

$failing_manager  = new ERankly_Migration_Manager();
$adapters_property = new ReflectionProperty( $failing_manager, 'adapters' );
if ( PHP_VERSION_ID < 80500 ) {
	$adapters_property->setAccessible( true );
}
$adapters_property->setValue( $failing_manager, array( 'failing' => new ERankly_Phase2_Failing_Adapter() ) );
$failed_report = $failing_manager->run( 'failing', true );
erankly_phase2_assert( 'failed' === $failed_report['status'] && 'migration_interrupted' === $failed_report['warnings'][0]['code'], 'Unexpected source records produce a persisted failed report instead of a fatal request.' );

fwrite( STDOUT, "Phase 2 migration adapter smoke tests passed.\n" );

// phpcs:enable
