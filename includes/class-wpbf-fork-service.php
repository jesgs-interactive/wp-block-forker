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

	/**
	 * Append or prepend already-serialized block markup to an existing post.
	 *
	 * Idempotent per target: a repeated call with a merge_token already
	 * recorded on the target returns success without writing again.
	 *
	 * @param array $args {
	 *     @type int    $source_post_id
	 *     @type int    $target_post_id
	 *     @type string $extracted_content Serialized block markup.
	 *     @type string $position          'append' or 'prepend'.
	 *     @type string $merge_token
	 * }
	 * @return int|\WP_Error Target post ID, or WP_Error.
	 */
	public function merge_into( array $args ) {
		$source_post_id = absint( $args['source_post_id'] ?? 0 );
		$target_post_id = absint( $args['target_post_id'] ?? 0 );
		$content        = trim( (string) ( $args['extracted_content'] ?? '' ) );
		$position       = ( 'prepend' === ( $args['position'] ?? '' ) ) ? 'prepend' : 'append';
		$merge_token    = sanitize_key( $args['merge_token'] ?? '' );

		if ( '' === $content ) {
			return new \WP_Error(
				'wpbf_empty_selection',
				__( 'No block content was provided to merge.', 'wp-block-forker' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $merge_token ) {
			return new \WP_Error(
				'wpbf_missing_token',
				__( 'A merge_token is required.', 'wp-block-forker' ),
				array( 'status' => 400 )
			);
		}

		if ( $source_post_id && $source_post_id === $target_post_id ) {
			return new \WP_Error(
				'wpbf_same_post',
				__( 'The target post must be different from the source post.', 'wp-block-forker' ),
				array( 'status' => 400 )
			);
		}

		$target = $target_post_id ? get_post( $target_post_id ) : null;

		if ( ! $target || in_array( $target->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error(
				'wpbf_invalid_target',
				__( 'The target post could not be found.', 'wp-block-forker' ),
				array( 'status' => 404 )
			);
		}

		$post_type_object = get_post_type_object( $target->post_type );
		if ( ! $post_type_object || ! $post_type_object->public || ! $post_type_object->show_in_rest ) {
			return new \WP_Error(
				'wpbf_invalid_post_type',
				__( 'That post type cannot be merged into.', 'wp-block-forker' ),
				array( 'status' => 400 )
			);
		}

		// Replay check goes before the lock check: a retried request that
		// already succeeded must keep reporting success even if someone has
		// since opened the target in an editor.
		if ( in_array( $merge_token, get_post_meta( $target_post_id, '_wpbf_merge_token' ), true ) ) {
			return $target_post_id;
		}

		// wp_check_post_lock() lives in wp-admin, which isn't loaded on REST
		// requests.
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		// Only guards against *another* user's open editor session: core
		// treats a lock held by the current user as not locked, so the same
		// user with the target open in a second tab is not protected.
		if ( wp_check_post_lock( $target_post_id ) ) {
			return new \WP_Error(
				'wpbf_target_locked',
				__( 'Someone else is editing the target post right now. Try again once they are done.', 'wp-block-forker' ),
				array( 'status' => 409 )
			);
		}

		$existing = trim( $target->post_content );
		$parts    = ( 'prepend' === $position ) ? array( $content, $existing ) : array( $existing, $content );
		$merged   = implode( "\n\n", array_filter( $parts, 'strlen' ) );

		// wp_update_post() expects slashed data and unslashes it itself; REST
		// params arrive already unslashed, so skipping wp_slash() would eat
		// the backslashes inside block comment JSON.
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $target_post_id,
					'post_content' => $merged,
				)
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The content is already written at this point, so a failed token
		// write can't be reported as a failed merge — that would invite a
		// retry and a duplicate append. Log it and report success.
		if ( false === add_post_meta( $target_post_id, '_wpbf_merge_token', $merge_token ) ) {
			error_log( sprintf( 'WP Block Forker: merged into post %d but could not record merge token; a retry will append again.', $target_post_id ) );
		}

		return $target_post_id;
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
