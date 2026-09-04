<?php
/** REST routes for AJAX redirect row actions (toggle/delete). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes toggle/delete as REST routes so the admin table can update rows in place without a full page reload,
 * preserving the current search term and pagination state.
 */
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
			'/redirects/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $id_args,
			)
		);
	}

	/** Capability check shared by both routes. */
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
}
