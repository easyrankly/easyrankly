<?php
/**
 * Dependency-free regressions for the release-blocking P0 fixes.
 *
 * Run: php tests/p0-regressions.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress stubs.

if ( isset( $argv[1] ) ) {
	$scenario = (string) $argv[1];

	if ( str_starts_with( $scenario, 'canonical-' ) ) {
		erankly_p0_run_canonical_scenario( $scenario );
	} elseif ( str_starts_with( $scenario, 'sitemap-' ) ) {
		erankly_p0_run_sitemap_scenario( $scenario );
	} elseif ( 'redirect-guards' === $scenario ) {
		erankly_p0_run_redirect_scenario();
	} else {
		throw new RuntimeException( 'Unknown P0 regression scenario: ' . $scenario );
	}

	exit( 0 );
}

/** Fails the harness when an invariant is not satisfied. */
function erankly_p0_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "P0 regression failed: {$message}\n" );
		exit( 1 );
	}
}

/** Runs one scenario in an isolated PHP process. */
function erankly_p0_run_isolated( string $scenario ): void {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario );
	$output  = array();
	$code    = 0;

	exec( $command . ' 2>&1', $output, $code );
	erankly_p0_assert( 0 === $code, $scenario . ': ' . implode( "\n", $output ) );
}

$scenarios = array(
	'canonical-posts-home',
	'canonical-static-front',
	'canonical-static-blog',
	'canonical-paged-blog',
	'sitemap-no-owner',
	'sitemap-slim-owner',
	'sitemap-disabled',
	'redirect-guards',
);

foreach ( $scenarios as $scenario ) {
	erankly_p0_run_isolated( $scenario );
}

$root              = dirname( __DIR__ );
$plugin_source     = (string) file_get_contents( $root . '/easyrankly.php' );
$readme            = (string) file_get_contents( $root . '/readme.txt' );
$meta_renderer     = (string) file_get_contents( $root . '/admin/meta-box.php' );
$meta_module_paths = glob( $root . '/admin/meta-box/*.php' ) ?: array();
sort( $meta_module_paths, SORT_STRING );
$meta_source = implode(
	"\n",
	array_map(
		static fn( string $path ): string => (string) file_get_contents( $path ),
		array_merge( array( $root . '/admin/meta-box.php' ), $meta_module_paths )
	)
);
$runner_source = (string) file_get_contents( $root . '/includes/redirects/class-erankly-redirects-runner.php' );

erankly_p0_assert( str_contains( $plugin_source, '* Version:     2.0.0' ), 'plugin header must advertise 2.0.0' );
erankly_p0_assert( str_contains( $plugin_source, "define( 'ERANKLY_VERSION', '2.0.0' );" ), 'runtime version must be 2.0.0' );
erankly_p0_assert( str_contains( $readme, 'Stable tag: 2.0.0' ), 'Stable tag must be 2.0.0' );
erankly_p0_assert( ! str_contains( $readme, '= 2.1.0 =' ), 'unreleased 2.1.0 section must not remain' );
erankly_p0_assert( str_contains( $plugin_source, "erankly_should_serve_sitemaps() ? '1' : '0'" ), 'rewrite signature must track effective sitemap ownership' );
erankly_p0_assert( str_contains( $runner_source, "array( \$this, 'maybe_redirect' ), 11" ), 'redirect runner must execute after the core REST callback' );
erankly_p0_assert( str_contains( $meta_source, 'function erankly_save_meta_box( int $post_id, WP_Post $post ): void' ), 'meta-box callback must accept the post object' );
erankly_p0_assert( array( 'post-saver.php', 'term-saver.php' ) === array_map( 'basename', $meta_module_paths ), 'post and taxonomy persistence must remain in dedicated modules' );
erankly_p0_assert( ! str_contains( $meta_renderer, 'function erankly_save_meta_box(' ), 'meta-box rendering must stay separate from post persistence' );
erankly_p0_assert( ! str_contains( $meta_renderer, 'function erankly_save_term_fields(' ), 'meta-box rendering must stay separate from taxonomy persistence' );
foreach ( array_merge( array( $root . '/admin/meta-box.php' ), $meta_module_paths ) as $meta_path ) {
	erankly_p0_assert( count( file( $meta_path ) ?: array() ) <= 800, basename( $meta_path ) . ' must remain below the structural size ceiling' );
}

$schema_save = strpos( $meta_source, '$schema_mode = erankly_sanitize_registered_meta' );
$save_hook   = strpos( $meta_source, "do_action( 'erankly_save_meta_box', \$post_id, \$post );" );
erankly_p0_assert( false !== $schema_save && false !== $save_hook && $save_hook > $schema_save, 'meta-box extension hook must run after complex schema fields are saved' );

fwrite( STDOUT, "P0 regressions passed.\n" );

/** Runs one canonical scenario with isolated WordPress conditional stubs. */
function erankly_p0_run_canonical_scenario( string $scenario ): void {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['erankly_p0_scenario'] = $scenario;

	function is_paged(): bool {
		return 'canonical-paged-blog' === $GLOBALS['erankly_p0_scenario'];
	}

	function is_singular(): bool {
		return false;
	}

	function is_search(): bool {
		return false;
	}

	function is_404(): bool {
		return false;
	}

	function is_front_page(): bool {
		return in_array( $GLOBALS['erankly_p0_scenario'], array( 'canonical-posts-home', 'canonical-static-front' ), true );
	}

	function is_home(): bool {
		return in_array( $GLOBALS['erankly_p0_scenario'], array( 'canonical-posts-home', 'canonical-static-blog', 'canonical-paged-blog' ), true );
	}

	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return 'page_for_posts' === $name && 'canonical-static-blog' === $GLOBALS['erankly_p0_scenario'] ? 42 : $default;
	}

	function get_permalink( int $post_id ): string|false {
		return 42 === $post_id ? 'https://example.test/blog/' : false;
	}

	function get_query_var( string $name, mixed $default = '' ): mixed {
		return 'paged' === $name ? 2 : $default;
	}

	function get_pagenum_link( int $page, bool $escape = true ): string {
		unset( $escape );
		return 'https://example.test/blog/page/' . $page . '/';
	}

	function esc_url_raw( string $url ): string {
		return $url;
	}

	function erankly_localize_url( string $url ): string {
		return $url;
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $hook, $args );
		return $value;
	}

	require dirname( __DIR__ ) . '/includes/canonical.php';

	$expected = match ( $scenario ) {
		'canonical-static-blog' => 'https://example.test/blog/',
		'canonical-paged-blog'  => 'https://example.test/blog/page/2/',
		default                 => 'https://example.test/',
	};

	erankly_p0_assert( $expected === erankly_get_canonical(), $scenario . ' returned the wrong canonical URL' );
}

/** Runs sitemap ownership and rewrite registration scenarios. */
function erankly_p0_run_sitemap_scenario( string $scenario ): void {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ERANKLY_PATH', dirname( __DIR__ ) . '/' );

	if ( 'sitemap-slim-owner' === $scenario ) {
		define( 'SLIM_SEO_VER', '4.10.0' );
	}

	$GLOBALS['erankly_p0_sitemap_enabled'] = 'sitemap-disabled' !== $scenario;
	$GLOBALS['erankly_p0_rewrite_rules']   = array();
	$GLOBALS['erankly_p0_filters']         = array();

	function erankly_sitemap_enabled(): bool {
		return (bool) $GLOBALS['erankly_p0_sitemap_enabled'];
	}

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['erankly_p0_filters'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
		return true;
	}

	function add_action( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}

	function has_filter( string $hook ): bool {
		unset( $hook );
		return false;
	}

	function apply_filters_ref_array( string $hook, array $args ): mixed {
		unset( $hook );
		return $args[0] ?? null;
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $hook, $args );
		return $value;
	}

	function add_rewrite_rule( string $regex, string $query, string $position = 'bottom' ): void {
		$GLOBALS['erankly_p0_rewrite_rules'][] = compact( 'regex', 'query', 'position' );
	}

	require dirname( __DIR__ ) . '/includes/compatibility.php';
	require dirname( __DIR__ ) . '/includes/robots.php';

	$should_serve = 'sitemap-no-owner' === $scenario;
	erankly_p0_assert( $should_serve === erankly_should_serve_sitemaps(), $scenario . ' returned the wrong effective sitemap state' );

	if ( 'sitemap-slim-owner' === $scenario ) {
		$owners = erankly_external_seo_head_owners();
		erankly_p0_assert( 'slim-seo' === ( $owners[0]['slug'] ?? '' ), 'Slim SEO must be detected through SLIM_SEO_VER' );
	}

	$GLOBALS['erankly_p0_rewrite_rules'] = array();
	$GLOBALS['erankly_p0_filters']       = array();
	erankly_register_rewrites();

	erankly_p0_assert( $should_serve === ( 1 === count( $GLOBALS['erankly_p0_rewrite_rules'] ) ), $scenario . ' registered an inconsistent rewrite rule' );
}

/** Verifies that broad redirect rules cannot own core endpoints. */
function erankly_p0_run_redirect_scenario(): void {
	define( 'ABSPATH', __DIR__ . '/' );

	class ERankly_Redirects_Repository {}

	$GLOBALS['erankly_p0_is_admin'] = false;
	$GLOBALS['erankly_p0_is_ajax']  = false;
	$GLOBALS['erankly_p0_is_cron']  = false;
	$GLOBALS['pagenow']             = '';

	function is_admin(): bool {
		return (bool) $GLOBALS['erankly_p0_is_admin'];
	}

	function wp_doing_ajax(): bool {
		return (bool) $GLOBALS['erankly_p0_is_ajax'];
	}

	function wp_doing_cron(): bool {
		return (bool) $GLOBALS['erankly_p0_is_cron'];
	}

	function wp_parse_url( string $url, int $component = -1 ): string|array|false|null {
		return parse_url( $url, $component );
	}

	function rest_url(): string {
		return 'https://example.test/site/wp-json/';
	}

	require dirname( __DIR__ ) . '/includes/redirects/class-erankly-redirects-runner.php';

	$reflection = new ReflectionClass( ERankly_Redirects_Runner::class );
	$runner     = $reflection->newInstanceWithoutConstructor();
	$guard      = $reflection->getMethod( 'should_skip_request' );
	$guard->setAccessible( true );

	$evaluate = static function ( string $uri, array $state = array() ) use ( $runner, $guard ): bool {
		$GLOBALS['erankly_p0_is_admin'] = (bool) ( $state['admin'] ?? false );
		$GLOBALS['erankly_p0_is_ajax']  = (bool) ( $state['ajax'] ?? false );
		$GLOBALS['erankly_p0_is_cron']  = (bool) ( $state['cron'] ?? false );
		$GLOBALS['pagenow']             = (string) ( $state['pagenow'] ?? '' );
		$_GET                           = ! empty( $state['rest_route'] ) ? array( 'rest_route' => '/wp/v2/posts' ) : array();

		return (bool) $guard->invoke( $runner, $uri );
	};

	erankly_p0_assert( ! $evaluate( '/site/old-page/' ), 'ordinary frontend routes must remain eligible' );
	erankly_p0_assert( $evaluate( '/site/wp-json' ), 'REST index must be protected' );
	erankly_p0_assert( $evaluate( '/site/wp-json/wp/v2/posts' ), 'pretty REST routes must be protected' );
	erankly_p0_assert( $evaluate( '/site/?rest_route=/wp/v2/posts', array( 'rest_route' => true ) ), 'plain REST routes must be protected' );
	erankly_p0_assert( $evaluate( '/site/wp-login.php' ), 'login path must be protected' );
	erankly_p0_assert( $evaluate( '/site/', array( 'pagenow' => 'wp-login.php' ) ), 'login runtime must be protected' );
	erankly_p0_assert( $evaluate( '/site/old-page/', array( 'admin' => true ) ), 'admin requests must be protected' );
	erankly_p0_assert( $evaluate( '/site/old-page/', array( 'ajax' => true ) ), 'AJAX requests must be protected' );
	erankly_p0_assert( $evaluate( '/site/old-page/', array( 'cron' => true ) ), 'cron requests must be protected' );
}
