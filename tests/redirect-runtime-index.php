<?php
// phpcs:ignoreFile -- Dependency-free runtime-index benchmark harness.
/** Verifies bounded redirect candidate selection with thousands of rules. */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}
function get_option( $name, $default = false ) {
	$GLOBALS['erankly_redirect_runtime_reads'][] = $name;
	return $GLOBALS['erankly_redirect_runtime_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['erankly_redirect_runtime_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['erankly_redirect_runtime_options'][ $name ] );
	return true;
}
function erankly_redirects_flush_external_caches() {}

$wpdb         = new stdClass();
$wpdb->prefix = 'wp_';

require dirname( __DIR__ ) . '/includes/redirects/class-erankly-redirects-normalizer.php';
require dirname( __DIR__ ) . '/includes/redirects/class-erankly-redirects-repository.php';

function erankly_redirect_index_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Redirect runtime index failed: {$message}\n" );
		exit( 1 );
	}
}

$rows = array();
for ( $index = 1; $index <= 3000; ++$index ) {
	$rows[] = array(
		'id'             => $index,
		'source_path'    => '/section-' . $index . '/old',
		'source_query'   => '',
		'target_url'     => '/new',
		'status_code'    => 301,
		'match_type'     => 'exact',
		'case_sensitive' => 1,
		'trailing_slash' => 'ignore',
		'query_mode'     => 'ignore',
		'priority'       => 10,
	);
}
$rows[] = array_merge( $rows[0], array( 'id' => 4001, 'source_path' => '/catalog/item', 'priority' => 5 ) );
$rows[] = array_merge( $rows[0], array( 'id' => 4002, 'source_path' => '/shop*', 'match_type' => 'wildcard' ) );
$rows[] = array_merge( $rows[0], array( 'id' => 4003, 'source_path' => '^/catalog/.+$', 'match_type' => 'regex' ) );
$rows[] = array_merge( $rows[0], array( 'id' => 4004, 'source_path' => '/catalog/query', 'query_mode' => 'exact', 'source_query' => 'page=2' ) );

$GLOBALS['erankly_redirect_runtime_options'] = array();
$GLOBALS['erankly_redirect_runtime_reads']   = array();
$repository = new ERankly_Redirects_Repository();
$compiler   = new ReflectionMethod( $repository, 'compile_runtime_rules' );
if ( PHP_VERSION_ID < 80500 ) {
	$compiler->setAccessible( true );
}
$started    = hrtime( true );
$compiled   = $compiler->invoke( $repository, $rows );
$compile_ms = (int) round( ( hrtime( true ) - $started ) / 1_000_000 );
$persister  = new ReflectionMethod( $repository, 'persist_runtime_rules' );
if ( PHP_VERSION_ID < 80500 ) {
	$persister->setAccessible( true );
}
$persister->invoke( $repository, $compiled );

$manifest = $GLOBALS['erankly_redirect_runtime_options']['erankly_redirects_runtime_rules'] ?? array();
erankly_redirect_index_assert( 3 === (int) ( $manifest['version'] ?? 0 ) && ! isset( $manifest['all'], $manifest['global'], $manifest['prefix'] ), 'the runtime manifest still embeds every redirect rule' );
$GLOBALS['erankly_redirect_runtime_reads'] = array();

$repository = new ERankly_Redirects_Repository();
$started    = hrtime( true );
$candidates = $repository->get_pattern_rules( '/catalog/item', '' );
$lookup_us  = (int) round( ( hrtime( true ) - $started ) / 1000 );

erankly_redirect_index_assert( count( $candidates ) <= 3, 'a route traverses unrelated prefix buckets' );
erankly_redirect_index_assert( in_array( 4001, array_column( $candidates, 'id' ), true ), 'the matching exact prefix bucket is absent' );
erankly_redirect_index_assert( in_array( 4003, array_column( $candidates, 'id' ), true ), 'global regex rules are absent' );
erankly_redirect_index_assert( ! in_array( 4004, array_column( $candidates, 'id' ), true ), 'a different exact query leaked into candidates' );
erankly_redirect_index_assert( ! in_array( 'erankly_redirects_runtime_rules_all', $GLOBALS['erankly_redirect_runtime_reads'], true ), 'a route loaded the compatibility all-rules segment' );
erankly_redirect_index_assert( ! in_array( 'erankly_redirects_runtime_rules_prefix_index', $GLOBALS['erankly_redirect_runtime_reads'], true ), 'a route loaded the cleanup-only prefix index' );

$shopping = $repository->get_pattern_rules( '/shopping', '' );
erankly_redirect_index_assert( in_array( 4002, array_column( $shopping, 'id' ), true ), 'a partial-segment wildcard was indexed too narrowly' );

$repository->invalidate_runtime_rules();
foreach ( array_keys( $GLOBALS['erankly_redirect_runtime_options'] ) as $option ) {
	erankly_redirect_index_assert( ! str_starts_with( $option, 'erankly_redirects_runtime_rules' ), 'runtime invalidation retained a segmented option' );
}

printf( "Redirect runtime index passed (rules=%d, candidates=%d, compile=%d ms, lookup=%d us).\n", count( $rows ), count( $candidates ), $compile_ms, $lookup_us );
