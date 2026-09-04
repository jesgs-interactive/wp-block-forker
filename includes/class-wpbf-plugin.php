<?php
/**
 * Wires hooks. Nothing else lives here.
 */

namespace WPBF;

defined( 'ABSPATH' ) || exit;

class Plugin {

	protected Fork_Service $fork_service;
	protected Rest_Controller $rest_controller;

	public function __construct() {
		$this->fork_service   = new Fork_Service();
		$this->rest_controller = new Rest_Controller( $this->fork_service );
	}

	public function init(): void {
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	public function enqueue_editor_assets(): void {
		$asset_file = WPBF_PLUGIN_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'wpbf-editor',
			WPBF_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'wpbf-editor', 'wp-block-forker' );

		wp_localize_script(
			'wpbf-editor',
			'wpbfConfig',
			array(
				'postTypes' => $this->get_forkable_post_types(),
			)
		);
	}

	/**
	 * Post types the current user is allowed to fork into: public,
	 * REST-exposed (a fork target has to be creatable via REST since
	 * that's how the fork lands), and the user can actually create one.
	 */
	protected function get_forkable_post_types(): array {
		$types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'objects'
		);

		$out = array();

		foreach ( $types as $type ) {
			if ( ! current_user_can( $type->cap->create_posts ) ) {
				continue;
			}

			$out[] = array(
				'slug'  => $type->name,
				'label' => $type->labels->singular_name,
			);
		}

		return $out;
	}
}
