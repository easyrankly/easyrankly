<?php
// phpcs:ignoreFile -- Deterministic developer build helper; it intentionally uses direct filesystem APIs.
/**
 * Deterministic dependency-free POT builder for distributed EasyRankly files.
 *
 * @package EasyRankly
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/easyrankly.php' );
if ( false === $main || 1 !== preg_match( '/\* Version:\s+([0-9A-Za-z.-]+)/', $main, $version_match ) ) {
	throw new RuntimeException( 'The plugin version header is unavailable.' );
}

$version = (string) $version_match[1];
$files   = array();
$ignore  = array( '.git', '.github', '.codegraph', 'bin', 'build', 'coverage', 'dist', 'docs', 'node_modules', 'tests', 'vendor' );
$walk    = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $current ) use ( $ignore ): bool {
			return ! $current->isDir() || ! in_array( $current->getFilename(), $ignore, true );
		}
	)
);

foreach ( $walk as $file ) {
	if ( ! $file instanceof SplFileInfo || ! $file->isFile() || ! in_array( $file->getExtension(), array( 'php', 'js' ), true ) ) {
		continue;
	}
	$files[] = $file->getPathname();
}
sort( $files, SORT_STRING );

$calls = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );
$items = array();

foreach ( $files as $file ) {
	$source = file_get_contents( $file );
	if ( false === $source ) {
		throw new RuntimeException( 'Unreadable source: ' . $file );
	}
	$relative = ltrim( str_replace( $root, '', $file ), '/' );

	if ( 'php' === pathinfo( $file, PATHINFO_EXTENSION ) ) {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		for ( $index = 0; $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			$name  = is_array( $token ) ? ltrim( (string) $token[1], '\\' ) : '';
			if ( ! is_array( $token )
				|| ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true )
				|| ! in_array( $name, $calls, true ) ) {
				continue;
			}
			$cursor = $index + 1;
			while ( $cursor < $count && is_array( $tokens[ $cursor ] ) && T_WHITESPACE === $tokens[ $cursor ][0] ) {
				++$cursor;
			}
			if ( '(' !== ( $tokens[ $cursor ] ?? null ) ) {
				continue;
			}
			++$cursor;
			while ( $cursor < $count && is_array( $tokens[ $cursor ] ) && T_WHITESPACE === $tokens[ $cursor ][0] ) {
				++$cursor;
			}
			$literal = $tokens[ $cursor ] ?? null;
			if ( ! is_array( $literal ) || T_CONSTANT_ENCAPSED_STRING !== $literal[0] ) {
				continue;
			}
			$quote             = $literal[1][0];
			$value             = substr( $literal[1], 1, -1 );
			$value             = "'" === $quote
				? str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $value )
				: stripcslashes( $value );
			$items[ $value ][] = $relative . ':' . (int) $token[2];
		}
		continue;
	}

	preg_match_all(
		'/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])easyrankly\3\s*\)/s',
		$source,
		$matches,
		PREG_OFFSET_CAPTURE
	);
	foreach ( $matches[2] as $index => $match ) {
		$quote    = $matches[1][ $index ][0];
		$message  = '"' === $quote
			? (string) json_decode( '"' . $match[0] . '"' )
			: str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $match[0] );
		$line     = 1 + substr_count( substr( $source, 0, $match[1] ), "\n" );
		$items[ $message ][] = $relative . ':' . $line;
	}
}

ksort( $items, SORT_STRING );
$lines = array(
	'msgid ""',
	'msgstr ""',
	'"Project-Id-Version: EasyRankly ' . $version . '\\n"',
	'"Report-Msgid-Bugs-To: https://easyrankly.com/\\n"',
	'"POT-Creation-Date: 2026-07-25 00:00+0000\\n"',
	'"MIME-Version: 1.0\\n"',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Content-Transfer-Encoding: 8bit\\n"',
	'"X-Domain: easyrankly\\n"',
	'',
);
foreach ( $items as $message => $references ) {
	$lines[] = '#: ' . implode( ' ', array_values( array_unique( $references ) ) );
	$lines[] = 'msgid ' . json_encode( $message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$lines[] = 'msgstr ""';
	$lines[] = '';
}

file_put_contents( $root . '/languages/easyrankly.pot', implode( "\n", $lines ) );
