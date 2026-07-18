<?php
/**
 * Dependency-free SSRF regression tests for the Broken-Link crawler.
 *
 * Run: php tests/security-broken-links-ssrf.php
 *
 * @package EasyRankly
 */

declare(strict_types=1);

// phpcs:disable -- This standalone harness intentionally provides minimal WordPress stubs.

define( 'ABSPATH', __DIR__ . '/' );
define( 'ERANKLY_HEALTH_BL_HTTP_TIMEOUT', 8 );
define( 'MB_IN_BYTES', 1048576 );

final class WP_Error {
}

$GLOBALS['erankly_security_home_url']       = 'https://example.test';
$GLOBALS['erankly_security_validate_urls']  = true;
$GLOBALS['erankly_security_filters']        = array();
$GLOBALS['erankly_security_http_calls']     = array();
$GLOBALS['erankly_security_http_responses'] = array();

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( string $path = '' ): string {
	return $GLOBALS['erankly_security_home_url'] . $path;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['erankly_security_filters'][ $hook ] ) ) {
		return $GLOBALS['erankly_security_filters'][ $hook ]( $value, ...$args );
	}

	return $value;
}

function wp_http_validate_url( string $url ): bool {
	return $GLOBALS['erankly_security_validate_urls']
		&& false !== filter_var( $url, FILTER_VALIDATE_URL )
		&& false === strpos( $url, '127.0.0.1' );
}

function erankly_security_response( string $transport ): array {
	$response = array_shift( $GLOBALS['erankly_security_http_responses'][ $transport ] );

	return is_array( $response )
		? $response
		: array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '',
		);
}

function erankly_security_record_request( string $transport, string $url, array $args ): array {
	$GLOBALS['erankly_security_http_calls'][] = compact( 'transport', 'url', 'args' );

	return erankly_security_response( $transport );
}

function wp_remote_get( string $url, array $args = array() ): array {
	return erankly_security_record_request( 'raw_get', $url, $args );
}

function wp_remote_head( string $url, array $args = array() ): array {
	return erankly_security_record_request( 'raw_head', $url, $args );
}

function wp_safe_remote_get( string $url, array $args = array() ): array {
	return erankly_security_record_request( 'safe_get', $url, $args );
}

function wp_safe_remote_head( string $url, array $args = array() ): array {
	return erankly_security_record_request( 'safe_head', $url, $args );
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_header( array $response, string $header ) {
	return $response['headers'][ strtolower( $header ) ] ?? '';
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

require_once dirname( __DIR__ ) . '/includes/health/broken-links-crawler.php';

function erankly_security_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function erankly_security_reset_http(): void {
	$GLOBALS['erankly_security_http_calls']     = array();
	$GLOBALS['erankly_security_http_responses'] = array(
		'raw_get'   => array(),
		'raw_head'  => array(),
		'safe_get'  => array(),
		'safe_head' => array(),
	);
}

erankly_security_assert( erankly_health_bl_is_internal( 'https://example.test/page' ), 'Exact HTTPS origin must be internal.' );
erankly_security_assert( erankly_health_bl_is_internal( 'https://example.test:443/page' ), 'Explicit default HTTPS port must match.' );
erankly_security_assert( ! erankly_health_bl_is_internal( 'http://example.test/page' ), 'A scheme change must not be internal.' );
erankly_security_assert( ! erankly_health_bl_is_internal( 'https://example.test:8443/page' ), 'An arbitrary same-host port must not be internal.' );

$GLOBALS['erankly_security_home_url'] = 'https://example.test:8443';
erankly_security_assert( erankly_health_bl_is_internal( 'https://example.test:8443/page' ), 'A configured non-default home port must match.' );
erankly_security_assert( ! erankly_health_bl_is_internal( 'https://example.test/page' ), 'The configured home port must be required.' );
$GLOBALS['erankly_security_home_url'] = 'https://example.test';

$GLOBALS['erankly_security_validate_urls'] = false;
$GLOBALS['erankly_security_filters']['erankly_health_bl_allow_external_http_request'] = static fn() => true;
erankly_security_assert(
	! erankly_health_bl_is_http_request_allowed( 'http://127.0.0.1/private', false ),
	'A truthy filter must not bypass WordPress SSRF validation.'
);
unset( $GLOBALS['erankly_security_filters']['erankly_health_bl_allow_external_http_request'] );
$GLOBALS['erankly_security_validate_urls'] = true;

erankly_security_reset_http();
$GLOBALS['erankly_security_http_responses']['safe_head'][] = array(
	'response' => array( 'code' => 405 ),
	'headers'  => array(),
	'body'     => '',
);
$GLOBALS['erankly_security_http_responses']['safe_get'][] = array(
	'response' => array( 'code' => 204 ),
	'headers'  => array(),
	'body'     => '',
);
$external = erankly_health_bl_probe( 'https://external.example/link', false );
erankly_security_assert( 'ok' === $external['state'], 'The safe external GET fallback must preserve probe behavior.' );
erankly_security_assert( 2 === count( $GLOBALS['erankly_security_http_calls'] ), 'External fallback must issue exactly two requests.' );
erankly_security_assert( 'safe_head' === $GLOBALS['erankly_security_http_calls'][0]['transport'], 'External HEAD must use the safe wrapper.' );
erankly_security_assert( 'safe_get' === $GLOBALS['erankly_security_http_calls'][1]['transport'], 'External GET must use the safe wrapper.' );
foreach ( $GLOBALS['erankly_security_http_calls'] as $call ) {
	erankly_security_assert( ! empty( $call['args']['reject_unsafe_urls'] ), 'External requests must reject unsafe redirect URLs.' );
	erankly_security_assert( 5 === $call['args']['redirection'], 'Safe external requests may keep their bounded redirect chain.' );
	erankly_security_assert( ! empty( $call['args']['sslverify'] ), 'TLS verification must default to enabled.' );
}

erankly_security_reset_http();
$internal = erankly_health_bl_probe( 'https://example.test/page', true );
erankly_security_assert( 'ok' === $internal['state'], 'An exact-origin internal probe must still work.' );
erankly_security_assert( 1 === count( $GLOBALS['erankly_security_http_calls'] ), 'A successful internal HEAD must not fall back to GET.' );
erankly_security_assert( 'raw_head' === $GLOBALS['erankly_security_http_calls'][0]['transport'], 'Exact-origin loopback may use raw HEAD.' );
erankly_security_assert( 0 === $GLOBALS['erankly_security_http_calls'][0]['args']['redirection'], 'Internal probes must never follow redirects.' );
erankly_security_assert( ! empty( $GLOBALS['erankly_security_http_calls'][0]['args']['sslverify'] ), 'Internal TLS verification must default to enabled.' );

erankly_security_reset_http();
$blocked = erankly_health_bl_probe( 'https://example.test:8443/private', true );
erankly_security_assert( 'unreachable' === $blocked['state'], 'A same-host alternate port must be blocked.' );
erankly_security_assert( array() === $GLOBALS['erankly_security_http_calls'], 'A blocked internal origin must not reach the HTTP API.' );

erankly_security_reset_http();
$GLOBALS['erankly_security_http_responses']['raw_get'][] = array(
	'response' => array( 'code' => 200 ),
	'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
	'body'     => '<html><body>Safe loopback</body></html>',
);
$html = erankly_health_bl_fetch_html( 'https://example.test/page' );
erankly_security_assert( false !== strpos( (string) $html, 'Safe loopback' ), 'Exact-origin HTML fetch must still return its body.' );
erankly_security_assert( 'raw_get' === $GLOBALS['erankly_security_http_calls'][0]['transport'], 'Internal HTML fetch must use loopback GET.' );
erankly_security_assert( 0 === $GLOBALS['erankly_security_http_calls'][0]['args']['redirection'], 'Internal HTML fetch must not follow redirects.' );
erankly_security_assert( ! empty( $GLOBALS['erankly_security_http_calls'][0]['args']['sslverify'] ), 'Internal HTML fetch must verify TLS by default.' );

fwrite( STDOUT, "Broken-Link SSRF security contract passed.\n" );
