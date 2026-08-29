<?php
/**
 * WP-CLI integration checks for the third-party SEO migration adapters.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/migration-regression.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once ERANKLY_PATH . 'includes/migrations.php';

$failures = array();
$checks   = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$missing  = 'erankly-test-option-missing-' . wp_generate_uuid4();
$original = array();
$options  = array(
	'rank-math-options-titles',
	'rank-math-options-general',
	'rank-math-options-sitemap',
	'rank_math_modules',
	'wpseo',
	'wpseo_titles',
	'wpseo_social',
	'aioseo_options',
	'aioseo_options_dynamic',
	'aioseo_options_localized',
	'aioseo_options_dynamic_localized',
	'seopress_titles_option_name',
	'seopress_social_option_name',
	'seopress_xml_sitemap_option_name',
	'seopress_toggle',
);
foreach ( $options as $option ) {
	$original[ $option ] = get_option( $option, $missing );
}

$post_id = 0;

try {
	$user_ids = get_users(
		array(
			'number' => 1,
			'fields' => 'ids',
		)
	);
	$user_id  = ! empty( $user_ids ) ? absint( $user_ids[0] ) : 0;
	$assert( $user_id > 0, 'A local user is required for the Person identity fixture.' );

	update_option(
		'rank-math-options-titles',
		array(
			'pt_post_title'                   => '%title% - %sitename%',
			'pt_post_description'             => '%excerpt%',
			'pt_post_default_rich_snippet'    => 'article',
			'pt_post_default_article_type'    => 'BlogPosting',
			'author_archive_title'            => '%author_name% - %sitename%',
			'date_archive_title'              => '%date% - %sitename%',
			'search_title'                    => '%search_query% - %sitename%',
			'404_title'                       => 'Missing - %sitename%',
			'knowledgegraph_type'             => 'person',
			'knowledgegraph_id'               => $user_id,
		),
		false
	);
	update_option(
		'rank-math-options-general',
		array(
			'redirections_debug'    => 'off',
			'redirections_fallback' => 'default',
		),
		false
	);
	update_option( 'rank-math-options-sitemap', array(), false );
	update_option( 'rank_math_modules', array( 'redirections' ), false );

	update_option( 'wpseo', array(), false );
	update_option(
		'wpseo_titles',
		array(
			'title-post'                        => '%%title%% - %%sitename%%',
			'metadesc-post'                     => '%%excerpt%%',
			'schema-page-type-post'              => 'WebPage',
			'schema-article-type-post'           => 'BlogPosting',
			'title-author-wpseo'                 => '%%name%% - %%sitename%%',
			'title-archive-wpseo'                => '%%date%% - %%sitename%%',
			'title-search-wpseo'                 => '%%searchphrase%% - %%sitename%%',
			'title-404-wpseo'                    => 'Missing - %%sitename%%',
			'company_or_person'                  => 'person',
			'company_or_person_user_id'          => $user_id,
		),
		false
	);
	update_option( 'wpseo_social', array(), false );

	update_option(
		'aioseo_options',
		array(
			'searchAppearance' => array(
				'global'   => array(
					'siteTitle' => '#site_title',
					'schema'    => array(
						'siteRepresents' => 'person',
						'person'          => array( 'userId' => $user_id ),
					),
				),
				'archives' => array(
					'author' => array( 'title' => '#author_name - #site_title' ),
					'date'   => array( 'title' => '#date - #site_title' ),
				),
				'advanced' => array(
					'searchPage' => array( 'title' => '#search_query - #site_title' ),
					'404Page'    => array( 'title' => 'Missing - #site_title' ),
				),
			),
		),
		false
	);
	update_option( 'aioseo_options_dynamic', array(), false );
	update_option( 'aioseo_options_localized', array(), false );
	update_option( 'aioseo_options_dynamic_localized', array(), false );

	update_option(
		'seopress_titles_option_name',
		array(
			'seopress_titles_single_titles'              => array(
				'post' => array(
					'title' => '%%title%% - %%sitename%%',
					'desc'  => '%%excerpt%%',
				),
			),
			'seopress_titles_archives_author_title'      => '%%author_name%% - %%sitename%%',
			'seopress_titles_archives_date_title'        => '%%date%% - %%sitename%%',
			'seopress_titles_archives_search_title'      => '%%search_keywords%% - %%sitename%%',
			'seopress_titles_archives_404_title'         => 'Missing - %%sitename%%',
		),
		false
	);
	update_option(
		'seopress_social_option_name',
		array(
			'seopress_social_knowledge_type'    => 'person',
			'seopress_social_knowledge_user_id' => $user_id,
		),
		false
	);
	update_option( 'seopress_xml_sitemap_option_name', array(), false );
	update_option( 'seopress_toggle', array(), false );

	$expected_special = array(
		'author' => '{{author_name}} - {{site_name}}',
		'date'   => '{{archive_date}} - {{site_name}}',
		'search' => '{{search_query}} - {{site_name}}',
	);
	foreach ( array( 'rankmath', 'yoast', 'aioseo', 'seopress' ) as $source ) {
		$adapter  = erankly_migration_manager()->adapter( $source );
		$settings = $adapter ? $adapter->global_settings() : array();
		$assert( $adapter instanceof ERankly_Migration_Adapter, $source . ': adapter unavailable.' );
		foreach ( $expected_special as $context => $template ) {
			$actual = (string) ( $settings['global_special_meta'][ $context ]['title'] ?? '' );
			$assert( $template === $actual, $source . ': invalid ' . $context . ' archive template: ' . $actual );
		}
		$assert( ! array_key_exists( 'noindex', $settings['global_special_meta']['404'] ?? array() ), $source . ': an unspecified 404 robots policy should remain unset for safe default merging.' );
		$assert( $user_id === absint( $settings['schema_person_user_id'] ?? 0 ), $source . ': Person identity did not retain the local user.' );
	}
	$rankmath_settings = erankly_migration_manager()->adapter( 'rankmath' )->global_settings();
	$assert( 1 === (int) ( $rankmath_settings['enable_redirects'] ?? 0 ), 'Rank Math: the source redirect module did not enable EasyRankly redirects.' );
	$assert( 0 === (int) ( $rankmath_settings['redirect_exclude_admins'] ?? 1 ), 'Rank Math: normal redirects were incorrectly disabled for administrators.' );

	foreach ( array(
		'rankmath' => '%page%',
		'yoast'    => '%%page%%',
		'aioseo'   => '#page_number',
		'seopress' => '%%current_pagination%%',
	) as $source => $template ) {
		$assert( '{{pagination}}' === erankly_import_convert_variables( $template, $source ), $source . ': pagination variable was not converted to its native context token.' );
	}

	$gate_report = array(
		'mode'                        => 'import',
		'status'                      => 'complete',
		'source_fingerprint_verified' => true,
		'counts'                      => array( 'redirects_unchanged' => 1 ),
		'warnings'                    => array(),
		'html_baseline'               => array(
			'state'             => 'not_source_owned',
			'redirect_contract' => array(
				'state'          => 'verified',
				'tested'         => 1,
				'passed'         => 1,
				'failed'         => 0,
				'request_failed' => 0,
			),
		),
		'evidence'                    => array(
			'invariant'           => array( 'status' => 'pass' ),
			'accounting'          => array(),
			'semantic_comparison' => array(),
			'redirect_audit'      => array(
				'storage_summary' => array(
					'expected' => 1,
					'tested'   => 1,
					'passed'   => 1,
					'failed'   => 0,
				),
				'loops'             => array(),
				'chains'            => array(),
				'collisions'        => array(),
				'dangerous_regex'   => array(),
			),
		),
	);
	$gate        = ( new ERankly_Migration_Go_Live_Gate() )->evaluate( $gate_report );
	$gate_checks = array_column( $gate['checks'] ?? array(), null, 'code' );
	$assert( 'pass' === (string) ( $gate_checks['redirect_storage']['status'] ?? '' ), 'An unchanged redirect with verified storage did not pass the go-live gate.' );
	$gate_report['evidence']['redirect_audit']['storage_summary']['tested'] = 0;
	$gate_report['evidence']['redirect_audit']['storage_summary']['passed'] = 0;
	$gate                                                      = ( new ERankly_Migration_Go_Live_Gate() )->evaluate( $gate_report );
	$gate_checks                                               = array_column( $gate['checks'] ?? array(), null, 'code' );
	$assert( 'fail' === (string) ( $gate_checks['redirect_storage']['status'] ?? '' ), 'An unverified unchanged redirect incorrectly passed the go-live gate.' );
	$gate_report['evidence']['redirect_audit']['storage_summary']['tested'] = 1;
	$gate_report['evidence']['redirect_audit']['storage_summary']['passed'] = 1;
	$gate_report['html_baseline']['redirect_contract'] = array(
		'state'          => 'differences_found',
		'tested'         => 1,
		'passed'         => 0,
		'failed'         => 1,
		'request_failed' => 0,
	);
	$gate        = ( new ERankly_Migration_Go_Live_Gate() )->evaluate( $gate_report );
	$gate_checks = array_column( $gate['checks'] ?? array(), null, 'code' );
	$assert( 'fail' === (string) ( $gate_checks['redirect_runtime']['status'] ?? '' ), 'A redirect with a dead same-site target incorrectly passed the go-live gate.' );

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'draft',
			'post_title'  => 'EasyRankly migration regression fixture',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	$post_id = (int) $post_id;

	$fixtures = array(
		'rankmath' => array(
			'cursor' => array( 'stage' => 'post', 'after_id' => $post_id - 1 ),
			'meta'   => array(
				'rank_math_focus_keyword'  => 'alpha, beta',
				'rank_math_pillar_content' => 'on',
			),
			'cornerstone' => true,
		),
		'yoast' => array(
			'cursor' => array( 'stage' => 'post', 'after_id' => $post_id - 1 ),
			'meta'   => array(
				'_yoast_wpseo_focuskw'       => 'alpha, beta',
				'_yoast_wpseo_is_cornerstone' => '1',
			),
			'cornerstone' => true,
		),
		'aioseo' => array(
			'cursor' => array( 'stage' => 'v3_postmeta', 'after_id' => $post_id - 1 ),
			'meta'   => array( '_aioseop_keywords' => 'alpha, beta' ),
			'cornerstone' => false,
		),
		'seopress' => array(
			'cursor' => array( 'stage' => 'post', 'after_id' => $post_id - 1 ),
			'meta'   => array( '_seopress_analysis_target_kw' => 'alpha, beta' ),
			'cornerstone' => false,
		),
	);

	foreach ( $fixtures as $source => $fixture ) {
		foreach ( $fixture['meta'] as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		$adapter = erankly_migration_manager()->adapter( $source );
		$page    = $adapter ? $adapter->content_batch( $fixture['cursor'], 10 ) : array();
		$mapped  = array();
		foreach ( is_array( $page['records'] ?? null ) ? $page['records'] : array() as $record ) {
			if ( $post_id === absint( $record['object_id'] ?? 0 ) ) {
				$mapped = is_array( $record['meta'] ?? null ) ? $record['meta'] : array();
				break;
			}
		}
		$assert( array( 'alpha', 'beta' ) === ( $mapped['_erankly_focus_keywords'] ?? array() ), $source . ': focus keyphrases were not mapped to native metadata.' );
		$assert( ! array_key_exists( '_erankly_legacy_editorial', $mapped ), $source . ': obsolete editorial payload was imported.' );
		$assert( ! $fixture['cornerstone'] || true === ( $mapped['_erankly_cornerstone'] ?? false ), $source . ': cornerstone/pillar content was not mapped.' );
		foreach ( array_keys( $fixture['meta'] ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}
} catch ( Throwable $error ) {
	$failures[] = get_class( $error ) . ': ' . $error->getMessage();
} finally {
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $original as $option => $value ) {
		if ( $missing === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, false );
		}
	}
}

if ( $failures ) {
	WP_CLI::error( implode( "\n", $failures ) );
}

WP_CLI::success( sprintf( '%d migration regression checks passed.', $checks ) );
