<?php
/**
 * Migration source-change exception.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Raised when source data changes after the immutable job snapshot. */
final class ERankly_Migration_Source_Changed_Exception extends RuntimeException {}
