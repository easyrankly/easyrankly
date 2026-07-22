<?php
// phpcs:ignoreFile -- WP-CLI characterization harness with intentionally compact helpers.
/**
 * Shared M1 characterization harness.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run the multilingual contract through WP-CLI.' );
}

if ( ! is_multisite() ) {
	throw new RuntimeException( 'The multilingual contract requires WordPress Multisite.' );
}

final class ERankly_ML_Contract_Result {
	private string $suite;
	private int $passed = 0;
	private array $failures = array();

	public function __construct( string $suite ) {
		$this->suite = $suite;
	}

	public function check( bool $condition, string $id, string $message, array $context = array() ): void {
		if ( $condition ) {
			++$this->passed;
			return;
		}

		$this->failures[] = array(
			'id'      => $id,
			'message' => $message,
			'context' => $context,
		);
	}

	public function same( mixed $expected, mixed $actual, string $id, string $message ): void {
		$this->check(
			$expected === $actual,
			$id,
			$message,
			array( 'expected' => $expected, 'actual' => $actual )
		);
	}

	public function failure_count(): int {
		return count( $this->failures );
	}

	public function summary(): array {
		return array(
			'suite'    => $this->suite,
			'provider' => erankly_ml_contract_provider_name(),
			'passed'   => $this->passed,
			'failed'   => count( $this->failures ),
			'failures' => $this->failures,
		);
	}

	public function finish(): void {
		$summary = $this->summary();
		$ids     = array_column( $this->failures, 'id' );
		sort( $ids, SORT_STRING );

		if ( $ids ) {
			fwrite( STDOUT, 'ERANKLY_ML_CONTRACT_FAILURE_IDS=' . implode( ',', $ids ) . "\n" );
		}

		fwrite( STDOUT, wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		if ( $this->failures ) {
			WP_CLI::error( sprintf( '%s failed with %d assertion(s).', $this->suite, count( $this->failures ) ) );
		}

		WP_CLI::success( sprintf( '%s passed (%d assertions).', $this->suite, $this->passed ) );
	}
}

interface ERankly_ML_Contract_Driver {
	public function id(): string;
	public function save_site_map( array $map ): void;
	public function site_map(): array;
	public function default_blog_id(): int;
	public function locale_fallback( int $blog_id, string $locale ): string;
	public function link( int $group_id, int $blog_id, string $object_type, int $object_id ): int;
	public function unlink( int $blog_id, string $object_type, int $object_id ): void;
	public function find_group_id( int $blog_id, string $object_type, int $object_id ): int;
	public function group_for( int $blog_id, string $object_type, int $object_id ): array;
	public function seo_alternates(): array;
	public function navigable_alternates(): array;
	public function render_hreflang(): string;
	public function render_full_head(): string;
	public function render_shortcode( string $surface ): string;
	public function frontend_contract(): array;
	public function rest_contract(): array;
	public function attempt_cross_site_post_link( int $source_post_id, int $target_blog_id, int $target_post_id ): void;
	public function storage_descriptor(): array;
	public function ownership_snapshot(): array;
}

function erankly_ml_contract_manifest(): array {
	return require __DIR__ . '/manifest.php';
}

function erankly_ml_contract_snapshot(): array {
	return require __DIR__ . '/snapshots/legacy-baseline.php';
}

function erankly_ml_contract_provider_name(): string {
	$name = sanitize_key( (string) getenv( 'ERANKLY_ML_CONTRACT_PROVIDER' ) );
	$name = '' !== $name ? $name : 'bundled';

	if ( ! in_array( $name, array( 'bundled', 'addon' ), true ) ) {
		throw new RuntimeException( 'Unsupported M1 contract provider: ' . $name );
	}

	return $name;
}

function erankly_ml_contract_driver(): ERankly_ML_Contract_Driver {
	static $driver = null;

	if ( $driver instanceof ERankly_ML_Contract_Driver ) {
		return $driver;
	}

	$adapter_file = (string) getenv( 'ERANKLY_ML_CONTRACT_DRIVER_FILE' );
	if ( '' !== $adapter_file ) {
		if ( ! is_file( $adapter_file ) ) {
			throw new RuntimeException( 'The requested provider contract driver file does not exist.' );
		}
		require_once $adapter_file;
	}

	$provider = erankly_ml_contract_provider_name();
	$driver   = apply_filters( 'erankly_multilingual_contract_driver', null, $provider );

	if ( ! $driver && 'bundled' === $provider ) {
		require_once __DIR__ . '/class-bundled-driver.php';
		$driver = new ERankly_ML_Contract_Bundled_Driver();
	}

	if ( ! $driver instanceof ERankly_ML_Contract_Driver ) {
		throw new RuntimeException( 'No M1 contract driver is registered for provider: ' . $provider );
	}

	$provider_ids = erankly_ml_contract_manifest()['provider_ids'] ?? array();
	$expected_id  = (string) ( $provider_ids[ $provider ] ?? '' );
	if ( '' === $expected_id || $expected_id !== $driver->id() ) {
		throw new RuntimeException( 'The M1 contract driver returned an invalid provider ID.' );
	}

	return $driver;
}

function erankly_ml_contract_sorted_keys( array $map ): array {
	$keys = array_map( 'strval', array_keys( $map ) );
	sort( $keys, SORT_STRING );
	return $keys;
}

function erankly_ml_contract_set_post_query( int $post_id ): void {
	global $post, $wp_query, $wp_the_query;

	$post_type    = (string) get_post_type( $post_id );
	$wp_query     = new WP_Query( array( 'p' => $post_id, 'post_type' => '' !== $post_type ? $post_type : 'any' ) );
	$wp_the_query = $wp_query;
	$post         = get_post( $post_id );

	if ( $post instanceof WP_Post ) {
		setup_postdata( $post );
	}
}

function erankly_ml_contract_set_term_query( int $term_id, string $taxonomy ): void {
	global $wp_query, $wp_the_query;

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term instanceof WP_Term ) {
		throw new RuntimeException( 'Unable to create a term request context.' );
	}

	$wp_query              = new WP_Query();
	$wp_query->is_archive  = true;
	$wp_query->is_category = 'category' === $taxonomy;
	$wp_query->is_tag      = 'post_tag' === $taxonomy;
	$wp_query->is_tax      = ! in_array( $taxonomy, array( 'category', 'post_tag' ), true );
	$wp_query->queried_object    = $term;
	$wp_query->queried_object_id = $term_id;
	$wp_the_query = $wp_query;
}

function erankly_ml_contract_set_home_query(): void {
	global $wp_query, $wp_the_query;

	update_option( 'show_on_front', 'posts' );
	$wp_query          = new WP_Query();
	$wp_query->is_home = true;
	$wp_the_query      = $wp_query;
}

function erankly_ml_contract_count_callbacks( string $tag, callable $predicate ): int {
	global $wp_filter;

	$hook = $wp_filter[ $tag ] ?? null;
	if ( ! $hook instanceof WP_Hook ) {
		return 0;
	}

	$count = 0;
	foreach ( $hook->callbacks as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$callback = $entry['function'] ?? null;
			if ( $predicate( $callback ) ) {
				++$count;
			}
		}
	}

	return $count;
}

function erankly_ml_contract_normalize_url( string $url, array $site_ids ): string {
	$homes = array();
	foreach ( array_values( $site_ids ) as $index => $blog_id ) {
		$home = untrailingslashit( (string) get_home_url( (int) $blog_id, '/' ) );
		if ( '' !== $home ) {
			$homes[] = array(
				'home'  => $home,
				'token' => '{site:' . ( $index + 1 ) . '}',
			);
		}
	}

	usort( $homes, static fn( array $left, array $right ): int => strlen( $right['home'] ) <=> strlen( $left['home'] ) );
	foreach ( $homes as $entry ) {
		if ( $url === $entry['home'] || str_starts_with( $url, $entry['home'] . '/' ) ) {
			$relative = ltrim( substr( $url, strlen( $entry['home'] ) ), '/' );
			return $entry['token'] . '/' . $relative;
		}
	}

	return $url;
}

function erankly_ml_contract_normalize_alternates( array $alternates, array $site_ids ): array {
	$normalized = array();
	foreach ( $alternates as $hreflang => $url ) {
		$normalized[ (string) $hreflang ] = erankly_ml_contract_normalize_url( (string) $url, $site_ids );
	}
	ksort( $normalized, SORT_STRING );
	return $normalized;
}

function erankly_ml_contract_html_attributes( string $tag ): array {
	$attributes = array();
	preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\\s*=\\s*(["\'])(.*?)\\2/s', $tag, $matches, PREG_SET_ORDER );
	foreach ( $matches as $match ) {
		$attributes[ strtolower( $match[1] ) ] = html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	return $attributes;
}

function erankly_ml_contract_normalize_head( string $html, array $site_ids ): array {
	$alternates = array();
	$canonical  = '';
	preg_match_all( '/<link\\b[^>]*>/i', $html, $links );
	foreach ( $links[0] as $link ) {
		$attributes = erankly_ml_contract_html_attributes( $link );
		$rel        = strtolower( (string) ( $attributes['rel'] ?? '' ) );
		if ( 'alternate' === $rel && isset( $attributes['hreflang'], $attributes['href'] ) ) {
			$alternates[ (string) $attributes['hreflang'] ] = erankly_ml_contract_normalize_url( (string) $attributes['href'], $site_ids );
		} elseif ( 'canonical' === $rel && isset( $attributes['href'] ) ) {
			$canonical = erankly_ml_contract_normalize_url( (string) $attributes['href'], $site_ids );
		}
	}
	ksort( $alternates, SORT_STRING );

	return array(
		'canonical'  => $canonical,
		'alternates' => $alternates,
		'credit'     => str_contains( $html, 'EasyRankly SEO plugin v' ),
	);
}

function erankly_ml_contract_normalize_switcher( string $html, array $site_ids ): array {
	$select_id = '';
	$label_for = '';
	$options   = array();

	if ( preg_match( '/<select\\b[^>]*data-erml-switcher[^>]*>/i', $html, $select ) ) {
		$attributes = erankly_ml_contract_html_attributes( $select[0] );
		$select_id  = preg_replace( '/erml-switcher-\\d+/', '{switcher-id}', (string) ( $attributes['id'] ?? '' ) );
	}
	if ( preg_match( '/<label\\b[^>]*>/i', $html, $label ) ) {
		$attributes = erankly_ml_contract_html_attributes( $label[0] );
		$label_for  = preg_replace( '/erml-switcher-\\d+/', '{switcher-id}', (string) ( $attributes['for'] ?? '' ) );
	}
	preg_match_all( '/<option\\b([^>]*)>(.*?)<\\/option>/is', $html, $option_matches, PREG_SET_ORDER );
	foreach ( $option_matches as $option ) {
		$attributes = erankly_ml_contract_html_attributes( '<option ' . $option[1] . '>' );
		$options[]  = array(
			'hreflang' => (string) ( $attributes['hreflang'] ?? '' ),
			'url'      => erankly_ml_contract_normalize_url( (string) ( $attributes['value'] ?? '' ), $site_ids ),
			'current'  => (bool) preg_match( '/\\sselected(?:=|\\s|>)/i', '<option ' . $option[1] . '>' ),
			'label'    => trim( wp_strip_all_tags( $option[2] ) ),
		);
	}
	usort( $options, static fn( array $left, array $right ): int => strcmp( $left['hreflang'], $right['hreflang'] ) );

	return array(
		'marker'    => str_contains( $html, 'data-erml-switcher' ),
		'select_id' => $select_id,
		'label_for' => $label_for,
		'options'   => $options,
	);
}

function erankly_ml_contract_normalize_notice( string $html, array $site_ids ): array {
	$attributes = array();
	if ( preg_match( '/<div\\b[^>]*data-erml-notice[^>]*>/i', $html, $notice ) ) {
		$attributes = erankly_ml_contract_html_attributes( $notice[0] );
	}
	$translations = json_decode( (string) ( $attributes['data-translations'] ?? '' ), true );
	$translations = is_array( $translations ) ? $translations : array();
	foreach ( $translations as &$translation ) {
		if ( is_array( $translation ) ) {
			unset( $translation['blog_id'], $translation['object_id'] );
			$translation['url'] = erankly_ml_contract_normalize_url( (string) ( $translation['url'] ?? '' ), $site_ids );
		}
	}
	unset( $translation );
	usort( $translations, static fn( array $left, array $right ): int => strcmp( (string) ( $left['hreflang'] ?? '' ), (string) ( $right['hreflang'] ?? '' ) ) );

	return array(
		'marker'       => str_contains( $html, 'data-erml-notice' ),
		'post_id'      => isset( $attributes['data-post-id'] ) ? '{object-id}' : '',
		'current_lang' => (string) ( $attributes['data-current-lang'] ?? '' ),
		'translations' => $translations,
	);
}

function erankly_ml_contract_normalize_rest_search( array $rows, array $site_ids ): array {
	$normalized = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$normalized[] = array(
			'id'    => isset( $row['id'] ) ? '{object-id}' : '',
			'title' => (string) ( $row['title'] ?? '' ),
			'url'   => erankly_ml_contract_normalize_url( (string) ( $row['url'] ?? '' ), $site_ids ),
		);
	}
	usort( $normalized, static fn( array $left, array $right ): int => strcmp( $left['title'], $right['title'] ) );
	return $normalized;
}

function erankly_ml_contract_normalize_robots_txt( string $robots_txt, array $site_ids ): array {
	$rules    = array();
	$sitemaps = array();
	foreach ( preg_split( '/\\R/', trim( $robots_txt ) ) ?: array() as $line ) {
		$line = trim( $line );
		if ( str_starts_with( $line, 'Sitemap: ' ) ) {
			$sitemaps[] = erankly_ml_contract_normalize_url( trim( substr( $line, strlen( 'Sitemap: ' ) ) ), $site_ids );
		} elseif ( '' !== $line ) {
			$rules[] = $line;
		}
	}
	sort( $rules, SORT_STRING );
	sort( $sitemaps, SORT_STRING );
	return array( 'rules' => $rules, 'sitemaps' => $sitemaps );
}

function erankly_ml_contract_robots_index_state(): string {
	$robots = erankly_filter_wp_robots( array() );
	if ( ! empty( $robots['noindex'] ) ) {
		return 'noindex';
	}
	return ! empty( $robots['index'] ) ? 'index' : 'unspecified';
}

function erankly_ml_contract_prepare_concurrency_fixture(): array {
	$ids = array();
	foreach ( array( 'a', 'b' ) as $suffix ) {
		$id = wp_insert_post(
			array(
				'post_title'  => 'M1 concurrent group ' . strtoupper( $suffix ),
				'post_name'   => 'm1-concurrent-group-' . $suffix,
				'post_status' => 'publish',
				'post_type'   => 'post',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Unable to create the concurrent group fixture.' );
		}
		$ids[ $suffix ] = (int) $id;
	}

	foreach ( array( 'a', 'b' ) as $suffix ) {
		delete_site_option( 'erankly_ml_contract_ready_' . $suffix );
		delete_site_option( 'erankly_ml_contract_result_' . $suffix );
	}
	update_site_option( 'erankly_ml_contract_concurrency_posts', $ids );

	return $ids;
}
