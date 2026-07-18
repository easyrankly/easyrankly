<?php
// phpcs:ignoreFile -- Dependency-free crawler-state storage harness.
/** Verifies segmented crawl state and cursor-based queues. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['erankly_bl_options'] = array();
$GLOBALS['erankly_bl_writes']  = array();

function get_option( $name, $default = false ) {
	return $GLOBALS['erankly_bl_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	if ( ! array_key_exists( $name, $GLOBALS['erankly_bl_options'] ) || $GLOBALS['erankly_bl_options'][ $name ] !== $value ) {
		$GLOBALS['erankly_bl_options'][ $name ] = $value;
		$GLOBALS['erankly_bl_writes'][ $name ]  = ( $GLOBALS['erankly_bl_writes'][ $name ] ?? 0 ) + 1;
	}
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['erankly_bl_options'][ $name ] );
	return true;
}
function absint( $value ) {
	return abs( (int) $value );
}

require dirname( __DIR__ ) . '/includes/health/constants.php';
require dirname( __DIR__ ) . '/includes/health/broken-links-crawler.php';

function erankly_bl_state_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Broken-Link state segmentation failed: {$message}\n" );
		exit( 1 );
	}
}

$state                 = erankly_health_bl_default_state();
$state['status']       = 'checking';
$state['queue']        = array_fill( 0, 300, array( 'url' => 'https://example.test/', 'depth' => 0 ) );
$state['queue_cursor'] = 125;
$state['visited']      = array_fill_keys( array_map( static fn( int $id ): string => '/page-' . $id, range( 1, 300 ) ), true );
$state['links']        = array_fill_keys( array_map( static fn( int $id ): string => 'https://external.test/' . $id, range( 1, 3000 ) ), array( 'type' => 'external', 'occurrences' => array() ) );
$state['check_queue']  = array_keys( $state['links'] );
$state['check_total']  = count( $state['check_queue'] );
erankly_health_bl_save_state( $state );

$control = get_option( ERANKLY_HEALTH_BL_STATE_OPTION, array() );
foreach ( array( 'queue', 'visited', 'links', 'check_queue', 'found' ) as $heavy_key ) {
	erankly_bl_state_assert( ! array_key_exists( $heavy_key, $control ), "control option retained {$heavy_key}" );
}

$hydrated = erankly_health_bl_get_state();
erankly_bl_state_assert( 3000 === count( $hydrated['links'] ) && 125 === $hydrated['queue_cursor'], 'segmented state did not hydrate losslessly' );
erankly_bl_state_assert( 175 === erankly_health_bl_progress_payload( $hydrated )['queued'], 'queue progress does not use its numeric cursor' );

$links_writes_before = (int) ( $GLOBALS['erankly_bl_writes'][ ERANKLY_HEALTH_BL_LINKS_OPTION ] ?? 0 );
$hydrated['check_cursor'] = 25;
$hydrated['checks_done']  = 25;
erankly_health_bl_save_state( $hydrated );
erankly_bl_state_assert( $links_writes_before === (int) $GLOBALS['erankly_bl_writes'][ ERANKLY_HEALTH_BL_LINKS_OPTION ], 'a checking tick rewrote the unchanged link graph' );

erankly_health_bl_reset_state();
foreach ( array( ERANKLY_HEALTH_BL_STATE_OPTION, ERANKLY_HEALTH_BL_QUEUE_OPTION, ERANKLY_HEALTH_BL_VISITED_OPTION, ERANKLY_HEALTH_BL_LINKS_OPTION, ERANKLY_HEALTH_BL_CHECK_QUEUE_OPTION, ERANKLY_HEALTH_BL_FOUND_OPTION ) as $option ) {
	erankly_bl_state_assert( ! array_key_exists( $option, $GLOBALS['erankly_bl_options'] ), "reset retained {$option}" );
}

fwrite( STDOUT, "Broken-Link segmented state and cursor contract passed.\n" );
