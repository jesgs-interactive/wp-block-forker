<?php
/**
 * REST routes the editor UI calls. They only write to the fork/merge
 * destination — see Fork_Service and AGENTS.md for why "move" removal of
 * source blocks is not done here.
 */

namespace WPBF;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	protected Fork_Service $fork_service;

	public function __construct( Fork_Service $fork_service ) {
		$this->fork_service = $fork_service;
	}

	public function register_routes(): void {
		register_rest_route(
			'wpbf/v1',
			'/fork',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_fork' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'source_post_id'    => $this->post_id_arg(),
					'post_type'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'title'             => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'extracted_content' => array(
						'required' => true,
						'type'     => 'string',
					),
					'mode'              => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'copy', 'move' ),
					),
					'fork_token'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'wpbf/v1',
			'/merge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_merge' ),
				'permission_callback' => array( $this, 'check_merge_permission' ),
				'args'                => array(
					'source_post_id'    => $this->post_id_arg(),
					'target_post_id'    => $this->post_id_arg(),
					'extracted_content' => array(
						'required' => true,
						'type'     => 'string',
					),
					'position'          => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'append', 'prepend' ),
					),
					'merge_token'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	protected function post_id_arg(): array {
		return array(
			'required'          => true,
			'type'              => 'integer',
			// Bare 'is_numeric' breaks here: has_valid_params() calls
			// validate_callback with 3 args ($value, $request, $param),
			// and PHP 8's strict arity check throws ArgumentCountError
			// on internal functions given more args than they declare.
			'validate_callback' => static function ( $value ) {
				return is_numeric( $value );
			},
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Two gates: can the user edit the source post, and can they create
	 * posts of the requested target type. Object-specific edit_post — not
	 * the generic edit_posts — so authors can't fork out of a post that
	 * isn't theirs.
	 */
	public function check_permission( \WP_REST_Request $request ) {
		$source_post_id = absint( $request->get_param( 'source_post_id' ) );

		if ( ! current_user_can( 'edit_post', $source_post_id ) ) {
			return new \WP_Error(
				'wpbf_forbidden_source',
				__( 'You cannot edit the source post.', 'wp-block-forker' ),
				array( 'status' => 403 )
			);
		}

		$post_type        = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			return new \WP_Error(
				'wpbf_forbidden_target',
				__( 'You cannot create posts of that type.', 'wp-block-forker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Object-specific edit_post on both ends. No create_posts check: a merge
	 * only writes to a post that already exists.
	 */
	public function check_merge_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'edit_post', absint( $request->get_param( 'source_post_id' ) ) ) ) {
			return new \WP_Error(
				'wpbf_forbidden_source',
				__( 'You cannot edit the source post.', 'wp-block-forker' ),
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( 'edit_post', absint( $request->get_param( 'target_post_id' ) ) ) ) {
			return new \WP_Error(
				'wpbf_forbidden_target',
				__( 'You cannot edit the target post.', 'wp-block-forker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle_merge( \WP_REST_Request $request ) {
		$result = $this->fork_service->merge_into(
			array(
				'source_post_id'    => $request->get_param( 'source_post_id' ),
				'target_post_id'    => $request->get_param( 'target_post_id' ),
				'extracted_content' => $request->get_param( 'extracted_content' ),
				'position'          => $request->get_param( 'position' ),
				'merge_token'       => $request->get_param( 'merge_token' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'id'       => $result,
				'edit_url' => get_edit_post_link( $result, 'raw' ),
			),
			200
		);
	}

	public function handle_fork( \WP_REST_Request $request ) {
		$result = $this->fork_service->create_fork(
			array(
				'source_post_id'    => $request->get_param( 'source_post_id' ),
				'post_type'         => $request->get_param( 'post_type' ),
				'title'             => $request->get_param( 'title' ),
				'extracted_content' => $request->get_param( 'extracted_content' ),
				'mode'              => $request->get_param( 'mode' ),
				'fork_token'        => $request->get_param( 'fork_token' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'id'       => $result,
				'edit_url' => get_edit_post_link( $result, 'raw' ),
			),
			201
		);
	}
}
