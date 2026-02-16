<?php
/**
 * Plugin scanner for GitHub-managed plugins.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin_Scanner.
 *
 * @since 1.0.0
 */
class Plugin_Scanner {
	/**
	 * Cache key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CACHE_KEY = 'alynt_pu_github_plugins';

	/**
	 * Header key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const HEADER_KEY = 'GitHub Plugin URI';

	/**
	 * Get all plugins with GitHub Plugin URI header.
	 *
	 * @since 1.0.0
	 * @return array<string, array> Keyed by plugin file path.
	 */
	public function get_github_plugins(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$this->ensure_plugin_functions_loaded();

		$results = $this->scan_for_github_plugins();

		set_transient( self::CACHE_KEY, $results, HOUR_IN_SECONDS );

		return $results;
	}

	/**
	 * Ensure the WordPress plugin API functions are available.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function ensure_plugin_functions_loaded(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Scan all installed plugins for the GitHub Plugin URI header.
	 *
	 * @since 1.0.0
	 * @return array<string, array> GitHub-managed plugins keyed by file path.
	 */
	private function scan_for_github_plugins(): array {
		$plugins = get_plugins();
		$results = array();
		$headers = $this->get_plugin_headers();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$github_data = $this->extract_github_plugin_data( $plugin_file, $plugin_data, $headers );
			if ( null !== $github_data ) {
				$results[ $plugin_file ] = $github_data;
			}
		}

		return $results;
	}

	/**
	 * Get the file header keys used to identify GitHub-managed plugins.
	 *
	 * @since 1.0.0
	 * @return array<string, string> Header key mapping.
	 */
	private function get_plugin_headers(): array {
		return array(
			'Name'            => 'Plugin Name',
			'Version'         => 'Version',
			'PluginURI'       => 'Plugin URI',
			'GitHubPluginURI' => self::HEADER_KEY,
			'Author'          => 'Author',
			'AuthorURI'       => 'Author URI',
			'Description'     => 'Description',
		);
	}

	/**
	 * Extract GitHub plugin data from a single plugin file.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin metadata from get_plugins().
	 * @param array  $headers     Header key mapping.
	 * @return array|null Normalised plugin data or null if not a GitHub plugin.
	 */
	private function extract_github_plugin_data( string $plugin_file, array $plugin_data, array $headers ): ?array {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		$data = get_file_data( $path, $headers, 'plugin' );

		$github_uri = isset( $data['GitHubPluginURI'] ) ? trim( (string) $data['GitHubPluginURI'] ) : '';
		if ( '' === $github_uri ) {
			return null;
		}

		$parsed = $this->parse_github_uri( $github_uri );
		if ( null === $parsed ) {
			return null;
		}

		$owner      = $parsed['owner'];
		$repo       = $parsed['repo'];
		$plugin_uri = sprintf( 'https://github.com/%s/%s', $owner, $repo );

		return array(
			'name'        => isset( $data['Name'] ) ? (string) $data['Name'] : (string) $plugin_data['Name'],
			'version'     => isset( $data['Version'] ) ? (string) $data['Version'] : (string) $plugin_data['Version'],
			'owner'       => $owner,
			'repo'        => $repo,
			'plugin_uri'  => $plugin_uri,
			'author'      => isset( $data['Author'] ) ? (string) $data['Author'] : '',
			'author_uri'  => isset( $data['AuthorURI'] ) ? (string) $data['AuthorURI'] : '',
			'description' => isset( $data['Description'] ) ? (string) $data['Description'] : '',
		);
	}

	/**
	 * Get GitHub data for a specific plugin.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin file path.
	 * @return array|null Array data or null if not a GitHub plugin.
	 */
	public function get_plugin_github_data( string $plugin_file ): ?array {
		$plugins = $this->get_github_plugins();

		if ( isset( $plugins[ $plugin_file ] ) ) {
			return $plugins[ $plugin_file ];
		}

		return null;
	}

	/**
	 * Parse GitHub Plugin URI header value.
	 *
	 * @since 1.0.0
	 * @param string $uri Raw header value.
	 * @return array|null Parsed owner/repo or null.
	 */
	public function parse_github_uri( string $uri ): ?array {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			return null;
		}

		if ( 0 === strpos( $uri, 'http://' ) || 0 === strpos( $uri, 'https://' ) ) {
			$parts = wp_parse_url( $uri );
			$path  = isset( $parts['path'] ) ? $parts['path'] : '';
			$path  = trim( (string) $path, '/' );
		} else {
			$path = $uri;
		}

		$path = preg_replace( '#^github\.com/#i', '', $path );
		$path = preg_replace( '#\.git$#i', '', $path );

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		if ( 2 !== count( $segments ) ) {
			return null;
		}

		return array(
			'owner' => $segments[0],
			'repo'  => $segments[1],
		);
	}

	/**
	 * Clear the cached plugins list.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Register hooks to invalidate cache when plugins change.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'activated_plugin', array( $this, 'clear_cache' ) );
		add_action( 'deactivated_plugin', array( $this, 'clear_cache' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
		add_action( 'switch_theme', array( $this, 'clear_cache' ) );
	}
}
