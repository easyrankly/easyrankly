<?php
// phpcs:ignoreFile -- WP-CLI integration harness mutates only ephemeral test fixtures and restores settings.
/**
 * WordPress integration coverage for persistent AI content analysis.
 *
 * Run: wp eval-file wp-content/plugins/easyrankly/tests/content-analysis-wordpress-integration.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration test must run through WP-CLI.' );
}

$GLOBALS['erankly_content_analysis_wp_failures'] = array();
$GLOBALS['erankly_content_analysis_wp_response'] = '';

global $wpdb;

/**
 * Records an integration-test assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure detail.
 * @return void
 */
function erankly_content_analysis_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		$GLOBALS['erankly_content_analysis_wp_failures'][] = $message;
	}
}

/**
 * Returns the deterministic response selected by the integration test.
 *
 * @param mixed  $pre    Existing short-circuit value.
 * @param string $system System prompt.
 * @param string $user   User prompt.
 * @return string
 */
function erankly_content_analysis_wp_model( mixed $pre, string $system, string $user ): string {
	unset( $pre, $system, $user );
	return (string) $GLOBALS['erankly_content_analysis_wp_response'];
}

/**
 * Disables only the test request counter; production defaults remain covered
 * by the standalone AI rate-limit test.
 *
 * @param array<string,int> $config Rate-limit configuration.
 * @param string            $bucket Rate-limit bucket.
 * @return array<string,int>
 */
function erankly_content_analysis_wp_rate_limit( array $config, string $bucket ): array {
	if ( 'content_analysis' === $bucket ) {
		$config['max'] = 0;
	}

	return $config;
}

$original_settings = erankly_get_plugin_option( ERANKLY_OPTION, array() );
$original_settings = is_array( $original_settings ) ? $original_settings : array();
$original_user_id  = get_current_user_id();
$created_user_id   = 0;
$post_id           = 0;
$analysis_meta_key = '_erankly_content_analysis_v1';
$original_reports  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture snapshot restored in finally.
	$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $analysis_meta_key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name; value uses a placeholder.
	ARRAY_A
);
$original_reports  = is_array( $original_reports ) ? $original_reports : array();

try {
	$settings                            = $original_settings;
	$settings['ai_enabled']              = 1;
	$settings['enable_content_analysis'] = 1;
	erankly_update_plugin_option( ERANKLY_OPTION, $settings );
	if ( false === has_action( 'rest_api_init', 'erankly_bootstrap_content_analysis_rest_routes' ) ) {
		add_action( 'rest_api_init', 'erankly_bootstrap_content_analysis_rest_routes', 5 );
	}

	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ids',
		)
	);

	if ( empty( $administrators ) ) {
		$new_user = wp_create_user( 'erankly-analysis-test-' . wp_generate_password( 8, false ), wp_generate_password( 24 ) );
		if ( is_wp_error( $new_user ) ) {
			erankly_content_analysis_wp_assert( false, 'The test administrator could not be created.' );
		} else {
			$created_user_id = absint( $new_user );
			$user = new WP_User( $created_user_id );
			$user->set_role( 'administrator' );
		}
	}

	$test_user_id = ! empty( $administrators ) ? absint( $administrators[0] ) : absint( $created_user_id );
	wp_set_current_user( $test_user_id );

	add_filter( 'erankly_ai_available', '__return_true' );
	add_filter( 'erankly_ai_call', 'erankly_content_analysis_wp_model', 10, 3 );
	add_filter( 'erankly_ai_rate_limit', 'erankly_content_analysis_wp_rate_limit', 10, 2 );

	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Guida audit SEO',
			'post_content' => '<p>Versione salvata.</p>',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	erankly_content_analysis_wp_assert( is_int( $post_id ) && $post_id > 0, 'The test post could not be created.' );

	$GLOBALS['erankly_content_analysis_wp_response'] = '{"keyword":"audit SEO tecnico"}';
	$keyword_request = new WP_REST_Request( 'POST', '/erankly/v1/ai/content-analysis/' . $post_id . '/keyword-suggestion' );
	$keyword_request->set_param( 'post_id', $post_id );
	$keyword_request->set_param( 'title', 'Guida audit SEO' );
	$keyword_request->set_param( 'content', '<p>Una procedura completa per eseguire un audit SEO tecnico.</p>' );
	$keyword_response = rest_do_request( $keyword_request );
	erankly_content_analysis_wp_assert( $keyword_response instanceof WP_REST_Response && 200 === $keyword_response->get_status(), 'A valid model response must return a keyword suggestion.' );
	erankly_content_analysis_wp_assert( 'audit SEO tecnico' === ( $keyword_response->get_data()['keyword'] ?? '' ), 'The keyword suggestion must be returned in the REST response.' );
	erankly_content_analysis_wp_assert( '' === get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true ), 'A keyword suggestion must not persist a content-analysis report.' );

	$GLOBALS['erankly_content_analysis_wp_response'] = wp_json_encode(
		array(
			'verdict'          => 'partially_in_focus',
			'score'            => 74,
			'summary'          => 'La guida è coerente e può essere approfondita.',
			'search_intent'    => 'Informativo',
			'strengths'        => array( 'Introduzione chiara.' ),
			'keyword_results'  => array(
				array( 'keyword' => 'audit SEO', 'status' => 'strong', 'assessment' => 'Tema centrale.', 'evidence' => array( 'Titolo coerente.' ), 'recommendations' => array() ),
				array( 'keyword' => 'SEO tecnica', 'status' => 'partial', 'assessment' => 'Tema secondario.', 'evidence' => array(), 'recommendations' => array( 'Aggiungere un esempio.' ) ),
			),
			'priority_actions' => array(
				array( 'priority' => 'high', 'title' => 'Aggiungere un esempio', 'reason' => 'Manca una procedura.', 'action' => 'Inserire una breve sequenza operativa.' ),
			),
			'missing_topics'    => array( 'Controllo tecnico' ),
			'suggested_headings' => array(
				array( 'level' => 'h2', 'text' => 'Controlli tecnici', 'reason' => 'Completa il percorso.' ),
			),
			'suggested_sentences' => array(
				array( 'text' => 'Un audit SEO ordina gli interventi per priorità.', 'placement' => 'Introduzione', 'keyword' => 'audit SEO' ),
			),
			'pillar'            => array( 'readiness' => 'strong', 'summary' => 'Buona base pillar.', 'cluster_ideas' => array( 'Audit dei contenuti' ), 'link_actions' => array( 'Collegare la guida tecnica.' ) ),
			'warnings'          => array(),
		)
	);

	$unsaved_content = '<p>UNSAVED_SECRET_MARKER: guida aggiornata.</p><h2>Audit SEO tecnico</h2><p>Una procedura completa.</p>';
	$request         = new WP_REST_Request( 'POST', '/erankly/v1/ai/content-analysis/' . $post_id );
	$request->set_param( 'post_id', $post_id );
	$request->set_param( 'title', 'Guida audit SEO' );
	$request->set_param( 'content', $unsaved_content );
	$request->set_param( 'keywords', array( 'audit SEO', 'SEO tecnica' ) );
	$request->set_param( 'cornerstone', true );

	$generated = rest_do_request( $request );
	erankly_content_analysis_wp_assert( $generated instanceof WP_REST_Response && 200 === $generated->get_status(), 'A valid model response must generate a REST report.' );

	$stored = get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true );
	erankly_content_analysis_wp_assert( is_array( $stored ) && isset( $stored['report'] ), 'The latest structured report must be stored in post meta.' );
	erankly_content_analysis_wp_assert( ! str_contains( maybe_serialize( $stored ), 'UNSAVED_SECRET_MARKER' ), 'Raw editor content must not be duplicated in the stored report.' );
	erankly_content_analysis_wp_assert( strlen( maybe_serialize( $stored ) ) <= ERANKLY_CONTENT_ANALYSIS_MAX_STORED_BYTES, 'Stored reports must remain within the 32 KiB bound.' );

	$get_request = new WP_REST_Request( 'GET', '/erankly/v1/ai/content-analysis/' . $post_id );
	$get_request->set_param( 'post_id', $post_id );
	$stale_response = rest_do_request( $get_request )->get_data();
	erankly_content_analysis_wp_assert( true === $stale_response['stale'], 'An analysis of unsaved content must be stale against the older saved post.' );

	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_title'   => 'Guida audit SEO',
			'post_content' => $unsaved_content,
		)
	);
	update_post_meta( $post_id, '_erankly_focus_keywords', array( 'audit SEO', 'SEO tecnica' ) );
	update_post_meta( $post_id, '_erankly_cornerstone', '1' );
	$fresh_response = rest_do_request( $get_request )->get_data();
	erankly_content_analysis_wp_assert( false === $fresh_response['stale'], 'Saving the analyzed snapshot must make the stored report fresh.' );

	$stored_before_failure = get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true );
	$GLOBALS['erankly_content_analysis_wp_response'] = '{"verdict":"in_focus","score":90,"summary":"Incomplete","keyword_results":[]}';
	$invalid = rest_do_request( $request );
	erankly_content_analysis_wp_assert( $invalid instanceof WP_REST_Response && $invalid->get_status() >= 400, 'An incomplete model response must fail closed.' );
	erankly_content_analysis_wp_assert(
		$stored_before_failure === get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true ),
		'A failed reanalysis must preserve the previous valid report.'
	);

	$settings['ai_enabled'] = 0;
	erankly_update_plugin_option( ERANKLY_OPTION, $settings );
	$offline_response = rest_do_request( $get_request )->get_data();
	erankly_content_analysis_wp_assert( true === $offline_response['has_analysis'], 'A saved report must remain readable after AI is disabled.' );
	$delete_request = new WP_REST_Request( 'DELETE', '/erankly/v1/ai/content-analysis/' . $post_id );
	$delete_request->set_param( 'post_id', $post_id );
	rest_do_request( $delete_request );
	erankly_content_analysis_wp_assert( '' === get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true ), 'A report must remain deletable after AI is disabled.' );

	update_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, array( 'schema_version' => 1, 'report' => array( 'summary' => 'Cache cleanup fixture' ) ) );
	get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true );
	require_once ERANKLY_PATH . 'includes/reset.php';
	$deleted_count = erankly_delete_content_analysis_reports();
	erankly_content_analysis_wp_assert( count( $original_reports ) + 1 === $deleted_count, 'Site-wide cleanup must report the deleted row count.' );
	erankly_content_analysis_wp_assert( '' === get_post_meta( $post_id, ERANKLY_CONTENT_ANALYSIS_META_KEY, true ), 'Site-wide cleanup must invalidate the post-meta cache.' );
} finally {
	remove_filter( 'erankly_ai_available', '__return_true' );
	remove_filter( 'erankly_ai_call', 'erankly_content_analysis_wp_model', 10 );
	remove_filter( 'erankly_ai_rate_limit', 'erankly_content_analysis_wp_rate_limit', 10 );
	erankly_update_plugin_option( ERANKLY_OPTION, $original_settings );

	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( $created_user_id > 0 ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $created_user_id );
	}
	$restored_post_ids = array();
	foreach ( $original_reports as $original_report ) {
		$original_post_id = absint( $original_report['post_id'] ?? 0 );
		if ( $original_post_id < 1 ) {
			continue;
		}
		if ( ! isset( $restored_post_ids[ $original_post_id ] ) ) {
			delete_post_meta( $original_post_id, $analysis_meta_key );
			$restored_post_ids[ $original_post_id ] = true;
		}
		add_post_meta( $original_post_id, $analysis_meta_key, wp_slash( maybe_unserialize( $original_report['meta_value'] ?? '' ) ) );
	}
	wp_set_current_user( $original_user_id );
}

if ( ! empty( $GLOBALS['erankly_content_analysis_wp_failures'] ) ) {
	foreach ( $GLOBALS['erankly_content_analysis_wp_failures'] as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI integration-test output.
	}
	exit( 1 );
}

fwrite( STDOUT, "Persistent content analysis WordPress integration passed.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI integration-test output.
