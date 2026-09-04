<?php
/** Database helpers shared by migration UI and background workers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @param string $table Fully-qualified table name (including prefix). */
function erankly_table_exists( string $table ): bool {
	global $wpdb;

	// esc_like(): underscores in the table name are LIKE wildcards otherwise, which
	// could match a differently-named table and skew the exact comparison below.
	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence check for plugin or third-party tables.
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
	);

	return $found === $table;
}
