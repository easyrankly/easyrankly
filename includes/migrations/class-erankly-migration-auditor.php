<?php
/**
 * Deterministic post-import evidence builder.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Turns temporary queue events into a persistent, auditable report. */
final class ERankly_Migration_Auditor {
	private const EXCEPTION_SAMPLE_LIMIT = 100;
	private const SAMPLE_LIMIT           = 20;

	/**
	 * Builds terminal accounting, semantic comparisons and redirect diagnostics.
	 *
	 * @param string                           $job_id Migration UUID.
	 * @param array<string,mixed>              $report Terminal report.
	 * @param ERankly_Migration_Job_Store      $store Queue store.
	 * @param ERankly_Migration_Journal        $journal Rollback journal.
	 * @param ERankly_Migration_Evidence_Store $evidence_store Complete exception ledger.
	 * @return array<string,mixed>
	 */
	public function build( string $job_id, array &$report, ERankly_Migration_Job_Store $store, ERankly_Migration_Journal $journal, ERankly_Migration_Evidence_Store $evidence_store ): array {
		$mode         = sanitize_key( (string) ( $report['mode'] ?? '' ) );
		$accounting   = array(
			'objects'   => $this->empty_accounting(),
			'settings'  => $this->empty_accounting(),
			'metadata'  => $this->empty_accounting(),
			'redirects' => $this->empty_accounting(),
		);
		$semantic     = array();
		$exceptions   = array();
		$redirects    = array();
		$live_targets = array();
		$last_id      = 0;
		$transformed  = 0;

		do {
			$rows = $store->evidence_page( $job_id, $last_id, 500 );
			foreach ( $rows as $row ) {
				$last_id = absint( $row['id'] ?? $last_id );
				$kind    = sanitize_key( (string) ( $row['item_kind'] ?? '' ) );
				$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
				$payload = is_array( $payload ) ? $payload : array();

				if ( 'object' === $kind ) {
					$terminal = 'invalid' === (string) ( $row['discovery_status'] ?? '' ) ? 'invalid' : 'accounted';
					++$accounting['objects']['discovered'];
					++$accounting['objects']['terminal'][ $terminal ];
					$url = $this->public_url( (string) ( $payload['object_type'] ?? '' ), absint( $payload['object_id'] ?? 0 ) );
					if ( '' !== $url && count( $live_targets ) < self::SAMPLE_LIMIT ) {
						$live_targets[ $url ] = array(
							'url'       => $url,
							'edit_url'  => $this->edit_url( (string) ( $payload['object_type'] ?? '' ), absint( $payload['object_id'] ?? 0 ) ),
							'reference' => sanitize_text_field( (string) ( $row['source_reference'] ?? '' ) ),
						);
					}
					continue;
				}

				if ( 'meta' === $kind ) {
					$terminal = $this->meta_terminal( $row, $mode );
					++$accounting['metadata']['discovered'];
					++$accounting['metadata']['terminal'][ $terminal ];
					if ( ! empty( $payload['transformed'] ) ) {
						++$transformed;
					}
					$this->add_semantic_result( $semantic, $row, $payload, $terminal, $mode );
					if ( $this->is_exception( $terminal ) ) {
						$exception = $this->exception( $row, $payload, $terminal );
						$evidence_store->add( $job_id, absint( $row['id'] ?? 0 ), $exception );
						if ( count( $exceptions ) < self::EXCEPTION_SAMPLE_LIMIT ) {
							$exceptions[] = $exception;
						}
					}
					continue;
				}

				if ( 'setting' === $kind ) {
					$terminal = $this->meta_terminal( $row, $mode );
					++$accounting['settings']['discovered'];
					++$accounting['settings']['terminal'][ $terminal ];
					if ( ! empty( $payload['transformed'] ) ) {
						++$transformed;
					}
					if ( $this->is_exception( $terminal ) ) {
						$exception = $this->exception( $row, $payload, $terminal );
						$evidence_store->add( $job_id, absint( $row['id'] ?? 0 ), $exception );
						if ( count( $exceptions ) < self::EXCEPTION_SAMPLE_LIMIT ) {
							$exceptions[] = $exception;
						}
					}
					continue;
				}

				if ( 'redirect' === $kind ) {
					$terminal = $this->redirect_terminal( $row, $mode );
					++$accounting['redirects']['discovered'];
					++$accounting['redirects']['terminal'][ $terminal ];
					if ( is_array( $payload['redirect'] ?? null ) ) {
						$redirects[] = array(
							'row'       => $row,
							'rule_hash' => sanitize_text_field( (string) ( $payload['rule_hash'] ?? '' ) ),
							'redirect'  => $payload['redirect'],
							'terminal'  => $terminal,
						);
					}
					if ( $this->is_exception( $terminal ) ) {
						$exception = $this->exception( $row, $payload, $terminal );
						$evidence_store->add( $job_id, absint( $row['id'] ?? 0 ), $exception );
						if ( count( $exceptions ) < self::EXCEPTION_SAMPLE_LIMIT ) {
							$exceptions[] = $exception;
						}
					}
				}
			}
			$row_count = count( $rows );
		} while ( 500 === $row_count );

		foreach ( $accounting as &$area ) {
			$area['classified'] = array_sum( $area['terminal'] );
			$area['balanced']   = $area['discovered'] === $area['classified'];
		}
		unset( $area );
		$balanced = ! in_array( false, array_column( $accounting, 'balanced' ), true );

		if ( isset( $report['counts'] ) && is_array( $report['counts'] ) ) {
			$report['counts']['fields_transformed'] = $transformed;
		}

		return array(
			'contract_version'    => 1,
			'accounting_scope'    => 'adapter_normalized_occurrences',
			'accounting'          => $accounting,
			'invariant'           => array(
				'code'   => 'every_discovered_occurrence_classified_once',
				'status' => $balanced ? 'pass' : 'fail',
			),
			'modifiers'           => array(
				'transformed'             => $transformed,
				'unresolved_placeholders' => $this->variable_diagnostics( $report ),
			),
			'semantic_comparison' => $semantic,
			'redirect_audit'      => $this->audit_redirects( $redirects, $mode ),
			'exceptions'          => $exceptions,
			'exception_count'     => $evidence_store->count( $job_id ),
			'exception_ledger'    => 'complete_paged_storage',
			'live_targets'        => array_values( $live_targets ),
			'rollback'            => $journal->summary( $job_id ),
		);
	}

	/**
	 * Returns a zeroed exclusive-outcome ledger.
	 *
	 * @return array{discovered:int,classified:int,balanced:bool,terminal:array<string,int>}
	 */
	private function empty_accounting(): array {
		return array(
			'discovered' => 0,
			'classified' => 0,
			'balanced'   => false,
			'terminal'   => array(
				'accounted'   => 0,
				'ready'       => 0,
				'imported'    => 0,
				'identical'   => 0,
				'transformed' => 0,
				'preserved'   => 0,
				'conflict'    => 0,
				'invalid'     => 0,
				'unsupported' => 0,
				'failed'      => 0,
			),
		);
	}

	/**
	 * Returns one exclusive metadata outcome.
	 *
	 * @param array<string,mixed> $row Queue row.
	 * @param string              $mode preview|import.
	 * @return string Terminal outcome.
	 */
	private function meta_terminal( array $row, string $mode ): string {
		$discovery = sanitize_key( (string) ( $row['discovery_status'] ?? '' ) );
		$apply     = sanitize_key( (string) ( $row['apply_status'] ?? '' ) );
		if ( 'written' === $apply ) {
			return 'imported';
		}
		if ( 'failed' === $apply ) {
			return 'failed';
		}
		if ( 'preserved' === $apply || 'existing' === $discovery ) {
			return 'preserved';
		}
		if ( in_array( $discovery, array( 'identical', 'duplicate' ), true ) ) {
			return 'identical';
		}
		if ( in_array( $discovery, array( 'conflict', 'invalid', 'unsupported' ), true ) ) {
			return $discovery;
		}
		if ( 'ready' === $discovery && 'preview' === $mode ) {
			return 'ready';
		}

		return 'failed';
	}

	/**
	 * Returns one exclusive redirect outcome.
	 *
	 * @param array<string,mixed> $row Queue row.
	 * @param string              $mode preview|import.
	 * @return string Terminal outcome.
	 */
	private function redirect_terminal( array $row, string $mode ): string {
		$discovery = sanitize_key( (string) ( $row['discovery_status'] ?? '' ) );
		$apply     = sanitize_key( (string) ( $row['apply_status'] ?? '' ) );
		if ( in_array( $apply, array( 'created', 'updated' ), true ) ) {
			return 'imported';
		}
		if ( 'failed' === $apply || 'failed' === $discovery ) {
			return 'failed';
		}
		if ( 'conflict' === $apply || 'conflict' === $discovery ) {
			return 'conflict';
		}
		if ( in_array( $discovery, array( 'unchanged', 'duplicate' ), true ) ) {
			return 'identical';
		}
		if ( in_array( $discovery, array( 'invalid', 'unsupported' ), true ) ) {
			return $discovery;
		}
		if ( str_starts_with( $discovery, 'ready_' ) && 'preview' === $mode ) {
			return 'ready';
		}

		return 'failed';
	}

	/**
	 * Adds a normalized before/after comparison without persisting raw SEO values.
	 *
	 * @param array<string,mixed> $semantic Aggregated comparison, passed by reference.
	 * @param array<string,mixed> $row Queue row.
	 * @param array<string,mixed> $payload Queue payload.
	 * @param string              $terminal Exclusive outcome.
	 * @param string              $mode preview|import.
	 */
	private function add_semantic_result( array &$semantic, array $row, array $payload, string $terminal, string $mode ): void {
		$key    = sanitize_key( (string) ( $payload['key'] ?? $row['target_field'] ?? '' ) );
		$domain = $this->semantic_domain( $key );
		if ( '' === $domain ) {
			return;
		}
		if ( ! isset( $semantic[ $domain ] ) ) {
			$semantic[ $domain ] = array(
				'compared' => 0,
				'matched'  => 0,
				'planned'  => 0,
				'mismatch' => 0,
				'samples'  => array(),
			);
		}

		$type = sanitize_key( (string) ( $payload['object_type'] ?? '' ) );
		$id   = absint( $payload['object_id'] ?? 0 );
		if ( 'preview' === $mode && 'ready' === $terminal ) {
			++$semantic[ $domain ]['planned'];
			return;
		}
		if ( ! in_array( $terminal, array( 'imported', 'identical' ), true ) || '' === $type || $id < 1 ) {
			return;
		}

		$expected = $payload['value'] ?? null;
		$current  = get_metadata( $type, $id, $key, true );
		$matched  = $this->canonical_json( $expected ) === $this->canonical_json( $current );
		++$semantic[ $domain ]['compared'];
		++$semantic[ $domain ][ $matched ? 'matched' : 'mismatch' ];
		if ( count( $semantic[ $domain ]['samples'] ) < 10 ) {
			$semantic[ $domain ]['samples'][] = array(
				'reference'   => sanitize_text_field( (string) ( $row['source_reference'] ?? '' ) ),
				'field'       => $key,
				'status'      => $matched ? 'match' : 'mismatch',
				'before_hash' => hash( 'sha256', $this->canonical_json( $expected ) ),
				'after_hash'  => hash( 'sha256', $this->canonical_json( $current ) ),
				'edit_url'    => $this->edit_url( $type, $id ),
			);
		}
	}

	/**
	 * Maps one EasyRankly field to the user-visible semantic area it affects.
	 *
	 * @param string $key EasyRankly metadata key.
	 * @return string Semantic domain or empty string.
	 */
	private function semantic_domain( string $key ): string {
		if ( str_contains( $key, 'title' ) ) {
			return 'title';
		}
		if ( str_contains( $key, 'canonical' ) ) {
			return 'canonical';
		}
		if ( str_contains( $key, 'robots' ) || str_contains( $key, 'noindex' ) || str_contains( $key, 'nofollow' ) ) {
			return 'robots';
		}
		if ( str_contains( $key, '_og_' ) || str_contains( $key, 'twitter' ) || str_contains( $key, 'facebook' ) ) {
			return 'social';
		}
		if ( str_contains( $key, 'schema' ) ) {
			return 'json_ld';
		}

		return '';
	}

	/**
	 * Builds static loop/chain/collision/regex and stored response diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $items Redirect evidence items.
	 * @param string                         $mode preview|import.
	 * @return array<string,mixed> Redirect audit.
	 */
	private function audit_redirects( array $items, string $mode ): array {
		$sources    = array();
		$loops      = array();
		$chains     = array();
		$dangerous  = array();
		$collisions = array();
		$probes     = array();
		$storage    = array(
			'expected' => 0,
			'imported' => 0,
			'identical' => 0,
			'tested'   => 0,
			'passed'   => 0,
			'failed'   => 0,
		);
		$repository = null;
		erankly_ensure_redirect_classes_available();
		if ( class_exists( 'ERankly_Redirects_Repository' ) && erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			$repository = new ERankly_Redirects_Repository();
		}

		foreach ( $items as $item ) {
			$rule   = $item['redirect'];
			$source = $this->path( (string) ( $rule['source_path'] ?? '' ) );
			$target = $this->internal_target_path( (string) ( $rule['target_url'] ?? '' ) );
			if ( isset( $sources[ $source ] ) ) {
				$collisions[] = $source;
			}
			$sources[ $source ] = $target;
			if ( '' !== $source && $source === $target ) {
				$loops[] = $source;
			}
			if ( 'regex' === (string) ( $rule['match_type'] ?? '' ) && $this->dangerous_regex( (string) ( $rule['source_path'] ?? '' ) ) ) {
				$dangerous[] = sanitize_text_field( (string) ( $item['row']['source_reference'] ?? $source ) );
			}

			$probe = array(
				'reference'         => sanitize_text_field( (string) ( $item['row']['source_reference'] ?? '' ) ),
				'source_path'       => $source,
				'expected_status'   => absint( $rule['status_code'] ?? 0 ),
				'expected_location' => esc_url_raw( (string) ( $rule['target_url'] ?? '' ) ),
				'storage_status'    => 'preview' === $mode ? 'not_applicable' : 'not_verified',
			);
			$terminal = (string) $item['terminal'];
			$expected = 'preview' !== $mode && in_array( $terminal, array( 'imported', 'identical' ), true );
			if ( $expected ) {
				++$storage['expected'];
				++$storage[ 'imported' === $terminal ? 'imported' : 'identical' ];
			}
			if ( $expected && $repository ) {
				$stored                  = $repository->find_by_hash( (string) $item['rule_hash'] );
				$probe['storage_status'] = $stored
					&& absint( $stored['status_code'] ?? 0 ) === $probe['expected_status']
					&& (string) ( $stored['target_url'] ?? '' ) === (string) ( $rule['target_url'] ?? '' ) ? 'pass' : 'fail';
				++$storage['tested'];
				++$storage[ 'pass' === $probe['storage_status'] ? 'passed' : 'failed' ];
			} elseif ( $expected ) {
				++$storage['tested'];
				++$storage['failed'];
			}
			if ( count( $probes ) < self::SAMPLE_LIMIT ) {
				$probes[] = $probe;
			}
		}

		foreach ( $sources as $source => $target ) {
			if ( '' !== $target && isset( $sources[ $target ] ) && $target !== $source ) {
				$chains[] = array( $source, $target, $sources[ $target ] );
			}
		}

		return array(
			'tested_without_following' => true,
			'storage_probes'           => $probes,
			'storage_summary'          => $storage,
			'loops'                    => array_values( array_unique( $loops ) ),
			'chains'                   => $chains,
			'collisions'               => array_values( array_unique( $collisions ) ),
			'dangerous_regex'          => array_values( array_unique( $dangerous ) ),
			'live_probe_state'         => 'pending_cutover',
		);
	}

	/**
	 * Returns report warnings related to variables/placeholders.
	 *
	 * @param array<string,mixed> $report Terminal report.
	 * @return array<int,array<string,string>> Variable diagnostics.
	 */
	private function variable_diagnostics( array $report ): array {
		$result = array();
		foreach ( is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array() as $warning ) {
			$haystack = strtolower( (string) ( $warning['code'] ?? '' ) . ' ' . (string) ( $warning['message'] ?? '' ) );
			if ( str_contains( $haystack, 'variable' ) || str_contains( $haystack, 'placeholder' ) ) {
				$result[] = array(
					'code'      => sanitize_key( (string) ( $warning['code'] ?? 'variable_warning' ) ),
					'reference' => sanitize_text_field( (string) ( $warning['reference'] ?? '' ) ),
				);
			}
		}

		return $result;
	}

	/**
	 * Builds a value-free exception row suitable for JSON and CSV export.
	 *
	 * @param array<string,mixed> $row Queue row.
	 * @param array<string,mixed> $payload Queue payload.
	 * @param string              $terminal Exclusive outcome.
	 * @return array<string,mixed> Exception row.
	 */
	private function exception( array $row, array $payload, string $terminal ): array {
		$type = sanitize_key( (string) ( $payload['object_type'] ?? $row['object_type'] ?? '' ) );
		$id   = absint( $payload['object_id'] ?? 0 );

		return array(
			'area'        => sanitize_key( (string) ( $row['item_kind'] ?? '' ) ),
			'outcome'     => $terminal,
			'reference'   => sanitize_text_field( (string) ( $row['source_reference'] ?? '' ) ),
			'target'      => sanitize_key( (string) ( $row['target_field'] ?? '' ) ),
			'object_type' => $type,
			'object_id'   => $id,
			'edit_url'    => $this->edit_url( $type, $id ),
		);
	}

	/**
	 * Whether an exclusive outcome must appear in the exception export.
	 *
	 * @param string $terminal Exclusive outcome.
	 * @return bool Whether this is an exception.
	 */
	private function is_exception( string $terminal ): bool {
		return in_array( $terminal, array( 'preserved', 'conflict', 'invalid', 'unsupported', 'failed' ), true );
	}

	/**
	 * Returns an edit link for a migrated object.
	 *
	 * @param string $type post|term|user.
	 * @param int    $id Object ID.
	 * @return string Admin edit URL.
	 */
	private function edit_url( string $type, int $id ): string {
		if ( $id < 1 ) {
			return '';
		}
		if ( 'post' === $type ) {
			$url = get_edit_post_link( $id, 'raw' );
			return is_string( $url ) ? $url : '';
		}
		if ( 'term' === $type ) {
			$term = get_term( $id );
			if ( $term instanceof WP_Term ) {
				$url = get_edit_term_link( $id, $term->taxonomy );
				return is_string( $url ) ? $url : '';
			}
		}
		if ( 'user' === $type ) {
			return admin_url( 'user-edit.php?user_id=' . $id );
		}

		return '';
	}

	/**
	 * Returns the public URL for a representative semantic probe.
	 *
	 * @param string $type post|term|user.
	 * @param int    $id Object ID.
	 * @return string Public URL.
	 */
	private function public_url( string $type, int $id ): string {
		if ( $id < 1 ) {
			return '';
		}
		if ( 'post' === $type ) {
			$url = get_permalink( $id );
		} elseif ( 'term' === $type ) {
			$url = get_term_link( $id );
		} elseif ( 'user' === $type ) {
			$url = get_author_posts_url( $id );
		} else {
			$url = '';
		}

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Conservative catastrophic-backtracking heuristic for imported regex rules.
	 *
	 * @param string $pattern Imported regex pattern.
	 * @return bool Whether review is required.
	 */
	private function dangerous_regex( string $pattern ): bool {
		return 1 === preg_match( '/(?:\.\*){2,}|\([^)]*[+*][^)]*\)[+*]|\[[^]]*\][+*][+*]/', $pattern );
	}

	/**
	 * Normalizes a redirect path for graph analysis.
	 *
	 * @param string $path Raw path.
	 * @return string Normalized path.
	 */
	private function path( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		return '/' === $path ? $path : untrailingslashit( $path );
	}

	/**
	 * Returns an internal target path, or an empty string for external targets.
	 *
	 * @param string $target Redirect target.
	 * @return string Internal path or empty string.
	 */
	private function internal_target_path( string $target ): string {
		$parts = wp_parse_url( $target );
		if ( false === $parts ) {
			return '';
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! empty( $parts['host'] ) && strtolower( (string) $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return '';
		}

		return $this->path( (string) ( $parts['path'] ?? $target ) );
	}

	/**
	 * Stable comparison encoding.
	 *
	 * @param mixed $value Value to normalize.
	 * @return string Canonical JSON.
	 */
	private function canonical_json( mixed $value ): string {
		if ( is_array( $value ) ) {
			if ( ! erankly_array_is_list( $value ) ) {
				ksort( $value );
			}
			foreach ( $value as $key => $item ) {
				if ( is_array( $item ) ) {
					$value[ $key ] = json_decode( $this->canonical_json( $item ), true );
				}
			}
		}

		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
