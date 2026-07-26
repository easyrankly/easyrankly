<?php
/**
 * Standalone regression for static-front-page breadcrumb duplication.
 *
 * Run: php tests/breadcrumb-front-page-contract.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress stubs.

if ( isset( $argv[1] ) ) {
	erankly_breadcrumb_front_page_run_scenario( (string) $argv[1] );
	exit( 0 );
}

$root      = dirname( __DIR__ );
$scenarios = array(
	'static-front'  => 'Static front page must not emit a duplicate Home→Home trail.',
	'single-page'   => 'A normal singular page must keep Home → page.',
	'nested-page'   => 'An internal page with an ancestor must keep a meaningful trail.',
);
$failures  = array();

foreach ( $scenarios as $scenario => $label ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario );
	$output  = array();
	$code    = 0;
	exec( $command . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		$failures[] = $label . ' (' . $scenario . '): ' . implode( "\n", $output );
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "Breadcrumb front-page contract passed.\n" );
exit( 0 );

/**
 * Executes one isolated breadcrumb scenario in a fresh process.
 *
 * @param string $scenario Scenario id.
 */
function erankly_breadcrumb_front_page_run_scenario( string $scenario ): void {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['erankly_bc_scenario'] = $scenario;
	$GLOBALS['erankly_bc_options']  = array(
		'show_on_front'  => 'static-front' === $scenario ? 'page' : 'posts',
		'page_on_front'  => 'static-front' === $scenario ? 10 : 0,
	);
	$GLOBALS['erankly_bc_permalinks'] = array(
		10 => 'https://example.test/',
		20 => 'https://example.test/about/',
		30 => 'https://example.test/about/team/',
	);
	$GLOBALS['erankly_bc_titles'] = array(
		10 => 'Home',
		20 => 'About',
		30 => 'Team',
	);
	$GLOBALS['erankly_bc_ancestors'] = array(
		10 => array(),
		20 => array(),
		30 => array( 20 ),
	);

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
	}

	class WP_Post_Type {
		/** @var object */
		public $labels;

		public function __construct( string $name ) {
			$this->labels = (object) array( 'name' => $name );
		}
	}

	class WP_Term {
		public int $term_id;
		public string $name;
		public string $taxonomy;

		public function __construct( int $term_id, string $name, string $taxonomy = 'category' ) {
			$this->term_id  = $term_id;
			$this->name     = $name;
			$this->taxonomy = $taxonomy;
		}
	}

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function esc_attr__( string $text, string $domain = 'default' ): string {
		return __( $text, $domain );
	}

	function esc_html( string $text ): string {
		return $text;
	}

	function esc_attr( string $text ): string {
		return $text;
	}

	function esc_url( string $url ): string {
		return $url;
	}

	function wp_kses( string $content, array $allowed_html ): string {
		unset( $allowed_html );
		return $content;
	}

	function wp_kses_allowed_html( string $context ): array {
		unset( $context );
		return array();
	}

	function wp_parse_args( array $args, array $defaults ): array {
		return array_merge( $defaults, $args );
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $hook, $args );
		return $value;
	}

	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}

	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['erankly_bc_options'][ $key ] ?? $default;
	}

	function get_queried_object_id(): int {
		return match ( $GLOBALS['erankly_bc_scenario'] ) {
			'static-front' => 10,
			'single-page'  => 20,
			'nested-page'  => 30,
			default        => 0,
		};
	}

	function is_singular(): bool {
		return in_array( $GLOBALS['erankly_bc_scenario'], array( 'static-front', 'single-page', 'nested-page' ), true );
	}

	function is_front_page(): bool {
		return 'static-front' === $GLOBALS['erankly_bc_scenario'];
	}

	function is_home(): bool {
		return false;
	}

	function is_category(): bool {
		return false;
	}

	function is_tag(): bool {
		return false;
	}

	function is_tax(): bool {
		return false;
	}

	function is_archive(): bool {
		return false;
	}

	function is_search(): bool {
		return false;
	}

	function is_404(): bool {
		return false;
	}

	function get_post_type( int $post_id ): string {
		unset( $post_id );
		return 'page';
	}

	function get_the_category( int $post_id ): array {
		unset( $post_id );
		return array();
	}

	function erankly_get_primary_term( int $post_id, string $taxonomy ): ?WP_Term {
		unset( $post_id, $taxonomy );
		return null;
	}

	function get_ancestors( int $object_id, string $object_type, string $resource_type = '' ): array {
		unset( $object_id, $object_type, $resource_type );
		return array();
	}

	function get_term( int $term_id, string $taxonomy ): WP_Error {
		unset( $term_id, $taxonomy );
		return new WP_Error( 'missing', 'missing' );
	}

	function get_term_link( WP_Term $term ): string {
		return 'https://example.test/category/' . $term->name . '/';
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}

	function get_post_type_archive_link( string $post_type ): string|false {
		unset( $post_type );
		return false;
	}

	function get_post_type_object( string $post_type ): ?WP_Post_Type {
		unset( $post_type );
		return null;
	}

	function get_post_ancestors( int $post_id ): array {
		return $GLOBALS['erankly_bc_ancestors'][ $post_id ] ?? array();
	}

	function get_permalink( int $post_id ): string {
		return (string) ( $GLOBALS['erankly_bc_permalinks'][ $post_id ] ?? '' );
	}

	function get_the_title( int $post_id ): string {
		return (string) ( $GLOBALS['erankly_bc_titles'][ $post_id ] ?? '' );
	}

	function erankly_get_setting( string $key, mixed $default = null ): mixed {
		return 'enable_breadcrumbs' === $key ? 1 : $default;
	}

	function erankly_get_post_meta_string( int $post_id, string $key ): string {
		unset( $post_id, $key );
		return '';
	}

	function erankly_normalize_seo_text( string $text ): string {
		return $text;
	}

	function erankly_replace_variables( string $value, int $post_id = 0, array $exclude = array() ): string {
		unset( $post_id, $exclude );
		return $value;
	}

	function erankly_get_canonical(): string {
		$id = get_queried_object_id();
		return (string) ( $GLOBALS['erankly_bc_permalinks'][ $id ] ?? home_url( '/' ) );
	}

	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}

	function get_the_archive_title(): string {
		return '';
	}

	function get_search_query(): string {
		return '';
	}

	require_once dirname( __DIR__ ) . '/includes/breadcrumbs.php';

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, $message . "\n" );
			exit( 1 );
		}
	};

	$items  = erankly_get_breadcrumb_items();
	$schema = erankly_schema_breadcrumb_list();
	$names  = array_map(
		static fn( array $item ): string => (string) ( $item['name'] ?? '' ),
		$items
	);
	$urls   = array_map(
		static function ( array $item ): string {
			$url = (string) ( $item['url'] ?? '' );
			return '' !== $url ? $url : erankly_get_canonical();
		},
		$items
	);

	switch ( $GLOBALS['erankly_bc_scenario'] ) {
		case 'static-front':
			$assert( array( 'Home' ) === $names, 'Static front page must keep only the Home crumb.' );
			$assert( array() === $schema, 'Non-significant single-crumb trails must omit BreadcrumbList schema.' );
			$assert( count( array_unique( $urls ) ) === count( $urls ), 'Static front page must not emit duplicate breadcrumb URLs.' );
			break;
		case 'single-page':
			$assert( array( 'Home', 'About' ) === $names, 'A normal page must emit Home → page.' );
			$assert( ! empty( $schema['itemListElement'] ) && 2 === count( $schema['itemListElement'] ), 'A normal page must emit a two-item BreadcrumbList.' );
			$assert( 1 === (int) $schema['itemListElement'][0]['position'] && 2 === (int) $schema['itemListElement'][1]['position'], 'Breadcrumb positions must remain sequential.' );
			break;
		case 'nested-page':
			$assert( array( 'Home', 'About', 'Team' ) === $names, 'A nested page must keep ancestor crumbs.' );
			$assert( 3 === count( $schema['itemListElement'] ?? array() ), 'A nested page must emit a three-item BreadcrumbList.' );
			break;
		default:
			fwrite( STDERR, "Unknown scenario.\n" );
			exit( 1 );
	}

	fwrite( STDOUT, "OK {$GLOBALS['erankly_bc_scenario']}\n" );
}
