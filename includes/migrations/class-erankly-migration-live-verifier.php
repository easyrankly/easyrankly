<?php
/** Same-origin HTML, robots, sitemap and redirect migration verifier. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Captures the old-plugin baseline and compares it after controlled cutover. */
final class ERankly_Migration_Live_Verifier {
	private const PROFILE_VERSION = 2;
	/** Requests issued by this verifier instance. */
	private int $request_count = 0;

	private float $started_at;

	/** Starts a fresh, request-scoped network budget. */
	public function __construct() {
		$this->started_at = microtime( true );
	}

	/** @return array<string,mixed> */
	public function capture_baseline( array $evidence, string $source = '' ): array {
		$source_owns_output = (bool) apply_filters( 'erankly_migration_source_owns_output', erankly_detect_external_seo_head_owner(), sanitize_key( $source ) );
		$limit              = max( 1, min( 10, (int) apply_filters( 'erankly_migration_live_sample_limit', 3 ) ) );
		if ( ! $source_owns_output ) {
			$redirect_contract = $this->redirect_contract( $evidence, $limit );
			return array(
				'captured_at'         => gmdate( 'c' ),
				'state'               => 'not_source_owned',
				'source_owned_output' => false,
				'pages'               => array(),
				'redirects'           => array(),
				'surfaces'            => array(),
				'redirect_contract'    => $redirect_contract,
				'policy'              => array(
					'same_origin_only'     => true,
					'follow_redirects'     => false,
					'raw_html_persisted'   => false,
					'profile_version'      => self::PROFILE_VERSION,
					'field_values_hashed'  => true,
					'sitemap_inventory'    => 'recursive_hash',
					'representative_limit' => $limit,
				),
			);
		}
		$pages     = array();
		$redirects = array();

		foreach ( array_slice( is_array( $evidence['live_targets'] ?? null ) ? $evidence['live_targets'] : array(), 0, $limit ) as $target ) {
			$url = esc_url_raw( (string) ( $target['url'] ?? '' ) );
			if ( '' !== $url ) {
				$pages[ $url ] = $this->page_probe( $url );
			}
		}

		$redirect_audit = is_array( $evidence['redirect_audit'] ?? null ) ? $evidence['redirect_audit'] : array();
		foreach ( array_slice( is_array( $redirect_audit['storage_probes'] ?? null ) ? $redirect_audit['storage_probes'] : array(), 0, $limit ) as $probe ) {
			$path = (string) ( $probe['source_path'] ?? '' );
			$url  = $this->same_origin_url( $path );
			if ( '' !== $url ) {
				$redirects[ $path ] = $this->response_probe( $url );
			}
		}
		$redirect_contract = $this->redirect_contract( $evidence, $limit, $redirects );

		$sitemap_path               = array(
			'yoast'    => '/sitemap_index.xml',
			'rankmath' => '/sitemap_index.xml',
			'aioseo'   => '/sitemap.xml',
			'seopress' => '/sitemaps.xml',
		)[ sanitize_key( $source ) ] ?? '/wp-sitemap.xml';
		$surfaces                   = array(
			'robots'  => $this->robots_probe( home_url( '/robots.txt' ) ),
			'sitemap' => $this->sitemap_probe( home_url( $sitemap_path ) ),
		);
		$surfaces['robots']['url']  = home_url( '/robots.txt' );
		$surfaces['sitemap']['url'] = home_url( $sitemap_path );
		$all                        = array_merge( array_values( $pages ), array_values( $redirects ), array_values( $surfaces ) );
		$success                    = count( array_filter( $all, static fn( array $item ): bool => 'ok' === (string) ( $item['request_state'] ?? '' ) ) );
		$total                      = count( $all );

		return array(
			'captured_at'         => gmdate( 'c' ),
			'state'               => 0 === $success ? 'unavailable' : ( $total === $success ? 'captured' : 'partial' ),
			'source_owned_output' => $source_owns_output,
			'pages'               => $pages,
			'redirects'           => $redirects,
			'surfaces'            => $surfaces,
			'redirect_contract'    => $redirect_contract,
			'policy'              => array(
				'same_origin_only'     => true,
				'follow_redirects'     => false,
				'raw_html_persisted'   => false,
				'profile_version'      => self::PROFILE_VERSION,
				'field_values_hashed'  => true,
				'sitemap_inventory'    => 'recursive_hash',
				'representative_limit' => $limit,
			),
		);
	}

	/** @return array<string,mixed> */
	public function verify( array $report ): array {
		$checkpoint = array();
		do {
			$batch      = $this->verify_batch( $report, $checkpoint );
			$checkpoint = is_array( $batch['checkpoint'] ?? null ) ? $batch['checkpoint'] : array();
		} while ( empty( $batch['done'] ) );

		return is_array( $batch['result'] ?? null ) ? $batch['result'] : $this->empty_verification( 'inconclusive' );
	}

	/**
 * @param array<string,mixed> $checkpoint Durable task cursor and partial result.
 * @return array{done:bool,checkpoint:array<string,mixed>,result:array<string,mixed>}
 */
	public function verify_batch( array $report, array $checkpoint = array() ): array {
		$baseline = is_array( $report['html_baseline'] ?? null ) ? $report['html_baseline'] : array();
		if ( ! in_array( (string) ( $baseline['state'] ?? '' ), array( 'captured', 'partial' ), true ) ) {
			$result = $this->empty_verification( 'no_baseline' );
			return array(
				'done'       => true,
				'checkpoint' => array(),
				'result'     => $result,
			);
		}

		$tasks = array();
		foreach ( is_array( $baseline['pages'] ?? null ) ? $baseline['pages'] : array() as $url => $before ) {
			$tasks[] = array(
				'type'   => 'page',
				'key'    => (string) $url,
				'before' => is_array( $before ) ? $before : array(),
			);
		}
		foreach ( is_array( $baseline['redirects'] ?? null ) ? $baseline['redirects'] : array() as $path => $before ) {
			$tasks[] = array(
				'type'   => 'redirect',
				'key'    => (string) $path,
				'before' => is_array( $before ) ? $before : array(),
			);
		}
		foreach ( is_array( $baseline['surfaces'] ?? null ) ? $baseline['surfaces'] : array() as $name => $before ) {
			$tasks[] = array(
				'type'   => 'surface',
				'key'    => sanitize_key( (string) $name ),
				'before' => is_array( $before ) ? $before : array(),
			);
		}

		$task_count = count( $tasks );
		$result     = is_array( $checkpoint['result'] ?? null ) ? $checkpoint['result'] : $this->empty_verification( 'running' );
		$position   = min( $task_count, absint( $checkpoint['position'] ?? 0 ) );
		$limit      = max( 1, min( 5, (int) apply_filters( 'erankly_migration_live_batch_size', 2 ) ) );
		$processed  = 0;
		while ( $position < $task_count && $processed < $limit ) {
			$task   = $tasks[ $position ];
			$type   = (string) $task['type'];
			$key    = (string) $task['key'];
			$before = is_array( $task['before'] ) ? $task['before'] : array();
			$status = 'request_failed';

			if ( 'page' === $type ) {
				$url               = $key;
				$after             = $this->page_probe( (string) $url );
				$comparison        = $this->compare_page( $before, $after );
				$result['pages'][] = array(
					'url'         => esc_url_raw( (string) $url ),
					'status'      => (string) $comparison['status'],
					'scope'       => (string) $comparison['scope'],
					'reasons'     => $comparison['reasons'],
					'before_hash' => sanitize_text_field( (string) ( $before['semantic_hash'] ?? '' ) ),
					'after_hash'  => sanitize_text_field( (string) ( $after['semantic_hash'] ?? '' ) ),
					'http_code'   => absint( $after['status_code'] ?? 0 ),
				);
				$status            = (string) $comparison['status'];
			} elseif ( 'redirect' === $type ) {
				$after                 = $this->response_probe( $this->same_origin_url( $key ) );
				$status                = 'ok' !== (string) ( $after['request_state'] ?? '' ) ? 'request_failed' : (
				absint( $before['status_code'] ?? 0 ) === absint( $after['status_code'] ?? 0 )
				&& $this->normalize_url( (string) ( $before['location'] ?? '' ) ) === $this->normalize_url( (string) ( $after['location'] ?? '' ) ) ? 'match' : 'mismatch'
				);
				$result['redirects'][] = array(
					'source_path'     => sanitize_text_field( $key ),
					'status'          => $status,
					'before_status'   => absint( $before['status_code'] ?? 0 ),
					'after_status'    => absint( $after['status_code'] ?? 0 ),
					'before_location' => esc_url_raw( (string) ( $before['location'] ?? '' ) ),
					'after_location'  => esc_url_raw( (string) ( $after['location'] ?? '' ) ),
				);
			} elseif ( 'surface' === $type ) {
				$after_url                  = 'robots' === $key ? home_url( '/robots.txt' ) : $this->destination_sitemap_url();
				$after                      = 'robots' === $key ? $this->robots_probe( $after_url ) : $this->sitemap_probe( $after_url );
				$after['url']               = $after_url;
				$comparison                 = $this->compare_surface( $key, $before, $after );
				$before_count               = 'sitemap' === $key ? absint( $before['top_level_count'] ?? $before['entry_count'] ?? 0 ) : absint( $before['sitemap_count'] ?? $before['entry_count'] ?? 0 );
				$after_count                = 'sitemap' === $key ? absint( $after['top_level_count'] ?? $after['entry_count'] ?? 0 ) : absint( $after['sitemap_count'] ?? $after['entry_count'] ?? 0 );
				$result['surfaces'][ $key ] = array(
					'status'       => (string) $comparison['status'],
					'scope'        => (string) $comparison['scope'],
					'reasons'      => $comparison['reasons'],
					'before_url'   => esc_url_raw( (string) ( $before['url'] ?? '' ) ),
					'after_url'    => esc_url_raw( $after_url ),
					'before_hash'  => sanitize_text_field( (string) ( $before['semantic_hash'] ?? '' ) ),
					'after_hash'   => sanitize_text_field( (string) ( $after['semantic_hash'] ?? '' ) ),
					'before_count' => $before_count,
					'after_count'  => $after_count,
				);
				$status                     = (string) $comparison['status'];
			}

			$this->tally( $status, $result['matched'], $result['expected_changes'], $result['mismatch'], $result['request_failed'] );
			++$position;
			++$processed;
		}

		$done = $position >= $task_count;
		if ( $done ) {
			unset( $result['progress'], $result['updated_at'] );
			$result['verified_at'] = gmdate( 'c' );
			$result['state']       = $result['request_failed'] > 0 ? 'inconclusive' : ( $result['mismatch'] > 0 ? 'differences_found' : ( $result['matched'] + $result['expected_changes'] > 0 ? 'verified' : 'no_baseline' ) );
		} else {
			$result['updated_at'] = gmdate( 'c' );
			$result['progress']   = array(
				'processed' => $position,
				'total'     => $task_count,
			);
		}

		return array(
			'done'       => $done,
			'checkpoint' => $done ? array() : array(
				'position' => $position,
				'result'   => $result,
			),
			'result'     => $result,
		);
	}

	/** Builds a stable empty/partial live-verification payload. */
	private function empty_verification( string $state ): array {
		return array(
			'verified_at'      => gmdate( 'c' ),
			'state'            => sanitize_key( $state ),
			'matched'          => 0,
			'expected_changes' => 0,
			'mismatch'         => 0,
			'request_failed'   => 0,
			'pages'            => array(),
			'redirects'        => array(),
			'surfaces'         => array(),
			'follow_redirects' => false,
		);
	}

	/** Probes one HTML document and stores semantic hashes, never the raw document. */
	private function page_probe( string $url ): array {
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return array(
				'request_state' => 'failed',
				'error'         => sanitize_key( $response->get_error_code() ),
			);
		}
		$body      = (string) wp_remote_retrieve_body( $response );
		$semantics = $this->parse_head( $body );

		return array(
			'request_state'   => 'ok',
			'status_code'     => absint( wp_remote_retrieve_response_code( $response ) ),
			'semantic_hash'   => $this->hash_value( $semantics ),
			'fields'          => array_keys( $semantics ),
			'profile_version' => self::PROFILE_VERSION,
			'profile'         => $this->page_profile( $semantics ),
		);
	}

	/** Probes an HTTP response without following its redirect chain. */
	private function response_probe( string $url ): array {
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return array(
				'request_state' => 'failed',
				'error'         => sanitize_key( $response->get_error_code() ),
			);
		}

		return array(
			'request_state' => 'ok',
			'status_code'   => absint( wp_remote_retrieve_response_code( $response ) ),
			'location'      => esc_url_raw( (string) wp_remote_retrieve_header( $response, 'location' ) ),
		);
	}

	/**
 * Verifies sampled redirect responses and the health of same-origin targets. External targets are not requested;
 * their response code and Location header are still checked at the source URL. Same-origin targets must resolve
 * directly to a successful response so a migrated rule cannot hide a redirect chain or 404.
 *
 * @param int                                     $limit            Maximum redirect rules to sample.
 * @param array<string,array<string,mixed>>        $source_responses Previously captured source responses keyed by path.
 */
	private function redirect_contract( array $evidence, int $limit, array $source_responses = array() ): array {
		$audit  = is_array( $evidence['redirect_audit'] ?? null ) ? $evidence['redirect_audit'] : array();
		$probes = array_slice( is_array( $audit['storage_probes'] ?? null ) ? $audit['storage_probes'] : array(), 0, max( 1, $limit ) );
		$result = array(
			'state'          => 'not_applicable',
			'tested'         => 0,
			'passed'         => 0,
			'failed'         => 0,
			'request_failed' => 0,
			'probes'         => array(),
		);

		foreach ( $probes as $probe ) {
			$path              = (string) ( $probe['source_path'] ?? '' );
			$source_url        = $this->same_origin_url( $path );
			$expected_status   = absint( $probe['expected_status'] ?? 0 );
			$expected_location = esc_url_raw( (string) ( $probe['expected_location'] ?? '' ) );
			$source_response   = is_array( $source_responses[ $path ] ?? null ) ? $source_responses[ $path ] : $this->response_probe( $source_url );
			$status            = 'match';
			$target_state      = 'not_applicable';
			$target_status     = 0;

			if ( 'ok' !== (string) ( $source_response['request_state'] ?? '' ) ) {
				$status = 'request_failed';
			} else {
				$actual_status   = absint( $source_response['status_code'] ?? 0 );
				$actual_location = esc_url_raw( (string) ( $source_response['location'] ?? '' ) );
				$status_only     = in_array( $expected_status, array( 410, 451 ), true );
				$location_match  = $status_only || $this->normalize_url( $expected_location ) === $this->normalize_url( $actual_location );
				if ( $expected_status !== $actual_status || ! $location_match ) {
					$status = 'mismatch';
				} elseif ( ! $status_only && '' !== $expected_location ) {
					$target_url = $expected_location;
					if ( ! $this->is_same_origin( $target_url ) && str_starts_with( $target_url, '/' ) ) {
						$target_url = $this->same_origin_url( $target_url );
					}
					if ( $this->is_same_origin( $target_url ) ) {
						$target          = $this->response_probe( $target_url );
						$target_state    = (string) ( $target['request_state'] ?? 'failed' );
						$target_status   = absint( $target['status_code'] ?? 0 );
						if ( 'ok' !== $target_state ) {
							$status = 'request_failed';
						} elseif ( $target_status < 200 || $target_status >= 300 ) {
							$status       = 'dead_target';
							$target_state = 'http_error';
						}
					} else {
						$target_state = 'external_not_probed';
					}
				}
			}

			++$result['tested'];
			if ( 'match' === $status ) {
				++$result['passed'];
			} elseif ( 'request_failed' === $status ) {
				++$result['request_failed'];
			} else {
				++$result['failed'];
			}
			$result['probes'][] = array(
				'source_path'       => sanitize_text_field( $path ),
				'status'            => $status,
				'expected_status'   => $expected_status,
				'actual_status'     => absint( $source_response['status_code'] ?? 0 ),
				'expected_location' => $expected_location,
				'actual_location'   => esc_url_raw( (string) ( $source_response['location'] ?? '' ) ),
				'target_state'      => sanitize_key( $target_state ),
				'target_status'     => $target_status,
			);
		}

		if ( $result['tested'] > 0 ) {
			$result['state'] = $result['request_failed'] > 0 ? 'inconclusive' : ( $result['failed'] > 0 ? 'differences_found' : 'verified' );
		}

		return $result;
	}

	private function robots_probe( string $url ): array {
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return array(
				'request_state' => 'failed',
				'error'         => sanitize_key( $response->get_error_code() ),
			);
		}
		$status_code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return array(
				'request_state' => 'failed',
				'error'         => 'robots_http_error',
				'status_code'   => $status_code,
			);
		}
		$body          = (string) wp_remote_retrieve_body( $response );
		$lines         = preg_split( '/\R/', strtolower( $body ) );
		$lines         = array_values( array_filter( array_map( 'trim', is_array( $lines ) ? $lines : array() ) ) );
		$lines         = array_values( array_filter( $lines, static fn( string $line ): bool => ! str_starts_with( $line, '#' ) ) );
		$sitemap_lines = array_values( array_filter( $lines, static fn( string $line ): bool => str_starts_with( $line, 'sitemap:' ) ) );
		$sitemap_urls  = array_map(
			fn( string $line ): string => $this->normalize_url( trim( substr( $line, strlen( 'sitemap:' ) ) ) ),
			$sitemap_lines
		);
		$rules         = array_values( array_diff( $lines, $sitemap_lines ) );
		sort( $lines );
		sort( $rules );
		sort( $sitemap_lines );
		sort( $sitemap_urls );

		return array(
			'request_state'        => 'ok',
			'status_code'          => $status_code,
			'semantic_hash'        => $this->hash_value(
				array(
					'rules'       => $rules,
					'has_sitemap' => ! empty( $sitemap_lines ),
				)
			),
			'legacy_semantic_hash' => $this->hash_value( $sitemap_lines ),
			'entry_count'          => count( $sitemap_lines ),
			'sitemap_count'        => count( $sitemap_lines ),
			'sitemap_targets_hash' => $this->hash_value( $sitemap_urls ),
			'rules_hash'           => $this->hash_value( $rules ),
			'profile_version'      => self::PROFILE_VERSION,
		);
	}

	/**
 * Captures a provider-independent sitemap inventory. Only hashes and counts are persisted. Child sitemap URLs
 * may change when providers change, so the comparison follows indexes to their final URL inventory instead of
 * treating the provider's index filenames as content.
 */
	private function sitemap_probe( string $url ): array {
		$visited          = array();
		$inventory        = array();
		$top_level        = array();
		$errors           = array();
		$document_limit   = max( 5, min( 50, (int) apply_filters( 'erankly_migration_sitemap_document_limit', 20 ) ) );
		$url_limit        = max( 100, min( 250000, (int) apply_filters( 'erankly_migration_sitemap_url_limit', 50000 ) ) );
		$root_status_code = 0;

		$this->crawl_sitemap( $url, 0, $visited, $inventory, $top_level, $errors, $root_status_code, $document_limit, $url_limit );
		$inventory = array_values( array_unique( $inventory ) );
		$top_level = array_values( array_unique( $top_level ) );
		sort( $inventory );
		sort( $top_level );

		if ( $errors ) {
			return array(
				'request_state'   => 'failed',
				'error'           => sanitize_key( (string) $errors[0] ),
				'errors'          => array_values( array_unique( array_map( 'sanitize_key', $errors ) ) ),
				'status_code'     => $root_status_code,
				'entry_count'     => count( $top_level ),
				'top_level_count' => count( $top_level ),
				'inventory_count' => count( $inventory ),
			);
		}

		return array(
			'request_state'        => 'ok',
			'status_code'          => $root_status_code,
			'semantic_hash'        => $this->hash_value( $inventory ),
			'legacy_semantic_hash' => $this->hash_value( $top_level ),
			'inventory_hash'       => $this->hash_value( $inventory ),
			'inventory_count'      => count( $inventory ),
			'entry_count'          => count( $top_level ),
			'top_level_count'      => count( $top_level ),
			'document_count'       => count( $visited ),
			'profile_version'      => self::PROFILE_VERSION,
		);
	}

	/**
 * Recursively follows one same-origin sitemap index.
 *
 * @param string             $url Root or child sitemap URL.
 */
	private function crawl_sitemap( string $url, int $depth, array &$visited, array &$inventory, array &$top_level, array &$errors, int &$root_status_code, int $document_limit, int $url_limit ): void {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! $this->is_same_origin( $url ) ) {
			$errors[] = 'sitemap_cross_origin';
			return;
		}
		$normalized = $this->normalize_url( $url );
		if ( isset( $visited[ $normalized ] ) ) {
			return;
		}
		if ( count( $visited ) >= $document_limit || $depth > 4 ) {
			$errors[] = 'sitemap_document_limit';
			return;
		}
		$visited[ $normalized ] = true;

		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			$errors[] = 'sitemap_request_failed';
			return;
		}
		$status_code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( 0 === $depth ) {
			$root_status_code = $status_code;
		}
		if ( $status_code < 200 || $status_code >= 300 ) {
			$errors[] = 'sitemap_http_error';
			return;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		preg_match_all( '/<loc\b[^>]*>(.*?)<\/loc>/is', $body, $matches );
		$locations = array_values(
			array_filter(
				array_map(
					static fn( string $item ): string => html_entity_decode( trim( wp_strip_all_tags( $item ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					$matches[1] ?? array()
				)
			)
		);
		if ( 0 === $depth ) {
			$top_level = $locations;
		}

		if ( preg_match( '/<sitemapindex\b/i', $body ) ) {
			foreach ( $locations as $child_url ) {
				$this->crawl_sitemap( $child_url, $depth + 1, $visited, $inventory, $top_level, $errors, $root_status_code, $document_limit, $url_limit );
				if ( $errors ) {
					return;
				}
			}
			return;
		}

		if ( ! preg_match( '/<urlset\b/i', $body ) ) {
			$errors[] = 'sitemap_invalid_document';
			return;
		}
		foreach ( $locations as $location ) {
			$inventory[] = $this->normalize_url( $location );
			if ( count( $inventory ) > $url_limit ) {
				$errors[] = 'sitemap_url_limit';
				return;
			}
		}
	}

	/** Returns the EasyRankly/WordPress sitemap endpoint used after cutover. */
	private function destination_sitemap_url(): string {
		$url = esc_url_raw( (string) apply_filters( 'erankly_migration_destination_sitemap_url', home_url( '/wp-sitemap.xml' ) ) );

		return '' !== $url && $this->is_same_origin( $url ) ? $url : home_url( '/wp-sitemap.xml' );
	}

	private function parse_head( string $html ): array {
		$result = array();
		if ( preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $html, $match ) ) {
			$result['title'] = $this->text( $match[1] );
		}
		if ( preg_match( '/<link\b(?=[^>]*\brel=["\']canonical["\'])[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/is', $html, $match ) ) {
			$result['canonical'] = $this->normalize_url( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		preg_match_all( '/<meta\b[^>]*>/is', $html, $tags );
		foreach ( $tags[0] ?? array() as $tag ) {
			if ( ! preg_match( '/\b(?:name|property)=["\']([^"\']+)["\']/i', $tag, $name ) || ! preg_match( '/\bcontent=["\']([^"\']*)["\']/i', $tag, $content ) ) {
				continue;
			}
			$key = strtolower( trim( (string) $name[1] ) );
			if ( in_array( $key, array( 'description', 'robots' ), true ) || str_starts_with( $key, 'og:' ) || str_starts_with( $key, 'twitter:' ) ) {
				$value = $this->text( $content[1] );
				if ( in_array( $key, array( 'og:url', 'og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src', 'twitter:url' ), true ) ) {
					$value = $this->normalize_url( $value );
				}
				$result[ $key ] = $value;
			}
		}
		preg_match_all( '/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $scripts );
		$graphs = array();
		foreach ( $scripts[1] ?? array() as $script ) {
			$decoded = json_decode( html_entity_decode( trim( (string) $script ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			if ( is_array( $decoded ) ) {
				$graphs[] = $this->sort_recursive( $decoded );
			}
		}
		if ( $graphs ) {
			$result['json_ld'] = $graphs;
		}
		ksort( $result );

		return $result;
	}

	private function page_profile( array $semantics ): array {
		$field_hashes = array();
		foreach ( $semantics as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( in_array( $key, array( 'json_ld', 'robots' ), true ) || $this->is_provider_only_field( $key ) ) {
				continue;
			}
			$field_hashes[ $key ] = $this->hash_value( $value );
		}
		ksort( $field_hashes );

		$schema = is_array( $semantics['json_ld'] ?? null ) ? $semantics['json_ld'] : array();

		return array(
			'field_hashes'         => $field_hashes,
			'robots'               => $this->robots_directive_profile( (string) ( $semantics['robots'] ?? '' ) ),
			'has_schema'           => ! empty( $schema ),
			'schema_content_types' => $this->schema_content_types( $schema ),
		);
	}

	/** @return array{status:string,scope:string,reasons:array<int,string>} */
	private function compare_page( array $before, array $after ): array {
		if ( 'ok' !== (string) ( $before['request_state'] ?? '' ) || 'ok' !== (string) ( $after['request_state'] ?? '' ) ) {
			return $this->comparison( 'request_failed', 'page', array( 'page_request_failed' ) );
		}
		$before_status = absint( $before['status_code'] ?? 0 );
		$after_status  = absint( $after['status_code'] ?? 0 );
		if ( $before_status < 200 || $before_status >= 300 || $after_status < 200 || $after_status >= 300 ) {
			return $this->comparison( 'request_failed', 'page', array( 'page_http_error' ) );
		}
		if ( $before_status !== $after_status ) {
			return $this->comparison( 'mismatch', 'page', array( 'page_http_status_changed' ) );
		}
		$before_hash = (string) ( $before['semantic_hash'] ?? '' );
		$after_hash  = (string) ( $after['semantic_hash'] ?? '' );
		if ( '' !== $before_hash && '' !== $after_hash && hash_equals( $before_hash, $after_hash ) ) {
			return $this->comparison( 'match', 'exact_semantics' );
		}

		$before_profile = is_array( $before['profile'] ?? null ) ? $before['profile'] : array();
		$after_profile  = is_array( $after['profile'] ?? null ) ? $after['profile'] : array();
		if ( $before_profile && $after_profile ) {
			$reasons = $this->page_profile_mismatches( $before_profile, $after_profile );
			return $reasons
				? $this->comparison( 'mismatch', 'field_semantics', $reasons )
				: $this->comparison( 'expected_difference', 'field_semantics', array( 'provider_markup_changed' ) );
		}

		$before_fields = array_values( array_unique( array_map( 'strtolower', is_array( $before['fields'] ?? null ) ? $before['fields'] : array() ) ) );
		$after_fields  = array_values( array_unique( array_map( 'strtolower', is_array( $after['fields'] ?? null ) ? $after['fields'] : array() ) ) );
		$required      = array_values( array_filter( $before_fields, fn( string $field ): bool => $this->is_critical_page_field( $field ) ) );
		$missing       = array_values( array_diff( $required, $after_fields ) );
		if ( $missing ) {
			return $this->comparison( 'mismatch', 'legacy_field_coverage', array_map( static fn( string $field ): string => 'missing_' . sanitize_key( $field ), $missing ) );
		}

		return $this->comparison( 'expected_difference', 'legacy_field_coverage', array( 'legacy_provider_markup_changed' ) );
	}

	private function page_profile_mismatches( array $before, array $after ): array {
		$reasons       = array();
		$before_hashes = is_array( $before['field_hashes'] ?? null ) ? $before['field_hashes'] : array();
		$after_hashes  = is_array( $after['field_hashes'] ?? null ) ? $after['field_hashes'] : array();
		foreach ( $before_hashes as $field => $hash ) {
			$field = strtolower( (string) $field );
			if ( ! $this->is_critical_page_field( $field ) || in_array( $field, array( 'robots', 'json_ld' ), true ) ) {
				continue;
			}
			if ( ! isset( $after_hashes[ $field ] ) ) {
				$reasons[] = 'missing_' . sanitize_key( $field );
			} elseif ( ! hash_equals( (string) $hash, (string) $after_hashes[ $field ] ) ) {
				$reasons[] = 'changed_' . sanitize_key( $field );
			}
		}

		$reasons = array_merge(
			$reasons,
			$this->robots_profile_mismatches(
				is_array( $before['robots'] ?? null ) ? $before['robots'] : array(),
				is_array( $after['robots'] ?? null ) ? $after['robots'] : array()
			)
		);
		if ( ! empty( $before['has_schema'] ) && empty( $after['has_schema'] ) ) {
			$reasons[] = 'schema_missing';
		}
		$missing_types = array_diff(
			is_array( $before['schema_content_types'] ?? null ) ? $before['schema_content_types'] : array(),
			is_array( $after['schema_content_types'] ?? null ) ? $after['schema_content_types'] : array()
		);
		foreach ( $missing_types as $type ) {
			$reasons[] = 'schema_type_missing_' . sanitize_key( (string) $type );
		}

		return array_values( array_unique( $reasons ) );
	}

	private function robots_directive_profile( string $value ): array {
		$profile = array(
			'index'        => 'index',
			'follow'       => 'follow',
			'restrictions' => array(),
		);
		$tokens  = preg_split( '/\s*,\s*/', strtolower( $value ) );
		foreach ( is_array( $tokens ) ? $tokens : array() as $token ) {
			$token = trim( $token );
			if ( '' === $token || 'all' === $token ) {
				continue;
			}
			if ( 'none' === $token ) {
				$profile['index']  = 'noindex';
				$profile['follow'] = 'nofollow';
				continue;
			}
			if ( in_array( $token, array( 'index', 'noindex' ), true ) ) {
				$profile['index'] = $token;
				continue;
			}
			if ( in_array( $token, array( 'follow', 'nofollow' ), true ) ) {
				$profile['follow'] = $token;
				continue;
			}
			$parts = array_map( 'trim', explode( ':', $token, 2 ) );
			$name  = sanitize_key( (string) ( $parts[0] ?? '' ) );
			if ( in_array( $name, array( 'noarchive', 'nosnippet', 'noimageindex', 'notranslate', 'indexifembedded', 'unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview' ), true ) ) {
				$profile['restrictions'][ $name ] = '' !== (string) ( $parts[1] ?? '' ) ? (string) $parts[1] : '1';
			}
		}
		ksort( $profile['restrictions'] );

		return $profile;
	}

	private function robots_profile_mismatches( array $before, array $after ): array {
		$reasons = array();
		foreach ( array( 'index', 'follow' ) as $directive ) {
			if ( (string) ( $before[ $directive ] ?? $directive ) !== (string) ( $after[ $directive ] ?? $directive ) ) {
				$reasons[] = 'robots_' . $directive . '_changed';
			}
		}
		$before_restrictions = is_array( $before['restrictions'] ?? null ) ? $before['restrictions'] : array();
		$after_restrictions  = is_array( $after['restrictions'] ?? null ) ? $after['restrictions'] : array();
		$keys                = array_unique( array_merge( array_keys( $before_restrictions ), array_keys( $after_restrictions ) ) );
		foreach ( $keys as $key ) {
			$before_value = $before_restrictions[ $key ] ?? null;
			$after_value  = $after_restrictions[ $key ] ?? null;
			if ( $before_value === $after_value ) {
				continue;
			}
			if ( null === $before_value && $this->is_permissive_preview_directive( (string) $key, (string) $after_value ) ) {
				continue;
			}
			$reasons[] = 'robots_' . sanitize_key( (string) $key ) . '_changed';
		}

		return $reasons;
	}

	/**
 * Whether a newly added preview directive only expands search previews.
 *
 * @return bool Whether the change is permissive.
 */
	private function is_permissive_preview_directive( string $key, string $value ): bool {
		return ( 'max-image-preview' === $key && 'large' === $value )
			|| ( in_array( $key, array( 'max-snippet', 'max-video-preview' ), true ) && '-1' === $value );
	}

	private function schema_content_types( array $graphs ): array {
		$found    = array();
		$critical = array(
			'article',
			'blogposting',
			'newsarticle',
			'faqpage',
			'howto',
			'product',
			'event',
			'recipe',
			'videoobject',
			'jobposting',
			'course',
			'dataset',
			'book',
			'softwareapplication',
			'localbusiness',
			'medicalwebpage',
			'profilepage',
			'aboutpage',
			'contactpage',
			'collectionpage',
			'itemlist',
		);
		$walk     = static function ( mixed $value ) use ( &$walk, &$found, $critical ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['@type'] ) ) {
				$types = is_array( $value['@type'] ) ? $value['@type'] : array( $value['@type'] );
				foreach ( $types as $type ) {
					$type = strtolower( trim( (string) $type ) );
					$type = preg_replace( '/^.*[#\/]/', '', $type );
					if ( in_array( $type, $critical, true ) ) {
						$found[] = $type;
					}
				}
			}
			foreach ( $value as $child ) {
				$walk( $child );
			}
		};
		$walk( $graphs );
		$found = array_values( array_unique( $found ) );
		sort( $found );

		return $found;
	}

	/**
 * Whether a field must survive a provider transition.
 *
 * @return bool Whether the field is migration-critical.
 */
	private function is_critical_page_field( string $field ): bool {
		return in_array(
			strtolower( $field ),
			array(
				'title',
				'description',
				'canonical',
				'robots',
				'json_ld',
				'og:title',
				'og:url',
				'og:image',
				'og:image:url',
				'og:image:secure_url',
				'twitter:card',
				'twitter:title',
				'twitter:image',
				'twitter:image:src',
				'twitter:site',
				'twitter:creator',
				'twitter:url',
			),
			true
		);
	}

	/**
 * Whether a field is editorial decoration emitted only by some providers.
 *
 * @return bool Whether the field is provider-only.
 */
	private function is_provider_only_field( string $field ): bool {
		return 1 === preg_match( '/^twitter:(?:label|data)\d+$/', strtolower( $field ) );
	}

	/** @return array{status:string,scope:string,reasons:array<int,string>} */
	private function compare_surface( string $name, array $before, array $after ): array {
		if ( 'ok' !== (string) ( $before['request_state'] ?? '' ) || 'ok' !== (string) ( $after['request_state'] ?? '' ) ) {
			return $this->comparison( 'request_failed', sanitize_key( $name ), array( sanitize_key( $name ) . '_request_failed' ) );
		}
		if ( 'robots' === $name ) {
			return $this->compare_robots_surface( $before, $after );
		}

		return $this->compare_sitemap_surface( $before, $after );
	}

	/** @return array{status:string,scope:string,reasons:array<int,string>} */
	private function compare_robots_surface( array $before, array $after ): array {
		if ( ! empty( $before['rules_hash'] ) ) {
			if ( ! hash_equals( (string) $before['rules_hash'], (string) ( $after['rules_hash'] ?? '' ) ) ) {
				return $this->comparison( 'mismatch', 'robots_rules', array( 'robots_rules_changed' ) );
			}
			$before_count = absint( $before['sitemap_count'] ?? $before['entry_count'] ?? 0 );
			$after_count  = absint( $after['sitemap_count'] ?? $after['entry_count'] ?? 0 );
			if ( $before_count > 0 && 0 === $after_count ) {
				return $this->comparison( 'mismatch', 'robots_rules', array( 'robots_sitemap_reference_missing' ) );
			}
			$before_targets_hash = (string) ( $before['sitemap_targets_hash'] ?? '' );
			$targets_match       = '' === $before_targets_hash || hash_equals( $before_targets_hash, (string) ( $after['sitemap_targets_hash'] ?? '' ) );
			return $this->normalize_url( (string) ( $before['url'] ?? '' ) ) === $this->normalize_url( (string) ( $after['url'] ?? '' ) )
				&& $before_count === $after_count && $targets_match
				? $this->comparison( 'match', 'robots_rules' )
				: $this->comparison( 'expected_difference', 'robots_rules', array( 'robots_sitemap_endpoint_changed' ) );
		}

		$legacy_hash = (string) ( $after['legacy_semantic_hash'] ?? '' );
		if ( '' !== $legacy_hash && hash_equals( (string) ( $before['semantic_hash'] ?? '' ), $legacy_hash ) ) {
			return $this->comparison( 'match', 'legacy_robots_sitemap_line' );
		}
		$before_count = absint( $before['entry_count'] ?? 0 );
		$after_count  = absint( $after['sitemap_count'] ?? $after['entry_count'] ?? 0 );
		return $before_count === $after_count && $after_count > 0
			? $this->comparison( 'expected_difference', 'legacy_robots_sitemap_line', array( 'robots_sitemap_endpoint_changed' ) )
			: $this->comparison( 'mismatch', 'legacy_robots_sitemap_line', array( 'robots_sitemap_reference_changed' ) );
	}

	/**
 * Compares final sitemap content inventory instead of index filenames.
 *
 * @return array{status:string,scope:string,reasons:array<int,string>}
 */
	private function compare_sitemap_surface( array $before, array $after ): array {
		$before_inventory = (string) ( $before['inventory_hash'] ?? '' );
		$after_inventory  = (string) ( $after['inventory_hash'] ?? '' );
		if ( '' !== $before_inventory ) {
			if ( '' === $after_inventory || ! hash_equals( $before_inventory, $after_inventory ) ) {
				return $this->comparison( 'mismatch', 'sitemap_inventory', array( 'sitemap_inventory_changed' ) );
			}
			return $this->normalize_url( (string) ( $before['url'] ?? '' ) ) === $this->normalize_url( (string) ( $after['url'] ?? '' ) )
				? $this->comparison( 'match', 'sitemap_inventory' )
				: $this->comparison( 'expected_difference', 'sitemap_inventory', array( 'sitemap_provider_endpoint_changed' ) );
		}

		$legacy_hash = (string) ( $after['legacy_semantic_hash'] ?? '' );
		if ( '' !== $legacy_hash && hash_equals( (string) ( $before['semantic_hash'] ?? '' ), $legacy_hash ) ) {
			return $this->comparison( 'match', 'legacy_sitemap_index' );
		}
		$before_count = absint( $before['entry_count'] ?? 0 );
		$after_count  = absint( $after['top_level_count'] ?? $after['entry_count'] ?? 0 );
		return $before_count === $after_count
			? $this->comparison( 'expected_difference', 'legacy_sitemap_index', array( 'sitemap_provider_endpoint_changed' ) )
			: $this->comparison( 'mismatch', 'legacy_sitemap_index', array( 'sitemap_structure_changed' ) );
	}

	/**
 * Builds one sanitized comparison result.
 *
 * @return array{status:string,scope:string,reasons:array<int,string>}
 */
	private function comparison( string $status, string $scope, array $reasons = array() ): array {
		return array(
			'status'  => sanitize_key( $status ),
			'scope'   => sanitize_key( $scope ),
			'reasons' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $reasons ) ) ) ),
		);
	}

	/** Same-origin GET with an explicit zero redirect budget. */
	private function request( string $url ): array|WP_Error {
		if ( '' === $url || ! $this->is_same_origin( $url ) ) {
			return new WP_Error( 'migration_probe_rejected', 'The migration probe URL is not same-origin.' );
		}

		$max_requests = max( 1, min( 50, (int) apply_filters( 'erankly_migration_probe_request_limit', 20 ) ) );
		$max_seconds  = max( 2, min( 60, (int) apply_filters( 'erankly_migration_probe_time_limit', 25 ) ) );
		$elapsed      = microtime( true ) - $this->started_at;
		if ( $this->request_count >= $max_requests || $elapsed >= $max_seconds ) {
			return new WP_Error( 'migration_probe_budget_exhausted', 'The migration probe request budget was exhausted.' );
		}
		++$this->request_count;
		$remaining = max( 1, (int) floor( $max_seconds - $elapsed ) );
		$timeout   = max( 1, min( $remaining, (int) apply_filters( 'erankly_migration_probe_timeout', 5 ) ) );
		$max_bytes = max( 16384, min( 5 * MB_IN_BYTES, (int) apply_filters( 'erankly_migration_probe_response_max_bytes', 2 * MB_IN_BYTES ) ) );

		// wp_safe_remote_get() rejects private/reserved IPs by default. A local or
		// intranet WordPress home is nevertheless safe here because the URL has
		// already passed exact scheme, host and port equality above.
		$allow_home_origin = function ( bool $external, string $host, string $request_url ): bool {
			unset( $host );
			return $this->is_same_origin( $request_url ) ? true : $external;
		};
		add_filter( 'http_request_host_is_external', $allow_home_origin, 10, 3 );
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => $timeout,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => $max_bytes,
					'headers'             => array( 'X-EasyRankly-Migration-Probe' => '1' ),
					'user-agent'          => 'EasyRankly/' . ERANKLY_VERSION . ' migration verifier',
				)
			);
		} finally {
			remove_filter( 'http_request_host_is_external', $allow_home_origin, 10 );
		}
		if ( ! is_wp_error( $response ) && strlen( (string) wp_remote_retrieve_body( $response ) ) >= $max_bytes ) {
			return new WP_Error( 'migration_probe_response_too_large', 'The migration probe response reached its byte limit.' );
		}

		return $response;
	}

	private function compare_probe( array $before, array $after, string $key ): string {
		if ( 'ok' !== (string) ( $before['request_state'] ?? '' ) || 'ok' !== (string) ( $after['request_state'] ?? '' ) ) {
			return 'request_failed';
		}

		return hash_equals( (string) ( $before[ $key ] ?? '' ), (string) ( $after[ $key ] ?? '' ) ) ? 'match' : 'mismatch';
	}

	/** Tallies one comparison. */
	private function tally( string $status, int &$matched, int &$expected, int &$mismatch, int &$failed ): void {
		if ( 'match' === $status ) {
			++$matched;
		} elseif ( 'expected_difference' === $status ) {
			++$expected;
		} elseif ( 'mismatch' === $status ) {
			++$mismatch;
		} else {
			++$failed;
		}
	}

	/**
 * Accepts only the exact WordPress home origin.
 *
 * @return bool Whether the URL is same-origin.
 */
	private function is_same_origin( string $url ): bool {
		$url_parts  = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );
		return is_array( $url_parts )
			&& is_array( $home_parts )
			&& in_array( strtolower( (string) ( $home_parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& strtolower( (string) ( $url_parts['scheme'] ?? '' ) ) === strtolower( (string) ( $home_parts['scheme'] ?? '' ) )
			&& strtolower( (string) ( $url_parts['host'] ?? '' ) ) === strtolower( (string) ( $home_parts['host'] ?? '' ) )
			&& (int) ( $url_parts['port'] ?? 0 ) === (int) ( $home_parts['port'] ?? 0 );
	}

	/** @return string Same-origin URL or empty string. */
	private function same_origin_url( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$url = home_url( '/' . ltrim( $path, '/' ) );

		return $this->is_same_origin( $url ) ? $url : '';
	}

	private function normalize_url( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' === $url ? '' : untrailingslashit( $url );
	}

	private function text( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Returns a stable hash for a scalar or JSON-like value. */
	private function hash_value( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = $this->sort_recursive( $value );
		}
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/** Recursively sorts associative JSON objects before hashing. */
	private function sort_recursive( array $value ): array {
		if ( ! erankly_array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->sort_recursive( $item );
			}
		}

		return $value;
	}
}
