<?php
/**
 * HTTP client for GitHub API requests.
 *
 * Handles low-level HTTP transport, User-Agent construction,
 * response header normalisation, and rate-limit tracking.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GitHub_Http_Client.
 *
 * @since 1.0.0
 */
class GitHub_Http_Client {
	/**
	 * API base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const API_BASE = 'https://api.github.com';

	/**
	 * GitHub request timeout in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const REQUEST_TIMEOUT = 15;

	/**
	 * HTTP success status code.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const HTTP_STATUS_OK = 200;

	/**
	 * HTTP forbidden status code.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const HTTP_STATUS_FORBIDDEN = 403;

	/**
	 * HTTP not found status code.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const HTTP_STATUS_NOT_FOUND = 404;

	/**
	 * Rate-limit transient key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const RATE_LIMIT_KEY = 'alynt_pu_rate_limited';

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
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Make an HTTP GET request to the GitHub API.
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint path (e.g. '/repos/owner/repo/releases/latest').
	 * @return array|WP_Error Associative array with 'code', 'body', 'headers' keys, or WP_Error.
	 */
	public function request( string $endpoint ) {
		$url      = self::API_BASE . $endpoint;
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
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
	 * Check if the client is currently rate-limited by GitHub.
	 *
	 * @since 1.0.0
	 * @return bool|int False if not limited, or Unix timestamp when the limit resets.
	 */
	public function is_rate_limited() {
		$reset = get_transient( self::RATE_LIMIT_KEY );
		if ( false === $reset ) {
			return false;
		}

		$reset = (int) $reset;
		if ( $reset <= time() ) {
			delete_transient( self::RATE_LIMIT_KEY );
			return false;
		}

		return $reset;
	}

	/**
	 * Build User-Agent header value.
	 *
	 * @since 1.0.0
	 * @return string User-Agent string.
	 */
	private function get_user_agent(): string {
		global $wp_version;

		$php_version = PHP_VERSION;
		$wp_version  = $wp_version ? $wp_version : 'unknown';

		return sprintf( 'Alynt-Plugin-Updater/%s; WordPress/%s; PHP/%s', ALYNT_PU_VERSION, $wp_version, $php_version );
	}

	/**
	 * Record rate-limit state from GitHub response headers.
	 *
	 * @since 1.0.0
	 * @param array $headers Normalised response headers.
	 * @return void
	 */
	private function handle_rate_limit_headers( array $headers ): void {
		$remaining = isset( $headers['x-ratelimit-remaining'] ) ? (int) $headers['x-ratelimit-remaining'] : null;
		$reset     = isset( $headers['x-ratelimit-reset'] ) ? (int) $headers['x-ratelimit-reset'] : null;

		if ( 0 === $remaining && $reset && $reset > time() ) {
			$ttl = $reset - time();
			set_transient( self::RATE_LIMIT_KEY, $reset, $ttl );
			$this->logger->warning( 'GitHub API rate limit exceeded.', array( 'reset' => $reset ) );
		}
	}

	/**
	 * Normalise response headers to a lower-case keyed array.
	 *
	 * @since 1.0.0
	 * @param mixed $headers Headers object or array from wp_remote_retrieve_headers().
	 * @return array Normalised headers.
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
