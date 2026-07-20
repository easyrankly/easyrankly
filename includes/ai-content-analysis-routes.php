<?php
/**
 * Lazy REST dispatchers for content analysis and keyword suggestions.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maximum editor payload accepted by the analysis route (512 KiB). */
const ERANKLY_CONTENT_ANALYSIS_REST_CONTENT_LIMIT = 524288;

/**
 * Registers read, generate and delete endpoints for one post report.
 *
 * @return void
 */
function erankly_content_analysis_register_rest_routes(): void {
	$route       = '/ai/content-analysis/(?P<post_id>\d+)';
	$source_args = array(
		'title'   => array(
			'type'              => 'string',
			'required'          => true,
			'maxLength'         => 500,
			'sanitize_callback' => 'sanitize_text_field',
		),
		'content' => array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => 'erankly_content_analysis_validate_rest_content',
		),
	);

	register_rest_route(
		'erankly/v1',
		$route,
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'erankly_content_analysis_rest_get_dispatch',
				'permission_callback' => 'erankly_content_analysis_rest_permission',
				'args'                => erankly_content_analysis_rest_base_args(),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'erankly_content_analysis_rest_generate_dispatch',
				'permission_callback' => 'erankly_content_analysis_rest_permission',
				'args'                => array_merge(
					erankly_content_analysis_rest_base_args(),
					$source_args,
					array(
						'keywords'    => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => 10,
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 120,
							),
						),
						'cornerstone' => array(
							'type'              => 'boolean',
							'required'          => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					)
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'erankly_content_analysis_rest_delete_dispatch',
				'permission_callback' => 'erankly_content_analysis_rest_permission',
				'args'                => erankly_content_analysis_rest_base_args(),
			),
		)
	);

	register_rest_route(
		'erankly/v1',
		$route . '/keyword-suggestion',
		array(
			'methods'             => 'POST',
			'callback'            => 'erankly_content_analysis_rest_suggest_keyword_dispatch',
			'permission_callback' => 'erankly_content_analysis_rest_permission',
			'args'                => array_merge( erankly_content_analysis_rest_base_args(), $source_args ),
		)
	);
}

/**
 * Shared route arguments.
 *
 * @return array<string,array<string,mixed>>
 */
function erankly_content_analysis_rest_base_args(): array {
	return array(
		'post_id' => array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		),
	);
}

/**
 * Bounds raw editor markup before the implementation parses it.
 *
 * @param mixed $value Raw REST value.
 * @return bool
 */
function erankly_content_analysis_validate_rest_content( mixed $value ): bool {
	return is_string( $value ) && strlen( $value ) <= ERANKLY_CONTENT_ANALYSIS_REST_CONTENT_LIMIT;
}

/**
 * Authorizes access to a public, editable post type.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function erankly_content_analysis_rest_permission( WP_REST_Request $request ) {
	$post_id = absint( $request['post_id'] );
	$post    = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'erankly_content_analysis_not_found', __( 'Content not found.', 'easyrankly' ), array( 'status' => 404 ) );
	}

	$post_type = get_post_type_object( $post->post_type );
	if ( ! $post_type instanceof WP_Post_Type || ! $post_type->public || ! $post_type->show_ui ) {
		return new WP_Error( 'erankly_content_analysis_unsupported', __( 'This content type cannot be analyzed.', 'easyrankly' ), array( 'status' => 400 ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'erankly_content_analysis_forbidden', __( 'You cannot analyze this content.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Loads the implementation and its compact text helpers.
 *
 * @param bool $with_ai Whether the request will call the configured AI provider.
 * @return void
 */
function erankly_content_analysis_load_implementation( bool $with_ai = false ): void {
	erankly_load_ai_helpers();

	if ( $with_ai ) {
		erankly_load_ai_module();
	}

	require_once ERANKLY_PATH . 'includes/ai-content-analysis.php';
}

/**
 * Dispatches report retrieval after lazy-loading the implementation.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_content_analysis_rest_get_dispatch( WP_REST_Request $request ) {
	erankly_content_analysis_load_implementation();
	return erankly_content_analysis_rest_get( $request );
}

/**
 * Dispatches report generation with the AI implementation loaded.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_content_analysis_rest_generate_dispatch( WP_REST_Request $request ) {
	erankly_content_analysis_load_implementation( true );
	return erankly_content_analysis_rest_generate( $request );
}

/**
 * Dispatches a one-off focus-keyword suggestion with the AI implementation loaded.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_content_analysis_rest_suggest_keyword_dispatch( WP_REST_Request $request ) {
	erankly_content_analysis_load_implementation( true );
	return erankly_content_analysis_rest_suggest_keyword( $request );
}

/**
 * Dispatches report deletion without loading the AI provider client.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_content_analysis_rest_delete_dispatch( WP_REST_Request $request ) {
	erankly_content_analysis_load_implementation();
	return erankly_content_analysis_rest_delete( $request );
}
