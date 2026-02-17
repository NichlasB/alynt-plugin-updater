<?php
/**
 * GitHub API client — orchestrates HTTP requests, caching, and version
 * normalisation to resolve the latest release for a GitHub repository.
 *
 * @package AlyntPluginUpdater
 * @since   1.0.0
 */

namespace Alynt\PluginUpdater;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GitHub_API.
 *
 * @since 1.0.0
 */
class GitHub_API {
	/**
	 * HTTP client.
	 *
	 * @since 1.0.0
	 * @var   GitHub_Http_Client
	 */
	private GitHub_Http_Client $http_client;

	/**
	 * Release cache.
	 *
	 * @since 1.0.0
	 * @var   GitHub_Release_Cache
	 */
	private GitHub_Release_Cache $cache;

	/**
	 * Version utility.
	 *
	 * @since 1.0.0
	 * @var   Version_Util
	 */
	private Version_Util $version_util;

	/**
	 * Logger.
	 *
	 * @since 1.0.0
	 * @var   Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param GitHub_Http_Client   $http_client  HTTP client.
	 * @param GitHub_Release_Cache $cache        Release cache.
	 * @param Version_Util         $version_util Version utility.
	 * @param Logger               $logger       Logger.
	 */
	public function __construct( GitHub_Http_Client $http_client, GitHub_Release_Cache $cache, Version_Util $version_util, Logger $logger ) {
		$this->http_client  = $http_client;
		$this->cache        = $cache;
		$this->version_util = $version_util;
		$this->logger       = $logger;
	}

	/**
	 * Get the latest release for a repository.
	 *
	 * @since 1.0.0
	 *
	 * @param string $owner       Repository owner.
	 * @param string $repo        Repository name.
	 * @param bool   $force_fresh Skip cache and fetch fresh data.
	 * @param bool   $cache_only  Return cached data only; skip API calls on cache miss.
	 * @return array|WP_Error Release data or WP_Error.
	 */
	public function get_latest_release( string $owner, string $repo, bool $force_fresh = false, bool $cache_only = false ) {
		$cached = $this->cache->get( $owner, $repo );

		$cached_result = $this->get_cached_release_result( $cached, $force_fresh, $owner, $repo );
		if ( null !== $cached_result ) {
			return $cached_result;
		}

		if ( $cache_only ) {
			return new WP_Error( 'no_cached_data', __( 'No cached release data available.', 'alynt-plugin-updater' ) );
		}

		$rate_limit_result = $this->get_rate_limited_result( $cached );
		if ( null !== $rate_limit_result ) {
			return $rate_limit_result;
		}

		$response = $this->http_client->request( sprintf( '/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) ) );
		if ( is_wp_error( $response ) ) {
			return $this->fallback_on_error( $response, $cached, $owner, $repo );
		}

		return $this->resolve_latest_release_response( $response, $cached, $owner, $repo );
	}

	/**
	 * Get release changelog/body.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return void
	 */
	public function clear_cache( string $owner, string $repo ): void {
		$this->cache->delete( $owner, $repo );
	}

	/**
	 * Check if currently rate limited.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|int False if not limited, or Unix timestamp when limit resets.
	 */
	public function is_rate_limited() {
		return $this->http_client->is_rate_limited();
	}

	/**
	 * Resolve return value from cache when allowed.
	 *
	 * @param mixed  $cached      Cached value.
	 * @param bool   $force_fresh Skip cache usage.
	 * @param string $owner       Repository owner.
	 * @param string $repo        Repository name.
	 * @return array|WP_Error|null Cached response or null when API call is needed.
	 */
	private function get_cached_release_result( $cached, bool $force_fresh, string $owner, string $repo ) {
		if ( $force_fresh || ! $this->cache->is_valid( $cached ) ) {
			return null;
		}

		if ( isset( $cached['error'] ) && true === $cached['error'] ) {
			return new WP_Error(
				isset( $cached['code'] ) ? $cached['code'] : 'cached_error',
				/* translators: 1: GitHub repository owner, 2: GitHub repository name. */
				sprintf( __( 'No releases or tags found for %1$s/%2$s.', 'alynt-plugin-updater' ), $owner, $repo )
			);
		}

		return $cached;
	}

	/**
	 * Resolve return value when currently rate limited.
	 *
	 * @param mixed $cached Cached value.
	 * @return array|WP_Error|null Cached data, WP_Error, or null when not limited.
	 */
	private function get_rate_limited_result( $cached ) {
		$rate_limit = $this->http_client->is_rate_limited();
		if ( ! $rate_limit ) {
			return null;
		}

		if ( $this->cache->is_valid( $cached ) ) {
			return $cached;
		}

		$reset = date_i18n( 'Y-m-d H:i:s', (int) $rate_limit );
		/* translators: %s: date and time when GitHub API rate limit resets. */
		return new WP_Error( 'rate_limited', sprintf( __( 'GitHub API rate limit exceeded. Resets at %s.', 'alynt-plugin-updater' ), $reset ) );
	}

	/**
	 * Parse and resolve latest release API response.
	 *
	 * @param array  $response Latest release response payload.
	 * @param mixed  $cached   Cached release value.
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @return array|WP_Error
	 */
	private function resolve_latest_release_response( array $response, $cached, string $owner, string $repo ) {
		if ( GitHub_Http_Client::HTTP_STATUS_NOT_FOUND === $response['code'] ) {
			return $this->fetch_from_tags( $owner, $repo, $cached );
		}

		if ( GitHub_Http_Client::HTTP_STATUS_FORBIDDEN === $response['code'] ) {
			return $this->handle_forbidden_response( $cached, $owner, $repo );
		}

		if ( GitHub_Http_Client::HTTP_STATUS_OK !== $response['code'] ) {
			return $this->fallback_on_error( $this->create_api_error( __( 'GitHub API error.', 'alynt-plugin-updater' ) ), $cached, $owner, $repo );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return $this->fallback_on_error( $this->create_api_error( __( 'Invalid release data from GitHub.', 'alynt-plugin-updater' ) ), $cached, $owner, $repo );
		}

		$release = $this->build_release_from_data( $data );
		$this->cache->set( $owner, $repo, $release );

		return $release;
	}

	/**
	 * Build normalised release data from a GitHub release payload.
	 *
	 * @param array $data Decoded release response.
	 * @return array<string, mixed>
	 */
	private function build_release_from_data( array $data ): array {
		return array(
			'version'      => $this->version_util->normalize( (string) $data['tag_name'] ),
			'tag'          => (string) $data['tag_name'],
			'download_url' => $this->get_download_url( $data ),
			'changelog'    => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'cached_at'    => time(),
			'source'       => 'releases',
		);
	}

	/**
	 * Determine the best download URL from release data.
	 *
	 * Prefers a .zip asset attachment; falls back to the zipball URL.
	 *
	 * @param array $release Release data from API.
	 * @return string Download URL or empty string.
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
	 * Handle API errors and fall back to cached data if possible.
	 *
	 * @param WP_Error $error  Error to return if no cache.
	 * @param mixed    $cached Cached data.
	 * @param string   $owner  Repository owner.
	 * @param string   $repo   Repository name.
	 * @return array|WP_Error
	 */
	private function fallback_on_error( WP_Error $error, $cached, string $owner, string $repo ) {
		if ( $this->cache->is_valid( $cached ) ) {
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
	 * Fetch release data from the tags API as a fallback.
	 *
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @param mixed  $cached Cached data.
	 * @return array|WP_Error
	 */
	private function fetch_from_tags( string $owner, string $repo, $cached ) {
		$response = $this->http_client->request( sprintf( '/repos/%s/%s/tags?per_page=1', rawurlencode( $owner ), rawurlencode( $repo ) ) );
		if ( is_wp_error( $response ) ) {
			return $this->fallback_on_error( $response, $cached, $owner, $repo );
		}

		if ( GitHub_Http_Client::HTTP_STATUS_FORBIDDEN === $response['code'] ) {
			return $this->handle_forbidden_response( $cached, $owner, $repo );
		}

		if ( GitHub_Http_Client::HTTP_STATUS_OK !== $response['code'] ) {
			return $this->fallback_on_error( $this->create_api_error( __( 'GitHub API error.', 'alynt-plugin-updater' ) ), $cached, $owner, $repo );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || empty( $data ) || empty( $data[0]['name'] ) ) {
			$this->logger->info( 'No releases found for repository.', array( 'owner' => $owner, 'repo' => $repo ) );

			$negative = array(
				'error'     => true,
				'code'      => 'no_releases',
				'cached_at' => time(),
			);

			$this->cache->set_with_ttl( $owner, $repo, $negative, HOUR_IN_SECONDS );

			return new WP_Error(
				'no_releases',
				/* translators: 1: GitHub repository owner, 2: GitHub repository name. */
				sprintf( __( 'No releases or tags found for %1$s/%2$s.', 'alynt-plugin-updater' ), $owner, $repo )
			);
		}

		$tag     = (string) $data[0]['name'];
		$release = array(
			'version'      => $this->version_util->normalize( $tag ),
			'tag'          => $tag,
			'download_url' => sprintf( 'https://github.com/%s/%s/archive/refs/tags/%s.zip', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $tag ) ),
			'changelog'    => '',
			'published_at' => '',
			'cached_at'    => time(),
			'source'       => 'tags',
		);

		$this->cache->set( $owner, $repo, $release );

		return $release;
	}

	/**
	 * Handle forbidden responses with rate-limit awareness.
	 *
	 * @param mixed  $cached Cached data.
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @return array|WP_Error
	 */
	private function handle_forbidden_response( $cached, string $owner, string $repo ) {
		if ( $this->http_client->is_rate_limited() ) {
			return $this->handle_rate_limit_error( $cached );
		}

		return $this->fallback_on_error( $this->create_forbidden_error(), $cached, $owner, $repo );
	}

	/**
	 * Create a standard forbidden-response error.
	 *
	 * @return WP_Error
	 */
	private function create_forbidden_error(): WP_Error {
		return new WP_Error( 'forbidden', __( 'Access denied by GitHub. The repository may be private or restricted.', 'alynt-plugin-updater' ) );
	}

	/**
	 * Create a standard API error payload.
	 *
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private function create_api_error( string $message ): WP_Error {
		return new WP_Error( 'api_error', $message );
	}

	/**
	 * Handle rate limit error responses.
	 *
	 * @param mixed $cached Cached data.
	 * @return array|WP_Error
	 */
	private function handle_rate_limit_error( $cached ) {
		$rate_limit = $this->http_client->is_rate_limited();

		if ( $this->cache->is_valid( $cached ) ) {
			return $cached;
		}

		$reset = $rate_limit ? date_i18n( 'Y-m-d H:i:s', (int) $rate_limit ) : __( 'unknown', 'alynt-plugin-updater' );

		/* translators: %s: date and time when GitHub API rate limit resets. */
		return new WP_Error( 'rate_limited', sprintf( __( 'GitHub API rate limit exceeded. Resets at %s.', 'alynt-plugin-updater' ), $reset ) );
	}
}
