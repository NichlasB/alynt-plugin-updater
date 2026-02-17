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
 *
 * @since 1.0.0
 */
class Update_Checker {
	/**
	 * Plugin scanner.
	 *
	 * @since 1.0.0
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * GitHub API client.
	 *
	 * @since 1.0.0
	 * @var GitHub_API
	 */
	private GitHub_API $github_api;

	/**
	 * Version utility.
	 *
	 * @since 1.0.0
	 * @var Version_Util
	 */
	private Version_Util $version_util;

	/**
	 * Source directory fixer.
	 *
	 * @since 1.0.0
	 * @var Source_Directory_Fixer
	 */
	private Source_Directory_Fixer $source_fixer;

	/**
	 * Logger.
	 *
	 * @since 1.0.0
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Plugin_Scanner        $scanner      Plugin scanner.
	 * @param GitHub_API            $github_api   GitHub API client.
	 * @param Version_Util          $version_util Version utility.
	 * @param Source_Directory_Fixer $source_fixer Source directory fixer.
	 * @param Logger                $logger       Logger.
	 */
	public function __construct( Plugin_Scanner $scanner, GitHub_API $github_api, Version_Util $version_util, Source_Directory_Fixer $source_fixer, Logger $logger ) {
		$this->scanner      = $scanner;
		$this->github_api   = $github_api;
		$this->version_util = $version_util;
		$this->source_fixer = $source_fixer;
		$this->logger       = $logger;
	}

	/**
	 * Register WordPress update hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this->source_fixer, 'fix_source_directory' ), 10, 4 );
	}

	/**
	 * Filter callback for pre_set_site_transient_update_plugins.
	 *
	 * @since 1.0.0
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
			$release = $this->github_api->get_latest_release( $plugin_data['owner'], $plugin_data['repo'], false, true );
			if ( is_wp_error( $release ) ) {
				continue;
			}

			if ( empty( $release['download_url'] ) ) {
				$this->logger->warning(
					'Release has no download URL, skipping update entry.',
					array(
						'plugin' => $plugin_file,
						'owner'  => $plugin_data['owner'],
						'repo'   => $plugin_data['repo'],
					)
				);
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @param string $plugin_file Plugin file path.
	 * @return array Result data.
	 */
	public function check_plugin_update( string $plugin_file ): array {
		$plugin_data = $this->scanner->get_plugin_github_data( $plugin_file );
		if ( null === $plugin_data ) {
			return $this->build_empty_result();
		}

		$current_version = (string) $plugin_data['version'];
		$release = $this->github_api->get_latest_release( $plugin_data['owner'], $plugin_data['repo'] );
		if ( is_wp_error( $release ) ) {
			return $this->build_error_result( $current_version, $release );
		}

		return $this->build_release_result( $current_version, $release );
	}

	/**
	 * Check all managed plugins for updates and persist results.
	 *
	 * @since 1.0.0
	 * @param bool $force_fresh Force fresh API calls.
	 * @return array<string, array> Results keyed by plugin file.
	 */
	public function check_all_updates( bool $force_fresh = false ): array {
		$plugins = $this->scanner->get_github_plugins();
		$results = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$current_version = (string) $plugin_data['version'];
			$release = $this->github_api->get_latest_release( $plugin_data['owner'], $plugin_data['repo'], $force_fresh );
			if ( is_wp_error( $release ) ) {
				$this->logger->warning(
					'Update check failed for plugin.',
					array(
						'plugin' => $plugin_file,
						'code'   => $release->get_error_code(),
						'error'  => $release->get_error_message(),
					)
				);
				$results[ $plugin_file ] = $this->build_error_result( $current_version, $release );
				continue;
			}

			$results[ $plugin_file ] = $this->build_release_result( $current_version, $release );
		}

		update_option( 'alynt_pu_last_results', $results, false );

		return $results;
	}

	/**
	 * Get stored results from the last update check.
	 *
	 * @since 1.0.0
	 * @return array<string, array> Results keyed by plugin file, or empty array.
	 */
	public function get_stored_results(): array {
		$results = get_option( 'alynt_pu_last_results', array() );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Build a default "no update" result shape.
	 *
	 * @since 1.0.0
	 * @param string $current_version Current plugin version.
	 * @return array<string, mixed> Result payload.
	 */
	private function build_empty_result( string $current_version = '' ): array {
		return array(
			'update_available' => false,
			'current_version'  => $current_version,
			'new_version'      => '',
			'download_url'     => '',
		);
	}

	/**
	 * Build an error result payload for failed update checks.
	 *
	 * @since 1.0.0
	 * @param string   $current_version Current plugin version.
	 * @param WP_Error $error           Error instance from GitHub lookup.
	 * @return array<string, mixed> Result payload.
	 */
	private function build_error_result( string $current_version, WP_Error $error ): array {
		$result                  = $this->build_empty_result( $current_version );
		$result['error']         = true;
		$result['error_message'] = $error->get_error_message();

		return $result;
	}

	/**
	 * Build a normalized result payload from release data.
	 *
	 * @since 1.0.0
	 * @param string $current_version Current plugin version.
	 * @param array  $release         Release payload.
	 * @return array<string, mixed> Result payload.
	 */
	private function build_release_result( string $current_version, array $release ): array {
		$new_version  = isset( $release['version'] ) ? (string) $release['version'] : '';
		$download_url = isset( $release['download_url'] ) ? (string) $release['download_url'] : '';

		if ( '' === $new_version ) {
			return $this->build_empty_result( $current_version );
		}

		return array(
			'update_available' => $this->version_util->is_update_available( $current_version, $new_version ),
			'current_version'  => $current_version,
			'new_version'      => $new_version,
			'download_url'     => $download_url,
		);
	}

	/**
	 * Build plugin info object for plugins_api.
	 *
	 * @since 1.0.0
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
