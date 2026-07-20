<?php
/**
 * Persistent, structured AI content analysis for post editors.
 *
 * Loaded only by the dedicated REST callbacks. A report is a reproducible
 * cache: one bounded post-meta value, overwritten only after a valid model
 * response and removable independently from editorial SEO metadata.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Private, non-REST post meta used for the latest report only. */
const ERANKLY_CONTENT_ANALYSIS_META_KEY = '_erankly_content_analysis_v1';

/** Stored report schema version. */
const ERANKLY_CONTENT_ANALYSIS_SCHEMA_VERSION = 1;

/** Prompt version included in input fingerprints. */
const ERANKLY_CONTENT_ANALYSIS_PROMPT_VERSION = '1';

/** Maximum serialized bytes stored for a single report. */
const ERANKLY_CONTENT_ANALYSIS_MAX_STORED_BYTES = 32768;

/** Maximum target keyphrases accepted by one analysis. */
const ERANKLY_CONTENT_ANALYSIS_MAX_KEYWORDS = 10;

/**
 * Multibyte-safe string length.
 *
 * @param string $value Text.
 * @return int
 */
function erankly_content_analysis_strlen( string $value ): int {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
}

/**
 * Multibyte-safe substring.
 *
 * @param string $value  Text.
 * @param int    $start  Start offset.
 * @param int    $length Maximum characters.
 * @return string
 */
function erankly_content_analysis_substr( string $value, int $start, int $length ): string {
	return function_exists( 'mb_substr' ) ? (string) mb_substr( $value, $start, $length ) : (string) substr( $value, $start, $length );
}

/**
 * Counts Unicode words without treating accented text as ASCII fragments.
 *
 * @param string $value Plain text.
 * @return int
 */
function erankly_content_analysis_word_count( string $value ): int {
	if ( ! preg_match_all( "/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/u", $value, $matches ) ) {
		return 0;
	}

	return count( $matches[0] );
}

/**
 * Normalizes human text for matching and fingerprints.
 *
 * @param string $value Raw text.
 * @return string
 */
function erankly_content_analysis_normalize_text( string $value ): string {
	$charset = get_bloginfo( 'charset' );
	$value   = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, '' !== $charset ? $charset : 'UTF-8' );
	$value   = (string) preg_replace( '/\s+/u', ' ', $value );

	return trim( $value );
}

/**
 * Case- and accent-insensitive matching form.
 *
 * @param string $value Human text.
 * @return string
 */
function erankly_content_analysis_match_text( string $value ): string {
	$value = remove_accents( erankly_content_analysis_normalize_text( $value ) );

	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
}

/**
 * Sanitizes ordered, unique focus keyphrases without mutating saved post meta.
 *
 * @param mixed $value Raw array or comma-delimited value.
 * @return array<int,string>
 */
function erankly_content_analysis_sanitize_keywords( mixed $value ): array {
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\r\n,]+/', $value );
	}

	$clean = array();
	$seen  = array();

	foreach ( is_array( $value ) ? $value : array() as $keyword ) {
		$keyword = erankly_trim_text( erankly_sanitize_text( $keyword ), 120 );
		$match   = erankly_content_analysis_match_text( $keyword );

		if ( '' === $match || isset( $seen[ $match ] ) ) {
			continue;
		}

		$seen[ $match ] = true;
		$clean[]        = $keyword;
	}

	return $clean;
}

/**
 * Extracts visible text from repeated HTML elements.
 *
 * @param string $html    Sanitized HTML.
 * @param string $pattern PCRE with the visible body in capture group 1.
 * @param int    $limit   Maximum returned rows.
 * @return array<int,string>
 */
function erankly_content_analysis_extract_html_texts( string $html, string $pattern, int $limit ): array {
	$values = array();

	if ( preg_match_all( $pattern, $html, $matches ) ) {
		foreach ( array_slice( (array) $matches[1], 0, $limit ) as $match ) {
			$text = erankly_content_analysis_normalize_text( wp_strip_all_tags( (string) $match, true ) );

			if ( '' !== $text ) {
				$values[] = erankly_trim_text( $text, 240 );
			}
		}
	}

	return $values;
}

/**
 * Extracts the document outline while retaining heading levels.
 *
 * @param string $html Sanitized content.
 * @return array<int,array{level:string,text:string}>
 */
function erankly_content_analysis_extract_headings( string $html ): array {
	$headings = array();

	if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( array_slice( $matches, 0, 60 ) as $match ) {
			$text = erankly_content_analysis_normalize_text( wp_strip_all_tags( (string) $match[2], true ) );

			if ( '' !== $text ) {
				$headings[] = array(
					'level' => 'h' . absint( $match[1] ),
					'text'  => erankly_trim_text( $text, 240 ),
				);
			}
		}
	}

	return $headings;
}

/**
 * Extracts image alternative text without requiring the DOM extension.
 *
 * @param string $html Sanitized content.
 * @return array<int,string>
 */
function erankly_content_analysis_extract_image_alts( string $html ): array {
	$alts = array();

	if ( preg_match_all( '/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $matches ) ) {
		foreach ( array_slice( (array) $matches[2], 0, 30 ) as $alt ) {
			$alt = erankly_content_analysis_normalize_text( wp_strip_all_tags( (string) $alt, true ) );

			if ( '' !== $alt ) {
				$alts[] = erankly_trim_text( $alt, 180 );
			}
		}
	}

	return $alts;
}

/**
 * Builds a beginning/middle/end sample instead of dropping everything after
 * the first configured AI-content window.
 *
 * @param string $text  Complete normalized text.
 * @param int    $limit Maximum sampled characters.
 * @return string
 */
function erankly_content_analysis_distributed_excerpt( string $text, int $limit ): string {
	$length = erankly_content_analysis_strlen( $text );
	if ( $length <= $limit ) {
		return $text;
	}

	$segment = max( 200, (int) floor( ( $limit - 90 ) / 3 ) );
	$middle  = max( 0, (int) floor( ( $length - $segment ) / 2 ) );
	$end     = max( 0, $length - $segment );

	return implode(
		"\n\n",
		array(
			'[Beginning] ' . erankly_content_analysis_substr( $text, 0, $segment ),
			'[Middle] ' . erankly_content_analysis_substr( $text, $middle, $segment ),
			'[End] ' . erankly_content_analysis_substr( $text, $end, $segment ),
		)
	);
}

/**
 * Counts source characters represented by the distributed sample, excluding
 * the human-readable segment labels added to the prompt.
 *
 * @param int $total Total normalized content characters.
 * @param int $limit Configured AI content limit.
 * @return int
 */
function erankly_content_analysis_sampled_characters( int $total, int $limit ): int {
	if ( $total <= $limit ) {
		return $total;
	}

	$segment = max( 200, (int) floor( ( $limit - 90 ) / 3 ) );

	return min( $total, $segment * 3 );
}

/**
 * Returns the configured AI context window even when the AI module is unloaded.
 *
 * @return int
 */
function erankly_content_analysis_context_limit(): int {
	if ( function_exists( 'erankly_ai_get_content_limit' ) ) {
		return erankly_ai_get_content_limit();
	}

	$stored = absint( erankly_get_setting( 'ai_content_limit', 4000 ) );
	$steps  = array( 4000, 16000, 64000 );
	$best   = 4000;
	$diff   = PHP_INT_MAX;

	foreach ( $steps as $step ) {
		if ( abs( $stored - $step ) < $diff ) {
			$best = $step;
			$diff = abs( $stored - $step );
		}
	}

	return $best;
}

/**
 * Prepares both deterministic signals and bounded model context.
 *
 * @param string            $title       Current editor title.
 * @param string            $content     Current editor content.
 * @param array<int,string> $keywords    Ordered target keyphrases.
 * @param bool              $cornerstone Whether the editor declared pillar intent.
 * @return array<string,mixed>
 */
function erankly_content_analysis_prepare_source( string $title, string $content, array $keywords, bool $cornerstone ): array {
	$title        = erankly_trim_text( erankly_normalize_seo_text( $title ), 300 );
	$html         = wp_kses_post( $content );
	$plain        = erankly_content_analysis_normalize_text( wp_strip_all_tags( strip_shortcodes( $html ), true ) );
	$headings     = erankly_content_analysis_extract_headings( $html );
	$image_alts   = erankly_content_analysis_extract_image_alts( $html );
	$anchor_texts = erankly_content_analysis_extract_html_texts( $html, '/<a\b[^>]*>(.*?)<\/a>/is', 40 );
	$limit        = erankly_content_analysis_context_limit();
	$total_chars  = erankly_content_analysis_strlen( $plain );
	$sample_chars = erankly_content_analysis_sampled_characters( $total_chars, $limit );
	$sample       = erankly_content_analysis_distributed_excerpt( $plain, $limit );
	$outline      = array_map(
		static fn( array $heading ): string => strtoupper( (string) $heading['level'] ) . ': ' . (string) $heading['text'],
		$headings
	);
	$hash_payload = array(
		'title'        => $title,
		'text'         => $plain,
		'headings'     => $headings,
		'image_alts'   => $image_alts,
		'anchor_texts' => $anchor_texts,
		'keywords'     => $keywords,
		'cornerstone'  => $cornerstone,
		'language'     => get_locale(),
		'prompt'       => ERANKLY_CONTENT_ANALYSIS_PROMPT_VERSION,
	);

	return array(
		'title'               => $title,
		'plain_text'          => $plain,
		'sample'              => $sample,
		'headings'            => $headings,
		'outline'             => $outline,
		'image_alts'          => $image_alts,
		'anchor_texts'        => $anchor_texts,
		'keywords'            => $keywords,
		'cornerstone'         => $cornerstone,
		'word_count'          => erankly_content_analysis_word_count( $plain ),
		'total_characters'    => $total_chars,
		'analyzed_characters' => $sample_chars,
		'coverage_percent'    => $total_chars > 0 ? min( 100, (int) round( $sample_chars / $total_chars * 100 ) ) : 0,
		'input_hash'          => hash( 'sha256', (string) wp_json_encode( $hash_payload ) ),
	);
}

/**
 * Counts exact phrase signals in key document regions.
 *
 * @param array<string,mixed> $source Prepared source.
 * @return array<int,array<string,mixed>>
 */
function erankly_content_analysis_keyword_signals( array $source ): array {
	$plain    = erankly_content_analysis_match_text( (string) $source['plain_text'] );
	$title    = erankly_content_analysis_match_text( (string) $source['title'] );
	$intro    = erankly_content_analysis_match_text( erankly_content_analysis_substr( (string) $source['plain_text'], 0, 700 ) );
	$headings = erankly_content_analysis_match_text( implode( ' ', array_column( (array) $source['headings'], 'text' ) ) );
	$alts     = erankly_content_analysis_match_text( implode( ' ', (array) $source['image_alts'] ) );
	$anchors  = erankly_content_analysis_match_text( implode( ' ', (array) $source['anchor_texts'] ) );
	$signals  = array();

	foreach ( (array) $source['keywords'] as $index => $keyword ) {
		$needle    = erankly_content_analysis_match_text( (string) $keyword );
		$signals[] = array(
			'keyword'       => (string) $keyword,
			'role'          => 0 === $index ? 'primary' : 'secondary',
			'occurrences'   => '' !== $needle ? substr_count( $plain, $needle ) : 0,
			'in_title'      => '' !== $needle && str_contains( $title, $needle ),
			'in_intro'      => '' !== $needle && str_contains( $intro, $needle ),
			'in_headings'   => '' !== $needle && str_contains( $headings, $needle ),
			'in_image_alts' => '' !== $needle && str_contains( $alts, $needle ),
			'in_anchors'    => '' !== $needle && str_contains( $anchors, $needle ),
		);
	}

	return $signals;
}

/**
 * Finds editable posts that target any of the same normalized keyphrases.
 *
 * @param int               $post_id  Current post.
 * @param array<int,string> $keywords Target keyphrases.
 * @return array<int,array<string,mixed>>
 */
function erankly_content_analysis_find_keyword_conflicts( int $post_id, array $keywords ): array {
	global $wpdb;

	if ( empty( $keywords ) ) {
		return array();
	}

	$like_sql = array();
	$args     = array( '_erankly_focus_keywords', $post_id );

	foreach ( $keywords as $keyword ) {
		$like_sql[] = 'pm.meta_value LIKE %s';
		$args[]     = '%' . $wpdb->esc_like( (string) $keyword ) . '%';
	}

	$query = "SELECT p.ID, p.post_title, pm.meta_value FROM {$wpdb->postmeta} AS pm INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.ID <> %d AND (" . implode( ' OR ', $like_sql ) . ") AND p.post_status NOT IN ('trash','auto-draft','inherit') ORDER BY p.post_modified_gmt DESC LIMIT 60"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table names and generated placeholder-only conditions.
	$query = $wpdb->prepare( $query, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All dynamic values use placeholders above.

	if ( ! is_string( $query ) ) {
		return array();
	}

	$rows      = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded on-demand editorial comparison.
	$targets   = array_combine( array_map( 'erankly_content_analysis_match_text', $keywords ), $keywords );
	$conflicts = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$candidate_id = absint( $row['ID'] ?? 0 );
		if ( $candidate_id < 1 || ! current_user_can( 'edit_post', $candidate_id ) ) {
			continue;
		}

		$stored  = maybe_unserialize( $row['meta_value'] ?? '' );
		$matches = array();

		foreach ( is_array( $stored ) ? $stored : array() as $candidate_keyword ) {
			$match = erankly_content_analysis_match_text( (string) $candidate_keyword );
			if ( isset( $targets[ $match ] ) ) {
				$matches[] = (string) $targets[ $match ];
			}
		}

		if ( empty( $matches ) ) {
			continue;
		}

		$conflicts[] = array(
			'post_id'  => $candidate_id,
			'title'    => erankly_trim_text( erankly_sanitize_text( $row['post_title'] ?? '' ), 180 ),
			'edit_url' => esc_url_raw( (string) get_edit_post_link( $candidate_id, 'raw' ) ),
			'keywords' => array_values( array_unique( $matches ) ),
		);

		if ( count( $conflicts ) >= 10 ) {
			break;
		}
	}

	return $conflicts;
}

/**
 * Returns current cached internal-link signals without forcing a graph build.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>
 */
function erankly_content_analysis_link_signals( int $post_id ): array {
	$empty = array(
		'available'      => false,
		'inbound_count'  => 0,
		'outbound_count' => 0,
		'is_orphan'      => false,
	);

	if ( ! function_exists( 'erankly_lb_get_graph' ) ) {
		return $empty;
	}

	$graph = erankly_lb_get_graph();
	$meta  = is_array( $graph ) && isset( $graph['posts'][ $post_id ] ) && is_array( $graph['posts'][ $post_id ] )
		? $graph['posts'][ $post_id ]
		: null;

	if ( null === $meta ) {
		return $empty;
	}

	return array(
		'available'      => true,
		'inbound_count'  => absint( $meta['inbound_count'] ?? 0 ),
		'outbound_count' => absint( $meta['outbound_count'] ?? 0 ),
		'is_orphan'      => ! empty( $meta['is_orphan'] ),
	);
}

/**
 * Loads and fills the bundled content-analysis prompt.
 *
 * @param array<string,mixed> $source  Prepared source.
 * @param array<string,mixed> $signals Deterministic signals.
 * @return array{system:string,user:string}
 */
function erankly_content_analysis_build_prompt( array $source, array $signals ): array {
	$file                             = ERANKLY_PATH . 'prompts/content-analysis.md';
	$raw                              = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled plugin prompt.
	$model_signals                    = $signals;
	$model_signals['cannibalization'] = array_map(
		static fn( array $row ): array => array(
			'title'    => (string) ( $row['title'] ?? '' ),
			'keywords' => array_values( (array) ( $row['keywords'] ?? array() ) ),
		),
		(array) ( $signals['cannibalization'] ?? array() )
	);
	$data                             = array(
		'title'              => $source['title'],
		'language'           => get_locale(),
		'keywords'           => $source['keywords'],
		'cornerstone'        => $source['cornerstone'],
		'word_count'         => $source['word_count'],
		'total_characters'   => $source['total_characters'],
		'coverage_percent'   => $source['coverage_percent'],
		'outline'            => $source['outline'],
		'image_alt_texts'    => $source['image_alts'],
		'internal_anchors'   => $source['anchor_texts'],
		'distributed_sample' => $source['sample'],
	);
	$filled                           = strtr(
		$raw,
		array(
			'{{language}}'     => get_locale(),
			'{{source_json}}'  => (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'{{signals_json}}' => (string) wp_json_encode( $model_signals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		)
	);

	return array(
		'system' => erankly_ai_extract_section( $filled, 'System' ),
		'user'   => erankly_ai_extract_section( $filled, 'User' ),
	);
}

/**
 * Loads and fills the bundled focus-keyword suggestion prompt.
 *
 * @param array<string,mixed> $source Prepared source.
 * @return array{system:string,user:string}
 */
function erankly_content_analysis_build_keyword_suggestion_prompt( array $source ): array {
	$file   = ERANKLY_PATH . 'prompts/keyword-suggestion.md';
	$raw    = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled plugin prompt.
	$data   = array(
		'title'              => $source['title'],
		'language'           => get_locale(),
		'word_count'         => $source['word_count'],
		'coverage_percent'   => $source['coverage_percent'],
		'outline'            => $source['outline'],
		'distributed_sample' => $source['sample'],
	);
	$filled = strtr(
		$raw,
		array(
			'{{language}}'    => get_locale(),
			'{{source_json}}' => (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		)
	);

	return array(
		'system' => erankly_ai_extract_section( $filled, 'System' ),
		'user'   => erankly_ai_extract_section( $filled, 'User' ),
	);
}

/**
 * Decodes the first JSON object returned by the model.
 *
 * @param string $raw Raw model text.
 * @return array<string,mixed>|WP_Error
 */
function erankly_content_analysis_decode_result( string $raw ) {
	$json = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', trim( $raw ) );
	if ( preg_match( '/\{.*\}/s', $json, $match ) ) {
		$json = $match[0];
	}

	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'erankly_content_analysis_parse', __( 'Could not read the AI content analysis.', 'easyrankly' ), array( 'status' => 502 ) );
	}

	return $data;
}

/**
 * Validates and bounds a model-provided focus-keyword suggestion.
 *
 * @param array<string,mixed> $data Decoded model response.
 * @return string|WP_Error
 */
function erankly_content_analysis_sanitize_keyword_suggestion( array $data ) {
	$keywords = erankly_content_analysis_sanitize_keywords( array( $data['keyword'] ?? '' ) );
	$keyword  = (string) ( $keywords[0] ?? '' );

	if ( '' === $keyword || erankly_content_analysis_word_count( $keyword ) > 8 ) {
		return new WP_Error( 'erankly_keyword_suggestion_schema', __( 'The AI returned an invalid keyword suggestion.', 'easyrankly' ), array( 'status' => 502 ) );
	}

	return $keyword;
}

/**
 * Bounds one model-provided string.
 *
 * @param mixed $value     Raw value.
 * @param int   $limit     Maximum characters.
 * @param bool  $multiline Whether line breaks are meaningful.
 * @return string
 */
function erankly_content_analysis_clean_string( mixed $value, int $limit, bool $multiline = false ): string {
	$clean = $multiline ? erankly_sanitize_textarea( $value ) : erankly_sanitize_text( $value );

	return erankly_trim_text( $clean, $limit );
}

/**
 * Bounds a list of model-provided strings.
 *
 * @param mixed $value       Raw list.
 * @param int   $max_items   Maximum rows.
 * @param int   $string_size Maximum characters per row.
 * @return array<int,string>
 */
function erankly_content_analysis_clean_string_list( mixed $value, int $max_items, int $string_size ): array {
	$clean = array();

	foreach ( array_slice( is_array( $value ) ? $value : array(), 0, $max_items ) as $item ) {
		$item = erankly_content_analysis_clean_string( $item, $string_size, true );
		if ( '' !== $item ) {
			$clean[] = $item;
		}
	}

	return array_values( array_unique( $clean ) );
}

/**
 * Validates and sanitizes the complete model report.
 *
 * @param array<string,mixed> $data     Decoded model response.
 * @param array<int,string>   $keywords Requested keyphrases.
 * @param bool                $pillar   Declared pillar state.
 * @return array<string,mixed>|WP_Error
 */
function erankly_content_analysis_sanitize_report( array $data, array $keywords, bool $pillar ) {
	$verdict = sanitize_key( (string) ( $data['verdict'] ?? '' ) );
	if ( ! in_array( $verdict, array( 'in_focus', 'partially_in_focus', 'out_of_focus' ), true ) ) {
		return new WP_Error( 'erankly_content_analysis_schema', __( 'The AI returned an invalid content-analysis verdict.', 'easyrankly' ), array( 'status' => 502 ) );
	}

	$summary = erankly_content_analysis_clean_string( $data['summary'] ?? '', 600, true );
	if ( '' === $summary || ! isset( $data['score'] ) || ! is_numeric( $data['score'] ) ) {
		return new WP_Error( 'erankly_content_analysis_schema', __( 'The AI returned an incomplete content analysis.', 'easyrankly' ), array( 'status' => 502 ) );
	}

	$keyword_map     = array_combine( array_map( 'erankly_content_analysis_match_text', $keywords ), $keywords );
	$keyword_rows    = array();
	$keyword_results = array();

	foreach ( array_slice( is_array( $data['keyword_results'] ?? null ) ? $data['keyword_results'] : array(), 0, ERANKLY_CONTENT_ANALYSIS_MAX_KEYWORDS * 2 ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$match      = erankly_content_analysis_match_text( (string) ( $row['keyword'] ?? '' ) );
		$status     = sanitize_key( (string) ( $row['status'] ?? 'partial' ) );
		$assessment = erankly_content_analysis_clean_string( $row['assessment'] ?? '', 360, true );
		if ( '' === $assessment || ! isset( $keyword_map[ $match ] ) || isset( $keyword_rows[ $match ] ) || ! in_array( $status, array( 'strong', 'partial', 'weak', 'missing', 'overused' ), true ) ) {
			continue;
		}

		$keyword_rows[ $match ] = array(
			'keyword'         => (string) $keyword_map[ $match ],
			'status'          => $status,
			'assessment'      => $assessment,
			'evidence'        => erankly_content_analysis_clean_string_list( $row['evidence'] ?? array(), 2, 220 ),
			'recommendations' => erankly_content_analysis_clean_string_list( $row['recommendations'] ?? array(), 2, 240 ),
		);
	}

	foreach ( $keyword_map as $match => $keyword ) {
		if ( ! isset( $keyword_rows[ $match ] ) ) {
			return new WP_Error( 'erankly_content_analysis_schema', __( 'The AI returned an incomplete keyword analysis.', 'easyrankly' ), array( 'status' => 502 ) );
		}

		$keyword_results[] = $keyword_rows[ $match ];
	}

	$priorities = array();
	foreach ( array_slice( is_array( $data['priority_actions'] ?? null ) ? $data['priority_actions'] : array(), 0, 8 ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$priority = sanitize_key( (string) ( $row['priority'] ?? 'medium' ) );
		$title    = erankly_content_analysis_clean_string( $row['title'] ?? '', 160 );
		$action   = erankly_content_analysis_clean_string( $row['action'] ?? '', 320, true );
		if ( '' === $title || '' === $action ) {
			continue;
		}
		$priorities[] = array(
			'priority' => in_array( $priority, array( 'high', 'medium', 'low' ), true ) ? $priority : 'medium',
			'title'    => $title,
			'reason'   => erankly_content_analysis_clean_string( $row['reason'] ?? '', 260, true ),
			'action'   => $action,
		);
	}

	$headings = array();
	foreach ( array_slice( is_array( $data['suggested_headings'] ?? null ) ? $data['suggested_headings'] : array(), 0, 8 ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$level = sanitize_key( (string) ( $row['level'] ?? 'h2' ) );
		$text  = erankly_content_analysis_clean_string( $row['text'] ?? '', 180 );
		if ( '' === $text ) {
			continue;
		}
		$headings[] = array(
			'level'  => in_array( $level, array( 'h2', 'h3' ), true ) ? $level : 'h2',
			'text'   => $text,
			'reason' => erankly_content_analysis_clean_string( $row['reason'] ?? '', 220, true ),
		);
	}

	$sentences = array();
	foreach ( array_slice( is_array( $data['suggested_sentences'] ?? null ) ? $data['suggested_sentences'] : array(), 0, 8 ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$text = erankly_content_analysis_clean_string( $row['text'] ?? '', 360, true );
		if ( '' === $text ) {
			continue;
		}
		$sentences[] = array(
			'text'      => $text,
			'placement' => erankly_content_analysis_clean_string( $row['placement'] ?? '', 180, true ),
			'keyword'   => erankly_content_analysis_clean_string( $row['keyword'] ?? '', 120 ),
		);
	}

	$pillar_data = is_array( $data['pillar'] ?? null ) ? $data['pillar'] : array();
	$readiness   = sanitize_key( (string) ( $pillar_data['readiness'] ?? ( $pillar ? 'weak' : 'not_applicable' ) ) );
	if ( ! $pillar ) {
		$readiness = 'not_applicable';
	} elseif ( ! in_array( $readiness, array( 'strong', 'partial', 'weak' ), true ) ) {
		$readiness = $pillar ? 'weak' : 'not_applicable';
	}

	return array(
		'verdict'             => $verdict,
		'score'               => max( 0, min( 100, absint( $data['score'] ?? 0 ) ) ),
		'summary'             => $summary,
		'search_intent'       => erankly_content_analysis_clean_string( $data['search_intent'] ?? '', 320, true ),
		'strengths'           => erankly_content_analysis_clean_string_list( $data['strengths'] ?? array(), 5, 220 ),
		'keyword_results'     => $keyword_results,
		'priority_actions'    => $priorities,
		'missing_topics'      => erankly_content_analysis_clean_string_list( $data['missing_topics'] ?? array(), 8, 200 ),
		'suggested_headings'  => $headings,
		'suggested_sentences' => $sentences,
		'pillar'              => array(
			'readiness'     => $readiness,
			'summary'       => erankly_content_analysis_clean_string( $pillar_data['summary'] ?? '', 420, true ),
			'cluster_ideas' => erankly_content_analysis_clean_string_list( $pillar_data['cluster_ideas'] ?? array(), 6, 220 ),
			'link_actions'  => erankly_content_analysis_clean_string_list( $pillar_data['link_actions'] ?? array(), 6, 220 ),
		),
		'warnings'            => erankly_content_analysis_clean_string_list( $data['warnings'] ?? array(), 5, 220 ),
	);
}

/**
 * Drops lowest-value optional rows until the serialized meta stays bounded.
 *
 * @param array<string,mixed> $record Complete record.
 * @return array<string,mixed>|WP_Error
 */
function erankly_content_analysis_compact_record( array $record ) {
	$optional_lists = array( 'warnings', 'suggested_headings', 'missing_topics', 'strengths', 'suggested_sentences', 'priority_actions' );
	$stored_bytes   = strlen( maybe_serialize( $record ) );

	foreach ( $optional_lists as $key ) {
		while ( $stored_bytes > ERANKLY_CONTENT_ANALYSIS_MAX_STORED_BYTES && ! empty( $record['report'][ $key ] ) ) {
			array_pop( $record['report'][ $key ] );
			$stored_bytes = strlen( maybe_serialize( $record ) );
		}
	}

	foreach ( array( 'cluster_ideas', 'link_actions' ) as $key ) {
		while ( $stored_bytes > ERANKLY_CONTENT_ANALYSIS_MAX_STORED_BYTES && ! empty( $record['report']['pillar'][ $key ] ) ) {
			array_pop( $record['report']['pillar'][ $key ] );
			$stored_bytes = strlen( maybe_serialize( $record ) );
		}
	}

	if ( $stored_bytes > ERANKLY_CONTENT_ANALYSIS_MAX_STORED_BYTES ) {
		return new WP_Error( 'erankly_content_analysis_too_large', __( 'The AI content analysis was too large to store safely.', 'easyrankly' ), array( 'status' => 502 ) );
	}

	return $record;
}

/**
 * Returns the current saved-post snapshot for freshness checks.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>|null
 */
function erankly_content_analysis_saved_source( int $post_id ): ?array {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	$keywords = erankly_content_analysis_sanitize_keywords( get_post_meta( $post_id, '_erankly_focus_keywords', true ) );
	$pillar   = (bool) get_post_meta( $post_id, '_erankly_cornerstone', true );

	return erankly_content_analysis_prepare_source( $post->post_title, $post->post_content, $keywords, $pillar );
}

/**
 * Formats the persistent state returned to editor clients.
 *
 * @param array<string,mixed>|null $record Stored record.
 * @param bool                     $stale  Whether saved post inputs differ.
 * @return WP_REST_Response
 */
function erankly_content_analysis_response( ?array $record, bool $stale = false ): WP_REST_Response {
	return rest_ensure_response(
		array(
			'has_analysis' => null !== $record,
			'analysis'     => $record,
			'stale'        => null !== $record && $stale,
		)
	);
}

/**
 * Returns the latest report and its freshness state.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function erankly_content_analysis_rest_get( WP_REST_Request $request ): WP_REST_Response {
	$post_id = absint( $request['post_id'] );
	$record  = get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true );

	if ( ! is_array( $record ) || ERANKLY_CONTENT_ANALYSIS_SCHEMA_VERSION !== absint( $record['schema_version'] ?? 0 ) || empty( $record['report'] ) ) {
		return erankly_content_analysis_response( null );
	}

	$current = erankly_content_analysis_saved_source( $post_id );
	$stale   = null === $current || ! hash_equals( (string) ( $record['input_hash'] ?? '' ), (string) $current['input_hash'] );

	return erankly_content_analysis_response( $record, $stale );
}

/**
 * Generates and atomically replaces the latest valid report.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_content_analysis_rest_generate( WP_REST_Request $request ) {
	if ( ! erankly_ai_module_enabled() || ! erankly_ai_enabled() ) {
		return new WP_Error( 'erankly_content_analysis_ai_unavailable', __( 'Connect and enable an AI provider before analyzing content.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	$post_id  = absint( $request['post_id'] );
	$keywords = erankly_content_analysis_sanitize_keywords( $request['keywords'] ?? array() );

	if ( empty( $keywords ) ) {
		return new WP_Error( 'erankly_content_analysis_keywords_required', __( 'Add at least one focus keyword before analyzing.', 'easyrankly' ), array( 'status' => 400 ) );
	}

	if ( count( $keywords ) > ERANKLY_CONTENT_ANALYSIS_MAX_KEYWORDS ) {
		return new WP_Error( 'erankly_content_analysis_too_many_keywords', __( 'Use no more than ten focus keywords for one analysis.', 'easyrankly' ), array( 'status' => 400 ) );
	}

	$source = erankly_content_analysis_prepare_source(
		(string) $request['title'],
		(string) $request['content'],
		$keywords,
		(bool) $request['cornerstone']
	);

	if ( '' === (string) $source['plain_text'] ) {
		return new WP_Error( 'erankly_content_analysis_content_required', __( 'Add some content before starting the analysis.', 'easyrankly' ), array( 'status' => 400 ) );
	}

	$blog_id   = is_multisite() ? get_current_blog_id() : 0;
	$lock_name = erankly_ai_rate_limit_lock_name( 'content-analysis:' . $blog_id . ':' . $post_id );
	if ( ! erankly_ai_rate_limit_acquire_lock( $lock_name ) ) {
		return new WP_Error( 'erankly_content_analysis_busy', __( 'This content is already being analyzed. Please wait a moment.', 'easyrankly' ), array( 'status' => 409 ) );
	}

	try {
		$signals = array(
			'source'          => array(
				'word_count'          => $source['word_count'],
				'total_characters'    => $source['total_characters'],
				'analyzed_characters' => $source['analyzed_characters'],
				'coverage_percent'    => $source['coverage_percent'],
				'heading_count'       => count( (array) $source['headings'] ),
				'image_alt_count'     => count( (array) $source['image_alts'] ),
				'anchor_count'        => count( (array) $source['anchor_texts'] ),
			),
			'keyword_checks'  => erankly_content_analysis_keyword_signals( $source ),
			'cannibalization' => erankly_content_analysis_find_keyword_conflicts( $post_id, $keywords ),
			'links'           => erankly_content_analysis_link_signals( $post_id ),
		);
		$prompt  = erankly_content_analysis_build_prompt( $source, $signals );

		if ( '' === $prompt['system'] || '' === $prompt['user'] ) {
			return new WP_Error( 'erankly_content_analysis_prompt_missing', __( 'The content-analysis prompt is missing.', 'easyrankly' ), array( 'status' => 500 ) );
		}

		$raw = erankly_ai_call_model( $prompt['system'], $prompt['user'], 'content_analysis' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$decoded = erankly_content_analysis_decode_result( (string) $raw );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$report = erankly_content_analysis_sanitize_report( $decoded, $keywords, (bool) $source['cornerstone'] );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$report['signals'] = $signals;
		$record            = erankly_content_analysis_compact_record(
			array(
				'schema_version'    => ERANKLY_CONTENT_ANALYSIS_SCHEMA_VERSION,
				'prompt_version'    => ERANKLY_CONTENT_ANALYSIS_PROMPT_VERSION,
				'analyzed_at'       => gmdate( 'c' ),
				'input_hash'        => (string) $source['input_hash'],
				'keywords_snapshot' => $keywords,
				'cornerstone'       => (bool) $source['cornerstone'],
				'report'            => $report,
			)
		);

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$updated = update_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, wp_slash( $record ) );
		if ( false === $updated && get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true ) !== $record ) {
			return new WP_Error( 'erankly_content_analysis_storage', __( 'The content analysis could not be saved.', 'easyrankly' ), array( 'status' => 500 ) );
		}

		return erankly_content_analysis_response( $record );
	} finally {
		erankly_ai_rate_limit_release_lock( $lock_name );
	}
}

/**
 * Suggests one primary focus keyphrase from the current editor content.
 *
 * The result is intentionally not persisted. The editor applies it to the
 * unsaved post-meta buffer so the author retains control over the final value.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_content_analysis_rest_suggest_keyword( WP_REST_Request $request ) {
	if ( ! erankly_ai_module_enabled() || ! erankly_ai_enabled() ) {
		return new WP_Error( 'erankly_keyword_suggestion_ai_unavailable', __( 'Connect and enable an AI provider before suggesting a keyword.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	$post_id = absint( $request['post_id'] );
	$source  = erankly_content_analysis_prepare_source(
		(string) $request['title'],
		(string) $request['content'],
		array(),
		false
	);

	if ( '' === (string) $source['plain_text'] ) {
		return new WP_Error( 'erankly_keyword_suggestion_content_required', __( 'Add some content before asking for a keyword.', 'easyrankly' ), array( 'status' => 400 ) );
	}

	$blog_id   = is_multisite() ? get_current_blog_id() : 0;
	$lock_name = erankly_ai_rate_limit_lock_name( 'keyword-suggestion:' . $blog_id . ':' . $post_id );
	if ( ! erankly_ai_rate_limit_acquire_lock( $lock_name ) ) {
		return new WP_Error( 'erankly_keyword_suggestion_busy', __( 'A keyword is already being suggested for this content. Please wait a moment.', 'easyrankly' ), array( 'status' => 409 ) );
	}

	try {
		$prompt = erankly_content_analysis_build_keyword_suggestion_prompt( $source );

		if ( '' === $prompt['system'] || '' === $prompt['user'] ) {
			return new WP_Error( 'erankly_keyword_suggestion_prompt_missing', __( 'The keyword-suggestion prompt is missing.', 'easyrankly' ), array( 'status' => 500 ) );
		}

		$raw = erankly_ai_call_model( $prompt['system'], $prompt['user'], 'content_analysis' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$decoded = erankly_content_analysis_decode_result( (string) $raw );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$keyword = erankly_content_analysis_sanitize_keyword_suggestion( $decoded );
		if ( is_wp_error( $keyword ) ) {
			return $keyword;
		}

		return rest_ensure_response( array( 'keyword' => $keyword ) );
	} finally {
		erankly_ai_rate_limit_release_lock( $lock_name );
	}
}

/**
 * Deletes the latest report without changing editorial post metadata.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function erankly_content_analysis_rest_delete( WP_REST_Request $request ): WP_REST_Response {
	delete_post_meta( absint( $request['post_id'] ), ERANKLY_CONTENT_ANALYSIS_META_KEY );

	return erankly_content_analysis_response( null );
}
