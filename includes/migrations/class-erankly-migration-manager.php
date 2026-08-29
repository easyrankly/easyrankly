<?php
/**
 * Orchestrates dry runs, conflict-safe writes and migration reports.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Third-party SEO migration service. */
final class ERankly_Migration_Manager {
	private const REPORTS_OPTION = 'erankly_migration_reports_v1';
	private const REPORT_LIMIT   = 10;
	private const DETAIL_LIMIT   = 100;

	/**
	 * Registered source adapters.
	 *
	 * @var array<string,ERankly_Migration_Adapter>
	 */
	private array $adapters = array();

	/**
	 * Returns all registered source adapters.
	 *
	 * @return array<string,ERankly_Migration_Adapter>
	 */
	public function adapters(): array {
		foreach ( array( 'yoast', 'rankmath', 'aioseo', 'seopress' ) as $source ) {
			$this->adapter( $source );
		}

		return $this->adapters;
	}

	/**
	 * Returns one source adapter.
	 *
	 * @param string $source Source adapter slug.
	 * @return ERankly_Migration_Adapter|null
	 */
	public function adapter( string $source ): ?ERankly_Migration_Adapter {
		$source = sanitize_key( $source );
		if ( isset( $this->adapters[ $source ] ) ) {
			return $this->adapters[ $source ];
		}

		$classes = array(
			'yoast'    => 'ERankly_Migration_Adapter_Yoast',
			'rankmath' => 'ERankly_Migration_Adapter_RankMath',
			'aioseo'   => 'ERankly_Migration_Adapter_AIOSEO',
			'seopress' => 'ERankly_Migration_Adapter_SEOPress',
		);
		if ( ! isset( $classes[ $source ] ) || ! function_exists( 'erankly_migration_load_adapter' ) || ! erankly_migration_load_adapter( $source ) ) {
			return null;
		}

		$class                     = $classes[ $source ];
		$this->adapters[ $source ] = new $class();

		return $this->adapters[ $source ];
	}

	/**
	 * Builds the immutable header and zeroed counters for a migration report.
	 *
	 * @param string $source  Source adapter slug.
	 * @param bool   $dry_run Whether writes are simulated.
	 * @param string $run_id  Optional pre-generated run UUID.
	 * @return array<string,mixed>
	 */
	public function new_report( string $source, bool $dry_run, string $run_id = '' ): array {
		$adapter = $this->adapter( $source );
		$run_id  = '' !== $run_id ? sanitize_text_field( $run_id ) : wp_generate_uuid4();

		return array(
			'id'             => $run_id,
			'source'         => sanitize_key( $source ),
			'source_label'   => $adapter ? $adapter->label() : sanitize_text_field( $source ),
			'source_version' => $adapter ? $adapter->version() : '',
			'mode'           => $dry_run ? 'preview' : 'import',
			'status'         => 'running',
			'started_at'     => gmdate( 'c' ),
			'completed_at'   => '',
			'capabilities'   => $adapter ? $adapter->capabilities() : array(),
			'counts'         => $this->empty_counts(),
			'details'        => array(),
			'warnings'       => array(),
		);
	}

	/**
	 * Runs a preview or real migration.
	 *
	 * @param string $source  Source adapter slug.
	 * @param bool   $dry_run Whether writes must be simulated.
	 * @return array<string,mixed>
	 */
	public function run( string $source, bool $dry_run ): array {
		$adapter = $this->adapter( $source );
		$report  = $this->new_report( $source, $dry_run );
		$run_id  = (string) $report['id'];

		if ( ! $adapter ) {
			$report['status']     = 'failed';
			$report['warnings'][] = array(
				'code'      => 'unknown_source',
				'message'   => 'Unknown migration source.',
				'reference' => '',
			);
			return $this->finish_report( $report );
		}

		try {
			$profile                      = $adapter->profile();
			$report['source_profile']     = $profile;
			$report['source_inventory']   = $adapter->inventory();
			$report['source_fingerprint'] = $adapter->fingerprint();
			if ( 'unsupported' === (string) ( $profile['storage_status'] ?? '' ) ) {
				$report['status']     = 'failed';
				$report['warnings'][] = array(
					'code'      => 'unsupported_source_storage',
					'message'   => 'The detected source version or storage signature is not certified. No data was written.',
					'reference' => '',
				);
				return $this->finish_report( $report );
			}

			if ( ! $adapter->is_available() ) {
				$report['status']     = 'failed';
				$report['warnings'][] = array(
					'code'      => 'no_source_data',
					'message'   => 'No importable source data was found.',
					'reference' => '',
				);
				return $this->finish_report( $report );
			}

			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'erankly_import_variable_diagnostics' ) ) {
				erankly_import_variable_diagnostics( null, true );
			}

			$planned      = array();
			$seen_objects = array();
			foreach ( $adapter->content_records() as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				$object_type = sanitize_key( (string) ( $record['object_type'] ?? '' ) );
				$object_id   = absint( $record['object_id'] ?? 0 );
				$reference   = sanitize_text_field( (string) ( $record['source_reference'] ?? $object_type . ':' . $object_id ) );
				$meta        = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array();

				if ( ! in_array( $object_type, array( 'post', 'term', 'user' ), true ) || $object_id < 1 ) {
					++$report['counts']['objects_invalid'];
					$this->detail( $report, 'invalid_object', $reference, '' );
					continue;
				}

				$object_identity = $object_type . ':' . $object_id;
				if ( ! isset( $seen_objects[ $object_identity ] ) ) {
					$seen_objects[ $object_identity ] = true;
					++$report['counts']['objects_found'];
					++$report['counts'][ $object_type . 's_found' ];
				}
				$this->apply_meta_record( $object_type, $object_id, $meta, $reference, $dry_run, $planned, $report );
			}

			$this->apply_redirects( $adapter, $run_id, $dry_run, $report );
			$failed_writes     = $report['counts']['fields_failed'] + $report['counts']['redirects_failed'];
			$successful_writes = $report['counts']['fields_written'] + $report['counts']['redirects_created'] + $report['counts']['redirects_updated'];
			$report['status']  = $failed_writes > 0 ? ( $successful_writes > 0 ? 'partial' : 'failed' ) : 'complete';
		} catch ( Throwable $error ) {
			$report['warnings'][] = array(
				'code'      => 'migration_interrupted',
				'message'   => 'Migration stopped because an unexpected source record could not be processed.',
				'reference' => sanitize_text_field( get_class( $error ) ),
			);
			$successful_writes    = $report['counts']['fields_written'] + $report['counts']['redirects_created'] + $report['counts']['redirects_updated'];
			$report['status']     = $successful_writes > 0 ? 'partial' : 'failed';
		}

		$variable_warnings  = function_exists( 'erankly_import_variable_diagnostics' ) ? erankly_import_variable_diagnostics() : array();
		$report['warnings'] = array_slice( array_merge( $report['warnings'], $adapter->warnings(), $variable_warnings ), 0, self::DETAIL_LIMIT );

		return $this->finish_report( $report );
	}

	/**
	 * Applies one object's mapped metadata.
	 *
	 * @param string              $object_type post|term|user.
	 * @param int                 $object_id   Object ID.
	 * @param array<string,mixed> $meta        Mapped EasyRankly metadata.
	 * @param string              $reference   Source reference.
	 * @param bool                $dry_run     Whether writes are simulated.
	 * @param array<string,mixed> $planned     Fields already planned in this run.
	 * @param array<string,mixed> $report      Running report.
	 * @return void
	 */
	private function apply_meta_record( string $object_type, int $object_id, array $meta, string $reference, bool $dry_run, array &$planned, array &$report ): void {
		$allowed = erankly_get_meta_keys();

		foreach ( $meta as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) || ! $this->is_meaningful( $value ) ) {
				continue;
			}

			++$report['counts']['fields_found'];
			$identity = $object_type . ':' . $object_id . ':' . $key;

			if ( array_key_exists( $identity, $planned ) ) {
				if ( wp_json_encode( $planned[ $identity ] ) === wp_json_encode( $value ) ) {
					++$report['counts']['fields_duplicate'];
				} else {
					++$report['counts']['fields_conflicts'];
					$this->detail( $report, 'source_conflict', $reference, $key );
				}
				continue;
			}

			if ( metadata_exists( $object_type, $object_id, $key ) ) {
				++$report['counts']['fields_skipped_existing'];
				$this->detail( $report, 'existing_value_preserved', $reference, $key );
				continue;
			}

			$clean = erankly_sanitize_registered_meta( $value, $key );
			if ( ! $this->is_meaningful( $clean ) ) {
				++$report['counts']['fields_invalid'];
				$this->detail( $report, 'invalid_value', $reference, $key );
				continue;
			}

			$planned[ $identity ] = $clean;
			++$report['counts']['fields_ready'];
			++$report['counts'][ $object_type . '_fields_ready' ];

			if ( $dry_run ) {
				continue;
			}

			// update_metadata() stores the supplied value directly. Unlike the
			// update_post_meta()/term/user wrappers it does not wp_unslash() first,
			// so pre-slashing here would corrupt regexes and JSON-LD backslashes.
			$result = update_metadata( $object_type, $object_id, $key, $clean );
			if ( false === $result ) {
				++$report['counts']['fields_failed'];
				$this->detail( $report, 'write_failed', $reference, $key );
			} else {
				++$report['counts']['fields_written'];
				++$report['counts'][ $object_type . '_fields_written' ];
			}
		}
	}

	/**
	 * Applies redirect records with source ownership and rule-hash conflict checks.
	 *
	 * @param ERankly_Migration_Adapter $adapter Source adapter.
	 * @param string                    $run_id  Migration UUID.
	 * @param bool                      $dry_run Whether writes are simulated.
	 * @param array<string,mixed>       $report  Running report.
	 * @return void
	 */
	private function apply_redirects( ERankly_Migration_Adapter $adapter, string $run_id, bool $dry_run, array &$report ): void {
		erankly_ensure_redirect_classes_available();

		if ( ! class_exists( 'ERankly_Redirects_Normalizer' ) || ! class_exists( 'ERankly_Redirects_Repository' ) ) {
			++$report['counts']['redirects_failed'];
			$report['warnings'][] = array(
				'code'      => 'redirect_engine_unavailable',
				'message'   => 'The EasyRankly redirect engine could not be loaded.',
				'reference' => '',
			);
			return;
		}

		$table_exists = erankly_table_exists( ERankly_Redirects_Repository::get_table_name() );
		if ( ! $dry_run && ! $table_exists && class_exists( 'ERankly_Redirects_Activator' ) ) {
			ERankly_Redirects_Activator::activate();
			$table_exists = erankly_table_exists( ERankly_Redirects_Repository::get_table_name() );
		}

		$repository = $table_exists ? new ERankly_Redirects_Repository() : null;
		if ( ! $dry_run && ! $repository ) {
			$report['warnings'][] = array(
				'code'      => 'redirect_storage_unavailable',
				'message'   => 'The redirect table could not be created, so redirect writes were not performed.',
				'reference' => '',
			);
		}
		if ( $repository && ! $dry_run ) {
			$repository->begin_bulk();
		}

		$planned = array();
		try {
			foreach ( $adapter->redirect_records() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				++$report['counts']['redirects_found'];
				$reference            = sanitize_text_field( (string) ( $row['source_reference'] ?? '' ) );
				$row['source_plugin'] = $adapter->slug();
				$row['migration_id']  = $run_id;
				$redirect             = erankly_import_prepare_redirect( $row );

				if ( null === $redirect ) {
					++$report['counts']['redirects_invalid'];
					$this->detail( $report, 'invalid_redirect', $reference, '' );
					continue;
				}

				$rule_hash  = ERankly_Redirects_Normalizer::rule_hash( $redirect );
				$value_hash = $this->redirect_value_hash( $redirect );
				if ( isset( $planned[ $rule_hash ] ) ) {
					if ( hash_equals( (string) $planned[ $rule_hash ], $value_hash ) ) {
						++$report['counts']['redirects_duplicate'];
					} else {
						++$report['counts']['redirects_conflicts'];
						$this->detail( $report, 'source_redirect_conflict', $reference, '' );
					}
					continue;
				}
				$planned[ $rule_hash ] = $value_hash;

				$existing = $repository ? $repository->find_by_hash( $rule_hash ) : null;
				if ( $existing && $adapter->slug() !== (string) ( $existing['source_plugin'] ?? '' ) ) {
					++$report['counts']['redirects_conflicts'];
					$this->detail( $report, 'redirect_conflict_preserved', $reference, '' );
					continue;
				}

				if ( $existing && $this->same_redirect( $existing, $redirect ) ) {
					++$report['counts']['redirects_unchanged'];
					continue;
				}

				if ( $existing ) {
					++$report['counts']['redirects_ready_update'];
					if ( ! $dry_run ) {
						if ( $repository && $repository->update( (int) $existing['id'], $redirect ) ) {
							++$report['counts']['redirects_updated'];
						} else {
							++$report['counts']['redirects_failed'];
						}
					}
					continue;
				}

				++$report['counts']['redirects_ready_create'];
				if ( ! $dry_run ) {
					if ( $repository && $repository->create( $redirect ) > 0 ) {
						++$report['counts']['redirects_created'];
					} else {
						++$report['counts']['redirects_failed'];
					}
				}
			}
		} finally {
			if ( $repository && ! $dry_run ) {
				$repository->end_bulk();
			}
		}
	}

	/**
	 * Checks whether stored and proposed redirects have identical behavior.
	 *
	 * @param array<string,mixed> $existing Stored redirect.
	 * @param array<string,mixed> $proposed Proposed redirect.
	 * @return bool
	 */
	public function same_redirect( array $existing, array $proposed ): bool {
		return hash_equals( $this->redirect_value_hash( $existing ), $this->redirect_value_hash( $proposed ) );
	}

	/**
	 * Hashes redirect behavior without provenance or migration-run fields.
	 *
	 * @param array<string,mixed> $redirect Normalized or stored redirect.
	 * @return string
	 */
	public function redirect_value_hash( array $redirect ): string {
		$keys     = array( 'source_path', 'source_query', 'target_url', 'status_code', 'match_type', 'is_regex', 'is_wildcard', 'case_sensitive', 'trailing_slash', 'query_mode', 'priority', 'is_active', 'visibility', 'required_role', 'conditions', 'start_at', 'end_at' );
		$behavior = array();
		foreach ( $keys as $key ) {
			$value            = $redirect[ $key ] ?? '';
			$behavior[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
		}

		return hash( 'sha256', (string) wp_json_encode( $behavior ) );
	}

	/**
	 * Checks whether a mapped value should be considered importable.
	 *
	 * @param mixed $value Mapped value.
	 * @return bool
	 */
	public function is_meaningful( mixed $value ): bool {
		if ( true === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		return is_string( $value ) && '' !== trim( $value );
	}

	/**
	 * Adds a bounded detail row.
	 *
	 * @param array<string,mixed> $report    Running report.
	 * @param string              $code      Detail code.
	 * @param string              $reference Source record reference.
	 * @param string              $field     Target field.
	 * @return void
	 */
	private function detail( array &$report, string $code, string $reference, string $field ): void {
		if ( count( $report['details'] ) >= self::DETAIL_LIMIT ) {
			return;
		}

		$report['details'][] = array(
			'code'      => sanitize_key( $code ),
			'reference' => sanitize_text_field( $reference ),
			'field'     => sanitize_key( $field ),
		);
	}

	/**
	 * Returns a zeroed report counter map.
	 *
	 * @return array<string,int>
	 */
	public function empty_counts(): array {
		return array(
			'settings_found'          => 0,
			'settings_ready'          => 0,
			'settings_written'        => 0,
			'settings_skipped_existing' => 0,
			'settings_identical'      => 0,
			'settings_conflicts'      => 0,
			'settings_invalid'        => 0,
			'settings_failed'         => 0,
			'objects_found'           => 0,
			'posts_found'             => 0,
			'terms_found'             => 0,
			'users_found'             => 0,
			'objects_invalid'         => 0,
			'fields_found'            => 0,
			'fields_ready'            => 0,
			'fields_written'          => 0,
			'post_fields_ready'       => 0,
			'post_fields_written'     => 0,
			'term_fields_ready'       => 0,
			'term_fields_written'     => 0,
			'user_fields_ready'       => 0,
			'user_fields_written'     => 0,
			'fields_skipped_existing' => 0,
			'fields_identical'        => 0,
			'fields_transformed'      => 0,
			'fields_unsupported'      => 0,
			'fields_duplicate'        => 0,
			'fields_conflicts'        => 0,
			'fields_invalid'          => 0,
			'fields_failed'           => 0,
			'redirects_found'         => 0,
			'redirects_ready_create'  => 0,
			'redirects_ready_update'  => 0,
			'redirects_created'       => 0,
			'redirects_updated'       => 0,
			'redirects_unchanged'     => 0,
			'redirects_duplicate'     => 0,
			'redirects_conflicts'     => 0,
			'redirects_invalid'       => 0,
			'redirects_failed'        => 0,
		);
	}

	/**
	 * Completes and persists a bounded report history.
	 *
	 * @param array<string,mixed> $report Running report.
	 * @return array<string,mixed>
	 */
	public function finish_report( array $report ): array {
		if ( empty( $report['completed_at'] ) ) {
			$report['completed_at'] = gmdate( 'c' );
		}
		$report['verification']   = $this->build_verification( $report );
		$report['go_live_gate']   = $this->evaluate_go_live_gate( $report );
		$report                   = $this->synchronize_verification_with_gate( $report );
		$reports                  = get_option( self::REPORTS_OPTION, array() );
		$reports                  = is_array( $reports ) ? $reports : array();
		$reports[ $report['id'] ] = $report;

		$report_count = count( $reports );
		while ( $report_count > self::REPORT_LIMIT ) {
			$expired_report_id = (string) array_key_first( $reports );
			array_shift( $reports );
			if ( '' !== $expired_report_id && class_exists( 'ERankly_Migration_Evidence_Store' ) ) {
				( new ERankly_Migration_Evidence_Store() )->delete_job( $expired_report_id );
			}
			--$report_count;
		}

		update_option( self::REPORTS_OPTION, $reports, false );

		return $report;
	}

	/**
	 * Evaluates the strict go-live gate, optionally refreshing rollback expiry.
	 *
	 * @param array<string,mixed> $report Terminal migration report.
	 * @param bool                $refresh_rollback Whether to read the live journal state.
	 * @return array<string,mixed>
	 */
	public function evaluate_go_live_gate( array $report, bool $refresh_rollback = false ): array {
		$rollback = is_array( $report['evidence']['rollback'] ?? null ) ? $report['evidence']['rollback'] : array();
		if ( $refresh_rollback && 'import' === (string) ( $report['mode'] ?? '' ) && function_exists( 'erankly_migration_journal' ) ) {
			try {
				$rollback = erankly_migration_journal()->summary( (string) ( $report['id'] ?? '' ) );
			} catch ( Throwable ) {
				$rollback = array_merge(
					$rollback,
					array(
						'expired'   => true,
						'available' => 0,
					)
				);
			}
		}

		return ( new ERankly_Migration_Go_Live_Gate() )->evaluate( $report, $rollback );
	}

	/**
	 * Builds a deterministic post-preview/import decision and switch checklist.
	 *
	 * @param array<string,mixed> $report Terminal migration report.
	 * @return array<string,mixed>
	 */
	private function build_verification( array $report ): array {
		$counts          = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
		$mode            = (string) ( $report['mode'] ?? '' );
		$status          = (string) ( $report['status'] ?? 'failed' );
		$failed          = (int) ( $counts['settings_failed'] ?? 0 ) + (int) ( $counts['fields_failed'] ?? 0 ) + (int) ( $counts['redirects_failed'] ?? 0 );
		$invalid         = (int) ( $counts['settings_invalid'] ?? 0 ) + (int) ( $counts['objects_invalid'] ?? 0 ) + (int) ( $counts['fields_invalid'] ?? 0 ) + (int) ( $counts['redirects_invalid'] ?? 0 );
		$conflicts       = (int) ( $counts['settings_conflicts'] ?? 0 ) + (int) ( $counts['fields_conflicts'] ?? 0 ) + (int) ( $counts['redirects_conflicts'] ?? 0 );
		$warnings        = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : array();
		$blocking_warnings = array_filter(
			$warnings,
			static fn( mixed $warning ): bool => ! is_array( $warning ) || ! isset( $warning['blocking'] ) || (bool) $warning['blocking']
		);
		$source_verified = ! empty( $report['source_fingerprint_verified'] );
		$evidence        = is_array( $report['evidence'] ?? null ) ? $report['evidence'] : array();
		$invariant_pass  = empty( $evidence ) || 'pass' === (string) ( $evidence['invariant']['status'] ?? '' );
		$blocked         = 'complete' !== $status || $failed > 0 || ! $source_verified || ! $invariant_pass || ! empty( $blocking_warnings );
		$needs_review    = $invalid > 0 || $conflicts > 0;

		if ( $blocked ) {
			$state = 'blocked';
		} elseif ( $needs_review ) {
			$state = 'review';
		} elseif ( 'preview' === $mode ) {
			$state = 'ready';
		} else {
			$state = 'safe';
		}

		$checks = array(
			array(
				'code'   => 'source_integrity',
				'status' => $source_verified ? 'pass' : 'fail',
				'count'  => $source_verified ? 0 : 1,
			),
			array(
				'code'   => 'write_failures',
				'status' => $failed > 0 ? 'fail' : ( 'preview' === $mode ? 'not_applicable' : 'pass' ),
				'count'  => $failed,
			),
			array(
				'code'   => 'invalid_records',
				'status' => $invalid > 0 ? 'warn' : 'pass',
				'count'  => $invalid,
			),
			array(
				'code'   => 'conflicts',
				'status' => $conflicts > 0 ? 'warn' : 'pass',
				'count'  => $conflicts,
			),
			array(
				'code'   => 'diagnostics',
				'status' => $blocking_warnings ? 'warn' : 'pass',
				'count'  => count( $blocking_warnings ),
			),
			array(
				'code'   => 'accounting_invariant',
				'status' => $invariant_pass ? 'pass' : 'fail',
				'count'  => $invariant_pass ? 0 : 1,
			),
		);

		$next_actions = array();
		if ( 'blocked' === $state ) {
			$next_actions[] = 'keep_source_active';
			$next_actions[] = 'resolve_blockers';
		} elseif ( 'preview' === $mode ) {
			if ( 'review' === $state ) {
				$next_actions[] = 'review_diagnostics';
			}
			$next_actions[] = 'run_import';
		} else {
			if ( 'review' === $state ) {
				$next_actions[] = 'review_diagnostics';
				$next_actions[] = 'keep_source_active';
			}
			$next_actions[] = 'controlled_deactivation';
			$next_actions[] = 'purge_caches';
			$next_actions[] = 'verify_frontend';
			$next_actions[] = 'retain_source_backup';
		}

		return array(
			'state'           => $state,
			'ready_to_import' => 'preview' === $mode && ! $blocked,
			'ready_to_switch' => 'import' === $mode && 'safe' === $state,
			'checks'          => $checks,
			'next_actions'    => $next_actions,
		);
	}

	/**
	 * Keeps the legacy summary aligned with the authoritative go-live gate.
	 *
	 * @param array<string,mixed> $report Migration report.
	 * @return array<string,mixed>
	 */
	private function synchronize_verification_with_gate( array $report ): array {
		if ( 'import' !== (string) ( $report['mode'] ?? '' ) ) {
			return $report;
		}

		$gate  = is_array( $report['go_live_gate'] ?? null ) ? $report['go_live_gate'] : array();
		$state = sanitize_key( (string) ( $gate['state'] ?? 'blocked' ) );
		if ( ! isset( $report['verification'] ) || ! is_array( $report['verification'] ) ) {
			$report['verification'] = array();
		}

		$report['verification']['ready_to_switch'] = ! empty( $gate['ready_for_cutover'] ) || ! empty( $gate['go_live'] );
		if ( 'rolled_back' === $state ) {
			$report['verification']['state'] = 'rolled_back';
		} elseif ( in_array( $state, array( 'ready_for_cutover', 'go_live' ), true ) ) {
			$report['verification']['state'] = 'safe';
		} else {
			$report['verification']['state'] = 'blocked';
		}

		return $report;
	}

	/**
	 * Returns a persisted migration report.
	 *
	 * @param string $report_id Migration UUID.
	 * @return array<string,mixed>|null
	 */
	public function get_report( string $report_id ): ?array {
		$reports = get_option( self::REPORTS_OPTION, array() );
		if ( ! is_array( $reports ) || ! isset( $reports[ $report_id ] ) || ! is_array( $reports[ $report_id ] ) ) {
			return null;
		}
		$report                 = $reports[ $report_id ];
		$report['go_live_gate'] = $this->evaluate_go_live_gate( $report, true );
		$report                 = $this->synchronize_verification_with_gate( $report );

		return $report;
	}

	/**
	 * Returns reports ordered newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function reports(): array {
		$reports = get_option( self::REPORTS_OPTION, array() );
		if ( ! is_array( $reports ) ) {
			return array();
		}
		$reports = array_reverse( array_values( $reports ) );
		foreach ( $reports as &$report ) {
			if ( is_array( $report ) ) {
				$report['go_live_gate'] = $this->evaluate_go_live_gate( $report, true );
				$report                 = $this->synchronize_verification_with_gate( $report );
			}
		}
		unset( $report );

		return array_values( array_filter( $reports, 'is_array' ) );
	}

	/**
	 * Replaces one existing report after a privileged verification or rollback action.
	 *
	 * @param array<string,mixed> $report Updated report.
	 * @return bool Whether storage changed.
	 */
	public function update_report( array $report ): bool {
		$report_id = sanitize_text_field( (string) ( $report['id'] ?? '' ) );
		$reports   = get_option( self::REPORTS_OPTION, array() );
		if ( '' === $report_id || ! is_array( $reports ) || ! isset( $reports[ $report_id ] ) ) {
			return false;
		}
		$report['go_live_gate'] = $this->evaluate_go_live_gate( $report, true );
		$report                 = $this->synchronize_verification_with_gate( $report );
		$reports[ $report_id ]  = $report;

		return update_option( self::REPORTS_OPTION, $reports, false );
	}
}
