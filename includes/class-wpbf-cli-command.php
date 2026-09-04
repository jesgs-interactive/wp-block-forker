<?php
/**
 * wp block-fork — CLI analog of selecting blocks in the editor and forking
 * them. Selection here is expressed as zero-based indices into
 * parse_blocks()'s top-level array, since block editor "selection" has no
 * server-side representation to key off otherwise.
 */

namespace WPBF;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

class CLI_Command {

	protected Fork_Service $fork_service;

	public function __construct( Fork_Service $fork_service ) {
		$this->fork_service = $fork_service;
	}

	/**
	 * Fork selected top-level blocks from a post into a new post.
	 *
	 * ## OPTIONS
	 *
	 * <source_post_id>
	 * : The ID of the post to fork blocks from.
	 *
	 * --blocks=<indices>
	 * : Comma-separated zero-based indices of top-level blocks to fork,
	 * in parse_blocks() order. Run with an out-of-range index to see the
	 * post's total top-level block count in the error message.
	 *
	 * [--mode=<mode>]
	 * : copy leaves the source post untouched. move also removes the
	 * forked blocks from the source post and requires --yes.
	 * ---
	 * default: copy
	 * options:
	 *   - copy
	 *   - move
	 * ---
	 *
	 * [--post-type=<post_type>]
	 * : Post type for the new post.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--title=<title>]
	 * : Title for the new post. Defaults to the first extracted block's text.
	 *
	 * [--yes]
	 * : Required to execute --mode=move, since it changes the source post.
	 * Not required for copy mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-fork 123 --blocks=2,3,7
	 *     wp block-fork 123 --blocks=0,1 --mode=move --post-type=page --yes
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$source_post_id = absint( $args[0] ?? 0 );
		$source_post    = get_post( $source_post_id );

		if ( ! $source_post ) {
			\WP_CLI::error( sprintf( 'Post %d not found.', $source_post_id ) );
			return;
		}

		$raw_indices = array_filter(
			array_map( 'trim', explode( ',', (string) ( $assoc_args['blocks'] ?? '' ) ) ),
			'strlen'
		);

		if ( empty( $raw_indices ) ) {
			\WP_CLI::error( 'Pass --blocks=<comma-separated indices>.' );
			return;
		}

		$indices = array_values( array_unique( array_map( 'absint', $raw_indices ) ) );

		$mode      = ( 'move' === ( $assoc_args['mode'] ?? 'copy' ) ) ? 'move' : 'copy';
		$post_type = sanitize_key( $assoc_args['post-type'] ?? 'post' );

		if ( ! get_post_type_object( $post_type ) ) {
			\WP_CLI::error( sprintf( 'Unknown post type: %s', $post_type ) );
			return;
		}

		if ( 'move' === $mode && empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Move mode changes the source post. Re-run with --yes to confirm.' );
			return;
		}

		$blocks = parse_blocks( $source_post->post_content );
		$total  = count( $blocks );

		foreach ( $indices as $index ) {
			if ( $index >= $total ) {
				\WP_CLI::error(
					sprintf( 'Block index %d is out of range — post #%d has %d top-level block(s) (0-%d).', $index, $source_post_id, $total, max( 0, $total - 1 ) )
				);
				return;
			}
		}

		$extracted_blocks = array();
		$remaining_blocks = array();

		foreach ( $blocks as $index => $block ) {
			if ( in_array( $index, $indices, true ) ) {
				$extracted_blocks[] = $block;
			} else {
				$remaining_blocks[] = $block;
			}
		}

		$extracted_content = serialize_blocks( $extracted_blocks );
		$title              = ! empty( $assoc_args['title'] )
			? sanitize_text_field( $assoc_args['title'] )
			: $this->fork_service->derive_title( $extracted_blocks );
		$fork_token         = 'cli-' . md5( $source_post_id . ':' . implode( ',', $indices ) . ':' . microtime( true ) );

		$result = $this->fork_service->create_fork(
			array(
				'source_post_id'    => $source_post_id,
				'post_type'         => $post_type,
				'title'             => $title,
				'extracted_content' => $extracted_content,
				'mode'              => $mode,
				'fork_token'        => $fork_token,
			)
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		\WP_CLI::success( sprintf( 'Created %s #%d from %d block(s) of post #%d.', $post_type, $result, count( $indices ), $source_post_id ) );

		if ( 'move' === $mode ) {
			$remaining_content = serialize_blocks( $remaining_blocks );
			$update            = $this->fork_service->update_source_content( $source_post_id, $remaining_content );

			if ( is_wp_error( $update ) ) {
				\WP_CLI::error( $update->get_error_message() );
				return;
			}

			\WP_CLI::success( sprintf( 'Removed %d block(s) from source post #%d.', count( $indices ), $source_post_id ) );
		}
	}
}
