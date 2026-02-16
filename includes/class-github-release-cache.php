<?php
/**
 * Cache manager for GitHub release data.
 *
 * Wraps WordPress transients to store, retrieve, validate,
 * and clear cached release information per repository.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GitHub_Release_Cache.
 *
 * @since 1.0.0
 */
class GitHub_Release_Cache {
	/**
	 * Transient key prefix for release data.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CACHE_PREFIX = 'alynt_pu_release_';

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
	 * Get cached release data, clearing invalid entries automatically.
	 *
	 * @since 1.0.0
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return mixed Cached release array, cached error array, or false when empty/invalid.
	 */
	public function get( string $owner, string $repo ) {
		$cache_key = $this->get_key( $owner, $repo );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && ! $this->is_valid( $cached ) ) {
			delete_transient( $cache_key );
			$this->logger->debug( 'Invalid cache structure, refreshing.', array( 'cache_key' => $cache_key ) );
			return false;
		}

		return $cached;
	}

	/**
	 * Store release data using the configured cache duration.
	 *
	 * @since 1.0.0
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @param array  $data  Release data to cache.
	 * @return void
	 */
	public function set( string $owner, string $repo, array $data ): void {
		set_transient( $this->get_key( $owner, $repo ), $data, $this->get_duration() );
	}

	/**
	 * Store release data with a specific time-to-live.
	 *
	 * @since 1.0.0
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @param array  $data  Release data to cache.
	 * @param int    $ttl   Time to live in seconds.
	 * @return void
	 */
	public function set_with_ttl( string $owner, string $repo, array $data, int $ttl ): void {
		set_transient( $this->get_key( $owner, $repo ), $data, $ttl );
	}

	/**
	 * Delete cached release data for a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return void
	 */
	public function delete( string $owner, string $repo ): void {
		delete_transient( $this->get_key( $owner, $repo ) );
	}

	/**
	 * Check whether a cached value has a valid structure.
	 *
	 * Accepts both successful release arrays (must contain 'version')
	 * and negative-cache error entries (must contain 'error' => true).
	 *
	 * @since 1.0.0
	 * @param mixed $cached Cached value.
	 * @return bool True if the structure is valid.
	 */
	public function is_valid( $cached ): bool {
		if ( ! is_array( $cached ) ) {
			return false;
		}

		if ( isset( $cached['error'] ) && true === $cached['error'] ) {
			return true;
		}

		return isset( $cached['version'] );
	}

	/**
	 * Get the configured cache duration in seconds.
	 *
	 * Clamps the stored option between the minimum and maximum
	 * values defined in Config.
	 *
	 * @since 1.0.0
	 * @return int Cache duration in seconds.
	 */
	public function get_duration(): int {
		$duration = (int) get_option( Config::CACHE_DURATION_OPTION, Config::CACHE_DURATION_DEFAULT );
		return max( Config::CACHE_DURATION_MIN, min( Config::CACHE_DURATION_MAX, $duration ) );
	}

	/**
	 * Build the transient key for a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return string Transient key.
	 */
	public function get_key( string $owner, string $repo ): string {
		$key = self::CACHE_PREFIX . sanitize_key( $owner . '_' . $repo );

		if ( strlen( $key ) > 172 ) {
			$key = self::CACHE_PREFIX . md5( $owner . '/' . $repo );
		}

		return $key;
	}
}
