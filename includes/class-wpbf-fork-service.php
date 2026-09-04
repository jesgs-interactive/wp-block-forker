<?php
/**
 * Core fork logic, used by the REST controller.
 */

namespace WPBF;

defined( 'ABSPATH' ) || exit;

class Fork_Service {

	/**
	 * Create a new post from already-serialized block markup.
	 *
	 * Idempotent: a repeated call with the same fork_token returns the
	 * post created by the first call instead of creating a duplicate.
	 *
	 * @param array $args {
	 *     @type int    $source_post_id
	 *     @type string $post_type
	 *     @type string $title
	 *     @type string $extracted_content Serialized block markup.
	 *     @type string $mode              'copy' or 'move'. Stored as provenance only —
	 *                                     this method never touches the source post.
	 *     @type string $fork_token
	 * }
	 * @return int|\WP_Error New post ID, or WP_Error.
	 */
	public function create_fork( array $args ) {
		$source_post_id = absint( $args['source_post_id'] ?? 0 );
		$post_type      = sanitize_key( $args['post_type'] ?? '' );
		$title          = (string) ( $args['title'] ?? '' );
		$content        = (string) ( $args['extracted_content'] ?? '' );
		$mode           = ( 'move' === ( $args['mode'] ?? '' ) ) ? 'move' : 'copy';
		$fork_token     = sanitize_key( $args['fork_token'] ?? '' );

		if ( ! $source_post_id || ! get_post( $source_post_id ) ) {
			return new \WP_Error(
				'wpbf_invalid_source',
				__( 'The source post could not be found.', 'wp-block-forker' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === trim( $content ) ) {
			return new \WP_Error(
				'wpbf_empty_selection',
				__( 'No block content was provided to fork.', 'wp-block-forker' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $fork_token ) {
			return new \WP_Error(
				'wpbf_missing_token',
				__( 'A fork_token is required.', 'wp-block-forker' ),
				array( 'status' => 400 )
			);
		}

		$existing = $this->find_existing_fork( $fork_token );
		if ( null !== $existing ) {
			return $existing;
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! $post_type_object->show_in_rest ) {
			return new \WP_Error(
				'wpbf_invalid_post_type',
				__( 'That post type cannot be forked into.', 'wp-block-forker' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === trim( $title ) ) {
			$title = __( 'Untitled fragment', 'wp-block-forker' );
		}

		$new_post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		update_post_meta( $new_post_id, '_wpbf_source_post_id', $source_post_id );
		update_post_meta( $new_post_id, '_wpbf_fork_mode', $mode );
		update_post_meta( $new_post_id, '_wpbf_fork_token', $fork_token );

		return $new_post_id;
	}

	protected function find_existing_fork( string $fork_token ): ?int {
		if ( '' === $fork_token ) {
			return null;
		}

		$existing = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_wpbf_fork_token',
				'meta_value'  => $fork_token,
			)
		);

		return ! empty( $existing ) ? (int) $existing[0] : null;
	}
}
