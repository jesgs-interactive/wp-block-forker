<?php
/**
 * Minimal GitHub Releases update checker. Dependency-free, intended for
 * public repositories. Generated from the wordpress-plugin-scaffold skill's
 * references/self-hosted-updates.md — keep plugin-specific values in the
 * constants below, not scattered through the methods.
 */

namespace WPBF;

defined( 'ABSPATH' ) || exit;

class Update_Checker {

	protected string $plugin_basename;
	protected string $current_version;

	public const GITHUB_REPO         = 'jesgs-interactive/wp-block-forker';
	public const GITHUB_RELEASES_URL = 'https://api.github.com/repos/jesgs-interactive/wp-block-forker/releases/latest';
	public const PLUGIN_NAME         = 'WP Block Forker';
	public const PLUGIN_AUTHOR       = 'Jess G.';

	public function __construct( string $plugin_basename, string $current_version ) {
		$this->plugin_basename = $plugin_basename;
		$this->current_version = $current_version;
	}

	public function init(): void {
		add_filter( 'site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
	}

	protected function get_slug(): string {
		return basename( $this->plugin_basename, '.php' );
	}

	public function check_update( $transient ) {
		if ( ( '' === $this->current_version || false !== strpos( $this->current_version, '{{' ) ) && ! WP_DEBUG ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$remote_version = ltrim( (string) $release['tag_name'], 'vV' );
		if ( ! version_compare( $remote_version, $this->current_version, '>' ) ) {
			return $transient;
		}

		$package = $this->find_release_asset_url( $release );
		if ( '' === $package || ! is_object( $transient ) ) {
			return $transient;
		}

		$update              = new \stdClass();
		$update->slug        = $this->get_slug();
		$update->new_version = $remote_version;
		$update->url         = $release['html_url'] ?? 'https://github.com';
		$update->package     = $package;

		$transient->response[ $this->plugin_basename ] = $update;

		return $transient;
	}

	public function plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->get_slug() ) {
			return $res;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) ) {
			return $res;
		}

		$download_link = $this->find_release_asset_url( $release );
		if ( '' === $download_link ) {
			return $res;
		}

		$info                = new \stdClass();
		$info->name          = self::PLUGIN_NAME;
		$info->slug          = $this->get_slug();
		$info->version       = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' ) ?: $this->current_version;
		$info->author        = self::PLUGIN_AUTHOR;
		$info->homepage      = $release['html_url'] ?? 'https://github.com';
		$info->download_link = $download_link;
		$info->sections      = array(
			'description' => __( 'Fork selected blocks in the editor into a new post, as a copy or a move.', 'wp-block-forker' ),
			'changelog'   => ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : '',
		);

		return $info;
	}

	protected function find_release_asset_url( array $release ): string {
		$slug = $this->get_slug();
		foreach ( $release['assets'] ?? array() as $asset ) {
			$name = $asset['name'] ?? '';
			$url  = $asset['browser_download_url'] ?? '';
			if ( '' === $name || '' === $url ) {
				continue;
			}
			if ( 0 === strcasecmp( $name, $slug . '.zip' )
				|| ( false !== stripos( $name, $slug ) && '.zip' === strtolower( substr( $name, -4 ) ) )
			) {
				return $url;
			}
		}
		return '';
	}

	protected function get_latest_release(): ?array {
		$transient_key = 'wpbf_github_release_' . md5( self::GITHUB_REPO );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_RELEASES_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => self::PLUGIN_NAME . '-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		set_transient( $transient_key, $decoded, 6 * HOUR_IN_SECONDS );

		return $decoded;
	}
}
