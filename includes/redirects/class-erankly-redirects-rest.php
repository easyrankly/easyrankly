<?php
/** REST routes for AJAX redirect row actions (toggle/delete). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	/** Exposes redirect management helpers to the authenticated admin UI. */
final class ERankly_Redirects_Rest {

	private ERankly_Redirects_Repository $repository;

	public function __construct( ERankly_Redirects_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$id_args = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			'erankly/v1',
			'/redirects/toggle',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $id_args,
			)
		);

		register_rest_route(
			'erankly/v1',
			'/redirects/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_rule' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'erankly/v1',
			'/redirects/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $id_args,
			)
		);
	}

	/** Capability check shared by all three routes. */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/** @return WP_REST_Response|WP_Error */
	public function toggle( WP_REST_Request $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$redirect = $id > 0 ? $this->repository->find_by_id( $id ) : null;

		if ( ! $redirect ) {
			return new WP_Error( 'erankly_redirect_not_found', __( 'Redirect not found.', 'easyrankly' ), array( 'status' => 404 ) );
		}

		if ( ! $this->repository->toggle_active( $id ) ) {
			return new WP_Error( 'erankly_redirect_toggle_failed', __( 'The redirect status could not be changed.', 'easyrankly' ), array( 'status' => 500 ) );
		}

		$updated = $this->repository->find_by_id( $id );

		return rest_ensure_response(
			array(
				'success'   => true,
				'is_active' => ! empty( $updated['is_active'] ),
			)
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function delete( WP_REST_Request $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$redirect = $id > 0 ? $this->repository->find_by_id( $id ) : null;

		if ( ! $redirect ) {
			return new WP_Error( 'erankly_redirect_not_found', __( 'Redirect not found.', 'easyrankly' ), array( 'status' => 404 ) );
		}

		if ( ! $this->repository->delete( $id ) ) {
			return new WP_Error( 'erankly_redirect_delete_failed', __( 'The redirect could not be deleted.', 'easyrankly' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function test_rule( WP_REST_Request $request ) {
		$match_type = sanitize_key( (string) $request->get_param( 'match_type' ) );
		$query_mode = sanitize_key( (string) $request->get_param( 'query_mode' ) );
		if ( ! in_array( $match_type, ERankly_Redirects_Normalizer::VALID_MATCH_TYPES, true ) || ! in_array( $query_mode, ERankly_Redirects_Normalizer::VALID_QUERY_MODES, true ) ) {
			return new WP_Error( 'erankly_redirect_test_invalid_mode', __( 'Select a valid matching mode.', 'easyrankly' ), array( 'status' => 400 ) );
		}

		$case_sensitive = rest_sanitize_boolean( $request->get_param( 'case_sensitive' ) );
		$trailing_slash = 'exact' === (string) $request->get_param( 'trailing_slash' ) ? 'exact' : 'ignore';
		$status_code    = absint( $request->get_param( 'status_code' ) );
		$source_path    = ERankly_Redirects_Normalizer::normalize_source(
			sanitize_text_field( (string) $request->get_param( 'source_path' ) ),
			'regex' === $match_type,
			'wildcard' === $match_type,
			$case_sensitive,
			$trailing_slash
		);
		$test_url       = sanitize_text_field( (string) $request->get_param( 'test_url' ) );

		if ( '' === $source_path || '' === $test_url ) {
			return new WP_Error( 'erankly_redirect_test_required', __( 'Enter a source and a URL to test.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		if ( 'exact' === $match_type && ! ERankly_Redirects_Normalizer::is_valid_internal_path( $source_path ) ) {
			return new WP_Error( 'erankly_redirect_test_source', __( 'Enter a valid internal source path.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		if ( 'wildcard' === $match_type && ! ERankly_Redirects_Normalizer::is_valid_wildcard_source( $source_path ) ) {
			return new WP_Error( 'erankly_redirect_test_wildcard', __( 'The wildcard pattern is not valid.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		if ( 'regex' === $match_type && ! ERankly_Redirects_Normalizer::is_valid_regex( $source_path ) ) {
			return new WP_Error( 'erankly_redirect_test_regex', __( 'The regular expression is not valid or is too expensive.', 'easyrankly' ), array( 'status' => 400 ) );
		}

		$rule = array(
			'source_path'      => $source_path,
			'source_query'     => 'exact' === $query_mode ? ltrim( sanitize_text_field( (string) $request->get_param( 'source_query' ) ), '?' ) : '',
			'target_url'       => ERankly_Redirects_Normalizer::normalize_target_url( (string) $request->get_param( 'target_url' ) ),
			'match_type'       => $match_type,
			'case_sensitive'   => $case_sensitive ? 1 : 0,
			'trailing_slash'   => $trailing_slash,
			'query_mode'       => $query_mode,
		);
		if ( ! in_array( $status_code, array( 410, 451 ), true ) && '' === $rule['target_url'] ) {
			return new WP_Error( 'erankly_redirect_test_target', __( 'Enter a target URL for this redirect.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		$evaluation = ERankly_Redirects_Normalizer::evaluate_rule( $rule, $test_url );

		return rest_ensure_response(
			array(
				'matches'    => ! empty( $evaluation['matches'] ),
				'target_url'  => in_array( $status_code, array( 410, 451 ), true ) ? '' : (string) ( $evaluation['target_url'] ?? '' ),
				'status_only' => in_array( $status_code, array( 410, 451 ), true ),
			)
		);
	}
}
