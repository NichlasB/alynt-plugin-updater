<?php
/**
 * GitHub API client.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GitHub_API.
 */
class GitHub_API {
	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * Cache prefix.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'alynt_pu_release_';

	/**
	 * Version utility.
	 *
	 * @var Version_Util
	 */
	private Version_Util $version_util;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Version_Util $version_util Version utility.
	 * @param Logger       $logger       Logger.
	 */
	public function __construct( Version_Util $version_util, Logger $logger ) {
		$this->version_util = $version_util;
		$this->logger       = $logger;
	}

	/**
	 * Get the latest release for a repository.
	 *
	 * @param string $owner       Repository owner.
	 * @param string $repo        Repository name.
	 * @param bool   $force_fresh Skip cache and fetch fresh data.
	 * @return array|WP_Error Release data or WP_Error.
	 */
	public function get_latest_release( string $owner, string $repo, bool $force_fresh = false ) {
		$cache_key = $this->get_cache_key( $owner, $repo );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && ! $this->is_valid_cache( $cached ) ) {
			delete_transient( $cache_key );
			$this->logger->debug( 'Invalid cache structure, refreshing.', array( 'cache_key' => $cache_key ) );
			$cached = false;
		}

		if ( ! $force_fresh && $this->is_valid_cache( $cached ) ) {
			if ( isset( $cached['error'] ) && true === $cached['error'] ) {
				return new WP_Error(
					$cached['code'],
					sprintf( __( 'No releases or tags found for %1$s/%2$s.', 'alynt-plugin-updater' ), $owner, $repo )
				);
			}

			return $cached;
		}

		$rate_limit = $this->is_rate_limited();
		if ( $rate_limit ) {
			if ( $this->is_valid_cache( $cached ) ) {
				return $cached;
			}

			$reset = date_i18n( 'Y-m-d H:i:s', (int) $rate_limit );
			return new WP_Error( 'rate_limited', sprintf( __( 'GitHub API rate limit exceeded. Resets at %s.', 'alynt-plugin-updater' ), $reset ) );
		}

		$response = $this->request( sprintf( '/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) ) );
		if ( is_wp_error( $response ) ) {
			return $this->fallback_on_error( $response, $cached, $owner, $repo );
		}

		if ( 404 === $response['code'] ) {
			return $this->fetch_from_tags( $owner, $repo, $cached );
		}

		if ( 403 === $response['code'] ) {
			return $this->handle_rate_limit_error( $cached );
		}

		if ( 200 !== $response['code'] ) {
			return $this->fallback_on_error( new WP_Error( 'api_error', __( 'GitHub API error.', 'alynt-plugin-updater' ) ), $cached, $owner, $repo );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return $this->fallback_on_error( new WP_Error( 'api_error', __( 'Invalid release data from GitHub.', 'alynt-plugin-updater' ) ), $cached, $owner, $repo );
		}

		$release = array(
			'version'      => $this->version_util->normalize( (string) $data['tag_name'] ),
			'tag'          => (string) $data['tag_name'],
			'download_url' => $this->get_download_url( $data ),
			'changelog'    => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'cached_at'    => time(),
			'source'       => 'releases',
		);

		set_transient( $cache_key, $release, $this->get_cache_duration() );

		return $release;
	}

	/**
	 * Get release changelog/body.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return string Changelog markdown or empty string.
	 */
	public function get_release_changelog( string $owner, string $repo ): string {
		$release = $this->get_latest_release( $owner, $repo );

		if ( is_wp_error( $release ) ) {
			return '';
		}

		return isset( $release['changelog'] ) ? (string) $release['changelog'] : '';
	}

	/**
	 * Clear cached release data for a repository.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return void
	 */
	public function clear_cache( string $owner, string $repo ): void {
		delete_transient( $this->get_cache_key( $owner, $repo ) );
	}

	/**
	 * Check if currently rate limited.
	 *
	 * @return bool|int False if not limited, or Unix timestamp when limit resets.
	 */
	public function is_rate_limited() {
		$reset = get_transient( 'alynt_pu_rate_limited' );
		if ( false === $reset ) {
			return false;
		}

		$reset = (int) $reset;
		if ( $reset <= time() ) {
			delete_transient( 'alynt_pu_rate_limited' );
			return false;
		}

		return $reset;
	}

	/**
	 * Build User-Agent header.
	 *
	 * @return string User-Agent string.
	 */
	private function get_user_agent(): string {
		global $wp_version;

		$php_version = PHP_VERSION;
		$wp_version  = $wp_version ? $wp_version : 'unknown';

		return sprintf( 'Alynt-Plugin-Updater/%s; WordPress/%s; PHP/%s', ALYNT_PU_VERSION, $wp_version, $php_version );
	}

	/**
	 * Make HTTP request to GitHub API.
	 *
	 * @param string $endpoint API endpoint path.
	 * @return array|WP_Error Response array with 'code', 'body', 'headers'.
	 */
	private function request( string $endpoint ) {
		$url      = self::API_BASE . $endpoint;
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => $this->get_user_agent(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );

		$headers = $this->normalize_headers( $headers );
		$this->handle_rate_limit_headers( $headers );

		return array(
			'code'    => $code,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Handle rate limit headers from response.
	 *
	 * @param array $headers Response headers.
	 * @return void
	 */
	private function handle_rate_limit_headers( array $headers ): void {
		$remaining = isset( $headers['x-ratelimit-remaining'] ) ? (int) $headers['x-ratelimit-remaining'] : null;
		$reset     = isset( $headers['x-ratelimit-reset'] ) ? (int) $headers['x-ratelimit-reset'] : null;

		if ( 0 === $remaining && $reset && $reset > time() ) {
			$ttl = $reset - time();
			set_transient( 'alynt_pu_rate_limited', $reset, $ttl );
			$this->logger->warning( 'GitHub API rate limit exceeded.', array( 'reset' => $reset ) );
		}
	}

	/**
	 * Get cache key for a repository.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return string Transient key.
	 */
	private function get_cache_key( string $owner, string $repo ): string {
		return self::CACHE_PREFIX . sanitize_key( $owner . '_' . $repo );
	}

	/**
	 * Determine download URL from release data.
	 *
	 * @param array $release Release data from API.
	 * @return string Download URL.
	 */
	private function get_download_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) ) {
					continue;
				}

				$url = (string) $asset['browser_download_url'];
				if ( '.zip' === substr( $url, -4 ) ) {
					return $url;
				}
			}
		}

		if ( ! empty( $release['zipball_url'] ) ) {
			return (string) $release['zipball_url'];
		}

		return '';
	}

	/**
	 * Check if cached release data is valid.
	 *
	 * @param mixed $cached Cached value.
	 * @return bool True if valid.
	 */
	private function is_valid_cache( $cached ): bool {
		if ( ! is_array( $cached ) ) {
			return false;
		}

		if ( isset( $cached['error'] ) && true === $cached['error'] ) {
			return true;
		}

		return isset( $cached['version'] );
	}

	/**
	 * Get cache duration in seconds.
	 *
	 * @return int Cache duration.
	 */
	private function get_cache_duration(): int {
		$duration = (int) get_option( 'alynt_pu_cache_duration', 3600 );
		if ( $duration < 300 ) {
			return 300;
		}
		if ( $duration > 86400 ) {
			return 86400;
		}

		return $duration;
	}

	/**
	 * Handle API errors and fallback to cached data if possible.
	 *
	 * @param WP_Error $error  Error to return if no cache.
	 * @param mixed    $cached Cached data.
	 * @param string   $owner  Repository owner.
	 * @param string   $repo   Repository name.
	 * @return array|WP_Error
	 */
	private function fallback_on_error( WP_Error $error, $cached, string $owner, string $repo ) {
		if ( $this->is_valid_cache( $cached ) ) {
			$this->logger->warning(
				'GitHub API error, using cached data.',
				array(
					'owner' => $owner,
					'repo'  => $repo,
					'code'  => $error->get_error_code(),
				)
			);
			return $cached;
		}

		return $error;
	}

	/**
	 * Fetch release data from tags API.
	 *
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @param mixed  $cached Cached data.
	 * @return array|WP_Error
	 */
	private function fetch_from_tags( string $owner, string $repo, $cached ) {
		$response = $this->request( sprintf( '/repos/%s/%s/tags', rawurlencode( $owner ), rawurlencode( $repo ) ) );
		if ( is_wp_error( $response ) ) {
			return $this->fallback_on_error( $response, $cached, $owner, $repo );
		}

		if ( 403 === $response['code'] ) {
			return $this->handle_rate_limit_error( $cached );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || empty( $data ) || empty( $data[0]['name'] ) ) {
			$this->logger->info( 'No releases found for repository.', array( 'owner' => $owner, 'repo' => $repo ) );

			$negative = array(
				'error'     => true,
				'code'      => 'no_releases',
				'cached_at' => time(),
			);

			set_transient( $this->get_cache_key( $owner, $repo ), $negative, HOUR_IN_SECONDS );

			return new WP_Error(
				'no_releases',
				sprintf( __( 'No releases or tags found for %1$s/%2$s.', 'alynt-plugin-updater' ), $owner, $repo )
			);
		}

		$tag = (string) $data[0]['name'];
		$release = array(
			'version'      => $this->version_util->normalize( $tag ),
			'tag'          => $tag,
			'download_url' => sprintf( 'https://github.com/%s/%s/archive/refs/tags/%s.zip', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $tag ) ),
			'changelog'    => '',
			'published_at' => '',
			'cached_at'    => time(),
			'source'       => 'tags',
		);

		set_transient( $this->get_cache_key( $owner, $repo ), $release, $this->get_cache_duration() );

		return $release;
	}

	/**
	 * Handle rate limit error responses.
	 *
	 * @param mixed $cached Cached data.
	 * @return array|WP_Error
	 */
	private function handle_rate_limit_error( $cached ) {
		$rate_limit = $this->is_rate_limited();

		if ( $this->is_valid_cache( $cached ) ) {
			return $cached;
		}

		$reset = $rate_limit ? date_i18n( 'Y-m-d H:i:s', (int) $rate_limit ) : __( 'unknown', 'alynt-plugin-updater' );

		return new WP_Error( 'rate_limited', sprintf( __( 'GitHub API rate limit exceeded. Resets at %s.', 'alynt-plugin-updater' ), $reset ) );
	}

	/**
	 * Normalize headers to lower-case array.
	 *
	 * @param mixed $headers Headers object or array.
	 * @return array Normalized headers.
	 */
	private function normalize_headers( $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return array();
		}

		return array_change_key_case( $headers, CASE_LOWER );
	}
}
