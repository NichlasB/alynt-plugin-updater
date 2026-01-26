<?php
/**
 * Update checker integration with WordPress.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

use stdClass;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Update_Checker.
 */
class Update_Checker {
	/**
	 * Plugin scanner.
	 *
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * GitHub API client.
	 *
	 * @var GitHub_API
	 */
	private GitHub_API $github_api;

	/**
	 * Version utility.
	 *
	 * @var Version_Util
	 */
	private Version_Util $version_util;

	/**
	 * Constructor.
	 *
	 * @param Plugin_Scanner $scanner      Plugin scanner.
	 * @param GitHub_API     $github_api   GitHub API client.
	 * @param Version_Util   $version_util Version utility.
	 */
	public function __construct( Plugin_Scanner $scanner, GitHub_API $github_api, Version_Util $version_util ) {
		$this->scanner      = $scanner;
		$this->github_api   = $github_api;
		$this->version_util = $version_util;
	}

	/**
	 * Register WordPress update hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
	}

	/**
	 * Filter callback for pre_set_site_transient_update_plugins.
	 *
	 * @param object $transient Update transient.
	 * @return object Modified transient.
	 */
	public function check_for_updates( object $transient ): object {
		$plugins = $this->scanner->get_github_plugins();
		if ( empty( $plugins ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$release = $this->github_api->get_latest_release( $plugin_data['owner'], $plugin_data['repo'] );
			if ( is_wp_error( $release ) ) {
				continue;
			}

			if ( empty( $release['download_url'] ) ) {
				continue;
			}

			$current_version = $plugin_data['version'];
			if ( ! $this->version_util->is_update_available( $current_version, $release['version'] ) ) {
				continue;
			}

			$slug = dirname( $plugin_file );
			$transient->response[ $plugin_file ] = (object) array(
				'slug'         => $slug,
				'plugin'       => $plugin_file,
				'new_version'  => $release['version'],
				'url'          => $plugin_data['plugin_uri'],
				'package'      => $release['download_url'],
				'icons'        => array(),
				'banners'      => array(),
				'tested'       => '',
				'requires_php' => '',
				'compatibility' => new stdClass(),
			);
		}

		return $transient;
	}

	/**
	 * Filter callback for plugins_api.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action API action.
	 * @param object             $args   Arguments.
	 * @return false|object Plugin info object or false.
	 */
	public function plugin_information( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$plugins = $this->scanner->get_github_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( dirname( $plugin_file ) !== $args->slug ) {
				continue;
			}

			$release = $this->github_api->get_latest_release( $plugin_data['owner'], $plugin_data['repo'] );
			if ( is_wp_error( $release ) ) {
				return $result;
			}

			return $this->build_plugin_info( $plugin_file, $plugin_data, $release );
		}

		return $result;
	}

	/**
	 * Check for update for a single plugin.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return array Result data.
	 */
	public function check_plugin_update( string $plugin_file ): array {
		$plugin_data = $this->scanner->get_plugin_github_data( $plugin_file );
		if ( null === $plugin_data ) {
			return array(
				'update_available' => false,
				'current_version'  => '',
				'new_version'      => '',
				'download_url'     => '',
			);
		}

		$release = $this->github_api->get_latest_release( $plugin_data['owner'], $plugin_data['repo'] );
		if ( is_wp_error( $release ) || empty( $release['version'] ) ) {
			return array(
				'update_available' => false,
				'current_version'  => $plugin_data['version'],
				'new_version'      => '',
				'download_url'     => '',
			);
		}

		$update_available = $this->version_util->is_update_available( $plugin_data['version'], $release['version'] );

		return array(
			'update_available' => $update_available,
			'current_version'  => $plugin_data['version'],
			'new_version'      => $release['version'],
			'download_url'     => $release['download_url'],
		);
	}

	/**
	 * Check all managed plugins for updates.
	 *
	 * @param bool $force_fresh Force fresh API calls.
	 * @return array<string, array> Results keyed by plugin file.
	 */
	public function check_all_updates( bool $force_fresh = false ): array {
		$plugins = $this->scanner->get_github_plugins();
		$results = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$release = $this->github_api->get_latest_release( $plugin_data['owner'], $plugin_data['repo'], $force_fresh );
			if ( is_wp_error( $release ) ) {
				$results[ $plugin_file ] = array(
					'update_available' => false,
					'current_version'  => $plugin_data['version'],
					'new_version'      => '',
					'download_url'     => '',
				);
				continue;
			}

			$results[ $plugin_file ] = array(
				'update_available' => $this->version_util->is_update_available( $plugin_data['version'], $release['version'] ),
				'current_version'  => $plugin_data['version'],
				'new_version'      => $release['version'],
				'download_url'     => $release['download_url'],
			);
		}

		return $results;
	}

	/**
	 * Build plugin info object for plugins_api.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin data.
	 * @param array  $release     Release data.
	 * @return object Plugin info object.
	 */
	private function build_plugin_info( string $plugin_file, array $plugin_data, array $release ): object {
		$info              = new stdClass();
		$info->name         = $plugin_data['name'];
		$info->slug         = dirname( $plugin_file );
		$info->version      = $release['version'];
		$info->author       = $plugin_data['author'];
		$info->homepage     = $plugin_data['plugin_uri'];
		$info->download_link = $release['download_url'];
		$info->last_updated = '';
		$info->sections     = array();

		if ( ! empty( $release['published_at'] ) ) {
			$timestamp         = strtotime( $release['published_at'] );
			$info->last_updated = $timestamp ? date_i18n( 'Y-m-d', $timestamp ) : '';
		}

		$description = $plugin_data['description'];
		if ( '' === $description ) {
			$description = __( 'No description provided.', 'alynt-plugin-updater' );
		}

		$info->sections['description'] = wp_kses_post( wpautop( $description ) );
		$info->sections['changelog']   = wp_kses_post( wpautop( (string) $release['changelog'] ) );

		return $info;
	}
}
