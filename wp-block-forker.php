<?php
/**
 * Plugin Name:       WP Block Forker
 * Description:       Select blocks in the editor and fork them into a new post, as a copy or a move. WP-CLI: wp block-fork.
 * Version:           {{VERSION}}
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Jess G.
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-block-forker
 */

defined( 'ABSPATH' ) || exit;

define( 'WPBF_VERSION', '{{VERSION}}' );
define( 'WPBF_PLUGIN_FILE', __FILE__ );
define( 'WPBF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPBF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Manual classmap. PSR-4 is skipped deliberately for this plugin — see AGENTS.md
// for the "src/ collision with the JS build" reasoning. Order matters only in
// that Fork_Service has no internal deps, so it can load first.
require_once WPBF_PLUGIN_DIR . 'includes/class-wpbf-fork-service.php';
require_once WPBF_PLUGIN_DIR . 'includes/class-wpbf-rest-controller.php';
require_once WPBF_PLUGIN_DIR . 'includes/class-wpbf-cli-command.php';
require_once WPBF_PLUGIN_DIR . 'includes/class-wpbf-plugin.php';
require_once WPBF_PLUGIN_DIR . 'includes/class-wpbf-update-checker.php';

add_action(
	'plugins_loaded',
	function () {
		$plugin = new \WPBF\Plugin();
		$plugin->init();

		$updater = new \WPBF\Update_Checker( plugin_basename( WPBF_PLUGIN_FILE ), WPBF_VERSION );
		$updater->init();
	}
);
